# WP1 — Fork, rename and test harness (parts 1 and 2)

Scope of part 1: DESIGN.md §1.3 steps 1-5, the §1.5 removals that belong to WP1 (the search provider that resells
scraped results, and the hard-coded translation-counterpart meta), §1.6, `bin/neutrality.sh` (patterns A-D with
`--zip` and `--source`) wired into `bin/lint.sh`, and `tests/Unit/Wiring/NeutralityTest.php`.

Scope of part 2 (section "Part 2" below): the LGA test site on port 8793 (§12.1), the fixture router on 8794, the
`lga-test-env.php` mu-plugin with the outbound host logger, the integration suite ported and green, the coexistence
section with `Core\Environment::conflicts()` (§1.4), `bin/build-zip.sh` writing the plugin and source ZIPs (§1.3
steps 6-7), WP-CLI (§12.7), and the final lint, unit and integration runs.

Paths below are relative to the repository root (`ROOT` in DESIGN.md §1.3) or to this folder; the engine folder is
`SRC` of §1.3, and `S` is the session scratchpad of DESIGN.md §12.1.

## Fork point

| Item | Value |
|---|---|
| `git rev-parse HEAD` | `338059c0b6fd3731b7b96897d9cc5e7d0fd7e35d` ("Rebuild the 1.2.4 release zip with the performance fixes", 2026-11-22) |
| `git status --porcelain` before the copy | empty (clean tree) |
| `git diff --stat` | empty |
| `git diff \| sha256sum` | `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855` (the hash of an empty diff) |

The engine's performance fixes (visitor-request cost in `Plugin.php`, `Automation/Scheduler.php`,
`Database/Migrator.php`, `Support/Locks.php`, `tests/bootstrap.php`, and the later bounded-work changes) are in that
commit and were carried over unchanged. After the work, `git diff --stat` of the repository is still empty: nothing
outside this folder was modified.

## Part 1: what was built

1. **Copy (step 1).** The listed paths only: `includes`, `assets`, `uninstall.php`, `readme.txt`, the main file,
   `data/universal/{keywords,excluded-domains}.json`, `tests/` without `fixtures/sites/*/listing`, `bin/{test,lint,
   build-zip}.sh`, `bin/phpunit.phar`, `phpunit.xml.dist`, `composer.json`. Not copied: the opportunity database, the
   seed list, the query templates, the database build script, `ops/`, `dist/`, `releases/`, `docs/`.
2. **File renames (step 2).** `Integrations/AiWriter.php`, `Compliance/PositioningGate.php`,
   `Discovery/CatalogImporter.php`, `Discovery/PackSeeds.php`, `Automation/Jobs/CatalogImportJob.php`,
   `Automation/Jobs/PositioningAuditJob.php`; the search-provider adapter of §1.5 deleted. No other file name carried
   an old identifier.
3. **String replacement (step 3).** A PHP script in the session scratchpad applied rows 1-17 in order (literal, rows
   16-17 as regular expressions) to every `*.php *.js *.mjs *.css *.json *.txt *.xml *.sh *.dist` file: 289 files
   changed (row 3: 2,277 replacements, row 14: 1,129, row 15: 1,942, row 17: 185, …). Row 18 ("Link Growth" menu,
   title and digest strings) was done by hand: `Admin/Menu.php`, `assets/js/app.js`, `Admin/Notices.php`,
   `Monitoring/Notifier.php`.
4. **Manual edits (step 4).**
   - Header per §1.1 (neutral author, no URIs, `Tested up to: 7.1`, `WC requires at least: 8.0`, `WC tested up to:
     11.3`), `Version: 1.0.0`, `LGA_VERSION` 1.0.0, `LGA_DB_VERSION` 0.9.0; composer package
     `linkgrowth/linkgrowth-autopilot`; `readme.txt` rewritten with a changelog that starts at 1.0.0 (§1.6).
   - `Plugin::NAME = 'LinkGrowth Autopilot'`; every literal product name in shipped code now uses it (mail
     subjects and bodies, the backup header, the activation error, notices, the digest footer; the SPA receives it as
     `lga-config.productName`).
   - Class renames of §1.1 with all references, including the job classes and the test fake job.
   - The removed search provider is gone from `ProviderRegistry`, `Settings`, `SettingsController` (limits, test
     button, secret map, primary list), `Secrets`, `Notices`, `OverviewController`, `GrowthRun` (the "add a key"
     action now names Google Programmable Search or Brave Search) and `settings.js`.
   - `phpunit.xml.dist` source directory `includes`; `bin/lint.sh` and `bin/test.sh` work on `.`; `bin/lint.sh` also
     checks `data/**/*.json` and runs `bin/neutrality.sh`; `bin/build-zip.sh` stages the step-6 allowlist (see
     deviations).
   - `tests/bootstrap.php`: `LGA_*` constants pointing at this folder, `LGA_DB_VERSION` 0.9.0, test home
     `https://northwind.test`, demo blog name and e-mail addresses.
   - `setAccessible()` calls dropped in `tests/Support/Container.php`, `tests/Unit/Seo/RenderCheckReviewFixesTest.php`
     (2) and `tests/integration/wiring-audit.php`.
