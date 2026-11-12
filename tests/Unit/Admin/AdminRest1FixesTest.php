<?php
/**
 * Regression tests for the admin REST fixes (slice admin-rest-1): opt-out permanence, URL/percent-encoding
 * preservation, side-effect-free permission callbacks, campaign action errors, kick re-scheduling, audit
 * object-type alias, provider-authority count and the draft-building time budget.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Admin;

use PPC\LinkGrowth\Admin\Rest\AutomationController;
use PPC\LinkGrowth\Admin\Rest\BacklinksController;
use PPC\LinkGrowth\Admin\Rest\LogsController;
use PPC\LinkGrowth\Admin\Rest\OutreachController;
use PPC\LinkGrowth\Automation\Scheduler;
use PPC\LinkGrowth\Outreach\OutreachEngine;
use PPC\LinkGrowth\Outreach\Unsubscribe;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\Backlinks;
use PPC\LinkGrowth\Repositories\Contacts;
use PPC\LinkGrowth\Repositories\DomainMetrics;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Suppression;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_Error;
use WP_REST_Request;

final class AdminRest1FixesTest extends TestCase {

    private mixed $previousCaps = null;

    protected function setUp(): void {
        parent::setUp();
        $this->previousCaps             = $GLOBALS['ppclg_test_caps'] ?? null;
        $GLOBALS['ppclg_test_caps']     = [ 'manage_options' => true ];
        $GLOBALS['ppclg_test_user_id']  = 1;
        $GLOBALS['ppclg_test_routes']   = [];
    }

    protected function tearDown(): void {
        if ( null === $this->previousCaps ) {
            unset( $GLOBALS['ppclg_test_caps'] );
        } else {
            $GLOBALS['ppclg_test_caps'] = $this->previousCaps;
        }
        $GLOBALS['ppclg_test_user_id'] = 0;
        $GLOBALS['ppclg_test_routes']  = [];
        parent::tearDown();
    }

    private function c(): \PPC\LinkGrowth\Container {
        return Container::plugin()->container();
    }

    /** @param array<string,mixed> $params */
    private function request( string $method, string $route, array $params = [] ): WP_REST_Request {
        $r = new WP_REST_Request( $method, $route );
        foreach ( $params as $k => $v ) {
            $r->set_param( $k, $v );
        }
        return $r;
    }

    /** @return array{prospect:int,contact:int,token:string} */
    private function contactWithSentMessage( string $email = 'editor@lab.example.org', string $approach = 'resource_page' ): array {
        $c   = $this->c();
        $now = gmdate( 'Y-m-d H:i:s' );
        $pid = $c->get( Prospects::class )->insert( [ 'domain' => 'lab.example.org', 'name' => 'Lab', 'status' => 'qualified', 'first_seen' => $now, 'updated_at' => $now ] );
        $cid = $c->get( Contacts::class )->insert( [ 'prospect_id' => $pid, 'email' => $email, 'role' => 'editor', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] );
        $tok = str_repeat( 'cd', 16 );
        $c->get( OutreachMessages::class )->insert( [ 'prospect_id' => $pid, 'contact_id' => $cid, 'approach' => $approach, 'sequence' => 1, 'subject' => 'Hello', 'body' => 'Body', 'status' => 'sent', 'unsubscribe_token' => $tok, 'created_at' => $now, 'updated_at' => $now ] );
        return [ 'prospect' => $pid, 'contact' => $cid, 'token' => $tok ];
    }

    private function setContactStatus( int $contactId, string $status ): mixed {
        return ( new OutreachController( $this->c() ) )->contactStatus( $this->request( 'POST', '/ppclg/v1/outreach/contacts/' . $contactId . '/status', [ 'id' => $contactId, 'status' => $status ] ) );
    }

    private function writeCounter(): int {
        $n = 0;
        foreach ( $GLOBALS['ppclg_test_transients'] as $key => $t ) {
            if ( str_starts_with( (string) $key, 'ppclg_rl_u1_' ) ) {
                $n += (int) $t['value'];
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // admin-rest-1-1: opt-outs are permanent
    // ------------------------------------------------------------------

    public function testUnsubscribedContactCannotBeReactivatedFromTheStatusEndpoint(): void {
        $fx  = $this->contactWithSentMessage();
        $sup = $this->c()->get( Suppression::class );
        $this->assertTrue( $this->c()->get( Unsubscribe::class )->process( $fx['token'] )['ok'] );
        $this->assertTrue( $sup->isSuppressed( 'email', 'editor@lab.example.org' ) );

        $res = $this->setContactStatus( $fx['contact'], 'active' );
        $this->assertInstanceOf( WP_Error::class, $res );
        $this->assertSame( 'ppclg_contact_opted_out', $res->get_error_code() );
        $this->assertSame( 409, $res->get_error_data()['status'] );
        $this->assertSame( 'Unsubscribed via link', $res->get_error_data()['suppression']['reason'] );
        $this->assertTrue( $sup->isSuppressed( 'email', 'editor@lab.example.org' ), 'the opt-out suppression must survive' );
        $this->assertSame( 'opted_out', $this->c()->get( Contacts::class )->find( $fx['contact'] )['status'] );

        // Laundering through another status does not help: the recipient's reason is kept.
        $this->assertIsArray( $this->setContactStatus( $fx['contact'], 'suppressed' ) );
        $this->assertInstanceOf( WP_Error::class, $this->setContactStatus( $fx['contact'], 'active' ) );
        $this->assertTrue( $sup->isSuppressed( 'email', 'editor@lab.example.org' ) );
    }

    public function testReviewerRecordedOptOutIsAlsoPermanent(): void {
        $fx = $this->contactWithSentMessage();
        $this->assertIsArray( $this->setContactStatus( $fx['contact'], 'opted_out' ) );
        $res = $this->setContactStatus( $fx['contact'], 'active' );
        $this->assertInstanceOf( WP_Error::class, $res );
        $this->assertTrue( $this->c()->get( Suppression::class )->isSuppressed( 'email', 'editor@lab.example.org' ) );
    }

    public function testReviewerSuppressedMarkerCanStillBeUndone(): void {
        $fx  = $this->contactWithSentMessage();
        $sup = $this->c()->get( Suppression::class );
        $this->assertIsArray( $this->setContactStatus( $fx['contact'], 'suppressed' ) );
        $this->assertTrue( $sup->isSuppressed( 'email', 'editor@lab.example.org' ) );

        $res = $this->setContactStatus( $fx['contact'], 'active' );
        $this->assertIsArray( $res );
        $this->assertTrue( $res['ok'] );
        $this->assertSame( 'active', $res['contact']['status'] );
        $this->assertFalse( $sup->isSuppressed( 'email', 'editor@lab.example.org' ) );
    }

    public function testReviewerMarkerDoesNotHideALaterRecipientOptOut(): void {
        $fx  = $this->contactWithSentMessage();
        $sup = $this->c()->get( Suppression::class );
        $this->assertIsArray( $this->setContactStatus( $fx['contact'], 'suppressed' ) );
        // The recipient then unsubscribes; the existing reviewer entry keeps its reason.
        $this->assertTrue( $this->c()->get( Unsubscribe::class )->process( $fx['token'] )['ok'] );
        $this->assertIsArray( $this->setContactStatus( $fx['contact'], 'bounced' ) );

        $res = $this->setContactStatus( $fx['contact'], 'active' );
        $this->assertInstanceOf( WP_Error::class, $res, 'an opted-out message proves the recipient opted out' );
        $this->assertTrue( $sup->isSuppressed( 'email', 'editor@lab.example.org' ) );
    }

    public function testContactWhoseEntryWasDeliberatelyRemovedCanBeReactivated(): void {
        $fx  = $this->contactWithSentMessage();
        $sup = $this->c()->get( Suppression::class );
        $this->c()->get( Unsubscribe::class )->process( $fx['token'] );
        $entry = $sup->findOneBy( [ 'type' => 'email', 'value' => 'editor@lab.example.org' ] );
        $this->assertNotNull( $entry );
        $logs = new LogsController( $this->c() );
        $del  = $logs->deleteSuppression( $this->request( 'DELETE', '/ppclg/v1/suppression/' . $entry['id'], [ 'id' => (int) $entry['id'] ] ) );
        $this->assertTrue( $del['ok'] );

        $res = $this->setContactStatus( $fx['contact'], 'active' );
        $this->assertIsArray( $res );
        $this->assertSame( 'active', $res['contact']['status'] );
    }

    // ------------------------------------------------------------------
    // admin-rest-1-3: manual backlink URLs keep percent-encoding
    // ------------------------------------------------------------------

    public function testManualBacklinkKeepsPercentEncodedUrl(): void {
        $ctl = new BacklinksController( $this->c() );
        $res = $ctl->create(
            $this->request(
                'POST',
                '/ppclg/v1/backlinks',
                [
                    'source_url' => '  https://blog.example.org/caf%C3%A9-ppc-tools/?id=12&path=a%2Fb ',
                    'target_url' => 'https://purepeptidescanada.test/2024/10/best%20ppc%20agencies',
                ]
            )
        );
        $this->assertIsArray( $res );
        $this->assertTrue( $res['ok'] );
        $this->assertStringContainsString( '/caf%C3%A9-ppc-tools/', (string) $res['backlink']['source_url'] );
        $this->assertStringContainsString( 'path=a%2Fb', (string) $res['backlink']['source_url'] );
        $this->assertStringContainsString( 'best%20ppc%20agencies', (string) $res['backlink']['target_url'] );
    }

    public function testManualBacklinkUrlValidation(): void {
        $ctl = new BacklinksController( $this->c() );
        // No scheme: https is assumed and literal spaces are encoded.
        $ok = $ctl->create( $this->request( 'POST', '/ppclg/v1/backlinks', [ 'source_url' => 'blog.example.org/best ppc tools' ] ) );
        $this->assertIsArray( $ok );
        $this->assertSame( 'https://blog.example.org/best%20ppc%20tools', $ok['backlink']['source_url'] );

        $missing = $ctl->create( $this->request( 'POST', '/ppclg/v1/backlinks', [] ) );
        $this->assertInstanceOf( WP_Error::class, $missing );
        $this->assertSame( 'ppclg_missing_source', $missing->get_error_code() );

        foreach ( [ "https://blog.example.org/a\tb", 'ftp://blog.example.org/file', "https://blog.example.org/a\x00b" ] as $bad ) {
            $err = $ctl->create( $this->request( 'POST', '/ppclg/v1/backlinks', [ 'source_url' => $bad ] ) );
            $this->assertInstanceOf( WP_Error::class, $err, $bad );
            $this->assertSame( 'ppclg_invalid_backlink', $err->get_error_code(), $bad );
        }
        $badTarget = $ctl->create( $this->request( 'POST', '/ppclg/v1/backlinks', [ 'source_url' => 'https://blog.example.org/x', 'target_url' => 'ftp://purepeptidescanada.test/' ] ) );
        $this->assertInstanceOf( WP_Error::class, $badTarget );
        $this->assertSame( 0, $this->c()->get( Backlinks::class )->count( [ 'source_url' => 'https://blog.example.org/x' ] ) );
    }

    // ------------------------------------------------------------------
    // admin-rest-1-4: message edits keep percent-encoded URLs
    // ------------------------------------------------------------------

    public function testMessageEditPassesBodyUnmangledToTheEngine(): void {
        $fx  = $this->contactWithSentMessage();
        $now = gmdate( 'Y-m-d H:i:s' );
        $mid = $this->c()->get( OutreachMessages::class )->insert( [ 'prospect_id' => $fx['prospect'], 'contact_id' => $fx['contact'], 'approach' => 'broken_link', 'sequence' => 1, 'subject' => 's', 'body' => 'b', 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now ] );
        $spy = new class() {
            /** @var array<int,array<string,mixed>> */
            public array $calls = [];
            /** @return array<string,mixed> */
            public function updateMessage( int $id, string $subject, string $body, int $userId ): array {
                $this->calls[] = [ 'id' => $id, 'subject' => $subject, 'body' => $body, 'user' => $userId ];
                return [ 'ok' => true, 'violations' => [] ];
            }
            /** @return array<string,string> */
            public function preview( int $id ): array {
                return [ 'subject' => '', 'body' => '', 'to' => '' ];
            }
            public function campaign( int $id ): ?array {
                return null;
            }
        };
        $this->c()->set( OutreachEngine::class, $spy );

        $body = "Hi,\r\nThe broken link https://library.example.edu/guides/Lab%20Safety%20Manual.pdf on your page\n"
            . "could point to https://x.ca/?utm_source=ppc%2Bnews&x=a%20b instead.\x00\x07 Thanks";
        $res = ( new OutreachController( $this->c() ) )->updateMessage(
            $this->request( 'POST', '/ppclg/v1/outreach/messages/' . $mid, [ 'id' => $mid, 'subject' => "Re: broken  link\n on %20 page", 'body' => $body ] )
        );
        $this->assertIsArray( $res );
        $this->assertCount( 1, $spy->calls );
        $sent = $spy->calls[0];
        $this->assertStringContainsString( 'https://library.example.edu/guides/Lab%20Safety%20Manual.pdf', $sent['body'] );
        $this->assertStringContainsString( 'utm_source=ppc%2Bnews&x=a%20b', $sent['body'] );
        $this->assertStringNotContainsString( "\x00", $sent['body'] );
        $this->assertStringNotContainsString( "\x07", $sent['body'] );
        $this->assertStringNotContainsString( "\r", $sent['body'] );
        $this->assertSame( 'Re: broken link on %20 page', $sent['subject'] );

        $empty = ( new OutreachController( $this->c() ) )->updateMessage( $this->request( 'POST', '/x', [ 'id' => $mid, 'subject' => '  ', 'body' => 'x' ] ) );
        $this->assertInstanceOf( WP_Error::class, $empty );
    }

    // ------------------------------------------------------------------
    // admin-rest-1-5: rate limit counted once per dispatched write
    // ------------------------------------------------------------------

    public function testPermissionCallbacksHaveNoSideEffectsAndWritesCountOnce(): void {
        ( new LogsController( $this->c() ) )->register( 'ppclg/v1' );
        $routes = $GLOBALS['ppclg_test_routes'];
        $this->assertArrayHasKey( 'ppclg/v1/suppression', $routes );

        // rest_send_allow_header() calls every handler's permission_callback of the matched route.
        foreach ( $routes['ppclg/v1/suppression'] as $registration ) {
            $handler = $registration[0];
            $this->assertTrue( call_user_func( $handler['permission_callback'], $this->request( 'GET', '/ppclg/v1/suppression' ) ) );
            $this->assertTrue( call_user_func( $handler['permission_callback'], $this->request( 'POST', '/ppclg/v1/suppression' ) ) );
        }
        $this->assertSame( 0, $this->writeCounter(), 'permission callbacks must not count requests' );

        $get = null;
        foreach ( $routes['ppclg/v1/suppression'] as $registration ) {
            if ( 'GET' === $registration[0]['methods'] ) {
                $get = $registration[0]['callback'];
            }
        }
        $this->assertNotNull( $get );
        $get( $this->request( 'GET', '/ppclg/v1/suppression' ) );
        $this->assertSame( 0, $this->writeCounter(), 'reads are not rate limited' );

        $post = $routes['ppclg/v1/notifications/read'][0][0]['callback'];
        $res  = $post( $this->request( 'POST', '/ppclg/v1/notifications/read' ) );
        $this->assertInstanceOf( \WP_REST_Response::class, $res );
        $this->assertSame( 1, $this->writeCounter() );

        for ( $i = 1; $i < \PPC\LinkGrowth\Admin\Rest\Controller::RATE_LIMIT_PER_MINUTE; $i++ ) {
            $post( $this->request( 'POST', '/ppclg/v1/notifications/read' ) );
        }
        $this->assertSame( \PPC\LinkGrowth\Admin\Rest\Controller::RATE_LIMIT_PER_MINUTE, $this->writeCounter() );
        $limited = $post( $this->request( 'POST', '/ppclg/v1/notifications/read' ) );
        $this->assertInstanceOf( WP_Error::class, $limited );
        $this->assertSame( 429, $limited->get_error_data()['status'] );
    }

    // ------------------------------------------------------------------
    // admin-rest-1-6: refused campaign actions are errors
    // ------------------------------------------------------------------

    public function testRefusedCampaignApprovalIsAConflictWithTheReason(): void {
        $engine = $this->c()->get( OutreachEngine::class );
        $id     = $engine->createCampaign( 'Resource pages', 'resource_page', [], 1 );
        $this->assertGreaterThan( 0, $id );
        $ctl = new OutreachController( $this->c() );

        $submitted = $ctl->campaignAction( $this->request( 'POST', '/x', [ 'id' => $id, 'action' => 'submit' ] ) );
        $this->assertIsArray( $submitted );
        $this->assertTrue( $submitted['ok'] );

        // Sender identity is incomplete on a fresh install.
        $res = $ctl->campaignAction( $this->request( 'POST', '/x', [ 'id' => $id, 'action' => 'approve' ] ) );
        $this->assertInstanceOf( WP_Error::class, $res );
        $this->assertSame( 'ppclg_campaign_action_failed', $res->get_error_code() );
        $data = $res->get_error_data();
        $this->assertSame( 409, $data['status'] );
        $this->assertFalse( $data['ok'] );
        $this->assertSame( 'pending_approval', $data['campaign']['status'] );
        $this->assertFalse( $data['sender']['ok'] );
        $this->assertStringContainsString( 'could not be approved', $res->get_error_message() );
        $this->assertStringContainsString( 'postal_address', $res->get_error_message() );

        // Pause works (pending_approval -> paused); resume without a sender is refused with the reason.
        $paused = $ctl->campaignAction( $this->request( 'POST', '/x', [ 'id' => $id, 'action' => 'pause' ] ) );
        $this->assertIsArray( $paused );
        $resume = $ctl->campaignAction( $this->request( 'POST', '/x', [ 'id' => $id, 'action' => 'resume' ] ) );
        $this->assertInstanceOf( WP_Error::class, $resume );
        $this->assertStringContainsString( 'sender identity is incomplete', $resume->get_error_message() );
        $this->assertSame( 'paused', $resume->get_error_data()['campaign']['status'] );
    }

    // ------------------------------------------------------------------
    // admin-rest-1-7: kick re-registers the recurring schedule
    // ------------------------------------------------------------------

    public function testKickReRegistersTheScheduleBeforeQueueingATick(): void {
        $spy = new class() {
            /** @var string[] */
            public array $calls = [];
            public function schedule(): void {
                $this->calls[] = 'schedule';
            }
            public function kick(): bool {
                $this->calls[] = 'kick';
                return true;
            }
            /** @return array<string,mixed> */
            public function status(): array {
                return [ 'backend' => 'wp_cron' ];
            }
        };
        $this->c()->set( Scheduler::class, $spy );
        $res = ( new AutomationController( $this->c() ) )->kick( $this->request( 'POST', '/ppclg/v1/automation/kick' ) );
        $this->assertIsArray( $res );
        $this->assertTrue( $res['ok'] );
        $this->assertSame( [ 'schedule', 'kick' ], $spy->calls );
    }

    // ------------------------------------------------------------------
    // admin-rest-1-8: audit "Message" filter
    // ------------------------------------------------------------------

    public function testAuditMessageFilterMatchesOutreachMessageRows(): void {
        $audit = $this->c()->get( AuditLog::class );
        $audit->record( 'user:1', 'outreach.approved', 'outreach_message', 5 );
        $audit->record( 'system', 'outreach.sent', 'outreach_message', 5 );
        $audit->record( 'user:1', 'backlink.deleted', 'backlink', 3 );
        $ctl = new LogsController( $this->c() );
        $this->assertSame( 2, $ctl->auditLog( $this->request( 'GET', '/ppclg/v1/audit', [ 'object_type' => 'message' ] ) )['total'] );
        $this->assertSame( 2, $ctl->auditLog( $this->request( 'GET', '/ppclg/v1/audit', [ 'object_type' => 'outreach_message' ] ) )['total'] );
        $this->assertSame( 1, $ctl->auditLog( $this->request( 'GET', '/ppclg/v1/audit', [ 'object_type' => 'backlink' ] ) )['total'] );
    }

    // ------------------------------------------------------------------
    // admin-rest-1-9: high-authority domains use provider authority
    // ------------------------------------------------------------------

    public function testHighAuthorityDomainsCountsProviderAuthorityNotLinkQuality(): void {
        $backlinks = $this->c()->get( Backlinks::class );
        $metrics   = $this->c()->get( DomainMetrics::class );
        $target    = 'https://purepeptidescanada.test/';
        $backlinks->upsert( 'https://directory.a-lab.org/listing', $target, [ 'status' => 'active', 'quality_score' => 80 ] );   // no provider data
        $backlinks->upsert( 'https://www.b-lab.org/resources', $target, [ 'status' => 'active', 'quality_score' => 80 ] );       // authority 45
        $backlinks->upsert( 'https://www.b-lab.org/other', $target, [ 'status' => 'active', 'quality_score' => 65 ] );           // same domain
        $backlinks->upsert( 'https://c-lab.org/links', $target, [ 'status' => 'active', 'quality_score' => 70 ] );               // authority 10
        $backlinks->upsert( 'https://d-lab.org/gone', $target, [ 'status' => 'lost', 'quality_score' => 90 ] );                  // lost, authority 90
        $metrics->put( 'b-lab.org', 'ahrefs', [ 'authority' => 45, 'authority_label' => 'DR' ], 30 );
        $metrics->put( 'b-lab.org', 'moz', [ 'empty' => true ], 3 );
        $metrics->put( 'c-lab.org', 'moz', [ 'authority' => 10 ], 30 );
        $metrics->put( 'd-lab.org', 'moz', [ 'authority' => 90 ], 30 );

        $s = ( new BacklinksController( $this->c() ) )->summary( $this->request( 'GET', '/ppclg/v1/backlinks/summary' ) );
        $this->assertSame( 1, $s['high_authority_domains'], 'only b-lab.org has provider authority >= 30 and an active link' );
        $this->assertSame( 30, $s['high_authority_min'] );
        $this->assertSame( 3, $s['quality_domains'], 'link-quality count (score >= 60) is still reported separately' );
        $this->assertSame( 3, $s['referring_domains'] );
    }

    // ------------------------------------------------------------------
    // admin-rest-1-10: draft building stops at a time budget
    // ------------------------------------------------------------------

    /** @return object spy OutreachEngine */
    private function draftSpy(): object {
        return new class() {
            /** @var array<int,int[]> */
            public array $chunks = [];
            /** @param int[] $ids @return array<string,mixed> */
            public function buildDrafts( int $campaignId, array $ids, int $userId, bool $useAi = false ): array {
                $this->chunks[] = array_values( $ids );
                usleep( 2000 );
                $skipped = [];
                foreach ( $ids as $pid ) {
                    if ( 0 === $pid % 2 ) {
                        $skipped[ $pid ] = 'No eligible active contact';
                    }
                }
                return [ 'created' => count( $ids ) - count( $skipped ), 'skipped' => $skipped ];
            }
            public function campaign( int $id ): ?array {
                return null;
            }
        };
    }

    public function testDraftBuildingReturnsRemainingProspectsWhenTheTimeBudgetIsUsed(): void {
        $id  = $this->c()->get( OutreachEngine::class )->createCampaign( 'AI campaign', 'resource_page', [], 1 );
        $spy = $this->draftSpy();
        $this->c()->set( OutreachEngine::class, $spy );
        add_filter( 'ppclg_rest_drafts_seconds', static fn(): float => 0.0 );

        $res = ( new OutreachController( $this->c() ) )->drafts( $this->request( 'POST', '/x', [ 'id' => $id, 'prospect_ids' => [ 11, 12, 13 ], 'use_ai' => true ] ) );
        $this->assertIsArray( $res );
        $this->assertSame( [ [ 11 ] ], $spy->chunks, 'AI drafting checks the budget after every prospect and always makes progress' );
        $this->assertSame( 1, $res['created'] );
        $this->assertSame( [ 12, 13 ], $res['remaining'] );
        $this->assertTrue( $res['partial'] );
        $this->assertArrayHasKey( 12, $res['skipped'] );
        $this->assertArrayHasKey( 13, $res['skipped'] );
        $this->assertStringContainsString( 'time limit', $res['skipped'][13] );
    }

    public function testDraftBuildingWithinBudgetProcessesEverything(): void {
        $id  = $this->c()->get( OutreachEngine::class )->createCampaign( 'Template campaign', 'resource_page', [], 1 );
        $spy = $this->draftSpy();
        $this->c()->set( OutreachEngine::class, $spy );

        $res = ( new OutreachController( $this->c() ) )->drafts( $this->request( 'POST', '/x', [ 'id' => $id, 'prospect_ids' => [ 11, 12, 13 ], 'use_ai' => false ] ) );
        $this->assertSame( [ [ 11, 12, 13 ] ], $spy->chunks );
        $this->assertSame( 2, $res['created'] );
        $this->assertSame( [], $res['remaining'] );
        $this->assertFalse( $res['partial'] );
        $this->assertSame( [ 12 => 'No eligible active contact' ], $res['skipped'] );
    }
}
