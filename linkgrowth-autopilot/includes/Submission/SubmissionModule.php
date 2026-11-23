<?php
/**
 * Module `submission` (DESIGN.md §3.2 Acquisition workflows row, submissions): listing packages, the submission policy,
 * the adapters, submission and listing verification; the submissions table, the package, submit and verify jobs, the
 * Submissions page (the owner-task queue of the submitter role) and its controller.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Submission;

use LinkGrowth\Autopilot\Admin\Capabilities;
use LinkGrowth\Autopilot\Admin\Rest\SubmissionsController;
use LinkGrowth\Autopilot\Analysis\PageAnalyzer;
use LinkGrowth\Autopilot\Analysis\SubmissionPageFinder;
use LinkGrowth\Autopilot\Automation\Frequency;
use LinkGrowth\Autopilot\Automation\Jobs\PackageJob;
use LinkGrowth\Autopilot\Automation\Jobs\SubmitJob;
use LinkGrowth\Autopilot\Automation\Jobs\VerifyListingsJob;
use LinkGrowth\Autopilot\Automation\TaskDefinition;
use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Content\ContentGenerator;
use LinkGrowth\Autopilot\Content\FactsRegistry;
use LinkGrowth\Autopilot\Content\LinkableAssets;
use LinkGrowth\Autopilot\Core\AbstractModule;
use LinkGrowth\Autopilot\Database\Schema;
use LinkGrowth\Autopilot\Integrations\ProviderRegistry;
use LinkGrowth\Autopilot\Monitoring\Notifier;
use LinkGrowth\Autopilot\Repositories\AuditLog;
use LinkGrowth\Autopilot\Repositories\Backlinks;
use LinkGrowth\Autopilot\Repositories\Opportunities;
use LinkGrowth\Autopilot\Repositories\Prospects;
use LinkGrowth\Autopilot\Repositories\Submissions;
use LinkGrowth\Autopilot\Support\Http\Client;
use LinkGrowth\Autopilot\Support\Http\RobotsTxt;
use LinkGrowth\Autopilot\Support\Limits;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Settings;

final class SubmissionModule extends AbstractModule {

    public const ID = 'submission';

    public function id(): string {
        return self::ID;
    }

    public function services( Container $c ): void {
        // The due-checks of this module's tasks (Automation\TaskRegistry resolves them by name).
        $c->singleton( DueChecks::class, static fn( Container $c ) => new DueChecks( $c ) );
        $c->singleton( Submissions::class, static fn() => new Submissions() );
        $c->singleton( PackageBuilder::class, static fn( Container $c ) => new PackageBuilder( $c->get( Settings::class ), $c->get( ContentGenerator::class ), $c->get( FactsRegistry::class ), $c->get( LinkableAssets::class ) ) );
        $c->singleton( Policy::class, static fn( Container $c ) => new Policy( $c->get( Settings::class ), $c->get( RobotsTxt::class ), $c->get( Limits::class ), $c->get( Submissions::class ) ) );
        $c->singleton( AdapterRegistry::class, static fn( Container $c ) => new AdapterRegistry( $c ) );
        $c->singleton( ListingVerifier::class, static fn( Container $c ) => new ListingVerifier( $c->get( Client::class ), $c->get( PageAnalyzer::class ), $c->get( ProviderRegistry::class ), $c->get( Submissions::class ), $c->get( Backlinks::class ), $c->get( Prospects::class ), $c->get( Settings::class ), $c->get( Notifier::class ), $c->get( AuditLog::class ), $c->get( Logger::class ) ) );
        $c->singleton( SubmissionEngine::class, static fn( Container $c ) => new SubmissionEngine( $c->get( Submissions::class ), $c->get( Prospects::class ), $c->get( Opportunities::class ), $c->get( PackageBuilder::class ), $c->get( Policy::class ), $c->get( AdapterRegistry::class ), $c->get( SubmissionPageFinder::class ), $c->get( Limits::class ), $c->get( AuditLog::class ), $c->get( Notifier::class ), $c->get( Logger::class ) ) );
    }

    public function tables(): array {
        $t       = static fn( string $n ): string => Schema::table( $n );
        $charset = Schema::charsetCollate();

        return [
            'submissions' => "CREATE TABLE {$t('submissions')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  prospect_id bigint(20) unsigned NOT NULL,
  opportunity_id bigint(20) unsigned DEFAULT NULL,
  method varchar(20) NOT NULL DEFAULT 'manual',
  adapter varchar(60) NOT NULL DEFAULT 'manual_package',
  status varchar(30) NOT NULL DEFAULT 'pending_review',
  target_url varchar(700) NOT NULL DEFAULT '',
  target_hash char(40) NOT NULL DEFAULT '',
  category varchar(190) NOT NULL DEFAULT '',
  payload longtext,
  response longtext,
  confirmation_ref varchar(190) NOT NULL DEFAULT '',
  listing_url varchar(700) NOT NULL DEFAULT '',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  last_error text,
  scheduled_at datetime DEFAULT NULL,
  submitted_at datetime DEFAULT NULL,
  confirmed_at datetime DEFAULT NULL,
  next_verify_at datetime DEFAULT NULL,
  reviewed_by bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY prospect_target (prospect_id,target_hash),
  KEY status (status),
  KEY next_verify_at (next_verify_at)
) $charset;",
        ];
    }

    public function jobs(): array {
        return [
            'package'         => PackageJob::class,
            'submit'          => SubmitJob::class,
            'verify_listings' => VerifyListingsJob::class,
        ];
    }

    /**
     * The submission tasks, with the engine's cadences (DESIGN.md §5.1; Automation\TaskDefinition explains the WP2 values).
     *
     * @return list<TaskDefinition>
     */
    public function tasks(): array {
        return [
            new TaskDefinition( id: 'package', jobType: 'package', label: __( 'Package submissions', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::HOURLY, module: 'packaging', dueCheck: null, priority: PackageJob::priority(), order: 70 ),
            new TaskDefinition( id: 'submit', jobType: 'submit', label: __( 'Process submission queue', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::CONTINUOUS, module: 'submissions', dueCheck: 'submitDue', priority: SubmitJob::priority(), budgetPool: 'submissions', requiresSetup: true, consent: 'submissions', autopilot: true, outbound: true, cadence: 'every tick (when approved submissions are queued)', order: 80 ),
            new TaskDefinition( id: 'verify_listings', jobType: 'verify_listings', label: __( 'Verify listings', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::SIX_HOURS, module: '', dueCheck: null, priority: VerifyListingsJob::priority(), outbound: true, order: 90 ),
        ];
    }

    public function restControllers(): array {
        return [ SubmissionsController::class ];
    }

    public function adminPages(): array {
        return [
            'submissions' => [ 'label' => __( 'Submissions', 'linkgrowth-autopilot' ), 'icon' => 'package', 'module' => 'pages/submissions.js', 'cap' => Capabilities::CAP, 'order' => 30 ],
        ];
    }
}