5. **§1.5 removals of WP1.**
   - `Seo\PostLanguage` reads no foreign meta key any more. The owner can name one in the new setting
     `seo.language_counterpart_meta_key` (default `''`, letters, digits and underscores, at most 64; validated in
     `SettingsController`, field in Settings → SEO); `InternalLinker` passes it to `PostLanguage`.
   - No AI model ships (hard rule, §1.7 D): `providers.ai.model` defaults to `''`, `AiWriter::model()` returns `''`
     without a plain owner value, `AiWriter::isEnabled()` needs the key, the switch and a model, the settings test
     says "No AI model is set" instead of calling the API, the form may clear the model, and the provider label is
     "Anthropic API (optional writer)" (secret constant `LGA_SECRET_ANTHROPIC_API_KEY`).
6. **Tests (step 5).** Every test ported to the new names; store data replaced by the demo businesses of §12.2 in
   tests, fixtures and harnesses; new support classes `tests/Support/Profiles.php` (Northwind Supply Co. and
   Fjordline Lab Supplies), `tests/Support/DiscoveryData.php` (neutral query templates and seeds through the plugin's
   filters) and `tests/Support/NeutralCatalog.php` (a synthetic 250-item catalog in the importer's format). One test
   was deleted with a successor; see `tests/PORTING.md` for every deletion, rename, retired assertion and port.
7. **§1.6.** `ReleaseWiringTest::testHeaderVersionConstantStableTagAndChangelogAgree`: header Version,
   `LGA_VERSION`, Stable tag and the first changelog entry agree whatever the value; the §1.1 header lines; no Plugin
   or Author URI; `Plugin::NAME`; the schema version never runs ahead of the release.
8. **Neutrality scan.** `bin/neutrality.sh` (patterns A-D of §1.7, case-insensitive `grep -rniEI`; every term written
   with a character class so the script never matches itself), modes: working copy (default), `--zip FILE` (layout:
   one top folder `linkgrowth-autopilot/`, no `tests/ docs/ bin/ dist/ node_modules/ .phpunit.cache/`; A-D with no
   A exception), `--source FILE` (top folder `linkgrowth-autopilot-source/`; A, B and D with no exception and no
   pending list), `--only=`, `--root`, `--strict`. A exceptions: exactly `docs/DESIGN.md` and
   `tests/integration/coexistence.php`; C allowlist: exactly the §1.7 C list.
9. **NeutralityTest** (12 tests): A, B and D through the script and again in PHP with the script's own patterns;
   planted hits (A, B, D) and the two A exceptions; the ZIP and source-ZIP modes on synthetic archives; the pending
   list (existing files, owners, B rows only for shipped files, exact counts); §1.7 E: empty business profile, empty
   brand terms, empty AI model, autopilot off, and no file in `data/` outside the §1.2 layout. Skipped with the
   reason because the design assigns them to WP4a: "no default enables outbound acquisition" (the module flags are
   keys of the WP4a schema; the engine's go-live defaults `automation.submissions` and `auto_outreach_drafts` become
   neutral in WP4a, §2.4) and "data/ holds exactly the §1.2 files" (packs, schemas and catalog are WP4a/WP9). The
   real ZIP structure check (§1.7 E, last item) runs in part 2 when `bin/build-zip.sh` writes both ZIPs.

## Part 1: deviations, with reasons

1. **`rsync` is not installed.** Step 1 used `cp -a` and `tar --exclude='./fixtures/sites/*/listing'` with the same
   sources and exclusion.
2. **Escaped prefixes the replacement rows do not reach.** Rows 14 and 17 need `_` or a word boundary after the old
   prefix, so SQL `LIKE` patterns with escaped underscores in `uninstall.php` (transient cleanup) and
   `tests/integration/run.php`, the basic-auth example user in `settings.js` and the robots.txt agent token
   (`RobotsTxt::BOT_TOKEN`, now `linkgrowthautopilotbot`, the lower-case form of the user agent) were renamed by
   hand. Without the first fix, uninstall would have left every `lga_` transient behind.
3. **Pattern B uses `\bInterac\b`.** The literal term of §1.7 also matches "interactive" (29 such lines in code and
   tests, for example `AiWriter::INTERACTIVE_TIMEOUT`), which would make B impossible to satisfy; the word boundary
   keeps the term's meaning (the payment brand).
4. **Pending list for B and C** (`bin/neutrality-pending.txt`, 89 rows). The design leaves store content in shipped
   files after WP1: step 1 copies the engine taxonomy "to be rewritten in WP4a", and §2.4 assigns the content engine,
   outreach templates and personalizer to WP7/WP8 and the market words to WP3-WP9. A plain scan would therefore fail
   lint in WP1. The list is a ratchet, not an exception list: every row names the package that removes it, a listed
   file may not gain hits (NeutralityTest also requires the exact count, so a package that removes hits must lower
   the row), unlisted files must be clean, tests may never have a B hit, `--strict` (release gate WP31) ignores the
   list and `--source` never reads it. State: B 56 lines in 5 shipped files (ContentGenerator, FactsRegistry,
   LinkableAssets → WP7; Templates, Personalizer → WP8); C 317 lines in 84 files (WP1 part 2 for fixtures and
   integration sections: 14 files; WP3: 3; WP4a: 9; WP4b: 4; WP6: 21; WP7: 12; WP8: 16; WP9: 5).
   **Consequence for part 2:** the WP1 acceptance item "`bin/neutrality.sh --source` passes on the source ZIP" could
   not pass while the five B rows existed, because the source check has no pending list by design. Part 2 took the
   first option: the content engine and outreach templates were neutralized, so the B rows are gone (see Part 2).
5. **Shipped neutralization done now (beyond step 4), because the tests could only be neutral with it:**
   `Settings::defaults()` ships an empty business profile (brand, legal name, tagline, e-mail, address, city, region,
   postal code, country, founded year, social, categories) and empty `discovery.brand_terms`; brand fallbacks use the
   new `Settings::brandName()` (site.brand_name, else the WordPress site title) in `Menu`, `SettingsController`,
   `ContentGenerator`, `QueryGenerator`, `Personalizer` and the SPA; `ContentGenerator` no longer falls back to the
   store's city, region and year; the store's domains are out of `excluded-domains.json`, the store's product terms
   out of `keywords.json` and the anchor stop list, a store payment domain out of `BacklinkImporter`, the claims
   superlative rule reads "best product(s)" instead of the store's product noun (PHP and `outreach.js`),
   `PackageBuilder` takes directory keywords from the site's categories only and its editorial ask makes no claim,
   and comments, placeholders and one job summary that named the store were rewritten. These are steps in the
   direction of WP4a/WP6/WP7/WP8; those packages still own the full rewrite.
6. **Job types and settings keys are not renamed yet.** Only class names change in §1.1; the job type strings
   (`storefront_audit`, `import_verified`), `compliance.storefront_gate`, `automation.submissions` and the other
   engine keys stay until WP2 (task registry) and WP4a (settings schema) rename or remove them.
7. **What the fork does without the files step 1 leaves out.** `QueryGenerator` has no built-in templates (only the
   owner's custom queries run), `PackSeeds` imports no built-in seeds and `CatalogImporter` finds no catalog
   (`needsImport()` is false). Brand-mention scans therefore have no query templates either. Packs (WP4a/WP6) and the
   catalog (WP9) bring them back; the tests install neutral data through the existing filters instead.
8. **Language rule detail.** A counterpart without a language prefix is now "not the site language" instead of "not
   English", which is the same on an English site and correct on any other.
9. **`bin/build-zip.sh`.** The old script zipped a folder that no longer exists, so step 6's allowlist build was
   written now: staging in a temporary folder, lint, ZIP, `neutrality.sh --zip`. It was run once with
   `OUT_DIR` in the scratchpad (792 KB, one top folder, the seven allowlisted paths, "Neutrality OK (zip)") and the
   probe ZIP was deleted; nothing was written to `dist/`. The source ZIP (step 7) is part 2.
10. **`.gitignore`.** This folder now ignores `/dist/`, `/.phpunit.cache/`, `/bin/phpunit.phar`, `/vendor/` and
    `/node_modules/`, as the repository root does for its own copies, so a later commit does not pick up build
    output or the 5.7 MB PHPUnit archive.

## Part 1: evidence

All commands from this folder, PHP 8.3.6, with `nice -n 10`.

| Check | Command | Result |
|---|---|---|
| Lint | `bash bin/lint.sh` | `Lint OK` (exit 0): PHP syntax of every PHP file, `node --check` of every module in `assets/js`, JSON of `data/**`, and the neutrality scan |
| Unit suite | `bash bin/test.sh` | `OK, but some tests were skipped! Tests: 747, Assertions: 23165, Skipped: 2` (exit 0; 0 failures, 0 errors, 0 warnings, 0 deprecations) |
| Pattern A | `bash bin/neutrality.sh --only=A`, and the same pattern with `grep -rniEI` over the whole folder minus `docs/DESIGN.md` | 0 hits (the design document itself has 91, as expected) |
| Pattern B | `bash bin/neutrality.sh --only=B` | tests, fixtures, `bin/` and configs: 0 hits; shipped files: 56 lines in the 5 pending files listed above |
| Pattern C | `bash bin/neutrality.sh --only=C` | 317 lines, all in pending rows |
| Pattern D | `bash bin/neutrality.sh --only=D`, and the same pattern over the whole folder, docs included | 0 hits |
| Probe ZIP | `OUT_DIR=<scratchpad> bash bin/build-zip.sh` | built, "Neutrality OK (zip)", deleted afterwards |
| Engine untouched | `git status --porcelain`, `git diff --stat` at the repository root | only this folder is new; no tracked file changed |

Not run in part 1: the integration suite (`tests/integration/run.php` needs the LGA site of §12.1, part 2; its files
were ported and pass `php -l`), the coexistence section, WP-CLI and the source ZIP. The browser harness tests of the
unit suite (`*Harness.mjs`, Playwright with Chromium) ran inside `bin/test.sh`: none of them was skipped.

## Part 2: what was built

1. **LGA test site (§12.1).** `S/wp-test-lga/wordpress`, built by the new `tests/integration/build-lga-site.sh`
   (idempotent): WordPress 7.1.4 core, WooCommerce 11.3.0 (bundled Action Scheduler), SQLite Database Integration
   3.1.2 with its drop-in and the twentytwentyfive theme, copied from the engine's existing test site (read-only use;
   its database, mu-plugins, uploads and other plugins were not copied), so the LGA site has its own SQLite database.
   New `wp-config.php`: `http://localhost:8793`, fresh salts, `DB_ENGINE` sqlite, `WP_DEBUG` and `SAVEQUERIES` on,
   `DISABLE_WP_CRON`, environment type `local`. Installed with WP-CLI `core install`; WooCommerce and twentytwentyfive
   active; this folder symlinked to `wp-content/plugins/linkgrowth-autopilot`. The engine's test site was not touched.
2. **Fixture router on 8794.** `tests/fixtures/router.php` (ported in part 1) documents port 8794; it ran as
   `LGA_FIXTURE_LOG=S/lga-run/fixture.log nice -n 10 php -S 127.0.0.1:8794 tests/fixtures/router.php` and was stopped
   after the runs. The suite starts and stops the site's own server on 8793 itself when a section needs real HTTP
   (render check, IndexNow key file).
3. **Test mu-plugin** `tests/integration/lga-test-env.php` (copied to the site's `wp-content/mu-plugins/` by the build
   script; it replaces the mu-plugin example): loopback hosts allowed; `*.test` routed to the fixture server
   (`LGA_FIXTURE_BASE`, default `http://127.0.0.1:8794`); the `pre_http_request` **host logger** at the earliest
   priority for WP4b's `activation-silence` (`lga_test_http_requests()`, `lga_test_http_hosts()`,
   `lga_test_http_reset()`, and `LGA_HTTP_HOST_LOG` for requests made by other processes; the internal fixture hop is
   not counted); the frozen clock `lga_test_time()` / filter `lga_test_time` (`$GLOBALS['lga_test_time']` or
   `LGA_TEST_TIME`); `LGA_TEST_KNOWN_CONFLICTS` for the `lga_known_conflicts` filter. Nothing in it names a product.
