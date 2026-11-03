<?php
/**
 * Base repository around $wpdb for a single plugin table.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Repositories;

use PPC\LinkGrowth\Database\Schema;
use PPC\LinkGrowth\Support\Json;

abstract class Repository {

    /** Table short name, e.g. "prospects". */
    protected string $table = '';

    /** Columns stored as JSON. @var string[] */
    protected array $jsonColumns = [];

    /** Columns allowed for ORDER BY. @var string[] */
    protected array $sortable = [ 'id' ];

    public function table(): string {
        return Schema::table( $this->table );
    }

    protected function db(): \wpdb {
        global $wpdb;
        return $wpdb;
    }

    public static function now(): string {
        return gmdate( 'Y-m-d H:i:s' );
    }

    /**
     * @param array<string,mixed> $data
     */
    public function insert( array $data ): int {
        $data = $this->prepareData( $data );
        $ok   = $this->db()->insert( $this->table(), $data );
        if ( false === $ok ) {
            throw new \RuntimeException( sprintf( 'Insert into %s failed: %s', $this->table, $this->db()->last_error ) );
        }
        return (int) $this->db()->insert_id;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update( int $id, array $data ): bool {
        $data = $this->prepareData( $data );
        if ( empty( $data ) ) {
            return true;
        }
        $result = $this->db()->update( $this->table(), $data, [ 'id' => $id ] );
        return false !== $result;
    }

    /** @return array<string,mixed>|null */
    public function find( int $id ): ?array {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $this->db()->get_row( $this->db()->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A );
        return $row ? $this->hydrate( $row ) : null;
    }

    /**
     * @param array<string,mixed> $where column => value (scalar or list for IN)
     * @return array<string,mixed>|null
     */
    public function findOneBy( array $where, string $orderBy = 'id', string $order = 'ASC' ): ?array {
        $rows = $this->findBy( $where, $orderBy, $order, 1 );
        return $rows[0] ?? null;
    }

    /**
     * @param array<string,mixed> $where
     * @return array<int,array<string,mixed>>
     */
    public function findBy( array $where, string $orderBy = 'id', string $order = 'ASC', int $limit = 100, int $offset = 0 ): array {
        [ $sql, $params ] = $this->buildWhere( $where );
        $orderBy          = in_array( $orderBy, $this->sortable, true ) ? $orderBy : 'id';
        $order            = 'DESC' === strtoupper( $order ) ? 'DESC' : 'ASC';
        $query            = "SELECT * FROM {$this->table()} {$sql} ORDER BY {$orderBy} {$order} LIMIT %d OFFSET %d";
        $params[]         = max( 1, $limit );
        $params[]         = max( 0, $offset );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->db()->get_results( $this->db()->prepare( $query, $params ), ARRAY_A );
        return array_map( [ $this, 'hydrate' ], $rows ?: [] );
    }

    /** @param array<string,mixed> $where */
    public function count( array $where = [] ): int {
        [ $sql, $params ] = $this->buildWhere( $where );
        $query            = "SELECT COUNT(*) FROM {$this->table()} {$sql}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $this->db()->get_var( empty( $params ) ? $query : $this->db()->prepare( $query, $params ) );
    }

    public function delete( int $id ): bool {
        return false !== $this->db()->delete( $this->table(), [ 'id' => $id ] );
    }

    /**
     * Generic paginated listing with filters and free-text search.
     *
     * @param array<string,mixed> $where
     * @param string[]            $searchColumns
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
     */
    public function paginate( array $where, string $search, array $searchColumns, int $page, int $perPage, string $orderBy, string $order, string $extraWhere = '', array $extraParams = [] ): array {
        [ $sql, $params ] = $this->buildWhere( $where );
        $search           = trim( $search );
        if ( '' !== $search && ! empty( $searchColumns ) ) {
            $like  = '%' . $this->db()->esc_like( $search ) . '%';
            $parts = [];
            foreach ( $searchColumns as $col ) {
                $parts[]  = "{$col} LIKE %s";
                $params[] = $like;
            }
            $sql .= ( '' === $sql ? ' WHERE ' : ' AND ' ) . '(' . implode( ' OR ', $parts ) . ')';
        }
        if ( '' !== $extraWhere ) {
            $sql   .= ( '' === $sql ? ' WHERE ' : ' AND ' ) . '(' . $extraWhere . ')';
            $params = array_merge( $params, $extraParams );
        }
        $orderBy = in_array( $orderBy, $this->sortable, true ) ? $orderBy : 'id';
        $order   = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
        $page    = max( 1, $page );
        $perPage = min( 200, max( 1, $perPage ) );

        $countSql = "SELECT COUNT(*) FROM {$this->table()} {$sql}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $this->db()->get_var( empty( $params ) ? $countSql : $this->db()->prepare( $countSql, $params ) );

        $listSql  = "SELECT * FROM {$this->table()} {$sql} ORDER BY {$orderBy} {$order}, id DESC LIMIT %d OFFSET %d";
        $params[] = $perPage;
        $params[] = ( $page - 1 ) * $perPage;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->db()->get_results( $this->db()->prepare( $listSql, $params ), ARRAY_A );

        return [
            'items'    => array_map( [ $this, 'hydrate' ], $rows ?: [] ),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil( $total / $perPage ),
        ];
    }

    /**
     * Counts grouped by a column.
     *
     * @return array<string,int>
     */
    public function countBy( string $column, string $extraWhere = '', array $params = [] ): array {
        if ( ! preg_match( '/^[a-z_]+$/', $column ) ) {
            return [];
        }
        $sql = "SELECT {$column} AS k, COUNT(*) AS c FROM {$this->table()}" . ( '' !== $extraWhere ? " WHERE {$extraWhere}" : '' ) . " GROUP BY {$column}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->db()->get_results( empty( $params ) ? $sql : $this->db()->prepare( $sql, $params ), ARRAY_A );
        $out  = [];
        foreach ( $rows ?: [] as $r ) {
            $out[ (string) $r['k'] ] = (int) $r['c'];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $where
     * @return array{0:string,1:array<int,mixed>}
     */
    protected function buildWhere( array $where ): array {
        if ( empty( $where ) ) {
            return [ '', [] ];
        }
        $clauses = [];
        $params  = [];
        foreach ( $where as $column => $value ) {
            if ( ! preg_match( '/^[a-z_]+$/', $column ) ) {
                continue;
            }
            if ( is_array( $value ) ) {
                if ( empty( $value ) ) {
                    $clauses[] = '1=0';
                    continue;
                }
                $clauses[] = "{$column} IN (" . implode( ',', array_fill( 0, count( $value ), '%s' ) ) . ')';
                foreach ( $value as $v ) {
                    $params[] = (string) $v;
                }
            } elseif ( null === $value ) {
                $clauses[] = "{$column} IS NULL";
            } else {
                $clauses[] = "{$column} = %s";
                $params[]  = (string) $value;
            }
        }
        return [ empty( $clauses ) ? '' : ' WHERE ' . implode( ' AND ', $clauses ), $params ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    protected function prepareData( array $data ): array {
        foreach ( $this->jsonColumns as $col ) {
            if ( array_key_exists( $col, $data ) && ! is_string( $data[ $col ] ) && null !== $data[ $col ] ) {
                $data[ $col ] = Json::encode( $data[ $col ] );
            }
        }
        foreach ( $data as $k => $v ) {
            if ( is_bool( $v ) ) {
                $data[ $k ] = $v ? 1 : 0;
            }
        }
        return $data;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function hydrate( array $row ): array {
        foreach ( $this->jsonColumns as $col ) {
            if ( array_key_exists( $col, $row ) ) {
                $row[ $col ] = Json::decode( $row[ $col ] );
            }
        }
        if ( isset( $row['id'] ) ) {
            $row['id'] = (int) $row['id'];
        }
        return $row;
    }
}
