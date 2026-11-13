# Operations guide

## The automated cycle

Every tick (default every 5 minutes via Action Scheduler) the pipeline enqueues the stages that are due and runs queued jobs within the time budget:

1. **Discover** (daily) — seeds (once), rotating search queries, competitor backlinks (weekly), brand mentions (weekly).
2. **Evaluate** (continuous) — profile, relevance, risk, provider metrics, score. Rejects poor sites, qualifies the rest, finds the submission page and contacts. Each run takes prospects in this order: first those that did not come from the verified opportunity import (new discoveries, then due re-reviews, oldest first, then automatic re-checks of sites that were unreachable or hit an evaluation error), then imported prospects in the same order. Imported prospects are still evaluated, only after the others, so the import cannot hold up newly found sites under the daily evaluation limit. A site that was already a prospect before the import counts as a discovered prospect, not an imported one.
3. **Find opportunities** — one opportunity per qualified prospect at the real submission/contact page; broken links on resource pages.
4. **Package** (hourly) — complete submission packages for opportunities at or above `min_score_auto_queue`.
5. **Submit** — processes queued packages. In the default auto mode a package queues itself when the site scores ≥ the quality threshold and every structural gate passes; in stricter modes it waits for your approval unless the site is set to *Always allow*. A site set to *Never automate* always gets its package prepared for a person. Whatever cannot be automated safely waits in the review queue.
6. **Verify listings** (every 6 h) — checks submitted listings; confirmed listings become backlinks and the prospect becomes *acquired*.
7. **Auto outreach** (daily) — drafts personalised messages for qualified editorial prospects (resource pages, publications, broken links, unlinked mentions, podcasts, associations) into evergreen "Auto" campaigns (a paused, archived or completed Auto campaign gets no new drafts); **Outreach send** delivers approved messages inside the send window and limits (auto-drafts are approved automatically only with *Outreach autosend* on and *Require individual approval* off); **Follow-ups** (daily) drafts follow-ups for unanswered messages, which are queued to send without review only with autosend on and in campaigns set to auto-approve follow-ups.
8. **Monitor backlinks** — checks due links, records changes, raises notifications; **Import backlinks** (weekly) pulls provider and GA4 referral data.
9. **Cleanup** (daily) and **Metrics refresh** (weekly).
10. **Storefront audit** (daily, and again after products, product categories or tags, or menus change) — see *Storefront compliance check* below.
11. **Import verified** (when the bundled `data/verified-opportunities.json` changes) — imports the researched opportunities as owner tasks.
12. **Site audit** (daily), **IndexNow** (hourly, only when URLs are waiting and the site may submit) and **Internal links** (weekly, suggestions only) — see the On-site SEO page.

Every automated action is written to the audit log (Logs → Audit).

## Grow now (one click)

**Overview → Grow your site → Grow now** runs every stage above once, in this order: check the storefront and the setup, import verified opportunities, audit on-site SEO, notify search engines (IndexNow), suggest internal links, discover, find unlinked mentions, find broken-link opportunities, evaluate, find opportunities, prepare packages, submit, verify listings, draft outreach, check backlinks, import backlinks, refresh metrics, summarise.

