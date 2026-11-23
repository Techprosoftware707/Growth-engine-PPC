<?php
declare(strict_types=1);

namespace CompatFixture;

function helper(): string
{
    return strtolower(get_option('blogname', ''));
}

// WordPress 6.6.0 function without a guard (the acceptance fixture "an unguarded function with @since 6.6").
$utf8 = is_utf8_charset('UTF-8'); // expect: fail

if (function_exists('is_utf8_charset')) {
    $utf8 = is_utf8_charset('UTF-8'); // expect: guarded
}
$utf8 = version_compare(get_bloginfo('version'), '6.6', '>=') ? is_utf8_charset('UTF-8') : true; // expect: guarded
$utf8 = is_wp_version_compatible('6.6') && is_utf8_charset('UTF-8'); // expect: guarded
if (version_compare($GLOBALS['wp_version'], '6.5', '>=')) {
    $utf8 = is_utf8_charset('UTF-8'); // expect: fail
}
// A guard branch that declares an unrelated function does not guard the code after it.
function early(): void
{
    if (!function_exists('is_utf8_charset')) {
        function unrelated_helper(): void {}
    }
    $utf8 = is_utf8_charset('UTF-8'); // expect: fail
}

// WordPress 6.7.0 class and static method.
\WP_Block_Metadata_Registry::register_collection(__DIR__, __DIR__ . '/manifest.php'); // expect: fail
if (class_exists('WP_Block_Metadata_Registry')) {
    \WP_Block_Metadata_Registry::register_collection(__DIR__, __DIR__ . '/manifest.php'); // expect: guarded
}

// WordPress 6.6.0 filter.
add_filter('site_status_autoloaded_options_size_limit', static fn(int $limit): int => $limit); // expect: fail

// WooCommerce 9.5.0 function and 9.4.0 hook.
$geo = wc_get_customer_geolocation(); // expect: fail
$geo = defined('WC_VERSION') && version_compare(WC_VERSION, '9.5.0', '>=') ? wc_get_customer_geolocation() : []; // expect: guarded
add_filter('woocommerce_brands_settings_fields', static fn(array $f): array => $f); // expect: fail

// Action Scheduler 3.9.3 function (the floor is the release bundled with WooCommerce 8.0).
$ok = as_supports('ensure_recurring_actions_hook'); // expect: fail
if (function_exists('as_supports')) {
    $ok = as_supports('ensure_recurring_actions_hook'); // expect: guarded
}

// Old symbols, PHP and own functions: never reported.
$orders = wc_get_orders(['limit' => 1]);
$len = strlen(helper());
add_action('init', static function (): void {
});

// Deprecated and unknown symbols are listed but do not fail.
$page = get_page_by_title('About'); // expect: deprecated
$x = my_vendor_api_call(); // expect: unknown
