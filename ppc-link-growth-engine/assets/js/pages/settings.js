/**
 * Settings page: business profile, automation & limits, discovery, integrations,
 * outreach & compliance, content & facts, monitoring and advanced options.
 * Changes are collected into a draft and saved in one request (changed sections only).
 */

import * as api from '../api.js';
import {
    el, replace, badge, button, callout, kv, loading, errorState, emptyState, fmtNumber, fmtBytes, fmtDate, labelize, truncate, pageHeader, tabs, dataTable,
    formField, confirm, toast, card, extLink, copyButton, isObject, deepClone, deepEqual, getPath, setPath, modal, details, linkButton, icon,
} from '../ui.js';

const TAB_IDS = [ 'business', 'automation', 'discovery', 'integrations', 'outreach', 'content', 'monitoring', 'advanced' ];
const DAYS = [ [ 1, 'Mon' ], [ 2, 'Tue' ], [ 3, 'Wed' ], [ 4, 'Thu' ], [ 5, 'Fri' ], [ 6, 'Sat' ], [ 7, 'Sun' ] ];
const SEED_TYPES = [ 'directory', 'supplier_directory', 'association', 'publication', 'media', 'blog', 'educational', 'resource_page', 'podcast', 'government', 'ecommerce' ];
const OPPORTUNITIES = [ 'directory_listing', 'resource_page', 'editorial', 'partnership', 'expert_commentary', 'educational_contribution', 'broken_link', 'unlinked_mention', 'podcast', 'membership', 'supplier_listing' ];
const ASSET_KINDS = [ 'guide', 'resource', 'info', 'home', 'tool', 'page' ];

const obj = ( v ) => ( isObject( v ) ? v : {} );
const arr = ( v ) => ( Array.isArray( v ) ? v : [] );

/**
 * Automation flags owned by POST /automation/toggle. The form applies them there (with the same confirmation
 * as the Automation page for the gated ones) instead of inside POST /settings, so a save can neither skip that
 * confirmation nor carry a flag the owner did not touch in this tab.
 */
const TOGGLE_FLAGS = [ 'enabled', 'submissions', 'outreach_autosend' ];
const CONFIRM_FLAGS = {
    submissions: { title: 'Enable automated submissions', text: 'Enable automated submissions? In auto mode the engine submits by itself to high-scoring sites whenever every safety gate passes (public listing form, no CAPTCHA or login, robots.txt allows it). Every submission is logged in the audit trail.' },
    outreach_autosend: { title: 'Enable outreach autosend', text: 'Enable outreach autosend? Follow-ups in campaigns set to “Auto-approve follow-ups” will be sent to third parties in your name without your review. Initial cold emails that the engine drafts for qualified prospects (score at or above the auto-queue threshold, with a known contact) are also approved and sent without your review if “Require individual approval” (Settings → Outreach) is off; while it is on, they wait for your approval. Sending stays inside the send window and the outreach email limits, and every message includes the unsubscribe link.' },
};
/** Turning the storefront compliance gate off: POST /settings needs confirm=true for it. */
const STOREFRONT_GATE_OFF = { title: 'Turn off the storefront compliance gate', text: 'Turn off the storefront compliance gate? Automated submissions and outreach sending will continue even while the storefront check finds consumer-use wording (weight loss, skincare, nasal sprays, capsules, syringes, dosing…) that contradicts the research-use-only listings. How the store presents itself is your legal decision; the check keeps running and its findings stay visible on the Overview.' };
/** Turning “Require individual approval” off while autosend is on: POST /settings needs confirm=true for it. */
const APPROVAL_OFF_GATE = { title: 'Turn off individual approval', text: 'Turn off “Require individual approval”? Outreach autosend is on, so initial cold emails that the engine drafts for qualified prospects (score at or above the auto-queue threshold, with a known contact) will be approved and sent to third parties in your name without your review. Sending stays inside the send window and the outreach email limits, and every message includes the unsubscribe link.' };
const LIMIT_GROUPS = { daily_limits: 'Daily', weekly_limits: 'Weekly' };

/** Why POST /settings rejected a secret (secrets.reasons[key]). */
const SECRET_REJECT_REASONS = {
    invalid_key: 'not a setting this plugin stores',
    too_long: 'longer than 20,000 characters',
    invalid_service_account_json: 'must be the JSON key file Google issues for a service account, with client_email and private_key',
};

/**
 * The leaves of draft that differ from original, as a nested partial (lists and scalars are sent whole).
 * Saving only these means a tab opened before a change made elsewhere (another tab, the Automation page)
 * does not write its stale copy of the untouched siblings back over it.
 */
export function changedLeaves( draft, original ) {
    const out = {};
    for ( const key of Object.keys( obj( draft ) ) ) {
        const d = draft[ key ];
        const o = isObject( original ) ? original[ key ] : undefined;
        if ( isObject( d ) && isObject( o ) ) {
            const sub = changedLeaves( d, o );
            if ( Object.keys( sub ).length ) {
                out[ key ] = sub;
            }
        } else if ( ! deepEqual( d, o ) ) {
            out[ key ] = deepClone( d );
        }
    }
    return out;
}

/** Moves the toggle-owned automation flags out of a changedLeaves() partial: [[flag, value], …]. */
export function takeToggleFlags( changed ) {
    const flags = [];
    const automation = changed && isObject( changed.automation ) ? changed.automation : null;
    if ( ! automation ) {
        return flags;
    }
    for ( const flag of TOGGLE_FLAGS ) {
        if ( Object.prototype.hasOwnProperty.call( automation, flag ) ) {
            flags.push( [ flag, Boolean( automation[ flag ] ) ] );
            delete automation[ flag ];
        }
    }
    if ( ! Object.keys( automation ).length ) {
        delete changed.automation;
    }
    return flags;
}

/** Rate limits a changedLeaves() partial switches to 0 (= no cap), as labels like "Daily submissions". */
export function limitsRemoved( changed ) {
    const out = [];
    const automation = obj( obj( changed ).automation );
    for ( const [ group, label ] of Object.entries( LIMIT_GROUPS ) ) {
        for ( const [ key, value ] of Object.entries( obj( automation[ group ] ) ) ) {
            if ( Number( value ) === 0 ) {
                out.push( `${ label } ${ labelize( key ).toLowerCase() }` );
            }
        }
    }
    return out;
}

function providerBadge( p ) {
    if ( ! p ) {
        return null;
    }
    if ( p.error ) {
        return badge( 'Error', 'danger', { dot: true, title: String( p.error ) } );
    }
    if ( p.configured && p.enabled !== false ) {
        return badge( 'Ready', 'success', { dot: true } );
    }
    if ( p.configured ) {
        return badge( 'Disabled', 'neutral', { dot: true } );
    }
    return badge( 'Not configured', 'warning', { dot: true } );
}

