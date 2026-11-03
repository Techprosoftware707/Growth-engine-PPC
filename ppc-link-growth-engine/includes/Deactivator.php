<?php
/**
 * Deactivation routine (keeps data; removes schedules and locks).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth;

use PPC\LinkGrowth\Automation\Scheduler;
use PPC\LinkGrowth\Support\Locks;

final class Deactivator {

    public static function deactivate(): void {
        $plugin = Plugin::instance();
        $plugin->get( Scheduler::class )->unschedule();
        $plugin->get( Locks::class )->releaseAll();
    }
}
