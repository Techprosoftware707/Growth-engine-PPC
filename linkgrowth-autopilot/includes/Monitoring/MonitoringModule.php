<?php
/**
 * Module `monitoring` (DESIGN.md §3.2 Backlink verification row): backlink checks, provider and GA4 imports and the
 * notifier; the backlinks table, the monitoring settings and jobs, the Backlinks page and its controller.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Monitoring;

use LinkGrowth\Autopilot\Admin\Capabilities;
use LinkGrowth\Autopilot\Admin\Rest\BacklinksController;
use LinkGrowth\Autopilot\Analysis\PageAnalyzer;
use LinkGrowth\Autopilot\Automation\Frequency;
use LinkGrowth\Autopilot\Automation\Jobs\ImportBacklinksJob;
use LinkGrowth\Autopilot\Automation\Jobs\MonitorBacklinksJob;
use LinkGrowth\Autopilot\Automation\TaskDefinition;
use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Core\AbstractModule;
use LinkGrowth\Autopilot\Database\Schema;
use LinkGrowth\Autopilot\Integrations\Google\Analytics4;
use LinkGrowth\Autopilot\Integrations\ProviderRegistry;
use LinkGrowth\Autopilot\Repositories\AuditLog;
use LinkGrowth\Autopilot\Repositories\Backlinks;
use LinkGrowth\Autopilot\Repositories\Notifications;
use LinkGrowth\Autopilot\Repositories\Prospects;
use LinkGrowth\Autopilot\Support\Http\Client;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\SettingsSchema;

final class MonitoringModule extends AbstractModule {

    public const ID = 'monitoring';

    public function id(): string {
        return self::ID;
    }

    public function services( Container $c ): void {
        // The due-checks of this module's tasks (Automation\TaskRegistry resolves them by name).
        $c->singleton( DueChecks::class, static fn( Container $c ) => new DueChecks( $c ) );
        $c->singleton( Backlinks::class, static fn() => new Backlinks() );
        $c->singleton( Notifier::class, static fn( Container $c ) => new Notifier( $c->get( Notifications::class ), $c->get( Settings::class ), $c->get( Logger::class ) ) );
        $c->singleton( BacklinkMonitor::class, static fn( Container $c ) => new BacklinkMonitor( $c->get( Backlinks::class ), $c->get( Client::class ), $c->get( PageAnalyzer::class ), $c->get( Settings::class ), $c->get( Notifier::class ), $c->get( AuditLog::class ), $c->get( Logger::class ) ) );
        $c->singleton( BacklinkImporter::class, static fn( Container $c ) => new BacklinkImporter( $c->get( Backlinks::class ), $c->get( Prospects::class ), $c->get( ProviderRegistry::class ), $c->get( Analytics4::class ), $c->get( Settings::class ), $c->get( Notifier::class ), $c->get( AuditLog::class ), $c->get( Logger::class ) ) );
    }

    public function tables(): array {
        $t       = static fn( string $n ): string => Schema::table( $n );
        $charset = Schema::charsetCollate();

        return [
            'backlinks' => "CREATE TABLE {$t('backlinks')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  prospect_id bigint(20) unsigned DEFAULT NULL,
  source_domain varchar(190) NOT NULL,
  source_url varchar(700) NOT NULL DEFAULT '',
  source_hash char(40) NOT NULL DEFAULT '',
  target_url varchar(700) NOT NULL DEFAULT '',
  target_hash char(40) NOT NULL DEFAULT '',
  anchor_text varchar(500) NOT NULL DEFAULT '',
  rel varchar(60) NOT NULL DEFAULT 'dofollow',
  placement varchar(30) NOT NULL DEFAULT 'unknown',
  status varchar(20) NOT NULL DEFAULT 'pending',
  http_status smallint(5) unsigned DEFAULT NULL,
  is_indexable tinyint(1) DEFAULT NULL,
  origin varchar(40) NOT NULL DEFAULT 'manual',
  quality_score tinyint(3) unsigned DEFAULT NULL,
  first_seen datetime NOT NULL,
  last_checked datetime DEFAULT NULL,
  last_seen_live datetime DEFAULT NULL,
  next_check_at datetime DEFAULT NULL,
  lost_at datetime DEFAULT NULL,
  consecutive_failures tinyint(3) unsigned NOT NULL DEFAULT 0,
  changes longtext,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_target (source_hash,target_hash),
  KEY source_domain (source_domain),
  KEY status (status),
  KEY next_check_at (next_check_at)
) $charset;",
        ];
    }

    public function settingsDefaults(): array {
        return [
            'monitoring' => [
                'check_interval_days_new'     => 1,
                'check_interval_days_stable'  => 7,
                'lost_after_failures'         => 2,
                'digest_email'                => true,
                'notify_email'                => '',
                'notify_critical_immediately' => true,
                'import_interval_days'        => 7,
                'import_limit'                => 500,
            ],
        ];
    }

    public function settingsRules(): array {
        return [
            'monitoring.check_interval_days_new'     => SettingsSchema::int( 1, 30 ),
            'monitoring.check_interval_days_stable'  => SettingsSchema::int( 1, 90 ),
            'monitoring.lost_after_failures'         => SettingsSchema::int( 1, 10 ),
            'monitoring.digest_email'                => SettingsSchema::bool(),
            'monitoring.notify_email'                => SettingsSchema::email(),
            'monitoring.notify_critical_immediately' => SettingsSchema::bool(),
            'monitoring.import_interval_days'        => SettingsSchema::int( 1, 90 ),
            'monitoring.import_limit'                => SettingsSchema::int( 10, 5000 ),
        ];
    }

    public function jobs(): array {
        return [
            'monitor_backlinks' => MonitorBacklinksJob::class,
            'import_backlinks'  => ImportBacklinksJob::class,
        ];
    }

    /**
     * The monitoring tasks, with the engine's cadences (DESIGN.md §5.1; Automation\TaskDefinition explains the WP2 values).
     * The monitor checks the backlink_checks limit in its due-check (the digest is sent whatever the limit), so the task names no pool.
     *
     * @return list<TaskDefinition>
     */
    public function tasks(): array {
        return [
            new TaskDefinition( id: 'monitor_backlinks', jobType: 'monitor_backlinks', label: __( 'Monitor backlinks', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::CONTINUOUS, module: 'monitoring', dueCheck: 'monitorDue', priority: MonitorBacklinksJob::priority(), outbound: true, cadence: 'every tick (when backlinks are due)', order: 130 ),
            new TaskDefinition( id: 'import_backlinks', jobType: 'import_backlinks', label: __( 'Import backlinks', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::WEEKLY, module: 'imports', dueCheck: null, priority: ImportBacklinksJob::priority(), outbound: true, order: 140, schedule: 'importSchedule' ),
        ];
    }

    public function restControllers(): array {
        return [ BacklinksController::class ];
    }

    public function adminPages(): array {
        return [
            'backlinks' => [ 'label' => __( 'Backlinks', 'linkgrowth-autopilot' ), 'icon' => 'link', 'module' => 'pages/backlinks.js', 'cap' => Capabilities::CAP, 'order' => 50 ],
        ];
    }

    public function settingsTabs(): array {
        return [
            'monitoring' => [ 'label' => __( 'Monitoring', 'linkgrowth-autopilot' ), 'module' => 'pages/settings.js', 'order' => 70 ],
        ];
    }
}
