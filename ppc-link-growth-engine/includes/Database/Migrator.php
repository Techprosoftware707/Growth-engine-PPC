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
        return version_compare( $this->currentVersion(), PPCLG_DB_VERSION, '<' ) || count( $this->schema->existingTables() ) < count( Schema::TABLES );
    }

    public function maybeMigrate(): void {
        if ( $this->needsMigration() ) {
            $this->migrate( false );
        }
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
            $this->logger->info( 'migrate', 'Schema migrated.', [ 'from' => $from, 'to' => PPCLG_DB_VERSION, 'changes' => count( $messages ), 'backup' => $backup ? basename( $backup ) : null ] );
            $this->audit->record( 'system:migrator', 'schema.migrated', 'schema', 0, [ 'from' => $from, 'to' => PPCLG_DB_VERSION, 'backup' => $backup ? basename( $backup ) : null ] );
        } catch ( \Throwable $e ) {
            $this->logger->error( 'migrate', 'Migration failed: ' . $e->getMessage() );
        } finally {
            delete_option( 'ppclg_lock_migrate' );
        }
    }
}
