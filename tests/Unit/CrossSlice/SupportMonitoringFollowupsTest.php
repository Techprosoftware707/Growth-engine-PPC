<?php
/**
 * Cross-slice follow-ups for the support / monitoring / discovery group: the test bootstrap stubs
 * wp_check_invalid_utf8() and rest_authorization_required_code() ([8]) and its sanitize_file_name() stub
 * rewrites multi-extension names like core ([15]); a search request refused for its API key does not count
 * against the daily limit ([39]), and a mention scan then falls back to the next provider; crawl() takes a
 * robots.txt timeout from the caller's budget ([40]), and a shortened robots.txt request that times out is not
 * cached for other callers; Backlinks::dueForCheck() includes unverified rows whose re-check date has passed
 * and leaves stopped rows out ([42]); broken-link probes reserve the robots.txt request and the host's crawl
 * delay, and a host with a long Crawl-delay only defers its own links ([45]); [hidden] always hides inside the
 * admin app ([49]).
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\CrossSlice;

use PPC\LinkGrowth\Admin\Rest\BacklinksController;
use PPC\LinkGrowth\Analysis\PageAnalyzer;
use PPC\LinkGrowth\Analysis\Taxonomy;
use PPC\LinkGrowth\Content\LinkableAssets;
use PPC\LinkGrowth\Database\Schema;
use PPC\LinkGrowth\Discovery\BrokenLinkFinder;
use PPC\LinkGrowth\Discovery\MentionFinder;
use PPC\LinkGrowth\Integrations\Search\AbstractSearch;
use PPC\LinkGrowth\Integrations\Search\BraveSearch;
use PPC\LinkGrowth\Integrations\Search\GoogleCustomSearch;
use PPC\LinkGrowth\Integrations\Search\Serper;
use PPC\LinkGrowth\Monitoring\BacklinkMonitor;
use PPC\LinkGrowth\Outreach\OutreachEngine;
use PPC\LinkGrowth\Outreach\Unsubscribe;
use PPC\LinkGrowth\Repositories\Backlinks;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\SearchLog;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Http\RobotsTxt;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_Error;
use WP_REST_Request;

final class SupportMonitoringFollowupsTest extends TestCase {

    private const TARGET = 'https://purepeptidescanada.test/';

    private mixed $previousCaps = null;

    /** @var float[] seconds the client was asked to wait */
    private array $slept = [];

    protected function setUp(): void {
        parent::setUp();
        $this->previousCaps = $GLOBALS['ppclg_test_caps'] ?? null;
        $this->slept        = [];
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 0 );
    }

    protected function tearDown(): void {
        if ( null === $this->previousCaps ) {
            unset( $GLOBALS['ppclg_test_caps'] );
        } else {
            $GLOBALS['ppclg_test_caps'] = $this->previousCaps;
        }
        $GLOBALS['ppclg_test_user_id'] = 0;
        parent::tearDown();
    }

    /** A client whose waits are recorded instead of slept. */
    private function client(): Client {
        return new Client(
            Container::get( Settings::class ),
            Container::get( Logger::class ),
            new RobotsTxt( Container::get( Logger::class ) ),
            function ( float $seconds ): void {
                $this->slept[] = $seconds;
            }
        );
    }

    private function finder( Client $client ): BrokenLinkFinder {
        return new BrokenLinkFinder( $client, Container::get( PageAnalyzer::class ), Container::get( Taxonomy::class ), Container::get( LinkableAssets::class ), Container::get( Opportunities::class ), Container::get( Logger::class ) );
    }

    /** @return array<int,array{method:string,url:string,args:array<string,mixed>}> requests to $url */
    private static function requestsTo( string $url ): array {
        return array_values( array_filter( HttpFixtures::$requests, static fn( array $r ): bool => $r['url'] === $url ) );
    }

    private function ago( int $days ): string {
        return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
    }

    // ------------------------------------------------------------------ [8] bootstrap stubs

    public function testInvalidUtf8AndAuthorizationCodeStubsBehaveLikeCore(): void {
        $this->assertSame( 'Café ok', wp_check_invalid_utf8( 'Café ok' ) );
        $this->assertSame( '', wp_check_invalid_utf8( "bad \xC3\x28 bytes" ) );
        $this->assertSame( "bad \u{FFFD}( bytes", wp_check_invalid_utf8( "bad \xC3\x28 bytes", true ) );
        $this->assertSame( '', wp_check_invalid_utf8( '' ) );

        $GLOBALS['ppclg_test_user_id'] = 0;
        $this->assertSame( 401, rest_authorization_required_code() );
        $GLOBALS['ppclg_test_user_id'] = 7;
        $this->assertSame( 403, rest_authorization_required_code() );
    }

    public function testPermissionRefusalRunsEndToEnd(): void {
        $GLOBALS['ppclg_test_caps'] = [];
        $controller                 = new BacklinksController( Container::plugin()->container() );

        $GLOBALS['ppclg_test_user_id'] = 0;
        $anonymous                     = $controller->permission( new WP_REST_Request( 'GET', '/ppclg/v1/backlinks' ) );
        $this->assertInstanceOf( WP_Error::class, $anonymous );
        $this->assertSame( 'ppclg_forbidden', $anonymous->get_error_code() );
        $this->assertSame( 401, $anonymous->get_error_data()['status'] );

        $GLOBALS['ppclg_test_user_id'] = 7;
        $member                        = $controller->permissionWrite( new WP_REST_Request( 'POST', '/ppclg/v1/backlinks' ) );
        $this->assertInstanceOf( WP_Error::class, $member );
        $this->assertSame( 403, $member->get_error_data()['status'] );

        $GLOBALS['ppclg_test_caps'] = [ 'manage_options' => true ];
        $this->assertTrue( $controller->permission( new WP_REST_Request( 'GET', '/ppclg/v1/backlinks' ) ) );
    }

    public function testOutreachMessageEditRunsEndToEnd(): void {
        $db  = $GLOBALS['wpdb'];
        $now = gmdate( 'Y-m-d H:i:s' );
        $db->insert( Schema::table( 'prospects' ), [ 'domain' => 'editlab.test', 'name' => 'Edit Lab', 'status' => 'qualified', 'first_seen' => $now, 'updated_at' => $now ] );
        $pid = (int) $db->insert_id;
        $db->insert( Schema::table( 'contacts' ), [ 'prospect_id' => $pid, 'email' => 'ed@editlab.test', 'name' => 'Ed', 'role' => 'editor', 'confidence' => 80, 'consent_basis' => 'conspicuous_publication', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] );
        $cid = (int) $db->insert_id;
        $db->insert( Schema::table( 'campaigns' ), [ 'name' => 'Camp', 'approach' => 'resource_page', 'status' => 'active', 'daily_limit' => 20, 'settings' => '[]', 'stats' => '[]', 'created_at' => $now, 'updated_at' => $now ] );
        $camp  = (int) $db->insert_id;
        $token = bin2hex( random_bytes( 16 ) );
        $db->insert( Schema::table( 'outreach_messages' ), [ 'campaign_id' => $camp, 'prospect_id' => $pid, 'contact_id' => $cid, 'approach' => 'resource_page', 'sequence' => 1, 'subject' => 'Old subject', 'body' => 'Old body ' . Unsubscribe::urlFor( $token ), 'status' => 'draft', 'variant' => 'rp-a', 'unsubscribe_token' => $token, 'created_at' => $now, 'updated_at' => $now ] );
        $mid    = (int) $db->insert_id;
        $engine = Container::get( OutreachEngine::class );

        // Invalid UTF-8 is discarded like core does, so the body counts as empty.
        $bad = $engine->updateMessage( $mid, 'A lab reference for your list', "Hello \xC3\x28 there", 1 );
        $this->assertFalse( $bad['ok'] );
        $this->assertContains( 'Body is empty.', $bad['violations'] );

        $good = $engine->updateMessage( $mid, 'A lab reference for your list', "Hello,\n\nI came across your resource list and thought our laboratory guide might fit.\n\nJane", 1 );
        $this->assertTrue( $good['ok'], implode( ' ', $good['violations'] ) );
    }

    // ------------------------------------------------------------------ [15] sanitize_file_name stub

    public function testSanitizeFileNameStubRewritesIntermediateExtensionsLikeCore(): void {
        $this->assertSame( 'ppclg-backup-2026-11-12.sql_.gz', sanitize_file_name( 'ppclg-backup-2026-11-12.sql.gz' ) );
        $this->assertSame( 'archive.tar.gz', sanitize_file_name( 'archive.tar.gz' ), 'tar is an allowed upload type' );
        $this->assertSame( 'my.data_.v2.csv', sanitize_file_name( 'my.data.v2.csv' ) );
        $this->assertSame( 'photo.jpg', sanitize_file_name( 'photo.jpg' ) );
        $this->assertSame( 'export.csv', sanitize_file_name( '..export..csv-' ) );

        $GLOBALS['ppclg_test_caps'] = [];
        $this->assertSame( 'bundle.js_.gz', sanitize_file_name( 'bundle.js.gz' ) );
        $GLOBALS['ppclg_test_caps'] = [ 'unfiltered_html' => true ];
        $this->assertSame( 'bundle.js.gz', sanitize_file_name( 'bundle.js.gz' ) );
    }

    // ------------------------------------------------------------------ [39] search quota

    private function serper(): Serper {
        Container::get( Secrets::class )->set( 'serper_api_key', 'test-key' );
        return new Serper( Container::get( Secrets::class ), Container::get( Settings::class ), Container::get( Client::class ), Container::get( Logger::class ) );
    }

    private static function answer( string $host, int $status, string $body ): void {
        HttpFixtures::$handler = static fn( string $url ) => str_contains( $url, $host )
            ? [ 'response' => [ 'code' => $status, 'message' => '' ], 'headers' => [ 'content-type' => 'application/json' ], 'body' => $body, 'cookies' => [] ]
            : null;
    }

    public function testSearchRefusedForItsKeyDoesNotCountAgainstTheDailyLimit(): void {
        $serper = $this->serper();

        self::answer( 'serper.dev', 403, '{"message":"Unauthorized.","statusCode":403}' );
        $this->assertSame( [], $serper->search( 'peptide directory' ) );
        $this->assertStringContainsString( 'HTTP 403', $serper->lastError(), 'the failure is still reported' );
        $this->assertSame( 0, $serper->usedToday() );

        self::answer( 'serper.dev', 401, '{"message":"Unauthorized"}' );
        $serper->search( 'peptide directory' );
        $this->assertSame( 0, $serper->usedToday() );

        // Requests the provider ran or may have billed still count.
        self::answer( 'serper.dev', 500, 'Internal error' );
        $serper->search( 'peptide directory' );
        $this->assertSame( 1, $serper->usedToday() );
        self::answer( 'serper.dev', 403, '{"message":"Quota exceeded for this key"}' );
        $serper->search( 'peptide directory' );
        $this->assertSame( 2, $serper->usedToday() );
        self::answer( 'serper.dev', 200, '{"organic":[{"link":"https://labdirectory.test/","title":"Lab directory"}]}' );
        $this->assertCount( 1, $serper->search( 'peptide directory' ) );
        $this->assertSame( 3, $serper->usedToday() );
        $this->assertSame( 97, $serper->remainingToday() );

        // A counter left from an earlier day is replaced, and the refund leaves today's count at 0.
        update_option( AbstractSearch::OPTION, [ 'date' => '2001-01-01', 'serper' => 5 ], false );
        self::answer( 'serper.dev', 401, '{"message":"Unauthorized"}' );
        $serper->search( 'peptide directory' );
        $this->assertSame( [ 'date' => gmdate( 'Y-m-d' ), 'serper' => 0 ], get_option( AbstractSearch::OPTION ) );
    }

    public function testBraveAndGoogleKeyErrorsAreRefunded(): void {
        $secrets = Container::get( Secrets::class );
        $secrets->set( 'brave_api_key', 'brave-key' );
        $secrets->set( 'google_cse_api_key', 'google-key' );
        $secrets->set( 'google_cse_cx', 'cx-id' );
        $brave  = new BraveSearch( $secrets, Container::get( Settings::class ), Container::get( Client::class ), Container::get( Logger::class ) );
        $google = new GoogleCustomSearch( $secrets, Container::get( Settings::class ), Container::get( Client::class ), Container::get( Logger::class ) );

        self::answer( 'api.search.brave.com', 422, '{"type":"ErrorResponse","error":{"code":"SUBSCRIPTION_TOKEN_INVALID","detail":"The provided subscription token is invalid.","status":422}}' );
        $brave->search( 'peptide directory' );
        $this->assertSame( 0, $brave->usedToday() );
        self::answer( 'api.search.brave.com', 429, '{"type":"ErrorResponse","error":{"code":"RATE_LIMITED","status":429}}' );
        $brave->search( 'peptide directory' );
        $this->assertSame( 1, $brave->usedToday() );

        self::answer( 'googleapis.com', 400, '{"error":{"code":400,"message":"API key not valid. Please pass a valid API key.","status":"INVALID_ARGUMENT","details":[{"reason":"API_KEY_INVALID"}]}}' );
        $google->search( 'peptide directory' );
        $this->assertSame( 0, $google->usedToday() );
        self::answer( 'googleapis.com', 403, '{"error":{"code":403,"message":"Quota exceeded for quota metric \'Queries\'.","errors":[{"domain":"usageLimits","reason":"dailyLimitExceeded"}]}}' );
        $google->search( 'peptide directory' );
        $this->assertSame( 1, $google->usedToday() );
        self::answer( 'googleapis.com', 400, '{"error":{"code":400,"message":"Invalid value for start","status":"INVALID_ARGUMENT"}}' );
        $google->search( 'peptide directory' );
        $this->assertSame( 2, $google->usedToday(), 'other 400s still count' );
    }

    // ------------------------------------------------------------------ [40] robots.txt timeout from the budget

    public function testCrawlTakesTheRobotsTimeoutFromTheCaller(): void {
        $client = $this->client();
        foreach ( [ 'rt1.test', 'rt2.test', 'rt3.test', 'rt4.test' ] as $host ) {
            HttpFixtures::add( 'https://' . $host . '/robots.txt', '', 404, [ 'content-type' => 'text/plain' ] );
            HttpFixtures::add( 'https://' . $host . '/page', '<html><body>ok</body></html>' );
        }
        $this->assertTrue( $client->crawl( 'https://rt1.test/page', [ 'timeout' => 5, 'robots_timeout' => 4 ] )->ok() );
        $this->assertSame( 4, self::requestsTo( 'https://rt1.test/robots.txt' )[0]['args']['timeout'] );
        $this->assertSame( 5, self::requestsTo( 'https://rt1.test/page' )[0]['args']['timeout'] );

        $client->crawl( 'https://rt2.test/page' );
        $this->assertSame( 8, self::requestsTo( 'https://rt2.test/robots.txt' )[0]['args']['timeout'], 'default unchanged' );
        $client->crawl( 'https://rt3.test/page', [ 'robots_timeout' => 1 ] );
        $this->assertSame( 3, self::requestsTo( 'https://rt3.test/robots.txt' )[0]['args']['timeout'], 'never below the client floor' );
        $client->crawl( 'https://rt4.test/page', [ 'robots_timeout' => 30 ] );
        $this->assertSame( 8, self::requestsTo( 'https://rt4.test/robots.txt' )[0]['args']['timeout'], 'never above 8 s' );
    }

    public function testMentionScanWithLessThanTwelveSecondsLeftStillCrawls(): void {
        Container::get( Secrets::class )->set( 'serper_api_key', 'test-key' );
        HttpFixtures::$handler = static fn( string $url ) => str_contains( $url, 'serper.dev' )
            ? [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'headers' => [ 'content-type' => 'application/json' ], 'body' => (string) wp_json_encode( [ 'organic' => [ [ 'link' => 'https://mentions9.test/article/', 'title' => 'Article', 'snippet' => '', 'position' => 1 ] ] ] ), 'cookies' => [] ]
            : null;
        HttpFixtures::add( 'https://mentions9.test/article/', '<html><head><title>Article</title></head><body><main><p>Pure Peptides Canada supplies labs.</p></main></body></html>' );

        $scan = Container::get( MentionFinder::class )->scan( [ 'brand query nine' ], TimeBudget::seconds( 9 ) );
        $this->assertSame( 1, $scan['pages_checked'], 'the old fixed 8 s robots.txt reservation needed 12 s' );
        $robots = self::requestsTo( 'https://mentions9.test/robots.txt' );
        $page   = self::requestsTo( 'https://mentions9.test/article/' );
        $this->assertCount( 1, $robots );
        $this->assertCount( 1, $page );
        $this->assertGreaterThanOrEqual( 3, $page[0]['args']['timeout'] );
        $this->assertLessThanOrEqual( 8, $robots[0]['args']['timeout'] + $page[0]['args']['timeout'], 'robots.txt + page fit the 9 s budget' );
    }

    public function testAShortenedRobotsTxtTimeoutIsNotCachedForOtherCallers(): void {
        // robots.txt answers, but not within 3 s.
        HttpFixtures::$handler = static function ( string $url, array $args ) {
            if ( str_ends_with( $url, '/robots.txt' ) && str_contains( $url, 'slowrobots' ) ) {
                return (int) $args['timeout'] <= 3
                    ? new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 3001 milliseconds' )
                    : [ 'response' => [ 'code' => 404, 'message' => '' ], 'headers' => [], 'body' => '', 'cookies' => [] ];
            }
            return null;
        };
        HttpFixtures::add( 'https://slowrobots1.test/a/', '<html><body>a</body></html>' );
        HttpFixtures::add( 'https://slowrobots1.test/b/', '<html><body>b</body></html>' );
        $key    = 'ppclg_robots_' . md5( 'https://slowrobots1.test' );
        $client = $this->client();

        $short = $client->crawl( 'https://slowrobots1.test/a/', [ 'timeout' => 3, 'robots_timeout' => 3 ] );
        $this->assertSame( 'robots_disallow', $short->errorCode, 'still not crawled without robots.txt' );
        $this->assertArrayNotHasKey( $key, $GLOBALS['ppclg_test_transients'], 'not cached for other callers' );
        // Another short crawl in the same request reuses the failure instead of asking again.
        $this->assertSame( 'robots_disallow', $client->crawl( 'https://slowrobots1.test/b/', [ 'timeout' => 3, 'robots_timeout' => 3 ] )->errorCode );
        $this->assertCount( 1, self::requestsTo( 'https://slowrobots1.test/robots.txt' ) );
        // A full-length crawl asks again and gets through.
        $this->assertTrue( $client->crawl( 'https://slowrobots1.test/b/' )->ok() );
        $robots = self::requestsTo( 'https://slowrobots1.test/robots.txt' );
        $this->assertCount( 2, $robots );
        $this->assertSame( 8, (int) $robots[1]['args']['timeout'] );
        $this->assertSame( 404, $GLOBALS['ppclg_test_transients'][ $key ]['value']['status'] );

        // A later request (fresh client) with the default timeout is not blocked by an earlier short failure.
        $this->client()->crawl( 'https://slowrobots2.test/a/', [ 'robots_timeout' => 3 ] );
        $this->assertArrayNotHasKey( 'ppclg_robots_' . md5( 'https://slowrobots2.test' ), $GLOBALS['ppclg_test_transients'] );
        HttpFixtures::add( 'https://slowrobots2.test/a/', '<html><body>a</body></html>' );
        $this->assertTrue( $this->client()->crawl( 'https://slowrobots2.test/a/' )->ok() );

        // Short link probes behave the same way.
        $probe = $this->client()->probe( 'https://slowrobots3.test/page', [ 'timeout' => 3 ] );
        $this->assertSame( 'robots_unavailable', $probe->errorCode );
        $this->assertArrayNotHasKey( 'ppclg_robots_' . md5( 'https://slowrobots3.test' ), $GLOBALS['ppclg_test_transients'] );

        // A full-length robots.txt request that times out is still cached for an hour, as before.
        HttpFixtures::$handler = static fn( string $url ) => str_contains( $url, 'deadrobots.test' ) ? new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 8001 milliseconds' ) : null;
        $this->assertSame( 'robots_disallow', $this->client()->crawl( 'https://deadrobots.test/a/' )->errorCode );
        $cached = $GLOBALS['ppclg_test_transients'][ 'ppclg_robots_' . md5( 'https://deadrobots.test' ) ] ?? null;
        $this->assertIsArray( $cached );
        $this->assertSame( 503, $cached['value']['status'] );
        $this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $cached['expires'], 2 );
        // Non-timeout failures of a short request (the host does not resolve) are cached as before.
        HttpFixtures::$handler = static fn( string $url ) => str_contains( $url, 'nxrobots.test' ) ? new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: nxrobots.test' ) : null;
        $this->client()->crawl( 'https://nxrobots.test/a/', [ 'robots_timeout' => 3 ] );
        $this->assertArrayHasKey( 'ppclg_robots_' . md5( 'https://nxrobots.test' ), $GLOBALS['ppclg_test_transients'] );
    }

    public function testMentionScanFallsBackWhenTheFirstProviderRejectsItsKey(): void {
        $secrets = Container::get( Secrets::class );
        $secrets->set( 'serper_api_key', 'revoked-key' );
        $secrets->set( 'brave_api_key', 'brave-key' );
        HttpFixtures::$handler = static function ( string $url ) {
            if ( str_contains( $url, 'serper.dev' ) ) {
                return [ 'response' => [ 'code' => 401, 'message' => 'Unauthorized' ], 'headers' => [ 'content-type' => 'application/json' ], 'body' => '{"message":"Unauthorized"}', 'cookies' => [] ];
            }
            if ( str_contains( $url, 'api.search.brave.com' ) ) {
                return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'headers' => [ 'content-type' => 'application/json' ], 'body' => (string) wp_json_encode( [ 'web' => [ 'results' => [ [ 'url' => 'https://mentions-brave.test/article/', 'title' => 'Article', 'description' => '' ] ] ] ] ), 'cookies' => [] ];
            }
            return null;
        };
        HttpFixtures::add( 'https://mentions-brave.test/article/', '<html><head><title>Article</title></head><body><main><p>Pure Peptides Canada supplies labs.</p></main></body></html>' );

        // Serper has the most queries left, so it is tried first ('auto'), and its refused key is refunded:
        // it stays first, so without a fallback every mention scan would stop on it.
        $scan = Container::get( MentionFinder::class )->scan( [ 'brand query fallback' ], TimeBudget::seconds( 30 ) );
        $this->assertSame( 1, $scan['queries'] );
        $this->assertSame( 1, $scan['pages_checked'] );
        $this->assertSame( '', $scan['warning'] );
        $this->assertNotEmpty( self::requestsTo( 'https://mentions-brave.test/article/' ) );
        $log = Container::get( SearchLog::class )->findBy( [] );
        $this->assertCount( 1, $log );
        $this->assertSame( 'brave', $log[0]['provider'] );

        // When every provider fails, the query is not logged and each failure is reported.
        HttpFixtures::$handler = static fn( string $url ) => str_contains( $url, 'serper.dev' ) || str_contains( $url, 'api.search.brave.com' )
            ? [ 'response' => [ 'code' => 503, 'message' => '' ], 'headers' => [], 'body' => 'down', 'cookies' => [] ]
            : null;
        $scan = Container::get( MentionFinder::class )->scan( [ 'brand query both down' ], TimeBudget::seconds( 30 ) );
        $this->assertSame( 0, $scan['queries'] );
        $this->assertStringContainsString( 'Search provider "serper" failed', $scan['warning'] );
        $this->assertStringContainsString( 'Search provider "brave" failed', $scan['warning'] );
        $this->assertStringContainsString( 'will be retried', $scan['warning'] );
        $this->assertCount( 1, Container::get( SearchLog::class )->findBy( [] ) );
    }

    // ------------------------------------------------------------------ [42] due rows

    public function testDueForCheckIncludesUnverifiedRowsPastTheirRecheckDateOnly(): void {
        $repo = Container::get( Backlinks::class );
        $add  = static fn( string $src, array $data ): int => $repo->upsert( $src, self::TARGET, $data )[0];

        $activeDue    = $add( 'https://a.test/links', [ 'status' => Backlinks::STATUS_ACTIVE, 'next_check_at' => $this->ago( 2 ) ] );
        $activeLater  = $add( 'https://b.test/links', [ 'status' => Backlinks::STATUS_ACTIVE, 'next_check_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ] );
        $robotsDue    = $add( 'https://c.test/members/links', [ 'status' => Backlinks::STATUS_UNVERIFIED, 'next_check_at' => $this->ago( 1 ) ] );
        $stopped      = $add( 'https://d.test/old', [ 'status' => Backlinks::STATUS_UNVERIFIED, 'next_check_at' => null ] );
        $lostDue      = $add( 'https://e.test/links', [ 'status' => Backlinks::STATUS_LOST, 'next_check_at' => $this->ago( 3 ) ] );
        $robotsLater  = $add( 'https://f.test/members/links', [ 'status' => Backlinks::STATUS_UNVERIFIED, 'next_check_at' => gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ) ] );
        unset( $activeLater, $stopped, $robotsLater );

        $ids = static fn( array $rows ): array => array_map( static fn( array $r ): int => (int) $r['id'], $rows );
        $this->assertSame( [ $lostDue, $activeDue, $robotsDue ], $ids( $repo->dueForCheck( 10 ) ), 'oldest first, any status, stopped and future rows left out' );
        $this->assertSame( [ $lostDue ], $ids( $repo->dueForCheck( 1 ) ) );

        // The dashboard counter (and MonitorBacklinksJob::moreDue / Pipeline::monitorDue, which ask dueForCheck( 1 )) agree.
        $GLOBALS['ppclg_test_caps'] = [ 'manage_options' => true ];
        $summary = ( new BacklinksController( Container::plugin()->container() ) )->summary( new WP_REST_Request( 'GET', '/ppclg/v1/backlinks/summary' ) );
        $this->assertSame( 3, $summary['due_for_check'] );
    }

    public function testEveryInsertPathSetsANextCheckDate(): void {
        $repo     = Container::get( Backlinks::class );
        [ $id ]   = $repo->upsert( 'https://plain.test/links', self::TARGET );
        $this->assertNotNull( $repo->find( $id )['next_check_at'] );
        $this->assertContains( $id, array_map( static fn( array $r ): int => (int) $r['id'], $repo->dueForCheck( 10 ) ) );

        $manual = Container::get( BacklinkMonitor::class )->addManual( 'https://manual.test/resources', self::TARGET, 1 );
        $this->assertGreaterThan( 0, $manual );
        $this->assertNotNull( $repo->find( $manual )['next_check_at'] );
        $this->assertContains( $manual, array_map( static fn( array $r ): int => (int) $r['id'], $repo->dueForCheck( 10 ) ) );
    }

    // ------------------------------------------------------------------ [45] probe budget

    public function testDelayForReportsTheSettingOrAKnownRobotsCrawlDelay(): void {
        $client = $this->client();
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 2 );
        $this->assertSame( 2.0, $client->delayFor( 'https://waiting.test/page' ) );
        $this->assertSame( [], HttpFixtures::$requests, 'robots.txt is never fetched to answer' );

        HttpFixtures::add( 'https://waiting.test/robots.txt', "User-agent: *\nCrawl-delay: 20\n", 200, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://waiting.test/page', '<html><body>ok</body></html>' );
        $client->crawl( 'https://waiting.test/page' );
        $this->assertSame( 20.0, $client->delayFor( 'https://waiting.test/other' ) );
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 45 );
        $this->assertSame( 45.0, $client->delayFor( 'https://waiting.test/other' ) );
    }

    public function testFirstProbeReservesRobotsTxtAndTheCrawlDelay(): void {
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 2 );
        HttpFixtures::add( 'https://slowsite2.test/robots.txt', '', 404, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::$handler = static fn( string $url ) => str_contains( $url, 'slowsite2.test' ) && ! str_ends_with( $url, '/robots.txt' ) ? new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) : null;
        [ $pid ] = Container::get( Prospects::class )->upsertDiscovered( 'resources-budget.test', [] );
        $page    = ( new PageAnalyzer() )->analyze( '<html><head><title>Peptide resources</title></head><body><main><ul><li><a href="https://slowsite2.test/peptide-storage-guide/">Peptide storage guide</a></li></ul></main></body></html>', 'https://resources-budget.test/links/' );

        // 10 s cannot hold robots.txt + HEAD + GET at 3 s each plus the 2 s wait before the GET: nothing is
        // sent, and the result says why.
        $stats = $this->finder( $this->client() )->scan( $page, $pid, TimeBudget::seconds( 10 ), 5 );
        $this->assertSame( 0, $stats['checked'] );
        $this->assertSame( [], HttpFixtures::$requests );
        $this->assertStringContainsString( 'No links were checked', $stats['note'] ?? '' );
        $this->assertStringContainsString( '(2 s, set in Settings > Advanced', $stats['note'] ?? '' );

        // 16 s can: every request and wait together stay inside the budget.
        $stats = $this->finder( $this->client() )->scan( $page, $pid, TimeBudget::seconds( 16 ), 5 );
        $this->assertSame( 1, $stats['checked'] );
        $this->assertArrayNotHasKey( 'note', $stats );
        $this->assertSame( [ 'GET', 'HEAD', 'GET' ], array_column( HttpFixtures::$requests, 'method' ) );
        $this->assertSame( [ 2.0 ], array_map( 'round', $this->slept ), 'the host was not hit before HEAD: only the GET waits' );
        $total = array_sum( $this->slept );
        foreach ( HttpFixtures::$requests as $r ) {
            $this->assertSame( 4, (int) $r['args']['timeout'] );
            $total += (int) $r['args']['timeout'];
        }
        $this->assertLessThanOrEqual( 16.0, $total );
    }

    public function testTheDefaultBudgetStillProbesWithALongerCrawlDelaySetting(): void {
        // About 16 s is left when BrokenLinkScanJob reaches the finder with the default 20 s job budget.
        foreach ( [ 4 => 3, 6 => 3, 7 => null, 10 => null ] as $delay => $expected ) {
            $GLOBALS['ppclg_test_transients'] = [];
            HttpFixtures::$requests           = [];
            $this->slept                      = [];
            Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', $delay );
            $host = 'setting' . $delay . '.test';
            HttpFixtures::add( 'https://' . $host . '/robots.txt', '', 404, [ 'content-type' => 'text/plain' ] );
            HttpFixtures::$handler = static fn( string $url ) => str_contains( $url, $host ) && ! str_ends_with( $url, '/robots.txt' ) ? new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) : null;
            [ $pid ] = Container::get( Prospects::class )->upsertDiscovered( 'resources-setting' . $delay . '.test', [] );
            $page    = ( new PageAnalyzer() )->analyze( '<html><head><title>Peptide resources</title></head><body><main><ul><li><a href="https://' . $host . '/peptide-guide/">Peptide guide</a></li></ul></main></body></html>', 'https://resources-setting' . $delay . '.test/links/' );

            $stats = $this->finder( $this->client() )->scan( $page, $pid, TimeBudget::seconds( 16 ), 5 );
            if ( null === $expected ) {
                $this->assertSame( 0, $stats['checked'], "delay {$delay}" );
                $this->assertSame( [], HttpFixtures::$requests, "delay {$delay}" );
                $this->assertStringContainsString( 'only 16 s was left', $stats['note'] ?? '', "delay {$delay}" );
                $this->assertStringContainsString( "({$delay} s, set in Settings > Advanced", $stats['note'] ?? '', "delay {$delay}" );
                continue;
            }
            $this->assertSame( 1, $stats['checked'], "delay {$delay}" );
            $this->assertSame( [ 'GET', 'HEAD', 'GET' ], array_column( HttpFixtures::$requests, 'method' ), "delay {$delay}" );
            $total = array_sum( $this->slept );
            foreach ( HttpFixtures::$requests as $r ) {
                $this->assertSame( $expected, (int) $r['args']['timeout'], "delay {$delay}" );
                $total += (int) $r['args']['timeout'];
            }
            $this->assertLessThanOrEqual( 16.0, $total, "delay {$delay}: requests and waits fit the budget" );
        }
    }

    public function testPendingWaitIsWhatIsLeftOfTheHostDelay(): void {
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 3 );
        $client = $this->client();
        HttpFixtures::add( 'https://pending.test/robots.txt', '', 404, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://pending.test/a', '<html><body>a</body></html>' );
        $this->assertSame( 3.0, $client->minDelay() );
        $this->assertSame( 0.0, $client->pendingWait( 'https://pending.test/a' ), 'never requested' );
        $client->crawl( 'https://pending.test/a' );
        $this->assertEqualsWithDelta( 3.0, $client->pendingWait( 'https://pending.test/b' ), 0.5 );
        $this->assertSame( 0.0, $client->pendingWait( 'https://other.test/b' ) );
        // A request made by another PHP request: only what is left of the delay.
        set_transient( 'ppclg_hit_' . md5( 'earlier.test' ), microtime( true ) - 2.0, 60 );
        $this->assertEqualsWithDelta( 1.0, $client->pendingWait( 'https://earlier.test/x' ), 0.5 );
        set_transient( 'ppclg_hit_' . md5( 'longago.test' ), microtime( true ) - 30.0, 60 );
        $this->assertSame( 0.0, $client->pendingWait( 'https://longago.test/x' ) );
    }

    public function testAHostWithALongCrawlDelayOnlyDefersItsOwnLinks(): void {
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 2 );
        // longdelay.test's robots.txt (Crawl-delay: 30) was read by an earlier run and is still cached.
        HttpFixtures::add( 'https://longdelay.test/robots.txt', "User-agent: *\nCrawl-delay: 30\n", 200, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://longdelay.test/', '<html><body>home</body></html>' );
        $this->client()->crawl( 'https://longdelay.test/' );
        unset( $GLOBALS['ppclg_test_transients'][ 'ppclg_hit_' . md5( 'longdelay.test' ) ] );
        // lateslow.test's robots.txt sets Crawl-delay: 25 too, but is read only during the scan.
        HttpFixtures::add( 'https://lateslow.test/robots.txt', "User-agent: *\nCrawl-delay: 25\n", 200, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://lateslow.test/peptide-research-storage-guide/', '<html><body>404</body></html>', 404 );
        HttpFixtures::add( 'https://deadlink.test/robots.txt', '', 404, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://deadlink.test/peptide-guide/', '<html><body>404</body></html>', 404 );
        HttpFixtures::$requests = [];
        $this->slept            = [];
        [ $pid ] = Container::get( Prospects::class )->upsertDiscovered( 'resources-longdelay.test', [] );
        // Most relevant first: longdelay.test, then lateslow.test, then deadlink.test.
        $page = ( new PageAnalyzer() )->analyze(
            '<html><head><title>Peptide resources</title></head><body><main><ul>'
            . '<li><a href="https://longdelay.test/peptide-research-peptide-storage-peptide-guide/">Peptide research, peptide storage and peptide handling guide</a></li>'
            . '<li><a href="https://lateslow.test/peptide-research-storage-guide/">Peptide research storage guide</a></li>'
            . '<li><a href="https://deadlink.test/peptide-guide/">Peptide guide</a></li>'
            . '</ul></main></body></html>',
            'https://resources-longdelay.test/links/'
        );

        $stats = $this->finder( $this->client() )->scan( $page, $pid, TimeBudget::seconds( 29 ), 5 );
        // longdelay.test: robots.txt + HEAD + GET plus its 30 s Crawl-delay cannot fit 29 s, so it is left for
        // a later scan. lateslow.test is probed (its Crawl-delay is not known yet), but confirming would wait
        // 25 s twice: left for later too. deadlink.test still fits and is recorded.
        $this->assertSame( [ 'checked' => 2, 'broken' => 1, 'opportunities' => 1 ], $stats );
        $this->assertSame( [], self::requestsTo( 'https://longdelay.test/peptide-research-peptide-storage-peptide-guide/' ) );
        $this->assertCount( 1, self::requestsTo( 'https://lateslow.test/peptide-research-storage-guide/' ) );
        $this->assertCount( 2, self::requestsTo( 'https://deadlink.test/peptide-guide/' ) );
        $broken = array_column( array_column( Container::get( Opportunities::class )->findBy( [ 'prospect_id' => $pid, 'type' => 'broken_link' ] ), 'details' ), 'broken_url' );
        $this->assertSame( [ 'https://deadlink.test/peptide-guide/' ], $broken );
        foreach ( $this->slept as $s ) {
            $this->assertLessThan( 3.0, $s, 'no Crawl-delay wait was started' );
        }
    }

    public function testConfirmingProbeWaitsOnlyWhenTheBudgetCoversTheRobotsCrawlDelay(): void {
        HttpFixtures::add( 'https://patient.test/robots.txt', "User-agent: *\nCrawl-delay: 20\n", 200, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://patient.test/peptide-hplc-guide/', '<html><body>404</body></html>', 404 );
        [ $pid ] = Container::get( Prospects::class )->upsertDiscovered( 'resources-patient.test', [] );
        $page    = ( new PageAnalyzer() )->analyze( '<html><head><title>Peptide resources</title></head><body><main><ul><li><a href="https://patient.test/peptide-hplc-guide/">Peptide HPLC purity guide</a></li></ul></main></body></html>', 'https://resources-patient.test/links/' );

        // 30 s: the confirming probe would first wait the 20 s Crawl-delay (twice in the worst case), so it
        // is left for the next scan and nothing is recorded.
        $stats = $this->finder( $this->client() )->scan( $page, $pid, TimeBudget::seconds( 30 ), 5 );
        $this->assertSame( [ 'checked' => 1, 'broken' => 0, 'opportunities' => 0 ], $stats );
        $this->assertSame( [], $this->slept, 'no 20 s wait was started' );
        $this->assertCount( 1, self::requestsTo( 'https://patient.test/peptide-hplc-guide/' ) );

        // 60 s covers it: the confirming probe waits the Crawl-delay and the dead link is recorded. (A later
        // run: the cached robots.txt and the "host was just hit" marker have expired.)
        HttpFixtures::$requests           = [];
        $GLOBALS['ppclg_test_transients'] = [];
        $stats = $this->finder( $this->client() )->scan( $page, $pid, TimeBudget::seconds( 60 ), 5 );
        $this->assertSame( 1, $stats['opportunities'] );
        $this->assertCount( 1, $this->slept );
        $this->assertGreaterThan( 19.0, $this->slept[0] );
        $this->assertCount( 2, self::requestsTo( 'https://patient.test/peptide-hplc-guide/' ) );
    }

    // ------------------------------------------------------------------ [49] [hidden]

    public function testHiddenAttributeAlwaysHidesInsideTheApp(): void {
        $css = (string) file_get_contents( PPCLG_DIR . 'assets/css/admin.css' );
        $this->assertMatchesRegularExpression( '/\.ppclg-wrap \[hidden\]\s*\{\s*display:\s*none\s*!important;\s*\}/', $css );
    }
}
