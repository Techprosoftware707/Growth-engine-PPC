<?php
/**
 * Uninstall: removes plugin data only when the administrator opted in
 * (Settings → Advanced → "Delete all data on uninstall").
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Permissions are removed on every uninstall (data only when opted in below): the "LinkGrowth Submitter"
// role and both plugin capabilities (lga_manage, lga_submit_listings) on every role. Users who had the
// submitter role keep their account; the role comes back if the plugin is installed again.
if ( ! class_exists( \LinkGrowth\Autopilot\Admin\Capabilities::class, false ) ) {
    require_once __DIR__ . '/includes/Admin/Capabilities.php';
}
( new \LinkGrowth\Autopilot\Admin\Capabilities() )->remove();

$lga_settings = get_option( 'lga_settings', [] );
$lga_delete   = is_array( $lga_settings ) && ! empty( $lga_settings['advanced']['delete_on_uninstall'] );

if ( ! $lga_delete ) {
    return;
}

global $wpdb;

$lga_tables = [
    'lga_prospects',
    'lga_opportunities',
    'lga_submissions',
    'lga_contacts',
    'lga_campaigns',
    'lga_outreach_messages',
    'lga_backlinks',
    'lga_suppression',
    'lga_notifications',
    'lga_audit_log',
    'lga_logs',
    'lga_jobs',
    'lga_domain_metrics',
    'lga_search_log',
];

foreach ( $lga_tables as $lga_table ) {
    $lga_full = $wpdb->prefix . $lga_table;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( "DROP TABLE IF EXISTS `{$lga_full}`" );
}

// Pre-migration backups (gzipped SQL dumps of every plugin table, including contacts, outreach messages and
// the suppression list) live in a random directory under uploads. Remove them before lga_backup_dir (which
// names that directory) is deleted below; leftover lga-backups-* directories are removed as well.
if ( ! class_exists( \LinkGrowth\Autopilot\Database\Backup::class, false ) ) {
    require_once __DIR__ . '/includes/Database/Backup.php';
}
$lga_uploads = wp_upload_dir( null, false );
\LinkGrowth\Autopilot\Database\Backup::removeAll( (string) ( $lga_uploads['basedir'] ?? '' ), (string) get_option( 'lga_backup_dir', '' ) );

// Every option the plugin writes uses the lga_ prefix (settings, secrets, counters, facts, custom seeds,
// stage runs/results, schedule interval, digest/import timestamps, provider errors, locks); delete them all.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$lga_option_names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'lga\\_%'" );
foreach ( (array) $lga_option_names as $lga_option ) {
    delete_option( (string) $lga_option );
}

// Transients (caps sync, schedule check, overview caches, rate limits, activation redirect): each one through the
// transient API, so its cached copy goes too, then any leftover rows. The whole object cache is not flushed (1.2.4):
// on a Redis-backed store that would throw away every cached object of WooCommerce and the other plugins at once.
// With a persistent object cache the plugin's transients live only there and expire on their own; nothing reads
// them once the plugin is gone.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$lga_transients = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_lga\\_%' OR option_name LIKE '\\_site\\_transient\\_lga\\_%'" );
foreach ( (array) $lga_transients as $lga_transient ) {
    $lga_transient = (string) $lga_transient;
    if ( str_starts_with( $lga_transient, '_site_transient_' ) ) {
        delete_site_transient( substr( $lga_transient, strlen( '_site_transient_' ) ) );
    } else {
        delete_transient( substr( $lga_transient, strlen( '_transient_' ) ) );
    }
}
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_lga\\_%' OR option_name LIKE '\\_transient\\_timeout\\_lga\\_%' OR option_name LIKE '\\_site\\_transient\\_lga\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_lga\\_%'" );
wp_cache_delete( 'alloptions', 'options' );
wp_cache_delete( 'notoptions', 'options' );

// Per-user dismissed admin notices.
delete_metadata( 'user', 0, 'lga_dismissed_notices', '', true );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( 'lga_tick' );
}
wp_clear_scheduled_hook( 'lga_tick' );
