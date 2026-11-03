<?php
/**
 * Polite HTTP client on top of the WordPress HTTP API.
 *
 *  - Identifies itself with a descriptive user agent.
 *  - Respects robots.txt for crawling (never for our own site or API hosts).
 *  - Per-host crawl delay, response size cap, timeouts.
 *  - Refuses private/loopback targets via wp_http_validate_url (SSRF protection).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Support\Http;

use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Url;

final class Client {

    /** @var array<string,float> host => last request time */
    private array $lastHit = [];

    /** @var array<string,Response> short-lived in-request cache */
    private array $cache = [];

    public function __construct(
        private Settings $settings,
        private Logger $logger,
        private RobotsTxt $robots
    ) {}

    /**
     * Crawl a third-party page: robots-aware, throttled, HTML-oriented.
     *
     * @param array<string,mixed> $opts { allow_robots_skip?: bool, timeout?: int, cache?: bool }
     */
    public function crawl( string $url, array $opts = [] ): Response {
        $url = Url::normalize( $url );
        if ( '' === $url ) {
            return Response::error( $url, 'Invalid URL.', 'invalid_url' );
        }
        if ( ! empty( $opts['cache'] ) && isset( $this->cache[ $url ] ) ) {
            return $this->cache[ $url ];
        }
        if ( empty( $opts['allow_robots_skip'] ) && ! $this->robots->isAllowed( $url, fn( string $u ) => $this->rawRequest( 'GET', $u, [ 'timeout' => 8 ] ) ) ) {
            $this->logger->debug( 'http', 'Blocked by robots.txt', [ 'url' => $url ] );
            return Response::error( $url, 'Disallowed by robots.txt.', 'robots_disallow' );
        }
        $this->throttle( Url::host( $url ) );
        $resp = $this->rawRequest( 'GET', $url, $opts );
        if ( ! empty( $opts['cache'] ) ) {
            $this->cache[ $url ] = $resp;
        }
        return $resp;
    }

    /** HEAD-ish check for broken links (falls back to GET when HEAD is refused). */
    public function probe( string $url, array $opts = [] ): Response {
        $url = Url::normalize( $url );
        if ( '' === $url ) {
            return Response::error( $url, 'Invalid URL.', 'invalid_url' );
        }
        $this->throttle( Url::host( $url ) );
        $resp = $this->rawRequest( 'HEAD', $url, array_merge( [ 'timeout' => 10 ], $opts ) );
        if ( $resp->error || in_array( $resp->status, [ 403, 405, 501 ], true ) ) {
            $resp = $this->rawRequest( 'GET', $url, array_merge( [ 'timeout' => 10, 'limit_kb' => 64 ], $opts ) );
        }
        return $resp;
    }

    /**
     * JSON API call (not subject to robots.txt or crawl delay; providers have their own limits).
     *
     * @param array<string,string> $headers
     * @param array<string,mixed>|string|null $body
     */
    public function api( string $method, string $url, array $headers = [], array|string|null $body = null, int $timeout = 20 ): Response {
        $opts = [ 'headers' => $headers, 'timeout' => $timeout, 'limit_kb' => 8192 ];
        if ( null !== $body ) {
            if ( is_array( $body ) ) {
                $opts['body']                    = wp_json_encode( $body );
                $opts['headers']['Content-Type'] = 'application/json';
            } else {
                $opts['body'] = $body;
            }
        }
        return $this->rawRequest( strtoupper( $method ), $url, $opts );
    }

    /**
     * Form POST used by the authorized submission adapter.
     *
     * @param array<string,string> $fields
     * @param array<string,string> $headers
     */
    public function postForm( string $url, array $fields, array $headers = [], array $cookies = [] ): Response {
        $this->throttle( Url::host( $url ) );
        return $this->rawRequest( 'POST', $url, [ 'body' => $fields, 'headers' => $headers, 'cookies' => $cookies, 'timeout' => 25 ] );
    }

    /**
     * @param array<string,mixed> $opts
     */
    public function rawRequest( string $method, string $url, array $opts = [] ): Response {
        $start   = microtime( true );
        $timeout = (int) ( $opts['timeout'] ?? $this->settings->get( 'advanced.request_timeout', 15 ) );
        $limitKb = (int) ( $opts['limit_kb'] ?? $this->settings->get( 'advanced.max_response_kb', 1536 ) );
        $headers = array_merge(
            [
                'Accept'          => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-CA,en;q=0.9',
            ],
            (array) ( $opts['headers'] ?? [] )
        );

        $args = [
            'method'              => $method,
            'timeout'             => max( 3, $timeout ),
            'redirection'         => (int) ( $opts['redirection'] ?? 4 ),
            'user-agent'          => $this->settings->userAgent(),
            'headers'             => $headers,
            'sslverify'           => true,
            'reject_unsafe_urls'  => true,
            'limit_response_size' => $limitKb * 1024,
            'decompress'          => true,
        ];
        if ( isset( $opts['body'] ) ) {
            $args['body'] = $opts['body'];
        }
        if ( ! empty( $opts['cookies'] ) ) {
            $args['cookies'] = $opts['cookies'];
        }

        /**
         * Filter the request arguments the Link Growth Engine uses for outbound requests.
         *
         * @param array<string,mixed> $args
         * @param string              $url
         */
        $args = apply_filters( 'ppclg_http_request_args', $args, $url );

        $result  = wp_remote_request( $url, $args );
        $elapsed = microtime( true ) - $start;

        if ( is_wp_error( $result ) ) {
            $this->logger->debug( 'http', 'Request failed', [ 'url' => $url, 'error' => $result->get_error_message() ] );
            return Response::error( $url, $result->get_error_message(), (string) $result->get_error_code(), $elapsed );
        }

        $status  = (int) wp_remote_retrieve_response_code( $result );
        $hdrs    = [];
        $rawHdrs = wp_remote_retrieve_headers( $result );
        foreach ( ( is_object( $rawHdrs ) && method_exists( $rawHdrs, 'getAll' ) ) ? $rawHdrs->getAll() : (array) $rawHdrs as $k => $v ) {
            $hdrs[ strtolower( (string) $k ) ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
        }
        $finalUrl = $url;
        if ( isset( $result['http_response'] ) && is_object( $result['http_response'] ) && method_exists( $result['http_response'], 'get_response_object' ) ) {
            $ro = $result['http_response']->get_response_object();
            if ( is_object( $ro ) && isset( $ro->url ) && is_string( $ro->url ) && '' !== $ro->url ) {
                $finalUrl = Url::normalize( $ro->url ) ?: $url;
            }
        }
        $body    = (string) wp_remote_retrieve_body( $result );
        $cookies = [];
        foreach ( (array) wp_remote_retrieve_cookies( $result ) as $cookie ) {
            if ( is_object( $cookie ) && isset( $cookie->name ) ) {
                $cookies[ (string) $cookie->name ] = (string) ( $cookie->value ?? '' );
            }
        }
        return new Response( $status, $hdrs, $body, $url, $finalUrl, $elapsed, null, null, $cookies );
    }

    private function throttle( string $host ): void {
        if ( '' === $host ) {
            return;
        }
        $delay = (float) $this->settings->get( 'advanced.crawl_delay_seconds', 2 );
        $key   = 'ppclg_hit_' . md5( $host );
        $last  = $this->lastHit[ $host ] ?? (float) get_transient( $key );
        $wait  = $delay - ( microtime( true ) - $last );
        if ( $last > 0 && $wait > 0 && $wait <= 5 ) {
            usleep( (int) ( $wait * 1000000 ) );
        }
        $now                     = microtime( true );
        $this->lastHit[ $host ] = $now;
        set_transient( $key, $now, 60 );
    }
}
