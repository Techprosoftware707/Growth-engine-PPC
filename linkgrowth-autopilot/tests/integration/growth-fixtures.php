<?php
/**
 * Helpers for the growth section of the integration run (tests/integration/growth.php): storefront fixtures with
 * consumer-use wording, an offline guard for outbound HTTP, a REST helper and cleanup.
 *
 * Every post and term created here carries the marker `_lga_it_fixture`, so lga_it_growth_cleanup() can
 * remove leftovers of an interrupted run (run.php calls it before the existing checks: a leftover consumer-use
 * category would otherwise make the storefront check block the existing submission checks).
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

const LGA_IT_MARKER = '_lga_it_fixture';

/**
 * Product categories of a demo shop whose names drifted into consumer-use framing, in shop order. Each one carries a
 * term of Compliance\PositioningGate (block: weight loss, anti-aging, tanning, skincare, nasal spray, capsule; warn:
 * metabolic, cognitive, recovery, growth), so the check has something to block.
 */
const LGA_IT_LIVE_CATEGORIES = [
    'Metabolic & Weight Loss Kits',
    'Anti-Aging Serums',
    'Tanning Lotions',
    'Cognitive & Focus',
    'Recovery Packs',
    'Growth Media',
    'Skincare Sets',
    'Nasal Sprays',
    'Capsules',
];

/** Laboratory names the categories are renamed to in part (b): no BLOCK or WARN term. */
const LGA_IT_RESEARCH_CATEGORIES = [
    'Metabolic & Weight Loss Kits' => 'Calibration Standards',
    'Anti-Aging Serums'            => 'Buffer Solutions',
    'Tanning Lotions'              => 'Spectroscopy Reference Materials',
    'Cognitive & Focus'            => 'Assay Plates',
    'Recovery Packs'               => 'Sample Storage Tubes',
    'Growth Media'                 => 'Culture Plates',
    'Skincare Sets'                => 'Bench Surface Wipes',
    'Nasal Sprays'                 => 'Spray Bottles for Laboratory Use',
    'Capsules'                     => 'Weighing Boats',
];

const LGA_IT_LIVE_TAG = 'buy-weight-loss-kits-online';

/**
 * Removes every post and term this section created (marked, or matched by the fixture names as a fallback).
 *
 * @return int number of objects removed
 */
