<?php
/**
 * Minimal wpdb replacement backed by in-memory SQLite for unit tests.
 *
 * Supports the subset used by the plugin repositories: prepare(), insert(), update(), delete(),
 * get_results(), get_row(), get_var(), get_col(), query(), esc_like(), insert_id, last_error, prefix.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Support;

final class FakeWpdb extends \wpdb {

    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public int $insert_id = 0;
    public string $last_error = '';
    public string $last_query = '';
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = new \PDO( 'sqlite::memory:' );
        $this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
        $this->pdo->sqliteCreateFunction( 'CONCAT', static fn( ...$a ) => implode( '', array_map( 'strval', $a ) ) );
        $this->pdo->sqliteCreateFunction( 'FLOOR', static fn( $v ) => floor( (float) $v ) );
        $this->pdo->sqliteCreateFunction( 'DATE_FORMAT', static function ( $value, $format ) {
            $ts = strtotime( (string) $value );
            if ( ! $ts ) {
                return null;
            }
            return gmdate( str_replace( [ '%x', '%v', '%Y', '%m', '%d' ], [ 'o', 'W', 'Y', 'm', 'd' ], (string) $format ), $ts );
        } );
    }

    public function get_charset_collate(): string {
        return '';
    }

    /** Translate dbDelta-style MySQL CREATE TABLE statements to SQLite and run them. */
    public function createFromMysql( string $sql ): void {
        $sql = preg_replace( '/\)\s*[^)]*;\s*$/s', ');', trim( $sql ) ) ?? $sql; // strip charset suffix
        $lines   = preg_split( '/\r?\n/', $sql ) ?: [];
        $out     = [];
        $indexes = [];
        $table   = '';
        if ( preg_match( '/CREATE TABLE\s+(\S+)/i', $sql, $m ) ) {
            $table = $m[1];
        }
        foreach ( $lines as $line ) {
            $t = trim( $line );
            if ( preg_match( '/^PRIMARY KEY/i', $t ) ) {
                continue;
            }
            if ( preg_match( '/^UNIQUE KEY\s+(\w+)\s+\((.+)\),?$/i', $t, $m ) ) {
                $indexes[] = sprintf( 'CREATE UNIQUE INDEX IF NOT EXISTS %s_%s ON %s (%s)', $table, $m[1], $table, $m[2] );
                continue;
            }
            if ( preg_match( '/^KEY\s+(\w+)\s+\((.+)\),?$/i', $t, $m ) ) {
                $indexes[] = sprintf( 'CREATE INDEX IF NOT EXISTS %s_%s ON %s (%s)', $table, $m[1], $table, $m[2] );
                continue;
            }
            $t = preg_replace( '/bigint\(\d+\) unsigned NOT NULL AUTO_INCREMENT/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $t ) ?? $t;
            $t = preg_replace( '/\b(tinyint|smallint|int|bigint)\(\d+\)( unsigned)?/i', 'INTEGER', $t ) ?? $t;
            $t = preg_replace( '/\bvarchar\(\d+\)/i', 'TEXT', $t ) ?? $t;
            $t = preg_replace( '/\bchar\(\d+\)/i', 'TEXT', $t ) ?? $t;
            $t = preg_replace( '/\b(longtext|text|datetime)\b/i', 'TEXT', $t ) ?? $t;
            $out[] = $t;
        }
        $ddl = implode( "\n", $out );
        $ddl = preg_replace( '/,\s*\)/', "\n)", $ddl ) ?? $ddl;
        $ddl = str_ireplace( 'CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $ddl );
        $this->pdo->exec( $ddl );
        foreach ( $indexes as $idx ) {
            $this->pdo->exec( $idx );
        }
    }

    public function prepare( string $query, ...$args ): string {
        if ( 1 === count( $args ) && is_array( $args[0] ) ) {
            $args = $args[0];
        }
        $query = str_replace( [ '%%' ], [ "\0PCT\0" ], $query );
        $i     = 0;
        $out   = preg_replace_callback(
            '/%[sdf]/',
            function ( $m ) use ( &$i, $args ) {
                $v = $args[ $i++ ] ?? null;
                return match ( $m[0] ) {
                    '%d' => (string) (int) $v,
                    '%f' => (string) (float) $v,
                    default => $this->pdo->quote( (string) $v ),
                };
            },
            $query
        ) ?? $query;
        return str_replace( "\0PCT\0", '%', $out );
    }

    public function esc_like( string $text ): string {
        return addcslashes( $text, '_%\\' );
    }

    public function insert( string $table, array $data ): bool {
        $cols = array_keys( $data );
        $sql  = sprintf( 'INSERT INTO %s (%s) VALUES (%s)', $table, implode( ',', $cols ), implode( ',', array_fill( 0, count( $cols ), '?' ) ) );
        try {
            $st = $this->pdo->prepare( $sql );
            $st->execute( array_values( array_map( static fn( $v ) => is_bool( $v ) ? (int) $v : $v, $data ) ) );
            $this->insert_id = (int) $this->pdo->lastInsertId();
            return true;
        } catch ( \PDOException $e ) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    public function update( string $table, array $data, array $where ): int|false {
        $set = implode( ', ', array_map( static fn( $c ) => "$c = ?", array_keys( $data ) ) );
        $w   = implode( ' AND ', array_map( static fn( $c ) => "$c = ?", array_keys( $where ) ) );
        try {
            $st = $this->pdo->prepare( "UPDATE $table SET $set WHERE $w" );
            $st->execute( array_merge( array_values( array_map( static fn( $v ) => is_bool( $v ) ? (int) $v : $v, $data ) ), array_values( $where ) ) );
            return $st->rowCount();
        } catch ( \PDOException $e ) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    public function delete( string $table, array $where ): int|false {
        $w = implode( ' AND ', array_map( static fn( $c ) => "$c = ?", array_keys( $where ) ) );
        $st = $this->pdo->prepare( "DELETE FROM $table WHERE $w" );
        $st->execute( array_values( $where ) );
        return $st->rowCount();
    }

    public function query( string $sql ): int|false {
        $this->last_query = $sql;
        try {
            return $this->pdo->exec( $sql );
        } catch ( \PDOException $e ) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    public function get_results( string $sql, string $output = 'OBJECT' ): array {
        $this->last_query = $sql;
        $rows = $this->pdo->query( $sql )->fetchAll( \PDO::FETCH_ASSOC );
        if ( 'ARRAY_A' === $output ) {
            return $rows;
        }
        if ( 'ARRAY_N' === $output ) {
            return array_map( 'array_values', $rows );
        }
        return array_map( static fn( $r ) => (object) $r, $rows );
    }

    public function get_row( string $sql, string $output = 'OBJECT' ): array|object|null {
        $rows = $this->get_results( $sql, $output );
        return $rows[0] ?? null;
    }

    public function get_var( string $sql ): mixed {
        $this->last_query = $sql;
        $row = $this->pdo->query( $sql )->fetch( \PDO::FETCH_NUM );
        return $row ? $row[0] : null;
    }

    public function get_col( string $sql ): array {
        return array_map( static fn( $r ) => $r[0], $this->pdo->query( $sql )->fetchAll( \PDO::FETCH_NUM ) );
    }
}
