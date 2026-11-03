<?php
/**
 * Uninstall: removes plugin data only when the administrator opted in
 * (Settings → Advanced → "Delete all data on uninstall").
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$ppclg_settings = get_option( 'ppclg_settings', [] );
$ppclg_delete   = is_array( $ppclg_settings ) && ! empty( $ppclg_settings['advanced']['delete_on_uninstall'] );

if ( ! $ppclg_delete ) {
    return;
}

global $wpdb;

$ppclg_tables = [
    'ppclg_prospects',
    'ppclg_opportunities',
    'ppclg_submissions',
    'ppclg_contacts',
    'ppclg_campaigns',
    'ppclg_outreach_messages',
    'ppclg_backlinks',
    'ppclg_suppression',
    'ppclg_notifications',
    'ppclg_audit_log',
    'ppclg_logs',
    'ppclg_jobs',
    'ppclg_domain_metrics',
    'ppclg_search_log',
];

foreach ( $ppclg_tables as $ppclg_table ) {
    $ppclg_full = $wpdb->prefix . $ppclg_table;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( "DROP TABLE IF EXISTS `{$ppclg_full}`" );
}

// Every option the plugin writes uses the ppclg_ prefix (settings, secrets, counters, facts, custom seeds,
// stage runs/results, schedule interval, digest/import timestamps, provider errors, locks); delete them all.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$ppclg_option_names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'ppclg\\_%'" );
foreach ( (array) $ppclg_option_names as $ppclg_option ) {
    delete_option( (string) $ppclg_option );
}

// Transients (caps sync, schedule check, overview caches, rate limits, activation redirect).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ppclg\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ppclg\\_%' OR option_name LIKE '\\_site\\_transient\\_ppclg\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_ppclg\\_%'" );

// Per-user dismissed admin notices.
delete_metadata( 'user', 0, 'ppclg_dismissed_notices', '', true );
wp_cache_flush();

$ppclg_role = get_role( 'administrator' );
if ( $ppclg_role ) {
    $ppclg_role->remove_cap( 'ppclg_manage' );
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( 'ppclg_tick' );
}
wp_clear_scheduled_hook( 'ppclg_tick' );
