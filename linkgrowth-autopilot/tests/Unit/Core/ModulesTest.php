<?php
/**
 * The module seam (DESIGN.md §3.1, WP2): every module in Core\Modules::LIST implements Core\Module and has a unique
 * id; Plugin (services, boot), Database\Schema (tables), Support\Settings (defaults), Support\SettingsSchema (rules),
 * JobRunner::types() (jobs), Automation\TaskRegistry (tasks), Admin\Rest\RestApi (REST controllers), Admin\Menu
 * (pages, settings tabs), Health\HealthMonitor (health checks) and Cli\CommandRegistry (WP-CLI commands) return the
 * union of the modules' declarations; and a module added through the `lga_modules` filter (ModulesTestProbeModule
 * below) is collected by every one of them.
 *
 * The ids, counts and lists written here are what the 13 WP2 modules declare (Tests\Support\Wp2Modules), checked to
 * be among what the collectors return: a later package's line in Core\Modules::LIST adds to them without breaking
 * this test (DESIGN.md §13.1).
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Core;

use LinkGrowth\Autopilot\Admin\Menu;
use LinkGrowth\Autopilot\Admin\Rest\Controller;
use LinkGrowth\Autopilot\Admin\Rest\RestApi;
use LinkGrowth\Autopilot\Automation\Context;
use LinkGrowth\Autopilot\Automation\Frequency;
use LinkGrowth\Autopilot\Automation\JobRunner;
use LinkGrowth\Autopilot\Automation\Jobs\AbstractJob;
use LinkGrowth\Autopilot\Automation\TaskDefinition;
use LinkGrowth\Autopilot\Automation\TaskRegistry;
use LinkGrowth\Autopilot\Cli\CommandRegistry;
use LinkGrowth\Autopilot\Container as PluginContainer;
use LinkGrowth\Autopilot\Core\AbstractModule;
use LinkGrowth\Autopilot\Core\CoreModule;
use LinkGrowth\Autopilot\Core\Module;
use LinkGrowth\Autopilot\Core\Modules;
use LinkGrowth\Autopilot\Database\Schema;
use LinkGrowth\Autopilot\Health\Check;
use LinkGrowth\Autopilot\Health\Checks\CoexistenceCheck;
use LinkGrowth\Autopilot\Health\Checks\OpcacheCheck;
use LinkGrowth\Autopilot\Health\HealthMonitor;
use LinkGrowth\Autopilot\Plugin;
use LinkGrowth\Autopilot\Support\Opcache;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\SettingsSchema;
use LinkGrowth\Autopilot\Support\TimeBudget;
use LinkGrowth\Autopilot\Tests\Support\Container;
use LinkGrowth\Autopilot\Tests\Support\TestCase;
use LinkGrowth\Autopilot\Tests\Support\Wp2Modules;

final class ModulesTest extends TestCase {

    /** The Settings tabs of the WP2 modules, in the Settings page's order. */
    private const WP2_TABS = [ 'business', 'automation', 'discovery', 'integrations', 'outreach', 'content', 'monitoring', 'advanced' ];

    /** The Core\Module methods, in the order of DESIGN.md §3.1. */
    private const METHODS = [ 'id', 'services', 'boot', 'tables', 'settingsDefaults', 'settingsRules', 'jobs', 'tasks', 'restControllers', 'adminPages', 'settingsTabs', 'healthChecks', 'cliCommands' ];

    protected function tearDown(): void {
        $GLOBALS['lga_test_caps']     = [];
        $GLOBALS['lga_test_is_admin'] = false;
        $GLOBALS['lga_test_user_id']  = 0;
        unset( $GLOBALS['lga_test_routes'], $GLOBALS['lga_test_menu'], $_GET['page'] );
        ModulesTestProbeModule::$throwOnBoot = false;
        ModulesTestProbeChecks::$due         = true;
        // The probe module must not reach the next test through the memoised module list of this plugin instance.
        Container::reset();
        parent::tearDown();
    }

    /** Adds the probe module through `lga_modules` and starts a fresh plugin instance (the list is read once). */
    private static function withProbe(): Plugin {
        add_filter( Modules::FILTER, static fn( array $list ): array => array_merge( $list, [ ModulesTestProbeModule::class ] ) );
        Container::reset();
        return Container::plugin();
    }

    /** @return array<string,callable> the factories a container holds */
    private static function factories( PluginContainer $c ): array {
        return (array) ( new \ReflectionProperty( PluginContainer::class, 'factories' ) )->getValue( $c );
    }

    /**
     * Each module's declarations of one kind, keyed by module id.
     *
     * @return array<string,array<mixed>>
     */
    private static function declared( string $method ): array {
        $out = [];
        foreach ( Container::plugin()->modules() as $module ) {
            $out[ $module->id() ] = (array) $module->{$method}();
        }
        return $out;
    }

    /**
     * What the WP2 modules declare of one kind, merged (a map keeps its keys, a list is appended to).
     *
     * @return array<mixed>
     */
    private static function wp2( string $method ): array {
        return array_merge( ...array_values( Wp2Modules::declared( $method ) ) );
    }

    /**
     * The keys of page or tab definitions in their `order` (stable, as Admin\Menu sorts them).
     *
     * @param array<string,array{order:int}> $definitions
     * @return list<string>
     */
    private static function sortedKeys( array $definitions ): array {
        uasort( $definitions, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );
        return array_keys( $definitions );
    }

    /**
     * The hook names (with counts) $register adds, in the unit stubs.
     *
     * @return array<string,int>
     */
    private static function hooksOf( callable $register ): array {
        $GLOBALS['lga_test_actions'] = [];
        $GLOBALS['lga_test_filters'] = [];
        $register();
        $out = [];
        foreach ( [ 'lga_test_actions', 'lga_test_filters' ] as $global ) {
            foreach ( $GLOBALS[ $global ] as $hook => $callbacks ) {
                $out[ $hook ] = ( $out[ $hook ] ?? 0 ) + count( $callbacks );
            }
        }
        ksort( $out );
        return $out;
    }

    // ------------------------------------------------------------------
    // The list and the interface
    // ------------------------------------------------------------------

    public function testEveryListedModuleImplementsTheInterfaceAndHasAUniqueId(): void {
        $ids = [];
        foreach ( Modules::LIST as $class ) {
            $this->assertTrue( class_exists( $class ), $class );
            $this->assertTrue( is_subclass_of( $class, Module::class ), $class . ' implements Core\Module' );
            $this->assertTrue( ( new \ReflectionClass( $class ) )->isInstantiable(), $class );
            $module = new $class();
            $this->assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $module->id(), $class );
            $ids[] = $module->id();
        }
        $this->assertSame( Wp2Modules::IDS, Wp2Modules::only( $ids, Wp2Modules::IDS ), 'the modules of DESIGN.md §13.2 WP2, each once and in their order (later packages add theirs)' );
        $this->assertSame( Wp2Modules::LIST, Wp2Modules::only( Modules::LIST, Wp2Modules::LIST ), 'Tests\Support\Wp2Modules names their classes' );
        $this->assertSame( array_unique( $ids ), $ids, 'ids are unique' );

        $loaded = Container::plugin()->modules();
        $this->assertSame( Modules::LIST, array_map( 'get_class', $loaded ), 'without the filter the plugin loads LIST, in order' );
        $this->assertSame( $loaded, Container::plugin()->modules(), 'built once per plugin instance' );
        $this->assertSame( [], Container::plugin()->bootErrors() );
    }

    public function testTheInterfaceIsTheOneOfTheDesignAndTheBaseModuleDeclaresNothing(): void {
        $methods = array_map( static fn( \ReflectionMethod $m ): string => $m->getName(), ( new \ReflectionClass( Module::class ) )->getMethods() );
        $this->assertSame( self::METHODS, $methods );

        $base = new class() extends AbstractModule {
            public function id(): string {
                return 'base_probe';
            }
        };
        foreach ( array_slice( self::METHODS, 3 ) as $method ) {
            $this->assertSame( [], $base->{$method}(), 'AbstractModule::' . $method . '() is empty' );
        }
        $c = new PluginContainer();
        $base->services( $c );
        $this->assertSame( [], self::factories( $c ) );
        $GLOBALS['lga_test_actions'] = $GLOBALS['lga_test_filters'] = [];
        $base->boot( Container::plugin() );
        $this->assertSame( [ [], [] ], [ $GLOBALS['lga_test_actions'], $GLOBALS['lga_test_filters'] ] );
    }

    public function testInvalidEntriesAndTakenIdsAreLeftOutOfTheList(): void {
        $instance = new ModulesTestProbeModule();
        add_filter(
            Modules::FILTER,
            static fn( array $list ): array => array_merge(
                $list,
                [ 'No\\Such\\ModuleClass', \stdClass::class, AbstractModule::class, 42, CoreModule::class, ModulesTestBadIdModule::class, $instance, ModulesTestProbeModule::class ]
            )
        );
        $listed = array_map( static fn( string $class ): string => ( new $class() )->id(), Modules::LIST );
        $loaded = Modules::load();
        $this->assertSame( array_merge( $listed, [ 'probe' ] ), array_map( static fn( Module $m ): string => $m->id(), $loaded ), 'a second core and a second probe are left out, the instance is kept' );
        $this->assertSame( $instance, $loaded[ count( $loaded ) - 1 ] );

        add_filter( Modules::FILTER, static fn(): string => 'not a list' );
        $this->assertSame( [], Modules::load() );
    }

    // ------------------------------------------------------------------
    // Every collector returns the union of the modules' declarations
    // ------------------------------------------------------------------

    public function testTheContainerHoldsTheUnionOfTheModulesServices(): void {
        $union = [];
        foreach ( Container::plugin()->modules() as $module ) {
            $c = new PluginContainer();
            $module->services( $c );
            $ids = array_keys( self::factories( $c ) );
            foreach ( $ids as $id ) {
                $this->assertArrayNotHasKey( $id, $union, $id . ' is registered by one module only (' . $module->id() . ' and ' . ( $union[ $id ] ?? '' ) . ')' );
                $union[ $id ] = $module->id();
            }
        }
        $registered = array_keys( self::factories( Container::plugin()->container() ) );
        sort( $registered );
        $expected = array_keys( $union );
        sort( $expected );
        $this->assertSame( $expected, $registered );
        $wp2 = array_keys( array_filter( $union, static fn( string $owner ): bool => in_array( $owner, Wp2Modules::IDS, true ) ) );
        $this->assertCount( 106, $wp2, 'the WP2 modules: the 94 factories Plugin::registerServices() held before WP2, plus the two health checks, the health monitor and the WP-CLI registry (part 1), the settings schema, the task registry and the DueChecks of six modules (part 2)' );
        foreach ( $registered as $id ) {
            $this->assertInstanceOf( $id, Container::get( $id ), $id . ' builds' );
        }
    }

    public function testBootAddsTheUnionOfTheModulesHooks(): void {
        $perModule = [];
        $wp2       = [];
        foreach ( Container::plugin()->modules() as $module ) {
            $isWp2 = in_array( get_class( $module ), Wp2Modules::LIST, true );
            foreach ( self::hooksOf( static fn() => $module->boot( Container::plugin() ) ) as $hook => $n ) {
                $perModule[ $hook ] = ( $perModule[ $hook ] ?? 0 ) + $n;
                if ( $isWp2 ) {
                    $wp2[ $hook ] = ( $wp2[ $hook ] ?? 0 ) + $n;
                }
            }
        }
        ksort( $perModule );
        Container::reset();
        $booted = self::hooksOf( static fn() => Container::plugin()->boot() );
        $this->assertSame( $perModule, $booted );
        $this->assertSame( 3, $wp2['init'] ?? 0, 'core, admin and automation each add one maintenance check' );
        foreach ( [ 'rest_api_init', 'site_status_tests', 'lga_tick', 'cron_schedules', 'parse_request', 'save_post', 'save_post_product' ] as $hook ) {
            $this->assertSame( 1, $wp2[ $hook ] ?? 0, $hook . ' (the WP2 modules)' );
        }
    }

    public function testTheSchemaHoldsTheUnionOfTheModulesTables(): void {
        $union = [];
        foreach ( self::declared( 'tables' ) as $id => $tables ) {
            foreach ( $tables as $name => $sql ) {
                $this->assertArrayNotHasKey( $name, $union, $name . ' is declared by one module only' );
                $this->assertStringStartsWith( 'CREATE TABLE ' . Schema::table( $name ) . ' (', $sql, $name . ' (' . $id . ')' );
                $union[ $name ] = $sql;
            }
        }
        $this->assertSame( $union, Schema::declared() );
        $this->assertSame( array_keys( $union ), Schema::names() );
        $this->assertSame( array_values( $union ), ( new Schema() )->statements() );
        $names = array_keys( self::wp2( 'tables' ) );
        sort( $names );
        $this->assertSame( [ 'audit_log', 'backlinks', 'campaigns', 'contacts', 'domain_metrics', 'jobs', 'logs', 'notifications', 'opportunities', 'outreach_messages', 'prospects', 'search_log', 'submissions', 'suppression' ], $names, 'the 14 engine tables (the WP2 modules)' );
        $this->assertSame( [], array_values( array_diff( $names, Schema::names() ) ), 'all in the schema' );
    }

    public function testTheSettingsDefaultsAreTheUnionOfTheModulesSections(): void {
        $union = [];
        foreach ( self::declared( 'settingsDefaults' ) as $sections ) {
            foreach ( $sections as $section => $defaults ) {
                $this->assertArrayNotHasKey( $section, $union, $section . ' is declared by one module only' );
                $union[ $section ] = $defaults;
            }
        }
        $this->assertSame( $union, Settings::defaults() );
        $wp2 = self::wp2( 'settingsDefaults' );
        $this->assertSame( $wp2, array_intersect_key( Settings::defaults(), $wp2 ), 'the WP2 sections are among the defaults' );
        $sections = array_keys( $wp2 );
        sort( $sections );
        $this->assertSame( [ 'advanced', 'automation', 'compliance', 'discovery', 'growth', 'monitoring', 'outreach', 'providers', 'seo', 'site' ], $sections );
        $this->assertSame( 'northwind.test', Settings::defaults()['site']['domain'], 'derived from the home URL at call time' );
        $this->assertSame( 'sc-domain:northwind.test', Settings::defaults()['providers']['google']['gsc_property'] );
    }

    public function testTheSettingsRulesAreTheUnionOfTheModulesRules(): void {
        $union = [];
        foreach ( self::declared( 'settingsRules' ) as $id => $rules ) {
            foreach ( $rules as $path => $rule ) {
                $this->assertArrayNotHasKey( $path, $union, $path . ' is declared by one module only (' . $id . ')' );
                $union[ $path ] = $rule;
            }
        }
        $schema = Container::get( SettingsSchema::class );
        $this->assertSame( $union, $schema->rules() );
        $this->assertSame( [], $schema->duplicates() );
        $this->assertCount( 120, self::wp2( 'settingsRules' ), 'the WP2 modules: one rule per settings key (116) and the four optional keys' );
        $this->assertSame( [], self::declared( 'settingsRules' )['analysis'], 'the analysis module has no settings' );
    }

    public function testTheTasksAreTheUnionOfTheModulesTasks(): void {
        $union = [];
        foreach ( self::declared( 'tasks' ) as $id => $tasks ) {
            foreach ( $tasks as $task ) {
                $this->assertInstanceOf( TaskDefinition::class, $task, $id );
                $this->assertArrayNotHasKey( $task->id, $union, $task->id . ' is declared by one module only (' . $id . ')' );
                $union[ $task->id ] = $task->jobType;
            }
        }
        $registry  = Container::get( TaskRegistry::class );
        $collected = [];
        foreach ( $registry->tasks() as $task ) {
            $collected[ $task->id ] = $task->jobType;
        }
        $this->assertSame( [], $registry->rejected() );
        ksort( $union );
        ksort( $collected );
        $this->assertSame( $union, $collected, 'the same tasks (the registry puts them in tick order)' );
        $wp2Tasks = self::wp2( 'tasks' );
        $this->assertCount( 20, $wp2Tasks, 'the WP2 modules: one per engine job type' );
        foreach ( $wp2Tasks as $task ) {
            $this->assertSame( $task->jobType, $collected[ $task->id ] ?? null, $task->id . ' is collected' );
        }
        $wp2Types = array_keys( self::wp2( 'jobs' ) );
        $this->assertSame( $wp2Types, Wp2Modules::only( $wp2Types, array_values( $collected ) ), 'every job type of the WP2 modules has a task' );
    }

    public function testTheJobTypesAreTheUnionOfTheModulesJobs(): void {
        $union = [];
        foreach ( self::declared( 'jobs' ) as $jobs ) {
            foreach ( $jobs as $type => $class ) {
                $this->assertArrayNotHasKey( $type, $union, $type . ' is declared by one module only' );
                $this->assertSame( $type, $class::type(), $class . ' reports the type it is declared under' );
                $union[ $type ] = $class;
            }
        }
        $this->assertSame( $union, Container::get( JobRunner::class )->types() );
        $this->assertCount( 20, self::wp2( 'jobs' ), 'the WP2 modules' );
    }

    public function testTheRestControllersAreTheUnionOfTheModulesControllers(): void {
        $union = [];
        foreach ( self::declared( 'restControllers' ) as $controllers ) {
            foreach ( $controllers as $class ) {
                $this->assertNotContains( $class, $union, $class . ' is declared by one module only' );
                $union[] = $class;
            }
        }
        $this->assertSame( $union, ( new RestApi( Container::plugin()->container() ) )->controllers() );
        $this->assertCount( 12, self::wp2( 'restControllers' ), 'the WP2 modules' );
    }

    /**
     * WP2 registers the controllers in module order, so the route order changed (docs/notes/WP2.md). It cannot change
     * which handler answers a request: WordPress takes the first route whose pattern matches the path, and no path
     * of one controller's routes matches a route pattern of another controller.
     */
    public function testNoRouteOfOneControllerMatchesAPathOfAnother(): void {
        $GLOBALS['lga_test_routes'] = [];
        Container::plugin()->boot();
        do_action( 'rest_api_init' );
        $byPath = [];
        foreach ( \LinkGrowth\Autopilot\Tests\Support\Wiring::restRoutes( $GLOBALS['lga_test_routes'], RestApi::NAMESPACE ) as $route ) {
            [ , $path ]          = explode( ' ', $route['route'], 2 );
            $byPath[ $path ][]   = (string) strstr( $route['callback'], '::', true );
        }
        $routes = [];
        foreach ( $byPath as $path => $controllers ) {
            $this->assertCount( 1, array_unique( $controllers ), $path . ' belongs to one controller' );
            $routes[ $path ] = $controllers[0];
        }
        $this->assertCount( count( ( new RestApi( Container::plugin()->container() ) )->controllers() ), array_unique( $routes ), 'every controller registered routes' );
        // A path a route answers: each named group replaced by a value it accepts.
        $sample = static fn( string $pattern ): string => (string) preg_replace_callback(
            '/\(\?P<[a-z_]+>([^()]*)\)/',
            static fn( array $m ): string => match ( true ) {
                str_contains( $m[1], '\d' )      => '7',
                str_contains( $m[1], 'sql' )     => 'backup-1.sql.gz',
                default                          => explode( '|', $m[1] )[0],
            },
            $pattern
        );
        $checked = 0;
        foreach ( $routes as $pattern => $controller ) {
            $path = $sample( $pattern );
            $this->assertSame( 1, preg_match( '@^' . $pattern . '$@i', $path ), 'the sample path ' . $path . ' matches its own route' );
            foreach ( $routes as $other => $otherController ) {
                if ( $otherController === $controller ) {
                    continue;
                }
                $this->assertSame( 0, preg_match( '@^' . $other . '$@i', $path ), $path . ' (' . $controller . ') would also match ' . $other . ' (' . $otherController . ')' );
                $checked++;
            }
        }
        $this->assertGreaterThan( 1000, $checked );
    }

    public function testTheAdminPagesAndSettingsTabsAreTheUnionOfTheModulesDeclarations(): void {
        foreach ( [ 'adminPages' => Menu::pageDefinitions(), 'settingsTabs' => Menu::settingsTabs() ] as $method => $collected ) {
            $union = [];
            foreach ( self::declared( $method ) as $declared ) {
                foreach ( $declared as $key => $def ) {
                    $this->assertArrayNotHasKey( $key, $union, $method . ': ' . $key . ' is declared by one module only' );
                    $union[ $key ] = $def;
                }
            }
            uasort( $union, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );
            $this->assertSame( $union, $collected, $method );
        }
        $this->assertSame( Wp2Modules::PAGES, self::sortedKeys( self::wp2( 'adminPages' ) ), 'the WP2 modules declare the engine\'s pages' );
        $this->assertSame( Wp2Modules::PAGES, Wp2Modules::only( array_keys( Menu::pages() ), Wp2Modules::PAGES ), 'the navigation order of the engine' );
        $this->assertSame( self::WP2_TABS, self::sortedKeys( self::wp2( 'settingsTabs' ) ), 'the WP2 modules declare the Settings tabs of the engine' );
        $this->assertSame( self::WP2_TABS, Wp2Modules::only( array_keys( Menu::settingsTabs() ), self::WP2_TABS ), 'the tab order of the Settings page' );
    }

    public function testTheHealthChecksAndCliCommandsAreTheUnionOfTheModulesDeclarations(): void {
        $checks   = array_merge( ...array_values( self::declared( 'healthChecks' ) ) );
        $commands = array_merge( ...array_values( self::declared( 'cliCommands' ) ) );
        $this->assertSame( $checks, Container::get( HealthMonitor::class )->checks() );
        $this->assertSame( [ OpcacheCheck::class, CoexistenceCheck::class ], self::wp2( 'healthChecks' ), 'the WP2 modules: the two checks that exist today (Site Health OPcache and environment)' );
        $this->assertSame( $commands, Container::get( CommandRegistry::class )->commands() );
        $this->assertSame( [], self::wp2( 'cliCommands' ), 'the WP2 modules declare no WP-CLI command' );
    }

    public function testTheHealthMonitorRunsEveryCheckInTheMonitorShape(): void {
        Container::plugin()->container()->set( Opcache::class, new Opcache( static fn() => false ) ); // OPcache status unreadable
        $results = Container::get( HealthMonitor::class )->run();
        $this->assertCount( count( Container::get( HealthMonitor::class )->checks() ), $results, 'one result per check' );
        $this->assertSame( [ 'opcache', 'coexistence' ], Wp2Modules::only( array_keys( $results ), [ 'opcache', 'coexistence' ] ) );
        foreach ( $results as $id => $result ) {
            $this->assertSame( [ 'id', 'status', 'label', 'message', 'evidence', 'action' ], array_keys( $result ), $id );
            $this->assertSame( $id, $result['id'] );
        }
        foreach ( [ 'opcache', 'coexistence' ] as $id ) {
            $this->assertSame( Check::OK, $results[ $id ]['status'], $id . ': nothing to report on the unit site' );
            $this->assertSame( [ 'label' => '', 'url' => '' ], $results[ $id ]['action'] );
        }
        $this->assertSame( "PHP's code cache (OPcache) has nothing to report", $results['opcache']['label'] );
        $this->assertSame( [ 'site_health_test' => 'lga_environment', 'conflicts' => [] ], $results['coexistence']['evidence'] );
    }

    // ------------------------------------------------------------------
    // A module added through lga_modules
    // ------------------------------------------------------------------

    public function testAModuleAddedThroughTheFilterIsCollectedByEveryCollector(): void {
        $plugin = self::withProbe();
        $this->assertSame( 'probe', $plugin->modules()[ count( Modules::LIST ) ]->id() );

        // Plugin: services and boot.
        $this->assertTrue( $plugin->container()->has( ModulesTestProbeService::class ) );
        $this->assertInstanceOf( ModulesTestProbeService::class, $plugin->get( ModulesTestProbeService::class ) );
        $GLOBALS['lga_test_actions'] = $GLOBALS['lga_test_filters'] = [];
        Container::reset();
        add_filter( Modules::FILTER, static fn( array $list ): array => array_merge( $list, [ ModulesTestProbeModule::class ] ) );
        $plugin = Container::plugin();
        $plugin->boot();
        $this->assertCount( 1, $GLOBALS['lga_test_actions']['lga_probe_event'] ?? [], 'the probe hooked its event' );
        $this->assertNotEmpty( $GLOBALS['lga_test_actions']['lga_tick'] ?? [], 'the built-in modules still boot' );
        do_action( 'lga_probe_event' );
        $this->assertSame( 1, $plugin->get( ModulesTestProbeService::class )->calls, 'its lazy hook resolved its service' );

        // Database\Schema.
        $this->assertContains( 'probe_items', Schema::names() );
        $this->assertContains( Schema::table( 'probe_items' ), ( new Schema() )->tableNames() );
        $this->assertStringContainsString( 'CREATE TABLE ' . Schema::table( 'probe_items' ), implode( "\n", ( new Schema() )->statements() ) );

        // Support\Settings.
        $this->assertSame( [ 'enabled' => true, 'limit' => 3 ], Settings::defaults()['probe'] ?? null );
        $this->assertSame( 3, $plugin->get( Settings::class )->get( 'probe.limit' ) );

        // Support\SettingsSchema.
        $this->assertSame( SettingsSchema::int( 1, 5 ), $plugin->get( SettingsSchema::class )->rule( 'probe.limit' ) );
        $this->assertSame( [ 'probe' => [ 'limit' => 5 ] ], $plugin->get( SettingsSchema::class )->sanitize( [ 'probe' => [ 'limit' => 50 ] ] ) );

        // JobRunner::types().
        $this->assertSame( ModulesTestProbeJob::class, $plugin->get( JobRunner::class )->types()['probe_job'] ?? null );

        // Automation\TaskRegistry: the probe's task, its due-check (ModulesTestProbe\DueChecks is not in the probe's
        // namespace, so it is named as Class::method) and the gate.
        $tasks = $plugin->get( TaskRegistry::class )->tasks();
        $probe = array_values( array_filter( $tasks, static fn( TaskDefinition $t ): bool => 'probe_task' === $t->id ) );
        $this->assertCount( 1, $probe );
        $this->assertSame( 'probe_job', $probe[0]->jobType );
        $this->assertSame( 'probe_task', end( $tasks )->id, 'after the built-in tasks' );
        $this->assertSame( '', $plugin->get( TaskRegistry::class )->gate( $probe[0], Context::scheduler() ) );
        ModulesTestProbeChecks::$due = false;
        $this->assertSame( TaskRegistry::NO_WORK, $plugin->get( TaskRegistry::class )->gate( $probe[0], Context::scheduler() ) );
        ModulesTestProbeChecks::$due = true;

        // Admin\Rest\RestApi.
        $this->assertContains( ModulesTestProbeController::class, ( new RestApi( $plugin->container() ) )->controllers() );
        $GLOBALS['lga_test_routes'] = [];
        ( new RestApi( $plugin->container() ) )->register();
        $this->assertArrayHasKey( 'lga/v1/probe', $GLOBALS['lga_test_routes'] );
        $this->assertArrayHasKey( 'lga/v1/overview', $GLOBALS['lga_test_routes'] );

        // Admin\Menu: the page sorts in by its order, gets a submenu with its capability, and its slug is recognised.
        $this->assertSame( [ 'overview', 'prospects', 'submissions', 'outreach', 'backlinks', 'probe', 'seo', 'automation', 'settings', 'logs', 'probe_secret' ], Wp2Modules::only( array_keys( Menu::pages() ), array_merge( Wp2Modules::PAGES, [ 'probe', 'probe_secret' ] ) ), 'sorted in by their order among the WP2 pages' );
        $this->assertSame( [ 'label' => 'Probe', 'icon' => 'zap', 'module' => 'pages/probe.js', 'cap' => 'lga_manage', 'order' => 55 ], Menu::pageDefinitions()['probe'] );
        $this->assertSame( 'probe', Menu::pageFromSlug( 'lga-probe' ) );
        $GLOBALS['lga_test_caps'] = [ 'lga_manage' => true ];
        $GLOBALS['lga_test_menu'] = [];
        $plugin->get( Menu::class )->addMenu();
        $slugs = array_map( static fn( array $e ): string => (string) ( 'menu' === $e[0] ? $e[4] : $e[5] ), $GLOBALS['lga_test_menu'] );
        $this->assertContains( 'lga-probe', $slugs );
        $secret = array_values( array_filter( $GLOBALS['lga_test_menu'], static fn( array $e ): bool => 'submenu' === $e[0] && 'lga-probe_secret' === $e[5] ) );
        $this->assertSame( 'lga_probe_secret', $secret[0][4] ?? null, 'a page is registered with its own capability' );
        $this->assertArrayHasKey( 'probe', $plugin->get( Menu::class )->config( 'overview' )['pages'] );
        $this->assertArrayNotHasKey( 'probe_secret', $plugin->get( Menu::class )->config( 'overview' )['pages'], 'the SPA gets only the pages the user may open' );
        $GLOBALS['lga_test_caps']['lga_probe_secret'] = true;
        $this->assertArrayHasKey( 'probe_secret', $plugin->get( Menu::class )->config( 'overview' )['pages'] );
        $this->assertSame( [ 'label' => 'Probe settings', 'module' => 'settings/probe.js', 'order' => 65 ], Menu::settingsTabs()['probe'] ?? null );
        $this->assertSame( [ 'business', 'automation', 'discovery', 'integrations', 'outreach', 'content', 'probe', 'monitoring', 'advanced' ], Wp2Modules::only( array_keys( Menu::settingsTabs() ), array_merge( self::WP2_TABS, [ 'probe' ] ) ), 'the probe tab sorts in after content (60)' );
        $this->assertArrayHasKey( 'probe', $plugin->get( Menu::class )->config( 'overview' )['settingsTabs'] );

        // Health\HealthMonitor.
        $this->assertContains( ModulesTestProbeCheck::class, $plugin->get( HealthMonitor::class )->checks() );
        $plugin->container()->set( Opcache::class, new Opcache( static fn() => false ) );
        $results = $plugin->get( HealthMonitor::class )->run();
        $this->assertSame( [ 'id' => 'probe', 'status' => Check::WARNING, 'label' => 'Probe check', 'message' => 'Probe says hello.', 'evidence' => [ 'n' => 1 ], 'action' => [ 'label' => '', 'url' => '' ] ], $results['probe'] ?? null );

        // Cli\CommandRegistry.
        $commands = $plugin->get( CommandRegistry::class )->commands();
        $this->assertSame( ModulesTestProbeCommand::class, $commands['probe'] ?? null );
        $added = [];
        $done  = $plugin->get( CommandRegistry::class )->register( static function ( string $name, string $class ) use ( &$added ): void {
            $added[ $name ] = $class;
        } );
        $this->assertSame( array_combine( array_map( static fn( string $name ): string => 'linkgrowth ' . $name, array_keys( $commands ) ), array_values( $commands ) ), $added, 'each command under the root command' );
        $this->assertSame( ModulesTestProbeCommand::class, $added['linkgrowth probe'] ?? null );
        $this->assertSame( $added, $done );
    }

    public function testAModuleWhoseBootThrowsLeavesOnlyItsOwnHooksOut(): void {
        ModulesTestProbeModule::$throwOnBoot = true;
        $GLOBALS['lga_test_actions'] = $GLOBALS['lga_test_filters'] = [];
        $plugin = self::withProbe();
        $plugin->boot();
        $this->assertSame( [ 'probe' => 'probe boot failed' ], $plugin->bootErrors() );
        $this->assertArrayNotHasKey( 'lga_probe_event', $GLOBALS['lga_test_actions'] );
        $this->assertNotEmpty( $GLOBALS['lga_test_actions']['lga_tick'] ?? [] );
    }

    public function testAHealthCheckThatThrowsIsReportedAsCriticalWithoutStoppingTheOthers(): void {
        $plugin = self::withProbe();
        $plugin->container()->set( Opcache::class, new Opcache( static fn() => false ) );
        $plugin->container()->singleton( ModulesTestProbeCheck::class, static fn() => throw new \RuntimeException( 'probe check unavailable' ) );
        $results = $plugin->get( HealthMonitor::class )->run();
        $this->assertSame( [ 'opcache', 'coexistence', ModulesTestProbeCheck::class ], Wp2Modules::only( array_keys( $results ), [ 'opcache', 'coexistence', ModulesTestProbeCheck::class ] ) );
        $this->assertSame( Check::CRITICAL, $results[ ModulesTestProbeCheck::class ]['status'] );
        $this->assertSame( 'probe check unavailable', $results[ ModulesTestProbeCheck::class ]['message'] );
        $this->assertSame( Check::OK, $results['opcache']['status'] );
    }
}

