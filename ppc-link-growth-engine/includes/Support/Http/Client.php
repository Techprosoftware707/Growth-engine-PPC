<?php
/**
 * Polite HTTP client on top of the WordPress HTTP API.
 *
 *  - Identifies itself with a descriptive user agent.
 *  - Respects robots.txt for crawling and link probes, on every redirect hop (never for API hosts).
 *  - Per-host crawl delay (the setting or the host's robots.txt Crawl-delay, whichever is longer),
 *    response size cap, timeouts.
 *  - Refuses private/loopback targets via wp_http_validate_url (SSRF protection).
 *  - Inside a background job (JobRunner::execute() / runType() call within()), crawls, probes and API reads end
 *    before the job's time budget does: each timeout is capped to the time left, a crawl delay that does not fit
 *    is not waited out, and a request that cannot fit is not sent (error code DEADLINE, "try again later").
 *    Measured before 1.2.4: one evaluation ran 60.06 s (Crawl-delay: 30) and 42.06 s (a host answering in 14 s per
 *    page) against its 20 s budget, holding a PHP worker the whole time. Form POSTs, other POSTs and partner
 *    submission calls (api() with $bounded false) are never refused or cut short: a request whose answer we stop
 *    waiting for may still have been received.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Support\Http;

use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Support\Url;

final class Client {

    /**
     * Longest pause before one request to a host: the ceiling of the crawl-delay setting (the settings API
     * clamps it to 0..60 s; robots.txt Crawl-delay is capped at 30 s when parsed).
     */
    private const MAX_DELAY_SECONDS = 60.0;

    /** Redirects followed by crawl() and probe() when the caller does not say otherwise. */
    private const MAX_REDIRECTS = 4;

    /** robots.txt fetch timeout (seconds) unless the caller's own timeout, or crawl()'s robots_timeout, is shorter. */
    private const ROBOTS_TIMEOUT = 8;

    /** Error code of a request this client did not send (or cut short) because the job's time budget ran out. */
    public const DEADLINE = 'deadline';

    public const DEADLINE_MESSAGE = 'Not requested: the time budget of this run is used up (it is tried again on a later run).';

    /** Least time a crawl, probe or API read needs to be worth starting under a deadline (seconds). */
    private const MIN_REQUEST_SECONDS = 3.0;

    /** Kept free before the deadline for the job's own bookkeeping (seconds). */
    private const DEADLINE_MARGIN = 1.0;

    /** microtime() by which bounded requests must be over (set by within()), or null. */
    private ?float $deadline = null;

    /** Requests refused or cut short by the deadline in this request (callers compare before/after). */
    private int $deadlineMisses = 0;

    /** Whether the last response this client returned was a DEADLINE one (see lastWasDeadline()). */
    private bool $lastDeadline = false;

    /** Least time a bounded request (a redirect hop, the page after its robots.txt) is still sent with (seconds). */
    private const MIN_HOP_SECONDS = 2.0;

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
     * Runs $fn with every crawl, probe and API read (GET/HEAD) of this client bounded by $budget (and by an outer
     * deadline already set, whichever ends first). Returns what $fn returns.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function within( TimeBudget $budget, callable $fn ): mixed {
        $previous       = $this->deadline;
        $at             = microtime( true ) + $budget->remaining();
        $this->deadline = null === $previous ? $at : min( $previous, $at );
        try {
            return $fn();
        } finally {
            $this->deadline = $previous;
        }
    }

    /** Seconds a bounded request may still use (deadline minus the margin), or null outside within(). */
    public function timeLeft(): ?float {
        return null === $this->deadline ? null : $this->deadline - microtime( true ) - self::DEADLINE_MARGIN;
    }

    /** How many requests the deadline refused or cut short so far in this PHP request. */
    public function deadlineMisses(): int {
        return $this->deadlineMisses;
    }

    /**
     * Whether the most recent request answered DEADLINE (not sent, or our shortened timeout ran out). Providers ask
     * this before recording a failure: a request the run had no time for says nothing about the provider.
     */
    public function lastWasDeadline(): bool {
        return $this->lastDeadline;
    }

    /**
     * Crawl a third-party page: robots-aware, throttled, HTML-oriented.
     *
     * Redirects are followed here, one hop at a time, so each target is checked against its own host's
     * robots.txt and crawl delay before it is requested.
     *
     * robots_timeout caps the robots.txt request (3..8 s, default 8) for a host whose robots.txt is not cached
     * yet, so a caller with a time budget can fit robots.txt plus the page into what it has left. When such a
     * shortened request times out, the failure is kept for this request only (see RobotsTxt::isAllowed()).
     *
     * @param array<string,mixed> $opts { allow_robots_skip?: bool, timeout?: int, robots_timeout?: int, cache?: bool, redirection?: int }
     */
    public function crawl( string $url, array $opts = [] ): Response {
        $this->lastDeadline = false;
        $url = Url::normalize( $url );
        if ( '' === $url ) {
            return Response::error( $url, 'Invalid URL.', 'invalid_url' );
        }
        if ( ! empty( $opts['cache'] ) && isset( $this->cache[ $url ] ) ) {
            return $this->cache[ $url ];
        }
        if ( ! $this->fits( self::MIN_REQUEST_SECONDS ) ) {
            return $this->refuse( $url );
        }
        $opts['_bounded'] = true;
        $robots           = empty( $opts['allow_robots_skip'] );
        if ( $robots ) {
            $wanted  = $this->robotsTimeout( $opts );
            $blocked = $this->robotsGate( $url, $this->cap( $wanted ), false, $this->cap( $wanted ) < $wanted );
            if ( null !== $blocked ) {
                return $blocked;
            }
        }
        if ( ! $this->throttle( Url::host( $url ), $robots ? $this->robotsDelay( $url ) : 0.0 ) ) {
            return $this->refuse( $url );
        }
        $resp = $this->follow( 'GET', $url, $opts, $robots, false );
        if ( ! empty( $opts['cache'] ) && self::DEADLINE !== $resp->errorCode ) {
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
        $this->lastDeadline = false;
        $url = Url::normalize( $url );
        if ( '' === $url ) {
            return Response::error( $url, 'Invalid URL.', 'invalid_url' );
        }
        if ( ! $this->fits( self::MIN_REQUEST_SECONDS ) ) {
            return $this->refuse( $url );
        }
        $opts['_bounded'] = true;
        $timeout          = (int) ( $opts['timeout'] ?? 10 );
        $blocked          = $this->robotsGate( $url, $this->cap( $timeout ), true, $this->cap( $timeout ) < $timeout );
        if ( null !== $blocked ) {
            return $blocked;
        }
        if ( ! $this->throttle( Url::host( $url ), $this->robotsDelay( $url ) ) ) {
            return $this->refuse( $url );
        }
        $resp = $this->follow( 'HEAD', $url, array_merge( [ 'timeout' => 10 ], $opts ), true, true );
        if ( in_array( (string) $resp->errorCode, [ 'robots_disallow', 'robots_unavailable', self::DEADLINE ], true ) ) {
            return $resp; // a redirect led to a URL our bot may not fetch, or the time is up
        }
        if ( $resp->error || in_array( $resp->status, [ 403, 405, 501 ], true ) ) {
            if ( ! $this->fits( self::MIN_REQUEST_SECONDS ) || ! $this->throttle( Url::host( $url ), $this->robotsDelay( $url ) ) ) {
                return $this->refuse( $url );
            }
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
    public function api( string $method, string $url, array $headers = [], array|string|null $body = null, int $timeout = 20, bool $bounded = true ): Response {
        $this->lastDeadline = false;
        $opts = [ 'headers' => $headers, 'timeout' => $timeout, 'limit_kb' => 8192 ];
        if ( $bounded && in_array( strtoupper( $method ), [ 'GET', 'HEAD' ], true ) ) {
            // Reads only: a POST (token exchange, IndexNow, a paid generation call) is never refused or cut short, nor
            // is a call made with $bounded false (a partner submission API, whatever its method: not idempotent).
            if ( ! $this->fits( self::MIN_REQUEST_SECONDS ) ) {
                return $this->refuse( $url );
            }
            $opts['_bounded'] = true;
        }
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
        $this->lastDeadline = false;
        $this->throttle( Url::host( $url ), 0.0, false ); // never cut short: see the class comment
        return $this->rawRequest( 'POST', $url, [ 'body' => $fields, 'headers' => $headers, 'cookies' => $cookies, 'timeout' => 25 ] );
    }

    /**
     * @param array<string,mixed> $opts
     */
    public function rawRequest( string $method, string $url, array $opts = [] ): Response {
        $this->lastDeadline = false;
        $start   = microtime( true );
        $timeout = (int) ( $opts['timeout'] ?? $this->settings->get( 'advanced.request_timeout', 15 ) );
        $limitKb = (int) ( $opts['limit_kb'] ?? $this->settings->get( 'advanced.max_response_kb', 1536 ) );
        $capped  = false;
        if ( ! empty( $opts['_bounded'] ) && null !== $this->deadline ) {
            if ( ! $this->fits( self::MIN_HOP_SECONDS ) ) {
                return $this->refuse( $url );
            }
            $bounded = $this->cap( max( 3, $timeout ) );
            $capped  = $bounded < max( 3, $timeout );
            $timeout = $bounded;
        }
        unset( $opts['_bounded'] );
        $headers = array_merge(
            [
                'Accept'          => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-CA,en;q=0.9',
            ],
            (array) ( $opts['headers'] ?? [] )
        );

        $args = [
            'method'              => $method,
            'timeout'             => $capped ? max( 1, $timeout ) : max( 3, $timeout ),
            'redirection'         => (int) ( $opts['redirection'] ?? 4 ),
            'user-agent'          => $this->settings->userAgent(),
            'headers'             => $headers,
            'sslverify'           => array_key_exists( 'sslverify', $opts ) ? (bool) $opts['sslverify'] : true,
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
            if ( $capped && preg_match( '/timed?\s?out|timeout/i', $result->get_error_message() ) ) {
                // Our shortened timeout ran out, not the host's normal one: "try again later", never "unreachable".
                $this->deadlineMisses++;
                $this->lastDeadline = true;
                return Response::error( $url, $result->get_error_message(), self::DEADLINE, $elapsed );
            }
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
            if ( ! empty( $opts['_bounded'] ) && ! $this->fits( self::MIN_REQUEST_SECONDS ) ) {
                return $this->refuse( $url ); // no time left for the redirect target (and its robots.txt)
            }
            if ( $checkRobots ) {
                $wanted  = min( $this->robotsTimeout( $opts ), $timeout );
                $robotsT = ! empty( $opts['_bounded'] ) ? $this->cap( $wanted ) : $wanted;
                $blocked = $this->robotsGate( $next, $robotsT, $allowUnreachable, $robotsT < $wanted );
                if ( null !== $blocked && self::DEADLINE === $blocked->errorCode ) {
                    return $blocked;
                }
                if ( null !== $blocked ) {
                    $this->logger->debug( 'http', 'Redirect target blocked by robots.txt', [ 'url' => $url, 'target' => $next ] );
                    return new Response( 0, [], '', $url, $next, microtime( true ) - $start, (string) $blocked->error, (string) $blocked->errorCode );
                }
            }
            if ( ! $this->throttle( Url::host( $next ), $checkRobots ? $this->robotsDelay( $next ) : 0.0 ) ) {
                return $this->refuse( $url );
            }
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
    private function robotsGate( string $url, int $timeout, bool $allowUnreachable, bool $deadlineCapped = false ): ?Response {
        $fetcher = $this->robotsFetcher( $timeout );
        // A robots.txt request shorter than ROBOTS_TIMEOUT that times out is not cached for other callers.
        $shortened = self::robotsFetchTimeout( $timeout ) < self::ROBOTS_TIMEOUT;
        if ( $this->robots->isAllowed( $url, $fetcher, $shortened ) ) {
            return null;
        }
        $error = $this->robots->fetchError( $url, $fetcher, $shortened );
        if ( null !== $error && $deadlineCapped && preg_match( '/timed?\s?out|timeout/i', $error ) ) {
            return $this->refuse( $url ); // robots.txt only ran out of the time this run had left: not a "disallow"
        }
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

    /**
     * Seconds the client waits between two requests to $url's host: the crawl-delay setting, or the host's
     * robots.txt Crawl-delay when that is longer and the host's robots.txt is already loaded (it is never
     * fetched here). Lets a caller keep a series of requests to one host inside its time budget.
     */
    public function delayFor( string $url ): float {
        return $this->delaySeconds( (float) ( $this->robots->knownCrawlDelay( Url::normalize( $url ) ?: $url ) ?? 0.0 ) );
    }

    /** Seconds the client waits between two requests to any host: the crawl-delay setting (delayFor() can be longer). */
    public function minDelay(): float {
        return $this->delaySeconds( 0.0 );
    }

    /**
     * Seconds the client would wait now before its next request to $url's host: what is left of delayFor()
     * since the host was last requested, 0 when that was longer ago or never. Never fetches robots.txt.
     */
    public function pendingWait( string $url ): float {
        $host = Url::host( Url::normalize( $url ) ?: $url );
        if ( '' === $host ) {
            return 0.0;
        }
        $delay = $this->delayFor( $url );
        $last  = $this->lastHitAt( $host );
        if ( $last <= 0 || $delay <= 0 ) {
            return 0.0;
        }
        return max( 0.0, min( $delay, $delay - ( microtime( true ) - $last ) ) );
    }

    /** robots.txt request timeout asked for by the caller (crawl()'s robots_timeout), else the default. */
    private function robotsTimeout( array $opts ): int {
        return isset( $opts['robots_timeout'] ) ? (int) $opts['robots_timeout'] : self::ROBOTS_TIMEOUT;
    }

    /** The host's robots.txt Crawl-delay in seconds (0 when none). Uses the robots.txt already loaded for the gate. */
    private function robotsDelay( string $url ): float {
        return (float) ( $this->robots->crawlDelay( $url, $this->robotsFetcher( self::ROBOTS_TIMEOUT ) ) ?? 0.0 );
    }

    private function robotsFetcher( int $timeout ): \Closure {
        $timeout = self::robotsFetchTimeout( $timeout );
        return fn( string $u ): Response => $this->rawRequest( 'GET', $u, [ 'timeout' => $timeout ] );
    }

    /** The timeout a robots.txt request asked for with $timeout really gets: 3..ROBOTS_TIMEOUT seconds. */
    private static function robotsFetchTimeout( int $timeout ): int {
        return max( 3, min( self::ROBOTS_TIMEOUT, $timeout ) );
    }

    /**
     * Waits until at least the per-host delay has passed since the previous request to $host: the larger of
     * the advanced.crawl_delay_seconds setting and the host's robots.txt Crawl-delay. The whole remaining
     * delay is waited (up to MAX_DELAY_SECONDS); a request is never sent early.
     *
     * Under a deadline ($bounded, inside within()) a wait that would leave less than MIN_REQUEST_SECONDS for the
     * request is not started: false is returned, nothing is recorded, and the caller does not send the request
     * (the host is asked again on a later run, still after its whole delay).
     */
    private function throttle( string $host, float $robotsDelay = 0.0, bool $bounded = true ): bool {
        if ( '' === $host ) {
            return true;
        }
        $delay = $this->delaySeconds( $robotsDelay );
        $last  = $this->lastHitAt( $host );
        if ( $last > 0 && $delay > 0 ) {
            // min(): a timestamp from the future (another server's clock) never makes the wait longer than the delay.
            $wait = min( $delay, $delay - ( microtime( true ) - $last ) );
            if ( $wait > 0 ) {
                if ( $bounded && ! $this->fits( $wait + self::MIN_REQUEST_SECONDS ) ) {
                    return false;
                }
                $this->pause( $wait );
            }
        }
        $now                     = microtime( true );
        $this->lastHit[ $host ] = $now;
        set_transient( 'ppclg_hit_' . md5( $host ), $now, max( 60, (int) ceil( $delay ) + 5 ) );
        return true;
    }

    /** Whether $seconds still fit before the deadline (always true outside within()). */
    private function fits( float $seconds ): bool {
        $left = $this->timeLeft();
        return null === $left || $left >= $seconds;
    }

    /** $timeout (seconds) capped to the time left before the deadline (at least 1), unchanged outside within(). */
    private function cap( int $timeout ): int {
        $left = $this->timeLeft();
        if ( null === $left ) {
            return $timeout;
        }
        return max( 1, min( $timeout, (int) floor( $left ) ) );
    }

    /** The answer for a request the deadline does not let us send. */
    private function refuse( string $url ): Response {
        $this->deadlineMisses++;
        $this->lastDeadline = true;
        $this->logger->debug( 'http', 'Request not sent: the time budget of this run is used up.', [ 'url' => $url ] );
        return Response::error( $url, self::DEADLINE_MESSAGE, self::DEADLINE );
    }

    /** When $host was last requested (this request, or another one within its delay), 0.0 when unknown. */
    private function lastHitAt( string $host ): float {
        return $this->lastHit[ $host ] ?? (float) get_transient( 'ppclg_hit_' . md5( $host ) );
    }

    /** The per-host delay: the larger of the crawl-delay setting and $robotsDelay, at most MAX_DELAY_SECONDS. */
    private function delaySeconds( float $robotsDelay ): float {
        return min( self::MAX_DELAY_SECONDS, max( 0.0, (float) $this->settings->get( 'advanced.crawl_delay_seconds', 2 ), $robotsDelay ) );
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
