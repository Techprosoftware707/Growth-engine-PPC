<?php
/**
 * Error / activity log storage.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Repositories;

final class Logs extends Repository {

    protected string $table = 'logs';

    protected array $jsonColumns = [ 'context' ];

    protected array $sortable = [ 'id', 'level', 'channel', 'created_at' ];

    public function prune( int $days ): int {
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $n = $this->db()->query( $this->db()->prepare( "DELETE FROM {$this->table()} WHERE created_at < %s", $cutoff ) );
        return false === $n ? 0 : (int) $n;
    }

    /** @return array<string,int> */
    public function levelCountsSince( string $since ): array {
        return $this->countBy( 'level', 'created_at >= %s', [ $since ] );
    }
}
