# Operations guide

## The automated cycle

Every tick (default every 5 minutes via Action Scheduler) the pipeline enqueues the stages that are due and runs queued jobs within the time budget:

1. **Discover** (daily) — seeds (once), rotating search queries, competitor backlinks (weekly), brand mentions (weekly).
2. **Evaluate** (continuous) — profile, relevance, risk, provider metrics, score. Rejects poor sites, qualifies the rest, finds the submission page and contacts.
3. **Find opportunities** — one opportunity per qualified prospect at the real submission/contact page; broken links on resource pages.
4. **Package** (hourly) — complete submission packages for opportunities at or above `min_score_auto_queue`.
5. **Submit** — processes approved/queued packages whose destination is explicitly authorised; everything else waits in the review queue.
6. **Verify listings** (every 6 h) — checks submitted listings; confirmed listings become backlinks and the prospect becomes *acquired*.
7. **Outreach send** — sends approved messages inside the send window and limits; **Follow-ups** (daily) drafts follow-ups for unanswered messages.
8. **Monitor backlinks** — checks due links, records changes, raises notifications; **Import backlinks** (weekly) pulls provider and GA4 referral data.
9. **Cleanup** (daily) and **Metrics refresh** (weekly).

Every automated action is written to the audit log (Logs → Audit).

## Daily routine for the administrator (10–15 minutes)

1. **Overview** — read notifications (lost links, high-value prospects, replies), check that integrations are healthy and credits remain.
2. **Submissions → Needs manual** — open each package, read the instructions, visit the submission page in your browser, paste the prepared fields and content, then click *Mark submitted* with the listing URL/confirmation. The verifier takes it from there.
3. **Submissions → Pending review** — approve packages whose destination you have authorised for automated posting (toggle *Automation allowed* in the prospect drawer first), or reject.
4. **Outreach → Messages → Draft** — read each draft, edit if needed (the claims check runs live), approve. Approved messages go out automatically within the send window, or use *Send now*.
5. **Prospects** — sanity-check new *qualified* prospects; hold, reject or suppress anything you do not want pursued. Rejections and suppressions are permanent for discovery.

## Weekly

- Review **Backlinks**: new, lost, changed; investigate lost links (the source page may have moved).
- Review **Outreach → Responses** and log replies that arrived outside the webhook.
- Check **Automation → Limits** and adjust if the queue is starving or growing too fast.
- Check the SE Ranking credit balance on the Overview.

## Authorising automated submissions

Automated form posting is opt-in per destination:

1. Open the prospect, confirm the submission page and form look legitimate and that the site's terms allow automated/business submissions.
2. Toggle **Automation allowed**. For directories that expose an official API, fill in the *Configured API* fields instead (endpoint, method, headers, JSON body template, success path, listing URL path, API key).
3. Approve the package. The engine re-checks every gate at send time (settings switch, authorisation, no CAPTCHA/login, robots.txt, limits, duplicates, required fields) and records the response.

The engine never solves CAPTCHAs, never logs in, never retries validation failures, and never posts comments, forum messages or profile links.

## Reading a score

Each prospect drawer shows the seven components with their weight, raw score, data source and notes. *Confidence* is the share of the 100 points backed by real data; without any metrics provider it is 70 (traffic and authority unknown). A high score with low confidence deserves a manual look before outreach.

## Handling replies and opt-outs

- Replies arriving through the inbound webhook mark the message *replied* and raise a notification.
- Any reply containing "unsubscribe" suppresses the contact.
- The unsubscribe link in every email works for at least 60 days and suppresses the address immediately.
- Add domains or emails to **Logs → Suppression** to stop any further contact or pursuit.

## Troubleshooting

| Symptom | Check |
|---|---|
| Nothing happens automatically | Automation master switch; Automation → Scheduler status (next run, backend); WP-Cron disabled? |
| Discovery finds nothing | A search provider must be configured with daily quota left (Settings → Integrations). |
| Scores all "unknown traffic/authority" | Configure SE Ranking (or another metrics provider) and check its Test button. |
| Submissions stuck in *queued* | Gates failing: see the submission's last error; confirm *Automation allowed*, no CAPTCHA, robots.txt allows the form action. |
| Outreach not sending | Sender identity incomplete (postal address), campaign not approved, message not approved, outside send window, limits reached, WP Mail SMTP misconfigured. |
| Errors in Logs | Each entry has channel and context; provider errors also appear on the provider card. |
