<?php
declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Discovery;

use PPC\LinkGrowth\Discovery\BrokenLinkFinder;
use PPC\LinkGrowth\Discovery\Classifier;
use PPC\LinkGrowth\Discovery\DomainFilter;
use PPC\LinkGrowth\Discovery\ProspectIntake;
use PPC\LinkGrowth\Discovery\QueryGenerator;
use PPC\LinkGrowth\Discovery\SeedCatalog;
use PPC\LinkGrowth\Analysis\PageAnalyzer;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\SearchLog;
use PPC\LinkGrowth\Repositories\Suppression;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class DiscoveryTest extends TestCase {

    public function testDomainFilterReasons(): void {
        $f = Container::get( DomainFilter::class );
        $this->assertSame( 'own_site', $f->reason( 'https://www.purepeptidescanada.test/about/' ) );
        $this->assertSame( 'excluded_platform', $f->reason( 'https://www.facebook.com/groups/x' ) );
        $this->assertSame( 'no_tld', $f->reason( 'http://localhost/x' ) );
        $this->assertSame( 'non_html', $f->reason( 'https://a.test/report.pdf' ) );
        $this->assertNull( $f->reason( 'https://labdirectory.test/add-listing/' ) );
        Container::get( Suppression::class )->add( 'domain', 'blocked.test', 'test' );
        $this->assertSame( 'suppressed', $f->reason( 'https://blocked.test/' ) );
    }

    public function testClassifier(): void {
        $c = Container::get( Classifier::class );
        $d = $c->classify( 'https://labdirectory.test/', 'Canadian Laboratory Suppliers Directory', 'Add your laboratory business listing' );
        $this->assertContains( $d['site_type'], [ 'directory', 'supplier_directory' ] );
        $this->assertContains( $d['opportunity_type'], [ 'directory_listing', 'supplier_listing' ] );
        $r = $c->classify( 'https://resources.test/links/', 'Laboratory resources and links for students', '' );
        $this->assertSame( 'resource_page', $r['site_type'] );
        $e = $c->classify( 'https://biochem.uwaterloo.ca/', 'Department of Biochemistry, University of Waterloo', '' );
        $this->assertSame( 'educational', $e['site_type'] );
        $p = $c->classify( 'https://pods.test/podcast/episode-12', 'Episode 12: Canadian biotech', '' );
        $this->assertSame( 'podcast', $p['site_type'] );
    }

    public function testQueryGeneratorRotatesAndRespectsCooldown(): void {
        $q    = Container::get( QueryGenerator::class );
        $pool = $q->pool();
        $this->assertGreaterThan( 40, count( $pool ) );
        $this->assertCount( count( array_unique( array_map( static fn( $i ) => strtolower( $i['query'] ), $pool ) ) ), $pool, 'no duplicate queries' );
        $first = $q->next( 5 );
        $this->assertCount( 5, $first );
        foreach ( $first as $item ) {
            Container::get( SearchLog::class )->record( 'test', $item['query'], 0, 0 );
        }
        $second = $q->next( 5 );
        $this->assertEmpty( array_intersect( array_column( $first, 'query' ), array_column( $second, 'query' ) ) );
        $brand = $q->brandQueries();
        $this->assertNotEmpty( $brand );
        $this->assertStringContainsString( '-site:purepeptidescanada.test', $brand[0] );
    }

    public function testProspectIntakeCreatesProspectAndOpportunityOnce(): void {
        $intake = Container::get( ProspectIntake::class );
        $r1 = $intake->fromUrl( 'https://labdirectory.test/add-listing/?utm_source=x', 'search', 'lab directory canada', [ 'title' => 'Add your laboratory business', 'opportunity_type' => 'directory_listing' ] );
        $this->assertTrue( $r1['created'] );
        $this->assertNotNull( $r1['opportunity_id'] );
        $r2 = $intake->fromUrl( 'https://labdirectory.test/add-listing/', 'seed', '', [] );
        $this->assertFalse( $r2['created'] );
        $this->assertSame( $r1['prospect_id'], $r2['prospect_id'] );
        $this->assertSame( $r1['opportunity_id'], $r2['opportunity_id'] );
        $p = Container::get( Prospects::class )->find( $r1['prospect_id'] );
        $this->assertSame( 'labdirectory.test', $p['domain'] );
        $this->assertSame( 'https://labdirectory.test/', $p['homepage_url'] );
        $this->assertSame( 'discovered', $p['status'] );
        $this->assertSame( 'directory_listing', $p['opportunity_type'] );
        $this->assertSame( 1, Container::get( Opportunities::class )->count( [ 'prospect_id' => $r1['prospect_id'] ] ) );
        $this->assertSame( 1, Container::get( AuditLog::class )->count( [ 'action' => 'prospect.discovered' ] ) );
        $skip = $intake->fromUrl( 'https://facebook.com/x', 'search' );
        $this->assertSame( 'excluded_platform', $skip['skipped'] );
    }

    public function testSeedCatalogImportsCanadianSeeds(): void {
        $seeds = Container::get( SeedCatalog::class );
        $this->assertGreaterThan( 30, count( $seeds->all() ) );
        $r = $seeds->import( Container::get( ProspectIntake::class ) );
        $this->assertGreaterThan( 30, $r['created'] );
        $this->assertNotSame( '', $seeds->importedAt() );
        $p = Container::get( Prospects::class )->findByDomain( 'biotech.ca' );
        $this->assertSame( 'CA', $p['country'] );
        $this->assertSame( 'membership', $p['opportunity_type'] );
    }

    public function testBrokenLinkFinderCreatesRelevantOpportunities(): void {
        $this->loadFixtureSites();
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 0 );
        [ $pid ] = Container::get( Prospects::class )->upsertDiscovered( 'labdirectory.test', [] );
        $page   = ( new PageAnalyzer() )->analyze( $this->fixture( 'sites/labdirectory.test/resources/index.html' ), 'https://labdirectory.test/resources/' );
        $stats  = Container::get( BrokenLinkFinder::class )->scan( $page, $pid, TimeBudget::seconds( 20 ), 10 );
        $this->assertSame( 2, $stats['broken'] );
        $this->assertSame( 2, $stats['opportunities'] );
        $opps = Container::get( Opportunities::class )->findBy( [ 'prospect_id' => $pid, 'type' => 'broken_link' ] );
        $this->assertCount( 2, $opps );
        $details = array_column( $opps, 'details' );
        $broken  = array_column( $details, 'broken_url' );
        $this->assertContains( 'http://deadlab.test/peptide-storage-guide/', $broken );
        $this->assertStringContainsString( 'storage', $details[ array_search( 'http://deadlab.test/peptide-storage-guide/', $broken, true ) ]['suggested_url'] );
    }
}
