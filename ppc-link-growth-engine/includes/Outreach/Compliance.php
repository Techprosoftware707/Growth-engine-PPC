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

    /** Canadian province / territory → IANA zone of its most populous area (send window when WordPress is on UTC). */
    public const CA_REGION_TIMEZONES = [
        'ON' => 'America/Toronto',
        'QC' => 'America/Toronto',
        'NU' => 'America/Toronto',
        'MB' => 'America/Winnipeg',
        'SK' => 'America/Regina',
        'AB' => 'America/Edmonton',
        'NT' => 'America/Edmonton',
        'BC' => 'America/Vancouver',
        'YT' => 'America/Vancouver',
        'NS' => 'America/Halifax',
        'PE' => 'America/Halifax',
        'NB' => 'America/Halifax',
        'NL' => 'America/St_Johns',
    ];

    /** @var array<string,array<string,mixed>> per-request mail-domain results */
    private array $dnsCache = [];

    public function __construct(
        private Settings $settings,
        private Suppression $suppression,
        private OutreachMessages $messages,
        private ClaimsFilter $claims
    ) {}

    /**
     * Sender identification required by CASL (s.6(2)) and CAN-SPAM: name, working address, postal address.
     *
     * The sender email must be on a domain with a mail server (an MX record): replies and emailed unsubscribe
     * requests (the List-Unsubscribe mailto) go there. An A/AAAA record alone does not count: RFC 5321 would fall back
     * to it, but a parked or registrar-forwarded domain almost always has one, pointing at a web server that takes
     * no mail. When the domain has no MX, "sender_email_domain" is missing (automatic sending pauses with the plain
     * reason in `problems`) and `suggestion` offers the site email when its domain has MX. A separate Reply-To
     * address must have a mail server too ("reply_to_domain"). A domain without an SPF record is a warning only. The postal address
     * defaults to the business address under Settings → Site when the outreach one is empty.
     *
     * @return array{ok:bool,missing:string[],warnings:string[],problems:string[],suggestion:?array<string,string>,mail_domain:?array<string,mixed>,postal_address:string,postal_default:bool}
     */
    public function senderReady(): array {
        $missing    = [];
        $warnings   = [];
        $problems   = [];
        $suggestion = null;
        $dns        = null;
        $name       = trim( (string) $this->settings->get( 'outreach.sender_name', '' ) );
        $email      = trim( (string) $this->settings->get( 'outreach.sender_email', '' ) );
        $replyTo    = trim( (string) $this->settings->get( 'outreach.reply_to', '' ) );
        $configured = trim( (string) $this->settings->get( 'outreach.postal_address', '' ) );
        $postal     = $this->postalAddress();

        if ( '' === $name ) {
            $missing[]  = 'sender_name';
            $problems[] = 'The sender name is missing (Settings → Outreach).';
        }
        if ( '' === $email || ! is_email( $email ) ) {
            $missing[]  = 'sender_email';
            $problems[] = 'The sender email is missing or not a valid address (Settings → Outreach).';
        } else {
            $site = $this->settings->siteDomain();
            $dom  = self::domainOf( $email );
            if ( '' !== $site && $dom !== $site && ! str_ends_with( $dom, '.' . $site ) ) {
                $warnings[] = 'Sender email domain differs from the site domain; deliverability and trust are better when they match.';
            }
            $dns = $this->mailDomain( $dom );
            if ( $dns['checked'] && false === $dns['receives'] ) {
                $missing[]  = 'sender_email_domain';
                $problems[] = sprintf( 'The sender email domain %1$s cannot receive mail (it has no MX record, i.e. no mail server; a parked domain looks like this), so replies and unsubscribe requests sent to %2$s would bounce. Automatic sending is paused until the sender email uses a domain that receives mail.', $dom, $email );
                $siteEmail  = strtolower( trim( (string) $this->settings->get( 'site.email', '' ) ) );
                if ( '' !== $siteEmail && is_email( $siteEmail ) && $siteEmail !== strtolower( $email ) ) {
                    $siteDns = $this->mailDomain( self::domainOf( $siteEmail ) );
                    if ( $siteDns['checked'] && $siteDns['mx'] ) {
                        $suggestion = [
                            'sender_email' => $siteEmail,
                            'reason'       => sprintf( 'Use %s (your site email): its domain has a mail server (MX record).', $siteEmail ),
                        ];
                    }
                }
            } elseif ( $dns['checked'] && false === $dns['spf'] ) {
                $warnings[] = sprintf( 'The sender email domain %s has no SPF record, so receiving servers are more likely to treat outreach as spam. Ask your email provider for the SPF TXT record to publish.', $dom );
            } elseif ( ! $dns['checked'] && '' !== $dns['error'] ) {
                $warnings[] = 'Whether the sender email domain can receive mail could not be checked (' . $dns['error'] . ').';
            }
        }
        if ( '' !== $replyTo && ! is_email( $replyTo ) ) {
            $warnings[] = 'Reply-To address is not a valid email address; the sender address will be used.';
        } elseif ( '' !== $replyTo && strtolower( $replyTo ) !== strtolower( $email ) ) {
            $replyDns = $this->mailDomain( self::domainOf( $replyTo ) );
            if ( $replyDns['checked'] && false === $replyDns['receives'] ) {
                $missing[]  = 'reply_to_domain';
                $problems[] = sprintf( 'The Reply-To address %1$s is on %2$s, which has no MX record (no mail server), so replies would bounce. Automatic sending is paused until the Reply-To address (Settings → Outreach) uses a domain that receives mail, or is left empty.', $replyTo, $replyDns['domain'] );
            }
        }
        if ( '' === $postal ) {
            $missing[]  = 'postal_address';
            $problems[] = 'The postal address is missing (Settings → Outreach, or the business address under Settings → Site).';
        } elseif ( mb_strlen( $postal ) < 12 ) {
            $warnings[] = 'Postal address looks incomplete; include street, city, province and postal code.';
        } else {
            foreach ( self::postalGaps( $postal ) as $gap ) {
                $warnings[] = $gap;
            }
        }
        return [
            'ok'             => [] === $missing,
            'missing'        => $missing,
            'warnings'       => $warnings,
            'problems'       => $problems,
            'suggestion'     => $suggestion,
            'mail_domain'    => $dns,
            'postal_address' => $postal,
            'postal_default' => '' === $configured && '' !== $postal,
        ];
    }

    /**
     * One plain sentence about a senderReady() result that is not ok, naming the real problem: the mail domain when
     * that is it, otherwise only the fields that are actually missing ('' when the sender is ready).
     *
     * @param array<string,mixed> $ready senderReady()
     */
    public static function senderProblemText( array $ready ): string {
        if ( ! empty( $ready['ok'] ) ) {
            return '';
        }
        $missing = array_map( 'strval', (array) ( $ready['missing'] ?? [] ) );
        if ( [] !== array_intersect( [ 'sender_email_domain', 'reply_to_domain' ], $missing ) ) {
            $text       = implode( ' ', array_map( 'strval', (array) ( $ready['problems'] ?? [] ) ) );
            $suggestion = is_array( $ready['suggestion'] ?? null ) ? (string) ( $ready['suggestion']['reason'] ?? '' ) : '';
            return trim( $text . ( '' !== $suggestion ? ' ' . $suggestion : '' ) );
        }
        $labels = [ 'sender_name' => 'sender name', 'sender_email' => 'sender email', 'postal_address' => 'postal address' ];
        $named  = array_values( array_filter( array_map( static fn( string $m ): string => $labels[ $m ] ?? str_replace( '_', ' ', $m ), $missing ) ) );
        return sprintf( 'Outreach sender identity is incomplete (%s; required by CASL / CAN-SPAM): outreach emails cannot be sent until it is complete (Settings → Outreach).', [] !== $named ? implode( ', ', $named ) : 'see Settings → Outreach' );
    }

    /**
     * The postal address every email carries: outreach.postal_address, or (when that is empty) the business address
     * from Settings → Site (street, city, region + postal code, country).
     */
    public function postalAddress(): string {
        return self::effectivePostalAddress( $this->settings );
    }

    /** @see postalAddress() */
    public static function effectivePostalAddress( Settings $settings ): string {
        $configured = trim( (string) $settings->get( 'outreach.postal_address', '' ) );
        if ( '' !== $configured ) {
            return $configured;
        }
        $parts = array_filter(
            array_map(
                'trim',
                [
                    (string) $settings->get( 'site.address', '' ),
                    (string) $settings->get( 'site.city', '' ),
                    trim( (string) $settings->get( 'site.region', '' ) . ' ' . (string) $settings->get( 'site.postal_code', '' ) ),
                    (string) $settings->get( 'site.country', '' ),
                ]
            ),
            static fn( string $p ): bool => '' !== $p
        );
        return implode( ', ', $parts );
    }

    /**
     * CASL asks for a valid mailing address: warn when it has no postal / ZIP code or no city part.
     *
     * @return string[]
     */
    public static function postalGaps( string $postal ): array {
        $out = [];
        if ( ! preg_match( '/\b[ABCEGHJ-NPRSTVXY]\d[ABCEGHJ-NPRSTV-Z][ -]?\d[ABCEGHJ-NPRSTV-Z]\d\b|\b\d{5}(-\d{4})?\b|\b[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2}\b/i', $postal ) ) {
            $out[] = 'The postal address has no postal code; CASL requires a valid mailing address (street, city, province and postal code).';
        }
        $parts = preg_split( '/\s*[,\n]\s*/', trim( $postal ) ) ?: [];
        if ( count( array_filter( $parts, static fn( string $p ): bool => '' !== trim( $p ) ) ) < 2 ) {
            $out[] = 'The postal address seems to have no city; write it as "street, city, province postal code".';
        }
        return $out;
    }

    /**
     * Whether a domain can receive mail: it has an MX record ("receives"; the A/AAAA result is reported as `a` but is
     * not enough, see senderReady()). SPF is a TXT "v=spf1" record. Results are cached for 12 hours.
     * `ppclg_mail_domain_dns` (null, $domain) may return [mx, a, spf] to answer without DNS (tests). Reserved test
     * domains (.test, .example, .invalid, .localhost) are not looked up. When the resolver itself fails, the result
     * is "not checked" (sending is not paused on a lookup failure) and that failure is remembered for 20 minutes, so
     * a resolver that times out does not stall every request; `ppclg_mail_domain_lookup` (null, $domain) may return
     * the records, or false for a failed lookup, in place of the real DNS queries (tests).
     *
     * @return array{domain:string,checked:bool,receives:?bool,mx:bool,a:bool,spf:?bool,error:string}
     */
    public function mailDomain( string $domain ): array {
        $domain = strtolower( trim( $domain, " .\t\n\r" ) );
        if ( isset( $this->dnsCache[ $domain ] ) ) {
            return $this->dnsCache[ $domain ];
        }
        $out = [ 'domain' => $domain, 'checked' => false, 'receives' => null, 'mx' => false, 'a' => false, 'spf' => null, 'error' => '' ];
        if ( '' === $domain || ! str_contains( $domain, '.' ) || preg_match( '/\.(test|example|invalid|localhost)$/', $domain ) ) {
            return $this->dnsCache[ $domain ] = $out;
        }
        /**
         * Short-circuits the mail-domain DNS lookup.
         *
         * @param array{mx?:bool,a?:bool,spf?:bool|null}|null $records null = look up in DNS.
         * @param string                                       $domain
         */
        $records = apply_filters( 'ppclg_mail_domain_dns', null, $domain );
        if ( ! is_array( $records ) ) {
            $key     = 'ppclg_mxchk_' . md5( $domain );
            $failKey = 'ppclg_mxfail_' . md5( $domain );
            $cached  = get_transient( $key );
            if ( is_array( $cached ) && isset( $cached['mx'] ) ) {
                $records = $cached;
            } elseif ( false !== get_transient( $failKey ) ) {
                $out['error'] = 'the DNS lookup failed; it is tried again in a few minutes';
                return $this->dnsCache[ $domain ] = $out;
            } else {
                /**
                 * Replaces the DNS queries of the mail-domain check (tests).
                 *
                 * @param array{mx?:bool,a?:bool,spf?:bool|null}|false|null $records null = query DNS, false = failed.
                 * @param string                                             $domain
                 */
                $looked  = apply_filters( 'ppclg_mail_domain_lookup', null, $domain );
                $records = is_array( $looked ) ? $looked : ( false === $looked ? null : self::lookupDns( $domain ) );
                if ( null === $records ) {
                    set_transient( $failKey, 1, 20 * MINUTE_IN_SECONDS );
                    $out['error'] = 'the DNS lookup failed';
                    return $this->dnsCache[ $domain ] = $out;
                }
                set_transient( $key, $records, 12 * HOUR_IN_SECONDS );
            }
        }
        $out['checked']  = true;
        $out['mx']       = ! empty( $records['mx'] );
        $out['a']        = ! empty( $records['a'] );
        $out['spf']      = array_key_exists( 'spf', $records ) && null !== $records['spf'] ? (bool) $records['spf'] : null;
        $out['receives'] = $out['mx'];
        return $this->dnsCache[ $domain ] = $out;
    }

    /** @return array{mx:bool,a:bool,spf:?bool}|null null when the resolver did not answer at all. */
    private static function lookupDns( string $domain ): ?array {
        if ( ! function_exists( 'dns_get_record' ) ) {
            return null;
        }
        $fqdn = $domain . '.';
        // phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record() warns on NXDOMAIN.
        $mx   = @dns_get_record( $fqdn, DNS_MX );
        $a    = @dns_get_record( $fqdn, DNS_A );
        $aaaa = ( is_array( $a ) && [] !== $a ) ? [] : @dns_get_record( $fqdn, DNS_AAAA );
        $txt  = @dns_get_record( $fqdn, DNS_TXT );
        // phpcs:enable
        if ( false === $mx && false === $a && false === $aaaa && false === $txt ) {
            // An unregistered domain also fails every query; tell the two apart with checkdnsrr().
            if ( function_exists( 'checkdnsrr' ) && ( checkdnsrr( $fqdn, 'MX' ) || checkdnsrr( $fqdn, 'A' ) ) ) {
                return [ 'mx' => checkdnsrr( $fqdn, 'MX' ), 'a' => true, 'spf' => null ];
            }
            return self::resolverWorks() ? [ 'mx' => false, 'a' => false, 'spf' => false ] : null;
        }
        $hasMx = false;
        foreach ( is_array( $mx ) ? $mx : [] as $r ) {
            // A "null MX" (RFC 7505: priority 0, target ".") explicitly says the domain accepts no mail.
            if ( isset( $r['target'] ) && '' !== trim( (string) $r['target'], '.' ) ) {
                $hasMx = true;
            }
        }
        $spf = false;
        foreach ( is_array( $txt ) ? $txt : [] as $r ) {
            $value = (string) ( $r['txt'] ?? ( isset( $r['entries'] ) ? implode( '', (array) $r['entries'] ) : '' ) );
            if ( str_starts_with( strtolower( trim( $value ) ), 'v=spf1' ) ) {
                $spf = true;
            }
        }
        return [
            'mx'  => $hasMx,
            'a'   => ( is_array( $a ) && [] !== $a ) || ( is_array( $aaaa ) && [] !== $aaaa ),
            'spf' => false === $txt ? null : $spf,
        ];
    }

    /** Whether DNS answers at all here (a well-known zone must resolve). */
    private static function resolverWorks(): bool {
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $r = @dns_get_record( 'iana.org.', DNS_A );
        return is_array( $r ) && [] !== $r;
    }

    private static function domainOf( string $email ): string {
        return strtolower( substr( $email, (int) strrpos( $email, '@' ) + 1 ) );
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
            $v[] = [] !== array_intersect( [ 'sender_email_domain', 'reply_to_domain' ], $sender['missing'] ) && [] !== $sender['problems']
                ? implode( ' ', $sender['problems'] )
                : 'Sender identity incomplete: ' . implode( ', ', $sender['missing'] ) . ' (set under Settings → Outreach).';
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
        $postal = $this->postalAddress();
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
     * Whether a timestamp falls inside the configured send window, evaluated in sendTimezone().
     */
    public function withinSendWindow( ?int $timestamp = null ): bool {
        $ts = $timestamp ?? time();
        try {
            $dt = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $this->sendTimezone() );
        } catch ( \Throwable $e ) {
            $dt = new \DateTimeImmutable( '@' . $ts );
        }
        $hour = (int) $dt->format( 'G' );
        $dow  = (int) $dt->format( 'N' ); // 1 = Monday … 7 = Sunday

        if ( ! in_array( $dow, $this->sendDays(), true ) ) {
            return false;
        }

        [ $start, $end ] = $this->sendHours();
        if ( $start === $end ) {
            return true; // no restriction
        }
        if ( $start < $end ) {
            return $hour >= $start && $hour < $end;
        }
        return $hour >= $start || $hour < $end; // overnight window
    }

    /**
     * Start of the next send window at or after $timestamp (the timestamp itself when the window is open), or null
     * when no send day is configured within the next 8 days.
     */
    public function nextSendWindow( ?int $timestamp = null ): ?int {
        $ts = $timestamp ?? time();
        if ( $this->withinSendWindow( $ts ) ) {
            return $ts;
        }
        // Step through whole hours of the send zone (not of UTC): in a zone with a :30 or :45 offset (St. John's,
        // India, Nepal) a UTC hour falls on :30 local time, so a 09:00 opening would be reported as 09:30.
        try {
            $local = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $this->sendTimezone() );
            $hour  = $local->setTime( (int) $local->format( 'G' ), 0 )->getTimestamp() + HOUR_IN_SECONDS;
        } catch ( \Throwable $e ) {
            $hour = $ts - ( $ts % HOUR_IN_SECONDS ) + HOUR_IN_SECONDS;
        }
        for ( $i = 0; $i < 8 * 24 + 2; $i++, $hour += HOUR_IN_SECONDS ) {
            if ( $this->withinSendWindow( $hour ) ) {
                return $hour;
            }
        }
        return null;
    }

    /** "Monday 09:00 Toronto time" for the next send window ('' when there is none). */
    public function describeNextSendWindow( ?int $timestamp = null ): string {
        $next = $this->nextSendWindow( $timestamp );
        if ( null === $next ) {
            return '';
        }
        $dt = ( new \DateTimeImmutable( '@' . $next ) )->setTimezone( $this->sendTimezone() );
        return $dt->format( 'l H:i' ) . ' ' . $this->sendTimezoneInfo()['label'];
    }

    /**
     * The zone the send window is evaluated in: outreach.timezone when set to a valid IANA zone; otherwise the
     * WordPress timezone, except that a WordPress install left on UTC for a Canadian business (site.country CA)
     * uses the zone of its province (site.region), so 9–17 means business hours there.
     */
    public function sendTimezone(): \DateTimeZone {
        try {
            return new \DateTimeZone( $this->sendTimezoneInfo()['timezone'] );
        } catch ( \Throwable $e ) {
            return new \DateTimeZone( 'UTC' );
        }
    }

    /**
     * @return array{timezone:string,source:string,label:string,wordpress:string} source: setting | wordpress | region
     */
    public function sendTimezoneInfo(): array {
        $wp = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : 'UTC';
        $tz = trim( (string) $this->settings->get( 'outreach.timezone', '' ) );
        if ( '' !== $tz && self::validTimezone( $tz ) ) {
            return [ 'timezone' => $tz, 'source' => 'setting', 'label' => self::zoneLabel( $tz ), 'wordpress' => $wp ];
        }
        if ( self::isUtc( $wp ) && 'CA' === strtoupper( trim( (string) $this->settings->get( 'site.country', '' ) ) ) ) {
            $region = strtoupper( trim( (string) $this->settings->get( 'site.region', '' ) ) );
            if ( isset( self::CA_REGION_TIMEZONES[ $region ] ) ) {
                $zone = self::CA_REGION_TIMEZONES[ $region ];
                return [ 'timezone' => $zone, 'source' => 'region', 'label' => self::zoneLabel( $zone ), 'wordpress' => $wp ];
            }
        }
        $zone = '' !== $wp && self::validTimezone( $wp ) ? $wp : 'UTC';
        return [ 'timezone' => $zone, 'source' => 'wordpress', 'label' => self::zoneLabel( $zone ), 'wordpress' => $wp ];
    }

    /** A known IANA zone name (or a ±HH:MM offset WordPress may report). */
    public static function validTimezone( string $tz ): bool {
        if ( preg_match( '/^[+-]\d{2}:\d{2}$/', $tz ) ) {
            return true;
        }
        static $known = null;
        $known ??= array_flip( \DateTimeZone::listIdentifiers() );
        return isset( $known[ $tz ] ) || 'UTC' === $tz;
    }

    private static function isUtc( string $tz ): bool {
        return in_array( strtoupper( trim( $tz ) ), [ '', 'UTC', 'GMT', 'Z', 'ETC/UTC', 'ETC/GMT', 'UTC+0', 'UTC-0', '+00:00', '-00:00' ], true );
    }

    private static function zoneLabel( string $tz ): string {
        if ( 'UTC' === $tz || preg_match( '/^[+-]\d{2}:\d{2}$/', $tz ) ) {
            return 'UTC' === $tz ? 'UTC' : 'UTC' . $tz;
        }
        $city = str_replace( '_', ' ', (string) substr( (string) strrchr( '/' . $tz, '/' ), 1 ) );
        return $city . ' time';
    }

    /** @return int[] ISO weekdays (1 = Monday … 7 = Sunday) */
    private function sendDays(): array {
        $days = array_map( 'intval', array_filter( (array) $this->settings->get( 'outreach.send_days', [ 1, 2, 3, 4, 5 ] ), 'is_numeric' ) );
        $days = array_map( static fn( int $d ): int => 0 === $d ? 7 : $d, $days );
        return [] === $days ? [ 1, 2, 3, 4, 5 ] : $days;
    }

    /** @return array{0:int,1:int} */
    private function sendHours(): array {
        return [
            max( 0, min( 23, (int) $this->settings->get( 'outreach.send_window_start', 9 ) ) ),
            max( 0, min( 24, (int) $this->settings->get( 'outreach.send_window_end', 17 ) ) ),
        ];
    }

    /**
     * Whether a body still carries the mandatory footer: the unsubscribe link for this token
     * and the configured postal address (when one is configured).
     */
    public function bodyHasFooter( string $body, string $token ): bool {
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) || ! $this->bodyHasUnsubscribe( $body, $token ) ) {
            return false;
        }
        $postal = $this->postalAddress();
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
