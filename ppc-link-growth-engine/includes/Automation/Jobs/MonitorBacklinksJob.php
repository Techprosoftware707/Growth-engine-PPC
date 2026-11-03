<?php
/**
 * Job: re-check backlinks that are due, continue on the next tick when more remain, then send the digest.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Monitoring\BacklinkMonitor;
use PPC\LinkGrowth\Monitoring\Notifier;
use PPC\LinkGrowth\Repositories\Backlinks;
use PPC\LinkGrowth\Support\TimeBudget;
use Throwable;

final class MonitorBacklinksJob extends AbstractJob {

    private const DEFAULT_MAX = 25;

    public static function type(): string {
        return 'monitor_backlinks';
    }

    public static function priority(): int {
        return 4;
    }

    /**
     * @param array<string,mixed> $payload { max?: int }
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        if ( ! $this->flag( $c, $payload, 'monitoring' ) ) {
            return $this->skipped( 'monitoring_disabled' );
        }
        $limits = $this->limits( $c );
        $stats  = [ 'checked' => 0, 'lost' => 0, 'changed' => 0, 'recovered' => 0, 'continued' => false, 'digest_sent' => false, 'notes' => [] ];
        $max    = isset( $payload['max'] ) ? max( 1, min( 100, (int) $payload['max'] ) ) : self::DEFAULT_MAX;

        if ( ! $limits->allow( 'backlink_checks' ) ) {
            $stats['notes'][] = 'Daily backlink check limit reached.';
        } elseif ( ! $budget->has( 4.0 ) ) {
            // Too little time to check anything: let the next tick pick the work up (digest below still runs).
            $stats['notes'][]   = 'Not enough time budget to check links; re-queued.';
            $stats['continued'] = null !== $this->continueLater( $c, $payload, 60 );
        } else {
            try {
                $r = $c->get( BacklinkMonitor::class )->checkDue( $budget, $max );
                foreach ( [ 'checked', 'lost', 'changed', 'recovered' ] as $k ) {
                    $stats[ $k ] = (int) ( $r[ $k ] ?? 0 );
                }
                if ( $stats['checked'] > 0 && $limits->allow( 'backlink_checks' ) && $this->moreDue( $c ) ) {
                    $stats['continued'] = null !== $this->continueLater( $c, $payload, 60 );
                }
            } catch ( Throwable $e ) {
                $this->logger( $c )->error( 'monitor', 'Backlink check run failed', $this->errorContext( $e ) );
                $stats['notes'][] = 'Backlink check failed: ' . $e->getMessage();
            }
        }

        // Daily digest of notifications (independent of whether links were due).
        try {
            if ( class_exists( Notifier::class ) ) {
                $notifier = $c->get( Notifier::class );
                if ( $notifier->digestDue() ) {
                    $stats['digest_sent'] = (bool) $notifier->sendDigest();
                }
            }
        } catch ( Throwable $e ) {
            $this->logger( $c )->warning( 'monitor', 'Digest failed', $this->errorContext( $e ) );
            $stats['notes'][] = 'Digest failed: ' . $e->getMessage();
        }

        return $this->result( $stats );
    }

    private function moreDue( Container $c ): bool {
        try {
            return [] !== $c->get( Backlinks::class )->dueForCheck( 1 );
        } catch ( Throwable $e ) {
            return false;
        }
    }
}
