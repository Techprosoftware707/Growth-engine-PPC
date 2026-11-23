<?php
/**
 * compat-min (DESIGN.md §12.1, §12.9): the plugin on the declared minimum versions, WordPress 6.5.x and
 * WooCommerce 8.0.x, on the site that tests/integration/build-compat-min-site.sh builds (http://localhost:8797).
 *
 * Usage: LGA_WP_PATH=$S/wp-min-lga/wordpress php tests/integration/compat-min.php
 *
 * Covers activation, setup, one tick and deactivation (§12.9). The setup wizard of the design is WP27's and does not
 * exist yet, so setup is the owner's settings save through REST (POST /lga/v1/settings with the Northwind Supply Co
 * profile of tests/Support/Profiles.php) until then. Each phase runs in its own PHP process, because a plugin
 * activated in a process only boots (plugins_loaded, init, rest_api_init) in the next one:
 *   1. activate   reset the plugin state, activate with no unexpected output, tables, version, capability, schedule
 *   2. loaded     boot hooks, REST routes, settings save, overview, one tick, then a visitor's page view and the
 *                 owner's dashboard page served by `PHP_BINARY -S localhost:8797` (started and stopped here); no
 *                 outbound request
 *   3. deactivate schedules gone, tables and settings kept
 * and finally: the run added no PHP error, warning or deprecation from a plugin file to the site's debug.log.
 * Prints "Passed: N  Failed: M" (or "Not run: <reason>" when the site is not the compat-min stack) and exits
 * non-zero on any failure.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

const LGA_CM_PORT   = 8797;
const LGA_CM_PLUGIN = 'linkgrowth-autopilot/linkgrowth-autopilot.php';

$wpPath = rtrim( (string) ( getenv( 'LGA_WP_PATH' ) ?: '' ), '/' );
if ( '' === $wpPath || ! is_file( $wpPath . '/wp-load.php' ) ) {
    echo "Not run: set LGA_WP_PATH to the compat-min WordPress (tests/integration/build-compat-min-site.sh)\n";
    exit( 2 );
}
$phase = (string) ( getenv( 'LGA_CM_PHASE' ) ?: '' );

final class CM {
    public static int $pass = 0;
    public static int $fail = 0;
    /** @var list<string> */
    public static array $failures = [];
    public static function check( bool $ok, string $label, string $detail = '' ): void {
        if ( $ok ) {
            self::$pass++;
            echo "  ✔ {$label}\n";
            return;
        }
        self::$fail++;
        self::$failures[] = $label . ( '' !== $detail ? ' — ' . $detail : '' );
        echo "  ✘ {$label}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
    }
}

