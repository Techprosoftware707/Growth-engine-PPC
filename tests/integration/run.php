<?php
/**
 * End-to-end integration run against a real WordPress install.
 *
 * Usage: PPCLG_WP_PATH=/path/to/wordpress php tests/integration/run.php
 *
 * Requirements: the fixture server (tests/fixtures/router.php) on 127.0.0.1:8788 and the mu-plugin
 * that routes *.test hosts to it (see docs/TESTING.md). Exits non-zero when any check fails.
 */

declare(strict_types=1);

$wpPath = getenv( 'PPCLG_WP_PATH' ) ?: '';
if ( '' === $wpPath || ! is_file( rtrim( $wpPath, '/' ) . '/wp-load.php' ) ) {
    fwrite( STDERR, "Set PPCLG_WP_PATH to a WordPress root containing wp-load.php\n" );
    exit( 2 );
}
$_SERVER['HTTP_HOST']      = 'localhost:8787';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
require rtrim( $wpPath, '/' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

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
    public static function step( string $title, callable $fn ): void {
        echo "\n== {$title}\n";
        try {
            $fn();
        } catch ( \Throwable $e ) {
            self::check( false, 'step threw ' . get_class( $e ), $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
        }
    }
}

use PPC\LinkGrowth\Plugin;

$fixtureLog = getenv( 'PPCLG_FIXTURE_LOG' ) ?: sys_get_temp_dir() . '/ppclg-fixture-submissions.log';
@unlink( $fixtureLog );
$GLOBALS['itMail'] = [];
add_filter( 'pre_wp_mail', static function ( $null, array $atts ) {
    $GLOBALS['itMail'][] = $atts;
    return true;
}, 10, 2 );

wp_set_current_user( 1 );

IT::step( 'Activation and schema', static function (): void {
    global $wpdb;
    $plugin = 'ppc-link-growth-engine/ppc-link-growth-engine.php';
    if ( '0' !== (string) getenv( 'PPCLG_RESET' ) ) {
        // Start from a clean plugin state: drop plugin tables and options, deactivate.
        if ( is_plugin_active( $plugin ) ) {
            deactivate_plugins( $plugin );
        }
        foreach ( \PPC\LinkGrowth\Database\Schema::TABLES as $t ) {
            $wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'ppclg_' . $t . '`' ); // phpcs:ignore
        }
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ppclg\_%' OR option_name LIKE '\_transient\_ppclg\_%' OR option_name LIKE '\_transient\_timeout\_ppclg\_%'" ); // phpcs:ignore
        wp_cache_flush();
        IT::check( true, 'plugin state reset' );
    }
    if ( ! is_plugin_active( $plugin ) ) {
        $r = activate_plugin( $plugin );
        IT::check( null === $r, 'activate_plugin returned no error', is_wp_error( $r ) ? $r->get_error_message() : '' );
    }
    IT::check( is_plugin_active( $plugin ), 'plugin active' );
    IT::check( function_exists( 'ppclg' ), 'ppclg() helper defined' );
    $schema = ppclg()->get( \PPC\LinkGrowth\Database\Schema::class );
    IT::check( count( $schema->existingTables() ) === count( \PPC\LinkGrowth\Database\Schema::TABLES ), 'all plugin tables exist', implode( ',', $schema->existingTables() ) );
    IT::check( get_option( 'ppclg_db_version' ) === PPCLG_DB_VERSION, 'db version stored' );
    IT::check( get_role( 'administrator' )->has_cap( 'ppclg_manage' ), 'administrator has ppclg_manage' );
    IT::check( ! ppclg()->get( \PPC\LinkGrowth\Database\Migrator::class )->needsMigration(), 'no pending migration' );
    IT::check( ppclg()->get( \PPC\LinkGrowth\Automation\Scheduler::class )->status()['backend'] !== 'none', 'scheduler registered a backend' );
} );

