<?php
/**
 * Regression tests for the outreach slice fixes: no head-of-line blocking of the send queue (outreach-1) or
 * the follow-up queue (outreach-2), replies cancel pending follow-ups (outreach-3), automation never lifts an
 * owner's pause (outreach-4), the website's status / domain suppression is re-checked at send time
 * (outreach-5), reply-to-unsubscribe works for bottom-posted, inline and HTML replies (outreach-6), automation
 * honours "Require individual approval" (outreach-7), the custom Message-ID does not leak into later mails
 * (outreach-8), Postmark-shaped headers are read (outreach-9), rows are claimed before sending (outreach-10)
 * and approval / send agree on the unsubscribe link (outreach-11).
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Outreach;

use PPC\LinkGrowth\Automation\JobRunner;
use PPC\LinkGrowth\Database\Schema;
use PPC\LinkGrowth\Outreach\Compliance;
use PPC\LinkGrowth\Outreach\FollowUps;
use PPC\LinkGrowth\Outreach\InboundWebhook;
use PPC\LinkGrowth\Outreach\Mailer;
use PPC\LinkGrowth\Outreach\OutreachEngine;
use PPC\LinkGrowth\Outreach\Unsubscribe;
use PPC\LinkGrowth\Repositories\Campaigns;
use PPC\LinkGrowth\Repositories\Contacts;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Suppression;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class OutreachFixesTest extends TestCase {

    private const SECRET = 'whsec-0123456789abcdef0123456789abcdef';

    protected function setUp(): void {
        parent::setUp();
        $this->settings()->update(
            [
                'outreach'   => [
                    'sender_name'       => 'Jane Doe',
                    'sender_email'      => 'jane@purepeptidescanada.test',
                    'postal_address'    => '222 Jarvis St, Toronto, ON M5B 2B8, Canada',
                    'send_window_start' => 9,
                    'send_window_end'   => 9,
                    'send_days'         => [ 1, 2, 3, 4, 5, 6, 7 ],
                ],
                'automation' => [
                    'daily_limits'  => [ 'outreach_emails' => 500 ],
                    'weekly_limits' => [ 'outreach_emails' => 2000 ],
                ],
            ]
        );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['phpmailer'] );
        $GLOBALS['ppclg_test_home'] = 'https://purepeptidescanada.test';
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    private function settings(): Settings {
        return Container::get( Settings::class );
    }

    private function engine(): OutreachEngine {
        return Container::get( OutreachEngine::class );
    }

    private function messages(): OutreachMessages {
        return Container::get( OutreachMessages::class );
    }

    private function db(): \wpdb {
        return $GLOBALS['wpdb'];
    }

    private function prospect( string $domain, string $status = 'qualified', array $extra = [] ): int {
        $now = gmdate( 'Y-m-d H:i:s' );
        $this->db()->insert( Schema::table( 'prospects' ), array_merge( [ 'domain' => $domain, 'name' => $domain, 'status' => $status, 'first_seen' => $now, 'updated_at' => $now ], $extra ) );
        return (int) $this->db()->insert_id;
    }

    private function contact( int $prospectId, string $email, string $status = 'active' ): int {
        $now = gmdate( 'Y-m-d H:i:s' );
        $this->db()->insert( Schema::table( 'contacts' ), [ 'prospect_id' => $prospectId, 'email' => $email, 'name' => 'Ed', 'role' => 'editor', 'confidence' => 80, 'consent_basis' => 'conspicuous_publication', 'status' => $status, 'created_at' => $now, 'updated_at' => $now ] );
        return (int) $this->db()->insert_id;
    }

    /** @param array<string,mixed> $settings */
    private function campaign( string $status, array $settings = [], int $dailyLimit = 50, string $name = 'Camp' ): int {
        $now = gmdate( 'Y-m-d H:i:s' );
        $this->db()->insert( Schema::table( 'campaigns' ), [ 'name' => $name, 'approach' => 'resource_page', 'status' => $status, 'daily_limit' => $dailyLimit, 'settings' => json_encode( $settings ), 'stats' => '[]', 'created_at' => $now, 'updated_at' => $now ] );
        return (int) $this->db()->insert_id;
    }

    private function body( string $token ): string {
        return "Hello,\n\nI came across your resource list and thought our laboratory guide might fit.\n\nJane Doe\nPure Peptides Canada\n\n"
            . "Pure Peptides Canada · 222 Jarvis St, Toronto, ON M5B 2B8, Canada\nIf you'd rather not hear from us again, reply with \"unsubscribe\" or use this link: " . Unsubscribe::urlFor( $token );
    }

    /** @param array<string,mixed> $extra */
    private function message( int $campaignId, int $prospectId, int $contactId, string $status, int $sequence = 1, array $extra = [] ): int {
        $token = bin2hex( random_bytes( 16 ) );
        $now   = gmdate( 'Y-m-d H:i:s' );
        $this->db()->insert(
            Schema::table( 'outreach_messages' ),
            array_merge(
                [ 'campaign_id' => $campaignId, 'prospect_id' => $prospectId, 'contact_id' => $contactId, 'approach' => 'resource_page', 'sequence' => $sequence, 'subject' => 'A Canadian lab reference for your list', 'body' => $this->body( $token ), 'status' => $status, 'variant' => 'rp-a', 'unsubscribe_token' => $token, 'created_at' => $now, 'updated_at' => $now ],
                $extra
            )
        );
        return (int) $this->db()->insert_id;
    }

    /** A prospect + active contact + message in one go. */
    private function lead( int $campaignId, string $domain, string $status = 'approved', string $contactStatus = 'active' ): int {
        $p = $this->prospect( $domain );
        $c = $this->contact( $p, 'ed@' . $domain, $contactStatus );
        return $this->message( $campaignId, $p, $c, $status );
    }

    private function msgStatus( int $messageId ): string {
        return (string) $this->messages()->find( $messageId )['status'];
    }

    /** @return array<string,mixed> */
    private function sendDue( int $max = 10 ): array {
        return $this->engine()->sendDue( TimeBudget::seconds( 60.0 ), $max );
    }

    private function ago( int $days ): string {
        return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
    }

    /** @return array<int,array<string,mixed>> */
    private function mailsTo( string $email ): array {
        return array_values( array_filter( $GLOBALS['ppclg_test_mail'], static fn( array $m ): bool => $m['to'] === $email ) );
    }

    /** @param array<string,mixed> $payload */
    private function inbound( array $payload ): array {
        Container::get( Secrets::class )->set( 'inbound_webhook_secret', self::SECRET );
        return Container::get( InboundWebhook::class )->handle( $payload, self::SECRET );
    }

    /** A sent first message with a known Message-ID, ready for webhook replies. @return array{0:int,1:int,2:string} */
    private function sentConversation( string $email = 'library@uni.test' ): array {
        $camp      = $this->campaign( Campaigns::STATUS_SENDING, [ 'follow_ups' => true, 'auto_follow_up' => true ] );
        $p         = $this->prospect( substr( $email, (int) strpos( $email, '@' ) + 1 ), Prospects::STATUS_IN_PROGRESS );
        $c         = $this->contact( $p, $email );
        $messageId = 'ppclg-' . str_repeat( 'c', 32 ) . '@purepeptidescanada.test';
        $id        = $this->message( $camp, $p, $c, OutreachMessages::STATUS_SENT, 1, [ 'sent_at' => $this->ago( 8 ), 'message_id' => $messageId ] );
        return [ $id, $c, $messageId ];
    }

    // ------------------------------------------------------------------ outreach-1

    public function testPausedCampaignBacklogNoLongerStallsEveryOtherCampaign(): void {
        $paused = $this->campaign( Campaigns::STATUS_PAUSED );
        $held   = [];
        for ( $i = 0; $i < 10; $i++ ) {
            $held[] = $this->lead( $paused, "paused{$i}.test" );
        }
        $live = $this->lead( $this->campaign( Campaigns::STATUS_APPROVED ), 'live.test' );

        $r = $this->sendDue();
        $this->assertSame( 1, $r['sent'], json_encode( $r ) );
        $this->assertSame( OutreachMessages::STATUS_SENT, $this->msgStatus( $live ) );
        foreach ( $held as $id ) {
            $this->assertSame( OutreachMessages::STATUS_APPROVED, $this->msgStatus( $id ), 'messages of a paused campaign wait, untouched' );
        }
        $this->assertFalse( $this->engine()->hasSendable(), 'nothing sendable is left, so the pipeline need not queue the send job' );
    }

    public function testArchivedCampaignAndScheduledRowsWithAutosendOffDoNotFillTheBatch(): void {
        $archived = $this->campaign( Campaigns::STATUS_ARCHIVED );
        $active   = $this->campaign( Campaigns::STATUS_APPROVED );
        for ( $i = 0; $i < 6; $i++ ) {
            $this->lead( $archived, "arch{$i}.test" );
        }
        for ( $i = 0; $i < 6; $i++ ) {
            $this->lead( $active, "sched{$i}.test", OutreachMessages::STATUS_SCHEDULED );
        }
        $live = $this->lead( $active, 'live.test' );

        $r = $this->sendDue();
        $this->assertSame( 1, $r['sent'], json_encode( $r ) );
        $this->assertSame( OutreachMessages::STATUS_SENT, $this->msgStatus( $live ) );
    }

    public function testCampaignAtItsDailyLimitDoesNotStarveOtherCampaigns(): void {
        $capped = $this->campaign( Campaigns::STATUS_APPROVED, [], 1 );
        for ( $i = 0; $i < 12; $i++ ) {
            $this->lead( $capped, "capped{$i}.test" );
        }
        $other = $this->lead( $this->campaign( Campaigns::STATUS_APPROVED ), 'other.test' );

        $this->sendDue();
        $this->sendDue();
        $this->assertSame( OutreachMessages::STATUS_SENT, $this->msgStatus( $other ), 'the capped campaign no longer occupies the whole window' );
        $this->assertSame( 1, $this->messages()->count( [ 'campaign_id' => $capped, 'status' => OutreachMessages::STATUS_SENT ] ), 'the per-campaign cap still holds' );
    }

    public function testComplianceBlockedMessagesLeaveTheReadySetWithTheReason(): void {
        $camp    = $this->campaign( Campaigns::STATUS_APPROVED );
        $bounced = [];
        for ( $i = 0; $i < 10; $i++ ) {
            $bounced[] = $this->lead( $camp, "bounced{$i}.test", OutreachMessages::STATUS_APPROVED, Contacts::STATUS_BOUNCED );
        }
        $good = $this->lead( $camp, 'good.test' );

        $first = $this->sendDue();
        $this->assertSame( 10, $first['reasons']['compliance'] ?? 0, json_encode( $first ) );
        $this->sendDue();
        $this->assertSame( OutreachMessages::STATUS_SENT, $this->msgStatus( $good ) );
        foreach ( $bounced as $id ) {
            $row = $this->messages()->find( $id );
            $this->assertSame( OutreachMessages::STATUS_FAILED, $row['status'] );
            $this->assertStringContainsString( 'Blocked: Contact is not active', (string) $row['last_error'] );
        }
        $this->assertCount( 0, $this->mailsTo( 'ed@bounced0.test' ) );
    }

    public function testCoolDownDefersTheMessageUntilTheIntervalEnds(): void {
        $camp = $this->campaign( Campaigns::STATUS_APPROVED );
        $p    = $this->prospect( 'cool.test' );
        $this->message( $camp, $p, $this->contact( $p, 'first@cool.test' ), OutreachMessages::STATUS_SENT, 1, [ 'sent_at' => $this->ago( 10 ) ] );
        $second = $this->message( $camp, $p, $this->contact( $p, 'second@cool.test' ), OutreachMessages::STATUS_APPROVED, 1, [ 'approach' => 'partnership' ] );

        $r = $this->sendDue();
        $this->assertSame( 0, $r['sent'] );
        $row = $this->messages()->find( $second );
        $this->assertSame( OutreachMessages::STATUS_APPROVED, $row['status'], 'still approved: it goes out once the cool-down is over' );
        $this->assertStringStartsWith( 'Deferred:', (string) $row['last_error'] );
        $until = strtotime( (string) $row['scheduled_at'] . ' UTC' );
        $this->assertEqualsWithDelta( time() + 80 * DAY_IN_SECONDS, $until, 120 );
        $this->assertFalse( $this->engine()->hasSendable() );
    }

    // ------------------------------------------------------------------ outreach-2

    public function testFollowUpsAreNotStarvedByConversationsThatAlreadyHadTheirFollowUp(): void {
        $camp = $this->campaign( Campaigns::STATUS_SENDING, [ 'follow_ups' => true ] );
        for ( $i = 0; $i < 20; $i++ ) {
            $p = $this->prospect( "old{$i}.test", Prospects::STATUS_IN_PROGRESS );
            $c = $this->contact( $p, "ed@old{$i}.test" );
            $this->message( $camp, $p, $c, OutreachMessages::STATUS_SENT, 1, [ 'sent_at' => $this->ago( 60 ) ] );
            $this->message( $camp, $p, $c, OutreachMessages::STATUS_SENT, 2, [ 'sent_at' => $this->ago( 50 ) ] );
        }
        $p     = $this->prospect( 'fresh.test', Prospects::STATUS_IN_PROGRESS );
        $c     = $this->contact( $p, 'ed@fresh.test' );
        $fresh = $this->message( $camp, $p, $c, OutreachMessages::STATUS_SENT, 1, [ 'sent_at' => $this->ago( 10 ) ] );

        $r = Container::get( FollowUps::class )->generateDue( TimeBudget::seconds( 60.0 ), 20 );
        $this->assertSame( 1, $r['created'], json_encode( $r ) );
        $this->assertNotNull( $this->messages()->existsFor( $c, 'resource_page', 2 ), 'the newer conversation gets its follow-up' );
        $this->assertSame( OutreachMessages::STATUS_SENT, $this->msgStatus( $fresh ) );
    }

    public function testCampaignsWithFollowUpsOffOrIneligibleContactsDoNotStarveTheQueue(): void {
        $off  = $this->campaign( Campaigns::STATUS_SENDING, [ 'follow_ups' => false ] );
        $camp = $this->campaign( Campaigns::STATUS_SENDING, [ 'follow_ups' => true ] );
        for ( $i = 0; $i < 12; $i++ ) {
            $p = $this->prospect( "off{$i}.test", Prospects::STATUS_IN_PROGRESS );
            $this->message( $off, $p, $this->contact( $p, "ed@off{$i}.test" ), OutreachMessages::STATUS_SENT, 1, [ 'sent_at' => $this->ago( 40 ) ] );
            $q = $this->prospect( "gone{$i}.test", Prospects::STATUS_IN_PROGRESS );
            $this->message( $camp, $q, $this->contact( $q, "ed@gone{$i}.test", Contacts::STATUS_OPTED_OUT ), OutreachMessages::STATUS_SENT, 1, [ 'sent_at' => $this->ago( 40 ) ] );
        }
        $p = $this->prospect( 'fresh.test', Prospects::STATUS_IN_PROGRESS );
        $c = $this->contact( $p, 'ed@fresh.test' );
        $this->message( $camp, $p, $c, OutreachMessages::STATUS_SENT, 1, [ 'sent_at' => $this->ago( 10 ) ] );

        $r = Container::get( FollowUps::class )->generateDue( TimeBudget::seconds( 60.0 ), 20 );
        $this->assertSame( 1, $r['created'], json_encode( $r ) );
        $this->assertNotNull( $this->messages()->existsFor( $c, 'resource_page', 2 ) );
        $this->assertSame( 0, $this->messages()->count( [ 'campaign_id' => $off, 'sequence' => 2 ] ), 'follow-ups stay off where the campaign says so' );
    }

    // ------------------------------------------------------------------ outreach-3

    public function testWebhookReplyCancelsThePendingFollowUp(): void {
        $this->settings()->set( 'automation.outreach_autosend', true );
        [ $orig, $contactId, $messageId ] = $this->sentConversation( 'ed@reply.test' );
        $original = $this->messages()->find( $orig );
        $followUp = $this->message( (int) $original['campaign_id'], (int) $original['prospect_id'], $contactId, OutreachMessages::STATUS_SCHEDULED, 2, [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s' ) ] );

        $r = $this->inbound( [ 'from' => 'ed@reply.test', 'subject' => 'Re: A Canadian lab reference', 'text' => 'Thanks, but it is not a fit for us.', 'in_reply_to' => '<' . $messageId . '>' ] );
        $this->assertSame( 1, $r['matched'] );
        $this->assertSame( OutreachMessages::STATUS_REPLIED, $this->msgStatus( $orig ) );
        $this->assertSame( OutreachMessages::STATUS_CLOSED, $this->msgStatus( $followUp ) );

        $this->sendDue();
        $this->assertCount( 0, $this->mailsTo( 'ed@reply.test' ), 'no "following up" email after a reply' );
    }

    public function testLoggedReplyCancelsAnApprovedFollowUp(): void {
        [ $orig, $contactId ] = $this->sentConversation( 'ed@logged.test' );
        $original = $this->messages()->find( $orig );
        $followUp = $this->message( (int) $original['campaign_id'], (int) $original['prospect_id'], $contactId, OutreachMessages::STATUS_APPROVED, 2 );

        $this->assertTrue( $this->engine()->logReply( $orig, 'Thanks, not a fit for us.', 1 ) );
        $this->assertSame( OutreachMessages::STATUS_CLOSED, $this->msgStatus( $followUp ) );
        $this->sendDue();
        $this->assertCount( 0, $this->mailsTo( 'ed@logged.test' ) );
    }

    public function testFollowUpIsNotSentWhenAnEarlierMessageWasAnswered(): void {
        [ $orig, $contactId ] = $this->sentConversation( 'ed@race.test' );
        $original = $this->messages()->find( $orig );
        $this->messages()->setStatus( $orig, OutreachMessages::STATUS_REPLIED, [ 'replied_at' => gmdate( 'Y-m-d H:i:s' ) ] );
        // A follow-up approved in the same moment (not cancelled by the reply path).
        $followUp = $this->message( (int) $original['campaign_id'], (int) $original['prospect_id'], $contactId, OutreachMessages::STATUS_APPROVED, 2 );

        $r = $this->sendDue();
        $this->assertSame( 0, $r['sent'] );
        $this->assertSame( OutreachMessages::STATUS_CLOSED, $this->msgStatus( $followUp ) );
        $this->assertCount( 0, $this->mailsTo( 'ed@race.test' ) );
    }

    // ------------------------------------------------------------------ outreach-4 / outreach-7

    public function testAutomationNeverReapprovesAPausedCampaign(): void {
        $camp = $this->campaign( Campaigns::STATUS_APPROVED );
        $this->assertTrue( $this->engine()->pauseCampaign( $camp, 1 ) );
        $this->assertFalse( $this->engine()->approveCampaign( $camp, 0 ), 'automation (user 0) cannot lift a pause' );
        $this->assertSame( Campaigns::STATUS_PAUSED, Container::get( Campaigns::class )->find( $camp )['status'] );
        $this->assertTrue( $this->engine()->approveCampaign( $camp, 1 ), 'the owner still can' );
    }

    public function testAutomationCannotApproveMessagesWhileIndividualApprovalIsRequired(): void {
        $camp = $this->campaign( Campaigns::STATUS_APPROVED );
        $id   = $this->lead( $camp, 'gate.test', OutreachMessages::STATUS_DRAFT );

        $byAutomation = $this->engine()->approveMessage( $id, 0 );
        $this->assertFalse( $byAutomation['ok'], 'outreach.require_approval defaults to on' );
        $this->assertStringContainsString( 'Require individual approval', implode( ' ', $byAutomation['violations'] ) );
        $this->assertSame( OutreachMessages::STATUS_DRAFT, $this->msgStatus( $id ) );

        $this->settings()->set( 'outreach.require_approval', false );
        $this->assertTrue( $this->engine()->approveMessage( $id, 0 )['ok'], 'with the requirement off, autosend may approve' );
        $this->assertTrue( $this->engine()->approveMessage( $this->lead( $camp, 'owner.test', OutreachMessages::STATUS_DRAFT ), 1 )['ok'], 'a person can always approve' );
    }

    public function testAutoOutreachJobKeepsAPausedAutoCampaignPausedAndSendsNothing(): void {
        $this->settings()->set( 'automation.outreach_autosend', true );
        $this->settings()->set( 'outreach.require_approval', false );
        $p = $this->prospect( 'first-resources.test', Prospects::STATUS_QUALIFIED, [ 'opportunity_type' => 'resource_page', 'score' => 90 ] );
        $this->contact( $p, 'editor@first-resources.test' );
        $first = Container::get( JobRunner::class )->runType( 'auto_outreach', [], TimeBudget::seconds( 60.0 ) );
        $this->assertSame( 1, $first['auto_approved'] ?? null, json_encode( $first ) );
        $camp = (int) ( $first['campaigns']['resource_page'] ?? 0 );
        $this->assertSame( Campaigns::STATUS_APPROVED, Container::get( Campaigns::class )->find( $camp )['status'] );

        $this->assertTrue( $this->engine()->pauseCampaign( $camp, 1 ) );
        $q = $this->prospect( 'second-resources.test', Prospects::STATUS_QUALIFIED, [ 'opportunity_type' => 'resource_page', 'score' => 90 ] );
        $this->contact( $q, 'editor@second-resources.test' );
        Container::reset();
        $second = Container::get( JobRunner::class )->runType( 'auto_outreach', [], TimeBudget::seconds( 60.0 ) );

        $this->assertSame( 0, $second['auto_approved'] ?? null, json_encode( $second ) );
        $this->assertSame( Campaigns::STATUS_PAUSED, Container::get( Campaigns::class )->find( $camp )['status'], 'the owner\'s pause stands' );
        $this->assertSame( 0, $this->messages()->count( [ 'prospect_id' => $q, 'status' => OutreachMessages::STATUS_APPROVED ] ) );
        $this->sendDue();
        $this->assertCount( 0, $GLOBALS['ppclg_test_mail'] );
    }

    // ------------------------------------------------------------------ outreach-5

    public function testRejectedWebsiteIsNotContactedEvenWithAnApprovedMessage(): void {
        $camp = $this->campaign( Campaigns::STATUS_APPROVED );
        $id   = $this->lead( $camp, 'peptide-blog.test' );
        Container::get( Prospects::class )->setStatus( (int) $this->messages()->find( $id )['prospect_id'], Prospects::STATUS_REJECTED );

        $r = $this->sendDue();
        $this->assertSame( 0, $r['sent'] );
        $this->assertCount( 0, $GLOBALS['ppclg_test_mail'] );
        $this->assertSame( OutreachMessages::STATUS_FAILED, $this->msgStatus( $id ) );
        $this->assertStringContainsString( 'must not be contacted', (string) $this->messages()->find( $id )['last_error'] );
    }

    public function testSuppressedWebsiteDomainBlocksAnOffDomainContact(): void {
        $camp = $this->campaign( Campaigns::STATUS_APPROVED );
        $p    = $this->prospect( 'peptide-blog.test' );
        $id   = $this->message( $camp, $p, $this->contact( $p, 'peptideblogger@gmail.com' ), OutreachMessages::STATUS_DRAFT );
        Container::get( Suppression::class )->add( 'domain', 'peptide-blog.test', 'Owner suppressed the website' );

        $this->assertFalse( $this->engine()->approveMessage( $id, 1 )['ok'], 'approval checks the website too' );
        $this->messages()->setStatus( $id, OutreachMessages::STATUS_APPROVED );
        $this->assertFalse( $this->engine()->sendNow( $id, 1 )['ok'] );
        $this->assertCount( 0, $this->mailsTo( 'peptideblogger@gmail.com' ) );
    }

    public function testWebsiteOnHoldDefersSending(): void {
        $camp = $this->campaign( Campaigns::STATUS_APPROVED );
        $id   = $this->lead( $camp, 'hold.test' );
        Container::get( Prospects::class )->setStatus( (int) $this->messages()->find( $id )['prospect_id'], Prospects::STATUS_ON_HOLD );

        $r = $this->sendDue();
        $this->assertSame( 1, $r['reasons']['prospect_on_hold'] ?? 0, json_encode( $r ) );
        $row = $this->messages()->find( $id );
        $this->assertSame( OutreachMessages::STATUS_APPROVED, $row['status'] );
        $this->assertGreaterThan( time() + 23 * HOUR_IN_SECONDS, strtotime( (string) $row['scheduled_at'] . ' UTC' ) );
        $this->assertCount( 0, $GLOBALS['ppclg_test_mail'] );
    }

    // ------------------------------------------------------------------ outreach-6

    /** @return array<string,string> */
    private function replyStates( string $text, string $field = 'text' ): array {
        [ $orig, $contactId, $messageId ] = $this->sentConversation();
        $r = $this->inbound( [ 'from' => 'library@uni.test', 'subject' => 'Re: A Canadian lab reference', $field => $text, 'in_reply_to' => '<' . $messageId . '>' ] );
        $this->assertSame( 1, $r['matched'] );
        return [
            'message'    => $this->msgStatus( $orig ),
            'contact'    => (string) Container::get( Contacts::class )->find( $contactId )['status'],
            'suppressed' => Container::get( Suppression::class )->isSuppressed( 'email', 'library@uni.test' ) ? 'yes' : 'no',
        ];
    }

    private function quotedOriginal(): string {
        $url = Unsubscribe::urlFor( str_repeat( 'c', 32 ) );
        return "On Mon, Nov 9, 2026 at 9:00 AM Jane Doe <jane@purepeptidescanada.test> wrote:\n> Hello,\n> I came across your resource list.\n>\n> Pure Peptides Canada · 222 Jarvis St, Toronto, ON M5B 2B8, Canada\n> If you'd rather not hear from us again, reply with \"unsubscribe\" or use\n> this link: {$url}\n";
    }

    public function testBottomPostedUnsubscribeIsHonoured(): void {
        $states = $this->replyStates( $this->quotedOriginal() . "\nPlease unsubscribe me." );
        $this->assertSame( [ 'message' => 'opted_out', 'contact' => 'opted_out', 'suppressed' => 'yes' ], $states );
    }

    public function testInlineReplyBetweenQuotedLinesIsHonoured(): void {
        $states = $this->replyStates( "> Hello,\n> I came across your resource list.\n\nNo thanks. Stop emailing me.\n\n> Would you consider adding it?" );
        $this->assertSame( [ 'message' => 'opted_out', 'contact' => 'opted_out', 'suppressed' => 'yes' ], $states );
    }

    public function testHtmlOnlyUnsubscribeReplyIsHonoured(): void {
        $url  = Unsubscribe::urlFor( str_repeat( 'c', 32 ) );
        $html = '<div dir="ltr">Please remove me from your list.</div><br><div class="gmail_quote"><div class="gmail_attr">On Mon, Nov 9, 2026 at 9:00 AM Jane Doe &lt;jane@purepeptidescanada.test&gt; wrote:<br></div>'
            . '<blockquote class="gmail_quote"><p>Hello,</p><p>I came across your resource list.</p><p>If you\'d rather not hear from us again, reply with "unsubscribe" or use this link: <a href="' . $url . '">' . $url . '</a></p></blockquote></div>';
        $this->assertSame( [ 'message' => 'opted_out', 'contact' => 'opted_out', 'suppressed' => 'yes' ], $this->replyStates( $html, 'html' ) );
    }

    public function testPositiveRepliesQuotingOurFooterAreNotTreatedAsOptOuts(): void {
        $this->assertSame( [ 'message' => 'replied', 'contact' => 'active', 'suppressed' => 'no' ], $this->replyStates( $this->quotedOriginal() . "\nSure, happy to add your guide to the list." ) );

        $this->setUp();
        $url  = Unsubscribe::urlFor( str_repeat( 'c', 32 ) );
        $html = '<div>Sounds good, I added it.</div><blockquote><div>If you\'d rather not hear from us again,<br>reply with "unsubscribe" or use this link: ' . $url . '</div></blockquote>';
        $this->assertSame( [ 'message' => 'replied', 'contact' => 'active', 'suppressed' => 'no' ], $this->replyStates( $html, 'html' ) );

        $this->setUp();
        // The whole reply is the unquoted original after the attribution: no new text, only our own footer.
        $this->assertSame( [ 'message' => 'replied', 'contact' => 'active', 'suppressed' => 'no' ], $this->replyStates( "On Mon, Nov 9, 2026, Jane Doe wrote:\nHello,\nIf you'd rather not hear from us again, reply with \"unsubscribe\" or use this link: {$url}" ) );
    }

    // ------------------------------------------------------------------ outreach-8

    public function testCustomMessageIdIsClearedFromTheSharedPhpMailerAfterSending(): void {
        $token   = str_repeat( 'a', 32 );
        $ours    = '<ppclg-' . $token . '@purepeptidescanada.test>';
        $mailer  = new \stdClass();
        $mailer->MessageID   = $ours; // as left behind by our phpmailer_init hook inside wp_mail()
        $GLOBALS['phpmailer'] = $mailer;
        $url = Unsubscribe::urlFor( $token );

        $res = Container::get( Mailer::class )->send( [ 'id' => 1, 'subject' => 'Hello', 'body' => "Hi\n" . $url, 'unsubscribe_token' => $token ], [ 'email' => 'ed@uni.test' ], $url );
        $this->assertTrue( $res['ok'] );
        $this->assertSame( '', $mailer->MessageID, 'the next wp_mail() of this request gets its own Message-ID' );

        $mailer->MessageID = '<other@example.test>';
        Container::get( Mailer::class )->send( [ 'id' => 1, 'subject' => 'Hello', 'body' => "Hi\n" . $url, 'unsubscribe_token' => $token ], [ 'email' => 'ed@uni.test' ], $url );
        $this->assertSame( '<other@example.test>', $mailer->MessageID, 'an ID that is not ours is left alone' );
    }

    // ------------------------------------------------------------------ outreach-9

    public function testPostmarkHeadersArrayMatchesAReplyFromAnotherAddress(): void {
        [ $orig, , $messageId ] = $this->sentConversation();
        $r = $this->inbound(
            [
                'From'     => 'Colleague <colleague@other-uni.test>',
                'Subject'  => 'Re: A Canadian lab reference',
                'TextBody' => 'My colleague forwarded this; happy to add you.',
                'Headers'  => [ [ 'Name' => 'In-Reply-To', 'Value' => '<' . $messageId . '>' ], [ 'Name' => 'X-Spam-Status', 'Value' => 'No' ] ],
            ]
        );
        $this->assertSame( 1, $r['matched'] );
        $this->assertSame( OutreachMessages::STATUS_REPLIED, $this->msgStatus( $orig ) );
    }

    // ------------------------------------------------------------------ outreach-10

    public function testStaleBatchCopyIsNotSentAfterSendNow(): void {
        $id    = $this->lead( $this->campaign( Campaigns::STATUS_APPROVED ), 'race.test' );
        $stale = $this->messages()->find( $id );

        $this->assertTrue( $this->engine()->sendNow( $id, 1 )['ok'] );
        $deliver = new \ReflectionMethod( OutreachEngine::class, 'deliver' );
        $this->assertSame( [ 'skipped', 'changed' ], $deliver->invoke( $this->engine(), $stale, false, 0 ) );
        $this->assertCount( 1, $this->mailsTo( 'ed@race.test' ) );
    }

    public function testStaleBatchCopyIsNotSentAfterRejection(): void {
        $id    = $this->lead( $this->campaign( Campaigns::STATUS_APPROVED ), 'reject.test' );
        $stale = $this->messages()->find( $id );

        $this->assertTrue( $this->engine()->rejectMessage( $id, 1, 'Wrong contact' ) );
        $deliver = new \ReflectionMethod( OutreachEngine::class, 'deliver' );
        $this->assertSame( 'skipped', $deliver->invoke( $this->engine(), $stale, false, 0 )[0] );
        $this->assertSame( OutreachMessages::STATUS_REJECTED, $this->msgStatus( $id ) );
        $this->assertCount( 0, $GLOBALS['ppclg_test_mail'] );
    }

    public function testInterruptedSendIsMarkedFailedNotResent(): void {
        $id = $this->lead( $this->campaign( Campaigns::STATUS_APPROVED ), 'crash.test' );
        $this->messages()->update( $id, [ 'status' => OutreachEngine::STATUS_SENDING, 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ] );

        $this->sendDue();
        $this->assertSame( OutreachMessages::STATUS_FAILED, $this->msgStatus( $id ) );
        $this->assertStringContainsString( 'interrupted', (string) $this->messages()->find( $id )['last_error'] );
        $this->assertCount( 0, $GLOBALS['ppclg_test_mail'] );
    }

    // ------------------------------------------------------------------ outreach-11

    public function testUnsubscribeLinkFromAnEarlierSiteUrlIsRefreshedSoApprovalAndSendingAgree(): void {
        $camp = $this->campaign( Campaigns::STATUS_APPROVED );
        $p    = $this->prospect( 'uni.test' );
        $GLOBALS['ppclg_test_home'] = 'http://staging.purepeptidescanada.test';
        $id = $this->message( $camp, $p, $this->contact( $p, 'library@uni.test' ), OutreachMessages::STATUS_DRAFT );
        $GLOBALS['ppclg_test_home'] = 'https://purepeptidescanada.test';

        $m     = $this->messages()->find( $id );
        $token = (string) $m['unsubscribe_token'];
        $this->assertTrue( Container::get( Compliance::class )->bodyHasFooter( (string) $m['body'], $token ), 'no second footer is appended on edit' );
        $this->assertTrue( $this->engine()->approveMessage( $id, 1 )['ok'] );
        $this->assertTrue( $this->engine()->sendNow( $id, 1 )['ok'] );

        $mail = $this->mailsTo( 'library@uni.test' );
        $this->assertCount( 1, $mail );
        $this->assertStringContainsString( Unsubscribe::urlFor( $token ), $mail[0]['message'] );
        $this->assertStringNotContainsString( 'staging.', $mail[0]['message'] );
        $this->assertStringContainsString( Unsubscribe::urlFor( $token ), (string) $this->messages()->find( $id )['body'], 'the record keeps what was sent' );
    }

    public function testBodyWithoutAWorkingUnsubscribeLinkFailsApprovalLikeItWouldFailSending(): void {
        $camp  = $this->campaign( Campaigns::STATUS_APPROVED );
        $id    = $this->lead( $camp, 'nolink.test', OutreachMessages::STATUS_DRAFT );
        $token = (string) $this->messages()->find( $id )['unsubscribe_token'];
        $this->messages()->update( $id, [ 'body' => "Hello.\n\n222 Jarvis St, Toronto, ON M5B 2B8, Canada\nTo unsubscribe quote reference {$token}." ] );

        $r = $this->engine()->approveMessage( $id, 1 );
        $this->assertFalse( $r['ok'] );
        $this->assertContains( 'Body must contain the unsubscribe link.', $r['violations'] );
    }

    public function testRefreshLinksRewritesOnlyThisTokensUnsubscribeLinks(): void {
        $token = str_repeat( 'b', 32 );
        $other = str_repeat( 'd', 32 );
        $now   = Unsubscribe::urlFor( $token );
        $body  = "a http://old.test/wp-json/ppclg/v1/unsubscribe?t={$token}.\nb https://old.test/?rest_route=/ppclg/v1/unsubscribe&t={$token}\nc https://old.test/wp-json/ppclg/v1/unsubscribe?t={$other}\nd {$now}";
        $out   = Unsubscribe::refreshLinks( $body, $token );
        $this->assertSame( "a {$now}.\nb {$now}\nc https://old.test/wp-json/ppclg/v1/unsubscribe?t={$other}\nd {$now}", $out );
        $this->assertSame( $out, Unsubscribe::refreshLinks( $out, $token ), 'idempotent' );
    }
}
