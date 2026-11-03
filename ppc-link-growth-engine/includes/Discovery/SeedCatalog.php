<?php
/**
 * Curated seed websites (data/seeds.json + admin additions).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Discovery;

final class SeedCatalog {

    public const IMPORTED_OPTION = 'ppclg_seeds_imported_at';

    /** @return array<int,array{url:string,name:string,type:string,opportunity:string,category:string,country:string}> */
    public function all(): array {
        $file = PPCLG_DIR . 'data/seeds.json';
        $json = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : []; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $list = is_array( $json ) && isset( $json['seeds'] ) ? (array) $json['seeds'] : [];
        $custom = get_option( 'ppclg_custom_seeds', [] );
        if ( is_array( $custom ) ) {
            $list = array_merge( $list, $custom );
        }
        /**
         * Filter the seed catalog.
         *
         * @param array<int,array<string,mixed>> $list
         */
        $list = apply_filters( 'ppclg_seed_catalog', $list );
        $out  = [];
        foreach ( $list as $s ) {
            if ( ! is_array( $s ) || empty( $s['url'] ) ) {
                continue;
            }
            $out[] = [
                'url'         => (string) $s['url'],
                'name'        => (string) ( $s['name'] ?? '' ),
                'type'        => (string) ( $s['type'] ?? 'directory' ),
                'opportunity' => (string) ( $s['opportunity'] ?? 'directory_listing' ),
                'category'    => (string) ( $s['category'] ?? '' ),
                'country'     => strtoupper( (string) ( $s['country'] ?? '' ) ),
            ];
        }
        return $out;
    }

    public function importedAt(): string {
        return (string) get_option( self::IMPORTED_OPTION, '' );
    }

    /** @return array{seeds:int,created:int} */
    public function import( ProspectIntake $intake ): array {
        $created = 0;
        $seeds   = $this->all();
        foreach ( $seeds as $seed ) {
            $r = $intake->fromUrl( $seed['url'], 'seed', $seed['category'], [ 'name' => $seed['name'], 'site_type' => $seed['type'], 'opportunity_type' => $seed['opportunity'], 'country' => $seed['country'], 'title' => $seed['name'] ] );
            if ( $r && $r['created'] ) {
                $created++;
            }
        }
        update_option( self::IMPORTED_OPTION, gmdate( 'c' ), false );
        return [ 'seeds' => count( $seeds ), 'created' => $created ];
    }
}
