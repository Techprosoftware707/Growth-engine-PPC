# Pure Peptides Canada — SEO, Backlink & Site-Quality Engagement: Execution Report

Date: 2026-11-07 · Site: https://purepeptidescanada.com/ · Repo: Techprosoftware707/Growth-engine-PPC (branch `claude/laughing-clarke-dcr7ql`)
Full audit: `ops/audit/AUDIT.md` (65 findings) · Append-only log: `ops/log/execution-log.md`

## Executive summary

The engagement ran all nine phases. The site itself is healthy — every tested journey works, performance problems are infrastructure-level, and compliance framing (research-use-only) is consistent. The strategic gap is off-site: **4 referring domains and 3 referral sessions in 28 days** against rankings of position 31–69 for non-brand money queries. The work therefore split into (a) fixing everything on-site that was broken or suboptimal — 40+ audit fixes executed and verified live, (b) building the linkable-asset and schema foundation AI/search engines reward, and (c) producing a verified, deduplicated database of 88 legitimate backlink opportunities with a ready-to-run submission queue — of which everything that could be done without the owner's identity/inbox was done.

## Baseline (2026-11-07, for before/after comparison)

| Metric | Value |
|---|---|
| Referring domains | 4 (2 dofollow: doserly.com DR18, thepeptideco.online DR8) |
| Backlinks | 625 (603 = one nofollow aggregator, gotpeptide.ca) |
| SE Ranking domain authority | 9 |
| GSC 28d | 827 clicks · 20,652 impressions · avg pos 32.3 · ~95% brand |
| GA4 28d sessions | organic 1,156 · direct 783 · AI-assistant 69 · **referral 3** |
| Page sitemap entries | 46 (now 30 after placeholder cleanup) |

## What was changed on the live site (all verified by fresh fetch after change)

**Content & linkable assets**
- Published `/peptide-glossary/` — 55-term research glossary with DefinedTermSet/DefinedTerm JSON-LD, citations, research-use-only framing; added to the Research Library menu and /education/ hub; old 46-term glossary post canonicalized to it; sitemap refreshed.
- 6 dead external citations repaired across 5 posts (archive.org, PubMed, NLM Digital Collections, EBI ChEBI, pharmacologyeducation.org replacements — each target verified 200 before linking).
- 17 generic "Link" anchors rewritten to descriptive anchors across 3 posts; stale product link in the reusable COA block repointed to the live blend product.
- 3 orphan pages de-orphaned (/editorial-standards/, /referral-program/, /peptides-recherche-quebec/) with natural in-context links; pillar page now links /buy-research-peptides-canada/ in its "short answer" block (H-05 internal-link boost).
- Terms of Service and Shipping & Returns now carry internal links (previously zero outgoing links).

**SEO meta & settings**
- 7 titles/descriptions rewritten on GSC evidence (incl. /about/ at pos 9.1 with 1,508 impressions); 4 over-long titles shortened; /my-account/ no longer titled "Reset Your Password".
- 16 placeholder province/city pages (IDs 160–176 minus Toronto) drafted out of the index — sitemap 46→30, every URL still 301s to /shipping-across-canada/ (no 404s).
- Rank Math monthly report email fixed (was going to the wrong domain); author + product-tag sitemaps disabled; education category given a meta description; 2 products given unique descriptions/focus keywords (9 others verified already done).
- M-13 duplicate URL pairs: all three now 301 to canonical (shipping-across-canada-2, cjc1295-lpamorelin typo slug, ll37).

**Structured data (fix-10/20/21 — root cause found)**
- /faq/ emitted an invalid `[WebPage,FAQPage]` node with empty mainEntity alongside the valid theme FAQPage: stray schema meta deleted; live page now has exactly one FAQPage (30 questions).
- Pages default to "no rich snippet" site-wide, which collapses Rank Math's whole graph to BreadcrumbList. Fixed with per-page Article schema (Organization author, real dates via RM variables) on /lab-testing/, /education/, /shipping-returns/, /buy-research-peptides-canada/, /peptide-glossary/ — all five verified emitting the full Place/Organization/WebSite/WebPage/Article graph.
- /peptide-coa-guide/ and /hplc-testing-guide/ Article nodes now carry author + datePublished/dateModified.

