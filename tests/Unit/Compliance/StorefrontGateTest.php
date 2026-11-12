<?php
/**
 * The storefront compliance gate: automated submissions get the hard "storefront_inconsistent" blocker while
 * manual packaging / owner tasks keep working; outreach sending pauses (with a clear last_error) while drafting
 * continues; both gates fail closed when the audit cannot be constructed or cannot run.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Compliance;

use PPC\LinkGrowth\Compliance\StorefrontAudit;
use PPC\LinkGrowth\Database\Schema;
use PPC\LinkGrowth\Outreach\OutreachEngine;
use PPC\LinkGrowth\Outreach\Unsubscribe;
use PPC\LinkGrowth\Repositories\Campaigns;
use PPC\LinkGrowth\Repositories\Logs;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Submission\AdapterRegistry;
use PPC\LinkGrowth\Submission\Policy;
use PPC\LinkGrowth\Submission\SubmissionEngine;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\TestCase;
use RuntimeException;

final class StorefrontGateTest extends TestCase {

    use StorefrontFixtures;

    private const TARGET = 'https://labdirectory.test/add-listing/';

    /** @var array<int,string> URLs the fake destination received POSTs for */
    private array $posts = [];

    protected function setUp(): void {
        parent::setUp();
        $this->clearStore();
        $this->posts = [];
    }

    protected function tearDown(): void {
        $this->clearStore();
        parent::tearDown();
    }

    private function settings(): Settings {
        return Container::get( Settings::class );
    }

    private function audit(): StorefrontAudit {
        return Container::get( StorefrontAudit::class );
    }

    // ------------------------------------------------------------------ submissions

    /** @return array<string,mixed> */
    private static function listingForm(): array {
        return [
            'kind'         => 'listing_submission',
            'method'       => 'POST',
            'action'       => 'https://labdirectory.test/add-listing/submit',
            'has_captcha'  => false,
            'has_password' => false,
            'fields'       => [ [ 'name' => 'website', 'semantic' => 'website', 'type' => 'url', 'required' => true ] ],
        ];
    }

    /** @return array<string,mixed> */
    private function evaluate(): array {
        return Container::get( Policy::class )->evaluate(
            [ 'id' => 11, 'domain' => 'labdirectory.test', 'score' => 95, 'automation_allowed' => 1, 'submission_config' => [] ],
            [ 'unmapped_required' => [] ],
            self::listingForm(),
            self::TARGET
        );
    }

    public function testPolicyBlocksAutomatedSubmissionsWithTheStorefrontCode(): void {
        $this->store( self::researchOnlyStore() );
        $ok = $this->evaluate();
        $this->assertTrue( $ok['allowed'], implode( ' | ', $ok['reasons'] ) );
        $this->assertSame( [], $ok['codes'] );

        $this->store( self::liveLikeStore() );
        $this->audit()->markStale();
        $r = $this->evaluate();
        $this->assertFalse( $r['allowed'] );
        $this->assertTrue( $r['hard'], 'the storefront blocker is a hard blocker' );
        $this->assertSame( [ Policy::REASON_STOREFRONT ], $r['codes'] );
        $this->assertSame( 'storefront_inconsistent', Policy::REASON_STOREFRONT );
        $this->assertCount( 1, $r['reasons'] );
        $this->assertStringStartsWith( 'The storefront compliance check is blocking automated submissions', $r['reasons'][0] );

        // The owner's acknowledgement of these exact findings lifts the blocker.
        $this->audit()->acknowledge( 1 );
        $this->assertTrue( $this->evaluate()['allowed'] );

        // A new consumer-use menu item changes the findings and re-blocks; switching the gate off never blocks.
        $store                    = self::liveLikeStore();
        $store['menu_items'][7][] = [ 'ID' => 900, 'title' => 'Fat Burners' ];
        $this->store( $store );
        $this->audit()->markStale();
        $this->assertSame( [ Policy::REASON_STOREFRONT ], $this->evaluate()['codes'], 'changed findings re-block' );
        $this->settings()->set( 'compliance.storefront_gate', false );
        $this->assertTrue( $this->evaluate()['allowed'] );
    }

    private function prepareSubmissions(): void {
        $this->loadFixtureSites();
        $s = $this->settings();
        $s->set( 'site.email', 'info@purepeptidescanada.test' );
        $s->set( 'site.city', 'Toronto' );
        $s->set( 'site.region', 'ON' );
        $s->set( 'site.brand_name', 'Pure Peptides Canada' );
        $s->set( 'outreach.sender_name', 'Jane Doe' );
        $s->set( 'advanced.crawl_delay_seconds', 0 );
        HttpFixtures::$handler = function ( string $url, array $args ) {
            if ( 'POST' !== strtoupper( (string) ( $args['method'] ?? '' ) ) ) {
                return null;
            }
            $this->posts[] = $url;
            return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'headers' => [ 'content-type' => 'text/html; charset=utf-8' ], 'body' => '<html><body><p>Thank you, your listing has been submitted.</p></body></html>', 'cookies' => [] ];
        };
    }

    private function prospect( string $domain = 'labdirectory.test' ): int {
        [ $id ] = Container::get( Prospects::class )->upsertDiscovered(
            $domain,
            [
                'name'             => 'LabDirectory Canada',
                'status'           => 'qualified',
                'score'            => 85,
                'site_type'        => 'directory',
                'opportunity_type' => 'directory_listing',
                'submission_url'   => 'https://' . $domain . '/add-listing/',
            ]
        );
        return $id;
    }

    public function testManualPackagingAndOwnerTasksContinueWhileBlocked(): void {
        $this->prepareSubmissions();
        $this->store( self::liveLikeStore() );
        $engine = Container::get( SubmissionEngine::class );
        $subs   = Container::get( Submissions::class );

        $sid = (int) $engine->packageProspect( $this->prospect(), 1 );
        $this->assertGreaterThan( 0, $sid, 'packaging still works while the storefront check blocks' );
        $row = $subs->find( $sid );
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $row['status'], 'the package becomes an owner task instead of being queued' );
        $this->assertSame( 'manual', $row['method'] );
        $this->assertSame( AdapterRegistry::ID_MANUAL, $row['adapter'] );
        $this->assertStringContainsString( 'The storefront compliance check is blocking automated submissions', (string) $row['last_error'] );
        $this->assertNotEmpty( $row['payload'], 'the listing package is built for the owner' );

        $this->assertTrue( $engine->markSubmittedManually( $sid, 1, 'https://labdirectory.test/listing/pure-peptides-canada/', '' ), 'the owner can still record a manual submission' );
        $this->assertSame( Submissions::STATUS_SUBMITTED, $subs->find( $sid )['status'] );
        $this->assertSame( [], $this->posts, 'nothing was posted automatically' );
    }

    public function testQueuedSubmissionIsNotPostedOnceTheStorefrontBlocks(): void {
        $this->prepareSubmissions();
        $this->store( self::researchOnlyStore() );
        $engine = Container::get( SubmissionEngine::class );
        $subs   = Container::get( Submissions::class );

        $sid = (int) $engine->packageProspect( $this->prospect(), 1 );
        $row = $subs->find( $sid );
        $this->assertSame( Submissions::STATUS_QUEUED, $row['status'], (string) $row['last_error'] );

        // A product is saved with consumer-use wording before the queue runs.
        $this->store( self::liveLikeStore() );
        $this->audit()->markStale();
        $subs->update( $sid, [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
        $engine->processQueue( TimeBudget::seconds( 60 ), 5 );

        $this->assertSame( [], $this->posts, 'the queued submission is not posted' );
        $row = $subs->find( $sid );
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $row['status'] );
        $this->assertStringContainsString( 'storefront compliance check', (string) $row['last_error'] );
    }

    // ------------------------------------------------------------------ outreach

    private function prepareOutreach(): void {
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

    /** @return array{campaign:int,prospect:int,contact:int,message:int} */
    private function approvedMessage( string $domain = 'library.test' ): array {
        $db  = $GLOBALS['wpdb'];
        $now = gmdate( 'Y-m-d H:i:s' );
        $db->insert( Schema::table( 'campaigns' ), [ 'name' => 'Camp', 'approach' => 'resource_page', 'status' => Campaigns::STATUS_APPROVED, 'daily_limit' => 50, 'settings' => '[]', 'stats' => '[]', 'created_at' => $now, 'updated_at' => $now ] );
        $campaign = (int) $db->insert_id;
        $db->insert( Schema::table( 'prospects' ), [ 'domain' => $domain, 'name' => $domain, 'status' => 'qualified', 'first_seen' => $now, 'updated_at' => $now ] );
        $prospect = (int) $db->insert_id;
        $db->insert( Schema::table( 'contacts' ), [ 'prospect_id' => $prospect, 'email' => 'ed@' . $domain, 'name' => 'Ed', 'role' => 'editor', 'confidence' => 80, 'consent_basis' => 'conspicuous_publication', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] );
        $contact = (int) $db->insert_id;
        $token   = bin2hex( random_bytes( 16 ) );
        $body    = "Hello,\n\nI came across your resource list and thought our laboratory guide might fit.\n\nJane Doe\nPure Peptides Canada\n\n"
            . "Pure Peptides Canada · 222 Jarvis St, Toronto, ON M5B 2B8, Canada\nIf you'd rather not hear from us again, reply with \"unsubscribe\" or use this link: " . Unsubscribe::urlFor( $token );
        $db->insert( Schema::table( 'outreach_messages' ), [ 'campaign_id' => $campaign, 'prospect_id' => $prospect, 'contact_id' => $contact, 'approach' => 'resource_page', 'sequence' => 1, 'subject' => 'A Canadian lab reference for your list', 'body' => $body, 'status' => OutreachMessages::STATUS_APPROVED, 'variant' => 'rp-a', 'unsubscribe_token' => $token, 'created_at' => $now, 'updated_at' => $now ] );
        return [ 'campaign' => $campaign, 'prospect' => $prospect, 'contact' => $contact, 'message' => (int) $db->insert_id ];
    }

    private function engine(): OutreachEngine {
        return Container::get( OutreachEngine::class );
    }

    /** @return array<string,mixed> */
    private function message( int $id ): array {
        return (array) Container::get( OutreachMessages::class )->find( $id );
    }

    public function testOutreachSendingIsRefusedWhileBlockingAndResumesAfterAcknowledgement(): void {
        $this->prepareOutreach();
        $this->store( self::liveLikeStore() );
        $lead = $this->approvedMessage();

        $r = $this->engine()->sendDue( TimeBudget::seconds( 60 ), 10 );
        $this->assertSame( 0, $r['sent'], json_encode( $r ) );
        $this->assertSame( 1, $r['reasons'][ OutreachEngine::SKIP_STOREFRONT ] ?? 0, json_encode( $r ) );
        $this->assertSame( [], $GLOBALS['ppclg_test_mail'], 'no email left the site' );
        $m = $this->message( $lead['message'] );
        $this->assertSame( OutreachMessages::STATUS_APPROVED, $m['status'], 'the message stays approved' );
        $this->assertStringStartsWith( 'Paused: the storefront compliance check is blocking automated submissions and outreach sending', (string) $m['last_error'] );
        $this->assertFalse( $this->engine()->hasSendable(), 'the pause note is written once; the send stage is not queued on every tick' );

        $now = $this->engine()->sendNow( $lead['message'], 1 );
        $this->assertFalse( $now['ok'] );
        $this->assertStringStartsWith( 'Paused: the storefront compliance check is blocking', $now['error'] );
        $this->assertSame( [], $GLOBALS['ppclg_test_mail'] );

        // Drafting continues so the owner can review.
        $db  = $GLOBALS['wpdb'];
        $nowTs = gmdate( 'Y-m-d H:i:s' );
        $db->insert( Schema::table( 'prospects' ), [ 'domain' => 'archive.test', 'name' => 'Archive', 'status' => 'qualified', 'first_seen' => $nowTs, 'updated_at' => $nowTs ] );
        $pid = (int) $db->insert_id;
        $db->insert( Schema::table( 'contacts' ), [ 'prospect_id' => $pid, 'email' => 'editor@archive.test', 'name' => 'Ed', 'role' => 'editor', 'confidence' => 80, 'consent_basis' => 'conspicuous_publication', 'status' => 'active', 'created_at' => $nowTs, 'updated_at' => $nowTs ] );
        $draft = $this->engine()->draftForProspect( $lead['campaign'], $pid, 1 );
        $this->assertNotNull( $draft, 'drafts are still created while sending is paused' );
        $this->assertSame( OutreachMessages::STATUS_DRAFT, $this->message( (int) $draft )['status'] );

        // The owner acknowledges the findings: sending resumes.
        $this->audit()->acknowledge( 1 );
        $this->assertTrue( $this->engine()->hasSendable() );
        $r = $this->engine()->sendDue( TimeBudget::seconds( 60 ), 10 );
        $this->assertSame( 1, $r['sent'], json_encode( $r ) );
        $this->assertCount( 1, $GLOBALS['ppclg_test_mail'] );
        $m = $this->message( $lead['message'] );
        $this->assertSame( OutreachMessages::STATUS_SENT, $m['status'] );
        $this->assertSame( '', (string) $m['last_error'] );
    }

    public function testOutreachSendsNormallyWhenTheStorefrontPasses(): void {
        $this->prepareOutreach();
        $this->store( self::researchOnlyStore() );
        $lead = $this->approvedMessage();
        $r    = $this->engine()->sendDue( TimeBudget::seconds( 60 ), 10 );
        $this->assertSame( 1, $r['sent'], json_encode( $r ) );
        $this->assertSame( OutreachMessages::STATUS_SENT, $this->message( $lead['message'] )['status'] );
    }

    // ------------------------------------------------------------------ fail closed

    public function testBothGatesFailClosedWhenTheAuditCannotBeConstructed(): void {
        $this->prepareOutreach();
        $this->store( self::researchOnlyStore() );
        Container::plugin()->container()->singleton(
            StorefrontAudit::class,
            static function (): StorefrontAudit {
                throw new RuntimeException( 'Storefront audit factory exploded.' );
            }
        );

        $r = $this->evaluate();
        $this->assertFalse( $r['allowed'] );
        $this->assertTrue( $r['hard'] );
        $this->assertSame( [ Policy::REASON_STOREFRONT ], $r['codes'] );
        $this->assertStringContainsString( 'could not run', $r['reasons'][0] );

        $lead = $this->approvedMessage();
        $sent = $this->engine()->sendDue( TimeBudget::seconds( 60 ), 10 );
        $this->assertSame( 0, $sent['sent'], json_encode( $sent ) );
        $this->assertSame( [], $GLOBALS['ppclg_test_mail'] );
        $this->assertStringStartsWith( 'Paused: the storefront compliance check could not run', (string) $this->message( $lead['message'] )['last_error'] );
        $this->assertFalse( $this->engine()->sendNow( $lead['message'], 1 )['ok'] );

        $errors = array_filter(
            Container::get( Logs::class )->findBy( [ 'level' => 'error' ], 'id', 'ASC', 50 ),
            static fn( array $row ): bool => str_contains( (string) $row['message'], 'fail closed' )
        );
        $this->assertNotEmpty( $errors, 'the failure is logged' );
    }

    public function testBothGatesFailClosedWhenTheScanThrows(): void {
        $this->prepareOutreach();
        $this->store( [ 'woo' => true, 'throw' => true ] );

        $r = $this->evaluate();
        $this->assertFalse( $r['allowed'] );
        $this->assertSame( [ Policy::REASON_STOREFRONT ], $r['codes'] );
        $this->assertStringContainsString( 'could not run', $r['reasons'][0] );

        $lead = $this->approvedMessage();
        $now  = $this->engine()->sendNow( $lead['message'], 1 );
        $this->assertFalse( $now['ok'] );
        $this->assertStringContainsString( 'could not run', $now['error'] );
        $this->assertSame( [], $GLOBALS['ppclg_test_mail'] );
    }
}
