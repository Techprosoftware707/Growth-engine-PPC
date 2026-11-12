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

    /**
     * Pure analytics/click-id parameters dropped by normalize(). "ref" is not listed: sites use it functionally
     * (branch, referrer program, directory section), so removing it can fetch a different page.
     */
    private const TRACKING_PARAMS = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid', 'yclid' ];

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
        $query = isset( $parts['query'] ) ? self::normalizeQuery( (string) $parts['query'] ) : '';
        $out = $scheme . '://' . $host . ( $port ? ':' . $port : '' ) . $path;
        if ( '' !== $query ) {
            $out .= '?' . $query;
        }
        return $out;
    }

    /**
     * Canonical query string: tracking parameters dropped, pairs sorted by key, each key and value
     * re-encoded (RFC 3986). The pairs are split by hand rather than with parse_str(), which renames keys
     * ("cat.id" becomes "cat_id"), adds "=" to bare keys, keeps only the last of repeated keys and re-indexes
     * "tags[]", so the server would receive a different query.
     */
    private static function normalizeQuery( string $query ): string {
        $pairs = [];
        foreach ( explode( '&', $query ) as $piece ) {
            if ( '' === $piece ) {
                continue;
            }
            $eq  = strpos( $piece, '=' );
            $key = false === $eq ? $piece : substr( $piece, 0, $eq );
            if ( in_array( urldecode( $key ), self::TRACKING_PARAMS, true ) ) {
                continue;
            }
            $key     = rawurlencode( urldecode( $key ) );
            $pairs[] = [ $key, false === $eq ? $key : $key . '=' . rawurlencode( urldecode( substr( $piece, $eq + 1 ) ) ) ];
        }
        // usort() is stable (PHP 8), so repeated keys keep their order.
        usort( $pairs, static fn( array $a, array $b ): int => strcmp( $a[0], $b[0] ) );
        return implode( '&', array_column( $pairs, 1 ) );
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
        // The query (and fragment) of a relative reference is not part of its path.
        $suffix = '';
        $cut    = strcspn( $href, '?#' );
        if ( $cut < strlen( $href ) ) {
            $suffix = substr( $href, $cut );
            $href   = substr( $href, 0, $cut );
        }
        $path = $b['path'] ?? '/';
        $dir  = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );
        $full = $dir . $href;
        // Resolve ./ and ../ segments.
        $segments = [];
        $parts    = explode( '/', $full );
        foreach ( $parts as $seg ) {
            if ( '.' === $seg || '' === $seg ) {
                continue;
            }
            if ( '..' === $seg ) {
                array_pop( $segments );
                continue;
            }
            $segments[] = $seg;
        }
        // RFC 3986 5.2.4: "submit/", "./" and ".." resolve to a directory, so the trailing slash stays.
        $last     = (string) end( $parts );
        $resolved = '/' . implode( '/', $segments );
        if ( [] !== $segments && in_array( $last, [ '', '.', '..' ], true ) ) {
            $resolved .= '/';
        }
        return self::normalize( $scheme . '://' . $host . $port . $resolved . $suffix );
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
