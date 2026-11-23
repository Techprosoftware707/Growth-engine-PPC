<?php
/**
 * Plugin Name:       Compat Fixture (clean)
 * Description:       Code that bin/compat-api.php must accept for WordPress 6.5 and WooCommerce 8.0.
 * Requires at least: 6.5
 * WC requires at least: 8.0
 */

declare(strict_types=1);

namespace CompatFixture;

require __DIR__ . '/includes/checks.php';
