<?php
/**
 * SE Ranking Data API (https://api.seranking.com, "Authorization: Token <key>").
 *
 * Credit costs (per SE Ranking docs): backlinks/authority/domain 5 per record,
 * domain/overview/db 100 per request, backlinks/summary 100 per record, backlinks/all 1 per record.
 * Results are cached by MetricsAggregator to conserve credits.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations\Providers;

use PPC\LinkGrowth\Integrations\Contracts\BacklinkSource;
use PPC\LinkGrowth\Integrations\Contracts\MetricsProvider;
use PPC\LinkGrowth\Support\Http\Response;

final class SeRanking extends AbstractProvider implements MetricsProvider, BacklinkSource {

    public const BASE = 'https://api.seranking.com/v1';

    public function id(): string {
        return 'seranking';
    }

    public function label(): string {
        return 'SE Ranking';
    }

    public function isConfigured(): bool {
        return $this->secrets->has( 'seranking_api_key' );
    }

    private function request( string $path, array $query = [] ): Response {
        $url = self::BASE . $path . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );
        usleep( 150000 ); // stay well under the 10 req/s limit
        return $this->client->api( 'GET', $url, [ 'Authorization' => 'Token ' . $this->secrets->get( 'seranking_api_key' ), 'Accept' => 'application/json' ] );
    }

    /** @return array<string,mixed>|null */
    public function subscription(): ?array {
        if ( ! $this->isConfigured() ) {
            return null;
        }
        $r = $this->request( '/account/subscription' );
        if ( ! $r->ok() ) {
            $this->fail( 'Subscription check failed: ' . $this->describe( $r ) );
            return null;
        }
        $this->succeed();
        $j = $r->json();
        return [
            'status'          => (string) ( $j['status'] ?? '' ),
            'expiration_date' => (string) ( $j['expiration_date'] ?? '' ),
            'units_limit'     => (int) ( $j['units_limit'] ?? 0 ),
            'units_left'      => (int) ( $j['units_left'] ?? 0 ),
        ];
    }

    public function domainMetrics( string $domain ): ?array {
        if ( ! $this->isConfigured() ) {
            return null;
        }
        $out = [ 'authority_label' => 'Domain InLink Rank', 'raw' => [] ];
        $any = false;

        // Authority (5 credits).
        $r = $this->request( '/backlinks/authority/domain', [ 'target' => $domain ] );
        if ( $r->ok() ) {
            $j = $r->json();
            $row = $j['pages'][0] ?? ( $j[0] ?? null );
            $ir  = is_array( $row ) ? self::pick( $row, [ 'domain_inlink_rank', 'inlink_rank', 'domain_trust' ] ) : null;
            if ( null !== $ir ) {
                $out['authority']       = (int) round( $ir );
                $out['raw']['authority'] = $row;
                $any = true;
            }
        } elseif ( 429 === $r->status ) {
            $this->fail( 'Rate limited by SE Ranking.' );
            return null;
        } else {
            $this->fail( 'Authority lookup failed: ' . $this->describe( $r ) );
        }

        // Regional organic overview (100 credits) — optional.
        if ( $this->settings->get( 'providers.seranking.fetch_overview', true ) ) {
            $source = strtolower( (string) $this->settings->get( 'providers.seranking.region', 'ca' ) ) ?: 'ca';
            $r2     = $this->request( '/domain/overview/db', [ 'domain' => $domain, 'source' => $source, 'with_subdomains' => 1 ] );
            if ( $r2->ok() ) {
                $j  = $r2->json();
                $org = is_array( $j['organic'] ?? null ) ? $j['organic'] : [];
                $tr  = self::pick( $org, [ 'traffic_sum', 'traffic', 'organic_traffic' ] );
                $kw  = self::pick( $org, [ 'keywords_count', 'keywords', 'organic_keywords' ] );
                if ( null !== $tr ) {
                    $out['organic_traffic'] = (int) round( $tr );
                    $out['traffic_region']  = $source;
                    $any = true;
                }
                if ( null !== $kw ) {
                    $out['organic_keywords'] = (int) round( $kw );
                    $any = true;
                }
                $out['raw']['overview'] = array_intersect_key( $org, array_flip( [ 'traffic_sum', 'keywords_count', 'price_sum', 'top1_5', 'top6_10', 'top11_20', 'year', 'month' ] ) );
            } else {
                $this->fail( 'Domain overview failed: ' . $this->describe( $r2 ) );
            }
        }

        // Backlink summary (100 credits) — optional, off by default.
        if ( $this->settings->get( 'providers.seranking.fetch_summary', false ) ) {
            $r3 = $this->request( '/backlinks/summary', [ 'target' => $domain, 'mode' => 'domain' ] );
            if ( $r3->ok() ) {
                $j   = $r3->json();
                $row = is_array( $j['summary'] ?? null ) ? ( $j['summary'][0] ?? $j['summary'] ) : $j;
                if ( is_array( $row ) ) {
                    $bl = self::pick( $row, [ 'backlinks', 'total_backlinks', 'backlinks_count', 'total' ] );
                    $rd = self::pick( $row, [ 'refdomains', 'referring_domains', 'refdomains_count', 'domains' ] );
                    $nf = self::pick( $row, [ 'nofollow_backlinks', 'nofollow', 'backlinks_nofollow' ] );
                    if ( null !== $bl ) {
                        $out['backlinks'] = (int) $bl;
                        if ( null !== $nf ) {
                            $out['dofollow_backlinks'] = (int) max( 0, $bl - $nf );
                        }
                        $any = true;
                    }
                    if ( null !== $rd ) {
                        $out['referring_domains'] = (int) $rd;
                        $any = true;
                    }
                    $out['raw']['summary'] = array_slice( $row, 0, 25, true );
                }
            } else {
                $this->fail( 'Backlink summary failed: ' . $this->describe( $r3 ) );
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
        $r = $this->request( '/backlinks/all', [ 'target' => $domain, 'mode' => 'domain', 'limit' => max( 1, min( 10000, $limit ) ), 'per_domain' => max( 1, min( 100, $perDomain ) ) ] );
        if ( ! $r->ok() ) {
            $this->fail( 'Backlink list failed: ' . $this->describe( $r ) );
            return [];
        }
        $this->succeed();
        $j    = $r->json();
        $rows = $j['backlinks'] ?? ( $j['data'] ?? ( $j['items'] ?? ( isset( $j[0] ) ? $j : [] ) ) );
        $out  = [];
        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $from = (string) ( $row['url_from'] ?? $row['source_url'] ?? $row['from_url'] ?? '' );
            $to   = (string) ( $row['url_to'] ?? $row['target_url'] ?? $row['to_url'] ?? '' );
            if ( '' === $from ) {
                continue;
            }
            $rel  = strtolower( (string) ( $row['rel'] ?? '' ) );
            $nf   = isset( $row['nofollow'] ) ? (bool) $row['nofollow'] : ( isset( $row['is_nofollow'] ) ? (bool) $row['is_nofollow'] : str_contains( $rel, 'nofollow' ) );
            $out[] = [
                'source_url'       => $from,
                'target_url'       => $to,
                'anchor'           => (string) ( $row['anchor'] ?? $row['anchor_text'] ?? '' ),
                'nofollow'         => $nf,
                'first_seen'       => (string) ( $row['first_seen'] ?? $row['date_found'] ?? $row['first_visited'] ?? '' ),
                'last_seen'        => (string) ( $row['last_visited'] ?? $row['last_seen'] ?? '' ),
                'source_authority' => isset( $row['domain_inlink_rank'] ) ? (int) $row['domain_inlink_rank'] : null,
            ];
        }
        return $out;
    }

    /** Competitor suggestions for a domain (100 credits). @return string[] */
    public function competitors( string $domain ): array {
        if ( ! $this->isConfigured() ) {
            return [];
        }
        $source = strtolower( (string) $this->settings->get( 'providers.seranking.region', 'ca' ) ) ?: 'ca';
        $r      = $this->request( '/domain/competitors', [ 'domain' => $domain, 'source' => $source ] );
        if ( ! $r->ok() ) {
            $this->fail( 'Competitor lookup failed: ' . $this->describe( $r ) );
            return [];
        }
        $this->succeed();
        $j    = $r->json();
        $rows = $j['competitors'] ?? ( $j['data'] ?? ( isset( $j[0] ) ? $j : [] ) );
        $out  = [];
        foreach ( (array) $rows as $row ) {
            $d = is_array( $row ) ? (string) ( $row['domain'] ?? $row['competitor'] ?? $row['base_domain'] ?? '' ) : (string) $row;
            if ( '' !== $d ) {
                $out[] = strtolower( $d );
            }
        }
        return array_values( array_unique( $out ) );
    }
}
