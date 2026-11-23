# WP2 — Server-side module seams (parts 0, 1 and 2 of the package)

Scope of part 0: the behaviour to preserve, recorded on the untouched WP1 code (e160656): the golden scheduler test
(`tests/Unit/Automation/SchedulerGoldenTest.php`, `tests/fixtures/golden/scheduler.json`), the wiring snapshot without
WordPress (`tests/Unit/Wiring/WiringSnapshotTest.php`, `tests/fixtures/golden/wiring.json`) and on the LGA test site
(`tests/integration/wiring-snapshot.php`, `tests/fixtures/golden/wiring-wordpress.json`), the frozen clock
(`tests/Support/FrozenClock.php`) and the helpers `tests/Support/Wiring.php` and `tests/Support/GoldenJson.php`.
Part 0 changed no plugin code and reported lint OK, the full unit suite green (769 tests) and the integration suite
534 of 534 with the fixture router, coexistence included.

Scope of part 1: DESIGN.md §3.1 and §3.2. `Core\Module` (the interface of §3.1 as written), `Core\AbstractModule`,
`Core\Modules::LIST` and the `lga_modules` filter; the existing code regrouped into the 13 modules of §13.2 WP2;
`Plugin` (services, boot), `Database\Schema` (tables), `Support\Settings` (defaults), `JobRunner::types()`,
`Admin\Rest\RestApi` (controllers), `Admin\Menu` (pages, settings tabs), a `Health\HealthMonitor` skeleton and
`Cli\CommandRegistry` collect from the modules.

