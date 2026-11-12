<?php
/**
 * Cross-slice follow-ups for the outreach group: interactive AI drafting uses a shorter HTTP timeout ([4]),
 * "Send now" takes the job-runner lock ([18]), the auto-outreach job leaves held evergreen campaigns alone and
 * does not attempt approvals the engine refuses ([30]), the pipeline only queues the send stage when something
 * is sendable ([31]), the inbound webhook accepts its secret from a query parameter or HTTP Basic auth ([32])
 * and the repository names the transient "sending" status ([33]).
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\CrossSlice;

use PPC\LinkGrowth\Admin\Rest\OutreachController;
use PPC\LinkGrowth\Admin\Rest\PublicController;
use PPC\LinkGrowth\Automation\JobRunner;
use PPC\LinkGrowth\Automation\Pipeline;
use PPC\LinkGrowth\Database\Schema;
use PPC\LinkGrowth\Integrations\ClaudeWriter;
use PPC\LinkGrowth\Outreach\OutreachEngine;
use PPC\LinkGrowth\Outreach\Unsubscribe;
use PPC\LinkGrowth\Repositories\Campaigns;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_Error;
use WP_REST_Request;

final class OutreachFollowupsTest extends TestCase {

    private const SECRET = 'whsec-cross-slice-0123456789abcdef';

    private mixed $previousCaps = null;

    protected function setUp(): void {
        parent::setUp();
        $this->previousCaps            = $GLOBALS['ppclg_test_caps'] ?? null;
        $GLOBALS['ppclg_test_caps']    = [ 'manage_options' => true ];
        $GLOBALS['ppclg_test_user_id'] = 1;
        $GLOBALS['ppclg_test_routes']  = [];
        unset( $_SERVER['PHP_AUTH_PW'], $_SERVER['PHP_AUTH_USER'], $GLOBALS['wp'] );
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
        if ( null === $this->previousCaps ) {
            unset( $GLOBALS['ppclg_test_caps'] );
        } else {
            $GLOBALS['ppclg_test_caps'] = $this->previousCaps;
        }
        $GLOBALS['ppclg_test_user_id'] = 0;
        $GLOBALS['ppclg_test_routes']  = [];
        unset( $_SERVER['PHP_AUTH_PW'], $_SERVER['PHP_AUTH_USER'], $GLOBALS['wp'] );
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    private function c(): \PPC\LinkGrowth\Container {
        return Container::plugin()->container();
    }

    private function settings(): Settings {
        return Container::get( Settings::class );
    }

    private function messages(): OutreachMessages {
        return Container::get( OutreachMessages::class );
    }

    /** @param array<string,mixed> $params */
    private function request( string $method, string $route, array $params = [] ): WP_REST_Request {
        $r = new WP_REST_Request( $method, $route );
        foreach ( $params as $k => $v ) {
            $r->set_param( $k, $v );
        }
        return $r;
    }

    /** @param array<string,mixed> $extra */
    private function prospect( string $domain, array $extra = [] ): int {
        $now = gmdate( 'Y-m-d H:i:s' );
        $GLOBALS['wpdb']->insert( Schema::table( 'prospects' ), array_merge( [ 'domain' => $domain, 'name' => $domain, 'status' => Prospects::STATUS_QUALIFIED, 'first_seen' => $now, 'updated_at' => $now ], $extra ) );
        return (int) $GLOBALS['wpdb']->insert_id;
    }

    private function contact( int $prospectId, string $email ): int {
        $now = gmdate( 'Y-m-d H:i:s' );
        $GLOBALS['wpdb']->insert( Schema::table( 'contacts' ), [ 'prospect_id' => $prospectId, 'email' => $email, 'name' => 'Ed', 'role' => 'editor', 'confidence' => 80, 'consent_basis' => 'conspicuous_publication', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] );
        return (int) $GLOBALS['wpdb']->insert_id;
    }

    private function campaign( string $status, string $name = 'Camp' ): int {
        $now = gmdate( 'Y-m-d H:i:s' );
        $GLOBALS['wpdb']->insert( Schema::table( 'campaigns' ), [ 'name' => $name, 'approach' => 'resource_page', 'status' => $status, 'daily_limit' => 50, 'settings' => '[]', 'stats' => '[]', 'created_at' => $now, 'updated_at' => $now ] );
        return (int) $GLOBALS['wpdb']->insert_id;
    }

    /** An approved first message to a fresh prospect/contact in $campaignId. */
    private function lead( int $campaignId, string $domain, string $status = OutreachMessages::STATUS_APPROVED ): int {
        $p     = $this->prospect( $domain );
        $c     = $this->contact( $p, 'ed@' . $domain );
        $token = bin2hex( random_bytes( 16 ) );
        $now   = gmdate( 'Y-m-d H:i:s' );
        $body  = "Hello,\n\nI came across your resource list and thought our laboratory guide might fit.\n\nJane Doe\nPure Peptides Canada\n\n"
            . "Pure Peptides Canada · 222 Jarvis St, Toronto, ON M5B 2B8, Canada\nIf you'd rather not hear from us again, reply with \"unsubscribe\" or use this link: " . Unsubscribe::urlFor( $token );
        $GLOBALS['wpdb']->insert(
            Schema::table( 'outreach_messages' ),
            [ 'campaign_id' => $campaignId, 'prospect_id' => $p, 'contact_id' => $c, 'approach' => 'resource_page', 'sequence' => 1, 'subject' => 'A Canadian lab reference for your list', 'body' => $body, 'status' => $status, 'variant' => 'rp-a', 'unsubscribe_token' => $token, 'created_at' => $now, 'updated_at' => $now ]
        );
        return (int) $GLOBALS['wpdb']->insert_id;
    }

    /** Enables the AI writer and answers every Anthropic call; returns nothing (requests land in HttpFixtures). */
    private function fakeAnthropic(): void {
        Container::get( Secrets::class )->set( 'anthropic_api_key', 'sk-ant-test-0123456789' );
        $this->settings()->set( 'providers.ai.enabled', true );
        HttpFixtures::$handler = static function ( string $url, array $args ): ?array {
            if ( ! str_starts_with( $url, ClaudeWriter::ENDPOINT ) ) {
                return null;
            }
            return [
                'response' => [ 'code' => 200, 'message' => 'OK' ],
                'headers'  => [ 'content-type' => 'application/json' ],
                'body'     => (string) wp_json_encode( [ 'stop_reason' => 'end_turn', 'content' => [ [ 'type' => 'text', 'text' => 'Hello there.' ] ], 'usage' => [] ] ),
                'cookies'  => [],
            ];
        };
    }

    /** @return int[] timeouts of the Anthropic requests made so far */
    private function anthropicTimeouts(): array {
        $out = [];
        foreach ( HttpFixtures::$requests as $r ) {
            if ( str_starts_with( $r['url'], ClaudeWriter::ENDPOINT ) ) {
                $out[] = (int) ( $r['args']['timeout'] ?? 0 );
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ [4] interactive AI timeout

    public function testInteractiveWriterIsACopyWithTheShorterTimeout(): void {
        $this->fakeAnthropic();
        $shared      = Container::get( ClaudeWriter::class );
        $interactive = $shared->interactive();

        $this->assertNotSame( $shared, $interactive );
        $this->assertSame( ClaudeWriter::TIMEOUT, $shared->timeout(), 'background jobs keep the long timeout' );
        $this->assertSame( ClaudeWriter::INTERACTIVE_TIMEOUT, $interactive->timeout() );
        $this->assertSame( 40, ClaudeWriter::INTERACTIVE_TIMEOUT );
        $this->assertSame( 90, ClaudeWriter::TIMEOUT );
        $this->assertSame( ClaudeWriter::TIMEOUT, $shared->withTimeout( 500 )->timeout(), 'never longer than the background timeout' );
        $this->assertSame( 5, $shared->withTimeout( 0 )->timeout() );

        $this->assertSame( 'Hello there.', $interactive->generate( 'system', 'user' ) );
        $this->assertSame( 'Hello there.', $shared->generate( 'system', 'user' ) );
        $this->assertSame( [ 40, 90 ], $this->anthropicTimeouts() );
    }

    public function testRestDraftingUsesTheInteractiveTimeoutAndJobsKeepTheLongOne(): void {
        $this->fakeAnthropic();
        $engine = Container::get( OutreachEngine::class );
        $camp   = $engine->createCampaign( 'AI drafts', 'resource_page', [], 1 );
        $first  = $this->prospect( 'ai-first.test' );
        $this->contact( $first, 'editor@ai-first.test' );
        $second = $this->prospect( 'ai-second.test' );
        $this->contact( $second, 'editor@ai-second.test' );

        $this->assertSame( 1, $engine->buildDrafts( $camp, [ $first ], 1, true, true )['created'] );
        $this->assertSame( 1, $engine->buildDrafts( $camp, [ $second ], 1, true )['created'] );
        $this->assertSame( [ ClaudeWriter::INTERACTIVE_TIMEOUT, ClaudeWriter::TIMEOUT ], $this->anthropicTimeouts() );
        $this->assertSame( ClaudeWriter::TIMEOUT, Container::get( ClaudeWriter::class )->timeout(), 'the shared writer is unchanged' );
    }

    public function testDraftsEndpointAsksTheEngineForInteractiveDrafting(): void {
        $id  = Container::get( OutreachEngine::class )->createCampaign( 'Spy campaign', 'resource_page', [], 1 );
        $spy = new class() {
            /** @var array<int,array<int,mixed>> */
            public array $calls = [];
            /** @param int[] $ids @return array<string,mixed> */
            public function buildDrafts( int $campaignId, array $ids, int $userId, bool $useAi = false, bool $interactive = false ): array {
                $this->calls[] = [ $ids, $useAi, $interactive ];
                return [ 'created' => count( $ids ), 'skipped' => [] ];
            }
            public function campaign( int $id ): ?array {
                return null;
            }
        };
        $this->c()->set( OutreachEngine::class, $spy );

        $res = ( new OutreachController( $this->c() ) )->drafts( $this->request( 'POST', '/x', [ 'id' => $id, 'prospect_ids' => [ 7 ], 'use_ai' => true ] ) );
        $this->assertIsArray( $res );
        $this->assertSame( [ [ [ 7 ], true, true ] ], $spy->calls );
    }

    // ------------------------------------------------------------------ [18] Send now takes the runner lock

    public function testSendNowIsRefusedWhileTheRunnerHoldsTheLock(): void {
        $id     = $this->lead( $this->campaign( Campaigns::STATUS_APPROVED ), 'locked.test' );
        $runner = Container::get( JobRunner::class );
        $ctl    = new OutreachController( $this->c() );
        $send   = fn() => $ctl->messageAction( $this->request( 'POST', '/x', [ 'id' => $id, 'action' => 'send' ] ) );

        $this->assertTrue( $runner->lock( 60 ), 'a tick / runNow is in progress' );
        $busy = $send();
        $this->assertInstanceOf( WP_Error::class, $busy );
        $this->assertSame( 'ppclg_runner_busy', $busy->get_error_code() );
        $this->assertSame( 409, $busy->get_error_data()['status'] );
        $this->assertStringContainsString( 'Try again in a minute', $busy->get_error_message() );
        $this->assertSame( OutreachMessages::STATUS_APPROVED, (string) $this->messages()->find( $id )['status'] );
        $this->assertCount( 0, $GLOBALS['ppclg_test_mail'] );

        $runner->unlock();
        $ok = $send();
        $this->assertIsArray( $ok );
        $this->assertTrue( $ok['ok'], json_encode( $ok ) );
        $this->assertSame( OutreachMessages::STATUS_SENT, (string) $this->messages()->find( $id )['status'] );
        $this->assertCount( 1, $GLOBALS['ppclg_test_mail'] );
        $this->assertFalse( $runner->isLocked(), 'Send now releases the lock it took' );
    }

    public function testSendNowReleasesTheLockWhenTheSendIsRefused(): void {
        $id     = $this->lead( $this->campaign( Campaigns::STATUS_APPROVED ), 'draft-only.test', OutreachMessages::STATUS_DRAFT );
        $runner = Container::get( JobRunner::class );
        $res    = ( new OutreachController( $this->c() ) )->messageAction( $this->request( 'POST', '/x', [ 'id' => $id, 'action' => 'send' ] ) );

        $this->assertIsArray( $res );
        $this->assertFalse( $res['ok'] );
        $this->assertStringContainsString( 'Approve the message', $res['error'] );
        $this->assertFalse( $runner->isLocked() );
    }

    // ------------------------------------------------------------------ [30] held evergreen campaigns, approval gate

    public function testPausedEvergreenCampaignGetsNoNewDraftsAndIsNotReapproved(): void {
        $this->settings()->set( 'automation.outreach_autosend', true );
        $this->settings()->set( 'outreach.require_approval', false );
        $camp = $this->campaign( Campaigns::STATUS_PAUSED, 'Auto: ' . \PPC\LinkGrowth\Outreach\Templates::label( 'resource_page' ) );
        $p    = $this->prospect( 'held-resources.test', [ 'opportunity_type' => 'resource_page', 'score' => 90 ] );
        $this->contact( $p, 'editor@held-resources.test' );

        $r = Container::get( JobRunner::class )->runType( 'auto_outreach', [], TimeBudget::seconds( 60.0 ) );
        $this->assertSame( 0, $r['drafted'], json_encode( $r ) );
        $this->assertSame( 0, $r['skipped'] );
        $this->assertSame( 0, $this->messages()->count( [ 'prospect_id' => $p ] ), 'no drafts pile up in a paused campaign' );
        $this->assertSame( Campaigns::STATUS_PAUSED, (string) Container::get( Campaigns::class )->find( $camp )['status'] );
        $this->assertStringContainsString( 'is paused', implode( ' ', $r['warnings'] ) );
        $this->assertSame( 1, count( array_filter( $r['warnings'], static fn( string $w ): bool => str_contains( $w, 'is paused' ) ) ), 'one warning per campaign, not per prospect' );
        $skippedLogs = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'logs' ) . ' WHERE message LIKE %s', 'Draft skipped%' ) );
        $this->assertSame( 0, $skippedLogs );
    }

    public function testArchivedEvergreenCampaignIsLeftAlone(): void {
        $this->campaign( Campaigns::STATUS_ARCHIVED, 'Auto: ' . \PPC\LinkGrowth\Outreach\Templates::label( 'resource_page' ) );
        $p = $this->prospect( 'archived-resources.test', [ 'opportunity_type' => 'resource_page', 'score' => 90 ] );
        $this->contact( $p, 'editor@archived-resources.test' );

        $r = Container::get( JobRunner::class )->runType( 'auto_outreach', [], TimeBudget::seconds( 60.0 ) );
        $this->assertSame( 0, $r['drafted'] );
        $this->assertSame( 0, $this->messages()->count( [ 'prospect_id' => $p ] ) );
        $this->assertSame( 1, Container::get( Campaigns::class )->count( [ 'approach' => 'resource_page' ] ), 'no second evergreen campaign is created' );
        $skippedLogs = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'logs' ) . ' WHERE message LIKE %s', 'Draft skipped%' ) );
        $this->assertSame( 0, $skippedLogs );
    }

    public function testAutosendWithIndividualApprovalRequiredApprovesTheCampaignButLeavesDraftsForReview(): void {
        $this->settings()->set( 'automation.outreach_autosend', true ); // outreach.require_approval stays at its default (on)
        $p = $this->prospect( 'review-resources.test', [ 'opportunity_type' => 'resource_page', 'score' => 90 ] );
        $this->contact( $p, 'editor@review-resources.test' );

        $r = Container::get( JobRunner::class )->runType( 'auto_outreach', [], TimeBudget::seconds( 60.0 ) );
        $this->assertSame( 1, $r['drafted'], json_encode( $r ) );
        $this->assertSame( 0, $r['auto_approved'] );
        $camp = (int) $r['campaigns']['resource_page'];
        $this->assertSame( Campaigns::STATUS_APPROVED, (string) Container::get( Campaigns::class )->find( $camp )['status'] );
        $msg = $this->messages()->findOneBy( [ 'prospect_id' => $p ] );
        $this->assertSame( OutreachMessages::STATUS_DRAFT, (string) $msg['status'] );
        $this->assertStringNotContainsString( 'Not approved', (string) ( $msg['last_error'] ?? '' ) );
        $settings = Container::get( Campaigns::class )->find( $camp )['settings'];
        $settings = is_array( $settings ) ? $settings : (array) json_decode( (string) $settings, true );
        $this->assertStringContainsString( 'Require individual approval', (string) ( $settings['notes'] ?? '' ), 'the campaign note describes when drafts are approved automatically' );
    }

    public function testAutosendWithApprovalOffStillApprovesAutoDrafts(): void {
        $this->settings()->set( 'automation.outreach_autosend', true );
        $this->settings()->set( 'outreach.require_approval', false );
        $p = $this->prospect( 'auto-resources.test', [ 'opportunity_type' => 'resource_page', 'score' => 90 ] );
        $this->contact( $p, 'editor@auto-resources.test' );

        $r = Container::get( JobRunner::class )->runType( 'auto_outreach', [], TimeBudget::seconds( 60.0 ) );
        $this->assertSame( 1, $r['auto_approved'], json_encode( $r ) );
        $this->assertSame( OutreachMessages::STATUS_APPROVED, (string) $this->messages()->findOneBy( [ 'prospect_id' => $p ] )['status'] );
    }

    public function testPausedApproachWithManyHigherScoredProspectsDoesNotStarveOtherApproaches(): void {
        $this->campaign( Campaigns::STATUS_PAUSED, 'Auto: ' . \PPC\LinkGrowth\Outreach\Templates::label( 'resource_page' ) );
        for ( $i = 0; $i < 205; $i++ ) {
            $p = $this->prospect( "held{$i}.test", [ 'opportunity_type' => 'resource_page', 'score' => 95 ] );
            $this->contact( $p, "editor@held{$i}.test" );
        }
        $editorial = $this->prospect( 'editorial-site.test', [ 'opportunity_type' => 'editorial', 'score' => 80 ] );
        $this->contact( $editorial, 'editor@editorial-site.test' );

        $r = Container::get( JobRunner::class )->runType( 'auto_outreach', [], TimeBudget::seconds( 120.0 ) );
        $this->assertSame( 1, $r['drafted'], json_encode( $r ) );
        $this->assertSame( 1, $this->messages()->count( [ 'prospect_id' => $editorial ] ), 'the editorial prospect is drafted although 205 higher-scored prospects belong to a paused approach' );
        $this->assertSame( 1, $this->messages()->count(), 'nothing is drafted for the paused approach' );
        $this->assertSame( 1, count( array_filter( $r['warnings'], static fn( string $w ): bool => str_contains( $w, 'is paused' ) ) ), 'the held campaign is reported once' );
    }

    // ------------------------------------------------------------------ [31] outreach send stage due-check

    private function outreachDue(): bool {
        $m = new \ReflectionMethod( Pipeline::class, 'outreachDue' );
        return (bool) $m->invoke( Container::get( Pipeline::class ) );
    }

    public function testSendStageIsNotDueForMessagesHeldBackByTheirCampaign(): void {
        $camp = $this->campaign( Campaigns::STATUS_PAUSED );
        $this->lead( $camp, 'paused-camp.test' );
        $this->assertNotSame( [], $this->messages()->readyToSend( 1 ), 'the old check saw this row' );
        $this->assertFalse( $this->outreachDue(), 'a paused campaign cannot send, so no send job is queued' );

        Container::get( Campaigns::class )->setStatus( $camp, Campaigns::STATUS_APPROVED );
        Container::reset();
        $this->assertTrue( $this->outreachDue() );
    }

    public function testScheduledFollowUpsAreOnlyDueWithAutosendOn(): void {
        $this->lead( $this->campaign( Campaigns::STATUS_APPROVED ), 'scheduled.test', OutreachMessages::STATUS_SCHEDULED );
        $this->assertFalse( $this->outreachDue() );
        $this->settings()->set( 'automation.outreach_autosend', true );
        Container::reset();
        $this->assertTrue( $this->outreachDue() );
    }

    public function testStaleSendingClaimMakesTheSendStageDueEvenWhenNothingIsSendable(): void {
        $camp = $this->campaign( Campaigns::STATUS_PAUSED );
        $this->lead( $camp, 'held-approved.test' ); // held back by its paused campaign
        $claim = $this->lead( $camp, 'crashed-send.test', OutreachMessages::STATUS_SENDING );
        $this->assertFalse( $this->engine()->hasSendable() );
        $this->assertFalse( $this->outreachDue(), 'a claim taken just now belongs to a run that may still be sending' );

        $GLOBALS['wpdb']->update( Schema::table( 'outreach_messages' ), [ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 1200 ) ], [ 'id' => $claim ] );
        $this->assertTrue( $this->outreachDue(), 'a stale claim is released by the send stage, so the stage is due' );

        Container::get( JobRunner::class )->runType( 'outreach_send', [], TimeBudget::seconds( 60.0 ) );
        $this->assertSame( OutreachMessages::STATUS_FAILED, (string) $this->messages()->find( $claim )['status'] );
        $this->assertFalse( $this->outreachDue(), 'once released, nothing makes the stage due' );
    }

    private function engine(): OutreachEngine {
        return Container::get( OutreachEngine::class );
    }

    // ------------------------------------------------------------------ [32] inbound secret forms

    /** @param array<string,string> $headers @param array<string,mixed> $query */
    private function inbound( array $headers = [], array $query = [], array $json = [] ): mixed {
        Container::get( Secrets::class )->set( 'inbound_webhook_secret', self::SECRET );
        $r = new WP_REST_Request( 'POST', '/ppclg/v1/inbound' );
        foreach ( $headers as $k => $v ) {
            $r->set_header( $k, $v );
        }
        foreach ( $query as $k => $v ) {
            $r->set_param( $k, $v );
        }
        $r->set_body( (string) wp_json_encode( array_merge( [ 'from' => 'someone@nowhere.test', 'subject' => 'Hi', 'text' => 'Hello' ], $json ) ) );
        return ( new PublicController( $this->c() ) )->inbound( $r );
    }

    public function testInboundAcceptsTheSecretAsAQueryParameter(): void {
        $res = $this->inbound( [], [ 'secret' => self::SECRET ] );
        $this->assertSame( [ 'ok' => true, 'matched' => 0 ], $res );

        $bad = $this->inbound( [], [ 'secret' => 'wrong' ] );
        $this->assertInstanceOf( WP_Error::class, $bad );
        $this->assertSame( 403, $bad->get_error_data()['status'] );
    }

    public function testInboundAcceptsTheSecretAsTheBasicAuthPassword(): void {
        $res = $this->inbound( [ 'Authorization' => 'Basic ' . base64_encode( 'ppclg:' . self::SECRET ) ] );
        $this->assertSame( [ 'ok' => true, 'matched' => 0 ], $res );

        $bad = $this->inbound( [ 'Authorization' => 'Basic ' . base64_encode( 'ppclg:not-it' ) ] );
        $this->assertInstanceOf( WP_Error::class, $bad );
        $this->assertSame( 403, $bad->get_error_data()['status'] );

        // Servers that parse Basic auth themselves (mod_php) only expose PHP_AUTH_PW.
        $_SERVER['PHP_AUTH_USER'] = 'ppclg';
        $_SERVER['PHP_AUTH_PW']   = self::SECRET;
        $this->assertSame( [ 'ok' => true, 'matched' => 0 ], $this->inbound() );
    }

    public function testInboundHeaderAndBodySecretStillWorkAndArePreferred(): void {
        $this->assertSame( [ 'ok' => true, 'matched' => 0 ], $this->inbound( [ 'X-PPCLG-Secret' => self::SECRET ] ) );
        $this->assertSame( [ 'ok' => true, 'matched' => 0 ], $this->inbound( [], [], [ 'secret' => self::SECRET ] ) );

        // The header wins over a query parameter or Basic auth password, so a wrong header is not rescued by them.
        $headerWins = $this->inbound( [ 'X-PPCLG-Secret' => 'wrong', 'Authorization' => 'Basic ' . base64_encode( 'ppclg:' . self::SECRET ) ], [ 'secret' => self::SECRET ] );
        $this->assertInstanceOf( WP_Error::class, $headerWins );
        $this->assertSame( 403, $headerWins->get_error_data()['status'] );

        $missing = $this->inbound();
        $this->assertInstanceOf( WP_Error::class, $missing );
        $this->assertSame( 401, $missing->get_error_data()['status'] );
    }

    public function testApplicationPasswordLoginIsNotAttemptedOnTheInboundRouteOnly(): void {
        ( new PublicController( $this->c() ) )->register( 'ppclg/v1' );

        $GLOBALS['wp'] = (object) [ 'query_vars' => [ 'rest_route' => '/ppclg/v1/inbound' ] ];
        $this->assertFalse( apply_filters( 'application_password_is_api_request', true ) );
        $GLOBALS['wp'] = (object) [ 'query_vars' => [ 'rest_route' => '/ppclg/v1/inbound/' ] ];
        $this->assertFalse( apply_filters( 'application_password_is_api_request', true ) );

        $GLOBALS['wp'] = (object) [ 'query_vars' => [ 'rest_route' => '/ppclg/v1/outreach/messages' ] ];
        $this->assertTrue( apply_filters( 'application_password_is_api_request', true ) );
        $GLOBALS['wp'] = (object) [ 'query_vars' => [ 'rest_route' => '/wp/v2/users/me' ] ];
        $this->assertTrue( apply_filters( 'application_password_is_api_request', true ) );
        unset( $GLOBALS['wp'] );
        $this->assertTrue( apply_filters( 'application_password_is_api_request', true ), 'outside a REST request nothing changes' );
    }

    // ------------------------------------------------------------------ [33] "sending" status constant

    public function testSendingStatusIsNamedByTheRepository(): void {
        $this->assertSame( 'sending', OutreachMessages::STATUS_SENDING );
        $this->assertSame( OutreachMessages::STATUS_SENDING, OutreachEngine::STATUS_SENDING );

        $id = $this->lead( $this->campaign( Campaigns::STATUS_APPROVED ), 'claimed.test' );
        $this->messages()->setStatus( $id, OutreachMessages::STATUS_SENDING );
        $page = ( new OutreachController( $this->c() ) )->messages( $this->request( 'GET', '/x', [ 'status' => 'sending' ] ) );
        $this->assertSame( [ $id ], array_map( static fn( array $m ): int => (int) $m['id'], $page['items'] ) );
    }
}
