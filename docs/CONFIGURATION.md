# Configuration

All settings live under **Link Growth → Settings**. Secrets are stored encrypted (or as wp-config constants) and are never returned by the REST API; the UI shows only the last four characters.

## Business profile

Seeded from the live site (About, Contact, Lab Testing pages and the Organization structured data): brand and legal name, domain, Toronto address, contact email, founding year (2023), social profiles, categories. These values populate every directory listing. Keep them accurate — they are what gets submitted.

**Verified facts** (Settings → Content & facts) are the only statements the content generator may use. Each fact has a source URL and a *verified* flag. Untick a fact and it disappears from all generated copy. Add new facts only when they can be verified on the website.

**Linkable assets** (same tab) are quoted in listing packages and outreach, so each title and summary must pass the claims filter. A save with a flagged asset is refused as a whole (nothing on the page is saved) and the message names each asset and the phrase that was flagged.

## Integrations

| Provider | Needed for | Where to get it | Notes |
|---|---|---|---|
| **SE Ranking Data API** (primary) | Domain InLink Rank, regional organic traffic/keywords, backlink lists (competitor mining, backlink import) | SE Ranking account → API | Credits: authority 5/domain, domain overview 100/request, backlinks 1/record, summary 100 (off by default). Results are cached 30 days per domain. The dashboard shows the live credit balance. The live site already holds an SE Ranking key inside Easy MCP AI; paste the same key here. |
| **Google service account** | Search Console totals and GA4 referral traffic in background jobs | Google Cloud → IAM → Service account → JSON key; add the service-account email as a user in Search Console and as a Viewer on the GA4 property | The site already uses a service account for Easy MCP AI; the same JSON key works. Site Kit's own connection cannot be reused by background jobs, but the **Site Kit bridge** shows Search Console/GA4 numbers on the Overview page without extra credentials when you are logged in. |
| **Google Programmable Search** | Discovery queries (100 free/day) | Programmable Search Engine (search the entire web) + Custom Search JSON API key | Daily limit configurable. |
| **Brave Search API** | Discovery queries | api.search.brave.com | Alternative or additional search source. |
| **Serper.dev** | Google SERP results for discovery | serper.dev | Alternative or additional search source. |
| **Ahrefs API v3** | Domain Rating, organic metrics, backlinks | Ahrefs (API access depends on plan) | Optional. |
| **Semrush** | Authority Score, organic metrics, backlinks | Semrush API | Optional. CSV responses are parsed. |
| **Moz Links API** | Domain Authority, Spam Score | Moz | Optional. |
| **RDAP** | Domain registration date (age) | none (free) | On by default. |
| **Anthropic Claude** | Optional personalisation of outreach drafts | console.anthropic.com | Default model `claude-opus-5-5`. Used only when you build drafts with AI on the Outreach page; automatic drafting does not use it. All AI output passes the claims filter, and drafts you build always wait for your approval. |
| **Inbound email webhook** | Automatic reply tracking | Your email provider's inbound parse (Resend, Postmark, Mailgun, SendGrid) posting JSON or form data to `/wp-json/ppclg/v1/inbound` with the shared secret (see below) | Replies are matched by `In-Reply-To`, the message token, or sender address. |

Use **Test** next to each provider to run a cheap live check (the SE Ranking test uses the free subscription endpoint).

When more than one search provider is configured, discovery and brand-mention scans use the preferred one first and move on to the next when it fails or has no daily quota left. A query refused because of the API key (for example HTTP 401) does not count against that provider's daily limit.

**Inbound webhook secret.** The endpoint reads the secret from, in this order: the `X-PPCLG-Secret` header, a `secret` field in the body, a `secret` query parameter, or the password of HTTP Basic auth (the user name is ignored). Use the header when your provider allows it. For providers that can only be given a URL (for example SendGrid Inbound Parse, Mailgun routes, Postmark), use `https://SITE/wp-json/ppclg/v1/inbound?secret=SECRET` or `https://ppclg:SECRET@SITE/wp-json/ppclg/v1/inbound`; Settings → Integrations shows both for your site, ready to copy. In these URL forms use a secret of only letters and digits, or percent-encode any other character, and keep in mind that a secret in a URL can end up in server and proxy logs. A request without a secret gets 401, a wrong secret 403, and 503 while no secret is configured.

If a provider is not configured, every place that would use it says so explicitly (score components show "unknown", the Overview lists missing integrations). The engine keeps working with whatever data exists and never invents DR, DA, traffic or rankings.

## Automation and limits

- **Master switch** (`automation.enabled`) — **on by default**: the engine runs the full discover → evaluate → package → submit → verify → monitor cycle on its own schedule. Manual runs from the Automation Center always work.
- **Submission mode** (`automation.submission_mode`) — how automated form posting is authorised:
  - `auto` (default): any prospect scoring at or above `min_score_auto_queue` (70) qualifies by itself. The structural gates still decide each case: public listing form (never a comment or forum form), POST, no CAPTCHA, no login, no required upload, robots.txt allows the action URL, every required field mappable, package text passes the claims filter, limits not exhausted, no duplicate.
  - `authorized_only`: only websites whose per-site automation is *Always allow* (or that have a partner API configured) qualify.
  - `manual`: packages wait in the review queue, except for websites set to *Always allow* or with a partner API configured, which stay authorised. To stop all automated posting, turn off *Automated submissions*.
