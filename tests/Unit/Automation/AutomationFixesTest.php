<?php
/**
 * Regression tests for the automation slice fixes: real retries for unreachable / error-parked prospects
 * (automation-1), no tick ceiling that starves stages (automation-2), no persisted transient "evaluating"
 * status plus crash recovery (automation-3), auto-outreach candidates filtered before the LIMIT
 * (automation-4) and cron-safe tick callback arguments (automation-5).
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Automation;

use PPC\LinkGrowth\Automation\JobRunner;
use PPC\LinkGrowth\Automation\Jobs\EvaluateJob;
use PPC\LinkGrowth\Automation\Pipeline;
use PPC\LinkGrowth\Automation\Scheduler;
use PPC\LinkGrowth\Repositories\Contacts;
use PPC\LinkGrowth\Repositories\Jobs;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_Error;

final class AutomationFixesTest extends TestCase {

    private bool $down = false;

    protected function setUp(): void {
        parent::setUp();
        $this->settings()->set( 'advanced.crawl_delay_seconds', 0 );
        $this->down = false;
        HttpFixtures::$handler = function ( string $url ) {
            if ( ! $this->down ) {
                return null;
            }
            if ( str_ends_with( $url, '/robots.txt' ) ) {
                return [ 'response' => [ 'code' => 404, 'message' => 'Not Found' ], 'headers' => [ 'content-type' => 'text/plain' ], 'body' => '', 'cookies' => [] ];
            }
            return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
        };
    }

    private function settings(): Settings {
        return Container::get( Settings::class );
    }

    private function prospects(): Prospects {
        return Container::get( Prospects::class );
    }

    /** @return array<string,mixed> */
    private function evaluate( array $payload = [] ): array {
        return Container::get( JobRunner::class )->runType( 'evaluate', $payload, TimeBudget::seconds( 60.0 ) );
    }

    /** A new request: fresh services (HTTP in-request cache, robots cache) over the same database and options. */
    private function nextRequest(): void {
        $GLOBALS['ppclg_test_transients'] = [];
        Container::reset();
    }

    private function past(): string {
        return gmdate( 'Y-m-d H:i:s', time() - 60 );
    }

    private function days( string $datetime ): float {
        return ( strtotime( $datetime . ' UTC' ) - time() ) / DAY_IN_SECONDS;
    }

    // ------------------------------------------------------------------
    // automation-1: unreachable / error-parked prospects are really re-checked
    // ------------------------------------------------------------------

    public function testUnreachableDiscoveredProspectIsRetriedAndQualifiesOnceTheSiteIsBack(): void {
        $this->loadFixtureSites();
        [ $id ] = $this->prospects()->upsertDiscovered( 'labdirectory.test', [] );

        $this->down = true;
        $r          = $this->evaluate();
        $this->assertSame( 1, $r['unreachable'] );
        $row = $this->prospects()->find( $id );
        $this->assertSame( Prospects::STATUS_REJECTED, $row['status'] );
        $this->assertStringStartsWith( 'Site unreachable', $row['rejection_reason'] );
        $this->assertSame( 1, $row['profile']['unreachable_checks'] );
        $this->assertEqualsWithDelta( 1.0, $this->days( (string) $row['next_review'] ), 0.01, 'first retry one day later' );
        $this->assertSame( [], EvaluateJob::retryDue( $this->prospects(), 10 ), 'not due before the retry date' );

        // The retry date passes and the site is back up.
        $this->prospects()->update( $id, [ 'next_review' => $this->past() ] );
        $this->assertCount( 1, EvaluateJob::retryDue( $this->prospects(), 10 ) );
        $this->nextRequest();
        $this->down = false;
        $r          = $this->evaluate();
        $this->assertSame( 1, $r['evaluated'] );
        $row = $this->prospects()->find( $id );
        $this->assertSame( Prospects::STATUS_QUALIFIED, $row['status'], 'a transient outage no longer rejects a prospect for good' );
        $this->assertSame( '', $row['rejection_reason'] );
    }

    public function testQualifiedProspectKeepsStatusAndProfileUntilRepeatedFailures(): void {
        $p      = $this->prospects();
        [ $id ] = $p->upsertDiscovered( 'flaky-but-good.test', [] );
        $good   = [ 'reachable' => true, 'home' => [ 'title' => 'Good site' ], 'submission_form' => [ 'kind' => 'listing', 'action' => 'https://flaky-but-good.test/add/' ] ];
        $p->update( $id, [ 'status' => 'qualified', 'score' => 82, 'recommended_action' => 'Submit directory listing', 'profile' => $good, 'next_review' => $this->past() ] );
        $this->down = true;

        $expected = [ 1 => [ 'qualified', 1.0 ], 2 => [ 'qualified', 7.0 ], 3 => [ 'rejected', 30.0 ], 4 => [ 'rejected', null ] ];
        foreach ( $expected as $check => [ $status, $days ] ) {
            $this->nextRequest();
            $this->evaluate();
            $row = $this->prospects()->find( $id );
            $this->assertSame( $status, $row['status'], "status after failed check {$check}" );
            $this->assertSame( $check, $row['profile']['unreachable_checks'] );
            if ( null === $days ) {
                $this->assertNull( $row['next_review'], 'automatic re-checks stop once the back-off schedule is exhausted' );
            } else {
                $this->assertEqualsWithDelta( $days, $this->days( (string) $row['next_review'] ), 0.01 );
                $this->prospects()->update( $id, [ 'next_review' => $this->past() ] );
            }
            if ( 'qualified' === $status ) {
                $this->assertSame( 'Submit directory listing', $row['recommended_action'] );
                $this->assertSame( 'listing', $row['profile']['submission_form']['kind'], 'the last good profile is kept for packaging' );
            } else {
                $this->assertSame( 'Do not pursue', $row['recommended_action'] );
            }
        }
        $this->assertSame( [], EvaluateJob::retryDue( $this->prospects(), 10 ) );
    }

    public function testRetrySelectionSkipsHumanDecisionsAndPicksMachineParkedRows(): void {
        $p = $this->prospects();
        // Owner rejected a prospect whose last check had found the site unreachable: keeps a real action.
        [ $human ] = $p->upsertDiscovered( 'owner-rejected.test', [] );
        $p->update( $human, [ 'status' => 'rejected', 'rejection_reason' => 'Site unreachable (HTTP 503)', 'recommended_action' => 'Pitch editorial mention', 'next_review' => $this->past() ] );
        // Owner put a prospect on hold after an evaluation error left its status unchanged.
        [ $hold ] = $p->upsertDiscovered( 'owner-hold.test', [] );
        $p->update( $hold, [ 'status' => 'on_hold', 'rejection_reason' => 'Evaluation error: boom', 'recommended_action' => 'Submit directory listing', 'next_review' => $this->past() ] );
        // Machine-made rows.
        [ $unreach ] = $p->upsertDiscovered( 'machine-unreachable.test', [] );
        $p->update( $unreach, [ 'status' => 'rejected', 'rejection_reason' => 'Site unreachable (cURL error 28: Operation timed out)', 'recommended_action' => 'Do not pursue', 'next_review' => $this->past() ] );
        [ $parked ] = $p->upsertDiscovered( 'machine-parked.test', [] );
        $p->update( $parked, [ 'status' => 'on_hold', 'rejection_reason' => 'Evaluation error: boom', 'recommended_action' => '', 'next_review' => $this->past() ] );
        [ $scored ] = $p->upsertDiscovered( 'low-score.test', [] );
        $p->update( $scored, [ 'status' => 'rejected', 'rejection_reason' => 'Score 20 is below the keep threshold of 50.', 'recommended_action' => 'Do not pursue', 'next_review' => $this->past() ] );
        // Legacy row stranded in the transient status by a dead worker.
        [ $stranded ] = $p->upsertDiscovered( 'stranded.test', [] );
        $p->update( $stranded, [ 'status' => 'evaluating', 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ] );

        $ids = array_map( static fn( array $r ): int => (int) $r['id'], EvaluateJob::retryDue( $p, 50 ) );
        sort( $ids );
        $this->assertSame( [ $unreach, $parked, $stranded ], $ids );
    }

    public function testErrorParkedProspectIsReEvaluatedByTheAutomaticRun(): void {
        $this->loadFixtureSites();
        [ $id ] = $this->prospects()->upsertDiscovered( 'labdirectory.test', [] );
        $this->prospects()->update( $id, [ 'status' => 'on_hold', 'rejection_reason' => 'Evaluation error: boom', 'recommended_action' => '', 'next_review' => $this->past() ] );
        $this->assertSame( [], $this->prospects()->dueForEvaluation( 10 ) );

        $r = $this->evaluate();
        $this->assertSame( 1, $r['evaluated'] );
        $this->assertSame( Prospects::STATUS_QUALIFIED, $this->prospects()->find( $id )['status'] );
    }

    // ------------------------------------------------------------------
    // automation-3: no persisted "evaluating" status; interrupted evaluations are recovered
    // ------------------------------------------------------------------

    public function testEvaluationLeavesTheStoredStatusUntouchedWhileInFlight(): void {
        $p      = $this->prospects();
        [ $id ] = $p->upsertDiscovered( 'approved-site.test', [] );
        $p->update( $id, [ 'status' => 'approved', 'score' => 80, 'recommended_action' => 'Pitch editorial mention', 'next_review' => $this->past() ] );
        $seen = [];
        HttpFixtures::$handler = static function ( string $url ) use ( $p, $id, &$seen ) {
            $seen[] = [ (string) $p->find( $id )['status'], array_keys( (array) get_option( 'ppclg_evaluate_inflight', [] ) ) ];
            return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
        };
        $this->evaluate();

        $this->assertNotEmpty( $seen );
        $this->assertSame( 'approved', $seen[0][0], 'status is not overwritten before the network work' );
        $this->assertSame( [ $id ], $seen[0][1], 'an in-flight marker is kept instead' );
        $this->assertSame( 'approved', $p->find( $id )['status'] );
        $this->assertFalse( get_option( 'ppclg_evaluate_inflight', false ), 'marker removed after the evaluation' );
    }

    public function testEvaluationInterruptedByWorkerDeathIsParkedWithoutLosingTheOwnerStatus(): void {
        $p          = $this->prospects();
        [ $approved ] = $p->upsertDiscovered( 'approved-site.test', [] );
        $p->update( $approved, [ 'status' => 'approved', 'score' => 80, 'recommended_action' => 'Pitch editorial mention', 'next_review' => $this->past() ] );
        [ $fresh ] = $p->upsertDiscovered( 'poison.test', [] );
        // A worker died while evaluating both (marker written, outcome never stored).
        update_option( 'ppclg_evaluate_inflight', [ $approved => time() - 30, $fresh => time() - 30 ], false );
        HttpFixtures::$handler = static fn() => new WP_Error( 'http_request_failed', 'should not be fetched' );

        $r = $this->evaluate();
        $this->assertSame( 0, $r['evaluated'], 'the prospects that killed the worker are not retried in the same run' );
        $this->assertSame( 2, $r['errors'] );
        $this->assertSame( [], HttpFixtures::$requests );

        $row = $p->find( $approved );
        $this->assertSame( 'approved', $row['status'], 'the owner approval survives the crash' );
        $this->assertStringStartsWith( 'Evaluation error: interrupted', $row['rejection_reason'] );
        $this->assertEqualsWithDelta( 1.0, $this->days( (string) $row['next_review'] ), 0.01 );

        $row = $p->find( $fresh );
        $this->assertSame( 'on_hold', $row['status'], 'a discovered row is parked so it cannot block the queue' );
        $this->assertSame( '', $row['recommended_action'] );
        $this->assertFalse( get_option( 'ppclg_evaluate_inflight', false ) );

        // Both come back automatically once the short review delay has passed.
        $p->update( $approved, [ 'next_review' => $this->past() ] );
        $p->update( $fresh, [ 'next_review' => $this->past() ] );
        $this->assertCount( 1, $p->dueForEvaluation( 10 ) );
        $this->assertCount( 1, EvaluateJob::retryDue( $p, 10 ) );
    }

    // ------------------------------------------------------------------
    // automation-2: a low tick ceiling no longer starves stages
    // ------------------------------------------------------------------

    public function testLowMaxExecutionSecondsIsRaisedToWhatTheStagesNeed(): void {
        foreach ( [ 5 => 15.0, 10 => 15.0, 14 => 15.0, 20 => 20.0, 60 => 60.0 ] as $setting => $effective ) {
            $this->settings()->set( 'automation.max_execution_seconds', $setting );
            $this->assertSame( $effective, JobRunner::effectiveMaxSeconds( $this->settings() ) );
        }
    }

    public function testTickWithLowCeilingStillEvaluatesAndSubmits(): void {
        $this->settings()->set( 'automation.max_execution_seconds', 10 );
        $this->settings()->set( 'automation.submissions', true );
        $this->down = true; // the outcome does not matter, only that the work happens
        [ $id ] = $this->prospects()->upsertDiscovered( 'example.test', [] );
        $jobs = Container::get( Jobs::class );
        $jobs->enqueue( 'submit', [ 'manual' => true ], 3 );
        $jobs->enqueue( 'evaluate', [ 'manual' => true ], 4 );

        Container::get( Pipeline::class )->tick();

        $results = get_option( JobRunner::OPTION_STAGE_RESULTS, [] );
        $this->assertArrayNotHasKey( 'skipped', $results['submit']['result'], 'submit is not deferred' );
        $this->assertArrayNotHasKey( 'skipped', $results['evaluate']['result'], 'evaluate is not deferred' );
        $this->assertSame( 1, $results['evaluate']['result']['evaluated'] );
        $this->assertNotSame( 'discovered', $this->prospects()->find( $id )['status'] );
    }

    public function testRepeatedDeferralIsReportedAndResetByProgress(): void {
        $r = Container::get( JobRunner::class )->runType( 'package', [ 'deferrals' => 9 ], TimeBudget::seconds( 1.0 ) );
        $this->assertTrue( $r['skipped'] );
        $this->assertSame( 10, $r['deferrals'] );
        $queued = Container::get( Jobs::class )->findBy( [ 'type' => 'package', 'status' => Jobs::STATUS_QUEUED ] );
        $this->assertCount( 1, $queued );
        $this->assertSame( 10, $queued[0]['payload']['deferrals'] );
        $logs = Container::get( \PPC\LinkGrowth\Repositories\Logs::class )->findBy( [ 'level' => 'warning', 'channel' => 'jobs' ] );
        $this->assertNotEmpty( $logs );
        $this->assertStringContainsString( 'deferred 10 times in a row', (string) $logs[0]['message'] );

        // A continuation after progress starts the count again.
        Container::get( Jobs::class )->update( (int) $queued[0]['id'], [ 'status' => Jobs::STATUS_CANCELLED ] );
        ( new \PPC\LinkGrowth\Automation\Jobs\PackageJob() )->continueLater( Container::get( JobRunner::class )->container(), [ 'deferrals' => 4 ], 0 );
        $next = Container::get( Jobs::class )->findBy( [ 'type' => 'package', 'status' => Jobs::STATUS_QUEUED ] );
        $this->assertArrayNotHasKey( 'deferrals', $next[0]['payload'] );
    }

    // ------------------------------------------------------------------
    // automation-4: auto-outreach filters eligibility before the LIMIT
    // ------------------------------------------------------------------

    private function editorialProspect( string $domain, int $score ): int {
        [ $id ] = $this->prospects()->upsertDiscovered( $domain, [] );
        $this->prospects()->update( $id, [ 'status' => 'qualified', 'score' => $score, 'opportunity_type' => 'editorial', 'name' => ucfirst( $domain ) ] );
        Container::get( Contacts::class )->upsert( $id, 'editor@' . $domain, [ 'name' => 'Ed Itor' ] );
        return $id;
    }

    /** @return array<string,mixed> */
    private function autoOutreach(): array {
        return Container::get( JobRunner::class )->runType( 'auto_outreach', [], TimeBudget::seconds( 60.0 ) );
    }

    public function testHigherScoredFormFirstProspectsNoLongerHideEditorialOnes(): void {
        $p = $this->prospects();
        for ( $i = 0; $i < 45; $i++ ) {
            [ $id ] = $p->upsertDiscovered( "dir{$i}.test", [] );
            $p->update( $id, [ 'status' => 'qualified', 'score' => 95, 'opportunity_type' => 'directory_listing' ] );
            Container::get( Contacts::class )->upsert( $id, "info@dir{$i}.test", [] );
        }
        $editorial = $this->editorialProspect( 'editorial-blog.test', 85 );

        $r = $this->autoOutreach();
        $this->assertSame( 1, $r['drafted'] );
        $this->assertSame( 1, Container::get( OutreachMessages::class )->count( [ 'prospect_id' => $editorial ] ) );
    }

    public function testAlreadyDraftedProspectsDoNotBlockTheNextOnes(): void {
        for ( $i = 0; $i < 41; $i++ ) {
            $this->editorialProspect( "blog{$i}.test", 99 - ( $i % 20 ) );
        }
        $this->assertSame( 40, $this->autoOutreach()['drafted'], 'per-run cap is kept' );
        $second = $this->autoOutreach();
        $this->assertSame( 1, $second['drafted'], 'the 41st prospect is drafted on the next run' );
        $this->assertSame( 0, $second['skipped'] );
        $this->assertSame( 41, Container::get( OutreachMessages::class )->count() );
        $this->assertSame( 0, $this->autoOutreach()['drafted'], 'nobody is drafted twice' );
    }

    // ------------------------------------------------------------------
    // automation-5: cron arguments never break the tick callback
    // ------------------------------------------------------------------

    public function testTickCallbackAcceptsPositionalAndLegacyNamedCronArguments(): void {
        $scheduler = Container::get( Scheduler::class );
        foreach ( [ [], [ time() ], [ 'kick' => (string) time() ] ] as $args ) {
            delete_option( Pipeline::OPTION_LAST_TICK );
            // This is how WP_Hook dispatches a cron event's args (call_user_func_array).
            call_user_func_array( [ $scheduler, 'onTick' ], $args );
            $this->assertNotFalse( get_option( Pipeline::OPTION_LAST_TICK, false ), 'tick ran for args ' . wp_json_encode( $args ) );
        }
        $this->assertTrue( $scheduler->kick() );
    }
}