/** A module from an extension: one of everything the seam collects. */
final class ModulesTestProbeModule extends AbstractModule {

    public static bool $throwOnBoot = false;

    public function id(): string {
        return 'probe';
    }

    public function services( PluginContainer $c ): void {
        $c->singleton( ModulesTestProbeService::class, static fn() => new ModulesTestProbeService() );
    }

    public function boot( Plugin $plugin ): void {
        if ( self::$throwOnBoot ) {
            throw new \RuntimeException( 'probe boot failed' );
        }
        add_action( 'lga_probe_event', $plugin->lazy( ModulesTestProbeService::class, 'handle' ) );
    }

    public function tables(): array {
        return [
            'probe_items' => 'CREATE TABLE ' . Schema::table( 'probe_items' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY  (id)
) " . Schema::charsetCollate() . ';',
        ];
    }

    public function settingsDefaults(): array {
        return [ 'probe' => [ 'enabled' => true, 'limit' => 3 ] ];
    }

    public function settingsRules(): array {
        return [ 'probe.enabled' => SettingsSchema::bool(), 'probe.limit' => SettingsSchema::int( 1, 5 ) ];
    }

    public function jobs(): array {
        return [ 'probe_job' => ModulesTestProbeJob::class ];
    }

    public function tasks(): array {
        return [ new TaskDefinition( id: 'probe_task', jobType: 'probe_job', label: 'Probe task', defaultFrequency: Frequency::CONTINUOUS, module: '', dueCheck: ModulesTestProbeChecks::class . '::due', priority: 5, requiresProfile: false ) ];
    }

