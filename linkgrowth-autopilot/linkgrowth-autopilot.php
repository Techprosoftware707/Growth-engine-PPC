<?php
/**
 * Plugin Name:       LinkGrowth Autopilot
 * Description:       Autonomous, policy-bound backlink discovery, qualification, acquisition workflows and backlink monitoring for any WordPress site.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Tested up to:      7.1
 * Requires PHP:      8.1
 * Author:            LinkGrowth Autopilot contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       linkgrowth-autopilot
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   11.3
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( defined( 'LGA_VERSION' ) ) {
    // Another copy of the plugin is already loaded; do not load twice.
    return;
}

define( 'LGA_VERSION', '1.0.0' );
define( 'LGA_DB_VERSION', '0.9.0' ); // Fork baseline schema; becomes 1.0.0 with the 1.0.0 schema (DESIGN.md §4.5).
define( 'LGA_FILE', __FILE__ );
define( 'LGA_DIR', plugin_dir_path( __FILE__ ) );
define( 'LGA_URL', plugin_dir_url( __FILE__ ) );
define( 'LGA_BASENAME', plugin_basename( __FILE__ ) );
define( 'LGA_MIN_PHP', '8.1' );
define( 'LGA_MIN_WP', '6.5' );

/**
 * Environment guard: refuse to boot (but never fatal) on unsupported runtimes.
 */
if ( version_compare( PHP_VERSION, LGA_MIN_PHP, '<' ) ) {
    add_action(
        'admin_notices',
        static function (): void {
            echo '<div class="notice notice-error"><p>';
            echo esc_html(
                sprintf(
                    /* translators: 1: required PHP version, 2: current PHP version */
                    __( 'LinkGrowth Autopilot requires PHP %1$s or newer. This server runs PHP %2$s. The plugin has not been loaded.', 'linkgrowth-autopilot' ),
                    LGA_MIN_PHP,
                    PHP_VERSION
                )
            );
            echo '</p></div>';
        }
    );
    return;
}

require_once LGA_DIR . 'includes/Autoloader.php';

\LinkGrowth\Autopilot\Autoloader::register( LGA_DIR . 'includes', 'LinkGrowth\\Autopilot\\' );

register_activation_hook( __FILE__, [ \LinkGrowth\Autopilot\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \LinkGrowth\Autopilot\Deactivator::class, 'deactivate' ] );

/**
 * Returns the plugin singleton.
 */
function lga(): \LinkGrowth\Autopilot\Plugin {
    return \LinkGrowth\Autopilot\Plugin::instance();
}

add_action( 'plugins_loaded', static function (): void {
    lga()->boot();
}, 5 );
