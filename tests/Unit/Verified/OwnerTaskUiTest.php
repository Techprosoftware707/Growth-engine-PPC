<?php
/**
 * assets/js/pages/submissions.js owner-task UX: static guards always run; the rendering, clipboard and
 * "Mark submitted" behaviour run under node with a fake DOM and fake fetch (OwnerTaskUiHarness.mjs), skipped
 * when node is not installed.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Verified;

use PHPUnit\Framework\TestCase;

final class OwnerTaskUiTest extends TestCase {

    /** @var array<string,mixed>|null */
    private static ?array $result = null;

    private static string $skip = '';

    private static function source(): string {
        $src = file_get_contents( PPCLG_DIR . 'assets/js/pages/submissions.js' );
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
                $cmd  = escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/OwnerTaskUiHarness.mjs' ) . ' ' . escapeshellarg( rtrim( PPCLG_DIR, '/' ) ) . ' 2>&1';
                $out  = (string) shell_exec( $cmd );
                $json = json_decode( $out, true );
                if ( ! is_array( $json ) ) {
                    $this->fail( 'Owner-task harness failed: ' . $out );
                }
                self::$result = $json;
            }
        }
        if ( '' !== self::$skip ) {
            $this->markTestSkipped( self::$skip );
        }
        return (array) self::$result;
    }

    public function testPageUsesDomHelpersTheExistingEndpointsAndTheBannerContract(): void {
        $src = self::source();
        $this->assertStringNotContainsString( 'innerHTML', $src );
        $this->assertStringNotContainsString( 'insertAdjacentHTML', $src );
        $this->assertStringContainsString( "import( '../compliance-banner.js' )", $src );
        $this->assertMatchesRegularExpression( '/try \{\s*const mod = await import\( \'\.\.\/compliance-banner\.js\' \);/', $src, 'the banner module is loaded dynamically inside try/catch' );
        $this->assertStringContainsString( "api.get( '/compliance/storefront', {}, { silent: true } )", $src );
        $this->assertStringContainsString( 'e.status !== 404', $src, 'a 404 from the compliance route is ignored' );
        $this->assertStringContainsString( 'api.post( `/submissions/${ s.id }/mark-submitted`, { listing_url: listing, note: noteInput.value.trim() } )', $src );
        $this->assertStringContainsString( "rel: 'noopener noreferrer'", $src );
        $this->assertStringContainsString( "target: '_blank'", $src );
        $this->assertStringContainsString( "api.post( '/submissions/verified/import', {} )", $src );
        $this->assertDoesNotMatchRegularExpression( '/\b(health|medical|pharmacy|wellness|supplements?)\b/i', $src, 'no health/medical framing in the page copy' );
    }

    public function testUrlGuardAndListingKit(): void {
        $r = $this->harness();
        $this->assertSame( 'https://example.org/a', $r['safe']['https'] );
        $this->assertSame( 'http://example.org', $r['safe']['http'] );
        foreach ( [ 'js', 'mailto', 'relative', 'protocolRelative' ] as $k ) {
            $this->assertSame( '', $r['safe'][ $k ], $k );
        }
        $this->assertTrue( $r['isOwner'] );
        $this->assertTrue( $r['notOwner'] );
        $lines = explode( "\n", (string) $r['kit'] );
        $this->assertSame( [ 'Business name', 'Website', 'Address', 'Email', 'Description', 'Category rule' ], array_map( static fn( string $l ): string => explode( ':', $l, 2 )[0], $lines ) );
        $this->assertStringContainsString( 'research use and are not for human or veterinary use', (string) $r['kit'] );
        $this->assertSame( [ $r['kit'] ], $r['clipboard'], 'Copy listing kit writes exactly the kit text' );
        $this->assertSame( 'success', $r['copyToasts'][0]['kind'] );
    }

    public function testOwnerTaskCardRendersEscapedStepsSafeLinksAndLabels(): void {
        $r = $this->harness();
        $this->assertSame( 2, $r['stepsBr'], 'three step lines separated by two <br>' );
        $this->assertSame( '<script>alert(1)</script> Pitch once by email', $r['stepsTextNodes'][1], 'markup in steps stays text' );
        $this->assertSame( 0, $r['scriptElements'] );
        foreach ( $r['anchors'] as $a ) {
            $this->assertMatchesRegularExpression( '#^https?://#', (string) $a['href'] );
            $this->assertSame( '_blank', $a['target'] );
            $this->assertSame( 'noopener noreferrer', $a['rel'] );
        }
        $open = array_values( array_filter( $r['anchors'], static fn( array $a ): bool => 'Open submission page' === $a['text'] ) );
        $this->assertCount( 1, $open );
        $this->assertSame( 'https://www.rdworldonline.com/content-submission-guidelines-for-rd-world/', $open[0]['href'] );
        foreach ( [ 'Tier 1', 'Authority 86', 'Outreach', 'Conditional', 'Storefront consistency', 'never drafts or sends outreach for this site', 'editor@example.org', 'Mark submitted' ] as $needle ) {
            $this->assertStringContainsString( $needle, (string) $r['text'] );
        }
        $this->assertSame( [ 'Copy listing kit', 'Mark submitted' ], $r['buttons'] );
        $this->assertSame( [ 'Tier 2', 'Authority unknown', 'Conditional' ], $r['badges'] );

        // A non-http target gets no link and the listing task shows no outreach wording.
        $this->assertSame( [ 'https://purepeptidescanada.com' ], $r['unsafeAnchors'] );
        $this->assertStringNotContainsString( 'Open submission page', (string) $r['unsafeText'] );
        $this->assertStringNotContainsString( 'Outreach opportunity', (string) $r['unsafeText'] );
        $this->assertStringContainsString( 'No web page recorded', (string) $r['unsafeText'] );
        $this->assertSame( [ 'Copy listing kit' ], $r['doneButtons'], 'a submitted task has no Mark submitted form' );
    }

    public function testMarkSubmittedReportsRefusalsHonestly(): void {
        $r = $this->harness();
        $this->assertSame( 0, $r['badUrlRequests'], 'a javascript: listing URL is refused before any request' );
        $this->assertSame( 'error', $r['badUrlToasts'][0]['kind'] );

        $this->assertSame( 'POST', $r['refusedRequest']['method'] );
        $this->assertStringEndsWith( '/submissions/77/mark-submitted', $r['refusedRequest']['url'] );
        $this->assertSame( 'https://www.rdworldonline.com/author/pure-peptides-canada/', $r['refusedRequest']['body']['listing_url'] );
        $this->assertSame( [ [ 'kind' => 'error', 'text' => 'This submission is already confirmed.' ] ], $r['refusedToasts'] );
        $this->assertSame( 0, $r['markedAfterRefusal'] );

        $this->assertSame( [ [ 'kind' => 'success', 'text' => 'Marked as submitted; verification starts on the next run.' ] ], $r['acceptedToasts'] );
        $this->assertSame( 1, $r['markedAfterAccept'] );
    }
}
