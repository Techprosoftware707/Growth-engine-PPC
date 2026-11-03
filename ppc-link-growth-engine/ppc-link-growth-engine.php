<?php
/**
 * Plugin Name:       PPC Link Growth Engine
 * Plugin URI:        https://purepeptidescanada.com/
 * Description:       Automated, compliance-first backlink discovery, qualification, submission packaging, outreach drafting and backlink monitoring for Pure Peptides Canada.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Pure Peptides Canada
 * Author URI:        https://purepeptidescanada.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ppc-link-growth-engine
 * Domain Path:       /languages
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( defined( 'PPCLG_VERSION' ) ) {
    // Another copy of the plugin is already loaded; do not load twice.
    return;
}

define( 'PPCLG_VERSION', '1.0.0' );
define( 'PPCLG_DB_VERSION', '1.0.0' );
define( 'PPCLG_FILE', __FILE__ );
define( 'PPCLG_DIR', plugin_dir_path( __FILE__ ) );
define( 'PPCLG_URL', plugin_dir_url( __FILE__ ) );
define( 'PPCLG_BASENAME', plugin_basename( __FILE__ ) );
define( 'PPCLG_MIN_PHP', '8.1' );
define( 'PPCLG_MIN_WP', '6.5' );

/**
 * Environment guard: refuse to boot (but never fatal) on unsupported runtimes.
 */
if ( version_compare( PHP_VERSION, PPCLG_MIN_PHP, '<' ) ) {
    add_action(
        'admin_notices',
        static function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html(
                sprintf(
                    /* translators: 1: required PHP version, 2: current PHP version */
                    __( 'PPC Link Growth Engine requires PHP %1$s or newer. This server runs PHP %2$s. The plugin has not been loaded.', 'ppc-link-growth-engine' ),
                    PPCLG_MIN_PHP,
                    PHP_VERSION
                )
            );
            echo '</p></div>';
        }
    );
    return;
}

require_once PPCLG_DIR . 'includes/Autoloader.php';

\PPC\LinkGrowth\Autoloader::register( PPCLG_DIR . 'includes', 'PPC\\LinkGrowth\\' );

register_activation_hook( __FILE__, [ \PPC\LinkGrowth\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \PPC\LinkGrowth\Deactivator::class, 'deactivate' ] );

/**
 * Returns the plugin singleton.
 */
function ppclg(): \PPC\LinkGrowth\Plugin {
    return \PPC\LinkGrowth\Plugin::instance();
}

add_action( 'plugins_loaded', static function (): void {
    ppclg()->boot();
}, 5 );
