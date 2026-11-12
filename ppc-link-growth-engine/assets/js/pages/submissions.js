/**
 * Submissions page: status tabs, table and the package review drawer
 * (business fields, editable generated content with claims check, form mapping,
 * instructions, response excerpt and approve / reject / retry / rebuild / mark-submitted actions).
 *
 * Owner tasks from the verified opportunity database (needs_manual rows whose payload.package.source is
 * "verified_db") get their own view: tier / authority badges, outreach and conditional labels, the
 * "Open submission page" and "Copy listing kit" buttons, the steps, the storefront gate note and a
 * "Mark submitted" form that uses the existing mark-submitted action (the listing is then verified by the
 * verify_listings stage). Nothing on this page submits a listing or contacts a site by itself.
 */

import * as api from '../api.js';
import {
    el, replace, badge, statusBadge, button, callout, kv, pre, loading, errorState, emptyState, timeEl, fmtNumber, fmtDate, labelize, truncate,
    pageHeader, drawer, tabs, dataTable, formField, formModal, confirm, prompt, toast, extLink, listView, normalizeList, card, checklist, copyButton, isObject, icon, linkButton,
    details,
} from '../ui.js';
import { reportAction } from './outcome.js';

const TABS = [
    { id: 'all', label: 'All', status: '' },
    { id: 'pending_review', label: 'Pending review', status: 'pending_review' },
    { id: 'needs_manual', label: 'Needs manual', status: 'needs_manual' },
    { id: 'owner_tasks', label: 'Owner tasks', status: 'needs_manual', source: 'verified_db' },
    { id: 'approved', label: 'Approved / Queued', status: 'approved,queued' },
    { id: 'submitted', label: 'Submitted', status: 'submitted' },
    { id: 'confirmed', label: 'Confirmed', status: 'confirmed' },
    { id: 'failed', label: 'Failed', status: 'failed' },
    { id: 'rejected', label: 'Rejected', status: 'rejected' },
];
const METHODS = { manual: 'Manual', form: 'Form', api: 'API' };
const CONTENT_FIELDS = [
    { key: 'tagline', label: 'Tagline', rows: 2 },
    { key: 'short_description', label: 'Short description (≤ 160 chars)', rows: 3 },
    { key: 'description', label: 'Description', rows: 6 },
    { key: 'company_background', label: 'Company background', rows: 5 },
];

const obj = ( v ) => ( isObject( v ) ? v : {} );
const arr = ( v ) => ( Array.isArray( v ) ? v : [] );

/** Source marker of owner tasks created from the verified opportunity database. */
export const VERIFIED_SOURCE = 'verified_db';

const TIERS = { 1: 'Tier 1', 2: 'Tier 2', 3: 'Tier 3', 4: 'Tier 4' };
const KINDS = { listing: 'Listing / profile', outreach: 'Outreach (pitch)' };
const CONDITIONAL = { yes: 'Conditional only', no: 'Without conditions' };

/** Statuses in which an owner task can still be marked as submitted from its card. */
const OPEN_TASK_STATUSES = [ 'needs_manual', 'pending_review', 'failed' ];

/** Why the verified import skipped items (VerifiedOpportunities skip reasons and DomainFilter reasons). */
const SKIP_REASONS = {
    excluded_platform: 'the domain is on the platform exclusion list (data/excluded-domains.json and Settings → Discovery)',
    suppressed: 'the domain is on the suppression list',
    own_site: 'it is this site',
    invalid_url: 'it has no usable web address',
    invalid_item: 'the entry is incomplete',
    non_html: 'the address is not a web page',
    no_domain: 'the address has no domain',
    no_tld: 'the address has no domain',
    prospect_rejected: 'the site was rejected earlier',
    prospect_suppressed: 'the site is suppressed',
    prospect_missing: 'the site record could not be created',
    error: 'an error occurred (see Logs)',
};

/** The URL when it is an absolute http(s) URL, otherwise '' (never javascript:, mailto: or relative links). */
export function safeHttpUrl( value ) {
    const url = typeof value === 'string' ? value.trim() : '';
    return /^https?:\/\/[^\s/]+/i.test( url ) ? url : '';
}

/** payload.package when the payload belongs to a verified-database owner task, otherwise null. */
export function ownerTaskPackage( payload ) {
    const pkg = obj( obj( payload ).package );
    return pkg.source === VERIFIED_SOURCE ? pkg : null;
}

/** Steps text split into lines (each rendered as a text node; never parsed as markup). */
export function stepLines( text ) {
    return String( text === null || text === undefined ? '' : text ).split( /\r?\n/ ).map( ( l ) => l.trim() ).filter( Boolean );
}

/**
 * Plain-text listing kit for the clipboard: name, website, address, email, description and the category rule
 * (labelled as a rule: it tells the owner which category to pick, it is not text to paste into a listing).
 */
export function listingKitText( pkg ) {
    const p = obj( pkg );
    return [
        [ 'Business name', p.business_name ],
        [ 'Website', p.website ],
        [ 'Address', p.address ],
        [ 'Email', p.email ],
        [ 'Description', p.description ],
        [ 'Category rule', p.category_rule ],
    ].filter( ( [ , v ] ) => typeof v === 'string' && v.trim() !== '' ).map( ( [ k, v ] ) => `${ k }: ${ v.trim() }` ).join( '\n' );
}