    public function restControllers(): array {
        return [ ModulesTestProbeController::class ];
    }

    public function adminPages(): array {
        return [
            'probe'        => [ 'label' => 'Probe', 'icon' => 'zap', 'module' => 'pages/probe.js', 'cap' => 'lga_manage', 'order' => 55 ],
            'probe_secret' => [ 'label' => 'Probe secrets', 'icon' => 'lock', 'module' => 'pages/probe-secret.js', 'cap' => 'lga_probe_secret', 'order' => 95 ],
        ];
    }

    public function settingsTabs(): array {
        return [ 'probe' => [ 'label' => 'Probe settings', 'module' => 'settings/probe.js', 'order' => 65 ] ];
    }

    public function healthChecks(): array {
        return [ ModulesTestProbeCheck::class ];
    }

    public function cliCommands(): array {
        return [ 'probe' => ModulesTestProbeCommand::class ];
    }
}

/** A module whose id is not a lower-case key (left out of the list). */
final class ModulesTestBadIdModule extends AbstractModule {
    public function id(): string {
        return 'Bad-Id';
    }
}

final class ModulesTestProbeService {
    public int $calls = 0;
    public function handle(): void {
        $this->calls++;
    }
}

final class ModulesTestProbeJob extends AbstractJob {
    public static function type(): string {
        return 'probe_job';
    }
    public function handle( array $payload, TimeBudget $budget, PluginContainer $c ): array {
        unset( $payload, $budget, $c );
        return [ 'ok' => true ];
    }
}

final class ModulesTestProbeController extends Controller {
    public function register( string $namespace ): void {
        register_rest_route( $namespace, '/probe', [ 'methods' => 'GET', 'callback' => static fn(): array => [], 'permission_callback' => [ $this, 'permission' ] ] );
    }
}

final class ModulesTestProbeCheck implements Check {
    public function id(): string {
        return 'probe';
    }
    public function run(): array {
        return [ 'id' => 'probe', 'status' => Check::WARNING, 'label' => 'Probe check', 'message' => 'Probe says hello.', 'evidence' => [ 'n' => 1 ], 'action' => [ 'label' => '', 'url' => '' ] ];
    }
}

final class ModulesTestProbeCommand {
    public function __invoke(): void {}
}

final class ModulesTestProbeChecks {
    public static bool $due = true;
    public function __construct( PluginContainer $c ) {
        unset( $c );
    }
    public function due(): bool {
        return self::$due;
    }
}
