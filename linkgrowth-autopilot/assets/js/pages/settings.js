/**
 * Settings page: the tabs of lga-config.settingsTabs (the modules' settingsTabs(), DESIGN.md §10.1), each rendered by
 * its own module under assets/js/settings/ (business profile, automation & limits, discovery, integrations,
 * outreach & compliance, content & facts, monitoring and advanced options in the built-in modules).
 * Changes of every tab are collected into one draft and saved in one request (changed sections only).
 *
 * A tab module exports `default function render( tab )` and returns its content; `tab` carries the page context
 * (`ctx`), the shared `state`, `bind( path, spec )` (a formField bound to the draft), `grid()`, `markDirty()`,
 * `load()`, `rowEditor()`, `providerBadge()` and `sendZoneText()`.
 */

import * as api from '../api.js';
import {
    el, replace, badge, button, loading, errorState, labelize, pageHeader, tabs, dataTable,
    formField, confirm, toast, card, isObject, deepClone, deepEqual, getPath, setPath, linkButton, icon,
    controlId, checkbox, input, select, textarea,
} from '../ui.js';

const obj = ( v ) => ( isObject( v ) ? v : {} );
const arr = ( v ) => ( Array.isArray( v ) ? v : [] );

/**
 * Automation flags owned by POST /automation/toggle. The form applies them there (with the same confirmation
 * as the Automation page for the gated ones) instead of inside POST /settings, so a save can neither skip that
 * confirmation nor carry a flag the owner did not touch in this tab.
 */
const TOGGLE_FLAGS = [ 'enabled', 'submissions', 'outreach_autosend' ];

/** "Evaluated in Toronto time (America/Toronto, from your business region …)" for the send-window card. */
export function sendZoneText( info ) {
    const z = info && typeof info === 'object' ? info : {};
    if ( ! z.timezone ) {
        return 'Site time zone';
    }
    const why = { setting: 'set here', region: `from your business region, because WordPress is set to ${ z.wordpress || 'UTC' }`, wordpress: 'the WordPress time zone' }[ z.source ] || '';
    return `Evaluated in ${ z.label || z.timezone } (${ z.timezone }${ why ? `, ${ why }` : '' })`;
}
const CONFIRM_FLAGS = {
    submissions: { title: 'Enable automated submissions', text: 'Enable automated submissions? In auto mode the engine submits by itself to high-scoring sites whenever every safety gate passes (public listing form, no CAPTCHA or login, robots.txt allows it). Every submission is logged in the audit trail.' },
    outreach_autosend: { title: 'Enable outreach autosend', text: 'Enable outreach autosend? Follow-ups in campaigns set to “Auto-approve follow-ups” will be sent to third parties in your name without your review. Initial cold emails that the engine drafts for qualified prospects (score at or above the auto-queue threshold, with a known contact) are also approved and sent without your review if “Require individual approval” (Settings → Outreach) is off; while it is on, they wait for your approval. Sending stays inside the send window and the outreach email limits, and every message includes the unsubscribe link.' },
};
/** Turning the storefront compliance gate off: POST /settings needs confirm=true for it. */
const STOREFRONT_GATE_OFF = { title: 'Turn off the storefront compliance gate', text: 'Turn off the storefront compliance gate? Automated submissions and outreach sending will continue even while the storefront check finds consumer-use wording (weight loss, skincare, nasal sprays, capsules, syringes, dosing…) that contradicts the research-use-only listings. How the store presents itself is your legal decision; the check keeps running and its findings stay visible on the Overview.' };
/** Turning “Require individual approval” off while autosend is on: POST /settings needs confirm=true for it. */
// Mirrors SettingsController::autoLinksConfirmationText(): automatic links edit published pages without review.
const AUTO_LINKS_GATE = { title: 'Turn on automatic internal links', text: 'Turn on automatic internal links? Every day the engine will edit your published pages and posts without asking you first, adding the best internal-link suggestions (highest relevance first, up to the daily number under Settings → Automation → On-site SEO). WordPress keeps a revision of every page it changes, and “Undo automatic links” on the On-site SEO page removes them. To add a link to a page holding markup only an administrator may save (such as inline SVG diagrams or structured data), it saves that one change as your account, and it takes its own automatic links back out of pages whose template doesn’t display them.' };

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

