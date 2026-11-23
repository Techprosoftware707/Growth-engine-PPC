<?php
/**
 * Due-checks of the submission module's tasks (DESIGN.md §5.1, TaskDefinition::$dueCheck): asked by
 * Automation\TaskRegistry::gate() on a tick, after the task's limit pool had room. Moved unchanged from the engine's
 * Pipeline (WP2).
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Submission;

use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Repositories\Submissions;

final class DueChecks {

    public function __construct( private Container $c ) {}

    /** Approved submissions are waiting in the queue. */
    public function submitDue(): bool {
        return [] !== $this->c->get( Submissions::class )->readyToProcess( 1 );
    }
}
