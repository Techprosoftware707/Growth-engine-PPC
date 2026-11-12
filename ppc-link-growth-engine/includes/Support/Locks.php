<?php
/**
 * Simple TTL locks stored in wp_options (works without object cache, survives across requests).
 *
 * Acquire, stale takeover and release are atomic compare-and-set statements run straight against the
 * options table. The Options API is not used for them: add_option() is "INSERT ... ON DUPLICATE KEY UPDATE"
 * (two racing callers both succeed) and get_option() answers from the calling process's own object cache,
 * which update_option() has just primed with that process's token, so a read-back check always "passes".
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Support;

final class Locks {

    private const PREFIX = 'ppclg_lock_';

    /** @var array<string,array{token:string,raw:string}> name => lock held by this process */
    private array $held = [];

    public function acquire( string $name, int $ttl = 120 ): bool {
        $option = self::PREFIX . sanitize_key( $name );
        $token  = Crypto::randomToken( 8 );
        $value  = [ 'token' => $token, 'expires' => time() + $ttl ];
        $raw    = serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- same format maybe_serialize() stores

        $acquired = $this->sqlAcquire( $option, $raw );
        if ( null === $acquired ) {
            $acquired = $this->optionsApiAcquire( $option, $value, $token );
        }
        if ( $acquired ) {
            $this->held[ $name ] = [ 'token' => $token, 'raw' => $raw ];
        }
        return $acquired;
    }

    public function release( string $name ): void {
        $held = $this->held[ $name ] ?? null;
        unset( $this->held[ $name ] );
        if ( null === $held ) {
            return;
        }
        $option = self::PREFIX . sanitize_key( $name );
        // Delete only the row that still carries this process's value: a lock taken over by another
        // process after this one overran its TTL is left alone.
        $deleted = $this->quietQuery( 'DELETE FROM {options} WHERE option_name = %s AND option_value = %s', $option, $held['raw'] );
        if ( false === $deleted ) {
            $current = get_option( $option );
            if ( is_array( $current ) && ( $current['token'] ?? '' ) === $held['token'] ) {
                delete_option( $option );
            }
            return;
        }
        $this->forgetCached( $option );
    }

    public function isLocked( string $name ): bool {
        $option = self::PREFIX . sanitize_key( $name );
        $raw     = $this->readRaw( $option );
        $current = false === $raw ? get_option( $option ) : self::decode( $raw );
        return is_array( $current ) && (int) ( $current['expires'] ?? 0 ) >= time();
    }

    public function releaseAll(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) );
        wp_cache_flush();
        $this->held = [];
    }

    /**
     * Atomic test-and-set against the options table.
     *
     * @return bool|null null when the options table cannot be queried directly (the Options API is used then).
     */
    private function sqlAcquire( string $option, string $raw ): ?bool {
        // option_name is UNIQUE: of several concurrent INSERTs exactly one creates the row.
        $inserted = $this->quietQuery( 'INSERT INTO {options} (option_name, option_value, autoload) VALUES (%s, %s, %s)', $option, $raw, 'off' );
        if ( 1 === $inserted ) {
            $this->forgetCached( $option );
            return true;
        }
        // The row exists (or the INSERT failed): read the stored value from the database, not the cache.
        $current = $this->readRaw( $option );
        if ( false === $current ) {
            return false === $inserted ? null : false;
        }
        if ( null === $current ) {
            return false; // Released between the two statements; the next run will take it.
        }
        $value = self::decode( $current );
        if ( ! is_array( $value ) || ! isset( $value['expires'] ) || (int) $value['expires'] >= time() ) {
            return false; // Held (and not expired).
        }
        // Stale lock: take it over only if nobody else did since it was read.
        $updated = $this->quietQuery( 'UPDATE {options} SET option_value = %s WHERE option_name = %s AND option_value = %s', $raw, $option, $current );
        if ( 1 !== $updated ) {
            return false;
        }
        $this->forgetCached( $option );
        return true;
    }

    /**
     * Pre-existing Options API path, used only when the options table cannot be queried directly.
     *
     * @param array{token:string,expires:int} $value
     */
    private function optionsApiAcquire( string $option, array $value, string $token ): bool {
        if ( add_option( $option, $value, '', false ) ) {
            return true;
        }
        $current = get_option( $option );
        if ( is_array( $current ) && isset( $current['expires'] ) && (int) $current['expires'] < time() ) {
            update_option( $option, $value, false );
            $check = get_option( $option );
            return is_array( $check ) && ( $check['token'] ?? '' ) === $token;
        }
        return false;
    }

    /**
     * Stored value of $option read straight from the database.
     *
     * @return string|null|false null when there is no such row, false when the query failed.
     */
    private function readRaw( string $option ): string|null|false {
        global $wpdb;
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );
        } catch ( \Throwable $e ) {
            return false;
        }
        if ( null === $raw ) {
            return '' !== (string) ( $wpdb->last_error ?? '' ) ? false : null;
        }
        return (string) $raw;
    }

    /**
     * Runs one write statement against the options table with database errors silenced (a duplicate-key
     * INSERT is an expected outcome here). "{options}" in $sql is replaced by the options table name.
     *
     * @return int|false rows affected, or false on error
     */
    private function quietQuery( string $sql, string ...$args ): int|false {
        global $wpdb;
        $sql  = str_replace( '{options}', $wpdb->options, $sql );
        $prev = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
        try {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->query( $wpdb->prepare( $sql, ...$args ) );
        } catch ( \Throwable $e ) {
            $result = false;
        } finally {
            if ( null !== $prev ) {
                $wpdb->suppress_errors( $prev );
            }
        }
        return false === $result ? false : (int) $result;
    }

    /** Drops the cached copy of $option after a direct write, so get_option() everywhere reads the database. */
    private function forgetCached( string $option ): void {
        wp_cache_delete( $option, 'options' );
        $notoptions = wp_cache_get( 'notoptions', 'options' );
        if ( is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
            unset( $notoptions[ $option ] );
            wp_cache_set( 'notoptions', $notoptions, 'options' );
        }
    }

    private static function decode( ?string $raw ): mixed {
        if ( null === $raw || '' === $raw ) {
            return null;
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
        $value = @unserialize( $raw, [ 'allowed_classes' => false ] );
        return false === $value && 'b:0;' !== $raw ? $raw : $value;
    }
}
