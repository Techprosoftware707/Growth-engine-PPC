<?php
/**
 * The task registry (DESIGN.md §5.1, §5.2, §13.2 WP2): every cadence of the engine's Pipeline::stages() (e160656) is a
 * TaskDefinition with every field of §5.1, declared by the module that owns its job; ids and job types are unique and
 * the §5.1 invariants hold; a task added through `lga_scheduled_tasks` is scheduled; invalid entries are left out with
 * their reason; and TaskRegistry::gate() applies the gates in the order of §5.2 with their reason codes (one test per
 * gate, each also showing which gate comes first). SchedulerGoldenTest shows the enqueue sequence stayed the same.
 *
 * The task tables written here are those of the 13 WP2 modules (Tests\Support\Wp2Modules), checked to be among the
 * registry's tasks; a later package's module adds its tasks without editing this file (DESIGN.md §13.1).
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Automation;

use LinkGrowth\Autopilot\Automation\Context;
use LinkGrowth\Autopilot\Automation\Frequency;
use LinkGrowth\Autopilot\Automation\JobRunner;
use LinkGrowth\Autopilot\Automation\Jobs\AbstractJob;
use LinkGrowth\Autopilot\Automation\LoadGuard;
use LinkGrowth\Autopilot\Automation\Pipeline;
use LinkGrowth\Autopilot\Automation\TaskDefinition;
use LinkGrowth\Autopilot\Automation\TaskRegistry;
use LinkGrowth\Autopilot\Container as PluginContainer;
use LinkGrowth\Autopilot\Repositories\Jobs;
use LinkGrowth\Autopilot\Support\Limits;
use LinkGrowth\Autopilot\Support\Locks;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\TimeBudget;
use LinkGrowth\Autopilot\Tests\Support\Container;
use LinkGrowth\Autopilot\Tests\Support\FrozenClock;
use LinkGrowth\Autopilot\Tests\Support\TestCase;
use LinkGrowth\Autopilot\Tests\Support\Wp2Modules;

final class TaskRegistryTest extends TestCase {

    /**
     * The engine's stages (Pipeline::stages() at e160656), in its order: job type => [interval in seconds (0 = every
     * tick with a due-check, -1 = only when a person starts it), automation flag, has a due-check, the cadence text
     * of the Automation page]. With the default settings.
     */
    private const ENGINE_STAGES = [
        'discover'           => [ 86400, 'discovery', false, 'daily' ],
        'mention_scan'       => [ 604800, 'discovery', false, 'weekly' ],
        'evaluate'           => [ 0, 'evaluation', true, 'every tick (when prospects are due)' ],
        'find_opportunities' => [ 0, 'opportunities', true, 'every tick (when qualified prospects lack opportunities)' ],
        'storefront_audit'   => [ 86400, '', false, 'daily (and when products, product categories/tags or menus change)' ],
        'import_verified'    => [ 0, '', true, 'when the bundled verified opportunity data changes' ],
        'package'            => [ 3600, 'packaging', false, 'hourly' ],
        'submit'             => [ 0, 'submissions', true, 'every tick (when approved submissions are queued)' ],
        'verify_listings'    => [ 21600, '', false, 'every 6 hours' ],
        'outreach_send'      => [ 0, '', true, 'every tick inside the send window (when approved messages are ready)' ],
        'auto_outreach'      => [ 86400, '', false, 'daily' ],
        'follow_ups'         => [ 86400, '', false, 'daily' ],
        'monitor_backlinks'  => [ 0, 'monitoring', true, 'every tick (when backlinks are due)' ],
        'import_backlinks'   => [ 604800, 'imports', false, 'every 7 days' ],
        'cleanup'            => [ 86400, '', false, 'daily' ],
        'metrics_refresh'    => [ 604800, '', false, 'weekly' ],
        'site_audit'         => [ 86400, '', false, 'daily' ],
        'indexnow'           => [ 3600, '', true, 'hourly (when URLs are waiting)' ],
        'internal_links'     => [ 604800, '', false, 'weekly (suggestions only)' ],
        'broken_link_scan'   => [ -1, '', false, 'manual' ],
    ];

    /**
     * Task id => [job type, owning module, requiresProfile, requiresSetup, consent, autopilot, outbound, offAllowed,
     * budgetPool]: the gate fields of the §5.1 table (P, S, C:x, A, O; cleanup cannot be off) and the limit pools the
     * engine checked before it enqueued.
     */
    private const TASKS = [
        'discover'           => [ 'discover', 'discovery', true, false, '', false, true, true, '' ],
        'mention_scan'       => [ 'mention_scan', 'discovery', true, false, '', false, true, true, '' ],
        'evaluate'           => [ 'evaluate', 'scoring', true, false, '', false, true, true, 'evaluations' ],
        'find_opportunities' => [ 'find_opportunities', 'discovery', true, false, '', false, true, true, '' ],
        'positioning_audit'  => [ 'storefront_audit', 'compliance', true, false, '', false, false, true, '' ],
        'catalog_import'     => [ 'import_verified', 'discovery', true, false, '', false, false, true, '' ],
        'package'            => [ 'package', 'submission', true, false, '', false, false, true, '' ],
        'submit'             => [ 'submit', 'submission', true, true, 'submissions', true, true, true, 'submissions' ],
        'verify_listings'    => [ 'verify_listings', 'submission', true, false, '', false, true, true, '' ],
        'outreach_send'      => [ 'outreach_send', 'outreach', true, true, '', false, true, true, 'outreach_emails' ],
        'outreach_drafts'    => [ 'auto_outreach', 'outreach', true, false, '', false, false, true, '' ],
        'follow_ups'         => [ 'follow_ups', 'outreach', true, true, '', false, true, true, '' ],
        'monitor_backlinks'  => [ 'monitor_backlinks', 'monitoring', true, false, '', false, true, true, '' ],
        'import_backlinks'   => [ 'import_backlinks', 'monitoring', true, false, '', false, true, true, '' ],
        'cleanup'            => [ 'cleanup', 'automation', false, false, '', false, false, false, '' ],
        'metrics_refresh'    => [ 'metrics_refresh', 'integrations', true, false, '', false, true, true, '' ],
        'site_audit'         => [ 'site_audit', 'seo', false, false, '', false, false, true, '' ],
        'indexnow'           => [ 'indexnow', 'seo', true, false, '', false, true, true, '' ],
        'internal_links'     => [ 'internal_links', 'seo', true, false, '', false, false, true, '' ],
        'broken_link_scan'   => [ 'broken_link_scan', 'discovery', true, false, '', false, true, true, '' ],
    ];

    /** The TaskDefinition fields of DESIGN.md §5.1, in the constructor's order, with the defaults it gives the optional ones. */
    private const DESIGN_FIELDS = [
        'id'               => null,
        'jobType'          => null,
        'label'            => null,
        'defaultFrequency' => null,
        'module'           => null,
        'dueCheck'         => null,
        'priority'         => null,
        'budgetPool'       => '',
        'runCap'           => '',
        'requiresProfile'  => true,
        'requiresSetup'    => false,
        'consent'          => '',
        'autopilot'        => false,
        'outbound'         => false,
        'offAllowed'       => true,
    ];

    /** 2026-01-05 16:00:00 UTC. */
    private const T0 = 1767628800;

    protected function setUp(): void {
        parent::setUp();
        TaskRegistryTestChecks::$due = true;
        add_filter( 'lga_job_types', static fn( array $types ): array => $types + [ 'registry_probe' => TaskRegistryTestJob::class ] );
    }

    protected function tearDown(): void {
        FrozenClock::set( null );
        Container::reset();
        parent::tearDown();
    }

    private static function registry(): TaskRegistry {
        return Container::get( TaskRegistry::class );
    }

    /** @return array<string,TaskDefinition> id => task */
    private static function byId(): array {
        $out = [];
        foreach ( self::registry()->tasks() as $task ) {
            $out[ $task->id ] = $task;
        }
        return $out;
    }

    /** @return array<string,true> the ids of the tasks the WP2 modules declare */
    private static function wp2TaskIds(): array {
        $out = [];
        foreach ( Wp2Modules::declared( 'tasks' ) as $tasks ) {
            foreach ( $tasks as $task ) {
                $out[ $task->id ] = true;
            }
        }
        return $out;
    }

    /** A task of the probe job type; $fields override the defaults (named constructor arguments). */
    private static function probe( array $fields = [] ): TaskDefinition {
        return new TaskDefinition( ...array_merge( [ 'id' => 'registry_probe', 'jobType' => 'registry_probe', 'label' => 'Probe', 'defaultFrequency' => Frequency::HOURLY, 'module' => '', 'dueCheck' => null, 'priority' => 5, 'requiresProfile' => false ], $fields ) );
    }

    private static function gate( TaskDefinition $task, ?Context $context = null ): string {
        return self::registry()->gate( $task, $context ?? Context::scheduler( self::T0 ) );
    }

    private static function set( string $path, mixed $value ): void {
        Container::get( Settings::class )->set( $path, $value );
    }

    private static function pauseBackground(): void {
        add_filter( 'lga_pause_background', static fn(): bool => true );
        Container::get( LoadGuard::class )->forget();
    }

    private static function exhaust( string $pool ): void {
        self::set( 'automation.daily_limits.' . $pool, 1 );
        Container::get( Limits::class )->spend( $pool );
    }

    /** @return array<string,string> job type => ISO time of its last run, $secondsAgo before T0 */
    private static function ranAgo( string $type, int $secondsAgo ): array {
        return [ $type => gmdate( 'c', self::T0 - $secondsAgo ) ];
    }

    // ------------------------------------------------------------------
    // The engine's cadences as task definitions
    // ------------------------------------------------------------------

    public function testEveryEngineCadenceIsATaskDefinitionWithEveryFieldOfTheDesign(): void {
        $params = ( new \ReflectionMethod( TaskDefinition::class, '__construct' ) )->getParameters();
        $names  = array_map( static fn( \ReflectionParameter $p ): string => $p->getName(), $params );
        $this->assertSame( array_keys( self::DESIGN_FIELDS ), array_slice( $names, 0, 15 ), 'the §5.1 fields, in order' );
        $this->assertSame( [ 'cadence', 'order', 'schedule' ], array_slice( $names, 15 ), 'then the three WP2 additions' );
        foreach ( array_slice( $params, 7, 8 ) as $param ) {
            $this->assertSame( self::DESIGN_FIELDS[ $param->getName() ], $param->getDefaultValue(), $param->getName() . ' default of §5.1' );
        }

        $registry = self::registry();
        $tasks    = $registry->tasks();
        $this->assertSame( [], $registry->rejected(), 'no built-in task is left out' );
        $wp2    = self::wp2TaskIds();
        $byType = [];
        foreach ( $tasks as $task ) {
            if ( isset( $wp2[ $task->id ] ) ) {
                $byType[ $task->jobType ] = $task;
            }
        }
        $this->assertCount( count( $wp2 ), $byType, 'every task of the WP2 modules is collected' );
        $this->assertSame( array_keys( self::ENGINE_STAGES ), array_keys( $byType ), 'one task per engine stage, in the engine\'s order' );

        $types = Container::get( JobRunner::class )->types();
        foreach ( self::ENGINE_STAGES as $type => [ $every, $flag, $due, $cadence ] ) {
            $task = $byType[ $type ];
            $this->assertSame( [ 'every' => $every, 'cadence' => $cadence ], $registry->schedule( $task ), $type . ': the engine\'s interval and text' );
            $this->assertSame( $flag, $task->module, $type . ': the engine\'s automation flag' );
            $this->assertSame( $due, null !== $task->dueCheck, $type . ': a due-check where the engine had one' );
            $this->assertSame( max( 1, min( 9, $types[ $type ]::priority() ) ), $task->priority, $type . ': the job class\'s priority' );
            $this->assertSame( 0 === $every, Frequency::CONTINUOUS === $task->defaultFrequency, $type . ': continuous exactly where the engine checked on every tick' );
            $this->assertNotSame( '', trim( $task->label ), $type . ' has a label' );
            $this->assertSame( '', $task->runCap, $type . ': run bounds come with WP10' );
        }

        $this->assertSame( array_keys( self::TASKS ), array_map( static fn( TaskDefinition $t ): string => $t->id, array_values( $byType ) ), 'the ids of §5.1' );
        $owners = [];
        $jobsOf = [];
        foreach ( Container::plugin()->modules() as $module ) {
            $jobsOf[ $module->id() ] = $module->jobs();
            foreach ( $module->tasks() as $task ) {
                $owners[ $task->id ] = $module->id();
            }
        }
        foreach ( self::TASKS as $id => [ $type, $module, $profile, $setup, $consent, $autopilot, $outbound, $offAllowed, $pool ] ) {
            $task = self::byId()[ $id ];
            $this->assertSame(
                [ $type, $module, $profile, $setup, $consent, $autopilot, $outbound, $offAllowed, $pool ],
                [ $task->jobType, $owners[ $id ] ?? null, $task->requiresProfile, $task->requiresSetup, $task->consent, $task->autopilot, $task->outbound, $task->offAllowed, $task->budgetPool ],
                $id
            );
            $this->assertArrayHasKey( $type, $jobsOf[ $module ], $id . ' is declared by the module that declares its job' );
        }
    }

    public function testIdsAndJobTypesAreUniqueAndTheInvariantsOfTheDesignHold(): void {
        $tasks = self::registry()->tasks();
        $ids   = array_map( static fn( TaskDefinition $t ): string => $t->id, $tasks );
        $types = array_map( static fn( TaskDefinition $t ): string => $t->jobType, $tasks );
        $this->assertSame( array_unique( $ids ), $ids, 'ids are unique' );
        $this->assertSame( array_unique( $types ), $types, 'one task per job type' );
        foreach ( $tasks as $task ) {
            $this->assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $task->id );
            $this->assertGreaterThanOrEqual( 1, $task->priority, $task->id );
            $this->assertLessThanOrEqual( 9, $task->priority, $task->id );
            $this->assertContains( $task->defaultFrequency, Frequency::ALL, $task->id );
            if ( $task->outbound ) {
                $this->assertTrue( $task->requiresProfile, $task->id . ': outbound implies requiresProfile (§5.1)' );
            }
            if ( $task->autopilot ) {
                $this->assertNotSame( '', $task->consent, $task->id . ': an autopilot task names a consent (§5.1)' );
            }
            if ( Frequency::CONTINUOUS === $task->defaultFrequency ) {
                $this->assertNotNull( $task->dueCheck, $task->id . ': continuous only with a due-check' );
            }
        }
        $wp2 = self::wp2TaskIds();
        $this->assertSame( [ 'cleanup' ], array_values( array_map( static fn( TaskDefinition $t ): string => $t->id, array_filter( $tasks, static fn( TaskDefinition $t ): bool => ! $t->offAllowed && isset( $wp2[ $t->id ] ) ) ) ), 'of the WP2 tasks, only cleanup can never be off' );
        $orders = array_map( static fn( TaskDefinition $t ): int => $t->order, $tasks );
        $sorted = $orders;
        sort( $sorted );
        $this->assertSame( $sorted, $orders, 'in tick order' );
    }

    public function testDueChecksAndSchedulesAreMethodsOfTheOwningModulesDueChecks(): void {
        $found = [];
        foreach ( Container::plugin()->modules() as $module ) {
            $isWp2     = in_array( get_class( $module ), Wp2Modules::LIST, true );
            $namespace = substr( get_class( $module ), 0, (int) strrpos( get_class( $module ), '\\' ) );
            foreach ( $module->tasks() as $task ) {
                foreach ( [ $task->dueCheck, $task->schedule ] as $name ) {
                    if ( null === $name ) {
                        continue;
                    }
                    $class = $namespace . '\\DueChecks';
                    $this->assertTrue( method_exists( $class, $name ), $class . '::' . $name . ' (' . $task->id . ')' );
                    if ( ! $isWp2 ) {
                        continue; // the registry builds another module's DueChecks with the container when it is no service
                    }
                    $this->assertTrue( Container::plugin()->container()->has( $class ), $class . ' is a service of its module' );
                    $found[] = $task->id . ':' . $name;
                }
            }
        }
        sort( $found );
        $this->assertSame( [ 'catalog_import:catalogImportDue', 'evaluate:evaluateDue', 'find_opportunities:opportunitiesDue', 'import_backlinks:importSchedule', 'indexnow:indexnowDue', 'internal_links:internalLinksSchedule', 'monitor_backlinks:monitorDue', 'outreach_send:outreachDue', 'submit:submitDue' ], $found );
    }

    public function testTheSchedulesFollowTheEnginesSettings(): void {
        $registry = self::registry();
        $tasks    = self::byId();
        self::set( 'monitoring.import_interval_days', 3 );
        $this->assertSame( [ 'every' => 3 * DAY_IN_SECONDS, 'cadence' => 'every 3 days' ], $registry->schedule( $tasks['import_backlinks'] ) );
        self::set( 'seo.auto_apply_links', true );
        $this->assertSame( [ 'every' => DAY_IN_SECONDS, 'cadence' => 'daily (applies up to 20 links automatically)' ], $registry->schedule( $tasks['internal_links'] ) );
        self::set( 'seo.internal_links', false );
        $this->assertSame( [ 'every' => WEEK_IN_SECONDS, 'cadence' => 'weekly (suggestions only)' ], $registry->schedule( $tasks['internal_links'] ) );
    }

    // ------------------------------------------------------------------
    // The filter and the scheduler
    // ------------------------------------------------------------------

    public function testATaskAddedThroughTheFilterIsScheduled(): void {
        add_filter( TaskRegistry::FILTER, static fn( array $tasks ): array => array_merge( $tasks, [ self::probe( [ 'priority' => 2 ] ) ] ) );
        add_filter( 'lga_test_time', static fn(): int => FrozenClock::now() );
        $this->assertTrue( Container::get( Locks::class )->acquire( JobRunner::LOCK, DAY_IN_SECONDS ), 'the runner is busy elsewhere: ticks only enqueue' );
        $jobs   = Container::get( Jobs::class );
        $probes = static fn(): array => $jobs->findBy( [ 'type' => 'registry_probe' ], 'id', 'ASC', 50 );

        $tasks = self::registry()->tasks();
        $this->assertSame( 'registry_probe', end( $tasks )->id, 'an extension task comes after the built-in ones (ORDER_LAST)' );

        FrozenClock::set( self::T0 );
        $out = Container::get( Pipeline::class )->tick();
        $this->assertContains( 'registry_probe', $out['enqueued'] );
        $this->assertCount( 1, $probes() );
        $this->assertSame( 2, (int) $probes()[0]['priority'], 'with the task\'s priority' );
        $this->assertSame( 'registry_probe', array_slice( $out['enqueued'], -1 )[0], 'last in the tick' );

        FrozenClock::set( self::T0 + 600 );
        $this->assertNotContains( 'registry_probe', Container::get( Pipeline::class )->tick()['enqueued'], 'ten minutes later: not due' );
        $jobs->update( (int) $probes()[0]['id'], [ 'status' => Jobs::STATUS_COMPLETED ] );
        FrozenClock::set( self::T0 + 1800 );
        $this->assertNotContains( 'registry_probe', Container::get( Pipeline::class )->tick()['enqueued'], 'hourly: not due yet' );
        FrozenClock::set( self::T0 + 3600 );
        $this->assertContains( 'registry_probe', Container::get( Pipeline::class )->tick()['enqueued'], 'due again an hour after the last run' );
        $this->assertCount( 2, $probes() );

        $stages = Container::get( Pipeline::class )->status()['stages'];
        $this->assertSame( [ 'priority' => 2, 'cadence' => 'hourly', 'flag' => '' ], $stages['registry_probe'] );
    }

    public function testATaskWithADueCheckOfItsOwnIsEnqueuedOnlyWhileItFindsWork(): void {
        add_filter( TaskRegistry::FILTER, static fn( array $tasks ): array => array_merge( $tasks, [ self::probe( [ 'defaultFrequency' => Frequency::CONTINUOUS, 'dueCheck' => TaskRegistryTestChecks::class . '::due', 'cadence' => 'every tick (when probes wait)' ] ) ] ) );
        $this->assertTrue( Container::get( Locks::class )->acquire( JobRunner::LOCK, DAY_IN_SECONDS ) );
        TaskRegistryTestChecks::$due = false;
        $this->assertNotContains( 'registry_probe', Container::get( Pipeline::class )->tick()['enqueued'] );
        TaskRegistryTestChecks::$due = true;
        $this->assertContains( 'registry_probe', Container::get( Pipeline::class )->tick()['enqueued'] );
        $this->assertSame( 'every tick (when probes wait)', Container::get( Pipeline::class )->status()['stages']['registry_probe']['cadence'] );
    }

    public function testTheSchedulerTakesItsTasksFromTheRegistry(): void {
        $this->assertFalse( method_exists( Pipeline::class, 'stages' ), 'Pipeline::stages() is gone' );
        add_filter( TaskRegistry::FILTER, static fn( array $tasks ): array => array_values( array_filter( $tasks, static fn( TaskDefinition $t ): bool => 'discover' !== $t->id ) ) );
        $this->assertTrue( Container::get( Locks::class )->acquire( JobRunner::LOCK, DAY_IN_SECONDS ) );
        $out = Container::get( Pipeline::class )->tick();
        $this->assertNotContains( 'discover', $out['enqueued'], 'a task the filter removed is not scheduled' );
        $this->assertContains( 'mention_scan', $out['enqueued'] );
        $this->assertSame( [ 'priority' => 6, 'cadence' => 'manual', 'flag' => '' ], Container::get( Pipeline::class )->status()['stages']['discover'], 'its job type is still listed, as one only a person starts' );
    }

    public function testInvalidTasksAreLeftOutWithTheirReason(): void {
        $builtIn = count( self::registry()->tasks() );
        $this->assertSame( [], self::registry()->rejected() );
        add_filter(
            TaskRegistry::FILTER,
            static fn( array $tasks ): array => array_merge(
                $tasks,
                [
                    'not a task',
                    self::probe( [ 'id' => 'Bad-Id' ] ),
                    self::probe( [ 'id' => 'discover' ] ),
                    self::probe( [ 'id' => 'probe_unknown', 'jobType' => 'no_such_job' ] ),
                    self::probe( [ 'id' => 'probe_second_discover', 'jobType' => 'discover' ] ),
                    self::probe( [ 'id' => 'probe_priority', 'priority' => 10 ] ),
                    self::probe( [ 'id' => 'probe_frequency', 'defaultFrequency' => 'fortnightly' ] ),
                    self::probe( [ 'id' => 'probe_continuous', 'defaultFrequency' => Frequency::CONTINUOUS ] ),
                    self::probe( [ 'id' => 'probe_off', 'defaultFrequency' => Frequency::OFF, 'offAllowed' => false ] ),
                    self::probe( [ 'id' => 'probe_outbound', 'outbound' => true ] ),
                    self::probe( [ 'id' => 'probe_autopilot', 'autopilot' => true ] ),
                    self::probe( [ 'id' => 'probe_module', 'module' => 'No Flag' ] ),
                    self::probe( [ 'id' => 'probe_due_bare', 'dueCheck' => 'due' ] ),
                    self::probe( [ 'id' => 'probe_due_missing', 'dueCheck' => TaskRegistryTestChecks::class . '::nope' ] ),
                    self::probe( [ 'id' => 'probe_schedule', 'schedule' => 'No\\Such\\Checks::schedule' ] ),
                    self::probe(),
                ]
            )
        );
        $registry = self::registry();
        $ids      = array_map( static fn( TaskDefinition $t ): string => $t->id, $registry->tasks() );
        $this->assertCount( $builtIn + 1, $ids, 'the built-in tasks (the 20 of the WP2 modules and any a later module adds) and the one valid probe' );
        $this->assertContains( 'registry_probe', $ids );
        $this->assertSame(
            [
                [ 'id' => '#' . $builtIn, 'reason' => 'not_a_task' ],
                [ 'id' => 'Bad-Id', 'reason' => 'bad_id' ],
                [ 'id' => 'discover', 'reason' => 'duplicate_id' ],
                [ 'id' => 'probe_unknown', 'reason' => 'unknown_job_type' ],
                [ 'id' => 'probe_second_discover', 'reason' => 'duplicate_job_type' ],
                [ 'id' => 'probe_priority', 'reason' => 'bad_priority' ],
                [ 'id' => 'probe_frequency', 'reason' => 'bad_frequency' ],
                [ 'id' => 'probe_continuous', 'reason' => 'continuous_without_due_check' ],
                [ 'id' => 'probe_off', 'reason' => 'off_not_allowed' ],
                [ 'id' => 'probe_outbound', 'reason' => 'outbound_without_profile' ],
                [ 'id' => 'probe_autopilot', 'reason' => 'autopilot_without_consent' ],
                [ 'id' => 'probe_module', 'reason' => 'bad_module' ],
                [ 'id' => 'probe_due_bare', 'reason' => 'due_check_missing' ],
                [ 'id' => 'probe_due_missing', 'reason' => 'due_check_missing' ],
                [ 'id' => 'probe_schedule', 'reason' => 'schedule_missing' ],
            ],
            $registry->rejected()
        );

        add_filter( TaskRegistry::FILTER, static fn(): string => 'not a list' );
        $this->assertSame( [], $registry->tasks(), 'a filter that returns no list leaves no task' );
    }

    // ------------------------------------------------------------------
    // The gates (DESIGN.md §5.2), one test each, in order
    // ------------------------------------------------------------------

    public function testTheGatesAreInTheOrderOfTheDesign(): void {
        $this->assertSame( [ 'master', 'module', 'profile', 'setup', 'consent', 'autopilot', 'load', 'limit', 'schedule', 'lock' ], array_keys( TaskRegistry::GATES ) );
        $this->assertSame(
            [ 'lga_paused_master', 'lga_module_off', 'lga_profile_incomplete', 'lga_setup_incomplete', 'lga_consent_missing', 'lga_autopilot_off', 'lga_paused_load', 'lga_limit_reached', 'lga_task_off', 'lga_not_due', 'lga_no_work', 'lga_already_queued' ],
            array_merge( ...array_values( TaskRegistry::GATES ) ),
            'the reason codes of §5.2 (and of the schedule and the lock)'
        );
        $this->assertSame( '', self::gate( self::probe() ), 'a probe with every gate open is let through' );
    }

    public function testGate1TheMasterSwitchHoldsEveryTaskFirst(): void {
        self::set( 'automation.enabled', false );
        self::set( 'automation.discovery', false );
        self::pauseBackground();
        $discover = self::byId()['discover'];
        $this->assertSame( TaskRegistry::PAUSED_MASTER, self::gate( $discover ), 'before the module flag and the pause' );
        $this->assertSame( TaskRegistry::PAUSED_MASTER, self::gate( self::probe() ) );
        $this->assertSame( TaskRegistry::PAUSED_MASTER, self::gate( self::probe(), Context::manual( self::T0 ) ), 'a person is held too (DESIGN.md §5.2)' );
        self::set( 'automation.enabled', true );
        $this->assertSame( TaskRegistry::MODULE_OFF, self::gate( $discover ) );
    }

    public function testGate2TheModuleFlagHoldsItsTasksAsAutopilotReadsIt(): void {
        self::set( 'automation.discovery', false );
        self::pauseBackground();
        $tasks = self::byId();
        $this->assertSame( TaskRegistry::MODULE_OFF, self::gate( $tasks['discover'] ), 'before the pause' );
        $this->assertSame( TaskRegistry::MODULE_OFF, self::gate( $tasks['mention_scan'] ) );
        $this->assertSame( TaskRegistry::MODULE_OFF, self::gate( $tasks['discover'], Context::manual( self::T0 ) ) );
        $this->assertSame( TaskRegistry::PAUSED_LOAD, self::gate( $tasks['site_audit'] ), 'a task without a flag goes on to the next gate' );

        self::set( 'automation.submissions', false );
        $this->assertSame( TaskRegistry::MODULE_OFF, self::gate( $tasks['submit'] ) );
        self::set( 'automation.autopilot', true );
        $this->assertSame( TaskRegistry::PAUSED_LOAD, self::gate( $tasks['submit'] ), 'Autopilot raises the submissions flag (Autopilot::flag())' );
        $this->assertSame( TaskRegistry::MODULE_OFF, self::gate( $tasks['discover'] ), 'but not the discovery flag' );
    }

    public function testGates3To6ProfileSetupConsentAndAutopilotLetEveryTaskThroughUntilWP4b(): void {
        $guarded = self::probe( [ 'requiresProfile' => true, 'requiresSetup' => true, 'consent' => 'submissions', 'autopilot' => true, 'outbound' => true ] );
        $this->assertSame( '', self::gate( $guarded ), 'no setup state, consent record or Autopilot consent exists before WP4a/WP4b' );
        $this->assertSame( '', self::gate( $guarded, Context::manual( self::T0 ) ) );
        self::set( 'automation.autopilot', false );
        $this->assertSame( TaskRegistry::NO_WORK, self::gate( self::byId()['submit'] ), 'submit (P S C:submissions A O) passes gates 3-6 and is held only by its due-check: nothing waits' );
        $source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Automation/TaskRegistry.php' );
        foreach ( [ 'profileReady', 'setupComplete', 'consentRecorded', 'autopilotAllows' ] as $stub ) {
            $this->assertMatchesRegularExpression( '/TODO\(WP4b\)[^\n]*\n\s+private function ' . $stub . '\(/', $source, $stub . ' is the WP4b stub' );
        }
    }

    public function testGate7TheLoadGuardPauseHoldsEveryTask(): void {
        self::pauseBackground();
        self::exhaust( 'evaluations' );
        $this->assertSame( TaskRegistry::PAUSED_LOAD, self::gate( self::probe() ) );
        $this->assertSame( TaskRegistry::PAUSED_LOAD, self::gate( self::byId()['evaluate'] ), 'before the limit pool' );
        $this->assertSame( TaskRegistry::PAUSED_LOAD, self::gate( self::probe(), Context::manual( self::T0 ) ), 'a person is held too (DESIGN.md §5.2)' );
    }

    public function testGate8TheLimitPoolHoldsTasksThatSpendIt(): void {
        $evaluate = self::byId()['evaluate'];
        $pooled   = self::probe( [ 'budgetPool' => 'evaluations' ] );
        $this->assertSame( '', self::gate( $pooled ), 'the pool has room' );
        self::exhaust( 'evaluations' );
        $this->assertSame( TaskRegistry::LIMIT_REACHED, self::gate( $pooled ) );
        $this->assertSame( TaskRegistry::LIMIT_REACHED, self::gate( $pooled, Context::scheduler( self::T0, self::ranAgo( 'registry_probe', 60 ) ) ), 'before the schedule' );
        $this->assertSame( TaskRegistry::LIMIT_REACHED, self::gate( $pooled, Context::manual( self::T0 ) ), 'a person is held too' );
        $this->assertSame( TaskRegistry::LIMIT_REACHED, self::gate( $evaluate ), 'evaluate spends the evaluations pool' );
        $this->assertSame( '', self::gate( self::probe() ), 'a task without a pool is not held' );
        $this->assertSame( '', self::gate( self::probe( [ 'budgetPool' => 'submissions' ] ) ), 'another pool with room holds nothing' );
    }

    public function testGate9TheScheduleHoldsTasksThatAreOffNotDueOrWithoutWork(): void {
        $hourly = self::probe();
        $this->assertSame( '', self::gate( $hourly ), 'never ran' );
        $this->assertSame( TaskRegistry::NOT_DUE, self::gate( $hourly, Context::scheduler( self::T0, self::ranAgo( 'registry_probe', 3599 ) ) ), 'an hour has not passed' );
        $this->assertSame( '', self::gate( $hourly, Context::scheduler( self::T0, self::ranAgo( 'registry_probe', 3600 ) ) ), 'due an hour after the last run' );
        $this->assertSame( '', self::gate( $hourly, Context::manual( self::T0 ) ), 'a person is not held by the schedule' );

        $off = self::probe( [ 'defaultFrequency' => Frequency::OFF ] );
        $this->assertSame( TaskRegistry::TASK_OFF, self::gate( $off ) );
        $this->assertSame( '', self::gate( $off, Context::manual( self::T0 ) ), 'an off task still runs when a person starts it' );
        $this->assertSame( TaskRegistry::TASK_OFF, self::gate( self::byId()['broken_link_scan'] ), 'the broken-link scan only runs when started (as in the engine)' );

        $continuous = self::probe( [ 'defaultFrequency' => Frequency::CONTINUOUS, 'dueCheck' => TaskRegistryTestChecks::class . '::due' ] );
        $this->assertSame( '', self::gate( $continuous, Context::scheduler( self::T0, self::ranAgo( 'registry_probe', 1 ) ) ), 'continuous: considered on every tick' );
        TaskRegistryTestChecks::$due = false;
        $this->assertSame( TaskRegistry::NO_WORK, self::gate( $continuous ), 'the due-check finds nothing waiting' );
        $this->assertSame( '', self::gate( $continuous, Context::manual( self::T0 ) ), 'a person is not held by the due-check' );
        $this->assertSame( TaskRegistry::NO_WORK, self::gate( self::byId()['evaluate'] ), 'a module task: its DueChecks method (no prospect waits)' );

        Container::get( Jobs::class )->enqueue( 'registry_probe', [], 5 );
        $this->assertSame( TaskRegistry::NOT_DUE, self::gate( $hourly, Context::scheduler( self::T0, self::ranAgo( 'registry_probe', 60 ) ) ), 'before the lock' );
        $this->assertSame( TaskRegistry::NO_WORK, self::gate( $continuous ), 'before the lock' );
    }

    public function testGate10TheLockHoldsATaskWhoseJobIsQueuedOrRunning(): void {
        $jobs = Container::get( Jobs::class );
        $task = self::probe();
        $id   = $jobs->enqueue( 'registry_probe', [], 5 );
        $this->assertSame( TaskRegistry::ALREADY_QUEUED, self::gate( $task ) );
        $this->assertSame( '', self::gate( $task, Context::manual( self::T0 ) ), 'a person is not held by the lock' );
        $jobs->update( $id, [ 'status' => Jobs::STATUS_RUNNING ] );
        $this->assertSame( TaskRegistry::ALREADY_QUEUED, self::gate( $task ), 'running counts as well' );
        $jobs->update( $id, [ 'status' => Jobs::STATUS_COMPLETED ] );
        $this->assertSame( '', self::gate( $task ), 'a finished job does not' );
        $jobs->enqueue( 'registry_probe', [ 'manual' => true ], 1 );
        $this->assertSame( TaskRegistry::ALREADY_QUEUED, self::gate( $task ), 'nor does it matter who queued it (the engine\'s duplicate check)' );
    }

    public function testAManualContextSkipsOnlyTheAutopilotGateTheScheduleAndTheLock(): void {
        $manual = Context::manual( self::T0 );
        $this->assertTrue( $manual->isManual() );
        $this->assertFalse( Context::scheduler( self::T0 )->isManual() );
        Container::get( Jobs::class )->enqueue( 'registry_probe', [], 5 );
        TaskRegistryTestChecks::$due = false;
        foreach ( [ self::probe( [ 'defaultFrequency' => Frequency::OFF ] ), self::probe( [ 'defaultFrequency' => Frequency::CONTINUOUS, 'dueCheck' => TaskRegistryTestChecks::class . '::due' ] ), self::probe() ] as $task ) {
            $this->assertSame( '', self::gate( $task, $manual ), $task->defaultFrequency );
            $this->assertNotSame( '', self::gate( $task ), $task->defaultFrequency . ': the scheduler is held' );
        }
        $this->assertSame( self::T0 - 60, Context::scheduler( self::T0, self::ranAgo( 'x', 60 ) )->lastRun( 'x' ) );
        $this->assertSame( 0, Context::scheduler( self::T0, [ 'x' => 'not a time' ] )->lastRun( 'x' ) );
        $this->assertSame( 0, $manual->lastRun( 'x' ) );
    }
}

/** A job type an extension registers (through lga_job_types). */
final class TaskRegistryTestJob extends AbstractJob {
    public static function type(): string {
        return 'registry_probe';
    }
    public function handle( array $payload, TimeBudget $budget, PluginContainer $c ): array {
        unset( $payload, $budget, $c );
        return [ 'ok' => true ];
    }
}

/** An extension's due-check class (built with the container as its argument). */
final class TaskRegistryTestChecks {
    public static bool $due = true;
    public function __construct( PluginContainer $c ) {
        unset( $c );
    }
    public function due(): bool {
        return self::$due;
    }
}
