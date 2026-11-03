<?php
/**
 * Cached provider metrics per domain (credit conservation).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Repositories;

final class DomainMetrics extends Repository {

    protected string $table = 'domain_metrics';

    protected array $jsonColumns = [ 'metrics' ];

    protected array $sortable = [ 'id', 'domain', 'provider', 'fetched_at' ];

    /** @return array<string,mixed>|null metrics still within TTL */
    public function fresh( string $domain, string $provider ): ?array {
        $row = $this->findOneBy( [ 'domain' => $domain, 'provider' => $provider ] );
        if ( ! $row || $row['expires_at'] < self::now() ) {
            return null;
        }
        return is_array( $row['metrics'] ) ? $row['metrics'] : null;
    }

    /** @param array<string,mixed> $metrics */
    public function put( string $domain, string $provider, array $metrics, int $ttlDays ): void {
        $row  = $this->findOneBy( [ 'domain' => $domain, 'provider' => $provider ] );
        $data = [
            'metrics'    => $metrics,
            'fetched_at' => self::now(),
            'expires_at' => gmdate( 'Y-m-d H:i:s', time() + max( 1, $ttlDays ) * DAY_IN_SECONDS ),
        ];
        if ( $row ) {
            $this->update( (int) $row['id'], $data );
        } else {
            $this->insert( array_merge( [ 'domain' => $domain, 'provider' => $provider ], $data ) );
        }
    }

    /** @return array<string,array<string,mixed>> provider => metrics (fresh only) */
    public function allFresh( string $domain ): array {
        $out = [];
        foreach ( $this->findBy( [ 'domain' => $domain ], 'id', 'ASC', 20 ) as $row ) {
            if ( $row['expires_at'] >= self::now() && is_array( $row['metrics'] ) ) {
                $out[ (string) $row['provider'] ] = $row['metrics'];
            }
        }
        return $out;
    }
}
