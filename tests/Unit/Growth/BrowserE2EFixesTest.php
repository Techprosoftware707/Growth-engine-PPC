<?php
/**
 * Regressions found in the browser click-through of the "Grow now" release:
 * - the import step of a growth run showed only the last batch ("71 new owner tasks") after importing 549;
 * - the summary step said "1 outreach drafts await your approval";
 * - after Stop, the steps that never ran still said "Waiting";
 * - integrations that are simply not set up showed a red "Error" badge on the overview and settings pages.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Growth;

use PPC\LinkGrowth\Automation\Jobs\ImportVerifiedJob;
use PPC\LinkGrowth\Compliance\StorefrontAudit;
use PPC\LinkGrowth\Discovery\VerifiedOpportunities;
use PPC\LinkGrowth\Growth\GrowthRun;
use PPC\LinkGrowth\Repositories\Jobs;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;

require_once __DIR__ . '/GrowthFakes.php';

final class BrowserE2EFixesTest extends TestCase {

    public function testImportSummaryOfTheLastRoundReportsTheWholeImport(): void {
        $c    = Container::plugin()->container();
        $job  = new ImportVerifiedJob();
        $jobs = Container::get( Jobs::class );
        $last = $job->handle( [ 'manual' => true ], TimeBudget::seconds( 60 ), $c );
        $this->assertTrue( $last['more'], 'the bundled database needs more than one batch' );
        $this->assertStringContainsString( '100 of 595 so far', $last['summary'] );
        for ( $i = 0; $i < 10 && $last['more']; $i++ ) {
            $jobs->cancelQueued( 'import_verified' );
            $last = $job->handle( [ 'manual' => true, 'continued' => $i + 1 ], TimeBudget::seconds( 60 ), $c );
        }
        $this->assertFalse( $last['more'] );

        global $wpdb;
        $t     = \PPC\LinkGrowth\Database\Schema::table( 'submissions' );
        $tasks = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE payload LIKE %s", '%' . VerifiedOpportunities::PAYLOAD_MARKER . '%' ) );
        $this->assertGreaterThan( 100, $tasks );
        $this->assertLessThan( 100, (int) $last['imported'], 'the last batch alone imported fewer items' );
        // The sentence counts every batch of this version, not only the last one.
        $this->assertStringContainsString( sprintf( '(%d new owner tasks', $tasks ), $last['summary'] );
        $this->assertStringContainsString( 'all 595 items processed', $last['summary'] );
        $this->assertMatchesRegularExpression( '/\d+ skipped \(/', $last['summary'], 'skip reasons follow the skipped total' );
        $this->assertSame( 1, substr_count( $last['summary'], 'skipped' ), 'the skipped total is stated once' );

        // A second pass of the same version reports what it found as already present.
        delete_option( VerifiedOpportunities::OPTION_VERSION );
        delete_option( VerifiedOpportunities::OPTION_STATE );
        $jobs->cancelQueued( 'import_verified' );
        $again = $job->handle( [ 'manual' => true ], TimeBudget::seconds( 60 ), $c );
        $this->assertStringNotContainsString( 'new owner task', $again['summary'] );
        $this->assertStringContainsString( 'already present', $again['summary'] );
    }

    public function testReimportKeepsItemsWhoseSiteWasOnlyUnreachable(): void {
        $c    = Container::plugin()->container();
        $job  = new ImportVerifiedJob();
        $jobs = Container::get( Jobs::class );
        $run  = static function () use ( $job, $jobs, $c ): array {
            $last = [ 'more' => true ];
            for ( $i = 0; $i < 12 && $last['more']; $i++ ) {
                $jobs->cancelQueued( 'import_verified' );
                $last = $job->handle( [ 'manual' => true ], TimeBudget::seconds( 60 ), $c );
            }
            return $last;
        };
        $this->assertFalse( $run()['more'] );

        // Two prospects that carry exactly one item each.
        $map   = (array) get_option( VerifiedOpportunities::OPTION_ITEMS, [] );
        $byPid = [];
        foreach ( $map as $itemId => $packed ) {
            $parts                      = explode( ':', (string) $packed ); // hash:submission:prospect
            $byPid[ (int) $parts[2] ][] = (string) $itemId;
        }
        $single = array_keys( array_filter( $byPid, static fn( array $ids ): bool => 1 === count( $ids ) ) );
        $this->assertGreaterThanOrEqual( 2, count( $single ) );
        [ $parked, $refused ] = [ (int) $single[0], (int) $single[1] ];
        $prospects = Container::get( \PPC\LinkGrowth\Repositories\Prospects::class );
        // The evaluator could not fetch one site (machine rejection, re-checked later); the owner rejected the other.
        $prospects->update( $parked, [ 'status' => 'rejected', 'recommended_action' => 'Do not pursue', 'rejection_reason' => 'Site unreachable: HTTP 403' ] );
        $prospects->update( $refused, [ 'status' => 'rejected', 'recommended_action' => 'manual_submission', 'rejection_reason' => 'Not relevant for us' ] );
        $this->assertTrue( \PPC\LinkGrowth\Automation\Jobs\EvaluateJob::isUnreachableRejection( (array) $prospects->find( $parked ) ) );
        $this->assertFalse( \PPC\LinkGrowth\Automation\Jobs\EvaluateJob::isUnreachableRejection( (array) $prospects->find( $refused ) ) );

        // A new data version: every item is looked at again.
        foreach ( [ VerifiedOpportunities::OPTION_VERSION, VerifiedOpportunities::OPTION_STATE, VerifiedOpportunities::OPTION_ITEMS ] as $option ) {
            delete_option( $option );
        }
        $last  = $run();
        $state = (array) get_option( VerifiedOpportunities::OPTION_STATE, [] );
        $this->assertSame( 1, (int) ( $state['skip_reasons']['prospect_rejected'] ?? 0 ), 'only the owner\'s rejection skips its item: ' . $last['summary'] );
        $after = (array) get_option( VerifiedOpportunities::OPTION_ITEMS, [] );
        $this->assertArrayHasKey( $byPid[ $parked ][0], $after, 'the unreachable site keeps its owner task' );
        $this->assertArrayNotHasKey( $byPid[ $refused ][0], $after );
        // The parked prospect keeps the marker the evaluator's automatic re-check looks for.
        $this->assertSame( 'Do not pursue', (string) $prospects->find( $parked )['recommended_action'] );
        $this->assertSame( 'rejected', (string) $prospects->find( $parked )['status'] );
    }

    public function testSummaryStepUsesTheSingularForOne(): void {
        GrowthFakeJob::reset();
        $GLOBALS['ppclg_test_filters']['ppclg_job_types'] = [ static fn() => GrowthFakeJob::registry( [ 'import_verified' ] ) ];
        Container::plugin()->container()->set( StorefrontAudit::class, new FakeStorefront() );
        Container::get( Settings::class )->set( 'automation.submissions', true );

        $now = gmdate( 'Y-m-d H:i:s' );
        Container::get( Submissions::class )->insert( [ 'prospect_id' => 1, 'status' => 'needs_manual', 'target_url' => 'https://dir.test/add', 'target_hash' => sha1( 'dir' ), 'created_at' => $now, 'updated_at' => $now ] );
        Container::get( OutreachMessages::class )->insert( [ 'prospect_id' => 1, 'contact_id' => 1, 'approach' => 'resource_page', 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now ] );

        $growth = Container::get( GrowthRun::class );
        $growth->start( 1 );
        $state = $growth->state();
        for ( $n = 0; $n < 10 && GrowthRun::STATUS_RUNNING === $state['status']; $n++ ) {
            $state = $growth->advance( TimeBudget::seconds( 60.0 ) );
        }
        $summary = '';
        foreach ( $state['steps'] as $step ) {
            if ( 'summary' === $step['key'] ) {
                $summary = (string) $step['summary'];
            }
        }
        $this->assertStringContainsString( '1 owner task waiting.', $summary );
        $this->assertStringContainsString( '1 outreach draft awaits your approval.', $summary );
        $this->assertStringContainsString( '0 live backlinks.', $summary );
        $this->assertStringNotContainsString( '1 outreach drafts', $summary );
    }

    /** @return array<string,mixed> */
    private static function node( string $script ): array {
        $node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
        if ( '' === $node ) {
            self::markTestSkipped( 'node is not installed' );
        }
        $file = tempnam( sys_get_temp_dir(), 'ppclg-e2e' ) . '.mjs';
        file_put_contents( $file, $script );
        try {
            $out = (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $file ) . ' 2>&1' );
        } finally {
            unlink( $file );
        }
        $json = json_decode( $out, true );
        self::assertIsArray( $json, 'node output: ' . $out );
        return $json;
    }

    public function testStepsOfAStoppedRunSayNotRunInsteadOfWaiting(): void {
        $url = json_encode( 'file://' . PPCLG_DIR . 'assets/js/pages/overview.js' );
        $r   = self::node(
            "const m = await import({$url});\n" .
            "const d = ( s, run ) => m.stepDisplay( s, run );\n" .
            "console.log( JSON.stringify( {\n" .
            "  runningPending: d( { status: 'pending' }, 'running' ),\n" .
            "  cancelledPending: d( { status: 'pending' }, 'cancelled' ),\n" .
            "  cancelledRunning: d( { status: 'running' }, 'cancelled' ),\n" .
            "  failedPending: d( { status: 'pending' }, 'failed' ),\n" .
            "  cancelledDone: d( { status: 'done', summary: 'Audited 10 pages.' }, 'cancelled' ),\n" .
            "  completedDone: d( { status: 'done', summary: 'x' }, 'completed' ),\n" .
            "} ) );\n"
        );
        $this->assertSame( 'Waiting', $r['runningPending']['meta']['label'] );
        $this->assertSame( 'Not run', $r['cancelledPending']['meta']['label'] );
        $this->assertSame( 'notrun', $r['cancelledPending']['status'] );
        $this->assertSame( 'Not run: the run was stopped before this step.', $r['cancelledPending']['summary'] );
        $this->assertSame( 'Not finished: the run was stopped during this step.', $r['cancelledRunning']['summary'] );
        $this->assertSame( 'Not run: the run stopped on an error before this step.', $r['failedPending']['summary'] );
        $this->assertSame( 'Done', $r['cancelledDone']['meta']['label'] );
        $this->assertSame( 'Audited 10 pages.', $r['cancelledDone']['summary'] );
        $this->assertSame( 'Done', $r['completedDone']['meta']['label'] );
        $css = (string) file_get_contents( PPCLG_DIR . 'assets/css/admin.css' );
        $this->assertStringContainsString( '.ppclg-grow-step--notrun', $css );
    }

    public function testNeedsYouDropsTheStorefrontItemOnceTheLiveCheckNoLongerPauses(): void {
        $url = json_encode( 'file://' . PPCLG_DIR . 'assets/js/pages/overview.js' );
        $r   = self::node(
            "const m = await import({$url});\n" .
            "const acts = [ { key: 'storefront', label: 'Review the storefront compliance findings' }, { key: 'owner_tasks', label: 'Complete 3 owner tasks' }, null ];\n" .
            "const keys = ( st ) => m.currentNeeds( acts, m.storefrontResolved( st ) ).map( ( a ) => a.key );\n" .
            "console.log( JSON.stringify( {\n" .
            "  pass: keys( { effective: 'pass', blocking: false } ),\n" .
            "  acknowledged: keys( { effective: 'acknowledged', blocking: false } ),\n" .
            "  block: keys( { effective: 'block', blocking: true } ),\n" .
            "  warn: keys( { effective: 'warn', blocking: false } ),\n" .
            "  failedClosed: keys( { effective: 'pass', blocking: true, error: 'The check could not run.' } ),\n" .
            "  unknown: m.currentNeeds( acts, false ).map( ( a ) => a.key ),\n" .
            "} ) );\n"
        );
        $this->assertSame( [ 'owner_tasks' ], $r['pass'] );
        $this->assertSame( [ 'owner_tasks' ], $r['acknowledged'] );
        $this->assertSame( [ 'storefront', 'owner_tasks' ], $r['block'] );
        $this->assertSame( [ 'storefront', 'owner_tasks' ], $r['warn'] );
        $this->assertSame( [ 'storefront', 'owner_tasks' ], $r['failedClosed'], 'a check that could not run still pauses automation' );
        $this->assertSame( [ 'storefront', 'owner_tasks' ], $r['unknown'] );
        // The overview hands the banner's answers (load, Re-check, Acknowledge) to the panel.
        $src = (string) file_get_contents( PPCLG_DIR . 'assets/js/pages/overview.js' );
        $this->assertStringContainsString( 'renderBanner( host, status, { onRecheck: report, onAcknowledge: report } )', $src );
        $this->assertStringContainsString( 'growPanelInstance.setStorefront( st )', $src );
    }

    public function testIntegrationsThatAreNotSetUpSayNotConfiguredNotError(): void {
        foreach ( [ 'overview', 'settings' ] as $page ) {
            $url = json_encode( 'file://' . PPCLG_DIR . 'assets/js/pages/' . $page . '.js' );
            $r   = self::node(
                "const m = await import({$url});\n" .
                "const s = m.providerBadgeState;\n" .
                "console.log( JSON.stringify( {\n" .
                "  gsc: s( { configured: false, enabled: true, error: 'Service account JSON and property required for background use' } ),\n" .
                "  broken: s( { configured: true, enabled: true, error: 'HTTP 401 from the API' } ),\n" .
                "  ready: s( { configured: true, enabled: true, error: '' } ),\n" .
                "  disabled: s( { configured: true, enabled: false } ),\n" .
                "  missing: s( null ),\n" .
                "} ) );\n"
            );
            $this->assertSame( [ 'label' => 'Not configured', 'kind' => 'warning', 'title' => 'Service account JSON and property required for background use' ], $r['gsc'], $page );
            $this->assertSame( [ 'label' => 'Error', 'kind' => 'danger', 'title' => 'HTTP 401 from the API' ], $r['broken'], $page );
            $this->assertSame( 'Ready', $r['ready']['label'], $page );
            $this->assertSame( 'Disabled', $r['disabled']['label'], $page );
            $this->assertSame( 'Unknown', $r['missing']['label'], $page );
        }
    }
}
