<?php
/**
 * 1.2.0 Build B — discovery without a search key, and the Grow run under Autopilot:
 * - with Autopilot on, no owner competitor list and SE Ranking configured, competitors are found automatically (at
 *   most once every 30 days, one provider lookup), giants / media / government / health and the site itself are left
 *   out, the result is kept apart from the owner's list, a removed domain never comes back, and the backlink scan
 *   mines owner + automatic lists; the discover step says so plainly;
 * - no search key and no usable provider: "Needs you" names the one key that unlocks discovery;
 * - under Autopilot the step labels drop "for your approval" / "applied only by you", "Needs you" lists only
 *   genuine blockers (no Search Console / metrics / SEO-issue / link-review items; owner tasks with the Submitter
 *   role hint; credits exhausted) and the summary never says drafts await approval.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Growth;

use PPC\LinkGrowth\Analysis\Taxonomy;
use PPC\LinkGrowth\Automation\Autopilot;
use PPC\LinkGrowth\Automation\Jobs\DiscoverJob;
use PPC\LinkGrowth\Compliance\StorefrontAudit;
use PPC\LinkGrowth\Discovery\CompetitorBacklinkSource;
use PPC\LinkGrowth\Growth\GrowthRun;
use PPC\LinkGrowth\Integrations\Contracts\BacklinkSource;
use PPC\LinkGrowth\Integrations\ProviderRegistry;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Seo\SiteAuditor;
use PPC\LinkGrowth\Support\Limits;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;

require_once __DIR__ . '/GrowthFakes.php';

/** A configured backlink provider that can list competitors (stands in for SE Ranking). */
final class FakeCompetitorProvider implements BacklinkSource {

    /** @var string[] */
    public array $competitors = [];

    public string $error = '';

    public int $competitorCalls = 0;

    /** @var string[] */
    public array $mined = [];

    public function id(): string {
        return 'fake_rank';
    }

    public function label(): string {
        return 'SE Ranking';
    }

    public function isConfigured(): bool {
        return true;
    }

    public function lastError(): string {
        return $this->error;
    }

    /** @return string[] */
    public function competitors( string $domain ): array {
        $this->competitorCalls++;
        return '' !== $this->error ? [] : $this->competitors;
    }

    public function backlinks( string $domain, int $limit = 200, int $perDomain = 3 ): array {
        $this->mined[] = $domain;
        return [];
    }
}

final class AutopilotDiscoveryTest extends TestCase {

    private FakeCompetitorProvider $provider;

    protected function setUp(): void {
        parent::setUp();
        GrowthFakeJob::reset();
        $this->provider = new FakeCompetitorProvider();
        $this->provider->competitors = [
            'amazon.ca', 'en.wikipedia.org', 'cbc.ca', 'canada.ca', 'hc-sc.gc.ca', 'purepeptidescanada.test', 'www.rxpharmacycanada.com', 'peptidehealthclinic.ca',
            'canadalabsupply.com', 'www.northernbioreagents.ca', 'reddit.com', 'labpeptides-direct.com',
        ];
        $this->settings()->set( 'discovery.seed_catalog', false );
    }

    private function settings(): Settings {
        return Container::get( Settings::class );
    }

    private function withProvider(): void {
        $provider = $this->provider;
        $GLOBALS['ppclg_test_filters']['ppclg_backlink_sources'] = [ static fn( array $list ): array => array_merge( $list, [ $provider ] ) ];
    }

    private function source(): CompetitorBacklinkSource {
        return new CompetitorBacklinkSource( Container::get( ProviderRegistry::class ), $this->settings(), Container::get( Logger::class ), Container::get( Limits::class ), Container::get( Taxonomy::class ), Container::get( AuditLog::class ) );
    }

    // ------------------------------------------------------------------ competitor detection

