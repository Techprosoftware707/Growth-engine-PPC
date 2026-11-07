# Backlink & SEO Monitoring Plan — Pure Peptides Canada

Baseline (2026-11-07, `ops/reports/baseline-2026-11-07.json`): 625 backlinks / 4 referring domains (2 dofollow) / SE Ranking domain authority 9 · GSC 28d: 827 clicks, 20,652 impressions, avg pos 32.3 (~95% brand) · GA4 28d sessions: organic 1,156, direct 783, AI-assistant 69, referral 3.

## What to watch, and with what
1. **New/lost backlinks** — SE Ranking backlinks summary (100 credits) + list (1 credit/row) monthly; diff against `backlink-monitor.csv`, append a row per new link with anchor/rel/first_seen, mark losses `lost` with date. 71,659 credits remain (key expires 2026-11-11 — renew before monthly run).
2. **Submission outcomes** — for each row in `ops/submissions/submitted.csv` and each queue item the owner completes: fetch the listing URL, confirm the link is present and crawlable, record anchor/rel. First check: 2026-11-21.
3. **Rankings/traffic** — GSC (Site Kit `wp_sitekit_gsc_query_performance`) 28d vs baseline: non-brand clicks, striking-distance queries from `ops/audit/data/gsc-pages-28d.tsv`; GA4 referral + AI-assistant sessions (the referral number is the KPI this whole engagement exists to move: baseline 3).
4. **Index health** — page-sitemap.xml count (30 at close), GSC URL inspection for /peptide-glossary/ and /buy-research-peptides-canada/ (H-05), 404 log via Rank Math.

## Automation
The PPC Link Growth Engine plugin (built in this repo, `dist/ppc-link-growth-engine-1.0.0.zip`) automates all four loops once installed (install currently pending user approval): link checker with anchor/rel/placement/indexability deltas, loss alerts, provider imports (SE Ranking primary), GA4 referral import, and the monitoring dashboard. Until then this folder is the manual log.

## Cadence
- Weekly (first month): submission outcomes + GSC deltas.
- Monthly: SE Ranking pull + full diff; refresh `backlink-monitor.csv`; snapshot into `ops/reports/`.
- Alert conditions: any lost dofollow link; aggregator refdomain drop; GSC coverage error on money pages; DA drop ≥2.
