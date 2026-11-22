<?php
declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Content;

use LinkGrowth\Autopilot\Content\ClaimsFilter;
use LinkGrowth\Autopilot\Content\ContentGenerator;
use LinkGrowth\Autopilot\Content\FactsRegistry;
use LinkGrowth\Autopilot\Content\LinkableAssets;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Tests\Support\Container;
use LinkGrowth\Autopilot\Tests\Support\Facts;
use LinkGrowth\Autopilot\Tests\Support\Profiles;
use LinkGrowth\Autopilot\Tests\Support\TestCase;

final class ContentTest extends TestCase {

    public function testClaimsFilterFlagsProhibitedLanguageButAllowsNegations(): void {
        $f = Container::get( ClaimsFilter::class );
        $v = $f->check( 'Our fasteners treat injuries, help with weight loss, are 99% pure and FDA approved. Take a dose daily. The best supplier.' );
        $rules = array_unique( array_column( $v, 'rule' ) );
        foreach ( [ 'therapeutic', 'benefit', 'purity_number', 'approval', 'human_use', 'superlative' ] as $r ) {
            $this->assertContains( $r, $rules, "expected rule $r" );
        }
        $this->assertTrue( $f->isClean( 'Products are not for human use and we do not provide dosing guidance. It is not a supplement.' ) );
        $this->assertTrue( $f->isClean( 'Independent HPLC and mass spectrometry reports are listed by batch. Research use only.' ) );
        // The positioning statement is the owner's own fact (a research-supplies business, Fjordline); sanitize()
        // appends it when the copy lacks one.
        Facts::install( Container::get( FactsRegistry::class ), Profiles::FJORDLINE );
        $clean = $f->sanitize( 'We supply research fasteners. Our products cure everything. Reports are listed by batch.' );
        $this->assertStringNotContainsString( 'cure', $clean );
        $this->assertStringEndsWith( Facts::POSITIONING, $clean );
        $this->assertArrayHasKey( 'therapeutic', ClaimsFilter::rules() );
    }

    public function testFactsRegistryOnlyExposesVerifiedFacts(): void {
        // No business fact ships (DESIGN.md §1.7 E); the facts of an unsaved list are the owner's business profile.
        $this->assertSame( [], FactsRegistry::defaults() );
        $facts = Container::get( FactsRegistry::class );
        Profiles::apply( Container::get( Settings::class ) );
        $this->assertNotEmpty( $facts->verified() );
        $facts->replace( [ 'identity' => [ 'statement' => 'Custom statement.', 'verified' => false, 'source' => 'https://x.test', 'group' => 'identity' ], 'research_use_only' => [ 'statement' => 'RUO statement.', 'verified' => true, 'source' => '', 'group' => 'compliance' ] ] );
        $this->assertSame( '', $facts->statement( 'identity' ) );
        $this->assertSame( 'RUO statement.', $facts->statement( 'research_use_only' ) );
        $facts->reset();
        $this->assertSame( 'Northwind Supply Co offers industrial supplies and packaging.', $facts->statement( 'identity' ) );
        $this->assertSame( 'It is based in Portland, OR.', $facts->statement( 'location' ) );
    }

    public function testGeneratorProducesUniqueCompliantCopy(): void {
        Profiles::apply( Container::get( Settings::class ) );
        Facts::install( Container::get( FactsRegistry::class ) );
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
            $this->assertStringContainsString( 'Northwind Supply Co', $text );
        }
        $this->assertSame( $a, $g->description( 'labdirectory.test', 500, 'business' ), 'deterministic per destination' );
        $short = $g->shortDescription( 'labdirectory.test' );
        $this->assertLessThanOrEqual( 160, mb_strlen( $short ) );
        $profile = $g->supplierProfile( 'frasers.com' );
        foreach ( [ 'tagline', 'short_description', 'description', 'products_services', 'differentiators', 'markets', 'customers', 'compliance' ] as $k ) {
            $this->assertNotEmpty( $profile[ $k ] );
        }
        $this->assertTrue( $f->isClean( $g->companyBackground( 'x.test' ) ) );
        $this->assertStringContainsString( 'Lab Testing', $g->resourceContribution( 'x.test', 'Lab Testing and Certificates of Analysis', 'https://northwind.test/lab-testing/', 'Reports by batch.' ) );
        $this->assertSame( $g->rewriteForDestination( $a, 'X', 'ctx' ), $a, 'no AI configured → draft returned unchanged' );
    }

    public function testLinkableAssetsMatchTopics(): void {
        $assets = Container::get( LinkableAssets::class );
        // Without an owner list the plugin assumes only the home page (it cannot know the site's other pages).
        Profiles::apply( Container::get( Settings::class ) );
        $this->assertSame( [ 'https://northwind.test/' ], array_column( $assets->all(), 'url' ) );
        $this->assertSame( 'home', $assets->home()['kind'] );
        // The owner's own list (content.linkable_assets).
        Facts::installAssets( Container::get( Settings::class ) );
        $this->assertGreaterThan( 5, count( $assets->all() ) );
        $best = $assets->bestFor( 'how to read a certificate of analysis coa for fasteners', 'guide' );
        $this->assertStringContainsString( 'coa', $best['url'] );
        $storage = $assets->bestFor( 'lyophilized fastener handling and storage' );
        $this->assertStringContainsString( 'storage', $storage['url'] );
        $this->assertSame( 'home', $assets->home()['kind'] );
    }
}
