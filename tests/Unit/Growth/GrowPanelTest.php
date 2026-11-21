<?php
/**
 * assets/js/pages/overview.js "Grow your site" panel: static guards always run; the loop behaviour (auto-resume,
 * retry twice then Resume, refused start, compliance banner loading) runs under node with a fake DOM and fetch
 * (GrowPanelHarness.mjs), skipped when node is not installed.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Growth;

use PHPUnit\Framework\TestCase;

final class GrowPanelTest extends TestCase {

    /** @var array<string,mixed>|null */
    private static ?array $result = null;

    private static string $skip = '';

    private static function source(): string {
        $src = file_get_contents( PPCLG_DIR . 'assets/js/pages/overview.js' );
        self::assertIsString( $src );
        return $src;
    }

    /** @return array<string,mixed> */
    private function harness(): array {
        if ( null === self::$result && '' === self::$skip ) {
            $node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
            if ( '' === $node ) {
                self::$skip = 'node is not installed';
            } else {
                $cmd  = escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/GrowPanelHarness.mjs' ) . ' ' . escapeshellarg( rtrim( PPCLG_DIR, '/' ) ) . ' 2>&1';
                $out  = (string) shell_exec( $cmd );
                $json = json_decode( $out, true );
                if ( ! is_array( $json ) ) {
                    $this->fail( 'Grow panel harness failed: ' . $out );
                }
                self::$result = $json;
            }
        }
        if ( '' !== self::$skip ) {
            $this->markTestSkipped( self::$skip );
        }
        return (array) self::$result;
    }

    public function testPanelUsesDomHelpersAndPlainWording(): void {
        $src = self::source();
        $this->assertStringNotContainsString( 'innerHTML', $src );
        $this->assertStringNotContainsString( 'insertAdjacentHTML', $src );
        $this->assertStringContainsString( "api.post( '/growth/start'", $src );
        $this->assertStringContainsString( "api.post( '/growth/step'", $src );
        $this->assertStringContainsString( "api.get( '/growth/status'", $src );
        $this->assertStringContainsString( "import( '../compliance-banner.js' )", $src );
        $this->assertStringContainsString( "'Grow your site'", $src );
        $this->assertStringContainsString( "'Grow now'", $src );
        // The panel's own copy (the pre-existing "Integration health" card title is about API integrations).
        $start = strpos( $src, 'const GROW_EXPLAIN' );
        $end   = strpos( $src, 'let growPanelInstance' );
        $this->assertNotFalse( $start );
        $this->assertNotFalse( $end );
        $panel = substr( $src, (int) $start, (int) $end - (int) $start );
        $this->assertDoesNotMatchRegularExpression( '/\b(health|medical|pharmacy|supplements?|wellness)\b/i', $panel, 'no health/medical framing in the panel' );
        $this->assertDoesNotMatchRegularExpression( '/\b(medical|pharmacy|supplements?|wellness)\b/i', $src );
    }

    public function testAnActiveRunResumesOnLoadAndShowsResults(): void {
        $r = $this->harness()['resume'];
        $this->assertSame( [ 'GET /growth/status', 'POST /growth/step', 'POST /growth/step' ], $r['requests'] );
        $this->assertSame( 'completed', $r['finishedStatus'] );
        $this->assertTrue( $r['hasNeedsYou'] );
        $this->assertTrue( $r['hasWhatHappened'] );
        $this->assertTrue( $r['hasStepSummary'] );
        $this->assertTrue( $r['hasHeadline'] );
        $this->assertTrue( $r['unknownForMissing'], 'missing numbers say "not available", never 0' );
        $this->assertContains( 'https://shop.example/wp-admin/admin.php?page=ppclg-submissions#tab=needs_manual', array_column( $r['links'], 'href' ) );
        $this->assertSame( [ 'Grow now' ], $r['buttons'] );
        $this->assertFalse( $r['busy'] );
    }

    public function testRequestErrorsAreRetriedTwiceThenResumeContinues(): void {
        $r = $this->harness()['retry'];
        $this->assertSame( 3, $r['stepCallsBeforeResume'], 'one request + two retries' );
        $this->assertSame( [ 'Resume', 'Stop' ], $r['buttons'], 'the run still runs on the server, so it stays stoppable' );
        $this->assertTrue( $r['serverError'], 'an HTTP 502 is the server answering with an error, with a pointer to the Logs' );
        $this->assertFalse( $r['lostContact'], 'the server did answer' );
        $this->assertTrue( $r['logsLink'] );
        $this->assertSame( 4, $r['afterResume']['stepCalls'] );
        $this->assertTrue( $r['afterResume']['done'] );
        $this->assertSame( [ 'Grow now' ], $r['afterResume']['buttons'] );
    }

    public function testGrowNowStartsAndLoopsAndARefusalIsReported(): void {
        $h = $this->harness();
        $this->assertSame( [ 'Grow now' ], $h['start']['idleButtons'] );
        $this->assertTrue( $h['start']['explains'], 'the one-line explanation says what the run never does' );
        $this->assertFalse( $h['start']['promisesApproval'], 'no approval promise the default settings do not keep' );
        $this->assertTrue( $h['start']['neutralPolicy'], 'without the settings the subtitle makes no promise either way' );
        $this->assertSame( [ 'GET /growth/status', 'POST /growth/start', 'POST /growth/step' ], $h['start']['requests'] );
        $this->assertSame( [ 'GET /growth/status', 'POST /growth/start' ], $h['refused']['requests'], 'no step after a refused start' );
        $this->assertTrue( $h['refused']['toast'], 'the refusal message is shown' );
        $this->assertSame( [ 'Grow now' ], $h['refused']['buttons'] );
    }

    public function testErrorKindsGetMatchingAdviceAndStopStaysAvailable(): void {
        $h = $this->harness();
        $this->assertSame( [ 'Resume', 'Stop' ], $h['network']['buttons'] );
        $this->assertTrue( $h['network']['lostContact'], 'no answer at all is "lost contact"' );

        $this->assertSame( 1, $h['session']['stepCalls'], 'an expired session is not retried' );
        $this->assertSame( [ 'Reload the page' ], $h['session']['buttons'], 'no Resume or Stop with a dead nonce' );
        $this->assertTrue( $h['session']['expired'] );
        $this->assertFalse( $h['session']['lostContact'] );
        $this->assertSame( 1, $h['session']['reloaded'] );

        $stop = $h['stopFromError'];
        $this->assertSame( [ 'Resume', 'Stop' ], $stop['errorButtons'] );
        $this->assertTrue( $stop['confirmShown'] );
        $this->assertTrue( $stop['cancelled'], 'Stop sends POST /growth/cancel from the error view' );
        $this->assertSame( [ 'Grow now' ], $stop['buttons'] );
        $this->assertTrue( $stop['errorGone'], 'the stale error callout goes away after a successful stop' );
        $this->assertTrue( $stop['stopped'] );
    }

    public function testProgressIsAnnouncedAndFocusSurvivesRedraws(): void {
        $a = $this->harness()['a11y'];
        $this->assertSame( 'Growth run progress', $a['progressbar']['aria-label'] ?? null );
        $this->assertSame( '1 of 4 steps finished', $a['progressbar']['aria-valuetext'] ?? null );
        $this->assertSame( 1, $a['liveCount'] );
        $this->assertTrue( $a['liveSameNode'], 'the live region is not re-created on redraw' );
        $this->assertSame( '1 of 4 steps finished, now: Step site_audit.', $a['liveTextBefore'] );
        $this->assertSame( '2 of 4 steps finished, now: Step submit.', $a['liveTextAfter'] );
        $this->assertSame( 0, $a['stepsListLive'], 'the redrawn step list is no live region' );
        $this->assertTrue( $a['stopRedrawn'] );
        $this->assertTrue( $a['focusOnStop'], 'keyboard focus moves to the redrawn Stop button' );
        $this->assertSame( 'Grow now', $a['focusAfterFinish'] );
        $this->assertSame( 'Growth run completed.', $a['liveTextFinished'] );
    }

    public function testSubtitleStatesWhatTheRunSendsFromTheSettings(): void {
        $p = $this->harness()['policy'];
        $this->assertStringContainsString( 'Listings to sites that pass every safety check are submitted automatically', $p['auto'] );
        $this->assertStringContainsString( 'outreach emails are sent only after your approval', $p['auto'] );
        $this->assertStringContainsString( 'outreach emails to qualified prospects are sent automatically', $p['autosend'] );
        $this->assertStringContainsString( 'sent only after your approval', $p['autosendWithApproval'] );
        $this->assertStringContainsString( 'Automated submissions are off', $p['off'] );
        $this->assertStringContainsString( 'Always allow', $p['authorizedOnly'] );
        $this->assertStringNotContainsString( 'approval', $p['unknown'] );
        $this->assertTrue( $p['rendered'] );
    }

    public function testAuditHeadlineNeverShowsZeroForAnAuditThatDidNotRun(): void {
        $a = $this->harness()['audit'];
        $this->assertTrue( $a['notRunYet'] );
        $this->assertTrue( $a['noZero'] );
        $this->assertTrue( $a['partial'] );
        $f = $this->harness()['fallback'];
        $this->assertTrue( $f['paused'], 'the fallback banner shows a check that could not run despite an older pass' );
        $this->assertTrue( $f['passEmpty'] );
    }

    public function testComplianceBannerIgnores404AndRendersABlockingStatus(): void {
        $b = $this->harness()['banner'];
        $this->assertTrue( $b['emptyOn404'] );
        $this->assertTrue( $b['blockShown'] );
        $this->assertTrue( $b['legal'] );
    }

    // ------------------------------------------------------------------
    // 1.2.4: server pressure
    // ------------------------------------------------------------------

    public function testAPausedStepShowsThePlainReasonWaitsAndThenContinues(): void {
        $p = $this->harness()['paused'];
        $this->assertTrue( $p['sawNote'], '"Paused: a backup is running (resumes automatically)." is shown' );
        $this->assertSame( 1, $p['stepsWhilePaused'], 'no second step request while the pause delay runs' );
        $this->assertSame( 'completed', $p['finishedStatus'] );
        $this->assertSame( 2, $p['stepCalls'] );
        $this->assertTrue( $p['noError'], 'a pause is not an error' );

        $h = $this->harness()['helpers'];
        $this->assertSame( 60000, $h['delayRetryAfter'], 'paused: the server\'s retry_after (60 s), not every 3 s' );
        $this->assertSame( 300000, $h['delayCapped'] );
        $this->assertSame( 3000, $h['delayNotPaused'] );
        $this->assertSame( 3000, $h['delayNoRetry'] );
        $this->assertSame( 'Paused: a backup is running (resumes automatically).', $h['pausedText'] );
        $this->assertSame( 'Paused: a backup is running (resumes automatically).', $h['pausedFromMessage'] );
        $this->assertSame( '', $h['pausedNone'] );
        $this->assertTrue( $h['detailOwner'] );
        $this->assertTrue( $h['detailBackup'] );
    }

    public function testStopDuringAPauseFreesThePanelAtOnce(): void {
        // The page's own delays: a paused step waits retry_after (60 s) before the next request.
        $p = $this->harness()['stopWhilePaused'];
        $this->assertTrue( $p['sawNote'] );
        $this->assertTrue( $p['confirmShown'] );
        $this->assertTrue( $p['cancelled'] );
        $this->assertTrue( $p['freed'], 'Stop ends the minute-long wait: the panel offers "Grow now" again within 2 s' );
        $this->assertFalse( $p['busy'] );
        $this->assertSame( [ 'Grow now' ], $p['buttons'] );
        $this->assertTrue( $p['stopped'], 'the stopped run is shown' );
        $this->assertSame( 1, $p['stepCalls'], 'no step request for the stopped run' );
        $this->assertSame( 'cancelled', $p['finishedStatus'] );
        $this->assertSame( [ 'POST /growth/start', 'POST /growth/step' ], $p['restart']['requests'], 'a new run starts at once' );
        $this->assertSame( 'completed', $p['restart']['finishedStatus'] );
    }

    public function testTheOverviewShowsTheOpcacheNoticeOnlyWhenCritical(): void {
        $o = $this->harness()['opcache'];
        $this->assertTrue( $o['rendered'] );
        $this->assertTrue( $o['danger'] );
        $this->assertTrue( $o['title'] );
        $this->assertSame( [ 'opcache.memory_consumption=256', 'opcache.interned_strings_buffer=32', 'opcache.max_accelerated_files=32531' ], $o['codes'] );
        $this->assertTrue( $o['nowValues'] );
        $this->assertTrue( $o['restart'] );
        $this->assertTrue( $o['host'] );
        $this->assertTrue( $o['recommendedIsNull'] );
        $this->assertTrue( $o['nullIsNull'] );

        $src = self::source();
        $this->assertStringContainsString( "from '../server-pressure.js'", $src );
        $this->assertStringContainsString( 'opcacheCallout( data ? data.opcache : null )', $src );
        $this->assertStringContainsString( "badge( 'Paused', 'warning'", $src );
        $automation = (string) file_get_contents( PPCLG_DIR . 'assets/js/pages/automation.js' );
        $this->assertStringContainsString( 'callout( \'warning\', pausedText( s.paused ), pausedDetail( s.paused ) )', $automation );
        $this->assertStringContainsString( 'r.warning', $automation, 'Run now shows the pause warning' );
        $this->assertStringNotContainsString( 'innerHTML', (string) file_get_contents( PPCLG_DIR . 'assets/js/server-pressure.js' ) );
    }

    public function testTheDashboardShowsTheLoadAndMemoryNumbersAndTheRamLine(): void {
        $p = $this->harness()['pressure'];
        $this->assertSame( 'Paused: the server is busy (load 6.53 on 1 CPU) — resumes when it calms down.', $p['busyText'] );
        $this->assertStringStartsWith( 'Load over the last minute: 6.53 on 1 CPU, 6.53 per CPU; background work waits while it is above 2 per CPU. The scheduler tick', $p['busyDetail'] );
        $this->assertStringContainsString( 'nothing is lost', $p['busyDetail'] );
        $this->assertStringStartsWith( 'Load over the last minute: 2.5 on 1 CPU (the CPU count could not be read, so it counts as 1), 2.5 per CPU;', $p['busyUnknownCpus'] );
        $this->assertStringStartsWith( 'Load over the last minute: 8.4 on 4 CPUs, 2.1 per CPU;', $p['busyFourCpus'] );
        $this->assertSame( 'Paused: the server is low on free memory — resumes when memory frees up.', $p['memoryText'] );
        $this->assertStringStartsWith( 'Free memory: 88 MB of 960 MB (9.2%); background work waits while less than 10% is free. The scheduler tick', $p['memoryDetail'] );
        $this->assertStringStartsWith( 'The scheduler tick', $p['noNumbers'], 'incomplete numbers: the plain detail only' );
        $this->assertStringStartsWith( 'The scheduler tick', $p['backupDetail'] );

        $line = 'This server has 960 MB of memory; a bigger code cache takes memory from PHP and the database, so more RAM is the lasting fix.';
        $this->assertSame( $line, $p['noticeMemory'] );
        $this->assertSame( '', $p['noticeNoMemory'] );
        $this->assertTrue( $p['calloutMemory'], 'the Overview notice shows the RAM line' );
        $this->assertSame( [ 'opcache.memory_consumption=160' ], $p['calloutCodes'], 'the RAM line is not a setting' );
        $this->assertTrue( $p['calloutWithoutMemory'], 'no RAM line when the server does not have one' );
    }
}
