<?php
/**
 * Ahrefs API v3 (Bearer token). Optional.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations\Providers;

use PPC\LinkGrowth\Integrations\Contracts\BacklinkSource;
use PPC\LinkGrowth\Integrations\Contracts\MetricsProvider;
use PPC\LinkGrowth\Support\Http\Response;

final class Ahrefs extends AbstractProvider implements MetricsProvider, BacklinkSource {

    public const BASE = 'https://api.ahrefs.com/v3';

    public function id(): string {
        return 'ahrefs';
    }

    public function label(): string {
        return 'Ahrefs';
    }

    public function isConfigured(): bool {
        return $this->secrets->has( 'ahrefs_api_key' );
    }

    private function request( string $path, array $query ): Response {
        $url = self::BASE . $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        return $this->client->api( 'GET', $url, [ 'Authorization' => 'Bearer ' . $this->secrets->get( 'ahrefs_api_key' ), 'Accept' => 'application/json' ] );
    }

    public function domainMetrics( string $domain ): ?array {
        if ( ! $this->isConfigured() ) {
            return null;
        }
        $date = gmdate( 'Y-m-d' );
        $out  = [ 'authority_label' => 'Domain Rating', 'raw' => [] ];
        $any  = false;

        $r = $this->request( '/site-explorer/domain-rating', [ 'target' => $domain, 'date' => $date ] );
        if ( $r->ok() ) {
            $dr = self::pick( (array) ( $r->json()['domain_rating'] ?? [] ), [ 'domain_rating' ] );
            if ( null !== $dr ) {
                $out['authority']        = (int) round( $dr );
                $out['raw']['ahrefs_rank'] = $r->json()['domain_rating']['ahrefs_rank'] ?? null;
                $any = true;
            }
        } else {
            $this->fail( 'Domain rating failed: ' . $this->describe( $r ) );
            if ( in_array( $r->status, [ 401, 403 ], true ) ) {
                return null;
            }
        }
        if ( $this->settings->get( 'providers.ahrefs.fetch_metrics', true ) ) {
            $r2 = $this->request( '/site-explorer/metrics', [ 'target' => $domain, 'date' => $date, 'mode' => 'subdomains' ] );
            if ( $r2->ok() ) {
                $m = (array) ( $r2->json()['metrics'] ?? [] );
                $t = self::pick( $m, [ 'org_traffic' ] );
                $k = self::pick( $m, [ 'org_keywords' ] );
                if ( null !== $t ) {
                    $out['organic_traffic'] = (int) round( $t );
                    $any = true;
                }
                if ( null !== $k ) {
                    $out['organic_keywords'] = (int) round( $k );
                }
            } else {
                $this->fail( 'Metrics failed: ' . $this->describe( $r2 ) );
            }
            $r3 = $this->request( '/site-explorer/backlinks-stats', [ 'target' => $domain, 'date' => $date, 'mode' => 'subdomains' ] );
            if ( $r3->ok() ) {
                $m  = (array) ( $r3->json()['metrics'] ?? [] );
                $rd = self::pick( $m, [ 'live_refdomains' ] );
                $bl = self::pick( $m, [ 'live' ] );
                if ( null !== $rd ) {
                    $out['referring_domains'] = (int) $rd;
                    $any = true;
                }
                if ( null !== $bl ) {
                    $out['backlinks'] = (int) $bl;
                }
            }
        }
        if ( $any ) {
            $this->succeed();
            return $out;
        }
        return null;
    }

    public function backlinks( string $domain, int $limit = 200, int $perDomain = 3 ): array {
        if ( ! $this->isConfigured() ) {
            return [];
        }
        $r = $this->request(
            '/site-explorer/all-backlinks',
            [
                'target'      => $domain,
                'mode'        => 'subdomains',
                'limit'       => max( 1, min( 1000, $limit ) ),
                'select'      => 'url_from,url_to,anchor,is_nofollow,first_seen,last_seen,domain_rating_source',
                'history'     => 'live',
                'aggregation' => $perDomain <= 1 ? '1_per_domain' : 'all',
            ]
        );
        if ( ! $r->ok() ) {
            $this->fail( 'Backlink list failed: ' . $this->describe( $r ) );
            return [];
        }
        $this->succeed();
        $out = [];
        foreach ( (array) ( $r->json()['backlinks'] ?? [] ) as $row ) {
            if ( ! is_array( $row ) || empty( $row['url_from'] ) ) {
                continue;
            }
            $out[] = [
                'source_url'       => (string) $row['url_from'],
                'target_url'       => (string) ( $row['url_to'] ?? '' ),
                'anchor'           => (string) ( $row['anchor'] ?? '' ),
                'nofollow'         => ! empty( $row['is_nofollow'] ),
                'first_seen'       => (string) ( $row['first_seen'] ?? '' ),
                'last_seen'        => (string) ( $row['last_seen'] ?? '' ),
                'source_authority' => isset( $row['domain_rating_source'] ) ? (int) round( (float) $row['domain_rating_source'] ) : null,
            ];
        }
        return $out;
    }
}
