<?php
/**
 * Frozen clock for unit tests, without a seam in the plugin's code.
 *
 * The plugin reads the time through unqualified calls (`time()`, `gmdate( 'c' )`, …) inside its own namespaces. PHP
 * resolves such a call to a function of the calling namespace first and falls back to the global one, so install()
 * (run once from tests/bootstrap.php, before any plugin code) declares `time()`, `gmdate()`, `date()` and
 * `strtotime()` in every namespace declared under includes/. Each one returns or uses FrozenClock::now(): the frozen
 * time while a test has set one ($GLOBALS['lga_test_time'], the same global the test mu-plugin of the integration
 * site reads), the real time otherwise, so a test that never sets it sees no difference. The WordPress stubs of the
 * bootstrap (current_time(), the transients, wp_date(), date_i18n(), human_time_diff()) read the same clock.
 *
 * The declarations must exist before the first plugin call of each function: PHP caches the resolved function per
 * call site. tests/bootstrap.php installs them right after registering the autoloaders.
 *
 * A clock seam added to the plugin later (DESIGN.md §13.2 WP2) stays frozen by these tests as long as it reads the
 * time through one of these: an unqualified time()/gmdate()/date()/strtotime() call in a plugin namespace,
 * current_time(), or the `lga_test_time` filter (which the tests that freeze the clock also register).
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Support;

final class FrozenClock {

    /** Functions declared in every plugin namespace. */
    public const FUNCTIONS = [ 'time', 'gmdate', 'date', 'strtotime' ];

    /** @var string[] namespaces that received the declarations */
    private static array $namespaces = [];

    /** The frozen Unix time, or the real time when no test froze it. */
    public static function now(): int {
        $frozen = $GLOBALS['lga_test_time'] ?? null;
        return is_int( $frozen ) ? $frozen : \time();
    }

    /** Freezes the clock at $timestamp (null: real time again). */
    public static function set( ?int $timestamp ): void {
        $GLOBALS['lga_test_time'] = $timestamp;
    }

    public static function isFrozen(): bool {
        return is_int( $GLOBALS['lga_test_time'] ?? null );
    }

    /** @return string[] the namespaces install() covered */
    public static function namespaces(): array {
        return self::$namespaces;
    }

    /** Declares the clock functions in every namespace of the PHP files under $dir (idempotent). */
    public static function install( string $dir ): void {
        $found = [];
        $files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $files as $file ) {
            if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
                continue;
            }
            $src = (string) file_get_contents( $file->getPathname() );
            if ( preg_match_all( '/^namespace\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*;/m', $src, $m ) ) {
                foreach ( $m[1] as $ns ) {
                    $found[ $ns ] = true;
                }
            }
        }
        $names = array_keys( $found );
        sort( $names );
        foreach ( $names as $ns ) {
            if ( in_array( $ns, self::$namespaces, true ) || function_exists( $ns . '\\time' ) ) {
                continue;
            }
            self::declare( $ns );
            self::$namespaces[] = $ns;
        }
    }

    private static function declare( string $ns ): void {
        $clock = '\\' . self::class . '::now()';
        // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test helper: declares functions in a namespace known only at run time.
        eval(
            'namespace ' . $ns . ';' .
            'function time(): int { return ' . $clock . '; }' .
            'function gmdate( string $format, ?int $timestamp = null ): string { return \\gmdate( $format, $timestamp ?? ' . $clock . ' ); }' .
            'function date( string $format, ?int $timestamp = null ): string { return \\date( $format, $timestamp ?? ' . $clock . ' ); }' .
            'function strtotime( string $datetime, ?int $baseTimestamp = null ): int|false { return \\strtotime( $datetime, $baseTimestamp ?? ' . $clock . ' ); }'
        );
    }
}
