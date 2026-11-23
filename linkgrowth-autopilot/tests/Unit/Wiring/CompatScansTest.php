<?php
/**
 * The static compatibility scans of DESIGN.md §11.8 (WP12) find what they are meant to find, and bin/lint.sh runs
 * them.
 *
 * - bin/php85-scan.sh (rules in bin/php85-scan.php): every line of tests/fixtures/compat-scan/php85/*.php.fixture
 *   that ends with an "expect: <rule>" comment is reported with that rule, and nothing else is; among them the
 *   acceptance fixtures "a readonly class" and "a json_validate call". The clean fixture (near misses) gives no
 *   finding, the syntax check rejects the PHP 8.4/8.5 fixtures when the oldest PHP available is older, and the
 *   fork itself passes with the PHP 8.1 floor.
 * - bin/compat-api.php: the same for the mini plugins in tests/fixtures/compat-scan/api/{violations,clean} (the
 *   acceptance fixture "an unguarded function with @since 6.6"), looked up in the stand-in WordPress and
 *   WooCommerce source tests/fixtures/compat-scan/api/source on every run. With LGA_WP_PATH (the LGA site of
 *   DESIGN.md §12.1) the same fixtures are checked against the real source and the fork must pass; without it that
 *   test is skipped with the reason, and the script itself says "not run" instead of passing.
 * - The default targets of both scans leave out exactly tests/fixtures/compat-scan (not every path starting with
 *   it), so the seeded violations never fail the fork's own lint.
 *
 * The fixtures use the .php.fixture extension where they hold PHP 8.4/8.5 syntax, so bin/lint.sh (php -l on PHP
 * 8.3) never picks them up.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Wiring;

use LinkGrowth\Autopilot\Tests\Support\TestCase;

final class CompatScansTest extends TestCase {

    private const ROOT = __DIR__ . '/../../..';
    private const PHP85 = 'tests/fixtures/compat-scan/php85';
    private const API = 'tests/fixtures/compat-scan/api';
    private const STAND_IN = 'tests/fixtures/compat-scan/api/source';

    /** @return array{0:int,1:string} exit code and output (stdout and stderr) */
    private static function runTool( array $cmd, array $env = [] ): array {
        $spec = [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
        $proc = proc_open( $cmd, $spec, $pipes, self::ROOT, $env ? $env + getenv() : null );
        self::assertIsResource( $proc );
        $out = (string) stream_get_contents( $pipes[1] );
        $err = (string) stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        return [ proc_close( $proc ), $out . $err ];
    }

    /**
     * "<file basename>:<line>:<tag>" for every line that ends with "// expect: a, b".
     *
     * @return list<string>
     */
    private static function expected( array $files ): array {
        $want = [];
        foreach ( $files as $f ) {
            foreach ( file( self::ROOT . '/' . $f ) ?: [] as $n => $line ) {
                if ( preg_match( '#// expect: ([a-z0-9, -]+)$#', rtrim( $line ), $m ) ) {
                    foreach ( array_map( 'trim', explode( ',', $m[1] ) ) as $tag ) {
                        $want[] = basename( $f ) . ':' . ( $n + 1 ) . ':' . $tag;
                    }
                }
            }
        }
        $want = array_values( array_unique( $want ) );
        sort( $want );
        return $want;
    }

    /** @return list<string> "<file basename>:<line>:<rule>" of every reported line */
    private static function reported( string $out, bool $fullPath = false ): array {
        preg_match_all( '/^(\S+?):(\d+): \[([a-z0-9-]+)\]/m', $out, $m, PREG_SET_ORDER );
        $got = [];
        foreach ( $m as $x ) {
            $got[] = ( $fullPath ? $x[1] : basename( $x[1] ) ) . ':' . $x[2] . ':' . $x[3];
        }
        $got = array_values( array_unique( $got ) );
        sort( $got );
        return $got;
    }

    /** Line number of the first line of a fixture that contains $needle. */
    private static function lineOf( string $file, string $needle ): int {
        foreach ( file( self::ROOT . '/' . $file ) ?: [] as $n => $line ) {
            if ( str_contains( $line, $needle ) ) {
                return $n + 1;
            }
        }
        self::fail( "{$needle} not found in {$file}" );
    }

    /** @return list<string> */
    private static function violationFixtures(): array {
        $files = array_map(
            static fn( string $f ): string => self::PHP85 . '/' . basename( $f ),
            glob( self::ROOT . '/' . self::PHP85 . '/*.php.fixture' ) ?: []
        );
        return array_values( array_filter( $files, static fn( string $f ): bool => ! str_ends_with( $f, '/clean.php.fixture' ) ) );
    }

    private static function removeTree( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $it as $f ) {
            $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
        }
        rmdir( $dir );
    }

    public function testPhp85ScanReportsEverySeededViolationAndNothingElse(): void {
        $files = self::violationFixtures();
        $this->assertCount( 5, $files, 'deprecations and the 8.2, 8.3, 8.4 and 8.5 floor fixtures' );
        [ $code, $out ] = self::runTool( array_merge( [ PHP_BINARY, 'bin/php85-scan.php', '--min=8.1' ], $files ) );
        $this->assertSame( 1, $code, $out );
        $this->assertSame( self::expected( $files ), self::reported( $out ), $out );

        // The acceptance fixtures of DESIGN.md §13.2 WP12.
        $f82 = self::PHP85 . '/floor-8.2.php.fixture';
        $f83 = self::PHP85 . '/floor-8.3.php.fixture';
        $this->assertContains( 'floor-8.2.php.fixture:' . self::lineOf( $f82, 'readonly class Money' ) . ':readonly-class', self::reported( $out ) );
        $this->assertContains( 'floor-8.3.php.fixture:' . self::lineOf( $f83, '$valid = json_validate($s); // expect' ) . ':new-function', self::reported( $out ) );
        // Guarded calls are accepted: only the unguarded call and the four wrongly guarded ones are reported (one of
        // them after a guard branch that declares an unrelated function).
        $this->assertSame( 5, substr_count( $out, 'json_validate()' ), $out );
    }

    public function testPhp85ScanWithoutTheFloorReportsOnlyTheDeprecations(): void {
        [ $code, $out ] = self::runTool( array_merge( [ PHP_BINARY, 'bin/php85-scan.php' ], self::violationFixtures() ) );
        $this->assertSame( 1, $code, $out );
        $this->assertSame( self::expected( [ self::PHP85 . '/deprecations.php.fixture' ] ), self::reported( $out ), $out );
    }

    public function testPhp85ScanAcceptsTheCleanFixture(): void {
        [ $code, $out ] = self::runTool( [ PHP_BINARY, 'bin/php85-scan.php', '--min=8.1', self::PHP85 . '/clean.php.fixture' ] );
        $this->assertSame( 0, $code, $out );
        $this->assertSame( [], self::reported( $out ), $out );
        $this->assertStringContainsString( 'php85-scan: 0 finding(s)', $out );
    }

    public function testTheShellWrapperRejectsSyntaxTheOldestPhpCannotParse(): void {
        $f84 = self::PHP85 . '/floor-8.4.php.fixture';
        $f85 = self::PHP85 . '/floor-8.5.php.fixture';
        // PHP=PHP_BINARY: the scan runs on the PHP that runs this test (under the PHP 8.5 wrapper, PHPRC points at that
        // build's php.ini, which another php on PATH cannot load); the syntax check uses the oldest PHP with -n.
        [ $code, $out ] = self::runTool( [ 'bash', 'bin/php85-scan.sh', '--min=8.1', self::PHP85 . '/clean.php.fixture', $f84, $f85 ], [ 'PHP' => PHP_BINARY ] );
        $this->assertStringNotContainsString( 'PHP Startup', $out );
        $this->assertSame( 1, $code, $out );
        $this->assertSame( 1, preg_match( '/php -l with PHP (\d+)\.(\d+)/', $out, $m ), $out );
        $oldest = (int) $m[1] * 100 + (int) $m[2];
        $this->assertSame( $oldest < 804, str_contains( $out, $f84 . ': [php-lint]' ), $out );
        $this->assertSame( $oldest < 805, str_contains( $out, $f85 . ': [php-lint]' ), $out );
        $this->assertStringNotContainsString( 'clean.php.fixture: [php-lint]', $out );
        $this->assertStringEndsWith( "php85-scan FAILED\n", $out );
    }

    public function testTheForkPassesThePhp85ScanWithThePhp81Floor(): void {
        $list = tempnam( sys_get_temp_dir(), 'lga-php85-list' );
        try {
            [ $code, $out ] = self::runTool( [ PHP_BINARY, 'bin/php85-scan.php', '--min=8.1', '--files-out=' . $list ] );
            $files = array_filter( explode( "\n", (string) file_get_contents( $list ) ) );
        } finally {
            unlink( $list );
        }
        $this->assertSame( 0, $code, $out );
        $this->assertMatchesRegularExpression( '/^php85-scan: 0 finding\(s\); \d+ file\(s\) scanned for PHP 8\.1-8\.5 deprecations, \d+ for the PHP 8\.1 floor$/m', $out );
        // The fork's own files, tests and tools are scanned; the seeded violations are not.
        $base = (string) realpath( self::ROOT );
        $rel  = array_map( static fn( string $f ): string => substr( $f, strlen( $base ) + 1 ), $files );
        foreach ( [ 'linkgrowth-autopilot.php', 'uninstall.php', 'includes/Plugin.php', 'tests/bootstrap.php', 'tests/Support/FakeWpdb.php', 'tests/fixtures/router.php', 'bin/php85-scan.php', 'bin/compat-api.php' ] as $f ) {
            $this->assertContains( $f, $rel );
        }
        $this->assertSame( [], array_values( array_filter( $rel, static fn( string $f ): bool => str_starts_with( $f, 'tests/fixtures/compat-scan/' ) ) ) );
    }

    public function testTheDefaultTargetsLeaveOutExactlyTheFixtureFolder(): void {
        $root = sys_get_temp_dir() . '/lga-compat-root-' . bin2hex( random_bytes( 4 ) );
        $bad  = "<?php\n\$n = (integer) '1';\n";
        $put  = static function ( string $rel, string $code ) use ( $root ): void {
            if ( ! is_dir( dirname( $root . '/' . $rel ) ) ) {
                mkdir( dirname( $root . '/' . $rel ), 0777, true );
            }
            file_put_contents( $root . '/' . $rel, $code );
        };
        try {
            $put( 'plugin.php', "<?php\n/**\n * Plugin Name: Scan Root\n * Requires at least: 6.5\n * WC requires at least: 8.0\n */\n" );
            $put( 'includes/A.php', "<?php\nfunction a(): int { return 1; }\n" );
            $put( 'tests/fixtures/compat-scan/seeded.php', $bad );
            $put( 'tests/fixtures/compat-scan/deeper/seeded.php', $bad );
            $put( 'tests/fixtures/compat-scan-extra/near.php', $bad );
            $put( 'tests/fixtures/other/compat-scan/near.php', $bad );
            $put( 'tests/Unit/compat-scan/near.php', $bad );
            [ $code, $out ] = self::runTool( [ PHP_BINARY, 'bin/php85-scan.php', '--min=8.1', '--root=' . $root ] );
        } finally {
            self::removeTree( $root );
        }
        $this->assertSame( 1, $code, $out );
        $this->assertSame(
            [ 'tests/Unit/compat-scan/near.php:2:noncanonical-cast', 'tests/fixtures/compat-scan-extra/near.php:2:noncanonical-cast', 'tests/fixtures/other/compat-scan/near.php:2:noncanonical-cast' ],
            self::reported( $out, true ),
            $out
        );
        $this->assertStringContainsString( '5 file(s) scanned for PHP 8.1-8.5 deprecations, 2 for the PHP 8.1 floor', $out );
    }

    /**
     * A function newer than the floor is accepted as polyfilled only when a floor file (main file, uninstall.php,
     * includes/) declares it under the name the call resolves to: a declaration in tests/ or bin/ never ships, and
     * a namespaced function cannot satisfy a fully qualified call or a call from another namespace. A guard branch
     * that declares an unrelated class does not guard the code after it; one that declares the guarded class does.
     */
    public function testOnlyTheShippedCodeProvidesAPolyfillUnderTheResolvedName(): void {
        $root  = sys_get_temp_dir() . '/lga-compat-poly-' . bin2hex( random_bytes( 4 ) );
        $files = [
            'plugin.php'                        => "<?php\n/**\n * Plugin Name: Scan Root\n */\n",
            'includes/Seed/UsesNew.php'         => <<<'PHP'
                <?php
                namespace LinkGrowth\Autopilot\Seed;
                function uses(array $a): array
                {
                    return [
                        \array_first($a), // expect: new-function
                        array_find($a, static fn($v): bool => (bool) $v), // expect: new-function
                        \mb_trim(' x '), // expect: new-function
                        mb_ltrim(' x '), // expect: new-function
                        array_last($a),
                        \array_any($a, static fn($v): bool => (bool) $v),
                        array_any($a, static fn($v): bool => (bool) $v),
                    ];
                }
                PHP,
            'includes/Seed/Polyfills.php'       => "<?php\nnamespace LinkGrowth\\Autopilot\\Seed;\nfunction array_last(array \$a): mixed { return \$a ? \$a[array_key_last(\$a)] : null; }\n",
            'includes/Other/Functions.php'      => "<?php\nnamespace LinkGrowth\\Autopilot\\Other;\nfunction mb_trim(string \$s): string { return trim(\$s); }\nfunction mb_ltrim(string \$s): string { return ltrim(\$s); }\n",
            'includes/compat.php'               => "<?php\nif (!function_exists('array_any')) {\n    function array_any(array \$a, callable \$c): bool { foreach (\$a as \$k => \$v) { if (\$c(\$v, \$k)) { return true; } } return false; }\n}\n",
            'includes/Random/Randomizer.php'    => <<<'PHP'
                <?php
                namespace Random;
                if (!class_exists('Random\Randomizer')) {
                    class Randomizer {}
                }
                $r = new Randomizer();
                if (!class_exists('Random\Engine\Secure')) {
                    class SomethingElse {}
                }
                $e = new Engine\Secure(); // expect: new-class
                PHP,
            'tests/Support/ShimForTests.php'    => "<?php\nnamespace LinkGrowth\\Autopilot\\Tests\\Support;\nfunction array_first(array \$a): mixed { foreach (\$a as \$v) { return \$v; } return null; }\n",
            'tests/ShimGlobal.php'              => "<?php\nif (!function_exists('array_find')) {\n    function array_find(array \$a, callable \$c): mixed { foreach (\$a as \$k => \$v) { if (\$c(\$v, \$k)) { return \$v; } } return null; }\n}\n",
            'bin/shim.php'                      => "<?php\nfunction array_first(array \$a): mixed { return \$a ? reset(\$a) : null; }\nfunction mb_trim(string \$s): string { return trim(\$s); }\n",
        ];
        $want = [];
        foreach ( $files as $rel => $code ) {
            foreach ( explode( "\n", $code ) as $n => $line ) {
                if ( preg_match( '#// expect: ([a-z-]+)$#', rtrim( $line ), $m ) ) {
                    $want[] = $rel . ':' . ( $n + 1 ) . ':' . $m[1];
                }
            }
        }
        sort( $want );
        try {
            foreach ( $files as $rel => $code ) {
                if ( ! is_dir( dirname( $root . '/' . $rel ) ) ) {
                    mkdir( dirname( $root . '/' . $rel ), 0777, true );
                }
                file_put_contents( $root . '/' . $rel, $code );
            }
            [ $code, $out ] = self::runTool( [ PHP_BINARY, 'bin/php85-scan.php', '--min=8.1', '--root=' . $root ] );
        } finally {
            self::removeTree( $root );
        }
        $this->assertCount( 5, $want );
        $this->assertSame( 1, $code, $out );
        $this->assertSame( $want, self::reported( $out, true ), $out );
        $this->assertStringContainsString( '9 file(s) scanned for PHP 8.1-8.5 deprecations, 6 for the PHP 8.1 floor', $out );
    }

    public function testCompatApiSaysNotRunWithoutTheWordPressSource(): void {
        [ $code, $out ] = self::runTool( [ PHP_BINARY, 'bin/compat-api.php', '--wp=' . self::ROOT . '/tests/fixtures/compat-scan/no-such-wordpress' ] );
        $this->assertSame( 3, $code, $out );
        $this->assertStringStartsWith( 'compat-api not run: no WordPress source', $out );
        [ $code, $out ] = self::runTool( [ PHP_BINARY, 'bin/compat-api.php' ], [ 'LGA_WP_PATH' => '' ] );
        $this->assertSame( 3, $code, $out );
        $this->assertStringStartsWith( 'compat-api not run: no WordPress source', $out );
    }

    /** The seeded API floor violations against one WordPress source (the stand-in or the LGA site). */
    private function assertCompatApiVerdicts( string $wp ): void {
        $checks = self::API . '/violations/includes/checks.php';
        [ $code, $out ] = self::runTool( [ PHP_BINARY, 'bin/compat-api.php', '--wp=' . $wp, '--root=' . self::API . '/violations' ] );
        $this->assertSame( 1, $code, $out );
        $this->assertSame( self::expected( [ $checks ] ), self::reported( $out ), $out );
        $line = self::lineOf( $checks, "\$utf8 = is_utf8_charset('UTF-8'); // expect: fail" );
        $this->assertMatchesRegularExpression( '/^includes\/checks\.php:' . $line . ': \[fail\] function is_utf8_charset\(\) @since WordPress 6\.6\.0 /m', $out, 'the acceptance fixture: an unguarded function with @since 6.6' );
        $this->assertStringContainsString( 'floors WordPress 6.5.0, WooCommerce 8.0.0, Action Scheduler 3.6.1', $out, 'floors come from the plugin header' );
        $this->assertStringContainsString( 'compat-api FAILED: 9 failing use(s), 7 guarded use(s), 1 deprecated symbol(s), 1 unknown symbol(s)', $out );

        [ $code, $out ] = self::runTool( [ PHP_BINARY, 'bin/compat-api.php', '--wp=' . $wp, '--root=' . self::API . '/clean' ] );
        $this->assertSame( 0, $code, $out );
        $this->assertSame( [], array_values( array_filter( self::reported( $out ), static fn( string $r ): bool => ! str_ends_with( $r, ':guarded' ) ) ), $out );
        $this->assertStringContainsString( 'compat-api OK: 0 failing use(s), 4 guarded use(s), 0 deprecated symbol(s), 0 unknown symbol(s)', $out );
    }

    public function testCompatApiReportsTheSeededViolationsAndAcceptsCleanCode(): void {
        $this->assertCompatApiVerdicts( self::ROOT . '/' . self::STAND_IN );
    }

    public function testOnTheLgaSiteSourceTheVerdictsHoldAndTheForkPasses(): void {
        $wp = (string) getenv( 'LGA_WP_PATH' );
        if ( '' === $wp || ! is_file( $wp . '/wp-includes/version.php' ) || ! is_file( $wp . '/wp-content/plugins/woocommerce/woocommerce.php' ) ) {
            $this->markTestSkipped( 'compat-api on the real source needs the WordPress and WooCommerce of the LGA site: set LGA_WP_PATH (DESIGN.md §12.1).' );
        }
        $this->assertCompatApiVerdicts( $wp );

        [ $code, $out ] = self::runTool( [ PHP_BINARY, 'bin/compat-api.php', '--wp=' . $wp ] );
        $this->assertSame( 0, $code, $out );
        $this->assertStringContainsString( 'floors WordPress 6.5.0, WooCommerce 8.0.0, Action Scheduler 3.6.1', $out );
        $this->assertMatchesRegularExpression( '/^compat-api OK: 0 failing use\(s\), \d+ guarded use\(s\), 0 deprecated symbol\(s\), \d+ unknown symbol\(s\)$/m', $out );
        $this->assertStringNotContainsString( 'tests/', $out, 'only the shipped files are a default target' );
    }

    public function testLintRunsBothScans(): void {
        $lint = (string) file_get_contents( self::ROOT . '/bin/lint.sh' );
        $this->assertStringContainsString( 'bash bin/php85-scan.sh --min=8.1', $lint );
        $this->assertStringContainsString( 'bin/compat-api.php', $lint );
        $this->assertMatchesRegularExpression( '/3\)\s*echo "compat-api: not run/', $lint, 'exit 3 (no WordPress source) is reported as not run, never as passed' );
    }
}
