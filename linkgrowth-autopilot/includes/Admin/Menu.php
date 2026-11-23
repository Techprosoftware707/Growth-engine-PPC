<?php
/**
 * Admin menu, page container and front-end configuration injection; the pages and Settings tabs come from the modules
 * (DESIGN.md §3.1, §10.1).
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Admin;

use LinkGrowth\Autopilot\Plugin;
use LinkGrowth\Autopilot\Repositories\Notifications;
use LinkGrowth\Autopilot\Support\Settings;

final class Menu {

    /** Top-level menu slug (also the overview page). */
    public const SLUG = 'lga';

    /** Transient set on activation; triggers a one-time redirect to the overview. */
    public const REDIRECT_TRANSIENT = 'lga_activation_redirect';

    /** Filter: the admin page definitions (page key => {label, icon, module, cap, order}) after the modules'. */
    public const FILTER_PAGES = 'lga_admin_pages';

    public function __construct( private Settings $settings, private Notifications $notifications ) {}

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'addMenu' ] );
        add_action( 'admin_init', [ $this, 'maybeRedirect' ] );
        add_filter( 'admin_body_class', [ $this, 'bodyClass' ] );
        // WooCommerce sends users without edit_posts out of wp-admin; the submitter role needs its one screen.
        add_filter( 'woocommerce_prevent_admin_access', [ $this, 'allowSubmitterAdmin' ] );
    }

    /** @return array<string,string> page key => label, in navigation order (pageDefinitions()) */
    public static function pages(): array {
        return array_map( static fn( array $def ): string => $def['label'], self::pageDefinitions() );
    }

    /**
     * The SPA pages every module declares (Module::adminPages()), then the `lga_admin_pages` filter, in navigation
     * order (`order`, then declaration order). A page key taken by an earlier module and a definition without a label
     * are left out.
     *
     * @return array<string,array{label:string,icon:string,module:string,cap:string,order:int}>
     */
    public static function pageDefinitions(): array {
        $pages = [];
        foreach ( Plugin::instance()->modules() as $module ) {
            foreach ( (array) $module->adminPages() as $key => $def ) {
                if ( ! isset( $pages[ $key ] ) ) {
                    $pages[ $key ] = $def;
                }
            }
        }
        /**
         * Filters the SPA pages (DESIGN.md §3.1, §10.1).
         *
         * @param array<string,array{label:string,icon:string,module:string,cap:string,order:int}> $pages
         */
        $pages = apply_filters( self::FILTER_PAGES, $pages );
        $out   = [];
        foreach ( is_array( $pages ) ? $pages : [] as $key => $def ) {
            $key = (string) $key;
            if ( 1 !== preg_match( '/^[a-z0-9_-]+$/', $key ) || ! is_array( $def ) || '' === trim( (string) ( $def['label'] ?? '' ) ) ) {
                continue;
            }
            $out[ $key ] = [
                'label'  => (string) $def['label'],
                'icon'   => (string) ( $def['icon'] ?? '' ),
                'module' => (string) ( $def['module'] ?? '' ),
                'cap'    => '' !== (string) ( $def['cap'] ?? '' ) ? (string) $def['cap'] : Capabilities::CAP,
                'order'  => (int) ( $def['order'] ?? 100 ),
            ];
        }
        uasort( $out, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] ); // stable since PHP 8.0
        return $out;
    }

    /**
     * The Settings tabs every module declares (Module::settingsTabs()), in tab order (`order`, then declaration
     * order); a tab key taken by an earlier module is left out.
     *
     * @return array<string,array{label:string,module:string,order:int}>
     */
    public static function settingsTabs(): array {
        $out = [];
        foreach ( Plugin::instance()->modules() as $module ) {
            foreach ( (array) $module->settingsTabs() as $key => $def ) {
                $key = (string) $key;
                if ( isset( $out[ $key ] ) || '' === $key || ! is_array( $def ) || '' === trim( (string) ( $def['label'] ?? '' ) ) ) {
                    continue;
                }
                $out[ $key ] = [
                    'label'  => (string) $def['label'],
                    'module' => (string) ( $def['module'] ?? '' ),
                    'order'  => (int) ( $def['order'] ?? 100 ),
                ];
            }
        }
        uasort( $out, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );
        return $out;
    }

    /**
     * Pages a user sees: with full access, every page whose capability they have (a page of the plugin's own
     * capability needs full access, Capabilities::userCan()); only the owner-task queue for the submitter role.
     *
     * @return array<string,string> page key => label
     */
    public static function pagesFor( bool $submitOnly ): array {
        if ( $submitOnly ) {
            return [ 'submissions' => __( 'Owner tasks', 'linkgrowth-autopilot' ) ];
        }
        $out = [];
        foreach ( self::pageDefinitions() as $key => $def ) {
            if ( Capabilities::CAP === $def['cap'] ? Capabilities::userCan() : current_user_can( $def['cap'] ) ) {
                $out[ $key ] = $def['label'];
            }
        }
        return $out;
    }

    /** Admin menu slug for a page key: "lga" for the overview, "lga-{page}" otherwise. */
    public static function slugFor( string $page ): string {
        return 'overview' === $page ? self::SLUG : self::SLUG . '-' . $page;
    }

    /** Page key for an admin menu slug, or '' when the slug is not one of ours. */
    public static function pageFromSlug( string $slug ): string {
        if ( self::SLUG === $slug ) {
            return 'overview';
        }
        if ( str_starts_with( $slug, self::SLUG . '-' ) ) {
            $page = substr( $slug, strlen( self::SLUG ) + 1 );
            if ( array_key_exists( $page, self::pageDefinitions() ) ) {
                return $page;
            }
        }
        return '';
    }

    /** Page key of the current request ('' outside the plugin screens). */
    public static function currentPage(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing value.
        $slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
        return '' === $slug ? '' : self::pageFromSlug( $slug );
    }

    public static function isPluginScreen(): bool {
        return is_admin() && '' !== self::currentPage();
    }

    public static function url( string $page = 'overview' ): string {
        return admin_url( 'admin.php?page=' . self::slugFor( $page ) );
    }

    public function addMenu(): void {
        if ( Capabilities::submitOnly() ) {
            // The submitter role gets one top-level item: the owner-task queue. No other plugin page is
            // registered for them, so opening another plugin URL directly is refused by WordPress.
            $label = __( 'Link Submissions', 'linkgrowth-autopilot' );
            add_menu_page( $label, $label, Capabilities::SUBMIT_CAP, self::slugFor( 'submissions' ), [ $this, 'render' ], 'dashicons-admin-links', 58 );
            return;
        }
        $label  = __( 'LinkGrowth', 'linkgrowth-autopilot' );
        $unread = $this->unreadCount();
        $title  = $label;
        if ( $unread > 0 ) {
            $title .= sprintf(
                ' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
                $unread,
                number_format_i18n( $unread ),
                esc_html(
                    sprintf(
                        /* translators: %s: number of unread notifications */
                        _n( '%s unread notification', '%s unread notifications', $unread, 'linkgrowth-autopilot' ),
                        number_format_i18n( $unread )
                    )
                )
            );
        }

        add_menu_page( $label, $title, Capabilities::CAP, self::SLUG, [ $this, 'render' ], 'dashicons-admin-links', 58 );

        foreach ( self::pageDefinitions() as $page => $def ) {
            $pageLabel = $def['label'];
            add_submenu_page(
                self::SLUG,
                sprintf( '%1$s – %2$s', $pageLabel, $label ),
                $pageLabel,
                $def['cap'],
                self::slugFor( $page ),
                [ $this, 'render' ]
            );
        }
    }

    /** One-time redirect to the overview after activation (never during bulk activation, AJAX, cron or REST). */
    public function maybeRedirect(): void {
        if ( ! get_transient( self::REDIRECT_TRANSIENT ) ) {
            return;
        }
        delete_transient( self::REDIRECT_TRANSIENT );
        if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_network_admin() ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only.
        if ( isset( $_GET['activate-multi'] ) ) {
            return;
        }
        if ( ! Capabilities::userCan() ) {
            return;
        }
        wp_safe_redirect( self::url( 'overview' ) );
        exit;
    }

    public function bodyClass( string $classes ): string {
        if ( self::isPluginScreen() ) {
            $classes .= ' lga-admin lga-page-' . self::currentPage();
        }
        return $classes;
    }

    /**
     * `woocommerce_prevent_admin_access` filter: lets a submitter-only user reach wp-admin (their role has
     * only `read`, which WooCommerce otherwise redirects to My Account). Everyone else is unchanged.
     */
    public function allowSubmitterAdmin( mixed $prevent ): mixed {
        return Capabilities::submitOnly() ? false : $prevent;
    }

    /** Renders the application container and the JSON configuration consumed by assets/js/app.js. */
    public function render(): void {
        $submitOnly = Capabilities::submitOnly();
        if ( ! Capabilities::userCan() && ! $submitOnly ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'linkgrowth-autopilot' ), 403 );
        }
        $page = self::currentPage();
        if ( '' === $page ) {
            $page = 'overview';
        }
        if ( $submitOnly && ! array_key_exists( $page, self::pagesFor( true ) ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'linkgrowth-autopilot' ), 403 );
        }
        $json = wp_json_encode( $this->config( $page ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) ) {
            $json = '{}';
        }
        echo '<div class="wrap lga-wrap"><div id="lga-app" data-page="' . esc_attr( $page ) . '"></div></div>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with HEX flags; safe inside a JSON script element.
        echo '<script type="application/json" id="lga-config">' . $json . '</script>';
    }

    /**
     * Configuration passed to the JavaScript application.
     *
     * @return array<string,mixed>
     */
    public function config( string $page ): array {
        $user       = wp_get_current_user();
        $submitOnly = Capabilities::submitOnly();
        $pages      = self::pagesFor( $submitOnly );
        $urls       = [];
        $slugs      = [];
        foreach ( $pages as $key => $label ) {
            $urls[ $key ]  = esc_url_raw( self::url( $key ) );
            $slugs[ $key ] = self::slugFor( $key );
        }
        if ( $submitOnly ) {
            // Only what the owner-task queue needs: no settings, automation state or notification counts.
            return [
                'restRoot'            => esc_url_raw( rest_url( 'lga/v1' ) ),
                'nonce'               => wp_create_nonce( 'wp_rest' ),
                'page'                => 'submissions',
                'siteDomain'          => $this->settings->siteDomain(),
                'brand'               => $this->settings->brandName(),
                'productName'         => __( Plugin::NAME, 'linkgrowth-autopilot' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
                'pages'               => $pages,
                'version'             => defined( 'LGA_VERSION' ) ? LGA_VERSION : '',
                'user'                => [
                    'name' => (string) $user->display_name,
                    'id'   => (int) $user->ID,
                ],
                'adminUrl'            => esc_url_raw( admin_url() ),
                'pluginUrl'           => esc_url_raw( defined( 'LGA_URL' ) ? LGA_URL : '' ),
                'automationEnabled'   => false,
                'capabilities'        => [
                    'manage'         => false,
                    'manage_options' => false,
                    'submit'         => true,
                    'submitOnly'     => true,
                ],
                'dashboardUrl'        => esc_url_raw( self::url( 'submissions' ) ),
                'pageUrls'            => $urls,
                'menuSlugs'           => $slugs,
                'unreadNotifications' => 0,
                'homeUrl'             => esc_url_raw( home_url( '/' ) ),
                'locale'              => get_user_locale(),
                'timezone'            => wp_timezone_string(),
            ];
        }
        return [
            'restRoot'            => esc_url_raw( rest_url( 'lga/v1' ) ),
            'nonce'               => wp_create_nonce( 'wp_rest' ),
            'page'                => $page,
            'siteDomain'          => $this->settings->siteDomain(),
            'brand'               => $this->settings->brandName(),
                'productName'         => __( Plugin::NAME, 'linkgrowth-autopilot' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
            'pages'               => $pages,
            // Settings tabs from the modules (DESIGN.md §10.1); the Settings page still renders its own tabs until the
            // SPA builds them from this list.
            'settingsTabs'        => self::settingsTabs(),
            'version'             => defined( 'LGA_VERSION' ) ? LGA_VERSION : '',
            'user'                => [
                'name' => (string) $user->display_name,
                'id'   => (int) $user->ID,
            ],
            'adminUrl'            => esc_url_raw( admin_url() ),
            'pluginUrl'           => esc_url_raw( defined( 'LGA_URL' ) ? LGA_URL : '' ),
            'automationEnabled'   => (bool) $this->settings->get( 'automation.enabled', false ),
            'capabilities'        => [
                'manage'         => current_user_can( Capabilities::CAP ),
                'manage_options' => current_user_can( 'manage_options' ),
                'submit'         => true,
                'submitOnly'     => false,
            ],
            // Settings → "Hand owner tasks to a staff member": where to create a user with the submitter role.
            'newUserUrl'          => esc_url_raw( admin_url( 'user-new.php' ) ),
            'submitterRole'       => [
                'slug' => Capabilities::SUBMITTER_ROLE,
                'name' => Capabilities::submitterRoleName(),
            ],
            'dashboardUrl'        => esc_url_raw( self::url( 'overview' ) ),
            'pageUrls'            => $urls,
            'menuSlugs'           => $slugs,
            'unreadNotifications' => $this->unreadCount(),
            'homeUrl'             => esc_url_raw( home_url( '/' ) ),
            'locale'              => get_user_locale(),
            'timezone'            => wp_timezone_string(),
            'ajaxUrl'             => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
        ];
    }

    private function unreadCount(): int {
        try {
            return $this->notifications->unreadCount();
        } catch ( \Throwable $e ) {
            return 0;
        }
    }
}
