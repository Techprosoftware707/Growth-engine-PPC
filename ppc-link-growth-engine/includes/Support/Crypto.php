<?php
/**
 * Authenticated encryption for credentials at rest.
 *
 * Key material is derived from the site's unique salts (AUTH_KEY / SECURE_AUTH_KEY),
 * so secrets stored in the database cannot be decrypted from a database dump alone.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Support;

use RuntimeException;

final class Crypto {

    private const PREFIX_SODIUM  = 'v1s:';
    private const PREFIX_OPENSSL = 'v1o:';

    private ?string $key = null;

    public function hasStrongKeys(): bool {
        $a = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
        $b = defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : '';
        return strlen( $a ) >= 32 && strlen( $b ) >= 32 && stripos( $a, 'put your unique phrase here' ) === false;
    }

    private function key(): string {
        if ( null === $this->key ) {
            $a = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
            $b = defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : '';
            if ( '' === $a && '' === $b ) {
                throw new RuntimeException( 'WordPress salts are not defined; cannot derive encryption key.' );
            }
            $this->key = hash( 'sha256', $a . '|' . $b . '|ppclg-credentials-v1', true );
        }
        return $this->key;
    }

    public function encrypt( string $plaintext ): string {
        if ( '' === $plaintext ) {
            return '';
        }
        if ( function_exists( 'sodium_crypto_secretbox' ) ) {
            $nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $cipher = sodium_crypto_secretbox( $plaintext, $nonce, $this->key() );
            return self::PREFIX_SODIUM . base64_encode( $nonce . $cipher );
        }
        if ( function_exists( 'openssl_encrypt' ) ) {
            $iv     = random_bytes( 12 );
            $tag    = '';
            $cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
            if ( false === $cipher ) {
                throw new RuntimeException( 'Encryption failed.' );
            }
            return self::PREFIX_OPENSSL . base64_encode( $iv . $tag . $cipher );
        }
        throw new RuntimeException( 'No encryption backend available (sodium or openssl required).' );
    }

    public function decrypt( string $payload ): string {
        if ( '' === $payload ) {
            return '';
        }
        if ( str_starts_with( $payload, self::PREFIX_SODIUM ) ) {
            $raw = base64_decode( substr( $payload, strlen( self::PREFIX_SODIUM ) ), true );
            if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
                throw new RuntimeException( 'Corrupt encrypted payload.' );
            }
            $nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key() );
            if ( false === $plain ) {
                throw new RuntimeException( 'Decryption failed (salts changed?).' );
            }
            return $plain;
        }
        if ( str_starts_with( $payload, self::PREFIX_OPENSSL ) ) {
            $raw = base64_decode( substr( $payload, strlen( self::PREFIX_OPENSSL ) ), true );
            if ( false === $raw || strlen( $raw ) <= 28 ) {
                throw new RuntimeException( 'Corrupt encrypted payload.' );
            }
            $iv     = substr( $raw, 0, 12 );
            $tag    = substr( $raw, 12, 16 );
            $cipher = substr( $raw, 28 );
            $plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag );
            if ( false === $plain ) {
                throw new RuntimeException( 'Decryption failed (salts changed?).' );
            }
            return $plain;
        }
        // Legacy/plain value (never written by this plugin, tolerated for imports).
        return $payload;
    }

    public static function randomToken( int $bytes = 16 ): string {
        return bin2hex( random_bytes( $bytes ) );
    }
}
