<?php
/**
 * Generates rotating discovery queries from templates × topics × Canadian geo modifiers.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Discovery;

use PPC\LinkGrowth\Repositories\SearchLog;
use PPC\LinkGrowth\Support\Settings;

final class QueryGenerator {

    public const CURSOR_OPTION = 'ppclg_query_cursor';

    public function __construct( private Settings $settings, private SearchLog $log ) {}

    /** @return array<string,mixed> */
    private function data(): array {
        $file = PPCLG_DIR . 'data/discovery-queries.json';
        $json = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : []; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $json = is_array( $json ) ? $json : [ 'categories' => [], 'topics' => [], 'brand_mention_templates' => [] ];
        /**
         * Filter discovery query templates.
         *
         * @param array<string,mixed> $json
         */
        return apply_filters( 'ppclg_discovery_queries', $json );
    }

    /**
     * Full ordered pool of candidate queries.
     *
     * @return array<int,array{query:string,category:string,opportunity:string}>
     */
    public function pool(): array {
        $data   = $this->data();
        $topics = array_values( array_filter( array_map( 'strval', (array) ( $data['topics'] ?? [] ) ) ) );
        $geos   = array_values( array_filter( array_map( 'strval', (array) $this->settings->get( 'discovery.geo_modifiers', [ 'Canada' ] ) ) ) );
        $topics = $topics ?: [ 'laboratory supplies' ];
        $geos   = $geos ?: [ 'Canada' ];
        $pool   = [];

        foreach ( (array) $this->settings->get( 'discovery.custom_queries', [] ) as $q ) {
            $q = trim( (string) $q );
            if ( '' !== $q ) {
                $pool[] = [ 'query' => $q, 'category' => 'custom', 'opportunity' => 'resource_page' ];
            }
        }
        $byCategory = [];
        foreach ( (array) ( $data['categories'] ?? [] ) as $cat => $def ) {
            $opp = (string) ( $def['opportunity'] ?? 'resource_page' );
            $ti  = 0;
            $gi  = 0;
            foreach ( (array) ( $def['templates'] ?? [] ) as $tpl ) {
                $tpl = (string) $tpl;
                $variants = 1;
                if ( str_contains( $tpl, '{topic}' ) ) {
                    $variants = min( 3, count( $topics ) );
                }
                if ( str_contains( $tpl, '{geo}' ) ) {
                    $variants = max( $variants, min( 3, count( $geos ) ) );
                }
                for ( $v = 0; $v < $variants; $v++ ) {
                    $q = str_replace( [ '{topic}', '{geo}' ], [ $topics[ $ti % count( $topics ) ], $geos[ $gi % count( $geos ) ] ], $tpl );
                    $ti++;
                    $gi++;
                    $byCategory[ (string) $cat ][] = [ 'query' => trim( preg_replace( '/\s+/', ' ', $q ) ?? $q ), 'category' => (string) $cat, 'opportunity' => $opp ];
                }
            }
        }
        // Interleave categories so each run touches several opportunity types.
        $max = 0;
        foreach ( $byCategory as $list ) {
            $max = max( $max, count( $list ) );
        }
        for ( $i = 0; $i < $max; $i++ ) {
            foreach ( $byCategory as $list ) {
                if ( isset( $list[ $i ] ) ) {
                    $pool[] = $list[ $i ];
                }
            }
        }
        $seen = [];
        $out  = [];
        foreach ( $pool as $item ) {
            $k = strtolower( $item['query'] );
            if ( ! isset( $seen[ $k ] ) ) {
                $seen[ $k ] = true;
                $out[]      = $item;
            }
        }
        return $out;
    }

    /**
     * Next $count queries not run within the cooldown, continuing from the stored cursor.
     *
     * @return array<int,array{query:string,category:string,opportunity:string}>
     */
    public function next( int $count ): array {
        $pool = $this->pool();
        if ( empty( $pool ) || $count <= 0 ) {
            return [];
        }
        $cooldown = max( 1, (int) $this->settings->get( 'discovery.query_cooldown_days', 30 ) );
        $cursor   = (int) get_option( self::CURSOR_OPTION, 0 ) % count( $pool );
        $out      = [];
        $checked  = 0;
        while ( count( $out ) < $count && $checked < count( $pool ) ) {
            $item = $pool[ ( $cursor + $checked ) % count( $pool ) ];
            $checked++;
            if ( ! $this->log->ranWithin( $item['query'], $cooldown ) ) {
                $out[] = $item;
            }
        }
        update_option( self::CURSOR_OPTION, ( $cursor + $checked ) % count( $pool ), false );
        return $out;
    }

    /**
     * Moves the rotation cursor back to $query, so a query that could not run (provider error)
     * and the ones after it are offered first on the next run.
     */
    public function rewindTo( string $query ): void {
        $query = strtolower( trim( $query ) );
        foreach ( $this->pool() as $i => $item ) {
            if ( strtolower( $item['query'] ) === $query ) {
                update_option( self::CURSOR_OPTION, $i, false );
                return;
            }
        }
    }

    /** Brand-mention queries. @return string[] */
    public function brandQueries(): array {
        $data   = $this->data();
        $brand  = (string) $this->settings->get( 'site.brand_name', 'Pure Peptides Canada' );
        $domain = $this->settings->siteDomain();
        $out    = [];
        foreach ( (array) ( $data['brand_mention_templates'] ?? [] ) as $tpl ) {
            $out[] = str_replace( [ '{brand}', '{domain}' ], [ $brand, $domain ], (string) $tpl );
        }
        foreach ( (array) $this->settings->get( 'discovery.brand_terms', [] ) as $term ) {
            $term = trim( (string) $term );
            if ( '' !== $term && strtolower( $term ) !== strtolower( $brand ) && strtolower( $term ) !== $domain ) {
                $out[] = '"' . $term . '" -site:' . $domain;
            }
        }
        return array_values( array_unique( $out ) );
    }
}
