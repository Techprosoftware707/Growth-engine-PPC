<?php
/**
 * Google service-account OAuth2 (JWT bearer) without external libraries.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations\Google;

use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Secrets;

final class ServiceAccount {

    public const SCOPES = [
        'https://www.googleapis.com/auth/webmasters.readonly',
        'https://www.googleapis.com/auth/analytics.readonly',
    ];

    private string $lastError = '';

    public function __construct( private Secrets $secrets, private Client $client, private Logger $logger ) {}

    public function isConfigured(): bool {
        $c = $this->credentials();
        return null !== $c;
    }

    public function lastError(): string {
        return $this->lastError;
    }

    public function clientEmail(): string {
        $c = $this->credentials();
        return $c ? (string) $c['client_email'] : '';
    }

    /** @return array{client_email:string,private_key:string,token_uri:string}|null */
    private function credentials(): ?array {
        $json = $this->secrets->get( 'google_service_account_json' );
        if ( '' === $json ) {
            return null;
        }
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
            return null;
        }
        return [
            'client_email' => (string) $data['client_email'],
            'private_key'  => (string) $data['private_key'],
            'token_uri'    => (string) ( $data['token_uri'] ?? 'https://oauth2.googleapis.com/token' ),
        ];
    }

    /** Access token (cached ~55 minutes) or empty string. */
    public function accessToken(): string {
        $c = $this->credentials();
        if ( ! $c ) {
            $this->lastError = 'Service account JSON not configured.';
            return '';
        }
        $key    = 'ppclg_gsa_token_' . md5( $c['client_email'] . implode( ' ', self::SCOPES ) );
        $cached = get_transient( $key );
        if ( is_string( $cached ) && '' !== $cached ) {
            return $cached;
        }
        $now    = time();
        $header = self::b64( wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
        $claims = self::b64(
            wp_json_encode(
                [
                    'iss'   => $c['client_email'],
                    'scope' => implode( ' ', self::SCOPES ),
                    'aud'   => $c['token_uri'],
                    'iat'   => $now,
                    'exp'   => $now + 3600,
                ]
            )
        );
        $sig = '';
        $pk  = openssl_pkey_get_private( $c['private_key'] );
        if ( false === $pk || ! openssl_sign( $header . '.' . $claims, $sig, $pk, OPENSSL_ALGO_SHA256 ) ) {
            $this->lastError = 'Unable to sign JWT with the service-account private key.';
            $this->logger->error( 'google', $this->lastError );
            return '';
        }
        $jwt = $header . '.' . $claims . '.' . self::b64( $sig );
        $r   = $this->client->api( 'POST', $c['token_uri'], [ 'Content-Type' => 'application/x-www-form-urlencoded' ], http_build_query( [ 'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt ] ) );
        if ( ! $r->ok() ) {
            $this->lastError = 'Token exchange failed: ' . ( $r->error ?: 'HTTP ' . $r->status . ' ' . mb_substr( (string) ( $r->json()['error_description'] ?? $r->json()['error'] ?? '' ), 0, 160 ) );
            $this->logger->warning( 'google', $this->lastError );
            return '';
        }
        $json  = $r->json();
        $token = (string) ( $json['access_token'] ?? '' );
        $ttl   = max( 60, (int) ( $json['expires_in'] ?? 3600 ) - 120 );
        if ( '' !== $token ) {
            set_transient( $key, $token, $ttl );
            $this->lastError = '';
        }
        return $token;
    }

    private static function b64( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }
}
