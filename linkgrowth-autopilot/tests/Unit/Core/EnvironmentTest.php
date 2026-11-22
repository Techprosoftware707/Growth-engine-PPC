<?php
/**
 * Core\Environment (DESIGN.md §1.4): generic detection of another plugin doing the same work on the site. The
 * shipped plugin names no other product; the owner lists one in `lga_known_conflicts`, and the hook detector finds
 * any other code on this plugin's cron hook. Each finding is a warning (notice and Site Health), nothing is blocked.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Core;

use LinkGrowth\Autopilot\Admin\Notices;
use LinkGrowth\Autopilot\Automation\Scheduler;
use LinkGrowth\Autopilot\Core\Environment;
use LinkGrowth\Autopilot\Tests\Support\Container;
use LinkGrowth\Autopilot\Tests\Support\TestCase;

final class EnvironmentTest extends TestCase {

    private const OTHER = 'other-backlinks/other-backlinks.php';

    protected function tearDown(): void {
        unset( $GLOBALS['lga_test_active_plugins'], $GLOBALS['wp_filter'][ Scheduler::HOOK ] );
        parent::tearDown();
    }

    public function testNothingIsReportedByDefault(): void {
        $env = new Environment();
        $this->assertSame( [], $env->conflicts() );
        $this->assertSame( [], $env->knownConflicts(), 'the known-conflicts list is empty by default' );
        $result = $env->siteHealthTest();
        $this->assertSame( 'good', $result['status'] );
        $this->assertSame( Environment::TEST, $result['test'] );
    }

    public function testAnActivePluginTheOwnerListsIsAWarningWithTheDesignWording(): void {
        add_filter( Environment::FILTER_KNOWN, static fn( array $list ): array => $list + [ self::OTHER => 'Other Backlinks', 'inactive/inactive.php' => 'Inactive Plugin', LGA_BASENAME => 'Itself' ] );
        $env = new Environment();
        $this->assertSame( [], $env->conflicts(), 'a listed plugin that is not active is not a conflict' );

        $GLOBALS['lga_test_active_plugins'] = [ self::OTHER, LGA_BASENAME ];
        $this->assertSame( [ self::OTHER => 'Other Backlinks' ], $env->knownConflicts(), 'only the active one, never this plugin itself' );
        $conflicts = $env->conflicts();
        $this->assertCount( 1, $conflicts );
        $this->assertSame( 'plugin:' . self::OTHER, $conflicts[0]['id'] );
        $this->assertSame( 'Other Backlinks is active on this site. Running both can double outbound crawling and contact attempts to the same sites. Use one per site.', $conflicts[0]['message'] );

        $result = $env->siteHealthTest();
        $this->assertSame( 'recommended', $result['status'], 'a warning, not critical: nothing is blocked' );
        $this->assertStringContainsString( 'Other Backlinks is active on this site.', $result['description'] );

        $notices = ( new Notices( Container::get( \LinkGrowth\Autopilot\Support\Settings::class ), Container::get( \LinkGrowth\Autopilot\Integrations\ProviderRegistry::class ), null, $env ) )->notices();
        $warning = array_values( array_filter( $notices, static fn( array $n ): bool => str_starts_with( (string) $n['id'], 'conflict_' ) ) );
        $this->assertCount( 1, $warning, 'the dashboard shows the same warning' );
        $this->assertSame( 'warning', $warning[0]['level'] );
        $this->assertSame( $conflicts[0]['message'], $warning[0]['message'] );
    }

    public function testAnotherPluginOnTheTickHookIsFoundAndOwnCallbacksAreNot(): void {
        $own = Container::get( Scheduler::class );
        $GLOBALS['wp_filter'][ Scheduler::HOOK ] = [ 10 => [ 'own' => [ 'function' => [ $own, 'status' ], 'accepted_args' => 1 ] ] ];
        $this->assertSame( [], ( new Environment() )->conflicts(), "this plugin's own callback is not a conflict" );

        $GLOBALS['wp_filter'][ Scheduler::HOOK ][10]['other'] = [ 'function' => [ new \ArrayObject(), 'count' ], 'accepted_args' => 1 ];
        $conflicts = ( new Environment() )->conflicts();
        $this->assertSame( [ 'hook:' . Scheduler::HOOK ], array_column( $conflicts, 'id' ) );
        $this->assertStringContainsString( 'Running both can double outbound crawling', $conflicts[0]['message'] );
    }

    public function testTheSiteHealthTestIsRegisteredBesideTheOthers(): void {
        $tests = ( new Environment() )->addTests( [ 'direct' => [ 'lga_opcache' => [ 'label' => 'x' ] ] ] );
        $this->assertArrayHasKey( 'lga_opcache', $tests['direct'] );
        $this->assertArrayHasKey( Environment::TEST, $tests['direct'] );
        $this->assertIsCallable( $tests['direct'][ Environment::TEST ]['test'] );
    }
}
