<?php
/**
 * Decides whether the plugin's background work (the scheduler tick, kicks, Grow now steps) should pause right now
 * because the server is busy with something more important.
 *
 * Live 1.2.3 incident: right after a plugin update PHP's code cache (OPcache) was full and an UpdraftPlus backup
 * held PHP-FPM workers for minutes; every background tick took another worker for up to 20 s and the store answered
 * 504 for about 20 minutes. Background work now pauses while:
 *
 *  1. OPcache is restarting (opcache_get_status() reports restart_pending or restart_in_progress): every request
 *     waits for the running ones, so a 20-second job holds up the whole site;
 *  2. a backup is running: UpdraftPlus has a resumption ('updraft_backup_resume') or a "Backup now" event due in
 *     the next 30 minutes (or overdue by less than an hour) in the cron array, and the job's own record
 *     (updraft_jobdata_<nonce>) does not say "finished"; other backup plugins report through the
 *     'ppclg_backup_running' filter;
 *  3. the plugin was updated or activated less than 10 minutes ago, so OPcache can warm up and other plugins finish
 *     their own post-update work;
 *  4. the 'ppclg_pause_background' filter says so (true or a message pauses; false lets the work run even when one
 *     of the reasons above was found).
 *
 * A full OPcache on its own never pauses anything (that state can last for months and would stop Autopilot for
 * good); Admin\SiteHealth tells the owner about it instead.
 *
 * Cheap by design: no HTTP request; the cron array and the version marker are autoloaded options (already in
 * memory), opcache_get_status(false) skips the script list, and the only database read is one UpdraftPlus job record
 * when a resumption event exists. The answer is kept for the rest of the request; Pipeline::tick() asks afresh.
 *
 * Nothing is lost while paused, only delayed: queued jobs stay queued, cadences are not consumed, outreach keeps its
 * send window and limits.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation;

use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Opcache;
use Throwable;

final class LoadGuard {

    /** Autoloaded marker: { version, since (unix time of the update / activation), from }. */
    public const OPTION_VERSION = 'ppclg_running_version';

    /** The pause the background work last recorded (non-autoloaded): { code, message, text, since, source }. */
    public const OPTION_PAUSE = 'ppclg_background_pause';

    /** Quiet window after an update or activation. */
    public const UPDATE_QUIET_SECONDS = 600;

    /** A backup counts as running while one of its events is due within this many seconds … */
    public const BACKUP_LOOKAHEAD_SECONDS = 1800;

    /** … or overdue by less than this (an older overdue event is a leftover, e.g. from a site copy). */
    public const BACKUP_OVERDUE_SECONDS = 3600;

    /** UpdraftPlus: the resumption event every running backup keeps scheduled (args: [resumption, nonce]). */
    public const UPDRAFT_RESUME_HOOK = 'updraft_backup_resume';

    /** UpdraftPlus "Backup now" events that start a backup within seconds. */
    public const UPDRAFT_START_HOOKS = [ 'updraft_backupnow_backup', 'updraft_backupnow_backup_all', 'updraft_backupnow_backup_database' ];

    /** UpdraftPlus job record, per backup nonce. */
    public const UPDRAFT_JOBDATA_PREFIX = 'updraft_jobdata_';

    public const FILTER_BACKUP = 'ppclg_backup_running';
    public const FILTER_PAUSE  = 'ppclg_pause_background';

    public const CODE_OPCACHE_RESTART = 'opcache_restart';
    public const CODE_BACKUP          = 'backup';
    public const CODE_UPDATED         = 'just_updated';
    public const CODE_OWNER           = 'owner';

    /** How long the Grow now panel waits before it asks again while paused. */
    public const RETRY_AFTER_SECONDS = 60;

    private bool $checked = false;

    /** @var array<string,mixed>|null */
    private ?array $reason = null;

    public function __construct( private ?Opcache $opcache = null ) {}

    /**
     * Why background work should pause now, or null to go.
     *
     * @param bool $fresh Check again instead of using this request's earlier answer.
     * @return array{code:string,message:string,text:string,until:?string,source:string}|null
     */
    public function pauseReason( bool $fresh = false ): ?array {
        if ( $this->checked && ! $fresh ) {
            return $this->reason;
        }
        if ( $fresh && null !== $this->opcache ) {
            $this->opcache->forget();
        }
        $this->checked = true;
        $this->noteVersion();

        $reason = null;
        try {
            $reason = $this->opcacheRestarting() ?? $this->backupRunning() ?? $this->justUpdated();
        } catch ( Throwable $e ) {
            $reason = null; // a check that fails never stops the plugin's work
        }

        try {
            /**
             * Pauses (or lets run) the plugin's background work: the scheduler tick, kicks and Grow now steps.
             * Return true or a short message to pause, false to run even when a reason above was found.
             *
             * @param bool|string $pause  Whether a built-in reason was found.
             * @param array|null  $reason The built-in reason ({code, message, text, until, source}) or null.
             */
            $filtered = apply_filters( self::FILTER_PAUSE, null !== $reason, $reason );
            if ( false === $filtered ) {
                $reason = null;
            } elseif ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
                $reason = self::reason( self::CODE_OWNER, self::clean( $filtered ), null, 'filter' );
            } elseif ( true === $filtered && null === $reason ) {
                $reason = self::reason( self::CODE_OWNER, 'background work was paused for this site', null, 'filter' );
            }
        } catch ( Throwable $e ) {
            unset( $e );
        }

        $this->reason = $reason;
        return $reason;
    }

    /** Forgets this request's answer (the next pauseReason() checks again). */
    public function forget(): void {
        $this->checked = false;
        $this->reason  = null;
        if ( null !== $this->opcache ) {
            $this->opcache->forget();
        }
    }

    /**
     * Records an update: when the running PPCLG_VERSION differs from the stored one, the time is stored with the
     * new version (the quiet window starts). Writes nothing when the version is unchanged.
     */
    public function noteVersion(): void {
        try {
            $stored  = get_option( self::OPTION_VERSION, null );
            $version = is_array( $stored ) ? (string) ( $stored['version'] ?? '' ) : '';
            if ( $version === self::running() ) {
                return;
            }
            update_option( self::OPTION_VERSION, [ 'version' => self::running(), 'since' => time(), 'from' => $version ], true );
        } catch ( Throwable $e ) {
            unset( $e );
        }
    }

    /** Activation (Activator): starts the quiet window even when the version did not change. */
    public function recordActivation(): void {
        try {
            $stored = get_option( self::OPTION_VERSION, null );
            $from   = is_array( $stored ) ? (string) ( $stored['version'] ?? '' ) : '';
            update_option( self::OPTION_VERSION, [ 'version' => self::running(), 'since' => time(), 'from' => $from ], true );
        } catch ( Throwable $e ) {
            unset( $e );
        }
        $this->forget();
    }

    /**
     * Keeps the pause record in step with $reason and logs each change once: a new pause (or another reason) is
     * stored and logged; the first check that finds no reason logs the resume and removes the record. Returns the
     * reason with `since` (when this pause began), or null.
     *
     * @param array<string,mixed>|null $reason pauseReason()
     * @return array<string,mixed>|null
     */
    public function track( ?array $reason, ?Logger $logger = null ): ?array {
        $stored = $this->recorded();
        if ( null === $reason ) {
            if ( null !== $stored ) {
                delete_option( self::OPTION_PAUSE );
                $since   = is_string( $stored['since'] ?? null ) ? strtotime( (string) $stored['since'] ) : false;
                $minutes = false !== $since && $since > 0 ? max( 1, (int) round( ( time() - $since ) / 60 ) ) : 0;
                $this->log(
                    $logger,
                    $minutes > 0
                        ? sprintf( 'Background work resumed after %d %s (%s).', $minutes, 1 === $minutes ? 'minute' : 'minutes', (string) ( $stored['message'] ?? '' ) )
                        : sprintf( 'Background work resumed (%s).', (string) ( $stored['message'] ?? '' ) ),
                    [ 'code' => (string) ( $stored['code'] ?? '' ) ]
                );
            }
            return null;
        }
        if ( null !== $stored && (string) ( $stored['code'] ?? '' ) === (string) $reason['code'] ) {
            $reason['since'] = (string) ( $stored['since'] ?? '' );
            return $reason;
        }
        $reason['since'] = gmdate( 'c' );
        update_option(
            self::OPTION_PAUSE,
            [ 'code' => (string) $reason['code'], 'message' => (string) $reason['message'], 'text' => (string) $reason['text'], 'since' => $reason['since'], 'source' => (string) ( $reason['source'] ?? '' ) ],
            false
        );
        $this->log( $logger, $reason['text'], [ 'code' => (string) $reason['code'], 'source' => (string) ( $reason['source'] ?? '' ), 'until' => $reason['until'] ?? null ] );
        return $reason;
    }

    /**
     * The stored pause record, or null.
     *
     * @return array<string,mixed>|null
     */
    public function recorded(): ?array {
        $stored = get_option( self::OPTION_PAUSE, null );
        return is_array( $stored ) && '' !== (string) ( $stored['code'] ?? '' ) ? $stored : null;
    }

    /**
     * The live reason with `since` from the record when it is the same pause (for the dashboard), or null.
     *
     * @return array<string,mixed>|null
     */
    public function status(): ?array {
        $reason = $this->pauseReason();
        if ( null === $reason ) {
            return null;
        }
        $stored = $this->recorded();
        $reason['since'] = null !== $stored && (string) ( $stored['code'] ?? '' ) === $reason['code'] ? (string) ( $stored['since'] ?? '' ) : null;
        return $reason;
    }

    /**
     * "Background work is paused: a backup is running. It resumes automatically." for answers to manual actions.
     *
     * @param array<string,mixed> $reason
     */
    public static function warning( array $reason ): string {
        $message = rtrim( (string) ( $reason['message'] ?? '' ), '.' );
        if ( self::CODE_OWNER === ( $reason['code'] ?? '' ) ) {
            return sprintf( 'Background work is paused: %s.', $message );
        }
        return sprintf( 'Background work is paused: %s. It resumes automatically.', $message );
    }

    // ------------------------------------------------------------------
    // The reasons
    // ------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function opcacheRestarting(): ?array {
        $opcache = $this->opcache ?? new Opcache();
        if ( ! $opcache->restarting() ) {
            return null;
        }
        return self::reason( self::CODE_OPCACHE_RESTART, "PHP's code cache is restarting", null, 'opcache' );
    }

    /** @return array<string,mixed>|null */
    private function backupRunning(): ?array {
        if ( $this->updraftRunning() ) {
            return self::reason( self::CODE_BACKUP, 'a backup is running', null, 'updraftplus' );
        }
        /**
         * Lets other backup plugins report a running backup. Return true, or the backup plugin's name.
         *
         * @param bool $running
         */
        $other = apply_filters( self::FILTER_BACKUP, false );
        if ( true === $other || ( is_string( $other ) && '' !== trim( $other ) ) ) {
            return self::reason( self::CODE_BACKUP, 'a backup is running', null, is_string( $other ) ? self::clean( $other ) : 'filter' );
        }
        return null;
    }

    /**
     * An UpdraftPlus resumption or "Backup now" event in the cron array, due within BACKUP_LOOKAHEAD_SECONDS (or
     * overdue by less than BACKUP_OVERDUE_SECONDS), whose job record does not say "finished".
     */
    private function updraftRunning(): bool {
        $cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : get_option( 'cron', [] );
        if ( ! is_array( $cron ) || [] === $cron ) {
            return false;
        }
        $now   = time();
        $hooks = array_merge( [ self::UPDRAFT_RESUME_HOOK ], self::UPDRAFT_START_HOOKS );
        foreach ( $cron as $timestamp => $events ) {
            if ( ! is_int( $timestamp ) && ! ctype_digit( (string) $timestamp ) ) {
                continue; // the 'version' entry
            }
            $at = (int) $timestamp;
            if ( $at > $now + self::BACKUP_LOOKAHEAD_SECONDS || $at < $now - self::BACKUP_OVERDUE_SECONDS || ! is_array( $events ) ) {
                continue;
            }
            foreach ( $hooks as $hook ) {
                if ( empty( $events[ $hook ] ) || ! is_array( $events[ $hook ] ) ) {
                    continue;
                }
                if ( self::UPDRAFT_RESUME_HOOK !== $hook ) {
                    return true; // "Backup now" starts within seconds
                }
                foreach ( $events[ $hook ] as $event ) {
                    $args  = is_array( $event ) && is_array( $event['args'] ?? null ) ? array_values( $event['args'] ) : [];
                    $nonce = isset( $args[1] ) && is_string( $args[1] ) ? $args[1] : '';
                    if ( ! $this->updraftJobFinished( $nonce ) ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** The UpdraftPlus job record for $nonce says "finished" (a leftover event of a completed backup). */
    private function updraftJobFinished( string $nonce ): bool {
        if ( 1 !== preg_match( '/^[A-Za-z0-9]{4,40}$/', $nonce ) ) {
            return false;
        }
        $name = self::UPDRAFT_JOBDATA_PREFIX . $nonce;
        // UpdraftPlus keeps job data as a network option (a normal option on a single site).
        $job = function_exists( 'get_site_option' ) ? get_site_option( $name, null ) : get_option( $name, null );
        return is_array( $job ) && 'finished' === (string) ( $job['jobstatus'] ?? '' );
    }

    /** @return array<string,mixed>|null */
    private function justUpdated(): ?array {
        $stored = get_option( self::OPTION_VERSION, null );
        $since  = is_array( $stored ) ? (int) ( $stored['since'] ?? 0 ) : 0;
        $now    = time();
        if ( $since <= 0 || $since > $now + 60 ) {
            return null;
        }
        $until = $since + self::UPDATE_QUIET_SECONDS;
        if ( $now >= $until ) {
            return null;
        }
        return self::reason( self::CODE_UPDATED, 'the plugin was just updated', gmdate( 'c', $until ), 'update' );
    }

    // ------------------------------------------------------------------

    /**
     * @return array{code:string,message:string,text:string,until:?string,source:string}
     */
    private static function reason( string $code, string $message, ?string $until, string $source ): array {
        $text = sprintf( 'Paused: %s (resumes automatically).', $message );
        if ( self::CODE_OWNER === $code ) {
            $text = sprintf( 'Paused: %s.', $message ); // lasts as long as the site's own filter says so
        } elseif ( null !== $until ) {
            $left = (int) ceil( max( 0, (int) strtotime( $until ) - time() ) / 60 );
            $text = sprintf( 'Paused: %s (resumes automatically in about %d %s).', $message, max( 1, $left ), 1 === max( 1, $left ) ? 'minute' : 'minutes' );
        }
        return [ 'code' => $code, 'message' => $message, 'text' => $text, 'until' => $until, 'source' => $source ];
    }

    private static function running(): string {
        return defined( 'PPCLG_VERSION' ) ? (string) PPCLG_VERSION : '';
    }

    private static function clean( string $text ): string {
        $text = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $text ) ) );
        return mb_substr( rtrim( $text, '.' ), 0, 160 );
    }

    /** @param array<string,mixed> $context */
    private function log( ?Logger $logger, string $message, array $context ): void {
        if ( null === $logger ) {
            return;
        }
        try {
            $logger->info( 'automation', $message, $context );
        } catch ( Throwable $e ) {
            unset( $e );
        }
    }
}
