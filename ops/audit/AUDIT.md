# Master Site Audit — purepeptidescanada.com

Generated: 2026-11-07 05:41Z · Audit lead consolidation of 6 auditor reports (crawl, journeys, schema, perf, wpdata, links)
Raw findings: 71 → 65 after dedup (6 merged). Raw data: `ops/audit/data/` (full evidence under the audit-run scratchpad).

## Executive summary

**Site health:** Structurally sound and conversion-ready store — clean crawl (318/318 sitemap URLs resolve 200, zero JS page errors, working purchase funnel, valid schema core, zero mixed content) — but held back by slow uncached HTML (no edge cache, TTFB 1.1–5s), PNG-only images, a missing privacy policy, and near-zero non-brand organic visibility (~95% of clicks are brand queries).

| Severity | Count |
|---|---|
| High | 6 |
| Medium | 18 |
| Low | 19 |
| Info | 22 |
| **Total** | **65** |

Automatable-now fixes (executable via `mcp__wp-api__*` tools): **24** queued in `fix-queue.json`; **22** items need manual/owner action (infra, theme/plugin code, legal copy, business decisions).

**Tooling basis for "automatable now" flags** (verified via ToolSearch against the live wp-api tool list): Rank Math post meta (`wp_rm_update_post_seo`, `wp_update_post_meta`), redirections (`wp_rm_create_redirect`, `wp_rm_update_redirect`, `wp_rm_delete_redirects`), content edits (`wp_update_page`, `wp_update_post`, `wp_replace_in_post`, `wp_create_page`, `wp_delete_post`), taxonomy (`wp_update_category`), settings (`wp_ability_rank_math_set_sitemap_settings`, `set_module_status`, `set_post_type_seo_settings`, `set_global_seo_settings`, `set_plugin_preferences`), custom CSS (`wp_update_custom_css`), WPForms (`wp_ability_wpforms_create_form`/`add_field`). No wp-api tool exists for Cloudflare config, theme/plugin PHP templates, Site Kit module settings, or security headers — those are manual.

---

## HIGH

### H-01 — Footer "Privacy policy" link redirects to homepage; no privacy policy page exists
- Sources: J-01 + LINK-01 (merged) · Area: legal/trust, internal links
- URLs: `https://purepeptidescanada.com/privacy-policy` (linked site-wide in footer; also from /wholesale-terms/ and /referral-program/)
- Evidence: GET /privacy-policy → 301 → homepage (slash and no-slash variants; confirmed by Playwright footer clicks on desktop + mobile). Cause confirmed site-side: Rank Math redirect **rule id 1** (active 301 → `/`, 2,182 hits, last hit 2026-11-07) lists `privacy-policy` among 53 exact sources that are otherwise bot probes (.env, .git/HEAD, phpmyadmin…). All 12 other footer links return 200.
- Impact: An e-commerce store collecting checkout PII has no reachable privacy policy — legal/compliance exposure (PIPEDA), trust signal loss, and a broken promise on every page.
- Fix: Draft privacy policy copy (owner/legal approval), publish page at slug `privacy-policy` via `wp_create_page`, then remove the `privacy-policy` pattern from rule 1's sources via `wp_rm_update_redirect` (keep the other 52 probe patterns). Footer href already points at /privacy-policy, so no theme edit needed.
- Automatable now: **yes** (mechanics; policy copy itself needs owner approval — see manual_review MR-05)

### H-02 — No HTML edge caching: TTFB 1.1–5s on every page, cold loads 13–14s
- Sources: PERF-01 + crawl-02 + J-04 (merged) · Area: performance/infrastructure
- URLs: site-wide; worst: /lyophilized-peptides-explained/ (17.4s), /cjc-1295-dac-research-guide/ (16.0s), /education/ (20.6s once), checkout TTFB 4.9s mid-session
- Evidence: Every HTML response is `cf-cache-status: DYNAMIC`; homepage HTML byte size varies per request (origin renders every view). Origin emits `cf-edge-cache: cache,platform=wordpress` (the Cloudflare APO plugin signal) yet responses stay DYNAMIC → APO not active on the zone. TTFB medians over 5 curl runs: home 2631ms, shop 1189ms, product 1258ms, guide 1387ms; Playwright nav-timing confirms (528–2284ms, independent of proxy). Full-crawl timing: 85/318 pages >3s, 17 pages >5s. First-load DOMContentLoaded 13.6s desktop / 13.8s mobile; curl cold TTFB 14.29s. TLS setup only ~80–120ms, so the wait is origin processing.
- Impact: The single largest drag on conversions and Core Web Vitals; checkout (the money page) carries 4.9s TTFB; real-user mobile LCP almost certainly fails "good".
- Fix: Activate Cloudflare APO (origin signal already present — only the Cloudflare-side subscription/toggle is missing) or a Cache-Everything rule with cookie bypass for cart/checkout/login, plus an origin page cache as fallback; investigate slow uncached PHP render.
- Automatable now: **no** (Cloudflare dashboard / infra; plugin install is a site-change decision)

