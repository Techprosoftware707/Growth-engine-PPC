<?php
/**
 * End-to-end integration run against a real WordPress install.
 *
 * Usage: LGA_WP_PATH=/path/to/wordpress LGA_FIXTURE_LOG=/path/to/fixture.log php tests/integration/run.php
 *        (LGA_IT_ONLY=<part of a step title> runs one section plus the setup steps; LGA_RESET=0 keeps plugin state)
 *
 * Requirements (DESIGN.md §12.1): the LGA test site on http://localhost:8793 (tests/integration/build-lga-site.sh),
 * the fixture server on 127.0.0.1:8794 started with the same LGA_FIXTURE_LOG
 * (LGA_FIXTURE_LOG=… php -S 127.0.0.1:8794 tests/fixtures/router.php) and the mu-plugin
 * tests/integration/lga-test-env.php, which routes *.test hosts to it. Exits non-zero when any check fails.
 */

declare(strict_types=1);

$wpPath = getenv( 'LGA_WP_PATH' ) ?: '';
if ( '' === $wpPath || ! is_file( rtrim( $wpPath, '/' ) . '/wp-load.php' ) ) {
    fwrite( STDERR, "Set LGA_WP_PATH to a WordPress root containing wp-load.php\n" );
    exit( 2 );
}
$_SERVER['HTTP_HOST']      = 'localhost:8793';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
require rtrim( $wpPath, '/' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// First run on a fresh site: the plugin was not active when WordPress loaded, so its boot hooks (plugins_loaded,
// init, rest_api_init) never ran in this process. Activate it and run the suite again in a new process that loads
// it like every later run does (the first step then resets and re-activates it as usual).
if ( ! is_plugin_active( 'linkgrowth-autopilot/linkgrowth-autopilot.php' ) && '' === (string) getenv( 'LGA_IT_REEXEC' ) ) {
    $activated = activate_plugin( 'linkgrowth-autopilot/linkgrowth-autopilot.php' );
    if ( is_wp_error( $activated ) ) {
        fwrite( STDERR, 'Could not activate the plugin: ' . $activated->get_error_message() . "\n" );
        exit( 1 );
    }
    echo "The plugin was inactive on this site: activated it, running the suite again with it loaded.\n";
    putenv( 'LGA_IT_REEXEC=1' );
    passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ), $exitCode );
    exit( (int) $exitCode );
}

final class IT {
    public static int $pass = 0;
    public static int $fail = 0;
    /** @var string[] */
    public static array $failures = [];
    public static function check( bool $ok, string $label, string $detail = '' ): void {
        if ( $ok ) {
            self::$pass++;
            echo "  ✔ {$label}\n";
        } else {
            self::$fail++;
            self::$failures[] = $label . ( '' !== $detail ? ' — ' . $detail : '' );
            echo "  ✘ {$label}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
        }
    }
    /** Steps always run, also with LGA_IT_ONLY (they set up the plugin for every other step). */
    private const SETUP = [ 'Activation and schema', 'Configure for the fixture environment' ];

    public static function step( string $title, callable $fn ): void {
        // LGA_IT_ONLY=<part of a step title>: run only the matching steps (plus setup), for iterating on one section.
        $only = (string) getenv( 'LGA_IT_ONLY' );
        if ( '' !== $only && ! in_array( $title, self::SETUP, true ) && false === stripos( $title, $only ) ) {
            return;
        }
        echo "\n== {$title}\n";
        try {
            $fn();
        } catch ( \Throwable $e ) {
            self::check( false, 'step threw ' . get_class( $e ), $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
        }
    }
}

use LinkGrowth\Autopilot\Plugin;

require_once __DIR__ . '/growth-fixtures.php';
// The neutral demo business (DESIGN.md §12.2) and the discovery data the fork does not bundle (§1.3 step 1).
require_once dirname( __DIR__ ) . '/Support/Profiles.php';
require_once dirname( __DIR__ ) . '/Support/DiscoveryData.php';
require_once dirname( __DIR__ ) . '/Support/NeutralCatalog.php';
// The run stays offline: fixture hosts (*.test) go to the fixture server, everything else is refused.
lga_it_offline_guard();

$fixtureLog = getenv( 'LGA_FIXTURE_LOG' ) ?: sys_get_temp_dir() . '/lga-fixture-submissions.log';
@unlink( $fixtureLog );
$GLOBALS['itMail'] = [];
add_filter( 'pre_wp_mail', static function ( $null, array $atts ) {
    $GLOBALS['itMail'][] = $atts;
    return true;
}, 10, 2 );

wp_set_current_user( 1 );

IT::step( 'Activation and schema', static function (): void {
    global $wpdb;
    $plugin = 'linkgrowth-autopilot/linkgrowth-autopilot.php';
    if ( '0' !== (string) getenv( 'LGA_RESET' ) ) {
        // Start from a clean plugin state: drop plugin tables and options, deactivate.
        if ( is_plugin_active( $plugin ) ) {
            deactivate_plugins( $plugin );
        }
        // On a fresh site the plugin was never loaded, so its classes are not autoloadable yet.
        if ( ! class_exists( \LinkGrowth\Autopilot\Database\Schema::class ) ) {
            require_once WP_PLUGIN_DIR . '/linkgrowth-autopilot/includes/Autoloader.php';
            \LinkGrowth\Autopilot\Autoloader::register( WP_PLUGIN_DIR . '/linkgrowth-autopilot/includes', 'LinkGrowth\\Autopilot\\' );
        }
        foreach ( \LinkGrowth\Autopilot\Database\Schema::TABLES as $t ) {
            $wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'lga_' . $t . '`' ); // phpcs:ignore
        }
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lga\_%' OR option_name LIKE '\_transient\_lga\_%' OR option_name LIKE '\_transient\_timeout\_lga\_%'" ); // phpcs:ignore
        wp_cache_flush();
        IT::check( true, 'plugin state reset' );
        // Store fixtures left behind by an interrupted growth section would change the storefront check.
        $leftovers = lga_it_growth_cleanup();
        IT::check( true, 'growth section leftovers removed (' . $leftovers . ')' );
    }
    if ( ! is_plugin_active( $plugin ) ) {
        $r = activate_plugin( $plugin );
        IT::check( null === $r, 'activate_plugin returned no error', is_wp_error( $r ) ? $r->get_error_message() : '' );
    }
    IT::check( is_plugin_active( $plugin ), 'plugin active' );
    IT::check( function_exists( 'lga' ), 'lga() helper defined' );
    $schema = lga()->get( \LinkGrowth\Autopilot\Database\Schema::class );
    IT::check( count( $schema->existingTables() ) === count( \LinkGrowth\Autopilot\Database\Schema::TABLES ), 'all plugin tables exist', implode( ',', $schema->existingTables() ) );
    IT::check( get_option( 'lga_db_version' ) === LGA_DB_VERSION, 'db version stored' );
    IT::check( get_role( 'administrator' )->has_cap( 'lga_manage' ), 'administrator has lga_manage' );
    IT::check( ! lga()->get( \LinkGrowth\Autopilot\Database\Migrator::class )->needsMigration(), 'no pending migration' );
    IT::check( lga()->get( \LinkGrowth\Autopilot\Automation\Scheduler::class )->status()['backend'] !== 'none', 'scheduler registered a backend' );
    // 1.2.4: an activation starts the 10-minute quiet window in which background work waits (Automation\LoadGuard);
    // the rest of the run starts after it.
    if ( class_exists( \LinkGrowth\Autopilot\Automation\LoadGuard::class ) ) {
        $guard = lga()->get( \LinkGrowth\Autopilot\Automation\LoadGuard::class );
        if ( '0' !== (string) getenv( 'LGA_RESET' ) ) {
            $reason = $guard->pauseReason( true );
            IT::check( 'just_updated' === ( $reason['code'] ?? null ), 'the activation starts the quiet window', (string) wp_json_encode( $reason ) );
        }
        update_option( 'lga_running_version', [ 'version' => LGA_VERSION, 'since' => time() - HOUR_IN_SECONDS, 'from' => '' ], true );
        $guard->forget();
        IT::check( null === $guard->pauseReason( true ), 'the run starts after the quiet window' );
    }
} );

