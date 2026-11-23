<?php
/**
 * Stand-in for the core hooks the compat-api fixtures use (tokenized, never loaded).
 */

/**
 * Fires after WordPress has finished loading but before any headers are sent.
 *
 * @since 1.5.0
 */
do_action( 'init' );

/**
 * Filters the post content.
 *
 * @since 0.71
 */
$content = apply_filters( 'the_content', $content );

/**
 * Filters max bytes threshold to trigger warning in Site Health.
 *
 * @since 6.6.0
 */
$limit = apply_filters( 'site_status_autoloaded_options_size_limit', 800000 );
