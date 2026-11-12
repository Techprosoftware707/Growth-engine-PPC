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

        wp_enqueue_style( self::HANDLE, PPCLG_URL . 'assets/css/admin.css', [], $this->fileVersion( 'assets/css/admin.css', $version ) );

        if ( function_exists( 'wp_enqueue_script_module' ) ) {
            // app.js loads its siblings with relative specifiers ('./api.js', '../ui.js', './pages/x.js'), which
            // the browser resolves without the entry's ?ver= and which caches (Cloudflare, browser) would keep
            // serving stale after an update. Registering each sibling under its unversioned URL puts that URL
            // into the WordPress import map, which remaps every relative import to its versioned URL.
            $deps = [];
            if ( function_exists( 'wp_register_script_module' ) ) {
                foreach ( $this->moduleVersions( $version ) as $url => $moduleVersion ) {
                    wp_register_script_module( $url, $url, [], $moduleVersion );
                    $deps[] = [ 'id' => $url, 'import' => 'dynamic' ];
                }
            }
            wp_enqueue_script_module( self::HANDLE, PPCLG_URL . 'assets/js/app.js', $deps, $this->fileVersion( 'assets/js/app.js', $version ) );
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

    /**
     * Every ES module the admin app imports (all of assets/js except the app.js entry), keyed by its
     * unversioned URL — the exact URL a relative import resolves to — with its cache-busting version.
     *
     * @return array<string,string>
     */
    public function moduleVersions( string $version ): array {
        $base = rtrim( str_replace( '\\', '/', PPCLG_DIR ), '/' ) . '/';
        $out  = [];
        foreach ( [ 'assets/js/*.js', 'assets/js/pages/*.js' ] as $pattern ) {
            $files = glob( $base . $pattern );
            foreach ( is_array( $files ) ? $files : [] as $file ) {
                $relative = substr( str_replace( '\\', '/', $file ), strlen( $base ) );
                if ( 'assets/js/app.js' === $relative ) {
                    continue;
                }
                $out[ PPCLG_URL . $relative ] = $this->fileVersion( $relative, $version );
            }
        }
        ksort( $out );
        return $out;
    }

    /** Version for one asset: the plugin version plus the file's modification time, so any changed file gets a new URL. */
    private function fileVersion( string $relative, string $version ): string {
        $path  = rtrim( PPCLG_DIR, '/\\' ) . '/' . ltrim( $relative, '/' );
        $mtime = is_file( $path ) ? filemtime( $path ) : false;
        return false !== $mtime ? $version . '.' . $mtime : $version;
    }

    /** Cache-busting version: plugin version, with a timestamp suffix while debugging. */
    private function version(): string {
        $version = defined( 'PPCLG_VERSION' ) ? (string) PPCLG_VERSION : '1.0.0';
        $debug   = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) || 'debug' === (string) $this->settings->get( 'advanced.log_level', 'info' );
        return $debug ? $version . '.' . time() : $version;
    }
}
