<?php
/**
 * Helpers for the growth section of the integration run (tests/integration/growth.php): storefront fixtures that
 * mirror the live store, an offline guard for outbound HTTP, a REST helper and cleanup.
 *
 * Every post and term created here carries the marker `_ppclg_it_fixture`, so ppclg_it_growth_cleanup() can
 * remove leftovers of an interrupted run (run.php calls it before the existing checks: a leftover consumer-use
 * category would otherwise make the storefront check block the existing submission checks).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

const PPCLG_IT_MARKER = '_ppclg_it_fixture';

/** Product categories of the live store (consumer-use framing), in the order the shop lists them. */
const PPCLG_IT_LIVE_CATEGORIES = [
    'Weight Loss & Metabolic',
    'Anti-Aging & Cellular',
    'Pigmentation & Tanning',
    'Cognitive & Neuro',
    'Recovery',
    'Growth',
    'Peptide Skincare',
    'Nasal Sprays',
    'Capsules',
];

/** Research-only names the categories are renamed to in part (b): no BLOCK or WARN term. */
const PPCLG_IT_RESEARCH_CATEGORIES = [
    'Weight Loss & Metabolic' => 'GLP-1 Analogue Research Compounds',
    'Anti-Aging & Cellular'   => 'Cellular Signalling Research Peptides',
    'Pigmentation & Tanning'  => 'Melanocortin Research Peptides',
    'Cognitive & Neuro'       => 'Neuropeptide Research Compounds',
    'Recovery'                => 'Tissue Research Peptides',
    'Growth'                  => 'Secretagogue Research Peptides',
    'Peptide Skincare'        => 'Copper Peptide Research Compounds',
    'Nasal Sprays'            => 'Peptide Solutions for Laboratory Use',
    'Capsules'                => 'Oral Formulation Research Reagents',
];

const PPCLG_IT_LIVE_TAG = 'buy-semaglutide-canada';

/**
 * Removes every post and term this section created (marked, or matched by the fixture names as a fallback).
 *
 * @return int number of objects removed
 */
function ppclg_it_growth_cleanup(): int {
    $n = 0;
    $posts = get_posts(
        [
            'post_type'        => [ 'product', 'post', 'page' ],
            'post_status'      => 'any',
            'numberposts'      => -1,
            'fields'           => 'ids',
            'meta_key'         => PPCLG_IT_MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery
            'suppress_filters' => true,
        ]
    );
    foreach ( array_merge( (array) $posts, ppclg_it_trashed_fixture_ids() ) as $id ) {
        if ( wp_delete_post( (int) $id, true ) ) {
            $n++;
        }
    }
    $names = array_merge( PPCLG_IT_LIVE_CATEGORIES, array_values( PPCLG_IT_RESEARCH_CATEGORIES ) );
    foreach ( [ 'product_cat', 'product_tag' ] as $taxonomy ) {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            continue;
        }
        $terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
        foreach ( is_array( $terms ) ? $terms : [] as $term ) {
            $marked = (bool) get_term_meta( (int) $term->term_id, PPCLG_IT_MARKER, true );
            $named  = in_array( html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ), $names, true ) || PPCLG_IT_LIVE_TAG === $term->slug;
            if ( $marked || $named ) {
                if ( true === wp_delete_term( (int) $term->term_id, $taxonomy ) ) {
                    $n++;
                }
            }
        }
    }
    wp_cache_flush();
    return $n;
}

/** Fixture products/posts left in the trash (get_posts( 'any' ) skips trashed items). @return int[] */
function ppclg_it_trashed_fixture_ids(): array {
    $ids = get_posts(
        [
            'post_type'        => [ 'product', 'post', 'page' ],
            'post_status'      => 'trash',
            'numberposts'      => -1,
            'fields'           => 'ids',
            'meta_key'         => PPCLG_IT_MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery
            'suppress_filters' => true,
        ]
    );
    return array_map( 'intval', (array) $ids );
}

/** Creates (or returns) a marked term. */
function ppclg_it_term( string $name, string $taxonomy, string $slug = '' ): int {
    $args = '' !== $slug ? [ 'slug' => $slug ] : [];
    $res  = wp_insert_term( $name, $taxonomy, $args );
    if ( is_wp_error( $res ) ) {
        $existing = $res->get_error_data( 'term_exists' );
        if ( ! $existing ) {
            throw new RuntimeException( 'Could not create the ' . $taxonomy . ' term "' . $name . '": ' . $res->get_error_message() );
        }
        $id = (int) $existing;
    } else {
        $id = (int) $res['term_id'];
    }
    update_term_meta( $id, PPCLG_IT_MARKER, 1 );
    return $id;
}

/**
 * Creates a published WooCommerce simple product (marked).
 *
 * @param int[] $categoryIds
 * @param int[] $tagIds
 */
