<?php
/**
 * Classifies the outcome of a non-idempotent submission request (listing form POST, partner API call).
 *
 * A request may only be repeated automatically when it provably never reached the destination
 * (DNS, connect or TLS failure before anything was sent). Timeouts and dropped connections after
 * the body was sent, and 5xx answers, leave the outcome unknown: the destination may already have
 * stored the listing, so repeating the request would post a duplicate.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Submission\Adapters;

use PPC\LinkGrowth\Support\Http\Response;

final class Delivery {

    /** Longest Retry-After the engine honours (seconds). */
    public const MAX_RETRY_AFTER = 7 * 86400;

    /** cURL errors raised before any request byte is sent (resolve, connect, TLS handshake, bad URL). */
    private const PRE_SEND_CURL = '/\bcurl error (1|3|5|6|7|35|51|53|58|59|60|66|77|83|90|91)\s*:/i';

    /** Transport messages that mean the connection was never established. */
    private const PRE_SEND_TEXT = '/(could not resolve|couldn\'t resolve|resolving timed out|connection timed out after|connection refused|failed to connect|no route to host|network is unreachable|ssl certificate problem|ssl connect error|a valid url was not provided)/i';

    /** True when the request failed before anything was sent to the destination (safe to repeat). */
    public static function neverSent( Response $r ): bool {
        if ( null === $r->error ) {
            return false;
        }
        if ( in_array( (string) $r->errorCode, [ 'invalid_url', 'robots_disallow' ], true ) ) {
            return true;
        }
        $message = (string) $r->error;
        return (bool) preg_match( self::PRE_SEND_CURL, $message ) || (bool) preg_match( self::PRE_SEND_TEXT, $message );
    }

    /**
     * Seconds the destination asked us to wait (Retry-After header: delta-seconds or HTTP date), or null.
     */
    public static function retryAfter( Response $r ): ?int {
        $value = trim( $r->header( 'retry-after' ) );
        if ( '' === $value ) {
            return null;
        }
        if ( ctype_digit( $value ) ) {
            $seconds = (int) $value;
        } else {
            $ts = strtotime( $value );
            if ( false === $ts ) {
                return null;
            }
            $seconds = $ts - time();
        }
        return max( 0, min( self::MAX_RETRY_AFTER, $seconds ) );
    }
}
