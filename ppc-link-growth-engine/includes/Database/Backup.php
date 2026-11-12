<?php
/**
 * Pre-migration backup of the plugin's own tables (gzipped SQL).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Database;

use PPC\LinkGrowth\Support\Logger;

final class Backup {

    /** Prefix of the (randomly suffixed) backup directory inside uploads. */
    public const DIR_PREFIX = 'ppclg-backups-';

    public function __construct( private Schema $schema, private Logger $logger ) {}

    /**
     * Directory for backups: inside uploads, random suffix (not guessable), with index.php + .htaccess guards.
     */
    public function directory(): string {
        $dir = (string) get_option( 'ppclg_backup_dir', '' );
        $uploads = wp_upload_dir( null, false );
        $base    = trailingslashit( $uploads['basedir'] );
        if ( '' === $dir || ! is_dir( $base . $dir ) ) {
            $dir = self::DIR_PREFIX . wp_generate_password( 16, false, false );
            update_option( 'ppclg_backup_dir', $dir, false );
        }
        $path = $base . $dir;
        if ( ! is_dir( $path ) ) {
            wp_mkdir_p( $path );
        }
        if ( ! file_exists( $path . '/index.php' ) ) {
            file_put_contents( $path . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }
        if ( ! file_exists( $path . '/.htaccess' ) ) {
            file_put_contents( $path . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }
        return $path;
    }

    /**
     * Dumps the given tables. Returns the absolute file path or null when nothing to back up.
     *
     * @param string[] $tables
     */
    public function dump( array $tables, string $label = 'migration' ): ?string {
        global $wpdb;
        if ( empty( $tables ) ) {
            return null;
        }
        $path = $this->directory();
        $file = sprintf( '%s/%s-%s-%s.sql.gz', $path, gmdate( 'Ymd-His' ), sanitize_key( $label ), wp_generate_password( 8, false, false ) );

        $gz = gzopen( $file, 'wb6' );
        if ( ! $gz ) {
            $this->logger->error( 'backup', 'Unable to open backup file for writing.', [ 'file' => basename( $file ) ] );
            return null;
        }
        gzwrite( $gz, "-- PPC Link Growth Engine table backup\n-- Created: " . gmdate( 'c' ) . "\n-- Site: " . home_url() . "\nSET FOREIGN_KEY_CHECKS=0;\n\n" );
        foreach ( $tables as $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
            if ( $create && isset( $create[1] ) ) {
                gzwrite( $gz, "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n" );
            }
            $offset = 0;
            $batch  = 500;
            do {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch, $offset ), ARRAY_A );
                if ( ! is_array( $rows ) ) {
                    $rows = [];
                }
                foreach ( $rows as $row ) {
                    $cols   = array_map( static fn( $c ) => '`' . $c . '`', array_keys( $row ) );
                    $values = array_map( static fn( $v ) => self::sqlValue( $v, $wpdb ), array_values( $row ) );
                    gzwrite( $gz, sprintf( "INSERT INTO `%s` (%s) VALUES (%s);\n", $table, implode( ',', $cols ), implode( ',', $values ) ) );
                }
                $offset += $batch;
            } while ( count( $rows ) === $batch );
            gzwrite( $gz, "\n" );
        }
        gzwrite( $gz, "SET FOREIGN_KEY_CHECKS=1;\n" );
        gzclose( $gz );

        $this->logger->info( 'backup', 'Plugin tables backed up before migration.', [ 'file' => basename( $file ), 'tables' => count( $tables ) ] );
        $this->prune( 10 );
        return $file;
    }

    /**
     * One value as an SQL literal for the dump file.
     *
     * esc_sql() replaces every "%" with wpdb's per-request placeholder hash; that hash is only turned back
     * into "%" when the string goes through $wpdb->query(). The dump is written to a file instead, so the
     * placeholder is removed here or every percent sign (percent-encoded URLs, "100%" in copy) would be
     * restored as a random hash.
     */
    private static function sqlValue( mixed $v, object $wpdb ): string {
        if ( null === $v ) {
            return 'NULL';
        }
        $escaped = (string) esc_sql( (string) $v );
        if ( method_exists( $wpdb, 'remove_placeholder_escape' ) ) {
            $escaped = (string) $wpdb->remove_placeholder_escape( $escaped );
        }
        return "'" . $escaped . "'";
    }

    /**
     * Deletes every backup directory (and the dumps in it) under $uploadsBase. Used on uninstall when the
     * owner chose "Delete all data": the dumps hold contacts, outreach messages and the suppression list.
     * Directories are matched by their fixed "ppclg-backups-" prefix, so one whose random name was lost with
     * the ppclg_backup_dir option is removed too.
     *
     * @return int Number of directories removed.
     */
    public static function removeAll( string $uploadsBase, string $namedDir = '' ): int {
        $uploadsBase = rtrim( $uploadsBase, '/\\' );
        if ( '' === $uploadsBase || ! is_dir( $uploadsBase ) ) {
            return 0;
        }
        $dirs = glob( $uploadsBase . '/' . self::DIR_PREFIX . '*', GLOB_ONLYDIR ) ?: [];
        if ( 1 === preg_match( '/^' . preg_quote( self::DIR_PREFIX, '/' ) . '[A-Za-z0-9]+$/', $namedDir ) ) {
            $dirs[] = $uploadsBase . '/' . $namedDir;
        }
        $removed = 0;
        foreach ( array_unique( $dirs ) as $dir ) {
            if ( is_link( $dir ) || ! is_dir( $dir ) ) {
                continue; // never follow a symlink out of uploads
            }
            foreach ( scandir( $dir ) ?: [] as $entry ) {
                $path = $dir . '/' . $entry;
                if ( '.' !== $entry && '..' !== $entry && ( is_file( $path ) || is_link( $path ) ) ) {
                    wp_delete_file( $path );
                }
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
            if ( @rmdir( $dir ) ) {
                $removed++;
            }
        }
        return $removed;
    }

    /** Keep only the newest $keep backups. */
    public function prune( int $keep ): void {
        $files = glob( $this->directory() . '/*.sql.gz' ) ?: [];
        rsort( $files );
        foreach ( array_slice( $files, $keep ) as $old ) {
            wp_delete_file( $old );
        }
    }

    /** @return array<int,array{file:string,size:int,created:string}> */
    public function listBackups(): array {
        $files = glob( $this->directory() . '/*.sql.gz' ) ?: [];
        rsort( $files );
        return array_map(
            static fn( string $f ) => [
                'file'    => basename( $f ),
                'size'    => (int) filesize( $f ),
                'created' => gmdate( 'c', (int) filemtime( $f ) ),
            ],
            $files
        );
    }
}
