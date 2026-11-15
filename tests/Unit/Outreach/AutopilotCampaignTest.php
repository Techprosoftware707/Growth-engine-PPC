<?php
/**
 * 1.2.0 Autopilot + "we create a campaign and it says saved in draft but we don't see anything".
 *
 * Reproduces the owner's install (autosend on, "Require individual approval" on, sender on a parked .ca domain,
 * WordPress on UTC for a Toronto business, Auto campaigns already holding drafts) and checks:
 * - creating a campaign builds drafts at once for matching prospects only, takes over the Auto campaign's unsent
 *   drafts, says why when nobody is eligible, and reports what happens next;
 * - Autopilot is one switch: on = campaigns and drafts approved and sent inside caps/window; off = today's rules;
 *   turning it on needs confirm=true (REST and /settings) and records consent; turning it off returns what it
 *   approved but did not send to draft; follow-ups go out automatically only with reply tracking;
 * - background drafting also fills owner campaigns and approves drafts that were waiting;
 * - the sender domain must receive mail (MX / A), SPF is a warning, the postal address defaults from the site;
 * - the send window runs in the business's zone when WordPress is left on UTC.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Outreach;

use PPC\LinkGrowth\Admin\Rest\AutomationController;
use PPC\LinkGrowth\Admin\Rest\GrowthController;
use PPC\LinkGrowth\Admin\Rest\OutreachController;
use PPC\LinkGrowth\Admin\Rest\SettingsController;
use PPC\LinkGrowth\Automation\Autopilot;
use PPC\LinkGrowth\Automation\JobRunner;
use PPC\LinkGrowth\Automation\Pipeline;
use PPC\LinkGrowth\Database\Schema;
use PPC\LinkGrowth\Growth\GrowthRun;
use PPC\LinkGrowth\Outreach\CampaignFiller;
use PPC\LinkGrowth\Outreach\Compliance;
use PPC\LinkGrowth\Outreach\FollowUps;
use PPC\LinkGrowth\Outreach\OutreachEngine;
use PPC\LinkGrowth\Repositories\Campaigns;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Submission\Policy;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_Error;
use WP_REST_Request;

final class AutopilotCampaignTest extends TestCase {

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
                    'outreach_autosend' => true,  // as on the live site
                    'daily_limits'      => [ 'outreach_emails' => 10 ],
                    'weekly_limits'     => [ 'outreach_emails' => 40 ],
                ],
                // outreach.require_approval keeps its default (true), as on the live site.
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

    /** A qualified website with an active published contact. */
    private function site( string $domain, string $type, int $score = 85, string $status = Prospects::STATUS_QUALIFIED, string $via = 'search' ): int {
        $now = gmdate( 'Y-m-d H:i:s' );
        $this->db()->insert( Schema::table( 'prospects' ), [ 'domain' => $domain, 'name' => $domain, 'status' => $status, 'opportunity_type' => $type, 'score' => $score, 'discovered_via' => $via, 'first_seen' => $now, 'updated_at' => $now ] );
        $id = (int) $this->db()->insert_id;
        $this->db()->insert( Schema::table( 'contacts' ), [ 'prospect_id' => $id, 'email' => 'editor@' . $domain, 'name' => 'Ed', 'role' => 'editor', 'confidence' => 80, 'consent_basis' => 'conspicuous_publication', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] );
        return $id;
    }

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

    /** DNS answers for the mail-domain check (no real lookups in tests). @param array<string,array<string,mixed>> $map */
    private function dns( array $map ): void {
        add_filter( 'ppclg_mail_domain_dns', static fn( $r, string $domain ) => $map[ $domain ] ?? $r, 10, 2 );
    }

    // ------------------------------------------------------------------ campaign creation shows something real

    public function testCreatingACampaignBuildsDraftsForTheMatchingProspectsAtOnce(): void {
        $a = $this->site( 'biochem-resources.ca', 'resource_page' );
        $b = $this->site( 'lab-links.ca', 'resource_page' );
        $this->site( 'partner-lab.ca', 'partnership' );
        $this->site( 'news-lab.ca', 'editorial' );

        $res = $this->create();

        $this->assertSame( 2, $res['drafted'], json_encode( $res ) );
        $this->assertSame( 2, $res['eligible'] );
        $this->assertSame( 0, $res['remaining'] );
        $drafts = $this->messagesOf( (int) $res['campaign_id'] );
        $this->assertEqualsCanonicalizing( [ $a, $b ], array_map( static fn( array $m ): int => (int) $m['prospect_id'], $drafts ), 'only resource-page prospects get a resource-page pitch' );
        $this->assertSame( [ 'draft', 'draft' ], array_column( $drafts, 'status' ), 'Autopilot off: drafts wait for approval' );
        $this->assertSame( 'waiting', $res['campaign']['next_step']['state'] );
        $this->assertSame( 2, $res['campaign']['next_step']['waiting'] );
        $this->assertStringContainsString( 'Waiting for your approval', $res['campaign']['next_step']['text'] );
    }

    public function testAnEmptyCampaignSaysWhyInPlainWords(): void {
        $now = gmdate( 'Y-m-d H:i:s' );
        $this->db()->insert( Schema::table( 'prospects' ), [ 'domain' => 'nocontact.ca', 'name' => 'x', 'status' => 'qualified', 'opportunity_type' => 'resource_page', 'score' => 80, 'first_seen' => $now, 'updated_at' => $now ] );

        $res = $this->create();

        $this->assertSame( 0, $res['drafted'] );
        $this->assertSame( 0, $res['eligible'] );
        $this->assertStringContainsString( 'No qualified prospects with a published email address', $res['explanation'] );
        $this->assertSame( 'empty', $res['campaign']['next_step']['state'] );
        $this->assertStringContainsString( 'the engine adds them as it evaluates sites', $res['campaign']['next_step']['text'] );
    }

    public function testANewCampaignTakesOverTheUnsentDraftsOfTheAutoCampaign(): void {
        $p = $this->site( 'resources-hub.ca', 'resource_page' );
        Container::get( JobRunner::class )->runType( 'auto_outreach', [ 'manual' => true ], TimeBudget::seconds( 60.0 ) );
        $auto = Container::get( Campaigns::class )->findOneBy( [ 'name' => 'Auto: Resource page inclusion' ] );
        $this->assertNotNull( $auto );
        $this->assertCount( 1, $this->messagesOf( (int) $auto['id'] ) );

        $res = $this->create();

        $this->assertSame( 1, $res['drafted'], json_encode( $res ) );
        $this->assertCount( 0, $this->messagesOf( (int) $auto['id'] ) );
        $mine = $this->messagesOf( (int) $res['campaign_id'] );
        $this->assertCount( 1, $mine );
        $this->assertSame( $p, (int) $mine[0]['prospect_id'] );
    }

    public function testOneOpenMessagePerWebsiteAndTheSkipReasonNamesTheCampaign(): void {
        $p      = $this->site( 'two-pitches.ca', 'partnership' );
        $engine = Container::get( OutreachEngine::class );
        $first  = $engine->createCampaign( 'Partners', 'partnership', [], 1 );
        $this->assertSame( 1, $engine->buildDrafts( $first, [ $p ], 1 )['created'] );
        $second = $engine->createCampaign( 'Resources', 'resource_page', [], 1 );
        $r      = $engine->buildDrafts( $second, [ $p ], 1 );
        $this->assertSame( 0, $r['created'] );
        $this->assertStringContainsString( 'already has an open message', $r['skipped'][ $p ] );
        $this->assertStringContainsString( 'campaign "Partners"', $r['skipped'][ $p ] );
    }

    public function testCandidatesEndpointListsOnlyMatchingProspects(): void {
        $match = $this->site( 'match.ca', 'resource_page' );
        $this->site( 'other.ca', 'partnership' );
        $id  = Container::get( OutreachEngine::class )->createCampaign( 'RP', 'resource_page', [], 1 );
        $res = ( new OutreachController( $this->c() ) )->candidates( $this->request( 'GET', [ 'id' => $id ] ) );
        $this->assertSame( [ $match ], array_column( $res['items'], 'id' ) );
    }

    public function testApproveAllDraftsApprovesTheCampaignsDraftsInOneRequest(): void {
        $this->site( 'one.ca', 'resource_page' );
        $this->site( 'two.ca', 'resource_page' );
        $res = $this->create();
        $all = ( new OutreachController( $this->c() ) )->approveDrafts( $this->request( 'POST', [ 'id' => $res['campaign_id'] ] ) );
        $this->assertSame( 2, $all['approved'] );
        $this->assertTrue( $all['campaign_approved'], 'one click also approves the draft campaign' );
        $this->assertSame( [ 'approved', 'approved' ], array_column( $this->messagesOf( (int) $res['campaign_id'] ), 'status' ) );
        $this->assertSame( 'sending', $all['campaign']['next_step']['state'] );
        $this->assertSame( 2, Container::get( OutreachEngine::class )->sendDue( TimeBudget::seconds( 30.0 ) )['sent'] );
    }

    // ------------------------------------------------------------------ the live conflict: autosend on + approval required

    public function testWithoutAutopilotRequireApprovalStillBlocksAutomationExactlyAsBefore(): void {
        $this->site( 'auto-rp.ca', 'resource_page' );
        $r = Container::get( JobRunner::class )->runType( 'auto_outreach', [], TimeBudget::seconds( 60.0 ) );
        $this->assertSame( 1, $r['drafted'] );
        $this->assertSame( 0, $r['auto_approved'] );
        $send = Container::get( OutreachEngine::class )->sendDue( TimeBudget::seconds( 30.0 ) );
        $this->assertSame( 0, $send['sent'] );
        $this->assertSame( 1, $send['reasons']['awaiting_approval'] ?? 0, 'the send stage now says why nothing went out' );
        $this->assertCount( 0, $GLOBALS['ppclg_test_mail'] );
    }

    public function testAutopilotApprovesANewCampaignAndItsDraftsAndTheSendStageMailsThem(): void {
        $this->site( 'auto-one.ca', 'resource_page' );
        $this->site( 'auto-two.ca', 'resource_page' );
        $this->enableAutopilot();

        $res = $this->create();

        $this->assertTrue( $res['autopilot'] );
        $this->assertSame( 2, $res['approved'] );
        $this->assertSame( Campaigns::STATUS_APPROVED, Container::get( Campaigns::class )->find( (int) $res['campaign_id'] )['status'] );
        $this->assertSame( 'sending', $res['campaign']['next_step']['state'] );
        $this->assertStringContainsString( 'Sending automatically: 0 of 5 today', $res['campaign']['next_step']['text'] );

        $sent = Container::get( OutreachEngine::class )->sendDue( TimeBudget::seconds( 30.0 ) );
        $this->assertSame( 2, $sent['sent'], json_encode( $sent ) );
        $this->assertCount( 2, $GLOBALS['ppclg_test_mail'] );
        $audit = $this->db()->get_col( "SELECT actor FROM wp_ppclg_audit_log WHERE action = 'outreach.message_approved'" );
        $this->assertSame( [ Autopilot::ACTOR, Autopilot::ACTOR ], $audit, 'approvals are attributed to Autopilot' );
    }

    public function testAutopilotApprovesDraftsThatWereAlreadyWaiting(): void {
        // The live "Auto: Industry partnership" campaign: approved, drafts that were never approved.
        $this->site( 'partner-a.ca', 'partnership' );
        $this->site( 'partner-b.ca', 'partnership' );
        $first = Container::get( JobRunner::class )->runType( 'auto_outreach', [], TimeBudget::seconds( 60.0 ) );
        $this->assertSame( 2, $first['drafted'] );
        $this->assertSame( 0, $first['auto_approved'] );

        // Switching Autopilot on approves them at once (not only at the next daily outreach run).
        $on = ( new AutomationController( $this->c() ) )->setAutopilot( $this->request( 'POST', [ 'enabled' => true, 'confirm' => true ] ) );
        $this->assertSame( 2, $on['approved_now'], json_encode( $on ) );
        $this->assertSame( 2, $this->messages()->count( [ 'status' => OutreachMessages::STATUS_APPROVED ] ) );

        // Drafts that appear later in an approved campaign are picked up by the background stage.
        $this->db()->query( "UPDATE wp_ppclg_outreach_messages SET status = 'draft'" );
        Container::reset();
        $second = Container::get( JobRunner::class )->runType( 'auto_outreach', [], TimeBudget::seconds( 60.0 ) );
        $this->assertSame( 2, $second['approved_waiting'], json_encode( $second ) );
        $this->assertSame( 2, $this->messages()->count( [ 'status' => OutreachMessages::STATUS_APPROVED ] ) );
    }

    public function testBackgroundDraftingFillsCampaignsTheOwnerCreated(): void {
        $id = Container::get( OutreachEngine::class )->createCampaign( 'Run 2', 'resource_page', [], 1 ); // empty, as before 1.2.0
        $this->site( 'later-rp.ca', 'resource_page', 60 ); // below the auto-queue score: owner campaigns still get it
        $r = Container::get( JobRunner::class )->runType( 'auto_outreach', [], TimeBudget::seconds( 60.0 ) );
        $this->assertSame( 1, $r['filled'], json_encode( $r ) );
        $this->assertCount( 1, $this->messagesOf( $id ) );
    }

    public function testTurningAutopilotOffReturnsWhatItApprovedButNotWhatYouApproved(): void {
        $this->site( 'ap-1.ca', 'resource_page' );
        $this->site( 'ap-2.ca', 'resource_page' );
        $this->enableAutopilot();
        $res  = $this->create();
        $msgs = $this->messagesOf( (int) $res['campaign_id'] );
        $this->assertSame( [ 'approved', 'approved' ], array_column( $msgs, 'status' ) );
        // A person re-approves the second one: it must stay approved.
        Container::get( OutreachEngine::class )->approveMessage( (int) $msgs[1]['id'], 1 );

        $off = ( new AutomationController( $this->c() ) )->setAutopilot( $this->request( 'POST', [ 'enabled' => false ] ) );

        $this->assertFalse( $off['enabled'] );
        $this->assertSame( 1, $off['reverted'] );
        $this->assertSame( 'draft', $this->messages()->find( (int) $msgs[0]['id'] )['status'] );
        $this->assertSame( Autopilot::REVERTED_NOTE, $this->messages()->find( (int) $msgs[0]['id'] )['last_error'] );
        $this->assertSame( 'approved', $this->messages()->find( (int) $msgs[1]['id'] )['status'] );
        // Back to the live rules: autosend on + approval required approves nothing.
        $this->assertFalse( Container::get( Autopilot::class )->autoApproveMessages() );
    }

    public function testTurningAutopilotOnNeedsConfirmationAndRecordsConsent(): void {
        $ctl = new AutomationController( $this->c() );
        $no  = $ctl->setAutopilot( $this->request( 'POST', [ 'enabled' => true ] ) );
        $this->assertInstanceOf( WP_Error::class, $no );
        $this->assertSame( 400, $no->get_error_data()['status'] );
        $this->assertTrue( $no->get_error_data()['confirm_required'] );
        $this->assertFalse( Container::get( Autopilot::class )->isOn() );

        $yes = $ctl->setAutopilot( $this->request( 'POST', [ 'enabled' => true, 'confirm' => true ] ) );
        $this->assertTrue( $yes['enabled'] );
        $consent = get_option( Autopilot::OPTION_CONSENT );
        $this->assertSame( 1, $consent['user_id'] );
        $this->assertSame( Autopilot::POLICY_VERSION, $consent['policy_version'] );
        $this->assertNotEmpty( $consent['at'] );
        $this->assertSame( 1, (int) $this->db()->get_var( "SELECT COUNT(*) FROM wp_ppclg_audit_log WHERE action = 'autopilot.enabled' AND actor = 'user:1'" ) );
        $this->assertTrue( (bool) $this->settings()->get( 'automation.enabled' ), 'the scheduler runs under Autopilot' );
    }

    public function testSettingsEndpointCannotSwitchAutopilotOnWithoutConfirmOrWriteTheConsent(): void {
        $ctl = new SettingsController( $this->c() );
        $no  = $ctl->update( $this->request( 'POST', [ 'settings' => [ 'automation' => [ 'autopilot' => true ] ] ] ) );
        $this->assertInstanceOf( WP_Error::class, $no );
        $this->assertSame( 'ppclg_confirmation_required', $no->get_error_code() );
        $this->assertFalse( Container::get( Autopilot::class )->isOn() );

        $forged = $ctl->update( $this->request( 'POST', [ 'settings' => [ 'automation' => [ 'autopilot_consent' => [ 'user_id' => 99 ] ], 'secrets' => [ 'inbound_webhook_secret' => 'x' ] ] ] ) );
        $this->assertIsArray( $forged );
        $this->assertFalse( get_option( Autopilot::OPTION_CONSENT, false ) );
        $this->assertArrayNotHasKey( 'autopilot_consent', $this->settings()->get( 'automation' ) );
        $this->assertArrayNotHasKey( 'secrets', $this->settings()->all() );

        $yes = $ctl->update( $this->request( 'POST', [ 'settings' => [ 'automation' => [ 'autopilot' => true ] ], 'confirm' => true ] ) );
        $this->assertIsArray( $yes );
        $this->assertTrue( Container::get( Autopilot::class )->isOn() );
        $this->assertSame( 1, get_option( Autopilot::OPTION_CONSENT )['user_id'] );
    }

    public function testAutopilotRaisesTheSubmissionAndStageFlags(): void {
        $this->settings()->update( [ 'automation' => [ 'submissions' => false, 'submission_mode' => 'manual', 'auto_outreach_drafts' => false ] ] );
        $ap = Container::get( Autopilot::class );
        $this->assertFalse( $ap->submissionsEnabled() );
        $this->assertSame( 'manual', Policy::submissionMode( $this->settings() ) );
        $this->enableAutopilot();
        $this->assertTrue( $ap->submissionsEnabled() );
        $this->assertSame( 'auto', Policy::submissionMode( $this->settings() ) );
        $this->assertTrue( $ap->flag( 'auto_outreach_drafts' ) );
        $status = Container::get( Pipeline::class )->status();
        $this->assertTrue( $status['autopilot'] );
        $this->assertTrue( $status['effective_flags']['submissions'] );
        $this->assertFalse( $status['flags']['submissions'], 'the stored switch itself is untouched' );
    }

    public function testGrowNowPolicyAndStepLabelFollowAutopilot(): void {
        $this->enableAutopilot();
        $state = ( new GrowthController( $this->c() ) )->status( $this->request( 'GET' ) );
        $this->assertTrue( $state['policy']['autopilot'] );
        $this->assertFalse( $state['policy']['require_approval'] );
        $labels = array_column( GrowthRun::plan( true ), 'label', 'key' );
        $this->assertSame( 'Draft and approve outreach (Autopilot sends it)', $labels['auto_outreach'] );
        $this->assertSame( 18, count( GrowthRun::plan( true ) ) );
    }

    // ------------------------------------------------------------------ follow-ups need reply tracking

    public function testAutopilotSendsFollowUpsOnlyWithReplyTracking(): void {
        $this->enableAutopilot();
        $camp = Container::get( OutreachEngine::class )->createCampaign( 'FU', 'resource_page', [ 'follow_ups' => true ], 1 );
        $sent = function ( string $domain ) use ( $camp ): int {
            $p   = $this->site( $domain, 'resource_page', 85, Prospects::STATUS_IN_PROGRESS );
            $c   = (int) $this->db()->get_var( "SELECT id FROM wp_ppclg_contacts WHERE prospect_id = {$p}" );
            $now = gmdate( 'Y-m-d H:i:s', time() - 9 * DAY_IN_SECONDS );
            $this->db()->insert( Schema::table( 'outreach_messages' ), [ 'campaign_id' => $camp, 'prospect_id' => $p, 'contact_id' => $c, 'approach' => 'resource_page', 'sequence' => 1, 'subject' => 'Hello', 'body' => 'x', 'status' => 'sent', 'sent_at' => $now, 'unsubscribe_token' => bin2hex( random_bytes( 16 ) ), 'created_at' => $now, 'updated_at' => $now ] );
            return $p;
        };
        $noTracking = $sent( 'fu-one.ca' );
        Container::get( FollowUps::class )->generateDue( TimeBudget::seconds( 30.0 ) );
        $this->assertSame( 'draft', $this->messages()->findOneBy( [ 'prospect_id' => $noTracking, 'sequence' => 2 ] )['status'], 'no reply tracking: the follow-up waits' );
        $this->assertStringContainsString( 'reply tracking', Container::get( Autopilot::class )->followUpNote() );

        Container::get( Secrets::class )->set( 'inbound_webhook_secret', 'whsec-0123456789abcdef0123456789abcdef' );
        $tracked = $sent( 'fu-two.ca' );
        Container::get( FollowUps::class )->generateDue( TimeBudget::seconds( 30.0 ) );
        $follow = $this->messages()->findOneBy( [ 'prospect_id' => $tracked, 'sequence' => 2 ] );
        $this->assertSame( 'scheduled', $follow['status'] );
        $this->assertSame( Autopilot::ACTOR, $this->db()->get_var( "SELECT actor FROM wp_ppclg_audit_log WHERE action = 'outreach.followup_scheduled' AND object_id = " . (int) $follow['id'] ) );
    }

    // ------------------------------------------------------------------ sender readiness

    public function testParkedSenderDomainPausesSendingAndSuggestsTheSiteEmail(): void {
        $this->settings()->update( [ 'outreach' => [ 'sender_email' => 'hi@purepeptidescanada.ca' ], 'site' => [ 'email' => 'hi@purepeptidescanada.com' ] ] );
        $this->dns( [ 'purepeptidescanada.ca' => [ 'mx' => false, 'a' => false, 'spf' => false ], 'purepeptidescanada.com' => [ 'mx' => true, 'a' => true, 'spf' => true ] ] );

        $ready = Container::get( Compliance::class )->senderReady();

        $this->assertFalse( $ready['ok'] );
        $this->assertContains( 'sender_email_domain', $ready['missing'] );
        $this->assertStringContainsString( 'purepeptidescanada.ca cannot receive mail', $ready['problems'][0] );
        $this->assertSame( 'hi@purepeptidescanada.com', $ready['suggestion']['sender_email'] );

        $this->site( 'parked.ca', 'resource_page' );
        $this->enableAutopilot();
        $res = $this->create();
        $this->assertSame( 'blocked', $res['campaign']['next_step']['state'] );
        $this->assertStringContainsString( 'Paused: the sender email domain cannot receive mail', $res['campaign']['next_step']['text'] );
        $this->assertSame( Campaigns::STATUS_DRAFT, Container::get( Campaigns::class )->find( (int) $res['campaign_id'] )['status'], 'not approved while the sender cannot receive replies' );
        $this->assertSame( 0, Container::get( OutreachEngine::class )->sendDue( TimeBudget::seconds( 30.0 ) )['sent'] );
    }

    public function testMissingSpfIsOnlyAWarningAndAnARecordCountsAsImplicitMx(): void {
        $this->settings()->set( 'outreach.sender_email', 'hello@example-lab.ca' );
        $this->dns( [ 'example-lab.ca' => [ 'mx' => false, 'a' => true, 'spf' => false ] ] );
        $ready = Container::get( Compliance::class )->senderReady();
        $this->assertTrue( $ready['ok'] );
        $this->assertNotEmpty( array_filter( $ready['warnings'], static fn( string $w ): bool => str_contains( $w, 'no SPF record' ) ) );
    }

    public function testPostalAddressDefaultsFromTheBusinessAddressAndWarnsWithoutAPostalCode(): void {
        $this->settings()->set( 'outreach.postal_address', '' );
        $ready = Container::get( Compliance::class )->senderReady();
        $this->assertNotContains( 'postal_address', $ready['missing'] );
        $this->assertTrue( $ready['postal_default'] );
        $this->assertStringContainsString( 'M5B 2B8', $ready['postal_address'] );

        $this->assertSame( [], Compliance::postalGaps( '222 Jarvis St, Toronto, ON M5B 2B8' ) );
        $this->assertCount( 1, Compliance::postalGaps( '222 Jarvis Street, Toronto, Ontario' ) );
        $this->assertCount( 2, Compliance::postalGaps( '222 Jarvis Street Toronto Ontario' ) );
    }

    // ------------------------------------------------------------------ send-window time zone

    public function testSendWindowUsesTheBusinessZoneWhenWordPressIsOnUtc(): void {
        $GLOBALS['ppclg_test_timezone'] = 'UTC';
        $this->settings()->update( [ 'outreach' => [ 'send_window_start' => 9, 'send_window_end' => 17, 'send_days' => [ 1, 2, 3, 4, 5 ] ] ] );
        $compliance = Container::get( Compliance::class );

        $this->assertSame( 'America/Toronto', $compliance->sendTimezoneInfo()['timezone'] );
        $this->assertSame( 'region', $compliance->sendTimezoneInfo()['source'] );
        $monday0430Toronto = gmmktime( 9, 30, 0, 11, 16, 2026 );  // 09:30 UTC = 04:30 Toronto: closed
        $monday1000Toronto = gmmktime( 15, 0, 0, 11, 16, 2026 );  // 15:00 UTC = 10:00 Toronto: open
        $this->assertFalse( $compliance->withinSendWindow( $monday0430Toronto ) );
        $this->assertTrue( $compliance->withinSendWindow( $monday1000Toronto ) );
        $sunday = gmmktime( 14, 0, 0, 11, 15, 2026 );
        $this->assertSame( 'Monday 09:00 Toronto time', $compliance->describeNextSendWindow( $sunday ) );

        $this->settings()->set( 'site.region', 'BC' );
        $this->assertSame( 'America/Vancouver', $compliance->sendTimezoneInfo()['timezone'] );
        $this->settings()->set( 'outreach.timezone', 'America/Halifax' );
        $this->assertSame( [ 'America/Halifax', 'setting' ], [ $compliance->sendTimezoneInfo()['timezone'], $compliance->sendTimezoneInfo()['source'] ] );

        $GLOBALS['ppclg_test_timezone'] = 'Europe/Paris';
        $this->settings()->set( 'outreach.timezone', '' );
        $this->assertSame( [ 'Europe/Paris', 'wordpress' ], [ $compliance->sendTimezoneInfo()['timezone'], $compliance->sendTimezoneInfo()['source'] ] );
    }

    public function testTimezoneSettingIsValidated(): void {
        $ctl = new SettingsController( $this->c() );
        $ctl->update( $this->request( 'POST', [ 'settings' => [ 'outreach' => [ 'timezone' => 'Mars/Olympus' ] ] ] ) );
        $this->assertSame( '', $this->settings()->get( 'outreach.timezone' ) );
        $ctl->update( $this->request( 'POST', [ 'settings' => [ 'outreach' => [ 'timezone' => 'America/Winnipeg' ] ] ] ) );
        $this->assertSame( 'America/Winnipeg', $this->settings()->get( 'outreach.timezone' ) );
    }

    public function testSenderEndpointReportsTheRealAutosendStateAndTheZone(): void {
        $GLOBALS['ppclg_test_timezone'] = '+00:00';
        $sender = ( new OutreachController( $this->c() ) )->sender( $this->request( 'GET' ) );
        $this->assertTrue( $sender['autosend'] );
        $this->assertFalse( $sender['auto_approve'], 'autosend on + approval required: nothing is approved automatically' );
        $this->assertFalse( $sender['autopilot'] );
        $this->assertSame( 'America/Toronto', $sender['send_window']['timezone'] );
        $this->assertSame( 'Toronto time', $sender['send_window']['timezone_label'] );
        $this->enableAutopilot();
        $this->assertTrue( ( new OutreachController( $this->c() ) )->sender( $this->request( 'GET' ) )['auto_approve'] );
    }

    public function testFillerEligibilitySkipsSuppressedDomainsCoolDownAndVerifiedOwnerTasks(): void {
        $ok     = $this->site( 'fine.ca', 'resource_page' );
        $this->site( 'imported.ca', 'resource_page', 85, Prospects::STATUS_QUALIFIED, \PPC\LinkGrowth\Discovery\VerifiedOpportunities::SOURCE );
        $cooled = $this->site( 'recent.ca', 'resource_page' );
        $c      = (int) $this->db()->get_var( "SELECT id FROM wp_ppclg_contacts WHERE prospect_id = {$cooled}" );
        $now    = gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS );
        $this->db()->insert( Schema::table( 'outreach_messages' ), [ 'campaign_id' => 0, 'prospect_id' => $cooled, 'contact_id' => $c, 'approach' => 'partnership', 'sequence' => 1, 'subject' => 's', 'body' => 'b', 'status' => 'sent', 'sent_at' => $now, 'unsubscribe_token' => bin2hex( random_bytes( 16 ) ), 'created_at' => $now, 'updated_at' => $now ] );
        $this->site( 'blocked.ca', 'resource_page' );
        $this->db()->insert( Schema::table( 'suppression' ), [ 'type' => 'domain', 'value' => 'blocked.ca', 'reason' => 'test', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ] );

        $this->assertSame( [ $ok ], Container::get( CampaignFiller::class )->candidateIds( 'resource_page' ) );
    }
}