/** Short badges for an owner task (list rows and the drawer). */
export function ownerTaskBadges( pkg ) {
    const p = obj( pkg );
    const tier = Number( p.tier );
    const authority = p.authority === null || p.authority === undefined || p.authority === '' ? null : Number( p.authority );
    return [
        Number.isFinite( tier ) && tier > 0 ? badge( `Tier ${ tier }`, tier === 1 ? 'success' : ( tier === 2 ? 'info' : 'neutral' ), { title: 'Priority tier from the verified database (1 = highest)' } ) : null,
        badge( Number.isFinite( authority ) && authority !== null ? `Authority ${ authority }` : 'Authority unknown', 'neutral', { outline: true, title: 'Domain authority recorded in the verified database' } ),
        p.outreach ? badge( 'Outreach', 'warning', { title: 'A pitch to a person: you decide whether to write. Nothing is sent by the plugin.' } ) : null,
        p.conditional ? badge( 'Conditional', 'warning', { outline: true, title: 'Proceed only when the conditions in the steps are met' } ) : null,
    ].filter( Boolean );
}

/** Steps as escaped text with line breaks (text nodes and <br>, no markup from data). */
export function stepsBlock( text ) {
    const lines = stepLines( text );
    if ( ! lines.length ) {
        return el( 'p', { class: 'ppclg-muted' }, 'No steps were recorded for this item.' );
    }
    const out = [];
    lines.forEach( ( line, i ) => {
        if ( i > 0 ) {
            out.push( el( 'br' ) );
        }
        out.push( line );
    } );
    return el( 'p', { class: 'ppclg-owner-steps', style: { lineHeight: '1.6' } }, out );
}

async function copyText( text, label ) {
    if ( ! text ) {
        toast( `There is nothing to copy for the ${ label }.`, 'warning' );
        return false;
    }
    try {
        await navigator.clipboard.writeText( text );
        toast( `The ${ label } was copied to the clipboard.`, 'success', { timeout: 2500 } );
        return true;
    } catch ( e ) {
        toast( 'Could not copy automatically; select the text in the listing kit and copy it manually.', 'warning' );
        return false;
    }
}

/**
 * Owner-task card for a verified-database submission.
 *
 * @param {object}   s        Submission row (detail endpoint).
 * @param {object}   pkg      payload.package.
 * @param {Function} onMarked Called after the server accepted "Mark submitted".
 */
