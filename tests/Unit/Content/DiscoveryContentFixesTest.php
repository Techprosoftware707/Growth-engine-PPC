<?php
/**
 * Regression tests for the discovery-content slice fixes (Content side):
 * claims-filter negation scope, research-use-only survival in length-limited fields,
 * fact gating of generated copy, and claims-clean default linkable assets.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Content;

use PPC\LinkGrowth\Content\ClaimsFilter;
use PPC\LinkGrowth\Content\ContentGenerator;
use PPC\LinkGrowth\Content\FactsRegistry;
use PPC\LinkGrowth\Content\LinkableAssets;
use PPC\LinkGrowth\Outreach\Compliance;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class DiscoveryContentFixesTest extends TestCase {

    private const RUO = '/research use only|laboratory research (use|purposes) only|not for human/i';

    /** discovery-content-1: a negation only excuses the phrase it directly governs. */
    public function testClaimsNegationDoesNotHideRealClaims(): void {
        $f = Container::get( ClaimsFilter::class );
        foreach ( [
            'Our peptides cure disease, not just symptoms.',
            'No side effects with any of our research peptides',
            'Cures arthritis - no joke, try it for yourself',
            'BPC-157 has no known risks and heals tendons fast.',
            'No prescription needed - it treats joint pain.',
            'We never miss a shipment and our peptides heal wounds',
            'We ship across Canada with no customs fees, and it heals tendons.',
            'Sold for research and not for human use, BPC-157 heals tendons in studies.',
            'Hi there, no pressure at all: our peptides cure arthritis.',
            'No joke, it cures arthritis.',
            'Never fails to heal tendons.',
            "No doubt\nheals tendons.",
        ] as $claim ) {
            $this->assertFalse( $f->isClean( $claim ), "claim must be flagged: $claim" );
        }
        $this->assertNotEmpty( Container::get( Compliance::class )->checkClaims( 'Quick note', 'Hi there, no pressure at all: our peptides cure arthritis.' ) );
        $this->assertNotEmpty( Container::get( Compliance::class )->checkClaims( 'Hello', 'Our peptides cure disease, not just symptoms.' ) );
        $this->assertSame( 'No side effects', $f->check( 'No side effects with any of our research peptides' )[0]['match'] );
    }

    /** discovery-content-1: genuine compliance negations stay clean. */
    public function testClaimsNegationStillAllowsComplianceStatements(): void {
        $f = Container::get( ClaimsFilter::class );
        foreach ( [
            'Products are not for human use and we do not provide dosing guidance. It is not a supplement.',
            'All research products are sold strictly for in-vitro laboratory research use and are not for human or veterinary use.',
            'The company does not provide dosing, administration or protocol guidance of any kind.',
            'Products are not approved by Health Canada for human or animal use and are not drugs, supplements or cosmetics.',
            'We do not offer dosing or administration advice.',
            'It is neither a drug nor a supplement.',
            'We make no therapeutic claims.',
            "Our calculator states 'no dosing or administration math'.",
            'No health/therapeutic framing.',
        ] as $ok ) {
            $this->assertTrue( $f->isClean( $ok ), "compliance statement must stay clean: $ok" );
        }
        // Every shipped fact statement is clean as written.
        foreach ( FactsRegistry::defaults() as $key => $fact ) {
            $this->assertTrue( $f->isClean( $fact['statement'] ), "default fact $key" );
        }
    }

    /** discovery-content-2: field limits never cut or drop the research-use-only statement. */
    public function testFitLengthKeepsResearchUseOnlyStatement(): void {
        $g = Container::get( ContentGenerator::class );
        $f = Container::get( ClaimsFilter::class );
        $domains = [ 'a.test', 'resources.test', 'uni.example.ca', 'labdirectory.test', 'biocompare.com', 'frasers.com', 'x.test', 'dest7.example.ca' ];
        foreach ( $domains as $d ) {
            foreach ( [ 120, 150, 180, 200, 230, 249, 250, 300, 500 ] as $max ) {
                foreach ( [ 'business', 'scientific', 'educational' ] as $style ) {
                    $out = $g->description( $d, $max, $style );
                    $this->assertLessThanOrEqual( $max, mb_strlen( $out ), "$d/$max/$style" );
                    $this->assertMatchesRegularExpression( self::RUO, $out, "$d/$max/$style: $out" );
                    $this->assertStringContainsStringIgnoringCase( 'not for human', $out, "explicit disclaimer kept at $max: $out" );
                    $this->assertTrue( $f->isClean( $out ), $out );
                    $this->assertDoesNotMatchRegularExpression( '/\b(and|with|of|in|for|the|a|based)\.$/', $out, "garbled ending: $out" );
                }
            }
            foreach ( [ 60, 80, 100, 119, 160, 200 ] as $max ) {
                $out = $g->shortDescription( $d, $max );
                $this->assertLessThanOrEqual( $max, mb_strlen( $out ), "$d/$max" );
                $this->assertStringContainsString( 'Research use only', $out, "$d/$max: $out" );
                $this->assertDoesNotMatchRegularExpression( '/\b(and|with|of|in|for|the|a|based)\.$/', $out, "garbled ending: $out" );
            }
        }
        // The full positioning sentence is kept whenever it fits.
        $this->assertStringContainsString( 'not for human or veterinary use', $g->description( 'a.test', 300 ) );
    }

    /** discovery-content-3: unverified or edited facts are never asserted by template copy. */
    public function testGeneratedCopyFollowsTheFactsRegistry(): void {
        $facts = Container::get( FactsRegistry::class );
        $all   = FactsRegistry::defaults();
        foreach ( [ 'shipping_terms', 'lab_reports', 'testing_methods' ] as $k ) {
            $all[ $k ]['verified'] = false;
        }
        $all['payment']['statement'] = 'Checkout accepts card and Interac e-Transfer.';
        $facts->replace( $all );

        $g    = Container::get( ContentGenerator::class );
        $blob = '';
        for ( $i = 0; $i < 30; $i++ ) {
            $d     = "dest$i.example.ca";
            $blob .= implode( "\n", [
                $g->description( $d, 600, 'business' ),
                $g->description( $d, 600, 'scientific' ),
                $g->description( $d, 600, 'educational' ),
                $g->shortDescription( $d ),
                $g->tagline( $d ),
                $g->companyBackground( $d ),
                $g->partnershipProposal( $d, 'Org', 'Context.' ),
                implode( "\n", $g->supplierProfile( $d ) ),
            ] ) . "\n";
        }
        foreach ( [ '$299', 'crypto', 'Janoshik', 'Independent', 'independent batch', 'independent testing', 'per-batch', 'Batch-level', 'HPLC purity', 'COAs' ] as $needle ) {
            $this->assertStringNotContainsString( $needle, $blob, "unverified/edited fact still asserted: $needle" );
        }
        $this->assertStringContainsString( 'Checkout accepts card and Interac e-Transfer.', $blob, 'edited fact is used as written' );

        // Region comes from settings and must match the verified location.
        $facts->reset();
        $settings = Container::get( Settings::class );
        $settings->set( 'site.city', 'Vancouver' );
        $settings->set( 'site.region', 'BC' );
        $all = FactsRegistry::defaults();
        $all['location']['statement'] = 'It is based in Vancouver, British Columbia and operates as an online store.';
        $facts->replace( $all );
        $blob = '';
        for ( $i = 0; $i < 20; $i++ ) {
            $blob .= $g->description( "dest$i.example.ca", 600 ) . ' ' . implode( ' ', $g->supplierProfile( "dest$i.example.ca" ) );
        }
        $this->assertStringNotContainsString( 'Ontario', $blob );
        $this->assertStringNotContainsString( 'Toronto', $blob );
        $this->assertStringContainsString( 'Vancouver, British Columbia', $blob );
    }

    public function testFactsSupportsRequiresVerifiedAndMatchingStatement(): void {
        $facts = Container::get( FactsRegistry::class );
        $this->assertTrue( $facts->supports( 'payment' ) );
        $this->assertTrue( $facts->supports( 'payment', 'crypto', 'e-Transfer' ) );
        $this->assertFalse( $facts->supports( 'payment', 'paypal' ) );
        $this->assertFalse( $facts->supports( 'nonexistent' ) );
        $all = FactsRegistry::defaults();
        $all['payment']['statement'] = 'Checkout accepts card.';
        $all['cad_pricing']['verified'] = false;
        $facts->replace( $all );
        $this->assertFalse( $facts->supports( 'payment' ), 'edited: no longer the default the template was written from' );
        $this->assertTrue( $facts->supports( 'payment', 'card' ) );
        $this->assertFalse( $facts->supports( 'cad_pricing', 'canadian dollar' ) );
    }

    /** discovery-content-6: default assets are quotable; superlative assets are not chosen. */
    public function testLinkableAssetsAreClaimsClean(): void {
        $assets = Container::get( LinkableAssets::class );
        $f      = Container::get( ClaimsFilter::class );
        foreach ( LinkableAssets::defaults( 'https://purepeptidescanada.test' ) as $a ) {
            $this->assertTrue( $f->isClean( $a['title'] . '. ' . $a['summary'] ), $a['title'] );
        }
        $g = Container::get( ContentGenerator::class );
        foreach ( [ 'Lab supplier resources in Canada', 'Vendor evaluation checklist for Canadian suppliers', 'Procurement resources for labs in canada' ] as $ctx ) {
            $a = $assets->bestFor( $ctx, 'guide' );
            $this->assertTrue( $assets->isClean( $a ), $a['title'] );
            foreach ( [ 'resources.test', 'uni.example.ca', 'a.test', 'b.test' ] as $d ) {
                $blurb = $g->resourceContribution( $d, $a['title'], $a['url'], $a['summary'] );
                $this->assertStringContainsString( $a['title'], $blurb, "$d: $blurb" );
                $this->assertStringContainsString( $a['url'], $blurb, "$d: $blurb" );
            }
        }
        // A custom asset with a superlative title is never selected while a clean one exists.
        Container::get( Settings::class )->set( 'content.linkable_assets', [
            [ 'url' => 'https://purepeptidescanada.test/best/', 'title' => 'The Best Peptide Supplier Guide', 'summary' => 'Supplier checklist.', 'topics' => [ 'supplier', 'checklist', 'canada' ], 'kind' => 'guide' ],
            [ 'url' => 'https://purepeptidescanada.test/coa/', 'title' => 'Reading a COA', 'summary' => 'How to read a certificate of analysis.', 'topics' => [ 'coa' ], 'kind' => 'guide' ],
        ] );
        $this->assertSame( 'https://purepeptidescanada.test/coa/', $assets->bestFor( 'supplier checklist canada', 'guide' )['url'] );
    }
}
