<?php
/**
 * Growth\GrowthRun: the one-click growth run state machine (start, step order, budget stop + resume, cancel,
 * runner busy, gates, continuation caps), its background continuation in Pipeline::tick(), the
 * Jobs::claimNext() type / schedule filters and JobRunner::runQueuedOfType().
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Growth;

use PPC\LinkGrowth\Automation\JobRunner;
use PPC\LinkGrowth\Automation\Pipeline;
use PPC\LinkGrowth\Compliance\StorefrontAudit;
use PPC\LinkGrowth\Discovery\VerifiedOpportunities;
use PPC\LinkGrowth\Growth\GrowthRun;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\Jobs;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Seo\IndexNow;
use PPC\LinkGrowth\Seo\InternalLinker;
use PPC\LinkGrowth\Seo\SiteAuditor;
use PPC\LinkGrowth\Support\Locks;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;

require_once __DIR__ . '/GrowthFakes.php';

final class GrowthRunTest extends TestCase {

    private FakeStorefront $storefront;

    protected function setUp(): void {
        parent::setUp();
        GrowthFakeJob::reset();
        $this->registerJobs();
        $this->storefront = new FakeStorefront();
        Container::plugin()->container()->set( StorefrontAudit::class, $this->storefront );
        $this->settings()->set( 'automation.submissions', true );
    }

    /** @param string[] $except */
    private function registerJobs( array $except = [ 'import_verified' ] ): void {
        $GLOBALS['ppclg_test_filters']['ppclg_job_types'] = [ static fn() => GrowthFakeJob::registry( $except ) ];
    }

    private function settings(): Settings {
        return Container::get( Settings::class );
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

    private function index( string $key ): int {
        foreach ( GrowthRun::plan() as $i => $step ) {
            if ( $step['key'] === $key ) {
                return $i;
            }
        }
        $this->fail( 'No step ' . $key );
    }

    /** @return array<string,mixed> */
    private function runToEnd( int $maxAdvances = 10 ): array {
        $state = $this->growth()->state();
        for ( $n = 0; $n < $maxAdvances && GrowthRun::STATUS_RUNNING === $state['status']; $n++ ) {
            $state = $this->growth()->advance( TimeBudget::seconds( 60.0 ) );
        }
        return $state;
    }

    private function auditCount( string $action ): int {
        return Container::get( AuditLog::class )->count( [ 'action' => $action ] );
    }

    // ------------------------------------------------------------------
    // State machine
    // ------------------------------------------------------------------

    public function testStartIsIdempotentWhileARunIsActive(): void {
        $first = $this->growth()->start( 7 );
        $this->assertSame( GrowthRun::STATUS_RUNNING, $first['status'] );
        $this->assertNotSame( '', $first['id'] );
        $this->assertSame( 7, $first['started_by'] );
        $this->assertTrue( $first['active'] );
        $this->assertSame( [ 'done' => 0, 'total' => count( GrowthRun::plan() ) ], $first['progress'] );

        $second = $this->growth()->start( 9 );
        $this->assertSame( $first['id'], $second['id'], 'a second start returns the active run unchanged' );
        $this->assertSame( 7, $second['started_by'] );
        $this->assertSame( 1, $this->auditCount( 'growth.started' ) );
        $this->assertTrue( $this->growth()->isActive() );

        $stored = get_option( GrowthRun::OPTION );
        $this->assertIsArray( $stored );
        $this->assertArrayNotHasKey( 'active', $stored, 'computed fields are not persisted' );
    }

    public function testIdleStateBeforeAnyRun(): void {
        $state = $this->growth()->state();
        $this->assertSame( GrowthRun::STATUS_IDLE, $state['status'] );
        $this->assertFalse( $state['active'] );
        $this->assertFalse( $this->growth()->isActive() );
        $this->assertSame( PHP_INT_MAX, $this->growth()->lastAdvanceAge() );
        $this->assertSame( GrowthRun::STATUS_IDLE, $this->growth()->advance( TimeBudget::seconds( 20.0 ) )['status'], 'advance without a run does nothing' );
    }

    public function testStepsRunInOrderAndTheRunCompletes(): void {
        $this->growth()->start( 1 );
        $state = $this->growth()->advance( TimeBudget::seconds( 60.0 ) );

        $this->assertSame( GrowthRun::STATUS_COMPLETED, $state['status'] );
        $this->assertSame( count( GrowthRun::plan() ), $state['cursor'] );
        $this->assertSame(
            [ 'site_audit', 'indexnow', 'internal_links', 'discover', 'mention_scan', 'broken_link_scan', 'evaluate', 'find_opportunities', 'package', 'submit', 'verify_listings', 'auto_outreach', 'monitor_backlinks', 'import_backlinks', 'metrics_refresh' ],
            GrowthFakeJob::order()
        );
        $this->assertSame( array_column( GrowthRun::plan(), 'key' ), array_column( $state['steps'], 'key' ) );
        foreach ( $state['steps'] as $step ) {
            $this->assertContains( $step['status'], [ 'done', 'skipped' ], $step['key'] );
            $this->assertNotSame( '', $step['summary'], $step['key'] . ' has a summary' );
            $this->assertArrayNotHasKey( '_acc', $step, 'internal accumulators are not exposed' );
        }
        // Every job ran as a manual job tagged with the run id.
        foreach ( GrowthFakeJob::$runs as $run ) {
            $this->assertTrue( $run['payload']['manual'] );
            $this->assertSame( $state['id'], $run['payload']['growth_run'] );
        }
        $this->assertSame( 'Results: 5 checked.', $this->step( $state, 'discover' )['summary'], 'summary composed from the numeric result keys' );
        $this->assertSame( [ 'checked' => 5 ], $this->step( $state, 'discover' )['counts'] );
        $this->assertSame( 1, $this->auditCount( 'growth.completed' ) );
        $this->assertNotNull( $state['finished_at'] );
        $stageRuns = get_option( Pipeline::OPTION_STAGE_RUNS, [] );
        $this->assertArrayHasKey( 'discover', $stageRuns, 'stages the run executed are not re-enqueued by the next tick' );
        $this->assertSame( [ 'done' => count( GrowthRun::plan() ), 'total' => count( GrowthRun::plan() ) ], $state['progress'] );
        $this->assertFalse( $this->growth()->isActive() );

        // A completed run keeps its results until the next start.
        $again = $this->growth()->state();
        $this->assertSame( GrowthRun::STATUS_COMPLETED, $again['status'] );
        $next = $this->growth()->start( 1 );
        $this->assertNotSame( $state['id'], $next['id'], 'a new start after completion begins a new run' );
    }

    public function testUnregisteredJobTypeIsSkippedNotFatal(): void {
        $this->growth()->start( 1 );
        $state = $this->growth()->advance( TimeBudget::seconds( 60.0 ) );
        $step  = $this->step( $state, 'import_verified' );
        $this->assertSame( 'skipped', $step['status'] );
        $this->assertSame( 'Not available in this version.', $step['summary'] );
        $this->assertSame( GrowthRun::STATUS_COMPLETED, $state['status'] );
    }

    public function testJobSummaryAndReasonAreUsedForStepSummaries(): void {
        GrowthFakeJob::$script['site_audit'] = [ 'result' => [ 'summary' => 'Audited 12 of 12 pages; 3 issues so far.', 'issues' => 3 ] ];
        GrowthFakeJob::$script['discover']   = [ 'result' => [ 'checked' => 0, 'skipped' => true, 'reason' => 'no_search_provider', 'message' => 'Add a search provider key.' ] ];
        $this->growth()->start( 1 );
        $state = $this->growth()->advance( TimeBudget::seconds( 60.0 ) );

        $this->assertSame( 'Audited 12 of 12 pages; 3 issues so far.', $this->step( $state, 'site_audit' )['summary'] );
        $this->assertSame( [ 'checked' => 5, 'issues' => 3 ], $this->step( $state, 'site_audit' )['counts'] );
        $discover = $this->step( $state, 'discover' );
        $this->assertSame( 'skipped', $discover['status'] );
        $this->assertSame( 'Skipped: no search provider. Add a search provider key.', $discover['summary'] );
    }

    public function testFailedJobMarksTheStepFailedAndTheRunGoesOn(): void {
        GrowthFakeJob::$script['discover'] = [ 'throw' => true ];
        $this->growth()->start( 1 );
        $state = $this->growth()->advance( TimeBudget::seconds( 60.0 ) );

        $step = $this->step( $state, 'discover' );
        $this->assertSame( 'failed', $step['status'] );
        $this->assertStringContainsString( 'Scripted failure in discover', $step['error'] );
        $this->assertStringContainsString( 'The job failed', $step['summary'] );
        $this->assertSame( 'done', $this->step( $state, 'mention_scan' )['status'], 'later steps still run' );
        $this->assertSame( GrowthRun::STATUS_COMPLETED, $state['status'] );
        $this->assertSame( 1, $state['summary']['steps']['failed'] );
    }

    public function testBudgetStopPersistsAndTheNextAdvanceResumes(): void {
        $this->growth()->start( 1 );
        // 12 s: everything up to "package" fits, "submit" needs 14 s and waits for the next advance.
        $state = $this->growth()->advance( TimeBudget::seconds( 12.0 ) );

        $this->assertSame( GrowthRun::STATUS_RUNNING, $state['status'] );
        $this->assertSame( $this->index( 'submit' ), $state['cursor'] );
        $this->assertSame( 'done', $this->step( $state, 'package' )['status'] );
        $this->assertSame( 'pending', $this->step( $state, 'submit' )['status'] );
        $this->assertSame( 0, GrowthFakeJob::count( 'submit' ) );
        $stored = get_option( GrowthRun::OPTION );
        $this->assertSame( $this->index( 'submit' ), $stored['cursor'], 'the state was saved after every step' );
        $this->assertTrue( $this->growth()->isActive() );
        $this->assertLessThanOrEqual( 2, $this->growth()->lastAdvanceAge() );

        $state = $this->growth()->advance( TimeBudget::seconds( 60.0 ) );
        $this->assertSame( GrowthRun::STATUS_COMPLETED, $state['status'] );
        $this->assertSame( 1, GrowthFakeJob::count( 'submit' ) );
        $this->assertSame( 1, GrowthFakeJob::count( 'package' ), 'finished steps are not repeated' );
    }

    public function testASmallBudgetStillMakesProgressOnEveryAdvance(): void {
        $this->growth()->start( 1 );
        $cursor = -1;
        for ( $n = 0; $n < 40; $n++ ) {
            $state = $this->growth()->advance( TimeBudget::seconds( 8.0 ) );
            if ( GrowthRun::STATUS_RUNNING !== $state['status'] ) {
                break;
            }
            $this->assertGreaterThan( $cursor, $state['cursor'], 'every advance finishes at least one step' );
            $cursor = $state['cursor'];
        }
        $this->assertSame( GrowthRun::STATUS_COMPLETED, $state['status'] );
        $this->assertSame( 1, GrowthFakeJob::count( 'submit' ), 'a step needing more than the budget still runs first thing in an advance' );
    }

    public function testCancelStopsTheRunAndCancelsOnlyItsQueuedJobs(): void {
        $state  = $this->growth()->start( 3 );
        $tagged = $this->jobs()->enqueue( 'evaluate', [ 'manual' => true, 'growth_run' => $state['id'] ], 4, 60 );
        $other  = $this->jobs()->enqueue( 'evaluate', [ 'manual' => true, 'growth_run' => 'another-run' ], 4, 60 );
        $plain  = $this->jobs()->enqueue( 'discover', [], 5 );

        $cancelled = $this->growth()->cancel( 3 );
        $this->assertSame( GrowthRun::STATUS_CANCELLED, $cancelled['status'] );
        $this->assertSame( 1, $cancelled['cancelled_jobs'] );
        $this->assertSame( Jobs::STATUS_CANCELLED, $this->jobs()->find( $tagged )['status'] );
        $this->assertSame( Jobs::STATUS_QUEUED, $this->jobs()->find( $other )['status'] );
        $this->assertSame( Jobs::STATUS_QUEUED, $this->jobs()->find( $plain )['status'] );
        $this->assertSame( 1, $this->auditCount( 'growth.cancelled' ) );
        $this->assertFalse( $this->growth()->isActive() );

        $after = $this->growth()->advance( TimeBudget::seconds( 60.0 ) );
        $this->assertSame( GrowthRun::STATUS_CANCELLED, $after['status'] );
        $this->assertSame( [], GrowthFakeJob::$runs, 'nothing runs after a cancel' );
        $this->assertSame( 0, $this->growth()->cancel( 3 )['cancelled_jobs'], 'cancelling again is a no-op' );
    }

    public function testACancelWhileAJobRunsStopsTheStepAndItsContinuation(): void {
        GrowthFakeJob::$script['site_audit'] = [
            'more'   => 5,
            // Another request cancels the run while this job is running; the job then queues its continuation.
            'before' => fn() => $this->growth()->cancel( 2 ),
        ];
        $state = $this->growth()->start( 1 );
        $after = $this->growth()->advance( TimeBudget::seconds( 60.0 ) );

        $this->assertSame( GrowthRun::STATUS_CANCELLED, $after['status'] );
        $this->assertSame( 1, GrowthFakeJob::count( 'site_audit' ), 'the continuation is not drained after the cancel' );
        $this->assertSame( [ 'site_audit' ], GrowthFakeJob::order(), 'no later step runs' );
        $this->assertSame( 0, $this->jobs()->count( [ 'type' => 'site_audit', 'status' => Jobs::STATUS_QUEUED ] ), 'the late continuation is never queued' );
        $this->assertSame( 0, $this->jobs()->count( [ 'type' => 'site_audit' ] ), 'a job of a cancelled run does not continue itself' );
        $this->assertSame( $state['id'], $after['id'] );
    }

    public function testRunnerBusyReturnsWaitingAndRunsNothing(): void {
        $this->growth()->start( 1 );
        $runner = Container::get( JobRunner::class );
        $this->assertTrue( $runner->lock( 60 ) );

        $state = $this->growth()->advance( TimeBudget::seconds( 60.0 ) );
        $this->assertTrue( $state['waiting'] );
        $this->assertStringContainsString( 'job runner is busy', $state['note'] );
        $this->assertSame( GrowthRun::STATUS_RUNNING, $state['status'] );
        $this->assertSame( 'done', $this->step( $state, 'preflight' )['status'], 'internal steps do not need the runner' );
        $this->assertSame( $this->index( 'site_audit' ), $state['cursor'] );
        $this->assertSame( 'pending', $this->step( $state, 'site_audit' )['status'] );
        $this->assertSame( [], GrowthFakeJob::$runs );

        $runner->unlock();
        $state = $this->growth()->advance( TimeBudget::seconds( 60.0 ) );
        $this->assertFalse( $state['waiting'] );
        $this->assertSame( GrowthRun::STATUS_COMPLETED, $state['status'] );
    }

    public function testConcurrentAdvanceIsRefusedWhileTheGrowthLockIsHeld(): void {
        $this->growth()->start( 1 );
        $locks = Container::get( Locks::class );
        $this->assertTrue( $locks->acquire( GrowthRun::LOCK, 60 ) );

        $state = $this->growth()->advance( TimeBudget::seconds( 60.0 ) );
        $this->assertTrue( $state['waiting'] );
        $this->assertSame( 0, $state['cursor'] );
        $this->assertSame( [], GrowthFakeJob::$runs );

        $locks->release( GrowthRun::LOCK );
        $this->assertSame( GrowthRun::STATUS_COMPLETED, $this->growth()->advance( TimeBudget::seconds( 60.0 ) )['status'] );
    }

    // ------------------------------------------------------------------
    // Gates
    // ------------------------------------------------------------------

    public function testSubmitIsBlockedWhileTheStorefrontGateBlocks(): void {
        $this->storefront->blocking = true;
        $this->growth()->start( 1 );
        $state = $this->runToEnd();

        $submit = $this->step( $state, 'submit' );
        $this->assertSame( 'blocked', $submit['status'] );
        $this->assertStringContainsString( 'storefront compliance check', $submit['summary'] );
        $this->assertStringContainsString( 'Two product categories', $submit['summary'] );
        $this->assertSame( 0, GrowthFakeJob::count( 'submit' ), 'the submit job never ran' );
        $this->assertSame( 1, GrowthFakeJob::count( 'auto_outreach' ), 'drafting continues for review' );
        $this->assertSame( 1, GrowthFakeJob::count( 'package' ), 'packaging continues for review' );

        $preflight = $this->step( $state, 'preflight' );
        $this->assertSame( 'done', $preflight['status'], 'a blocking storefront is a "needs you" item, not a failure' );
        $this->assertSame( 2, $preflight['counts']['storefront_block'] );
        $this->assertSame( 1, $this->storefront->runs, 'preflight re-ran the storefront check' );
        $this->assertSame( 'storefront', $state['next_actions'][0]['key'], 'the storefront finding is the first next action' );
        $this->assertSame( 'overview', $state['next_actions'][0]['page'] );
    }

    public function testSubmitFailsClosedWhenTheGateCannotBeRead(): void {
        $this->storefront->throwOnGate = true;
        $this->growth()->start( 1 );
        $state = $this->runToEnd();
        $this->assertSame( 'blocked', $this->step( $state, 'submit' )['status'] );
        $this->assertStringContainsString( 'could not run', $this->step( $state, 'submit' )['summary'] );
        $this->assertSame( 0, GrowthFakeJob::count( 'submit' ) );
    }

    public function testSubmitIsBlockedWhenAutomatedSubmissionsAreOffAndNoSettingChanges(): void {
        $this->settings()->set( 'automation.submissions', false );
        $this->settings()->set( 'automation.outreach_autosend', false );
        $before = get_option( Settings::OPTION );
        $this->growth()->start( 1 );
        $state = $this->runToEnd();

        $submit = $this->step( $state, 'submit' );
        $this->assertSame( 'blocked', $submit['status'] );
        $this->assertStringStartsWith( 'Automated submissions are switched off', $submit['summary'] );
        $this->assertSame( 0, GrowthFakeJob::count( 'submit' ) );
        $this->assertContains( 'submissions_off', array_column( $state['next_actions'], 'key' ) );
        $this->assertSame( $before, get_option( Settings::OPTION ), 'the growth run never changes a setting' );
    }

    // ------------------------------------------------------------------
    // Continuations
    // ------------------------------------------------------------------

    public function testContinuationsAreDrainedUpToEachStepsCap(): void {
        $this->settings()->set( 'growth.evaluate_rounds', 2 );
        GrowthFakeJob::$script['site_audit']         = [ 'more' => 20 ];
        GrowthFakeJob::$script['evaluate']           = [ 'more' => 5 ];
        GrowthFakeJob::$script['find_opportunities'] = [ 'more' => 10 ];
        GrowthFakeJob::$script['discover']           = [ 'more' => 1 ];
        $this->growth()->start( 1 );
        $state = $this->runToEnd();
        $this->assertSame( GrowthRun::STATUS_COMPLETED, $state['status'] );

        $audit = $this->step( $state, 'site_audit' );
        $this->assertSame( 8, $audit['rounds'] );
        $this->assertSame( 8, GrowthFakeJob::count( 'site_audit' ) );
        $this->assertSame( 5, $audit['counts']['checked'], 'the audit reports a running total: the latest round\'s value is kept, not a sum' );
        $this->assertSame( 'done', $audit['status'] );
        $this->assertStringContainsString( 'scheduler continues it in the background', $audit['note'] );

        $this->assertSame( 2, $this->step( $state, 'evaluate' )['rounds'], 'evaluate follows growth.evaluate_rounds' );
        $this->assertSame( 4, $this->step( $state, 'find_opportunities' )['rounds'] );

        $discover = $this->step( $state, 'discover' );
        $this->assertSame( 2, $discover['rounds'], 'a continuation is drained until the job reports no more work' );
        $this->assertSame( 10, $discover['counts']['checked'], 'per-round counts are summed over the rounds' );
        $this->assertSame( '', $discover['note'] );

        // The continuation left for the scheduler carries the run id (manual: it runs even with automation off).
        $left = $this->jobs()->findBy( [ 'type' => 'site_audit', 'status' => Jobs::STATUS_QUEUED ] );
        $this->assertCount( 1, $left );
        $this->assertSame( $state['id'], $left[0]['payload']['growth_run'] );
        $this->assertTrue( $left[0]['payload']['manual'] );
        // Drained continuations went through the queue (attempts and results recorded).
        $done = $this->jobs()->findBy( [ 'type' => 'site_audit', 'status' => Jobs::STATUS_COMPLETED ], 'id', 'ASC', 50 );
        $this->assertCount( 7, $done );
        $this->assertSame( 1, (int) $done[0]['attempts'] );
        $this->assertSame( 5, $done[0]['result']['checked'] );
    }

    // ------------------------------------------------------------------
    // Summary and next actions
    // ------------------------------------------------------------------

    public function testSummaryCountsAndNextActionsUseRealRoutesAndNeverFabricate(): void {
        $subs = Container::get( Submissions::class );
        foreach ( [ 'needs_manual', 'needs_manual', 'needs_manual', 'pending_review' ] as $n => $status ) {
            $subs->insert( [ 'prospect_id' => $n + 1, 'status' => $status, 'target_url' => 'https://dir' . $n . '.test/add', 'target_hash' => sha1( (string) $n ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ] );
        }
        $msgs = Container::get( OutreachMessages::class );
        foreach ( [ 1, 2 ] as $n ) {
            $msgs->insert( [ 'prospect_id' => $n, 'contact_id' => $n, 'approach' => 'resource_page', 'status' => 'draft', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ] );
        }
        // On-site SEO and verified-database services that cannot answer (whether or not they are registered).
        $broken = new class() {
            public function __call( string $name, array $args ): never {
                throw new \RuntimeException( 'unavailable' );
            }
        };
        $c = Container::plugin()->container();
        foreach ( [ SiteAuditor::class, IndexNow::class, InternalLinker::class, VerifiedOpportunities::class ] as $class ) {
            $c->set( $class, $broken );
        }
        $this->growth()->start( 1 );
        $state = $this->runToEnd();
        $sum   = $state['summary'];

        $this->assertSame( 3, $sum['submissions']['owner_tasks'] );
        $this->assertSame( 1, $sum['submissions']['pending_review'] );
        $this->assertSame( 2, $sum['outreach_drafts'] );
        $this->assertSame( [ 'live' => 0, 'lost' => 0 ], $sum['backlinks'] );
        $this->assertSame( 0, $sum['prospects']['total'] );
        // Services that cannot answer are reported as unknown (null), never as 0.
        $this->assertNull( $sum['site_audit'] );
        $this->assertNull( $sum['indexnow'] );
        $this->assertNull( $sum['link_suggestions_pending'] );
        $this->assertNull( $sum['verified'] );
        $this->assertSame( 'warn', $sum['compliance']['status'] );

        $byKey = array_column( $state['next_actions'], null, 'key' );
        $this->assertSame( 3, $byKey['owner_tasks']['count'] );
        $this->assertSame( 'submissions', $byKey['owner_tasks']['page'] );
        $this->assertSame( [ 'tab' => 'needs_manual' ], $byKey['owner_tasks']['params'] );
        $this->assertSame( '#/submissions?tab=needs_manual', $byKey['owner_tasks']['route'] );
        $this->assertSame( '#/submissions?tab=pending_review', $byKey['pending_review']['route'] );
        $this->assertSame( '#/outreach?tab=messages&status=draft', $byKey['outreach_drafts']['route'] );
        $this->assertStringContainsString( 'nothing is sent without approval', $byKey['outreach_drafts']['label'] );
        // Setup gaps from the preflight (no providers / sender / Google in the test install) come last.
        $this->assertArrayHasKey( 'sender_identity', $byKey );
        $this->assertSame( '#/settings?tab=outreach', $byKey['sender_identity']['route'] );
        $this->assertArrayHasKey( 'search_provider', $byKey );
        $this->assertSame( 'owner_tasks', $state['next_actions'][0]['key'] );

        $last = $this->step( $state, 'summary' );
        $this->assertSame( 'done', $last['status'] );
        $this->assertStringContainsString( '3 owner tasks waiting', $last['summary'] );
        $this->assertStringContainsString( '2 outreach drafts await your approval', $last['summary'] );
    }

    public function testSeoNumbersBecomeNextActions(): void {
        $c = Container::plugin()->container();
        $c->set( SiteAuditor::class, new class() {
            public function summary(): array {
                return [ 'checked_at' => '2026-11-12T10:00:00+00:00', 'pages' => 40, 'complete' => true, 'counts' => [ 'high' => 2, 'medium' => 5, 'low' => 9 ], 'issues_total' => 16 ];
            }
        } );
        $c->set( InternalLinker::class, new class() {
            public function pendingCount(): int {
                return 4;
            }
        } );
        $c->set( IndexNow::class, new class() {
            public function status(): array {
                return [ 'enabled' => true, 'key' => 'k', 'key_url' => '', 'last_submitted_at' => null, 'last_status' => null, 'last_error' => '', 'submitted_total' => 31, 'pending' => 0 ];
            }
        } );
        GrowthFakeJob::$script['indexnow'] = [ 'result' => [ 'submitted' => 31 ] ];
        $this->growth()->start( 1 );
        $state = $this->runToEnd();
        $sum   = $state['summary'];

        $this->assertSame( 2, $sum['site_audit']['high'] );
        $this->assertSame( 16, $sum['site_audit']['issues_total'] );
        $this->assertSame( 31, $sum['indexnow']['submitted_total'] );
        $this->assertSame( 31, $sum['indexnow_submitted_run'] );
        $this->assertSame( 4, $sum['link_suggestions_pending'] );
        $byKey = array_column( $state['next_actions'], null, 'key' );
        $this->assertSame( '#/seo?links=pending', $byKey['link_suggestions']['route'] );
        $this->assertStringContainsString( 'applied only when you click Apply', $byKey['link_suggestions']['label'] );
        $this->assertSame( '#/seo?severity=high', $byKey['seo_issues']['route'] );
        $this->assertSame( 2, $byKey['seo_issues']['count'] );
    }

    public function testPreflightReportsAnUnreadableStorefrontAsANeedsYouItem(): void {
        $this->storefront->throwOnRun = true;
        $this->growth()->start( 1 );
        $state     = $this->growth()->advance( TimeBudget::seconds( 60.0 ) );
        $preflight = $this->step( $state, 'preflight' );
        $this->assertSame( 'done', $preflight['status'] );
        $this->assertStringContainsString( 'could not run', $preflight['summary'] );
        $this->assertContains( 'storefront', array_column( $state['needs_you'], 'key' ) );
    }

    // ------------------------------------------------------------------
    // Background continuation (Pipeline::tick)
    // ------------------------------------------------------------------

    public function testTickAdvancesAStaleActiveRunEvenWithAutomationOff(): void {
        $this->settings()->set( 'automation.enabled', false );
        $this->growth()->start( 1 );
        $stored                    = get_option( GrowthRun::OPTION );
        $stored['last_advance_at'] = gmdate( 'c', time() - 120 );
        update_option( GrowthRun::OPTION, $stored, false );
        $this->assertGreaterThan( GrowthRun::BACKGROUND_AFTER_SECONDS, $this->growth()->lastAdvanceAge() );

        $out = Container::get( Pipeline::class )->tick();

        $this->assertIsArray( $out['growth'] );
        $this->assertTrue( $out['growth']['advanced'] );
        $state = $this->growth()->state();
        $this->assertGreaterThan( 0, $state['cursor'] );
        $this->assertLessThanOrEqual( 2, $this->growth()->lastAdvanceAge() );
        $this->assertNotSame( [], GrowthFakeJob::$runs );
    }

    public function testTickLeavesARecentlyAdvancedRunToTheDashboard(): void {
        $this->growth()->start( 1 );
        $this->growth()->advance( TimeBudget::seconds( 12.0 ) ); // stops before "submit"
        $cursor = $this->growth()->state()['cursor'];

        $out = Container::get( Pipeline::class )->tick();
        $this->assertNull( $out['growth'] );
        $this->assertSame( $cursor, $this->growth()->state()['cursor'] );
    }

    public function testTickSurvivesAGrowthRunFailure(): void {
        update_option( GrowthRun::OPTION, [ 'id' => 'x', 'status' => 'running', 'steps' => [ 'not-a-step' ], 'last_advance_at' => '2000-01-01T00:00:00+00:00' ], false );
        $out = Container::get( Pipeline::class )->tick();
        $this->assertArrayHasKey( 'run', $out, 'the normal tick still completes' );
        $this->assertNull( $out['growth'], 'a malformed stored run is not treated as active' );
        $this->assertFalse( $this->growth()->isActive() );
        $this->assertSame( GrowthRun::STATUS_RUNNING, $this->growth()->start( 1 )['status'], 'a new run can start over it' );
    }

    // ------------------------------------------------------------------
    // Jobs::claimNext and JobRunner::runQueuedOfType
    // ------------------------------------------------------------------

    public function testClaimNextFiltersByTypeAndCanIgnoreTheSchedule(): void {
        $jobs   = $this->jobs();
        $due    = $jobs->enqueue( 'other_stage', [], 1 );
        $future = $jobs->enqueue( 'site_audit', [], 5, 600 );

        $this->assertNull( $jobs->claimNext( 't1', 'site_audit' ), 'a job scheduled in the future is not due' );
        $claimed = $jobs->claimNext( 't1', 'site_audit', true );
        $this->assertNotNull( $claimed );
        $this->assertSame( $future, (int) $claimed['id'] );
        $this->assertSame( Jobs::STATUS_RUNNING, $claimed['status'] );
        $this->assertSame( 1, (int) $claimed['attempts'] );

        $jobs->enqueue( 'site_audit', [], 1, 600 );
        $any = $jobs->claimNext( 't2' );
        $this->assertSame( $due, (int) $any['id'], 'the existing call (no type, schedule respected) is unchanged' );
        $this->assertNull( $jobs->claimNext( 't2' ), 'future jobs stay unclaimed without ignoreSchedule' );
        $this->assertNull( $jobs->claimNext( 't2', '' ), 'an empty type means any type' );
    }

    public function testRunQueuedOfTypeRunsOnlyThatTypeThroughTheQueue(): void {
        $jobs  = $this->jobs();
        $a     = $jobs->enqueue( 'site_audit', [ 'manual' => true ], 5, 600 );
        $other = $jobs->enqueue( 'other_stage', [ 'manual' => true ], 1 );
        $runner = Container::get( JobRunner::class );

        $res = $runner->runQueuedOfType( 'site_audit', TimeBudget::seconds( 30.0 ) );
        $this->assertSame( 1, $res['ran'] );
        $this->assertSame( 1, $res['completed'] );
        $this->assertSame( $a, $res['results'][0]['job_id'] );
        $this->assertSame( 5, $res['results'][0]['result']['checked'] );
        $this->assertSame( Jobs::STATUS_COMPLETED, $jobs->find( $a )['status'] );
        $this->assertSame( Jobs::STATUS_QUEUED, $jobs->find( $other )['status'], 'other types are left alone' );
        $this->assertSame( 0, GrowthFakeJob::count( 'other_stage' ) );

        $none = $runner->runQueuedOfType( 'site_audit', TimeBudget::seconds( 30.0 ), false );
        $this->assertSame( 0, $none['ran'] );
        $this->assertArrayHasKey( 'error', $runner->runQueuedOfType( 'no_such_type', TimeBudget::seconds( 30.0 ) ) );
    }

    public function testRunQueuedOfTypeStopsAfterAFailureInsteadOfRetryingAtOnce(): void {
        GrowthFakeJob::$script['site_audit'] = [ 'throw' => true ];
        $jobs = $this->jobs();
        $id   = $jobs->enqueue( 'site_audit', [], 5 );
        $res  = Container::get( JobRunner::class )->runQueuedOfType( 'site_audit', TimeBudget::seconds( 30.0 ) );

        $this->assertSame( 1, $res['ran'] );
        $this->assertSame( 1, $res['failed'] );
        $this->assertFalse( $res['results'][0]['ok'] );
        $this->assertStringContainsString( 'Scripted failure', $res['results'][0]['result']['error'] );
        $row = $jobs->find( $id );
        $this->assertSame( Jobs::STATUS_QUEUED, $row['status'], 're-queued with the normal retry delay' );
        $this->assertSame( 1, (int) $row['attempts'] );
        $this->assertSame( 1, GrowthFakeJob::count( 'site_audit' ), 'not claimed again in the same call' );
    }
}
