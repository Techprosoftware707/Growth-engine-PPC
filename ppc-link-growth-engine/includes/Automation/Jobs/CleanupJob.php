<?php
/**
 * Job: housekeeping — prune logs, audit entries, jobs, stale runs, expired transients and metric caches.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\DomainMetrics;
use PPC\LinkGrowth\Repositories\Jobs;
use PPC\LinkGrowth\Repositories\Logs;
use PPC\LinkGrowth\Support\TimeBudget;
use Throwable;

final class CleanupJob extends AbstractJob {

    private const JOB_RETENTION_DAYS   = 14;
    private const STALE_RUN_SECONDS    = 900;
    private const METRICS_EXPIRED_DAYS = 60;
    private const TRANSIENT_PREFIX     = 'ppclg_';

    public static function type(): string {
        return 'cleanup';
    }

    public static function priority(): int {
        return 8;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        $settings = $this->settings( $c );
        $logger   = $this->logger( $c );
        $stats    = [ 'logs_pruned' => 0, 'audit_pruned' => 0, 'jobs_pruned' => 0, 'jobs_recovered' => 0, 'transients_deleted' => 0, 'domain_metrics_deleted' => 0, 'errors' => [] ];

        $steps = [
            'logs_pruned'            => static fn(): int => $c->get( Logs::class )->prune( max( 1, (int) $settings->get( 'advanced.log_retention_days', 30 ) ) ),
            'audit_pruned'           => static fn(): int => $c->get( AuditLog::class )->prune( max( 7, (int) $settings->get( 'advanced.audit_retention_days', 365 ) ) ),
            'jobs_pruned'            => static fn(): int => $c->get( Jobs::class )->prune( self::JOB_RETENTION_DAYS ),
            'jobs_recovered'         => static fn(): int => $c->get( Jobs::class )->recoverStale( self::STALE_RUN_SECONDS ),
            'transients_deleted'     => fn(): int => $this->deleteExpiredTransients(),
            'domain_metrics_deleted' => fn(): int => $this->deleteExpiredMetrics( $c ),
        ];

        foreach ( $steps as $key => $step ) {
            if ( ! $budget->has( 1.0 ) ) {
                $stats['errors'][] = sprintf( 'Skipped %s: time budget exhausted.', $key );
                continue;
            }
            try {
                $stats[ $key ] = (int) $step();
            } catch ( Throwable $e ) {
                $stats['errors'][] = sprintf( '%s failed: %s', $key, $e->getMessage() );
                $logger->error( 'cleanup', 'Cleanup step failed', array_merge( [ 'step' => $key ], $this->errorContext( $e ) ) );
            }
        }

        $this->audit( $c )->record( 'maintenance.cleanup', 'system', 0, $stats );
        return $this->result( $stats );
    }

    /** Deletes expired transients created by this plugin (options table only; object caches expire on their own). */
    private function deleteExpiredTransients(): int {
        if ( wp_using_ext_object_cache() ) {
            return 0;
        }
        global $wpdb;
        $like  = $wpdb->esc_like( '_transient_timeout_' . self::TRANSIENT_PREFIX ) . '%';
        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $like,
                time()
            )
        );
        $deleted = 0;
        foreach ( (array) $names as $name ) {
            $key = substr( (string) $name, strlen( '_transient_timeout_' ) );
            if ( '' !== $key && delete_transient( $key ) ) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /** Removes cached provider metrics whose TTL expired more than 60 days ago. */
    private function deleteExpiredMetrics( Container $c ): int {
        global $wpdb;
        $table  = $c->get( DomainMetrics::class )->table();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - self::METRICS_EXPIRED_DAYS * DAY_IN_SECONDS );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $n = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %s", $cutoff ) );
        return false === $n ? 0 : (int) $n;
    }
}