IT::step( 'Configure for the fixture environment', static function (): void {
    $settings = lga()->get( \LinkGrowth\Autopilot\Support\Settings::class );
    // The shipped profile is empty (DESIGN.md §1.7 E): the run uses the Northwind Supply Co demo business, its topics
    // (discovery.extra_terms, as an owner enters them) and neutral query templates through lga_discovery_queries.
    \LinkGrowth\Autopilot\Tests\Support\Profiles::apply( $settings );
    \LinkGrowth\Autopilot\Tests\Support\Profiles::applyTopics( $settings );
    add_filter( 'lga_discovery_queries', static fn(): array => \LinkGrowth\Autopilot\Tests\Support\DiscoveryData::queries() );
    // No opportunity catalog ships (§2.5): the importer reads the synthetic neutral catalog (250 fictional items).
    $catalog = \LinkGrowth\Autopilot\Tests\Support\NeutralCatalog::write();
    register_shutdown_function( static fn() => \LinkGrowth\Autopilot\Tests\Support\NeutralCatalog::cleanup() );
    lga()->container()->set( \LinkGrowth\Autopilot\Discovery\CatalogImporter::class, \LinkGrowth\Autopilot\Discovery\CatalogImporter::fromContainer( lga()->container(), $catalog ) );
    $settings->update( [
        'site'       => [ 'domain' => 'northwind.test', 'home_url' => 'https://northwind.test' ],
        'advanced'   => [ 'crawl_delay_seconds' => 0, 'log_level' => 'debug' ],
        'providers'  => [ 'rdap' => [ 'enabled' => false ], 'seranking' => [ 'enabled' => false ] ],
        'discovery'  => [ 'seed_catalog' => false, 'brand_terms' => [ 'Northwind Supply Co', 'northwind.test' ] ],
        'automation' => [ 'enabled' => true, 'submissions' => true, 'submission_mode' => 'manual', 'auto_outreach_drafts' => true, 'outreach_autosend' => false, 'daily_limits' => [ 'evaluations' => 50, 'submissions' => 5, 'outreach_emails' => 10, 'backlink_checks' => 100, 'discovery_queries' => 10, 'provider_lookups' => 20 ] ],
        'outreach'   => [ 'sender_name' => 'Test Sender', 'sender_email' => 'outreach@northwind.test', 'postal_address' => '1200 NW Front Ave, Portland, OR 97209', 'signature' => 'Test Sender, Northwind Supply Co' ],
        'monitoring' => [ 'notify_email' => 'admin@example.com' ],
    ] );
    IT::check( 'northwind.test' === $settings->siteDomain(), 'site domain configured' );
    // Register an offline search provider returning fixture pages.
    add_filter( 'lga_search_providers', static function ( array $list ): array {
        $list[] = new class() implements \LinkGrowth\Autopilot\Integrations\Contracts\SearchProvider {
            public function id(): string { return 'fixture_search'; }
            public function label(): string { return 'Fixture search'; }
            public function isConfigured(): bool { return true; }
            public function remainingToday(): int { return 999; }
            public function lastError(): string { return ''; }
            public function search( string $query, array $opts = [] ): array {
                if ( str_contains( $query, 'Northwind Supply Co' ) || str_contains( $query, 'northwindsupplyco' ) ) {
                    return [
                        [ 'url' => 'https://mention.test/blog/regional-fastener-suppliers/', 'title' => 'Comparing regional research fastener suppliers', 'snippet' => 'Northwind Supply Co lists Kestrel reports', 'position' => 1 ],
                        [ 'url' => 'https://mention.test/blog/linked/', 'title' => 'Where we buy reagents', 'snippet' => '', 'position' => 2 ],
                    ];
                }
                return [
                    [ 'url' => 'https://labdirectory.test/add-listing/', 'title' => 'Add your laboratory business | LabDirectory', 'snippet' => 'Submit a free listing', 'position' => 1 ],
                    [ 'url' => 'https://resources.test/links/', 'title' => 'Laboratory resources and links for students', 'snippet' => 'Useful links', 'position' => 2 ],
                    [ 'url' => 'https://spam.test/', 'title' => 'Best Online Casino Bonus', 'snippet' => 'buy backlinks', 'position' => 3 ],
                    [ 'url' => 'https://facebook.com/labgroup', 'title' => 'Facebook group', 'snippet' => '', 'position' => 4 ],
                ];
            }
        };
        return $list;
    } );
    $registry = lga()->get( \LinkGrowth\Autopilot\Integrations\ProviderRegistry::class );
    IT::check( null !== $registry->primarySearch() && 'fixture_search' === $registry->primarySearch()->id(), 'fixture search provider active' );
} );

