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
 */

declare(strict_types=1);

use PPC\LinkGrowth\Automation\Pipeline;
use PPC\LinkGrowth\Repositories\Jobs;
use PPC\LinkGrowth\Repositories\Logs;

IT::step( '1.2.4: background work pauses while a backup runs (the live UpdraftPlus case); Site Health warns about a full OPcache', static function (): void {
    wp_set_current_user( 1 );
    $pipeline = ppclg()->get( Pipeline::class );
    $jobs     = ppclg()->get( Jobs::class );
    $logs     = ppclg()->get( Logs::class );
    $nonce    = 'itbackup0001';
    $hook     = 'updraft_backup_resume';
    $text     = 'Paused: a backup is running (resumes automatically).';
    $settings = ppclg()->get( \PPC\LinkGrowth\Support\Settings::class );
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
        return count( as_get_scheduled_actions( [ 'hook' => 'ppclg_tick', 'status' => 'pending', 'per_page' => 200 ], 'ids' ) );
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
    $rest = ppclg_it_rest( 'GET', '/automation/status' );
    IT::check( 200 === $rest->get_status() && 'backup' === ( $rest->get_data()['paused']['code'] ?? null ), 'GET /automation/status carries the pause' );
    $overview = ppclg_it_rest( 'GET', '/overview' );
    IT::check( 200 === $overview->get_status() && 'backup' === ( $overview->get_data()['automation']['paused']['code'] ?? null ), 'GET /overview carries the pause for the dashboard' );
    IT::check( array_key_exists( 'opcache', (array) $overview->get_data() ), 'GET /overview has the opcache warning field' );

    // A second paused tick logs nothing new.
    $pipeline->tick();
    IT::check( 1 === $logged( 'Paused: a backup is running' ) - $pausedBefore, 'the pause is logged once', (string) ( $logged( 'Paused: a backup is running' ) - $pausedBefore ) );

    // A kick starts nothing.
    $kicksBefore = $kicks();
    $kick        = ppclg_it_rest( 'POST', '/automation/kick' );
    $kd          = (array) $kick->get_data();
    IT::check( 200 === $kick->get_status() && true === ( $kd['ok'] ?? null ) && false === ( $kd['kicked'] ?? null ) && str_contains( (string) ( $kd['message'] ?? '' ), 'a backup is running' ), 'POST /automation/kick says the work is paused and starts nothing', (string) wp_json_encode( $kd['message'] ?? null ) );
    IT::check( $kicks() === $kicksBefore, 'no extra tick was queued in Action Scheduler', $kicksBefore . ' → ' . $kicks() );

    // Run now still runs the stage you asked for, with the warning.
    $run = ppclg_it_rest( 'POST', '/automation/run', [ 'stage' => 'cleanup', 'options' => [] ] );
    $rd  = (array) $run->get_data();
    IT::check( 200 === $run->get_status() && true === ( $rd['ok'] ?? null ), 'POST /automation/run still runs a stage' );
    IT::check( str_contains( (string) ( $rd['warning'] ?? '' ), 'Background work is paused: a backup is running.' ), 'and its answer warns that background work is paused', (string) wp_json_encode( $rd['warning'] ?? null ) );
    IT::check( Jobs::STATUS_QUEUED === ( $jobs->find( $jobId )['status'] ?? '' ), 'the queued job is still waiting' );

    // Grow now waits instead of failing.
    $start = ppclg_it_rest( 'POST', '/growth/start' );
    $sd    = (array) $start->get_data();
    IT::check( 200 === $start->get_status() && 'running' === ( $sd['status'] ?? '' ), 'POST /growth/start starts a run', (string) wp_json_encode( $sd['message'] ?? null ) );
    $step = ppclg_it_rest( 'POST', '/growth/step' );
    $st   = (array) $step->get_data();
    IT::check( 200 === $step->get_status() && true === ( $st['ok'] ?? null ) && true === ( $st['waiting'] ?? null ), 'POST /growth/step waits (ok, waiting)', (string) wp_json_encode( [ $st['ok'] ?? null, $st['waiting'] ?? null, $st['message'] ?? null ] ) );
    IT::check( $text === ( $st['message'] ?? null ) && 'backup' === ( $st['paused']['code'] ?? null ) && (int) ( $st['retry_after'] ?? 0 ) >= 30, 'with the plain reason and a retry after a minute', (string) wp_json_encode( [ $st['message'] ?? null, $st['retry_after'] ?? null ] ) );
    $started = array_filter( (array) ( $st['steps'] ?? [] ), static fn( $s ): bool => 'pending' !== ( $s['status'] ?? '' ) );
    IT::check( 0 === (int) ( $st['cursor'] ?? -1 ) && [] === $started, 'no step started and none failed', (string) wp_json_encode( array_column( $started, 'status', 'key' ) ) );
    ppclg_it_rest( 'POST', '/growth/cancel' );

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
    add_filter( 'ppclg_backup_running', $other );
    $tick = $pipeline->tick();
    IT::check( 'backup' === ( $tick['paused']['code'] ?? null ) && 'BackWPup' === ( $tick['paused']['source'] ?? null ), 'ppclg_backup_running pauses for another backup plugin' );
    remove_filter( 'ppclg_backup_running', $other );

    // The quiet window after an update (here: the stored version is the previous one).
    $marker = get_option( 'ppclg_running_version' );
    update_option( 'ppclg_running_version', [ 'version' => '1.2.3', 'since' => time() - DAY_IN_SECONDS, 'from' => '1.2.2' ], true );
    $tick = $pipeline->tick();
    IT::check( 'just_updated' === ( $tick['paused']['code'] ?? null ), 'the first tick after an update is paused (quiet window)', (string) wp_json_encode( $tick['paused'] ?? null ) );
    IT::check( PPCLG_VERSION === ( get_option( 'ppclg_running_version' )['version'] ?? null ), 'the running version is stored' );
    update_option( 'ppclg_running_version', is_array( $marker ) ? $marker : [ 'version' => PPCLG_VERSION, 'since' => time() - HOUR_IN_SECONDS, 'from' => '' ], true );
    $tick = $pipeline->tick();
    IT::check( 'paused' !== ( $tick['skipped_reason'] ?? '' ), 'after the window the tick runs again' );

    // Tools → Site Health lists the OPcache test.
    if ( ! class_exists( 'WP_Site_Health' ) ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
    }
    $tests = WP_Site_Health::get_tests();
    $test  = $tests['direct']['ppclg_opcache'] ?? null;
    IT::check( is_array( $test ) && "PHP's code cache (OPcache)" === ( $test['label'] ?? '' ) && is_callable( $test['test'] ?? null ), 'Site Health lists the direct test "PHP\'s code cache (OPcache)"' );
    $result = is_array( $test ) && is_callable( $test['test'] ?? null ) ? call_user_func( $test['test'] ) : null;
    IT::check( is_array( $result ) && 'ppclg_opcache' === ( $result['test'] ?? '' ) && in_array( $result['status'] ?? '', [ 'good', 'recommended', 'critical' ], true ), 'the test returns a Site Health result', (string) wp_json_encode( [ $result['status'] ?? null, $result['label'] ?? null ] ) );
    $cliOff = ! function_exists( 'opcache_get_status' ) || false === @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    if ( $cliOff ) {
        IT::check( 'good' === ( $result['status'] ?? '' ), 'OPcache is off in the CLI: the test passes with nothing to report' );
    }
    IT::check( isset( $tests['direct']['php_version'] ), 'the core tests are still listed' );
} );
