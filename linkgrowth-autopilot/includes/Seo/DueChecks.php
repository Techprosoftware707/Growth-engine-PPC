<?php
/**
 * Due-checks and schedules of the SEO module's tasks (DESIGN.md §5.1, TaskDefinition::$dueCheck and
 * TaskDefinition::$schedule): asked by Automation\TaskRegistry on a tick and for the Automation page. Moved unchanged
 * from the engine's Pipeline (WP2).
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Seo;

use LinkGrowth\Autopilot\Automation\Autopilot;
use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Support\Settings;
use Throwable;

final class DueChecks {

    public function __construct( private Container $c ) {}

    /**
     * IndexNow has URLs waiting (changed posts queued by the save hooks, the first submission, or the daily
     * comparison) and may submit (production site, search engines not discouraged, no back-off running).
     */
    public function indexnowDue(): bool {
        try {
            return class_exists( IndexNow::class ) && $this->c->get( IndexNow::class )->isDue();
        } catch ( Throwable $e ) {
            return false;
        }
    }

    /**
     * Internal links run daily while suggestions are applied automatically (seo.auto_apply_links or Autopilot), so the
     * daily cap is used; weekly (suggestions only) otherwise.
     *
     * @return array{every:int,cadence:string}
     */
    public function internalLinksSchedule(): array {
        $autopilot = $this->autopilot();
        if ( (bool) $this->c->get( Settings::class )->get( 'seo.internal_links', true ) && $autopilot->autoApplyLinks() ) {
            return [ 'every' => DAY_IN_SECONDS, 'cadence' => sprintf( 'daily (applies up to %d links automatically)', $autopilot->linksPerDay() ) ];
        }
        return [ 'every' => WEEK_IN_SECONDS, 'cadence' => 'weekly (suggestions only)' ];
    }

    /** The approval policy (Autopilot), built from the same settings when the container has none. */
    private function autopilot(): Autopilot {
        try {
            $svc = $this->c->get( Autopilot::class );
            if ( $svc instanceof Autopilot ) {
                return $svc;
            }
        } catch ( Throwable $e ) {
            unset( $e );
        }
        return new Autopilot( $this->c->get( Settings::class ) );
    }
}
