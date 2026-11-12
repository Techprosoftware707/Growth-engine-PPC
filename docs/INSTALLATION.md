# Installation

## Requirements

- WordPress 6.5 or newer (built and tested against 7.1), PHP 8.1 or newer (the live site runs PHP 8.5), MySQL/MariaDB.
- The site's `wp-config.php` must contain real, unique `AUTH_KEY` and `SECURE_AUTH_KEY` salts: the plugin derives its credential-encryption key from them. The dashboard warns if they look weak.
- Outbound HTTPS from the web server (the plugin crawls prospects and calls provider APIs).
- A cron backend: the plugin uses **Action Scheduler** automatically when WooCommerce (or any plugin bundling it) is active, otherwise WP-Cron. On the live site WooCommerce is active, so Action Scheduler runs the `ppclg_tick` action every five minutes.

Nothing else is installed. The plugin has no Composer runtime dependencies and ships no third-party libraries.

## Install from the zip

```bash
bash bin/build-zip.sh
```

Then in WordPress: **Plugins → Add New → Upload Plugin**, choose `dist/ppc-link-growth-engine-1.1.0.zip`, install and activate. Alternatively copy the `ppc-link-growth-engine/` directory into `wp-content/plugins/` and activate it from the Plugins screen.

## What activation does

1. Creates the plugin tables (`wp_ppclg_*`: prospects, opportunities, submissions, contacts, campaigns, outreach_messages, backlinks, suppression, notifications, audit_log, logs, jobs, domain_metrics, search_log). When the plugin is later upgraded, the existing tables are backed up to a gzipped SQL file in a randomly named folder under `wp-content/uploads/` **before** the schema migration runs.
2. Stores default settings (business profile seeded from purepeptidescanada.com, conservative limits, automation **on** in the default *auto* submission mode, *Outreach autosend* **off**).
3. Grants the `ppclg_manage` capability to the Administrator role.
4. Schedules the `ppclg_tick` background action.
5. Redirects you to **Link Growth → Overview**.

Deactivation removes the schedule and locks but keeps all data. Uninstalling removes data only when **Settings → Advanced → Delete all data on uninstall** was enabled.

## Coexistence with the live site

The plugin registers its own admin menu, REST namespace (`ppclg/v1`), tables and background action. It does not touch WooCommerce, checkout, products, payments, Rank Math, Site Kit or the theme. Public-facing surface is limited to two REST endpoints: the outreach unsubscribe page and the optional inbound-email webhook.

## Credentials in wp-config.php (recommended for production)

Any credential can be defined as a constant instead of being stored (encrypted) in the database. Constants win over stored values and are shown as "locked" in Settings:

```php
define( 'PPCLG_SECRET_SERANKING_API_KEY', '…' );
define( 'PPCLG_SECRET_GOOGLE_SERVICE_ACCOUNT_JSON', '{"type":"service_account",…}' );
define( 'PPCLG_SECRET_GOOGLE_CSE_API_KEY', '…' );
define( 'PPCLG_SECRET_GOOGLE_CSE_CX', '…' );
define( 'PPCLG_SECRET_BRAVE_API_KEY', '…' );
define( 'PPCLG_SECRET_SERPER_API_KEY', '…' );
define( 'PPCLG_SECRET_AHREFS_API_KEY', '…' );
define( 'PPCLG_SECRET_SEMRUSH_API_KEY', '…' );
define( 'PPCLG_SECRET_MOZ_ACCESS_ID', '…' );
define( 'PPCLG_SECRET_MOZ_SECRET_KEY', '…' );
define( 'PPCLG_SECRET_ANTHROPIC_API_KEY', '…' );
define( 'PPCLG_SECRET_INBOUND_WEBHOOK_SECRET', '…' );
```

## Upgrading

Upload the new zip (WordPress replaces the plugin in place) or replace the directory. On the next admin page load the migrator backs up the plugin tables and applies schema changes. Backups are listed under **Settings → Advanced**.