    public function testAutopilotFindsRealCompetitorsThroughSeRankingAndKeepsThemApart(): void {
        $this->withProvider();
        $this->settings()->set( 'automation.autopilot', true );
        $src = $this->source();

        $res = $src->autoDetect();
        $this->assertIsArray( $res );
        $this->assertTrue( $res['ran'] );
        $this->assertSame( [ 'canadalabsupply.com', 'northernbioreagents.ca', 'labpeptides-direct.com' ], $res['added'] );
        foreach ( [ 'amazon.ca', 'wikipedia.org', 'cbc.ca', 'canada.ca', 'hc-sc.gc.ca', 'purepeptidescanada.test', 'rxpharmacycanada.com', 'peptidehealthclinic.ca', 'reddit.com' ] as $d ) {
            $this->assertArrayHasKey( $d, $res['filtered'], $d );
        }
        $this->assertSame( 'health, medical or pharmacy site', $res['filtered']['rxpharmacycanada.com'] );
        $this->assertSame( 'your own site', $res['filtered']['purepeptidescanada.test'] );
        $this->assertStringContainsString( 'Found 3 competitors automatically through SE Ranking: canadalabsupply.com, northernbioreagents.ca, labpeptides-direct.com', $res['message'] );

        // Kept apart from the owner's list; the backlink scan uses both lists.
        $this->assertSame( [], $this->settings()->get( 'discovery.competitor_domains' ) );
        $this->assertSame( $res['added'], $this->settings()->get( 'discovery.competitor_domains_auto' ) );
        $this->assertSame( $res['added'], $src->competitors() );
        $this->assertSame( 1, Container::get( Limits::class )->used( 'provider_lookups' ), 'one provider lookup' );
        $audit = Container::get( AuditLog::class )->findBy( [ 'action' => 'discovery.competitors_detected' ] );
        $this->assertSame( Autopilot::ACTOR, $audit[0]['actor'] );

        // At most once every 30 days.
        $this->assertNull( $src->autoDetect() );
        $this->assertSame( 1, $this->provider->competitorCalls );

        // A domain the owner removed is never added back.
        $this->settings()->set( 'discovery.competitor_domains_auto', [ 'canadalabsupply.com', 'labpeptides-direct.com' ] );
        $again = $src->autoDetect( true );
        $this->assertSame( [], $again['added'] );
        $this->assertSame( 'removed by you earlier', $again['filtered']['northernbioreagents.ca'] );
        $this->assertSame( [ 'canadalabsupply.com', 'labpeptides-direct.com' ], $this->settings()->get( 'discovery.competitor_domains_auto' ) );

        $this->settings()->set( 'discovery.competitor_domains', [ 'rival.ca' ] );
        $this->assertSame( [ 'rival.ca', 'canadalabsupply.com', 'labpeptides-direct.com' ], $src->competitors() );
        $this->assertNull( $src->autoDetect( true ), 'the owner listed competitors: nothing is detected' );
    }

    public function testDetectionNeedsAutopilotAProviderAndProviderLookupsLeft(): void {
        $src = $this->source();
        $this->settings()->set( 'automation.autopilot', true );
        $this->assertNull( $src->autoDetect(), 'no provider that lists competitors' );
        $this->withProvider();
        $this->settings()->set( 'automation.autopilot', false );
        $this->assertNull( $src->autoDetect(), 'Autopilot off' );

        $this->settings()->set( 'automation.autopilot', true );
        $this->settings()->set( 'automation.daily_limits.provider_lookups', 1 );
        Container::get( Limits::class )->spend( 'provider_lookups' );
        $res = $src->autoDetect();
        $this->assertFalse( $res['ran'] );
        $this->assertSame( 'provider_lookup_limit', $res['reason'] );
        $this->assertStringContainsString( 'provider lookup limit is used up', $res['message'] );
        $this->assertSame( 0, $this->provider->competitorCalls );

        $this->settings()->set( 'automation.daily_limits.provider_lookups', 60 );
        $this->provider->error = 'HTTP 500 upstream';
        $failed = $src->autoDetect();
        $this->assertSame( 'provider_error', $failed['reason'] );
        $this->assertNotSame( '', (string) get_option( CompetitorBacklinkSource::DETECT_RETRY_OPTION, '' ) );
        $this->assertNull( $src->autoDetect(), 'retried only after the back-off' );
    }

