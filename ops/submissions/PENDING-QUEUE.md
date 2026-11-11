# Submission Pending Queue — needs the owner (accounts, email verification, or CAPTCHA)

Date: 2026-11-07. Companion to `ops/backlinks/TOP-25.md` (full step-by-step per item) and `ops/submissions/profile-pack.md` (ready-to-paste descriptions). Every item below was URL-verified; none requires payment on the free tier.

> **STOP — storefront consistency gate (added 2026-11-11).** Every item below uses the research-use-only description. On 2026-11-11 the live /shop/ showed consumer-use categories (Weight Loss & Metabolic, Anti-Aging & Cellular, Pigmentation & Tanning, Peptide Skincare, Nasal Sprays, Capsules) plus syringes and bacteriostatic water, and Health Canada advisory RA-81874 (2026-04-09) says RUO labelling does not make the named peptides legal. Submitting the RUO description while the store presents this way would misrepresent the business. Resolve the store presentation with Canadian regulatory counsel first, and make sure the description matches the live store on the day you submit. Details: `ops/backlinks/TOP-NEW-50.md` (gate section).

**Why these are queued, not done:** each needs at least one of (a) an account created with `hi@purepeptidescanada.com` and its email-verification link clicked, (b) a CAPTCHA solved in a browser, (c) a real person's name as the accountable contact, or (d) identity/phone verification. The agent executed everything that had none of those gates (see `submitted.csv` — 1 item), and per the engagement rules did not bypass CAPTCHAs, invent identities, or leave half-verified listings in reviewer queues.

## How to work the queue
1. Use one password manager entry per site; always sign up with `hi@purepeptidescanada.com`.
2. Copy NAP + description verbatim from the standard kit in TOP-25.md (identical NAP everywhere).
3. Categories: laboratory supplies / biotechnology / scientific research. Never a health, pharmacy, or supplement category.
4. After each goes live, add a row to `submitted.csv` with the listing URL.

## Order of attack (highest value first, ~10 min each)

| # | Platform | Gate | TOP-25 ref |
|---|----------|------|------------|
| 1 | Google Business Profile | Google account + identity verification (video/postcard) | #1 |
| 2 | Canadian Trade Index | CAPTCHA on form (3-min form otherwise; form confirmed live) | #2 |
| 3 | PubChem chemical-vendor listing | NCBI account + substance data deposit | #5 |
| 4 | Thomasnet | account + staff review | #3 |
| 5 | Trustpilot Business | work-email signup + domain claim | #4 |
| 6 | Biotechgate | 3-min form, no account — but staff verify via the named human contact's email, and the form has an anti-bot field a script could trip; do it in a browser | #7 |
| 7 | SciCrunch / RRID registry | account (Cloudflare-gated signup) | #6 |
| 8 | Crunchbase | account; big AI/knowledge-graph payoff | #16 |
| 9 | LinkedIn Company Page | needs an admin's personal LinkedIn | #19 |
| 10 | Qwoted + HARO + Source of Sources + MentionMatch + SourceBottle | email subscriptions / source profiles under a real person's name (founder) | #8–#12 |
| 11 | YellowPages.ca | form + phone verification | #18 |
| 12 | MacRAE'S Blue Book | same platform/CAPTCHA as CTI | #25 |
| 13 | Brownbook → n49 → ZipLeaf → Hotfrog | email-confirm each | #21–#24 |
| 14 | Wakelet / Raindrop / Flipboard / Substack / Featured.com | accounts; content surfaces (Substack external links verified dofollow) | #13–#15, #17, #20 |
| 15 | Wikidata item + YouTube brand channel + Science Exchange | see TOP-25 honorable mentions | — |

## Deliberately NOT queued
- **Made in Canada Directory** — eligibility caveat: the directory is for products *made in Canada*; manufacturing origin of the catalog is not verified, and listing there without that verification would misrepresent the business. Owner decision only.
- **Money Robot / PBN-style directories found in competitor backlinks** — documented as AVOID in `ops/backlinks/competitor-gap.md`; they would trade short-term links for Google trust.
- **Review-exchange or fake-review channels** — never.

## Social profiles (category B) — create once, low upkeep
Facebook Business Page, Instagram Business, Pinterest Business, X/Twitter (exists — optimize bio link), TikTok Business, Glassdoor/Indeed employer pages, F6S, Wellfound. All account-gated; NAP kit applies. Prioritize only after the table above — entity value is real but smaller per item.

## Media/publishing (categories C, G, H) — needs owner identity
Podcast guesting (MatchMaker.fm, PodMatch, PodcastGuests, CanadianSME/Ontario/Startup Canada podcasts), Medium/Substack/Vocal publications, document platforms (Issuu; the SlideShare/Scribd-class rows are flagged incomplete in the research DB). These put a named human in front of an audience — owner's call on who speaks for the company.
