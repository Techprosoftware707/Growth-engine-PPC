<?php
/**
 * Job: run the discovery engine (seed catalog, search queries, competitor backlinks).
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Automation\Jobs;

use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Discovery\CompetitorBacklinkSource;
use LinkGrowth\Autopilot\Discovery\DiscoveryEngine;
use LinkGrowth\Autopilot\Support\TimeBudget;

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
        $summary = self::summaryFor( $stats, $c->get( CompetitorBacklinkSource::class )->autoCompetitors() );
        if ( '' !== $summary ) {
            $stats['summary'] = $summary;
        }
        return $this->result( $stats );
    }

    /**
     * Plain summary when competitors were found automatically or the automatic list was mined ('' otherwise: the
     * growth run then composes one from the counts, as before).
     *
     * @param array<string,mixed> $stats
     * @param string[] $auto
     */
    public static function summaryFor( array $stats, array $auto ): string {
        $detect = is_array( $stats['competitor_detection'] ?? null ) ? $stats['competitor_detection'] : null;
        $comp   = is_array( $stats['competitors'] ?? null ) ? $stats['competitors'] : null;
        if ( null === $detect && ( null === $comp || [] === $auto ) ) {
            return '';
        }
        $parts   = [];
        $queries = (int) ( $stats['queries_run'] ?? 0 );
        if ( $queries > 0 ) {
            $parts[] = sprintf( 'Ran %d search %s.', $queries, 1 === $queries ? 'query' : 'queries' );
        } else {
            foreach ( (array) ( $stats['warnings'] ?? [] ) as $w ) {
                if ( is_string( $w ) && str_starts_with( $w, 'No search provider configured' ) ) {
                    $parts[] = 'No search key is set, so no search queries ran; competitors are the discovery source.';
                    break;
                }
            }
        }
        if ( null !== $detect && '' !== (string) $detect['message'] ) {
            $parts[] = rtrim( (string) $detect['message'] );
        }
        if ( null !== $comp ) {
            $parts[] = sprintf( 'Mined the backlinks of %d %s: %d new %s.', (int) $comp['competitors'], 1 === (int) $comp['competitors'] ? 'competitor' : 'competitors', (int) $comp['created'], 1 === (int) $comp['created'] ? 'prospect' : 'prospects' );
            if ( '' !== (string) ( $comp['warning'] ?? '' ) ) {
                $parts[] = rtrim( (string) $comp['warning'] );
            }
        } elseif ( [] !== $auto ) {
            $parts[] = sprintf( 'Competitor backlinks are mined weekly (%d %s on the list).', count( $auto ), 1 === count( $auto ) ? 'competitor' : 'competitors' );
        }
        $new     = (int) ( $stats['new_prospects'] ?? 0 );
        $parts[] = sprintf( '%d new %s in total.', $new, 1 === $new ? 'prospect' : 'prospects' );
        return implode( ' ', $parts );
    }
}
