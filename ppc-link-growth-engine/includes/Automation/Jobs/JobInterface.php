<?php
/**
 * Contract implemented by every background job type.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Support\TimeBudget;

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