    public function testTheDiscoverStepReportsAutomaticCompetitorsPlainly(): void {
        $this->withProvider();
        $this->settings()->set( 'automation.autopilot', true );
        Container::plugin()->container()->set( CompetitorBacklinkSource::class, $this->source() );
        $res = ( new DiscoverJob() )->handle( [], TimeBudget::seconds( 30 ), Container::plugin()->container() );
        $this->assertStringContainsString( 'No search key is set, so no search queries ran', $res['summary'] );
        $this->assertStringContainsString( 'Found 3 competitors automatically through SE Ranking', $res['summary'] );
        $this->assertStringContainsString( 'Mined the backlinks of 3 competitors', $res['summary'] );
        $this->assertSame( [ 'canadalabsupply.com', 'northernbioreagents.ca', 'labpeptides-direct.com' ], $this->provider->mined );
        $this->assertSame( '', DiscoverJob::summaryFor( [ 'queries_run' => 2, 'competitors' => null, 'competitor_detection' => null ], [] ), 'unchanged without automatic competitors' );
    }

    // ------------------------------------------------------------------ Grow now under Autopilot

    private function growNow(): array {
        $GLOBALS['ppclg_test_filters']['ppclg_job_types'] = [ static fn() => GrowthFakeJob::registry( [ 'import_verified' ] ) ];
        Container::plugin()->container()->set( StorefrontAudit::class, new FakeStorefront() );
        $growth = Container::get( GrowthRun::class );
        $growth->start( 1 );
        $state = $growth->state();
        for ( $n = 0; $n < 10 && GrowthRun::STATUS_RUNNING === $state['status']; $n++ ) {
            $state = $growth->advance( TimeBudget::seconds( 60.0 ) );
        }
        return $state;
    }