/**
 * Label, tone and tooltip of an integration's health badge (exported for tests). An integration that is not set
 * up says "Not configured" (the server's note on what is missing becomes the tooltip); "Error" is kept for a
 * configured integration that reported a failure.
 *
 * @param {object|null} p Provider status from GET /settings `providers`.
 * @return {{label:string, kind:string, title:string}}
 */
export function providerBadgeState( p ) {
    if ( ! p || typeof p !== 'object' ) {
        return { label: 'Unknown', kind: 'neutral', title: '' };
    }
    const note = p.error ? String( p.error ) : '';
    if ( p.configured === false ) {
        return { label: 'Not configured', kind: 'warning', title: note };
    }
    if ( note !== '' ) {
        return { label: 'Error', kind: 'danger', title: note };
    }
    if ( p.configured && p.enabled !== false ) {
        return { label: 'Ready', kind: 'success', title: '' };
    }
    if ( p.configured ) {
        return { label: 'Disabled', kind: 'neutral', title: '' };
    }
    return { label: 'Not configured', kind: 'warning', title: '' };
}

function providerBadge( p ) {
    if ( ! p ) {
        return null;
    }
    const st = providerBadgeState( p );
    return badge( st.label, st.kind, { dot: true, title: st.title } );
}

/** Text of the "hand owner tasks to a staff member" card (kept in one place for the docs and tests). */
export const SUBMITTER_CARD_TEXT = 'Hand owner tasks to a staff member or assistant: create a WordPress user with the LinkGrowth Submitter role. They see only the owner-task queue and can mark listings submitted; they cannot change settings, send email or see your data.';

/**
 * Card that tells the owner how to hand the owner tasks (listings that need a real person) to someone else.
 * Links to Users → Add New (config.newUserUrl, an http(s) admin URL); nothing is created or sent from here.
 * Returns null when the URL is missing (e.g. for a submitter-only user, who never sees Settings anyway).
 */
export function submitterRoleCard( config ) {
    const url = typeof obj( config ).newUserUrl === 'string' ? config.newUserUrl.trim() : '';
    if ( ! /^https?:\/\/[^\s/]+/i.test( url ) ) {
        return null;
    }
    return el( 'div', { class: 'lga-submitter-role-card', style: { marginBottom: '14px' } }, card( {
        title: 'Hand owner tasks to a staff member',
        subtitle: 'Listings that need a real person: an account on the site, a phone call or a postcard',
        body: el( 'p', {}, SUBMITTER_CARD_TEXT ),
        footer: [ linkButton( 'Add a user', url, { iconName: 'plus', control: 'add-submitter-user' } ) ],
    } ) );
}

