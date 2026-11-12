<?php
/**
 * Verifies that a submitted listing went live (a page on the prospect site links to our domain),
 * records the backlink, promotes the prospect and reschedules unverified submissions.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Submission;

use PPC\LinkGrowth\Analysis\PageAnalysis;
use PPC\LinkGrowth\Analysis\PageAnalyzer;
use PPC\LinkGrowth\Integrations\ProviderRegistry;
use PPC\LinkGrowth\Monitoring\Notifier;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\Backlinks;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Repository;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Str;
use PPC\LinkGrowth\Support\Url;
use Throwable;

final class ListingVerifier {

    /** Opportunity types whose link appears on the target page itself rather than on a listing page. */
    private const PAGE_TYPES = [ 'resource_page', 'broken_link', 'unlinked_mention', 'editorial', 'educational_contribution' ];

    private const RETRY_DAYS       = 3;
    private const GIVE_UP_DAYS     = 30;
    private const MAX_SEARCH_PAGES = 3;
    private const GIVE_UP_MESSAGE  = 'Could not verify listing after 30 days';

    public function __construct(
        private Client $client,
        private PageAnalyzer $analyzer,
        private ProviderRegistry $providers,
        private Submissions $submissions,
        private Backlinks $backlinks,
        private Prospects $prospects,
        private Settings $settings,
        private Notifier $notifier,
        private AuditLog $audit,
        private Logger $logger
    ) {}

    /**
     * @param array<string,mixed> $submission
     * @param array<string,mixed> $prospect
     * @return array{confirmed:bool,listing_url:string,note:string}
     */
    public function verify( array $submission, array $prospect ): array {
        $id = (int) ( $submission['id'] ?? 0 );
        try {
            return $this->check( $submission, $prospect );
        } catch ( Throwable $e ) {
            $this->logger->error( 'submission', 'Listing verification error', [ 'submission_id' => $id, 'error' => $e->getMessage() ] );
            return $this->reschedule( $submission, 0, false, 'Verification error: ' . $e->getMessage() );
        }
    }

    /**
     * @param array<string,mixed> $submission
     * @param array<string,mixed> $prospect
     * @return array{confirmed:bool,listing_url:string,note:string}
     */
    private function check( array $submission, array $prospect ): array {
        $site    = $this->settings->siteDomain();
        $brand   = trim( (string) $this->settings->get( 'site.brand_name', '' ) );
        $domain  = Url::registrableDomain( (string) ( $prospect['domain'] ?? '' ) );
        $payload = is_array( $submission['payload'] ?? null ) ? $submission['payload'] : [];
        $oppType = (string) ( $payload['destination']['opportunity_type'] ?? '' );
        $checked = [];

        // 1. Known listing URL and, for page-level opportunities, the page where the link should appear.
        $candidates = [];
        $listing    = Url::normalize( (string) ( $submission['listing_url'] ?? '' ) );
        if ( '' !== $listing ) {
            $candidates[] = $listing;
        }
        if ( in_array( $oppType, self::PAGE_TYPES, true ) ) {
            $dest = Url::normalize( (string) ( $payload['destination']['url'] ?? '' ) );
            if ( '' !== $dest ) {
                $candidates[] = $dest;
            }
        }
        foreach ( array_unique( $candidates ) as $url ) {
            $checked[ $url ] = true;
            $hit             = $this->checkPage( $url, $site );
            if ( null !== $hit ) {
                return $this->confirm( $submission, $prospect, $hit['page'], $hit['link'], $oppType );
            }
        }

        // 2. Search the prospect site for the brand and check up to three result pages.
        // Like DiscoveryEngine: when a provider fails (revoked key, 429, outage) the next one runs the query.
        $searched = false;
        if ( '' !== $domain && '' !== $brand ) {
            $results = [];
            foreach ( $this->providers->searchProviders() as $provider ) {
                if ( $provider->remainingToday() <= 0 ) {
                    continue;
                }
                $searched = true;
                try {
                    $results = $provider->search( sprintf( 'site:%s "%s"', $domain, $brand ), [ 'num' => 10, 'country' => 'ca' ] );
                } catch ( Throwable $e ) {
                    $results = [];
                    $this->logger->warning( 'submission', 'Listing search failed', [ 'provider' => $provider->id(), 'error' => $e->getMessage() ] );
                    continue;
                }
                if ( ! empty( $results ) || '' === $provider->lastError() ) {
                    break;
                }
                $this->logger->warning( 'submission', 'Listing search failed', [ 'provider' => $provider->id(), 'error' => $provider->lastError() ] );
            }
            $n = 0;
            foreach ( $results as $r ) {
                if ( $n >= self::MAX_SEARCH_PAGES ) {
                    break;
                }
                $url = Url::normalize( (string) ( $r['url'] ?? '' ) );
                if ( '' === $url || isset( $checked[ $url ] ) || ! Url::belongsTo( $url, $domain ) || ! Url::looksLikeHtml( $url ) ) {
                    continue;
                }
                $checked[ $url ] = true;
                $n++;
                $hit = $this->checkPage( $url, $site );
                if ( null !== $hit ) {
                    return $this->confirm( $submission, $prospect, $hit['page'], $hit['link'], $oppType );
                }
            }
        }

        // 3. Without a search provider and without a listing URL, at least look at the target page.
        if ( ! $searched && [] === $candidates ) {
            $target = Url::normalize( (string) ( $submission['target_url'] ?? '' ) );
            if ( '' !== $target && ! isset( $checked[ $target ] ) ) {
                $checked[ $target ] = true;
                $hit                = $this->checkPage( $target, $site );
                if ( null !== $hit ) {
                    return $this->confirm( $submission, $prospect, $hit['page'], $hit['link'], $oppType );
                }
            }
        }

        return $this->reschedule( $submission, count( $checked ), $searched );
    }

    /**
     * @return array{page:PageAnalysis,link:array<string,mixed>}|null
     */
    private function checkPage( string $url, string $site ): ?array {
        if ( '' === $site ) {
            return null;
        }
        $resp = $this->client->crawl( $url, [ 'cache' => true ] );
        if ( $resp->failed() || ! $resp->isHtml() ) {
            return null;
        }
        $page  = $this->analyzer->fromResponse( $resp );
        $links = $page->linksTo( $site );
        if ( [] === $links ) {
            return null;
        }
        $best = null;
        foreach ( $links as $l ) {
            if ( empty( $l['hidden'] ) ) {
                $best = $l;
                break;
            }
        }
        return [ 'page' => $page, 'link' => $best ?? $links[0] ];
    }

    /**
     * @param array<string,mixed> $submission
     * @param array<string,mixed> $prospect
     * @param array<string,mixed> $link
     * @return array{confirmed:bool,listing_url:string,note:string}
     */
    private function confirm( array $submission, array $prospect, PageAnalysis $page, array $link, string $oppType ): array {
        $id      = (int) ( $submission['id'] ?? 0 );
        $pid     = (int) ( $prospect['id'] ?? 0 );
        $now     = Repository::now();
        $pageUrl = $page->finalUrl ?: $page->url;
        $rel     = self::rel( (array) ( $link['rel'] ?? [] ) );
        $region  = (string) ( $link['region'] ?? 'content' );
        $place   = in_array( $oppType, self::PAGE_TYPES, true ) && in_array( $region, [ 'content', 'nav', 'footer', 'sidebar', 'header', 'comments' ], true ) ? $region : 'directory';

        $this->submissions->setStatus(
            $id,
            Submissions::STATUS_CONFIRMED,
            [
                'confirmed_at'   => $now,
                'listing_url'    => mb_substr( $pageUrl, 0, 700 ),
                'next_verify_at' => null,
                'last_error'     => '',
            ]
        );

        $interval = max( 1, (int) $this->settings->get( 'monitoring.check_interval_days_new', 1 ) );
        [ $backlinkId, $created ] = $this->backlinks->upsert(
            $pageUrl,
            (string) $link['url'],
            [
                'prospect_id'          => $pid > 0 ? $pid : null,
                'anchor_text'          => mb_substr( (string) ( $link['anchor'] ?? '' ), 0, 500 ),
                'rel'                  => $rel,
                'placement'            => $place,
                'status'               => Backlinks::STATUS_ACTIVE,
                'origin'               => 'submission',
                'http_status'          => $page->status,
                'is_indexable'         => ( $page->isIndexable() && ! $page->canonicalMismatch() ) ? 1 : 0,
                'quality_score'        => $this->quality( $page, $link, $rel, $place ),
                'last_checked'         => $now,
                'last_seen_live'       => $now,
                'next_check_at'        => gmdate( 'Y-m-d H:i:s', time() + $interval * DAY_IN_SECONDS ),
                'consecutive_failures' => 0,
                'lost_at'              => null,
            ]
        );

        if ( $pid > 0 ) {
            $this->prospects->setStatus( $pid, Prospects::STATUS_ACQUIRED );
        }

        $this->notifier->notify(
            'submission',
            'success',
            'Listing confirmed',
            sprintf( '%s now links to %s from %s (%s).', (string) ( $prospect['domain'] ?? 'A prospect' ), (string) $link['url'], $pageUrl, $rel ),
            [ 'submission_id' => $id, 'prospect_id' => $pid, 'backlink_id' => $backlinkId, 'listing_url' => $pageUrl ]
        );
        $this->audit->record(
            AuditLog::currentActor(),
            'submission.confirmed',
            'submission',
            $id,
            [ 'prospect_id' => $pid, 'listing_url' => $pageUrl, 'target' => (string) $link['url'], 'rel' => $rel, 'placement' => $place, 'backlink_id' => $backlinkId, 'backlink_created' => $created ]
        );
        $this->logger->info( 'submission', 'Listing verified', [ 'submission_id' => $id, 'listing_url' => $pageUrl, 'rel' => $rel ] );

        return [
            'confirmed'   => true,
            'listing_url' => $pageUrl,
            'note'        => sprintf( 'Live %s link found (anchor "%s").', $rel, Str::truncate( (string) ( $link['anchor'] ?? '' ), 60 ) ),
        ];
    }

    /**
     * @param array<string,mixed> $submission
     * @return array{confirmed:bool,listing_url:string,note:string}
     */
    private function reschedule( array $submission, int $pagesChecked, bool $searched, string $noteOverride = '' ): array {
        $id          = (int) ( $submission['id'] ?? 0 );
        $submittedAt = strtotime( (string) ( $submission['submitted_at'] ?? '' ) . ' UTC' );
        if ( false === $submittedAt || $submittedAt <= 0 ) {
            $submittedAt = time();
        }
        $deadline = $submittedAt + self::GIVE_UP_DAYS * DAY_IN_SECONDS;
        $response = is_array( $submission['response'] ?? null ) ? $submission['response'] : [];

        if ( '' !== $noteOverride ) {
            $note = $noteOverride;
        } elseif ( 0 === $pagesChecked ) {
            $note = $searched ? 'Listing not visible yet (no search result pages on the site to check).' : 'Listing not visible yet (no listing URL known and no search provider configured).';
        } else {
            $note = sprintf( 'Listing not visible yet (%d page%s checked%s).', $pagesChecked, 1 === $pagesChecked ? '' : 's', $searched ? ', including search results' : '' );
        }
        $response['verification'] = [ 'at' => gmdate( 'c' ), 'pages_checked' => $pagesChecked, 'searched' => $searched, 'note' => $note ];

        if ( time() >= $deadline ) {
            $this->submissions->update(
                $id,
                [
                    'next_verify_at' => null,
                    'last_error'     => self::GIVE_UP_MESSAGE,
                    'response'       => $response,
                    'updated_at'     => Repository::now(),
                ]
            );
            $this->notifier->notify(
                'submission',
                'warning',
                'Listing not verified',
                sprintf( 'Submission #%d could not be verified within 30 days; check the destination manually or mark it rejected.', $id ),
                [ 'submission_id' => $id ]
            );
            $this->audit->record( AuditLog::currentActor(), 'submission.verify_expired', 'submission', $id, [ 'pages_checked' => $pagesChecked ] );
            return [ 'confirmed' => false, 'listing_url' => '', 'note' => self::GIVE_UP_MESSAGE ];
        }

        $next = min( time() + self::RETRY_DAYS * DAY_IN_SECONDS, $deadline );
        $this->submissions->update(
            $id,
            [
                'next_verify_at' => gmdate( 'Y-m-d H:i:s', $next ),
                'response'       => $response,
                'updated_at'     => Repository::now(),
            ]
        );
        $this->logger->debug( 'submission', 'Listing not verified yet', [ 'submission_id' => $id, 'pages_checked' => $pagesChecked, 'next_verify_at' => gmdate( 'c', $next ) ] );
        return [ 'confirmed' => false, 'listing_url' => '', 'note' => $note ];
    }

    /** @param string[] $rel */
    private static function rel( array $rel ): string {
        $flags = [];
        foreach ( [ 'nofollow', 'sponsored', 'ugc' ] as $f ) {
            if ( in_array( $f, array_map( 'strtolower', array_map( 'strval', $rel ) ), true ) ) {
                $flags[] = $f;
            }
        }
        return [] === $flags ? 'dofollow' : implode( ',', $flags );
    }

    /**
     * Simple 0-100 quality estimate, aligned with the monitoring module's formula.
     *
     * @param array<string,mixed> $link
     */
    private function quality( PageAnalysis $page, array $link, string $rel, string $placement ): int {
        $score = 0;
        if ( $page->isIndexable() && ! $page->canonicalMismatch() ) {
            $score += 30;
        }
        if ( 'dofollow' === $rel ) {
            $score += 25;
        }
        $score += match ( $placement ) {
            'content'   => 25,
            'directory' => 15,
            'sidebar'   => 10,
            'footer'    => 5,
            default     => 0,
        };
        $anchor = trim( (string) ( $link['anchor'] ?? '' ) );
        if ( '' !== $anchor && ! preg_match( '/^(click here|here|website|link|www\.|https?:)/i', $anchor ) ) {
            $score += 10;
        }
        if ( str_starts_with( $page->finalUrl ?: $page->url, 'https://' ) ) {
            $score += 10;
        }
        return max( 0, min( 100, $score ) );
    }
}