- The panel lists each step with its status (done, skipped, blocked, failed) and one sentence about what it did. The browser asks the server to continue every few seconds; each request works for at most `growth.step_seconds` (20 s).
- If you close the tab, the scheduler continues the run about a minute later, even while the automation switch is off (the run was your explicit click). While other jobs are due (sends, follow-ups, monitoring), background ticks alternate between the run and the queue so neither starves. Opening the Overview again resumes the display. Only one run is active at a time; *Stop* keeps what was done, cancels the run's queued follow-up jobs, and a job of the run that is already running finishes its current batch without continuing. *Stop* stays available when the panel loses contact with the server (the run keeps running on the server until stopped).
- The run uses the same gates as the scheduled stages: the submission policy (including the storefront gate), robots.txt, the daily limits and the outreach approval rules. Steps run as manual runs, so a stage whose own switch is off still runs, but *Automated submissions* and *Outreach autosend* are always respected. The submit step shows **blocked** when the storefront check blocks or automated submissions are off.
- The run never changes a setting and never edits your pages (internal links are suggestions). What it may send on its own follows your settings, and the panel's subtitle states it: with *Automated submissions* on in **auto** mode, listings to sites that pass every safety gate are submitted automatically; outreach is only drafted, and emails go out after your approval (or automatically while *Outreach autosend* is on and *Require individual approval* is off). IndexNow is notified of new and changed pages.
- At the end, **What happened** shows headline numbers (prospects, owner tasks, drafts awaiting approval, live and lost backlinks, SEO issues, IndexNow submissions, link suggestions, storefront status) and **Needs you** links to the pages where you act. A number that could not be read shows as "not available", never as 0; SEO issues show "not run yet" until an audit has finished a pass, and "(partial)" while one is in progress.
- Work that does not fit (for example more prospects than the evaluation rounds allow) continues in the background; the step says so.

## Storefront compliance check

Listings and outreach describe the business as a research-use-only supplier of laboratory reagents. When the storefront presents products for consumer use, every automated listing and email would misrepresent the business, so the plugin checks the store first:

- **What it reads**: product category and tag names, slugs and descriptions, published product titles and short descriptions, and menu items (page and post titles when WooCommerce is not active). It never edits anything.
- **Results**: *block* (consumer-use wording such as weight loss, anti-aging, tanning, skincare, beauty, nasal spray, capsules, injections, syringes, needles, dosing, supplements, wellness, "for human use", or a product title that is a consumer dosage form), *warn* (wording to review: recovery, growth, cognitive, metabolic, longevity, healing) or *pass*. Each finding names the term, where it is and a link to edit it.
- **While it blocks** (and the gate is on): new packages become owner tasks under Submissions → Needs manual, queued submissions are moved there when the queue runs, and outreach sending pauses; each paused message shows "Paused: the storefront compliance check is blocking …". Drafting and packaging continue so you can review them. These items do not return to the queue by themselves after the store is fixed: approve or retry them.
- **Your decision**: how the store presents itself is your legal decision, ideally with Canadian regulatory counsel. Either change the storefront wording (the check re-runs when products, categories, tags or menus are saved; *Re-check now* on the banner runs it at once), or click *Acknowledge* to record that you reviewed these exact findings. An acknowledgement lasts until the findings change. Turning the gate off (Settings → Outreach & compliance) asks for confirmation.
- **Fail closed**: if the check cannot run, automated submissions and outreach sending stay paused and the error is logged.

## On-site SEO page

- **Site audit**: issues by severity (high, medium, low) with the fix and an edit link. *Run audit* continues where the last batch stopped; the audit reads the database and changes nothing.
- **IndexNow**: the key file link, the last submission and its HTTP status, URLs waiting, and *Submit now* (compares every public URL now). Submissions happen only on the production site and never while WordPress discourages search engines. HTTP 403 means IndexNow could not read the key file: open the key file link and check that it shows only the key (a caching or security rule may block it). After any refusal the scheduled submission waits at least an hour; after HTTP 429 ("slow down") it waits as long as IndexNow's Retry-After asks (at most a week), and *Submit now* is disabled until then.
- **Internal-link suggestions**: each row shows the sentence, the anchor and the target. *Apply* (or *Apply all*, after confirmation) first makes sure the current content is kept as a revision (the restore point), then adds the link; nothing is changed if the text was edited since the suggestion was made, or if WordPress keeps fewer than 2 revisions for the item. Text inside links and headings (also when written as `<a href=/x/>` or `<h2 />`) and inside blocks that render from their settings (for example Rank Math FAQ and HowTo) is never linked. *Dismiss* hides a suggestion for good.

## Verified opportunities (owner tasks)

