# LGA: review observations deferred to later work packages

Reviewers raised these in WP1/WP2/WP12. Skeptics judged them not defects of that package, mostly because the design assigns the area to a later package. Each later package must handle the items listed for it, or say in its notes why not.

## WP4a (profile, packs, settings schema)
- Settings::brandName() fallback is not used in PackageBuilder::business() (package name and legal_name ''), ListingVerifier::check() (brand-search step skipped), GenericFormAdapter::listingUrl() or Notifier::brand(). WP4a's "Profile::brand() everywhere" must cover all four.
- Support/Http/Client.php sends a hard-coded `Accept-Language: en-CA` (computed UA and Accept-Language are WP4a).
- Test harness fixtures still carry the old store catalog's opaque ids, version string and import counts (SubmitterUiHarness.mjs:124 is WP4a; the Verified harnesses are WP9).
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

## Tooling / WP31
- The neutrality scan checks neither file names nor binary files (.mo etc.).
- BrowserE2EFixesTest::node() leaves an empty lga-e2e* file per call in /tmp.
- On the SQLite test sites the weekly backlink trend is always zero.
- On WP 6.5 the dashboard's relative imports load without the version map (no cache-busting after an update).
- The integration snapshot's cron and roles sections read the DB after activation with every module, so a module that schedules its own recurring event or adds a role changes them.
