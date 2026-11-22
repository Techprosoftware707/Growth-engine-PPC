<?php
/**
 * Job: process approved/queued submissions through the authorised adapters.
 *
 * The `automation.submissions` flag is a hard gate: manual runs do not bypass it.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Automation\Jobs;

use LinkGrowth\Autopilot\Automation\Autopilot;
use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Submission\SubmissionEngine;
use LinkGrowth\Autopilot\Support\TimeBudget;

final class SubmitJob extends AbstractJob {

    private const DEFAULT_MAX = 5;

    public static function type(): string {
        return 'submit';
    }

    public static function priority(): int {
        return 3;
    }

    /**
     * @param array<string,mixed> $payload { max?: int }
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        if ( ! ( new Autopilot( $this->settings( $c ) ) )->submissionsEnabled() ) {
            return $this->skipped( 'submissions_disabled', [ 'hint' => 'Enable Automation → Submissions to let the engine submit approved packages.' ] );
        }
        $limits = $this->limits( $c );
        if ( ! $limits->allow( 'submissions' ) ) {
            return $this->skipped( 'submission_limit_reached', [ 'limits' => $limits->snapshot()['submissions'] ?? null ] );
        }
        if ( ! $budget->has( 12.0 ) ) {
            return $this->deferred( $c, $payload );
        }
        $max   = isset( $payload['max'] ) ? max( 1, min( 20, (int) $payload['max'] ) ) : self::DEFAULT_MAX;
        $stats = $c->get( SubmissionEngine::class )->processQueue( $budget, $max );

        return $this->result( array_merge( [ 'processed' => 0, 'submitted' => 0, 'failed' => 0, 'manual' => 0 ], $stats ) );
    }
}
