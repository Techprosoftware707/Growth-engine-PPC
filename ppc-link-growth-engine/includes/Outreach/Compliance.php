<?php
/**
 * CASL / CAN-SPAM compliance gate for outreach: sender identification, consent basis,
 * suppression, mandatory footer, honest subjects, claim-free copy, contact cool-down,
 * approval states and the send window.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Outreach;

use PPC\LinkGrowth\Content\ClaimsFilter;
use PPC\LinkGrowth\Repositories\Campaigns;
use PPC\LinkGrowth\Repositories\Contacts;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Suppression;
use PPC\LinkGrowth\Support\Settings;

final class Compliance {

    public const CONSENT_BASES = [ 'conspicuous_publication', 'existing_relationship', 'express' ];

    public const BLOCKED_ROLES = [ 'support', 'noreply' ];

    /** Prospect states in which the owner decided the website must not be contacted ("on hold" only defers sending). */
    public const BLOCKED_PROSPECT_STATUSES = [ Prospects::STATUS_REJECTED, Prospects::STATUS_SUPPRESSED ];

    public function __construct(
        private Settings $settings,
        private Suppression $suppression,
        private OutreachMessages $messages,
        private ClaimsFilter $claims
    ) {}

    /**
     * Sender identification required by CASL (s.6(2)) and CAN-SPAM: name, working address, postal address.
     *
     * @return array{ok:bool,missing:string[],warnings:string[]}
     */
    public function senderReady(): array {
        $missing  = [];
        $warnings = [];
        $name     = trim( (string) $this->settings->get( 'outreach.sender_name', '' ) );
        $email    = trim( (string) $this->settings->get( 'outreach.sender_email', '' ) );
        $replyTo  = trim( (string) $this->settings->get( 'outreach.reply_to', '' ) );
        $postal   = trim( (string) $this->settings->get( 'outreach.postal_address', '' ) );

        if ( '' === $name ) {
            $missing[] = 'sender_name';
        }
        if ( '' === $email || ! is_email( $email ) ) {
            $missing[] = 'sender_email';
        } else {
            $site = $this->settings->siteDomain();
            $dom  = strtolower( substr( $email, (int) strrpos( $email, '@' ) + 1 ) );
            if ( '' !== $site && $dom !== $site && ! str_ends_with( $dom, '.' . $site ) ) {
                $warnings[] = 'Sender email domain differs from the site domain; deliverability and trust are better when they match.';
            }
        }
        if ( '' !== $replyTo && ! is_email( $replyTo ) ) {
            $warnings[] = 'Reply-To address is not a valid email address; the sender address will be used.';
        }
        if ( '' === $postal ) {
            $missing[] = 'postal_address';
        } elseif ( mb_strlen( $postal ) < 12 ) {
            $warnings[] = 'Postal address looks incomplete; include street, city, province and postal code.';
        }
        return [ 'ok' => [] === $missing, 'missing' => $missing, 'warnings' => $warnings ];
    }

    /**
     * Full pre-send / pre-approval validation of a message.
     *
     * @param array<string,mixed>      $message  Message row (status may be overridden by the caller).
     * @param array<string,mixed>      $contact  Contact row.
     * @param array<string,mixed>|null $campaign Campaign row when the message belongs to one.
     * @param array<string,mixed>|null $prospect Prospect row (the website) when the caller has it.
     * @return array{ok:bool,violations:string[]}
     */
    public function validate( array $message, array $contact, ?array $campaign, ?array $prospect = null ): array {
        $r = $this->assess( $message, $contact, $campaign, $prospect );
        return [ 'ok' => $r['ok'], 'violations' => $r['violations'] ];
    }

    /**
     * validate() plus how lasting the problems are: "transient" is true when every violation clears by itself
     * or by a settings change (incomplete sender identity, the contact cool-down), and "retry_at" is the Unix
     * time the cool-down ends. Anything else (contact, website, copy) needs the owner to act on the message.
     *
     * @param array<string,mixed>      $message
     * @param array<string,mixed>      $contact
     * @param array<string,mixed>|null $campaign
     * @param array<string,mixed>|null $prospect
     * @return array{ok:bool,violations:string[],transient:bool,retry_at:int|null}
     */
    public function assess( array $message, array $contact, ?array $campaign, ?array $prospect = null ): array {
        $v         = [];
        $transient = 0;
        $retryAt   = null;

        $sender = $this->senderReady();
        if ( ! $sender['ok'] ) {
            $v[] = 'Sender identity incomplete: ' . implode( ', ', $sender['missing'] ) . ' (set under Settings → Outreach).';
            $transient++;
        }

        $contactCheck = $this->contactAllowed( $contact );
        foreach ( $contactCheck['reasons'] as $r ) {
            $v[] = $r;
        }
        if ( null !== $prospect ) {
            foreach ( $this->prospectAllowed( $prospect )['reasons'] as $r ) {
                $v[] = $r;
            }
        }

        $subject  = trim( (string) ( $message['subject'] ?? '' ) );
        $body     = (string) ( $message['body'] ?? '' );
        $sequence = max( 1, (int) ( $message['sequence'] ?? 1 ) );
        $token    = (string) ( $message['unsubscribe_token'] ?? '' );

        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
            $v[] = 'Message has no unsubscribe token; rebuild the draft.';
        } elseif ( ! $this->bodyHasUnsubscribe( $body, $token ) ) {
            $v[] = 'Body must contain the unsubscribe link.';
        }
        $postal = trim( (string) $this->settings->get( 'outreach.postal_address', '' ) );
        if ( '' !== $postal && ! $this->containsNormalized( $body, $postal ) ) {
            $v[] = 'Body must contain the sender postal address.';
        }
        if ( '' === trim( $body ) ) {
            $v[] = 'Body is empty.';
        }

        if ( '' === $subject ) {
            $v[] = 'Subject is empty.';
        } else {
            if ( preg_match( '/^\s*(fw|fwd)\s*:/i', $subject ) ) {
                $v[] = 'Subject must not use a forward prefix (FW:/FWD:).';
            }
            if ( preg_match( '/^\s*re\s*:/i', $subject ) && $sequence <= 1 ) {
                $v[] = 'Subject must not pretend to be a reply (RE:) on a first contact.';
            }
            if ( $this->isAllCaps( $subject ) ) {
                $v[] = 'Subject must not be in ALL CAPS.';
            }
            if ( preg_match( '/[!?]{2,}|\$\$+|free!!!/i', $subject ) ) {
                $v[] = 'Subject uses spam-like punctuation.';
            }
        }

        foreach ( $this->checkClaims( $subject, $body ) as $c ) {
            $v[] = $c;
        }

        if ( $sequence <= 1 ) {
            $days = max( 0, (int) $this->settings->get( 'outreach.min_days_between_contacts', 90 ) );
            $pid  = (int) ( $message['prospect_id'] ?? 0 );
            if ( $days > 0 && $pid > 0 ) {
                $last = $this->messages->lastSentForProspect( $pid );
                if ( $last && (int) ( $last['id'] ?? 0 ) !== (int) ( $message['id'] ?? 0 ) && ! empty( $last['sent_at'] ) ) {
                    $sentAt = strtotime( (string) $last['sent_at'] . ' UTC' );
                    if ( false !== $sentAt && $sentAt > time() - $days * DAY_IN_SECONDS ) {
                        $v[] = sprintf( 'This website was contacted on %s; the minimum interval between contacts is %d days.', gmdate( 'Y-m-d', $sentAt ), $days );
                        $transient++;
                        $retryAt = $sentAt + $days * DAY_IN_SECONDS;
                    }
                }
            }
        }

        if ( null !== $campaign ) {
            $cs = (string) ( $campaign['status'] ?? '' );
            if ( ! in_array( $cs, [ Campaigns::STATUS_APPROVED, Campaigns::STATUS_SENDING ], true ) ) {
                $v[] = 'Campaign is not approved for sending (status: ' . ( '' !== $cs ? $cs : 'unknown' ) . ').';
            }
        }

        $ms = (string) ( $message['status'] ?? '' );
        if ( ! in_array( $ms, [ OutreachMessages::STATUS_APPROVED, OutreachMessages::STATUS_SCHEDULED ], true ) ) {
            $v[] = 'Message is not approved (status: ' . ( '' !== $ms ? $ms : 'unknown' ) . ').';
        }

        return [
            'ok'         => [] === $v,
            'violations' => array_values( array_unique( $v ) ),
            'transient'  => [] !== $v && count( $v ) === $transient,
            'retry_at'   => $retryAt,
        ];
    }

    /**
     * Website-level checks: the owner has not rejected or suppressed the prospect (status, including the automatic
     * rejection below the keep score), and the prospect's own domain is not on the suppression list (the contact
     * may use another domain, e.g. gmail.com). "On hold" is handled by the send path as a deferral.
     *
     * @param array<string,mixed> $prospect
     * @return array{ok:bool,reasons:string[]}
     */
    public function prospectAllowed( array $prospect ): array {
        $r      = [];
        $status = (string) ( $prospect['status'] ?? '' );
        if ( in_array( $status, self::BLOCKED_PROSPECT_STATUSES, true ) ) {
            $r[] = 'The website is marked "' . $status . '"; it must not be contacted.';
        }
        $domain = strtolower( trim( (string) ( $prospect['domain'] ?? '' ) ) );
        if ( '' !== $domain ) {
            try {
                if ( $this->suppression->isSuppressed( 'domain', $domain ) ) {
                    $r[] = 'The website\'s domain is on the suppression list.';
                }
            } catch ( \Throwable $e ) {
                $r[] = 'Suppression list could not be checked.';
            }
        }
        return [ 'ok' => [] === $r, 'reasons' => $r ];
    }

    /**
     * Contact-level checks shared by drafting and validation: active, not suppressed, lawful
     * consent basis, not a support / no-reply mailbox.
     *
     * @param array<string,mixed> $contact
     * @return array{ok:bool,reasons:string[]}
     */
    public function contactAllowed( array $contact ): array {
        $r      = [];
        $email  = strtolower( trim( (string) ( $contact['email'] ?? '' ) ) );
        $status = (string) ( $contact['status'] ?? '' );
        $role   = strtolower( trim( (string) ( $contact['role'] ?? '' ) ) );
        $basis  = strtolower( trim( (string) ( $contact['consent_basis'] ?? '' ) ) );

        if ( '' === $email || ! is_email( $email ) ) {
            $r[] = 'Contact has no valid email address.';
        } else {
            try {
                if ( $this->suppression->isSuppressed( 'email', $email ) ) {
                    $r[] = 'Contact email is on the suppression list.';
                }
                $domain = substr( $email, (int) strrpos( $email, '@' ) + 1 );
                if ( '' !== $domain && $this->suppression->isSuppressed( 'domain', $domain ) ) {
                    $r[] = 'Contact domain is on the suppression list.';
                }
            } catch ( \Throwable $e ) {
                $r[] = 'Suppression list could not be checked.';
            }
        }
        if ( Contacts::STATUS_ACTIVE !== $status ) {
            $r[] = 'Contact is not active (status: ' . ( '' !== $status ? $status : 'unknown' ) . ').';
        }
        if ( ! in_array( $basis, self::CONSENT_BASES, true ) ) {
            $r[] = 'Contact has no recognised consent basis (expected one of: ' . implode( ', ', self::CONSENT_BASES ) . ').';
        }
        if ( in_array( $role, self::BLOCKED_ROLES, true ) ) {
            $r[] = 'Contact role "' . $role . '" is not a valid outreach target.';
        }
        return [ 'ok' => [] === $r, 'reasons' => $r ];
    }

    /**
     * Human-readable claims violations for subject + body.
     *
     * @return string[]
     */
    public function checkClaims( string $subject, string $body ): array {
        $out = [];
        try {
            foreach ( $this->claims->check( trim( $subject . "\n" . $body ) ) as $hit ) {
                $out[] = 'Claims: ' . $hit['message'] . ' ("' . $hit['match'] . '").';
            }
        } catch ( \Throwable $e ) {
            $out[] = 'Claims filter could not run.';
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Whether a timestamp falls inside the configured send window (site timezone).
     */
    public function withinSendWindow( ?int $timestamp = null ): bool {
        $ts = $timestamp ?? time();
        try {
            $tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
            $dt = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
        } catch ( \Throwable $e ) {
            $dt = new \DateTimeImmutable( '@' . $ts );
        }
        $hour = (int) $dt->format( 'G' );
        $dow  = (int) $dt->format( 'N' ); // 1 = Monday … 7 = Sunday

        $days = array_map( 'intval', array_filter( (array) $this->settings->get( 'outreach.send_days', [ 1, 2, 3, 4, 5 ] ), 'is_numeric' ) );
        $days = array_map( static fn( int $d ): int => 0 === $d ? 7 : $d, $days );
        if ( [] === $days ) {
            $days = [ 1, 2, 3, 4, 5 ];
        }
        if ( ! in_array( $dow, $days, true ) ) {
            return false;
        }

        $start = max( 0, min( 23, (int) $this->settings->get( 'outreach.send_window_start', 9 ) ) );
        $end   = max( 0, min( 24, (int) $this->settings->get( 'outreach.send_window_end', 17 ) ) );
        if ( $start === $end ) {
            return true; // no restriction
        }
        if ( $start < $end ) {
            return $hour >= $start && $hour < $end;
        }
        return $hour >= $start || $hour < $end; // overnight window
    }

    /**
     * Whether a body still carries the mandatory footer: the unsubscribe link for this token
     * and the configured postal address (when one is configured).
     */
    public function bodyHasFooter( string $body, string $token ): bool {
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) || ! $this->bodyHasUnsubscribe( $body, $token ) ) {
            return false;
        }
        $postal = trim( (string) $this->settings->get( 'outreach.postal_address', '' ) );
        return '' === $postal || $this->containsNormalized( $body, $postal );
    }

    // --- Helpers ------------------------------------------------------------

    /**
     * The same rule the send path applies: the body carries this token's unsubscribe link, either the current URL
     * or one built under an earlier site URL / permalink setting, which Unsubscribe::refreshLinks() rewrites to the
     * current URL before sending (so approval never passes a body that the mailer would then refuse).
     */
    private function bodyHasUnsubscribe( string $body, string $token ): bool {
        $url = Unsubscribe::urlFor( $token );
        return '' !== $url && str_contains( Unsubscribe::refreshLinks( $body, $token ), $url );
    }

    private function containsNormalized( string $haystack, string $needle ): bool {
        $norm = static fn( string $s ): string => mb_strtolower( trim( preg_replace( '/[\s,.;]+/u', ' ', $s ) ?? $s ) );
        $n    = $norm( $needle );
        return '' === $n || str_contains( $norm( $haystack ), $n );
    }

    private function isAllCaps( string $subject ): bool {
        $letters = preg_replace( '/[^\p{L}]/u', '', $subject ) ?? '';
        if ( mb_strlen( $letters ) < 6 ) {
            return false;
        }
        return mb_strtoupper( $letters ) === $letters;
    }
}