The plugin bundles 595 researched opportunities (business directories, associations, research and data platforms, publications) with their submission pages, requirements and steps. They are imported once per data version as owner tasks: Submissions → **Owner tasks** lists them with tier, authority, kind (listing or outreach) and conditions. Each task has the listing kit (*Copy listing kit*), the steps, a safe link to the submission page and *Mark submitted* for the listing URL, after which listing verification takes over. The import contacts nobody and sends nothing; whether to pitch an outreach-type opportunity is your decision (automatic drafting skips them). Items whose domain is on the platform exclusion list (`data/excluded-domains.json`, for example Google Business Profile, LinkedIn or GitHub) are skipped and listed with the reason; allowing them means changing that exclusion list, which also lets discovery pick those platforms up. Items with no free tier (paid press releases, paid directory programs) are skipped as *paid_only*, because listings are free tiers only; a task created for one by an earlier import is closed. Items for a site where a listing was already submitted are skipped as *already_listed*, so no task asks for a duplicate listing. Automatic outreach drafting skips every prospect that has a verified owner task, whatever source first added the site.

## Daily routine for the administrator (10–15 minutes)

1. **Overview** — read notifications (lost links, high-value prospects, replies), check that integrations are healthy and credits remain.
2. **Overview** — if the storefront compliance banner shows, read the findings and decide (change the wording, or acknowledge). Click *Grow now* when you want everything to run once and see what needs you.
3. **Submissions → Owner tasks / Needs manual** — open each package, read the instructions, visit the submission page in your browser, paste the prepared fields and content, then click *Mark submitted* with the listing URL/confirmation. The verifier takes it from there.
4. **Submissions → Pending review** — approve packages whose destination you have authorised for automated posting (set its *Per-site automation* to *Always allow* in the prospect drawer first; approving re-checks every gate, and a package that still cannot be automated moves to *Needs manual* with the reason), or reject.
5. **Outreach → Messages → Draft** — read each draft, edit if needed (the claims check runs live), approve. Approved messages go out automatically within the send window, or use *Send now*.
6. **Prospects** — sanity-check new *qualified* prospects; hold, reject or suppress anything you do not want pursued. Rejections and suppressions are permanent for discovery, and they cancel the prospect's unsent outreach (draft, approved and scheduled messages). *Delete* also removes the prospect's opportunities, submissions, contacts and outreach messages, so it is refused once a submission or email was sent to the site: suppress or reject it instead.

## Weekly

- Review **On-site SEO**: fix high-priority audit issues in the editor and apply or dismiss internal-link suggestions.
- Review **Backlinks**: new, lost, changed; investigate lost links (the source page may have moved).
- Review replies in **Outreach → Messages** and use *Log reply* for replies that arrived outside the webhook.
- Check **Automation → Limits** and adjust if the queue is starving or growing too fast.
- Check the SE Ranking credit balance on the Overview.

## How automated submissions are authorised

In the default **auto** mode nothing is required from you: a site that scores at or above the quality threshold and passes every structural gate (public listing form, POST, no CAPTCHA, no login, no required upload, robots.txt allows the action URL, all required fields mappable, package text passes the claims filter, limits, no duplicate) is submitted automatically, and everything else lands in the review queue with a prepared package.

You can still steer it per destination with the prospect's **Per-site automation** setting (prospect drawer → Actions), which is *Inherit* by default:

1. **Boost**: set it to **Always allow** to authorise the site regardless of its score and of the submission mode (useful for moderate-scoring sites you trust, and only for directories whose terms permit automated listings). For directories that expose an official API, fill in the *Configured API* fields instead (endpoint, method, headers, JSON body template, success path, listing URL path, API key).
2. **Keep manual**: set it to **Never automate** and nothing is submitted to the site automatically in any mode, partner API included; its packages are prepared for you to submit by hand.
3. **Block**: suppress the prospect (or reject it) and the engine never touches it again.
4. **Slow down**: switch `automation.submission_mode` to `authorized_only` or `manual` in Settings → Automation, or lower the daily/weekly submission limits. Sites set to *Always allow* or with a partner API stay authorised in both modes; to stop all automated posting, turn off *Automated submissions*.

The engine re-checks every gate at send time and records the request and response.

