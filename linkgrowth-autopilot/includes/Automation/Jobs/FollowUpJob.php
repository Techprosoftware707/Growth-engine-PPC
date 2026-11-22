<?php
/**
 * Job: draft follow-up messages for outreach that received no reply.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Automation\Jobs;

use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Outreach\FollowUps;
use LinkGrowth\Autopilot\Support\TimeBudget;

final class FollowUpJob extends AbstractJob {

    private const DEFAULT_MAX = 20;

    public static function type(): string {
        return 'follow_ups';
    }

    public static function priority(): int {
        return 6;
    }

    /**
     * @param array<string,mixed> $payload { max?: int }
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        if ( ! $budget->has( 3.0 ) ) {
            return $this->deferred( $c, $payload );
        }
        $max   = isset( $payload['max'] ) ? max( 1, min( 100, (int) $payload['max'] ) ) : self::DEFAULT_MAX;
        $stats = $c->get( FollowUps::class )->generateDue( $budget, $max );

        return $this->result( array_merge( [ 'created' => 0 ], $stats ) );
    }
}
