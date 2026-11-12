<?php
/**
 * Minimal WordPress stubs for the storefront compliance tests (taxonomies, posts, nav menus), driven by the
 * global fixture $GLOBALS['ppclg_test_storefront']:
 *
 *   [
 *     'woo'            => bool,                                  // taxonomy_exists('product_cat'|'product_tag')
 *     'throw'          => bool,                                  // get_terms()/get_posts() throw (scan failure)
 *     'terms'          => [ taxonomy => [ [term_id, name, slug, description], … ] ],
 *     'posts'          => [ [ID, post_type, post_status, post_title, post_excerpt], … ],
 *     'menu_locations' => [ location => menu id ],
 *     'menu_items'     => [ menu id => [ [ID|db_id, title, url], … ] ],
 *   ]
 *
 * With the fixture unset every stub reports an empty store, so other tests are unaffected. Each stub is only
 * defined when WordPress (or another stub file) has not defined it already.
 */

declare(strict_types=1);

if ( ! function_exists( 'ppclg_test_storefront_fixture' ) ) {
    /** @return array<string,mixed> */
    function ppclg_test_storefront_fixture(): array {
        $f = $GLOBALS['ppclg_test_storefront'] ?? [];
        return is_array( $f ) ? $f : [];
    }
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
    function taxonomy_exists( string $taxonomy ): bool {
        $f = ppclg_test_storefront_fixture();
        if ( isset( $f['terms'][ $taxonomy ] ) ) {
            return true;
        }
        return ! empty( $f['woo'] ) && in_array( $taxonomy, [ 'product_cat', 'product_tag' ], true );
    }
}

if ( ! function_exists( 'get_terms' ) ) {
    /**
     * @param array<string,mixed>|string $args
     * @return array<int,object>|WP_Error
     */
    function get_terms( array|string $args = [], mixed $deprecated = '' ): array|WP_Error {
        $f = ppclg_test_storefront_fixture();
        if ( ! empty( $f['throw'] ) ) {
            throw new RuntimeException( 'Simulated database failure while reading terms.' );
        }
        $args     = is_array( $args ) ? $args : [];
        $taxonomy = (string) ( is_array( $args['taxonomy'] ?? null ) ? ( $args['taxonomy'][0] ?? '' ) : ( $args['taxonomy'] ?? '' ) );
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );
        }
        $out = [];
        foreach ( (array) ( $f['terms'][ $taxonomy ] ?? [] ) as $t ) {
            $out[] = (object) [
                'term_id'     => (int) ( $t['term_id'] ?? 0 ),
                'name'        => (string) ( $t['name'] ?? '' ),
                'slug'        => (string) ( $t['slug'] ?? '' ),
                'description' => (string) ( $t['description'] ?? '' ),
                'taxonomy'    => $taxonomy,
                'count'       => (int) ( $t['count'] ?? 0 ),
            ];
        }
        $number = (int) ( $args['number'] ?? 0 );
        $offset = (int) ( $args['offset'] ?? 0 );
        return array_slice( $out, $offset, $number > 0 ? $number : null );
    }
}

if ( ! function_exists( 'get_posts' ) ) {
    /**
     * @param array<string,mixed>|null $args
     * @return array<int,object|int>
     */
    function get_posts( ?array $args = null ): array {
        $f = ppclg_test_storefront_fixture();
        if ( ! empty( $f['throw'] ) ) {
            throw new RuntimeException( 'Simulated database failure while reading posts.' );
        }
        $args     = $args ?? [];
        $types    = (array) ( $args['post_type'] ?? 'post' );
        $statuses = (array) ( $args['post_status'] ?? 'publish' );
        $rows     = [];
        foreach ( (array) ( $f['posts'] ?? [] ) as $p ) {
            if ( ! in_array( (string) ( $p['post_type'] ?? 'post' ), $types, true ) && ! in_array( 'any', $types, true ) ) {
                continue;
            }
            if ( ! in_array( (string) ( $p['post_status'] ?? 'publish' ), $statuses, true ) && ! in_array( 'any', $statuses, true ) ) {
                continue;
            }
            $rows[] = (object) array_merge( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => '', 'post_excerpt' => '', 'post_content' => '' ], $p );
        }
        usort( $rows, static fn( object $a, object $b ): int => (int) $a->ID <=> (int) $b->ID );
        if ( 'DESC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) && 'ID' !== ( $args['orderby'] ?? '' ) ) {
            $rows = array_reverse( $rows );
        }
        $perPage = (int) ( $args['posts_per_page'] ?? ( $args['numberposts'] ?? 5 ) );
        $offset  = (int) ( $args['offset'] ?? 0 );
        $rows    = array_slice( $rows, $offset, $perPage >= 0 ? $perPage : null );
        if ( 'ids' === ( $args['fields'] ?? '' ) ) {
            return array_map( static fn( object $p ): int => (int) $p->ID, $rows );
        }
        return $rows;
    }
}

if ( ! function_exists( 'get_nav_menu_locations' ) ) {
    /** @return array<string,int> */
    function get_nav_menu_locations(): array {
        $f = ppclg_test_storefront_fixture();
        return array_map( 'intval', (array) ( $f['menu_locations'] ?? [] ) );
    }
}

if ( ! function_exists( 'wp_get_nav_menu_items' ) ) {
    /**
     * @param array<string,mixed> $args
     * @return array<int,object>|false
     */
    function wp_get_nav_menu_items( int|string|object $menu, array $args = [] ): array|false {
        $f  = ppclg_test_storefront_fixture();
        $id = is_object( $menu ) ? (int) ( $menu->term_id ?? 0 ) : (int) $menu;
        if ( ! isset( $f['menu_items'][ $id ] ) ) {
            return false;
        }
        $out = [];
        foreach ( (array) $f['menu_items'][ $id ] as $item ) {
            $itemId = (int) ( $item['db_id'] ?? ( $item['ID'] ?? 0 ) );
            $out[]  = (object) [ 'ID' => $itemId, 'db_id' => $itemId, 'title' => (string) ( $item['title'] ?? '' ), 'url' => (string) ( $item['url'] ?? '' ), 'object' => (string) ( $item['object'] ?? 'custom' ) ];
        }
        return $out;
    }
}
