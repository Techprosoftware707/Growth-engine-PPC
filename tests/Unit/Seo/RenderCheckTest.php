<?php
/**
 * 1.2.2 — automatic internal links only where visitors see them (Seo\RenderCheck):
 * - before Autopilot edits an item, its public page is requested once (anonymous, no redirects, HTML only, never
 *   text/markdown) and must name the item and show its text; the words around each anchor must be on the page;
 * - a page that does not show its content (the live home page: a hard-coded front-page template) gets no automatic
 *   link, its suggestions are set aside as "not shown" with a note, and an automatic link already on it is taken
 *   back out through the undo path (exact restore when unedited, else only the <a>; a revision first; audited);
 *   it comes back once the page shows its content again;
 * - the posts page and the WooCommerce cart / checkout / account pages are decided without a request (the shop page
 *   is checked like any page: classic themes print its content as the shop description, RenderCheckReviewFixesTest);
 * - an error, a timeout, a non-200 answer, a page that does not name the item or a slow answer is "unknown": nothing
 *   changes, the item is tried on a later run; timeouts, 5xx and slow answers stop further requests in the run and
 *   pause page checks for a few minutes in later runs too;
 * - at most a few requests per run and per PHP request; the verdict is kept per item and checked again when the item's modification
 *   time, its template, the theme or the front-page settings change, or after a week;
 * - a person's Apply still works on such an item and says that visitors will not see the link.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Seo;

require_once dirname( __DIR__, 2 ) . '/Support/StubsSeo.php';

use PPC\LinkGrowth\Automation\Audit;
use PPC\LinkGrowth\Automation\Autopilot;
use PPC\LinkGrowth\Automation\Jobs\InternalLinksJob;
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

final class RenderCheckTest extends TestCase {

    private const WATER_URL = 'https://purepeptidescanada.test/product/bacteriostatic-water/';

    /** Long-form content like the live articles: three paragraphs of real sentences. */
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

    private function linker( ?RenderCheck $render = null ): InternalLinker {
        $linker = new InternalLinker( $this->settings(), Container::get( Logger::class ), Container::get( Locks::class ), Container::get( Audit::class ), $render );
        Container::plugin()->container()->set( InternalLinker::class, $linker );
        return $linker;
    }

    private function render(): RenderCheck {
        return Container::get( RenderCheck::class );
    }

    /** A later PHP request (another cron tick): fresh page-check and link services, the same stored data. */
    private function laterRequest(): InternalLinker {
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

    private function asAdmin(): void {
        $GLOBALS['ppclg_test_caps']    = [ 'manage_options' => true, 'edit_post' => true, 'unfiltered_html' => true ];
        $GLOBALS['ppclg_test_user_id'] = 1;
    }

    // ------------------------------------------------------------------ shown

    public function testAPageThatShowsTheWordsGetsTheLinkAfterOneAnonymousHtmlRequest(): void {
        $this->article( 501 );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $this->assertCount( 2, $this->bySource( $linker, 501, 'pending' ) );

        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 2, $res['applied'], $res['message'] );
        $this->assertSame( 1, $res['fetches'], 'one request for the item, both suggestions compared against it' );
        $this->assertCount( 1, StubsSeo::$renderRequests );
        $req = StubsSeo::$renderRequests[0];
        $this->assertSame( 'https://purepeptidescanada.test/article-501/', $req['url'] );
        $this->assertSame( 'GET', $req['args']['method'] );
        $this->assertSame( 0, $req['args']['redirection'], 'redirects are not followed' );
        $this->assertArrayNotHasKey( 'cookies', $req['args'], 'anonymous, as a visitor' );
        $this->assertStringNotContainsString( 'markdown', strtolower( (string) $req['args']['headers']['Accept'] ) );
        $this->assertStringContainsString( 'text/html', (string) $req['args']['headers']['Accept'] );
        $this->assertFalse( $req['args']['sslverify'], 'a loopback request (https_local_ssl_verify)' );
        $this->assertSame( 2048 * 1024, $req['args']['limit_response_size'] );
        $this->assertStringContainsString( '<a href="' . self::WATER_URL . '">bacteriostatic water</a>', StubsSeo::$posts[501]['post_content'] );

        // The verdict is kept, and carried over the plugin's own edits (only <a> tags changed).
        $cached = $this->render()->cached( 501 );
        $this->assertNotNull( $cached );
        $this->assertSame( RenderCheck::SHOWN, $cached['verdict'] );
        $this->assertSame( 3, $cached['samples'] );
        $this->assertSame( StubsSeo::$posts[501]['post_modified_gmt'], $cached['modified'] );
    }

    public function testOnlyTheWordsThePageShowsAreLinked(): void {
        $this->article( 502 );
        StubsSeo::$render[502] = 'partial'; // e.g. a template that prints only the first paragraph
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 1, $res['applied'], $res['message'] );
        $this->assertSame( 1, $res['not_shown'] );
        $this->assertStringContainsString( 'bacteriostatic water</a>', StubsSeo::$posts[502]['post_content'] );
        $this->assertStringNotContainsString( 'peptide storage</a>', StubsSeo::$posts[502]['post_content'] );
        $set = $this->bySource( $linker, 502, InternalLinker::STATUS_NOT_SHOWN );
        $this->assertCount( 1, $set );
        $this->assertSame( 'peptide storage', $set[0]['anchor'] );
        $this->assertSame( RenderCheck::NOTE_WORDS, $set[0]['note'] );
        $this->assertSame( 'words', $set[0]['render_scope'] );
    }

    // ------------------------------------------------------------------ hidden (the live case)

    public function testTheHardCodedFrontPageGetsNoLinkAndItsSuggestionsAreSetAside(): void {
        $this->article( 134 );
        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', 134 );
        StubsSeo::$render[134] = 'hidden'; // front-page.php ignores the page content, like the live theme
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $this->assertSame( 2, $linker->counts()['pending'] );

        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 0, 2, 1 ], [ $res['applied'], $res['not_shown'], $res['fetches'] ], $res['message'] );
        $this->assertStringContainsString( '2 set aside because the page doesn’t display its content', $res['message'] );
        $this->assertSame( self::ARTICLE, StubsSeo::$posts[134]['post_content'], 'nothing edited' );
        $this->assertSame( [], StubsSeo::$updates );
        $counts = $linker->counts();
        $this->assertSame( [ 0, 2, 0 ], [ $counts['pending'], $counts['not_shown'], $counts['refused'] ], 'neither waiting nor left for review' );
        foreach ( $this->bySource( $linker, 134 ) as $s ) {
            $this->assertSame( InternalLinker::STATUS_NOT_SHOWN, $s['status'] );
            $this->assertSame( RenderCheck::NOTE_TEMPLATE, $s['note'] );
        }
        $this->assertSame( 2, $linker->autoInfo()['not_shown'] );

        // Regenerating the list keeps them set aside, and the next run asks nothing (the verdict is kept).
        $linker->run( TimeBudget::seconds( 30 ), [ 'restart' => true ] );
        $this->assertSame( 2, $linker->counts()['not_shown'] );
        $again = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 0, 0 ], [ $again['applied'], $again['fetches'] ] );
        $this->assertSame( 'No internal-link suggestion is waiting to be applied automatically.', $again['message'] );
    }

    public function testAnAutomaticLinkOnAPageThatStopsShowingItsContentIsTakenBackOutAndReturnsLater(): void {
        $this->article( 134 );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $this->assertSame( 2, $linker->autoApply( TimeBudget::seconds( 30 ) )['applied'] );
        $linked = StubsSeo::$posts[134]['post_content'];
        $this->assertSame( 2, $linker->autoInfo()['present'] );

        // The page becomes the static front page of a theme whose front page is hard-coded (the live situation).
        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', 134 );
        StubsSeo::$render[134] = 'hidden';
        $updates = count( StubsSeo::$updates );
        $revs    = count( StubsSeo::$revisions[134] ?? [] );
        $res     = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 0, 2 ], [ $res['applied'], $res['removed'] ], $res['message'] );
        $this->assertStringContainsString( '2 automatic links removed from pages that don’t display their content', $res['message'] );
        $this->assertSame( self::ARTICLE, StubsSeo::$posts[134]['post_content'], 'unedited since: exactly the content from before the links' );
        $this->assertCount( $updates + 1, StubsSeo::$updates, 'one save for both links' );
        $this->assertGreaterThan( $revs, count( StubsSeo::$revisions[134] ), 'revisions kept' );
        $this->assertSame( 0, StubsSeo::$updates[ $updates ]['_user'], 'saved without a user: kses leaves this item unchanged' );
        $audit = Container::get( AuditLog::class )->findBy( [ 'action' => 'seo.internal_link_auto_removed' ] );
        $this->assertCount( 1, $audit );
        $this->assertSame( Autopilot::ACTOR, $audit[0]['actor'] );
        $this->assertSame( [ 'restored', 'restored' ], $audit[0]['details']['modes'] );
        $this->assertSame( 'not_displayed', $audit[0]['details']['reason'] );
        $info = $linker->autoInfo();
        $this->assertSame( [ 0, 2, 2 ], [ $info['present'], $info['removed'], $info['not_shown'] ] );
        foreach ( $this->bySource( $linker, 134 ) as $s ) {
            $this->assertSame( InternalLinker::STATUS_NOT_SHOWN, $s['status'] );
            $this->assertSame( InternalLinker::REMOVED_NOTE, $s['note'] );
        }

        // The theme starts showing the page's content again: the links are checked and added again.
        update_option( 'page_on_front', 0 );
        StubsSeo::$render[134] = 'content';
        $back = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 2, $back['applied'], $back['message'] );
        $this->assertSame( $linked, StubsSeo::$posts[134]['post_content'] );
        $this->assertSame( 2, $linker->autoInfo()['present'] );
    }

    public function testInAnItemEditedSinceOnlyTheInsertedAnchorIsRemoved(): void {
        $this->article( 503 );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $this->assertSame( 2, $linker->autoApply( TimeBudget::seconds( 30 ) )['applied'] );
        $edited                                 = StubsSeo::$posts[503]['post_content'] . "\n<p>Added later by the editor.</p>";
        StubsSeo::$posts[503]['post_content']      = $edited;
        StubsSeo::$posts[503]['post_modified_gmt'] = '2026-11-16 10:00:00';
        StubsSeo::$render[503]                     = 'hidden';
        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 2, $res['removed'] );
        $this->assertSame( self::ARTICLE . "\n<p>Added later by the editor.</p>", StubsSeo::$posts[503]['post_content'], 'the words stay, only the two <a> tags went' );
        $audit = Container::get( AuditLog::class )->findBy( [ 'action' => 'seo.internal_link_auto_removed' ] );
        $this->assertSame( [ 'unlinked', 'unlinked' ], $audit[0]['details']['modes'] );
    }

    public function testPostsPageAndWooCommercePagesAreDecidedWithoutARequest(): void {
        $render = $this->render();
        $this->article( 601 );
        $this->article( 602 );
        $this->article( 603 );
        update_option( 'page_for_posts', 601 );
        $this->assertNull( $render->cheap( 601 ), 'the posts page setting counts only with a static front page' );
        update_option( 'show_on_front', 'page' );
        StubsSeoReview::$wcPages = [ 'shop' => 604, 'cart' => 602, 'checkout' => 603 ];
        $this->assertSame( [ RenderCheck::HIDDEN, 'posts_page', RenderCheck::NOTE_POSTS ], array_values( array_intersect_key( (array) $render->cheap( 601 ), array_flip( [ 'verdict', 'code', 'note' ] ) ) ) );
        $this->assertSame( 'woo_cart', $render->cheap( 602 )['code'] );
        $this->assertSame( 'woo_checkout', $render->cheap( 603 )['code'] );
        $this->assertSame( RenderCheck::NOTE_WOO, $render->cheap( 603 )['note'] );
        $this->assertNull( $render->cheap( 604 ), 'the shop page is checked from its page (it can show its content as the shop description)' );

        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 0, 0 ], [ $res['applied'], $res['fetches'] ] );
        $this->assertSame( [], StubsSeo::$renderRequests, 'no request at all' );
        $this->assertSame( 6, $linker->counts()['not_shown'] );
        $this->assertSame( RenderCheck::NOTE_POSTS, $this->bySource( $linker, 601 )[0]['note'] );
    }

    // ------------------------------------------------------------------ unknown

    public function testAServerErrorIsUnknownSkipsTheItemAndStopsFurtherRequests(): void {
        $this->article( 511 );
        $this->article( 512 );
        StubsSeo::$renderStatus[511] = 503;
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 0, $res['applied'] );
        $this->assertCount( 1, StubsSeo::$renderRequests, 'no second request after a 5xx' );
        $this->assertFalse( $res['more'], 'no continuation while the host is in trouble' );
        $this->assertStringContainsString( '1 page could not be checked (tried again later)', $res['message'] );
        $this->assertSame( 'http', $this->render()->stopped() );
        foreach ( $this->bySource( $linker, 511 ) as $s ) {
            $this->assertSame( InternalLinker::STATUS_PENDING, $s['status'], 'never a permanent verdict' );
            $this->assertEmpty( $s['auto_skip'] ?? '' );
            $this->assertStringContainsString( 'HTTP 503', (string) $s['render_note'] );
        }
        $this->assertSame( self::ARTICLE, StubsSeo::$posts[511]['post_content'] );

        // Runs in the next minutes ask nothing at all (the host gets a break).
        StubsSeo::$renderStatus[511] = 200;
        $linker = $this->laterRequest();
        $paused = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 0, 0 ], [ $paused['applied'], $paused['fetches'] ], $paused['message'] );
        $this->assertCount( 1, StubsSeo::$renderRequests );
        $this->assertStringContainsString( 'page checks are paused for a few minutes', $paused['message'] );
        $this->assertNotFalse( get_transient( RenderCheck::PAUSE_TRANSIENT ) );

        // After the pause the next run leaves the item alone for a while (retry later) and goes on with the others.
        delete_transient( RenderCheck::PAUSE_TRANSIENT );
        $linker = $this->laterRequest();
        $next   = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 2, $next['applied'] );
        $this->assertSame( [ 'https://purepeptidescanada.test/article-511/', 'https://purepeptidescanada.test/article-512/' ], array_column( StubsSeo::$renderRequests, 'url' ) );
        $this->assertSame( self::ARTICLE, StubsSeo::$posts[511]['post_content'] );

        // Once the retry time is over it is checked again.
        $all                   = get_option( RenderCheck::OPTION );
        $all[511]['expires']   = time() - 1;
        update_option( RenderCheck::OPTION, $all );
        $linker = $this->laterRequest();
        $later  = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 2, $later['applied'], $later['message'] );
        $this->assertStringContainsString( 'bacteriostatic water</a>', StubsSeo::$posts[511]['post_content'] );
        $this->assertSame( '', (string) ( $this->bySource( $linker, 511, 'applied' )[0]['render_note'] ?? '' ) );
    }

    public function testATimeoutAndAPageThatDoesNotNameTheItemAreUnknown(): void {
        $this->article( 521 );
        $this->article( 522 );
        $this->article( 523 );
        StubsSeo::$renderStatus[521] = 404;
        // A login wall / maintenance page that answers 200 but is not this item.
        StubsSeo::$render[522] = static fn( int $id ): string => '<html><head><title>Sign in</title></head><body class="login"><form><p>Our laboratory team reconstitutes every research vial with bacteriostatic water before the stability tests begin.</p></form></body></html>';
        StubsSeo::$renderStatus[523] = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 8001 milliseconds' );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 0, $res['applied'], $res['message'] );
        $this->assertCount( 3, StubsSeo::$renderRequests, 'a 404 or a wrong page does not stop the run; the timeout does' );
        $this->assertSame( 'timeout', $this->render()->stopped() );
        $this->assertSame( [ 'http', 'identity', 'timeout' ], [ $this->render()->cached( 521 )['code'], $this->render()->cached( 522 )['code'], $this->render()->cached( 523 )['code'] ] );
        foreach ( [ 521, 522, 523 ] as $id ) {
            $this->assertSame( RenderCheck::UNKNOWN, $this->render()->cached( $id )['verdict'] );
            $this->assertGreaterThan( time() + RenderCheck::UNKNOWN_RETRY - 60, $this->render()->cached( $id )['expires'] );
            $this->assertLessThan( time() + RenderCheck::UNKNOWN_RETRY + 60, $this->render()->cached( $id )['expires'], 'retried after hours, not a week' );
        }
        $this->assertSame( 6, $linker->counts()['pending'] );
        $this->assertSame( [], StubsSeo::$updates );
    }

    public function testASlowAnswerStopsFurtherRequestsInTheRun(): void {
        $this->article( 531 );
        $this->article( 532 );
        $t      = 1000.0;
        $render = new RenderCheck( $this->settings(), Container::get( Logger::class ), Container::get( Client::class ), static fn( float $s ) => null, static function () use ( &$t ): float {
            $t += 6.0; // every request seems to take 6 s
            return $t;
        } );
        $linker = $this->linker( $render );
        $linker->run( TimeBudget::seconds( 30 ) );
        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 'slow', $render->stopped() );
        $this->assertSame( 1, $res['fetches'] );
        $this->assertSame( 2, $res['applied'], 'the slow answer itself still counts' );
        $this->assertCount( 1, StubsSeo::$renderRequests );
        $this->assertFalse( $res['more'] );
    }

    public function testAtMostAFewRequestsPerRunAndTheJobContinuesLater(): void {
        foreach ( [ 541, 542, 543, 544 ] as $id ) {
            $this->article( $id, 'post', '<p>Reconstitute every research vial number ' . $id . ' with bacteriostatic water before the stability tests begin.</p>' );
        }
        $this->linker();
        $job = ( new InternalLinksJob() )->handle( [], TimeBudget::seconds( 30 ), Container::plugin()->container() );
        $this->assertSame( RenderCheck::MAX_FETCHES, $job['page_checks'] );
        $this->assertSame( 3, $job['applied'], (string) $job['summary'] );
        $this->assertTrue( $job['continued'], 'links are left for today: another round later' );
        $queued = Container::get( Jobs::class )->findBy( [ 'type' => 'internal_links', 'status' => Jobs::STATUS_QUEUED ] );
        $this->assertCount( 1, $queued );
        $payload = is_array( $queued[0]['payload'] ) ? $queued[0]['payload'] : json_decode( (string) $queued[0]['payload'], true );
        $this->assertTrue( $payload['auto_only'] );
        $this->assertGreaterThanOrEqual( time() + InternalLinksJob::AUTO_CONTINUE_SECONDS - 60, strtotime( (string) $queued[0]['scheduled_at'] . ' UTC' ), 'after a pause' );

        // The continuation (15 minutes later, another PHP request) only applies (the list is fresh, so it is not
        // regenerated).
        $this->laterRequest();
        $next = ( new InternalLinksJob() )->handle( $payload, TimeBudget::seconds( 30 ), Container::plugin()->container() );
        $this->assertSame( 0, $next['checked'] );
        $this->assertSame( 1, $next['applied'], (string) $next['summary'] );
        $this->assertSame( 4, $next['auto_today'] );
    }

    // ------------------------------------------------------------------ cache

    public function testTheVerdictIsCheckedAgainWhenTheItemTemplateOrFrontPageChange(): void {
        $this->article( 551 );
        $render = $this->render();
        $render->beginRun();
        $this->assertSame( RenderCheck::SHOWN, $render->check( 551, [], TimeBudget::seconds( 30 ) )['verdict'] );
        $this->assertNotNull( $render->cached( 551 ) );

        StubsSeo::$posts[551]['post_modified_gmt'] = '2026-11-16 12:00:00'; // edited in WordPress
        $this->assertNull( $render->cached( 551 ), 'a new modification time invalidates it' );
        $render->rekey( 551 );
        $this->assertNotNull( $render->cached( 551 ), 'after the plugin’s own edit the verdict is carried over' );

        StubsSeo::setMeta( 551, '_wp_page_template', 'template-faq.php' );
        $this->assertNull( $render->cached( 551 ), 'another page template' );
        StubsSeo::setMeta( 551, '_wp_page_template', '' );
        $this->assertNotNull( $render->cached( 551 ) );

        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', 999 );
        $this->assertNotNull( $render->cached( 551 ), 'another page becoming the front page does not concern this one' );
        update_option( 'page_on_front', 551 );
        $this->assertNull( $render->cached( 551 ), 'this page becoming the front page does' );
        update_option( 'page_on_front', 0 );
        $this->assertNotNull( $render->cached( 551 ) );
        StubsSeoReview::$wcPages = [ 'shop' => 551 ];
        $this->assertNull( $render->cached( 551 ), 'nor does it as the WooCommerce shop' );
        StubsSeoReview::$wcPages = [];

        $all                 = get_option( RenderCheck::OPTION );
        $all[551]['expires'] = time() - 1;
        update_option( RenderCheck::OPTION, $all );
        $this->assertNull( $render->cached( 551 ), 'and after a week' );
        $this->assertSame( RenderCheck::VERDICT_TTL, 7 * 86400 );
    }

    // ------------------------------------------------------------------ manual

    public function testAPersonCanStillApplyButIsToldVisitorsWillNotSeeTheLink(): void {
        $this->article( 134 );
        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', 134 );
        StubsSeo::$render[134] = 'hidden';
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $linker->autoApply( TimeBudget::seconds( 30 ) );
        $set = $this->bySource( $linker, 134, InternalLinker::STATUS_NOT_SHOWN );
        $this->assertCount( 2, $set );

        $this->asAdmin();
        $res = $linker->apply( [ (int) $set[0]['id'] ], 1, TimeBudget::seconds( 30 ) );
        $this->assertSame( 1, $res['applied'] );
        $this->assertTrue( $res['results'][0]['ok'] );
        $this->assertStringContainsString( 'Note: this page doesn’t display its content', $res['results'][0]['message'] );
        $this->assertStringContainsString( 'visitors and Google won’t see this link', (string) $res['results'][0]['warning'] );
        $this->assertStringContainsString( '</a>', StubsSeo::$posts[134]['post_content'] );
        // A link a person added is never taken out by the automation.
        $GLOBALS['ppclg_test_caps']    = [];
        $GLOBALS['ppclg_test_user_id'] = 0;
        $this->assertSame( 0, $linker->autoApply( TimeBudget::seconds( 30 ) )['removed'] );
        $this->assertStringContainsString( '</a>', StubsSeo::$posts[134]['post_content'] );

        // Dismissing a set-aside suggestion works as for a pending one.
        $this->asAdmin();
        $this->assertSame( 1, $linker->dismiss( [ (int) $set[1]['id'] ], 1 )['dismissed'] );
    }

    // ------------------------------------------------------------------ the comparison itself

    public function testVisibleWordsComeFromTheBodyOnly(): void {
        $html = '<html><head><title>Bacteriostatic water guide</title><meta name="description" content="hidden words here"><script>var x = "inside script";</script></head>'
            . '<body class="page page-id-7"><!-- a comment --><style>.a{color:red}</style><nav>Home</nav>'
            . '<p>Use bacterio<b>static</b> water&nbsp;&amp; log it.</p><svg viewBox="0 0 10 10"><text>Diagram label</text></svg>'
            . '<script type="application/ld+json">{"name":"FAQ answer text"}</script><p>Don&#8217;t skip it &#8211; really.</p></body></html>';
        $words = (string) RenderCheck::visibleWords( $html );
        $this->assertStringContainsString( ' use bacteriostatic water log it ', $words, 'inline tags do not break words; entities decoded' );
        $this->assertStringContainsString( ' don t skip it really ', $words );
        foreach ( [ 'guide', 'hidden', 'inside', 'comment', 'color', 'diagram', 'faq' ] as $absent ) {
            $this->assertStringNotContainsString( ' ' . $absent . ' ', $words, $absent );
        }
        $this->assertNull( RenderCheck::visibleWords( '<html><head></head></html>' ) );
        // The item's own straight quotes and the page's curly ones compare equal.
        $this->assertSame( RenderCheck::words( "Don't skip it -- really" ), RenderCheck::words( "Don\u{2019}t skip it \u{2013} really" ) );
        $this->assertSame( [ '3x3', 'grid' ], RenderCheck::words( "3\u{00D7}3 grid" ) );
    }

    public function testThePageMustNameTheItem(): void {
        $url = 'https://purepeptidescanada.test/about/';
        $this->assertTrue( RenderCheck::identifies( '<body class="home page-template-default page page-id-134">', 134, $url ) );
        $this->assertTrue( RenderCheck::identifies( '<body class="single postid-9731 x">', 9731, $url ) );
        $this->assertFalse( RenderCheck::identifies( '<body class="page page-id-1345">', 134, $url ), 'not a prefix match' );
        $this->assertTrue( RenderCheck::identifies( "<head><link rel='shortlink' href='https://purepeptidescanada.test/?p=134' /></head><body>", 134, $url ) );
        $this->assertTrue( RenderCheck::identifies( '<head><link rel="canonical" href="https://purepeptidescanada.test/about" /></head><body class="x">', 135, $url ) );
        $this->assertFalse( RenderCheck::identifies( '<head><link rel="canonical" href="https://purepeptidescanada.test/wp-login.php" /></head><body class="login">', 135, $url ) );
    }

    public function testEvaluateNeedsEvidenceBeforeSayingHidden(): void {
        $page = static fn( string $main ): string => '<html><body class="page page-id-9"><nav><a href="/">Bacteriostatic Water</a></nav><main>' . $main . '</main></body></html>';
        $shown = RenderCheck::evaluate( $page( self::ARTICLE ), 9, '', self::ARTICLE, [ 1 => 'bacteriostatic water' ] );
        $this->assertSame( [ RenderCheck::SHOWN, 3, 4 ], [ $shown['verdict'], $shown['samples'], $shown['matched'] ] );
        $this->assertSame( RenderCheck::WORDS_FOUND, $shown['found'][1] );

        $hidden = RenderCheck::evaluate( $page( '<p>Hand-written copy.</p>' ), 9, '', self::ARTICLE, [ 1 => 'bacteriostatic water' ] );
        $this->assertSame( [ RenderCheck::HIDDEN, 'template' ], [ $hidden['verdict'], $hidden['code'] ] );
        $this->assertSame( RenderCheck::WORDS_MISSING, $hidden['found'][1], 'the anchor alone (in the menu) never counts' );

        // One short sentence cannot prove that a page hides its content.
        $tiny = RenderCheck::evaluate( $page( '<p>Other.</p>' ), 9, '', '<p>Keep bacteriostatic water cold.</p>', [ 1 => 'bacteriostatic water' ] );
        $this->assertSame( [ RenderCheck::UNKNOWN, 'too_little_text' ], [ $tiny['verdict'], $tiny['code'] ] );
        // An anchor with no word around it is left for a person.
        $bare = RenderCheck::evaluate( $page( '<p>Bacteriostatic water</p>' ), 9, '', '<p>Bacteriostatic water</p>', [ 1 => 'bacteriostatic water' ] );
        $this->assertSame( RenderCheck::WORDS_SHORT, $bare['found'][1] );
    }
}