Scope of part 2 (sections "Part 2" below): DESIGN.md §2.2 (only the engine's existing keys; the complete schema is
WP4a), §3.1, §5.1, §5.2 and the rest of §13.2 WP2: `Support\SettingsSchema` collected from the modules'
`settingsDefaults()`/`settingsRules()` and `SettingsController` validating with it; `Automation\TaskDefinition`,
`Automation\TaskRegistry` (the modules' `tasks()` and the `lga_scheduled_tasks` filter) and `TaskRegistry::gate()`;
`Pipeline::stages()` removed, the scheduler taking its tasks from the registry. WP2 is complete with part 2.

`S` below is the session scratchpad of DESIGN.md §12.1.

## Part 1: what was built

1. **The seam** (`includes/Core/`).
   - `Module.php`: the interface of §3.1, method for method (a test compares the method list with the design's
     order); the imports of `Container` and `Plugin` are the only additions.
   - `AbstractModule.php`: `id()` abstract, every other method empty (`[]`, or nothing for `services()`/`boot()`).
   - `Modules.php`: `LIST`, the 13 module classes in the order of §13.2 WP2 (core, discovery, analysis, scoring,
     submission, outreach, monitoring, seo, integrations, automation, admin, content, compliance), and `load()`:
     `LIST` through `lga_modules`, each class built once. Left out: an entry that is neither a `Module` instance nor
     the name of an instantiable class implementing `Module`, and a module whose id is not a lower-case key or was
     taken by an earlier module (the earlier one wins). A `Module` instance is accepted as well as a class name.
   - `Plugin::modules()` calls `load()` once per plugin instance (an extension adds its filter before
     `plugins_loaded` priority 5, when the plugin boots).
2. **The modules.** One class per area, in the area's namespace. Classes stayed where they were (no file moved apart
   from the one §3.2 asks for, item 4); each module declares what it owns:

   | id | class | services (container factories) | boot hooks | tables | settings sections | jobs | REST controllers | pages | settings tabs | health checks |
   |---|---|---|---|---|---|---|---|---|---|---|
   | `core` | `Core\CoreModule` | Crypto, Settings, Secrets, Logger, RobotsTxt, Client, Locks, Limits, Opcache, ServerResources, Schema, Backup, Migrator, Logs, AuditLog, Notifications, Environment, SiteHealthTests, OpcacheCheck, CoexistenceCheck, HealthMonitor, CommandRegistry | init (1, maintenance), site_status_tests, cli_init (WP-CLI only) | notifications, audit_log, logs | site, advanced | — | — | — | business, advanced | OpcacheCheck, CoexistenceCheck |
   | `discovery` | `Discovery\DiscoveryModule` | Prospects, Opportunities, SearchLog, DomainFilter, Classifier, QueryGenerator, PackSeeds, ProspectIntake, CompetitorBacklinkSource, MentionFinder, BrokenLinkFinder, DiscoveryEngine, CatalogImporter | — | prospects, opportunities, search_log | discovery | discover, find_opportunities, mention_scan, broken_link_scan, import_verified | ProspectsController | prospects | discovery | — |
   | `analysis` | `Analysis\AnalysisModule` | Taxonomy, PageAnalyzer, FormDetector, ContactExtractor, RelevanceScorer, RiskAnalyzer, SubmissionPageFinder, SiteProfiler | — | — | — | — | — | — | — | — |
   | `scoring` | `Scoring\ScoringModule` | ScoringEngine | — | — | — | evaluate | — | — | — | — |
   | `submission` | `Submission\SubmissionModule` | Submissions, PackageBuilder, Policy, AdapterRegistry, ListingVerifier, SubmissionEngine | — | submissions | — | package, submit, verify_listings | SubmissionsController | submissions | — | — |
   | `outreach` | `Outreach\OutreachModule` | Contacts, Campaigns, OutreachMessages, Suppression, Templates, Personalizer, Compliance, Mailer, Unsubscribe, InboundWebhook, FollowUps, OutreachEngine, CampaignFiller | — | contacts, campaigns, outreach_messages, suppression | outreach | outreach_send, auto_outreach, follow_ups | OutreachController, PublicController | outreach | outreach | — |
   | `monitoring` | `Monitoring\MonitoringModule` | Backlinks, Notifier, BacklinkMonitor, BacklinkImporter | — | backlinks | monitoring | monitor_backlinks, import_backlinks | BacklinksController | backlinks | monitoring | — |
   | `seo` | `Seo\SeoModule` | SiteAuditor, IndexNow, RenderCheck, InternalLinker | parse_request, transition_post_status, save_post | — | seo | site_audit, indexnow, internal_links | SeoController | seo | — | — |
   | `integrations` | `Integrations\IntegrationsModule` | DomainMetrics, Rdap, ProviderRegistry, ServiceAccount, SearchConsole, Analytics4, SiteKitBridge, MetricsAggregator, AiWriter | — | domain_metrics | providers | metrics_refresh | — | — | integrations | — |
   | `automation` | `Automation\AutomationModule` | Jobs, Autopilot, Audit, LoadGuard, JobRunner, Pipeline, Scheduler, GrowthRun | init (20, maintenance; plus the version note), lga_tick, cron_schedules | jobs | automation, growth | cleanup | AutomationController, GrowthController | automation | automation | — |
   | `admin` | `Admin\AdminModule` | Capabilities, Menu, Assets, Notices, RestApi | init (2, maintenance), rest_api_init; on admin requests admin_menu, admin_init, admin_body_class, woocommerce_prevent_admin_access, admin_enqueue_scripts, admin_notices, wp_ajax_lga_dismiss_notice | — | — | — | OverviewController, SettingsController, LogsController | overview, settings, logs | — | — |
   | `content` | `Content\ContentModule` | FactsRegistry, ClaimsFilter, LinkableAssets, ContentGenerator | — | — | — | — | — | — | content | — |
   | `compliance` | `Compliance\ComplianceModule` | PositioningGate | the 15 storefront hooks of `Plugin::STOREFRONT_HOOKS` | — | compliance | storefront_audit | ComplianceController | — | — | — |

   No module declares a WP-CLI command (none exists before the server-cron work), `settingsRules()` or `tasks()`
   (part 2). Pages carry `label`, `icon` (the SPA's icon names), `module` (`pages/<page>.js`), `cap` (`lga_manage`)
   and `order` (10 … 90, the engine's navigation order). Settings tabs carry `label`, `module` (`pages/settings.js`,
   which renders all eight today; WP3 splits it into `settings/<tab>.js`) and `order` (10 … 80, the tab order of the
   Settings page).
3. **The collectors, one by one: what moved where.**
   - **Plugin** (`includes/Plugin.php`, 619 → 402 lines). `registerServices()` calls every module's `services()` on the
     first service request; the 94 factories it held moved unchanged (same constructor arguments) into
     the module of each service, and 4 are new (the two health checks, `HealthMonitor`, `CommandRegistry`): 98 in
     all. `boot()` loads the text domain, calls every module's `boot()` (a module that throws is recorded in
     `bootErrors()` and the others still boot) and fires `lga_booted`. The hook blocks of the old `boot()` moved into
     the module that owns them: the migration check to core, the capability sync, the REST registrar and the admin
     hooks to admin, the schedule self-heal, the version note, the tick and the cron interval to automation, the
     storefront hooks to compliance, the IndexNow hooks to seo. The callbacks themselves stayed `Plugin` methods
     (`maybeMigrate`, `ensureCapabilities`, `maybeSchedule`, `maybeRedirect`, `registerRest`, `siteStatusTests`,
     `cronSchedules`, `maybeServeIndexNowKey`, `onPostTransition`, `onSavePost`) or `Plugin::lazy()` closures, so the
     hook snapshot reads the same. New on `Plugin`: `modules()`, `bootErrors()`, `isMaintenanceRequest()` (asked once
     per instance, so every module sees the same answer); `lazy()` and `noteVersion()` are public now (the modules
     call them).
   - **Database\Schema** (365 → 101 lines). The 14 CREATE TABLE statements moved byte for byte into the `tables()` of
     their modules (core 3, discovery 3, outreach 4, submission, monitoring, integrations and automation 1 each).
     `Schema::declared()` (name => statement, module order; the first module wins a name), `names()`, `statements()`,
     `tableNames()` and the new `charsetCollate()` read the modules. The constant `Schema::TABLES` is gone; its users
     call `Schema::names()`: `Database\Migrator`, `Core\Environment`, `tests/integration/run.php`,
     `tests/integration/coexistence.php`, `tests/integration/wiring-snapshot.php` and
     `tests/Unit/Support/DataSupportCoreFixesTest.php`.
   - **Support\Settings** (292 → 179 lines). The ten default sections moved into the modules' `settingsDefaults()`
     (core: site, advanced; discovery; outreach; monitoring; seo; integrations: providers; automation: automation,
     growth; compliance). `defaults()` merges them in module order (a section two modules declare is merged, the
     earlier module's value wins a key; the built-in modules share none). The home URL and domain the defaults derive
     from are `Settings::defaultHome()` and `Settings::domainOf()`, read at call time as before.
   - **JobRunner::types()**. The `BUILTIN` list is gone: the types are the modules' `jobs()` (type => class; an entry
     whose class does not report that type, or a type an earlier module took, is left out), then `lga_job_types` and
     the validation as before. 20 types, the same classes and priorities.
   - **Admin\Rest\RestApi::controllers()**. The modules' `restControllers()` in module order, then
     `lga_rest_controllers` and the validation as before. 12 controllers, 84 routes.
   - **Admin\Menu**. `pageDefinitions()` collects the modules' `adminPages()`, applies the new `lga_admin_pages`
     filter (DESIGN.md §3.1), keeps valid entries and sorts by `order`; `pages()` (key => label) is built from it, so
     the menu, `lga-config.pages` and `pageFromSlug()` see extension pages. `addMenu()` registers each page with its
     own `cap`. `pagesFor()` (the SPA's `pages`) keeps the pages the user may open: a page with `lga_manage` needs
     full access (`Capabilities::userCan()`, as before), any other capability is checked as it is. `settingsTabs()`
     collects the modules' tabs in `order`, and the owner's `lga-config` now carries `settingsTabs` (DESIGN.md §10.1).
     `Menu::PAGE_KEYS` is gone (one list, in the modules).
   - **Health\HealthMonitor** (new, `includes/Health/`). `checks()` collects the modules' `healthChecks()`, `run()`
     builds each through the container and returns `id => {id, status ok|warning|critical, label, message, evidence,
     action{label,url}}` (DESIGN.md §5.8); a check that cannot be built or throws is reported as critical with the
     reason and does not stop the others. Not hooked anywhere yet (scheduling, caching, notifications, Site Health
     mapping and `GET /automation/health` come with the health package). The checks that exist today are declared by
     core in the new `Health\Check` shape: `Health\Checks\OpcacheCheck` (`opcache`, the Site Health OPcache test;
     good → ok, recommended → warning, critical → critical) and `Health\Checks\CoexistenceCheck` (`coexistence`,
     `Core\Environment::conflicts( true )`, warning only).
   - **Cli\CommandRegistry** (new, `includes/Cli/`). `commands()` collects the modules' `cliCommands()`; `register()`
     adds each as `wp linkgrowth <subcommand>` through `WP_CLI::add_command()` (or a registrar passed in, for tests).
     Core hooks it lazily on `cli_init` only when `WP_CLI` is defined; the WP-CLI phar of §12.7 fires `cli_init` from
     `init` (read in its `Runner.php`).
4. **One file moved, as §3.2 asks** (Health row: "Admin\SiteHealth OPcache test (moved here)"):
   `includes/Admin/SiteHealth.php` → `includes/Health/SiteHealthTests.php`, class `Health\SiteHealthTests`, unchanged
   otherwise. References renamed in `Plugin`, `Admin\Rest\OverviewController`, comments of `Automation\LoadGuard`,
   `Support\ServerResources` and `assets/js/server-pressure.js`, and the tests listed below.
5. **Tests.** New `tests/Unit/Core/ModulesTest.php` (16 tests):
   - every class of `LIST` implements `Core\Module`, is instantiable and has a unique lower-case id; the ids are the
     13 of §13.2; the plugin loads `LIST` in order and only once; no boot error;
   - the interface has the methods of §3.1 in that order; `AbstractModule` declares nothing;
   - invalid entries (unknown class, a non-module class, the abstract base, a scalar, a second module with a taken id,
     a bad id) are left out, an instance is kept, a filter that returns no list gives no modules;
   - each collector returns the union of the modules' declarations, and no two built-in modules declare the same
     service, table, settings section, job type, controller, page or tab: the container's 98 factories (each builds),
     the hooks `Plugin::boot()` adds (equal to each module's `boot()` run on its own), `Schema::declared()`,
     `names()` and `statements()` (the 14 tables), `Settings::defaults()` (the 10 sections, derived from the home
     URL), `JobRunner::types()` (20), `RestApi::controllers()` (12), `Menu::pageDefinitions()` and
     `Menu::settingsTabs()` (in the engine's order), `HealthMonitor::checks()` (2) and `CommandRegistry::commands()`
     (none); the health monitor runs both checks in the §5.8 shape;
   - no path of one controller's routes matches a route pattern of another controller (74 paths, 4,894 pairs), so the
     registration order cannot change which handler answers (deliberate difference 1);
   - a probe module added through `lga_modules` (a service, a lazy boot hook, a table, a settings section, a job, a
     controller, two pages, one with its own capability, a settings tab, a health check and a WP-CLI command) is
     collected by every collector: the container, the boot, the schema, the defaults and `Settings::get()`, the job
     types, the controllers and their routes, the page order, the submenu and its capability, `pageFromSlug()`, the
     SPA's pages (filtered by capability) and tabs, the health monitor and the CLI registry;
   - a module whose boot throws leaves only its own hooks out; a health check that throws is reported as critical and
     the others still run.

## Part 1: deliberate differences from the part 0 snapshots (with reasons)

The golden scheduler sequence is identical (6 of 6 scenarios, same fixture bytes). In the wiring snapshots every
section is identical except these two, rewritten with `LGA_GOLDEN_UPDATE=1` and compared with copies of the part 0
fixtures kept in `S/wp2p1/golden-part0/`:

1. **REST route order** (`wiring.json` `rest_routes`, `wiring-wordpress.json` `rest_routes`). The same 84 routes (85
   with WordPress's namespace index), with the same handlers, permission callbacks, rate limits and arguments: the
   sorted lists are equal and a line-sorted diff of `wiring.json` is empty. Only the order differs, because
   `RestApi` now registers the controllers module by module: Prospects, Submissions, Outreach, Public, Backlinks, Seo,
   Automation, Growth, Overview, Settings, Logs, Compliance (before: Overview, Prospects, Submissions, Outreach,
   Backlinks, Automation, Settings, Logs, Public, Compliance, Seo, Growth). Keeping the old order would have meant
   declaring all existing controllers in one module, against the seam (a module declares its own controllers,
   DESIGN.md §13.1). It changes no dispatch: WordPress takes the first matching route, and
   `ModulesTest::testNoRouteOfOneControllerMatchesAPathOfAnother` shows that no route of one controller matches a
   path of another. What a client can see is the order of `GET /wp-json/lga/v1` (the REST index).
2. **Plugin files a page view loads** (`wiring-wordpress.json` `frontend_plugin_files`): 20 instead of 4. The four
   (main file, `Autoloader`, `Container`, `Plugin`) plus the seam (`Core/Module.php`, `Core/AbstractModule.php`,
   `Core/Modules.php`) and the 13 module classes. `Module::boot()` runs on every request by design (§3.1: "hooks
   (cheap; front-end safe)"), so the module classes are loaded on every request; no service class is loaded, and
   the rest of the 1.2.4 rule holds: 0 plugin queries and 0 writes on a page view (`frontend_plugin_queries` 0,
   `VisitorRequestCostTest` 13 of 13: no service factory registered, no maintenance hook, no option read or
   written, the same hooks with the same lazy callbacks, `hooks.frontend` identical). Measured cost on the LGA site
   (`S/wp2p1/measure.sh`: a temporary mu-plugin, removed after each run, timed `plugins_loaded` priority 4 → 6, i.e.
   the plugin's `boot()`; `php -S` on 8793; 10 warm-up and 60 measured page views per mode; medians):

   | | boot() | plugin files | bytes | whole page view |
   |---|---|---|---|---|
   | before (e160656 code), OPcache off | 68 µs | 4 | 45,393 | 152.9 ms |
   | after, OPcache off | 180 µs | 20 | 95,249 | 162.2 ms |
   | before, OPcache on | 64 µs | 4 | 45,393 | 144.9 ms |
   | after, OPcache on | 165 µs | 20 | 95,249 | 140.6 ms |

   About 0.1 ms more per page view (16 more includes and 13 small objects; the same hook registrations as before); the
   whole-page medians move within their noise. This is the one place where the seam costs a visitor something. If
   the owner wants the 4 files back, the way out is a compiled boot map (the boot hooks of the modules written to a
   generated file on maintenance requests); it was not built because it adds a cache that can go stale.

Other differences that no snapshot records, all from the module order:
- `JobRunner::types()` lists the same 20 types in module order (discover, find_opportunities, mention_scan,
  broken_link_scan, import_verified, evaluate, package, submit, verify_listings, outreach_send, auto_outreach,
  follow_ups, monitor_backlinks, import_backlinks, site_audit, indexnow, internal_links, metrics_refresh, cleanup,
  storefront_audit). Visible as the order of `job_types` and `stage_labels` in `GET /automation/status`; the
  Automation page lists stages from `Pipeline::status()`, whose order is unchanged.
- `Settings::defaults()` lists its sections in module order (site, advanced, discovery, outreach, monitoring, seo,
  providers, automation, growth, compliance): the key order of `GET /settings`. The values, and the validation
  (`settings_validation`, 975 probes), are identical.
- `Schema::statements()` lists the tables in module order (dbDelta and the backup dump run in that order).
- In the unit stubs (which ignore priorities) the three `init` checks register as migrate, schedule, capabilities;
  WordPress runs them by priority (1, 2, 20), as the identical `hooks.admin` and `hooks.cron` show.
- `lga-config` gains `settingsTabs` (owner view only), and its `pages` are filtered by capability (no change for the
  built-in pages, which all need `lga_manage`).

## Part 1: deviations from the design, with reasons

1. **Existing hook callbacks stay on `Plugin`.** The modules register the same `Plugin` methods and `lazy()` closures
   instead of their own methods, so that the hooks of every request type and the tests that call or look for those
   methods (`ReleaseWiringTest`, `VisitorRequestCostTest`, `tests/integration/growth.php`) stay as they are. New
   modules can use their own callbacks.
2. **Admin pages and controllers by area.** §3.2 lists "every existing controller" and the pages under the Dashboard
   row; WP2 lets each area's module declare its page and controller (§13.1: a package owns its module class,
   controllers and SPA page), and the admin module keeps Overview, Settings and Logs. This causes deliberate
   difference 1.
3. **No extra extension filters.** §3.1 names `lga_modules`, `lga_job_types`, `lga_scheduled_tasks`,
   `lga_rest_controllers` and `lga_admin_pages`; tables, settings sections, settings tabs, health checks and WP-CLI
   commands are extended through a module (`lga_modules`). `lga_scheduled_tasks` comes with the task registry
   (part 2).
4. **Collectors read `Plugin::instance()->modules()`.** `Settings::defaults()`, `Schema`, `JobRunner::types()`,
   `RestApi` and `Menu` are called statically or built without the plugin in many places (and by many tests), so
   they ask the plugin singleton for its modules instead of taking them as a constructor argument.
   `HealthMonitor` and `CommandRegistry`, which are new, get the list from their factories.
5. **Health checks.** §5.8's ids `opcache` and `coexistence` are the two checks that exist today; they wrap the Site
   Health tests without changing them, and the Site Health screen still gets its tests from `Plugin::siteStatusTests()`
   exactly as before. `Health\Checks\SiteHealthResult` (a converter) sits next to them.
6. **Settings tabs module path.** `pages/settings.js` until WP3 splits the tabs into their own modules.

## Part 1: tests changed (none weakened)

- `tests/Unit/Wiring/ReleaseWiringTest.php`: `Menu::PAGE_KEYS` (removed) replaced by the same nine keys written in
  the test; the two source-text assertions that read `includes/Plugin.php` now read the files the factories moved to
  (`includes/Automation/AutomationModule.php` for `LoadGuard`, `includes/Core/CoreModule.php` for
  `SiteHealthTests`), with the same factory text; the container check names `Health\SiteHealthTests`.
- `tests/Unit/Admin/SubmitterRoleTest.php`: `Menu::PAGE_KEYS` replaced by the nine keys.
- `tests/Unit/Automation/LoadGuardTest.php`, `tests/Unit/Automation/ServerLoadTest.php`,
  `tests/integration/server-pressure.php`: the class rename of item 4.
- `tests/Unit/Support/DataSupportCoreFixesTest.php`, `tests/integration/run.php`, `tests/integration/coexistence.php`,
  `tests/integration/wiring-snapshot.php`: `Schema::TABLES` → `Schema::names()` (the same 14 names; in
  `coexistence.php` only that line changed).
- `tests/Unit/Verified/CatalogImporterTest.php`: a comment that named `JobRunner::BUILTIN`.
- `tests/fixtures/golden/wiring.json` and `wiring-wordpress.json`: the two sections of the deliberate differences.
- `bin/neutrality-pending.txt`: the pending pattern C row of the discovery defaults (one line of geographic query
  modifiers, owner WP4a) moved with the code from `includes/Support/Settings.php` to
  `includes/Discovery/DiscoveryModule.php`, same count and owner; no row raised.

## Part 1: evidence

All commands from this folder, PHP 8.3.6, `nice -n 10`, every PHPUnit and integration run under
`flock S/lga-suite.lock`, one after another. Ports 8793 (the site, started and stopped by the suite; and by the
measurement script) and 8794 (the fixture router, started for each integration run by `S/wp2p1/it.sh` and stopped
afterwards); no server left running.

| Check | Command | Result |
|---|---|---|
| Lint | `bash bin/lint.sh` | `Lint OK` (exit 0); pattern A 0, B 0, C 214 lines all pending, D 0 |
| Unit suite | `bash bin/test.sh` | `OK, but some tests were skipped! Tests: 785, Assertions: 30585, Skipped: 2` (exit 0, 98 s): the 769 of part 0 plus `ModulesTest` 16; the 2 skips are the WP4a checks of WP1 |
| Golden scheduler | `bash bin/test.sh tests/Unit/Automation/SchedulerGoldenTest.php` | 6 of 6, fixture unchanged |
| Wiring snapshot (unit) | `bash bin/test.sh tests/Unit/Wiring/WiringSnapshotTest.php` | 8 of 8 after the `rest_routes` rewrite; before it, only `testRestRoutesMatchTheSnapshot` failed, on its ordered assertion |
| Visitor cost | `bash bin/test.sh tests/Unit/Automation/VisitorRequestCostTest.php` | 13 of 13, unchanged test |
| Integration suite, incl. coexistence | `S/wp2p1/it.sh` (fixture router on 8794, then `LGA_WP_PATH=S/wp-test-lga/wordpress LGA_FIXTURE_LOG=S/lga-run/fixture.log php tests/integration/run.php`) | `Passed: 534  Failed: 0` (exit 0, 34 s), coexistence `Passed: 36  Failed: 0`; the wiring step 11 of 11 after the rewrite (before it: the 9 other sections matched, `frontend_plugin_files` and `rest_routes` did not); the site's `debug.log` gained no line (78 before and after) |
| WP-CLI on the test site | `php S/tools/wpcli/wp-cli.phar … eval` | `cli_init` holds `lazy:CommandRegistry::register`; 13 modules; no command; no boot error; `HealthMonitor::run()` gives `opcache` ok and `coexistence` ok on the real site |
| Build (extra) | `OUT_DIR=S/wp2p1/dist TMPDIR=S bash bin/build-zip.sh` | exit 0: `Lint OK`, `Neutrality OK (zip)`, `Neutrality OK (source)`; plugin ZIP 245 entries with the module, `Health/` and `Cli/` files; both ZIPs deleted afterwards, `dist/` untouched |
| Page-view cost | `S/wp2p1/measure.sh` | the table of deliberate difference 2 |
| Engine untouched | `git status --porcelain` at the repository root | only `linkgrowth-autopilot/` changed |

The empty `lga-e2e*` files the unit runs created in `/tmp` (four per run) were deleted; the ones that were there
before were left.

## Part 2: what was built

1. **The settings schema** (`includes/Support/SettingsSchema.php`, a container service of core).
   - A rule is a plain array with a `type`, built with static helpers so the modules read like the §2.2 list:
     `bool()`, `int( min, max, clamp|refuse )`, `float( min, max, clamp|refuse )`, `enum( values, fallback )`,
     `string( sanitize text|textarea|key|none, trim, strip, case, empty keep|refuse, pattern, length, max )` (with
     `text()` and `textarea()`), `email()`, `url()`, `domain()`, `timezone()`, `list( string|int|domain|url, max_items,
     item_max, min/max, case, unique )` (the host lists are `list( 'domain' )`), `records( fields, max_items )` (a list
     of objects whose fields are rules, `required` and `default` per field: the linkable assets), `secret( max,
     json_keys )` (the write-only credentials of `Support\Secrets`; `json_keys` is the service-account JSON check),
     `readOnly( of )`, and `optional( rule, default )` for a key POST /settings accepts that `Settings::defaults()`
     does not list. "json/map" of the task list is covered by `records`, the secret's `json_keys` and the nested
     sections (`automation.daily_limits.*` are one int rule per counter, as the engine accepted only those keys).
   - `rules()` collects every module's `settingsRules()` in module order (a path two modules declare keeps the first
     module's rule and is listed by `duplicates()`; an entry without a dotted lower-case path or with an unknown type is
     left out); `rule()`, `defaults()` (the flattened defaults plus the optional keys' defaults), `sanitize()` (a
     partial settings tree: every value through the rule of its dot path; unknown keys, refused values and read-only
     keys left out, an object left empty dropped, a list may be emptied), `sanitizeValue()`, `secretRule()`,
     `secretProblem()`, `validUrl()`, `domainValue()`, `flatten()`.
   - **One rule per key, in the module that owns the section** (120 rules: the 116 keys of `Settings::defaults()` and
     the four optional keys the engine's `EXTRA_SCHEMA` added):

     | module | rules | sections |
     |---|---|---|
     | `core` | 24 | site (16), advanced (8) |
     | `discovery` | 9 | discovery |
     | `outreach` | 13 | outreach |
     | `monitoring` | 8 | monitoring |
     | `seo` | 9 | seo (`indexnow_key` read-only) |
     | `integrations` | 24 | providers (21) and the optional `providers.seranking.fetch_overview`, `fetch_summary`, `providers.ahrefs.fetch_metrics` |
     | `automation` | 28 | automation (26), growth (2) |
     | `compliance` | 4 | compliance (the three acknowledgement keys read-only) |
     | `content` | 1 | the optional `content.linkable_assets` (records) |

     By type: int 42, bool 33, string 20, list 9, email 4, read-only 4, enum 3, url 2, domain 1, timezone 1, records 1.
     The int ranges are the engine's `INT_RANGES` (by leaf key; every other int "a whole number from 0"), all clamped.
   - **`Admin\Rest\SettingsController`** validates with the schema: `update()` calls `SettingsSchema::sanitize()`;
     `EXTRA_SCHEMA`, `INT_RANGES`, `READ_ONLY`, `EMAIL_KEYS`, `TEXTAREA_KEYS`, `DOMAIN_LISTS`, `sanitizeSettings()`,
     `sanitizeNode()`, `sanitizeList()`, `sanitizeScalar()`, `sanitizeAssets()`, `dropReadOnly()`, `validUrl()` and
     `domainValue()` are gone (1,054 → 717 lines). The secrets keep their order of checks; the key, length and JSON
     checks are the schema's secret rules. Everything after the sanitising (Autopilot, the confirmations, the claims
     filter on assets, the audit) is unchanged.
2. **The task registry** (`includes/Automation/`).
   - `Frequency`: the eight names of §5.1 (`15min`, `hourly`, `6h`, `daily`, `weekly`, `monthly`, `continuous`,
     `off`), `seconds()` and `label()` (the engine's cadence words). WP2 keeps the engine's due rule (the interval
     since the last enqueue); the calendar rule of §5.2 and `next()` are WP10's (`monthly` is 30 days until then).
   - `TaskDefinition`: the constructor of §5.1, field for field and in order, plus three WP2 fields after them
     (`cadence`, `order`, `schedule`; deviation 3 below). The class comment says which values are the engine's and
     which later packages fill.
   - `Context`: `Context::scheduler( now, lastRuns )` (the tick, with the `lga_stage_runs` it read) and
     `Context::manual()`.
   - `TaskRegistry` (a container service of automation): `tasks()` collects every module's `tasks()` in module order,
     applies `lga_scheduled_tasks`, checks each entry and sorts by `order` (stable); left out, with the reason in
     `rejected()`: not a TaskDefinition, bad or taken id, unregistered or already scheduled job type, priority outside
     1-9, unknown frequency, `continuous` without a due-check, `off` that may not be off, outbound without
     requiresProfile, autopilot without a consent, a module flag, consent or pool that is not a key, a due-check or
     schedule method that does not exist. `task()`, `schedule()` (`{every, cadence}` now: the task's schedule method,
     else the frequency) and `gate()`.
   - **`gate( TaskDefinition, Context )`** returns `''` or the reason code of the first gate that holds the task:

     | # | gate | reason | WP2 |
     |---|---|---|---|
     | 1 | `automation.enabled` | `lga_paused_master` | live |
     | 2 | the task's automation flag (`Autopilot::flag()`) | `lga_module_off` | live |
     | 3 | requiresProfile | `lga_profile_incomplete` | pass-through stub, `TODO(WP4b)` |
     | 4 | requiresSetup | `lga_setup_incomplete` | pass-through stub, `TODO(WP4b)` |
     | 5 | consent | `lga_consent_missing` | pass-through stub, `TODO(WP4b)` |
     | 6 | autopilot (scheduler only) | `lga_autopilot_off` | pass-through stub, `TODO(WP4b)` |
     | 7 | LoadGuard pause (this request's answer) | `lga_paused_load` | live |
     | 8 | the limit pool has room | `lga_limit_reached` | live |
     | 9 | schedule (scheduler only): off / interval since the last run / due-check | `lga_task_off`, `lga_not_due`, `lga_no_work` | live |
     | 10 | lock (scheduler only): a job of the type is queued or running | `lga_already_queued` | live |

     A due-check that throws is not caught by the gate: the tick logs "Stage scheduling failed" and goes on, as before.
   - **Due-checks** moved out of `Pipeline` unchanged into a `DueChecks` class per module (a container service of
     each, built with the container so they resolve services lazily as `Pipeline` did): `Scoring\DueChecks::evaluateDue`,
     `Discovery\DueChecks::opportunitiesDue` and `catalogImportDue` (the engine's `verifiedImportDue`),
     `Submission\DueChecks::submitDue`, `Outreach\DueChecks::outreachDue`, `Monitoring\DueChecks::monitorDue` and
     `importSchedule`, `Seo\DueChecks::indexnowDue` and `internalLinksSchedule`. A task names them by method; the
     registry resolves the name against the `DueChecks` class of the declaring module's namespace, or `Class::method`
     for a task an extension adds through the filter. The limit checks the engine made at the top of `evaluateDue`,
     `submitDue` and `outreachDue` are the tasks' `budgetPool` now (gate 8, same order of checks).
   - **The engine's 20 stages as tasks**, each declared by the module that declares its job, in the engine's order
     (`order` 10 … 200):

     | order | task id | job type | module | frequency (engine) | flag | due-check / schedule | priority | pool |
     |---|---|---|---|---|---|---|---|---|
     | 10 | discover | discover | discovery | daily | discovery | — | 6 | — |
     | 20 | mention_scan | mention_scan | discovery | weekly | discovery | — | 6 | — |
     | 30 | evaluate | evaluate | scoring | continuous | evaluation | evaluateDue | 4 | evaluations |
     | 40 | find_opportunities | find_opportunities | discovery | continuous | opportunities | opportunitiesDue | 5 | — |
     | 50 | positioning_audit | storefront_audit | compliance | daily | — | — | 2 | — |
     | 60 | catalog_import | import_verified | discovery | continuous | — | catalogImportDue | 4 | — |
     | 70 | package | package | submission | hourly | packaging | — | 5 | — |
     | 80 | submit | submit | submission | continuous | submissions | submitDue | 3 | submissions |
     | 90 | verify_listings | verify_listings | submission | 6h | — | — | 6 | — |
     | 100 | outreach_send | outreach_send | outreach | continuous | — | outreachDue | 3 | outreach_emails |
     | 110 | outreach_drafts | auto_outreach | outreach | daily | — | — | 6 | — |
     | 120 | follow_ups | follow_ups | outreach | daily | — | — | 6 | — |
     | 130 | monitor_backlinks | monitor_backlinks | monitoring | continuous | monitoring | monitorDue | 4 | — (in the due-check) |
     | 140 | import_backlinks | import_backlinks | monitoring | weekly (`monitoring.import_interval_days`) | imports | importSchedule | 6 | — |
     | 150 | cleanup | cleanup | automation | daily (cannot be off) | — | — | 8 | — |
     | 160 | metrics_refresh | metrics_refresh | integrations | weekly | — | — | 7 | — |
     | 170 | site_audit | site_audit | seo | daily | — | — | 6 | — |
     | 180 | indexnow | indexnow | seo | hourly | — | indexnowDue | 6 | — |
     | 190 | internal_links | internal_links | seo | weekly (daily while links are applied automatically) | — | internalLinksSchedule | 7 | — |
     | 200 | broken_link_scan | broken_link_scan | discovery | off (only when a person starts it) | — | — | 7 | — |

     Priorities are the job classes' `priority()`; the cadence texts of the Automation page are the engine's.
     requiresProfile, requiresSetup, consent, autopilot and outbound carry the §5.1 values (P, S, C:x, A, O), so
     submit is P S C:submissions A O, outreach_send and follow_ups P S O, site_audit and cleanup need no profile.
3. **`Automation\Pipeline`**: `stages()` and the eight due methods are gone (625 → 517 lines). `enqueueDueStages()`
   builds `Context::scheduler( time(), lga_stage_runs )`, asks `gate()` for each task in tick order and inserts the job
   of each task it lets through (the lock gate did the duplicate check, so the insert does not repeat it:
   `enqueueStage()` still checks for everything else that calls it). `status()['stages']` (job type => priority,
   cadence, flag) comes from the registry, then the registered job types no task schedules, as before. The
   `lga_stage_runs` marker stays keyed by job type (`GrowthRun` writes it too).

## Part 2: deliberate differences (with reasons)

None in any snapshot: `scheduler.json`, `wiring.json` and `wiring-wordpress.json` are byte for byte the files part 1
left (sha256 checked after the runs, `S/wp2p2/golden-part1.sha256`); the golden scheduler sequence (6 of 6 scenarios)
and every section of both wiring snapshots matched without a rewrite, the 975 settings probes included. Beyond them:

- **The differential check of POST /settings** (`S/wp2p2/SettingsDiffTest.php`, run with the unit bootstrap; not part
  of the plugin or the suite): the engine's controller (e160656, renamed `OldSettingsController` in the scratchpad)
  and the schema-driven one answered 15,626 generated requests on fresh installs identically (response, stored
  option, option names, custom seeds): every key with 112 to 130 values (13,725 requests: numbers, numeric strings,
  floats, booleans, null, empty and padded text, tags, line breaks, long and multi-byte text, e-mails, URLs, hosts,
  zones, enum words in any case, lists of every shape, nested and associative arrays), 1,500 random trees (several
  sections and keys, unknown keys, groups sent as scalars or lists, `confirm` on and off) and 401 secret requests.
  A one-rule mutation (`advanced.log_retention_days` up to 366) was caught and reverted.
- **A job class replaced through `lga_job_types`** keeps the task's priority (the job class's own priority when the
  module declared it). Before, a replacement class's `priority()` was used at enqueue. The unit fakes are such
  replacements (all priority 5); no test depended on it.
- **A database error in the duplicate check** of a tick is logged as "Stage scheduling failed: …" instead of "Could not
  enqueue stage: …" (both automation errors with the job type; the stage is not enqueued either way).
- Page views: the module classes carry their rules and tasks, so the 20 files a page view loads are 120,726 bytes
  instead of 95,249 (still 20 files, no plugin query or write). Measured with part 1's script (`S/wp2p2/measure.sh`, 60
  page views per mode after 10 warm-up ones, medians): boot() 171 µs with OPcache off (part 1: 180), 168 µs with
  OPcache on (part 1: 165), whole page view 140.7 and 141.3 ms: no change beyond the noise.

## Part 2: deviations from the design, with reasons

1. **Task ids of §5.1, job types of the engine.** `positioning_audit` runs `storefront_audit`, `catalog_import` runs
   `import_verified` and `outreach_drafts` runs `auto_outreach`: renaming the job types would change the golden
   sequence and the `job_types` snapshot, which WP2 must keep. The scheduler package (WP10) renames them.
2. **The engine's schedule, not the §5.1 defaults.** Frequencies (site_audit daily, indexnow hourly, broken_link_scan
   off …), priorities (the job classes') and module flags (the engine's `automation.<flag>` switches read through
   `Autopilot::flag()`, so verify_listings, outreach_send, the drafts, follow-ups, metrics, SEO and broken-link tasks
   have none) are the engine's, so behaviour is unchanged. §5.1's table, the `automation.modules.*` flags (WP4a/WP4b),
   `automation.schedules.<task>` and `lga_tasks` come with WP10. `budgetPool` names only the three pools the engine
   checked before it enqueued (evaluations, submissions, outreach_emails); the monitor keeps its `backlink_checks`
   check inside its due-check, because the digest is due whatever the limit; the other tasks check their counters in
   their jobs as before. `runCap` is '' for every task (WP10 writes the bounds and CONFIGURATION.md).
3. **Three fields beyond §5.1 on `TaskDefinition`**: `cadence` (the Automation page's text, which the engine wrote per
   stage, e.g. "every tick inside the send window (when approved messages are ready)"), `order` (the engine enqueued
   in its own stage order, which is neither module order nor priority; the golden sequence depends on it, and the
   order among jobs of the same priority decides which runs first) and `schedule` (a DueChecks method for the two
   stages whose interval the engine took from settings: `monitoring.import_interval_days` and automatic internal
   links). All three go when WP10 moves the schedule into `lga_tasks`.
4. **Gates 3-6 are pass-through stubs** (`profileReady()`, `setupComplete()`, `consentRecorded()`,
   `autopilotAllows()`, each marked `TODO(WP4b)`): `setup.profile_ready`, `setup.completed`, the consent record and
   `Autopilot::allows()` do not exist before WP4a/WP4b. The fields they read already hold the §5.1 values.
5. **Two gates beyond the nine of §5.2**: the frequency and interval check (`lga_task_off`, `lga_not_due`, beside the
   due-check's `lga_no_work`; §5.2 names no code for the due-check) and the lock (`lga_already_queued`, the engine's
   duplicate check, which §5.2 describes as `active_key` for continuous tasks). They come last, in the engine's
   order (interval, due-check, duplicate check), so no query moves.
6. **Gates before enqueue only.** §5.2 also checks them "again when a claimed job starts"; that is WP10 (runner) and
   WP4b (switches). Nothing calls `gate()` with `Context::manual()` yet: Run now and Grow now keep their own paths
   until WP10's GrowthRun rework. Note for WP10: the engine's growth run continues while automation is off, while
   §5.2's manual context is held by the master switch (gate 1); `gate()` follows §5.2.
7. **Registration checks the §5.1 invariants** (outbound implies requiresProfile, autopilot names a consent,
   priorities 1-9, continuous needs a due-check) instead of leaving them to a test only, so an extension cannot
   register an outbound task that would run before the profile exists.
8. **Due-checks of filter tasks** are written `Class::method` (built through the container, or with the container
   as the only argument): a task added through `lga_scheduled_tasks` has no module whose `DueChecks` class could be
   used. A module added through `lga_modules` uses plain method names, like the built-in ones.
9. **The schema keeps the engine's rule types and their quiet refusals.** §2.2's new types (`iso_country`,
   `iso_region`, `bcp47`, `weights` …), its renamed and removed keys and the 400 `lga_invalid_setting` answer are
   WP4a's complete schema (with `SettingsKeysTest`). One engine quirk is kept and tested: `site.country` refuses '',
   so a country cannot be cleared from the form.
10. **The secret rules live in `SettingsSchema`** (keyed by the `Support\Secrets` keys): secrets are not settings
    keys and have no module section; WP11/WP4a can move them to the provider modules with the provider contract.

## Part 2: tests changed (none weakened)

- New `tests/Unit/Support/SettingsSchemaTest.php` (19 tests): exactly one rule per key of `Settings::defaults()` and no
  rule without a key except the four optional ones (with the defaults the services read); each module declares the
  rules of its own sections; every rule's type fits its default (and the default passes its own rule, except the
  country quirk); the integer rules have the engine's ranges (42 rules, the engine's table written in the test); one
  test per rule type, accepting, refusing and clamping or normalising: bool, int (clamp and refuse), float (clamp
  and refuse), enum (and its fallback), string (text, textarea, key, strip, case, length, pattern, empty), email,
  url, domain, timezone, list (strings, hosts, URLs, integers, own limits), records, secret (key, length, JSON keys)
  and read-only; a whole tree (unknown sections and keys, refused values, empty groups dropped, lists emptied, an
  optional key); a module added through `lga_modules` contributes rules, may not take over another module's key, and
  malformed entries are left out.
- New `tests/Unit/Automation/TaskRegistryTest.php` (17 tests): every engine stage (the e160656 `stages()` table written
  in the test) is a task with the engine's interval, flag, due-check, priority and text, every §5.1 field and its
  default, the §5.1 gate values, in the engine's order, declared by the module of its job; ids and job types are
  unique and the §5.1 invariants hold; the due-checks and schedules are methods of the owning module's DueChecks;
  the schedules follow `monitoring.import_interval_days` and automatic internal links; a task added through
  `lga_scheduled_tasks` is enqueued by the tick with its priority, last, and again only after its interval; a filter
  task with its own due-check is enqueued only while it finds work; a task removed by the filter is not scheduled
  and `Pipeline::stages()` is gone; 15 kinds of invalid entry are left out with their reason; the gate order and
  reason codes; then one test per gate (1, 2, 3-6, 7, 8, 9, 10), each also showing that the gate before it wins; and
  the manual context.
- `tests/Unit/Core/ModulesTest.php` (16 → 18 tests): the settings rules and the tasks are the union of the modules'
  declarations; 106 container factories (98 plus the settings schema, the task registry and six DueChecks); the probe
  module of the `lga_modules` test also declares two rules and a task with a due-check, which the schema and the
  registry collect and the gate applies.
- `tests/Unit/CrossSlice/OutreachFollowupsTest.php`: its helper called the private `Pipeline::outreachDue()` by
  reflection; it now asks `TaskRegistry::gate()` for the outreach_send task (what the scheduler asks) and asserts that
  the answer is '' or `lga_no_work`, so only the due-check decides, as before. The four tests that use it are
  unchanged.
- Comments only: `tests/Unit/Wiring/WiringSnapshotTest.php` (where the optional keys' defaults live now) and
  `tests/Unit/CrossSlice/SupportMonitoringFollowupsTest.php` (`Monitoring\DueChecks::monitorDue`).
- `SchedulerGoldenTest`, `WiringSnapshotTest`, `VisitorRequestCostTest` and all three golden fixtures: unchanged.

## Part 2: evidence

All commands from this folder, PHP 8.3.6, `nice -n 10`, every PHPUnit and integration run under
`flock S/lga-suite.lock`, one after another. Ports 8793 (the site, started and stopped by the suite and by the
measurement script) and 8794 (the fixture router, started for each integration run by `S/wp2p2/it.sh` and stopped
afterwards); no server left running. Lint, the unit suite, the integration suite and the build ran twice in that
order: once after the work, and again on the final tree after the last edit (a comment and a safer registry lookup
in `Pipeline::tasks()`); the figures below are the final run's, and both runs gave the same counts.

| Check | Command | Result |
|---|---|---|
| Lint | `bash bin/lint.sh` | `Lint OK` (exit 0); pattern A 0, B 0, C 214 lines all pending, D 0 |
| Unit suite | `bash bin/test.sh` | `OK, but some tests were skipped! Tests: 823, Assertions: 32161, Skipped: 2` (exit 0, 96 s): the 785 of part 1 plus `SettingsSchemaTest` 19, `TaskRegistryTest` 17 and `ModulesTest` 2; the 2 skips are the WP4a checks of WP1. The first run had 4 errors, all `OutreachFollowupsTest`'s reflection on the removed `Pipeline::outreachDue()` (fixed above) |
| Golden scheduler | `bash bin/test.sh tests/Unit/Automation/SchedulerGoldenTest.php` | 6 of 6, fixture unchanged |
| Wiring snapshot (unit) | `bash bin/test.sh tests/Unit/Wiring/WiringSnapshotTest.php` | 8 of 8, fixture unchanged (975 settings probes, 11 request cases) |
| Settings differential | `bash bin/test.sh S/wp2p2/SettingsDiffTest.php` | 3 of 3: 15,626 requests answered identically by the engine's and the new controller (84 s); the mutation run failed as it should |
| Integration suite, incl. coexistence | `S/wp2p2/it.sh` (fixture router on 8794, then `LGA_WP_PATH=S/wp-test-lga/wordpress LGA_FIXTURE_LOG=S/lga-run/fixture.log php tests/integration/run.php`) | `Passed: 534  Failed: 0` (exit 0, 32 s), coexistence `Passed: 36  Failed: 0`; the wiring step 11 of 11 with the part 1 fixture; the site's `debug.log` gained no line (78 before and after) |
| Build | `TMPDIR=S bash bin/build-zip.sh` | exit 0: `Lint OK`, `Neutrality OK (zip)` (C 120 pending), `Neutrality OK (source)`; `dist/linkgrowth-autopilot-1.0.0.zip` (256 entries, the 11 new files included) and `dist/linkgrowth-autopilot-1.0.0-source.zip` (446 entries, the new tests included); they replace the part 0 build in `dist/` (git-ignored); no staging folder left |
| Page-view cost | `S/wp2p2/measure.sh part2` | the figures under "deliberate differences"; the probe mu-plugin was removed after the run |
| Engine untouched | `git status --porcelain` at the repository root | only `linkgrowth-autopilot/` changed |

The empty `lga-e2e*` files the unit runs created in `/tmp` (16) were deleted; the 20 that were there before were left.

## Review fixes (two confirmed defects)

### 1. Shared tests pinned the built-in declarations exactly (seam)

**Defect.** The code seam worked, but the WP2 tests pinned the 13 built-in modules' declarations as exact values (ids,
"106 factories", "the 14 engine tables", 120 rules, 20 tasks and jobs, 12 controllers, the literal page and tab
order, the nine page keys written into two tests, the engine range table applied to every int rule, the whole
enqueue sequence and both wiring snapshots). One more line in `Core\Modules::LIST` therefore broke 34 unit tests in
7 shared files and 7 integration wiring checks, so a parallel package could not add its line and stay green without
editing WP2's tests and golden fixtures (against §3.1 and §13.1).

**Fix (tests only, no plugin code).**
- New `tests/Support/Wp2Modules.php`: the 13 WP2 module classes, their ids, their nine pages in navigation order,
  `declared( method )` (what the WP2 modules among the loaded ones declare), `only( list, subset )` (the items of a
  list that are in a subset, in the list's order) and the `lga_modules` callback `filter()` with `limit()` for unit
  tests. It loads without the plugin (class names as strings).
- `ModulesTest`, `SettingsSchemaTest`, `TaskRegistryTest`, `ReleaseWiringTest`, `SubmitterRoleTest`: every pin now
  applies to the WP2 modules' declarations, and the test checks that the collectors return them (as a subset, and
  in their order where order matters); the invariants that hold for any module (union equals the collector, no
  duplicates, every service builds, a known rule type that fits its default, one rule per key, the §5.1 task
  invariants, due-checks exist, no route of one controller matches another's path, every controller registers
  routes, one health result per check in the §5.8 shape) still run over every loaded module. Details: the invalid-task
  test counts the built-in tasks instead of 20 (the rejected entry's index follows); the DueChecks-is-a-service check
  applies to the WP2 modules (the registry builds another module's DueChecks with the container when it is no
  service); "only cleanup can never be off" and the read-only key list apply to the WP2 tasks and keys; the probe
  test of `ModulesTest` checks its page and tab positions among the WP2 ones and its WP-CLI command among all
  commands. No assertion was dropped and every fixed number or list is still asserted, for the WP2 modules; the
  invalid-task test's two numbers (the count and the rejected entry's index) are computed from the built-in tasks,
  which are 20 with the WP2 modules alone.
- `SchedulerGoldenTest`, `WiringSnapshotTest` and the request processes of `tests/integration/wiring-snapshot.php`
  run with the plugin limited to the WP2 modules through `lga_modules` (in the integration processes the filter is
  put into `$wp_filter` before WordPress loads, like the existing `lga_maintenance_request` one; the callback lives
  under `tests/`, so it is in no hook list and no page-view file list). The three fixtures are byte for byte the
  files of part 2 (sha256 checked).
- Not covered by the limit: the `cron` and `roles` sections of the integration snapshot read what the activations
  (with every module) left in the site's database. A later module that schedules its own recurring WP-Cron or Action
  Scheduler event, or adds a role or capability on activation, still changes those two sections (tasks run through
  the one tick, so a normal module adds neither).

**Regression check (fail before, pass after).** `S/wp2fix/make-copy.sh DEST` copies the fork (without `dist/`) and
adds a throwaway probe module, `includes/Probe/*` from `S/wp2fix/probe/` (id `seamx`: a service and its DueChecks,
a lazy boot hook, the table `seamx_items`, the default `seamx.limit` 3 with the rule `int(1,5)`, the job `seamx_run`,
a daily task with the due-check `seamxDue` and `requiresProfile:false`, GET `/seamx/ping`, a page at order 95, a
tab, a health check and a WP-CLI command), plus exactly one line after `ComplianceModule` in `Modules::LIST`; nothing
else. The integration runs used a copy of `S/wp-test-lga/wordpress` whose plugin symlink points at the copy, the
fixture router on 8794 (`S/wp2fix/it.sh`) and `LGA_ENGINE_PATH` set to the engine folder (read-only symlink, as in the
normal coexistence run). The copies were deleted afterwards.

| Run | Before the fix | After the fix |
|---|---|---|
| Full unit suite in the probe copy | `FAILURES! Tests: 823, Assertions: 26871, Failures: 34, Skipped: 2`: ModulesTest 15, SchedulerGoldenTest 6, WiringSnapshotTest 6, TaskRegistryTest 3, SettingsSchemaTest 2, ReleaseWiringTest 1, SubmitterRoleTest 1 | `OK, but some tests were skipped! Tests: 825, Assertions: 32403, Skipped: 2` (the 2 extra tests are those of fix 2) |
| Integration suite on the probe copy site | `Passed: 527  Failed: 7` (the 7 wiring-snapshot sections hooks.frontend/admin/rest/cron, frontend_plugin_files, rest_routes, tables), coexistence 36 of 36 | `Passed: 534  Failed: 0`, coexistence 36 of 36; the copy's database held `wp_lga_seamx_items` and `lga_settings.seamx = {"limit":3}`, so the probe was active |

### 2. `SettingsSchema::sanitize()` kept keys that hold a dot

**Defect.** `sanitize()` accepted any top-level key that is a node of the tree, and `isNode()` also lists group paths
such as `automation.daily_limits`; `sanitizeNode()` built `path.key` without refusing a key with a dot. So
`{"automation.daily_limits":{"submissions":999}}`, `{"automation":{"daily_limits.submissions":999}}` or
`{"providers":{"seranking.enabled":false}}` matched a real rule and were stored under the literal dotted key. The
engine (e160656) walked the defaults one segment at a time and dropped them. Through `SettingsController::update()`
the junk stayed in `lga_settings` for good, was never read by `Settings::get()`, was reported as a change in
`changed` and the `settings.updated` audit row, and then hid a real change of the same key (5 → 50 applied but
`changed: []`, no audit row). Only the automation and providers sections (three-segment rules) were reachable;
gated flags and read-only keys were not.

**Fix.** `includes/Support/SettingsSchema.php`: `sanitize()` and `sanitizeNode()` accept a key only when it is one
path segment (`isSegment()`: a string matching `/^[a-z0-9_]+$/D`, the segments `rules()` accepts). Any other key
could never match a rule or node except through a dot, so the only behaviour change is that dotted keys are unknown
again, as at e160656.

**Regression checks (fail before, pass after).** New in `tests/Unit/Support/SettingsSchemaTest.php` (19 → 21 tests):
`testSanitiseLeavesOutKeysThatHoldADotAtEveryLevel` (six dotted inputs at the top, in a section and in a group give
`[]`; real keys beside dotted ones are kept) and `testPostSettingsStoresNoDottedKeyAndReportsAndAuditsTheRealChange`
(through `SettingsController::update()`: a dotted-only request changes and stores nothing and writes no audit row;
the following real change 5 → 50 is reported in `changed` and audited). Before the fix both failed (`Tests: 2,
Assertions: 3, Failures: 2`: sanitize kept `automation.daily_limits`, and the first request reported
`automation.daily_limits.submissions`, `providers.ai.enabled` and `providers.seranking.enabled` as changed); after it
`SettingsSchemaTest` is 21 of 21. The scratch differential of part 2 (`S/wp2fix/diff/SettingsDiffTest.php`, the
engine's controller against the schema-driven one on fresh installs) gained `testDottedKeys`: every key with each
pair of adjacent segments joined by a dot, alone and beside another key, and each whole dot path as one top-level
key (732 requests). On the unfixed schema it failed on the first case (`{"providers":{"seranking.enabled":true}}`);
on the fixed tree all four tests pass: 13,725 + 1,500 + 732 + 401 = 16,358 requests answered identically.

### Tests changed by the fixes (none weakened)

`tests/Support/Wp2Modules.php` (new), `tests/Unit/Core/ModulesTest.php`, `tests/Unit/Support/SettingsSchemaTest.php`,
`tests/Unit/Automation/TaskRegistryTest.php`, `tests/Unit/Automation/SchedulerGoldenTest.php`,
`tests/Unit/Wiring/WiringSnapshotTest.php`, `tests/Unit/Wiring/ReleaseWiringTest.php` (its own copy of the nine page
keys removed), `tests/Unit/Admin/SubmitterRoleTest.php`, `tests/integration/wiring-snapshot.php`. Plugin code:
`includes/Support/SettingsSchema.php` only.

### Evidence of the fixes

All commands from this folder, PHP 8.3.6, `nice -n 10`, every PHPUnit and integration run under
`flock S/lga-suite.lock`, one after another: lint, full unit, full integration, build. Ports 8793 (started and stopped
by the suite) and 8794 (the fixture router, started by `S/wp2fix/it.sh` for the run and stopped afterwards); no
server left running.

| Check | Command | Result |
|---|---|---|
| Lint | `bash bin/lint.sh` | `Lint OK` (exit 0); pattern A 0, B 0, C 214 lines all pending, D 0 |
| Unit suite | `bash bin/test.sh` | `OK, but some tests were skipped! Tests: 825, Assertions: 32213, Skipped: 2` (exit 0, 96 s): the 823 of part 2 plus the two tests of fix 2 |
| Integration suite, incl. coexistence | `S/wp2fix/it.sh` (fixture router on 8794, then `LGA_WP_PATH=S/wp-test-lga/wordpress LGA_FIXTURE_LOG=S/lga-run/fixture.log php tests/integration/run.php`) | `Passed: 534  Failed: 0` (exit 0), coexistence `Passed: 36  Failed: 0`, the wiring step with the unchanged fixture; the site's `debug.log` gained no line (78 before and after) |
| Build | `TMPDIR=S bash bin/build-zip.sh` | exit 0: `Lint OK`, `Neutrality OK (zip)` (C 120 pending), `Neutrality OK (source)`; `dist/linkgrowth-autopilot-1.0.0.zip` (256 entries, no test file) and `dist/linkgrowth-autopilot-1.0.0-source.zip` (447 entries: part 2's 446 plus `tests/Support/Wp2Modules.php`) |
| Golden fixtures | `sha256sum -c S/wp2p2/golden-part1.sha256` | `scheduler.json`, `wiring.json`, `wiring-wordpress.json`: OK |
| Engine untouched | `git status --porcelain` at the repository root | only `linkgrowth-autopilot/` changed |

The empty `lga-e2e*` files the unit runs created in `/tmp` were deleted; the 20 that were there before were left.

## Observations for later packages (not changed)

- A package that adds a module adds one line to `Core\Modules::LIST` and its own tests; WP2's tests and the three
  golden fixtures stay as they are (they pin the WP2 modules, see the review fixes above). A package that changes a
  WP2 module (WP4a's schema, WP10's schedule) changes what they pin and updates them in its notes. To check that a
  package's own tests keep this property, repeat the probe run of review fix 1: in a throwaway copy, add a module of
  everything and one `LIST` line, then run the unit and integration suites.

- `uninstall.php` keeps its own list of the 14 table names (it runs without the plugin loaded). WP5, which adds the
  new tables and the per-site uninstall loop, must make uninstall read the modules' tables (or keep the list in step).
- `tests/Support/FrozenClock.php` covers the new namespaces (`Health`, `Health\Checks`, `Cli`) by itself: it
  scans `includes/`.
- WP4a: the complete schema of §2.2 adds its rules next to the sections in the modules' `settingsRules()`, renames
  and removes engine keys there and in `settingsDefaults()`, and can answer refusals with 400 `lga_invalid_setting`
  from `SettingsSchema::sanitizeValue()` returning null (today the value is left out quietly).
- WP4b: the four `TODO(WP4b)` methods of `TaskRegistry` are the switches, consents and Autopilot gates; the task fields
  they read are filled already.
- WP10: `Frequency::next()` and `lga_tasks` replace the interval since `lga_stage_runs`; then `TaskDefinition::$order`,
  `$cadence` and `$schedule` can go, the §5.1 defaults (frequencies, priorities, flags, pools, bounds) replace the
  engine's, the job types `storefront_audit`/`import_verified` get their task ids, and GrowthRun asks `gate()` with
  `Context::manual()` (see part 2 deviation 6 about the master switch).