IT::step( 'Discovery via search', static function (): void {
    $pipeline = lga()->get( \LinkGrowth\Autopilot\Automation\Pipeline::class );
    $r        = $pipeline->runNow( 'discover', [ 'max_queries' => 2, 'skip_competitors' => true ], 25 );
    IT::check( isset( $r['result'] ), 'discover job ran', wp_json_encode( $r ) );
    $prospects = lga()->get( \LinkGrowth\Autopilot\Repositories\Prospects::class );
    IT::check( null !== $prospects->findByDomain( 'labdirectory.test' ), 'labdirectory.test discovered' );
    IT::check( null !== $prospects->findByDomain( 'resources.test' ), 'resources.test discovered' );
    IT::check( null !== $prospects->findByDomain( 'spam.test' ), 'spam.test discovered (to be rejected later)' );
    IT::check( null === $prospects->findByDomain( 'facebook.com' ), 'excluded platform skipped' );
    $manual = lga()->get( \LinkGrowth\Autopilot\Discovery\ProspectIntake::class )->manual( 'https://mention.test/', 'added in integration test', 1 );
    IT::check( $manual && $manual['prospect_id'] > 0, 'manual prospect added' );
} );

IT::step( 'Evaluation and scoring', static function (): void {
    $pipeline  = lga()->get( \LinkGrowth\Autopilot\Automation\Pipeline::class );
    $prospects = lga()->get( \LinkGrowth\Autopilot\Repositories\Prospects::class );
    $r = $pipeline->runNow( 'evaluate', [], 28 );
    IT::check( isset( $r['result'] ), 'evaluate job ran', wp_json_encode( $r ) );
    $lab = $prospects->findByDomain( 'labdirectory.test' );
    IT::check( 'qualified' === $lab['status'], 'labdirectory.test qualified', 'status=' . $lab['status'] . ' reason=' . $lab['rejection_reason'] );
    IT::check( (int) $lab['score'] >= 60, 'labdirectory.test score >= 60', 'score=' . $lab['score'] );
    IT::check( 'US' === $lab['country'], 'country detected US (schema.org addressCountry)', (string) $lab['country'] );
    IT::check( 'https://labdirectory.test/add-listing/' === $lab['submission_url'], 'submission page found', (string) $lab['submission_url'] );
    IT::check( ! empty( $lab['profile']['submission_form'] ), 'submission form stored in profile' );
    IT::check( '' !== $lab['recommended_action'], 'recommended action set' );
    IT::check( is_array( $lab['score_breakdown'] ) && isset( $lab['score_breakdown']['components']['topical_relevance'] ), 'score breakdown stored' );
    $contacts = lga()->get( \LinkGrowth\Autopilot\Repositories\Contacts::class )->forProspect( (int) $lab['id'] );
    IT::check( in_array( 'editor@labdirectory.test', array_column( $contacts, 'email' ), true ), 'editor contact extracted' );
    $spam = $prospects->findByDomain( 'spam.test' );
    IT::check( 'rejected' === $spam['status'], 'spam.test rejected', 'status=' . $spam['status'] );
    $audit = lga()->get( \LinkGrowth\Autopilot\Repositories\AuditLog::class )->count( [ 'action' => 'prospect.evaluated' ] );
    IT::check( $audit >= 3, 'evaluation audited', (string) $audit );
} );

IT::step( 'Opportunities, broken links and mention scan', static function (): void {
    $pipeline  = lga()->get( \LinkGrowth\Autopilot\Automation\Pipeline::class );
    $prospects = lga()->get( \LinkGrowth\Autopilot\Repositories\Prospects::class );
    $opps      = lga()->get( \LinkGrowth\Autopilot\Repositories\Opportunities::class );
    $pipeline->runNow( 'find_opportunities', [], 25 );
    $lab = $prospects->findByDomain( 'labdirectory.test' );
    IT::check( $opps->count( [ 'prospect_id' => (int) $lab['id'] ] ) >= 1, 'listing opportunity exists for labdirectory.test' );
    $res = $prospects->findByDomain( 'resources.test' );
    $pipeline->runNow( 'broken_link_scan', [ 'prospect_id' => (int) $res['id'] ], 25 );
    IT::check( $opps->count( [ 'prospect_id' => (int) $res['id'], 'type' => 'broken_link' ] ) >= 1, 'broken-link opportunities for resources.test' );
    $pipeline->runNow( 'mention_scan', [], 25 );
    $mention = $prospects->findByDomain( 'mention.test' );
    IT::check( $opps->count( [ 'prospect_id' => (int) $mention['id'], 'type' => 'unlinked_mention' ] ) >= 1, 'unlinked mention opportunity created' );
    $backlinks = lga()->get( \LinkGrowth\Autopilot\Repositories\Backlinks::class );
    IT::check( count( $backlinks->forDomain( 'mention.test' ) ) >= 1, 'linked mention recorded as backlink' );
} );

