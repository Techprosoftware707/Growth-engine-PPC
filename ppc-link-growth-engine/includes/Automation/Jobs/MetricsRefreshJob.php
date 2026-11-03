<?php
/**
 * Job: refresh provider metrics for approved/acquired prospects whose data is older than 30 days.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Integrations\MetricsAggregator;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Support\TimeBudget;
use Throwable;

final class MetricsRefreshJob extends AbstractJob {

    private const MAX_PROSPECTS = 10;
    private const STALE_DAYS    = 30;
    private const SCAN_WINDOW   = 100;

    public static function type(): string {
        return 'metrics_refresh';
    }

    public static function priority(): int {
        return 7;
    }

    /**
     * @param array<string,mixed> $payload { max?: int }
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        $limits     = $this->limits( $c );
        $aggregator = $c->get( MetricsAggregator::class );
        $prospects  = $c->get( Prospects::class );
        $stats      = [ 'candidates' => 0, 'refreshed' => 0, 'lookups' => 0, 'deferred' => 0, 'limited' => 0, 'errors' => 0, 'items' => [] ];

        if ( ! $aggregator->hasAnyProvider() ) {
            return $this->skipped( 'no_metrics_provider' );
        }
        if ( ! $limits->allow( 'provider_lookups' ) ) {
            return $this->skipped( 'provider_lookup_limit_reached', [ 'limits' => $limits->snapshot()['provider_lookups'] ?? null ] );
        }

        $max    = isset( $payload['max'] ) ? max( 1, min( 50, (int) $payload['max'] ) ) : self::MAX_PROSPECTS;
        $cutoff = time() - self::STALE_DAYS * DAY_IN_SECONDS;
        $rows   = $prospects->findBy( [ 'status' => [ Prospects::STATUS_ACQUIRED, Prospects::STATUS_APPROVED ] ], 'score', 'DESC', self::SCAN_WINDOW );
        $stale  = [];
        foreach ( $rows as $row ) {
            $metrics   = is_array( $row['metrics'] ?? null ) ? $row['metrics'] : [];
            $fetchedAt = isset( $metrics['fetched_at'] ) ? (int) strtotime( (string) $metrics['fetched_at'] ) : 0;
            if ( $fetchedAt <= 0 || $fetchedAt < $cutoff ) {
                $stale[] = $row;
            }
            if ( count( $stale ) >= $max ) {
                break;
            }
        }
        $stats['candidates'] = count( $stale );

        foreach ( $stale as $row ) {
            if ( ! $budget->has( 4.0 ) ) {
                $stats['deferred']++;
                continue;
            }
            if ( ! $limits->allow( 'provider_lookups' ) ) {
                $stats['limited']++;
                continue;
            }
            $id     = (int) $row['id'];
            $domain = (string) $row['domain'];
            try {
                $lookups = 0;
                $metrics = $aggregator->metricsFor(
                    $domain,
                    true,
                    static function () use ( $limits, &$lookups ): bool {
                        if ( ! $limits->allow( 'provider_lookups', $lookups + 1 ) ) {
                            return false;
                        }
                        $lookups++;
                        return true;
                    }
                );
                if ( $lookups > 0 ) {
                    $limits->spend( 'provider_lookups', $lookups );
                }
                $prospects->update( $id, [ 'metrics' => $metrics, 'updated_at' => $this->now() ] );
                $stats['refreshed']++;
                $stats['lookups'] += $lookups;
                $stats['items'][]  = [ 'prospect_id' => $id, 'domain' => $domain, 'authority' => $metrics['authority'] ?? null, 'lookups' => $lookups ];
            } catch ( Throwable $e ) {
                $stats['errors']++;
                $this->logger( $c )->error( 'metrics', 'Metrics refresh failed', array_merge( [ 'prospect_id' => $id, 'domain' => $domain ], $this->errorContext( $e ) ) );
            }
        }

        if ( 0 === $stats['refreshed'] && 0 === $stats['errors'] && $stats['deferred'] > 0 ) {
            return $this->deferred( $c, $payload, 'time_budget', $stats );
        }
        if ( $stats['refreshed'] > 0 ) {
            $this->audit( $c )->record( 'prospects.metrics_refreshed', 'prospect', 0, [ 'refreshed' => $stats['refreshed'], 'lookups' => $stats['lookups'], 'errors' => $stats['errors'] ] );
        }
        return $this->result( $stats );
    }
}
