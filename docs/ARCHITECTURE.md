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

- Prospect: discovered → evaluating → qualified | rejected → approved/in_progress → acquired; on_hold and suppressed are manual.
- Submission: pending_review | needs_manual → approved → queued → submitted → confirmed | failed | rejected | duplicate.
- Outreach message: draft → approved | scheduled → sent → replied | bounced | opted_out | closed.
- Backlink: pending → active | lost | broken | redirected | unverified.

## Background execution

`Scheduler` registers `ppclg_tick`. `Pipeline::tick()` records the tick, enqueues due stages (deduplicated), and runs `JobRunner` under a lock for `automation.max_execution_seconds`. Jobs are small, idempotent and re-enqueue themselves when work remains, so the 30-second PHP limit is never approached. Failed jobs retry with backoff up to three times; stale "running" jobs are recovered by the cleanup stage. Manual runs from the dashboard use `Pipeline::runNow()`.

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
