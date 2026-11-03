<?php
/**
 * Admin stylesheet and ES-module application loader (plugin screens only).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Admin;

use PPC\LinkGrowth\Support\Settings;

final class Assets {

    public const HANDLE = 'ppclg-admin';

    public function __construct( private Settings $settings ) {}

    public function register(): void {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    /** @param string $hook Current admin page hook (unused: our own screen detection is more reliable). */
    public function enqueue( string $hook = '' ): void {
        unset( $hook );
        if ( ! Menu::isPluginScreen() ) {
            return;
        }
        $version = $this->version();

        wp_enqueue_style( self::HANDLE, PPCLG_URL . 'assets/css/admin.css', [], $version );

        if ( function_exists( 'wp_enqueue_script_module' ) ) {
            wp_enqueue_script_module( self::HANDLE, PPCLG_URL . 'assets/js/app.js', [], $version );
            return;
        }

        // WordPress < 6.5: classic enqueue with a type="module" tag.
        wp_enqueue_script( self::HANDLE, PPCLG_URL . 'assets/js/app.js', [], $version, true );
        add_filter( 'script_loader_tag', [ $this, 'moduleTag' ], 10, 3 );
    }

    /** Turns the classic script tag for our handle into an ES module tag. */
    public function moduleTag( string $tag, string $handle, string $src ): string {
        unset( $src );
        if ( self::HANDLE !== $handle || str_contains( $tag, 'type="module"' ) ) {
            return $tag;
        }
        if ( preg_match( '/\stype=(["\'])[^"\']*\1/i', $tag ) ) {
            return preg_replace( '/\stype=(["\'])[^"\']*\1/i', ' type="module"', $tag, 1 ) ?? $tag;
        }
        return preg_replace( '/<script\b/i', '<script type="module"', $tag, 1 ) ?? $tag;
    }

    /** Cache-busting version: plugin version, with a timestamp suffix while debugging. */
    private function version(): string {
        $version = defined( 'PPCLG_VERSION' ) ? (string) PPCLG_VERSION : '1.0.0';
        $debug   = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) || 'debug' === (string) $this->settings->get( 'advanced.log_level', 'info' );
        return $debug ? $version . '.' . time() : $version;
    }
}
