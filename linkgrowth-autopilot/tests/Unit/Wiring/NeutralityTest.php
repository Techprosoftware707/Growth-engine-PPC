<?php
/**
 * Neutrality of the fork (DESIGN.md §1.7), on every unit run:
 * - bin/neutrality.sh passes for patterns A (old identifiers) and B (store data) over the working copy, and its
 *   patterns are applied a second time here in PHP over the same files, so a broken script cannot pass silently;
 * - the scanner finds planted hits (A, B, D), honours only the listed A exceptions, and checks the plugin ZIP and
 *   source ZIP layouts (--zip, --source);
 * - the pending list (bin/neutrality-pending.txt) names existing files only, each with the work package that removes
 *   its hits, B rows only for shipped files, and exactly the current number of hits (it can only shrink);
 * - structural checks (§1.7 E): the business profile and the AI model ship empty; data/ holds no file outside the
 *   §1.2 layout. The checks that need WP4a's settings schema are skipped with the reason.
 *
 * Every term a planted file needs is assembled at run time, so this file never matches the patterns it tests.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Wiring;

use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Tests\Support\TestCase;
use ZipArchive;

final class NeutralityTest extends TestCase {

    private const ROOT = __DIR__ . '/../../../';

    private const SHIPPED = [ 'linkgrowth-autopilot.php', 'uninstall.php', 'readme.txt', 'includes', 'assets', 'data', 'languages' ];

    /** @var string[] temporary folders and files */
    private array $tmp = [];

    protected function tearDown(): void {
        foreach ( array_reverse( $this->tmp ) as $path ) {
            self::remove( $path );
        }
        $this->tmp = [];
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0:int,1:string} exit code and output of bin/neutrality.sh with $args */
    private static function scan( string ...$args ): array {
        $cmd = 'bash ' . escapeshellarg( realpath( self::ROOT ) . '/bin/neutrality.sh' );
        foreach ( $args as $a ) {
            $cmd .= ' ' . escapeshellarg( $a );
        }
        $out  = [];
        $code = 0;
        exec( $cmd . ' 2>&1', $out, $code );
        return [ $code, implode( "\n", $out ) ];
    }

    /** The ERE of one pattern letter, read from bin/neutrality.sh (the single source of the patterns). */
    private static function pattern( string $letter ): string {
        $script = (string) file_get_contents( self::ROOT . 'bin/neutrality.sh' );
        if ( 1 === preg_match( "/^PAT_{$letter}='([^']+)'$/m", $script, $m ) ) {
            return $m[1]; // bash single quotes keep the text as it is
        }
        self::assertSame( 1, preg_match( '/^PAT_' . $letter . '="(.+)"$/m', $script, $m ), "PAT_{$letter} is defined" );
        return str_replace( '\\\\', '\\', $m[1] ); // inside bash double quotes "\\\\" is one backslash
    }

    /**
     * Lines matching an ERE (case-insensitive) in text files under $paths (relative to the fork root).
     *
     * @param string[] $paths
     * @param string[] $except exact file paths left out; an entry ending in / is a folder
     * @param string|null $base the tree to scan (default: this working copy)
     * @return string[] "path:line"
     */
    private static function phpGrep( string $ere, array $paths, array $except = [], ?string $base = null ): array {
        $re   = '~' . str_replace( '~', '\~', $ere ) . '~i';
        $root = realpath( $base ?? self::ROOT ) . '/';
        $out  = [];
        foreach ( $paths as $p ) {
            $abs = '.' === $p ? rtrim( $root, '/' ) : $root . $p;
            if ( ! file_exists( $abs ) ) {
                continue;
            }
            $files = is_dir( $abs ) ? new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $abs, \FilesystemIterator::SKIP_DOTS ) ) : [ new \SplFileInfo( $abs ) ];
            foreach ( $files as $f ) {
                $rel = substr( $f->getPathname(), strlen( $root ) );
                // As bin/neutrality.sh: folders the build never ships, and only the top-level build output dist/.
                if ( preg_match( '~(^|/)(\.git|node_modules|\.phpunit\.cache)/|^dist/~', $rel ) ) {
                    continue;
                }
                foreach ( $except as $entry ) {
                    if ( str_ends_with( $entry, '/' ) ? str_starts_with( $rel, $entry ) : $rel === $entry ) {
                        continue 2;
                    }
                }
                $text = (string) file_get_contents( $f->getPathname() );
                if ( str_contains( $text, "\0" ) ) {
                    continue; // binary (bin/phpunit.phar)
                }
                foreach ( preg_split( '/\R/', $text ) ?: [] as $n => $line ) {
                    if ( preg_match( $re, $line ) ) {
                        $out[] = $rel . ':' . ( $n + 1 );
                    }
                }
            }
        }
        return $out;
    }

    /** @return array<int,array{0:string,1:int,2:string,3:string}> rows of the pending list */
    private static function pending(): array {
        $rows = [];
        foreach ( file( self::ROOT . 'bin/neutrality-pending.txt', FILE_IGNORE_NEW_LINES ) ?: [] as $line ) {
            if ( '' === trim( $line ) || str_starts_with( ltrim( $line ), '#' ) ) {
                continue;
            }
            $parts = preg_split( '/\s+/', trim( $line ) ) ?: [];
            self::assertCount( 4, $parts, 'pending row: ' . $line );
            $rows[] = [ $parts[0], (int) $parts[1], $parts[2], $parts[3] ];
        }
        return $rows;
    }

    private function tempDir(): string {
        $dir = sys_get_temp_dir() . '/lga-neutrality-test-' . bin2hex( random_bytes( 6 ) );
        mkdir( $dir, 0700, true );
        $this->tmp[] = $dir;
        return $dir;
    }

    private static function put( string $path, string $text ): void {
        if ( ! is_dir( dirname( $path ) ) ) {
            mkdir( dirname( $path ), 0700, true );
        }
        file_put_contents( $path, $text );
    }

    /** @param array<string,string> $files archive path => content */
    private function zip( array $files ): string {
        $file = $this->tempDir() . '/test.zip';
        $zip  = new ZipArchive();
        $this->assertTrue( true === $zip->open( $file, ZipArchive::CREATE ) );
        foreach ( $files as $name => $content ) {
            $zip->addFromString( $name, $content );
        }
        $zip->close();
        return $file;
    }

    private static function remove( string $path ): void {
        if ( is_dir( $path ) && ! is_link( $path ) ) {
            foreach ( scandir( $path ) ?: [] as $entry ) {
                if ( '.' !== $entry && '..' !== $entry ) {
                    self::remove( $path . '/' . $entry );
                }
            }
            rmdir( $path );
        } elseif ( file_exists( $path ) || is_link( $path ) ) {
            unlink( $path );
        }
    }

    /** An old engine identifier, a store word and a model id, assembled so this file holds none of them. */
    private static function oldIdentifier(): string {
        return 'pp' . 'clg_settings';
    }

    private static function storeWord(): string {
        return 'pep' . 'tide';
    }

    private static function modelId(): string {
        return 'cla' . 'ude-opus-' . '9-9';
    }

    /** A category slug of the store's catalog (pattern B covers the catalog, not only the brand). */
    private static function storeCatalogTerm(): string {
        return 'weight-loss' . '-metabolic';
    }

    /** A market word of pattern C. */
    private static function marketWord(): string {
        return 'Toront' . 'o';
    }

    /** A scratch copy of the scanner with the given files (path => content). */
    private function plantedTree( array $files ): string {
        $root = $this->tempDir();
        mkdir( $root . '/bin' );
        copy( self::ROOT . 'bin/neutrality.sh', $root . '/bin/neutrality.sh' );
        foreach ( $files as $path => $content ) {
            self::put( $root . '/' . $path, $content );
        }
        return $root;
    }

    // ------------------------------------------------------------------ the working copy

    public function testPatternAFindsNoOldIdentifierAnywhereInTheFork(): void {
        [ $code, $out ] = self::scan( '--only=A' );
        $this->assertSame( 0, $code, $out );
        $this->assertStringContainsString( 'pattern A: 0 hits', $out );
        // The same pattern in PHP over the whole fork, with only the two listed exceptions.
        $this->assertSame( [], self::phpGrep( self::pattern( 'A' ), [ '.' ], [ 'docs/DESIGN.md', 'tests/integration/coexistence.php' ] ) );
    }

    public function testPatternBFindsNoStoreDataInTestsAndOnlyPendingHitsInShippedFiles(): void {
        [ $code, $out ] = self::scan( '--only=B' );
        $this->assertSame( 0, $code, $out );
        $this->assertMatchesRegularExpression( '/pattern B: (0 hits|\d+ hit\(s\), all in files of the pending list)/', $out );
        // Tests, fixtures, bin and the configs: no store data at all, with no exception.
        $this->assertSame( [], self::phpGrep( self::pattern( 'B' ), [ 'tests', 'bin', 'composer.json', 'phpunit.xml.dist' ] ) );
        // Shipped files: only the pending rows, at their listed counts.
        $allowed = [];
        foreach ( self::pending() as [ $letter, $count, $path ] ) {
            if ( 'B' === $letter ) {
                $allowed[ $path ] = $count;
            }
        }
        $found = [];
        foreach ( self::phpGrep( self::pattern( 'B' ), self::SHIPPED ) as $hit ) {
            $file           = explode( ':', $hit )[0];
            $found[ $file ] = ( $found[ $file ] ?? 0 ) + 1;
        }
        ksort( $found );
        ksort( $allowed );
        $this->assertSame( $allowed, $found, 'every shipped file with store data is on the pending list with its exact count' );
    }

    public function testPatternDFindsNoModelIdentifierAnywhere(): void {
        [ $code, $out ] = self::scan( '--only=D' );
        $this->assertSame( 0, $code, $out );
        $this->assertStringContainsString( 'pattern D: 0 hits', $out );
        $this->assertSame( [], self::phpGrep( self::pattern( 'D' ), [ '.' ] ), 'docs included' );
        $this->assertSame( '', Settings::defaults()['providers']['ai']['model'] );
    }

    public function testTheWholeScanPassesAndLintRunsIt(): void {
        [ $code, $out ] = self::scan();
        $this->assertSame( 0, $code, $out );
        $this->assertStringContainsString( 'Neutrality OK (repo)', $out );
        $this->assertStringContainsString( 'bash bin/neutrality.sh', (string) file_get_contents( self::ROOT . 'bin/lint.sh' ) );
    }

    // ------------------------------------------------------------------ the scanner itself

    public function testPlantedHitsAreFoundAndOnlyTheListedExceptionsApply(): void {
        $root = $this->tempDir();
        mkdir( $root . '/bin' );
        copy( self::ROOT . 'bin/neutrality.sh', $root . '/bin/neutrality.sh' );
        self::put( $root . '/includes/Clean.php', "<?php\n// Northwind Supply Co\n" );
        [ $code, $out ] = self::scan( '--root', $root );
        $this->assertSame( 0, $code, 'a clean tree passes: ' . $out );

        self::put( $root . '/includes/Old.php', "<?php\nget_option( '" . self::oldIdentifier() . "' );\n" );
        self::put( $root . '/tests/Unit/StoreTest.php', "<?php\n// a " . self::storeWord() . " supplier\n" );
        self::put( $root . '/tests/Unit/CatalogTest.php', "<?php\n// slug " . self::storeCatalogTerm() . "\n" );
        self::put( $root . '/docs/notes/X.md', 'model: ' . self::modelId() . "\n" );
        self::put( $root . '/docs/DESIGN.md', 'map: ' . self::oldIdentifier() . "\n" );
        self::put( $root . '/tests/integration/coexistence.php', "<?php\n// activates the " . self::oldIdentifier() . " plugin\n" );
        [ $code, $out ] = self::scan( '--root', $root );
        $this->assertSame( 1, $code, $out );
        $this->assertStringContainsString( 'pattern A: includes/Old.php has 1 matching line(s), allowed 0', $out );
        $this->assertStringContainsString( 'pattern B: store data outside the shipped files (never allowed)', $out );
        $this->assertStringContainsString( 'tests/Unit/StoreTest.php:2:', $out );
        $this->assertStringContainsString( 'tests/Unit/CatalogTest.php:2:', $out, 'the store catalog is store data too' );
        $this->assertStringContainsString( 'pattern D: docs/notes/X.md has 1 matching line(s)', $out );
        $this->assertStringNotContainsString( 'docs/DESIGN.md', $out, 'DESIGN.md documents the map (exception A)' );
        $this->assertStringNotContainsString( 'coexistence.php', $out, 'the coexistence test activates the engine (exception A)' );

        // --only narrows the patterns.
        [ $code, $out ] = self::scan( '--root', $root, '--only=D' );
        $this->assertSame( 1, $code );
        $this->assertStringNotContainsString( 'pattern A', $out );
    }

    public function testZipModeChecksTheLayoutAndHasNoExceptions(): void {
        [ $code, $out ] = self::scan( '--zip', $this->zip( [ 'linkgrowth-autopilot/linkgrowth-autopilot.php' => "<?php\n", 'linkgrowth-autopilot/includes/A.php' => "<?php\n" ] ) );
        $this->assertSame( 0, $code, $out );
        $this->assertStringContainsString( 'Neutrality OK (zip)', $out );

        [ $code, $out ] = self::scan( '--zip', $this->zip( [ 'linkgrowth-autopilot/a.php' => "<?php\n", 'linkgrowth-autopilot/tests/x.php' => "<?php\n", 'linkgrowth-autopilot/docs/DESIGN.md' => 'map: ' . self::oldIdentifier() ] ) );
        $this->assertSame( 1, $code );
        $this->assertStringContainsString( 'the plugin ZIP must not contain tests/', $out );
        $this->assertStringContainsString( 'the plugin ZIP must not contain docs/', $out );
        $this->assertStringContainsString( 'pattern A: docs/DESIGN.md', $out, 'no exception inside the ZIP' );

        [ $code, $out ] = self::scan( '--zip', $this->zip( [ 'linkgrowth-autopilot/a.php' => "<?php\n", 'extra/b.php' => "<?php\n" ] ) );
        $this->assertSame( 1, $code );
        $this->assertStringContainsString( 'exactly one top folder linkgrowth-autopilot/', $out );
    }

    public function testSourceModeScansEverythingWithNoExceptionsAndNoPendingList(): void {
        $clean = [ 'linkgrowth-autopilot-source/linkgrowth-autopilot.php' => "<?php\n", 'linkgrowth-autopilot-source/tests/T.php' => "<?php\n", 'linkgrowth-autopilot-source/docs/README.md' => "Docs\n" ];
        [ $code, $out ] = self::scan( '--source', $this->zip( $clean ) );
        $this->assertSame( 0, $code, $out );

        // A pending file in the source ZIP is still a failure (the list is never read), and so is DESIGN.md.
        $dirty = $clean + [
            'linkgrowth-autopilot-source/includes/Content/ContentGenerator.php' => "<?php\n// " . self::storeWord() . "\n",
            'linkgrowth-autopilot-source/docs/DESIGN.md'                       => 'map: ' . self::oldIdentifier() . "\n",
        ];
        [ $code, $out ] = self::scan( '--source', $this->zip( $dirty ) );
        $this->assertSame( 1, $code );
        $this->assertStringContainsString( 'pattern B: includes/Content/ContentGenerator.php has 1 matching line(s), allowed 0', $out );
        $this->assertStringContainsString( 'pattern A: docs/DESIGN.md has 1 matching line(s), allowed 0', $out );
        $this->assertStringNotContainsString( 'pattern C', $out, 'pattern C is not part of the source check' );
    }

    public function testExceptionsMatchWholePathsOnly(): void {
        $old  = 'map: ' . self::oldIdentifier() . "\n";
        $root = $this->plantedTree(
            [
                'docs/DESIGN.md'                         => $old,
                'docs/DESIGN.md.bak'                     => $old,
                'tests/integration/coexistence.php'      => "<?php\n// " . $old,
                'tests/integration/coexistence.php.orig' => "<?php\n// " . $old,
                'includes/Outreach/Jurisdictions.php'     => "<?php\n// " . self::marketWord() . "\n",
                'includes/Outreach/Jurisdictions.php.bak' => "<?php\n// " . self::marketWord() . "\n",
                'tests/Unit/Profile/CountryTest.php'      => "<?php\n// " . self::marketWord() . "\n",
            ]
        );
        [ $code, $out ] = self::scan( '--root', $root );
        $this->assertSame( 1, $code, $out );
        $this->assertStringContainsString( 'pattern A: docs/DESIGN.md.bak has 1 matching line(s), allowed 0', $out, 'a copy of an excepted file is not excepted' );
        $this->assertStringContainsString( 'pattern A: tests/integration/coexistence.php.orig has 1 matching line(s), allowed 0', $out );
        $this->assertStringContainsString( 'pattern C: includes/Outreach/Jurisdictions.php.bak has 1 matching line(s), allowed 0', $out, 'the C allowlist names files, not prefixes' );
        $this->assertStringNotContainsString( 'docs/DESIGN.md:', $out );
        $this->assertStringNotContainsString( 'coexistence.php:', $out );
        $this->assertStringNotContainsString( 'Jurisdictions.php:', $out );
        $this->assertStringNotContainsString( 'CountryTest.php', $out, 'an allowlisted folder (trailing /) covers its files' );

        // The plugin ZIP: the C allowlist is exact there too.
        [ $code, $out ] = self::scan( '--zip', $this->zip( [ 'linkgrowth-autopilot/linkgrowth-autopilot.php' => "<?php\n", 'linkgrowth-autopilot/includes/Outreach/Jurisdictions.php.bak' => "<?php\n// " . self::marketWord() . "\n" ] ) );
        $this->assertSame( 1, $code, $out );
        $this->assertStringContainsString( 'pattern C: includes/Outreach/Jurisdictions.php.bak has 1 matching line(s), allowed 0', $out );

        // The PHP twin applies the same rule.
        $hits = self::phpGrep( self::pattern( 'A' ), [ '.' ], [ 'docs/DESIGN.md', 'tests/integration/coexistence.php' ], $root );
        sort( $hits );
        $this->assertSame( [ 'docs/DESIGN.md.bak:1', 'tests/integration/coexistence.php.orig:2' ], $hits );
    }

    public function testNestedDistFoldersAreScannedAndOnlyTheTopLevelBuildOutputIsSkipped(): void {
        $old  = 'export const option = "' . self::oldIdentifier() . "\";\n";
        $root = $this->plantedTree(
            [
                'includes/Clean.php'     => "<?php\n",
                'assets/js/dist/probe.js' => $old,
                'dist/old-build.txt'      => $old, // the top-level build output folder (git-ignored)
            ]
        );
        [ $code, $out ] = self::scan( '--root', $root );
        $this->assertSame( 1, $code, $out );
        $this->assertStringContainsString( 'pattern A: assets/js/dist/probe.js has 1 matching line(s), allowed 0', $out, 'a bundle folder ships and is scanned' );
        $this->assertStringNotContainsString( 'old-build.txt', $out, 'only the top-level dist/ is build output' );
        $this->assertSame( [ 'assets/js/dist/probe.js:1' ], self::phpGrep( self::pattern( 'A' ), [ '.' ], [], $root ), 'the PHP twin skips only the top-level dist/' );

        [ $code, $out ] = self::scan( '--zip', $this->zip( [ 'linkgrowth-autopilot/linkgrowth-autopilot.php' => "<?php\n", 'linkgrowth-autopilot/assets/js/dist/probe.js' => $old ] ) );
        $this->assertSame( 1, $code, $out );
        $this->assertStringContainsString( 'pattern A: assets/js/dist/probe.js has 1 matching line(s), allowed 0', $out );

        [ $code, $out ] = self::scan( '--source', $this->zip( [ 'linkgrowth-autopilot-source/linkgrowth-autopilot.php' => "<?php\n", 'linkgrowth-autopilot-source/assets/js/dist/probe.js' => '// ' . self::storeWord() . "\n" ] ) );
        $this->assertSame( 1, $code, $out );
        $this->assertStringContainsString( 'pattern B: assets/js/dist/probe.js has 1 matching line(s), allowed 0', $out );
    }

    public function testAFailedBuildLeavesNoArchiveUnderTheReleaseNames(): void {
        foreach ( [ 'zip', 'unzip' ] as $tool ) {
            $found = [];
            $code  = 0;
            exec( 'command -v ' . $tool . ' 2>/dev/null', $found, $code );
            if ( 0 !== $code ) {
                $this->markTestSkipped( "{$tool} is needed to run bin/build-zip.sh." );
            }
        }
        // A minimal tree with the real build and scan scripts; lint is stubbed (it has its own checks).
        $root = $this->plantedTree(
            [
                'linkgrowth-autopilot.php' => "<?php\n/**\n * Plugin Name: Build probe\n * Version: 9.9.9\n */\n",
                'includes/Clean.php'       => "<?php\n",
                'tests/CleanTest.php'      => "<?php\n",
                'bin/lint.sh'              => "#!/usr/bin/env bash\nexit 0\n",
            ]
        );
        copy( self::ROOT . 'bin/build-zip.sh', $root . '/bin/build-zip.sh' );
        $out   = $this->tempDir();
        $stage = $this->tempDir();
        $build = static function () use ( $root, $out, $stage ): array {
            $lines = [];
            $code  = 0;
            exec( 'OUT_DIR=' . escapeshellarg( $out ) . ' TMPDIR=' . escapeshellarg( $stage ) . ' bash ' . escapeshellarg( $root . '/bin/build-zip.sh' ) . ' 2>&1', $lines, $code );
            return [ $code, implode( "\n", $lines ) ];
        };
        $plugin = $out . '/linkgrowth-autopilot-9.9.9.zip';
        $source = $out . '/linkgrowth-autopilot-9.9.9-source.zip';

        [ $code, $log ] = $build();
        $this->assertSame( 0, $code, $log );
        $this->assertFileExists( $plugin );
        $this->assertFileExists( $source );

        // Store data in a test file: the plugin ZIP passes its check, the source ZIP does not, so the build fails
        // and neither release name is left in the output folder (not even the archives of the previous build).
        self::put( $root . '/tests/StoreTest.php', "<?php\n// a " . self::storeWord() . " supplier\n" );
        [ $code, $log ] = $build();
        $this->assertNotSame( 0, $code, $log );
        $this->assertStringContainsString( 'Neutrality OK (zip)', $log );
        $this->assertStringContainsString( 'Neutrality FAILED (source)', $log );
        $this->assertFileDoesNotExist( $plugin, 'a failed build publishes no plugin ZIP' );
        $this->assertFileDoesNotExist( $source, 'the rejected source ZIP is not left under the release name' );
        $this->assertSame( [], array_values( array_diff( scandir( $out ) ?: [], [ '.', '..' ] ) ), 'nothing else is left in the output folder' );
        $this->assertSame( [], array_values( array_diff( scandir( $stage ) ?: [], [ '.', '..' ] ) ), 'the staging folder is removed' );
    }

    public function testThePendingListNamesExistingFilesOwnersAndExactCounts(): void {
        $rows = self::pending();
        $this->assertNotEmpty( $rows );
        $counts = [ 'B' => [], 'C' => [] ];
        foreach ( [ 'B', 'C' ] as $letter ) {
            $except = 'C' === $letter ? [ 'data/packs/industry/research-supplies.json', 'data/packs/country/ca.json', 'includes/Outreach/Jurisdictions.php', 'includes/Profile/CountryData.php', 'includes/Content/ClaimPacks.php', 'tests/Unit/Profile/', 'tests/Unit/Outreach/JurisdictionsTest.php' ] : [];
            $scope  = 'C' === $letter ? array_merge( self::SHIPPED, [ 'tests' ] ) : self::SHIPPED;
            foreach ( self::phpGrep( self::pattern( $letter ), $scope, $except ) as $hit ) {
                $file                       = explode( ':', $hit )[0];
                $counts[ $letter ][ $file ] = ( $counts[ $letter ][ $file ] ?? 0 ) + 1;
            }
        }
        $seen = [];
        foreach ( $rows as [ $letter, $count, $path, $owner ] ) {
            $this->assertContains( $letter, [ 'B', 'C' ], $path );
            $this->assertFileExists( self::ROOT . $path );
            $this->assertMatchesRegularExpression( '/^WP(\d+[ab]?|1-part2)$/', $owner, $path );
            $this->assertGreaterThan( 0, $count, $path );
            $this->assertArrayNotHasKey( $letter . $path, $seen, 'one row per pattern and file' );
            $seen[ $letter . $path ] = true;
            if ( 'B' === $letter ) {
                $this->assertMatchesRegularExpression( '#^(linkgrowth-autopilot\.php|uninstall\.php|readme\.txt|includes/|assets/|data/|languages/)#', $path, 'B rows are shipped files only' );
            }
            $this->assertSame( $count, $counts[ $letter ][ $path ] ?? 0, "lower the row of {$path} when its hits are removed" );
        }
        foreach ( $counts as $letter => $files ) {
            foreach ( array_keys( $files ) as $file ) {
                $this->assertArrayHasKey( $letter . $file, $seen, "{$file} has pattern {$letter} hits but is not on the pending list" );
            }
        }
    }

    // ------------------------------------------------------------------ structural checks (§1.7 E)

    public function testTheBusinessProfileAndTheAiModelShipEmpty(): void {
        $site = Settings::defaults()['site'];
        foreach ( [ 'brand_name', 'legal_name', 'tagline', 'email', 'address', 'city', 'region', 'postal_code', 'country', 'founded_year' ] as $key ) {
            $this->assertSame( '', $site[ $key ], 'site.' . $key );
        }
        $this->assertSame( [], $site['social'] );
        $this->assertSame( [], $site['categories'] );
        $this->assertSame( [], Settings::defaults()['discovery']['brand_terms'] );
        $this->assertSame( '', Settings::defaults()['providers']['ai']['model'] );
    }

    public function testNoDefaultEnablesOutboundAcquisition(): void {
        $this->assertFalse( Settings::defaults()['automation']['autopilot'] );
        $this->markTestSkipped( 'DESIGN.md §1.7 E: automation.modules.submissions, outreach_drafts and distribution are keys of the WP4a settings schema (§2.2); the engine go-live defaults automation.submissions and auto_outreach_drafts become the neutral defaults in WP4a (§2.4 row "Go-live defaults").' );
    }

    public function testDataHoldsNoFileOutsideTheLayout(): void {
        $allowed = [ 'data/universal/keywords-universal.json', 'data/universal/excluded-domains.json', 'data/universal/query-templates.json', 'data/packs/industry/general.json', 'data/packs/industry/research-supplies.json', 'data/catalog/global-platforms.json', 'data/schema/industry-pack.schema.json', 'data/schema/country-pack.schema.json', 'data/schema/catalog.schema.json' ];
        foreach ( [ 'us', 'ca', 'gb', 'ie', 'au', 'nz', 'de', 'fr' ] as $cc ) {
            $allowed[] = 'data/packs/country/' . $cc . '.json';
        }
        $allowed[] = 'data/universal/keywords.json'; // the engine taxonomy until WP4a rewrites it as keywords-universal.json (§1.3 step 1)
        $root      = realpath( self::ROOT ) . '/';
        foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . 'data', \FilesystemIterator::SKIP_DOTS ) ) as $f ) {
            $this->assertContains( substr( $f->getPathname(), strlen( $root ) ), $allowed );
        }
    }

    public function testDataHoldsExactlyTheLayoutFiles(): void {
        $this->markTestSkipped( 'DESIGN.md §1.7 E "data/ contains only the files listed in §1.2": the packs, schemas, catalog and universal files are written in WP4a (and the catalog in WP9); until then testDataHoldsNoFileOutsideTheLayout keeps out every file that is not part of that layout.' );
    }
}
