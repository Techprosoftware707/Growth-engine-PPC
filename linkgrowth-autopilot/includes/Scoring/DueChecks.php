<?php
/**
 * Due-checks of the scoring module's tasks (DESIGN.md §5.1, TaskDefinition::$dueCheck): asked by
 * Automation\TaskRegistry::gate() on a tick, after the task's limit pool had room. Moved unchanged from the engine's
 * Pipeline (WP2).
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Scoring;

use LinkGrowth\Autopilot\Automation\Jobs\EvaluateJob;
use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Repositories\Prospects;

final class DueChecks {

    public function __construct( private Container $c ) {}

    /** Prospects are due for evaluation, or machine-parked ones (unreachable, evaluation error) are due for a retry. */
    public function evaluateDue(): bool {
        $prospects = $this->c->get( Prospects::class );
        return [] !== $prospects->dueForEvaluation( 1 ) || [] !== EvaluateJob::retryDue( $prospects, 1 );
    }
}
