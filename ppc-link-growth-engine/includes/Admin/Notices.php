<?php
/**
 * Dismissible admin notices for configuration gaps (plugin screens only).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Admin;

use PPC\LinkGrowth\Integrations\ProviderRegistry;
use PPC\LinkGrowth\Support\Crypto;
use PPC\LinkGrowth\Support\Settings;

final class Notices {

    public const ACTION = 'ppclg_dismiss_notice';
    public const NONCE  = 'ppclg_notice';
    public const META   = 'ppclg_dismissed_notices';

    /** Days a dismissed notice stays hidden for that user. */
    private const DISMISS_DAYS = 7;

    public function __construct( private Settings $settings, private ProviderRegistry $providers ) {}

    public function register(): void {
        add_action( 'admin_notices', [ $this, 'render' ] );
        add_action( 'wp_ajax_' . self::ACTION, [ $this, 'dismiss' ] );
    }

    /**
     * Current notices (not yet filtered by dismissal).
     *
     * @return array<int,array{id:string,level:string,message:string,action_url:string,action_label:string}>
     */
    public function notices(): array {
        $out = [];

        try {
            if ( ! (bool) $this->settings->get( 'automation.enabled', false ) ) {
                $out[] = [
                    'id'           => 'automation_off',
                    'level'        => 'info',
                    'message'      => __( 'Automation is switched off. Discovery, evaluation, packaging and monitoring only run when the master switch is on; manual actions still work.', 'ppc-link-growth-engine' ),
                    'action_url'   => Menu::url( 'automation' ),
                    'action_label' => __( 'Open Automation', 'ppc-link-growth-engine' ),
                ];
            }
        } catch ( \Throwable $e ) {
            unset( $e );
        }

        try {
            $status          = $this->providers->status();
            $searchConfigured = false;
            $errors           = [];
            foreach ( $status as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                if ( 'search' === (string) ( $entry['kind'] ?? '' ) && ! empty( $entry['configured'] ) ) {
                    $searchConfigured = true;
                }
                if ( ! empty( $entry['configured'] ) && ! empty( $entry['enabled'] ) && '' !== (string) ( $entry['error'] ?? '' ) && '' !== (string) ( $entry['error_at'] ?? '' ) ) {
                    $errors[] = (string) ( $entry['label'] ?? 'Provider' ) . ': ' . (string) $entry['error'];
                }
            }
            if ( ! $searchConfigured ) {
                $out[] = [
                    'id'           => 'no_search_provider',
                    'level'        => 'warning',
                    'message'      => __( 'No search provider is configured, so discovery cannot run search queries. Add a Google Programmable Search, Brave Search or Serper API key.', 'ppc-link-growth-engine' ),
                    'action_url'   => Menu::url( 'settings' ),
                    'action_label' => __( 'Open Settings', 'ppc-link-growth-engine' ),
                ];
            }
            if ( ! empty( $errors ) ) {
                $out[] = [
                    'id'           => 'provider_errors_' . substr( md5( implode( '|', $errors ) ), 0, 8 ),
                    'level'        => 'warning',
                    'message'      => sprintf(
                        /* translators: %s: list of provider errors */
                        __( 'Integration problems reported: %s', 'ppc-link-growth-engine' ),
                        implode( ' · ', array_slice( $errors, 0, 4 ) )
                    ),
                    'action_url'   => Menu::url( 'settings' ),
                    'action_label' => __( 'Review integrations', 'ppc-link-growth-engine' ),
                ];
            }
        } catch ( \Throwable $e ) {
            unset( $e );
        }

        try {
            $name   = trim( (string) $this->settings->get( 'outreach.sender_name', '' ) );
            $email  = trim( (string) $this->settings->get( 'outreach.sender_email', '' ) );
            $postal = trim( (string) $this->settings->get( 'outreach.postal_address', '' ) );
            if ( '' === $name || '' === $email || ! is_email( $email ) || '' === $postal ) {
                $out[] = [
                    'id'           => 'sender_identity',
                    'level'        => 'info',
                    'message'      => __( 'Outreach sender identity is incomplete (sender name, sender email and postal address are required by CASL / CAN-SPAM). Outreach emails cannot be sent until it is complete.', 'ppc-link-growth-engine' ),
                    'action_url'   => Menu::url( 'settings' ),
                    'action_label' => __( 'Complete sender identity', 'ppc-link-growth-engine' ),
                ];
            }
        } catch ( \Throwable $e ) {
            unset( $e );
        }

        try {
            if ( ! ( new Crypto() )->hasStrongKeys() ) {
                $out[] = [
                    'id'           => 'weak_salts',
                    'level'        => 'error',
                    'message'      => __( 'AUTH_KEY / SECURE_AUTH_KEY in wp-config.php are missing or default. Stored API keys cannot be protected until unique salts are defined.', 'ppc-link-growth-engine' ),
                    'action_url'   => 'https://api.wordpress.org/secret-key/1.1/salt/',
                    'action_label' => __( 'Generate salts', 'ppc-link-growth-engine' ),
                ];
            }
        } catch ( \Throwable $e ) {
            unset( $e );
        }

        /**
         * Filters the admin notices shown on the plugin screens.
         *
         * @param array<int,array<string,string>> $out
         */
        $filtered = apply_filters( 'ppclg_admin_notices', $out );
        return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_array' ) ) : $out;
    }

    public function render(): void {
        if ( ! Menu::isPluginScreen() || ! Capabilities::userCan() ) {
            return;
        }
        $dismissed = $this->dismissed();
        $shown     = 0;
        foreach ( $this->notices() as $notice ) {
            $id = sanitize_key( (string) ( $notice['id'] ?? '' ) );
            if ( '' === $id || ( isset( $dismissed[ $id ] ) && (int) $dismissed[ $id ] > time() ) ) {
                continue;
            }
            $level = in_array( (string) ( $notice['level'] ?? '' ), [ 'info', 'warning', 'error', 'success' ], true ) ? (string) $notice['level'] : 'info';
            $action = '';
            if ( ! empty( $notice['action_url'] ) && ! empty( $notice['action_label'] ) ) {
                $action = ' <a href="' . esc_url( (string) $notice['action_url'] ) . '">' . esc_html( (string) $notice['action_label'] ) . '</a>';
            }
            printf(
                '<div class="notice notice-%1$s is-dismissible ppclg-notice" data-ppclg-notice="%2$s"><p><strong>%3$s</strong> %4$s%5$s</p></div>',
                esc_attr( $level ),
                esc_attr( $id ),
                esc_html__( 'Link Growth:', 'ppc-link-growth-engine' ),
                esc_html( (string) ( $notice['message'] ?? '' ) ),
                $action // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
            );
            $shown++;
        }
        if ( $shown > 0 ) {
            $this->printScript();
        }
    }

    /** AJAX: remembers a dismissal for the current user. */
    public function dismiss(): void {
        check_ajax_referer( self::NONCE );
        if ( ! Capabilities::userCan() ) {
            wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
        }
        $id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( (string) $_POST['id'] ) ) : '';
        if ( '' === $id ) {
            wp_send_json_error( [ 'message' => 'missing id' ], 400 );
        }
        $list = $this->dismissed();
        $now  = time();
        foreach ( $list as $k => $expires ) {
            if ( (int) $expires <= $now ) {
                unset( $list[ $k ] );
            }
        }
        $list[ $id ] = $now + self::DISMISS_DAYS * DAY_IN_SECONDS;
        update_user_meta( get_current_user_id(), self::META, $list );
        wp_send_json_success( [ 'id' => $id ] );
    }

    /** @return array<string,int> notice id => expiry timestamp */
    private function dismissed(): array {
        $meta = get_user_meta( get_current_user_id(), self::META, true );
        return is_array( $meta ) ? $meta : [];
    }

    private function printScript(): void {
        $config = wp_json_encode(
            [
                'url'    => admin_url( 'admin-ajax.php' ),
                'action' => self::ACTION,
                'nonce'  => wp_create_nonce( self::NONCE ),
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ( ! is_string( $config ) ) {
            return;
        }
        $js = '(function(){var c=' . $config . ';document.addEventListener("click",function(ev){var t=ev.target;if(!t||!t.closest){return;}var b=t.closest(".notice-dismiss");if(!b){return;}var n=b.closest(".ppclg-notice");if(!n){return;}var id=n.getAttribute("data-ppclg-notice");if(!id){return;}var fd=new FormData();fd.append("action",c.action);fd.append("id",id);fd.append("_ajax_nonce",c.nonce);try{fetch(c.url,{method:"POST",credentials:"same-origin",body:fd}).catch(function(){});}catch(e){}});})();';
        if ( function_exists( 'wp_print_inline_script_tag' ) ) {
            wp_print_inline_script_tag( $js );
            return;
        }
        echo '<script>' . $js . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static script with JSON-encoded config.
    }
}
