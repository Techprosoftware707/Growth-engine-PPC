<?php
/**
 * 1.1.0 integration wiring: the new services, job types, REST controllers, SPA page, settings sections, pipeline
 * cadences, CSS and hooks are registered; POST /settings cannot write the storefront acknowledgement or the
 * IndexNow key and asks for confirmation before the storefront gate is turned off; reading the IndexNow status
 * writes nothing; automatic outreach drafting skips prospects imported from the verified opportunity database.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Wiring;

use PPC\LinkGrowth\Admin\Menu;
use PPC\LinkGrowth\Admin\Rest\ComplianceController;
use PPC\LinkGrowth\Admin\Rest\GrowthController;
use PPC\LinkGrowth\Admin\Rest\RestApi;
use PPC\LinkGrowth\Admin\Rest\SeoController;
use PPC\LinkGrowth\Admin\Rest\SettingsController;
use PPC\LinkGrowth\Automation\JobRunner;
use PPC\LinkGrowth\Automation\Jobs\ImportVerifiedJob;
use PPC\LinkGrowth\Automation\Jobs\IndexNowJob;
use PPC\LinkGrowth\Automation\Jobs\InternalLinksJob;
use PPC\LinkGrowth\Automation\Jobs\SiteAuditJob;
use PPC\LinkGrowth\Automation\Jobs\StorefrontAuditJob;
use PPC\LinkGrowth\Automation\Pipeline;
use PPC\LinkGrowth\Compliance\StorefrontAudit;
use PPC\LinkGrowth\Database\Schema;
use PPC\LinkGrowth\Discovery\VerifiedOpportunities;
use PPC\LinkGrowth\Growth\GrowthRun;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Seo\IndexNow;
use PPC\LinkGrowth\Seo\InternalLinker;
use PPC\LinkGrowth\Seo\SiteAuditor;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_Error;
use WP_REST_Request;

final class ReleaseWiringTest extends TestCase {

    private const ROOT = __DIR__ . '/../../../ppc-link-growth-engine/';

    private mixed $previousCaps = null;

    protected function setUp(): void {
        parent::setUp();
        $this->previousCaps            = $GLOBALS['ppclg_test_caps'] ?? null;
        $GLOBALS['ppclg_test_caps']    = [ 'manage_options' => true ];
        $GLOBALS['ppclg_test_user_id'] = 1;
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

    private function settings(): Settings {
        return Container::get( Settings::class );
    }

    /** @param array<string,mixed> $params @return array<string,mixed>|WP_Error */
    private function saveSettings( array $params ): array|WP_Error {
        $r = new WP_REST_Request( 'POST', '/ppclg/v1/settings' );
        foreach ( $params as $k => $v ) {
            $r->set_param( $k, $v );
        }
        return ( new SettingsController( Container::plugin()->container() ) )->update( $r );
    }

    public function testServicesJobsControllersPagesAndSettingsAreRegistered(): void {
        $c = Container::plugin()->container();
        foreach ( [ StorefrontAudit::class, SiteAuditor::class, IndexNow::class, InternalLinker::class, VerifiedOpportunities::class, GrowthRun::class ] as $class ) {
            $this->assertTrue( $c->has( $class ), $class . ' has a factory' );
            $this->assertInstanceOf( $class, $c->get( $class ) );
        }

        $types = Container::get( JobRunner::class )->types();
        $this->assertSame(
            [ StorefrontAuditJob::class, ImportVerifiedJob::class, SiteAuditJob::class, IndexNowJob::class, InternalLinksJob::class ],
            [ $types['storefront_audit'] ?? null, $types['import_verified'] ?? null, $types['site_audit'] ?? null, $types['indexnow'] ?? null, $types['internal_links'] ?? null ]
        );

        $controllers = ( new RestApi( $c ) )->controllers();
        foreach ( [ ComplianceController::class, SeoController::class, GrowthController::class ] as $class ) {
            $this->assertContains( $class, $controllers );
        }

        $this->assertContains( 'seo', Menu::PAGE_KEYS );
        $this->assertSame( 'On-site SEO', Menu::pages()['seo'] );
        $this->assertSame( Menu::PAGE_KEYS, array_keys( Menu::pages() ), 'menu labels follow the navigation order' );
        $app = (string) file_get_contents( self::ROOT . 'assets/js/app.js' );
        $this->assertStringContainsString( "seo: () => import( './pages/seo.js' ),", $app );
        $this->assertStringContainsString( "seo: 'search',", $app );
        $this->assertStringContainsString( "seo: 'On-site SEO',", $app );

        $stages = Container::get( Pipeline::class )->status()['stages'];
        foreach ( [ 'storefront_audit' => 'daily', 'site_audit' => 'daily', 'indexnow' => 'hourly', 'internal_links' => 'weekly', 'import_verified' => 'when the bundled verified opportunity data changes' ] as $type => $cadence ) {
            $this->assertArrayHasKey( $type, $stages );
            $this->assertStringStartsWith( $cadence, $stages[ $type ]['cadence'], $type );
            $this->assertSame( '', $stages[ $type ]['flag'], $type );
        }

        $defaults = Settings::defaults();
        $this->assertSame( [ 'indexnow' => true, 'indexnow_key' => '', 'audit_max_pages' => 400, 'audit_http_sample' => true, 'internal_links' => true, 'max_links_per_post' => 3 ], $defaults['seo'] );
        $this->assertSame( [ 'storefront_gate' => true, 'acknowledged_hash' => '', 'acknowledged_by' => 0, 'acknowledged_at' => '' ], $defaults['compliance'] );
        $this->assertSame( [ 'evaluate_rounds' => 6, 'step_seconds' => 20 ], $defaults['growth'] );

        $main = (string) file_get_contents( self::ROOT . 'ppc-link-growth-engine.php' );
        $this->assertMatchesRegularExpression( '/\*\s+Version:\s+1\.1\.0\b/', $main );
        $this->assertStringContainsString( "define( 'PPCLG_VERSION', '1.1.0' );", $main );
        $this->assertStringContainsString( 'Stable tag: 1.1.0', (string) file_get_contents( self::ROOT . 'readme.txt' ) );

        $css = (string) file_get_contents( self::ROOT . 'assets/css/admin.css' );
        foreach ( [ '.ppclg-grow-step--blocked .ppclg-grow-step-icon', '.ppclg-compliance-finding', '.ppclg-owner-steps', '.ppclg-wrap mark' ] as $selector ) {
            $this->assertStringContainsString( $selector . ' {', $css, $selector );
        }
        $this->assertSame( 1, substr_count( $css, '.ppclg-compliance-host:empty {' ), 'the shared rule is defined once' );
    }

    public function testBootHooksTheStorefrontAuditAndIndexNow(): void {
        Container::plugin()->boot();
        $actions = $GLOBALS['ppclg_test_actions'];
        foreach ( [ 'parse_request', 'transition_post_status', 'save_post', 'save_post_product', 'created_product_cat', 'edited_product_cat', 'delete_product_cat', 'created_product_tag', 'edited_product_tag', 'delete_product_tag', 'wp_update_nav_menu' ] as $hook ) {
            $this->assertNotEmpty( $actions[ $hook ] ?? [], $hook . ' is hooked' );
        }

        // A product category change marks the storefront check stale.
        delete_option( StorefrontAudit::STALE_OPTION );
        do_action( 'edited_product_cat' );
        $this->assertNotFalse( get_option( StorefrontAudit::STALE_OPTION, false ) );

        // Requests that are not a key file pass through untouched (no output, no exit).
        $_SERVER['REQUEST_URI'] = '/shop/';
        ob_start();
        Container::plugin()->maybeServeIndexNowKey( null );
        $this->assertSame( '', ob_get_clean() );
        unset( $_SERVER['REQUEST_URI'] );
    }

    public function testSettingsCannotWriteTheAcknowledgementOrTheIndexNowKey(): void {
        $res = $this->saveSettings(
            [
                'settings' => [
                    'compliance' => [ 'acknowledged_hash' => str_repeat( 'a', 40 ), 'acknowledged_by' => 7, 'acknowledged_at' => '2026-01-01T00:00:00+00:00' ],
                    'seo'        => [ 'indexnow_key' => 'deadbeefdeadbeefdeadbeefdeadbeef', 'max_links_per_post' => 99, 'audit_max_pages' => 1 ],
                    'growth'     => [ 'evaluate_rounds' => 500, 'step_seconds' => 2 ],
                ],
            ]
        );
        $this->assertIsArray( $res );
        $this->assertTrue( $res['ok'] );
        $this->assertSame( '', $this->settings()->get( 'compliance.acknowledged_hash' ) );
        $this->assertSame( 0, $this->settings()->get( 'compliance.acknowledged_by' ) );
        $this->assertSame( '', $this->settings()->get( 'compliance.acknowledged_at' ) );
        $this->assertSame( '', $this->settings()->get( 'seo.indexnow_key' ) );
        $this->assertSame( 10, $this->settings()->get( 'seo.max_links_per_post' ), 'clamped to 1..10' );
        $this->assertSame( 20, $this->settings()->get( 'seo.audit_max_pages' ), 'clamped to 20..5000' );
        $this->assertSame( 20, $this->settings()->get( 'growth.evaluate_rounds' ), 'clamped to 1..20' );
        $this->assertSame( 8, $this->settings()->get( 'growth.step_seconds' ), 'clamped to 8..25' );
        $this->assertNotContains( 'compliance.acknowledged_hash', $res['changed'] );
    }

    public function testTurningTheStorefrontGateOffNeedsConfirmation(): void {
        $refused = $this->saveSettings( [ 'settings' => [ 'compliance' => [ 'storefront_gate' => false ] ] ] );
        $this->assertInstanceOf( WP_Error::class, $refused );
        $this->assertSame( 'ppclg_confirmation_required', $refused->get_error_code() );
        $this->assertContains( 'storefront_gate_off', (array) ( $refused->get_error_data()['keys'] ?? [] ) );
        $this->assertStringContainsString( 'your legal decision', $refused->get_error_message() );
        $this->assertTrue( $this->settings()->get( 'compliance.storefront_gate' ), 'nothing was saved' );

        $ok = $this->saveSettings( [ 'settings' => [ 'compliance' => [ 'storefront_gate' => false ] ], 'confirm' => true ] );
        $this->assertIsArray( $ok );
        $this->assertFalse( $this->settings()->get( 'compliance.storefront_gate' ) );
        $entry = Container::get( AuditLog::class )->findOneBy( [ 'action' => 'settings.updated' ] );
        $this->assertNotNull( $entry );
        $details = is_array( $entry['details'] ?? null ) ? $entry['details'] : (array) json_decode( (string) ( $entry['details'] ?? '' ), true );
        $this->assertContains( 'compliance.storefront_gate', (array) ( $details['changed'] ?? [] ) );
        $this->assertContains( 'storefront_gate_off', (array) ( $details['confirmed'] ?? [] ) );

        // Turning it back on needs no confirmation.
        $on = $this->saveSettings( [ 'settings' => [ 'compliance' => [ 'storefront_gate' => true ] ] ] );
        $this->assertIsArray( $on );
        $this->assertTrue( $this->settings()->get( 'compliance.storefront_gate' ) );
    }

    public function testReadingTheIndexNowStatusWritesNothing(): void {
        $status = Container::get( IndexNow::class )->status();
        $this->assertSame( '', $status['key'] );
        $this->assertSame( '', $status['key_url'] );
        $this->assertSame( '', $this->settings()->get( 'seo.indexnow_key' ), 'status() never generates the key' );
        $this->assertSame( [], get_option( Settings::OPTION, [] ), 'no settings were stored' );
    }

    public function testAutomaticDraftingSkipsVerifiedDatabaseProspects(): void {
        $db  = $GLOBALS['wpdb'];
        $now = gmdate( 'Y-m-d H:i:s' );
        $ids = [];
        foreach ( [ 'verified-resources.test' => VerifiedOpportunities::SOURCE, 'found-resources.test' => 'search' ] as $domain => $via ) {
            $db->insert( Schema::table( 'prospects' ), [ 'domain' => $domain, 'name' => $domain, 'status' => Prospects::STATUS_QUALIFIED, 'opportunity_type' => 'resource_page', 'score' => 90, 'discovered_via' => $via, 'first_seen' => $now, 'updated_at' => $now ] );
            $ids[ $domain ] = (int) $db->insert_id;
            $db->insert( Schema::table( 'contacts' ), [ 'prospect_id' => $ids[ $domain ], 'email' => 'editor@' . $domain, 'name' => 'Ed', 'role' => 'editor', 'confidence' => 80, 'consent_basis' => 'conspicuous_publication', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] );
        }
        $this->settings()->update(
            [
                'outreach'   => [ 'sender_name' => 'Jane Doe', 'sender_email' => 'jane@purepeptidescanada.test', 'postal_address' => '222 Jarvis St, Toronto, ON M5B 2B8, Canada' ],
                'automation' => [ 'auto_outreach_drafts' => true ],
            ]
        );

        $result   = Container::get( JobRunner::class )->runType( 'auto_outreach', [ 'manual' => true ], TimeBudget::seconds( 60.0 ) );
        $messages = Container::get( OutreachMessages::class );
        $this->assertSame( 1, $messages->count( [ 'prospect_id' => $ids['found-resources.test'] ] ), (string) json_encode( $result ) );
        $this->assertSame( 0, $messages->count( [ 'prospect_id' => $ids['verified-resources.test'] ] ), 'a verified-database prospect is an owner task: never auto-drafted' );
    }
}