// --------------------------------------------------------------------------- parent: runs the three phases
if ( '' === $phase ) {
    $debugLog = $wpPath . '/wp-content/debug.log';
    clearstatcache();
    $offset = is_file( $debugLog ) ? (int) filesize( $debugLog ) : 0;
    $pass   = 0;
    $fail   = 0;
    $lines  = [];
    foreach ( [ 'activate', 'loaded', 'deactivate' ] as $p ) {
        echo "\n== compat-min: {$p}\n";
        $out  = [];
        $code = 0;
        exec( 'LGA_CM_PHASE=' . escapeshellarg( $p ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1', $out, $code );
        echo implode( "\n", $out ) . "\n";
        $text = implode( "\n", $out );
        if ( preg_match( '/^Not run: (.+)$/m', $text, $m ) ) {
            echo "\nNot run: {$m[1]}\n";
            exit( 3 );
        }
        if ( ! preg_match( '/^PHASE ' . $p . ': passed (\d+) failed (\d+)$/m', $text, $m ) ) {
            ++$fail;
            echo "  ✘ phase {$p} finished — exit {$code}\n";
            continue;
        }
        $pass += (int) $m[1];
        $fail += (int) $m[2];
        if ( 0 !== $code && 0 === (int) $m[2] ) {
            ++$fail;
            echo "  ✘ phase {$p} exited {$code}\n";
        }
    }
    echo "\n== compat-min: PHP errors, warnings and deprecations from the plugin\n";
    clearstatcache();
    $size  = is_file( $debugLog ) ? (int) filesize( $debugLog ) : 0;
    $added = $size > 0 ? (string) file_get_contents( $debugLog, false, null, $size >= $offset ? $offset : 0 ) : '';
    $dirs  = [ $wpPath . '/wp-content/plugins/linkgrowth-autopilot', (string) realpath( $wpPath . '/wp-content/plugins/linkgrowth-autopilot' ), dirname( __DIR__, 2 ) ];
    $dirs  = implode( '|', array_map( static fn( string $d ): string => preg_quote( $d, '#' ), array_unique( array_filter( $dirs ) ) ) );
    preg_match_all( '#^.*PHP (?:Deprecated|Warning|Notice|Fatal error|Parse error|Recoverable fatal error):.* in (?:' . $dirs . ')/(?:includes/|linkgrowth-autopilot\.php|uninstall\.php).*$#m', $added, $mm );
    $lines = array_values( array_unique( array_map( static fn( string $l ): string => (string) preg_replace( '/^\[[^\]]+\]\s*/', '', $l ), $mm[0] ) ) );
    if ( [] === $lines ) {
        ++$pass;
        echo '  ✔ the run added no PHP error, warning or deprecation from a plugin file to debug.log (PHP ' . PHP_VERSION . ")\n";
    } else {
        ++$fail;
        echo '  ✘ the run added PHP errors from plugin files to debug.log — ' . implode( ' | ', array_slice( $lines, 0, 5 ) ) . "\n";
    }
    echo "\n==============================\n";
    printf( "Passed: %d  Failed: %d\n", $pass, $fail );
    exit( $fail > 0 ? 1 : 0 );
}

// --------------------------------------------------------------------------- phases: WordPress in this process
$_SERVER['HTTP_HOST']      = 'localhost:' . LGA_CM_PORT;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
require $wpPath . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once dirname( __DIR__ ) . '/Support/Profiles.php';

$wpVersion = (string) get_bloginfo( 'version' );
$wcVersion = defined( 'WC_VERSION' ) ? (string) WC_VERSION : '';
if ( ! str_starts_with( $wpVersion, '6.5.' ) || ! str_starts_with( $wcVersion, '8.0.' ) ) {
    echo 'Not run: the site at LGA_WP_PATH runs WordPress ' . $wpVersion . ' and WooCommerce ' . ( '' !== $wcVersion ? $wcVersion : 'none' ) . ", not 6.5.x and 8.0.x\n";
    exit( 3 );
}
echo "  WordPress {$wpVersion}, WooCommerce {$wcVersion}, PHP " . PHP_VERSION . "\n";

// Offline: nothing leaves this machine. The site itself (the front-end check) is the only allowed host.
$GLOBALS['cmRefused'] = [];
add_filter(
    'pre_http_request',
    static function ( $pre, $args, $url ) {
        $host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
        $port = (int) wp_parse_url( (string) $url, PHP_URL_PORT );
        if ( in_array( $host, [ 'localhost', '127.0.0.1' ], true ) && LGA_CM_PORT === $port ) {
            return $pre;
        }
        $GLOBALS['cmRefused'][ $host ] = ( $GLOBALS['cmRefused'][ $host ] ?? 0 ) + 1;
        return new WP_Error( 'lga_cm_offline', 'Outbound request refused by compat-min (offline): ' . $host );
    },
    1,
    3
);
wp_set_current_user( 1 );

/** @return array{cron:bool,as:int} the WP-Cron event and pending Action Scheduler actions of the plugin's tick */
function lga_cm_schedule(): array {
    $as = 0;
    if ( function_exists( 'as_get_scheduled_actions' ) ) {
        $as = count( (array) as_get_scheduled_actions( [ 'hook' => 'lga_tick', 'group' => 'lga', 'status' => 'pending', 'per_page' => 10 ], 'ids' ) );
    }
    return [ 'cron' => false !== wp_next_scheduled( 'lga_tick' ), 'as' => $as ];
}

/** @return list<string> the plugin's tables that exist */
function lga_cm_tables(): array {
    global $wpdb;
    $out = [];
    foreach ( \LinkGrowth\Autopilot\Database\Schema::names() as $t ) {
        $name = $wpdb->prefix . 'lga_' . $t;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name ) {
            $out[] = $t;
        }
    }
    return $out;
}

try {
    if ( 'activate' === $phase ) {
        global $wpdb;
        if ( is_plugin_active( LGA_CM_PLUGIN ) ) {
            deactivate_plugins( LGA_CM_PLUGIN );
        }
        if ( ! class_exists( \LinkGrowth\Autopilot\Database\Schema::class ) ) {
            require_once WP_PLUGIN_DIR . '/linkgrowth-autopilot/includes/Autoloader.php';
            \LinkGrowth\Autopilot\Autoloader::register( WP_PLUGIN_DIR . '/linkgrowth-autopilot/includes', 'LinkGrowth\\Autopilot\\' );
        }
        foreach ( \LinkGrowth\Autopilot\Database\Schema::names() as $t ) {
            $wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'lga_' . $t . '`' ); // phpcs:ignore
        }
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lga\_%' OR option_name LIKE '\_transient\_lga\_%' OR option_name LIKE '\_transient\_timeout\_lga\_%'" ); // phpcs:ignore
        wp_cache_flush();
        CM::check( [] === lga_cm_tables() && false === get_option( 'lga_db_version' ), 'plugin state reset (no table, no option)' );

        ob_start();
        $r      = activate_plugin( LGA_CM_PLUGIN );
        $output = (string) ob_get_clean();
        CM::check( null === $r, 'activate_plugin returned no error', is_wp_error( $r ) ? $r->get_error_message() : '' );
        CM::check( '' === $output, 'the activation printed nothing (no "unexpected output")', substr( $output, 0, 200 ) );
        CM::check( is_plugin_active( LGA_CM_PLUGIN ), 'plugin active' );
        CM::check( lga_cm_tables() === \LinkGrowth\Autopilot\Database\Schema::names(), 'all ' . count( \LinkGrowth\Autopilot\Database\Schema::names() ) . ' plugin tables exist', implode( ',', lga_cm_tables() ) );
        CM::check( get_option( 'lga_db_version' ) === LGA_DB_VERSION, 'db version stored', (string) wp_json_encode( get_option( 'lga_db_version' ) ) );
        $admin = get_role( 'administrator' );
        CM::check( null !== $admin && $admin->has_cap( 'lga_manage' ), 'administrator has lga_manage' );
        $s = lga_cm_schedule();
        CM::check( $s['cron'] || $s['as'] > 0, 'the tick is scheduled (WP-Cron event or Action Scheduler action)', (string) wp_json_encode( $s ) );
        CM::check( [] === $GLOBALS['cmRefused'], 'the activation made no outbound request', (string) wp_json_encode( $GLOBALS['cmRefused'] ) );
    } elseif ( 'loaded' === $phase ) {
        CM::check( is_plugin_active( LGA_CM_PLUGIN ) && function_exists( 'lga' ), 'the plugin loaded with WordPress (lga() defined)' );
        CM::check( false !== has_action( 'lga_tick' ), 'the tick handler is hooked (lga_tick)' );
        $routes = rest_get_server()->get_routes();
        CM::check( isset( $routes['/lga/v1/settings'], $routes['/lga/v1/overview'] ), 'the REST routes are registered (lga/v1)', (string) count( array_filter( array_keys( $routes ), static fn( string $r ): bool => str_starts_with( $r, '/lga/v1' ) ) ) );

        // Setup (stand-in for the WP27 wizard): the owner saves the business profile.
        $profile = \LinkGrowth\Autopilot\Tests\Support\Profiles::SITES[ \LinkGrowth\Autopilot\Tests\Support\Profiles::NORTHWIND ];
        $req     = new WP_REST_Request( 'POST', '/lga/v1/settings' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( [ 'settings' => [ 'site' => $profile ] ] ) );
        $res = rest_do_request( $req );
        CM::check( 200 === $res->get_status(), 'POST /lga/v1/settings with the Northwind Supply Co profile answers 200', $res->get_status() . ' ' . substr( (string) wp_json_encode( $res->get_data() ), 0, 200 ) );
        $settings = lga()->get( \LinkGrowth\Autopilot\Support\Settings::class );
        CM::check( 'Northwind Supply Co' === $settings->get( 'site.brand_name' ) && 'northwind.test' === $settings->siteDomain(), 'the profile is stored', (string) wp_json_encode( [ $settings->get( 'site.brand_name' ), $settings->siteDomain() ] ) );
        $res = rest_do_request( new WP_REST_Request( 'GET', '/lga/v1/settings' ) );
        CM::check( 200 === $res->get_status(), 'GET /lga/v1/settings answers 200', (string) $res->get_status() );
        $res = rest_do_request( new WP_REST_Request( 'GET', '/lga/v1/overview' ) );
        CM::check( 200 === $res->get_status() && is_array( $res->get_data() ), 'GET /lga/v1/overview answers 200', (string) $res->get_status() );

        // One tick, after the quiet window the activation starts (Automation\LoadGuard), as run.php does.
        if ( class_exists( \LinkGrowth\Autopilot\Automation\LoadGuard::class ) ) {
            update_option( 'lga_running_version', [ 'version' => LGA_VERSION, 'since' => time() - HOUR_IN_SECONDS, 'from' => '' ], true );
            lga()->get( \LinkGrowth\Autopilot\Automation\LoadGuard::class )->forget();
        }
        $before = (string) get_option( 'lga_last_tick', '' );
        $tick   = lga()->get( \LinkGrowth\Autopilot\Automation\Pipeline::class )->tick();
        CM::check( is_array( $tick ) && 'paused' !== ( $tick['skipped_reason'] ?? '' ), 'one tick ran', substr( (string) wp_json_encode( $tick ), 0, 300 ) );
        $after = (string) get_option( 'lga_last_tick', '' );
        CM::check( '' !== $after && $after !== $before, 'the tick recorded its time (lga_last_tick)', $before . ' → ' . $after );
        $errors = lga()->get( \LinkGrowth\Autopilot\Repositories\Logs::class )->count( [ 'level' => 'error' ] );
        CM::check( 0 === $errors, 'no error-level log entry', (string) $errors );
        $status = lga()->get( \LinkGrowth\Autopilot\Automation\Scheduler::class )->status();
        CM::check( 'none' !== $status['backend'], 'the scheduler reports a backend (' . $status['backend'] . ')' );

        // A visitor's page view, served by this PHP on the compat-min port.
        $log  = sys_get_temp_dir() . '/lga-compat-min-server.log';
        $proc = proc_open( [ PHP_BINARY, '-S', 'localhost:' . LGA_CM_PORT, '-t', rtrim( ABSPATH, '/' ) ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', $log, 'a' ], 2 => [ 'file', $log, 'a' ] ], $pipes );
        try {
            $up = false;
            for ( $i = 0; $i < 50 && ! $up; $i++ ) {
                // An HTTP probe (a WP_Error while the server starts) logs no PHP warning, unlike a raw socket.
                $up = ! is_wp_error( wp_remote_head( 'http://localhost:' . LGA_CM_PORT . '/license.txt', [ 'timeout' => 1 ] ) );
                if ( ! $up ) {
                    usleep( 100000 );
                }
            }
            CM::check( $up, 'the site answers on port ' . LGA_CM_PORT );
            $page = wp_remote_get( 'http://localhost:' . LGA_CM_PORT . '/', [ 'timeout' => 20 ] );
            $code = (int) wp_remote_retrieve_response_code( $page );
            $body = (string) wp_remote_retrieve_body( $page );
            CM::check( 200 === $code && str_contains( $body, '</html>' ), 'the home page renders for a visitor (200)', is_wp_error( $page ) ? $page->get_error_message() : (string) $code );
            CM::check( ! preg_match( '/(Fatal error|Warning|Deprecated|Notice):/', $body ), 'the home page shows no PHP error' );

            // The owner opens the plugin's dashboard page (admin menu, SPA root, script module on WordPress 6.5).
            $expires = time() + HOUR_IN_SECONDS;
            $cookies = [ AUTH_COOKIE => wp_generate_auth_cookie( 1, $expires, 'auth' ), LOGGED_IN_COOKIE => wp_generate_auth_cookie( 1, $expires, 'logged_in' ) ];
            $url     = 'http://localhost:' . LGA_CM_PORT . '/wp-admin/admin.php?page=' . \LinkGrowth\Autopilot\Admin\Menu::SLUG;
            // The first admin page after the activation is the plugin's one-time redirect to its own page.
            $first = wp_remote_get( $url, [ 'timeout' => 30, 'redirection' => 0, 'cookies' => $cookies ] );
            $code  = (int) wp_remote_retrieve_response_code( $first );
            $to    = (string) wp_remote_retrieve_header( $first, 'location' );
            CM::check( 302 === $code && str_contains( $to, 'page=' . \LinkGrowth\Autopilot\Admin\Menu::SLUG ), 'the first admin page after the activation redirects to the plugin once', is_wp_error( $first ) ? $first->get_error_message() : $code . ' ' . $to );
            $admin   = wp_remote_get( $url, [ 'timeout' => 30, 'redirection' => 0, 'cookies' => $cookies ] );
            $code    = (int) wp_remote_retrieve_response_code( $admin );
            $body    = (string) wp_remote_retrieve_body( $admin );
            CM::check( 200 === $code && str_contains( $body, '<div id="lga-app" data-page="overview">' ) && str_contains( $body, 'id="lga-config"' ), 'the dashboard page renders for the owner (200, SPA root and config)', is_wp_error( $admin ) ? $admin->get_error_message() : $code . ' ' . (string) wp_remote_retrieve_header( $admin, 'location' ) );
            $tag = preg_match( '#<script\b[^>]*linkgrowth-autopilot/assets/js/app\.js[^>]*>#', $body, $m ) ? $m[0] : '';
            CM::check( str_contains( $tag, 'type="module"' ), 'the app script is loaded as a module (WordPress 6.5 prints no script module in wp-admin: a classic tag of type module)', '' !== $tag ? $tag : 'no app.js tag' );
            CM::check( ! preg_match( '/(Fatal error|Warning|Deprecated|Notice):/', $body ), 'the dashboard page shows no PHP error' );
        } finally {
            if ( is_resource( $proc ) ) {
                proc_terminate( $proc );
                proc_close( $proc );
            }
        }
        CM::check( [] === $GLOBALS['cmRefused'], 'no outbound request (the site stays offline)', (string) wp_json_encode( $GLOBALS['cmRefused'] ) );
    } elseif ( 'deactivate' === $phase ) {
        CM::check( is_plugin_active( LGA_CM_PLUGIN ), 'plugin active before the deactivation' );
        deactivate_plugins( LGA_CM_PLUGIN );
        CM::check( ! is_plugin_active( LGA_CM_PLUGIN ), 'plugin deactivated' );
        $s = lga_cm_schedule();
        CM::check( ! $s['cron'] && 0 === $s['as'], 'the tick schedule is gone (no WP-Cron event, no pending Action Scheduler action)', (string) wp_json_encode( $s ) );
        CM::check( lga_cm_tables() === \LinkGrowth\Autopilot\Database\Schema::names(), 'the tables are kept (data is kept)' );
        $site = (array) ( get_option( 'lga_settings' )['site'] ?? [] );
        CM::check( 'Northwind Supply Co' === ( $site['brand_name'] ?? null ), 'the settings are kept', (string) wp_json_encode( $site['brand_name'] ?? null ) );
        CM::check( [] === $GLOBALS['cmRefused'], 'the deactivation made no outbound request', (string) wp_json_encode( $GLOBALS['cmRefused'] ) );
    } else {
        CM::check( false, 'known phase', $phase );
    }
} catch ( \Throwable $e ) {
    CM::check( false, 'phase ' . $phase . ' threw ' . get_class( $e ), $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
}

printf( "PHASE %s: passed %d failed %d\n", $phase, CM::$pass, CM::$fail );
exit( CM::$fail > 0 ? 1 : 0 );
