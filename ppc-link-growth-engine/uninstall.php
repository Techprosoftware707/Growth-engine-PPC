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

foreach ( [
    'ppclg_settings',
    'ppclg_secrets',
    'ppclg_db_version',
    'ppclg_activated_at',
    'ppclg_counters',
    'ppclg_backup_dir',
    'ppclg_facts',
    'ppclg_provider_quota',
    'ppclg_search_quota',
    'ppclg_last_tick',
] as $ppclg_option ) {
    delete_option( $ppclg_option );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ppclg\\_lock\\_%' OR option_name LIKE '\\_transient\\_ppclg\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ppclg\\_%'" );

$ppclg_role = get_role( 'administrator' );
if ( $ppclg_role ) {
    $ppclg_role->remove_cap( 'ppclg_manage' );
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( 'ppclg_tick' );
}
wp_clear_scheduled_hook( 'ppclg_tick' );