IT::step( 'Configure for the fixture environment', static function (): void {
    $settings = ppclg()->get( \PPC\LinkGrowth\Support\Settings::class );
    $settings->update( [
        'site'       => [ 'domain' => 'purepeptidescanada.test', 'home_url' => 'https://purepeptidescanada.test' ],
        'advanced'   => [ 'crawl_delay_seconds' => 0, 'log_level' => 'debug' ],
        'providers'  => [ 'rdap' => [ 'enabled' => false ], 'seranking' => [ 'enabled' => false ] ],
        'discovery'  => [ 'seed_catalog' => false, 'brand_terms' => [ 'Pure Peptides Canada', 'purepeptidescanada.test' ] ],
        'automation' => [ 'enabled' => true, 'submissions' => false, 'outreach_autosend' => false, 'daily_limits' => [ 'evaluations' => 50, 'submissions' => 5, 'outreach_emails' => 10, 'backlink_checks' => 100, 'discovery_queries' => 10, 'provider_lookups' => 20 ] ],
        'outreach'   => [ 'sender_name' => 'Test Sender', 'sender_email' => 'outreach@purepeptidescanada.test', 'postal_address' => '222 Jarvis St, Toronto, ON M5B 2B8', 'signature' => 'Test Sender, Pure Peptides Canada' ],
        'monitoring' => [ 'notify_email' => 'admin@example.com' ],
    ] );
    IT::check( 'purepeptidescanada.test' === $settings->siteDomain(), 'site domain configured' );
    // Register an offline search provider returning fixture pages.
    add_filter( 'ppclg_search_providers', static function ( array $list ): array {
        $list[] = new class() implements \PPC\LinkGrowth\Integrations\Contracts\SearchProvider {
            public function id(): string { return 'fixture_search'; }
            public function label(): string { return 'Fixture search'; }
            public function isConfigured(): bool { return true; }
            public function remainingToday(): int { return 999; }
            public function lastError(): string { return ''; }
            public function search( string $query, array $opts = [] ): array {
                if ( str_contains( $query, 'Pure Peptides Canada' ) || str_contains( $query, 'purepeptidescanada' ) ) {
                    return [
                        [ 'url' => 'https://mention.test/blog/canadian-peptide-suppliers/', 'title' => 'Comparing Canadian research peptide suppliers', 'snippet' => 'Pure Peptides Canada lists Janoshik reports', 'position' => 1 ],
                        [ 'url' => 'https://mention.test/blog/linked/', 'title' => 'Where we buy reagents', 'snippet' => '', 'position' => 2 ],
                    ];
                }
                return [
                    [ 'url' => 'https://labdirectory.test/add-listing/', 'title' => 'Add your laboratory business | LabDirectory Canada', 'snippet' => 'Submit a free listing', 'position' => 1 ],
                    [ 'url' => 'https://resources.test/links/', 'title' => 'Laboratory resources and links for students', 'snippet' => 'Useful links', 'position' => 2 ],
                    [ 'url' => 'https://spam.test/', 'title' => 'Best Online Casino Bonus', 'snippet' => 'buy backlinks', 'position' => 3 ],
                    [ 'url' => 'https://facebook.com/labgroup', 'title' => 'Facebook group', 'snippet' => '', 'position' => 4 ],
                ];
            }
        };
        return $list;
    } );
    $registry = ppclg()->get( \PPC\LinkGrowth\Integrations\ProviderRegistry::class );
    IT::check( null !== $registry->primarySearch() && 'fixture_search' === $registry->primarySearch()->id(), 'fixture search provider active' );
} );

IT::step( 'Discovery via search', static function (): void {
    $pipeline = ppclg()->get( \PPC\LinkGrowth\Automation\Pipeline::class );
    $r        = $pipeline->runNow( 'discover', [ 'max_queries' => 2, 'skip_competitors' => true ], 25 );
    IT::check( isset( $r['result'] ), 'discover job ran', wp_json_encode( $r ) );
    $prospects = ppclg()->get( \PPC\LinkGrowth\Repositories\Prospects::class );
    IT::check( null !== $prospects->findByDomain( 'labdirectory.test' ), 'labdirectory.test discovered' );
    IT::check( null !== $prospects->findByDomain( 'resources.test' ), 'resources.test discovered' );
    IT::check( null !== $prospects->findByDomain( 'spam.test' ), 'spam.test discovered (to be rejected later)' );
    IT::check( null === $prospects->findByDomain( 'facebook.com' ), 'excluded platform skipped' );
    $manual = ppclg()->get( \PPC\LinkGrowth\Discovery\ProspectIntake::class )->manual( 'https://mention.test/', 'added in integration test', 1 );
    IT::check( $manual && $manual['prospect_id'] > 0, 'manual prospect added' );
} );

