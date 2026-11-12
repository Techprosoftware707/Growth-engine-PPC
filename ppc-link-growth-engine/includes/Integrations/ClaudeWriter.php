<?php
/**
 * Optional AI writer using the Anthropic Messages API through the WordPress HTTP API.
 *
 * Used only to personalise drafts; every output still passes the ClaimsFilter and
 * nothing is sent or submitted without the configured approval workflow.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations;

use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Support\Settings;

final class ClaudeWriter {

    public const ENDPOINT    = 'https://api.anthropic.com/v1/messages';
    public const API_VERSION = '2026-10-21';

    /** HTTP timeout (seconds) per API call for background jobs. */
    public const TIMEOUT = 90;

    /**
     * HTTP timeout (seconds) per API call while an administrator waits on a REST request: one hung call plus the
     * caller's own time budget must stay under a proxy limit such as Cloudflare's 100 s.
     */
    public const INTERACTIVE_TIMEOUT = 40;

    private string $lastError = '';

    private int $timeout = self::TIMEOUT;

    public function __construct( private Secrets $secrets, private Settings $settings, private Client $client, private Logger $logger ) {}

    public function isConfigured(): bool {
        return $this->secrets->has( 'anthropic_api_key' );
    }

    public function isEnabled(): bool {
        return $this->isConfigured() && (bool) $this->settings->get( 'providers.ai.enabled', false );
    }

    public function lastError(): string {
        return $this->lastError;
    }

    /** Seconds each API call made by this instance may take. */
    public function timeout(): int {
        return $this->timeout;
    }

    /**
     * A copy of this writer whose API calls give up after INTERACTIVE_TIMEOUT seconds (for requests an
     * administrator is waiting on). The shared instance used by background jobs keeps TIMEOUT.
     */
    public function interactive(): self {
        return $this->withTimeout( self::INTERACTIVE_TIMEOUT );
    }

    /** A copy of this writer with a different per-call HTTP timeout (5 to TIMEOUT seconds). */
    public function withTimeout( int $seconds ): self {
        $copy          = clone $this;
        $copy->timeout = max( 5, min( self::TIMEOUT, $seconds ) );
        return $copy;
    }

    public function model(): string {
        $m = (string) $this->settings->get( 'providers.ai.model', 'claude-opus-5-5' );
        return preg_match( '/^[a-z0-9\-]+$/', $m ) ? $m : 'claude-opus-5-5';
    }

    /**
     * Returns generated text or null (refusal, error, not configured).
     */
    public function generate( string $system, string $user, ?int $maxTokens = null ): ?string {
        if ( ! $this->isEnabled() ) {
            return null;
        }
        $maxTokens = max( 200, min( 8000, (int) ( $maxTokens ?? $this->settings->get( 'providers.ai.max_tokens', 1200 ) ) ) );
        $body      = [
            'model'         => $this->model(),
            'max_tokens'    => $maxTokens,
            'system'        => $system,
            'messages'      => [ [ 'role' => 'user', 'content' => $user ] ],
            'output_config' => [ 'effort' => 'medium' ],
            'fallbacks'     => 'default',
        ];
        $r = $this->client->api(
            'POST',
            self::ENDPOINT,
            [
                'x-api-key'         => $this->secrets->get( 'anthropic_api_key' ),
                'anthropic-version' => self::API_VERSION,
                'anthropic-beta'    => 'server-side-fallback-2026-07-01',
                'Accept'            => 'application/json',
            ],
            $body,
            $this->timeout
        );
        if ( ! $r->ok() ) {
            $this->lastError = 'Anthropic API: ' . ( $r->error ?: 'HTTP ' . $r->status . ' ' . mb_substr( (string) ( $r->json()['error']['message'] ?? '' ), 0, 200 ) );
            $this->logger->warning( 'ai', $this->lastError );
            update_option( 'ppclg_provider_error_ai', [ 'message' => $this->lastError, 'at' => gmdate( 'c' ) ], false );
            return null;
        }
        $json = $r->json();
        if ( ( $json['stop_reason'] ?? '' ) === 'refusal' ) {
            $this->lastError = 'The model declined this request (' . (string) ( $json['stop_details']['category'] ?? 'refusal' ) . ').';
            $this->logger->info( 'ai', $this->lastError );
            return null;
        }
        $text = '';
        foreach ( (array) ( $json['content'] ?? [] ) as $block ) {
            if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
                $text .= (string) ( $block['text'] ?? '' );
            }
        }
        $this->logger->debug( 'ai', 'Generation completed', [ 'input_tokens' => $json['usage']['input_tokens'] ?? null, 'output_tokens' => $json['usage']['output_tokens'] ?? null, 'model' => $json['model'] ?? null ] );
        delete_option( 'ppclg_provider_error_ai' );
        $this->lastError = '';
        $text = trim( $text );
        return '' === $text ? null : $text;
    }
}
