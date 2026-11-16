<?php
/**
 * 1.2.2 — GET /seo/links pages its items (per_page 1–500, default 100, page) and reports total / pages / page /
 * per_page; status=refused lists the pending suggestions automation left for a person and status=not_shown the ones
 * set aside because the page does not show them; items carry auto_skip / note / render_note and none of the internal
 * hashes. The live list (529 pending, 449 KB in one answer) now comes back in small pages.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Seo;

require_once dirname( __DIR__, 2 ) . '/Support/StubsSeo.php';

use PPC\LinkGrowth\Admin\Rest\SeoController;
use PPC\LinkGrowth\Seo\InternalLinker;
use PPC\LinkGrowth\Seo\RenderCheck;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\StubsSeo;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_REST_Request;

final class LinksListTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        StubsSeo::reset();
        $GLOBALS['ppclg_test_caps']    = [ 'manage_options' => true ];
        $GLOBALS['ppclg_test_user_id'] = 1;
        // 529 pending suggestions like the live store (8 left for a person), 3 set aside, 1 applied.
        $items = [];
        $id    = 1;
        $make  = static function ( int $id, int $source, string $status, array $extra = [] ): array {
            return array_merge(
                [
                    'id' => $id, 'source_post_id' => $source, 'source_title' => 'What Is IGF-1? Insulin-Like Growth Factor 1 and the GH/IGF-1 Axis Explained', 'source_url' => 'https://purepeptidescanada.test/what-is-igf-1-insulin-like-growth-factor-1-and-the-gh-igf-1-axis-explained/',
                    'anchor' => 'receptor tyrosine kinase', 'term' => 'receptor tyrosine kinase', 'target_id' => 11839, 'target_url' => 'https://purepeptidescanada.test/receptor-tyrosine-kinase-explained/', 'target_title' => 'Receptor Tyrosine Kinases Explained: How Growth Factor Signals Cross the Membrane',
                    'excerpt' => '… IGF-1 acts through its own receptor, a receptor tyrosine kinase that autophosphorylates once the ligand binds, which starts the PI3K/Akt and MAPK cascades that the rest of this article walks through step by step in order …',
                    'context_hash' => md5( (string) $id ), 'relevance' => 90, 'status' => $status, 'created_at' => '2026-11-16T00:24:35+00:00', 'updated_at' => '2026-11-16T00:24:37+00:00',
                ],
                $extra
            );
        };
        for ( ; $id <= 529; $id++ ) {
            $items[ (string) $id ] = $make( $id, 9000 + $id, 'pending', $id % 66 === 0 ? [ 'auto_skip' => InternalLinker::KSES_REFUSAL . ' Apply it by hand.', 'auto_skip_code' => 'kses' ] : [] );
        }
        for ( $n = 0; $n < 3; $n++, $id++ ) {
            $items[ (string) $id ] = $make( $id, 140, InternalLinker::STATUS_NOT_SHOWN, [ 'note' => RenderCheck::NOTE_POSTS, 'render_code' => 'posts_page' ] );
        }
        $items[ (string) $id ] = $make( $id, 134, 'applied', [ 'auto' => true, 'link_open' => '<a href="x">', 'link_start' => 2761, 'before_hash' => 'b', 'after_hash' => 'a', 'link_text' => 'net peptide content' ] );
        update_option( InternalLinker::OPTION, [ 'next_id' => $id + 1, 'generated_at' => gmdate( 'c' ), 'items' => $items ] );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['ppclg_test_caps'] );
        $GLOBALS['ppclg_test_user_id'] = 0;
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function get( array $params ): array {
        $req = new WP_REST_Request( 'GET', '/ppclg/v1/seo/links' );
        foreach ( $params as $k => $v ) {
            $req->set_param( $k, $v );
        }
        return ( new SeoController( Container::plugin()->container() ) )->links( $req );
    }

    public function testPagesAreSmallAndCountTheWholeList(): void {
        $first = $this->get( [ 'status' => 'pending', 'per_page' => 50 ] );
        $this->assertSame( [ 529, 11, 1, 50 ], [ $first['total'], $first['pages'], $first['page'], $first['per_page'] ] );
        $this->assertCount( 50, $first['items'] );
        $this->assertSame( 1, $first['items'][0]['id'] );
        $this->assertLessThan( 60 * 1024, strlen( (string) wp_json_encode( $first ) ), 'a page of 50 is small' );

        $last = $this->get( [ 'status' => 'pending', 'per_page' => 50, 'page' => 11 ] );
        $this->assertCount( 29, $last['items'] );
        $this->assertSame( 501, $last['items'][0]['id'] );
        $this->assertSame( [], $this->get( [ 'status' => 'pending', 'per_page' => 50, 'page' => 12 ] )['items'] );

        $default = $this->get( [ 'status' => 'pending' ] );
        $this->assertSame( 100, $default['per_page'] );
        $this->assertCount( 100, $default['items'] );
        $this->assertSame( 500, $this->get( [ 'per_page' => 5000 ] )['per_page'], 'at most 500' );
        $this->assertSame( 100, $this->get( [ 'per_page' => 0 ] )['per_page'] );
        $this->assertSame( 1, $this->get( [ 'per_page' => 1, 'page' => -3 ] )['page'] );

        $counts = $first['counts'];
        $this->assertSame( [ 529, 1, 3, 8 ], [ $counts['pending'], $counts['applied'], $counts['not_shown'], $counts['refused'] ] );
        $this->assertSame( 533, $this->get( [ 'status' => 'all', 'per_page' => 1 ] )['total'] );
    }

    public function testFiltersForWhatAutomationLeftForAPersonAndWhatIsNotShown(): void {
        $refused = $this->get( [ 'status' => 'refused' ] );
        $this->assertSame( 'refused', $refused['status'] );
        $this->assertSame( 8, $refused['total'] );
        foreach ( $refused['items'] as $item ) {
            $this->assertSame( 'pending', $item['status'] );
            $this->assertStringStartsWith( InternalLinker::KSES_REFUSAL, $item['auto_skip'] );
        }
        $hidden = $this->get( [ 'status' => 'not_shown' ] );
        $this->assertSame( 3, $hidden['total'] );
        $this->assertSame( RenderCheck::NOTE_POSTS, $hidden['items'][0]['note'] );
        $this->assertSame( 'not_shown', $hidden['items'][0]['status'] );
        $this->assertSame( 'pending', $this->get( [ 'status' => 'bogus' ] )['status'] );

        // Every item says why it waits (empty strings when nothing to say) and keeps internal bookkeeping out.
        $applied = $this->get( [ 'status' => 'applied' ] )['items'][0];
        foreach ( [ 'auto_skip', 'note', 'render_note' ] as $k ) {
            $this->assertArrayHasKey( $k, $applied );
            $this->assertIsString( $applied[ $k ] );
        }
        foreach ( [ 'context_hash', 'before_hash', 'after_hash', 'link_open', 'link_start', 'link_text' ] as $k ) {
            $this->assertArrayNotHasKey( $k, $applied, $k );
        }
        $this->assertTrue( $applied['auto'] );
        $this->assertSame( 3, $this->get( [] )['auto']['not_shown'] );
        $this->assertSame( 8, $this->get( [] )['auto']['left_for_you'] );
    }
}
