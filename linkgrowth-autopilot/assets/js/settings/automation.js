/**
 * Settings tab "Automation & limits" (automation): stage flags, limits, submission mode, thresholds, Grow now and on-site SEO.
 * The Settings page (pages/settings.js) renders it from lga-config.settingsTabs; `tab` carries the page's shared
 * state and helpers (bind() edits the draft the page saves with every other tab's changes).
 */

import {
    el, card, callout, labelize,
} from '../ui.js';

export default function render( tab ) {
    const { bind, grid } = tab;
    const flags = [
        [ 'enabled', 'Master switch', 'Run background stages on the schedule.' ],
        [ 'discovery', 'Discovery', 'Search, seeds, competitor backlinks and mention scans.' ],
        [ 'evaluation', 'Evaluation', 'Crawl and score prospects.' ],
        [ 'opportunities', 'Opportunity finding', 'Create opportunities and scan for broken links.' ],
        [ 'packaging', 'Packaging', 'Build listing packages for review.' ],
        [ 'submissions', 'Automated submissions', 'Submit packages without a manual click wherever the policy and safety gates allow.' ],
        [ 'auto_outreach_drafts', 'Auto outreach drafts', 'Draft personalised outreach for qualified editorial prospects into evergreen “Auto” campaigns.' ],
        [ 'outreach_autosend', 'Outreach autosend', 'Send without per-message review (sender identity must be complete): follow-ups in campaigns set to “Auto-approve follow-ups”; auto-drafted first messages also need “Require individual approval” (Outreach tab) turned off. Off by default: emails go out in your name. Autopilot (Automation page) covers all of this with one switch.' ],
        [ 'monitoring', 'Backlink monitoring', 'Re-check backlinks and alert on changes.' ],
        [ 'imports', 'Provider imports', 'Import backlinks and referral sources periodically.' ],
    ];
    const limitKeys = [ 'discovery_queries', 'evaluations', 'submissions', 'outreach_emails', 'backlink_checks', 'provider_lookups' ];
    return el( 'div', { class: 'lga-stack' },
        callout( 'info', 'Live switches', 'These are the same flags as on the Automation page. The master switch, “Automated submissions” and “Outreach autosend” are applied through the Automation page’s switch when you save, and enabling either of the last two asks for confirmation here as well. Only the settings you change in this tab are saved.' ),
        card( { title: 'Stage flags', body: flags.map( ( [ key, label, hint ] ) => bind( `automation.${ key }`, { type: 'toggle', label, hint } ).el ) } ),
        card( { title: 'Daily limits', subtitle: '0 = unlimited', body: grid( limitKeys.map( ( k ) => bind( `automation.daily_limits.${ k }`, { type: 'number', label: labelize( k ), min: 0, default: 0 } ) ), 3 ) } ),
        card( { title: 'Weekly limits', subtitle: '0 = unlimited', body: grid( [ 'discovery_queries', 'submissions', 'outreach_emails' ].map( ( k ) => bind( `automation.weekly_limits.${ k }`, { type: 'number', label: labelize( k ), min: 0, default: 0 } ) ), 3 ) } ),
        card( { title: 'Submission mode', body: [
            bind( 'automation.submission_mode', { type: 'select', label: 'Who authorises automated submissions', options: [
                { value: 'auto', label: 'Auto — any site scoring at or above the auto-queue threshold (recommended)' },
                { value: 'authorized_only', label: 'Authorized only — each site must be set to “Always allow” by hand' },
                { value: 'manual', label: 'Manual — everything goes to the review queue' },
            ], hint: 'Whatever the mode, the engine only ever posts through public listing forms (POST, no CAPTCHA, no login, no required upload) that robots.txt allows, within the daily limits, and never twice to the same target.' } ).el,
        ] } ),
        card( { title: 'Thresholds & timing', body: grid( [
            bind( 'automation.min_score_auto_queue', { type: 'number', label: 'Minimum score to auto-package', min: 0, max: 100, default: 70, hint: 'In auto mode this is also the quality bar a site must clear before the engine submits to it by itself.' } ),
            bind( 'automation.min_score_keep', { type: 'number', label: 'Minimum score to keep', min: 0, max: 100, default: 50, hint: 'Prospects below this are rejected after evaluation.' } ),
            bind( 'automation.max_execution_seconds', { type: 'number', label: 'Max seconds per tick', min: 15, max: 25, default: 20, hint: 'Keep below the PHP time limit (30 s on this host).' } ),
            bind( 'automation.tick_interval', { type: 'number', label: 'Tick interval (seconds)', min: 60, step: 60, default: 300 } ),
            bind( 'automation.international', { type: 'toggle', label: 'Include non-Canadian sites', hint: 'When off, only sites judged Canadian are kept.' } ),
        ] ) } ),
        card( { title: 'Grow now', subtitle: 'The one-click run on the Overview page', body: grid( [
            bind( 'growth.evaluate_rounds', { type: 'number', label: 'Evaluation rounds per run', min: 1, max: 20, default: 6, hint: 'Most evaluation batches one run performs; the daily evaluation limit still applies. The scheduler continues any remaining work.' } ),
            bind( 'growth.step_seconds', { type: 'number', label: 'Seconds per step request', min: 8, max: 25, default: 20, hint: 'How long each request of a running growth run may work. Keep it below the PHP time limit.' } ),
        ] ) } ),
        card( { title: 'On-site SEO', subtitle: 'Site audit, IndexNow and internal-link suggestions (On-site SEO page)', body: grid( [
            bind( 'seo.indexnow', { type: 'toggle', label: 'Notify search engines through IndexNow', hint: 'Submits new and changed public URLs. Only on the production site, and never while WordPress discourages search engines.' } ),
            bind( 'seo.internal_links', { type: 'toggle', label: 'Suggest internal links', hint: 'Lists places where a product or key page could be linked. Without automatic links, content changes only when you click Apply on the On-site SEO page (WordPress keeps a revision).' } ),
            bind( 'seo.max_links_per_post', { type: 'number', label: 'Suggested links per item', min: 1, max: 10, default: 3 } ),
            bind( 'seo.auto_apply_links', { type: 'toggle', label: 'Add the best internal links automatically', hint: 'Always on while Autopilot is on. Highest relevance first, a revision of each page is kept, and “Undo automatic links” on the On-site SEO page removes them.' } ),
            bind( 'seo.auto_apply_per_day', { type: 'number', label: 'Automatic internal links per day', min: 1, max: 100, default: 20 } ),
            bind( 'seo.language_counterpart_meta_key', { type: 'text', label: 'Translation counterpart meta key (optional)', hint: 'Only if your translation tool is not Polylang or WPML: the post meta key that marks a translated page with the id of its original. Letters, digits and underscores. Leave empty to read none.' } ),
            bind( 'seo.audit_max_pages', { type: 'number', label: 'Pages per site audit', min: 20, max: 5000, default: 400, hint: 'Posts, pages and products read from the database. Orphan pages are checked only when the audit covers every item.' } ),
            bind( 'seo.audit_http_sample', { type: 'toggle', label: 'HTTP spot check after each audit', hint: 'Requests at most 10 of your own URLs (after reading robots.txt) to confirm their status and canonical tag.' } ),
        ] ) } ),
    );
}