export function ownerTaskCard( s, pkg, { onMarked = null } = {} ) {
    const p = obj( pkg );
    const target = safeHttpUrl( s.target_url ) || safeHttpUrl( p.target_url );
    const registration = safeHttpUrl( p.registration_url );
    const contactEmail = typeof p.contact_email === 'string' ? p.contact_email.trim() : '';
    const stack = el( 'div', { class: 'ppclg-stack' } );

    stack.appendChild( el( 'div', { class: 'ppclg-row', style: { flexWrap: 'wrap', gap: '6px' } }, ownerTaskBadges( p ), p.link_attribute ? badge( `Link: ${ truncate( String( p.link_attribute ), 60 ) }`, 'neutral', { outline: true, title: String( p.link_attribute ) } ) : null ) );

    if ( p.outreach ) {
        stack.appendChild( callout( 'info', 'Outreach opportunity: your decision', [
            el( 'div', {}, 'This is a pitch to a person, not a form. The plugin does not write to this site and creates no outreach message or contact for it. If you decide to pitch, write the message yourself, once, as a real named person.' ),
            contactEmail ? el( 'div', { style: { marginTop: '6px' } }, 'Contact address the site lists: ', el( 'span', { class: 'ppclg-mono' }, contactEmail ) ) : null,
        ] ) );
    }
    if ( p.conditional ) {
        stack.appendChild( callout( 'warning', 'Conditional', 'Proceed only when the conditions in the steps below are met. If they are not, reject this task instead of submitting.' ) );
    }
    if ( p.gate_note ) {
        stack.appendChild( callout( 'warning', 'Storefront consistency', String( p.gate_note ) ) );
    }

    stack.appendChild( el( 'div', {},
        el( 'div', { class: 'ppclg-callout-title' }, 'Steps' ),
        stepsBlock( p.steps ),
    ) );
    if ( p.requirements && String( p.requirements ).trim() !== '' && String( p.requirements ).trim() !== stepLines( p.steps ).join( ' ' ) ) {
        stack.appendChild( details( 'Requirements recorded during verification', el( 'p', {}, String( p.requirements ) ) ) );
    }

    const kitText = listingKitText( p );
    stack.appendChild( card( {
        flat: true,
        title: 'Listing kit',
        subtitle: 'Use these exact details; the description stays research-use-only',
        body: kv( [
            [ 'Business name', p.business_name || null ],
            [ 'Website', safeHttpUrl( p.website ) ? extLink( p.website ) : ( p.website || null ) ],
            [ 'Address', p.address || null ],
            [ 'Email', p.email || null ],
            [ 'Description', p.description ? el( 'span', {}, String( p.description ) ) : el( 'span', { class: 'ppclg-muted' }, 'No description passed the research-use-only check; write one by hand.' ) ],
            [ 'Category rule', p.category_rule || null ],
        ], { compact: true } ),
        footer: [ p.positioning_rule, p.budget_rule, p.identity_rule ].filter( Boolean ).length
            ? el( 'ul', { class: 'ppclg-small', style: { margin: 0, paddingLeft: '18px' } }, [ p.positioning_rule, p.budget_rule, p.identity_rule ].filter( Boolean ).map( ( r ) => el( 'li', {}, String( r ) ) ) )
            : null,
    } ) );

    stack.appendChild( details( 'Verification and terms', kv( [
        [ 'Platform', p.platform || null ],
        [ 'Submission page', target ? extLink( target ) : el( 'span', { class: 'ppclg-muted' }, 'No web page recorded' ) ],
        [ 'Registration page', registration ? extLink( registration ) : null ],
        [ 'Free', p.free || null ],
        [ 'Payment', p.payment || null ],
        [ 'Approval', p.approval_required || null ],
        [ 'Effort', p.effort ? ( /^\d+$/.test( String( p.effort ) ) ? `${ p.effort } minutes` : String( p.effort ) ) : null ],
        [ 'Risk', p.risk || null ],
        [ 'Verification', p.verification_note || null ],
        [ 'Data version', p.data_version || null ],
    ], { compact: true } ) ) );

    const buttons = [];
    if ( target ) {
        buttons.push( el( 'a', { href: target, target: '_blank', rel: 'noopener noreferrer', class: 'ppclg-btn ppclg-btn--primary' }, icon( 'external', 14 ), 'Open submission page' ) );
    }
    buttons.push( button( 'Copy listing kit', { iconName: 'copy', onClick: () => copyText( kitText, 'listing kit' ) } ) );
    stack.appendChild( el( 'div', { class: 'ppclg-row', style: { flexWrap: 'wrap', gap: '8px' } }, buttons ) );

    if ( OPEN_TASK_STATUSES.includes( String( s.status ) ) ) {
        const urlInput = el( 'input', { type: 'url', class: 'ppclg-input', placeholder: 'https://directory.example/listing/your-business', 'aria-label': 'Listing URL' } );
        const noteInput = el( 'textarea', { class: 'ppclg-textarea', rows: 2, 'aria-label': 'Note (optional)' } );
        const markBtn = button( 'Mark submitted', { kind: 'success', iconName: 'send', onClick: async () => {
            const listing = urlInput.value.trim();
            if ( listing !== '' && ! safeHttpUrl( listing ) ) {
                toast( 'The listing URL must start with http:// or https://.', 'error' );
                return;
            }
            let res;
            try {
                res = await api.post( `/submissions/${ s.id }/mark-submitted`, { listing_url: listing, note: noteInput.value.trim() } );
            } catch ( e ) {
                return; // api.js already showed the error.
            }
            const accepted = reportAction( res, 'Marked as submitted. The listing will be checked by the listing verification stage.' );
            if ( accepted && typeof onMarked === 'function' ) {
                await onMarked( res );
            }
        } } );
        stack.appendChild( card( {
            flat: true,
            title: 'Done? Mark it submitted',
            subtitle: 'Add the public listing URL if you have it; listing verification then looks for the link to your site.',
            body: el( 'div', { class: 'ppclg-stack ppclg-stack--tight' },
                el( 'label', { class: 'ppclg-field-label' }, 'Listing URL (optional)', urlInput ),
                el( 'label', { class: 'ppclg-field-label' }, 'Note (optional)', noteInput ),
            ),
            footer: [ markBtn ],
        } ) );
    }

    return card( {
        title: p.platform ? `Owner task: ${ p.platform }` : 'Owner task',
        subtitle: 'From the verified opportunity database. You do this step by hand; nothing is submitted automatically.',
        body: stack,
    } );
}

/** Summary card for the verified opportunity database import (GET /submissions/verified). */
function verifiedSummaryCard( status, { onImport, onShowTasks } ) {
    const st = obj( status );
    const reasons = Object.entries( obj( st.skip_reasons ) ).filter( ( [ , n ] ) => Number( n ) > 0 );
    const skippedList = arr( st.skipped_items );
    const body = [
        el( 'p', {}, String( st.summary || '' ) ),
        kv( [
            [ 'Owner tasks open', fmtNumber( st.owner_tasks_open ) ],
            [ 'Items with an owner task', `${ fmtNumber( st.imported ) } of ${ fmtNumber( st.total ) }` ],
            [ 'Skipped', reasons.length ? reasons.map( ( [ r, n ] ) => `${ fmtNumber( n ) }: ${ SKIP_REASONS[ r ] || labelize( r ) }` ).join( '; ' ) : fmtNumber( st.skipped || 0 ) ],
            [ 'Data version', st.data_version || null ],
            [ 'Imported version', st.imported_version || el( 'span', { class: 'ppclg-muted' }, 'not imported yet' ) ],
        ], { compact: true } ),
        skippedList.length ? details( `Skipped items (${ skippedList.length })`, el( 'ul', { class: 'ppclg-small' }, skippedList.map( ( i ) => el( 'li', {}, `${ i.platform || i.host || i.id } (tier ${ i.tier || '?' }): ${ SKIP_REASONS[ i.reason ] || labelize( i.reason || '' ) }` ) ) ) ) : null,
    ];
    return card( {
        title: 'Verified opportunity database',
        subtitle: 'Researched listing and outreach opportunities, each turned into one owner task',
        actions: [
            button( 'Show owner tasks', { small: true, iconName: 'list', onClick: onShowTasks } ),
            button( st.up_to_date ? 'Check for changes' : 'Import now', { small: true, kind: st.up_to_date ? 'secondary' : 'primary', iconName: 'download', onClick: onImport } ),
        ],
        body,
    } );
}

