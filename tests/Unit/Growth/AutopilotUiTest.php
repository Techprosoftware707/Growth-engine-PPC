<?php
/**
 * Browser checks for the 1.2.0 Build B UI (AutopilotUiHarness.mjs, headless Chrome via Playwright; skipped when node,
 * Playwright or a browser is missing; PPCLG_PLAYWRIGHT / PPCLG_CHROME override the paths):
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

namespace PPC\LinkGrowth\Tests\Unit\Growth;

use PHPUnit\Framework\TestCase;

final class AutopilotUiTest extends TestCase {

    /** @var array<string,mixed>|null */
    private static ?array $browser = null;

    private static string $browserSkip = '';

    public function testStaticGuards(): void {
        $overview = (string) file_get_contents( PPCLG_DIR . 'assets/js/pages/overview.js' );
        $this->assertStringNotContainsString( 'autopilotCard(', $overview, 'the Overview shows the switch inside the Grow panel, not as a second card' );
        $this->assertStringContainsString( 'setAutopilot: ( status, onChange )', $overview );
        $this->assertStringContainsString( "'/seo/links/undo-auto'", (string) file_get_contents( PPCLG_DIR . 'assets/js/pages/seo.js' ) );
        $settings = (string) file_get_contents( PPCLG_DIR . 'assets/js/pages/settings.js' );
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
        foreach ( [ 'What runs automatically:', 'up to 10 a day and 40 a week', 'Add up to 20 internal links a day', 'open public forms that allow it', 'Discover new prospects, evaluate them and monitor your backlinks', 'These limits always stay on:', 'No account creation or sign-ups on third-party sites.', 'Link Growth Submitter role' ] as $needle ) {
            $this->assertStringContainsString( $needle, $dialog );
        }
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
            self::$browserSkip = 'Playwright is not installed (set PPCLG_PLAYWRIGHT)';
            return;
        }
        $chrome = (string) getenv( 'PPCLG_CHROME' );
        if ( '' === $chrome && is_executable( '/usr/bin/google-chrome' ) ) {
            $chrome = '/usr/bin/google-chrome';
        }
        $cmd = [ $node, __DIR__ . '/AutopilotUiHarness.mjs', PPCLG_DIR, $playwright ];
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