IT::step( 'Submission packaging, authorized submission and verification', static function () use ( $fixtureLog ): void {
    $engine    = lga()->get( \LinkGrowth\Autopilot\Submission\SubmissionEngine::class );
    $prospects = lga()->get( \LinkGrowth\Autopilot\Repositories\Prospects::class );
    $subs      = lga()->get( \LinkGrowth\Autopilot\Repositories\Submissions::class );
    $settings  = lga()->get( \LinkGrowth\Autopilot\Support\Settings::class );
    $lab       = $prospects->findByDomain( 'labdirectory.test' );
    $sid       = $engine->packageProspect( (int) $lab['id'], 1 );
    IT::check( is_int( $sid ) && $sid > 0, 'package created', var_export( $sid, true ) );
    $sub = $subs->find( (int) $sid );
    IT::check( in_array( $sub['status'], [ 'needs_manual', 'pending_review' ], true ), 'manual mode parks the package for human review', $sub['status'] );
    $fields = array_column( (array) ( $sub['payload']['fields'] ?? [] ), 'value', 'name' );
    IT::check( ( $fields['business_name'] ?? '' ) === 'Northwind Supply Co', 'business name mapped', wp_json_encode( $fields ) );
    IT::check( ( $fields['website'] ?? '' ) === 'https://northwind.test', 'website mapped', (string) ( $fields['website'] ?? '' ) );
    IT::check( ( $fields['category'] ?? '' ) === '18', 'category matched to Scientific Supplies & Reagents', (string) ( $fields['category'] ?? '' ) );
    IT::check( ( $fields['state'] ?? '' ) === 'OR', 'state matched to the business region', (string) ( $fields['state'] ?? '' ) );
    IT::check( ! empty( $sub['payload']['content']['description'] ) && stripos( $sub['payload']['content']['description'], 'research use' ) !== false, 'description generated with research-use positioning' );
    IT::check( empty( $sub['payload']['compliance']['violations'] ), 'package passes claims filter' );
    IT::check( empty( $sub['payload']['unmapped_required'] ), 'no unmapped required fields', wp_json_encode( $sub['payload']['unmapped_required'] ?? [] ) );
    IT::check( $engine->packageProspect( (int) $lab['id'], 1 ) === $sid, 'duplicate package prevented' );

    // Switch to full-auto mode: no per-prospect authorization, no human approval.
    $settings->set( 'automation.submission_mode', 'auto' );
    $settings->set( 'automation.min_score_auto_queue', 60 );
    $subs->delete( (int) $sid );
    $sid = $engine->packageProspect( (int) $lab['id'], 0 );
    IT::check( is_int( $sid ) && $sid > 0, 'auto mode re-packaged without a user', var_export( $sid, true ) );
    $sub = $subs->find( (int) $sid );
    IT::check( 'queued' === $sub['status'], 'auto mode queues the submission by itself (no approval click)', $sub['status'] . ' ' . (string) $sub['last_error'] );
    IT::check( 0 === (int) ( $lab['automation_allowed'] ?? 0 ), 'prospect was never individually authorized' );
    $r = $engine->processQueue( \LinkGrowth\Autopilot\Support\TimeBudget::seconds( 25 ), 5 );
    IT::check( ( $r['submitted'] ?? 0 ) === 1, 'form submitted automatically', wp_json_encode( $r ) );
    $sub = $subs->find( (int) $sid );
    IT::check( 'submitted' === $sub['status'], 'submission status submitted', $sub['status'] . ' ' . (string) $sub['last_error'] );
    $log = is_file( $fixtureLog ) ? file_get_contents( $GLOBALS['fixtureLog'] ) : '';
    IT::check( str_contains( $log, '"business_name":"Northwind Supply Co"' ), 'fixture server received the POST', substr( $log, 0, 300 ) );
    IT::check( str_contains( $log, '"form_token":"tok-7f3a9c"' ), 'hidden token preserved' );
    IT::check( ! str_contains( $log, '"newsletter":"1"' ), 'newsletter checkbox NOT ticked' );
    IT::check( str_contains( $log, '"accuracy":"1"' ), 'accuracy/terms consent ticked' );
    IT::check( str_contains( $log, 'LinkGrowthAutopilotBot' ), 'honest user agent sent' );

    // Low-quality guard: a rejected/low-score prospect is never auto-submitted.
    $spam   = $prospects->findByDomain( 'spam.test' );
    $policy = lga()->get( \LinkGrowth\Autopilot\Submission\Policy::class )->evaluate( $spam, [ 'unmapped_required' => [] ], [ 'kind' => 'listing_submission', 'method' => 'POST', 'fields' => [] ], 'https://spam.test/submit' );
    IT::check( ! $policy['allowed'], 'auto mode refuses low-quality sites', wp_json_encode( $policy['reasons'] ) );

    // Verification via known listing URL.
    $subs->update( (int) $sid, [ 'listing_url' => 'https://labdirectory.test/listing/northwind-supply-co/', 'next_verify_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
    $v = $engine->verifyDue( \LinkGrowth\Autopilot\Support\TimeBudget::seconds( 20 ), 5 );
    IT::check( ( $v['confirmed'] ?? 0 ) === 1, 'listing verified', wp_json_encode( $v ) );
    IT::check( 'confirmed' === $subs->find( (int) $sid )['status'], 'submission confirmed' );
    $bl = lga()->get( \LinkGrowth\Autopilot\Repositories\Backlinks::class )->forDomain( 'labdirectory.test' );
    IT::check( count( $bl ) >= 1, 'backlink recorded from listing' );
    IT::check( 'acquired' === $prospects->findByDomain( 'labdirectory.test' )['status'], 'prospect marked acquired' );
} );

IT::step( 'Backlink monitoring', static function (): void {
    $monitor   = lga()->get( \LinkGrowth\Autopilot\Monitoring\BacklinkMonitor::class );
    $backlinks = lga()->get( \LinkGrowth\Autopilot\Repositories\Backlinks::class );
    $id        = $monitor->addManual( 'https://labdirectory.test/listing/northwind-supply-co/', 'https://northwind.test/test-reports/', 1 );
    IT::check( $id > 0, 'manual backlink added' );
    $r = $monitor->check( $backlinks->find( $id ) );
    IT::check( 'active' === $r['status'], 'nofollow test-reports link detected active', wp_json_encode( $r ) );
    $row = $backlinks->find( $id );
    IT::check( str_contains( (string) $row['rel'], 'nofollow' ), 'rel recorded as nofollow', (string) $row['rel'] );
    IT::check( 'Test Reports' === $row['anchor_text'], 'anchor text recorded', (string) $row['anchor_text'] );
    $dead = $monitor->addManual( 'https://deadlab.test/old-listing/', 'https://northwind.test/', 1 );
    $monitor->check( $backlinks->find( $dead ) );
    $monitor->check( $backlinks->find( $dead ) );
    IT::check( in_array( $backlinks->find( $dead )['status'], [ 'lost', 'broken' ], true ), 'dead page marked lost/broken after two strikes', $backlinks->find( $dead )['status'] );
    $notes = lga()->get( \LinkGrowth\Autopilot\Repositories\Notifications::class )->count( [ 'severity' => 'critical' ] );
    IT::check( $notes >= 1, 'critical notification raised for lost backlink' );
    $due = $monitor->checkDue( \LinkGrowth\Autopilot\Support\TimeBudget::seconds( 20 ), 10 );
    IT::check( isset( $due['checked'] ), 'checkDue returns stats' );
    $alerts = array_values( array_filter( $GLOBALS['itMail'], static fn( $m ) => stripos( (string) $m['subject'], 'lost' ) !== false || stripos( (string) $m['subject'], 'backlink' ) !== false ) );
    IT::check( count( $alerts ) >= 1, 'immediate email alert sent for the lost backlink', wp_json_encode( array_column( $GLOBALS['itMail'], 'subject' ) ) );
    $GLOBALS['itMail'] = [];
} );

IT::step( 'Outreach drafting, approval, sending, replies and opt-out', static function (): void {
    $GLOBALS['itMail'] = [];
    $mail = &$GLOBALS['itMail'];
    $outreach  = lga()->get( \LinkGrowth\Autopilot\Outreach\OutreachEngine::class );
    $messages  = lga()->get( \LinkGrowth\Autopilot\Repositories\OutreachMessages::class );
    $prospects = lga()->get( \LinkGrowth\Autopilot\Repositories\Prospects::class );
    $lab       = $prospects->findByDomain( 'labdirectory.test' );
    $cid       = $outreach->createCampaign( 'Resource page test', 'resource_page', [ 'daily_limit' => 5 ], 1 );
    IT::check( $cid > 0, 'campaign created' );
    $built = $outreach->buildDrafts( $cid, [ (int) $lab['id'] ], 1 );
    IT::check( ( $built['created'] ?? 0 ) === 1, 'draft created', wp_json_encode( $built ) );
    $draft = $messages->findOneBy( [ 'campaign_id' => $cid ] );
    IT::check( str_contains( $draft['body'], '1200 NW Front Ave' ), 'postal address in footer' );
    IT::check( str_contains( $draft['body'], 'unsubscribe' ), 'unsubscribe mechanism in body' );
    IT::check( str_contains( $draft['body'], 'labdirectory' ) || str_contains( $draft['body'], 'LabDirectory' ), 'personalised with site name' );
    IT::check( 32 === strlen( (string) $draft['unsubscribe_token'] ), 'unsubscribe token generated' );
    $send = $outreach->sendNow( (int) $draft['id'], 1 );
    IT::check( empty( $send['ok'] ), 'send refused before approvals', wp_json_encode( $send ) );
    $outreach->approveCampaign( $cid, 1 );
    $a = $outreach->approveMessage( (int) $draft['id'], 1 );
    IT::check( ! empty( $a['ok'] ), 'message approved', wp_json_encode( $a ) );
    $send = $outreach->sendNow( (int) $draft['id'], 1 );
    IT::check( ! empty( $send['ok'] ), 'message sent', wp_json_encode( $send ) );
    IT::check( count( $mail ) === 1, 'exactly one email dispatched', (string) count( $mail ) );
    $headers = implode( "\n", (array) ( $mail[0]['headers'] ?? [] ) );
    IT::check( stripos( $headers, 'List-Unsubscribe' ) !== false, 'List-Unsubscribe header present' );
    IT::check( stripos( $headers, 'From: Test Sender <outreach@northwind.test>' ) !== false, 'From header uses sender identity', $headers );
    $sent = $messages->find( (int) $draft['id'] );
    IT::check( 'sent' === $sent['status'] && '' !== $sent['message_id'], 'message marked sent with message id' );
    $dup = $outreach->buildDrafts( $cid, [ (int) $lab['id'] ], 1 );
    IT::check( ( $dup['created'] ?? 0 ) === 0, 'no duplicate draft for the same contact/approach' );

    $inbound = lga()->get( \LinkGrowth\Autopilot\Outreach\InboundWebhook::class );
    lga()->get( \LinkGrowth\Autopilot\Support\Secrets::class )->set( 'inbound_webhook_secret', 'hook-secret' );
    $bad = $inbound->handle( [ 'from' => 'editor@labdirectory.test', 'subject' => 'Re: x', 'text' => 'thanks', 'in_reply_to' => '<' . $sent['message_id'] . '>' ], 'wrong' );
    IT::check( empty( $bad['ok'] ), 'inbound webhook rejects wrong secret' );
    $good = $inbound->handle( [ 'from' => 'editor@labdirectory.test', 'subject' => 'Re: x', 'text' => 'Happy to add you.', 'in_reply_to' => '<' . $sent['message_id'] . '>' ], 'hook-secret' );
    IT::check( ! empty( $good['ok'] ) && ( $good['matched'] ?? 0 ) === 1, 'reply matched by message id', wp_json_encode( $good ) );
    IT::check( 'replied' === $messages->find( (int) $draft['id'] )['status'], 'message marked replied' );

    $unsub = lga()->get( \LinkGrowth\Autopilot\Outreach\Unsubscribe::class )->process( (string) $draft['unsubscribe_token'] );
    IT::check( ! empty( $unsub['ok'] ) && 'editor@labdirectory.test' === $unsub['email'], 'unsubscribe processed', wp_json_encode( $unsub ) );
    IT::check( lga()->get( \LinkGrowth\Autopilot\Repositories\Suppression::class )->isSuppressed( 'email', 'editor@labdirectory.test' ), 'email suppressed after opt-out' );
    $compliance = lga()->get( \LinkGrowth\Autopilot\Outreach\Compliance::class );
    IT::check( $compliance->senderReady()['ok'], 'sender identity ready' );
} );

IT::step( 'REST API', static function (): void {
    $do = static function ( string $method, string $route, array $params = [] ) {
        $req = new WP_REST_Request( $method, '/lga/v1' . $route );
        foreach ( $params as $k => $v ) {
            $req->set_param( $k, $v );
        }
        if ( 'POST' === $method ) {
            $req->set_header( 'Content-Type', 'application/json' );
            $req->set_body( wp_json_encode( $params ) );
        }
        return rest_do_request( $req );
    };
    $overview = $do( 'GET', '/overview' );
    IT::check( 200 === $overview->get_status(), 'GET /overview 200', wp_json_encode( $overview->get_data() ) );
    $data = $overview->get_data();
    IT::check( isset( $data['stats']['prospects_total'] ) && $data['stats']['prospects_total'] >= 4, 'overview stats populated' );
    IT::check( isset( $data['charts']['score_histogram'] ), 'overview charts present' );
    $list = $do( 'GET', '/prospects', [ 'status' => 'acquired' ] );
    IT::check( 200 === $list->get_status() && ( $list->get_data()['total'] ?? 0 ) === 1, 'GET /prospects filter works', wp_json_encode( $list->get_data()['total'] ?? null ) );
    $one = $do( 'GET', '/prospects/' . $list->get_data()['items'][0]['id'] );
    IT::check( 200 === $one->get_status() && isset( $one->get_data()['prospect']['domain'] ), 'GET /prospects/{id}' );
    $subs = $do( 'GET', '/submissions', [ 'status' => 'confirmed' ] );
    IT::check( 200 === $subs->get_status() && ( $subs->get_data()['total'] ?? 0 ) === 1, 'GET /submissions' );
    $bl = $do( 'GET', '/backlinks' );
    IT::check( 200 === $bl->get_status() && ( $bl->get_data()['total'] ?? 0 ) >= 2, 'GET /backlinks' );
    $auto = $do( 'GET', '/automation/status' );
    IT::check( 200 === $auto->get_status() && isset( $auto->get_data()['queue'] ), 'GET /automation/status' );
    $settings = $do( 'GET', '/settings' );
    $json     = wp_json_encode( $settings->get_data() );
    IT::check( 200 === $settings->get_status(), 'GET /settings 200' );
    IT::check( ! str_contains( $json, 'hook-secret' ), 'secrets never returned by settings endpoint' );
    $save = $do( 'POST', '/settings', [ 'settings' => [ 'automation' => [ 'daily_limits' => [ 'submissions' => 7 ] ] ], 'secrets' => [ 'brave_api_key' => 'brave-123456' ] ] );
    IT::check( 200 === $save->get_status(), 'POST /settings 200', wp_json_encode( $save->get_data() ) );
    IT::check( 7 === (int) lga()->get( \LinkGrowth\Autopilot\Support\Settings::class )->get( 'automation.daily_limits.submissions' ), 'setting persisted' );
    IT::check( lga()->get( \LinkGrowth\Autopilot\Support\Secrets::class )->get( 'brave_api_key' ) === 'brave-123456', 'secret stored encrypted' );
    $logs = $do( 'GET', '/logs' );
    IT::check( 200 === $logs->get_status() && ( $logs->get_data()['total'] ?? 0 ) > 0, 'GET /logs' );
    $audit = $do( 'GET', '/audit' );
    IT::check( 200 === $audit->get_status() && ( $audit->get_data()['total'] ?? 0 ) > 0, 'GET /audit' );
    $run = $do( 'POST', '/automation/run', [ 'stage' => 'cleanup' ] );
    IT::check( 200 === $run->get_status(), 'POST /automation/run cleanup', wp_json_encode( $run->get_data() ) );
    $camps = $do( 'GET', '/outreach/campaigns' );
    IT::check( 200 === $camps->get_status() && ( $camps->get_data()['total'] ?? 0 ) >= 1, 'GET /outreach/campaigns' );
    $campId = (int) ( $camps->get_data()['items'][0]['id'] ?? 0 );
    $pause  = $do( 'POST', '/outreach/campaigns/' . $campId . '/pause' );
    IT::check( 200 === $pause->get_status(), 'POST /outreach/campaigns/{id}/pause', wp_json_encode( $pause->get_data() ) );
    $msgs = $do( 'GET', '/outreach/messages', [ 'campaign_id' => $campId ] );
    IT::check( 200 === $msgs->get_status() && ( $msgs->get_data()['total'] ?? 0 ) >= 1, 'GET /outreach/messages' );
    $msgId = (int) ( $msgs->get_data()['items'][0]['id'] ?? 0 );
    $msg   = $do( 'GET', '/outreach/messages/' . $msgId );
    IT::check( 200 === $msg->get_status() && isset( $msg->get_data()['message']['subject'], $msg->get_data()['contact'] ), 'GET /outreach/messages/{id} envelope' );
    $subsList = $do( 'GET', '/submissions' );
    $subId    = (int) ( $subsList->get_data()['items'][0]['id'] ?? 0 );
    $subOne   = $do( 'GET', '/submissions/' . $subId );
    IT::check( 200 === $subOne->get_status() && isset( $subOne->get_data()['submission']['payload']['fields'] ), 'GET /submissions/{id} returns full package' );
    $bl1 = $do( 'GET', '/backlinks/summary' );
    IT::check( 200 === $bl1->get_status(), 'GET /backlinks/summary' );
    $notif = $do( 'GET', '/notifications' );
    IT::check( 200 === $notif->get_status(), 'GET /notifications' );
    $bad = $do( 'POST', '/automation/run', [ 'stage' => 'rm -rf' ] );
    IT::check( $bad->get_status() >= 400, 'unknown stage rejected' );
    wp_set_current_user( 0 );
    $anon = $do( 'GET', '/overview' );
    IT::check( in_array( $anon->get_status(), [ 401, 403 ], true ), 'anonymous blocked from admin routes' );
    $pub = $do( 'GET', '/unsubscribe', [ 't' => 'not-a-token' ] );
    IT::check( $pub->get_status() >= 400, 'public unsubscribe rejects bad token', (string) $pub->get_status() );
    $inb = $do( 'POST', '/inbound', [ 'from' => 'x@y.test', 'text' => 'hi' ] );
    IT::check( in_array( $inb->get_status(), [ 401, 403 ], true ), 'public inbound without secret rejected', (string) $inb->get_status() );
    wp_set_current_user( 1 );
} );

IT::step( 'Automatic outreach drafting', static function (): void {
    $settings = lga()->get( \LinkGrowth\Autopilot\Support\Settings::class );
    $settings->set( 'automation.min_score_auto_queue', 50 ); // resources.test scored in the 50s
    $r = lga()->get( \LinkGrowth\Autopilot\Automation\Pipeline::class )->runNow( 'auto_outreach', [ 'manual' => true ], 20 );
    $res = (array) ( $r['result'] ?? [] );
    IT::check( ( $res['drafted'] ?? 0 ) >= 1, 'auto outreach drafted at least one message', wp_json_encode( $res ) );
    $campaigns = lga()->get( \LinkGrowth\Autopilot\Repositories\Campaigns::class );
    $auto = $campaigns->findOneBy( [ 'approach' => 'educational_contribution' ] );
    IT::check( null !== $auto && str_starts_with( (string) $auto['name'], 'Auto:' ), 'evergreen campaign created', wp_json_encode( $auto['name'] ?? null ) );
    $messages = lga()->get( \LinkGrowth\Autopilot\Repositories\OutreachMessages::class );
    $draft = null !== $auto ? $messages->findOneBy( [ 'campaign_id' => (int) $auto['id'] ] ) : null;
    IT::check( null !== $draft && 'draft' === $draft['status'], 'auto-drafted message waits for approval while autosend is off', wp_json_encode( $draft['status'] ?? null ) );
    IT::check( null !== $draft && str_contains( (string) $draft['body'], 'unsubscribe' ), 'auto draft carries the compliance footer' );
    $again = lga()->get( \LinkGrowth\Autopilot\Automation\Pipeline::class )->runNow( 'auto_outreach', [ 'manual' => true ], 20 );
    IT::check( ( ( (array) ( $again['result'] ?? [] ) )['drafted'] ?? -1 ) === 0, 'second run drafts nothing new (dedupe)', wp_json_encode( $again['result'] ?? null ) );
} );

IT::step( 'Scheduler tick, limits and backups', static function (): void {
    $pipeline = lga()->get( \LinkGrowth\Autopilot\Automation\Pipeline::class );
    $tick     = $pipeline->tick();
    IT::check( is_array( $tick ), 'tick ran', wp_json_encode( $tick ) );
    IT::check( '' !== (string) get_option( 'lga_last_tick', '' ), 'last tick recorded' );
    $limits = lga()->get( \LinkGrowth\Autopilot\Support\Limits::class )->snapshot();
    IT::check( ( $limits['submissions']['used_today'] ?? 0 ) === 1, 'submission limit counter incremented', wp_json_encode( $limits['submissions'] ?? null ) );
    IT::check( ( $limits['outreach_emails']['used_today'] ?? 0 ) === 1, 'outreach limit counter incremented' );
    $backup = lga()->get( \LinkGrowth\Autopilot\Database\Backup::class );
    $file   = $backup->dump( lga()->get( \LinkGrowth\Autopilot\Database\Schema::class )->existingTables(), 'integration' );
    IT::check( null !== $file && is_file( $file ) && filesize( $file ) > 100, 'backup dump created' );
    $status = $pipeline->status();
    IT::check( isset( $status['queue'], $status['limits'], $status['flags'] ), 'pipeline status shape' );
    $logs = lga()->get( \LinkGrowth\Autopilot\Repositories\Logs::class )->count( [ 'level' => 'error' ] );
    IT::check( 0 === $logs, 'no error-level log entries during the run', (string) $logs );
    if ( $logs > 0 ) {
        foreach ( lga()->get( \LinkGrowth\Autopilot\Repositories\Logs::class )->findBy( [ 'level' => 'error' ], 'id', 'DESC', 10 ) as $row ) {
            echo "    error log: {$row['channel']}: {$row['message']}\n";
        }
    }
} );

// "Grow now" release: storefront compliance, growth run, verified opportunities, on-site SEO.
require __DIR__ . '/growth.php';

// "LinkGrowth Submitter" role: owner tasks handed to a staff member or assistant.
require __DIR__ . '/submitter.php';

// 1.2.0: campaigns fill at once, the Autopilot switch, sender DNS checks and the send-window time zone.
require __DIR__ . '/autopilot.php';

// 1.2.0 Build B: automatic internal links (and their undo) and competitors found without a search key.
require __DIR__ . '/autopilot-links.php';

// 1.2.1: drafts whose footer was rendered under an old postal address are refreshed and approved, not stuck.
require __DIR__ . '/footer-refresh.php';

// 1.2.2: automatic links only on pages that show them (loopback render check); administrator markup saved as the
// consenting owner instead of being left for a person.
require __DIR__ . '/render-check.php';

// 1.2.3: internal links never use the brand name as link text and never cross languages (the live Terms page case).
require __DIR__ . '/link-rules.php';

// 1.2.4: no background work while a backup runs, OPcache restarts or the plugin was just updated; the OPcache warning.
require __DIR__ . '/server-pressure.php';

// The test mu-plugin (tests/integration/lga-test-env.php): the outbound host logger that WP4b's activation-silence
// section counts with, and the frozen clock.
IT::step( 'Test environment: outbound host logger and frozen clock', static function (): void {
    IT::check( function_exists( 'lga_test_http_hosts' ) && function_exists( 'lga_test_http_reset' ) && function_exists( 'lga_test_time' ), 'the lga-test-env mu-plugin is loaded' );
    if ( ! function_exists( 'lga_test_http_hosts' ) ) {
        return;
    }
    wp_remote_get( 'https://resources.test/', [ 'timeout' => 5 ] );
    $hosts = lga_test_http_hosts();
    // In a full run the discovery and evaluation sections crawled labdirectory.test before this step.
    $whole = '' === (string) getenv( 'LGA_IT_ONLY' );
    IT::check( ( $hosts['resources.test'] ?? 0 ) > 0 && ( ! $whole || ( $hosts['labdirectory.test'] ?? 0 ) > 0 ), 'requests to fixture hosts were recorded' . ( $whole ? ' (the whole run so far)' : '' ), (string) wp_json_encode( $hosts ) );
    IT::check( ! isset( $hosts['127.0.0.1'] ), 'the internal hop to the fixture server is not counted as a request of its own', (string) wp_json_encode( array_keys( $hosts ) ) );
    $file = (string) wp_tempnam( 'lga-host-log' );
    putenv( 'LGA_HTTP_HOST_LOG=' . $file );
    lga_test_http_reset();
    $res = wp_remote_get( 'https://labdirectory.test/robots.txt', [ 'timeout' => 5 ] );
    $blocked = wp_remote_get( 'https://outside.example/', [ 'timeout' => 5 ] );
    putenv( 'LGA_HTTP_HOST_LOG' );
    IT::check( 200 === wp_remote_retrieve_response_code( $res ) && is_wp_error( $blocked ), 'a fixture request is answered and an outside host is refused (offline run)' );
    IT::check( [ 'labdirectory.test' => 1, 'outside.example' => 1 ] === lga_test_http_hosts(), 'after a reset, exactly the attempted hosts are recorded, refused ones included', (string) wp_json_encode( lga_test_http_hosts() ) );
    $lines = array_values( array_filter( array_map( static fn( string $l ): ?array => json_decode( $l, true ), file( $file, FILE_IGNORE_NEW_LINES ) ?: [] ) ) );
    @unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    IT::check( [ 'labdirectory.test', 'outside.example' ] === array_column( $lines, 'host' ), 'LGA_HTTP_HOST_LOG gets one JSON line per request (for requests made by other processes)', (string) wp_json_encode( $lines ) );
    $GLOBALS['lga_test_time'] = 1767225600;
    $frozen   = lga_test_time();
    $filtered = (int) apply_filters( 'lga_test_time', 0 );
    unset( $GLOBALS['lga_test_time'] );
    IT::check( 1767225600 === $frozen && 1767225600 === $filtered, 'lga_test_time() and the lga_test_time filter return the frozen time a test sets', $frozen . ' / ' . $filtered );
    IT::check( abs( lga_test_time() - time() ) <= 1, 'without a frozen time it is the real time' );
} );

// Coexistence with the engine this plugin was forked from (DESIGN.md §1.4): its own processes, because each plugin
// must load with WordPress. The file is not in the source ZIP (it names the engine), so a copy without it skips it.
$coexistence = __DIR__ . '/coexistence.php';
if ( is_file( $coexistence ) && '0' !== (string) getenv( 'LGA_COEXISTENCE' ) && ( '' === (string) getenv( 'LGA_IT_ONLY' ) || false !== stripos( 'coexistence', (string) getenv( 'LGA_IT_ONLY' ) ) ) ) {
    $out  = [];
    $code = 0;
    exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $coexistence ) . ' 2>&1', $out, $code );
    echo implode( "\n", $out ) . "\n";
    $text = implode( "\n", $out );
    if ( preg_match( '/^Passed: (\d+)\s+Failed: (\d+)$/m', $text, $m ) ) {
        IT::$pass += (int) $m[1];
        IT::$fail += (int) $m[2];
        if ( (int) $m[2] > 0 ) {
            IT::$failures[] = 'coexistence: ' . $m[2] . ' failed check(s), see above';
        }
    } elseif ( preg_match( '/^Not run: (.+)$/m', $text, $m ) ) {
        echo "  (coexistence not run: {$m[1]})\n";
        IT::$failures[] = 'coexistence not run: ' . $m[1];
        IT::$fail++;
    } else {
        IT::check( false, 'coexistence section finished', 'exit ' . $code );
    }
}

echo "\n==============================\n";
printf( "Passed: %d  Failed: %d\n", IT::$pass, IT::$fail );
foreach ( IT::$failures as $f ) {
    echo " - {$f}\n";
}
exit( IT::$fail > 0 ? 1 : 0 );