4. **Integration suite** (`tests/integration/run.php` and its sections), ported to `LGA_WP_PATH`, `LGA_FIXTURE_LOG`,
   `LGA_IT_ONLY`, `LGA_RESET` and the host `localhost:8793`, and green. All 479 engine checks were kept, none deleted
   (no section tested only the engine's data); the details per file are in `tests/PORTING.md` ("Integration suite").
   In short: the setup step installs the Northwind demo business, its topics, neutral query templates and the
   synthetic 250-item catalog (the fork bundles none of these); the fixture sites are a neutral United States world
   (country `US`, state `OR`, a new neutral listing page); the storefront fixtures use consumer-use wording without
   store product names; catalog counts follow `NeutralCatalog::TOTAL`. New: a step for the test mu-plugin (8 checks).
5. **Coexistence section** `tests/integration/coexistence.php` (§1.4; run by `run.php` as its last section, three
   fresh processes so that each plugin loads with WordPress): the engine folder is symlinked into the site for the
   section only and used read-only; both plugins boot (helpers, constants, own tick hooks, both REST namespaces, own
   tables and capabilities); an LGA tick reads 0 and writes 0 of the engine's options (counted with the `pre_option`
   filter, also attributed by caller); deactivating either plugin leaves the other's WP-Cron event and Action
   Scheduler action exactly as they were, and re-activating restores its own; the owner-listed engine
   (`LGA_TEST_KNOWN_CONFLICTS` through the test mu-plugin) gives the §1.4 warning on the dashboard and in Site Health;
   unlisted, nothing is reported; a foreign `lga_` table is reported; no outside host was contacted. Cleanup removes
   every table, option, transient, schedule, role and capability the engine's activation wrote, and the symlink; the
   engine's folder is unchanged (`git status` shows nothing outside this folder).
