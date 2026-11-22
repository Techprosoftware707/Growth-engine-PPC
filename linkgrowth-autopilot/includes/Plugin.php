<?php
/**
 * Plugin bootstrap: wires services, hooks and lifecycle.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot;

use LinkGrowth\Autopilot\Admin\Assets;
use LinkGrowth\Autopilot\Admin\Capabilities;
use LinkGrowth\Autopilot\Admin\Menu;
use LinkGrowth\Autopilot\Admin\Notices;
use LinkGrowth\Autopilot\Admin\Rest\RestApi;
use LinkGrowth\Autopilot\Admin\SiteHealth;
use LinkGrowth\Autopilot\Analysis\ContactExtractor;
use LinkGrowth\Autopilot\Analysis\FormDetector;
use LinkGrowth\Autopilot\Analysis\PageAnalyzer;
use LinkGrowth\Autopilot\Analysis\RelevanceScorer;
use LinkGrowth\Autopilot\Analysis\RiskAnalyzer;
use LinkGrowth\Autopilot\Analysis\SiteProfiler;
use LinkGrowth\Autopilot\Analysis\SubmissionPageFinder;
use LinkGrowth\Autopilot\Analysis\Taxonomy;
use LinkGrowth\Autopilot\Automation\Audit;
use LinkGrowth\Autopilot\Automation\Autopilot;
use LinkGrowth\Autopilot\Automation\JobRunner;
use LinkGrowth\Autopilot\Automation\LoadGuard;
use LinkGrowth\Autopilot\Automation\Pipeline;
use LinkGrowth\Autopilot\Automation\Scheduler;
use LinkGrowth\Autopilot\Compliance\PositioningGate;
use LinkGrowth\Autopilot\Content\ClaimsFilter;
use LinkGrowth\Autopilot\Content\ContentGenerator;
use LinkGrowth\Autopilot\Content\FactsRegistry;
use LinkGrowth\Autopilot\Content\LinkableAssets;
use LinkGrowth\Autopilot\Core\Environment;
use LinkGrowth\Autopilot\Database\Backup;
use LinkGrowth\Autopilot\Database\Migrator;
use LinkGrowth\Autopilot\Database\Schema;
use LinkGrowth\Autopilot\Discovery\BrokenLinkFinder;
use LinkGrowth\Autopilot\Discovery\Classifier;
use LinkGrowth\Autopilot\Discovery\CompetitorBacklinkSource;
use LinkGrowth\Autopilot\Discovery\DiscoveryEngine;
use LinkGrowth\Autopilot\Discovery\DomainFilter;
use LinkGrowth\Autopilot\Discovery\MentionFinder;
use LinkGrowth\Autopilot\Discovery\ProspectIntake;
use LinkGrowth\Autopilot\Discovery\QueryGenerator;
use LinkGrowth\Autopilot\Discovery\PackSeeds;
use LinkGrowth\Autopilot\Discovery\CatalogImporter;
use LinkGrowth\Autopilot\Growth\GrowthRun;
use LinkGrowth\Autopilot\Integrations\AiWriter;
use LinkGrowth\Autopilot\Integrations\Google\Analytics4;
use LinkGrowth\Autopilot\Integrations\Google\SearchConsole;
use LinkGrowth\Autopilot\Integrations\Google\ServiceAccount;
use LinkGrowth\Autopilot\Integrations\Google\SiteKitBridge;
use LinkGrowth\Autopilot\Integrations\MetricsAggregator;
use LinkGrowth\Autopilot\Integrations\ProviderRegistry;
use LinkGrowth\Autopilot\Integrations\Rdap;
use LinkGrowth\Autopilot\Monitoring\BacklinkImporter;
use LinkGrowth\Autopilot\Monitoring\BacklinkMonitor;
use LinkGrowth\Autopilot\Monitoring\Notifier;
use LinkGrowth\Autopilot\Outreach\CampaignFiller;
use LinkGrowth\Autopilot\Outreach\Compliance;
use LinkGrowth\Autopilot\Outreach\FollowUps;
use LinkGrowth\Autopilot\Outreach\InboundWebhook;
use LinkGrowth\Autopilot\Outreach\Mailer;
use LinkGrowth\Autopilot\Outreach\OutreachEngine;
use LinkGrowth\Autopilot\Outreach\Personalizer;
use LinkGrowth\Autopilot\Outreach\Templates;
use LinkGrowth\Autopilot\Outreach\Unsubscribe;
use LinkGrowth\Autopilot\Repositories\AuditLog;
use LinkGrowth\Autopilot\Repositories\Backlinks;
use LinkGrowth\Autopilot\Repositories\Campaigns;
use LinkGrowth\Autopilot\Repositories\Contacts;
use LinkGrowth\Autopilot\Repositories\DomainMetrics;
use LinkGrowth\Autopilot\Repositories\Jobs;
use LinkGrowth\Autopilot\Repositories\Logs;
use LinkGrowth\Autopilot\Repositories\Notifications;
use LinkGrowth\Autopilot\Repositories\Opportunities;
use LinkGrowth\Autopilot\Repositories\OutreachMessages;
use LinkGrowth\Autopilot\Repositories\Prospects;
use LinkGrowth\Autopilot\Repositories\SearchLog;
use LinkGrowth\Autopilot\Repositories\Submissions;
use LinkGrowth\Autopilot\Repositories\Suppression;
use LinkGrowth\Autopilot\Scoring\ScoringEngine;
use LinkGrowth\Autopilot\Seo\IndexNow;
use LinkGrowth\Autopilot\Seo\InternalLinker;
use LinkGrowth\Autopilot\Seo\RenderCheck;
use LinkGrowth\Autopilot\Seo\SiteAuditor;
use LinkGrowth\Autopilot\Submission\AdapterRegistry;
use LinkGrowth\Autopilot\Submission\ListingVerifier;
use LinkGrowth\Autopilot\Submission\PackageBuilder;
use LinkGrowth\Autopilot\Submission\Policy;
use LinkGrowth\Autopilot\Submission\SubmissionEngine;
use LinkGrowth\Autopilot\Support\Crypto;
use LinkGrowth\Autopilot\Support\Http\Client;
use LinkGrowth\Autopilot\Support\Http\RobotsTxt;
use LinkGrowth\Autopilot\Support\Limits;
use LinkGrowth\Autopilot\Support\Locks;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Opcache;
use LinkGrowth\Autopilot\Support\Secrets;
use LinkGrowth\Autopilot\Support\ServerResources;
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
     * Registers the plugin's hooks.
     *
     * 1.2.4 performance rule: a visitor's request (page view, REST call to another plugin, storefront AJAX, including
     * a logged-out visitor's admin-ajax.php call) only adds hook callbacks here. It reads and writes nothing in the
     * database and builds no service: every callback below resolves its service when its hook fires with work to do.
     * The checks that read or write (schema migration, capability sync, the schedule self-heal, the update marker of
     * the quiet window) run on admin, cron and WP-CLI requests only (maintenanceRequest()); the migration also runs
     * before the plugin's own REST routes, which use its tables. Measured on the test site before this rule: 28
     * plugin files and 3 plugin queries on every page view, 9 writes on the page view after the hourly timers
     * expired, and the whole migration (backup dump plus dbDelta: 110 plugin queries, 7 writes) on the first page
     * view after a schema change.
     */
    public function boot(): void {
        if ( $this->booted ) {
            return;
        }
        $this->booted = true;

        load_plugin_textdomain( 'linkgrowth-autopilot', false, dirname( LGA_BASENAME ) . '/languages' );

        if ( self::maintenanceRequest() ) {
            // Database migrations (with backup) when the stored version differs: never inside a shopper's page view.
            add_action( 'init', [ $this, 'maybeMigrate' ], 1 );
            // Capabilities are (re)synced so role changes by other plugins do not strip ours (at most hourly).
            add_action( 'init', [ $this, 'ensureCapabilities' ], 2 );
            // Self-healing schedule check for the recurring tick (at most hourly).
            add_action( 'init', [ $this, 'maybeSchedule' ], 20 );
            // Server pressure (1.2.4): an update starts the 10-minute quiet window on the first admin, cron or WP-CLI
            // request that runs the new version (one in-memory compare of an autoloaded option otherwise). The
            // background tick runs on cron and LoadGuard::pauseReason() notes the version itself, so the window is in
            // place before any background work, and a visitor's page view never writes the marker.
            $this->noteVersion();
        }

        // REST API (admin + public endpoints): registered for requests to the plugin's own routes only.
        add_action( 'rest_api_init', [ $this, 'registerRest' ] );

        // Site Health warns when PHP's code cache is full (applied on the Site Health screen and its checks only).
        add_filter( 'site_status_tests', [ $this, 'siteStatusTests' ] );

        // Background automation: the recurring tick and its WP-Cron interval.
        add_action( self::TICK_HOOK, $this->lazy( Scheduler::class, 'onTick' ) );
        add_filter( 'cron_schedules', [ $this, 'cronSchedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval

        // Storefront compliance: mark the preflight stale when products, product categories/tags, pages/posts or nav
        // menus change (outside is_admin(): products are also saved through REST and the WooCommerce API).
        foreach ( self::STOREFRONT_HOOKS as $hook => [ $method, $args ] ) {
            add_action( $hook, $this->lazy( PositioningGate::class, $method, true ), 20, $args );
        }

        // IndexNow: serve the key file on front-end requests (as early as possible) and track changed URLs. Each
        // callback resolves the service only when needed and never throws.
        add_action( 'parse_request', [ $this, 'maybeServeIndexNowKey' ], 0 );
        add_action( 'transition_post_status', [ $this, 'onPostTransition' ], 10, 3 );
        add_action( 'save_post', [ $this, 'onSavePost' ], 20, 3 );

        if ( self::adminRequest() ) {
            // The same hooks Menu::register(), Assets::register() and Notices::register() add, built on first use. Not
            // for a logged-out visitor's admin-ajax.php call: none of them serves it (the dismiss action is wp_ajax_
            // only), and admin_init would read the activation-redirect transient and build the menu on every call.
            add_action( 'admin_menu', $this->lazy( Menu::class, 'addMenu' ) );
            add_action( 'admin_init', [ $this, 'maybeRedirect' ] );
            add_filter( 'admin_body_class', $this->lazy( Menu::class, 'bodyClass' ) );
            // WooCommerce sends users without edit_posts out of wp-admin; the submitter role needs its one screen.
            add_filter( 'woocommerce_prevent_admin_access', $this->lazy( Menu::class, 'allowSubmitterAdmin' ) );
            add_action( 'admin_enqueue_scripts', $this->lazy( Assets::class, 'enqueue' ) );
            add_action( 'admin_notices', $this->lazy( Notices::class, 'render' ) );
            add_action( 'wp_ajax_' . self::NOTICE_DISMISS_ACTION, $this->lazy( Notices::class, 'dismiss' ) );
        }

        /**
         * Fires once the LinkGrowth Autopilot has finished booting.
         *
         * @param Plugin $plugin
         */
        do_action( 'lga_booted', $this );
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

    /** Starts the quiet window when the running version differs from the autoloaded marker (one in-memory read). */
    private function noteVersion(): void {
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
     * `site_status_tests`: the OPcache test (Admin\SiteHealth) and the other-plugins test (Core\Environment,
     * DESIGN.md §1.4). Applied on the Site Health screen, its REST
     * endpoints and the weekly cron check; never on a page view.
     *
     * @param mixed $tests
     * @return mixed
     */
    public function siteStatusTests( mixed $tests ): mixed {
        try {
            $tests = $this->get( SiteHealth::class )->addTests( $tests );
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
    private function lazy( string $id, string $method, bool $quiet = false ): \Closure {
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

    private function registerServices(): void {
        $c = $this->container;

        // --- Support -------------------------------------------------------
        $c->singleton( Crypto::class, static fn() => new Crypto() );
        $c->singleton( Settings::class, static fn() => new Settings() );
        $c->singleton( Secrets::class, static fn( Container $c ) => new Secrets( $c->get( Crypto::class ) ) );
        $c->singleton( Logs::class, static fn() => new Logs() );
        $c->singleton( Logger::class, static fn( Container $c ) => new Logger( $c->get( Logs::class ), $c->get( Settings::class ) ) );
        $c->singleton( RobotsTxt::class, static fn( Container $c ) => new RobotsTxt( $c->get( Logger::class ) ) );
        $c->singleton( Client::class, static fn( Container $c ) => new Client( $c->get( Settings::class ), $c->get( Logger::class ), $c->get( RobotsTxt::class ) ) );
        $c->singleton( Locks::class, static fn() => new Locks() );
        $c->singleton( Limits::class, static fn( Container $c ) => new Limits( $c->get( Settings::class ) ) );
        // The one approval policy (Autopilot switch + the individual outreach / submission settings).
        $c->singleton( Autopilot::class, static fn( Container $c ) => new Autopilot( $c->get( Settings::class ), $c->get( Secrets::class ), $c->get( AuditLog::class ) ) );

        // --- Database ------------------------------------------------------
        $c->singleton( Schema::class, static fn() => new Schema() );
        $c->singleton( Backup::class, static fn( Container $c ) => new Backup( $c->get( Schema::class ), $c->get( Logger::class ) ) );
        $c->singleton( Migrator::class, static fn( Container $c ) => new Migrator( $c->get( Schema::class ), $c->get( Backup::class ), $c->get( Logger::class ), $c->get( AuditLog::class ) ) );

        // --- Repositories --------------------------------------------------
        foreach ( [
            Prospects::class,
            Opportunities::class,
            Submissions::class,
            Contacts::class,
            Campaigns::class,
            OutreachMessages::class,
            Backlinks::class,
            Suppression::class,
            Notifications::class,
            AuditLog::class,
            Jobs::class,
            DomainMetrics::class,
            SearchLog::class,
        ] as $repo ) {
            $c->singleton( $repo, static fn() => new $repo() );
        }

        // --- Analysis ------------------------------------------------------
        $c->singleton( Taxonomy::class, static fn( Container $c ) => new Taxonomy( $c->get( Settings::class ) ) );
        $c->singleton( PageAnalyzer::class, static fn() => new PageAnalyzer() );
        $c->singleton( FormDetector::class, static fn() => new FormDetector() );
        $c->singleton( ContactExtractor::class, static fn( Container $c ) => new ContactExtractor( $c->get( Suppression::class ) ) );
        $c->singleton( RelevanceScorer::class, static fn( Container $c ) => new RelevanceScorer( $c->get( Taxonomy::class ) ) );
        $c->singleton( RiskAnalyzer::class, static fn( Container $c ) => new RiskAnalyzer( $c->get( Taxonomy::class ) ) );
        $c->singleton( SubmissionPageFinder::class, static fn( Container $c ) => new SubmissionPageFinder( $c->get( Client::class ), $c->get( PageAnalyzer::class ), $c->get( FormDetector::class ), $c->get( Logger::class ) ) );
        $c->singleton( SiteProfiler::class, static fn( Container $c ) => new SiteProfiler( $c->get( Client::class ), $c->get( PageAnalyzer::class ), $c->get( ContactExtractor::class ), $c->get( FormDetector::class ), $c->get( Rdap::class ), $c->get( Logger::class ), $c->get( Settings::class ) ) );

        // --- Integrations --------------------------------------------------
        $c->singleton( Rdap::class, static fn( Container $c ) => new Rdap( $c->get( Client::class ) ) );
        $c->singleton( ProviderRegistry::class, static fn( Container $c ) => new ProviderRegistry( $c ) );
        $c->singleton( ServiceAccount::class, static fn( Container $c ) => new ServiceAccount( $c->get( Secrets::class ), $c->get( Client::class ), $c->get( Logger::class ) ) );
        $c->singleton( SearchConsole::class, static fn( Container $c ) => new SearchConsole( $c->get( ServiceAccount::class ), $c->get( Client::class ), $c->get( Settings::class ), $c->get( Logger::class ) ) );
        $c->singleton( Analytics4::class, static fn( Container $c ) => new Analytics4( $c->get( ServiceAccount::class ), $c->get( Client::class ), $c->get( Settings::class ), $c->get( Logger::class ) ) );
        $c->singleton( SiteKitBridge::class, static fn( Container $c ) => new SiteKitBridge( $c->get( Logger::class ) ) );
        $c->singleton( MetricsAggregator::class, static fn( Container $c ) => new MetricsAggregator( $c->get( ProviderRegistry::class ), $c->get( DomainMetrics::class ), $c->get( Settings::class ), $c->get( Logger::class ), $c->get( Client::class ) ) );
        $c->singleton( AiWriter::class, static fn( Container $c ) => new AiWriter( $c->get( Secrets::class ), $c->get( Settings::class ), $c->get( Client::class ), $c->get( Logger::class ) ) );

        // --- Scoring -------------------------------------------------------
        $c->singleton( ScoringEngine::class, static fn( Container $c ) => new ScoringEngine( $c->get( Settings::class ) ) );

        // --- Content -------------------------------------------------------
        $c->singleton( FactsRegistry::class, static fn( Container $c ) => new FactsRegistry( $c->get( Settings::class ) ) );
        $c->singleton( ClaimsFilter::class, static fn( Container $c ) => new ClaimsFilter( $c->get( FactsRegistry::class ) ) );
        $c->singleton( LinkableAssets::class, static fn( Container $c ) => new LinkableAssets( $c->get( Settings::class ), $c->get( Taxonomy::class ) ) );
        $c->singleton( ContentGenerator::class, static fn( Container $c ) => new ContentGenerator( $c->get( FactsRegistry::class ), $c->get( ClaimsFilter::class ), $c->get( Settings::class ), $c->get( AiWriter::class ), $c->get( Logger::class ) ) );

        // --- Compliance ----------------------------------------------------
        // Storefront preflight. Submission\Policy and Outreach\OutreachEngine resolve it lazily (and fail closed when
        // it cannot be built), so a broken audit never stops those services from being constructed.
        $c->singleton( PositioningGate::class, static fn( Container $c ) => new PositioningGate( $c->get( Settings::class ), $c->get( Logger::class ), $c->get( ClaimsFilter::class ), $c->get( AuditLog::class ) ) );

        // --- Discovery -----------------------------------------------------
        $c->singleton( DomainFilter::class, static fn( Container $c ) => new DomainFilter( $c->get( Settings::class ), $c->get( Suppression::class ) ) );
        $c->singleton( Classifier::class, static fn( Container $c ) => new Classifier( $c->get( Taxonomy::class ) ) );
        $c->singleton( QueryGenerator::class, static fn( Container $c ) => new QueryGenerator( $c->get( Settings::class ), $c->get( SearchLog::class ) ) );
        $c->singleton( PackSeeds::class, static fn() => new PackSeeds() );
        $c->singleton( ProspectIntake::class, static fn( Container $c ) => new ProspectIntake( $c->get( Prospects::class ), $c->get( Opportunities::class ), $c->get( DomainFilter::class ), $c->get( Classifier::class ), $c->get( AuditLog::class ), $c->get( Logger::class ) ) );
        $c->singleton( CompetitorBacklinkSource::class, static fn( Container $c ) => new CompetitorBacklinkSource( $c->get( ProviderRegistry::class ), $c->get( Settings::class ), $c->get( Logger::class ), $c->get( Limits::class ), $c->get( Taxonomy::class ), $c->get( AuditLog::class ) ) );
        $c->singleton( MentionFinder::class, static fn( Container $c ) => new MentionFinder( $c->get( ProviderRegistry::class ), $c->get( Client::class ), $c->get( PageAnalyzer::class ), $c->get( Settings::class ), $c->get( ProspectIntake::class ), $c->get( SearchLog::class ), $c->get( Logger::class ) ) );
        $c->singleton( BrokenLinkFinder::class, static fn( Container $c ) => new BrokenLinkFinder( $c->get( Client::class ), $c->get( PageAnalyzer::class ), $c->get( Taxonomy::class ), $c->get( LinkableAssets::class ), $c->get( Opportunities::class ), $c->get( Logger::class ) ) );
        $c->singleton( DiscoveryEngine::class, static fn( Container $c ) => new DiscoveryEngine( $c->get( ProviderRegistry::class ), $c->get( QueryGenerator::class ), $c->get( PackSeeds::class ), $c->get( ProspectIntake::class ), $c->get( CompetitorBacklinkSource::class ), $c->get( SearchLog::class ), $c->get( Limits::class ), $c->get( Settings::class ), $c->get( Logger::class ) ) );
        $c->singleton( CatalogImporter::class, static fn( Container $c ) => new CatalogImporter( $c->get( ProspectIntake::class ), $c->get( Prospects::class ), $c->get( Opportunities::class ), $c->get( Submissions::class ), $c->get( ClaimsFilter::class ), $c->get( AuditLog::class ), $c->get( Logger::class ), $c->get( ContentGenerator::class ), $c->get( Locks::class ) ) );

        // --- Submission ----------------------------------------------------
        $c->singleton( PackageBuilder::class, static fn( Container $c ) => new PackageBuilder( $c->get( Settings::class ), $c->get( ContentGenerator::class ), $c->get( FactsRegistry::class ), $c->get( LinkableAssets::class ) ) );
        $c->singleton( Policy::class, static fn( Container $c ) => new Policy( $c->get( Settings::class ), $c->get( RobotsTxt::class ), $c->get( Limits::class ), $c->get( Submissions::class ) ) );
        $c->singleton( AdapterRegistry::class, static fn( Container $c ) => new AdapterRegistry( $c ) );
        $c->singleton( ListingVerifier::class, static fn( Container $c ) => new ListingVerifier( $c->get( Client::class ), $c->get( PageAnalyzer::class ), $c->get( ProviderRegistry::class ), $c->get( Submissions::class ), $c->get( Backlinks::class ), $c->get( Prospects::class ), $c->get( Settings::class ), $c->get( Notifier::class ), $c->get( AuditLog::class ), $c->get( Logger::class ) ) );
        $c->singleton( SubmissionEngine::class, static fn( Container $c ) => new SubmissionEngine( $c->get( Submissions::class ), $c->get( Prospects::class ), $c->get( Opportunities::class ), $c->get( PackageBuilder::class ), $c->get( Policy::class ), $c->get( AdapterRegistry::class ), $c->get( SubmissionPageFinder::class ), $c->get( Limits::class ), $c->get( AuditLog::class ), $c->get( Notifier::class ), $c->get( Logger::class ) ) );

        // --- Outreach ------------------------------------------------------
        $c->singleton( Templates::class, static fn() => new Templates() );
        $c->singleton( Personalizer::class, static fn( Container $c ) => new Personalizer( $c->get( LinkableAssets::class ), $c->get( FactsRegistry::class ), $c->get( Settings::class ) ) );
        $c->singleton( Compliance::class, static fn( Container $c ) => new Compliance( $c->get( Settings::class ), $c->get( Suppression::class ), $c->get( OutreachMessages::class ), $c->get( ClaimsFilter::class ) ) );
        $c->singleton( Mailer::class, static fn( Container $c ) => new Mailer( $c->get( Settings::class ), $c->get( Logger::class ) ) );
        $c->singleton( Unsubscribe::class, static fn( Container $c ) => new Unsubscribe( $c->get( OutreachMessages::class ), $c->get( Contacts::class ), $c->get( Suppression::class ), $c->get( AuditLog::class ) ) );
        $c->singleton( InboundWebhook::class, static fn( Container $c ) => new InboundWebhook( $c->get( Secrets::class ), $c->get( OutreachMessages::class ), $c->get( Contacts::class ), $c->get( Notifier::class ), $c->get( AuditLog::class ), $c->get( Logger::class ) ) );
        $c->singleton( FollowUps::class, static fn( Container $c ) => new FollowUps( $c->get( OutreachMessages::class ), $c->get( Campaigns::class ), $c->get( Settings::class ), $c->get( Templates::class ), $c->get( Personalizer::class ), $c->get( Autopilot::class ) ) );
        $c->singleton( OutreachEngine::class, static fn( Container $c ) => new OutreachEngine( $c->get( Campaigns::class ), $c->get( OutreachMessages::class ), $c->get( Contacts::class ), $c->get( Prospects::class ), $c->get( Opportunities::class ), $c->get( Templates::class ), $c->get( Personalizer::class ), $c->get( Compliance::class ), $c->get( Mailer::class ), $c->get( ContentGenerator::class ), $c->get( Limits::class ), $c->get( AuditLog::class ), $c->get( Notifier::class ), $c->get( Settings::class ), $c->get( Logger::class ), autopilot: $c->get( Autopilot::class ) ) );
        $c->singleton( CampaignFiller::class, static fn( Container $c ) => new CampaignFiller( $c ) );

        // --- Monitoring ----------------------------------------------------
        $c->singleton( Notifier::class, static fn( Container $c ) => new Notifier( $c->get( Notifications::class ), $c->get( Settings::class ), $c->get( Logger::class ) ) );
        $c->singleton( BacklinkMonitor::class, static fn( Container $c ) => new BacklinkMonitor( $c->get( Backlinks::class ), $c->get( Client::class ), $c->get( PageAnalyzer::class ), $c->get( Settings::class ), $c->get( Notifier::class ), $c->get( AuditLog::class ), $c->get( Logger::class ) ) );
        $c->singleton( BacklinkImporter::class, static fn( Container $c ) => new BacklinkImporter( $c->get( Backlinks::class ), $c->get( Prospects::class ), $c->get( ProviderRegistry::class ), $c->get( Analytics4::class ), $c->get( Settings::class ), $c->get( Notifier::class ), $c->get( AuditLog::class ), $c->get( Logger::class ) ) );

        // --- Automation ----------------------------------------------------
        $c->singleton( Audit::class, static fn( Container $c ) => new Audit( $c->get( AuditLog::class ) ) );
        // Server pressure: background work pauses while OPcache restarts, a backup runs, the plugin was just updated,
        // or the server is busy or low on memory (ServerResources: read only while a pause is decided).
        $c->singleton( Opcache::class, static fn() => new Opcache() );
        $c->singleton( ServerResources::class, static fn() => new ServerResources() );
        $c->singleton( LoadGuard::class, static fn( Container $c ) => new LoadGuard( $c->get( Opcache::class ), $c->get( ServerResources::class ) ) );
        $c->singleton( JobRunner::class, static fn( Container $c ) => new JobRunner( $c, $c->get( Jobs::class ), $c->get( Locks::class ), $c->get( Settings::class ), $c->get( Logger::class ), $c->get( AuditLog::class ) ) );
        $c->singleton( Pipeline::class, static fn( Container $c ) => new Pipeline( $c->get( Jobs::class ), $c->get( JobRunner::class ), $c->get( Settings::class ), $c->get( Limits::class ), $c->get( Logger::class ), $c->get( LoadGuard::class ) ) );
        $c->singleton( Scheduler::class, static fn( Container $c ) => new Scheduler( $c->get( Pipeline::class ), $c->get( Settings::class ), $c->get( Logger::class ), $c->get( LoadGuard::class ) ) );

        // --- On-site SEO ---------------------------------------------------
        // The audit and the link suggestions only read content; links are applied by an admin's Apply click, or under
        // Autopilot / seo.auto_apply_links after RenderCheck confirmed the page shows the words.
        $c->singleton( SiteAuditor::class, static fn( Container $c ) => new SiteAuditor( $c->get( Settings::class ), $c->get( Logger::class ), $c->get( Locks::class ), $c->get( Client::class ) ) );
        $c->singleton( IndexNow::class, static fn( Container $c ) => new IndexNow( $c->get( Settings::class ), $c->get( Logger::class ), $c->get( Client::class ), $c->get( Locks::class ), $c->get( Audit::class ) ) );
        $c->singleton( RenderCheck::class, static fn( Container $c ) => new RenderCheck( $c->get( Settings::class ), $c->get( Logger::class ), $c->get( Client::class ) ) );
        $c->singleton( InternalLinker::class, static fn( Container $c ) => new InternalLinker( $c->get( Settings::class ), $c->get( Logger::class ), $c->get( Locks::class ), $c->get( Audit::class ), $c->get( RenderCheck::class ) ) );

        // --- Growth run ----------------------------------------------------
        // Resolves JobRunner, Scheduler and the other services lazily (Pipeline -> GrowthRun -> Scheduler -> Pipeline).
        $c->singleton( GrowthRun::class, static fn( Container $c ) => new GrowthRun( $c ) );

        // --- Admin ---------------------------------------------------------
        $c->singleton( Capabilities::class, static fn() => new Capabilities() );
        $c->singleton( Menu::class, static fn( Container $c ) => new Menu( $c->get( Settings::class ), $c->get( Notifications::class ) ) );
        $c->singleton( Assets::class, static fn( Container $c ) => new Assets( $c->get( Settings::class ) ) );
        $c->singleton( Environment::class, static fn() => new Environment() );
        $c->singleton( Notices::class, static fn( Container $c ) => new Notices( $c->get( Settings::class ), $c->get( ProviderRegistry::class ), $c->get( Compliance::class ), $c->get( Environment::class ) ) );
        $c->singleton( SiteHealth::class, static fn( Container $c ) => new SiteHealth( $c->get( Opcache::class ), $c->get( ServerResources::class ) ) );
        $c->singleton( RestApi::class, static fn( Container $c ) => new RestApi( $c ) );
    }
}
