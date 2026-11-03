<?php
/**
 * Notification hub: stores dashboard notifications, emails critical alerts
 * immediately (throttled) and sends a daily plain-text digest.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Monitoring;

use PPC\LinkGrowth\Repositories\Notifications;
use PPC\LinkGrowth\Repositories\Repository;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Redactor;
use PPC\LinkGrowth\Support\Settings;

final class Notifier {

    public const OPTION_LAST_DIGEST = 'ppclg_last_digest_at';

    /** Highest notification id covered by the last digest (guards the inclusive time boundary). */
    public const OPTION_LAST_DIGEST_ID = 'ppclg_last_digest_max_id';

    /** @var string[] */
    public const SEVERITIES = [ 'info', 'success', 'warning', 'critical' ];

    /** Identical titles are emailed, and exact duplicates stored, at most once per window (6 hours). */
    private const THROTTLE_SECONDS = 21600;

    /** Digest cadence (24 hours). */
    private const DIGEST_INTERVAL = 86400;

    private const MAX_DIGEST_ITEMS = 100;

    private const MAX_DETAIL_LINES = 12;

    public function __construct(
        private Notifications $notifications,
        private Settings $settings,
        private Logger $logger
    ) {}

    /**
     * Stores a dashboard notification; critical ones are emailed immediately when enabled.
     *
     * Exact duplicates (same type, severity, title and message) are stored once per 6 hours.
     * Critical emails are throttled per title to once per 6 hours; later alerts with the same
     * title stay in the dashboard and the daily digest.
     *
     * @param string              $severity info|success|warning|critical
     * @param array<string,mixed> $data
     * @return int Notification id (0 when throttled as a duplicate or when storage failed).
     */
    public function notify( string $type, string $severity, string $title, string $message, array $data = [] ): int {
        try {
            $type     = sanitize_key( $type );
            $type     = '' === $type ? 'general' : $type;
            $severity = in_array( $severity, self::SEVERITIES, true ) ? $severity : 'info';
            $title    = trim( wp_strip_all_tags( $title ) );
            $message  = trim( wp_strip_all_tags( $message ) );
            if ( '' === $title ) {
                $title = ucfirst( str_replace( [ '_', '-' ], ' ', $type ) );
            }
            $data = $this->scrub( $data );

            $dupKey = 'ppclg_ntf_' . md5( $type . '|' . $severity . '|' . $title . '|' . $message );
            if ( false !== get_transient( $dupKey ) ) {
                $this->logger->debug( 'notify', 'Duplicate notification suppressed', [ 'type' => $type, 'title' => $title ] );
                return 0;
            }

            $id = 0;
            try {
                $id = $this->notifications->add( $type, $severity, $title, $message, $data );
                set_transient( $dupKey, time(), self::THROTTLE_SECONDS );
            } catch ( \Throwable $e ) {
                $this->logger->error( 'notify', 'Could not store notification', [ 'type' => $type, 'title' => $title, 'error' => $e->getMessage() ] );
            }

            $this->logger->log(
                'critical' === $severity ? 'warning' : 'info',
                'notify',
                $title,
                [ 'type' => $type, 'severity' => $severity, 'message' => $message, 'notification_id' => $id ]
            );

            if ( 'critical' === $severity && (bool) $this->settings->get( 'monitoring.notify_critical_immediately', true ) ) {
                $this->emailCritical( $title, $message, $data );
            }

            return $id;
        } catch ( \Throwable $e ) {
            $this->logger->error( 'notify', 'Notification failed', [ 'error' => $e->getMessage() ] );
            return 0;
        }
    }

    /** True when the daily digest is enabled and at least 24h passed since the last one. */
    public function digestDue(): bool {
        if ( ! (bool) $this->settings->get( 'monitoring.digest_email', true ) ) {
            return false;
        }
        $last = (string) get_option( self::OPTION_LAST_DIGEST, '' );
        if ( '' === $last ) {
            return true;
        }
        $ts = $this->timestamp( $last );
        return 0 === $ts || $ts <= time() - self::DIGEST_INTERVAL;
    }

    /**
     * Emails the unread notifications created since the last digest (plain text) and
     * records the digest time. Returns true only when an email was actually sent.
     */
    public function sendDigest(): bool {
        try {
            $since = (string) get_option( self::OPTION_LAST_DIGEST, '' );
            if ( '' === $since || 0 === $this->timestamp( $since ) ) {
                $since = gmdate( 'Y-m-d H:i:s', time() - self::DIGEST_INTERVAL );
            }
            $lastId = (int) get_option( self::OPTION_LAST_DIGEST_ID, 0 );
            $items  = array_values( array_filter( $this->notifications->unreadSince( $since ), static fn( array $n ): bool => empty( $n['is_read'] ) && (int) $n['id'] > $lastId ) );

            // Advance the window even when nothing is sent so each digest covers a distinct period.
            $latest = $this->notifications->recent( 1 );
            update_option( self::OPTION_LAST_DIGEST, Repository::now(), false );
            update_option( self::OPTION_LAST_DIGEST_ID, max( $lastId, (int) ( $latest[0]['id'] ?? 0 ) ), false );

            if ( empty( $items ) ) {
                $this->logger->debug( 'notify', 'Digest skipped: no unread notifications', [ 'since' => $since ] );
                return false;
            }
            $to = $this->recipient();
            if ( '' === $to ) {
                $this->logger->warning( 'notify', 'Digest skipped: no recipient email configured' );
                return false;
            }

            $total   = count( $items );
            $items   = array_slice( $items, 0, self::MAX_DIGEST_ITEMS );
            $groups  = [ 'critical' => [], 'warning' => [], 'success' => [], 'info' => [] ];
            foreach ( $items as $n ) {
                $sev = (string) ( $n['severity'] ?? 'info' );
                $groups[ isset( $groups[ $sev ] ) ? $sev : 'info' ][] = $n;
            }

            $lines   = [];
            $lines[] = sprintf( '%s - Link Growth daily digest', $this->brand() );
            $lines[] = sprintf( '%d new notification%s since %s.', $total, 1 === $total ? '' : 's', $this->localDate( $since ) );
            if ( $total > self::MAX_DIGEST_ITEMS ) {
                $lines[] = sprintf( 'Showing the first %d; the rest are in the dashboard.', self::MAX_DIGEST_ITEMS );
            }
            foreach ( $groups as $sev => $list ) {
                if ( empty( $list ) ) {
                    continue;
                }
                $lines[] = '';
                $lines[] = sprintf( '%s (%d)', strtoupper( $sev ), count( $list ) );
                $lines[] = str_repeat( '-', 40 );
                foreach ( $list as $n ) {
                    $lines[] = sprintf( '- [%s] %s', $this->localDate( (string) ( $n['created_at'] ?? '' ) ), (string) ( $n['title'] ?? '' ) );
                    $msg     = trim( (string) ( $n['message'] ?? '' ) );
                    if ( '' !== $msg ) {
                        $lines[] = '  ' . str_replace( "\n", "\n  ", wordwrap( $msg, 76, "\n", true ) );
                    }
                }
            }
            $lines[] = '';
            $lines[] = 'Open the dashboard: ' . $this->dashboardUrl();
            $lines[] = '';
            $lines[] = $this->footer();

            $subject = sprintf( '[%s] Link Growth digest: %d notification%s', $this->brand(), $total, 1 === $total ? '' : 's' );
            $ok      = $this->mail( $to, $subject, implode( "\n", $lines ), 'digest' );
            if ( $ok ) {
                $this->logger->info( 'notify', 'Digest sent', [ 'count' => $total, 'since' => $since ] );
            }
            return $ok;
        } catch ( \Throwable $e ) {
            $this->logger->error( 'notify', 'Digest failed', [ 'error' => $e->getMessage() ] );
            return false;
        }
    }

    /** Email address alerts and digests go to (settings monitoring.notify_email, else the admin email). */
    public function recipient(): string {
        $email = sanitize_email( (string) $this->settings->get( 'monitoring.notify_email', '' ) );
        if ( '' === $email || ! is_email( $email ) ) {
            $email = sanitize_email( (string) get_option( 'admin_email', '' ) );
        }
        return is_email( $email ) ? $email : '';
    }

    /**
     * @param array<string,mixed> $data
     */
    private function emailCritical( string $title, string $message, array $data ): void {
        $key = 'ppclg_ntfmail_' . md5( $title );
        if ( false !== get_transient( $key ) ) {
            $this->logger->debug( 'notify', 'Critical email throttled (same title within 6h)', [ 'title' => $title ] );
            return;
        }
        $to = $this->recipient();
        if ( '' === $to ) {
            $this->logger->warning( 'notify', 'Critical alert not emailed: no recipient configured', [ 'title' => $title ] );
            return;
        }
        set_transient( $key, time(), self::THROTTLE_SECONDS );

        $lines   = [];
        $lines[] = $title;
        $lines[] = str_repeat( '=', max( 8, min( 60, mb_strlen( $title ) ) ) );
        $lines[] = '';
        $lines[] = wordwrap( $message, 78, "\n", true );
        $details = $this->detailLines( $data );
        if ( ! empty( $details ) ) {
            $lines[] = '';
            $lines[] = 'Details:';
            foreach ( $details as $d ) {
                $lines[] = '  ' . $d;
            }
        }
        $lines[] = '';
        $lines[] = 'Open the dashboard: ' . $this->dashboardUrl();
        $lines[] = '';
        $lines[] = 'Further alerts with the same title during the next 6 hours are collected in the dashboard and the daily digest.';
        $lines[] = '';
        $lines[] = $this->footer();

        $this->mail( $to, sprintf( '[%s] Critical: %s', $this->brand(), $title ), implode( "\n", $lines ), 'critical' );
    }

    private function mail( string $to, string $subject, string $body, string $kind ): bool {
        $error   = '';
        $capture = static function ( $wpError ) use ( &$error ): void {
            if ( is_wp_error( $wpError ) ) {
                $error = $wpError->get_error_message();
            }
        };
        add_action( 'wp_mail_failed', $capture );
        $ok = false;
        try {
            $ok = (bool) wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
        } catch ( \Throwable $e ) {
            $error = $e->getMessage();
        } finally {
            remove_action( 'wp_mail_failed', $capture );
        }
        if ( ! $ok ) {
            $this->logger->warning( 'notify', 'Email could not be sent', [ 'kind' => $kind, 'subject' => $subject, 'error' => $error ] );
        } else {
            $this->logger->debug( 'notify', 'Email sent', [ 'kind' => $kind, 'subject' => $subject ] );
        }
        return $ok;
    }

    /**
     * Masks credential-like keys in notification data (emails stay visible: the dashboard is admin-only).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function scrub( array $data, int $depth = 0 ): array {
        $out = [];
        foreach ( $data as $k => $v ) {
            if ( Redactor::isSensitiveKey( strtolower( (string) $k ) ) ) {
                $out[ $k ] = '[redacted]';
                continue;
            }
            if ( is_array( $v ) ) {
                $out[ $k ] = $depth < 5 ? $this->scrub( $v, $depth + 1 ) : '[truncated]';
            } elseif ( is_object( $v ) ) {
                $out[ $k ] = $depth < 5 ? $this->scrub( (array) $v, $depth + 1 ) : '[truncated]';
            } elseif ( is_string( $v ) ) {
                $out[ $k ] = mb_substr( $v, 0, 2000 );
            } else {
                $out[ $k ] = $v;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $data
     * @return string[]
     */
    private function detailLines( array $data ): array {
        $lines = [];
        foreach ( $data as $k => $v ) {
            if ( count( $lines ) >= self::MAX_DETAIL_LINES ) {
                break;
            }
            if ( is_scalar( $v ) || null === $v ) {
                $value = is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : (string) $v;
                if ( '' === trim( $value ) ) {
                    continue;
                }
                $lines[] = sprintf( '%s: %s', str_replace( '_', ' ', (string) $k ), mb_substr( $value, 0, 300 ) );
            }
        }
        return $lines;
    }

    private function brand(): string {
        $brand = trim( (string) $this->settings->get( 'site.brand_name', '' ) );
        return '' !== $brand ? $brand : 'PPC Link Growth Engine';
    }

    private function dashboardUrl(): string {
        return admin_url( 'admin.php?page=ppclg' );
    }

    private function footer(): string {
        return sprintf( 'Sent by PPC Link Growth Engine for %s. Alert settings: Link Growth > Settings > Monitoring.', $this->settings->siteDomain() ?: $this->brand() );
    }

    /** Formats a UTC "Y-m-d H:i:s" string in the site's timezone. */
    private function localDate( string $utc ): string {
        if ( 0 === $this->timestamp( $utc ) ) {
            return $utc;
        }
        $local = get_date_from_gmt( $utc, 'M j, Y H:i' );
        return is_string( $local ) && '' !== $local ? $local : $utc;
    }

    private function timestamp( string $utc ): int {
        $utc = trim( $utc );
        if ( '' === $utc ) {
            return 0;
        }
        $ts = strtotime( $utc . ' UTC' );
        return false === $ts ? 0 : $ts;
    }
}
