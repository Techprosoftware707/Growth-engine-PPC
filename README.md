# PPC Link Growth Engine

A WordPress plugin that gives **Pure Peptides Canada** (https://purepeptidescanada.com/) an automated, compliance-first backlink growth platform: it discovers websites that could legitimately link to the business, qualifies and scores them on a 100-point model, finds the actual submission page or editorial contact, prepares personalised listing packages and outreach emails, submits listings where that is explicitly authorised, and monitors every acquired backlink.

The plugin lives in `ppc-link-growth-engine/` and installs like any other plugin. Everything else in this repository (tests, docs, build scripts) supports development.

## What it does

| Area | Capability |
|---|---|
| Discovery | Rotating search queries (business/scientific directories, associations, publications, education, resource pages, blogs, networks, podcasts, media), a curated Canadian seed catalog, competitor-backlink mining through SE Ranking/Ahrefs/Semrush, unlinked brand-mention detection and broken-link detection on relevant resource pages. |
| Qualification | Polite crawling (robots.txt, crawl delay, honest user agent), site profiling, topical + geographic relevance against a laboratory-research keyword taxonomy, editorial-quality and audience signals, provider metrics (Domain InLink Rank, DR, DA, Authority Score, organic traffic, keywords, referring domains) and a spam/PBN/link-farm/misuse risk analysis. |
| Scoring | 100-point weighted score (topical relevance 30, organic traffic 20, editorial quality 15, audience 10, authority 10, indexability & placement 10, risk 5) with confidence (how much of the score is backed by real data), classification bands and automatic rejection of link farms, parked/hacked sites and irrelevant sites. Metrics are never fabricated: missing providers are reported as missing. |
| Submissions | Detects listing forms, maps required fields, picks the right category, generates unique verified-fact descriptions per directory, and builds complete packages. Automated form posting happens **only** when the administrator has explicitly authorised the destination, the form has no CAPTCHA/login, robots.txt allows it and limits permit. Everything else goes to a review queue with the full package and instructions. Confirmed listings are verified and recorded as backlinks. |
| Outreach | Eight personalised approaches (resource page, directory inclusion, partnership, expert commentary, educational contribution, editorial mention, broken-link replacement, unlinked mention), CASL/CAN-SPAM identification and unsubscribe handling, suppression lists, approval workflow, send windows, daily limits, follow-ups, reply logging and an inbound-email webhook. |
| Content | Verified-fact registry (seeded from the live site), a claims filter that blocks therapeutic/human-use/purity/superlative language, research-use-only positioning in every piece of copy. Optional AI personalisation through the Anthropic API, always filtered. |
| Monitoring | Scheduled backlink checks (status, anchor, rel, placement, indexability, changes, two-strike loss detection), imports from SE Ranking/Ahrefs/Semrush and GA4 referral sources, in-dashboard notifications and email alerts/digests. |
| Integrations | SE Ranking Data API (primary), Ahrefs v3, Semrush, Moz, Google Search Console and GA4 (service account) with a Site Kit bridge for dashboard numbers, Google Programmable Search / Brave / Serper for discovery, RDAP for domain age, Anthropic Claude (optional). |
| Dashboard | Overview, Prospect Explorer, Submission Queue, Outreach Manager, Backlink Monitor, Automation Center, Settings and Logs — a dependency-free admin app with light/dark themes and accessible SVG charts. |
| Safety | Capability checks, REST nonces, sanitised input and escaped output, encrypted credentials (keyed from the site salts, or wp-config constants), redacted logs, per-host rate limiting, duplicate prevention, locks, retries, audit log of every action, pre-migration table backups. |

## Quick start

1. Build or download the plugin zip: `bash bin/build-zip.sh` → `dist/ppc-link-growth-engine-<version>.zip`.
2. Upload it in WordPress under **Plugins → Add New → Upload Plugin** and activate.
3. Open **Link Growth → Settings**, confirm the business profile, add your SE Ranking API key, a search provider key, and (optionally) the Google service-account JSON.
4. In **Automation**, run *Discover* and *Evaluate* once to see prospects, then switch the master automation on when you are happy with the limits.

Full guides: [docs/INSTALLATION.md](docs/INSTALLATION.md), [docs/CONFIGURATION.md](docs/CONFIGURATION.md), [docs/OPERATIONS.md](docs/OPERATIONS.md), [docs/COMPLIANCE.md](docs/COMPLIANCE.md), [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/EXTENDING.md](docs/EXTENDING.md), [docs/TESTING.md](docs/TESTING.md).

## Operating principle

The engine optimises for genuine, relevant, editorially appropriate links rather than link counts. It never bypasses CAPTCHAs, logins, rate limits or robots directives, never mass-posts comments or profiles, and never claims that a backlink guarantees a ranking improvement.

## Development

```bash
bash bin/lint.sh        # php -l on every PHP file, node --check on every JS module, JSON validation
bash bin/test.sh        # PHPUnit unit suite (needs vendor/bin/phpunit or bin/phpunit.phar)
bash bin/build-zip.sh   # distributable zip in dist/
```

See [docs/TESTING.md](docs/TESTING.md) for the end-to-end run against a local WordPress install with fixture websites.
