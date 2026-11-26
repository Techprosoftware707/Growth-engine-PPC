# WP3 — SPA seams, control convention and the browser E2E suite

WP3 has two parts, both uncommitted in the working tree: **part 1** (the sections under "Part 1" below) builds the
seams the dashboard's controls hang on, **part 2** (the sections under "Part 2") the browser E2E suite of DESIGN.md
§12.5 that exercises every one of them; "Review fixes", at the end, records four defects a review found in part 2's
harness and seam, how they were fixed, and the final evidence (it supersedes part 2's evidence table). §13.2 WP3's acceptance: every existing page and tab works as before
(part 1, the existing browser harnesses green), `tests/e2e/run.mjs` passes on the existing pages with complete
manifests (part 2), no horizontal scroll at 390 px (both).

## Part 1: SPA seams and control ids

Scope of part 1 (DESIGN.md §3.1, §10.1, §13.2 WP3; the owner's spec §10 "every control must work; no decorative
buttons"): the router and the navigation built from `lga-config.pages`; the Settings page split into tab modules
registered through `settingsTabs`; `ui.js` control helpers that set `data-lga-control="<page>.<control-id>"` on every
interactive element they create, with every existing control of every page and tab converted to them; the
pre-existing problems part 0 found; and no other visible change, checked with part 0's capture of the dashboard.
Part 2 builds the E2E harness (`tests/e2e/run.mjs`, the control manifests of §12.5) on top of these ids.

Part 0 (no plugin code changed) recorded the baseline on the untouched tree 333cb63: 48 views (45 as administrator, 3
as submitter), each at 1280 and 390 px in light and dark (192 screenshots), with a control inventory, the REST
requests, console messages and a horizontal-scroll check per load. Its tooling is in the scratchpad
(`S/wp3-baseline/`: `capture.mjs`, `compare.mjs`, `analyze.py`, the seed database); `S` below is the session
scratchpad of DESIGN.md §12.1.

### What was built

#### 1. Router and navigation from `lga-config.pages`

- **Server** (`includes/Admin/Menu.php`): `Menu::spaPages( $submitOnly )` gives the definitions of the pages the user
  may open (`pagesFor()`, so a page whose capability the user lacks is left out), in navigation order, as
  `key => {label, icon, module, cap, order}` straight from the modules' `adminPages()` (and `lga_admin_pages`).
  `config()` prints them as `pages`; `pageUrls` and `menuSlugs` follow the same keys. The submitter role gets
  `submissions` only, labelled "Owner tasks" with the capability `lga_submit_listings`. `settingsTabs` (owner only) is
  unchanged in shape.
- **Client** (`assets/js/app.js`): the hard-coded `PAGES`, `PAGE_ICONS` and `DEFAULT_PAGES` are gone.
  - `definitionsFrom()` reads `lga-config.pages` (and `settingsTabs`) into definitions sorted by `order`, leaving out
    keys that are not `[a-z0-9_-]+` and entries without a label; a bare string is read as the label of a page whose
    module is `pages/<key>.js`.
  - `routeFor()` routes `#lga-app[data-page]` to itself when the user has it, else to the Overview, else to the user's
    first page; with no page at all the app says so instead of rendering a blank screen.
  - The navigation lists exactly the pages of `lga-config`, with their icon and label; `ctx.pageUrl()` and
    `ctx.navigate()` send a page the user does not have to the Overview (without the other page's parameters).
  - `moduleUrl()` resolves a module path against `app.js`: a path relative to `assets/js/` (`pages/overview.js`,
    `settings/business.js`; no `..`, no query), or an absolute http(s) URL of a `.js` file on the site's own origin
    (another plugin's page). Anything else is refused and the page shows "This page could not be loaded" with the
    reason; nothing is requested. `ctx.loadModule()` loads such a path for the page modules (Settings uses it for its
    tabs).
  - The submitter rule stays in the client as well (only `submissions`, whatever the bootstrap lists).
- **Import map** (`includes/Admin/Assets.php`): `MODULE_GLOBS` adds `assets/js/settings/*.js`, so the tab modules are
  versioned like the pages (`AdminRest2FixesTest` now also checks every module path of `Menu::pageDefinitions()` and
  `Menu::settingsTabs()` against the map).

#### 2. Settings tabs as modules (`assets/js/settings/`)

- The eight tabs of the engine's `pages/settings.js` are eight modules: `business.js`, `automation.js`,
  `discovery.js`, `integrations.js`, `outreach.js`, `content.js`, `monitoring.js`, `advanced.js`. Each module's code is
  the former `xxxTab()` function moved without change (constants such as the seed types, opportunity types, asset
  kinds and send days moved with the tab that uses them; the secret field and the test button live in the
  Integrations tab). Each exports `default function render( tab )` and returns its content.
- The modules' `settingsTabs()` now point at them (`'module' => 'settings/<tab>.js'`, same labels and order).
- `pages/settings.js` keeps the page: the shared state, `load()`, `save()` (the same leaf diff, confirmations and
  `POST /settings` body as before), `bind()`, `grid()`, the row editor, the save bar and the exported helpers the tests
  import. It renders the tabs of `ctx.config.settingsTabs` in their order, loads their modules (in parallel with
  `GET /settings`, through `ctx.loadModule()`), and hands each tab
  `{ ctx, state, bind, grid, markDirty, load, rowEditor, providerBadge, sendZoneText }`. A `#tab=` that is not in
  `settingsTabs` opens the first tab. A tab whose module fails to load shows the error in its own panel with a
  "Try again" button that reloads the page (the browser keeps a failed module failed for the page view); the other
  tabs keep working.
- Server side nothing changed: the same `GET /settings`, the same `POST /settings` with the same payload, validated by
  `SettingsSchema` as in WP2.

#### 3. Control ids: helpers and convention (`assets/js/ui.js`)

- **Convention.** Every interactive element carries `data-lga-control="<page>.<control-id>"`:
  - `<page>` is the page key app.js renders (`setControlScope()`); the header, the section navigation, the
    notifications drawer and the load-failure button use the scope `shell` (they are on every page);
  - `<control-id>` is kebab-case and names one control of the page; a control repeated once per row of a list (the
    "Open" row of a table, a row's "Remove" button, the "Configure" link of each integration) keeps one id on every
    row;
  - ids are stable: they come from code (a settings path, a column key, a tab id, a fixed name), never from data such
    as a URL or a record id.
- **Helpers.** `setControlScope()`, `getControlScope()`, `kebab()`, `controlId( ...parts )` (keeps a scope the first
  part names), `controlName()`, `setControl( node, id )`; new element helpers `link()`, `input()`, `checkbox()`,
  `textarea()`, `select()`, `toggleSwitch()` (also exported as `toggle`). Every existing helper takes a `control`
  option: `button`, `iconButton`, `linkButton`, `extLink`, `copyButton`, `details` (on its `<summary>`), `statTile`
  (only when it is a link or a button), `switchRow`, `formField` (`spec.control`, else `field-<name>`), `errorState`
  (its "Try again"). Helpers that build several controls take a prefix and derive the parts' ids: `tabs` →
  `<prefix>-<tab id>`; `dataTable` → `<prefix>-sort-<column>`, `<prefix>-row`, `<prefix>-row-expand`; `pagination` →
  `<prefix>-per-page|prev|next`; `filterBar` → `<prefix>-<field>`, `<prefix>-reset`; `listView` → its filter bar,
  table, pager and retry; `drawer` → `<prefix>-close`; `modal` → `<prefix>-close` and `<prefix>-<action>`; `confirm` →
  `<prefix>-cancel|confirm`; `prompt` → `<prefix>-input|cancel|confirm`; `formModal` → `<prefix>-<field>`,
  `<prefix>-cancel|submit`; `toast` → `toast-dismiss`, `toast-<action>`; `chipList`, `checklist`; the charts'
  chart/table switch → `<chart>-view-chart|view-table`. A helper called without an id sets no attribute (nothing is
  invented from a label), so a missed control shows up as a control without an id, which fails the E2E suite (part 2,
  "What fails the run"; before the review fixes only the scratchpad tool `S/wp3p1/ids.py` looked for one).
- **Conversion.** Every interactive element of every page, tab, drawer, dialog and the shell is created through these
  helpers with an id; the few raw elements left are wrapped with `setControl()` where the DOM had to stay exactly as it
  was (the owner-task "Open submission page" button-link, the template-variants and Autopilot-limits disclosures, the
  notifications bell), and the load-failure screen (shown when `ui.js` itself failed to load) sets
  `shell.reload` by hand. Distinct page-scoped ids the capture below saw (`S/wp3p1/control-ids.json`): Overview 27,
  Prospects 58, Submissions 63, Outreach 43, Backlinks 32, On-site SEO 29, Automation 56, Settings 173 (110 of them
  `field-<settings path>`), Logs 38, plus 15 `shell.*`. Prefixes per page, for orientation: Overview `grow-*`,
  `tile-*`, `chart-*`, `autopilot`, `integration*`, `automation-*`, `notifications-open-all`, `refresh`; Prospects
  `list-*`, `add`, `drawer-*` (incl. `drawer-tab-*`), `summary-*`, `opportunities-*`, `contacts-*`, `submissions-*`,
  `outreach-*`, `backlinks-*`, `actions-*`, `api-*`; Submissions `list-*`, `tab-*`, `owner-task-*`, `verified-*`,
  `drawer-*`, `content-*`, `fields-*`, `kit-*`, `steps-*`; Outreach `tab-*`, `campaign-*`, `drafts-dialog-*`,
  `messages-*`, `contacts-*`, `drawer-*`, `sender-*`; Backlinks `list-*`, `tile-*`, `chart-*`, `import*`, `add*`,
  `drawer-*`; On-site SEO `audit-*`, `indexnow-*`, `links-*`; Automation `flag-*`, `stage-<job type>-run|result`,
  `jobs-*`, `limits-*`, `kick`, `tick`, `refresh`, `auto-refresh`, `autopilot`; Settings `tab-*`, `field-*`,
  `secret-<key>-*`, `test-<provider>*`, `seeds-*`, `facts-*`, `assets-*`, `send-day-*`, `save`, `discard`, the save
  confirmations; Logs `tab-*`, `logs-*`, `audit-*`, `suppression-*`.

#### 4. Pre-existing problems found in part 0

| Found in part 0 | Fix |
|---|---|
| Horizontal page scroll at 390 px on Backlinks (both themes, the page and its drawer view: `scrollWidth` 450 for an `innerWidth` of 390). The filter bar's action group ("Reset", "Import from providers", "Add backlink", 409 px wide) could not wrap. | `assets/css/admin.css`: `.lga-filterbar-actions` gets `flex-wrap: wrap` and `max-width: 100%`. The buttons wrap onto a second line; `scrollWidth` is 390 now. |
| Console errors, warnings, page errors, failed requests | None in any of the 192 loads of part 0, and none in the 192 loads after the work. Nothing to fix. |

### Deliberate differences (with reasons)

Compared with `S/wp3-baseline/compare.mjs` (controls matched by region, role and accessible name: kind, enabled,
visible, text, href, target and state; REST requests on load and after actions; console messages; failed requests;
horizontal scroll) and pixel by pixel (`--pixels`), part 0's baseline against the capture of the final tree:

1. **Backlinks at 390 px** (4 of 192 screenshot pairs): no horizontal scroll (450 → 390 px); "Add backlink" wraps
   below "Import from providers". This is the fix above.
2. **Logs at 390 px** (2 pairs, light and dark; the page is 26 px taller): the same CSS rule lets the Logs toolbar
   wrap too, so "Refresh" moves to its own line instead of the "Auto-refresh every 15s" label being squeezed onto two
   lines. No control, text or request changes. Keeping it unchanged would need a page-specific rule; the wrapping
   toolbar is the behaviour §10.1 asks for at 390 px.
3. **Everything else is identical**: the same 48 views, every control (kind, enabled state, text, state), the same
   REST requests on load and after the views' actions, no new console message or failed request. The other pixel
   differences are below the 0.001 threshold and come from data the page shows live (the OPcache memory figures of
   the Overview's code-cache notice; the one log row a page view writes at real time), as in part 0's repeat run.
4. **`lga-config.pages`** carries page definitions (`{label, icon, module, cap, order}`) instead of labels, and
   `settingsTabs` the new module paths. No golden fixture records `lga-config`: `scheduler.json`, `wiring.json` and
   `wiring-wordpress.json` are unchanged (the unit wiring snapshot, the integration wiring step and the golden
   scheduler test pass without a rewrite; no `LGA_GOLDEN_UPDATE`).
5. **Routing edge cases** (not reachable through WordPress, which renders only registered plugin pages): a
   `data-page` that is not in `lga-config` opens the Overview (before: any built-in page key was routable, whatever
   the bootstrap listed); `ctx.pageUrl()` for such a page gives the Overview's URL (before: `#` plus the parameters);
   a bootstrap without pages shows "No dashboard page is available" (before: the nine built-in pages).
6. **The import map** has 8 more entries (the tab modules).

### Deviations from the design (with reasons)

1. **Scope `shell`** for the header, the navigation, the notifications drawer and the load-failure button: they are
   on every page, so one id serves every page's manifest (§12.5 names manifests per page; part 2 adds a shell
   manifest).
2. **Repeated row controls share one id** (an id names a control, not an element); §10.1 asks for
   `<page>.<action>` and the E2E manifest describes one entry per control, which data-dependent per-row ids would
   make unstable. Part 2 can tell rows apart by the table's `data-key`.
3. **"Register its expected effect for the E2E manifest"** (§10.1) is part 2's: the helpers only name the controls
   here; the manifests and the effect checks come with `tests/e2e/run.mjs`.
4. **Tab modules export `render( tab )` returning a node**, not `render( main, ctx )` like a page: a tab edits the
   page's one shared draft and is saved with every other tab by the page's save bar, so it gets the page's state and
   helpers instead of a context of its own.
5. **Module paths**: relative to `assets/js/`, or an absolute same-origin URL for an extension's page; everything else
   is refused (§10.1 only says "lazy-loads `module` paths").
6. **Pattern C rows owned by WP3** in `bin/neutrality-pending.txt` (`pages/overview.js` 1, `pages/prospects.js` 1,
   `pages/submissions.js` 5) are not removed: removing them changes visible text, which part 1 must not. The 7 pattern
   C lines of the Settings page moved with the code (`pages/settings.js` 7 → 2, new rows `settings/automation.js` 1
   and `settings/outreach.js` 4, owner WP4a as before; total 214, unchanged).
7. **The client keeps the submitter rule** (only `submissions`) as a second guard; it is the role's rule, not a page
   list.

### Tests

#### New

- `tests/Unit/Admin/SpaConfigTest.php` (11 tests) with `SpaConfigHarness.mjs` (Playwright, routes answered by the
  harness, no server) and `tests/fixtures/spa-config.json` (the `pages` and `settingsTabs` Admin\Menu prints for the
  owner with the WP2 modules; the browser harnesses build their `lga-config` from it):
  - server side: the fixture equals what `Menu::config()` prints; every page entry is the module's definition; a page
    added through `lga_admin_pages` with a capability the user lacks is not in `pages` or `pageUrls`, and is (in order)
    once the user has it; a page without a capability needs `lga_manage`; every built-in tab points at
    `settings/<tab>.js` and the file exists; the Settings page has no tab list of its own; the submitter gets exactly
    the owner-task page and no `settingsTabs`;
  - app.js helpers under node: definitions sorted by order, bad keys and missing labels left out, bare labels,
    module paths accepted and refused (`..`, query, another origin, `javascript:`, `.mjs`), routes (known, unknown,
    forbidden, without Overview, none);
  - browser: a page that exists only in `lga-config` (its module served by the harness) is listed at its order, routed,
    rendered, and its controls are scoped to it; a page missing from `lga-config` (no capability) and an unknown page
    open the Overview, are not listed and their modules are never requested; without an Overview the first page opens;
    module paths outside the plugin are refused without any request; a Settings tab that exists only in `lga-config`
    is listed at its order, opened by `#tab=`, and its field is saved through the same `POST /settings` with the leaf
    only; a tab missing from `lga-config` is not listed and its hash opens the first tab; a tab whose module fails shows
    the error in its panel, its "Try again" reloads the page and the other tabs keep working;
  - the ui.js helpers name every control they create (`<page>.<id>`, kebab-case, no id on a static tile or an empty
    link, derived ids of tabs, tables, pagers, filter bars, dialogs, scoped drawers).

#### Changed (none weakened)

- Browser harnesses (`JsCoreFixesHarness`, `JsPagesFixesHarness`, `SubmitterUiHarness`, `AutopilotUiHarness`,
  `CampaignUiHarness`, `OwnerTaskBrowserHarness`): their `lga-config` gains `pages` and `settingsTabs` from
  `tests/fixtures/spa-config.json` (what the server always sends; before, app.js fell back to its own list), and their
  import maps list `assets/js/settings/` like the server's. No selector and no assertion changed: the DOM is the same
  apart from the `data-lga-control` attribute.
- Source assertions that read code which moved, now read the file it moved to, with the same strings:
  `AutopilotUiTest::testStaticGuards` (`settings/discovery.js`, `settings/automation.js`);
  `SettingsAutomationFollowupsTest` (`settings/automation.js` for the tick-ceiling field and the autosend hint,
  `settings/outreach.js` for the approval hint, `settings/integrations.js` for the inbound secret URL forms; the
  page-level strings still read `pages/settings.js`).
- `ReleaseWiringTest::testServicesJobsControllersPagesAndSettingsAreRegistered`: the three strings of app.js's page
  list (route, icon, label of the SEO page) are replaced by the same facts where they live now: the SEO module's page
  definition (label, icon, module, capability, order), the module file, app.js loading `def.module`, and no
  `import( './pages/` left in app.js.
- `AdminRest2FixesTest::testEveryRelativeModuleImportIsVersionedThroughTheImportMap`: also scans
  `assets/js/settings/*.js`, and checks that every module path of the pages and tabs is versioned by the import map.

#### Capture tooling (scratchpad, not the repo)

`S/wp3-baseline/capture.mjs`: the two places that read a page's label from `lga-config.pages` accept both shapes
(a string at 333cb63, `{label, …}` now); the part 0 copy is `S/wp3-baseline/capture.part0.mjs`. New:
`S/wp3p1/ids.py` (per view: controls without `data-lga-control`, ids not in the `<scope>.<kebab>` form, ids shared by
controls that are not row repeats) and `S/wp3p1/callscan.mjs` (helper calls in the page, tab and shell modules that
name no control).

### Evidence

All commands from this folder, PHP 8.3.6, `nice -n 10`, every PHPUnit, integration and browser run under
`flock S/lga-suite.lock`, one after another. Ports 8793 (the site, started and stopped by the capture script and by
the integration suite) and 8794 (the fixture router, started and stopped by `S/wp3p1/it.sh`); no server left running.

| Check | Command | Result |
|---|---|---|
| Capture of the final tree | `node S/wp3-baseline/capture.mjs --out S/wp3p1/capture --shots S/e2e-shots/wp3p1` | exit 0 in 479 s: the same 48 views (45 administrator, 3 submitter), 192 screenshots; 0 console errors, warnings or page errors in all 192 loads; the site's database put back afterwards |
| Compare with part 0 | `node S/wp3-baseline/compare.mjs S/wp3-baseline/capture S/wp3p1/capture --pixels --diff-dir S/e2e-shots/wp3p1-diff` | no view added or removed; controls (2,906 in the 48 views at 1280 px light), their text, enabled state and state, the REST requests on load and after actions, console messages and failed requests identical; the only reported difference is Backlinks' horizontal scroll at 390 px (450 → 390, 4 loads); pixels: 16 of 192 pairs differ, 6 over the 0.001 threshold (Backlinks 390 × 4, Logs 390 × 2: deliberate differences 1 and 2), the other 10 below it (live data, difference 3) |
| Control ids | `python3 S/wp3p1/ids.py S/wp3p1/capture` | 2,906 controls in 48 views: 0 without `data-lga-control`, 0 ids outside `<scope>.<kebab-case>`; the only id on several controls of a view that the script cannot place in separate rows is the Overview's per-integration "Configure" link (10, one per `.lga-health-row`: a row repeat); 534 distinct ids (519 page-scoped, 15 `shell.*`) |
| Helper calls | `node S/wp3p1/callscan.mjs …` | every helper call that creates a control names it (the calls left are an `errorState` without a retry, static tiles and the doc comment of app.js) |
| Browser harnesses | the 10 runners of part 0 one by one, plus `SpaConfigTest` | all green, none skipped: JsCoreFixes 13, JsPagesFixes 19, SubmitterUi 7, ComplianceBanner 6, AutopilotUi 7, GrowPanel 14, CampaignUi 6, OwnerTaskBrowser 3, OwnerTaskUi 4, BrowserE2EFixes 6 (85, as in part 0), SpaConfigTest 11 (146 assertions) |
| Lint | `LGA_WP_PATH=S/wp-test-lga/wordpress bash bin/lint.sh` | `Lint OK` (exit 0); neutrality A 0, B 0, C 214 all pending (as before), D 0; php85-scan OK; compat-api OK |
| Unit suite | `LGA_WP_PATH=S/wp-test-lga/wordpress bash bin/test.sh` | `OK, but some tests were skipped! Tests: 848, Assertions: 32510, Skipped: 2` (exit 0, 120 s): part 0's 837 plus `SpaConfigTest` 11; the 2 skips are the WP4a checks of `NeutralityTest` |
| Integration suite, incl. coexistence | `S/wp3p1/it.sh` (fixture router on 8794, then `LGA_WP_PATH=S/wp-test-lga/wordpress LGA_FIXTURE_LOG=S/lga-run/fixture.log php tests/integration/run.php`) | `Passed: 538  Failed: 0` (exit 0, 35 s), coexistence `Passed: 36  Failed: 0`, the wiring step with the unchanged fixtures; the site's `debug.log` stayed at 145 lines |
| Golden fixtures | `git status --porcelain tests/fixtures/golden` | unchanged (no `LGA_GOLDEN_UPDATE`) |
| Build (extra) | `OUT_DIR=S/wp3p1/dist TMPDIR=S bash bin/build-zip.sh` | exit 0: `Lint OK`, `Neutrality OK (zip)`, `Neutrality OK (source)`; the plugin ZIP (265 entries) ships `assets/js/settings/` (8 modules); both ZIPs deleted afterwards, `dist/` untouched |
| Engine untouched | `git status --porcelain` at the repository root | only `linkgrowth-autopilot/` changed |

The empty `lga-e2e*` files the unit runs created in `/tmp` (4 per run, `BrowserE2EFixesTest`) were deleted; the 28 that
were there before were left.

Not run in part 1: the E2E suite of §12.5 (`tests/e2e/run.mjs` does not exist yet; part 2), PHP 8.5 (not part of this
step).

### Handed to part 2 (all taken up there, except the last point)

- Manifests: `S/wp3p1/control-ids.json` lists every id the capture saw per page (dialogs, toasts and drawers opened
  by actions are not in it; `callscan.mjs` shows they are named). The shell ids belong in a `shell` manifest.
- `Menu::spaPages()` and `definitionsFrom()` are where a page's module path and capability come from; the router
  refuses module paths outside the plugin, so an extension page must use a same-origin URL.
- The pattern C rows owned by WP3 (Overview 1, Prospects 1, Submissions 5) are store-specific wording; they need an
  owner decision on the replacement text.

## Part 2: the browser E2E suite (DESIGN.md §12.5)

Scope of part 2: `tests/e2e/run.mjs` and its control manifests for every existing page and Settings tab (no setup
wizard yet: WP27), with the permissions pass, the responsive pass, the seeded-defect checks that prove the suite fails
when it should, and the problems the suite found in the dashboard. Part 2 was started in an earlier session that a
usage limit cut off in the middle of a full run; this session restored the site from that run's own snapshot first
(see "Recovery" below), reviewed and completed the work, and ran everything again.

### How to run it

From this folder, with the LGA test site of §12.1 (`S` is the session scratchpad) and nothing listening on 8793:

```
LGA_WP_PATH=$S/wp-test-lga/wordpress flock $S/lga-suite.lock nice -n 10 node tests/e2e/run.mjs
```

- Playwright 1.63.0 through `createRequire` (`require('playwright')`, found through `NODE_PATH`), headless Chromium
  1243 from `PLAYWRIGHT_BROWSERS_PATH` (`chromium.launch()` without options; nothing is installed).
- The run serves the site itself on 127.0.0.1:8793 and the fixture router on 127.0.0.1:8794 (a router already
  running on 8794 is used as it is), and stops both at the end. It refuses to start while 8793 is in use.
- Output: the report (`report.json`: every entry with its requests, DOM-change count and problems, every view's
  screenshot and overflow figures, the seed, the refused outbound requests) and the server logs under
  `$S/e2e-runs/<UTC stamp>/` (`--out DIR` to choose); screenshots under `$S/e2e-shots/e2e/<role>/` (`--shots DIR`),
  named `<view>--<width>-<theme>.png`. Nothing is written into the repository.
- Exit 0: everything passed; 1: failures (each printed as `✘ <where>: <what>` and listed in the report); 2: usage
  error (no `LGA_WP_PATH`); 3: **not run** (the browser could not start: `E2E suite: not run (<reason>)`, before the
  site is touched).
- Options for working on one page: `--only overview,logs` (control pass and coverage for those pages, plus the shell),
  `--skip responsive,permissions,controls`, `--discover FILE` (every control met, with its text, value, options and
  where it was met: the starting point for a new manifest entry), `--verbose` (every passing entry and seed call).
- `bin/lint.sh` checks the suite's modules with `node --check` and the manifests with
  `node tests/e2e/run.mjs --check-manifests` (no browser, no site).
- A full run takes about 46 minutes since the review fixes (2,768 s for the final one: about 1.5 minutes for the
  preparation, the seed and the views, 4 for the responsive pass, 2 for the permissions pass, 38 for the control pass
  at about 2.7 s per entry); before them it took 41.

### What one run does

1. Loads and validates the manifests (`tests/e2e/controls/*.json`); starts Chromium. Either failing ends the run before
   the site is touched.
2. **Snapshot.** Copies the site's SQLite database and `wp-content/debug.log` into the output folder and writes a
   marker next to the site's WordPress folder (`$S/wp-test-lga/.lga-e2e-restore-pending.json`). A run that is killed
   before its own restore (SIGKILL, a machine that stops: what happened to this part's first session) leaves the marker,
   and the next run puts that snapshot back before it takes its own. SIGINT and SIGTERM (`timeout`) restore normally.
3. **Site preparation** (`tests/e2e/setup.php`): the plugin state is reset exactly as the integration suite's first
   step does (plugin tables dropped, `lga_` options and transients deleted, plugin deactivated and activated again), the
   post-activation quiet window ended, two users created with random passwords (`lga-e2e-admin`, administrator;
   `lga-e2e-submitter`, the LinkGrowth Submitter role), eight owner tasks from the verified-opportunity database for
   fictional `*.test` directories and one table backup (the only records not created through REST: no lga/v1 route
   creates either; backups are otherwise made before migrations only).
4. **Servers.** The site runs under `php -S` with `tests/e2e/server-prepend.php` as `auto_prepend_file` (never
   installed in the site): no Action Scheduler loopback runner (no background job changes data between two page loads;
   the dashboard's own "Run now" buttons still run their work in their request), no outbound HTTP except to the site
   and the `*.test` fixtures (everything else is refused and logged: the run never reaches a third-party service), mail
   logged instead of sent, and the per-user REST write counter reading 0 (the walk makes several hundred writes in
   minutes; the 60-per-minute limit itself is covered by the unit suite).
5. **Login** as both users through `wp-login.php`.
6. **Seed through REST** as the administrator (`tests/e2e/lib/seed.mjs`, 153 calls, each must answer 2xx): the
   Northwind Supply Co profile (§12.2) and fixture settings, prospects of every fixture kind (directory with a form,
   resource page, blog with a mention, spam site), their evaluations, a contact, packages, backlinks (one checked), a
   suppression entry, an outreach campaign, 26 extra prospects, backlinks and suppression entries (so every pager has
   a second page), a finished growth run, a WooCommerce product and three posts that mention it (internal-link
   suggestions), seven manual kits, five outreach prospects drafted into the campaign with their messages approved,
   sent, replied and rejected as the message drawer's buttons need. The ids are the `{seed.<key>}` values of the
   manifests.
7. **Views.** Every page of the administrator's `lga-config.pages` (9: Overview, Prospects, Submissions, Outreach,
   Backlinks, On-site SEO, Automation, Settings, Logs), every tab of those pages (12: Submissions 8, Outreach 2, Logs
   2) and every Settings tab of `lga-config.settingsTabs` (8; the count must equal the tabs the page shows): 28 views
   as the administrator, and the owner-task page with its tab as the submitter (30 in all). Every load must be clean
   (no console error, page error or failed request) and WordPress must not deny it.
8. **Responsive pass**: every view at 1280 × 900 and 390 × 844 px, in the light and the dark theme of the dashboard's
   theme switch (`lga_theme` in localStorage; the run checks the `.lga-wrap` shows that theme): a full-page screenshot
   of each, and at 390 px `document.documentElement.scrollWidth <= innerWidth` (the report names the elements that
   stick out when it fails).
9. **Permissions pass** as the submitter: their `lga-config` lists only `submissions` and no Settings tabs, their
   admin menu links to the owner-task page only, every other page's URL is refused to them, and the manifest entries
   marked for the submitter are performed as the submitter (destructive ones last).
10. **Control pass** as the administrator: every entry, destructive ones last (see below).
11. **Coverage**: every `[data-lga-control]` element met anywhere in the walk (loads, steps, after actions, dialogs,
    drawers, toasts; visible or not) needs a manifest entry, every entry's control must have been met by its role, a
    control the submitter meets must be an owner-task (`submissions.*`) or shell control with a submitter entry, and
    no interactive element of the dashboard met in the walk may lack a `data-lga-control` (review fix 1).
12. **End**: the PHP error log must have no new line naming the plugin with a PHP error; the servers stop; the database
    and `debug.log` are put back and the database's SHA-256 compared with the snapshot (a difference fails the run);
    the `uploads/lga-backups-*` folder the run's reset made (the reset deletes the option naming the site's folder, so
    the first `GET /settings` makes a new one) is removed again, so a run leaves no file behind.

### One manifest entry

`tests/e2e/controls/<page>.json` is `{"page": "<page>", "description": "…", "controls": [entry, …]}`; Settings keeps
one file per tab (`settings.<tab>.json`, so a package that adds a tab adds a file); a page's link in the section
navigation (`shell.nav-<page>`, which app.js makes for every page of `lga-config.pages`) is in that page's own file,
clicked from another page (the Overview unless `page` says otherwise), so a package that adds a page touches no shared
manifest; the header and the notifications drawer are in `shell.json`. An entry is the §12.5 shape `{control, action: click|fill|select|toggle,
input?, expect: {request: "METHOD /route", status, dom?}}` plus what a real walk needs (`lib/manifest.mjs` documents
each key; an unknown key is an error, so a typo cannot quietly weaken a check):

- `expect.request` may name query parameters the request must carry (`?status=qualified`, `?campaign_id=*`) and ones
  it must not (`?!channel`: absent or empty; every filter Reset expects the reload without the filter its `before`
  step typed).
- `expect` also takes `body` (text the request body must contain; for `fill` it defaults to the input), `navigate`,
  `popup` and `download` (links that leave the page, open a new tab or download a file); at least one expectation is
  required. `dom` is a selector that must match a visible element, `!selector` (none), or `text=…`.
- `view` (`#tab=messages`, `#open={seed.prospectMain}`), `page` (another page to start from), `before` (steps that
  reach the control: a control id to click, or `{control, action, input, nth, within}`), `then` (steps between the
  action and the checks: fill a dialog, submit it), `after` (steps that put the state back), `prepare` (REST calls as
  the administrator before the view loads: a state the page cannot reach by itself), `mock` (the browser gets this
  answer instead of the server's, e.g. a 500 for an error state and its "Try again"; mocked failures count as
  declared), `nth` and `within` (row controls), `roles`, `destructive`, `fresh`, `timeout`, `allowFailures`, `id`
  (a second entry for the same control) and `note`.

How an entry is performed (`run.mjs` `runEntry`): run the `prepare` calls; load the view (or reuse the page when the
previous entry left it exactly as loaded); run `before` (each step settles at least `DEFER_MS`, 650 ms, after its
action, longer than the dashboard's longest debounce, so the request a step schedules is answered within the step and
never counted for the action); find the visible, enabled control; record the DOM state and the expected DOM
condition; act; run `then`; wait for the expected request to be answered (and the navigation, new tab or download);
let the page settle; then check. Every console error, page error and failed request is checked once, wherever it
happens: during the load, the `before` steps, the action, the `after` steps, or later (a late answer, a timer:
reported for the entry it follows, and the entry's record is marked failed). A page the walk moves on from is not
closed at once: it stays open `LINGER_MS` (8 s) with its writes held (never sent, never answered; reads go through),
so a timer the entry started (the status reload 3 s after "Kick scheduler", a toast's dismissal) runs on a live page
and what it raises is reported; the end of each pass waits for the last pages. Timers longer than that (the 9-15 s
toasts' dismissal, the 15 s and 30 s auto-refresh loops, which their own entries wait for) are not covered.

### What fails the run

- a control without a manifest entry, or an entry whose control the walk never meets (per role);
- an interactive element of the dashboard without `data-lga-control` (a link, button, input, select, textarea,
  summary, disclosure, `[role=button|link|tab|switch|checkbox|…]` or focusable element inside `.lga-wrap` that is not
  itself, or inside, a named control; a focusable tab panel is not a control): nothing could describe it, so nothing
  would show that it works;
- an action that sends no request and changes nothing: no lga/v1, REST, admin-ajax or document request, no DOM
  mutation, navigation, new tab, download or native dialog (spec §10 "no decorative buttons");
- an expected request that is not sent, answers another status, or whose body lacks the expected text;
- a DOM expectation that does not hold after the action, **or that held before it already** (no change);
- any console error or page error; any failed request (4xx, 5xx or network error) the entry does not declare
  (`expect.status`, `allowFailures`, `mock`);
- a submitter who meets a control outside the owner-task page and the shell, a submitter `lga-config` listing more
  than the owner-task page, or another page that opens for the submitter;
- a horizontal scroll at 390 px or a theme the dashboard does not show, on any view;
- a seed call that does not answer 2xx, a PHP error-log line naming the plugin, a database that differs from the
  snapshot after the restore.

Outbound requests to hosts other than the fixtures are refused by the server, listed in the report and printed (for
the record: WordPress core's and WooCommerce's own update and connection checks are among them); they are not a
failure, because a control may legitimately call a provider (its test button then shows the provider's error).

### The manifests

| File | Entries | Controls | Request checked | DOM checked | Navigate / new tab / download | Mocked answer | Destructive | As the submitter |
|---|---|---|---|---|---|---|---|---|
| `automation.json` | 82 | 82 | 39 | 42 | 1 | 1 | 2 | 0 |
| `backlinks.json` | 47 | 46 | 25 | 19 | 4 | 1 | 1 | 0 |
| `logs.json` | 49 | 49 | 39 | 9 | 1 | 0 | 1 | 0 |
| `outreach.json` | 105 | 105 | 58 | 46 | 5 | 5 | 10 | 0 |
| `overview.json` | 53 | 53 | 8 | 24 | 23 | 5 | 0 | 0 |
| `prospects.json` | 101 | 101 | 52 | 41 | 12 | 2 | 2 | 0 |
| `seo.json` | 37 | 37 | 15 | 14 | 8 | 5 | 3 | 0 |
| `settings.advanced.json` | 9 | 9 | 8 | 8 | 1 | 8 | 0 | 0 |
| `settings.automation.json` | 35 | 35 | 35 | 35 | 0 | 35 | 0 | 0 |
| `settings.business.json` | 16 | 16 | 16 | 16 | 0 | 16 | 0 | 0 |
| `settings.content.json` | 18 | 18 | 15 | 17 | 0 | 14 | 1 | 0 |
| `settings.discovery.json` | 18 | 18 | 17 | 11 | 0 | 16 | 0 | 0 |
| `settings.integrations.json` | 125 | 125 | 43 | 103 | 0 | 32 | 0 | 0 |
| `settings.json` | 35 | 35 | 9 | 24 | 2 | 1 | 0 | 0 |
| `settings.monitoring.json` | 8 | 8 | 8 | 8 | 0 | 8 | 0 | 0 |
| `settings.outreach.json` | 20 | 20 | 20 | 20 | 0 | 20 | 0 | 0 |
| `shell.json` | 6 | 6 | 2 | 3 | 2 | 0 | 0 | 1 |
| `submissions.json` | 140 | 94 | 72 | 55 | 26 | 7 | 18 | 51 |
| **Total** | **904** | **857** | **481** | **495** | **85** | **176** | **38** | **52** |

"Controls" counts distinct ids; an id has more than one entry when the submitter performs it too, or when one control
is checked in two situations (`id`). An entry may check a request and a DOM change at once.

- **Destructive entries** (38: delete a backlink, a prospect, a suppression entry; cancel queued jobs; archive a
  campaign; approve, reject, send and close messages; mark owner tasks submitted; reject or retry packages; apply or
  dismiss link suggestions; reset the facts registry) run after every other entry of their role, each on its own seeded
  record (the owner tasks: four for the administrator, three for the submitter), so no later entry depends on what they
  remove. A refusal the dashboard should meet (e.g. "Suggest from SE Ranking" without a key) is an entry expecting that
  4xx.
- **Settings fields** (110 `field-*` entries, plus the secrets, the facts, linkable-asset and custom-seed rows and the
  send days: 149): each changes its field, clicks Save and checks the request Save sends: `POST /settings` (or
  `POST /automation/toggle` for the three automation flags), with the field's settings path and its new value in the
  body (`"site":{"address":"1200 NW Front Ave e2e"`), and the save bar left "unsaved". The server's answer to that one
  request is mocked (422) so the site keeps its seeded settings for the rest of the walk; the real save path is the
  `settings.save` entry and the save confirmations (limits removed, automated submissions and autosend enabled,
  individual approval off, storefront gate off, automatic links on), which save for real and put the value back.
- **Secrets**: every one of the 11 write-only secrets has its Set/Replace, input (Save sends it) and, with a value
  stored first through REST and removed afterwards, its Clear, the confirmation's close, cancel and confirm, and Undo.
- **Data-dependent controls**: a control that only shows for some data gets that data first: the link-status filters
  "Left for you" and "Not shown on the page" (shown while selected, `#links=…`), the backup download (the backup
  `setup.php` makes), the Overview's "Review internal-link suggestions" (a growth run after the seed's suggestions),
  error states and their "Try again" (a mocked 500).
- **Owner tasks and the submitter** (52 entries): the owner-task card and the package drawer as the submitter, the
  shell's theme switch and the navigation's one link (`shell.nav-submissions`, in `submissions.json`); the walk's other
  controls must never appear for them.
- `tests/Unit/E2E/ManifestTest.php` (with `ManifestHarness.mjs`, node only) runs the manifest library on every unit
  run: request patterns and status classes, the validator refusing the entries a typo would weaken, the shipped
  manifests valid with a file per page, a shell file, a file per Settings tab, submitter and destructive entries, and
  every Settings field entry checking its Save request and body.

### Problems the suite found, and what changed

| Found by the walk (plugin code) | Change |
|---|---|
| A contact whose source is not a web address (the source of a contact added by hand is `manual`) showed a "page" link to `#` that opened the dashboard again in a new tab: a link that does nothing useful (Outreach › Contacts, Prospects › Contacts). | `ui.js` `extLink()`: a value that is not an http(s) address is plain text (its title keeps the value), never a link; the Contacts tables label it (`Manual`). `SpaConfigTest::testTheUiHelpersNameEveryControlTheyCreate` checks it (`extLinkNotWeb`). Visible change: "page ↗" becomes "Manual" in those rows. |
| A button whose handler ended on a refused request showed the refusal as an error toast (api.js) and then also raised an uncaught error on the page ("Suggest from SE Ranking" without a key: `page error: SE Ranking is not configured: add the API key first.`). | `api.js` marks an error it has shown (`surfaced`); `ui.js` `button()` ends the click there instead of rethrowing. A refusal the handler asked to keep silent, and any other error, still surface. New `SpaConfigTest::testARefusedRequestInAButtonHandlerEndsAtItsToast`; fail-before: the scenario run against a copy whose `button()` lacks the catch reports the uncaught error `Probe refused: add the API key first.` for the refused button, so `assertSame( [], … )` fails; with the change it reports none (`S/wp3p2b/failbefore-surfaced.sh`, output in `S/wp3p2b/failbefore-surfaced.out`). No visible change apart from the missing console error. |

#### Corrections to the harness the first session left

The first session wrote the suite and the manifests but never completed a full run; this session found and fixed:

| Problem | Fix |
|---|---|
| A run interrupted by SIGTERM could exit before its restore: the signal closed the browser, the main flow failed, called the (already started) cleanup, got `true` back at once and exited, so the site stayed in the run's seeded state (seen in this session; the database was put back by hand from the run's own snapshot). A killed run (the first session's) left the site seeded as well, with nothing pointing at the snapshot. | `cleanup()` is one shared promise that both the signal handler and the main flow wait for; an interrupted run is reported as such and exits 130. `lib/site.mjs` writes a restore-pending marker with the snapshot and puts an unfinished run's snapshot back before the next run takes its own. |
| Every run left an empty `uploads/lga-backups-<random>` folder behind (12 from part 2's runs; removed). | The run removes the backup folders it created (only new ones holding nothing but the guard files and dumps). |
| `ManifestTest` could not run: its helper `result()` overrode PHPUnit's final `TestCase::result()`, a fatal error that would have stopped the whole unit suite. | Renamed to `harness()`; the test runs (6 tests). |
| 149 Settings entries only checked that the save bar turned "unsaved", so a field bound to the wrong settings path, or to none, would have passed. | Each now checks the request Save sends and its body (see "The manifests"). |
| A DOM expectation could already hold before the action (the drafts filter's expected row was on screen before typing), and console errors or failed requests in an entry's `before`/`after` steps, between entries and during view discovery were never checked. | Both are checked now (see "What fails the run"); the drafts-filter entry expects the matching prospect to be the only row left. |
| The two "package this prospect" entries depended on a prospect the Overview's growth run had meanwhile rejected (the fixture host does not answer), so the package route answered 422; the Overview's "Review internal-link suggestions" link appeared only after the walk's own growth run, so it was met without an entry. | `prepare` steps: the prospects are qualified again first; a growth run is made before the link's entry. No plugin change: refusing to package a rejected prospect is correct. |
| Data-dependent controls the part 1 capture had seen but the walk never reached (the link-status filters "Left for you" and "Not shown on the page", the backup download, the Clear button of a stored secret other than Brave's). | Entries that create the data first (see "The manifests"). |
| The geo-modifiers entry typed the old store's market words (neutrality pattern C, lint failed). | Neutral words (`Portland`, `Oregon`, `Pacific Northwest`). |
| The end-of-run summary called WooCommerce's own connection check "1 by the plugin". | WordPress.org and WooCommerce hosts are named as such. |

No other control was decorative, broken or unreachable: every control of every page and tab has an entry that
passes.

### Seeded-defect checks

`S/wp3p2b/defects.sh` (scratchpad) copies `assets/`, `tests/e2e/` and the fixture router into a throwaway folder,
seeds one defect into the copy, runs the copy's `run.mjs` (which serves the copy's `assets/` to the browser; PHP comes
from the site), checks it fails with the expected message, and deletes the copy:

| Defect seeded into the copy | Run | Result |
|---|---|---|
| (a) an extra Backlinks header button `backlinks.seeded-extra` with no manifest entry | `--only backlinks --skip responsive,permissions` | exit 1: `coverage backlinks.seeded-extra: a control with no manifest entry ("Seeded extra", button in main; …)`, the only failure (61 entries passed) |
| (b) a Backlinks button without a handler, with a manifest entry expecting only the page to be there | same | exit 1: `backlinks.seeded-decorative: decorative: the action sent no request and changed nothing; no DOM change: the page showed .lga-page-backlinks before the action already`, the only failure |
| (c) `console.error()` at the start of the Backlinks page module | same | exit 1, 60 failures: every load of the page (view discovery, each entry, the shell's link to it) reports `console error: Seeded defect (c): a console error on the Backlinks page` |
| (d) `.lga-page-backlinks .lga-page-head { min-width: 640px }` | `--only backlinks --skip controls,permissions` | exit 1: `responsive admin 390px light backlinks: horizontal scroll: scrollWidth 664 > innerWidth 390 (div.lga-page-head (right 664), …)` and the same in dark; 1280 px passes |
| (e) no browser (`PLAYWRIGHT_BROWSERS_PATH` pointing at an empty folder) | the full command | exit 3: `E2E suite: not run (the browser could not start: browserType.launch: Executable doesn't exist at …)`; no snapshot taken, the database's checksum unchanged |

Each copy was deleted afterwards (`copies deleted: yes`); after the five runs the site's database had its checksum
from before, `debug.log` its 145 lines, no marker and no new backup folder were left, and no server was running.
Results: `S/wp3p2b/defects/results.txt`. Three more defect classes these checks did not cover (a control without an
id, a Reset that does nothing, an error raised by a timer after an entry) are seeded before and after the review
fixes in "Review fixes" below.

### Deviations from the design (with reasons)

1. **Manifest keys beyond §12.5's shape** (`view`, `before`, `then`, `after`, `prepare`, `mock`, `nth`, `within`,
   `roles`, `destructive`, `id`, `allowFailures`; `body`, `navigate`, `popup`, `download` in `expect`): most controls
   are inside a tab, drawer or dialog that other controls open, some leave the page or open a new tab, and some
   states (an error to retry, Autopilot on before "turn off") are not reachable by clicking. Each key only adds a
   check or a way to reach the control; none removes one.
2. **Settings fields save through a mocked answer.** Their entries check the Save request and its body, but the
   server's answer to it is replaced (422) so the walk does not change the seeded settings 149 times; the real save is
   covered by the save and confirmation entries, the unit tests of `SettingsSchema`/`SettingsController` and the
   integration suite.
3. **The E2E server does not rate-limit REST writes and makes no outbound request** (`server-prepend.php`, used only
   by this suite's `php -S`): see "What one run does" 4.
4. **Eight owner tasks and one table backup are written by `setup.php`**, not through REST: no lga/v1 route creates
   a verified-database owner task (they come from the catalog import, and no catalog ships, §2.5) or a backup (made
   before migrations only).
5. **Rows of one control share its id** (part 1, deviation 2): an entry picks a row with `nth` or `within`.
6. **No wizard steps**: there is no setup wizard yet (WP27 adds it and its manifest).
7. **§12.3's command** sets `NODE_PATH` and `PLAYWRIGHT_BROWSERS_PATH`; both are already set in this environment, so
   the documented command is the one above (the design's form works the same).
8. **Registering a control's expected effect** (§10.1) lives in the manifests, not in `ui.js`: an id is all the page
   code carries, and every id must have an entry, so a control added without one fails the suite, and so does a
   control created without an id (review fix 1).

### Tests

- **New:** `tests/Unit/E2E/ManifestTest.php` (6 tests, with `ManifestHarness.mjs`, node only; see "The manifests");
  `SpaConfigTest::testARefusedRequestInAButtonHandlerEndsAtItsToast` (with the `surfacedErrors` scenario in
  `SpaConfigHarness.mjs`, whose route handler can now answer a request with an error status).
- **Changed:** `SpaConfigTest::testTheUiHelpersNameEveryControlTheyCreate` checks that a value that is not a web
  address is text (`extLinkNotWeb`); the headers of both SpaConfig files name the part 2 checks. No assertion was
  removed or weakened.
- `bin/lint.sh` runs `node --check` on `tests/e2e/**/*.mjs` and `node tests/e2e/run.mjs --check-manifests`.

### Recovery (start of this session)

- No server was listening on 8793 or 8794 and no PHP, Chromium or node process of the first session was left.
- The site's database was in the cut-off run's seeded state (SHA-256 `ec5019…`: 3 users, the seed's prospects,
  backlinks, campaign and messages) and `debug.log` had one line more (146, the run's
  `[lga][error][verified] Verified opportunity database unreadable`). That run's own snapshot
  (`S/wp3p2/full1/site-db-before.sqlite`, `407c69…`) differed from the copy the first session took when it started
  (`S/wp3p2/site-db-original.sqlite`, `5208d9…`, the state part 1's last integration run left) by one dashboard page
  view a probe of that session had made without restoring (one log row, an auto-draft post, session tokens,
  transients). The database was put back to `5208d9…` and `debug.log` to its 145 lines (byte-identical to the first
  145 of the 146); the seeded database was kept as `S/wp3p2b/cutoff-run-db.sqlite`.
- The 12 empty `uploads/lga-backups-*` folders part 2's runs had left were removed (the database names the folder
  part 1's integration run made, which was kept).

### Evidence

All commands from this folder, PHP 8.3.6, node 22, `nice -n 10`, every PHPUnit, integration and browser run under
`flock S/lga-suite.lock`, one browser at a time; ports 8793 and 8794 only; no server left running. The final
sequence below ran one after another on the final tree.

| Check | Command | Result |
|---|---|---|
| Lint | `LGA_WP_PATH=S/wp-test-lga/wordpress bash bin/lint.sh` | `Lint OK` (exit 0): `E2E manifests OK: 904 entries in 18 files`; neutrality A 0, B 0, C 214 all pending (as before), D 0; php85-scan OK; compat-api OK |
| Unit suite | `LGA_WP_PATH=S/wp-test-lga/wordpress bash bin/test.sh` | `OK, but some tests were skipped! Tests: 855, Assertions: 32588, Skipped: 2` (exit 0, 122 s): part 1's 848, `ManifestTest` 6 and the new SpaConfig test; the 2 skips are the WP4a checks of `NeutralityTest`. The 4 empty `lga-e2e*` files the run left in `/tmp` were deleted (the 28 from before were left) |
| Integration suite, incl. coexistence | `S/wp3p2b/it.sh` (fixture router on 8794, then `LGA_WP_PATH=… LGA_FIXTURE_LOG=S/lga-run/fixture.log php tests/integration/run.php`, which serves the site on 8793 itself) | `Passed: 538  Failed: 0` (exit 0), coexistence `Passed: 36  Failed: 0`; the site's `debug.log` stayed at 145 lines |
| **E2E suite** | `LGA_WP_PATH=S/wp-test-lga/wordpress flock S/lga-suite.lock nice -n 10 node tests/e2e/run.mjs` (under `timeout 3600`) | **`E2E suite passed.`** (exit 0, 2,456 s, report `S/e2e-runs/20261125T210031/report.json`): 9 pages, 8 Settings tabs, 12 page tabs, 30 views; 153 of 153 seed calls; 120 screenshots (30 views × 1280/390 px × light/dark) under `S/e2e-shots/e2e/`, 60 overflow checks at 390 px (widest `scrollWidth` 390); permissions pass 52 entries as the submitter; control pass 854 entries as the administrator (30 destructive, run last); **906 of 906 entries passed** (all 904 manifest entries, two of them for both roles), **857 controls met, every one with an entry and every entry's control met**; 481 requests checked (status and, where given, body), 496 DOM checks, 0 console errors, 0 page errors, 0 undeclared failed requests; no outbound request was attempted; the database put back (checksum matches), the backup folder the run made removed, `debug.log` back to 145 lines |
| Build (both ZIPs) | `OUT_DIR=S/wp3p2b/dist TMPDIR=S bash bin/build-zip.sh` | exit 0: `Lint OK`, `Neutrality OK (zip)`, `Neutrality OK (source)` (the source ZIP's temporary-path check passed: no suite file names the scratchpad). Plugin ZIP: 265 entries, only `linkgrowth-autopilot.php`, `uninstall.php`, `readme.txt`, `includes/`, `assets/`, `data/`, `languages/`: no `tests/`, so no E2E file ships. Source ZIP: 534 entries, with the suite's 25 files (`tests/e2e/run.mjs`, `lib/` 4, `setup.php`, `server-prepend.php`, `controls/` 18), without `docs/notes/`, `DESIGN.md` and `coexistence.php`. Both ZIPs deleted afterwards; `dist/` untouched |
| Seeded defects | `S/wp3p2b/defects.sh` | (a) to (e) all detected, see "Seeded-defect checks" |
| Fail-before of the new SpaConfig test | `S/wp3p2b/failbefore-surfaced.sh` | without the `button()` catch: the refused button raises `Probe refused: add the API key first.` as an uncaught error; with it: none |
| Golden fixtures | `git status --porcelain tests/fixtures/golden` | unchanged; `lga-config` gained no field in part 2 (no `LGA_GOLDEN_UPDATE`) |
| Engine untouched | `git status --porcelain` at the repository root | only `linkgrowth-autopilot/` changed |

Earlier runs of this session (scratchpad, for the record): a full run with the first session's checks
(`S/wp3p2b/full2`, stopped with SIGTERM after 357 entries: 2 failures, the package entries; it exposed the
restore race above), a full run with the new checks (`S/wp3p2b/full3`: 852 of 854 entries passed, 804 controls met,
2 failures: the drafts filter's "no DOM change" and the needs-you link without an entry), and three runs of single
pages for the fixes (`S/wp3p2b/only4`, `only5`, `only6`).

Not run: PHP 8.5 (not part of this step); the setup wizard's steps (no wizard until WP27).

### For later packages

- **WP4a**: the pattern C rows WP3 owns (Overview 1, Prospects 1, Submissions 5) still need the owner's replacement
  wording (part 1, deviation 6).
- **WP30**: the configuration notices the plugin prints above the dashboard through `admin_notices`
  (`Admin\Notices`: e.g. "No search provider is configured … Open Settings", with WordPress's dismiss button, seen on
  the E2E site's pages) are outside `.lga-wrap`, are not built by `ui.js` and carry no `data-lga-control`, so the
  suite neither checks their link and dismiss button nor fails on them. The control-id check covers the dashboard
  (`.lga-wrap`) only; noted here, not changed in WP3 (outside the review fixes' scope).
- **WP9**: the `import_verified` stage logs `[lga][error][verified] Verified opportunity database unreadable` whenever
  it runs without a catalog (no catalog ships, §2.5): every E2E and integration run writes it. An expected absence
  should not be an error.
- **Every package that adds or changes a control** adds its entry to `tests/e2e/controls/<page>.json` (or its own
  Settings tab file) and runs `node tests/e2e/run.mjs --only <page>`; `--discover` lists what the walk met (controls
  without an id with `"id": null`). A package that adds a page also adds its navigation link's entry to that same
  file: `{"control": "shell.nav-<page>", "action": "click", "expect": {"navigate": "/page=lga-<page>(&|#|$)/"}}`
  (clicked from the Overview); `shell.json` stays as it is.

## Review fixes (after part 2)

A review of the uncommitted WP3 work confirmed four defects in the E2E harness and in the seam it hands to later
packages. All four are fixed in `tests/e2e/` and the unit tests; no plugin code changed (`assets/`, `includes/`
untouched), `lga-config` gained no field and no golden fixture changed.

| # | Finding | Fix |
|---|---|---|
| 1 | **Interactive elements without `data-lga-control` were ignored.** `collectControls()` only collected `[data-lga-control]`, and coverage only checked the ids it met, so the likeliest mistake (a ui.js helper called without `control:`) gave a control the suite never saw, decorative or not. The check existed only in the scratchpad tool `S/wp3p1/ids.py`. | `lib/browser.mjs` `collectControls()` also returns every interactive element of the dashboard (`INTERACTIVE_SELECTOR`: links, buttons, inputs, selects, textareas, summaries, disclosures, `[onclick]`, the interactive ARIA roles, `contenteditable`, any focusable element) inside `.lga-wrap` that is not itself, or inside, a named control, with its page (`shell` for the header, navigation and footer). A focusable tab panel (`role=tabpanel`, tabindex 0) and a `<details>` whose `<summary>` is the control are not controls. `run.mjs` records them wherever the walk meets them (loads, steps, after actions, dialogs, drawers, toasts; visible or hidden) and the coverage step fails on each one: `coverage <page> (no control id): an interactive element without data-lga-control ("<text>", <tag> in <region>: <html>; met by … in …)`. `--discover` lists them with `"id": null`; the summary line and `report.json` count them. |
| 2 | **A broken filter Reset passed.** `runStep()` settled without `since`: the DOM had been quiet since the load, so a `before` fill returned about 100 ms later, before the filter's 350 ms debounce fired; the debounced request (still carrying the typed filter) came after `runEntry` took its mark and was counted as the Reset's request, and `requestMatches()` accepts any extra query. A Reset that does nothing passed, depending on timing (9 entries have this shape). | (a) `settle()` takes `defer`: with `since` (the page clock after the step's action) the page is not settled until `DEFER_MS` (650 ms) after it, longer than the dashboard's longest debounce (outreach claims check 400 ms, filterBar 350 ms) plus the time the request needs to show up; `runStep()` uses it, so what a step schedules is sent, answered and rendered within the step. (b) Request patterns take `!key` (the request must not carry `key`, or carry it empty), and the 9 Reset entries expect the reload without the filter their `before` step typed (`GET /logs?!channel`, `GET /backlinks?!q`, …), so a Reset that reloads without clearing fails too. |
| 3 | **Errors a few seconds after an entry were never checked.** The next entry's `Session.load()` flushed and closed the page at once, so a console error, page error or failed request a timer raised later (the Automation page's status reload 3 s after "Kick scheduler", toast timers) happened on a closed page. The header of `run.mjs` and this file claimed otherwise. | `Session.retire()`: a page the walk moves on from stays open `LINGER_MS` (8 s) with its writes held (`holdWrites()`: any non-GET request is never sent and never answered, so a lingering page cannot change what later entries see; reads go through, so the delayed reload runs); then its events are checked for the load or entry it belongs to (`<entry> (after its checks): …`, the entry's record in `report.json` marked failed) and it is closed. Each pass waits for its last lingering pages at its end (about 8 s per pass). Timers longer than 8 s are not covered: the 9-15 s toasts' dismissal (a DOM removal) and the 15 s and 30 s auto-refresh loops, whose own entries wait for the refresh. |
| 4 | **A package page's navigation link could not go in the package's own manifest.** app.js gives every page of `lga-config.pages` a `shell.nav-<page>` link that coverage requires an entry for; `validateEntry()` accepted `shell.*` only in `shell.json`, and all 9 built-in nav entries were there, so a package following this file's guidance got a red run, and the precedent was to edit the shared `shell.json` (against §13.1). | `validateEntry()` also accepts `shell.nav-<page>` in `<page>.json` (or `<page>.<part>.json`) and refuses a nav entry clicked on the page it opens (the navigate check would hold before the click); a `shell.*` entry starts on the Overview wherever it is described. The 9 built-in nav entries moved from `shell.json` to their pages' files (same entries; `shell.json` keeps the header and the notifications drawer: 6 entries). The headers of `manifest.mjs` and `shell.json` and "One manifest entry" / "For later packages" above say where a new page's nav entry goes. |

### Regression checks

New unit tests (both run on every unit run; the browser part is skipped when node, Playwright or a browser is
missing):

- `tests/Unit/E2E/BrowserLibTest.php` (5 tests, with `BrowserLibHarness.mjs`: Playwright, pages served by the
  harness, no server):
  - `testEveryInteractiveElementWithoutAControlIdIsReportedAndNothingElse` (finding 1): a page with named controls,
    a button and a raw link without an id (the review's seed), a header button, a search input, a disclosure without
    a summary, a raw `role=switch`, a focusable span, a hidden button and a toast button without an id: exactly these
    9 are reported (with page, region, visibility), not the WordPress menu outside `.lga-wrap`, an anchor without
    `href`, a hidden input, the input inside a named label, a `<details>` with a named summary or a focusable tab panel;
  - `testADebouncedRequestOfAStepIsAnsweredBeforeSettleReturns` (finding 2): a field whose request is debounced
    `DEFER_MS - 200` ms; after a fill, `settle()` as `runStep()` calls it returns with that request answered and
    nothing left for the next action;
  - `testALingeringPageSendsReadsButHoldsItsWrites` (finding 3): after `holdWrites()` a GET reaches the server, a POST
    does not, stays pending and raises nothing on the page;
  - `testDeferCoversEveryDebounceOfTheDashboard` and `testLingerCoversEveryTimerOfTheDashboard`: scan `assets/js`;
    every debounce delay must be at most `DEFER_MS - 200`, and every `setTimeout` delay written there at most
    `LINGER_MS` (the two auto-refresh loops excepted), so a later package that adds a longer one gets a failing test
    instead of a quietly weaker suite.
- `tests/Unit/E2E/ManifestTest.php` (3 new tests, 4 new validator cases):
  - `testANegatedQueryKeyMatchesOnlyARequestWithoutThatFilter` and `testEveryFilterResetExpectsAReloadWithoutTheFilter`
    (finding 2);
  - `testAPageDescribesItsOwnNavigationLink` (finding 4): `shell.nav-backlinks` is valid in `backlinks.json` (and
    still in `shell.json`); every page of `tests/fixtures/spa-config.json` has its nav entry in its own file, clicked
    from another page; the validator refuses `shell.nav-prospects` and `shell.theme` in `backlinks.json` and a nav
    entry clicked on the page it opens.

### What the fixed harness exposed

The first full run with the fixes (`S/wp3fix/full-attempt1`, 2,734 s) failed 5 checks, all from one manifest entry
whose passing state had depended on the race of finding 2: `outreach.drafts-dialog-select-all` typed "Northfield"
into the drafts dialog's filter (debounced 200 ms) before "Select all visible". Under the old harness the filter never
applied before the click, so every run selected every listed prospect and built their drafts in the newest campaign;
the destructive `campaign-approve-drafts-confirm-confirm`, `campaign-drafts-failed-dialog-close|ok` and
`drawer-approve-confirm-confirm` entries were built on that state (the newest campaign has drafts, some fail the
compliance check; the seed campaign keeps its drafts). With the fill applied, the entry selected only the resource
prospect (already in the seed campaign, so skipped), "Approve all drafts" then hit the seed campaign and approved the
message the drawer entry needs, and the failed-drafts dialog never opened: `before: step
outreach.campaign-approve-drafts not found` (2), `before: step outreach.drawer-approve not found` (1) and the two
dialog controls never met (2). Change: the select-all entry no longer types into the filter (the filter itself is
checked by `outreach.drafts-dialog-search`, which expects the matching prospect to be the only row left), so the
state those entries need is now what the manifest says; the four entries' notes name the dependency. No check was
removed: the select-all entry expects the same `POST /outreach/campaigns/{id}/drafts`, and `--only outreach` then
passed (111 of 111, `S/wp3fix/outreach1.log`) before the final sequence below was run again from lint on.

### Seeded defects, before and after the fixes

`S/wp3fix/defects.sh before|after` copies `assets/`, `tests/e2e/` (for `before`: the harness as it was, saved in
`S/wp3fix/before/`) and the fixture router into a throwaway folder, seeds `S/wp3fix/seed_review.py` (E1 a button made
without a control id and a raw `<a href="#">` in the Backlinks header; E2 every filter bar's Reset returns at once;
E3 a page error 6 s after a click on the Backlinks "Lost or broken" tile, and the Automation page's own status reload
3 s after "Kick scheduler" throws), runs the copy's suite with `--only backlinks,logs,automation --skip
responsive,permissions` against the LGA site (PHP from the site, assets from the copy) and deletes the copy.

| Seeded defect | Harness before the fixes (`S/wp3fix/defects/before`, exit 1, 588 s) | Harness after the fixes (`S/wp3fix/defects/after`, exit 1, 714 s) |
|---|---|---|
| E1: button without an id, raw link | not reported (0 failures name them) | `coverage backlinks (no control id): an interactive element without data-lga-control ("Seeded button without an id", button type=button in main: …)` and the same for "Seeded raw link" |
| E2: Reset does nothing (4 entries in scope) | 3 of 4 pass: `logs.logs-filter-reset` ok with `GET /logs?channel=rest…`, the audit and suppression Resets likewise with the filter their `before` step typed; only `backlinks.list-filter-reset` failed, by timing | all 4 fail: `decorative: the action sent no request and changed nothing; expected GET /logs?!channel but saw no lga/v1 request` (and Backlinks, Audit, Suppression): the step's own request is no longer counted for the Reset |
| E3: page error 6 s after the Lost tile | not reported (`backlinks.tile-lost` ok) | `backlinks.tile-lost (after its checks): page error: Seeded E3: a page error 6 s after the Lost tile` |
| E3: Kick's 3 s reload throws | not reported (`automation.kick` ok, `POST /automation/kick 200`) | `automation.kick (after its checks): page error: …the delayed reload after Kick scheduler failed`, and the same for `automation.toast-dismiss`, whose `before` step clicks Kick |
| Anything else | the run's only failure is the Backlinks Reset above (189 of 190 passed) | no other failure: 9 failures, all seeded (7 of the 185 entries, 178 passed, plus E1's 2 coverage failures) |

Both runs restored the site's database (checksum matches) and removed the backup folder they made; the copies were
deleted.

### Seam probe (finding 4)

`S/wp3fix/seam.sh`: throwaway copies of the plugin and of the LGA site (the copy site's plugin symlink points at the
copy). The "package" adds `includes/Seamprobe/SeamprobeModule.php` (one `adminPages()` entry, order 65),
`assets/js/pages/seamprobe.js` (a button that loads the settings), `tests/e2e/controls/seamprobe.json` (that button
and `shell.nav-seamprobe`) and one `Modules::LIST` line, and touches nothing else.

- Before the fixes (the manifests as they were plus `seamprobe.json`, checked by the old harness): exit 1,
  `seamprobe.json #2 (shell.nav-seamprobe): control shell.nav-seamprobe does not belong to seamprobe.json`.
- After: `E2E manifests OK: 906 entries in 19 files`; the copy's walk `--only seamprobe --skip permissions` exit 0,
  `E2E suite passed`: 10 pages, the new page and its nav link listed and routed, `seamprobe.load` (`GET /settings`)
  and `shell.nav-seamprobe` (from `seamprobe.json`) passed, 4 screenshots, `scrollWidth` 390 at 390 px in both
  themes, no control without an id; the copy site's database restored; copies deleted.

### Fail-before of the unit checks

`S/wp3fix/failbefore-unit.sh` runs `tests/Unit/E2E` in a throwaway copy of the plugin whose `tests/e2e` is the
harness before the fixes, then on the real tree: before, `Tests: 14, Failures: 9` (all 9 new tests: the 5 of
`BrowserLibTest` and the 4 changed or new `ManifestTest` checks); after, `OK (14 tests, 155 assertions)`
(`S/wp3fix/failbefore-unit.out`).

### Cost

The step defer and the lingering pages make a run longer: the seeded runs above took 714 s instead of 588 s for
about the same entries, and a full run about 46 minutes instead of 41. About three retired pages are open at
a time besides the current one (one entry every 2-3 s, 8 s each).

### Evidence (final sequence)

All commands from this folder, PHP 8.3.6, node 22, `nice -n 10`, every PHPUnit, integration and browser run under
`flock S/lga-suite.lock`, one browser at a time, one after another on the final tree; ports 8793 and 8794 only, no
server left running. This table supersedes part 2's "Evidence".

| Check | Command | Result |
|---|---|---|
| Lint | `LGA_WP_PATH=S/wp-test-lga/wordpress bash bin/lint.sh` | `Lint OK` (exit 0): `E2E manifests OK: 904 entries in 18 files`; neutrality A 0, B 0, C 214 all pending (as before), D 0; php85-scan OK; compat-api OK |
| Unit suite | `LGA_WP_PATH=S/wp-test-lga/wordpress bash bin/test.sh` | `OK, but some tests were skipped! Tests: 863, Assertions: 32675, Skipped: 2` (exit 0, 121 s): part 2's 855 plus `BrowserLibTest` 5 and `ManifestTest` 3; the 2 skips are the WP4a checks of `NeutralityTest`. The 4 empty `lga-e2e*` files the run left in `/tmp` were deleted (the 28 from before were left) |
| Integration suite, incl. coexistence | `S/wp3fix/it.sh` (fixture router on 8794, then `LGA_WP_PATH=… LGA_FIXTURE_LOG=S/lga-run/fixture.log php tests/integration/run.php`, which serves the site on 8793 itself) | `Passed: 538  Failed: 0` (exit 0, 34 s), coexistence `Passed: 36  Failed: 0`; the site's `debug.log` stayed at 145 lines |
| **E2E suite** | `LGA_WP_PATH=S/wp-test-lga/wordpress flock S/lga-suite.lock nice -n 10 node tests/e2e/run.mjs` (under `timeout 4800`) | **`E2E suite passed.`** (exit 0, 2,768 s, report `S/wp3fix/full/run/report.json`): 9 pages, 8 Settings tabs, 12 page tabs, 30 views; 153 of 153 seed calls; 120 screenshots (30 views × 1280/390 px × light/dark) under `S/e2e-shots/e2e/`, 60 overflow checks at 390 px (widest `scrollWidth` 390); permissions pass 52 entries as the submitter; control pass 854 entries as the administrator (30 destructive, run last); **906 of 906 entries passed** (all 904 manifest entries, two of them for both roles), **857 controls met, every one with an entry and every entry's control met, 0 interactive elements without a control id** (`--discover`: none with `"id": null`); 481 requests checked (status and, where given, body), 496 DOM checks, 0 console errors, 0 page errors, 0 undeclared failed requests, 0 problems after an entry's checks (lingering pages); no outbound request was attempted; the database put back (checksum matches the one before the run), the backup folder the run made removed, `debug.log` back to 145 lines, no `lga-e2e*` file left in `/tmp` |
| Build (both ZIPs) | `TMPDIR=S bash bin/build-zip.sh` | exit 0: `Lint OK`, `Neutrality OK (zip)`, `Neutrality OK (source)`; `dist/linkgrowth-autopilot-1.0.0.zip` (265 entries: `linkgrowth-autopilot.php`, `uninstall.php`, `readme.txt`, `includes/`, `assets/` with the 8 `assets/js/settings/` modules, `data/`, `languages/`; no `tests/`) and `dist/linkgrowth-autopilot-1.0.0-source.zip` (536 entries: part 2's 534 plus `BrowserLibTest.php` and `BrowserLibHarness.mjs`; the suite's 25 files; without `docs/notes/`, `DESIGN.md` and `coexistence.php`). The command is the one given for this step (`TMPDIR=S bash bin/build-zip.sh`), so the ZIPs are in `dist/` (git-ignored; it held the WP1/WP2 build of Nov 23 before) |
| Seeded defects, seam probe, unit fail-before | `S/wp3fix/defects.sh before`, `… after`, `S/wp3fix/seam.sh`, `S/wp3fix/failbefore-unit.sh` | see the three sections above |
| Golden fixtures | `git status --porcelain tests/fixtures/golden` | unchanged; `lga-config` gained no field (no `LGA_GOLDEN_UPDATE`) |
| Engine untouched | `git status --porcelain` at the repository root | only `linkgrowth-autopilot/` changed |

Not run: PHP 8.5 (not part of this step); the setup wizard's steps (no wizard until WP27).