- **Per-site automation** (prospect drawer → Actions; `automation_allowed` on the prospect): *Inherit* (default, `0`) follows the submission mode; *Always allow* (`1`) authorises the site in every mode (choose it only for directories whose terms permit automated listings); *Never automate* (`-1`) refuses automated submission to the site in every mode, `auto` and partner APIs included, so its packages are prepared for you to submit by hand. *Always allow* does not skip any structural gate.
- Per-stage switches: discovery, evaluation, opportunities, packaging, **submissions**, **auto outreach drafts** (`auto_outreach_drafts`, on by default: drafts personalised outreach into evergreen "Auto" campaigns), **outreach autosend** (off by default; it needs a complete sender identity), monitoring, imports. Outreach autosend sends, without per-message review, follow-ups in campaigns set to auto-approve follow-ups. Auto-drafted first-contact emails are approved and sent automatically only when autosend is on **and** `outreach.require_approval` is off; with autosend alone they wait for your approval. Emails go out in your name, so both stay explicit opt-ins.
- **Confirmation**: switching *Automated submissions* or *Outreach autosend* on, and turning *Require individual approval* off while autosend is on, asks you to confirm. The REST API (`POST /automation/toggle`, `POST /settings`) refuses these changes with `ppclg_confirmation_required`, saving nothing, unless the request includes `confirm: true`; confirmed changes are recorded in the audit log.
- Daily limits: discovery queries, evaluations, submissions, outreach emails, backlink checks, provider lookups. Weekly limits: discovery queries, submissions, outreach emails.
- `min_score_keep` (default 50): prospects below it are rejected automatically. `min_score_auto_queue` (default 70): opportunities at or above it are packaged automatically — and in auto mode this is the quality bar a site must clear before the engine submits to it by itself. Raise it to be pickier, lower it to pursue more moderate sites.
- `max_execution_seconds` (default 20, minimum 15): the time budget each background tick may use. Lower values are raised to 15 s, the least some stages need per item. Keep it below the PHP time limit (30 s on the live site).

## Grow now (`growth`)

Settings → Automation & limits → *Grow now*:

- `growth.evaluate_rounds` (default 6, 1–20): the most evaluation batches one growth run performs. The daily evaluation limit still applies; remaining work continues in the background.
- `growth.step_seconds` (default 20, 8–25): how long each `POST /growth/step` request may work. Keep it below the PHP time limit (30 s on the live site) and any proxy timeout (Cloudflare allows 100 s).

## On-site SEO (`seo`)

Settings → Automation & limits → *On-site SEO*:

- `seo.indexnow` (on): submit new and changed public URLs to IndexNow. Submissions happen only when WordPress reports the `production` environment and *Discourage search engines* (Settings → Reading) is off. A live site whose host reports another environment type can opt in from code with the `ppclg_indexnow_environment` filter (see EXTENDING.md).
- `seo.indexnow_key`: generated once (32 hexadecimal characters) and served at `https://SITE/{key}.txt`. It is not editable through the settings form, because search engines would have to verify a new key; the On-site SEO page shows the key file link.
- `seo.internal_links` (on): compute internal-link suggestions weekly. Suggestions never change content by themselves.
- `seo.max_links_per_post` (default 3, 1–10): suggested links per post or page.
- `seo.audit_max_pages` (default 400, 20–5000): items read per site audit (posts, pages, products). Orphan pages are checked only when the audit covered every item.
- `seo.audit_http_sample` (on): after each audit, request at most 10 of the site's own URLs (after reading robots.txt) to confirm their HTTP status and canonical tag. A Cloudflare challenge is reported as unknown.

## Storefront compliance (`compliance`)

Settings → Outreach & compliance → *Storefront compliance gate*:

- `compliance.storefront_gate` (on): while the storefront check blocks, automated submissions become owner tasks and outreach sending pauses. Turning it off asks for confirmation (`POST /settings` refuses it with `ppclg_confirmation_required` unless the request carries `confirm: true`), and the change is recorded in the audit log.
- `compliance.acknowledged_hash`, `acknowledged_by`, `acknowledged_at`: written only by *Acknowledge* on the Overview (`POST /compliance/storefront/acknowledge` with `confirm: true`). `POST /settings` ignores these keys.

## Discovery

Geo modifiers (Canadian provinces and cities), competitor domains (use *Suggest from SE Ranking*), brand terms for unlinked-mention detection, custom queries, excluded domains and custom seeds. The query pool is generated from `data/discovery-queries.json` × topics × geo modifiers; each query is not repeated within the cooldown (30 days).

## Outreach and compliance

Sender name, sender email (use a mailbox on your own domain that is delivered by WP Mail SMTP), reply-to, **postal address (required by CASL/CAN-SPAM)**, signature, *Require individual approval* (`outreach.require_approval`, on by default and recommended: while it is on, automation never approves a message), follow-up delay and maximum, minimum days between contacts to the same website, send window and days. See [COMPLIANCE.md](COMPLIANCE.md).

To stop automatic drafting for one approach, pause its "Auto: …" campaign on the Outreach page: a paused, archived or completed Auto campaign gets no new drafts and is never re-approved by automation.

## Monitoring

Check intervals for new (daily) and stable (weekly) links, the number of consecutive failed checks before a link is declared lost (2), notification email, daily digest, import interval and limit.

## Advanced

Log level and retention, audit retention, crawler user agent, request timeout, crawl delay per host, maximum response size, backups list, delete-on-uninstall.
