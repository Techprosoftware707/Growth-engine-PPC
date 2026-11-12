/**
 * On-site SEO page: site audit issues, IndexNow submissions and internal-link suggestions.
 *
 * Nothing on this page changes site content except the internal-link "Apply" buttons: a row's Apply runs only on
 * that click, and "Apply all" only after a confirmation that says it edits published content and creates revisions.
 */

import * as api from '../api.js';
import {
    el, replace, card, badge, button, linkButton, callout, statTile, dataTable, errorState, loading, pageHeader, extLink, timeEl,
    fmtNumber, fmtDate, toast, confirm, labelize, truncate, kv, details, isObject,
} from '../ui.js';

const SEVERITY = {
    high: { label: 'High', kind: 'danger' },
    medium: { label: 'Medium', kind: 'warning' },
    low: { label: 'Low', kind: 'neutral' },
};

const LINK_STATUSES = [
    { id: 'pending', label: 'Waiting for review' },
    { id: 'applied', label: 'Applied' },
    { id: 'dismissed', label: 'Dismissed' },
    { id: 'stale', label: 'No longer valid' },
];

const obj = ( v ) => ( isObject( v ) ? v : {} );
const arr = ( v ) => ( Array.isArray( v ) ? v : [] );
const plural = ( n, one, many ) => `${ fmtNumber( n ) } ${ Number( n ) === 1 ? one : many }`;

/** Excerpt text with the first case-insensitive occurrence of the anchor marked (text nodes only). */
function highlight( excerpt, anchor ) {
    const text = String( excerpt || '' );
    const needle = String( anchor || '' );
    const idx = needle ? text.toLowerCase().indexOf( needle.toLowerCase() ) : -1;
    if ( idx < 0 || text.slice( idx, idx + needle.length ).toLowerCase() !== needle.toLowerCase() ) {
        return el( 'span', {}, text );
    }
    return el( 'span', {}, text.slice( 0, idx ), el( 'mark', {}, text.slice( idx, idx + needle.length ) ), text.slice( idx + needle.length ) );
}

/** Message from a refusal or failure (ApiError or a { ok:false, message } body). */
function reason( errOrRes, fallback ) {
    if ( errOrRes && typeof errOrRes.message === 'string' && errOrRes.message !== '' ) {
        return errOrRes.message;
    }
    return fallback;
}

