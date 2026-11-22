<?php
/**
 * Runs schema migrations with a backup first.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Database;

use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Support\Logger;

final class Migrator {

    /** Transient set once every plugin table was seen; holds the schema version it was checked for. */
    public const TABLES_OK = 'ppclg_db_tables_ok';

    /** Transient set when an automatic migration failed or left tables missing; automatic retries wait for it. */
    public const BACKOFF = 'ppclg_migrate_backoff';

    private const TABLES_CHECK_TTL = 43200; // 12 hours

    private const BACKOFF_SECONDS = 900; // 15 minutes

    public function __construct(
        private Schema $schema,
        private Backup $backup,
        private Logger $logger,
        private AuditLog $audit
    ) {}

    public function currentVersion(): string {
        return (string) get_option( 'ppclg_db_version', '' );
    }

    public function needsMigration(): bool {
        return $this->versionBehind() || $this->tablesMissing();
    }

    /**
     * Whether nothing is to do: the stored schema version is the code's and every table was seen for it (the 12-hour
     * marker). Two option reads, no service built; Plugin::maybeMigrate() asks this before building the migrator.
     */
    public static function upToDate(): bool {
        return PPCLG_DB_VERSION === (string) get_option( 'ppclg_db_version', '' ) && PPCLG_DB_VERSION === get_transient( self::TABLES_OK );
    }

    /**
     * Runs on init for admin, cron and WP-CLI requests, and before the plugin's own REST routes (Plugin registers it
     * for those only). A visitor's page view, a REST call to another plugin (WooCommerce's Store API cart and
     * checkout) or a storefront AJAX request (wc-ajax, or admin-ajax.php without a login cookie) never migrates,
     * even right after an update that raised the schema version: the backup dump and dbDelta (measured on a test copy
     * with live-sized tables: 110 plugin queries, 7 writes and a 735 KB backup inside the first page view) wait for
     * the next admin page, cron run or plugin REST call. Page views do not touch the plugin's tables, so nothing waits for the new schema in the
     * meantime. The table-existence check (one SHOW TABLES per table) runs at most every 12 hours once all tables
     * were found. After a failed automatic migration, retries wait 15 minutes.
     */
    public function maybeMigrate(): void {
        if ( ! self::isMaintenanceRequest() ) {
            return;
        }
        if ( ! $this->needsMigration() ) {
            return;
        }
        if ( false !== get_transient( self::BACKOFF ) ) {
            return;
        }
        $this->migrate( false );
    }

    private function versionBehind(): bool {
        return version_compare( $this->currentVersion(), PPCLG_DB_VERSION, '<' );
    }

    /** Whether a plugin table is missing (cached for 12 hours once every table was found). */
    private function tablesMissing(): bool {
        if ( PPCLG_DB_VERSION === get_transient( self::TABLES_OK ) ) {
            return false;
        }
        if ( count( $this->schema->existingTables() ) < count( Schema::TABLES ) ) {
            return true;
        }
        set_transient( self::TABLES_OK, PPCLG_DB_VERSION, self::TABLES_CHECK_TTL );
        return false;
    }

    private static function isMaintenanceRequest(): bool {
        return \PPC\LinkGrowth\Plugin::maintenanceRequest() || \PPC\LinkGrowth\Plugin::pluginRestRoute();
    }

    /**
     * @param bool $force Run dbDelta even if the version matches.
     */
    public function migrate( bool $force ): void {
        if ( ! $force && ! $this->needsMigration() ) {
            return;
        }
        // Lock to avoid concurrent migrations from parallel requests.
        if ( ! add_option( 'ppclg_lock_migrate', time(), '', false ) ) {
            $since = (int) get_option( 'ppclg_lock_migrate', 0 );
            if ( $since > time() - 300 ) {
                return;
            }
            update_option( 'ppclg_lock_migrate', time(), false );
        }

        try {
            $existing = $this->schema->existingTables();
            $from     = $this->currentVersion();
            $backup   = null;
            if ( ! empty( $existing ) && $from !== PPCLG_DB_VERSION ) {
                $backup = $this->backup->dump( $existing, 'pre-migration-' . ( $from ?: 'initial' ) );
            }
            $messages = $this->schema->install();
            update_option( 'ppclg_db_version', PPCLG_DB_VERSION, false );
            $missing = count( Schema::TABLES ) - count( $this->schema->existingTables() );
            if ( $missing > 0 ) {
                delete_transient( self::TABLES_OK );
                set_transient( self::BACKOFF, time(), self::BACKOFF_SECONDS );
                $this->logger->error( 'migrate', sprintf( '%d plugin table(s) are still missing after the migration; the next automatic attempt waits 15 minutes.', $missing ) );
            } else {
                set_transient( self::TABLES_OK, PPCLG_DB_VERSION, self::TABLES_CHECK_TTL );
                delete_transient( self::BACKOFF );
            }
            $this->logger->info( 'migrate', 'Schema migrated.', [ 'from' => $from, 'to' => PPCLG_DB_VERSION, 'changes' => count( $messages ), 'backup' => $backup ? basename( $backup ) : null ] );
            $this->audit->record( 'system:migrator', 'schema.migrated', 'schema', 0, [ 'from' => $from, 'to' => PPCLG_DB_VERSION, 'backup' => $backup ? basename( $backup ) : null ] );
        } catch ( \Throwable $e ) {
            set_transient( self::BACKOFF, time(), self::BACKOFF_SECONDS );
            $this->logger->error( 'migrate', 'Migration failed: ' . $e->getMessage() );
        } finally {
            delete_option( 'ppclg_lock_migrate' );
        }
    }
}
