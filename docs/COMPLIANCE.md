# Compliance and ethics

## Positioning rules enforced in every piece of content

- Pure Peptides Canada is described as a **Canadian-owned supplier of research peptides and laboratory reagents** based in Toronto.
- Research products are **for in-vitro laboratory research use only**, not for human or veterinary use; this sentence is added to every description.
- **No therapeutic, medical, dosing, benefit, safety or approval claims.** The claims filter blocks: treat/cure/heal/therapy, human-use and dosing language, weight-loss/anti-aging/muscle claims, FDA/Health Canada approval or "pharmaceutical grade", specific purity percentages (the Lab Testing page and batch COAs are cited instead), superlatives (#1, best, cheapest), safety claims, supplement positioning, patient/consumer language.
- Only facts marked **verified** in the facts registry are used (seeded from the live About, Lab Testing and Contact pages).
- Negated compliance statements ("not for human use", "we do not provide dosing guidance") are allowed.

The filter runs on generated copy, on administrator edits, and on AI output. Violations are shown in the UI and block approval.

## Outreach (CASL in Canada, CAN-SPAM in the US)

Outreach targets published business/editorial addresses of organisations that are relevant to a laboratory supplier (CASL "conspicuous publication" / business-to-business relevance). The plugin enforces:

- **Identification**: sender name, a sending address on the business domain, and the business postal address in every email (required for both CASL and CAN-SPAM).
- **Unsubscribe**: a working one-click unsubscribe link (also exposed as `List-Unsubscribe` / `List-Unsubscribe-Post` headers) plus "reply with unsubscribe"; opt-outs are honoured immediately and permanently through the suppression list.
- **No deception**: no fake "RE:" subjects, accurate sender, plain-text messages that say what is being asked.
- **Restraint**: individual approval of every initial message by default (enabling *Outreach autosend* — an explicit, confirmed opt-in — lets auto-drafted messages send without review once the sender identity is complete), at most one follow-up by default, minimum 90 days between contacts to the same website, daily and weekly limits, send windows in the site time zone.
- **Suppression**: no-reply, support, billing and similar mailboxes are never contacted; suppressed domains/emails are skipped everywhere (discovery, contacts, sending).
- **Records**: every send, reply, opt-out and suppression is in the audit log.

Consent basis is recorded per contact. Review the drafts: the plugin personalises them, but you remain the sender.

## Submissions

- Automated form submission only to destinations you explicitly authorise, only through forms that have no CAPTCHA or login, where robots.txt allows the action URL, within limits, with an honest user agent and a descriptive referer.
- No mass posting of comments, forum messages, profile links or unsolicited promotional posts — those opportunity types do not exist in the system.
- Every submission records what was sent and the response excerpt.

## Crawling

- Identifies itself as `PPCLinkGrowthBot/1.0 (+https://purepeptidescanada.com/; link research)`.
- Honours `robots.txt` (including `Disallow` for `*` and for the bot's own token), waits between requests to the same host, caps response size, follows a small number of redirects and never requests private network addresses.

## Data protection

- Contact details are stored only for business outreach and shown only to administrators.
- Logs redact email addresses and credentials.
- Credentials are encrypted at rest; wp-config constants are supported.
- Data is deleted on uninstall only when you opt in.

## What the engine will not claim

A backlink never guarantees a ranking improvement. The dashboard reports what was acquired and what it is worth by the model's own criteria; it does not promise outcomes.
