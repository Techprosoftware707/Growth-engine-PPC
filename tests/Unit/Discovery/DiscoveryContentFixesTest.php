<?php
/**
 * Regression tests for the discovery-content slice fixes (Discovery side):
 * failed searches/backlink scans, custom seeds added later, broken-link evidence and
 * probe time budgets.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Discovery;

use PPC\LinkGrowth\Analysis\PageAnalyzer;
use PPC\LinkGrowth\Discovery\BrokenLinkFinder;
use PPC\LinkGrowth\Discovery\CompetitorBacklinkSource;
use PPC\LinkGrowth\Discovery\DiscoveryEngine;
use PPC\LinkGrowth\Discovery\MentionFinder;
use PPC\LinkGrowth\Discovery\ProspectIntake;
use PPC\LinkGrowth\Discovery\QueryGenerator;
use PPC\LinkGrowth\Discovery\SeedCatalog;
use PPC\LinkGrowth\Integrations\Contracts\BacklinkSource;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\SearchLog;
use PPC\LinkGrowth\Support\Limits;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class DiscoveryContentFixesTest extends TestCase {

    /** @var array<int,string> queries sent to Serper, in order */
    private array $serperQueries = [];

    private bool $serperDown = true;

    protected function setUp(): void {
        parent::setUp();
        $this->serperQueries = [];
        $this->serperDown    = true;
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 0 );
    }

    private function useSerper(): void {
        Container::get( Secrets::class )->set( 'serper_api_key', 'test-key' );
        HttpFixtures::$handler = function ( string $url, array $args ) {
            if ( ! str_contains( $url, 'serper.dev' ) ) {
                return null;
            }
            $body                  = json_decode( (string) ( $args['body'] ?? '' ), true );
            $this->serperQueries[] = (string) ( $body['q'] ?? '' );
            if ( $this->serperDown ) {
                return [ 'response' => [ 'code' => 401, 'message' => 'Unauthorized' ], 'headers' => [ 'content-type' => 'application/json' ], 'body' => '{"message":"Unauthorized"}', 'cookies' => [] ];
            }
            return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'headers' => [ 'content-type' => 'application/json' ], 'body' => (string) wp_json_encode( [ 'organic' => [ [ 'link' => 'https://labdirectory.test/add-listing/', 'title' => 'Add your laboratory business', 'snippet' => 'Directory', 'position' => 1 ] ] ] ), 'cookies' => [] ];
        };
    }

    /** discovery-content-4: a failed search spends no quota, starts no cooldown and is reported. */
    public function testFailedSearchesAreNotLoggedOrSpent(): void {
        Container::get( Settings::class )->set( 'discovery.seed_catalog', false );
        $this->useSerper();
        $limits = Container::get( Limits::class );
        $before = $limits->remaining( 'discovery_queries' );

        $stats = Container::get( DiscoveryEngine::class )->run( TimeBudget::seconds( 60 ), [ 'max_queries' => 10, 'skip_competitors' => true ] );
        $this->assertSame( 0, $stats['queries_run'] );
        $this->assertCount( 2, $this->serperQueries, 'stops after consecutive failures instead of burning the batch' );
        $this->assertSame( 0, Container::get( SearchLog::class )->count() );
        $this->assertSame( $before, $limits->remaining( 'discovery_queries' ) );
        $this->assertNotEmpty( array_filter( $stats['warnings'], static fn( string $w ): bool => str_contains( $w, 'serper' ) && str_contains( $w, '401' ) ) );
        $failedFirst = $this->serperQueries[0];

        // Once the provider works again, the same queries are run first.
        $this->serperDown    = false;
        $this->serperQueries = [];
        $stats = Container::get( DiscoveryEngine::class )->run( TimeBudget::seconds( 60 ), [ 'max_queries' => 3, 'skip_competitors' => true ] );
        $this->assertSame( 3, $stats['queries_run'] );
        $this->assertSame( $failedFirst, $this->serperQueries[0] );
        $this->assertSame( 3, Container::get( SearchLog::class )->count() );
        $this->assertSame( $before - 3, $limits->remaining( 'discovery_queries' ) );
    }

    /** discovery-content-4: mention scans do not log failed searches (7-day skip). */
    public function testMentionScanDoesNotLogFailedSearches(): void {
        $this->useSerper();
        $scan = Container::get( MentionFinder::class )->scan( [ '"Pure Peptides Canada" -site:purepeptidescanada.test' ], TimeBudget::seconds( 30 ) );
        $this->assertSame( 0, $scan['queries'] );
        $this->assertStringContainsString( 'serper', $scan['warning'] );
        $this->assertSame( 0, Container::get( SearchLog::class )->count() );
        $this->assertFalse( Container::get( SearchLog::class )->ranWithin( '"Pure Peptides Canada" -site:purepeptidescanada.test', 7 ) );
    }

    /** discovery-content-8: the mention crawl timeout fits the remaining budget. */
    public function testMentionCrawlTimeoutFitsBudget(): void {
        $this->useSerper();
        $this->serperDown = false;
        HttpFixtures::add( 'https://labdirectory.test/add-listing/', '<html><head><title>Listing</title></head><body><main><p>Pure Peptides Canada is listed here.</p></main></body></html>' );
        $scan = Container::get( MentionFinder::class )->scan( [ 'brand query' ], TimeBudget::seconds( 20 ) );
        $this->assertSame( 1, $scan['queries'] );
        $pages = array_values( array_filter( HttpFixtures::$requests, static fn( array $r ): bool => str_contains( $r['url'], 'labdirectory.test/add-listing' ) ) );
        $this->assertNotEmpty( $pages );
        $this->assertLessThanOrEqual( 11, (int) $pages[0]['args']['timeout'], 'robots (8s) + page must fit the 20s budget' );
        $this->assertGreaterThanOrEqual( 3, (int) $pages[0]['args']['timeout'] );
        // Not enough budget for robots.txt plus a page: nothing is crawled.
        HttpFixtures::$requests = [];
        Container::get( MentionFinder::class )->scan( [ 'other brand query' ], TimeBudget::seconds( 9 ) );
        $this->assertSame( [], array_filter( HttpFixtures::$requests, static fn( array $r ): bool => str_contains( $r['url'], 'labdirectory.test' ) ) );
    }

    /** discovery-content-4: a failed backlink scan does not start the weekly interval. */
    public function testFailedCompetitorScanIsRetriedAndReported(): void {
        $fake = new class() implements BacklinkSource {
            public string $error = 'HTTP 401 Unauthorized';
            public function id(): string {
                return 'fakelinks';
            }
            public function isConfigured(): bool {
                return true;
            }
            public function lastError(): string {
                return $this->error;
            }
            public function backlinks( string $domain, int $limit = 200, int $perDomain = 3 ): array {
                if ( '' !== $this->error ) {
                    return [];
                }
                return [ [ 'source_url' => 'https://labdirectory.test/suppliers/', 'target_url' => 'https://' . $domain . '/', 'anchor' => 'x', 'nofollow' => false, 'first_seen' => '', 'last_seen' => '', 'source_authority' => null ] ];
            }
        };
        add_filter( 'ppclg_backlink_sources', static fn( array $list ): array => array_merge( $list, [ $fake ] ) );
        Container::get( Settings::class )->set( 'discovery.competitor_domains', [ 'rivalpeptides.test' ] );
        $src = Container::get( CompetitorBacklinkSource::class );

        $this->assertTrue( $src->isDue() );
        $stats = $src->discover( Container::get( ProspectIntake::class ) );
        $this->assertStringContainsString( 'HTTP 401', $stats['warning'] );
        $this->assertSame( '', (string) get_option( CompetitorBacklinkSource::LAST_RUN_OPTION, '' ) );
        $this->assertFalse( $src->isDue(), 'short back-off instead of hammering a failing API' );

        // After the back-off, a working provider runs and starts the weekly interval.
        update_option( CompetitorBacklinkSource::RETRY_OPTION, gmdate( 'c', time() - 60 ), false );
        $this->assertTrue( $src->isDue() );
        $fake->error = '';
        $stats = $src->discover( Container::get( ProspectIntake::class ) );
        $this->assertSame( '', $stats['warning'] );
        $this->assertSame( 1, $stats['backlinks'] );
        $this->assertNotSame( '', (string) get_option( CompetitorBacklinkSource::LAST_RUN_OPTION, '' ) );
        $this->assertFalse( get_option( CompetitorBacklinkSource::RETRY_OPTION, false ) );
        $this->assertFalse( $src->isDue() );
    }

    /** discovery-content-5: custom seeds saved after the first run are imported once. */
    public function testCustomSeedsAddedLaterAreImported(): void {
        $engine = Container::get( DiscoveryEngine::class );
        $first  = $engine->run( TimeBudget::seconds( 30 ), [ 'skip_competitors' => true ] );
        $this->assertGreaterThan( 30, $first['seeds']['created'] );
        $total = Container::get( Prospects::class )->count();

        update_option( 'ppclg_custom_seeds', [ [ 'url' => 'https://canadianlabdirectory.example.ca/add-listing/', 'name' => 'Canadian Lab Directory', 'type' => 'directory', 'opportunity' => 'directory_listing', 'country' => 'CA' ] ], false );
        $second = $engine->run( TimeBudget::seconds( 30 ), [ 'skip_competitors' => true ] );
        $this->assertSame( [ 'seeds' => 1, 'created' => 1 ], $second['seeds'] );
        $this->assertNotNull( Container::get( Prospects::class )->findByDomain( 'example.ca' ) ?? Container::get( Prospects::class )->findByDomain( 'canadianlabdirectory.example.ca' ) );
        $this->assertSame( $total + 1, Container::get( Prospects::class )->count() );

        // Nothing new: no import; a seed prospect the owner deleted is not re-created.
        $third = $engine->run( TimeBudget::seconds( 30 ), [ 'skip_competitors' => true ] );
        $this->assertNull( $third['seeds'] );
        $p = Container::get( Prospects::class )->findByDomain( 'biotech.ca' );
        Container::get( Prospects::class )->delete( (int) $p['id'] );
        $this->assertNull( $engine->run( TimeBudget::seconds( 30 ), [ 'skip_competitors' => true ] )['seeds'] );
        $this->assertNull( Container::get( Prospects::class )->findByDomain( 'biotech.ca' ) );
    }

    /** discovery-content-5: installs that imported before per-seed tracking only pick up custom seeds. */
    public function testLegacyImportOnlyQueuesCustomSeeds(): void {
        update_option( SeedCatalog::IMPORTED_OPTION, '2026-01-01T00:00:00+00:00', false );
        update_option( 'ppclg_custom_seeds', [ [ 'url' => 'https://newdirectory.test/submit/', 'name' => 'New Directory' ] ], false );
        $pending = Container::get( SeedCatalog::class )->pending();
        $this->assertCount( 1, $pending );
        $this->assertSame( 'https://newdirectory.test/submit/', $pending[0]['url'] );
    }

    /** discovery-content-7: a slow (timing-out) link is not a broken link; 404/DNS/refused are. */
    public function testTimeoutsAreNotBrokenLinks(): void {
        HttpFixtures::$handler = static function ( string $url ) {
            if ( str_contains( $url, 'slowlab.test' ) ) {
                return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received' );
            }
            if ( str_contains( $url, 'slowdns.test' ) ) {
                return new \WP_Error( 'http_request_failed', 'cURL error 28: Resolving timed out after 10000 milliseconds' );
            }
            if ( str_contains( $url, 'refused.test' ) ) {
                return new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to refused.test port 443 after 2 ms: Connection refused' );
            }
            if ( str_contains( $url, 'flaky.test' ) ) {
                return new \WP_Error( 'http_request_failed', 'cURL error 35: OpenSSL SSL_connect: Connection reset by peer' );
            }
            return null;
        };
        HttpFixtures::addPattern( '#^https?://deadlab\.test/#', '<html><body>404</body></html>', 404 );
        [ $pid ] = Container::get( Prospects::class )->upsertDiscovered( 'resources2.test', [] );
        $html    = '<html><head><title>Peptide research resources</title></head><body><main><h1>Resources</h1><ul>'
            . '<li><a href="https://slowlab.test/peptide-storage-guide/">Peptide storage guide</a></li>'
            . '<li><a href="https://slowdns.test/hplc-peptide-guide/">HPLC peptide testing guide</a></li>'
            . '<li><a href="https://flaky.test/peptide-coa/">Peptide certificate of analysis guide</a></li>'
            . '<li><a href="https://deadlab.test/peptide-hplc-guide/">Peptide HPLC purity guide</a></li>'
            . '<li><a href="https://refused.test/peptide-handling/">Peptide handling guide</a></li>'
            . '<li><a href="https://nxdomain-lab.test/peptide-coa-guide/">Peptide COA guide</a></li>'
            . '</ul></main></body></html>';
        $page  = ( new PageAnalyzer() )->analyze( $html, 'https://resources2.test/links/' );
        $stats = Container::get( BrokenLinkFinder::class )->scan( $page, $pid, TimeBudget::seconds( 30 ), 10 );
        $this->assertSame( 6, $stats['checked'] );
        $this->assertSame( 3, $stats['broken'] );
        $opps   = Container::get( Opportunities::class )->findBy( [ 'prospect_id' => $pid, 'type' => 'broken_link' ] );
        $byUrl  = array_column( array_column( $opps, 'details' ), 'failure', 'broken_url' );
        ksort( $byUrl );
        $this->assertSame( [ 'https://deadlab.test/peptide-hplc-guide/' => 'not_found', 'https://nxdomain-lab.test/peptide-coa-guide/' => 'dns', 'https://refused.test/peptide-handling/' => 'refused' ], $byUrl );
    }

    /** discovery-content-8: probe timeouts are sized to the remaining budget. */
    public function testBrokenLinkProbesFitTheBudget(): void {
        HttpFixtures::$handler = static function ( string $url ) {
            return str_contains( $url, 'slowlab.test' ) ? new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) : null;
        };
        [ $pid ] = Container::get( Prospects::class )->upsertDiscovered( 'resources3.test', [] );
        $html    = '<html><head><title>Peptide resources</title></head><body><main><ul>'
            . '<li><a href="https://slowlab.test/peptide-storage-guide/">Peptide storage guide</a></li>'
            . '<li><a href="https://slowlab.test/peptide-hplc-guide/">Peptide HPLC guide</a></li>'
            . '</ul></main></body></html>';
        $page = ( new PageAnalyzer() )->analyze( $html, 'https://resources3.test/links/' );

        Container::get( BrokenLinkFinder::class )->scan( $page, $pid, TimeBudget::seconds( 8 ), 10 );
        $this->assertNotEmpty( HttpFixtures::$requests );
        foreach ( HttpFixtures::$requests as $r ) {
            $this->assertLessThanOrEqual( 3, (int) $r['args']['timeout'], 'HEAD+GET must fit an 8s budget' );
        }
        HttpFixtures::$requests = [];
        Container::get( BrokenLinkFinder::class )->scan( $page, $pid, TimeBudget::seconds( 60 ), 10 );
        foreach ( HttpFixtures::$requests as $r ) {
            $this->assertSame( 10, (int) $r['args']['timeout'], 'capped at the normal probe timeout' );
        }
        // Too little budget for even one HEAD+GET: no request at all.
        HttpFixtures::$requests = [];
        $stats = Container::get( BrokenLinkFinder::class )->scan( $page, $pid, TimeBudget::seconds( 5 ), 10 );
        $this->assertSame( 0, $stats['checked'] );
        $this->assertSame( [], HttpFixtures::$requests );
    }

    public function testQueryGeneratorRewind(): void {
        $q     = Container::get( QueryGenerator::class );
        $first = $q->next( 3 );
        $this->assertNotSame( $first[0]['query'], $q->next( 1 )[0]['query'] );
        $q->rewindTo( $first[0]['query'] );
        $this->assertSame( $first[0]['query'], $q->next( 1 )[0]['query'] );
    }
}
