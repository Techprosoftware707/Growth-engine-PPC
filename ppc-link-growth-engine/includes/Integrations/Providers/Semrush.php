<?php
/**
 * Semrush Analytics API (CSV responses). Optional.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations\Providers;

use PPC\LinkGrowth\Integrations\Contracts\BacklinkSource;
use PPC\LinkGrowth\Integrations\Contracts\MetricsProvider;
use PPC\LinkGrowth\Support\Http\Response;

final class Semrush extends AbstractProvider implements MetricsProvider, BacklinkSource {

    public function id(): string {
        return 'semrush';
    }

    public function label(): string {
        return 'Semrush';
    }

    public function isConfigured(): bool {
        return $this->secrets->has( 'semrush_api_key' );
    }

    private function request( string $base, array $query ): Response {
        $query['key'] = $this->secrets->get( 'semrush_api_key' );
        return $this->client->api( 'GET', $base . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ), [ 'Accept' => 'text/csv' ] );
    }

    /** @return array<int,array<string,string>> */
    public static function parseCsv( string $body ): array {
        $lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\n|\r/', trim( $body ) ) ?: [] ) ) );
        if ( count( $lines ) < 2 || str_starts_with( $lines[0], 'ERROR' ) ) {
            return [];
        }
        $header = str_getcsv( $lines[0], ';', '"', '\\' );
        $rows   = [];
        foreach ( array_slice( $lines, 1 ) as $line ) {
            $vals = str_getcsv( $line, ';', '"', '\\' );
            if ( count( $vals ) !== count( $header ) ) {
                continue;
            }
            $rows[] = array_combine( array_map( 'strval', $header ), array_map( 'strval', $vals ) ) ?: [];
        }
        return $rows;
    }

    public function domainMetrics( string $domain ): ?array {
        if ( ! $this->isConfigured() ) {
            return null;
        }
        $db  = strtolower( (string) $this->settings->get( 'providers.semrush.database', 'ca' ) ) ?: 'ca';
        $out = [ 'authority_label' => 'Authority Score', 'raw' => [] ];
        $any = false;

        $r = $this->request( 'https://api.semrush.com/', [ 'type' => 'domain_ranks', 'export_columns' => 'Db,Dn,Rk,Or,Ot,Oc', 'domain' => $domain, 'database' => $db ] );
        if ( $r->ok() ) {
            $rows = self::parseCsv( $r->body );
            if ( ! empty( $rows ) ) {
                $row = $rows[0];
                $ot  = self::pick( $row, [ 'Organic Traffic', 'Ot' ] );
                $or  = self::pick( $row, [ 'Organic Keywords', 'Or' ] );
                if ( null !== $ot ) {
                    $out['organic_traffic'] = (int) $ot;
                    $out['traffic_region']  = $db;
                    $any = true;
                }
                if ( null !== $or ) {
                    $out['organic_keywords'] = (int) $or;
                    $any = true;
                }
                $out['raw']['domain_ranks'] = $row;
            } elseif ( str_starts_with( trim( $r->body ), 'ERROR' ) ) {
                $this->fail( 'Semrush: ' . trim( mb_substr( $r->body, 0, 120 ) ) );
            }
        } else {
            $this->fail( 'Domain ranks failed: ' . $this->describe( $r ) );
        }

        $r2 = $this->request( 'https://api.semrush.com/analytics/v1/', [ 'type' => 'backlinks_overview', 'target' => $domain, 'target_type' => 'root_domain', 'export_columns' => 'ascore,total,domains_num,follows_num,nofollows_num' ] );
        if ( $r2->ok() ) {
            $rows = self::parseCsv( $r2->body );
            if ( ! empty( $rows ) ) {
                $row = $rows[0];
                $as  = self::pick( $row, [ 'ascore' ] );
                if ( null !== $as ) {
                    $out['authority'] = (int) round( $as );
                    $any = true;
                }
                $tot = self::pick( $row, [ 'total' ] );
                $dn  = self::pick( $row, [ 'domains_num' ] );
                $fo  = self::pick( $row, [ 'follows_num' ] );
                if ( null !== $tot ) {
                    $out['backlinks'] = (int) $tot;
                }
                if ( null !== $dn ) {
                    $out['referring_domains'] = (int) $dn;
                }
                if ( null !== $fo ) {
                    $out['dofollow_backlinks'] = (int) $fo;
                }
                $out['raw']['backlinks_overview'] = $row;
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
        $r = $this->request( 'https://api.semrush.com/analytics/v1/', [ 'type' => 'backlinks', 'target' => $domain, 'target_type' => 'root_domain', 'export_columns' => 'page_ascore,source_url,target_url,anchor,nofollow,first_seen,last_seen', 'display_limit' => max( 1, min( 1000, $limit ) ) ] );
        if ( ! $r->ok() ) {
            $this->fail( 'Backlinks failed: ' . $this->describe( $r ) );
            return [];
        }
        $this->succeed();
        $out = [];
        foreach ( self::parseCsv( $r->body ) as $row ) {
            if ( empty( $row['source_url'] ) ) {
                continue;
            }
            $out[] = [
                'source_url'       => $row['source_url'],
                'target_url'       => $row['target_url'] ?? '',
                'anchor'           => $row['anchor'] ?? '',
                'nofollow'         => in_array( strtolower( $row['nofollow'] ?? '' ), [ 'true', '1', 'yes' ], true ),
                'first_seen'       => isset( $row['first_seen'] ) && is_numeric( $row['first_seen'] ) ? gmdate( 'Y-m-d', (int) $row['first_seen'] ) : (string) ( $row['first_seen'] ?? '' ),
                'last_seen'        => isset( $row['last_seen'] ) && is_numeric( $row['last_seen'] ) ? gmdate( 'Y-m-d', (int) $row['last_seen'] ) : (string) ( $row['last_seen'] ?? '' ),
                'source_authority' => isset( $row['page_ascore'] ) ? (int) $row['page_ascore'] : null,
            ];
        }
        return $out;
    }
}
