<?php
/**
 * Audit log of every automated and manual action.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Repositories;

use PPC\LinkGrowth\Support\Redactor;

final class AuditLog extends Repository {

    protected string $table = 'audit_log';

    protected array $jsonColumns = [ 'details' ];

    protected array $sortable = [ 'id', 'actor', 'action', 'object_type', 'created_at' ];

    /** @param array<string,mixed> $details */
    public function record( string $actor, string $action, string $objectType = '', int $objectId = 0, array $details = [] ): int {
        try {
            return $this->insert(
                [
                    'actor'       => mb_substr( $actor, 0, 60 ),
                    'action'      => mb_substr( $action, 0, 60 ),
                    'object_type' => mb_substr( $objectType, 0, 40 ),
                    'object_id'   => $objectId,
                    'details'     => Redactor::redact( $details ),
                    'created_at'  => self::now(),
                ]
            );
        } catch ( \Throwable $e ) {
            return 0;
        }
    }

    public static function currentActor(): string {
        if ( function_exists( 'get_current_user_id' ) && get_current_user_id() > 0 ) {
            return 'user:' . get_current_user_id();
        }
        return 'system';
    }

    public function prune( int $days ): int {
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 7, $days ) * DAY_IN_SECONDS );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $n = $this->db()->query( $this->db()->prepare( "DELETE FROM {$this->table()} WHERE created_at < %s", $cutoff ) );
        return false === $n ? 0 : (int) $n;
    }
}
