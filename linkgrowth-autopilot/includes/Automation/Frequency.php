<?php
/**
 * Task frequencies (DESIGN.md §5.1): the names a TaskDefinition's defaultFrequency uses.
 *
 * WP2 keeps the engine's due rule: a task is due when its interval has passed since it was last enqueued (the
 * `lga_stage_runs` marker), continuous tasks are considered on every tick (and enqueued only when their due-check finds
 * work), and an `off` task is never enqueued by the scheduler. The calendar rules of §5.2 (daily at an anchor time,
 * weekly on an anchor weekday, monthly on an anchor day, `Frequency::next()`) and the task state in `lga_tasks` come
 * with the scheduler package (WP10, §13.2); until then `monthly` is 30 days after the last run.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Automation;

final class Frequency {

    public const FIFTEEN_MINUTES = '15min';
    public const HOURLY          = 'hourly';
    public const SIX_HOURS       = '6h';
    public const DAILY           = 'daily';
    public const WEEKLY          = 'weekly';
    public const MONTHLY         = 'monthly';
    public const CONTINUOUS      = 'continuous';
    public const OFF             = 'off';

    /** Every frequency name. */
    public const ALL = [ self::FIFTEEN_MINUTES, self::HOURLY, self::SIX_HOURS, self::DAILY, self::WEEKLY, self::MONTHLY, self::CONTINUOUS, self::OFF ];

    /** The six frequencies every task allows (DESIGN.md §5.1); `continuous` needs a due-check, `off` needs offAllowed. */
    public const SCHEDULED = [ self::FIFTEEN_MINUTES, self::HOURLY, self::SIX_HOURS, self::DAILY, self::WEEKLY, self::MONTHLY ];

    /** Seconds between two enqueues; 0 = considered on every tick, -1 = never by the scheduler. */
    private const SECONDS = [
        self::FIFTEEN_MINUTES => 900,
        self::HOURLY          => 3600,
        self::SIX_HOURS       => 21600,
        self::DAILY           => 86400,
        self::WEEKLY          => 604800,
        self::MONTHLY         => 2592000,
        self::CONTINUOUS      => 0,
        self::OFF             => -1,
    ];

    /** How the Automation page describes a frequency (the engine's cadence words). */
    private const LABELS = [
        self::FIFTEEN_MINUTES => 'every 15 minutes',
        self::HOURLY          => 'hourly',
        self::SIX_HOURS       => 'every 6 hours',
        self::DAILY           => 'daily',
        self::WEEKLY          => 'weekly',
        self::MONTHLY         => 'monthly',
        self::CONTINUOUS      => 'every tick',
        self::OFF             => 'manual',
    ];

    public static function isValid( string $frequency ): bool {
        return isset( self::SECONDS[ $frequency ] );
    }

    /** Seconds between two enqueues (0 = every tick, -1 = off or an unknown name). */
    public static function seconds( string $frequency ): int {
        return self::SECONDS[ $frequency ] ?? -1;
    }

    public static function label( string $frequency ): string {
        return self::LABELS[ $frequency ] ?? 'manual';
    }
}
