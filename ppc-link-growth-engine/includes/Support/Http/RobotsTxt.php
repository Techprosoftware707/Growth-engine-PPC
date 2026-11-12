<?php
/**
 * robots.txt fetching, caching and matching (Google-style longest-match semantics).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Support\Http;

use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Url;

final class RobotsTxt {

    public const BOT_TOKEN = 'ppclinkgrowthbot';

    /**
     * Statuses that mean "robots.txt is temporarily unavailable": 5xx, 429 Too Many Requests and 408 Request
     * Timeout. Like Google, the crawler then treats the whole host as disallowed until it can re-read robots.txt.
     */
    private const RETRY_STATUSES = [ 408, 429 ];

    /** @var array<string,array{status:int,rules:array<string,array<int,array{allow:bool,path:string}>>,delay:?float,error?:?string,shortened?:bool}> */
    private array $memory = [];

    public function __construct( private Logger $logger ) {}

    /**
     * Whether our bot may fetch $url. Unavailable robots.txt (network error, 5xx, 429, 408) means "disallow for now".
     *
     * $shortened says $fetcher waits less than the normal robots.txt timeout (a caller short of time). If that
     * request times out, the failure is kept for this request only, never in the shared cache: a full-length
     * fetch may still succeed, so other callers are not blocked for an hour, and a later full-length call
     * fetches robots.txt again.
     */
    public function isAllowed( string $url, callable $fetcher, bool $shortened = false ): bool {
        $host = Url::host( $url );
        if ( '' === $host ) {
            return false;
        }
        $data = $this->load( $url, $fetcher, $shortened );
        if ( self::unavailable( $data['status'] ) ) {
            return false;
        }
        if ( 200 !== $data['status'] ) {
            return true; // 404 etc. => everything allowed.
        }
        $path = Url::path( $url );
        $q    = wp_parse_url( $url, PHP_URL_QUERY );
        if ( is_string( $q ) && '' !== $q ) {
            $path .= '?' . $q;
        }
        $rules = $data['rules'][ self::BOT_TOKEN ] ?? $data['rules']['*'] ?? [];
        return self::evaluate( $rules, $path );
    }

    public function crawlDelay( string $url, callable $fetcher ): ?float {
        $data = $this->load( $url, $fetcher );
        return $data['delay'];
    }

    /**
     * The Crawl-delay of $url's host when its robots.txt is already loaded (in this request or the transient
     * cache), without fetching anything; null when robots.txt is not loaded yet or sets no Crawl-delay.
     */
    public function knownCrawlDelay( string $url ): ?float {
        if ( '' === Url::host( $url ) ) {
            return null;
        }
        $data  = $this->cached( self::originKey( $url ) );
        $delay = null === $data ? null : ( $data['delay'] ?? null );
        return null === $delay ? null : (float) $delay;
    }

    /**
     * The transport error (DNS failure, connection refused, timeout...) that stopped robots.txt for $url's host
     * from being fetched, or null when the server answered (whatever the status).
     */
    public function fetchError( string $url, callable $fetcher, bool $shortened = false ): ?string {
        $data  = $this->load( $url, $fetcher, $shortened );
        $error = $data['error'] ?? null;
        return is_string( $error ) && '' !== $error ? $error : null;
    }

    private static function unavailable( int $status ): bool {
        return $status >= 500 || in_array( $status, self::RETRY_STATUSES, true );
    }

    /**
     * @param array<int,array{allow:bool,path:string}> $rules
     */
    public static function evaluate( array $rules, string $path ): bool {
        $best    = null;
        $bestLen = -1;
        foreach ( $rules as $rule ) {
            $pattern = $rule['path'];
            if ( '' === $pattern ) {
                continue;
            }
            if ( self::matches( $pattern, $path ) ) {
                $len = strlen( $pattern );
                if ( $len > $bestLen || ( $len === $bestLen && $rule['allow'] && $best && ! $best['allow'] ) ) {
                    $best    = $rule;
                    $bestLen = $len;
                }
            }
        }
        return null === $best ? true : $best['allow'];
    }

    public static function matches( string $pattern, string $path ): bool {
        $anchored = str_ends_with( $pattern, '$' );
        if ( $anchored ) {
            $pattern = substr( $pattern, 0, -1 );
        }
        $regex = '';
        foreach ( explode( '*', $pattern ) as $i => $part ) {
            if ( $i > 0 ) {
                $regex .= '.*';
            }
            $regex .= preg_quote( $part, '#' );
        }
        $regex = '#^' . $regex . ( $anchored ? '$' : '' ) . '#';
        return (bool) preg_match( $regex, $path );
    }

    /**
     * @return array{status:int,rules:array<string,array<int,array{allow:bool,path:string}>>,delay:?float,error?:?string,shortened?:bool}
     */
    private function load( string $url, callable $fetcher, bool $shortened = false ): array {
        $key    = self::originKey( $url );
        $cached = $this->cached( $key );
        // A timeout from a shortened fetch is reused by other shortened fetches only.
        if ( null !== $cached && ( $shortened || empty( $cached['shortened'] ) ) ) {
            return $cached;
        }
        $tkey = 'ppclg_robots_' . md5( $key );
        /** @var Response $resp */
        $resp = $fetcher( $key . '/robots.txt' );
        $data = [ 'status' => $resp->status, 'rules' => [], 'delay' => null, 'error' => null ];
        if ( $resp->error ) {
            $data['status'] = 503;
            $data['error']  = (string) $resp->error;
        } elseif ( 200 === $resp->status && $resp->body !== '' ) {
            $parsed        = self::parse( $resp->body );
            $data['rules'] = $parsed['rules'];
            $data['delay'] = $parsed['delay'];
        }
        if ( $shortened && null !== $data['error'] && preg_match( '/timed?\s?out|timeout/i', $data['error'] ) ) {
            $data['shortened']    = true;
            $this->memory[ $key ] = $data;
            return $data;
        }
        $ttl = DAY_IN_SECONDS;
        if ( self::unavailable( $data['status'] ) ) {
            // Re-check within the hour, but never before the host's Retry-After (capped at a day).
            $ttl = (int) min( DAY_IN_SECONDS, max( HOUR_IN_SECONDS, self::retryAfter( $resp ) ) );
        }
        set_transient( $tkey, $data, $ttl );
        $this->memory[ $key ] = $data;
        return $data;
    }

    /** "scheme://host" of $url: the key robots.txt data is cached under. */
    private static function originKey( string $url ): string {
        $scheme = (string) ( wp_parse_url( $url, PHP_URL_SCHEME ) ?: 'https' );
        return $scheme . '://' . Url::host( $url );
    }

    /**
     * robots.txt data already loaded for the origin $key (memory, then the transient cache), or null.
     *
     * @return array{status:int,rules:array<string,array<int,array{allow:bool,path:string}>>,delay:?float,error?:?string,shortened?:bool}|null
     */
    private function cached( string $key ): ?array {
        if ( isset( $this->memory[ $key ] ) ) {
            return $this->memory[ $key ];
        }
        $cached = get_transient( 'ppclg_robots_' . md5( $key ) );
        if ( is_array( $cached ) && isset( $cached['status'], $cached['rules'] ) ) {
            $this->memory[ $key ] = $cached;
            return $cached;
        }
        return null;
    }

    /** Seconds from a Retry-After header (delta-seconds or HTTP-date); 0 when absent or unparseable. */
    private static function retryAfter( Response $resp ): int {
        $value = trim( $resp->header( 'retry-after' ) );
        if ( '' === $value ) {
            return 0;
        }
        if ( ctype_digit( $value ) ) {
            return (int) min( (float) $value, (float) YEAR_IN_SECONDS );
        }
        $at = strtotime( $value );
        return false === $at ? 0 : max( 0, $at - time() );
    }

    /**
     * @return array{rules:array<string,array<int,array{allow:bool,path:string}>>,delay:?float}
     */
    public static function parse( string $body ): array {
        $rules  = [];
        $delay  = null;
        $agents = [];
        $fresh  = true;
        foreach ( preg_split( '/\r\n|\r|\n/', $body ) ?: [] as $line ) {
            $line = trim( preg_replace( '/#.*$/', '', $line ) ?? '' );
            if ( '' === $line || ! str_contains( $line, ':' ) ) {
                continue;
            }
            [ $field, $value ] = array_map( 'trim', explode( ':', $line, 2 ) );
            $field = strtolower( $field );
            if ( 'user-agent' === $field ) {
                if ( ! $fresh ) {
                    $agents = [];
                    $fresh  = true;
                }
                $agent = strtolower( $value );
                // Match our token by prefix (e.g. "PPCLinkGrowthBot/1.0").
                if ( str_starts_with( $agent, self::BOT_TOKEN ) ) {
                    $agent = self::BOT_TOKEN;
                }
                $agents[] = $agent;
                if ( ! isset( $rules[ $agent ] ) ) {
                    $rules[ $agent ] = [];
                }
                continue;
            }
            if ( empty( $agents ) ) {
                continue;
            }
            $fresh = false;
            if ( 'disallow' === $field || 'allow' === $field ) {
                foreach ( $agents as $a ) {
                    if ( '' === $value && 'disallow' === $field ) {
                        continue; // "Disallow:" (empty) = allow all.
                    }
                    $rules[ $a ][] = [ 'allow' => 'allow' === $field, 'path' => $value ];
                }
            } elseif ( 'crawl-delay' === $field && in_array( self::BOT_TOKEN, $agents, true ) || ( 'crawl-delay' === $field && in_array( '*', $agents, true ) && null === $delay ) ) {
                $delay = is_numeric( $value ) ? min( 30.0, (float) $value ) : null;
            }
        }
        return [ 'rules' => $rules, 'delay' => $delay ];
    }
}
