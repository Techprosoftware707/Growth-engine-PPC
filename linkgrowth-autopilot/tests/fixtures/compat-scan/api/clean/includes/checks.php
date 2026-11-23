<?php
declare(strict_types=1);

namespace CompatFixture;

use WP_Error;

function boot(): void
{
    add_action('init', [__NAMESPACE__ . '\\Plugin', 'init']);
    add_filter('the_content', 'wptexturize');
    if (!function_exists('is_utf8_charset')) {
        return;
    }
    $utf8 = is_utf8_charset('UTF-8');
}

final class Plugin
{
    public static function init(): void
    {
        $error = new WP_Error('code', esc_html__('Message', 'compat-fixture'));
        $orders = function_exists('wc_get_orders') ? wc_get_orders(['limit' => 1]) : [];
        wp_register_script_module('compat-fixture', plugins_url('app.js', __FILE__)); // @since 6.5.0, the floor
        if (is_wp_version_compatible('6.7')) {
            \WP_Block_Metadata_Registry::register_collection(__DIR__, __DIR__ . '/manifest.php');
        }
        if (defined('WC_VERSION') && version_compare(WC_VERSION, '9.5', '>=')) {
            $geo = wc_get_customer_geolocation();
        }
    }
}
