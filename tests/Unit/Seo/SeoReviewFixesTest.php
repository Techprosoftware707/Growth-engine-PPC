<?php
/**
 * Review fixes for the on-site SEO helpers: a restore-point revision before a link is applied, HTML5 tag closing
 * and opaque blocks in the link tokenizer, WooCommerce's noindex pages, accurate orphan wording, boundary-aware
 * system paths and the HTTP spot check waiting for a fresh budget.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Seo;

require_once dirname( __DIR__, 2 ) . '/Support/StubsSeo.php';

use PPC\LinkGrowth\Automation\Audit;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Seo\IndexNow;
use PPC\LinkGrowth\Seo\InternalLinker;
use PPC\LinkGrowth\Seo\SiteAuditor;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Locks;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\StubsSeo;
use PPC\LinkGrowth\Tests\Support\StubsSeoReview;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class SeoReviewFixesTest extends TestCase {

    private const WATER = 'Bacteriostatic Water';

    private mixed $previousCaps = null;

    protected function setUp(): void {
        parent::setUp();
        StubsSeo::reset();
        $this->previousCaps            = $GLOBALS['ppclg_test_caps'] ?? null;
        $GLOBALS['ppclg_test_caps']    = [ 'manage_options' => true, 'edit_post' => true, 'unfiltered_html' => true ];
        $GLOBALS['ppclg_test_user_id'] = 1;
        Container::get( Settings::class )->set( 'seo.audit_http_sample', false );
    }

    protected function tearDown(): void {
        if ( null === $this->previousCaps ) {
            unset( $GLOBALS['ppclg_test_caps'] );
        } else {
            $GLOBALS['ppclg_test_caps'] = $this->previousCaps;
        }
        $GLOBALS['ppclg_test_user_id'] = 0;
        StubsSeo::reset();
        parent::tearDown();
    }

    private function linker(): InternalLinker {
        return new InternalLinker( Container::get( Settings::class ), Container::get( Logger::class ), Container::get( Locks::class ), Container::get( Audit::class ) );
    }

    private function auditor( bool $withClient = false ): SiteAuditor {
        return new SiteAuditor( Container::get( Settings::class ), Container::get( Logger::class ), Container::get( Locks::class ), $withClient ? Container::get( Client::class ) : null );
    }

    /** @return array<int,array<string,mixed>> pending suggestions of one source */
    private function suggestionsFor( InternalLinker $linker, int $source ): array {
        return array_values( array_filter( $linker->suggestions(), static fn( array $s ): bool => $source === (int) $s['source_post_id'] ) );
    }

    private function seedWater(): void {
        StubsSeo::addPost( 100, [ 'post_type' => 'product', 'post_title' => self::WATER ] );
    }

    // ------------------------------------------------------------------ seo-quality-1: restore point

    public function testApplyKeepsTheContentBeforeTheEditAsARevision(): void {
        $this->seedWater();
        $original = '<p>Rinse with bacteriostatic water before use.</p>';
        // Created without any revision (as through REST, WP-CLI or an importer).
        StubsSeo::addPost( 300, [ 'post_title' => 'Lab Notes', 'post_content' => $original ] );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $s = $this->suggestionsFor( $linker, 300 );
        $this->assertCount( 1, $s );

        $res = $linker->apply( [ $s[0]['id'] ], 1 );
        $this->assertTrue( $res['ok'], $res['message'] );
        $this->assertStringContainsString( 'previous version', $res['message'] );

        $item = $linker->suggestions( 'applied' )[0];
        $this->assertGreaterThan( 0, $item['restore_revision_id'] );
        $this->assertSame( $original, StubsSeoReview::$contents[ $item['restore_revision_id'] ] ?? null, 'the revision to restore holds the content before the edit' );
        $this->assertNotSame( $item['restore_revision_id'], $item['revision_id'], 'the edited content is a separate revision' );
        $this->assertCount( 2, StubsSeo::$revisions[300] );
        $audit = Container::get( AuditLog::class )->findBy( [ 'action' => 'seo.internal_link_applied' ] );
        $this->assertSame( $item['restore_revision_id'], (int) $audit[0]['details']['restore_revision_id'] );
    }

    public function testAnExistingRevisionOfTheCurrentContentIsTheRestorePoint(): void {
        $this->seedWater();
        StubsSeo::addPost( 301, [ 'post_title' => 'Prep', 'post_content' => '<p>Keep bacteriostatic water cold.</p>' ] );
        StubsSeo::$revisions[301] = [];
        $existing                 = StubsSeo::addRevision( 301 );
        StubsSeoReview::$contents[ $existing ] = '<p>Keep bacteriostatic water cold.</p>';
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $res = $linker->apply( [ $this->suggestionsFor( $linker, 301 )[0]['id'] ], 1 );
        $this->assertTrue( $res['ok'], $res['message'] );
        $this->assertSame( $existing, $linker->suggestions( 'applied' )[0]['restore_revision_id'] );
    }

    public function testApplyIsRefusedWhenThePreviousVersionCouldNotBeRestored(): void {
        $this->seedWater();
        StubsSeo::addPost( 302, [ 'post_title' => 'Prep', 'post_content' => '<p>Keep bacteriostatic water cold.</p>' ] );
        StubsSeo::addPost( 303, [ 'post_title' => 'Prep 2', 'post_content' => '<p>Store bacteriostatic water upright.</p>' ] );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $a = $this->suggestionsFor( $linker, 302 )[0];
        $b = $this->suggestionsFor( $linker, 303 )[0];

        // A host that keeps a single revision (WP_POST_REVISIONS = 1) would drop the original on update.
        StubsSeoReview::$keep = 1;
        $res                  = $linker->apply( [ $a['id'] ], 1 );
        $this->assertFalse( $res['ok'] );
        $this->assertStringContainsString( 'fewer than 2 revisions', $res['results'][0]['message'] );
        $this->assertSame( [], StubsSeo::$updates );

        // No revision can be saved and none exists: nothing is edited.
        StubsSeoReview::$keep      = -1;
        StubsSeoReview::$saveFails = true;
        $res                       = $linker->apply( [ $b['id'] ], 1 );
        $this->assertFalse( $res['ok'] );
        $this->assertStringContainsString( 'could not keep a revision', $res['results'][0]['message'] );
        $this->assertSame( [], StubsSeo::$updates );
        $this->assertSame( '<p>Store bacteriostatic water upright.</p>', StubsSeo::$posts[303]['post_content'] );

        // Sources on such a host are not suggested in the first place.
        StubsSeoReview::$saveFails = false;
        StubsSeoReview::$keep      = 0;
        StubsSeo::addPost( 304, [ 'post_title' => 'Prep 3', 'post_content' => '<p>Bacteriostatic water again.</p>' ] );
        $linker->run( TimeBudget::seconds( 30 ), [ 'restart' => true ] );
        $this->assertSame( [], $this->suggestionsFor( $linker, 304 ) );
    }

    // ------------------------------------------------------------------ seo-quality-2: HTML5 tag closing

    public function testATrailingSlashDoesNotCloseALinkOrAHeading(): void {
        $cases = [
            'unquoted href ending in /' => '<!-- wp:html --><p><a href=https://purepeptidescanada.test/shop/>Shop Bacteriostatic Water now</a></p><!-- /wp:html -->',
            'self-closed a'             => '<p><a href="/shop/" />Bacteriostatic Water</a></p>',
            'self-closed h2'            => '<h2 class="x" />Bacteriostatic Water</h2>',
            'unquoted a href'           => '<p><a href=/shop/>our Bacteriostatic Water shop</a></p>',
        ];
        foreach ( $cases as $label => $html ) {
            $this->assertNull( InternalLinker::locate( InternalLinker::analyse( $html ), self::WATER ), $label );
            // Defence in depth: an insertion at that spot is refused even when offered directly.
            $at = (int) strpos( $html, self::WATER );
            $this->assertSame( [ 0 => false ], InternalLinker::validateInsertions( $html, [ [ 'start' => $at, 'end' => $at + strlen( self::WATER ), 'text' => self::WATER ] ] ), $label );
        }
        // Real self-closing SVG is still closed, and text after it can be linked.
        $svg = '<p>Icon <svg viewBox="0 0 1 1"/> then Bacteriostatic Water.</p>';
        $this->assertNotNull( InternalLinker::locate( InternalLinker::analyse( $svg ), self::WATER ) );
        // An unquoted value ending in "/" does not self-close an SVG either.
        $this->assertNull( InternalLinker::locate( InternalLinker::analyse( '<p><svg id=a/>Bacteriostatic Water</p>' ), self::WATER ) );

        $this->seedWater();
        foreach ( array_values( $cases ) as $n => $html ) {
            StubsSeo::addPost( 310 + $n, [ 'post_title' => 'Raw ' . $n, 'post_content' => $html ] );
        }
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        foreach ( array_keys( array_values( $cases ) ) as $n ) {
            $this->assertSame( [], $this->suggestionsFor( $linker, 310 + $n ), 'no suggestion inside an open link or heading' );
        }
    }

    // ------------------------------------------------------------------ seo-quality-3: opaque blocks

    public function testTextInBlocksThatRenderFromTheirAttributesIsNotLinked(): void {
        $faq = '<!-- wp:rank-math/faq-block {"questions":[{"id":"q1","title":"What is it?","content":"Bacteriostatic Water is sterile water with a preservative.","visible":true}]} -->'
            . '<div class="wp-block-rank-math-faq-block"><div class="rank-math-faq-item"><h3 class="rank-math-question">What is it?</h3><div class="rank-math-answer">Bacteriostatic Water is sterile water with a preservative.</div></div></div>'
            . '<!-- /wp:rank-math/faq-block -->';
        $this->assertNull( InternalLinker::locate( InternalLinker::analyse( $faq ), self::WATER ), 'Rank Math FAQ (fallback list, registry not needed)' );

        // A third-party block registered with a render callback.
        StubsSeoReview::$dynamicBlocks = [ 'acme/callout' ];
        $dynamic = '<!-- wp:acme/callout {"tone":"info"} --><div class="callout"><p>Use Bacteriostatic Water.</p></div><!-- /wp:acme/callout -->';
        $this->assertNull( InternalLinker::locate( InternalLinker::analyse( $dynamic ), self::WATER ) );
        // The same markup when the block is not dynamic (its saved HTML is what visitors see).
        StubsSeoReview::$dynamicBlocks = [];
        $this->assertNotNull( InternalLinker::locate( InternalLinker::analyse( $dynamic ), self::WATER ) );

        // Words that also sit in the block's attributes: the block may render from them.
        $attr = '<!-- wp:acme/note {"label":"About Bacteriostatic Water"} --><p>Bacteriostatic Water notes.</p><!-- /wp:acme/note -->';
        $this->assertNull( InternalLinker::locate( InternalLinker::analyse( $attr ), self::WATER ) );

        // Core blocks render their saved content (even those with a render callback, like core/list in WP 7).
        foreach ( [
            '<!-- wp:paragraph --><p>Use Bacteriostatic Water daily.</p><!-- /wp:paragraph -->',
            '<!-- wp:html --><p>Use Bacteriostatic Water daily.</p><!-- /wp:html -->',
            '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Bacteriostatic Water, 30 ml</li><!-- /wp:list-item --></ul><!-- /wp:list -->',
            '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:paragraph --><p>Bacteriostatic Water.</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
        ] as $html ) {
            $this->assertNotNull( InternalLinker::locate( InternalLinker::analyse( $html ), self::WATER ), $html );
        }
        // After a closed opaque block, later text is eligible again.
        $this->assertNotNull( InternalLinker::locate( InternalLinker::analyse( $faq . '<!-- wp:paragraph --><p>Order Bacteriostatic Water here.</p><!-- /wp:paragraph -->' ), self::WATER ) );

        $this->seedWater();
        StubsSeo::addPost( 320, [ 'post_title' => 'FAQ', 'post_content' => $faq ] );
        StubsSeo::addPost( 321, [ 'post_title' => 'Paragraph', 'post_content' => '<!-- wp:paragraph --><p>Use Bacteriostatic Water daily.</p><!-- /wp:paragraph -->' ] );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $this->assertSame( [], $this->suggestionsFor( $linker, 320 ), 'no suggestion inside a Rank Math FAQ block' );
        $this->assertCount( 1, $this->suggestionsFor( $linker, 321 ) );
    }

    // ------------------------------------------------------------------ seo-quality-4: WooCommerce noindex pages

    public function testWooCommerceCartCheckoutAndAccountPagesAreNoindex(): void {
        StubsSeo::addPost( 40, [ 'post_type' => 'page', 'post_title' => 'Cart' ] );
        StubsSeo::addPost( 41, [ 'post_type' => 'page', 'post_title' => 'Checkout' ] );
        StubsSeo::addPost( 42, [ 'post_type' => 'page', 'post_title' => 'My account' ] );
        StubsSeo::addPost( 43, [ 'post_type' => 'page', 'post_title' => 'About Our Laboratory Reagent Supply Business' ] );
        StubsSeoReview::$wcPages = [ 'cart' => 40, 'checkout' => 41, 'myaccount' => 42, 'shop' => 44 ];

        $this->assertTrue( SiteAuditor::isNoindex( 40, 'page' ) );
        $this->assertTrue( SiteAuditor::isNoindex( 42, 'page' ) );
        $this->assertFalse( SiteAuditor::isNoindex( 43, 'page' ) );

        // IndexNow never submits them.
        $sent                  = [];
        HttpFixtures::$handler = static function ( string $url, array $args ) use ( &$sent ) {
            if ( IndexNow::ENDPOINT !== $url ) {
                return null;
            }
            $sent[] = json_decode( (string) ( $args['body'] ?? '' ), true );
            return [ 'response' => [ 'code' => 200, 'message' => '' ], 'headers' => [], 'body' => '', 'cookies' => [] ];
        };
        $in = new IndexNow( Container::get( Settings::class ), Container::get( Logger::class ), Container::get( Client::class ), Container::get( Locks::class ), Container::get( Audit::class ), static function (): void {} );
        $in->run( TimeBudget::seconds( 20 ), [ 'full' => true ] );
        $this->assertCount( 1, $sent );
        $urls = $sent[0]['urlList'];
        foreach ( [ 40, 41, 42 ] as $id ) {
            $this->assertNotContains( StubsSeo::permalink( $id ), $urls );
        }
        $this->assertContains( StubsSeo::permalink( 43 ), $urls );

        // The audit does not grade them (no title / description advice for noindex pages).
        $auditor = $this->auditor();
        $auditor->run( TimeBudget::seconds( 30 ) );
        foreach ( $auditor->result()['issues'] as $issue ) {
            $this->assertNotContains( (int) $issue['post_id'], [ 40, 41, 42 ], (string) $issue['code'] );
        }
    }

    // ------------------------------------------------------------------ seo-quality-6: orphans

    public function testBlogPostsAreNotCalledUnreachableAndBlockNavigationCounts(): void {
        $long = '<p>' . str_repeat( 'Our laboratory reagent catalogue lists every compound with its certificate of analysis. ', 40 ) . '</p>';
        StubsSeo::addPost( 50, [ 'post_type' => 'page', 'post_title' => 'Shipping Times Across Canada and Abroad', 'post_content' => $long ] );
        StubsSeo::addPost( 51, [ 'post_type' => 'page', 'post_title' => 'About the Laboratory Reagent Supplier', 'post_content' => $long ] );
        StubsSeo::addPost( 52, [ 'post_type' => 'post', 'post_title' => 'Notes on Storing Lyophilized Reagents', 'post_content' => $long ] );
        // A block theme links page 51 from its header navigation (a wp_navigation post, not a classic menu).
        StubsSeoReview::$blockTheme = true;
        StubsSeo::addPost( 60, [ 'post_type' => 'wp_navigation', 'post_title' => 'Header', 'post_content' => '<!-- wp:navigation-link {"label":"About","type":"page","id":51,"url":"https://purepeptidescanada.test/about/","kind":"post-type"} /-->' ] );

        $auditor = $this->auditor();
        $auditor->run( TimeBudget::seconds( 30 ) );
        $byId = [];
        foreach ( $auditor->result()['issues'] as $i ) {
            $byId[ (int) $i['post_id'] ][ (string) $i['code'] ] = $i;
        }
        $this->assertArrayHasKey( 'orphan', $byId[50] ?? [], 'a page nothing links to is an orphan' );
        $this->assertSame( 'medium', $byId[50]['orphan']['severity'] );
        $this->assertArrayNotHasKey( 'orphan', $byId[51] ?? [], 'linked from the block navigation' );
        $this->assertArrayNotHasKey( 'orphan', $byId[52] ?? [], 'posts are reachable through the blog listing and archives' );
        $this->assertSame( 'low', $byId[52]['no_content_links']['severity'] ?? null );
        $this->assertStringContainsString( 'blog listing and archives', (string) $byId[52]['no_content_links']['message'] );
        $this->assertStringNotContainsString( 'no path', (string) $byId[52]['no_content_links']['message'] );
    }

    public function testNavigationLinkIdsReadsPostTypeIdsAndUrls(): void {
        StubsSeo::addPost( 70, [ 'post_type' => 'page', 'post_title' => 'Contact', 'post_name' => 'contact' ] );
        $nav = '<!-- wp:navigation-link {"id":9,"kind":"taxonomy","url":"https://purepeptidescanada.test/category/news/"} /-->'
            . '<!-- wp:navigation-submenu {"label":"More","id":12,"kind":"post-type"} --><!-- wp:navigation-link {"url":"https://purepeptidescanada.test/contact/","kind":"custom"} /--><!-- /wp:navigation-submenu -->';
        $this->assertSame( [ 12, 70 ], SiteAuditor::navigationLinkIds( $nav ) );
    }

    // ------------------------------------------------------------------ seo-quality-7: system paths

    public function testSystemPathsMatchOnlyAtAPathBoundary(): void {
        foreach ( [ '/cart', '/cart/', '/cart/x', '/checkout/order-received/5/', '/feed/', '/sitemap', '/sitemap/', '/wp-admin/', '/wp-login.php', '/robots.txt', '/wp-content/uploads/a.pdf' ] as $path ) {
            $this->assertTrue( SiteAuditor::isSystemPath( $path ), $path );
        }
        foreach ( [ '/feedback/', '/cartridge-filters/', '/sitemap-guide/', '/cart-old/', '/my-accounts/', '/wp-login.php5', '/robots.txt-old' ] as $path ) {
            $this->assertFalse( SiteAuditor::isSystemPath( $path ), $path );
        }

        $long = '<p>' . str_repeat( 'Our laboratory reagent catalogue lists every compound with its certificate of analysis. ', 40 ) . '</p>';
        StubsSeo::addPost( 10, [ 'post_type' => 'page', 'post_title' => 'Research Reagents Supplier in Canada Home', 'post_content' => $long . '<p><a href="/feedback/">feedback</a> <a href="/cartridge-filters/">filters</a> <a href="/sitemap-guide/">guide</a> <a href="/cart-old/">old cart</a> <a href="/cart/">cart</a> <a href="/sitemap_index.xml">xml</a></p>' ] );
        StubsSeo::addPost( 11, [ 'post_type' => 'page', 'post_title' => 'Customer Feedback on Our Reagent Orders', 'post_name' => 'feedback', 'post_content' => $long ] );
        StubsSeo::addPost( 12, [ 'post_type' => 'page', 'post_title' => 'Cartridge Filters for Laboratory Water', 'post_name' => 'cartridge-filters', 'post_content' => $long ] );
        StubsSeo::addPost( 13, [ 'post_type' => 'page', 'post_title' => 'Sitemap Guide to the Reagent Catalogue', 'post_name' => 'sitemap-guide', 'post_content' => $long ] );
        update_option( 'page_on_front', 10 );

        $auditor = $this->auditor();
        $auditor->run( TimeBudget::seconds( 30 ) );
        $issues = $auditor->result()['issues'];
        $orphans = array_map( 'intval', array_column( array_filter( $issues, static fn( array $i ): bool => 'orphan' === $i['code'] ), 'post_id' ) );
        $this->assertSame( [], array_values( array_intersect( $orphans, [ 11, 12, 13 ] ) ), 'pages linked as /feedback/, /cartridge-filters/ and /sitemap-guide/ are not orphans' );
        $broken = array_values( array_filter( $issues, static fn( array $i ): bool => 'broken_internal_link' === $i['code'] ) );
        $this->assertCount( 1, $broken, 'only /cart-old/ is broken; /cart/ and the sitemap file are system addresses' );
        $this->assertStringContainsString( '/cart-old/', $broken[0]['message'] );
    }

    // ------------------------------------------------------------------ correctness-7: spot-check deferral

    public function testSpotCheckDeferralsCountOncePerTick(): void {
        Container::get( Settings::class )->set( 'seo.audit_http_sample', true );
        StubsSeo::addPost( 80, [ 'post_type' => 'page', 'post_title' => 'Short' ] );
        $auditor = $this->auditor( true );

        // Several short runs in the same tick: the spot check keeps waiting instead of running out of time.
        foreach ( [ 1, 2, 3 ] as $n ) {
            $res = $auditor->run( TimeBudget::seconds( 4.0 ) );
            $this->assertTrue( $res['more'], "run $n" );
            $this->assertTrue( $res['sample_deferred'], "run $n" );
        }
        $work = get_option( SiteAuditor::OPTION_RUN );
        $this->assertSame( 1, (int) $work['sample_deferrals'], 'deferrals in one tick count once' );
        $this->assertSame( [], HttpFixtures::$requests );

        // A later tick counts again; after two counted deferrals the check runs (with whatever time there is).
        $work['sample_deferred_at'] = time() - 120;
        update_option( SiteAuditor::OPTION_RUN, $work, false );
        $this->assertTrue( $auditor->run( TimeBudget::seconds( 4.0 ) )['sample_deferred'] );
        $this->assertSame( 2, (int) get_option( SiteAuditor::OPTION_RUN )['sample_deferrals'] );
        $done = $auditor->run( TimeBudget::seconds( 4.0 ) );
        $this->assertTrue( $done['done'] );
    }
}
