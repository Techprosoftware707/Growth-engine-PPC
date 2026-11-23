<?php
/**
 * Plugin bootstrap: loads the modules (Core\Modules), registers their services and boots their hooks; lifecycle.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot;

use LinkGrowth\Autopilot\Admin\Capabilities;
use LinkGrowth\Autopilot\Admin\Menu;
use LinkGrowth\Autopilot\Admin\Rest\RestApi;
use LinkGrowth\Autopilot\Automation\LoadGuard;
use LinkGrowth\Autopilot\Automation\Scheduler;
use LinkGrowth\Autopilot\Core\Environment;
use LinkGrowth\Autopilot\Core\Module;
use LinkGrowth\Autopilot\Core\Modules;
use LinkGrowth\Autopilot\Database\Migrator;
use LinkGrowth\Autopilot\Health\SiteHealthTests;
use LinkGrowth\Autopilot\Seo\IndexNow;
use LinkGrowth\Autopilot\Support\Settings;

final class Plugin {

    /** The product name. Every self-reference in shipped copy uses it (translated with __( Plugin::NAME, … )). */
    public const NAME = 'LinkGrowth Autopilot';

    private static ?Plugin $instance = null;

    /** Scheduler::HOOK, written out so that registering the hook does not load the Scheduler class. */
    public const TICK_HOOK = 'lga_tick';

    /** LoadGuard::OPTION_VERSION (autoloaded), written out for the same reason. */
    public const VERSION_OPTION = 'lga_running_version';

    /**
     * Storefront compliance (PositioningGate::register()): hook => [method, accepted args]. Registered here with
     * callbacks that build the audit only when one of these fires, so a page view never loads it.
     */
    public const STOREFRONT_HOOKS = [
        'save_post_product'       => [ 'markStaleForPost', 1 ],
        'save_post_page'          => [ 'markStaleForPost', 1 ],
        'save_post_post'          => [ 'markStaleForPost', 1 ],
        'trashed_post'            => [ 'markStaleForPost', 1 ],
        'untrashed_post'          => [ 'markStaleForPost', 1 ],
        'deleted_post'            => [ 'markStaleForPost', 1 ],
        'created_product_cat'     => [ 'markStale', 0 ],
        'edited_product_cat'      => [ 'markStale', 0 ],
        'delete_product_cat'      => [ 'markStale', 0 ],
        'created_product_tag'     => [ 'markStale', 0 ],
        'edited_product_tag'      => [ 'markStale', 0 ],
        'delete_product_tag'      => [ 'markStale', 0 ],
        'wp_update_nav_menu'      => [ 'markStale', 0 ],
        'wp_delete_nav_menu'      => [ 'markStale', 0 ],
        'wp_update_nav_menu_item' => [ 'markStale', 0 ],
    ];

    private Container $container;

    private bool $booted = false;

    private bool $servicesRegistered = false;

    /** @var list<Module>|null the modules of this request (modules()) */
    private ?array $modules = null;

    /** Whether this is a maintenance request, decided once when the plugin boots (isMaintenanceRequest()). */
    private ?bool $maintenance = null;

    /** @var array<string,string> module id => message of a module whose boot() threw (the others still booted) */
    private array $bootErrors = [];

    public static function instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->container = new Container();
    }

    /** The service container (the factories are registered the first time a service is needed). */
    public function container(): Container {
        if ( ! $this->servicesRegistered ) {
            $this->servicesRegistered = true;
            $this->registerServices();
        }
        return $this->container;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get( string $id ): object {
        return $this->container()->get( $id );
    }

    /**
     * The modules of this request (Core\Modules::LIST through the `lga_modules` filter), built once.
     *
     * @return list<Module>
     */
    public function modules(): array {
        if ( null === $this->modules ) {
            $this->modules = Modules::load();
        }
        return $this->modules;
    }

    /** @return array<string,string> module id => message, for each module whose boot() threw */
    public function bootErrors(): array {
        return $this->bootErrors;
    }

    /**
     * Registers the plugin's hooks: every module's boot() (Core\Modules order), then `lga_booted`.
     *
     * 1.2.4 performance rule, for every module: a visitor's request (page view, REST call to another plugin,
     * storefront AJAX, including a logged-out visitor's admin-ajax.php call) only adds hook callbacks here. It reads
     * and writes nothing in the database and builds no service (services() is not even called): every callback
     * resolves its service when its hook fires with work to do (lazy()). The checks that read or write (schema
     * migration, capability sync, the schedule self-heal, the update marker of the quiet window) run on admin, cron
     * and WP-CLI requests only (isMaintenanceRequest()); the migration also runs before the plugin's own REST routes,
     * which use its tables. Measured on the test site before this rule: 28 plugin files and 3 plugin queries on every
     * page view, 9 writes on the page view after the hourly timers expired, and the whole migration (backup dump plus
     * dbDelta: 110 plugin queries, 7 writes) on the first page view after a schema change.
     */
    public function boot(): void {
        if ( $this->booted ) {
            return;
        }
        $this->booted = true;

        load_plugin_textdomain( 'linkgrowth-autopilot', false, dirname( LGA_BASENAME ) . '/languages' );

        // Each module adds its hook callbacks (Module::boot()). A module that throws leaves its hooks out; the
        // others still boot.
        foreach ( $this->modules() as $module ) {
            try {
                $module->boot( $this );
            } catch ( \Throwable $e ) {
                $this->bootErrors[ $module->id() ] = $e->getMessage();
            }
        }

        /**
         * Fires once the LinkGrowth Autopilot has finished booting.
         *
         * @param Plugin $plugin
         */
        do_action( 'lga_booted', $this );
    }

    /**
     * Whether the current request is a maintenance request (maintenanceRequest()), asked once per plugin instance so
     * that every module's boot() sees the same answer.
     */
    public function isMaintenanceRequest(): bool {
        if ( null === $this->maintenance ) {
            $this->maintenance = self::maintenanceRequest();
        }
        return $this->maintenance;
    }

    /** Notices::ACTION (the dismiss AJAX action), written out so that registering it does not load Notices. */
    public const NOTICE_DISMISS_ACTION = 'lga_dismiss_notice';

    /** Menu::REDIRECT_TRANSIENT (the one-time redirect after activation), written out for the same reason. */
    public const REDIRECT_TRANSIENT = 'lga_activation_redirect';

    /**
     * Admin, cron, WP-CLI and other command-line requests (no visitor waits on those): the only ones that run the
     * plugin's maintenance checks (filter 'lga_maintenance_request').
     */
    public static function maintenanceRequest(): bool {
        $maintenance = self::adminRequest() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || 'cli' === PHP_SAPI;
        return (bool) apply_filters( 'lga_maintenance_request', $maintenance );
    }

    /**
     * A wp-admin request of someone who may be logged in. is_admin() is also true for every admin-ajax.php request,
     * the logged-out ones a storefront sends included (theme live search, wishlists, form plugins); such a request
     * counts only with a login cookie. The cookie is read instead of is_user_logged_in() because boot() asks on
     * plugins_loaded, before WordPress determines the current user: asking that early would settle the user before
     * other plugins' authentication filters are added. A visitor holding a stale login cookie still counts, as before.
     */
    public static function adminRequest(): bool {
        if ( ! is_admin() ) {
            return false;
        }
        return ! wp_doing_ajax() || ( defined( 'LOGGED_IN_COOKIE' ) && ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- presence only
    }

    /** `init` (priority 1, maintenance requests): migrate when the schema version or a table is behind. */
    public function maybeMigrate(): void {
        if ( Migrator::upToDate() ) {
            return; // two cheap reads; the migrator and its backup are built only when there is work
        }
        $this->get( Migrator::class )->maybeMigrate();
    }

    /** `init` (priority 2, maintenance requests): capability and submitter-role sync (throttled hourly). */
    public function ensureCapabilities(): void {
        $this->get( Capabilities::class )->ensure();
    }

    /** `init` (priority 20, maintenance requests): the schedule self-heal (throttled hourly). */
    public function maybeSchedule(): void {
        if ( Scheduler::checkedRecently() ) {
            return; // the scheduler (pipeline, runner) is built only when the hourly check is due
        }
        $this->get( Scheduler::class )->maybeSchedule();
    }

    /** `admin_init`: the one-time redirect to the dashboard after activation (Menu::maybeRedirect()). */
    public function maybeRedirect(): void {
        if ( ! get_transient( self::REDIRECT_TRANSIENT ) ) {
            return;
        }
        $this->get( Menu::class )->maybeRedirect();
    }

    /**
     * `rest_api_init`: registers the plugin's routes when the request can reach them (restRequestNeedsRoutes()).
     * Other REST requests (WooCommerce's Store API cart and checkout, /wp/v2/...) and REST requests that another
     * plugin dispatches from PHP inside a page view (preloading data into the page) do not load the plugin's 17
     * controllers.
     * The 'lga_rest_register_all' filter (true) registers them on every REST request.
     */
    public function registerRest( mixed $server = null ): void {
        unset( $server );
        if ( ! self::restRequestNeedsRoutes() && ! apply_filters( 'lga_rest_register_all', false ) ) {
            return;
        }
        if ( self::pluginRestRoute() ) {
            // The plugin's routes read and write its tables: migrate first when the schema is behind (as on init for
            // admin, cron and WP-CLI requests). Two cached reads when it is up to date.
            $this->maybeMigrate();
        }
        $this->get( RestApi::class )->register();
    }

    /** Whether the current request is a REST request for one of the plugin's own routes (/lga/...). */
    public static function pluginRestRoute(): bool {
        $route = self::restRoute();
        return '/lga' === $route || str_starts_with( $route, '/lga/' );
    }

    /**
     * The route of the current REST request ('/' for the REST index), '' when this is not a REST request or the
     * request has not been parsed yet (WordPress sets the rest_route query var before `parse_request`).
     */
    private static function restRoute(): string {
        $wp    = $GLOBALS['wp'] ?? null;
        $route = is_object( $wp ) && isset( $wp->query_vars ) && is_array( $wp->query_vars ) ? ( $wp->query_vars['rest_route'] ?? '' ) : '';
        return is_string( $route ) && '' !== $route ? '/' . trim( $route, '/' ) : '';
    }

    /**
     * Whether the REST dispatch that is starting (rest_api_init) can reach the plugin's routes:
     *  - a REST request: when its route is one of the plugin's (/lga/...), the REST index or a batch request;
     *  - a dispatch from PHP inside another request (rest_do_request(), REST preloading): on admin, cron and
     *    command-line requests only. Nothing of the plugin runs through REST inside a visitor's page view, where
     *    another plugin may preload REST data into the page;
     *  - too early to tell (another plugin asked for the REST server before the request was parsed): registered.
     */
    public static function restRequestNeedsRoutes(): bool {
        $route = self::restRoute();
        if ( '' !== $route ) {
            if ( '/' === $route ) {
                return true; // the REST index lists every route
            }
            foreach ( [ '/lga', '/batch' ] as $prefix ) {
                if ( $route === $prefix || str_starts_with( $route, $prefix . '/' ) ) {
                    return true;
                }
            }
            return false;
        }
        if ( ! did_action( 'parse_request' ) ) {
            return true;
        }
        return self::maintenanceRequest();
    }

    /**
     * Starts the quiet window when the running version differs from the autoloaded marker (one in-memory read).
     * Called by the automation module's boot() on maintenance requests.
     */
    public function noteVersion(): void {
        $stored = get_option( self::VERSION_OPTION, null );
        if ( is_array( $stored ) && LGA_VERSION === (string) ( $stored['version'] ?? '' ) ) {
            return;
        }
        try {
            $this->get( LoadGuard::class )->noteVersion();
        } catch ( \Throwable $e ) {
            unset( $e );
        }
    }

    /**
     * `site_status_tests`: the OPcache test (Health\SiteHealthTests) and the other-plugins test (Core\Environment,
     * DESIGN.md §1.4). Applied on the Site Health screen, its REST
     * endpoints and the weekly cron check; never on a page view.
     *
     * @param mixed $tests
     * @return mixed
     */
    public function siteStatusTests( mixed $tests ): mixed {
        try {
            $tests = $this->get( SiteHealthTests::class )->addTests( $tests );
            return $this->get( Environment::class )->addTests( $tests );
        } catch ( \Throwable $e ) {
            return $tests;
        }
    }

    /**
     * `cron_schedules`: the tick interval (Scheduler::cronSchedules()).
     *
     * @param mixed $schedules
     * @return mixed
     */
    public function cronSchedules( mixed $schedules ): mixed {
        try {
            // Only the settings are needed for the interval; the scheduler (pipeline, job runner) is not built.
            return Scheduler::withSchedule( $schedules, Scheduler::intervalFrom( $this->get( Settings::class ) ) );
        } catch ( \Throwable $e ) {
            return $schedules;
        }
    }

    /**
     * A hook callback that builds service $id when the hook fires and calls $method with the hook's arguments.
     * With $quiet a service that cannot be built (or a callback that throws) never breaks the host action.
     */
    public function lazy( string $id, string $method, bool $quiet = false ): \Closure {
        return function ( mixed ...$args ) use ( $id, $method, $quiet ): mixed {
            if ( ! $quiet ) {
                return $this->get( $id )->{$method}( ...$args );
            }
            try {
                return $this->get( $id )->{$method}( ...$args );
            } catch ( \Throwable $e ) {
                unset( $e ); // The gate fails closed on its own when the audit cannot run; never break a save.
                return null;
            }
        };
    }

    /**
     * `parse_request` (priority 0): answers GET/HEAD /{indexnow-key}.txt with the key and ends the request.
     * Every other request is left alone; the service is resolved only for paths that look like a key file.
     */
    public function maybeServeIndexNowKey( mixed $wp = null ): void {
        try {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only matched against a strict pattern.
            $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
            // A string test first, so that ordinary page views do not load the IndexNow class.
            if ( str_ends_with( (string) wp_parse_url( $uri, PHP_URL_PATH ), '.txt' ) && IndexNow::looksLikeKeyRequest( $uri ) ) {
                $this->get( IndexNow::class )->serveKey( $wp );
            }
        } catch ( \Throwable $e ) {
            unset( $e );
        }
    }

    /** `transition_post_status`: queue a newly published / updated public URL for IndexNow. */
    public function onPostTransition( mixed $new = '', mixed $old = '', mixed $post = null ): void {
        if ( 'publish' !== $new ) {
            return; // only published items are submitted (fires for every status change, autosaves included)
        }
        try {
            $this->get( IndexNow::class )->onTransition( $new, $old, $post );
        } catch ( \Throwable $e ) {
            unset( $e );
        }
    }

    /** `save_post` (priority 20): queue the saved URL for IndexNow when it is published. */
    public function onSavePost( mixed $postId = 0, mixed $post = null, mixed $update = null ): void {
        try {
            $this->get( IndexNow::class )->onSavePost( $postId, $post, $update );
        } catch ( \Throwable $e ) {
            unset( $e );
        }
    }

    /** Registers the service factories every module declares (Module::services()), on the first service request. */
    private function registerServices(): void {
        foreach ( $this->modules() as $module ) {
            $module->services( $this->container );
        }
    }
}
