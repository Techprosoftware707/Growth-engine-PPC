<?php
/**
 * Job: import the bundled verified opportunity database (data/verified-opportunities.json) as prospects with
 * one owner task each. Local database work only (no HTTP, no email); batches of at most 100 items, and the
 * job re-queues itself until the pass is complete.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Discovery\VerifiedOpportunities;
use PPC\LinkGrowth\Support\TimeBudget;
use Throwable;

final class ImportVerifiedJob extends AbstractJob {

    /** Seconds needed to import at least a few items and save the cursor. */
    private const MIN_SECONDS = 3.0;

    public static function type(): string {
        return 'import_verified';
    }

    public static function priority(): int {
        return 4;
    }

    /**
     * @param array<string,mixed> $payload { batch?: int (1–100) }
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        // No "skipped" counter here: AbstractJob::skipped()/deferred() use that key as the "did not run" flag.
        $empty = [ 'imported' => 0, 'existing' => 0, 'updated' => 0, 'processed' => 0, 'owner_tasks_open' => 0, 'more' => false ];
        if ( ! class_exists( VerifiedOpportunities::class ) ) {
            return $this->skipped( 'not_available', $empty + [ 'summary' => 'The verified opportunity database is not available in this installation.' ] );
        }
        if ( ! $budget->has( self::MIN_SECONDS ) ) {
            return $this->deferred( $c, $payload, 'time_budget', array_merge( $empty, [ 'more' => true, 'summary' => 'Not enough time left in this run; the verified opportunity import continues on the next tick.' ] ) );
        }

        try {
            $service = $c->has( VerifiedOpportunities::class ) ? $c->get( VerifiedOpportunities::class ) : VerifiedOpportunities::fromContainer( $c );
        } catch ( Throwable $e ) {
            $this->logger( $c )->error( 'verified', 'Verified opportunity import unavailable: ' . $e->getMessage(), $this->errorContext( $e ) );
            return $this->skipped( 'not_available', $empty + [ 'summary' => 'The verified opportunity database could not be loaded: ' . $e->getMessage() ] );
        }

        $batch = isset( $payload['batch'] ) ? max( 1, min( VerifiedOpportunities::MAX_BATCH, (int) $payload['batch'] ) ) : VerifiedOpportunities::MAX_BATCH;
        $res   = $service->import( $budget, $batch );

        $out = [
            'summary'          => (string) $res['summary'],
            'imported'         => (int) $res['imported'],
            // Not "skipped": that key is the "did not run" flag of AbstractJob::skipped()/deferred() (and GrowthRun).
            'items_skipped'    => (int) $res['skipped'],
            'existing'         => (int) $res['existing'],
            'updated'          => (int) $res['updated'],
            'processed'        => (int) $res['processed'],
            'cursor'           => (int) $res['cursor'],
            'total'            => (int) $res['total'],
            'owner_tasks_open' => (int) $res['owner_tasks_open'],
            'more'             => ! $res['done'],
        ];
        if ( '' !== (string) $res['error'] ) {
            $out['error'] = (string) $res['error'];
            $out['more']  = false;
            return $this->result( array_merge( $out, [ 'ok' => false ] ) );
        }
        if ( ! empty( $res['busy'] ) ) {
            return $this->skipped( 'import_running', $out );
        }
        if ( $out['more'] ) {
            // Local database work only: the next batch may start right away.
            $out['continued'] = null !== $this->continueLater( $c, $payload, 0 );
        }
        return $this->result( $out );
    }
}
