<?php
/**
 * Due-checks of the outreach module's tasks (DESIGN.md §5.1, TaskDefinition::$dueCheck): asked by
 * Automation\TaskRegistry::gate() on a tick, after the task's limit pool had room. Moved unchanged from the engine's
 * Pipeline (WP2).
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Outreach;

use LinkGrowth\Autopilot\Container;

final class DueChecks {

    public function __construct( private Container $c ) {}

    /**
     * Messages the send stage may actually send are waiting: only rows of an active, uncapped campaign (scheduled
     * rows only with autosend on), so held-back messages do not queue a send job that does nothing on every tick.
     * Rows left "sending" by a run that died also make it due, because the send stage is what marks them failed.
     */
    public function outreachDue(): bool {
        $engine = $this->c->get( OutreachEngine::class );
        if ( $engine->hasStaleClaims() ) {
            return true;
        }
        // Outside the send window (by default weekdays 9-17, about 24 % of the week) the stage could only skip every
        // message as "outside_send_window": measured on a test copy on a Sunday, a no-op send job on each of 15 ticks
        // (an idle tick: 36 queries and 10 write queries; 22 and 3 without it). The stage is due again on the first
        // tick inside the window; nothing waits longer than before.
        if ( ! $this->c->get( Compliance::class )->withinSendWindow() ) {
            return false;
        }
        return $engine->hasSendable();
    }
}