async function fetchSubmissions( params ) {
    const res = await api.get( '/submissions', params );
    const list = normalizeList( res );
    const status = String( params.status || '' );
    if ( list.total === 0 && status.includes( ',' ) ) {
        // Fallback for an API that filters on a single status only: merge the parts client-side.
        const parts = status.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean );
        const results = await Promise.all( parts.map( ( s ) => api.get( '/submissions', { ...params, status: s, page: 1 }, { silent: true } ).catch( () => ( { items: [], total: 0 } ) ) ) );
        const lists = results.map( normalizeList );
        const items = lists.flatMap( ( l ) => l.items ).sort( ( a, b ) => Number( b.id ) - Number( a.id ) ).slice( 0, Number( params.per_page ) || 25 );
        const total = lists.reduce( ( n, l ) => n + l.total, 0 );
        return { items, total, page: 1, per_page: Number( params.per_page ) || 25, pages: 1 };
    }
    return res;
}

export default async function render( root, ctx ) {
    const params = ctx.params || {};
    const initialTab = TABS.some( ( t ) => t.id === params.tab ) ? params.tab : 'all';
    const initialTabDef = TABS.find( ( t ) => t.id === initialTab );
    const bannerHost = el( 'div', { class: 'ppclg-compliance-host' } );
    const verifiedHost = el( 'div' );

    const list = listView( {
        fetch: fetchSubmissions,
        defaultSort: { key: 'id', dir: 'desc' },
        initial: { filters: {
            status: initialTabDef.status,
            method: params.method || '',
            q: params.q || '',
            source: initialTabDef.source || ( params.source === VERIFIED_SOURCE ? VERIFIED_SOURCE : '' ),
            tier: TIERS[ params.tier ] ? String( params.tier ) : '',
            kind: KINDS[ params.kind ] ? params.kind : '',
            conditional: CONDITIONAL[ params.conditional ] ? params.conditional : '',
        } },
        onRowClick: ( row ) => openSubmission( Number( row.id ) ),
        rowKey: ( row ) => row.id,
        empty: { title: 'No submissions in this state', message: 'Packages are created from approved prospects (Prospects → Actions → Create package) or automatically by the packaging stage.', iconName: 'package' },
        filters: [
            { name: 'method', label: 'Method', type: 'select', options: METHODS, allLabel: 'All methods' },
            { name: 'source', label: 'Source', type: 'select', options: { [ VERIFIED_SOURCE ]: 'Verified database' }, allLabel: 'All sources' },
            { name: 'tier', label: 'Tier', type: 'select', options: TIERS, allLabel: 'All tiers' },
            { name: 'kind', label: 'Kind', type: 'select', options: KINDS, allLabel: 'Listings and outreach' },
            { name: 'conditional', label: 'Conditions', type: 'select', options: CONDITIONAL, allLabel: 'Any' },
            { name: 'q', label: 'Search', type: 'search', placeholder: 'Target URL…' },
        ],
        columns: [
            { key: 'id', label: '#', sortable: true, render: ( r ) => `#${ r.id }` },
            { key: 'prospect', label: 'Prospect', render: ( r ) => el( 'span', {}, el( 'span', { class: 'ppclg-cell-title' }, r.prospect_name || r.prospect_domain || `Prospect #${ r.prospect_id }` ), el( 'span', { class: 'ppclg-cell-sub' }, r.prospect_domain || '' ) ) },
            { key: 'target_url', label: 'Target', render: ( r ) => extLink( r.target_url, truncate( String( r.target_url || '' ).replace( /^https?:\/\//, '' ), 48 ) ) },
            { key: 'owner_task', label: 'Owner task', render: ( r ) => {
                const pkg = ownerTaskPackage( r.payload );
                return pkg ? el( 'span', { class: 'ppclg-row', style: { flexWrap: 'wrap', gap: '4px' } }, ownerTaskBadges( pkg ) ) : null;
            } },
            { key: 'method', label: 'Method', sortable: true, render: ( r ) => statusBadge( r.method, { dot: false } ) },
            { key: 'status', label: 'Status', sortable: true, render: ( r ) => statusBadge( r.status ) },
            { key: 'category', label: 'Category', render: ( r ) => ( r.category ? truncate( r.category, 30 ) : null ) },
            { key: 'attempts', label: 'Attempts', align: 'right', render: ( r ) => fmtNumber( r.attempts ) },
            { key: 'scheduled_at', label: 'Scheduled', sortable: true, render: ( r ) => ( r.scheduled_at ? timeEl( r.scheduled_at ) : null ) },
            { key: 'submitted_at', label: 'Submitted', sortable: true, render: ( r ) => ( r.submitted_at ? timeEl( r.submitted_at ) : null ) },
            { key: 'last_error', label: 'Last error', render: ( r ) => ( r.last_error ? el( 'span', { class: 'ppclg-small', title: r.last_error, style: { color: 'var(--ppclg-critical)' } }, truncate( r.last_error, 40 ) ) : null ) },
            { key: 'updated_at', label: 'Updated', sortable: true, render: ( r ) => timeEl( r.updated_at ) },
        ],
        onLoaded: ( { total, state } ) => {
            const f = state.filters;
            const ownerTab = f.status === 'needs_manual' && f.source === VERIFIED_SOURCE;
            const tab = ownerTab ? TABS.find( ( t ) => t.id === 'owner_tasks' ) : TABS.find( ( t ) => ! t.source && t.status === ( f.status || '' ) );
            if ( tab && view.active !== tab.id ) {
                view.select( tab.id, { silent: true } ); // keep the highlighted tab in step with the filters
            }
            if ( tab && ( ownerTab || ( ! f.source && ! f.tier && ! f.kind && ! f.conditional ) ) ) {
                view.setCount( tab.id, total );
            }
            ctx.setHash( { tab: tab ? tab.id : 'all', method: f.method, q: f.q, source: ownerTab ? '' : f.source, tier: f.tier, kind: f.kind, conditional: f.conditional } );
        },
    } );

    const view = tabs( {
        pills: true,
        active: initialTab,
        items: TABS.map( ( t ) => ( { id: t.id, label: t.label } ) ),
        onChange: ( id ) => {
            const tab = TABS.find( ( t ) => t.id === id );
            const next = { status: tab ? tab.status : '' };
            if ( tab && tab.source ) {
                next.source = tab.source;
            } else if ( list.state.filters.source === VERIFIED_SOURCE && list.state.filters.status === 'needs_manual' ) {
                next.source = ''; // leaving the "Owner tasks" tab
            }
            list.setFilters( next );
        },
    } );

    // Storefront compliance banner (assets/js/compliance-banner.js; GET /compliance/storefront). A missing
    // module or route (404 while the compliance controller is not registered) simply shows nothing.
    // Spacing below the banner only while it shows something (the banner module empties the host on "pass").
    const spaceBanner = () => {
        bannerHost.style.marginBottom = bannerHost.firstChild ? '14px' : '';
    };

    async function loadBanner() {
        await renderBanner();
        spaceBanner();
    }

    async function renderBanner() {
        let status;
        try {
            status = await api.get( '/compliance/storefront', {}, { silent: true } );
        } catch ( e ) {
            if ( e && e.status !== 404 ) {
                replace( bannerHost, callout( 'warning', null, `The storefront compliance status could not be loaded${ e.message ? `: ${ e.message }` : '.' }` ) );
            } else {
                replace( bannerHost );
            }
            return;
        }
        if ( ! isObject( status ) || typeof status.effective !== 'string' ) {
            replace( bannerHost );
            return;
        }
        try {
            const mod = await import( '../compliance-banner.js' );
            if ( typeof mod.default !== 'function' ) {
                throw new Error( 'compliance banner not available' );
            }
            mod.default( bannerHost, status, {
                onRecheck: () => {
                    spaceBanner();
                    list.reload();
                },
                onAcknowledge: () => {
                    spaceBanner();
                    list.reload();
                },
            } );
        } catch ( e ) {
            // Fallback while the banner module is unavailable: state the status in plain words.
            if ( status.effective === 'block' || status.effective === 'warn' ) {
                replace( bannerHost, callout( status.effective === 'block' ? 'danger' : 'warning', 'Storefront compliance check',
                    status.effective === 'block'
                        ? 'The store currently presents products in a way that contradicts the research-use-only listing description. How the store presents itself is your legal decision as the owner; automated submissions and outreach sending pause while this check blocks.'
                        : 'The storefront check found wording worth reviewing before you submit listings.' ) );
            } else {
                replace( bannerHost );
            }
        }
    }

    async function loadVerified() {
        let status;
        try {
            status = await api.get( '/submissions/verified', {}, { silent: true } );
        } catch ( e ) {
            replace( verifiedHost );
            return;
        }
        if ( ! isObject( status ) || status.available !== true ) {
            replace( verifiedHost, isObject( status ) && status.available === false && status.summary ? el( 'div', { style: { marginBottom: '14px' } }, callout( 'warning', 'Verified opportunity database', String( status.summary ) ) ) : null );
            return;
        }
        replace( verifiedHost, el( 'div', { style: { marginBottom: '14px' } }, verifiedSummaryCard( status, {
            onImport: async () => {
                let res;
                try {
                    res = await api.post( '/submissions/verified/import', {} );
                } catch ( e ) {
                    return; // api.js already showed the error.
                }
                reportAction( res, 'Verified opportunities imported.', { refusedText: 'The import did not run.' } );
                await loadVerified();
                list.reload();
            },
            onShowTasks: () => view.select( 'owner_tasks' ),
        } ) ) );
    }

    replace( root,
        pageHeader( {
            title: 'Submissions',
            description: 'Listing packages generated for directories and resource pages. Review the content, approve or hand-submit, and track confirmations.',
            actions: button( 'Refresh', { iconName: 'refresh', onClick: () => list.reload() } ),
        } ),
        bannerHost,
        verifiedHost,
        el( 'div', { style: { marginBottom: '14px' } }, view.el ),
        list.el,
    );
    list.reload();
    loadBanner();
    loadVerified();

    if ( params.open ) {
        openSubmission( Number( params.open ) );
    }

    // ------------------------------------------------------------------
    // Drawer
    // ------------------------------------------------------------------
    async function openSubmission( id ) {
        if ( ! id ) {
            return;
        }
        const body = el( 'div', {}, loading( 'Loading package…', true ) );
        const dr = drawer( `Submission #${ id }`, body, { width: 'wide' } );

        async function load() {
            let data;
            try {
                data = await api.get( `/submissions/${ id }` );
            } catch ( e ) {
                dr.setContent( errorState( { title: 'Could not load this submission', message: e && e.message ? e.message : '', retry: load } ) );
                return;
            }
            const s = obj( data.submission ).id ? obj( data.submission ) : obj( data );
            const prospect = obj( data.prospect );
            const opportunity = obj( data.opportunity );
            if ( ! s.id ) {
                dr.setContent( errorState( { title: 'Submission not found' } ) );
                return;
            }
            const payload = obj( s.payload );
            const ownerPkg = ownerTaskPackage( payload );
            const refresh = async () => {
                list.reload();
                await load();
            };

            dr.setTitle( `${ prospect.name || prospect.domain || `Submission #${ s.id }` }` );
            dr.setSubtitle( [ statusBadge( s.status ), statusBadge( s.method, { dot: false } ), s.adapter ? badge( labelize( s.adapter ), 'neutral' ) : null, ownerPkg ? ownerTaskBadges( ownerPkg ) : null, extLink( s.target_url, truncate( String( s.target_url || '' ), 60 ) ) ] );

            const stack = el( 'div', { class: 'ppclg-stack' } );

            if ( s.last_error ) {
                stack.appendChild( callout( s.status === 'failed' ? 'danger' : 'warning', 'Last error', String( s.last_error ) ) );
            }
            if ( arr( payload.unmapped_required ).length ) {
                stack.appendChild( callout( 'warning', 'Required fields we cannot fill automatically', `The form requires: ${ payload.unmapped_required.map( labelize ).join( ', ' ) }. Complete these by hand when submitting.` ) );
            }
            const compliance = obj( payload.compliance );
            if ( compliance.clean === false && arr( compliance.violations ).length ) {
                stack.appendChild( callout( 'danger', 'Claims filter flagged the generated content', el( 'ul', {}, arr( compliance.violations ).map( ( v ) => el( 'li', {}, isObject( v ) ? `${ v.message || v.rule }: “${ v.match || '' }”` : String( v ) ) ) ) ) );
            }

            if ( ownerPkg ) {
                stack.appendChild( ownerTaskCard( s, ownerPkg, { onMarked: refresh } ) );
            }

            stack.appendChild( card( { title: 'Status & timeline', body: kv( [
                [ 'Prospect', prospect.id ? el( 'a', { href: ctx.pageUrl( 'prospects', { open: prospect.id } ) }, `${ prospect.name || prospect.domain } ` ) : null ],
                [ 'Opportunity', opportunity.id ? el( 'span', {}, badge( labelize( opportunity.type || '' ), 'brand' ), ' ', opportunity.title || '', opportunity.url ? [ ' ', extLink( opportunity.url, 'page' ) ] : null ) : null ],
                [ 'Target page', extLink( s.target_url ) ],
                [ 'Category', s.category || null ],
                [ 'Attempts', fmtNumber( s.attempts ) ],
                [ 'Scheduled', s.scheduled_at ? fmtDate( s.scheduled_at ) : null ],
                [ 'Submitted', s.submitted_at ? fmtDate( s.submitted_at ) : null ],
                [ 'Confirmed', s.confirmed_at ? fmtDate( s.confirmed_at ) : null ],
                [ 'Next verification', s.next_verify_at ? fmtDate( s.next_verify_at ) : null ],
                [ 'Confirmation ref', s.confirmation_ref || null ],
                [ 'Listing URL', s.listing_url ? extLink( s.listing_url ) : null ],
                [ 'Reviewed by', s.reviewed_by ? `User #${ s.reviewed_by }` : null ],
                [ 'Created', fmtDate( s.created_at ) ],
            ], { compact: true } ) } ) );

            // Generic package cards (owner tasks show their kit in the owner-task card instead).
            if ( ! ownerPkg ) {
                // Business fields
                const business = obj( payload.business );
                stack.appendChild( card( {
                    title: 'Business details',
                    subtitle: 'Copied from Settings → Business profile when the package was built',
                    actions: copyButton( () => Object.entries( business ).map( ( [ k, v ] ) => `${ labelize( k ) }: ${ Array.isArray( v ) ? v.join( ', ' ) : v }` ).join( '\n' ), 'Copy all' ),
                    body: Object.keys( business ).length ? kv( Object.entries( business ).map( ( [ k, v ] ) => [ labelize( k ), Array.isArray( v ) ? v.join( ', ' ) : ( k === 'website' || k === 'logo_url' ? ( v ? extLink( String( v ) ) : null ) : v ) ] ), { compact: true } ) : emptyState( { title: 'No business fields in this package', compact: true } ),
                } ) );

                // Generated content (editable)
                const content = obj( payload.content );
                const contentFields = CONTENT_FIELDS.map( ( f ) => formField( { type: 'textarea', name: f.key, label: f.label, value: content[ f.key ] || '', rows: f.rows, full: true } ) );
                const categoriesField = formField( { type: 'list', name: 'category_suggestions', label: 'Category suggestions (one per line)', value: arr( content.category_suggestions ), rows: 3 } );
                const keywordsField = formField( { type: 'list', name: 'keywords', label: 'Keywords (one per line)', value: arr( content.keywords ), rows: 3 } );
                const violationsBox = el( 'div' );
                const editable = ! [ 'confirmed', 'submitted' ].includes( s.status );
                const fieldInputs = {};
                const fieldRows = arr( payload.fields );

                const saveContent = async ( { quiet = false } = {} ) => {
                    const nextContent = { ...content };
                    for ( const f of contentFields ) {
                        nextContent[ f.name ] = f.get();
                    }
                    nextContent.category_suggestions = categoriesField.get();
                    nextContent.keywords = keywordsField.get();
                    const nextFields = fieldRows.map( ( f ) => ( fieldInputs[ f.name ] ? { ...f, value: fieldInputs[ f.name ].value } : f ) );
                    const res = await api.post( `/submissions/${ s.id }/payload`, { payload: { ...payload, content: nextContent, fields: nextFields } } );
                    const violations = arr( res && ( res.violations || obj( res.compliance ).violations ) );
                    replace( violationsBox, violations.length
                        ? callout( 'danger', 'Claims filter', el( 'ul', { class: 'ppclg-violations' }, violations.map( ( v ) => el( 'li', {}, icon( 'alert', 14 ), isObject( v ) ? `${ v.message || labelize( v.rule || 'claim' ) }: “${ v.match || '' }”` : String( v ) ) ) ) )
                        : callout( 'success', null, 'No unverified claims found. The copy stays within the research-use-only positioning.' ) );
                    if ( ! quiet ) {
                        // held: the edit took an approved/queued submission out of the automated queue.
                        const held = Boolean( res && res.held ) ? ' The submission was moved back to review; approve it again once the text is fixed.' : '';
                        toast( violations.length ? `${ violations.length } claim issue(s) found — edit the text before approving.${ held }` : 'Content saved and claims check passed.', violations.length ? 'warning' : 'success' );
                    }
                    return violations;
                };

                stack.appendChild( card( {
                    title: 'Generated content',
                    subtitle: content.style ? `Style: ${ labelize( content.style ) } · generated ${ fmtDate( payload.generated_at ) }` : 'Edit freely; every save re-runs the claims filter',
                    actions: copyButton( () => CONTENT_FIELDS.map( ( f ) => `${ f.label }\n${ contentFields.find( ( c ) => c.name === f.key ).get() }` ).join( '\n\n' ), 'Copy text' ),
                    body: [
                        el( 'div', { class: 'ppclg-form-grid' }, contentFields.map( ( f ) => f.el ), categoriesField.el, keywordsField.el ),
                        violationsBox,
                    ],
                    footer: editable ? [ button( 'Save & check claims', { kind: 'primary', iconName: 'shield', onClick: () => saveContent() } ) ] : [ el( 'span', { class: 'ppclg-notice-inline' }, 'Content is locked once submitted.' ) ],
                } ) );

                // Form field mapping
                stack.appendChild( card( {
                    title: 'Form field mapping',
                    subtitle: fieldRows.length ? `${ fieldRows.length } fields detected on the listing form` : 'No listing form detected — use the instructions below',
                    body: fieldRows.length ? dataTable( {
                        dense: true,
                        plain: true,
                        rows: fieldRows,
                        columns: [
                            { key: 'label', label: 'Field', render: ( f ) => el( 'span', {}, el( 'span', { class: 'ppclg-cell-title' }, f.label || f.name ), el( 'span', { class: 'ppclg-cell-sub ppclg-mono' }, f.name ) ) },
                            { key: 'semantic', label: 'Meaning', render: ( f ) => badge( labelize( f.semantic || 'unknown' ), f.semantic && f.semantic !== 'unknown' ? 'info' : 'neutral' ) },
                            { key: 'type', label: 'Type', render: ( f ) => `${ f.type || 'text' }${ f.required ? ' · required' : '' }${ f.options_count ? ` · ${ f.options_count } options` : '' }` },
                            { key: 'value', label: 'Value', render: ( f ) => {
                                if ( isObject( f.matched_option ) ) {
                                    return el( 'span', {}, badge( `Select: ${ f.matched_option.text || f.matched_option.value }`, 'success' ) );
                                }
                                if ( ! editable || [ 'hidden', 'captcha', 'password', 'file' ].includes( f.type ) ) {
                                    return f.value === '' || f.value === null || f.value === undefined ? el( 'span', { class: 'ppclg-muted' }, f.required ? 'Missing (required)' : '—' ) : el( 'span', {}, truncate( String( f.value ), 80 ) );
                                }
                                const input = el( f.type === 'textarea' ? 'textarea' : 'input', { class: f.type === 'textarea' ? 'ppclg-textarea' : 'ppclg-input ppclg-input--small', value: f.value === null || f.value === undefined ? '' : String( f.value ), rows: 2, 'aria-label': f.label || f.name } );
                                fieldInputs[ f.name ] = input;
                                return input;
                            } },
                        ],
                    } ) : el( 'p', {}, 'The package is a manual kit: open the target page and fill the form with the business details and content above.' ),
                    footer: fieldRows.length && editable ? [ button( 'Save field values', { onClick: () => saveContent() } ) ] : null,
                } ) );

                // Instructions
                if ( arr( payload.instructions ).length ) {
                    stack.appendChild( card( { title: 'Submission steps', subtitle: 'Tick steps as you complete them (saved in this browser only)', body: checklist( arr( payload.instructions ).map( String ), { storageKey: `ppclg_sub_steps_${ s.id }` } ) } ) );
                }
            }

            // Response
            if ( s.response && ( ! isObject( s.response ) || Object.keys( s.response ).length ) ) {
                stack.appendChild( card( { title: 'Last response', subtitle: 'Excerpt stored by the adapter (HTML stripped, max 2000 chars)', body: pre( s.response ) } ) );
            }

            // Actions
            const actions = [];
            if ( ! ownerPkg && [ 'pending_review', 'needs_manual' ].includes( s.status ) ) {
                actions.push( button( 'Approve', { kind: 'success', iconName: 'check', onClick: async () => {
                    if ( ! ( await confirm( 'Approve this package? If automated submission is permitted for this site it will be queued; otherwise it stays as a manual kit.', { confirmText: 'Approve' } ) ) ) {
                        return;
                    }
                    const res = await api.post( `/submissions/${ s.id }/approve`, {} );
                    const ok = res === true || ( isObject( res ) && ( res.ok === true || res.approved === true ) );
                    // A refused approval keeps the package manual; the server explains why (policy reasons, claims check…).
                    const serverText = isObject( res ) && typeof res.message === 'string' ? res.message.trim() : '';
                    toast( serverText || ( ok ? 'Approved and queued for automated submission.' : 'Not approved for automated submission; the package stays manual. Submit it by hand and use “Mark submitted”.' ), ok ? 'success' : 'warning', { timeout: 7000 } );
                    await refresh();
                } } ) );
            }
            if ( ! [ 'confirmed', 'rejected', 'duplicate' ].includes( s.status ) ) {
                actions.push( button( 'Reject', { kind: 'danger', iconName: 'x', onClick: async () => {
                    const reason = await prompt( 'Why is this package being rejected?', { title: 'Reject submission', label: 'Reason', confirmText: 'Reject', danger: true } );
                    if ( reason === null ) {
                        return;
                    }
                    reportAction( await api.post( `/submissions/${ s.id }/reject`, { reason } ), 'Submission rejected.' );
                    await refresh();
                } } ) );
            }
            if ( [ 'failed', 'rejected' ].includes( s.status ) ) {
                actions.push( button( 'Retry', { iconName: 'refresh', onClick: async () => {
                    // A refused retry (policy no longer allows it) answers ok: false with the reason.
                    reportAction( await api.post( `/submissions/${ s.id }/retry`, {} ), 'Submission queued again for automatic sending.' );
                    await refresh();
                } } ) );
            }
            if ( ! ownerPkg && ! [ 'submitted', 'confirmed' ].includes( s.status ) ) {
                actions.push( button( 'Rebuild package', { iconName: 'package', onClick: async () => {
                    if ( ! ( await confirm( 'Rebuild the package from the current site profile and settings? Your edits to the generated content will be replaced.', { confirmText: 'Rebuild' } ) ) ) {
                        return;
                    }
                    // rebuildPackage() returns false when the crawl fails or the submission is locked: ok false + message.
                    reportAction( await api.post( `/submissions/${ s.id }/rebuild`, {} ), 'Package rebuilt.' );
                    await refresh();
                } } ) );
            }
            if ( ! ( ownerPkg && OPEN_TASK_STATUSES.includes( String( s.status ) ) ) && ! [ 'confirmed', 'rejected', 'duplicate' ].includes( s.status ) ) {
                actions.push( button( 'Mark submitted', { kind: 'primary', iconName: 'send', onClick: () => formModal( {
                    title: 'Mark as submitted manually',
                    description: 'Record that you submitted this listing by hand. Verification will look for the live listing starting now.',
                    submitText: 'Mark submitted',
                    fields: [
                        { type: 'url', name: 'listing_url', label: 'Listing URL (if known)', placeholder: 'https://directory.example/listing/pure-peptides-canada' },
                        { type: 'text', name: 'confirmation_ref', label: 'Confirmation reference' },
                        { type: 'textarea', name: 'note', label: 'Note', rows: 3 },
                    ],
                    onSubmit: async ( values ) => {
                        const accepted = reportAction( await api.post( `/submissions/${ s.id }/mark-submitted`, values ), 'Marked as submitted. Verification is scheduled.' );
                        await refresh();
                        return accepted;
                    },
                } ) } ) );
            }
            if ( ownerPkg ) {
                const page = safeHttpUrl( s.target_url );
                if ( page ) {
                    actions.push( linkButton( 'Open submission page', page, { iconName: 'external', external: true } ) );
                }
            } else {
                actions.push( linkButton( 'Open target page', s.target_url || '#', { iconName: 'external', external: true } ) );
            }
            dr.setActions( actions );
            dr.setContent( stack );
        }
        await load();
    }
}
