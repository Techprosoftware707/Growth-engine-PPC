<?php
/**
 * Regression tests for the integrations-monitoring slice fixes: an unreachable robots.txt (network
 * outage, DNS failure, 5xx) is a transient failure and not a robots.txt Disallow, and dead domains still
 * reach "broken" (integrations-monitoring-1); "unverified" robots rows are re-checked once their 30-day
 * date passes (integrations-monitoring-2); Site Kit's Google model objects are read (integrations-monitoring-3);
 * the GA4 search/social filters match whole host labels (integrations-monitoring-4); payment and webmail
 * referrers are not imported as backlinks (integrations-monitoring-5); str_getcsv gets an explicit escape
 * argument (integrations-monitoring-6).
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Monitoring;

use PPC\LinkGrowth\Integrations\Google\Analytics4;
use PPC\LinkGrowth\Integrations\Google\ServiceAccount;
use PPC\LinkGrowth\Integrations\Google\SiteKitBridge;
use PPC\LinkGrowth\Integrations\Providers\Semrush;
use PPC\LinkGrowth\Monitoring\BacklinkImporter;
use PPC\LinkGrowth\Monitoring\BacklinkMonitor;
use PPC\LinkGrowth\Repositories\Backlinks;
use PPC\LinkGrowth\Repositories\Notifications;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class IntegrationsMonitoringFixesTest extends TestCase {

    private const TARGET = 'https://purepeptidescanada.test/';

    private const LINK_PAGE = '<html><head><title>Resources</title></head><body><main><p>See <a href="https://purepeptidescanada.test/">Pure Peptides Canada</a> for lab supplies.</p></main></body></html>';

    protected function setUp(): void {
        parent::setUp();
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 0 );
    }

    /** @param array<string,mixed> $data */
    private function backlink( string $source, array $data = [] ): int {
        [ $id ] = Container::get( Backlinks::class )->upsert( $source, self::TARGET, array_merge( [ 'status' => Backlinks::STATUS_ACTIVE, 'origin' => 'manual', 'last_seen_live' => gmdate( 'Y-m-d H:i:s' ) ], $data ) );
        return $id;
    }

    /** @return array<string,mixed> */
    private function check( int $id ): array {
        return Container::get( BacklinkMonitor::class )->check( Container::get( Backlinks::class )->find( $id ) );
    }

    /** @return string[] */
    private function requestedUrls(): array {
        return array_map( static fn( array $r ): string => $r['url'], HttpFixtures::$requests );
    }

    private function ago( int $days ): string {
        return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
    }

    // ------------------------------------------------------------------ integrations-monitoring-1

    public function testNetworkOutageIsTransientNotRobotsDisallow(): void {
        // down.test has no fixture, so every request fails with "cURL error 6: Could not resolve host".
        $id = $this->backlink( 'https://down.test/resources' );
        $r  = $this->check( $id );
        $row = Container::get( Backlinks::class )->find( $id );

        $this->assertSame( Backlinks::STATUS_ACTIVE, $row['status'], $r['note'] );
        $this->assertStringContainsString( 'robots.txt of the linking site could not be fetched', $r['note'] );
        $this->assertStringContainsString( 'retrying tomorrow', $r['note'] );
        $this->assertStringNotContainsString( 'disallows', $r['note'] );
        $this->assertSame( 0, (int) $row['consecutive_failures'] );
        $this->assertGreaterThan( time(), strtotime( $row['next_check_at'] . ' UTC' ) );
        $this->assertLessThanOrEqual( time() + 2 * DAY_IN_SECONDS, strtotime( $row['next_check_at'] . ' UTC' ) );
        foreach ( $this->requestedUrls() as $url ) {
            $this->assertStringEndsWith( '/robots.txt', $url, 'the page itself must never be fetched while robots.txt is unknown' );
        }

        // Still monitored: due again once the retry date passes.
        Container::get( Backlinks::class )->update( $id, [ 'next_check_at' => $this->ago( 0 ) ] );
        $this->assertContains( $id, array_map( static fn( array $b ): int => (int) $b['id'], Container::get( Backlinks::class )->dueForCheck( 10 ) ) );
    }

    public function testRobotsTxt503IsTransientAndThePageIsNotFetched(): void {
        HttpFixtures::add( 'https://flaky.test/robots.txt', 'Service Unavailable', 503, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://flaky.test/links', self::LINK_PAGE );
        $id  = $this->backlink( 'https://flaky.test/links' );
        $r   = $this->check( $id );
        $row = Container::get( Backlinks::class )->find( $id );

        $this->assertSame( Backlinks::STATUS_ACTIVE, $row['status'], $r['note'] );
        $this->assertStringContainsString( 'HTTP 503', $r['note'] );
        $this->assertNotContains( 'https://flaky.test/links', $this->requestedUrls() );
    }

    public function testCachedRobotsFailureThatHasClearedIsTransient(): void {
        // robots.txt answers 503 once (CDN hiccup, cached by the crawler for an hour), then 200 again.
        $robotsHits            = 0;
        HttpFixtures::$handler = static function ( string $url ) use ( &$robotsHits ) {
            if ( 'https://blip.test/robots.txt' === $url && 0 === $robotsHits++ ) {
                return [ 'response' => [ 'code' => 503, 'message' => 'Service Unavailable' ], 'headers' => [ 'content-type' => 'text/plain' ], 'body' => '', 'cookies' => [] ];
            }
            return null;
        };
        HttpFixtures::add( 'https://blip.test/robots.txt', "User-agent: *\nAllow: /\n", 200, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://blip.test/links', self::LINK_PAGE );
        $id  = $this->backlink( 'https://blip.test/links' );
        $r   = $this->check( $id );
        $row = Container::get( Backlinks::class )->find( $id );

        $this->assertSame( Backlinks::STATUS_ACTIVE, $row['status'], $r['note'] );
        $this->assertStringContainsString( 'briefly unavailable', $r['note'] );
        $this->assertNotContains( 'https://blip.test/links', $this->requestedUrls() );
    }

    public function testBlipOnSiteWithoutRobotsTxtIsTransient(): void {
        // No robots.txt (404 = everything allowed); the first request fails, the re-read gets the 404.
        $hits                  = 0;
        HttpFixtures::$handler = static function ( string $url ) use ( &$hits ) {
            if ( 'https://norobots.test/robots.txt' === $url && 0 === $hits++ ) {
                return new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to norobots.test port 443' );
            }
            return null;
        };
        HttpFixtures::add( 'https://norobots.test/links', self::LINK_PAGE );
        $id  = $this->backlink( 'https://norobots.test/links' );
        $r   = $this->check( $id );
        $row = Container::get( Backlinks::class )->find( $id );

        $this->assertSame( Backlinks::STATUS_ACTIVE, $row['status'], $r['note'] );
        $this->assertStringContainsString( 'briefly unavailable', $r['note'] );
        $this->assertNotContains( 'https://norobots.test/links', $this->requestedUrls() );
    }

    public function testRealRobotsDisallowStillMarksUnverified(): void {
        HttpFixtures::add( 'https://private.test/robots.txt', "User-agent: *\nDisallow: /members/\n", 200, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://private.test/members/links', self::LINK_PAGE );
        $id  = $this->backlink( 'https://private.test/members/links' );
        $r   = $this->check( $id );
        $row = Container::get( Backlinks::class )->find( $id );

        $this->assertSame( Backlinks::STATUS_UNVERIFIED, $row['status'] );
        $this->assertStringContainsString( 'robots.txt disallows crawling this page', $r['note'] );
        $this->assertGreaterThan( time() + 29 * DAY_IN_SECONDS, strtotime( $row['next_check_at'] . ' UTC' ) );
        $this->assertNotContains( 'https://private.test/members/links', $this->requestedUrls() );
    }

    public function testDomainThatStopsResolvingEventuallyBecomesBroken(): void {
        $id = $this->backlink( 'https://expired.test/resources', [ 'last_seen_live' => $this->ago( 20 ), 'created_at' => $this->ago( 40 ), 'first_seen' => $this->ago( 40 ) ] );

        $r1 = $this->check( $id );
        $this->assertSame( Backlinks::STATUS_ACTIVE, Container::get( Backlinks::class )->find( $id )['status'], $r1['note'] );
        $this->assertSame( 1, (int) Container::get( Backlinks::class )->find( $id )['consecutive_failures'] );
        $this->assertStringContainsString( 'does not resolve', $r1['note'] );

        $r2  = $this->check( $id );
        $row = Container::get( Backlinks::class )->find( $id );
        $this->assertSame( Backlinks::STATUS_BROKEN, $row['status'], $r2['note'] );
        $this->assertTrue( $r2['lost'] );
        $this->assertSame( 1, Container::get( Notifications::class )->count( [ 'title' => 'Backlink lost' ] ) );
    }

    public function testShortDnsOutageAndLongTimeoutsAreNotStrikes(): void {
        // Recently confirmed link, domain briefly unresolvable: transient only.
        $recent = $this->backlink( 'https://blipdns.test/resources' );
        $this->check( $recent );
        $this->check( $recent );
        $row = Container::get( Backlinks::class )->find( $recent );
        $this->assertSame( Backlinks::STATUS_ACTIVE, $row['status'] );
        $this->assertSame( 0, (int) $row['consecutive_failures'] );

        // Timeouts are ambiguous (firewalls drop bots), so they never count, however long they last.
        HttpFixtures::$handler = static fn( string $url ) => str_contains( $url, 'slow.test' ) ? new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 5000 milliseconds' ) : null;
        $slow = $this->backlink( 'https://slow.test/resources', [ 'last_seen_live' => $this->ago( 30 ), 'created_at' => $this->ago( 60 ) ] );
        $this->check( $slow );
        $this->check( $slow );
        $row = Container::get( Backlinks::class )->find( $slow );
        $this->assertSame( Backlinks::STATUS_ACTIVE, $row['status'] );
        $this->assertSame( 0, (int) $row['consecutive_failures'] );
        $this->assertSame( 0, Container::get( Notifications::class )->count( [ 'title' => 'Backlink lost' ] ) );
    }

    public function testPageLevelNetworkErrorKeepsTransientBehaviour(): void {
        HttpFixtures::add( 'https://five.test/robots.txt', "User-agent: *\nAllow: /\n", 200, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://five.test/p', 'error', 503 );
        $id  = $this->backlink( 'https://five.test/p' );
        $r   = $this->check( $id );
        $row = Container::get( Backlinks::class )->find( $id );
        $this->assertSame( Backlinks::STATUS_ACTIVE, $row['status'] );
        $this->assertSame( 'Temporary HTTP 503 from the linking page. Not counted as a failure; retrying tomorrow.', $r['note'] );
        $this->assertSame( 503, (int) $row['http_status'] );
    }

    // ------------------------------------------------------------------ integrations-monitoring-2

    public function testUnverifiedRobotsRowIsRecheckedAfterItsRetryDate(): void {
        HttpFixtures::add( 'https://private.test/robots.txt', "User-agent: *\nDisallow: /members/\n", 200, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://private.test/members/links', self::LINK_PAGE );
        $repo = Container::get( Backlinks::class );
        $id   = $this->backlink( 'https://private.test/members/links' );
        $this->check( $id );
        $this->assertSame( Backlinks::STATUS_UNVERIFIED, $repo->find( $id )['status'] );

        // Monitoring stopped for good (next_check_at NULL) must stay out.
        $stopped = $this->backlink( 'https://gave-up.test/old', [ 'status' => Backlinks::STATUS_UNVERIFIED, 'next_check_at' => null ] );

        // Not due before the 30 days are over.
        $r = Container::get( BacklinkMonitor::class )->checkDue( TimeBudget::seconds( 60 ), 10 );
        $this->assertSame( 0, $r['checked'] );

        // 30 days later the site has opened the page to crawlers.
        $repo->update( $id, [ 'next_check_at' => $this->ago( 0 ) ] );
        $GLOBALS['ppclg_test_transients'] = [];
        HttpFixtures::reset();
        HttpFixtures::add( 'https://private.test/robots.txt', "User-agent: *\nAllow: /\n", 200, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://private.test/members/links', self::LINK_PAGE );
        \PPC\LinkGrowth\Tests\Support\Container::reset();
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 0 );

        $r = Container::get( BacklinkMonitor::class )->checkDue( TimeBudget::seconds( 60 ), 10 );
        $this->assertSame( 1, $r['checked'], (string) $r['note'] );
        $this->assertSame( Backlinks::STATUS_ACTIVE, Container::get( Backlinks::class )->find( $id )['status'] );
        $this->assertSame( Backlinks::STATUS_UNVERIFIED, Container::get( Backlinks::class )->find( $stopped )['status'] );
        $this->assertNotContains( 'https://gave-up.test/old', $this->requestedUrls() );
        $this->assertFalse( $r['more_due'] );
    }

    // ------------------------------------------------------------------ integrations-monitoring-3

    /** An object shaped like Site Kit's Google\Service\SearchConsole\ApiDataRow (Google\Model: ArrayAccess, not an array). */
    private function apiDataRow( float $clicks, float $impressions, float $ctr, float $position ): object {
        return new class( $clicks, $impressions, $ctr, $position ) implements \ArrayAccess {
            public $clicks;
            public $ctr;
            public $impressions;
            public $keys = [];
            public $position;
            protected array $modelData = [];
            public function __construct( float $c, float $i, float $ctr, float $p ) {
                $this->clicks      = $c;
                $this->impressions = $i;
                $this->ctr         = $ctr;
                $this->position    = $p;
            }
            public function offsetExists( mixed $o ): bool { return isset( $this->$o ); }
            public function offsetGet( mixed $o ): mixed { return $this->$o ?? null; }
            public function offsetSet( mixed $o, mixed $v ): void { $this->$o = $v; }
            public function offsetUnset( mixed $o ): void { unset( $this->$o ); }
        };
    }

    /** @param array<int,array{0:string,1:int,2:int}> $rows source, sessions, users */
    private function runReportResponse( array $rows ): object {
        $value = static fn( string $v ): object => new class( $v ) {
            public $value;
            public $oneValue = 'value';
            public function __construct( string $v ) { $this->value = $v; }
        };
        $objects = [];
        foreach ( $rows as [ $source, $sessions, $users ] ) {
            $objects[] = new class( [ $value( $source ), $value( 'referral' ) ], [ $value( (string) $sessions ), $value( (string) $users ) ] ) {
                public $dimensionValues;
                public $metricValues;
                public function __construct( array $d, array $m ) { $this->dimensionValues = $d; $this->metricValues = $m; }
            };
        }
        return new class( $objects ) implements \ArrayAccess {
            public $kind = 'analyticsData#runReport';
            public $rowCount;
            public $rows;
            protected $rowsType = 'Row';
            public function __construct( array $rows ) { $this->rows = $rows; $this->rowCount = count( $rows ); }
            public function offsetExists( mixed $o ): bool { return isset( $this->$o ); }
            public function offsetGet( mixed $o ): mixed { return $this->$o ?? null; }
            public function offsetSet( mixed $o, mixed $v ): void { $this->$o = $v; }
            public function offsetUnset( mixed $o ): void { unset( $this->$o ); }
        };
    }

    public function testSiteKitSearchAnalyticsModelRowsAreRead(): void {
        $data = SiteKitBridge::normaliseData( [ $this->apiDataRow( 1234, 56789, 0.0217, 14.2 ) ] );
        $this->assertIsArray( $data );
        $totals = SiteKitBridge::parseSearchAnalyticsTotals( $data );
        $this->assertSame( 1234.0, $totals['clicks'] );
        $this->assertSame( 56789.0, $totals['impressions'] );
        $this->assertSame( 0.0217, $totals['ctr'] );
        $this->assertSame( 14.2, $totals['position'] );

        // Plain arrays (older Site Kit / already-serialised data) keep working, also with a "rows" key.
        $this->assertSame( 7.0, SiteKitBridge::parseSearchAnalyticsTotals( [ 'rows' => [ [ 'clicks' => 7, 'impressions' => 70 ] ] ] )['clicks'] );
        $this->assertSame( 5.0, SiteKitBridge::parseSearchAnalyticsTotals( [ [ 'clicks' => 5 ] ] )['clicks'] );

        // No rows for the period: zeros. Unreadable rows: null (an error, not a fake success).
        $this->assertSame( 0, SiteKitBridge::parseSearchAnalyticsTotals( [] )['clicks'] );
        $this->assertSame( 0, SiteKitBridge::parseSearchAnalyticsTotals( [ 'rows' => [] ] )['clicks'] );
        $this->assertNull( SiteKitBridge::parseSearchAnalyticsTotals( [ 'unexpected' ] ) );
        $this->assertNull( SiteKitBridge::normaliseData( null ) );
    }

    public function testSiteKitRunReportModelIsReadAsReferralRows(): void {
        $data = SiteKitBridge::normaliseData( $this->runReportResponse( [ [ 'labmanager.com', 42, 30 ], [ 'google.com', 9, 9 ] ] ) );
        $this->assertIsArray( $data );
        $rows = Analytics4::parseReferralRows( $data );
        $this->assertSame( [ [ 'source' => 'labmanager.com', 'sessions' => 42, 'users' => 30 ] ], $rows );

        $plain = [ 'rows' => [ [ 'dimensionValues' => [ [ 'value' => 'yelp.ca' ] ], 'metricValues' => [ [ 'value' => '3' ], [ 'value' => '2' ] ] ] ] ];
        $this->assertSame( $plain, SiteKitBridge::normaliseData( $plain ) );
    }

    // ------------------------------------------------------------------ integrations-monitoring-4

    /** @param string[] $sources */
    private function keptSources( array $sources ): array {
        $rows = array_map( static fn( string $s ): array => [ 'dimensionValues' => [ [ 'value' => $s ], [ 'value' => 'referral' ] ], 'metricValues' => [ [ 'value' => '3' ], [ 'value' => '2' ] ] ], $sources );
        return array_column( Analytics4::parseReferralRows( [ 'rows' => $rows ] ), 'source' );
    }

    public function testGa4FiltersMatchWholeHostLabels(): void {
        $real = [ 'trustpilot.com', 'huffpost.com', 'washingtonpost.com', 'about.com', 'canadianbusinessdirect.com', 'research.ca', 'dropbox.com', 'fedex.com', 'bravenewlabs.ca', 'bingoforum.com', 'biotechnet.co.uk', 'chemistrypost.com', 'yelp.ca', 'labmanager.com' ];
        $this->assertSame( $real, $this->keptSources( $real ) );

        $excluded = [ 'google.com', 'www.google.ca', 'com.google.android.gm', 'googleads.g.doubleclick.net', 'bing.com', 'cn.bing.com', 'search.yahoo.com', 'duckduckgo.com', 'search.brave.com', 'search.aol.com', 'yandex.ru', 'ecosia.org', 't.co', 'x.com', 'm.facebook.com', 'l.instagram.com', 'out.reddit.com', 'ca.linkedin.com', 'pinterest.ca', 'm.youtube.com', 'threads.net', 'mobile.twitter.com', '(direct)' ];
        $this->assertSame( [], $this->keptSources( $excluded ) );

        $all = Analytics4::parseReferralRows( [ 'rows' => [ [ 'dimensionValues' => [ [ 'value' => 't.co' ] ], 'metricValues' => [] ] ] ], true );
        $this->assertSame( 't.co', $all[0]['source'] );
    }

    // ------------------------------------------------------------------ integrations-monitoring-5

    public function testGa4ImportSkipsPaymentAndWebmailReferrers(): void {
        Container::get( Secrets::class )->set( 'google_service_account_json', (string) json_encode( [ 'client_email' => 'sa@example.iam.gserviceaccount.com', 'private_key' => 'dummy' ] ) );
        Container::get( Settings::class )->set( 'providers.google.ga4_property_id', '123456' );
        set_transient( 'ppclg_gsa_token_' . md5( 'sa@example.iam.gserviceaccount.com' . implode( ' ', ServiceAccount::SCOPES ) ), 'token', 3000 );
        $sources = [ 'paypal.com', 'www.paypal.com', 'checkout.stripe.com', 'klarna.com', 'outlook.live.com', 'mail.example.ca', 'pay.amazon.com', 'labmanager.com', 'yelp.ca' ];
        $rows    = array_map( static fn( string $s ): array => [ 'dimensionValues' => [ [ 'value' => $s ], [ 'value' => 'referral' ] ], 'metricValues' => [ [ 'value' => '5' ], [ 'value' => '4' ] ] ], $sources );
        HttpFixtures::add( 'https://analyticsdata.googleapis.com/v1beta/properties/123456:runReport', (string) json_encode( [ 'rows' => $rows ] ), 200, [ 'content-type' => 'application/json' ] );

        add_filter( 'ppclg_ignored_referral_domains', static fn( array $d ): array => array_merge( $d, [ 'yelp.ca' ] ) );
        $out = Container::get( BacklinkImporter::class )->importFromAnalytics( 28 );

        $this->assertSame( 1, $out['new'], (string) json_encode( $out ) );
        $this->assertSame( 1, $out['sources'] );
        $imported = array_column( Container::get( Backlinks::class )->findBy( [], 'id', 'ASC', 50 ), 'source_url' );
        $this->assertSame( [ 'https://labmanager.com/' ], $imported );
    }

    // ------------------------------------------------------------------ integrations-monitoring-6

    public function testSemrushCsvParsingRaisesNoDeprecations(): void {
        $deprecations = [];
        set_error_handler(
            static function ( int $no, string $msg ) use ( &$deprecations ): bool {
                $deprecations[] = $msg;
                return true;
            },
            E_DEPRECATED | E_USER_DEPRECATED
        );
        try {
            $rows = Semrush::parseCsv( "Source url;Target url;Anchor\nhttps://a.test/;https://purepeptidescanada.test/;\"Peptides; lab grade\"\n" );
        } finally {
            restore_error_handler();
        }
        $this->assertSame( [], $deprecations );
        $this->assertSame( 'Peptides; lab grade', $rows[0]['Anchor'] );

        $src = (string) file_get_contents( PPCLG_DIR . 'includes/Integrations/Providers/Semrush.php' );
        preg_match_all( '/str_getcsv\((.*)\);/', $src, $m );
        $this->assertNotEmpty( $m[1] );
        foreach ( $m[1] as $args ) {
            $this->assertSame( 3, substr_count( $args, ',' ), 'str_getcsv must pass $escape explicitly (PHP 8.4+ deprecation): ' . $args );
        }
    }
}
