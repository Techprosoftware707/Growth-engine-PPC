<?php
/**
 * Detects broken outbound links on relevant resource pages that a PPC resource could replace.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Discovery;

use PPC\LinkGrowth\Analysis\PageAnalysis;
use PPC\LinkGrowth\Analysis\PageAnalyzer;
use PPC\LinkGrowth\Analysis\Taxonomy;
use PPC\LinkGrowth\Content\LinkableAssets;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Str;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Support\Url;

final class BrokenLinkFinder {

    public function __construct(
        private Client $client,
        private PageAnalyzer $analyzer,
        private Taxonomy $taxonomy,
        private LinkableAssets $assets,
        private Opportunities $opportunities,
        private Logger $logger
    ) {}

    /**
     * @return array{checked:int,broken:int,opportunities:int}
     */
    public function scan( PageAnalysis $page, int $prospectId, TimeBudget $budget, int $maxChecks = 25 ): array {
        $stats = [ 'checked' => 0, 'broken' => 0, 'opportunities' => 0 ];
        $terms = array_merge( $this->taxonomy->groups()['core']['terms'] ?? [], $this->taxonomy->groups()['adjacent']['terms'] ?? [] );
        $links = array_filter( $page->externalLinks(), static fn( $l ) => in_array( $l['region'], [ 'content', 'sidebar' ], true ) && Url::looksLikeHtml( $l['url'] ) );
        // Relevant links first.
        usort(
            $links,
            function ( $a, $b ) use ( $terms ) {
                return $this->relevance( $b, $terms ) <=> $this->relevance( $a, $terms );
            }
        );
        $seen = [];
        foreach ( $links as $link ) {
            if ( $stats['checked'] >= $maxChecks || ! $budget->has( 4 ) ) {
                break;
            }
            $host = Url::registrableDomain( $link['url'] );
            if ( isset( $seen[ $link['url'] ] ) || in_array( $host, [ 'facebook.com', 'twitter.com', 'x.com', 'linkedin.com', 'youtube.com', 'instagram.com' ], true ) ) {
                continue;
            }
            $seen[ $link['url'] ] = true;
            $resp = $this->client->probe( $link['url'] );
            $stats['checked']++;
            $broken = in_array( $resp->status, [ 404, 410 ], true ) || ( $resp->error && in_array( $resp->errorCode, [ 'http_request_failed' ], true ) && preg_match( '/resolve|could not|no such host|connection refused|timed out/i', $resp->error ) );
            if ( ! $broken ) {
                continue;
            }
            // Confirm once more to avoid transient failures.
            usleep( 500000 );
            $again = $this->client->probe( $link['url'] );
            if ( ! ( in_array( $again->status, [ 404, 410 ], true ) || $again->error ) ) {
                continue;
            }
            $stats['broken']++;
            if ( $this->relevance( $link, $terms ) <= 0 ) {
                continue; // broken, but not something a PPC resource could legitimately replace
            }
            $asset = $this->assets->bestFor( $link['anchor'] . ' ' . str_replace( [ '-', '_', '/' ], ' ', Url::path( $link['url'] ) ) . ' ' . $page->title, 'guide' );
            [ , $created ] = $this->opportunities->upsert(
                $prospectId,
                'broken_link',
                $page->finalUrl ?: $page->url,
                $page->title,
                [
                    'broken_url'      => $link['url'],
                    'anchor'          => $link['anchor'],
                    'status'          => $again->status ?: ( $resp->status ?: 0 ),
                    'error'           => $again->error ?: $resp->error,
                    'suggested_url'   => $asset['url'],
                    'suggested_title' => $asset['title'],
                    'suggested_summary' => $asset['summary'],
                    'found_at'        => gmdate( 'c' ),
                ],
                70,
                $link['url']
            );
            if ( $created ) {
                $stats['opportunities']++;
            }
        }
        return $stats;
    }

    /** @param array{url:string,anchor:string} $link @param string[] $terms */
    private function relevance( array $link, array $terms ): int {
        $hay = mb_strtolower( $link['anchor'] . ' ' . str_replace( [ '-', '_', '/', '.' ], ' ', $link['url'] ) );
        $n   = 0;
        foreach ( $terms as $t ) {
            $n += Str::countPhrase( $hay, $t );
        }
        return $n;
    }
}
