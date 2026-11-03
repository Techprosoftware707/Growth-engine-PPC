<?php
/**
 * Plugin capability: granted to administrators and checked on every admin screen and REST route.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Admin;

final class Capabilities {

    /** Capability required to use the Link Growth Engine. */
    public const CAP = 'ppclg_manage';

    /** Transient that throttles the role sync to once per hour. */
    public const SYNC_TRANSIENT = 'ppclg_caps_synced';

    /**
     * Grants the capability to the administrator role (idempotent; throttled by transient).
     *
     * Runs on `init` so a role manager that strips custom capabilities is healed automatically.
     */
    public function ensure( bool $force = false ): void {
        if ( ! $force && get_transient( self::SYNC_TRANSIENT ) ) {
            return;
        }
        if ( ! function_exists( 'get_role' ) ) {
            return;
        }
        try {
            $roles = [ 'administrator' ];
            /**
             * Filters the roles that receive the plugin capability.
             *
             * @param string[] $roles Role slugs.
             */
            $roles = apply_filters( 'ppclg_capability_roles', $roles );
            foreach ( array_unique( array_map( 'strval', (array) $roles ) ) as $slug ) {
                $role = get_role( $slug );
                if ( $role && ! $role->has_cap( self::CAP ) ) {
                    $role->add_cap( self::CAP );
                }
            }
            set_transient( self::SYNC_TRANSIENT, 1, HOUR_IN_SECONDS );
        } catch ( \Throwable $e ) {
            // Never fatal on init.
            unset( $e );
        }
    }

    /** Removes the capability from every role (used on uninstall / reset). */
    public function remove(): void {
        if ( ! function_exists( 'wp_roles' ) ) {
            return;
        }
        foreach ( array_keys( wp_roles()->roles ) as $slug ) {
            $role = get_role( (string) $slug );
            if ( $role && $role->has_cap( self::CAP ) ) {
                $role->remove_cap( self::CAP );
            }
        }
        delete_transient( self::SYNC_TRANSIENT );
    }

    /**
     * Whether a user may use the plugin: the dedicated capability or `manage_options`.
     */
    public static function userCan( ?int $userId = null ): bool {
        if ( null === $userId ) {
            return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
        }
        return user_can( $userId, self::CAP ) || user_can( $userId, 'manage_options' );
    }
}
