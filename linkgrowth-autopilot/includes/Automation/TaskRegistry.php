<?php
/**
 * The task registry (DESIGN.md §5.1, §5.2; WP2): the scheduled tasks every module declares (Module::tasks(), in
 * Core\Modules order), then the `lga_scheduled_tasks` filter, checked and put in tick order; and gate(), which decides
 * whether a task may be enqueued now, with the reason code of the first gate that holds it back. It replaces the
 * engine's hard-coded Pipeline::stages(): Automation\Pipeline considers the tasks in order on every tick and enqueues
 * each one gate() lets through.
 *
 * Gates, in order (DESIGN.md §5.2), with the reason code of each; "stub" = pass-through until WP4b, "scheduler" = not
 * applied when a person starts the task (Context::manual()):
 *   1. master     automation.enabled                                  lga_paused_master
 *   2. module     the task's automation flag (Autopilot::flag())       lga_module_off
 *   3. profile    requiresProfile: setup.profile_ready                 lga_profile_incomplete   stub
 *   4. setup      requiresSetup: setup.completed                       lga_setup_incomplete     stub
 *   5. consent    the consent is recorded                              lga_consent_missing      stub
 *   6. autopilot  Autopilot::allows() for autopilot tasks              lga_autopilot_off        stub, scheduler
 *   7. load       LoadGuard has not paused background work             lga_paused_load
 *   8. limit      the task's limit pool has room                       lga_limit_reached
 *   9. schedule   not off; the interval since the last run passed;     lga_task_off, lga_not_due, lga_no_work
 *                 the due-check finds work                                                      scheduler
 *  10. lock       no job of the task's type is queued or running       lga_already_queued       scheduler
 *
 * Steps 9 and 10 are the engine's stage rule as it was: the interval since `lga_stage_runs`, the due-check, and the
 * duplicate check of Pipeline::enqueueStage(). An exception from a due-check is not caught here (the tick logs it and
 * goes on with the next task, as before).
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Automation;

use LinkGrowth\Autopilot\Automation\Jobs\JobInterface;
use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Core\Module;
use LinkGrowth\Autopilot\Repositories\Jobs;
use LinkGrowth\Autopilot\Support\Limits;
use LinkGrowth\Autopilot\Support\Settings;
use Throwable;

final class TaskRegistry {

    /** Filter: the task list (TaskDefinition objects) after the modules', before it is checked. */
    public const FILTER = 'lga_scheduled_tasks';

    public const PAUSED_MASTER      = 'lga_paused_master';
    public const MODULE_OFF         = 'lga_module_off';
    public const PROFILE_INCOMPLETE = 'lga_profile_incomplete';
    public const SETUP_INCOMPLETE   = 'lga_setup_incomplete';
    public const CONSENT_MISSING    = 'lga_consent_missing';
    public const AUTOPILOT_OFF      = 'lga_autopilot_off';
    public const PAUSED_LOAD        = 'lga_paused_load';
    public const LIMIT_REACHED      = 'lga_limit_reached';
    public const TASK_OFF           = 'lga_task_off';
    public const NOT_DUE            = 'lga_not_due';
    public const NO_WORK            = 'lga_no_work';
    public const ALREADY_QUEUED     = 'lga_already_queued';

    /** The gates in the order gate() applies them => the reason codes each can give. */
    public const GATES = [
        'master'    => [ self::PAUSED_MASTER ],
        'module'    => [ self::MODULE_OFF ],
        'profile'   => [ self::PROFILE_INCOMPLETE ],
        'setup'     => [ self::SETUP_INCOMPLETE ],
        'consent'   => [ self::CONSENT_MISSING ],
        'autopilot' => [ self::AUTOPILOT_OFF ],
        'load'      => [ self::PAUSED_LOAD ],
        'limit'     => [ self::LIMIT_REACHED ],
        'schedule'  => [ self::TASK_OFF, self::NOT_DUE, self::NO_WORK ],
        'lock'      => [ self::ALREADY_QUEUED ],
    ];

    /** A task id, module flag, consent or pool name. */
    private const KEY = '/^[a-z0-9_]+$/';

    /** @var array<string,Module> task id => the module that declared it (filter-added tasks have none) */
    private array $owners = [];

    /** @var list<array{id:string,reason:string}> what the last tasks() left out, and why */
    private array $rejected = [];

    /** @param list<Module> $modules the loaded modules (Plugin::modules()) */
    public function __construct( private Container $c, private array $modules ) {}

    /**
     * The tasks, checked, in tick order (TaskDefinition::$order, then module order, then filter order). Left out (see
     * rejected()): an entry that is not a TaskDefinition; an id that is not a lower-case key or that an earlier task
     * took; a job type that is not registered (JobRunner::types()) or that an earlier task already schedules; a
     * priority outside 1-9; an unknown frequency; `continuous` without a due-check; `off` when the task may not be
     * off; an outbound task that does not require the profile; an autopilot task without a consent; a module flag,
     * consent or pool that is not a key; a due-check or schedule method that does not exist.
     *
     * @param array<string,class-string<JobInterface>>|null $types the registered job types (null = ask JobRunner)
     * @return list<TaskDefinition>
     */
    public function tasks( ?array $types = null ): array {
        $types    = $types ?? $this->jobTypes();
        $declared = [];
        $owner    = [];
        foreach ( $this->modules as $module ) {
            try {
                $list = (array) $module->tasks();
            } catch ( Throwable $e ) {
                unset( $e );
                continue; // a module whose tasks cannot be read loses its own tasks only
            }
            foreach ( $list as $task ) {
                $declared[] = $task;
                if ( $task instanceof TaskDefinition ) {
                    $owner[ spl_object_id( $task ) ] = $module;
                }
            }
        }

        /**
         * Filters the scheduled tasks (DESIGN.md §5.1). Add an extension's TaskDefinition to the list; its job type
         * must be registered (`lga_job_types` or a module's jobs()), and a due-check of a task added here is written
         * as `Class::method` (the class is built through the container, or with the container as its argument).
         *
         * @param list<TaskDefinition> $tasks
         */
        $filtered = apply_filters( self::FILTER, $declared );

        $this->owners   = [];
        $this->rejected = [];
        $ids            = [];
        $jobTypes       = [];
        $out            = [];
        foreach ( is_array( $filtered ) ? array_values( $filtered ) : [] as $i => $task ) {
            if ( ! $task instanceof TaskDefinition ) {
                $this->rejected[] = [ 'id' => '#' . $i, 'reason' => 'not_a_task' ];
                continue;
            }
            $module = $owner[ spl_object_id( $task ) ] ?? null;
            $reason = $this->problem( $task, $module, $types, $ids, $jobTypes );
            if ( '' !== $reason ) {
                $this->rejected[] = [ 'id' => $task->id, 'reason' => $reason ];
                continue;
            }
            $ids[ $task->id ]           = true;
            $jobTypes[ $task->jobType ] = true;
            if ( null !== $module ) {
                $this->owners[ $task->id ] = $module;
            }
            $out[] = $task;
        }
        usort( $out, static fn( TaskDefinition $a, TaskDefinition $b ): int => $a->order <=> $b->order ); // stable (PHP 8)
        return $out;
    }

    /** @return list<array{id:string,reason:string}> the entries the last tasks() call left out */
    public function rejected(): array {
        return $this->rejected;
    }

    public function task( string $id ): ?TaskDefinition {
        foreach ( $this->tasks() as $task ) {
            if ( $task->id === $id ) {
                return $task;
            }
        }
        return null;
    }

    /**
     * The schedule a task has now: `every` (seconds between enqueues; 0 = every tick, -1 = never by the scheduler)
     * and the text the Automation page shows.
     *
     * @return array{every:int,cadence:string}
     */
    public function schedule( TaskDefinition $task ): array {
        if ( null !== $task->schedule ) {
            $method = $this->method( $task, $task->schedule );
            $own    = null !== $method ? $method() : null;
            if ( is_array( $own ) && isset( $own['every'] ) ) {
                return [ 'every' => (int) $own['every'], 'cadence' => (string) ( $own['cadence'] ?? Frequency::label( $task->defaultFrequency ) ) ];
            }
        }
        return [
            'every'   => Frequency::seconds( $task->defaultFrequency ),
            'cadence' => '' !== $task->cadence ? $task->cadence : Frequency::label( $task->defaultFrequency ),
        ];
    }

    /**
     * Whether $task may be enqueued now: '' when every gate lets it through, otherwise the reason code of the first
     * gate that holds it back (see the class comment for the order).
     */
    public function gate( TaskDefinition $task, Context $context ): string {
        // 1. The master switch ("Pause everything").
        if ( ! (bool) $this->settings()->get( 'automation.enabled', false ) ) {
            return self::PAUSED_MASTER;
        }
        // 2. The task's automation flag, as Autopilot reads it (Autopilot raises the submissions flag while it is on).
        if ( '' !== $task->module && ! $this->autopilot()->flag( $task->module ) ) {
            return self::MODULE_OFF;
        }
        // 3.-6. TODO(WP4b): setup.profile_ready for requiresProfile, setup.completed for requiresSetup, the consent
        // record (Automation\Consents) for consent, and Autopilot::allows() for autopilot tasks started by the
        // scheduler. Neither the setup state nor the consent record exists before WP4a/WP4b, so these let every
        // task through.
        if ( ! $this->profileReady( $task ) ) {
            return self::PROFILE_INCOMPLETE;
        }
        if ( ! $this->setupComplete( $task ) ) {
            return self::SETUP_INCOMPLETE;
        }
        if ( ! $this->consentRecorded( $task ) ) {
            return self::CONSENT_MISSING;
        }
        if ( ! $context->isManual() && ! $this->autopilotAllows( $task ) ) {
            return self::AUTOPILOT_OFF;
        }
        // 7. Server pressure: this request's LoadGuard answer (the tick asks it first, and stops while paused).
        if ( $this->paused() ) {
            return self::PAUSED_LOAD;
        }
        // 8. The limit pool.
        if ( '' !== $task->budgetPool && ! $this->limits()->allow( $task->budgetPool ) ) {
            return self::LIMIT_REACHED;
        }
        if ( $context->isManual() ) {
            return '';
        }
        // 9. The schedule: off, the interval since the last run, the due-check.
        $schedule = $this->schedule( $task );
        if ( $schedule['every'] < 0 ) {
            return self::TASK_OFF;
        }
        $last = $context->lastRun( $task->jobType );
        if ( $schedule['every'] > 0 && $last > 0 && ( $context->now - $last ) < $schedule['every'] ) {
            return self::NOT_DUE;
        }
        if ( null !== $task->dueCheck ) {
            $check = $this->method( $task, $task->dueCheck );
            if ( null === $check || ! (bool) $check() ) {
                return self::NO_WORK;
            }
        }
        // 10. The lock: a job of this type is already queued or running (the engine's duplicate check).
        if ( $this->jobs()->hasPending( $task->jobType ) ) {
            return self::ALREADY_QUEUED;
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Gates owned by WP4b (pass-through until then)
    // ------------------------------------------------------------------

    /** TODO(WP4b): `! $task->requiresProfile || setup.profile_ready`. */
    private function profileReady( TaskDefinition $task ): bool {
        unset( $task );
        return true;
    }

    /** TODO(WP4b): `! $task->requiresSetup || setup.completed`. */
    private function setupComplete( TaskDefinition $task ): bool {
        unset( $task );
        return true;
    }

    /** TODO(WP4b): `'' === $task->consent || Consents::has( $task->consent )`. */
    private function consentRecorded( TaskDefinition $task ): bool {
        unset( $task );
        return true;
    }

    /** TODO(WP4b): `! $task->autopilot || Autopilot::allows( $task->consent )`. */
    private function autopilotAllows( TaskDefinition $task ): bool {
        unset( $task );
        return true;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Why a task cannot be registered ('' = it can).
     *
     * @param array<string,class-string> $types
     * @param array<string,true>         $ids      ids taken so far
     * @param array<string,true>         $jobTypes job types scheduled so far
     */
    private function problem( TaskDefinition $task, ?Module $module, array $types, array $ids, array $jobTypes ): string {
        return match ( true ) {
            1 !== preg_match( self::KEY, $task->id )                                     => 'bad_id',
            isset( $ids[ $task->id ] )                                                  => 'duplicate_id',
            ! isset( $types[ $task->jobType ] )                                         => 'unknown_job_type',
            isset( $jobTypes[ $task->jobType ] )                                        => 'duplicate_job_type',
            $task->priority < 1 || $task->priority > 9                                  => 'bad_priority',
            ! Frequency::isValid( $task->defaultFrequency )                             => 'bad_frequency',
            Frequency::CONTINUOUS === $task->defaultFrequency && null === $task->dueCheck => 'continuous_without_due_check',
            Frequency::OFF === $task->defaultFrequency && ! $task->offAllowed           => 'off_not_allowed',
            $task->outbound && ! $task->requiresProfile                                 => 'outbound_without_profile',
            $task->autopilot && '' === $task->consent                                   => 'autopilot_without_consent',
            '' !== $task->module && 1 !== preg_match( self::KEY, $task->module )        => 'bad_module',
            '' !== $task->consent && 1 !== preg_match( self::KEY, $task->consent )      => 'bad_consent',
            '' !== $task->budgetPool && 1 !== preg_match( self::KEY, $task->budgetPool ) => 'bad_budget_pool',
            null !== $task->dueCheck && null === self::target( $module, $task->dueCheck ) => 'due_check_missing',
            null !== $task->schedule && null === self::target( $module, $task->schedule ) => 'schedule_missing',
            default                                                                     => '',
        };
    }

    /**
     * The class and method a check name points at: `Class::method`, or a method of the DueChecks class in the
     * namespace of the module that declared the task. Null when it does not exist (or is not public).
     *
     * @return array{0:class-string,1:string}|null
     */
    private static function target( ?Module $module, string $name ): ?array {
        if ( str_contains( $name, '::' ) ) {
            [ $class, $method ] = explode( '::', $name, 2 );
        } elseif ( null !== $module ) {
            $moduleClass = get_class( $module );
            $class       = substr( $moduleClass, 0, (int) strrpos( $moduleClass, '\\' ) ) . '\\DueChecks';
            $method      = $name;
        } else {
            return null;
        }
        if ( '' === $method || ! class_exists( $class ) || ! method_exists( $class, $method ) || ! ( new \ReflectionMethod( $class, $method ) )->isPublic() ) {
            return null;
        }
        return [ $class, $method ];
    }

    /** The check $name of $task as a callable, or null when it cannot be resolved. */
    private function method( TaskDefinition $task, string $name ): ?\Closure {
        $target = self::target( $this->owners[ $task->id ] ?? null, $name );
        if ( null === $target ) {
            return null;
        }
        [ $class, $method ] = $target;
        $object = $this->c->has( $class ) ? $this->c->get( $class ) : new $class( $this->c );
        return \Closure::fromCallable( [ $object, $method ] );
    }

    /** @return array<string,class-string<JobInterface>> */
    private function jobTypes(): array {
        return $this->c->get( JobRunner::class )->types();
    }

    private function settings(): Settings {
        return $this->c->get( Settings::class );
    }

    private function limits(): Limits {
        return $this->c->get( Limits::class );
    }

    private function jobs(): Jobs {
        return $this->c->get( Jobs::class );
    }

    /** The approval policy (Autopilot), built from the same settings when the container has none (as Pipeline did). */
    private function autopilot(): Autopilot {
        try {
            $svc = $this->c->get( Autopilot::class );
            if ( $svc instanceof Autopilot ) {
                return $svc;
            }
        } catch ( Throwable $e ) {
            unset( $e );
        }
        return new Autopilot( $this->settings() );
    }

    /** Whether background work is paused now (LoadGuard, this request's answer); a guard that cannot answer never holds a task. */
    private function paused(): bool {
        try {
            $guard = $this->c->has( LoadGuard::class ) ? $this->c->get( LoadGuard::class ) : new LoadGuard();
            return $guard instanceof LoadGuard && null !== $guard->pauseReason();
        } catch ( Throwable $e ) {
            unset( $e );
            return false;
        }
    }
}
