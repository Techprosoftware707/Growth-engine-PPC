# Competitor Backlink Gap Analysis — Pure Peptides Canada

Date: 2026-11-07 · Data source: SE Ranking backlinks API (referring-domain pulls) + direct page verification (curl) · Raw data: `scratchpad/backlink-research/competitor-gap/` (gap_table.csv/json, top_gap_classification.json, raw_refdomains_*.json)

## Competitors analyzed

Organic-keyword competitors were pulled from SE Ranking for purepeptidescanada.com (Canada database), then referring-domain profiles were fetched for each:

| Competitor | Why included | Referring domains found |
|---|---|---|
| eastcoastpep.com | CA organic competitor (4 common keywords, 58 total) | ~60+ domains (the only competitor with a real link profile) |
| canadapeptidelab.ca | CA peptide vendor (homepage verified) | 2 (peptelens.ca, thepeptide10.com) |
| peptideology.ca | CA organic competitor (6 common keywords) | 0 |
| renovapeptides.do | organic competitor (traffic 22) | 2 (peptidesdivas.com, biohacker.s3nna.dev) |
| klarovel.com | CA organic competitor (6 common keywords, homepage verified) | not in SE Ranking backlink index |

PPC baseline (for contrast): 4 referring domains — peptelens.ca (nofollow), gotpeptide.ca (603 backlinks, all nofollow), doserly.com (9 dofollow), thepeptideco.online (1 dofollow). DA 9.

## Headline finding

**The competitor link profiles are almost entirely non-replicable or toxic.** eastcoastpep.com — the only competitor with meaningful links — got them from (a) a Moncton SEO agency's client-site footer network ("BOSS Marketing"), (b) a Money Robot automated spam-link network, and (c) a PBN seller. Copying this profile would be a Google link-scheme violation and a real penalty risk. Only three gap domains are worth anything, and only one is self-service.

## Top gap domains by authority (with classification)

| Domain | Authority | Kind | Self-service? | Action |
|---|---|---|---|---|
| secretsearchenginelabs.com | 73 | directory (alt search engine) | **yes** — `http://www.secretsearchenginelabs.com/add-url.php` (HTTP 200, form present) | **ADDED to opportunities DB (tier 3)**. Free add-URL; links observed nofollow, HTTP-only site — citation parity play only |
| extremeseotools.com | 50 | spam-network (Money Robot doorway) | yes | **AVOID** — link scheme |
| coriumderm.ca | 41 | local business (BOSS agency client footer) | no | not replicable (paid agency placement) |
| brunswickfyr.ca | 39 | local business (BOSS network) | no | not replicable |
| bossmarketingservices.com / bossmarketing.ca | 38 / 36 | the agency itself | no | documents the mechanism; not a target |
| profitsurgeseo.com | 34 | spam-network (Money Robot) | yes | **AVOID** |
| wecelebrities.com / gladeflowers.com / exotichealths.com | 33 / 18 / 6 | repurposed expired domains (CVGorilla paid placements) | no | **AVOID** |
| modernlaser.ca | 31 | dead site ("Index of /") | no | skip |
| **peptidesdivas.com** | 29 | **editorial — niche peptide review/community blog** | no (WPForms contact form: `https://peptidesdivas.com/contact-us/`) | **OUTREACH target**: 8 dofollow links to renovapeptides.do; pitch a vendor review/profile citing PPC's Janoshik COA program |
| trmechanicalservices.ca, evenflowph.ca, jpctoolset.com, roofedge.ca, gencanroofing.com, rdc-metalworks.com, keepitcleanmoncton.com | 29–15 | BOSS-network local businesses | no | not replicable |
| a-z-pets.com | 20 | PBN directory (openly sells "Premium PBN & Link Building") | yes | **AVOID** |
| searchrankings.site + 12 more .site/.shop/.website/.online domains | 13–4 | Money Robot spam templates (flagged spam_suspect in gap table) | yes | **AVOID** |
| **thepeptide10.com** | 1 (new) | **editorial — independent 10-vendor peptide shortlist** (3 dofollow links to canadapeptidelab.ca) | no (no public email; `/selection` + methodology pages live) | **MONITOR / EARN**: inclusion is editorial from a 430-vendor research pool; PPC's COA transparency is exactly its selection criterion |

## What goes into the opportunities database

- **1 row merged** as self-service + replicable: `secretsearchenginelabs.com` (category: directory, tier 3). It is the only gap domain that is simultaneously self-service, free, and not part of a spam/PBN/agency scheme.
- **2 outreach/monitor targets documented here** (not self-service, so not CSV rows): peptidesdivas.com (pitch via contact form) and thepeptide10.com (earn inclusion; no contact channel found).
- **All Money Robot / PBN / BOSS-network / repurposed-domain entries are deliberately excluded.** They are recorded in `top_gap_classification.json` as AVOID so no future automation re-prospects them.

## Strategic takeaway

The gap analysis proves PPC cannot and should not "copy competitor backlinks" — competitors bought low-quality links. PPC's path is the verified white-hat inventory in `opportunities.csv` (88 rows): Canadian/B2B/scientific directories, entity profiles, resource registries (PubChem, SciCrunch), earned media (HARO/SOS/Qwoted/MentionMatch) and the two niche editorial targets above, where PPC's third-party COA testing story is a genuine differentiator.
