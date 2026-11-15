<?php
/**
 * 1.2.0 review repairs for Autopilot outreach (each test failed on the first 1.2.0 build):
 * - a sender that is not ready no longer locks drafts as "needs editing", and fixing it approves them at once;
 * - a parked sender domain with only a parking A record pauses sending; the Reply-To domain is checked too;
 * - a background run that started before Autopilot was switched off can neither approve nor send afterwards, and a
 *   background settings write never puts the old switch back;
 * - without Autopilot, automation never approves drafts in the owner's own campaigns (1.1.0 behaviour);
 * - under Autopilot the owner's campaigns email only sites at the auto-queue score without review; the rest and
 *   follow-ups without reply tracking wait visibly, and follow-ups go out once reply tracking is set up;
 * - creating a campaign continues in the background without drafting into the "Auto:" campaigns;
 * - the next send window is right in half-hour zones; a failed DNS lookup is not repeated on every request;
 * - the Overview / admin notice name the real sender problem.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Outreach;

use PPC\LinkGrowth\Admin\Notices;
use PPC\LinkGrowth\Admin\Rest\AutomationController;
use PPC\LinkGrowth\Admin\Rest\OutreachController;
use PPC\LinkGrowth\Admin\Rest\OverviewController;
use PPC\LinkGrowth\Admin\Rest\SettingsController;
use PPC\LinkGrowth\Automation\Autopilot;
use PPC\LinkGrowth\Automation\JobRunner;
use PPC\LinkGrowth\Database\Schema;
use PPC\LinkGrowth\Integrations\ProviderRegistry;
use PPC\LinkGrowth\Outreach\CampaignFiller;
use PPC\LinkGrowth\Outreach\Compliance;
use PPC\LinkGrowth\Outreach\FollowUps;
use PPC\LinkGrowth\Outreach\OutreachEngine;
use PPC\LinkGrowth\Repositories\Campaigns;
use PPC\LinkGrowth\Repositories\Jobs;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_Error;
use WP_REST_Request;

final class AutopilotRepairsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['ppclg_test_caps']    = [ 'manage_options' => true ];
        $GLOBALS['ppclg_test_user_id'] = 1;
        $this->settings()->update(
            [
                'outreach'   => [
                    'sender_name'       => 'Pure Peptides Canada',
                    'sender_email'      => 'jane@purepeptidescanada.test',
                    'postal_address'    => '222 Jarvis St, Toronto, ON M5B 2B8, Canada',
                    'send_window_start' => 9,
                    'send_window_end'   => 9,
                    'send_days'         => [ 1, 2, 3, 4, 5, 6, 7 ],
                ],
                'automation' => [
                    'outreach_autosend' => true,
                    'daily_limits'      => [ 'outreach_emails' => 10 ],
                    'weekly_limits'     => [ 'outreach_emails' => 40 ],
                ],
            ]
        );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['ppclg_test_timezone'], $GLOBALS['ppclg_test_caps'] );
        $GLOBALS['ppclg_test_user_id'] = 0;
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    private function settings(): Settings {
        return Container::get( Settings::class );
    }

    private function c(): \PPC\LinkGrowth\Container {
        return Container::plugin()->container();
    }

    private function db(): \wpdb {
        return $GLOBALS['wpdb'];
    }

    private function messages(): OutreachMessages {
        return Container::get( OutreachMessages::class );
    }

    /** @param array<string,mixed> $params */
    private function request( string $method, array $params = [] ): WP_REST_Request {
        $r = new WP_REST_Request( $method, '/x' );
        foreach ( $params as $k => $v ) {
            $r->set_param( $k, $v );
        }
        return $r;
    }

    private function site( string $domain, string $type = 'resource_page', int $score = 85, string $status = Prospects::STATUS_QUALIFIED ): int {
        $now = gmdate( 'Y-m-d H:i:s' );
        $this->db()->insert( Schema::table( 'prospects' ), [ 'domain' => $domain, 'name' => $domain, 'status' => $status, 'opportunity_type' => $type, 'score' => $score, 'discovered_via' => 'search', 'first_seen' => $now, 'updated_at' => $now ] );
        $id = (int) $this->db()->insert_id;
        $this->db()->insert( Schema::table( 'contacts' ), [ 'prospect_id' => $id, 'email' => 'editor@' . $domain, 'name' => 'Ed', 'role' => 'editor', 'confidence' => 80, 'consent_basis' => 'conspicuous_publication', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] );
        return $id;
    }

    /** @return array<string,mixed> */
    private function create( string $name = 'Run 1', string $approach = 'resource_page' ): array {
        $res = ( new OutreachController( $this->c() ) )->createCampaign( $this->request( 'POST', [ 'name' => $name, 'approach' => $approach, 'settings' => [ 'daily_limit' => 5 ] ] ) );
        $this->assertIsArray( $res, $res instanceof WP_Error ? $res->get_error_message() : '' );
        return $res;
    }

    /** @return array<int,array<string,mixed>> */
    private function messagesOf( int $campaignId ): array {
        return $this->messages()->findBy( [ 'campaign_id' => $campaignId ], 'id', 'ASC', 100 );
    }

    private function enableAutopilot(): void {
        $res = ( new AutomationController( $this->c() ) )->setAutopilot( $this->request( 'POST', [ 'enabled' => true, 'confirm' => true ] ) );
        $this->assertIsArray( $res );
        $this->assertTrue( $res['enabled'] );
    }

    /** @param array<string,array<string,mixed>> $map */
    private function dns( array $map ): void {
        add_filter( 'ppclg_mail_domain_dns', static fn( $r, string $domain ) => $map[ $domain ] ?? $r, 10, 2 );
    }

    /** What another request (the owner clicking "Turn off Autopilot") stores while this one keeps its cached copy. */
    private function otherRequestStores( string $section, string $key, mixed $value ): void {
        $stored                       = (array) get_option( Settings::OPTION, [] );
        $stored[ $section ][ $key ]   = $value;
        $GLOBALS['ppclg_test_options'][ Settings::OPTION ] = $stored;
    }

    /** The live owner's parked sender: .ca has only parking A records, .com has a Cloudflare MX. */
    private function parkedSender(): void {
        $this->settings()->update( [ 'outreach' => [ 'sender_email' => 'hi@purepeptidescanada.ca' ], 'site' => [ 'email' => 'hi@purepeptidescanada.com' ] ] );
        $this->dns( [ 'purepeptidescanada.ca' => [ 'mx' => false, 'a' => true, 'spf' => false ], 'purepeptidescanada.com' => [ 'mx' => true, 'a' => true, 'spf' => true ] ] );
    }

    // ------------------------------------------------------------------ sender not ready

    public function testAParkingARecordIsNotAMailServer(): void {
        $this->parkedSender();
        $ready = Container::get( Compliance::class )->senderReady();
        $this->assertFalse( $ready['ok'] );
        $this->assertContains( 'sender_email_domain', $ready['missing'] );
        $this->assertStringContainsString( 'purepeptidescanada.ca cannot receive mail (it has no MX record', $ready['problems'][0] );
        $this->assertSame( 'hi@purepeptidescanada.com', $ready['suggestion']['sender_email'] );

        // The Reply-To address actually used for replies must have a mail server too.
        $this->settings()->update( [ 'outreach' => [ 'sender_email' => 'hi@purepeptidescanada.com', 'reply_to' => 'replies@purepeptidescanada.ca' ] ] );
        $reply = ( new Compliance( $this->settings(), Container::get( \PPC\LinkGrowth\Repositories\Suppression::class ), $this->messages(), Container::get( \PPC\LinkGrowth\Content\ClaimsFilter::class ) ) )->senderReady();
        $this->assertFalse( $reply['ok'] );
        $this->assertSame( [ 'reply_to_domain' ], $reply['missing'] );
        $this->assertStringContainsString( 'replies@purepeptidescanada.ca', $reply['problems'][0] );
    }

    public function testABrokenSenderNeverLocksDraftsAndFixingItApprovesThemAtOnce(): void {
        $this->parkedSender();
        $this->site( 'rp-one.ca' );
        $this->site( 'rp-two.ca' );
        $this->enableAutopilot();

        $res = $this->create();
        $id  = (int) $res['campaign_id'];
        $this->assertSame( 0, $res['approved'] );
        $this->assertSame( 'blocked', $res['campaign']['next_step']['state'] );
        foreach ( $this->messagesOf( $id ) as $m ) {
            $this->assertSame( 'draft', $m['status'] );
            $this->assertStringStartsNotWith( 'Not approved:', (string) $m['last_error'], 'a sender problem is not a draft to edit' );
        }
        // An approval attempt while the sender is broken is noted as waiting, not as "needs editing".
        $first = (int) $this->messagesOf( $id )[0]['id'];
        $this->assertFalse( Container::get( OutreachEngine::class )->approveMessage( $first, 0 )['ok'] );
        $this->assertStringStartsWith( OutreachEngine::WAITING_PREFIX, (string) $this->messages()->find( $first )['last_error'] );
        $this->assertFalse( CampaignFiller::needsEdit( (string) $this->messages()->find( $first )['last_error'] ) );
        // A note written by the first 1.2.0 build for the same reason is retried too.
        $this->messages()->update( (int) $this->messagesOf( $id )[1]['id'], [ 'last_error' => 'Not approved: The sender email domain purepeptidescanada.ca cannot receive mail.' ] );

        // "Use hi@purepeptidescanada.com": the waiting campaign and drafts are approved now, not at the next daily run.
        $saved = ( new SettingsController( $this->c() ) )->update( $this->request( 'POST', [ 'settings' => [ 'outreach' => [ 'sender_email' => 'hi@purepeptidescanada.com' ] ] ] ) );
        $this->assertIsArray( $saved );
        $this->assertSame( 2, $saved['approved_now'] );
        $this->assertSame( Campaigns::STATUS_APPROVED, Container::get( Campaigns::class )->find( $id )['status'] );
        $this->assertSame( [ 'approved', 'approved' ], array_column( $this->messagesOf( $id ), 'status' ) );
        $step = Container::get( CampaignFiller::class )->nextStep( Container::get( Campaigns::class )->find( $id ) );
        $this->assertSame( 'sending', $step['state'] );
        $this->assertSame( 2, Container::get( OutreachEngine::class )->sendDue( TimeBudget::seconds( 30.0 ) )['sent'] );
    }

    public function testTheToastFollowsTheCardWhenSendingIsPaused(): void {
        // campaignCreatedText() reads campaign.next_step; the server must hand it the blocked state.
        $this->parkedSender();
        $this->site( 'paused-rp.ca' );
        $this->enableAutopilot();
        $res = $this->create();
        $this->assertTrue( $res['autopilot'] );
        $this->assertSame( 'blocked', $res['campaign']['next_step']['state'] );
        $src = (string) file_get_contents( PPCLG_DIR . 'assets/js/pages/outreach.js' );
        $this->assertStringContainsString( "next.state === 'blocked'", $src );
    }

    // ------------------------------------------------------------------ switching off while a run is in progress

    public function testARunThatStartedBeforeAutopilotWasSwitchedOffCanNeitherApproveNorSend(): void {
        $this->site( 'race-one.ca' );
        $this->site( 'race-two.ca' );
        $this->enableAutopilot();
        $res  = $this->create();
        $msgs = $this->messagesOf( (int) $res['campaign_id'] );
        $this->assertSame( [ 'approved', 'approved' ], array_column( $msgs, 'status' ) );
        // A third draft the running tick has not approved yet.
        $p     = $this->site( 'race-three.ca' );
        $draft = (int) Container::get( OutreachEngine::class )->draftForProspect( (int) $res['campaign_id'], $p, 0 );
        $this->assertGreaterThan( 0, $draft );

        // The owner switches Autopilot off in another request; this one still has "on" cached.
        $this->otherRequestStores( 'automation', 'autopilot', false );
        $this->assertTrue( Container::get( Autopilot::class )->isOn(), 'precondition: the cached copy says on' );

        $this->assertFalse( Container::get( OutreachEngine::class )->approveMessage( $draft, 0 )['ok'], 'no approval after the switch' );
        $this->assertSame( 'draft', $this->messages()->find( $draft )['status'] );

        $sent = Container::get( OutreachEngine::class )->sendDue( TimeBudget::seconds( 30.0 ) );
        $this->assertSame( 0, $sent['sent'], json_encode( $sent ) );
        $this->assertCount( 0, $GLOBALS['ppclg_test_mail'] );
        $this->assertSame( [ 'draft', 'draft' ], [ $this->messages()->find( (int) $msgs[0]['id'] )['status'], $this->messages()->find( (int) $msgs[1]['id'] )['status'] ] );
        $this->assertSame( Autopilot::REVERTED_NOTE, $this->messages()->find( (int) $msgs[0]['id'] )['last_error'] );
    }

    public function testABackgroundSettingsWriteKeepsWhatAnotherRequestChanged(): void {
        $this->settings()->set( 'automation.autopilot', true );
        $tick = new Settings();
        $this->assertTrue( (bool) $tick->get( 'automation.autopilot' ) ); // the tick caches "on"
        $this->otherRequestStores( 'automation', 'autopilot', false );
        $this->otherRequestStores( 'outreach', 'require_approval', true );

        $tick->set( 'discovery.competitor_domains_auto', [ 'rival-lab.ca' ] );

        $fresh = new Settings();
        $this->assertFalse( (bool) $fresh->get( 'automation.autopilot' ), 'switched off stays off' );
        $this->assertTrue( (bool) $fresh->get( 'outreach.require_approval' ) );
        $this->assertSame( [ 'rival-lab.ca' ], $fresh->get( 'discovery.competitor_domains_auto' ) );
    }

    // ------------------------------------------------------------------ Autopilot off = 1.1.0 behaviour

    public function testWithoutAutopilotAutomationNeverApprovesDraftsInTheOwnersCampaigns(): void {
        $this->settings()->update( [ 'outreach' => [ 'require_approval' => false ] ] ); // autosend on, approval off
        $engine = Container::get( OutreachEngine::class );
        $id     = $engine->createCampaign( 'Mine', 'resource_page', [], 1 );
        $this->assertTrue( $engine->approveCampaign( $id, 1 ) );
        $p = $this->site( 'owner-pick.ca' );
        $this->assertSame( 1, $engine->buildDrafts( $id, [ $p ], 1 )['created'] );

        Container::get( JobRunner::class )->runType( 'auto_outreach', [], TimeBudget::seconds( 60.0 ) );

        $this->assertSame( [ 'draft' ], array_column( $this->messagesOf( $id ), 'status' ), 'the owner reviews drafts in their own campaign' );
        $this->assertSame( 'waiting', Container::get( CampaignFiller::class )->nextStep( Container::get( Campaigns::class )->find( $id ) )['state'] );
    }

    // ------------------------------------------------------------------ relevance bar and follow-ups under Autopilot

    public function testUnderAutopilotOwnerCampaignsEmailOnlySitesAtTheAutoQueueScoreWithoutReview(): void {
        $high = $this->site( 'high-rp.ca', 'resource_page', 85 );
        $low  = $this->site( 'low-rp.ca', 'resource_page', 60 );
        $this->enableAutopilot();

        $res = $this->create();
        $id  = (int) $res['campaign_id'];
        $this->assertSame( [ $high ], array_map( static fn( array $m ): int => (int) $m['prospect_id'], $this->messagesOf( $id ) ), 'the engine picks only sites at the bar' );

        // A draft to the lower-scoring site (built by the owner) is not approved automatically, and the card says so.
        $this->assertSame( 1, Container::get( OutreachEngine::class )->buildDrafts( $id, [ $low ], 1 )['created'] );
        Container::get( CampaignFiller::class )->approveWaiting( TimeBudget::seconds( 30.0 ) );
        $lowMsg = $this->messages()->findOneBy( [ 'prospect_id' => $low ] );
        $this->assertSame( 'draft', $lowMsg['status'] );
        $step = Container::get( CampaignFiller::class )->nextStep( Container::get( Campaigns::class )->find( $id ) );
        $this->assertSame( 1, $step['waiting'], 'the owner can approve it with “Approve all drafts”' );
        $this->assertSame( 1, $step['below_bar'] );
        $this->assertStringContainsString( 'scoring below the auto-queue score (70)', $step['text'] );
    }

    public function testFollowUpsWithoutReplyTrackingWaitVisiblyAndGoOutOnceItIsSetUp(): void {
        $this->enableAutopilot();
        $engine = Container::get( OutreachEngine::class );
        $camp   = $engine->createCampaign( 'FU', 'resource_page', [ 'follow_ups' => true ], 1 );
        $this->assertTrue( $engine->approveCampaign( $camp, 1 ) );
        $p   = $this->site( 'fu-wait.ca', 'resource_page', 85, Prospects::STATUS_IN_PROGRESS );
        $c   = (int) $this->db()->get_var( "SELECT id FROM wp_ppclg_contacts WHERE prospect_id = {$p}" );
        $old = gmdate( 'Y-m-d H:i:s', time() - 9 * DAY_IN_SECONDS );
        $this->db()->insert( Schema::table( 'outreach_messages' ), [ 'campaign_id' => $camp, 'prospect_id' => $p, 'contact_id' => $c, 'approach' => 'resource_page', 'sequence' => 1, 'subject' => 'Hello', 'body' => 'x', 'status' => 'sent', 'sent_at' => $old, 'unsubscribe_token' => bin2hex( random_bytes( 16 ) ), 'created_at' => $old, 'updated_at' => $old ] );
        Container::get( FollowUps::class )->generateDue( TimeBudget::seconds( 30.0 ) );
        $follow = $this->messages()->findOneBy( [ 'prospect_id' => $p, 'sequence' => 2 ] );
        $this->assertSame( 'draft', $follow['status'] );

        $step = Container::get( CampaignFiller::class )->nextStep( Container::get( Campaigns::class )->find( $camp ) );
        $this->assertSame( 'waiting', $step['state'], 'not "Sending automatically" while the follow-up waits' );
        $this->assertSame( 1, $step['follow_ups_waiting'] );
        $this->assertStringContainsString( 'Settings → Integrations', $step['text'] );
        $this->assertStringContainsString( 'Settings → Integrations', Container::get( Autopilot::class )->followUpNote() );

        Container::get( Secrets::class )->set( 'inbound_webhook_secret', 'whsec-0123456789abcdef0123456789abcdef' );
        Container::get( CampaignFiller::class )->approveWaiting( TimeBudget::seconds( 30.0 ) );
        $this->assertSame( 'approved', $this->messages()->find( (int) $follow['id'] )['status'], 'the waiting follow-up goes out once replies are tracked' );
    }

    // ------------------------------------------------------------------ campaign create continues in the background

    public function testCreatingACampaignQueuesAFillOnlyRunThatLeavesTheAutoCampaignsAlone(): void {
        $this->settings()->set( 'automation.auto_outreach_drafts', false );
        add_filter( 'ppclg_rest_campaign_fill_seconds', static fn() => 0.0 ); // nothing fits the request: all goes to the background
        for ( $i = 0; $i < 12; $i++ ) {
            $this->site( "rp-bg-{$i}.ca" );
        }
        $this->site( 'partner-bg.ca', 'partnership' );
        $res = $this->create();
        $this->assertGreaterThan( 0, $res['remaining'] );
        $job = Container::get( Jobs::class )->findOneBy( [ 'type' => 'auto_outreach' ] );
        $this->assertNotNull( $job );
        $this->assertTrue( ! empty( $job['payload']['fill_only'] ) );
        $this->assertTrue( empty( $job['payload']['manual'] ), 'not a manual run that would ignore “auto outreach drafts” off' );

        $GLOBALS['ppclg_test_filters']['ppclg_rest_campaign_fill_seconds'] = [];
        Container::get( JobRunner::class )->runType( 'auto_outreach', (array) $job['payload'], TimeBudget::seconds( 60.0 ) );
        $this->assertNull( Container::get( Campaigns::class )->findOneBy( [ 'name' => 'Auto: Industry partnership' ] ), 'no evergreen drafting' );
        $this->assertGreaterThan( 0, count( $this->messagesOf( (int) $res['campaign_id'] ) ) );
    }

    // ------------------------------------------------------------------ send window and DNS

    public function testNextSendWindowOpensOnTheHourInHalfHourZones(): void {
        $this->settings()->update( [ 'outreach' => [ 'send_window_start' => 9, 'send_window_end' => 17, 'send_days' => [ 1, 2, 3, 4, 5 ], 'timezone' => 'America/St_Johns' ] ] );
        $compliance = Container::get( Compliance::class );
        $sunday     = gmmktime( 14, 0, 0, 11, 15, 2026 );
        $this->assertSame( 'Monday 09:00 St Johns time', $compliance->describeNextSendWindow( $sunday ) );
        $this->settings()->set( 'outreach.timezone', 'Asia/Kolkata' );
        $this->assertSame( 'Monday 09:00 Kolkata time', $compliance->describeNextSendWindow( $sunday ) );
    }

    public function testAFailedDnsLookupIsNotRepeatedOnEveryRequest(): void {
        $calls = 0;
        add_filter( 'ppclg_mail_domain_lookup', static function ( $r ) use ( &$calls ) {
            $calls++;
            return false; // the resolver timed out
        } );
        $this->settings()->set( 'outreach.sender_email', 'hello@slow-resolver.ca' );
        $first = Container::get( Compliance::class )->senderReady();
        $this->assertTrue( $first['ok'], 'a failed lookup does not pause sending' );
        Container::reset(); // the next request
        Container::get( Compliance::class )->senderReady();
        Container::reset();
        $again = Container::get( Compliance::class )->mailDomain( 'slow-resolver.ca' );
        $this->assertSame( 1, $calls, 'remembered for a while instead of stalling every request' );
        $this->assertFalse( $again['checked'] );
    }

    // ------------------------------------------------------------------ the real sender problem everywhere

    public function testOverviewAndTheAdminNoticeNameTheRealSenderProblem(): void {
        $this->parkedSender();
        $overview = ( new OverviewController( $this->c() ) )->overview( $this->request( 'GET' ) );
        $line     = implode( "\n", (array) ( $overview['missing_integrations'] ?? [] ) );
        $this->assertStringContainsString( 'purepeptidescanada.ca cannot receive mail', $line );
        $this->assertStringNotContainsString( 'sender name, sender email, postal address', $line );

        $notices = ( new Notices( $this->settings(), Container::get( ProviderRegistry::class ), Container::get( Compliance::class ) ) )->notices();
        $sender  = array_values( array_filter( $notices, static fn( array $n ): bool => 'sender_identity' === $n['id'] ) );
        $this->assertStringContainsString( 'cannot receive mail', $sender[0]['message'] );

        // An empty outreach postal address with a business address under Settings → Site is not "incomplete".
        $this->settings()->update( [ 'outreach' => [ 'sender_email' => 'jane@purepeptidescanada.test', 'postal_address' => '' ] ] );
        Container::reset();
        $notices = ( new Notices( $this->settings(), Container::get( ProviderRegistry::class ), Container::get( Compliance::class ) ) )->notices();
        $this->assertSame( [], array_values( array_filter( $notices, static fn( array $n ): bool => 'sender_identity' === $n['id'] ) ) );
    }

    // ------------------------------------------------------------------ automatic links need confirmation

    public function testSwitchingAutomaticLinksOnNeedsConfirmation(): void {
        $ctl = new SettingsController( $this->c() );
        $no  = $ctl->update( $this->request( 'POST', [ 'settings' => [ 'seo' => [ 'auto_apply_links' => true ] ] ] ) );
        $this->assertInstanceOf( WP_Error::class, $no );
        $this->assertSame( 'ppclg_confirmation_required', $no->get_error_code() );
        $this->assertStringContainsString( 'edit your published pages', $no->get_error_message() );
        $this->assertFalse( (bool) $this->settings()->get( 'seo.auto_apply_links' ) );

        $yes = $ctl->update( $this->request( 'POST', [ 'settings' => [ 'seo' => [ 'auto_apply_links' => true ] ], 'confirm' => true ] ) );
        $this->assertIsArray( $yes );
        $this->assertTrue( (bool) $this->settings()->get( 'seo.auto_apply_links' ) );
        $row = $this->db()->get_row( "SELECT actor, details FROM wp_ppclg_audit_log WHERE action = 'settings.updated' ORDER BY id DESC LIMIT 1", ARRAY_A );
        $this->assertSame( 'user:1', $row['actor'] );
        $this->assertStringContainsString( 'auto_apply_links', (string) $row['details'] );
    }
}