IT::step( 'Evaluation and scoring', static function (): void {
    $pipeline  = ppclg()->get( \PPC\LinkGrowth\Automation\Pipeline::class );
    $prospects = ppclg()->get( \PPC\LinkGrowth\Repositories\Prospects::class );
    $r = $pipeline->runNow( 'evaluate', [], 28 );
    IT::check( isset( $r['result'] ), 'evaluate job ran', wp_json_encode( $r ) );
    $lab = $prospects->findByDomain( 'labdirectory.test' );
    IT::check( 'qualified' === $lab['status'], 'labdirectory.test qualified', 'status=' . $lab['status'] . ' reason=' . $lab['rejection_reason'] );
    IT::check( (int) $lab['score'] >= 60, 'labdirectory.test score >= 60', 'score=' . $lab['score'] );
    IT::check( 'CA' === $lab['country'], 'country detected CA' );
    IT::check( 'https://labdirectory.test/add-listing/' === $lab['submission_url'], 'submission page found', (string) $lab['submission_url'] );
    IT::check( ! empty( $lab['profile']['submission_form'] ), 'submission form stored in profile' );
    IT::check( '' !== $lab['recommended_action'], 'recommended action set' );
    IT::check( is_array( $lab['score_breakdown'] ) && isset( $lab['score_breakdown']['components']['topical_relevance'] ), 'score breakdown stored' );
    $contacts = ppclg()->get( \PPC\LinkGrowth\Repositories\Contacts::class )->forProspect( (int) $lab['id'] );
    IT::check( in_array( 'editor@labdirectory.test', array_column( $contacts, 'email' ), true ), 'editor contact extracted' );
    $spam = $prospects->findByDomain( 'spam.test' );
    IT::check( 'rejected' === $spam['status'], 'spam.test rejected', 'status=' . $spam['status'] );
    $audit = ppclg()->get( \PPC\LinkGrowth\Repositories\AuditLog::class )->count( [ 'action' => 'prospect.evaluated' ] );
    IT::check( $audit >= 3, 'evaluation audited', (string) $audit );
} );

IT::step( 'Opportunities, broken links and mention scan', static function (): void {
    $pipeline  = ppclg()->get( \PPC\LinkGrowth\Automation\Pipeline::class );
    $prospects = ppclg()->get( \PPC\LinkGrowth\Repositories\Prospects::class );
    $opps      = ppclg()->get( \PPC\LinkGrowth\Repositories\Opportunities::class );
    $pipeline->runNow( 'find_opportunities', [], 25 );
    $lab = $prospects->findByDomain( 'labdirectory.test' );
    IT::check( $opps->count( [ 'prospect_id' => (int) $lab['id'] ] ) >= 1, 'listing opportunity exists for labdirectory.test' );
    $res = $prospects->findByDomain( 'resources.test' );
    $pipeline->runNow( 'broken_link_scan', [ 'prospect_id' => (int) $res['id'] ], 25 );
    IT::check( $opps->count( [ 'prospect_id' => (int) $res['id'], 'type' => 'broken_link' ] ) >= 1, 'broken-link opportunities for resources.test' );
    $pipeline->runNow( 'mention_scan', [], 25 );
    $mention = $prospects->findByDomain( 'mention.test' );
    IT::check( $opps->count( [ 'prospect_id' => (int) $mention['id'], 'type' => 'unlinked_mention' ] ) >= 1, 'unlinked mention opportunity created' );
    $backlinks = ppclg()->get( \PPC\LinkGrowth\Repositories\Backlinks::class );
    IT::check( count( $backlinks->forDomain( 'mention.test' ) ) >= 1, 'linked mention recorded as backlink' );
} );

