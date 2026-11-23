<?php
/**
 * Integration section (1.2.4): background work never adds load while the server is under pressure. Required from
 * run.php after link-rules.php. Real WordPress + SQLite + WooCommerce's Action Scheduler: the real cron array, the
 * real REST routes and Tools → Site Health's own test list.
 *
 * It reproduces the live case: an UpdraftPlus backup is running, which WordPress shows as a one-off
 * 'updraft_backup_resume' event (args: resumption number, backup nonce) in the cron array. With that event scheduled,
 * a tick claims no job (a queued manual job stays queued, never attempted), enqueues no stage, the status shows
 * "Paused: a backup is running (resumes automatically).", a kick starts nothing, a Grow now step waits instead of
 * failing, and "Run now" still runs the stage with the warning. The pause is logged once. Once the event is removed
 * the next tick runs the waiting job and logs the resume. A leftover event of a finished backup, other backup plugins
 * (filter) and the quiet window after an update are checked too, and the Site Health OPcache test is registered.
 *
 * Observable values are written out (not read from the 1.2.4 classes), so the section also runs against 1.2.3.
 * Everything it schedules is removed at the end.
 *
 * The second step covers the load and memory pauses on this machine's real /proc and /sys, forced and lifted
 * through their filters: a threshold below zero for lga_max_load_per_cpu makes any load count as busy, a share of
 * 1.0 for lga_min_free_memory_ratio makes any memory in use count as short. INF and 0 switch the checks off, so a
 * genuinely busy test machine cannot hold the step. The text must name this machine's own CPU count and memory,
 * read here independently of the plugin.
 */

declare(strict_types=1);

use LinkGrowth\Autopilot\Automation\Pipeline;
use LinkGrowth\Autopilot\Repositories\Jobs;
use LinkGrowth\Autopilot\Repositories\Logs;

