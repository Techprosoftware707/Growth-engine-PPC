<?php
/**
 * Curated seed websites (data/seeds.json + admin additions).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Discovery;

use PPC\LinkGrowth\Support\Url;

final class SeedCatalog {

    public const IMPORTED_OPTION = 'ppclg_seeds_imported_at';

    /** Keys (hashed normalized URLs) of every seed already handed to the intake. */
    public const IMPORTED_KEYS_OPTION = 'ppclg_seeds_imported_keys';

    /** @return array<int,array{url:string,name:string,type:string,opportunity:string,category:string,country:string}> */
    public function all(): array {
        $list   = $this->builtIn();
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

    /** Seeds shipped in data/seeds.json. @return array<int,mixed> */
    private function builtIn(): array {
        $file = PPCLG_DIR . 'data/seeds.json';
        $json = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : []; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        return is_array( $json ) && isset( $json['seeds'] ) ? array_values( (array) $json['seeds'] ) : [];
    }

    public function importedAt(): string {
        return (string) get_option( self::IMPORTED_OPTION, '' );
    }

    /**
     * Seeds not yet imported: custom seeds saved after the first import, and catalog additions.
     *
     * @return array<int,array{url:string,name:string,type:string,opportunity:string,category:string,country:string}>
     */
    public function pending(): array {
        $done = $this->importedKeys();
        return array_values( array_filter( $this->all(), static fn( array $s ): bool => ! isset( $done[ self::key( $s['url'] ) ] ) ) );
    }

    /**
     * Imports the whole catalog, or with $onlyPending only the seeds not imported before.
     * Every seed handed to the intake is remembered, so a seed the owner later deleted or
     * rejected is not re-created on the next run.
     *
     * @return array{seeds:int,created:int}
     */
    public function import( ProspectIntake $intake, bool $onlyPending = false ): array {
        $created = 0;
        $seeds   = $onlyPending ? $this->pending() : $this->all();
        $done    = $this->importedKeys();
        foreach ( $seeds as $seed ) {
            $r = $intake->fromUrl( $seed['url'], 'seed', $seed['category'], [ 'name' => $seed['name'], 'site_type' => $seed['type'], 'opportunity_type' => $seed['opportunity'], 'country' => $seed['country'], 'title' => $seed['name'] ] );
            if ( $r && $r['created'] ) {
                $created++;
            }
            $done[ self::key( $seed['url'] ) ] = 1;
        }
        update_option( self::IMPORTED_KEYS_OPTION, $done, false );
        update_option( self::IMPORTED_OPTION, gmdate( 'c' ), false );
        return [ 'seeds' => count( $seeds ), 'created' => $created ];
    }

    /** @return array<string,int> seed key => 1 */
    private function importedKeys(): array {
        $keys = get_option( self::IMPORTED_KEYS_OPTION, null );
        if ( is_array( $keys ) ) {
            return $keys;
        }
        if ( '' === $this->importedAt() ) {
            return [];
        }
        // Installs that imported before seeds were tracked one by one: the built-in catalog was
        // imported then, so only custom seeds count as pending (the intake de-duplicates by domain).
        $keys = [];
        foreach ( $this->builtIn() as $s ) {
            if ( is_array( $s ) && ! empty( $s['url'] ) ) {
                $keys[ self::key( (string) $s['url'] ) ] = 1;
            }
        }
        update_option( self::IMPORTED_KEYS_OPTION, $keys, false );
        return $keys;
    }

    private static function key( string $url ): string {
        $norm = Url::normalize( $url );
        return substr( md5( strtolower( '' !== $norm ? $norm : trim( $url ) ) ), 0, 16 );
    }
}
