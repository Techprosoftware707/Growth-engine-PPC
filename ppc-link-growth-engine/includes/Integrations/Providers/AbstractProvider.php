<?php
/**
 * Shared plumbing for API providers.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations\Providers;

use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Http\Response;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Support\Settings;

abstract class AbstractProvider {

    protected string $lastError = '';

    public function __construct(
        protected Secrets $secrets,
        protected Settings $settings,
        protected Client $client,
        protected Logger $logger
    ) {}

    public function lastError(): string {
        return $this->lastError;
    }

    public function isEnabled(): bool {
        return (bool) $this->settings->get( 'providers.' . $this->id() . '.enabled', false );
    }

    abstract public function id(): string;

    protected function fail( string $message, array $context = [] ): void {
        $this->lastError = $message;
        $this->logger->warning( 'provider:' . $this->id(), $message, $context );
        update_option( 'ppclg_provider_error_' . $this->id(), [ 'message' => $message, 'at' => gmdate( 'c' ) ], false );
    }

    protected function succeed(): void {
        $this->lastError = '';
        delete_option( 'ppclg_provider_error_' . $this->id() );
    }

    protected function describe( Response $r ): string {
        if ( $r->error ) {
            return $r->error;
        }
        $body = trim( mb_substr( wp_strip_all_tags( $r->body ), 0, 200 ) );
        return sprintf( 'HTTP %d %s', $r->status, $body );
    }

    protected static function num( mixed $v ): ?float {
        if ( null === $v || '' === $v || ! is_numeric( $v ) ) {
            return null;
        }
        return (float) $v;
    }

    /** First numeric value found for any of the candidate keys. */
    protected static function pick( array $row, array $keys ): ?float {
        foreach ( $keys as $k ) {
            if ( array_key_exists( $k, $row ) ) {
                $n = self::num( $row[ $k ] );
                if ( null !== $n ) {
                    return $n;
                }
            }
        }
        return null;
    }
}
