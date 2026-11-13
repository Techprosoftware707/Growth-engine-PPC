=== PPC Link Growth Engine ===
Contributors: purepeptidescanada
Tags: seo, backlinks, outreach, directories, monitoring
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Compliance-first backlink discovery, qualification, submission packaging, outreach drafting and backlink monitoring for Pure Peptides Canada.

== Description ==

PPC Link Growth Engine discovers websites that could legitimately link to Pure Peptides Canada, scores them on a transparent 100-point model, locates their real submission pages and editorial contacts, prepares personalised listing packages and outreach emails, submits listings only where explicitly authorised, and monitors every acquired backlink.

* Discovery: rotating search queries, curated Canadian seed catalog, competitor backlink mining, unlinked mentions, broken links.
* Qualification: polite crawling, relevance and risk analysis, SE Ranking / Ahrefs / Semrush / Moz metrics, never fabricated.
* Submissions: form detection, field mapping, verified-fact descriptions, explicit authorisation gates, review queue, listing verification.
* Outreach: eight personalised approaches, CASL/CAN-SPAM identification and unsubscribe, approvals, limits, follow-ups, reply tracking.
* Monitoring: scheduled checks, change and loss detection, provider and GA4 imports, notifications and digests.
* Grow now: one click on the Overview runs every stage once, in order, and lists what happened and what needs you.
* Storefront compliance check: pauses automated submissions and outreach sending while the storefront presents products for consumer use.
* On-site SEO: read-only site audit, IndexNow notifications and internal-link suggestions that are applied only when you click Apply.
* Verified opportunities: 595 researched listing and outreach opportunities bundled with the plugin, imported as owner tasks.
* Link Growth Submitter role: hand the owner tasks to a staff member or assistant who sees only that queue.
* Dashboard: Overview, Prospects, Submissions, Outreach, Backlinks, On-site SEO, Automation, Settings, Logs.

== Installation ==

1. Upload the plugin zip under Plugins → Add New → Upload Plugin and activate.
2. Open Link Growth → Settings, review the business profile and add your API keys.
3. Run Discover and Evaluate from the Automation Center, then enable automation.

== Changelog ==

= 1.1.0 =
* New: "Grow now" on the Overview runs every growth stage once as a resumable run (continued by the scheduler when the tab is closed), then shows what happened and what needs you.
* New: storefront compliance check (daily, on growth runs and when products, product terms or menus change). While it blocks and the gate is on, automated submissions become owner tasks and outreach sending pauses; the owner may acknowledge the exact findings.
* New: On-site SEO page with a read-only site audit, IndexNow submissions (production sites only) and internal-link suggestions applied only by an explicit Apply click (the previous version is kept as a revision first).
* New: the bundled verified opportunity database is imported as owner tasks (manual submissions with a listing kit), with tier, kind and condition filters on the Submissions page.
* Evaluation order: prospects that did not come from the verified opportunity import (new discoveries, due re-reviews and automatic re-checks) are evaluated before imported ones; imported prospects are evaluated after them.
* New: "Link Growth Submitter" role for handing owner tasks to a staff member or assistant. It has only the read and ppclg_submit_listings capabilities and sees one menu item, Link Submissions: the open owner tasks and the ones that user marked submitted in the last 14 days. It can mark a task submitted (listing URL and note; recorded in the audit log with the user id) and nothing else; every other page and REST route refuses it. Settings has a card with a link to add such a user. Uninstalling removes the role and the plugin capabilities. Tasks that need the owner's decision are not shown to this role: a rejected, suppressed or on-hold site, text that fails the claims check, a listing that may already have been sent or would be a duplicate, and outreach pitches. Its listing URL must be on the task's site. The role is created on the first page load after an update, even when the plugin was not reactivated.
* Mark submitted: the listing URL must be an http(s) address; anything else is refused instead of being stored. A listing URL on the site's own domain is refused, and listing verification ignores one, because a page on the own site always links to it.
* Settings: new seo, compliance and growth sections. The storefront acknowledgement and the IndexNow key cannot be changed through the settings form; turning the storefront gate off asks for confirmation.

= 1.0.0 =
* Initial release.
