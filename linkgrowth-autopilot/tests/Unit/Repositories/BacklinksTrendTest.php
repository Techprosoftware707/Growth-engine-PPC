<?php
/**
 * Backlinks::weeklyTrend() with rows whose week cannot be computed (WP12, PHP 8.5).
 *
 * DATE_FORMAT() returns NULL for such a row (the SQLite translation of the LGA test site does for every row). The
 * trend used that NULL as an array key, which PHP 8.5 deprecates ("Using null as an array offset"); the integration
 * suite on PHP 8.5 logged it from includes/Repositories/Backlinks.php. Under failOnDeprecation this test fails on
 * PHP 8.5 without the fix; on every PHP the row is left out of the trend and the dated row is still counted.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Repositories;

use LinkGrowth\Autopilot\Repositories\Backlinks;
use LinkGrowth\Autopilot\Tests\Support\Container;
use LinkGrowth\Autopilot\Tests\Support\TestCase;

final class BacklinksTrendTest extends TestCase {

    public function testRowsWithoutAComputableWeekAreLeftOutOfTheTrend(): void {
        $b = Container::get( Backlinks::class );
        [ $dated ] = $b->upsert( 'https://labdirectory.test/listing/northwind-supply-co/', 'https://northwind.test/', [ 'status' => Backlinks::STATUS_ACTIVE ] );
        [ $undated ] = $b->upsert( 'https://blog.example.test/packaging-guide/', 'https://northwind.test/', [ 'status' => Backlinks::STATUS_LOST ] );
        // No week can be computed for these values (FakeWpdb's DATE_FORMAT returns NULL, as MySQL does for a bad date).
        $this->assertTrue( $b->update( $undated, [ 'first_seen' => 'unknown', 'lost_at' => 'unknown' ] ) );
        $this->assertGreaterThan( 0, $dated );

        $trend = $b->weeklyTrend( 4 );

        $this->assertCount( 4, $trend );
        $this->assertSame( 1, array_sum( array_column( $trend, 'new' ) ), 'the dated row is counted, the undated one is not' );
        $this->assertSame( 0, array_sum( array_column( $trend, 'lost' ) ) );
        $this->assertSame( [ 'week', 'new', 'lost' ], array_keys( $trend[0] ) );
    }
}
