<?php
/**
 * Module `automation` (DESIGN.md §3.2 Autopilot scheduler and Background jobs rows): the scheduler, pipeline, job
 * runner, server-pressure guard, Autopilot policy, audit trail and the "Grow now" run; the jobs queue table, the
 * automation and growth settings, the cleanup job, the Automation page and the Automation and Growth controllers.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Automation;

use LinkGrowth\Autopilot\Admin\Capabilities;
use LinkGrowth\Autopilot\Admin\Rest\AutomationController;
use LinkGrowth\Autopilot\Admin\Rest\GrowthController;
use LinkGrowth\Autopilot\Automation\Jobs\CleanupJob;
use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Core\AbstractModule;
use LinkGrowth\Autopilot\Database\Schema;
use LinkGrowth\Autopilot\Growth\GrowthRun;
use LinkGrowth\Autopilot\Plugin;
use LinkGrowth\Autopilot\Repositories\AuditLog;
use LinkGrowth\Autopilot\Repositories\Jobs;
use LinkGrowth\Autopilot\Support\Limits;
use LinkGrowth\Autopilot\Support\Locks;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Opcache;
use LinkGrowth\Autopilot\Support\Secrets;
use LinkGrowth\Autopilot\Support\ServerResources;
use LinkGrowth\Autopilot\Submission\Policy;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\SettingsSchema;

final class AutomationModule extends AbstractModule {

    public const ID = 'automation';

    public function id(): string {
        return self::ID;
    }

    public function services( Container $c ): void {
        $c->singleton( Jobs::class, static fn() => new Jobs() );
        // The one approval policy (Autopilot switch + the individual outreach / submission settings).
        $c->singleton( Autopilot::class, static fn( Container $c ) => new Autopilot( $c->get( Settings::class ), $c->get( Secrets::class ), $c->get( AuditLog::class ) ) );
        $c->singleton( Audit::class, static fn( Container $c ) => new Audit( $c->get( AuditLog::class ) ) );
        // Server pressure: background work pauses while OPcache restarts, a backup runs, the plugin was just updated,
        // or the server is busy or low on memory (ServerResources: read only while a pause is decided).
        $c->singleton( LoadGuard::class, static fn( Container $c ) => new LoadGuard( $c->get( Opcache::class ), $c->get( ServerResources::class ) ) );
        $c->singleton( JobRunner::class, static fn( Container $c ) => new JobRunner( $c, $c->get( Jobs::class ), $c->get( Locks::class ), $c->get( Settings::class ), $c->get( Logger::class ), $c->get( AuditLog::class ) ) );
        // The scheduled tasks of every module and their gates (DESIGN.md §5.1, §5.2); Pipeline enqueues what it lets through.
        $c->singleton( TaskRegistry::class, static fn( Container $c ) => new TaskRegistry( $c, Plugin::instance()->modules() ) );
        $c->singleton( Pipeline::class, static fn( Container $c ) => new Pipeline( $c->get( Jobs::class ), $c->get( JobRunner::class ), $c->get( Settings::class ), $c->get( Limits::class ), $c->get( Logger::class ), $c->get( LoadGuard::class ), $c->get( TaskRegistry::class ) ) );
        $c->singleton( Scheduler::class, static fn( Container $c ) => new Scheduler( $c->get( Pipeline::class ), $c->get( Settings::class ), $c->get( Logger::class ), $c->get( LoadGuard::class ) ) );

        // --- Growth run ----------------------------------------------------
        // Resolves JobRunner, Scheduler and the other services lazily (Pipeline -> GrowthRun -> Scheduler -> Pipeline).
        $c->singleton( GrowthRun::class, static fn( Container $c ) => new GrowthRun( $c ) );
    }

    public function boot( Plugin $plugin ): void {
        if ( $plugin->isMaintenanceRequest() ) {
            // Self-healing schedule check for the recurring tick (at most hourly).
            add_action( 'init', [ $plugin, 'maybeSchedule' ], 20 );
            // Server pressure (1.2.4): an update starts the 10-minute quiet window on the first admin, cron or WP-CLI
            // request that runs the new version (one in-memory compare of an autoloaded option otherwise). The
            // background tick runs on cron and LoadGuard::pauseReason() notes the version itself, so the window is in
            // place before any background work, and a visitor's page view never writes the marker.
            $plugin->noteVersion();
        }

        // Background automation: the recurring tick and its WP-Cron interval.
        add_action( Plugin::TICK_HOOK, $plugin->lazy( Scheduler::class, 'onTick' ) );
        add_filter( 'cron_schedules', [ $plugin, 'cronSchedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
    }

    public function tables(): array {
        $t       = static fn( string $n ): string => Schema::table( $n );
        $charset = Schema::charsetCollate();

        return [
            'jobs' => "CREATE TABLE {$t('jobs')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(40) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'queued',
  priority tinyint(3) unsigned NOT NULL DEFAULT 5,
  payload longtext,
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  max_attempts tinyint(3) unsigned NOT NULL DEFAULT 3,
  scheduled_at datetime NOT NULL,
  started_at datetime DEFAULT NULL,
  finished_at datetime DEFAULT NULL,
  result longtext,
  error text,
  lock_token varchar(40) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status_sched (status,scheduled_at),
  KEY type (type)
) $charset;",
        ];
    }

    public function settingsDefaults(): array {
        return [
            'automation' => [
                'enabled'               => true,
                // One switch for hands-off growth (Automation\Autopilot): written only through
                // POST /automation/autopilot or POST /settings with confirm=true, which records the owner's consent.
                'autopilot'             => false,
                'discovery'             => true,
                'evaluation'            => true,
                'opportunities'         => true,
                'packaging'             => true,
                'submissions'           => true,
                'submission_mode'       => 'auto',
                'auto_outreach_drafts'  => true,
                'outreach_autosend'     => false,
                'monitoring'            => true,
                'imports'               => true,
                'daily_limits'          => [
                    'discovery_queries' => 15,
                    'evaluations'       => 40,
                    'submissions'       => 5,
                    'outreach_emails'   => 10,
                    'backlink_checks'   => 150,
                    'provider_lookups'  => 60,
                ],
                'weekly_limits'         => [
                    'discovery_queries' => 80,
                    'submissions'       => 20,
                    'outreach_emails'   => 40,
                ],
                'min_score_auto_queue'  => 70,
                'min_score_keep'        => 50,
                'max_execution_seconds' => 20,
                'tick_interval'         => 300,
                'international'         => true,
            ],
            'growth'     => [
                'evaluate_rounds' => 6,  // most evaluate job runs per growth step (1–20)
                'step_seconds'    => 20, // seconds per POST /growth/step (8–25)
            ],
        ];
    }

    /**
     * The automation and growth rules. automation.autopilot is accepted here as a boolean, but POST /settings never
     * stores it as sent: switching it goes through Automation\Autopilot (consent record, confirmation).
     */
    public function settingsRules(): array {
        $flag   = SettingsSchema::bool();
        $budget = SettingsSchema::int( 0 ); // limits: whole numbers from 0 (0 = none allowed), no upper bound
        return [
            'automation.enabled'                         => $flag,
            'automation.autopilot'                       => $flag,
            'automation.discovery'                       => $flag,
            'automation.evaluation'                      => $flag,
            'automation.opportunities'                   => $flag,
            'automation.packaging'                       => $flag,
            'automation.submissions'                     => $flag,
            // An unknown mode becomes 'auto', as the engine stored it.
            'automation.submission_mode'                 => SettingsSchema::enum( Policy::SUBMISSION_MODES, 'auto' ),
            'automation.auto_outreach_drafts'            => $flag,
            'automation.outreach_autosend'               => $flag,
            'automation.monitoring'                      => $flag,
            'automation.imports'                         => $flag,
            'automation.daily_limits.discovery_queries'  => $budget,
            'automation.daily_limits.evaluations'        => $budget,
            'automation.daily_limits.submissions'        => $budget,
            'automation.daily_limits.outreach_emails'    => $budget,
            'automation.daily_limits.backlink_checks'    => $budget,
            'automation.daily_limits.provider_lookups'   => $budget,
            'automation.weekly_limits.discovery_queries' => $budget,
            'automation.weekly_limits.submissions'       => $budget,
            'automation.weekly_limits.outreach_emails'   => $budget,
            'automation.min_score_auto_queue'            => SettingsSchema::int( 0, 100 ),
            'automation.min_score_keep'                  => SettingsSchema::int( 0, 100 ),
            // The runner raises lower values to JobRunner::MIN_TICK_SECONDS.
            'automation.max_execution_seconds'           => SettingsSchema::int( 15, 120 ),
            'automation.tick_interval'                   => SettingsSchema::int( 60, 86400 ),
            'automation.international'                   => $flag,
            'growth.evaluate_rounds'                     => SettingsSchema::int( 1, 20 ),
            'growth.step_seconds'                        => SettingsSchema::int( 8, 25 ),
        ];
    }

    public function jobs(): array {
        return [ 'cleanup' => CleanupJob::class ];
    }

    /**
     * The automation task, with the engine's cadences (DESIGN.md §5.1; Automation\TaskDefinition explains the WP2 values).
     *
     * @return list<TaskDefinition>
     */
    public function tasks(): array {
        return [
            new TaskDefinition( id: 'cleanup', jobType: 'cleanup', label: __( 'Housekeeping', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::DAILY, module: '', dueCheck: null, priority: CleanupJob::priority(), requiresProfile: false, offAllowed: false, order: 150 ),
        ];
    }

    public function restControllers(): array {
        return [ AutomationController::class, GrowthController::class ];
    }

    public function adminPages(): array {
        return [
            'automation' => [ 'label' => __( 'Automation', 'linkgrowth-autopilot' ), 'icon' => 'zap', 'module' => 'pages/automation.js', 'cap' => Capabilities::CAP, 'order' => 70 ],
        ];
    }

    public function settingsTabs(): array {
        return [
            'automation' => [ 'label' => __( 'Automation & limits', 'linkgrowth-autopilot' ), 'module' => 'pages/settings.js', 'order' => 20 ],
        ];
    }
}