function lga_it_growth_cleanup(): int {
    $n = 0;
    $posts = get_posts(
        [
            'post_type'        => [ 'product', 'post', 'page' ],
            'post_status'      => 'any',
            'numberposts'      => -1,
            'fields'           => 'ids',
            'meta_key'         => LGA_IT_MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery
            'suppress_filters' => true,
        ]
    );
    foreach ( array_merge( (array) $posts, lga_it_trashed_fixture_ids() ) as $id ) {
        if ( wp_delete_post( (int) $id, true ) ) {
            $n++;
        }
    }
    $names = array_merge( LGA_IT_LIVE_CATEGORIES, array_values( LGA_IT_RESEARCH_CATEGORIES ) );
    foreach ( [ 'product_cat', 'product_tag' ] as $taxonomy ) {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            continue;
        }
        $terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
        foreach ( is_array( $terms ) ? $terms : [] as $term ) {
            $marked = (bool) get_term_meta( (int) $term->term_id, LGA_IT_MARKER, true );
            $named  = in_array( html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' ), $names, true ) || LGA_IT_LIVE_TAG === $term->slug;
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
function lga_it_trashed_fixture_ids(): array {
    $ids = get_posts(
        [
            'post_type'        => [ 'product', 'post', 'page' ],
            'post_status'      => 'trash',
            'numberposts'      => -1,
            'fields'           => 'ids',
            'meta_key'         => LGA_IT_MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery
            'suppress_filters' => true,
        ]
    );
    return array_map( 'intval', (array) $ids );
}

/** Creates (or returns) a marked term. */
function lga_it_term( string $name, string $taxonomy, string $slug = '' ): int {
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
    update_term_meta( $id, LGA_IT_MARKER, 1 );
    return $id;
}

/**
 * Creates a published WooCommerce simple product (marked).
 *
 * @param int[] $categoryIds
 * @param int[] $tagIds
 */
function lga_it_product( string $title, array $categoryIds, array $tagIds = [], string $short = '' ): int {
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
    update_post_meta( $id, LGA_IT_MARKER, 1 );
    return $id;
}

/** Creates a published post (marked). */
function lga_it_post( string $title, string $content, string $type = 'post' ): int {
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
    update_post_meta( (int) $id, LGA_IT_MARKER, 1 );
    return (int) $id;
}

/**
 * Dispatches a REST request as the current user.
 *
 * @param array<string,mixed> $params
 */
function lga_it_rest( string $method, string $route, array $params = [] ): WP_REST_Response {
    $req = new WP_REST_Request( $method, '/lga/v1' . $route );
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
 * hosts and the fixture server go through the test mu-plugin, and every other host is refused (an opportunity
 * catalog can name real third-party sites, which the evaluation stage would otherwise crawl).
 * Calling it again only resets the recorders.
 */
function lga_it_offline_guard(): void {
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
                return $pre; // the mu-plugin routes *.test hosts to the fixture server on 127.0.0.1:8794
            }
            // This WordPress itself while lga_it_self_server() serves it (1.2.2 render check): still offline.
            if ( ! empty( $GLOBALS['itAllowSelf'] ) && strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) === $host ) {
                return $pre;
            }
            $GLOBALS['itBlocked'][ $host ] = ( $GLOBALS['itBlocked'][ $host ] ?? 0 ) + 1;
            return new WP_Error( 'lga_it_offline', 'Outbound request refused by the integration test (offline): ' . $host );
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
function lga_it_grow(): array {
    $t0    = microtime( true );
    $codes = [];
    $start = lga_it_rest( 'POST', '/growth/start' );
    $codes[] = $start->get_status();
    $state = (array) $start->get_data();
    $n     = 0;
    while ( 'running' === ( $state['status'] ?? '' ) && $n < 120 ) {
        $n++;
        $res     = lga_it_rest( 'POST', '/growth/step' );
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
function lga_it_step( array $state, string $key ): array {
    foreach ( (array) ( $state['steps'] ?? [] ) as $step ) {
        if ( is_array( $step ) && $key === ( $step['key'] ?? '' ) ) {
            return $step;
        }
    }
    return [];
}

/**
 * Serves this WordPress over HTTP on its own address (home_url, e.g. localhost:8793) with PHP's built-in server, so
 * the plugin's loopback page requests (Seo\RenderCheck, 1.2.2) reach real themes and templates, and lets the offline
 * guard pass requests to that host. Returns a function that stops the server again (only one this call started).
 */
function lga_it_self_server(): \Closure {
    $host  = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
    $port  = (int) ( wp_parse_url( home_url( '/' ), PHP_URL_PORT ) ?: 80 );
    $up    = static function () use ( $host, $port ): bool {
        $fp = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.5 );
        if ( is_resource( $fp ) ) {
            fclose( $fp );
            return true;
        }
        return false;
    };
    $GLOBALS['itAllowSelf'] = true;
    $proc                   = null;
    if ( ! $up() ) {
        $log  = sys_get_temp_dir() . '/lga-it-self-server.log';
        $proc = proc_open( [ PHP_BINARY, '-S', $host . ':' . $port, '-t', rtrim( ABSPATH, '/' ) ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', $log, 'a' ], 2 => [ 'file', $log, 'a' ] ], $pipes );
        for ( $i = 0; $i < 50 && ! $up(); $i++ ) {
            usleep( 100000 );
        }
        // A step that throws before stopping it must not leave the server running after the run.
        register_shutdown_function(
            static function () use ( &$proc ): void {
                if ( is_resource( $proc ) ) {
                    proc_terminate( $proc );
                }
            }
        );
    }
    return static function () use ( &$proc ): void {
        $GLOBALS['itAllowSelf'] = false;
        if ( is_resource( $proc ) ) {
            proc_terminate( $proc );
            proc_close( $proc );
            $proc = null;
        }
    };
}
