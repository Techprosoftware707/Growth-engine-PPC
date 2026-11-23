<?php
/**
 * The ordered list of modules (DESIGN.md §3.1). Parallel work packages add exactly one line to LIST.
 *
 * Order matters where a collector keeps it: the REST controllers register, the job types list, the tables are created
 * and the settings sections appear in this order; admin pages and settings tabs sort by their own `order`.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Core;

final class Modules {

    /** Filter: the module list (class names, or Module instances) before it is loaded. */
    public const FILTER = 'lga_modules';

    /** @var list<class-string<Module>> */
    public const LIST = [
        CoreModule::class,
        \LinkGrowth\Autopilot\Discovery\DiscoveryModule::class,
        \LinkGrowth\Autopilot\Analysis\AnalysisModule::class,
        \LinkGrowth\Autopilot\Scoring\ScoringModule::class,
        \LinkGrowth\Autopilot\Submission\SubmissionModule::class,
        \LinkGrowth\Autopilot\Outreach\OutreachModule::class,
        \LinkGrowth\Autopilot\Monitoring\MonitoringModule::class,
        \LinkGrowth\Autopilot\Seo\SeoModule::class,
        \LinkGrowth\Autopilot\Integrations\IntegrationsModule::class,
        \LinkGrowth\Autopilot\Automation\AutomationModule::class,
        \LinkGrowth\Autopilot\Admin\AdminModule::class,
        \LinkGrowth\Autopilot\Content\ContentModule::class,
        \LinkGrowth\Autopilot\Compliance\ComplianceModule::class,
    ];

    /**
     * The modules of this request: LIST through the `lga_modules` filter, each built once, in order. An entry that is
     * neither a Module instance nor the name of an instantiable class implementing Module is left out, as is a module
     * whose id is not a lower-case key or is already taken by an earlier module (the earlier one wins).
     *
     * Plugin::modules() calls this once per request; nothing here reads the database or builds a service.
     *
     * @return list<Module>
     */
    public static function load(): array {
        /**
         * Filters the module list (DESIGN.md §3.1). Add an extension's module class (or instance) to the list; the
         * filter must be added before `plugins_loaded` (priority 5), when the plugin boots its modules.
         *
         * @param list<class-string<Module>|Module> $list
         */
        $list = apply_filters( self::FILTER, self::LIST );
        $out  = [];
        $ids  = [];
        foreach ( is_array( $list ) ? $list : [] as $entry ) {
            $module = self::build( $entry );
            if ( null === $module ) {
                continue;
            }
            try {
                $id = $module->id();
            } catch ( \Throwable ) {
                continue;
            }
            if ( '' === $id || 1 !== preg_match( '/^[a-z0-9_]+$/', $id ) || isset( $ids[ $id ] ) ) {
                continue;
            }
            $ids[ $id ] = true;
            $out[]      = $module;
        }
        return $out;
    }

    private static function build( mixed $entry ): ?Module {
        if ( $entry instanceof Module ) {
            return $entry;
        }
        if ( ! is_string( $entry ) || ! class_exists( $entry ) || ! is_subclass_of( $entry, Module::class ) ) {
            return null;
        }
        try {
            return new $entry();
        } catch ( \Throwable $e ) {
            unset( $e ); // an abstract class or a constructor that needs arguments
            return null;
        }
    }
}
