# Architecture

```
ppc-link-growth-engine/
├── ppc-link-growth-engine.php   bootstrap, constants, activation hooks
├── includes/
│   ├── Plugin.php               service container wiring (all constructors are declared here)
│   ├── Container.php / Autoloader.php / Activator.php / Deactivator.php
│   ├── Support/                 Settings, Secrets (encrypted), Crypto, Logger, Redactor, Limits, Locks, TimeBudget, Url, Str, Json
│   │   └── Http/                Client (robots-aware, throttled, SSRF-safe), Response, RobotsTxt
│   ├── Database/                Schema (dbDelta), Migrator (backup → migrate), Backup (gzipped SQL)
│   ├── Repositories/            one repository per table; JSON columns auto-encoded
│   ├── Analysis/                PageAnalyzer (DOM), FormDetector, ContactExtractor, SiteProfiler, SubmissionPageFinder,
│   │                            RelevanceScorer, RiskAnalyzer, QualitySignals, Taxonomy (data/keywords.json)
│   ├── Scoring/                 ScoringEngine (weights, confidence, classification, hard gates)
│   ├── Integrations/            ProviderRegistry, MetricsAggregator (cache), Providers (SE Ranking, Ahrefs, Semrush, Moz),
│   │                            Search (Google CSE, Brave, Serper), Google (ServiceAccount JWT, SearchConsole, Analytics4, SiteKitBridge),
│   │                            Rdap, ClaudeWriter
│   ├── Content/                 FactsRegistry, ClaimsFilter, LinkableAssets, ContentGenerator
│   ├── Discovery/               DiscoveryEngine, QueryGenerator, SeedCatalog, Classifier, DomainFilter, ProspectIntake,
│   │                            CompetitorBacklinkSource, MentionFinder, BrokenLinkFinder,
│   │                            VerifiedOpportunities (imports data/verified-opportunities.json as owner tasks)
│   ├── Compliance/              StorefrontAudit (storefront wording check; gate for Policy and OutreachEngine)
│   ├── Seo/                     SiteAuditor (read-only audit), IndexNow, InternalLinker (suggestions; applied on click)
│   ├── Growth/                  GrowthRun ("Grow now": resumable one-click run of every stage)
│   ├── Submission/              PackageBuilder, Policy, AdapterRegistry, Adapters (manual, generic form, configured API),
│   │                            ListingVerifier, SubmissionEngine
│   ├── Outreach/                Templates, Personalizer, Compliance, Mailer, Unsubscribe, InboundWebhook, FollowUps, OutreachEngine
│   ├── Monitoring/              Notifier, BacklinkMonitor, BacklinkImporter
│   ├── Automation/              Scheduler (Action Scheduler / WP-Cron), Pipeline (stage cadence), JobRunner (locks, retries), Jobs/*
│   └── Admin/                   Capabilities, Menu, Assets, Notices, Rest/* (ppclg/v1 controllers)
├── assets/                      css/admin.css, js/app.js + modules + pages (no build step)
└── data/                        keywords.json, seeds.json, discovery-queries.json, excluded-domains.json,
                                 verified-opportunities.json (595 researched opportunities, built by bin/build-verified-db.py)
```

## Data model

`prospects` (one per registrable domain) → `opportunities` (specific pages/actions) → `submissions` (packages and their lifecycle) / `outreach_messages` (per contact & approach) → `backlinks` (acquired or discovered links). Supporting tables: `contacts`, `campaigns`, `suppression`, `notifications`, `audit_log`, `logs`, `jobs`, `domain_metrics` (provider cache), `search_log` (query cooldown).

Status flows:

- Prospect: discovered → qualified | rejected → approved/in_progress → acquired; on_hold and suppressed are set by a person (the engine also parks a prospect on hold after an evaluation error and retries it after 1 day). No "evaluating" status is stored while a prospect is evaluated: the in-flight marker lives in the `ppclg_evaluate_inflight` option (a legacy "evaluating" row older than 15 minutes is picked up again). Machine decisions are retried, a person's never: a site that cannot be fetched is re-checked after 1, 7 and 30 days (then no longer); a qualified prospect keeps its status until 3 consecutive checks failed, and statuses set by a person (approved, in progress, acquired, on hold) are kept.
- Submission: packaged as queued (the policy allows it), pending_review (only authorisation blocks it) or needs_manual (a person must submit) → approved (by a person) → submitted → confirmed; failed (a retryable failure goes back to queued, at most 3 attempts) | rejected | duplicate. While the queue sends a row it holds a short claim on it, and payload edits, reject, rebuild and mark-submitted are refused until the result is recorded.
- Outreach message: draft → approved | scheduled → sending → sent → replied | bounced | opted_out | closed; rejected by a person (or when the prospect is rejected), failed when sending fails. "sending" is the claim taken just before the email goes out; a row left in it for more than 15 minutes is marked failed and never re-sent automatically.
- Backlink: pending → active | lost | broken | redirected | unverified.

