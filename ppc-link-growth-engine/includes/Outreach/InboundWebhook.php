<?php
/**
 * Inbound email webhook (replies, bounces, "unsubscribe" replies) from the mail provider.
 *
 * The provider posts a JSON payload with a shared secret; the secret is compared in constant
 * time against the encrypted "inbound_webhook_secret". Matching order: In-Reply-To / References
 * → "ppclg-{token}" anywhere in headers or text → sender address → latest sent message.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Outreach;

use PPC\LinkGrowth\Monitoring\Notifier;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\Contacts;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Suppression;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Redactor;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Support\Str;

final class InboundWebhook {

    private const UNSUBSCRIBE_RE = '/\b(unsubscribe|opt[\s\-]?out|remove (me|us)|stop (emailing|contacting|sending|messaging)|do not (email|contact) (me|us)|take (me|us) off|no more emails?)\b/i';

    private const BOUNCE_RE = '/\b(undeliverable|undelivered mail|delivery (status notification|failure|has failed|failed)|mail delivery failed|returned mail|failure notice|address rejected|mailbox (unavailable|not found|full)|user unknown)\b/i';

    private ?Suppression $suppression = null;

    public function __construct(
        private Secrets $secrets,
        private OutreachMessages $messages,
        private Contacts $contacts,
        private Notifier $notifier,
        private AuditLog $audit,
        private Logger $logger
    ) {}

    /**
     * @param array<string,mixed> $payload Tolerated keys: from|sender, subject, text|body|html, in_reply_to|in-reply-to, references, message_id|message-id, headers{}.
     * @return array{ok:bool,matched:int,error:string}
     */
    public function handle( array $payload, string $providedSecret ): array {
        try {
            $secret = $this->secrets->get( 'inbound_webhook_secret' );
            if ( '' === $secret ) {
                return [ 'ok' => false, 'matched' => 0, 'error' => 'Inbound webhook secret is not configured.' ];
            }
            if ( '' === $providedSecret || ! hash_equals( $secret, $providedSecret ) ) {
                $this->logger->warning( 'outreach', 'Inbound webhook rejected: invalid secret.' );
                return [ 'ok' => false, 'matched' => 0, 'error' => 'Invalid webhook secret.' ];
            }

            $n       = $this->normalize( $payload );
            $message = $this->match( $n );
            if ( ! $message ) {
                $this->logger->info( 'outreach', 'Inbound email did not match any outreach message.', [ 'from_domain' => $this->domainOf( $n['from_email'] ), 'subject' => $n['subject'] ] );
                return [ 'ok' => true, 'matched' => 0, 'error' => '' ];
            }
            $this->apply( $message, $n );
            return [ 'ok' => true, 'matched' => 1, 'error' => '' ];
        } catch ( \Throwable $e ) {
            $this->logger->error( 'outreach', 'Inbound webhook failed: ' . $e->getMessage() );
            return [ 'ok' => false, 'matched' => 0, 'error' => 'Webhook processing failed.' ];
        }
    }

    // --- Normalisation ------------------------------------------------------

    /**
     * @param array<string,mixed> $payload
     * @return array{from_email:string,subject:string,text:string,new_text:string,in_reply_to:string,references:string,message_id:string,headers:array<string,string>,blob:string}
     */
    private function normalize( array $payload ): array {
        $headers = $this->headers( $payload['headers'] ?? [] );
        $pick    = static function ( array $src, array $keys ): string {
            foreach ( $keys as $k ) {
                if ( isset( $src[ $k ] ) && '' !== $src[ $k ] && null !== $src[ $k ] ) {
                    $v = $src[ $k ];
                    if ( is_array( $v ) ) {
                        $v = $v['email'] ?? $v['address'] ?? $v['value'] ?? implode( ' ', array_map( 'strval', array_filter( $v, 'is_scalar' ) ) );
                    }
                    return trim( (string) $v );
                }
            }
            return '';
        };

        $from    = $pick( $payload, [ 'from', 'sender', 'from_email', 'From', 'envelope_from' ] );
        $from    = '' !== $from ? $from : ( $headers['from'] ?? '' );
        $subject = $pick( $payload, [ 'subject', 'Subject' ] );
        $subject = '' !== $subject ? $subject : ( $headers['subject'] ?? '' );
        $text    = $pick( $payload, [ 'text', 'body', 'plain', 'text_body', 'stripped-text', 'body-plain', 'TextBody', 'content' ] );
        if ( '' === $text ) {
            $html = $pick( $payload, [ 'html', 'body_html', 'HtmlBody', 'body-html' ] );
            $text = '' !== $html ? Str::clean( $html ) : '';
        }
        $inReplyTo = $pick( $payload, [ 'in_reply_to', 'in-reply-to', 'inReplyTo', 'In-Reply-To' ] );
        $inReplyTo = '' !== $inReplyTo ? $inReplyTo : ( $headers['in-reply-to'] ?? '' );
        $refs      = $pick( $payload, [ 'references', 'References' ] );
        $refs      = '' !== $refs ? $refs : ( $headers['references'] ?? '' );
        $messageId = $pick( $payload, [ 'message_id', 'message-id', 'messageId', 'Message-Id', 'Message-ID' ] );
        $messageId = '' !== $messageId ? $messageId : ( $headers['message-id'] ?? '' );

        $headerBlob = '';
        foreach ( $headers as $k => $v ) {
            $headerBlob .= $k . ': ' . $v . "\n";
        }
        $text = str_replace( [ "\r\n", "\r" ], "\n", $text );

        return [
            'from_email'  => $this->extractEmail( $from ),
            'subject'     => $this->oneLine( $subject, 255 ),
            'text'        => $text,
            'new_text'    => $this->stripQuoted( $text ),
            'in_reply_to' => $inReplyTo,
            'references'  => $refs,
            'message_id'  => $messageId,
            'headers'     => $headers,
            'blob'        => $headerBlob . "\n" . $subject . "\n" . $inReplyTo . "\n" . $refs . "\n" . $text,
        ];
    }

    /**
     * Accepts {name: value}, [[name, value]], [{name, value}] or a raw header block.
     *
     * @return array<string,string> lower-cased header name => value
     */
    private function headers( mixed $raw ): array {
        $out = [];
        $add = static function ( string $name, mixed $value ) use ( &$out ): void {
            $name = strtolower( trim( $name ) );
            if ( '' === $name ) {
                return;
            }
            if ( is_array( $value ) ) {
                $value = implode( ' ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
            }
            $value = trim( (string) $value );
            $out[ $name ] = isset( $out[ $name ] ) && '' !== $out[ $name ] ? $out[ $name ] . ' ' . $value : $value;
        };
        if ( is_string( $raw ) ) {
            $unfolded = preg_replace( "/\r?\n[ \t]+/", ' ', $raw ) ?? $raw;
            foreach ( preg_split( "/\r?\n/", $unfolded ) ?: [] as $line ) {
                if ( preg_match( '/^([A-Za-z0-9\-]+):\s*(.*)$/', $line, $m ) ) {
                    $add( $m[1], $m[2] );
                }
            }
            return $out;
        }
        if ( ! is_array( $raw ) ) {
            return $out;
        }
        foreach ( $raw as $k => $v ) {
            if ( is_int( $k ) && is_array( $v ) ) {
                $name  = (string) ( $v['name'] ?? $v['key'] ?? $v[0] ?? '' );
                $value = $v['value'] ?? $v[1] ?? '';
                $add( $name, $value );
            } elseif ( is_string( $k ) ) {
                $add( $k, $v );
            }
        }
        return $out;
    }

    private function extractEmail( string $value ): string {
        if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $m ) ) {
            $email = strtolower( $m[0] );
            return is_email( $email ) ? $email : '';
        }
        return '';
    }

    /** Removes quoted history and our own footer so intent detection only sees the new text. */
    private function stripQuoted( string $text ): string {
        $lines = explode( "\n", $text );
        $kept  = [];
        $count = count( $lines );
        for ( $i = 0; $i < $count; $i++ ) {
            $t = trim( $lines[ $i ] );
            if ( preg_match( '/^(-{2,}\s*Original Message\s*-{2,}|_{10,}|From:\s.+|On .{3,120} wrote:|Le .{3,120} a écrit\s?:|Am .{3,120} schrieb .+:|>+)/iu', $t ) ) {
                break;
            }
            // "On <date>, <name>" on one line with "wrote:" wrapped onto the next.
            if ( preg_match( '/^On .{3,160}$/i', $t ) && isset( $lines[ $i + 1 ] ) && str_contains( $lines[ $i + 1 ], 'wrote:' ) ) {
                break;
            }
            if ( false !== stripos( $t, 'rather not hear from us again' ) || false !== stripos( $t, 'List-Unsubscribe' ) ) {
                continue;
            }
            $kept[] = $lines[ $i ];
        }
        return trim( implode( "\n", $kept ) );
    }

    // --- Matching -----------------------------------------------------------

    /**
     * @param array{from_email:string,subject:string,text:string,new_text:string,in_reply_to:string,references:string,message_id:string,headers:array<string,string>,blob:string} $n
     * @return array<string,mixed>|null
     */
    private function match( array $n ): ?array {
        // 1. Threading headers.
        $ids = [];
        foreach ( [ $n['in_reply_to'], $n['references'] ] as $raw ) {
            if ( preg_match_all( '/<([^<>\s]+)>/', $raw, $m ) ) {
                $ids = array_merge( $ids, $m[1] );
            } elseif ( '' !== trim( $raw ) ) {
                $ids = array_merge( $ids, preg_split( '/[\s,]+/', trim( $raw ) ) ?: [] );
            }
        }
        foreach ( array_unique( array_filter( $ids ) ) as $id ) {
            $found = $this->messages->findByMessageId( (string) $id );
            if ( $found ) {
                return $found;
            }
        }
        // 2. Token anywhere (Message-ID quoted in text, custom headers, forwarded copies).
        if ( preg_match_all( '/ppclg-([a-f0-9]{32})/i', $n['blob'], $m ) ) {
            foreach ( array_unique( array_map( 'strtolower', $m[1] ) ) as $token ) {
                $found = $this->messages->findByToken( $token );
                if ( $found ) {
                    return $found;
                }
            }
        }
        // 3. Sender address → contact(s) → latest sent message.
        if ( '' === $n['from_email'] ) {
            return null;
        }
        $best = null;
        foreach ( $this->contacts->findBy( [ 'email' => $n['from_email'] ], 'id', 'DESC', 20 ) as $contact ) {
            foreach ( $this->messages->findBy( [ 'contact_id' => (int) $contact['id'] ], 'sent_at', 'DESC', 5 ) as $msg ) {
                if ( empty( $msg['sent_at'] ) ) {
                    continue;
                }
                if ( null === $best || strcmp( (string) $msg['sent_at'], (string) $best['sent_at'] ) > 0 ) {
                    $best = $msg;
                }
            }
        }
        return $best;
    }

    // --- State changes ------------------------------------------------------

    /**
     * @param array<string,mixed> $message
     * @param array{from_email:string,subject:string,text:string,new_text:string,in_reply_to:string,references:string,message_id:string,headers:array<string,string>,blob:string} $n
     */
    private function apply( array $message, array $n ): void {
        $messageId  = (int) $message['id'];
        $contactId  = (int) ( $message['contact_id'] ?? 0 );
        $prospectId = (int) ( $message['prospect_id'] ?? 0 );
        $contact    = $contactId > 0 ? $this->contacts->find( $contactId ) : null;
        $email      = strtolower( trim( (string) ( $contact['email'] ?? '' ) ) );
        $summary    = $this->summary( $n['subject'], '' !== $n['new_text'] ? $n['new_text'] : $n['text'] );
        $now        = OutreachMessages::now();
        $fromDomain = $this->domainOf( $n['from_email'] );

        $isBounce = (bool) preg_match( self::BOUNCE_RE, $n['subject'] )
            || ( '' !== $n['from_email'] && (bool) preg_match( '/^(mailer-daemon|postmaster|no-?reply)@/i', $n['from_email'] ) && (bool) preg_match( self::BOUNCE_RE, $n['text'] ) );

        if ( $isBounce ) {
            $this->messages->setStatus( $messageId, OutreachMessages::STATUS_BOUNCED, [ 'last_error' => 'Bounced: ' . mb_substr( $summary, 0, 400 ) ] );
            if ( $contact && Contacts::STATUS_OPTED_OUT !== (string) $contact['status'] ) {
                $this->contacts->update( $contactId, [ 'status' => Contacts::STATUS_BOUNCED, 'updated_at' => $now ] );
            }
            $this->notify( 'outreach_bounce', 'warning', 'Outreach email bounced', sprintf( 'Message #%d to a contact at %s bounced.', $messageId, $this->domainOf( $email ) ?: 'unknown domain' ), [ 'message_id' => $messageId, 'prospect_id' => $prospectId ] );
            $this->audit->record( 'system:inbound', 'outreach.bounced', 'outreach_message', $messageId, [ 'contact_id' => $contactId, 'prospect_id' => $prospectId, 'subject' => $n['subject'] ] );
            return;
        }

        $wantsOut = (bool) preg_match( self::UNSUBSCRIBE_RE, $n['subject'] . "\n" . $n['new_text'] );
        $extra    = [ 'replied_at' => ! empty( $message['replied_at'] ) ? $message['replied_at'] : $now, 'reply_summary' => $summary ];
        $this->messages->setStatus( $messageId, $wantsOut ? OutreachMessages::STATUS_OPTED_OUT : OutreachMessages::STATUS_REPLIED, $extra );

        if ( $wantsOut ) {
            if ( '' !== $email && is_email( $email ) ) {
                $this->contacts->setStatusByEmail( $email, Contacts::STATUS_OPTED_OUT );
                $this->suppress( $email );
            } elseif ( $contact ) {
                $this->contacts->update( $contactId, [ 'status' => Contacts::STATUS_OPTED_OUT, 'updated_at' => $now ] );
            }
            if ( $contactId > 0 ) {
                $pending = $this->messages->findBy( [ 'contact_id' => $contactId, 'status' => [ OutreachMessages::STATUS_DRAFT, OutreachMessages::STATUS_APPROVED, OutreachMessages::STATUS_SCHEDULED ] ], 'id', 'ASC', 200 );
                foreach ( $pending as $p ) {
                    $this->messages->setStatus( (int) $p['id'], OutreachMessages::STATUS_OPTED_OUT, [ 'last_error' => 'Recipient asked not to be contacted' ] );
                }
            }
            $this->notify( 'outreach_opt_out', 'info', 'Contact opted out by reply', sprintf( 'A contact at %s asked not to be contacted again; the address has been suppressed.', $this->domainOf( $email ) ?: $fromDomain ?: 'unknown domain' ), [ 'message_id' => $messageId, 'prospect_id' => $prospectId ] );
            $this->audit->record( 'system:inbound', 'outreach.opted_out', 'outreach_message', $messageId, [ 'contact_id' => $contactId, 'prospect_id' => $prospectId, 'email' => $email ] );
            return;
        }

        $this->notify( 'outreach_reply', 'success', 'Reply received', sprintf( 'Reply to "%s" from %s.', mb_substr( (string) ( $message['subject'] ?? '' ), 0, 80 ), $fromDomain ?: $this->domainOf( $email ) ?: 'unknown domain' ), [ 'message_id' => $messageId, 'prospect_id' => $prospectId, 'summary' => mb_substr( $summary, 0, 200 ) ] );
        $this->audit->record( 'system:inbound', 'outreach.replied', 'outreach_message', $messageId, [ 'contact_id' => $contactId, 'prospect_id' => $prospectId, 'subject' => $n['subject'] ] );
        $this->logger->info( 'outreach', 'Reply matched to message #' . $messageId, [ 'prospect_id' => $prospectId ] );
    }

    private function summary( string $subject, string $text ): string {
        $text = trim( preg_replace( '/\n{3,}/', "\n\n", Str::clean( $text ) ) ?? $text );
        $out  = '' !== $subject ? 'Subject: ' . $subject . "\n" . $text : $text;
        $out  = Redactor::redactString( $out );
        return mb_strlen( $out ) > 1000 ? mb_substr( $out, 0, 999 ) . '…' : $out;
    }

    private function suppress( string $email ): void {
        try {
            if ( null === $this->suppression ) {
                $this->suppression = new Suppression(); // repository has no dependencies; not part of the fixed constructor
            }
            $this->suppression->add( 'email', $email, 'Asked not to be contacted (reply)' );
        } catch ( \Throwable $e ) {
            $this->logger->warning( 'outreach', 'Could not add address to the suppression list: ' . $e->getMessage() );
        }
    }

    /** @param array<string,mixed> $data */
    private function notify( string $type, string $severity, string $title, string $message, array $data = [] ): void {
        try {
            $this->notifier->notify( $type, $severity, $title, $message, $data );
        } catch ( \Throwable $e ) {
            $this->logger->warning( 'outreach', 'Notification failed: ' . $e->getMessage() );
        }
    }

    private function domainOf( string $email ): string {
        return str_contains( $email, '@' ) ? substr( $email, (int) strrpos( $email, '@' ) + 1 ) : '';
    }

    private function oneLine( string $s, int $max ): string {
        $s = trim( preg_replace( '/\s+/u', ' ', $s ) ?? $s );
        return mb_strlen( $s ) > $max ? mb_substr( $s, 0, $max ) : $s;
    }
}
