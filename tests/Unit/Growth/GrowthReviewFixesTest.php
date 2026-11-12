<?php
/**
 * Review fixes for the growth run: a cancelled run's running job does not continue itself, snapshot counters are
 * not summed over rounds, an audit that never ran is reported as unknown, and a background run takes turns with
 * the job queue instead of starving it.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Growth;

use PPC\LinkGrowth\Automation\JobRunner;
use PPC\LinkGrowth\Automation\Pipeline;
use PPC\LinkGrowth\Compliance\StorefrontAudit;
use PPC\LinkGrowth\Growth\GrowthRun;
use PPC\LinkGrowth\Repositories\Jobs;
use PPC\LinkGrowth\Seo\SiteAuditor;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;

require_once __DIR__ . '/GrowthFakes.php';

final class GrowthReviewFixesTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        GrowthFakeJob::reset();
        $GLOBALS['ppclg_test_filters']['ppclg_job_types'] = [ static fn() => GrowthFakeJob::registry( [ 'import_verified' ] ) ];
        Container::plugin()->container()->set( StorefrontAudit::class, new FakeStorefront() );
        Container::get( Settings::class )->set( 'automation.submissions', true );
    }

    private function growth(): GrowthRun {
        return Container::get( GrowthRun::class );
    }

    private function jobs(): Jobs {
        return Container::get( Jobs::class );
    }

    /** @return array<string,mixed> */
    private function step( array $state, string $key ): array {
        foreach ( $state['steps'] as $step ) {
            if ( $step['key'] === $key ) {
                return $step;
            }
        }
        $this->fail( 'No step ' . $key );
    }

    /** @return array<string,mixed> */
    private function runToEnd(): array {
        $state = $this->growth()->state();
        for ( $n = 0; $n < 10 && GrowthRun::STATUS_RUNNING === $state['status']; $n++ ) {
            $state = $this->growth()->advance( TimeBudget::seconds( 60.0 ) );
        }
        return $state;
    }

    private function makeStale(): void {
        $stored                    = get_option( GrowthRun::OPTION );
        $stored['last_advance_at'] = gmdate( 'c', time() - 120 );
        update_option( GrowthRun::OPTION, $stored, false );
    }

    // ------------------------------------------------------------------ correctness-1

    public function testATaggedJobRunningInTheQueueWhenStopIsClickedDoesNotContinue(): void {
        $state = $this->growth()->start( 1 );
        // A continuation the run left for the scheduler; the normal queue runner claims it, and the owner clicks
        // Stop while it runs (after cancel() looked for queued jobs).
        $this->jobs()->enqueue( 'evaluate', [ 'manual' => true, 'growth_run' => $state['id'], 'continued' => 6 ], 5, 0 );
        GrowthFakeJob::$script['evaluate'] = [ 'more' => 3, 'before' => static fn() => Container::get( GrowthRun::class )->cancel( 1 ) ];

        Container::get( JobRunner::class )->run( TimeBudget::seconds( 30.0 ) );

        $this->assertFalse( $this->growth()->isActive() );
        $this->assertSame( 1, GrowthFakeJob::count( 'evaluate' ) );
        $this->assertSame( 0, $this->jobs()->count( [ 'type' => 'evaluate', 'status' => Jobs::STATUS_QUEUED ] ), 'the cancelled run\'s chain ends' );
        Container::get( JobRunner::class )->run( TimeBudget::seconds( 30.0 ) );
        $this->assertSame( 1, GrowthFakeJob::count( 'evaluate' ), 'nothing of the cancelled run runs on later ticks' );
    }

    public function testACancelledRunStaysKnownAfterANewRunStarts(): void {
        $first = $this->growth()->start( 1 );
        $this->growth()->cancel( 1 );
        $second = $this->growth()->start( 1 );
        $this->assertNotSame( $first['id'], $second['id'] );
        $this->assertArrayNotHasKey( 'cancelled_ids', $second, 'bookkeeping is not part of the reported state' );

        $this->assertTrue( GrowthRun::isCancelledRun( $first['id'] ) );
        $this->assertFalse( GrowthRun::isCancelledRun( $second['id'] ) );
        $this->assertFalse( GrowthRun::isCancelledRun( '' ) );

        // A job of the first run that was still running finishes without continuing; the new run's jobs continue.
        GrowthFakeJob::$script['discover'] = [ 'more' => 1 ];
        Container::get( JobRunner::class )->runType( 'discover', [ 'manual' => true, 'growth_run' => $first['id'] ], TimeBudget::seconds( 30.0 ) );
        $this->assertSame( 0, $this->jobs()->count( [ 'type' => 'discover', 'status' => Jobs::STATUS_QUEUED ] ) );
        GrowthFakeJob::$script['discover'] = [ 'more' => 1 ];
        Container::get( JobRunner::class )->runType( 'discover', [ 'manual' => true, 'growth_run' => $second['id'] ], TimeBudget::seconds( 30.0 ) );
        $this->assertSame( 1, $this->jobs()->count( [ 'type' => 'discover', 'status' => Jobs::STATUS_QUEUED ] ) );

        // A completed run leaves its continuations to the scheduler as before.
        $this->jobs()->cancelQueued( 'discover' );
        $state = $this->runToEnd();
        $this->assertSame( GrowthRun::STATUS_COMPLETED, $state['status'] );
        $this->assertFalse( GrowthRun::isCancelledRun( $state['id'] ) );
    }

    // ------------------------------------------------------------------ correctness-3

    public function testRunningTotalsAreNotSummedOverRounds(): void {
        $calls                               = 0;
        GrowthFakeJob::$script['site_audit'] = [
            'more'   => 3,
            'before' => static function () use ( &$calls ): void {
                $calls++;
                GrowthFakeJob::$script['site_audit']['result'] = [ 'checked' => 100 * $calls, 'total' => 400, 'issues' => 10 * $calls, 'high' => $calls ];
            },
        ];
        GrowthFakeJob::$script['discover'] = [ 'more' => 2, 'result' => [ 'found' => 3 ] ];
        $this->growth()->start( 1 );
        $state = $this->runToEnd();

        $audit = $this->step( $state, 'site_audit' );
        $this->assertSame( 4, $audit['rounds'] );
        // The fake applies a scripted result from the next round on: the rounds report 5, 100, 200 and 300 pages.
        $this->assertSame( [ 'checked' => 300, 'total' => 400, 'issues' => 30, 'high' => 3 ], array_intersect_key( $audit['counts'], array_flip( [ 'checked', 'total', 'issues', 'high' ] ) ), 'the last round\'s running totals, never 605 pages of 1200' );
        $discover = $this->step( $state, 'discover' );
        $this->assertSame( 3, $discover['rounds'] );
        $this->assertSame( 9, $discover['counts']['found'], 'per-round amounts are still summed' );
    }

    public function testAStepWithOnlySnapshotValuesIsLabelledSkipped(): void {
        // IndexNow skipped (not production): it reports the pending total and the last HTTP status, nothing done.
        GrowthFakeJob::$script['indexnow'] = [ 'result' => [ 'skipped' => true, 'reason' => 'not_production', 'checked' => 0, 'submitted' => 0, 'pending' => 37, 'http_status' => 202, 'candidates' => 37 ] ];
        $this->growth()->start( 1 );
        $state = $this->runToEnd();
        $step  = $this->step( $state, 'indexnow' );
        $this->assertSame( GrowthRun::STEP_SKIPPED, $step['status'] );
        $this->assertSame( 37, $step['counts']['pending'] );
    }

    // ------------------------------------------------------------------ safety-7

    public function testAnAuditThatNeverRanIsUnknownNotZero(): void {
        $c = Container::plugin()->container();
        $c->set( SiteAuditor::class, new class() {
            public function summary(): array {
                return [ 'checked_at' => null, 'pages' => 0, 'complete' => false, 'counts' => [ 'high' => 0, 'medium' => 0, 'low' => 0 ], 'issues_total' => 0, 'running' => false, 'progress' => [ 'checked' => 0, 'total' => 0 ] ];
            }
        } );
        $this->growth()->start( 1 );
        $state = $this->runToEnd();
        $audit = $state['summary']['site_audit'];
        $this->assertIsArray( $audit );
        $this->assertNull( $audit['checked_at'] );
        $this->assertFalse( $audit['running'] );
        $this->assertNull( $audit['high'], 'no audit result: the count is unknown, not 0' );
        $this->assertNull( $audit['issues_total'] );
        $this->assertNotContains( 'seo_issues', array_column( $state['next_actions'], 'key' ) );
    }

    public function testARunningAuditReportsItsPartialCounts(): void {
        $c = Container::plugin()->container();
        $c->set( SiteAuditor::class, new class() {
            public function summary(): array {
                return [ 'checked_at' => null, 'pages' => 120, 'complete' => false, 'counts' => [ 'high' => 3, 'medium' => 1, 'low' => 0 ], 'issues_total' => 4, 'running' => true, 'progress' => [ 'checked' => 120, 'total' => 400 ] ];
            }
        } );
        $this->growth()->start( 1 );
        $state = $this->runToEnd();
        $audit = $state['summary']['site_audit'];
        $this->assertTrue( $audit['running'] );
        $this->assertFalse( $audit['complete'] );
        $this->assertSame( 3, $audit['high'] );
        $this->assertSame( 4, $audit['issues_total'] );
        $this->assertContains( 'seo_issues', array_column( $state['next_actions'], 'key' ) );
    }

    // ------------------------------------------------------------------ correctness-4

    public function testABackgroundRunAndTheQueueTakeTurns(): void {
        Container::get( Settings::class )->set( 'automation.enabled', false );
        $this->growth()->start( 1 );
        $this->makeStale();
        // The previous tick advanced the background run; a manual job is due in the queue.
        update_option( Pipeline::OPTION_GROWTH_TURN, 'growth', false );
        $queued = $this->jobs()->enqueue( 'other_stage', [ 'manual' => true ], 5, 0 );

        $out = Container::get( Pipeline::class )->tick();
        $this->assertNull( $out['growth'], 'this tick belongs to the queue' );
        $this->assertSame( 'queue', $out['growth_turn'] ?? null );
        $this->assertSame( Jobs::STATUS_COMPLETED, $this->jobs()->find( $queued )['status'], 'the due job ran with a full budget' );
        $this->assertSame( 0, (int) $this->growth()->state()['cursor'], 'the run waited one tick' );

        $this->makeStale();
        $out = Container::get( Pipeline::class )->tick();
        $this->assertIsArray( $out['growth'], 'the next tick continues the run' );
        $this->assertTrue( $out['growth']['advanced'] );
        $this->assertGreaterThan( 0, (int) $this->growth()->state()['cursor'] );
        $this->assertSame( 'growth', get_option( Pipeline::OPTION_GROWTH_TURN ) );
    }

    public function testWithNothingDueTheBackgroundRunKeepsEveryTick(): void {
        $this->growth()->start( 1 );
        $this->makeStale();
        update_option( Pipeline::OPTION_GROWTH_TURN, 'growth', false );
        $this->jobs()->enqueue( 'other_stage', [ 'manual' => true ], 5, 3600 ); // not due yet

        $out = Container::get( Pipeline::class )->tick();
        $this->assertIsArray( $out['growth'] );
        $this->assertTrue( $out['growth']['advanced'] );
    }
}