## Submission delivery

Adapters (`Submission\Adapters\*`) return `status` (`submitted`, `failed` with `retryable`, or `needs_manual`) plus two optional keys: `sent` (whether a request actually reached the destination; the daily/weekly submission limit is spent only then) and `retry_after` (seconds the destination asked to wait on a retryable failure; the retry then waits at least 6 hours and at most 7 days). A listing request is not idempotent, so `retryable` is reported only when it provably never left (DNS, connect or TLS failure). A timeout or dropped connection after sending, a 5xx answer, an exception after hand-off or an interrupted run (an expired claim) moves the row to needs_manual with `outcome_unknown`; it is never re-posted automatically. Limits are checked when a row is about to be sent, not when it is packaged or approved. `Submission\Policy` re-runs every gate right before sending; its hard blockers include a site set to "never automate", a comment or forum form, a CAPTCHA, login or required upload, robots.txt, an unmapped required field, an earlier sent submission to the same target or site, package text that fails the claims filter, and a blocking storefront compliance check (reason code `storefront_inconsistent`, see below). Checkboxes about fees, paid or featured placements and newsletters (`PackageBuilder::CONSENT_PAID`, `CONSENT_DENY`) are never ticked.

## Background execution

`Scheduler` registers `ppclg_tick`. `Pipeline::tick()` records the tick, continues a growth run whose tab was closed, enqueues due stages (deduplicated), and runs `JobRunner` under a lock for `automation.max_execution_seconds` (at least 15 s). Jobs are small, idempotent and re-enqueue themselves when work remains, so with the default 20 s the 30-second PHP limit is never approached. Failed jobs retry with backoff up to three times; stale "running" jobs are recovered by the cleanup stage. Manual runs from the dashboard use `Pipeline::runNow()`; *Send now* on an outreach message takes the same runner lock and is refused (HTTP 409) while a background run holds it.

## Storefront compliance gate

`Compliance\StorefrontAudit` reads the store from the database only (no HTTP, never edits): with WooCommerce, product categories and tags (name, slug, description), published product titles and short descriptions, and the items of every assigned menu location; without WooCommerce, published page and post titles. Matching is whole-word and case-insensitive against a block list (consumer-use framing such as weight loss, anti-aging, tanning, skincare, nasal spray, capsule, injection, syringe, dosing, supplement, "for human use") and a warn list (recovery, growth, cognitive, metabolic, longevity, healing); a phrase governed by a negation ("Not for human use") is not a finding. Product titles that are consumer dosage forms block, and short descriptions also go through the claims filter. The result (status pass / warn / block, a hash of the findings, counts and up to 200 findings) is stored in the non-autoloaded option `ppclg_storefront_audit`.

The check runs daily (stage `storefront_audit`), in the growth run's preflight, and before the gate relies on a result that is missing, older than 24 hours or marked stale (saving a product, page or post, a product category or tag, or a menu marks it stale). While the gate (`compliance.storefront_gate`) is on and the effective status is *block*, `Policy` refuses automated submissions with a hard reason and `OutreachEngine` refuses every send (scheduled and *Send now*) with a "Paused: …" note on the message; drafting and packaging continue. When the check cannot run, both fail closed. The owner may acknowledge the current findings (`POST /compliance/storefront/acknowledge` with `confirm: true`; stored as hash, user and time, audited): the effective status is then *acknowledged* until the findings change.

## Growth run

`Growth\GrowthRun` is a resumable state machine stored in the non-autoloaded option `ppclg_growth_run`. The dashboard starts it (`POST /growth/start`) and calls `POST /growth/step` until it is no longer running; each step request runs as many steps as fit in `growth.step_seconds` (20 s, at most 25 s) and the state is saved after every step. Steps, in order: preflight (storefront check plus integration and sender-identity checks), `import_verified`, `site_audit`, `indexnow`, `internal_links`, `discover`, `mention_scan`, `broken_link_scan`, `evaluate`, `find_opportunities`, `package`, `submit`, `verify_listings`, `auto_outreach`, `monitor_backlinks`, `import_backlinks`, `metrics_refresh`, summary. A job step runs the job once through `JobRunner::runType()` (manual, tagged with the run id, under the runner lock) and then its queued continuations through `JobRunner::runQueuedOfType()`, up to a cap per step (`evaluate`: `growth.evaluate_rounds`; `site_audit` and `import_verified`: 8; `find_opportunities`: 4; others: 3). An unregistered job type is skipped. The submit step is *blocked* while the storefront gate blocks or automated submissions are off; there is no outreach sending step. `Pipeline::tick()` continues a run nobody advanced for 45 seconds (closed tab), even while the automation switch is off. Only one run is active at a time (lock `growth_run`); cancelling keeps the work done and cancels the run's queued follow-up jobs. The summary reads every number from the database and reports a value it cannot read as `null` ("not available"), never as 0.

