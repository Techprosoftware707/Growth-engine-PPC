<?php
/**
 * Submission repository (listing submissions and review queue).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Repositories;

use PPC\LinkGrowth\Support\Json;
use PPC\LinkGrowth\Support\Url;

final class Submissions extends Repository {

    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_NEEDS_MANUAL   = 'needs_manual';
    public const STATUS_APPROVED       = 'approved';
    public const STATUS_QUEUED         = 'queued';
    public const STATUS_SUBMITTED      = 'submitted';
    public const STATUS_CONFIRMED      = 'confirmed';
    public const STATUS_FAILED         = 'failed';
    public const STATUS_REJECTED       = 'rejected';
    public const STATUS_DUPLICATE      = 'duplicate';

    public const STATUSES = [
        self::STATUS_PENDING_REVIEW,
        self::STATUS_NEEDS_MANUAL,
        self::STATUS_APPROVED,
        self::STATUS_QUEUED,
        self::STATUS_SUBMITTED,
        self::STATUS_CONFIRMED,
        self::STATUS_FAILED,
        self::STATUS_REJECTED,
        self::STATUS_DUPLICATE,
    ];

    protected string $table = 'submissions';

    protected array $jsonColumns = [ 'payload', 'response' ];

    protected array $sortable = [ 'id', 'status', 'method', 'created_at', 'updated_at', 'submitted_at', 'confirmed_at', 'prospect_id' ];

    public function existsForTarget( int $prospectId, string $targetUrl ): ?array {
        return $this->findOneBy( [ 'prospect_id' => $prospectId, 'target_hash' => Url::hash( $targetUrl ) ] );
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create( int $prospectId, ?int $opportunityId, string $targetUrl, array $data ): int {
        $now = self::now();
        return $this->insert(
            array_merge(
                [
                    'prospect_id'    => $prospectId,
                    'opportunity_id' => $opportunityId,
                    'target_url'     => mb_substr( Url::normalize( $targetUrl ) ?: $targetUrl, 0, 700 ),
                    'target_hash'    => Url::hash( $targetUrl ),
                    'status'         => self::STATUS_PENDING_REVIEW,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ],
                $data
            )
        );
    }

    public function setStatus( int $id, string $status, array $extra = [] ): void {
        $this->update( $id, array_merge( [ 'status' => $status, 'updated_at' => self::now() ], $extra ) );
    }

    /** @return array<int,array<string,mixed>> */
    public function readyToProcess( int $limit ): array {
        $sql = "SELECT * FROM {$this->table()} WHERE status IN (%s,%s) AND (scheduled_at IS NULL OR scheduled_at <= %s) ORDER BY id ASC LIMIT %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->db()->get_results( $this->db()->prepare( $sql, self::STATUS_APPROVED, self::STATUS_QUEUED, self::now(), $limit ), ARRAY_A );
        return array_map( [ $this, 'hydrate' ], $rows ?: [] );
    }

    /**
     * Atomically claims a due approved/queued row for one queue run. The UPDATE matches only while the
     * row is still approved/queued, due and unchanged since it was read (same updated_at), and moves
     * scheduled_at to the end of the lease so no other run (and no compare-and-set reject) acts on it.
     *
     * @param array<string,mixed> $row      Row as just read.
     * @param int                 $until    Unix time the lease ends.
     * @param array<string,mixed> $response Response column to store (carrying the claim marker).
     */
    public function claimForSend( array $row, int $until, array $response ): bool {
        $now = self::now();
        $sql = "UPDATE {$this->table()} SET scheduled_at = %s, updated_at = %s, response = %s WHERE id = %d AND status IN (%s,%s) AND (scheduled_at IS NULL OR scheduled_at <= %s) AND updated_at = %s";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $n = $this->db()->query( $this->db()->prepare( $sql, gmdate( 'Y-m-d H:i:s', $until ), $now, Json::encode( $response ), (int) $row['id'], self::STATUS_APPROVED, self::STATUS_QUEUED, $now, (string) ( $row['updated_at'] ?? '' ) ) );
        return 1 === (int) $n;
    }

    /**
     * Compare-and-set status change for a row in the automated queue: applies only while the row still
     * has the status and schedule that were read (a queue run's claim always moves scheduled_at), and
     * clears scheduled_at.
     *
     * @param array<string,mixed> $row Row as just read.
     */
    public function casStatusFromQueue( array $row, string $status ): bool {
        $at     = trim( (string) ( $row['scheduled_at'] ?? '' ) );
        $params = [ $status, self::now(), (int) $row['id'], (string) ( $row['status'] ?? '' ) ];
        if ( '' === $at ) {
            $sql = "UPDATE {$this->table()} SET status = %s, updated_at = %s, scheduled_at = NULL WHERE id = %d AND status = %s AND scheduled_at IS NULL";
        } else {
            $sql      = "UPDATE {$this->table()} SET status = %s, updated_at = %s, scheduled_at = NULL WHERE id = %d AND status = %s AND scheduled_at = %s";
            $params[] = $at;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $n = $this->db()->query( $this->db()->prepare( $sql, $params ) );
        return 1 === (int) $n;
    }

    /** @return array<int,array<string,mixed>> */
    public function dueForVerification( int $limit ): array {
        $sql = "SELECT * FROM {$this->table()} WHERE status = %s AND next_verify_at IS NOT NULL AND next_verify_at <= %s ORDER BY next_verify_at ASC LIMIT %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->db()->get_results( $this->db()->prepare( $sql, self::STATUS_SUBMITTED, self::now(), $limit ), ARRAY_A );
        return array_map( [ $this, 'hydrate' ], $rows ?: [] );
    }

    /** @return array<string,int> */
    public function statusCounts(): array {
        return $this->countBy( 'status' );
    }
}
