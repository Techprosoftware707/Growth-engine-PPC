# Compliance and ethics

## Positioning rules enforced in every piece of content

- Pure Peptides Canada is described as a **Canadian-owned supplier of research peptides and laboratory reagents** based in Toronto.
- Research products are **for in-vitro laboratory research use only**, not for human or veterinary use; this sentence is added to every description.
- **No therapeutic, medical, dosing, benefit, safety or approval claims.** The claims filter blocks: treat/cure/heal/therapy, human-use and dosing language, weight-loss/anti-aging/muscle claims, FDA/Health Canada approval or "pharmaceutical grade", specific purity percentages (the Lab Testing page and batch COAs are cited instead), superlatives (#1, best, cheapest), safety claims, supplement positioning, patient/consumer language.
- Only facts marked **verified** in the facts registry are used (seeded from the live About, Lab Testing and Contact pages).
- Negated compliance statements ("not for human use", "we do not provide dosing guidance") are allowed. A negation only excuses the phrase it directly governs: it must stand at most two words before the flagged phrase, in the same clause, and none of the words in between may start a new assertion ("and", "but", "it", "we", "only"…). A negation inside the flagged phrase ("no side effects") or after it ("cures disease, not just symptoms") does not count.

The filter runs on generated copy, on administrator edits, on AI output and on custom linkable assets (Settings → Content & facts: a save with a flagged asset title or summary is refused and nothing is saved). Violations are shown in the UI and block approval. For submissions they are a hard blocker: a package whose text fails the check is never queued or posted, and the form adapter checks the final field values once more right before it posts.

## Storefront consistency

Listings and outreach say the products are for laboratory research use only. If the storefront itself frames them for consumer use, those listings and emails would misrepresent the business. The storefront compliance check (daily, on every growth run, and whenever products, product categories or tags, or menus change) reads the store from the database and reports consumer-use wording: weight loss, fat loss, slimming, anti-aging, tanning, skincare, beauty, cosmetics, nasal sprays, capsules, injectables, injections, syringes, needles, pen injectors, dosage and dosing, libido, sexual, muscle growth or gain, bodybuilding, nootropics, supplements, wellness, "for human (use)", and product titles that are consumer dosage forms (facial toner, cleanser, serum or cream, moisturizer, softgels, gummies, lozenges, oral sprays, sublingual). Recovery, growth, cognitive, metabolic, longevity and healing are reported for review without blocking. Negated statements ("Not for human use") are not findings.

While the check blocks and the gate is on, nothing is submitted automatically (packages become owner tasks) and no outreach email is sent, including *Send now*; drafting and packaging continue. If the check cannot run, the same pause applies. The plugin never edits the storefront and never decides for you: how the store presents itself is the owner's legal decision, ideally made with Canadian regulatory counsel. You can change the wording, or acknowledge the exact findings you reviewed (recorded in the audit log with your user and time); any change in the findings pauses automation again. Turning the gate off asks for confirmation and is recorded in the audit log.

## Outreach (CASL in Canada, CAN-SPAM in the US)

Outreach targets published business/editorial addresses of organisations that are relevant to a laboratory supplier (CASL "conspicuous publication" / business-to-business relevance). The plugin enforces:

- **Identification**: sender name, a sending address on the business domain, and the business postal address in every email (required for both CASL and CAN-SPAM).
- **Unsubscribe**: every email carries an unsubscribe link and `List-Unsubscribe` / `List-Unsubscribe-Post` headers, plus "reply with unsubscribe". The link opens a confirmation page with a single *Unsubscribe* button; the opt-out happens when that button is pressed or when the mail client sends the RFC 8058 one-click POST. Opening the link alone changes nothing, so mail-security scanners that fetch every link cannot unsubscribe anyone. Opt-outs are honoured immediately and permanently through the suppression list, and the contact's unsent messages are cancelled. A contact who opted out, or whose address is on the suppression list (other than through your own status change in the Contacts tab), cannot be set back to active there: only if the recipient has asked to be contacted again, remove the entry under Logs → Suppression list first (the removal is audited).
- **No deception**: no fake "RE:" subjects, accurate sender, plain-text messages that say what is being asked.
- **Owner decides who is pitched**: automatic drafting never drafts for a prospect that has a verified-database owner task, whether the import or an earlier source (seeds, search, manual entry) first added the site; those are owner tasks. A growth run (*Grow now*) has no send step: it drafts, and emails go out through the send stage only under the approval and autosend rules below.
- **Restraint**: individual approval of every message by default. *Outreach autosend* (an explicit, confirmed opt-in that also needs a complete sender identity) sends follow-ups without review only in campaigns set to auto-approve follow-ups. Auto-drafted first-contact emails are approved and sent without review only when autosend is on **and** *Require individual approval* (Settings → Outreach) is off; turning that setting off while autosend is on asks for its own confirmation. Automation never approves a message in a paused campaign, and a paused, archived or completed "Auto" campaign gets no new drafts. Rejecting or suppressing a prospect cancels its unsent messages. At most one follow-up by default, minimum 90 days between contacts to the same website, daily and weekly limits, send windows in the site time zone.
- **Suppression**: no-reply, support, billing and similar mailboxes are never contacted; suppressed domains/emails are skipped everywhere (discovery, contacts, sending).
- **Records**: every send, reply, opt-out and suppression is in the audit log.

Consent basis is recorded per contact. Review the drafts: the plugin personalises them, but you remain the sender.

## Submissions

- Automated form submission only when every gate passes. In the default *auto* mode a site qualifies by its score (at or above the auto-queue threshold); in *authorized only* mode only sites set to *Always allow* (or with a partner API) qualify; a site set to *Never automate* is refused in every mode. The form must be a public listing form (POST, no CAPTCHA, no login, no required upload) on a page and action URL that robots.txt allows, within limits, with an honest user agent and a descriptive referer.
- No comment, forum, profile or unsolicited promotional posting. Those opportunity types do not exist in the system, and the policy also refuses any form detected as a comment form, or whose action URL, field names or hidden values mark it as a blog comment, reply or forum (bbPress) form, whatever kind it was detected as.
- No paid placements: checkboxes about fees, payments, prices, invoices, sponsored, featured or premium placements or orders are never ticked, and neither are newsletter or marketing boxes. A required box of that kind stays unfilled, so the package goes to a person.
- No duplicates: a target or site that already received a submission (submitted or confirmed, or with a recorded send time) is never submitted to automatically again.
- No blind re-posting: a listing request is repeated automatically only when it provably never reached the destination (DNS, connection or TLS failure). A timeout or dropped connection after sending, a 5xx answer or an interrupted run leaves the outcome unknown: the submission goes to *Needs manual* with a note to check the destination first. An HTTP 429 with `Retry-After` is retried after that delay (at least 6 hours, at most 7 days); a 429 without it goes to a person.
- Limits are checked when a request is about to be sent, not when a package is built or approved, and only requests that actually reached a destination count against them.
- Every submission records what was sent and the response excerpt.
- The verified opportunity database is imported as owner tasks only: the plugin prepares the listing kit and steps, and a person submits each listing (the plugin never creates accounts, logs in or completes verification steps). The import makes no HTTP requests and contacts nobody.
- Owner tasks can be handed to a real staff member or assistant with the *Link Growth Submitter* role. That person creates each account themselves, under their own name, and discloses that they work for the business where the platform asks (personal-profile platforms). The role sees only the owner-task queue and can only mark tasks submitted; it cannot send email or see contacts, outreach, settings or logs. Tasks the owner must decide on never reach it: sites the owner rejected, suppressed or put on hold, text that fails the claims check, listings that may already have been sent or would be a duplicate, and outreach pitches to a person. Each mark is in the audit log with the user's id.

## Site content

- The site audit and the internal-link suggestions only read your content. A suggested link is added only when an administrator clicks *Apply* (or confirms *Apply all*) on the On-site SEO page; the content before the edit is kept as a revision first (the link is refused when WordPress keeps fewer than 2 revisions), the action is in the audit log, and nothing is changed when the surrounding text was edited after the suggestion was made. Page-builder content (Elementor, Divi, Beaver Builder, Bricks) and products (which keep no revisions) are never edited.
- IndexNow tells search engines which of your own public URLs are new or changed. It submits only from the production site, never while WordPress discourages search engines, never URLs that are drafts, password-protected or set to noindex, and it backs off for an hour after any refusal.

## Crawling

- Identifies itself as `PPCLinkGrowthBot/1.0 (+https://purepeptidescanada.com/; link research)` (Settings → Advanced).
- Honours `robots.txt` (including `Disallow` for `*` and for the bot's own token) for page crawls, for link probes (broken-link scans and backlink checks) and for every redirect hop, each against its own host's robots.txt. Provider API calls are not crawls and do not consult robots.txt.
- A robots.txt that cannot be read (network failure, 5xx, 429 or 408) means the whole host is treated as disallowed until it is re-checked: that result is cached for at least 1 hour, longer when the host sends `Retry-After`, at most 24 hours. A link probe reports such a host as unknown, never as a broken link. A robots.txt that was read is cached for 24 hours; a missing one (404) allows everything.
- Honours the robots.txt `Crawl-delay`: between two requests to the same host it waits the larger of that value (read up to 30 s) and the crawl-delay setting (default 2 s), never more than 60 s.
- Caps response size, follows a small number of redirects and never requests private network addresses.

## Data protection

- Contact details are stored only for business outreach and shown only to administrators (never to the *Link Growth Submitter* role).
- Logs redact email addresses and credentials.
- Credentials are encrypted at rest; wp-config constants are supported.
- Data is deleted on uninstall only when you opt in.

## What the engine will not claim

A backlink never guarantees a ranking improvement. The dashboard reports what was acquired and what it is worth by the model's own criteria; it does not promise outcomes.
