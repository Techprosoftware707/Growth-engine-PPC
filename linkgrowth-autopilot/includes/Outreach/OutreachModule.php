<?php
/**
 * Module `outreach` (DESIGN.md §3.2 Acquisition workflows row, outreach): templates, personalisation, the send-window
 * and suppression rules, the mailer, unsubscribe and inbound replies, follow-ups and campaign drafting; the contacts,
 * campaigns, outreach messages and suppression tables, the outreach settings and jobs, the Outreach page and the
 * Outreach and public (unsubscribe, inbound) controllers.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Outreach;

use LinkGrowth\Autopilot\Admin\Capabilities;
use LinkGrowth\Autopilot\Admin\Rest\OutreachController;
use LinkGrowth\Autopilot\Admin\Rest\PublicController;
use LinkGrowth\Autopilot\Automation\Autopilot;
use LinkGrowth\Autopilot\Automation\Frequency;
use LinkGrowth\Autopilot\Automation\Jobs\AutoOutreachJob;
use LinkGrowth\Autopilot\Automation\Jobs\FollowUpJob;
use LinkGrowth\Autopilot\Automation\Jobs\OutreachSendJob;
use LinkGrowth\Autopilot\Automation\TaskDefinition;
use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Content\ClaimsFilter;
use LinkGrowth\Autopilot\Content\ContentGenerator;
use LinkGrowth\Autopilot\Content\FactsRegistry;
use LinkGrowth\Autopilot\Content\LinkableAssets;
use LinkGrowth\Autopilot\Core\AbstractModule;
use LinkGrowth\Autopilot\Database\Schema;
use LinkGrowth\Autopilot\Monitoring\Notifier;
use LinkGrowth\Autopilot\Repositories\AuditLog;
use LinkGrowth\Autopilot\Repositories\Campaigns;
use LinkGrowth\Autopilot\Repositories\Contacts;
use LinkGrowth\Autopilot\Repositories\Opportunities;
use LinkGrowth\Autopilot\Repositories\OutreachMessages;
use LinkGrowth\Autopilot\Repositories\Prospects;
use LinkGrowth\Autopilot\Repositories\Suppression;
use LinkGrowth\Autopilot\Support\Limits;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Secrets;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\SettingsSchema;

final class OutreachModule extends AbstractModule {

    public const ID = 'outreach';

    public function id(): string {
        return self::ID;
    }

    public function services( Container $c ): void {
        // The due-checks of this module's tasks (Automation\TaskRegistry resolves them by name).
        $c->singleton( DueChecks::class, static fn( Container $c ) => new DueChecks( $c ) );
        foreach ( [ Contacts::class, Campaigns::class, OutreachMessages::class, Suppression::class ] as $repo ) {
            $c->singleton( $repo, static fn() => new $repo() );
        }
        $c->singleton( Templates::class, static fn() => new Templates() );
        $c->singleton( Personalizer::class, static fn( Container $c ) => new Personalizer( $c->get( LinkableAssets::class ), $c->get( FactsRegistry::class ), $c->get( Settings::class ) ) );
        $c->singleton( Compliance::class, static fn( Container $c ) => new Compliance( $c->get( Settings::class ), $c->get( Suppression::class ), $c->get( OutreachMessages::class ), $c->get( ClaimsFilter::class ) ) );
        $c->singleton( Mailer::class, static fn( Container $c ) => new Mailer( $c->get( Settings::class ), $c->get( Logger::class ) ) );
        $c->singleton( Unsubscribe::class, static fn( Container $c ) => new Unsubscribe( $c->get( OutreachMessages::class ), $c->get( Contacts::class ), $c->get( Suppression::class ), $c->get( AuditLog::class ) ) );
        $c->singleton( InboundWebhook::class, static fn( Container $c ) => new InboundWebhook( $c->get( Secrets::class ), $c->get( OutreachMessages::class ), $c->get( Contacts::class ), $c->get( Notifier::class ), $c->get( AuditLog::class ), $c->get( Logger::class ) ) );
        $c->singleton( FollowUps::class, static fn( Container $c ) => new FollowUps( $c->get( OutreachMessages::class ), $c->get( Campaigns::class ), $c->get( Settings::class ), $c->get( Templates::class ), $c->get( Personalizer::class ), $c->get( Autopilot::class ) ) );
        $c->singleton( OutreachEngine::class, static fn( Container $c ) => new OutreachEngine( $c->get( Campaigns::class ), $c->get( OutreachMessages::class ), $c->get( Contacts::class ), $c->get( Prospects::class ), $c->get( Opportunities::class ), $c->get( Templates::class ), $c->get( Personalizer::class ), $c->get( Compliance::class ), $c->get( Mailer::class ), $c->get( ContentGenerator::class ), $c->get( Limits::class ), $c->get( AuditLog::class ), $c->get( Notifier::class ), $c->get( Settings::class ), $c->get( Logger::class ), autopilot: $c->get( Autopilot::class ) ) );
        $c->singleton( CampaignFiller::class, static fn( Container $c ) => new CampaignFiller( $c ) );
    }

    public function tables(): array {
        $t       = static fn( string $n ): string => Schema::table( $n );
        $charset = Schema::charsetCollate();

        return [
            'contacts' => "CREATE TABLE {$t('contacts')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  prospect_id bigint(20) unsigned NOT NULL,
  name varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL,
  role varchar(100) NOT NULL DEFAULT '',
  source_url varchar(700) NOT NULL DEFAULT '',
  confidence tinyint(3) unsigned NOT NULL DEFAULT 50,
  consent_basis varchar(60) NOT NULL DEFAULT 'conspicuous_publication',
  status varchar(20) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY prospect_email (prospect_id,email),
  KEY email (email),
  KEY status (status)
) $charset;",

            'campaigns' => "CREATE TABLE {$t('campaigns')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  approach varchar(40) NOT NULL DEFAULT 'resource_page',
  status varchar(30) NOT NULL DEFAULT 'draft',
  daily_limit int(11) unsigned NOT NULL DEFAULT 10,
  settings longtext,
  stats longtext,
  created_by bigint(20) unsigned DEFAULT NULL,
  approved_by bigint(20) unsigned DEFAULT NULL,
  approved_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $charset;",

            'outreach_messages' => "CREATE TABLE {$t('outreach_messages')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
  prospect_id bigint(20) unsigned NOT NULL,
  contact_id bigint(20) unsigned NOT NULL,
  opportunity_id bigint(20) unsigned DEFAULT NULL,
  approach varchar(40) NOT NULL DEFAULT '',
  sequence tinyint(3) unsigned NOT NULL DEFAULT 1,
  subject varchar(255) NOT NULL DEFAULT '',
  body longtext,
  status varchar(30) NOT NULL DEFAULT 'draft',
  variant varchar(20) NOT NULL DEFAULT '',
  scheduled_at datetime DEFAULT NULL,
  sent_at datetime DEFAULT NULL,
  replied_at datetime DEFAULT NULL,
  reply_summary text,
  message_id varchar(190) NOT NULL DEFAULT '',
  unsubscribe_token char(32) NOT NULL DEFAULT '',
  last_error text,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY contact_approach_seq (contact_id,approach,sequence),
  KEY campaign_id (campaign_id),
  KEY prospect_id (prospect_id),
  KEY status (status),
  KEY unsubscribe_token (unsubscribe_token),
  KEY message_id (message_id)
) $charset;",

            'suppression' => "CREATE TABLE {$t('suppression')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(20) NOT NULL DEFAULT 'domain',
  value varchar(190) NOT NULL,
  reason varchar(190) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY type_value (type,value)
) $charset;",
        ];
    }

    public function settingsDefaults(): array {
        return [
            'outreach' => [
                'sender_name'               => '',
                'sender_email'              => '',
                'reply_to'                  => '',
                'postal_address'            => '',
                'signature'                 => '',
                'require_approval'          => true,
                'follow_up_days'            => 7,
                'max_follow_ups'            => 1,
                'min_days_between_contacts' => 90,
                'send_window_start'         => 9,
                'send_window_end'           => 17,
                'send_days'                 => [ 1, 2, 3, 4, 5 ],
                // IANA zone the send window is evaluated in; '' = the WordPress timezone, or the business region's
                // zone when WordPress is left on UTC (see Outreach\Compliance::sendTimezone()).
                'timezone'                  => '',
            ],
        ];
    }

    public function settingsRules(): array {
        return [
            'outreach.sender_name'               => SettingsSchema::text(),
            'outreach.sender_email'              => SettingsSchema::email(),
            'outreach.reply_to'                  => SettingsSchema::email(),
            'outreach.postal_address'            => SettingsSchema::textarea(),
            'outreach.signature'                 => SettingsSchema::textarea(),
            'outreach.require_approval'          => SettingsSchema::bool(),
            'outreach.follow_up_days'            => SettingsSchema::int( 1, 60 ),
            'outreach.max_follow_ups'            => SettingsSchema::int( 0, 3 ),
            'outreach.min_days_between_contacts' => SettingsSchema::int( 0, 365 ),
            'outreach.send_window_start'         => SettingsSchema::int( 0, 23 ),
            'outreach.send_window_end'           => SettingsSchema::int( 1, 24 ),
            // ISO weekdays; 0 is kept as the engine kept it, other numbers outside 0-7 are dropped.
            'outreach.send_days'                 => SettingsSchema::list( 'int', [ 'min' => 0, 'max' => 7 ] ),
            'outreach.timezone'                  => SettingsSchema::timezone(),
        ];
    }

    public function jobs(): array {
        return [
            'outreach_send' => OutreachSendJob::class,
            'auto_outreach' => AutoOutreachJob::class,
            'follow_ups'    => FollowUpJob::class,
        ];
    }

    /**
     * The outreach tasks, with the engine's cadences (DESIGN.md §5.1; Automation\TaskDefinition explains the WP2 values).
     *
     * @return list<TaskDefinition>
     */
    public function tasks(): array {
        return [
            new TaskDefinition( id: 'outreach_send', jobType: 'outreach_send', label: __( 'Send approved outreach', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::CONTINUOUS, module: '', dueCheck: 'outreachDue', priority: OutreachSendJob::priority(), budgetPool: 'outreach_emails', requiresSetup: true, outbound: true, cadence: 'every tick inside the send window (when approved messages are ready)', order: 100 ),
            new TaskDefinition( id: 'outreach_drafts', jobType: 'auto_outreach', label: __( 'Draft outreach', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::DAILY, module: '', dueCheck: null, priority: AutoOutreachJob::priority(), order: 110 ),
            new TaskDefinition( id: 'follow_ups', jobType: 'follow_ups', label: __( 'Generate follow-ups', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::DAILY, module: '', dueCheck: null, priority: FollowUpJob::priority(), requiresSetup: true, outbound: true, order: 120 ),
        ];
    }

    public function restControllers(): array {
        return [ OutreachController::class, PublicController::class ];
    }

    public function adminPages(): array {
        return [
            'outreach' => [ 'label' => __( 'Outreach', 'linkgrowth-autopilot' ), 'icon' => 'mail', 'module' => 'pages/outreach.js', 'cap' => Capabilities::CAP, 'order' => 40 ],
        ];
    }

    public function settingsTabs(): array {
        return [
            'outreach' => [ 'label' => __( 'Outreach & compliance', 'linkgrowth-autopilot' ), 'module' => 'pages/settings.js', 'order' => 50 ],
        ];
    }
}
