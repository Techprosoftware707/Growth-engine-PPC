<?php
/**
 * Browser checks for the 1.2.0 campaign / Autopilot UI (CampaignUiHarness.mjs, headless Chrome via Playwright;
 * skipped when node, Playwright or a browser is missing; PPCLG_PLAYWRIGHT / PPCLG_CHROME override the paths):
 *
 * - Creating a campaign while the Messages tab is open shows the Campaigns tab with the new card, its drafts and
 *   a "What happens next" line (it used to show only a toast while the message list stayed on screen), and the
 *   Messages campaign filter lists the new campaign afterwards.
 * - "Approve all drafts" approves a campaign's drafts in one request.
 * - The banner no longer claims automatic sending while "Require individual approval" blocks it, and the send
 *   window line names the zone it is evaluated in.
 * - The Autopilot switch asks for confirmation (policy text + hard limits) and posts confirm=true.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Outreach;

use PHPUnit\Framework\TestCase;

final class CampaignUiTest extends TestCase {

    /** @var array<string,mixed>|null */
    private static ?array $browser = null;

    private static string $browserSkip = '';

    public function testStaticGuards(): void {
        $src = (string) file_get_contents( PPCLG_DIR . 'assets/js/pages/outreach.js' );
        $this->assertStringNotContainsString( "toast( 'Campaign created as a draft.', 'success' );", $src );
        $this->assertStringNotContainsString( "'No messages yet'", $src );
        $this->assertStringContainsString( "view.select( 'campaigns' );", $src );
        $this->assertStringContainsString( '/approve-drafts', $src );
        $this->assertStringContainsString( '/candidates', $src );
        $this->assertFileExists( PPCLG_DIR . 'assets/js/autopilot.js' );
    }

    public function testCreatingFromTheMessagesTabShowsTheNewCampaignAndWhatHappensNext(): void {
        $r = $this->browser( 'createFromMessages' );
        $this->assertSame( [ [ 'kind' => 'success', 'text' => 'Campaign created with 2 drafts. They are waiting for your approval: use “Approve all drafts” on the campaign card.' ] ], $r['toasts'] );
        $this->assertStringContainsString( 'Campaigns', (string) $r['activeTab'] );
        $this->assertStringContainsString( 'tab=campaigns', (string) $r['hash'] );
        $run1 = array_values( array_filter( $r['cards'], static fn( string $t ): bool => str_contains( $t, 'Run 1' ) ) );
        $this->assertNotEmpty( $run1, 'the new campaign card is on screen' );
        $this->assertStringContainsString( 'What happens next: Waiting for your approval', $run1[0] );
        $this->assertStringContainsString( 'Draft: 2', $run1[0] );
        $this->assertSame( 1, $r['approveAll'] );
        $this->assertSame( 'success', $r['afterApprove'][0]['kind'] ?? '' );
        $this->assertStringContainsString( '2 draft(s) approved', $r['afterApprove'][0]['text'] ?? '' );
        $this->assertContains( 'Run 1', $r['filterOptions'], 'the Messages campaign filter lists the new campaign' );
        $this->assertSame( 'resource_page', $r['posts'][0]['approach'] ?? null );
        $this->assertSame( [], $r['errors'] );
    }

    public function testBannerTellsTheTruthAboutAutosendAndTheSendZone(): void {
        $r = $this->browser( 'createFromMessages' );
        $banner = implode( "\n", $r['banner'] );
        $this->assertStringContainsString( 'Outreach autosend is on, but nothing is approved automatically', $banner );
        $this->assertStringContainsString( '0 auto-drafted emails will be sent', $banner );
        $this->assertStringNotContainsString( 'are approved and sent in your name without individual review', $banner );
        $this->assertStringContainsString( 'Toronto time (from your business region, because WordPress is set to UTC)', implode( ' ', $r['zoneLine'] ) );
        $this->assertStringContainsString( 'Next: Monday 09:00 Toronto time', implode( ' ', $r['zoneLine'] ) );
    }

    public function testAutopilotSwitchAsksFirstAndPostsTheConfirmation(): void {
        $r = $this->browser( 'autopilotSwitch' );
        $this->assertStringContainsString( 'Autopilot', (string) $r['before'] );
        $this->assertStringContainsString( 'Off', (string) $r['before'] );
        $dialog = implode( "\n", $r['dialog'] );
        $this->assertStringContainsString( 'Turn on Autopilot?', $dialog );
        $this->assertStringContainsString( 'No account creation or sign-ups on third-party sites.', $dialog );
        $this->assertSame( [ [ 'enabled' => true, 'confirm' => true ] ], $r['posts'] );
        $this->assertSame( 'success', $r['toastsOn'][0]['kind'] ?? '' );
        $this->assertStringContainsString( 'reply tracking', (string) $r['after'] );
        $this->assertStringContainsString( 'On through Autopilot', (string) $r['raised'] );
        $this->assertSame( [], $r['errors'] );
    }

    public function testPureHelpers(): void {
        $h = $this->browser( 'helpers' );
        $this->assertSame( 'Campaign created with 3 drafts (2 more prospects are being drafted in the background). They are waiting for your approval: use “Approve all drafts” on the campaign card.', $h['createdDrafts']['text'] );
        $this->assertSame( 'Campaign created with 2 drafts. Autopilot approved 2 and sends them automatically inside the send window and daily limits.', $h['createdAutopilot']['text'] );
        $this->assertSame( 'info', $h['createdEmpty']['kind'] );
        $this->assertStringContainsString( 'published email address', $h['createdEmpty']['text'] );
        $this->assertSame( 'Outreach autosend is on, but nothing is approved automatically', $h['bannerConflict']['title'] );
        $this->assertSame( 'Outreach autosend is on', $h['bannerAutosend']['title'] );
        $this->assertSame( 'Autopilot is on', $h['bannerAutopilot']['title'] );
        $this->assertStringContainsString( 'next send window Monday 09:00 Toronto time', $h['bannerAutopilot']['text'] );
        $this->assertNull( $h['bannerOff'] );
        $this->assertSame( 'Evaluated in Toronto time (America/Toronto, from your business region, because WordPress is set to UTC)', $h['zone'] );
        $this->assertStringContainsString( 'Autopilot is on', $h['growAutopilot'] );
        // Sending paused: the toast repeats the card instead of promising automatic sending.
        $this->assertSame( 'warning', $h['createdBlocked']['kind'] );
        $this->assertStringContainsString( 'Nothing is sent yet. Paused: the sender email domain cannot receive mail', $h['createdBlocked']['text'] );
        $this->assertStringNotContainsString( 'sends them automatically', $h['createdBlocked']['text'] );
        // Grow panel tiles follow the live switch, not the stored run.
        $this->assertSame( [ 'autopilot' => false, 'autoLinks' => false, 'changed' => true ], $h['modesAfterOff'] );
        $this->assertSame( [ 'autopilot' => true, 'autoLinks' => true, 'changed' => false ], $h['modesNoPolicy'] );
        // Automation page: under Autopilot the autosend stage no longer claims the owner's drafts need approval.
        $this->assertStringContainsString( 'Your own campaigns and their first-contact drafts are approved and sent automatically', $h['stageOn'] );
        $this->assertStringNotContainsString( 'Autopilot is on', $h['stageOff'] );
        $this->assertSame( [], $h['errors'] );
    }

    public function testUsingTheSuggestedSenderReloadsTheCampaignCards(): void {
        $r = $this->browser( 'useSuggestion' );
        $this->assertStringContainsString( 'Paused: the sender email domain cannot receive mail', (string) $r['before'] );
        $this->assertStringContainsString( 'Sending automatically', (string) $r['after'], 'the card no longer says Paused' );
        $this->assertStringNotContainsString( 'Paused: the sender', (string) $r['after'] );
        $this->assertStringContainsString( '2 waiting drafts were approved', implode( ' ', array_column( $r['toasts'], 'text' ) ) );
        $this->assertSame( [], $r['errors'] );
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
        $cmd = [ $node, __DIR__ . '/CampaignUiHarness.mjs', PPCLG_DIR, $playwright ];
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
