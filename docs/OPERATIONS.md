# Operations guide

## The automated cycle

Every tick (default every 5 minutes via Action Scheduler) the pipeline enqueues the stages that are due and runs queued jobs within the time budget:

1. **Discover** (daily) — seeds (once), rotating search queries, competitor backlinks (weekly), brand mentions (weekly).
2. **Evaluate** (continuous) — profile, relevance, risk, provider metrics, score. Rejects poor sites, qualifies the rest, finds the submission page and contacts.
3. **Find opportunities** — one opportunity per qualified prospect at the real submission/contact page; broken links on resource pages.
4. **Package** (hourly) — complete submission packages for opportunities at or above `min_score_auto_queue`.
5. **Submit** — processes queued packages. In the default auto mode a package queues itself when the site scores ≥ the quality threshold and every structural gate passes; in stricter modes it waits for your approval unless the site is set to *Always allow*. A site set to *Never automate* always gets its package prepared for a person. Whatever cannot be automated safely waits in the review queue.
6. **Verify listings** (every 6 h) — checks submitted listings; confirmed listings become backlinks and the prospect becomes *acquired*.
7. **Auto outreach** (daily) — drafts personalised messages for qualified editorial prospects (resource pages, publications, broken links, unlinked mentions, podcasts, associations) into evergreen "Auto" campaigns (a paused, archived or completed Auto campaign gets no new drafts); **Outreach send** delivers approved messages inside the send window and limits (auto-drafts are approved automatically only with *Outreach autosend* on and *Require individual approval* off); **Follow-ups** (daily) drafts follow-ups for unanswered messages, which are queued to send without review only with autosend on and in campaigns set to auto-approve follow-ups.
8. **Monitor backlinks** — checks due links, records changes, raises notifications; **Import backlinks** (weekly) pulls provider and GA4 referral data.
9. **Cleanup** (daily) and **Metrics refresh** (weekly).

Every automated action is written to the audit log (Logs → Audit).

## Daily routine for the administrator (10–15 minutes)

1. **Overview** — read notifications (lost links, high-value prospects, replies), check that integrations are healthy and credits remain.
2. **Submissions → Needs manual** — open each package, read the instructions, visit the submission page in your browser, paste the prepared fields and content, then click *Mark submitted* with the listing URL/confirmation. The verifier takes it from there.
3. **Submissions → Pending review** — approve packages whose destination you have authorised for automated posting (set its *Per-site automation* to *Always allow* in the prospect drawer first; approving re-checks every gate, and a package that still cannot be automated moves to *Needs manual* with the reason), or reject.
4. **Outreach → Messages → Draft** — read each draft, edit if needed (the claims check runs live), approve. Approved messages go out automatically within the send window, or use *Send now*.
5. **Prospects** — sanity-check new *qualified* prospects; hold, reject or suppress anything you do not want pursued. Rejections and suppressions are permanent for discovery, and they cancel the prospect's unsent outreach (draft, approved and scheduled messages). *Delete* also removes the prospect's opportunities, submissions, contacts and outreach messages, so it is refused once a submission or email was sent to the site: suppress or reject it instead.

## Weekly

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
| Errors in Logs | Each entry has channel and context; provider errors also appear on the provider card. |
