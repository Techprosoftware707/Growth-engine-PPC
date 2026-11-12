<?php
/**
 * Shared helpers for job types (continuation, results, flags, services).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Automation\Audit;
use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Growth\GrowthRun;
use PPC\LinkGrowth\Monitoring\Notifier;
use PPC\LinkGrowth\Repositories\Jobs;
use PPC\LinkGrowth\Support\Limits;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use Throwable;

abstract class AbstractJob implements JobInterface {

    /** Automation flags that a manual run never bypasses (real-world side effects). */
    protected const HARD_GATES = [ 'submissions', 'outreach_autosend' ];

    /** Upper bound on how many times one job may re-enqueue itself in a chain. */
    private const MAX_CONTINUATIONS = 100;

    /** A warning is logged every time a job has been deferred this many times in a row. */
    private const DEFERRAL_WARN_EVERY = 10;

    public static function priority(): int {
        return 5;
    }

    /**
     * Re-enqueue this job type (same payload) so remaining work continues on the next tick.
     * Nothing is enqueued when a job of this type is already waiting in the queue.
     *
     * @param array<string,mixed> $payload
     * @return int|null Job id, or null when nothing was enqueued.
     */
    public function continueLater( Container $c, array $payload, int $delay = 60 ): ?int {
        unset( $payload['deferrals'] ); // the run made progress: the consecutive-deferral count restarts
        return $this->enqueueContinuation( $c, $payload, $delay );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function enqueueContinuation( Container $c, array $payload, int $delay ): ?int {
        try {
            $jobs = $c->get( Jobs::class );
            if ( $jobs->count( [ 'type' => static::type(), 'status' => Jobs::STATUS_QUEUED ] ) > 0 ) {
                return null;
            }
            $run = (string) ( $payload['growth_run'] ?? '' );
            if ( '' !== $run && class_exists( GrowthRun::class ) && GrowthRun::isCancelledRun( $run ) ) {
                // Stop was clicked while this job ran: the cancelled run's chain ends here.
                $this->logger( $c )->info( 'jobs', 'Not continuing: the growth run this job belongs to was cancelled.', [ 'type' => static::type(), 'growth_run' => $run ] );
                return null;
            }
            $chain = (int) ( $payload['continued'] ?? 0 );
            if ( $chain >= self::MAX_CONTINUATIONS ) {
                $this->logger( $c )->warning( 'jobs', 'Continuation chain limit reached; not re-enqueueing.', [ 'type' => static::type() ] );
                return null;
            }
            $payload['continued'] = $chain + 1;
            return $jobs->enqueue( static::type(), $payload, static::priority(), max( 0, $delay ) );
        } catch ( Throwable $e ) {
            $this->logger( $c )->error( 'jobs', 'Could not re-enqueue job: ' . $e->getMessage(), [ 'type' => static::type() ] );
            return null;
        }
    }

    /**
     * Wraps a job result with the job type and completion time.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function result( array $data ): array {
        return array_merge( [ 'job' => static::type(), 'completed_at' => gmdate( 'c' ) ], $data );
    }

    /**
     * Result for a run that did no work.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    protected function skipped( string $reason, array $extra = [] ): array {
        return $this->result( array_merge( [ 'skipped' => true, 'reason' => $reason ], $extra ) );
    }

    /**
     * Nothing could be done within the remaining time budget: re-queue this job for the next tick
     * (so a cadence stage is not silently postponed by a full period) and report that.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    protected function deferred( Container $c, array $payload, string $reason = 'time_budget', array $extra = [] ): array {
        $deferrals             = max( 0, (int) ( $payload['deferrals'] ?? 0 ) ) + 1;
        $payload['deferrals'] = $deferrals;
        if ( 0 === $deferrals % self::DEFERRAL_WARN_EVERY ) {
            // Starvation must be visible: a job that never gets enough time is otherwise recorded as "completed".
            $this->logger( $c )->warning(
                'jobs',
                sprintf( 'The "%s" stage has been deferred %d times in a row (%s) without doing any work; check Settings → Automation → Max seconds per tick and the PHP time limit.', static::type(), $deferrals, $reason ),
                [ 'type' => static::type(), 'deferrals' => $deferrals, 'reason' => $reason ]
            );
        }
        $id = $this->enqueueContinuation( $c, $payload, 60 );
        return $this->skipped( $reason, array_merge( $extra, [ 'requeued' => null !== $id, 'requeued_job_id' => $id, 'deferrals' => $deferrals ] ) );
    }

    /** @param array<string,mixed> $payload */
    protected function isManual( array $payload ): bool {
        return ! empty( $payload['manual'] );
    }

    /**
     * Whether an automation flag (settings automation.{flag}) allows this run.
     * A manual run (payload manual=true) may proceed when a soft flag is off; hard gates always apply.
     *
     * @param array<string,mixed> $payload
     */
    protected function flag( Container $c, array $payload, string $flag ): bool {
        if ( (bool) $this->settings( $c )->get( 'automation.' . $flag, false ) ) {
            return true;
        }
        return $this->isManual( $payload ) && ! in_array( $flag, self::HARD_GATES, true );
    }

    protected function settings( Container $c ): Settings {
        return $c->get( Settings::class );
    }

    protected function logger( Container $c ): Logger {
        return $c->get( Logger::class );
    }

    protected function limits( Container $c ): Limits {
        return $c->get( Limits::class );
    }

    protected function audit( Container $c ): Audit {
        return $c->get( Audit::class );
    }

    /**
     * Stores a dashboard notification; never throws (the notifier is optional at runtime).
     *
     * @param array<string,mixed> $data
     */
    protected function notify( Container $c, string $type, string $severity, string $title, string $message, array $data = [] ): void {
        try {
            if ( ! class_exists( Notifier::class ) ) {
                return;
            }
            $c->get( Notifier::class )->notify( $type, $severity, $title, $message, $data );
        } catch ( Throwable $e ) {
            $this->logger( $c )->warning( 'automation', 'Notification could not be stored: ' . $e->getMessage(), [ 'title' => $title ] );
        }
    }

    /**
     * Compact, redaction-safe context for logging an exception.
     *
     * @return array<string,mixed>
     */
    protected function errorContext( Throwable $e ): array {
        return [
            'error' => $e->getMessage(),
            'class' => get_class( $e ),
            'at'    => basename( $e->getFile() ) . ':' . $e->getLine(),
        ];
    }

    /** Current UTC timestamp for datetime columns. */
    protected function now(): string {
        return gmdate( 'Y-m-d H:i:s' );
    }

    /** UTC datetime $days from now. */
    protected function inDays( int $days ): string {
        return gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
    }
}
