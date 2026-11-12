<?php
/**
 * Site audit: issue detection on fixture posts, Rank Math variables, cursor batches, page cap and the HTTP sample.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Seo;

require_once dirname( __DIR__, 2 ) . '/Support/StubsSeo.php';

use PPC\LinkGrowth\Automation\Jobs\SiteAuditJob;
use PPC\LinkGrowth\Repositories\Jobs;
use PPC\LinkGrowth\Seo\SiteAuditor;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Locks;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\StubsSeo;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class SiteAuditorTest extends TestCase {

    private const LONG = 'Our laboratory reagent catalogue lists every compound with its certificate of analysis, storage temperature, lot number and handling notes. ';

    protected function setUp(): void {
        parent::setUp();
        StubsSeo::reset();
        $this->settings()->set( 'seo.audit_http_sample', false );
    }

    private function settings(): Settings {
        return Container::get( Settings::class );
    }

    private function auditor( ?\Closure $replaceVars = null, bool $withClient = false ): SiteAuditor {
        return new SiteAuditor(
            $this->settings(),
            Container::get( Logger::class ),
            Container::get( Locks::class ),
            $withClient ? Container::get( Client::class ) : null,
            $replaceVars
        );
    }

    private function words( int $n ): string {
        return '<p>' . implode( ' ', array_fill( 0, $n, 'reagent' ) ) . '</p>';
    }

    /** A realistic small site with one problem per item. */
    private function seedSite(): void {
        $good = '<p>' . str_repeat( self::LONG, 12 ) . '</p>';
        // 10: home page (front page, in menu) — clean.
        StubsSeo::addPost( 10, [ 'post_type' => 'page', 'post_title' => 'Research Reagents in Canada', 'post_content' => $good . '<p><a href="/storage-guide/">storage</a> <a href="/product/bacteriostatic-water/">water</a></p>', 'post_excerpt' => str_repeat( 'Laboratory reagents with lot-level documentation. ', 2 ) ] );
        // 11: in the menu but noindex.
        StubsSeo::addPost( 11, [ 'post_type' => 'page', 'post_title' => 'Certificates of Analysis Library Page', 'post_content' => $good, 'post_excerpt' => str_repeat( 'Certificates of analysis for every lot we sell. ', 2 ) ], [ 'rank_math_robots' => [ 'noindex', 'nofollow' ] ] );
        // 12, 13: duplicate titles, no description, thin.
        StubsSeo::addPost( 12, [ 'post_title' => 'How to Store Lyophilized Reagents', 'post_name' => 'storage-guide', 'post_content' => $this->words( 120 ) ] );
        StubsSeo::addPost( 13, [ 'post_title' => 'How to Store Lyophilized Reagents', 'post_name' => 'storage-guide-2', 'post_content' => $this->words( 400 ) . '<p><a href="/storage-guide/">guide</a></p>' ] );
        // 14: long title, short description, two H1s, image without alt, broken internal link, links to 15.
        StubsSeo::addPost(
            14,
            [
                'post_title'   => 'A Very Long Title About Reconstitution Volumes, Labels and Lot Records',
                'post_excerpt' => 'Too short.',
                'post_content' => '<h1>First</h1><h1>Second</h1><p><img src="/a.jpg"><img src="/b.jpg" alt="Vial label"></p>' . $good . '<p><a href="/no-such-page/">old</a> <a href="https://purepeptidescanada.test/lab-notes/">notes</a> <a href="https://example.org/">external</a> <a href="/product-category/reagents/">cat</a> <a href="/wp-content/uploads/sds.pdf">SDS</a> <a href="#top">top</a> <a href="mailto:hi@purepeptidescanada.test">mail</a></p>',
            ]
        );
        // 15: linked from 14 → not an orphan.
        StubsSeo::addPost( 15, [ 'post_type' => 'page', 'post_title' => 'Laboratory Notes and Handling Updates', 'post_name' => 'lab-notes', 'post_content' => $good . '<p><a href="/a-very-long-title-about-reconstitution-volumes-labels-and-lot-records/">long</a></p>', 'post_excerpt' => str_repeat( 'Notes on handling and storing laboratory reagents. ', 2 ) ] );
        // 16: nobody links here → orphan.
        StubsSeo::addPost( 16, [ 'post_type' => 'page', 'post_title' => 'Shipping Times Across Canada and Abroad', 'post_content' => $good, 'post_excerpt' => str_repeat( 'Shipping times for orders within Canada and abroad. ', 2 ) ] );
        // 20: product with a Rank Math title template and a featured image without alt.
        StubsSeo::addPost( 20, [ 'post_type' => 'product', 'post_title' => 'Bacteriostatic Water', 'post_content' => '<p>Short product text.</p>', 'post_excerpt' => str_repeat( 'Bacteriostatic water for laboratory reconstitution. ', 2 ) ], [ 'rank_math_title' => '%title% %sep% %sitename%', '_thumbnail_id' => 900, 'rank_math_focus_keyword' => 'bacteriostatic water,water' ] );
        StubsSeo::addPost( 900, [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'vial' ] );
        // 30: draft — never audited.
        StubsSeo::addPost( 30, [ 'post_status' => 'draft', 'post_title' => 'Draft' ] );
        StubsSeo::addMenuItem( 10 );
        StubsSeo::addMenuItem( 11 );
        StubsSeo::addTerm( 'product_cat', 'reagents' );
        update_option( 'page_on_front', 10 );
    }

    /** @param array<int,array<string,mixed>> $issues @return array<int,string[]> post id => codes */
    private static function codes( array $issues ): array {
        $out = [];
        foreach ( $issues as $i ) {
            $out[ (int) $i['post_id'] ][] = (string) $i['code'];
        }
        return $out;
    }

    public function testDetectsIssuesWithSeverityFixAndEditLink(): void {
        $this->seedSite();
        $before   = StubsSeo::$posts;
        $replaced = [];
        $auditor  = $this->auditor( static function ( string $text, object $post ) use ( &$replaced ): string {
            $replaced[] = $text;
            return strtr( $text, [ '%title%' => (string) $post->post_title, '%sep%' => '–', '%sitename%' => 'Pure Peptides Canada' ] );
        } );

        $res = $auditor->run( TimeBudget::seconds( 30 ) );
        $this->assertTrue( $res['done'] );
        $this->assertFalse( $res['more'] );
        $this->assertSame( 8, $res['checked'], 'Seven published pages/posts plus one product; drafts and attachments are not audited.' );

        $result = $auditor->result();
        $codes  = self::codes( $result['issues'] );
        $sev    = [];
        foreach ( $result['issues'] as $i ) {
            $sev[ $i['post_id'] . ':' . $i['code'] ] = $i['severity'];
            $this->assertNotSame( '', trim( (string) $i['fix'] ), 'Every issue carries a concrete fix.' );
            $this->assertNotSame( '', trim( (string) $i['message'] ) );
            $this->assertStringContainsString( 'post.php?post=' . $i['post_id'], (string) $i['edit_url'] );
            $this->assertMatchesRegularExpression( '/^(high|medium|low)$/', (string) $i['severity'] );
        }

        $this->assertSame( 'high', $sev['11:noindex_in_menu'] ?? null );
        $this->assertSame( 'high', $sev['12:duplicate_title'] ?? null );
        $this->assertSame( 'high', $sev['13:duplicate_title'] ?? null );
        $this->assertSame( 'medium', $sev['12:missing_description'] ?? null );
        $this->assertSame( 'low', $sev['12:thin_content'] ?? null );
        $this->assertNotContains( 'thin_content', $codes[13] ?? [], '400 words is not thin.' );
        $this->assertSame( 'medium', $sev['14:title_too_long'] ?? null );
        $this->assertSame( 'medium', $sev['14:description_too_short'] ?? null );
        $this->assertSame( 'low', $sev['14:multiple_h1'] ?? null );
        $this->assertSame( 'low', $sev['14:images_missing_alt'] ?? null );
        $this->assertSame( 'medium', $sev['14:broken_internal_link'] ?? null );
        $this->assertSame( 'medium', $sev['16:orphan'] ?? null );
        $this->assertSame( 'low', $sev['20:product_image_missing_alt'] ?? null );
        $this->assertNotContains( 'thin_content', $codes[20] ?? [], 'Products are not judged by word count.' );

        // One broken link only: the category archive, the upload, the anchor and the mailto are fine.
        $broken = array_values( array_filter( $result['issues'], static fn( array $i ): bool => 'broken_internal_link' === $i['code'] ) );
        $this->assertCount( 1, $broken );
        $this->assertStringContainsString( '/no-such-page/', $broken[0]['message'] );

        // Not orphans: linked from content (12, 15), in a menu (11), front page (10). The noindex page is not judged.
        foreach ( [ 10, 11, 12, 15 ] as $id ) {
            $this->assertNotContains( 'orphan', $codes[ $id ] ?? [], "#$id is linked" );
        }
        // Clean pages stay clean; the noindex page gets only the menu warning.
        $this->assertSame( [], $codes[10] ?? [] );
        $this->assertSame( [ 'noindex_in_menu' ], $codes[11] ?? [] );

        // Rank Math variables went through the resolver.
        $this->assertContains( '%title% %sep% %sitename%', $replaced );
        $this->assertNotContains( 'title_too_short', $codes[20] ?? [], 'Resolved: "Bacteriostatic Water – Pure Peptides Canada" (42 chars).' );

        // Counts, ordering, contract summary.
        $this->assertSame( count( $result['issues'] ), $result['issues_total'] );
        $this->assertSame( $result['issues_total'], array_sum( $result['counts'] ) );
        $order = array_map( static fn( array $i ): string => $i['severity'], $result['issues'] );
        $this->assertSame( $order, array_merge( array_values( array_filter( $order, static fn( $s ) => 'high' === $s ) ), array_values( array_filter( $order, static fn( $s ) => 'medium' === $s ) ), array_values( array_filter( $order, static fn( $s ) => 'low' === $s ) ) ) );
        $summary = $auditor->summary();
        $this->assertSame( [ 'checked_at', 'pages', 'complete', 'counts', 'issues_total' ], array_slice( array_keys( $summary ), 0, 5 ) );
        $this->assertTrue( $summary['complete'] );
        $this->assertSame( 8, $summary['pages'] );
        $this->assertSame( $result['counts'], $summary['counts'] );
        $this->assertIsString( $summary['checked_at'] );

        // Read-only: nothing was edited and no HTTP request was made.
        $this->assertSame( $before, StubsSeo::$posts );
        $this->assertSame( [], StubsSeo::$updates );
        $this->assertSame( [], HttpFixtures::$requests );
        $this->assertFalse( (bool) get_option( SiteAuditor::OPTION_RUN, false ), 'Work state is removed once the audit completes.' );
    }

    public function testRankMathFallbackWithoutHelperAndSerializedRobots(): void {
        StubsSeo::addPost( 1, [ 'post_type' => 'page', 'post_title' => 'Storage Temperatures', 'post_excerpt' => 'x' ], [ 'rank_math_title' => '%title% | %sitename% %unknownvar%', 'rank_math_description' => '%excerpt% about %title%' ] );
        StubsSeo::addPost( 2, [ 'post_type' => 'page', 'post_title' => 'Hidden' ], [ 'rank_math_robots' => serialize( [ 'noindex' ] ) ] );
        StubsSeo::addPost( 3, [ 'post_type' => 'page', 'post_title' => 'Indexed' ], [ 'rank_math_robots' => 'index,follow' ] );
        $auditor = $this->auditor();
        $this->assertSame( 'Storage Temperatures | Pure Peptides Canada', $auditor->resolveTitle( StubsSeo::post( 1 ) ) );
        $this->assertSame( 'x about Storage Temperatures', $auditor->resolveDescription( StubsSeo::post( 1 ) ) );
        $this->assertSame( 'Indexed', $auditor->resolveTitle( StubsSeo::post( 3 ) ), 'No Rank Math: the post title is the SEO title.' );
        $this->assertTrue( SiteAuditor::isNoindex( 2, 'page' ) );
        $this->assertFalse( SiteAuditor::isNoindex( 3, 'page' ) );

        // Post-type default from Rank Math's Titles & Meta when the item has no robots meta.
        update_option( 'rank-math-options-titles', [ 'pt_page_custom_robots' => 'on', 'pt_page_robots' => [ 'noindex' ] ] );
        $this->assertTrue( SiteAuditor::isNoindex( 1, 'page' ) );
        $this->assertFalse( SiteAuditor::isNoindex( 3, 'page' ), 'Item meta wins over the post-type default.' );
    }

    public function testShortcodeTagsAreIgnoredButTheirContentCounts(): void {
        $caption = '[caption id="attachment_5" width="300"]<img src="/v.jpg"> Vial label close-up[/caption]';
        $this->assertSame( 5, SiteAuditor::wordCount( '<p>Two words</p>[gallery ids="1,2"]' . $caption ), 'Two words + Vial label close-up.' );
        $this->assertStringContainsString( '<img src="/v.jpg">', SiteAuditor::stripShortcodeTags( $caption ) );
        $this->assertSame( [ [ 0, strlen( $caption ) ] ], SiteAuditor::shortcodeRanges( $caption ) );
        StubsSeo::addPost( 1, [ 'post_type' => 'page', 'post_title' => 'Vial Labels and Lot Numbers Explained', 'post_content' => $caption . $this->words( 320 ), 'post_excerpt' => str_repeat( 'How to read vial labels and lot numbers. ', 2 ) ] );
        update_option( 'page_on_front', 1 ); // the front page is never an orphan
        $auditor = $this->auditor();
        $auditor->run( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 'images_missing_alt' ], array_column( $auditor->result()['issues'], 'code' ), 'The captioned image without alt text is found.' );
    }

    public function testCursorBatchesPageCapAndSkipsOrphanCheckWhenCapped(): void {
        for ( $i = 1; $i <= 5; $i++ ) {
            StubsSeo::addPost( $i, [ 'post_type' => 'page', 'post_title' => 'Laboratory Page Number ' . $i . ' With Detail', 'post_content' => $this->words( 350 ) ] );
        }
        $this->settings()->set( 'seo.audit_max_pages', 3 );
        $auditor = $this->auditor();

        // No time: nothing analysed, work kept for the next run.
        $first = $auditor->run( TimeBudget::seconds( 0.5 ) );
        $this->assertTrue( $first['more'] );
        $this->assertFalse( $first['done'] );
        $this->assertSame( 0, $first['checked'] );
        $this->assertSame( 3, $first['total'] );
        $this->assertSame( 'Audited 0 of 3 pages; 0 issues so far.', $first['summary'] );
        $summary = $auditor->summary();
        $this->assertFalse( $summary['complete'] );
        $this->assertTrue( $summary['running'] );

        $second = $auditor->run( TimeBudget::seconds( 30 ) );
        $this->assertTrue( $second['done'] );
        $result = $auditor->result();
        $this->assertSame( 3, $result['pages'] );
        $this->assertTrue( $result['capped'] );
        $this->assertFalse( $result['orphans_checked'], 'A capped audit cannot know inbound links from unread items.' );
        $this->assertNotContains( 'orphan', array_column( $result['issues'], 'code' ) );
    }

    public function testBusyLockIsReportedNotRun(): void {
        StubsSeo::addPost( 1, [ 'post_type' => 'page', 'post_title' => 'Page' ] );
        $other = new Locks();
        $this->assertTrue( $other->acquire( SiteAuditor::LOCK, 60 ) );
        $res = $this->auditor()->run( TimeBudget::seconds( 10 ) );
        $this->assertTrue( $res['busy'] );
        $this->assertFalse( $res['ok'] );
        $this->assertSame( [], $this->auditor()->result() );
        $other->release( SiteAuditor::LOCK );
    }

    public function testSiteWideDiscourageIsHigh(): void {
        StubsSeo::addPost( 1, [ 'post_type' => 'page', 'post_title' => 'Laboratory Reagents and Documentation', 'post_content' => $this->words( 400 ), 'post_excerpt' => str_repeat( 'Laboratory reagents with lot documentation. ', 2 ) ] );
        update_option( 'blog_public', '0' );
        $auditor = $this->auditor();
        $auditor->run( TimeBudget::seconds( 30 ) );
        $issues = $auditor->result()['issues'];
        $this->assertSame( 'site_noindex', $issues[0]['code'] );
        $this->assertSame( 'high', $issues[0]['severity'] );
        $this->assertStringContainsString( 'options-reading.php', $issues[0]['edit_url'] );
    }

    public function testHttpSampleGoesThroughClientRespectsCapAndReportsUnknownForChallenges(): void {
        $this->settings()->set( 'seo.audit_http_sample', true );
        $this->settings()->set( 'advanced.crawl_delay_seconds', 0 );
        $good = '<p>' . str_repeat( self::LONG, 12 ) . '</p>';
        for ( $i = 1; $i <= 14; $i++ ) {
            StubsSeo::addPost( $i, [ 'post_type' => 'page', 'post_title' => 'Reference Page Number ' . $i . ' For The Lab', 'post_name' => 'ref-' . $i, 'post_content' => $good, 'post_excerpt' => str_repeat( 'Reference material for laboratory work and storage. ', 2 ) ] );
        }
        $home = 'https://purepeptidescanada.test';
        HttpFixtures::add( $home . '/robots.txt', "User-agent: *\nAllow: /\n", 200, [ 'content-type' => 'text/plain' ] );
        $page = static fn( string $canonical, string $extra = '' ): string => '<!DOCTYPE html><html><head><title>t</title><link rel="canonical" href="' . $canonical . '">' . $extra . '<script type="application/ld+json">{}</script></head><body><h1>t</h1></body></html>';
        HttpFixtures::add( $home . '/', $page( $home . '/' ) );
        HttpFixtures::add( $home . '/ref-1/', $page( $home . '/somewhere-else/' ) );
        HttpFixtures::add( $home . '/ref-2/', $page( $home . '/ref-2/', '<meta name="robots" content="noindex, follow">' ) );
        HttpFixtures::add( $home . '/ref-3/', '<html><body>Just a moment…</body></html>', 403, [ 'content-type' => 'text/html', 'server' => 'cloudflare', 'cf-mitigated' => 'challenge' ] );
        HttpFixtures::addPattern( '#^https://purepeptidescanada\.test/ref-\d+/$#', $page( '' ) . '' );

        $auditor = $this->auditor( null, true );
        $res     = $auditor->run( TimeBudget::seconds( 200 ) );
        $this->assertTrue( $res['done'] );
        $result = $auditor->result();

        $pageRequests = array_values( array_filter( HttpFixtures::$requests, static fn( array $r ): bool => ! str_ends_with( $r['url'], '/robots.txt' ) ) );
        $this->assertLessThanOrEqual( SiteAuditor::SAMPLE_MAX, count( $pageRequests ) );
        $this->assertCount( SiteAuditor::SAMPLE_MAX, $result['sample'] );
        $this->assertTrue( (bool) array_filter( HttpFixtures::$requests, static fn( array $r ): bool => str_ends_with( $r['url'], '/robots.txt' ) ), 'robots.txt is consulted first.' );

        $by = [];
        foreach ( $result['issues'] as $i ) {
            $by[ $i['post_id'] . ':' . $i['code'] ] = $i['severity'];
        }
        $this->assertSame( 'medium', $by['1:canonical_mismatch'] ?? null );
        $this->assertSame( 'high', $by['2:noindex_rendered'] ?? null );
        $sample3 = array_values( array_filter( $result['sample'], static fn( array $s ): bool => 3 === $s['post_id'] ) );
        $this->assertSame( 'unknown', $sample3[0]['result'] ?? null, 'A Cloudflare challenge is not reported as a site error.' );
        $this->assertArrayNotHasKey( '3:http_error', $by );
    }

    public function testSiteAuditJobReportsSummaryCountersAndContinuation(): void {
        for ( $i = 1; $i <= 3; $i++ ) {
            StubsSeo::addPost( $i, [ 'post_type' => 'page', 'post_title' => 'Page ' . $i ] );
        }
        $c   = Container::plugin()->container();
        $c->set( SiteAuditor::class, $this->auditor() );
        $job = new SiteAuditJob();

        // Too little time: deferred and re-queued, nothing analysed.
        $deferred = $job->handle( [ 'manual' => true ], TimeBudget::seconds( 2.0 ), $c );
        $this->assertSame( 'site_audit', $deferred['job'] );
        $this->assertTrue( $deferred['skipped'] );
        $this->assertTrue( $deferred['more'] );
        $this->assertIsString( $deferred['summary'] );
        $c->get( Jobs::class )->cancelQueued( 'site_audit' );

        $done = $job->handle( [ 'manual' => true ], TimeBudget::seconds( 30 ), $c );
        $this->assertFalse( $done['more'] );
        $this->assertSame( 3, $done['checked'] );
        $this->assertStringStartsWith( 'Audited 3 pages;', $done['summary'] );
        foreach ( [ 'checked', 'total', 'issues', 'high', 'medium', 'low' ] as $k ) {
            $this->assertIsInt( $done[ $k ] );
        }

        // With the HTTP spot check on and too little time left for it, the job reports more work and waits for the
        // next tick (a continuation due at once would be claimed again in this tick with even less time).
        $this->settings()->set( 'seo.audit_http_sample', true );
        $c->set( SiteAuditor::class, $this->auditor( null, true ) );
        $more = ( new SiteAuditJob() )->handle( [ 'manual' => true, 'restart' => true ], TimeBudget::seconds( 4.0 ), $c );
        $this->assertTrue( $more['more'] );
        $this->assertTrue( $more['requeued'] );
        $this->assertTrue( $more['skipped'] );
        $this->assertSame( 'time_budget', $more['reason'] );
        $this->assertSame( 'Audited all 3 pages; the HTTP spot check of up to 10 URLs runs next.', $more['summary'] );
        $queued = $c->get( Jobs::class )->findBy( [ 'type' => 'site_audit', 'status' => Jobs::STATUS_QUEUED ] );
        $this->assertCount( 1, $queued );
        $this->assertArrayNotHasKey( 'restart', $queued[0]['payload'], 'The continuation must not restart the audit.' );
        $this->assertGreaterThan( gmdate( 'Y-m-d H:i:s', time() + 30 ), (string) $queued[0]['scheduled_at'], 'the spot check continues on a later tick' );
    }
}
