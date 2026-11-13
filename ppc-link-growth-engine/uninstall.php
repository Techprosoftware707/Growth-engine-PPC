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

// Permissions are removed on every uninstall (data only when opted in below): the "Link Growth Submitter"
// role and both plugin capabilities (ppclg_manage, ppclg_submit_listings) on every role. Users who had the
// submitter role keep their account; the role comes back if the plugin is installed again.
if ( ! class_exists( \PPC\LinkGrowth\Admin\Capabilities::class, false ) ) {
    require_once __DIR__ . '/includes/Admin/Capabilities.php';
}
( new \PPC\LinkGrowth\Admin\Capabilities() )->remove();

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

// Pre-migration backups (gzipped SQL dumps of every plugin table, including contacts, outreach messages and
// the suppression list) live in a random directory under uploads. Remove them before ppclg_backup_dir (which
// names that directory) is deleted below; leftover ppclg-backups-* directories are removed as well.
if ( ! class_exists( \PPC\LinkGrowth\Database\Backup::class, false ) ) {
    require_once __DIR__ . '/includes/Database/Backup.php';
}
$ppclg_uploads = wp_upload_dir( null, false );
\PPC\LinkGrowth\Database\Backup::removeAll( (string) ( $ppclg_uploads['basedir'] ?? '' ), (string) get_option( 'ppclg_backup_dir', '' ) );

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

if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( 'ppclg_tick' );
}
wp_clear_scheduled_hook( 'ppclg_tick' );
