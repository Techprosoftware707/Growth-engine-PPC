<?php
declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Analysis;

use PPC\LinkGrowth\Analysis\ContactExtractor;
use PPC\LinkGrowth\Analysis\PageAnalyzer;
use PPC\LinkGrowth\Analysis\RelevanceScorer;
use PPC\LinkGrowth\Analysis\RiskAnalyzer;
use PPC\LinkGrowth\Analysis\SiteProfiler;
use PPC\LinkGrowth\Analysis\SubmissionPageFinder;
use PPC\LinkGrowth\Scoring\ScoringEngine;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class ProfilerRelevanceRiskTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $this->loadFixtureSites();
        Container::get( \PPC\LinkGrowth\Support\Settings::class )->set( 'advanced.crawl_delay_seconds', 0 );
        Container::get( \PPC\LinkGrowth\Support\Settings::class )->set( 'providers.rdap.enabled', false );
    }

    public function testProfileOfCanadianDirectory(): void {
        $p = Container::get( SiteProfiler::class )->profile( 'labdirectory.test' );
        $this->assertTrue( $p->reachable );
        $this->assertSame( 'LabDirectory Canada', $p->name );
        $this->assertSame( 'CA', $p->country );
        $this->assertGreaterThanOrEqual( 60, $p->countryConfidence );
        $this->assertSame( 'https://labdirectory.test/contact/', $p->contactUrl );
        $this->assertSame( 'en', $p->language );
        $this->assertSame( '2026-10-20', $p->lastContentDate );
        $emails = array_column( $p->contacts, 'email' );
        $this->assertContains( 'editor@labdirectory.test', $emails );
        $this->assertNotContains( 'noreply@labdirectory.test', $emails );
        $editor = array_values( array_filter( $p->contacts, static fn( $c ) => 'editor@labdirectory.test' === $c['email'] ) )[0];
        $this->assertSame( 'editor', $editor['role'] );
        $this->assertSame( 'Jane Okafor', $editor['name'] );
        $this->assertGreaterThan( 70, $editor['confidence'] );
        $this->assertTrue( $p->https );
        $arr = $p->toArray();
        $this->assertSame( 'LabDirectory Canada', $arr['name'] );
        $this->assertArrayHasKey( 'home', $arr );
    }

    public function testRelevanceHighForDirectoryLowForSpam(): void {
        $scorer = Container::get( RelevanceScorer::class );
        $home   = ( new PageAnalyzer() )->analyze( $this->fixture( 'sites/labdirectory.test/index.html' ), 'https://labdirectory.test/' );
        $r      = $scorer->score( [ $home ], 'CA', 80 );
        $this->assertGreaterThanOrEqual( 70, $r['topical'] );
        $this->assertGreaterThanOrEqual( 80, $r['geo'] );
        $this->assertGreaterThanOrEqual( 70, $r['score'] );
        $this->assertArrayHasKey( 'laboratory', $r['matched'] );
        $spam = ( new PageAnalyzer() )->analyze( $this->fixture( 'sites/spam.test/index.html' ), 'https://spam.test/' );
        $s    = $scorer->score( [ $spam ], '', 0 );
        $this->assertLessThan( 20, $s['topical'] );
        $this->assertNotEmpty( $s['negative'] );
    }

    public function testRiskAnalyzerHardRejectsSpamAndPassesDirectory(): void {
        $profiler = Container::get( SiteProfiler::class );
        $risk     = Container::get( RiskAnalyzer::class );
        $spam     = $profiler->profile( 'spam.test' );
        $r        = $risk->analyze( $spam, [ 'misuse' => [] ] );
        $this->assertTrue( $r['hard_reject'] );
        $this->assertContains( 'spam_terms', $r['flags'] );
        $this->assertContains( 'link_selling', $r['flags'] );
        $this->assertContains( 'hidden_links', $r['flags'] );
        $this->assertContains( 'pbn_footprint', $r['flags'] );
        $good = $risk->analyze( $profiler->profile( 'labdirectory.test' ), [] );
        $this->assertFalse( $good['hard_reject'] );
        $this->assertLessThan( 30, $good['score'] );
    }

    public function testScoringEngineClassifiesAndReportsConfidence(): void {
        $profiler = Container::get( SiteProfiler::class );
        $engine   = Container::get( ScoringEngine::class );
        $this->assertSame( 100, array_sum( ScoringEngine::WEIGHTS ) );
        $this->assertSame( 'exceptional', ScoringEngine::classify( 95 ) );
        $this->assertSame( 'high', ScoringEngine::classify( 80 ) );
        $this->assertSame( 'good', ScoringEngine::classify( 70 ) );
        $this->assertSame( 'moderate', ScoringEngine::classify( 50 ) );
        $this->assertSame( 'reject', ScoringEngine::classify( 49 ) );

        $profile   = $profiler->profile( 'labdirectory.test' );
        $relevance = Container::get( RelevanceScorer::class )->score( [ $profile->home ], $profile->country, $profile->countryConfidence );
        $risk      = Container::get( RiskAnalyzer::class )->analyze( $profile, $relevance );
        $noData    = $engine->score( $profile, $relevance, $risk, [] );
        $this->assertSame( 70, $noData['confidence'], 'traffic (20) and authority (10) unknown without providers' );
        $this->assertFalse( $noData['auto_reject'] );
        $this->assertStringContainsString( 'unknown', $noData['components']['organic_traffic']['source'] );
        $withData = $engine->score( $profile, $relevance, $risk, [ 'organic_traffic' => 12000, 'organic_keywords' => 900, 'authority_scores' => [ 'seranking' => 45 ], 'referring_domains' => 300, 'traffic_source' => 'seranking (ca)' ] );
        $this->assertSame( 100, $withData['confidence'] );
        $this->assertGreaterThan( $noData['total'], $withData['total'] );
        $this->assertGreaterThanOrEqual( 70, $withData['total'] );
        $this->assertSame( 'page analysis', $withData['components']['editorial_quality']['source'] );

        $spamProfile = $profiler->profile( 'spam.test' );
        $spamRel     = Container::get( RelevanceScorer::class )->score( [ $spamProfile->home ], '', 0 );
        $spamRisk    = Container::get( RiskAnalyzer::class )->analyze( $spamProfile, $spamRel );
        $spamScore   = $engine->score( $spamProfile, $spamRel, $spamRisk, [ 'organic_traffic' => 500000, 'authority_scores' => [ 'ahrefs' => 70 ] ] );
        $this->assertTrue( $spamScore['auto_reject'] );
        $this->assertLessThanOrEqual( 40, $spamScore['total'] );
        $this->assertSame( 'reject', $spamScore['class'] );
    }

    public function testSubmissionPageFinderLocatesListingForm(): void {
        $home   = ( new PageAnalyzer() )->analyze( $this->fixture( 'sites/labdirectory.test/index.html' ), 'https://labdirectory.test/' );
        $finder = Container::get( SubmissionPageFinder::class );
        $best   = $finder->find( $home, 3 );
        $this->assertSame( 'https://labdirectory.test/add-listing/', $best['url'] );
        $this->assertSame( 'listing_submission', $best['kind'] );
        $this->assertNotNull( $best['form'] );
        $this->assertGreaterThanOrEqual( 100, $best['score'] );
    }

    public function testContactExtractorRoles(): void {
        $ex = Container::get( ContactExtractor::class );
        $this->assertSame( 'editor', $ex->role( 'editor' ) );
        $this->assertSame( 'partnerships', $ex->role( 'sponsorship' ) );
        $this->assertSame( 'noreply', $ex->role( 'no-reply' ) );
        $this->assertSame( 'person', $ex->role( 'jane.okafor' ) );
        $this->assertSame( 'general', $ex->role( 'hello' ) );
        $this->assertSame( 'support', $ex->role( 'billing' ) );
    }
}