The engine never solves CAPTCHAs, never logs in, never retries validation failures, never ticks fee or paid-placement boxes, and never posts comments, forum messages or profile links. It never re-posts a request whose outcome is unknown (a timeout or server error after the form was sent): that submission goes to *Needs manual* so you can check the destination first and use *Mark submitted* if the listing is there.

## Reading a score

Each prospect drawer shows the seven components with their weight, raw score, data source and notes. *Confidence* is the share of the 100 points backed by real data; without any metrics provider it is 70 (traffic and authority unknown). A high score with low confidence deserves a manual look before outreach.

## Handling replies and opt-outs

- Replies arriving through the inbound webhook mark the message *replied*, cancel the conversation's unsent follow-ups and raise a notification.
- A reply whose new text asks to stop ("unsubscribe", "opt out", "remove me", "stop emailing"…) opts the contact out and suppresses the address; quoted earlier messages and the plugin's own footer are ignored. Bounces mark the message and contact *bounced*.
- The unsubscribe link in every email opens a confirmation page; its *Unsubscribe* button, or the one-click unsubscribe of the recipient's mail client, suppresses the address immediately and cancels the contact's unsent messages. Merely opening the link changes nothing. The link keeps working as long as the sent message is stored (CASL requires at least 60 days).
- An opted-out contact cannot be set back to *active* in Outreach → Contacts. Only if the person asked to be contacted again, remove the entry under **Logs → Suppression list** first.
- Add domains or emails to **Logs → Suppression list** to stop any further contact or pursuit.

## Troubleshooting

| Symptom | Check |
|---|---|
| Nothing happens automatically | Automation master switch; Automation → Scheduler status (next run, backend); WP-Cron disabled? |
| Discovery finds nothing | A search provider must be configured with daily quota left (Settings → Integrations). |
| Scores all "unknown traffic/authority" | Configure SE Ranking (or another metrics provider) and check its Test button. |
| Submissions stuck in *queued* | Gates failing: see the submission's last error; check the site's *Per-site automation* (not *Never automate*), no CAPTCHA, robots.txt allows the form action, submission limits not used up. |
| A submission in *Needs manual* says the outcome is unknown | The request may have reached the site (timeout or server error after sending, or an interrupted run). Check the destination; use *Mark submitted* if the listing is there, otherwise submit it by hand. It is never re-posted automatically. |
| "This submission is being sent right now; try again in a minute." | The queue is posting that submission; edits, reject, rebuild and mark-submitted wait until the result is recorded. |
| Outreach not sending | Sender identity incomplete (postal address), campaign not approved (or paused), message not approved, outside send window, limits reached, WP Mail SMTP misconfigured. Auto-drafts stay drafts while *Require individual approval* is on, even with autosend on. |
| *Send now* says the automation runner is busy | A background run holds the runner lock (it may be sending outreach); nothing was sent. Try again in a minute. |
| Inbound webhook answers 401, 403 or 503 | 401: no secret in the request; 403: wrong secret (in the URL forms, use only letters and digits or percent-encode); 503: no secret configured in Settings → Integrations. |
| Submissions all go to *Needs manual* with "storefront" in the reason, and outreach says "Paused: the storefront compliance check is blocking" | The storefront check blocks (Overview banner). Change the storefront wording and click *Re-check now*, or *Acknowledge* the findings. |
| Grow now step shows *blocked* at "Submit approved listings" | The storefront check blocks, or *Automated submissions* is off; the step sentence says which. |
| Grow now keeps saying the runner is busy | A background job holds the runner lock; the panel retries every few seconds. If it persists, check Automation → Queue for a long-running job. |
| IndexNow says it only runs on the production site | WordPress reports another environment type (`WP_ENVIRONMENT_TYPE`). Nothing is submitted from staging copies. |
| IndexNow answers HTTP 403 | The key file is not reachable: open `https://SITE/{key}.txt` (link on the On-site SEO page); it must show only the key. Check caching or firewall rules (Cloudflare) for `.txt` files. |
| An internal-link suggestion is refused with "the text changed" | The post was edited after the suggestion was made. Click *Find suggestions* on the On-site SEO page. |
| Errors in Logs | Each entry has channel and context; provider errors also appear on the provider card. |
