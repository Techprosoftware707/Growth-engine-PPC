<?php
/**
 * Log of discovery search queries (prevents repeating queries too often).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Repositories;

final class SearchLog extends Repository {

    protected string $table = 'search_log';

    protected array $sortable = [ 'id', 'provider', 'run_at', 'results', 'new_prospects' ];

    public static function hash( string $query ): string {
        return sha1( strtolower( trim( preg_replace( '/\s+/', ' ', $query ) ?? $query ) ) );
    }

    public function ranWithin( string $query, int $days ): bool {
        $since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $sql   = "SELECT COUNT(*) FROM {$this->table()} WHERE query_hash = %s AND run_at >= %s";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $this->db()->get_var( $this->db()->prepare( $sql, self::hash( $query ), $since ) ) > 0;
    }

    public function record( string $provider, string $query, int $results, int $newProspects ): int {
        return $this->insert(
            [
                'provider'      => mb_substr( $provider, 0, 30 ),
                'query'         => mb_substr( $query, 0, 500 ),
                'query_hash'    => self::hash( $query ),
                'results'       => $results,
                'new_prospects' => $newProspects,
                'run_at'        => self::now(),
            ]
        );
    }

    public function countSince( string $since ): int {
        $sql = "SELECT COUNT(*) FROM {$this->table()} WHERE run_at >= %s";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $this->db()->get_var( $this->db()->prepare( $sql, $since ) );
    }
}
