<?php
/**
 * Base test case: resets options, transients, HTTP fixtures and the fake database between tests.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;
use LinkGrowth\Autopilot\Database\Schema;

abstract class TestCase extends BaseTestCase {

    protected function setUp(): void {
        parent::setUp();
        // The running version is already recorded, so the 10-minute quiet window after an update or activation
        // (Automation\LoadGuard) does not pause the ticks of every test; LoadGuard tests set it themselves.
        $GLOBALS['lga_test_options']    = [ 'lga_running_version' => [ 'version' => LGA_VERSION, 'since' => 0, 'from' => '' ] ];
        $GLOBALS['lga_test_transients'] = [];
        $GLOBALS['lga_test_option_writes'] = []; // update_option() calls per option name
        $GLOBALS['lga_test_primed']        = []; // _prime_post_caches() batches
        $GLOBALS['lga_test_did']           = []; // did_action() answers (hook => count)
        $GLOBALS['lga_test_is_admin']      = false; // is_admin()
        $GLOBALS['lga_test_doing_ajax']    = false; // wp_doing_ajax()
        $GLOBALS['lga_test_as_async']   = [];
        $GLOBALS['lga_test_cron_single'] = [];
        $GLOBALS['lga_test_mail']       = [];
        $GLOBALS['lga_test_actions']    = [];
        $GLOBALS['lga_test_filters']    = [];
        $GLOBALS['lga_test_user_id']    = 0;
        HttpFixtures::reset();
        $GLOBALS['wpdb'] = new FakeWpdb();
        foreach ( ( new Schema() )->statements() as $sql ) {
            $GLOBALS['wpdb']->createFromMysql( $sql );
        }
        \LinkGrowth\Autopilot\Tests\Support\Container::reset();
        // Created now so that its Support\ServerResources reads nothing (see Container::plugin()): the test machine's
        // own load and memory never pause a test's tick or change the OPcache advice.
        \LinkGrowth\Autopilot\Tests\Support\Container::plugin();
    }

    /** A Support\ServerResources that knows nothing (no load, no CPU count, no memory), as on a server without /proc. */
    protected static function quietServer(): \LinkGrowth\Autopilot\Support\ServerResources {
        return \LinkGrowth\Autopilot\Tests\Support\Container::quietServer();
    }

    protected function fixture( string $relative ): string {
        return (string) file_get_contents( __DIR__ . '/../fixtures/' . ltrim( $relative, '/' ) );
    }

    protected function loadFixtureSites(): void {
        HttpFixtures::loadSites( __DIR__ . '/../fixtures/sites' );
    }
}
