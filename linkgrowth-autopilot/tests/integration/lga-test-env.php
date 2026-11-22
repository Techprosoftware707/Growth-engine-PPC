<?php
/**
 * Plugin Name: LGA test environment helpers
 * Description: Test installs only (DESIGN.md §12.1). Lets the plugin reach the local fixture server, routes every *.test host to it, records the host of every outbound HTTP request, and offers a frozen clock. Copy to wp-content/mu-plugins/lga-test-env.php of a throwaway site; never install it on a real site.
 *
 * Environment:
 *   LGA_FIXTURE_BASE    fixture server base URL (default http://127.0.0.1:8794, tests/fixtures/router.php)
 *   LGA_HTTP_HOST_LOG   optional file: one JSON line per outbound request ({"time","host","method","url"}), so a
 *                       test can count requests made by other processes (HTTP server, WP-CLI) as well
 *   LGA_TEST_TIME       optional Unix time returned by lga_test_time() instead of time()
 *   LGA_TEST_KNOWN_CONFLICTS  optional JSON object {"folder/main.php":"Label"} added to the lga_known_conflicts
 *                       filter (DESIGN.md §1.4), as an owner would list another plugin that does the same work
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The fixture server (and this site itself) are on loopback addresses: allow them as request targets. */
add_filter(
    'http_request_host_is_external',
    static function ( $external, $host ) {
        return in_array( $host, [ 'localhost', '127.0.0.1' ], true ) ? true : $external;
    },
    10,
    2
);
add_filter(
    'http_request_args',
    static function ( $args, $url ) {
        if ( str_starts_with( (string) $url, 'http://localhost' ) || str_starts_with( (string) $url, 'http://127.0.0.1' ) ) {
            $args['reject_unsafe_urls'] = false;
        }
        return $args;
    },
    10,
    2
);

/**
 * Host logger (activation-silence and similar sections): records every outbound request the site attempts, before
 * any other pre_http_request filter can answer or refuse it. The internal hop of the fixture routing below carries
 * `_lga_fixture` and is not counted (its original *.test request already was).
 *
 * @return list<array{time:int,host:string,method:string,url:string}>
 */
function lga_test_http_requests(): array {
    return (array) ( $GLOBALS['lga_test_http_requests'] ?? [] );
}

/** @return array<string,int> host => number of requests */
function lga_test_http_hosts(): array {
    $hosts = [];
    foreach ( lga_test_http_requests() as $r ) {
        $hosts[ $r['host'] ] = ( $hosts[ $r['host'] ] ?? 0 ) + 1;
    }
    return $hosts;
}

function lga_test_http_reset(): void {
    $GLOBALS['lga_test_http_requests'] = [];
}

add_filter(
    'pre_http_request',
    static function ( $pre, $args, $url ) {
        if ( ! empty( $args['_lga_fixture'] ) ) {
            return $pre;
        }
        $entry = [
            'time'   => time(),
            'host'   => strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) ),
            'method' => strtoupper( (string) ( $args['method'] ?? 'GET' ) ),
            'url'    => (string) $url,
        ];
        $GLOBALS['lga_test_http_requests'][] = $entry;
        $file = (string) getenv( 'LGA_HTTP_HOST_LOG' );
        if ( '' !== $file ) {
            file_put_contents( $file, (string) wp_json_encode( $entry ) . "\n", FILE_APPEND | LOCK_EX );
        }
        return $pre;
    },
    PHP_INT_MIN,
    3
);

/**
 * Routes every *.test host to the fixture server (tests/fixtures/router.php), so the plugin crawls realistic
 * fixture sites without real network access. URL layout: {base}/{host}/{path}.
 */
add_filter(
    'pre_http_request',
    static function ( $pre, $args, $url ) {
        if ( false !== $pre || ! empty( $args['_lga_fixture'] ) ) {
            return $pre;
        }
        $host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
        if ( '' === $host || ! str_ends_with( $host, '.test' ) ) {
            return $pre;
        }
        $base   = getenv( 'LGA_FIXTURE_BASE' ) ?: 'http://127.0.0.1:8794';
        $path   = (string) ( wp_parse_url( (string) $url, PHP_URL_PATH ) ?: '/' );
        $query  = (string) wp_parse_url( (string) $url, PHP_URL_QUERY );
        $target = rtrim( $base, '/' ) . '/' . $host . $path . ( '' !== $query ? '?' . $query : '' );
        $args['_lga_fixture']       = 1;
        $args['reject_unsafe_urls'] = false;
        $args['redirection']        = 0;
        $args['sslverify']          = false;
        $response = wp_remote_request( $target, $args );
        if ( is_array( $response ) && isset( $response['http_response'] ) && is_object( $response['http_response'] ) ) {
            $ro = $response['http_response']->get_response_object();
            if ( is_object( $ro ) && property_exists( $ro, 'url' ) ) {
                $ro->url = $url; // the plugin sees the fixture host, not the router address
            }
        }
        return $response;
    },
    5,
    3
);

/**
 * Frozen clock for tests: $GLOBALS['lga_test_time'] (set by a test) or the LGA_TEST_TIME environment variable,
 * else the real time. Exposed as the `lga_test_time` filter so a clock seam can read it; the WP1 fork has no such
 * seam yet (the plugin still calls time() directly), so nothing in the plugin depends on it.
 */
function lga_test_time(): int {
    if ( isset( $GLOBALS['lga_test_time'] ) && is_int( $GLOBALS['lga_test_time'] ) ) {
        return $GLOBALS['lga_test_time'];
    }
    $env = getenv( 'LGA_TEST_TIME' );
    return ( false !== $env && ctype_digit( $env ) ) ? (int) $env : time();
}
add_filter( 'lga_test_time', static fn( $now = null ): int => lga_test_time() );

/** Owner-listed conflicting plugins for tests (the coexistence section lists a second backlink plugin here). */
add_filter(
    'lga_known_conflicts',
    static function ( $list ) {
        $json  = (string) getenv( 'LGA_TEST_KNOWN_CONFLICTS' );
        $extra = '' !== $json ? json_decode( $json, true ) : null;
        return is_array( $extra ) ? array_merge( is_array( $list ) ? $list : [], $extra ) : $list;
    }
);