## On-site SEO

- `Seo\SiteAuditor` reads published posts, pages and products from the database in cursor-based batches (at most `seo.audit_max_pages`) and stores up to 500 issues with a severity, a fix sentence and an edit link in `ppclg_site_audit`. Rank Math titles, descriptions, focus keywords and robots are resolved with `RankMath\Helper::replace_vars` when available. Internal links are resolved with `url_to_postid()`, not HTTP; the optional HTTP sample requests at most 10 of the site's own URLs, after robots.txt, and reports a Cloudflare challenge as unknown. It never edits content.
- `Seo\IndexNow` keeps a 32-character key in `seo.indexnow_key` (generated by the first submission or when the On-site SEO page is opened), serves it at `/{key}.txt` from `parse_request` (priority 0), queues changed URLs from `transition_post_status` / `save_post`, and submits at most 10,000 URLs per request to `https://api.indexnow.org/indexnow` through `Support\Http\Client`. The first run submits every public, indexable URL; afterwards only new or changed URLs (tracked against `post_modified_gmt`), with unchanged URLs re-sent at most every 30 days. It submits only when `wp_get_environment_type()` is `production` (filter `ppclg_indexnow_environment`) and WordPress does not discourage search engines; after a refusal the scheduled stage waits an hour.
- `Seo\InternalLinker` suggests links from posts and pages (types that keep revisions) to products (by title) and to posts or pages with a Rank Math focus keyword, at the first whole-word match outside links, headings, code, captions, shortcodes and block comments, at most `seo.max_links_per_post` per item. `apply()` runs only from `POST /seo/links/apply` with `confirm: true`: it re-locates the text, refuses when the surrounding text changed, wraps exactly the matched bytes in a link, saves through `wp_update_post()` (a revision) and records an audit entry.

## Verified opportunity database

`Discovery\VerifiedOpportunities` imports `data/verified-opportunities.json` when its version changes (stage `import_verified`, the growth run, or *Import now* on the Submissions page), in batches of at most 100 items with a cursor. Each item goes through `ProspectIntake` and `DomainFilter` (excluded platforms and suppressed domains are skipped and listed), gets one opportunity and one owner task: a submission row with status `needs_manual`, method `manual`, adapter `manual_package`, the submission page as target and `payload.package` (listing kit, description, steps, tier, authority, conditions, `source: verified_db`). Duplicates are prevented by the prospect and target hash, so a re-import finds the existing tasks. The import makes no HTTP requests and creates no contacts or messages; automatic outreach drafting skips prospects with `discovered_via = verified_db`. Options: `ppclg_verified_db_version`, `ppclg_verified_db_state`, `ppclg_verified_db_items`, `ppclg_verified_db_file`.

## Scoring model

Weighted sum of seven components (see README). Component details are stored in `prospects.score_breakdown` and shown in the UI. Hard gates set `auto_reject`: hard risk flags (parked, PBN footprint, hacked keywords, hidden links, heavy spam/link selling), no topical relevance, unreachable site.

## Security model

- Admin REST routes require `ppclg_manage` (granted to administrators) and the WordPress REST nonce; POSTs are rate limited per user.
- Three routes are also open to `ppclg_submit_listings` (the *Link Growth Submitter* role): `GET /submissions`, `GET /submissions/{id}` and `POST /submissions/{id}/mark-submitted`. A controller opts a route in with `route( …, $public = false, $submitter = true )`, and the handler then limits the data to the owner-task queue (`Capabilities::submitOnly()`); every other route stays admin-only by default. `SubmitterRoleTest` walks every registered route to keep it that way.
- Secrets: AES-GCM/XSalsa20 encrypted with a key derived from the site salts, or wp-config constants; never serialised to the client.
- Outbound requests use `reject_unsafe_urls` (no private networks), size limits, timeouts and TLS verification.
- Logs and audit entries pass through the redactor.
- Database writes go through `$wpdb->prepare`; output is escaped.

## Extensibility

Filters: `ppclg_metrics_providers`, `ppclg_backlink_sources`, `ppclg_search_providers`, `ppclg_submission_adapters`, `ppclg_job_types`, `ppclg_rest_controllers`, `ppclg_taxonomy`, `ppclg_discovery_queries`, `ppclg_seed_catalog`, `ppclg_excluded_domains`, `ppclg_http_request_args`, `ppclg_indexnow_environment`. Action: `ppclg_booted`. See EXTENDING.md.
