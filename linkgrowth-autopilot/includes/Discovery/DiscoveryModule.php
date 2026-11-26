<?php
/**
 * Module `discovery` (DESIGN.md §3.2 Discovery row): queries, pack seeds, classification, brand mentions, broken links,
 * competitor backlinks, the catalog import and prospect intake; the prospects, opportunities and search log tables,
 * the discovery settings, the discovery jobs, the Prospects page and its controller.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Discovery;

use LinkGrowth\Autopilot\Admin\Capabilities;
use LinkGrowth\Autopilot\Admin\Rest\ProspectsController;
use LinkGrowth\Autopilot\Analysis\PageAnalyzer;
use LinkGrowth\Autopilot\Analysis\Taxonomy;
use LinkGrowth\Autopilot\Automation\Frequency;
use LinkGrowth\Autopilot\Automation\Jobs\BrokenLinkScanJob;
use LinkGrowth\Autopilot\Automation\Jobs\CatalogImportJob;
use LinkGrowth\Autopilot\Automation\Jobs\DiscoverJob;
use LinkGrowth\Autopilot\Automation\Jobs\FindOpportunitiesJob;
use LinkGrowth\Autopilot\Automation\Jobs\MentionScanJob;
use LinkGrowth\Autopilot\Automation\TaskDefinition;
use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Content\ClaimsFilter;
use LinkGrowth\Autopilot\Content\ContentGenerator;
use LinkGrowth\Autopilot\Content\LinkableAssets;
use LinkGrowth\Autopilot\Core\AbstractModule;
use LinkGrowth\Autopilot\Database\Schema;
use LinkGrowth\Autopilot\Integrations\ProviderRegistry;
use LinkGrowth\Autopilot\Repositories\AuditLog;
use LinkGrowth\Autopilot\Repositories\Opportunities;
use LinkGrowth\Autopilot\Repositories\Prospects;
use LinkGrowth\Autopilot\Repositories\SearchLog;
use LinkGrowth\Autopilot\Repositories\Submissions;
use LinkGrowth\Autopilot\Repositories\Suppression;
use LinkGrowth\Autopilot\Support\Http\Client;
use LinkGrowth\Autopilot\Support\Limits;
use LinkGrowth\Autopilot\Support\Locks;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\SettingsSchema;

final class DiscoveryModule extends AbstractModule {

    public const ID = 'discovery';

    public function id(): string {
        return self::ID;
    }

    public function services( Container $c ): void {
        // The due-checks of this module's tasks (Automation\TaskRegistry resolves them by name).
        $c->singleton( DueChecks::class, static fn( Container $c ) => new DueChecks( $c ) );
        foreach ( [ Prospects::class, Opportunities::class, SearchLog::class ] as $repo ) {
            $c->singleton( $repo, static fn() => new $repo() );
        }
        $c->singleton( DomainFilter::class, static fn( Container $c ) => new DomainFilter( $c->get( Settings::class ), $c->get( Suppression::class ) ) );
        $c->singleton( Classifier::class, static fn( Container $c ) => new Classifier( $c->get( Taxonomy::class ) ) );
        $c->singleton( QueryGenerator::class, static fn( Container $c ) => new QueryGenerator( $c->get( Settings::class ), $c->get( SearchLog::class ) ) );
        $c->singleton( PackSeeds::class, static fn() => new PackSeeds() );
        $c->singleton( ProspectIntake::class, static fn( Container $c ) => new ProspectIntake( $c->get( Prospects::class ), $c->get( Opportunities::class ), $c->get( DomainFilter::class ), $c->get( Classifier::class ), $c->get( AuditLog::class ), $c->get( Logger::class ) ) );
        $c->singleton( CompetitorBacklinkSource::class, static fn( Container $c ) => new CompetitorBacklinkSource( $c->get( ProviderRegistry::class ), $c->get( Settings::class ), $c->get( Logger::class ), $c->get( Limits::class ), $c->get( Taxonomy::class ), $c->get( AuditLog::class ) ) );
        $c->singleton( MentionFinder::class, static fn( Container $c ) => new MentionFinder( $c->get( ProviderRegistry::class ), $c->get( Client::class ), $c->get( PageAnalyzer::class ), $c->get( Settings::class ), $c->get( ProspectIntake::class ), $c->get( SearchLog::class ), $c->get( Logger::class ) ) );
        $c->singleton( BrokenLinkFinder::class, static fn( Container $c ) => new BrokenLinkFinder( $c->get( Client::class ), $c->get( PageAnalyzer::class ), $c->get( Taxonomy::class ), $c->get( LinkableAssets::class ), $c->get( Opportunities::class ), $c->get( Logger::class ) ) );
        $c->singleton( DiscoveryEngine::class, static fn( Container $c ) => new DiscoveryEngine( $c->get( ProviderRegistry::class ), $c->get( QueryGenerator::class ), $c->get( PackSeeds::class ), $c->get( ProspectIntake::class ), $c->get( CompetitorBacklinkSource::class ), $c->get( SearchLog::class ), $c->get( Limits::class ), $c->get( Settings::class ), $c->get( Logger::class ) ) );
        $c->singleton( CatalogImporter::class, static fn( Container $c ) => new CatalogImporter( $c->get( ProspectIntake::class ), $c->get( Prospects::class ), $c->get( Opportunities::class ), $c->get( Submissions::class ), $c->get( ClaimsFilter::class ), $c->get( AuditLog::class ), $c->get( Logger::class ), $c->get( ContentGenerator::class ), $c->get( Locks::class ) ) );
    }

    public function tables(): array {
        $t       = static fn( string $n ): string => Schema::table( $n );
        $charset = Schema::charsetCollate();

        return [
            'prospects' => "CREATE TABLE {$t('prospects')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  domain varchar(190) NOT NULL,
  name varchar(255) NOT NULL DEFAULT '',
  homepage_url varchar(500) NOT NULL DEFAULT '',
  country varchar(2) NOT NULL DEFAULT '',
  language varchar(10) NOT NULL DEFAULT '',
  site_type varchar(40) NOT NULL DEFAULT 'unknown',
  opportunity_type varchar(40) NOT NULL DEFAULT 'unknown',
  status varchar(30) NOT NULL DEFAULT 'discovered',
  score tinyint(3) unsigned DEFAULT NULL,
  score_class varchar(20) NOT NULL DEFAULT '',
  score_confidence tinyint(3) unsigned NOT NULL DEFAULT 0,
  score_breakdown longtext,
  relevance tinyint(3) unsigned DEFAULT NULL,
  metrics longtext,
  profile longtext,
  risk_flags longtext,
  submission_url varchar(700) NOT NULL DEFAULT '',
  contact_url varchar(700) NOT NULL DEFAULT '',
  recommended_action varchar(60) NOT NULL DEFAULT '',
  discovered_via varchar(60) NOT NULL DEFAULT '',
  discovery_query varchar(255) NOT NULL DEFAULT '',
  automation_allowed tinyint(1) NOT NULL DEFAULT 0,
  submission_config longtext,
  notes text,
  rejection_reason varchar(255) NOT NULL DEFAULT '',
  first_seen datetime NOT NULL,
  last_evaluated datetime DEFAULT NULL,
  next_review datetime DEFAULT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY domain (domain),
  KEY status (status),
  KEY score (score),
  KEY opportunity_type (opportunity_type),
  KEY site_type (site_type),
  KEY country (country),
  KEY next_review (next_review)
) $charset;",

            'opportunities' => "CREATE TABLE {$t('opportunities')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  prospect_id bigint(20) unsigned NOT NULL,
  type varchar(40) NOT NULL DEFAULT 'unknown',
  url varchar(700) NOT NULL DEFAULT '',
  url_hash char(40) NOT NULL DEFAULT '',
  title varchar(255) NOT NULL DEFAULT '',
  details longtext,
  status varchar(30) NOT NULL DEFAULT 'open',
  score tinyint(3) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY prospect_type_url (prospect_id,type,url_hash),
  KEY status (status),
  KEY type (type)
) $charset;",

            'search_log' => "CREATE TABLE {$t('search_log')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  provider varchar(30) NOT NULL DEFAULT '',
  query varchar(500) NOT NULL DEFAULT '',
  query_hash char(40) NOT NULL DEFAULT '',
  results int(11) unsigned NOT NULL DEFAULT 0,
  new_prospects int(11) unsigned NOT NULL DEFAULT 0,
  run_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY query_hash (query_hash),
  KEY run_at (run_at)
) $charset;",
        ];
    }

    public function settingsDefaults(): array {
        return [
            'discovery' => [
                'geo_modifiers'       => [ 'Canada', 'Canadian', 'Ontario', 'Toronto', 'British Columbia', 'Alberta', 'Quebec', 'Vancouver', 'Montreal', 'Ottawa' ],
                'competitor_domains'  => [],
                // Competitors Autopilot found through SE Ranking while competitor_domains was empty (kept apart from
                // the owner's list; removable in Settings → Discovery; see Discovery\CompetitorBacklinkSource).
                'competitor_domains_auto' => [],
                'brand_terms'         => [], // the brand name, legal name, site title and domain are always brand terms (Seo\BrandTerms)
                'query_cooldown_days' => 30,
                'results_per_query'   => 10,
                'seed_catalog'        => true,
                'custom_queries'      => [],
                'excluded_domains'    => [],
            ],
        ];
    }

    public function settingsRules(): array {
        return [
            'discovery.geo_modifiers'           => SettingsSchema::list(),
            'discovery.competitor_domains'      => SettingsSchema::list( 'domain' ),
            'discovery.competitor_domains_auto' => SettingsSchema::list( 'domain' ),
            'discovery.brand_terms'             => SettingsSchema::list(),
            'discovery.query_cooldown_days'     => SettingsSchema::int( 0, 365 ),
            'discovery.results_per_query'       => SettingsSchema::int( 1, 50 ),
            'discovery.seed_catalog'            => SettingsSchema::bool(),
            'discovery.custom_queries'          => SettingsSchema::list(),
            'discovery.excluded_domains'        => SettingsSchema::list( 'domain' ),
        ];
    }

    public function jobs(): array {
        return [
            'discover'           => DiscoverJob::class,
            'find_opportunities' => FindOpportunitiesJob::class,
            'mention_scan'       => MentionScanJob::class,
            'broken_link_scan'   => BrokenLinkScanJob::class,
            'import_verified'    => CatalogImportJob::class,
        ];
    }

    /**
     * The discovery tasks, with the engine's cadences (DESIGN.md §5.1; Automation\TaskDefinition explains the WP2 values).
     * The engine runs the broken-link scan only when a person starts it (off; DESIGN.md §5.1 makes it daily from WP10).
     *
     * @return list<TaskDefinition>
     */
    public function tasks(): array {
        return [
            new TaskDefinition( id: 'discover', jobType: 'discover', label: __( 'Discover prospects', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::DAILY, module: 'discovery', dueCheck: null, priority: DiscoverJob::priority(), outbound: true, order: 10 ),
            new TaskDefinition( id: 'mention_scan', jobType: 'mention_scan', label: __( 'Scan brand mentions', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::WEEKLY, module: 'discovery', dueCheck: null, priority: MentionScanJob::priority(), outbound: true, order: 20 ),
            new TaskDefinition( id: 'find_opportunities', jobType: 'find_opportunities', label: __( 'Find opportunities', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::CONTINUOUS, module: 'opportunities', dueCheck: 'opportunitiesDue', priority: FindOpportunitiesJob::priority(), outbound: true, cadence: 'every tick (when qualified prospects lack opportunities)', order: 40 ),
            new TaskDefinition( id: 'catalog_import', jobType: 'import_verified', label: __( 'Import the opportunity catalog', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::CONTINUOUS, module: '', dueCheck: 'catalogImportDue', priority: CatalogImportJob::priority(), cadence: 'when the bundled verified opportunity data changes', order: 60 ),
            new TaskDefinition( id: 'broken_link_scan', jobType: 'broken_link_scan', label: __( 'Scan for broken links', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::OFF, module: '', dueCheck: null, priority: BrokenLinkScanJob::priority(), outbound: true, order: 200 ),
        ];
    }

    public function restControllers(): array {
        return [ ProspectsController::class ];
    }

    public function adminPages(): array {
        return [
            'prospects' => [ 'label' => __( 'Prospects', 'linkgrowth-autopilot' ), 'icon' => 'target', 'module' => 'pages/prospects.js', 'cap' => Capabilities::CAP, 'order' => 20 ],
        ];
    }

    public function settingsTabs(): array {
        return [
            'discovery' => [ 'label' => __( 'Discovery', 'linkgrowth-autopilot' ), 'module' => 'settings/discovery.js', 'order' => 30 ],
        ];
    }
}