IT::step( '1.2.4: background work pauses while a backup runs (the live UpdraftPlus case); Site Health warns about a full OPcache', static function (): void {
    wp_set_current_user( 1 );
    $pipeline = lga()->get( Pipeline::class );
    $jobs     = lga()->get( Jobs::class );
    $logs     = lga()->get( Logs::class );
    $nonce    = 'itbackup0001';
    $hook     = 'updraft_backup_resume';
    $text     = 'Paused: a backup is running (resumes automatically).';
    $settings = lga()->get( \LinkGrowth\Autopilot\Support\Settings::class );
    $settings->set( 'automation.enabled', true );
    wp_clear_scheduled_hook( $hook, [ 1, $nonce ] );
    wp_clear_scheduled_hook( $hook, [ 2, 'itbackup0002' ] );

    $logged = static function ( string $needle ) use ( $logs ): int {
        $n = 0;
        foreach ( $logs->findBy( [ 'channel' => 'automation' ], 'id', 'DESC', 200 ) as $row ) {
            if ( str_contains( (string) $row['message'], $needle ) ) {
                $n++;
            }
        }
        return $n;
    };
    $pausedBefore  = $logged( 'Paused: a backup is running' );
    $resumedBefore = $logged( 'Background work resumed' );
    $kicks         = static function (): int {
        if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
            return count( (array) ( _get_cron_array() ?: [] ) );
        }
        return count( as_get_scheduled_actions( [ 'hook' => 'lga_tick', 'status' => 'pending', 'per_page' => 200 ], 'ids' ) );
    };

    // A manual job waits in the queue (priority 1: the first one any tick would claim).
    $jobs->cancelQueued( 'cleanup' );
    $jobId = (int) $jobs->enqueue( 'cleanup', [ 'manual' => true ], 1 );
    IT::check( $jobId > 0, 'a manual cleanup job is queued' );

    // --- The backup runs -------------------------------------------------------------------------------------
    $scheduled = wp_schedule_single_event( time() + 240, $hook, [ 1, $nonce ] );
    IT::check( true === $scheduled && false !== wp_next_scheduled( $hook, [ 1, $nonce ] ), 'a one-off updraft_backup_resume event is in the cron array' );

    $completedBefore = (int) ( $jobs->statusCounts()[ Jobs::STATUS_COMPLETED ] ?? 0 );
    $tick            = $pipeline->tick();
    IT::check( 'paused' === ( $tick['skipped_reason'] ?? '' ), 'the tick is skipped as paused', (string) wp_json_encode( [ 'skipped' => $tick['skipped_reason'] ?? null, 'run' => $tick['run'] ?? null ] ) );
    IT::check( 'backup' === ( $tick['paused']['code'] ?? null ) && $text === ( $tick['paused']['text'] ?? null ), 'with the reason "a backup is running"', (string) wp_json_encode( $tick['paused'] ?? null ) );
    IT::check( 0 === (int) ( $tick['run']['ran'] ?? -1 ) && [] === ( $tick['enqueued'] ?? null ), 'no job ran and no stage was enqueued' );
    $job = $jobs->find( $jobId );
    IT::check( Jobs::STATUS_QUEUED === ( $job['status'] ?? '' ) && 0 === (int) ( $job['attempts'] ?? -1 ), 'the queued job was not claimed (still queued, 0 attempts)', (string) wp_json_encode( [ $job['status'] ?? null, $job['attempts'] ?? null ] ) );
    IT::check( (int) ( $jobs->statusCounts()[ Jobs::STATUS_COMPLETED ] ?? 0 ) === $completedBefore, 'no job completed during the paused tick' );
    $status = $pipeline->status();
    IT::check( $text === ( $status['paused']['text'] ?? null ), 'the automation status shows "' . $text . '"' );
    $rest = lga_it_rest( 'GET', '/automation/status' );
    IT::check( 200 === $rest->get_status() && 'backup' === ( $rest->get_data()['paused']['code'] ?? null ), 'GET /automation/status carries the pause' );
    $overview = lga_it_rest( 'GET', '/overview' );
    IT::check( 200 === $overview->get_status() && 'backup' === ( $overview->get_data()['automation']['paused']['code'] ?? null ), 'GET /overview carries the pause for the dashboard' );
    IT::check( array_key_exists( 'opcache', (array) $overview->get_data() ), 'GET /overview has the opcache warning field' );

    // A second paused tick logs nothing new.
    $pipeline->tick();
    IT::check( 1 === $logged( 'Paused: a backup is running' ) - $pausedBefore, 'the pause is logged once', (string) ( $logged( 'Paused: a backup is running' ) - $pausedBefore ) );

    // A kick starts nothing.
    $kicksBefore = $kicks();
    $kick        = lga_it_rest( 'POST', '/automation/kick' );
    $kd          = (array) $kick->get_data();
    IT::check( 200 === $kick->get_status() && true === ( $kd['ok'] ?? null ) && false === ( $kd['kicked'] ?? null ) && str_contains( (string) ( $kd['message'] ?? '' ), 'a backup is running' ), 'POST /automation/kick says the work is paused and starts nothing', (string) wp_json_encode( $kd['message'] ?? null ) );
    IT::check( $kicks() === $kicksBefore, 'no extra tick was queued in Action Scheduler', $kicksBefore . ' → ' . $kicks() );

    // Run now still runs the stage you asked for, with the warning.
    $run = lga_it_rest( 'POST', '/automation/run', [ 'stage' => 'cleanup', 'options' => [] ] );
    $rd  = (array) $run->get_data();
    IT::check( 200 === $run->get_status() && true === ( $rd['ok'] ?? null ), 'POST /automation/run still runs a stage' );
    IT::check( str_contains( (string) ( $rd['warning'] ?? '' ), 'Background work is paused: a backup is running.' ), 'and its answer warns that background work is paused', (string) wp_json_encode( $rd['warning'] ?? null ) );
    IT::check( Jobs::STATUS_QUEUED === ( $jobs->find( $jobId )['status'] ?? '' ), 'the queued job is still waiting' );

    // Grow now waits instead of failing.
    $start = lga_it_rest( 'POST', '/growth/start' );
    $sd    = (array) $start->get_data();
    IT::check( 200 === $start->get_status() && 'running' === ( $sd['status'] ?? '' ), 'POST /growth/start starts a run', (string) wp_json_encode( $sd['message'] ?? null ) );
    $step = lga_it_rest( 'POST', '/growth/step' );
    $st   = (array) $step->get_data();
    IT::check( 200 === $step->get_status() && true === ( $st['ok'] ?? null ) && true === ( $st['waiting'] ?? null ), 'POST /growth/step waits (ok, waiting)', (string) wp_json_encode( [ $st['ok'] ?? null, $st['waiting'] ?? null, $st['message'] ?? null ] ) );
    IT::check( $text === ( $st['message'] ?? null ) && 'backup' === ( $st['paused']['code'] ?? null ) && (int) ( $st['retry_after'] ?? 0 ) >= 30, 'with the plain reason and a retry after a minute', (string) wp_json_encode( [ $st['message'] ?? null, $st['retry_after'] ?? null ] ) );
    $started = array_filter( (array) ( $st['steps'] ?? [] ), static fn( $s ): bool => 'pending' !== ( $s['status'] ?? '' ) );
    IT::check( 0 === (int) ( $st['cursor'] ?? -1 ) && [] === $started, 'no step started and none failed', (string) wp_json_encode( array_column( $started, 'status', 'key' ) ) );
    lga_it_rest( 'POST', '/growth/cancel' );

    // --- The backup finished ---------------------------------------------------------------------------------
    wp_clear_scheduled_hook( $hook, [ 1, $nonce ] );
    IT::check( false === wp_next_scheduled( $hook, [ 1, $nonce ] ), 'the resume event is removed' );
    $tick = $pipeline->tick();
    IT::check( 'paused' !== ( $tick['skipped_reason'] ?? '' ) && array_key_exists( 'paused', $tick ) && null === $tick['paused'], 'the next tick is not paused', (string) wp_json_encode( [ $tick['skipped_reason'] ?? null, $tick['paused'] ?? null ] ) );
    IT::check( (int) ( $tick['run']['ran'] ?? 0 ) >= 1, 'and runs jobs', (string) wp_json_encode( $tick['run'] ?? null ) );
    IT::check( Jobs::STATUS_COMPLETED === ( $jobs->find( $jobId )['status'] ?? '' ), 'the waiting job ran (nothing was lost)', (string) ( $jobs->find( $jobId )['status'] ?? '' ) );
    IT::check( 1 === $logged( 'Background work resumed' ) - $resumedBefore, 'the resume is logged once' );
    $status = $pipeline->status();
    IT::check( array_key_exists( 'paused', $status ) && null === $status['paused'], 'the status no longer shows a pause' );

    // A leftover resume event of a backup whose job record says "finished" does not pause.
    wp_schedule_single_event( time() + 120, $hook, [ 2, 'itbackup0002' ] );
    update_option( 'updraft_jobdata_itbackup0002', [ 'jobstatus' => 'finished', 'backup_time' => time() - 900 ], false );
    $tick = $pipeline->tick();
    IT::check( 'paused' !== ( $tick['skipped_reason'] ?? '' ), 'a finished backup\'s leftover event does not pause' );
    update_option( 'updraft_jobdata_itbackup0002', [ 'jobstatus' => 'dbcreating', 'backup_time' => time() - 60 ], false );
    $tick = $pipeline->tick();
    IT::check( 'backup' === ( $tick['paused']['code'] ?? null ), 'the same event pauses while that backup is still running' );
    wp_clear_scheduled_hook( $hook, [ 2, 'itbackup0002' ] );
    delete_option( 'updraft_jobdata_itbackup0002' );

    // Other backup plugins report through a filter.
    $other = static fn(): string => 'BackWPup';
    add_filter( 'lga_backup_running', $other );
    $tick = $pipeline->tick();
    IT::check( 'backup' === ( $tick['paused']['code'] ?? null ) && 'BackWPup' === ( $tick['paused']['source'] ?? null ), 'lga_backup_running pauses for another backup plugin' );
    remove_filter( 'lga_backup_running', $other );

    // The quiet window after an update (here: the stored version is the previous one).
    $marker = get_option( 'lga_running_version' );
    update_option( 'lga_running_version', [ 'version' => '1.2.3', 'since' => time() - DAY_IN_SECONDS, 'from' => '1.2.2' ], true );
    $tick = $pipeline->tick();
    IT::check( 'just_updated' === ( $tick['paused']['code'] ?? null ), 'the first tick after an update is paused (quiet window)', (string) wp_json_encode( $tick['paused'] ?? null ) );
    IT::check( LGA_VERSION === ( get_option( 'lga_running_version' )['version'] ?? null ), 'the running version is stored' );
    update_option( 'lga_running_version', is_array( $marker ) ? $marker : [ 'version' => LGA_VERSION, 'since' => time() - HOUR_IN_SECONDS, 'from' => '' ], true );
    $tick = $pipeline->tick();
    IT::check( 'paused' !== ( $tick['skipped_reason'] ?? '' ), 'after the window the tick runs again' );

    // Tools → Site Health lists the OPcache test.
    if ( ! class_exists( 'WP_Site_Health' ) ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
    }
    $tests = WP_Site_Health::get_tests();
    $test  = $tests['direct']['lga_opcache'] ?? null;
    IT::check( is_array( $test ) && "PHP's code cache (OPcache)" === ( $test['label'] ?? '' ) && is_callable( $test['test'] ?? null ), 'Site Health lists the direct test "PHP\'s code cache (OPcache)"' );
    $result = is_array( $test ) && is_callable( $test['test'] ?? null ) ? call_user_func( $test['test'] ) : null;
    IT::check( is_array( $result ) && 'lga_opcache' === ( $result['test'] ?? '' ) && in_array( $result['status'] ?? '', [ 'good', 'recommended', 'critical' ], true ), 'the test returns a Site Health result', (string) wp_json_encode( [ $result['status'] ?? null, $result['label'] ?? null ] ) );
    $cliOff = ! function_exists( 'opcache_get_status' ) || false === @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    if ( $cliOff ) {
        IT::check( 'good' === ( $result['status'] ?? '' ), 'OPcache is off in the CLI: the test passes with nothing to report' );
    }
    IT::check( isset( $tests['direct']['php_version'] ), 'the core tests are still listed' );
} );

