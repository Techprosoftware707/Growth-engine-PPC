<?php
/**
 * Orchestrates automation stages: cadence-based enqueueing, direct runs and status.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Monitoring\Notifier;
use PPC\LinkGrowth\Repositories\Backlinks;
use PPC\LinkGrowth\Repositories\Jobs;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Support\Limits;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use Throwable;

final class Pipeline {

    public const OPTION_LAST_TICK  = 'ppclg_last_tick';
    public const OPTION_STAGE_RUNS = 'ppclg_stage_runs';

    /** Per-stage automation flags exposed in status(). */
    public const FLAGS = [ 'discovery', 'evaluation', 'opportunities', 'packaging', 'submissions', 'outreach_autosend', 'monitoring', 'imports' ];

    /** Stale "running" jobs older than this are re-queued on every tick. */
    private const STALE_SECONDS = 900;

    private const FALLBACK_MAX_SECONDS = 20.0;

    public function __construct(
        private Jobs $jobs,
        private JobRunner $runner,
        private Settings $settings,
        private Limits $limits,
        private Logger $logger
    ) {}

    /**
     * One scheduler tick: enqueue due stages (when automation is enabled) and run the queue.
     *
     * @return array{enqueued:string[],run:array{ran:int,completed:int,failed:int,skipped:bool},skipped_reason:string}
     */
    public function tick(): array {
        update_option( self::OPTION_LAST_TICK, gmdate( 'c' ), false );
        $out = [
            'enqueued'       => [],
            'run'            => [ 'ran' => 0, 'completed' => 0, 'failed' => 0, 'skipped' => false ],
            'skipped_reason' => '',
        ];

        try {
            $recovered = $this->jobs->recoverStale( self::STALE_SECONDS );
            if ( $recovered > 0 ) {
                $this->logger->warning( 'automation', 'Recovered stale running jobs', [ 'count' => $recovered ] );
            }
        } catch ( Throwable $e ) {
            $this->logger->error( 'automation', 'Stale job recovery failed: ' . $e->getMessage() );
        }

        if ( (bool) $this->settings->get( 'automation.enabled', false ) ) {
            $out['enqueued'] = $this->enqueueDueStages();
        } else {
            $out['skipped_reason'] = 'automation_disabled';
            $this->cancelAutomaticQueued();
            if ( ! $this->hasQueuedManual() ) {
                return $out;
            }
        }

        try {
            $out['run'] = $this->runner->run( TimeBudget::seconds( $this->maxSeconds() ) );
        } catch ( Throwable $e ) {
            $this->logger->error( 'automation', 'Job runner crashed: ' . $e->getMessage() );
        }
        if ( ! empty( $out['run']['skipped'] ) && '' === $out['skipped_reason'] ) {
            $out['skipped_reason'] = 'runner_busy';
        }
        return $out;
    }

    /**
     * Enqueues a stage unless an identical job is already queued/running.
     *
     * @param array<string,mixed> $payload
     */
    public function enqueueStage( string $type, array $payload = [], int $priority = 5, bool $manual = false ): ?int {
        if ( ! isset( $this->runner->types()[ $type ] ) ) {
            $this->logger->warning( 'automation', 'Refusing to enqueue unknown job type', [ 'type' => $type ] );
            return null;
        }
        if ( $manual ) {
            $payload['manual'] = true;
        }
        try {
            $pending = [] === $payload ? $this->jobs->hasPending( $type ) : $this->jobs->hasPending( $type, $payload );
            if ( $pending ) {
                return null;
            }
            $id = $this->jobs->enqueue( $type, $payload, $priority );
        } catch ( Throwable $e ) {
            $this->logger->error( 'automation', 'Could not enqueue stage: ' . $e->getMessage(), [ 'type' => $type ] );
            return null;
        }
        $this->logger->debug( 'automation', 'Stage enqueued', [ 'type' => $type, 'job_id' => $id, 'manual' => $manual, 'priority' => $priority ] );
        return $id;
    }

    /**
     * Runs a stage immediately under the runner lock; queues it (priority 1) when the runner is busy.
     *
     * @param array<string,mixed> $payload
     * @return array{result:array<string,mixed>,job_id:?int}
     */
    public function runNow( string $type, array $payload, float $seconds ): array {
        if ( ! isset( $this->runner->types()[ $type ] ) ) {
            return [ 'result' => [ 'ok' => false, 'error' => sprintf( 'Unknown job type "%s".', $type ) ], 'job_id' => null ];
        }
        $payload['manual'] = true;
        $seconds           = max( 1.0, min( 120.0, $seconds ) );

        if ( ! $this->runner->lock( (int) ceil( $seconds ) + 60 ) ) {
            $id = $this->enqueueStage( $type, $payload, 1, true );
            return [
                'result' => [
                    'ok'        => true,
                    'queued'    => true,
                    'duplicate' => null === $id,
                    'message'   => null === $id
                        ? 'The job runner is busy and this job is already queued; it will run on the next tick.'
                        : 'The job runner is busy; the job has been queued with top priority and will run on the next tick.',
                ],
                'job_id' => $id,
            ];
        }

        try {
            $result = $this->runner->runType( $type, $payload, TimeBudget::seconds( $seconds ) );
        } finally {
            $this->runner->unlock();
        }
        $this->recordStageRun( $type );
        try {
            $this->container()->get( Audit::class )->record(
                'automation.run_now',
                'job',
                0,
                [ 'type' => $type, 'payload' => $payload, 'ok' => (bool) ( $result['ok'] ?? true ), 'elapsed' => $result['elapsed'] ?? null ]
            );
        } catch ( Throwable $e ) {
            unset( $e );
        }
        return [ 'result' => $result, 'job_id' => null ];
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array {
        $flags = [];
        foreach ( self::FLAGS as $flag ) {
            $flags[ $flag ] = (bool) $this->settings->get( 'automation.' . $flag, false );
        }
        $stages = [];
        foreach ( $this->stages() as $type => $stage ) {
            $stages[ $type ] = [
                'priority' => $stage['priority'],
                'cadence'  => $stage['cadence'],
                'flag'     => $stage['flag'],
            ];
        }
        $queue = [];
        $recent = [];
        try {
            $queue  = $this->jobs->statusCounts();
            $recent = $this->jobs->findBy( [], 'id', 'DESC', 20 );
        } catch ( Throwable $e ) {
            $this->logger->error( 'automation', 'Could not read job queue: ' . $e->getMessage() );
        }
        $lastTick = get_option( self::OPTION_LAST_TICK, '' );
        $results  = get_option( JobRunner::OPTION_STAGE_RESULTS, [] );

        return [
            'automation_enabled'    => (bool) $this->settings->get( 'automation.enabled', false ),
            'flags'                 => $flags,
            'queue'                 => $queue,
            'last_tick'             => is_string( $lastTick ) && '' !== $lastTick ? $lastTick : null,
            'stage_runs'            => $this->stageRuns(),
            'stage_results'         => is_array( $results ) ? $results : [],
            'stages'                => $stages,
            'limits'                => $this->limits->snapshot(),
            'recent_jobs'           => $recent,
            'locked'                => $this->runner->isLocked(),
            'max_execution_seconds' => $this->maxSeconds(),
            'tick_interval'         => max( 60, (int) $this->settings->get( 'automation.tick_interval', 300 ) ),
        ];
    }

    /** Cancels queued jobs (optionally of one type). */
    public function cancelQueued( ?string $type = null ): int {
        $n = $this->jobs->cancelQueued( $type );
        $this->logger->info( 'automation', 'Queued jobs cancelled', [ 'type' => $type ?? '(all)', 'count' => $n ] );
        try {
            $this->container()->get( Audit::class )->record( 'automation.cancel_queued', 'job', 0, [ 'type' => $type, 'count' => $n ] );
        } catch ( Throwable $e ) {
            unset( $e );
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Stage definitions: cadence in seconds (0 = every tick, subject to the optional due-check),
     * governing automation flag ('' = none) and priority from the job class.
     *
     * @return array<string,array{every:int,flag:string,due:?string,priority:int,cadence:string}>
     */
    private function stages(): array {
        $importDays = max( 1, (int) $this->settings->get( 'monitoring.import_interval_days', 7 ) );
        $defs       = [
            'discover'           => [ 'every' => DAY_IN_SECONDS, 'flag' => 'discovery', 'due' => null, 'cadence' => 'daily' ],
            'mention_scan'       => [ 'every' => WEEK_IN_SECONDS, 'flag' => 'discovery', 'due' => null, 'cadence' => 'weekly' ],
            'evaluate'           => [ 'every' => 0, 'flag' => 'evaluation', 'due' => 'evaluateDue', 'cadence' => 'every tick (when prospects are due)' ],
            'find_opportunities' => [ 'every' => 0, 'flag' => 'opportunities', 'due' => 'opportunitiesDue', 'cadence' => 'every tick (when qualified prospects lack opportunities)' ],
            'package'            => [ 'every' => HOUR_IN_SECONDS, 'flag' => 'packaging', 'due' => null, 'cadence' => 'hourly' ],
            'submit'             => [ 'every' => 0, 'flag' => 'submissions', 'due' => 'submitDue', 'cadence' => 'every tick (when approved submissions are queued)' ],
            'verify_listings'    => [ 'every' => 6 * HOUR_IN_SECONDS, 'flag' => '', 'due' => null, 'cadence' => 'every 6 hours' ],
            'outreach_send'      => [ 'every' => 0, 'flag' => '', 'due' => 'outreachDue', 'cadence' => 'every tick (when approved messages are ready)' ],
            'follow_ups'         => [ 'every' => DAY_IN_SECONDS, 'flag' => '', 'due' => null, 'cadence' => 'daily' ],
            'monitor_backlinks'  => [ 'every' => 0, 'flag' => 'monitoring', 'due' => 'monitorDue', 'cadence' => 'every tick (when backlinks are due)' ],
            'import_backlinks'   => [ 'every' => $importDays * DAY_IN_SECONDS, 'flag' => 'imports', 'due' => null, 'cadence' => sprintf( 'every %d days', $importDays ) ],
            'cleanup'            => [ 'every' => DAY_IN_SECONDS, 'flag' => '', 'due' => null, 'cadence' => 'daily' ],
            'metrics_refresh'    => [ 'every' => WEEK_IN_SECONDS, 'flag' => '', 'due' => null, 'cadence' => 'weekly' ],
        ];
        $types = $this->runner->types();
        $out   = [];
        foreach ( $defs as $type => $def ) {
            if ( ! isset( $types[ $type ] ) ) {
                continue;
            }
            $def['priority'] = max( 1, min( 9, (int) $types[ $type ]::priority() ) );
            $out[ $type ]    = $def;
        }
        // Registered-but-unscheduled types (e.g. broken_link_scan, custom ones) are still listed for the dashboard.
        foreach ( $types as $type => $class ) {
            if ( ! isset( $out[ $type ] ) ) {
                $out[ $type ] = [ 'every' => -1, 'flag' => '', 'due' => null, 'cadence' => 'manual', 'priority' => max( 1, min( 9, (int) $class::priority() ) ) ];
            }
        }
        return $out;
    }

    /** @return string[] enqueued types */
    private function enqueueDueStages(): array {
        $enqueued = [];
        $runs     = $this->stageRuns();
        $now      = time();
        foreach ( $this->stages() as $type => $stage ) {
            if ( $stage['every'] < 0 ) {
                continue; // manual-only
            }
            try {
                if ( '' !== $stage['flag'] && ! (bool) $this->settings->get( 'automation.' . $stage['flag'], false ) ) {
                    continue;
                }
                $last = isset( $runs[ $type ] ) ? (int) strtotime( (string) $runs[ $type ] ) : 0;
                if ( $stage['every'] > 0 && $last > 0 && ( $now - $last ) < $stage['every'] ) {
                    continue;
                }
                if ( null !== $stage['due'] && ! $this->{$stage['due']}() ) {
                    continue;
                }
                $id = $this->enqueueStage( $type, [], $stage['priority'] );
                if ( null !== $id ) {
                    $enqueued[]     = $type;
                    $runs[ $type ] = gmdate( 'c', $now );
                }
            } catch ( Throwable $e ) {
                $this->logger->error( 'automation', 'Stage scheduling failed: ' . $e->getMessage(), [ 'type' => $type ] );
            }
        }
        if ( [] !== $enqueued ) {
            update_option( self::OPTION_STAGE_RUNS, $runs, false );
        }
        return $enqueued;
    }

    private function evaluateDue(): bool {
        if ( ! $this->limits->allow( 'evaluations' ) ) {
            return false;
        }
        return [] !== $this->container()->get( Prospects::class )->dueForEvaluation( 1 );
    }

    private function opportunitiesDue(): bool {
        return [] !== $this->container()->get( Prospects::class )->qualifiedWithoutOpportunities( 1 );
    }

    private function submitDue(): bool {
        if ( ! $this->limits->allow( 'submissions' ) ) {
            return false;
        }
        return [] !== $this->container()->get( Submissions::class )->readyToProcess( 1 );
    }

    private function outreachDue(): bool {
        if ( ! $this->limits->allow( 'outreach_emails' ) ) {
            return false;
        }
        return [] !== $this->container()->get( OutreachMessages::class )->readyToSend( 1 );
    }

    private function monitorDue(): bool {
        if ( $this->limits->allow( 'backlink_checks' ) && [] !== $this->container()->get( Backlinks::class )->dueForCheck( 1 ) ) {
            return true;
        }
        return $this->digestDue();
    }

    private function digestDue(): bool {
        try {
            if ( ! class_exists( Notifier::class ) ) {
                return false;
            }
            return (bool) $this->container()->get( Notifier::class )->digestDue();
        } catch ( Throwable $e ) {
            return false;
        }
    }

    /**
     * When automation is switched off, automatically enqueued work must not run; manual jobs stay queued.
     * The stage's last-run marker is cleared so it is re-enqueued promptly once automation is re-enabled.
     */
    private function cancelAutomaticQueued(): void {
        try {
            $queued = $this->jobs->findBy( [ 'status' => Jobs::STATUS_QUEUED ], 'id', 'ASC', 200 );
        } catch ( Throwable $e ) {
            return;
        }
        if ( [] === $queued ) {
            return;
        }
        $runs    = $this->stageRuns();
        $changed = false;
        foreach ( $queued as $job ) {
            $payload = is_array( $job['payload'] ?? null ) ? $job['payload'] : [];
            if ( ! empty( $payload['manual'] ) ) {
                continue;
            }
            try {
                $this->jobs->update(
                    (int) $job['id'],
                    [ 'status' => Jobs::STATUS_CANCELLED, 'finished_at' => gmdate( 'Y-m-d H:i:s' ), 'error' => 'Cancelled: automation disabled.' ]
                );
                unset( $runs[ (string) $job['type'] ] );
                $changed = true;
            } catch ( Throwable $e ) {
                $this->logger->error( 'automation', 'Could not cancel queued job: ' . $e->getMessage(), [ 'id' => (int) $job['id'] ] );
            }
        }
        if ( $changed ) {
            update_option( self::OPTION_STAGE_RUNS, $runs, false );
            $this->logger->info( 'automation', 'Automation is disabled; automatically queued jobs were cancelled.' );
        }
    }

    private function hasQueuedManual(): bool {
        try {
            foreach ( $this->jobs->findBy( [ 'status' => Jobs::STATUS_QUEUED ], 'id', 'ASC', 200 ) as $job ) {
                $payload = is_array( $job['payload'] ?? null ) ? $job['payload'] : [];
                if ( ! empty( $payload['manual'] ) ) {
                    return true;
                }
            }
        } catch ( Throwable $e ) {
            unset( $e );
        }
        return false;
    }

    /** @return array<string,string> type => ISO-8601 time of the last enqueue/run */
    private function stageRuns(): array {
        $runs = get_option( self::OPTION_STAGE_RUNS, [] );
        return is_array( $runs ) ? $runs : [];
    }

    private function recordStageRun( string $type ): void {
        $runs          = $this->stageRuns();
        $runs[ $type ] = gmdate( 'c' );
        update_option( self::OPTION_STAGE_RUNS, $runs, false );
    }

    private function maxSeconds(): float {
        $max = (float) $this->settings->get( 'automation.max_execution_seconds', self::FALLBACK_MAX_SECONDS );
        return $max > 0 ? $max : self::FALLBACK_MAX_SECONDS;
    }

    private function container(): Container {
        return $this->runner->container();
    }
}