6. **`Core\Environment`** (new, `includes/Core/Environment.php`), generic as §1.4 requires: active plugins listed in
   `lga_known_conflicts` (empty by default, `[basename => label]`, never this plugin), another plugin's callback on
   `lga_tick`, and in the full check (Site Health only) foreign routes in `lga/v1` and foreign `lga_` tables. Warning
   text exactly as §1.4. Shown as a dismissible warning notice on the plugin's screens (`Admin\Notices`) and as the
   Site Health direct test `lga_environment` (status "recommended": nothing is blocked). Unit test
   `tests/Unit/Core/EnvironmentTest.php` (4). IndexNow conflict detection is WP6 and was not built.
7. **Store data out of the content engine and outreach templates** (needed for `--source`, see part 1 deviation 4):
   - `Content\FactsRegistry::defaults()` returns no facts. Until the owner saves a fact list, the facts are the ones
     the business profile states (what it offers, location, founding year, e-mail, public profiles).
   - `Content\LinkableAssets::defaults()` is the home page only, described from the profile; other pages come from
     the owner's `content.linkable_assets`.
   - `Content\ContentGenerator` writes openings, short descriptions, taglines, backgrounds and supplier profiles from
     the profile, and quality, commerce and audience sentences from the owner's verified facts as written. No sentence
     states a testing partner, carrier, payment method or product line the owner did not.
   - `Outreach\Templates` and `Outreach\Personalizer` use the new `{{brand_intro}}` and `{{offer}}` variables instead
     of the store's self-description and the claims that went with it.
   - Unit tests that relied on the store's facts and pages install the demo businesses' own facts and pages
     (`tests/Support/Facts.php`); one assertion was retired with a named successor (WP7 `FactsProvenanceTest`). See
     `tests/PORTING.md`.
