<?php
/**
 * Combines metrics from all configured providers, with per-domain caching to conserve API credits.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations;

use PPC\LinkGrowth\Repositories\DomainMetrics;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;

final class MetricsAggregator {

    public function __construct(
        private ProviderRegistry $registry,
        private DomainMetrics $cache,
        private Settings $settings,
        private Logger $logger
    ) {}

    public function hasAnyProvider(): bool {
        return ! empty( $this->registry->metricsProviders() );
    }

    /**
     * @param callable|null $canSpend called before each live lookup; return false to skip fetching.
     * @return array<string,mixed> merged metrics (may be mostly empty when nothing is configured)
     */
    public function metricsFor( string $domain, bool $allowFetch = true, ?callable $canSpend = null ): array {
        $perProvider = $this->cache->allFresh( $domain );
        $missing     = [];
        foreach ( $this->registry->metricsProviders( false ) as $provider ) {
            $id = $provider->id();
            if ( isset( $perProvider[ $id ] ) ) {
                continue;
            }
            if ( ! $provider->isEnabled() ) {
                $missing[ $id ] = 'disabled';
                continue;
            }
            if ( ! $provider->isConfigured() ) {
                $missing[ $id ] = 'not configured';
                continue;
            }
            if ( ! $allowFetch || ( $canSpend && ! $canSpend( $id ) ) ) {
                $missing[ $id ] = 'lookup deferred (limit reached)';
                continue;
            }
            try {
                $m = $provider->domainMetrics( $domain );
            } catch ( \Throwable $e ) {
                $this->logger->warning( 'metrics', 'Provider exception: ' . $e->getMessage(), [ 'provider' => $id, 'domain' => $domain ] );
                $m = null;
            }
            if ( is_array( $m ) ) {
                $m['fetched_at']      = gmdate( 'c' );
                $perProvider[ $id ]   = $m;
                $this->cache->put( $domain, $id, $m, (int) $this->settings->get( 'providers.' . $id . '.cache_days', 30 ) );
            } else {
                $missing[ $id ] = $provider->lastError() ?: 'no data';
                // Cache the miss briefly so we do not burn credits retrying every run.
                $this->cache->put( $domain, $id, [ 'empty' => true, 'error' => $provider->lastError(), 'fetched_at' => gmdate( 'c' ) ], 3 );
            }
        }
        $merged            = self::merge( $perProvider );
        $merged['missing'] = $missing;
        return $merged;
    }

    /**
     * @param array<string,array<string,mixed>> $perProvider
     * @return array<string,mixed>
     */
    public static function merge( array $perProvider ): array {
        $out = [
            'authority'         => null,
            'authority_scores'  => [],
            'authority_labels'  => [],
            'organic_traffic'   => null,
            'traffic_source'    => null,
            'organic_keywords'  => null,
            'referring_domains' => null,
            'backlinks'         => null,
            'dofollow_backlinks' => null,
            'spam_score'        => null,
            'providers'         => [],
            'fetched_at'        => gmdate( 'c' ),
        ];
        foreach ( $perProvider as $id => $m ) {
            if ( ! is_array( $m ) || ! empty( $m['empty'] ) ) {
                continue;
            }
            $out['providers'][ $id ] = array_diff_key( $m, [ 'raw' => 1 ] );
            if ( isset( $m['authority'] ) ) {
                $out['authority_scores'][ $id ] = (int) $m['authority'];
                $out['authority_labels'][ $id ] = (string) ( $m['authority_label'] ?? $id );
            }
            if ( isset( $m['organic_traffic'] ) && ( null === $out['organic_traffic'] || (int) $m['organic_traffic'] > $out['organic_traffic'] ) ) {
                $out['organic_traffic'] = (int) $m['organic_traffic'];
                $out['traffic_source']  = $id . ( isset( $m['traffic_region'] ) ? ' (' . $m['traffic_region'] . ')' : '' );
            }
            foreach ( [ 'organic_keywords', 'referring_domains', 'backlinks', 'dofollow_backlinks' ] as $k ) {
                if ( isset( $m[ $k ] ) && ( null === $out[ $k ] || (int) $m[ $k ] > $out[ $k ] ) ) {
                    $out[ $k ] = (int) $m[ $k ];
                }
            }
            if ( isset( $m['spam_score'] ) ) {
                $out['spam_score'] = (int) $m['spam_score'];
            }
        }
        if ( ! empty( $out['authority_scores'] ) ) {
            $out['authority'] = (int) round( array_sum( $out['authority_scores'] ) / count( $out['authority_scores'] ) );
        }
        return $out;
    }
}
