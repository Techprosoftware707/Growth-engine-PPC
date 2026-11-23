<?php
/**
 * The 13 modules WP2 built (DESIGN.md §13.2 WP2), for the tests that pin what those modules declare.
 *
 * Later packages add their module with one line in Core\Modules::LIST and otherwise touch only their own files
 * (DESIGN.md §3.1, §13.1). So that such a line breaks no shared test:
 *  - a test that pins the built-in declarations (ids, counts, tables, sections, rules, tasks, pages, tabs, checks)
 *    pins the WP2 modules' declarations (declared()) and checks that the collectors return them, among any others;
 *  - the golden and snapshot tests, which record the whole plugin (tests/fixtures/golden/scheduler.json, wiring.json,
 *    wiring-wordpress.json), run with the plugin limited to these modules through the `lga_modules` filter
 *    (limit() in the unit tests, filter() in the request processes of tests/integration/wiring-snapshot.php).
 * A package that changes a WP2 module still changes what these tests see, as it should.
 *
 * This file loads without the plugin (the integration snapshot adds the filter before WordPress loads): class names
 * are written as strings and only limit() and declared() use the plugin.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Support;

final class Wp2Modules {

    /** Core\Modules::FILTER. */
    public const FILTER = 'lga_modules';

    /** The WP2 modules, in Core\Modules::LIST order. */
    public const LIST = [
        'LinkGrowth\\Autopilot\\Core\\CoreModule',
        'LinkGrowth\\Autopilot\\Discovery\\DiscoveryModule',
        'LinkGrowth\\Autopilot\\Analysis\\AnalysisModule',
        'LinkGrowth\\Autopilot\\Scoring\\ScoringModule',
        'LinkGrowth\\Autopilot\\Submission\\SubmissionModule',
        'LinkGrowth\\Autopilot\\Outreach\\OutreachModule',
        'LinkGrowth\\Autopilot\\Monitoring\\MonitoringModule',
        'LinkGrowth\\Autopilot\\Seo\\SeoModule',
        'LinkGrowth\\Autopilot\\Integrations\\IntegrationsModule',
        'LinkGrowth\\Autopilot\\Automation\\AutomationModule',
        'LinkGrowth\\Autopilot\\Admin\\AdminModule',
        'LinkGrowth\\Autopilot\\Content\\ContentModule',
        'LinkGrowth\\Autopilot\\Compliance\\ComplianceModule',
    ];

    /** Their ids (DESIGN.md §13.2 WP2), in the same order. */
    public const IDS = [ 'core', 'discovery', 'analysis', 'scoring', 'submission', 'outreach', 'monitoring', 'seo', 'integrations', 'automation', 'admin', 'content', 'compliance' ];

    /** Their admin pages in navigation order (Menu::PAGE_KEYS before WP2). */
    public const PAGES = [ 'overview', 'prospects', 'submissions', 'outreach', 'backlinks', 'seo', 'automation', 'settings', 'logs' ];

    /**
     * The `lga_modules` callback: the list without the modules that are not WP2's, in the list's order.
     *
     * @return list<mixed>
     */
    public static function filter( mixed $list ): array {
        $keep = static fn( mixed $entry ): bool => in_array( is_object( $entry ) ? get_class( $entry ) : $entry, self::LIST, true );
        return array_values( array_filter( is_array( $list ) ? $list : [], $keep ) );
    }

    /**
     * Limits this test's plugin to the WP2 modules: adds the filter and starts a fresh plugin instance (the module
     * list is read once per instance). TestCase::setUp() clears the filters before the next test.
     */
    public static function limit(): void {
        add_filter( self::FILTER, [ self::class, 'filter' ] );
        Container::reset();
        Container::plugin();
    }

    /**
     * What the WP2 modules among the plugin's modules declare through one Core\Module method, keyed by module id.
     *
     * @return array<string,array<mixed>>
     */
    public static function declared( string $method ): array {
        $out = [];
        foreach ( Container::plugin()->modules() as $module ) {
            if ( in_array( get_class( $module ), self::LIST, true ) ) {
                $out[ $module->id() ] = (array) $module->{$method}();
            }
        }
        return $out;
    }

    /**
     * The items of $list that are in $subset, in $list's order. Equal to $subset when $list holds every item of
     * $subset in the same relative order, whatever else it holds.
     *
     * @param array<mixed> $list
     * @param array<mixed> $subset
     * @return list<mixed>
     */
    public static function only( array $list, array $subset ): array {
        return array_values( array_filter( $list, static fn( mixed $item ): bool => in_array( $item, $subset, true ) ) );
    }
}
