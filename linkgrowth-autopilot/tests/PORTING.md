# Test porting record (WP1)

How the engine's unit suite became this fork's suite (DESIGN.md §1.3 step 5). Rule: a test is never weakened to
pass; it is either kept with neutral data (the demo businesses of `tests/Support/Profiles.php`, DESIGN.md §12.2) or
deleted, and every deletion names the test a later work package must add.

Counts: the engine suite had 725 test methods (732 tests with data sets). This suite has 748 methods: 1 deleted,
4 renamed, 24 added (16 in part 1, 4 in part 2, 4 with the review fixes). `bash bin/test.sh` runs 755 tests (2 of
them skipped with the reason, see below). The engine's integration run had 479 checks; this fork's `tests/integration/run.php` keeps all
479 (no section tested only the engine's data, so none was deleted) and adds 8 harness checks and the 36 checks of
the coexistence section: 523 in all (see "Integration suite" below).

## Deleted

| Engine test | Why it only tested the engine's own data | Successor (must be added by) |
|---|---|---|
| `Unit/Outreach/FooterRefreshTest::testTheChangelogSaysWhenTheStuckDraftsAreApprovedAutomaticallyAndWhatIsAudited` | It read the engine's `readme.txt` changelog entry for release 1.2.1. The fork's changelog starts at 1.0.0 (§1.6) and the readme is not the place for behaviour notes. The behaviour it described (Autopilot approving waiting drafts) is removed in WP4b (§2.7). | WP4b `tests/Unit/Automation/AutopilotPolicyTest.php`: no draft, campaign or first contact is approved automatically, with or without Autopilot (§2.7, §12.9 restriction table). WP29: `docs/FEATURES.md` describes the approval rules. |

## Assertions retired inside kept tests

These assertions checked engine release artefacts that DESIGN.md §1.3 step 1 does not copy (the engine's `docs/`
and its 1.2.x changelog). Each test still runs with every other assertion.

| Test | Retired assertions | Replacement |
|---|---|---|
| `Unit/Wiring/ReleaseWiringTest::testServicesJobsControllersPagesAndSettingsAreRegistered` | header `Version: 1.2.4`, `LGA_VERSION` 1.2.4, `Stable tag: 1.2.4`, and the 1.2.4 and 1.2.3 changelog sentences | New `testHeaderVersionConstantStableTagAndChangelogAgree` (§1.6): header Version, `LGA_VERSION`, Stable tag and the first changelog entry agree whatever the value; the §1.1 header identity; `Plugin::NAME`. |
| `Unit/Wiring/ReleaseWiringTest::testServerPressureServicesHooksAndDocsAreWired` | needles in the engine's `docs/OPERATIONS.md`, `docs/TESTING.md` and its readme changelog (server-pressure filters and OPcache values) | WP29 docs test (`tests/Unit/Wiring/DocsWiringTest.php`): `docs/OPERATIONS.md` has a "Server pressure" section naming `lga_pause_background`, `lga_backup_running`, `lga_max_load_per_cpu`, `lga_min_free_memory_ratio`, `lga_cpu_count` and both OPcache value sets, and `docs/TESTING.md` (a WP29 deliverable, §13.2) lists `tests/Unit/Automation/LoadGuardTest.php`, `tests/Unit/Automation/ServerLoadTest.php` and `tests/integration/server-pressure.php`. The readme needles were the engine's 1.2.4 changelog entry; the fork's changelog rule is `testHeaderVersionConstantStableTagAndChangelogAgree` (§1.6). The code-side assertions (services, hooks, constants, filters) and the `run.php` require check are kept. |
| `Unit/Support/DataSupportCoreFixesTest::testSuppressionTypeColumnFitsEveryTypeAndSchemaVersionWasBumped` | "the schema version was bumped past 1.0.0" (an upgrade of engine installs that had `varchar(10)`) | The fork's baseline schema 0.9.0 has `varchar(20)` from the start and no fork install ever had the narrow column; the test now asserts that the declared `LGA_DB_VERSION` is the one the tests run against. The 0.9.0 → 1.0.0 upgrade is WP5's integration section `upgrade-0.9.0`. |
| `Unit/Content/DiscoveryContentFixesTest::testFactsSupportsRequiresVerifiedAndMatchingStatement` (part 2) | "an edited statement no longer supports a template written from the shipped default wording" (`supports( 'payment' )` false after an edit) | No fact ships any more (§1.7 E), so there is no default wording to compare an edit with. The test now asserts that a verified edited statement supports itself, that the detail the edit dropped is no longer supported, and that an unverified fact supports nothing. Successor for the rule that copy follows the owner's facts: WP7 U `FactsProvenanceTest` (§7.4: facts come only from the owner, the wizard or an owner-started import; `verify()` is the only path to verified). |
| `Unit/Content/ContentTest::testFactsRegistryOnlyExposesVerifiedFacts` (part 2) | "after a reset the identity statement is the shipped default" | After a reset the facts are the owner's business profile: the test asserts `FactsRegistry::defaults()` is empty and the identity and location statements the Northwind profile gives. |

## Renamed (all tests kept)

| Engine | Fork | Notes |
|---|---|---|
| `Unit/Compliance/StorefrontAuditTest` | `Unit/Compliance/PositioningGateTest` | class rename of §1.1 |
| `Unit/Verified/VerifiedOpportunitiesTest` | `Unit/Verified/CatalogImporterTest` | all 13 tests ported to the synthetic neutral catalog `tests/Support/NeutralCatalog.php` (250 fictional items instead of the 595-item store kit; batch, cursor, fingerprint, skip and owner-task rules unchanged) |
| `Unit/Seo/LinkRulesLiveCaseTest` | `Unit/Seo/LinkRulesBilingualSiteTest` | all 8 tests ported from the store's live pages to a neutral bilingual site; the counterpart meta is the owner's key (`seo.language_counterpart_meta_key`) |
| `testSeedCatalogImports…` (`Unit/Discovery/DiscoveryTest`) | `testPackSeedsImportTheCatalog` | the fork ships no seed list (§1.3 step 1); the test installs neutral seeds through the `lga_seed_catalog` filter |
| `testBootHooksTheStorefrontAuditAndIndexNow` | `testBootHooksThePositioningGateAndIndexNow` | class rename |
| `Unit/Growth/GrowthFakes::Fake…ImportJob` | `FakeCatalogImportJob` | job class rename of §1.1 |
| `Unit/Analysis/ProfilerRelevanceRiskTest::testProfileOf…Directory` (named after the engine's home market) | `testProfileOfTheDirectory` | part 2: the fixture directory is in the United States now (schema.org `addressCountry` US); same assertions with the new values |

## Ported with neutral data (kept, same assertions on behaviour)

- **Store identity → demo businesses.** Brand, domain, e-mail, address, products and industry words in every test,
  fixture page and harness were replaced with the Northwind Supply Co. profile (`northwind.test`, Portland OR,
  industrial supplies and packaging). Brand-structure tests (offsets of the brand name and its tail, domain forms)
  were recomputed for the new name instead of being loosened. Mail-domain tests use `northwind.example.com` and
  `northwind.example.net`, because `Outreach\Compliance::mailDomain()` does not look up `.test`/`.example` names.
- **Storefront, link and claim fixtures (review fix).** The unit fixtures that still carried the store's catalog
  were ported: the consumer-use and research-only storefronts of `Unit/Compliance/StorefrontFixtures.php` (category
  names and slugs, a tag and product titles; the same terms, severities, item counts and findings), the labels in
  `ComplianceBannerHarness.mjs`, the internal-link suggestion of `Unit/Seo/LinksListTest.php` and
  `AutopilotUiHarness.mjs` (a Northwind guide with texts at least as long, so the page-size check is unchanged),
  two claims in `Unit/Content/DiscoveryContentFixesTest.php` and the clean asset of
  `Unit/CrossSlice/SettingsAutomationFollowupsTest.php`. The integration storefront category no longer starts with
  the store's category name. Pattern B now also lists the store's catalog, so these cannot come back.
- **Empty shipped profile.** `Support\Settings::defaults()` no longer carries a business profile, so tests that relied
  on the store address as a fallback now apply `Profiles::apply()` first; two of them also assert the new fresh-install
  behaviour (the postal address is reported missing until a business address exists).
- **Removed search provider.** Tests that used the removed reseller of scraped results now use Brave Search or Google
  Programmable Search (the same `AbstractSearch` refund, quota and fallback rules; daily caps 60 and 90).
- **Data files that are not copied.** Query templates and seeds come from `tests/Support/DiscoveryData.php` through the
  plugin's own filters; the taxonomy gets the demo business's topics through `discovery.extra_terms`
  (`Profiles::applyTopics()`), as an owner would enter them.
- **No AI model default.** Tests that call the AI writer set a model id first (`providers.ai.model`).
- **Schema version 0.9.0.** "Schema behind" tests use 0.8.0 as the older version.
- **Send-window zones.** The region-to-zone map is exercised with a British Columbia and a Nova Scotia business; the
  local times come from the time zone data instead of fixed UTC offsets.

## Added

- `Unit/Integrations/ForkRemovalsTest` (3): no AI model ships and the writer stays off until the owner names one;
  only the remaining search providers are registered and a stored primary of the removed one is refused; the
  translation counterpart meta is read only under the key the owner names.
- `Unit/Wiring/NeutralityTest` (15): patterns A, B and D over the fork (through `bin/neutrality.sh` and again in PHP),
  planted hits, the ZIP and source-ZIP modes, the pending list, and the structural checks of §1.7 E. Review fixes:
  exceptions and the C allowlist match whole paths only (a `.bak` copy of an excepted file is scanned), a `dist/`
  folder below the top level ships and is scanned in every mode (only the working copy's top-level build output is
  left out, in the script and in its PHP twin), and a build whose source ZIP fails its check leaves no archive under
  either release name (`bin/build-zip.sh` on a minimal tree with lint stubbed). Skipped with the
  reason: `testNoDefaultEnablesOutboundAcquisition` (the module flags are WP4a's settings schema) and
  `testDataHoldsExactlyTheLayoutFiles` (the packs, schemas and catalog are written in WP4a and WP9).
- `Unit/Wiring/ReleaseWiringTest::testHeaderVersionConstantStableTagAndChangelogAgree` (§1.6).
- `Unit/Wiring/PortingRecordTest` (1, review fix): every engine document whose assertions a row of "Assertions
  retired inside kept tests" retires is named again in that row's replacement (skipped in the source ZIP, which
  leaves this file out).
- `Unit/Core/EnvironmentTest` (4, part 2): `Core\Environment::conflicts()` (DESIGN.md §1.4) reports nothing by default,
  an owner-listed active plugin gives the §1.4 warning on the dashboard and in Site Health (a recommendation,
  nothing blocked), a listed inactive plugin and this plugin itself are never reported, another plugin's callback on
  `lga_tick` is found and the plugin's own is not, and the Site Health test is registered beside the OPcache test.

## Part 2: content engine without store data (unit tests ported)

The source ZIP check (`bin/neutrality.sh --source`, patterns A, B and D with no exception) needs the shipped content
engine and outreach templates to carry no store data, so part 2 removed it (DESIGN.md §1.3 step 7, §1.7 B):
`Content\FactsRegistry::defaults()` is empty and the facts of an unsaved list come from the business profile;
`Content\LinkableAssets::defaults()` is the home page only; `Content\ContentGenerator`, `Outreach\Templates` and
`Outreach\Personalizer` write from the profile (what the business offers, where it is based, when it was founded)
and from the owner's verified facts as written. The tests that relied on the store's facts and pages now install the
demo businesses' own facts and pages, as an owner enters them (`tests/Support/Facts.php`):

| Test | Port |
|---|---|
| `Unit/Content/ContentTest` (4 tests) | Northwind profile and facts; the claims filter appends the owner's positioning fact (Fjordline) and the test asserts that exact statement; asset matching runs on the owner's asset list, and the home-only default is asserted too |
| `Unit/Content/DiscoveryContentFixesTest` (3 tests) | the gating tests start from the Northwind fact list instead of the store's; "every fact is clean" runs over the profile facts and both demo fact lists; see the retired assertion above |
| `Unit/Discovery/DiscoveryTest::testBrokenLinkFinderCreatesRelevantOpportunities` | the replacement link comes from the owner's asset list (a storage guide on the demo site) |
| `Unit/Verified/CatalogImporterTest::testPackageFieldsArePresentAndTheDescriptionIsResearchUseOnly` | descriptions vary by destination with the Northwind profile and facts (none ship) |

## Part 2: fixture sites without market words

The fixture sites (`tests/fixtures/sites/`) carried the engine's home market. They are now a neutral United States
world that matches the Northwind demo business (Portland, Oregon): the directory has a US address, JSON-LD
`addressCountry` US and a State select instead of a province select; the blog, university and resource pages are in
the Pacific Northwest; no page uses a term of pattern C. The listing page the engine's copy left out
(`fixtures/sites/*/listing`, §1.3 step 1) has a neutral successor, `labdirectory.test/listing/northwind-supply-co/`.
Unit tests that read the fixtures assert the same behaviour with the new values (`PageAnalyzerTest`,
`ProfilerRelevanceRiskTest`, `SubmissionFixesTest`, `SubmissionProspectsFollowupsTest`, `StorefrontGateTest`: the
business region moved from the old market's province to OR/Portland). `tests/bootstrap.php` uses
America/Los_Angeles as the default WordPress time zone and has an `is_plugin_active()` stub
(`$GLOBALS['lga_test_active_plugins']`).

## Integration suite

`tests/integration/run.php` runs against the LGA site of DESIGN.md §12.1 (`localhost:8793`, fixture server on
`127.0.0.1:8794`). Every engine section was kept and ported; none was deleted. What changed, by file:

| File | Port |
|---|---|
| `run.php` | `LGA_WP_PATH`, `LGA_FIXTURE_LOG`, `LGA_IT_ONLY`, `LGA_RESET`; host `localhost:8793`. On a fresh site the plugin is activated and the suite re-runs itself in a new process (the boot hooks only run when the plugin loads with WordPress), and the reset loads the plugin's autoloader first. The setup step applies the Northwind profile and topics, the neutral query templates (`tests/Support/DiscoveryData.php` through `lga_discovery_queries`) and the synthetic catalog (`tests/Support/NeutralCatalog.php`, 250 items, put in the plugin container), because the fork bundles none of them (§1.3 step 1). Country `CA` → `US` (the fixture directory's JSON-LD); "province ON" → "state OR" (the business region); the monitored listing link is `/test-reports/` "Test Reports" instead of the store's page. New: a step for the test mu-plugin (host logger, frozen clock; 8 checks) and the coexistence section as the last step. |
| `growth.php`, `growth-fixtures.php` | the storefront fixtures use consumer-use category names, a tag and products without store product names; the expected findings (the same eleven) and the "at least 7 blocking" bound are unchanged. The catalog checks use `NeutralCatalog::TOTAL` (250) instead of 595: all items in batches of 100 (at least 3 rounds instead of 6), imported = total minus skipped and more than 84 % of the total (the engine's "more than 500 of 595"), owner tasks = total minus skipped, the paid-only item is `NeutralCatalog::PAID_ID`. |
| `autopilot.php` | the send-window zone is checked for a business in British Columbia (America/Vancouver) instead of the old market's capital region; the region-to-zone map itself is unchanged (WP8 generalizes it). |
| `render-check.php`, `submitter.php` | neutral page and package text. |
| `coexistence.php` (new, not in the source ZIP) | DESIGN.md §1.4: both plugins boot, an LGA tick reads and writes no option of the engine, deactivating either plugin leaves the other's WP-Cron event and Action Scheduler action in place, the owner-listed engine gives the warning on the dashboard and in Site Health, the generic detectors find nothing else (and do find a foreign table), no outside host is contacted, and everything the engine's activation wrote is removed afterwards (36 checks). |
| `lga-test-env.php` (replaces the mu-plugin example) | the test mu-plugin of §12.1: loopback hosts allowed, `*.test` routed to the fixture server, the `pre_http_request` host logger (`lga_test_http_requests()`, `lga_test_http_hosts()`, `lga_test_http_reset()`, `LGA_HTTP_HOST_LOG`) for WP4b's `activation-silence`, `lga_test_time()` / the `lga_test_time` filter, and `LGA_TEST_KNOWN_CONFLICTS`. |
| `build-lga-site.sh` (new) | builds the LGA site from an existing local WordPress copy (core, WooCommerce, SQLite integration, twentytwentyfive), with its own database. |