    private function seedWork(): void {
        $subs = Container::get( Submissions::class );
        foreach ( [ 'needs_manual', 'needs_manual', 'pending_review' ] as $n => $status ) {
            $subs->insert( [ 'prospect_id' => $n + 1, 'status' => $status, 'target_url' => 'https://dir' . $n . '.test/add', 'target_hash' => sha1( (string) $n ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ] );
        }
        Container::get( OutreachMessages::class )->insert( [ 'prospect_id' => 1, 'contact_id' => 1, 'approach' => 'resource_page', 'status' => 'draft', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ] );
        Container::plugin()->container()->set( SiteAuditor::class, new class() {
            /** @return array<string,mixed> */
            public function summary(): array {
                return [ 'checked_at' => '2026-11-12T10:00:00+00:00', 'pages' => 40, 'complete' => true, 'counts' => [ 'high' => 2, 'medium' => 1, 'low' => 0 ], 'issues_total' => 3 ];
            }
        } );
    }

    public function testUnderAutopilotNeedsYouListsOnlyGenuineBlockers(): void {
        $this->seedWork();
        $this->settings()->set( 'automation.autopilot', true );
        $state  = $this->growNow();
        $labels = array_column( $state['steps'], 'label', 'key' );
        $this->assertSame( 'Add the best internal links automatically', $labels['internal_links'] );
        $this->assertSame( 'Submit listings that pass every safety check', $labels['submit'] );
        foreach ( $labels as $label ) {
            $this->assertStringNotContainsString( 'for your approval', $label );
            $this->assertStringNotContainsString( 'applied only by you', $label );
        }

        $byKey = array_column( $state['next_actions'], null, 'key' );
        // No discovery source at all: the one key that unlocks discovery under Autopilot.
        $this->assertStringContainsString( 'Add your SE Ranking API key', $byKey['discovery_source']['label'] );
        $this->assertStringContainsString( 'Link Growth Submitter role', $byKey['owner_tasks']['label'] );
        $this->assertArrayHasKey( 'sender_identity', $byKey, 'outreach cannot be sent without a sender: a real blocker' );
        foreach ( [ 'google', 'metrics_provider', 'seo_issues', 'link_suggestions', 'pending_review', 'outreach_drafts', 'search_provider' ] as $key ) {
            $this->assertArrayNotHasKey( $key, $byKey, $key );
        }
        $summary = '';
        foreach ( $state['steps'] as $step ) {
            $summary = 'summary' === $step['key'] ? (string) $step['summary'] : $summary;
        }
        $this->assertStringNotContainsString( 'await your approval', $summary );
        $this->assertStringContainsString( 'Autopilot approves outreach drafts', $summary );
        $this->assertStringContainsString( 'Internal links are added automatically (up to 20 a day', $summary );
    }

    public function testWithAProviderThatListsCompetitorsAutopilotNeedsNoSearchKeyButReportsExhaustedCredits(): void {
        $this->withProvider();
        $this->settings()->set( 'automation.autopilot', true );
        $byKey = array_column( $this->growNow()['next_actions'], null, 'key' );
        $this->assertArrayNotHasKey( 'discovery_source', $byKey );
        $this->assertArrayNotHasKey( 'search_provider', $byKey );

        set_transient( 'ppclg_seranking_balance', [ 'v' => [ 'status' => 'active', 'units_limit' => 1000, 'units_left' => 0 ] ], 600 );
        $byKey = array_column( $this->growNow()['next_actions'], null, 'key' );
        $this->assertStringContainsString( 'SE Ranking has no API credits left', $byKey['provider_credits']['label'] );
        $this->assertStringContainsString( 'Add a Serper.dev API key', $byKey['discovery_source']['label'], 'credits gone: the provider is no longer a usable discovery source' );
    }

    public function testWithoutAutopilotTheRunStillAsksForApprovalAsBefore(): void {
        $this->seedWork();
        $state  = $this->growNow();
        $labels = array_column( $state['steps'], 'label', 'key' );
        $this->assertSame( 'Suggest internal links (applied only by you)', $labels['internal_links'] );
        $this->assertSame( 'Draft outreach for your approval', $labels['auto_outreach'] );
        $byKey = array_column( $state['next_actions'], null, 'key' );
        foreach ( [ 'pending_review', 'outreach_drafts', 'seo_issues', 'google', 'metrics_provider', 'discovery_source' ] as $key ) {
            $this->assertArrayHasKey( $key, $byKey, $key );
        }
        $this->assertStringNotContainsString( 'Submitter', $byKey['owner_tasks']['label'] );
        $this->assertSame( 'Add the best internal links automatically', array_column( GrowthRun::plan( false, true ), 'label', 'key' )['internal_links'], 'seo.auto_apply_links alone changes only that label' );
    }

    public function testAutopilotStatusListsWhatRunsWithTheCaps(): void {
        $this->settings()->update( [ 'automation' => [ 'daily_limits' => [ 'outreach_emails' => 10, 'submissions' => 5 ], 'weekly_limits' => [ 'outreach_emails' => 40 ] ], 'seo' => [ 'auto_apply_per_day' => 20 ] ] );
        $status = Container::get( Autopilot::class )->status();
        $runs   = implode( "\n", $status['will_run'] );
        $this->assertStringContainsString( 'up to 10 a day and 40 a week', $runs );
        $this->assertStringContainsString( 'Add up to 20 internal links a day', $runs );
        $this->assertStringContainsString( 'open public forms that allow it', $runs );
        $this->assertStringContainsString( 'Discover new prospects, evaluate them and monitor your backlinks', $runs );
        $this->assertSame( 20, $status['effective']['links_per_day'] );
        $this->assertFalse( $status['effective']['auto_apply_links'] );
        $this->settings()->set( 'automation.autopilot', true );
        $this->assertTrue( Container::get( Autopilot::class )->status()['effective']['auto_apply_links'] );
    }
}
