<?php
declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Content;

use PPC\LinkGrowth\Content\ClaimsFilter;
use PPC\LinkGrowth\Content\ContentGenerator;
use PPC\LinkGrowth\Content\FactsRegistry;
use PPC\LinkGrowth\Content\LinkableAssets;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class ContentTest extends TestCase {

    public function testClaimsFilterFlagsProhibitedLanguageButAllowsNegations(): void {
        $f = Container::get( ClaimsFilter::class );
        $v = $f->check( 'Our peptides treat injuries, help with weight loss, are 99% pure and FDA approved. Take a dose daily. The best supplier.' );
        $rules = array_unique( array_column( $v, 'rule' ) );
        foreach ( [ 'therapeutic', 'benefit', 'purity_number', 'approval', 'human_use', 'superlative' ] as $r ) {
            $this->assertContains( $r, $rules, "expected rule $r" );
        }
        $this->assertTrue( $f->isClean( 'Products are not for human use and we do not provide dosing guidance. It is not a supplement.' ) );
        $this->assertTrue( $f->isClean( 'Independent HPLC and mass spectrometry reports are listed by batch. Research use only.' ) );
        $clean = $f->sanitize( 'We supply research peptides. Our products cure everything. Reports are listed by batch.' );
        $this->assertStringNotContainsString( 'cure', $clean );
        $this->assertStringContainsString( 'research use', strtolower( $clean ) );
        $this->assertArrayHasKey( 'therapeutic', ClaimsFilter::rules() );
    }

    public function testFactsRegistryOnlyExposesVerifiedFacts(): void {
        $facts = Container::get( FactsRegistry::class );
        $this->assertNotEmpty( $facts->verified() );
        $facts->replace( [ 'identity' => [ 'statement' => 'Custom statement.', 'verified' => false, 'source' => 'https://x.test', 'group' => 'identity' ], 'research_use_only' => [ 'statement' => 'RUO statement.', 'verified' => true, 'source' => '', 'group' => 'compliance' ] ] );
        $this->assertSame( '', $facts->statement( 'identity' ) );
        $this->assertSame( 'RUO statement.', $facts->statement( 'research_use_only' ) );
        $facts->reset();
        $this->assertSame( FactsRegistry::defaults()['identity']['statement'], $facts->statement( 'identity' ) );
    }

    public function testGeneratorProducesUniqueCompliantCopy(): void {
        $g  = Container::get( ContentGenerator::class );
        $f  = Container::get( ClaimsFilter::class );
        $a  = $g->description( 'labdirectory.test', 500, 'business' );
        $b  = $g->description( 'biocompare.com', 500, 'scientific' );
        $c  = $g->description( 'resources.test', 500, 'educational' );
        $this->assertNotSame( $a, $b );
        $this->assertNotSame( $b, $c );
        foreach ( [ $a, $b, $c ] as $text ) {
            $this->assertLessThanOrEqual( 500, mb_strlen( $text ) );
            $this->assertTrue( $f->isClean( $text ), $text );
            $this->assertStringContainsString( 'research use', strtolower( $text ) );
            $this->assertStringContainsString( 'Pure Peptides Canada', $text );
        }
        $this->assertSame( $a, $g->description( 'labdirectory.test', 500, 'business' ), 'deterministic per destination' );
        $short = $g->shortDescription( 'labdirectory.test' );
        $this->assertLessThanOrEqual( 160, mb_strlen( $short ) );
        $profile = $g->supplierProfile( 'frasers.com' );
        foreach ( [ 'tagline', 'short_description', 'description', 'products_services', 'differentiators', 'markets', 'customers', 'compliance' ] as $k ) {
            $this->assertNotEmpty( $profile[ $k ] );
        }
        $this->assertTrue( $f->isClean( $g->companyBackground( 'x.test' ) ) );
        $this->assertStringContainsString( 'Lab Testing', $g->resourceContribution( 'x.test', 'Lab Testing and Certificates of Analysis', 'https://purepeptidescanada.test/lab-testing/', 'Reports by batch.' ) );
        $this->assertSame( $g->rewriteForDestination( $a, 'X', 'ctx' ), $a, 'no AI configured → draft returned unchanged' );
    }

    public function testLinkableAssetsMatchTopics(): void {
        $assets = Container::get( LinkableAssets::class );
        $this->assertGreaterThan( 5, count( $assets->all() ) );
        $best = $assets->bestFor( 'how to read a certificate of analysis coa for peptides', 'guide' );
        $this->assertStringContainsString( 'coa', $best['url'] );
        $storage = $assets->bestFor( 'lyophilized peptide handling and storage' );
        $this->assertStringContainsString( 'storage', $storage['url'] );
        $this->assertSame( 'home', $assets->home()['kind'] );
    }
}
