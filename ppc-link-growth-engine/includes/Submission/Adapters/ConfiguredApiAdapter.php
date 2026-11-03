<?php
/**
 * Submits a listing through a partner API configured per prospect
 * (prospect.submission_config.api + the encrypted secret "partner_api_{prospectId}").
 *
 * Config keys: endpoint (https), method (POST default; PUT/PATCH allowed), headers {},
 * body_template (JSON string with {{placeholders}} from payload.business / payload.content /
 * payload.destination and {{api_key}}), success_path (dot path that must be non-empty in the
 * JSON response), listing_url_path and optional confirmation_path (dot paths).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Submission\Adapters;

use PPC\LinkGrowth\Submission\Policy;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Http\Response;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Redactor;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Str;
use PPC\LinkGrowth\Support\Url;
use Throwable;

final class ConfiguredApiAdapter implements AdapterInterface {

    private const PLACEHOLDER = '/\{\{\s*([A-Za-z0-9_.\-]+)\s*\}\}/';
    private const MAX_EXCERPT = 2000;
    private const TIMEOUT     = 20;

    public function __construct(
        private Client $client,
        private Secrets $secrets,
        private Settings $settings,
        private Logger $logger
    ) {}

    public function id(): string {
        return 'configured_api';
    }

    public function label(): string {
        return 'Configured partner API';
    }

    /** @inheritDoc */
    public function supports( array $prospect, array $payload ): bool {
        $cfg = Policy::apiConfig( $prospect );
        return null !== $cfg && Policy::validEndpoint( $cfg['endpoint'] );
    }

    /** @inheritDoc */
    public function submit( array $submission, array $prospect, array $payload ): array {
        $sid = (int) ( $submission['id'] ?? 0 );
        $pid = (int) ( $prospect['id'] ?? 0 );
        try {
            return $this->run( $sid, $pid, $prospect, $payload );
        } catch ( Throwable $e ) {
            $this->logger->error( 'submission', 'Partner API adapter error', [ 'submission_id' => $sid, 'error' => $e->getMessage() ] );
            return $this->failed( 'Adapter error: ' . $e->getMessage(), false );
        }
    }

    // ------------------------------------------------------------------
    // Flow
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $prospect
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function run( int $sid, int $pid, array $prospect, array $payload ): array {
        if ( ! (bool) $this->settings->get( 'automation.submissions', false ) ) {
            return $this->manual( 'Automated submissions are switched off.' );
        }
        $cfg = Policy::apiConfig( $prospect );
        if ( null === $cfg ) {
            return $this->manual( 'No partner API is configured for this prospect.' );
        }
        if ( ! Policy::validEndpoint( $cfg['endpoint'] ) ) {
            return $this->manual( 'The partner API endpoint is not a valid public https:// URL.' );
        }

        $apiKey   = $pid > 0 ? $this->secrets->get( 'partner_api_' . $pid ) : '';
        $needsKey = str_contains( $cfg['body_template'], 'api_key' ) || str_contains( implode( ' ', $cfg['headers'] ), 'api_key' );
        if ( $needsKey && '' === $apiKey ) {
            return $this->manual( sprintf( 'The partner API key for this prospect is not configured (secret "partner_api_%d").', $pid ) );
        }

        $vars = [
            'api_key'     => $apiKey,
            'business'    => (array) ( $payload['business'] ?? [] ),
            'content'     => (array) ( $payload['content'] ?? [] ),
            'destination' => (array) ( $payload['destination'] ?? [] ),
        ];

        $headers = [ 'Accept' => 'application/json' ];
        foreach ( $cfg['headers'] as $k => $v ) {
            $headers[ $k ] = $this->render( $v, $vars, 'raw' );
        }

        $template = trim( $cfg['body_template'] );
        if ( '' !== $template ) {
            $isJson = $this->templateIsJson( $template );
            $isForm = ! $isJson && $this->templateIsForm( $template );
            $body   = $this->render( $template, $vars, $isJson ? 'json' : ( $isForm ? 'form' : 'raw' ) );
            if ( ! $this->hasHeader( $headers, 'content-type' ) ) {
                if ( $isJson ) {
                    $headers['Content-Type'] = 'application/json';
                } elseif ( $isForm ) {
                    $headers['Content-Type'] = 'application/x-www-form-urlencoded';
                }
            }
        } else {
            // No template: send the package itself as JSON (Client::api encodes arrays).
            $body = [
                'business'    => $vars['business'],
                'content'     => $vars['content'],
                'destination' => $vars['destination'],
            ];
        }

        $resp = $this->client->api( $cfg['method'], $cfg['endpoint'], $headers, $body, self::TIMEOUT );
        $this->logger->info( 'submission', 'Partner API called', [ 'submission_id' => $sid, 'host' => Url::host( $cfg['endpoint'] ), 'http_status' => $resp->status ] );

        return $this->interpret( $resp, $cfg );
    }

    /**
     * @param array{endpoint:string,method:string,headers:array<string,string>,body_template:string,success_path:string,listing_url_path:string,confirmation_path:string} $cfg
     * @return array<string,mixed>
     */
    private function interpret( Response $resp, array $cfg ): array {
        $clean    = trim( preg_replace( '/\s+/u', ' ', Str::clean( $resp->body ) ) ?? '' );
        $response = [
            'adapter'       => $this->id(),
            'http_status'   => $resp->status,
            'endpoint_host' => Url::host( $cfg['endpoint'] ),
            'elapsed'       => round( $resp->elapsed, 2 ),
            'excerpt'       => Redactor::redactString( mb_substr( $clean, 0, self::MAX_EXCERPT ) ),
            'at'            => gmdate( 'c' ),
        ];

        if ( null !== $resp->error ) {
            return $this->failed( 'API request failed: ' . $resp->error, 'invalid_url' !== (string) $resp->errorCode, $response );
        }
        if ( $resp->status >= 500 || 429 === $resp->status ) {
            return $this->failed( sprintf( 'The partner API returned HTTP %d.', $resp->status ), true, $response );
        }
        if ( $resp->status >= 400 ) {
            return $this->failed( sprintf( 'The partner API rejected the request (HTTP %d).', $resp->status ), false, $response );
        }

        $json         = $resp->json();
        $successValue = '' !== $cfg['success_path'] ? self::dot( $json, $cfg['success_path'] ) : true;
        if ( empty( $successValue ) ) {
            return $this->failed( sprintf( 'The API response did not contain a non-empty "%s" value.', $cfg['success_path'] ), false, $response );
        }
        $response['success_value'] = is_scalar( $successValue ) ? $successValue : '[object]';

        $listing = Url::normalize( self::scalar( self::dot( $json, $cfg['listing_url_path'] ) ) );
        $ref     = '' !== $cfg['confirmation_path']
            ? self::scalar( self::dot( $json, $cfg['confirmation_path'] ) )
            : ( is_scalar( $successValue ) && ! is_bool( $successValue ) ? (string) $successValue : '' );

        return [
            'status'           => self::STATUS_SUBMITTED,
            'response'         => $response,
            'confirmation_ref' => mb_substr( $ref, 0, 190 ),
            'listing_url'      => $listing,
            'error'            => '',
            'retryable'        => false,
        ];
    }

    // ------------------------------------------------------------------
    // Templating helpers
    // ------------------------------------------------------------------

    /**
     * Replace {{placeholders}}; values are escaped for the template format (json | form | raw).
     *
     * @param array<string,mixed> $vars
     */
    private function render( string $template, array $vars, string $mode ): string {
        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            function ( array $m ) use ( $vars, $mode ): string {
                $value = $this->resolve( $m[1], $vars );
                return match ( $mode ) {
                    'json'  => (string) substr( (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 1, -1 ),
                    'form'  => rawurlencode( $value ),
                    default => $value,
                };
            },
            $template
        );
    }

    /** @param array<string,mixed> $vars */
    private function resolve( string $path, array $vars ): string {
        if ( 'api_key' === $path ) {
            return (string) $vars['api_key'];
        }
        $segments = explode( '.', $path );
        if ( 1 === count( $segments ) ) {
            foreach ( [ 'business', 'content', 'destination' ] as $group ) {
                if ( is_array( $vars[ $group ] ) && array_key_exists( $path, $vars[ $group ] ) ) {
                    return self::stringify( $vars[ $group ][ $path ] );
                }
            }
            return '';
        }
        $cur = $vars;
        foreach ( $segments as $s ) {
            if ( 'api_key' === $s ) {
                return '';
            }
            if ( is_array( $cur ) && array_key_exists( $s, $cur ) ) {
                $cur = $cur[ $s ];
            } else {
                return '';
            }
        }
        return self::stringify( $cur );
    }

    private static function stringify( mixed $value ): string {
        if ( is_bool( $value ) ) {
            return $value ? '1' : '';
        }
        if ( is_scalar( $value ) ) {
            return (string) $value;
        }
        if ( is_array( $value ) ) {
            $scalars = array_filter( $value, 'is_scalar' );
            return implode( ', ', array_map( 'strval', $scalars ) );
        }
        return '';
    }

    private static function scalar( mixed $value ): string {
        return is_scalar( $value ) && ! is_bool( $value ) ? trim( (string) $value ) : '';
    }

    /** @param array<mixed> $data */
    private static function dot( array $data, string $path ): mixed {
        $path = trim( $path );
        if ( '' === $path ) {
            return null;
        }
        $cur = $data;
        foreach ( explode( '.', $path ) as $seg ) {
            if ( is_array( $cur ) && array_key_exists( $seg, $cur ) ) {
                $cur = $cur[ $seg ];
            } else {
                return null;
            }
        }
        return $cur;
    }

    private function templateIsJson( string $template ): bool {
        $probe = preg_replace( self::PLACEHOLDER, 'x', $template ) ?? $template;
        return is_array( json_decode( $probe, true ) );
    }

    private function templateIsForm( string $template ): bool {
        return (bool) preg_match( '/^[\w\-\.\[\]%]+=/', $template );
    }

    /** @param array<string,string> $headers */
    private function hasHeader( array $headers, string $name ): bool {
        foreach ( array_keys( $headers ) as $k ) {
            if ( strtolower( (string) $k ) === strtolower( $name ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function manual( string $why, array $response = [] ): array {
        return [
            'status'           => self::STATUS_NEEDS_MANUAL,
            'response'         => array_merge( [ 'adapter' => $this->id(), 'at' => gmdate( 'c' ) ], $response ),
            'confirmation_ref' => '',
            'listing_url'      => '',
            'error'            => $why,
            'retryable'        => false,
        ];
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function failed( string $why, bool $retryable, array $response = [] ): array {
        return [
            'status'           => self::STATUS_FAILED,
            'response'         => array_merge( [ 'adapter' => $this->id(), 'at' => gmdate( 'c' ) ], $response ),
            'confirmation_ref' => '',
            'listing_url'      => '',
            'error'            => $why,
            'retryable'        => $retryable,
        ];
    }
}
