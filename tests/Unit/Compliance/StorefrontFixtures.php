<?php
/**
 * Storefront fixtures for the compliance tests (driven through tests/Support/StubsCompliance.php).
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Compliance;

require_once dirname( __DIR__, 2 ) . '/Support/StubsCompliance.php';

trait StorefrontFixtures {

    /** Installs a storefront fixture (see StubsCompliance.php for the shape). @param array<string,mixed> $fixture */
    protected function store( array $fixture ): void {
        $GLOBALS['ppclg_test_storefront'] = $fixture;
    }

    protected function clearStore(): void {
        unset( $GLOBALS['ppclg_test_storefront'] );
    }

    /**
     * A store presented like the live one: consumer-use categories, a syringe product, a consumer tag,
     * a facial serum, a weight-loss short description and a weight-loss menu item.
     *
     * @return array<string,mixed>
     */
    protected static function liveLikeStore(): array {
        return [
            'woo'            => true,
            'terms'          => [
                'product_cat' => [
                    [ 'term_id' => 11, 'name' => 'Weight Loss &amp; Metabolic', 'slug' => 'weight-loss-metabolic' ],
                    [ 'term_id' => 12, 'name' => 'Anti-Aging & Cellular', 'slug' => 'anti-aging-cellular' ],
                    [ 'term_id' => 13, 'name' => 'Pigmentation & Tanning', 'slug' => 'pigmentation-tanning' ],
                    [ 'term_id' => 14, 'name' => 'Cognitive & Neuro', 'slug' => 'cognitive-neuro' ],
                    [ 'term_id' => 15, 'name' => 'Recovery', 'slug' => 'recovery' ],
                    [ 'term_id' => 16, 'name' => 'Growth', 'slug' => 'growth' ],
                    [ 'term_id' => 17, 'name' => 'Peptide Skincare', 'slug' => 'peptide-skincare' ],
                    [ 'term_id' => 18, 'name' => 'Nasal Sprays', 'slug' => 'nasal-sprays' ],
                    [ 'term_id' => 19, 'name' => 'Capsules', 'slug' => 'capsules' ],
                ],
                'product_tag' => [
                    [ 'term_id' => 31, 'name' => 'Buy Semaglutide Canada', 'slug' => 'buy-semaglutide-canada' ],
                    [ 'term_id' => 32, 'name' => 'peptides', 'slug' => 'weight-loss-peptides' ],
                ],
            ],
            'posts'          => [
                [ 'ID' => 101, 'post_type' => 'product', 'post_title' => 'Insulin Syringes 30G', 'post_excerpt' => 'Sterile 1 ml syringes with fine 30G needles.' ],
                [ 'ID' => 102, 'post_type' => 'product', 'post_title' => 'Semaglutide 5mg', 'post_excerpt' => '<p>Popular for weight loss. Take daily before meals.</p>' ],
                [ 'ID' => 103, 'post_type' => 'product', 'post_title' => 'GHK-Cu Facial Serum', 'post_excerpt' => '' ],
                [ 'ID' => 104, 'post_type' => 'product', 'post_title' => 'BPC-157 10mg', 'post_excerpt' => 'Lyophilized powder. For research use only. Not for human use.' ],
                [ 'ID' => 105, 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'Fat Burner Capsules', 'post_excerpt' => '' ],
                [ 'ID' => 106, 'post_type' => 'page', 'post_title' => 'Weight loss guide', 'post_excerpt' => '' ],
            ],
            'menu_locations' => [ 'primary' => 7, 'footer' => 7 ],
            'menu_items'     => [
                7 => [
                    [ 'ID' => 501, 'title' => 'Shop' ],
                    [ 'ID' => 502, 'title' => 'Weight Loss' ],
                    [ 'ID' => 503, 'title' => 'Research Peptides' ],
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
                    [ 'term_id' => 11, 'name' => 'Research Peptides', 'slug' => 'research-peptides', 'description' => 'Lyophilized peptides for in-vitro laboratory research. Not for human use.' ],
                    [ 'term_id' => 12, 'name' => 'Peptide Blends', 'slug' => 'peptide-blends' ],
                    [ 'term_id' => 13, 'name' => 'Lab Supplies', 'slug' => 'lab-supplies' ],
                ],
                'product_tag' => [
                    [ 'term_id' => 31, 'name' => 'Lyophilized', 'slug' => 'lyophilized' ],
                ],
            ],
            'posts'          => [
                [ 'ID' => 104, 'post_type' => 'product', 'post_title' => 'BPC-157 10mg', 'post_excerpt' => 'Lyophilized research peptide. For research use only. Not for human use.' ],
                [ 'ID' => 107, 'post_type' => 'product', 'post_title' => 'Bacteriostatic Water 30ml', 'post_excerpt' => 'Sterile diluent for laboratory reconstitution. Not for human consumption.' ],
                [ 'ID' => 108, 'post_type' => 'product', 'post_title' => 'TB-500 5mg', 'post_excerpt' => 'Certificate of analysis available for every batch.' ],
            ],
            'menu_locations' => [ 'primary' => 7 ],
            'menu_items'     => [
                7 => [
                    [ 'ID' => 501, 'title' => 'Shop' ],
                    [ 'ID' => 503, 'title' => 'Research Peptides' ],
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
