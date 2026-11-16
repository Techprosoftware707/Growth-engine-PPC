<?php
/**
 * 1.2.2 — the default email signature never repeats a line. Live drafts were signed
 * "Pure Peptides Canada\nPure Peptides Canada\nhttps://purepeptidescanada.com" because the sender name was the brand.
 * The sender name is now left out when it equals the brand (case and white space ignored), no line is repeated right
 * below itself (also in a signature typed in Settings), and first emails and follow-ups both get it.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Outreach;

use PPC\LinkGrowth\Database\Schema;
use PPC\LinkGrowth\Outreach\FollowUps;
use PPC\LinkGrowth\Outreach\OutreachEngine;
use PPC\LinkGrowth\Outreach\Personalizer;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class SignatureTest extends TestCase {

    private const LIVE_DUPLICATE = "Pure Peptides Canada\nPure Peptides Canada\nhttps://purepeptidescanada.com";

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['ppclg_test_caps']    = [ 'manage_options' => true ];
        $GLOBALS['ppclg_test_user_id'] = 1;
        $this->settings()->update(
            [
                'site'     => [ 'brand_name' => 'Pure Peptides Canada', 'home_url' => 'https://purepeptidescanada.com' ],
                'outreach' => [ 'sender_name' => 'Pure Peptides Canada', 'sender_email' => 'jane@purepeptidescanada.test', 'postal_address' => '222 Jarvis St, Toronto, ON M5B 2B8, Canada' ],
            ]
        );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['ppclg_test_caps'] );
        $GLOBALS['ppclg_test_user_id'] = 0;
        parent::tearDown();
    }

    private function settings(): Settings {
        return Container::get( Settings::class );
    }

    private function personalizer(): Personalizer {
        return Container::get( Personalizer::class );
    }

    private function site( string $domain, string $status = Prospects::STATUS_QUALIFIED ): int {
        $db  = $GLOBALS['wpdb'];
        $now = gmdate( 'Y-m-d H:i:s' );
        $db->insert( Schema::table( 'prospects' ), [ 'domain' => $domain, 'name' => $domain, 'status' => $status, 'opportunity_type' => 'resource_page', 'score' => 85, 'discovered_via' => 'search', 'first_seen' => $now, 'updated_at' => $now ] );
        $id = (int) $db->insert_id;
        $db->insert( Schema::table( 'contacts' ), [ 'prospect_id' => $id, 'email' => 'editor@' . $domain, 'name' => 'Ed', 'role' => 'editor', 'confidence' => 80, 'consent_basis' => 'conspicuous_publication', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] );
        return $id;
    }

    public function testTheSenderNameIsLeftOutWhenItIsTheBrand(): void {
        $this->assertSame( "Pure Peptides Canada\nhttps://purepeptidescanada.com", $this->personalizer()->currentSignature() );
        foreach ( [ 'pure peptides canada', '  Pure   Peptides Canada ', "PURE PEPTIDES\u{00A0}CANADA" ] as $name ) {
            $this->settings()->set( 'outreach.sender_name', $name );
            $this->assertSame( "Pure Peptides Canada\nhttps://purepeptidescanada.com", $this->personalizer()->currentSignature(), $name );
        }
        $this->assertNotSame( self::LIVE_DUPLICATE, $this->personalizer()->currentSignature() );
    }

    public function testAPersonsNameStaysAboveTheBrand(): void {
        $this->settings()->set( 'outreach.sender_name', 'Jane Doe' );
        $this->assertSame( "Jane Doe\nPure Peptides Canada\nhttps://purepeptidescanada.com", $this->personalizer()->currentSignature() );
        $this->settings()->set( 'outreach.sender_name', '' );
        $this->assertSame( "Pure Peptides Canada\nhttps://purepeptidescanada.com", $this->personalizer()->currentSignature() );
    }

    public function testATypedSignatureNeverRepeatsALineRightBelowItself(): void {
        $this->settings()->set( 'outreach.signature', "Jane Doe\nPure Peptides Canada\npure peptides canada \n\nhttps://purepeptidescanada.com" );
        $this->assertSame( "Jane Doe\nPure Peptides Canada\n\nhttps://purepeptidescanada.com", $this->personalizer()->currentSignature() );
        // Lines that are not next to each other, and blank lines, are kept as typed.
        $this->settings()->set( 'outreach.signature', "Pure Peptides Canada\nResearch team\nPure Peptides Canada" );
        $this->assertSame( "Pure Peptides Canada\nResearch team\nPure Peptides Canada", $this->personalizer()->currentSignature() );
    }

    public function testFirstEmailsAndFollowUpsAreSignedOnce(): void {
        $engine = Container::get( OutreachEngine::class );
        $camp   = $engine->createCampaign( 'Mine', 'resource_page', [], 1 );
        $this->assertTrue( $engine->approveCampaign( $camp, 1 ) );
        $id = $engine->draftForProspect( $camp, $this->site( 'sig-rp.ca' ), 1 );
        $this->assertNotNull( $id );
        $body = (string) Container::get( OutreachMessages::class )->find( (int) $id )['body'];
        $this->assertStringNotContainsString( "Pure Peptides Canada\nPure Peptides Canada", $body, 'first email' );
        $this->assertStringContainsString( "\n\nPure Peptides Canada\nhttps://purepeptidescanada.com\n\n", $body, 'signed once, above the footer' );

        $p   = $this->site( 'sig-fu.ca', Prospects::STATUS_IN_PROGRESS );
        $db  = $GLOBALS['wpdb'];
        $c   = (int) $db->get_var( "SELECT id FROM wp_ppclg_contacts WHERE prospect_id = {$p}" );
        $old = gmdate( 'Y-m-d H:i:s', time() - 9 * DAY_IN_SECONDS );
        $db->insert( Schema::table( 'outreach_messages' ), [ 'campaign_id' => $camp, 'prospect_id' => $p, 'contact_id' => $c, 'approach' => 'resource_page', 'sequence' => 1, 'subject' => 'Hello', 'body' => 'x', 'status' => 'sent', 'sent_at' => $old, 'unsubscribe_token' => bin2hex( random_bytes( 16 ) ), 'created_at' => $old, 'updated_at' => $old ] );
        Container::get( FollowUps::class )->generateDue( TimeBudget::seconds( 30.0 ) );
        $follow = Container::get( OutreachMessages::class )->findOneBy( [ 'prospect_id' => $p, 'sequence' => 2 ] );
        $this->assertNotNull( $follow );
        $this->assertStringNotContainsString( "Pure Peptides Canada\nPure Peptides Canada", (string) $follow['body'], 'follow-up' );
        $this->assertStringContainsString( "\n\nPure Peptides Canada\nhttps://purepeptidescanada.com\n\n", (string) $follow['body'] );
    }
}
