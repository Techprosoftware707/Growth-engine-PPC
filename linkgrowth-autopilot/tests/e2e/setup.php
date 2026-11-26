<?php
/**
 * Site preparation for the browser E2E suite (tests/e2e/run.mjs, DESIGN.md §12.5). run.mjs copies the site's SQLite
 * database aside first and puts it back when the run ends, so everything this script writes lasts for one run only.
 *
 * Usage: LGA_WP_PATH=/path/to/wordpress LGA_E2E_ADMIN_PASS=… LGA_E2E_SUBMITTER_PASS=… php tests/e2e/setup.php
 *
 * 1. Resets the plugin state exactly as the integration suite's first step does (plugin tables dropped, lga_ options
 *    and transients deleted, the plugin deactivated and activated again), so every E2E run starts from the same clean
 *    state whatever ran on the site before, and the data the walk sees is only what run.mjs seeds through REST.
 * 2. Ends the 10-minute quiet window an activation starts (Automation\LoadGuard), as the integration suite does, so
 *    the dashboard is not shown in its "just updated" pause.
 * 3. Creates (or resets) two users with the passwords from the environment: lga-e2e-admin (administrator) and
 *    lga-e2e-submitter (the LinkGrowth Submitter role, owner tasks only).
 * 4. Creates eight owner tasks from the verified opportunity database (fictional directories), the only records not
 *    seeded through REST: no lga/v1 route creates them (they come from the catalog import; no catalog ships).
 * 5. Backs up one plugin table (Database\Backup, as before a migration), so Settings › Advanced lists a download.
 * Prints one JSON object: {ok, version, users{admin,submitter}, tables, home, seed{ownerTaskA,…, backupFile}}. Exit 1 on
 * any failure.
 */

declare(strict_types=1);

