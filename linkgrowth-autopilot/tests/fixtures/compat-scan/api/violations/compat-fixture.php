<?php
/**
 * Plugin Name:       Compat Fixture
 * Description:       Seeded WordPress 6.5 / WooCommerce 8.0 floor violations for bin/compat-api.php.
 * Requires at least: 6.5
 * WC requires at least: 8.0
 *
 * Each reported line ends with an "expect:" comment (fail, guarded, deprecated or unknown); no other line may be
 * reported. Never loaded by WordPress or the test suite.
 */

declare(strict_types=1);

namespace CompatFixture;

require __DIR__ . '/includes/checks.php';
