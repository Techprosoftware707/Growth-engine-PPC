<?php
/**
 * Wiring snapshot on the LGA test site (WP2 part 0): what WordPress itself holds of the plugin, compared with
 * tests/fixtures/golden/wiring-wordpress.json. The parts that need no WordPress are tests/Unit/Wiring/WiringSnapshotTest.php.
 *
 * Each request type is its own PHP process that loads WordPress the way that request would, then reports as JSON:
 *   frontend  a visitor's page view (the `lga_maintenance_request` filter says no, as on a web server): the hooks the
 *             plugin added, the plugin files PHP loaded and the plugin's database queries (1.2.4: none);
 *   admin     a wp-admin request (WP_ADMIN): the hooks;
 *   rest      a REST request to the plugin's own route (rest_route /lga/v1/settings, `rest_api_init` fired through
 *             rest_get_server()): the hooks and every lga/v1 route of the REST server (method, path, handler,
 *             permission callback, arguments);
 *   cron      a WP-Cron request (DOING_CRON): the hooks, the recurring WP-Cron events and Action Scheduler actions of
 *             the plugin's hooks with their intervals, the tick's cron schedule, the plugin's tables as the database
 *             describes them, and the plugin's roles and capabilities.
 * A hook entry is [priority, callback, accepted args], the callback written as tests/Support/Wiring.php does (no
 * namespace; "lazy:" for a service resolved when the hook fires). Only callbacks defined in the plugin's own files
 * count (not the test files). One-off cron events and Action Scheduler actions (kicks) are left out: they come and go.
 *
 * Each request process limits the plugin to the 13 WP2 modules (tests/Support/Wp2Modules.php, through the
 * `lga_modules` filter added before WordPress loads), so a later package's line in Core\Modules::LIST does not change
 * the hooks, the files a page view loads, the routes or the tables listed (DESIGN.md §13.1); a change to a WP2 module
 * still does. The cron and roles sections read what the activations (with every module) left in the database.
 *
 * run.php runs this as the step after the setup steps (a freshly activated and configured site), so the state the
 * schedules are read in is the same on every run: LGA_IT_ONLY=wiring php tests/integration/run.php runs just that.
 * LGA_GOLDEN_UPDATE=1 rewrites the fixture instead of comparing (then review the diff).
 */

declare(strict_types=1);

const LGA_IT_WIRING_MARK     = 'LGA-WIRING-JSON ';
const LGA_IT_WIRING_FIXTURE  = __DIR__ . '/../fixtures/golden/wiring-wordpress.json';
const LGA_IT_WIRING_CONTEXTS = [ 'frontend', 'admin', 'rest', 'cron' ];

