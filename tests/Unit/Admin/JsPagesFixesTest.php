<?php
/**
 * Regression tests for the js-pages slice fixes (assets/js/pages/*.js):
 *
 * - js-pages-1  Settings saves only the leaves the owner changed; master/submissions/autosend go through
 *               /automation/toggle (with confirmation for the gated ones), so a stale tab cannot re-enable them.
 * - js-pages-2  Autosend consent and page copy say that initial auto-drafted emails are sent without review.
 * - js-pages-3  The per-site automation switch copy matches Policy, and the drawer shows the effective mode.
 * - js-pages-5  Refused campaign actions are reported with the server's reason, never as done.
 * - js-pages-6  Run/action buttons report skipped, queued, failed and partially failed runs truthfully.
 * - js-pages-8  An emptied number field keeps the saved value; setting a rate limit to 0 asks first.
 * - js-pages-9  Secrets the server rejects are reported and kept in their field.
 * - js-pages-10 The audit "object type" filter uses the type outreach messages are recorded with.
 *
 * Static guards always run. The pure helpers run under node (skipped without node); the page behaviour runs
 * in headless Chrome through Playwright (JsPagesFixesHarness.mjs), skipped when Playwright or a browser is
 * missing. PPCLG_PLAYWRIGHT / PPCLG_CHROME override the module and browser paths.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class JsPagesFixesTest extends TestCase {

    /** @var array<string,mixed>|null */
    private static ?array $browser = null;

    private static string $browserSkip = '';

    /** @var array<string,mixed>|null */
    private static ?array $helpers = null;

    private static string $helpersSkip = '';

    private static function page( string $name ): string {
        $source = file_get_contents( PPCLG_DIR . 'assets/js/pages/' . $name );
        self::assertIsString( $source );
        return $source;
    }

    // ------------------------------------------------------------------ static guards

    public function testSettingsSaveSendsLeavesAndRoutesAutomationFlagsThroughTheToggle(): void {
        $src = self::page( 'settings.js' );
        $this->assertStringNotContainsString( 'changed[ key ] = state.draft[ key ]', $src, 'whole sections must not be re-sent' );
        $this->assertStringContainsString( 'changedLeaves( state.draft, state.original )', $src );
        $this->assertStringContainsString( "const TOGGLE_FLAGS = [ 'enabled', 'submissions', 'outreach_autosend' ];", $src );
        $this->assertStringContainsString( "api.post( '/automation/toggle'", $src );
    }

    public function testEmptiedNumberFieldsNoLongerSaveZero(): void {
        $src = self::page( 'settings.js' );
        $this->assertStringNotContainsString( "next = v === null ? ( spec.default !== undefined ? spec.default : 0 ) : v;", $src );
        $this->assertStringContainsString( 'getPath( state.original, path,', $src );
        $this->assertStringContainsString( 'limitsRemoved( changed )', $src );
    }

    public function testSettingsReadsRejectedAndLockedSecrets(): void {
        $src = self::page( 'settings.js' );
        $this->assertStringContainsString( 'secretResult.rejected', $src );
        $this->assertStringContainsString( 'secretResult.locked', $src );
        $this->assertStringContainsString( 'await load( { keepSecrets } )', $src );
    }

    public function testAutosendCopyNoLongerPromisesReviewOfInitialMessages(): void {
        $automation = self::page( 'automation.js' );
        $this->assertStringNotContainsString( 'Initial messages always require approval', $automation );
        $this->assertStringNotContainsString( 'are off by default', $automation );
        $this->assertStringContainsString( 'without your review', $automation );
        $outreach = self::page( 'outreach.js' );
        $this->assertStringNotContainsString( 'Every message is reviewed and approved before it is sent', $outreach );
        $this->assertStringContainsString( 'sender.autosend === true', $outreach );
    }

    public function testPerSiteSwitchCopyMatchesThePolicy(): void {
        $src = self::page( 'prospects.js' );
        $this->assertStringNotContainsString( 'Everything else always waits for your approval', $src );
        $this->assertStringNotContainsString( 'Packages wait for your approval unless the site is explicitly allowed for automation', $src );
        $this->assertStringContainsString( 'export function submissionModeNotice(', $src );
    }

    public function testActionButtonsReadTheResponse(): void {
        $this->assertStringContainsString( "reportRun( res, 'Evaluation' )", self::page( 'prospects.js' ) );
        $this->assertStringContainsString( "reportRun( res, 'Import'", self::page( 'backlinks.js' ) );
        $this->assertStringContainsString( 'reportRun( res, def.label )', self::page( 'automation.js' ) );
        $submissions = self::page( 'submissions.js' );
        foreach ( [ 'rebuild', 'retry', 'reject', 'mark-submitted' ] as $action ) {
            $this->assertMatchesRegularExpression( '#reportAction\( await api\.post\( `/submissions/\$\{ s\.id \}/' . preg_quote( $action, '#' ) . '`#', $submissions, $action );
        }
        $this->assertStringNotContainsString( "toast( 'Package rebuilt.', 'success' )", $submissions );
        $outreach = self::page( 'outreach.js' );
        $this->assertStringContainsString( 'reportAction( res, `Campaign', $outreach );
    }

    public function testAuditFilterUsesTheRecordedObjectType(): void {
        $src = self::page( 'logs.js' );
        $this->assertStringContainsString( "outreach_message: 'Outreach message'", $src );
        $this->assertStringNotContainsString( "message: 'Message'", $src );
    }

    // ------------------------------------------------------------------ node checks (pure helpers)

    /** js-pages-1 */
    public function testStaleTabDiffContainsOnlyTheEditedLeaf(): void {
        $r = $this->helpers();
        $this->assertSame( [ 'automation' => [ 'daily_limits' => [ 'evaluations' => 25 ] ] ], $r['staleDiff'] );
        $this->assertSame( [ [ 'enabled', false ], [ 'outreach_autosend', false ] ], $r['flags'] );
        $this->assertSame( [ 'site' => [ 'social' => [] ] ], $r['flagDiffAfter'], 'flags leave the /settings body; lists are sent whole' );
    }

    /** js-pages-8 */
    public function testRemovedLimitsAreDetected(): void {
        $r = $this->helpers();
        $this->assertSame( [ 'Daily submissions', 'Weekly submissions' ], $r['removed'] );
        $this->assertSame( [], $r['removedNone'] );
    }

    /** js-pages-6 */
    public function testRunOutcomesAreClassifiedTruthfully(): void {
        $runs = $this->helpers()['runs'];
        $this->assertSame( [ 'skipped', 'warning' ], [ $runs['skipped']['state'], $runs['skipped']['kind'] ] );
        $this->assertStringContainsString( 'daily evaluation limit', $runs['skipped']['message'] );
        $this->assertSame( [ 'queued', 'info' ], [ $runs['duplicate']['state'], $runs['duplicate']['kind'] ] );
        $this->assertStringContainsString( 'already queued', $runs['duplicate']['message'] );
        $this->assertStringContainsString( 'job #7', $runs['queued']['message'] );
        $this->assertSame( [ 'failed', 'error' ], [ $runs['failed']['state'], $runs['failed']['kind'] ] );
        $this->assertStringContainsString( 'Boom', $runs['failed']['message'] );
        $this->assertSame( 'warning', $runs['importErrors']['kind'] );
        $this->assertStringContainsString( 'Daily provider lookup limit reached', $runs['importErrors']['message'] );
        $this->assertStringContainsString( 'a.example: Timeout', $runs['itemError']['message'] );
        $this->assertSame( 'success', $runs['countSkipped']['kind'], 'a numeric "skipped" count is not a skipped run' );
        $this->assertSame( [ 'finished', 'success', 'Evaluation finished.' ], array_values( $runs['finished'] ) );
    }

    // ------------------------------------------------------------------ browser checks

    /** js-pages-1 */
    public function testBrowserStaleSettingsTabSendsOnlyTheEditedLimit(): void {
        $r = $this->browser( 'settingsStale' );
        $this->assertSame( [ [ 'settings' => [ 'automation' => [ 'daily_limits' => [ 'evaluations' => 25 ] ] ] ] ], $r['settingsBodies'] );
        $this->assertSame( [], $r['toggles'], 'untouched automation flags are not re-sent' );
        $this->assertSame( [], $r['errors'] );
    }

    /** js-pages-1 */
    public function testBrowserGatedFlagFromSettingsNeedsConfirmationAndUsesTheToggle(): void {
        $r = $this->browser( 'settingsGate' );
        $this->assertCount( 1, $r['declinedModal'] );
        $this->assertStringContainsString( 'without your review', $r['declinedModal'][0] );
        $this->assertSame( 0, $r['afterDecline']['settings'] );
        $this->assertSame( 0, $r['afterDecline']['toggles'] );
        $this->assertSame( [], $r['settingsBodies'], 'the flag is not written through POST /settings' );
        $this->assertSame( [ [ 'key' => 'outreach_autosend', 'value' => true, 'confirm' => true ] ], $r['toggles'] );
        $this->assertSame( [], $r['errors'] );
    }

    /** js-pages-8 */
    public function testBrowserEmptiedLimitIsUnchangedAndZeroAsksFirst(): void {
        $r = $this->browser( 'settingsLimits' );
        $this->assertTrue( $r['cleared']['saveDisabled'], 'an emptied field is no change' );
        $this->assertStringContainsString( 'No unsaved changes', $r['cleared']['dirtyLabel'] );
        $this->assertCount( 1, $r['zeroModal'] );
        $this->assertStringContainsString( 'Daily submissions', $r['zeroModal'][0] );
        $this->assertSame( 0, $r['posts'], 'declining keeps the cap' );
        $this->assertSame( [], $r['errors'] );
    }

    /** js-pages-9 */
    public function testBrowserRejectedSecretIsReportedAndKept(): void {
        $r = $this->browser( 'settingsSecrets' );
        $this->assertSame( [ [ 'google_service_account_json' ] ], $r['sentSecrets'] );
        $texts = array_column( $r['toasts'], 'text' );
        $this->assertNotContains( 'Settings saved.', $texts );
        $this->assertSame( 'error', $r['toasts'][0]['kind'] );
        $this->assertStringContainsString( 'Not saved: Google service account JSON', $r['toasts'][0]['text'] );
        $this->assertSame( '{"type":"service_account"}', $r['keptValue'] );
        $this->assertStringContainsString( 'You have unsaved changes', $r['dirtyLabel'] );
        $this->assertSame( [], $r['errors'] );
    }

    /** js-pages-2 */
    public function testBrowserAutosendConsentDescribesUnreviewedInitialEmails(): void {
        $r = $this->browser( 'automationConsent' );
        $this->assertStringContainsString( 'first-contact emails', $r['desc'] );
        $this->assertStringNotContainsString( 'Initial messages always require approval', $r['desc'] );
        $this->assertStringContainsString( 'Initial cold emails', $r['modal'][0] );
        $this->assertStringContainsString( 'without your review', $r['modal'][0] );
        $this->assertStringNotContainsString( 'off by default', $r['header'] );
        $this->assertSame( 0, $r['toggles'], 'cancelling the confirmation sends nothing' );
        $this->assertSame( [], $r['errors'] );
    }

    /** js-pages-3 + js-pages-6 */
    public function testBrowserProspectDrawerShowsEffectiveModeAndHonestEvaluateResults(): void {
        $r = $this->browser( 'prospects' );
        $this->assertStringContainsString( 'Submission mode: Auto', $r['modeNotice'] );
        $this->assertStringContainsString( 'while its per-site setting below is “Inherit”', $r['modeNotice'] );
        $this->assertStringContainsString( 'Never automate', $r['modeNotice'] );
        $this->assertStringNotContainsString( 'Everything else always waits for your approval', $r['modeNotice'] );
        $this->assertSame( [ [ 'kind' => 'warning', 'text' => 'Evaluation did not run: the daily evaluation limit is used up.' ] ], $r['evaluate'][0] );
        $this->assertSame( 'info', $r['evaluate'][1][0]['kind'] );
        $this->assertStringContainsString( 'already queued', $r['evaluate'][1][0]['text'] );
        $this->assertSame( [ [ 'kind' => 'success', 'text' => 'Evaluation finished.' ] ], $r['evaluate'][2] );
        $this->assertSame( [], $r['errors'] );
    }

    /** js-pages-5 + js-pages-2 */
    public function testBrowserRefusedCampaignApprovalIsNotReportedAsApproved(): void {
        $r = $this->browser( 'outreach' );
        $this->assertSame( 2, $r['approvals'] );
        foreach ( $r['approve'] as $i => $toasts ) {
            $texts = array_column( $toasts, 'text' );
            $this->assertNotContains( 'Campaign approved.', $texts, "attempt $i" );
            $this->assertSame( 'error', $toasts[0]['kind'], "attempt $i" );
            $this->assertStringContainsString( 'could not be approved', $toasts[0]['text'], "attempt $i" );
        }
        $this->assertStringNotContainsString( 'Every message is reviewed and approved before it is sent', $r['header'] );
        $this->assertNotEmpty( array_filter( $r['banner'], static fn( string $t ): bool => str_contains( $t, 'Outreach autosend is on' ) ) );
        $this->assertSame( [], $r['errors'] );
    }

    /** js-pages-6 */
    public function testBrowserRefusedRebuildAndFailedImportAreReported(): void {
        $sub = $this->browser( 'submissions' );
        $this->assertSame( [ [ 'kind' => 'error', 'text' => 'The package could not be rebuilt (submitted or confirmed submissions are locked).' ] ], $sub['toasts'] );
        $this->assertSame( [], $sub['errors'] );
        $imp = $this->browser( 'backlinks' );
        $this->assertSame( 'warning', $imp['toasts'][0]['kind'] );
        $this->assertStringContainsString( 'Daily provider lookup limit reached', $imp['toasts'][0]['text'] );
        $this->assertSame( [], $imp['errors'] );
    }

    /** js-pages-10 */
    public function testBrowserAuditFilterSendsOutreachMessage(): void {
        $r = $this->browser( 'logs' );
        $this->assertContains( 'outreach_message', $r['options'] );
        $this->assertNotContains( 'message', $r['options'] );
        $this->assertSame( 'outreach_message', end( $r['audit'] ) );
        $this->assertSame( [], $r['errors'] );
    }

    // ------------------------------------------------------------------ runners

    /** @return array<string,mixed> */
    private function helpers(): array {
        if ( null === self::$helpers && '' === self::$helpersSkip ) {
            $node = self::which( 'node' );
            if ( null === $node ) {
                self::$helpersSkip = 'node is not installed';
            } else {
                [ $code, $out, $err ] = self::exec( [ $node, __DIR__ . '/JsPagesFixesHelpers.mjs', PPCLG_DIR ], 60 );
                $data = json_decode( $out, true );
                if ( ! is_array( $data ) ) {
                    self::fail( "Node helper checks failed (exit $code): " . substr( $err . $out, 0, 2000 ) );
                }
                self::$helpers = $data;
            }
        }
        if ( null === self::$helpers ) {
            $this->markTestSkipped( self::$helpersSkip );
        }
        return self::$helpers;
    }

    /** @return array<string,mixed> */
    private function browser( string $scenario ): array {
        if ( null === self::$browser && '' === self::$browserSkip ) {
            self::runHarness();
        }
        if ( null === self::$browser ) {
            $this->markTestSkipped( self::$browserSkip );
        }
        $this->assertArrayHasKey( $scenario, self::$browser );
        $r = self::$browser[ $scenario ];
        $this->assertArrayNotHasKey( 'failed', $r, (string) ( $r['failed'] ?? '' ) );
        return $r;
    }

    private static function runHarness(): void {
        $node = self::which( 'node' );
        if ( null === $node ) {
            self::$browserSkip = 'node is not installed';
            return;
        }
        $playwright = self::playwright( $node );
        if ( null === $playwright ) {
            self::$browserSkip = 'Playwright is not installed (set PPCLG_PLAYWRIGHT)';
            return;
        }
        $chrome = (string) getenv( 'PPCLG_CHROME' );
        if ( '' === $chrome && is_executable( '/usr/bin/google-chrome' ) ) {
            $chrome = '/usr/bin/google-chrome';
        }
        $cmd = [ $node, __DIR__ . '/JsPagesFixesHarness.mjs', PPCLG_DIR, $playwright ];
        if ( '' !== $chrome ) {
            $cmd[] = $chrome;
        }
        [ $code, $out, $err ] = self::exec( $cmd, 240 );
        $data = json_decode( $out, true );
        if ( ! is_array( $data ) ) {
            self::fail( "Browser harness failed (exit $code): " . substr( $err . $out, 0, 2000 ) );
        }
        if ( isset( $data['skip'] ) ) {
            self::$browserSkip = (string) $data['skip'];
            return;
        }
        self::$browser = (array) ( $data['results'] ?? [] );
    }

    private static function which( string $binary ): ?string {
        foreach ( explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ) as $dir ) {
            $path = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $binary;
            if ( '' !== $dir && is_file( $path ) && is_executable( $path ) ) {
                return $path;
            }
        }
        return null;
    }

    private static function playwright( string $node ): ?string {
        $env = (string) getenv( 'PPCLG_PLAYWRIGHT' );
        if ( '' !== $env ) {
            return $env;
        }
        if ( is_dir( '/opt/node-tools/node_modules/playwright' ) ) {
            return '/opt/node-tools/node_modules/playwright';
        }
        [ $code, $out ] = self::exec( [ $node, '-e', "process.stdout.write(require.resolve('playwright'))" ], 20 );
        return 0 === $code && '' !== trim( $out ) ? trim( $out ) : null;
    }

    /**
     * Runs a command without a shell, with a deadline.
     *
     * @param list<string> $cmd
     * @return array{0:int,1:string,2:string}
     */
    private static function exec( array $cmd, int $timeout ): array {
        $dir  = sys_get_temp_dir();
        $out  = (string) tempnam( $dir, 'ppclg-jsp-out' );
        $err  = (string) tempnam( $dir, 'ppclg-jsp-err' );
        $proc = proc_open( $cmd, [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', $out, 'w' ], 2 => [ 'file', $err, 'w' ] ], $pipes );
        if ( ! is_resource( $proc ) ) {
            return [ -1, '', 'proc_open failed' ];
        }
        fclose( $pipes[0] );
        $deadline = microtime( true ) + $timeout;
        $code     = -1;
        while ( true ) {
            $status = proc_get_status( $proc );
            if ( ! $status['running'] ) {
                $code = (int) $status['exitcode'];
                break;
            }
            if ( microtime( true ) > $deadline ) {
                proc_terminate( $proc, 9 );
                break;
            }
            usleep( 50000 );
        }
        proc_close( $proc );
        $stdout = (string) file_get_contents( $out );
        $stderr = (string) file_get_contents( $err );
        @unlink( $out );
        @unlink( $err );
        return [ $code, $stdout, $stderr ];
    }
}
