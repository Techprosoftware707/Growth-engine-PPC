<?php
/**
 * Unsubscribe links and processing (no login). The link opens a confirmation page whose single button POSTs
 * the opt-out, and mailbox providers use the RFC 8058 one-click POST, so a link scanner that only GETs the URL
 * never unsubscribes anyone (see Admin\Rest\PublicController).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Outreach;

use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\Contacts;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Suppression;

final class Unsubscribe {

    public const REST_ROUTE = 'ppclg/v1/unsubscribe';

    public function __construct(
        private OutreachMessages $messages,
        private Contacts $contacts,
        private Suppression $suppression,
        private AuditLog $audit
    ) {}

    /**
     * Public unsubscribe URL for a message token: rest_url('ppclg/v1/unsubscribe') . '?t=' . $token
     * (built with add_query_arg so it also stays valid when permalinks are plain).
     */
    public static function urlFor( string $token ): string {
        $token = strtolower( trim( $token ) );
        if ( '' === $token || ! function_exists( 'rest_url' ) ) {
            return '';
        }
        $base = (string) rest_url( self::REST_ROUTE );
        if ( function_exists( 'add_query_arg' ) ) {
            return (string) add_query_arg( [ 't' => $token ], $base );
        }
        return $base . ( str_contains( $base, '?' ) ? '&' : '?' ) . 't=' . $token;
    }

    public function url( string $token ): string {
        return self::urlFor( $token );
    }

    /**
     * Rewrites every unsubscribe link for $token in $body to the current urlFor( $token ): a draft built while the
     * site answered on another scheme or host, or with other permalink settings, still carries the token's link,
     * just under the old URL. Compliance and the send path both use this, so they agree on whether a body has a
     * working unsubscribe link. Bodies without a link for this token are returned unchanged.
     */
    public static function refreshLinks( string $body, string $token ): string {
        $token = strtolower( trim( $token ) );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) || ! str_contains( $body, $token ) ) {
            return $body;
        }
        $url = self::urlFor( $token );
        if ( '' === $url ) {
            return $body;
        }
        $re  = '~(?:https?:)?//[^\s<>"\'()\[\]]*?ppclg(?:/|%2F)v1(?:/|%2F)unsubscribe[^\s<>"\'()\[\]]*?[?&](?:amp;)?t=' . $token . '(?![a-f0-9])~i';
        $out = preg_replace_callback( $re, static fn(): string => $url, $body );
        return is_string( $out ) ? $out : $body;
    }

    /**
     * Marks the message and contact opted out, suppresses the address and records the audit entry.
     *
     * @return array{ok:bool,email:string}
     */
    public function process( string $token ): array {
        $token = strtolower( trim( $token ) );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
            return [ 'ok' => false, 'email' => '' ];
        }
        try {
            $message = $this->messages->findByToken( $token );
            if ( ! $message ) {
                return [ 'ok' => false, 'email' => '' ];
            }
            $messageId = (int) $message['id'];
            $contactId = (int) ( $message['contact_id'] ?? 0 );
            $contact   = $contactId > 0 ? $this->contacts->find( $contactId ) : null;
            $email     = strtolower( trim( (string) ( $contact['email'] ?? '' ) ) );

            $this->messages->setStatus( $messageId, OutreachMessages::STATUS_OPTED_OUT );
            if ( $contactId > 0 ) {
                $pending = $this->messages->findBy(
                    [ 'contact_id' => $contactId, 'status' => [ OutreachMessages::STATUS_DRAFT, OutreachMessages::STATUS_APPROVED, OutreachMessages::STATUS_SCHEDULED ] ],
                    'id',
                    'ASC',
                    200
                );
                foreach ( $pending as $p ) {
                    $this->messages->setStatus( (int) $p['id'], OutreachMessages::STATUS_OPTED_OUT, [ 'last_error' => 'Recipient unsubscribed' ] );
                }
            }
            if ( $contact ) {
                if ( '' !== $email && is_email( $email ) ) {
                    $this->contacts->setStatusByEmail( $email, Contacts::STATUS_OPTED_OUT );
                    $this->suppression->add( 'email', $email, 'Unsubscribed via link' );
                } else {
                    $this->contacts->update( $contactId, [ 'status' => Contacts::STATUS_OPTED_OUT, 'updated_at' => Contacts::now() ] );
                }
            }
            $this->audit->record(
                'recipient',
                'outreach.unsubscribed',
                'outreach_message',
                $messageId,
                [ 'contact_id' => $contactId, 'prospect_id' => (int) ( $message['prospect_id'] ?? 0 ), 'email' => $email ]
            );
            return [ 'ok' => true, 'email' => $email ];
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'email' => '' ];
        }
    }

    /**
     * Minimal, fully escaped confirmation page (no theme, no scripts, no tracking).
     *
     * @param array{ok:bool,email:string} $result
     */
    public function renderPage( array $result ): string {
        $ok     = ! empty( $result['ok'] );
        $masked = $this->mask( (string) ( $result['email'] ?? '' ) );
        $site   = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
        $title  = $ok ? 'You have been unsubscribed' : 'Unsubscribe link not recognised';
        $text   = $ok
            ? ( '' !== $masked ? 'We will not email ' . $masked . ' again. No further action is needed.' : 'We will not email you again. No further action is needed.' )
            : 'This link is not valid or has already expired. Reply to the email you received with the word "unsubscribe" and we will take care of it.';

        return '<!DOCTYPE html>' . "\n"
            . '<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>' . $this->e( $title ) . '</title>'
            . '<style>body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f6f7f9;color:#1d2433}main{max-width:520px;margin:12vh auto;padding:32px;background:#fff;border:1px solid #e3e6eb;border-radius:12px}h1{font-size:1.4rem;margin:0 0 12px}p{line-height:1.5;margin:0 0 10px}small{color:#5b6472}</style></head>'
            . '<body><main><h1>' . $this->e( $title ) . '</h1><p>' . $this->e( $text ) . '</p>'
            . ( '' !== $site ? '<p><small>' . $this->e( $site ) . '</small></p>' : '' )
            . '</main></body></html>';
    }

    private function mask( string $email ): string {
        if ( '' === $email || ! str_contains( $email, '@' ) ) {
            return '';
        }
        [ $local, $domain ] = explode( '@', $email, 2 );
        return mb_substr( $local, 0, 1 ) . '***@' . $domain;
    }

    private function e( string $s ): string {
        return function_exists( 'esc_html' ) ? esc_html( $s ) : htmlspecialchars( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }
}
