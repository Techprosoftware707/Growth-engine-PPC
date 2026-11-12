<?php
/**
 * Finds pages that mention the brand without linking to it (and records those that do link).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Discovery;

use PPC\LinkGrowth\Analysis\PageAnalyzer;
use PPC\LinkGrowth\Integrations\ProviderRegistry;
use PPC\LinkGrowth\Repositories\SearchLog;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Support\Url;

final class MentionFinder {

    /** Longest timeout Client::crawl() uses for its robots.txt request. */
    private const ROBOTS_TIMEOUT = 8;

    /** Shortest page timeout worth trying (the HTTP client's own floor). */
    private const MIN_TIMEOUT = 3;

    public function __construct(
        private ProviderRegistry $registry,
        private Client $client,
        private PageAnalyzer $analyzer,
        private Settings $settings,
        private ProspectIntake $intake,
        private SearchLog $log,
        private Logger $logger
    ) {}

    /**
     * @param string[] $queries
     * @return array{queries:int,pages_checked:int,unlinked:int,linked:array<int,array{url:string,anchor:string,nofollow:bool,target:string}>,warning:string}
     */
    public function scan( array $queries, TimeBudget $budget, int $maxPages = 15 ): array {
        $stats     = [ 'queries' => 0, 'pages_checked' => 0, 'unlinked' => 0, 'linked' => [], 'warning' => '' ];
        $providers = $this->registry->searchProviders();
        if ( [] === $providers ) {
            $stats['warning'] = 'No search provider configured.';
            return $stats;
        }
        $own      = $this->settings->siteDomain();
        $terms    = array_map( 'strval', (array) $this->settings->get( 'discovery.brand_terms', [] ) );
        $seen     = [];
        $failures = 0;
        $maxWait  = max( self::MIN_TIMEOUT, (int) $this->settings->get( 'advanced.request_timeout', 15 ) );
        foreach ( $queries as $q ) {
            if ( ! $budget->has( 6 ) || $stats['pages_checked'] >= $maxPages ) {
                break;
            }
            if ( $this->log->ranWithin( $q, 7 ) ) {
                continue;
            }
            // Like DiscoveryEngine: when a provider fails (bad key, 429, outage), the next one runs the query.
            $results  = [];
            $provider = null;
            $failed   = [];
            foreach ( $providers as $p ) {
                if ( $p->remainingToday() <= 0 ) {
                    continue;
                }
                $results  = $p->search( $q, [ 'num' => 10, 'country' => 'ca' ] );
                $provider = $p;
                if ( ! empty( $results ) || '' === $p->lastError() ) {
                    break;
                }
                $failed[] = sprintf( 'Search provider "%s" failed: %s.', $p->id(), $p->lastError() );
            }
            if ( null === $provider ) {
                $stats['warning'] = 'Search quota used up for every provider today.';
                break;
            }
            if ( empty( $results ) && '' !== $provider->lastError() ) {
                // The search did not run: do not log it, or the query would be skipped for 7 days
                // although it never ran.
                $stats['warning'] = implode( ' ', $failed ) . ' Mention queries were not counted and will be retried.';
                if ( ++$failures >= 2 ) {
                    break;
                }
                continue;
            }
            $failures = 0;
            $stats['queries']++;
            $new = 0;
            foreach ( $results as $res ) {
                // A crawl is a robots.txt fetch (when the host's robots.txt is not cached) plus the page
                // request: split what is left of the budget between the two, with at most ROBOTS_TIMEOUT
                // for robots.txt, so a scan with as little as 7 s left can still crawl a page.
                $left    = $budget->remaining() - 1;
                $robots  = (int) max( self::MIN_TIMEOUT, min( self::ROBOTS_TIMEOUT, floor( $left / 2 ) ) );
                $timeout = (int) min( $maxWait, floor( $left - $robots ) );
                if ( $timeout < self::MIN_TIMEOUT || $stats['pages_checked'] >= $maxPages ) {
                    break;
                }
                $url = Url::normalize( $res['url'] );
                if ( '' === $url || isset( $seen[ $url ] ) || Url::belongsTo( $url, $own ) || ! Url::looksLikeHtml( $url ) ) {
                    continue;
                }
                $seen[ $url ] = true;
                $resp = $this->client->crawl( $url, [ 'cache' => true, 'timeout' => $timeout, 'robots_timeout' => $robots ] );
                $stats['pages_checked']++;
                if ( $resp->failed() || ! $resp->isHtml() ) {
                    continue;
                }
                $page    = $this->analyzer->fromResponse( $resp );
                $mention = $page->mentions( $terms );
                $links   = $page->linksTo( $own );
                if ( ! empty( $links ) ) {
                    foreach ( $links as $l ) {
                        $stats['linked'][] = [ 'url' => $page->finalUrl ?: $url, 'anchor' => $l['anchor'], 'nofollow' => $l['nofollow'], 'target' => $l['url'] ];
                    }
                    continue;
                }
                if ( empty( $mention ) ) {
                    continue;
                }
                $snippet = $page->snippetAround( $mention[0] );
                $r       = $this->intake->fromUrl( $page->finalUrl ?: $url, 'mention', $q, [ 'title' => $page->title ?: $res['title'], 'snippet' => $snippet, 'opportunity_type' => 'unlinked_mention', 'details' => [ 'mention_terms' => $mention, 'mention_snippet' => $snippet ] ] );
                if ( $r && null === $r['skipped'] ) {
                    $stats['unlinked']++;
                    if ( $r['created'] ) {
                        $new++;
                    }
                }
            }
            $this->log->record( $provider->id(), $q, count( $results ), $new );
        }
        return $stats;
    }
}
