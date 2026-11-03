<?php
/**
 * Backlink repository.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Repositories;

use PPC\LinkGrowth\Support\Url;

final class Backlinks extends Repository {

    public const STATUS_PENDING    = 'pending';
    public const STATUS_ACTIVE     = 'active';
    public const STATUS_LOST       = 'lost';
    public const STATUS_BROKEN     = 'broken';
    public const STATUS_REDIRECTED = 'redirected';
    public const STATUS_UNVERIFIED = 'unverified';

    protected string $table = 'backlinks';

    protected array $jsonColumns = [ 'changes' ];

    protected array $sortable = [ 'id', 'source_domain', 'status', 'rel', 'placement', 'first_seen', 'last_checked', 'last_seen_live', 'lost_at', 'quality_score', 'next_check_at' ];

    /**
     * Insert or update by (source_url, target_url). Returns [id, created].
     *
     * @param array<string,mixed> $data
     * @return array{0:int,1:bool}
     */
    public function upsert( string $sourceUrl, string $targetUrl, array $data = [] ): array {
        $sourceUrl = Url::normalize( $sourceUrl );
        $targetUrl = Url::normalize( $targetUrl ) ?: $targetUrl;
        $sh        = sha1( $sourceUrl );
        $th        = sha1( $targetUrl );
        $row       = $this->findOneBy( [ 'source_hash' => $sh, 'target_hash' => $th ] );
        $now       = self::now();
        if ( $row ) {
            $this->update( (int) $row['id'], array_merge( [ 'updated_at' => $now ], $data ) );
            return [ (int) $row['id'], false ];
        }
        $id = $this->insert(
            array_merge(
                [
                    'source_domain' => Url::registrableDomain( $sourceUrl ),
                    'source_url'    => mb_substr( $sourceUrl, 0, 700 ),
                    'source_hash'   => $sh,
                    'target_url'    => mb_substr( $targetUrl, 0, 700 ),
                    'target_hash'   => $th,
                    'status'        => self::STATUS_PENDING,
                    'origin'        => 'manual',
                    'first_seen'    => $now,
                    'next_check_at' => $now,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ],
                $data
            )
        );
        return [ $id, true ];
    }

    /** @return array<int,array<string,mixed>> */
    public function dueForCheck( int $limit ): array {
        $sql = "SELECT * FROM {$this->table()} WHERE (next_check_at IS NULL OR next_check_at <= %s) AND status <> %s ORDER BY next_check_at ASC, id ASC LIMIT %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->db()->get_results( $this->db()->prepare( $sql, self::now(), self::STATUS_UNVERIFIED, $limit ), ARRAY_A );
        return array_map( [ $this, 'hydrate' ], $rows ?: [] );
    }

    /** @return array<string,int> */
    public function statusCounts(): array {
        return $this->countBy( 'status' );
    }

    public function referringDomainCount( string $status = self::STATUS_ACTIVE ): int {
        $sql = "SELECT COUNT(DISTINCT source_domain) FROM {$this->table()} WHERE status = %s";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $this->db()->get_var( $this->db()->prepare( $sql, $status ) );
    }

    public function highAuthorityDomainCount( int $minQuality = 60 ): int {
        $sql = "SELECT COUNT(DISTINCT source_domain) FROM {$this->table()} WHERE status = %s AND quality_score >= %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $this->db()->get_var( $this->db()->prepare( $sql, self::STATUS_ACTIVE, $minQuality ) );
    }

    /** @return array<string,int> anchor => count (top N) */
    public function anchorDistribution( int $limit = 12 ): array {
        $sql = "SELECT anchor_text, COUNT(*) AS c FROM {$this->table()} WHERE status = %s GROUP BY anchor_text ORDER BY c DESC LIMIT %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->db()->get_results( $this->db()->prepare( $sql, self::STATUS_ACTIVE, $limit ), ARRAY_A );
        $out  = [];
        foreach ( $rows ?: [] as $r ) {
            $out[ '' === $r['anchor_text'] ? '(empty / image)' : $r['anchor_text'] ] = (int) $r['c'];
        }
        return $out;
    }

    /** @return array<string,int> */
    public function relDistribution(): array {
        return $this->countBy( 'rel', 'status = %s', [ self::STATUS_ACTIVE ] );
    }

    /** New vs lost per week for the last N weeks. @return array<int,array{week:string,new:int,lost:int}> */
    public function weeklyTrend( int $weeks = 12 ): array {
        $since = gmdate( 'Y-m-d 00:00:00', time() - $weeks * WEEK_IN_SECONDS );
        $sqlN  = "SELECT DATE_FORMAT(first_seen, '%%x-%%v') AS wk, COUNT(*) AS c FROM {$this->table()} WHERE first_seen >= %s GROUP BY wk";
        $sqlL  = "SELECT DATE_FORMAT(lost_at, '%%x-%%v') AS wk, COUNT(*) AS c FROM {$this->table()} WHERE lost_at IS NOT NULL AND lost_at >= %s GROUP BY wk";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $new = $this->db()->get_results( $this->db()->prepare( $sqlN, $since ), ARRAY_A ) ?: [];
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $lost = $this->db()->get_results( $this->db()->prepare( $sqlL, $since ), ARRAY_A ) ?: [];
        $map  = [];
        for ( $i = $weeks - 1; $i >= 0; $i-- ) {
            $wk         = gmdate( 'o-W', time() - $i * WEEK_IN_SECONDS );
            $map[ $wk ] = [ 'week' => $wk, 'new' => 0, 'lost' => 0 ];
        }
        foreach ( $new as $r ) {
            if ( isset( $map[ $r['wk'] ] ) ) {
                $map[ $r['wk'] ]['new'] = (int) $r['c'];
            }
        }
        foreach ( $lost as $r ) {
            if ( isset( $map[ $r['wk'] ] ) ) {
                $map[ $r['wk'] ]['lost'] = (int) $r['c'];
            }
        }
        return array_values( $map );
    }

    public function countSince( string $column, string $since, string $status = '' ): int {
        if ( ! in_array( $column, [ 'first_seen', 'lost_at', 'last_checked' ], true ) ) {
            return 0;
        }
        $sql    = "SELECT COUNT(*) FROM {$this->table()} WHERE {$column} >= %s";
        $params = [ $since ];
        if ( '' !== $status ) {
            $sql     .= ' AND status = %s';
            $params[] = $status;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $this->db()->get_var( $this->db()->prepare( $sql, $params ) );
    }

    /** @return array<int,array<string,mixed>> */
    public function forDomain( string $domain ): array {
        return $this->findBy( [ 'source_domain' => Url::registrableDomain( $domain ) ], 'id', 'DESC', 50 );
    }
}
