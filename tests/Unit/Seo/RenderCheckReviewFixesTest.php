<?php
/**
 * 1.2.2 review fixes for automatic links and the page check (Seo\RenderCheck, InternalLinker::autoApply()):
 * - a permanently deleted item with an automatic link is closed instead of stopping the re-check of every other
 *   page (and the job no longer re-queues itself every 15 minutes because of it);
 * - an automatic link a person already removed by hand is closed, not "Removed automatically", and never comes back;
 * - after a timeout / 5xx / challenge / slow answer, page checks pause for a few minutes across runs and requests,
 *   a PHP request makes at most a few page requests, and a Grow now step does not run the background job's paused
 *   automatic-links round at once;
 * - a post split with <!--nextpage--> is judged by its first page, and suggestions on later pages are left for a
 *   person instead of being set aside on page-1 evidence;
 * - the WooCommerce shop page is decided by its page (classic themes print its content as the shop description);
 * - a page that shows an item's words without their formatting (an excerpt) is not taken as showing the content.
 *
 * Observable values (statuses, codes) are written out here, so these tests ran against the checkpoint code too.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Seo;

require_once dirname( __DIR__, 2 ) . '/Support/StubsSeo.php';

use PPC\LinkGrowth\Automation\Audit;
use PPC\LinkGrowth\Automation\Jobs\InternalLinksJob;
use PPC\LinkGrowth\Growth\GrowthRun;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\Jobs;
use PPC\LinkGrowth\Seo\InternalLinker;
use PPC\LinkGrowth\Seo\RenderCheck;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Locks;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\StubsSeo;
use PPC\LinkGrowth\Tests\Support\StubsSeoReview;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class RenderCheckReviewFixesTest extends TestCase {

    private const ARTICLE = '<p>Our laboratory team reconstitutes every research vial with bacteriostatic water before the stability tests begin.</p>'
        . "\n<p>Each batch is logged in the cold room register and checked against the certificate of analysis on arrival.</p>"
        . "\n<p>Long term peptide storage follows the supplier guidance for lyophilised material kept away from light and moisture.</p>";

    protected function setUp(): void {
        parent::setUp();
        StubsSeo::reset();
        $GLOBALS['ppclg_test_caps']    = [];
        $GLOBALS['ppclg_test_user_id'] = 0;
        unset( $GLOBALS['ppclg_seo_update_fails'] );
        StubsSeo::addPost( 100, [ 'post_type' => 'product', 'post_title' => 'Bacteriostatic Water' ] );
        StubsSeo::addPost( 102, [ 'post_type' => 'page', 'post_title' => 'How We Store Reagents', 'post_name' => 'storage-guide', 'post_content' => '<p>Notes on cold rooms.</p>' ], [ 'rank_math_focus_keyword' => 'peptide storage' ] );
        $this->settings()->update( [ 'automation' => [ 'autopilot' => true ], 'seo' => [ 'auto_apply_per_day' => 20 ] ] );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['ppclg_test_caps'], $GLOBALS['ppclg_test_user_caps'] );
        $GLOBALS['ppclg_test_user_id'] = 0;
        parent::tearDown();
    }

    private function settings(): Settings {
        return Container::get( Settings::class );
    }

    private function linker(): InternalLinker {
        $linker = new InternalLinker( $this->settings(), Container::get( Logger::class ), Container::get( Locks::class ), Container::get( Audit::class ) );
        Container::plugin()->container()->set( InternalLinker::class, $linker );
        return $linker;
    }

    /** A new PHP request: fresh page-check and link services (the container keeps one of each per request). */
    private function newRequest(): InternalLinker {
        Container::plugin()->container()->set( RenderCheck::class, new RenderCheck( $this->settings(), Container::get( Logger::class ), Container::get( Client::class ) ) );
        return $this->linker();
    }

    /** @return array<int,array<string,mixed>> */
    private function bySource( InternalLinker $linker, int $source, string $status = '' ): array {
        return array_values( array_filter( $linker->suggestions( $status ), static fn( array $s ): bool => $source === (int) $s['source_post_id'] ) );
    }

    private function article( int $id, string $type = 'page', ?string $content = null ): void {
        StubsSeo::addPost( $id, [ 'post_type' => $type, 'post_title' => 'Article ' . $id, 'post_name' => 'article-' . $id, 'post_content' => $content ?? self::ARTICLE ] );
    }

    /** The page the stand-in theme serves, with $main as what the template prints for the item. */
    private static function page( int $id, string $main, string $bodyClass = '' ): string {
        $p     = StubsSeo::$posts[ $id ];
        $class = '' !== $bodyClass ? $bodyClass : ( 'page' === $p['post_type'] ? 'page-template-default page page-id-' . $id : 'single single-' . $p['post_type'] . ' postid-' . $id );
        return '<!DOCTYPE html><html><head><title>' . htmlspecialchars( (string) $p['post_title'] ) . '</title></head><body class="' . $class . '"><header><nav><a href="/">Home</a> <a href="/product/bacteriostatic-water/">Bacteriostatic Water</a></nav></header>'
            . '<main>' . $main . '</main><footer><p>Research use only</p></footer></body></html>';
    }

    /** @return array<int,array<string,mixed>> */
    private function queuedLinkJobs(): array {
        return Container::get( Jobs::class )->findBy( [ 'type' => 'internal_links', 'status' => Jobs::STATUS_QUEUED ] );
    }

    // ------------------------------------------------------------------ finding 1: a deleted item

    public function testADeletedItemWithAnAutomaticLinkDoesNotStopTheOtherPagesFromBeingChecked(): void {
        $this->article( 501 );
        $this->article( 502 );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $this->assertSame( 4, $linker->autoApply( TimeBudget::seconds( 30 ) )['applied'] );
        // 501's links are the oldest; then 501 is deleted for good (emptied from the trash).
        $store = get_option( InternalLinker::OPTION );
        foreach ( $store['items'] as &$i ) {
            if ( 501 === (int) $i['source_post_id'] ) {
                $i['applied_at'] = '2026-01-01T00:00:00+00:00';
            }
        }
        unset( $i );
        update_option( InternalLinker::OPTION, $store );
        unset( StubsSeo::$posts[501] );
        // 502 becomes the hard-coded front page: its automatic links must still be re-checked and taken out.
        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', 502 );
        StubsSeo::$render[502] = 'hidden';

        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 2, 1 ], [ $res['removed'], $res['fetches'] ], $res['message'] );
        $this->assertFalse( $res['more'], 'nothing was deferred by the request limit or the budget' );
        $this->assertSame( self::ARTICLE, StubsSeo::$posts[502]['post_content'] );
        $gone = $this->bySource( $linker, 501 );
        $this->assertCount( 2, $gone );
        foreach ( $gone as $s ) {
            $this->assertSame( 'stale', $s['status'], 'the deleted item’s links are closed' );
        }
        $this->assertSame( 0, $linker->autoInfo()['present'] );

        // The job does not queue another automatic round because of it.
        $job = ( new InternalLinksJob() )->handle( [], TimeBudget::seconds( 30 ), Container::plugin()->container() );
        $this->assertEmpty( $job['continued'] ?? false, (string) $job['summary'] );
        $this->assertSame( [], $this->queuedLinkJobs() );
    }

    public function testSuggestionsOfADeletedItemAreClosedWithoutARequestAndWithoutAContinuation(): void {
        $this->article( 505 );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $this->assertSame( 2, $linker->counts()['pending'] );
        unset( StubsSeo::$posts[505] );
        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 0, 2, 0 ], [ $res['applied'], $res['closed'], $res['fetches'] ], $res['message'] );
        $this->assertFalse( $res['more'] );
        $this->assertSame( 2, $linker->counts()['stale'] );
    }

    // ------------------------------------------------------------------ finding 2: a link a person removed

    public function testALinkSomeoneRemovedByHandStaysRemovedWhenThePageIsHiddenAndShownAgain(): void {
        $this->article( 503 );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $this->assertSame( 2, $linker->autoApply( TimeBudget::seconds( 30 ) )['applied'] );
        // The owner deletes both links in the editor.
        StubsSeo::$posts[503]['post_content']      = self::ARTICLE;
        StubsSeo::$posts[503]['post_modified_gmt'] = '2026-11-16 10:00:00';
        $linker->run( TimeBudget::seconds( 30 ), [ 'restart' => true ] );
        $this->assertSame( 0, $linker->counts()['pending'], 'as in 1.2.1: never suggested again' );

        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', 503 );
        StubsSeo::$render[503] = 'hidden';
        $saves = count( StubsSeo::$updates );
        $res   = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 0, $res['removed'], $res['message'] );
        $this->assertCount( $saves, StubsSeo::$updates, 'nothing to save' );
        $info = $linker->autoInfo();
        $this->assertSame( [ 0, 0, 0 ], [ $info['removed'], $info['not_shown'], $info['present'] ], 'the automation removed nothing' );
        foreach ( $this->bySource( $linker, 503 ) as $s ) {
            $this->assertNotContains( $s['status'], [ 'pending', 'not_shown' ], 'closed, not set aside to return' );
            $this->assertStringNotContainsString( 'Removed automatically', (string) $s['note'] );
        }
        $this->assertSame( [], Container::get( AuditLog::class )->findBy( [ 'action' => 'seo.internal_link_auto_removed' ] ), 'no removal is audited' );

        // The page shows its content again: the links the owner deleted are not added back.
        update_option( 'page_on_front', 0 );
        StubsSeo::$render[503] = 'content';
        $back = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 0, $back['applied'], $back['message'] );
        $linker->run( TimeBudget::seconds( 30 ), [ 'restart' => true ] );
        $this->assertSame( 0, $linker->autoApply( TimeBudget::seconds( 30 ) )['applied'] );
        $this->assertSame( self::ARTICLE, StubsSeo::$posts[503]['post_content'] );
    }

    // ------------------------------------------------------------------ finding 3: Grow now and host trouble

    public function testOneGrowNowStepMakesAtMostThreePageRequestsAndNoneAfterAServerError(): void {
        for ( $id = 561; $id <= 575; $id++ ) {
            $this->article( $id, 'post', '<p>Reconstitute every research vial number ' . $id . ' with bacteriostatic water before the stability tests begin.</p>' );
        }
        $this->linker();
        // The background run: three page checks, then a paused automatic-links round 15 minutes later.
        $bg = ( new InternalLinksJob() )->handle( [], TimeBudget::seconds( 30 ), Container::plugin()->container() );
        $this->assertSame( 3, $bg['page_checks'] );
        $this->assertTrue( $bg['continued'] );
        $this->assertCount( 1, $this->queuedLinkJobs() );

        // A Grow now click (a REST request of its own) runs the internal_links step.
        $this->newRequest();
        StubsSeo::$renderRequests = [];
        $run   = new GrowthRun( Container::plugin()->container() );
        $state = $this->startRun( $run );
        $this->jobStep( $run, $state, TimeBudget::seconds( 25 ) );
        $this->assertLessThanOrEqual( 3, count( StubsSeo::$renderRequests ), 'one Grow now step: at most 3 page requests' );
        $queued = $this->queuedLinkJobs();
        $this->assertCount( 1, $queued, 'the background round keeps its pause' );
        $payload = is_array( $queued[0]['payload'] ) ? $queued[0]['payload'] : json_decode( (string) $queued[0]['payload'], true );
        $this->assertTrue( $payload['auto_only'] );
        $this->assertGreaterThan( time(), strtotime( (string) $queued[0]['scheduled_at'] . ' UTC' ) );

        // The host is in trouble: the first page answers 503 and nothing more is asked in that step...
        foreach ( array_keys( StubsSeo::$posts ) as $id ) {
            StubsSeo::$renderStatus[ $id ] = 503;
        }
        $this->newRequest();
        StubsSeo::$renderRequests = [];
        delete_option( GrowthRun::OPTION );
        $state = $this->startRun( $run );
        $this->jobStep( $run, $state, TimeBudget::seconds( 25 ) );
        $this->assertCount( 1, StubsSeo::$renderRequests, 'no request after the 503' );

        // ... nor by the next request a few minutes later (another click, the cron).
        $linker = $this->newRequest();
        StubsSeo::$renderRequests = [];
        $again = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 0, $again['fetches'], $again['message'] );
        $this->assertSame( [], StubsSeo::$renderRequests );
        $this->assertFalse( $again['more'] );
    }

    public function testTheRequestLimitHoldsAcrossRunsInOneRequestAndTheStopAfterTroubleLasts(): void {
        for ( $id = 581; $id <= 588; $id++ ) {
            $this->article( $id, 'post', '<p>Reconstitute every research vial number ' . $id . ' with bacteriostatic water before the stability tests begin.</p>' );
        }
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $first  = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $second = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 3, $first['fetches'] );
        $this->assertSame( 0, $second['fetches'], 'the same PHP request: no more page requests' );
        $this->assertCount( 3, StubsSeo::$renderRequests );

        // A 503 stops page requests for the next runs too, until the pause is over.
        $linker = $this->newRequest();
        StubsSeo::$renderStatus[584] = 503;
        StubsSeo::$renderRequests    = [];
        $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertCount( 1, StubsSeo::$renderRequests );
        $linker = $this->newRequest();
        $this->assertSame( 0, $linker->autoApply( TimeBudget::seconds( 30 ) )['fetches'], 'paused after the 503' );
        $this->assertCount( 1, StubsSeo::$renderRequests );
        // Ten minutes later the checks resume.
        $GLOBALS['ppclg_test_transients'] = [];
        $linker                           = $this->newRequest();
        $this->assertGreaterThan( 0, $linker->autoApply( TimeBudget::seconds( 30 ) )['fetches'] );
    }

    /** @return array<string,mixed> the stored run state */
    private function startRun( GrowthRun $run ): array {
        $run->start( 1 );
        $load = new \ReflectionMethod( GrowthRun::class, 'load' );
        $load->setAccessible( true );
        $state = $load->invoke( $run );
        $this->assertIsArray( $state );
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function jobStep( GrowthRun $run, array &$state, TimeBudget $budget ): string {
        $i = null;
        foreach ( $state['steps'] as $n => $step ) {
            if ( 'internal_links' === $step['key'] ) {
                $i = $n;
            }
        }
        $this->assertNotNull( $i );
        $step = new \ReflectionMethod( GrowthRun::class, 'jobStep' );
        $step->setAccessible( true );
        return (string) $step->invokeArgs( $run, [ &$state, $i, $budget, 0 ] );
    }

    // ------------------------------------------------------------------ finding 5: <!--nextpage-->

    public function testAMultiPagePostIsJudgedByItsFirstPage(): void {
        $url   = 'https://purepeptidescanada.test/split/';
        $page2 = '<!-- wp:paragraph --><p>Our laboratory team reconstitutes every research vial with bacteriostatic water before the stability tests begin.</p><!-- /wp:paragraph -->'
            . "\n<!-- wp:paragraph --><p>Each batch is logged in the cold room register and checked against the certificate of analysis on arrival.</p><!-- /wp:paragraph -->";
        $short   = '<!-- wp:paragraph --><p>Part one: an overview.</p><!-- /wp:paragraph -->';
        $content = $short . "\n<!-- wp:nextpage -->\n<!--nextpage-->\n<!-- /wp:nextpage -->\n" . $page2;
        // What WordPress serves at the permalink: page 1 only, with the page links.
        $html = '<html><body class="single single-post postid-77"><main>' . $short . '<p class="post-nav-links">Pages: 1 2</p></main></body></html>';
        $v    = RenderCheck::evaluate( $html, 77, $url, $content, [ 9 => 'bacteriostatic water' ] );
        $this->assertNotSame( 'hidden', $v['verdict'], 'text on page 2 is no evidence that the template hides the content' );
        $this->assertSame( 'later', $v['found'][9], 'the anchor is on page 2, which this request did not read' );

        $long    = '<!-- wp:paragraph --><p>Long term peptide storage follows the supplier guidance for lyophilised material kept away from light and moisture.</p><!-- /wp:paragraph -->';
        $content = $long . "\n<!--nextpage-->\n" . $page2;
        $html    = '<html><body class="single single-post postid-77"><main>' . $long . '<p>Pages: 1 2</p></main></body></html>';
        $v       = RenderCheck::evaluate( $html, 77, $url, $content, [ 8 => 'peptide storage', 9 => 'bacteriostatic water' ] );
        $this->assertSame( [ 'shown', 'yes', 'later' ], [ $v['verdict'], $v['found'][8], $v['found'][9] ] );
    }

    public function testSuggestionsOnLaterPagesAreLeftForAPersonAndExistingLinksStay(): void {
        // The test stand-in for wp_kses_post() (strip_tags) drops HTML comments such as <!--nextpage-->, which
        // WordPress keeps: an owner who may save unfiltered HTML lets the saves go through as on a real site.
        $GLOBALS['ppclg_test_user_caps'] = [ 1 => [ 'edit_post' => true, 'unfiltered_html' => true ] ];
        update_option( \PPC\LinkGrowth\Automation\Autopilot::OPTION_CONSENT, [ 'user_id' => 1, 'at' => '2026-11-16T10:00:00+00:00', 'policy_version' => \PPC\LinkGrowth\Automation\Autopilot::POLICY_VERSION ] );
        $page2 ="<p>Our laboratory team reconstitutes every research vial with bacteriostatic water before the stability tests begin.</p>\n"
            . '<p>Each batch is logged in the cold room register and checked against the certificate of analysis on arrival.</p>';
        // A long first page with one anchor; the other anchor is on page 2.
        $this->article( 591, 'post', "<p>Long term peptide storage follows the supplier guidance for lyophilised material kept away from light and moisture.</p>\n<!--nextpage-->\n" . $page2 );
        StubsSeo::$render[591] = static function ( int $id ): string {
            $parts = explode( '<!--nextpage-->', (string) StubsSeo::$posts[ $id ]['post_content'] );
            return self::page( $id, $parts[0] . '<p>Pages: 1 2</p>' );
        };
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 1, 0 ], [ $res['applied'], $res['not_shown'] ], $res['message'] );
        $this->assertStringContainsString( 'peptide storage</a>', StubsSeo::$posts[591]['post_content'] );
        $later = $this->bySource( $linker, 591, 'pending' );
        $this->assertCount( 1, $later, 'not set aside as "not shown"' );
        $this->assertSame( 'bacteriostatic water', $later[0]['anchor'] );
        $this->assertNotEmpty( $later[0]['auto_skip'] ?? '', 'left for a person: the page check reads only the first page' );

        // A short first page and an automatic link on page 2 (added by 1.2.1, before page checks existed).
        $this->article( 592, 'post', "<p>Part one: an overview.</p>\n<!--nextpage-->\n" . $page2 );
        $linker->run( TimeBudget::seconds( 30 ), [ 'restart' => true ] );
        $GLOBALS['ppclg_test_filters']['ppclg_render_check_pre'] = [ static fn( $v, int $id, array $terms ) => 592 === $id ? [ 'verdict' => 'shown', 'code' => 'shown', 'note' => '', 'found' => array_fill_keys( array_keys( $terms ), 'yes' ) ] : $v ];
        $this->assertSame( 1, $linker->autoApply( TimeBudget::seconds( 30 ) )['applied'] );
        $GLOBALS['ppclg_test_filters']['ppclg_render_check_pre'] = [];
        $this->assertStringContainsString( 'bacteriostatic water</a>', StubsSeo::$posts[592]['post_content'] );
        // Now the item is checked against what WordPress serves at its address: page 1 only.
        StubsSeo::$render[592]                     = StubsSeo::$render[591];
        StubsSeo::$posts[592]['post_modified_gmt'] = '2026-11-16 11:00:00';
        $again = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 0, $again['removed'], 'no page-level "hidden" from text on page 2: ' . $again['message'] );
        $this->assertStringContainsString( 'bacteriostatic water</a>', StubsSeo::$posts[592]['post_content'] );
    }

    public function testWithAShortFirstPageSuggestionsOnLaterPagesAreLeftForAPersonAndNotReportedAsUnchecked(): void {
        // A first page with no run of 8 words (nothing to compare: "too little text") and both anchors on page 2.
        $this->article( 592, 'post', "<p>Part one: an overview.</p>\n<!--nextpage-->\n" . self::ARTICLE );
        StubsSeo::$render[592] = static function ( int $id ): string {
            $parts = explode( '<!--nextpage-->', (string) StubsSeo::$posts[ $id ]['post_content'] );
            return self::page( $id, $parts[0] . '<p>Pages: 1 2</p>' );
        };
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 'bacteriostatic water', 'peptide storage' ], array_column( $this->bySource( $linker, 592, 'pending' ), 'anchor' ), 'precondition: both on page 2' );

        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 0, 2, 0, 0, 1 ], [ $res['applied'], $res['refused'], $res['not_shown'], $res['unchecked'], $res['fetches'] ], $res['message'] );
        $this->assertStringContainsString( '2 left for you to review', $res['message'] );
        $this->assertStringNotContainsString( 'could not be checked', $res['message'] );
        $left = $this->bySource( $linker, 592, 'refused' );
        $this->assertCount( 2, $left, 'left for a person, not waiting to be applied automatically' );
        foreach ( $left as $s ) {
            $this->assertSame( 'later_page', $s['auto_skip_code'] ?? '', $s['anchor'] );
            $this->assertStringContainsString( 'later page of this item', (string) $s['auto_skip'] );
            $this->assertArrayNotHasKey( 'render_note', $s, 'no "too little text" note on a long article' );
        }
        $this->assertSame( 2, $linker->autoInfo()['left_for_you'] );

        // The next run, and one in a later request once the stored verdict is gone, ask nothing and report nothing.
        $again = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 0, 0, 0 ], [ $again['refused'], $again['unchecked'], $again['fetches'] ], $again['message'] );
        delete_option( RenderCheck::OPTION );
        $later = $this->newRequest()->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 0, 0 ], [ $later['unchecked'], $later['fetches'] ], $later['message'] );
        $this->assertCount( 2, $this->bySource( $linker, 592, 'refused' ) );
        $this->assertStringNotContainsString( '</a>', StubsSeo::$posts[592]['post_content'], 'nothing was linked' );
    }

    // ------------------------------------------------------------------ finding 6: the shop page

    public function testTheShopPageIsDecidedByItsPage(): void {
        $this->article( 701 );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $this->assertSame( 2, $linker->autoApply( TimeBudget::seconds( 30 ) )['applied'] );
        // The page becomes the WooCommerce shop of a classic theme, which prints its content as the shop description.
        StubsSeoReview::$wcPages = [ 'shop' => 701 ];
        $products                = '<ul class="products"><li>Bacteriostatic Water</li><li>Sterile Saline</li></ul>';
        StubsSeo::$render[701]   = static fn( int $id ): string => self::page( $id, '<div class="page-description">' . StubsSeo::$posts[ $id ]['post_content'] . '</div>' . $products, 'archive post-type-archive post-type-archive-product woocommerce-shop woocommerce woocommerce-page' );
        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 0, 1 ], [ $res['removed'], $res['fetches'] ], $res['message'] );
        $this->assertSame( 2, substr_count( (string) StubsSeo::$posts[701]['post_content'], '</a>' ), 'the visible links stay' );

        // A shop page that shows only the catalogue: its page says so, and the links go.
        StubsSeo::$render[701]                     = static fn( int $id ): string => self::page( $id, $products, 'archive post-type-archive post-type-archive-product woocommerce-shop woocommerce woocommerce-page' );
        StubsSeo::$posts[701]['post_modified_gmt'] = '2026-11-16 12:00:00';
        $off = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 2, $off['removed'], $off['message'] );
        $this->assertSame( self::ARTICLE, StubsSeo::$posts[701]['post_content'] );

        // Cart, checkout and account pages stay decided from the settings, without a request.
        $this->article( 702 );
        StubsSeoReview::$wcPages = [ 'shop' => 701, 'cart' => 702 ];
        StubsSeo::$renderRequests = [];
        $this->assertSame( 'woo_cart', ( new RenderCheck( $this->settings(), Container::get( Logger::class ) ) )->cheap( 702 )['code'] ?? '' );
        $this->assertSame( [], StubsSeo::$renderRequests );
    }

    // ------------------------------------------------------------------ finding 8: words without their markup

    public function testAPageShowingTheWordsWithoutTheirFormattingIsNotTakenAsShowingTheContent(): void {
        $content = '<p>Our laboratory team reconstitutes every <strong>research vial</strong> with bacteriostatic water before the stability tests begin.</p>'
            . "\n<p>Each batch is logged in the <em>cold room register</em> and checked against the certificate of analysis on arrival.</p>"
            . "\n<p>Long term peptide storage follows the supplier guidance for lyophilised material kept away from light and moisture.</p>";
        $this->article( 711, 'post', $content );
        // A template that prints a plain-text excerpt (the_excerpt(), wp_trim_words()) instead of the content.
        StubsSeo::$render[711] = static function ( int $id ): string {
            $words = preg_split( '/\s+/', trim( strip_tags( (string) StubsSeo::$posts[ $id ]['post_content'] ) ) ) ?: [];
            return self::page( $id, '<p class="excerpt">' . implode( ' ', array_slice( $words, 0, 55 ) ) . '</p>' );
        };
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 0, 0 ], [ $res['applied'], $res['not_shown'] ], $res['message'] );
        $this->assertSame( $content, StubsSeo::$posts[711]['post_content'] );
        $this->assertSame( 2, $linker->counts()['pending'], 'neither set aside nor left for a person: checked again later' );

        // The theme prints the content: the formatting is there and the links are added.
        StubsSeo::$render[711] = 'content';
        $all                   = get_option( RenderCheck::OPTION );
        $all[711]['expires']   = time() - 1;
        update_option( RenderCheck::OPTION, $all );
        $this->assertSame( 2, $linker->autoApply( TimeBudget::seconds( 30 ) )['applied'] );

        // An excerpt never takes a link back out (a stale page cache can look the same).
        StubsSeo::$render[711]                     = static function ( int $id ): string {
            $words = preg_split( '/\s+/', trim( strip_tags( (string) StubsSeo::$posts[ $id ]['post_content'] ) ) ) ?: [];
            return self::page( $id, '<p class="excerpt">' . implode( ' ', array_slice( $words, 0, 55 ) ) . '</p>' );
        };
        StubsSeo::$posts[711]['post_modified_gmt'] = '2026-11-16 13:00:00';
        $this->assertSame( 0, $linker->autoApply( TimeBudget::seconds( 30 ) )['removed'] );
        $this->assertSame( 2, substr_count( (string) StubsSeo::$posts[711]['post_content'], '</a>' ) );
    }
}
