<?php
/**
 * Coexistence section (DESIGN.md §1.4): LinkGrowth Autopilot and the PPC Link Growth Engine it was forked from,
 * both active on the LGA test site.
 *
 * This is the only fork file (besides docs/DESIGN.md) that names the engine: it must activate it to prove the two
 * do not interfere. It is left out of the source ZIP (§1.3 step 7). The engine's files are used read-only: its folder
 * is symlinked into the test site's wp-content/plugins/ for the duration of the section and the link is removed
 * afterwards, together with everything the engine's activation wrote to the test site's database.
 *
 * Usage (tests/integration/run.php runs it as its last section when this file exists; LGA_COEXISTENCE=0 skips it):
 *   LGA_WP_PATH=/path/to/wordpress [LGA_ENGINE_PATH=/path/to/ppc-link-growth-engine] php tests/integration/coexistence.php
 * LGA_ENGINE_PATH defaults to the engine folder beside this plugin's folder in the repository.
 *
 * Phases, each a fresh PHP process so that each plugin loads with WordPress as on a real site:
 *   activate  link the engine folder, activate both plugins (each one's activation runs);
 *   check     both boot; an LGA tick reads no ppclg_* option (counted with the `pre_option` filter); deactivating
 *             one plugin leaves the other's WP-Cron event and Action Scheduler action in place; the owner-listed
 *             conflict (lga_known_conflicts, through the test mu-plugin's LGA_TEST_KNOWN_CONFLICTS) gives the warning
 *             on the dashboard and in Site Health, and the generic detectors find nothing else;
 *   cleanup   deactivate the engine, remove its tables, options, transients, schedules, role and capabilities,
 *             and the symlink.
 * Output ends with "Passed: N  Failed: M" (or "Not run: <reason>"), like run.php.
 */

declare(strict_types=1);

const LGA_IT_ENGINE_SLUG     = 'ppc-link-growth-engine';
const LGA_IT_ENGINE_BASENAME = 'ppc-link-growth-engine/ppc-link-growth-engine.php';
const LGA_IT_ENGINE_LABEL    = 'PPC Link Growth Engine';
const LGA_IT_PLUGIN_BASENAME = 'linkgrowth-autopilot/linkgrowth-autopilot.php';

$wpPath = rtrim( (string) ( getenv( 'LGA_WP_PATH' ) ?: '' ), '/' );
if ( '' === $wpPath || ! is_file( $wpPath . '/wp-load.php' ) ) {
    fwrite( STDERR, "Set LGA_WP_PATH to a WordPress root containing wp-load.php\n" );
    exit( 2 );
}
$enginePath = rtrim( (string) ( getenv( 'LGA_ENGINE_PATH' ) ?: dirname( __DIR__, 3 ) . '/' . LGA_IT_ENGINE_SLUG ), '/' );
$phase      = (string) getenv( 'LGA_COEXIST_PHASE' );
$link       = $wpPath . '/wp-content/plugins/' . LGA_IT_ENGINE_SLUG;

