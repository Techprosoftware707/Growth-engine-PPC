<?php
/**
 * Storefront fixtures for the compliance tests (driven through tests/Support/StubsCompliance.php).
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Compliance;

require_once dirname( __DIR__, 2 ) . '/Support/StubsCompliance.php';

trait StorefrontFixtures {

    /** Installs a storefront fixture (see StubsCompliance.php for the shape). @param array<string,mixed> $fixture */
    protected function store( array $fixture ): void {
        $GLOBALS['lga_test_storefront'] = $fixture;
    }

    protected function clearStore(): void {
        unset( $GLOBALS['lga_test_storefront'] );
    }

    /**
     * A storefront presented for consumer use: consumer-use categories, a syringe product, a consumer tag,
     * a facial serum, a weight-loss short description and a weight-loss menu item (neutral names, no store's
     * catalog).
     *
     * @return array<string,mixed>
     */
    protected static function liveLikeStore(): array {
        return [
            'woo'            => true,
            'terms'          => [
                'product_cat' => [
                    [ 'term_id' => 11, 'name' => 'Metabolic &amp; Weight Loss Kits', 'slug' => 'metabolic-weight-loss-kits' ],
                    [ 'term_id' => 12, 'name' => 'Anti-Aging Serums', 'slug' => 'anti-aging-serums' ],
                    [ 'term_id' => 13, 'name' => 'Tanning Lotions', 'slug' => 'tanning-lotions' ],
                    [ 'term_id' => 14, 'name' => 'Cognitive & Focus', 'slug' => 'cognitive-focus' ],
                    [ 'term_id' => 15, 'name' => 'Recovery Packs', 'slug' => 'recovery-packs' ],
                    [ 'term_id' => 16, 'name' => 'Growth Media', 'slug' => 'growth-media' ],
                    [ 'term_id' => 17, 'name' => 'Skincare Sets', 'slug' => 'skincare-sets' ],
                    [ 'term_id' => 18, 'name' => 'Nasal Spray Bottles', 'slug' => 'nasal-spray-bottles' ],
                    [ 'term_id' => 19, 'name' => 'Capsule Fillers', 'slug' => 'capsule-fillers' ],
                ],
                'product_tag' => [
                    [ 'term_id' => 31, 'name' => 'Buy Calibration Kits Online', 'slug' => 'buy-calibration-kits-online' ],
                    [ 'term_id' => 32, 'name' => 'fasteners', 'slug' => 'weight-loss-fasteners' ],
                ],
            ],
            'posts'          => [
                [ 'ID' => 101, 'post_type' => 'product', 'post_title' => 'Dispensing Syringes 30G', 'post_excerpt' => 'Sterile 1 ml syringes with fine 30G needles.' ],
                [ 'ID' => 102, 'post_type' => 'product', 'post_title' => 'Calibration Kit 5 g', 'post_excerpt' => '<p>Popular for weight loss. Take daily before meals.</p>' ],
                [ 'ID' => 103, 'post_type' => 'product', 'post_title' => 'Citrus Facial Serum', 'post_excerpt' => '' ],
                [ 'ID' => 104, 'post_type' => 'product', 'post_title' => 'Glass Sample Vials 10 ml', 'post_excerpt' => 'Borosilicate glass. For research use only. Not for human use.' ],
                [ 'ID' => 105, 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'Fat Burner Capsules', 'post_excerpt' => '' ],
                [ 'ID' => 106, 'post_type' => 'page', 'post_title' => 'Weight loss guide', 'post_excerpt' => '' ],
            ],
            'menu_locations' => [ 'primary' => 7, 'footer' => 7 ],
            'menu_items'     => [
                7 => [
                    [ 'ID' => 501, 'title' => 'Shop' ],
                    [ 'ID' => 502, 'title' => 'Weight Loss' ],
                    [ 'ID' => 503, 'title' => 'Lab Glassware' ],
                ],
            ],
        ];
    }

    /**
     * The same store with research-only taxonomy, titles, descriptions and menus.
     *
     * @return array<string,mixed>
     */
    protected static function researchOnlyStore(): array {
        return [
            'woo'            => true,
            'terms'          => [
                'product_cat' => [
                    [ 'term_id' => 11, 'name' => 'Lab Glassware', 'slug' => 'lab-glassware', 'description' => 'Borosilicate glassware for in-vitro laboratory research. Not for human use.' ],
                    [ 'term_id' => 12, 'name' => 'Pipette Tips', 'slug' => 'pipette-tips' ],
                    [ 'term_id' => 13, 'name' => 'Lab Supplies', 'slug' => 'lab-supplies' ],
                ],
                'product_tag' => [
                    [ 'term_id' => 31, 'name' => 'Borosilicate', 'slug' => 'borosilicate' ],
                ],
            ],
            'posts'          => [
                [ 'ID' => 104, 'post_type' => 'product', 'post_title' => 'Glass Sample Vials 10 ml', 'post_excerpt' => 'Borosilicate glass vials. For research use only. Not for human use.' ],
                [ 'ID' => 107, 'post_type' => 'product', 'post_title' => 'Phosphate Buffer 500 ml', 'post_excerpt' => 'Buffer solution for laboratory calibration. Not for human consumption.' ],
                [ 'ID' => 108, 'post_type' => 'product', 'post_title' => 'Pipette Tips, Pack of 1000', 'post_excerpt' => 'Certificate of analysis available for every batch.' ],
            ],
            'menu_locations' => [ 'primary' => 7 ],
            'menu_items'     => [
                7 => [
                    [ 'ID' => 501, 'title' => 'Shop' ],
                    [ 'ID' => 503, 'title' => 'Lab Glassware' ],
                    [ 'ID' => 504, 'title' => 'COA Library' ],
                    [ 'ID' => 505, 'title' => 'Contact' ],
                ],
            ],
        ];
    }

    /**
     * Compact (severity|where|term|object_id) keys of a findings list.
     *
     * @param array<int,array<string,mixed>> $findings
     * @return string[]
     */
    protected static function keys( array $findings ): array {
        return array_map( static fn( array $f ): string => $f['severity'] . '|' . $f['where'] . '|' . $f['term'] . '|' . $f['object_id'], $findings );
    }
}
