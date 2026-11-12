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

    /** Pause before the confirming probe (seconds). */
    private const CONFIRM_PAUSE = 0.5;

    public function __construct(
        private Client $client,
        private PageAnalyzer $analyzer,
        private Taxonomy $taxonomy,
        private LinkableAssets $assets,
        private Opportunities $opportunities,
        private Logger $logger
    ) {}

    /**
     * note is set when the time budget let no link be checked.
     *
     * @return array{checked:int,broken:int,opportunities:int,note?:string}
     */
    public function scan( PageAnalysis $page, int $prospectId, TimeBudget $budget, int $maxChecks = 25 ): array {
        $stats = [ 'checked' => 0, 'broken' => 0, 'opportunities' => 0 ];
        $start = $budget->remaining();
        $short = false; // a link was left unchecked for lack of time
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
            // A probe is up to three requests (robots.txt on the first probe of a host, HEAD, then a GET
            // fallback). The client waits the host's crawl delay before the GET, and before HEAD only while
            // the host's previous request is more recent than that delay. Stop when the budget cannot hold a
            // probe with the crawl-delay setting alone: no link would fit.
            $left = $budget->remaining() - 0.5;
            if ( null === self::timeoutFor( $left - $this->client->minDelay(), 3 ) ) {
                $short = true;
                break;
            }
            $host = Url::registrableDomain( $link['url'] );
            if ( isset( $seen[ $link['url'] ] ) || in_array( $host, [ 'facebook.com', 'twitter.com', 'x.com', 'linkedin.com', 'youtube.com', 'instagram.com' ], true ) ) {
                continue;
            }
            // This host's own waits (a pending wait, a longer robots.txt Crawl-delay) may not fit: leave the
            // link for the next scan and try the others.
            $timeout = self::timeoutFor( $left - $this->client->pendingWait( $link['url'] ) - $this->client->delayFor( $link['url'] ), 3 );
            if ( null === $timeout ) {
                $short = true;
                continue;
            }
            $seen[ $link['url'] ] = true;
            $resp = $this->client->probe( $link['url'], [ 'timeout' => $timeout ] );
            $stats['checked']++;
            if ( '' === self::failureKind( $resp ) ) {
                continue;
            }
            // Confirm once more to avoid transient failures; leave it for the next scan when the
            // budget cannot cover a second probe (never record an unconfirmed link). robots.txt is loaded
            // now, so the confirming probe is HEAD (after what is left of the host's crawl delay) plus a GET
            // fallback (after the whole delay, which now includes its robots.txt Crawl-delay). Stop when even
            // the crawl-delay setting alone leaves too little; otherwise only this link waits for later.
            $left = $budget->remaining() - self::CONFIRM_PAUSE;
            if ( null === self::timeoutFor( $left - 2 * $this->client->minDelay(), 2 ) ) {
                break;
            }
            $timeout = self::timeoutFor( $left - $this->client->pendingWait( $link['url'] ) - $this->client->delayFor( $link['url'] ), 2 );
            if ( null === $timeout ) {
                continue;
            }
            usleep( (int) ( self::CONFIRM_PAUSE * 1000000 ) );
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
        if ( 0 === $stats['checked'] && $short ) {
            $stats['note'] = sprintf(
                'No links were checked: only %d s was left, not enough for one link check plus the wait between requests to a site (%s s, set in Settings > Advanced, or longer when the site\'s robots.txt asks for it).',
                (int) round( $start ),
                rtrim( rtrim( number_format( $this->client->minDelay(), 1, '.', '' ), '0' ), '.' )
            );
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

    /** Per-request probe timeout for $requests requests within $seconds, or null when too little is left. */
    private static function timeoutFor( float $seconds, int $requests ): ?int {
        $t = (int) min( self::PROBE_TIMEOUT, floor( $seconds / max( 1, $requests ) ) );
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
