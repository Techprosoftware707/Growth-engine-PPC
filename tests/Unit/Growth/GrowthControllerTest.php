<?php
/**
 * Admin\Rest\GrowthController: admin-only routes, honest ok/message on every write, step budget clamping.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Growth;

use PPC\LinkGrowth\Admin\Rest\GrowthController;
use PPC\LinkGrowth\Compliance\StorefrontAudit;
use PPC\LinkGrowth\Growth\GrowthRun;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/GrowthFakes.php';

final class GrowthControllerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        GrowthFakeJob::reset();
        $GLOBALS['ppclg_test_filters']['ppclg_job_types'] = [ static fn() => GrowthFakeJob::registry() ];
        Container::plugin()->container()->set( StorefrontAudit::class, new FakeStorefront() );
        $GLOBALS['ppclg_test_user_id'] = 1;
        $GLOBALS['ppclg_test_caps']    = [ 'manage_options' => true ];
        $GLOBALS['ppclg_test_routes']  = [];
    }

    protected function tearDown(): void {
        $GLOBALS['ppclg_test_user_id'] = 0;
        $GLOBALS['ppclg_test_caps']    = [];
        parent::tearDown();
    }

    private function controller(): GrowthController {
        return new GrowthController( Container::plugin()->container() );
    }

    /** Dispatches through the registered route (permission callback + wrapper), like WordPress does. */
    private function dispatch( string $method, string $route ): mixed {
        $handler = $GLOBALS['ppclg_test_routes'][ 'ppclg/v1' . $route ][0][0] ?? null;
        $this->assertIsArray( $handler, 'route registered: ' . $route );
        $this->assertStringContainsString( $method, (string) $handler['methods'] );
        $request = new WP_REST_Request( $method, '/ppclg/v1' . $route );
        $allowed = ( $handler['permission_callback'] )( $request );
        if ( true !== $allowed ) {
            return $allowed;
        }
        $response = ( $handler['callback'] )( $request );
        return $response instanceof WP_REST_Response ? $response->get_data() : $response;
    }

    public function testRoutesAreAdminOnly(): void {
        $this->controller()->register( 'ppclg/v1' );
        foreach ( [ '/growth/start', '/growth/step', '/growth/status', '/growth/cancel' ] as $route ) {
            $handler = $GLOBALS['ppclg_test_routes'][ 'ppclg/v1' . $route ][0][0] ?? null;
            $this->assertIsArray( $handler, $route );
            $this->assertNotSame( '__return_true', $handler['permission_callback'], $route . ' is not public' );
        }
        $GLOBALS['ppclg_test_caps'] = [];
        $denied = $this->dispatch( 'POST', '/growth/start' );
        $this->assertInstanceOf( WP_Error::class, $denied );
        $this->assertSame( 'ppclg_forbidden', $denied->get_error_code() );
        $this->assertFalse( Container::get( GrowthRun::class )->isActive(), 'nothing started without the capability' );
    }

    public function testStartStepStatusAndCancelReportHonestly(): void {
        $this->controller()->register( 'ppclg/v1' );

        $idle = $this->dispatch( 'GET', '/growth/status' );
        $this->assertSame( 'idle', $idle['status'] );
        $this->assertSame( 20, $idle['step_seconds'] );

        $step = $this->dispatch( 'POST', '/growth/step' );
        $this->assertFalse( $step['ok'], 'stepping without a run is refused' );
        $this->assertSame( 'No growth run has been started yet.', $step['message'] );

        $cancel = $this->dispatch( 'POST', '/growth/cancel' );
        $this->assertFalse( $cancel['ok'] );
        $this->assertSame( 'No growth run is in progress.', $cancel['message'] );

        $start = $this->dispatch( 'POST', '/growth/start' );
        $this->assertTrue( $start['ok'] );
        $this->assertSame( 'Growth run started.', $start['message'] );
        $this->assertFalse( $start['already_running'] );
        $this->assertSame( 1, $start['started_by'] );

        $again = $this->dispatch( 'POST', '/growth/start' );
        $this->assertTrue( $again['ok'] );
        $this->assertTrue( $again['already_running'] );
        $this->assertSame( $start['id'], $again['id'] );

        $stopped = $this->dispatch( 'POST', '/growth/cancel' );
        $this->assertTrue( $stopped['ok'] );
        $this->assertSame( 'cancelled', $stopped['status'] );
        $this->assertStringContainsString( 'Work already done is kept', $stopped['message'] );

        $after = $this->dispatch( 'POST', '/growth/step' );
        $this->assertFalse( $after['ok'] );
        $this->assertSame( 'This growth run is no longer running.', $after['message'] );
    }

    public function testStepRunsTheRunToCompletion(): void {
        $this->controller()->register( 'ppclg/v1' );
        $this->dispatch( 'POST', '/growth/start' );
        $state = [];
        for ( $n = 0; $n < 10; $n++ ) {
            $state = $this->dispatch( 'POST', '/growth/step' );
            $this->assertTrue( $state['ok'] );
            if ( 'running' !== $state['status'] ) {
                break;
            }
        }
        $this->assertSame( 'completed', $state['status'] );
        $this->assertSame( $state['progress']['total'], $state['progress']['done'] );
        $this->assertIsArray( $state['next_actions'] );
        $this->assertIsArray( $state['summary'] );
    }

    public function testStepSecondsAreClamped(): void {
        $this->controller()->register( 'ppclg/v1' );
        $settings = Container::get( Settings::class );
        $settings->set( 'growth.step_seconds', 300 );
        $this->assertSame( GrowthController::MAX_STEP_SECONDS, $this->dispatch( 'GET', '/growth/status' )['step_seconds'] );
        $settings->set( 'growth.step_seconds', 2 );
        $this->assertSame( GrowthController::MIN_STEP_SECONDS, $this->dispatch( 'GET', '/growth/status' )['step_seconds'] );
        $settings->set( 'growth.step_seconds', 0 );
        $this->assertSame( GrowthController::DEFAULT_STEP_SECONDS, $this->dispatch( 'GET', '/growth/status' )['step_seconds'] );
    }
}
