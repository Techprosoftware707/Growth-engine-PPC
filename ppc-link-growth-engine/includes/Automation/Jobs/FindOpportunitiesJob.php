<?php
/**
 * Job: create the primary opportunity for qualified prospects that have none, and scan resource pages for broken links.
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

final class FindOpportunitiesJob extends AbstractJob {

    private const DEFAULT_LIMIT       = 10;
    private const MAX_LIMIT           = 25;
    private const BROKEN_LINK_CHECKS  = 15;
    private const BROKEN_LINK_SECONDS = 10.0;

    /** Fallback mapping when a prospect carries no usable opportunity type. */
    private const SITE_TYPE_TO_OPPORTUNITY = [
        'directory'          => 'directory_listing',
        'supplier_directory' => 'supplier_listing',
        'association'        => 'membership',
        'publication'        => 'editorial',
        'media'              => 'editorial',
        'blog'               => 'editorial',
        'educational'        => 'educational_contribution',
        'resource_page'      => 'resource_page',
        'podcast'            => 'podcast',
        'government'         => 'resource_page',
        'ecommerce'          => 'partnership',
    ];

    public static function type(): string {
        return 'find_opportunities';
    }

    public static function priority(): int {
        return 5;
    }

    /**
     * @param array<string,mixed> $payload { limit?: int }
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        if ( ! $this->flag( $c, $payload, 'opportunities' ) ) {
            return $this->skipped( 'opportunities_disabled' );
        }
        $prospects     = $c->get( Prospects::class );
        $opportunities = $c->get( Opportunities::class );
        $logger        = $this->logger( $c );
        $limit         = max( 1, min( (int) ( $payload['limit'] ?? self::DEFAULT_LIMIT ), self::MAX_LIMIT ) );
        $stats         = [
            'prospects'                 => 0,
            'created'                   => 0,
            'existing'                  => 0,
            'broken_link_scans'         => 0,
            'broken_links_checked'      => 0,
            'broken_links_found'        => 0,
            'broken_link_opportunities' => 0,
            'errors'                    => 0,
            'deferred'                  => 0,
            'continued'                 => false,
            'items'                     => [],
        ];

        $rows               = $prospects->qualifiedWithoutOpportunities( $limit );
        $stats['prospects'] = count( $rows );
        if ( [] === $rows ) {
            return $this->result( array_merge( $stats, [ 'message' => 'Every qualified prospect already has an opportunity.' ] ) );
        }

        foreach ( $rows as $row ) {
            if ( ! $budget->has( 2.0 ) ) {
                $stats['deferred']++;
                continue;
            }
            $id     = (int) $row['id'];
            $domain = (string) $row['domain'];
            try {
                $profile = is_array( $row['profile'] ?? null ) ? $row['profile'] : [];
                $form    = is_array( $profile['submission_form'] ?? null ) ? $profile['submission_form'] : null;
                $type    = $this->opportunityType( $row );
                $url     = $this->targetUrl( $row );
                $title   = $this->title( $row, $profile );
                $details = [
                    'discovered_via'     => 'evaluation',
                    'site_type'          => (string) ( $row['site_type'] ?? '' ),
                    'form_kind'          => (string) ( $form['kind'] ?? '' ),
                    'has_captcha'        => ! empty( $form['has_captcha'] ),
                    'recommended_action' => (string) ( $row['recommended_action'] ?? '' ),
                    'contact_url'        => (string) ( $row['contact_url'] ?? '' ),
                    'score_class'        => (string) ( $row['score_class'] ?? '' ),
                ];
                $score = isset( $row['score'] ) && null !== $row['score'] ? (int) $row['score'] : null;

                [ $oppId, $created ] = $opportunities->upsert( $id, $type, $url, $title, $details, $score );
                $item = [ 'prospect_id' => $id, 'domain' => $domain, 'opportunity_id' => $oppId, 'type' => $type, 'url' => $url, 'created' => $created ];
                if ( $created ) {
                    $stats['created']++;
                    $this->audit( $c )->record( 'opportunity.created', 'opportunity', $oppId, [ 'prospect_id' => $id, 'domain' => $domain, 'type' => $type, 'url' => $url, 'via' => 'find_opportunities' ] );
                } else {
                    $stats['existing']++;
                }

                $isResourcePage = 'resource_page' === (string) ( $row['site_type'] ?? '' ) || 'resource_page' === $type;
                if ( $isResourcePage && $budget->has( self::BROKEN_LINK_SECONDS ) ) {
                    $scan = $this->scanBrokenLinks( $c, $id, $url, $budget );
                    $stats['broken_link_scans']++;
                    $stats['broken_links_checked']      += (int) ( $scan['checked'] ?? 0 );
                    $stats['broken_links_found']        += (int) ( $scan['broken'] ?? 0 );
                    $stats['broken_link_opportunities'] += (int) ( $scan['opportunities'] ?? 0 );
                    $item['broken_links']                = $scan;
                }
                $stats['items'][] = $item;
            } catch ( Throwable $e ) {
                $stats['errors']++;
                $logger->error( 'opportunities', 'Opportunity creation failed', array_merge( [ 'prospect_id' => $id, 'domain' => $domain ], $this->errorContext( $e ) ) );
                $stats['items'][] = [ 'prospect_id' => $id, 'domain' => $domain, 'error' => $e->getMessage() ];
            }
        }

        if ( 0 === $stats['created'] && 0 === $stats['existing'] && 0 === $stats['errors'] && $stats['deferred'] > 0 ) {
            return $this->deferred( $c, $payload, 'time_budget', $stats );
        }
        if ( $stats['created'] > 0 && ( $stats['deferred'] > 0 || count( $rows ) >= $limit ) && [] !== $prospects->qualifiedWithoutOpportunities( 1 ) ) {
            $stats['continued'] = null !== $this->continueLater( $c, $payload, 30 );
        }
        return $this->result( $stats );
    }

    /** @param array<string,mixed> $row */
    private function opportunityType( array $row ): string {
        $type = (string) ( $row['opportunity_type'] ?? '' );
        if ( '' !== $type && array_key_exists( $type, Opportunities::TYPES ) ) {
            return $type;
        }
        $siteType = (string) ( $row['site_type'] ?? '' );
        if ( isset( self::SITE_TYPE_TO_OPPORTUNITY[ $siteType ] ) ) {
            return self::SITE_TYPE_TO_OPPORTUNITY[ $siteType ];
        }
        $profile = is_array( $row['profile'] ?? null ) ? $row['profile'] : [];
        $form    = is_array( $profile['submission_form'] ?? null ) ? $profile['submission_form'] : null;
        if ( null !== $form && 'listing_submission' === (string) ( $form['kind'] ?? '' ) ) {
            return 'directory_listing';
        }
        return 'resource_page';
    }

    /** @param array<string,mixed> $row */
    private function targetUrl( array $row ): string {
        foreach ( [ (string) ( $row['submission_url'] ?? '' ), (string) ( $row['homepage_url'] ?? '' ) ] as $candidate ) {
            $u = Url::normalize( $candidate );
            if ( '' !== $u ) {
                return $u;
            }
        }
        return 'https://' . (string) $row['domain'] . '/';
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $profile
     */
    private function title( array $row, array $profile ): string {
        $home  = is_array( $profile['home'] ?? null ) ? $profile['home'] : [];
        $title = (string) ( $profile['name'] ?? '' ) ?: (string) ( $home['title'] ?? '' ) ?: (string) ( $row['name'] ?? '' ) ?: (string) $row['domain'];
        return mb_substr( trim( $title ), 0, 255 );
    }

    /**
     * Crawls the opportunity page and runs the broken-link finder on it.
     *
     * @return array<string,mixed>
     */
    private function scanBrokenLinks( Container $c, int $prospectId, string $url, TimeBudget $budget ): array {
        try {
            $resp = $c->get( Client::class )->crawl( $url, [ 'cache' => true ] );
            if ( ! $resp->ok() || ! $resp->isHtml() ) {
                return [ 'checked' => 0, 'broken' => 0, 'opportunities' => 0, 'note' => $resp->error ?: ( 'HTTP ' . $resp->status ) ];
            }
            $page = $c->get( PageAnalyzer::class )->fromResponse( $resp );
            return $c->get( BrokenLinkFinder::class )->scan( $page, $prospectId, $budget, self::BROKEN_LINK_CHECKS );
        } catch ( Throwable $e ) {
            $this->logger( $c )->warning( 'opportunities', 'Broken-link scan failed', array_merge( [ 'prospect_id' => $prospectId, 'url' => $url ], $this->errorContext( $e ) ) );
            return [ 'checked' => 0, 'broken' => 0, 'opportunities' => 0, 'note' => $e->getMessage() ];
        }
    }
}
