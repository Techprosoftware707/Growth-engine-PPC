<?php
/**
 * Base test case: resets options, transients, HTTP fixtures and the fake database between tests.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;
use PPC\LinkGrowth\Database\Schema;

abstract class TestCase extends BaseTestCase {

    protected function setUp(): void {
        parent::setUp();
        // The running version is already recorded, so the 10-minute quiet window after an update or activation
        // (Automation\LoadGuard) does not pause the ticks of every test; LoadGuard tests set it themselves.
        $GLOBALS['ppclg_test_options']    = [ 'ppclg_running_version' => [ 'version' => PPCLG_VERSION, 'since' => 0, 'from' => '' ] ];
        $GLOBALS['ppclg_test_transients'] = [];
        $GLOBALS['ppclg_test_as_async']   = [];
        $GLOBALS['ppclg_test_cron_single'] = [];
        $GLOBALS['ppclg_test_mail']       = [];
        $GLOBALS['ppclg_test_actions']    = [];
        $GLOBALS['ppclg_test_filters']    = [];
        $GLOBALS['ppclg_test_user_id']    = 0;
        HttpFixtures::reset();
        $GLOBALS['wpdb'] = new FakeWpdb();
        foreach ( ( new Schema() )->statements() as $sql ) {
            $GLOBALS['wpdb']->createFromMysql( $sql );
        }
        \PPC\LinkGrowth\Tests\Support\Container::reset();
    }

    protected function fixture( string $relative ): string {
        return (string) file_get_contents( __DIR__ . '/../fixtures/' . ltrim( $relative, '/' ) );
    }

    protected function loadFixtureSites(): void {
        HttpFixtures::loadSites( __DIR__ . '/../fixtures/sites' );
    }
}