IT::step( '1.2.4: background work pauses while the server is busy or low on free memory (load and memory filters)', static function (): void {
    global $wpdb;
    wp_set_current_user( 1 );
    $pipeline = lga()->get( Pipeline::class );
    $jobs     = lga()->get( Jobs::class );
    $logs     = lga()->get( Logs::class );
    lga()->get( \LinkGrowth\Autopilot\Support\Settings::class )->set( 'automation.enabled', true );
    $busyText   = '/^Paused: the server is busy \(load (\d+(?:\.\d+)?) on (\d+) (CPU|CPUs)\) — resumes when it calms down\.$/u';
    $memoryText = 'Paused: the server is low on free memory — resumes when memory frees up.';

    // What this machine has, read here without the plugin's classes.
    $online = trim( (string) @file_get_contents( '/sys/devices/system/cpu/online' ) ); // phpcs:ignore
    $cpus   = 0;
    if ( 1 === preg_match( '/^\d+(?:-\d+)?(?:,\d+(?:-\d+)?)*$/', $online ) ) {
        foreach ( explode( ',', $online ) as $range ) {
            $ends  = array_map( 'intval', explode( '-', $range ) );
            $cpus += count( $ends ) > 1 ? $ends[1] - $ends[0] + 1 : 1;
        }
    } else {
        $cpus = (int) preg_match_all( '/^processor\s*:/m', (string) @file_get_contents( '/proc/cpuinfo' ) ); // phpcs:ignore
    }
    $totalKb = preg_match( '/^MemTotal:\s+(\d+) kB/m', (string) @file_get_contents( '/proc/meminfo' ), $m ) ? (int) $m[1] : 0; // phpcs:ignore
    IT::check( 'Linux' === PHP_OS_FAMILY && function_exists( 'sys_getloadavg' ) && $cpus > 0 && $totalKb > 0, 'this machine reports its load, CPU count and memory', PHP_OS_FAMILY . ", {$cpus} CPUs, {$totalKb} kB" );

    // Entries that start with $prefix (the debug line "Kick skipped: Paused: …" is not a pause of its own).
    $logged = static function ( string $prefix ) use ( $logs ): int {
        $n = 0;
        foreach ( $logs->findBy( [ 'channel' => 'automation' ], 'id', 'DESC', 200 ) as $row ) {
            if ( str_starts_with( (string) $row['message'], $prefix ) ) {
                $n++;
            }
        }
        return $n;
    };
    $busyBefore    = $logged( 'Paused: the server is busy' );
    $memoryBefore  = $logged( 'Paused: the server is low on free memory' );
    $resumedBefore = $logged( 'Background work resumed' );
    delete_option( 'lga_cpu_count' );

    $jobs->cancelQueued( 'cleanup' );
    $jobId = (int) $jobs->enqueue( 'cleanup', [ 'manual' => true ], 1 );
    IT::check( $jobId > 0, 'a manual cleanup job is queued' );

    // --- Busy -------------------------------------------------------------------------------------------------
    $busy = static fn(): float => -1.0;
    add_filter( 'lga_max_load_per_cpu', $busy );
    $tick = $pipeline->tick();
    $text = (string) ( $tick['paused']['text'] ?? '' );
    IT::check( 'paused' === ( $tick['skipped_reason'] ?? '' ) && 'server_busy' === ( $tick['paused']['code'] ?? null ), 'the tick is skipped as paused: the server is busy', (string) wp_json_encode( [ $tick['skipped_reason'] ?? null, $tick['paused'] ?? null ] ) );
    IT::check( 1 === preg_match( $busyText, $text, $tm ) && (int) $tm[2] === $cpus && ( 1 === $cpus ) === ( 'CPU' === $tm[3] ), "the text names the load and this machine's {$cpus} CPUs", $text );
    IT::check( 0 === (int) ( $tick['run']['ran'] ?? -1 ) && [] === ( $tick['enqueued'] ?? null ), 'no job ran and no stage was enqueued' );
    $job = $jobs->find( $jobId );
    IT::check( Jobs::STATUS_QUEUED === ( $job['status'] ?? '' ) && 0 === (int) ( $job['attempts'] ?? -1 ), 'the queued job was not claimed', (string) wp_json_encode( [ $job['status'] ?? null, $job['attempts'] ?? null ] ) );
    $measured = (array) ( $tick['paused']['measured'] ?? [] );
    IT::check( $cpus === ( $measured['cpus'] ?? null ) && true === ( $measured['cpus_known'] ?? null ) && -1.0 === ( $measured['max_load_per_cpu'] ?? null ) && is_float( $measured['load'] ?? null ), 'the reason carries the measured numbers', (string) wp_json_encode( $measured ) );
    $stored   = get_option( 'lga_cpu_count' );
    $autoload = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'lga_cpu_count' ) );
    IT::check( is_array( $stored ) && $cpus === ( $stored['count'] ?? null ) && in_array( $autoload, [ 'no', 'off' ], true ), 'the CPU count is stored once, not autoloaded', (string) wp_json_encode( [ $stored, $autoload ] ) );
    IT::check( $text === ( $pipeline->status()['paused']['text'] ?? null ), 'the automation status shows the same text' );
    $rest = lga_it_rest( 'GET', '/automation/status' );
    IT::check( 200 === $rest->get_status() && 'server_busy' === ( $rest->get_data()['paused']['code'] ?? null ) && $cpus === ( $rest->get_data()['paused']['measured']['cpus'] ?? null ), 'GET /automation/status carries the pause and its numbers' );
    $overview = lga_it_rest( 'GET', '/overview' );
    IT::check( 200 === $overview->get_status() && 'server_busy' === ( $overview->get_data()['automation']['paused']['code'] ?? null ), 'GET /overview carries the pause for the dashboard' );
    $kick = (array) lga_it_rest( 'POST', '/automation/kick' )->get_data();
    IT::check( false === ( $kick['kicked'] ?? null ) && str_contains( (string) ( $kick['message'] ?? '' ), 'the server is busy' ), 'POST /automation/kick starts nothing and says why', (string) wp_json_encode( $kick['message'] ?? null ) );
    $start = (array) lga_it_rest( 'POST', '/growth/start' )->get_data();
    IT::check( 'running' === ( $start['status'] ?? '' ) && str_contains( (string) ( $start['message'] ?? '' ), 'Paused: the server is busy' ), 'Grow now starts and says it waits', (string) wp_json_encode( $start['message'] ?? null ) );
    $step = (array) lga_it_rest( 'POST', '/growth/step' )->get_data();
    IT::check( true === ( $step['waiting'] ?? null ) && 1 === preg_match( $busyText, (string) ( $step['message'] ?? '' ) ) && 'server_busy' === ( $step['paused']['code'] ?? null ), 'the Grow now step waits with the busy text', (string) wp_json_encode( [ $step['waiting'] ?? null, $step['message'] ?? null ] ) );
    lga_it_rest( 'POST', '/growth/cancel' );
    $pipeline->tick();
    IT::check( 1 === $logged( 'Paused: the server is busy' ) - $busyBefore, 'the pause is logged once over two ticks' );
    remove_filter( 'lga_max_load_per_cpu', $busy );

    // --- Low on free memory ------------------------------------------------------------------------------------
    $off   = static fn(): float => INF; // this machine's own load never decides the rest of the step
    $short = static fn(): float => 1.0;
    add_filter( 'lga_max_load_per_cpu', $off );
    add_filter( 'lga_min_free_memory_ratio', $short );
    $tick = $pipeline->tick();
    IT::check( 'low_memory' === ( $tick['paused']['code'] ?? null ) && $memoryText === ( $tick['paused']['text'] ?? null ), 'the tick is paused: "' . $memoryText . '"', (string) wp_json_encode( $tick['paused'] ?? null ) );
    IT::check( (int) round( $totalKb / 1024 ) === ( $tick['paused']['measured']['total_mb'] ?? null ) && 100.0 === ( $tick['paused']['measured']['min_percent'] ?? null ), "with this machine's memory (" . (int) round( $totalKb / 1024 ) . ' MB)', (string) wp_json_encode( $tick['paused']['measured'] ?? null ) );
    IT::check( 0 === (int) ( $tick['run']['ran'] ?? -1 ) && Jobs::STATUS_QUEUED === ( $jobs->find( $jobId )['status'] ?? '' ), 'no job ran; the job still waits' );
    IT::check( $memoryText === ( $pipeline->status()['paused']['text'] ?? null ), 'the automation status shows it' );
    lga_it_rest( 'POST', '/growth/start' );
    $step = (array) lga_it_rest( 'POST', '/growth/step' )->get_data();
    IT::check( true === ( $step['waiting'] ?? null ) && $memoryText === ( $step['message'] ?? null ), 'the Grow now step waits with the memory text', (string) wp_json_encode( $step['message'] ?? null ) );
    lga_it_rest( 'POST', '/growth/cancel' );
    IT::check( 1 === $logged( 'Paused: the server is low on free memory' ) - $memoryBefore, 'the memory pause is logged once' );

    // --- Lifted: the next tick runs the waiting job and logs the resume --------------------------------------
    remove_filter( 'lga_min_free_memory_ratio', $short );
    $none = static fn(): float => 0.0;
    add_filter( 'lga_min_free_memory_ratio', $none );
    $tick = $pipeline->tick();
    IT::check( 'paused' !== ( $tick['skipped_reason'] ?? '' ) && array_key_exists( 'paused', $tick ) && null === $tick['paused'], 'with the checks lifted the tick is not paused', (string) wp_json_encode( $tick['paused'] ?? null ) );
    IT::check( Jobs::STATUS_COMPLETED === ( $jobs->find( $jobId )['status'] ?? '' ), 'the waiting job ran (nothing was lost)', (string) ( $jobs->find( $jobId )['status'] ?? '' ) );
    IT::check( 1 === $logged( 'Background work resumed' ) - $resumedBefore, 'the resume is logged once' );
    $status = $pipeline->status();
    IT::check( array_key_exists( 'paused', $status ) && null === $status['paused'], 'the status no longer shows a pause' );
    remove_filter( 'lga_min_free_memory_ratio', $none );
    remove_filter( 'lga_max_load_per_cpu', $off );

    // --- The owner's filter still decides ----------------------------------------------------------------------
    $run = static fn(): bool => false;
    add_filter( 'lga_max_load_per_cpu', $busy );
    add_filter( 'lga_pause_background', $run );
    $tick = $pipeline->tick();
    IT::check( 'paused' !== ( $tick['skipped_reason'] ?? '' ), 'lga_pause_background returning false lets the work run on a busy server' );
    remove_filter( 'lga_pause_background', $run );
    remove_filter( 'lga_max_load_per_cpu', $busy );

    // --- The OPcache advice follows this machine's memory (OPcache is off in the CLI: a stand-in status) ------
    $full   = [
        'opcache_enabled'        => true,
        'cache_full'             => true,
        'memory_usage'           => [ 'used_memory' => 134217000, 'free_memory' => 728, 'wasted_memory' => 0 ],
        'interned_strings_usage' => [ 'buffer_size' => 8388608, 'used_memory' => 8388608, 'free_memory' => 0 ],
        'opcache_statistics'     => [ 'num_cached_scripts' => 9100, 'max_cached_keys' => 16229 ],
    ];
    $health = new \LinkGrowth\Autopilot\Health\SiteHealthTests(
        new \LinkGrowth\Autopilot\Support\Opcache( static fn(): array => $full, [ 'opcache.memory_consumption' => '128', 'opcache.interned_strings_buffer' => '8', 'opcache.max_accelerated_files' => '10000' ] ),
        lga()->get( \LinkGrowth\Autopilot\Support\ServerResources::class )
    );
    $warning = (array) $health->warning();
    $small   = $totalKb * 1024 < 1879048192;
    $first   = (string) ( $warning['remedy'][1] ?? '' );
    IT::check(
        $small
            ? 'opcache.memory_consumption=160 (now 128)' === $first && str_contains( (string) ( $warning['memory'] ?? '' ), 'This server has ' . (int) round( $totalKb / 1024 ) . ' MB of memory' )
            : 'opcache.memory_consumption=256 (now 128)' === $first && '' === ( $warning['memory'] ?? null ),
        'the OPcache advice fits this machine\'s ' . (int) round( $totalKb / 1024 ) . ' MB',
        (string) wp_json_encode( [ $first, $warning['memory'] ?? null ] )
    );
} );