export default async function render( root, ctx ) {
    const params = ctx.params || {};
    const state = {
        severity: [ 'high', 'medium', 'low' ].includes( params.severity ) ? params.severity : '',
        linkStatus: LINK_STATUSES.some( ( s ) => s.id === params.links ) ? params.links : 'pending',
    };

    const auditHost = el( 'div', { class: 'ppclg-section' } );
    const indexHost = el( 'div', { class: 'ppclg-section' } );
    const linksHost = el( 'div', { class: 'ppclg-section' } );

    replace( root,
        pageHeader( {
            title: 'On-site SEO',
            description: 'Checks your published pages, posts and products for search problems, tells IndexNow-enabled search engines about new and changed pages, and suggests internal links between your own pages. The audit never edits content; a link is added only when you click Apply.',
        } ),
        auditHost,
        indexHost,
        linksHost,
    );

    const keepHash = () => ctx.setHash( { ...( state.severity ? { severity: state.severity } : {} ), ...( state.linkStatus !== 'pending' ? { links: state.linkStatus } : {} ) } );

    // ------------------------------------------------------------------
    // Site audit
    // ------------------------------------------------------------------
    async function loadAudit() {
        replace( auditHost, card( { title: 'Site audit', body: loading( 'Loading the audit…', true ) } ) );
        let data;
        try {
            data = await api.get( '/seo/audit', {}, { silent: true } );
        } catch ( e ) {
            replace( auditHost, card( { title: 'Site audit', body: errorState( { title: 'The audit could not be loaded', message: reason( e, '' ), retry: loadAudit } ) } ) );
            return;
        }
        renderAudit( obj( data ) );
    }

    async function runAudit( progressNode ) {
        let last = null;
        for ( let round = 0; round < 40 && root.isConnected; round++ ) {
            let res;
            try {
                res = obj( await api.post( '/seo/audit/run', {}, { silent: true } ) );
            } catch ( e ) {
                toast( reason( e, 'The audit could not run.' ), 'error' );
                break;
            }
            last = res;
            if ( res.ok === false ) {
                toast( reason( res, 'The audit did not run.' ), 'warning' );
                break;
            }
            replace( progressNode, el( 'span', { class: 'ppclg-small ppclg-muted', role: 'status' }, String( res.message || '' ) ) );
            if ( ! res.more ) {
                toast( String( res.message || 'Audit finished.' ), 'success', { timeout: 7000 } );
                break;
            }
        }
        if ( last && last.more && last.ok !== false ) {
            toast( 'The audit is still running; it continues in the background and on your next visit.', 'info' );
        }
        await loadAudit();
    }

    function renderAudit( data ) {
        if ( data.available === false ) {
            replace( auditHost, card( { title: 'Site audit', body: callout( 'warning', 'Not available', String( data.message || 'The site audit is not available in this installation.' ) ) } ) );
            return;
        }
        const summary = obj( data.summary );
        const result = data.result ? obj( data.result ) : null;
        const progress = obj( data.progress );
        const counts = obj( summary.counts );
        const issues = arr( data.issues );
        const progressNode = el( 'div' );

        const runBtn = button( progress.running ? 'Continue audit' : ( result ? 'Run audit again' : 'Run audit' ), {
            kind: 'primary', iconName: 'play', small: true,
            title: 'Reads published pages, posts and products from the database. Nothing is edited.',
            onClick: () => runAudit( progressNode ),
        } );

        const tiles = el( 'div', { class: 'ppclg-grid ppclg-grid--tiles' },
            statTile( { label: 'Pages audited', iconName: 'file', value: result ? fmtNumber( result.pages ) : 'Not run yet', hint: result && result.checked_at ? `Last finished ${ fmtDate( result.checked_at ) }` : 'Run the audit to see results' } ),
            statTile( { label: 'High', iconName: 'alert', value: result ? fmtNumber( counts.high ) : '—', tone: Number( counts.high ) > 0 ? 'critical' : null, hint: 'Noindex in menus, missing or duplicate titles', onClick: result ? () => setSeverity( 'high' ) : null } ),
            statTile( { label: 'Medium', iconName: 'info', value: result ? fmtNumber( counts.medium ) : '—', hint: 'Descriptions, title length, broken links, orphans', onClick: result ? () => setSeverity( 'medium' ) : null } ),
            statTile( { label: 'Low', iconName: 'list', value: result ? fmtNumber( counts.low ) : '—', hint: 'Thin content, missing alt text, extra H1s', onClick: result ? () => setSeverity( 'low' ) : null } ),
        );

        const notes = [];
        if ( progress.running ) {
            notes.push( callout( 'info', 'Audit in progress', `${ plural( progress.checked, 'item', 'items' ) } of ${ fmtNumber( progress.total ) } checked so far. The results below are from the last finished audit.` ) );
        }
        if ( result && result.capped ) {
            notes.push( callout( 'info', 'Partial audit', `Only the first ${ fmtNumber( result.max_pages ) } published items were audited (setting “Audit max pages”). Orphan pages are not reported for a partial audit because links from the unread items are unknown.` ) );
        }
        if ( result && Number( result.issues_total ) > issues.length ) {
            notes.push( callout( 'info', null, `Showing the ${ fmtNumber( issues.length ) } most important of ${ fmtNumber( result.issues_total ) } issues.` ) );
        }

        const tableHost = el( 'div' );
        const filterRow = el( 'div', { class: 'ppclg-row', role: 'group', 'aria-label': 'Filter issues by severity' } );

        function setSeverity( sev ) {
            state.severity = sev;
            keepHash();
            drawFilter();
            drawTable();
        }

        function drawFilter() {
            const options = [ { id: '', label: `All (${ fmtNumber( issues.length ) })` } ].concat( Object.entries( SEVERITY ).map( ( [ id, s ] ) => ( { id, label: `${ s.label } (${ fmtNumber( issues.filter( ( i ) => i.severity === id ).length ) })` } ) ) );
            replace( filterRow, options.map( ( o ) => button( o.label, {
                small: true,
                kind: state.severity === o.id ? 'primary' : 'ghost',
                attrs: { 'aria-pressed': state.severity === o.id ? 'true' : 'false' },
                onClick: () => setSeverity( o.id ),
            } ) ) );
        }

        function drawTable() {
            const rows = state.severity ? issues.filter( ( i ) => i.severity === state.severity ) : issues;
            replace( tableHost, dataTable( {
                caption: 'Site audit issues',
                rows,
                rowKey: ( r ) => `${ r.post_id }-${ r.code }-${ r.message }`,
                empty: result
                    ? { title: state.severity ? `No ${ SEVERITY[ state.severity ].label.toLowerCase() } issues` : 'No issues found', message: 'Nothing to fix here in the last audit.', iconName: 'check', compact: true }
                    : { title: 'No audit yet', message: 'Click “Run audit” to check your published pages.', iconName: 'search', compact: true },
                columns: [
                    { key: 'severity', label: 'Severity', width: '96px', render: ( r ) => {
                        const s = SEVERITY[ r.severity ] || { label: labelize( r.severity ), kind: 'neutral' };
                        return badge( s.label, s.kind, { dot: true } );
                    } },
                    { key: 'page', label: 'Page', render: ( r ) => el( 'div', {},
                        el( 'div', {}, String( r.title || ( Number( r.post_id ) > 0 ? `#${ r.post_id }` : 'Whole site' ) ) ),
                        r.url ? el( 'div', { class: 'ppclg-small' }, extLink( r.url, null, { maxLength: 60 } ) ) : null,
                    ) },
                    { key: 'issue', label: 'Issue and fix', render: ( r ) => el( 'div', {},
                        el( 'div', {}, String( r.message || '' ) ),
                        r.fix ? el( 'div', { class: 'ppclg-small ppclg-muted' }, `Fix: ${ r.fix }` ) : null,
                    ) },
                    { key: 'edit', label: 'Edit', align: 'right', render: ( r ) => ( r.edit_url ? linkButton( 'Edit', r.edit_url, { small: true, iconName: 'edit', external: true } ) : null ) },
                ],
            } ) );
        }

        const extras = [];
        if ( result ) {
            const stats = obj( result.stats );
            extras.push( details( 'Totals from the last audit', kv( [
                [ 'Items by type', Object.entries( obj( result.by_type ) ).map( ( [ t, n ] ) => `${ labelize( t ) }: ${ fmtNumber( n ) }` ).join( ' · ' ) || null ],
                [ 'Items without a Rank Math focus keyword', stats.without_focus_keyword !== undefined ? fmtNumber( stats.without_focus_keyword ) : 'unknown' ],
                [ 'Items set to noindex', stats.noindex !== undefined ? fmtNumber( stats.noindex ) : 'unknown' ],
                [ 'Internal links in content', stats.internal_links !== undefined ? fmtNumber( stats.internal_links ) : 'unknown' ],
                [ 'External links in content', stats.external_links !== undefined ? fmtNumber( stats.external_links ) : 'unknown' ],
                [ 'Average words per item', stats.average_words !== undefined ? fmtNumber( stats.average_words ) : 'unknown' ],
            ], { compact: true } ) ) );
            const sample = arr( result.sample );
            extras.push( details( sample.length ? `HTTP spot check (${ plural( sample.length, 'address', 'addresses' ) })` : 'HTTP spot check (not run)', sample.length
                ? dataTable( {
                    caption: 'HTTP spot check',
                    dense: true,
                    rows: sample,
                    columns: [
                        { key: 'url', label: 'Address', render: ( s ) => extLink( s.url, null, { maxLength: 60 } ) },
                        { key: 'status', label: 'HTTP', render: ( s ) => ( s.status ? String( s.status ) : 'unknown' ) },
                        { key: 'result', label: 'Result', render: ( s ) => badge( s.result === 'ok' ? 'OK' : ( s.result === 'issue' ? 'Issue' : 'Unknown' ), s.result === 'ok' ? 'success' : ( s.result === 'issue' ? 'warning' : 'neutral' ) ) },
                        { key: 'note', label: 'Note', render: ( s ) => truncate( String( s.note || '' ), 120 ) },
                    ],
                } )
                : el( 'p', { class: 'ppclg-muted' }, 'Up to 10 addresses are fetched at the end of an audit (robots.txt and crawl delay respected) to confirm the HTTP status, canonical tag and structured data. It was turned off or had no time in the last audit.' ) ) );
        }

        replace( auditHost, card( {
            title: 'Site audit',
            subtitle: summary.checked_at ? el( 'span', {}, 'Last finished ', timeEl( summary.checked_at ) ) : 'Not run yet',
            actions: [ runBtn ],
            body: el( 'div', { class: 'ppclg-stack' }, tiles, progressNode, notes, result ? filterRow : null, tableHost, extras ),
        } ) );
        drawFilter();
        drawTable();
    }

    // ------------------------------------------------------------------
    // IndexNow
    // ------------------------------------------------------------------
    async function loadIndexNow() {
        replace( indexHost, card( { title: 'IndexNow', body: loading( 'Loading IndexNow status…', true ) } ) );
        let data;
        try {
            data = obj( await api.get( '/seo/indexnow', {}, { silent: true } ) );
        } catch ( e ) {
            replace( indexHost, card( { title: 'IndexNow', body: errorState( { title: 'IndexNow status could not be loaded', message: reason( e, '' ), retry: loadIndexNow } ) } ) );
            return;
        }
        if ( data.available === false ) {
            replace( indexHost, card( { title: 'IndexNow', body: callout( 'warning', 'Not available', String( data.message || 'IndexNow is not available in this installation.' ) ) } ) );
            return;
        }
        const s = obj( data.status );
        const history = arr( data.history );
        const blocked = String( s.blocked_reason || '' );
        // After an HTTP 429 the endpoint asked us to wait: "Submit now" waits as well (the server refuses it too).
        const slowDown = Boolean( s.retry_after ) && ( s.rate_limited === true || Number( s.last_status ) === 429 );
        const resumeAt = slowDown ? fmtDate( s.retry_after ) : '';

        const submitBtn = button( 'Submit now', {
            kind: 'primary', small: true, iconName: 'send',
            disabled: blocked !== '' || slowDown,
            title: blocked !== '' ? blocked : ( slowDown ? `IndexNow asked us to wait; submissions resume at ${ resumeAt }.` : 'Sends new and changed public URLs to IndexNow now.' ),
            onClick: async () => {
                let res;
                try {
                    res = obj( await api.post( '/seo/indexnow/run', {}, { silent: true } ) );
                } catch ( e ) {
                    toast( reason( e, 'The submission could not be sent.' ), 'error' );
                    return;
                }
                if ( res.ok === true ) {
                    toast( String( res.message || 'Done.' ), 'success', { timeout: 7000 } );
                } else {
                    toast( reason( res, 'Nothing was submitted.' ), res.skipped ? 'warning' : 'error', { timeout: 9000 } );
                }
                await loadIndexNow();
            },
        } );

        const body = el( 'div', { class: 'ppclg-stack' },
            blocked !== '' ? callout( 'warning', 'Submissions are paused', blocked ) : null,
            s.last_error ? callout( 'danger', 'Last submission was refused', String( s.last_error ) ) : null,
            s.retry_after ? callout( slowDown ? 'warning' : 'info', null, slowDown
                ? el( 'span', {}, 'IndexNow asked us to slow down (HTTP 429). Submissions, including “Submit now”, resume at ', timeEl( s.retry_after, { relative: false } ), '.' )
                : el( 'span', {}, 'The scheduled submission waits until ', timeEl( s.retry_after, { relative: false } ), ' before it tries again.' ) ) : null,
            kv( [
                [ 'Status', s.enabled ? badge( 'On', 'success', { dot: true } ) : badge( 'Off', 'neutral', { dot: true } ) ],
                [ 'Key file', s.key_url ? extLink( s.key_url ) : el( 'span', { class: 'ppclg-muted' }, 'not generated yet (created on the first submission)' ) ],
                [ 'Last accepted submission', s.last_submitted_at ? timeEl( s.last_submitted_at ) : el( 'span', { class: 'ppclg-muted' }, 'never' ) ],
                [ 'Last response', s.last_status ? `HTTP ${ s.last_status }${ Number( s.last_status ) === 202 ? ' (accepted; key being verified)' : '' }` : el( 'span', { class: 'ppclg-muted' }, 'no request sent yet' ) ],
                [ 'URLs submitted in total', fmtNumber( s.submitted_total ) ],
                [ 'Waiting to be submitted', fmtNumber( s.pending ) ],
                [ 'Addresses tracked', fmtNumber( s.tracked ) ],
            ] ),
            history.length ? details( `Recent attempts (${ fmtNumber( history.length ) })`, dataTable( {
                caption: 'Recent IndexNow attempts',
                dense: true,
                rows: history,
                columns: [
                    { key: 'at', label: 'When', render: ( h ) => timeEl( h.at ) },
                    { key: 'urls', label: 'URLs', align: 'right', render: ( h ) => fmtNumber( h.urls ) },
                    { key: 'status', label: 'HTTP', render: ( h ) => ( h.status ? badge( String( h.status ), [ 200, 202 ].includes( Number( h.status ) ) ? 'success' : 'danger' ) : 'no response' ) },
                    { key: 'error', label: 'Note', render: ( h ) => ( h.error ? truncate( String( h.error ), 140 ) : 'Accepted' ) },
                ],
            } ) ) : null,
        );

        replace( indexHost, card( {
            title: 'IndexNow',
            subtitle: 'Tells Bing and other IndexNow search engines about new and changed public pages. Drafts, password-protected and noindex items are never sent.',
            actions: [ submitBtn ],
            body,
        } ) );
    }

    // ------------------------------------------------------------------
    // Internal links
    // ------------------------------------------------------------------
    async function loadLinks() {
        replace( linksHost, card( { title: 'Internal link suggestions', body: loading( 'Loading suggestions…', true ) } ) );
        let data;
        try {
            data = obj( await api.get( '/seo/links', { status: state.linkStatus }, { silent: true } ) );
        } catch ( e ) {
            replace( linksHost, card( { title: 'Internal link suggestions', body: errorState( { title: 'Suggestions could not be loaded', message: reason( e, '' ), retry: loadLinks } ) } ) );
            return;
        }
        renderLinks( data );
    }

    /** Applies suggestions (explicit admin click) and reports every refusal. */
    async function applyIds( ids ) {
        let applied = 0;
        const refused = [];
        for ( let i = 0; i < ids.length; i += 100 ) {
            let res;
            try {
                res = obj( await api.post( '/seo/links/apply', { ids: ids.slice( i, i + 100 ), confirm: true }, { silent: true } ) );
            } catch ( e ) {
                toast( reason( e, 'The links could not be applied.' ), 'error' );
                break;
            }
            applied += Number( res.applied ) || 0;
            for ( const r of arr( res.results ) ) {
                if ( ! r.ok ) {
                    refused.push( String( r.message || 'Not applied.' ) );
                }
            }
            if ( res.ok === false && arr( res.results ).length === 0 ) {
                refused.push( reason( res, 'Nothing was applied.' ) );
            }
        }
        if ( refused.length === 0 && applied > 0 ) {
            toast( `${ plural( applied, 'link was', 'links were' ) } added. WordPress saved a revision of each edited item.`, 'success', { timeout: 7000 } );
        } else if ( refused.length > 0 ) {
            toast( `${ plural( applied, 'link was', 'links were' ) } added; ${ fmtNumber( refused.length ) } not applied: ${ truncate( refused[ 0 ], 200 ) }${ refused.length > 1 ? ' (see each row)' : '' }`, applied > 0 ? 'warning' : 'error', { timeout: 10000 } );
        }
        await loadLinks();
    }

    async function dismissIds( ids ) {
        let res;
        try {
            res = obj( await api.post( '/seo/links/dismiss', { ids }, { silent: true } ) );
        } catch ( e ) {
            toast( reason( e, 'The suggestion could not be dismissed.' ), 'error' );
            return;
        }
        toast( reason( res, res.ok ? 'Dismissed.' : 'Nothing was dismissed.' ), res.ok ? 'success' : 'warning' );
        await loadLinks();
    }

    async function refreshSuggestions() {
        for ( let round = 0; round < 30 && root.isConnected; round++ ) {
            let res;
            try {
                res = obj( await api.post( '/seo/links/run', {}, { silent: true } ) );
            } catch ( e ) {
                toast( reason( e, 'Suggestions could not be refreshed.' ), 'error' );
                break;
            }
            if ( res.ok === false ) {
                toast( reason( res, 'Suggestions were not refreshed.' ), 'warning' );
                break;
            }
            if ( ! res.more ) {
                toast( String( res.message || 'Suggestions refreshed.' ), 'success', { timeout: 7000 } );
                break;
            }
        }
        await loadLinks();
    }

    function renderLinks( data ) {
        if ( data.available === false ) {
            replace( linksHost, card( { title: 'Internal link suggestions', body: callout( 'warning', 'Not available', String( data.message || 'Internal-link suggestions are not available in this installation.' ) ) } ) );
            return;
        }
        const counts = obj( data.counts );
        const items = arr( data.items );
        const progress = obj( data.progress );
        const pendingItems = state.linkStatus === 'pending' ? items : [];

        const refreshBtn = button( 'Find suggestions', {
            small: true, iconName: 'refresh',
            title: 'Reads published content and lists places where a product or key page could be linked. Nothing is edited.',
            onClick: () => refreshSuggestions(),
        } );
        const applyAllBtn = button( 'Apply all', {
            small: true, kind: 'primary', iconName: 'link',
            disabled: pendingItems.length === 0,
            title: pendingItems.length === 0 ? 'No suggestions are waiting for review.' : 'Adds every suggested link listed below after you confirm.',
            onClick: async () => {
                const sources = new Set( pendingItems.map( ( i ) => i.source_post_id ) ).size;
                const ok = await confirm(
                    `This edits ${ plural( sources, 'published item', 'published items' ) } on your live site: ${ plural( pendingItems.length, 'link is', 'links are' ) } added around the existing words shown below. Nothing else in the content changes, and WordPress saves a revision of each item so you can restore the previous version from the editor.`,
                    { title: 'Apply all suggested links?', confirmText: `Apply ${ plural( pendingItems.length, 'link', 'links' ) }`, danger: true },
                );
                if ( ok ) {
                    await applyIds( pendingItems.map( ( i ) => i.id ) );
                }
            },
        } );

        const statusRow = el( 'div', { class: 'ppclg-row', role: 'group', 'aria-label': 'Show suggestions by status' },
            LINK_STATUSES.map( ( s ) => button( `${ s.label } (${ fmtNumber( counts[ s.id ] || 0 ) })`, {
                small: true,
                kind: state.linkStatus === s.id ? 'primary' : 'ghost',
                attrs: { 'aria-pressed': state.linkStatus === s.id ? 'true' : 'false' },
                onClick: () => {
                    state.linkStatus = s.id;
                    keepHash();
                    return loadLinks();
                },
            } ) ),
        );

        const columns = [
            { key: 'source', label: 'In this item', render: ( r ) => el( 'div', {},
                el( 'div', {}, String( r.source_title || `#${ r.source_post_id }` ) ),
                el( 'div', { class: 'ppclg-small ppclg-row' }, r.source_url ? extLink( r.source_url, 'View', {} ) : null, r.edit_url ? extLink( r.edit_url, 'Edit', {} ) : null ),
            ) },
            { key: 'excerpt', label: 'Words to link', render: ( r ) => el( 'div', {},
                el( 'div', { class: 'ppclg-small' }, highlight( r.excerpt, r.anchor ) ),
                el( 'div', { class: 'ppclg-small ppclg-muted' }, 'Links to ', extLink( r.target_url, r.target_title || r.target_url, { maxLength: 60 } ) ),
            ) },
        ];
        if ( state.linkStatus === 'pending' ) {
            columns.push( { key: 'actions', label: 'Actions', align: 'right', render: ( r ) => el( 'div', { class: 'ppclg-row ppclg-row--end' },
                button( 'Apply', { small: true, kind: 'primary', title: 'Adds this one link now (WordPress keeps a revision).', onClick: () => applyIds( [ r.id ] ) } ),
                button( 'Dismiss', { small: true, kind: 'ghost', title: 'Hide this suggestion; it will not be suggested again.', onClick: () => dismissIds( [ r.id ] ) } ),
            ) } );
        } else {
            columns.push( { key: 'status', label: 'Status', render: ( r ) => el( 'div', {},
                badge( labelize( r.status ), r.status === 'applied' ? 'success' : 'neutral' ),
                r.applied_at ? el( 'div', { class: 'ppclg-small ppclg-muted' }, timeEl( r.applied_at ) ) : null,
                r.note ? el( 'div', { class: 'ppclg-small ppclg-muted' }, truncate( String( r.note ), 160 ) ) : null,
            ) } );
            if ( state.linkStatus === 'stale' ) {
                columns.push( { key: 'actions', label: 'Actions', align: 'right', render: ( r ) => button( 'Dismiss', { small: true, kind: 'ghost', onClick: () => dismissIds( [ r.id ] ) } ) } );
            }
        }

        replace( linksHost, card( {
            title: 'Internal link suggestions',
            subtitle: `Places in your published content where a product or key page could be linked (at most ${ fmtNumber( data.max_links_per_post || 3 ) } per item). Links are added only when you click Apply.`,
            actions: [ refreshBtn, applyAllBtn ],
            body: el( 'div', { class: 'ppclg-stack' },
                data.enabled === false ? callout( 'info', 'Automatic suggestions are off', 'The weekly background check is turned off in Settings. “Find suggestions” still runs when you click it.' ) : null,
                progress.running ? callout( 'info', null, `Suggestions are being refreshed: ${ fmtNumber( progress.checked ) } of ${ fmtNumber( progress.total ) } items checked.` ) : null,
                el( 'div', { class: 'ppclg-small ppclg-muted' }, data.generated_at ? el( 'span', {}, 'Last refreshed ', timeEl( data.generated_at ) ) : 'Not refreshed yet.' ),
                statusRow,
                dataTable( {
                    caption: 'Internal link suggestions',
                    rows: items,
                    rowKey: ( r ) => r.id,
                    empty: state.linkStatus === 'pending'
                        ? { title: 'No suggestions waiting', message: 'Click “Find suggestions” to look for linkable words in your published content.', iconName: 'link', compact: true }
                        : { title: 'Nothing here', compact: true },
                    columns,
                } ),
            ),
        } ) );
    }

    await Promise.all( [ loadAudit(), loadIndexNow(), loadLinks() ] );
}
