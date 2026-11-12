<?php
/**
 * Regression tests for the submission slice fixes: the adapter only ever posts the approved listing
 * form, rows rejected/edited during a queue run are never posted, sent submissions are never
 * re-queued, ambiguous POST outcomes are never retried, claims violations block approval and
 * sending, fee/paid-placement boxes are never ticked, static page copy is not taken as a
 * confirmation, packages created at the limit wait in the queue, and the limit counts only
 * requests that were actually sent.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Submission;

use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Submission\AdapterRegistry;
use PPC\LinkGrowth\Submission\Adapters\AdapterInterface;
use PPC\LinkGrowth\Submission\Adapters\Delivery;
use PPC\LinkGrowth\Submission\PackageBuilder;
use PPC\LinkGrowth\Submission\Policy;
use PPC\LinkGrowth\Submission\SubmissionEngine;
use PPC\LinkGrowth\Support\Http\Response;
use PPC\LinkGrowth\Support\Limits;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class SubmissionFixesTest extends TestCase {

    private const PAGE_URL  = 'https://labdirectory.test/add-listing/';
    private const VIOLATING = 'Our FDA approved peptides cure joint pain and are the best on the market. Safe for human use.';

    /** @var array<int,array{url:string,body:mixed}> */
    private array $posts = [];

    /** Response body (or callable/WP_Error) the fake destination answers POSTs with. */
    private mixed $postReply = '<html><body><p>Thank you, your listing has been submitted.</p></body></html>';

    private int $postStatus = 200;

    /** @var array<string,string> */
    private array $postHeaders = [ 'content-type' => 'text/html; charset=utf-8' ];

    /** @var callable|null fn(int $postNumber): void — runs while a POST is "in flight". */
    private $duringPost = null;

    protected function setUp(): void {
        parent::setUp();
        $this->loadFixtureSites();
        $s = Container::get( Settings::class );
        $s->set( 'site.email', 'info@purepeptidescanada.test' );
        $s->set( 'site.city', 'Toronto' );
        $s->set( 'site.region', 'ON' );
        $s->set( 'site.brand_name', 'Pure Peptides Canada' );
        $s->set( 'outreach.sender_name', 'Jane Doe' );
        $s->set( 'advanced.crawl_delay_seconds', 0 ); // no politeness sleeps against the in-memory fixtures
        $this->posts       = [];
        $this->duringPost  = null;
        HttpFixtures::$handler = function ( string $url, array $args ) {
            if ( 'POST' !== strtoupper( (string) ( $args['method'] ?? '' ) ) ) {
                return null;
            }
            $this->posts[] = [ 'url' => $url, 'body' => $args['body'] ?? null ];
            if ( null !== $this->duringPost ) {
                ( $this->duringPost )( count( $this->posts ) );
            }
            if ( $this->postReply instanceof \WP_Error ) {
                return $this->postReply;
            }
            return [ 'response' => [ 'code' => $this->postStatus, 'message' => 'OK' ], 'headers' => $this->postHeaders, 'body' => (string) $this->postReply, 'cookies' => [] ];
        };
    }

    // ------------------------------------------------------------------ helpers

    private function page(): string {
        return $this->fixture( 'sites/labdirectory.test/add-listing/index.html' );
    }

    private function prospect( string $domain = 'labdirectory.test', int $score = 85 ): int {
        [ $id ] = Container::get( Prospects::class )->upsertDiscovered(
            $domain,
            [
                'name'             => 'LabDirectory Canada',
                'status'           => 'qualified',
                'score'            => $score,
                'site_type'        => 'directory',
                'opportunity_type' => 'directory_listing',
                'submission_url'   => 'https://' . $domain . '/add-listing/',
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

    private function runQueue(): array {
        return $this->engine()->processQueue( TimeBudget::seconds( 60 ), 5 );
    }

    private function packageQueued( int $pid ): int {
        $sid = (int) $this->engine()->packageProspect( $pid, 1 );
        $row = $this->subs()->find( $sid );
        $this->assertNotNull( $row );
        $this->assertSame( Submissions::STATUS_QUEUED, $row['status'], (string) $row['last_error'] );
        return $sid;
    }

    private function makeDue( int $sid ): void {
        $this->subs()->update( $sid, [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
    }

    // ------------------------------------------------------------------ submission-1

    /** @return array<string,array{0:bool}> */
    public static function listingFormIds(): array {
        return [ 'listing form with an id' => [ true ], 'listing form without an id' => [ false ] ];
    }

    /** @dataProvider listingFormIds */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'listingFormIds' )]
    public function testNewsletterFormSharingTheListingActionIsNeverPosted( bool $withId ): void {
        $newsletter = '<aside><h3>Supplier newsletter</h3><form method="post" action="#signup"><input type="hidden" name="nl" value="1"><input type="email" name="email" placeholder="Email address" required><button type="submit" name="subscribe" value="1">Sign me up</button></form></aside>';
        $page       = str_replace( '<main>', $newsletter . '<main>', $this->page() );
        $page       = str_replace( 'action="/add-listing/submit"', 'action="#wpcf7-f12-o2"', $page );
        if ( ! $withId ) {
            $page = str_replace( '<form id="listing-form" ', '<form ', $page );
        }
        HttpFixtures::add( self::PAGE_URL, $page );

        $sid = $this->packageQueued( $this->prospect() );
        $this->runQueue();

        $this->assertCount( 1, $this->posts );
        $body = (array) $this->posts[0]['body'];
        $this->assertArrayHasKey( 'business_name', $body, 'the listing form is posted' );
        $this->assertArrayNotHasKey( 'subscribe', $body, 'the newsletter form is never posted' );
        $this->assertArrayNotHasKey( 'nl', $body );
        $this->assertSame( Submissions::STATUS_SUBMITTED, $this->subs()->find( $sid )['status'] );
    }

    public function testLiveFormOfAnotherKindIsNotPosted(): void {
        $sid = $this->packageQueued( $this->prospect() );
        // The listing form was replaced by a contact form that kept the element id.
        $contact = '<form id="listing-form" method="post" action=""><label for="your_name">Your name</label><input type="text" id="your_name" name="your_name">'
            . '<label for="email">Your email</label><input type="email" id="email" name="email" required>'
            . '<label for="your_message">Your message</label><textarea id="your_message" name="your_message" required></textarea>'
            . '<button type="submit" name="send">Send message</button></form>';
        $page = preg_replace( '#<form id="listing-form".*?</form>#s', $contact, $this->page() );
        HttpFixtures::add( self::PAGE_URL, (string) $page );

        $this->runQueue();
        $row = $this->subs()->find( $sid );
        $this->assertSame( [], $this->posts, 'nothing is posted into a form the policy never approved' );
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $row['status'] );
    }

    // ------------------------------------------------------------------ submission-2

    public function testRowRejectedDuringARunIsNeverPosted(): void {
        HttpFixtures::add( 'https://otherdirectory.test/add-listing/', $this->page() );
        HttpFixtures::add( 'https://otherdirectory.test/robots.txt', $this->fixture( 'sites/labdirectory.test/robots.txt' ), 200, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://otherdirectory.test/', '<html><body><a href="/add-listing/">Add</a></body></html>' );
        $s1 = $this->packageQueued( $this->prospect() );
        $s2 = $this->packageQueued( $this->prospect( 'otherdirectory.test', 88 ) );

        $engine           = $this->engine();
        $this->duringPost = function ( int $n ) use ( $engine, $s2 ): void {
            if ( 1 === $n ) {
                $this->assertTrue( $engine->reject( $s2, 1, 'Do not list us on this site' ) );
            }
        };
        $this->runQueue();

        $this->assertSame( Submissions::STATUS_SUBMITTED, $this->subs()->find( $s1 )['status'] );
        $this->assertSame( Submissions::STATUS_REJECTED, $this->subs()->find( $s2 )['status'], 'the rejection stands' );
        $this->assertSame( [ 'labdirectory.test' ], array_map( static fn( array $p ): string => (string) parse_url( $p['url'], PHP_URL_HOST ), $this->posts ) );
    }

    public function testRejectAndEditsAreRefusedWhileTheRowIsBeingSent(): void {
        $sid              = $this->packageQueued( $this->prospect() );
        $engine           = $this->engine();
        $inFlight         = null;
        $this->duringPost = function () use ( $engine, $sid, &$inFlight ): void {
            $row      = $this->subs()->find( $sid );
            $inFlight = SubmissionEngine::isInFlight( $row );
            $this->assertFalse( $engine->reject( $sid, 1, 'too late' ), 'a row being sent cannot be rejected' );
            $this->assertFalse( $engine->rebuildPackage( $sid, 1 ), 'a row being sent cannot be rebuilt' );
            $this->assertFalse( $engine->markSubmittedManually( $sid, 1, '', '' ) );
        };
        $this->runQueue();

        $this->assertTrue( $inFlight );
        $row = $this->subs()->find( $sid );
        $this->assertSame( Submissions::STATUS_SUBMITTED, $row['status'] );
        $this->assertFalse( SubmissionEngine::isInFlight( $row ) );
        $this->assertArrayNotHasKey( SubmissionEngine::CLAIM_KEY, (array) $row['response'], 'the claim marker is cleared' );
    }

    public function testRowsAreReReadBeforeSending(): void {
        $sid = $this->packageQueued( $this->prospect() );
        // Rescheduled after the batch was loaded (e.g. by another process): not posted in this run.
        $rows = $this->subs()->readyToProcess( 5 );
        $this->assertCount( 1, $rows );
        $this->subs()->update( $sid, [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ] );
        $stats = $this->runQueue();
        $this->assertSame( 0, $stats['processed'] );
        $this->assertSame( [], $this->posts );
    }

    public function testInterruptedClaimGoesToAPersonInsteadOfReposting(): void {
        $sid      = $this->packageQueued( $this->prospect() );
        $row      = $this->subs()->find( $sid );
        $response = (array) $row['response'];
        $response[ SubmissionEngine::CLAIM_KEY ] = [ 'since' => gmdate( 'c', time() - 3600 ), 'until' => time() - 1800 ];
        $this->subs()->update( $sid, [ 'response' => $response, 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 1800 ) ] );

        $stats = $this->runQueue();
        $row   = $this->subs()->find( $sid );
        $this->assertSame( 1, $stats['manual'] );
        $this->assertSame( [], $this->posts );
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $row['status'] );
        $this->assertStringContainsString( 'interrupted', (string) $row['last_error'] );
        $this->assertArrayNotHasKey( SubmissionEngine::CLAIM_KEY, (array) $row['response'] );
    }

    // ------------------------------------------------------------------ submission-3

    public function testSentSubmissionIsNeverRequeuedAfterReject(): void {
        $sid = $this->packageQueued( $this->prospect() );
        $this->runQueue();
        $this->assertSame( Submissions::STATUS_SUBMITTED, $this->subs()->find( $sid )['status'] );
        $this->assertCount( 1, $this->posts );

        $engine = $this->engine();
        $this->assertTrue( $engine->reject( $sid, 1, 'wrong category' ) );
        $this->assertFalse( $engine->retry( $sid, 1 ), 'retry refuses an already-sent submission' );
        $this->assertFalse( $engine->rebuildPackage( $sid, 1 ), 'rebuild refuses an already-sent submission' );
        $this->assertSame( Submissions::STATUS_REJECTED, $this->subs()->find( $sid )['status'] );

        // Even if some other path re-queued it, the policy's duplicate gate now fires before sending.
        $this->subs()->update( $sid, [ 'status' => Submissions::STATUS_QUEUED, 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
        $this->runQueue();
        $row = $this->subs()->find( $sid );
        $this->assertCount( 1, $this->posts, 'the same listing is never posted twice' );
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $row['status'] );
        $this->assertStringContainsString( 'already sent', (string) $row['last_error'] );
    }

    public function testSecondTargetOnTheSameSiteIsBlockedAfterASend(): void {
        $pid = $this->prospect();
        $sid = $this->packageQueued( $pid );
        $this->runQueue();
        $first = $this->subs()->find( $sid );
        $this->assertSame( Submissions::STATUS_SUBMITTED, $first['status'] );

        $other = $this->subs()->create( $pid, null, 'https://labdirectory.test/submit-business/', [ 'status' => Submissions::STATUS_QUEUED, 'adapter' => 'generic_form', 'method' => 'form', 'payload' => $first['payload'], 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
        $this->runQueue();
        $row = $this->subs()->find( $other );
        $this->assertCount( 1, $this->posts );
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $row['status'] );
        $this->assertStringContainsString( 'already submitted to this site', (string) $row['last_error'] );
    }

    // ------------------------------------------------------------------ submission-4

    /** @return array<string,array{0:string}> */
    public static function ambiguousOutcomes(): array {
        return [ 'timeout after send' => [ 'timeout' ], 'bad gateway' => [ '502' ], 'server error' => [ '500' ] ];
    }

    /** @dataProvider ambiguousOutcomes */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'ambiguousOutcomes' )]
    public function testAmbiguousPostOutcomesAreNeverRetried( string $mode ): void {
        if ( 'timeout' === $mode ) {
            $this->postReply = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 25001 milliseconds with 0 bytes received' );
        } else {
            $this->postStatus = (int) $mode;
            $this->postReply  = '<html><body>' . $mode . ' Bad Gateway (origin slow)</body></html>';
        }
        $sid = $this->packageQueued( $this->prospect() );
        for ( $i = 0; $i < 3; $i++ ) {
            $this->runQueue();
            $this->makeDue( $sid );
        }
        $row = $this->subs()->find( $sid );
        $this->assertCount( 1, $this->posts, 'a POST with an unknown outcome is never repeated automatically' );
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $row['status'] );
        $this->assertTrue( ! empty( $row['response']['outcome_unknown'] ) );
        $this->assertSame( 1, Container::get( Limits::class )->used( 'submissions' ), 'the sent request counts against the limit' );
    }

    public function testPostThatNeverLeftIsRetriedWithoutSpendingTheLimit(): void {
        $this->postReply = new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to labdirectory.test port 443: Connection refused' );
        $sid             = $this->packageQueued( $this->prospect() );
        $this->runQueue();
        $row = $this->subs()->find( $sid );
        $this->assertSame( Submissions::STATUS_QUEUED, $row['status'], 'nothing reached the destination, so a later retry is safe' );
        $this->assertSame( 1, (int) $row['attempts'] );
        $this->assertSame( 0, Container::get( Limits::class )->used( 'submissions' ) );
    }

    public function testRateLimitHonoursRetryAfterAndOtherwiseGoesToAPerson(): void {
        $this->postStatus  = 429;
        $this->postReply   = '<html><body>Slow down</body></html>';
        $this->postHeaders = [ 'content-type' => 'text/html', 'retry-after' => '86400' ];
        $sid               = $this->packageQueued( $this->prospect() );
        $this->runQueue();
        $row = $this->subs()->find( $sid );
        $this->assertSame( Submissions::STATUS_QUEUED, $row['status'] );
        $this->assertGreaterThanOrEqual( time() + 86000, (int) strtotime( (string) $row['scheduled_at'] . ' UTC' ), 'waits as long as the destination asked' );

        $this->postHeaders = [ 'content-type' => 'text/html' ];
        $this->makeDue( $sid );
        $this->runQueue();
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $this->subs()->find( $sid )['status'] );
        $this->assertCount( 2, $this->posts );
    }

    public function testDeliveryClassifiesPreSendFailures(): void {
        $this->assertTrue( Delivery::neverSent( Response::error( 'https://x.test/', 'cURL error 6: Could not resolve host: x.test', 'http_request_failed' ) ) );
        $this->assertTrue( Delivery::neverSent( Response::error( 'https://x.test/', 'cURL error 28: Connection timed out after 10001 milliseconds', 'http_request_failed' ) ) );
        $this->assertFalse( Delivery::neverSent( Response::error( 'https://x.test/', 'cURL error 28: Operation timed out after 25001 milliseconds with 0 bytes received', 'http_request_failed' ) ) );
        $this->assertFalse( Delivery::neverSent( Response::error( 'https://x.test/', 'cURL error 52: Empty reply from server', 'http_request_failed' ) ) );
        $this->assertSame( 120, Delivery::retryAfter( new Response( 429, [ 'retry-after' => '120' ], '', 'u', 'u', 0.0 ) ) );
        $this->assertNull( Delivery::retryAfter( new Response( 429, [], '', 'u', 'u', 0.0 ) ) );
    }

    // ------------------------------------------------------------------ submission-5

    public function testClaimsViolationsBlockApprovalAndSending(): void {
        $sid = $this->packageQueued( $this->prospect() );
        $row = $this->subs()->find( $sid );
        // A reviewer edit (or any other write) put flagged text into the package; the stored flag is stale.
        $payload                       = (array) $row['payload'];
        $payload['content']['description'] = self::VIOLATING;
        foreach ( $payload['fields'] as $i => $f ) {
            if ( 'description' === ( $f['name'] ?? '' ) ) {
                $payload['fields'][ $i ]['value'] = self::VIOLATING;
            }
        }
        $payload['compliance'] = [ 'clean' => true, 'violations' => [] ];
        $this->subs()->update( $sid, [ 'payload' => $payload ] );

        $this->runQueue();
        $row = $this->subs()->find( $sid );
        $this->assertSame( [], $this->posts, 'flagged text is never posted' );
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $row['status'] );
        $this->assertStringContainsString( 'claims compliance', (string) $row['last_error'] );
        $this->assertFalse( $this->engine()->approve( $sid, 1 ), 'flagged text blocks approval' );

        // Policy itself treats compliance.clean = false as a hard blocker.
        $prospect = Container::get( Prospects::class )->find( (int) $row['prospect_id'] );
        $form     = [ 'kind' => 'listing_submission', 'method' => 'POST', 'action' => self::PAGE_URL, 'fields' => [] ];
        $r        = Container::get( Policy::class )->evaluate( $prospect, [ 'unmapped_required' => [], 'compliance' => [ 'clean' => false, 'violations' => [ [ 'rule' => 'therapeutic' ] ] ] ], $form, 'https://labdirectory.test/other/' );
        $this->assertFalse( $r['allowed'] );
        $this->assertTrue( $r['hard'] );

        // The adapter re-checks the values it is about to send (defence in depth).
        $adapter = Container::get( AdapterRegistry::class )->get( AdapterRegistry::ID_FORM );
        $result  = $adapter->submit( $row, $prospect, $payload );
        $this->assertSame( AdapterInterface::STATUS_NEEDS_MANUAL, $result['status'] );
        $this->assertStringContainsString( 'claims', $result['error'] );
        $this->assertSame( [], $this->posts );
    }

    public function testCleanPackagesCarryAFieldLevelComplianceCheck(): void {
        $sid = $this->packageQueued( $this->prospect() );
        $c   = $this->subs()->find( $sid )['payload']['compliance'];
        $this->assertTrue( $c['clean'] );
        $this->assertSame( [], $c['violations'] );
    }

    // ------------------------------------------------------------------ submission-6

    public function testFeeConsentBoxesAreNeverTicked(): void {
        $page = str_replace( 'I confirm this information is accurate and I agree to the listing terms.', 'I agree to pay the $99 annual listing fee.', $this->page() );
        HttpFixtures::add( self::PAGE_URL, $page );
        $sid = (int) $this->engine()->packageProspect( $this->prospect(), 1 );
        $row = $this->subs()->find( $sid );
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $row['status'] );
        $this->assertContains( 'consent', $row['payload']['unmapped_required'] );
        $this->runQueue();
        $this->assertSame( [], $this->posts );

        foreach ( [ 'I agree to pay the $99 annual listing fee', 'I authorize you to charge my card for the Featured plan', 'I confirm my order for a sponsored (paid) dofollow listing', 'I agree to be invoiced CAD 150 after approval' ] as $label ) {
            $this->assertSame( 1, preg_match( PackageBuilder::CONSENT_PAID, $label ), $label );
        }
        foreach ( [ 'I confirm this information is accurate and I agree to the listing terms.', 'I have read and agree to the Privacy Policy', 'I consent to my data being stored in order to process my submission' ] as $label ) {
            $this->assertSame( 0, preg_match( PackageBuilder::CONSENT_PAID, $label ), $label );
        }
    }

    public function testAdapterNeverTicksAFeeBoxThatAppearedAfterPackaging(): void {
        $sid  = $this->packageQueued( $this->prospect() );
        $page = str_replace( 'I confirm this information is accurate and I agree to the listing terms.', 'I confirm I agree to be billed for the premium listing.', $this->page() );
        HttpFixtures::add( self::PAGE_URL, $page );
        $this->runQueue();
        $row = $this->subs()->find( $sid );
        $this->assertSame( [], $this->posts );
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $row['status'] );
        $this->assertStringContainsString( 'cannot be ticked automatically', (string) $row['last_error'] );
    }

    // ------------------------------------------------------------------ submission-7

    public function testRedisplayedFormWithStaticConfirmationCopyIsNotASuccess(): void {
        $page = str_replace( 'Listings are reviewed by our editorial team within five business days.', 'All listings will be reviewed by our editorial team within five business days.', $this->page() );
        HttpFixtures::add( self::PAGE_URL, $page );
        $this->postReply = str_replace( '<form id="listing-form"', '<p class="error">This field cannot be left blank: Year founded.</p><form id="listing-form"', $page );

        $sid = $this->packageQueued( $this->prospect() );
        $this->runQueue();
        $row = $this->subs()->find( $sid );
        $this->assertCount( 1, $this->posts );
        $this->assertSame( Submissions::STATUS_FAILED, $row['status'], 'the form came back without a new confirmation' );
        $this->assertTrue( $row['response']['form_redisplayed'] );
        $this->assertContains( 'will be reviewed', $row['response']['page_markers'] );
        $this->assertSame( 1, Container::get( Limits::class )->used( 'submissions' ) );
    }

    public function testRedisplayedFormWithANewConfirmationIsASuccess(): void {
        $page = str_replace( 'Listings are reviewed by our editorial team within five business days.', 'All listings will be reviewed by our editorial team within five business days.', $this->page() );
        HttpFixtures::add( self::PAGE_URL, $page );
        $this->postReply = str_replace( '<form id="listing-form"', '<p class="notice">Thank you! Your listing was received.</p><form id="listing-form"', $page );

        $sid = $this->packageQueued( $this->prospect() );
        $this->runQueue();
        $this->assertSame( Submissions::STATUS_SUBMITTED, $this->subs()->find( $sid )['status'] );
    }

    // ------------------------------------------------------------------ submission-8

    public function testPackagesCreatedAtTheLimitWaitInTheQueue(): void {
        $limits = Container::get( Limits::class );
        $limits->spend( 'submissions', 5 );
        $pid = $this->prospect();
        Container::get( Opportunities::class )->upsert( $pid, 'directory_listing', self::PAGE_URL, 'LabDirectory', [], 85 );
        $this->engine()->packageOpenOpportunities( TimeBudget::seconds( 60 ), 10, 70 );
        $sid = (int) ( $this->subs()->findOneBy( [ 'prospect_id' => $pid ] )['id'] ?? 0 );
        $this->assertSame( Submissions::STATUS_QUEUED, $this->subs()->find( $sid )['status'], 'the limit is not a packaging criterion' );

        $this->runQueue();
        $this->assertSame( [], $this->posts, 'nothing is sent while the limit is used up' );
        $this->assertSame( Submissions::STATUS_QUEUED, $this->subs()->find( $sid )['status'] );

        $GLOBALS['ppclg_test_options'][ Limits::OPTION ] = [];
        $this->runQueue();
        $this->assertCount( 1, $this->posts );
        $this->assertSame( Submissions::STATUS_SUBMITTED, $this->subs()->find( $sid )['status'] );
    }

    public function testApprovalWhileTheLimitIsUsedUpApprovesInsteadOfParkingManual(): void {
        Container::get( Settings::class )->set( 'automation.submission_mode', 'authorized_only' );
        $sid = (int) $this->engine()->packageProspect( $this->prospect(), 1 );
        $this->assertSame( Submissions::STATUS_PENDING_REVIEW, $this->subs()->find( $sid )['status'] );
        Container::get( Prospects::class )->update( (int) $this->subs()->find( $sid )['prospect_id'], [ 'automation_allowed' => 1 ] );
        Container::get( Limits::class )->spend( 'submissions', 5 );
        $this->assertTrue( $this->engine()->approve( $sid, 1 ) );
        $this->assertSame( Submissions::STATUS_APPROVED, $this->subs()->find( $sid )['status'] );
    }

    // ------------------------------------------------------------------ submission-9

    public function testLimitIsSpentOnlyWhenARequestWasSent(): void {
        $sid = $this->packageQueued( $this->prospect() );
        HttpFixtures::add( self::PAGE_URL, '<html><body>Service unavailable</body></html>', 503 );
        for ( $i = 0; $i < 3; $i++ ) {
            $this->runQueue();
            $this->makeDue( $sid );
        }
        $row = $this->subs()->find( $sid );
        $this->assertSame( [], $this->posts );
        $this->assertSame( Submissions::STATUS_FAILED, $row['status'] );
        $this->assertSame( 0, Container::get( Limits::class )->used( 'submissions' ), 'a dead form page does not use up the daily limit' );
    }
}
