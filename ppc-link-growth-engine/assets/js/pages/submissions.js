/**
 * Submissions page: status tabs, table and the package review drawer
 * (business fields, editable generated content with claims check, form mapping,
 * instructions, response excerpt and approve / reject / retry / rebuild / mark-submitted actions).
 */

import * as api from '../api.js';
import {
    el, replace, badge, statusBadge, button, callout, kv, pre, loading, errorState, emptyState, timeEl, fmtNumber, fmtDate, labelize, truncate,
    pageHeader, drawer, tabs, dataTable, formField, formModal, confirm, prompt, toast, extLink, listView, normalizeList, card, checklist, copyButton, isObject, icon, linkButton,
} from '../ui.js';
import { reportAction } from './outcome.js';

const TABS = [
    { id: 'all', label: 'All', status: '' },
    { id: 'pending_review', label: 'Pending review', status: 'pending_review' },
    { id: 'needs_manual', label: 'Needs manual', status: 'needs_manual' },
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

    const list = listView( {
        fetch: fetchSubmissions,
        defaultSort: { key: 'id', dir: 'desc' },
        initial: { filters: { status: TABS.find( ( t ) => t.id === initialTab ).status, method: params.method || '', q: params.q || '' } },
        onRowClick: ( row ) => openSubmission( Number( row.id ) ),
        rowKey: ( row ) => row.id,
        empty: { title: 'No submissions in this state', message: 'Packages are created from approved prospects (Prospects → Actions → Create package) or automatically by the packaging stage.', iconName: 'package' },
        filters: [
            { name: 'method', label: 'Method', type: 'select', options: METHODS, allLabel: 'All methods' },
            { name: 'q', label: 'Search', type: 'search', placeholder: 'Target URL…' },
        ],
        columns: [
            { key: 'id', label: '#', sortable: true, render: ( r ) => `#${ r.id }` },
            { key: 'prospect', label: 'Prospect', render: ( r ) => el( 'span', {}, el( 'span', { class: 'ppclg-cell-title' }, r.prospect_name || r.prospect_domain || `Prospect #${ r.prospect_id }` ), el( 'span', { class: 'ppclg-cell-sub' }, r.prospect_domain || '' ) ) },
            { key: 'target_url', label: 'Target', render: ( r ) => extLink( r.target_url, truncate( String( r.target_url || '' ).replace( /^https?:\/\//, '' ), 48 ) ) },
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
            const tab = TABS.find( ( t ) => t.status === ( state.filters.status || '' ) );
            if ( tab ) {
                view.setCount( tab.id, total );
            }
            ctx.setHash( { tab: tab ? tab.id : 'all', method: state.filters.method, q: state.filters.q } );
        },
    } );

    const view = tabs( {
        pills: true,
        active: initialTab,
        items: TABS.map( ( t ) => ( { id: t.id, label: t.label } ) ),
        onChange: ( id ) => {
            const tab = TABS.find( ( t ) => t.id === id );
            list.setFilters( { status: tab ? tab.status : '' } );
        },
    } );

    replace( root,
        pageHeader( {
            title: 'Submissions',
            description: 'Listing packages generated for directories and resource pages. Review the content, approve or hand-submit, and track confirmations.',
            actions: button( 'Refresh', { iconName: 'refresh', onClick: () => list.reload() } ),
        } ),
        el( 'div', { style: { marginBottom: '14px' } }, view.el ),
        list.el,
    );
    list.reload();

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
            const refresh = async () => {
                list.reload();
                await load();
            };

            dr.setTitle( `${ prospect.name || prospect.domain || `Submission #${ s.id }` }` );
            dr.setSubtitle( [ statusBadge( s.status ), statusBadge( s.method, { dot: false } ), s.adapter ? badge( labelize( s.adapter ), 'neutral' ) : null, extLink( s.target_url, truncate( String( s.target_url || '' ), 60 ) ) ] );

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
                    toast( violations.length ? `${ violations.length } claim issue(s) found — edit the text before approving.` : 'Content saved and claims check passed.', violations.length ? 'warning' : 'success' );
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

            // Response
            if ( s.response && ( ! isObject( s.response ) || Object.keys( s.response ).length ) ) {
                stack.appendChild( card( { title: 'Last response', subtitle: 'Excerpt stored by the adapter (HTML stripped, max 2000 chars)', body: pre( s.response ) } ) );
            }

            // Actions
            const actions = [];
            if ( [ 'pending_review', 'needs_manual' ].includes( s.status ) ) {
                actions.push( button( 'Approve', { kind: 'success', iconName: 'check', onClick: async () => {
                    if ( ! ( await confirm( 'Approve this package? If automated submission is permitted for this site it will be queued; otherwise it stays as a manual kit.', { confirmText: 'Approve' } ) ) ) {
                        return;
                    }
                    const res = await api.post( `/submissions/${ s.id }/approve`, {} );
                    const ok = res === true || ( isObject( res ) && ( res.ok === true || res.approved === true ) );
                    toast( ok ? 'Approved and queued for automated submission.' : 'Approved for manual handling — automated submission is not permitted for this target. Submit it by hand and use “Mark submitted”.', ok ? 'success' : 'info', { timeout: 7000 } );
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
                    reportAction( await api.post( `/submissions/${ s.id }/retry`, {} ), 'Submission re-queued for review.' );
                    await refresh();
                } } ) );
            }
            if ( ! [ 'submitted', 'confirmed' ].includes( s.status ) ) {
                actions.push( button( 'Rebuild package', { iconName: 'package', onClick: async () => {
                    if ( ! ( await confirm( 'Rebuild the package from the current site profile and settings? Your edits to the generated content will be replaced.', { confirmText: 'Rebuild' } ) ) ) {
                        return;
                    }
                    // rebuildPackage() returns false when the crawl fails or the submission is locked: ok false + message.
                    reportAction( await api.post( `/submissions/${ s.id }/rebuild`, {} ), 'Package rebuilt.' );
                    await refresh();
                } } ) );
            }
            if ( ! [ 'confirmed', 'rejected', 'duplicate' ].includes( s.status ) ) {
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
            actions.push( linkButton( 'Open target page', s.target_url || '#', { iconName: 'external', external: true } ) );
            dr.setActions( actions );
            dr.setContent( stack );
        }
        await load();
    }
}
