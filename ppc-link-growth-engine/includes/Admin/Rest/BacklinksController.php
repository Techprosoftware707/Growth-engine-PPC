<?php
/**
 * /backlinks — monitored links, manual additions, on-demand checks and summary charts.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Admin\Rest;

use PPC\LinkGrowth\Automation\Pipeline;
use PPC\LinkGrowth\Monitoring\BacklinkMonitor;
use PPC\LinkGrowth\Repositories\Backlinks;
use PPC\LinkGrowth\Repositories\DomainMetrics;
use PPC\LinkGrowth\Repositories\Prospects;
use WP_REST_Request;

final class BacklinksController extends Controller {

    private const INT_COLUMNS = [ 'prospect_id', 'http_status', 'is_indexable', 'quality_score', 'consecutive_failures' ];

    private const RUN_SECONDS = 25.0;

    /** Provider authority (DR / DA / Authority Score, 0-100) at or above which a referring domain is "high-authority". */
    private const HIGH_AUTHORITY_MIN = 30;

    /** Monitor link-quality score (indexable, dofollow, placement, anchor, https) counted as a quality link. */
    private const QUALITY_SCORE_MIN = 60;

    /** Upper bound on referring domains examined for the authority count (keeps the summary query bounded). */
    private const AUTHORITY_SCAN_MAX = 5000;

    public function register( string $namespace ): void {
        $this->route( $namespace, '/backlinks', 'GET', [ $this, 'index' ], $this->listArgs( [ 'status', 'rel', 'origin', 'placement', 'prospect_id' ] ) );
        $this->route( $namespace, '/backlinks', 'POST', [ $this, 'create' ] );
        $this->route( $namespace, '/backlinks/summary', 'GET', [ $this, 'summary' ] );
        $this->route( $namespace, '/backlinks/import', 'POST', [ $this, 'import' ] );
        $this->route( $namespace, '/backlinks/(?P<id>\d+)', 'GET', [ $this, 'show' ], $this->idArgs() );
        $this->route( $namespace, '/backlinks/(?P<id>\d+)', 'DELETE', [ $this, 'destroy' ], $this->idArgs() );
        $this->route( $namespace, '/backlinks/(?P<id>\d+)/check', 'POST', [ $this, 'check' ], $this->idArgs() );
    }

    /** @return array<string,mixed> */
    public function index( WP_REST_Request $request ): array {
        $pg    = $this->paginationArgs( $request, 'id', 'DESC' );
        $where = [];
        $status = $this->keyList( $request, 'status' );
        if ( [] !== $status ) {
            $where['status'] = count( $status ) > 1 ? $status : $status[0];
        }
        foreach ( [ 'origin', 'placement' ] as $key ) {
            $v = $this->str( $request, $key, '', 40 );
            $v = preg_replace( '/[^a-z0-9_:\-]/', '', strtolower( $v ) ) ?? '';
            if ( '' !== $v ) {
                $where[ $key ] = $v;
            }
        }
        $prospectId = $this->int( $request, 'prospect_id', 0 );
        if ( $prospectId > 0 ) {
            $where['prospect_id'] = $prospectId;
        }
        $extra  = '';
        $params = [];
        $rel    = sanitize_key( $this->str( $request, 'rel', '', 20 ) );
        if ( 'dofollow' === $rel ) {
            $where['rel'] = 'dofollow';
        } elseif ( '' !== $rel ) {
            global $wpdb;
            $extra    = 'rel LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $rel ) . '%';
        }
        $repo = $this->c->get( Backlinks::class );
        $page = $repo->paginate( $where, $pg['q'], [ 'source_domain', 'anchor_text', 'source_url' ], $pg['page'], $pg['per_page'], $pg['orderby'], $pg['order'], $extra, $params );
        $items = array_map( static fn( array $b ): array => self::castInts( $b, self::INT_COLUMNS ), $page['items'] );
        foreach ( $items as &$b ) {
            $b['changes_count'] = is_array( $b['changes'] ?? null ) ? count( $b['changes'] ) : 0;
            unset( $b['changes'] );
        }
        unset( $b );
        $page['items']  = $this->attachProspects( $items );
        $page['facets'] = $this->safe(
            static fn(): array => [
                'status' => $repo->statusCounts(),
                'origin' => $repo->countBy( 'origin' ),
                'rel'    => $repo->countBy( 'rel' ),
            ],
            [],
            'backlink facets'
        );
        return $page;
    }

    /** @return array<string,mixed>|\WP_Error */
    public function show( WP_REST_Request $request ) {
        $id = $this->id( $request );
        $b  = $this->c->get( Backlinks::class )->find( $id );
        if ( null === $b ) {
            return $this->notFound( __( 'Backlink', 'ppc-link-growth-engine' ) );
        }
        $prospect = (int) ( $b['prospect_id'] ?? 0 ) > 0 ? $this->safe( fn(): ?array => $this->c->get( Prospects::class )->find( (int) $b['prospect_id'] ), null, 'backlink prospect' ) : null;
        return [
            'backlink' => self::castInts( $b, self::INT_COLUMNS ),
            'prospect' => $prospect ? $this->slimProspect( $prospect, true ) : null,
        ];
    }

    /** POST /backlinks {source_url, target_url} @return array<string,mixed>|\WP_Error */
    public function create( WP_REST_Request $request ) {
        // URLs are read with url(), not str(): sanitize_text_field() deletes percent-encoded octets
        // (caf%C3%A9 -> caf, a%2Fb -> ab), so the wrong page would be stored and monitored.
        $source = $this->url( $request, 'source_url', '', 700 );
        $target = $this->url( $request, 'target_url', '', 700 );
        if ( '' === $source ) {
            if ( '' !== $this->str( $request, 'source_url', '', 700 ) ) {
                return $this->fail( 'ppclg_invalid_backlink', __( 'The backlink could not be added: check that the source URL is valid and points to another site.', 'ppc-link-growth-engine' ), 422 );
            }
            return $this->fail( 'ppclg_missing_source', __( 'The source URL (the page that links to you) is required.', 'ppc-link-growth-engine' ), 400 );
        }
        if ( '' === $target && '' !== $this->str( $request, 'target_url', '', 700 ) ) {
            return $this->fail( 'ppclg_invalid_backlink', __( 'The backlink could not be added: check that the target URL is a valid http(s) address on your site.', 'ppc-link-growth-engine' ), 422 );
        }
        $id = $this->c->get( BacklinkMonitor::class )->addManual( $source, $target, $this->userId() );
        if ( $id <= 0 ) {
            return $this->fail( 'ppclg_invalid_backlink', __( 'The backlink could not be added: check that the source URL is valid and points to another site.', 'ppc-link-growth-engine' ), 422 );
        }
        $row = $this->c->get( Backlinks::class )->find( $id );
        return [ 'ok' => true, 'backlink_id' => $id, 'backlink' => $row ? self::castInts( $row, self::INT_COLUMNS ) : null ];
    }

    /** POST /backlinks/{id}/check @return array<string,mixed>|\WP_Error */
    public function check( WP_REST_Request $request ) {
        $id   = $this->id( $request );
        $repo = $this->c->get( Backlinks::class );
        $b    = $repo->find( $id );
        if ( null === $b ) {
            return $this->notFound( __( 'Backlink', 'ppc-link-growth-engine' ) );
        }
        $result = $this->c->get( BacklinkMonitor::class )->check( $b );
        $this->audit( 'backlink.checked_manually', 'backlink', $id, [ 'status' => (string) ( $result['status'] ?? '' ), 'changed' => (bool) ( $result['changed'] ?? false ) ] );
        $fresh = $repo->find( $id );
        return [
            'ok'       => true,
            'result'   => $result,
            'backlink' => $fresh ? self::castInts( $fresh, self::INT_COLUMNS ) : null,
        ];
    }

    /** POST /backlinks/import @return array<string,mixed> */
    public function import( WP_REST_Request $request ): array {
        unset( $request );
        $run = $this->c->get( Pipeline::class )->runNow( 'import_backlinks', [ 'force' => true ], self::RUN_SECONDS );
        return [
            'ok'     => (bool) ( $run['result']['ok'] ?? true ),
            'result' => $run['result'] ?? [],
            'job_id' => $run['job_id'] ?? null,
        ];
    }

    /** DELETE /backlinks/{id} @return array<string,mixed>|\WP_Error */
    public function destroy( WP_REST_Request $request ) {
        $id   = $this->id( $request );
        $repo = $this->c->get( Backlinks::class );
        $b    = $repo->find( $id );
        if ( null === $b ) {
            return $this->notFound( __( 'Backlink', 'ppc-link-growth-engine' ) );
        }
        $repo->delete( $id );
        $this->audit( 'backlink.deleted', 'backlink', $id, [ 'source_url' => (string) ( $b['source_url'] ?? '' ), 'status' => (string) ( $b['status'] ?? '' ) ] );
        return [ 'ok' => true, 'deleted' => $id ];
    }

    /** GET /backlinks/summary @return array<string,mixed> */
    public function summary( WP_REST_Request $request ): array {
        unset( $request );
        $repo    = $this->c->get( Backlinks::class );
        $since30 = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
        $counts  = $this->safe( static fn(): array => $repo->statusCounts(), [], 'backlink status counts' );

        $anchors = [];
        foreach ( $this->safe( static fn(): array => $repo->anchorDistribution( 15 ), [], 'anchors' ) as $anchor => $count ) {
            $anchors[] = [ 'anchor' => (string) $anchor, 'count' => (int) $count ];
        }
        $rel = [];
        foreach ( $this->safe( static fn(): array => $repo->relDistribution(), [], 'rel' ) as $value => $count ) {
            $rel[] = [ 'rel' => (string) $value, 'count' => (int) $count ];
        }
        $origins = [];
        foreach ( $this->safe( static fn(): array => $repo->countBy( 'origin' ), [], 'origins' ) as $value => $count ) {
            $origins[] = [ 'origin' => (string) $value, 'count' => (int) $count ];
        }
        $placements = [];
        foreach ( $this->safe( static fn(): array => $repo->countBy( 'placement', 'status = %s', [ Backlinks::STATUS_ACTIVE ] ), [], 'placements' ) as $value => $count ) {
            $placements[] = [ 'placement' => (string) $value, 'count' => (int) $count ];
        }
        $statusList = [];
        foreach ( $counts as $value => $count ) {
            $statusList[] = [ 'status' => (string) $value, 'count' => (int) $count ];
        }

        return [
            'status_counts'          => $counts,
            'status_list'            => $statusList,
            'total'                  => (int) array_sum( $counts ),
            'active'                 => (int) ( $counts[ Backlinks::STATUS_ACTIVE ] ?? 0 ),
            'lost'                   => (int) ( $counts[ Backlinks::STATUS_LOST ] ?? 0 ) + (int) ( $counts[ Backlinks::STATUS_BROKEN ] ?? 0 ),
            'pending'                => (int) ( $counts[ Backlinks::STATUS_PENDING ] ?? 0 ),
            'new_30d'                => (int) $this->safe( static fn(): int => $repo->countSince( 'first_seen', $since30 ), 0, 'new 30d' ),
            'lost_30d'               => (int) $this->safe( static fn(): int => $repo->countSince( 'lost_at', $since30 ), 0, 'lost 30d' ),
            'referring_domains'      => (int) $this->safe( static fn(): int => $repo->referringDomainCount( Backlinks::STATUS_ACTIVE ), 0, 'referring domains' ),
            // Real provider authority (what the Backlinks page labels "Authority ≥ 30 on a configured provider"),
            // not the monitor's link-quality score; that one is reported separately as quality_domains.
            'high_authority_domains' => (int) $this->safe( fn(): int => $this->providerAuthorityDomainCount( self::HIGH_AUTHORITY_MIN ), 0, 'high authority' ),
            'high_authority_min'     => self::HIGH_AUTHORITY_MIN,
            'quality_domains'        => (int) $this->safe( static fn(): int => $repo->highAuthorityDomainCount( self::QUALITY_SCORE_MIN ), 0, 'quality domains' ),
            'rel_distribution'       => $rel,
            'anchor_distribution'    => $anchors,
            'origin_distribution'    => $origins,
            'placement_distribution' => $placements,
            'trend'                  => $this->safe( static fn(): array => $repo->weeklyTrend( 12 ), [], 'trend' ),
            'due_for_check'          => (int) $this->safe( static fn(): int => count( $repo->dueForCheck( 500 ) ), 0, 'due' ),
        ];
    }

    /**
     * Distinct active referring domains whose cached provider metrics report an authority of at least $min
     * on any configured provider. Domains without provider data are not counted.
     */
    private function providerAuthorityDomainCount( int $min ): int {
        global $wpdb;
        $table = $this->c->get( Backlinks::class )->table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $domains = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT source_domain FROM {$table} WHERE status = %s AND source_domain <> '' ORDER BY source_domain ASC LIMIT %d", Backlinks::STATUS_ACTIVE, self::AUTHORITY_SCAN_MAX ) );
        $domains = array_values( array_unique( array_map( static fn( $d ): string => strtolower( (string) $d ), is_array( $domains ) ? $domains : [] ) ) );
        if ( [] === $domains ) {
            return 0;
        }
        $metrics = $this->c->get( DomainMetrics::class );
        $high    = [];
        foreach ( array_chunk( $domains, 200 ) as $chunk ) {
            // Up to 20 providers per domain.
            foreach ( $metrics->findBy( [ 'domain' => $chunk ], 'id', 'ASC', count( $chunk ) * 20 ) as $row ) {
                $m = $row['metrics'] ?? null;
                if ( ! is_array( $m ) || ! empty( $m['empty'] ) || ! isset( $m['authority'] ) || ! is_numeric( $m['authority'] ) ) {
                    continue;
                }
                if ( (float) $m['authority'] >= $min ) {
                    $high[ strtolower( (string) $row['domain'] ) ] = true;
                }
            }
        }
        return count( $high );
    }
}