export default async function render( root, ctx ) {
    const params = ctx.params || {};
    // The tabs this user sees, in tab order (lga-config.settingsTabs); a tab id the hash names that is not one of
    // them opens the first tab.
    const tabDefs = Object.values( ctx.config && isObject( ctx.config.settingsTabs ) ? ctx.config.settingsTabs : {} );
    const tabModules = loadTabModules( tabDefs, ctx );
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
        activeTab: tabDefs.some( ( t ) => t.key === params.tab ) ? params.tab : ( tabDefs[ 0 ] ? tabDefs[ 0 ].key : '' ),
    };

    const content = el( 'div' );
    const dirtyLabel = el( 'span', { class: 'lga-muted lga-small' }, 'No unsaved changes' );
    const saveBtn = button( 'Save changes', { kind: 'primary', iconName: 'check', control: 'save', onClick: () => save() } );
    const discardBtn = button( 'Discard', { kind: 'ghost', control: 'discard', onClick: async () => {
        if ( ! isDirty() || ( await confirm( 'Discard all unsaved changes?', { danger: true, confirmText: 'Discard', control: 'discard-confirm' } ) ) ) {
            await load();
        }
    } } );
    const saveBar = el( 'div', { class: 'lga-savebar' }, el( 'div', { class: 'lga-row' }, icon( 'settings', 16 ), dirtyLabel ), el( 'div', { class: 'lga-row' }, discardBtn, saveBtn ) );

    replace( root,
        pageHeader( { title: 'Settings', description: 'Configure the business profile, automation limits, discovery inputs, integrations, outreach identity and monitoring. Secrets are encrypted at rest and never shown again in full.' } ),
        submitterRoleCard( ctx.config ),
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
        saveBar.classList.toggle( 'lga-savebar--dirty', dirty );
        dirtyLabel.textContent = dirty ? 'You have unsaved changes' : 'No unsaved changes';
        saveBtn.disabled = ! dirty;
    }

    /** A formField bound to the draft at `path` (its control id is `field-<path>`). */
    function bind( path, spec ) {
        const current = getPath( state.draft, path, spec.default !== undefined ? spec.default : ( spec.type === 'list' ? [] : ( spec.type === 'toggle' || spec.type === 'checkbox' ? false : '' ) ) );
        return formField( {
            control: controlId( 'field', path ),
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
            replace( content, errorState( { title: 'Settings could not be loaded', message: e && e.message ? e.message : '', retry: load, control: 'load-retry' } ) );
            return;
        }
        // The tab modules load while GET /settings runs (a tab whose module failed shows the error in its panel).
        const loaded = await tabModules;
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
        drawTabs( loaded );
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
        if ( removed.length && ! ( await confirm( `Remove these rate limits? ${ removed.join( ', ' ) } will have no cap (0 = unlimited).`, { title: 'Remove rate limits', confirmText: 'Remove limits', danger: true, control: 'remove-limits-confirm' } ) ) ) {
            toast( 'Nothing was saved.', 'info' );
            return;
        }
        for ( const [ flag, value ] of flagChanges ) {
            const gate = CONFIRM_FLAGS[ flag ];
            if ( value && gate && ! ( await confirm( gate.text, { title: gate.title, confirmText: 'Enable', danger: true, control: controlId( 'enable', flag, 'confirm' ) } ) ) ) {
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
            } else if ( await confirm( APPROVAL_OFF_GATE.text, { title: APPROVAL_OFF_GATE.title, confirmText: 'Turn off', danger: true, control: 'approval-off-confirm' } ) ) {
                approvalOffConfirmed = true;
            } else {
                toast( 'Nothing was saved.', 'info' );
                return;
            }
        }
        let gateOffConfirmed = false;
        if ( obj( changed.compliance ).storefront_gate === false && getPath( state.original, 'compliance.storefront_gate', true ) ) {
            if ( await confirm( STOREFRONT_GATE_OFF.text, { title: STOREFRONT_GATE_OFF.title, confirmText: 'Turn off', danger: true, control: 'storefront-gate-off-confirm' } ) ) {
                gateOffConfirmed = true;
            } else {
                toast( 'Nothing was saved.', 'info' );
                return;
            }
        }
        let autoLinksConfirmed = false;
        // Asked also while Autopilot is on: once Autopilot is off, automatic links act on this confirmation alone.
        if ( obj( changed.seo ).auto_apply_links === true && ! getPath( state.original, 'seo.auto_apply_links', false ) ) {
            if ( await confirm( AUTO_LINKS_GATE.text, { title: AUTO_LINKS_GATE.title, confirmText: 'Turn on', danger: true, control: 'auto-links-confirm' } ) ) {
                autoLinksConfirmed = true;
            } else {
                toast( 'Nothing was saved.', 'info' );
                return;
            }
        }
        if ( Object.keys( changed ).length ) {
            body.settings = changed;
            if ( approvalOffConfirmed || gateOffConfirmed || autoLinksConfirmed ) {
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
    /** What a tab module's render( tab ) receives. */
    function tabContext() {
        return { ctx, state, bind, grid, markDirty, load, rowEditor, providerBadge, sendZoneText };
    }

    function drawTabs( loaded ) {
        const view = tabs( {
            active: state.activeTab,
            rerender: true,
            control: 'tab',
            onChange: ( id ) => {
                state.activeTab = id;
                ctx.setHash( { tab: id } );
            },
            items: tabDefs.map( ( def ) => ( { id: def.key, label: def.label, render: ( p ) => {
                const mod = loaded[ def.key ];
                if ( mod && typeof mod.default === 'function' ) {
                    p.appendChild( mod.default( tabContext() ) );
                    return;
                }
                // The browser keeps a module that failed to load as failed for this page view, so only a reload
                // fetches it again; the other tabs keep working.
                p.appendChild( errorState( {
                    title: 'This tab could not be loaded',
                    message: `${ mod && mod.error ? mod.error : `The tab "${ def.label }" has no renderer.` } Reload the page (hard refresh: Ctrl+Shift+R / Cmd+Shift+R) if this keeps happening after a plugin update.`,
                    retry: () => window.location.reload(),
                    control: controlId( 'tab', def.key, 'reload' ),
                } ) );
            } } ) ),
        } );
        replace( content, view.el );
    }

    function grid( fields, cols = 2 ) {
        return el( 'div', { class: [ 'lga-form-grid', cols === 3 ? 'lga-form-grid--3' : '' ] }, fields.map( ( f ) => ( f && f.el ? f.el : f ) ) );
    }

    // ----- Row editor (Discovery seeds, Content facts and assets) -----------
    /** Editable rows: each cell input is `<control>-<column key>`, the row's remove button `<control>-remove`, the add button `<control>-add`. */
    function rowEditor( { rows, columns, newRow, addLabel = 'Add row', onChange, control = 'rows' } ) {
        const host = el( 'div' );
        const draw = () => {
            const table = dataTable( {
                dense: true,
                control,
                rows,
                empty: { title: 'No entries', compact: true },
                columns: columns.map( ( c ) => ( { key: c.key, label: c.label, width: c.width, render: ( row ) => {
                    let field;
                    const ctl = controlId( control, c.key );
                    if ( c.type === 'select' ) {
                        field = select( { class: 'lga-select lga-input--small', 'aria-label': c.label, options: c.options, value: String( row[ c.key ] ), control: ctl } );
                    } else if ( c.type === 'checkbox' ) {
                        field = checkbox( { checked: Boolean( row[ c.key ] ), 'aria-label': c.label, control: ctl } );
                    } else if ( c.type === 'textarea' ) {
                        field = textarea( { class: 'lga-textarea', rows: 2, value: row[ c.key ] || '', 'aria-label': c.label, style: { minHeight: '52px' }, control: ctl } );
                    } else {
                        field = input( { type: c.type === 'url' ? 'url' : 'text', class: [ 'lga-input lga-input--small', c.mono ? 'lga-textarea--mono' : '' ], value: row[ c.key ] || '', 'aria-label': c.label, readOnly: Boolean( c.readOnly && row[ c.key ] ), control: ctl } );
                    }
                    const handler = () => {
                        row[ c.key ] = c.type === 'checkbox' ? field.checked : field.value;
                        onChange();
                    };
                    field.addEventListener( c.type === 'select' || c.type === 'checkbox' ? 'change' : 'input', handler );
                    return field;
                } } ) ).concat( [ { key: '__remove', label: 'Remove', hideLabel: true, align: 'right', render: ( row ) => button( '', { small: true, kind: 'ghost', iconName: 'trash', title: 'Remove row', control: controlId( control, 'remove' ), onClick: () => {
                    const i = rows.indexOf( row );
                    if ( i >= 0 ) {
                        rows.splice( i, 1 );
                        onChange();
                        draw();
                    }
                } } ) } ] ),
            } );
            replace( host, table, el( 'div', { class: 'lga-row', style: { marginTop: '10px' } }, button( addLabel, { small: true, iconName: 'plus', control: controlId( control, 'add' ), onClick: () => {
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
}

/**
 * Loads the module of every tab (in parallel) through the router's loader: resolves to tab key => module, or
 * { error } for a tab whose module could not be loaded.
 */
function loadTabModules( defs, ctx ) {
    const load = ctx && typeof ctx.loadModule === 'function' ? ctx.loadModule : () => Promise.reject( new Error( 'No module loader.' ) );
    return Promise.all( defs.map( ( def ) => Promise.resolve().then( () => load( def.module ) ).then(
        ( mod ) => [ def.key, mod ],
        ( e ) => [ def.key, { error: `The script of the "${ def.label }" tab failed to load. ${ e && e.message ? e.message : '' }`.trim() } ],
    ) ) ).then( ( pairs ) => Object.fromEntries( pairs ) );
}