// ------------------------------------------------------------------ child: one request type
$lgaWiringContext = (string) getenv( 'LGA_WIRING_CONTEXT' );
if ( '' !== $lgaWiringContext && realpath( (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) === realpath( __FILE__ ) ) {
    $wpPath = rtrim( (string) ( getenv( 'LGA_WP_PATH' ) ?: '' ), '/' );
    if ( '' === $wpPath || ! is_file( $wpPath . '/wp-load.php' ) || ! in_array( $lgaWiringContext, LGA_IT_WIRING_CONTEXTS, true ) ) {
        fwrite( STDERR, "Set LGA_WP_PATH and LGA_WIRING_CONTEXT (frontend, admin, rest or cron)\n" );
        exit( 2 );
    }
    $_SERVER['HTTP_HOST']      = 'localhost:8793';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI']    = match ( $lgaWiringContext ) {
        'admin'  => '/wp-admin/admin.php?page=lga',
        'rest'   => '/wp-json/lga/v1/settings',
        'cron'   => '/wp-cron.php',
        default  => '/',
    };
    $web = static fn(): bool => false;
    if ( in_array( $lgaWiringContext, [ 'frontend', 'rest' ], true ) ) {
        // The suite runs on the CLI, which the plugin treats as maintenance; a web server's page view or REST call is not.
        $GLOBALS['wp_filter']['lga_maintenance_request'][10][] = [ 'function' => $web, 'accepted_args' => 1 ];
    }
    // The WP2 modules only (tests/Support/Wp2Modules.php): the filter is in place before the plugin boots its modules.
    require_once dirname( __DIR__ ) . '/Support/Wp2Modules.php';
    $GLOBALS['wp_filter'][ \LinkGrowth\Autopilot\Tests\Support\Wp2Modules::FILTER ][10][] = [ 'function' => [ \LinkGrowth\Autopilot\Tests\Support\Wp2Modules::class, 'filter' ], 'accepted_args' => 1 ];
    if ( 'admin' === $lgaWiringContext ) {
        define( 'WP_ADMIN', true );
    }
    if ( 'cron' === $lgaWiringContext ) {
        define( 'DOING_CRON', true );
    }
    require $wpPath . '/wp-load.php';
    require_once dirname( __DIR__ ) . '/Support/Wiring.php';

    $root = (string) realpath( WP_PLUGIN_DIR . '/linkgrowth-autopilot' );
    $out  = [];
    // First, before anything below loads classes to describe them.
    if ( 'frontend' === $lgaWiringContext ) {
        $files = [];
        foreach ( get_included_files() as $file ) {
            $real = (string) realpath( $file );
            if ( str_starts_with( $real, $root . '/' ) && ! str_starts_with( $real, $root . '/tests/' ) ) {
                $files[] = substr( $real, strlen( $root ) + 1 );
            }
        }
        sort( $files );
        $out['plugin_files'] = $files;
        $queries             = 0;
        foreach ( (array) ( $GLOBALS['wpdb']->queries ?? [] ) as $q ) {
            $sql = is_array( $q ) ? (string) ( $q[0] ?? '' ) : (string) $q;
            if ( false !== stripos( $sql, 'lga_' ) ) {
                $queries++;
            }
        }
        $out['plugin_queries'] = defined( 'SAVEQUERIES' ) && SAVEQUERIES ? $queries : 'not measured (SAVEQUERIES off)';
    }
    if ( 'rest' === $lgaWiringContext ) {
        if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) ) {
            $GLOBALS['wp']->query_vars['rest_route'] = '/lga/v1/settings';
        }
        if ( ! defined( 'REST_REQUEST' ) ) {
            define( 'REST_REQUEST', true );
        }
        $server              = rest_get_server(); // fires rest_api_init
        $out['rest_routes'] = \LinkGrowth\Autopilot\Tests\Support\Wiring::restRoutes( $server->get_routes( 'lga/v1' ), 'lga/v1' );
    }
    $out['hooks'] = lga_it_wiring_hooks( $root );
    if ( 'cron' === $lgaWiringContext ) {
        $out['cron']   = lga_it_wiring_cron();
        $out['tables'] = lga_it_wiring_tables();
        $out['roles']  = lga_it_wiring_roles();
    }
    echo LGA_IT_WIRING_MARK . wp_json_encode( $out ) . "\n";
    exit( 0 );
}

/**
 * The plugin's callbacks on every hook: hook => [[priority, callback, accepted args], …], hooks sorted by name,
 * callbacks in the order WordPress runs them.
 *
 * @return array<string,array<int,array{0:int,1:string,2:int}>>
 */
function lga_it_wiring_hooks( string $root ): array {
    $out = [];
    foreach ( (array) ( $GLOBALS['wp_filter'] ?? [] ) as $hook => $wpHook ) {
        $callbacks = $wpHook instanceof WP_Hook ? $wpHook->callbacks : ( is_array( $wpHook ) ? $wpHook : [] );
        ksort( $callbacks );
        foreach ( $callbacks as $priority => $list ) {
            foreach ( (array) $list as $entry ) {
                $cb   = $entry['function'] ?? null;
                $file = (string) realpath( \LinkGrowth\Autopilot\Tests\Support\Wiring::file( $cb ) );
                if ( '' === $file || ! str_starts_with( $file, $root . '/' ) || str_starts_with( $file, $root . '/tests/' ) ) {
                    continue;
                }
                $out[ (string) $hook ][] = [ (int) $priority, \LinkGrowth\Autopilot\Tests\Support\Wiring::callback( $cb ), (int) ( $entry['accepted_args'] ?? 1 ) ];
            }
        }
    }
    ksort( $out );
    return $out;
}

