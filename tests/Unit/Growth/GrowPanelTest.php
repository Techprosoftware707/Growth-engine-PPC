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
        $this->assertSame( [ 'Resume' ], $r['buttons'] );
        $this->assertTrue( $r['lostContact'] );
        $this->assertSame( 4, $r['afterResume']['stepCalls'] );
        $this->assertTrue( $r['afterResume']['done'] );
        $this->assertSame( [ 'Grow now' ], $r['afterResume']['buttons'] );
    }

    public function testGrowNowStartsAndLoopsAndARefusalIsReported(): void {
        $h = $this->harness();
        $this->assertSame( [ 'Grow now' ], $h['start']['idleButtons'] );
        $this->assertTrue( $h['start']['explains'], 'the one-line explanation says what the run never does' );
        $this->assertSame( [ 'GET /growth/status', 'POST /growth/start', 'POST /growth/step' ], $h['start']['requests'] );
        $this->assertSame( [ 'GET /growth/status', 'POST /growth/start' ], $h['refused']['requests'], 'no step after a refused start' );
        $this->assertTrue( $h['refused']['toast'], 'the refusal message is shown' );
        $this->assertSame( [ 'Grow now' ], $h['refused']['buttons'] );
    }

    public function testComplianceBannerIgnores404AndRendersABlockingStatus(): void {
        $b = $this->harness()['banner'];
        $this->assertTrue( $b['emptyOn404'] );
        $this->assertTrue( $b['blockShown'] );
        $this->assertTrue( $b['legal'] );
    }
}
