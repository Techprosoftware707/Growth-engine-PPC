<?php
/**
 * Job: send approved outreach messages that are due (compliance, send window and limits enforced by the engine).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Outreach\OutreachEngine;
use PPC\LinkGrowth\Support\TimeBudget;

final class OutreachSendJob extends AbstractJob {

    private const DEFAULT_MAX = 10;

    public static function type(): string {
        return 'outreach_send';
    }

    public static function priority(): int {
        return 3;
    }

    /**
     * @param array<string,mixed> $payload { max?: int }
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        $limits = $this->limits( $c );
        if ( ! $limits->allow( 'outreach_emails' ) ) {
            return $this->skipped( 'outreach_limit_reached', [ 'limits' => $limits->snapshot()['outreach_emails'] ?? null ] );
        }
        if ( ! $budget->has( 4.0 ) ) {
            return $this->deferred( $c, $payload );
        }
        $max   = isset( $payload['max'] ) ? max( 1, min( 50, (int) $payload['max'] ) ) : self::DEFAULT_MAX;
        $stats = $c->get( OutreachEngine::class )->sendDue( $budget, $max );

        return $this->result( array_merge( [ 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'reasons' => [] ], $stats ) );
    }
}
