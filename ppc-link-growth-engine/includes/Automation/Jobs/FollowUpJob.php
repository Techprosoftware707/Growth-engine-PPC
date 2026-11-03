<?php
/**
 * Job: draft follow-up messages for outreach that received no reply.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Outreach\FollowUps;
use PPC\LinkGrowth\Support\TimeBudget;

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
