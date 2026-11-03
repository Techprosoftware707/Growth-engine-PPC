<?php
/**
 * Job: import backlinks from SEO providers and GA4 referral sources.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Monitoring\BacklinkImporter;
use PPC\LinkGrowth\Support\TimeBudget;
use Throwable;

final class ImportBacklinksJob extends AbstractJob {

    public static function type(): string {
        return 'import_backlinks';
    }

    public static function priority(): int {
        return 6;
    }

    /**
     * @param array<string,mixed> $payload { force?: bool, days?: int }
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        if ( ! $this->flag( $c, $payload, 'imports' ) ) {
            return $this->skipped( 'imports_disabled' );
        }
        $importer = $c->get( BacklinkImporter::class );
        $force    = ! empty( $payload['force'] );
        if ( ! $force && ! $importer->isDue() ) {
            return $this->skipped( 'not_due' );
        }
        if ( ! $budget->has( 5.0 ) ) {
            return $this->deferred( $c, $payload );
        }
        $limits = $this->limits( $c );
        $limit  = max( 50, min( 5000, (int) $this->settings( $c )->get( 'monitoring.import_limit', 500 ) ) );
        $days   = isset( $payload['days'] ) ? max( 1, min( 90, (int) $payload['days'] ) ) : 28;
        $stats  = [ 'providers' => null, 'analytics' => null, 'errors' => [] ];

        if ( ! $limits->allow( 'provider_lookups' ) ) {
            $stats['errors'][] = 'Daily provider lookup limit reached; provider import deferred.';
        } else {
            try {
                $r = $importer->importFromProviders( $limit );
                $stats['providers'] = $r;
                $used = count( (array) ( $r['providers'] ?? [] ) );
                if ( $used > 0 ) {
                    $limits->spend( 'provider_lookups', $used );
                }
            } catch ( Throwable $e ) {
                $this->logger( $c )->error( 'import', 'Provider backlink import failed', $this->errorContext( $e ) );
                $stats['errors'][] = 'Provider import failed: ' . $e->getMessage();
            }
        }

        if ( ! $budget->has( 5.0 ) ) {
            $stats['errors'][] = 'Not enough time budget for analytics import.';
        } else {
            try {
                $stats['analytics'] = $importer->importFromAnalytics( $days );
            } catch ( Throwable $e ) {
                $this->logger( $c )->error( 'import', 'Analytics referral import failed', $this->errorContext( $e ) );
                $stats['errors'][] = 'Analytics import failed: ' . $e->getMessage();
            }
        }

        $this->audit( $c )->record(
            'backlinks.imported',
            'backlink',
            0,
            [
                'providers'     => (array) ( $stats['providers']['providers'] ?? [] ),
                'records'       => (int) ( $stats['providers']['records'] ?? 0 ),
                'new'           => (int) ( $stats['providers']['new'] ?? 0 ),
                'analytics_new' => (int) ( $stats['analytics']['new'] ?? 0 ),
                'forced'        => $force,
                'errors'        => $stats['errors'],
            ]
        );
        return $this->result( $stats );
    }
}
