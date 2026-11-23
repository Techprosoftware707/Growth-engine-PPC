<?php
/**
 * Stand-in for the WooCommerce functions and hooks the compat-api fixtures use (tokenized, never loaded).
 */

/**
 * @since 2.6.0
 */
function wc_get_orders( $args ) {}

/**
 * @since 9.5.0
 */
function wc_get_customer_geolocation( $fallback = array() ) {}

/**
 * Filter Brands settings.
 *
 * @since 9.4.0
 */
$fields = apply_filters( 'woocommerce_brands_settings_fields', array() );
