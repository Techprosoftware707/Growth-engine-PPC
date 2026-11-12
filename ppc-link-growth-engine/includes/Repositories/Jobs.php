<?php
/**
 * Background job queue persisted in the database.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Repositories;

final class Jobs extends Repository {

    public const STATUS_QUEUED    = 'queued';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected string $table = 'jobs';

    protected array $jsonColumns = [ 'payload', 'result' ];

    protected array $sortable = [ 'id', 'type', 'status', 'priority', 'scheduled_at', 'started_at', 'finished_at', 'created_at' ];

    /** @param array<string,mixed> $payload */
    public function enqueue( string $type, array $payload = [], int $priority = 5, ?int $delaySeconds = null, int $maxAttempts = 3 ): int {
        $when = gmdate( 'Y-m-d H:i:s', time() + (int) ( $delaySeconds ?? 0 ) );
        return $this->insert(
            [
                'type'         => $type,
                'status'       => self::STATUS_QUEUED,
                'priority'     => max( 1, min( 9, $priority ) ),
                'payload'      => $payload,
                'attempts'     => 0,
                'max_attempts' => $maxAttempts,
                'scheduled_at' => $when,
                'created_at'   => self::now(),
            ]
        );
    }

    /** Whether a queued/running job of this type (optionally with identical payload) already exists. */
    public function hasPending( string $type, ?array $payload = null ): bool {
        $sql    = "SELECT id, payload FROM {$this->table()} WHERE type = %s AND status IN (%s,%s) LIMIT 50";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->db()->get_results( $this->db()->prepare( $sql, $type, self::STATUS_QUEUED, self::STATUS_RUNNING ), ARRAY_A );
        if ( empty( $rows ) ) {
            return false;
        }
        if ( null === $payload ) {
            return true;
        }
        $target = wp_json_encode( $payload );
        foreach ( $rows as $r ) {
            if ( wp_json_encode( \PPC\LinkGrowth\Support\Json::decode( $r['payload'] ) ) === $target ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Atomically claims the next due job for this runner.
     *
     * @param string|null $type           Only claim jobs of this type (null/'' = any type).
     * @param bool        $ignoreSchedule Also claim queued jobs whose scheduled_at is still in the future (a
     *                                    continuation queued for the next tick, run right away by a growth step).
     * @return array<string,mixed>|null
     */
    public function claimNext( string $token, ?string $type = null, bool $ignoreSchedule = false ): ?array {
        $where  = 'status = %s';
        $params = [ self::STATUS_QUEUED ];
        if ( ! $ignoreSchedule ) {
            $where   .= ' AND scheduled_at <= %s';
            $params[] = self::now();
        }
        if ( null !== $type && '' !== $type ) {
            $where   .= ' AND type = %s';
            $params[] = $type;
        }
        $sql = "SELECT id FROM {$this->table()} WHERE {$where} ORDER BY priority ASC, scheduled_at ASC, id ASC LIMIT 1";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $id = (int) $this->db()->get_var( $this->db()->prepare( $sql, $params ) );
        if ( ! $id ) {
            return null;
        }
        $upd = "UPDATE {$this->table()} SET status = %s, lock_token = %s, started_at = %s, attempts = attempts + 1 WHERE id = %d AND status = %s";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $n = $this->db()->query( $this->db()->prepare( $upd, self::STATUS_RUNNING, $token, self::now(), $id, self::STATUS_QUEUED ) );
        if ( 1 !== (int) $n ) {
            return null; // Someone else claimed it.
        }
        return $this->find( $id );
    }

    /** @param array<string,mixed> $result */
    public function complete( int $id, array $result ): void {
        $this->update( $id, [ 'status' => self::STATUS_COMPLETED, 'result' => $result, 'finished_at' => self::now(), 'error' => null, 'lock_token' => '' ] );
    }

    public function fail( int $id, string $error, bool $retry, int $delaySeconds = 300 ): void {
        $data = [ 'error' => mb_substr( $error, 0, 2000 ), 'finished_at' => self::now(), 'lock_token' => '' ];
        if ( $retry ) {
            $data['status']       = self::STATUS_QUEUED;
            $data['scheduled_at'] = gmdate( 'Y-m-d H:i:s', time() + $delaySeconds );
            $data['finished_at']  = null;
        } else {
            $data['status'] = self::STATUS_FAILED;
        }
        $this->update( $id, $data );
    }

    /** Re-queue jobs stuck in "running" for longer than $seconds (e.g. after a fatal error). */
    public function recoverStale( int $seconds = 900 ): int {
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $seconds );
        $sql    = "UPDATE {$this->table()} SET status = CASE WHEN attempts >= max_attempts THEN %s ELSE %s END, lock_token = '', error = CONCAT(COALESCE(error,''), ' [recovered: stale run]') WHERE status = %s AND started_at < %s";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $n = $this->db()->query( $this->db()->prepare( $sql, self::STATUS_FAILED, self::STATUS_QUEUED, self::STATUS_RUNNING, $cutoff ) );
        return false === $n ? 0 : (int) $n;
    }

    public function cancelQueued( ?string $type = null ): int {
        $sql    = "UPDATE {$this->table()} SET status = %s, finished_at = %s WHERE status = %s";
        $params = [ self::STATUS_CANCELLED, self::now(), self::STATUS_QUEUED ];
        if ( $type ) {
            $sql     .= ' AND type = %s';
            $params[] = $type;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $n = $this->db()->query( $this->db()->prepare( $sql, $params ) );
        return false === $n ? 0 : (int) $n;
    }

    public function prune( int $days = 14 ): int {
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $sql    = "DELETE FROM {$this->table()} WHERE status IN (%s,%s,%s) AND created_at < %s";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $n = $this->db()->query( $this->db()->prepare( $sql, self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED, $cutoff ) );
        return false === $n ? 0 : (int) $n;
    }

    /** @return array<string,int> */
    public function statusCounts(): array {
        return $this->countBy( 'status' );
    }
}