### H-03 — PNG-only images, zero WebP/AVIF; 448KB LCP hero
- Source: PERF-02 · Area: performance/images
- URLs: site-wide; hero: `hero-vial.png` 448KB displayed at 420px; `bpc-157.png` 652,848 bytes
- Evidence: Playwright capture: homepage images = 10 PNG / 1,029,635 bytes + SVG; zero webp/avif on home, shop, product. Re-request with `Accept: image/avif,image/webp` still returns `image/png` (no negotiation → Cloudflare Polish off). Images are 1008KB of the homepage's 1727KB transfer; hero-vial.png is the measured LCP element.
- Impact: 60–80% wasted image bytes on every view; directly inflates LCP (lab: shop 3372ms already fails 2.5s "good").
- Fix: Enable Cloudflare Polish (lossy + WebP) or an image-optimization plugin; recompress hero to <60KB WebP at display size.
- Automatable now: **no** (Cloudflare dashboard; plugin install = site-change decision)

### H-04 — No HSTS header on a store taking payment details
- Source: PERF-03 · Area: security/headers
- URLs: all responses incl. /checkout/, /cart/, static assets
- Evidence: grep over all captured response headers: zero `strict-transport-security`. http:// does 301 to https (good), but first/expired visits remain downgrade-interceptable.
- Impact: MITM/downgrade exposure on an e-commerce checkout; also an easy security-score win.
- Fix: Cloudflare → SSL/TLS → Edge Certificates → HSTS (start max-age=86400, raise to 31536000 + includeSubDomains), or send from origin.
- Automatable now: **no** (Cloudflare/server config)

### H-05 — Key commercial landing page unknown to Google
- Source: wp-01 · Area: indexing
- URL: `https://purepeptidescanada.com/buy-research-peptides-canada/`
- Evidence: Live fetch 200, but GSC URL Inspection: verdict NEUTRAL, "URL is unknown to Google", no lastCrawlTime, no sitemap reference. All 7 other inspected key URLs are "Submitted and indexed" (crawls 2026-10-21 → 2026-11-07). Corroborating: links auditor's sitemap pull (327 URLs) also shows glossary-type gaps, and this page is one of the 16 striking-distance targets' natural money page for "buy peptides canada" (625 imp / pos 51.5, 0 clicks).
- Impact: The page built for the site's #1 non-brand commercial query cannot rank — it is invisible to Google.
- Fix: Check robots/sitemap exclusion via `wp_rm_get_post_seo` / sitemap settings `exclude_posts`; clear it via `wp_rm_update_post_seo`; confirm it appears in page-sitemap.xml; trigger IndexNow (bing_post_types already includes page); add internal links from /, /shop/.
- Automatable now: **yes**

### H-06 — Organic visibility is ~95% brand-only; all non-brand commercial queries rank page 3–7
- Source: wp-02 · Area: organic visibility (strategic)
- URLs: site-wide; homepage holds 746 of ~830 page-level clicks (90%)
- Evidence: GSC 28d (500 query rows): 694 clicks / 11,654 impressions. Brand variants = ~95% of clicks ('pure peptides canada' 296, 'pure peptides' 225…). Non-brand: 'buy peptides canada' 625 imp/0 clicks/pos 51.5; 'peptides canada' 388 imp/pos 68.6; 'canada peptides' 196/52.4; 'glutathione peptide canada' 186/46.6; 'ghk-cu canada' 120/44.0; 'bpc 157 canada' 107/31.4. GA4: Referral = 3 sessions in 28d (no authority inflow). DA 9, 4 refdomains (baseline log).
- Impact: The store depends almost entirely on people who already know the brand; the growth engine's core problem.
- Fix: Authority building (backlink program, other phases), on-page targeting of the 16 striking-distance pages (M-12), fixing H-05. Meta edits automatable once copy approved; the ranking problem itself is not.
- Automatable now: **no** (strategy; sub-tasks in M-12/H-05 are)

---

## MEDIUM

### M-01 — 16 placeholder pages in sitemap all 301 to /shipping-across-canada/
- Source: crawl-01 · Area: sitemap/redirects
- URLs: /peptides-ontario/, /peptides-quebec/, /peptides-british-columbia/, /peptides-alberta/ … (16 province/city pages, page IDs 160–176) listed in page-sitemap.xml
- Evidence: Crawl of all 318 sitemap URLs: exactly these 16 return first-status 301 → /shipping-across-canada/. `wp_rm_list_redirects` confirms one active rule (id 16, 303 hits) with these 16 exact sources; `wp_list_pages` confirms all 16 still published, so Rank Math keeps them in the sitemap.
- Impact: Sitemap lists non-final URLs — crawl-budget waste and a sitemap-quality signal against the site.
- Fix: Set the 16 pages to draft via `wp_update_page` (redirect rule 16 keeps the URLs resolving; no 404 risk), or per-page noindex/exclude-from-sitemap via `wp_rm_update_post_seo`.
- Automatable now: **yes**

