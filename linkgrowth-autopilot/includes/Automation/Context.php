<?php
/**
 * Who asks TaskRegistry::gate() (DESIGN.md §5.2): the scheduler's tick, or a person (Run now, Grow now,
 * `wp linkgrowth tasks run`). A manual context skips the Autopilot gate, the schedule (frequency, interval and
 * due-check) and the queue lock; every other gate applies to both.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Automation;

final class Context {

    public const SCHEDULER = 'scheduler';
    public const MANUAL    = 'manual';

    /**
     * @param array<string,string> $lastRuns job type => ISO-8601 time of its last enqueue or run (Pipeline::OPTION_STAGE_RUNS)
     */
    private function __construct(
        public readonly string $kind,
        public readonly int $now,
        public readonly array $lastRuns
    ) {}

    /**
     * The scheduler's tick at $now (default: the current time), with the last runs it read.
     *
     * @param array<string,string> $lastRuns
     */
    public static function scheduler( ?int $now = null, array $lastRuns = [] ): self {
        return new self( self::SCHEDULER, $now ?? time(), $lastRuns );
    }

    /** A person started the task. */
    public static function manual( ?int $now = null ): self {
        return new self( self::MANUAL, $now ?? time(), [] );
    }

    public function isManual(): bool {
        return self::MANUAL === $this->kind;
    }

    /** When a job of $jobType was last enqueued or run (Unix time; 0 = never or unreadable). */
    public function lastRun( string $jobType ): int {
        return isset( $this->lastRuns[ $jobType ] ) ? (int) strtotime( (string) $this->lastRuns[ $jobType ] ) : 0;
    }
}
