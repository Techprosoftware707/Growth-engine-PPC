<?php
/**
 * Registers the recurring tick with Action Scheduler (preferred) or WP-Cron, self-healing on init.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation;

use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use Throwable;

final class Scheduler {

    public const HOOK          = 'ppclg_tick';
    public const GROUP         = 'ppclg';
    public const INTERVAL_NAME = 'ppclg_tick_interval';

    /** Transient that throttles the self-healing schedule check to once per hour. */
    public const TRANSIENT_CHECKED = 'ppclg_schedule_checked';

    /** Option remembering the interval the current schedule was created with. */
    public const OPTION_INTERVAL = 'ppclg_schedule_interval';

    private const MIN_INTERVAL = 60;

    public function __construct(
        private Pipeline $pipeline,
        private Settings $settings,
        private Logger $logger
    ) {}

    public function register(): void {
        add_action( self::HOOK, [ $this, 'onTick' ] );
        add_filter( 'cron_schedules', [ $this, 'cronSchedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
        add_action( 'init', [ $this, 'maybeSchedule' ], 20 );
    }

    /**
     * Hook callback: runs one pipeline tick and never lets an exception escape into cron.
     *
     * The recurring tick has no arguments; a kick() carries one (a timestamp) so that it never looks like the
     * recurring action. Action Scheduler passes args positionally and WP-Cron passes them through
     * call_user_func_array(), which treats string keys as PHP 8 named arguments, so the variadic accepts the
     * no-argument, positional and legacy associative forms alike instead of throwing "Unknown named parameter"
     * before this method's try/catch is reached.
     *
     * @param mixed ...$args Ignored event arguments.
     */
    public function onTick( mixed ...$args ): void {
        unset( $args );
        try {
            $this->pipeline->tick();
        } catch ( Throwable $e ) {
            $this->logger->error( 'automation', 'Tick failed: ' . $e->getMessage(), [ 'at' => basename( $e->getFile() ) . ':' . $e->getLine() ] );
        }
    }

    /** Tick interval in seconds (settings automation.tick_interval, minimum 60). */
    public function interval(): int {
        return max( self::MIN_INTERVAL, (int) $this->settings->get( 'automation.tick_interval', 300 ) );
    }

    /**
     * @param array<string,array{interval:int,display:string}> $schedules
     * @return array<string,array{interval:int,display:string}>
     */
    public function cronSchedules( $schedules ): array {
        $schedules = is_array( $schedules ) ? $schedules : [];
        $interval  = $this->interval();
        $schedules[ self::INTERVAL_NAME ] = [
            'interval' => $interval,
            'display'  => sprintf(
                /* translators: %d: number of minutes */
                __( 'Every %d minutes (PPC Link Growth)', 'ppc-link-growth-engine' ),
                max( 1, (int) round( $interval / 60 ) )
            ),
        ];
        return $schedules;
    }

    /** Self-healing: verifies the schedule at most once per hour. */
    public function maybeSchedule(): void {
        if ( get_transient( self::TRANSIENT_CHECKED ) ) {
            return;
        }
        set_transient( self::TRANSIENT_CHECKED, 1, HOUR_IN_SECONDS );
        $this->schedule();
    }

    /** Ensures exactly one recurring tick exists on the best available backend. */
    public function schedule(): void {
        try {
            $interval = $this->interval();
            $stored   = (int) get_option( self::OPTION_INTERVAL, 0 );
            if ( $stored > 0 && $stored !== $interval ) {
                // Interval changed in settings: rebuild the schedule.
                $this->unschedule();
            }

            if ( $this->actionSchedulerAvailable() ) {
                if ( ! did_action( 'init' ) ) {
                    // Action Scheduler must not be used before init; retry there.
                    add_action( 'init', [ $this, 'schedule' ], 99 );
                    return;
                }
                if ( ! $this->hasActionSchedulerTick() ) {
                    as_schedule_recurring_action( time() + 60, $interval, self::HOOK, [], self::GROUP );
                    $this->logger->info( 'automation', 'Recurring tick scheduled via Action Scheduler', [ 'interval' => $interval ] );
                }
                // Avoid double ticks when WP-Cron was used before Action Scheduler became available.
                if ( wp_next_scheduled( self::HOOK ) ) {
                    wp_clear_scheduled_hook( self::HOOK );
                }
            } elseif ( ! wp_next_scheduled( self::HOOK ) ) {
                $result = wp_schedule_event( time() + 60, self::INTERVAL_NAME, self::HOOK );
                if ( false === $result || is_wp_error( $result ) ) {
                    $this->logger->warning( 'automation', 'WP-Cron scheduling failed', [ 'error' => is_wp_error( $result ) ? $result->get_error_message() : 'unknown' ] );
                } else {
                    $this->logger->info( 'automation', 'Recurring tick scheduled via WP-Cron', [ 'interval' => $interval ] );
                }
            }
            update_option( self::OPTION_INTERVAL, $interval, false );
        } catch ( Throwable $e ) {
            $this->logger->error( 'automation', 'Scheduling failed: ' . $e->getMessage() );
        }
    }

    /** Removes every recurring and one-off tick from both backends. */
    public function unschedule(): void {
        try {
            if ( function_exists( 'as_unschedule_all_actions' ) ) {
                // Hook only: Action Scheduler then cancels every pending action of the hook whatever its args,
                // which includes kicks (they carry their own argument, so an args/group match would miss them).
                as_unschedule_all_actions( self::HOOK );
            }
        } catch ( Throwable $e ) {
            $this->logger->warning( 'automation', 'Action Scheduler unschedule failed: ' . $e->getMessage() );
        }
        try {
            if ( function_exists( 'wp_unschedule_hook' ) ) {
                wp_unschedule_hook( self::HOOK );
            }
            wp_clear_scheduled_hook( self::HOOK );
        } catch ( Throwable $e ) {
            $this->logger->warning( 'automation', 'WP-Cron unschedule failed: ' . $e->getMessage() );
        }
        delete_option( self::OPTION_INTERVAL );
        delete_transient( self::TRANSIENT_CHECKED );
    }

    /**
     * @return array{backend:string,next_run:?string,last_tick:?string,interval:int,cron_disabled:bool}
     */
    public function status(): array {
        $backend = 'none';
        $next    = null;
        try {
            if ( $this->actionSchedulerAvailable() && did_action( 'init' ) ) {
                $ts = $this->nextActionSchedulerRun();
                if ( null !== $ts ) {
                    $backend = 'action_scheduler';
                    $next    = $ts;
                }
            }
            if ( 'none' === $backend ) {
                $ts = wp_next_scheduled( self::HOOK );
                if ( is_int( $ts ) && $ts > 0 ) {
                    $backend = 'wp_cron';
                    $next    = $ts;
                }
            }
        } catch ( Throwable $e ) {
            $this->logger->warning( 'automation', 'Scheduler status check failed: ' . $e->getMessage() );
        }
        $lastTick = get_option( Pipeline::OPTION_LAST_TICK, '' );
        return [
            'backend'       => $backend,
            'next_run'      => null !== $next ? gmdate( 'c', $next ) : null,
            'last_tick'     => is_string( $lastTick ) && '' !== $lastTick ? $lastTick : null,
            'interval'      => $this->interval(),
            'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && (bool) DISABLE_WP_CRON,
        ];
    }

    /** Triggers a tick as soon as possible (asynchronously). */
    public function kick(): bool {
        try {
            if ( function_exists( 'as_enqueue_async_action' ) && did_action( 'init' ) ) {
                // A kick carries an argument so that it never matches the recurring action's empty args: otherwise
                // hasActionSchedulerTick() would mistake a pending kick for the schedule and not repair a lost one.
                $id = as_enqueue_async_action( self::HOOK, [ time() ], self::GROUP );
                if ( (int) $id > 0 ) {
                    return true;
                }
            }
            // WP-Cron fallback: a unique argument sidesteps the 10-minute duplicate-event guard. It must be a
            // positional (list) argument: cron hands args to call_user_func_array(), where string keys are named arguments.
            $scheduled = wp_schedule_single_event( time(), self::HOOK, [ time() ] );
            if ( false === $scheduled || is_wp_error( $scheduled ) ) {
                return false;
            }
            return (bool) spawn_cron();
        } catch ( Throwable $e ) {
            $this->logger->warning( 'automation', 'Kick failed: ' . $e->getMessage() );
            return false;
        }
    }

    private function actionSchedulerAvailable(): bool {
        return function_exists( 'as_schedule_recurring_action' ) && ( function_exists( 'as_has_scheduled_action' ) || function_exists( 'as_next_scheduled_action' ) );
    }

    /** Whether the recurring tick exists (pending or running). Kicks do not count: their args are never empty. */
    private function hasActionSchedulerTick(): bool {
        if ( function_exists( 'as_has_scheduled_action' ) ) {
            return (bool) as_has_scheduled_action( self::HOOK, [], self::GROUP );
        }
        $next = as_next_scheduled_action( self::HOOK, [], self::GROUP );
        return false !== $next && null !== $next;
    }

    private function nextActionSchedulerRun(): ?int {
        if ( ! function_exists( 'as_next_scheduled_action' ) ) {
            return null;
        }
        $next = as_next_scheduled_action( self::HOOK, [], self::GROUP );
        if ( is_int( $next ) && $next > 0 ) {
            return $next;
        }
        if ( true === $next ) {
            return time(); // the recurring action is running (or an argument-less action is due now)
        }
        return null;
    }
}
