# LinkGrowth Autopilot: design document

Status: implementation design for version 1.0.0. Written before any code exists in this folder.
Source spec: the owner's master specification (sections 1-17), referred to below as "spec §N".
Code base forked from: `/home/user/Growth-engine-PPC/ppc-link-growth-engine` (PPC Link Growth Engine 1.2.4, namespace `PPC\LinkGrowth`), called "the engine" below.

This document is meant to be built from. Every section names files, classes, columns, routes, settings keys and tests. Where the design picks one option over another, the reason is stated once. Work packages for the implementation workflows are in §13.

Legend used in tables: **R** = reused from the engine unchanged apart from the rename; **G** = reused, but generalized (brand, industry and market logic removed); **X** = reused and extended; **N** = new.

**Revision 2.** This revision closes the gaps found by the completeness review. The main changes:
- Every Autopilot method is redefined, and "Grow now" is rebuilt on the task registry. Autopilot can no longer raise the submission mode, approve a first contact or skip a consent (§2.7).
- Bulk approval of first contacts is removed (§2.7, §10.2), and follow-ups are capped (§2.7).
- The catalog has an inclusion rule (§2.5). Facts have a provenance rule (§7.4). Accuracy and compliance thresholds are fixed floors (§7.5). Auto-publish requires `ai_used = 0` (§7.6).
- Owned sites are detected automatically and treated as owned until the owner rejects them; syndication is never reciprocal, and there is at most one owned placement per resource (§2.8). Suppression and contact lists can be carried between the owner's sites (§2.8). Syndicated copies on a receiver site keep their canonical when the plugin is removed (§4.4, §6.6), and the anonymous receiver endpoint no longer discloses the version (§10.2).
- Maps and local listings need a verified physical address and a business type other than `online_only`. Review platforms are claim-profile tasks only (§2.5). Pitches never claim expertise the owner has not stated (§2.7). Comparisons auto-publish only when they compare the owner's own products (§7.2).
- Secondary industry packs are supported (§2.3), and the Overview tiles are defined (§10.2). The document states plainly that no directory submission API is bundled (§6.6).
- No outbound request is made before the profile is saved (§2.1). Three switches are defined: master, Autopilot and modules (§2.1).
- The settings key list is complete (§2.2). An opportunity type enum is added (§3.4), and scoring bands are defined (§3.3).
- Task priorities and frequencies are set (§5.1). There is a runner log (§5.5). Crontab lines are per site and there is a network runner (§5.6).
- Provider extension filters are named (§6.1). Anti-bot form checks are extended (§6.7). Calculator and dataset storage is specified (§7.2).
- Monitor check priority and coverage are defined (§3.5). Analytics storage has caps and rollups (§9.1).
- Privacy exporters, erasers and retention are added (§11.9). The SSRF transport and proxy rules are stated (§11.1). The plugin now handles IndexNow and SEO-plugin conflicts (§1.4).
- New tests cover admin cost, checkout cost, PHP 8.1 compatibility, the multisite build and every restriction (§12). A source ZIP is built (§1.3).
- WP4 is split into WP4a (profile and schema) and WP4b (switches, consents, Autopilot rewrite), and every package's acceptance list names the new tests (§13).
- Appendix B lists what still needs a person.

---

## 0. Ground rules for every implementation workflow

1. **The live store is off limits.** The PPC plugin stays as it is and stays deployed. Do not install, activate, upload or test anything on the live site. Do not use the `wp-api` MCP tools in this project: they are connected to the live store. All testing happens on throwaway WordPress copies under the session scratchpad (§12.1).
2. **No system-wide installs.** PHP 8.5, WP-CLI and any other tool are extracted into the scratchpad (§12.6, §12.7). `/usr/bin/php` and `update-alternatives` are never touched.
3. **Keep CPU use light.** Another workflow measures performance on this machine. Run suites with `nice -n 10`, one integration suite at a time, and no parallel PHPUnit processes. Do not use ports 8787-8792; this project uses 8793-8797 (8797 only for the optional `compat-min` site).
4. **No model identifiers in any file**, including code defaults, docs, tests and fixtures. The AI model setting ships empty and the owner supplies it (§2.4).
5. **No PPC data in the fork.** Nothing from `ppc-link-growth-engine/data/verified-opportunities.json`, `ops/`, `dist/`, `releases/` or `docs/screenshots/` is copied. The neutrality scan (§1.7) enforces this.
6. **The fork never reads or writes any `ppclg_*`, `PPCLG_*` or `ppc_*` identifier.** Both plugins can be active on one site without sharing data, and there is no migration from PPC.
7. **Commits** are made only when the orchestrating workflow says so.
8. **Never fabricate.** A test that could not run (no browser, no PHP 8.5, no WP-CLI) is reported as "not run" with the reason, never as passed. A simulated provider result is never shown as a real acquisition.

---

## 1. Fork and rename plan

### 1.1 Identity

| Item | Engine (PPC) | LinkGrowth Autopilot |
|---|---|---|
| Plugin folder / slug | `ppc-link-growth-engine` | `linkgrowth-autopilot` |
| Main file | `ppc-link-growth-engine.php` | `linkgrowth-autopilot.php` |
| Basename | `ppc-link-growth-engine/ppc-link-growth-engine.php` | `linkgrowth-autopilot/linkgrowth-autopilot.php` |
| Plugin Name | PPC Link Growth Engine | LinkGrowth Autopilot |
| Header extras | Plugin/Author URI to the store | `Author: LinkGrowth Autopilot contributors`, no URI; `Requires at least: 6.5`, `Requires PHP: 8.1`, `Tested up to: 7.1`, `WC requires at least: 8.0`, `WC tested up to: 11.3`, `Text Domain: linkgrowth-autopilot`, `Domain Path: /languages`, `License: GPL-2.0-or-later`. The minimums are declared, not run. PHP 8.1 is backed by the static compatibility scan (§11.8). WP 6.5 and WC 8.0 are backed by the API scan (§11.8) and listed as "declared, not run" in TEST-RESULTS.md unless the optional `compat-min` run succeeds (§12.9) |
| Version | 1.2.4 | 1.0.0 |
| DB version | 1.0.1 | 1.0.0 (0.9.0 is the renamed fork baseline, used only by the upgrade test, §4.5) |
| Product name constant | (string literals) | `LinkGrowth\Autopilot\Plugin::NAME = 'LinkGrowth Autopilot'`; every self-reference uses it via `__()` |
| PHP namespace | `PPC\LinkGrowth\` | `LinkGrowth\Autopilot\` |
| Test namespace | `PPC\LinkGrowth\Tests\` | `LinkGrowth\Autopilot\Tests\` |
| Constants | `PPCLG_VERSION`, `_DB_VERSION`, `_FILE`, `_DIR`, `_URL`, `_BASENAME`, `_MIN_PHP`, `_MIN_WP` | `LGA_VERSION`, `LGA_DB_VERSION`, `LGA_FILE`, `LGA_DIR`, `LGA_URL`, `LGA_BASENAME`, `LGA_MIN_PHP`, `LGA_MIN_WP` |
| wp-config secret constants | `PPCLG_SECRET_<KEY>` | `LGA_SECRET_<KEY>`; on multisite `LGA_SECRET_<BLOGID>_<KEY>` is checked first |
| Other wp-config constants | none | `LGA_CRON_TOKEN` (optional, overrides the stored token), `LGA_MAX_RESOURCES_PER_MONTH` (can only lower the ceiling, §7.6), `LGA_ALLOW_LOCAL_HOSTS` (test sites only, honored only when `wp_get_environment_type()` is `local` or `development`) |
| Global helper | `ppclg()` | `lga()` |
| Options, site options, transients | `ppclg_*` | `lga_*` |
| Post meta | foreign `ppc_en_counterpart` (removed) | own hidden meta `_lga_*` (for example `_lga_resource_id`, `_lga_canonical_url`) |
| User meta | `ppclg_dismissed_notices` | `lga_dismissed_notices` |
| Tables | `{$wpdb->prefix}ppclg_*` | `{$wpdb->prefix}lga_*` |
| Actions and filters | `ppclg_*` | `lga_*` |
| Cron hook, schedule key | `ppclg_tick`, `ppclg_tick_interval` | `lga_tick`, `lga_tick_interval` |
| Action Scheduler group | `ppclg` | `lga` |
| REST namespace | `ppclg/v1` | `lga/v1` |
| REST / WP_Error codes | `ppclg_*` | `lga_*` |
| Capabilities | `ppclg_manage`, `ppclg_submit_listings` | `lga_manage`, `lga_submit_listings` |
| Role | `ppclg_link_submitter` ("Link Growth Submitter") | `lga_link_submitter` ("LinkGrowth Submitter") |
| Text domain | `ppc-link-growth-engine` | `linkgrowth-autopilot` (`languages/linkgrowth-autopilot.pot`) |
| Admin menu | "Link Growth", slugs `ppclg`, `ppclg-<page>` | "LinkGrowth", slugs `lga`, `lga-<page>` |
| Script/style handles | `ppclg-admin` | `lga-admin`, `lga-calculator` (front end, only on pages that contain a calculator) |
| DOM ids, classes | `#ppclg-app`, `#ppclg-config`, `.ppclg-*`, `ppclg-page-<page>` | `#lga-app`, `#lga-config`, `.lga-*`, `lga-page-<page>` |
| CSS custom properties | `--ppclg-*` (35) | `--lga-*` |
| JS identifiers | `ppclgBusy` and similar camelCase | `lgaBusy` |
| localStorage keys | `ppclg_theme` etc. | `lga_theme` etc. |
| HTTP headers | `X-PPCLG-Secret` | `X-LGA-Secret` (inbound mail webhook), `X-LGA-Cron-Token` (server cron), `X-LGA-Signature` (outgoing and confirming webhooks) |
| Data attributes | `data-ppclg-probe`, `ppclg-fragment-root` | `data-lga-probe`, `lga-fragment-root`, plus `data-lga-control` on every interactive control (§12.5) |
| Outreach Message-ID | `ppclg-<hex>@<domain>` | `lga-<hex>@<domain>` (inbound regex `/lga-([a-f0-9]{32})/`) |
| User agent | `PPCLinkGrowthBot/1.0 (+home; link research)` | `LinkGrowthAutopilotBot/<LGA_VERSION> (+<home_url>/; link research)`, computed at runtime, editable in Advanced |
| Accept-Language | hard-coded `en-CA,en;q=0.9` | derived from `get_locale()` (`en_US` gives `en-US,en;q=0.9`), editable in Advanced |
| Site Health test ids | `ppclg_opcache` | `lga_opcache`, `lga_runner`, `lga_queue`, `lga_providers`, `lga_database` |
| WP-CLI | none | `wp linkgrowth …` (§5.6) |
| Backup directory | `ppclg-backups-<random>` | `lga-backups-<random>` |
| Credential encryption context | `ppclg-credentials-v1` | `lga-credentials-v1` (fresh secrets, no reuse) |
| Composer package | `purepeptidescanada/ppc-link-growth-engine` | `linkgrowth/linkgrowth-autopilot` |
| ZIP | `dist/ppc-link-growth-engine-<v>.zip` | `linkgrowth-autopilot/dist/linkgrowth-autopilot-<v>.zip` |
| Test env vars | `PPCLG_WP_PATH`, `PPCLG_IT_ONLY`, `PPCLG_FIXTURE_LOG`, `PPCLG_RESET`, `PPCLG_FIXTURE_BASE` | `LGA_WP_PATH`, `LGA_IT_ONLY`, `LGA_FIXTURE_LOG`, `LGA_RESET`, `LGA_FIXTURE_BASE` |
| Test globals, fixture arg | `$GLOBALS['ppclg_test_*']`, `_ppclg_fixture` | `$GLOBALS['lga_test_*']`, `_lga_fixture` |
| Class renames | `Integrations\ClaudeWriter` | `Integrations\AiWriter` (provider label "Anthropic API (optional writer)"; secret `LGA_SECRET_ANTHROPIC_API_KEY`) |
| | `Compliance\StorefrontAudit` | `Compliance\PositioningGate` (§2.4) |
| | `Discovery\VerifiedOpportunities`, `Jobs\ImportVerifiedJob` | `Discovery\CatalogImporter`, `Jobs\CatalogImportJob` (§2.5) |
| | `Discovery\SeedCatalog` | `Discovery\PackSeeds` |
| | `Jobs\StorefrontAuditJob` | `Jobs\PositioningAuditJob` |

Why `lga` and not the longer `lgap` that the mapping notes used: the owner's decision for this phase fixes `LGA_` / `lga_` / `lga/v1`. `lga` is short, and a search of the engine found no existing identifier containing `lga_`, so the bare-token replacement is safe.

### 1.2 Folder layout

`/home/user/Growth-engine-PPC/linkgrowth-autopilot/` is both the source project and the plugin folder. Development-only folders sit beside the shipped ones and are left out of the ZIP by an allowlist (§1.3, step 6).

```
linkgrowth-autopilot/
  linkgrowth-autopilot.php      shipped   bootstrap, header, constants, environment guard
  uninstall.php                 shipped
  readme.txt                    shipped
  includes/                     shipped   PHP (PSR-4: LinkGrowth\Autopilot\ -> includes/)
  assets/                       shipped   admin SPA (js/, css/), front-end calculator (js/front/)
  data/                         shipped   neutral data only (§2.5)
    universal/                  keywords-universal.json, excluded-domains.json, query-templates.json
    packs/industry/             general.json, research-supplies.json
    packs/country/              us.json, ca.json, gb.json, ie.json, au.json, nz.json, de.json, fr.json
    catalog/                    global-platforms.json (optional import, §2.5)
    schema/                     industry-pack.schema.json, country-pack.schema.json, catalog.schema.json
  languages/                    shipped   linkgrowth-autopilot.pot
  docs/                         dev only  DESIGN.md and the deliverable docs (§16 list in WP29)
  tests/                        dev only  Unit/, integration/, e2e/, fixtures/, Support/, bootstrap.php
  bin/                          dev only  lint.sh, test.sh, build-zip.sh, neutrality.sh, php85-scan.sh, phpunit.phar
  composer.json, phpunit.xml.dist         dev only
  dist/                         dev only  built ZIPs
```

Why one folder instead of a nested `plugin/` folder: tests and scripts can use `dirname(__DIR__)` paths, a symlink of the folder into a test site works the same way as the engine's current setup, and the ZIP allowlist keeps dev files out.

### 1.3 Mechanical fork procedure (WP1)

**Fork point.** Another workflow is changing the engine while this design is written (visitor-request cost fixes in `Plugin.php`, `Automation/Scheduler.php`, `Database/Migrator.php`, `Support/Locks.php` and `tests/bootstrap.php`; more performance work is queued). WP1 forks only after the orchestrator confirms those changes are finished, records the fork point in `docs/notes/WP1.md` (`git rev-parse HEAD` plus `git diff --stat` and a hash of `git diff` for uncommitted engine changes), and carries those fixes over, because §11.3 depends on them. Later engine changes are not merged automatically.

Run with `nice -n 10`. `SRC=/home/user/Growth-engine-PPC/ppc-link-growth-engine`, `ROOT=/home/user/Growth-engine-PPC`, `DST=$ROOT/linkgrowth-autopilot`.

1. **Copy**, leaving out the store data:
   ```bash
   rsync -a "$SRC/includes" "$SRC/assets" "$SRC/uninstall.php" "$SRC/readme.txt" "$DST/"
   cp "$SRC/ppc-link-growth-engine.php" "$DST/linkgrowth-autopilot.php"
   mkdir -p "$DST/data/universal" "$DST/languages" "$DST/bin" "$DST/tests"
   cp "$SRC/data/keywords.json" "$SRC/data/excluded-domains.json" "$DST/data/universal/"   # rewritten in WP4a
   rsync -a --exclude 'fixtures/sites/*/listing' "$ROOT/tests/" "$DST/tests/"
   cp "$ROOT/bin/test.sh" "$ROOT/bin/lint.sh" "$ROOT/bin/build-zip.sh" "$ROOT/bin/phpunit.phar" "$DST/bin/"
   cp "$ROOT/phpunit.xml.dist" "$ROOT/composer.json" "$DST/"
   ```
   Not copied: `data/verified-opportunities.json`, `data/seeds.json`, `data/discovery-queries.json`, `bin/build-verified-db.py`, `ops/`, `dist/`, `releases/`, `docs/` (the docs are rewritten from scratch in WP29) and `docs/screenshots/`.
2. **Rename files**: `includes/Integrations/ClaudeWriter.php` becomes `AiWriter.php`, `includes/Compliance/StorefrontAudit.php` becomes `PositioningGate.php`, `includes/Discovery/VerifiedOpportunities.php` becomes `CatalogImporter.php`, `includes/Discovery/SeedCatalog.php` becomes `PackSeeds.php`, and the jobs as in §1.1. Delete `includes/Integrations/Search/Serper.php` (§6.6: it resells scraped Google results, which spec §3D forbids). Rename every remaining file whose name contains `ppclg` or `ppc-` (for example test harness files and mu-plugin examples) to the `lga` form.
3. **Replace strings** in all text files (`*.php *.js *.mjs *.css *.json *.txt *.xml *.sh *.dist`) **in this order** (longest and most specific first). The From and To columns are the literal file contents. Write the script in the scratchpad (not in the repository, because it contains the old identifiers) as a small PHP or Perl program that reads the pairs from a here-document and applies them with literal (non-regex) replacement, except rows 16 and 17 (regular expressions), so that no shell escaping of backslashes is involved.

   | # | From | To | Notes |
   |---|---|---|---|
   | 1 | `PPC\\LinkGrowth\\Tests` | `LinkGrowth\\Autopilot\\Tests` | two literal backslashes: composer.json autoload-dev |
   | 2 | `PPC\\LinkGrowth` | `LinkGrowth\\Autopilot` | two literal backslashes: PHP strings, composer.json |
   | 3 | `PPC\LinkGrowth` | `LinkGrowth\Autopilot` | one backslash: namespaces, `use`, `@package` |
   | 4 | `ppc-link-growth-engine` | `linkgrowth-autopilot` | slug, text domain, paths |
   | 5 | `PPCLinkGrowthBot` | `LinkGrowthAutopilotBot` | then made dynamic in WP4a |
   | 6 | `PPC Link Growth Engine` | `LinkGrowth Autopilot` | |
   | 7 | `Link Growth Engine` | `LinkGrowth Autopilot` | |
   | 8 | `PPC Link Growth` | `LinkGrowth Autopilot` | cron label |
   | 9 | `Link Growth Submitter` | `LinkGrowth Submitter` | role |
   | 10 | `X-PPCLG-` | `X-LGA-` | header |
   | 11 | `PPCLG_` | `LGA_` | constants, env vars |
   | 12 | `PPCLG` | `LGA` | remaining upper-case uses |
   | 13 | `ppclg/v1` | `lga/v1` | REST |
   | 14 | `ppclg_` | `lga_` | options, tables, hooks, caps, globals |
   | 15 | `ppclg-` | `lga-` | CSS classes and variables, handles, DOM ids, menu slugs |
   | 16 | regex `ppclg([A-Z])` | `lga$1` | JS camelCase (`ppclgBusy`) |
   | 17 | regex `\bppclg\b` | `lga` | menu slug `'ppclg'`, AS group, `ppclg(` helper |
   | 18 | `'Link Growth'` (menu and title strings, reviewed by hand) | `'LinkGrowth'` | `assets/js/app.js` title, `Admin/Menu.php` |

4. **Manual edits right after the replacement** (still WP1):
   - Plugin header and `readme.txt` as in §1.1; Version 1.0.0; `LGA_DB_VERSION` `'0.9.0'` (it becomes `1.0.0` in WP5).
   - `Plugin::NAME` constant, and every literal product name routed through it.
   - Rename the PHP classes listed in §1.1 and update the references (`grep -rn ClaudeWriter`, `StorefrontAudit`, `VerifiedOpportunities`, `SeedCatalog`).
   - Remove Serper from `ProviderRegistry`, `Settings`, `SettingsController`, `settings.js` and the tests.
   - `phpunit.xml.dist` source directory becomes `includes`; `bin/*.sh` paths change from `ppc-link-growth-engine` to `.`; `bin/test.sh` looks for `bin/phpunit.phar` in the fork.
   - `tests/bootstrap.php`: `LGA_*` constants pointing at the fork, test home `https://northwind.test` (the neutral demo business, §12.2).
   - Drop `ReflectionProperty::setAccessible()` / `ReflectionMethod::setAccessible()` calls (deprecated in PHP 8.5, a no-op since 8.1): `tests/Support/Container.php:18`, `tests/Unit/Seo/RenderCheckReviewFixesTest.php:269,285`, `tests/integration/wiring-audit.php:26`.
5. **Tests that assert PPC content** (inventory in the mapping, for example `VerifiedOpportunitiesTest` expecting 595 items, `LinkRulesLiveCaseTest`, `ReleaseWiringTest` expecting 1.2.4) are first ported to the new names. The ones that only test PPC data are **deleted** in WP1 and listed in `tests/PORTING.md` with the replacement test that WP4a-WP9 must add. A test is never weakened to pass; it is either kept with neutral data or deleted with a named successor.
6. **Build allowlist** (`bin/build-zip.sh`): stage into `mktemp -d` under the scratchpad, copy only `linkgrowth-autopilot.php uninstall.php readme.txt includes/ assets/ data/ languages/` into `stage/linkgrowth-autopilot/`, run `bin/lint.sh` and `bin/neutrality.sh --zip`, then `zip -qr dist/linkgrowth-autopilot-<version>.zip linkgrowth-autopilot`. Excluded on top of the allowlist: `*.DS_Store`, `.git*`, `node_modules`, `.phpunit.cache`, `*.map`.
7. **Source ZIP (spec §16 deliverable 3).** The same script also writes `dist/linkgrowth-autopilot-<version>-source.zip`, with the top folder `linkgrowth-autopilot-source/`. It contains the plugin allowlist plus `tests/`, `docs/`, `bin/`, `composer.json` and `phpunit.xml.dist`. It leaves out `dist/`, `.phpunit.cache/`, `node_modules/`, `tests/e2e/shots/` and every scratchpad path. It also leaves out the files that must name the engine: `docs/DESIGN.md`, `docs/notes/`, `tests/PORTING.md` and `tests/integration/coexistence.php`. `bin/neutrality.sh --source` runs patterns A, B and D on the extracted source ZIP with **no** exceptions, and the build fails on any hit. INSTALLATION.md and FEATURES.md name this file as the source deliverable, and the repository folder is the working copy.

### 1.4 Coexistence with the PPC plugin

- Different slug, namespace, constants, option, table, REST, hook, capability and AS group prefixes. A test (WP1, `tests/integration/coexistence.php`) activates both on the LGA test site and asserts: both boot; no `lga_` code reads a `ppclg_` option (a `pre_option_ppclg_*` filter counts reads during an LGA tick and must stay 0); deactivating one leaves the other's cron and AS actions in place.
- `Core\Environment::conflicts()` stays generic so the shipped plugin names no other product: it warns (dashboard and Site Health, nothing blocked) when another plugin registers the `lga/v1` REST namespace, the `lga_tick` hook or an `lga_` table, or when an active plugin basename is listed in the `lga_known_conflicts` filter (empty by default; `[basename => label]`). The warning text: "{label} is active on this site. Running both can double outbound crawling and contact attempts to the same sites. Use one per site." The owner can add the PPC plugin to that filter on sites where both are installed; the coexistence test does exactly that.
- **IndexNow conflicts** (`Core\Environment::indexNowConflicts(): list<array{id,label}>`). These detectors are generic (no product is named in shipped copy except the label of what was found). Each looks for a known IndexNow implementation that is already submitting URLs:
  - Rank Math: option `rank_math_modules` contains `instant-indexing`.
  - Yoast: option `wpseo` has `enable_index_now` true.
  - AIOSEO: the IndexNow addon basename `aioseo-index-now/aioseo-index-now.php` is active.
  - SEOPress: option `seopress_instant_indexing_option_name` has IndexNow enabled.
  - The standalone IndexNow plugin: a basename ending in `/indexnow-url-submission.php` is active.
  - Anything registered through the `lga_indexnow_implementations` filter.

  When the wizard runs or a conflict first appears, the plugin acts as follows. If `seo.indexnow` has never been saved by the owner, it is stored as `false`. The `indexnow` task stays off. The Overview, the SEO page and Site Health (`environment` check, warning) show "IndexNow is already submitted by {label}. LinkGrowth's IndexNow is off so the same URLs are not sent twice." The owner can still turn it on, and saving `true` while a conflict exists needs `confirm: true`. New resources are still announced through the other implementation, because it hooks post publication itself. Tests: U `IndexNowConflictTest` (each detector plus the filter), I `seo-conflicts` (Rank Math option set → `seo.indexnow` false after the wizard, no IndexNow request in the fixture log).
- **SEO plugin data** (`Seo\SeoPlugin\Adapter`) is **read-only**, except on posts the plugin itself created: resources (`_lga_resource_id` set) and syndicated copies received on a receiver site (`_lga_syndicated_from` set). On those posts only, the adapter writes the SEO title, meta description and canonical through the SEO plugin's own storage:
  - Rank Math: `rank_math_title`, `rank_math_description`, `rank_math_canonical_url`.
  - Yoast: `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_canonical`, or `YoastSEO()->meta` when available.
  - AIOSEO: `AIOSEO\Plugin\Common\Models\Post` when the class exists, else nothing.
  - SEOPress: `_seopress_titles_title`, `_seopress_titles_desc`, `_seopress_robots_canonical`.
  - The SEO Framework: `_genesis_title`, `_genesis_description`, `_genesis_canonical_uri`.

  Every write first stores a post revision (`wp_save_post_revision`) and an `audit_log` entry `seo.meta_written` with the old and new values. The adapter refuses any other post with `lga_seo_readonly`. Without an SEO plugin, the plugin outputs the description through its own `wp_head` tag only on those posts. Tests: U `SeoAdapterWriteScopeTest` (a normal post is refused; a resource is written with a revision and an audit row, for each adapter).

### 1.5 Removed outright

- The Serper adapter and the PPC data files.
- `PostLanguage` reading of `ppc_en_counterpart`. Polylang and WPML stay, and the setting `seo.language_counterpart_meta_key` lets owners name their own key.
- The hard-coded AI model default, and the store's brand, address and social defaults.
- The "Include non-Canadian sites" toggle that had no reader. It is replaced by `market.include_international`, which EvaluateJob enforces (§2.2).
- Every path through which automation approved or sent a cold first contact (§2.7):
  - `Autopilot::autoApproveMessages`, `autoApproveAutoCampaigns`, `autoApproveOwnerCampaigns` and `RAISED_FLAGS`.
  - `Policy::MODE_AUTO`, the auto-by-score submission mode.
  - `CampaignFiller::approveWaiting()` and `CampaignFiller::approveDrafts()`, and `AutomationController::approveWaitingNow()` with its two callers in `SettingsController`.
  - The route `POST /outreach/campaigns/{id}/approve-drafts` with `APPROVE_ALL_MAX`.
  - The settings `automation.outreach_autosend` and `automation.auto_outreach_drafts`, replaced by `automation.modules.outreach_drafts` and the per-message approval rule.
  - The `AUTOPILOT_LABELS` of GrowthRun ("Draft and approve outreach (Autopilot sends it)").

### 1.6 Version and changelog

`readme.txt` starts a new changelog at 1.0.0 ("Initial release, forked from an internal engine; see docs/FEATURES.md"). `tests/Unit/Wiring/ReleaseWiringTest.php` is rewritten to assert that the header Version, `LGA_VERSION` and `Stable tag` agree, whatever the value.

### 1.7 Verification: nothing PPC-specific remains

`bin/neutrality.sh` (run by `bin/lint.sh`, by the build and by `tests/Unit/Wiring/NeutralityTest.php`) scans the shipped files (`linkgrowth-autopilot.php uninstall.php readme.txt includes assets data languages`) and, with `--zip`, the extracted ZIP. Matching is case-insensitive and uses `grep -rniE`.

**A. Old identifiers: must be 0 hits anywhere in the fork, tests included** (exceptions: `docs/DESIGN.md`, which documents the map, and `tests/integration/coexistence.php`, which must activate the engine to prove the two do not interfere):

```
ppclg | PPCLG | PPC\\+LinkGrowth | ppc[-_ ]link[-_ ]growth | Link Growth Engine | PPCLinkGrowthBot | ppc_en_counterpart | ppc-french | \bPPC\b
```

**B. Brand and store data: 0 hits in shipped files and tests:**

```
purepeptides | pure peptides | PurePeptidesCan | 61592401685940 | peptide | janoshik | 222 Jarvis | Jarvis St | M5B | Xpresspost | Interac | GHK | bacteriostatic | hi@pure
```

**C. Industry and market words: allowed only in listed files.** Pattern:

```
research[- ]use[- ]only | \bRUO\b | not for human | HPLC | certificates? of analysis | Health Canada | Canada Post | canadapost | Toronto | Ontario | Canadian | \bCASL\b | \.gc\.ca
```

Allowlist: `data/packs/industry/research-supplies.json` (the positioning statement and its claim packs), `data/packs/country/ca.json`, `includes/Outreach/Jurisdictions.php` (CASL profile), `includes/Profile/CountryData.php` (ccTLD and regulator tables), `includes/Content/ClaimPacks.php` (regulator alternation built from country packs), and the matching unit tests under `tests/Unit/Profile/` and `tests/Unit/Outreach/JurisdictionsTest.php`. Any hit outside the allowlist fails.

**D. Model identifiers: 0 hits everywhere, docs included:**

```
\b(claude|gemini|llama|mistral)-[a-z0-9.]+-[a-z0-9.]+  |  \bgpt-[0-9]  |  'model'\s*=>\s*'[a-z]
```

The first two patterns match versioned model names (a family word followed by at least two hyphenated parts, or the gpt- prefix with a digit) but not scratchpad paths such as `/tmp/claude-0/…`. The third catches a non-empty model default in PHP settings arrays.

**E. Structural checks** (NeutralityTest):
- `Settings::defaults()` has empty `site.brand_name`, `site.email`, `site.address`, `site.city`, `site.region`, `site.postal_code`, `site.country`, `site.social`, `site.categories` and `providers.ai.model`.
- No default enables outbound acquisition: `automation.autopilot`, `automation.modules.submissions`, `outreach_drafts` and `distribution` are all false.
- `data/` contains only the files listed in §1.2.
- The ZIP has exactly one top-level folder `linkgrowth-autopilot/` and no `tests/`, `docs/`, `bin/`, `dist/` or `node_modules/`.

**F. Settings keys** (`tests/Unit/Wiring/SettingsKeysTest.php`, run with NeutralityTest). A grep over `includes/` collects every literal key passed to `->get( '…' )`, `->set( '…' )` or `Settings::path( '…' )` on the settings object. It also collects every `data-key` / `bind( '…' )` key in `assets/js/settings/*.js`. Each key must be declared in `SettingsSchema`, either as a leaf, under a declared map (`automation.schedules.<task>`, `providers.<id>.*`, `notifications.events.<event>`) or as a virtual read-only key (`consent.*`, §2.2). An undeclared key fails the test, and so does a declared key that no code reads (dead setting).

---

## 2. Generalization

### 2.1 The site profile

Everything site-specific moves into one per-site profile, filled by the setup wizard and edited in Settings. `Profile\Profile` is a read-only view over `Settings` and the active packs, with typed getters used by every module (`brand()`, `domain()`, `ownedDomains()`, `targetCountries()`, `primaryCountry()`, `languages()`, `industryPack()`, `coreTerms()`, `targetKeywords()`, `audience()`, `positioning()`, `jurisdictionFor(string $country)`, `geoModifiers()`, `providerDatabase(string $provider)`).

`Profile::brand()` returns `site.brand_name`, else `get_bloginfo('name')`, else the domain; it replaces every "Pure Peptides Canada" fallback (Menu.php, app.js, SettingsController, QueryGenerator, ContentGenerator, Personalizer, BrokenLinkScanJob, Notifier). Notifications say "your site" when even the domain is unknown.

**Setup stages.** Each task's gates are declared on its `TaskDefinition` (§5.1).

| Stage | Marker | What may run | Blocked, with reason |
|---|---|---|---|
| 0. Activated | none | `health_check`, `cleanup`, `provider_health` (tests only providers the owner configured, so none at first), `site_audit` (own host only, through the self-request allowlist, §11.1) and self-heal | every task with `requiresProfile = true`: discovery (`discover`, `mention_scan`, `competitor_backlinks`, `broken_link_scan`, `catalog_import`), analysis (`evaluate`, `find_opportunities`, `rescore`, `metrics_refresh`), `ownership_check`, `monitor_backlinks`, `import_backlinks`, `analytics_snapshot`, `content_gaps`, `internal_links`, `indexnow`, `decision_recompute` and every acquisition task. Reason: `lga_profile_incomplete` ("Finish the business, market and industry steps of setup first") |
| 1. Profile ready | `setup.profile_ready = true`, set by `POST /setup/step/{n}` once steps 2, 3 and 4 have all been saved valid (and cleared if a later save makes one of them invalid) | everything not acquisition: discovery, analysis, monitoring, imports, analytics, on-site SEO suggestions, content gap analysis, drafting (resource drafts, outreach drafts) | submissions, outreach sending, follow-ups, distribution, resource publishing, applying internal links. Reason: `lga_setup_incomplete` |
| 2. Setup completed | `setup.completed = true` (`POST /setup/complete`) | acquisition tasks whose module flag is on and whose consent is recorded (§2.7) | |

No outbound request is made before stage 1. That covers crawling, search queries, RDAP, IndexNow pings, catalog fetches and provider calls the owner did not configure. The plugin also sends no telemetry and makes no update or licence check at any stage (SECURITY.md: "no telemetry, no phone-home"). WordPress core's own update checks are not affected. `IndexNow` hooks on post transitions only add URLs to `lga_indexnow_pending`, and the `indexnow` task that would send them is blocked until stage 1. Test: I `activation-silence` (WP4b). On a fresh install the runner activates the plugin and runs 10 ticks with a frozen clock advanced 15 minutes per tick. It also publishes a post and saves the Woo store address in between. A `pre_http_request` logger in the test mu-plugin records every outbound host. The assertion is 0 requests to any host other than `home_url()`'s host.

**The three switches.**
- `automation.enabled` (default true) is the **master kill switch**, labeled "Pause everything" on the Automation page. When false, no task is enqueued and no claimed job starts. The tick still runs self-heal and `health_check`. REST reads, the dashboard and manual edits keep working.
- `automation.autopilot` (default false) means **unattended outbound acquisition under the recorded consents**. When it is on, the scheduler may start, by itself, the tasks marked `autopilot = true` in §5.1 (submit, distribution_send, the publish step of resource_build, applying internal links, autosent follow-ups), each only if its own consent is recorded. When it is off, those tasks run only when a person starts them ("Run now", "Grow now", or a per-item action), still behind the same module flag, consent, setup and limit gates. Autopilot never changes another setting, never approves a first contact and never raises the submission mode (§2.7).
- **Module flags** `automation.modules.<module>` turn individual task groups on or off (§2.2).

The Overview switch toggles `automation.autopilot` only. Turning it on opens the consent dialog (`POST /automation/autopilot` with `confirm: true` and the consents to record). The master switch is on the Automation page, and module flags are on the Automation page and in Settings > Automation.

**Setup wizard** (`admin.php?page=lga-setup`, SPA page `setup`, REST `/setup*`).

| Step | Fields (settings key) | Prefill (shown as "suggested, not verified" until confirmed) | Required to finish |
|---|---|---|---|
| 1 Environment | read-only report | `Core\Environment::report()`: WP, PHP, extensions (sodium, openssl, mbstring, zlib, intl), Action Scheduler, `DISABLE_WP_CRON`, object cache, page-cache plugin, WooCommerce and HPOS, SEO plugin, multilingual plugin, known conflicts (`Environment::conflicts()`) | none |
| 2 Business identity | `site.brand_name`, `legal_name`, `domain`, `home_url`, `tagline`, `email`, `phone`, `business_type` (`storefront`: customers visit a physical address; `service_area`: the business travels to customers from a real base address; `online_only`: no customer-facing location), `address`, `city`, `region`, `postal_code`, `country`, `founded_year`, `logo_url`, `social[]`, `categories[]`, `owned_domains[]` (plus the suggested owned domains of §2.8, each to confirm or reject) | blogname, blogdescription, home_url host, admin_email (flagged "confirm this address is public"), `woocommerce_store_address/_2/_city/_postcode`, `woocommerce_default_country` split into country and region, custom logo, homepage Organization / LocalBusiness JSON-LD (`legalName`, `telephone`, `foundingDate`, `sameAs`; a `LocalBusiness` type with `address` suggests `storefront`), WooCommerce top-level product categories; `business_type` is never prefilled as confirmed | brand_name, domain, country, business_type, at least one category |
| 3 Market and languages | `market.target_countries[]` (ISO-2), `market.target_regions[]` (ISO 3166-2, optional), `market.languages[]` (BCP 47 primary tags), `market.include_international`, `market.currency` | base country; `get_locale()` plus Polylang/WPML languages; WooCommerce currency | one country, one language |
| 4 Industry, audience, keywords | `industry.pack`, `industry.secondary_packs[]` (at most 2), `industry.label`, `industry.products_services[]`, `industry.core_terms[]`, `industry.adjacent_terms[]`, `industry.negative_terms[]`, `audience.segments[]` (`{label, description}`), `keywords.target[]`, `keywords.target_urls[]`, `keywords.excluded_urls[]` (patterns), `keywords.excluded[]`, `discovery.excluded_domains[]`, `discovery.competitor_domains[]` | pack terms; WooCommerce categories and best-selling product names; non-brand GSC queries (top 50 by impressions, if connected); SEO-plugin focus keywords; page titles of the main menu | pack, 3 target keywords |
| 5 Verified facts | rows in `lga_facts` (§4.3) for each key in the pack's `fact_schema` | statements built from steps 2-4, all unverified | every `required` fact key verified |
| 6 Linkable assets | `content.linkable_assets[]` confirmed from the inventory (§7.1) | `Resources\Inventory::candidates()` | none (home page is the fallback) |
| 7 Outreach identity | `outreach.sender_name`, `sender_email`, `reply_to`, `sending_domains[]`, `postal_address`, `privacy_url`, `signature`, `timezone`, `expertise_topics[]` (optional, §2.7); shows the jurisdiction profiles that apply (§2.6) | site email, address from step 2, WordPress timezone, the privacy page (`get_privacy_policy_url()`) | only if outreach is turned on later |
| 8 Providers | per-provider credentials and Test (§6) | Site Kit detected for Google | none |
| 9 Runner and limits | `automation.runner`, server-cron token (generated here, shown once), the crontab lines filled with this site's path and URL (§5.6), schedule and limit defaults | detected WP-CLI availability is unknown to PHP, so both methods are shown | none |
| 10 Review and consent | `automation.autopilot` plus separate opt-ins: `consent.submissions`, `consent.distribution`, `consent.resource_publish`, `consent.internal_links`, `consent.follow_ups`. Each consent is recorded in `lga_autopilot_consent` with user id, time, policy version and the SHA-256 of the exact text shown, and in `audit_log`. The page states what still needs a person (Appendix B): every first contact, authorization records, owner tasks, YMYL and guide drafts | | the identity, market and facts steps |

### 2.2 Settings schema changes

Settings stay in one option `lga_settings` (autoload `no`). `Support\SettingsSchema` declares the type and validation of every key. `SettingsController` validates from the schema instead of its hand-written range table, so each module contributes its own section (§3.1). Validation rules used: `bool`, `int{min,max}`, `float{min,max}`, `enum[...]`, `string{max,pattern}`, `email`, `url{https}`, `list<T>{max_items}`, `iso_country`, `iso_region`, `bcp47`, `domain`, `weights{sum:100}`.

The complete key list follows. Engine keys not listed here are carried over renamed, with the same type, default and range, and each module declares them in its `settingsRules()`. `SettingsKeysTest` (§1.7 F) fails on any key read in code that is missing here or from a module's rules. Columns: key, type and rule, default, meaning. `list<T>{n}` means at most n items. Validation failures return 400 `lga_invalid_setting` with the key and the rule.

```
# Identity and market (wizard steps 2-4)
site.brand_name / legal_name / tagline / email / phone / address / city / postal_code   string{max:190}   ''
site.region                  iso_region                            ''
site.country                 iso_country                           ''
site.domain / home_url       domain / url{https}                    derived from home_url()
site.business_type           enum[storefront,service_area,online_only]  ''   required to finish setup; drives map/local items (§2.5)
site.founded_year            int{1800,current year}                null
site.logo_url                url{https}                            ''
site.social[]                list<url>{20}                          []
site.categories[]            list<string{max:80}>{20}               []
site.owned_domains[]         list<domain>{100}                      []   owned sites (§2.8): never prospects, contacts or targets; their links are_owned
market.target_countries[]    list<iso_country>{30}                  []   empty = base country only at runtime
market.target_regions[]      list<iso_region>{50}                   []
market.languages[]           list<bcp47>{10}                        []   empty = the site locale language
market.include_international bool                                  true   false: prospects outside target countries are rejected in EvaluateJob
market.currency              string{pattern:^[A-Z]{3}$}             ''
industry.pack                enum[installed packs]                 'general'
industry.secondary_packs[]   list<enum[installed packs]>{2}         []   merged as adjacent topics, extra directory categories and lower-prior seeds (§2.3); must differ from industry.pack
industry.label               string{max:80}                         ''   '' = the pack label
industry.products_services[] / core_terms[] / adjacent_terms[] / negative_terms[]   list<string{max:80}>{50}  []   merged over the packs
audience.segments[]          list<{label:string{max:80}, description:string{max:300}}>{10}  []
keywords.target[]            list<string{max:100}>{50}              []
keywords.target_urls[]       list<url>{200}                         []
keywords.excluded_urls[]     list<string{max:200}>{200}             []   URL patterns (* wildcard), never targets
keywords.excluded[]          list<string{max:100}>{200}             []
discovery.excluded_domains[] list<domain>{1000}                     []
discovery.competitor_domains[] list<domain>{20}                     []
discovery.competitor_domains_auto[] list<domain>{20}                []   written only by the competitor detector (§2.7), removable by the owner

# Setup and consent
setup.profile_ready          bool, read-only (written by POST /setup/step/{2,3,4})   false
setup.completed              bool, read-only (written by POST /setup/complete)       false
consent.{submissions,distribution,resource_publish,internal_links,follow_ups}
                             VIRTUAL read-only bool: answered from the record in lga_autopilot_consent (§4.4);
                             POST /settings refuses them with 400 lga_consent_readonly; written only by
                             POST /setup/complete and POST /automation/autopilot with confirm=true, and revoked
                             by POST /automation/consents/{key}/revoke

# The three switches (§2.1)
automation.enabled           bool                                  true    master kill switch ("Pause everything")
automation.autopilot         bool, written only by POST /automation/autopilot (confirm=true, requires setup.completed)   false
automation.modules.{discovery,evaluation,opportunities,packaging,monitoring,imports,analytics,decision,seo,content_gaps}   bool   true
automation.modules.{submissions,outreach_drafts,distribution,resource_drafts}                                             bool   false

# Scheduling, runner and limits
automation.submission_mode   enum[authorized_only,manual]          'authorized_only'   (engine 'auto' removed; stored 'auto' migrates to authorized_only)
automation.runner            enum[auto,server_cron,wp_cron]        'auto'   (§5.5)
automation.server_cron_interval int{60,3600}                       300     seconds between the owner's crontab runs (heartbeat expectation)
automation.server_cron_fallback bool                               true    re-enable the WP-Cron/AS tick when the heartbeat is missing
automation.tick_interval     int{60,900}                           300     (engine 60-86400; 15-minute tasks need <= 900)
automation.max_execution_seconds int{15,55}                        20      HTTP runs
automation.cli_max_seconds   int{30,900}                           240     wp linkgrowth run
automation.schedules.<task>  enum[15min,hourly,6h,daily,weekly,monthly,continuous,off]   per §5.1   continuous only for tasks with a due-check; off refused for health_check and cleanup
automation.anchors.<task>    {hour:int{0,23}, minute:int{0,59}, weekday:int{1,7}, day:int{1,28}}   {}
automation.daily_limits.<counter> / weekly_limits.<counter> / monthly_limits.<counter>   int{0,100000}   per §5.3 (0 = no limit for that period); outreach_emails daily is int{0,50}
automation.min_score_auto_queue int{50,100} 70;  min_score_keep int{0,69} 50;  max_risk_auto_reject int{30,100} 70;  min_confidence_auto int{40,100} 60
scoring.weights              weights{sum:100, each int{0,40}}      {topical_relevance:25, site_quality_legitimacy:20, source_page_quality:15, audience_traffic_evidence:15, editorial_context:10, link_accessibility_permanence:10, risk:5}

# Content (§7)
content.publish_mode         enum[draft,auto_when_passed]          'draft'
content.min_gap_score        int{0,100}                            60
content.thresholds           object, only the tunable keys of §7.5 (accuracy and compliance are fixed floors and are refused here)
content.ai_drafting          bool                                  false
content.post_type            enum[page,post]                       'page'
content.authors[]            list<{name:string{max:120}, credentials:string{max:200}, user_id:int, confirmed_by:int, confirmed_at:datetime}>{10}  []   bylines and credentials only for real people the owner confirmed
content.linkable_assets[]    list<{url:url, post_id:int, label:string{max:120}, topics:list<string>{10}}>{100}  []   confirmed in wizard step 6
content.claims_custom_block[] list<string{max:120}>{100}           []

# Distribution (§6.6)
distribution.approval        enum[required,auto_own_channels]      'required'
distribution.utm             {source:string{max:40}, medium:string{max:40}, campaign:string{max:60}}   {source:'{channel}', medium:'social', campaign:'lga-{asset_type}'}
distribution.paid_budget     int{0,100000}                         0       monthly budget in market.currency for paid or sponsored placements; 0 = never (§6.7)
distribution.receiver_enabled bool                                 false   this site accepts syndicated copies (§6.6)
distribution.receiver_allowed_sources[] list<domain>{50}           []      hosts allowed to syndicate to this site
distribution.revert_missing_canonical bool                         true    a syndicated copy whose canonical is missing is switched back to draft

# Outreach (§2.6, §2.7)
outreach.sender_name / signature / postal_address   string{max:300}  ''
outreach.sender_email / reply_to   email                           ''
outreach.sending_domains[]   list<domain>{5}                       []      extra domains the sender address may use (must be the owner's: confirmed by the DNS check in POST /outreach/sender)
outreach.privacy_url         url{https}                            ''      '' = get_privacy_policy_url(); required by uk_pecr, eu_eprivacy and strict profiles
outreach.timezone            IANA zone or ''                       ''
outreach.expertise_topics[]  list<string{max:80}>{10}              []      topics the business can speak on (§2.7); empty disables expert_commentary and podcast approaches
outreach.require_approval    bool, locked true for first contacts (read-only in the UI; a stored false is ignored)   true
outreach.autosend_followups  bool                                  false   needs consent.follow_ups, reply tracking and Autopilot (§2.7)
outreach.max_follow_ups      int{0,1}                              1       at most one follow-up per first contact
outreach.follow_up_days      int{5,30}                             7
outreach.min_days_between_contacts int{30,365}                     90
outreach.cross_site_cooldown_days int{0,365}                       90      cool-down for domains in an imported "contacted by my other sites" list (§2.8)
outreach.send_window_start / send_window_end   int{0,23}           9 / 17
outreach.send_days[]         list<int{1,7}>{7}                     [1,2,3,4,5]

# Notifications
notifications.email          email or ''                           ''      '' = site.email, then admin_email
notifications.digest         enum[daily,weekly,off]                'weekly'
notifications.critical_immediately bool                            true
notifications.events.{link_lost, link_changed, suspicious_change, hidden_link, submission_failed, submission_confirmed, provider_error, strategy_paused, resource_published, health_degraded, cron_missing, owned_site_suggested}   bool   true

# Providers
providers.<id>.enabled       bool                                  false for every provider (engine: true for SE Ranking and Google)
providers.<id>.limits        {per_minute,per_hour,per_day,per_month,units_per_month: int{0,…}}   {}   owner overrides; may only lower the declared limits
providers.ai.model           string{max:100}                       ''      owner-supplied; the field offers the provider's model list endpoint

# On-site SEO
seo.seo_plugin               enum[auto,rank_math,yoast,aioseo,seopress,tsf,none]   'auto'
seo.indexnow                 bool                                  true    stored false by the wizard when another IndexNow implementation is active (§1.4)
seo.internal_links           bool                                  true    suggestions
seo.auto_apply_links         bool                                  false   apply suggestions without a click; needs consent.internal_links (§2.7)
seo.auto_apply_per_day       int{1,50}                             10
seo.max_links_per_post       int{1,5}                              3
seo.never_anchor[]           list<string{max:80}>{200}             []      words never used as internal-link anchors
seo.language_counterpart_meta_key string{max:64, pattern:^[A-Za-z0-9_]+$}   ''
compliance.positioning_gate  bool                                  true    has an effect only when a positioning rule exists (§2.4)

# Advanced
advanced.user_agent / accept_language   string{max:200}            ''      '' = computed (§1.1)
advanced.delete_on_uninstall bool                                  false
advanced.receiver_uninstall_action enum[keep,draft]                'keep'  what uninstall does to received syndicated copies (§4.4)
advanced.log_retention_days  int{7,365}                            30
advanced.audit_retention_days int{90,3650}                         365
advanced.job_retention_days  int{1,90}                             14
advanced.activity_retention_days int{30,365}                       90
advanced.check_history_days  int{180,1825}                         730
advanced.analytics_retention_months int{3,25}                      25
advanced.contact_retention_days int{90,3650}                       730     after last activity (§11.9)
advanced.trusted_proxies[]   list<ip or cidr>{20}                  []      only these REMOTE_ADDRs may supply X-Forwarded-For for rate limiting
advanced.request_timeout int{5,30} 15;  crawl_delay_seconds int{1,30} 2;  max_response_kb int{256,4096} 1536
```

Removed engine keys: `automation.submissions`, `automation.auto_outreach_drafts` and `automation.outreach_autosend` (replaced by module flags and §2.7), `automation.international` (now `market.include_international`), `discovery.geo_modifiers` and `discovery.brand_terms` (derived, §2.4), `discovery.seed_catalog` (now pack seeds), `compliance.storefront_gate` (now `compliance.positioning_gate`), and `providers.search.serper_daily_limit`. The 1.0.0 migration (§4.5) maps stored values and drops the rest.

### 2.3 Industry and country packs

Packs are JSON files validated against `data/schema/*.schema.json` by `Profile\PackRepository` (no code in packs, only data). Owners can import a custom pack (`POST /packs/import`, max 256 KB) that is stored in option `lga_custom_packs`. The filters `lga_industry_packs` and `lga_country_packs` let extensions add packs.

**Industry pack schema** (all keys optional except `schema`, `id`, `label`):

```json
{
  "schema": 1,
  "id": "general",
  "label": "General business",
  "version": "1.0.0",
  "ymyl": false,
  "topics": { "core": [], "adjacent": [] },
  "directory_categories": ["business directory", "local business directory", "industry association", "chamber of commerce", "supplier directory", "professional association"],
  "directory_subtypes": ["industry", "business", "professional", "niche", "supplier", "resource", "educational", "regional_business", "association"],
  "site_type_boost_terms": { "supplier_directory": ["supplier", "vendor", "manufacturer", "wholesale", "distributor"] },
  "audience_phrases": { "default": "people and organisations looking for {category}" },
  "phrases": { "supplier_noun": "business", "directory_phrase": "directories that list {category} businesses", "organisation_phrase": "organisations in the {industry} sector" },
  "fact_schema": [
    { "key": "identity", "label": "What the business is and does", "required": true },
    { "key": "location", "label": "Where it is based / service area", "required": true },
    { "key": "established", "label": "Year founded", "required": false },
    { "key": "products_services", "label": "Main products or services", "required": true },
    { "key": "audience", "label": "Who the customers are", "required": false },
    { "key": "differentiators", "label": "Verifiable differentiators", "required": false },
    { "key": "expertise", "label": "Topics the business can speak about with authority (who, and why)", "required": false },
    { "key": "contact", "label": "Public contact channel", "required": true }
  ],
  "claim_packs": [],
  "positioning": null,
  "misuse_terms": [],
  "audience_conflict_reason": "",
  "generic_anchor_terms": [],
  "excluded_competitor_niches": [],
  "never_pursue_site_types": ["bookmarking", "link_dump", "web20_profile", "comment_forum", "article_directory_spun"],
  "spam_whitelist": [],
  "query_templates": {
    "business_directories": ["{category} directory {geo}", "list your business {geo} {category}", "add your company {category} {geo}"],
    "industry_associations": ["{industry} association {geo} members", "{industry} trade association directory"],
    "resource_pages": ["intitle:resources {topic}", "\"useful links\" {topic}", "\"recommended tools\" {topic}", "\"further reading\" {topic}"],
    "publications": ["{industry} magazine \"write for us\"", "{topic} blog \"contribute\"", "{industry} news \"submit a story\""],
    "educational": ["{topic} guide site:.edu", "{topic} course resources"]
  },
  "asset_type_priors": { "statistics": 0.8, "calculator": 0.75, "dataset": 0.7, "glossary": 0.6, "reference_guide": 0.55, "comparison": 0.5, "infographic": 0.45 },
  "seeds": []
}
```

**Example pack `research-supplies`** (shipped, opt-in; it carries no brand and no store data):

```json
{
  "schema": 1, "id": "research-supplies", "label": "Research and laboratory supplies", "ymyl": true,
  "topics": {
    "core": ["laboratory reagents", "research chemicals", "lab consumables", "analytical standards", "laboratory supplies"],
    "adjacent": ["life science research", "biotechnology", "analytical chemistry", "laboratory equipment", "university research labs"]
  },
  "directory_categories": ["Laboratory Supplies", "Scientific Research Supplier", "Research Reagents", "Biotechnology"],
  "site_type_boost_terms": { "supplier_directory": ["laborator", "scientific", "life science", "reagent", "supplier"] },
  "positioning": {
    "statement": "For research use only. Not for human or veterinary use.",
    "pattern": "research use only|not for human",
    "contradiction_terms": {
      "block": ["for human use", "dosage", "dosing", "injectable", "syringe", "supplement", "weight loss", "fat burn", "anti-aging", "muscle growth", "bodybuilding", "libido", "nootropic"],
      "warn": ["wellness", "beauty", "skincare", "cosmetic"],
      "title_forms": ["capsule", "nasal spray", "pen injector", "gummies"]
    }
  },
  "claim_packs": ["health_claims", "research_use_only", "regulatory_approval"],
  "misuse_terms": ["dosing guide", "cycle protocol", "stack protocol", "clinic protocol"],
  "audience_conflict_reason": "Content targets non-research use, which conflicts with the research-use-only positioning.",
  "excluded_competitor_niches": ["pharmacy", "clinic", "supplement", "fitness", "cosmetic"],
  "generic_anchor_terms": ["research", "sample", "samples", "kit", "vial", "vials"],
  "fact_schema": [
    { "key": "identity", "required": true }, { "key": "location", "required": true }, { "key": "products_services", "required": true },
    { "key": "quality_evidence", "label": "How quality is documented (for example certificates of analysis)", "required": false },
    { "key": "compliance_statement", "label": "Intended-use statement", "required": true },
    { "key": "contact", "required": true }
  ],
  "query_templates": {
    "industry_directories": ["{topic} supplier directory {geo}", "scientific supplier directory {geo}", "laboratory supplies vendors list {geo}"],
    "resource_pages": ["laboratory resources {topic}", "\"lab resources\" {topic} university"]
  },
  "seeds": []
}
```

**Secondary packs** (`industry.secondary_packs[]`, at most 2, for businesses that span industries). `Profile\PackRepository::merged()` merges them under the primary pack:
- Their `topics.core` and `topics.adjacent` are added to the **adjacent** terms.
- Their `directory_categories` and `directory_subtypes` are added.
- Their `query_templates` are added with weight 0.5 for the decision engine's template sampling.
- Their `seeds` are added as prospects with prior score −10.
- Positioning, `claim_packs`, `misuse_terms`, `never_pursue_site_types` and `ymyl` are combined with the stricter value winning: claim packs are unioned, `ymyl` is ORed, and a secondary pack's positioning applies when the primary has none.
- `fact_schema` keys are unioned, and a key is required if any pack requires it.
- `asset_type_priors` take the maximum.

Relevance scoring treats secondary-pack topics as adjacent (lower weight), so a secondary industry never outweighs the primary one.

When `positioning` is set: `ClaimsFilter` appends the statement to listing descriptions, `fitLength` keeps it, `PositioningGate` runs (§2.4), outreach templates fill `{{positioning_note}}`, and `ymyl: true` forces resources into draft-only mode (§7.6). With the `general` pack, none of this exists: no statement is appended, no gate runs and the job is not scheduled.

**Country pack schema** (`data/packs/country/<iso>.json`):

```json
{
  "schema": 1, "iso": "CA", "name": "Canada", "demonym": "Canadian",
  "regions": [{ "code": "CA-ON", "name": "Ontario", "tz": "America/Toronto" }],
  "major_cities": ["Toronto", "Montreal", "Vancouver"],
  "cctld": ["ca"], "second_level_suffixes": ["gc.ca", "on.ca", "qc.ca", "bc.ca", "ab.ca"],
  "postal_regex": "^[A-Z]\\d[A-Z] ?\\d[A-Z]\\d$", "phone_codes": ["+1"],
  "regulators": ["Health Canada", "Competition Bureau"],
  "provider_db": { "semrush": "ca", "seranking": "ca", "brave_country": "CA", "gsc_country": "can" },
  "jurisdiction": "casl",
  "geo_terms": ["Canada", "Canadian"],
  "media_giants": ["cbc.ca", "theglobeandmail.com"],
  "excluded_domains": ["canadapost-postescanada.ca", "amazon.ca", "ebay.ca", "walmart.ca"],
  "seeds": [{ "name": "…", "url": "https://…", "category": "business_directory", "industries": ["*"] }]
}
```

The CA pack receives the general Canadian business directories and chambers from the engine's `seeds.json`; sector seeds (life-science associations and lab directories) move to `research-supplies.json` `seeds` tagged `"countries": ["CA"]`. Subdivision names come from `WC()->countries->get_states()` when WooCommerce is active, else from the pack. Countries without a pack still work: the ccTLD table (`Profile\CountryData::CCTLD`, about 250 entries) and `DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $iso)` give the country and its time zones, and the jurisdiction falls back to the strictest profile (§2.6).

### 2.4 Site-specific item and its replacement

| Engine item (file) | Replacement in the fork | WP |
|---|---|---|
| Header, readme, composer: brand, URIs, contributors | Neutral header (§1.1) | 1 |
| `Settings.php` `site.*` store identity, social, categories, domain fallback | Empty defaults; wizard step 2 prefill | 4a |
| `discovery.geo_modifiers` (Canada list), `brand_terms` | `Profile::geoModifiers()` from target countries, regions and cities in country packs; `brand_terms` derived from brand, legal name and domain forms (`BrandTerms::domainForms`), editable | 4a |
| SE Ranking region and Semrush database `'ca'` | `Profile::providerDatabase()` from the primary target country's pack, else WooCommerce base country, else `us`; editable per provider | 4a |
| `providers.ai.model` default and `ClaudeWriter` constants | Empty model setting; `AiWriter` disabled until key and model are set; the model list comes from the provider's list endpoint at runtime | 4a |
| UA `PPCLinkGrowthBot/1.0`, `Accept-Language: en-CA` | Computed (§1.1) | 4a |
| Go-live defaults (submissions on, mode `auto`, auto outreach drafts, SE Ranking and Google enabled, storefront gate on) | Neutral defaults (§2.2) | 4a |
| `automation.international` dead toggle | `market.include_international`, enforced in EvaluateJob (geo filter) with a test | 6 |
| `FactsRegistry` 19 store facts | Empty `lga_facts` table; pack `fact_schema` drives the wizard; `supports()` compares against the owner's statements only | 7 |
| `ContentGenerator` store sentences, provinces table, RUO handling | Fact-driven composer: one template per fact group (`'{brand} is {identity}.'`, `'Based in {city}, {region_name}.'`, `'Founded in {year}.'`, …), destination-seeded variety kept; positioning statement only when the pack defines one; region names from packs or WooCommerce | 7 |
| `ClaimsFilter` peptide/medical RULES, RUO append | Two layers: **core rules always on** (unsupported superlatives and guarantees; invented testimonials, reviews, ratings, endorsements, awards, "as featured in"; unverified approvals and certifications; "studies show" without a source; invented statistics; fake scarcity; link-exchange offers) and **claim packs** enabled by the industry pack or the owner (`health_claims`, `research_use_only`, `supplements_cosmetics`, `financial_returns`, `legal_outcomes`, `cannabis`, `alcohol_tobacco`, `firearms`, `regulatory_approval`); regulator names come from the target countries' packs; owner custom block phrases. Lives in `Content/ClaimsFilter.php` plus `Content/ClaimPacks.php` | 7 |
| `assets/js/pages/outreach.js` duplicate claims rules | Removed; the SPA calls `POST /content/check` | 7 |
| `LinkableAssets` 10 store URLs | No defaults; inventory candidates (§7.1) confirmed by the owner; fallback is the home page with the site tagline; `bestFor()` requires `match_score >= 30` and a live, indexable asset (HTTP 200, no noindex), otherwise returns `null` and records a content need | 7 |
| `StorefrontAudit` (RUO block/warn terms, on by default) | `PositioningGate`: data from `pack.positioning.contradiction_terms` plus owner custom rules; not applicable (and its task off) unless a positioning or custom rule exists; reason code `positioning_inconsistent`; neutral copy ("talk to your legal counsel") | 7 |
| `Templates` (8 approaches, Canadian lab supplier wording, `{{research_note}}`) | Neutral library with placeholders `{{business_summary}} {{audience}} {{expertise_topics}} {{market}} {{category}} {{positioning_note}}` (empty unless the pack mandates one); `{{expertise_topics}}` has the restricted source of §2.7; packs may add variants; owner overrides stored in `lga_template_overrides`, validated against the placeholder whitelist, the word limit and the claims filter | 8 |
| `Personalizer` (store wording, `canada` stoplist, Canada observations) | Phrases from profile and pack; market phrase from target countries ("in Canada" only if CA is targeted); topic stoplist = the profile's country and brand words | 8 |
| `Outreach\Compliance` CASL-first, Canadian time zone map, CA/US/UK postal checks | Jurisdiction profiles (§2.6); postal validation from country packs; send time zone = `outreach.timezone`, else WordPress zone, else the region's zone from the pack, else the country's first zone | 8 |
| Autopilot consent text (CASL, RUO, storefront gate) | Generated from active profiles, claim packs and modules; lists exactly what will run | 8 |
| `VerifiedOpportunities` 595-item PPC kit, banned words, fixed path, automatic import | `CatalogImporter` (§2.5): opt-in, kit built from the live profile at import time, banned words and mandatory statement from the pack | 9 |
| `QueryGenerator` fallbacks `laboratory supplies`, `Canada`, brand | Topics from `keywords.target`, pack topics and GSC queries; geos from `Profile::geoModifiers()`; fallbacks are the site categories and the primary country name | 6 |
| `Taxonomy` (`data/keywords.json`, `canadian_signals`) | `Taxonomy::fromProfile()`: core/adjacent from profile and pack, negatives from `keywords-universal.json` minus the pack's `spam_whitelist`, geo group from country packs, `site_type_hints` universal | 6 |
| `RelevanceScorer::geoScore` CA 60-100, US 35, … | Primary target country 100, other target countries 80, same language region 40, international floor 20 (0 and reject when `include_international` is false); unknown country scored on the geo term group | 6 |
| `SiteProfiler::guessCountry` (.ca, Canadian regexes, default CA) | ccTLD map, JSON-LD `addressCountry`, `<html lang>` and hreflang region, phone country codes, country-pack city and postal signals for any target country; no default country | 6 |
| `Url::isCanadianTld`, `MULTI_SUFFIXES` (Canadian) | `Url::countryFromHost()` over `CountryData::CCTLD`; multi-part suffixes from all packs plus a built-in list of common ones (`co.uk`, `com.au`, `co.nz`, …) | 6 |
| `RiskAnalyzer` `misuse_context`, en/fr language rule | `audience_conflict` flag from `pack.misuse_terms` (absent when the pack has none); language rule = `market.languages` | 6 |
| `ScoringEngine` gate message "laboratory research supply", weights 30/20/15/10/10/10/5 | Message uses `industry.label`; spec weights (§3.3) | 20 |
| `Classifier` lab-term boost, `.gc.ca`/provincial host rules | `pack.site_type_boost_terms` plus the universal supplier terms; government host rules from country packs (`second_level_suffixes` tagged government) | 6 |
| `CompetitorBacklinkSource` GIANTS (Canadian media) and HEALTH_TERMS | Global giants list plus the packs' `media_giants`; `pack.excluded_competitor_niches` (empty for most packs) | 6 |
| `PackageBuilder` category terms, provinces, CA/US/GB/AU only, `'CA'` fallback, editorial ask | Category terms from `site.categories` plus `pack.directory_categories`; ISO 3166 names from WooCommerce or `CountryData`; keywords from `keywords.target`; editorial ask from profile and pack phrases; no default country | 8 |
| `InternalLinker::GENERIC` niche words | Universal generic list (navigation and commerce boilerplate in the site language) + `pack.generic_anchor_terms` + owner setting `seo.never_anchor[]` | 6 |
| `SiteAuditor`/`InternalLinker` Rank Math only | `Seo\SeoPluginAdapter` interface with Rank Math, Yoast, AIOSEO, SEOPress, The SEO Framework and none (title, description, robots, focus keywords, cornerstone flags) | 6 |
| `PostLanguage` `ppc_en_counterpart` | Removed; Polylang and WPML kept; optional owner meta key | 1 |
| Hard-coded brand fallbacks in 9 files | `Profile::brand()` | 4a |
| SPA examples and copy (store domain example, CASL checklist, Toronto placeholder, `listing/pure-peptides-canada`) | `example.com` placeholders; jurisdiction checklist rendered from REST; time zone placeholder from the detected zone | 3, 8 |
| `OutreachEngine` RUO dedupe of `{{research_note}}` | Same dedupe keyed on `pack.positioning.pattern`; no-op without a positioning | 8 |
| `data/excluded-domains.json` (store and lab-test domains, Canadian storefronts) | Universal list (social, search, marketplaces, shorteners, hosting, payment, couriers, academic publishers) + country-pack additions + own and owned domains added at runtime | 4a |
| `docs/*` and screenshots | Rewritten (WP29); screenshots regenerated on the test site with the demo brand, labeled "simulated data" | 29 |
| Test harness PPC identity | Demo businesses (§12.2) | 1 |

### 2.5 Bundled data versus optional import

Shipped in `data/`: only the neutral files listed in §1.2. **No opportunity database is imported automatically.**

- **Catalog import** (`Discovery\CatalogImporter`, job `catalog_import`, REST `GET /catalog`, `POST /catalog/import`, Settings > Data). Sources: the shipped `data/catalog/global-platforms.json`, or an owner upload (JSON, max 5 MB, max 5,000 items, validated against `catalog.schema.json`). The owner picks filters: target countries, industries, free tier only, maximum effort, item categories. Matching items become owner-task prospects (source `catalog:<catalog_id>`), in batches of `max(25, ceil(items/8))` per run.
- Catalog item schema: `{id, name, url, submission_url, category, kind: maps|aggregator|employer|event|industry_platform|review_profile|association, countries[], industries[], requires: {physical_address: bool, service_area_ok: bool, real_jobs: bool, real_events: bool}, free_tier: bool, effort: low|medium|high, automatable: false, task: listing|claim_profile, steps[] (templated with {brand} {website} {category} {address} {email} {description}), verification_note, last_verified}`. `automatable` is always false for catalog items in 1.0.0: they become owner tasks, or go through the authorized-form policy (§6.7) only if the owner records an authorization. The importer refuses any item with `automatable: true` (the catalog schema pins the value to `false`).
- The package kit (NAP, categories, description) is built at import time from the live profile and `ContentGenerator`, never stored in the catalog.
- **Inclusion rule** (applies to the shipped catalog and to every owner upload, enforced by `catalog.schema.json` plus `CatalogImporter::eligible()`). A platform is included only when a real business has an independent reason to be listed there, for its customers and not for the link:
  - `maps`: a maps or local listing, only for a verified physical location.
  - `aggregator`: business data aggregators that feed maps and local search.
  - `employer`: employer pages, only for real job openings.
  - `event`: event platforms, only for real events.
  - `industry_platform`: industry-specific platforms where buyers look for suppliers.
  - `association`: professional or trade association member directories.
  - `review_profile`: review and rating platforms.

  **Excluded** by category and by the `never_pursue_site_types` check: document-sharing sites (slide, PDF and document hosts), Web 2.0 and free-blog hosts, social bookmarking, article directories, link directories without editorial review, press-release distribution used for links, generic "company profile" sites, and paid-only items.
- **Filters at import time** (`CatalogImporter::eligible(item, profile)`, each refusal recorded with its reason in the import report):
  - **Industry relevance**: `industries[]` contains `*`, `industry.pack` or a secondary pack. For `industry_platform`, at least one item category also matches `pack.directory_categories` or `site.categories`.
  - **Country**: `countries[]` contains `*` or a target country.
  - **Maps and local** (`kind = maps`, or `requires.physical_address`): a verified `location` fact whose statement contains a street address that parses with the country pack's postal rules, and `site.business_type` is `storefront`, or `service_area` when the item sets `service_area_ok` (the platform supports hidden-address service-area listings). Never for `online_only`. The kit always uses the verified address and never invents a location.
  - **Employer and event**: only when the owner ticks "I have real openings or events to list" in the import dialog. This creates the owner task only; nothing is posted.
  - **Review platforms**: `task = claim_profile` only, an owner task that says "Claim and verify your existing profile; reviews must come from real customers; do not ask for, write or incentivize reviews". The kit contains no review request text. ClaimsFilter blocks review solicitation phrases in every package.
  - **Profile cap**: at most **10** profile-type items (`maps`, `aggregator`, `industry_platform`, `review_profile`) open or prepared at a time per site. Further eligible items wait in the catalog state until one is completed or dismissed, so the result is never a mass profile list.
- **`global-platforms.json`** is produced once in WP9 from the engine's verified data:
  - Keep only items that pass the inclusion rule and are not tied to one country or industry.
  - Strip all brand text from `relevance`, `steps` and `verification_note`, templatize the steps, and tag each item with `kind` and `requires`.
  - Drop every item in an excluded category, every paid-only item, and every item whose only purpose is a profile link.
  - At most 40 items are shipped.

  The script used runs from the scratchpad and is not kept; only the output is committed, after the neutrality scan passes.
- Canadian citation items and lab/science items are **not** shipped as a catalog. The CA pack seeds and the research-supplies pack seeds cover the legitimate national and sector directories. Pack seeds follow the same inclusion rule, which the pack schema validator checks for `category`.

### 2.6 Jurisdiction-aware email compliance

`Outreach\Jurisdictions` holds one profile per regime. The rules that apply to a message are the **union** of the sender's profile (from `site.country`) and the recipient's profile (from the prospect's country, else the ccTLD, else unknown). The consent bases allowed are the **intersection**. This is compliance support, not legal advice; the wizard says so and asks the owner to confirm.

Consent bases recorded per contact (`contacts.consent_basis` and `consent_evidence_url`): `invited` (an address published specifically for submissions, press, partnerships or editorial contact), `conspicuous_publication` (a business address published on the site without a "no unsolicited messages" statement), `existing_relationship`, `express_consent`, `unknown`. ContactExtractor sets `invited` only when the address sits on a submission, write-for-us, press or partnership page.

| Profile | Applies when | First contact allowed with | Every message must contain | Opt-out | Records |
|---|---|---|---|---|---|
| `casl` (Canada) | sender or recipient in CA | `express_consent`, `existing_relationship`, `invited`, `conspicuous_publication` (address published conspicuously, no refusal statement, message relevant to the recipient's business role) | sender name, mailing address, and phone, email or web contact; unsubscribe link | works for at least 60 days; applied within 10 business days (the plugin applies it immediately) | basis, evidence URL, date |
| `can_spam` (US) | recipient in US | any basis except `unknown` (opt-out regime; the plugin still requires a published business address) | accurate From/Reply-To, non-deceptive subject, valid postal address, clear opt-out | works for at least 30 days; applied within 10 business days (immediately in practice) | opt-outs kept permanently in `lga_suppression` |
| `uk_pecr` (GB) | recipient in GB | corporate subscribers (companies, LLPs, public bodies) with `invited` or `conspicuous_publication`; individual subscribers (sole traders, some partnerships, any personal address) only with `express_consent`; a legitimate-interests note is stored per campaign for named contacts | identity, contact address, opt-out, link to a privacy notice (UK GDPR Art. 14 information) | applied immediately | basis plus the campaign's legitimate-interests note |
| `eu_eprivacy` (EU/EEA, list of ISO codes in code) | recipient in EU/EEA | `invited` or `express_consent` only; DE, AT and countries flagged `consent_only` in the profile: `express_consent` or `invited` only, and the UI warns that unsolicited B2B email is generally not permitted there | identity, address, opt-out, privacy notice link (GDPR Art. 13/14) | applied immediately | basis, evidence |
| `au_spam` (AU) | recipient in AU | `express_consent`, `invited`, inferred consent by `conspicuous_publication` relevant to the role | sender identification, functional unsubscribe | works for at least 30 days; applied within 5 business days | basis, evidence |
| `nz_uema` (NZ) | recipient in NZ | `express_consent`, `invited`, deemed consent by `conspicuous_publication` | identification, unsubscribe | applied within 5 working days | basis, evidence |
| `strict` (fallback) | any other or unknown country | `invited` or `express_consent` only | identity, address, opt-out, privacy notice link | immediately | basis, evidence |

Each profile is a PHP array `{id, label, consent_bases[], footer_required[], optout_days, privacy_notice_required, b2b_rule, notes}`. `Compliance::check()` returns blocking problems (missing footer element, disallowed basis, missing privacy notice URL `outreach.privacy_url`) that stop the draft from being approvable. The send-time zone uses the recipient's region zone when known.

### 2.7 Outreach and acquisition policy changes from the engine

**Autopilot, method by method** (`Automation\Autopilot`, rewritten in WP4b). In the engine, `isOn()` raised the submission mode to auto-by-score, raised the module flags `submissions`, `outreach_autosend` and `auto_outreach_drafts`, turned off approval, and auto-applied links. None of that survives. The rule is that Autopilot only lets the scheduler **start** work that the owner has already allowed. It never widens what is allowed.

| Method (engine) | New meaning in the fork |
|---|---|
| `isOn()` | `automation.enabled && automation.autopilot && setup.completed`. It changes no other value. Callers that used `isOn()` to widen a gate (22 call sites in the engine, found with `grep -rn "isOn()" includes`) are rewritten to call `allows()` |
| `allows(string $consent): bool` (new) | `isOn()` and `Consents::has($consent)`, where `$consent` is one of `submissions`, `distribution`, `resource_publish`, `internal_links`, `follow_ups`. This is the only Autopilot method the scheduler uses for tasks marked `autopilot = true` (§5.1) |
| `submissionMode()` | Returns the stored `automation.submission_mode` (`authorized_only` or `manual`); any other stored value reads as `authorized_only`. `Policy::MODE_AUTO` and `'auto'` are deleted from `Policy::SUBMISSION_MODES`. Autopilot never changes the mode |
| `submissionsEnabled()` | `automation.modules.submissions && Consents::has('submissions') && setup.completed`. Autopilot does not raise it |
| `flag(string $module)` | Returns `automation.modules.<module>` and nothing more. `RAISED_FLAGS` is deleted. For an acquisition module (`submissions`, `distribution`) it returns false while that module's consent is missing, so a flag is never effective past its consent |
| `requireApproval()` | Always `true` for first contacts; the stored `outreach.require_approval` is ignored (§2.2). `requireApprovalForFollowUp(array $msg)` (new) is false only under the follow-up autosend rule below |
| `outreachAutosend(array $message)` | True only for a message with status `approved`, `approved_by > 0` (a real user, never `system:*`) and `approved_at` set, or for a follow-up that passes the autosend rule. These messages go out in the next send window without another click. Messages approved by no one are never sent |
| `autoApproveMessages()`, `autoApproveAutoCampaigns()`, `autoApproveOwnerCampaigns()` | **Deleted**, together with `CampaignFiller::approveWaiting()`, `CampaignFiller::approveDrafts()`, `AutomationController::approveWaitingNow()` and its two calls in `SettingsController`. No code path lets a non-user actor set a first contact to `approved`. `Repositories\OutreachMessages::approve()` requires `int $userId > 0` and `sequence = 0` messages are refused when the caller is the scheduler |
| `autoDrafts()` | `automation.modules.outreach_drafts && setup.profile_ready`. Drafting is not outbound contact, so it needs no consent and no Autopilot |
| `autoApplyLinks()` | `seo.auto_apply_links && Consents::has('internal_links')`. The scheduler applies links unattended only when `allows('internal_links')` holds, and a person can apply them from the SEO page at any time. The engine's separate `ppclg_auto_links_consent` option becomes the `internal_links` entry of `lga_autopilot_consent` |
| `linksPerDay()` | `seo.auto_apply_per_day` (1-50, default 10) |
| `autoDetectCompetitors()` | `automation.modules.discovery && setup.profile_ready && discovery.competitor_domains` empty `&&` a `CompetitorSource` provider connected. It no longer depends on Autopilot. Results go to `discovery.competitor_domains_auto`, shown in Settings > Keywords with "Remove" |
| `replyTracking()` | Unchanged: the inbound webhook secret exists |
| `autoFollowUp(array $campaignSettings)` | Replaced by `followUpAutosend(array $followUp): bool`, the follow-up autosend rule below |
| `followUpNote()`, `policyText()`, `hardLimits()`, `willRun()`, `status()`, `numbers()`, `ownerTasksNote()` | Generated from the recorded consents, active modules, claim packs and jurisdiction profiles. They list exactly what will run unattended and what still needs a person (Appendix B). No text says that Autopilot approves or sends outreach |
| `enable(int $userId, array $consents)` / `disable(int $userId)` | `enable` needs `confirm: true` and `setup.completed`, records each consent passed (who, when, policy version, SHA-256 of the text shown) and approves nothing. `disable` is renamed from `revertAutoApproved`: queued follow-ups whose `approved_by` is null return to `awaiting_approval`. Queued submissions and distributions stay queued but are not started by the scheduler while Autopilot is off |

Test: U `AutopilotPolicyTest` (WP4b) covers every row with all combinations of Autopilot, consents and module flags. I `autopilot-gates` (WP8) turns Autopilot on with every consent recorded and every module on, then runs the scheduler for 20 ticks and one Grow now. The assertions:
- `submission_mode` is still `authorized_only`.
- Every first-contact draft is in `awaiting_approval`.
- The fixture mail log has 0 sent messages that were not approved by a user.
- No form POST went to a destination without an authorization record.
- The internal-link applier ran only after `consent.internal_links` was recorded.

**First-contact approval.** With `automation.modules.outreach_drafts` on, the engine prepares personalized drafts. The drafting cap per run is the remaining `outreach_drafts` daily budget (`Limits::remaining('outreach_drafts')`, at most 10 by default); the engine's `MAX_DRAFTS_PER_RUN = 40` is deleted. A person approves each first contact, one per request:
- `GET /outreach/messages/{id}/preview` returns the rendered subject and body exactly as they will be sent, the footer, the jurisdiction profile(s) and `Compliance::check()` result, the consent basis with its evidence URL, and a `preview_token`. The token is single use, bound to the user, the message id and the body hash, and expires after 30 minutes.
- `POST /outreach/messages/{id}/approve` needs that `preview_token`. It is refused with `lga_preview_required` without a matching token, with `lga_compliance_blocked` while the check has blocking problems, and with `lga_message_changed` when the body hash changed since the preview.
- No route approves more than one first contact per request. `approve-drafts` is removed, and campaign `approve` only activates a campaign for drafting and approves no message.

The evergreen "Auto:" campaigns are kept as **drafting containers only**, renamed "Prepared by LinkGrowth". `CampaignFiller::fillAutoCampaigns()` and `fillOwnerCampaigns()` fill them with drafts within the drafting cap, and every draft inside still needs its own preview and approval. Approved messages are sent by `outreach_send` inside the send window and limits. Test: I `duplicates` gains the case "approve route count". It walks every `lga/v1` route that accepts POST and has `approve` in its path or action enum, calls each one with a request naming two first-contact drafts (ids array, campaign id, or `all`), and asserts that at most one message changed to `approved`.

**Follow-ups** (`Outreach\FollowUps`):
- **Cap**: `outreach.max_follow_ups` is 0 or 1 (default 1), so there is at most one follow-up per first contact. The earliest send is `outreach.follow_up_days` (default 7) after the first contact was sent, and the follow-up must be sent within 30 days of the first contact; after that the contact becomes `no_response`.
- **Drafted only** when the first contact was approved by a person and sent, and reply tracking is configured.
- **Stop conditions**, checked when drafting **and again immediately before sending**: any reply (inbound webhook match, `log-reply`, or a reply recorded on any message to the same contact or domain); a bounce; an unsubscribe; a complaint; a suppression hit on the email, its hash or the domain (including imported lists, §2.8); contact `consent_basis = unknown`; the recipient's jurisdiction check now failing; the opportunity no longer `contacted` (declined, dismissed, live_verified, expired); the prospect now owned or suggested-owned; or the outreach arm `paused_risk` (§8.5). A hit cancels the draft with the reason.
- **Autosend rule** (`followUpAutosend`): `allows('follow_ups')`, `outreach.autosend_followups`, `replyTracking()`, the first contact approved by a person, no stop condition, and the `outreach_emails` limit has room. Otherwise the follow-up waits for its own preview and approval like a first contact.
- Test: U `FollowUpsTest` covers each stop condition (drafting and pre-send), the cap of 1, the 30-day window, unknown basis, autosend with and without each precondition, and a follow-up after a reply on a different message to the same domain.

**Expertise placeholder.** `{{expertise_topics}}` is filled only from verified `expertise` or `differentiators` facts (`lga_facts`, `verified = 1`) or from the owner-entered `outreach.expertise_topics[]`. It is never filled from pack topics, keywords, GSC queries or AI output. When both sources are empty:
- the `expert_commentary` and `podcast` approaches are unavailable (the template library omits them and `Personalizer` refuses them with `lga_no_expertise`);
- opportunities of those types get `acquisition_method = owner_task` with the note "Add the topics you can speak on (Settings > Outreach) to draft a pitch".

Test: U `TemplatesExpertiseTest` (WP8). With no facts and an empty list, those two approaches are absent and pack topics never appear in a rendered pitch. With one verified fact, its statement appears. An unverified fact is ignored.

**Grow now** (`Growth\GrowthRun`, reworked in WP10, UI in WP28; it is not reused unchanged). The engine's fixed `STEPS` list, its `Pipeline::stages()` coupling and its Autopilot labels are removed:
- **Steps**: `GrowthRun::plan()` builds the steps from `TaskRegistry`, ordered by priority, including every task that is enabled and passes its gates, plus the internal `preflight` and `summary` steps.
- **Gates**: each step calls the same `TaskRegistry::gate($task, Context::manual())` as the tick: master switch, module flag, `requiresProfile`, `requiresSetup`, consent, LoadGuard, limit pool and decision budget. The only difference from the tick is that due-ness and frequency are ignored, and that `autopilot = true` tasks may run with Autopilot off, because a person started the run. A blocked step shows its reason code.
- **Outreach step** label: "Draft outreach for your approval". It never approves. The summary lists the drafts waiting for approval with a link to the Outreach page.
- **Preflight** label: "Check setup and positioning". It uses the `PositioningGate` wording and is skipped when no positioning rule exists. The `import_verified` step is removed; the `catalog_import` task appears only while an owner-started import has pending items.

Test: I `growth-autopilot` (WP10). On the Northwind profile with Autopilot on and every consent recorded, a full Grow now run with fixture contacts must end with at least one outreach draft, all drafts in `awaiting_approval`, and 0 sent messages. A second case with Autopilot off gives the same outreach result and runs `submit` only for destinations with an authorization record.
- **Submission mode defaults to `authorized_only`** (§6.7). Auto-by-score is gone, and no switch can raise it.
- **Sender alignment**: `outreach.sender_email` must be on `site.domain` or a domain listed in `outreach.sending_domains[]`; templates cannot contain invented staff names (the placeholder whitelist has no persona fields); the signature must contain the configured sender name.
- **Never-automate classes** (refused by `Submission\Policy` and `Distribution\Policy`, with the reason shown): paid-only listings, account or login required, reciprocal link required, email or phone verification required (`needs_owner_verification`), CAPTCHA, comment or forum forms, bookmarking and link-dump sites, review platforms (reviews must come from customers), terms that forbid automated submissions.

### 2.8 Owned sites and cross-site contact history (spec §11)

**Owned-site detection** (`Profile\OwnedSites`). `Profile::ownedDomains()` returns `site.owned_domains[]` plus every **suggested** owned domain the owner has not rejected. A suggestion counts as owned from the moment it is found, so detection can only narrow what the engine does. `Profile::suggestedOwnedDomains(): list<array{domain, sources[], first_seen, status}>` comes from these sources, each used only when that provider is connected (WP6 builds the class and the fingerprint and RDAP sources, WP15 and WP16 add their providers):

| Source | How | Package |
|---|---|---|
| Google Search Console | `GET https://www.googleapis.com/webmasters/v3/sites` (every property the service account can see, `siteUrl` hosts) | WP16 |
| Bing Webmaster Tools | `GetUserSites` | WP15 |
| GA4 | Admin API `GET https://analyticsadmin.googleapis.com/v1beta/accountSummaries`, then `properties/{id}/dataStreams` `webStreamData.defaultUri` for each property of the same account (scope `analytics.readonly`) | WP16 |
| Hosting fingerprint | the same nameserver-set hash **and** the same IP-set hash (`lga_domain_fingerprints.nameservers_hash`, `ips_hash`) as `site.domain`, for any domain the engine has fingerprinted (prospects, backlink sources). Shared nameservers of large DNS providers alone never match: both hashes must be equal, and the nameserver set must not be on the built-in list of shared DNS providers | WP6 |
| RDAP registrant | the same non-empty registrant organization as `site.domain`, ignoring redacted values and privacy-service names (built-in list plus `lga_rdap_privacy_orgs` filter) | WP6 |

Suggestions are stored in option `lga_owned_suggestions` (not autoloaded, at most 200). Each one raises `owned_site_suggested` once and appears in wizard step 2 and in Settings > Exclusions with "Confirm as mine" (moves it to `site.owned_domains`) and "Not mine" (status `rejected`, kept so it is not suggested again).

**What "owned" means everywhere:**
- `DomainFilter` and `ProspectIntake` refuse owned and suggested-owned hosts (and their subdomains) as prospects, with reason `owned_site`.
- `ContactExtractor` stores no contacts for them, and Outreach refuses them.
- `Submission\Policy` refuses them as targets.
- Their backlinks to us are stored with `is_owned = 1` and excluded from KPIs and the decision engine.
- A domain that becomes owned after a prospect was created has its open opportunities closed as `dismissed` with reason `owned_site`.

**Distribution between owned sites** (`Distribution\Policy`, channel `owned`). It is never suggested by the engine. The owner may create one with all of these:
- manual approval and a written justification of at least 40 characters;
- at most **one** owned-channel placement per resource, across all owned targets;
- the target site's home page and topic pages must pass `RelevanceScorer` against the resource topic with a site relevance sub-score of at least 40, shown in the approval dialog;
- **no reciprocity**: syndication or owned-channel placement to a target is refused with `lga_reciprocal_syndication` when that target has syndicated to us. The receiver side records every accepted source host in option `lga_syndication_sources` (`{host: {first_at, last_at, count}}`, at most 500) and returns the same list to authenticated callers.

These placements are excluded from KPIs and audited.

Test: I `owned-sites` (WP6). Owned and suggested-owned domains (from a fingerprint match and from a fixture GSC sites list) are fed through discovery, contact extraction, the outreach drafter and the submission policy, and never become prospects, contacts or targets. A rejected suggestion becomes eligible again. A second owned-channel placement for one resource is refused, and so is syndication to a host in `lga_syndication_sources`.

**Contact history across the owner's sites.** Each install keeps its own suppression list and contact history, so the owner can carry them between sites:
- `GET /suppression/export` returns CSV `type,value,reason,source,created_at`. Emails are exported as `email_hash` (SHA-256 of the lower-cased address), and domains as `domain` or `contacted_domain`.
- `POST /suppression/import` (CSV, at most 1 MB and 20,000 rows, `confirm: true`) adds rows with `source = import:<label>`. `email_hash` and `domain` rows suppress. `contacted_domain` rows ("contacted by my other sites") set `expires_at = now + outreach.cross_site_cooldown_days`: while active, prospects on that domain are not drafted for outreach and show "contacted from another of your sites".

CONFIGURATION.md explains running both on each site and listing every owned site in `site.owned_domains`. Test: U `SuppressionImportTest` (round trip, hashing, cool-down expiry, malformed rows refused).

---

## 3. Module map

### 3.1 The module seam

To let packages be built in parallel without editing the same wiring files, WP2 converts the engine's central wiring into modules. Each module is one class implementing:

```php
namespace LinkGrowth\Autopilot\Core;

interface Module {
    public function id(): string;                                   // 'discovery'
    public function services( Container $c ): void;                 // DI registrations
    public function boot( Plugin $plugin ): void;                   // hooks (cheap; front-end safe)
    /** @return array<string,string> table name (without prefix) => CREATE TABLE statement */
    public function tables(): array;
    /** @return array<string,mixed> settings section => defaults */
    public function settingsDefaults(): array;
    /** @return array<string,array<string,mixed>> dot.path => validation rule */
    public function settingsRules(): array;
    /** @return array<string,class-string> job type => JobInterface class */
    public function jobs(): array;
    /** @return list<\LinkGrowth\Autopilot\Automation\TaskDefinition> */
    public function tasks(): array;
    /** @return list<class-string> REST controllers */
    public function restControllers(): array;
    /** @return array<string,array{label:string,icon:string,module:string,cap:string,order:int}> SPA pages */
    public function adminPages(): array;
    /** @return array<string,array{label:string,module:string,order:int}> Settings tabs (JS module paths) */
    public function settingsTabs(): array;
    /** @return list<class-string> Health\Check implementations */
    public function healthChecks(): array;
    /** @return array<string,class-string> WP-CLI subcommand => class */
    public function cliCommands(): array;
}
```

`Core\AbstractModule` returns empty arrays for everything. `Core\Modules::LIST` is the ordered list of module classes; it is the **only** file every parallel package touches (one line each). `Plugin`, `Database\Schema`, `Support\Settings`, `SettingsSchema`, `JobRunner::types()`, `Automation\TaskRegistry`, `Admin\Rest\RestApi`, `Admin\Menu`, `Health\HealthMonitor` and `Cli\CommandRegistry` collect from the modules. The SPA builds its router and Settings tabs from `lga-config.pages` and `lga-config.settingsTabs` that `Admin\Menu` prints (§10.1).

Filters for extensions: `lga_modules`, `lga_job_types`, `lga_scheduled_tasks`, `lga_rest_controllers`, `lga_admin_pages`, plus the provider and adapter filters of §6.1 (`lga_providers`, `lga_metrics_providers`, `lga_search_providers`, `lga_backlink_sources`, `lga_acquisition_adapters`, `lga_distribution_adapters`), `lga_industry_packs`, `lga_country_packs`, `lga_indexnow_implementations` and `lga_known_conflicts`. Every filter is listed with its signature in the developer section of `docs/PROVIDERS.md`.

### 3.2 Modules and responsibilities (spec §14 list)

| Spec §14 area | Module class / namespace | Responsibilities | Reused (from the engine) | New |
|---|---|---|---|---|
| Bootstrap | `linkgrowth-autopilot.php`, `Plugin`, `Autoloader`, `Container`, `Activator`, `Deactivator`, `uninstall.php`, `Core\{Module, AbstractModule, Modules, Environment}` | Constants and PHP/WP guard; module loading; activation (tables, defaults, caps, schedule) per site and on network activation; `wp_initialize_site` for new sites; WooCommerce feature declarations; coexistence check | R: autoloader, container, activator, deactivator, uninstall (X: per-site uninstall loop with `get_sites` batches of 100) | N: module seam, `Environment::report()`, `Environment::conflicts()`, `before_woocommerce_init` `FeaturesUtil::declare_compatibility('custom_order_tables', LGA_FILE, true)` and `'cart_checkout_blocks'` |
| Autopilot scheduler | `Automation\{Scheduler, Pipeline, Autopilot, LoadGuard, TaskRegistry, TaskDefinition, Frequency, RunnerMode}`, `Growth\GrowthRun`, `Runner\ServerCron` | Ticks, per-task schedules, due logic, runner modes, Autopilot consents and hard limits, "Run now" growth runs, server pressure pauses, runner log | R: LoadGuard; X: Scheduler (runner modes, heartbeat-based fallback, runner log), Pipeline (`stages()` replaced by TaskRegistry), Autopilot (every method redefined, §2.7: it only lets the scheduler start consented tasks), GrowthRun (steps and gates from TaskRegistry, no approval path, PositioningGate wording, §2.7; WP10 and WP28) | N: TaskRegistry, Frequency, ServerCron, RunnerMode, `Automation\Consents` (the consent record) |
| Background jobs | `Automation\{JobRunner, Jobs\*, ErrorClass, FailedJobRecovery, SelfHeal}`, `Repositories\Jobs`, `Support\{Locks, TimeBudget, Backoff}` | Queue, claim, priorities, dedupe keys, retries by error class, backoff, requeue of recoverable failures, dead-letter view | R: Locks, TimeBudget, AbstractJob; X: Jobs repository (`active_key` dedupe, error class), JobRunner (per-type max attempts, redacted errors) | N: Backoff, ErrorClass, FailedJobRecovery, SelfHeal; new job types listed in §5.1 |
| Discovery | `Discovery\*` | Query generation from profile, pack seeds, classification and subtypes, broken links, mentions, competitor backlinks, competitor intersect, citation topics, catalog import, prospect intake | G: DiscoveryEngine, QueryGenerator, Classifier, BrokenLinkFinder, MentionFinder, CompetitorBacklinkSource, DomainFilter, ProspectIntake; SeedCatalog becomes PackSeeds; VerifiedOpportunities becomes CatalogImporter | N: `CompetitorIntersect` (sites linking to 2+ competitors and not to us), `CitationTopics` (competitor target pages grouped by topic), `Expansion` (decision-engine triggered discovery, §8.6) |
| Scoring (and analysis) | `Scoring\*`, `Analysis\*` | Page/site analysis, relevance, quality, risk, eligibility, terms, per-opportunity 0-100 score with explanations | G: PageAnalyzer, SiteProfiler, RelevanceScorer, QualitySignals, RiskAnalyzer, FormDetector, SubmissionPageFinder, ContactExtractor, Taxonomy; X: ScoringEngine (v2 components) | N: `Scoring\OpportunityScorer`, `Scoring\Explainer`, `Analysis\LegitimacySignals`, `Analysis\OutboundQuality`, `Analysis\EligibilityDetector` (free/paid, account, reciprocal, verification, region/category restrictions, expiry), `Analysis\TermsDetector` |
| Provider integrations | `Integrations\*` | Every external data, search, analytics, URL-submission and AI provider behind one contract with state, limits, circuit breaker and activity log | X: SeRanking, Ahrefs, Semrush, Moz (+ links), Google SearchConsole (+ dimensions, URL inspection), Analytics4 (+ key events, revenue, landing pages), SiteKitBridge, BraveSearch, GoogleCustomSearch (labeled legacy), Rdap, IndexNow (moved under the contract), AiWriter, MetricsAggregator, ProviderRegistry | N: `Integrations\Framework\{Provider, ProviderGateway, RateLimiter, CircuitBreaker, ErrorClassifier, ProviderStateRepository, ActivityLog, CredentialField, ConnectionResult, OAuthClient}`, `Integrations\Bing\WebmasterTools`, `Integrations\Providers\Majestic`, `Integrations\Woo\OrderAttribution`, `Integrations\Wayback` (optional, titles of dead pages) |
| Acquisition workflows | `Submission\*`, `Outreach\*`, `Distribution\*` | Authorized directory and API submissions, owner-task packages, outreach drafts with jurisdiction checks and per-message approval, distribution, syndication and owned-channel publishing, authorizations, success state machine | X: SubmissionEngine, Policy (authorized_only, authorizations, never-automate classes, owned targets refused), PackageBuilder (G), AdapterRegistry (filter `lga_acquisition_adapters`), adapters (contract v2), ListingVerifier, FollowUps (cap and stop conditions, §2.7); G: OutreachEngine, Templates, Personalizer, Compliance, Mailer, CampaignFiller (drafting only: `approveWaiting` and `approveDrafts` deleted), Unsubscribe, InboundWebhook | N: `Submission\Authorizations`, `Outreach\Jurisdictions`, `Distribution\{DistributionEngine, Policy, Queue, Verifier, Adapters\{Mastodon, Bluesky, LinkedIn, FacebookPages, Webhook, WordPressSyndication}, Receiver\SyndicationReceiver}` |
| Backlink verification | `Monitoring\*` | Periodic checks, change detection, alerts, history, ownership and content drift, destination check, recommendations, replacement tasks | X: BacklinkMonitor, BacklinkImporter, Notifier | N: `Monitoring\{DestinationChecker, RedirectTracer, CheckHistory, OwnershipWatcher, ContentDrift, Recommendations, RecoveryPlanner}` |
| Analytics | `Analytics\*` | Daily snapshots of every metric, attribution, KPI series, chart data | none (the engine queries live) | N: `Analytics\{Snapshotter, Sources\{Internal, Ga4, Gsc, Bing, Woo}, Series, Kpis, Attribution}` |
| Decision engine | `Decision\*` | Outcome ledger, arm statistics, priorities, budget allocation, stop rule, expansions, decision log | none | N: `Decision\{Ledger, StrategyKey, StatsBuilder, Scorer, Allocator, Budget, StopRule, Expansion, DecisionLog}` |
| Content | `Content\*`, `Resources\*` | Verified facts, claims, descriptions, linkable assets, inventory, gap analysis, resource builders, quality/accuracy/originality/compliance checks, publish gate, register, calculators, maintenance | G: ContentGenerator, ClaimsFilter, LinkableAssets; X: FactsRegistry (table) | N: `Content\ClaimPacks`, `Resources\{Inventory, GapAnalyzer, Planner, Builders\*, Checks\*, PublishGate, Register, Maintenance, Calculator\{Block, Shortcode, Expression}, Dataset\CsvImporter, Infographic\SvgChart}` |
| On-site SEO | `Seo\*` | IndexNow, internal links with render check, site audit, SEO-plugin adapters | R: IndexNow (under the provider contract), RenderCheck, BrandTerms; G: InternalLinker, SiteAuditor, PostLanguage | N: `Seo\SeoPlugin\{Adapter, RankMath, Yoast, Aioseo, Seopress, Tsf, None}` |
| Compliance | `Compliance\PositioningGate` | Optional positioning consistency gate driven by the pack | G: from StorefrontAudit | |
| Dashboard | `Admin\*`, `Admin\Rest\*`, `assets/js/**`, `assets/css/admin.css` | Menu, SPA shell, pages, REST controllers, notices, Site Health, setup wizard | X: Menu, Assets, Notices, SiteHealth, every existing controller, `app.js`, `ui.js`, `charts.js`, pages | N: pages `opportunities`, `acquisition`, `resources`, `analytics`, `strategy`, `providers`, `setup`; controllers listed in §10 |
| Database | `Database\{Schema, Migrator, Backup}`, `Repositories\*` | Module-collected schema, versioned migrations with backup, repositories | X: Schema (collects from modules), Migrator (versioned steps); R: Backup, all repositories | N: repositories for every new table (§4.3) |
| Security | `Security\*`, `Support\{Crypto, Secrets, Redactor}` | SSRF guard with IP pinning, cron token, OAuth state, HMAC signing, REST permission map, redaction | X: Crypto, Secrets (per-blog constants, undecryptable detection), Redactor (URL query keys) | N: `Security\{UrlGuard, CronToken, OAuthState, RequestSigner, PermissionMap}` |
| Logging | `Support\Logger`, `Repositories\{Logs, AuditLog, Notifications}`, `Automation\Audit`, `Monitoring\Notifier` | Logs, audit trail, notifications with per-event preferences and dedupe | X: redaction of URLs and job errors, notification `event` and `dedupe_key`, per-event toggles | |
| Settings | `Support\{Settings, SettingsSchema}`, `Profile\{Profile, PackRepository, CountryData, OwnedSites}`, `Admin\Rest\SettingsController` | Defaults from modules, validation from the schema, profile view, packs, owned-site detection (§2.8) | G: Settings; X: SettingsController (schema-driven, refuses `consent.*`) | N: SettingsSchema, Profile, PackRepository, CountryData, OwnedSites |
| Privacy | `Privacy\{Exporter, Eraser, Retention, PolicyText}` | WordPress personal-data exporters and erasers, contact retention, privacy policy text (§11.9) | | N |
| Health | `Health\{HealthMonitor, Check, Checks\*, SiteHealthTests, Diagnostics}` | Runner, queue, providers, DB, limits, secrets and environment checks; Site Health; notify on state change; redacted diagnostics | R: Admin\SiteHealth OPcache test (moved here) | N: all others |
| CLI | `Cli\{CommandRegistry, LinkGrowthCommand}` | `wp linkgrowth …` | | N |

### 3.3 Scoring v2 (spec §6)

Scores are computed **per opportunity** (the page where our link would live) and combine site-level signals (cached on the prospect for 30 days) with page-level signals. `Scoring\OpportunityScorer::score(array $prospect, array $opportunity, PageAnalysis $page, array $context): ScoreResult`.

| Component (key) | Default weight | Inputs (sub-score 0-100 = weighted mean of the known inputs) |
|---|---|---|
| `topical_relevance` | 25 | Source-page relevance 60 % (title, headings, body and the topics of nearby outbound links scored by `RelevanceScorer` against `Taxonomy::fromProfile()`); site relevance 40 % (homepage and profile pages); multiplied by the geo factor (§2.4 geo score / 100, floor 0.2) |
| `site_quality_legitimacy` | 20 | Business identity (Organization/LocalBusiness schema, address and phone on site) 20; trust pages (about, contact, privacy) 20; domain age from RDAP 15; HTTPS 10; maintenance (share of sampled outbound links alive, last-updated date) 15; not parked or thin 10; third-party authority 10, used only when known and labeled "third-party estimate (not a Google metric)" |
| `source_page_quality` | 15 | Content depth, freshness (date within 3 years), outbound link quality (`OutboundQuality`: share of links to risky TLDs, excluded or spam-flagged domains, number of outbound links, follow ratio, relevance of destinations), dead outbound ratio, ad density |
| `audience_traffic_evidence` | 15 | Provider organic traffic or keywords (log scale), audience signals (newsletter, comments, active social profiles, recent posts), GA4 referral sessions from that domain in the last 90 days. If none is known, the component is **excluded** (known = false) and the total is renormalized over the known weights; no placeholder value is used |
| `editorial_context` | 10 | Expected placement in body copy (by opportunity type and page structure), surrounding relevant text, evidence of editorial review (guidelines page, "submissions are reviewed"), bylines and dates, absence of "sponsored post", "link insertion" or "guest post price" markers |
| `link_accessibility_permanence` | 10 | Indexable (meta robots, X-Robots-Tag), self-canonical, robots.txt allows Googlebot and Bingbot for the page, reachable without login or JavaScript, linked from the site (sitemap or internal links), expected rel of our link (follow 100, ugc and nofollow 40, sponsored 30: nofollow links still bring referral traffic), listing expiry or renewal, historical reliability of the domain (share of earlier links still live, approval rate of earlier submissions) |
| `risk` | 5 | 100 minus the RiskAnalyzer score (parked, spam terms, link selling, PBN footprint, hidden links, hacked content, thin, noindex, ad-heavy, risky TLD, young domain, audience conflict) |

- **Uniqueness modifier** (spec "referring-domain uniqueness"): ×1.0 for a new referring domain, ×0.6 when the domain already has a live link to us, ×0.8 when only lost links exist. Shown as a separate line in the explanation. A domain with an acquisition already in progress is kept but not queued again.
- **Indexed status** is a separate field, never derived from reachability. It stays `unknown` for third-party pages (no authorized source reports index status for pages we do not own). For our own target pages, GSC URL Inspection can set it, with the source and date.
- **Hard gates** (score capped at 40 and status `rejected` with the gate reason): any hard risk flag; topical relevance below 12 ("No topical relevance to {industry.label}"); unreachable; site type in `pack.never_pursue_site_types`; paid placement that would be followed (§6.7).
- **Confidence** = sum of the known components' weights. Below `automation.min_confidence_auto` (60) the opportunity is `needs_evidence` and never acquired automatically.
- **Thresholds and bands** (`Scoring\Bands`, one class so the table, the drawer and the tests agree):

  | Field or band | Rule |
  |---|---|
  | `opportunities.relevance` (the Opportunities "relevance score" column) | the `topical_relevance` sub-score (0-100) before weighting, geo factor included |
  | `opportunities.score` (the "quality score" column) | the weighted total after modifiers and gates |
  | `risk_rating` | from the RiskAnalyzer score r (0-100): `low` when r < 30, `medium` when 30 ≤ r < 70, `high` when r ≥ 70. With the default `max_risk_auto_reject = 70`, `high` means auto-rejected; the band edges move with that setting (`high` = r ≥ `max_risk_auto_reject`, `medium` = 30 ≤ r < it) |
  | score < `min_score_keep` (50) | status `rejected`, `reject_reason = low_score`; the full explanation stays in `score_breakdown`. The row stays visible under the Rejected filter, can be reopened by the owner (`POST /opportunities/{id}/status`), and is rescored after a weight change |
  | `min_score_keep` ≤ score < `min_score_auto_queue` (50-69) | stays `open`; shown and actionable by a person, never queued automatically |
  | score ≥ `min_score_auto_queue` (70) and confidence ≥ `min_confidence_auto` | eligible for automatic packaging and drafting; acquisition itself still needs the gates of §2.7 and §6.7 |
  | risk score ≥ `max_risk_auto_reject` | status `rejected`, `reject_reason = high_risk`, whatever the score |

  The `opportunities.reject_reason varchar(30) NOT NULL DEFAULT ''` column is added (§4.2). Tests: U `ScoringBandsTest` (WP20) covers every edge (29/30, 69/70, 49/50 and so on), the moving edge when `max_risk_auto_reject` changes, the explanation kept on a low-score reject, and reopening.
- **Explanation** (`Scoring\Explainer`): for each component `{key, label, weight, score, weighted, known, evidence[], text}`, where `text` is one plain sentence ("The page is a curated list of 42 laboratory resources; 9 of the 12 sampled links still work"). Plus `modifiers[]`, `gates[]`, `confidence`, `class` (excellent >= 80, good 65-79, fair 50-64, poor < 50) and `scored_at`. Stored in `opportunities.score_breakdown` and returned by `GET /opportunities/{id}`.
- Weights come from `scoring.weights` (validated to sum to 100). Changing weights queues a `rescore` job for open opportunities (batches of 200).

### 3.4 Opportunity types, lifecycle and attribution (spec §3A-E, §4)

**Type enum** (`Discovery\OpportunityTypes`). `Repositories\Opportunities::insert()` and `update()` refuse a `type`, `subtype` or `acquisition_method` that is not in this table (`InvalidArgumentException`, logged as `lga_unknown_type`). Extensions add types through `lga_opportunity_types` with the same structure. Spec §3D (competitor intelligence) is not a type: any type can come from it, recorded as `discovered_via = competitor:<provider>` plus `competitor_count`.

| Type | Spec | Subtypes | Allowed `acquisition_method` |
|---|---|---|---|
| `directory_listing` | §3A | from `pack.directory_subtypes` (`industry`, `business`, `professional`, `niche`, `supplier`, `resource`, `educational`, `regional_business`, `association`) plus `local_citation` (spec §1.3 "citation opportunities": NAP listings in local or regional directories, under the maps/local rule of §2.5) | `official_api`, `authorized_form`, `owner_task` |
| `supplier_listing` | §3A | `manufacturer_dealer_list`, `distributor_list`, `marketplace_profile` | `official_api`, `authorized_form`, `editorial_outreach`, `owner_task` |
| `membership` | §3A | `trade_association`, `chamber`, `professional_body` | `owner_task` (joining is a business decision) |
| `business_listing` | §4 "approved business listing integrations" | `maps`, `aggregator`, `employer`, `event`, `industry_platform`, `review_profile` (catalog `kind`, §2.5) | `official_api`, `owner_task` |
| `resource_page` | §3B | `curated_links`, `tools`, `educational_reference`, `supplier_resources` | `resource_suggestion`, `owner_task` |
| `broken_link` | §3C | `resource_page`, `article`, `directory` | `resource_suggestion`, `owner_task` |
| `editorial` | §3E | `contribution`, `original_research`, `news_tip`, `reference_update` | `editorial_outreach`, `official_api` (a publication's partner API), `owner_task` |
| `expert_commentary` | §3E | `journalist_request`, `roundup`, `interview` | `editorial_outreach` only with expertise (§2.7), else `owner_task` |
| `educational_contribution` | §3E | `course_resource`, `library_guide`, `workshop` | `editorial_outreach`, `owner_task` |
| `podcast` | §3E | `guest`, `sponsor` (sponsor needs `distribution.paid_budget > 0` and `rel="sponsored"`) | `editorial_outreach` only with expertise, else `owner_task` |
| `partnership` | §3E | `supplier`, `customer_case`, `co_marketing` | `editorial_outreach`, `owner_task` |
| `unlinked_mention` | §1.2, §3E | `brand`, `product`, `resource` | `editorial_outreach` (a link request), `owner_task` |
| `link_recovery` | §1.11, §7 | `moved_page`, `removed_link`, `expired_listing` | `editorial_outreach`, `authorized_form` (re-listing at a destination with an authorization), `owner_task` |
| `syndication_target` | §4 "syndication with appropriate canonicalization" | `wordpress_receiver`, `partner_feed` | `syndication`, `owner_task` |

The `distribution` method is used only by `lga_distributions` items, which are not opportunities. `none` marks an opportunity that has not been prepared. Test: U `OpportunityTypesTest` checks that unknown types, subtypes and methods are refused, that each type is limited to its allowed methods, and that a `podcast` without expertise resolves to `owner_task`.

`opportunities.status`: `open` → `prepared` (package or draft ready) → `awaiting_approval` → `contacted` | `submitted` | `distributed` → `provider_confirmed` → `live_verified`; terminal: `declined`, `no_response` (30 days after the last follow-up), `expired`, `dismissed`, `refused_policy`, `rejected`. Every change sets `status_changed_at` and writes `audit_log` `opportunity.status`.

`acquisition_method`: `official_api`, `authorized_form`, `owner_task`, `editorial_outreach`, `resource_suggestion`, `distribution`, `syndication`, `none`.

Attribution: backlinks get `opportunity_id`, `submission_id`, `distribution_id` and `resource_id`. When `BacklinkMonitor` confirms a link whose source URL matches an open opportunity's URL (same `url_hash`, or same host and a path that the opportunity page redirects to), it links the two and moves the opportunity to `live_verified`. A verified link to a registered resource with no submission, outreach or distribution on that domain is marked `is_natural = 1` (§8.6).

Broken-link opportunities also store `broken_url`, `last_verified_broken_at` (re-checked within 24 hours before any outreach or package), `suggested_url`, `suggested_resource_id` and `match_score`. `broken_link_scan` gets its own task (daily by default, §5.1).

Competitor intersect: `Discovery\CompetitorIntersect` keeps, per referring page, the set of competitors it links to (`opportunities.details.competitor_links[] = {competitor, target_url, anchor}`) and `competitor_count`; the upsert merges sets instead of overwriting (fixes the engine's `array_merge` overwrite). Opportunities with `competitor_count >= 2` and no link to us sort first and get a dashboard filter.

Citation topics: `Discovery\CitationTopics` groups competitor backlinks by target page (URL path, title, anchors) and counts referring domains per topic. Topics feed the gap analyzer (§7.1).

### 3.5 Backlink monitor v2 (spec §7)

On top of the engine's checks (source exists, link present, anchor and rel change, inaccessible source, broken, removed, two-strike rule, transient statuses):

- **Destination check** (`DestinationChecker`, at most once per day per target URL): our target URL must return 200, must not be noindex, and must not redirect to an unrelated page (different path family or the home page). Results go to `backlinks.destination_status` and `destination_checked_at`; a failing destination raises `link_changed` with the recommendation `fix_destination`.
- **Redirects** (`RedirectTracer`): the chain and type (301, 302, 307, 308, meta refresh) for the source and the destination, stored in `redirect_chain` (JSON, at most 5 hops). When the source moved on the same site and the link is still present, the tracked `source_url` is updated and the change recorded.
- **Rel direction**: `followed_to_nofollow`, `sponsored_added`, `ugc_added`, `nofollow_removed`; checked against `placement_type` (a paid or sponsored placement must stay sponsored or nofollow, otherwise a warning "paid link is followed; ask the site to add rel=sponsored").
- **Check history**: one row per check in `lga_backlink_checks` (no 50-entry cap); `backlinks.changes` keeps the last 20 changes for the drawer.
- **Content drift** (`ContentDrift`): per check the page title, a 64-bit simhash of the main content (`content_hash`), word count, language, outbound link count and noindex flag. A Hamming distance above 18 bits, a word count drop of more than 70 %, a language change, or outbound links up more than 3× raises `suspicious_change` and re-runs RiskAnalyzer on the page.
- **Ownership** (`OwnershipWatcher`, monthly task `ownership_check`): RDAP registrar, registration and last-changed/transfer events, nameserver set hash, organization name from schema, site name. Stored in `lga_domain_fingerprints`. A change of registrar plus nameservers, a transfer event, or a changed organization name raises `suspicious_change` ("source site may have changed owner") and queues re-evaluation.
- **Hidden link** found on a live page is a warning notification (event `hidden_link`), not only a note. Content drift and ownership changes notify through event `suspicious_change`. Both events are listed in `notifications.events` (default on, §2.2) and are deduped per backlink and change (`dedupe_key = sha1(event|backlink_id|change_hash)`).
- **Check priority** (`Monitoring\CheckPlanner`, used by `monitor_backlinks` within the `backlink_checks` limits of §5.3). Due links are taken in this order, and within each tier by `next_check_at` ascending:
  1. **Attributed or acquired** links: `opportunity_id`, `submission_id` or `distribution_id` set, or origin `submission`/`outreach`/`distribution`/`manual`. Due by the engine's new/stable intervals (1 and 7 days).
  2. **Not yet verified** links: `first_verified_at` null and origin not `import`. Checked once as soon as possible, then as tier 1.
  3. **Other verified links**, by `quality_score` descending. Due every 14 days.
  4. **Imported-only** links (origin `import` from a provider, never fetched by us). Sampled within what is left of the day's budget after tiers 1-3: `ceil(remaining × 0.5)` links, chosen by quality-weighted random sampling with a seeded generator so every link is eventually sampled, and each sampled link moves to tier 2.

  Coverage: `GET /backlinks/summary` returns `coverage_30d = links checked in the last 30 days / active links` overall and per tier. The Backlinks page shows "X % of links checked in the last 30 days" with the per-tier split and, when coverage of tiers 1-2 drops below 95 %, a hint to raise the `backlink_checks` limit. Tests (WP21): U `CheckPlannerTest` (tier order; an imported profile of 20,000 links does not delay a due tier-1 link; sampling stays within the remaining budget; coverage numbers on a seeded set) and U `NotifierEventsTest` (both events respect their preference toggles and dedupe).
- **Inaccessible since**: `backlinks.inaccessible_since` is set on the first inconclusive result and cleared on success; an alert fires after 14 days.
- **Recommendations** (`Recommendations`), stored on the backlink (`recommendation`) and on the notification: `contact_restore`, `fix_destination`, `update_tracked_url`, `find_replacement`, `review_manually`. Disavow: never generated or uploaded. A "review manually" event may suggest that the owner consider a disavow only with the evidence listed; there is no export of a disavow file in 1.0.0.
- **Recovery**: when a domain's last live link is lost, `RecoveryPlanner` creates a `link_recovery` opportunity (details: backlink id, last seen live, last evidence) and records a `domain_lost` outcome (§8). It first looks for a moved page (redirect chain, the site's sitemap, a Brave site-search query when Brave is connected), then proposes a restore or re-listing draft (cool-down and suppression respected, approval required), then asks `Discovery\Expansion` for comparable sites in the same category.
- `first_verified_at` is set on the first confirmation and drives "new verified backlinks" (§9).

---

## 4. Database schema

### 4.1 Conventions

- All tables are `{$wpdb->prefix}lga_<name>`, created through `dbDelta` (two spaces after `PRIMARY KEY`, one column per line, `KEY` not `INDEX`, lower-case types).
- Works on MySQL 5.7+, MariaDB 10.4+ and the SQLite Database Integration drop-in 3.1.2 used by the test site: no ENUM, no generated columns, no functional or partial indexes, no `ON DUPLICATE KEY UPDATE` (use `INSERT IGNORE` followed by a conditional `UPDATE`, which the drop-in translates). Indexed text columns are at most `varchar(190)`; long URLs are indexed through `char(40)` SHA-1 hash columns.
- Every query goes through `$wpdb->prepare`. Repositories capture `$wpdb->last_error` after `insert`, `update`, `delete` and raw queries and report it to `Health\Checks\DatabaseCheck` (fixing silent update failures).
- `is_simulated` (`tinyint(1) NOT NULL DEFAULT 0`) on every table that can hold provider results: set when the row came from a mock or sandbox provider (§12.4). KPIs, the decision engine and "successful" counts filter `is_simulated = 0`.
- `strategy_key` is `char(16)`: the first 16 hex characters of `sha1` of the canonical arm string (§8.1).

### 4.2 Reused tables (renamed) and their changes

The 14 engine tables keep their columns. Added columns and indexes:

| Table | Added columns | Added indexes |
|---|---|---|
| `prospects` | `is_owned tinyint(1) NOT NULL DEFAULT 0`, `legitimacy longtext`, `reliability tinyint(3) unsigned DEFAULT NULL`, `strategy_key char(16) NOT NULL DEFAULT ''`, `is_simulated tinyint(1) NOT NULL DEFAULT 0` | `strategy_key`, `is_owned` |
| `opportunities` | `subtype varchar(40) NOT NULL DEFAULT ''`, `acquisition_method varchar(30) NOT NULL DEFAULT 'none'`, `relevance tinyint(3) unsigned DEFAULT NULL` (the topical_relevance sub-score, §3.3), `risk_rating varchar(10) NOT NULL DEFAULT ''` (low, medium, high, §3.3), `reject_reason varchar(30) NOT NULL DEFAULT ''` (low_score, high_risk, gate:<id>, owned_site, policy), `score_breakdown longtext`, `score_confidence tinyint(3) unsigned NOT NULL DEFAULT 0`, `suggested_url varchar(700) NOT NULL DEFAULT ''`, `suggested_resource_id bigint(20) unsigned DEFAULT NULL`, `match_score tinyint(3) unsigned DEFAULT NULL`, `placement_type varchar(20) NOT NULL DEFAULT 'unknown'` (editorial, free_listing, paid, sponsored, ugc, unknown), `expected_rel varchar(30) NOT NULL DEFAULT 'unknown'`, `eligibility longtext` (JSON verdict and evidence URL), `terms_verdict varchar(20) NOT NULL DEFAULT 'unknown'` (allows, forbids_automation, unclear, unknown), `terms_checked_at datetime DEFAULT NULL`, `broken_url varchar(700) NOT NULL DEFAULT ''`, `last_verified_broken_at datetime DEFAULT NULL`, `competitor_count smallint(5) unsigned NOT NULL DEFAULT 0`, `discovered_via varchar(60) NOT NULL DEFAULT ''`, `strategy_key char(16) NOT NULL DEFAULT ''`, `status_changed_at datetime DEFAULT NULL`, `is_simulated tinyint(1) NOT NULL DEFAULT 0` | `status_score (status,score)`, `acquisition_method`, `risk_rating`, `competitor_count`, `strategy_key`, `suggested_resource_id` |
| `submissions` | `authorization_id bigint(20) unsigned DEFAULT NULL`, `placement_type varchar(20) NOT NULL DEFAULT 'unknown'`, `expected_rel varchar(30) NOT NULL DEFAULT 'unknown'`, `provider_confirmed_at datetime DEFAULT NULL`, `strategy_key char(16) NOT NULL DEFAULT ''`, `is_simulated tinyint(1) NOT NULL DEFAULT 0`. Status values become `pending_review, needs_manual, needs_owner_verification, approved, queued, sent_unconfirmed, provider_confirmed, live_verified, failed, rejected, refused_policy` (`confirmed_at` keeps its column and means live verified) | `adapter_status (adapter,status)`, `strategy_key` |
| `contacts` | `jurisdiction varchar(20) NOT NULL DEFAULT ''`, `consent_evidence_url varchar(700) NOT NULL DEFAULT ''`, `last_activity_at datetime DEFAULT NULL` (last draft, send, reply or status change; drives retention, §11.9); `consent_basis` default changes to `'unknown'` | `consent_basis`, `last_activity_at` |
| `outreach_messages` | `jurisdiction varchar(20) NOT NULL DEFAULT ''`, `strategy_key char(16) NOT NULL DEFAULT ''`, `approved_by bigint(20) unsigned DEFAULT NULL`, `approved_at datetime DEFAULT NULL` | `strategy_key` |
| `backlinks` | `opportunity_id`, `submission_id`, `distribution_id`, `resource_id` (`bigint(20) unsigned DEFAULT NULL`), `placement_type varchar(20) NOT NULL DEFAULT 'unknown'`, `is_owned tinyint(1) NOT NULL DEFAULT 0`, `is_natural tinyint(1) DEFAULT NULL`, `first_verified_at datetime DEFAULT NULL`, `indexed_status varchar(12) NOT NULL DEFAULT 'unknown'`, `indexed_source varchar(30) NOT NULL DEFAULT ''`, `indexed_checked_at datetime DEFAULT NULL`, `destination_status smallint(5) unsigned DEFAULT NULL`, `destination_checked_at datetime DEFAULT NULL`, `redirect_chain text`, `content_hash char(16) NOT NULL DEFAULT ''`, `inaccessible_since datetime DEFAULT NULL`, `recommendation varchar(40) NOT NULL DEFAULT ''`, `strategy_key char(16) NOT NULL DEFAULT ''`, `is_simulated tinyint(1) NOT NULL DEFAULT 0` | `first_verified_at`, `opportunity_id`, `resource_id`, `status_domain (status,source_domain)`, `strategy_key` |
| `jobs` | `task varchar(40) NOT NULL DEFAULT ''`, `active_key char(40) DEFAULT NULL` (sha1 of type and canonical payload while queued or running, NULL otherwise), `error_class varchar(20) NOT NULL DEFAULT ''`, `requeues tinyint(3) unsigned NOT NULL DEFAULT 0` | `UNIQUE KEY active_key (active_key)`, `type_status (type,status)`, `status_finished (status,finished_at)` |
| `notifications` | `event varchar(40) NOT NULL DEFAULT ''`, `dedupe_key char(40) NOT NULL DEFAULT ''` | `event`, `dedupe_key` |
| `search_log` | `strategy_key char(16) NOT NULL DEFAULT ''`, `qualified int(11) unsigned NOT NULL DEFAULT 0` | `provider_run (provider,run_at)` |
| `suppression` | `source varchar(60) NOT NULL DEFAULT 'local'` (local, unsubscribe, bounce, complaint, import:<label>), `expires_at datetime DEFAULT NULL` (cool-downs only); `type` values become `email`, `email_hash`, `domain`, `contacted_domain` (§2.8, §11.9) | `type_value (type,value)`, `expires_at` |
| `logs` | none (channel `runner` rows carry the run record in `context`, §5.5) | `channel_created (channel,created_at)` |
| `campaigns`, `audit_log`, `domain_metrics` | none | none |

`UNIQUE active_key`: MySQL and SQLite both allow many NULLs in a unique index, so finished jobs (NULL) never collide, and two processes trying to enqueue the same pending job get a duplicate-key failure that `Jobs::enqueue()` treats as "already queued". This replaces the 50-row payload scan in `hasPending()`.

### 4.3 New tables

```sql
CREATE TABLE {p}lga_tasks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  task varchar(40) NOT NULL,
  frequency varchar(12) NOT NULL DEFAULT 'daily',
  enabled tinyint(1) NOT NULL DEFAULT 1,
  last_run_at datetime DEFAULT NULL,
  next_run_at datetime DEFAULT NULL,
  last_status varchar(20) NOT NULL DEFAULT '',
  last_duration_ms int(11) unsigned NOT NULL DEFAULT 0,
  last_job_id bigint(20) unsigned DEFAULT NULL,
  last_error varchar(255) NOT NULL DEFAULT '',
  run_count int(11) unsigned NOT NULL DEFAULT 0,
  fail_count int(11) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY task (task),
  KEY next_run_at (next_run_at)
) {charset};

CREATE TABLE {p}lga_counters (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  counter varchar(80) NOT NULL,
  period char(1) NOT NULL,
  period_key varchar(16) NOT NULL,
  used bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY counter_period (counter,period,period_key),
  KEY period_key (period,period_key)
) {charset};
-- period: i = minute (YmdHi), h = hour (YmdH), d = day (Y-m-d), w = ISO week (o-\WW), m = month (Y-m); site time zone

CREATE TABLE {p}lga_provider_state (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  provider varchar(60) NOT NULL,
  kind varchar(20) NOT NULL DEFAULT 'data',
  enabled tinyint(1) NOT NULL DEFAULT 0,
  mode varchar(10) NOT NULL DEFAULT 'live',
  connection_status varchar(20) NOT NULL DEFAULT 'not_configured',
  credential_status varchar(20) NOT NULL DEFAULT 'missing',
  credential_expires_at datetime DEFAULT NULL,
  last_verified_at datetime DEFAULT NULL,
  granted_scopes text,
  operations text,
  quota longtext,
  circuit_state varchar(10) NOT NULL DEFAULT 'closed',
  consecutive_failures smallint(5) unsigned NOT NULL DEFAULT 0,
  open_count smallint(5) unsigned NOT NULL DEFAULT 0,
  open_until datetime DEFAULT NULL,
  paused_until datetime DEFAULT NULL,
  retry_after_source varchar(20) NOT NULL DEFAULT '',
  last_error_class varchar(20) NOT NULL DEFAULT '',
  last_error varchar(255) NOT NULL DEFAULT '',
  last_error_at datetime DEFAULT NULL,
  last_success_at datetime DEFAULT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY provider (provider),
  KEY circuit (circuit_state,open_until)
) {charset};
-- connection_status: not_configured | connected | error | expired | rate_limited | disabled | credentials_unreadable
-- credential_status: missing | valid | invalid | expired | revoked | unreadable

CREATE TABLE {p}lga_provider_activity (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  provider varchar(60) NOT NULL,
  operation varchar(40) NOT NULL,
  object_type varchar(30) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  outcome varchar(20) NOT NULL,
  http_status smallint(5) unsigned DEFAULT NULL,
  duration_ms int(11) unsigned NOT NULL DEFAULT 0,
  units int(11) unsigned NOT NULL DEFAULT 0,
  error_class varchar(20) NOT NULL DEFAULT '',
  message varchar(255) NOT NULL DEFAULT '',
  is_simulated tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY provider_created (provider,created_at),
  KEY outcome_created (outcome,created_at),
  KEY created_at (created_at)
) {charset};
-- outcome: ok | error | unsupported | rate_limited | auth_error | refused | skipped_circuit

CREATE TABLE {p}lga_authorizations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  provider varchar(60) NOT NULL,
  destination_domain varchar(190) NOT NULL DEFAULT '',
  operations text,
  evidence_type varchar(30) NOT NULL,
  evidence_url varchar(700) NOT NULL DEFAULT '',
  evidence_note text,
  granted_by bigint(20) unsigned NOT NULL DEFAULT 0,
  granted_at datetime NOT NULL,
  expires_at datetime DEFAULT NULL,
  revoked_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY provider_destination (provider,destination_domain),
  KEY destination_domain (destination_domain)
) {charset};
-- evidence_type: api_scope | terms_allow | written_permission | owner_account | partner_agreement

CREATE TABLE {p}lga_distributions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  resource_id bigint(20) unsigned DEFAULT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  provider varchar(60) NOT NULL,
  channel varchar(20) NOT NULL,
  status varchar(30) NOT NULL DEFAULT 'draft',
  dedupe_hash char(40) NOT NULL,
  message text,
  link_url varchar(700) NOT NULL DEFAULT '',
  canonical_url varchar(700) NOT NULL DEFAULT '',
  remote_id varchar(190) NOT NULL DEFAULT '',
  remote_url varchar(700) NOT NULL DEFAULT '',
  canonical_ok tinyint(1) DEFAULT NULL,
  canonical_checked_at datetime DEFAULT NULL,
  justification text,
  payload longtext,
  response longtext,
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  last_error varchar(255) NOT NULL DEFAULT '',
  approved_by bigint(20) unsigned DEFAULT NULL,
  approved_at datetime DEFAULT NULL,
  scheduled_at datetime DEFAULT NULL,
  sent_at datetime DEFAULT NULL,
  confirmed_at datetime DEFAULT NULL,
  verified_at datetime DEFAULT NULL,
  strategy_key char(16) NOT NULL DEFAULT '',
  is_simulated tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY provider_dedupe (provider,dedupe_hash),
  KEY status_sched (status,scheduled_at),
  KEY resource_id (resource_id)
) {charset};
-- channel: social | distribution | syndication | owned | webhook
-- status: draft | awaiting_approval | queued | sent_unconfirmed | handed_off | provider_confirmed | live_verified
--         | canonical_missing | failed | canceled | refused_policy

CREATE TABLE {p}lga_resources (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned DEFAULT NULL,
  asset_type varchar(30) NOT NULL,
  title varchar(255) NOT NULL DEFAULT '',
  purpose text,
  topic varchar(190) NOT NULL DEFAULT '',
  topic_hash char(40) NOT NULL DEFAULT '',
  target_keywords text,
  gap_id bigint(20) unsigned DEFAULT NULL,
  gap_evidence longtext,
  generator varchar(20) NOT NULL DEFAULT 'template',
  ai_used tinyint(1) NOT NULL DEFAULT 0,
  sources longtext,
  facts_used text,
  check_results longtext,
  check_passed tinyint(1) NOT NULL DEFAULT 0,
  status varchar(30) NOT NULL DEFAULT 'planned',
  publish_mode varchar(10) NOT NULL DEFAULT 'draft',
  actor varchar(60) NOT NULL DEFAULT 'system',
  links_earned int(11) unsigned NOT NULL DEFAULT 0,
  natural_links int(11) unsigned NOT NULL DEFAULT 0,
  referral_sessions_90d int(11) unsigned NOT NULL DEFAULT 0,
  retire_reason varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  drafted_at datetime DEFAULT NULL,
  published_at datetime DEFAULT NULL,
  last_reviewed_at datetime DEFAULT NULL,
  next_review_at datetime DEFAULT NULL,
  is_simulated tinyint(1) NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY post_id (post_id),
  UNIQUE KEY topic_type (topic_hash,asset_type),
  KEY status (status),
  KEY next_review_at (next_review_at),
  KEY gap_id (gap_id)
) {charset};
-- generator: template | ai_assisted | owner | imported (an existing page registered as an asset)
-- status: planned | drafting | draft | needs_owner_content | awaiting_review | published | rejected | retired

CREATE TABLE {p}lga_content_gaps (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  topic varchar(190) NOT NULL,
  topic_hash char(40) NOT NULL,
  suggested_type varchar(30) NOT NULL,
  gap_score tinyint(3) unsigned NOT NULL DEFAULT 0,
  score_parts text,
  evidence longtext,
  sources varchar(120) NOT NULL DEFAULT '',
  feasibility varchar(20) NOT NULL DEFAULT 'unknown',
  status varchar(20) NOT NULL DEFAULT 'open',
  resource_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY topic_type (topic_hash,suggested_type),
  KEY status_score (status,gap_score)
) {charset};
-- sources: comma list of gsc, competitor, broken_link, resource_page, pack
-- feasibility: ready | needs_owner_data | needs_facts | unknown ; status: open | planned | drafted | covered | dismissed

CREATE TABLE {p}lga_facts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  fact_key varchar(80) NOT NULL,
  fact_group varchar(40) NOT NULL DEFAULT 'business',
  statement text NOT NULL,
  value varchar(190) NOT NULL DEFAULT '',
  value_num double DEFAULT NULL,
  unit varchar(30) NOT NULL DEFAULT '',
  source_type varchar(20) NOT NULL DEFAULT 'owner',
  origin varchar(20) NOT NULL DEFAULT 'owner',
  source_url varchar(700) NOT NULL DEFAULT '',
  source_excerpt text,
  source_matched_at datetime DEFAULT NULL,
  dataset_id bigint(20) unsigned DEFAULT NULL,
  dataset_ref varchar(190) NOT NULL DEFAULT '',
  verified tinyint(1) NOT NULL DEFAULT 0,
  verified_by bigint(20) unsigned DEFAULT NULL,
  verified_at datetime DEFAULT NULL,
  review_at datetime DEFAULT NULL,
  last_checked_at datetime DEFAULT NULL,
  check_status varchar(20) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY fact_key (fact_key),
  KEY group_verified (fact_group,verified),
  KEY review_at (review_at)
) {charset};
-- source_type: owner | first_party | dataset | url ; check_status: '' | ok | source_changed | source_unreachable
-- origin: owner | wizard | dataset_import | system_suggestion (who created the row; §7.4)
-- dataset_id: attachment id of the dataset; dataset_ref: "column=<name>;agg=<sum|mean|count|min|max|value>;filter=<expr>"

CREATE TABLE {p}lga_backlink_checks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  backlink_id bigint(20) unsigned NOT NULL,
  checked_at datetime NOT NULL,
  status varchar(20) NOT NULL,
  http_status smallint(5) unsigned DEFAULT NULL,
  rel varchar(60) NOT NULL DEFAULT '',
  anchor_text varchar(255) NOT NULL DEFAULT '',
  placement varchar(30) NOT NULL DEFAULT '',
  indexable tinyint(1) DEFAULT NULL,
  content_hash char(16) NOT NULL DEFAULT '',
  word_count int(11) unsigned DEFAULT NULL,
  outbound_count smallint(5) unsigned DEFAULT NULL,
  title varchar(255) NOT NULL DEFAULT '',
  note varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY backlink_checked (backlink_id,checked_at),
  KEY checked_at (checked_at)
) {charset};

CREATE TABLE {p}lga_domain_fingerprints (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  domain varchar(190) NOT NULL,
  registrar varchar(190) NOT NULL DEFAULT '',
  registered_at datetime DEFAULT NULL,
  rdap_changed_at datetime DEFAULT NULL,
  transfer_at datetime DEFAULT NULL,
  nameservers_hash char(40) NOT NULL DEFAULT '',
  ips_hash char(40) NOT NULL DEFAULT '',
  registrant_org varchar(190) NOT NULL DEFAULT '',
  org_name varchar(190) NOT NULL DEFAULT '',
  site_name varchar(190) NOT NULL DEFAULT '',
  checked_at datetime NOT NULL,
  changed_at datetime DEFAULT NULL,
  history longtext,
  PRIMARY KEY  (id),
  UNIQUE KEY domain (domain),
  KEY ns_ips (nameservers_hash,ips_hash),
  KEY checked_at (checked_at)
) {charset};
-- ips_hash: sha1 of the sorted A/AAAA set; registrant_org: RDAP registrant organization when not redacted (§2.8)

CREATE TABLE {p}lga_analytics_daily (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  metric varchar(40) NOT NULL,
  source varchar(20) NOT NULL,
  grain char(1) NOT NULL DEFAULT 'd',
  dim varchar(30) NOT NULL DEFAULT 'total',
  dim_value varchar(255) NOT NULL DEFAULT '',
  dim_hash char(40) NOT NULL DEFAULT '',
  day date NOT NULL,
  value double NOT NULL DEFAULT 0,
  weight double NOT NULL DEFAULT 0,
  is_simulated tinyint(1) NOT NULL DEFAULT 0,
  fetched_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY metric_day (metric,source,grain,dim,dim_hash,day),
  KEY day_metric (day,metric)
) {charset};
-- dim: total | source_domain | page | query | channel | keyword | rel | country
-- grain: d = day | w = ISO week (day = the Monday); dim_value '(other)' = everything below the top-N cap (§9.1)
-- weight: impressions behind a ctr/position value, so rollups and the "other" bucket can be weighted means

CREATE TABLE {p}lga_strategy_stats (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  strategy_key char(16) NOT NULL,
  family varchar(12) NOT NULL,
  arm varchar(190) NOT NULL,
  parent_key char(16) NOT NULL DEFAULT '',
  discovered double NOT NULL DEFAULT 0,
  qualified double NOT NULL DEFAULT 0,
  attempts double NOT NULL DEFAULT 0,
  acquired double NOT NULL DEFAULT 0,
  aged double NOT NULL DEFAULT 0,
  survived double NOT NULL DEFAULT 0,
  referral_sessions double NOT NULL DEFAULT 0,
  conversions double NOT NULL DEFAULT 0,
  value_sum double NOT NULL DEFAULT 0,
  risk_points double NOT NULL DEFAULT 0,
  risk_attempts double NOT NULL DEFAULT 0,
  cost_units double NOT NULL DEFAULT 0,
  priority double NOT NULL DEFAULT 0,
  share double NOT NULL DEFAULT 0,
  state varchar(20) NOT NULL DEFAULT 'active',
  state_reason varchar(255) NOT NULL DEFAULT '',
  state_changed_at datetime DEFAULT NULL,
  computed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY strategy_key (strategy_key),
  KEY family_state (family,state)
) {charset};
-- family: discovery | acquisition | asset ; state: active | paused_risk | paused_manual | deprioritized

CREATE TABLE {p}lga_strategy_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  strategy_key char(16) NOT NULL,
  event varchar(30) NOT NULL,
  value double NOT NULL DEFAULT 1,
  object_type varchar(30) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  occurred_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY strategy_time (strategy_key,occurred_at),
  KEY event_time (event,occurred_at),
  KEY object (object_type,object_id)
) {charset};

CREATE TABLE {p}lga_decisions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_at datetime NOT NULL,
  strategy_key char(16) NOT NULL,
  pool varchar(30) NOT NULL DEFAULT '',
  old_share double NOT NULL DEFAULT 0,
  new_share double NOT NULL DEFAULT 0,
  quota int(11) unsigned NOT NULL DEFAULT 0,
  priority double NOT NULL DEFAULT 0,
  action varchar(20) NOT NULL DEFAULT 'allocate',
  inputs longtext,
  reason varchar(500) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY run_at (run_at),
  KEY strategy_run (strategy_key,run_at)
) {charset};
-- action: allocate | pause | resume | deprioritize | expand
```

Total: 14 renamed tables plus 15 new ones = 29 tables. `Schema::TABLES` is built from the modules; `tests/Support/FakeWpdb.php` keeps building the schema from the real statements.

### 4.4 Options and transients

Autoloaded (tiny markers read on every request, so they cost nothing extra when the alloptions query runs anyway):
- `lga_db_version` (string).
- `lga_runtime`, an array of at most 10 scalars, under 1 KB (checked by I `autoload`):
  - `setup_completed`, `profile_ready`, `runner`, `version`;
  - `receiver_enabled`, `calculator_present`, `indexnow_enabled`;
  - `caps_version` (replaces the engine's `caps_synced` option read);
  - `schedule_checked_at` (Unix time of the last `Scheduler::maybeSchedule` self-check);
  - `critical_health` (number of checks currently critical, so admin notices outside plugin pages need no query, §11.3).

Not autoloaded:
- Settings and secrets: `lga_settings`, `lga_secrets`, `lga_custom_packs`, `lga_template_overrides`.
- Runner: `lga_last_tick`, `lga_cron_heartbeat` (`{at, source: cli|http|wp_cron|as, duration_ms, jobs_run, status}`), `lga_cron_heartbeat_server` (the last heartbeat whose source is `cli` or `http`, for the 7-day warning of §5.8), `lga_cron_token` (`{hash, created_at, last4, created_by}`), `lga_background_pause`, `lga_migrate_backoff`.
- State: `lga_health_state`, `lga_growth_run`, `lga_growth_turn`, `lga_site_audit`, `lga_internal_links`, `lga_render_checks`, `lga_indexnow_state`, `lga_indexnow_pending`, `lga_indexnow_conflicts`, `lga_query_cursor`, `lga_competitor_*`, `lga_catalog_state`, `lga_positioning_audit`, `lga_backup_dir`.
- `lga_autopilot_consent`, the **single store of consents**: `{<key>: {granted: bool, user_id, at, policy_version, text_sha256, revoked_by?, revoked_at?}}` for the keys of §2.2. `consent.*` reads come from here, and every write is also audited.
- Owned sites: `lga_owned_suggestions` (§2.8), `lga_syndication_sources` (§2.8).

Removed relative to the engine:
- `ppclg_counters` (now the `lga_counters` table) and `ppclg_stage_runs` (now `lga_tasks`).
- `ppclg_stage_results`, replaced by `lga_tasks.last_status`/`last_error`, `jobs.result` and the runner log rows (§5.5).
- `ppclg_provider_error_*` (now `lga_provider_state`).
- `ppclg_auto_links_consent` (now the `internal_links` entry of `lga_autopilot_consent`) and `ppclg_caps_synced` (now `lga_runtime.caps_version`).
- `ppclg_verified_db_*` and `ppclg_storefront_*`.

Transients keep the engine's patterns under `lga_` (`lga_lock_<name>`, `lga_robots_<host>`, `lga_rdap_<domain>`, `lga_rl_<key>`, `lga_gsa_token_<hash>`, `lga_ntf_<key>`, …), plus `lga_cronfail_<iphash>` and `lga_cronok_<iphash>` for the cron endpoint rate limits and `lga_oauth_<state>` (10 minutes).

Uninstall (when `advanced.delete_on_uninstall` is true) removes:
- all 29 tables;
- options and transients `LIKE 'lga\_%'`, `'_transient_lga\_%'` and `'_transient_timeout_lga\_%'`;
- user meta `lga_dismissed_notices`;
- post meta `_lga_%`, **except the receiver meta below**;
- the role and capabilities, AS actions in group `lga`, the cron hook, and the backup directory.

It runs per site on multisite. Resources created as WordPress pages are **not** deleted (they are the owner's content); their `_lga_*` meta is removed. With `delete_on_uninstall` false, only the cron hook and AS actions are removed.

**Receiver sites** (syndicated copies, `_lga_syndicated_from` set) need care, because a copy without its canonical becomes an uncanonicalized duplicate:
- At receive time, `SyndicationReceiver` writes the canonical into the active SEO plugin's own canonical field through `SeoPluginAdapter` (§1.4) and records `_lga_canonical_store = <adapter id>`. The canonical then survives deactivation and uninstall, because the SEO plugin outputs it.
- Without an SEO plugin, the canonical depends on this plugin's `get_canonical_url` filter. The Plugins screen then shows a row notice (`after_plugin_row_{LGA_BASENAME}`): "N syndicated posts rely on this plugin for their canonical link. Deactivating it removes their canonical. See Settings > Advanced > Removing the plugin."
- **Settings > Advanced > Removing the plugin** is a confirmation screen. It lists the syndicated copies, says whether each one's canonical is held by an SEO plugin, and sets `advanced.receiver_uninstall_action`:
  - `keep` (default): `_lga_canonical_url`, `_lga_syndicated_from` and `_lga_canonical_store` stay on those posts, so a later reinstall or an SEO plugin can use them;
  - `draft`: uninstall switches every copy whose canonical is not held by an SEO plugin to `draft`, after writing a revision and an audit row.

  The screen also offers "Copy canonicals into {SEO plugin} now" when an SEO plugin is present.

Test: in WP19's two-site integration (8793 → 8796), the receiver writes Yoast's `_yoast_wpseo_canonical` when Yoast is simulated through its meta, and uninstall with `keep` leaves the canonical meta in place. Without an SEO plugin, uninstall with `draft` switches the copy to draft, and the Plugins-row notice appears before deactivation.

### 4.5 Migration and versioning

- `LGA_DB_VERSION = '1.0.0'`. `Database\Migrator::STEPS` is an ordered map `version => callable[]`. Each step is idempotent and runs after `dbDelta` of the full schema. `lga_db_version` is written (autoload yes) only after every step of that version succeeded.
- `0.9.0` = the renamed engine schema (14 tables, no new columns). It exists only as the fixture `tests/fixtures/db/schema-0.9.0.sql` with sample rows, to test a real upgrade path.
- `1.0.0` steps: (0) settings: `automation.submission_mode = 'auto'` becomes `authorized_only`, removed keys (§2.2) are dropped, `automation.autopilot` is set to false and any stored `consent.*` value is discarded (consents must be given again in the 1.0.0 dialog); (1) dbDelta adds the new columns and 15 tables; (2) map statuses: opportunities `packaged` to `prepared`, `acquired` to `live_verified`; submissions `submitted` to `sent_unconfirmed`, `confirmed` to `live_verified`; (3) seed `lga_tasks` from the task registry; (4) move `lga_counters` option rows into the table, then delete the option; (5) seed `lga_provider_state` from `lga_provider_error_*` options, then delete them; (6) `UPDATE jobs SET active_key = NULL WHERE status NOT IN ('queued','running')`; (7) set `backlinks.first_verified_at = COALESCE(first_seen)` for active rows whose origin is a verifying origin (submission, manual check), leave others NULL.
- Before any step: `Database\Backup` dumps the plugin's tables (gzip, random directory in uploads, keeps 10). Under lock `migrate`; on failure the 15-minute back-off, an admin notice and the health check `database` (critical) apply. Downgrades are not supported; docs say so.
- Migration runs on `admin_init`, before the tick, on our REST namespace and before WP-CLI commands; never on front-end requests (§11.3).

---

## 5. Scheduler, limits, runners and health

### 5.1 Task registry and per-task schedules

`Automation\TaskRegistry` replaces the hard-coded `Pipeline::stages()`. Each module returns `TaskDefinition` objects:

```php
final class TaskDefinition {
    public function __construct(
        public string $id,               // 'discover'
        public string $jobType,          // registered job type
        public string $label,
        public string $defaultFrequency, // Frequency::DAILY
        public string $module,           // automation.modules.<flag> that must be on ('' = always)
        public ?string $dueCheck,        // name of a method on the module's DueChecks class, or null
        public int    $priority,         // 1 (first) .. 9, the job priority it enqueues with
        public string $budgetPool = '',  // limit counter it spends (§5.3); pools listed in §8.4 are also split per arm; '' = none
        public string $runCap = '',      // the internal bound that holds when it runs often and has no pool (column "Bound" below)
        public bool   $requiresProfile = true, // blocked until setup.profile_ready (§2.1); false only for the four stage-0 tasks
        public bool   $requiresSetup = false,  // blocked until setup.completed
        public string $consent = '',     // consent key that must be recorded (§2.7), '' = none
        public bool   $autopilot = false,// the scheduler starts it only while Autopilot::allows($consent); a person may run it any time
        public bool   $outbound = false, // sends requests to hosts other than the site's own (must imply requiresProfile)
        public bool   $offAllowed = true // false: health_check and cleanup
    ) {}
}
```

Due-checks are methods of internal classes resolved by name inside the module; they are never taken from settings (spec §12 "never execute untrusted code"). Settings can only change `frequency` and `enabled` (`automation.schedules.<task>`, `lga_tasks.enabled`). Extensions add tasks through the `lga_scheduled_tasks` filter with their own `TaskDefinition`. A unit test (`TaskRegistryTest`) checks that every task with `outbound = true` has `requiresProfile = true`, that every `autopilot = true` task names a consent, and that priorities are 1-9.

Frequencies (`Automation\Frequency`): `15min` (900 s), `hourly` (3,600 s), `6h` (21,600 s), `daily`, `weekly`, `monthly` (calendar-based), `continuous` (checked on every tick, enqueued only when the due-check says work is waiting), `off`.

**Every task allows all six frequencies** (15min, hourly, 6h, daily, weekly, monthly). Tasks with a due-check also allow `continuous`, and every task except `health_check` and `cleanup` allows `off`. The engine's narrower per-task sets are dropped. Cost is bounded by limits instead: a frequent task spends its limit pool (§5.3) and stops when the pool is empty. A task without a pool has the run cap in the "Bound" column: incremental work, a per-run cap, or a minimum age before an item is redone, so running it every 15 minutes does no more total work than the cap allows. CONFIGURATION.md records this reason and the bound of each task.

Default schedule. Priority 1 is claimed first. Gates: P = `requiresProfile`, S = `requiresSetup`, C:x = consent x, A = `autopilot`, O = `outbound`.

| Task id | Job type | Prio | Default | Module flag | Gates | Limit pool / bound | Notes |
|---|---|---|---|---|---|---|---|
| `health_check` | health_check | 1 | 15min | (always) | none | local reads only | §5.8; cannot be off |
| `provider_health` | provider_health | 2 | daily | (always) | none (tests only configured providers) | provider limits; at most 1 test per provider per hour | connection tests, credential expiry |
| `submit` | submit | 2 | continuous | submissions | P S C:submissions A O | submissions | §6.7 |
| `outreach_send` | outreach_send | 2 | continuous | outreach_drafts | P S O | outreach_emails | person-approved messages only (§2.7); no Autopilot needed because each message carries its approval |
| `distribution_send` | distribution_send | 2 | 15min | distribution | P S C:distribution A O | distribution_posts | approved or auto-own-channel items |
| `monitor_backlinks` | monitor_backlinks | 3 | continuous | monitoring | P O | backlink_checks | check priority of §3.5 |
| `verify_listings` | verify_listings | 3 | 6h | submissions | P O | each listing at most once per 24 h, 50 per run | |
| `distribution_verify` | distribution_verify | 3 | 6h | distribution | P O | each item at most once per 6 h, 50 per run | canonical and link checks |
| `indexnow` | indexnow | 3 | 15min | seo | P O | url_submissions | only when URLs are waiting; off when another IndexNow implementation is active (§1.4) |
| `evaluate` | evaluate | 4 | continuous | evaluation | P O | evaluations | |
| `find_opportunities` | find_opportunities | 4 | continuous | opportunities | P O | each prospect at most once per 30 days, 25 per run | |
| `package` | package | 4 | hourly | packaging | P | only opportunities without a current package, 50 per run | |
| `positioning_audit` | positioning_audit | 4 | daily | (pack positioning) | P | only posts changed since the last run | registered only when the pack or owner defines rules |
| `rescore` | rescore | 5 | continuous | evaluation | P | batches of 200, only after a weight change | |
| `outreach_drafts` | auto_outreach | 5 | daily | outreach_drafts | P | outreach_drafts | drafts only; per-run cap = remaining daily budget (§2.7) |
| `follow_ups` | follow_ups | 5 | daily | outreach_drafts | P S (+C:follow_ups A for autosend) O | outreach_drafts, outreach_emails | at most one per first contact (§2.7) |
| `import_backlinks` | import_backlinks | 5 | weekly | imports | P O | provider_lookups | own-site links from providers, GA4 and Bing |
| `analytics_snapshot` | analytics_snapshot | 5 | daily | analytics | P O | provider limits; only days not yet settled (last 3) plus the backfill queue | GSC, GA4, Bing, Woo, internal |
| `decision_recompute` | decision_recompute | 5 | daily | decision | P | local; skipped when no new strategy events since the last run | |
| `discover` | discover | 6 | daily | discovery | P O | discovery_queries | query templates chosen by the decision engine |
| `mention_scan` | mention_scan | 6 | weekly | discovery | P O | discovery_queries | |
| `competitor_backlinks` | import_competitor_backlinks | 6 | weekly | discovery | P O | provider_lookups | split out of the engine's discover step |
| `broken_link_scan` | broken_link_scan | 6 | daily | opportunities | P O | each resource page at most once per 30 days, 20 pages per run | was manual-only in the engine |
| `catalog_import` | catalog_import | 6 | continuous | discovery | P | `max(25, ceil(items/8))` items per run | only while an owner-started import has pending items |
| `ownership_check` | ownership_check | 6 | monthly | monitoring | P O | RDAP limits; each domain at most once per 30 days, 100 per run | RDAP and DNS fingerprints |
| `metrics_refresh` | metrics_refresh | 6 | weekly | evaluation | P O | provider_lookups | |
| `content_gaps` | content_gaps | 7 | weekly | content_gaps | P | local plus provider limits; skipped when inputs are unchanged | |
| `resource_build` | resource_build | 7 | weekly | resource_drafts | P (+S C:resource_publish A for the publish step) | resources_drafted, resources_published | capped (§7.6) |
| `resource_review` | resource_review | 7 | monthly | content_gaps | P O | each resource at most once per 30 days | maintenance |
| `site_audit` | site_audit | 7 | weekly | seo | none (own host only) | incremental: only posts modified since the last full audit; a full audit at most weekly | was daily in the engine |
| `internal_links` | internal_links | 7 | weekly | seo | P (+S C:internal_links A for applying) | `seo.auto_apply_per_day`; suggestions only for posts changed since the last run | |
| `cleanup` | cleanup | 9 | daily | (always) | none | batches within the tick budget | §11.4; cannot be off |
| (internal) `self_heal` | none, runs inline | — | every tick | | | 200 ms | §5.7; not configurable |

### 5.2 Due logic

- `lga_tasks.next_run_at` is the single source of truth. On enqueue: `last_run_at = now`, `next_run_at = Frequency::next(freq, now, anchor)`.
- `15min`, `hourly`, `6h`: `now + interval`.
- `daily`: the next occurrence of the anchor time in the site time zone (`wp_timezone()`). Default anchor = 01:00 plus `crc32(task) % 360` minutes, which spreads daily tasks over 01:00-07:00 and keeps them out of busy daytime hours.
- `weekly`: the next anchor weekday (default Monday) at the anchor time.
- `monthly`: next month's anchor day (default `crc32(task) % 28 + 1`) at the anchor time; when the anchor day is beyond the month's length, the last day. Built with `DateTimeImmutable` in the site zone (`modify('first day of next month')`, then `setDate(Y, m, min(day, t))`), so DST changes and month ends are handled.
- `continuous`: considered on every tick; enqueued only when the due-check returns true and no job of that type is pending (`active_key`).
- A missed run (site down, cron missing) runs once when it comes due; runs are never caught up one by one.
- Changing a task's frequency sets `next_run_at = min(current next_run_at, now + new interval)`; `off` clears it. `POST /automation/tasks/{task}/run` and `wp linkgrowth tasks run <task>` enqueue at once without touching the schedule.
- Gates applied before enqueue by `TaskRegistry::gate(TaskDefinition, Context)`, in order, each with its reason code:
  1. `automation.enabled` (`lga_paused_master`);
  2. module flag (`lga_module_off`);
  3. `requiresProfile` (`lga_profile_incomplete`);
  4. `requiresSetup` (`lga_setup_incomplete`);
  5. consent (`lga_consent_missing`);
  6. for `autopilot` tasks started by the scheduler, `Autopilot::allows()` (`lga_autopilot_off`);
  7. LoadGuard pause (no enqueue while paused, `lga_paused_load`);
  8. limit pool and decision budget have room (`lga_limit_reached`);
  9. due-check.

  The same gates are checked again when a claimed job starts, so a job queued before a switch changed does not run past it. `Context::manual()` (Run now, Grow now, `wp linkgrowth tasks run`) skips only step 6 and the due-check.
- The tick interval must be at most 900 s; the settings validator refuses a larger value while any task is set to `15min`.

### 5.3 Daily, weekly and monthly limits

`Support\Limits` moves to the `lga_counters` table and gains a monthly period. `reserve(string $counter, int $n = 1): bool` is atomic across processes:

```
for each period p in [m, w, d] with limit L_p > 0 (and for display also periods without limits):
    INSERT IGNORE (counter, p, period_key(p), used 0)
    if L_p > 0:
        UPDATE ... SET used = used + n WHERE counter=? AND period=? AND period_key=? AND used + n <= L_p
        if rows_affected == 0: compensate the periods already incremented (used = used - n) and return false
    else:
        UPDATE ... SET used = used + n WHERE …
return true
```

`release(counter, n)` refunds (for example a search the provider did not bill). `remaining(counter)` is the minimum over the three periods. `snapshot()` returns used/limit for each period, for the dashboard meters. Period keys use the site time zone. Rows older than 13 months are pruned.

Default limits (owner-editable; 0 = no limit for that period):

| Counter | Daily | Weekly | Monthly |
|---|---|---|---|
| `discovery_queries` | 15 | 80 | 300 |
| `provider_lookups` | 60 | 300 | 1,000 |
| `evaluations` | 40 | 200 | 800 |
| `backlink_checks` | 150 | 0 | 4,000 |
| `submissions` | 5 | 20 | 60 |
| `outreach_drafts` | 10 | 40 | 120 |
| `outreach_emails` | 10 | 40 | 120 |
| `distribution_posts` | 3 | 12 | 40 |
| `resources_drafted` | 1 | 2 | 6 |
| `resources_published` | 1 | 1 | 2 |
| `url_submissions` | 200 | 0 | 0 |
| `ai_requests` | 30 | 0 | 600 |

Per-provider counters (`provider:<id>:req`, `provider:<id>:units`) use the same table with periods `i`, `h`, `d` and `m`, filled from each provider's declared limits and the owner's overrides (§6.2).

### 5.4 Queue, retries and backoff

- **Enqueue**: `Jobs::enqueue(type, payload, priority, delay, maxAttempts, task)` computes `active_key = sha1(type . canonical_json(payload))`. A duplicate-key error means the same job is already queued or running; the call returns the existing id. On completion, failure or cancel, `active_key` becomes NULL.
- **Claim**: unchanged atomic compare-and-set (`claimNext`), `ORDER BY priority ASC, scheduled_at ASC`. The decision engine may lower or raise a job's priority by at most 2 (§8.4).
- **Error classes** (`Automation\ErrorClass`), set by `ErrorClassifier` from HTTP status, provider error codes and exception types:

| Class | Typical cause | Retry | Provider effect |
|---|---|---|---|
| `transient` | 0, 408, 425, 5xx, timeout, DNS failure | yes, backoff | circuit breaker counts it |
| `rate_limited` | 429, provider "too many requests" | yes, at `max(Retry-After, backoff)` | provider paused until that time |
| `quota` | monthly or daily credits exhausted | at the next quota reset | provider paused until reset |
| `auth` | 401, 403 with an auth error, invalid key | no | provider paused, `credential_status = invalid`, one notification; cleared by a successful connection test |
| `expired` | OAuth token expired and cannot refresh | no | `credential_status = expired`, re-connect notice |
| `permanent` | other 4xx, validation errors | no | |
| `unsupported` | operation not in `operations()` or plan lacks it | no | logged as `unsupported` in activity |
| `policy` | refused by Policy (never-automate class, missing authorization) | no | |
| `db` | `$wpdb->last_error` set | yes once | health `database` check |

- **Backoff** (`Support\Backoff::delay(int $attempt, int $base, int $cap, ?int $retryAfter = null): int`): full jitter, `max(retryAfter ?? 0, random_int(base, min(cap, base * 2 ** attempt)))`. Defaults: jobs base 120 s, cap 6 h; provider circuit base 60 s, cap 24 h; submissions base 1 h, cap 24 h, Retry-After honored up to 7 days; IndexNow base 1 h, cap 7 days (keeps the engine's behavior). Tests use a seeded random source.
- **Max attempts per job type** (`JobInterface::maxAttempts()`): provider-backed jobs 5, crawl jobs 3, `submit` 3 (with the engine's rule that a request that may have arrived is never retried), `outreach_send` 1, `distribution_send` 1 (plus an `Idempotency-Key` where the platform supports it), everything else 3.
- **Dead letter and requeue**: a job that exhausted its attempts, or failed with a non-retryable class, stays `failed`. `Automation\FailedJobRecovery` (inside self-heal, at most 10 per tick) requeues failed jobs whose class is `transient`, `rate_limited` or `quota` once the provider's circuit is closed and the reset time has passed, with `attempts = 0` and `requeues + 1`; after 2 requeues the job stays failed and one notification is sent. Manual: `POST /automation/jobs/{id}/retry`, `POST /automation/jobs/retry-failed` (`type`, `error_class` filters), `POST /automation/jobs/purge` (`status=failed|completed`, `older_than_days`), and the matching `wp linkgrowth queue` commands.
- **Job error text** goes through `Redactor` before it is stored (`jobs.error`, `jobs.result`, `lga_tasks.last_error`, runner log rows). The engine's `ppclg_stage_results` option is not carried over (§4.4).
- No infinite loops: attempts cap, requeue cap, the continuation cap (`MAX_CONTINUATIONS = 100`), the per-tick budget, and the circuit breaker (§6.2) together bound every retry path; a test asserts each bound.

### 5.5 Runners

Three ways to run the tick; all call `Pipeline::tick(TimeBudget)` under the runner lock (`Locks::acquire('runner', ttl)`), honor LoadGuard and record a heartbeat.

| Mode (`automation.runner`) | What schedules the tick | When to use |
|---|---|---|
| `auto` (default) | Action Scheduler recurring action `lga_tick` (group `lga`) when available, else WP-Cron event `lga_tick`. Server-cron hits are also accepted and recorded | any site; works without shell access but only when visitors or WP-Cron trigger it |
| `server_cron` | the owner's system cron through crontab line A (WP-CLI) or B (HTTPS). The AS/WP-Cron recurring tick is unscheduled, so lines C and D of §5.6 do nothing for this plugin in this mode, and only heartbeats with source `cli` or `http` count. If `server_cron_fallback` is on and the last `cli`/`http` heartbeat is older than 3 × `server_cron_interval`, the scheduler re-adds the AS/WP-Cron tick as a fallback and the health check `runner` turns critical ("server cron has not run since …"). When `cli`/`http` heartbeats return, the fallback is removed again | recommended for production, especially with page caching or low traffic |
| `wp_cron` | WP-Cron only, even when Action Scheduler exists | diagnostics |

`Scheduler::maybeSchedule()` keeps its hourly self-check, driven by `lga_runtime.schedule_checked_at`, but only runs on admin, cron, CLI and our REST requests (§11.3).

**Runner log (execution history).** Every tick, whatever its runner, writes one `lga_logs` row with `channel = 'runner'`, `level = info|warning|error` and `context = {source: cli|http|wp_cron|as|manual|growth, started_at, duration_ms, jobs_run, jobs_failed, tasks_enqueued[], status: ran|skipped|busy|paused|error, paused_reason, budget_s, memory_peak_kb}`. Ticks that only find nothing due are aggregated: consecutive `skipped` or `ran` rows with `jobs_run = 0` from the same source within one hour update the previous row's `count` and `last_at` instead of adding a row. A busy site therefore writes at most about 12 idle rows a day per source, plus one row per tick that did work. The rows are kept for `advanced.log_retention_days` like other logs.

`GET /automation/runs?source=&status=&limit=50` returns the newest rows (at most 100). The Automation page shows "Recent runs" (the last 50: time, source, duration, jobs run or failed, status and paused reason), and `wp linkgrowth status` prints the last 10. Covered by I `scheduler` (one row per working tick; idle ticks aggregated; a paused tick records `paused_reason`; redaction of errors).

### 5.6 Server cron: HTTPS endpoint, WP-CLI and crontab lines

**HTTPS endpoint** (`Runner\ServerCron`, `Security\CronToken`):

- Route: `POST /wp-json/lga/v1/cron/run` (also reachable as `/?rest_route=/lga/v1/cron/run` on sites without pretty permalinks). GET returns 405.
- Token: `CronToken::rotate()` makes 32 random bytes (`random_bytes`), base64url-encoded (43 characters). Only `sha256(token)` is stored, in `lga_cron_token` with `created_at`, `created_by` and the last 4 characters for display. The token is shown once (wizard step 9, Automation page "Rotate token", or `wp linkgrowth cron-token rotate`). Rotation invalidates the old token at once. `LGA_CRON_TOKEN` in `wp-config.php` overrides the stored value (compared the same way). Without a token the endpoint returns 403 `lga_cron_disabled`.
- Accepted carriers: header `X-LGA-Cron-Token: <token>` (preferred), `Authorization: Bearer <token>`, or a form-encoded POST body field `token`. A `token` or `lga_token` **query-string** parameter is refused with 400 `lga_token_in_query` before any comparison, and a warning (without the value) is logged, because query strings end up in access logs.
- Verification: `hash_equals($stored, hash('sha256', $provided))` in the REST `permission_callback`.
- Rate limits: failed attempts per client IP (REMOTE_ADDR, or X-Forwarded-For only from `advanced.trusted_proxies`): more than 10 in 10 minutes gives 429 with `Retry-After`. Accepted calls: at most 6 per minute per IP; and a global minimum gap of 30 s between runs (`lga_cron_heartbeat.at`), else 200 `{"status":"skipped","reason":"too_soon"}`.
- Execution: `ignore_user_abort(true)`; `set_time_limit(seconds + 15)` where allowed; budget `min(automation.max_execution_seconds, 55)`; runner lock (busy gives `{"status":"busy"}`); LoadGuard (paused gives `{"status":"paused","paused_reason":"load"}`); `Pipeline::tick`; heartbeat `{at, source: 'http', duration_ms, jobs_run, status}`.
- Response: `{"ok":true,"status":"ran|skipped|busy|paused","jobs_run":3,"duration_ms":18234,"paused_reason":null,"next_due_in":120}`. No paths, versions, settings, counts by type or error text.
- Headers: `nocache_headers()`, `Cache-Control: no-store, no-cache, must-revalidate, private`, `X-Robots-Tag: noindex, nofollow`; `DONOTCACHEPAGE` defined.
- The endpoint takes no task, payload or option: it only runs the scheduled tick (spec §12 "no unauthorized execution").

**WP-CLI** (`Cli\LinkGrowthCommand`, registered with `WP_CLI::add_command('linkgrowth', …)` only when `defined('WP_CLI') && WP_CLI`):

| Command | Behavior |
|---|---|
| `wp linkgrowth run [--seconds=<n>] [--task=<id>] [--quiet]` | Runs ticks in a loop for up to `n` seconds (default `automation.cli_max_seconds` 240, max 900), each tick with a sub-budget of at most 60 s, under the runner lock; stops early when nothing is due, the lock is busy or LoadGuard pauses. `--task` enqueues that task first (if enabled). Records the heartbeat with source `cli`. Exit 0 on success (also when busy or paused, so cron stays quiet), 1 on error. Without `--url` on multisite it runs only the main site and says so unless `--quiet` |
| `wp linkgrowth run --network [--seconds=<n>] [--per-site=<n>] [--quiet]` | Multisite only (error on a single site). Iterates `get_sites(['archived'=>0,'spam'=>0,'deleted'=>0,'number'=>0,'fields'=>'ids'])` in order of the oldest heartbeat first. Sites where the plugin is not active, or setup is not completed and nothing is due, are skipped. Each site runs in its **own process**, `WP_CLI::runcommand("linkgrowth run --seconds=<b> --quiet", ['launch' => true, 'exit_error' => false, 'command_args' => ['--url=' . $siteUrl]])`, so no container state crosses sites. The per-site budget is `b = --per-site` or `max(20, floor(n / sites))`, and the loop stops when `n` (default 600, max 3,000) is used up. Exit 1 if any site returned 1 |
| `wp linkgrowth status [--format=table\|json]` | Runner mode, heartbeat, last tick, next run per task, queue counts, paused reason |
| `wp linkgrowth health [--format=table\|json]` | All health checks (§5.8); exit 0 ok, 1 warning, 2 critical |
| `wp linkgrowth tasks list` / `tasks run <task>` / `tasks set <task> <frequency>` | Task table, run now, change frequency (validated like the REST route) |
| `wp linkgrowth queue list [--status=] [--type=] [--limit=]` / `queue retry <id>...` / `queue retry --failed [--type=]` / `queue purge --failed\|--completed [--older-than=<days>] [--yes]` | Queue tools |
| `wp linkgrowth cron-token rotate [--porcelain]` / `cron-token disable` / `cron-token status` | Token management (prints the token once) |
| `wp linkgrowth providers list` / `providers test <id>` | Provider state and connection test |
| `wp linkgrowth diagnostics` | The redacted environment report (§11.5) |

**Crontab lines** (in `docs/SERVER-CRON.md`, and filled in by wizard step 9 and the Automation page with this site's path, URL and names). Several sites on one server must not share a lock or a token file. So every name carries the site: `<sitehash>` is the first 10 hex characters of `sha1(home_url())`, and `<host>` is `home_url()`'s host with any port as `-<port>`. Both are computed by `Runner\CronLines::forSite()` and shown already filled in.

```cron
# A. WP-CLI (recommended; runner mode server_cron or auto). Every 5 minutes; flock prevents overlaps of THIS site only.
*/5 * * * * cd /var/www/example.com/htdocs && flock -n /tmp/lga-run-<sitehash>.lock nice -n 10 wp linkgrowth run --seconds=240 --quiet >/dev/null 2>&1

# A-network. Multisite: one line for the whole network (each site runs in its own process, §5.6 table).
*/5 * * * * cd /var/www/example.com/htdocs && flock -n /tmp/lga-run-network-<sitehash>.lock nice -n 10 wp linkgrowth run --network --seconds=600 --quiet >/dev/null 2>&1
# A-network without the --network command (any WP-CLI version): one run per site, sequentially.
*/10 * * * * cd /var/www/example.com/htdocs && flock -n /tmp/lga-run-network-<sitehash>.lock sh -c 'wp site list --field=url --archived=0 --spam=0 --deleted=0 | xargs -I{} nice -n 10 wp --url={} linkgrowth run --seconds=60 --quiet' >/dev/null 2>&1

# B. HTTPS endpoint (hosts without shell or WP-CLI; runner mode server_cron or auto). The token file is per site, chmod 600.
*/5 * * * * curl -fsS -m 90 -X POST -H "X-LGA-Cron-Token: $(cat $HOME/.lga-cron-token-<host>)" https://example.com/wp-json/lga/v1/cron/run >/dev/null 2>&1
# wget variant
*/5 * * * * wget -q -O /dev/null --timeout=90 --post-data='' --header="X-LGA-Cron-Token: $(cat $HOME/.lga-cron-token-<host>)" https://example.com/wp-json/lga/v1/cron/run

# C and D: ONLY for runner mode "auto". In server_cron mode the plugin's AS/WP-Cron tick is unscheduled,
# so these lines do not run this plugin (use A or B instead).
# C. Run all of WordPress's cron events from the system cron (also covers other plugins).
#    Put define( 'DISABLE_WP_CRON', true ); in wp-config.php first.
*/5 * * * * cd /var/www/example.com/htdocs && wp cron event run --due-now --quiet
# D. Only the Action Scheduler queue for this plugin.
*/5 * * * * cd /var/www/example.com/htdocs && wp action-scheduler run --group=lga --quiet
```

The wizard shows lines C and D only when the selected runner mode is `auto`. The `POST /automation/cron/mode` route refuses `server_cron` with `lga_cron_lines_mismatch` when the only heartbeats seen in the last 24 hours have source `wp_cron` or `as` and the owner has not passed `confirm: true`. Docs also cover:
- hosting control panels' cron UIs (paste line B);
- managed hosts that already run `wp-cron.php` from a system cron (choose mode `auto` with line C);
- caching and CDN exclusions for `/wp-json/lga/v1/*` (§11.3);
- how to read the heartbeat and the Recent runs list on the dashboard.

Tests: I `server-cron` checks that two sites' generated lines differ in lock and token names, and that in `server_cron` mode a `wp_cron`/`as` heartbeat does not clear the missing-heartbeat state. I `wp-cli` checks `run --network` on the multisite build (§12.1), with each site getting a `cli` heartbeat and a single site refusing `--network` (real WP-CLI or the shim, reported as such).

### 5.7 Self-healing step (every tick, at most 200 ms)

1. `Jobs::recoverStale(900)` (running jobs older than 15 minutes go back to the queue).
2. Release locks past their TTL (Locks already expires them; this also clears `lga_evaluate_inflight` entries older than 1 hour).
3. `FailedJobRecovery::run(10)` (§5.4).
4. Half-open probes are not run here; the next real call through `ProviderGateway` is the probe (§6.2).
5. If `Schema::existingTables()` is missing any table (checked at most hourly), run the Migrator again (with its back-off).
6. Re-add the recurring tick if it is missing in `auto` mode (the engine's hourly self-check).
7. If more than 24 hours passed since the last `cleanup`, enqueue it.

### 5.8 Health monitoring

`Health\HealthMonitor::run(): array<Check result>` runs every 15 minutes (`health_check` task), on the dashboard (cached for 60 s), in Site Health, through `GET /automation/health` and in `wp linkgrowth health`. Each check returns `{id, status: ok|warning|critical, label, message, evidence{}, action{label, url}}`.

| Check id | Warning | Critical |
|---|---|---|
| `runner` | last tick older than 3 × tick interval; or, in `auto` or `wp_cron` mode, no `cli`/`http` heartbeat (`lga_cron_heartbeat_server`) in the last 7 days and the plugin active for more than 7 days: "No server cron heartbeat in 7 days. WP-Cron depends on visits, so work can stall on quiet sites or behind page caches." The action links to the crontab lines on the Automation page and to SERVER-CRON.md | older than 12 × tick interval; or `server_cron` mode with no `cli`/`http` heartbeat for 3 × `server_cron_interval`; or no token in `server_cron` mode with line B |
| `action_scheduler` | more than 5 past-due `lga` actions, or more than 3 failed `lga` actions in 24 h | AS store errors |
| `queue` | more than 200 due jobs, or the oldest due job older than 6 h, or failure rate above 20 % over 24 h (at least 10 jobs) | oldest due job older than 24 h |
| `locks` | runner lock held longer than 2 × the maximum run (auto-released by self-heal) | |
| `providers` | an enabled provider with `auth`/`expired` credentials, an open circuit for more than 24 h, or a LinkedIn/Facebook token expiring within 7 days | |
| `database` | a `$wpdb->last_error` captured in the last hour | a table missing, or the migration failing |
| `limits` | a monthly limit reached (info level shown as warning in the widget) | |
| `secrets` | a stored secret that no longer decrypts (salts changed); the provider shows `credentials_unreadable` | |
| `setup` | setup not completed | |
| `environment` | PHP newer than the tested range; object cache missing on a large site; page-cache plugin without server cron; the cURL extension missing (outbound crawling and provider calls are refused, §11.1); an HTTP proxy configured (`WP_PROXY_HOST`: DNS pinning replaced by host checks, §11.1); another IndexNow implementation active (§1.4) | PHP below 8.1 (the plugin does not boot) |
| `coexistence` | a known conflicting plugin active, or another plugin using the `lga/v1` namespace, `lga_tick` hook or `lga_` tables (§1.4) | |
| `opcache` | (kept from the engine) | |

Notifications fire once per transition to warning or critical and once on recovery (`lga_health_state` keeps the last state per check; dedupe key `health:<id>:<status>`). Critical ones are emailed when `notifications.critical_immediately` is on and the `health_degraded` (or `cron_missing` for `runner`) event is enabled.

Site Health (`Health\SiteHealthTests`): direct tests `lga_runner`, `lga_queue`, `lga_providers`, `lga_database` and `lga_opcache`, each mapping warning to `recommended` and critical to `critical`.

### 5.9 LoadGuard and visitor protection

LoadGuard is kept unchanged apart from the rename (OPcache restart, backup running, 10-minute post-update window, load above 2 per CPU, less than 10 % memory free, `lga_pause_background` filter). Added:

- `Scheduler::kick()` never runs on front-end requests: it is allowed from admin screens, our REST namespace, cron and CLI only, and never on WooCommerce cart, checkout or account pages or Store API (`/wc/store/`) requests.
- Tick work never runs inline in a visitor request; WP-Cron loopbacks are the only front-end-triggered path, and `server_cron` mode removes even that.
- Per-tick budget (default 20 s HTTP, 240 s CLI) and the engine's per-host crawl delay stay.

---

## 6. Provider adapter contract

### 6.1 Interfaces

Every external service (data, search, analytics, URL submission, acquisition, distribution, AI) implements the base contract plus the operation interfaces it supports.

```php
namespace LinkGrowth\Autopilot\Integrations\Framework;

interface Provider {
    public function id(): string;                         // 'bing_webmaster'
    public function label(): string;
    /** data | search | analytics | url_submission | acquisition | distribution | ai */
    public function kind(): string;
    /** acquisition/distribution only: api | directory | publication | listing | social | distribution | syndication | owned | webhook */
    public function channel(): ?string;
    /** live | sandbox | mock (mock only registers when LGA_ALLOW_LOCAL_HOSTS is honored) */
    public function mode(): string;
    /** @return list<CredentialField> */
    public function credentialSchema(): array;
    public function isConfigured(): bool;                 // all required credentials present and decryptable
    /** @return list<string> e.g. ['backlinks','ref_domains','metrics','top_pages'] */
    public function operations(): array;
    /** Scopes or plan features each operation needs, e.g. ['publish' => ['w_member_social']] */
    public function requiredPermissions(): array;
    /** @return array{per_minute?:int,per_hour?:int,per_day?:int,per_month?:int,units_per_month?:int,unit_cost?:array<string,int>} */
    public function declaredLimits(): array;
    /** Live check: auth, granted scopes, quota/balance when the API reports it. */
    public function testConnection(): ConnectionResult;
    /** @return array{docs_url:string, terms_url:string, automation_allowed:bool, notes:string} */
    public function terms(): array;
}

final class CredentialField {
    public function __construct(
        public string $key,        // secret key in Secrets, e.g. 'bing_webmaster_api_key'
        public string $label,
        public string $type,       // secret | text | url | select | oauth | json
        public bool   $required = true,
        public string $help = '',
        public ?string $pattern = null,
        public ?string $constant = null   // LGA_SECRET_BING_WEBMASTER_API_KEY
    ) {}
}

final class ConnectionResult {
    public function __construct(
        public string $status,          // connected | error | expired | rate_limited | unsupported
        public array  $grantedScopes = [],
        public ?array $quota = null,    // e.g. ['remaining' => 940, 'resets_at' => '…']
        public ?string $expiresAt = null,
        public string $message = '',    // redacted
        public string $errorClass = ''
    ) {}
}
```

Operation interfaces (`Integrations\Contracts\*`):

```php
interface MetricsProvider   { public function domainMetrics( string $domain ): ?array; }
interface BacklinkSource    { public function backlinks( string $domain, int $limit = 200, int $perDomain = 3 ): array; }
interface RefDomainsSource  { public function referringDomains( string $domain, int $limit = 200 ): array; }
interface TopPagesSource    { /** pages of $domain ordered by referring domains */ public function topPagesByLinks( string $domain, int $limit = 50 ): array; }
interface CompetitorSource  { public function competitors( string $domain, int $limit = 20 ): array; }
interface SearchProvider    { public function search( string $query, array $options = [] ): array; }
interface AnalyticsSource   { /** @return list<array{day:string,dim:string,dim_value:string,metric:string,value:float}> */
                              public function fetch( array $metrics, string $from, string $to, array $dims = [] ): array; }
interface UrlSubmitter      { public function submitUrls( array $urls ): SubmitResult; }
interface AcquisitionAdapter {
    public function supports( array $opportunity, array $package ): bool;
    public function prepare( array $opportunity, array $package ): array;           // payload preview for approval
    public function submit( array $submission, array $prepared ): OperationResult;
    public function status( array $submission ): OperationResult;                   // poll provider-side status where supported
}
interface DistributionAdapter {
    public function compose( array $resource, array $options ): array;              // message within the platform's limits
    public function publish( array $distribution ): OperationResult;
    public function status( array $distribution ): OperationResult;
    public function remove( array $distribution ): OperationResult;                 // only where the API allows deletion
}

final class OperationResult {
    /** sent_unconfirmed | provider_confirmed | handed_off | failed | needs_manual | needs_owner_verification | unsupported | rate_limited | auth_error | refused_policy */
    public function __construct(
        public string $state,
        public string $remoteId = '',
        public string $remoteUrl = '',
        public bool   $sent = false,       // a request reached the destination (spends limits)
        public bool   $retryable = false,
        public ?int   $retryAfter = null,
        public string $errorClass = '',
        public string $message = '',       // redacted
        public array  $response = []       // redacted, size-capped (16 KB)
    ) {}
}
```

**Extension filters** (`Integrations\ProviderRegistry`, `Submission\AdapterRegistry`, `Distribution\AdapterRegistry`). Each returns a list of objects; entries that do not implement the expected interface are dropped and logged once.

| Filter | Receives | Replaces in the engine |
|---|---|---|
| `lga_providers` | every `Provider` (data, search, analytics, URL submission, AI); the registry builds the typed lists below from it by interface | (new) |
| `lga_metrics_providers` | `MetricsProvider` list, in priority order | `ppclg_metrics_providers` |
| `lga_search_providers` | `SearchProvider` list | `ppclg_search_providers` |
| `lga_backlink_sources` | `BacklinkSource` / `RefDomainsSource` list | `ppclg_backlink_sources` |
| `lga_acquisition_adapters` | `AcquisitionAdapter` list (each also a `Provider` with kind `acquisition`) | `ppclg_submission_adapters` (`AdapterRegistry::FILTER`) |
| `lga_distribution_adapters` | `DistributionAdapter` list (each also a `Provider` with kind `distribution`) | (new) |

A provider or adapter added through any filter gets the full treatment: its own `lga_provider_state` row, a card on the Providers page (connection status, credentials from its `credentialSchema()`, permissions, operations, limits, activity), and every call goes through `ProviderGateway` (enabled check, scopes, rate limits, circuit breaker, activity log). The developer section of `docs/PROVIDERS.md` documents each filter with a minimal example class. Test: U `ProviderExtensionTest`. A fixture provider added through `lga_providers` and a fixture adapter through `lga_acquisition_adapters` appear in `GET /providers`. A call while the provider is disabled is refused (`refused_policy`), a call above its declared per-minute limit gets `rate_limited`, and each call writes one activity row.

**Configured partner APIs, one provider per destination.** Each `ConfiguredApiAdapter` destination is registered as its own provider instance with id `partner_api:<prospect_id>`, label "Partner API: {destination host}", kind `acquisition`, channel `api`. It has its own `lga_provider_state` row, its credentials (`partner_api_<prospect_id>_*` in Secrets), its declared limits (from the destination's documentation, entered with the configuration), its operations (`submit`, plus `status` when a status endpoint is configured) and its activity history. Each appears as a separate card in the Providers page's "Partner APIs" group, with Edit configuration, Test (a dry-run request only when the destination documents a validation endpoint; otherwise "Test not supported by this destination"), Enable/disable, Limits and Activity. Removing a configuration archives its state and activity rows instead of deleting them.

### 6.2 Provider gateway: limits, circuit breaker, activity

All calls go through `ProviderGateway::call(Provider $p, string $operation, callable $fn, array $ctx = []): mixed`:

1. Refuse with `unsupported` when `$operation` is not in `operations()`; with `refused_policy` when the provider is disabled, not configured, the required scopes are not in `granted_scopes`, or (for acquisition and distribution) Policy refuses.
2. Circuit breaker (`CircuitBreaker`, state in `lga_provider_state`): `closed` lets calls through. After 3 consecutive `transient` failures, or one `rate_limited`, it moves to `open` with `open_until = now + Backoff::delay(open_count, 60, 86400, retryAfter)`. When `open_until` has passed, the first caller atomically flips it to `half_open` (`UPDATE … SET circuit_state='half_open' WHERE provider=? AND circuit_state='open' AND open_until <= ?`, only the process that changed the row probes) and makes one call: success closes it and resets the counters; failure re-opens it with `open_count + 1`. While open, calls return `skipped_circuit` immediately and jobs are rescheduled for `open_until`.
3. Rate limiter (`RateLimiter`): reserves `provider:<id>:req` in periods minute, hour, day and month, and `provider:<id>:units` by the operation's unit cost, against `declaredLimits()` merged with `providers.<id>.limits`. No budget gives `rate_limited` with the time of the next window.
4. Run `$fn` with the provider's HTTP client (`Support\Http\Client`, through UrlGuard, §11.1). Parse `Retry-After` (seconds or HTTP date) and provider quota headers.
5. Classify the outcome (`ErrorClassifier`), update `lga_provider_state` (connection and credential status, last error, last success, quota), write one `lga_provider_activity` row (redacted message, duration, units, outcome), and notify once on an auth or expiry failure.

Each provider declares its limits and unit costs; the owner can lower them. The Providers page shows remaining quota per provider and the activity aggregates (requests, successes, failures, last activity) for 24 h, 7 days and 30 days.

### 6.3 Credentials and OAuth

- Secrets stay encrypted (`Support\Crypto`: sodium secretbox, or OpenSSL AES-256-GCM, keys derived from `AUTH_KEY`/`SECURE_AUTH_KEY` with the context `lga-credentials-v1`). `LGA_SECRET_<KEY>` (and on multisite `LGA_SECRET_<BLOGID>_<KEY>`) override stored values. REST returns only `{set: bool, masked: '••••abcd', source: 'stored'|'constant'}`.
- When a stored secret no longer decrypts, `Secrets::get()` returns null, the provider state becomes `credentials_unreadable`, and the health check `secrets` asks the owner to re-enter it.
- **OAuth** (LinkedIn, Facebook): `Integrations\Framework\OAuthClient` handles the authorization-code flow. `GET /providers/{id}/oauth/start` (capability `lga_manage`) returns the authorization URL with a `state` from `Security\OAuthState` (32 random bytes, stored in transient `lga_oauth_<state>` with user id and provider, 10-minute expiry, single use). The redirect URI is `admin-post.php?action=lga_oauth_callback`, because a browser redirect from the provider carries the WordPress login cookie but no REST nonce, so a REST callback could not authenticate the user. The callback checks the capability and `state`, exchanges the code (client secret from Secrets), stores tokens encrypted with `expires_at`, and redirects to the Providers page. Refresh happens before expiry where the platform issues refresh tokens; otherwise the health check warns 7 days ahead.

### 6.4 Permissions and authorizations

Two kinds of permission are tracked and shown on the Providers page:

- **Provider permissions**: the scopes or plan features the connection actually has (`granted_scopes`, from the token response or the connection test). An operation whose required permissions are missing is refused with `refused_policy` and the message names the missing scope.
- **Destination authorizations** (`lga_authorizations`, `Submission\Authorizations`): what a third-party destination has allowed, with evidence. Created by the owner on the Prospects or Acquisition page (`POST /authorizations`) with `evidence_type` (`api_scope`, `terms_allow`, `written_permission`, `owner_account`, `partner_agreement`), an evidence URL or note, optional expiry, and the operations allowed. Every automated submission to a destination must reference an active authorization (`submissions.authorization_id`); revoking one stops queued submissions to that destination.

### 6.5 Success semantics

The plugin never reports success without the provider's confirmation (spec §4).

```
draft / prepared ──approve──> queued ──send──> sent_unconfirmed ──provider id or confirmation──> provider_confirmed ──link found live──> live_verified
                                     └──────> handed_off (webhook delivered; not a success) ──signed callback──> provider_confirmed
                                     └──────> failed | needs_manual | needs_owner_verification | refused_policy | canceled
```

- `sent_unconfirmed`: a request reached the destination, but the response gave no confirmation ID (for example a form "thank you" page). Counted as "pending", never as "successful".
- `provider_confirmed`: the provider returned a confirmation ID, a created object ID or a URL (`OperationResult.remoteId`/`remoteUrl`). For API adapters a `success_path` or confirmation field is mandatory; `ConfiguredApiAdapter` refuses to save a configuration without one.
- `live_verified`: our link (or the published post) was fetched and found. Only this state counts as an acquired backlink in KPIs and in the decision engine.
- Owner-reported submissions (`mark-submitted`) are `sent_unconfirmed` with `actor = user:<id>`.
- Simulated providers can never produce `provider_confirmed` or `live_verified` rows with `is_simulated = 0`; a unit test enforces this.

### 6.6 Adapter catalogue

**FULL** = implementable with the public API and the owner's own credentials, and testable end to end against fixtures. **DISCOVERY-ONLY** = the plugin finds, records and tracks the opportunity and prepares an owner task, but does not automate the action, for the reason given.

#### Data and analytics providers

Endpoint names, scopes and limits below come from each provider's public documentation as known when this design was written. The implementer of each adapter re-checks them against the current documentation, records the documentation URL in `docs/PROVIDERS.md`, and keeps version strings (LinkedIn-Version, Graph API version, GA4 API version) in one constant per adapter.

| Adapter | Class | Status | Credentials | Operations | Endpoints used | Limits and notes |
|---|---|---|---|---|---|---|
| Google Search Console | `Google\SearchConsole` (X) | FULL | service account JSON added as a user on the property, or Site Kit bridge (interactive only) | `search_analytics` (dimensions date, query, page, country, device), `url_inspection` (own pages only), `sitemaps_list` | `POST https://www.googleapis.com/webmasters/v3/sites/{site}/searchAnalytics/query`; `POST https://searchconsole.googleapis.com/v1/urlInspection/index:inspect`; `GET …/sites/{site}/sitemaps` | scope `webmasters.readonly`; URL inspection quota per property (about 2,000/day, 600/min) declared as limits; background jobs use the service account (the Site Kit bridge needs a logged-in user, so it only serves dashboard reads) |
| Google Analytics 4 | `Google\Analytics4` (X) | FULL | service account with Viewer on the property, property id | `referrals`, `key_events`, `revenue`, `landing_pages`, `channels` | `POST https://analyticsdata.googleapis.com/v1beta/properties/{id}:runReport` with dimensions `date`, `sessionSource`, `sessionMedium`, `sessionDefaultChannelGroup`, `landingPagePlusQueryString`; metrics `sessions`, `activeUsers`, `keyEvents`, `ecommercePurchases`, `purchaseRevenue` | scope `analytics.readonly`; token quotas per property; revenue shown only with currency from the property |
| Bing Webmaster Tools | `Bing\WebmasterTools` (N) | FULL | API key generated in Bing Webmaster Tools (Settings, API access) for a verified site | `submit_urls`, `url_submission_quota`, `own_backlinks` (link counts and inbound links per page), `traffic` (query, page, rank-and-traffic stats) | `https://ssl.bing.com/webmaster/api.svc/json/{Method}?apikey=…`: `SubmitUrlBatch`, `GetUrlSubmissionQuota`, `GetLinkCounts`, `GetUrlLinks`, `GetQueryStats`, `GetPageStats`, `GetRankAndTrafficStats`, `GetUserSites` (connection test) | own verified site only; the key travels in the query string, so the URL redaction rule (§11.2) covers `apikey`; daily and monthly URL quotas read from `GetUrlSubmissionQuota`; URL submission is used for new resources when IndexNow is off |
| IndexNow | `Seo\IndexNow` (R, under the contract) | FULL | none (key generated and served by the plugin) | `submit_urls` | `POST https://api.indexnow.org/indexnow` | 429 back-off 1 h to 7 days (engine behavior kept) |
| Ahrefs | `Providers\Ahrefs` (X) | FULL | API v3 token (requires an Ahrefs plan with API access; consumes API units) | `metrics`, `backlinks`, `ref_domains`, `top_pages` | `https://api.ahrefs.com/v3/site-explorer/*` (domain-rating, backlinks-stats, all-backlinks, refdomains, top pages by links) | unit costs declared per operation; Domain Rating labeled "third-party estimate (not a Google metric)" |
| Semrush | `Providers\Semrush` (X) | FULL | API key (API units) | `metrics`, `backlinks`, `ref_domains`, `competitors` | `https://api.semrush.com/` (`domain_ranks`, `domain_organic_organic` for competitors), `https://api.semrush.com/analytics/v1/` (`backlinks_overview`, `backlinks`, `backlinks_refdomains`) | key in the query string (redacted); database from the country pack |
| Moz | `Providers\Moz` (X) | FULL | Links API access id and secret | `metrics`, `backlinks`, `ref_domains` | `POST https://lsapi.seomoz.com/v2/url_metrics`, `/v2/links`, `/v2/linking_root_domains` (Basic auth) | Domain Authority and Spam Score labeled as Moz's own metrics; plan row limits declared |
| Majestic | `Providers\Majestic` (N) | FULL where licensed | API key from a Majestic API subscription | `metrics` (Trust Flow, Citation Flow, topical Trust Flow, referring domains, external backlinks), `backlinks`, `ref_domains`, `top_pages` | `https://api.majestic.com/api/json?app_api_key=…&cmd=<command>&datasource=fresh` with commands `GetIndexItemInfo`, `GetBackLinkData`, `GetRefDomains`, `GetTopPages`, `GetSubscriptionInfo` | key in the query string (redacted); credit balance from `GetSubscriptionInfo` shown as quota; metrics labeled as Majestic's own, not Google factors |
| SE Ranking | `Providers\SeRanking` (X) | FULL | Data API key | `metrics`, `backlinks`, `ref_domains`, `competitors`, `balance` | `https://api.seranking.com/v1/*` (`Authorization: Token …`) | 429 now opens the circuit (fixes the engine calling again on the next lookup); region from the country pack |
| Brave Search API | `Search\BraveSearch` (R) | FULL | subscription token | `search` | `https://api.search.brave.com/res/v1/web/search` | official search API; daily quota kept; country from the target market |
| Google Programmable Search JSON API | `Search\GoogleCustomSearch` (R, labeled legacy) | FULL for existing keys | API key and engine id | `search` | `https://www.googleapis.com/customsearch/v1` | Google has closed this API to new customers and announced its end; the adapter is kept for owners who still have keys and labeled "legacy"; the implementer re-checks the current status at build time and documents it in LIMITATIONS.md |
| RDAP | `Integrations\Rdap` (R) | FULL | none | `domain_info` | IANA bootstrap and registry RDAP servers | used for age and ownership fingerprints |
| Wayback availability | `Integrations\Wayback` (N, optional) | FULL | none | `snapshot` | `https://archive.org/wayback/available?url=` | only to read the title of a dead page for broken-link matching; off by default |
| WooCommerce order attribution | `Woo\OrderAttribution` (N) | FULL (local) | none | `conversions`, `revenue` | `wc_get_orders()` (HPOS-safe), order meta `_wc_order_attribution_source_type`, `_wc_order_attribution_referrer`, `_wc_order_attribution_utm_source`, `_wc_order_attribution_utm_medium` | aggregates only (per day, per referrer host, per source type); no personal data stored; cells with fewer than 3 orders are merged into "other" |
| AI writer | `Integrations\AiWriter` (G) | FULL (optional) | Anthropic API key; model chosen by the owner from the list the API returns | `rewrite`, `draft` | `POST https://api.anthropic.com/v1/messages`, `GET https://api.anthropic.com/v1/models` | never sees competitor text; output always passes the claims and accuracy checks; no model identifier shipped |
| Serper | removed | — | — | — | — | resells scraped Google results (spec §3D) |

#### Acquisition and distribution adapters

| Adapter | Class | Status | Channel | Credentials | What it does | Confirmation | Limits and notes |
|---|---|---|---|---|---|---|---|
| Authorized form (directory) | `Submission\Adapters\GenericFormAdapter` (X) | FULL, only with an authorization record | directory | none | POSTs a public listing form that passed every policy gate (§6.7) | `sent_unconfirmed` unless the response carries a listing URL or reference; `live_verified` by ListingVerifier | per-destination one submission; global and per-adapter limits |
| Partner API (directory or publication) | `Submission\Adapters\ConfiguredApiAdapter` (X) | FULL | api / publication | per-destination key `partner_api_{prospectId}` | Templated request to an official partner endpoint the owner configures | `provider_confirmed` only from the configured `success_path` or confirmation field (mandatory) | Retry-After honored; unknown outcome after sending goes to `needs_manual` |
| Owner task package | `Submission\Adapters\ManualPackageAdapter` (R) | FULL (manual by design) | any | none | Step-by-step package for an authorized person (also the LinkGrowth Submitter role) | owner marks submitted (`sent_unconfirmed`), ListingVerifier confirms | used for everything not automatable |
| Mastodon | `Distribution\Adapters\Mastodon` (N) | FULL | social | instance URL and an access token with scope `write:statuses` created by the owner | Posts an announcement of our own published resource with a UTM-tagged link | `provider_confirmed` from the returned status `id` and `url`; `live_verified` after fetching the status URL | `POST {instance}/api/v1/statuses` with `Idempotency-Key`; character limit read from `GET /api/v2/instance`; 1 post per resource per account; per-account rate limits honored via headers |
| Bluesky | `Distribution\Adapters\Bluesky` (N) | FULL | social | handle and an app password | Same, with an external link card and a link facet | `provider_confirmed` from the returned record `uri` and `cid` | `com.atproto.server.createSession`, `com.atproto.repo.createRecord` (collection `app.bsky.feed.post`) on the account's PDS (default `https://bsky.social`); 300-grapheme limit; refresh via `refreshSession` |
| LinkedIn | `Distribution\Adapters\LinkedIn` (N) | FULL with the owner's LinkedIn app | social | OAuth client id and secret of the owner's app; member posting needs the self-serve "Share on LinkedIn" product (`w_member_social`); organization pages need the Community Management API product (`w_organization_social`), which LinkedIn grants after review | Posts an announcement as the member or the organization page | `provider_confirmed` from the `x-restli-id` post URN | `POST https://api.linkedin.com/rest/posts` with `LinkedIn-Version` (a monthly version string kept as one constant, verified at build time) and `X-Restli-Protocol-Version: 2.0.0`; access tokens last about 60 days and refresh tokens are only issued to approved partners, so the health check warns before expiry and the page offers "Reconnect" |
| Facebook Pages | `Distribution\Adapters\FacebookPages` (N) | FULL with the owner's Meta app | social | app id and secret; a Page access token obtained through OAuth with `pages_manage_posts` and `pages_read_engagement` (the owner as app admin can use them in development mode for their own pages; App Review is required before other people's pages could be used) | Posts to a Page the owner manages | `provider_confirmed` from the returned post `id` | `POST https://graph.facebook.com/{version}/{page-id}/feed` (`message`, `link`); the Graph API version is one constant verified at build time and editable in Advanced; long-lived Page tokens derived from a long-lived user token are stored encrypted |
| Generic webhook (Zapier, Make, n8n, custom) | `Distribution\Adapters\Webhook` (N) | FULL | webhook | HTTPS URL and a shared secret | POSTs `{event, resource{title,url,summary,asset_type}, distribution_id, message, utm_url}` signed with `X-LGA-Signature: sha256=<hmac>` and `X-LGA-Timestamp` | a 2xx is only `handed_off` (not success); `provider_confirmed` when the automation calls back `POST /wp-json/lga/v1/webhook/confirm` with `{distribution_id, remote_url}`, signed with the same secret (timestamp within 5 minutes, replay-protected); then `live_verified` after fetching `remote_url` | UrlGuard applies to the webhook URL |
| WordPress-to-WordPress syndication | `Distribution\Adapters\WordPressSyndication` (N) + `Distribution\Receiver\SyndicationReceiver` (N) | FULL when the target runs LinkGrowth Autopilot with the receiver on; DISCOVERY-ONLY otherwise | syndication or owned | target site URL, user name and an Application Password (HTTPS only) | Creates a post on the target (status `draft` by default; `publish` only when the target's authorization allows it) with a visible "Originally published at …" line and meta `_lga_canonical_url` | `provider_confirmed` from the created post id and link; then `Distribution\Verifier` fetches the public URL and checks `<link rel="canonical">` equals our URL: `canonical_ok = 1` gives `live_verified`; otherwise `canonical_missing`, an alert, and (default on) the copy is switched back to draft through the API | Receiver (off by default on every site, `distribution.receiver_enabled`): registers `_lga_canonical_url` and `_lga_syndicated_from` with `show_in_rest` and an `edit_posts` auth check, and accepts them only for hosts in `distribution.receiver_allowed_sources[]`. It writes the canonical into the active SEO plugin's own field (§1.4, so it survives deactivation, §4.4), outputs it through `get_canonical_url` and the SEO plugins' canonical filters (`wpseo_canonical`, `rank_math/frontend/canonical`, `aioseo_canonical_url`, `seopress_titles_canonical`), and records the source host in `lga_syndication_sources`. Without the receiver the adapter refuses full-text syndication (excerpt plus link only). **Reciprocity is refused**: no syndication to a host that has syndicated to us (§2.8). When the target is owned or suggested-owned (§2.8), the item is owned-channel publishing with the rules below |
| Owned-channel publishing | the WordPress adapter and the webhook adapter with channel `owned` | FULL (manual approval) | owned | as above | Placements on the owner's other sites or newsletter | as above | never automatic and never suggested by the engine. Each item needs manual approval, a written justification of at least 40 characters, at most one owned-channel placement per resource, and a target-site relevance sub-score of at least 40 against the resource topic; the reciprocity refusal of §2.8 also applies. Audited, and excluded from KPIs (spec §11) |

#### DISCOVERY-ONLY platforms (tracked as opportunities and owner tasks, not automated)

| Platform type | Reason |
|---|---|
| Google Business Profile | API access needs Google's approval, and creating or verifying a listing needs owner verification steps; the plugin prepares the listing package and tracks the profile URL. Like every maps and local item, it is proposed only for `site.business_type` `storefront`, or `service_area` with a hidden address, and only with a verified `location` fact; never for `online_only` (§2.5) |
| Bing Places, Apple Business Connect | no public self-serve write API for single businesses (Apple's API is for approved partners) |
| Yelp, Foursquare and similar local listings | no supported API to create a business listing; claim flows need owner verification |
| Data aggregators (paid partner programs) | paid partner access; listings are an owner purchase decision |
| Review platforms (Trustpilot, G2, Capterra and others) | reviews must come from customers; automating them would be fabricated reviews (spec §4). The plugin only creates "claim and verify your existing profile" owner tasks, never review requests (§2.5) |
| Medium | no new integration tokens are issued for its publishing API; the owner can use Medium's own import (which sets a canonical) manually |
| Reddit, Quora, forums, comment sections | promotional automation is spam under their rules and spec §4 |
| X (Twitter) | not requested; posting needs a paid API tier; the webhook adapter can bridge it if the owner chooses |
| Pinterest and other social networks | not in scope for 1.0.0; the webhook adapter covers them through the owner's automation |
| Publications without an official submission API | prepared, personalized pitch packages for an authorized person (spec §3E) |
| Directories without an authorization record or with a CAPTCHA, account, payment, reciprocal-link or verification requirement | owner tasks only |

**No bundled directory, listing or publication submission API in 1.0.0.** None of the directories, listing platforms or publications the plugin finds offers a public, self-serve write API that a single business may use without a partner agreement (§6.6 discovery-only list). The plugin therefore ships **no ready-made submission integration for any named directory**. Automated submission happens only through:
- a partner API the owner configures for a destination that grants one (`ConfiguredApiAdapter`, §6.1);
- an authorized form POST to a destination for which the owner recorded an authorization with evidence (§6.7);
- an acquisition adapter added by an extension (`lga_acquisition_adapters`).

Everything else is an owner task with a prepared package. FEATURES.md (status "framework: works with owner-configured partner APIs and authorizations; no bundled destination") and LIMITATIONS.md say this in these words. FEATURES.md lists the "What still needs a person" table of Appendix B.

### 6.7 Directory submissions and the authorized-form policy

`Submission\Policy::evaluate(prospect, opportunity, package, adapter): PolicyDecision` keeps every engine gate and adds the following. A decision lists each gate and its result, and the UI shows them.

1. `automation.modules.submissions` on, `setup.completed`, `consent.submissions` recorded; a scheduler start needs `Autopilot::allows('submissions')`, while a person's start (Run now, Grow now, a per-item "Submit now") does not (§2.1, §5.2).
2. `automation.submission_mode` (never changed by Autopilot): `authorized_only` (default) needs an active `lga_authorizations` row for the destination with an operation that covers the submission; `manual` turns everything into owner tasks. The destination must not be owned or suggested-owned (§2.8).
3. Authorization evidence for a **form** POST must be `terms_allow` (the destination's terms or submission page invite automated or structured submissions; URL recorded) or `written_permission` / `partner_agreement`. A public form alone is not permission.
4. Form checks (engine): POST only, no password field, no required upload, not a register or login form (`FormDetector::KIND_REGISTER/LOGIN`), not a comment or forum form, robots.txt allows the path, field mapping complete, public HTTPS destination. **Anti-bot measures are never worked around**. `FormDetector::antiBot(string $formHtml, string $pageHtml): AntiBotVerdict` extends the engine's regex (which caught only reCAPTCHA, hCaptcha and Turnstile). Any of the following makes the form an owner task (`refused_policy`, reason `anti_bot:<kind>`):
   - **Challenge widgets**: `g-recaptcha`, `recaptcha`, `h-captcha`, `hcaptcha`, `cf-turnstile`, `turnstile`, `frc-captcha` / `data-friendly-captcha`, `<altcha-widget>` / `altcha`, `mcaptcha` / `mcaptcha__widget`, `arkose` / `funcaptcha`, `geetest`, `data-sitekey`, `captcha` in any class, id, name or script URL, a Cloudflare challenge page (`cf-chl`, `challenge-platform`), and proof-of-work markers (`data-pow`, `pow-captcha`, `proof of work` in a script).
   - **JavaScript-set fields**: any `required` field whose value is empty in the HTML and that a script names (by `name` or `id`) in inline JS on the page; any hidden input whose name looks like a token (`token`, `nonce`, `_wpnonce` excepted when it has a server-rendered value, `ak_js`, `js_check`, `timestamp`, `form_time`, `antispam`) and is empty in the HTML; any form whose `action` is empty and is submitted through JS (`onsubmit`, `data-ajax`, a form plugin's AJAX handler); and any page that sets values through `document.forms`/`.value =` on that form.
   - **Time traps**: hidden fields with a server time or a minimum-fill-time marker (`form_start`, `time_check`, `_hp_time`). These are refused because posting one sooner than a human could would defeat the trap's purpose.

   **Honeypots are detected and left empty, never mapped**: inputs hidden with an inline `display:none`/`visibility:hidden`/zero size/off-screen position, inside a container hidden the same way or with the `hidden` attribute, with `aria-hidden="true"`, with `tabindex="-1"`, with a class or id containing `hp`, `honeypot`, `ohnohoney`, `trap` or `screen-reader-text`, or with a label or placeholder like "leave blank", "do not fill", "if you are human". A honeypot named `website`, `url`, `email` or `name` is still a honeypot: the field mapper skips it, and when the only field for our URL is a honeypot the mapping is incomplete (owner task).

   Tests (WP17): U `FormDetectorAntiBotTest` with one fixture form per widget, per JS-set pattern and per time trap (each must give `anti_bot`), one per honeypot style (each must be detected and left out of the payload, including a hidden `website` field next to a visible `url` field), and a plain form that passes.
5. Eligibility (`EligibilityDetector`, stored on the opportunity): paid-only, account required, reciprocal link required, email or phone verification required, region or category restrictions not met, listing expiry. Any of the first four gives `refused_policy` (owner task).
6. Terms (`TermsDetector`): the terms link on the submission page is fetched (robots-respecting) and scanned for "automated", "bot", "scrap", "script", "robot" prohibitions: a match gives `terms_verdict = forbids_automation` and refusal. Terms or consent checkboxes are **never ticked automatically**: the engine's `CONSENT_ALLOW` auto-tick is removed; a form with a required terms box becomes a prepared package for a person.
7. Placement and rel: `placement_type` from the page (free listing, paid, sponsored, editorial). Advertise and sponsor pages are excluded from automatic targets. A paid or sponsored placement needs `distribution.paid_budget > 0` (default 0, so never) and the package requests `rel="sponsored"`; the monitor alerts when such a link is followed.
8. Duplicates: one submission per destination and target (engine unique key), one open contact per site, cool-down and suppression list (engine), and the same rules for distributions (`provider_dedupe`).
9. A response asking to verify by email or phone sets `needs_owner_verification`; the follow-up is never automated.
10. Velocity: monthly limits (§5.3); anchor text for listings is the brand name or the URL only (never an exact-match keyword anchor).

---

## 7. Content engine (spec §5)

### 7.1 Inputs to the gap analysis

`Resources\Inventory` (weekly, batches of 200 posts) lists the site's published posts, pages and products with: title, URL, post type, word count, H2/H3 count, schema types in the content or SEO plugin, tables, downloads (links to PDF/CSV/XLSX), calculator blocks, focus keyword (SEO adapter), internal inbound links, GSC clicks and impressions for the URL (when connected), live backlinks to the URL (`lga_backlinks.target_hash`) and whether it is a registered resource. It classifies existing pages into asset types (heuristics: a page with 15+ `<dt>` or "Glossary" in the title is a glossary; a page with a Dataset schema or a CSV download is a dataset; …) and proposes linkable-asset candidates.

`Resources\GapAnalyzer` (task `content_gaps`) collects candidate topics from:

| Source | How | Needs |
|---|---|---|
| GSC queries | queries with 50+ impressions over 90 days where the best page's position is worse than 20 or no own page matches the topic; question, "vs", "calculator", "statistics", "glossary", "checklist" and "template" queries mapped to asset types | GSC |
| Competitor topics | `CitationTopics` (§3.4) and `TopPagesSource::topPagesByLinks()` for each competitor: page titles, URL slugs and anchors grouped by topic, with the number of referring domains | an authorized backlink provider |
| Broken links and resource pages | titles and anchors of dead resources found by `BrokenLinkFinder` (optionally the Wayback title), and the sections of discovered resource pages, where no own asset matches | none |
| Pack asset priors | `pack.asset_type_priors` combined with the industry topics | none |
| Own inventory | thin or outdated pages on high-impression topics (update candidates) | none |

Competitor data provides **topics and formats only**. The analyzer never stores competitor body text, and builders and the AI writer never receive it.

Gap score (0-100) = 35 × demand (log-scaled impressions plus competitor referring domains for the topic) + 25 × linkability (asset-type prior) + 20 × relevance (topic against the profile's core terms and target keywords) + 20 × feasibility (verified facts or data that can support it), minus 30 when a similar own asset exists (5-word shingle Jaccard of titles above 0.5, or the same topic hash). `feasibility` = `ready`, `needs_facts` or `needs_owner_data`. Gaps below `content.min_gap_score` stay listed but are never built automatically.

### 7.2 Asset types and builders

| Asset type | Builder (`Resources\Builders\*`) | Data it needs | Produced content | Auto-publish eligible |
|---|---|---|---|---|
| `statistics` | `StatisticsPage` | 10+ verified numeric facts in `lga_facts` (group `statistics`) with source URLs | list of statistics, each with its source citation and retrieval date; methodology note | yes, when all checks pass |
| `dataset` / `public_dataset` | `DatasetPage` + `Dataset\CsvImporter` | an owner CSV (at most 5 MB, 50,000 rows, UTF-8) or a first-party aggregate; a license chosen by the owner | HTML table of the first 100 rows, CSV download (media library attachment), schema.org `Dataset` JSON-LD (name, description, license, creator, `distribution.contentUrl`, `dateModified`), method note | yes |
| `data_report` / `original_research` | `DataReport` | first-party data only: owner survey CSV, or WooCommerce aggregates (owner opt-in, totals by month and category only, cells with fewer than 10 orders suppressed) | figures computed from the data; a methodology section generated from the processing steps actually run | yes only with `ai_used = 0` (narrative entirely from templates); any AI use makes it a draft (§7.6) |
| `calculator` / `interactive_tool` | `Calculator\Block` (`lga/calculator`) and shortcode `[lga_calculator id="…"]` | owner-defined inputs, formula and at least 3 test cases with expected outputs | an accessible form; script `assets/js/front/calculator.js` (under 5 KB) enqueued only on pages containing the block; formula evaluated by a safe expression parser (§11.1) | yes, once the owner approved the formula and the test cases pass |
| `comparison` | `ComparisonPage` | structured first-party data: WooCommerce product attributes and prices (`wc_get_products()`), or an owner table | comparison table and notes; every cell records its source (`product:<id>:<attribute>`, `fact:<id>`) | yes **only when every compared item is one of the owner's own products** (each row maps to a `wc_get_products()` id and every cell comes from that product's data). A row about any third-party product or competitor needs, **per cell**, a verified fact with a source URL whose fetched page contains the value. Such a comparison is always **draft-only**, and the accuracy check fails on any third-party cell without its own verified, source-matched fact |
| `infographic` | `Infographic\SvgChart` | a dataset or statistics resource | server-generated SVG chart (escaped text, no external fonts), an embed snippet with a visible attribution link to the source page | yes (derived) |
| `glossary` | `Glossary` | owner term list; definitions written by the owner or AI-drafted from facts | glossary page with `DefinedTermSet` schema | only when every definition was written by the owner (`ai_used = 0`); any AI-drafted definition makes it a draft, even after the owner approved it (§7.6) |
| `reference_guide`, `technical_guide`, `educational` | `Guide` | outline plus verified facts; optional AI drafting | without AI: an outline draft (`needs_owner_content`); with AI: a facts-bound draft | **no**, draft only |
| `downloadable` | `Downloadable` | an existing resource | CSV, Markdown or print-friendly HTML version | derived from its parent; PDF generation is not included in 1.0.0 (LIMITATIONS.md) |

Resources are WordPress `page` posts by default (`content.post_type` may be `post`), so the theme renders them. Each has hidden meta `_lga_resource_id` and a revision on every change.

**Calculator storage.** Post meta `_lga_calculator` on the resource post holds versioned JSON, validated on every save by `Calculator\Definition::validate()`:

```json
{ "v": 1,
  "inputs":  [ { "name": "area_m2", "label": "Floor area", "type": "number", "min": 0, "max": 100000, "step": 0.1, "unit": "m²", "default": 50 } ],
  "outputs": [ { "name": "litres", "label": "Paint needed", "expr": "ceil(area_m2 * coats / coverage)", "format": "number:0", "unit": "L" } ],
  "constants": [ { "name": "coverage", "value": 10, "fact_id": 12 } ],
  "tests":   [ { "inputs": { "area_m2": 50, "coats": 2 }, "expected": { "litres": 10 }, "tolerance": 0.001 } ],
  "formula_sha256": "…", "approved_by": 3, "approved_at": "2026-11-20T10:00:00Z" }
```

The rules:
- Names match `^[a-z][a-z0-9_]{0,31}$`; at most 20 inputs, 10 outputs, 20 constants and 50 tests, and at least 3 tests.
- Every expression parses with `Calculator\Expression` (§11.1) using only declared names.
- Every constant that is a stated fact references a verified `fact_id`.
- All tests pass on the server.
- `formula_sha256` covers inputs, outputs and constants. Any change clears `approved_by`/`approved_at`, and the block shows "Not yet approved" to editors and renders nothing to visitors until the owner approves again (`POST /resources/{id}/calculator/approve`, which needs `lga_manage` and passing tests).
- Revisions keep earlier versions because the meta is registered with `revisions_enabled`.

**Dataset storage.** The CSV is a media-library attachment (`post_mime_type = text/csv`). Attachment meta `_lga_dataset` holds `{v:1, columns:[{name, type: string|int|float|date|bool, unit}], rows, sha256, bytes, license, source_note, imported_by, imported_at, delimiter, has_header}`, and the resource post stores `_lga_dataset_id`. Rows are **never** copied into `lga_facts` or any table. The accuracy check and `DataReport` stream the attachment with `fgetcsv()` (escape passed explicitly) and compute the values they need. A `source_type = dataset` fact holds only `dataset_id` and `dataset_ref` (column, aggregation, filter) and is recomputed from the file at check time. Before any read, the file's SHA-256 is compared with the stored one, and a mismatch fails the check with "dataset file changed; re-import it". Tests (WP23): U `CalculatorDefinitionTest` (validation, approval cleared on change, failing test refused, unknown name refused) and U `DatasetStorageTest` (meta written, rows not in `lga_facts`, streamed matching of a 50,000-row file within the memory cap, hash mismatch fails the accuracy check).

### 7.3 Generation pipeline

```
gap (open) → Planner (asset type, outline, facts and data selected, internal links proposed)
          → Builder (template; optional AiWriter with only verified facts, profile and the active claim rules)
          → Checks (quality, accuracy, originality, compliance, readability)
          → PublishGate → WordPress draft (default) or publish
          → Register (lga_resources row, audit_log 'resource.created' / 'resource.published')
          → after publish: IndexNow or Bing URL submission, cache purge (§11.3), optional distribution items (approval per §2.2)
          → Maintenance (monthly review)
```

A failed check means the draft is rejected or kept as a draft with the failures listed; failed AI output is discarded, never patched by hand inside the pipeline. Rejections count against the drafting cap.

### 7.4 Verified facts registry

`Content\FactsRegistry` becomes a repository over `lga_facts`. A fact is usable in generated content only when `verified = 1`.

**Provenance** (`lga_facts.origin`):
- **Who creates facts.** Facts are created only by the owner (`POST /content/facts`, origin `owner`), by the setup wizard from what the owner typed (`wizard`), or by an owner-started dataset import (`dataset_import`).
- **What the system may do.** It may **propose** facts, for example a number found while crawling a source or a GSC trend, as `origin = system_suggestion` with `verified = 0`. They are listed under "Suggested facts" and are never used in content.
- **When a fact is verified.** `FactsRegistry::verify(int $factId, int $userId)` is the only path to `verified = 1`. It is called only from `POST /content/facts/{id}/verify` (capability `lga_manage`, the user id comes from the session) and needs `$userId > 0`.
  - URL facts (`source_type = url`): `verify()` also fetches the source page through UrlGuard and requires that it contains the stated value (normalized number with unit, or the quoted phrase). It stores up to 500 characters around the match in `source_excerpt` with `source_matched_at`. Without a match it refuses with `lga_source_mismatch`, and the owner's tick alone is not enough.
  - Dataset facts: verified only after the recomputation from the file matches the stated value.
- **Suggestions.** A system suggestion can be verified only through the same owner action. The owner sees the excerpt and the page link before ticking.
- **Auto-publish.** Auto-published statistics use only facts that went through `verify()` with an owner's tick, so no system-only path can produce a fact that an auto-published page cites.

Facts carry `review_at` (default 12 months). `resource_review` re-checks URL sources monthly. A changed or unreachable source sets `check_status = source_changed` or `source_unreachable` and **resets `verified = 0`** until the owner re-verifies, which flags every resource using that fact and blocks its auto-republish.

Test: U `FactsProvenanceTest` (WP7). No method other than `verify()` can write `verified = 1`: a reflection scan of `FactsRegistry` plus a grep of `includes/` for `'verified' =>` writes. `verify()` refuses `userId = 0`, a URL fact whose source lacks the value, and calls from the scheduler context. An auto-extracted `system_suggestion` run through every job never reaches `verified = 1`.

### 7.5 Checks and default thresholds (`content.thresholds`)

| Check | Rule | Default |
|---|---|---|
| Quality | minimum words by type (guide and technical 1,200, educational 800, glossary 20 terms of 25+ words, statistics 10 facts, comparison 3 items × 4 attributes, dataset 20 rows); at least 3 H2 headings for prose types; title unique (no existing post with title similarity above 0.8); meta description 70-160 characters; at least 2 relevant internal links; no placeholder tokens, no "TODO", no AI disclaimers | as listed |
| Accuracy | every number, percentage, currency amount and year in the text maps to a verified fact or a dataset value; every cited URL is fetched (robots-respecting), returns 200 and contains the cited value or quote | 100 % mapped, 100 % citations verified |
| Originality | 5-word shingle Jaccard against own published content (top 50 by title or keyword similarity) and against every fetched source or competitor page; no verbatim run of 12+ words outside a quoted, attributed block | own content at most 0.20, sources and competitors at most 0.10 |
| Compliance | ClaimsFilter core rules and the active claim packs: zero blocking matches; no testimonials, ratings, endorsements, expert bylines or credentials unless configured for a real person (`content.authors[]` with the owner's confirmation); pack `ymyl: true` forces draft-only | zero blocking matches |
| Readability | English: Flesch Reading Ease (the ease setting depends on the type; default at least 30); other languages: average sentence length at most 25 words (no readability formula is claimed for them) | as listed |
| Duplication | no other resource with the same `topic_hash` and asset type | unique |

Every check result (pass or fail, measured values, the offending sentences) is stored in `lga_resources.check_results` and shown on the Resources page.

**Fixed floors versus tunable thresholds.** `SettingsSchema` accepts under `content.thresholds` only the tunable keys below. The **accuracy** rule (100 % of numbers mapped to a verified fact or dataset value, and 100 % of citations fetched and matched) and the **compliance** rule (zero blocking ClaimsFilter matches, the YMYL draft-only rule, and the testimonial, byline and credential rule) are constants in `Resources\Checks\Floors`. They are not settings, cannot be lowered by a filter (`PublishGate` re-applies them after `lga_content_thresholds`), and a POST that tries to set `content.thresholds.accuracy*` or `.compliance*` gets 400 `lga_fixed_floor`.

| Tunable key | Default | Allowed range |
|---|---|---|
| `quality.min_words.<type>` | as in the table above | default × 0.75 up to 10,000 |
| `quality.min_internal_links` | 2 | 1-10 |
| `quality.min_h2` | 3 | 2-12 |
| `originality.max_own` | 0.20 | 0.05-0.30 |
| `originality.max_sources` | 0.10 | 0.05-0.15 |
| `originality.max_verbatim_words` | 12 | 8-20 |
| `readability.min_flesch` | 30 | 0-60 |
| `readability.max_avg_sentence` | 25 | 15-35 |

CONFIGURATION.md documents both groups and why the floors are fixed. Test: U `ThresholdsSchemaTest` (WP23). The validator refuses each weaker value (accuracy 99 %, one allowed compliance match, `max_own` 0.35, `max_sources` 0.2, `min_words` below 75 % of the default), and a filter returning weaker floors has no effect on `PublishGate`.

### 7.6 Auto-publish gate and caps

`Resources\PublishGate` publishes automatically only when **all** of these hold; otherwise it saves a WordPress draft:

- `content.publish_mode = auto_when_passed` and `consent.resource_publish` recorded;
- the asset type is auto-publish eligible (§7.2) and the industry pack is not YMYL;
- `ai_used = 0`: no part of the text, summaries and meta description included, came from the AI writer. Any AI use makes the resource a draft for the owner, without exception. This matches Appendix A and LIMITATIONS.md, because the accuracy check covers numbers and citations only, not qualitative claims;
- the publish step is started by a person, or by the scheduler while `Autopilot::allows('resource_publish')` holds (§5.1);
- every check passed;
- the gap score is at least `content.min_gap_score`;
- the `resources_published` daily and monthly limits have room, and the monthly total is under the hard ceiling of 8 (`LGA_MAX_RESOURCES_PER_MONTH` can lower it; values above 8 are ignored).

Caps for drafting (`resources_drafted`: 1 per day, 2 per week, 6 per month by default) keep the engine from producing a flood of pages (spec §5 "never hundreds of low-value AI pages").

Test: U `PublishGateTest` (WP23) covers each condition alone failing. The cases include `ai_used = 1` with every check passed and every other condition met, which always yields a draft; a third-party comparison cell, which yields a draft; and statistics citing a fact that is not owner-verified, which fails.

### 7.7 Resource register and maintenance

`lga_resources` records every resource the engine planned, drafted, imported or published, with its purpose (one sentence: "Answers GSC queries about X with a sourced statistics page; target for resource-page outreach in Y"), gap evidence, generator, AI use, sources, facts used, check results, status, actor and dates. REST: §10. Every create, publish, retire and check run is audited.

`resource_review` (monthly) re-verifies cited sources and URL facts, checks outbound links (reusing the monitor's link checker), dataset freshness, and performance (links earned, natural links, referral sessions over 90 days from analytics). It sets `next_review_at`, flags `needs_update`, and suggests retiring resources with no links or traffic after 12 months. Retiring is an owner action (it never deletes or unpublishes on its own).

`links_earned` and `natural_links` are updated when the monitor attributes a verified backlink to the resource (§3.4). The register feeds `LinkableAssets` (destinations for broken-link and resource-page opportunities) and the decision engine's asset family (§8).

---

## 8. Decision engine (spec §9)

### 8.1 Arms

An **arm** is a strategy the engine can spend budget on. Three families:

| Family | Arm key (canonical string) | Consumers |
|---|---|---|
| `discovery` | `disc/<source>/<opportunity_type>` where source is `search:brave`, `search:google_cse`, `provider:<id>`, `competitor:<provider>`, `seed:<pack>`, `catalog:<id>`, `mention_scan`, `broken_link_scan`, `expand:<arm>` | QueryGenerator template choice, provider order, discovery and lookup budgets, evaluation order |
| `acquisition` | `acq/<method>/<opportunity_type>/<category>` (method from §3.4, category = directory subtype or site type) | packaging, submission, outreach draft and distribution order and budgets |
| `asset` | `asset/<asset_type>` | gap ranking and resource building |

Each arm has a parent for shrinkage: `acq/<method>/<type>/<category>` → `acq/<method>/<type>` → `acq/<method>`; `disc/<source>/<type>` → `disc/<source>`. `strategy_key` = first 16 hex characters of `sha1(arm)`, stored on prospects, opportunities, submissions, outreach messages, distributions, backlinks and search log rows when they are created.

### 8.2 Outcome ledger

`Decision\Ledger::record(string $strategyKey, string $event, float $value = 1, string $objectType = '', int $objectId = 0)` is called through the action `lga_outcome` from the modules that observe outcomes. Events:

| Event | Emitted by | Arm family |
|---|---|---|
| `discovered` | ProspectIntake | discovery |
| `qualified` / `rejected` | EvaluateJob / OpportunityScorer | discovery |
| `attempt` (value = cost units) | SubmissionEngine send, OutreachEngine first send, DistributionEngine publish | acquisition |
| `provider_confirmed` | adapters | acquisition |
| `acquired` (value = link value, §8.3) | BacklinkMonitor on `live_verified` with an attributed opportunity | acquisition and the discovery arm of its prospect |
| `natural_link` | BacklinkMonitor (`is_natural`) | asset |
| `survived_90d` / `aged_90d` | daily recompute for links verified 90+ days ago | acquisition |
| `lost`, `domain_lost` | BacklinkMonitor, RecoveryPlanner | acquisition |
| `referral_sessions` (value = sessions), `conversion` (value = count), `revenue` | Analytics attribution (source domain → backlink → opportunity → arm) | acquisition, asset |
| `risk:bounce` (1), `risk:unsubscribe` (2), `risk:complaint` (5), `risk:rejected_submission` (1), `risk:spam_flag_later` (2), `risk:paid_followed` (5), `risk:policy_refusal_after_send` (3) | Mailer and InboundWebhook, SubmissionEngine, RiskAnalyzer re-evaluation, BacklinkMonitor | acquisition |
| `cost` (value = units) | ProviderGateway, search providers | discovery |

### 8.3 Statistics and priority (task `decision_recompute`, daily)

All counts are time-decayed with a 90-day half-life (`w = 0.5 ^ (age_days / 90)`), recomputed from `lga_strategy_events` of the last 18 months and stored in `lga_strategy_stats`. With `k = 10` pseudo-observations and the parent's rate as the prior (empirical Bayes shrinkage; the top-level prior is the family average):

```
Discovery arm a:
  Q_a = (qualified_a + k·Q_parent) / (discovered_a + k)                 qualified rate
  Y_a = (acquired_a  + k·Y_parent) / (qualified_a  + k)                 acquisitions per qualified prospect
  V_a = (value_sum_a + k·V_parent) / (acquired_a + k)                   mean value per acquired link
  E_a = Q_a · Y_a · V_a / max(0.1, cost_units_a / max(1, discovered_a)) expected value per unit of cost

Acquisition arm a:
  A_a = (acquired_a + k·A_parent) / (attempts_a + k)                    live-verified rate per attempt
  S_a = (survived_a + k·S_parent) / (aged_a + k)                        90-day survival
  V_a = (value_sum_a + k·V_parent) / (acquired_a + k)
  E_a = A_a · S_a · V_a
  R_a = (risk_points_a + k·R_parent) / (attempts_a + k)                 risk per attempt

Asset arm a (stored with attempts = resources published of that type, acquired = natural links, referral_sessions as for other arms):
  E_a = (natural_links_a + 0.02·referral_sessions_a + k·E_parent) / (resources_a + k)

Link value v (0..1), never a raw count:
  v = uniq · ( 0.5·quality/100 + 0.3·min(1, ln(1+sessions_90d)/ln(51)) + 0.2·min(1, conversions_90d/2) )
  uniq = 1 for a new referring domain, 0.3 when the domain already linked live; owned and simulated links give 0

UCB exploration bonus:   U_a = E_a + β · sqrt( ln(1 + N_family) / (1 + n_a) ),   β = 0.15, n_a = attempts (or discovered) of the arm
Priority:                P_a = max(0, U_a − λ·R_a),   λ = 0.5   (R_a = 0 for discovery and asset arms)
```

### 8.4 Budget allocation

Pools: `discovery_queries`, `provider_lookups`, `evaluations`, `submissions`, `outreach_drafts`, `distribution_posts`, `resources_drafted`. Each day, after the recompute, `Decision\Allocator` splits each pool's **remaining daily limit** among the active arms that consume it:

```
m      = number of active arms for the pool
f      = min(0.05, 0.5 / m)                             exploration floor per arm
s_a    = f + (1 − f·m) · P_a / Σ P                      (equal split if Σ P = 0)
s_a    = min(s_a, 0.5); renormalize the rest
quota_a = floor(C · s_a); the remainder goes to the arms with the largest fractional parts
```

Consumers call `Decision\Budget::take(pool, strategyKey, n = 1): bool`, which checks the arm's quota (tracked in `lga_counters` as `budget:<pool>:<key>`, period `d`) and then the global limit (`Limits::reserve`). Unused arm quota is released to a shared pool after 18:00 site time so budget is not wasted. Concrete uses:

- QueryGenerator picks query templates by weighted sampling without replacement, weights = discovery arm priorities, respecting the cool-down.
- Search providers and backlink sources are tried in order of their discovery arms' priority (the engine sorted only by "primary" and quota).
- EvaluateJob orders due prospects by `arm multiplier × prior score`; package, submit, outreach draft and distribution queues order opportunities by `quality × uniqueness × arm multiplier`, where the multiplier is `0.5 + P_a / max(P)` (0.5 to 1.5), and job priority shifts by at most 2 levels.
- Gap ranking multiplies the gap score by the asset arm multiplier.

With the module `decision` off, every multiplier is 1, quotas are not enforced and only the global limits apply.

### 8.5 Stop rule

For an acquisition arm with at least `n_min` attempts in the window (20; 10 for outreach arms):

```
benefit_upper = WilsonUpper95(acquired_a, attempts_a) · V_upper_a       (V_upper = mean value + 1.96·sd/√n, capped at 1)
risk_lower    = WilsonLower95(risk_attempts_a, attempts_a)              attempts that had at least one risk event
pause when benefit_upper < 0.01 and risk_lower >= r_max                 r_max = 0.15 (outreach), 0.25 (submissions), 0.20 (others)
```

- A paused arm (`state = paused_risk`) gets quota 0, its queued work is held (not deleted), the owner is notified (event `strategy_paused`) and the decision is audited. Resuming is manual (`POST /strategy/{key}/resume`), and a resumed arm keeps its history.
- Immediate pauses, without waiting for the sample: a spam complaint pauses the outreach arm for 30 days; a paid followed link pauses its arm; a provider policy violation pauses that provider's arms.
- `deprioritized`: an arm with 60+ attempts, zero acquisitions and no referral traffic loses its exploration floor (quota only from its priority, which is near 0), without a pause.

### 8.6 Expansion triggers (the spec §9 rules)

| Spec rule | Trigger | Action (job `expand`, payload names the arm; at most 20 new prospects per run, 3 runs per day) |
|---|---|---|
| A directory category brings qualified referral traffic | an acquisition arm with category `c` has `referral_sessions >= 20` over 90 days from at least one live link, or `conversions >= 1` | Its priority already rises. Also: competitor lookups (`CompetitorSource::competitors`) for each directory domain in `c` that sent traffic; pack seeds in `c`; queries from `c`'s templates with that directory's own category labels. New prospects get `discovered_via = expand:<arm>`. Submissions still go only through §6.7 |
| A resource earns natural links | `natural_link` events for a resource | `TopPagesSource` lookups for competitor pages on the same topic and their referring pages (sites that link to equivalent resources); resource-page queries with the resource topic; +10 % gap score for related topics; the resource becomes the preferred `suggested_url` for matching opportunities |
| A referring domain disappears | `domain_lost` | `RecoveryPlanner` (§3.5): moved-page search, restore or re-listing draft, comparable sites in the same category |
| A provider yields mostly irrelevant opportunities | low `Q_a` for `disc/provider:<id>/*` arms | automatic through priorities and quotas; the exploration floor keeps sampling it; the Providers page shows the qualification rate |
| A strategy creates risk without benefit | stop rule | pause, notify, audit |

### 8.7 Explainability

`lga_decisions` stores, for every daily run and arm: old and new share, quota, priority, the inputs (decayed counts and rates) and a reason sentence built from them, for example: "Industry-association directories: 3 live links in 90 days brought 34 referral sessions (top 10 % of arms). Share raised from 12 % to 20 % of today's submissions." The Strategy page (§10) shows the arm table, the reason per row, the pause and resume controls, and a log of changes. Every pause, resume and expansion is also in `audit_log`.

The objective is relevance- and quality-weighted live referring domains, referral sessions and conversions. Raw backlink counts are never a target or a boost signal (spec §9); a unit test asserts that adding 10 links from one already-linking domain does not raise an arm's priority more than one new domain does.

---

## 9. Analytics (spec §8)

### 9.1 Metrics, sources and storage

`Analytics\Snapshotter` (task `analytics_snapshot`, daily) writes one row per metric, source, dimension value and day into `lga_analytics_daily`. Sources: `internal` (our tables), `ga4`, `gsc`, `bing`, `woo`. GA4 and GSC data settle over 2-3 days, so each run re-fetches the last 3 days and overwrites them (the unique key makes this an upsert: `INSERT IGNORE` then `UPDATE`). A backfill of 90 days runs once when a source is first connected (in daily chunks within the tick budget).

| Metric (`metric`) | Definition | Source | Dimensions |
|---|---|---|---|
| `referring_domains_live` | distinct `source_domain` with at least one `active` backlink, excluding owned and simulated | internal | total |
| `referring_domains_new` | domains whose earliest `first_verified_at` is that day | internal | total |
| `quality_referring_domains` | sum over live domains of the best link `quality_score / 100` (the main KPI) | internal | total |
| `backlinks_live` | `active` backlinks, excluding owned and simulated | internal | total, rel |
| `backlinks_new_verified` | backlinks with `first_verified_at` that day (imports still pending are not counted) | internal | total |
| `backlinks_lost` | backlinks with `lost_at` that day | internal | total |
| `referral_sessions`, `referral_users` | GA4 `sessions`, `activeUsers` where `sessionMedium = referral` | ga4 | total, source_domain |
| `referral_key_events` | GA4 `keyEvents` for referral sessions | ga4 | total, source_domain |
| `referral_purchases`, `referral_revenue` | GA4 `ecommercePurchases`, `purchaseRevenue` for referral sessions; or WooCommerce orders with attribution source type `referral`, grouped by referrer host | ga4, woo | total, source_domain (revenue rows carry `dim = currency` totals as well) |
| `organic_clicks`, `organic_impressions`, `organic_ctr`, `organic_position` | GSC Search Analytics; Bing `GetRankAndTrafficStats` and `GetQueryStats` as a separate source | gsc, bing | total, page, query |
| `organic_landing_sessions` | GA4 `sessions` with `sessionDefaultChannelGroup = Organic Search` by `landingPagePlusQueryString` | ga4 | page |
| `keyword_clicks`, `keyword_impressions`, `keyword_position` | GSC with a `contains` filter per target keyword (`keywords.target[]`, at most 50) | gsc | keyword |
| `organic_revenue` | GA4 `purchaseRevenue` for `Organic Search`; or WooCommerce orders with attribution source type `organic` | ga4, woo | total |
| `resource_referral_sessions` | referral sessions landing on registered resources | ga4 | page |

**Storage bounds.** GSC and Bing can return thousands of page and query rows a day. So `Snapshotter` stores, per metric, source, dimension and day, at most:
- `page` 200 rows, `query` 200, `source_domain` 200, `keyword` `count(keywords.target)` (at most 50), `country` 50, `channel`, `rel` and `currency` uncapped (small fixed sets);
- plus one **`(other)`** row per dimension holding the remainder: the sum for additive metrics, and for `organic_ctr`/`organic_position` the impressions-weighted mean with the impressions in `weight`.

The top N is chosen by the day's clicks (GSC, Bing), sessions (GA4) or value (others), and the `total` row always holds the provider's own total, not the sum of the stored rows. Upper bound per day: 13 metric/source pairs with dimensions × at most 201 rows ≈ 2,600 rows/day. After the rollup below, the 25-month retention holds about 90 × 2,600 + 105 weeks × 2,600 ≈ 510,000 rows at most, plus about 15,000 total rows.

**Rollup** (in task `cleanup`, §11.4). Daily **dimension** rows (`dim != 'total'`, `grain = 'd'`) older than 90 days are aggregated into ISO-week rows (`grain = 'w'`, `day` = that week's Monday): sums for additive metrics, weighted means for ctr and position, and top 200 again plus `(other)`. The daily rows are deleted afterwards in batches. `total` rows stay daily for the whole retention period. Series requests longer than 90 days with a dimension, or grouped by week or month, read the weekly rows, and the response says `grain: 'w'`. Tests: U `CleanupTest` (rollup sums and weighted means match a hand-computed fixture, daily rows deleted, totals untouched, idempotent when run twice) and U `SnapshotterCapTest` (1,000 fixture query rows are stored as 200 plus `(other)`, `(other)` plus the stored rows equal the provider total, keyword cap follows `keywords.target`).

Rules (spec §8 "only genuine data"):

- A metric whose source is not connected is returned as `null` points with `source_connected: false`; the UI shows "—" and "not connected", never 0.
- Every series response carries `source`, `from`, `to`, `fetched_at`, and GA4's `subjectToThresholding` / sampling flags, shown as a badge.
- Revenue is shown only when GA4 e-commerce or WooCommerce attribution is connected, with its currency, labeled with the source; it is never estimated.
- "Indexed" appears only from GSC URL Inspection for our own pages, labeled with source and date. Accessible pages are labeled "indexable".
- WooCommerce data is read with `wc_get_orders()` in batches of 200 (`paginate => true`), so it works with HPOS on or off; only aggregates are stored.
- Attribution to the decision engine: referral sessions and conversions from domain D on day t are split equally among the live backlinks from D on t, and each share is recorded against that backlink's arm (§8.2) and resource.

### 9.2 Charts (Analytics page and Overview)

All charts use the existing `assets/js/charts.js` primitives (line, bar, segmented bar), integer ticks, light and dark themes, and an accessible table toggle under each chart. Ranges: 28 days, 90 days, 12 months, custom; grouping by day, week or month.

| Chart | Series | Source |
|---|---|---|
| Backlink growth | cumulative live backlinks (line); new verified versus lost per week (bars) | internal |
| Referring domains | live referring domains and quality-weighted referring domains (lines); new referring domains per month (bars) | internal |
| Referral traffic | referral sessions and users (lines); table of the top referring domains with sessions, users, key events and live links from that domain | ga4 |
| Conversions | referral key events and referral revenue per month (bars), source switch GA4 / WooCommerce | ga4, woo |
| Organic search | clicks and impressions (two small multiples), source switch GSC / Bing; tables of top landing pages and target keywords (clicks, impressions, CTR, average position, change over the period) | gsc, bing, ga4 |
| Link attributes | follow, nofollow, sponsored, ugc shares | internal |

---

## 10. Dashboard and REST map (spec §10)

### 10.1 SPA structure

- `Admin\Menu` prints `#lga-config` with `pages` (from the modules' `adminPages()`, filtered by capability), `settingsTabs`, `restRoot` (`/wp-json/lga/v1`), the REST nonce and the profile summary. `app.js` builds the router and navigation from `pages` and lazy-loads `module` paths (same import-map approach as the engine). A page module exports `render(main, ctx)`.
- Settings tabs are separate modules (`assets/js/settings/<tab>.js`) registered through `settingsTabs`; the engine's `settings.js` is split along its existing tabs in WP3.
- Every interactive element is created through `ui.js` helpers that set `data-lga-control="<page>.<action>"` and register its expected effect for the E2E manifest (§12.5). A control without a working effect fails the E2E suite.
- Responsive breakpoints at 1280, 960, 600 and 390 px; tables become stacked cards below 600 px; no horizontal page scroll; light, dark and auto themes (engine).
- The submitter role (`lga_submit_listings` only) sees the Owner tasks page only (engine behavior).

### 10.2 Pages, widgets and endpoints

All routes are under `lga/v1`, need `lga_manage` unless noted, and return `Cache-Control: private, no-store`. Writes are rate-limited to 60 per minute per user (engine).

**Overview**

| Spec item / widget | Endpoint |
|---|---|
| Autopilot status, switch and consents (the switch toggles `automation.autopilot` only, §2.1; the dialog lists each consent with its text and what still needs a person) | `GET /automation/autopilot`, `POST /automation/autopilot` (`enabled`, `confirm`, `consents{}`), `POST /automation/consents/{key}/revoke` |
| Last execution, next scheduled execution, runner mode, server-cron heartbeat | `GET /overview` → `runner{mode,last_tick,heartbeat,server_heartbeat,next_run}` |
| **Sites discovered**: prospects excluding owned and suggested-owned, total and created in the last 30 days. **Qualified opportunities**: opportunities with `score >= min_score_keep` and status not `rejected`, `dismissed` or `refused_policy`, total open and qualified in the last 30 days. Verified live backlinks; new backlinks this month (calendar month, `first_verified_at`); lost backlinks (30 days and total); referring domains (and new this month); quality-weighted referring domains; referral visitors (users and sessions, 28 days); referral conversions (28 days). Each tile shows its period and its definition in a tooltip | `GET /overview` → `stats{sites_discovered{total,last_30d}, qualified_opportunities{open,last_30d}, …}` (nulls when a source is not connected) |
| Queue health (due jobs, oldest due age, failed in 24 h, failure rate, paused reason) and health checks | `GET /automation/health` |
| Backlinks new versus lost (mini chart) | `GET /analytics/series?metric=backlinks_new_verified,backlinks_lost&group=week` |
| Run now (growth run) | `POST /growth/start`, `POST /growth/step`, `GET /growth/status`, `POST /growth/cancel` |
| Setup banner | `GET /setup` |
| Notifications bell | `GET /notifications`, `POST /notifications/read` |

**Opportunities** (new; one row per opportunity)

| Column or control | Endpoint |
|---|---|
| Table: website, source URL, type and subtype, relevance score, quality score and class, risk rating, suggested destination, acquisition method, status, competitors linking, confidence | `GET /opportunities?type=&subtype=&status=&risk=&method=&min_score=&competitors_min=&q=&sort=&page=&per_page=` |
| Score explanation drawer, eligibility and terms verdicts, competitor links, lifecycle history | `GET /opportunities/{id}` |
| Dismiss, reopen, mark declined | `POST /opportunities/{id}/status` |
| Change suggested destination | `POST /opportunities/{id}/destination` (`url` or `resource_id`) |
| Rescore now | `POST /opportunities/{id}/rescore` |
| Prepare package or draft | `POST /opportunities/{id}/prepare` |
| Record authorization for this destination | `POST /authorizations` |
| Bulk dismiss or prepare | `POST /opportunities/bulk` |
| Export CSV | `GET /opportunities/export` |

**Prospects** (site level, engine page kept): the engine's `/prospects*` routes.

**Acquisition history** (new)

| Widget | Endpoint |
|---|---|
| Tabs Successful (`provider_confirmed`, `live_verified`), Pending (`awaiting_approval`, `queued`, `sent_unconfirmed`, `handed_off`), Failed (`failed`), Rejected (`rejected`, `declined`, `refused_policy`) across submissions, outreach and distributions; filters kind, provider, date | `GET /acquisitions?outcome=&kind=&provider=&from=&to=&page=` |
| Approve, cancel or retry an item (one item per request) | `POST /submissions/{id}` (engine), `GET /outreach/messages/{id}/preview` then `POST /outreach/messages/{id}/approve` (§2.7), `POST /outreach/messages/{id}/reject`, `POST /distributions/{id}/approve`, `POST /distributions/{id}/cancel`, `POST /automation/jobs/{id}/retry` |

**Submissions / Owner tasks**: engine page and routes (`/submissions*`) with the new statuses. Owner-task routes (`GET /submissions?status=needs_manual`, `GET /submissions/{id}/payload`, `POST /submissions/{id}/mark-submitted`) also accept `lga_submit_listings`.

**Outreach**: the engine page and routes, with these changes (§2.7):

| Widget | Endpoint |
|---|---|
| Campaigns (list, create, activate for drafting, pause, resume, archive; "Prepared by LinkGrowth" containers) | `GET/POST /outreach/campaigns`, `GET /outreach/campaigns/{id}`, `POST /outreach/campaigns/{id}/{submit\|approve\|pause\|resume\|archive}` (`approve` activates drafting only and approves no message), `POST /outreach/campaigns/{id}/drafts`, `GET /outreach/campaigns/{id}/candidates` |
| **Removed** | `POST /outreach/campaigns/{id}/approve-drafts` (bulk approval) |
| Message preview with the rendered message, footer, jurisdiction check and consent basis | `GET /outreach/messages/{id}/preview` (returns the `preview_token`) |
| Approve one message (first contact or follow-up) | `POST /outreach/messages/{id}/approve` (`preview_token` required) |
| Edit, reject, send now (approved only), log a reply, close | `POST /outreach/messages/{id}`, `POST /outreach/messages/{id}/{reject\|send\|log-reply\|close}` |
| Contacts, approaches, sender check, jurisdiction checklist | `GET /outreach/contacts`, `POST /outreach/contacts/{id}/status`, `GET /outreach/approaches` (expert_commentary and podcast absent without expertise), `GET /outreach/sender`, `POST /outreach/sender`, `GET /outreach/jurisdictions` |

**Backlinks** (engine page, extended)

| Widget | Endpoint |
|---|---|
| Live profile table (rel, placement, status, HTTP, quality, origin, first verified, checks, recommendation), tiles and charts, check coverage ("X % of links checked in the last 30 days", per tier, §3.5) | `GET /backlinks`, `GET /backlinks/summary` (includes `coverage_30d`) |
| Check history and change list | `GET /backlinks/{id}/history` |
| Re-check now | `POST /backlinks/{id}/check` |
| Add or import | `POST /backlinks`, `POST /backlinks/import` |

**Resources** (new)

| Widget | Endpoint |
|---|---|
| Register table (type, status, purpose, checks, links earned, natural links, referral sessions 90 days, next review) | `GET /resources` |
| Detail with check results | `GET /resources/{id}` |
| Gap list and "Run gap analysis" | `GET /content/gaps`, `POST /content/gaps/run`, `POST /content/gaps/{id}/dismiss` |
| Plan a resource from a gap, register an existing page | `POST /resources` (`gap_id`), `POST /resources/import` (`post_id`) |
| Draft, run checks, publish, retire | `POST /resources/{id}/draft`, `POST /resources/{id}/check`, `POST /resources/{id}/publish`, `POST /resources/{id}/retire` |
| Calculator editor (inputs, formula, test cases) and approval | `POST /resources/{id}/calculator`, `POST /resources/{id}/calculator/approve` |
| Dataset upload | `POST /resources/datasets` (multipart; also needs `upload_files`) |
| Verified facts (list, add, verify, delete) and suggested facts (`origin = system_suggestion`, shown separately with excerpt and source link) | `GET /content/facts?origin=`, `POST /content/facts`, `POST /content/facts/{id}/verify`, `DELETE /content/facts/{id}` |
| Claims check of any text | `POST /content/check` |
| Distribute a published resource | `POST /distributions` |

**Analytics** (new): `GET /analytics/series?metric=&source=&dim=&from=&to=&group=`, `GET /analytics/top?metric=&dim=&from=&to=&limit=`, `GET /analytics/keywords`, `GET /analytics/landing-pages`, `GET /analytics/sources`, `POST /analytics/refresh`.

**Strategy** (new): `GET /strategy?family=`, `GET /strategy/decisions?key=&from=`, `POST /strategy/{key}/pause`, `POST /strategy/{key}/resume`, `POST /strategy/recompute`.

**Providers** (new)

| Widget | Endpoint |
|---|---|
| Provider cards: connection status, credential status and expiry, permissions (granted versus required), supported operations, limits and remaining quota, circuit state, last error, activity summary; includes providers and adapters added through the §6.1 filters and one card per configured partner API (`partner_api:<prospect_id>`, "Partner APIs" group) | `GET /providers`, `GET /providers/{id}` |
| Configure a partner API for a destination | `POST /providers/partner-api` (`prospect_id`, endpoint, method, field map, `success_path`, declared limits), `DELETE /providers/partner_api:{prospect_id}` (archives) |
| Save credentials (fields from `credentialSchema()`) | `POST /providers/{id}/credentials` |
| Test connection | `POST /providers/{id}/test` |
| Enable or disable | `POST /providers/{id}/enable` |
| Limit overrides | `POST /providers/{id}/limits` |
| Reset circuit | `POST /providers/{id}/reset` |
| OAuth connect and disconnect | `GET /providers/{id}/oauth/start`, `POST /providers/{id}/disconnect` (callback: `admin-post.php?action=lga_oauth_callback`) |
| Activity history | `GET /providers/{id}/activity?from=&outcome=&page=` |
| Destination authorizations | `GET /authorizations`, `POST /authorizations`, `DELETE /authorizations/{id}` |

**Automation**

| Widget | Endpoint |
|---|---|
| Master switch "Pause everything" (`automation.enabled`) and module switches (all modules in §2.2) | `GET /automation/status`, `POST /automation/toggle` (`key` = `enabled` or `modules.<id>`) |
| Task schedules (frequency select with all six frequencies plus continuous/off where allowed, priority, gates and the blocking reason, enabled, last run, next run, last status, run now) | `GET /automation/schedules`, `POST /automation/schedules`, `POST /automation/tasks/{task}/run` |
| Runner mode, heartbeat (all sources and the last server heartbeat), token (last 4 characters), crontab lines generated for this site (per-site lock and token file names; lines C/D only in `auto` mode; the network line on multisite) | `GET /automation/cron`, `POST /automation/cron/mode`, `POST /automation/cron/token` (returns the token once), `DELETE /automation/cron/token` |
| Recent runs (the last 50 runner log rows, §5.5) | `GET /automation/runs?source=&status=&limit=` |
| Daily, weekly and monthly limit meters | `GET /limits` |
| Queue (filters, retry, retry failed, purge, cancel queued) | `GET /automation/jobs`, `POST /automation/jobs/{id}/retry`, `POST /automation/jobs/retry-failed`, `POST /automation/jobs/purge`, `POST /automation/cancel` |
| Health | `GET /automation/health` |
| Pause state (LoadGuard) | `GET /automation/status` |

**On-site SEO**: engine routes `/seo/*`. **Logs**: `/logs`, `/audit`, `/notifications`.

**Settings tabs** (all read through `GET /settings` and saved through `POST /settings` with schema validation unless noted)

| Tab | Keys / extra endpoints |
|---|---|
| Business profile | `site.*` |
| Market and languages | `market.*` |
| Industry and audience | `industry.*`, `audience.*`; `GET /packs`, `POST /packs/import` |
| Keywords and URLs | `keywords.*` |
| Exclusions | `discovery.excluded_domains`, `keywords.excluded_urls`, `keywords.excluded`, `site.owned_domains`; owned-site suggestions `GET /owned-sites/suggestions`, `POST /owned-sites/suggestions/{domain}` (`action` = confirm or reject); suppression list `GET/POST /suppression`, `DELETE /suppression/{id}`, `GET /suppression/export`, `POST /suppression/import` (§2.8) |
| Automation and limits | `automation.*` (modules, submission mode, daily/weekly/monthly limits, thresholds) |
| Scoring | `scoring.weights` (sum must be 100), thresholds |
| Content | `content.*` |
| Outreach and compliance | `outreach.*`; `GET /outreach/jurisdictions`, `POST /outreach/sender` |
| Distribution | `distribution.*` |
| Notifications | `notifications.*`; `POST /notifications/test` |
| Data | `GET /catalog`, `POST /catalog/import` |
| Advanced | `advanced.*`; `GET /diagnostics`; "Removing the plugin" panel (`advanced.delete_on_uninstall`, `advanced.receiver_uninstall_action`, the list of syndicated copies and their canonical store, "Copy canonicals into {SEO plugin} now": `GET /syndication/received`, `POST /syndication/received/copy-canonicals`) |
| Privacy | `advanced.contact_retention_days`; personal-data export and erasure go through WordPress's Tools > Export/Erase Personal Data (§11.9) |

**Setup wizard**: `GET /setup` (state and step), `GET /setup/suggestions` (prefill values marked unverified), `POST /setup/step/{step}` (validates and saves one step), `POST /setup/complete` (requires the required fields of §2.1; records consents).

**Public routes** (no login): `POST /cron/run` (token, §5.6), `POST /webhook/confirm` (HMAC signature, timestamp within 5 minutes, nonce replay cache 10 minutes), `GET /syndication/capabilities` (only when the receiver is on; anonymous callers get exactly `{"receiver":true}`, with no version, allowed sources or settings; a caller authenticated with an Application Password of a user with `edit_posts` also gets `{version, accepts_canonical:true, allowed:bool}` where `allowed` says whether the caller's site is in `receiver_allowed_sources`), `GET /unsubscribe` and `POST /inbound` (engine). Each has its own rate limit and returns no internal data. Test: I `permissions` (anonymous capabilities response has exactly one key).

`Security\PermissionMap` lists every route with its required capability or public auth type; `tests/integration/permissions.php` walks `rest_get_server()->get_routes()` for the `lga/v1` namespace and fails on any route missing from the map, then calls each one as anonymous, subscriber, editor, submitter and administrator.

---

## 11. Security, performance and self-healing additions

### 11.1 Security

- **SSRF guard** (`Security\UrlGuard`), applied to every outbound request in `Support\Http\Client` and on every redirect hop: schemes http and https only; ports 80 and 443 only (fixture ports only under `LGA_ALLOW_LOCAL_HOSTS`); resolve A and AAAA (`dns_get_record`), refuse if **any** address is in a blocked range: 0.0.0.0/8, 10/8, 100.64/10, 127/8, 169.254/16, 172.16/12, 192.0.0/24, 192.0.2/24, 192.168/16, 198.18/15, 198.51.100/24, 203.0.113/24, 224/4, 240/4, 255.255.255.255, ::/128, ::1/128, ::ffff:0:0/96 (checked as IPv4), 64:ff9b::/96, 100::/64, 2001:db8::/32, fc00::/7, fe80::/10, ff00::/8; pin the checked address for the connection (`CURLOPT_RESOLVE` via `http_api_curl`) so a DNS rebind between check and connect has no effect; self-requests (render check, own pages) go only to `home_url()`'s host through an explicit allowlist. `reject_unsafe_urls` stays on and is re-applied **after** the `lga_http_request_args` filter, so a filter cannot turn the guard off.
  - **Transport.** Pinning works only with the cURL transport, because `http_api_curl` fires only there. WordPress's HTTP API (the Requests library) uses its cURL transport whenever `WpOrg\Requests\Transport\Curl::test(['ssl' => true])` passes, and falls back to fsockopen otherwise. So, before every plugin request to a host other than the site's own, `UrlGuard::assertPinnable()` runs that same test and refuses the request with `lga_transport_unpinned` when it fails. Crawling, discovery and provider calls stop, while own-host self-requests still work.

    The guard also **fails closed**. Every plugin request carries the arg `lga_request => <request id>`, and the `http_api_curl` callback records that id when it sets `CURLOPT_RESOLVE`. If a plugin request completes without that record (some other transport was used), `Support\Http\Client` discards the response, logs a critical guard failure, and refuses further third-party requests for the rest of the process. The health check `environment` warns ("The PHP cURL extension is missing, so outbound crawling and provider calls are disabled.").
  - **HTTP proxy.** When `WP_PROXY_HOST` is set and `WP_HTTP_Proxy::send_through_proxy($url)` is true for a URL, the proxy resolves the name and pinning has no effect. The guard then applies **host checks** instead: the URL's host must not be an IP literal in a blocked range, must not be `localhost`, `*.local`, `*.internal` or a single-label name, and must resolve publicly from this server (the same range check, without pinning). The request goes out only through the configured proxy, and the response's final URL is checked on every redirect hop. The `environment` check warns ("Requests go through a proxy: address pinning is replaced by host checks; make sure the proxy itself blocks private networks"). SECURITY.md documents this, and that the owner's proxy must refuse internal destinations.
  - Tests (WP11): U `UrlGuardTest` gains three cases. Without cURL (`function_exists` override in the test namespace), a third-party URL is refused and an own-host URL is allowed. With a proxy (`WP_PROXY_HOST` defined in a separate process), the request is allowed only after host checks, pinning is not attempted, and a host resolving to 10.0.0.5 is refused. With cURL and no proxy, `CURLOPT_RESOLVE` is set to the checked address and the request id is recorded. A request completed without the record (simulated by a `pre_http_request` short-circuit that bypasses the hook) is discarded, and the next third-party request is refused.
- **Cron token** (§5.6): random, hashed at rest, header or body only, rate-limited, constant-time compare.
- **Webhook signatures** (`Security\RequestSigner`): HMAC-SHA256 over `timestamp + "." + body`, `X-LGA-Signature: sha256=<hex>`, 5-minute window, replay cache.
- **OAuth**: single-use `state`, admin-post callback, tokens encrypted (§6.3).
- **Calculator formulas** (`Resources\Calculator\Expression`): a shunting-yard parser accepting numbers, declared input names, `+ - * / % ^`, parentheses, and the functions `min max round floor ceil abs sqrt pow log exp`; anything else is a parse error. The same grammar is implemented in `assets/js/front/calculator.js`; no `eval`, `new Function` or `create_function` anywhere (a lint rule greps for them). Server-side validation runs the owner's test cases.
- **Uploads**: CSV only (`wp_check_filetype_and_ext`, `text/csv` or `text/plain`), 5 MB, parsed with `fgetcsv($h, null, ',', '"', '')` (escape passed explicitly for PHP 8.4+), cells escaped on output, formula-looking cells (`=`, `+`, `-`, `@` at the start) prefixed with `'` in CSV downloads (CSV injection).
- **Output**: all PHP-rendered output escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` for resource bodies built from templates); JSON-LD via `wp_json_encode` with `JSON_HEX_TAG`; SVG charts built from escaped text only.
- **Never execute untrusted code**: API responses are only JSON-decoded; `unserialize` only with `allowed_classes => false`; job types limited to registered classes; due-checks resolved from internal method names (§5.1).
- **Capabilities and nonces**: engine model kept; new routes in the permission map; destructive routes (`purge`, `retire`, token rotation, uninstall data deletion) require `confirm: true`.

### 11.2 Redaction (spec §13 "diagnostics without secrets or personal data")

- `Support\Redactor` additionally masks URL query parameters named `key`, `apikey`, `api_key`, `app_api_key`, `access_token`, `token`, `password`, `secret`, `signature`, `client_secret`, `code` and `state`, in log messages, contexts, activity rows, job errors and notification data. The HTTP client logs URLs only after redaction.
- Job `error` and `result` text pass through the Redactor before storage.
- Emails in logs are masked fully (`•••@example.com`), not only to the first character.
- Provider responses stored in activity or submissions are capped at 16 KB and redacted.

### 11.3 Performance and caching

- **Front-end requests** do only: the IndexNow key check on `parse_request` (a regex on the path; the database is touched only when the path looks like a key file), block and shortcode registration (no queries), the syndication receiver's canonical filter (only when `lga_runtime.receiver_enabled`, using cached post meta), and the calculator script enqueue on pages that contain one. `Migrator::maybeMigrate`, `Capabilities::ensure`, `Scheduler::maybeSchedule` and LoadGuard's version note run on `admin_init`, before the tick, on our REST namespace and in WP-CLI, never on front-end requests (fixes the engine's uncached option reads on every page view). Target, asserted by a test: zero extra database queries on a front-end page view, product page, cart and checkout with the plugin active, compared with it inactive, and no plugin asset enqueued there.
- **Page caches and CDNs**: the cron endpoint is POST and sends no-store headers and `DONOTCACHEPAGE`; docs list the exclusions (`/wp-json/lga/v1/*`, the IndexNow key path). After the plugin publishes or changes content it calls `clean_post_cache()` and, when present, `rocket_clean_post()` (WP Rocket), `do_action('litespeed_purge_post', $id)` (LiteSpeed), `w3tc_flush_post()` (W3 Total Cache) and `wp_cache_post_change()` (WP Super Cache), and fires `lga_content_changed` for others.
- **Object cache**: Locks, Settings and GrowthRun keep bypassing or invalidating the cache as in the engine. The integration suite runs once with a file-backed persistent object-cache drop-in (test-only) to prove nothing depends on a non-persistent cache.
- **Admin**: the Overview endpoint reads aggregated counters and cached health (60 s) instead of live provider calls; live GSC/GA4 totals come from `lga_analytics_daily`.
- **Admin screens that are not ours** (dashboard, posts list, WooCommerce orders and others) get only this from the plugin on `admin_init`, all from the autoloaded `lga_db_version` and `lga_runtime`:
  - `Migrator::maybeMigrate()` compares versions and returns without a query when they match;
  - `Capabilities::ensure()` compares `lga_runtime.caps_version`;
  - `Scheduler::maybeSchedule()` returns unless `schedule_checked_at` is older than one hour, and then costs at most 3 queries once per hour;
  - `Notices` reads only `lga_runtime.critical_health` and `setup_completed`, and queries notification rows only on plugin pages, `index.php` and `plugins.php`.

  No plugin script or style is enqueued outside `lga` pages (checked by handle prefix). Target: at most 3 extra queries and less than 15 ms extra (median of 10 requests, warm opcode cache) per admin screen, compared with the plugin inactive.
- **Checkout**: no plugin code runs on the Store API checkout path. Test I `frontend-cost` also posts `POST /wp-json/wc/store/v1/checkout` with a cart from the fixture session and asserts 0 extra queries compared with the plugin inactive. A test mu-plugin hooks `all` and records hook names starting with `lga_`, and `Plugin::$requestWork` (a counter every work path increments) must stay 0. The order is created normally.
- **Indexes** for all new tables as in §4.3.

### 11.4 Cleanup (task `cleanup`, daily)

Batched deletes (`DELETE … WHERE id IN (SELECT id … LIMIT 1000)` style via a select of ids, repeated within the tick budget) for:
- logs, runner rows included (`log_retention_days`, 30), and audit (`audit_retention_days`, 365);
- completed and canceled jobs (`job_retention_days`, 14) and failed jobs (30);
- read notifications (90) and all notifications (365);
- search log (180) and provider activity (`activity_retention_days`, 90);
- backlink checks (`check_history_days`, 730, keeping the first and last check of each month after 180 days);
- analytics: daily dimension rows older than 90 days rolled up to weeks and then deleted (§9.1), and all rows older than `analytics_retention_months` (25);
- strategy events (18 months) and decisions (180);
- expired domain metrics (60 days after expiry) and counters older than 13 months;
- expired suppression cool-downs (`expires_at` past);
- **personal data past retention** (§11.9);
- expired `lga_` transients.

Each run logs how many rows it removed or rolled up per table.

### 11.5 Diagnostics

`Health\Diagnostics::report()` (`GET /diagnostics`, `wp linkgrowth diagnostics`, a "Copy diagnostics" button): plugin, DB and schema versions; WordPress, PHP, MySQL or SQLite versions; extensions; runner mode, last tick, heartbeat; Action Scheduler status; object cache and page-cache plugin; WooCommerce version and HPOS state; active SEO and multilingual plugins; table row counts; health results; provider states (no credentials, no URLs with query strings, no emails). Safe to paste into a support ticket.

### 11.6 Multisite

Network activation installs tables and defaults per site in batches of 100 sites (`get_sites`), the rest lazily on the first `admin_init` of each site; `wp_initialize_site` installs new sites; `wp_uninitialize_site` drops that site's tables when `delete_on_uninstall` is on. Every site keeps its own settings, secrets, packs, tables, schedules and history (spec §11). Secret constants: `LGA_SECRET_<BLOGID>_<KEY>` first, then `LGA_SECRET_<KEY>` (documented as network-wide). Server cron for a network is `wp linkgrowth run --network` or the `wp site list | xargs` line (§5.6). Each site keeps its own lock, heartbeat and token, and the HTTPS endpoint is per site (`https://<site>/wp-json/lga/v1/cron/run`).

### 11.7 WooCommerce

Optional. Declares HPOS (`custom_order_tables`) and cart/checkout blocks compatibility on `before_woocommerce_init`. Order reads only through `wc_get_orders()` and `WC_Order` getters. Never hooks into cart, checkout or order processing. Product data for comparison resources is read through `wc_get_products()`.

### 11.8 PHP 8.1-8.5 coding rules (checked by `bin/php85-scan.sh` in lint)

No implicitly nullable typed parameters (`Type $x = null`), no `(boolean)`, `(integer)`, `(double)` or `(binary)` casts, no backtick shell operator, no `case X;` with a semicolon, no `null` as an array offset, no `__sleep`/`__wakeup` (use `__serialize`/`__unserialize`), no `curl_close`, `finfo_close`, `xml_parser_free` or `imagedestroy` calls, no `setAccessible()` in tests, no `E_STRICT`, no `lcg_value()`, no `DATE_RFC7231`, explicit `$escape` for `fgetcsv`/`str_getcsv`/`fputcsv`. The scan is grep-based and reports file and line; the real check is running the suites on PHP 8.5 with `E_ALL` and PHPUnit `failOnDeprecation="true"` (§12.6).

**PHP 8.1 floor (static, same script, `--min=8.1`).** No PHP 8.1 runtime is available here, so the declared minimum is backed by a scan of `includes/`, `uninstall.php` and the main file for syntax and functions newer than 8.1:
- PHP 8.2: `readonly class`; DNF types (`(A&B)|null`); standalone `true`, `false` and `null` types; constants in traits; `#[\SensitiveParameter]` is allowed because it is only an attribute; `mysqli_execute_query`; `ini_parse_quantity`; `memory_reset_peak_usage`.
- PHP 8.3: typed class constants (`const string X`); `#[\Override]`; dynamic class constant fetch (`Foo::{$x}`); `json_validate`; `mb_str_pad`; `str_increment`/`str_decrement`; `Random\Randomizer::getBytesFromString`.
- PHP 8.4: property hooks; asymmetric visibility (`public private(set)`); `new Foo()->x` without parentheses; `array_find`, `array_find_key`, `array_any` and `array_all`; `mb_trim`, `mb_ltrim`, `mb_rtrim` and `mb_ucfirst`; `#[\Deprecated]`; `request_parse_body`.
- PHP 8.5: the pipe operator `|>`; `clone(` with a second argument; `array_first`/`array_last`; `#[\NoDiscard]`.

A use is allowed only inside `if ( function_exists(…) )` or `PHP_VERSION_ID >=` guards that the scan recognizes. The scan ends with `php -l` on every file run by the oldest PHP available (8.3), and TEST-RESULTS.md labels the 8.1 floor "static only".

**WordPress 6.5 and WooCommerce 8.0 floors (static, `bin/compat-api.php`).** The script tokenizes the plugin (`token_get_all`). It collects every called function name, `new`/static class name and `add_action`/`add_filter`/`do_action`/`apply_filters` hook name that is not the plugin's own, then finds each one's `@since` in the WordPress core and WooCommerce source of the test site (read-only, `$S/wp-test-lga/wordpress`). A symbol whose `@since` is newer than 6.5 (WP) or 8.0 (WC) fails unless it sits inside a `function_exists`/`class_exists`/`method_exists` or version guard. Symbols without `@since` are listed as "unknown" in the report, not failed. TEST-RESULTS.md labels these floors "declared; API scan passed; not run", unless the optional `compat-min` run (§12.9) succeeds.

### 11.9 Privacy and personal data (spec §12 "no unnecessary tracking", §13)

The plugin stores personal data of third parties: contact names and emails, outreach messages, and inbound replies (`outreach_messages.reply_summary`, `replied_at`). It stores none about site visitors, and WooCommerce attribution is aggregated with cells under 3 merged (§6.6).

- **No telemetry.** The plugin makes no request of its own to any service the owner did not configure: no usage statistics, no phone-home, no licence or update check. Outbound requests go only to the sites being researched (after setup stage 1, §2.1) and to providers the owner connected. SECURITY.md states this and names the test that backs it (I `activation-silence`). The WordPress.org update check is core's, not the plugin's.
- **Retention** (`Privacy\Retention`, run inside `cleanup`). Contacts whose `last_activity_at` (or `updated_at` when null) is older than `advanced.contact_retention_days` (default 730, range 90-3650) are removed with their messages' bodies, subjects and reply summaries. Message rows are kept, anonymized, for counts and the decision engine: `contact_id` set to 0, body, subject, reply and recipient set to `''`. Their suppression entries are converted to `type = email_hash` (SHA-256 of the lower-cased address), so an opt-out is still honored without storing the address. Hashed suppression rows are kept indefinitely, as opt-out evidence. CAN-SPAM opt-outs are kept permanently as hashes, matching §2.6.
- **Exporter and eraser.** `wp_privacy_personal_data_exporters` registers "LinkGrowth Autopilot contacts" (contacts, messages and replies for the email, in pages of 50). `wp_privacy_personal_data_erasers` registers an eraser that deletes the contact rows, blanks the message bodies and replies as above, keeps a hashed suppression row, and reports `items_retained: true` with the message "An opt-out record (hashed address) is kept so this address is never contacted again." Both handle multisite per site.
- **Privacy policy text.** `wp_add_privacy_policy_content()` suggests text describing the research crawling, the outreach contact data and its retention, and the absence of visitor tracking.
- **Logs** never contain full emails (`•••@example.com`, §11.2), and diagnostics contain none (§11.5).
- Tests (WP8): U `PrivacyRetentionTest` (contact past retention removed, messages anonymized, suppression converted to a hash and still blocking a send to that address) and I `privacy` (the WordPress exporter returns the contact's data; the eraser removes it and leaves a hash that still suppresses; a second erase is a no-op).

---

## 12. Test plan (spec §15)

### 12.1 Environments

| Environment | Path (`S` = `/tmp/claude-0/-home-user-Growth-engine-PPC/e01ede86-43cd-5f84-9cca-7e7b80d17fc9/scratchpad`) | URL | Contents |
|---|---|---|---|
| LGA integration site | `$S/wp-test-lga/wordpress` | `http://localhost:8793` | WordPress 7.1.4, WooCommerce 11.3.0 (bundles Action Scheduler 4.1.0), SQLite Database Integration 3.1.2 drop-in, theme twentytwentyfive; `WP_DEBUG` and `SAVEQUERIES` on, `DISABLE_WP_CRON` true; mu-plugin `lga-test-env.php` (neutral: allows the fixture host, serves fixtures, freezes time through `lga_test_time`); the plugin folder symlinked into `wp-content/plugins/linkgrowth-autopilot` |
| Fixture server | `linkgrowth-autopilot/tests/fixtures/router.php` | `http://127.0.0.1:8794` | neutral fixture sites (directory, resource page, blog with mention, spam site, publisher, syndication target, provider API mocks) |
| ZIP install site | `$S/wp-zip-lga/wordpress` | `http://localhost:8795` | the same stack, without the plugin |
| Syndication receiver site | `$S/wp-recv-lga/wordpress` | `http://localhost:8796` | the same stack, with the plugin installed from the ZIP and the receiver on |
| Multisite network | `$S/wp-ms-lga/wordpress` | none (in-process only; host `lga-ms.test`) | a copy of the LGA site's core and plugins with its own SQLite database, turned into a subdirectory network **programmatically** (see below) |
| Minimum-versions site (optional `compat-min`) | `$S/wp-min-lga/wordpress` | `http://localhost:8797` | WordPress 6.5.x and WooCommerce 8.0.x, downloaded from wordpress.org and the WooCommerce release archive into the scratchpad when downloads are permitted; run on the available PHP (8.3). If a download is refused, the section is "not run" with that reason |

**Multisite build** (`tests/integration/build-multisite.php`, run once by the `multisite-activation` section). WordPress's `network.php` refuses site addresses with a port, so the network is not created through the UI. The script:
1. Writes `wp-config.php` with `WP_ALLOW_MULTISITE` true, `DOMAIN_CURRENT_SITE = 'lga-ms.test'`, `PATH_CURRENT_SITE = '/'`, `SITE_ID_CURRENT_SITE = 1`, `BLOG_ID_CURRENT_SITE = 1` and `SUBDOMAIN_INSTALL = false`, with `MULTISITE` left undefined.
2. Installs a single site with `wp_install()` and home `http://lga-ms.test`.
3. Loads `wp-admin/includes/network.php`, calls `install_network()` and then `populate_network(1, 'lga-ms.test', <admin email>, 'LGA MS', '/', false)`.
4. Adds `define('MULTISITE', true)` to `wp-config.php`.
5. Creates three sites with `wp_insert_site()`.

The integration runner then loads WordPress in-process with `$_SERVER['HTTP_HOST'] = 'lga-ms.test'` (no HTTP server is needed, so the port limit never applies). The section network-activates the plugin and asserts tables and defaults per site, `wp_initialize_site` for a fourth site, per-site settings isolation, and `wp linkgrowth run --network` through real WP-CLI with `--url` or the shim. If any build step fails, the section reports "not run" with the failing step, never "passed".

Building the LGA site (WP1): copy WordPress core, `wp-content/plugins/woocommerce` and `wp-content/plugins/sqlite-database-integration` from the existing `$S/wp-test/wordpress` (read-only use), **without** its `wp-content/database`, mu-plugins and the PPC symlink; write a new `wp-config.php` (URLs on port 8793, new salts, `DB_ENGINE` sqlite); install with WP-CLI `core install` (or a PHP script calling `wp_install()` if WP-CLI is unavailable); activate WooCommerce. Servers run as `nice -n 10 php -S localhost:8793 -t …` and are stopped after each suite. The existing `wp-test` site and the perf-soak sites are never modified.

For the `coexistence` section only, the PPC plugin folder is symlinked into the LGA site's `wp-content/plugins/` for the duration of the test and removed afterwards; its files are never modified, and the section adds it to the `lga_known_conflicts` filter through the test mu-plugin.

### 12.2 Demo businesses (neutrality)

Two fictional profiles in `tests/Support/Profiles.php`, used by the unit and integration suites:

- **Northwind Supply Co.**: `northwind.test`, United States (US-OR), English, USD, pack `general`, categories "Industrial Supplies", "Packaging". No positioning statement.
- **Fjordline Lab Supplies**: `fjordline.test`, United Kingdom (GB-SCT), English, GBP, pack `research-supplies`, positioning on.

Core content, discovery, outreach and scoring tests run under both (PHPUnit data providers), proving that nothing depends on one brand, country or industry. `NeutralityTest` (§1.7) runs on every unit run.

### 12.3 Suites and commands

| Suite | Command (from `linkgrowth-autopilot/`) | What it covers |
|---|---|---|
| Lint | `nice -n 10 bash bin/lint.sh` | `php -l` of every PHP file, `node --check` of every JS module, JSON validity and pack/catalog schema validation, `bin/neutrality.sh`, `bin/php85-scan.sh` (8.5 rules and the `--min=8.1` floor, §11.8), `php bin/compat-api.php` (WP 6.5 / WC 8.0 API floor), forbidden functions (`eval`, `create_function`, `new Function`), the restriction scan (`RestrictionsTest`, §12.9) |
| Unit | `nice -n 10 bash bin/test.sh` | PHPUnit 11.5.57 (`bin/phpunit.phar`), `FakeWpdb`, HTTP fixtures, stubs; `failOnWarning`, `failOnDeprecation` |
| Integration | `LGA_WP_PATH=$S/wp-test-lga/wordpress nice -n 10 php tests/integration/run.php` (`LGA_IT_ONLY=<section>` for one section) | Real WordPress, WooCommerce and SQLite; sections listed in §12.9 |
| Browser E2E | `NODE_PATH=/usr/local/lib/node_modules_global PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers nice -n 10 node tests/e2e/run.mjs` | Every page and control (§12.5) |
| ZIP | `bash bin/build-zip.sh && nice -n 10 node tests/e2e/zip-install.mjs` | Clean install through the upload form (§12.8) |
| PHP 8.5 | the lint, unit and integration commands with `PATH=$S/php85/bin:$PATH` | §12.6 |

### 12.4 Mocks and "simulated versus real"

- Provider mocks are fixture routes in `tests/fixtures/router.php` (`/mock/bing/…`, `/mock/majestic/…`, `/mock/ga4/…`, `/mock/gsc/…`, `/mock/mastodon/…`, `/mock/bluesky/…`, `/mock/linkedin/…`, `/mock/facebook/…`, `/mock/hook/…`, plus the engine's), each able to return success, 401, 403, expired token, 429 with `Retry-After`, 500, 503, timeout (sleep longer than the client timeout, capped at 3 s in tests), malformed JSON and an unsupported-operation error. The base URLs are switched with the `lga_provider_base_url` filter, which only works when `LGA_ALLOW_LOCAL_HOSTS` is honored.
- Rows created through a provider in mode `mock` or `sandbox` get `is_simulated = 1`, a "Simulated" badge in the UI, and are excluded from KPIs, the decision engine and "successful" counts. A unit test asserts that a simulated provider can never produce a non-simulated `provider_confirmed` or `live_verified` row.
- `TEST-RESULTS.md` labels each result as **real WordPress**, **fixture/mock**, **static**, or **browser**, and lists every suite that did not run with the reason. No live third-party API is called by any test.

### 12.5 Browser E2E of every dashboard control

- `tests/e2e/run.mjs` (Playwright 1.63.0 via `createRequire`, Chromium at `/opt/pw-browsers/chromium-1243`, headless) logs into the LGA site as an administrator, seeds data through REST, then visits every page in `lga-config.pages` and every Settings tab and wizard step.
- On each page it collects all `[data-lga-control]` elements. Each must be described in `tests/e2e/controls/<page>.json` as `{control, action: click|fill|select|toggle, input?, expect: {request: "METHOD /route", status: 2xx|4xx, dom?: selector-or-text}}`. The test performs the action, waits for the request, checks the status and the DOM change, and fails on: an element with no manifest entry, a manifest entry with no element, an action that sends no request and changes nothing (spec §10 "no decorative buttons"), any console error, any failed request not declared as expected.
- Permissions pass: the same walk as the submitter role sees only the owner-task controls.
- Responsive pass: screenshots of each page at 1280 and 390 px in light and dark themes (saved under `$S/e2e-shots/`, never committed); a check that `document.documentElement.scrollWidth <= innerWidth` at 390 px.
- If the browser cannot start, the suite reports "not run (reason)" and the release gate in WP31 fails.

### 12.6 PHP 8.5

Option A (preferred, from the environment findings): user-space extract of the Ondrej PPA packages `php8.5-{cli,common,readline,sqlite3,mbstring,xml,curl,zip,intl,gd}` version `8.5.13-2+ubuntu24.04.1+deb.sury.org+1` into `$S/php85/root` with `dpkg -x`, after checking each `.deb` with `sha256sum` against the values recorded in the environment notes; `$S/php85/etc/php.ini` with `extension_dir=$S/php85/root/usr/lib/php/20250925`, the extension list (pdo before pdo_sqlite, xml before dom and the readers), `memory_limit=512M`, `opcache.enable_cli=1`, `openssl.cafile` and `curl.cainfo` at `/root/.ccr/ca-bundle.crt`; wrapper `$S/php85/bin/php`:

```sh
#!/bin/sh
PHPRC=$S/php85/etc PHP_INI_SCAN_DIR= exec $S/php85/root/usr/bin/php8.5 "$@"
```

The environment variables (not `-c`) matter because the integration fixtures start servers with `PHP_BINARY -S`, which inherits `PHPRC`. All shared libraries the packages need are already installed, so no apt install is needed. Option B (fallback): the static-php-cli 8.5.8 binary (no intl, no sodium; WordPress falls back to sodium_compat). Never a source build or a system install.

Runs: lint, unit (`failOnDeprecation`, and the test bootstrap converts `E_DEPRECATED` raised from plugin files into failures), integration. TEST-RESULTS.md states the exact PHP version run. If neither option works, PHP 8.5 is reported as "statically checked only".

### 12.7 WP-CLI

`$S/tools/wpcli/wp-cli.phar` from `https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar`, verified with the published `.sha512`, run as `php $S/tools/wpcli/wp-cli.phar --allow-root --path=$S/wp-test-lga/wordpress …` (also with the PHP 8.5 wrapper). If the download is not permitted, the CLI command class is tested through a minimal `WP_CLI` shim in `tests/Support/WpCliShim.php` and the report says "WP-CLI logic tested through a shim; real WP-CLI not run".

### 12.8 Clean-install ZIP test

1. `bin/build-zip.sh` builds `dist/linkgrowth-autopilot-1.0.0.zip`; `unzip -l` must show a single top folder `linkgrowth-autopilot/` and none of `tests/ docs/ bin/ dist/ node_modules/ .phpunit.cache/`; the neutrality scan runs on the extracted copy.
2. `tests/e2e/zip-install.mjs` opens `/wp-admin/plugin-install.php?tab=upload` on the ZIP site, uploads the file through the form, activates it from the result page, and checks: no PHP notice in `debug.log`, the setup wizard opens, all 29 tables exist, `lga_db_version` is `1.0.0`, the tick is scheduled.
3. Completes the wizard with the Northwind profile, runs `wp linkgrowth run --seconds=30` (or the HTTP cron endpoint with a fresh token), and checks the heartbeat and a completed job.
4. Deactivates (cron and AS actions gone, data kept), reactivates (idempotent), then deletes the plugin through the Plugins screen with `delete_on_uninstall` on: tables, options, role and caps gone; resource pages kept.
5. Repeats steps 2-4 with the PHP 8.5 wrapper serving the site when available.

### 12.9 Coverage of spec §15

| Spec §15 item | Tests (U = unit, I = integration section, B = browser, S = static) |
|---|---|
| Activation | I `activation` (tables, defaults, caps, schedule, setup redirect), I `activation-silence` (activation plus 10 ticks: 0 requests to non-own hosts, §2.1), I `multisite-activation` on the programmatically built network (§12.1; "not run" with the failing step if the build fails) |
| Deactivation | I `deactivation` (lga_tick cron and AS actions removed, data kept, no front-end hooks, reactivation idempotent) |
| DB install | U `SchemaTest` (FakeWpdb from real statements, 29 tables, indexes), I `activation` |
| DB upgrades | I `upgrade-0.9.0` (fixture schema with data, migrate to 1.0.0, status mapping, counters and provider state moved, backup written) |
| Cron execution | U `FrequencyTest` (15 min, hourly, 6 h, daily, weekly, monthly incl. 31 January → 28/29 February, DST changes), I `scheduler` (frozen clock), I `server-cron` (missing token 403, query-string token 400, wrong token 401, rate limit 429, valid token runs, busy lock, heartbeat, headers), I `wp-cli` (run, status, health exit codes, queue retry and purge, token rotate) |
| Background jobs | U `JobsQueueTest` (claim, complete, fail, active_key dedupe under two connections), I `pipeline` |
| Retries | U `BackoffTest` (seeded jitter bounds, Retry-After floor, caps), U `ErrorClassifierTest`, U `FailedJobRecoveryTest` (requeue cap 2), I `retries` (fixture 503 then 200) |
| Duplicate prevention | U `RepositoriesTest` (submissions, messages, distributions, resources `topic_type`, analytics upsert), I `duplicates` (same destination twice, same contact twice, same resource to the same channel twice, same job enqueued from two requests, approve route count), U `SuppressionImportTest` (a domain contacted from another owned site gets the cool-down) |
| API authentication | U per provider: valid, 401, 403, expired token, missing scope (`ProviderAuthTest` data provider over all providers); the provider state and notice are checked, and the logs are checked for absence of the secret |
| API failure handling | U per provider: 429 with Retry-After, 500, 503, timeout, malformed JSON, unsupported operation; circuit opens, half-opens with one probe, closes; no infinite loop (bounded call count) |
| Link verification | U and I `monitoring` (engine cases plus destination check, redirects, rel direction, hidden link alert, content drift, ownership change, first_verified_at) |
| Backlink status changes | U `BacklinkMonitorTest` (lost, broken, redirected, unverified), I `recovery` (domain loss creates a `link_recovery` opportunity and a `domain_lost` ledger event) |
| Dashboard operation | B every page and control (§12.5) |
| Permissions | I `permissions` (route map completeness; anonymous, subscriber, editor, submitter, administrator) |
| Security | U `UrlGuardTest` (every blocked range, IPv6, mapped IPv4, redirect to a private address, DNS rebind through a test resolver), U `CronTokenTest`, U `RequestSignerTest`, U `ExpressionTest` (grammar, rejection of anything else, fuzz of 1,000 random strings with no PHP error), U `RedactorTest` (query keys), I `escaping` (resource HTML with `<script>` in facts and CSV cells renders escaped) |
| Performance | I `frontend-cost` (`SAVEQUERIES` difference 0 on home, product, cart, checkout page and `POST /wc/store/v1/checkout`; no `lga_` hook fired and `Plugin::$requestWork = 0` on the Store API checkout; no plugin assets except the calculator page), I `admin-cost` (plugin active versus inactive on `wp-admin/index.php`, `edit.php` and the HPOS orders page `admin.php?page=wc-orders`: at most 3 extra queries and less than 15 ms extra (median of 10); no `lga-` handle enqueued outside `lga` pages), I `autoload` (autoloaded `lga_` options under 1 KB), U `CleanupTest` (batched deletes, analytics rollup), I `tick-budget` (a tick stops within budget + 2 s), I `object-cache` (suite subset with the persistent test drop-in) |
| PHP 8.5 | S `php85-scan`, then lint, unit and integration on PHP 8.5.13 (§12.6) |
| PHP 8.1 (declared minimum) | S `php85-scan --min=8.1` (§11.8); reported as "static only" because no PHP 8.1 runtime is available |
| WordPress compatibility | the integration suite on WordPress 7.1.4; S `compat-api` for the declared 6.5 floor; optional I `compat-min` (activation, wizard, one tick, deactivation on WordPress 6.5.x and WooCommerce 8.0.x, §12.1), otherwise the floor is reported as "declared, not run" |
| WooCommerce compatibility | I `woo-hpos` (attribution aggregates with HPOS on, then off with sync; compatibility declaration visible to the FeaturesController), I `growth` (categories and products), S `compat-api` for the declared WC 8.0 floor, optional I `compat-min` |
| Mocks for missing credentials | §12.4 |
| Simulated versus real | U `SimulatedFlagTest`, B badge check |
| Never claim acquisition without confirmation | U `SuccessSemanticsTest` (form "thank you" stays `sent_unconfirmed`; API without `success_path` refused; webhook 2xx is `handed_off`; owner mark is `sent_unconfirmed`; only a found link is `live_verified`) |
| Spec §3E/§4/§7/§11 restrictions | See the restriction table below |
| Fix errors before delivery | WP31 release gate: every suite green or explicitly "not run" with a reason that the owner accepts |

**Restrictions and their tests.** `tests/Unit/Wiring/RestrictionsTest.php` (static, run in lint and the unit suite) and the named integration cases:

| Restriction (spec) | Test |
|---|---|
| Never auto-disavow (§7) | S `RestrictionsTest`: no file write (`file_put_contents`, `fopen` with `w`/`a`, `WP_Filesystem` put) whose path or content builder contains `disavow`; no HTTP call whose URL contains `disavow`; the word appears only in `Monitoring\Recommendations` text and docs. U `RecommendationsTest`: `review_manually` carries evidence and no file |
| No automatic first contact, no unsolicited mass messages (§3E, §4) | I `autopilot-gates` (WP8), I `growth-autopilot` (WP10), I `duplicates` "approve route count" (no route approves more than one first contact per request, §2.7), S `RestrictionsTest` (no code path calls `OutreachMessages::approve()` outside `OutreachController::approveMessage`) |
| Autopilot never raises the submission mode or skips a consent (§3A, §4) | U `AutopilotPolicyTest` (WP4b), I `autopilot-gates` (WP8), S `RestrictionsTest` (no `MODE_AUTO`, no `'auto'` in `Policy::SUBMISSION_MODES`) |
| No outbound work before setup (§12 "no unauthorized execution") | I `activation-silence` (WP4b) |
| No artificial interlinking between owned sites (§11) | I `owned-sites` (WP6): owned and suggested-owned domains never become prospects, contacts or targets; one owned placement per resource; no reciprocal syndication |
| No CAPTCHA bypass, no account creation, no terms ticking (§3A, §4) | U `FormDetectorAntiBotTest` (WP17), I `acquisition` (WP17): register forms, challenge widgets, JS-token forms and required terms boxes are never posted; honeypots stay empty |
| No forum or comment spam, bookmarking, link farms, PBNs (§4) | U `PolicyNeverAutomateTest` (comment and forum forms, `never_pursue_site_types`), U `RiskAnalyzerTest` (PBN footprint, link selling, hacked content) |
| No mass profile creation, no fake listings (§4) | U `CatalogEligibilityTest` (WP9): profile cap 10, document-sharing/Web 2.0/bookmarking/article sites excluded, no maps item for `online_only` or without a verified address |
| No fabricated reviews or testimonials (§4, §5) | U `ClaimsFilterTest` (review solicitation and invented testimonials blocked), U `CatalogEligibilityTest` (review platforms only as claim-profile tasks) |
| Paid placements sponsored or nofollow (§4) | U `PolicyPaidPlacementTest`, U `BacklinkMonitorTest` (followed paid link alert) |
| No automatic publishing of AI text or unverified facts (§5) | U `PublishGateTest`, U `FactsProvenanceTest`, U `ThresholdsSchemaTest` (WP23, WP7) |
| No hidden links in our own output (§4) | I `escaping`: calculator and infographic embeds carry a visible attribution link; no `display:none` link in generated HTML |
| Never pretend a submission succeeded (§4) | U `SuccessSemanticsTest` |

### 12.10 Test results document

`docs/TEST-RESULTS.md` is generated by `bin/test-report.php` from the JUnit XML of PHPUnit, the integration runner's JSON summary and the Playwright JSON reporter: date, PHP versions, WordPress and WooCommerce versions, per suite counts (passed, failed, skipped, not run), the label of each check type (§12.4), and the list of skipped items with reasons.

---

## 13. Work packages

### 13.1 Rules for all packages

- **Definition of done**: lint passes (PHP 8.3); the unit suite is green; the integration sections the package touches are green on the LGA site; the neutrality scan is clean; new controls have E2E manifest entries; `docs/notes/WPnn.md` lists what was built, deviations from this design with reasons, and test evidence (commands and counts).
- **Shared wiring after WP5**: parallel packages add exactly one line to `includes/Core/Modules.php` and otherwise touch only their own files (their module class, controllers, jobs, settings section, SPA page or tab module, tests, `tests/e2e/controls/<page>.json`). If a package must change a shared file (`Plugin.php`, `Support/Settings.php`, `Database/Schema.php`, `assets/js/app.js`, `assets/js/ui.js`, `Admin/Menu.php`), it is marked sequential below.
- Sizing: each package is meant for one strong agent in a few hours. Order and dependencies below; "par" means it can run at the same time as the other packages of its group.

### 13.2 Packages

**WP1 — Fork, rename and test harness** (sequential, first)
- Goal: the renamed copy runs exactly like the engine under the new identity, on its own test site.
- Areas: everything under `linkgrowth-autopilot/` (§1.3 steps 1-7, including `bin/build-zip.sh` writing both the plugin ZIP and the source ZIP), `tests/` port, `tests/PORTING.md`, LGA test site (§12.1), fixture router on 8794, `lga-test-env.php` (with the `pre_http_request` host logger used by `activation-silence`), WP-CLI phar (§12.7), coexistence test.
- Depends: none.
- Acceptance: grep A of §1.7 gives 0 hits; lint OK; the ported unit suite green (count recorded, deleted PPC-only tests listed with successors); `tests/integration/run.php` green on `localhost:8793`; `coexistence` section green with both plugins active; header Version 1.0.0; `bin/build-zip.sh` produces both ZIPs and `bin/neutrality.sh --source` passes on the source ZIP.

**WP2 — Server-side module seams** (sequential)
- Goal: `Core\Module`, `AbstractModule`, `Modules::LIST`; `Plugin`, `Schema`, `Settings`/`SettingsSchema`, `JobRunner::types()`, `TaskRegistry` (with the engine's current cadences as `TaskDefinition`s carrying every field of §5.1, and `TaskRegistry::gate()` with the gate order of §5.2), `RestApi`, `Menu` (pages), `HealthMonitor` skeleton and `CommandRegistry` collect from modules; existing code regrouped into modules (core, discovery, analysis, scoring, submission, outreach, monitoring, seo, integrations, automation, admin, content, compliance).
- Depends: WP1.
- Acceptance: behavior unchanged (full unit and integration suites green); `SettingsController` validates from `SettingsSchema` with the engine's ranges (unit test per rule type); `Pipeline::stages()` removed and the scheduler produces the same enqueue sequence as before for a frozen clock (golden test).

**WP3 — SPA seams and control convention** (sequential)
- Goal: router and navigation from `lga-config.pages`; Settings split into tab modules registered through `settingsTabs`; `ui.js` control helpers setting `data-lga-control`; E2E harness skeleton (`tests/e2e/run.mjs`, manifests for existing pages); responsive check.
- Depends: WP2.
- Acceptance: every existing page and tab works as before (existing browser harnesses green); `tests/e2e/run.mjs` passes on the existing pages with complete manifests; no horizontal scroll at 390 px.

**WP4a — Profile, packs and the complete settings schema** (sequential)
- Goal:
  - `Profile\{Profile, PackRepository, CountryData}`, pack schemas and files (`general` with the `expertise` fact key, `research-supplies`, 8 country packs), secondary-pack merging (§2.3), universal data files.
  - The complete settings schema of §2.2, with every key, type, default and rule (`site.business_type`, `industry.secondary_packs`, the outreach, content, distribution, seo and advanced keys), and `SettingsKeysTest` (§1.7 F).
  - Neutral defaults, `Profile::brand()` everywhere, computed UA and Accept-Language, empty AI model, provider databases from packs.
  - Setup state with `setup.profile_ready` and `setup.completed`, and the `/setup*` REST routes (backend only).
- Depends: WP3.
- Acceptance: NeutralityTest structural checks (§1.7 E) and `SettingsKeysTest` (§1.7 F) pass; brand fallbacks grep clean (§1.7 B); `ProfileTest` and `PackRepositoryTest` (schema validation, custom pack import, invalid pack refused, secondary-pack merge rules); `/setup/suggestions` prefill test on WooCommerce data; `profile_ready` set only after steps 2-4 are valid.

**WP4b — Switches, consents, Autopilot rewrite and outbound silence** (sequential, right after WP4a)
- Goal:
  - The three switches and setup stages (§2.1): `requiresProfile`/`requiresSetup`/consent/`autopilot` gates in `TaskRegistry::gate()`, and reason codes.
  - `Automation\Consents` over `lga_autopilot_consent`, virtual read-only `consent.*` keys, the routes `POST /automation/autopilot` and `POST /automation/consents/{key}/revoke`.
  - `Automation\Autopilot` rewritten method by method (§2.7 table).
  - Deletion of every automatic approval path: `autoApprove*`, `RAISED_FLAGS`, `Policy::MODE_AUTO`, `CampaignFiller::approveWaiting`/`approveDrafts`, `AutomationController::approveWaitingNow` and its `SettingsController` callers, the `approve-drafts` route, and `automation.outreach_autosend`/`auto_outreach_drafts`.
  - `OutreachMessages::approve()` requiring a user id.
- Depends: WP4a.
- Acceptance: U `AutopilotPolicyTest` (every row of the §2.7 table); `RestrictionsTest` static parts for `MODE_AUTO` and the approval call site; I `activation-silence` (activation plus 10 ticks: 0 requests to non-own hosts, §2.1); outbound tasks blocked before `profile_ready` and acquisition tasks before `setup.completed` (integration); POST /settings refuses `consent.*`.

**WP5 — Schema 1.0.0, migrations and repositories** (sequential)
- Goal:
  - All column additions and the 15 new tables (§4), each in its module's `tables()`. These include `opportunities.reject_reason`, `contacts.last_activity_at`, the `suppression` source/expiry/types, the logs index, `lga_facts.origin`/`dataset_*`, `lga_domain_fingerprints.ips_hash`/`registrant_org` and `lga_analytics_daily.grain`/`weight`.
  - The `lga_runtime` markers of §4.4.
  - Migrator versioned steps, including step 0 (settings mapping).
  - Repositories for every new table.
  - The `0.9.0` fixture and upgrade test, and capture of `$wpdb->last_error`.
  - The multisite build script `tests/integration/build-multisite.php` and the `multisite-activation` section (§12.1).
- Depends: WP4b.
- Acceptance: `SchemaTest`; integration `upgrade-0.9.0` (including `submission_mode 'auto'` → `authorized_only` and discarded consents); repositories CRUD tests on FakeWpdb and on SQLite; `multisite-activation` green on the programmatic network (or "not run" with the failing build step); `LGA_DB_VERSION = 1.0.0`.

**WP6 — Discovery and analysis generalization** (par, group A)
- Goal:
  - Every discovery and analysis row of §2.4: QueryGenerator, Taxonomy, RelevanceScorer geo, SiteProfiler country, `Url::countryFromHost`, Classifier subtypes, CompetitorBacklinkSource filters, RiskAnalyzer audience conflict, `include_international`, InternalLinker generic words with `seo.never_anchor`, and PackSeeds; never-pursue site types.
  - The opportunity type enum `Discovery\OpportunityTypes` (§3.4), enforced in the repository.
  - `Profile\OwnedSites` with the fingerprint and RDAP sources and the owned rule in DomainFilter, ProspectIntake, ContactExtractor and the submission policy (§2.8), plus owned-site suggestions routes.
  - SEO-plugin adapters with the write scope of §1.4, and IndexNow conflict detection with the `seo.indexnow` default.
- Depends: WP5.
- Acceptance: discovery, analysis and SEO unit tests under both demo profiles; `include_international=false` rejects a foreign prospect (integration); SEO adapter tests for Rank Math, Yoast and none; U `OpportunityTypesTest`, U `SeoAdapterWriteScopeTest`, U `IndexNowConflictTest`; I `owned-sites` (owned and suggested-owned domains never become prospects, contacts or submission targets) and I `seo-conflicts`.

**WP7 — Content, claims and positioning generalization; facts registry** (par, group A)
- Goal: fact-driven ContentGenerator, ClaimsFilter core rules (including review solicitation) and ClaimPacks, PositioningGate, FactsRegistry on `lga_facts` with the provenance rule of §7.4 (origin, owner-only `verify()`, source match with excerpt, reset on source change, suggested facts), LinkableAssets without defaults (match threshold and live check), `POST /content/check`, `GET /content/facts?origin=`, removal of the JS rules copy.
- Depends: WP5.
- Acceptance: content tests under both profiles (no positioning sentence under `general`; statement kept under `research-supplies`); core rules block invented testimonials, ratings, awards, review requests and link-exchange offers; positioning task not registered without rules; `bestFor()` returns null below the threshold; U `FactsProvenanceTest` (an auto-extracted fact can never reach `verified = 1`; a URL fact without a source match cannot be verified).

**WP8 — Outreach: jurisdictions, per-message approval, follow-ups, neutral templates, privacy** (par, group A)
- Goal:
  - `Outreach\Jurisdictions` (§2.6), consent bases and evidence.
  - The template library with the new placeholders and owner overrides; `{{expertise_topics}}` only from verified facts or `outreach.expertise_topics`; expert_commentary and podcast unavailable without them (§2.7).
  - Personalizer and PackageBuilder neutral; sender alignment check with `sending_domains`.
  - **Per-message approval**: `GET /outreach/messages/{id}/preview` with `preview_token`, and `POST /outreach/messages/{id}/approve` requiring it. "Prepared by LinkGrowth" drafting containers; the drafting cap equal to the remaining daily budget.
  - Follow-up cap and stop conditions with the autosend rule (§2.7).
  - Privacy (§11.9): retention, exporter, eraser, policy text; suppression export and import with cross-site cool-down (§2.8).
  - `GET /outreach/jurisdictions`; the jurisdiction checklist and the preview-and-approve dialog on the Outreach page.
- Depends: WP5 (and WP4b for the Autopilot and approval deletions).
- Acceptance:
  - `JurisdictionsTest` (union and intersection rules for CA sender with US, GB, DE and unknown recipients); a DE recipient with `conspicuous_publication` cannot be approved.
  - I `autopilot-gates`: Autopilot on with all consents leaves the mode `authorized_only`, leaves first-contact drafts `awaiting_approval`, and sends nothing not approved by a user.
  - I `duplicates` "approve route count" (no route approves more than one first contact per request); approval without a valid preview token is refused.
  - U `FollowUpsTest` (cap 1, each stop condition at drafting and before sending, unknown basis, autosend preconditions) and U `TemplatesExpertiseTest`; templates contain no persona fields.
  - U `PrivacyRetentionTest`, I `privacy` and U `SuppressionImportTest`.

**WP9 — Catalog import and neutral catalog** (par, group A)
- Goal: `CatalogImporter` with the inclusion rule and `eligible()` filters of §2.5 (industry relevance, country, maps/local requires a verified physical `location` fact and `business_type` storefront or service-area, never `online_only`; employer and event only with the owner's tick; review platforms as claim-profile tasks only; profile-type cap of 10 open items), `catalog_import` task, `/catalog` routes, Data settings tab, `data/catalog/global-platforms.json` (at most 40 items) produced from the engine data as in §2.5 (scratchpad script, output only), catalog schema (`automatable` pinned to false, excluded categories refused).
- Depends: WP5.
- Acceptance: schema validation; import with filters creates owner tasks with the live profile's kit; re-import adds no duplicates; neutrality scan passes on the catalog; nothing is imported without the owner's action; U `CatalogEligibilityTest`: document-sharing, Web 2.0, bookmarking and article items refused; an 11th profile-type item waits; no maps item for `online_only` or without a verified address; a review item becomes a claim-profile task whose kit has no review request; an item outside the packs' industries refused.

**WP10 — Scheduler v2: tasks, frequencies, limits, queue, runner log, Grow now rework** (par, group A)
- Goal:
  - `Frequency` and task state in `lga_tasks`.
  - The default table of §5.1 with priorities, gates and run bounds; all six frequencies for every task (`off` refused for `health_check` and `cleanup`).
  - Due logic and gate order (§5.2).
  - `Limits` on `lga_counters` with monthly limits and atomic `reserve()`, `active_key` dedupe, error classes, `Backoff`, per-type max attempts, `FailedJobRecovery`, the self-heal step.
  - Job routes (`retry`, `retry-failed`, `purge`), `/automation/schedules`, `/limits`.
  - The runner log and `GET /automation/runs` (§5.5).
  - The kick restrictions (§5.9) and admin-screen cost rules (§11.3).
  - **The GrowthRun rework** (§2.7): steps and gates from TaskRegistry, no approval path, PositioningGate wording.
- Depends: WP5.
- Acceptance: `FrequencyTest`, `TaskRegistryTest` (outbound implies `requiresProfile`, `autopilot` tasks name a consent, priorities 1-9, every task accepts all six frequencies), `LimitsTest` (two connections racing on SQLite cannot exceed a limit), `BackoffTest`, `FailedJobRecoveryTest`, integration `scheduler` (runner log rows, idle aggregation) and `retries`; tick interval above 900 refused while a 15-minute task exists; I `growth-autopilot` (Grow now with Autopilot on and every consent: drafts in `awaiting_approval`, 0 sent messages); I `admin-cost` and the checkout case of I `frontend-cost`.

**WP11 — Security hardening** (par, group A)
- Goal: `UrlGuard` with IP pinning, the forced cURL transport for plugin requests, refusal without cURL, and host checks behind a proxy (§11.1); Redactor URL keys and job-error redaction, `RequestSigner`, `OAuthState`, `PermissionMap` and the permissions integration section, forbidden-function lint, full email masking; `RestrictionsTest` (static parts of §12.9: no disavow writer or call, no `MODE_AUTO`, single approval call site).
- Depends: WP5.
- Acceptance: `UrlGuardTest` (including the no-cURL, proxy and pinned cases), `RedactorTest`, `RequestSignerTest`, `RestrictionsTest`, integration `permissions`; the `lga_http_request_args` filter cannot disable the guard (test).

**WP12 — PHP 8.5 toolchain and static scan** (par, group A; tooling)
- Goal: Option A toolchain in the scratchpad (§12.6) with checksum verification, `bin/php85-scan.sh` in lint including the `--min=8.1` floor rules (§11.8), `bin/compat-api.php` for the WP 6.5 / WC 8.0 API floors, the optional `compat-min` site on port 8797 when downloads are permitted, a first lint, unit and integration run on PHP 8.5, fixes for any deprecations or floor violations found (small, targeted edits).
- Depends: WP1 (can start any time after it; rerun in WP31).
- Acceptance: `php -v` from the wrapper shows 8.5.13 (or the fallback is documented); both scans clean, each with a seeded violation fixture proving it detects (a `readonly class`, a `json_validate` call, an unguarded function with `@since 6.6`); `compat-min` either green or "not run" with the reason; results recorded in `docs/notes/WP12.md`.

**WP13 — Server cron and WP-CLI** (par, group B)
- Goal: `Runner\ServerCron`, `Security\CronToken`, `POST /cron/run`, runner modes and heartbeat-based fallback (§5.5) counting only `cli`/`http` heartbeats in `server_cron` mode, `lga_cron_heartbeat_server`, `/automation/cron*` routes with the mode-change check, `Runner\CronLines::forSite()` (per-site lock and token file names, lines C/D only for `auto`, the network lines), `Cli\LinkGrowthCommand` with all subcommands including `run --network` (one process per site), `docs/SERVER-CRON.md`.
- Depends: WP10, WP11 (and WP5 for the multisite build).
- Acceptance: integration `server-cron` (two sites get different lock and token names; a `wp_cron`/`as` heartbeat does not satisfy `server_cron` mode) and `wp-cli` including `run --network` on the multisite build (or shim, reported as such); mode `server_cron` unschedules the AS/WP-Cron tick and restores it when the heartbeat is missing (frozen clock); response contains only the documented keys.

**WP14 — Provider framework and existing providers** (sequential before WP15-WP19)
- Goal: `Integrations\Framework\*` (contract, gateway, rate limiter, circuit breaker, classifier, state, activity, credential schema, OAuth client); the extension filters of §6.1 (`lga_providers`, `lga_metrics_providers`, `lga_search_providers`, `lga_backlink_sources`, and the registry hooks for `lga_acquisition_adapters` and `lga_distribution_adapters`); move SE Ranking, Ahrefs, Semrush, Moz, GSC, GA4, Site Kit bridge, Brave, Google CSE (legacy label), RDAP, IndexNow and AiWriter onto it; `lga_provider_state` and activity; Providers page and routes (§10.2) including authorizations list and filter-added providers; provider settings tab moved to the Providers page.
- Depends: WP10, WP11.
- Acceptance: `ProviderAuthTest` and failure-handling tests over all migrated providers; the SE Ranking 429 case opens the circuit and the next lookup does not call the API; U `ProviderExtensionTest` (a filter-added provider is listed and gated by ProviderGateway); Providers page controls pass E2E.

**WP15 — Bing Webmaster, Majestic, Moz links** (par, group C)
- Goal: `Bing\WebmasterTools` (§6.6 operations; URL submission for new resources when IndexNow is off; own backlinks import; traffic source for analytics; `GetUserSites` as an owned-site source, §2.8), `Providers\Majestic`, Moz `BacklinkSource`/`RefDomainsSource`; settings cards on the Providers page; fixtures.
- Depends: WP14, WP6 (OwnedSites).
- Acceptance: fixture tests for success, 401, 429, 5xx, malformed JSON and quota for each; the API key never appears in logs or activity (redaction test); a fixture `GetUserSites` list produces owned-site suggestions.

**WP16 — Google extensions and WooCommerce attribution** (par, group C)
- Goal: GSC dimensions (page, query, country, device), URL inspection for own pages, GA4 key events, purchases, revenue, landing pages and channels, `Woo\OrderAttribution`; analytics `Sources\*` for GA4, GSC, Bing and Woo returning rows already capped to the top N plus `(other)` (§9.1); the GSC sites list and GA4 account summaries as owned-site sources (§2.8).
- Depends: WP14, WP6 (OwnedSites).
- Acceptance: fixture tests; integration `woo-hpos` with HPOS on and off; revenue absent (null) when no source is connected; fixture GSC sites and GA4 account summaries produce owned-site suggestions.

**WP17 — Acquisition contract v2** (par, group C)
- Goal: adapter contract (§6.1), success state machine (§6.5), `Submission\Authorizations` and routes, Policy gates (§6.7) with the extended anti-bot and honeypot checks (`FormDetector::antiBot`), `EligibilityDetector`, `TermsDetector`, no automatic terms ticking, placement type and expected rel, mandatory `success_path`, configured partner APIs as one provider per destination (`partner_api:<prospect_id>` state rows, cards, `POST /providers/partner-api`), new submission statuses in UI.
- Depends: WP14.
- Acceptance: `SuccessSemanticsTest`; a public form without an authorization record is never posted (integration `acquisition`); a register form with a website field is never posted; a terms page forbidding bots refuses automation; a paid placement is refused with the default budget; U `FormDetectorAntiBotTest` (one fixture per challenge widget, JS-set field, time trap and honeypot style; a hidden `website` honeypot stays empty); a configured partner API appears as its own provider card with limits and activity.

**WP18 — Distribution I: engine, Mastodon, Bluesky, webhook** (par, group C)
- Goal: `DistributionEngine`, `Distribution\Policy`, queue and `lga_distributions`, `distribution_send`/`distribution_verify` tasks, Mastodon, Bluesky and Webhook adapters, `POST /webhook/confirm`, `/distributions` routes, distribution panel on the Resources page.
- Depends: WP14.
- Acceptance: fixture tests for each adapter (success, auth, rate limit, duplicate with idempotency); webhook 2xx stays `handed_off` until a valid signed callback; replayed or old callbacks refused.

**WP19 — Distribution II: LinkedIn, Facebook Pages, WordPress syndication** (par after WP18)
- Goal:
  - OAuth flows (admin-post callback); LinkedIn and Facebook adapters with token expiry handling.
  - The `WordPressSyndication` adapter and `SyndicationReceiver`: the canonical written into the SEO plugin's field, `_lga_syndicated_from`, `lga_syndication_sources`.
  - Canonical verification and automatic revert to draft.
  - Owned-channel rules (§2.8): justification, one placement per resource, relevance at least 40, no reciprocal syndication.
  - The minimal anonymous `GET /syndication/capabilities`.
  - Receiver-safe uninstall: `advanced.receiver_uninstall_action`, the Plugins-row notice, and the "Removing the plugin" panel with its routes (§4.4).
- Depends: WP18, WP6 (SEO adapters, OwnedSites).
- Acceptance:
  - OAuth state tests (single use, expiry, wrong user); fixture posting tests.
  - Two-site integration (8793 → 8796): syndicates a post, verifies the canonical, and reverts a copy whose canonical was removed. The receiver writes the SEO plugin's canonical field. Uninstall with `keep` leaves the canonical meta, and uninstall with `draft` without an SEO plugin drafts the copy. Syndication back from 8796 to 8793 is refused as reciprocal.
  - An owned-channel item cannot be sent without a justification, and a second one for the same resource is refused.
  - The anonymous capabilities response is exactly `{"receiver":true}`.

**WP20 — Scoring v2 and the Opportunities page** (group D)
- Goal: `OpportunityScorer`, `Explainer` and `Bands` (§3.3: relevance column = topical_relevance sub-score, risk bands, `reject_reason`, low-score rejection with the explanation kept, 50-69 open but not auto-queued), legitimacy and outbound quality signals, uniqueness modifier, configurable weights and `rescore`, opportunity lifecycle and attribution (§3.4), competitor intersect and citation topics, broken-link scheduling and re-verification, Opportunities page and routes.
- Depends: WP6, WP17.
- Acceptance: scoring unit tests (weights sum, unknown components excluded and renormalized, no placeholder authority, DA/DR labeled, gates); U `ScoringBandsTest` (band edges, moving risk edge, explanation kept on reject, reopen); intersect merges competitor sets; attribution moves an opportunity to `live_verified` when its link is found; Opportunities page E2E.

**WP21 — Backlink monitor v2** (par, group D)
- Goal: §3.5 in full (destination checks, redirect tracing, rel direction, check history table, content drift, ownership fingerprints with `ips_hash` and `registrant_org` and the task, hidden-link and suspicious-change alerts through their own notification events, inaccessible-since, recommendations, recovery planner, `first_verified_at`, `CheckPlanner` tiers and `coverage_30d`).
- Depends: WP14 (RDAP through the gateway), WP5.
- Acceptance: monitoring unit tests for each detection; integration `recovery`; history is not truncated at 50; U `CheckPlannerTest` (tier order, a 20,000-link imported profile does not starve tier 1, sampling within the remaining budget, coverage numbers); U `NotifierEventsTest` (`suspicious_change` and `hidden_link` honor their toggles and dedupe).

**WP22 — Content engine I: inventory, gaps, register** (par, group D)
- Goal: `Inventory`, `GapAnalyzer` with all sources (§7.1), gap score, `lga_content_gaps`, `Register`, Resources page (register and gaps parts), `/resources` list and import, `/content/gaps*`.
- Depends: WP7, WP14 (TopPagesSource), WP20 (citation topics).
- Acceptance: gap tests with fixture GSC and competitor data; competitor text never reaches builders (assertion on planner input); existing pages registered as assets.

**WP23 — Content engine II: builders, checks, gate, calculator, datasets** (group D, after WP22)
- Goal: builders of §7.2 (comparison auto-publish only for the owner's own products; third-party cells need a per-cell verified fact and stay draft-only; data report auto-publish only with `ai_used = 0`), checks of §7.5 with the fixed floors in `Checks\Floors` and only the tunable keys in the schema, `PublishGate` and caps (§7.6, `ai_used = 0` without exception), calculator storage `_lga_calculator` with validation and approval (`/calculator/approve`), block, shortcode, expression parser and front-end script, dataset storage (attachment plus `_lga_dataset`, streamed matching, hash check) with the CSV importer and Dataset JSON-LD, SVG infographic, maintenance task, cache purge after publish, IndexNow/Bing submission.
- Depends: WP22, WP11.
- Acceptance: each check has pass and fail tests; a draft with an unmapped number is not publishable; the monthly ceiling cannot be exceeded; calculator script enqueued only on its page (integration `frontend-cost`); expression fuzz test; U `PublishGateTest` (`ai_used = 1` always gives a draft; a third-party comparison cell gives a draft); U `ThresholdsSchemaTest` (weaker accuracy, compliance or originality values refused); U `CalculatorDefinitionTest`; U `DatasetStorageTest`.

**WP24 — Analytics module** (par, group D)
- Goal: `Snapshotter` with the top-N caps and `(other)` rows, the weekly rollup in `cleanup` (§9.1), metrics of §9.1, backfill, attribution, `/analytics/*` routes reading the weekly grain where needed, Analytics page charts and tables, Overview KPIs (sites discovered and qualified opportunities as defined in §10.2, new this month, conversions, quality-weighted referring domains).
- Depends: WP16, WP21.
- Acceptance: snapshot upsert idempotent; nulls (not zeros) for unconnected sources; KPIs exclude owned and simulated links; U `SnapshotterCapTest` and the rollup cases of U `CleanupTest`; Overview tiles match their definitions on a seeded site (owned prospects not counted; rejected opportunities not counted); Analytics page E2E.

**WP25 — Decision engine and Strategy page** (group E)
- Goal: ledger hooks in all emitting modules (§8.2), stats recompute, priority (§8.3), allocator and `Budget::take` in all consumers (§8.4), stop rule (§8.5), expansions (§8.6), decision log, Strategy page and routes.
- Depends: WP20, WP24, WP17, WP18.
- Acceptance: unit tests with synthetic histories: exploration floor respected, caps respected, a risky arm with no benefit is paused after `n_min`, a complaint pauses immediately, ten links from one domain do not beat one new domain, the reason sentence matches the inputs; with the module off every multiplier is 1.

**WP26 — Health, self-healing and diagnostics** (par, group E)
- Goal: all checks of §5.8 (including the 7-day "no server cron heartbeat" warning and the environment warnings for missing cURL, an HTTP proxy and another IndexNow implementation), Site Health tests, notification-on-transition, per-event notification preferences (every event of §2.2), diagnostics report, salts-change detection, coexistence check.
- Depends: WP10, WP13, WP14.
- Acceptance: each check has warning and critical tests (frozen clock), including the 7-day heartbeat case (not raised in the first 7 days after activation, raised after, cleared by a `cli` heartbeat); notifications fire once per transition and once on recovery; diagnostics contain no secret, email or query-string URL (redaction test over a seeded site).

**WP27 — Setup wizard UI and core Settings tabs** (par, group E)
- Goal: wizard pages (§2.1) with prefill, validation and consent text (business type, secondary packs, owned-site suggestions to confirm or reject, expertise topics, privacy URL, sending domains, the five consents and the "what still needs a person" list of Appendix B); Business, Market, Industry, Keywords (detected competitors), Exclusions (owned-site suggestions, suppression export and import), Notifications (all events), Data, Advanced ("Removing the plugin" panel) and Privacy tabs on the new schema.
- Depends: WP4b, WP8, WP14.
- Acceptance: E2E completes the wizard for both demo profiles; outbound work starts only after steps 2-4 and acquisition only after completion; every tab control in the manifest.

**WP28 — Automation page, acquisition history, Overview completion** (group E)
- Goal: Automation page (master switch, module switches, schedules table with all frequencies, priority, gates and blocking reasons, runner and token panel with this site's generated crontab lines, Recent runs, queue tools, limit meters); Acquisition history page and `/acquisitions`; Overview widgets of §10.2 (Autopilot switch with the consent dialog and revoke, tile definitions); the Grow now UI on the reworked GrowthRun (step labels, blocked reasons, drafts-awaiting-approval summary).
- Depends: WP13, WP17, WP18, WP26, WP10 (GrowthRun).
- Acceptance: E2E of all controls; acquisition tabs count only `provider_confirmed`/`live_verified` as successful; token is shown once and never returned again by GET; the Grow now UI shows no "Autopilot sends" wording (grep of `assets/js`), and I `growth-autopilot` stays green.

**WP29 — Deliverable documentation** (par from group E, final pass after WP31 results)
- Goal:
  - `docs/INSTALLATION.md`, including "take a full backup first" and the source ZIP named as deliverable 3.
  - `CONFIGURATION.md`: the three switches and setup stages, task frequencies with each task's bound and why every task allows all six, fixed content floors versus tunable thresholds, owned sites and suggestions, suppression export and import across the owner's sites, retention settings.
  - `SERVER-CRON.md` (from WP13): per-site lock and token names, lines C/D only in `auto` mode, multisite lines.
  - `PROVIDERS.md`: per provider, the credentials and plan, scopes, endpoints, unit costs, rate limits, what it feeds and what happens without it; and a developer section with every extension filter and an example class.
  - `SECURITY.md`: no telemetry and no phone-home, the SSRF transport and proxy rules, the public endpoints and what they return.
  - `LIMITATIONS.md`: credentials per feature; discovery-only platforms and why; **no bundled directory, listing or publication submission API and why** (§6.6); what cannot be verified; PHP 8.5 run status; the PHP 8.1, WP 6.5 and WC 8.0 floors as "static or declared"; licensing notes for Majestic and Ahrefs; no PDF generation; Google Programmable Search status; AI text never auto-published.
  - `FEATURES.md`: the spec §1-§17 checklist with status, honest framework-only statuses, and the **"What still needs a person" table** (Appendix B).
  - `PROVIDER-ACCESS.md` (feature → provider → credential → behavior without it), `TESTING.md`, `readme.txt`.
  - Neutral screenshots from the demo site labeled as simulated, and the consolidated `docs/notes/WP*.md`.
- Depends: WP13-WP28 for content.
- Acceptance: every spec §16 deliverable has a file; links and commands in the docs were executed on the test site; neutrality scan of docs (patterns B and D); FEATURES.md contains the Appendix B table and the no-bundled-API statement; LIMITATIONS.md contains the no-bundled-API statement with its reason.

**WP30 — Browser E2E of every control** (after all UI packages)
- Goal: complete manifests for every page, tab and wizard step; permission and responsive passes; fix any decorative or broken control found.
- Depends: WP14, WP20, WP22-WP28.
- Acceptance: the E2E suite green with zero unmanifested controls and zero console errors.

**WP31 — Release: full runs, ZIP and results** (sequential, last)
- Goal: lint (with the 8.1 floor and API scans), unit and integration on PHP 8.3 and PHP 8.5; E2E; clean-install ZIP test (§12.8) on both PHP versions where available; source ZIP built and scanned; the optional `compat-min` run; `TEST-RESULTS.md` (labeling every check type, and the PHP 8.1, WP 6.5 and WC 8.0 floors as static or declared unless `compat-min` ran); final `FEATURES.md` statuses; version stamps.
- Depends: all.
- Acceptance: all suites green or explicitly "not run" with reasons; every row of the §12.9 restriction table green; ZIP installs, activates, runs, deactivates and uninstalls cleanly; the source ZIP passes `neutrality.sh --source`; nothing installed on the live site.

### 13.3 Dependency overview

```
WP1 → WP2 → WP3 → WP4a → WP4b → WP5
                                  ├── group A (par): WP6, WP7, WP8, WP9, WP10, WP11      (WP12 may start any time after WP1)
                                  │       WP10 + WP11 (+ WP5 multisite build) → WP13 (server cron, CLI)
                                  │       WP10 + WP11 → WP14 (provider framework)
                                  ├── group C (par, after WP14): WP15 (+WP6), WP16 (+WP6), WP17, WP18 → WP19 (+WP6)
                                  ├── group D: WP20 (WP6, WP17), WP21 (WP14), WP22 (WP7, WP14, WP20) → WP23, WP24 (WP16, WP21)
                                  ├── group E: WP25 (WP20, WP24, WP17, WP18), WP26 (WP10, WP13, WP14), WP27 (WP4b, WP8, WP14), WP28 (WP10, WP13, WP17, WP18, WP26)
                                  └── WP29 (docs), WP30 (E2E) → WP31 (release)
```

WP4 is split into two sequential parts because the switch, consent and Autopilot rewrite is load-bearing for every outbound module. Doing it before group A means no parallel package ever builds on the engine's auto-approval behavior.

---

## Appendix A. Known limitations to carry into LIMITATIONS.md

- Every external integration needs the owner's own account and credentials; paid plans are needed for Ahrefs, Semrush, Majestic, Moz and SE Ranking API access, and for Brave Search beyond its free allowance.
- LinkedIn organization posting needs LinkedIn's approval of the owner's app for the Community Management API; Facebook Page posting for pages not administered by the app's admins needs Meta App Review.
- Business listing platforms, review sites, most publications and directories without an authorization record are owner tasks, by design (§6.6).
- Index status of third-party pages cannot be verified by any authorized source the plugin uses; it stays "unknown".
- The plugin cannot write long-form prose without the optional AI writer; without it, guides are outline drafts for the owner. AI-written prose is never published automatically.
- No PDF generation in 1.0.0.
- PHP 8.5 coverage depends on the user-space toolchain working in the build environment; the results document states what ran.
- Legal compliance profiles are aids, not legal advice; the owner remains responsible for outreach compliance.
- **No bundled directory, listing or publication submission API.** No platform the plugin targets offers a public self-serve write API for a single business without a partner agreement. 1.0.0 therefore ships the framework only: owner-configured partner APIs, authorized forms backed by an authorization record, and extension adapters (§6.6). Without one of these, every submission is an owner task.
- AI-written text, including summaries and meta descriptions, is never published automatically (§7.6). Third-party comparisons are always drafts (§7.2).
- The declared minimums (PHP 8.1, WordPress 6.5, WooCommerce 8.0) are checked statically. They run only if the optional `compat-min` site could be built (§11.8, §12.9).
- Owned-site detection uses only connected providers and DNS/RDAP fingerprints. A site on unrelated hosting with no shared provider account is not detected, so the owner must list it in `site.owned_domains` (§2.8).
- Multisite is tested on a network built programmatically and loaded in-process, not through HTTP on a subdomain network (§12.1).

---

## Appendix B. What still needs a person

FEATURES.md and the wizard's consent step carry this table. Everything else runs unattended once setup is completed, the modules are on and the consents are recorded.

| Step | Why a person is needed | Where |
|---|---|---|
| Approving every first-contact email (one per request, after the preview) | Spec §3E/§4: no unsolicited mass messages, no automated cold email; each contact's consent basis and jurisdiction must be judged | Outreach > Messages (§2.7) |
| Approving follow-ups when autosend is not set up | Autosend needs reply tracking, the follow-ups consent and Autopilot (§2.7) | Outreach > Messages |
| Recording a destination authorization (evidence of permission) | A public form is not permission; only the owner can confirm terms, written permission or a partner agreement (§6.4, §6.7) | Prospects / Acquisition > Authorize |
| Configuring a partner API | The destination's credentials and terms belong to the owner (§6.1) | Providers > Partner APIs |
| Owner tasks: listings needing an account, payment, verification, CAPTCHA, terms ticking or a claim; memberships; employer and event listings; review-profile claims | Spec §3A: no account creation, no verification or CAPTCHA bypass, no automated consent (§2.5, §6.7) | Owner tasks (also the LinkGrowth Submitter role) |
| Verifying facts (ticking each one; confirming suggested facts against the source) | Spec §5: never invent statistics or claims (§7.4) | Resources > Facts |
| Reviewing and publishing guide, educational, glossary (non-owner definitions), YMYL, AI-assisted and third-party comparison drafts | Not eligible for auto-publish (§7.2, §7.6) | Resources |
| Approving calculator formulas and test cases | A formula error would publish wrong results (§7.2) | Resources > Calculator |
| Approving distributions when `distribution.approval = required`, and every owned-channel placement with its justification | Spec §11 and the owner's channels (§2.8, §6.6) | Resources > Distribute / Acquisition |
| Reconnecting OAuth providers (LinkedIn about every 60 days, Facebook when the token lapses) and fixing expired or invalid credentials | The platforms require the owner's interactive consent (§6.3) | Providers |
| Confirming or rejecting suggested owned sites | Only the owner knows which sites are theirs (§2.8) | Settings > Exclusions |
| Acting on monitor recommendations (restore requests, destination fixes, a possible disavow) | Spec §7: never auto-disavow; evidence and a recommendation only (§3.5) | Backlinks |
| Resuming a strategy the stop rule paused | Spec §9: risk without benefit stops; resuming is a judgment (§8.5) | Strategy |
| Installing the server cron line | Needs shell or control-panel access (§5.6) | Automation > Runner |
