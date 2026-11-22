<?php
/**
 * 1.2.4 performance: what a visitor's request costs.
 *
 * Measured on a test site copy (php -S on one CPU, SQLite) before this change: every page view loaded 28 plugin files
 * (310 KB) and ran 3 plugin queries; REST requests loaded 45 files; once an hour a visitor's page view did 9 writes
 * (two expired timers plus an unchanged interval); after an update that raised the schema version, the first page
 * view ran the backup dump and dbDelta (110 plugin queries, 7 writes, a 735 KB backup). After it: 4 files, 0
 * queries, 0 writes on every page view and every REST request to another plugin's routes.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Automation;

use LinkGrowth\Autopilot\Automation\LoadGuard;
use LinkGrowth\Autopilot\Automation\Scheduler;
use LinkGrowth\Autopilot\Compliance\PositioningGate;
use LinkGrowth\Autopilot\Plugin;
use LinkGrowth\Autopilot\Support\Locks;
use LinkGrowth\Autopilot\Tests\Support\Container;
use LinkGrowth\Autopilot\Tests\Support\TestCase;

final class VisitorRequestCostTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wp'] );
        parent::tearDown();
    }

    /** A visitor's web request (the suite itself runs on the CLI, which counts as maintenance). */
    private static function webRequest(): void {
        add_filter( 'lga_maintenance_request', static fn() => false );
    }

    private function freshPlugin(): Plugin {
        Container::reset();
        return Plugin::instance();
    }

    /** @return array<string,object> */
    private static function instances( Plugin $plugin ): array {
        $ref  = new \ReflectionClass( $plugin->container() );
        $prop = $ref->getProperty( 'instances' );
        return (array) $prop->getValue( $plugin->container() );
    }

    private static function servicesRegistered( Plugin $plugin ): bool {
        $prop = ( new \ReflectionClass( $plugin ) )->getProperty( 'servicesRegistered' );
        return (bool) $prop->getValue( $plugin );
    }

    /** @param array<int,callable> $callbacks */
    private static function hasMethod( array $callbacks, object $object, string $method ): bool {
        foreach ( $callbacks as $cb ) {
            if ( is_array( $cb ) && $cb[0] === $object && $cb[1] === $method ) {
                return true;
            }
        }
        return false;
    }

    public function testBootOnAPageViewBuildsNoServiceAndAddsNoMaintenanceChecks(): void {
        self::webRequest();
        $plugin = $this->freshPlugin();
        $plugin->boot();
        $this->assertFalse( self::servicesRegistered( $plugin ), 'no service factory was even registered' );

        $init = $GLOBALS['lga_test_actions']['init'] ?? [];
        foreach ( [ 'maybeMigrate', 'ensureCapabilities', 'maybeSchedule' ] as $method ) {
            $this->assertFalse( self::hasMethod( $init, $plugin, $method ), $method . ' is not hooked on a visitor request' );
        }
        // The hooks that must stay are there, as cheap callbacks.
        foreach ( [ 'rest_api_init', 'lga_tick', 'parse_request', 'save_post', 'save_post_product', 'edited_product_cat', 'wp_update_nav_menu' ] as $hook ) {
            $this->assertNotEmpty( $GLOBALS['lga_test_actions'][ $hook ] ?? [], $hook );
        }
        $this->assertNotEmpty( $GLOBALS['lga_test_filters']['cron_schedules'] ?? [] );
        $this->assertNotEmpty( $GLOBALS['lga_test_filters']['site_status_tests'] ?? [] );

        // An ordinary page view's parse_request does not build IndexNow.
        $_SERVER['REQUEST_URI'] = '/shop/';
        do_action( 'parse_request', null );
        unset( $_SERVER['REQUEST_URI'] );
        $this->assertFalse( self::servicesRegistered( $plugin ) );

        // The storefront hooks build the audit when they fire, and only then.
        delete_option( PositioningGate::STALE_OPTION );
        do_action( 'edited_product_cat' );
        $this->assertNotFalse( get_option( PositioningGate::STALE_OPTION, false ), 'a category change still marks the check stale' );
        $this->assertArrayHasKey( PositioningGate::class, self::instances( $plugin ) );
    }

    public function testAPageViewReadsAndWritesNoOptionEvenRightAfterAnUpdate(): void {
        self::webRequest();
        // The stored update marker is from an older version: before 1.2.4's rule the first page view rewrote it.
        update_option( Plugin::VERSION_OPTION, [ 'version' => '0.9.9', 'since' => 1, 'from' => '' ] );
        update_option( 'lga_db_version', '0.8.0' ); // the schema is behind as well (LGA_DB_VERSION is 0.9.0)
        $before = $GLOBALS['lga_test_options'];
        $plugin = $this->freshPlugin();
        $plugin->boot();
        do_action( 'init' );
        $this->assertSame( $before, $GLOBALS['lga_test_options'], 'nothing was written on a page view' );
        $this->assertFalse( self::servicesRegistered( $plugin ) );
    }

    public function testAMaintenanceRequestStartsTheQuietWindowAfterAnUpdate(): void {
        update_option( Plugin::VERSION_OPTION, [ 'version' => '0.9.9', 'since' => 1, 'from' => '' ] );
        add_filter( 'lga_maintenance_request', static fn() => true );
        $this->freshPlugin()->boot();
        $stored = get_option( Plugin::VERSION_OPTION );
        $this->assertSame( LGA_VERSION, $stored['version'] );
        $this->assertSame( '0.9.9', $stored['from'] );
        $this->assertGreaterThan( time() - 60, $stored['since'], 'the quiet window starts now' );
    }

    public function testTheCronIntervalFilterBuildsOnlyTheSettings(): void {
        $plugin    = $this->freshPlugin();
        $schedules = $plugin->cronSchedules( [ 'hourly' => [ 'interval' => 3600, 'display' => 'Hourly' ] ] );
        $this->assertSame( 300, $schedules[ Scheduler::INTERVAL_NAME ]['interval'] );
        $this->assertArrayHasKey( 'hourly', $schedules );
        $this->assertArrayNotHasKey( Scheduler::class, self::instances( $plugin ), 'no pipeline or job runner for a filter' );
        $this->assertSame( $schedules, Container::get( Scheduler::class )->cronSchedules( [ 'hourly' => [ 'interval' => 3600, 'display' => 'Hourly' ] ] ) );
    }

    /**
     * Review finding: is_admin() is true for every admin-ajax.php request, so a logged-out visitor's AJAX call (theme
     * live search, wishlist) ran the migration and the hourly checks. Measured on a copy of the test site with the
     * schema version behind (php -S on one CPU, SAVEQUERIES): that call loaded 18 plugin files and made 114 plugin
     * queries and 10 writes before, and 4 files with 0 plugin queries and 0 writes after.
     */
    public function testALoggedOutVisitorsAdminAjaxCallIsNoMaintenanceRequest(): void {
        $GLOBALS['lga_test_is_admin']   = true;
        $GLOBALS['lga_test_doing_ajax'] = true;
        unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
        $this->assertFalse( Plugin::adminRequest(), 'admin-ajax.php without a login cookie: a visitor' );
        $plugin = $this->freshPlugin();
        $plugin->boot();
        foreach ( [ 'admin_init', 'admin_menu', 'wp_ajax_' . Plugin::NOTICE_DISMISS_ACTION ] as $hook ) {
            $this->assertEmpty( $GLOBALS['lga_test_actions'][ $hook ] ?? [], $hook . ' is not hooked for a visitor\'s AJAX call' );
        }

        $_COOKIE[ LOGGED_IN_COOKIE ] = 'admin|1700000000|token|hash';
        try {
            $this->assertTrue( Plugin::adminRequest(), 'admin-ajax.php with a login cookie (the editor, heartbeat)' );
        } finally {
            unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
        }

        $GLOBALS['lga_test_doing_ajax'] = false;
        $this->assertTrue( Plugin::adminRequest(), 'a wp-admin page' );
        $this->freshPlugin()->boot();
        $this->assertNotEmpty( $GLOBALS['lga_test_actions']['admin_init'] ?? [], 'a wp-admin page keeps its admin hooks' );
        $GLOBALS['lga_test_is_admin'] = false;
        $this->assertFalse( Plugin::adminRequest(), 'a page view' );
    }

    public function testMaintenanceRequestsKeepTheirChecks(): void {
        add_filter( 'lga_maintenance_request', static fn() => true );
        $plugin = $this->freshPlugin();
        $plugin->boot();
        $init = $GLOBALS['lga_test_actions']['init'] ?? [];
        foreach ( [ 'maybeMigrate', 'ensureCapabilities', 'maybeSchedule' ] as $method ) {
            $this->assertTrue( self::hasMethod( $init, $plugin, $method ), $method . ' runs on admin, cron and WP-CLI requests' );
        }
    }

    public function testTheHookNamesWrittenOutInPluginMatchTheClasses(): void {
        $this->assertSame( Scheduler::HOOK, Plugin::TICK_HOOK );
        $this->assertSame( LoadGuard::OPTION_VERSION, Plugin::VERSION_OPTION );
        $this->assertSame( \LinkGrowth\Autopilot\Admin\Notices::ACTION, Plugin::NOTICE_DISMISS_ACTION );
        $this->assertSame( \LinkGrowth\Autopilot\Admin\Menu::REDIRECT_TRANSIENT, Plugin::REDIRECT_TRANSIENT );

        // Plugin::STOREFRONT_HOOKS is exactly what PositioningGate::register() adds.
        $GLOBALS['lga_test_actions'] = [];
        Container::get( PositioningGate::class )->register();
        $this->assertSame( array_keys( Plugin::STOREFRONT_HOOKS ), array_keys( $GLOBALS['lga_test_actions'] ) );
    }

    /** The admin hooks Plugin::boot() registers lazily are exactly the ones Menu, Assets and Notices register. */
    public function testTheLazyAdminHooksMatchTheAdminClasses(): void {
        $hooks = static fn(): array => [ array_keys( $GLOBALS['lga_test_actions'] ?? [] ), array_keys( $GLOBALS['lga_test_filters'] ?? [] ) ];
        $GLOBALS['lga_test_actions'] = $GLOBALS['lga_test_filters'] = [];
        $this->freshPlugin()->boot();
        [ $siteActions, $siteFilters ] = $hooks();

        $GLOBALS['lga_test_actions'] = $GLOBALS['lga_test_filters'] = [];
        $GLOBALS['lga_test_is_admin'] = true;
        $plugin                         = $this->freshPlugin();
        $plugin->boot();
        [ $adminActions, $adminFilters ] = $hooks();
        $this->assertFalse( self::servicesRegistered( $plugin ), 'an admin request builds the admin screens only when their hooks fire' );

        $GLOBALS['lga_test_actions'] = $GLOBALS['lga_test_filters'] = [];
        foreach ( [ \LinkGrowth\Autopilot\Admin\Menu::class, \LinkGrowth\Autopilot\Admin\Assets::class, \LinkGrowth\Autopilot\Admin\Notices::class ] as $class ) {
            Container::get( $class )->register();
        }
        [ $classActions, $classFilters ] = $hooks();
        sort( $classActions );
        sort( $classFilters );
        $lazyActions = array_values( array_diff( $adminActions, $siteActions ) );
        $lazyFilters = array_values( array_diff( $adminFilters, $siteFilters ) );
        sort( $lazyActions );
        sort( $lazyFilters );
        $this->assertSame( $classActions, $lazyActions );
        $this->assertSame( $classFilters, $lazyFilters );
    }

    public function testTheHourlyScheduleCheckDoesNotRewriteAnUnchangedInterval(): void {
        $scheduler = Container::get( Scheduler::class );
        $remember  = new \ReflectionMethod( $scheduler, 'rememberInterval' );
        $remember->invoke( $scheduler, 300 );
        $this->assertSame( 300, get_option( Scheduler::OPTION_INTERVAL ), 'first save' );
        update_option( Scheduler::OPTION_INTERVAL, '300' ); // as MySQL hands it back
        $remember->invoke( $scheduler, 300 );
        $this->assertSame( '300', get_option( Scheduler::OPTION_INTERVAL ), 'unchanged: not saved again' );
        $remember->invoke( $scheduler, 600 );
        $this->assertSame( 600, get_option( Scheduler::OPTION_INTERVAL ), 'a changed interval is saved' );

        delete_option( Scheduler::OPTION_INTERVAL ); // (a changed interval rebuilds the schedule and clears the marker)
        $this->assertFalse( Scheduler::checkedRecently() );
        $scheduler->maybeSchedule();
        $this->assertTrue( Scheduler::checkedRecently(), 'the hourly marker' );
    }

    public function testRestRoutesAreRegisteredOnlyForRequestsThatCanReachThem(): void {
        $route = static function ( ?string $r ): bool {
            $GLOBALS['wp'] = (object) [ 'query_vars' => null === $r ? [] : [ 'rest_route' => $r ] ];
            return Plugin::restRequestNeedsRoutes();
        };
        $this->assertTrue( $route( null ), 'PHP dispatch (rest_do_request, preloading)' );
        $this->assertTrue( $route( '/' ), 'the REST index lists every route' );
        $this->assertTrue( $route( '/lga/v1/overview' ) );
        $this->assertTrue( $route( 'lga/v1/public/unsubscribe' ) );
        $this->assertTrue( $route( '/lga' ) );
        $this->assertTrue( $route( '/batch/v1' ) );
        $this->assertFalse( $route( '/wc/store/v1/cart' ), 'WooCommerce cart and checkout' );
        $this->assertFalse( $route( '/wp/v2/posts' ) );
        $this->assertFalse( $route( '/lgax/v1' ) ); // a namespace that only starts like the plugin's
        unset( $GLOBALS['wp'] );
        $this->assertTrue( Plugin::restRequestNeedsRoutes(), 'not parsed yet (another plugin asked for the REST server early): registered' );

        // A dispatch from PHP after the request was parsed (a plugin preloading REST data into a page view): only
        // admin, cron and command-line requests need the plugin's routes then.
        $GLOBALS['lga_test_did']['parse_request'] = 1;
        $this->assertTrue( $route( null ), 'on the CLI (cron, WP-CLI) a PHP dispatch registers them' );
        self::webRequest();
        $this->assertFalse( $route( null ), 'a page view that preloads REST data does not' );
        $this->assertTrue( $route( '/lga/v1/public/unsubscribe' ), 'a REST request for the plugin still does' );
        $this->assertFalse( $route( '/wc/store/v1/cart' ) );
        unset( $GLOBALS['lga_test_filters']['lga_maintenance_request'] );
        $GLOBALS['lga_test_did'] = [];

        // A visitor REST request to another plugin builds no controller; ours does.
        $GLOBALS['lga_test_routes'] = [];
        $plugin                       = $this->freshPlugin();
        $GLOBALS['wp']                = (object) [ 'query_vars' => [ 'rest_route' => '/wc/store/v1/cart' ] ];
        $plugin->registerRest();
        $this->assertSame( [], $GLOBALS['lga_test_routes'] );
        $this->assertFalse( self::servicesRegistered( $plugin ) );
        $GLOBALS['wp'] = (object) [ 'query_vars' => [ 'rest_route' => '/lga/v1/overview' ] ];
        $plugin->registerRest();
        $this->assertArrayHasKey( 'lga/v1/overview', $GLOBALS['lga_test_routes'] );
    }

    public function testTheRestOptOutRegistersEverywhere(): void {
        $GLOBALS['lga_test_routes'] = [];
        add_filter( 'lga_rest_register_all', static fn() => true );
        $GLOBALS['wp'] = (object) [ 'query_vars' => [ 'rest_route' => '/wp/v2/posts' ] ];
        $this->freshPlugin()->registerRest();
        $this->assertNotEmpty( $GLOBALS['lga_test_routes'] );
    }

    /**
     * 1.2.4 performance: a kick and the recurring tick that fall due together in one Action Scheduler batch (the same
     * PHP worker) do not run back to back; a tick in another request is not held back.
     */
    public function testATickRightAfterAnotherInTheSameWorkerDoesNothing(): void {
        $last      = \LinkGrowth\Autopilot\Automation\Pipeline::OPTION_LAST_TICK;
        $scheduler = Container::get( Scheduler::class );
        $GLOBALS['lga_test_option_writes'] = [];
        $scheduler->onTick();
        $this->assertSame( 1, $GLOBALS['lga_test_option_writes'][ $last ] ?? 0, 'the first tick ran' );
        $scheduler->onTick( time() ); // the kick that was due in the same batch
        $this->assertSame( 1, $GLOBALS['lga_test_option_writes'][ $last ] ?? 0, 'skipped: no second tick in this worker' );

        // The next recurring tick in this (long-running) process, a minute later, runs.
        ( new \ReflectionProperty( Scheduler::class, 'tickStartedAt' ) )->setValue( $scheduler, microtime( true ) - Scheduler::MIN_TICK_GAP_SECONDS - 1 );
        $scheduler->onTick();
        $this->assertSame( 2, $GLOBALS['lga_test_option_writes'][ $last ] ?? 0 );

        // Another request (a fresh scheduler) is not held back by a tick that started moments ago elsewhere.
        update_option( $last, gmdate( 'c', time() - 5 ) );
        Container::reset();
        $GLOBALS['lga_test_option_writes'] = [];
        Container::get( Scheduler::class )->onTick( time() );
        $this->assertSame( 1, $GLOBALS['lga_test_option_writes'][ $last ] ?? 0, "the owner's kick in its own request runs" );
    }

    public function testDeactivationReleasesTheLocksWithoutFlushingTheObjectCache(): void {
        // (The rows themselves are removed by SQL; tests/integration/run.php checks that on a real database.)
        $GLOBALS['lga_test_cache_flushes'] = 0;
        Container::get( Locks::class )->releaseAll();
        $this->assertSame( 0, $GLOBALS['lga_test_cache_flushes'], 'wp_cache_flush() is never called' );
        $this->assertStringNotContainsString( 'wp_cache_flush()', (string) file_get_contents( LGA_DIR . 'includes/Support/Locks.php' ) );
        $this->assertStringNotContainsString( 'wp_cache_flush()', (string) file_get_contents( LGA_DIR . 'uninstall.php' ), 'nor on uninstall' );
    }
}
