<?php
/**
 * 1.2.0 Build B — internal links on Autopilot:
 * - with Autopilot (or seo.auto_apply_links) on, the internal_links job applies the most relevant pending
 *   suggestions by itself, up to seo.auto_apply_per_day a day and seo.max_links_per_post per item, with a revision
 *   before each change, audit-logged as the automation; kses refusals are left for a person and never retried;
 * - POST /seo/links/undo-auto {confirm:true} removes every automatic link still present: an item not edited since
 *   gets exactly its previous content back, an edited one loses only the inserted anchor; undone links are not added
 *   again;
 * - off, nothing is applied without a click (as before) and the stage stays weekly.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Seo;

require_once dirname( __DIR__, 2 ) . '/Support/StubsSeo.php';

use LinkGrowth\Autopilot\Admin\Rest\SeoController;
use LinkGrowth\Autopilot\Automation\Audit;
use LinkGrowth\Autopilot\Automation\Autopilot;
use LinkGrowth\Autopilot\Automation\Jobs\InternalLinksJob;
use LinkGrowth\Autopilot\Automation\Pipeline;
use LinkGrowth\Autopilot\Repositories\AuditLog;
use LinkGrowth\Autopilot\Seo\InternalLinker;
use LinkGrowth\Autopilot\Support\Limits;
use LinkGrowth\Autopilot\Support\Locks;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\TimeBudget;
use LinkGrowth\Autopilot\Tests\Support\Container;
use LinkGrowth\Autopilot\Tests\Support\StubsSeo;
use LinkGrowth\Autopilot\Tests\Support\StubsSeoReview;
use LinkGrowth\Autopilot\Tests\Support\TestCase;
use WP_Error;
use WP_REST_Request;

final class AutopilotLinksTest extends TestCase {

    private const WATER_URL   = 'https://northwind.test/product/corrugated-boxes/';
    private const STORAGE_URL = 'https://northwind.test/storage-guide/';

    protected function setUp(): void {
        parent::setUp();
        StubsSeo::reset();
        // The background job runs without a logged-in user (WP-Cron): no capabilities at all.
        $GLOBALS['lga_test_caps']    = [];
        $GLOBALS['lga_test_user_id'] = 0;
        unset( $GLOBALS['lga_seo_update_fails'] );
        StubsSeo::addPost( 100, [ 'post_type' => 'product', 'post_title' => 'Corrugated Boxes' ] );
        StubsSeo::addPost( 102, [ 'post_type' => 'page', 'post_title' => 'How We Store Reagents', 'post_name' => 'storage-guide', 'post_content' => '<p>Notes on cold rooms.</p>' ], [ 'rank_math_focus_keyword' => 'parts storage' ] );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['lga_test_caps'] );
        $GLOBALS['lga_test_user_id'] = 0;
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

    /** @return array<string,mixed> */
    private function runJob(): array {
        return ( new InternalLinksJob() )->handle( [], TimeBudget::seconds( 30 ), Container::plugin()->container() );
    }

    /** @return array<int,array<string,mixed>> */
    private function bySource( InternalLinker $linker, int $source, string $status = '' ): array {
        return array_values( array_filter( $linker->suggestions( $status ), static fn( array $s ): bool => $source === (int) $s['source_post_id'] ) );
    }

    private function asAdmin(): void {
        $GLOBALS['lga_test_caps']    = [ 'manage_options' => true, 'edit_post' => true, 'unfiltered_html' => true ];
        $GLOBALS['lga_test_user_id'] = 1;
    }

    private function request( array $params ): WP_REST_Request {
        $req = new WP_REST_Request( 'POST', '/lga/v1/seo/links/undo-auto' );
        foreach ( $params as $k => $v ) {
            $req->set_param( $k, $v );
        }
        return $req;
    }

    public function testAutopilotAppliesTheMostRelevantSuggestionsFirstUpToTheDailyCap(): void {
        $this->settings()->update( [ 'automation' => [ 'autopilot' => true ], 'seo' => [ 'auto_apply_per_day' => 2 ] ] );
        $c401 = '<p>Store it cold and dry. Later on, corrugated boxes.</p>';
        $c402 = '<p>Corrugated boxes first. Corrugated boxes again. Corrugated boxes thrice.</p>';
        $c403 = '<p>Read about parts storage here please.</p>';
        StubsSeo::addPost( 401, [ 'post_title' => 'Notes A', 'post_content' => $c401 ] );
        StubsSeo::addPost( 402, [ 'post_title' => 'Notes B', 'post_content' => $c402 ] );
        StubsSeo::addPost( 403, [ 'post_title' => 'Notes C', 'post_content' => $c403 ] );
        $linker = $this->linker();
        $this->assertTrue( $linker->autoApplyEnabled() );

        $job = $this->runJob();
        $this->assertSame( 2, $job['applied'], (string) $job['summary'] );
        $this->assertStringContainsString( 'Applied 2 internal links automatically (2 of 2 today)', $job['summary'] );
        $this->assertStringContainsString( 'waiting to be applied automatically', $job['summary'] );

        // Highest relevance first: B (three early mentions of a product) and A; C (a page, one mention) waits.
        $this->assertSame( 'applied', $this->bySource( $linker, 402, '' )[0]['status'] );
        $this->assertSame( 'applied', $this->bySource( $linker, 401, '' )[0]['status'] );
        $this->assertSame( 'pending', $this->bySource( $linker, 403, '' )[0]['status'] );
        $this->assertGreaterThan( $this->bySource( $linker, 403, '' )[0]['relevance'], $this->bySource( $linker, 401, '' )[0]['relevance'] );
        $this->assertSame( '<p><a href="' . self::WATER_URL . '">Corrugated boxes</a> first. Corrugated boxes again. Corrugated boxes thrice.</p>', StubsSeo::$posts[402]['post_content'] );
        $this->assertSame( $c403, StubsSeo::$posts[403]['post_content'] );

        // A revision of the content before the change was kept, and the change is audit-logged as Autopilot.
        $item = $this->bySource( $linker, 402, 'applied' )[0];
        $this->assertTrue( $item['auto'] );
        $this->assertSame( 0, $item['applied_by'] );
        $this->assertSame( $c402, StubsSeoReview::$contents[ $item['restore_revision_id'] ] ?? null );
        $audit = Container::get( AuditLog::class )->findBy( [ 'action' => 'seo.internal_link_applied' ] );
        $this->assertCount( 2, $audit );
        $this->assertSame( Autopilot::ACTOR, $audit[0]['actor'] );
        $this->assertTrue( $audit[0]['details']['automatic'] );
        $this->assertSame( 2, ( new Limits( $this->settings() ) )->used( InternalLinker::AUTO_COUNTER ) );

        // The cap holds for the rest of the day.
        $again = $this->runJob();
        $this->assertSame( 0, $again['applied'] );
        $this->assertStringContainsString( 'Today’s 2 automatic internal links are done', $again['summary'] );
        $this->assertSame( $c403, StubsSeo::$posts[403]['post_content'] );
        $info = $linker->autoInfo();
        $this->assertSame( [ true, true, 2, 2, 2 ], [ $info['enabled'], $info['autopilot'], $info['per_day'], $info['today'], $info['present'] ] );
    }

    public function testOffNothingIsAppliedAndTheSettingAloneTurnsItOnWithoutAutopilot(): void {
        StubsSeo::addPost( 404, [ 'post_title' => 'Prep', 'post_content' => '<p>Keep corrugated boxes cold.</p>' ] );
        $linker = $this->linker();
        $this->assertFalse( $linker->autoApplyEnabled() );
        $job = $this->runJob();
        $this->assertSame( 0, $job['applied'] );
        $this->assertSame( [], StubsSeo::$updates, 'Autopilot off: the job only suggests (as before).' );
        $this->assertFalse( $linker->autoApply()['ran'] );
        $this->assertStringStartsWith( 'weekly', Container::get( Pipeline::class )->status()['stages']['internal_links']['cadence'] );

        $this->settings()->set( 'seo.auto_apply_links', true );
        $this->assertStringStartsWith( 'daily (applies up to 20 links automatically)', Container::get( Pipeline::class )->status()['stages']['internal_links']['cadence'] );
        $job = $this->runJob();
        $this->assertSame( 1, $job['applied'] );
        $audit = Container::get( AuditLog::class )->findBy( [ 'action' => 'seo.internal_link_applied' ] );
        $this->assertSame( 'system', $audit[0]['actor'], 'without Autopilot the automation (not a person) is the actor' );

        // Turning internal-link suggestions off stops automatic links too.
        $this->settings()->set( 'seo.internal_links', false );
        $this->assertFalse( $linker->autoApplyEnabled() );
    }

    public function testKsesRefusalsAreLeftForAPersonAndNeverRetried(): void {
        $this->settings()->set( 'automation.autopilot', true );
        $content = '<p>Keep corrugated boxes cold.</p><iframe src="https://example.org/x"></iframe>';
        StubsSeo::addPost( 405, [ 'post_title' => 'Prep', 'post_content' => $content ] );
        $linker = $this->linker();
        $job    = $this->runJob();
        $this->assertSame( 0, $job['applied'] );
        $this->assertStringContainsString( '1 left for you to review', $job['summary'] );
        $item = $this->bySource( $linker, 405 )[0];
        $this->assertSame( 'pending', $item['status'] );
        $this->assertStringContainsString( 'WordPress would re-filter other markup', $item['auto_skip'] );
        $this->assertSame( $content, StubsSeo::$posts[405]['post_content'] );
        $retry = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 0, 0 ], [ $retry['applied'], $retry['refused'] ], 'not attempted again' );
        $this->assertSame( [], StubsSeo::$updates );
    }

    public function testARefusedSuggestionIsNotRetriedAfterTheListIsRegenerated(): void {
        $this->settings()->set( 'automation.autopilot', true );
        StubsSeo::addPost( 405, [ 'post_title' => 'Prep', 'post_content' => '<p>Keep corrugated boxes cold.</p><iframe src="https://example.org/x"></iframe>' ] );
        $linker = $this->linker();
        $this->assertStringContainsString( '1 left for you to review', $this->runJob()['summary'] );
        // The next daily run regenerates the suggestions first (a fresh pass), then applies.
        $next = $this->runJob();
        $this->assertStringNotContainsString( 'left for you to review', $next['summary'], 'not attempted again' );
        $this->assertNotEmpty( $this->bySource( $linker, 405 )[0]['auto_skip'] ?? '' );
    }

    public function testSuggestionsClosedAsNoLongerValidAreNotCountedAsLeftForYou(): void {
        StubsSeo::addPost( 409, [ 'post_title' => 'Notes', 'post_content' => '<p>Keep corrugated boxes cold.</p>' ] );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) ); // suggestions only (Autopilot off)
        $this->assertCount( 1, $this->bySource( $linker, 409, 'pending' ) );
        StubsSeo::$posts[409]['post_content'] = '<p>Changed: corrugated boxes must stay cold.</p>'; // edited since
        $this->settings()->set( 'automation.autopilot', true );
        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 0, 0, 1 ], [ $res['applied'], $res['refused'], $res['closed'] ] );
        $this->assertStringContainsString( '1 no longer valid (closed)', $res['message'] );
        $this->assertStringNotContainsString( 'left for you to review', $res['message'] );
    }

    public function testAnUndoneLinkIsNeverPrunedFromAFullStore(): void {
        $this->settings()->set( 'automation.autopilot', true );
        StubsSeo::addPost( 410, [ 'post_title' => 'Prep', 'post_content' => '<p>Keep corrugated boxes cold.</p>' ] );
        $linker = $this->linker();
        $this->assertSame( 1, $this->runJob()['applied'] );
        $this->asAdmin();
        $this->assertTrue( $linker->undoAuto( 1, TimeBudget::seconds( 30 ) )['ok'] );
        $GLOBALS['lga_test_caps'] = [];
        // The store is full (2000 items); another page now needs room for a new suggestion.
        $store = get_option( InternalLinker::OPTION );
        for ( $i = 0; count( $store['items'] ) < 2000; $i++ ) {
            $id                             = 50000 + $i;
            $store['items'][ (string) $id ] = [ 'id' => $id, 'source_post_id' => 90000 + $i, 'target_id' => 100, 'status' => 'applied', 'anchor' => 'x', 'relevance' => 1 ];
        }
        update_option( InternalLinker::OPTION, $store, false );
        StubsSeo::addPost( 411, [ 'post_title' => 'Other', 'post_content' => '<p>Read about parts storage here.</p>' ] );
        $linker->run( TimeBudget::seconds( 30 ), [ 'restart' => true ] );
        $linker->run( TimeBudget::seconds( 30 ), [ 'restart' => true ] );
        $undone = array_values( array_filter( $linker->suggestions( 'dismissed' ), static fn( array $s ): bool => 410 === (int) $s['source_post_id'] ) );
        $this->assertCount( 1, $undone, 'the undone link keeps its record' );
        $this->assertSame( [], $this->bySource( $linker, 410, 'pending' ) );
        $this->assertSame( 0, $linker->autoApply( TimeBudget::seconds( 30 ) )['applied'] );
        $this->assertStringNotContainsString( 'href', (string) StubsSeo::$posts[410]['post_content'] );
    }

    public function testThePerItemLimitHoldsForAutomaticLinks(): void {
        $this->settings()->set( 'automation.autopilot', true );
        StubsSeo::addPost( 406, [ 'post_title' => 'Prep', 'post_content' => '<p>Use corrugated boxes, then follow parts storage rules.</p>' ] );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $this->assertCount( 2, $this->bySource( $linker, 406 ) );
        $this->settings()->set( 'seo.max_links_per_post', 1 );
        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 1, $res['applied'] );
        $this->assertCount( 1, $this->bySource( $linker, 406, 'applied' ) );
        $this->assertCount( 1, $this->bySource( $linker, 406, 'pending' ) );
    }

    /**
     * Browser E2E finding: on a site with plain permalinks ("/?product=slug") every product address has the empty
     * path, so after the first automatic link in a post every other suggestion in it was closed as "already links to
     * this target" and Autopilot added one link per post instead of filling the daily cap.
     */
    public function testPlainPermalinkTargetsAreToldApart(): void {
        $this->settings()->update( [ 'automation' => [ 'autopilot' => true ], 'seo' => [ 'auto_apply_per_day' => 5, 'max_links_per_post' => 3 ] ] );
        StubsSeo::addPost( 110, [ 'post_type' => 'product', 'post_title' => 'Sterile Mixing Vials', 'url' => 'https://northwind.test/?product=sterile-mixing-vials' ] );
        StubsSeo::addPost( 111, [ 'post_type' => 'product', 'post_title' => 'Alcohol Prep Pads', 'url' => 'https://northwind.test/?product=alcohol-prep-pads' ] );
        StubsSeo::addPost( 112, [ 'post_type' => 'product', 'post_title' => 'Cold Storage Box', 'url' => 'https://northwind.test/?product=cold-storage-box' ] );
        // Post 408 already links to the home page: that is no link to any product either.
        $content = '<p>See <a href="https://northwind.test/">our shop</a>. Label the sterile mixing vials.</p><p>Wipe with alcohol prep pads.</p><p>Keep a cold storage box nearby.</p>';
        StubsSeo::addPost( 408, [ 'post_title' => 'Bench prep', 'post_content' => $content ] );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $this->assertCount( 3, $this->bySource( $linker, 408 ), 'a link to the home page hides no plain-permalink target' );

        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 3, $res['applied'], (string) $res['message'] );
        $this->assertCount( 3, $this->bySource( $linker, 408, 'applied' ) );
        $this->assertCount( 0, $this->bySource( $linker, 408, 'stale' ) );
        foreach ( [ 'sterile-mixing-vials', 'alcohol-prep-pads', 'cold-storage-box' ] as $slug ) {
            $this->assertStringContainsString( '?product=' . $slug, StubsSeo::$posts[408]['post_content'] );
        }

        // A target that really is linked already is still recognised (query order and entities do not matter).
        StubsSeo::addPost( 409, [ 'post_title' => 'Bench prep 2', 'post_content' => '<p><a href="https://northwind.test/?utm_source=x&amp;product=cold-storage-box">box</a>. Keep a cold storage box nearby.</p>' ] );
        $linker->run( TimeBudget::seconds( 30 ), [ 'restart' => true ] );
        $this->assertCount( 0, $this->bySource( $linker, 409 ) );
    }

    public function testUndoRestoresUneditedItemsExactlyAndUnlinksEditedOnes(): void {
        $this->settings()->set( 'automation.autopilot', true );
        // Tricky bytes (CRLF, single quotes, entities, UTF-8, an emoji) must come back byte for byte. (The kses stub
        // of the unit tests drops HTML comments, so block delimiters are covered by the integration test.)
        $c407 = "<div class='x'>\r\n<p>Use corrugated boxes, then follow parts storage rules. Caf\u{00E9} &amp; \u{1F9EA}</p>\r\n</div>";
        $c408 = '<p>Keep corrugated boxes cold.</p>';
        StubsSeo::addPost( 407, [ 'post_title' => 'Prep', 'post_content' => $c407 ] );
        StubsSeo::addPost( 408, [ 'post_title' => 'Prep 2', 'post_content' => $c408 ] );
        $linker = $this->linker();
        $this->assertSame( 3, $this->runJob()['applied'] );
        $this->assertStringContainsString( '<a href="' . self::STORAGE_URL . '">parts storage</a>', StubsSeo::$posts[407]['post_content'] );
        // Someone edits item 408 after the automatic link was added.
        StubsSeo::$posts[408]['post_content'] .= '<p>Added later by the editor.</p>';

        $this->asAdmin();
        $controller = new SeoController( Container::plugin()->container() );
        $list       = $controller->links( new WP_REST_Request( 'GET', '/lga/v1/seo/links' ) );
        $this->assertSame( 3, $list['auto']['present'] );
        $refused = $controller->linksUndoAuto( $this->request( [] ) );
        $this->assertInstanceOf( WP_Error::class, $refused );
        $this->assertSame( 400, $refused->get_error_data()['status'] );

        $res = $controller->linksUndoAuto( $this->request( [ 'confirm' => true ] ) );
        $this->assertIsArray( $res );
        $this->assertTrue( $res['ok'], $res['message'] );
        $this->assertSame( [ 2, 1, 0, 0 ], [ $res['restored'], $res['unlinked'], $res['already_gone'], $res['refused'] ] );
        $this->assertStringContainsString( 'Removed 3 automatic links', $res['message'] );
        $this->assertSame( $c407, StubsSeo::$posts[407]['post_content'], 'unedited item: exactly the content before the change' );
        $this->assertSame( $c408 . '<p>Added later by the editor.</p>', StubsSeo::$posts[408]['post_content'], 'edited item: only the inserted anchor is removed' );
        $this->assertSame( 0, $res['auto']['present'] );
        foreach ( $linker->suggestions( 'dismissed' ) as $s ) {
            $this->assertSame( InternalLinker::UNDONE_NOTE, $s['note'] );
        }
        $this->assertSame( 1, Container::get( AuditLog::class )->count( [ 'action' => 'seo.internal_links_auto_undone' ] ) );

        // Undone links are not suggested or added again.
        $GLOBALS['lga_test_caps'] = [];
        $linker->run( TimeBudget::seconds( 30 ), [ 'restart' => true ] );
        $this->assertSame( [], $this->bySource( $linker, 407, 'pending' ) );
        $this->assertSame( 0, $linker->autoApply( TimeBudget::seconds( 30 ) )['applied'] );
        $this->asAdmin();
        $none = $controller->linksUndoAuto( $this->request( [ 'confirm' => true ] ) );
        $this->assertSame( 'There are no automatic links to undo.', $none['message'] );
    }

    public function testUndoContentModes(): void {
        $before = '<p>Keep corrugated boxes cold.</p>';
        $open   = '<a href="' . self::WATER_URL . '">';
        $after  = '<p>Keep ' . $open . 'corrugated boxes</a> cold.</p>';
        $item   = [ 'link_open' => $open, 'link_text' => 'corrugated boxes', 'link_start' => 8, 'before_hash' => hash( 'sha256', $before ), 'after_hash' => hash( 'sha256', $after ), 'byte_identical' => true ];
        $this->assertSame( [ $before, 'restored' ], InternalLinker::undoContent( $after, $item ) );
        $this->assertSame( [ '<h2>New</h2>' . $before, 'unlinked' ], InternalLinker::undoContent( '<h2>New</h2>' . $after, $item ) );
        $this->assertSame( [ $before, 'gone' ], InternalLinker::undoContent( $before, $item ) );
    }

    public function testRoutesAndRelevance(): void {
        $GLOBALS['lga_test_routes'] = [];
        ( new SeoController( Container::plugin()->container() ) )->register( 'lga/v1' );
        $this->assertArrayHasKey( 'lga/v1/seo/links/undo-auto', $GLOBALS['lga_test_routes'] );
        $this->assertGreaterThan( InternalLinker::relevance( 'parts storage', 1, 0.9, 'page' ), InternalLinker::relevance( 'corrugated boxes', 3, 0.1, 'product' ) );
        $this->assertLessThanOrEqual( 100, InternalLinker::relevance( 'a b c d e f g h', 9, 0.0, 'product' ) );
    }
}
