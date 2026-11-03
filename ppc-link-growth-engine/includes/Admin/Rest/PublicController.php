<?php
/**
 * Unauthenticated endpoints: recipient unsubscribe (GET page, POST one-click) and the inbound email webhook.
 *
 * Every route is public by design; each one validates strictly and is rate limited per client address.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Admin\Rest;

use PPC\LinkGrowth\Outreach\InboundWebhook;
use PPC\LinkGrowth\Outreach\Unsubscribe;
use WP_REST_Request;

final class PublicController extends Controller {

    private const UNSUBSCRIBE_PER_MINUTE = 30;
    private const INBOUND_PER_MINUTE     = 120;
    private const INBOUND_MAX_BYTES      = 1048576;

    public function register( string $namespace ): void {
        $tokenArg = [
            't' => [
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => static fn( $value ): string => is_scalar( $value ) ? substr( preg_replace( '/[^a-f0-9]/', '', strtolower( trim( (string) $value ) ) ) ?? '', 0, 32 ) : '',
            ],
        ];
        $this->route( $namespace, '/unsubscribe', 'GET', [ $this, 'unsubscribePage' ], $tokenArg, true );
        $this->route( $namespace, '/unsubscribe', 'POST', [ $this, 'unsubscribePost' ], $tokenArg, true );
        $this->route( $namespace, '/inbound', 'POST', [ $this, 'inbound' ], [], true );
    }

    /** GET /unsubscribe?t= — processes the token and renders a confirmation page. */
    public function unsubscribePage( WP_REST_Request $request ) {
        $limited = $this->rateLimit( $request, self::UNSUBSCRIBE_PER_MINUTE, 'ip' );
        if ( true !== $limited ) {
            return $this->raw( $this->plainPage( 'Too many requests', 'Please wait a minute and open the link again.' ), 'text/html; charset=utf-8', 429, [ 'Retry-After' => '60' ] );
        }
        $result = $this->process( $request );
        $html   = $this->c->get( Unsubscribe::class )->renderPage( $result );
        return $this->raw( $html, 'text/html; charset=utf-8', ! empty( $result['ok'] ) ? 200 : 404, [ 'Cache-Control' => 'no-store, max-age=0', 'X-Robots-Tag' => 'noindex, nofollow' ] );
    }

    /** POST /unsubscribe — RFC 8058 one-click (form-encoded) or a confirmation form post. */
    public function unsubscribePost( WP_REST_Request $request ) {
        $limited = $this->rateLimit( $request, self::UNSUBSCRIBE_PER_MINUTE, 'ip' );
        if ( true !== $limited ) {
            return $limited;
        }
        $result = $this->process( $request );
        $accept = strtolower( (string) $request->get_header( 'accept' ) );
        if ( str_contains( $accept, 'text/html' ) ) {
            $html = $this->c->get( Unsubscribe::class )->renderPage( $result );
            return $this->raw( $html, 'text/html; charset=utf-8', 200, [ 'Cache-Control' => 'no-store, max-age=0', 'X-Robots-Tag' => 'noindex, nofollow' ] );
        }
        return $this->ok( [ 'ok' => (bool) $result['ok'] ], $result['ok'] ? 200 : 404 );
    }

    /** POST /inbound — reply / bounce webhook from the mail provider (secret in X-PPCLG-Secret header or body). */
    public function inbound( WP_REST_Request $request ) {
        $limited = $this->rateLimit( $request, self::INBOUND_PER_MINUTE, 'ip' );
        if ( true !== $limited ) {
            return $limited;
        }
        if ( strlen( (string) $request->get_body() ) > self::INBOUND_MAX_BYTES ) {
            return $this->fail( 'ppclg_payload_too_large', __( 'Payload too large.', 'ppc-link-growth-engine' ), 413 );
        }
        $secret = trim( (string) $request->get_header( 'x-ppclg-secret' ) );
        $params = $request->get_json_params();
        if ( ! is_array( $params ) ) {
            $params = $request->get_body_params();
        }
        if ( ! is_array( $params ) ) {
            $params = [];
        }
        if ( '' === $secret && isset( $params['secret'] ) && is_scalar( $params['secret'] ) ) {
            $secret = trim( (string) $params['secret'] );
        }
        unset( $params['secret'] );
        if ( '' === $secret ) {
            return $this->fail( 'ppclg_inbound_unauthorized', __( 'Missing webhook secret.', 'ppc-link-growth-engine' ), 401 );
        }

        $result = $this->c->get( InboundWebhook::class )->handle( $params, $secret );
        if ( empty( $result['ok'] ) ) {
            $error  = (string) ( $result['error'] ?? 'Webhook processing failed.' );
            $lower  = strtolower( $error );
            $status = 500;
            if ( str_contains( $lower, 'not configured' ) ) {
                $status = 503;
            } elseif ( str_contains( $lower, 'invalid' ) || str_contains( $lower, 'secret' ) ) {
                $status = 403;
            }
            return $this->fail( 'ppclg_inbound_rejected', $error, $status );
        }
        return [ 'ok' => true, 'matched' => (int) ( $result['matched'] ?? 0 ) ];
    }

    // ------------------------------------------------------------------

    /** @return array{ok:bool,email:string} */
    private function process( WP_REST_Request $request ): array {
        $token = $request->get_param( 't' );
        $token = is_scalar( $token ) ? strtolower( trim( (string) $token ) ) : '';
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
            return [ 'ok' => false, 'email' => '' ];
        }
        try {
            return $this->c->get( Unsubscribe::class )->process( $token );
        } catch ( \Throwable $e ) {
            $this->safe( fn() => $this->logger()->error( 'outreach', 'Unsubscribe processing failed: ' . $e->getMessage() ), null );
            return [ 'ok' => false, 'email' => '' ];
        }
    }

    /** Minimal escaped HTML page used when the renderer cannot be reached (rate limits). */
    private function plainPage( string $title, string $text ): string {
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>' . esc_html( $title ) . '</title>'
            . '<style>body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f6f7f9;color:#1d2433}main{max-width:520px;margin:12vh auto;padding:32px;background:#fff;border:1px solid #e3e6eb;border-radius:12px}h1{font-size:1.4rem;margin:0 0 12px}p{line-height:1.5;margin:0}</style></head>'
            . '<body><main><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $text ) . '</p></main></body></html>';
    }
}
