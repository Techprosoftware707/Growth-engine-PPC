<?php
/**
 * Job: run the discovery engine (seed catalog, search queries, competitor backlinks).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Discovery\DiscoveryEngine;
use PPC\LinkGrowth\Support\TimeBudget;

final class DiscoverJob extends AbstractJob {

    public static function type(): string {
        return 'discover';
    }

    public static function priority(): int {
        return 6;
    }

    /**
     * @param array<string,mixed> $payload { max_queries?: int, force_seeds?: bool, skip_competitors?: bool }
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        if ( ! $this->flag( $c, $payload, 'discovery' ) ) {
            return $this->skipped( 'discovery_disabled' );
        }
        if ( ! $budget->has( 3.0 ) ) {
            return $this->deferred( $c, $payload );
        }
        $opts = [];
        if ( isset( $payload['max_queries'] ) ) {
            $opts['max_queries'] = max( 1, min( 50, (int) $payload['max_queries'] ) );
        }
        if ( isset( $payload['force_seeds'] ) ) {
            $opts['force_seeds'] = (bool) $payload['force_seeds'];
        }
        if ( isset( $payload['skip_competitors'] ) ) {
            $opts['skip_competitors'] = (bool) $payload['skip_competitors'];
        }

        $stats = $c->get( DiscoveryEngine::class )->run( $budget, $opts );

        $this->audit( $c )->record(
            'discovery.ran',
            'discovery',
            0,
            [
                'queries_run'   => (int) ( $stats['queries_run'] ?? 0 ),
                'results'       => (int) ( $stats['results'] ?? 0 ),
                'new_prospects' => (int) ( $stats['new_prospects'] ?? 0 ),
                'warnings'      => array_slice( (array) ( $stats['warnings'] ?? [] ), 0, 5 ),
                'manual'        => $this->isManual( $payload ),
            ]
        );
        return $this->result( $stats );
    }
}
