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

    /** Stop searching after this many queries in a row failed on every provider. */
    private const MAX_CONSECUTIVE_FAILURES = 2;

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

        // 1. Seeds: the whole catalog once, then any seed added since (custom seeds, catalog updates).
        if ( $this->settings->get( 'discovery.seed_catalog', true ) ) {
            if ( ! empty( $opts['force_seeds'] ) || '' === $this->seeds->importedAt() ) {
                $stats['seeds'] = $this->seeds->import( $this->intake );
            } elseif ( [] !== $this->seeds->pending() ) {
                $stats['seeds'] = $this->seeds->import( $this->intake, true );
            }
            if ( null !== $stats['seeds'] ) {
                $stats['new_prospects'] += $stats['seeds']['created'];
            }
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
            $failures    = 0;
            $errors      = [];
            $firstFailed = null;
            foreach ( $this->queries->next( $max ) as $item ) {
                if ( ! $budget->has( 3 ) ) {
                    break;
                }
                $results  = [];
                $provider = null;
                $failed   = [];
                foreach ( $providers as $p ) {
                    if ( $p->remainingToday() <= 0 ) {
                        continue;
                    }
                    $results  = $p->search( $item['query'], [ 'num' => $perQuery, 'country' => 'ca' ] );
                    $provider = $p;
                    if ( ! empty( $results ) || '' === $p->lastError() ) {
                        break;
                    }
                    $failed[ $p->id() ] = $p->lastError();
                }
                if ( ! $provider ) {
                    $stats['warnings'][] = 'Search quota exhausted for all providers.';
                    break;
                }
                if ( empty( $results ) && '' !== $provider->lastError() ) {
                    $errors        = array_merge( $errors, $failed );
                    $firstFailed ??= $item['query'];
                    // Every provider failed (bad key, 429, outage). The query did not run: do not spend
                    // the discovery quota or log it, so it is not put into the 30-day cooldown.
                    if ( ++$failures >= self::MAX_CONSECUTIVE_FAILURES ) {
                        break;
                    }
                    continue;
                }
                $failures = 0;
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
            if ( null !== $firstFailed ) {
                $this->queries->rewindTo( $firstFailed );
            }
            foreach ( $errors as $id => $message ) {
                $stats['warnings'][] = sprintf( 'Search provider "%s" failed: %s. Affected queries were not counted and will be retried.', $id, $message );
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
