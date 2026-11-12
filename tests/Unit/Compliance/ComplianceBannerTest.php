<?php
/**
 * assets/js/compliance-banner.js: static guards always run; the rendering and button behaviour run under node
 * with a fake DOM and fake fetch (ComplianceBannerHarness.mjs), skipped when node is not installed.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Compliance;

use PHPUnit\Framework\TestCase;

final class ComplianceBannerTest extends TestCase {

    /** @var array<string,mixed>|null */
    private static ?array $result = null;

    private static string $skip = '';

    private static function source(): string {
        $src = file_get_contents( PPCLG_DIR . 'assets/js/compliance-banner.js' );
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
                $cmd  = escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/ComplianceBannerHarness.mjs' ) . ' ' . escapeshellarg( rtrim( PPCLG_DIR, '/' ) ) . ' 2>&1';
                $out  = (string) shell_exec( $cmd );
                $json = json_decode( $out, true );
                if ( ! is_array( $json ) ) {
                    $this->fail( 'Banner harness failed: ' . $out );
                }
                self::$result = $json;
            }
        }
        if ( '' !== self::$skip ) {
            $this->markTestSkipped( self::$skip );
        }
        return (array) self::$result;
    }

    public function testBannerUsesDomHelpersAndExportsTheContractSignature(): void {
        $src = self::source();
        $this->assertStringNotContainsString( 'innerHTML', $src );
        $this->assertStringNotContainsString( 'insertAdjacentHTML', $src );
        $this->assertStringContainsString( 'export default function renderComplianceBanner( container, status, { onRecheck, onAcknowledge } = {} )', $src );
        $this->assertStringContainsString( "api.post( '/compliance/storefront/recheck'", $src );
        $this->assertStringContainsString( "api.post( '/compliance/storefront/acknowledge', { confirm: true", $src );
        $this->assertDoesNotMatchRegularExpression( '/\b(health|medical|pharmacy|wellness product)\b/i', $src, 'no health/medical framing in the banner copy' );
    }

    public function testModelHidesForPassAndAcknowledgedAndSaysItIsTheOwnersLegalDecision(): void {
        $r = $this->harness();
        $this->assertFalse( $r['models']['pass']['show'] );
        $this->assertFalse( $r['models']['acknowledged']['show'] );
        $this->assertSame( 0, $r['passChildren'], 'pass empties the container' );
        $this->assertSame( 0, $r['acknowledgedChildren'] );

        $block = $r['models']['block'];
        $this->assertTrue( $block['show'] );
        $this->assertSame( 'danger', $block['kind'] );
        $this->assertTrue( $block['paused'] );
        $text = implode( ' ', $block['lines'] );
        $this->assertStringContainsString( 'your legal decision as the owner', $text );
        $this->assertStringContainsString( 'Canadian regulatory counsel', $text );
        $this->assertStringContainsString( 'automated submissions and outreach sending are paused', $text );
        $this->assertStringContainsString( '7 storefront items present the products for consumer use', $text );

        $this->assertFalse( $r['models']['blockGateOff']['paused'] );
        $this->assertStringContainsString( 'gate is switched off', implode( ' ', $r['models']['blockGateOff']['lines'] ) );
        $this->assertSame( 'warning', $r['models']['warn']['kind'] );
        $this->assertStringContainsString( 'Nothing is paused', implode( ' ', $r['models']['warn']['lines'] ) );
        $this->assertSame( 'Run the check now', $r['models']['unknown']['recheckLabel'] );
        $this->assertFalse( $r['models']['unknown']['canAcknowledge'] );
        $this->assertTrue( $r['models']['failed']['paused'] );
        $this->assertSame( 'The storefront check could not run: boom', $r['models']['failed']['lines'][0] );
        $this->assertStringContainsString( 'could not be loaded', $r['models']['missing']['lines'][0] );
    }

    public function testRenderedBannerEscapesDataAndLinksOnlyHttpUrls(): void {
        $r = $this->harness();
        $this->assertSame( [ 'Re-check now', 'Acknowledge these findings' ], $r['blockButtons'] );
        $this->assertStringContainsString( 'Canadian regulatory counsel', $r['blockText'] );
        $this->assertStringContainsString( '<img src=x onerror=alert(1)>', $r['blockText'], 'labels are text nodes, never markup' );
        $this->assertCount( 8, $r['links'], 'the javascript: URL is not linked' );
        foreach ( $r['links'] as $href ) {
            $this->assertMatchesRegularExpression( '#^https?://#', $href );
        }
        $this->assertSame( array_fill( 0, 8, 'noopener noreferrer' ), $r['linkRel'] );
        $this->assertSame( 1, $r['hasDetails'], 'findings beyond the first five are behind "Show all"' );
    }

    public function testButtonsReportRefusalsHonestly(): void {
        $r = $this->harness();
        $this->assertSame( [ [ 'kind' => 'error', 'text' => 'The storefront check could not run: boom.' ] ], $r['refusedToasts'] );
        $this->assertNull( $r['refusedCallback'], 'a refused re-check is not reported to the host as done' );
        $this->assertTrue( $r['stillBlocking'] );

        $this->assertSame( 'POST', $r['recheckRequest']['method'] );
        $this->assertStringContainsString( '/compliance/storefront/recheck', $r['recheckRequest']['url'] );
        $this->assertSame( 'pass', $r['recheckCallback'] );
        $this->assertSame( 0, $r['afterRecheckChildren'], 'a passing re-check removes the banner' );
        $this->assertSame( [ 'kind' => 'success', 'text' => 'Storefront checked: no consumer-use wording found.' ], end( $r['toasts'] ) );
    }
}
