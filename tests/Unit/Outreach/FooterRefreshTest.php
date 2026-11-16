<?php
/**
 * 1.2.1: the compliance footer of an unsent message follows the current settings (live bug on 1.2.0: four drafts
 * built while outreach.postal_address was "222 Jarvis st Toronto" were refused on every approval, automatic and
 * manual, with "Body must contain the sender postal address." after the address was corrected, and were then never
 * retried). Each test failed against 1.2.0:
 * - a stale postal address, brand or Settings → Site fallback address is replaced in place, the rest of the body is
 *   untouched, and one footer remains;
 * - a body without a recognisable footer gets one appended; sent / replied / closed / opted-out messages are never
 *   touched;
 * - the stuck drafts (and their 1.2.0 "Not approved:" note) are retried and approved under Autopilot, at once when
 *   the address is fixed in Settings, and without Autopilot only where automation already approves drafts ("Auto:"
 *   campaigns with autosend on and individual approval off), never while individual approval is required;
 * - a manual save replaces the stale footer instead of appending a second one, and an already approved message or a
 *   follow-up goes out with the current footer.
 * Review of 1.2.1 (each failed against the first 1.2.1 build): the footer carries the whole configured address on one
 * line (it was cut at 200 characters / decoded, so such a draft was retried forever while the card said "Sending");
 * a footer whose address runs over several lines, or with a blank line added, is replaced whole; a footer edited
 * beyond recognition is refused for an edit (and not retried) instead of being sent with the old address above the
 * new one; the changelog says when the stuck drafts are approved automatically.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Outreach;

use PPC\LinkGrowth\Admin\Rest\AutomationController;
use PPC\LinkGrowth\Admin\Rest\OutreachController;
use PPC\LinkGrowth\Admin\Rest\SettingsController;
use PPC\LinkGrowth\Database\Schema;
use PPC\LinkGrowth\Outreach\CampaignFiller;
use PPC\LinkGrowth\Outreach\Compliance;
use PPC\LinkGrowth\Outreach\FollowUps;
use PPC\LinkGrowth\Outreach\OutreachEngine;
use PPC\LinkGrowth\Outreach\Personalizer;
use PPC\LinkGrowth\Outreach\Unsubscribe;
use PPC\LinkGrowth\Repositories\Campaigns;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_Error;
use WP_REST_Request;

final class FooterRefreshTest extends TestCase {

    /** What outreach.postal_address said when the live drafts were built. */
    private const OLD = '222 Jarvis st Toronto';

    /** What it says now. */
    private const NEW = '222 Jarvis St, Toronto, ON M5B 2B8, Canada';

    /** The 1.2.0 note on the stuck drafts. */
    private const STUCK_NOTE = 'Not approved: Body must contain the sender postal address.';

    private const UNSUB_LINE = "If you'd rather not hear from us again";

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['ppclg_test_caps']    = [ 'manage_options' => true ];
        $GLOBALS['ppclg_test_user_id'] = 1;
        $this->settings()->update(
            [
                'outreach'   => [
                    'sender_name'       => 'Pure Peptides Canada',
                    'sender_email'      => 'jane@purepeptidescanada.test',
                    'postal_address'    => self::OLD,
                    'send_window_start' => 9,
                    'send_window_end'   => 9,
                    'send_days'         => [ 1, 2, 3, 4, 5, 6, 7 ],
                ],
                'automation' => [
                    'outreach_autosend' => true,
                    'daily_limits'      => [ 'outreach_emails' => 50 ],
                    'weekly_limits'     => [ 'outreach_emails' => 200 ],
                ],
            ]
        );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['ppclg_test_caps'] );
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

    private function engine(): OutreachEngine {
        return Container::get( OutreachEngine::class );
    }

    private function messages(): OutreachMessages {
        return Container::get( OutreachMessages::class );
    }

    /** The footer a new draft with this token gets under the current settings (built here, independently of the engine). */
    private function footerFor( string $token ): string {
        $brand = trim( (string) $this->settings()->get( 'site.brand_name', '' ) );
        return str_replace(
            [ '{{brand}}', '{{postal_address}}', '{{unsubscribe_url}}' ],
            [ '' !== $brand ? $brand : 'Pure Peptides Canada', Compliance::effectivePostalAddress( $this->settings() ), Unsubscribe::urlFor( $token ) ],
            Personalizer::FOOTER_TEMPLATE
        );
    }

    /** @param array<string,mixed> $params */
    private function request( string $method, array $params = [] ): WP_REST_Request {
        $r = new WP_REST_Request( $method, '/x' );
        foreach ( $params as $k => $v ) {
            $r->set_param( $k, $v );
        }
        return $r;
    }

    private function site( string $domain, int $score = 85, string $status = Prospects::STATUS_QUALIFIED ): int {
        $now = gmdate( 'Y-m-d H:i:s' );
        $this->db()->insert( Schema::table( 'prospects' ), [ 'domain' => $domain, 'name' => $domain, 'status' => $status, 'opportunity_type' => 'resource_page', 'score' => $score, 'discovered_via' => 'search', 'first_seen' => $now, 'updated_at' => $now ] );
        $id = (int) $this->db()->insert_id;
        $this->db()->insert( Schema::table( 'contacts' ), [ 'prospect_id' => $id, 'email' => 'editor@' . $domain, 'name' => 'Ed', 'role' => 'editor', 'confidence' => 80, 'consent_basis' => 'conspicuous_publication', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] );
        return $id;
    }

    /** An approved campaign of the owner's. */
    private function campaign(): int {
        $id = $this->engine()->createCampaign( 'Mine', 'resource_page', [], 1 );
        $this->assertTrue( $this->engine()->approveCampaign( $id, 1 ) );
        return $id;
    }

    /** A first-contact draft built under the current settings, returned as stored. @return array<string,mixed> */
    private function draft( string $domain, ?int $campaignId = null ): array {
        $p  = $this->site( $domain );
        $id = $this->engine()->draftForProspect( $campaignId ?? $this->campaign(), $p, 1 );
        $this->assertNotNull( $id );
        return $this->messages()->find( (int) $id );
    }

    /** @param array<string,mixed> $extra */
    private function insertMessage( string $body, string $status, array $extra = [] ): int {
        $p     = $this->site( 'raw-' . bin2hex( random_bytes( 3 ) ) . '.ca' );
        $c     = (int) $this->db()->get_var( "SELECT id FROM wp_ppclg_contacts WHERE prospect_id = {$p}" );
        $now   = gmdate( 'Y-m-d H:i:s' );
        $token = $extra['unsubscribe_token'] ?? bin2hex( random_bytes( 16 ) );
        $this->db()->insert( Schema::table( 'outreach_messages' ), array_merge( [ 'campaign_id' => 0, 'prospect_id' => $p, 'contact_id' => $c, 'approach' => 'resource_page', 'sequence' => 1, 'subject' => 'A Canadian lab reference for your list', 'body' => str_replace( '{url}', Unsubscribe::urlFor( $token ), $body ), 'status' => $status, 'variant' => 'rp-a', 'unsubscribe_token' => $token, 'created_at' => $now, 'updated_at' => $now ], $extra ) );
        return (int) $this->db()->insert_id;
    }

    private function refreshAudits( int $messageId ): int {
        return (int) $this->db()->get_var( $this->db()->prepare( 'SELECT COUNT(*) FROM wp_ppclg_audit_log WHERE action = %s AND object_id = %d', OutreachEngine::ACTION_FOOTER_REFRESHED, $messageId ) );
    }

    /** One footer, with the current address once and the old one nowhere. */
    private function assertCurrentFooterOnce( string $body, string $token, string $label = '' ): void {
        $this->assertSame( 1, substr_count( $body, self::UNSUB_LINE ), $label . ': exactly one footer' );
        $this->assertSame( 1, substr_count( $body, $this->footerFor( $token ) ), $label . ': the current footer' );
        $this->assertSame( 1, substr_count( $body, self::NEW ), $label . ': the current address once' );
        $this->assertSame( 0, substr_count( $body, self::OLD ), $label . ': the old address line is gone' );
        $this->assertTrue( Container::get( Compliance::class )->bodyHasFooter( $body, $token ), $label );
    }

    private function enableAutopilot(): array {
        $res = ( new AutomationController( $this->c() ) )->setAutopilot( $this->request( 'POST', [ 'enabled' => true, 'confirm' => true ] ) );
        $this->assertIsArray( $res );
        $this->assertTrue( $res['enabled'] );
        return $res;
    }

    // ------------------------------------------------------------------ the refresh itself

    public function testAStaleAddressIsReplacedInPlaceAndNothingElseChanges(): void {
        $m         = $this->draft( 'stale-rp.ca' );
        $token     = (string) $m['unsubscribe_token'];
        $before    = (string) $m['body'];
        $oldFooter = $this->footerFor( $token );
        $this->assertSame( 1, substr_count( $before, $oldFooter ), 'precondition: the draft carries the footer of its day' );
        $this->assertStringContainsString( self::OLD, $before );

        $this->settings()->set( 'outreach.postal_address', self::NEW );
        $this->assertFalse( Container::get( Compliance::class )->bodyHasFooter( $before, $token ), 'precondition: the 1.2.0 refusal' );

        $row = $this->engine()->refreshFooter( (int) $m['id'] );
        $this->assertSame( str_replace( $oldFooter, $this->footerFor( $token ), $before ), $row['body'], 'only the footer block changed, byte for byte' );
        $this->assertSame( $row['body'], (string) $this->messages()->find( (int) $m['id'] )['body'], 'stored' );
        $this->assertCurrentFooterOnce( (string) $row['body'], $token, 'refreshed' );
        $this->assertSame( $token, $row['unsubscribe_token'], 'the unsubscribe token is kept' );
        $this->assertSame( 'draft', $row['status'] );
        $this->assertSame( 1, $this->refreshAudits( (int) $m['id'] ) );

        // Nothing left to do: no second write, no second audit entry.
        $again = $this->engine()->refreshFooter( $row );
        $this->assertSame( $row['body'], $again['body'] );
        $this->assertSame( $row['updated_at'], $this->messages()->find( (int) $m['id'] )['updated_at'] );
        $this->assertSame( 1, $this->refreshAudits( (int) $m['id'] ) );
    }

    public function testABrandChangeRefreshesTheFooterLineOnly(): void {
        $this->settings()->set( 'outreach.postal_address', self::NEW );
        $m         = $this->draft( 'brand-rp.ca' );
        $token     = (string) $m['unsubscribe_token'];
        $before    = (string) $m['body'];
        $oldFooter = $this->footerFor( $token );
        $this->assertStringStartsWith( 'Pure Peptides Canada · ', $oldFooter );

        $this->settings()->set( 'site.brand_name', 'PPC Research Supply' );
        $this->assertTrue( Container::get( Compliance::class )->bodyHasFooter( $before, $token ), 'address and link are still there' );
        $row = $this->engine()->refreshFooter( (int) $m['id'] );
        $this->assertSame( str_replace( $oldFooter, $this->footerFor( $token ), $before ), $row['body'] );
        $this->assertStringContainsString( "\nPPC Research Supply · " . self::NEW . "\n" . self::UNSUB_LINE, (string) $row['body'] );
        $this->assertSame( substr_count( $before, 'Pure Peptides Canada' ) - 1, substr_count( (string) $row['body'], 'Pure Peptides Canada' ), 'the brand elsewhere in the copy is the writer\'s, not the footer\'s' );
        $this->assertSame( 1, $this->refreshAudits( (int) $m['id'] ) );
    }

    public function testTheSiteAddressFallbackIsFollowedToo(): void {
        // No outreach postal address: the footer uses the business address under Settings → Site.
        $this->settings()->update( [ 'outreach' => [ 'postal_address' => '' ], 'site' => [ 'address' => '100 King St W', 'city' => 'Toronto', 'region' => 'ON', 'postal_code' => 'M5X 1A9', 'country' => 'Canada' ] ] );
        $m     = $this->draft( 'fallback-rp.ca' );
        $token = (string) $m['unsubscribe_token'];
        $this->assertStringContainsString( '· 100 King St W, Toronto, ON M5X 1A9, Canada', (string) $m['body'] );

        $this->settings()->update( [ 'site' => [ 'address' => '222 Jarvis St', 'postal_code' => 'M5B 2B8' ] ] );
        $r = $this->engine()->approveMessage( (int) $m['id'], 1 );
        $this->assertTrue( $r['ok'], implode( ' ', $r['violations'] ) );
        $body = (string) $this->messages()->find( (int) $m['id'] )['body'];
        $this->assertCurrentFooterOnce( $body, $token, 'fallback' );
        $this->assertStringNotContainsString( '100 King St W', $body );
    }

    public function testABodyWithoutARecognisableFooterGetsOneAppended(): void {
        $this->settings()->set( 'outreach.postal_address', self::NEW );

        $bare  = $this->insertMessage( "Hello,\n\nA short note about your list.\n\nJane", OutreachMessages::STATUS_DRAFT );
        $token = (string) $this->messages()->find( $bare )['unsubscribe_token'];
        $row   = $this->engine()->refreshFooter( $bare );
        $this->assertSame( "Hello,\n\nA short note about your list.\n\nJane\n\n" . $this->footerFor( $token ), $row['body'] );
        $this->assertSame( 1, $this->refreshAudits( $bare ) );

        // Only the unsubscribe line is left (the brand / address line above it was deleted): it becomes the footer.
        $lone  = $this->insertMessage( "Hello,\n\nA short note.\n\nJane\n\n" . self::UNSUB_LINE . ', reply with "unsubscribe" or use this link: {url}', OutreachMessages::STATUS_DRAFT );
        $token = (string) $this->messages()->find( $lone )['unsubscribe_token'];
        $this->assertSame( "Hello,\n\nA short note.\n\nJane\n\n" . $this->footerFor( $token ), $this->engine()->refreshFooter( $lone )['body'] );

        // A body whose own wording already carries the current address and the link is not rewritten.
        $own  = "Hello,\n\nWrite to us at " . self::NEW . " any time.\nTo stop these emails: {url}";
        $mine = $this->insertMessage( $own, OutreachMessages::STATUS_DRAFT );
        $kept = (string) $this->messages()->find( $mine )['body'];
        $this->assertSame( $kept, $this->engine()->refreshFooter( $mine )['body'] );
        $this->assertSame( 0, $this->refreshAudits( $mine ) );
    }

    public function testAStaleFooterLeftAboveACurrentOneIsRemoved(): void {
        // 1.2.0's manual save appended the current footer under the stale one.
        $m         = $this->draft( 'twice-rp.ca' );
        $token     = (string) $m['unsubscribe_token'];
        $stale     = (string) $m['body'];
        $oldFooter = $this->footerFor( $token );
        $this->settings()->set( 'outreach.postal_address', self::NEW );
        $this->messages()->update( (int) $m['id'], [ 'body' => rtrim( $stale ) . "\n\n" . $this->footerFor( $token ) ] );

        $row = $this->engine()->refreshFooter( (int) $m['id'] );
        $this->assertSame( str_replace( $oldFooter, $this->footerFor( $token ), $stale ), $row['body'] );
        $this->assertCurrentFooterOnce( (string) $row['body'], $token, 'deduplicated' );
    }

    public function testSentAndEndedMessagesAreNeverTouched(): void {
        $m     = $this->draft( 'done-rp.ca' );
        $body  = (string) $m['body'];
        $stale = str_replace( Unsubscribe::urlFor( (string) $m['unsubscribe_token'] ), '{url}', $body ); // each row gets its own link
        $this->settings()->set( 'outreach.postal_address', self::NEW );
        foreach ( [ OutreachMessages::STATUS_SENT, OutreachMessages::STATUS_REPLIED, OutreachMessages::STATUS_CLOSED, OutreachMessages::STATUS_OPTED_OUT, OutreachMessages::STATUS_BOUNCED, OutreachEngine::STATUS_SENDING ] as $status ) {
            $id   = $this->insertMessage( $stale, $status );
            $row  = $this->messages()->find( $id );
            $sent = (string) $row['body'];
            $this->assertStringContainsString( self::OLD, $sent );
            $this->assertSame( $row, $this->engine()->refreshFooter( $row ), $status . ' is returned as it is' );
            $this->assertSame( $row, $this->engine()->refreshFooter( $id ), $status . ' by id too' );
            $this->assertSame( $sent, (string) $this->messages()->find( $id )['body'], $status . ' keeps the body it was sent with' );
            $this->assertSame( 0, $this->refreshAudits( $id ), $status );
        }
        $this->assertSame( $body, (string) $this->messages()->find( (int) $m['id'] )['body'], 'the draft itself is only refreshed when asked' );
        $this->assertSame( [], $this->engine()->refreshFooter( 999999 ), 'a missing message' );

        // Failed and rejected messages are not sent yet: they follow the settings like drafts.
        foreach ( [ OutreachMessages::STATUS_FAILED, OutreachMessages::STATUS_REJECTED, OutreachMessages::STATUS_APPROVED, OutreachMessages::STATUS_SCHEDULED ] as $status ) {
            $stale = $this->draft( $status . '-rp.ca' );
            $this->messages()->update( (int) $stale['id'], [ 'status' => $status, 'body' => str_replace( self::NEW, self::OLD, (string) $stale['body'] ) ] );
            $this->assertCurrentFooterOnce( (string) $this->engine()->refreshFooter( (int) $stale['id'] )['body'], (string) $stale['unsubscribe_token'], $status );
        }
    }

    // ------------------------------------------------------------------ the stuck drafts

    public function testTheStuckDraftsAreApprovedUnderAutopilotAndSentWithTheCurrentAddress(): void {
        // The live sequence: drafts built under the old address, the address corrected, then Autopilot switched on.
        $this->site( 'live-rp-one.ca' );
        $this->site( 'live-rp-two.ca' );
        $res = ( new OutreachController( $this->c() ) )->createCampaign( $this->request( 'POST', [ 'name' => 'Run 1', 'approach' => 'resource_page', 'settings' => [ 'daily_limit' => 5 ] ] ) );
        $this->assertIsArray( $res, $res instanceof WP_Error ? $res->get_error_message() : '' );
        $campaignId = (int) $res['campaign_id'];
        $drafts     = $this->messages()->findBy( [ 'campaign_id' => $campaignId ], 'id', 'ASC', 10 );
        $this->assertCount( 2, $drafts );

        $this->settings()->set( 'outreach.postal_address', self::NEW );
        foreach ( $drafts as $d ) {
            $this->messages()->update( (int) $d['id'], [ 'last_error' => self::STUCK_NOTE ] ); // what 1.2.0 wrote
        }
        $this->assertFalse( CampaignFiller::needsEdit( self::STUCK_NOTE ), 'retried, not left for a person' );
        $this->assertFalse( CampaignFiller::needsEdit( 'Not approved: Body must contain the unsubscribe link.' ) );
        $this->assertTrue( CampaignFiller::needsEdit( 'Not approved: Claims: Medical claim ("cures").' ), 'real edits still need a person' );
        $step = Container::get( CampaignFiller::class )->nextStep( Container::get( Campaigns::class )->find( $campaignId ) );
        $this->assertSame( 0, $step['needs_edit'], json_encode( $step ) );
        $this->assertSame( 2, $step['waiting'] );

        $on = $this->enableAutopilot();
        $this->assertSame( 2, $on['approved_now'] );
        foreach ( $drafts as $d ) {
            $row = $this->messages()->find( (int) $d['id'] );
            $this->assertSame( 'approved', $row['status'] );
            $this->assertSame( '', (string) $row['last_error'] );
            $this->assertCurrentFooterOnce( (string) $row['body'], (string) $d['unsubscribe_token'], 'approved #' . $d['id'] );
            $this->assertSame( str_replace( $this->footerForOld( (string) $d['unsubscribe_token'] ), $this->footerFor( (string) $d['unsubscribe_token'] ), (string) $d['body'] ), (string) $row['body'] );
            $this->assertSame( 1, $this->refreshAudits( (int) $d['id'] ) );
        }
        $this->assertSame( 'sending', Container::get( CampaignFiller::class )->nextStep( Container::get( Campaigns::class )->find( $campaignId ) )['state'] );

        $this->assertSame( 2, $this->engine()->sendDue( TimeBudget::seconds( 30.0 ) )['sent'] );
        $this->assertCount( 2, $GLOBALS['ppclg_test_mail'] );
        foreach ( $GLOBALS['ppclg_test_mail'] as $mail ) {
            $this->assertSame( 1, substr_count( $mail['message'], self::NEW ) );
            $this->assertSame( 0, substr_count( $mail['message'], self::OLD ) );
        }
    }

    public function testFixingTheAddressInSettingsApprovesTheWaitingDraftsAtOnceUnderAutopilot(): void {
        $this->enableAutopilot();
        $m = $this->draft( 'settings-rp.ca' );
        $this->assertSame( 'draft', $m['status'], 'precondition: built, not yet approved' );
        $this->assertStringContainsString( self::OLD, (string) $m['body'] );

        $saved = ( new SettingsController( $this->c() ) )->update( $this->request( 'POST', [ 'settings' => [ 'outreach' => [ 'postal_address' => self::NEW ] ] ] ) );
        $this->assertIsArray( $saved, $saved instanceof WP_Error ? $saved->get_error_message() : '' );
        $this->assertSame( 1, $saved['approved_now'] );
        $row = $this->messages()->find( (int) $m['id'] );
        $this->assertSame( 'approved', $row['status'], (string) $row['last_error'] );
        $this->assertCurrentFooterOnce( (string) $row['body'], (string) $m['unsubscribe_token'], 'approved from Settings' );
    }

    public function testWithIndividualApprovalRequiredNothingIsApprovedAutomaticallyButTheOwnersApprovalWorks(): void {
        $m = $this->draft( 'manual-rp.ca' );
        $this->settings()->set( 'outreach.postal_address', self::NEW );
        $this->messages()->update( (int) $m['id'], [ 'last_error' => self::STUCK_NOTE ] );

        // Autosend is on but "Require individual approval" is too: automation approves nothing.
        $saved = ( new SettingsController( $this->c() ) )->update( $this->request( 'POST', [ 'settings' => [ 'site' => [ 'brand_name' => 'Pure Peptides Canada Inc.' ] ] ] ) );
        $this->assertIsArray( $saved );
        $this->assertNull( $saved['approved_now'] ?? null );
        Container::get( CampaignFiller::class )->approveWaiting( TimeBudget::seconds( 30.0 ) );
        $this->assertSame( 'draft', $this->messages()->find( (int) $m['id'] )['status'] );
        $this->assertFalse( $this->engine()->approveMessage( (int) $m['id'], 0 )['ok'] );
        $this->assertSame( 'draft', $this->messages()->find( (int) $m['id'] )['status'] );

        $r = $this->engine()->approveMessage( (int) $m['id'], 1 );
        $this->assertTrue( $r['ok'], implode( ' ', $r['violations'] ) );
        $row = $this->messages()->find( (int) $m['id'] );
        $this->assertSame( 'approved', $row['status'] );
        $this->assertCurrentFooterOnce( (string) $row['body'], (string) $m['unsubscribe_token'], 'owner approval' );
        $this->assertStringContainsString( "\nPure Peptides Canada Inc. · " . self::NEW, (string) $row['body'] );
        $this->assertSame( 'user:1', (string) $this->db()->get_var( "SELECT actor FROM wp_ppclg_audit_log WHERE action = 'outreach.footer_refreshed' ORDER BY id DESC LIMIT 1" ) );
    }

    // ------------------------------------------------------------------ manual save, send path, follow-ups

    public function testAManualSaveReplacesTheStaleFooterInsteadOfAddingASecondOne(): void {
        $m         = $this->draft( 'edit-rp.ca' );
        $token     = (string) $m['unsubscribe_token'];
        $oldFooter = $this->footerFor( $token );
        $this->settings()->set( 'outreach.postal_address', self::NEW );

        $edited = preg_replace( '/^Hi Ed,/', 'Hello Ed,', (string) $m['body'] ) ?? '';
        $this->assertNotSame( (string) $m['body'], $edited );
        $r = $this->engine()->updateMessage( (int) $m['id'], (string) $m['subject'], $edited, 1 );
        $this->assertTrue( $r['ok'], implode( ' ', $r['violations'] ) );
        $body = (string) $this->messages()->find( (int) $m['id'] )['body'];
        $this->assertSame( str_replace( $oldFooter, $this->footerFor( $token ), $edited ), $body, 'the edit is kept, the footer replaced in place' );
        $this->assertCurrentFooterOnce( $body, $token, 'saved' );
        $details = (string) $this->db()->get_var( "SELECT details FROM wp_ppclg_audit_log WHERE action = 'outreach.message_updated' ORDER BY id DESC LIMIT 1" );
        $this->assertStringContainsString( '"footer_refreshed":true', $details );
        $this->assertTrue( $this->engine()->approveMessage( (int) $m['id'], 1 )['ok'] );
    }

    public function testAnApprovedMessageGoesOutWithTheCurrentFooter(): void {
        $m = $this->draft( 'send-rp.ca' );
        $this->assertTrue( $this->engine()->approveMessage( (int) $m['id'], 1 )['ok'], 'approved under the old address' );
        $this->settings()->set( 'outreach.postal_address', self::NEW );

        $out = $this->engine()->sendDue( TimeBudget::seconds( 30.0 ) );
        $this->assertSame( 1, $out['sent'], json_encode( $out ) );
        $row = $this->messages()->find( (int) $m['id'] );
        $this->assertSame( 'sent', $row['status'] );
        $this->assertSame( $row['body'], $GLOBALS['ppclg_test_mail'][0]['message'], 'the record keeps what was sent' );
        $this->assertCurrentFooterOnce( (string) $row['body'], (string) $m['unsubscribe_token'], 'sent' );
        $this->assertSame( 'system:outreach', (string) $this->db()->get_var( "SELECT actor FROM wp_ppclg_audit_log WHERE action = 'outreach.footer_refreshed' ORDER BY id DESC LIMIT 1" ) );
    }

    public function testAFollowUpDraftFollowsTheCurrentAddressToo(): void {
        $camp = $this->campaign();
        $p    = $this->site( 'fu-rp.ca', 85, Prospects::STATUS_IN_PROGRESS );
        $c    = (int) $this->db()->get_var( "SELECT id FROM wp_ppclg_contacts WHERE prospect_id = {$p}" );
        $old  = gmdate( 'Y-m-d H:i:s', time() - 9 * DAY_IN_SECONDS );
        $this->db()->insert( Schema::table( 'outreach_messages' ), [ 'campaign_id' => $camp, 'prospect_id' => $p, 'contact_id' => $c, 'approach' => 'resource_page', 'sequence' => 1, 'subject' => 'Hello', 'body' => 'x', 'status' => 'sent', 'sent_at' => $old, 'unsubscribe_token' => bin2hex( random_bytes( 16 ) ), 'created_at' => $old, 'updated_at' => $old ] );
        Container::get( FollowUps::class )->generateDue( TimeBudget::seconds( 30.0 ) );
        $follow = $this->messages()->findOneBy( [ 'prospect_id' => $p, 'sequence' => 2 ] );
        $this->assertNotNull( $follow );
        $this->assertStringContainsString( self::OLD, (string) $follow['body'] );

        $this->settings()->set( 'outreach.postal_address', self::NEW );
        $r = $this->engine()->approveMessage( (int) $follow['id'], 1 );
        $this->assertTrue( $r['ok'], implode( ' ', $r['violations'] ) );
        $this->assertCurrentFooterOnce( (string) $this->messages()->find( (int) $follow['id'] )['body'], (string) $follow['unsubscribe_token'], 'follow-up' );
    }

    // ------------------------------------------------------------------ review of 1.2.1

    /** The draft's footer line ("<brand> · <address>"), which must be the only line with the separator. */
    private function footerLines( string $body ): array {
        return array_values( array_filter( explode( "\n", $body ), static fn( string $l ): bool => str_contains( $l, ' · ' ) ) );
    }

    public function testALongPostalAddressIsCarriedInFullSoTheDraftIsApprovedInsteadOfRetriedForever(): void {
        // The settings field allows 2000 characters; the footer used to cut the address at 200, so a draft could never
        // pass the check (which wants the whole address) and was retried on every run while the card said "Sending".
        $long = trim( '222 Jarvis St, Toronto, ON M5B 2B8, Canada, c/o ' . str_repeat( 'Research Supply Division ', 8 ) );
        $this->assertGreaterThan( 200, mb_strlen( $long ) );
        $this->settings()->set( 'outreach.postal_address', $long );
        $this->enableAutopilot();
        $m = $this->draft( 'long-rp.ca' );
        $this->assertStringContainsString( '· ' . $long . "\n" . self::UNSUB_LINE, (string) $m['body'], 'the whole address, as configured' );

        $out = Container::get( CampaignFiller::class )->approveWaiting( TimeBudget::seconds( 30.0 ) );
        $this->assertSame( 1, $out['messages'], json_encode( $out ) );
        $row = $this->messages()->find( (int) $m['id'] );
        $this->assertSame( 'approved', $row['status'], (string) $row['last_error'] );
        $this->assertSame( 1, $this->engine()->sendDue( TimeBudget::seconds( 30.0 ) )['sent'] );
        $this->assertSame( 1, substr_count( $GLOBALS['ppclg_test_mail'][0]['message'], $long ) );
    }

    public function testTheFooterCarriesExactlyTheAddressTheCheckWantsOnOneLine(): void {
        // What the footer prints and what Compliance::assess() looks for are the same text: nothing is decoded, cut or
        // re-punctuated, and an address written on several lines (both address fields are textareas) is one line.
        $cases = [
            'entity' => [ 'Smith &amp; Co Research, 222 Jarvis St, Toronto, ON M5B 2B8', 'Smith &amp; Co Research, 222 Jarvis St, Toronto, ON M5B 2B8' ],
            'colon'  => [ 'Attn : Lab Supply, 222 Jarvis St, Toronto, ON M5B 2B8', 'Attn : Lab Supply, 222 Jarvis St, Toronto, ON M5B 2B8' ],
            'lines'  => [ "222 Jarvis St\nUnit 1,\n\nToronto, ON M5B 2B8", '222 Jarvis St, Unit 1, Toronto, ON M5B 2B8' ],
        ];
        foreach ( $cases as $label => [ $address, $line ] ) {
            $this->settings()->set( 'outreach.postal_address', $address );
            $m = $this->draft( $label . '-addr-rp.ca' );
            $r = $this->engine()->approveMessage( (int) $m['id'], 1 );
            $this->assertTrue( $r['ok'], $label . ': ' . implode( ' ', $r['violations'] ) );
            $body = (string) $this->messages()->find( (int) $m['id'] )['body'];
            $this->assertSame( [ 'Pure Peptides Canada · ' . $line ], $this->footerLines( $body ), $label );
            $this->assertStringContainsString( 'Pure Peptides Canada · ' . $line . "\n" . self::UNSUB_LINE, $body, $label );
            $this->assertSame( 1, substr_count( $body, self::UNSUB_LINE ), $label );
        }
    }

    public function testAMultiLineBusinessAddressGivesOneFooterThatIsReplacedWhole(): void {
        // outreach.postal_address empty: the footer uses Settings → Site, whose street field is a textarea.
        $saved = ( new SettingsController( $this->c() ) )->update( $this->request( 'POST', [ 'settings' => [ 'outreach' => [ 'postal_address' => '' ], 'site' => [ 'address' => "100 King St W\nSuite 4000", 'city' => 'Toronto', 'region' => 'ON', 'postal_code' => 'M5X 1A9', 'country' => 'CA' ] ] ] ) );
        $this->assertIsArray( $saved, $saved instanceof WP_Error ? $saved->get_error_message() : '' );
        $this->assertStringContainsString( "\n", (string) $this->settings()->get( 'site.address' ), 'precondition: stored on two lines' );
        $m = $this->draft( 'multiline-rp.ca' );
        $this->assertSame( [ 'Pure Peptides Canada · 100 King St W, Suite 4000, Toronto, ON M5X 1A9, CA' ], $this->footerLines( (string) $m['body'] ) );

        // A footer 1.2.0 rendered with the address on two lines.
        $legacy = $this->insertMessage( "Hi Ed,\n\nA short note.\n\nJane\n\nPure Peptides Canada · 100 King St W\nSuite 4000, Toronto, ON M5X 1A9, CA\n" . self::UNSUB_LINE . ', reply with "unsubscribe" or use this link: {url}', OutreachMessages::STATUS_APPROVED );

        ( new SettingsController( $this->c() ) )->update( $this->request( 'POST', [ 'settings' => [ 'site' => [ 'address' => "222 Jarvis St\nUnit 1", 'postal_code' => 'M5B 2B8' ] ] ] ) );
        $current = 'Pure Peptides Canada · 222 Jarvis St, Unit 1, Toronto, ON M5B 2B8, CA';
        $this->assertTrue( $this->engine()->approveMessage( (int) $m['id'], 1 )['ok'] );
        $out = $this->engine()->sendDue( TimeBudget::seconds( 30.0 ) );
        $this->assertSame( 2, $out['sent'], json_encode( $out ) );
        foreach ( $GLOBALS['ppclg_test_mail'] as $mail ) {
            $this->assertSame( [ $current ], $this->footerLines( $mail['message'] ), 'one footer line, the current one' );
            $this->assertStringContainsString( $current . "\n" . self::UNSUB_LINE, $mail['message'] );
            $this->assertSame( 0, substr_count( $mail['message'], '100 King St W' ) );
            $this->assertSame( 0, substr_count( $mail['message'], 'Suite 4000' ) );
            $this->assertSame( 1, substr_count( $mail['message'], self::UNSUB_LINE ) );
        }
        $this->assertStringStartsWith( "Hi Ed,\n\nA short note.\n\nJane\n\n" . $current . "\n", (string) $this->messages()->find( $legacy )['body'], 'only the footer changed' );
    }

    public function testAnEditedFooterIsReplacedWholeInsteadOfLeavingTheOldAddressAboveTheNewOne(): void {
        $edits = [
            'blank line added' => static fn( string $b ): string => str_replace( "\n" . self::UNSUB_LINE, "\n\n" . self::UNSUB_LINE, $b ),
            'address wrapped'  => static fn( string $b ): string => str_replace( '· ' . self::OLD, "· 222 Jarvis st\nToronto", $b ),
            'link deleted'     => static fn( string $b ): string => (string) preg_replace( '/\n' . preg_quote( self::UNSUB_LINE, '/' ) . '[^\n]*/u', '', $b ),
        ];
        $ids = [];
        foreach ( $edits as $label => $edit ) {
            $this->settings()->set( 'outreach.postal_address', self::OLD );
            $m      = $this->draft( str_replace( ' ', '-', $label ) . '-rp.ca' );
            $edited = $edit( (string) $m['body'] );
            $this->assertNotSame( (string) $m['body'], $edited, $label );
            $r = $this->engine()->updateMessage( (int) $m['id'], (string) $m['subject'], $edited, 1 );
            $this->assertTrue( $r['ok'], $label . ': ' . implode( ' ', $r['violations'] ) );
            $ids[ $label ] = (int) $m['id'];
        }
        $this->settings()->set( 'outreach.postal_address', self::NEW );
        foreach ( $ids as $label => $id ) {
            $r = $this->engine()->approveMessage( $id, 1 );
            $this->assertTrue( $r['ok'], $label . ': ' . implode( ' ', $r['violations'] ) );
            $row = $this->messages()->find( $id );
            $this->assertCurrentFooterOnce( (string) $row['body'], (string) $row['unsubscribe_token'], $label );
            $this->assertSame( 0, substr_count( (string) $row['body'], '222 Jarvis st' ), $label . ': no line of the old address is left' );
            $this->assertSame( [ 'Pure Peptides Canada · ' . self::NEW ], $this->footerLines( (string) $row['body'] ), $label );
        }
        $this->assertSame( 3, $this->engine()->sendDue( TimeBudget::seconds( 30.0 ) )['sent'] );
        foreach ( $GLOBALS['ppclg_test_mail'] as $mail ) {
            $this->assertSame( 0, substr_count( $mail['message'], '222 Jarvis st' ) );
            $this->assertSame( 1, substr_count( $mail['message'], self::NEW ) );
        }
    }

    public function testAFooterLineEditedBeyondRecognitionIsRefusedForAnEditNotSentWithTwoAddresses(): void {
        // "·" replaced: the line above the unsubscribe line may hold the old address, so the footer is not guessed at.
        $this->enableAutopilot();
        $m      = $this->draft( 'dash-rp.ca' );
        $edited = str_replace( 'Pure Peptides Canada · ' . self::OLD, 'Pure Peptides Canada - ' . self::OLD, (string) $m['body'] );
        $this->assertNotSame( (string) $m['body'], $edited );
        $this->assertTrue( $this->engine()->updateMessage( (int) $m['id'], (string) $m['subject'], $edited, 1 )['ok'], 'fine under the address it shows' );
        $this->settings()->set( 'outreach.postal_address', self::NEW );

        $filler = Container::get( CampaignFiller::class );
        $this->assertSame( 0, $filler->approveWaiting( TimeBudget::seconds( 30.0 ) )['messages'] );
        $row = $this->messages()->find( (int) $m['id'] );
        $this->assertSame( 'draft', $row['status'] );
        $this->assertSame( $edited, (string) $row['body'], 'nothing guessed: the body is as the owner left it' );
        $this->assertStringStartsWith( self::STUCK_NOTE . ' ', (string) $row['last_error'], 'the reason, then what to do' );
        $this->assertStringContainsString( OutreachEngine::FOOTER_EDIT_HINT, (string) $row['last_error'] );
        $this->assertTrue( CampaignFiller::needsEdit( (string) $row['last_error'] ), 'needs a person, so it is not retried on every run' );
        $step = $filler->nextStep( Container::get( Campaigns::class )->find( (int) $m['campaign_id'] ) );
        $this->assertSame( 1, $step['needs_edit'], json_encode( $step ) );
        $this->assertSame( 'needs_edit', $step['state'], json_encode( $step ) );

        // The next run leaves it alone (no rewrite of the note, no second attempt).
        $this->db()->query( $this->db()->prepare( 'UPDATE wp_ppclg_outreach_messages SET updated_at = %s WHERE id = %d', '2026-01-01 00:00:00', (int) $m['id'] ) );
        $this->assertSame( 0, $filler->approveWaiting( TimeBudget::seconds( 30.0 ) )['messages'] );
        $this->assertSame( '2026-01-01 00:00:00', (string) $this->messages()->find( (int) $m['id'] )['updated_at'] );
        $this->assertSame( 0, $this->engine()->sendDue( TimeBudget::seconds( 30.0 ) )['sent'] );
        $this->assertSame( [], $GLOBALS['ppclg_test_mail'] );

        // Saving the same body says so too; the owner's approval says what to do.
        $save = $this->engine()->updateMessage( (int) $m['id'], (string) $m['subject'], $edited, 1 );
        $this->assertFalse( $save['ok'] );
        $this->assertContains( OutreachEngine::FOOTER_EDIT_HINT, $save['violations'] );
        $r = $this->engine()->approveMessage( (int) $m['id'], 1 );
        $this->assertFalse( $r['ok'] );
        $this->assertContains( 'Body must contain the sender postal address.', $r['violations'] );
        $this->assertContains( OutreachEngine::FOOTER_EDIT_HINT, $r['violations'] );

        // Deleting the edited line, as the hint says, gives the current footer; then it is approved and sent.
        $fixed = str_replace( 'Pure Peptides Canada - ' . self::OLD . "\n", '', $edited );
        $this->assertTrue( $this->engine()->updateMessage( (int) $m['id'], (string) $m['subject'], $fixed, 1 )['ok'] );
        $this->assertSame( 1, $filler->approveWaiting( TimeBudget::seconds( 30.0 ) )['messages'] );
        $this->assertSame( 1, $this->engine()->sendDue( TimeBudget::seconds( 30.0 ) )['sent'] );
        $this->assertCurrentFooterOnce( $GLOBALS['ppclg_test_mail'][0]['message'], (string) $m['unsubscribe_token'], 'sent' );
    }

    public function testOwnUnsubscribeWordingWithAnOldAddressIsNotPatchedWithASecondFooter(): void {
        // The link is there in the owner's own words, the current address is not: the old one may be in those words.
        $this->settings()->set( 'outreach.postal_address', self::NEW );
        $own   = "Hello,\n\nA short note.\n\nJane\n\nPure Peptides Canada, " . self::OLD . '. To opt out of these emails: {url}';
        $id    = $this->insertMessage( $own, OutreachMessages::STATUS_DRAFT );
        $row   = $this->messages()->find( $id );
        $token = (string) $row['unsubscribe_token'];
        $body  = (string) $row['body'];
        $r     = $this->engine()->approveMessage( $id, 1 );
        $this->assertFalse( $r['ok'] );
        $this->assertContains( OutreachEngine::FOOTER_EDIT_HINT, $r['violations'] );
        $this->assertSame( $body, (string) $this->messages()->find( $id )['body'] );
        $this->assertFalse( $this->engine()->sendNow( $id, 1 )['ok'], 'not sent either' );
        $this->assertSame( [], $GLOBALS['ppclg_test_mail'] );

        // Deleting those lines, as the note says, gives the current footer.
        $fixed = (string) preg_replace( '/\n\nPure Peptides Canada, [^\n]*$/u', '', $body );
        $this->assertTrue( $this->engine()->updateMessage( $id, (string) $row['subject'], $fixed, 1 )['ok'] );
        $this->assertSame( "Hello,\n\nA short note.\n\nJane\n\n" . $this->footerFor( $token ), (string) $this->messages()->find( $id )['body'] );
        $this->assertTrue( $this->engine()->approveMessage( $id, 1 )['ok'] );
    }

    public function testOnlyTheNotesOlderBuildsWroteForAStaleFooterCountAsWaiting(): void {
        foreach ( [ self::STUCK_NOTE, 'Not approved: Body must contain the unsubscribe link.', 'Not approved: Body must contain the unsubscribe link. Body must contain the sender postal address.' ] as $note ) {
            $this->assertFalse( CampaignFiller::needsEdit( $note ), $note );
        }
        foreach ( [ self::STUCK_NOTE . ' ' . OutreachEngine::FOOTER_EDIT_HINT, self::STUCK_NOTE . ' Claims: Medical claim ("cures").' ] as $note ) {
            $this->assertTrue( CampaignFiller::needsEdit( $note ), $note );
        }
        // GrowthRun counts the same way (SQL).
        $camp = $this->campaign();
        foreach ( [ self::STUCK_NOTE, self::STUCK_NOTE . ' ' . OutreachEngine::FOOTER_EDIT_HINT ] as $i => $note ) {
            $d = $this->draft( 'note-' . $i . '-rp.ca', $camp );
            $this->messages()->update( (int) $d['id'], [ 'last_error' => $note ] );
        }
        $method = new \ReflectionMethod( \PPC\LinkGrowth\Growth\GrowthRun::class, 'draftsNeedingEdit' );
        $this->assertSame( 1, $method->invoke( Container::get( \PPC\LinkGrowth\Growth\GrowthRun::class ) ) );
    }

    public function testASignatureLineWithTheSeparatorIsNotTakenForTheFooter(): void {
        $this->settings()->set( 'outreach.signature', "Jane Doe · Outreach lead\nPure Peptides Canada" );
        $m = $this->draft( 'sig-rp.ca' );
        $this->assertStringContainsString( "Jane Doe · Outreach lead\nPure Peptides Canada\n\nPure Peptides Canada · " . self::OLD . "\n", (string) $m['body'] );
        // The owner deletes the footer's first line; the blank line under the signature stays.
        $edited = str_replace( "\nPure Peptides Canada · " . self::OLD, '', (string) $m['body'] );
        $this->settings()->set( 'outreach.postal_address', self::NEW );
        $this->assertTrue( $this->engine()->updateMessage( (int) $m['id'], (string) $m['subject'], $edited, 1 )['ok'] );
        $body = (string) $this->messages()->find( (int) $m['id'] )['body'];
        $this->assertStringContainsString( "Jane Doe · Outreach lead\nPure Peptides Canada\n\n" . $this->footerFor( (string) $m['unsubscribe_token'] ), $body, 'the signature is kept' );
        $this->assertCurrentFooterOnce( $body, (string) $m['unsubscribe_token'], 'signature' );
    }

    public function testWithAutosendAndNoIndividualApprovalTheAutoCampaignsStuckDraftsAreApprovedWithoutAutopilot(): void {
        // What the 1.2.1 changelog says: automation approves the stuck drafts wherever it already approves drafts.
        $this->settings()->set( 'outreach.require_approval', false );
        $auto = $this->engine()->createCampaign( CampaignFiller::AUTO_PREFIX . 'Resource page inclusion', 'resource_page', [], 0 );
        $this->assertTrue( $this->engine()->approveCampaign( $auto, 1 ) );
        $inAuto = $this->draft( 'auto-stuck-rp.ca', $auto );
        $mine   = $this->draft( 'mine-stuck-rp.ca' );
        $this->settings()->set( 'outreach.postal_address', self::NEW );
        foreach ( [ $inAuto, $mine ] as $d ) {
            $this->messages()->update( (int) $d['id'], [ 'last_error' => self::STUCK_NOTE ] );
        }
        $this->assertFalse( Container::get( \PPC\LinkGrowth\Automation\Autopilot::class )->isOn() );

        $this->assertSame( 1, Container::get( CampaignFiller::class )->approveWaiting( TimeBudget::seconds( 30.0 ) )['messages'] );
        $this->assertSame( 'approved', $this->messages()->find( (int) $inAuto['id'] )['status'] );
        $this->assertSame( 'draft', $this->messages()->find( (int) $mine['id'] )['status'], 'your own campaigns wait for you without Autopilot' );
        $this->assertSame( 1, $this->engine()->sendDue( TimeBudget::seconds( 30.0 ) )['sent'] );
    }

    public function testTheChangelogSaysWhenTheStuckDraftsAreApprovedAutomaticallyAndWhatIsAudited(): void {
        $readme  = (string) file_get_contents( dirname( __DIR__, 3 ) . '/ppc-link-growth-engine/readme.txt' );
        $section = substr( $readme, (int) strpos( $readme, '= 1.2.1 =' ), (int) strpos( $readme, '= 1.2.0 =' ) - (int) strpos( $readme, '= 1.2.1 =' ) );
        $this->assertStringContainsString( '= 1.2.1 =', $section );
        $this->assertStringNotContainsString( 'Without Autopilot nothing is approved automatically', $section );
        $this->assertStringContainsString( 'Outreach autosend on and Require individual approval off', $section );
        $this->assertStringContainsString( 'A manual save records no separate entry', $section );
    }

    /** The footer as it was rendered under the old address (for comparing the whole body). */
    private function footerForOld( string $token ): string {
        $now = (string) $this->settings()->get( 'outreach.postal_address' );
        $this->settings()->set( 'outreach.postal_address', self::OLD );
        $footer = $this->footerFor( $token );
        $this->settings()->set( 'outreach.postal_address', $now );
        return $footer;
    }
}
