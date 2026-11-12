<?php
/**
 * Storefront compliance preflight: scanning, matching, acknowledgement, staleness, fail-closed, job and REST.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Compliance;

use PPC\LinkGrowth\Admin\Rest\ComplianceController;
use PPC\LinkGrowth\Automation\Jobs\StorefrontAuditJob;
use PPC\LinkGrowth\Compliance\StorefrontAudit;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_Error;
use WP_REST_Request;

final class StorefrontAuditTest extends TestCase {

    use StorefrontFixtures;

    protected function setUp(): void {
        parent::setUp();
        $this->clearStore();
    }

    protected function tearDown(): void {
        $this->clearStore();
        unset( $GLOBALS['ppclg_test_caps'] );
        $GLOBALS['ppclg_test_user_id'] = 0;
        parent::tearDown();
    }

    private function audit(): StorefrontAudit {
        return Container::get( StorefrontAudit::class );
    }

    // ------------------------------------------------------------------ scanning

    public function testLiveStoreLikeTaxonomyBlocksWithFindings(): void {
        $this->store( self::liveLikeStore() );
        $r = $this->audit()->run();

        $this->assertSame( 'block', $r['status'] );
        $this->assertSame( [ 'block' => 13, 'warn' => 4 ], $r['counts'], json_encode( self::keys( $r['findings'] ) ) );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{40}$/', $r['hash'] );
        $this->assertTrue( $r['woocommerce'] );
        $this->assertSame( [ 'categories' => 9, 'tags' => 2, 'products' => 4, 'menu_items' => 3 ], $r['scanned'], 'the draft product, the page and the duplicate menu location are not scanned' );

        $keys = self::keys( $r['findings'] );
        foreach ( [
            'block|category|weight loss|11',
            'warn|category|metabolic|11',
            'block|category|anti-aging|12',
            'block|category|tanning|13',
            'warn|category|cognitive|14',
            'warn|category|recovery|15',
            'warn|category|growth|16',
            'block|category|skincare|17',
            'block|category|nasal spray|18',
            'block|category|capsule|19',
            'block|tag|weight loss|32',
            'block|product|syringe|101',
            'block|product|needle|101',
            'block|product|weight loss|102',
            'block|product|take daily|102',
            'block|product|facial serum|103',
            'block|menu|weight loss|502',
        ] as $expected ) {
            $this->assertContains( $expected, $keys );
        }
        $this->assertSame( count( $keys ), count( array_unique( $keys ) ), 'findings are deduplicated (name + slug of one term give one finding)' );
        foreach ( $r['findings'] as $f ) {
            $this->assertNotContains( (int) $f['object_id'], [ 104, 105, 106 ], 'research-only text, drafts and pages (Woo active) never produce findings' );
        }
        $this->assertSame( 'block', $r['findings'][0]['severity'], 'blocking findings are listed first' );

        $byKey = array_combine( $keys, $r['findings'] );
        $this->assertSame( 'Weight Loss & Metabolic', $byKey['block|category|weight loss|11']['label'], 'entities are decoded' );
        $this->assertSame( 'Slug: weight-loss-peptides', $byKey['block|tag|weight loss|32']['excerpt'] );
        $this->assertSame( 'Popular for weight loss. Take daily before meals.', $byKey['block|product|weight loss|102']['excerpt'], 'tags stripped from the short description' );
        $this->assertSame( 'claims:human_use', $byKey['block|product|take daily|102']['rule'] );
        $this->assertSame( 'dosage_form', $byKey['block|product|facial serum|103']['rule'] );
        $this->assertStringContainsString( 'post.php?post=101&action=edit', $byKey['block|product|syringe|101']['url'] );
        $this->assertStringContainsString( 'taxonomy=product_cat&tag_ID=11', $byKey['block|category|weight loss|11']['url'] );
        $this->assertStringContainsString( 'nav-menus.php?action=edit&menu=7', $byKey['block|menu|weight loss|502']['url'] );

        $this->assertSame( $r, get_option( StorefrontAudit::OPTION ), 'the result is stored' );
        $this->assertSame( 'block', $this->audit()->effectiveStatus() );
        $this->assertTrue( $this->audit()->isBlocking() );
        $reason = $this->audit()->blockingReason();
        $this->assertStringStartsWith( 'The storefront compliance check is blocking automated submissions and outreach sending: 13 storefront items use consumer-use wording', $reason );
        $this->assertStringContainsString( '"weight loss" in the product category "Weight Loss & Metabolic"', $reason );
    }

    public function testResearchOnlyRenamedTaxonomyPasses(): void {
        $this->store( self::researchOnlyStore() );
        $r = $this->audit()->run();

        $this->assertSame( 'pass', $r['status'], json_encode( self::keys( $r['findings'] ) ) );
        $this->assertSame( [ 'block' => 0, 'warn' => 0 ], $r['counts'] );
        $this->assertSame( [], $r['findings'] );
        $this->assertSame( 'pass', $this->audit()->effectiveStatus() );
        $this->assertFalse( $this->audit()->isBlocking() );
        $this->assertSame( '', $this->audit()->blockingReason() );
        $summary = $this->audit()->summary();
        $this->assertSame( 'pass', $summary['effective'] );
        $this->assertFalse( $summary['blocking'] );
        $this->assertSame( '', $summary['message'] );
    }

    public function testWithoutWooCommercePageAndPostTitlesAreScanned(): void {
        $this->store(
            [
                'woo'   => false,
                'posts' => [
                    [ 'ID' => 1, 'post_type' => 'page', 'post_title' => 'Weight loss guide' ],
                    [ 'ID' => 2, 'post_type' => 'post', 'post_title' => 'Longevity research roundup' ],
                    [ 'ID' => 3, 'post_type' => 'page', 'post_title' => 'About our laboratory' ],
                    [ 'ID' => 4, 'post_type' => 'product', 'post_title' => 'Nasal Spray' ],
                ],
            ]
        );
        $r = $this->audit()->run();
        $this->assertFalse( $r['woocommerce'] );
        $this->assertSame( [ 'pages' => 3 ], $r['scanned'] );
        $this->assertSame( [ 'block|page|weight loss|1', 'warn|post|longevity|2' ], self::keys( $r['findings'] ) );
    }

    public function testMatchingIsWholeWordCaseInsensitiveAndRespectsNegation(): void {
        $terms = static fn( string $text, bool $dosage = false ): array => array_map(
            static fn( array $m ): string => $m['severity'] . ':' . $m['term'],
            StorefrontAudit::matchTerms( StorefrontAudit::plain( $text ), $dosage )
        );
        $this->assertSame( [ 'block:weight loss', 'warn:metabolic' ], $terms( 'WEIGHT-LOSS_metabolic' ) );
        $this->assertSame( [ 'block:anti-aging' ], $terms( 'antiageing &amp; cellular' ) );
        $this->assertSame( [ 'block:skincare' ], $terms( 'Skin Care' ) );
        $this->assertSame( [ 'block:muscle growth' ], $terms( 'muscle growth' ), 'a blocking phrase hides the overlapping warning' );
        $this->assertSame( [], $terms( 'For research use only. Not for human use.' ) );
        $this->assertSame( [], $terms( 'Not intended for human use or consumption; no dosing guidance; not a dietary supplement.' ) );
        $this->assertSame( [ 'block:weight loss' ], $terms( 'No more weight loss struggles' ), 'a scope breaker ends the negation' );
        $this->assertSame( [], $terms( 'Doser, dosimetry, injector, regrowths, beautyful' ), 'whole words only' );
        $this->assertSame( [ 'block:for human' ], $terms( 'Made for humans' ) );
        $this->assertSame( [], $terms( 'Fetal Bovine Serum', true ), 'a lab serum is not a consumer dosage form' );
        $this->assertSame( [ 'block:facial cream' ], $terms( 'Copper Peptide Night Cream', true ) );
        $this->assertSame( [], $terms( 'Copper Peptide Night Cream', false ), 'dosage forms only apply to product titles' );
    }

    public function testFindingsAreCappedAtTwoHundredWhileCountsAndHashCoverAll(): void {
        $cats = [];
        for ( $i = 1; $i <= 250; $i++ ) {
            $cats[] = [ 'term_id' => $i, 'name' => 'Weight Loss ' . $i, 'slug' => 'weight-loss-' . $i, 'description' => 'Weight loss products.' ];
        }
        $this->store( [ 'woo' => true, 'terms' => [ 'product_cat' => $cats ] ] );
        $r = $this->audit()->run();
        $this->assertCount( StorefrontAudit::MAX_FINDINGS, $r['findings'] );
        $this->assertSame( 250, $r['counts']['block'] );
        $this->assertSame( 250, $r['total'] );
        $this->assertTrue( $r['truncated'] );

        $cats[249]['name'] = 'Research Peptides';
        $cats[249]['slug'] = 'research-peptides';
        $cats[249]['description'] = '';
        $this->store( [ 'woo' => true, 'terms' => [ 'product_cat' => $cats ] ] );
        $this->assertNotSame( $r['hash'], $this->audit()->run()['hash'], 'a change beyond the stored 200 still changes the hash' );
    }

    public function testHashIsStableAndOrderIndependent(): void {
        $a = [ 'severity' => 'block', 'where' => 'category', 'term' => 'capsule', 'object_id' => 3, 'label' => 'Capsules' ];
        $b = [ 'severity' => 'warn', 'where' => 'menu', 'term' => 'growth', 'object_id' => 9, 'label' => 'Growth' ];
        $this->assertSame( StorefrontAudit::hashFindings( [ $a, $b ] ), StorefrontAudit::hashFindings( [ $b, $a ] ) );
        $this->assertSame( StorefrontAudit::hashFindings( [ $a ] ), StorefrontAudit::hashFindings( [ array_merge( $a, [ 'label' => 'Renamed', 'excerpt' => 'x' ] ) ] ), 'labels and excerpts are not part of the hash' );
        $this->assertNotSame( StorefrontAudit::hashFindings( [ $a ] ), StorefrontAudit::hashFindings( [ array_merge( $a, [ 'object_id' => 4 ] ) ] ) );
    }

    // ------------------------------------------------------------------ acknowledgement / staleness / gate

    public function testAcknowledgeHoldsUntilTheFindingsChange(): void {
        $this->store( self::liveLikeStore() );
        $audit = $this->audit();
        $first = $audit->run();
        $this->assertTrue( $audit->isBlocking() );

        $summary = $audit->acknowledge( 5 );
        $this->assertSame( 'acknowledged', $summary['effective'] );
        $this->assertSame( 'block', $summary['status'] );
        $this->assertFalse( $summary['blocking'] );
        $this->assertSame( $first['hash'], $summary['acknowledged']['hash'] );
        $this->assertSame( 5, $summary['acknowledged']['by'] );
        $this->assertNotSame( '', $summary['acknowledged']['at'] );
        $this->assertSame( $first['hash'], Container::get( Settings::class )->get( 'compliance.acknowledged_hash' ) );
        $this->assertFalse( $audit->isBlocking() );
        $rows = Container::get( AuditLog::class )->findBy( [ 'action' => 'compliance.storefront_acknowledged' ], 'id', 'DESC', 5 );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'user:5', $rows[0]['actor'] );

        // A save that does not change the findings keeps the acknowledgement.
        $audit->markStale();
        $this->assertFalse( $audit->isBlocking() );
        $this->assertSame( 'acknowledged', $audit->effectiveStatus() );

        // Any change in the findings re-blocks.
        $store                           = self::liveLikeStore();
        $store['terms']['product_cat'][] = [ 'term_id' => 40, 'name' => 'Fat Burners', 'slug' => 'fat-burners' ];
        $this->store( $store );
        $audit->markStale();
        $this->assertTrue( $audit->isBlocking() );
        $this->assertSame( 'block', $audit->effectiveStatus() );
        $this->assertStringContainsString( '14 storefront items', $audit->blockingReason() );
    }

    public function testAcknowledgingAPassingStoreRecordsNothing(): void {
        $this->store( self::researchOnlyStore() );
        $summary = $this->audit()->acknowledge( 3 );
        $this->assertSame( 'pass', $summary['effective'] );
        $this->assertSame( '', $summary['acknowledged']['hash'] );
        $this->assertSame( [], Container::get( AuditLog::class )->findBy( [ 'action' => 'compliance.storefront_acknowledged' ], 'id', 'DESC', 5 ) );
    }

    public function testMissingStaleAndOldResultsAreRefreshedBeforeTheGateRelies(): void {
        $audit = $this->audit();
        $this->assertSame( 'unknown', $audit->effectiveStatus() );
        $this->assertSame( 'unknown', $audit->summary()['effective'] );

        $this->store( self::liveLikeStore() );
        $this->assertTrue( $audit->isBlocking(), 'never run: the gate scans first' );

        // The store is fixed, but nobody told the audit: the stored result stands until marked stale.
        $this->store( self::researchOnlyStore() );
        $this->assertTrue( $audit->isBlocking() );
        $audit->markStale();
        $this->assertTrue( $audit->summary()['stale'] );
        $this->assertFalse( $audit->isBlocking(), 'a stale result is re-scanned' );
        $this->assertFalse( get_option( StorefrontAudit::STALE_OPTION, false ) );

        // A day-old result is re-scanned too.
        $this->store( self::liveLikeStore() );
        $stored               = get_option( StorefrontAudit::OPTION );
        $stored['checked_at'] = gmdate( 'c', time() - 2 * DAY_IN_SECONDS );
        update_option( StorefrontAudit::OPTION, $stored, false );
        $this->assertSame( 'pass', $audit->effectiveStatus(), 'effectiveStatus() never scans' );
        $audit->markStale(); // only to reset the one-minute in-request memo …
        delete_option( StorefrontAudit::STALE_OPTION ); // … without marking the result stale
        $this->assertTrue( $audit->isBlocking(), 'an old result is re-scanned before the gate relies on it' );
    }

    public function testGateSwitchedOffNeverBlocks(): void {
        Container::get( Settings::class )->set( 'compliance.storefront_gate', false );
        $this->store( self::liveLikeStore() );
        $audit = $this->audit();
        $audit->run();
        $this->assertSame( 'block', $audit->effectiveStatus() );
        $this->assertFalse( $audit->isBlocking() );
        $this->assertSame( '', $audit->blockingReason() );
        $summary = $audit->summary();
        $this->assertFalse( $summary['gate'] );
        $this->assertFalse( $summary['blocking'] );
    }

    public function testScanFailureFailsClosed(): void {
        $this->store( [ 'woo' => true, 'throw' => true ] );
        $audit = $this->audit();
        $this->assertTrue( $audit->isBlocking() );
        $this->assertStringContainsString( 'could not run', $audit->blockingReason() );
        $summary = $audit->summary();
        $this->assertTrue( $summary['blocking'] );
        $this->assertSame( 'unknown', $summary['effective'] );
        $this->assertStringContainsString( 'Simulated database failure', $summary['error'] );

        try {
            $audit->run();
            $this->fail( 'run() must throw when the storefront cannot be read' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'Simulated database failure', $e->getMessage() );
        }
        $this->assertNotFalse( get_option( StorefrontAudit::STALE_OPTION, false ), 'a failed scan leaves the result marked stale' );
    }

    public function testSaveHooksMarkTheResultStale(): void {
        $audit = $this->audit();
        $audit->register();
        $this->assertArrayHasKey( 'save_post_product', $GLOBALS['ppclg_test_actions'] );
        $this->assertArrayHasKey( 'edited_product_cat', $GLOBALS['ppclg_test_actions'] );
        $this->assertArrayHasKey( 'created_product_tag', $GLOBALS['ppclg_test_actions'] );
        $this->assertArrayHasKey( 'wp_update_nav_menu', $GLOBALS['ppclg_test_actions'] );

        do_action( 'save_post_product', 101 );
        $this->assertNotFalse( get_option( StorefrontAudit::STALE_OPTION, false ) );
        delete_option( StorefrontAudit::STALE_OPTION );
        do_action( 'edited_product_cat' );
        $this->assertNotFalse( get_option( StorefrontAudit::STALE_OPTION, false ) );
    }

    // ------------------------------------------------------------------ job

    public function testJobReportsASummarySentenceAndIntegerCounters(): void {
        $this->store( self::liveLikeStore() );
        $r = ( new StorefrontAuditJob() )->handle( [], TimeBudget::seconds( 30 ), Container::plugin()->container() );
        $this->assertSame( 'storefront_audit', $r['job'] );
        $this->assertSame( 'storefront_audit', StorefrontAuditJob::type() );
        $this->assertSame( 'Checked 18 storefront items; 13 blocking and 4 to review. Automated submissions and outreach sending are paused until the storefront changes or the owner acknowledges the findings.', $r['summary'] );
        $this->assertSame( 18, $r['checked'] );
        $this->assertSame( 13, $r['block'] );
        $this->assertSame( 4, $r['warn'] );
        $this->assertSame( 17, $r['findings'] );
        $this->assertFalse( $r['more'] );
        $this->assertTrue( $r['blocking'] );

        $this->store( self::researchOnlyStore() );
        $r = ( new StorefrontAuditJob() )->handle( [], TimeBudget::seconds( 30 ), Container::plugin()->container() );
        $this->assertSame( 'Checked 11 storefront items; no consumer-use wording found.', $r['summary'] );
        $this->assertSame( 0, $r['block'] );
    }

    // ------------------------------------------------------------------ REST

    private function controller(): ComplianceController {
        $GLOBALS['ppclg_test_caps']    = [ 'manage_options' => true ];
        $GLOBALS['ppclg_test_user_id'] = 1;
        return new ComplianceController( Container::plugin()->container() );
    }

    /** @param array<string,mixed> $params */
    private function request( string $method, string $route, array $params = [] ): WP_REST_Request {
        $r = new WP_REST_Request( $method, $route );
        foreach ( $params as $k => $v ) {
            $r->set_param( $k, $v );
        }
        return $r;
    }

    public function testRestStatusRecheckAndAcknowledge(): void {
        $this->store( self::liveLikeStore() );
        $c = $this->controller();

        $GLOBALS['ppclg_test_routes'] = [];
        $c->register( 'ppclg/v1' );
        $this->assertArrayHasKey( 'ppclg/v1/compliance/storefront', $GLOBALS['ppclg_test_routes'] );
        $this->assertArrayHasKey( 'ppclg/v1/compliance/storefront/recheck', $GLOBALS['ppclg_test_routes'] );
        $this->assertArrayHasKey( 'ppclg/v1/compliance/storefront/acknowledge', $GLOBALS['ppclg_test_routes'] );

        $status = $c->status( $this->request( 'GET', '/ppclg/v1/compliance/storefront' ) );
        $this->assertIsArray( $status );
        $this->assertSame( 'block', $status['effective'], 'GET runs the check when it never ran' );
        $this->assertCount( 17, $status['findings'] );
        $this->assertCount( 5, $status['top'] );
        $this->assertTrue( $status['gate'] );
        $this->assertTrue( $status['blocking'] );
        $this->assertStringStartsWith( 'The storefront compliance check is blocking', $status['message'] );

        $refused = $c->acknowledge( $this->request( 'POST', '/ppclg/v1/compliance/storefront/acknowledge' ) );
        $this->assertInstanceOf( WP_Error::class, $refused );
        $this->assertSame( 400, $refused->get_error_data()['status'] );

        $changed = $c->acknowledge( $this->request( 'POST', '/ppclg/v1/compliance/storefront/acknowledge', [ 'confirm' => true, 'hash' => str_repeat( 'a', 40 ) ] ) );
        $this->assertInstanceOf( WP_Error::class, $changed );
        $this->assertSame( 'ppclg_findings_changed', $changed->get_error_code() );
        $this->assertSame( 'block', $this->audit()->effectiveStatus(), 'nothing was acknowledged' );

        $ack = $c->acknowledge( $this->request( 'POST', '/ppclg/v1/compliance/storefront/acknowledge', [ 'confirm' => true, 'hash' => $status['hash'] ] ) );
        $this->assertIsArray( $ack );
        $this->assertTrue( $ack['ok'] );
        $this->assertSame( 'acknowledged', $ack['effective'] );
        $this->assertSame( 1, $ack['acknowledged']['by'] );
        $this->assertStringStartsWith( 'Acknowledged 17 finding(s).', $ack['message'] );

        $this->store( self::researchOnlyStore() );
        $re = $c->recheck( $this->request( 'POST', '/ppclg/v1/compliance/storefront/recheck' ) );
        $this->assertIsArray( $re );
        $this->assertTrue( $re['ok'] );
        $this->assertSame( 'pass', $re['effective'] );
        $this->assertSame( [], $re['findings'] );
        $this->assertSame( 'Storefront checked: no consumer-use wording found.', $re['message'] );

        $nothing = $c->acknowledge( $this->request( 'POST', '/ppclg/v1/compliance/storefront/acknowledge', [ 'confirm' => true ] ) );
        $this->assertInstanceOf( WP_Error::class, $nothing );
        $this->assertSame( 'ppclg_nothing_to_acknowledge', $nothing->get_error_code() );
    }

    public function testRestRecheckReportsAFailureHonestly(): void {
        $this->store( [ 'woo' => true, 'throw' => true ] );
        $c  = $this->controller();
        $re = $c->recheck( $this->request( 'POST', '/ppclg/v1/compliance/storefront/recheck' ) );
        $this->assertInstanceOf( WP_Error::class, $re );
        $this->assertSame( 500, $re->get_error_data()['status'] );
        $this->assertStringContainsString( 'could not run', $re->get_error_message() );

        $status = $c->status( $this->request( 'GET', '/ppclg/v1/compliance/storefront' ) );
        $this->assertIsArray( $status );
        $this->assertSame( 'unknown', $status['effective'] );
        $this->assertStringContainsString( 'Simulated database failure', $status['error'] );
        $this->assertTrue( $status['blocking'], 'the response says automation is paused (fail closed)' );
        $this->assertStringContainsString( 'could not run', $status['message'] );
    }
}
