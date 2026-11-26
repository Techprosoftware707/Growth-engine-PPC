<?php
/**
 * Module `core` (DESIGN.md §3.2 Bootstrap, Database, Logging, Settings, Health and CLI rows): the support services, the
 * schema and migrator, the logging repositories, the environment check, the health monitor and its checks, the
 * Site Health tests, the WP-CLI registry; the business profile and advanced settings sections.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Core;

use LinkGrowth\Autopilot\Cli\CommandRegistry;
use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Database\Backup;
use LinkGrowth\Autopilot\Database\Migrator;
use LinkGrowth\Autopilot\Database\Schema;
use LinkGrowth\Autopilot\Health\Checks\CoexistenceCheck;
use LinkGrowth\Autopilot\Health\Checks\OpcacheCheck;
use LinkGrowth\Autopilot\Health\HealthMonitor;
use LinkGrowth\Autopilot\Health\SiteHealthTests;
use LinkGrowth\Autopilot\Plugin;
use LinkGrowth\Autopilot\Repositories\AuditLog;
use LinkGrowth\Autopilot\Repositories\Logs;
use LinkGrowth\Autopilot\Repositories\Notifications;
use LinkGrowth\Autopilot\Support\Crypto;
use LinkGrowth\Autopilot\Support\Http\Client;
use LinkGrowth\Autopilot\Support\Http\RobotsTxt;
use LinkGrowth\Autopilot\Support\Limits;
use LinkGrowth\Autopilot\Support\Locks;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Opcache;
use LinkGrowth\Autopilot\Support\Secrets;
use LinkGrowth\Autopilot\Support\ServerResources;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\SettingsSchema;

final class CoreModule extends AbstractModule {

    public const ID = 'core';

    public function id(): string {
        return self::ID;
    }

    public function services( Container $c ): void {
        // --- Support -------------------------------------------------------
        $c->singleton( Crypto::class, static fn() => new Crypto() );
        $c->singleton( Settings::class, static fn() => new Settings() );
        // The validation rule of every settings key, from the modules' settingsRules() (POST /settings validates with it).
        $c->singleton( SettingsSchema::class, static fn() => new SettingsSchema( Plugin::instance()->modules() ) );
        $c->singleton( Secrets::class, static fn( Container $c ) => new Secrets( $c->get( Crypto::class ) ) );
        $c->singleton( Logger::class, static fn( Container $c ) => new Logger( $c->get( Logs::class ), $c->get( Settings::class ) ) );
        $c->singleton( RobotsTxt::class, static fn( Container $c ) => new RobotsTxt( $c->get( Logger::class ) ) );
        $c->singleton( Client::class, static fn( Container $c ) => new Client( $c->get( Settings::class ), $c->get( Logger::class ), $c->get( RobotsTxt::class ) ) );
        $c->singleton( Locks::class, static fn() => new Locks() );
        $c->singleton( Limits::class, static fn( Container $c ) => new Limits( $c->get( Settings::class ) ) );
        // Server pressure readers (Automation\LoadGuard, Health\SiteHealthTests): read only while a pause or the OPcache
        // advice is decided.
        $c->singleton( Opcache::class, static fn() => new Opcache() );
        $c->singleton( ServerResources::class, static fn() => new ServerResources() );

        // --- Database ------------------------------------------------------
        $c->singleton( Schema::class, static fn() => new Schema() );
        $c->singleton( Backup::class, static fn( Container $c ) => new Backup( $c->get( Schema::class ), $c->get( Logger::class ) ) );
        $c->singleton( Migrator::class, static fn( Container $c ) => new Migrator( $c->get( Schema::class ), $c->get( Backup::class ), $c->get( Logger::class ), $c->get( AuditLog::class ) ) );

        // --- Logging repositories -------------------------------------------
        foreach ( [ Logs::class, AuditLog::class, Notifications::class ] as $repo ) {
            $c->singleton( $repo, static fn() => new $repo() );
        }

        // --- Environment, health and WP-CLI ---------------------------------
        $c->singleton( Environment::class, static fn() => new Environment() );
        $c->singleton( SiteHealthTests::class, static fn( Container $c ) => new SiteHealthTests( $c->get( Opcache::class ), $c->get( ServerResources::class ) ) );
        $c->singleton( OpcacheCheck::class, static fn( Container $c ) => new OpcacheCheck( $c->get( SiteHealthTests::class ) ) );
        $c->singleton( CoexistenceCheck::class, static fn( Container $c ) => new CoexistenceCheck( $c->get( Environment::class ) ) );
        $c->singleton( HealthMonitor::class, static fn( Container $c ) => new HealthMonitor( $c, Plugin::instance()->modules() ) );
        $c->singleton( CommandRegistry::class, static fn() => new CommandRegistry( Plugin::instance()->modules() ) );
    }

    public function boot( Plugin $plugin ): void {
        if ( $plugin->isMaintenanceRequest() ) {
            // Database migrations (with backup) when the stored version differs: never inside a shopper's page view.
            add_action( 'init', [ $plugin, 'maybeMigrate' ], 1 );
        }
        // Site Health warns when PHP's code cache is full and when another plugin does the same work (applied on the
        // Site Health screen and its checks only).
        add_filter( 'site_status_tests', [ $plugin, 'siteStatusTests' ] );
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            // `wp linkgrowth …` (WP-CLI only): the registry is built when WP-CLI asks for commands.
            add_action( 'cli_init', $plugin->lazy( CommandRegistry::class, 'register' ) );
        }
    }

    public function tables(): array {
        $t       = static fn( string $n ): string => Schema::table( $n );
        $charset = Schema::charsetCollate();

        return [
            'notifications' => "CREATE TABLE {$t('notifications')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(40) NOT NULL DEFAULT 'info',
  severity varchar(10) NOT NULL DEFAULT 'info',
  title varchar(255) NOT NULL DEFAULT '',
  message text,
  data longtext,
  is_read tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY is_read (is_read),
  KEY created_at (created_at)
) $charset;",

            'audit_log' => "CREATE TABLE {$t('audit_log')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  actor varchar(60) NOT NULL DEFAULT 'system',
  action varchar(60) NOT NULL,
  object_type varchar(40) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  details longtext,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY action (action),
  KEY object (object_type,object_id),
  KEY created_at (created_at)
) $charset;",

            'logs' => "CREATE TABLE {$t('logs')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  level varchar(10) NOT NULL DEFAULT 'info',
  channel varchar(40) NOT NULL DEFAULT 'general',
  message text,
  context longtext,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY level (level),
  KEY channel (channel),
  KEY created_at (created_at)
) $charset;",
        ];
    }

    public function settingsDefaults(): array {
        $home = Settings::defaultHome();
        return [
            // The business profile ships empty: every site enters its own (no brand, address or market is assumed).
            'site'     => [
                'brand_name'   => '',
                'legal_name'   => '',
                'domain'       => Settings::domainOf( $home ),
                'home_url'     => untrailingslashit( $home ),
                'tagline'      => '',
                'email'        => '',
                'phone'        => '',
                'address'      => '',
                'city'         => '',
                'region'       => '',
                'postal_code'  => '',
                'country'      => '',
                'founded_year' => '',
                'logo_url'     => '',
                'social'       => [],
                'categories'   => [],
            ],
            'advanced' => [
                'delete_on_uninstall'  => false,
                'log_level'            => 'info',
                'log_retention_days'   => 30,
                'audit_retention_days' => 365,
                'user_agent'           => 'LinkGrowthAutopilotBot/1.0 (+' . untrailingslashit( $home ) . '/; link research)',
                'request_timeout'      => 15,
                'crawl_delay_seconds'  => 2,
                'max_response_kb'      => 1536,
            ],
        ];
    }

    /**
     * The rules of the site and advanced sections (Support\SettingsSchema), with the engine's ranges and
     * normalisations.
     */
    public function settingsRules(): array {
        $text     = SettingsSchema::text();
        $textarea = SettingsSchema::textarea();
        return [
            'site.brand_name'              => $text,
            'site.legal_name'              => $text,
            'site.domain'                  => SettingsSchema::domain(),
            'site.home_url'                => SettingsSchema::url(),
            'site.tagline'                 => $text,
            'site.email'                   => SettingsSchema::email(),
            'site.phone'                   => $text,
            'site.address'                 => $textarea,
            'site.city'                    => $text,
            'site.region'                  => $text,
            'site.postal_code'             => $text,
            // Two letters, upper case (other characters are dropped); anything else is refused, '' included.
            'site.country'                 => SettingsSchema::string( [ 'strip' => '/[^a-z]/i', 'case' => 'upper', 'empty' => SettingsSchema::REFUSE, 'length' => 2 ] ),
            // Digits only, at most four ('' clears it).
            'site.founded_year'            => SettingsSchema::string( [ 'strip' => '/\D/', 'max' => 4 ] ),
            'site.logo_url'                => SettingsSchema::url(),
            'site.social'                  => SettingsSchema::list( 'url' ),
            'site.categories'              => SettingsSchema::list(),
            'advanced.delete_on_uninstall' => SettingsSchema::bool(),
            'advanced.log_level'           => SettingsSchema::enum( [ 'debug', 'info', 'warning', 'error' ] ),
            'advanced.log_retention_days'  => SettingsSchema::int( 1, 365 ),
            'advanced.audit_retention_days' => SettingsSchema::int( 7, 3650 ),
            // '' is refused (the computed agent is the default; a form cannot blank it).
            'advanced.user_agent'          => SettingsSchema::string( [ 'sanitize' => 'text', 'trim' => true, 'empty' => SettingsSchema::REFUSE, 'max' => 255 ] ),
            'advanced.request_timeout'     => SettingsSchema::int( 3, 60 ),
            'advanced.crawl_delay_seconds' => SettingsSchema::int( 0, 60 ),
            'advanced.max_response_kb'     => SettingsSchema::int( 64, 8192 ),
        ];
    }

    public function settingsTabs(): array {
        return [
            'business' => [ 'label' => __( 'Business profile', 'linkgrowth-autopilot' ), 'module' => 'settings/business.js', 'order' => 10 ],
            'advanced' => [ 'label' => __( 'Advanced', 'linkgrowth-autopilot' ), 'module' => 'settings/advanced.js', 'order' => 80 ],
        ];
    }

    public function healthChecks(): array {
        return [ OpcacheCheck::class, CoexistenceCheck::class ];
    }
}