**Privacy & UX**
- PIPEDA privacy policy drafted and created as **draft page 11886** (publication is an owner decision — see pending list).
- CLS fix: explicit dimensions for review flag icons via Additional CSS; verified flags no longer appear in layout-shift sources.

## Phase 8 re-test (after all changes)

30/30 page checks pass (15 pages × desktop/mobile): HTTP 200, zero mobile horizontal overflow, zero console errors (one benign report-only CSP notice from an embedded Google News widget). Full purchase journey verified: variation select → add to cart → cart ($93 subtotal correct) → checkout renders with billing form and 3 payment gateways. No payment was submitted. WooCommerce untouched throughout.

## Backlink program (Phases 2–4)

- **Database**: `ops/backlinks/opportunities.csv|json` — 88 verified rows (112 researched, 25 excluded with reasons in `excluded.json`): 15 tier-1, 13 tier-2, 36 tier-3, 24 tier-4; every submission URL HTTP-verified; 81/88 free.
- **Playbook**: `ops/backlinks/TOP-25.md` — step-by-step instructions with the standard NAP kit and compliance rules (never a health/medical category; RUO framing everywhere).
- **Competitor gap** (`ops/backlinks/competitor-gap.md`): competitors' link sources are mostly PBN/Money-Robot networks — documented as AVOID; one clean replicable source found and used.
- **Executed now**: secretsearchenginelabs.com URL submission — accepted ("added to the queue"), logged in `ops/submissions/submitted.csv`.
- **Queued for owner** (`ops/submissions/PENDING-QUEUE.md`): everything requiring an account at hi@purepeptidescanada.com, a CAPTCHA, identity verification, or a named human contact — GBP, CTI, PubChem, Thomasnet, Trustpilot, Biotechgate, SciCrunch, Crunchbase, LinkedIn, the HARO-class source services, YellowPages and the citation set. Each is ~10 minutes with the TOP-25 steps. Per the engagement rules, no CAPTCHAs were bypassed, no identities invented, no emails sent.

## Monitoring (Phase 7)

`ops/monitoring/backlink-monitor.csv` (all 4 refdomains + pending SSEL row) and `MONITORING.md` (weekly/monthly cadence, KPIs — referral sessions is the headline KPI, alert conditions). SE Ranking balance: 71,659 units; **the API key expires 2026-11-11 — renew it before the first monthly pull**.

## Blocked / owner decisions (nothing here was worked around)

1. **PPC Link Growth Engine plugin install** on the live site — both install routes denied by the session permission system. Zip is built and tested: `dist/ppc-link-growth-engine-1.0.0.zip` (sha256 90d8dd76…1302dd). It automates discovery→scoring→submissions→monitoring once installed.
2. **Privacy policy publication** — draft page 11886 needs owner/legal sign-off, then: remove `privacy-policy` from Rank Math redirect rule 1 sources and publish.
3. **301 redirect for the old glossary URL** — redirect creation was denied; canonical tag is in place as the fallback.
4. **Contact form (fix-25)** — WPForms write abilities are disabled server-side. Spec ready: name/email/message required, notify hi@purepeptidescanada.com, embed on page 156.
5. **Infrastructure (manual)**: Cloudflare APO/HTML edge cache (TTFB 1.1–5s today), image conversion to WebP/Polish (448KB PNG hero), HSTS header, M-05 product schema name (needs a theme filter — Rank Math free hardcodes it), age-gate modal CLS on cold loads (new minor finding).
6. **Submission queue** — the owner works `ops/submissions/PENDING-QUEUE.md` top-down; expected outcome is 15–25 quality listings/profiles in the first pass, which is what moves the 4-refdomain baseline.

## Verification trail

Every fix above was re-tested live after application (fresh fetch, no query-string cache bypass) and logged with evidence in `ops/log/execution-log.md`. Research evidence files live under the session scratchpad `backlink-research/` folders; database caveats (truncated documents-category rows: SlideShare/Scribd-class platforms not yet in the DB) are recorded in the workflow's merged.json handoff notes.
