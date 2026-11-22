<?php
/**
 * Contract implemented by every background job type.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Automation\Jobs;

use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Support\TimeBudget;

interface JobInterface {

    /** Stable job type identifier (stored in the jobs table, e.g. "evaluate"). */
    public static function type(): string;

    /** Queue priority: 1 (first) … 9 (last). */
    public static function priority(): int;

    /**
     * Performs the work for one job run.
     *
     * Implementations must respect the time budget, the automation flags and the daily limits,
     * catch Throwables per item and return an informative, JSON-serialisable result array.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array;
}
