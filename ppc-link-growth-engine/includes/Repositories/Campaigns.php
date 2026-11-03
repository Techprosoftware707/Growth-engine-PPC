<?php
/**
 * Outreach campaign repository.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Repositories;

final class Campaigns extends Repository {

    public const STATUS_DRAFT            = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED         = 'approved';
    public const STATUS_SENDING          = 'sending';
    public const STATUS_PAUSED           = 'paused';
    public const STATUS_COMPLETED        = 'completed';
    public const STATUS_ARCHIVED         = 'archived';

    protected string $table = 'campaigns';

    protected array $jsonColumns = [ 'settings', 'stats' ];

    protected array $sortable = [ 'id', 'name', 'status', 'approach', 'created_at', 'updated_at' ];

    /** @param array<string,mixed> $settings */
    public function create( string $name, string $approach, array $settings = [], ?int $userId = null ): int {
        $now = self::now();
        return $this->insert(
            [
                'name'        => mb_substr( $name, 0, 190 ),
                'approach'    => $approach,
                'status'      => self::STATUS_DRAFT,
                'daily_limit' => (int) ( $settings['daily_limit'] ?? 10 ),
                'settings'    => $settings,
                'stats'       => [],
                'created_by'  => $userId,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        );
    }

    public function setStatus( int $id, string $status, array $extra = [] ): void {
        $this->update( $id, array_merge( [ 'status' => $status, 'updated_at' => self::now() ], $extra ) );
    }

    /** @return array<int,array<string,mixed>> */
    public function sendable(): array {
        return $this->findBy( [ 'status' => [ self::STATUS_APPROVED, self::STATUS_SENDING ] ], 'id', 'ASC', 50 );
    }
}
