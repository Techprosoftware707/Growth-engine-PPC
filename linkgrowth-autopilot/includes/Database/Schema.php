<?php
/**
 * Database schema: the tables the modules declare (Module::tables(), DESIGN.md §3.1), created and upgraded with dbDelta.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Database;

use LinkGrowth\Autopilot\Plugin;

final class Schema {

    public static function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . 'lga_' . $name;
    }

    /** The table options clause (character set and collation) for a CREATE TABLE statement. */
    public static function charsetCollate(): string {
        global $wpdb;
        return (string) $wpdb->get_charset_collate();
    }

    /**
     * Every table the modules declare (Module::tables()), in Core\Modules order: name without prefix => CREATE TABLE
     * statement. A name already declared by an earlier module, a name that is not a lower-case key and an empty
     * statement are left out.
     *
     * @return array<string,string>
     */
    public static function declared(): array {
        $out = [];
        foreach ( Plugin::instance()->modules() as $module ) {
            foreach ( (array) $module->tables() as $name => $sql ) {
                $name = (string) $name;
                if ( isset( $out[ $name ] ) || 1 !== preg_match( '/^[a-z0-9_]+$/', $name ) || ! is_string( $sql ) || '' === trim( $sql ) ) {
                    continue;
                }
                $out[ $name ] = $sql;
            }
        }
        return $out;
    }

    /**
     * The plugin's table names without the prefix (formerly the TABLES constant).
     *
     * @return list<string>
     */
    public static function names(): array {
        return array_keys( self::declared() );
    }

    /** @return string[] */
    public function tableNames(): array {
        return array_map( [ self::class, 'table' ], self::names() );
    }

    /** @return string[] existing plugin tables */
    public function existingTables(): array {
        global $wpdb;
        $existing = [];
        foreach ( $this->tableNames() as $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
            if ( $found === $table ) {
                $existing[] = $table;
            }
        }
        return $existing;
    }

    /**
     * Full CREATE TABLE statements, formatted for dbDelta(), from the modules (declared()).
     *
     * @return string[]
     */
    public function statements(): array {
        return array_values( self::declared() );
    }

    /**
     * Create / upgrade all tables.
     *
     * @return array<string,string> dbDelta messages
     */
    public function install(): array {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $messages = [];
        foreach ( $this->statements() as $sql ) {
            $result = dbDelta( $sql );
            foreach ( (array) $result as $k => $v ) {
                $messages[ (string) $k ] = (string) $v;
            }
        }
        return $messages;
    }
}
