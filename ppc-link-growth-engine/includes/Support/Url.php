<?php
/**
 * URL helpers: normalisation, registrable domains, resolution.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Support;

final class Url {

    /** Multi-label public suffixes that matter for this project (compact list). */
    private const MULTI_SUFFIXES = [
        'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'net.uk',
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au',
        'co.nz', 'org.nz', 'net.nz', 'ac.nz', 'govt.nz',
        'co.za', 'org.za',
        'com.br', 'com.mx', 'com.ar', 'com.sg', 'com.hk', 'com.tw', 'co.jp', 'co.kr', 'co.in', 'co.il',
        'gc.ca', 'on.ca', 'qc.ca', 'bc.ca', 'ab.ca', 'mb.ca', 'sk.ca', 'ns.ca', 'nb.ca', 'nl.ca', 'pe.ca', 'yk.ca', 'nt.ca', 'nu.ca',
        'com.cn', 'net.cn', 'org.cn', 'edu.cn',
        'co.id', 'com.my', 'com.ph', 'com.vn', 'com.tr', 'com.ua', 'com.pl', 'org.pl',
        'github.io', 'netlify.app', 'vercel.app', 'wordpress.com', 'blogspot.com', 'wixsite.com', 'squarespace.com', 'weebly.com', 'tumblr.com', 'medium.com', 'substack.com',
    ];

    private const TRACKING_PARAMS = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid', 'ref', 'yclid' ];

    public static function normalize( string $url ): string {
        $url = trim( $url );
        if ( '' === $url ) {
            return '';
        }
        if ( ! preg_match( '#^[a-z][a-z0-9+\-.]*://#i', $url ) ) {
            $url = 'https://' . ltrim( $url, '/' );
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return '';
        }
        $scheme = strtolower( $parts['scheme'] ?? 'https' );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
            return '';
        }
        $host = strtolower( rtrim( $parts['host'], '.' ) );
        $port = isset( $parts['port'] ) ? (int) $parts['port'] : null;
        if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
            $port = null;
        }
        $path = $parts['path'] ?? '/';
        $path = preg_replace( '#/{2,}#', '/', $path ) ?? $path;
        if ( '' === $path ) {
            $path = '/';
        }
        $query = '';
        if ( ! empty( $parts['query'] ) ) {
            parse_str( $parts['query'], $params );
            foreach ( self::TRACKING_PARAMS as $tp ) {
                unset( $params[ $tp ] );
            }
            ksort( $params );
            $query = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
        }
        $out = $scheme . '://' . $host . ( $port ? ':' . $port : '' ) . $path;
        if ( '' !== $query ) {
            $out .= '?' . $query;
        }
        return $out;
    }

    public static function host( string $url ): string {
        $host = wp_parse_url( self::normalize( $url ), PHP_URL_HOST );
        return is_string( $host ) ? strtolower( $host ) : '';
    }

    /** Registrable domain: "sub.example.co.uk" => "example.co.uk"; strips leading www. */
    public static function registrableDomain( string $hostOrUrl ): string {
        $host = str_contains( $hostOrUrl, '/' ) || str_contains( $hostOrUrl, ':' ) ? self::host( $hostOrUrl ) : strtolower( trim( $hostOrUrl ) );
        $host = preg_replace( '/^www\./', '', $host ) ?? $host;
        if ( '' === $host || filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return $host;
        }
        $labels = explode( '.', $host );
        $n      = count( $labels );
        if ( $n <= 2 ) {
            return $host;
        }
        $lastTwo = $labels[ $n - 2 ] . '.' . $labels[ $n - 1 ];
        if ( in_array( $lastTwo, self::MULTI_SUFFIXES, true ) ) {
            return $labels[ $n - 3 ] . '.' . $lastTwo;
        }
        return $lastTwo;
    }

    public static function isSameSite( string $a, string $b ): bool {
        return self::registrableDomain( $a ) !== '' && self::registrableDomain( $a ) === self::registrableDomain( $b );
    }

    public static function absolutize( string $href, string $base ): string {
        $href = trim( $href );
        if ( '' === $href || str_starts_with( $href, '#' ) ) {
            return '';
        }
        if ( preg_match( '#^(javascript|mailto|tel|data|ftp|sms):#i', $href ) ) {
            return '';
        }
        if ( preg_match( '#^[a-z][a-z0-9+\-.]*://#i', $href ) ) {
            return self::normalize( $href );
        }
        $b = wp_parse_url( $base );
        if ( ! is_array( $b ) || empty( $b['host'] ) ) {
            return '';
        }
        $scheme = $b['scheme'] ?? 'https';
        $host   = $b['host'];
        $port   = isset( $b['port'] ) ? ':' . $b['port'] : '';
        if ( str_starts_with( $href, '//' ) ) {
            return self::normalize( $scheme . ':' . $href );
        }
        if ( str_starts_with( $href, '/' ) ) {
            return self::normalize( $scheme . '://' . $host . $port . $href );
        }
        if ( str_starts_with( $href, '?' ) ) {
            return self::normalize( $scheme . '://' . $host . $port . ( $b['path'] ?? '/' ) . $href );
        }
        $path = $b['path'] ?? '/';
        $dir  = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );
        $full = $dir . $href;
        // Resolve ./ and ../ segments.
        $segments = [];
        foreach ( explode( '/', $full ) as $seg ) {
            if ( '.' === $seg || '' === $seg ) {
                continue;
            }
            if ( '..' === $seg ) {
                array_pop( $segments );
                continue;
            }
            $segments[] = $seg;
        }
        return self::normalize( $scheme . '://' . $host . $port . '/' . implode( '/', $segments ) );
    }

    public static function hash( string $url ): string {
        return sha1( self::normalize( $url ) );
    }

    public static function looksLikeHtml( string $url ): bool {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        return ! preg_match( '/\.(pdf|jpe?g|png|gif|webp|svg|zip|gz|rar|7z|mp3|mp4|avi|mov|docx?|xlsx?|pptx?|csv|xml|json|css|js|ico|woff2?|ttf)$/i', $path );
    }

    public static function belongsTo( string $url, string $domain ): bool {
        $domain = strtolower( preg_replace( '/^www\./', '', trim( $domain ) ) ?? '' );
        if ( '' === $domain ) {
            return false;
        }
        $host = self::host( $url );
        return $host === $domain || str_ends_with( $host, '.' . $domain );
    }

    public static function path( string $url ): string {
        $p = wp_parse_url( $url, PHP_URL_PATH );
        return is_string( $p ) ? $p : '/';
    }

    public static function isCanadianTld( string $hostOrUrl ): bool {
        $host = str_contains( $hostOrUrl, '/' ) ? self::host( $hostOrUrl ) : strtolower( $hostOrUrl );
        return str_ends_with( $host, '.ca' );
    }
}
