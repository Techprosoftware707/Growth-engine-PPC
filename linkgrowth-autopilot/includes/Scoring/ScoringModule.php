<?php
/**
 * Module `scoring` (DESIGN.md §3.2 Scoring (and analysis) row): the scoring engine and the evaluation job that
 * profiles and scores prospects.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Scoring;

use LinkGrowth\Autopilot\Automation\Frequency;
use LinkGrowth\Autopilot\Automation\Jobs\EvaluateJob;
use LinkGrowth\Autopilot\Automation\TaskDefinition;
use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Core\AbstractModule;
use LinkGrowth\Autopilot\Support\Settings;

final class ScoringModule extends AbstractModule {

    public const ID = 'scoring';

    public function id(): string {
        return self::ID;
    }

    public function services( Container $c ): void {
        // The due-checks of this module's tasks (Automation\TaskRegistry resolves them by name).
        $c->singleton( DueChecks::class, static fn( Container $c ) => new DueChecks( $c ) );
        $c->singleton( ScoringEngine::class, static fn( Container $c ) => new ScoringEngine( $c->get( Settings::class ) ) );
    }

    public function jobs(): array {
        return [ 'evaluate' => EvaluateJob::class ];
    }

    /**
     * The scoring task, with the engine's cadences (DESIGN.md §5.1; Automation\TaskDefinition explains the WP2 values).
     *
     * @return list<TaskDefinition>
     */
    public function tasks(): array {
        return [
            new TaskDefinition( id: 'evaluate', jobType: 'evaluate', label: __( 'Evaluate prospects', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::CONTINUOUS, module: 'evaluation', dueCheck: 'evaluateDue', priority: EvaluateJob::priority(), budgetPool: 'evaluations', outbound: true, cadence: 'every tick (when prospects are due)', order: 30 ),
        ];
    }
}
