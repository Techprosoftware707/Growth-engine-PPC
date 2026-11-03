<?php
/**
 * Simple TTL locks stored in wp_options (works without object cache, survives across requests).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Support;

final class Locks {

    private const PREFIX = 'ppclg_lock_';

    /** @var array<string,string> name => token held by this process */
    private array $held = [];

    public function acquire( string $name, int $ttl = 120 ): bool {
        $option = self::PREFIX . sanitize_key( $name );
        $token  = Crypto::randomToken( 8 );
        $value  = [ 'token' => $token, 'expires' => time() + $ttl ];

        if ( add_option( $option, $value, '', false ) ) {
            $this->held[ $name ] = $token;
            return true;
        }
        $current = get_option( $option );
        if ( is_array( $current ) && isset( $current['expires'] ) && (int) $current['expires'] < time() ) {
            // Stale lock: take it over.
            update_option( $option, $value, false );
            $check = get_option( $option );
            if ( is_array( $check ) && ( $check['token'] ?? '' ) === $token ) {
                $this->held[ $name ] = $token;
                return true;
            }
        }
        return false;
    }

    public function release( string $name ): void {
        $option  = self::PREFIX . sanitize_key( $name );
        $current = get_option( $option );
        if ( is_array( $current ) && isset( $this->held[ $name ] ) && ( $current['token'] ?? '' ) === $this->held[ $name ] ) {
            delete_option( $option );
        }
        unset( $this->held[ $name ] );
    }

    public function isLocked( string $name ): bool {
        $current = get_option( self::PREFIX . sanitize_key( $name ) );
        return is_array( $current ) && (int) ( $current['expires'] ?? 0 ) >= time();
    }

    public function releaseAll(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) );
        wp_cache_flush();
        $this->held = [];
    }
}
