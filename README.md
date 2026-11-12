# PPC Link Growth Engine

A WordPress plugin that gives **Pure Peptides Canada** (https://purepeptidescanada.com/) an automated, compliance-first backlink growth platform: it discovers websites that could legitimately link to the business, qualifies and scores them on a 100-point model, finds the actual submission page or editorial contact, prepares personalised listing packages and outreach emails, submits listings where that is explicitly authorised, and monitors every acquired backlink.

The plugin lives in `ppc-link-growth-engine/` and installs like any other plugin. Everything else in this repository (tests, docs, build scripts) supports development.

## What it does

| Area | Capability |
|---|---|
| Discovery | Rotating search queries (business/scientific directories, associations, publications, education, resource pages, blogs, networks, podcasts, media), a curated Canadian seed catalog, competitor-backlink mining through SE Ranking/Ahrefs/Semrush, unlinked brand-mention detection and broken-link detection on relevant resource pages. |
| Qualification | Polite crawling (robots.txt, crawl delay, honest user agent), site profiling, topical + geographic relevance against a laboratory-research keyword taxonomy, editorial-quality and audience signals, provider metrics (Domain InLink Rank, DR, DA, Authority Score, organic traffic, keywords, referring domains) and a spam/PBN/link-farm/misuse risk analysis. |
| Scoring | 100-point weighted score (topical relevance 30, organic traffic 20, editorial quality 15, audience 10, authority 10, indexability & placement 10, risk 5) with confidence (how much of the score is backed by real data), classification bands and automatic rejection of link farms, parked/hacked sites and irrelevant sites. Metrics are never fabricated: missing providers are reported as missing. |
| Submissions | Detects listing forms, maps required fields, picks the right category, generates unique verified-fact descriptions per directory, and builds complete packages. In the default **auto** mode the engine submits by itself whenever every safety gate passes: the site scores at or above the quality threshold (70), exposes a public listing form (POST, no CAPTCHA, no login, no required upload), robots.txt allows the action URL and limits permit. Stricter modes ("authorized only", "manual") are one setting away, and each site can be set to *Always allow* or *Never automate*. Blog comment and forum forms are never posted. Everything the gates refuse goes to a review queue with the full package and instructions. Confirmed listings are verified and recorded as backlinks. |
| Outreach | Eight personalised approaches (resource page, directory inclusion, partnership, expert commentary, educational contribution, editorial mention, broken-link replacement, unlinked mention). Drafts are generated automatically into evergreen "Auto" campaigns for qualified editorial prospects; sending stays behind CASL/CAN-SPAM gates (sender identity + postal address + unsubscribe) and individual approval of every message by default. *Outreach autosend* (an explicit, confirmed opt-in) sends follow-ups in campaigns set to auto-approve them; auto-drafted first emails are also sent without review only if you additionally turn off *Require individual approval*. Suppression lists, send windows, daily limits, follow-ups, reply logging and an inbound-email webhook included. |
| Content | Verified-fact registry (seeded from the live site), a claims filter that blocks therapeutic/human-use/purity/superlative language, research-use-only positioning in every piece of copy. Optional AI personalisation through the Anthropic API, always filtered. |
| Monitoring | Scheduled backlink checks (status, anchor, rel, placement, indexability, changes, two-strike loss detection), imports from SE Ranking/Ahrefs/Semrush and GA4 referral sources, in-dashboard notifications and email alerts/digests. |
| Integrations | SE Ranking Data API (primary), Ahrefs v3, Semrush, Moz, Google Search Console and GA4 (service account) with a Site Kit bridge for dashboard numbers, Google Programmable Search / Brave / Serper for discovery, RDAP for domain age, Anthropic Claude (optional). |
| Grow now | One button on the Overview runs every growth stage once, in a fixed order, as a resumable run: storefront check and setup checks, verified-opportunity import, site audit, IndexNow, internal-link suggestions, discovery, mention and broken-link scans, evaluation, opportunities, packaging, submission, listing verification, outreach drafting, backlink checks, imports and metrics. Each step shows what it did; the end lists what needs you, with links. The run never changes a setting, never sends an email (drafts wait for approval) and never edits site content. If the tab is closed, the scheduler continues the run. |
| Storefront compliance | A daily check (also on every growth run and whenever products, product categories or tags, or menus change) reads the store for consumer-use wording that contradicts the research-use-only positioning (weight loss, anti-aging, tanning, skincare, nasal sprays, capsules, syringes, dosing…). While it blocks, automated submissions become owner tasks and outreach sending pauses; drafting and packaging continue. How the store presents itself is the owner's legal decision: the owner can acknowledge the exact findings, and any change in them pauses again. |
| On-site SEO | A read-only audit of published posts, pages and products (Rank Math titles, descriptions and robots, duplicates, lengths, H1s, image alt text, thin content, broken internal links, orphan pages), IndexNow notifications for new and changed URLs (production site only), and internal-link suggestions that change content only when you click *Apply* (WordPress keeps a revision). |
| Verified opportunities | 595 researched listing and outreach opportunities bundled in `data/verified-opportunities.json` are imported as owner tasks: one manual task per item with a listing kit, steps, tier and conditions, filtered on the Submissions page. The import makes no HTTP requests and contacts nobody. |
| Dashboard | Overview (with *Grow now*), Prospect Explorer, Submission Queue (with owner tasks), Outreach Manager, Backlink Monitor, On-site SEO, Automation Center, Settings and Logs — a dependency-free admin app with light/dark themes and accessible SVG charts. |
| Safety | Capability checks, REST nonces, sanitised input and escaped output, encrypted credentials (keyed from the site salts, or wp-config constants), redacted logs, per-host rate limiting, duplicate prevention, locks, retries, audit log of every action, pre-migration table backups. |

## Quick start

1. Build or download the plugin zip: `bash bin/build-zip.sh` → `dist/ppc-link-growth-engine-<version>.zip`.
2. Upload it in WordPress under **Plugins → Add New → Upload Plugin** and activate.
3. Open **Link Growth → Settings**, confirm the business profile, add your SE Ranking API key, a search provider key, and (optionally) the Google service-account JSON.
4. Click **Grow now** on the Overview to run every stage once and see what needs you (the storefront check result, owner tasks, drafts to approve, SEO fixes, link suggestions).
5. That's it: automation is on by default. The engine discovers, scores, packages and submits to qualifying sites on its own schedule within the configured limits; watch it from the Overview and the review queue. To send outreach emails without per-message review too, complete the sender identity, enable *Outreach autosend* and turn off *Require individual approval* in Settings → Outreach (you are asked to confirm, since emails go out in your name). Prefer a human in the loop? Switch the submission mode to "authorized only" or "manual" in Settings → Automation, or set single sites to *Never automate* in their prospect drawer.

Full guides: [docs/INSTALLATION.md](docs/INSTALLATION.md), [docs/CONFIGURATION.md](docs/CONFIGURATION.md), [docs/OPERATIONS.md](docs/OPERATIONS.md), [docs/COMPLIANCE.md](docs/COMPLIANCE.md), [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/EXTENDING.md](docs/EXTENDING.md), [docs/TESTING.md](docs/TESTING.md).

## Operating principle

The engine optimises for genuine, relevant, editorially appropriate links rather than link counts. It never bypasses CAPTCHAs, logins, rate limits or robots directives, never mass-posts comments or profiles, never edits site content without an explicit click, never shows a metric it could not measure (it says "unknown" or "not configured" instead), and never claims that a backlink guarantees a ranking improvement.

## Development

```bash
bash bin/lint.sh        # php -l on every PHP file, node --check on every JS module, JSON validation
bash bin/test.sh        # PHPUnit unit suite (needs vendor/bin/phpunit or bin/phpunit.phar)
bash bin/build-zip.sh   # distributable zip in dist/
```

See [docs/TESTING.md](docs/TESTING.md) for the end-to-end run against a local WordPress install with fixture websites.
