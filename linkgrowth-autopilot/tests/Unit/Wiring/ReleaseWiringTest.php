<?php
/**
 * Release and integration wiring: the services, job types, REST controllers, SPA page, settings sections, pipeline
 * cadences, CSS and hooks are registered; the plugin header, LGA_VERSION and the readme's Stable tag and first
 * changelog entry name the same version, whatever it is (DESIGN.md §1.6), and the header carries the identity of
 * §1.1; POST /settings cannot write the storefront acknowledgement or the IndexNow key and asks for confirmation
 * before the storefront gate is turned off; reading the IndexNow status writes nothing; automatic outreach drafting
 * skips prospects imported from the opportunity catalog.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Wiring;

use LinkGrowth\Autopilot\Admin\Menu;
use LinkGrowth\Autopilot\Admin\Rest\ComplianceController;
use LinkGrowth\Autopilot\Admin\Rest\GrowthController;
use LinkGrowth\Autopilot\Admin\Rest\RestApi;
use LinkGrowth\Autopilot\Admin\Rest\SeoController;
use LinkGrowth\Autopilot\Admin\Rest\SettingsController;
use LinkGrowth\Autopilot\Automation\JobRunner;
use LinkGrowth\Autopilot\Automation\Jobs\CatalogImportJob;
use LinkGrowth\Autopilot\Automation\Jobs\IndexNowJob;
use LinkGrowth\Autopilot\Automation\Jobs\InternalLinksJob;
use LinkGrowth\Autopilot\Automation\Jobs\SiteAuditJob;
use LinkGrowth\Autopilot\Automation\Jobs\PositioningAuditJob;
use LinkGrowth\Autopilot\Automation\Pipeline;
use LinkGrowth\Autopilot\Compliance\PositioningGate;
use LinkGrowth\Autopilot\Database\Schema;
use LinkGrowth\Autopilot\Discovery\CatalogImporter;
use LinkGrowth\Autopilot\Growth\GrowthRun;
use LinkGrowth\Autopilot\Repositories\AuditLog;
use LinkGrowth\Autopilot\Repositories\OutreachMessages;
use LinkGrowth\Autopilot\Repositories\Prospects;
use LinkGrowth\Autopilot\Seo\IndexNow;
use LinkGrowth\Autopilot\Seo\InternalLinker;
use LinkGrowth\Autopilot\Seo\RenderCheck;
use LinkGrowth\Autopilot\Seo\SiteAuditor;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\TimeBudget;
use LinkGrowth\Autopilot\Tests\Support\Container;
use LinkGrowth\Autopilot\Tests\Support\TestCase;
use LinkGrowth\Autopilot\Tests\Support\Wp2Modules;
use WP_Error;
use WP_REST_Request;

final class ReleaseWiringTest extends TestCase {

    private const ROOT = __DIR__ . '/../../../';

    private mixed $previousCaps = null;

    protected function setUp(): void {
        parent::setUp();
        $this->previousCaps            = $GLOBALS['lga_test_caps'] ?? null;
        $GLOBALS['lga_test_caps']    = [ 'manage_options' => true ];
        $GLOBALS['lga_test_user_id'] = 1;
    }

    protected function tearDown(): void {
        if ( null === $this->previousCaps ) {
            unset( $GLOBALS['lga_test_caps'] );
        } else {
            $GLOBALS['lga_test_caps'] = $this->previousCaps;
        }
        $GLOBALS['lga_test_user_id'] = 0;
        parent::tearDown();
    }

    private function settings(): Settings {
        return Container::get( Settings::class );
    }

    /** @param array<string,mixed> $params @return array<string,mixed>|WP_Error */
    private function saveSettings( array $params ): array|WP_Error {
        $r = new WP_REST_Request( 'POST', '/lga/v1/settings' );
        foreach ( $params as $k => $v ) {
            $r->set_param( $k, $v );
        }
        return ( new SettingsController( Container::plugin()->container() ) )->update( $r );
    }

    public function testServicesJobsControllersPagesAndSettingsAreRegistered(): void {
        $c = Container::plugin()->container();
        foreach ( [ PositioningGate::class, SiteAuditor::class, IndexNow::class, RenderCheck::class, InternalLinker::class, CatalogImporter::class, GrowthRun::class ] as $class ) {
            $this->assertTrue( $c->has( $class ), $class . ' has a factory' );
            $this->assertInstanceOf( $class, $c->get( $class ) );
        }

        $types = Container::get( JobRunner::class )->types();
        $this->assertSame(
            [ PositioningAuditJob::class, CatalogImportJob::class, SiteAuditJob::class, IndexNowJob::class, InternalLinksJob::class ],
            [ $types['storefront_audit'] ?? null, $types['import_verified'] ?? null, $types['site_audit'] ?? null, $types['indexnow'] ?? null, $types['internal_links'] ?? null ]
        );

        $controllers = ( new RestApi( $c ) )->controllers();
        foreach ( [ ComplianceController::class, SeoController::class, GrowthController::class ] as $class ) {
            $this->assertContains( $class, $controllers );
        }

        // WP2: the pages come from the modules (Menu::PAGE_KEYS is gone); the navigation order is unchanged.
        $this->assertArrayHasKey( 'seo', Menu::pages() );
        $this->assertSame( 'On-site SEO', Menu::pages()['seo'] );
        // The pages of the WP2 modules (Menu::PAGE_KEYS before WP2), among those later packages' modules add.
        $this->assertSame( Wp2Modules::PAGES, Wp2Modules::only( array_keys( Menu::pages() ), Wp2Modules::PAGES ), 'menu labels follow the navigation order' );
        // WP3: the SPA's router and navigation come from lga-config.pages (Menu::spaPages()), not from a list in
        // app.js: the SEO page is routed to its module with its icon and label from the module's adminPages().
        $this->assertSame( [ 'label' => 'On-site SEO', 'icon' => 'search', 'module' => 'pages/seo.js', 'cap' => 'lga_manage', 'order' => 60 ], Menu::pageDefinitions()['seo'] );
        $this->assertFileExists( self::ROOT . 'assets/js/pages/seo.js' );
        $app = (string) file_get_contents( self::ROOT . 'assets/js/app.js' );
        $this->assertStringContainsString( 'module = await loadModule( def ? def.module : \'\' );', $app );
        $this->assertStringNotContainsString( "import( './pages/", $app, 'no page list hard-coded in app.js' );

        $stages = Container::get( Pipeline::class )->status()['stages'];
        foreach ( [ 'storefront_audit' => 'daily', 'site_audit' => 'daily', 'indexnow' => 'hourly', 'internal_links' => 'weekly', 'import_verified' => 'when the bundled verified opportunity data changes' ] as $type => $cadence ) {
            $this->assertArrayHasKey( $type, $stages );
            $this->assertStringStartsWith( $cadence, $stages[ $type ]['cadence'], $type );
            $this->assertSame( '', $stages[ $type ]['flag'], $type );
        }

        $defaults = Settings::defaults();
        $this->assertSame( [ 'indexnow' => true, 'indexnow_key' => '', 'audit_max_pages' => 400, 'audit_http_sample' => true, 'internal_links' => true, 'max_links_per_post' => 3, 'auto_apply_links' => false, 'auto_apply_per_day' => 20, 'language_counterpart_meta_key' => '' ], $defaults['seo'] );
        $this->assertSame( [ 'storefront_gate' => true, 'acknowledged_hash' => '', 'acknowledged_by' => 0, 'acknowledged_at' => '' ], $defaults['compliance'] );
        $this->assertSame( [ 'evaluate_rounds' => 6, 'step_seconds' => 20 ], $defaults['growth'] );

        // The brand and language rules are services of the Seo slice, loaded by the autoloader.
        foreach ( [ \LinkGrowth\Autopilot\Seo\BrandTerms::class, \LinkGrowth\Autopilot\Seo\PostLanguage::class ] as $class ) {
            $this->assertTrue( class_exists( $class ), $class );
        }
        $this->assertSame( 'lga_post_language', \LinkGrowth\Autopilot\Seo\PostLanguage::FILTER );
        $this->assertSame( 'Not suggested: the link text is your brand name.', InternalLinker::BRAND_NOTE );
        $this->assertSame( 'Not suggested: the target page is in another language.', InternalLinker::LANGUAGE_NOTE );

        $css = (string) file_get_contents( self::ROOT . 'assets/css/admin.css' );
        foreach ( [ '.lga-grow-step--blocked .lga-grow-step-icon', '.lga-compliance-finding', '.lga-owner-steps', '.lga-wrap mark' ] as $selector ) {
            $this->assertStringContainsString( $selector . ' {', $css, $selector );
        }
        $this->assertSame( 1, substr_count( $css, '.lga-compliance-host:empty {' ), 'the shared rule is defined once' );
    }

    public function testHeaderVersionConstantStableTagAndChangelogAgree(): void {
        $main   = (string) file_get_contents( self::ROOT . 'linkgrowth-autopilot.php' );
        $readme = (string) file_get_contents( self::ROOT . 'readme.txt' );
        $this->assertSame( 1, preg_match( '/^\s*\*\s+Version:\s+(\S+)\s*$/m', $main, $header ), 'the header has a Version line' );
        $this->assertSame( 1, preg_match( "/define\(\s*'LGA_VERSION',\s*'([^']+)'\s*\);/", $main, $constant ) );
        $this->assertSame( 1, preg_match( '/^Stable tag:\s*(\S+)\s*$/m', $readme, $stable ) );
        $this->assertSame( 1, preg_match( "/== Changelog ==\n\n= ([^ ]+) =\n/", $readme, $changelog ), 'the changelog leads with an entry' );
        $version = $header[1];
        $this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $version );
        $this->assertSame( $version, $constant[1], 'LGA_VERSION matches the header' );
        $this->assertSame( $version, $stable[1], 'Stable tag matches the header' );
        $this->assertSame( $version, $changelog[1], 'the changelog leads with this version' );

        // The plugin identity of DESIGN.md §1.1: neutral author, no store address, the declared minimums.
        foreach ( [ 'Plugin Name:       LinkGrowth Autopilot', 'Author:            LinkGrowth Autopilot contributors', 'Requires at least: 6.5', 'Requires PHP:      8.1', 'Tested up to:      7.1', 'WC requires at least: 8.0', 'WC tested up to:   11.3', 'Text Domain:       linkgrowth-autopilot', 'Domain Path:       /languages', 'License:           GPL-2.0-or-later' ] as $line ) {
            $this->assertStringContainsString( ' * ' . $line, $main, $line );
        }
        $this->assertStringNotContainsString( 'Plugin URI:', $main );
        $this->assertStringNotContainsString( 'Author URI:', $main );
        $this->assertSame( 'LinkGrowth Autopilot', \LinkGrowth\Autopilot\Plugin::NAME );
        $this->assertStringContainsString( '=== ' . \LinkGrowth\Autopilot\Plugin::NAME . ' ===', $readme );
        $this->assertSame( 1, preg_match( "/define\(\s*'LGA_DB_VERSION',\s*'([^']+)'\s*\);/", $main, $db ) );
        $this->assertTrue( version_compare( $db[1], $version, '<=' ), 'the schema version never runs ahead of the release' );
    }

    public function testBootHooksThePositioningGateAndIndexNow(): void {
        Container::plugin()->boot();
        $actions = $GLOBALS['lga_test_actions'];
        foreach ( [ 'parse_request', 'transition_post_status', 'save_post', 'save_post_product', 'created_product_cat', 'edited_product_cat', 'delete_product_cat', 'created_product_tag', 'edited_product_tag', 'delete_product_tag', 'wp_update_nav_menu' ] as $hook ) {
            $this->assertNotEmpty( $actions[ $hook ] ?? [], $hook . ' is hooked' );
        }

        // A product category change marks the storefront check stale.
        delete_option( PositioningGate::STALE_OPTION );
        do_action( 'edited_product_cat' );
        $this->assertNotFalse( get_option( PositioningGate::STALE_OPTION, false ) );

        // Requests that are not a key file pass through untouched (no output, no exit).
        $_SERVER['REQUEST_URI'] = '/shop/';
        ob_start();
        Container::plugin()->maybeServeIndexNowKey( null );
        $this->assertSame( '', ob_get_clean() );
        unset( $_SERVER['REQUEST_URI'] );
    }

    public function testServerPressureServicesHooksAndDocsAreWired(): void {
        $c = Container::plugin()->container();
        foreach ( [ \LinkGrowth\Autopilot\Automation\LoadGuard::class, \LinkGrowth\Autopilot\Support\Opcache::class, \LinkGrowth\Autopilot\Health\SiteHealthTests::class ] as $class ) {
            $this->assertTrue( $c->has( $class ), $class . ' has a factory' );
            $this->assertInstanceOf( $class, $c->get( $class ) );
        }
        $pipeline = $c->get( Pipeline::class );
        $this->assertSame( $c->get( \LinkGrowth\Autopilot\Automation\LoadGuard::class ), $pipeline->loadGuard(), 'one guard per request' );
        $this->assertArrayHasKey( 'paused', $pipeline->status() );

        $this->assertSame( 'lga_running_version', \LinkGrowth\Autopilot\Automation\LoadGuard::OPTION_VERSION );
        $this->assertSame( 'lga_background_pause', \LinkGrowth\Autopilot\Automation\LoadGuard::OPTION_PAUSE, 'lga_ prefix: removed on uninstall' );
        $this->assertSame( 600, \LinkGrowth\Autopilot\Automation\LoadGuard::UPDATE_QUIET_SECONDS );
        $this->assertSame( 1800, \LinkGrowth\Autopilot\Automation\LoadGuard::BACKUP_LOOKAHEAD_SECONDS );
        $this->assertSame( 'updraft_backup_resume', \LinkGrowth\Autopilot\Automation\LoadGuard::UPDRAFT_RESUME_HOOK );
        $this->assertSame( [ 'opcache.memory_consumption' => '256', 'opcache.interned_strings_buffer' => '32', 'opcache.max_accelerated_files' => '32531' ], \LinkGrowth\Autopilot\Support\Opcache::RECOMMENDED_SETTINGS );

        // Boot registers the Site Health test on every request (the weekly check runs in cron) and records the version.
        delete_option( \LinkGrowth\Autopilot\Automation\LoadGuard::OPTION_VERSION );
        Container::plugin()->boot();
        $this->assertNotEmpty( $GLOBALS['lga_test_filters']['site_status_tests'] ?? [], 'site_status_tests is hooked' );
        $this->assertSame( LGA_VERSION, get_option( \LinkGrowth\Autopilot\Automation\LoadGuard::OPTION_VERSION )['version'] ?? null, 'the first request on a new version starts the quiet window' );

        // Activation starts the window too.
        $activator = (string) file_get_contents( self::ROOT . 'includes/Activator.php' );
        $this->assertStringContainsString( 'LoadGuard::class )->recordActivation()', $activator );

        $css = (string) file_get_contents( self::ROOT . 'assets/css/admin.css' );
        $this->assertStringContainsString( '.lga-paused-line {', $css );
        $this->assertFileExists( self::ROOT . 'assets/js/server-pressure.js' );

        $this->assertSame( 'lga_cpu_count', \LinkGrowth\Autopilot\Support\ServerResources::OPTION_CPUS, 'lga_ prefix: removed on uninstall' );
        $this->assertSame( [ 'opcache.memory_consumption' => '160', 'opcache.interned_strings_buffer' => '16', 'opcache.max_accelerated_files' => '20000' ], \LinkGrowth\Autopilot\Support\Opcache::SMALL_SERVER_SETTINGS );
        // WP2: the factories moved from Plugin.php into the module that owns each service (Core\Module::services()).
        $automation = (string) file_get_contents( self::ROOT . 'includes/Automation/AutomationModule.php' );
        $core       = (string) file_get_contents( self::ROOT . 'includes/Core/CoreModule.php' );
        $this->assertStringContainsString( 'new LoadGuard( $c->get( Opcache::class ), $c->get( ServerResources::class ) )', $automation, 'the guard reads the server through the container' );
        $this->assertStringContainsString( 'new SiteHealthTests( $c->get( Opcache::class ), $c->get( ServerResources::class ) )', $core, 'Site Health reads the memory through the container' );
        $run = (string) file_get_contents( self::ROOT . 'tests/integration/run.php' );
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
        $this->assertSame( 'lga_confirmation_required', $refused->get_error_code() );
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
        foreach ( [ 'verified-resources.test' => CatalogImporter::SOURCE, 'found-resources.test' => 'search' ] as $domain => $via ) {
            $db->insert( Schema::table( 'prospects' ), [ 'domain' => $domain, 'name' => $domain, 'status' => Prospects::STATUS_QUALIFIED, 'opportunity_type' => 'resource_page', 'score' => 90, 'discovered_via' => $via, 'first_seen' => $now, 'updated_at' => $now ] );
            $ids[ $domain ] = (int) $db->insert_id;
            $db->insert( Schema::table( 'contacts' ), [ 'prospect_id' => $ids[ $domain ], 'email' => 'editor@' . $domain, 'name' => 'Ed', 'role' => 'editor', 'confidence' => 80, 'consent_basis' => 'conspicuous_publication', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] );
        }
        $this->settings()->update(
            [
                'outreach'   => [ 'sender_name' => 'Jane Doe', 'sender_email' => 'jane@northwind.test', 'postal_address' => '1200 NW Front Ave, Portland, OR 97209, United States' ],
                'automation' => [ 'auto_outreach_drafts' => true ],
            ]
        );

        $result   = Container::get( JobRunner::class )->runType( 'auto_outreach', [ 'manual' => true ], TimeBudget::seconds( 60.0 ) );
        $messages = Container::get( OutreachMessages::class );
        $this->assertSame( 1, $messages->count( [ 'prospect_id' => $ids['found-resources.test'] ] ), (string) json_encode( $result ) );
        $this->assertSame( 0, $messages->count( [ 'prospect_id' => $ids['verified-resources.test'] ] ), 'a verified-database prospect is an owner task: never auto-drafted' );
    }
}
