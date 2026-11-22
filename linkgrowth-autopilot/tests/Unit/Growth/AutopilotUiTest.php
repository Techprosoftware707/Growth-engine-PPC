<?php
/**
 * Browser checks for the 1.2.0 Build B UI (AutopilotUiHarness.mjs, headless Chrome via Playwright; skipped when node,
 * Playwright or a browser is missing; LGA_PLAYWRIGHT / LGA_CHROME override the paths):
 *
 * - The Autopilot switch sits in the "Grow your site" panel; its confirm dialog lists in plain words what runs
 *   automatically (emails per day, internal links per day, open-form submissions, discover/evaluate/monitor) and the
 *   hard limits; once on, the panel says "Autopilot is on — nothing needs your approval", the step labels and tiles
 *   drop "for your approval" / "applied only by you", and no second Autopilot card is shown on the Overview.
 * - On-site SEO shows "Applied automatically: N" with "Undo automatic links" (asks first, posts confirm=true).
 * - Settings → Discovery lists the automatically found competitors apart from the owner's and lets them be removed;
 *   Settings → Automation has the automatic-link switch and daily number.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Growth;

use PHPUnit\Framework\TestCase;
use LinkGrowth\Autopilot\Automation\Autopilot;

final class AutopilotUiTest extends TestCase {

    /** @var array<string,mixed>|null */
    private static ?array $browser = null;

    private static string $browserSkip = '';

    public function testStaticGuards(): void {
        $overview = (string) file_get_contents( LGA_DIR . 'assets/js/pages/overview.js' );
        $this->assertStringNotContainsString( 'autopilotCard(', $overview, 'the Overview shows the switch inside the Grow panel, not as a second card' );
        $this->assertStringContainsString( 'setAutopilot: ( status, onChange )', $overview );
        $this->assertStringContainsString( "'/seo/links/undo-auto'", (string) file_get_contents( LGA_DIR . 'assets/js/pages/seo.js' ) );
        $settings = (string) file_get_contents( LGA_DIR . 'assets/js/pages/settings.js' );
        foreach ( [ "'discovery.competitor_domains_auto'", "'seo.auto_apply_links'", "'seo.auto_apply_per_day'" ] as $needle ) {
            $this->assertStringContainsString( $needle, $settings );
        }
    }

    public function testTheGrowPanelCarriesTheAutopilotSwitchAndSaysNothingNeedsApproval(): void {
        $r = $this->browser( 'growPanel' );
        $this->assertSame( 0, $r['separateCards'], 'no separate Autopilot card on the Overview' );
        $this->assertStringContainsString( 'Autopilot: let the engine work without asking me', (string) $r['before'] );
        $this->assertStringContainsString( 'Suggest internal links (applied only by you)', (string) $r['before'] );
        $dialog = implode( "\n", $r['dialog'] );
        foreach ( [ 'What runs automatically:', 'up to 10 a day and 40 a week', 'Add up to 20 internal links a day', 'open public forms that allow it', 'Discover new prospects, evaluate them and monitor your backlinks', 'These limits always stay on:', 'No account creation or sign-ups on third-party sites.', 'LinkGrowth Submitter role' ] as $needle ) {
            $this->assertStringContainsString( $needle, $dialog );
        }
        // 1.2.2: the text the owner confirms covers saving as their account and taking automatic links back out.
        $this->assertStringContainsString( 'it saves that one change as your account, and it takes its own automatic links back out of pages whose template doesn’t display them', $dialog );
        $this->assertSame( [ [ 'enabled' => true, 'confirm' => true ] ], $r['posts'] );
        $after = (string) $r['after'];
        $this->assertStringContainsString( 'Autopilot is on — nothing needs your approval', $after );
        $this->assertStringContainsString( 'Add the best internal links automatically', $after );
        $this->assertStringContainsString( 'Draft and approve outreach (Autopilot sends it)', $after );
        $this->assertStringNotContainsString( 'for your approval', $after );
        $this->assertStringNotContainsString( 'applied only by you', $after );
        $this->assertStringNotContainsString( 'await your approval', $after );
        $this->assertStringNotContainsString( 'Nothing is sent without approval', $after );
        $this->assertStringContainsString( 'Automatic internal links3Added automatically, up to 20 a day', $after );
        $this->assertStringContainsString( 'Nothing needs you right now.', $after );
        $this->assertSame( [], $r['errors'] );
    }

    public function testSeoPageShowsAutomaticLinksAndUndoesThemAfterConfirmation(): void {
        $r = $this->browser( 'seoUndo' );
        $this->assertStringContainsString( 'Applied automatically: 3', (string) $r['before'] );
        $this->assertStringContainsString( 'up to 20 a day (3 today)', (string) $r['before'] );
        $this->assertStringContainsString( 'Undo automatic links?', implode( ' ', $r['dialog'] ) );
        $this->assertStringContainsString( 'gets back exactly the content it had before', implode( ' ', $r['dialog'] ) );
        $this->assertSame( [ [ 'confirm' => true ] ], $r['posts'] );
        $this->assertSame( 'success', $r['toasts'][0]['kind'] ?? '' );
        $this->assertStringContainsString( 'Removed 3 automatic links', $r['toasts'][0]['text'] ?? '' );
        $this->assertStringContainsString( 'Applied automatically: 0', (string) $r['after'] );
        $this->assertTrue( $r['undoDisabled'] );
        $this->assertSame( [], $r['errors'] );
    }

    public function testSeoListComesInPagesAndSaysWhyRowsWait(): void {
        $r = $this->browser( 'seoLinksPaging' );
        $this->assertSame( [ 'status' => 'pending', 'per_page' => '50', 'page' => '1' ], $r['gets'][0], 'an explicit page size' );
        $this->assertSame( 50, $r['firstRows'] );
        $this->assertStringContainsString( 'Showing 1–50 of 120 (page 1 of 3)', (string) $r['first'] );
        $this->assertStringContainsString( 'Waiting (120)', (string) $r['first'], 'with automatic links on, pending rows are not "for review"' );
        $this->assertStringContainsString( 'Left for you (1)', (string) $r['first'] );
        $this->assertStringContainsString( 'Not shown on the page (2)', (string) $r['first'] );
        $this->assertStringContainsString( 'Left for you Not applied automatically: WordPress would re-filter other markup', (string) $r['first'] );
        $this->assertStringContainsString( 'Not checked yet: the page answered HTTP 503', (string) $r['first'] );
        $this->assertStringContainsString( 'not added because the page doesn’t display that text', (string) $r['first'] );
        $this->assertStringContainsString( 'taken back out of pages that don’t display their content', (string) $r['first'] );
        $this->assertStringContainsString( 'Apply these 50', (string) $r['first'] );
        $this->assertSame( '2', $r['gets'][1]['page'] );
        $this->assertStringContainsString( 'Showing 51–100 of 120 (page 2 of 3)', (string) $r['second'] );
        $this->assertSame( 'refused', $r['gets'][2]['status'] );
        $this->assertSame( '1', $r['gets'][2]['page'], 'a new filter starts on its first page' );
        $this->assertStringContainsString( 'Automation could not add these by itself', (string) $r['refused'] );
        $this->assertSame( 'not_shown', $r['gets'][3]['status'] );
        $this->assertStringContainsString( 'Not shown on the page Not added: this page’s template doesn’t display its content', (string) $r['notShown'] );
        $this->assertStringContainsString( 'Removed: not shown', (string) $r['notShown'] );
        $this->assertSame( [ [ 'ids' => [ 201 ], 'confirm' => true ] ], $r['posts'] );
        $this->assertSame( 'warning', $r['toasts'][0]['kind'] ?? '' );
        $this->assertStringContainsString( 'visitors and Google won’t see this link', $r['toasts'][0]['text'] ?? '' );
        // 1.2.3: suggestions closed for the brand name or another language show their reason under "No longer valid".
        $this->assertSame( 'stale', end( $r['gets'] )['status'] ?? '' );
        $this->assertSame( 2, $r['staleRows'] );
        $this->assertStringContainsString( 'Not suggested: the target page is in another language.', (string) $r['stale'] );
        $this->assertStringContainsString( 'Not suggested: the link text is your brand name.', (string) $r['stale'] );
        $this->assertSame( [], $r['errors'] );
    }

    public function testSettingsListTheAutomaticCompetitorsApartAndLetThemBeRemoved(): void {
        $r = $this->browser( 'settingsDiscovery' );
        $this->assertStringContainsString( 'Found automatically by Autopilot', (string) $r['label'] );
        $this->assertStringContainsString( 'never added back', (string) $r['label'] );
        $this->assertSame( "canadalabsupply.com\nnorthernbioreagents.ca", $r['value'] );
        $this->assertSame( [ [ 'settings' => [ 'discovery' => [ 'competitor_domains_auto' => [ 'canadalabsupply.com' ] ] ] ] ], $r['saved'] );
        $this->assertStringContainsString( 'Add the best internal links automatically', (string) $r['automationText'] );
        $this->assertStringContainsString( 'Automatic internal links per day', (string) $r['automationText'] );
        $this->assertSame( [], $r['errors'] );
    }

    public function testUnderAutopilotTurningAutomaticLinksOnInSettingsStillAsksFirst(): void {
        $r = $this->browser( 'settingsAutoLinks' );
        $dialog = implode( "\n", $r['dialog'] );
        $this->assertStringContainsString( 'Turn on automatic internal links?', $dialog );
        $this->assertStringContainsString( 'it saves that one change as your account', $dialog );
        $this->assertNotSame( [], $r['cancelDialog'], 'asked before the first save too' );
        $this->assertSame( 0, $r['afterCancel'], 'cancelling saves nothing' );
        $this->assertSame( [ [ 'settings' => [ 'seo' => [ 'auto_apply_links' => true ] ], 'confirm' => true ] ], $r['saved'] );
        $this->assertSame( [], $r['errors'] );
    }

    public function testPureHelpers(): void {
        $h = $this->browser( 'helpers' );
        $this->assertStringContainsString( 'never edits your site content unless you click Apply', $h['explainOff'] );
        $this->assertStringNotContainsString( 'unless you click Apply', $h['explainOn'] );
        $this->assertStringContainsString( 'added automatically, up to 20 a day', $h['explainOn'] );
        $this->assertStringContainsString( 'the best internal links are added (up to 20 a day)', $h['policyOn'] );
        $this->assertSame( 'Autopilot is on — nothing needs your approval', $h['onText'] );
        $this->assertFalse( $h['autoOff']['show'] );
        $this->assertSame( 'Applied automatically: 7', $h['autoOn']['title'] );
        $this->assertStringContainsString( 'the ones added earlier stay until you undo them', $h['autoLeft']['text'] );
        $this->assertStringContainsString( 'up to 8 a day', $h['willRunFallback'][0] );
        $this->assertStringContainsString( 'Add up to 12 internal links a day', $h['willRunFallback'][1] );
        $this->assertStringContainsString( 'up to 20 a day', $h['summaryOn'][2] );
        $this->assertStringContainsString( '(policy 1.2.0-2)', implode( ' ', $h['summaryOldConsent'] ) );
        $this->assertStringContainsString( 'switched on under an earlier version of the Autopilot text', implode( ' ', $h['summaryOldConsent'] ) );
        $this->assertStringNotContainsString( 'earlier version', implode( ' ', $h['summaryCurrentConsent'] ) );
        $this->assertSame( [], $h['errors'] );
    }

    // ------------------------------------------------------------------ harness runner

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
            self::$browserSkip = 'Playwright is not installed (set LGA_PLAYWRIGHT)';
            return;
        }
        $chrome = (string) getenv( 'LGA_CHROME' );
        if ( '' === $chrome && is_executable( '/usr/bin/google-chrome' ) ) {
            $chrome = '/usr/bin/google-chrome';
        }
        putenv( 'LGA_POLICY_TEXT=' . Autopilot::policyText() ); // the confirm dialog shows the real Autopilot text
        $cmd = [ $node, __DIR__ . '/AutopilotUiHarness.mjs', LGA_DIR, $playwright ];
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
        $env = (string) getenv( 'LGA_PLAYWRIGHT' );
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
        $out  = (string) tempnam( $dir, 'lga-jsp-out' );
        $err  = (string) tempnam( $dir, 'lga-jsp-err' );
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