/** @return array<string,mixed> recurring WP-Cron events and Action Scheduler actions of the plugin's hooks, and the tick schedule */
function lga_it_wiring_cron(): array {
    $wpCron = [];
    foreach ( (array) _get_cron_array() as $events ) {
        foreach ( (array) $events as $hook => $byKey ) {
            if ( ! str_starts_with( (string) $hook, 'lga' ) ) {
                continue;
            }
            foreach ( (array) $byKey as $event ) {
                if ( empty( $event['schedule'] ) ) {
                    continue; // a one-off event (a kick)
                }
                $wpCron[] = [ 'hook' => (string) $hook, 'schedule' => (string) $event['schedule'], 'interval' => (int) ( $event['interval'] ?? 0 ), 'args' => array_values( (array) ( $event['args'] ?? [] ) ) ];
            }
        }
    }
    $actions = [];
    if ( function_exists( 'as_get_scheduled_actions' ) && class_exists( 'ActionScheduler' ) ) {
        $ids = array_unique(
            array_merge(
                (array) as_get_scheduled_actions( [ 'group' => 'lga', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 200 ], 'ids' ),
                (array) as_get_scheduled_actions( [ 'hook' => 'lga_tick', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 200 ], 'ids' )
            )
        );
        sort( $ids );
        foreach ( $ids as $id ) {
            $action   = ActionScheduler::store()->fetch_action( (string) $id );
            $schedule = $action->get_schedule();
            if ( ! $schedule->is_recurring() ) {
                continue; // a one-off action (a kick)
            }
            $interval  = method_exists( $schedule, 'get_recurrence' ) ? $schedule->get_recurrence() : null;
            $actions[] = [ 'hook' => $action->get_hook(), 'group' => $action->get_group(), 'interval' => is_numeric( $interval ) ? (int) $interval : (string) $interval, 'args' => array_values( (array) $action->get_args() ) ];
        }
    }
    $schedules = [];
    foreach ( wp_get_schedules() as $name => $schedule ) {
        if ( str_starts_with( (string) $name, 'lga' ) ) {
            $schedules[ (string) $name ] = [ 'interval' => (int) $schedule['interval'], 'display' => (string) $schedule['display'] ];
        }
    }
    return [ 'wp_cron' => $wpCron, 'action_scheduler' => $actions, 'schedules' => $schedules ];
}

/** @return array<string,array<int,string>> the plugin's tables as the database lists their columns ({prefix}lga_x => ["name type null default extra", …]) */
function lga_it_wiring_tables(): array {
    global $wpdb;
    $out = [];
    foreach ( \LinkGrowth\Autopilot\Database\Schema::names() as $name ) {
        $table = $wpdb->prefix . 'lga_' . $name;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = (array) $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
        $cols = [];
        foreach ( $rows as $row ) {
            $cols[] = implode( ' ', array_filter( [ (string) $row['Field'], strtolower( (string) $row['Type'] ), 'YES' === ( $row['Null'] ?? '' ) ? 'NULL' : 'NOT NULL', null === ( $row['Default'] ?? null ) ? '' : 'DEFAULT ' . var_export( (string) $row['Default'], true ), (string) ( $row['Key'] ?? '' ), (string) ( $row['Extra'] ?? '' ) ], static fn( string $part ): bool => '' !== $part ) );
        }
        $out[ '{prefix}lga_' . $name ] = $cols;
    }
    ksort( $out );
    return $out;
}

/** @return array<string,array{name:string,capabilities:string[]}> roles holding a plugin capability, and the plugin's own roles */
function lga_it_wiring_roles(): array {
    $out = [];
    foreach ( wp_roles()->roles as $slug => $role ) {
        $caps = array_keys( array_filter( (array) ( $role['capabilities'] ?? [] ) ) );
        $own  = str_starts_with( (string) $slug, 'lga' );
        $mine = array_values( array_filter( $caps, static fn( $c ): bool => str_starts_with( (string) $c, 'lga' ) ) );
        if ( ! $own && [] === $mine ) {
            continue;
        }
        $list = $own ? array_map( 'strval', $caps ) : $mine;
        sort( $list );
        $out[ (string) $slug ] = [ 'name' => (string) ( $role['name'] ?? '' ), 'capabilities' => $list ];
    }
    ksort( $out );
    return $out;
}

// ------------------------------------------------------------------ the step (run.php)

/**
 * Runs the four request types and returns the snapshot, or the errors of the processes that did not report.
 *
 * @return array{snapshot:array<string,mixed>,errors:string[]}
 */