// ------------------------------------------------------------------ driver (no WordPress loaded)
if ( '' === $phase ) {
    if ( ! is_file( $enginePath . '/' . LGA_IT_ENGINE_SLUG . '.php' ) ) {
        echo "\n== coexistence\nNot run: the engine plugin was not found at {$enginePath} (set LGA_ENGINE_PATH)\n";
        exit( 3 );
    }
    echo "\n== coexistence: LinkGrowth Autopilot beside " . LGA_IT_ENGINE_LABEL . " (DESIGN.md §1.4)\n";
    $created = false;
    if ( is_link( $link ) ) {
        $created = realpath( $link ) === realpath( $enginePath );
        if ( ! $created ) {
            echo "Not run: {$link} links somewhere else; leaving it alone\n";
            exit( 3 );
        }
    } elseif ( file_exists( $link ) ) {
        echo "Not run: {$link} exists and is not a symlink; leaving it alone\n";
        exit( 3 );
    } else {
        $created = symlink( $enginePath, $link );
    }
    $pass = 0;
    $fail = 0;
    $run  = static function ( string $name ) use ( &$pass, &$fail ): void {
        $out  = [];
        $code = 0;
        $env  = 'LGA_COEXIST_PHASE=' . escapeshellarg( $name );
        exec( $env . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1', $out, $code );
        $text = implode( "\n", $out );
        echo $text . "\n";
        if ( preg_match( '/^PHASE ' . preg_quote( $name, '/' ) . ': passed (\d+) failed (\d+)$/m', $text, $m ) ) {
            $pass += (int) $m[1];
            $fail += (int) $m[2];
        } else {
            $fail++;
            echo "  ✘ phase {$name} did not finish (exit {$code})\n";
        }
    };
    try {
        $run( 'activate' );
        $run( 'check' );
    } finally {
        $run( 'cleanup' );
        if ( $created && is_link( $link ) ) {
            unlink( $link );
        }
    }
    $linkGone = ! file_exists( $link ) && ! is_link( $link );
    echo ( $linkGone ? '  ✔' : '  ✘' ) . " the engine symlink was removed from the test site\n";
    $linkGone ? $pass++ : $fail++;
    echo "\n==============================\n";
    printf( "Passed: %d  Failed: %d\n", $pass, $fail );
    exit( $fail > 0 ? 1 : 0 );
}

// ------------------------------------------------------------------ phases (WordPress loaded)
$_SERVER['HTTP_HOST']      = 'localhost:8793';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
require $wpPath . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

final class CO {
    public static int $pass = 0;
    public static int $fail = 0;
    public static function check( bool $ok, string $label, string $detail = '' ): void {
        $ok ? self::$pass++ : self::$fail++;
        echo ( $ok ? '  ✔ ' : '  ✘ ' ) . $label . ( ! $ok && '' !== $detail ? ' — ' . $detail : '' ) . "\n";
    }
    public static function done( string $phase ): void {
        echo 'PHASE ' . $phase . ': passed ' . self::$pass . ' failed ' . self::$fail . "\n";
    }
}

// Offline: only *.test (fixture server, through the test mu-plugin) and this machine may be reached.
$GLOBALS['coBlocked'] = [];
add_filter(
    'pre_http_request',
    static function ( $pre, $args, $url ) {
        if ( false !== $pre ) {
            return $pre;
        }
        $host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
        if ( str_ends_with( $host, '.test' ) || in_array( $host, [ '127.0.0.1', 'localhost' ], true ) ) {
            return $pre;
        }
        $GLOBALS['coBlocked'][ $host ] = ( $GLOBALS['coBlocked'][ $host ] ?? 0 ) + 1;
        return new WP_Error( 'lga_it_offline', 'Outbound request refused by the coexistence section (offline): ' . $host );
    },
    1,
    3
);
wp_set_current_user( 1 );

/** WP-Cron event and Action Scheduler action of one plugin's tick. @return array{cron:bool,as:int} */
function lga_co_schedule( string $hook, string $group ): array {
    $as = 0;
    if ( function_exists( 'as_get_scheduled_actions' ) ) {
        $as = count( (array) as_get_scheduled_actions( [ 'hook' => $hook, 'group' => $group, 'status' => 'pending', 'per_page' => 10 ], 'ids' ) );
    }
    return [ 'cron' => false !== wp_next_scheduled( $hook ), 'as' => $as ];
}

/** True when one of the two schedules exists (each plugin uses Action Scheduler when it is there, else WP-Cron). */
function lga_co_scheduled( array $s ): bool {
    return $s['cron'] || $s['as'] > 0;
}

if ( 'activate' === $phase ) {
    if ( ! is_plugin_active( LGA_IT_PLUGIN_BASENAME ) ) {
        $r = activate_plugin( LGA_IT_PLUGIN_BASENAME );
        CO::check( null === $r, 'LinkGrowth Autopilot activated', is_wp_error( $r ) ? $r->get_error_message() : '' );
    }
    $r = activate_plugin( LGA_IT_ENGINE_BASENAME );
    CO::check( null === $r, 'the engine activated beside it', is_wp_error( $r ) ? $r->get_error_message() : '' );
    CO::check( is_plugin_active( LGA_IT_ENGINE_BASENAME ) && is_plugin_active( LGA_IT_PLUGIN_BASENAME ), 'both plugins are active' );
    CO::done( 'activate' );
    exit( 0 );
}

if ( 'check' === $phase ) {
    global $wpdb;
    // 1. Both boot.
    CO::check( function_exists( 'lga' ) && function_exists( 'ppclg' ), 'both plugins loaded with WordPress (lga() and ppclg() exist)' );
    CO::check( defined( 'LGA_VERSION' ) && defined( 'PPCLG_VERSION' ) && LGA_VERSION !== '' && PPCLG_VERSION !== '', 'both version constants are defined', ( defined( 'LGA_VERSION' ) ? LGA_VERSION : '-' ) . ' / ' . ( defined( 'PPCLG_VERSION' ) ? PPCLG_VERSION : '-' ) );
    CO::check( false !== has_action( 'lga_tick' ) && false !== has_action( 'ppclg_tick' ), 'each plugin hooked its own tick (lga_tick, ppclg_tick)' );
    $namespaces = rest_get_server()->get_namespaces();
    CO::check( in_array( 'lga/v1', $namespaces, true ) && in_array( 'ppclg/v1', $namespaces, true ), 'both REST namespaces are registered (lga/v1, ppclg/v1)', implode( ',', $namespaces ) );
    $overview = rest_do_request( new WP_REST_Request( 'GET', '/lga/v1/overview' ) );
    CO::check( 200 === $overview->get_status(), 'GET /lga/v1/overview answers 200 with the engine active', (string) $overview->get_status() );
    $lgaTables = lga()->get( \LinkGrowth\Autopilot\Database\Schema::class )->existingTables();
    CO::check( count( $lgaTables ) === count( \LinkGrowth\Autopilot\Database\Schema::TABLES ), 'all lga_ tables exist', (string) count( $lgaTables ) );
    $engineTables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'ppclg_' ) . '%' ) );
    CO::check( count( $engineTables ) > 0 && [] === array_intersect( $engineTables, $lgaTables ), 'the engine has its own ppclg_ tables, none shared', count( $engineTables ) . ' engine tables' );
    $admin = get_role( 'administrator' );
    CO::check( null !== $admin && $admin->has_cap( 'lga_manage' ) && $admin->has_cap( 'ppclg_manage' ), 'each plugin granted its own capability (lga_manage, ppclg_manage)' );

    // 2. An LGA tick reads no ppclg_* option (and writes none).
    update_option( 'lga_running_version', [ 'version' => LGA_VERSION, 'since' => time() - HOUR_IN_SECONDS, 'from' => '' ], true );
    if ( class_exists( \LinkGrowth\Autopilot\Automation\LoadGuard::class ) ) {
        lga()->get( \LinkGrowth\Autopilot\Automation\LoadGuard::class )->forget();
    }
    delete_option( 'lga_last_tick' );
    $reads   = [];
    $byLga   = [];
    $writes  = [];
    $lgaDir  = (string) realpath( WP_PLUGIN_DIR . '/linkgrowth-autopilot' );
    $counter = static function ( $pre, $option ) use ( &$reads, &$byLga, $lgaDir ) {
        if ( is_string( $option ) && str_starts_with( $option, 'ppclg_' ) ) {
            $reads[ $option ] = ( $reads[ $option ] ?? 0 ) + 1;
            foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ) as $frame ) {
                if ( 'get_option' === ( $frame['function'] ?? '' ) ) {
                    $file = (string) realpath( (string) ( $frame['file'] ?? '' ) );
                    if ( '' !== $lgaDir && str_starts_with( $file, $lgaDir . '/' ) ) {
                        $byLga[ $option ] = ( $byLga[ $option ] ?? 0 ) + 1;
                    }
                    break;
                }
            }
        }
        return $pre;
    };
    $writer  = static function ( $option ) use ( &$writes ) {
        if ( is_string( $option ) && str_starts_with( $option, 'ppclg_' ) ) {
            $writes[ $option ] = ( $writes[ $option ] ?? 0 ) + 1;
        }
    };
    add_filter( 'pre_option', $counter, PHP_INT_MIN, 2 );
    add_action( 'updated_option', $writer, 10, 1 );
    add_action( 'added_option', $writer, 10, 1 );
    $tick = lga()->get( \LinkGrowth\Autopilot\Automation\Pipeline::class )->tick();
    remove_filter( 'pre_option', $counter, PHP_INT_MIN );
    remove_action( 'updated_option', $writer, 10 );
    remove_action( 'added_option', $writer, 10 );
    CO::check( is_array( $tick ) && '' !== (string) get_option( 'lga_last_tick', '' ), 'an LGA tick ran with the engine active', (string) wp_json_encode( $tick ) );
    CO::check( [] === $reads, 'the LGA tick read no ppclg_* option (pre_option count 0)', (string) wp_json_encode( $reads ) );
    CO::check( [] === $byLga, 'no LGA code called get_option() for a ppclg_* option', (string) wp_json_encode( $byLga ) );
    CO::check( [] === $writes, 'the LGA tick wrote no ppclg_* option', (string) wp_json_encode( $writes ) );

    // 3. Deactivating one leaves the other's cron and Action Scheduler actions in place.
    lga()->get( \LinkGrowth\Autopilot\Automation\Scheduler::class )->schedule();
    if ( function_exists( 'ppclg' ) ) {
        ppclg()->get( \PPC\LinkGrowth\Automation\Scheduler::class )->schedule();
    }
    $lgaBefore    = lga_co_schedule( 'lga_tick', 'lga' );
    $engineBefore = lga_co_schedule( 'ppclg_tick', 'ppclg' );
    CO::check( lga_co_scheduled( $lgaBefore ) && lga_co_scheduled( $engineBefore ), 'both ticks are scheduled', (string) wp_json_encode( [ 'lga' => $lgaBefore, 'engine' => $engineBefore ] ) );

    deactivate_plugins( LGA_IT_ENGINE_BASENAME );
    CO::check( ! is_plugin_active( LGA_IT_ENGINE_BASENAME ), 'engine deactivated' );
    CO::check( lga_co_schedule( 'lga_tick', 'lga' ) === $lgaBefore, "deactivating the engine leaves LinkGrowth Autopilot's cron event and Action Scheduler action", (string) wp_json_encode( lga_co_schedule( 'lga_tick', 'lga' ) ) );
    CO::check( ! lga_co_scheduled( lga_co_schedule( 'ppclg_tick', 'ppclg' ) ), "the engine's own schedule is gone (its deactivation ran)", (string) wp_json_encode( lga_co_schedule( 'ppclg_tick', 'ppclg' ) ) );

    $r = activate_plugin( LGA_IT_ENGINE_BASENAME );
    CO::check( null === $r && lga_co_scheduled( lga_co_schedule( 'ppclg_tick', 'ppclg' ) ), 'engine re-activated and scheduled again', is_wp_error( $r ) ? $r->get_error_message() : (string) wp_json_encode( lga_co_schedule( 'ppclg_tick', 'ppclg' ) ) );
    $engineAgain = lga_co_schedule( 'ppclg_tick', 'ppclg' );

    deactivate_plugins( LGA_IT_PLUGIN_BASENAME );
    CO::check( ! is_plugin_active( LGA_IT_PLUGIN_BASENAME ), 'LinkGrowth Autopilot deactivated' );
    CO::check( lga_co_schedule( 'ppclg_tick', 'ppclg' ) === $engineAgain, "deactivating LinkGrowth Autopilot leaves the engine's cron event and Action Scheduler action", (string) wp_json_encode( lga_co_schedule( 'ppclg_tick', 'ppclg' ) ) );
    CO::check( ! lga_co_scheduled( lga_co_schedule( 'lga_tick', 'lga' ) ), "LinkGrowth Autopilot's own schedule is gone", (string) wp_json_encode( lga_co_schedule( 'lga_tick', 'lga' ) ) );
    CO::check( count( lga()->get( \LinkGrowth\Autopilot\Database\Schema::class )->existingTables() ) === count( \LinkGrowth\Autopilot\Database\Schema::TABLES ), 'deactivation kept the lga_ tables (data is kept)' );
    $r = activate_plugin( LGA_IT_PLUGIN_BASENAME );
    CO::check( null === $r && lga_co_scheduled( lga_co_schedule( 'lga_tick', 'lga' ) ), 'LinkGrowth Autopilot re-activated and scheduled again', is_wp_error( $r ) ? $r->get_error_message() : (string) wp_json_encode( lga_co_schedule( 'lga_tick', 'lga' ) ) );
    CO::check( lga_co_schedule( 'ppclg_tick', 'ppclg' ) === $engineAgain, "re-activating it left the engine's schedule as it was" );

    // 4. Conflicts: generic detectors, the owner-listed engine.
    $env = lga()->get( \LinkGrowth\Autopilot\Core\Environment::class );
    putenv( 'LGA_TEST_KNOWN_CONFLICTS' );
    CO::check( [] === $env->conflicts( true ), 'unlisted, the engine is not reported: no shared REST namespace, cron hook or table', (string) wp_json_encode( $env->conflicts( true ) ) );
    putenv( 'LGA_TEST_KNOWN_CONFLICTS=' . wp_json_encode( [ LGA_IT_ENGINE_BASENAME => LGA_IT_ENGINE_LABEL ] ) );
    $conflicts = $env->conflicts();
    $expected  = LGA_IT_ENGINE_LABEL . ' is active on this site. Running both can double outbound crawling and contact attempts to the same sites. Use one per site.';
    CO::check( 1 === count( $conflicts ) && $expected === ( $conflicts[0]['message'] ?? '' ), 'the owner-listed engine gives the known-conflicts warning', (string) wp_json_encode( $conflicts ) );
    $notices = array_values( array_filter( lga()->get( \LinkGrowth\Autopilot\Admin\Notices::class )->notices(), static fn( array $n ): bool => $expected === ( $n['message'] ?? '' ) ) );
    CO::check( 1 === count( $notices ) && 'warning' === ( $notices[0]['level'] ?? '' ), 'the dashboard notice shows the warning' );
    $tests  = apply_filters( 'site_status_tests', [ 'direct' => [], 'async' => [] ] );
    $health = isset( $tests['direct']['lga_environment']['test'] ) && is_callable( $tests['direct']['lga_environment']['test'] ) ? call_user_func( $tests['direct']['lga_environment']['test'] ) : [];
    CO::check( 'recommended' === ( $health['status'] ?? '' ) && str_contains( (string) ( $health['description'] ?? '' ), esc_html( $expected ) ), 'Site Health (lga_environment) shows the warning, as a recommendation (nothing is blocked)', (string) wp_json_encode( $health ) );
    CO::check( 200 === rest_do_request( new WP_REST_Request( 'GET', '/lga/v1/overview' ) )->get_status(), 'the plugin keeps working while the warning shows' );
    deactivate_plugins( LGA_IT_ENGINE_BASENAME );
    CO::check( [] === $env->conflicts(), 'a listed plugin that is not active is not reported' );
    putenv( 'LGA_TEST_KNOWN_CONFLICTS' );
    $probe = $wpdb->prefix . 'lga_foreign_probe';
    $wpdb->query( "CREATE TABLE {$probe} ( id INTEGER PRIMARY KEY )" ); // phpcs:ignore
    $tableConflicts = array_column( $env->conflicts( true ), 'id' );
    $wpdb->query( "DROP TABLE IF EXISTS {$probe}" ); // phpcs:ignore
    CO::check( [ 'table:' . $probe ] === $tableConflicts, 'a table with the lga_ prefix that the plugin does not own is reported (full check)', (string) wp_json_encode( $tableConflicts ) );

    CO::check( [] === $GLOBALS['coBlocked'], 'no request to an outside host while both were active', (string) wp_json_encode( $GLOBALS['coBlocked'] ) );
    CO::done( 'check' );
    exit( 0 );
}

