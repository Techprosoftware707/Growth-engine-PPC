# LGA: review observations deferred to later work packages

Reviewers raised these in WP1/WP2/WP12/WP3. Skeptics judged them not defects of that package, mostly because the design assigns the area to a later package. Each later package must handle the items listed for it, or say in its notes why not.

## WP4a (profile, packs, settings schema)
- Settings::brandName() fallback is not used in PackageBuilder::business() (package name and legal_name ''), ListingVerifier::check() (brand-search step skipped), GenericFormAdapter::listingUrl() or Notifier::brand(). WP4a's "Profile::brand() everywhere" must cover all four.
- Support/Http/Client.php sends a hard-coded `Accept-Language: en-CA` (computed UA and Accept-Language are WP4a).
- Test harness fixtures still carry the old store catalog's opaque ids, version string and import counts (SubmitterUiHarness.mjs:124 is WP4a; the Verified harnesses are WP9).
- The pattern C rows of the dashboard pages WP3 touched (Overview 1, Prospects 1, Submissions 5) still need neutral replacement wording (WP3.md, part 1, deviation 6).
- The tests/fixtures/golden/wiring.json row `C 3 ... WP4a` (geo_modifiers market words) goes when the defaults become neutral; re-record the golden fixtures deliberately (LGA_GOLDEN_UPDATE=1) and document each difference.

## WP5 (schema, migrations)
- uninstall.php keeps its own list of the 14 tables; a module's table survives "Delete all data on uninstall". Uninstall must collect tables from the modules.
- The migrator does not notice a new module table on an existing install for up to 12 hours unless LGA_DB_VERSION is bumped.

## WP6 (discovery and analysis)
- QueryGenerator falls back to ['Canada'] when geo modifiers are cleared (lines ~42-44).
- The other 'CA' defaults: SiteProfiler, ProspectIntake, BraveSearch, RelevanceScorer geo score.
- Pattern C does not match a bare "Canada" or "en-CA". Add a market-phrase check to the WP6 tests.
- The filter lists in CompetitorBacklinkSource.php:69 and data/universal/keywords.json:54 hold bare compound and drug names (filter words, rewritten in WP6/WP4a).

## WP7 (content, facts)
- DiscoveryContentFixesTest::testLinkableAssetsAreClaimsClean lost assertions (38 to 29) because LinkableAssets defaults to the home page only. Install demo assets or restore real guide selection, and record it in PORTING.md.
- Integration run.php installs no content.linkable_assets, so broken-link opportunities and drafts point at the home page.
- The research-use positioning sentence is still appended to every description (pack-driven in WP7).

## WP8 (outreach)
- Personalizer::observation() writes "... in Canada" for every site (lines ~522-538). PackageBuilder.php:430-431 writes "relevant to laboratory research buyers in Canada". PackageBuilder::business() line ~361 turns an empty site.country into 'CA'.
- PackageBuilder::CATEGORY_TERMS makes Northwind pick "Scientific Supplies & Reagents".
- The send-window region-to-timezone map only covers the old market.
- Add a market-phrase check (bare country names) to the WP8 tests.

## WP9 (catalog)
- OwnerTaskBrowserHarness.mjs and OwnerTaskUiHarness.mjs carry the old store DB's item ids, version, counts and records.
- The comment at includes/Growth/GrowthRun.php:126 says "the 595 bundled items".
- The `import_verified` stage logs `Verified opportunity database unreadable` as an error on every run without a catalog. No catalog ships (§2.5), so an expected absence should not be logged as an error.

## WP10 (scheduler v2, Grow now)
- The golden test does not cover the Grow now / Run now path. WP10's GrowthRun rework must add a golden or behaviour test for it.
- A job type replaced through lga_job_types is enqueued with the built-in task's priority, not the replacement class's.
- Replacing a built-in task through lga_scheduled_tasks with a modified copy silently removes it (due_check_missing).
- An exception in an lga_scheduled_tasks callback aborts the whole tick, including the queue run; isolate it the way module tasks() is isolated.
- gate()/schedule() give wrong answers for a module task unless tasks() was called on the same registry instance first.
- The task key regex uses `$` instead of `\z` (a trailing newline is accepted).
- After the clock jumps backwards, every interval task stalls until the clock passes the old last run plus its interval (inherited).
- AutomationController::STAGE_LABELS is a central label list that ignores TaskDefinition::label (also WP28).

## WP26 (health, coexistence check)
- The coexistence isolation check runs on an idle tick (0 jobs), so it misses reads of the engine's options in jobs, at boot/init and through transients, and does not count deletes. Strengthen it with a tick that runs real work, counting from plugins_loaded, the _transient_/_site_transient_ prefixes and delete_option.
- The coexistence WP-Cron half is never exercised: the LGA site has Action Scheduler, so cron is false both before and after.
- Coexistence cleanup leaves the engine's canceled Action Scheduler rows and the engine's Action Scheduler group (2 rows per run).
- ServerResources::quietly() and Opcache::status() swallow every error inside them, which would also hide a deprecation.

## WP30 (browser E2E of every control)
- The plugin's configuration notices (`Admin\Notices`, printed through `admin_notices` above the dashboard, with an "Open Settings" link and WordPress's dismiss button) are outside `.lga-wrap` and carry no `data-lga-control`. The suite neither checks them nor fails on them.
- The suite's PHP error-log check does not catch a "WordPress database error" block whose backtrace names the plugin. The plugin path is never on the same line as "PHP Warning" etc. Flag such blocks.
- ManifestTest's "cover every page" check uses a fixed list of the current pages and tabs. A new page or tab with no manifest passes the unit suite and only fails the E2E run. Derive the list from `Menu::pageDefinitions()` and `Menu::settingsTabs()`.
- Page icons come from the fixed `ICONS` table in assets/js/ui.js. An unknown icon name renders a blank icon and nothing flags it. Check page icons against the table, or let a module supply its glyph.
- Timers longer than LINGER_MS (8 s) are not checked after an entry: the 9-15 s toast dismissals and the 15 s and 30 s auto-refresh loops, outside their own entries.
- Contrast: `.lga-muted` (`--lga-ink-3` #898781 on `--lga-surface` #fcfcfb) is 3.50:1 in the light theme, below WCAG AA (4.5:1) for 13 px text. This comes from the palette and was already the case at 333cb63: it covers every "—" placeholder and, since WP3, the "Manual" contact source label. The dark theme measures 4.85:1. Darken the token and check every muted use.

## Tooling / WP31
- The neutrality scan checks neither file names nor binary files (.mo etc.).
- BrowserE2EFixesTest::node() leaves an empty lga-e2e* file per call in /tmp.
- On the SQLite test sites the weekly backlink trend is always zero.
- On WP 6.5 the dashboard's relative imports load without the version map (no cache-busting after an update).
- The integration snapshot's cron and roles sections read the DB after activation with every module, so a module that schedules its own recurring event or adds a role changes them.
