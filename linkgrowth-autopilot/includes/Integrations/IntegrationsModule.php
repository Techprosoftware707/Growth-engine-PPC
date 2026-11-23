<?php
/**
 * Module `integrations` (DESIGN.md §3.2 Provider integrations row): the provider registry, the data and search
 * providers, Google (Search Console, GA4, Site Kit), RDAP, the metrics aggregator and the optional AI writer; the
 * domain metrics cache table, the provider settings and the metrics refresh job.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Integrations;

use LinkGrowth\Autopilot\Automation\Frequency;
use LinkGrowth\Autopilot\Automation\Jobs\MetricsRefreshJob;
use LinkGrowth\Autopilot\Automation\TaskDefinition;
use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Core\AbstractModule;
use LinkGrowth\Autopilot\Database\Schema;
use LinkGrowth\Autopilot\Integrations\Google\Analytics4;
use LinkGrowth\Autopilot\Integrations\Google\SearchConsole;
use LinkGrowth\Autopilot\Integrations\Google\ServiceAccount;
use LinkGrowth\Autopilot\Integrations\Google\SiteKitBridge;
use LinkGrowth\Autopilot\Repositories\DomainMetrics;
use LinkGrowth\Autopilot\Support\Http\Client;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Secrets;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\SettingsSchema;

final class IntegrationsModule extends AbstractModule {

    public const ID = 'integrations';

    public function id(): string {
        return self::ID;
    }

    public function services( Container $c ): void {
        $c->singleton( DomainMetrics::class, static fn() => new DomainMetrics() );
        $c->singleton( Rdap::class, static fn( Container $c ) => new Rdap( $c->get( Client::class ) ) );
        $c->singleton( ProviderRegistry::class, static fn( Container $c ) => new ProviderRegistry( $c ) );
        $c->singleton( ServiceAccount::class, static fn( Container $c ) => new ServiceAccount( $c->get( Secrets::class ), $c->get( Client::class ), $c->get( Logger::class ) ) );
        $c->singleton( SearchConsole::class, static fn( Container $c ) => new SearchConsole( $c->get( ServiceAccount::class ), $c->get( Client::class ), $c->get( Settings::class ), $c->get( Logger::class ) ) );
        $c->singleton( Analytics4::class, static fn( Container $c ) => new Analytics4( $c->get( ServiceAccount::class ), $c->get( Client::class ), $c->get( Settings::class ), $c->get( Logger::class ) ) );
        $c->singleton( SiteKitBridge::class, static fn( Container $c ) => new SiteKitBridge( $c->get( Logger::class ) ) );
        $c->singleton( MetricsAggregator::class, static fn( Container $c ) => new MetricsAggregator( $c->get( ProviderRegistry::class ), $c->get( DomainMetrics::class ), $c->get( Settings::class ), $c->get( Logger::class ), $c->get( Client::class ) ) );
        $c->singleton( AiWriter::class, static fn( Container $c ) => new AiWriter( $c->get( Secrets::class ), $c->get( Settings::class ), $c->get( Client::class ), $c->get( Logger::class ) ) );
    }

    public function tables(): array {
        $t       = static fn( string $n ): string => Schema::table( $n );
        $charset = Schema::charsetCollate();

        return [
            'domain_metrics' => "CREATE TABLE {$t('domain_metrics')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  domain varchar(190) NOT NULL,
  provider varchar(30) NOT NULL,
  metrics longtext,
  fetched_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY domain_provider (domain,provider),
  KEY expires_at (expires_at)
) $charset;",
        ];
    }

    public function settingsDefaults(): array {
        $domain = Settings::domainOf( Settings::defaultHome() );
        return [
            'providers' => [
                'seranking' => [ 'enabled' => true, 'region' => 'ca', 'cache_days' => 30 ],
                'ahrefs'    => [ 'enabled' => false, 'cache_days' => 30 ],
                'semrush'   => [ 'enabled' => false, 'database' => 'ca', 'cache_days' => 30 ],
                'moz'       => [ 'enabled' => false, 'cache_days' => 30 ],
                'google'    => [ 'enabled' => true, 'gsc_property' => 'sc-domain:' . $domain, 'ga4_property_id' => '', 'use_sitekit' => true ],
                'search'    => [ 'primary' => 'auto', 'google_cse_daily_limit' => 90, 'brave_daily_limit' => 60 ],
                'ai'        => [ 'enabled' => false, 'model' => '', 'max_tokens' => 1200 ],
                'rdap'      => [ 'enabled' => true ],
            ],
        ];
    }

    /**
     * The provider rules, plus the three switches the providers read with their own default that the defaults do not
     * list (SE Ranking's overview and summary requests, the Ahrefs metrics request).
     */
    public function settingsRules(): array {
        $cacheDays = SettingsSchema::int( 1, 365 );
        // A provider's market code: letters and digits only, lower case, at most 10; '' is refused.
        $market = SettingsSchema::string( [ 'strip' => '/[^a-z0-9]/i', 'case' => 'lower', 'empty' => SettingsSchema::REFUSE, 'max' => 10 ] );
        return [
            'providers.seranking.enabled'                => SettingsSchema::bool(),
            'providers.seranking.region'                 => $market,
            'providers.seranking.cache_days'             => $cacheDays,
            'providers.seranking.fetch_overview'         => SettingsSchema::optional( SettingsSchema::bool(), true ),
            'providers.seranking.fetch_summary'          => SettingsSchema::optional( SettingsSchema::bool(), false ),
            'providers.ahrefs.enabled'                   => SettingsSchema::bool(),
            'providers.ahrefs.cache_days'                => $cacheDays,
            'providers.ahrefs.fetch_metrics'             => SettingsSchema::optional( SettingsSchema::bool(), true ),
            'providers.semrush.enabled'                  => SettingsSchema::bool(),
            'providers.semrush.database'                 => $market,
            'providers.semrush.cache_days'               => $cacheDays,
            'providers.moz.enabled'                      => SettingsSchema::bool(),
            'providers.moz.cache_days'                   => $cacheDays,
            'providers.google.enabled'                   => SettingsSchema::bool(),
            'providers.google.gsc_property'              => SettingsSchema::text(),
            'providers.google.ga4_property_id'           => SettingsSchema::string( [ 'strip' => '/\D/', 'max' => 20 ] ),
            'providers.google.use_sitekit'               => SettingsSchema::bool(),
            'providers.search.primary'                   => SettingsSchema::enum( [ 'auto', 'google_cse', 'brave' ] ),
            'providers.search.google_cse_daily_limit'    => SettingsSchema::int( 0, 10000 ),
            'providers.search.brave_daily_limit'         => SettingsSchema::int( 0, 100000 ),
            'providers.ai.enabled'                       => SettingsSchema::bool(),
            // The owner's model id ('' = none: the AI writer stays off, Integrations\AiWriter::isEnabled()).
            'providers.ai.model'                         => SettingsSchema::string( [ 'trim' => true, 'case' => 'lower', 'pattern' => '/^[a-z0-9\-\.]{3,80}$/' ] ),
            'providers.ai.max_tokens'                    => SettingsSchema::int( 200, 8000 ),
            'providers.rdap.enabled'                     => SettingsSchema::bool(),
        ];
    }

    public function jobs(): array {
        return [ 'metrics_refresh' => MetricsRefreshJob::class ];
    }

    /**
     * The integrations task, with the engine's cadences (DESIGN.md §5.1; Automation\TaskDefinition explains the WP2 values).
     *
     * @return list<TaskDefinition>
     */
    public function tasks(): array {
        return [
            new TaskDefinition( id: 'metrics_refresh', jobType: 'metrics_refresh', label: __( 'Refresh metrics', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::WEEKLY, module: '', dueCheck: null, priority: MetricsRefreshJob::priority(), outbound: true, order: 160 ),
        ];
    }

    public function settingsTabs(): array {
        return [
            'integrations' => [ 'label' => __( 'Integrations', 'linkgrowth-autopilot' ), 'module' => 'pages/settings.js', 'order' => 40 ],
        ];
    }
}
