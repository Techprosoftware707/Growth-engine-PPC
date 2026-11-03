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

    /** @var array<string,array{status:int,rules:array<string,array<int,array{allow:bool,path:string}>>,delay:?float}> */
    private array $memory = [];

    public function __construct( private Logger $logger ) {}

    /**
     * Whether our bot may fetch $url. 5xx robots responses are treated as "disallow for now".
     */
    public function isAllowed( string $url, callable $fetcher ): bool {
        $host = Url::host( $url );
        if ( '' === $host ) {
            return false;
        }
        $data = $this->load( $url, $fetcher );
        if ( $data['status'] >= 500 ) {
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
     * @return array{status:int,rules:array<string,array<int,array{allow:bool,path:string}>>,delay:?float}
     */
    private function load( string $url, callable $fetcher ): array {
        $host   = Url::host( $url );
        $scheme = (string) ( wp_parse_url( $url, PHP_URL_SCHEME ) ?: 'https' );
        $key    = $scheme . '://' . $host;
        if ( isset( $this->memory[ $key ] ) ) {
            return $this->memory[ $key ];
        }
        $tkey   = 'ppclg_robots_' . md5( $key );
        $cached = get_transient( $tkey );
        if ( is_array( $cached ) && isset( $cached['status'], $cached['rules'] ) ) {
            $this->memory[ $key ] = $cached;
            return $cached;
        }
        /** @var Response $resp */
        $resp = $fetcher( $key . '/robots.txt' );
        $data = [ 'status' => $resp->status, 'rules' => [], 'delay' => null ];
        if ( $resp->error ) {
            $data['status'] = 503;
        } elseif ( 200 === $resp->status && $resp->body !== '' ) {
            $parsed        = self::parse( $resp->body );
            $data['rules'] = $parsed['rules'];
            $data['delay'] = $parsed['delay'];
        }
        $ttl = $data['status'] >= 500 ? HOUR_IN_SECONDS : DAY_IN_SECONDS;
        set_transient( $tkey, $data, $ttl );
        $this->memory[ $key ] = $data;
        return $data;
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