export default async function render( root, ctx ) {
    const params = ctx.params || {};
    const state = {
        data: null,
        original: {},
        draft: {},
        pendingSecrets: {},
        factsRows: [],
        factsDirty: false,
        customSeeds: [],
        seedsDirty: false,
        seedsKnown: false,
        activeTab: TAB_IDS.includes( params.tab ) ? params.tab : 'business',
    };

    const content = el( 'div' );
    const dirtyLabel = el( 'span', { class: 'ppclg-muted ppclg-small' }, 'No unsaved changes' );
    const saveBtn = button( 'Save changes', { kind: 'primary', iconName: 'check', onClick: () => save() } );
    const discardBtn = button( 'Discard', { kind: 'ghost', onClick: async () => {
        if ( ! isDirty() || ( await confirm( 'Discard all unsaved changes?', { danger: true, confirmText: 'Discard' } ) ) ) {
            await load();
        }
    } } );
    const saveBar = el( 'div', { class: 'ppclg-savebar' }, el( 'div', { class: 'ppclg-row' }, icon( 'settings', 16 ), dirtyLabel ), el( 'div', { class: 'ppclg-row' }, discardBtn, saveBtn ) );

    replace( root,
        pageHeader( { title: 'Settings', description: 'Configure the business profile, automation limits, discovery inputs, integrations, outreach identity and monitoring. Secrets are encrypted at rest and never shown again in full.' } ),
        content,
        saveBar,
    );
    replace( content, loading( 'Loading settings…' ) );
    await load();

    window.addEventListener( 'beforeunload', ( e ) => {
        if ( isDirty() && root.isConnected ) {
            e.preventDefault();
            e.returnValue = '';
        }
    } );

    // ------------------------------------------------------------------
    // State helpers
    // ------------------------------------------------------------------
    function isDirty() {
        return ! deepEqual( state.draft, state.original ) || Object.keys( state.pendingSecrets ).length > 0 || state.factsDirty || state.seedsDirty;
    }

    function markDirty() {
        const dirty = isDirty();
        saveBar.classList.toggle( 'ppclg-savebar--dirty', dirty );
        dirtyLabel.textContent = dirty ? 'You have unsaved changes' : 'No unsaved changes';
        saveBtn.disabled = ! dirty;
    }

    function bind( path, spec ) {
        const current = getPath( state.draft, path, spec.default !== undefined ? spec.default : ( spec.type === 'list' ? [] : ( spec.type === 'toggle' || spec.type === 'checkbox' ? false : '' ) ) );
        return formField( {
            ...spec,
            value: current,
            onChange: ( v ) => {
                let next = v;
                if ( spec.type === 'number' && v === null ) {
                    // An emptied number field leaves the saved value alone: 0 would mean "unlimited" for the rate limits.
                    next = getPath( state.original, path, getPath( obj( obj( state.data ).defaults ), path, spec.default !== undefined ? spec.default : 0 ) );
                }
                setPath( state.draft, path, next );
                markDirty();
            },
        } );
    }

    async function load( { keepSecrets = {} } = {} ) {
        try {
            state.data = await api.get( '/settings' );
        } catch ( e ) {
            replace( content, errorState( { title: 'Settings could not be loaded', message: e && e.message ? e.message : '', retry: load } ) );
            return;
        }
        const d = state.data || {};
        state.original = deepClone( obj( d.settings ) );
        state.draft = deepClone( state.original );
        // Secrets the server refused are kept in their fields so the owner can correct them.
        state.pendingSecrets = { ...obj( keepSecrets ) };
        state.factsRows = Object.entries( obj( d.facts ) ).map( ( [ key, f ] ) => ( { key, statement: String( obj( f ).statement || '' ), verified: Boolean( obj( f ).verified ), source: String( obj( f ).source || '' ), group: String( obj( f ).group || 'custom' ) } ) );
        state.factsDirty = false;
        state.seedsKnown = Array.isArray( d.custom_seeds );
        state.customSeeds = arr( d.custom_seeds ).map( ( s ) => ( { url: s.url || '', name: s.name || '', type: s.type || 'directory', opportunity: s.opportunity || 'directory_listing', category: s.category || '', country: s.country || 'CA' } ) );
        state.seedsDirty = false;
        markDirty();
        drawTabs();
    }

    function secretLabel( key ) {
        return obj( obj( obj( state.data ).secrets )[ key ] ).label || labelize( key );
    }

    async function save() {
        const body = {};
        // Leaf-level diff: untouched settings are never re-sent from this (possibly stale) tab.
        const changed = changedLeaves( state.draft, state.original );
        const flagChanges = takeToggleFlags( changed );
        const removed = limitsRemoved( changed );
        if ( removed.length && ! ( await confirm( `Remove these rate limits? ${ removed.join( ', ' ) } will have no cap (0 = unlimited).`, { title: 'Remove rate limits', confirmText: 'Remove limits', danger: true } ) ) ) {
            toast( 'Nothing was saved.', 'info' );
            return;
        }
        for ( const [ flag, value ] of flagChanges ) {
            const gate = CONFIRM_FLAGS[ flag ];
            if ( value && gate && ! ( await confirm( gate.text, { title: gate.title, confirmText: 'Enable', danger: true } ) ) ) {
                toast( 'Nothing was saved.', 'info' );
                return;
            }
        }
        let approvalOffConfirmed = false;
        if ( obj( changed.outreach ).require_approval === false && getPath( state.original, 'automation.outreach_autosend', false ) ) {
            const autosendOff = flagChanges.findIndex( ( [ flag, value ] ) => flag === 'outreach_autosend' && ! value );
            if ( autosendOff >= 0 ) {
                // Autosend is switched off in this save too: send that with the settings so both apply together.
                flagChanges.splice( autosendOff, 1 );
                setPath( changed, 'automation.outreach_autosend', false );
            } else if ( await confirm( APPROVAL_OFF_GATE.text, { title: APPROVAL_OFF_GATE.title, confirmText: 'Turn off', danger: true } ) ) {
                approvalOffConfirmed = true;
            } else {
                toast( 'Nothing was saved.', 'info' );
                return;
            }
        }
        let gateOffConfirmed = false;
        if ( obj( changed.compliance ).storefront_gate === false && getPath( state.original, 'compliance.storefront_gate', true ) ) {
            if ( await confirm( STOREFRONT_GATE_OFF.text, { title: STOREFRONT_GATE_OFF.title, confirmText: 'Turn off', danger: true } ) ) {
                gateOffConfirmed = true;
            } else {
                toast( 'Nothing was saved.', 'info' );
                return;
            }
        }
        if ( Object.keys( changed ).length ) {
            body.settings = changed;
            if ( approvalOffConfirmed || gateOffConfirmed ) {
                body.confirm = true;
            }
        }
        if ( Object.keys( state.pendingSecrets ).length ) {
            body.secrets = { ...state.pendingSecrets };
        }
        if ( state.factsDirty ) {
            const facts = {};
            for ( const row of state.factsRows ) {
                const key = String( row.key || '' ).trim().toLowerCase().replace( /[^a-z0-9_]+/g, '_' );
                if ( key && String( row.statement || '' ).trim() ) {
                    facts[ key ] = { statement: row.statement, verified: Boolean( row.verified ), source: row.source || '', group: row.group || 'custom' };
                }
            }
            body.facts = facts;
        }
        if ( state.seedsDirty ) {
            body.custom_seeds = state.customSeeds.filter( ( s ) => String( s.url || '' ).trim() !== '' );
        }
        if ( ! Object.keys( body ).length && ! flagChanges.length ) {
            toast( 'Nothing to save.', 'info' );
            return;
        }
        let res = null;
        if ( Object.keys( body ).length ) {
            try {
                res = await api.post( '/settings', body, { silent: true } );
            } catch ( e ) {
                // Nothing was saved (e.g. a linkable asset failed the claims filter, which the message names):
                // keep the draft and leave the reason up long enough to read.
                toast( e && e.message ? e.message : 'The settings could not be saved.', 'error', { timeout: 15000 } );
                return;
            }
        }
        for ( const [ flag, value ] of flagChanges ) {
            await api.post( '/automation/toggle', value && CONFIRM_FLAGS[ flag ] ? { key: flag, value, confirm: true } : { key: flag, value } );
        }

        // POST /settings answers 200 even when it stored none of some secrets: report them, keep the input.
        const secretResult = obj( obj( res ).secrets );
        const rejected = arr( secretResult.rejected ).map( String );
        const locked = arr( secretResult.locked ).map( String );
        const keepSecrets = {};
        for ( const key of rejected ) {
            if ( typeof state.pendingSecrets[ key ] === 'string' && state.pendingSecrets[ key ] !== '__clear__' ) {
                keepSecrets[ key ] = state.pendingSecrets[ key ];
            }
        }
        const secretsSaved = arr( secretResult.updated ).length + arr( secretResult.cleared ).length;
        const otherSaved = Boolean( body.settings || body.facts || body.custom_seeds || flagChanges.length || secretsSaved );
        if ( rejected.length ) {
            const reasons = obj( secretResult.reasons );
            if ( rejected.every( ( key ) => SECRET_REJECT_REASONS[ reasons[ key ] ] ) ) {
                const list = rejected.map( ( key ) => `${ secretLabel( key ) } (${ SECRET_REJECT_REASONS[ reasons[ key ] ] })` ).join( '; ' );
                toast( `Not saved: ${ list }. Your input is kept so you can correct it.`, 'error', { timeout: 12000 } );
            } else {
                const why = rejected.includes( 'google_service_account_json' )
                    ? 'A Google service-account key must be the JSON key file Google issues (with client_email and private_key); other values must be at most 20,000 characters.'
                    : 'The value is invalid or longer than 20,000 characters.';
                toast( `Not saved: ${ rejected.map( secretLabel ).join( ', ' ) }. ${ why } Your input is kept so you can correct it.`, 'error', { timeout: 12000 } );
            }
        }
        if ( locked.length ) {
            toast( `${ locked.map( secretLabel ).join( ', ' ) } ${ locked.length === 1 ? 'is' : 'are' } defined in wp-config.php and cannot be changed here.`, 'warning', { timeout: 9000 } );
        }
        if ( otherSaved ) {
            toast( rejected.length || locked.length ? 'Your other changes were saved.' : 'Settings saved.', 'success' );
        }
        await load( { keepSecrets } );
    }

    // ------------------------------------------------------------------
    // Tabs
    // ------------------------------------------------------------------
    function drawTabs() {
        const view = tabs( {
            active: state.activeTab,
            rerender: true,
            onChange: ( id ) => {
                state.activeTab = id;
                ctx.setHash( { tab: id } );
            },
            items: [
                { id: 'business', label: 'Business profile', render: ( p ) => p.appendChild( businessTab() ) },
                { id: 'automation', label: 'Automation & limits', render: ( p ) => p.appendChild( automationTab() ) },
                { id: 'discovery', label: 'Discovery', render: ( p ) => p.appendChild( discoveryTab() ) },
                { id: 'integrations', label: 'Integrations', render: ( p ) => p.appendChild( integrationsTab() ) },
                { id: 'outreach', label: 'Outreach & compliance', render: ( p ) => p.appendChild( outreachTab() ) },
                { id: 'content', label: 'Content & facts', render: ( p ) => p.appendChild( contentTab() ) },
                { id: 'monitoring', label: 'Monitoring', render: ( p ) => p.appendChild( monitoringTab() ) },
                { id: 'advanced', label: 'Advanced', render: ( p ) => p.appendChild( advancedTab() ) },
            ],
        } );
        replace( content, view.el );
    }

    function grid( fields, cols = 2 ) {
        return el( 'div', { class: [ 'ppclg-form-grid', cols === 3 ? 'ppclg-form-grid--3' : '' ] }, fields.map( ( f ) => ( f && f.el ? f.el : f ) ) );
    }

    // ----- Business ---------------------------------------------------
    function businessTab() {
        return el( 'div', { class: 'ppclg-stack' },
            card( { title: 'Identity', subtitle: 'Used verbatim in listing packages and outreach signatures', body: grid( [
                bind( 'site.brand_name', { type: 'text', label: 'Brand name', required: true } ),
                bind( 'site.legal_name', { type: 'text', label: 'Legal name' } ),
                bind( 'site.domain', { type: 'text', label: 'Site domain', hint: 'Without protocol, e.g. purepeptidescanada.com' } ),
                bind( 'site.home_url', { type: 'url', label: 'Home URL' } ),
                bind( 'site.tagline', { type: 'textarea', label: 'Tagline', rows: 2, full: true } ),
                bind( 'site.logo_url', { type: 'url', label: 'Logo URL', full: true } ),
            ] ) } ),
            card( { title: 'Contact & address', body: grid( [
                bind( 'site.email', { type: 'email', label: 'Public email' } ),
                bind( 'site.phone', { type: 'tel', label: 'Phone' } ),
                bind( 'site.address', { type: 'text', label: 'Street address' } ),
                bind( 'site.city', { type: 'text', label: 'City' } ),
                bind( 'site.region', { type: 'text', label: 'Province / region' } ),
                bind( 'site.postal_code', { type: 'text', label: 'Postal code' } ),
                bind( 'site.country', { type: 'text', label: 'Country (ISO-2)', maxlength: 2 } ),
                bind( 'site.founded_year', { type: 'text', label: 'Founded year', maxlength: 4 } ),
            ] ) } ),
            card( { title: 'Profiles & categories', body: grid( [
                bind( 'site.social', { type: 'list', label: 'Social profile URLs (one per line)', rows: 4 } ),
                bind( 'site.categories', { type: 'list', label: 'Directory categories to request (one per line)', rows: 4, hint: 'Tried in order when a directory offers a category list.' } ),
            ] ) } ),
        );
    }

    // ----- Automation -------------------------------------------------
    function automationTab() {
        const flags = [
            [ 'enabled', 'Master switch', 'Run background stages on the schedule.' ],
            [ 'discovery', 'Discovery', 'Search, seeds, competitor backlinks and mention scans.' ],
            [ 'evaluation', 'Evaluation', 'Crawl and score prospects.' ],
            [ 'opportunities', 'Opportunity finding', 'Create opportunities and scan for broken links.' ],
            [ 'packaging', 'Packaging', 'Build listing packages for review.' ],
            [ 'submissions', 'Automated submissions', 'Submit packages without a manual click wherever the policy and safety gates allow.' ],
            [ 'auto_outreach_drafts', 'Auto outreach drafts', 'Draft personalised outreach for qualified editorial prospects into evergreen “Auto” campaigns.' ],
            [ 'outreach_autosend', 'Outreach autosend', 'Send without per-message review (sender identity must be complete): follow-ups in campaigns set to “Auto-approve follow-ups”; auto-drafted first messages also need “Require individual approval” (Outreach tab) turned off. Off by default: emails go out in your name.' ],
            [ 'monitoring', 'Backlink monitoring', 'Re-check backlinks and alert on changes.' ],
            [ 'imports', 'Provider imports', 'Import backlinks and referral sources periodically.' ],
        ];
        const limitKeys = [ 'discovery_queries', 'evaluations', 'submissions', 'outreach_emails', 'backlink_checks', 'provider_lookups' ];
        return el( 'div', { class: 'ppclg-stack' },
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
                bind( 'seo.internal_links', { type: 'toggle', label: 'Suggest internal links', hint: 'Suggestions only: content changes only when you click Apply on the On-site SEO page (WordPress keeps a revision).' } ),
                bind( 'seo.max_links_per_post', { type: 'number', label: 'Suggested links per item', min: 1, max: 10, default: 3 } ),
                bind( 'seo.audit_max_pages', { type: 'number', label: 'Pages per site audit', min: 20, max: 5000, default: 400, hint: 'Posts, pages and products read from the database. Orphan pages are checked only when the audit covers every item.' } ),
                bind( 'seo.audit_http_sample', { type: 'toggle', label: 'HTTP spot check after each audit', hint: 'Requests at most 10 of your own URLs (after reading robots.txt) to confirm their status and canonical tag.' } ),
            ] ) } ),
        );
    }

    // ----- Discovery --------------------------------------------------
    function discoveryTab() {
        const competitorsField = bind( 'discovery.competitor_domains', { type: 'list', label: 'Competitor domains (one per line)', rows: 5, hint: 'Their backlink profiles are mined for prospects (requires SE Ranking).' } );
        const suggestBtn = button( 'Suggest from SE Ranking', { small: true, iconName: 'search', onClick: async () => {
            const res = await api.post( '/settings/competitors/suggest', {} );
            const items = arr( Array.isArray( res ) ? res : ( res && ( res.competitors || res.items || res.data ) ) ).map( ( c ) => ( typeof c === 'string' ? { domain: c } : obj( c ) ) ).filter( ( c ) => c.domain );
            if ( ! items.length ) {
                toast( 'No suggestions returned. Check that SE Ranking is configured and the domain has ranking data.', 'warning' );
                return;
            }
            const selected = new Set();
            modal( {
                title: 'Suggested competitors',
                content: el( 'div', { class: 'ppclg-prospect-picker' }, items.slice( 0, 40 ).map( ( c ) => {
                    const cb = el( 'input', { type: 'checkbox', 'aria-label': c.domain } );
                    cb.addEventListener( 'change', () => ( cb.checked ? selected.add( c.domain ) : selected.delete( c.domain ) ) );
                    const extra = Object.entries( c ).filter( ( [ k, v ] ) => k !== 'domain' && ( typeof v === 'number' || typeof v === 'string' ) ).slice( 0, 3 ).map( ( [ k, v ] ) => `${ labelize( k ) }: ${ typeof v === 'number' ? fmtNumber( v ) : v }` ).join( ' · ' );
                    return el( 'label', {}, cb, el( 'span', { class: 'ppclg-grow' }, el( 'span', { class: 'ppclg-strong' }, c.domain ), extra ? el( 'span', { class: 'ppclg-cell-sub' }, extra ) : null ) );
                } ) ),
                actions: [
                    { label: 'Cancel', onClick: ( close ) => close() },
                    { label: 'Add selected', kind: 'primary', onClick: ( close ) => {
                        const current = competitorsField.get();
                        const next = Array.from( new Set( current.concat( Array.from( selected ) ) ) );
                        competitorsField.set( next );
                        setPath( state.draft, 'discovery.competitor_domains', next );
                        markDirty();
                        close();
                    } },
                ],
            } );
        } } );

        const seedsHost = el( 'div' );
        const seedCatalog = arr( obj( state.data ).seeds );
        const renderSeeds = () => replace( seedsHost, rowEditor( {
            rows: state.customSeeds,
            columns: [
                { key: 'url', label: 'URL', type: 'url', width: '26%' },
                { key: 'name', label: 'Name', type: 'text' },
                { key: 'type', label: 'Site type', type: 'select', options: SEED_TYPES },
                { key: 'opportunity', label: 'Opportunity', type: 'select', options: OPPORTUNITIES },
                { key: 'category', label: 'Category', type: 'text' },
                { key: 'country', label: 'Country', type: 'text', width: '70px' },
            ],
            newRow: () => ( { url: '', name: '', type: 'directory', opportunity: 'directory_listing', category: '', country: 'CA' } ),
            addLabel: 'Add seed',
            onChange: () => {
                state.seedsDirty = true;
                markDirty();
            },
        } ) );
        renderSeeds();

        return el( 'div', { class: 'ppclg-stack' },
            card( { title: 'Query inputs', subtitle: `${ fmtNumber( obj( state.data ).query_pool_size ) } queries in the generated pool`, body: grid( [
                bind( 'discovery.geo_modifiers', { type: 'list', label: 'Geographic modifiers (one per line)', rows: 5 } ),
                bind( 'discovery.brand_terms', { type: 'list', label: 'Brand terms for mention scans (one per line)', rows: 5 } ),
                bind( 'discovery.custom_queries', { type: 'list', label: 'Custom search queries (one per line)', rows: 5, full: true, hint: 'Added to the generated pool verbatim.' } ),
                bind( 'discovery.excluded_domains', { type: 'list', label: 'Excluded domains (one per line)', rows: 4, full: true, hint: 'Never discovered or evaluated (big platforms are excluded by default).' } ),
            ] ) } ),
            card( { title: 'Competitors', actions: suggestBtn, body: competitorsField.el } ),
            card( { title: 'Cadence', body: grid( [
                bind( 'discovery.query_cooldown_days', { type: 'number', label: 'Query cooldown (days)', min: 1, default: 30, hint: 'A query is not repeated within this window.' } ),
                bind( 'discovery.results_per_query', { type: 'number', label: 'Results per query', min: 1, max: 50, default: 10 } ),
                bind( 'discovery.seed_catalog', { type: 'toggle', label: 'Use the seed catalog', hint: `${ fmtNumber( seedCatalog.length ) } curated starting points (directories, associations, publications).` } ),
            ] ) } ),
            card( {
                title: 'Custom seeds',
                subtitle: state.seedsKnown ? 'Appended to the built-in catalog' : 'Appended to the built-in catalog — saving replaces custom seeds added earlier',
                body: [ seedsHost, seedCatalog.length ? details( `Built-in catalog (${ seedCatalog.length } entries)`, dataTable( { dense: true, plain: true, rows: seedCatalog.slice( 0, 200 ), columns: [
                    { key: 'name', label: 'Name', render: ( s ) => s.name || s.url },
                    { key: 'url', label: 'URL', render: ( s ) => extLink( s.url, truncate( s.url, 50 ) ) },
                    { key: 'type', label: 'Type', render: ( s ) => labelize( s.type ) },
                    { key: 'opportunity', label: 'Opportunity', render: ( s ) => labelize( s.opportunity ) },
                    { key: 'country', label: 'Country' },
                ] } ) ) : null ],
            } ),
        );
    }

    // ----- Integrations -----------------------------------------------
    function secretField( key, { label, multiline = false, hint = '' } = {} ) {
        const info = obj( obj( obj( state.data ).secrets )[ key ] );
        const title = label || info.label || labelize( key );
        const host = el( 'div', { class: 'ppclg-field' } );
        const render = () => {
            const pending = state.pendingSecrets[ key ];
            const rows = [ el( 'span', { class: 'ppclg-field-label' }, title ) ];
            if ( info.locked ) {
                rows.push( el( 'div', { class: 'ppclg-secret' }, el( 'span', { class: 'ppclg-secret-masked' }, info.masked || 'configured' ), badge( 'Defined in wp-config.php', 'info', { iconName: 'lock' } ) ) );
            } else if ( pending === '__clear__' ) {
                rows.push( el( 'div', { class: 'ppclg-secret' }, badge( 'Will be removed on save', 'danger' ), button( 'Undo', { small: true, kind: 'ghost', onClick: () => {
                    delete state.pendingSecrets[ key ];
                    markDirty();
                    render();
                } } ) ) );
            } else if ( pending !== undefined ) {
                const input = el( multiline ? 'textarea' : 'input', { type: multiline ? null : 'password', class: multiline ? 'ppclg-textarea ppclg-textarea--mono' : 'ppclg-input', rows: 6, value: pending, autocomplete: 'new-password', 'aria-label': title, placeholder: multiline ? '{ "type": "service_account", … }' : 'Paste the new value' } );
                input.addEventListener( 'input', () => {
                    if ( input.value === '' ) {
                        delete state.pendingSecrets[ key ];
                    } else {
                        state.pendingSecrets[ key ] = input.value;
                    }
                    markDirty();
                } );
                rows.push( el( 'div', { class: 'ppclg-secret' }, el( 'div', { class: 'ppclg-grow' }, input ), button( 'Cancel', { small: true, kind: 'ghost', onClick: () => {
                    delete state.pendingSecrets[ key ];
                    markDirty();
                    render();
                } } ) ) );
                requestAnimationFrame( () => input.focus() );
            } else {
                rows.push( el( 'div', { class: 'ppclg-secret' },
                    el( 'span', { class: 'ppclg-secret-masked' }, info.configured ? ( info.masked || '••••••••' ) : 'Not set' ),
                    info.configured ? badge( 'Configured', 'success', { dot: true } ) : null,
                    button( info.configured ? 'Replace' : 'Set', { small: true, iconName: 'edit', onClick: () => {
                        state.pendingSecrets[ key ] = '';
                        render();
                        delete state.pendingSecrets[ key ];
                    } } ),
                    info.configured ? button( 'Clear', { small: true, kind: 'danger', onClick: async () => {
                        if ( await confirm( `Remove the stored ${ title }? The integration stops working until a new value is saved.`, { danger: true, confirmText: 'Remove on save' } ) ) {
                            state.pendingSecrets[ key ] = '__clear__';
                            markDirty();
                            render();
                        }
                    } } ) : null,
                ) );
            }
            if ( hint ) {
                rows.push( el( 'div', { class: 'ppclg-field-hint' }, hint ) );
            }
            replace( host, rows );
        };
        render();
        return host;
    }

    function testButton( provider, label = 'Test connection' ) {
        const result = el( 'span', { class: 'ppclg-row' } );
        const btn = button( label, { small: true, iconName: 'activity', onClick: async () => {
            replace( result );
            let res;
            try {
                res = await api.post( '/settings/test', { provider }, { silent: true } );
            } catch ( e ) {
                replace( result, badge( 'Failed', 'danger', { dot: true } ), el( 'span', { class: 'ppclg-small' }, e && e.message ? e.message : '' ) );
                return;
            }
            const ok = Boolean( res && res.ok );
            replace( result, badge( ok ? 'OK' : 'Failed', ok ? 'success' : 'danger', { dot: true } ), el( 'span', { class: 'ppclg-small' }, String( ( res && res.message ) || '' ) ), res && isObject( res.data ) && Object.keys( res.data ).length ? details( 'Details', kv( Object.entries( res.data ).filter( ( [ , v ] ) => typeof v !== 'object' ).map( ( [ k, v ] ) => [ labelize( k ), String( v ) ] ), { compact: true } ) ) : null );
        } } );
        return el( 'div', { class: 'ppclg-row' }, btn, result, el( 'span', { class: 'ppclg-notice-inline' }, 'Tests use saved values.' ) );
    }

    function integrationsTab() {
        const providers = obj( obj( state.data ).providers );
        const s = state.draft;
        const has = ( path ) => getPath( s, path ) !== undefined;
        const inboundUrl = api.buildUrl( '/inbound' );
        // URL forms for providers that can only be given a URL (the server also reads ?secret= and the Basic auth password).
        const inboundQueryUrl = api.buildUrl( '/inbound', { secret: 'SECRET' } );
        let inboundBasicUrl;
        try {
            const u = new URL( inboundUrl );
            u.username = 'ppclg';
            u.password = 'SECRET';
            inboundBasicUrl = u.toString();
        } catch ( e ) {
            inboundBasicUrl = inboundUrl.replace( /^(https?:\/\/)/i, '$1ppclg:SECRET@' );
        }
        const urlRow = ( url ) => el( 'div', { class: 'ppclg-row' }, el( 'code', { class: 'ppclg-secret-masked', style: { flex: '1 1 auto' } }, url ), copyButton( url ) );
        return el( 'div', { class: 'ppclg-stack' },
            ! obj( state.data ).crypto_strong ? callout( 'warning', 'Weak encryption keys', 'AUTH_KEY / AUTH_SALT in wp-config.php look like defaults. Secrets are encrypted with them; generate strong salts before storing API keys.' ) : null,
            el( 'div', { class: 'ppclg-grid ppclg-grid--2' },
                card( { title: 'SE Ranking', subtitle: 'Domain authority, traffic, keywords, competitors and backlinks (primary provider)', actions: providerBadge( providers.seranking ), body: el( 'div', { class: 'ppclg-stack' },
                    bind( 'providers.seranking.enabled', { type: 'toggle', label: 'Enabled' } ).el,
                    secretField( 'seranking_api_key' ),
                    grid( [
                        bind( 'providers.seranking.region', { type: 'text', label: 'Region / search engine', hint: 'e.g. ca, us, uk' } ),
                        bind( 'providers.seranking.cache_days', { type: 'number', label: 'Cache days', min: 1, default: 30 } ),
                        has( 'providers.seranking.fetch_overview' ) ? bind( 'providers.seranking.fetch_overview', { type: 'toggle', label: 'Fetch domain overview' } ) : null,
                        has( 'providers.seranking.fetch_summary' ) ? bind( 'providers.seranking.fetch_summary', { type: 'toggle', label: 'Fetch backlink summary' } ) : null,
                    ].filter( Boolean ) ),
                    testButton( 'seranking' ),
                ) } ),
                card( { title: 'Ahrefs', subtitle: 'Domain rating and backlink export', actions: providerBadge( providers.ahrefs ), body: el( 'div', { class: 'ppclg-stack' },
                    bind( 'providers.ahrefs.enabled', { type: 'toggle', label: 'Enabled' } ).el,
                    secretField( 'ahrefs_api_key' ),
                    grid( [
                        bind( 'providers.ahrefs.cache_days', { type: 'number', label: 'Cache days', min: 1, default: 30 } ),
                        has( 'providers.ahrefs.fetch_metrics' ) ? bind( 'providers.ahrefs.fetch_metrics', { type: 'toggle', label: 'Fetch domain metrics' } ) : null,
                    ].filter( Boolean ) ),
                    testButton( 'ahrefs' ),
                ) } ),
                card( { title: 'Semrush', subtitle: 'Authority score and organic traffic', actions: providerBadge( providers.semrush ), body: el( 'div', { class: 'ppclg-stack' },
                    bind( 'providers.semrush.enabled', { type: 'toggle', label: 'Enabled' } ).el,
                    secretField( 'semrush_api_key' ),
                    grid( [
                        bind( 'providers.semrush.database', { type: 'text', label: 'Database', hint: 'e.g. ca, us' } ),
                        bind( 'providers.semrush.cache_days', { type: 'number', label: 'Cache days', min: 1, default: 30 } ),
                    ] ),
                    testButton( 'semrush' ),
                ) } ),
                card( { title: 'Moz', subtitle: 'Domain authority and spam score', actions: providerBadge( providers.moz ), body: el( 'div', { class: 'ppclg-stack' },
                    bind( 'providers.moz.enabled', { type: 'toggle', label: 'Enabled' } ).el,
                    secretField( 'moz_access_id' ),
                    secretField( 'moz_secret_key' ),
                    grid( [ bind( 'providers.moz.cache_days', { type: 'number', label: 'Cache days', min: 1, default: 30 } ) ] ),
                    testButton( 'moz' ),
                ) } ),
            ),
            card( { title: 'Google Search Console & Analytics 4', subtitle: 'Service account for background use; Site Kit is used as a dashboard fallback when active', actions: el( 'span', { class: 'ppclg-row' }, providerBadge( providers.google_search_console ), providerBadge( providers.google_analytics_4 ) ), body: el( 'div', { class: 'ppclg-stack' },
                bind( 'providers.google.enabled', { type: 'toggle', label: 'Enabled' } ).el,
                secretField( 'google_service_account_json', { multiline: true, hint: 'Paste the JSON key of a service account that has been added as a user on the Search Console property and the GA4 property.' } ),
                grid( [
                    bind( 'providers.google.gsc_property', { type: 'text', label: 'Search Console property', hint: 'sc-domain:example.com or https://example.com/' } ),
                    bind( 'providers.google.ga4_property_id', { type: 'text', label: 'GA4 property ID', hint: 'Numeric, e.g. 123456789' } ),
                    bind( 'providers.google.use_sitekit', { type: 'toggle', label: 'Use Site Kit data when available', hint: providers.site_kit && providers.site_kit.configured ? 'Site Kit is active on this site.' : 'Site Kit is not active.' } ),
                ] ),
                el( 'div', { class: 'ppclg-row' }, testButton( 'google_search_console', 'Test Search Console' ), testButton( 'google_analytics_4', 'Test GA4' ), testButton( 'sitekit', 'Test Site Kit bridge' ) ),
            ) } ),
            card( { title: 'Search providers', subtitle: 'Used for discovery queries and listing verification', body: el( 'div', { class: 'ppclg-stack' },
                grid( [ bind( 'providers.search.primary', { type: 'select', label: 'Primary provider', options: { auto: 'Automatic (most remaining quota)', google_cse: 'Google Custom Search', brave: 'Brave Search', serper: 'Serper.dev' } } ) ] ),
                el( 'div', { class: 'ppclg-grid ppclg-grid--3' },
                    card( { flat: true, title: 'Google Custom Search', actions: providerBadge( providers.google_cse ), body: el( 'div', { class: 'ppclg-stack' }, secretField( 'google_cse_api_key' ), secretField( 'google_cse_cx' ), bind( 'providers.search.google_cse_daily_limit', { type: 'number', label: 'Daily query cap', min: 0, default: 90 } ).el, testButton( 'google_cse' ) ) } ),
                    card( { flat: true, title: 'Brave Search', actions: providerBadge( providers.brave ), body: el( 'div', { class: 'ppclg-stack' }, secretField( 'brave_api_key' ), bind( 'providers.search.brave_daily_limit', { type: 'number', label: 'Daily query cap', min: 0, default: 60 } ).el, testButton( 'brave' ) ) } ),
                    card( { flat: true, title: 'Serper.dev', actions: providerBadge( providers.serper ), body: el( 'div', { class: 'ppclg-stack' }, secretField( 'serper_api_key' ), bind( 'providers.search.serper_daily_limit', { type: 'number', label: 'Daily query cap', min: 0, default: 100 } ).el, testButton( 'serper' ) ) } ),
                ),
            ) } ),
            el( 'div', { class: 'ppclg-grid ppclg-grid--2' },
                card( { title: 'AI writer (optional)', subtitle: 'Rewrites descriptions and outreach openers; every output still passes the claims filter', actions: providerBadge( providers.anthropic ), body: el( 'div', { class: 'ppclg-stack' },
                    bind( 'providers.ai.enabled', { type: 'toggle', label: 'Enabled' } ).el,
                    secretField( 'anthropic_api_key' ),
                    grid( [
                        bind( 'providers.ai.model', { type: 'text', label: 'Model' } ),
                        bind( 'providers.ai.max_tokens', { type: 'number', label: 'Max tokens', min: 100, max: 8000, default: 1200 } ),
                    ] ),
                    testButton( 'anthropic' ),
                ) } ),
                card( { title: 'Email, RDAP & inbound replies', body: el( 'div', { class: 'ppclg-stack' },
                    bind( 'providers.rdap.enabled', { type: 'toggle', label: 'RDAP domain age lookups', hint: 'Free registry lookups used for the young-domain risk flag.' } ).el,
                    el( 'div', {}, el( 'div', { class: 'ppclg-field-label' }, 'Outgoing email' ), el( 'div', { class: 'ppclg-field-hint', style: { marginBottom: '6px' } }, 'Sends a test message to your account address through wp_mail() (WP Mail SMTP).' ), testButton( 'email', 'Send test email' ) ),
                    secretField( 'inbound_webhook_secret', { hint: 'Shared secret your inbound-email provider sends with every request: in the X-PPCLG-Secret header, a “secret” body field, or the webhook URL (see below).' } ),
                    el( 'div', {}, el( 'div', { class: 'ppclg-field-label' }, 'Inbound webhook URL' ), urlRow( inboundUrl ), el( 'div', { class: 'ppclg-field-hint' }, 'POST JSON with from, subject, text, in_reply_to / message_id. Replies are matched to sent messages and logged.' ) ),
                    el( 'div', {},
                        el( 'div', { class: 'ppclg-field-label' }, 'Sending the secret' ),
                        el( 'div', { class: 'ppclg-field-hint' }, 'Preferred: the header ', el( 'code', {}, 'X-PPCLG-Secret: SECRET' ), ' (or a “secret” field in the body). If your provider can only be given a URL, use one of these forms instead, with SECRET replaced by your secret. For these URL forms, use a secret of only letters and digits, or percent-encode any other character (such as + & # % @ : / ?); otherwise the request fails. A secret in a URL can end up in server and proxy logs, so use the header when you can.' ),
                        urlRow( inboundQueryUrl ),
                        urlRow( inboundBasicUrl ),
                    ),
                ) } ),
            ),
        );
    }

    // ----- Outreach ---------------------------------------------------
    function outreachTab() {
        const days = arr( getPath( state.draft, 'outreach.send_days', [ 1, 2, 3, 4, 5 ] ) ).map( Number );
        const dayGroup = el( 'div', { class: 'ppclg-checkgroup' }, DAYS.map( ( [ n, label ] ) => {
            const cb = el( 'input', { type: 'checkbox', checked: days.includes( n ) } );
            cb.addEventListener( 'change', () => {
                const current = new Set( arr( getPath( state.draft, 'outreach.send_days', [] ) ).map( Number ) );
                if ( cb.checked ) {
                    current.add( n );
                } else {
                    current.delete( n );
                }
                setPath( state.draft, 'outreach.send_days', Array.from( current ).sort( ( a, b ) => a - b ) );
                markDirty();
            } );
            return el( 'label', { class: 'ppclg-checkbox' }, cb, el( 'span', {}, label ) );
        } ) );
        return el( 'div', { class: 'ppclg-stack' },
            callout( 'info', 'CASL / CAN-SPAM checklist', el( 'ul', {}, [
                'Every message identifies the sender (name, working email, postal address) and carries a one-click unsubscribe.',
                'Only addresses conspicuously published for business enquiries are contacted (implied consent under CASL s.10(9)(b)); opt-outs are honoured within one business day and permanently suppressed.',
                'Messages contain no dosing, therapeutic or unverified claims; the claims filter blocks them before approval.',
                'Each contact receives at most the configured number of follow-ups and is not re-contacted within the cool-down period.',
            ].map( ( t ) => el( 'li', {}, t ) ) ) ),
            card( { title: 'Sender identity', subtitle: 'Required before any email can be sent', body: grid( [
                bind( 'outreach.sender_name', { type: 'text', label: 'Sender name', required: true } ),
                bind( 'outreach.sender_email', { type: 'email', label: 'Sender email', required: true, hint: 'Ideally on the site domain for deliverability.' } ),
                bind( 'outreach.reply_to', { type: 'email', label: 'Reply-To (optional)' } ),
                bind( 'outreach.postal_address', { type: 'textarea', label: 'Postal address (printed in every message)', rows: 2, required: true } ),
                bind( 'outreach.signature', { type: 'textarea', label: 'Signature', rows: 3, full: true, hint: 'Plain text; appears above the compliance footer.' } ),
            ] ) } ),
            card( { title: 'Approval & pacing', body: grid( [
                bind( 'outreach.require_approval', { type: 'toggle', label: 'Require individual approval for every message', hint: 'Recommended. While on, automation never approves a message; only follow-ups in campaigns that opted in to auto-approval can send automatically, and only with Outreach autosend on.' } ),
                bind( 'outreach.follow_up_days', { type: 'number', label: 'Days before a follow-up', min: 1, default: 7 } ),
                bind( 'outreach.max_follow_ups', { type: 'number', label: 'Maximum follow-ups', min: 0, max: 3, default: 1 } ),
                bind( 'outreach.min_days_between_contacts', { type: 'number', label: 'Cool-down between contacts (days)', min: 1, default: 90 } ),
            ] ) } ),
            card( { title: 'Send window', subtitle: 'Site time zone', body: [
                grid( [
                    bind( 'outreach.send_window_start', { type: 'number', label: 'Start hour (0–23)', min: 0, max: 23, default: 9 } ),
                    bind( 'outreach.send_window_end', { type: 'number', label: 'End hour (1–24)', min: 1, max: 24, default: 17 } ),
                ] ),
                el( 'div', { class: 'ppclg-field', style: { marginTop: '12px' } }, el( 'span', { class: 'ppclg-field-label' }, 'Send days' ), dayGroup ),
            ] } ),
            card( { title: 'Storefront compliance gate', subtitle: 'Listings and outreach describe a research-use-only supplier', body: [
                bind( 'compliance.storefront_gate', { type: 'toggle', default: true, label: 'Pause automated submissions and outreach sending while the storefront check blocks', hint: 'The check reads product categories, tags, product titles and menus for consumer-use wording. You can acknowledge its current findings on the Overview page instead of turning the gate off; any change in the findings pauses again. Turning the gate off asks for confirmation.' } ).el,
            ] } ),
        );
    }

    // ----- Content ----------------------------------------------------
    function rowEditor( { rows, columns, newRow, addLabel = 'Add row', onChange } ) {
        const host = el( 'div' );
        const draw = () => {
            const table = dataTable( {
                dense: true,
                rows,
                empty: { title: 'No entries', compact: true },
                columns: columns.map( ( c ) => ( { key: c.key, label: c.label, width: c.width, render: ( row ) => {
                    let input;
                    if ( c.type === 'select' ) {
                        input = el( 'select', { class: 'ppclg-select ppclg-input--small', 'aria-label': c.label }, c.options.map( ( o ) => el( 'option', { value: o, selected: String( row[ c.key ] ) === o }, labelize( o ) ) ) );
                    } else if ( c.type === 'checkbox' ) {
                        input = el( 'input', { type: 'checkbox', checked: Boolean( row[ c.key ] ), 'aria-label': c.label } );
                    } else if ( c.type === 'textarea' ) {
                        input = el( 'textarea', { class: 'ppclg-textarea', rows: 2, value: row[ c.key ] || '', 'aria-label': c.label, style: { minHeight: '52px' } } );
                    } else {
                        input = el( 'input', { type: c.type === 'url' ? 'url' : 'text', class: [ 'ppclg-input ppclg-input--small', c.mono ? 'ppclg-textarea--mono' : '' ], value: row[ c.key ] || '', 'aria-label': c.label, readOnly: Boolean( c.readOnly && row[ c.key ] ) } );
                    }
                    const handler = () => {
                        row[ c.key ] = c.type === 'checkbox' ? input.checked : input.value;
                        onChange();
                    };
                    input.addEventListener( c.type === 'select' || c.type === 'checkbox' ? 'change' : 'input', handler );
                    return input;
                } } ) ).concat( [ { key: '__remove', label: 'Remove', hideLabel: true, align: 'right', render: ( row ) => button( '', { small: true, kind: 'ghost', iconName: 'trash', title: 'Remove row', onClick: () => {
                    const i = rows.indexOf( row );
                    if ( i >= 0 ) {
                        rows.splice( i, 1 );
                        onChange();
                        draw();
                    }
                } } ) } ] ),
            } );
            replace( host, table, el( 'div', { class: 'ppclg-row', style: { marginTop: '10px' } }, button( addLabel, { small: true, iconName: 'plus', onClick: () => {
                rows.push( newRow() );
                onChange();
                draw();
                const inputs = host.querySelectorAll( 'tbody tr:last-child input, tbody tr:last-child textarea' );
                if ( inputs.length ) {
                    inputs[ 0 ].focus();
                }
            } } ) ) );
        };
        draw();
        return host;
    }

    function contentTab() {
        const claims = obj( obj( state.data ).claims_rules );
        const assets = arr( getPath( state.draft, 'content.linkable_assets', [] ) ).length ? arr( getPath( state.draft, 'content.linkable_assets', [] ) ) : arr( obj( state.data ).assets );
        const assetRows = assets.map( ( a ) => ( { url: a.url || '', title: a.title || '', summary: a.summary || '', topics: arr( a.topics ).join( ', ' ), kind: a.kind || 'guide' } ) );
        const syncAssets = () => {
            setPath( state.draft, 'content.linkable_assets', assetRows.filter( ( r ) => r.url && r.title ).map( ( r ) => ( { url: r.url, title: r.title, summary: r.summary, topics: String( r.topics || '' ).split( ',' ).map( ( t ) => t.trim() ).filter( Boolean ), kind: r.kind } ) ) );
            markDirty();
        };
        return el( 'div', { class: 'ppclg-stack' },
            card( {
                title: 'Verified facts',
                subtitle: 'The only statements generated content may make about the company. Unverified facts are never used.',
                actions: button( 'Reset to defaults', { small: true, kind: 'danger', iconName: 'refresh', onClick: async () => {
                    if ( ! ( await confirm( 'Replace all facts with the built-in defaults? Custom facts are lost.', { danger: true, confirmText: 'Reset' } ) ) ) {
                        return;
                    }
                    await api.post( '/settings/facts/reset', {} );
                    toast( 'Facts reset to defaults.', 'success' );
                    await load();
                } } ),
                body: rowEditor( {
                    rows: state.factsRows,
                    columns: [
                        { key: 'key', label: 'Key', type: 'text', mono: true, width: '14%' },
                        { key: 'statement', label: 'Statement', type: 'textarea' },
                        { key: 'verified', label: 'Verified', type: 'checkbox', width: '70px' },
                        { key: 'source', label: 'Source URL', type: 'url', width: '20%' },
                        { key: 'group', label: 'Group', type: 'text', width: '10%' },
                    ],
                    newRow: () => ( { key: '', statement: '', verified: false, source: '', group: 'custom' } ),
                    addLabel: 'Add fact',
                    onChange: () => {
                        state.factsDirty = true;
                        markDirty();
                    },
                } ),
            } ),
            card( { title: 'Claims filter rules', subtitle: 'Applied to every generated description and outreach message (negated compliance statements are allowed)', body: Object.keys( claims ).length ? el( 'ul', { class: 'ppclg-list--bullets' }, Object.entries( claims ).map( ( [ rule, message ] ) => el( 'li', {}, el( 'span', { class: 'ppclg-mono' }, rule ), ' — ', String( message ) ) ) ) : emptyState( { title: 'No rules reported', compact: true } ) } ),
            card( {
                title: 'Linkable assets',
                subtitle: 'Non-commercial pages offered in resource-page and educational outreach; topics drive matching',
                body: rowEditor( {
                    rows: assetRows,
                    columns: [
                        { key: 'title', label: 'Title', type: 'text', width: '22%' },
                        { key: 'url', label: 'URL', type: 'url', width: '24%' },
                        { key: 'summary', label: 'Summary', type: 'textarea' },
                        { key: 'topics', label: 'Topics (comma-separated)', type: 'text', width: '18%' },
                        { key: 'kind', label: 'Kind', type: 'select', options: ASSET_KINDS, width: '100px' },
                    ],
                    newRow: () => ( { url: ctx.config.homeUrl || '', title: '', summary: '', topics: '', kind: 'guide' } ),
                    addLabel: 'Add asset',
                    onChange: syncAssets,
                } ),
            } ),
        );
    }

    // ----- Monitoring -------------------------------------------------
    function monitoringTab() {
        return el( 'div', { class: 'ppclg-stack' },
            card( { title: 'Check cadence', body: grid( [
                bind( 'monitoring.check_interval_days_new', { type: 'number', label: 'Re-check new links every (days)', min: 1, default: 1, hint: 'Applies for the first 14 days after a link is found.' } ),
                bind( 'monitoring.check_interval_days_stable', { type: 'number', label: 'Re-check stable links every (days)', min: 1, default: 7 } ),
                bind( 'monitoring.lost_after_failures', { type: 'number', label: 'Mark lost after consecutive failures', min: 1, max: 10, default: 2 } ),
                bind( 'monitoring.import_interval_days', { type: 'number', label: 'Provider import every (days)', min: 1, default: 7 } ),
                bind( 'monitoring.import_limit', { type: 'number', label: 'Max records per import', min: 50, max: 5000, default: 500 } ),
            ] ) } ),
            card( { title: 'Notifications', body: grid( [
                bind( 'monitoring.notify_email', { type: 'email', label: 'Notification email', hint: 'Defaults to the site admin email when empty.' } ),
                bind( 'monitoring.digest_email', { type: 'toggle', label: 'Daily digest email', hint: 'One summary of unread alerts per day.' } ),
                bind( 'monitoring.notify_critical_immediately', { type: 'toggle', label: 'Email critical alerts immediately', hint: 'Lost high-value backlinks, failed submissions and bounces (throttled to one per title per 6 hours).' } ),
            ] ) } ),
        );
    }

    // ----- Advanced ---------------------------------------------------
    function advancedTab() {
        const d = obj( state.data );
        const backups = arr( d.backups );
        return el( 'div', { class: 'ppclg-stack' },
            card( { title: 'Logging & retention', body: grid( [
                bind( 'advanced.log_level', { type: 'select', label: 'Log level', options: { debug: 'Debug (verbose)', info: 'Info', warning: 'Warning', error: 'Error' } } ),
                bind( 'advanced.log_retention_days', { type: 'number', label: 'Keep logs for (days)', min: 1, default: 30 } ),
                bind( 'advanced.audit_retention_days', { type: 'number', label: 'Keep audit entries for (days)', min: 30, default: 365 } ),
            ], 3 ) } ),
            card( { title: 'Crawler', subtitle: 'The crawler honours robots.txt and never bypasses CAPTCHAs or logins', body: grid( [
                bind( 'advanced.user_agent', { type: 'text', label: 'User agent', full: true, hint: 'Identify the bot honestly and include a contact URL.' } ),
                bind( 'advanced.request_timeout', { type: 'number', label: 'Request timeout (s)', min: 3, max: 25, default: 15 } ),
                bind( 'advanced.crawl_delay_seconds', { type: 'number', label: 'Delay between requests to one host (s)', min: 0, max: 30, default: 2 } ),
                bind( 'advanced.max_response_kb', { type: 'number', label: 'Max response size (KB)', min: 128, max: 8192, default: 1536 } ),
            ] ) } ),
            card( { title: 'Database backups', subtitle: 'Created automatically before migrations', flush: true, body: dataTable( {
                dense: true,
                plain: true,
                rows: backups,
                empty: { title: 'No backups yet', compact: true },
                columns: [
                    { key: 'file', label: 'File', render: ( b ) => el( 'span', { class: 'ppclg-mono' }, b.file ) },
                    { key: 'size', label: 'Size', align: 'right', render: ( b ) => fmtBytes( b.size ) },
                    { key: 'created', label: 'Created', render: ( b ) => fmtDate( b.created ) },
                    { key: 'download', label: 'Download', hideLabel: true, align: 'right', render: ( b ) => ( /^[\w-]+\.sql\.gz$/.test( String( b.file || '' ) ) ? linkButton( 'Download', api.fileUrl( `/settings/backups/${ encodeURIComponent( b.file ) }` ), { small: true, iconName: 'download' } ) : null ) },
                ],
            } ) } ),
            card( { title: 'System', body: kv( [
                [ 'Plugin version', ctx.config.version || '—' ],
                [ 'Database schema', d.db_version || '—' ],
                [ 'Encryption keys', d.crypto_strong ? badge( 'Strong', 'success', { dot: true } ) : badge( 'Weak / default salts', 'danger', { dot: true } ) ],
                [ 'REST root', el( 'code', {}, api.restRoot() ) ],
            ], { compact: true } ) } ),
            card( { title: 'Danger zone', body: [
                bind( 'advanced.delete_on_uninstall', { type: 'checkbox', label: 'Delete all plugin data (tables, options, secrets, backups) when the plugin is uninstalled' } ).el,
                callout( 'danger', 'Irreversible', 'With this enabled, deleting the plugin from the Plugins screen permanently removes every prospect, submission, message, backlink record and setting. Leave it off to keep data for reinstallation.' ),
            ] } ),
        );
    }
}
