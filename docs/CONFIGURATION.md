# Configuration

All settings live under **Link Growth → Settings**. Secrets are stored encrypted (or as wp-config constants) and are never returned by the REST API; the UI shows only the last four characters.

## Business profile

Seeded from the live site (About, Contact, Lab Testing pages and the Organization structured data): brand and legal name, domain, Toronto address, contact email, founding year (2023), social profiles, categories. These values populate every directory listing. Keep them accurate — they are what gets submitted.

**Verified facts** (Settings → Content & facts) are the only statements the content generator may use. Each fact has a source URL and a *verified* flag. Untick a fact and it disappears from all generated copy. Add new facts only when they can be verified on the website.

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
| **Anthropic Claude** | Optional personalisation of descriptions and outreach drafts | console.anthropic.com | Default model `claude-opus-5-5`. All AI output passes the claims filter; nothing is sent automatically. |
| **Inbound email webhook** | Automatic reply tracking | Your email provider's inbound parse (Resend, Postmark, Mailgun, SendGrid) posting JSON to `/wp-json/ppclg/v1/inbound` with header `X-PPCLG-Secret` | Replies are matched by `In-Reply-To`, the message token, or sender address. |

Use **Test** next to each provider to run a cheap live check (the SE Ranking test uses the free subscription endpoint).

If a provider is not configured, every place that would use it says so explicitly (score components show "unknown", the Overview lists missing integrations). The engine keeps working with whatever data exists and never invents DR, DA, traffic or rankings.

## Automation and limits

- **Master switch** (`automation.enabled`) — off after installation. Manual runs from the Automation Center always work.
- Per-stage switches: discovery, evaluation, opportunities, packaging, **submissions** (automated form posting; requires explicit per-prospect authorisation as well), **outreach autosend** (only affects scheduled follow-ups; initial messages always need individual approval unless you turn `require_approval` off), monitoring, imports.
- Daily limits: discovery queries, evaluations, submissions, outreach emails, backlink checks, provider lookups. Weekly limits: discovery queries, submissions, outreach emails.
- `min_score_keep` (default 50): prospects below it are rejected automatically. `min_score_auto_queue` (default 70): opportunities at or above it are packaged automatically.
- `max_execution_seconds` (default 20): the time budget each background tick may use (the live site's PHP limit is 30 s).

## Discovery

Geo modifiers (Canadian provinces and cities), competitor domains (use *Suggest from SE Ranking*), brand terms for unlinked-mention detection, custom queries, excluded domains and custom seeds. The query pool is generated from `data/discovery-queries.json` × topics × geo modifiers; each query is not repeated within the cooldown (30 days).

## Outreach and compliance

Sender name, sender email (use a mailbox on your own domain that is delivered by WP Mail SMTP), reply-to, **postal address (required by CASL/CAN-SPAM)**, signature, approval requirement, follow-up delay and maximum, minimum days between contacts to the same website, send window and days. See [COMPLIANCE.md](COMPLIANCE.md).

## Monitoring

Check intervals for new (daily) and stable (weekly) links, the number of consecutive failed checks before a link is declared lost (2), notification email, daily digest, import interval and limit.

## Advanced

Log level and retention, audit retention, crawler user agent, request timeout, crawl delay per host, maximum response size, backups list, delete-on-uninstall.
