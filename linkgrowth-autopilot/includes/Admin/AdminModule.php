<?php
/**
 * Module `admin` (DESIGN.md §3.2 Dashboard row): the menu, the SPA shell and its assets, notices, capabilities and the
 * REST API registrar; the Overview, Settings and Logs pages and their controllers. The other pages and controllers
 * are declared by the module of their area.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Admin;

use LinkGrowth\Autopilot\Admin\Rest\LogsController;
use LinkGrowth\Autopilot\Admin\Rest\OverviewController;
use LinkGrowth\Autopilot\Admin\Rest\RestApi;
use LinkGrowth\Autopilot\Admin\Rest\SettingsController;
use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Core\AbstractModule;
use LinkGrowth\Autopilot\Core\Environment;
use LinkGrowth\Autopilot\Integrations\ProviderRegistry;
use LinkGrowth\Autopilot\Outreach\Compliance;
use LinkGrowth\Autopilot\Plugin;
use LinkGrowth\Autopilot\Repositories\Notifications;
use LinkGrowth\Autopilot\Support\Settings;

final class AdminModule extends AbstractModule {

    public const ID = 'admin';

    public function id(): string {
        return self::ID;
    }

    public function services( Container $c ): void {
        $c->singleton( Capabilities::class, static fn() => new Capabilities() );
        $c->singleton( Menu::class, static fn( Container $c ) => new Menu( $c->get( Settings::class ), $c->get( Notifications::class ) ) );
        $c->singleton( Assets::class, static fn( Container $c ) => new Assets( $c->get( Settings::class ) ) );
        $c->singleton( Notices::class, static fn( Container $c ) => new Notices( $c->get( Settings::class ), $c->get( ProviderRegistry::class ), $c->get( Compliance::class ), $c->get( Environment::class ) ) );
        $c->singleton( RestApi::class, static fn( Container $c ) => new RestApi( $c ) );
    }

    public function boot( Plugin $plugin ): void {
        if ( $plugin->isMaintenanceRequest() ) {
            // Capabilities are (re)synced so role changes by other plugins do not strip ours (at most hourly).
            add_action( 'init', [ $plugin, 'ensureCapabilities' ], 2 );
        }

        // REST API (admin + public endpoints): registered for requests to the plugin's own routes only.
        add_action( 'rest_api_init', [ $plugin, 'registerRest' ] );

        if ( Plugin::adminRequest() ) {
            // The same hooks Menu::register(), Assets::register() and Notices::register() add, built on first use. Not
            // for a logged-out visitor's admin-ajax.php call: none of them serves it (the dismiss action is wp_ajax_
            // only), and admin_init would read the activation-redirect transient and build the menu on every call.
            add_action( 'admin_menu', $plugin->lazy( Menu::class, 'addMenu' ) );
            add_action( 'admin_init', [ $plugin, 'maybeRedirect' ] );
            add_filter( 'admin_body_class', $plugin->lazy( Menu::class, 'bodyClass' ) );
            // WooCommerce sends users without edit_posts out of wp-admin; the submitter role needs its one screen.
            add_filter( 'woocommerce_prevent_admin_access', $plugin->lazy( Menu::class, 'allowSubmitterAdmin' ) );
            add_action( 'admin_enqueue_scripts', $plugin->lazy( Assets::class, 'enqueue' ) );
            add_action( 'admin_notices', $plugin->lazy( Notices::class, 'render' ) );
            add_action( 'wp_ajax_' . Plugin::NOTICE_DISMISS_ACTION, $plugin->lazy( Notices::class, 'dismiss' ) );
        }
    }

    public function restControllers(): array {
        return [ OverviewController::class, SettingsController::class, LogsController::class ];
    }

    public function adminPages(): array {
        return [
            'overview' => [ 'label' => __( 'Overview', 'linkgrowth-autopilot' ), 'icon' => 'home', 'module' => 'pages/overview.js', 'cap' => Capabilities::CAP, 'order' => 10 ],
            'settings' => [ 'label' => __( 'Settings', 'linkgrowth-autopilot' ), 'icon' => 'settings', 'module' => 'pages/settings.js', 'cap' => Capabilities::CAP, 'order' => 80 ],
            'logs'     => [ 'label' => __( 'Logs', 'linkgrowth-autopilot' ), 'icon' => 'list', 'module' => 'pages/logs.js', 'cap' => Capabilities::CAP, 'order' => 90 ],
        ];
    }
}