function lga_it_wiring_collect(): array {
    $snapshot = [ 'hooks' => [] ];
    $errors   = [];
    foreach ( LGA_IT_WIRING_CONTEXTS as $context ) {
        $lines = [];
        $code  = 0;
        exec( 'LGA_WIRING_CONTEXT=' . escapeshellarg( $context ) . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' 2>&1', $lines, $code );
        $data = null;
        foreach ( $lines as $line ) {
            if ( str_starts_with( $line, LGA_IT_WIRING_MARK ) ) {
                $data = json_decode( substr( $line, strlen( LGA_IT_WIRING_MARK ) ), true );
            }
        }
        if ( ! is_array( $data ) ) {
            $errors[] = $context . ': no report (exit ' . $code . '): ' . substr( implode( ' | ', $lines ), 0, 600 );
            continue;
        }
        $snapshot['hooks'][ $context ] = $data['hooks'] ?? [];
        foreach ( $data as $key => $value ) {
            if ( 'hooks' !== $key ) {
                $snapshot[ 'frontend' === $context && in_array( $key, [ 'plugin_files', 'plugin_queries' ], true ) ? 'frontend_' . $key : $key ] = $value;
            }
        }
    }
    return [ 'snapshot' => $snapshot, 'errors' => $errors ];
}

/** The step body: compares each section with the fixture through $check( bool, label, detail ). */
function lga_it_wiring_step( callable $check ): void {
    require_once dirname( __DIR__ ) . '/Support/GoldenJson.php';
    $result = lga_it_wiring_collect();
    foreach ( $result['errors'] as $error ) {
        $check( false, 'wiring snapshot: a request type reported', $error );
    }
    if ( [] !== $result['errors'] ) {
        return;
    }
    $actual = [ '_about' => 'Wiring snapshot on the LGA test site (tests/integration/wiring-snapshot.php), recorded on the WP1 fork state (e160656) right after the suite\'s setup steps. WP2 keeps it identical apart from documented, deliberate differences; rewrite only on purpose with LGA_GOLDEN_UPDATE=1.' ] + $result['snapshot'];
    if ( \LinkGrowth\Autopilot\Tests\Support\GoldenJson::updating() ) {
        file_put_contents( LGA_IT_WIRING_FIXTURE, \LinkGrowth\Autopilot\Tests\Support\GoldenJson::encode( $actual ) . "\n" );
        $check( false, 'wiring snapshot rewritten (LGA_GOLDEN_UPDATE=1): review the diff of tests/fixtures/golden/wiring-wordpress.json; an update run never counts as green' );
        return;
    }
    $expected = is_file( LGA_IT_WIRING_FIXTURE ) ? json_decode( (string) file_get_contents( LGA_IT_WIRING_FIXTURE ), true ) : null;
    $check( is_array( $expected ), 'the fixture tests/fixtures/golden/wiring-wordpress.json exists' );
    if ( ! is_array( $expected ) ) {
        return;
    }
    $sections = [];
    foreach ( LGA_IT_WIRING_CONTEXTS as $context ) {
        $sections[ 'hooks.' . $context ] = [ $expected['hooks'][ $context ] ?? null, $actual['hooks'][ $context ] ?? null, 'the hooks of the ' . $context . ' request' ];
    }
    $sections['frontend_plugin_files']   = [ $expected['frontend_plugin_files'] ?? null, $actual['frontend_plugin_files'] ?? null, 'the plugin files a page view loads' ];
    $sections['frontend_plugin_queries'] = [ $expected['frontend_plugin_queries'] ?? null, $actual['frontend_plugin_queries'] ?? null, 'the plugin\'s database queries on a page view' ];
    $sections['rest_routes']             = [ $expected['rest_routes'] ?? null, $actual['rest_routes'] ?? null, 'the lga/v1 routes of the REST server' ];
    $sections['cron']                    = [ $expected['cron'] ?? null, $actual['cron'] ?? null, 'the recurring WP-Cron events, Action Scheduler actions and the tick schedule' ];
    $sections['tables']                  = [ $expected['tables'] ?? null, $actual['tables'] ?? null, 'the plugin tables as the database describes them' ];
    $sections['roles']                   = [ $expected['roles'] ?? null, $actual['roles'] ?? null, 'the plugin roles and capabilities' ];
    foreach ( $sections as $name => [ $want, $got, $label ] ) {
        $check( null !== $want && $want === $got, 'wiring snapshot: ' . $label . ' (' . $name . ') match the fixture', $want === $got ? '' : \LinkGrowth\Autopilot\Tests\Support\GoldenJson::firstDifference( $want, $got, $name ) );
    }
}
