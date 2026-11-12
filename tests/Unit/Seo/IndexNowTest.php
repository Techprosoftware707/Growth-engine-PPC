<?php
/**
 * IndexNow: key generation and serving, first submission, only-changed resubmission, refusals keep URLs pending.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Seo;

require_once dirname( __DIR__, 2 ) . '/Support/StubsSeo.php';

use PPC\LinkGrowth\Automation\Audit;
use PPC\LinkGrowth\Automation\Jobs\IndexNowJob;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Seo\IndexNow;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Locks;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\StubsSeo;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class IndexNowTest extends TestCase {

    private int $terminated = 0;

    /** @var array<int,array<string,mixed>> decoded IndexNow request bodies */
    private array $sent = [];

    private int $answer = 200;

    protected function setUp(): void {
        parent::setUp();
        StubsSeo::reset();
        $this->terminated = 0;
        $this->sent       = [];
        $this->answer     = 200;
        HttpFixtures::$handler = function ( string $url, array $args ) {
            if ( IndexNow::ENDPOINT !== $url ) {
                return null;
            }
            $this->sent[] = [ 'method' => $args['method'] ?? '', 'headers' => $args['headers'] ?? [], 'body' => json_decode( (string) ( $args['body'] ?? '' ), true ) ];
            return [ 'response' => [ 'code' => $this->answer, 'message' => '' ], 'headers' => [], 'body' => '', 'cookies' => [] ];
        };
    }

    protected function tearDown(): void {
        unset( $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] );
        parent::tearDown();
    }

    private function indexNow(): IndexNow {
        return new IndexNow(
            Container::get( Settings::class ),
            Container::get( Logger::class ),
            Container::get( Client::class ),
            Container::get( Locks::class ),
            Container::get( Audit::class ),
            function (): void {
                $this->terminated++;
            }
        );
    }

    private function seed(): void {
        StubsSeo::addPost( 1, [ 'post_type' => 'post', 'post_title' => 'Storage Guide', 'post_modified_gmt' => '2026-10-01 10:00:00' ] );
        StubsSeo::addPost( 2, [ 'post_type' => 'page', 'post_title' => 'About', 'post_modified_gmt' => '2026-10-01 10:00:00' ] );
        StubsSeo::addPost( 3, [ 'post_type' => 'product', 'post_title' => 'Bacteriostatic Water', 'post_modified_gmt' => '2026-10-01 10:00:00' ] );
        StubsSeo::addPost( 4, [ 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Draft' ] );
        StubsSeo::addPost( 5, [ 'post_type' => 'page', 'post_title' => 'Members', 'post_password' => 'secret' ] );
        StubsSeo::addPost( 6, [ 'post_type' => 'page', 'post_title' => 'Thank You' ], [ 'rank_math_robots' => [ 'noindex' ] ] );
        StubsSeo::addPost( 7, [ 'post_type' => 'attachment', 'post_status' => 'publish', 'post_title' => 'logo' ] );
    }

    public function testKeyIsGeneratedOnceStoredInSettingsAndServedOnlyAtItsPath(): void {
        $in  = $this->indexNow();
        $key = $in->key();
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $key );
        $this->assertSame( $key, Container::get( Settings::class )->get( 'seo.indexnow_key' ) );
        $this->assertSame( $key, $this->indexNow()->key(), 'Generated once.' );
        $this->assertSame( 'https://purepeptidescanada.test/' . $key . '.txt', $in->keyUrl() );

        $this->assertSame( $key, $in->keyFileBody( '/' . $key . '.txt' ) );
        $this->assertSame( $key, $in->keyFileBody( '/' . $key . '.txt?cache=1' ) );
        $this->assertNull( $in->keyFileBody( '/' . $key . '.txt', 'POST' ) );
        $this->assertNull( $in->keyFileBody( '/robots.txt' ) );
        $this->assertNull( $in->keyFileBody( '/sub/' . $key . '.txt' ) );
        $this->assertNull( $in->keyFileBody( '/' . strrev( $key ) . '.txt' ) );
        $this->assertNull( $in->keyFileBody( '/' . $key . '.txt/' ) );

        // serveKey(): exact path → key as body, request ended.
        $_SERVER['REQUEST_URI']    = '/' . $key . '.txt';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ob_start();
        $in->serveKey();
        $this->assertSame( $key, ob_get_clean() );
        $this->assertSame( 1, $this->terminated );

        // Any other request is left alone.
        foreach ( [ '/', '/shop/', '/robots.txt', '/' . $key . '.txt.bak', '/wp-json/ppclg/v1/seo/indexnow' ] as $uri ) {
            $_SERVER['REQUEST_URI'] = $uri;
            ob_start();
            $in->serveKey();
            $this->assertSame( '', ob_get_clean(), $uri );
        }
        $this->assertSame( 1, $this->terminated );

        // Turned off: not served.
        Container::get( Settings::class )->set( 'seo.indexnow', false );
        $this->assertNull( $in->keyFileBody( '/' . $key . '.txt' ) );
    }

    public function testMalformedStoredKeyIsReplacedAndValidCustomKeyKept(): void {
        Container::get( Settings::class )->set( 'seo.indexnow_key', 'bad key!' );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $this->indexNow()->key() );
        Container::get( Settings::class )->set( 'seo.indexnow_key', 'Custom-Key-1234' );
        $this->assertSame( 'Custom-Key-1234', $this->indexNow()->key() );
        $this->assertTrue( IndexNow::looksLikeKeyRequest( '/Custom-Key-1234.txt' ) );
        $this->assertFalse( IndexNow::looksLikeKeyRequest( '/shop/' ) );
    }

    public function testFirstRunSubmitsAllPublicIndexableUrlsThenOnlyChangedOnes(): void {
        $this->seed();
        $in  = $this->indexNow();
        $res = $in->run( TimeBudget::seconds( 20 ) );
        $this->assertTrue( $res['ok'] );
        $this->assertSame( 4, $res['submitted'] );
        $this->assertCount( 1, $this->sent );

        $req = $this->sent[0];
        $this->assertSame( 'POST', $req['method'] );
        $this->assertStringStartsWith( 'application/json', (string) ( $req['headers']['Content-Type'] ?? '' ) );
        $key = $in->key();
        $this->assertSame( 'purepeptidescanada.test', $req['body']['host'] );
        $this->assertSame( $key, $req['body']['key'] );
        $this->assertSame( 'https://purepeptidescanada.test/' . $key . '.txt', $req['body']['keyLocation'] );
        $urls = $req['body']['urlList'];
        sort( $urls );
        $this->assertSame(
            [
                'https://purepeptidescanada.test/',
                'https://purepeptidescanada.test/about/',
                'https://purepeptidescanada.test/product/bacteriostatic-water/',
                'https://purepeptidescanada.test/storage-guide/',
            ],
            $urls,
            'Drafts, password-protected, noindex items and attachments are never submitted.'
        );

        $status = $in->status();
        $this->assertTrue( $status['enabled'] );
        $this->assertSame( 200, $status['last_status'] );
        $this->assertSame( '', $status['last_error'] );
        $this->assertSame( 4, $status['submitted_total'] );
        $this->assertSame( 0, $status['pending'] );
        $this->assertTrue( $status['initialized'] );
        $this->assertIsString( $status['last_submitted_at'] );
        $this->assertSame( 1, (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_ppclg_audit_log WHERE action = 'seo.indexnow_submitted'" ) );

        // Nothing changed: nothing is sent.
        $again = $in->run( TimeBudget::seconds( 20 ) );
        $this->assertSame( 0, $again['submitted'] );
        $this->assertCount( 1, $this->sent );
        $this->assertFalse( $in->isDue() );

        // One item changes and is saved: it is queued by the hook and only its URL goes out.
        StubsSeo::$posts[1]['post_modified_gmt'] = '2026-11-01 09:00:00';
        $in->onTransition( 'publish', 'publish', StubsSeo::post( 1 ) );
        $in->onTransition( 'draft', 'publish', StubsSeo::post( 4 ) ); // unpublished: ignored
        $in->onSavePost( 6, StubsSeo::post( 6 ), true );            // noindex: ignored
        $this->assertSame( [ 1 ], array_keys( $in->pending() ) );
        $this->assertTrue( $in->isDue() );
        $third = $in->run( TimeBudget::seconds( 20 ) );
        $this->assertSame( 1, $third['submitted'] );
        $this->assertSame( [ 'https://purepeptidescanada.test/storage-guide/' ], $this->sent[1]['body']['urlList'] );
        $this->assertSame( [], $in->pending() );
        $this->assertSame( 5, $in->status()['submitted_total'] );

        // Saved again without a change: dropped from the pending set without a request.
        $in->onSavePost( 1, StubsSeo::post( 1 ), true );
        $fourth = $in->run( TimeBudget::seconds( 20 ) );
        $this->assertSame( 0, $fourth['submitted'] );
        $this->assertCount( 2, $this->sent );
        $this->assertSame( [], $in->pending() );
    }

    public function testRefusalsAreLoggedKeepUrlsPendingAndBackOff(): void {
        $this->seed();
        $in = $this->indexNow();
        $in->run( TimeBudget::seconds( 20 ) );

        StubsSeo::$posts[2]['post_modified_gmt'] = '2026-11-02 09:00:00';
        $in->onTransition( 'publish', 'publish', StubsSeo::post( 2 ) );

        foreach ( [ 403 => 'key', 422 => 'belong to', 400 => 'invalid', 429 => 'slow down' ] as $code => $phrase ) {
            $this->answer = $code;
            $res          = $in->run( TimeBudget::seconds( 20 ), [ 'force' => true ] );
            $this->assertFalse( $res['ok'], "HTTP $code" );
            $this->assertSame( 0, $res['submitted'] );
            $this->assertSame( [ 2 ], array_keys( $in->pending() ), "HTTP $code keeps the URL pending" );
            $status = $in->status();
            $this->assertSame( $code, $status['last_status'] );
            $this->assertStringContainsString( $phrase, $status['last_error'] );
            $this->assertNotNull( $status['retry_after'] );
        }
        $this->assertFalse( $in->isDue(), 'The scheduled stage waits after a refusal.' );
        $blocked = $in->run( TimeBudget::seconds( 20 ) );
        $this->assertTrue( $blocked['skipped'] );
        $this->assertSame( 'rate_limited', $blocked['reason'] );

        $logged = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_ppclg_logs WHERE channel = 'seo' AND level = 'warning'" );
        $this->assertGreaterThanOrEqual( 4, $logged );

        // Accepted later (202 = key still being verified): the pending URL goes out and the error clears.
        $this->answer = 202;
        $ok           = $in->run( TimeBudget::seconds( 20 ), [ 'force' => true ] );
        $this->assertTrue( $ok['ok'] );
        $this->assertSame( 1, $ok['submitted'] );
        $this->assertSame( [], $in->pending() );
        $this->assertSame( '', $in->status()['last_error'] );
        $this->assertSame( 202, $in->status()['last_status'] );
    }

    public function testNetworkErrorKeepsPending(): void {
        $this->seed();
        HttpFixtures::$handler = static fn( string $url, array $args ) => IndexNow::ENDPOINT === $url ? new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) : null;
        $in  = $this->indexNow();
        $res = $in->run( TimeBudget::seconds( 20 ) );
        $this->assertFalse( $res['ok'] );
        $this->assertStringContainsString( 'timed out', $in->status()['last_error'] );
        $this->assertFalse( $in->status()['initialized'], 'A failed first run is retried as a full run.' );
        $this->assertSame( [ 1, 2, 3 ], array_keys( $in->pending() ) );
    }

    public function testDisabledNonProductionAndDiscouragedSitesNeverSubmit(): void {
        $this->seed();
        $in = $this->indexNow();

        Container::get( Settings::class )->set( 'seo.indexnow', false );
        $res = $in->run( TimeBudget::seconds( 20 ) );
        $this->assertTrue( $res['skipped'] );
        $this->assertStringContainsString( 'turned off', $res['summary'] );
        $in->onTransition( 'publish', 'draft', StubsSeo::post( 1 ) );
        $this->assertSame( [], $in->pending(), 'Nothing is queued while IndexNow is off.' );
        Container::get( Settings::class )->set( 'seo.indexnow', true );

        StubsSeo::$environment = 'staging';
        $this->assertStringContainsString( 'production', $in->run( TimeBudget::seconds( 20 ) )['summary'] );
        $this->assertFalse( $in->isDue() );
        StubsSeo::$environment = 'production';

        update_option( 'blog_public', '0' );
        $this->assertStringContainsString( 'discourage', $in->run( TimeBudget::seconds( 20 ) )['summary'] );
        $this->assertSame( [], $this->sent );
    }

    public function testJobResultCarriesSummaryAndCounters(): void {
        $this->seed();
        $c = Container::plugin()->container();
        $c->set( IndexNow::class, $this->indexNow() );
        $res = ( new IndexNowJob() )->handle( [], TimeBudget::seconds( 20 ), $c );
        $this->assertSame( 'indexnow', $res['job'] );
        $this->assertSame( 4, $res['submitted'] );
        $this->assertIsInt( $res['pending'] );
        $this->assertFalse( $res['more'] );
        $this->assertSame( 'Submitted 4 URLs to IndexNow (HTTP 200).', $res['summary'] );

        $this->answer = 403;
        StubsSeo::$posts[3]['post_modified_gmt'] = '2026-11-05 09:00:00';
        $c->get( IndexNow::class )->onTransition( 'publish', 'publish', StubsSeo::post( 3 ) );
        $refused = ( new IndexNowJob() )->handle( [], TimeBudget::seconds( 20 ), $c );
        $this->assertFalse( $refused['ok'] );
        $this->assertSame( 403, $refused['http_status'] );
        $this->assertSame( 1, $refused['pending'] );
    }

    public function testAuditLogRecordsSubmissionActor(): void {
        $this->seed();
        $GLOBALS['ppclg_test_user_id'] = 3;
        $this->indexNow()->run( TimeBudget::seconds( 20 ) );
        $row = Container::get( AuditLog::class )->findBy( [ 'action' => 'seo.indexnow_submitted' ] );
        $this->assertSame( 'user:3', $row[0]['actor'] ?? null );
    }
}
