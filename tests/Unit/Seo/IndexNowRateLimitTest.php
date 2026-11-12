<?php
/**
 * IndexNow after HTTP 429: "Submit now" (force) respects the back-off, Retry-After is honoured (delta seconds or
 * an HTTP date, capped at a week, never below an hour), and other refusals still allow an explicit retry.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Seo;

require_once dirname( __DIR__, 2 ) . '/Support/StubsSeo.php';

use PPC\LinkGrowth\Admin\Rest\SeoController;
use PPC\LinkGrowth\Automation\Audit;
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
use WP_REST_Request;

final class IndexNowRateLimitTest extends TestCase {

    private int $requests = 0;

    private int $answer = 429;

    private string $retryAfter = '';

    protected function setUp(): void {
        parent::setUp();
        StubsSeo::reset();
        $this->requests   = 0;
        $this->answer     = 429;
        $this->retryAfter = '';
        HttpFixtures::$handler = function ( string $url, array $args ) {
            unset( $args );
            if ( IndexNow::ENDPOINT !== $url ) {
                return null;
            }
            $this->requests++;
            $headers = '' !== $this->retryAfter ? [ 'retry-after' => $this->retryAfter ] : [];
            return [ 'response' => [ 'code' => $this->answer, 'message' => '' ], 'headers' => $headers, 'body' => '', 'cookies' => [] ];
        };
        StubsSeo::addPost( 11, [ 'post_type' => 'page', 'post_title' => 'About' ] );
    }

    private function indexNow(): IndexNow {
        return new IndexNow( Container::get( Settings::class ), Container::get( Logger::class ), Container::get( Client::class ), Container::get( Locks::class ), Container::get( Audit::class ), static function (): void {} );
    }

    private function waitSeconds( IndexNow $in ): int {
        $at = $in->status()['retry_after'];
        $this->assertIsString( $at );
        return (int) strtotime( $at ) - time();
    }

    public function testSubmitNowSendsNothingWhileIndexNowAskedToSlowDown(): void {
        $c  = Container::plugin()->container();
        $in = $this->indexNow();
        $c->set( IndexNow::class, $in );
        $controller = new SeoController( $c );

        $first = $controller->indexnowRun( new WP_REST_Request( 'POST', '/ppclg/v1/seo/indexnow/run' ) );
        $this->assertFalse( $first['ok'] );
        $this->assertSame( 1, $this->requests );
        $this->assertTrue( $first['status_data']['rate_limited'] );

        foreach ( [ 1, 2 ] as $click ) {
            $again = $controller->indexnowRun( new WP_REST_Request( 'POST', '/ppclg/v1/seo/indexnow/run' ) );
            $this->assertFalse( $again['ok'], "click $click" );
            $this->assertTrue( $again['skipped'], "click $click" );
            $this->assertSame( 'rate_limited', $again['reason'] );
            $this->assertStringContainsString( 'slow down', $again['message'] );
        }
        $this->assertSame( 1, $this->requests, 'clicking "Submit now" again re-sends nothing to the endpoint that asked us to slow down' );
        $this->assertSame( [ 11 ], array_keys( $in->pending() ), 'the URLs stay queued' );
    }

    public function testRetryAfterIsHonouredWithinLimits(): void {
        $in = $this->indexNow();

        $this->retryAfter = '86400';
        $in->run( TimeBudget::seconds( 20 ), [ 'full' => true ] );
        $this->assertEqualsWithDelta( 86400, $this->waitSeconds( $in ), 5, 'a 24 h Retry-After is honoured' );

        $this->expire();
        $this->retryAfter = gmdate( 'D, d M Y H:i:s', time() + 7200 ) . ' GMT';
        $in->run( TimeBudget::seconds( 20 ), [ 'full' => true ] );
        $this->assertEqualsWithDelta( 7200, $this->waitSeconds( $in ), 5, 'an HTTP-date Retry-After is honoured' );

        $this->expire();
        $this->retryAfter = '30';
        $in->run( TimeBudget::seconds( 20 ), [ 'full' => true ] );
        $this->assertEqualsWithDelta( 3600, $this->waitSeconds( $in ), 5, 'never less than an hour' );

        $this->expire();
        $this->retryAfter = '99999999';
        $in->run( TimeBudget::seconds( 20 ), [ 'full' => true ] );
        $this->assertEqualsWithDelta( 7 * 86400, $this->waitSeconds( $in ), 5, 'at most a week' );

        $this->expire();
        $this->retryAfter = 'soon';
        $in->run( TimeBudget::seconds( 20 ), [ 'full' => true ] );
        $this->assertEqualsWithDelta( 3600, $this->waitSeconds( $in ), 5, 'an invalid value falls back to an hour' );
        $this->assertSame( 5, $this->requests );
    }

    public function testOtherRefusalsStillAllowAnExplicitRetry(): void {
        $in           = $this->indexNow();
        $this->answer = 403;
        $in->run( TimeBudget::seconds( 20 ), [ 'full' => true ] );
        $this->assertFalse( $in->status()['rate_limited'] );
        $this->assertTrue( $in->run( TimeBudget::seconds( 20 ) )['skipped'], 'the schedule waits after a refusal' );

        $this->answer = 200;
        $res          = $in->run( TimeBudget::seconds( 20 ), [ 'full' => true, 'force' => true ] );
        $this->assertTrue( $res['ok'], 'after a key-check refusal "Submit now" may retry at once' );
        $this->assertSame( 2, $this->requests );
        $this->assertNull( $in->status()['retry_after'] );
    }

    private function expire(): void {
        $state                = get_option( IndexNow::OPTION_STATE );
        $state['retry_after'] = time() - 1;
        update_option( IndexNow::OPTION_STATE, $state, false );
    }
}
