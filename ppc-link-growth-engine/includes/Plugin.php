<?php
/**
 * Plugin bootstrap: wires services, hooks and lifecycle.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth;

use PPC\LinkGrowth\Admin\Assets;
use PPC\LinkGrowth\Admin\Capabilities;
use PPC\LinkGrowth\Admin\Menu;
use PPC\LinkGrowth\Admin\Notices;
use PPC\LinkGrowth\Admin\Rest\RestApi;
use PPC\LinkGrowth\Analysis\ContactExtractor;
use PPC\LinkGrowth\Analysis\FormDetector;
use PPC\LinkGrowth\Analysis\PageAnalyzer;
use PPC\LinkGrowth\Analysis\RelevanceScorer;
use PPC\LinkGrowth\Analysis\RiskAnalyzer;
use PPC\LinkGrowth\Analysis\SiteProfiler;
use PPC\LinkGrowth\Analysis\SubmissionPageFinder;
use PPC\LinkGrowth\Analysis\Taxonomy;
use PPC\LinkGrowth\Automation\Audit;
use PPC\LinkGrowth\Automation\JobRunner;
use PPC\LinkGrowth\Automation\Pipeline;
use PPC\LinkGrowth\Automation\Scheduler;
use PPC\LinkGrowth\Content\ClaimsFilter;
use PPC\LinkGrowth\Content\ContentGenerator;
use PPC\LinkGrowth\Content\FactsRegistry;
use PPC\LinkGrowth\Content\LinkableAssets;
use PPC\LinkGrowth\Database\Backup;
use PPC\LinkGrowth\Database\Migrator;
use PPC\LinkGrowth\Database\Schema;
use PPC\LinkGrowth\Discovery\BrokenLinkFinder;
use PPC\LinkGrowth\Discovery\Classifier;
use PPC\LinkGrowth\Discovery\CompetitorBacklinkSource;
use PPC\LinkGrowth\Discovery\DiscoveryEngine;
use PPC\LinkGrowth\Discovery\DomainFilter;
use PPC\LinkGrowth\Discovery\MentionFinder;
use PPC\LinkGrowth\Discovery\ProspectIntake;
use PPC\LinkGrowth\Discovery\QueryGenerator;
use PPC\LinkGrowth\Discovery\SeedCatalog;
use PPC\LinkGrowth\Integrations\ClaudeWriter;
use PPC\LinkGrowth\Integrations\Google\Analytics4;
use PPC\LinkGrowth\Integrations\Google\SearchConsole;
use PPC\LinkGrowth\Integrations\Google\ServiceAccount;
use PPC\LinkGrowth\Integrations\Google\SiteKitBridge;
use PPC\LinkGrowth\Integrations\MetricsAggregator;
use PPC\LinkGrowth\Integrations\ProviderRegistry;
use PPC\LinkGrowth\Integrations\Rdap;
use PPC\LinkGrowth\Monitoring\BacklinkImporter;
use PPC\LinkGrowth\Monitoring\BacklinkMonitor;
use PPC\LinkGrowth\Monitoring\Notifier;
use PPC\LinkGrowth\Outreach\Compliance;
use PPC\LinkGrowth\Outreach\FollowUps;
use PPC\LinkGrowth\Outreach\InboundWebhook;
use PPC\LinkGrowth\Outreach\Mailer;
use PPC\LinkGrowth\Outreach\OutreachEngine;
use PPC\LinkGrowth\Outreach\Personalizer;
use PPC\LinkGrowth\Outreach\Templates;
use PPC\LinkGrowth\Outreach\Unsubscribe;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\Backlinks;
use PPC\LinkGrowth\Repositories\Campaigns;
use PPC\LinkGrowth\Repositories\Contacts;
use PPC\LinkGrowth\Repositories\DomainMetrics;
use PPC\LinkGrowth\Repositories\Jobs;
use PPC\LinkGrowth\Repositories\Logs;
use PPC\LinkGrowth\Repositories\Notifications;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\SearchLog;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Repositories\Suppression;
use PPC\LinkGrowth\Scoring\ScoringEngine;
use PPC\LinkGrowth\Submission\AdapterRegistry;
use PPC\LinkGrowth\Submission\ListingVerifier;
use PPC\LinkGrowth\Submission\PackageBuilder;
use PPC\LinkGrowth\Submission\Policy;
use PPC\LinkGrowth\Submission\SubmissionEngine;
use PPC\LinkGrowth\Support\Crypto;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Http\RobotsTxt;
use PPC\LinkGrowth\Support\Limits;
use PPC\LinkGrowth\Support\Locks;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Support\Settings;

final class Plugin {

    private static ?Plugin $instance = null;

    private Container $container;

    private bool $booted = false;

    public static function instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->container = new Container();
        $this->registerServices();
    }

    public function container(): Container {
        return $this->container;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get( string $id ): object {
        return $this->container->get( $id );
    }

    public function boot(): void {
        if ( $this->booted ) {
            return;
        }
        $this->booted = true;

        load_plugin_textdomain( 'ppc-link-growth-engine', false, dirname( PPCLG_BASENAME ) . '/languages' );

        // Database migrations (with backup) when the stored version differs.
        add_action( 'init', [ $this->get( Migrator::class ), 'maybeMigrate' ], 1 );

        // Capabilities are (re)synced on init so role changes by other plugins do not strip ours.
        add_action( 'init', [ $this->get( Capabilities::class ), 'ensure' ], 2 );

        // REST API (admin + public endpoints).
        add_action( 'rest_api_init', [ $this->get( RestApi::class ), 'register' ] );

        // Background automation.
        $this->get( Scheduler::class )->register();

        if ( is_admin() ) {
            $this->get( Menu::class )->register();
            $this->get( Assets::class )->register();
            $this->get( Notices::class )->register();
        }

        /**
         * Fires once the Link Growth Engine has finished booting.
         *
         * @param Plugin $plugin
         */
        do_action( 'ppclg_booted', $this );
    }

    private function registerServices(): void {
        $c = $this->container;

        // --- Support -------------------------------------------------------
        $c->singleton( Crypto::class, static fn() => new Crypto() );
        $c->singleton( Settings::class, static fn() => new Settings() );
        $c->singleton( Secrets::class, static fn( Container $c ) => new Secrets( $c->get( Crypto::class ) ) );
        $c->singleton( Logs::class, static fn() => new Logs() );
        $c->singleton( Logger::class, static fn( Container $c ) => new Logger( $c->get( Logs::class ), $c->get( Settings::class ) ) );
        $c->singleton( RobotsTxt::class, static fn( Container $c ) => new RobotsTxt( $c->get( Logger::class ) ) );
        $c->singleton( Client::class, static fn( Container $c ) => new Client( $c->get( Settings::class ), $c->get( Logger::class ), $c->get( RobotsTxt::class ) ) );
        $c->singleton( Locks::class, static fn() => new Locks() );
        $c->singleton( Limits::class, static fn( Container $c ) => new Limits( $c->get( Settings::class ) ) );

        // --- Database ------------------------------------------------------
        $c->singleton( Schema::class, static fn() => new Schema() );
        $c->singleton( Backup::class, static fn( Container $c ) => new Backup( $c->get( Schema::class ), $c->get( Logger::class ) ) );
        $c->singleton( Migrator::class, static fn( Container $c ) => new Migrator( $c->get( Schema::class ), $c->get( Backup::class ), $c->get( Logger::class ), $c->get( AuditLog::class ) ) );

        // --- Repositories --------------------------------------------------
        foreach ( [
            Prospects::class,
            Opportunities::class,
            Submissions::class,
            Contacts::class,
            Campaigns::class,
            OutreachMessages::class,
            Backlinks::class,
            Suppression::class,
            Notifications::class,
            AuditLog::class,
            Jobs::class,
            DomainMetrics::class,
            SearchLog::class,
        ] as $repo ) {
            $c->singleton( $repo, static fn() => new $repo() );
        }

        // --- Analysis ------------------------------------------------------
        $c->singleton( Taxonomy::class, static fn( Container $c ) => new Taxonomy( $c->get( Settings::class ) ) );
        $c->singleton( PageAnalyzer::class, static fn() => new PageAnalyzer() );
        $c->singleton( FormDetector::class, static fn() => new FormDetector() );
        $c->singleton( ContactExtractor::class, static fn( Container $c ) => new ContactExtractor( $c->get( Suppression::class ) ) );
        $c->singleton( RelevanceScorer::class, static fn( Container $c ) => new RelevanceScorer( $c->get( Taxonomy::class ) ) );
        $c->singleton( RiskAnalyzer::class, static fn( Container $c ) => new RiskAnalyzer( $c->get( Taxonomy::class ) ) );
        $c->singleton( SubmissionPageFinder::class, static fn( Container $c ) => new SubmissionPageFinder( $c->get( Client::class ), $c->get( PageAnalyzer::class ), $c->get( FormDetector::class ), $c->get( Logger::class ) ) );
        $c->singleton( SiteProfiler::class, static fn( Container $c ) => new SiteProfiler( $c->get( Client::class ), $c->get( PageAnalyzer::class ), $c->get( ContactExtractor::class ), $c->get( FormDetector::class ), $c->get( Rdap::class ), $c->get( Logger::class ), $c->get( Settings::class ) ) );

        // --- Integrations --------------------------------------------------
        $c->singleton( Rdap::class, static fn( Container $c ) => new Rdap( $c->get( Client::class ) ) );
        $c->singleton( ProviderRegistry::class, static fn( Container $c ) => new ProviderRegistry( $c ) );
        $c->singleton( ServiceAccount::class, static fn( Container $c ) => new ServiceAccount( $c->get( Secrets::class ), $c->get( Client::class ), $c->get( Logger::class ) ) );
        $c->singleton( SearchConsole::class, static fn( Container $c ) => new SearchConsole( $c->get( ServiceAccount::class ), $c->get( Client::class ), $c->get( Settings::class ), $c->get( Logger::class ) ) );
        $c->singleton( Analytics4::class, static fn( Container $c ) => new Analytics4( $c->get( ServiceAccount::class ), $c->get( Client::class ), $c->get( Settings::class ), $c->get( Logger::class ) ) );
        $c->singleton( SiteKitBridge::class, static fn( Container $c ) => new SiteKitBridge( $c->get( Logger::class ) ) );
        $c->singleton( MetricsAggregator::class, static fn( Container $c ) => new MetricsAggregator( $c->get( ProviderRegistry::class ), $c->get( DomainMetrics::class ), $c->get( Settings::class ), $c->get( Logger::class ) ) );
        $c->singleton( ClaudeWriter::class, static fn( Container $c ) => new ClaudeWriter( $c->get( Secrets::class ), $c->get( Settings::class ), $c->get( Client::class ), $c->get( Logger::class ) ) );

        // --- Scoring -------------------------------------------------------
        $c->singleton( ScoringEngine::class, static fn( Container $c ) => new ScoringEngine( $c->get( Settings::class ) ) );

        // --- Content -------------------------------------------------------
        $c->singleton( FactsRegistry::class, static fn( Container $c ) => new FactsRegistry( $c->get( Settings::class ) ) );
        $c->singleton( ClaimsFilter::class, static fn( Container $c ) => new ClaimsFilter( $c->get( FactsRegistry::class ) ) );
        $c->singleton( LinkableAssets::class, static fn( Container $c ) => new LinkableAssets( $c->get( Settings::class ), $c->get( Taxonomy::class ) ) );
        $c->singleton( ContentGenerator::class, static fn( Container $c ) => new ContentGenerator( $c->get( FactsRegistry::class ), $c->get( ClaimsFilter::class ), $c->get( Settings::class ), $c->get( ClaudeWriter::class ), $c->get( Logger::class ) ) );

        // --- Discovery -----------------------------------------------------
        $c->singleton( DomainFilter::class, static fn( Container $c ) => new DomainFilter( $c->get( Settings::class ), $c->get( Suppression::class ) ) );
        $c->singleton( Classifier::class, static fn( Container $c ) => new Classifier( $c->get( Taxonomy::class ) ) );
        $c->singleton( QueryGenerator::class, static fn( Container $c ) => new QueryGenerator( $c->get( Settings::class ), $c->get( SearchLog::class ) ) );
        $c->singleton( SeedCatalog::class, static fn() => new SeedCatalog() );
        $c->singleton( ProspectIntake::class, static fn( Container $c ) => new ProspectIntake( $c->get( Prospects::class ), $c->get( Opportunities::class ), $c->get( DomainFilter::class ), $c->get( Classifier::class ), $c->get( AuditLog::class ), $c->get( Logger::class ) ) );
        $c->singleton( CompetitorBacklinkSource::class, static fn( Container $c ) => new CompetitorBacklinkSource( $c->get( ProviderRegistry::class ), $c->get( Settings::class ), $c->get( Logger::class ) ) );
        $c->singleton( MentionFinder::class, static fn( Container $c ) => new MentionFinder( $c->get( ProviderRegistry::class ), $c->get( Client::class ), $c->get( PageAnalyzer::class ), $c->get( Settings::class ), $c->get( ProspectIntake::class ), $c->get( SearchLog::class ), $c->get( Logger::class ) ) );
        $c->singleton( BrokenLinkFinder::class, static fn( Container $c ) => new BrokenLinkFinder( $c->get( Client::class ), $c->get( PageAnalyzer::class ), $c->get( Taxonomy::class ), $c->get( LinkableAssets::class ), $c->get( Opportunities::class ), $c->get( Logger::class ) ) );
        $c->singleton( DiscoveryEngine::class, static fn( Container $c ) => new DiscoveryEngine( $c->get( ProviderRegistry::class ), $c->get( QueryGenerator::class ), $c->get( SeedCatalog::class ), $c->get( ProspectIntake::class ), $c->get( CompetitorBacklinkSource::class ), $c->get( SearchLog::class ), $c->get( Limits::class ), $c->get( Settings::class ), $c->get( Logger::class ) ) );

        // --- Submission ----------------------------------------------------
        $c->singleton( PackageBuilder::class, static fn( Container $c ) => new PackageBuilder( $c->get( Settings::class ), $c->get( ContentGenerator::class ), $c->get( FactsRegistry::class ), $c->get( LinkableAssets::class ) ) );
        $c->singleton( Policy::class, static fn( Container $c ) => new Policy( $c->get( Settings::class ), $c->get( RobotsTxt::class ), $c->get( Limits::class ), $c->get( Submissions::class ) ) );
        $c->singleton( AdapterRegistry::class, static fn( Container $c ) => new AdapterRegistry( $c ) );
        $c->singleton( ListingVerifier::class, static fn( Container $c ) => new ListingVerifier( $c->get( Client::class ), $c->get( PageAnalyzer::class ), $c->get( ProviderRegistry::class ), $c->get( Submissions::class ), $c->get( Backlinks::class ), $c->get( Prospects::class ), $c->get( Settings::class ), $c->get( Notifier::class ), $c->get( AuditLog::class ), $c->get( Logger::class ) ) );
        $c->singleton( SubmissionEngine::class, static fn( Container $c ) => new SubmissionEngine( $c->get( Submissions::class ), $c->get( Prospects::class ), $c->get( Opportunities::class ), $c->get( PackageBuilder::class ), $c->get( Policy::class ), $c->get( AdapterRegistry::class ), $c->get( SubmissionPageFinder::class ), $c->get( Limits::class ), $c->get( AuditLog::class ), $c->get( Notifier::class ), $c->get( Logger::class ) ) );

        // --- Outreach ------------------------------------------------------
        $c->singleton( Templates::class, static fn() => new Templates() );
        $c->singleton( Personalizer::class, static fn( Container $c ) => new Personalizer( $c->get( LinkableAssets::class ), $c->get( FactsRegistry::class ), $c->get( Settings::class ) ) );
        $c->singleton( Compliance::class, static fn( Container $c ) => new Compliance( $c->get( Settings::class ), $c->get( Suppression::class ), $c->get( OutreachMessages::class ), $c->get( ClaimsFilter::class ) ) );
        $c->singleton( Mailer::class, static fn( Container $c ) => new Mailer( $c->get( Settings::class ), $c->get( Logger::class ) ) );
        $c->singleton( Unsubscribe::class, static fn( Container $c ) => new Unsubscribe( $c->get( OutreachMessages::class ), $c->get( Contacts::class ), $c->get( Suppression::class ), $c->get( AuditLog::class ) ) );
        $c->singleton( InboundWebhook::class, static fn( Container $c ) => new InboundWebhook( $c->get( Secrets::class ), $c->get( OutreachMessages::class ), $c->get( Contacts::class ), $c->get( Notifier::class ), $c->get( AuditLog::class ), $c->get( Logger::class ) ) );
        $c->singleton( FollowUps::class, static fn( Container $c ) => new FollowUps( $c->get( OutreachMessages::class ), $c->get( Campaigns::class ), $c->get( Settings::class ), $c->get( Templates::class ), $c->get( Personalizer::class ) ) );
        $c->singleton( OutreachEngine::class, static fn( Container $c ) => new OutreachEngine( $c->get( Campaigns::class ), $c->get( OutreachMessages::class ), $c->get( Contacts::class ), $c->get( Prospects::class ), $c->get( Opportunities::class ), $c->get( Templates::class ), $c->get( Personalizer::class ), $c->get( Compliance::class ), $c->get( Mailer::class ), $c->get( ContentGenerator::class ), $c->get( Limits::class ), $c->get( AuditLog::class ), $c->get( Notifier::class ), $c->get( Settings::class ), $c->get( Logger::class ) ) );

        // --- Monitoring ----------------------------------------------------
        $c->singleton( Notifier::class, static fn( Container $c ) => new Notifier( $c->get( Notifications::class ), $c->get( Settings::class ), $c->get( Logger::class ) ) );
        $c->singleton( BacklinkMonitor::class, static fn( Container $c ) => new BacklinkMonitor( $c->get( Backlinks::class ), $c->get( Client::class ), $c->get( PageAnalyzer::class ), $c->get( Settings::class ), $c->get( Notifier::class ), $c->get( AuditLog::class ), $c->get( Logger::class ) ) );
        $c->singleton( BacklinkImporter::class, static fn( Container $c ) => new BacklinkImporter( $c->get( Backlinks::class ), $c->get( Prospects::class ), $c->get( ProviderRegistry::class ), $c->get( Analytics4::class ), $c->get( Settings::class ), $c->get( Notifier::class ), $c->get( AuditLog::class ), $c->get( Logger::class ) ) );

        // --- Automation ----------------------------------------------------
        $c->singleton( Audit::class, static fn( Container $c ) => new Audit( $c->get( AuditLog::class ) ) );
        $c->singleton( JobRunner::class, static fn( Container $c ) => new JobRunner( $c, $c->get( Jobs::class ), $c->get( Locks::class ), $c->get( Settings::class ), $c->get( Logger::class ), $c->get( AuditLog::class ) ) );
        $c->singleton( Pipeline::class, static fn( Container $c ) => new Pipeline( $c->get( Jobs::class ), $c->get( JobRunner::class ), $c->get( Settings::class ), $c->get( Limits::class ), $c->get( Logger::class ) ) );
        $c->singleton( Scheduler::class, static fn( Container $c ) => new Scheduler( $c->get( Pipeline::class ), $c->get( Settings::class ), $c->get( Logger::class ) ) );

        // --- Admin ---------------------------------------------------------
        $c->singleton( Capabilities::class, static fn() => new Capabilities() );
        $c->singleton( Menu::class, static fn( Container $c ) => new Menu( $c->get( Settings::class ), $c->get( Notifications::class ) ) );
        $c->singleton( Assets::class, static fn( Container $c ) => new Assets( $c->get( Settings::class ) ) );
        $c->singleton( Notices::class, static fn( Container $c ) => new Notices( $c->get( Settings::class ), $c->get( ProviderRegistry::class ) ) );
        $c->singleton( RestApi::class, static fn( Container $c ) => new RestApi( $c ) );
    }
}
