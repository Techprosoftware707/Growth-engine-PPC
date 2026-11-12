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
use PPC\LinkGrowth\Support\Http\Response;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Str;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Support\Url;

final class BrokenLinkFinder {

    /** Per-request timeout cap for link probes (seconds). */
    private const PROBE_TIMEOUT = 10;

    /** Shortest probe timeout worth trying (the HTTP client's own floor). */
    private const MIN_PROBE_TIMEOUT = 3;

    /** Pause before the confirming probe, plus room for the per-host crawl delay. */
    private const CONFIRM_MARGIN = 2.5;

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
            if ( $stats['checked'] >= $maxChecks ) {
                break;
            }
            // A probe is up to two requests (HEAD, then GET): size the timeout to the budget left.
            $timeout = self::timeoutFor( $budget->remaining() - 0.5 );
            if ( null === $timeout ) {
                break;
            }
            $host = Url::registrableDomain( $link['url'] );
            if ( isset( $seen[ $link['url'] ] ) || in_array( $host, [ 'facebook.com', 'twitter.com', 'x.com', 'linkedin.com', 'youtube.com', 'instagram.com' ], true ) ) {
                continue;
            }
            $seen[ $link['url'] ] = true;
            $resp = $this->client->probe( $link['url'], [ 'timeout' => $timeout ] );
            $stats['checked']++;
            if ( '' === self::failureKind( $resp ) ) {
                continue;
            }
            // Confirm once more to avoid transient failures; leave it for the next scan when the
            // budget cannot cover a second probe (never record an unconfirmed link).
            $timeout = self::timeoutFor( $budget->remaining() - self::CONFIRM_MARGIN );
            if ( null === $timeout ) {
                break;
            }
            usleep( 500000 );
            $again = $this->client->probe( $link['url'], [ 'timeout' => $timeout ] );
            $kind  = self::failureKind( $again );
            if ( '' === $kind ) {
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
                    'failure'         => $kind,
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

    /**
     * Why a probe shows the link is dead, or '' when it does not: only HTTP 404/410 and
     * definitive DNS / connection-refused failures count. Timeouts, TLS errors, 5xx and
     * rate limiting are not proof a link is dead, so they never become an opportunity
     * (the outreach copy would otherwise tell a webmaster a live link "no longer resolves").
     *
     * @return ''|'not_found'|'gone'|'dns'|'refused'
     */
    private static function failureKind( Response $r ): string {
        if ( 404 === $r->status ) {
            return 'not_found';
        }
        if ( 410 === $r->status ) {
            return 'gone';
        }
        if ( ! $r->error || 'http_request_failed' !== $r->errorCode || preg_match( '/timed?\s?out|timeout/i', $r->error ) ) {
            return '';
        }
        if ( preg_match( '/could not resolve|no such host|name or service not known|name not resolved|nodename nor servname/i', $r->error ) ) {
            return 'dns';
        }
        if ( preg_match( '/connection refused/i', $r->error ) ) {
            return 'refused';
        }
        return '';
    }

    /** Per-request probe timeout for two requests within $seconds, or null when too little is left. */
    private static function timeoutFor( float $seconds ): ?int {
        $t = (int) min( self::PROBE_TIMEOUT, floor( $seconds / 2 ) );
        return $t >= self::MIN_PROBE_TIMEOUT ? $t : null;
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
