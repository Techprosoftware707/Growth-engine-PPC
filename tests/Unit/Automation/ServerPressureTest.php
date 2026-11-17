<?php
/**
 * 1.2.4 server pressure, through the public API only (the scheduler tick, kicks, Grow now steps, REST answers and
 * Site Health registration), so the file also runs against 1.2.3 and shows what was missing there: a scheduled
 * UpdraftPlus resumption ('updraft_backup_resume') or a plugin update did not stop the background work.
 *
 * The cron array is the 'cron' option here (the unit stubs have no _get_cron_array()), shaped like WordPress's:
 * timestamp => hook => key => { schedule, args }.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Automation;

use PPC\LinkGrowth\Admin\Rest\AutomationController;
use PPC\LinkGrowth\Admin\Rest\GrowthController;
use PPC\LinkGrowth\Automation\Pipeline;
use PPC\LinkGrowth\Automation\Scheduler;
use PPC\LinkGrowth\Compliance\StorefrontAudit;
use PPC\LinkGrowth\Growth\GrowthRun;
use PPC\LinkGrowth\Repositories\Jobs;
use PPC\LinkGrowth\Repositories\Logs;
use PPC\LinkGrowth\Support\Limits;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;
use PPC\LinkGrowth\Tests\Unit\Growth\FakeStorefront;
use PPC\LinkGrowth\Tests\Unit\Growth\GrowthFakeJob;
use WP_REST_Request;

require_once __DIR__ . '/../Growth/GrowthFakes.php';

final class ServerPressureTest extends TestCase {

    private const NONCE = 'a1b2c3d4e5f6';

    private mixed $previousCaps = null;

    protected function setUp(): void {
        parent::setUp();
        GrowthFakeJob::reset();
        $GLOBALS['ppclg_test_filters']['ppclg_job_types'] = [ static fn() => GrowthFakeJob::registry( [ 'import_verified' ] ) ];
        Container::plugin()->container()->set( StorefrontAudit::class, new FakeStorefront() );
        $this->settings()->set( 'automation.enabled', true );
        $this->previousCaps            = $GLOBALS['ppclg_test_caps'] ?? null;
        $GLOBALS['ppclg_test_caps']    = [ 'manage_options' => true ];
        $GLOBALS['ppclg_test_user_id'] = 1;
    }

    protected function tearDown(): void {
        if ( null === $this->previousCaps ) {
            unset( $GLOBALS['ppclg_test_caps'] );
        } else {
            $GLOBALS['ppclg_test_caps'] = $this->previousCaps;
        }
        $GLOBALS['ppclg_test_user_id'] = 0;
        parent::tearDown();
    }

    private function settings(): Settings {
        return Container::get( Settings::class );
    }

    private function pipeline(): Pipeline {
        return Container::get( Pipeline::class );
    }

    /** A new request: fresh services over the same database and options. */
    private function nextRequest(): void {
        Container::reset();
        $GLOBALS['ppclg_test_filters']['ppclg_job_types'] = [ static fn() => GrowthFakeJob::registry( [ 'import_verified' ] ) ];
        Container::plugin()->container()->set( StorefrontAudit::class, new FakeStorefront() );
    }

    /** Puts an event into the cron option (WordPress's own layout). */
    private function scheduleEvent( int $at, string $hook, array $args ): void {
        $cron = get_option( 'cron', [] );
        $cron = is_array( $cron ) ? $cron : [];
        $cron[ $at ][ $hook ][ md5( serialize( $args ) ) ] = [ 'schedule' => false, 'args' => $args ];
        ksort( $cron );
        $cron['version'] = 2;
        update_option( 'cron', $cron );
    }

    private function updraftResumeIn( int $seconds ): void {
        $this->scheduleEvent( time() + $seconds, 'updraft_backup_resume', [ 1, self::NONCE ] );
    }

    private function clearCron(): void {
        delete_option( 'cron' );
    }

    /** @return string[] automation log messages, oldest first */
    private function automationLog(): array {
        return array_map( static fn( array $r ): string => (string) $r['message'], Container::get( Logs::class )->findBy( [ 'channel' => 'automation' ], 'id', 'ASC', 200 ) );
    }

    /** @return string[] */
    private function logged( string $needle ): array {
        return array_values( array_filter( $this->automationLog(), static fn( string $m ): bool => str_contains( $m, $needle ) ) );
    }

    private function kicks(): int {
        $n = count( $GLOBALS['ppclg_test_as_async'] ?? [] );
        foreach ( $GLOBALS['ppclg_test_cron_single'] ?? [] as $event ) {
            if ( Scheduler::HOOK === $event['hook'] ) {
                $n++;
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // The scheduler tick
    // ------------------------------------------------------------------

    public function testTickClaimsNoJobWhileAnUpdraftBackupRunsAndResumesAfterwards(): void {
        $jobs = Container::get( Jobs::class );
        $id   = $jobs->enqueue( 'discover', [ 'manual' => true ], 1 );
        $this->updraftResumeIn( 240 );

        $out = $this->pipeline()->tick();
        $this->assertSame( [], GrowthFakeJob::$runs, 'no job ran while the backup runs' );
        $this->assertSame( 'paused', $out['skipped_reason'] );
        $this->assertSame( 'backup', $out['paused']['code'] ?? null );
        $this->assertSame( 0, $out['run']['ran'] );
        $this->assertSame( [], $out['enqueued'], 'no stage was enqueued' );
        $job = $jobs->find( $id );
        $this->assertSame( Jobs::STATUS_QUEUED, $job['status'], 'the queued job stays queued (nothing is lost)' );
        $this->assertSame( 0, (int) $job['attempts'], 'and was never claimed' );
        $this->assertSame( 1, $jobs->count( [] ), 'no other job was queued' );
        $this->assertFalse( get_option( Pipeline::OPTION_STAGE_RUNS, false ), 'no stage cadence was used up' );
        foreach ( Container::get( Limits::class )->snapshot() as $key => $limit ) {
            $this->assertSame( 0, (int) ( $limit['used_today'] ?? 0 ), $key . ': nothing spent' );
        }
        $this->assertNotSame( '', (string) get_option( Pipeline::OPTION_LAST_TICK, '' ), 'the tick itself is still recorded' );

        $status = $this->pipeline()->status();
        $this->assertSame( 'Paused: a backup is running (resumes automatically).', $status['paused']['text'] ?? null, 'the dashboard shows the reason' );

        // A second paused tick (next request) logs nothing new: one entry per pause.
        $this->nextRequest();
        $this->pipeline()->tick();
        $this->assertSame( [], GrowthFakeJob::$runs );
        $this->assertCount( 1, $this->logged( 'Paused: a backup is running' ), implode( ' | ', $this->automationLog() ) );

        // The backup finished (its resumption event is gone): the next tick works as usual.
        $this->clearCron();
        $this->nextRequest();
        $out = $this->pipeline()->tick();
        $this->assertSame( '', $out['skipped_reason'] );
        $this->assertNull( $out['paused'] ?? null );
        $this->assertContains( 'discover', GrowthFakeJob::order(), 'the waiting job ran' );
        $this->assertSame( Jobs::STATUS_COMPLETED, $jobs->find( $id )['status'] );
        $this->assertCount( 1, $this->logged( 'Background work resumed' ) );
        $status = $this->pipeline()->status();
        $this->assertArrayHasKey( 'paused', $status );
        $this->assertNull( $status['paused'] );
    }

    public function testAFinishedJobARemoteResumptionOrAStaleOneDoesNotPause(): void {
        // A leftover resumption of a backup whose job record says "finished".
        $this->updraftResumeIn( 120 );
        update_option( 'updraft_jobdata_' . self::NONCE, [ 'jobstatus' => 'finished', 'backup_time' => time() - 600 ] );
        $out = $this->pipeline()->tick();
        $this->assertSame( '', $out['skipped_reason'], 'a finished backup does not pause' );
        $this->assertNotSame( [], GrowthFakeJob::$runs );

        // Resumption more than 30 minutes away, or overdue for more than an hour.
        foreach ( [ 3 * HOUR_IN_SECONDS, -2 * HOUR_IN_SECONDS ] as $offset ) {
            $this->clearCron();
            delete_option( 'updraft_jobdata_' . self::NONCE );
            $this->updraftResumeIn( $offset );
            $this->nextRequest();
            $this->assertSame( '', $this->pipeline()->tick()['skipped_reason'], 'offset ' . $offset );
        }

        // Overdue by a few minutes (WP-Cron is behind while the backup holds the workers): still running.
        $this->clearCron();
        $this->updraftResumeIn( -300 );
        update_option( 'updraft_jobdata_' . self::NONCE, [ 'jobstatus' => 'dbcreating' ] );
        $this->nextRequest();
        $this->assertSame( 'paused', $this->pipeline()->tick()['skipped_reason'] );
    }

    public function testBackupNowEventAndOtherBackupPluginsPause(): void {
        $this->scheduleEvent( time() + 5, 'updraft_backupnow_backup_all', [ [ 'nocloud' => 0 ] ] );
        $out = $this->pipeline()->tick();
        $this->assertSame( 'backup', $out['paused']['code'] ?? null );
        $this->assertSame( [], GrowthFakeJob::$runs );

        $this->clearCron();
        $this->nextRequest();
        add_filter( 'ppclg_backup_running', static fn(): string => 'BackWPup' );
        $out = $this->pipeline()->tick();
        $this->assertSame( 'backup', $out['paused']['code'] ?? null );
        $this->assertSame( 'BackWPup', $out['paused']['source'] ?? null );
        $this->assertSame( [], GrowthFakeJob::$runs );
    }

    public function testTheFirstTenMinutesAfterAnUpdateArePaused(): void {
        // 1.2.3 never stored the marker: the first request on the new version starts the quiet window.
        delete_option( 'ppclg_running_version' );
        $out = $this->pipeline()->tick();
        $this->assertSame( 'just_updated', $out['paused']['code'] ?? null );
        $this->assertSame( 'Paused: the plugin was just updated (resumes automatically in about 10 minutes).', $out['paused']['text'] ?? null );
        $this->assertSame( [], GrowthFakeJob::$runs );
        $marker = get_option( 'ppclg_running_version' );
        $this->assertSame( PPCLG_VERSION, $marker['version'] ?? null, 'the running version is stored' );
        $this->assertEqualsWithDelta( time(), (int) ( $marker['since'] ?? 0 ), 2 );

        // An older version stored: the same.
        update_option( 'ppclg_running_version', [ 'version' => '0.9.0', 'since' => time() - DAY_IN_SECONDS, 'from' => '' ] );
        $this->nextRequest();
        $this->assertSame( 'just_updated', $this->pipeline()->tick()['paused']['code'] ?? null );
        $this->assertSame( '0.9.0', get_option( 'ppclg_running_version' )['from'] ?? null );

        // 9 minutes later still paused, 10 minutes and a second later the work runs.
        update_option( 'ppclg_running_version', [ 'version' => PPCLG_VERSION, 'since' => time() - 540, 'from' => '0.9.0' ] );
        $this->nextRequest();
        $this->assertSame( 'paused', $this->pipeline()->tick()['skipped_reason'] );
        update_option( 'ppclg_running_version', [ 'version' => PPCLG_VERSION, 'since' => time() - 601, 'from' => '0.9.0' ] );
        $this->nextRequest();
        $this->assertSame( '', $this->pipeline()->tick()['skipped_reason'] );
        $this->assertNotSame( [], GrowthFakeJob::$runs );
    }

    public function testOwnerFilterPausesAndCanLetWorkRunDespiteADetectedReason(): void {
        add_filter( 'ppclg_pause_background', static fn(): string => 'Nightly maintenance window' );
        $out = $this->pipeline()->tick();
        $this->assertSame( 'owner', $out['paused']['code'] ?? null );
        $this->assertSame( 'Paused: Nightly maintenance window.', $out['paused']['text'] ?? null );
        $this->assertSame( [], GrowthFakeJob::$runs );

        $GLOBALS['ppclg_test_filters']['ppclg_pause_background'] = [ static fn(): bool => true ];
        $this->nextRequest();
        $this->assertSame( 'owner', $this->pipeline()->tick()['paused']['code'] ?? null );

        // false lets the work run even though a backup was detected (a host that knows better).
        $GLOBALS['ppclg_test_filters']['ppclg_pause_background'] = [ static fn(): bool => false ];
        $this->updraftResumeIn( 60 );
        $this->nextRequest();
        $this->assertSame( '', $this->pipeline()->tick()['skipped_reason'] );
        $this->assertNotSame( [], GrowthFakeJob::$runs );
    }

    // ------------------------------------------------------------------
    // Kicks
    // ------------------------------------------------------------------

    public function testKickQueuesNothingWhilePausedAndWorksAfterwards(): void {
        $this->updraftResumeIn( 240 );
        $scheduler = Container::get( Scheduler::class );
        $this->assertFalse( $scheduler->kick(), 'no extra tick while a backup runs' );
        $this->assertSame( 0, $this->kicks() );

        $response = ( new AutomationController( Container::plugin()->container() ) )->kick( new WP_REST_Request( 'POST', '/ppclg/v1/automation/kick' ) );
        $this->assertIsArray( $response );
        $this->assertTrue( $response['ok'], 'a pause is not an error' );
        $this->assertFalse( $response['kicked'] ?? null );
        $this->assertStringContainsString( 'Background work is paused: a backup is running.', (string) $response['message'] );
        $this->assertSame( 0, $this->kicks() );

        $this->clearCron();
        $this->nextRequest();
        $this->assertTrue( Container::get( Scheduler::class )->kick() );
        $this->assertSame( 1, $this->kicks() );
    }

    // ------------------------------------------------------------------
    // Grow now
    // ------------------------------------------------------------------

    public function testGrowthStepsWaitWhilePausedAndContinueAfterwards(): void {
        $this->settings()->set( 'automation.submissions', true );
        $growth = Container::get( GrowthRun::class );
        $growth->start( 1 );
        $this->updraftResumeIn( 240 );
        $this->nextRequest();

        $controller = new GrowthController( Container::plugin()->container() );
        $state      = $controller->step( new WP_REST_Request( 'POST', '/ppclg/v1/growth/step' ) );
        $this->assertIsArray( $state );
        $this->assertTrue( $state['ok'], 'a paused step is not a failure' );
        $this->assertTrue( $state['waiting'] );
        $this->assertSame( 'Paused: a backup is running (resumes automatically).', $state['message'] );
        $this->assertSame( 'Paused: a backup is running (resumes automatically).', $state['note'] );
        $this->assertSame( 'backup', $state['paused']['code'] ?? null );
        $this->assertGreaterThanOrEqual( 30, (int) ( $state['retry_after'] ?? 0 ), 'the panel asks again in a minute, not every 3 s' );
        $this->assertSame( GrowthRun::STATUS_RUNNING, $state['status'] );
        $this->assertSame( 0, $state['cursor'] );
        foreach ( $state['steps'] as $step ) {
            $this->assertSame( GrowthRun::STEP_PENDING, $step['status'], $step['key'] . ' did not start (and did not fail)' );
        }
        $this->assertSame( [], GrowthFakeJob::$runs );

        // A background tick does not continue it either.
        $stored                    = get_option( GrowthRun::OPTION );
        $stored['last_advance_at'] = gmdate( 'c', time() - 120 );
        update_option( GrowthRun::OPTION, $stored, false );
        $this->nextRequest();
        $this->assertNull( $this->pipeline()->tick()['growth'] );
        $this->assertSame( [], GrowthFakeJob::$runs );

        // The backup finished: the run continues where it was and completes.
        $this->clearCron();
        $this->nextRequest();
        $growth = Container::get( GrowthRun::class );
        $state  = $growth->advance( TimeBudget::seconds( 60.0 ) );
        for ( $n = 0; $n < 10 && GrowthRun::STATUS_RUNNING === $state['status']; $n++ ) {
            $state = $growth->advance( TimeBudget::seconds( 60.0 ) );
        }
        $this->assertFalse( $state['waiting'] );
        $this->assertSame( GrowthRun::STATUS_COMPLETED, $state['status'] );
        $this->assertSame( 'preflight', $state['steps'][0]['key'] );
        $this->assertSame( GrowthRun::STEP_DONE, $state['steps'][0]['status'] );
        $this->assertNotSame( [], GrowthFakeJob::$runs );
    }

    // ------------------------------------------------------------------
    // Manual run now
    // ------------------------------------------------------------------

    public function testRunNowStillRunsAStageButSaysBackgroundWorkIsPaused(): void {
        $this->updraftResumeIn( 240 );
        $request = new WP_REST_Request( 'POST', '/ppclg/v1/automation/run' );
        $request->set_param( 'stage', 'discover' );
        $out = ( new AutomationController( Container::plugin()->container() ) )->run( $request );
        $this->assertIsArray( $out );
        $this->assertTrue( $out['ok'] );
        $this->assertSame( [ 'discover' ], GrowthFakeJob::order(), 'the stage you started ran' );
        $this->assertStringContainsString( 'Background work is paused: a backup is running.', (string) ( $out['warning'] ?? '' ) );
        $this->assertSame( 'backup', $out['status']['paused']['code'] ?? null, 'the status in the answer shows the pause' );

        $this->clearCron();
        $this->nextRequest();
        $out = ( new AutomationController( Container::plugin()->container() ) )->run( $request );
        $this->assertArrayNotHasKey( 'warning', $out );
    }

    // ------------------------------------------------------------------
    // Site Health
    // ------------------------------------------------------------------

    public function testTheSiteHealthTestIsRegisteredOnBoot(): void {
        Container::plugin()->boot();
        $tests = apply_filters( 'site_status_tests', [ 'direct' => [ 'other' => [ 'label' => 'Other', 'test' => 'other' ] ], 'async' => [] ] );
        $this->assertArrayHasKey( 'other', $tests['direct'], 'the other tests stay' );
        $this->assertArrayHasKey( 'ppclg_opcache', $tests['direct'] );
        $this->assertSame( "PHP's code cache (OPcache)", $tests['direct']['ppclg_opcache']['label'] );
        $this->assertIsCallable( $tests['direct']['ppclg_opcache']['test'] );
        $result = ( $tests['direct']['ppclg_opcache']['test'] )();
        $this->assertSame( 'ppclg_opcache', $result['test'] );
        $this->assertSame( 'good', $result['status'], 'OPcache is off in the CLI: nothing to report' );
    }
}
