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
use PPC\LinkGrowth\Seo\RenderCheck;
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
        foreach ( [ StorefrontAudit::class, SiteAuditor::class, IndexNow::class, RenderCheck::class, InternalLinker::class, VerifiedOpportunities::class, GrowthRun::class ] as $class ) {
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
        $this->assertSame( [ 'indexnow' => true, 'indexnow_key' => '', 'audit_max_pages' => 400, 'audit_http_sample' => true, 'internal_links' => true, 'max_links_per_post' => 3, 'auto_apply_links' => false, 'auto_apply_per_day' => 20 ], $defaults['seo'] );
        $this->assertSame( [ 'storefront_gate' => true, 'acknowledged_hash' => '', 'acknowledged_by' => 0, 'acknowledged_at' => '' ], $defaults['compliance'] );
        $this->assertSame( [ 'evaluate_rounds' => 6, 'step_seconds' => 20 ], $defaults['growth'] );

        $main = (string) file_get_contents( self::ROOT . 'ppc-link-growth-engine.php' );
        $this->assertMatchesRegularExpression( '/\*\s+Version:\s+1\.2\.4\b/', $main );
        $this->assertStringContainsString( "define( 'PPCLG_VERSION', '1.2.4' );", $main );
        $readme = (string) file_get_contents( self::ROOT . 'readme.txt' );
        $this->assertStringContainsString( 'Stable tag: 1.2.4', $readme );
        $this->assertStringContainsString( "== Changelog ==\n\n= 1.2.4 =\n", $readme, 'the changelog leads with this version' );
        // 1.2.4: the changelog names the pauses, the plain dashboard text, both filters and the exact OPcache remedy.
        $latest = (string) strstr( (string) strstr( $readme, "= 1.2.4 =\n" ), "= 1.2.3 =\n", true );
        foreach ( [ 'Paused: a backup is running (resumes automatically).', 'ppclg_backup_running', 'ppclg_pause_background', '10 minutes after the plugin is updated or activated', "PHP's code cache (OPcache) is full", 'opcache.memory_consumption=256', 'opcache.interned_strings_buffer=32', 'opcache.max_accelerated_files=32531', 'restart PHP-FPM', 'ask your host', 'A full cache alone never pauses anything', 'nothing is lost, only delayed' ] as $needle ) {
            $this->assertStringContainsString( $needle, $latest, $needle );
        }
        // 1.2.3: the changelog states both internal-link rules and the notes on closed suggestions.
        $entry = (string) strstr( (string) strstr( $readme, "= 1.2.3 =\n" ), "= 1.2.2 =\n", true );
        foreach ( [ 'New rule, brand name', 'New rule, language', 'Not suggested: the link text is your brand name.', 'Not suggested: the target page is in another language.' ] as $needle ) {
            $this->assertStringContainsString( $needle, $entry, $needle );
        }
        // The brand and language rules are services of the Seo slice, loaded by the autoloader.
        foreach ( [ \PPC\LinkGrowth\Seo\BrandTerms::class, \PPC\LinkGrowth\Seo\PostLanguage::class ] as $class ) {
            $this->assertTrue( class_exists( $class ), $class );
        }
        $this->assertSame( 'ppclg_post_language', \PPC\LinkGrowth\Seo\PostLanguage::FILTER );
        $this->assertSame( 'Not suggested: the link text is your brand name.', InternalLinker::BRAND_NOTE );
        $this->assertSame( 'Not suggested: the target page is in another language.', InternalLinker::LANGUAGE_NOTE );

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

    public function testServerPressureServicesHooksAndDocsAreWired(): void {
        $c = Container::plugin()->container();
        foreach ( [ \PPC\LinkGrowth\Automation\LoadGuard::class, \PPC\LinkGrowth\Support\Opcache::class, \PPC\LinkGrowth\Admin\SiteHealth::class ] as $class ) {
            $this->assertTrue( $c->has( $class ), $class . ' has a factory' );
            $this->assertInstanceOf( $class, $c->get( $class ) );
        }
        $pipeline = $c->get( Pipeline::class );
        $this->assertSame( $c->get( \PPC\LinkGrowth\Automation\LoadGuard::class ), $pipeline->loadGuard(), 'one guard per request' );
        $this->assertArrayHasKey( 'paused', $pipeline->status() );

        $this->assertSame( 'ppclg_running_version', \PPC\LinkGrowth\Automation\LoadGuard::OPTION_VERSION );
        $this->assertSame( 'ppclg_background_pause', \PPC\LinkGrowth\Automation\LoadGuard::OPTION_PAUSE, 'ppclg_ prefix: removed on uninstall' );
        $this->assertSame( 600, \PPC\LinkGrowth\Automation\LoadGuard::UPDATE_QUIET_SECONDS );
        $this->assertSame( 1800, \PPC\LinkGrowth\Automation\LoadGuard::BACKUP_LOOKAHEAD_SECONDS );
        $this->assertSame( 'updraft_backup_resume', \PPC\LinkGrowth\Automation\LoadGuard::UPDRAFT_RESUME_HOOK );
        $this->assertSame( [ 'opcache.memory_consumption' => '256', 'opcache.interned_strings_buffer' => '32', 'opcache.max_accelerated_files' => '32531' ], \PPC\LinkGrowth\Support\Opcache::RECOMMENDED_SETTINGS );

        // Boot registers the Site Health test on every request (the weekly check runs in cron) and records the version.
        delete_option( \PPC\LinkGrowth\Automation\LoadGuard::OPTION_VERSION );
        Container::plugin()->boot();
        $this->assertNotEmpty( $GLOBALS['ppclg_test_filters']['site_status_tests'] ?? [], 'site_status_tests is hooked' );
        $this->assertSame( PPCLG_VERSION, get_option( \PPC\LinkGrowth\Automation\LoadGuard::OPTION_VERSION )['version'] ?? null, 'the first request on a new version starts the quiet window' );

        // Activation starts the window too.
        $activator = (string) file_get_contents( self::ROOT . 'includes/Activator.php' );
        $this->assertStringContainsString( 'LoadGuard::class )->recordActivation()', $activator );

        $css = (string) file_get_contents( self::ROOT . 'assets/css/admin.css' );
        $this->assertStringContainsString( '.ppclg-paused-line {', $css );
        $this->assertFileExists( self::ROOT . 'assets/js/server-pressure.js' );

        $ops = (string) file_get_contents( self::ROOT . '../docs/OPERATIONS.md' );
        $this->assertStringContainsString( '## Server pressure', $ops );
        foreach ( [ 'updraft_backup_resume', 'ppclg_pause_background', 'ppclg_backup_running', 'opcache.memory_consumption=256', 'opcache.interned_strings_buffer=32', 'opcache.max_accelerated_files=32531' ] as $needle ) {
            $this->assertStringContainsString( $needle, $ops, $needle );
        }
        $testing = (string) file_get_contents( self::ROOT . '../docs/TESTING.md' );
        $this->assertStringContainsString( 'tests/Unit/Automation/LoadGuardTest.php', $testing );
        $this->assertStringContainsString( 'tests/integration/server-pressure.php', $testing );
        $run = (string) file_get_contents( self::ROOT . '../tests/integration/run.php' );
        $this->assertStringContainsString( "require __DIR__ . '/server-pressure.php';", $run );
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