if ( 'cleanup' === $phase ) {
    global $wpdb;
    if ( is_plugin_active( LGA_IT_ENGINE_BASENAME ) ) {
        deactivate_plugins( LGA_IT_ENGINE_BASENAME );
    }
    if ( ! is_plugin_active( LGA_IT_PLUGIN_BASENAME ) ) {
        activate_plugin( LGA_IT_PLUGIN_BASENAME );
    }
    // What the engine's activation wrote to the test site's database (its files are never touched).
    foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'ppclg_' ) . '%' ) ) as $table ) {
        $wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( (string) $table ) . '`' ); // phpcs:ignore
    }
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ppclg\\_%' OR option_name LIKE '\\_transient\\_ppclg\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ppclg\\_%' OR option_name LIKE '\\_site\\_transient\\_ppclg\\_%'" ); // phpcs:ignore
    $wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'ppclg\\_%'" ); // phpcs:ignore
    wp_clear_scheduled_hook( 'ppclg_tick' );
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( 'ppclg_tick' );
    }
    remove_role( 'ppclg_link_submitter' );
    foreach ( wp_roles()->role_objects as $role ) {
        foreach ( [ 'ppclg_manage', 'ppclg_submit_listings' ] as $cap ) {
            if ( $role->has_cap( $cap ) ) {
                $role->remove_cap( $cap );
            }
        }
    }
    wp_cache_flush();
    $left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%ppclg%'" ); // phpcs:ignore
    $tabs = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', '%' . $wpdb->esc_like( 'ppclg' ) . '%' ) );
    CO::check( ! is_plugin_active( LGA_IT_ENGINE_BASENAME ) && 0 === $left && [] === $tabs && null === get_role( 'ppclg_link_submitter' ), 'cleanup: the engine is inactive and left no table, option or role on the test site', $left . ' options, ' . count( $tabs ) . ' tables' );
    CO::check( is_plugin_active( LGA_IT_PLUGIN_BASENAME ), 'LinkGrowth Autopilot stays active' );
    CO::done( 'cleanup' );
    exit( 0 );
}

fwrite( STDERR, "Unknown LGA_COEXIST_PHASE {$phase}\n" );
exit( 2 );
