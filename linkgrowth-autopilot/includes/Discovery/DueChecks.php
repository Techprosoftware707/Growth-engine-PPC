<?php
/**
 * Due-checks of the discovery module's tasks (DESIGN.md §5.1, TaskDefinition::$dueCheck): asked by
 * Automation\TaskRegistry::gate() on a tick. Moved unchanged from the engine's Pipeline (WP2).
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Discovery;

use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Repositories\Prospects;
use Throwable;

final class DueChecks {

    public function __construct( private Container $c ) {}

    /** Qualified prospects lack opportunities. */
    public function opportunitiesDue(): bool {
        return [] !== $this->c->get( Prospects::class )->qualifiedWithoutOpportunities( 1 );
    }

    /** The bundled opportunity catalog has a version that is not fully imported (or a pass is unfinished). */
    public function catalogImportDue(): bool {
        try {
            return class_exists( CatalogImporter::class ) && $this->c->get( CatalogImporter::class )->needsImport();
        } catch ( Throwable $e ) {
            return false;
        }
    }
}
