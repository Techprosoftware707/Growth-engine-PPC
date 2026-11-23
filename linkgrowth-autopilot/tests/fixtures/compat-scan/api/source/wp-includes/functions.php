<?php
/**
 * Stand-in for the WordPress functions the compat-api fixtures call (tokenized, never loaded).
 */

/**
 * @since 1.5.0
 */
function get_option( $option, $default_value = false ) {}

/**
 * @since 0.71
 */
function get_bloginfo( $show = '', $filter = 'raw' ) {}

/**
 * @since 5.2.0
 */
function is_wp_version_compatible( $required ) {}

/**
 * @since 6.6.0
 */
function is_utf8_charset( $blog_charset = null ) {}

/**
 * @since 0.71
 */
function wptexturize( $text, $reset = false ) {}

/**
 * @since 2.8.0
 */
function esc_html__( $text, $domain = 'default' ) {}

/**
 * @since 2.6.0
 */
function plugins_url( $path = '', $plugin = '' ) {}

/**
 * @since 6.5.0
 */
function wp_register_script_module( $id, $src, $deps = array(), $version = false ) {}

/**
 * @since 2.1.0
 * @deprecated 6.2.0 Use WP_Query.
 */
function get_page_by_title( $page_title, $output = OBJECT, $post_type = 'page' ) {}
