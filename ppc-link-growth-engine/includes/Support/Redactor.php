<?php
/**
 * Removes credentials and private contact data from log/audit payloads.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Support;

final class Redactor {

    private const SENSITIVE_KEYS = [ 'key', 'token', 'secret', 'password', 'authorization', 'apikey', 'api_key', 'private_key', 'cookie', 'client_secret', 'x-api-key' ];

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function redact( mixed $value, int $depth = 0 ): mixed {
        if ( $depth > 6 ) {
            return '[truncated]';
        }
        if ( is_array( $value ) ) {
            $out = [];
            foreach ( $value as $k => $v ) {
                $key = strtolower( (string) $k );
                if ( self::isSensitiveKey( $key ) ) {
                    $out[ $k ] = '[redacted]';
                    continue;
                }
                $out[ $k ] = self::redact( $v, $depth + 1 );
            }
            return $out;
        }
        if ( is_object( $value ) ) {
            return self::redact( (array) $value, $depth + 1 );
        }
        if ( is_string( $value ) ) {
            return self::redactString( $value );
        }
        return $value;
    }

    public static function isSensitiveKey( string $key ): bool {
        foreach ( self::SENSITIVE_KEYS as $needle ) {
            if ( str_contains( $key, $needle ) ) {
                return true;
            }
        }
        return false;
    }

    public static function redactString( string $value ): string {
        if ( strlen( $value ) > 4000 ) {
            $value = substr( $value, 0, 4000 ) . '…[truncated]';
        }
        // Private keys / bearer tokens.
        $value = preg_replace( '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', '[redacted private key]', $value ) ?? $value;
        $value = preg_replace( '/(Bearer|Token)\s+[A-Za-z0-9\-_\.=]{12,}/i', '$1 [redacted]', $value ) ?? $value;
        $value = preg_replace( '/\b(sk-ant-[A-Za-z0-9\-_]{10,})/', '[redacted]', $value ) ?? $value;
        // Emails: keep first char and domain.
        $value = preg_replace_callback(
            '/([A-Z0-9._%+-])[A-Z0-9._%+-]*@([A-Z0-9.-]+\.[A-Z]{2,})/i',
            static fn( array $m ): string => $m[1] . '***@' . $m[2],
            $value
        ) ?? $value;
        return $value;
    }
}
