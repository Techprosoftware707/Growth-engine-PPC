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
│   │                            CompetitorBacklinkSource, MentionFinder, BrokenLinkFinder
│   ├── Submission/              PackageBuilder, Policy, AdapterRegistry, Adapters (manual, generic form, configured API),
│   │                            ListingVerifier, SubmissionEngine
│   ├── Outreach/                Templates, Personalizer, Compliance, Mailer, Unsubscribe, InboundWebhook, FollowUps, OutreachEngine
│   ├── Monitoring/              Notifier, BacklinkMonitor, BacklinkImporter
│   ├── Automation/              Scheduler (Action Scheduler / WP-Cron), Pipeline (stage cadence), JobRunner (locks, retries), Jobs/*
│   └── Admin/                   Capabilities, Menu, Assets, Notices, Rest/* (ppclg/v1 controllers)
├── assets/                      css/admin.css, js/app.js + modules + pages (no build step)
└── data/                        keywords.json, seeds.json, discovery-queries.json, excluded-domains.json
```

## Data model

`prospects` (one per registrable domain) → `opportunities` (specific pages/actions) → `submissions` (packages and their lifecycle) / `outreach_messages` (per contact & approach) → `backlinks` (acquired or discovered links). Supporting tables: `contacts`, `campaigns`, `suppression`, `notifications`, `audit_log`, `logs`, `jobs`, `domain_metrics` (provider cache), `search_log` (query cooldown).

Status flows:

- Prospect: discovered → qualified | rejected → approved/in_progress → acquired; on_hold and suppressed are set by a person (the engine also parks a prospect on hold after an evaluation error and retries it after 1 day). No "evaluating" status is stored while a prospect is evaluated: the in-flight marker lives in the `ppclg_evaluate_inflight` option (a legacy "evaluating" row older than 15 minutes is picked up again). Machine decisions are retried, a person's never: a site that cannot be fetched is re-checked after 1, 7 and 30 days (then no longer); a qualified prospect keeps its status until 3 consecutive checks failed, and statuses set by a person (approved, in progress, acquired, on hold) are kept.
- Submission: packaged as queued (the policy allows it), pending_review (only authorisation blocks it) or needs_manual (a person must submit) → approved (by a person) → submitted → confirmed; failed (a retryable failure goes back to queued, at most 3 attempts) | rejected | duplicate. While the queue sends a row it holds a short claim on it, and payload edits, reject, rebuild and mark-submitted are refused until the result is recorded.
- Outreach message: draft → approved | scheduled → sending → sent → replied | bounced | opted_out | closed; rejected by a person (or when the prospect is rejected), failed when sending fails. "sending" is the claim taken just before the email goes out; a row left in it for more than 15 minutes is marked failed and never re-sent automatically.
- Backlink: pending → active | lost | broken | redirected | unverified.

## Submission delivery

Adapters (`Submission\Adapters\*`) return `status` (`submitted`, `failed` with `retryable`, or `needs_manual`) plus two optional keys: `sent` (whether a request actually reached the destination; the daily/weekly submission limit is spent only then) and `retry_after` (seconds the destination asked to wait on a retryable failure; the retry then waits at least 6 hours and at most 7 days). A listing request is not idempotent, so `retryable` is reported only when it provably never left (DNS, connect or TLS failure). A timeout or dropped connection after sending, a 5xx answer, an exception after hand-off or an interrupted run (an expired claim) moves the row to needs_manual with `outcome_unknown`; it is never re-posted automatically. Limits are checked when a row is about to be sent, not when it is packaged or approved. `Submission\Policy` re-runs every gate right before sending; its hard blockers include a site set to "never automate", a comment or forum form, a CAPTCHA, login or required upload, robots.txt, an unmapped required field, an earlier sent submission to the same target or site, and package text that fails the claims filter. Checkboxes about fees, paid or featured placements and newsletters (`PackageBuilder::CONSENT_PAID`, `CONSENT_DENY`) are never ticked.

## Background execution

`Scheduler` registers `ppclg_tick`. `Pipeline::tick()` records the tick, enqueues due stages (deduplicated), and runs `JobRunner` under a lock for `automation.max_execution_seconds` (at least 15 s). Jobs are small, idempotent and re-enqueue themselves when work remains, so with the default 20 s the 30-second PHP limit is never approached. Failed jobs retry with backoff up to three times; stale "running" jobs are recovered by the cleanup stage. Manual runs from the dashboard use `Pipeline::runNow()`; *Send now* on an outreach message takes the same runner lock and is refused (HTTP 409) while a background run holds it.

## Scoring model

Weighted sum of seven components (see README). Component details are stored in `prospects.score_breakdown` and shown in the UI. Hard gates set `auto_reject`: hard risk flags (parked, PBN footprint, hacked keywords, hidden links, heavy spam/link selling), no topical relevance, unreachable site.

## Security model

- Admin REST routes require `ppclg_manage` (granted to administrators) and the WordPress REST nonce; POSTs are rate limited per user.
- Secrets: AES-GCM/XSalsa20 encrypted with a key derived from the site salts, or wp-config constants; never serialised to the client.
- Outbound requests use `reject_unsafe_urls` (no private networks), size limits, timeouts and TLS verification.
- Logs and audit entries pass through the redactor.
- Database writes go through `$wpdb->prepare`; output is escaped.

## Extensibility

Filters: `ppclg_metrics_providers`, `ppclg_backlink_sources`, `ppclg_search_providers`, `ppclg_submission_adapters`, `ppclg_job_types`, `ppclg_taxonomy`, `ppclg_discovery_queries`, `ppclg_seed_catalog`, `ppclg_excluded_domains`, `ppclg_http_request_args`. Action: `ppclg_booted`. See EXTENDING.md.
