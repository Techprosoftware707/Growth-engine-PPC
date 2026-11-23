<?php
/**
 * Stand-in for wp-includes/plugin.php: the hook API (tokenized by bin/compat-api.php, never loaded).
 */

/**
 * @since 0.71
 */
function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {}

/**
 * @since 0.71
 */
function apply_filters( $hook_name, $value, ...$args ) {}

/**
 * @since 1.2.0
 */
function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {}

/**
 * @since 1.2.0
 */
function do_action( $hook_name, ...$arg ) {}