### M-02 — /my-account/ title tag is "Reset Your Password | Pure Peptides Canada"
- Source: J-02 · Area: metadata/UX
- URL: `https://purepeptidescanada.com/my-account/`
- Evidence: `<title>` is "Reset Your Password…" while the page renders the login form (form.woocommerce-form-login with #username/#password); identical on desktop and mobile.
- Impact: Wrong tab title for users and wrong indexed title.
- Fix: `wp_rm_update_post_seo` on the My Account page → "My Account | Pure Peptides Canada".
- Automatable now: **yes**

### M-03 — Contact page has no contact form (only live chat + mailto)
- Source: J-03 · Area: journeys/contact
- URL: `https://purepeptidescanada.com/contact/` (page ID 156)
- Evidence: DOM on both viewports: the only `<form>` is the header search. `wp_ability_wpforms_list_forms` → {forms:[], total:0}, yet WPForms assets are enqueued site-wide. Users without a configured mail client have only chat.
- Impact: Lost contact/lead path; dead-weight JS/CSS from a formless plugin on every page.
- Fix: Create a form (name/email/message, required) via `wp_ability_wpforms_create_form` + `add_field` and embed on page 156 — owner should confirm wanted fields + notification address. Alternatively dequeue WPForms while unused (plugin-level, manual).
- Automatable now: **yes** (form creation/embed; owner confirms fields first)

### M-04 — llms.txt serves 2 dead + 1 redirected link; its source is NOT Rank Math (module disabled)
- Sources: SCH-01 + wp-10 (merged; SCH-01 downgraded from high — 2 links affected) · Area: AI surface
- URL: `https://purepeptidescanada.com/llms.txt`
- Evidence: llms.txt (200, 3,499 bytes) cites /blog/what-is-bpc-157 and /blog/glp-1-research-peptides-explained — both 404 (fetched twice); /blog/what-are-bioregulator-peptides is a 301. Live equivalents exist (/bpc-157-safety-research/, /glp-1-peptides-research-guide/, /what-are-bioregulator-peptides/). **Conflict found during dedup:** `wp_ability_rank_math_get_llms_txt` reports enabled=false, content empty — so the served file is a static file or another plugin's output, not Rank Math's.
- Impact: AI agents (72 GA4 sessions/28d from the AI Assistant channel, converting) follow the file into 404s; and the file cannot be edited via the Rank Math tools until its source is identified.
- Fix: Locate the file's source (static file in webroot / other plugin) and fix the 3 references; optionally enable Rank Math's llms-txt module (`wp_ability_rank_math_set_module_status`) as the managed replacement — verify which output wins before relying on it.
- Automatable now: **no** (source unidentified; module enable alone is unverified to take effect)

### M-05 — Product schema `name` is the keyword-stuffed SEO title, not the product name
- Source: SCH-02 · Area: structured data/product
- URLs: /product/bpc-157/, /product/semaglutide/, /product/glow-blend/ (all 3 sampled; pattern is template-level)
- Evidence: Product.name = "Buy BPC-157 Canada | HPLC-Tested Research Peptide + COA" vs visible H1 "BPC-157"; same mismatch on all 3.
- Impact: Schema/page mismatch degrades product rich results and merchant-listing eligibility.
- Fix: Set the product post type's schema name variable to `%title%` via `wp_ability_rank_math_set_post_type_seo_settings` (inspect current value with `get_settings` first).
- Automatable now: **yes**

### M-06 — Duplicate BreadcrumbList with conflicting trails on 16 of 19 audited pages
- Source: SCH-03 · Area: structured data/breadcrumbs
- URLs: e.g. /product/bpc-157/ ("Home > Recovery & Repair > BPC-157" vs "Home > Shop > BPC-157"); blog posts; /peptide-coa-guide/
- Evidence: Rank Math @graph BreadcrumbList + a second standalone custom-injected block on 16/19 pages, with different trails.
- Impact: Ambiguous breadcrumb signal; Google may pick the wrong trail or ignore both.
- Fix: Identify the emitter of the standalone block (theme or plugin), then keep one source of truth (remove the custom block or disable Rank Math breadcrumb JSON-LD).
- Automatable now: **no** (emitter unidentified; likely theme/plugin code)

### M-07 — /faq/ carries an invalid second FAQPage node (empty mainEntity)
- Source: SCH-04 · Area: structured data/FAQ
- URL: `https://purepeptidescanada.com/faq/`
- Evidence: Rank Math WebPage node typed `[WebPage,FAQPage]` with `"mainEntity": ""` alongside a valid standalone FAQPage (30 questions, all visible as `<details>`).
- Impact: Invalid node risks FAQ rich-result eligibility on the page that's best positioned for it.
- Fix: Inspect per-post schema via `wp_ability_rank_math_get_post_schema`, then clear the FAQPage type from the WebPage node via `wp_update_post_meta` (rank_math rich-snippet key), leaving the valid 30-question block.
- Automatable now: **yes**

### M-08 — Product schema missing `brand` on all sampled products
- Source: SCH-05 · Area: structured data/product
- URLs: /product/bpc-157/, /product/semaglutide/, /product/glow-blend/
- Evidence: No `brand` key in Product nodes (keys: name, description, sku, category, image, aggregateRating, review, offers, mainEntityOfPage).
- Impact: Google Merchant-listing warning; weaker product entity.
- Fix: Add brand "Pure Peptides Canada" via Rank Math's WooCommerce brand setting — no clearly matching wp-api settings tool was found (set_post_type_seo_settings covers title/desc/schema type only); check `wp_ability_rank_math_set_plugin_preferences` / `wp_list_disabled_tools`, else one-line Rank Math dashboard change.
- Automatable now: **no** (no verified tool path; re-check before manual)

### M-09 — 10 render-blocking stylesheets + 4 synchronous head scripts on every page
- Source: PERF-04 · Area: performance/render
- URLs: site-wide (home 10 CSS + 4 head sync JS; shop 8+4; product 9+4)
- Evidence: Head sync scripts: jquery.min.js (31.7KB), jquery-migrate, blockUI, js.cookie — none deferred. Checkout/photoswipe CSS loads site-wide.
- Impact: Blocks first paint on every page; compounds H-02's slow TTFB.
- Fix: Defer/footer the jQuery stack, dequeue page-specific CSS, inline critical CSS — theme/optimization-plugin work.
- Automatable now: **no** (theme/plugin code; plugin install = site-change decision)

### M-10 — ~280KB of Google third-party JS on every page incl. a misconfigured Subscribe-with-Google widget on cart/checkout
- Sources: PERF-05 + J-05 (merged) · Area: performance/third-party
- URLs: site-wide incl. /cart/, /checkout/
- Evidence: gtag.js 176.6KB + gstatic SwG bundle 65.8KB + news.google.com publisher.js 38KB on every page. The SwG widget (`div[google-add-preferred-source-btn]` in theme footer) embeds a news.google.com iframe with `publicationId=publication-id-free` (placeholder), throws CSP frame-ancestors console errors on /cart/, and retries aggressively when blocked. JS totals 450–488KB across 34–49 files; page weight home 1727KB/86 req.
- Impact: Dead-weight bytes and console noise on transactional pages; a news-subscription widget has no business on a WooCommerce checkout.
- Fix: Verify/remove the SwG widget (lives in theme footer markup — theme code) and disable Site Kit's Reader-Revenue/SwG module (no Site Kit settings write tool available); audit JS concatenation/defer.
- Automatable now: **no** (theme code + Site Kit settings not tool-reachable)

### M-11 — Lab CWV borderline: shop LCP 3372ms (fails 2.5s), CLS ~0.10 on shop/product
- Source: PERF-06 · Area: performance/CWV
- URLs: /shop/ (LCP 3372ms, CLS 0.0997), /product/retatrutide/ (LCP 2220ms, CLS 0.0981), / (LCP 2076ms, CLS 0.0024)
- Evidence: PerformanceObserver lab runs, desktop, fast connection — field mobile will be worse given TTFB. LCP elements: hero-vial.png (448KB PNG), product PNG (cf-cache MISS first load). CLS driven by the reviews widget (see L-10).
- Impact: Core Web Vitals likely failing in field data → ranking and conversion drag.
- Fix: Consequence finding — resolved by H-02 (edge cache), H-03 (WebP) and L-10 (CLS reservation); validate with CrUX/GSC afterwards.
- Automatable now: **no** (via parent fixes)

### M-12 — 16 striking-distance pages (impr>200, pos>15) are the highest-leverage SEO targets; 10 earned 0 clicks in 28d
- Sources: wp-03 + wp-04 (merged) · Area: striking distance/CTR
- URLs: home (11,579 imp/pos 27.4), /shop/ (1,824/60.5), /product/ghk-cu/ (970/57.7), /peptide-coa-guide/ (832/43.2), /product/bpc157tb4-blend/ (636/38.5), /product/bpc-157/ (493/50.1), /product-category/peptide-blends/ (426/34.8), /product/klow-blend/ (406/37.7), /retatrutide-canada/ (390/20.5), /hplc-testing-guide/ (383/37.0), /shipping-across-canada/ (324/32.9), /product/semaglutide/ (264/47.6), /product/retatrutide/ (223/32.9), /product/cjc-1295-ipamorelin/ (220/45.4), /product/tirzepatide/ (207/41.1), /product/gsh-glutathione/ (201/44.3)
- Evidence: GSC 28d page rows (full list in `data/gsc-pages-28d.tsv`). Only rewrite-candidate query (imp>300, CTR<1%): 'buy peptides canada' — a position problem, not a title problem. Near-candidate: /about/ 1,508 imp at pos 9.1, CTR 1.33%.
- Impact: This is where rankings move first; targets for content depth, internal links and meta.
- Fix: Title/meta targeting via `wp_rm_update_post_seo` (automatable once copy approved — /about/ rewrite queued); content depth + internal links from /, /shop/ need drafting (Phase 6).
- Automatable now: **partial** (meta edits yes; content no)

### M-13 — Three duplicate live URL pairs both indexed, splitting impressions (one typo slug)
- Source: wp-05 · Area: duplicate content
- URLs: /shipping-across-canada/ (324 imp) vs /shipping-across-canada-2/ (53 imp); /product/cjc-1295-ipamorelin/ (220) vs /product/cjc1295-lpamorelin/ (46 — typo 'lpamorelin'); /product/ll-37/ (48) vs /product/ll37/ (2)
- Evidence: GSC 28d page rows show both members of each pair collecting impressions.
- Impact: Split ranking signals on money pages.
- Fix: Confirm the canonical of each pair (read-only check), 301 the duplicate via `wp_rm_create_redirect`, unpublish the duplicate page/product.
- Automatable now: **yes**

### M-14 — Rank Math monthly SEO report emails go to the wrong domain
- Source: wp-06 · Area: configuration
- URL: wp-admin → Rank Math general settings
- Evidence: `console_email_send_to='hi@peptidescanada.com'` (monthly, enabled) vs the site's own contact 'hi@purepeptidescanada.com'. If peptidescanada.com is third-party, ranking reports leak to it.
- Impact: Possible data leak to an unowned domain; owner never sees reports.
- Fix: Set to hi@purepeptidescanada.com via `wp_ability_rank_math_set_plugin_preferences` (confirm field coverage; confirm intended address with owner).
- Automatable now: **yes** (verify-first)

### M-15 — 13 published commerce pages with 'bad' Rank Math SEO scores; HGH has no focus keyword
- Source: wp-07 · Area: on-page SEO
- URLs: products Tesofensine (9), Semax Nasal Spray (9), 5-Amino-1MQ Capsules (10), Orforglipron (11), Selank Nasal Spray (11), Epithalon Nasal Spray (11), BPC-157 Capsules (12), GHK-Cu Facial Toner (16), GHK-Cu Facial Cleanser (18), HCG (49); HGH (post 1143, score 9, keyword=null); page Shop (45)
- Evidence: `get_seo_scores` output (data/rank-math-seo-scores.json). Site audit: focus keyword missing from title on 41 pages / 103 products / 29 posts.
- Impact: Weak on-page signals on exactly the product pages that need to rank (several convert well when seen).
- Fix: Set focus keywords + meta via `wp_rm_update_post_seo` (automatable); content expansion needs drafting + review.
- Automatable now: **partial** (FK/meta yes; content no)

### M-16 — 3 orphan pages with zero incoming internal links
- Source: LINK-02 · Area: internal link graph
- URLs: /editorial-standards/, /peptides-recherche-quebec/, /referral-program/
- Evidence: Internal link graph from 99 crawled pages (2,540 content edges) cross-checked against raw HTML (chrome included) of all 117 fetched pages — zero references to each. Caveat: 137 older posts / 91 products not crawled.
- Impact: Orphans get minimal crawl priority and pass/receive no equity; /referral-program/ is a conversion asset.
- Fix: Link /editorial-standards/ from /about/ + author boxes; /referral-program/ from footer or my-account; /peptides-recherche-quebec/ from /acheter-peptides-canada/ — via `wp_replace_in_post` / `wp_update_page` / `wp_create_menu_item`.
- Automatable now: **yes**

### M-17 — Glossary duplicate/canonical/sitemap cleanup (PARTIALLY DONE mid-run)
- Source: LINK-03 · Area: internal links/canonical
- URLs: /education/ → /peptide-glossary/ + /research-peptide-glossary-terms/; /peptide-glossary/ absent from all sitemaps
- Evidence: /education/ linked both glossary URLs; /research-peptide-glossary-terms/ canonicals to /peptide-glossary/; /peptide-glossary/ is index,follow + self-canonical yet in no sitemap (327 URLs checked). **Ops log 2026-11-07 04:42–04:52Z shows another phase already published /peptide-glossary/, consolidated the old 46-term post via cross-page canonical and updated /education/ links; the 301 redirect was DENIED by the permission system (pending user approval).**
- Impact: Remaining risk: duplicate URL still 200s without a 301; canonical glossary invisible to sitemap-driven crawling.
- Fix (remaining): Create 301 /research-peptide-glossary-terms/ → /peptide-glossary/ via `wp_rm_create_redirect` (re-attempt with user approval); ensure /peptide-glossary/ is in the sitemap (`wp_rm_get_post_seo` → clear exclusion).
- Automatable now: **yes** (redirect needs the pending approval)

### M-18 — 6 dead external citation links (verified twice + browser-checked)
- Source: LINK-04 · Area: external links/E-E-A-T
- URLs (source → dead target): /what-is-a-receptor/ → uts.edu.au drug-receptors (410); /micropipette-explained/ → eppendorf.com anniversary page (404); /hplc-testing-guide/ AND /spectroscopy-vs-spectrometry/ → fda.gov ANDA peptide guidance (404); /affinity-chromatography-explained/ → zfin.org CHEBI:80171 (SSL/502); /spectroscopy-vs-spectrometry/ → doi.org 10.1111/j.1745-7270.2007.00320.x (reset/502); /receptor-upregulation-downregulation/ → pharmacologyeducation.ed.ac.uk (connection failed x3)
- Evidence: Each failed twice via curl and was re-verified in headless Chromium (data/external-dead-links.json).
- Impact: Broken citations on educational content undercut the E-E-A-T positioning the content exists for.
- Fix: Choose live/archive.org replacements (validate each with a fetch), then swap via `wp_replace_in_post`.
- Automatable now: **yes** (replacement URLs must be validated first)

---

## LOW

### L-01 — Missing meta description on /category/education/ (only one of 318)
- Source: crawl-03 · URL: /category/education/ · Evidence: no `<meta name="description">`; also the only auto-generated "Archives" title. Fix: term description via `wp_update_category`. Automatable: **yes**

### L-02 — /lab-testing/ has two `<h1>` elements (only multi-H1 page of 318)
- Sources: crawl-04 + SCH-15 (partial) · URL: /lab-testing/ · Evidence: theme-header H1 at byte ~72471 + custom hero `h1#ppl-h1` at ~76839; one likely visually hidden but both in DOM. Fix: demote one via `wp_replace_in_post` on page 135 — needs a human eye on which to keep. Automatable: **no** (supervised edit)

### L-03 — 4 title tags over 65 chars (SERP truncation risk)
- Source: crawl-05 · URLs: /what-are-bioregulator-peptides/ (69), /editorial-standards/ (69), /copper-peptides/ (66), /acheter-peptides-canada/ (66); 14 pages total >60. Fix: shorten via `wp_rm_update_post_seo`. Automatable: **yes**

### L-04 — hCaptcha /authenticate returned 401 twice on mobile checkout
- Source: J-06 · URL: /checkout/ · Evidence: 2x GET api.hcaptcha.com/authenticate → 401 with console errors (mobile pass only); checkout still rendered with all 3 payment methods. Fix: verify hCaptcha site/secret keys in the injecting plugin. Automatable: **no** (credentials)

### L-05 — Article schema on 2 guide pages lacks author/datePublished/dateModified; nonstandard `publisher_logo`
- Source: SCH-06 · URLs: /peptide-coa-guide/, /hplc-testing-guide/ · Evidence: no author/date keys, unlike blog BlogPosting nodes (page-specific). Fix: inspect `wp_ability_rank_math_get_post_schema`, set via `wp_update_post_meta` (rank_math schema keys). Automatable: **yes**

### L-06 — 4 key pages emit a @graph containing only BreadcrumbList (no WebPage/Organization/WebSite)
- Source: SCH-07 · URLs: /lab-testing/, /shipping-returns/, /education/, /buy-research-peptides-canada/ · Evidence: likely per-post "Schema: None". Fix: confirm per-post setting via `wp_ability_rank_math_get_post_schema`, restore default via post meta. Automatable: **yes**

### L-07 — Review datePublished not ISO 8601; offer seller duplicates the Organization under a different @id
- Source: SCH-08 · URL: /product/bpc-157/ (template-wide) · Evidence: '2026-06-23 23:38:00' (space, not T); seller Organization @id '/' vs canonical '/#organization'. Fix: template change in the emitting plugin (#richSnippet block). Automatable: **no** (plugin code)

### L-08 — HTML entities double-encoded inside JSON-LD ('&amp;' literal in names/categories)
- Source: SCH-09 · URL: /product-category/weight-loss-metabolic/ etc. · Evidence: 'Weight Loss &amp; Metabolic' as literal 5-char sequence in parsed JSON. Fix: decode before JSON-encoding in the emitting plugin/template. Automatable: **no** (plugin code)

### L-09 — BlogPosting author @id points to an author archive that 301s to homepage; first-name-only 'Jessica'
- Source: SCH-10 · URL: /what-are-peptides/ (pattern) · Evidence: author @id /author/jessica/ → 301 → /. Fix: editorial decision — real author profile vs #organization as author (E-E-A-T). Automatable: **no** (editorial decision)

### L-10 — 15/25 product-page imgs lack width/height (CLS ~0.098); repeated ca.svg review-flag icon
- Source: PERF-07 · URL: /product/retatrutide/ (pattern: customer-reviews widget) · Evidence: imgsNoDimCount 15/25 dominated by plugin flag icons; shop/product CLS ~0.098 vs home 0.0024. Fix: fixed dimensions for .crw flag icons via `wp_update_custom_css` (append). Automatable: **yes**

### L-11 — Homepage lazy-loads only 3 of 19 images; 16 below-fold grid PNGs eager
- Source: PERF-08 · URL: / · Evidence: lazy/eager 3/16 home vs 12/8 shop; 1008KB images at load. Fix: theme grid template should emit loading="lazy" (custom theme likely bypasses wp_get_attachment_image). Automatable: **no** (theme code)

### L-12 — CSP is only 'upgrade-insecure-requests'
- Source: PERF-09 · URL: site-wide · Evidence: no script-src/frame-ancestors (clickjacking covered by X-Frame-Options: SAMEORIGIN; rest of header set solid). Fix: optional report-only CSP at Cloudflare. Automatable: **no** (Cloudflare)

### L-13 — Rank Math site audit minors: 83/100, 5 fails / 2 warnings
- Source: wp-08 · Evidence: empty img alt on age-gate logo (decorative — acceptable), 2 unminified plugin CSS files, 43 homepage requests, failed remote mobile-speed test, WP auto-updates off. Fix: no high-impact action; items covered by H-02/M-09 or hosting decisions. Automatable: **no**

### L-14 — Noindexed content types submitted in XML sitemap (product tags, author archives)
- Source: wp-09 · Evidence: tax_product_tag_robots=[noindex] with tax_product_tag_sitemap=true; author_robots=[noindex] with authors_sitemap=true; product-tag URLs collecting GSC impressions. Fix: `wp_ability_rank_math_set_sitemap_settings` → taxonomies.product_tag=false, authors_sitemap=false. Automatable: **yes**

### L-15 — 5 plugin + 2 theme updates pending; 1 inactive plugin, 2 surplus themes; 1 late cron event
- Source: wp-11 · Evidence: Site Health 0 critical / 4 recommended; action_scheduler_run_queue_rucss late (WP Rocket RUCSS). Fix: updates + cleanup with owner approval and backups — excluded from automation as plugin/theme code (risky class). Automatable: **no** (risky class)

### L-16 — Stray URLs collecting impressions: /hello-world/ (default WP post), /yunohost/sso/
- Source: wp-14 (partial) · Evidence: GSC rows; robots.txt already blocks the main parameter patterns correctly. Fix: delete/redirect /hello-world/ via `wp_delete_post` + `wp_rm_create_redirect` (automatable); /yunohost/sso/ needs host investigation (manual). Automatable: **yes** (hello-world only)

### L-17 — 17 external citation links use generic anchor text 'Link'
- Source: LINK-05 · URLs: /rf-value-thin-layer-chromatography/ (8), /signal-transduction-explained/ (6), /liquid-chromatography-explained/ (3) · Evidence: anchors exactly 'Link' (zero 'click here'/bare-URL anchors site-wide otherwise). Fix: descriptive anchors via `wp_replace_in_post`. Automatable: **yes**

### L-18 — /terms-of-service/ and /shipping-returns/ have zero outgoing internal links; 30 posts site-wide with none
- Source: LINK-06 · Evidence: content-region parse (0 internal links); Rank Math link report posts_no_internal=30. Fix: add contextual links to /shipping-across-canada/, /faq/, /contact/ via `wp_update_page`; enumerate the 30 posts via `wp_ability_rank_math_get_link_report`. Automatable: **yes**

### L-19 — /lab-testing/ links an old product slug that 301s
- Source: LINK-11 · Evidence: anchor 'Coremend Blend (TB-500 + KPV + BPC-157)' → /product/coremendtb500-kpv-bpc157/ → 301 → /product/coremend-blend/. Fix: `wp_replace_in_post` to the final URL. Automatable: **yes**

---

## INFO (observations and verified-healthy baselines)

- **I-01** (crawl-07) 404 handling correct: 5/5 bad URLs return real HTTP 404, noindex, branded template. No action.
- **I-02** (crawl-08) Metadata health strong: 318/318 resolve 200; 0 missing titles, 0 real duplicate titles/descriptions, 0 canonical mismatches, 0 noindex-in-sitemap, og:title 318/318, 'research use only' 318/318. Baseline for re-tests.
- **I-03** (crawl-06) /shop/ listed in both page- and product-sitemap (319 entries / 318 unique). Harmless; optional dedupe queued (fix-24).
- **I-04** (J-07) Full purchase funnel verified working on both viewports (variation → cart $118 → checkout with 3 payment methods: payezwithcc, betpg_etransfer, mycryptocheckout). No order placed. Cart→checkout 8.7s is H-02's TTFB.
- **I-05** (J-08) Age-gate modal blocks all interaction until dismissed; persistence session-only (no cookie/localStorage key). **Automation note: every future crawler/browser run must click "I am 18+ — Enter" first, and must not rely on networkidle (SwG widget keeps the network busy).**
- **I-06** (J-09) All navigation verified: 29 unique nav targets 200 with correct H1s; mobile hamburger works.
- **I-07** (J-10) Site search works; proper no-results page.
- **I-08** (J-11) Themed 404, no horizontal overflow on mobile, zero JS pageerrors across ~100 page views, no broken images. Posts have no comments/sharing UI (appears intentional).
- **I-09** (SCH-11) No manual-action-risk markup: ratings/reviews/FAQs/prices in schema all match visible content (review counts exact; price delta ≤ $0.50).
- **I-10** (SCH-12) Organization/WebSite schema complete; 42 JSON-LD blocks, 0 parse errors, 0 unresolved @ids.
- **I-11** (SCH-13) robots.txt has no AI-bot-specific rules → GPTBot/ClaudeBot/PerplexityBot/Google-Extended inherit `User-agent: *` (permissive). Business choice; no change needed for AI visibility.
- **I-12** (SCH-14) llms-full.txt healthy: 49KB, 281 link targets, 0 broken, 1 redirected product link (→ /product/coremend-blend/; update at next regeneration); served x-robots-tag: noindex.
- **I-13** (SCH-15) Key pages are answer-engine ready (direct-answer leads verified). Caveat: age-gate markup sits before `<main>` in DOM, so naive extractors read age-gate copy first (theme change — manual_review MR-22).
- **I-14** (PERF-10) Static asset caching strong: 10-year max-age, cf HIT, brotli; only 'immutable' missing (cosmetic).
- **I-15** (PERF-11) HTTP/2 served, HTTP/3 advertised; brotli on HTML/CSS/JS (zstd on cart).
- **I-16** (PERF-12) No meaningful server fingerprint leakage; `Site Kit by Google 1.191.0` generator meta and odd `x-sso-wat` header (harmless; optional cleanup).
- **I-17** (wp-12) 28d baseline: 2,100 sessions, 23 GA4 conversions; Organic 1,144 sess/19 conv (83% of conversions); AI Assistant 72 sess/1 conv; **Referral 3 sessions — the metric the backlink program must move.** GSC: 694 clicks / 11,654 impressions.
- **I-18** (wp-13) Rank Math get_top_keywords returns empty despite connected=true (analytics_stats=false); Site Kit GSC covers the need. Open Rank Math Analytics to sync if wanted.
- **I-19** (LINK-07) 404-log entries /9475-2 and /product-category/fashion/shoes are external bot probes (empty referer/UA); nothing on-site links to them.
- **I-20** (LINK-08) Zero mixed content across 117 fetched pages.
- **I-21** (LINK-09) All 110 janoshik COA links return 403 bot-protection to automated clients — unverifiable, not broken. **Human should spot-check 2–3 in a normal browser (these are the product-trust links)** (MR-21).
- **I-22** (LINK-10) Some products reachable only via shop/category pagination (e.g. /product/hmg/, /product/teriparatide/) — internal-linking candidates for Phase 6.

---

## Dedup & severity notes

| Master | Merged raw findings | Note |
|---|---|---|
| H-01 | J-01 + LINK-01 | Same root cause (Rank Math redirect rule 1) |
| H-02 | PERF-01 + crawl-02 + J-04 | One caching/TTFB problem seen by 3 auditors |
| M-04 | SCH-01 + wp-10 | Conflict surfaced: llms.txt served while Rank Math module disabled; SCH-01 downgraded high→medium (2 links affected; source must be found first) |
| M-10 | PERF-05 + J-05 | SwG widget + third-party JS weight |
| M-12 | wp-03 + wp-04 | Striking distance + the single CTR-rewrite candidate |
| M-17 | LINK-03 | Partially remediated mid-run per ops log (04:42–04:52Z); remaining steps only |

All findings above carry concrete evidence from the auditor runs; nothing was dropped for lack of evidence — the six auditors' claims were all reproducibly sourced (files in `ops/audit/data/` and the audit-run scratchpad). Proxy-inflated absolute timings are treated as upper bounds; the DYNAMIC cache status and relative spreads are proxy-independent.
