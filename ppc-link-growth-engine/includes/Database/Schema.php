<?php
/**
 * Database schema for all plugin tables (dbDelta compatible).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Database;

final class Schema {

    public const TABLES = [
        'prospects',
        'opportunities',
        'submissions',
        'contacts',
        'campaigns',
        'outreach_messages',
        'backlinks',
        'suppression',
        'notifications',
        'audit_log',
        'logs',
        'jobs',
        'domain_metrics',
        'search_log',
    ];

    public static function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . 'ppclg_' . $name;
    }

    /** @return string[] */
    public function tableNames(): array {
        return array_map( [ self::class, 'table' ], self::TABLES );
    }

    /** @return string[] existing plugin tables */
    public function existingTables(): array {
        global $wpdb;
        $existing = [];
        foreach ( $this->tableNames() as $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
            if ( $found === $table ) {
                $existing[] = $table;
            }
        }
        return $existing;
    }

    /**
     * Full CREATE TABLE statements, formatted for dbDelta().
     *
     * @return string[]
     */
    public function statements(): array {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $t       = static fn( string $n ): string => self::table( $n );

        return [
            "CREATE TABLE {$t('prospects')} (
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

            "CREATE TABLE {$t('opportunities')} (
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

            "CREATE TABLE {$t('submissions')} (
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

            "CREATE TABLE {$t('contacts')} (
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

            "CREATE TABLE {$t('campaigns')} (
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

            "CREATE TABLE {$t('outreach_messages')} (
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

            "CREATE TABLE {$t('backlinks')} (
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

            "CREATE TABLE {$t('suppression')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(10) NOT NULL DEFAULT 'domain',
  value varchar(190) NOT NULL,
  reason varchar(190) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY type_value (type,value)
) $charset;",

            "CREATE TABLE {$t('notifications')} (
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

            "CREATE TABLE {$t('audit_log')} (
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

            "CREATE TABLE {$t('logs')} (
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

            "CREATE TABLE {$t('jobs')} (
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

            "CREATE TABLE {$t('domain_metrics')} (
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

            "CREATE TABLE {$t('search_log')} (
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

    /**
     * Create / upgrade all tables.
     *
     * @return array<string,string> dbDelta messages
     */
    public function install(): array {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $messages = [];
        foreach ( $this->statements() as $sql ) {
            $result = dbDelta( $sql );
            foreach ( (array) $result as $k => $v ) {
                $messages[ (string) $k ] = (string) $v;
            }
        }
        return $messages;
    }
}
