<?php
/**
 * Plugin capabilities: the full capability (administrators) and the submitter capability (the
 * "Link Growth Submitter" role), checked on every admin screen and REST route.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Admin;

final class Capabilities {

    /** Capability required to use the Link Growth Engine. */
    public const CAP = 'ppclg_manage';

    /**
     * Capability of the owner-task queue only: list the open owner tasks, read one and mark it submitted.
     * Nothing else in the plugin is reachable with it.
     */
    public const SUBMIT_CAP = 'ppclg_submit_listings';

    /** Role for a staff member or assistant who does the owner tasks by hand. */
    public const SUBMITTER_ROLE = 'ppclg_link_submitter';

    /** The submitter role's capabilities: exactly these, nothing more. */
    public const SUBMITTER_CAPS = [ 'read' => true, self::SUBMIT_CAP => true ];

    /** Transient that throttles the role sync to once per hour. */
    public const SYNC_TRANSIENT = 'ppclg_caps_synced';

    /** Display name of the submitter role. */
    public static function submitterRoleName(): string {
        return __( 'Link Growth Submitter', 'ppc-link-growth-engine' );
    }

    /**
     * Grants the capability to the administrator role and creates/refreshes the submitter role
     * (idempotent; throttled by transient).
     *
     * Runs on `init` so a role manager that strips custom capabilities is healed automatically.
     */
    public function ensure( bool $force = false ): void {
        if ( ! function_exists( 'get_role' ) ) {
            return;
        }
        // A missing submitter role skips the throttle: after an update that ran no activation hook, the role must
        // be there as soon as the Settings card points the owner to it, not when an older sync's hour runs out.
        if ( ! $force && get_transient( self::SYNC_TRANSIENT ) && null !== get_role( self::SUBMITTER_ROLE ) ) {
            return;
        }
        try {
            $roles = [ 'administrator' ];
            /**
             * Filters the roles that receive the plugin capability.
             *
             * The submitter role is never given the full capability, whatever this filter returns.
             *
             * @param string[] $roles Role slugs.
             */
            $roles = apply_filters( 'ppclg_capability_roles', $roles );
            foreach ( array_unique( array_map( 'strval', (array) $roles ) ) as $slug ) {
                if ( self::SUBMITTER_ROLE === $slug ) {
                    continue;
                }
                $role = get_role( $slug );
                if ( $role && ! $role->has_cap( self::CAP ) ) {
                    $role->add_cap( self::CAP );
                }
            }
            $this->ensureSubmitterRole();
            set_transient( self::SYNC_TRANSIENT, 1, HOUR_IN_SECONDS );
        } catch ( \Throwable $e ) {
            // Never fatal on init.
            unset( $e );
        }
    }

    /**
     * Creates the submitter role, or resets an existing one to exactly 'read' + SUBMIT_CAP (least privilege:
     * a capability another plugin or a role editor added to it is removed again).
     */
    public function ensureSubmitterRole(): void {
        if ( ! function_exists( 'add_role' ) ) {
            return;
        }
        $role = get_role( self::SUBMITTER_ROLE );
        if ( ! $role ) {
            add_role( self::SUBMITTER_ROLE, self::submitterRoleName(), self::SUBMITTER_CAPS );
            return;
        }
        foreach ( array_keys( (array) $role->capabilities ) as $cap ) {
            if ( ! isset( self::SUBMITTER_CAPS[ (string) $cap ] ) ) {
                $role->remove_cap( (string) $cap );
            }
        }
        foreach ( array_keys( self::SUBMITTER_CAPS ) as $cap ) {
            if ( ! $role->has_cap( $cap ) ) {
                $role->add_cap( $cap );
            }
        }
    }

    /** Removes both capabilities from every role and deletes the submitter role (used on uninstall / reset). */
    public function remove(): void {
        if ( ! function_exists( 'wp_roles' ) ) {
            return;
        }
        foreach ( array_keys( wp_roles()->roles ) as $slug ) {
            $role = get_role( (string) $slug );
            if ( ! $role ) {
                continue;
            }
            foreach ( [ self::CAP, self::SUBMIT_CAP ] as $cap ) {
                if ( $role->has_cap( $cap ) ) {
                    $role->remove_cap( $cap );
                }
            }
        }
        if ( function_exists( 'remove_role' ) ) {
            remove_role( self::SUBMITTER_ROLE );
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

    /**
     * Whether a user may work the owner-task queue: the submitter capability or full access.
     */
    public static function userCanSubmit( ?int $userId = null ): bool {
        if ( null === $userId ) {
            return current_user_can( self::SUBMIT_CAP ) || self::userCan();
        }
        return user_can( $userId, self::SUBMIT_CAP ) || self::userCan( $userId );
    }

    /**
     * Whether a user has the submitter capability but not full access: everything they see is limited to the
     * owner-task queue.
     */
    public static function submitOnly( ?int $userId = null ): bool {
        return ! self::userCan( $userId ) && self::userCanSubmit( $userId );
    }
}
