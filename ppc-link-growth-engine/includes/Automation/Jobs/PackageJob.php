<?php
/**
 * Job: build submission packages for open opportunities above the auto-queue score.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Automation\Autopilot;
use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Submission\SubmissionEngine;
use PPC\LinkGrowth\Support\TimeBudget;

final class PackageJob extends AbstractJob {

    private const DEFAULT_MAX = 10;

    public static function type(): string {
        return 'package';
    }

    public static function priority(): int {
        return 5;
    }

    /**
     * @param array<string,mixed> $payload { max?: int, min_score?: int }
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        if ( ! $this->flag( $c, $payload, 'packaging' ) ) {
            return $this->skipped( 'packaging_disabled' );
        }
        if ( ! $budget->has( 8.0 ) ) {
            return $this->deferred( $c, $payload );
        }
        $settings = $this->settings( $c );
        $minScore = isset( $payload['min_score'] )
            ? max( 0, min( 100, (int) $payload['min_score'] ) )
            : (int) $settings->get( 'automation.min_score_auto_queue', 70 );
        $max      = isset( $payload['max'] ) ? max( 1, min( 50, (int) $payload['max'] ) ) : self::DEFAULT_MAX;

        $engine = $c->get( SubmissionEngine::class );
        $stats  = $engine->packageOpenOpportunities( $budget, $max, $minScore );
        // Under Autopilot, packages that waited for review before it was switched on are queued once they pass.
        if ( $c->get( Autopilot::class )->isOn() && $budget->has( 6.0 ) ) {
            $re                     = $engine->reevaluatePending( $budget );
            $stats['rechecked']     = $re['checked'];
            $stats['queued_review'] = $re['queued'];
        }

        return $this->result(
            array_merge(
                [ 'packaged' => 0, 'skipped' => 0 ],
                $stats,
                [ 'min_score' => $minScore, 'max' => $max ]
            )
        );
    }
}
