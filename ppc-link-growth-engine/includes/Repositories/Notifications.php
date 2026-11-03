<?php
/**
 * In-dashboard notifications.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Repositories;

final class Notifications extends Repository {

    protected string $table = 'notifications';

    protected array $jsonColumns = [ 'data' ];

    protected array $sortable = [ 'id', 'severity', 'type', 'is_read', 'created_at' ];

    /** @param array<string,mixed> $data */
    public function add( string $type, string $severity, string $title, string $message, array $data = [] ): int {
        return $this->insert(
            [
                'type'       => mb_substr( $type, 0, 40 ),
                'severity'   => in_array( $severity, [ 'info', 'success', 'warning', 'critical' ], true ) ? $severity : 'info',
                'title'      => mb_substr( $title, 0, 255 ),
                'message'    => $message,
                'data'       => $data,
                'is_read'    => 0,
                'created_at' => self::now(),
            ]
        );
    }

    public function unreadCount(): int {
        return $this->count( [ 'is_read' => 0 ] );
    }

    public function markAllRead(): void {
        $this->db()->update( $this->table(), [ 'is_read' => 1 ], [ 'is_read' => 0 ] );
    }

    /** @return array<int,array<string,mixed>> */
    public function recent( int $limit = 20 ): array {
        return $this->findBy( [], 'id', 'DESC', $limit );
    }

    /** @return array<int,array<string,mixed>> */
    public function unreadSince( string $since ): array {
        $sql = "SELECT * FROM {$this->table()} WHERE created_at >= %s ORDER BY id DESC LIMIT 200";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->db()->get_results( $this->db()->prepare( $sql, $since ), ARRAY_A );
        return array_map( [ $this, 'hydrate' ], $rows ?: [] );
    }
}