8. **Market words out of the test harness.** The pending rows owned by "WP1-part2" are gone: the fixture sites,
   integration sections and `tests/bootstrap.php` (default time zone America/Los_Angeles, the Northwind demo
   business) have no pattern C hit. Pending list now: no B row; C 214 lines in 65 files (was 317 in 84), each row
   lowered to its exact count, none raised.
9. **`bin/build-zip.sh` (§1.3 steps 6-7)** writes both archives into `dist/` (git-ignored) after `bin/lint.sh`:
   `linkgrowth-autopilot-1.0.0.zip` (top folder `linkgrowth-autopilot/`, the shipped allowlist only, checked with
   `neutrality.sh --zip`) and `linkgrowth-autopilot-1.0.0-source.zip` (top folder `linkgrowth-autopilot-source/`,
   the allowlist plus `tests/ bin/ composer.json phpunit.xml.dist` and `docs/` when it has content; without
   `docs/DESIGN.md`, `docs/notes/`, `tests/PORTING.md`, `tests/integration/coexistence.php`, `tests/e2e/shots/`,
   `dist/`, `.phpunit.cache/`, `node_modules/`, `bin/phpunit.phar` and `.git*`; the build refuses staged files that
   contain a temporary path; checked with `neutrality.sh --source`, A, B and D with no exception).
10. **WP-CLI (§12.7).** `S/tools/wpcli/wp-cli.phar` downloaded from the URL in §12.7 with its published `.sha512`;
    `sha512sum -c` gives OK (`be928f6b8ca1e8df…a30fe8832`), WP-CLI 2.12.0. Used for `core install` and the smoke run.

## Part 2: deviations, with reasons

