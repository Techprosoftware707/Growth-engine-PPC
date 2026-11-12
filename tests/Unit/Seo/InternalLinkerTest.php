<?php
/**
 * Internal links: suggestion rules, byte-identical apply, refusal when the text changed, page builders skipped,
 * nothing applied without an explicit (confirmed) admin request.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Seo;

require_once dirname( __DIR__, 2 ) . '/Support/StubsSeo.php';

use PPC\LinkGrowth\Admin\Rest\SeoController;
use PPC\LinkGrowth\Automation\Audit;
use PPC\LinkGrowth\Automation\Jobs\InternalLinksJob;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Seo\InternalLinker;
use PPC\LinkGrowth\Support\Locks;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\StubsSeo;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_Error;
use WP_REST_Request;

final class InternalLinkerTest extends TestCase {

    private const WATER_URL   = 'https://purepeptidescanada.test/product/bacteriostatic-water/';
    private const STORAGE_URL = 'https://purepeptidescanada.test/storage-guide/';

    private mixed $previousCaps = null;

    protected function setUp(): void {
        parent::setUp();
        StubsSeo::reset();
        $this->previousCaps            = $GLOBALS['ppclg_test_caps'] ?? null;
        $GLOBALS['ppclg_test_caps']    = [ 'manage_options' => true, 'edit_post' => true, 'unfiltered_html' => true ];
        $GLOBALS['ppclg_test_user_id'] = 1;
        unset( $GLOBALS['ppclg_seo_update_fails'] );
    }

    protected function tearDown(): void {
        if ( null === $this->previousCaps ) {
            unset( $GLOBALS['ppclg_test_caps'] );
        } else {
            $GLOBALS['ppclg_test_caps'] = $this->previousCaps;
        }
        $GLOBALS['ppclg_test_user_id'] = 0;
        parent::tearDown();
    }

    private function linker(): InternalLinker {
        return new InternalLinker( Container::get( Settings::class ), Container::get( Logger::class ), Container::get( Locks::class ), Container::get( Audit::class ) );
    }

    /** Targets: product 100 (title), page 102 (focus keyword); post 103's keyword is generic; 104/105 share an anchor. */
    private function seedTargets(): void {
        StubsSeo::addPost( 100, [ 'post_type' => 'product', 'post_title' => 'Bacteriostatic Water' ] );
        StubsSeo::addPost( 102, [ 'post_type' => 'page', 'post_title' => 'How We Store Reagents', 'post_name' => 'storage-guide', 'post_content' => '<p>Notes on peptide storage at -20 °C.</p>' ], [ 'rank_math_focus_keyword' => 'peptide storage, cold chain' ] );
        StubsSeo::addPost( 103, [ 'post_type' => 'post', 'post_title' => 'Welcome' ], [ 'rank_math_focus_keyword' => 'home' ] );
        StubsSeo::addPost( 104, [ 'post_type' => 'product', 'post_title' => 'Sterile Vial Set' ] );
        StubsSeo::addPost( 105, [ 'post_type' => 'page', 'post_title' => 'Vials' ], [ 'rank_math_focus_keyword' => 'sterile vial set' ] );
    }

    /** @return array<int,array<string,mixed>> pending suggestions by source */
    private function bySource( InternalLinker $linker, int $source ): array {
        return array_values( array_filter( $linker->suggestions(), static fn( array $s ): bool => $source === (int) $s['source_post_id'] ) );
    }

    public function testSuggestionRules(): void {
        $this->seedTargets();
        $content = implode(
            "\n",
            [
                '<!-- wp:heading --><h2>Bacteriostatic Water basics</h2><!-- /wp:heading -->',
                '<p>See our <a href="/somewhere/">bacteriostatic water page</a> and the sterile vial set.</p>',
                '[caption id="x"]<img src="/v.jpg" alt=""> Bacteriostatic Water in a vial[/caption]',
                '<figure><img src="/w.jpg" alt="w"><figcaption>Bacteriostatic Water</figcaption></figure>',
                '<pre>Bacteriostatic Water</pre><p><code>bacteriostatic water</code></p>',
                '<script>var s = "Bacteriostatic Water";</script><style>/* peptide storage */</style>',
                '<!-- Bacteriostatic Water in a comment -->',
                '<p>Two Bacteriostatic Waters are not the product; non-bacteriostatic water-like is not either.</p>',
                '<p>Reconstitute with BACTERIOSTATIC&nbsp;WATER before use. Read about peptide storage first.</p>',
                '<p>Later mention of bacteriostatic water is not used again.</p>',
            ]
        );
        StubsSeo::addPost( 200, [ 'post_title' => 'Reconstitution Notes', 'post_content' => $content ] );
        // Self link: page 102 mentions its own keyword.
        // Already linked: 201 links to the product.
        StubsSeo::addPost( 201, [ 'post_title' => 'Order Notes', 'post_content' => '<p>Buy <a href="' . self::WATER_URL . '">here</a>. Bacteriostatic water ships cold.</p>' ] );
        // Elementor-built source: skipped.
        StubsSeo::addPost( 202, [ 'post_type' => 'page', 'post_title' => 'Landing', 'post_content' => '<p>Bacteriostatic water and peptide storage.</p>' ], [ '_elementor_edit_mode' => 'builder' ] );
        // Generic keyword "home" is never a target.
        StubsSeo::addPost( 203, [ 'post_title' => 'Shipping', 'post_content' => '<p>We ship home and abroad.</p>' ] );

        $linker = $this->linker();
        $res    = $linker->run( TimeBudget::seconds( 30 ) );
        $this->assertTrue( $res['ok'] );
        $this->assertFalse( $res['more'] );
        $this->assertSame( 2, $res['targets'], 'Product 100 and page 102; the generic keyword and the shared anchor are dropped.' );

        $s200 = $this->bySource( $linker, 200 );
        $this->assertCount( 2, $s200 );
        $water = array_values( array_filter( $s200, static fn( array $s ): bool => 100 === $s['target_id'] ) )[0];
        $this->assertSame( 'BACTERIOSTATIC WATER', $water['anchor'], 'First eligible whole-word occurrence, case-insensitive (shown with a plain space).' );
        $this->assertSame( self::WATER_URL, $water['target_url'] );
        $this->assertSame( 'Reconstitution Notes', $water['source_title'] );
        $this->assertStringContainsString( 'Reconstitute with BACTERIOSTATIC WATER before use', $water['excerpt'] );
        $this->assertSame( 'pending', $water['status'] );
        $storage = array_values( array_filter( $s200, static fn( array $s ): bool => 102 === $s['target_id'] ) )[0];
        $this->assertSame( 'peptide storage', $storage['anchor'] );
        $this->assertSame( self::STORAGE_URL, $storage['target_url'] );

        $this->assertSame( [], $this->bySource( $linker, 102 ), 'No self-links.' );
        $this->assertSame( [], $this->bySource( $linker, 201 ), 'Target already linked from the source.' );
        $this->assertSame( [], $this->bySource( $linker, 202 ), 'Elementor-built posts are skipped.' );
        $this->assertSame( [], $this->bySource( $linker, 203 ) );
        $this->assertSame( [], StubsSeo::$updates, 'Generating suggestions never edits content.' );
        $this->assertSame( 2, $linker->pendingCount() );

        // Max links per post.
        Container::get( Settings::class )->set( 'seo.max_links_per_post', 1 );
        $linker->run( TimeBudget::seconds( 30 ), [ 'restart' => true ] );
        $one = $this->bySource( $linker, 200 );
        $this->assertCount( 1, $one );
        $this->assertSame( 100, $one[0]['target_id'], 'Reading order decides which target is kept.' );
        $this->assertSame( $water['id'], $one[0]['id'], 'A re-run keeps the id of an unchanged suggestion.' );
    }

    public function testWholeWordAndExclusionsAtTheEngineLevel(): void {
        $html = '<p>abc<b>Bacteriostatic Water</b>s</p><h3>Bacteriostatic Water</h3><p title="Bacteriostatic Water">x</p><p>[product_info]Bacteriostatic Water[/product_info] [note text="Bacteriostatic Water"]</p><p>Ok: bacteriostatic water.</p>';
        $occ  = InternalLinker::locate( InternalLinker::analyse( $html ), 'Bacteriostatic Water' );
        $this->assertNotNull( $occ );
        // "abc<b>Bacteriostatic Water</b>s" reads as one longer word, so the last paragraph is the first eligible one.
        $this->assertSame( strrpos( $html, 'bacteriostatic water' ), $occ['start'] );
        $this->assertSame( 'bacteriostatic water', $occ['text'] );
        $inline = '<p>Use <strong>Bacteriostatic Water</strong>.</p>';
        $this->assertSame( strpos( $inline, 'Bacteriostatic' ), InternalLinker::locate( InternalLinker::analyse( $inline ), 'bacteriostatic water' )['start'] ?? null, 'Bold text bounded by spaces and punctuation is fine.' );
        $block = '<p>Intro</p><p>Bacteriostatic Water</p><p>next</p>';
        $this->assertNotNull( InternalLinker::locate( InternalLinker::analyse( $block ), 'bacteriostatic water' ), 'Block boundaries separate words.' );

        $html2 = '<h3>Bacteriostatic Water</h3><p title="Bacteriostatic Water">[product_info]Bacteriostatic Water[/product_info] [note text="Bacteriostatic Water"] BacteriostaticWater</p><p>Ok: bacteriostatic water.</p>';
        $occ2  = InternalLinker::locate( InternalLinker::analyse( $html2 ), 'Bacteriostatic Water' );
        $this->assertSame( strrpos( $html2, 'bacteriostatic water' ), $occ2['start'], 'Headings, attributes, shortcodes and joined words are skipped.' );
        $this->assertNull( InternalLinker::locate( InternalLinker::analyse( '<p>Bacteriostatic Waterfall</p>' ), 'Bacteriostatic Water' ) );
        $this->assertFalse( InternalLinker::usableAnchor( 'Home' ) );
        $this->assertFalse( InternalLinker::usableAnchor( 'abc' ) );
        $this->assertTrue( InternalLinker::usableAnchor( 'Sodium Chloride' ) );
    }

    public function testApplyChangesOnlyTheWrappedAnchorAndCreatesARevision(): void {
        $this->seedTargets();
        // Tricky bytes everywhere else: CRLF, tabs, entities, slashes and escaped JSON in block comments, single
        // quotes, unclosed-looking text, UTF-8 and an emoji.
        $content = "<!-- wp:paragraph {\"className\":\"x\\u003cy\",\"path\":\"C:\\\\lab\\\\notes\"} -->\r\n"
            . "<p class='intro'>Caf\u{00E9} &amp; lab \u{2014} notes \u{1F9EA}\t<em>a&lt;b</em> 5 > 3 &#8217;quoted&#8217;</p>\r\n"
            . "<!-- /wp:paragraph -->\r\n"
            . "<p>Rinse with bacteriostatic water (0.9%) and log the lot \\ batch.</p>\n"
            . "<p>Trailing \\'slashes\\' stay   exactly   as they are.&nbsp;</p>";
        StubsSeo::addPost( 300, [ 'post_title' => 'Lab Notes', 'post_content' => $content ] );

        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $s = $this->bySource( $linker, 300 );
        $this->assertCount( 1, $s );

        $res = $linker->apply( [ $s[0]['id'] ], 1 );
        $this->assertTrue( $res['ok'], $res['message'] );
        $this->assertSame( 1, $res['applied'] );
        $this->assertStringContainsString( 'revision', $res['message'] );

        $pos      = strpos( $content, 'bacteriostatic water' );
        $len      = strlen( 'bacteriostatic water' );
        $expected = substr( $content, 0, $pos ) . '<a href="' . self::WATER_URL . '">' . substr( $content, $pos, $len ) . '</a>' . substr( $content, $pos + $len );
        $stored   = (string) StubsSeo::$posts[300]['post_content'];
        $this->assertSame( $expected, $stored, 'Every byte outside the wrapped anchor is identical.' );
        $this->assertSame( $content, str_replace( [ '<a href="' . self::WATER_URL . '">', '</a>' ], '', $stored ) );

        // Saved through wp_update_post (slashed, as core expects) with a revision, recorded in the audit log.
        $this->assertCount( 1, StubsSeo::$updates );
        $this->assertNotEmpty( StubsSeo::$revisions[300] ?? [] );
        $item = $linker->suggestions( 'applied' )[0];
        $this->assertSame( 'applied', $item['status'] );
        $this->assertSame( StubsSeo::$revisions[300][0], $item['revision_id'] );
        $this->assertSame( 1, $item['applied_by'] );
        $audit = Container::get( AuditLog::class )->findBy( [ 'action' => 'seo.internal_link_applied' ] );
        $this->assertCount( 1, $audit );
        $this->assertSame( 'user:1', $audit[0]['actor'] );
        $this->assertSame( 300, (int) $audit[0]['object_id'] );
        $this->assertTrue( $audit[0]['details']['byte_identical'] );

        // Applying again is refused; regenerating does not re-suggest the pair.
        $again = $linker->apply( [ $s[0]['id'] ], 1 );
        $this->assertFalse( $again['ok'] );
        $linker->run( TimeBudget::seconds( 30 ), [ 'restart' => true ] );
        $this->assertSame( [], $this->bySource( $linker, 300 ) );
    }

    public function testApplyAllInOneParagraphKeepsEachSuggestionValid(): void {
        $this->seedTargets();
        StubsSeo::addPost( 301, [ 'post_title' => 'Prep', 'post_content' => '<p>Use bacteriostatic water, then follow peptide storage rules.</p>' ] );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $ids = array_column( $this->bySource( $linker, 301 ), 'id' );
        $this->assertCount( 2, $ids );
        $res = $linker->apply( $ids, 1 );
        $this->assertSame( 2, $res['applied'], $res['message'] );
        $this->assertSame( '<p>Use <a href="' . self::WATER_URL . '">bacteriostatic water</a>, then follow <a href="' . self::STORAGE_URL . '">peptide storage</a> rules.</p>', StubsSeo::$posts[301]['post_content'] );
    }

    public function testApplyRefusesWhenTextChangedOrAnchorGone(): void {
        $this->seedTargets();
        StubsSeo::addPost( 302, [ 'post_title' => 'Prep', 'post_content' => '<p>First rinse with bacteriostatic water and wait.</p><p>Second paragraph.</p>' ] );
        StubsSeo::addPost( 303, [ 'post_title' => 'Prep 2', 'post_content' => '<p>Keep bacteriostatic water cold.</p>' ] );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $a = $this->bySource( $linker, 302 )[0];
        $b = $this->bySource( $linker, 303 )[0];

        // The words around the anchor were edited after the suggestion was made.
        $changed302 = '<p>First rinse twice with bacteriostatic water and wait.</p><p>Second paragraph.</p>';
        StubsSeo::$posts[302]['post_content'] = $changed302;
        // The anchor itself was removed.
        StubsSeo::$posts[303]['post_content'] = '<p>Keep sterile diluent cold.</p>';

        $res = $linker->apply( [ $a['id'], $b['id'] ], 1 );
        $this->assertFalse( $res['ok'] );
        $this->assertSame( 0, $res['applied'] );
        foreach ( $res['results'] as $r ) {
            $this->assertFalse( $r['ok'] );
            $this->assertStringContainsString( 'text changed', $r['message'] );
        }
        $this->assertSame( $changed302, StubsSeo::$posts[302]['post_content'] );
        $this->assertSame( '<p>Keep sterile diluent cold.</p>', StubsSeo::$posts[303]['post_content'] );
        $this->assertSame( [], StubsSeo::$updates );
        $this->assertSame( 2, $linker->counts()['stale'] );
    }

    public function testElementorAndNoRevisionSourcesAreRefusedAtApplyTime(): void {
        $this->seedTargets();
        StubsSeo::addPost( 304, [ 'post_title' => 'Prep', 'post_content' => '<p>Keep bacteriostatic water cold.</p>' ] );
        StubsSeo::addPost( 305, [ 'post_title' => 'Prep 2', 'post_content' => '<p>Keep bacteriostatic water cold too.</p>' ] );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $a = $this->bySource( $linker, 304 )[0];
        $b = $this->bySource( $linker, 305 )[0];

        StubsSeo::setMeta( 304, '_elementor_edit_mode', 'builder' );
        $res = $linker->apply( [ $a['id'] ], 1 );
        $this->assertFalse( $res['ok'] );
        $this->assertStringContainsString( 'Elementor', $res['results'][0]['message'] );

        StubsSeo::$revisionsEnabled = false;
        $res = $linker->apply( [ $b['id'] ], 1 );
        $this->assertFalse( $res['ok'] );
        $this->assertStringContainsString( 'Revisions are turned off', $res['results'][0]['message'] );
        $this->assertSame( [], StubsSeo::$updates );

        // Products keep no revisions in WooCommerce, so they are never link sources.
        StubsSeo::$revisionsEnabled = true;
        StubsSeo::addPost( 306, [ 'post_type' => 'product', 'post_title' => 'Kit Box', 'post_content' => '<p>Ships with peptide storage notes.</p>' ] );
        $linker->run( TimeBudget::seconds( 30 ), [ 'restart' => true ] );
        $this->assertSame( [], $this->bySource( $linker, 306 ) );
    }

    public function testRefusesWithoutUnfilteredHtmlWhenKsesWouldAlterContentAndWhenSaveFails(): void {
        $this->seedTargets();
        StubsSeo::addPost( 307, [ 'post_title' => 'Prep', 'post_content' => '<p>Keep bacteriostatic water cold.</p><iframe src="https://example.org/x"></iframe>' ] );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $id = $this->bySource( $linker, 307 )[0]['id'];

        unset( $GLOBALS['ppclg_test_caps']['unfiltered_html'] );
        $res = $linker->apply( [ $id ], 1 );
        $this->assertFalse( $res['ok'] );
        $this->assertStringContainsString( 'unfiltered HTML', $res['results'][0]['message'] );
        $this->assertSame( 'pending', $linker->suggestions()[0]['status'], 'A permission refusal leaves the suggestion pending.' );

        $GLOBALS['ppclg_test_caps']['unfiltered_html'] = true;
        $GLOBALS['ppclg_seo_update_fails']             = true;
        $res = $linker->apply( [ $id ], 1 );
        $this->assertFalse( $res['ok'] );
        $this->assertStringContainsString( 'Nothing was changed', $res['results'][0]['message'] );
        $this->assertSame( '<p>Keep bacteriostatic water cold.</p><iframe src="https://example.org/x"></iframe>', StubsSeo::$posts[307]['post_content'] );
    }

    public function testDismissedSuggestionsAreNotSuggestedAgain(): void {
        $this->seedTargets();
        StubsSeo::addPost( 308, [ 'post_title' => 'Prep', 'post_content' => '<p>Keep bacteriostatic water cold.</p>' ] );
        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ) );
        $id  = $this->bySource( $linker, 308 )[0]['id'];
        $res = $linker->dismiss( [ $id ], 1 );
        $this->assertTrue( $res['ok'] );
        $linker->run( TimeBudget::seconds( 30 ), [ 'restart' => true ] );
        $this->assertSame( [], $this->bySource( $linker, 308 ) );
        $this->assertSame( 1, $linker->counts()['dismissed'] );
    }

    public function testJobOnlySuggestsAndRestApplyNeedsExplicitConfirmation(): void {
        $this->seedTargets();
        StubsSeo::addPost( 309, [ 'post_title' => 'Prep', 'post_content' => '<p>Keep bacteriostatic water cold.</p>' ] );
        $c = Container::plugin()->container();
        $c->set( InternalLinker::class, $this->linker() );

        $job = ( new InternalLinksJob() )->handle( [], TimeBudget::seconds( 30 ), $c );
        $this->assertSame( 'internal_links', $job['job'] );
        $this->assertSame( 1, $job['pending'] );
        $this->assertSame( 0, $job['applied'] );
        $this->assertFalse( $job['more'] );
        $this->assertIsString( $job['summary'] );
        $this->assertSame( [], StubsSeo::$updates, 'The background job never edits content.' );

        $controller = new SeoController( $c );
        $list       = $controller->links( new WP_REST_Request( 'GET', '/ppclg/v1/seo/links' ) );
        $this->assertSame( 1, $list['counts']['pending'] );
        $this->assertArrayNotHasKey( 'context_hash', $list['items'][0] );
        $this->assertStringContainsString( 'post.php?post=309', $list['items'][0]['edit_url'] );
        $id = (int) $list['items'][0]['id'];

        $req = new WP_REST_Request( 'POST', '/ppclg/v1/seo/links/apply' );
        $req->set_param( 'ids', [ $id ] );
        $refused = $controller->linksApply( $req );
        $this->assertInstanceOf( WP_Error::class, $refused );
        $this->assertSame( 400, $refused->get_error_data()['status'] );
        $this->assertSame( [], StubsSeo::$updates );

        $req->set_param( 'confirm', true );
        $applied = $controller->linksApply( $req );
        $this->assertIsArray( $applied );
        $this->assertTrue( $applied['ok'] );
        $this->assertSame( 1, $applied['applied'] );
        $this->assertSame( 1, $applied['counts']['applied'] );
        $this->assertCount( 1, StubsSeo::$updates );

        $routes = [];
        $GLOBALS['ppclg_test_routes'] = [];
        $controller->register( 'ppclg/v1' );
        foreach ( array_keys( $GLOBALS['ppclg_test_routes'] ) as $route ) {
            $routes[] = $route;
        }
        foreach ( [ '/seo/audit', '/seo/audit/run', '/seo/indexnow', '/seo/indexnow/run', '/seo/links', '/seo/links/apply', '/seo/links/dismiss' ] as $r ) {
            $this->assertContains( 'ppclg/v1' . $r, $routes );
        }
    }
}
