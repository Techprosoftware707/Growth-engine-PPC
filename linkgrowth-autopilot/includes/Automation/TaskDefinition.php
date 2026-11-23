<?php
/**
 * One scheduled task (DESIGN.md §5.1): what the scheduler enqueues, how often and behind which gates. Modules return
 * these from Module::tasks(); extensions add theirs through the `lga_scheduled_tasks` filter (Automation\TaskRegistry).
 *
 * The first fifteen fields are the ones of §5.1. In WP2 they carry the engine's own schedule:
 *  - `defaultFrequency` and `priority` are the engine's cadence and the job class's priority;
 *  - `module` names the engine's automation flag (`automation.<flag>`, read through Automation\Autopilot::flag());
 *    the `automation.modules.<flag>` switches of §2.1 replace those flags in WP4a/WP4b;
 *  - `budgetPool` names the limit counter the engine checked before it enqueued (evaluations, submissions,
 *    outreach_emails); the other tasks check their counters inside their jobs, as before, and get the pools of the
 *    §5.1 table with the scheduler package (WP10), as do the `runCap` bounds ('' until then);
 *  - `requiresProfile`, `requiresSetup`, `consent`, `autopilot` and `outbound` hold the §5.1 values, but the gates that
 *    read the first four are pass-through stubs until WP4b, so they change nothing yet.
 *
 * Three fields are WP2 additions that keep the engine's behaviour and texts and go when WP10 moves the schedule to
 * `lga_tasks`:
 *  - `cadence`: the text the Automation page shows ('' = the frequency's own word, Frequency::label());
 *  - `order`: the position in a tick (tasks are considered, and enqueued, in ascending order; equal values keep
 *    module order, then filter order); the engine's stages have 10 … 200, extensions default to ORDER_LAST;
 *  - `schedule`: the name of a method on the module's DueChecks class that returns the schedule the engine's own
 *    settings give the task now (`{every: seconds, cadence: text}`), for import_backlinks
 *    (monitoring.import_interval_days) and internal_links (daily while internal links are applied automatically);
 *    null = defaultFrequency.
 *
 * `dueCheck` and `schedule` name a method of the DueChecks class in the declaring module's namespace (for example
 * Scoring\DueChecks::evaluateDue()), or `Class::method` for a task an extension adds through the filter. They are
 * never read from settings.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Automation;

final class TaskDefinition {

    /** `order` of a task that names none: after the built-in tasks. */
    public const ORDER_LAST = 1000;

    public function __construct(
        public string $id,               // 'discover'
        public string $jobType,          // registered job type
        public string $label,
        public string $defaultFrequency, // Frequency::DAILY
        public string $module,           // automation flag that must be on ('' = always)
        public ?string $dueCheck,        // name of a method on the module's DueChecks class, or null
        public int $priority,            // 1 (first) .. 9, the job priority it enqueues with
        public string $budgetPool = '',  // limit counter that must have room before it is enqueued; '' = none
        public string $runCap = '',      // the internal bound when it runs often and has no pool ('' until WP10)
        public bool $requiresProfile = true, // blocked until setup.profile_ready (gate stub until WP4b)
        public bool $requiresSetup = false,  // blocked until setup.completed (gate stub until WP4b)
        public string $consent = '',     // consent key that must be recorded, '' = none (gate stub until WP4b)
        public bool $autopilot = false,  // the scheduler starts it only while Autopilot allows it (gate stub until WP4b)
        public bool $outbound = false,   // sends requests to hosts other than the site's own (implies requiresProfile)
        public bool $offAllowed = true,  // false: cleanup (and health_check later) can never be off
        public string $cadence = '',     // WP2: the Automation page's text ('' = Frequency::label())
        public int $order = self::ORDER_LAST, // WP2: position in a tick
        public ?string $schedule = null  // WP2: DueChecks method giving the schedule from the engine's settings
    ) {}
}
