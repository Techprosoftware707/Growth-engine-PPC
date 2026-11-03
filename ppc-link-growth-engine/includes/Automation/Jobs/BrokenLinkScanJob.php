<?php
/**
 * Job: scan resource-page prospects for broken outbound links we could replace.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Analysis\PageAnalyzer;
use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Discovery\BrokenLinkFinder;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Support\Url;
use Throwable;

final class BrokenLinkScanJob extends AbstractJob {

    private const MAX_PROSPECTS       = 5;
    private const MAX_CHECKS          = 25;
    private const PER_PROSPECT_SECONDS = 8.0;

    public static function type(): string {
        return 'broken_link_scan';
    }

    public static function priority(): int {
        return 7;
    }

    /**
     * @param array<string,mixed> $payload { prospect_id?: int }
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        $prospects = $c->get( Prospects::class );
        $single    = (int) ( $payload['prospect_id'] ?? 0 );

        if ( $single > 0 ) {
            $row = $prospects->find( $single );
            if ( null === $row ) {
                return $this->skipped( 'prospect_not_found', [ 'prospect_id' => $single ] );
            }
            if ( Prospects::STATUS_SUPPRESSED === (string) $row['status'] ) {
                return $this->skipped( 'prospect_suppressed', [ 'prospect_id' => $single ] );
            }
            $rows = [ $row ];
        } else {
            if ( ! $this->flag( $c, $payload, 'opportunities' ) ) {
                return $this->skipped( 'opportunities_disabled' );
            }
            $rows = $this->candidates( $prospects );
            if ( [] === $rows ) {
                return $this->result( [ 'prospects' => 0, 'message' => 'No qualified resource-page prospects to scan.' ] );
            }
        }

        $stats = [ 'prospects' => count( $rows ), 'pages_scanned' => 0, 'checked' => 0, 'broken' => 0, 'opportunities' => 0, 'errors' => 0, 'deferred' => 0, 'items' => [] ];
        foreach ( $rows as $row ) {
            if ( ! $budget->has( self::PER_PROSPECT_SECONDS ) ) {
                $stats['deferred']++;
                continue;
            }
            $id     = (int) $row['id'];
            $domain = (string) $row['domain'];
            $url    = '';
            try {
                $url  = $this->pageFor( $c, $row );
                $resp = $c->get( Client::class )->crawl( $url, [ 'cache' => true ] );
                if ( ! $resp->ok() || ! $resp->isHtml() ) {
                    $stats['items'][] = [ 'prospect_id' => $id, 'domain' => $domain, 'url' => $url, 'note' => $resp->error ?: ( 'HTTP ' . $resp->status ) ];
                    continue;
                }
                $page = $c->get( PageAnalyzer::class )->fromResponse( $resp );
                $scan = $c->get( BrokenLinkFinder::class )->scan( $page, $id, $budget, self::MAX_CHECKS );
                $stats['pages_scanned']++;
                $stats['checked']       += (int) ( $scan['checked'] ?? 0 );
                $stats['broken']        += (int) ( $scan['broken'] ?? 0 );
                $stats['opportunities'] += (int) ( $scan['opportunities'] ?? 0 );
                $stats['items'][]        = array_merge( [ 'prospect_id' => $id, 'domain' => $domain, 'url' => $url ], $scan );
                if ( (int) ( $scan['opportunities'] ?? 0 ) > 0 ) {
                    $this->audit( $c )->record( 'prospect.broken_links_found', 'prospect', $id, [ 'domain' => $domain, 'url' => $url, 'checked' => $scan['checked'] ?? 0, 'broken' => $scan['broken'] ?? 0, 'opportunities' => $scan['opportunities'] ?? 0 ] );
                }
            } catch ( Throwable $e ) {
                $stats['errors']++;
                $this->logger( $c )->error( 'opportunities', 'Broken-link scan failed', array_merge( [ 'prospect_id' => $id, 'domain' => $domain, 'url' => $url ], $this->errorContext( $e ) ) );
                $stats['items'][] = [ 'prospect_id' => $id, 'domain' => $domain, 'url' => $url, 'error' => $e->getMessage() ];
            }
        }

        if ( 0 === $stats['pages_scanned'] && 0 === $stats['errors'] && [] === $stats['items'] && $stats['deferred'] > 0 ) {
            return $this->deferred( $c, $payload, 'time_budget', $stats );
        }
        if ( $stats['opportunities'] > 0 ) {
            $this->notify(
                $c,
                'opportunity',
                'info',
                'Broken-link opportunities found',
                sprintf( '%d broken outbound link(s) on %d page(s) could be replaced with a Pure Peptides Canada resource.', $stats['opportunities'], $stats['pages_scanned'] ),
                [ 'opportunities' => $stats['opportunities'] ]
            );
        }
        return $this->result( $stats );
    }

    /**
     * Qualified/approved prospects typed as resource pages (by opportunity type, then by site type), best scores first.
     *
     * @return array<int,array<string,mixed>>
     */
    private function candidates( Prospects $prospects ): array {
        $statuses = [ Prospects::STATUS_QUALIFIED, Prospects::STATUS_APPROVED ];
        $rows     = $prospects->findBy( [ 'opportunity_type' => 'resource_page', 'status' => $statuses ], 'score', 'DESC', self::MAX_PROSPECTS );
        if ( count( $rows ) < self::MAX_PROSPECTS ) {
            $seen = array_column( $rows, 'id' );
            foreach ( $prospects->findBy( [ 'site_type' => 'resource_page', 'status' => $statuses ], 'score', 'DESC', self::MAX_PROSPECTS ) as $extra ) {
                if ( ! in_array( (int) $extra['id'], $seen, true ) ) {
                    $rows[] = $extra;
                    $seen[] = (int) $extra['id'];
                }
                if ( count( $rows ) >= self::MAX_PROSPECTS ) {
                    break;
                }
            }
        }
        return $rows;
    }

    /**
     * The page most likely to carry the resource list: an open resource-page opportunity, else the submission page, else the homepage.
     *
     * @param array<string,mixed> $row
     */
    private function pageFor( Container $c, array $row ): string {
        try {
            foreach ( $c->get( Opportunities::class )->forProspect( (int) $row['id'] ) as $opp ) {
                if ( 'resource_page' === (string) ( $opp['type'] ?? '' ) && Opportunities::STATUS_OPEN === (string) ( $opp['status'] ?? '' ) ) {
                    $u = Url::normalize( (string) ( $opp['url'] ?? '' ) );
                    if ( '' !== $u ) {
                        return $u;
                    }
                }
            }
        } catch ( Throwable $e ) {
            unset( $e );
        }
        foreach ( [ (string) ( $row['submission_url'] ?? '' ), (string) ( $row['homepage_url'] ?? '' ) ] as $candidate ) {
            $u = Url::normalize( $candidate );
            if ( '' !== $u ) {
                return $u;
            }
        }
        return 'https://' . (string) $row['domain'] . '/';
    }
}
