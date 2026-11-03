<?php
/**
 * Plugin Name: PPCLG test environment helpers (example)
 * Description: Routes *.test hosts to the local fixture server and allows localhost requests. Copy to wp-content/mu-plugins/ in a TEST install only.
 */
add_filter( 'http_request_host_is_external', static fn( $external, $host ) => in_array( $host, [ 'localhost', '127.0.0.1' ], true ) ? true : $external, 10, 2 );
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
    if ( ! empty( $args['_ppclg_fixture'] ) ) {
        return $pre;
    }
    $host = (string) wp_parse_url( $url, PHP_URL_HOST );
    if ( '' === $host || ! str_ends_with( $host, '.test' ) ) {
        return $pre;
    }
    $base   = getenv( 'PPCLG_FIXTURE_BASE' ) ?: 'http://127.0.0.1:8788';
    $path   = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' );
    $query  = (string) wp_parse_url( $url, PHP_URL_QUERY );
    $args['_ppclg_fixture']     = 1;
    $args['reject_unsafe_urls'] = false;
    $args['redirection']        = 0;
    $response = wp_remote_request( $base . '/' . $host . $path . ( '' !== $query ? '?' . $query : '' ), $args );
    if ( is_array( $response ) && isset( $response['http_response'] ) && is_object( $response['http_response'] ) ) {
        $ro = $response['http_response']->get_response_object();
        if ( is_object( $ro ) && property_exists( $ro, 'url' ) ) {
            $ro->url = $url;
        }
    }
    return $response;
}, 5, 3 );
