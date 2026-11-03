<?php
/**
 * Job: verify submitted listings went live (and record the resulting backlinks).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Submission\SubmissionEngine;
use PPC\LinkGrowth\Support\TimeBudget;

final class VerifyListingsJob extends AbstractJob {

    private const DEFAULT_MAX = 10;

    public static function type(): string {
        return 'verify_listings';
    }

    public static function priority(): int {
        return 6;
    }

    /**
     * @param array<string,mixed> $payload { max?: int }
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        if ( ! $budget->has( 6.0 ) ) {
            return $this->deferred( $c, $payload );
        }
        $max   = isset( $payload['max'] ) ? max( 1, min( 50, (int) $payload['max'] ) ) : self::DEFAULT_MAX;
        $stats = $c->get( SubmissionEngine::class )->verifyDue( $budget, $max );

        return $this->result( array_merge( [ 'checked' => 0, 'confirmed' => 0 ], $stats ) );
    }
}