$wpPath = getenv( 'LGA_WP_PATH' ) ?: '';
if ( '' === $wpPath || ! is_file( rtrim( $wpPath, '/' ) . '/wp-load.php' ) ) {
    fwrite( STDERR, "Set LGA_WP_PATH to a WordPress root containing wp-load.php\n" );
    exit( 2 );
}
$adminPass     = (string) getenv( 'LGA_E2E_ADMIN_PASS' );
$submitterPass = (string) getenv( 'LGA_E2E_SUBMITTER_PASS' );
if ( strlen( $adminPass ) < 12 || strlen( $submitterPass ) < 12 ) {
    fwrite( STDERR, "Set LGA_E2E_ADMIN_PASS and LGA_E2E_SUBMITTER_PASS (12 characters or more)\n" );
    exit( 2 );
}
$_SERVER['HTTP_HOST']      = 'localhost:8793';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
require rtrim( $wpPath, '/' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

/** @param array<string,mixed> $out */
function lga_e2e_setup_fail( string $message, array $out = [] ): void {
    fwrite( STDERR, $message . "\n" );
    echo wp_json_encode( [ 'ok' => false, 'error' => $message ] + $out ) . "\n";
    exit( 1 );
}

global $wpdb;
$plugin = 'linkgrowth-autopilot/linkgrowth-autopilot.php';
if ( ! is_file( WP_PLUGIN_DIR . '/' . $plugin ) ) {
    lga_e2e_setup_fail( 'The plugin is not installed on this site: ' . WP_PLUGIN_DIR . '/' . $plugin );
}

// ----- 1. Clean plugin state (the integration suite's "Activation and schema" reset) ------------------------------
if ( is_plugin_active( $plugin ) ) {
    deactivate_plugins( $plugin );
}
if ( ! class_exists( \LinkGrowth\Autopilot\Database\Schema::class ) ) {
    require_once WP_PLUGIN_DIR . '/linkgrowth-autopilot/includes/Autoloader.php';
    \LinkGrowth\Autopilot\Autoloader::register( WP_PLUGIN_DIR . '/linkgrowth-autopilot/includes', 'LinkGrowth\\Autopilot\\' );
}
foreach ( \LinkGrowth\Autopilot\Database\Schema::names() as $table ) {
    $wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'lga_' . $table . '`' ); // phpcs:ignore
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lga\_%' OR option_name LIKE '\_transient\_lga\_%' OR option_name LIKE '\_transient\_timeout\_lga\_%'" ); // phpcs:ignore
wp_cache_flush();
$activated = activate_plugin( $plugin );
if ( is_wp_error( $activated ) ) {
    lga_e2e_setup_fail( 'Could not activate the plugin: ' . $activated->get_error_message() );
}
if ( ! function_exists( 'lga' ) ) {
    lga_e2e_setup_fail( 'The plugin is active but lga() is not defined.' );
}
$schema = lga()->get( \LinkGrowth\Autopilot\Database\Schema::class );
$tables = count( $schema->existingTables() );
if ( count( \LinkGrowth\Autopilot\Database\Schema::names() ) !== $tables ) {
    lga_e2e_setup_fail( 'Not every plugin table exists after the activation.', [ 'tables' => $tables ] );
}

// ----- 2. No "just updated" quiet window ---------------------------------------------------------------------------
if ( class_exists( \LinkGrowth\Autopilot\Automation\LoadGuard::class ) ) {
    update_option( 'lga_running_version', [ 'version' => LGA_VERSION, 'since' => time() - HOUR_IN_SECONDS, 'from' => '' ], true );
    lga()->get( \LinkGrowth\Autopilot\Automation\LoadGuard::class )->forget();
}

// ----- 3. The two E2E users ----------------------------------------------------------------------------------------
lga()->get( \LinkGrowth\Autopilot\Admin\Capabilities::class )->ensure( true );
$users = [];
foreach ( [
    'admin'     => [ 'lga-e2e-admin', 'administrator', $adminPass, 'e2e-admin@example.com' ],
    'submitter' => [ 'lga-e2e-submitter', \LinkGrowth\Autopilot\Admin\Capabilities::SUBMITTER_ROLE, $submitterPass, 'e2e-submitter@example.com' ],
] as $key => [ $login, $role, $pass, $email ] ) {
    $existing = get_user_by( 'login', $login );
    if ( $existing ) {
        wp_delete_user( (int) $existing->ID );
    }
    $uid = wp_insert_user( [ 'user_login' => $login, 'user_pass' => $pass, 'user_email' => $email, 'role' => $role, 'display_name' => 'E2E ' . $key ] );
    if ( ! is_int( $uid ) ) {
        lga_e2e_setup_fail( 'Could not create the ' . $key . ' user: ' . ( is_wp_error( $uid ) ? $uid->get_error_message() : 'unknown error' ) );
    }
    // Keep the admin colour scheme and the welcome panels out of the screenshots' way.
    update_user_meta( $uid, 'show_welcome_panel', 0 );
    $users[ $key ] = [ 'id' => $uid, 'login' => $login, 'role' => $role ];
}
if ( ! user_can( $users['admin']['id'], \LinkGrowth\Autopilot\Admin\Capabilities::CAP ) ) {
    lga_e2e_setup_fail( 'The E2E administrator lacks the plugin capability.' );
}
if ( user_can( $users['submitter']['id'], \LinkGrowth\Autopilot\Admin\Capabilities::CAP ) || ! user_can( $users['submitter']['id'], \LinkGrowth\Autopilot\Admin\Capabilities::SUBMIT_CAP ) ) {
    lga_e2e_setup_fail( 'The E2E submitter does not have exactly the owner-task capability.' );
}

// ----- 4. Owner tasks from the verified opportunity database --------------------------------------------------------
// The only records written here instead of through REST: no lga/v1 route creates a verified-database owner task (they
// come from the catalog import, and no catalog ships, DESIGN.md §2.5), and the owner-task page needs them. The rows
// are the shape tests/integration/submitter.php uses, for fictional neutral directories.
$prospects = lga()->get( \LinkGrowth\Autopilot\Repositories\Prospects::class );
$subs      = lga()->get( \LinkGrowth\Autopilot\Repositories\Submissions::class );
$kit       = [
    'business_name'  => 'Northwind Supply Co',
    'website'        => 'https://northwind.test',
    'address'        => '1200 NW Front Ave, Portland, OR 97209',
    'email'          => 'hello@northwind.test',
    'description'    => 'Northwind Supply Co supplies industrial supplies and packaging to workshops and warehouses.',
    'category_rule'  => 'Choose the industrial supplies category.',
    'free'           => 'yes',
    'approval_required' => 'yes',
    'effort'         => '10',
    'risk'           => 'low',
    'data_version'   => 'e2e-1',
];
$ownerTasks = [];
$taskList = [ 'ownerTaskA' => [ 'e2e-owner-a.test', 'Owner Task Directory A', 2, 'Create an account.' . "\n" . 'Add the listing with the kit below.', 'A free account is required.' ] ];
// One task per destructive entry (each marks or rejects its own): four for the administrator, three for the submitter.
foreach ( [ 'Admin1', 'Admin2', 'Admin3', 'Admin4', 'Sub1', 'Sub2', 'Sub3' ] as $n => $suffix ) {
    $taskList[ 'ownerTask' . $suffix ] = [ 'e2e-owner-' . strtolower( $suffix ) . '.test', 'Owner Task Directory ' . $suffix, 2 + ( $n % 2 ), 'Open the form.' . "\n" . 'Submit the listing.', '' ];
}
foreach ( $taskList as $key => [ $host, $platform, $tier, $steps, $requirements ] ) {
    [ $pid ] = $prospects->upsertDiscovered( $host, [ 'name' => $platform ] );
    // Already evaluated and qualified, so the seeded growth run does not evaluate (and reject) the unreachable host,
    // which would keep the task with the owner instead of the submitter.
    $prospects->update( (int) $pid, [ 'status' => 'qualified', 'score' => 72, 'last_evaluated' => gmdate( 'Y-m-d H:i:s' ) ] );
    $package = $kit + [
        'source'            => 'verified_db',
        'item_id'           => 'e2e-' . $host,
        'platform'          => $platform,
        'tier'              => $tier,
        'outreach'          => false,
        'conditional'       => false,
        'steps'             => $steps,
        'requirements'      => $requirements,
        'target_url'        => 'https://' . $host . '/add-listing/',
        'registration_url'  => 'https://' . $host . '/register/',
        'verification_note' => 'Fictional directory of the E2E suite.',
    ];
    $id = $subs->create( (int) $pid, null, 'https://' . $host . '/add-listing/', [
        'status'  => 'needs_manual',
        'method'  => 'manual',
        'payload' => [ 'package' => $package, 'instructions' => [ 'Create an account.', 'Add the listing.' ], 'compliance' => [ 'clean' => true, 'violations' => [] ] ],
    ] );
    if ( ! is_int( $id ) || $id <= 0 ) {
        lga_e2e_setup_fail( 'Could not create the owner task for ' . $host );
    }
    $ownerTasks[ $key ] = $id;
}

// ----- 5. One table backup (the Settings › Advanced download link) -------------------------------------------------
// Backups are made before migrations only; a fresh reset has none. The dump goes into a new uploads/lga-backups-*
// folder, which run.mjs removes again with the restore (the restored database names the site's own folder).
$backup = lga()->get( \LinkGrowth\Autopilot\Database\Backup::class )->dump( [ $wpdb->prefix . 'lga_logs' ], 'e2e' );
if ( null === $backup || ! is_file( $backup ) ) {
    lga_e2e_setup_fail( 'Could not create the table backup the Advanced tab lists.' );
}

echo wp_json_encode( [
    'ok'      => true,
    'version' => LGA_VERSION,
    'users'   => $users,
    'tables'  => $tables,
    'home'    => home_url( '/' ),
    'seed'    => $ownerTasks + [ 'backupFile' => basename( $backup ) ],
] ) . "\n";
