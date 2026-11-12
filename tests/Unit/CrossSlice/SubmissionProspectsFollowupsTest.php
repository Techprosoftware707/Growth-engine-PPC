<?php
/**
 * Cross-slice follow-ups for submissions and prospects: the per-site "never automate" setting
 * (automation_allowed = -1) in Policy, the form adapter, the engine and the REST API; explicit
 * comment/forum refusals; charset-safe checkbox/submit values; honest broken-link copy; in-flight
 * guards on payload edits and actions; the repository compare-and-set queries; and pending outreach
 * taken out of the queue when a prospect is rejected or suppressed.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\CrossSlice;

use PPC\LinkGrowth\Admin\Rest\ProspectsController;
use PPC\LinkGrowth\Admin\Rest\SubmissionsController;
use PPC\LinkGrowth\Analysis\FormDetector;
use PPC\LinkGrowth\Repositories\Contacts;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Submission\AdapterRegistry;
use PPC\LinkGrowth\Submission\Adapters\AdapterInterface;
use PPC\LinkGrowth\Submission\PackageBuilder;
use PPC\LinkGrowth\Submission\Policy;
use PPC\LinkGrowth\Submission\SubmissionEngine;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_Error;
use WP_REST_Request;

final class SubmissionProspectsFollowupsTest extends TestCase {

    private const PAGE_URL = 'https://labdirectory.test/add-listing/';
    private const TARGET   = 'https://labdirectory.test/add-listing/';

    /** @var array<int,array{url:string,body:mixed}> */
    private array $posts = [];

    protected function setUp(): void {
        parent::setUp();
        $this->loadFixtureSites();
        $s = Container::get( Settings::class );
        $s->set( 'site.email', 'info@purepeptidescanada.test' );
        $s->set( 'site.city', 'Toronto' );
        $s->set( 'site.region', 'ON' );
        $s->set( 'site.brand_name', 'Pure Peptides Canada' );
        $s->set( 'outreach.sender_name', 'Jane Doe' );
        $s->set( 'advanced.crawl_delay_seconds', 0 );
        $this->posts           = [];
        HttpFixtures::$handler = function ( string $url, array $args ) {
            if ( 'POST' !== strtoupper( (string) ( $args['method'] ?? '' ) ) ) {
                return null;
            }
            $this->posts[] = [ 'url' => $url, 'body' => $args['body'] ?? null ];
            return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'headers' => [ 'content-type' => 'text/html; charset=utf-8' ], 'body' => '<html><body><p>Thank you, your listing has been submitted.</p></body></html>', 'cookies' => [] ];
        };
    }

    // ------------------------------------------------------------------ helpers

    private function prospect( string $domain = 'labdirectory.test', int $score = 85, int $automation = 0 ): int {
        [ $id ] = Container::get( Prospects::class )->upsertDiscovered(
            $domain,
            [
                'name'               => 'LabDirectory Canada',
                'status'             => 'qualified',
                'score'              => $score,
                'site_type'          => 'directory',
                'opportunity_type'   => 'directory_listing',
                'submission_url'     => 'https://' . $domain . '/add-listing/',
                'automation_allowed' => $automation,
            ]
        );
        return $id;
    }

    private function engine(): SubmissionEngine {
        return Container::get( SubmissionEngine::class );
    }

    private function subs(): Submissions {
        return Container::get( Submissions::class );
    }

    /** @return array<string,mixed> */
    private function listingForm( array $overrides = [] ): array {
        return array_merge(
            [
                'kind'         => FormDetector::KIND_LISTING,
                'method'       => 'POST',
                'action'       => 'https://labdirectory.test/add-listing/submit',
                'page_url'     => self::PAGE_URL,
                'has_captcha'  => false,
                'has_password' => false,
                'fields'       => [ [ 'name' => 'website', 'semantic' => 'website', 'type' => 'url', 'required' => true ] ],
            ],
            $overrides
        );
    }

    /** @return array<string,mixed> */
    private function prospectRow( ?int $score, mixed $automation, array $extra = [] ): array {
        return array_merge( [ 'id' => 11, 'domain' => 'labdirectory.test', 'score' => $score, 'automation_allowed' => $automation, 'submission_config' => [] ], $extra );
    }

    /** Request stub with has_param(), which the shared WP_REST_Request test double does not provide. */
    private function request( string $method, string $route, array $params ): WP_REST_Request {
        $req = new class( $method, $route ) extends WP_REST_Request {
            public function has_param( string $key ): bool {
                return array_key_exists( $key, $this->get_params() );
            }
        };
        foreach ( $params as $k => $v ) {
            $req->set_param( $k, $v );
        }
        return $req;
    }

    private function prospectsController(): ProspectsController {
        return new ProspectsController( Container::plugin()->container() );
    }

    private function submissionsController(): SubmissionsController {
        return new SubmissionsController( Container::plugin()->container() );
    }

    /** A queued submission that a queue run is sending right now (unexpired claim). */
    private function inFlightSubmission( int $pid ): int {
        $payload = [
            'content'           => [ 'description' => 'Canadian supplier of research peptides and laboratory reagents.' ],
            'fields'            => [ [ 'name' => 'description', 'semantic' => 'description', 'type' => 'textarea', 'required' => true, 'value' => 'Canadian supplier of research peptides and laboratory reagents.' ] ],
            'unmapped_required' => [],
            'form'              => $this->listingForm(),
            'compliance'        => [ 'clean' => true, 'violations' => [] ],
        ];
        return $this->subs()->create(
            $pid,
            null,
            self::TARGET,
            [
                'status'       => Submissions::STATUS_QUEUED,
                'adapter'      => 'generic_form',
                'method'       => 'form',
                'payload'      => $payload,
                'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + SubmissionEngine::CLAIM_LEASE ),
                'response'     => [ SubmissionEngine::CLAIM_KEY => [ 'since' => gmdate( 'c' ), 'until' => time() + SubmissionEngine::CLAIM_LEASE ] ],
            ]
        );
    }

    // ------------------------------------------------------------------ [51] per-site tri-state

    public function testAutomationSettingNormalisation(): void {
        foreach ( [ -1, '-1', -5, '-3' ] as $v ) {
            $this->assertSame( Prospects::AUTOMATION_NEVER, Prospects::automationSetting( [ 'automation_allowed' => $v ] ), var_export( $v, true ) );
        }
        foreach ( [ 1, '1', true ] as $v ) {
            $this->assertSame( Prospects::AUTOMATION_ALWAYS, Prospects::automationSetting( [ 'automation_allowed' => $v ] ), var_export( $v, true ) );
        }
        foreach ( [ 0, '0', null, 2, '7', 'abc', false ] as $v ) {
            $this->assertSame( Prospects::AUTOMATION_INHERIT, Prospects::automationSetting( [ 'automation_allowed' => $v ] ), var_export( $v, true ) );
        }
        $this->assertSame( Prospects::AUTOMATION_INHERIT, Prospects::automationSetting( [] ) );
    }

    public function testNeverAutomateBlocksEveryModeIncludingAutoAndPartnerApi(): void {
        $settings = Container::get( Settings::class );
        $policy   = Container::get( Policy::class );
        $payload  = [ 'unmapped_required' => [] ];
        $api      = [ 'submission_config' => [ 'api' => [ 'endpoint' => 'https://api.labdirectory.test/listings' ] ] ];

        foreach ( Policy::SUBMISSION_MODES as $mode ) {
            $settings->set( 'automation.submission_mode', $mode );
            $never = $policy->evaluate( $this->prospectRow( 95, -1 ), $payload, $this->listingForm(), self::TARGET );
            $this->assertFalse( $never['allowed'], $mode );
            $this->assertTrue( $never['hard'], "$mode: never automate is a hard blocker (a person submits it)" );
            $this->assertStringContainsString( 'Never automate', implode( ' ', $never['reasons'] ), $mode );

            $withApi = $policy->evaluate( $this->prospectRow( 95, -1, $api ), $payload, null, self::TARGET );
            $this->assertFalse( $withApi['allowed'], "$mode: a partner API does not override never automate" );

            $always = $policy->evaluate( $this->prospectRow( 40, 1 ), $payload, $this->listingForm(), self::TARGET );
            $this->assertTrue( $always['allowed'], "$mode: 1 still means always allowed: " . implode( ' | ', $always['reasons'] ) );
        }

        $settings->set( 'automation.submission_mode', 'auto' );
        $inherit = $policy->evaluate( $this->prospectRow( 95, 0 ), $payload, $this->listingForm(), self::TARGET );
        $this->assertTrue( $inherit['allowed'], 'inherit follows the mode: a high score qualifies in auto mode' );
    }

    public function testFormAdapterRefusesANeverAutomateSiteEvenWhenCalledDirectly(): void {
        $adapter = Container::get( AdapterRegistry::class )->get( 'generic_form' );
        $this->assertNotNull( $adapter );
        $payload = [ 'form' => $this->listingForm(), 'fields' => [ [ 'name' => 'website', 'semantic' => 'website', 'type' => 'url', 'value' => 'https://purepeptides.test/' ] ] ];
        $before  = count( HttpFixtures::$requests );

        $result = $adapter->submit( [ 'id' => 1, 'target_url' => self::TARGET ], $this->prospectRow( 99, -1 ), $payload );

        $this->assertSame( AdapterInterface::STATUS_NEEDS_MANUAL, $result['status'] );
        $this->assertFalse( $result['sent'] );
        $this->assertStringContainsString( 'Never automate', (string) $result['error'] );
        $this->assertSame( $before, count( HttpFixtures::$requests ), 'nothing is fetched or posted' );
        $this->assertSame( [], $this->posts );
    }

    public function testNeverAutomateSiteIsPackagedForAPersonAndAQueuedRowIsNotSent(): void {
        $never = $this->engine()->packageProspect( $this->prospect( 'labdirectory.test', 95, -1 ), 1 );
        $row   = $this->subs()->find( (int) $never );
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $row['status'] );
        $this->assertStringContainsString( 'Never automate', (string) $row['last_error'] );
        $this->assertFalse( $this->engine()->approve( (int) $never, 1 ), 'approval cannot queue it for automation' );
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $this->subs()->find( (int) $never )['status'] );

        // A row queued while the site inherited the mode is not sent once the site is set to never.
        HttpFixtures::add( 'https://otherdirectory.test/add-listing/', $this->fixture( 'sites/labdirectory.test/add-listing/index.html' ) );
        HttpFixtures::add( 'https://otherdirectory.test/robots.txt', "User-agent: *\nAllow: /\n", 200, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://otherdirectory.test/', '<html><body><a href="/add-listing/">Add</a></body></html>' );
        $pid = $this->prospect( 'otherdirectory.test', 95, 0 );
        $sid = (int) $this->engine()->packageProspect( $pid, 1 );
        $this->assertSame( Submissions::STATUS_QUEUED, $this->subs()->find( $sid )['status'] );
        Container::get( Prospects::class )->update( $pid, [ 'automation_allowed' => Prospects::AUTOMATION_NEVER ] );
        $this->subs()->update( $sid, [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );

        $this->engine()->processQueue( TimeBudget::seconds( 60 ), 5 );

        $this->assertSame( [], $this->posts );
        $after = $this->subs()->find( $sid );
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $after['status'] );
        $this->assertStringContainsString( 'Never automate', (string) $after['last_error'] );
    }

    public function testAutomationEndpointStoresTheTriState(): void {
        $pid  = $this->prospect();
        $repo = Container::get( Prospects::class );
        $set  = fn( mixed $v ): mixed => $this->prospectsController()->automation( $this->request( 'POST', "/ppclg/v1/prospects/$pid/automation", [ 'id' => $pid, 'automation_allowed' => $v ] ) );

        foreach ( [ [ -1, -1 ], [ '1', 1 ], [ 0, 0 ], [ 'never', -1 ], [ true, 1 ], [ false, 0 ], [ '-1', -1 ] ] as [ $in, $stored ] ) {
            $res = $set( $in );
            $this->assertIsArray( $res, var_export( $in, true ) );
            $this->assertSame( $stored, (int) $repo->find( $pid )['automation_allowed'], var_export( $in, true ) );
            $this->assertSame( $stored, $res['prospect']['automation_allowed'], 'the API returns the stored setting' );
        }

        foreach ( [ 2, '5', 'maybe', [ 1 ] ] as $bad ) {
            $res = $set( $bad );
            $this->assertInstanceOf( WP_Error::class, $res, var_export( $bad, true ) );
            $this->assertSame( 'ppclg_invalid_automation_setting', $res->get_error_code() );
            $this->assertSame( 400, $res->get_error_data()['status'] );
            $this->assertSame( -1, (int) $repo->find( $pid )['automation_allowed'], 'a refused value changes nothing' );
        }

        // The drawer's partner-API calls leave automation_allowed out, which keeps "never automate".
        $res = $this->prospectsController()->automation( $this->request( 'POST', "/ppclg/v1/prospects/$pid/automation", [ 'id' => $pid, 'submission_config' => null ] ) );
        $this->assertIsArray( $res );
        $this->assertSame( -1, (int) $repo->find( $pid )['automation_allowed'] );
    }

    public function testProspectListFiltersOnTheTriState(): void {
        $never   = $this->prospect( 'never.test', 80, -1 );
        $always  = $this->prospect( 'always.test', 80, 1 );
        $inherit = $this->prospect( 'inherit.test', 80, 0 );
        $ids     = function ( mixed $filter ): array {
            $page = $this->prospectsController()->index( $this->request( 'GET', '/ppclg/v1/prospects', [ 'automation_allowed' => $filter, 'per_page' => 50 ] ) );
            $out  = array_map( static fn( array $p ): int => (int) $p['id'], $page['items'] );
            sort( $out );
            return $out;
        };
        $this->assertSame( [ $never ], $ids( '-1' ) );
        $this->assertSame( [ $always ], $ids( 1 ) );
        $this->assertSame( [ $inherit ], $ids( '0' ) );
        $this->assertSame( [ $always ], $ids( 'true' ), 'boolean filters keep working' );
    }

    // ------------------------------------------------------------------ [35] comment and forum forms

    public function testCommentAndForumFormsAreRefusedWhateverTheirKind(): void {
        $policy = Container::get( Policy::class );

        $comment = $policy->formBlockers( $this->listingForm( [ 'kind' => FormDetector::KIND_COMMENT ] ) );
        $this->assertContains( 'The detected form is a blog comment / forum reply form (comment and forum posting is never automated).', $comment );

        $cases = [
            'wp-comments-post action' => $this->listingForm( [ 'action' => 'https://labdirectory.test/wp-comments-post.php' ] ),
            'comments path'           => $this->listingForm( [ 'action' => 'https://labdirectory.test/blog/post-1/comments/new' ] ),
            'forum path'              => $this->listingForm( [ 'action' => 'https://labdirectory.test/forum/new-topic' ] ),
            'bbp action query'        => $this->listingForm( [ 'action' => 'https://labdirectory.test/?action=bbp-new-reply' ] ),
            'empty action on a forum' => $this->listingForm( [ 'action' => '', 'page_url' => 'https://labdirectory.test/forums/topic/peptides/' ] ),
            'comment_post_ID field'   => $this->listingForm( [ 'fields' => [ [ 'name' => 'comment_post_ID', 'type' => 'hidden', 'value' => '42' ] ] ] ),
            'comment field'           => $this->listingForm( [ 'fields' => [ [ 'name' => 'comment', 'type' => 'textarea' ] ] ] ),
            'bbp field'               => $this->listingForm( [ 'fields' => [ [ 'name' => 'bbp_reply_content', 'type' => 'textarea' ] ] ] ),
            'woo review field'        => $this->listingForm( [ 'fields' => [ [ 'name' => 'wc_comment_rating', 'type' => 'select' ] ] ] ),
            'hidden bbPress marker'   => $this->listingForm( [ 'fields' => [ [ 'name' => 'action', 'type' => 'hidden', 'value' => 'bbp-new-topic' ] ] ] ),
        ];
        foreach ( $cases as $label => $form ) {
            $this->assertContains( 'The detected form is a blog comment / forum reply form (comment and forum posting is never automated).', $policy->formBlockers( $form ), $label );
            $r = $policy->evaluate( $this->prospectRow( 95, 1 ), [ 'unmapped_required' => [] ], $form, self::TARGET );
            $this->assertFalse( $r['allowed'], $label );
            $this->assertTrue( $r['hard'], $label );
        }

        // Package fields are checked too (compact stored forms carry no field list).
        $this->assertTrue( Policy::isCommentOrForumForm( $this->listingForm( [ 'fields' => [] ] ), [ 'fields' => [ [ 'name' => 'reply_content', 'value' => 'x' ] ] ] ) );

        // An ordinary listing form is untouched.
        $this->assertSame( [], $policy->formBlockers( $this->listingForm() ) );
        $this->assertFalse( Policy::isCommentOrForumForm( $this->listingForm( [ 'fields' => [ [ 'name' => 'business_name', 'type' => 'text' ], [ 'name' => 'description', 'type' => 'textarea' ] ] ] ) ) );
    }

    // ------------------------------------------------------------------ [36] charset of DOM-read values

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function charsetPages(): array {
        return [
            'windows-1252 page declared only in the header' => [ 'windows-1252', 'text/html; charset=windows-1252', '' ],
            'UTF-8 page with a stale iso-8859-1 meta'       => [ 'UTF-8', 'text/html; charset=utf-8', 'iso-8859-1' ],
        ];
    }

    /** @dataProvider charsetPages */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'charsetPages' )]
    public function testCheckboxAndSubmitValuesArePostedInUtf8( string $bytes, string $contentType, string $meta ): void {
        $page = $this->fixture( 'sites/labdirectory.test/add-listing/index.html' );
        $page = str_replace( '<meta charset="utf-8">', '' !== $meta ? '<meta charset="' . $meta . '">' : '', $page );
        $page = str_replace( 'name="accuracy" value="1"', 'name="accuracy" value="Oui, données exactes"', $page );
        $page = str_replace( '<button type="submit">Submit listing</button>', '<button type="submit" name="envoyer" value="Envoyé">Submit listing</button>', $page );
        if ( 'UTF-8' !== $bytes ) {
            $page = (string) mb_convert_encoding( $page, 'Windows-1252', 'UTF-8' );
        }
        HttpFixtures::add( self::PAGE_URL, $page, 200, [ 'content-type' => $contentType ] );

        $sid = (int) $this->engine()->packageProspect( $this->prospect(), 1 );
        $this->assertSame( Submissions::STATUS_QUEUED, $this->subs()->find( $sid )['status'], (string) $this->subs()->find( $sid )['last_error'] );
        $this->subs()->update( $sid, [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
        $this->engine()->processQueue( TimeBudget::seconds( 60 ), 5 );

        $this->assertCount( 1, $this->posts );
        $body = (array) $this->posts[0]['body'];
        $this->assertSame( 'Oui, données exactes', $body['accuracy'] ?? null );
        $this->assertSame( 'Envoyé', $body['envoyer'] ?? null );
    }

    // ------------------------------------------------------------------ [37] broken-link wording

    public function testBrokenLinkCopyMatchesWhatTheProbeFound(): void {
        $this->assertSame( 'no longer resolves', PackageBuilder::brokenLinkProblem( [ 'failure' => 'dns' ] ) );
        $this->assertSame( 'returns a 404 (not found) error', PackageBuilder::brokenLinkProblem( [ 'failure' => 'not_found' ] ) );
        $this->assertSame( 'returns a 410 (gone) error', PackageBuilder::brokenLinkProblem( [ 'failure' => 'gone' ] ) );
        $this->assertSame( 'appears unreachable', PackageBuilder::brokenLinkProblem( [ 'failure' => 'refused' ] ) );
        $this->assertSame( 'appears unreachable', PackageBuilder::brokenLinkProblem( [] ), 'older opportunities fall back to the cautious wording' );

        $builder  = Container::get( PackageBuilder::class );
        $prospect = [ 'id' => 5, 'domain' => 'resources.test', 'name' => 'Lab Resources', 'site_type' => 'resource_page', 'opportunity_type' => 'broken_link' ];
        $message  = function ( array $details ) use ( $builder, $prospect ): string {
            $opp = [ 'id' => 9, 'type' => 'broken_link', 'url' => 'https://resources.test/links/', 'title' => 'Peptide research links', 'details' => $details + [ 'broken_url' => 'https://dead.test/peptide-guide', 'anchor' => 'peptide handling guide' ] ];
            return (string) $builder->build( $prospect, $opp, null )['content']['request_message'];
        };
        $notFound = $message( [ 'failure' => 'not_found' ] );
        $this->assertStringContainsString( 'a link that returns a 404 (not found) error (https://dead.test/peptide-guide)', $notFound );
        $this->assertStringNotContainsString( 'no longer resolves', $notFound );
        $this->assertStringContainsString( 'a link that no longer resolves', $message( [ 'failure' => 'dns' ] ) );
        $this->assertStringContainsString( 'a link that appears unreachable', $message( [] ) );
    }

    // ------------------------------------------------------------------ [23] [24] in-flight guards

    public function testPayloadEditIsRefusedWhileTheRowIsBeingSent(): void {
        $sid    = $this->inFlightSubmission( $this->prospect() );
        $before = $this->subs()->find( $sid );
        $this->assertTrue( SubmissionEngine::isInFlight( $before ) );

        $req = $this->request( 'POST', "/ppclg/v1/submissions/$sid/payload", [ 'id' => $sid, 'payload' => [ 'fields' => [ [ 'name' => 'description', 'value' => 'Edited text' ] ] ] ] );
        $res = $this->submissionsController()->payload( $req );

        $this->assertInstanceOf( WP_Error::class, $res );
        $this->assertSame( 'ppclg_submission_in_flight', $res->get_error_code() );
        $this->assertSame( 409, $res->get_error_data()['status'] );
        $this->assertStringContainsString( 'being sent right now', $res->get_error_message() );
        $this->assertSame( $before['payload'], $this->subs()->find( $sid )['payload'], 'the payload is unchanged' );
    }

    public function testActionsOnARowBeingSentExplainWhy(): void {
        $sid = $this->inFlightSubmission( $this->prospect() );
        foreach ( [ 'reject', 'mark-submitted', 'rebuild' ] as $action ) {
            $req = $this->request( 'POST', "/ppclg/v1/submissions/$sid/$action", [ 'id' => $sid, 'action' => $action, 'reason' => 'reviewer decision' ] );
            $res = $this->submissionsController()->action( $req );
            $this->assertIsArray( $res, $action );
            $this->assertFalse( $res['ok'], $action );
            $this->assertSame( 'This submission is being sent right now; try again in a minute.', $res['message'], $action );
            $this->assertSame( Submissions::STATUS_QUEUED, $res['submission']['status'], $action );
        }

        // A confirmed row still gets the old explanation.
        $confirmed = $this->subs()->create( $this->prospect( 'other.test' ), null, 'https://other.test/add/', [ 'status' => Submissions::STATUS_CONFIRMED, 'submitted_at' => gmdate( 'Y-m-d H:i:s' ) ] );
        $req       = $this->request( 'POST', "/ppclg/v1/submissions/$confirmed/reject", [ 'id' => $confirmed, 'action' => 'reject', 'reason' => 'x' ] );
        $res       = $this->submissionsController()->action( $req );
        $this->assertFalse( $res['ok'] );
        $this->assertStringContainsString( 'already confirmed', $res['message'] );
    }

    // ------------------------------------------------------------------ [25] repository compare-and-set

    public function testRepositoryClaimAndQueueCompareAndSet(): void {
        $repo = $this->subs();
        $pid  = $this->prospect();
        $past = gmdate( 'Y-m-d H:i:s', time() - 120 );
        $sid  = $repo->create( $pid, null, self::TARGET, [ 'status' => Submissions::STATUS_QUEUED, 'scheduled_at' => $past, 'updated_at' => $past ] );
        $row  = $repo->find( $sid );

        $until = time() + 600;
        $this->assertTrue( $repo->claimForSend( $row, $until, [ 'in_flight' => [ 'until' => $until ] ] ) );
        $claimed = $repo->find( $sid );
        $this->assertSame( gmdate( 'Y-m-d H:i:s', $until ), $claimed['scheduled_at'] );
        $this->assertSame( [ 'in_flight' => [ 'until' => $until ] ], $claimed['response'] );
        $this->assertFalse( $repo->claimForSend( $row, $until, [] ), 'a second claim from the same stale read fails' );
        $this->assertFalse( $repo->claimForSend( $claimed, $until, [] ), 'a claimed row is not due' );

        $this->assertFalse( $repo->casStatusFromQueue( $row, Submissions::STATUS_REJECTED ), 'the claim moved scheduled_at, so the stale CAS loses' );
        $this->assertSame( Submissions::STATUS_QUEUED, $repo->find( $sid )['status'] );
        $this->assertTrue( $repo->casStatusFromQueue( $claimed, Submissions::STATUS_REJECTED ) );
        $after = $repo->find( $sid );
        $this->assertSame( Submissions::STATUS_REJECTED, $after['status'] );
        $this->assertNull( $after['scheduled_at'] );

        $unscheduled = $repo->create( $pid, null, 'https://labdirectory.test/other/', [ 'status' => Submissions::STATUS_APPROVED, 'scheduled_at' => null ] );
        $this->assertTrue( $repo->casStatusFromQueue( $repo->find( $unscheduled ), Submissions::STATUS_REJECTED ), 'NULL schedule matches IS NULL' );
    }

    // ------------------------------------------------------------------ [34] pending outreach of rejected/suppressed prospects

    /** @return array<string,int> */
    private function messages( int $pid ): array {
        [ $cid ] = Container::get( Contacts::class )->upsert( $pid, 'editor@' . Container::get( Prospects::class )->find( $pid )['domain'] );
        $repo    = Container::get( OutreachMessages::class );
        $out     = [];
        foreach ( [ OutreachMessages::STATUS_DRAFT, OutreachMessages::STATUS_APPROVED, OutreachMessages::STATUS_SCHEDULED, OutreachMessages::STATUS_SENT, 'sending' ] as $i => $status ) {
            $out[ $status ] = $repo->create( [ 'prospect_id' => $pid, 'contact_id' => $cid, 'sequence' => $i + 1, 'status' => $status ] );
        }
        return $out;
    }

    public function testRejectingAProspectRejectsItsUnsentOutreach(): void {
        $pid = $this->prospect();
        $ids = $this->messages( $pid );
        $res = $this->prospectsController()->setStatus( $this->request( 'POST', "/ppclg/v1/prospects/$pid/status", [ 'id' => $pid, 'status' => 'rejected', 'reason' => 'off topic' ] ) );
        $this->assertIsArray( $res );

        $repo = Container::get( OutreachMessages::class );
        foreach ( [ OutreachMessages::STATUS_DRAFT, OutreachMessages::STATUS_APPROVED, OutreachMessages::STATUS_SCHEDULED ] as $status ) {
            $m = $repo->find( $ids[ $status ] );
            $this->assertSame( OutreachMessages::STATUS_REJECTED, $m['status'], $status );
            $this->assertStringContainsString( 'prospect was rejected', (string) $m['last_error'] );
        }
        $this->assertSame( OutreachMessages::STATUS_SENT, $repo->find( $ids[ OutreachMessages::STATUS_SENT ] )['status'], 'sent history is kept' );
        $this->assertSame( 'sending', $repo->find( $ids['sending'] )['status'], 'a message being sent is left to the send path' );
    }

    public function testSuppressingAProspectClosesItsUnsentOutreach(): void {
        $pid = $this->prospect();
        $ids = $this->messages( $pid );
        $res = $this->prospectsController()->suppress( $this->request( 'POST', "/ppclg/v1/prospects/$pid/suppress", [ 'id' => $pid, 'reason' => 'asked us not to contact them' ] ) );
        $this->assertIsArray( $res );

        $repo = Container::get( OutreachMessages::class );
        foreach ( [ OutreachMessages::STATUS_DRAFT, OutreachMessages::STATUS_APPROVED, OutreachMessages::STATUS_SCHEDULED ] as $status ) {
            $this->assertSame( OutreachMessages::STATUS_CLOSED, $repo->find( $ids[ $status ] )['status'], $status );
        }
        $this->assertSame( OutreachMessages::STATUS_SENT, $repo->find( $ids[ OutreachMessages::STATUS_SENT ] )['status'] );

        // The status endpoint closes them too when it is used to suppress.
        $pid2 = $this->prospect( 'other.test' );
        $ids2 = $this->messages( $pid2 );
        $this->prospectsController()->setStatus( $this->request( 'POST', "/ppclg/v1/prospects/$pid2/status", [ 'id' => $pid2, 'status' => 'suppressed', 'reason' => 'x' ] ) );
        $this->assertSame( OutreachMessages::STATUS_CLOSED, $repo->find( $ids2[ OutreachMessages::STATUS_DRAFT ] )['status'] );

        // Other status changes leave outreach alone.
        $pid3 = $this->prospect( 'third.test' );
        $ids3 = $this->messages( $pid3 );
        $this->prospectsController()->setStatus( $this->request( 'POST', "/ppclg/v1/prospects/$pid3/status", [ 'id' => $pid3, 'status' => 'on_hold', 'reason' => '' ] ) );
        $this->assertSame( OutreachMessages::STATUS_APPROVED, $repo->find( $ids3[ OutreachMessages::STATUS_APPROVED ] )['status'] );
    }

    public function testDrawerSubmissionsTabCopyMatchesTheTriState(): void {
        $src = file_get_contents( PPCLG_DIR . 'assets/js/pages/prospects.js' );
        $this->assertIsString( $src );
        $start = strpos( $src, 'function submissionsTab(' );
        $this->assertNotFalse( $start );
        $end = strpos( $src, 'dataTable(', $start );
        $this->assertNotFalse( $end );
        $copy = substr( $src, $start, $end - $start );

        $this->assertStringNotContainsString( 'automation allowed', $copy, 'the per-site control is no longer an "automation allowed" switch' );
        $this->assertStringNotContainsString( 'even when it is not marked', $copy, '"Never automate" sites never qualify by score' );
        $this->assertStringContainsString( 'whose per-site automation is “Inherit” qualifies by itself once it scores at or above the auto-queue threshold', $copy );
        $this->assertStringContainsString( '“Never automate” keeps a site manual in every mode', $copy );
    }
}
