<?php
/**
 * Polite HTTP client on top of the WordPress HTTP API.
 *
 *  - Identifies itself with a descriptive user agent.
 *  - Respects robots.txt for crawling and link probes, on every redirect hop (never for API hosts).
 *  - Per-host crawl delay (the setting or the host's robots.txt Crawl-delay, whichever is longer),
 *    response size cap, timeouts.
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

    /**
     * Longest pause before one request to a host: the ceiling of the crawl-delay setting (the settings API
     * clamps it to 0..60 s; robots.txt Crawl-delay is capped at 30 s when parsed).
     */
    private const MAX_DELAY_SECONDS = 60.0;

    /** Redirects followed by crawl() and probe() when the caller does not say otherwise. */
    private const MAX_REDIRECTS = 4;

    /** robots.txt fetch timeout (seconds) unless the caller's own timeout is shorter. */
    private const ROBOTS_TIMEOUT = 8;

    /**
     * Transport errors that prove no web server answers on the host (DNS failure, connection refused).
     * Timeouts are excluded: a slow server may still be there.
     */
    private const UNREACHABLE_ERROR = '/could not resolve|no such host|name or service not known|name not resolved|nodename nor servname|connection refused/i';

    /** @var array<string,float> host => last request time */
    private array $lastHit = [];

    /** @var array<string,Response> short-lived in-request cache */
    private array $cache = [];

    /**
     * @param (\Closure(float):void)|null $sleeper Waits the given number of seconds (default: usleep). Tests pass a recorder.
     */
    public function __construct(
        private Settings $settings,
        private Logger $logger,
        private RobotsTxt $robots,
        private ?\Closure $sleeper = null
    ) {}

    /**
     * Crawl a third-party page: robots-aware, throttled, HTML-oriented.
     *
     * Redirects are followed here, one hop at a time, so each target is checked against its own host's
     * robots.txt and crawl delay before it is requested.
     *
     * @param array<string,mixed> $opts { allow_robots_skip?: bool, timeout?: int, cache?: bool, redirection?: int }
     */
    public function crawl( string $url, array $opts = [] ): Response {
        $url = Url::normalize( $url );
        if ( '' === $url ) {
            return Response::error( $url, 'Invalid URL.', 'invalid_url' );
        }
        if ( ! empty( $opts['cache'] ) && isset( $this->cache[ $url ] ) ) {
            return $this->cache[ $url ];
        }
        $robots = empty( $opts['allow_robots_skip'] );
        if ( $robots ) {
            $blocked = $this->robotsGate( $url, self::ROBOTS_TIMEOUT, false );
            if ( null !== $blocked ) {
                return $blocked;
            }
        }
        $this->throttle( Url::host( $url ), $robots ? $this->robotsDelay( $url ) : 0.0 );
        $resp = $this->follow( 'GET', $url, $opts, $robots, false );
        if ( ! empty( $opts['cache'] ) ) {
            $this->cache[ $url ] = $resp;
        }
        return $resp;
    }

    /**
     * HEAD-ish check for broken links (falls back to GET when HEAD is refused).
     *
     * robots.txt applies here too: a URL our bot may not fetch is not requested. It comes back as a
     * "robots_disallow" error (Disallow rule, or robots.txt answered 5xx/429/408) or a "robots_unavailable"
     * error (the robots.txt request failed on the network), which link checks must treat as unknown, never as
     * broken. The one exception is a host whose robots.txt could not be fetched because the name does not
     * resolve or the connection is refused: no web server answers there, so the probe still runs to record
     * that failure for itself (and a confirming probe re-tries it).
     */
    public function probe( string $url, array $opts = [] ): Response {
        $url = Url::normalize( $url );
        if ( '' === $url ) {
            return Response::error( $url, 'Invalid URL.', 'invalid_url' );
        }
        $timeout = (int) ( $opts['timeout'] ?? 10 );
        $blocked = $this->robotsGate( $url, $timeout, true );
        if ( null !== $blocked ) {
            return $blocked;
        }
        $this->throttle( Url::host( $url ), $this->robotsDelay( $url ) );
        $resp = $this->follow( 'HEAD', $url, array_merge( [ 'timeout' => 10 ], $opts ), true, true );
        if ( in_array( (string) $resp->errorCode, [ 'robots_disallow', 'robots_unavailable' ], true ) ) {
            return $resp; // a redirect led to a URL our bot may not fetch
        }
        if ( $resp->error || in_array( $resp->status, [ 403, 405, 501 ], true ) ) {
            $this->throttle( Url::host( $url ), $this->robotsDelay( $url ) );
            $resp = $this->follow( 'GET', $url, array_merge( [ 'timeout' => 10, 'limit_kb' => 64 ], $opts ), true, true );
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

    /**
     * Requests $url, following redirects one hop at a time (at most $opts['redirection'], default 4). With
     * $checkRobots each redirect target must pass its host's robots.txt and waits for its crawl delay first;
     * a disallowed target is not requested. Cookies set along the way are sent again only to the host that
     * set them; the final response carries the cookies of its own host.
     *
     * @param array<string,mixed> $opts
     */
    private function follow( string $method, string $url, array $opts, bool $checkRobots, bool $allowUnreachable ): Response {
        $max     = max( 0, (int) ( $opts['redirection'] ?? self::MAX_REDIRECTS ) );
        $timeout = (int) ( $opts['timeout'] ?? $this->settings->get( 'advanced.request_timeout', 15 ) );
        $jar     = [ Url::host( $url ) => (array) ( $opts['cookies'] ?? [] ) ]; // host => cookies
        $start   = microtime( true );
        $current = $url;
        $hops    = 0;
        while ( true ) {
            $host    = Url::host( $current );
            $hopOpts = array_merge( $opts, [ 'redirection' => 0, 'cookies' => $jar[ $host ] ?? [] ] );
            if ( $hops > 0 ) {
                $hopOpts['timeout'] = max( 3, $timeout - (int) floor( microtime( true ) - $start ) );
            }
            $resp         = $this->rawRequest( $method, $current, $hopOpts );
            $jar[ $host ] = array_merge( $jar[ $host ] ?? [], $resp->cookies );
            $next         = '';
            if ( null === $resp->error && $resp->status >= 300 && $resp->status < 400 && '' !== $resp->header( 'location' ) ) {
                $next = Url::absolutize( $resp->header( 'location' ), $current );
            }
            if ( '' === $next || $hops >= $max ) {
                break;
            }
            $hops++;
            if ( $checkRobots ) {
                $blocked = $this->robotsGate( $next, min( self::ROBOTS_TIMEOUT, $timeout ), $allowUnreachable );
                if ( null !== $blocked ) {
                    $this->logger->debug( 'http', 'Redirect target blocked by robots.txt', [ 'url' => $url, 'target' => $next ] );
                    return new Response( 0, [], '', $url, $next, microtime( true ) - $start, (string) $blocked->error, (string) $blocked->errorCode );
                }
            }
            $this->throttle( Url::host( $next ), $checkRobots ? $this->robotsDelay( $next ) : 0.0 );
            $current = $next;
        }
        if ( $current === $url && 0 === $hops ) {
            return $resp;
        }
        return new Response( $resp->status, $resp->headers, $resp->body, $url, $current, microtime( true ) - $start, $resp->error, $resp->errorCode, $jar[ Url::host( $current ) ] ?? [] );
    }

    /**
     * null when robots.txt lets our bot fetch $url, otherwise the error response to return instead.
     * With $allowUnreachable a host whose robots.txt failed with a DNS / connection-refused error passes
     * (see probe()).
     */
    private function robotsGate( string $url, int $timeout, bool $allowUnreachable ): ?Response {
        $fetcher = $this->robotsFetcher( $timeout );
        if ( $this->robots->isAllowed( $url, $fetcher ) ) {
            return null;
        }
        $error = $this->robots->fetchError( $url, $fetcher );
        if ( null !== $error ) {
            if ( $allowUnreachable && preg_match( self::UNREACHABLE_ERROR, $error ) && ! preg_match( '/timed?\s?out|timeout/i', $error ) ) {
                return null;
            }
            $this->logger->debug( 'http', 'robots.txt unavailable', [ 'url' => $url, 'error' => $error ] );
            return Response::error( $url, 'robots.txt could not be fetched (' . $error . '); not crawled for now.', $allowUnreachable ? 'robots_unavailable' : 'robots_disallow' );
        }
        $this->logger->debug( 'http', 'Blocked by robots.txt', [ 'url' => $url ] );
        return Response::error( $url, 'Disallowed by robots.txt.', 'robots_disallow' );
    }

    /** The host's robots.txt Crawl-delay in seconds (0 when none). Uses the robots.txt already loaded for the gate. */
    private function robotsDelay( string $url ): float {
        return (float) ( $this->robots->crawlDelay( $url, $this->robotsFetcher( self::ROBOTS_TIMEOUT ) ) ?? 0.0 );
    }

    private function robotsFetcher( int $timeout ): \Closure {
        $timeout = max( 3, min( self::ROBOTS_TIMEOUT, $timeout ) );
        return fn( string $u ): Response => $this->rawRequest( 'GET', $u, [ 'timeout' => $timeout ] );
    }

    /**
     * Waits until at least the per-host delay has passed since the previous request to $host: the larger of
     * the advanced.crawl_delay_seconds setting and the host's robots.txt Crawl-delay. The whole remaining
     * delay is waited (up to MAX_DELAY_SECONDS); a request is never sent early.
     */
    private function throttle( string $host, float $robotsDelay = 0.0 ): void {
        if ( '' === $host ) {
            return;
        }
        $delay = min( self::MAX_DELAY_SECONDS, max( 0.0, (float) $this->settings->get( 'advanced.crawl_delay_seconds', 2 ), $robotsDelay ) );
        $key   = 'ppclg_hit_' . md5( $host );
        $last  = $this->lastHit[ $host ] ?? (float) get_transient( $key );
        if ( $last > 0 && $delay > 0 ) {
            // min(): a timestamp from the future (another server's clock) never makes the wait longer than the delay.
            $wait = min( $delay, $delay - ( microtime( true ) - $last ) );
            if ( $wait > 0 ) {
                $this->pause( $wait );
            }
        }
        $now                     = microtime( true );
        $this->lastHit[ $host ] = $now;
        set_transient( $key, $now, max( 60, (int) ceil( $delay ) + 5 ) );
    }

    private function pause( float $seconds ): void {
        if ( null !== $this->sleeper ) {
            ( $this->sleeper )( $seconds );
            return;
        }
        // usleep() above one second is not portable: whole seconds go through sleep().
        $whole = (int) floor( $seconds );
        if ( $whole > 0 ) {
            sleep( $whole );
        }
        $rest = (int) round( ( $seconds - $whole ) * 1000000 );
        if ( $rest > 0 ) {
            usleep( min( 999999, $rest ) );
        }
    }
}
