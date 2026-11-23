<?php
/**
 * Orchestrates automation stages: on every tick it enqueues the scheduled tasks Automation\TaskRegistry lets through
 * (DESIGN.md §5.1, §5.2; the engine's hard-coded stages() list is gone since WP2), runs stages directly and reports
 * their status.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Automation;

use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Growth\GrowthRun;
use LinkGrowth\Autopilot\Repositories\Jobs;
use LinkGrowth\Autopilot\Support\Limits;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\TimeBudget;
use Throwable;

final class Pipeline {

    public const OPTION_LAST_TICK  = 'lga_last_tick';
    public const OPTION_STAGE_RUNS = 'lga_stage_runs';

    /** Who had the last tick that both a background growth run and due queued jobs wanted: 'growth' | 'queue'. */
    public const OPTION_GROWTH_TURN = 'lga_growth_turn';

    /** Per-stage automation flags exposed in status(). */
    public const FLAGS = [ 'discovery', 'evaluation', 'opportunities', 'packaging', 'submissions', 'outreach_autosend', 'monitoring', 'imports' ];

    /** Stale "running" jobs older than this are re-queued on every tick. */
    private const STALE_SECONDS = 900;

    public function __construct(
        private Jobs $jobs,
        private JobRunner $runner,
        private Settings $settings,
        private Limits $limits,
        private Logger $logger,
        private ?LoadGuard $guard = null,
        private ?TaskRegistry $tasks = null
    ) {}

    /**
     * One scheduler tick: continue an active growth run whose browser tab went away, enqueue due stages (when
     * automation is enabled) and run the queue.
     *
     * While the server is under pressure (LoadGuard: OPcache restarting, a backup running, the plugin just updated,
     * the load above 2 per CPU, less than 10 % of the memory available, or the lga_pause_background filter) the
     * tick does nothing else: no job is claimed, no stage is enqueued, no
     * growth step runs and no limit is spent; it returns skipped_reason "paused" with the reason, which is logged and
     * stored once per pause. The first tick after the pause logs the resume and works as usual.
     *
     * @return array{enqueued:string[],run:array{ran:int,completed:int,failed:int,skipped:bool},skipped_reason:string,growth:?array<string,mixed>,paused:?array<string,mixed>}
     */
    public function tick(): array {
        update_option( self::OPTION_LAST_TICK, gmdate( 'c' ), false );
        $out = [
            'enqueued'       => [],
            'run'            => [ 'ran' => 0, 'completed' => 0, 'failed' => 0, 'skipped' => false ],
            'skipped_reason' => '',
            'growth'         => null,
            'paused'         => null,
        ];

        // Asked afresh on every tick; the growth run and kicks in the same request reuse this answer.
        $paused = null;
        try {
            $guard         = $this->loadGuard();
            $paused        = $guard->pauseReason( true );
            $out['paused'] = $guard->track( $paused, $this->logger );
        } catch ( Throwable $e ) {
            $paused = null; // a guard that cannot answer never stops the tick
        }
        if ( null !== $paused ) {
            $out['skipped_reason'] = 'paused';
            return $out;
        }

        try {
            $recovered = $this->jobs->recoverStale( self::STALE_SECONDS );
            if ( $recovered > 0 ) {
                $this->logger->warning( 'automation', 'Recovered stale running jobs', [ 'count' => $recovered ] );
            }
        } catch ( Throwable $e ) {
            $this->logger->error( 'automation', 'Stale job recovery failed: ' . $e->getMessage() );
        }

        // A growth run is the owner's explicit click, so it continues even while the automation switch is off. It
        // shares the tick's budget with the queue run below, so one tick never runs longer than the ceiling. An
        // advance uses nearly all of that budget, so while other jobs are due the run and the queue take turns:
        // after a tick that advanced the run, the next one gives the queue (sends, follow-ups, monitoring) a full
        // budget, and the run continues on the tick after that.
        $automation = (bool) $this->settings->get( 'automation.enabled', false );
        $budget     = TimeBudget::seconds( $this->maxSeconds() );
        if ( $this->queueTurn( $automation ) ) {
            $out['growth']      = null;
            $out['growth_turn'] = 'queue';
        } else {
            $out['growth'] = $this->continueGrowthRun( $budget );
            if ( null !== $out['growth'] && ! empty( $out['growth']['advanced'] ) ) {
                $this->setTurn( 'growth' );
            }
        }
        if ( null === $out['growth'] ) {
            $budget = null; // nothing ran: the queue gets a fresh, full budget (created when it starts)
        }

        if ( $automation ) {
            $out['enqueued'] = $this->enqueueDueStages();
        } else {
            $out['skipped_reason'] = 'automation_disabled';
            $this->cancelAutomaticQueued();
            if ( ! $this->hasQueuedManual() ) {
                return $out;
            }
        }

        try {
            $out['run'] = $this->runner->run( $budget ?? TimeBudget::seconds( $this->maxSeconds() ) );
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
        } catch ( Throwable $e ) {
            $this->logger->error( 'automation', 'Could not enqueue stage: ' . $e->getMessage(), [ 'type' => $type ] );
            return null;
        }
        return $this->insertStage( $type, $payload, $priority, $manual );
    }

    /**
     * Adds the job row (the duplicate check is the caller's: enqueueStage(), or TaskRegistry's lock gate on a tick).
     *
     * @param array<string,mixed> $payload
     */
    private function insertStage( string $type, array $payload, int $priority, bool $manual ): ?int {
        try {
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
        // The scheduled tasks in tick order, then the registered job types no task schedules (custom ones), which are
        // still listed for the dashboard.
        $stages   = [];
        $types    = $this->runner->types();
        $registry = $this->tasks();
        foreach ( $registry->tasks( $types ) as $task ) {
            $stages[ $task->jobType ] = [
                'priority' => $task->priority,
                'cadence'  => $registry->schedule( $task )['cadence'],
                'flag'     => $task->module,
            ];
        }
        foreach ( $types as $type => $class ) {
            if ( ! isset( $stages[ $type ] ) ) {
                $stages[ $type ] = [ 'priority' => max( 1, min( 9, (int) $class::priority() ) ), 'cadence' => 'manual', 'flag' => '' ];
            }
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
        $paused   = null;
        try {
            $paused = $this->loadGuard()->status();
        } catch ( Throwable $e ) {
            unset( $e );
        }

        return [
            'automation_enabled'    => (bool) $this->settings->get( 'automation.enabled', false ),
            'flags'                 => $flags,
            // What the stages actually use: Autopilot raises submissions / outreach autosend / auto drafts to on.
            'effective_flags'       => $this->effectiveFlags(),
            'autopilot'             => $this->autopilot()->isOn(),
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
            // Background work paused because the server is under pressure: {code, message, text, until, since,
            // source}, e.g. text "Paused: a backup is running (resumes automatically)."; null while it runs.
            'paused'                => $paused,
        ];
    }

    /** The server-pressure guard (the container's, so a request asks it once). */
    public function loadGuard(): LoadGuard {
        if ( null === $this->guard ) {
            try {
                $svc = $this->container()->get( LoadGuard::class );
                $this->guard = $svc instanceof LoadGuard ? $svc : new LoadGuard();
            } catch ( Throwable $e ) {
                $this->guard = new LoadGuard();
            }
        }
        return $this->guard;
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
     * True when this tick belongs to the queue: the previous tick advanced a background growth run and queued jobs
     * that this tick would run are due (any due job with automation on, manual ones with automation off).
     */
    private function queueTurn( bool $automation ): bool {
        if ( 'growth' !== get_option( self::OPTION_GROWTH_TURN, '' ) ) {
            return false;
        }
        $this->setTurn( 'queue' );
        return $this->hasDueQueued( ! $automation );
    }

    private function setTurn( string $turn ): void {
        if ( get_option( self::OPTION_GROWTH_TURN, '' ) !== $turn ) {
            update_option( self::OPTION_GROWTH_TURN, $turn, false );
        }
    }

    /** Whether a queued job is due now (only manual ones when $manualOnly). */
    private function hasDueQueued( bool $manualOnly ): bool {
        try {
            $now = gmdate( 'Y-m-d H:i:s' );
            foreach ( $this->jobs->findBy( [ 'status' => Jobs::STATUS_QUEUED ], 'scheduled_at', 'ASC', 200 ) as $job ) {
                if ( (string) ( $job['scheduled_at'] ?? '' ) > $now ) {
                    continue;
                }
                $payload = is_array( $job['payload'] ?? null ) ? $job['payload'] : [];
                if ( ! $manualOnly || ! empty( $payload['manual'] ) ) {
                    return true;
                }
            }
        } catch ( Throwable $e ) {
            unset( $e );
        }
        return false;
    }

    /**
     * Advances an active growth run (Growth\GrowthRun) with this tick's budget when nobody advanced it for more
     * than GrowthRun::BACKGROUND_AFTER_SECONDS (the dashboard tab was closed). Never lets a failure break the tick.
     *
     * @return array<string,mixed>|null null when there was nothing to continue.
     */
    private function continueGrowthRun( TimeBudget $budget ): ?array {
        try {
            if ( ! class_exists( GrowthRun::class ) ) {
                return null;
            }
            $run = $this->container()->get( GrowthRun::class );
            if ( ! $run instanceof GrowthRun || ! $run->isActive() || $run->lastAdvanceAge() <= GrowthRun::BACKGROUND_AFTER_SECONDS ) {
                return null;
            }
            $state = $run->advance( $budget );
            return [
                'advanced' => empty( $state['waiting'] ),
                'id'       => (string) ( $state['id'] ?? '' ),
                'status'   => (string) ( $state['status'] ?? '' ),
                'cursor'   => (int) ( $state['cursor'] ?? 0 ),
            ];
        } catch ( Throwable $e ) {
            try {
                $this->logger->error( 'growth', 'The growth run could not be continued in the background: ' . $e->getMessage() );
            } catch ( Throwable $ignored ) {
                unset( $ignored );
            }
            return null;
        }
    }

    /**
     * Enqueues every scheduled task TaskRegistry::gate() lets through, in tick order (DESIGN.md §5.2; the engine's
     * stage rule: the automation flag, the interval since the stage's last run, the due-check, no duplicate job).
     *
     * @return string[] enqueued job types
     */
    private function enqueueDueStages(): array {
        $enqueued = [];
        $runs     = $this->stageRuns();
        $now      = time();
        $registry = $this->tasks();
        $context  = Context::scheduler( $now, $runs );
        foreach ( $registry->tasks() as $task ) {
            try {
                if ( '' !== $registry->gate( $task, $context ) ) {
                    continue;
                }
                $id = $this->insertStage( $task->jobType, [], $task->priority, false );
                if ( null !== $id ) {
                    $enqueued[]              = $task->jobType;
                    $runs[ $task->jobType ] = gmdate( 'c', $now );
                }
            } catch ( Throwable $e ) {
                $this->logger->error( 'automation', 'Stage scheduling failed: ' . $e->getMessage(), [ 'type' => $task->jobType ] );
            }
        }
        if ( [] !== $enqueued ) {
            update_option( self::OPTION_STAGE_RUNS, $runs, false );
        }
        return $enqueued;
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

    /** Tick budget: the configured ceiling, never below what the stages need for one item (JobRunner::MIN_TICK_SECONDS). */
    private function maxSeconds(): float {
        return JobRunner::effectiveMaxSeconds( $this->settings );
    }

    private function container(): Container {
        return $this->runner->container();
    }

    /** The task registry (the container's, so a request collects the modules' tasks with one registry). */
    private function tasks(): TaskRegistry {
        if ( null === $this->tasks ) {
            $c           = $this->container();
            $svc         = $c->has( TaskRegistry::class ) ? $c->get( TaskRegistry::class ) : null;
            $this->tasks = $svc instanceof TaskRegistry ? $svc : new TaskRegistry( $c, \LinkGrowth\Autopilot\Plugin::instance()->modules() );
        }
        return $this->tasks;
    }

    /** The approval policy (Autopilot), built from the same settings when the container has none. */
    private function autopilot(): Autopilot {
        try {
            $svc = $this->container()->get( Autopilot::class );
            if ( $svc instanceof Autopilot ) {
                return $svc;
            }
        } catch ( Throwable $e ) {
            unset( $e );
        }
        return new Autopilot( $this->settings );
    }

    /** @return array<string,bool> */
    private function effectiveFlags(): array {
        $out = [];
        foreach ( self::FLAGS as $flag ) {
            $out[ $flag ] = $this->autopilot()->flag( $flag );
        }
        return $out;
    }
}
