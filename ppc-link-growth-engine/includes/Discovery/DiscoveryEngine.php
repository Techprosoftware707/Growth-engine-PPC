<?php
/**
 * Orchestrates prospect discovery: seeds, search queries, competitor backlinks.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Discovery;

use PPC\LinkGrowth\Integrations\ProviderRegistry;
use PPC\LinkGrowth\Repositories\SearchLog;
use PPC\LinkGrowth\Support\Limits;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;

final class DiscoveryEngine {

    public function __construct(
        private ProviderRegistry $registry,
        private QueryGenerator $queries,
        private SeedCatalog $seeds,
        private ProspectIntake $intake,
        private CompetitorBacklinkSource $competitors,
        private SearchLog $log,
        private Limits $limits,
        private Settings $settings,
        private Logger $logger
    ) {}

    /**
     * @param array{max_queries?:int,force_seeds?:bool,skip_competitors?:bool} $opts
     * @return array<string,mixed>
     */
    public function run( TimeBudget $budget, array $opts = [] ): array {
        $stats = [ 'seeds' => null, 'queries_run' => 0, 'results' => 0, 'new_prospects' => 0, 'skipped' => 0, 'competitors' => null, 'warnings' => [] ];

        // 1. Seeds (once).
        if ( $this->settings->get( 'discovery.seed_catalog', true ) && ( ! empty( $opts['force_seeds'] ) || '' === $this->seeds->importedAt() ) ) {
            $stats['seeds'] = $this->seeds->import( $this->intake );
            $stats['new_prospects'] += $stats['seeds']['created'];
        }

        // 2. Search queries.
        $providers = $this->registry->searchProviders();
        $max       = min( (int) ( $opts['max_queries'] ?? 10 ), $this->limits->remaining( 'discovery_queries' ) );
        if ( empty( $providers ) ) {
            $stats['warnings'][] = 'No search provider configured or all daily quotas used (Settings → Integrations → Search).';
        } elseif ( $max <= 0 ) {
            $stats['warnings'][] = 'Daily/weekly discovery query limit reached.';
        } else {
            $perQuery = max( 5, min( 20, (int) $this->settings->get( 'discovery.results_per_query', 10 ) ) );
            foreach ( $this->queries->next( $max ) as $item ) {
                if ( ! $budget->has( 3 ) ) {
                    break;
                }
                $results  = [];
                $provider = null;
                foreach ( $providers as $p ) {
                    if ( $p->remainingToday() <= 0 ) {
                        continue;
                    }
                    $results  = $p->search( $item['query'], [ 'num' => $perQuery, 'country' => 'ca' ] );
                    $provider = $p;
                    if ( ! empty( $results ) || '' === $p->lastError() ) {
                        break;
                    }
                }
                if ( ! $provider ) {
                    $stats['warnings'][] = 'Search quota exhausted for all providers.';
                    break;
                }
                $this->limits->spend( 'discovery_queries' );
                $stats['queries_run']++;
                $stats['results'] += count( $results );
                $new = 0;
                foreach ( $results as $r ) {
                    $res = $this->intake->fromUrl( $r['url'], 'search', $item['query'], [ 'title' => $r['title'], 'snippet' => $r['snippet'], 'opportunity_type' => $item['opportunity'] ] );
                    if ( ! $res || null !== $res['skipped'] ) {
                        $stats['skipped']++;
                    } elseif ( $res['created'] ) {
                        $new++;
                    }
                }
                $stats['new_prospects'] += $new;
                $this->log->record( $provider->id(), $item['query'], count( $results ), $new );
            }
        }

        // 3. Competitor backlinks (weekly).
        if ( empty( $opts['skip_competitors'] ) && $budget->has( 5 ) && $this->competitors->isDue() && ! empty( $this->competitors->competitors() ) ) {
            $stats['competitors'] = $this->competitors->discover( $this->intake );
            $stats['new_prospects'] += $stats['competitors']['created'];
            if ( '' !== $stats['competitors']['warning'] ) {
                $stats['warnings'][] = $stats['competitors']['warning'];
            }
        }

        $this->logger->info( 'discovery', 'Discovery run finished.', $stats );
        return $stats;
    }
}