1. **Content neutralization ahead of WP7/WP8.** The design leaves the content engine and templates to WP7/WP8, but
   the part-2 acceptance requires `--source` to pass with no exception, so the store data (pattern B) had to go now.
   The rewrite is the smallest neutral one: the positioning machinery is unchanged (pattern C, pending for WP7), so
   every generated description still ends with the research-use statement (the engine's fallback) even for a
   business without positioning; WP7 makes it pack-driven. Profile-derived facts count as verified because the owner
   typed them; WP7's provenance rule (§7.4) will record them with origin `wizard`.
2. **Fresh-site handling in `run.php`.** On a site where the plugin was never active, its boot hooks cannot run in
   the process that activates it. The suite now activates it and runs itself again in a new process; the reset step
   loads the plugin's autoloader first. The engine's suite only ever ran on a site where its plugin was active.
3. **Fixture world.** The engine's fixture sites described its home market; they were rewritten as a neutral United
   States world matching the Northwind demo business instead of only deleting the market words, because the
   submission form must accept the business's own region. The listing page left out by §1.3 step 1 has a neutral
   successor.
4. **Frozen clock.** The mu-plugin provides `lga_test_time()` and the `lga_test_time` filter, but the plugin still
   calls `time()` directly, so nothing reads it yet (WP2's clock seam and golden test are its first user).
5. **Coexistence as subprocesses.** §1.4 calls it a section of the LGA site run; it runs as three processes started
   by `run.php` (each plugin must load with WordPress) and is skipped when the file is absent (the source ZIP). If the
   engine folder is missing it reports "not run" and the run counts that as a failure, never as a pass.
6. **Site Health id.** The environment check is `lga_environment` (§1.4 calls it the "environment" check; the §1.1
   id list does not name it). The REST namespace and table detectors run only in Site Health (they load every route
   and list tables), the cheap ones also on the plugin's screens.
7. **Source ZIP contents.** `bin/phpunit.phar` (a downloaded tool, git-ignored; `bin/test.sh` says where to get it)
   and `.git*` files are left out; `docs/` is left out while it is empty (WP29 fills it).
8. **Observations, not changed (owners named):** `Submission\PackageBuilder::CATEGORY_TERMS` puts laboratory
   categories first, so the Northwind package still picks "Scientific Supplies & Reagents" over "Industrial
   Supplies" (the integration check keeps the engine's behaviour; WP8 should prefer the profile's categories).
   `Analysis\RelevanceScorer` still favours the engine's home market in its geo score (WP6); the US directory still
   qualifies. `Outreach\Compliance` maps only the old market's regions to time zones, so the send-window check uses a
   business in British Columbia (WP8).

## Part 2: evidence

All commands from this folder unless noted, PHP 8.3.6, with `nice -n 10`, one suite at a time, ports 8793 (LGA site)
and 8794 (fixture server) only. Final runs, one after another:

| Check | Command | Result |
|---|---|---|
| Lint | `bash bin/lint.sh` | `Lint OK` (exit 0); pattern A 0, B 0, C 214 lines all pending, D 0 |
| Unit suite | `bash bin/test.sh` | `OK, but some tests were skipped! Tests: 751, Assertions: 22974, Skipped: 2` (exit 0, 90 s); the 2 skips are the WP4a checks of part 1; 744 test methods (part 1: 740, plus `EnvironmentTest` 4) |
| Integration suite, incl. coexistence | `LGA_WP_PATH=S/wp-test-lga/wordpress LGA_FIXTURE_LOG=S/lga-run/fixture.log php tests/integration/run.php` | `Passed: 523  Failed: 0` (exit 0, 30 s): the 479 engine checks, 8 harness checks, and coexistence `Passed: 36  Failed: 0` (activate 2, check 31, cleanup 2, symlink removed 1) |
| Plugin ZIP | `TMPDIR=S bash bin/build-zip.sh`, `unzip -Z1`, extracted into `S` and scanned | `dist/linkgrowth-autopilot-1.0.0.zip`, 792 KB, 220 entries, one top folder `linkgrowth-autopilot/` with `assets data includes languages linkgrowth-autopilot.php readme.txt uninstall.php`; no `tests docs bin dist node_modules .phpunit.cache`; `Neutrality OK (zip)`; grep A, B, D on the extracted copy 0 lines (C 120, all pending) |
| Source ZIP | same build | `dist/linkgrowth-autopilot-1.0.0-source.zip`, 1.4 MB, 396 entries, one top folder `linkgrowth-autopilot-source/` with `assets bin composer.json data includes languages linkgrowth-autopilot.php phpunit.xml.dist readme.txt tests uninstall.php`; none of the excluded paths; `Neutrality OK (source)`; grep A, B, D on the extracted copy 0 lines; in the extracted copy `bash bin/lint.sh` gives `Lint OK` and the unit suite (with this folder's `bin/phpunit.phar`) 751 tests OK; extracted copies deleted afterwards |
| WP-CLI | `php S/tools/wpcli/wp-cli.phar --allow-root --path=S/wp-test-lga/wordpress plugin list` | exit 0: `linkgrowth-autopilot active 1.0.0`, `woocommerce active 11.3.0`, `sqlite-database-integration` (drop-in in use), `lga-test-env` must-use, `db.php` drop-in; `core version` 7.1.4 |
| PHP notices on the LGA site | `wp-content/debug.log` | no PHP warning, notice or deprecation from plugin code. All 78 lines date from the install and the first failing runs (11:05-11:09 UTC) before the harness fixes: test-file warnings, WooCommerce's early-translation notice during `core install`, and four plugin log lines "Verified opportunity database unreadable" from the run before the neutral catalog was installed. The final runs added no line |
| Engine untouched | `git status --porcelain` at the repository root | only `linkgrowth-autopilot/` is new; no tracked file changed |

## Review fixes (after part 2)

Four confirmed review findings, fixed with minimal changes. Each has a regression check that failed on the tree
as part 2 left it and passes now (outputs kept in `S/wp1fix/`; the tree as part 2 left it was snapshotted there
before the first change).

1. **Store catalog in unit fixtures (neutrality, medium).** The storefront fixtures of the compliance tests
   (`tests/Unit/Compliance/StorefrontFixtures.php`) still held the store's own product category set (names and
   slugs), a product tag and compound product names; `ComplianceBannerHarness.mjs` repeated the category labels;
   `tests/Unit/Seo/LinksListTest.php` and `tests/Unit/Growth/AutopilotUiHarness.mjs` used one of the store's blog
   posts as the internal-link suggestion; `tests/Unit/Content/DiscoveryContentFixesTest.php` named a store product in
   two claims. All of it shipped in the source ZIP, and pattern B (brand, address, a few product words) could not see
   it. A comparison of every file in the fork with the store's page and product slugs from the engine's own audit
   data (read in place under the repository's `ops/`, nothing copied; the slug list stayed in the scratchpad and was
   deleted after the last comparison) found one more: the clean linkable asset of
   `tests/Unit/CrossSlice/SettingsAutomationFollowupsTest.php` was a store guide page.
   - **Fix.** The fixtures were ported with neutral data, keeping every assertion on behaviour: the consumer-use
     storefront has the same nine category terms, the same tag and product terms and the same item counts (18 and 11
     items checked, 13 blocking and 4 warning findings, the same finding keys), with names such as "Metabolic & Weight
     Loss Kits", "Tanning Lotions", "Dispensing Syringes 30G", "Calibration Kit 5 g" and "Citrus Facial Serum"; the
     research-only storefront is a laboratory glassware shop. The assertions that quote a label now quote the new
     label (`PositioningGateTest` lines 82 and 96, the banner harness's "no longer shows the old finding" check). The
     link suggestion is a Northwind guide whose texts are 603 bytes per item against 588 before, so the "a page of 50
     is under 60 KB" check is not loosened. The integration storefront category was renamed from a name that began
     with the store's category name to "Metabolic & Weight Loss Kits" (same findings). The clean asset is a box
     strength testing guide; its file lost 3 pattern C lines, so its pending row was lowered from 4 to 1 (C now 211
     lines, was 214).
   - **Regression check.** Pattern B in `bin/neutrality.sh` also lists the store's catalog: its compound product names
     (with or without hyphen), one of them with a dose or the market word, and its four distinctive category names in
     name and slug form. NeutralityTest plants a catalog slug in a test file and expects the scan to report it. Before
     the port: `bash bin/neutrality.sh --only=B` gave "store data outside the shipped files (never allowed)" with 22
     lines in 8 test files (`StorefrontFixtures.php` 9, `ComplianceBannerHarness.mjs` 3, `PositioningGateTest.php` 2,
     `LinksListTest.php` 2, `DiscoveryContentFixesTest.php` 2, `growth-fixtures.php` 2, `AutopilotUiHarness.mjs` 1,
     `growth.php` 1), "Neutrality FAILED (repo)"; `--source` on the source ZIP built at 11:42 failed the same way (the
     plugin ZIP passed); `testPlantedHitsAreFoundAndOnlyTheListedExceptionsApply` failed. After: B 0 hits, the test
     passes, and both rebuilt ZIPs pass.
2. **PORTING.md successor for the retired docs/TESTING.md assertions (parity, low).** The row of
   `ReleaseWiringTest::testServerPressureServicesHooksAndDocsAreWired` retired the engine's `docs/TESTING.md`
   needles without naming a successor. **Fix:** the replacement now names the WP29 `DocsWiringTest` check that
   `docs/TESTING.md` (a WP29 deliverable, §13.2) lists `tests/Unit/Automation/LoadGuardTest.php`,
   `tests/Unit/Automation/ServerLoadTest.php` and `tests/integration/server-pressure.php`, and says where the readme
   changelog needles went (§1.6 test). A stray blank line that split the retired-assertions table in two was removed.
   **Regression check:** new `tests/Unit/Wiring/PortingRecordTest` (every engine document a retired-assertions row
   names must appear again in its replacement; skipped in the source ZIP, which leaves PORTING.md out). Before: failed
   with "the retired docs/TESTING.md assertions need a named successor"; after: passes.
3. **Nested dist/ folders were never scanned (harness, medium).** `hits()` used `grep --exclude-dir=dist`, which
   skips a folder of that name at any depth, in every mode, while the build ships everything under `assets/`,
   `includes/` and `data/`. NeutralityTest's PHP twin had the same blind spot. **Fix:** the working-copy scan drops
   only hit paths that start with `dist/` (the git-ignored build output, which `--zip` and `--source` check); `--zip`
   and `--source` exclude no `dist/` at all; the PHP twin skips `^dist/` only. **Regression check:**
   `NeutralityTest::testNestedDistFoldersAreScannedAndOnlyTheTopLevelBuildOutputIsSkipped` plants
   `assets/js/dist/probe.js` with an old identifier (repo mode, PHP twin, `--zip`) and with a store word
   (`--source`), and a top-level `dist/` file that must stay ignored. Before: "Neutrality OK (repo)" (failed); after:
   every mode reports the probe and the top-level file is ignored.
4. **Rejected archive left under the release name; prefix-matched exceptions (harness, low).**
   - `bin/build-zip.sh` wrote each ZIP straight into `dist/` and checked it afterwards. **Fix:** both archives are
     written and checked inside the staging folder and moved into the output folder only after both checks pass; the
     previous build's archives are still removed first, so a failed build leaves neither release name behind.
     **Regression check:** `NeutralityTest::testAFailedBuildLeavesNoArchiveUnderTheReleaseNames` runs the real
     `build-zip.sh` and `neutrality.sh` on a minimal tree (lint stubbed): a clean build writes both ZIPs; with store
     data in a test file the plugin ZIP passes, the source ZIP fails, the build exits non-zero and the output and
     staging folders are empty. Before: failed ("a failed build publishes no plugin ZIP": both ZIPs were there);
     after: passes.
   - `except()` matched each exception as a path prefix, so `docs/DESIGN.md.bak`, `coexistence.php.orig` and
     `Jurisdictions.php.bak` were exempt. **Fix:** an entry matches its exact path; only an entry ending in `/` is a
     folder (`tests/Unit/Profile/`); the PHP twin follows the same rule. **Regression check:**
     `NeutralityTest::testExceptionsMatchWholePathsOnly` (repo mode, the plugin ZIP's C allowlist, the PHP twin).
     Before: "Neutrality OK (repo)" (failed); after: the three copies are reported and the real exceptions still apply.

NeutralityTest has 15 tests now (was 12), plus `PortingRecordTest` (1): 748 test methods, 755 tests with data sets.

### Deviations, with reasons

1. **Pattern B is wider than the list in §1.7 B.** The design's list names the brand, the address and a few product
   words but no product, so it cannot keep the store's catalog out. The added terms are the catalog forms the fixtures
   carried, written with character classes like the rest of the script. The bare forms of two compound names are not
   in the pattern (see the observation below).
2. **Integration fixture renamed.** `tests/integration/growth.php` and `growth-fixtures.php` were not part of the
   finding, but their category name began with the store's category name, which the wider pattern B reports; the
   rename changes no finding or check.
3. **Ported without a named successor.** No test was deleted and no assertion retired: every change replaces data,
   and each label assertion follows its fixture.

### Observations, not changed (owners named)

- Two compound drug names appear in bare form in shipped filter lists, the domain health-term list of
  `Discovery\CompetitorBacklinkSource` and the spam list of `data/universal/keywords.json`. They reject health-niche
  prospects rather than describe a catalog; both lists are rewritten by their owners (§2.4: WP6 replaces the health
  terms with `pack.excluded_competitor_niches`; WP4a rewrites `keywords.json`).
- `tests/Unit/Growth/BrowserE2EFixesTest::node()` (inherited from the engine) creates its script file with
  `tempnam()` and deletes only the `.mjs` copy, so each unit run leaves four empty `lga-e2e*` files in the system
  temp folder. The four this session's run created were deleted; the test was not changed (outside the findings).

### Evidence (final runs, one after another, from this folder, `nice -n 10`)

| Check | Command | Result |
|---|---|---|
| Lint | `bash bin/lint.sh` | `Lint OK` (exit 0); pattern A 0, B 0, C 211 lines all pending, D 0 |
| Unit suite | `bash bin/test.sh` | `OK, but some tests were skipped! Tests: 755, Assertions: 23021, Skipped: 2` (exit 0, 92 s; the 2 skips are the WP4a checks of part 1) |
| Integration suite, incl. coexistence | fixture router on 8794, then `LGA_WP_PATH=S/wp-test-lga/wordpress LGA_FIXTURE_LOG=S/lga-run/fixture.log php tests/integration/run.php` | `Passed: 523  Failed: 0` (exit 0, 32 s); coexistence `Passed: 36  Failed: 0`; the router was stopped afterwards; the site's `debug.log` gained no line (78 lines, all from 11:05-11:09) |
| Build | `TMPDIR=S bash bin/build-zip.sh` | exit 0: `Lint OK`, `Neutrality OK (zip)` (C 120, all pending), `Neutrality OK (source)` |
| Plugin ZIP | `unzip -Z1`, extracted into `S` and scanned | `dist/linkgrowth-autopilot-1.0.0.zip`, 792 KB, 220 entries, one top folder with `assets data includes languages linkgrowth-autopilot.php readme.txt uninstall.php`; no `tests/ docs/ bin/ dist/ node_modules/`; grep A, B, D on the extracted copy 0 lines |
| Source ZIP | same | `dist/linkgrowth-autopilot-1.0.0-source.zip`, 1.4 MB, 397 entries (`PortingRecordTest.php` is new), one top folder `linkgrowth-autopilot-source/`; no `PORTING.md`, `coexistence.php`, `docs/`, `bin/phpunit.phar`, `dist/` or `e2e/shots`; grep A, B, D 0 lines; the store-slug comparison of item 1 finds only the two filter words above; in the extracted copy `NeutralityTest` passes (15 tests, 2 skipped) and `PortingRecordTest` is skipped with its reason; extracted copies deleted |
| Engine untouched | `git status --porcelain` at the repository root | only `linkgrowth-autopilot/` is new; no tracked file changed |
