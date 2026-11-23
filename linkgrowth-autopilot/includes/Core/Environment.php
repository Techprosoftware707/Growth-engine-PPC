<?php
/**
 * Other software on this site that does the same work as this plugin (DESIGN.md §1.4).
 *
 * The detectors are generic, so the shipped plugin names no other product:
 * - an active plugin whose basename the owner lists in the `lga_known_conflicts` filter (empty by default;
 *   `[ basename => label ]`), for example a second backlink plugin installed on the same site;
 * - another plugin's callback on this plugin's cron hook (`lga_tick`);
 * - and, in the full check (Site Health), another plugin's routes in this plugin's REST namespace (`lga/v1`) and
 *   database tables with this plugin's table prefix that this plugin does not own.
 *
 * Each conflict is a warning only (the dashboard notices and Site Health): nothing is blocked.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Core;

use LinkGrowth\Autopilot\Automation\Scheduler;
use LinkGrowth\Autopilot\Database\Schema;
use LinkGrowth\Autopilot\Plugin;

final class Environment {

    /** Filter for owner-listed conflicting plugins: `[ 'folder/main-file.php' => 'Label' ]`. */
    public const FILTER_KNOWN = 'lga_known_conflicts';

    /** Key of the Site Health test in site_status_tests['direct']. */
    public const TEST = 'lga_environment';

    public const REST_NAMESPACE = 'lga/v1';

    /**
     * Conflicts found now. `$full` adds the REST namespace and table checks (they load every REST route and list the
     * database tables, so they run on the Site Health screen, not on every dashboard page).
     *
     * @return list<array{id:string,kind:string,label:string,message:string}>
     */
    public function conflicts( bool $full = false ): array {
        $out = [];
        foreach ( $this->knownConflicts() as $basename => $label ) {
            $out[] = self::conflict( 'plugin:' . $basename, 'plugin', $label );
        }
        if ( $this->foreignCallbacks( $GLOBALS['wp_filter'][ Scheduler::HOOK ] ?? null ) ) {
            /* translators: %s: the name of a WordPress hook */
            $out[] = self::conflict( 'hook:' . Scheduler::HOOK, 'hook', sprintf( __( 'Another plugin on the %s hook', 'linkgrowth-autopilot' ), Scheduler::HOOK ) );
        }
        if ( $full ) {
            if ( $this->foreignRoutes() ) {
                /* translators: %s: a REST API namespace */
                $out[] = self::conflict( 'rest:' . self::REST_NAMESPACE, 'rest', sprintf( __( 'Another plugin in the %s REST namespace', 'linkgrowth-autopilot' ), self::REST_NAMESPACE ) );
            }
            foreach ( $this->foreignTables() as $table ) {
                /* translators: %s: a database table name */
                $out[] = self::conflict( 'table:' . $table, 'table', sprintf( __( 'Another plugin with the database table %s', 'linkgrowth-autopilot' ), $table ) );
            }
        }
        return $out;
    }

    /** The warning for one conflict (DESIGN.md §1.4 wording). */
    public static function message( string $label ): string {
        return sprintf(
            /* translators: %s: name of the other plugin, or what was found */
            __( '%s is active on this site. Running both can double outbound crawling and contact attempts to the same sites. Use one per site.', 'linkgrowth-autopilot' ),
            $label
        );
    }

    /**
     * Active plugins the owner listed in `lga_known_conflicts` (never this plugin itself).
     *
     * @return array<string,string> basename => label
     */
    public function knownConflicts(): array {
        $listed = apply_filters( self::FILTER_KNOWN, [] );
        if ( ! is_array( $listed ) || [] === $listed ) {
            return [];
        }
        if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $own = defined( 'LGA_BASENAME' ) ? (string) LGA_BASENAME : '';
        $out = [];
        foreach ( $listed as $basename => $label ) {
            $basename = trim( (string) $basename );
            $label    = trim( wp_strip_all_tags( (string) $label ) );
            if ( '' === $basename || $own === $basename || ! function_exists( 'is_plugin_active' ) ) {
                continue;
            }
            $network = function_exists( 'is_plugin_active_for_network' ) && is_multisite() && is_plugin_active_for_network( $basename );
            if ( is_plugin_active( $basename ) || $network ) {
                $out[ $basename ] = '' !== $label ? $label : $basename;
            }
        }
        return $out;
    }

    /**
     * Site Health result for `lga_environment`.
     *
     * @return array{label:string,status:string,badge:array{label:string,color:string},description:string,actions:string,test:string}
     */
    public function siteHealthTest(): array {
        $conflicts = $this->conflicts( true );
        $badge     = [ 'label' => __( Plugin::NAME, 'linkgrowth-autopilot' ), 'color' => 'blue' ]; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
        if ( [] === $conflicts ) {
            return [
                'label'       => __( 'No other plugin does the same backlink work on this site', 'linkgrowth-autopilot' ),
                'status'      => 'good',
                'badge'       => $badge,
                'description' => '<p>' . esc_html__( 'No plugin listed as a conflict is active, and no other plugin uses this plugin\'s cron hook, REST namespace or table names.', 'linkgrowth-autopilot' ) . '</p>',
                'actions'     => '',
                'test'        => self::TEST,
            ];
        }
        $badge['color'] = 'orange';
        $items          = '';
        foreach ( $conflicts as $c ) {
            $items .= '<li>' . esc_html( $c['message'] ) . '</li>';
        }
        return [
            'label'       => __( 'Another plugin may do the same backlink work on this site', 'linkgrowth-autopilot' ),
            'status'      => 'recommended',
            'badge'       => $badge,
            'description' => '<ul>' . $items . '</ul>',
            'actions'     => '',
            'test'        => self::TEST,
        ];
    }

    /**
     * `site_status_tests`: adds the direct environment test.
     *
     * @param mixed $tests
     * @return array<string,mixed>
     */
    public function addTests( mixed $tests ): array {
        $tests = is_array( $tests ) ? $tests : [];
        if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
            $tests['direct'] = [];
        }
        $tests['direct'][ self::TEST ] = [
            'label' => __( 'Other backlink plugins', 'linkgrowth-autopilot' ),
            'test'  => [ $this, 'siteHealthTest' ],
        ];
        return $tests;
    }

    /** @return array{id:string,kind:string,label:string,message:string} */
    private static function conflict( string $id, string $kind, string $label ): array {
        return [ 'id' => $id, 'kind' => $kind, 'label' => $label, 'message' => self::message( $label ) ];
    }

    /** True when a hook (WP_Hook or callback array) has a callback that is not this plugin's code. */
    private function foreignCallbacks( mixed $hook ): bool {
        $callbacks = is_object( $hook ) && isset( $hook->callbacks ) ? (array) $hook->callbacks : ( is_array( $hook ) ? $hook : [] );
        foreach ( $callbacks as $byPriority ) {
            foreach ( (array) $byPriority as $entry ) {
                $fn = is_array( $entry ) && array_key_exists( 'function', $entry ) ? $entry['function'] : $entry;
                if ( ! self::isOwn( $fn ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /** True when the `lga/v1` namespace holds a route handler that is not this plugin's code. */
    private function foreignRoutes(): bool {
        if ( ! function_exists( 'rest_get_server' ) ) {
            return false;
        }
        try {
            $routes = rest_get_server()->get_routes( self::REST_NAMESPACE );
        } catch ( \Throwable $e ) {
            return false;
        }
        foreach ( (array) $routes as $route => $handlers ) {
            if ( '/' . self::REST_NAMESPACE === (string) $route ) {
                continue; // the namespace index WordPress itself adds for every namespace
            }
            foreach ( (array) $handlers as $handler ) {
                if ( is_array( $handler ) && isset( $handler['callback'] ) && ! self::isOwn( $handler['callback'] ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Tables with this plugin's prefix that it does not own. @return string[] */
    private function foreignTables(): array {
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
            return [];
        }
        $prefix = $wpdb->prefix . 'lga_';
        $like   = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $prefix ) . '%' : $prefix . '%';
        try {
            $found = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        } catch ( \Throwable $e ) {
            return []; // a database layer that cannot list tables reports nothing rather than failing the check
        }
        $own    = array_map( static fn( string $t ): string => $prefix . $t, Schema::names() );
        return array_values( array_diff( array_map( 'strval', $found ), $own ) );
    }

    /** True when a callback is defined by this plugin (its namespace, or a closure or function in its folder). */
    private static function isOwn( mixed $fn ): bool {
        try {
            if ( is_array( $fn ) && isset( $fn[0] ) ) {
                $class = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
                if ( str_starts_with( ltrim( $class, '\\' ), 'LinkGrowth\\Autopilot\\' ) ) {
                    return true;
                }
                $file = ( new \ReflectionClass( $class ) )->getFileName();
            } elseif ( is_string( $fn ) && str_contains( $fn, '::' ) ) {
                return str_starts_with( ltrim( $fn, '\\' ), 'LinkGrowth\\Autopilot\\' );
            } elseif ( $fn instanceof \Closure || ( is_string( $fn ) && function_exists( $fn ) ) ) {
                $file = ( new \ReflectionFunction( $fn ) )->getFileName();
            } elseif ( is_object( $fn ) ) {
                $file = ( new \ReflectionClass( $fn ) )->getFileName();
            } else {
                return false;
            }
        } catch ( \ReflectionException $e ) {
            return false;
        }
        if ( ! is_string( $file ) || '' === $file || ! defined( 'LGA_DIR' ) ) {
            return false;
        }
        $dir = realpath( (string) LGA_DIR );
        $own = realpath( $file );
        return false !== $dir && false !== $own && str_starts_with( $own, rtrim( $dir, '/' ) . '/' );
    }
}
