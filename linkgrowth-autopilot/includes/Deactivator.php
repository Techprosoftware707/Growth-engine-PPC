<?php
/**
 * Deactivation routine (keeps data; removes schedules and locks).
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot;

use LinkGrowth\Autopilot\Automation\Scheduler;
use LinkGrowth\Autopilot\Support\Locks;

final class Deactivator {

    public static function deactivate(): void {
        $plugin = Plugin::instance();
        $plugin->get( Scheduler::class )->unschedule();
        $plugin->get( Locks::class )->releaseAll();
    }
}