IT::step( 'Submission packaging, authorized submission and verification', static function () use ( $fixtureLog ): void {
    $engine    = ppclg()->get( \PPC\LinkGrowth\Submission\SubmissionEngine::class );
    $prospects = ppclg()->get( \PPC\LinkGrowth\Repositories\Prospects::class );
    $subs      = ppclg()->get( \PPC\LinkGrowth\Repositories\Submissions::class );
    $settings  = ppclg()->get( \PPC\LinkGrowth\Support\Settings::class );
    $lab       = $prospects->findByDomain( 'labdirectory.test' );
    $sid       = $engine->packageProspect( (int) $lab['id'], 1 );
    IT::check( is_int( $sid ) && $sid > 0, 'package created', var_export( $sid, true ) );
    $sub = $subs->find( (int) $sid );
    IT::check( in_array( $sub['status'], [ 'needs_manual', 'pending_review' ], true ), 'package queued for human review while automation not authorized', $sub['status'] );
    $fields = array_column( (array) ( $sub['payload']['fields'] ?? [] ), 'value', 'name' );
    IT::check( ( $fields['business_name'] ?? '' ) === 'Pure Peptides Canada', 'business name mapped', wp_json_encode( $fields ) );
    IT::check( ( $fields['website'] ?? '' ) === 'https://purepeptidescanada.test', 'website mapped', (string) ( $fields['website'] ?? '' ) );
    IT::check( ( $fields['category'] ?? '' ) === '18', 'category matched to Scientific Supplies & Reagents', (string) ( $fields['category'] ?? '' ) );
    IT::check( ( $fields['province'] ?? '' ) === 'ON', 'province matched', (string) ( $fields['province'] ?? '' ) );
    IT::check( ! empty( $sub['payload']['content']['description'] ) && stripos( $sub['payload']['content']['description'], 'research use' ) !== false, 'description generated with research-use positioning' );
    IT::check( empty( $sub['payload']['compliance']['violations'] ), 'package passes claims filter' );
    IT::check( empty( $sub['payload']['unmapped_required'] ), 'no unmapped required fields', wp_json_encode( $sub['payload']['unmapped_required'] ?? [] ) );
    IT::check( $engine->packageProspect( (int) $lab['id'], 1 ) === $sid, 'duplicate package prevented' );

    // Authorize automation explicitly and process.
    $prospects->update( (int) $lab['id'], [ 'automation_allowed' => 1 ] );
    $settings->set( 'automation.submissions', true );
    $engine->rebuildPackage( (int) $sid, 1 );
    $approved = $engine->approve( (int) $sid, 1 );
    IT::check( $approved, 'submission approved for automated processing' );
    $r = $engine->processQueue( \PPC\LinkGrowth\Support\TimeBudget::seconds( 25 ), 5 );
    IT::check( ( $r['submitted'] ?? 0 ) === 1, 'form submitted automatically', wp_json_encode( $r ) );
    $sub = $subs->find( (int) $sid );
    IT::check( 'submitted' === $sub['status'], 'submission status submitted', $sub['status'] . ' ' . (string) $sub['last_error'] );
    $log = is_file( $fixtureLog ) ? file_get_contents( $GLOBALS['fixtureLog'] ) : '';
    IT::check( str_contains( $log, '"business_name":"Pure Peptides Canada"' ), 'fixture server received the POST', substr( $log, 0, 300 ) );
    IT::check( str_contains( $log, '"form_token":"tok-7f3a9c"' ), 'hidden token preserved' );
    IT::check( ! str_contains( $log, '"newsletter":"1"' ), 'newsletter checkbox NOT ticked' );
    IT::check( str_contains( $log, '"accuracy":"1"' ), 'accuracy/terms consent ticked' );
    IT::check( str_contains( $log, 'PPCLinkGrowthBot' ), 'honest user agent sent' );

    // Verification via known listing URL.
    $subs->update( (int) $sid, [ 'listing_url' => 'https://labdirectory.test/listing/pure-peptides-canada/', 'next_verify_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
    $v = $engine->verifyDue( \PPC\LinkGrowth\Support\TimeBudget::seconds( 20 ), 5 );
    IT::check( ( $v['confirmed'] ?? 0 ) === 1, 'listing verified', wp_json_encode( $v ) );
    IT::check( 'confirmed' === $subs->find( (int) $sid )['status'], 'submission confirmed' );
    $bl = ppclg()->get( \PPC\LinkGrowth\Repositories\Backlinks::class )->forDomain( 'labdirectory.test' );
    IT::check( count( $bl ) >= 1, 'backlink recorded from listing' );
    IT::check( 'acquired' === $prospects->findByDomain( 'labdirectory.test' )['status'], 'prospect marked acquired' );
} );

IT::step( 'Backlink monitoring', static function (): void {
    $monitor   = ppclg()->get( \PPC\LinkGrowth\Monitoring\BacklinkMonitor::class );
    $backlinks = ppclg()->get( \PPC\LinkGrowth\Repositories\Backlinks::class );
    $id        = $monitor->addManual( 'https://labdirectory.test/listing/pure-peptides-canada/', 'https://purepeptidescanada.test/lab-testing/', 1 );
    IT::check( $id > 0, 'manual backlink added' );
    $r = $monitor->check( $backlinks->find( $id ) );
    IT::check( 'active' === $r['status'], 'nofollow lab-testing link detected active', wp_json_encode( $r ) );
    $row = $backlinks->find( $id );
    IT::check( str_contains( (string) $row['rel'], 'nofollow' ), 'rel recorded as nofollow', (string) $row['rel'] );
    IT::check( 'Lab Testing' === $row['anchor_text'], 'anchor text recorded', (string) $row['anchor_text'] );
    $dead = $monitor->addManual( 'https://deadlab.test/old-listing/', 'https://purepeptidescanada.test/', 1 );
    $monitor->check( $backlinks->find( $dead ) );
    $monitor->check( $backlinks->find( $dead ) );
    IT::check( in_array( $backlinks->find( $dead )['status'], [ 'lost', 'broken' ], true ), 'dead page marked lost/broken after two strikes', $backlinks->find( $dead )['status'] );
    $notes = ppclg()->get( \PPC\LinkGrowth\Repositories\Notifications::class )->count( [ 'severity' => 'critical' ] );
    IT::check( $notes >= 1, 'critical notification raised for lost backlink' );
    $due = $monitor->checkDue( \PPC\LinkGrowth\Support\TimeBudget::seconds( 20 ), 10 );
    IT::check( isset( $due['checked'] ), 'checkDue returns stats' );
    $alerts = array_values( array_filter( $GLOBALS['itMail'], static fn( $m ) => stripos( (string) $m['subject'], 'lost' ) !== false || stripos( (string) $m['subject'], 'backlink' ) !== false ) );
    IT::check( count( $alerts ) >= 1, 'immediate email alert sent for the lost backlink', wp_json_encode( array_column( $GLOBALS['itMail'], 'subject' ) ) );
    $GLOBALS['itMail'] = [];
} );

IT::step( 'Outreach drafting, approval, sending, replies and opt-out', static function (): void {
    $GLOBALS['itMail'] = [];
    $mail = &$GLOBALS['itMail'];
    $outreach  = ppclg()->get( \PPC\LinkGrowth\Outreach\OutreachEngine::class );
    $messages  = ppclg()->get( \PPC\LinkGrowth\Repositories\OutreachMessages::class );
    $prospects = ppclg()->get( \PPC\LinkGrowth\Repositories\Prospects::class );
    $lab       = $prospects->findByDomain( 'labdirectory.test' );
    $cid       = $outreach->createCampaign( 'Resource page test', 'resource_page', [ 'daily_limit' => 5 ], 1 );
    IT::check( $cid > 0, 'campaign created' );
    $built = $outreach->buildDrafts( $cid, [ (int) $lab['id'] ], 1 );
    IT::check( ( $built['created'] ?? 0 ) === 1, 'draft created', wp_json_encode( $built ) );
    $draft = $messages->findOneBy( [ 'campaign_id' => $cid ] );
    IT::check( str_contains( $draft['body'], '222 Jarvis St' ), 'postal address in footer' );
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
    IT::check( stripos( $headers, 'From: Test Sender <outreach@purepeptidescanada.test>' ) !== false, 'From header uses sender identity', $headers );
    $sent = $messages->find( (int) $draft['id'] );
    IT::check( 'sent' === $sent['status'] && '' !== $sent['message_id'], 'message marked sent with message id' );
    $dup = $outreach->buildDrafts( $cid, [ (int) $lab['id'] ], 1 );
    IT::check( ( $dup['created'] ?? 0 ) === 0, 'no duplicate draft for the same contact/approach' );

    $inbound = ppclg()->get( \PPC\LinkGrowth\Outreach\InboundWebhook::class );
    ppclg()->get( \PPC\LinkGrowth\Support\Secrets::class )->set( 'inbound_webhook_secret', 'hook-secret' );
    $bad = $inbound->handle( [ 'from' => 'editor@labdirectory.test', 'subject' => 'Re: x', 'text' => 'thanks', 'in_reply_to' => '<' . $sent['message_id'] . '>' ], 'wrong' );
    IT::check( empty( $bad['ok'] ), 'inbound webhook rejects wrong secret' );
    $good = $inbound->handle( [ 'from' => 'editor@labdirectory.test', 'subject' => 'Re: x', 'text' => 'Happy to add you.', 'in_reply_to' => '<' . $sent['message_id'] . '>' ], 'hook-secret' );
    IT::check( ! empty( $good['ok'] ) && ( $good['matched'] ?? 0 ) === 1, 'reply matched by message id', wp_json_encode( $good ) );
    IT::check( 'replied' === $messages->find( (int) $draft['id'] )['status'], 'message marked replied' );

    $unsub = ppclg()->get( \PPC\LinkGrowth\Outreach\Unsubscribe::class )->process( (string) $draft['unsubscribe_token'] );
    IT::check( ! empty( $unsub['ok'] ) && 'editor@labdirectory.test' === $unsub['email'], 'unsubscribe processed', wp_json_encode( $unsub ) );
    IT::check( ppclg()->get( \PPC\LinkGrowth\Repositories\Suppression::class )->isSuppressed( 'email', 'editor@labdirectory.test' ), 'email suppressed after opt-out' );
    $compliance = ppclg()->get( \PPC\LinkGrowth\Outreach\Compliance::class );
    IT::check( $compliance->senderReady()['ok'], 'sender identity ready' );
} );

IT::step( 'REST API', static function (): void {
    $do = static function ( string $method, string $route, array $params = [] ) {
        $req = new WP_REST_Request( $method, '/ppclg/v1' . $route );
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
    IT::check( 7 === (int) ppclg()->get( \PPC\LinkGrowth\Support\Settings::class )->get( 'automation.daily_limits.submissions' ), 'setting persisted' );
    IT::check( ppclg()->get( \PPC\LinkGrowth\Support\Secrets::class )->get( 'brave_api_key' ) === 'brave-123456', 'secret stored encrypted' );
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

IT::step( 'Scheduler tick, limits and backups', static function (): void {
    $pipeline = ppclg()->get( \PPC\LinkGrowth\Automation\Pipeline::class );
    $tick     = $pipeline->tick();
    IT::check( is_array( $tick ), 'tick ran', wp_json_encode( $tick ) );
    IT::check( '' !== (string) get_option( 'ppclg_last_tick', '' ), 'last tick recorded' );
    $limits = ppclg()->get( \PPC\LinkGrowth\Support\Limits::class )->snapshot();
    IT::check( ( $limits['submissions']['used_today'] ?? 0 ) === 1, 'submission limit counter incremented', wp_json_encode( $limits['submissions'] ?? null ) );
    IT::check( ( $limits['outreach_emails']['used_today'] ?? 0 ) === 1, 'outreach limit counter incremented' );
    $backup = ppclg()->get( \PPC\LinkGrowth\Database\Backup::class );
    $file   = $backup->dump( ppclg()->get( \PPC\LinkGrowth\Database\Schema::class )->existingTables(), 'integration' );
    IT::check( null !== $file && is_file( $file ) && filesize( $file ) > 100, 'backup dump created' );
    $status = $pipeline->status();
    IT::check( isset( $status['queue'], $status['limits'], $status['flags'] ), 'pipeline status shape' );
    $logs = ppclg()->get( \PPC\LinkGrowth\Repositories\Logs::class )->count( [ 'level' => 'error' ] );
    IT::check( 0 === $logs, 'no error-level log entries during the run', (string) $logs );
    if ( $logs > 0 ) {
        foreach ( ppclg()->get( \PPC\LinkGrowth\Repositories\Logs::class )->findBy( [ 'level' => 'error' ], 'id', 'DESC', 10 ) as $row ) {
            echo "    error log: {$row['channel']}: {$row['message']}\n";
        }
    }
} );

echo "\n==============================\n";
printf( "Passed: %d  Failed: %d\n", IT::$pass, IT::$fail );
foreach ( IT::$failures as $f ) {
    echo " - {$f}\n";
}
exit( IT::$fail > 0 ? 1 : 0 );
