<?php
/**
 * The browser E2E suite's browser library (tests/e2e/lib/browser.mjs, DESIGN.md §12.5), run by BrowserLibHarness.mjs
 * in headless Chrome against pages the harness serves (Playwright; the browser part is skipped when node, Playwright
 * or a browser is missing). What the walk relies on to fail when it should:
 *  - every interactive element of the dashboard without a data-lga-control is reported (a ui.js helper called
 *    without `control` gives a control no manifest entry can describe; spec §10, no decorative controls), and only
 *    those (a focusable tab panel, a <details> with a <summary>, an element inside a named control and the WordPress
 *    chrome outside .lga-wrap are not);
 *  - a step's debounced request is sent and answered before settle() returns, so it is never counted as the effect of
 *    the action after the step (a broken filter Reset passed on the request its own "before" fill scheduled);
 *  - a page that lingers after its entry (so a late timer is still checked) sends no write to the site;
 *  - DEFER_MS is longer than every debounce in assets/js, and LINGER_MS covers every setTimeout delay written there
 *    (the two auto-refresh loops excepted: their manifest entries wait for the refresh themselves).
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\E2E;

use PHPUnit\Framework\TestCase;

final class BrowserLibTest extends TestCase {

    /** Margin between the longest debounce and DEFER_MS: the time the debounced request needs to show up. */
    private const DEFER_MARGIN_MS = 200;

    /** Timers that outlast LINGER_MS on purpose: the auto-refresh loops, whose own entries wait for the refresh. */
    private const LONG_TIMERS = [
        'assets/js/pages/automation.js' => 30000,
        'assets/js/pages/logs.js'       => 15000,
    ];

    /** @var array<string,mixed>|null */
    private static ?array $harness = null;

    private static string $skip = '';

    public function testEveryInteractiveElementWithoutAControlIdIsReportedAndNothingElse(): void {
        $r = $this->scenario( 'unnamed' );
        self::assertSame( [ 'shell.theme', 'shell.nav-overview', 'backlinks.add', 'backlinks.flag', 'backlinks.more', 'backlinks.tab-a' ], $r['named'] );
        $texts = array_map( static fn ( array $c ): string => $c['tag'] . ( '' !== $c['role'] ? '[' . $c['role'] . ']' : '' ) . ': ' . $c['text'], $r['unnamed'] );
        self::assertSame(
            [
                'button: Header button without an id',
                'button: Seeded button without an id',
                'a: Seeded raw link',
                'input: Search without an id',
                'details: ',
                'div[switch]: A raw switch',
                'span: A focusable span',
                'button: Hidden button without an id',
                'button: Toast close without an id',
            ],
            $texts
        );
        $byText = array_column( $r['unnamed'], null, 'text' );
        self::assertSame( 'shell', $byText['Header button without an id']['scope'], 'the header is the shell\'s' );
        self::assertSame( 'backlinks', $byText['Seeded button without an id']['scope'], 'the page shown' );
        self::assertSame( 'toast', $byText['Toast close without an id']['region'] );
        self::assertFalse( $byText['Hidden button without an id']['visible'], 'a hidden control is reported too' );
    }

    public function testADebouncedRequestOfAStepIsAnsweredBeforeSettleReturns(): void {
        $r = $this->scenario( 'defer' );
        self::assertCount( 1, $r['inStep'], 'the request the fill scheduled ' . $r['debounceMs'] . ' ms later belongs to the step (settled after ' . $r['settledMs'] . ' ms)' );
        self::assertSame( '/logs', $r['inStep'][0]['route'] );
        self::assertSame( [ 'channel' => 'rest' ], $r['inStep'][0]['query'] );
        self::assertSame( 200, $r['inStep'][0]['status'] );
        self::assertTrue( $r['inStep'][0]['done'] );
        self::assertSame( [], $r['afterStep'], 'nothing of the step is left for the next action' );
        self::assertStringContainsString( 'channel=rest', $r['shown'] );
    }

    public function testALingeringPageSendsReadsButHoldsItsWrites(): void {
        $r = $this->scenario( 'hold' );
        self::assertSame( [ 'GET /wp-json/lga/v1/automation/status' ], $r['reached'], 'the read reaches the site, the POST does not' );
        self::assertStringContainsString( 'automation/status', $r['shown'] );
        self::assertSame( [ 'status' => null, 'failure' => null, 'done' => false ], $r['writeState'], 'the write is held, not answered or failed' );
        self::assertSame( [], $r['errors'], 'holding a write raises nothing on the page' );
    }

    public function testDeferCoversEveryDebounceOfTheDashboard(): void {
        $h = $this->harness();
        $defer = $h['constants']['DEFER_MS'];
        self::assertIsInt( $defer );
        self::assertTrue( $h['constants']['INTERACTIVE_SELECTOR'] );
        self::assertSame( [], array_values( array_filter( $h['scans']['unreadable'], static fn ( array $d ): bool => 'assets/js/ui.js' !== $d['file'] || 'debounceMs' !== $d['delay'] ) ), 'every debounce delay is a number the scan can read (filterBar\'s debounceMs default is read separately)' );
        self::assertNotEmpty( $h['scans']['debounces'] );
        foreach ( $h['scans']['debounces'] as $d ) {
            self::assertLessThanOrEqual( $defer - self::DEFER_MARGIN_MS, $d['delay'], sprintf( '%s:%d debounces %d ms: raise DEFER_MS in tests/e2e/lib/browser.mjs', $d['file'], $d['line'], $d['delay'] ) );
        }
    }

    public function testLingerCoversEveryTimerOfTheDashboard(): void {
        $h      = $this->harness();
        $linger = $h['constants']['LINGER_MS'];
        self::assertIsInt( $linger );
        self::assertNotEmpty( $h['scans']['timers'] );
        foreach ( $h['scans']['timers'] as $t ) {
            if ( ( self::LONG_TIMERS[ $t['file'] ] ?? null ) === $t['delay'] ) {
                continue;
            }
            self::assertLessThanOrEqual( $linger, $t['delay'], sprintf( '%s:%d waits %d ms: raise LINGER_MS in tests/e2e/lib/browser.mjs, or give the timer an entry that waits for it', $t['file'], $t['line'], $t['delay'] ) );
        }
        self::assertContains( 3000, array_column( $h['scans']['timers'], 'delay' ), 'the Automation page\'s reload 3 s after "Kick scheduler" is found' );
    }

    // ------------------------------------------------------------------ harness

    /** @return array<string,mixed> */
    private function scenario( string $name ): array {
        $data = $this->harness();
        if ( isset( $data['skip'] ) ) {
            $this->markTestSkipped( (string) $data['skip'] );
        }
        $this->assertArrayHasKey( $name, $data['results'] );
        $r = $data['results'][ $name ];
        $this->assertArrayNotHasKey( 'failed', $r, (string) ( $r['failed'] ?? '' ) );
        return $r;
    }

    /** @return array<string,mixed> */
    private function harness(): array {
        if ( null === self::$harness && '' === self::$skip ) {
            self::runHarness();
        }
        if ( null === self::$harness ) {
            $this->markTestSkipped( self::$skip );
        }
        return self::$harness;
    }

    private static function runHarness(): void {
        $node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
        if ( '' === $node ) {
            self::$skip = 'node is not installed';
            return;
        }
        $playwright = (string) getenv( 'LGA_PLAYWRIGHT' );
        if ( '' === $playwright && is_dir( '/opt/node-tools/node_modules/playwright' ) ) {
            $playwright = '/opt/node-tools/node_modules/playwright';
        }
        if ( '' === $playwright ) {
            $playwright = trim( (string) shell_exec( escapeshellarg( $node ) . ' -e ' . escapeshellarg( "process.stdout.write(require.resolve('playwright'))" ) . ' 2>/dev/null' ) );
        }
        $chrome = (string) getenv( 'LGA_CHROME' );
        if ( '' === $chrome && is_executable( '/usr/bin/google-chrome' ) ) {
            $chrome = '/usr/bin/google-chrome';
        }
        $root = rtrim( dirname( __DIR__, 3 ), '/' );
        $cmd  = escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/BrowserLibHarness.mjs' ) . ' ' . escapeshellarg( $root ) . ' ' . escapeshellarg( '' !== $playwright ? $playwright : 'playwright' );
        if ( '' !== $chrome ) {
            $cmd .= ' ' . escapeshellarg( $chrome );
        }
        $out  = (string) shell_exec( 'timeout 120 ' . $cmd . ' 2>/dev/null' );
        $data = json_decode( $out, true );
        if ( ! is_array( $data ) || ! isset( $data['scans'], $data['constants'] ) ) {
            self::fail( 'Browser library harness failed: ' . substr( $out, 0, 2000 ) );
        }
        if ( isset( $data['skip'] ) ) {
            // The scans ran; only the browser scenarios are skipped.
            self::$harness = [ 'scans' => $data['scans'], 'constants' => $data['constants'], 'skip' => (string) $data['skip'], 'results' => [] ];
            return;
        }
        self::$harness = $data;
    }
}
