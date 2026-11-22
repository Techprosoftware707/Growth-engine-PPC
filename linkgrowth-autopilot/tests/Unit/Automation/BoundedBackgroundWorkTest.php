<?php
/**
 * 1.2.4 performance: background work stays inside its time budget.
 *
 * Measured before (perf site copy, PHP 8.3 CLI on one CPU, a local slow-host server): one evaluation ran 60.1 s
 * against its 20 s budget on a host with robots.txt "Crawl-delay: 30" and 42.1 s on a host answering each page in
 * 14 s, because the budget was only checked before each prospect. Inside a job (Client::within()) crawls, probes and
 * API reads now end before the budget does; a request the run has no time for is not sent (Client::DEADLINE), and
 * nothing treats that as a verdict: the evaluation is continued on the next run with the pages already fetched,
 * provider errors are not recorded, listings and backlinks stay due, a form submission keeps its attempts.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Automation;

use LinkGrowth\Autopilot\Analysis\SiteProfiler;
use LinkGrowth\Autopilot\Automation\JobRunner;
use LinkGrowth\Autopilot\Automation\LoadGuard;
use LinkGrowth\Autopilot\Integrations\Providers\AbstractProvider;
use LinkGrowth\Autopilot\Integrations\Search\AbstractSearch;
use LinkGrowth\Autopilot\Monitoring\BacklinkMonitor;
use LinkGrowth\Autopilot\Repositories\Backlinks;
use LinkGrowth\Autopilot\Repositories\Jobs;
use LinkGrowth\Autopilot\Repositories\Prospects;
use LinkGrowth\Autopilot\Repositories\Submissions;
use LinkGrowth\Autopilot\Submission\ListingVerifier;
use LinkGrowth\Autopilot\Support\Http\Client;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Secrets;
use LinkGrowth\Autopilot\Support\ServerResources;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\TimeBudget;
use LinkGrowth\Autopilot\Tests\Support\Container;
use LinkGrowth\Autopilot\Tests\Support\HttpFixtures;
use LinkGrowth\Autopilot\Tests\Support\Profiles;
use LinkGrowth\Autopilot\Tests\Support\TestCase;
use WP_Error;

final class BoundedBackgroundWorkTest extends TestCase {

    private const PAGE = '<html lang="en"><head><title>%s</title><meta name="description" content="Laboratory research resources directory in Canada"></head><body><h1>%s</h1><p>Research laboratory testing resources for Canadian labs. Submit your resource.</p><a href="/contact/">Contact</a> <a href="/about/">About</a> <p>Email: editor@%s</p></body></html>';

    protected function setUp(): void {
        parent::setUp();
        Container::get( Settings::class )->set( 'providers.rdap.enabled', false );
    }

    private function client(): Client {
        return Container::get( Client::class );
    }

    /** A host whose robots.txt asks for $crawlDelay seconds between requests, with a home, contact and about page. */
    private function site( string $host, int $crawlDelay = 0 ): void {
        HttpFixtures::add( 'https://' . $host . '/robots.txt', "User-agent: *\nAllow: /\n" . ( $crawlDelay > 0 ? 'Crawl-delay: ' . $crawlDelay . "\n" : '' ), 200, [ 'content-type' => 'text/plain' ] );
        foreach ( [ '', 'contact/', 'about/', 'submit/' ] as $path ) {
            $title = ucfirst( strtok( $host, '.' ) ) . ' ' . trim( $path, '/' );
            HttpFixtures::add( 'https://' . $host . '/' . $path, sprintf( self::PAGE, $title, $title, $host ) );
        }
    }

    /** @return string[] */
    private static function urls(): array {
        return array_map( static fn( array $r ): string => $r['url'], HttpFixtures::$requests );
    }

    /** "Five minutes pass": the per-host delay clock of the client (memory and transient) is reset. */
    private function laterRun( string $host ): void {
        ( new \ReflectionProperty( Client::class, 'lastHit' ) )->setValue( $this->client(), [] );
        delete_transient( 'lga_hit_' . md5( $host ) );
    }

    // ------------------------------------------------------------------ the client

    public function testOutsideAJobNothingIsBounded(): void {
        $this->site( 'plain.test' );
        $r = $this->client()->crawl( 'https://plain.test/' );
        $this->assertTrue( $r->ok() );
        $this->assertSame( 15, HttpFixtures::$requests[1]['args']['timeout'], 'the configured request timeout' );
        $this->assertNull( $this->client()->timeLeft() );
    }

    public function testARequestThatCannotFitIsNotSent(): void {
        $this->site( 'tight.test' );
        $client = $this->client();
        $r      = $client->within( TimeBudget::seconds( 2.5 ), fn() => $client->crawl( 'https://tight.test/' ) );
        $this->assertSame( Client::DEADLINE, $r->errorCode );
        $this->assertSame( [], HttpFixtures::$requests, 'nothing was requested' );
        $this->assertSame( 1, $client->deadlineMisses() );
        $this->assertTrue( $client->lastWasDeadline() );

        // API reads too; POSTs and unbounded calls (a partner submission) are always sent.
        $this->assertSame( Client::DEADLINE, $client->within( TimeBudget::seconds( 2.5 ), fn() => $client->api( 'GET', 'https://api.tight.test/x' ) )->errorCode );
        $client->within( TimeBudget::seconds( 2.5 ), fn() => $client->api( 'POST', 'https://api.tight.test/token', [], [ 'a' => 1 ] ) );
        $client->within( TimeBudget::seconds( 2.5 ), fn() => $client->api( 'GET', 'https://api.tight.test/submit', [], null, 20, false ) );
        $client->within( TimeBudget::seconds( 2.5 ), fn() => $client->postForm( 'https://tight.test/submit/', [ 'a' => '1' ] ) );
        $this->assertSame( [ 'https://api.tight.test/token', 'https://api.tight.test/submit', 'https://tight.test/submit/' ], self::urls() );
        $this->assertSame( 25, HttpFixtures::$requests[2]['args']['timeout'], 'a form POST keeps its whole timeout' );
        $this->assertFalse( $client->lastWasDeadline() );
    }

    public function testACrawlDelayThatDoesNotFitIsNotWaitedOut(): void {
        $this->site( 'crawldelay.test', 30 );
        $client = $this->client();
        $start  = microtime( true );
        $home   = $client->within( TimeBudget::seconds( 20 ), fn() => $client->crawl( 'https://crawldelay.test/' ) );
        $next   = $client->within( TimeBudget::seconds( 20 ), fn() => $client->crawl( 'https://crawldelay.test/contact/' ) );
        $this->assertTrue( $home->ok() );
        $this->assertSame( Client::DEADLINE, $next->errorCode, 'the 30 s Crawl-delay does not fit a 20 s budget' );
        $this->assertLessThan( 2.0, microtime( true ) - $start, 'no sleep (measured before: the worker slept 30 s)' );
        $this->assertNotContains( 'https://crawldelay.test/contact/', self::urls() );
        $this->assertGreaterThan( 0, $client->pendingWait( 'https://crawldelay.test/contact/' ), 'the host still gets its whole delay later' );
    }

    public function testTimeoutsAreCappedToTheTimeLeftAndOurOwnTimeoutIsNoVerdict(): void {
        HttpFixtures::add( 'https://slowpages.test/robots.txt', '', 404, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::$handler = static function ( string $url ) {
            return str_ends_with( $url, '/robots.txt' ) ? null : new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 9001 milliseconds' );
        };
        $client = $this->client();
        $r      = $client->within( TimeBudget::seconds( 10 ), fn() => $client->crawl( 'https://slowpages.test/' ) );
        $page   = HttpFixtures::$requests[ count( HttpFixtures::$requests ) - 1 ];
        $this->assertLessThanOrEqual( 9, $page['args']['timeout'], '15 s capped to what the 10 s budget leaves' );
        $this->assertSame( Client::DEADLINE, $r->errorCode, 'our shortened timeout ran out: try again later' );

        // Outside a job the same timeout is the host's own: an ordinary failure.
        $r = $client->crawl( 'https://slowpages.test/other/' );
        $this->assertSame( 'http_request_failed', $r->errorCode );
    }

    // ------------------------------------------------------------------ evaluation

    public function testASlowHostIsEvaluatedOverSeveralRunsThatEachEndInTime(): void {
        $this->site( 'crawldelay.test', 30 );
        [ $id ] = Container::get( Prospects::class )->upsertDiscovered( 'crawldelay.test', [] );
        $runner = Container::get( JobRunner::class );
        $runs   = [];
        for ( $i = 0; $i < 6; $i++ ) {
            $t      = microtime( true );
            $result = $runner->runType( 'evaluate', [ 'prospect_id' => $id ], TimeBudget::seconds( 20 ) );
            $runs[] = [ 'seconds' => microtime( true ) - $t, 'evaluated' => (int) ( $result['evaluated'] ?? 0 ), 'deferred' => (int) ( $result['deferred'] ?? 0 ) ];
            if ( $runs[ $i ]['evaluated'] > 0 ) {
                break;
            }
            $this->assertSame( Prospects::STATUS_DISCOVERED, Container::get( Prospects::class )->find( $id )['status'], 'no verdict while deferred' );
            $this->laterRun( 'crawldelay.test' );
        }
        $this->assertGreaterThan( 1, count( $runs ), 'the first run could not fetch the contact page in time' );
        $this->assertSame( 1, $runs[ count( $runs ) - 1 ]['evaluated'], 'evaluated in the end' );
        $this->assertLessThanOrEqual( SiteProfiler::MAX_DEFERRALS + 1, count( $runs ) );
        foreach ( $runs as $run ) {
            $this->assertLessThan( 3.0, $run['seconds'], 'each run ends at once instead of sleeping out the 30 s delay' );
        }
        $row = Container::get( Prospects::class )->find( $id );
        $this->assertNotSame( Prospects::STATUS_DISCOVERED, $row['status'] );
        $fetched = array_count_values( self::urls() );
        $this->assertSame( 1, $fetched['https://crawldelay.test/'] ?? 0, 'the home page was fetched once and carried between runs' );
        $this->assertSame( 1, $fetched['https://crawldelay.test/contact/'] ?? 0, 'so was the contact page' );
        $this->assertGreaterThanOrEqual( 1, count( (array) ( $row['profile']['pages'] ?? [] ) ), 'judged with its extra pages' );
        $this->assertFalse( get_transient( SiteProfiler::CARRY_PREFIX . md5( 'crawldelay.test' ) ), 'nothing is kept once it is stored' );
    }

    /**
     * Review finding: a working site slower than the time a short run leaves (a job late in its tick) was rejected as
     * "Site unreachable" on the 4th run, because of the plugin's own shortened timeout. The fixture answers a request
     * whose timeout is under 15 s with a timeout, as a site answering in 14.5 s does.
     */
    public function testAHomepageOurDeadlineCutShortIsNeverAVerdict(): void {
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 0 ); // no real sleep between same-host pages
        // Crawl-delay 30: in a run, no page fits after the first one.
        HttpFixtures::add( 'https://slowhome.test/robots.txt', "User-agent: *\nAllow: /\nCrawl-delay: 30\n", 200, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::$handler = static function ( string $url, array $args ) {
            if ( ! str_starts_with( $url, 'https://slowhome.test/' ) || str_ends_with( $url, '/robots.txt' ) ) {
                return null;
            }
            if ( (int) ( $args['timeout'] ?? 0 ) < 15 ) {
                return new WP_Error( 'http_request_failed', sprintf( 'cURL error 28: Operation timed out after %d milliseconds with 0 bytes received', (int) $args['timeout'] * 1000 + 2 ) );
            }
            $title = 'Slowhome ' . trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
            return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'headers' => [ 'content-type' => 'text/html; charset=utf-8' ], 'body' => sprintf( self::PAGE, $title, $title, 'slowhome.test' ), 'cookies' => [] ];
        };
        [ $id ]  = Container::get( Prospects::class )->upsertDiscovered( 'slowhome.test', [] );
        $runner  = Container::get( JobRunner::class );
        $homeAsk = static fn(): int => count( array_filter( self::urls(), static fn( string $u ): bool => 'https://slowhome.test/' === $u ) );

        // Short runs (15 s: the homepage's 15 s timeout is capped to 14 s): no verdict, however many there are.
        for ( $i = 1; $i <= SiteProfiler::MAX_DEFERRALS + 2; $i++ ) {
            $result = $runner->runType( 'evaluate', [ 'prospect_id' => $id ], TimeBudget::seconds( 15 ) );
            $row    = Container::get( Prospects::class )->find( $id );
            $this->assertSame( Prospects::STATUS_DISCOVERED, $row['status'], 'run ' . $i . ' (before: rejected as "Site unreachable" on run 4)' );
            $this->assertSame( 0, (int) ( $result['evaluated'] ?? 0 ), 'run ' . $i );
            $this->assertSame( 1, (int) ( $result['deferred'] ?? 0 ), 'run ' . $i );
            $this->laterRun( 'slowhome.test' );
        }
        $this->assertSame( SiteProfiler::MAX_DEFERRALS, $homeAsk(), 'after 3 short runs, short runs skip the site without asking it again' );

        // Runs with time for the whole timeout (a tick's first job) fetch it, and the pages after it get their own
        // MAX_DEFERRALS runs: the site is judged with its contact and about pages, not on the homepage alone.
        $runs = 0;
        do {
            $result = $runner->runType( 'evaluate', [ 'prospect_id' => $id ], TimeBudget::seconds( 18 ) );
            $this->laterRun( 'slowhome.test' );
        } while ( 0 === (int) ( $result['evaluated'] ?? 0 ) && ++$runs <= SiteProfiler::MAX_DEFERRALS );
        $this->assertSame( 1, (int) ( $result['evaluated'] ?? 0 ) );
        $this->assertSame( 2, $runs, 'the homepage run and the contact-page run stopped for lack of time' );
        $row = Container::get( Prospects::class )->find( $id );
        $this->assertCount( 2, (array) ( $row['profile']['pages'] ?? [] ), 'judged with its extra pages' );
        $this->assertNotSame( Prospects::STATUS_DISCOVERED, $row['status'] );
        $this->assertStringStartsNotWith( 'Site unreachable', (string) $row['rejection_reason'] );
        $this->assertNotSame( 'unreachable', $result['items'][0]['outcome'] ?? '' );
        $this->assertSame( 15, HttpFixtures::$requests[ array_search( 'https://slowhome.test/', array_reverse( self::urls(), true ), true ) ]['args']['timeout'], 'the homepage got its whole timeout' );
        $this->assertSame( SiteProfiler::MAX_DEFERRALS + 1, $homeAsk(), 'and was fetched once, then carried' );
    }

    /** A site that does not answer within a run that had the time is still judged (the 4th run onwards). */
    public function testAHomepageThatTimesOutInARunWithTheTimeIsUnreachable(): void {
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 0 ); // no real sleep between same-host pages
        foreach ( [ 'https://deadhome.test', 'https://www.deadhome.test', 'http://deadhome.test' ] as $origin ) {
            HttpFixtures::add( $origin . '/robots.txt', '', 404, [ 'content-type' => 'text/plain' ] );
        }
        HttpFixtures::$handler = static fn( string $url, array $args ) => str_contains( $url, 'deadhome.test/' ) && ! str_ends_with( $url, '/robots.txt' )
            ? new WP_Error( 'http_request_failed', sprintf( 'cURL error 28: Operation timed out after %d milliseconds with 0 bytes received', (int) $args['timeout'] * 1000 + 1 ) )
            : null;
        [ $id ] = Container::get( Prospects::class )->upsertDiscovered( 'deadhome.test', [] );
        set_transient( SiteProfiler::CARRY_PREFIX . md5( 'deadhome.test' ), [ 'deferrals' => SiteProfiler::MAX_DEFERRALS, 'pages' => [], 'tried' => [] ], DAY_IN_SECONDS );
        $result = Container::get( JobRunner::class )->runType( 'evaluate', [ 'prospect_id' => $id ], TimeBudget::seconds( 18 ) );
        $this->assertSame( 1, (int) ( $result['unreachable'] ?? 0 ) );
        $this->assertStringStartsWith( 'Site unreachable (cURL error 28', (string) Container::get( Prospects::class )->find( $id )['rejection_reason'] );
        $this->assertSame( 15, HttpFixtures::$requests[ array_search( 'https://deadhome.test/', self::urls(), true ) ]['args']['timeout'], 'the homepage had its own whole 15 s timeout' );
    }

    /**
     * Review finding, continued: "Evaluate now" (25 s) on a slow site answered "not enough time" with nothing
     * evaluated. It now gives its verdict in that request, judged on the pages that fitted.
     */
    public function testEvaluateNowGivesAVerdictWhenTheTimeRunsOutAfterTheHomepage(): void {
        $this->site( 'crawlnow.test', 30 );
        [ $id ] = Container::get( Prospects::class )->upsertDiscovered( 'crawlnow.test', [] );
        $req    = new \WP_REST_Request( 'POST', '/lga/v1/prospects/' . $id . '/evaluate' );
        $req->set_param( 'id', $id );
        $out = ( new \LinkGrowth\Autopilot\Admin\Rest\ProspectsController( Container::plugin()->container() ) )->evaluate( $req );
        $this->assertTrue( $out['ok'] );
        $this->assertSame( 1, (int) ( $out['result']['evaluated'] ?? 0 ), 'before: skipped, time_budget' );
        $this->assertNotSame( Prospects::STATUS_DISCOVERED, Container::get( Prospects::class )->find( $id )['status'] );
        $this->assertNotContains( 'https://crawlnow.test/contact/', self::urls(), 'the contact page did not fit (Crawl-delay 30) and was not waited for' );
        $this->assertFalse( get_transient( SiteProfiler::CARRY_PREFIX . md5( 'crawlnow.test' ) ) );
    }

    public function testADeferredEvaluationContinuesWithTheNextHomepageAddress(): void {
        // An earlier run found https://apexdown.test/ failing for real and ran out of time before the next address.
        $this->site( 'www.apexdown.test' );
        set_transient( SiteProfiler::CARRY_PREFIX . md5( 'apexdown.test' ), [ 'deferrals' => 1, 'pages' => [], 'tried' => [ 'https://apexdown.test/' => 'cURL error 28: Operation timed out after 15001 milliseconds' ] ], DAY_IN_SECONDS );
        $profile = Container::get( SiteProfiler::class )->profile( 'apexdown.test', 'https://apexdown.test/', 0 );
        $this->assertNotContains( 'https://apexdown.test/', self::urls(), 'not asked again' );
        $this->assertContains( 'https://www.apexdown.test/', self::urls() );
        $this->assertTrue( $profile->reachable );
        $this->assertSame( 2, $profile->fetches, 'the earlier try still counts' );

        // All three addresses failed for real over the runs: the site is judged with the real error, not "deadline".
        HttpFixtures::reset();
        set_transient( SiteProfiler::CARRY_PREFIX . md5( 'deadsite.test' ), [ 'deferrals' => 2, 'pages' => [], 'tried' => [ 'https://deadsite.test/' => 'cURL error 28: timed out', 'https://www.deadsite.test/' => 'cURL error 28: timed out' ] ], DAY_IN_SECONDS );
        HttpFixtures::add( 'http://deadsite.test/robots.txt', '', 404, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::$handler = static fn( string $url ) => str_ends_with( $url, '/robots.txt' ) ? null : new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
        $dead = Container::get( SiteProfiler::class )->profile( 'deadsite.test', 'https://deadsite.test/', 0 );
        $this->assertFalse( $dead->reachable );
        $this->assertSame( 'cURL error 28: timed out', $dead->error );
        $this->assertSame( [ 'http://deadsite.test/' ], array_values( array_filter( self::urls(), static fn( string $u ): bool => ! str_ends_with( $u, '/robots.txt' ) ) ), 'only the last address was asked' );
    }

    // ------------------------------------------------------------------ providers, listings, backlinks

    public function testAProviderLookupTheRunHadNoTimeForRecordsNoProviderError(): void {
        $c        = Container::plugin()->container();
        $provider = new class( $c->get( Secrets::class ), $c->get( Settings::class ), $c->get( Client::class ), $c->get( Logger::class ) ) extends AbstractProvider {
            public function id(): string {
                return 'fakeprov';
            }
            public function lookup(): void {
                $r = $this->client->api( 'GET', 'https://api.fakeprov.test/v1' );
                $r->ok() ? $this->succeed() : $this->fail( 'Lookup failed: ' . $this->describe( $r ) );
            }
        };
        $client = $c->get( Client::class );
        $client->within( TimeBudget::seconds( 2.0 ), fn() => $provider->lookup() );
        $this->assertFalse( get_option( 'lga_provider_error_fakeprov', false ), 'no "provider failing" notice' );
        $this->assertNotSame( '', $provider->lastError(), 'the caller still sees that nothing came back' );

        $provider->lookup(); // a real failure (no fixture for the host) is still recorded
        $this->assertIsArray( get_option( 'lga_provider_error_fakeprov', false ) );
    }

    public function testASearchTheRunHadNoTimeForGetsItsQueryBack(): void {
        $c      = Container::plugin()->container();
        $search = new class( $c->get( Secrets::class ), $c->get( Settings::class ), $c->get( Client::class ), $c->get( Logger::class ) ) extends AbstractSearch {
            public function id(): string {
                return 'fakesearch';
            }
            public function label(): string {
                return 'Fake';
            }
            public function isConfigured(): bool {
                return true;
            }
            protected function dailyLimit(): int {
                return 10;
            }
            public function search( string $query, array $opts = [] ): array {
                $this->consume();
                $r = $this->client->api( 'GET', 'https://api.fakesearch.test/?q=' . rawurlencode( $query ) );
                return $r->ok() ? [] : $this->fail( 'Fake search error: ' . (string) $r->error );
            }
        };
        $client = $c->get( Client::class );
        $client->within( TimeBudget::seconds( 2.0 ), fn() => $search->search( 'lab directories' ) );
        $this->assertSame( 0, $search->usedToday(), 'the query was not run, so it is given back' );
        $this->assertNotSame( '', $search->lastError(), 'DiscoveryEngine then retries the query instead of logging 0 results' );
        $this->assertFalse( get_option( 'lga_provider_error_fakesearch', false ) );
    }

    public function testAListingCheckTheRunHadNoTimeForLeavesTheSubmissionDue(): void {
        $client   = $this->client();
        $prospect = [ 'id' => 0, 'domain' => 'labdirectory.test', 'homepage_url' => 'https://labdirectory.test/', 'name' => 'Lab Directory', 'status' => 'qualified' ];
        $result   = $client->within( TimeBudget::seconds( 2.0 ), fn() => Container::get( ListingVerifier::class )->verify( [ 'id' => 0, 'listing_url' => 'https://labdirectory.test/listing/1/', 'target_url' => 'https://labdirectory.test/add/', 'payload' => [] ], $prospect ) );
        $this->assertTrue( $result['deferred'] ?? false );
        $this->assertFalse( $result['confirmed'] );
        $this->assertSame( [], HttpFixtures::$requests );
    }

    /**
     * Review finding: a submission whose pages did not all fit in one run (Crawl-delay 30 on its host, two pages) was
     * deferred on every run without progress (the same first page each time), and verifyDue() stopped there, so the
     * submissions due after it were never verified again. Each run here is a new PHP process (no in-memory cache).
     */
    public function testAListingThatDoesNotFitOneRunMakesProgressAndDoesNotBlockTheOthers(): void {
        $this->site( 'slowlisting.test', 30 );
        HttpFixtures::add( 'https://slowlisting.test/a-listing', '<html><body><p>Listings.</p></body></html>' );
        HttpFixtures::add( 'https://slowlisting.test/a-page', '<html><body><p>Resources.</p></body></html>' );
        $this->site( 'quicklisting.test' );
        HttpFixtures::add( 'https://quicklisting.test/b-listing', '<html><body><a href="https://northwind.test/">Northwind Supply Co</a></body></html>' );
        $subs = Container::get( Submissions::class );
        $ago  = static fn( int $days ): string => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        [ $pa ] = Container::get( Prospects::class )->upsertDiscovered( 'slowlisting.test', [] );
        [ $pb ] = Container::get( Prospects::class )->upsertDiscovered( 'quicklisting.test', [] );
        $a = $subs->create( (int) $pa, null, 'https://slowlisting.test/submit/', [ 'status' => 'submitted', 'submitted_at' => $ago( 5 ), 'next_verify_at' => $ago( 2 ), 'listing_url' => 'https://slowlisting.test/a-listing', 'payload' => [ 'destination' => [ 'opportunity_type' => 'resource_page', 'url' => 'https://slowlisting.test/a-page' ] ] ] );
        $b = $subs->create( (int) $pb, null, 'https://quicklisting.test/submit/', [ 'status' => 'submitted', 'submitted_at' => $ago( 5 ), 'next_verify_at' => $ago( 1 ), 'listing_url' => 'https://quicklisting.test/b-listing' ] );
        $runner = Container::get( JobRunner::class );

        $run1 = $runner->runType( 'verify_listings', [], TimeBudget::seconds( 20 ) );
        $this->assertSame( 1, $run1['deferred'] ?? 0, 'A: the second page on the Crawl-delay host did not fit' );
        $this->assertSame( Submissions::STATUS_CONFIRMED, $subs->find( $b )['status'], 'B is verified in the same run (measured before: never)' );
        $rowA = $subs->find( $a );
        $this->assertSame( Submissions::STATUS_SUBMITTED, $rowA['status'] );
        $this->assertLessThanOrEqual( time(), strtotime( $rowA['next_verify_at'] . ' UTC' ), 'still due' );
        $this->assertSame( [ 'https://slowlisting.test/a-listing' ], $rowA['response'][ ListingVerifier::CARRY_KEY ]['checked'] ?? null );

        $this->newProcess( 'slowlisting.test' );
        $run2 = $runner->runType( 'verify_listings', [], TimeBudget::seconds( 20 ) );
        $this->assertSame( 1, $run2['checked'] ?? 0 );
        $this->assertSame( 0, $run2['deferred'] ?? 0 );
        $rowA = $subs->find( $a );
        $this->assertGreaterThan( time() + 2 * DAY_IN_SECONDS, strtotime( $rowA['next_verify_at'] . ' UTC' ), 'rescheduled as usual (+3 days)' );
        $this->assertSame( 2, $rowA['response']['verification']['pages_checked'] ?? null );
        $this->assertArrayNotHasKey( ListingVerifier::CARRY_KEY, $rowA['response'] );
        $fetched = array_count_values( self::urls() );
        $this->assertSame( 1, $fetched['https://slowlisting.test/a-listing'] ?? 0, 'the first page is not fetched again' );
        $this->assertSame( 1, $fetched['https://slowlisting.test/a-page'] ?? 0 );
    }

    /** Review finding, continued: a search that answered is not run (and paid for) again by the next run. */
    public function testADeferredListingCheckReusesItsSearchResults(): void {
        Profiles::apply( Container::get( \LinkGrowth\Autopilot\Support\Settings::class ) ); // the listing search looks for the brand
        Container::get( Secrets::class )->set( 'brave_api_key', 'brave-key' );
        $this->site( 'slowsearch.test', 30 );
        HttpFixtures::add( 'https://slowsearch.test/members/one', '<html><body><p>Members.</p></body></html>' );
        HttpFixtures::add( 'https://slowsearch.test/members/two', '<html><body><p>More members.</p></body></html>' );
        HttpFixtures::$handler = static fn( string $url ) => str_contains( $url, 'api.search.brave.com' )
            ? [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'headers' => [ 'content-type' => 'application/json' ], 'body' => (string) wp_json_encode( [ 'web' => [ 'results' => [ [ 'url' => 'https://slowsearch.test/members/one', 'title' => 'One' ], [ 'url' => 'https://slowsearch.test/members/two', 'title' => 'Two' ] ] ] ] ), 'cookies' => [] ]
            : null;
        [ $pid ] = Container::get( Prospects::class )->upsertDiscovered( 'slowsearch.test', [] );
        $subs    = Container::get( Submissions::class );
        $id      = $subs->create( (int) $pid, null, 'https://slowsearch.test/submit/', [ 'status' => 'submitted', 'submitted_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ), 'next_verify_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
        $runner  = Container::get( JobRunner::class );
        $search  = static fn(): int => count( array_filter( self::urls(), static fn( string $u ): bool => str_contains( $u, 'api.search.brave.com' ) ) );

        $this->assertSame( 1, $runner->runType( 'verify_listings', [], TimeBudget::seconds( 20 ) )['deferred'] ?? 0 );
        $this->assertSame( 1, $search() );
        $this->newProcess( 'slowsearch.test' );
        $run2 = $runner->runType( 'verify_listings', [], TimeBudget::seconds( 20 ) );
        $this->assertSame( 0, $run2['deferred'] ?? 0 );
        $this->assertSame( 1, $search(), 'the query was not sent again' );
        $row = $subs->find( $id );
        $this->assertSame( 2, $row['response']['verification']['pages_checked'] ?? null );
        $this->assertTrue( $row['response']['verification']['searched'] ?? false );
        $fetched = array_count_values( self::urls() );
        $this->assertSame( 1, $fetched['https://slowsearch.test/members/one'] ?? 0 );
        $this->assertSame( 1, $fetched['https://slowsearch.test/members/two'] ?? 0 );
    }

    /** Review finding, continued: a host that never fits is judged after MAX_DEFERRALS runs, not deferred forever. */
    public function testAListingHostThatNeverFitsIsRescheduledAfterTheLastDeferral(): void {
        HttpFixtures::add( 'https://hanglisting.test/robots.txt', '', 404, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::$handler = static fn( string $url ) => str_starts_with( $url, 'https://hanglisting.test/' ) && ! str_ends_with( $url, '/robots.txt' )
            ? new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 9001 milliseconds' )
            : null;
        [ $pid ] = Container::get( Prospects::class )->upsertDiscovered( 'hanglisting.test', [] );
        $subs    = Container::get( Submissions::class );
        $id      = $subs->create( (int) $pid, null, 'https://hanglisting.test/submit/', [ 'status' => 'submitted', 'submitted_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ), 'next_verify_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ), 'listing_url' => 'https://hanglisting.test/listing' ] );
        $runner  = Container::get( JobRunner::class );
        for ( $i = 1; $i <= ListingVerifier::MAX_DEFERRALS; $i++ ) {
            $this->assertSame( 1, $runner->runType( 'verify_listings', [], TimeBudget::seconds( 10 ) )['deferred'] ?? 0, 'run ' . $i );
            $this->newProcess( 'hanglisting.test' );
        }
        $last = $runner->runType( 'verify_listings', [], TimeBudget::seconds( 10 ) );
        $this->assertSame( 0, $last['deferred'] ?? 0 );
        $row = $subs->find( $id );
        $this->assertGreaterThan( time() + 2 * DAY_IN_SECONDS, strtotime( $row['next_verify_at'] . ' UTC' ), 'rescheduled (+3 days), so the 30-day limit is reached in the end' );
        $this->assertSame( 'Listing not visible yet (0 pages checked; the others did not fit in the time of 4 runs in a row).', $row['response']['verification']['note'] ?? null );
        $this->assertArrayNotHasKey( ListingVerifier::CARRY_KEY, $row['response'] );
    }

    /** A new PHP process a few minutes later: no in-memory page cache, and the per-host delay has passed. */
    private function newProcess( string $host ): void {
        ( new \ReflectionProperty( Client::class, 'cache' ) )->setValue( $this->client(), [] );
        $this->laterRun( $host );
    }

    public function testABacklinkCheckTheRunHadNoTimeForLeavesTheLinkDue(): void {
        [ $id ] = Container::get( Backlinks::class )->upsert( 'https://resources.test/links/', 'https://northwind.test/', [ 'status' => Backlinks::STATUS_ACTIVE, 'origin' => 'manual', 'last_seen_live' => gmdate( 'Y-m-d H:i:s' ) ] );
        $before = Container::get( Backlinks::class )->find( $id );
        $client = $this->client();
        $r      = $client->within( TimeBudget::seconds( 2.0 ), fn() => Container::get( BacklinkMonitor::class )->check( $before ) );
        $this->assertTrue( $r['deferred'] ?? false );
        $after = Container::get( Backlinks::class )->find( $id );
        $this->assertSame( $before['last_checked'], $after['last_checked'], 'not marked checked' );
        $this->assertSame( $before['next_check_at'], $after['next_check_at'], 'still due (not "retrying tomorrow")' );
        $this->assertSame( Backlinks::STATUS_ACTIVE, $after['status'] );
    }

    public function testABacklinkCheckThatDoesNotFitDoesNotHoldBackTheOtherSites(): void {
        // Two links on a "Crawl-delay: 30" host, then one on an ordinary host. The second slow-host page cannot wait
        // 30 s inside a 20 s run; it stays due and the run goes on to the other host instead of stopping there.
        $this->site( 'slowlinks.test', 30 );
        $this->site( 'otherlinks.test' );
        $repo = Container::get( Backlinks::class );
        $at   = static fn( int $min ): string => gmdate( 'Y-m-d H:i:s', time() - 3600 + 60 * $min );
        $base = [ 'status' => Backlinks::STATUS_ACTIVE, 'origin' => 'manual', 'last_seen_live' => gmdate( 'Y-m-d H:i:s' ) ];
        [ $a1 ] = $repo->upsert( 'https://slowlinks.test/', 'https://northwind.test/', $base + [ 'next_check_at' => $at( 1 ) ] );
        [ $a2 ] = $repo->upsert( 'https://slowlinks.test/contact/', 'https://northwind.test/', $base + [ 'next_check_at' => $at( 2 ) ] );
        [ $b ]  = $repo->upsert( 'https://otherlinks.test/', 'https://northwind.test/', $base + [ 'next_check_at' => $at( 3 ) ] );
        $a2Due  = $repo->find( $a2 )['next_check_at'];

        $budget = TimeBudget::seconds( 20.0 );
        $out    = $this->client()->within( $budget, fn() => Container::get( BacklinkMonitor::class )->checkDue( $budget, 10 ) );

        $this->assertSame( 2, $out['checked'], 'the first slow-host link and the other host were checked' );
        $this->assertContains( 'https://otherlinks.test/', self::urls(), 'the other host was not held back' );
        $this->assertNotContains( 'https://slowlinks.test/contact/', self::urls(), 'no 30 s wait inside the run' );
        $this->assertNotNull( $repo->find( $a1 )['last_checked'] );
        $this->assertNotNull( $repo->find( $b )['last_checked'] );
        $this->assertNull( $repo->find( $a2 )['last_checked'], 'not marked checked' );
        $this->assertSame( $a2Due, $repo->find( $a2 )['next_check_at'], 'still due for the next run' );
        $this->assertTrue( $out['more_due'] );
        $this->assertSame( '1 check did not fit in this run and stays due.', $out['note'] );
    }

    // ------------------------------------------------------------------ the runner and the guard

    public function testThePauseIsAskedAgainBetweenJobs(): void {
        $jobs = Container::get( Jobs::class );
        foreach ( [ 'cleanup', 'cleanup', 'cleanup' ] as $i => $type ) {
            $jobs->enqueue( $type, [ 'n' => $i, 'manual' => true ], 5 );
        }
        // A backup starts while the tick runs: the runner asks only between jobs, so the first job still runs.
        $asked = 0;
        $GLOBALS['lga_test_filters'][ LoadGuard::FILTER_PAUSE ] = [ static function () use ( &$asked ) {
            $asked++;
            return 'A backup started';
        } ];
        $runner = Container::get( JobRunner::class );
        $out = $runner->run( TimeBudget::seconds( 60 ) );
        $this->assertSame( 1, $out['ran'], 'stopped after the job that was running' );
        $this->assertSame( LoadGuard::CODE_OWNER, $out['paused'] ?? null );
        $this->assertCount( 2, $jobs->findBy( [ 'type' => 'cleanup', 'status' => Jobs::STATUS_QUEUED ] ), 'the rest stay queued' );
        $this->assertSame( 1, $asked, 'asked once, before the second claim' );
    }

    public function testAFullPhpWorkerPoolPausesBackgroundWork(): void {
        $pool  = [ 'idle-processes' => 1, 'active-processes' => 2, 'total-processes' => 3, 'listen-queue' => 0 ];
        $guard = static function () use ( &$pool ): LoadGuard {
            return new LoadGuard( null, new ServerResources( static fn() => null, static fn() => null, 'Linux', static function () use ( &$pool ) {
                return $pool;
            } ) );
        };
        $this->assertNull( $guard()->pauseReason(), 'one worker is free and nobody waits' );

        $pool['idle-processes'] = 0;
        $pool['active-processes'] = 3;
        $reason = $guard()->pauseReason();
        $this->assertSame( LoadGuard::CODE_WORKERS_BUSY, $reason['code'] ?? null );
        $this->assertSame( 'Paused: every PHP worker is busy (3 of 3 busy) — resumes when one is free.', $reason['text'] );

        $pool = [ 'idle-processes' => 1, 'active-processes' => 2, 'total-processes' => 3, 'listen-queue' => 2 ];
        $this->assertStringContainsString( '2 waiting', (string) ( $guard()->pauseReason()['text'] ?? '' ), 'requests queued for a worker' );

        // pm = ondemand keeps no idle worker by design (one is started per request): only waiting requests count.
        $pool = [ 'idle-processes' => 0, 'active-processes' => 1, 'total-processes' => 1, 'listen-queue' => 0, 'process-manager' => 'ondemand' ];
        $this->assertNull( $guard()->pauseReason(), 'an ondemand pool running only this job is not full' );
        $pool['listen-queue'] = 1;
        $this->assertSame( LoadGuard::CODE_WORKERS_BUSY, $guard()->pauseReason()['code'] ?? null, 'requests wait: full' );
        $pool = [ 'idle-processes' => 0, 'active-processes' => 3, 'total-processes' => 3, 'listen-queue' => 0, 'process-manager' => 'dynamic' ];
        $this->assertSame( LoadGuard::CODE_WORKERS_BUSY, $guard()->pauseReason()['code'] ?? null, 'dynamic with no spare worker left: full' );

        $GLOBALS['lga_test_filters'][ LoadGuard::FILTER_WORKERS ] = [ static fn() => false ];
        $this->assertNull( $guard()->pauseReason(), 'the filter switches it off' );
        unset( $GLOBALS['lga_test_filters'][ LoadGuard::FILTER_WORKERS ] );

        $pool = false; // not PHP-FPM (CLI, built-in server): nothing known, no pause
        $this->assertNull( $guard()->pauseReason() );
        $this->assertNull( ( new ServerResources() )->fpm(), 'this CLI has no fpm_get_status()' );
    }
}