function ppclg_it_product( string $title, array $categoryIds, array $tagIds = [], string $short = '' ): int {
    $product = new WC_Product_Simple();
    $product->set_name( $title );
    $product->set_status( 'publish' );
    $product->set_regular_price( '49.00' );
    $product->set_category_ids( $categoryIds );
    $product->set_tag_ids( $tagIds );
    $product->set_short_description( $short );
    $id = (int) $product->save();
    if ( $id <= 0 ) {
        throw new RuntimeException( 'Could not create the product "' . $title . '".' );
    }
    update_post_meta( $id, PPCLG_IT_MARKER, 1 );
    return $id;
}

/** Creates a published post (marked). */
function ppclg_it_post( string $title, string $content, string $type = 'post' ): int {
    $id = wp_insert_post(
        wp_slash(
            [
                'post_type'    => $type,
                'post_status'  => 'publish',
                'post_title'   => $title,
                'post_content' => $content,
                'post_author'  => 1,
            ]
        ),
        true
    );
    if ( is_wp_error( $id ) || (int) $id <= 0 ) {
        throw new RuntimeException( 'Could not create the post "' . $title . '".' );
    }
    update_post_meta( (int) $id, PPCLG_IT_MARKER, 1 );
    return (int) $id;
}

/**
 * Dispatches a REST request as the current user.
 *
 * @param array<string,mixed> $params
 */
function ppclg_it_rest( string $method, string $route, array $params = [] ): WP_REST_Response {
    $req = new WP_REST_Request( $method, '/ppclg/v1' . $route );
    foreach ( $params as $k => $v ) {
        $req->set_param( $k, $v );
    }
    if ( 'POST' === $method ) {
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( (object) $params ) );
    }
    return rest_do_request( $req );
}

/**
 * Keeps the integration run offline: requests to api.indexnow.org are answered by a recorder (HTTP 202), *.test
 * hosts and the fixture server go through the test mu-plugin, and every other host is refused (the verified
 * opportunity database names real third-party sites, which the evaluation stage would otherwise crawl).
 * Calling it again only resets the recorders.
 */
function ppclg_it_offline_guard(): void {
    $GLOBALS['itIndexNow'] = [];
    $GLOBALS['itBlocked']  = [];
    static $added = false;
    if ( $added ) {
        return;
    }
    $added = true;
    add_filter(
        'pre_http_request',
        static function ( $pre, $args, $url ) {
            if ( false !== $pre ) {
                return $pre;
            }
            $host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
            if ( 'api.indexnow.org' === $host ) {
                $GLOBALS['itIndexNow'][] = [
                    'url'     => (string) $url,
                    'method'  => strtoupper( (string) ( $args['method'] ?? 'GET' ) ),
                    'headers' => (array) ( $args['headers'] ?? [] ),
                    'body'    => is_string( $args['body'] ?? null ) ? $args['body'] : '',
                ];
                return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
            }
            if ( str_ends_with( $host, '.test' ) || '127.0.0.1' === $host ) {
                return $pre; // the mu-plugin routes *.test hosts to the fixture server on 127.0.0.1:8788
            }
            $GLOBALS['itBlocked'][ $host ] = ( $GLOBALS['itBlocked'][ $host ] ?? 0 ) + 1;
            return new WP_Error( 'ppclg_it_offline', 'Outbound request refused by the integration test (offline): ' . $host );
        },
        1,
        3
    );
}

/**
 * Starts a growth run through REST and steps it until it is no longer running.
 *
 * @return array{start:array<string,mixed>,state:array<string,mixed>,steps:int,codes:int[],seconds:float}
 */
function ppclg_it_grow(): array {
    $t0    = microtime( true );
    $codes = [];
    $start = ppclg_it_rest( 'POST', '/growth/start' );
    $codes[] = $start->get_status();
    $state = (array) $start->get_data();
    $n     = 0;
    while ( 'running' === ( $state['status'] ?? '' ) && $n < 120 ) {
        $n++;
        $res     = ppclg_it_rest( 'POST', '/growth/step' );
        $codes[] = $res->get_status();
        $state   = (array) $res->get_data();
        if ( ! empty( $state['waiting'] ) ) {
            sleep( 2 );
        }
    }
    return [ 'start' => (array) $start->get_data(), 'state' => $state, 'steps' => $n, 'codes' => $codes, 'seconds' => round( microtime( true ) - $t0, 1 ) ];
}

/**
 * One step of a growth state by key.
 *
 * @param array<string,mixed> $state
 * @return array<string,mixed>
 */
function ppclg_it_step( array $state, string $key ): array {
    foreach ( (array) ( $state['steps'] ?? [] ) as $step ) {
        if ( is_array( $step ) && $key === ( $step['key'] ?? '' ) ) {
            return $step;
        }
    }
    return [];
}
