<?php
/**
 * /seo REST controller: degrades to "not available" before the services are registered, reports refusals as
 * { ok:false, message } and returns the audit / IndexNow data the page renders.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Seo;

require_once dirname( __DIR__, 2 ) . '/Support/StubsSeo.php';

use PPC\LinkGrowth\Admin\Rest\SeoController;
use PPC\LinkGrowth\Automation\Audit;
use PPC\LinkGrowth\Seo\IndexNow;
use PPC\LinkGrowth\Seo\SiteAuditor;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Locks;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\StubsSeo;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_REST_Request;

final class SeoControllerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        StubsSeo::reset();
        $GLOBALS['ppclg_test_user_id'] = 1;
    }

    protected function tearDown(): void {
        $GLOBALS['ppclg_test_user_id'] = 0;
        parent::tearDown();
    }

    public function testUnregisteredServicesDegradeToNotAvailable(): void {
        // The plugin container registers the services (Plugin::registerServices); an empty container stands for an
        // installation where they cannot be built (its zero-argument fallback fails for these constructors).
        $empty      = new \PPC\LinkGrowth\Container();
        $controller = new SeoController( $empty );
        $this->assertFalse( $empty->has( SiteAuditor::class ) );
        $this->assertTrue( Container::plugin()->container()->has( SiteAuditor::class ), 'the plugin registers the SEO services' );
        $this->assertFalse( $controller->auditStatus( new WP_REST_Request( 'GET', '/ppclg/v1/seo/audit' ) )['available'] );
        $run = $controller->auditRun( new WP_REST_Request( 'POST', '/ppclg/v1/seo/audit/run' ) );
        $this->assertFalse( $run['ok'] );
        $this->assertSame( 'The site audit is not available.', $run['message'] );
        $this->assertFalse( $controller->indexnow( new WP_REST_Request( 'GET', '/ppclg/v1/seo/indexnow' ) )['available'] );
        $this->assertFalse( $controller->links( new WP_REST_Request( 'GET', '/ppclg/v1/seo/links' ) )['available'] );
    }

    public function testAuditAndIndexNowEndpoints(): void {
        $c = Container::plugin()->container();
        Container::get( Settings::class )->set( 'seo.audit_http_sample', false );
        $c->set( SiteAuditor::class, new SiteAuditor( Container::get( Settings::class ), Container::get( Logger::class ), Container::get( Locks::class ), null ) );
        $c->set( IndexNow::class, new IndexNow( Container::get( Settings::class ), Container::get( Logger::class ), Container::get( Client::class ), Container::get( Locks::class ), Container::get( Audit::class ), static function (): void {} ) );
        StubsSeo::addPost( 1, [ 'post_type' => 'page', 'post_title' => 'Short' ] );
        $controller = new SeoController( $c );

        $run = $controller->auditRun( new WP_REST_Request( 'POST', '/ppclg/v1/seo/audit/run' ) );
        $this->assertTrue( $run['ok'] );
        $this->assertTrue( $run['done'] );
        $this->assertStringStartsWith( 'Audited 1 page;', $run['message'] );

        $audit = $controller->auditStatus( new WP_REST_Request( 'GET', '/ppclg/v1/seo/audit' ) );
        $this->assertTrue( $audit['available'] );
        $this->assertTrue( $audit['summary']['complete'] );
        $this->assertSame( 1, $audit['result']['pages'] );
        $this->assertNotEmpty( $audit['issues'] );
        $this->assertSame( [ 'post_id', 'url', 'title', 'severity', 'code', 'message', 'fix', 'edit_url' ], array_keys( $audit['issues'][0] ) );

        $status = $controller->indexnow( new WP_REST_Request( 'GET', '/ppclg/v1/seo/indexnow' ) );
        $this->assertTrue( $status['available'] );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $status['status']['key'] );
        $this->assertSame( [], $status['history'] );

        Container::get( Settings::class )->set( 'seo.indexnow', false );
        $refused = $controller->indexnowRun( new WP_REST_Request( 'POST', '/ppclg/v1/seo/indexnow/run' ) );
        $this->assertFalse( $refused['ok'] );
        $this->assertTrue( $refused['skipped'] );
        $this->assertSame( 'IndexNow is turned off in Settings.', $refused['message'] );
    }
}
