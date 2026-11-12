<?php
/**
 * Sends outreach email through wp_mail() only, with the identification and list-unsubscribe
 * headers that CASL / CAN-SPAM and mailbox providers expect, and a stable custom Message-ID
 * so replies can be matched by the inbound webhook.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Outreach;

use PPC\LinkGrowth\Support\Crypto;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;

final class Mailer {

    public function __construct( private Settings $settings, private Logger $logger ) {}

    /**
     * Sends one plain-text outreach message.
     *
     * @param array<string,mixed> $message Message row (subject, body, unsubscribe_token, id, contact_id…).
     * @param array<string,mixed> $contact Contact row (email, name).
     * @return array{ok:bool,message_id:string,error:string} message_id is "ppclg-{token}@{domain}" (no angle brackets).
     */
    public function send( array $message, array $contact, string $unsubscribeUrl ): array {
        $to = sanitize_email( (string) ( $contact['email'] ?? '' ) );
        if ( '' === $to || ! is_email( $to ) ) {
            return $this->failure( 'Recipient email address is invalid.' );
        }
        $senderName  = $this->headerSafe( (string) $this->settings->get( 'outreach.sender_name', '' ) );
        $senderEmail = sanitize_email( (string) $this->settings->get( 'outreach.sender_email', '' ) );
        if ( '' === $senderEmail || ! is_email( $senderEmail ) ) {
            return $this->failure( 'Sender email is not configured (Settings → Outreach).' );
        }
        $replyTo = sanitize_email( (string) $this->settings->get( 'outreach.reply_to', '' ) );
        if ( '' === $replyTo || ! is_email( $replyTo ) ) {
            $replyTo = $senderEmail;
        }
        $subject = $this->headerSafe( (string) ( $message['subject'] ?? '' ) );
        $body    = str_replace( [ "\r\n", "\r" ], "\n", (string) ( $message['body'] ?? '' ) );
        if ( '' === $subject ) {
            return $this->failure( 'Subject is empty.' );
        }
        if ( '' === trim( $body ) ) {
            return $this->failure( 'Body is empty.' );
        }
        $unsubscribeUrl = trim( $unsubscribeUrl );
        if ( '' === $unsubscribeUrl || ! str_contains( $body, $unsubscribeUrl ) ) {
            // Last line of defence: never send without the unsubscribe mechanism in the body.
            return $this->failure( 'Body does not contain the unsubscribe link.' );
        }

        $token = strtolower( (string) ( $message['unsubscribe_token'] ?? '' ) );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
            $token = Crypto::randomToken( 16 );
        }
        $messageId = 'ppclg-' . $token . '@' . $this->messageIdDomain();

        $from    = '' !== $senderName ? sprintf( '%s <%s>', $senderName, $senderEmail ) : $senderEmail;
        $reply   = '' !== $senderName ? sprintf( '%s <%s>', $senderName, $replyTo ) : $replyTo;
        $headers = [
            'From: ' . $from,
            'Reply-To: ' . $reply,
            'Content-Type: text/plain; charset=UTF-8',
            'List-Unsubscribe: <mailto:' . $senderEmail . '?subject=unsubscribe>, <' . $unsubscribeUrl . '>',
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
        ];

        $error  = '';
        $onFail = static function ( $wpError ) use ( &$error ): void {
            if ( $wpError instanceof \WP_Error ) {
                $error = $wpError->get_error_message();
            }
        };
        $headerId = '<' . $messageId . '>';
        $used     = null;
        $onInit   = static function ( $phpmailer ) use ( $headerId, &$used ): void {
            // PHPMailer generates its own Message-ID unless one is set here (a header would duplicate it).
            if ( is_object( $phpmailer ) && property_exists( $phpmailer, 'MessageID' ) ) {
                $phpmailer->MessageID = $headerId;
                $used                 = $phpmailer;
            }
        };
        add_action( 'wp_mail_failed', $onFail, 10, 1 );
        add_action( 'phpmailer_init', $onInit, 99, 1 );
        try {
            $ok = (bool) wp_mail( $to, $subject, $body, $headers );
        } catch ( \Throwable $e ) {
            $ok    = false;
            $error = $e->getMessage();
        } finally {
            remove_action( 'wp_mail_failed', $onFail, 10 );
            remove_action( 'phpmailer_init', $onInit, 99 );
            $this->releaseMessageId( $used, $headerId );
        }

        $context = [
            'message'    => (int) ( $message['id'] ?? 0 ),
            'contact_id' => (int) ( $message['contact_id'] ?? $contact['id'] ?? 0 ),
            'to_domain'  => substr( $to, (int) strrpos( $to, '@' ) + 1 ),
            'message_id' => $messageId,
        ];
        if ( ! $ok ) {
            $error = '' !== $error ? $error : 'wp_mail() returned false (check the mail transport / WP Mail SMTP log).';
            $this->logger->warning( 'outreach', 'Email send failed: ' . $error, $context );
            return [ 'ok' => false, 'message_id' => '', 'error' => mb_substr( $error, 0, 500 ) ];
        }
        $this->logger->info( 'outreach', 'Email sent', $context );
        return [ 'ok' => true, 'message_id' => $messageId, 'error' => '' ];
    }

    /**
     * Sends a short configuration test to one address (used by the settings "Test" button).
     *
     * @return array{ok:bool,error:string}
     */
    public function sendTest( string $to ): array {
        $to = sanitize_email( $to );
        if ( '' === $to || ! is_email( $to ) ) {
            return [ 'ok' => false, 'error' => 'Invalid test recipient.' ];
        }
        $senderName  = $this->headerSafe( (string) $this->settings->get( 'outreach.sender_name', '' ) );
        $senderEmail = sanitize_email( (string) $this->settings->get( 'outreach.sender_email', '' ) );
        if ( '' === $senderEmail || ! is_email( $senderEmail ) ) {
            return [ 'ok' => false, 'error' => 'Sender email is not configured (Settings → Outreach).' ];
        }
        $headers = [
            'From: ' . ( '' !== $senderName ? sprintf( '%s <%s>', $senderName, $senderEmail ) : $senderEmail ),
            'Content-Type: text/plain; charset=UTF-8',
        ];
        $error  = '';
        $onFail = static function ( $wpError ) use ( &$error ): void {
            if ( $wpError instanceof \WP_Error ) {
                $error = $wpError->get_error_message();
            }
        };
        add_action( 'wp_mail_failed', $onFail, 10, 1 );
        try {
            $ok = (bool) wp_mail( $to, 'PPC Link Growth Engine: outreach sender test', "This is a test message from the PPC Link Growth Engine outreach sender configuration.\n\nSender: " . $senderEmail . "\nSent: " . gmdate( 'c' ) . "\n", $headers );
        } catch ( \Throwable $e ) {
            $ok    = false;
            $error = $e->getMessage();
        } finally {
            remove_action( 'wp_mail_failed', $onFail, 10 );
        }
        if ( ! $ok ) {
            $error = '' !== $error ? $error : 'wp_mail() returned false.';
            $this->logger->warning( 'outreach', 'Test email failed: ' . $error );
            return [ 'ok' => false, 'error' => mb_substr( $error, 0, 500 ) ];
        }
        return [ 'ok' => true, 'error' => '' ];
    }

    /**
     * wp_mail() reuses one PHPMailer instance per request and never clears MessageID, so the outreach ID would
     * otherwise be stamped on every later email of the same request (WooCommerce mails, alerts, the next
     * outreach email). Cleared only while it still holds this message's ID.
     */
    private function releaseMessageId( mixed $used, string $headerId ): void {
        global $phpmailer;
        foreach ( [ $used, $phpmailer ?? null ] as $mailer ) {
            if ( is_object( $mailer ) && property_exists( $mailer, 'MessageID' ) && $headerId === $mailer->MessageID ) {
                $mailer->MessageID = '';
            }
        }
    }

    private function messageIdDomain(): string {
        $domain = $this->settings->siteDomain();
        if ( '' === $domain && function_exists( 'home_url' ) ) {
            $host   = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
            $domain = is_string( $host ) ? strtolower( preg_replace( '/^www\./', '', $host ) ?? $host ) : '';
        }
        return '' !== $domain ? $domain : 'localhost';
    }

    /** Strips line breaks and control characters so a value is safe inside a mail header. */
    private function headerSafe( string $value ): string {
        $value = preg_replace( '/[\r\n\t\x00-\x1F\x7F]+/', ' ', $value ) ?? $value;
        return trim( preg_replace( '/\s{2,}/', ' ', $value ) ?? $value );
    }

    /** @return array{ok:bool,message_id:string,error:string} */
    private function failure( string $error ): array {
        $this->logger->warning( 'outreach', 'Email not sent: ' . $error );
        return [ 'ok' => false, 'message_id' => '', 'error' => $error ];
    }
}
