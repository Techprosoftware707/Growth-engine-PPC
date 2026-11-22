<?php
/**
 * Thin audit facade used by automation jobs (actor defaults to the current user or "system").
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Automation;

use LinkGrowth\Autopilot\Repositories\AuditLog;
use Throwable;

final class Audit {

    public function __construct( private AuditLog $log ) {}

    /**
     * Records an audit entry; never throws.
     *
     * @param array<string,mixed> $details Redacted by the repository before storage.
     * @return int Audit row id (0 on failure).
     */
    public function record( string $action, string $objectType = '', int $objectId = 0, array $details = [], ?string $actor = null ): int {
        try {
            return $this->log->record( $actor ?? AuditLog::currentActor(), $action, $objectType, $objectId, $details );
        } catch ( Throwable $e ) {
            return 0;
        }
    }
}
