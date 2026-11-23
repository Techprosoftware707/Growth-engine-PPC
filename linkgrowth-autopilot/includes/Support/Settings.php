<?php
/**
 * Plugin settings (non-secret) with dot-path access; the defaults come from the modules (DESIGN.md §3.1).
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Support;

use LinkGrowth\Autopilot\Plugin;

final class Settings {

    public const OPTION = 'lga_settings';

    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    /**
     * The defaults of every settings section, from the modules (Module::settingsDefaults(), in Core\Modules order).
     * A section two modules declare is merged; on a key both declare, the earlier module's default stays.
     *
     * @return array<string,mixed>
     */
    public static function defaults(): array {
        $out = [];
        foreach ( Plugin::instance()->modules() as $module ) {
            foreach ( (array) $module->settingsDefaults() as $section => $defaults ) {
                if ( ! is_string( $section ) || '' === $section ) {
                    continue;
                }
                $out[ $section ] = isset( $out[ $section ] ) && is_array( $out[ $section ] ) && is_array( $defaults )
                    ? self::mergeDeep( $defaults, $out[ $section ] )
                    : ( $out[ $section ] ?? $defaults );
            }
        }
        return $out;
    }

    /** The site's home URL with a trailing slash ('' outside WordPress), from which several defaults derive. */
    public static function defaultHome(): string {
        return function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
    }

    /** The host of $url without a leading "www.". */
    public static function domainOf( string $url ): string {
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        return (string) preg_replace( '/^www\./i', '', $host );
    }

    /** @return array<string,mixed> */
    public function all(): array {
        if ( null === $this->cache ) {
            $stored      = get_option( self::OPTION, [] );
            $this->cache = self::mergeDeep( self::defaults(), is_array( $stored ) ? $stored : [] );
        }
        return $this->cache;
    }

    /**
     * @param string $path Dot path, e.g. "automation.daily_limits.submissions".
     */
    public function get( string $path, mixed $default = null ): mixed {
        $value = $this->all();
        foreach ( explode( '.', $path ) as $segment ) {
            if ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
                $value = $value[ $segment ];
            } else {
                return $default;
            }
        }
        return $value;
    }

    /**
     * Drops this request's copy so the next read comes from the database. Settings is a container singleton that
     * caches the option for the whole request (a cron tick can run for tens of seconds), so code that must see a
     * change another request made meanwhile (Autopilot switched off, say) refreshes first.
     */
    public function refresh(): void {
        $this->cache = null;
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( self::OPTION, 'options' );
            $all = function_exists( 'wp_cache_get' ) ? wp_cache_get( 'alloptions', 'options' ) : false;
            if ( is_array( $all ) && array_key_exists( self::OPTION, $all ) ) {
                wp_cache_delete( 'alloptions', 'options' );
            }
        }
    }

    /**
     * Changes one path. The change is merged into a fresh read of the stored option, never into this request's
     * cached copy: a background job that writes one value must not put back settings the owner changed while it
     * ran (for example Autopilot switched off).
     */
    public function set( string $path, mixed $value ): void {
        $this->refresh();
        $all  = $this->all();
        $ref  = &$all;
        $keys = explode( '.', $path );
        $last = array_pop( $keys );
        foreach ( $keys as $k ) {
            if ( ! isset( $ref[ $k ] ) || ! is_array( $ref[ $k ] ) ) {
                $ref[ $k ] = [];
            }
            $ref = &$ref[ $k ];
        }
        $ref[ $last ] = $value;
        $this->replace( $all );
    }

    /** @param array<string,mixed> $values */
    public function replace( array $values ): void {
        $this->cache = self::mergeDeep( self::defaults(), $values );
        update_option( self::OPTION, $this->cache, false );
    }

    /** Merges $partial into a fresh read of the stored option (see set()). @param array<string,mixed> $partial */
    public function update( array $partial ): void {
        $this->refresh();
        $this->replace( self::mergeDeep( $this->all(), $partial ) );
    }

    public function ensureDefaults(): void {
        $stored = get_option( self::OPTION, null );
        if ( ! is_array( $stored ) ) {
            update_option( self::OPTION, self::defaults(), false );
            $this->cache = null;
        }
    }

    public function siteDomain(): string {
        return strtolower( (string) $this->get( 'site.domain', '' ) );
    }

    /**
     * The business name to use in copy: site.brand_name, else the WordPress site title, else ''. No brand ships as
     * a default (the profile of each site names its own).
     */
    public function brandName(): string {
        $brand = trim( (string) $this->get( 'site.brand_name', '' ) );
        if ( '' === $brand && function_exists( 'get_bloginfo' ) ) {
            $brand = trim( (string) get_bloginfo( 'name' ) );
        }
        return $brand;
    }

    public function userAgent(): string {
        return (string) $this->get( 'advanced.user_agent', 'LinkGrowthAutopilotBot/1.0' );
    }

    /**
     * Recursive merge where arrays with numeric keys (lists) are replaced, not merged.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    public static function mergeDeep( array $base, array $over ): array {
        foreach ( $over as $k => $v ) {
            if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) && ! self::isList( $v ) && ! self::isList( $base[ $k ] ) ) {
                $base[ $k ] = self::mergeDeep( $base[ $k ], $v );
            } else {
                $base[ $k ] = $v;
            }
        }
        return $base;
    }

    /** @param array<mixed> $a */
    public static function isList( array $a ): bool {
        if ( [] === $a ) {
            return true;
        }
        return array_keys( $a ) === range( 0, count( $a ) - 1 );
    }
}
