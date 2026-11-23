<?php
/**
 * Due-checks and schedules of the monitoring module's tasks (DESIGN.md §5.1, TaskDefinition::$dueCheck and
 * TaskDefinition::$schedule): asked by Automation\TaskRegistry on a tick and for the Automation page. Moved unchanged
 * from the engine's Pipeline (WP2).
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Monitoring;

use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Repositories\Backlinks;
use LinkGrowth\Autopilot\Support\Limits;
use LinkGrowth\Autopilot\Support\Settings;
use Throwable;

final class DueChecks {

    public function __construct( private Container $c ) {}

    /**
     * Backlinks are due for their check (while the backlink_checks limit has room), or the notification digest is
     * due (the monitor stage sends it, whatever the limit).
     */
    public function monitorDue(): bool {
        if ( $this->c->get( Limits::class )->allow( 'backlink_checks' ) && [] !== $this->c->get( Backlinks::class )->dueForCheck( 1 ) ) {
            return true;
        }
        return $this->digestDue();
    }

    /**
     * The backlink import runs every monitoring.import_interval_days days (at least 1; 7 by default).
     *
     * @return array{every:int,cadence:string}
     */
    public function importSchedule(): array {
        $days = max( 1, (int) $this->c->get( Settings::class )->get( 'monitoring.import_interval_days', 7 ) );
        return [ 'every' => $days * DAY_IN_SECONDS, 'cadence' => sprintf( 'every %d days', $days ) ];
    }

    private function digestDue(): bool {
        try {
            if ( ! class_exists( Notifier::class ) ) {
                return false;
            }
            return (bool) $this->c->get( Notifier::class )->digestDue();
        } catch ( Throwable $e ) {
            return false;
        }
    }
}
