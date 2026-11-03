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
        $stats    = [ 'queries' => 0, 'pages_checked' => 0, 'unlinked' => 0, 'linked' => [], 'warning' => '' ];
        $provider = $this->registry->primarySearch();
        if ( ! $provider ) {
            $stats['warning'] = 'No search provider configured.';
            return $stats;
        }
        $own   = $this->settings->siteDomain();
        $terms = array_map( 'strval', (array) $this->settings->get( 'discovery.brand_terms', [] ) );
        $seen  = [];
        foreach ( $queries as $q ) {
            if ( ! $budget->has( 6 ) || $stats['pages_checked'] >= $maxPages ) {
                break;
            }
            if ( $this->log->ranWithin( $q, 7 ) ) {
                continue;
            }
            $results = $provider->search( $q, [ 'num' => 10, 'country' => 'ca' ] );
            $stats['queries']++;
            $new = 0;
            foreach ( $results as $res ) {
                if ( ! $budget->has( 4 ) || $stats['pages_checked'] >= $maxPages ) {
                    break;
                }
                $url = Url::normalize( $res['url'] );
                if ( '' === $url || isset( $seen[ $url ] ) || Url::belongsTo( $url, $own ) || ! Url::looksLikeHtml( $url ) ) {
                    continue;
                }
                $seen[ $url ] = true;
                $resp = $this->client->crawl( $url, [ 'cache' => true ] );
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
