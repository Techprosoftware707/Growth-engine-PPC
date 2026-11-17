/**
 * On-site SEO page: site audit issues, IndexNow submissions and internal-link suggestions.
 *
 * Nothing on this page changes site content except the internal-link "Apply" buttons: a row's Apply runs only on
 * that click, and "Apply all" only after a confirmation that says it edits published content and creates revisions.
 * With automatic links on (Autopilot or Settings → Automation → On-site SEO) the background job adds the best
 * suggestions itself; "Undo automatic links" (after a confirmation) removes every automatic link still present.
 *
 * 1.2.2: suggestions are listed a page at a time (GET /seo/links per_page / page); "Left for you" lists the ones
 * automation could not add by itself (with the reason), "Not shown on the page" the ones set aside because the
 * item's page does not display its content (or those words). Both can still be applied by hand (with a warning).
 *
 * 1.2.3: suggestions closed because their link text is the brand name ("Not suggested: the link text is your brand
 * name.") or their target is in another language ("Not suggested: the target page is in another language.") are
 * listed under "No longer valid" with that note, like the other closed reasons.
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
    { id: 'refused', label: 'Left for you' },
    { id: 'not_shown', label: 'Not shown on the page' },
    { id: 'applied', label: 'Applied' },
    { id: 'dismissed', label: 'Dismissed' },
    { id: 'stale', label: 'No longer valid' },
];

/** Suggestions per page of the list (GET /seo/links per_page). */
export const LINKS_PER_PAGE = 50;

/** Statuses whose rows can still be applied or dismissed. */
const OPEN_STATUSES = [ 'pending', 'refused', 'not_shown' ];

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

/**
 * The "Applied automatically" line of the internal-links card (GET /seo/links `auto`). Exported for tests.
 *
 * @param {object} auto { enabled, autopilot, per_day, today, present, undone, not_shown, left_for_you, removed }
 * @return {{show: boolean, title: string, text: string, present: number, extra: string}}
 */
export function autoLinksText( auto ) {
    const a = obj( auto );
    const present = Number( a.present ) || 0;
    const show = a.enabled === true || present > 0;
    if ( ! show ) {
        return { show: false, title: '', text: '', present: 0, extra: '' };
    }
    const title = `Applied automatically: ${ fmtNumber( present ) }`;
    const text = a.enabled === true
        ? `${ a.autopilot ? 'Autopilot adds' : 'The engine adds' } the best suggestions by itself, highest relevance first: up to ${ fmtNumber( Number( a.per_day ) || 0 ) } a day (${ fmtNumber( Number( a.today ) || 0 ) } today), at most the per-item limit, with a revision of each page kept, and only on pages that show the words to visitors. “Undo automatic links” removes every automatic link still on the site.`
        : 'Automatic links are switched off now; the ones added earlier stay until you undo them.';
    const parts = [];
    const left = Number( a.left_for_you ) || 0;
    const hidden = Number( a.not_shown ) || 0;
    const removed = Number( a.removed ) || 0;
    if ( left > 0 ) {
        parts.push( `${ plural( left, 'suggestion was', 'suggestions were' ) } left for you (see “Left for you” for the reason)` );
    }
    if ( hidden > 0 ) {
        parts.push( `${ plural( hidden, 'suggestion is', 'suggestions are' ) } not added because the page doesn’t display that text (see “Not shown on the page”)` );
    }
    if ( removed > 0 ) {
        parts.push( `${ plural( removed, 'automatic link was', 'automatic links were' ) } taken back out of pages that don’t display their content` );
    }
    const extra = parts.length > 0 ? `${ parts.join( '; ' ) }.` : '';
    return { show, title, text, present, extra: extra.charAt( 0 ).toUpperCase() + extra.slice( 1 ) };
}

/**
 * "Showing 51–100 of 529" for a page of GET /seo/links (older responses without paging fields: every row). Exported for tests.
 *
 * @param {object} data { total, page, per_page, pages, items }
 * @return {{from: number, to: number, total: number, page: number, pages: number, text: string}}
 */
export function linksPageInfo( data ) {
    const d = obj( data );
    const count = arr( d.items ).length;
    const total = Number.isFinite( Number( d.total ) ) && d.total !== undefined ? Number( d.total ) : count;
    const perPage = Number( d.per_page ) > 0 ? Number( d.per_page ) : Math.max( count, 1 );
    const page = Number( d.page ) > 0 ? Number( d.page ) : 1;
    const pages = Number( d.pages ) > 0 ? Number( d.pages ) : Math.max( 1, Math.ceil( total / perPage ) );
    const from = count > 0 ? ( page - 1 ) * perPage + 1 : 0;
    const to = count > 0 ? from + count - 1 : 0;
    return { from, to, total, page, pages, text: total > 0 ? `Showing ${ fmtNumber( from ) }–${ fmtNumber( to ) } of ${ fmtNumber( total ) }` : '' };
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
        linkPage: 1,
    };

    const auditHost = el( 'div', { class: 'ppclg-section' } );
    const indexHost = el( 'div', { class: 'ppclg-section' } );
    const linksHost = el( 'div', { class: 'ppclg-section' } );

    replace( root,
        pageHeader( {
            title: 'On-site SEO',
            description: 'Checks your published pages, posts and products for search problems, tells IndexNow-enabled search engines about new and changed pages, and suggests internal links between your own pages. The audit never edits content; a link is added when you click Apply, or by itself when automatic links are on (Autopilot).',
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
            data = obj( await api.get( '/seo/links', { status: state.linkStatus, per_page: LINKS_PER_PAGE, page: state.linkPage }, { silent: true } ) );
        } catch ( e ) {
            replace( linksHost, card( { title: 'Internal link suggestions', body: errorState( { title: 'Suggestions could not be loaded', message: reason( e, '' ), retry: loadLinks } ) } ) );
            return;
        }
        // The page asked for no longer exists (rows were applied or dismissed): show the last one.
        if ( state.linkPage > 1 && arr( data.items ).length === 0 && Number( data.pages ) > 0 && Number( data.pages ) < state.linkPage ) {
            state.linkPage = Number( data.pages );
            return loadLinks();
        }
        renderLinks( data );
    }

    /** Applies suggestions (explicit admin click) and reports every refusal. */
    async function applyIds( ids ) {
        let applied = 0;
        const refused = [];
        const warnings = [];
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
                } else if ( r.warning ) {
                    warnings.push( String( r.warning ) );
                }
            }
            if ( res.ok === false && arr( res.results ).length === 0 ) {
                refused.push( reason( res, 'Nothing was applied.' ) );
            }
        }
        if ( refused.length === 0 && applied > 0 && warnings.length > 0 ) {
            toast( `${ plural( applied, 'link was', 'links were' ) } added. ${ truncate( warnings[ 0 ], 220 ) }`, 'warning', { timeout: 10000 } );
        } else if ( refused.length === 0 && applied > 0 ) {
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

    async function undoAuto( count ) {
        const ok = await confirm(
            `This removes ${ plural( count, 'automatic internal link', 'automatic internal links' ) } from your live site. An item not edited since gets back exactly the content it had before; in an item edited since, only the inserted link is removed and the words stay. WordPress keeps a revision of each edited item.`,
            { title: 'Undo automatic links?', confirmText: 'Undo automatic links', danger: true },
        );
        if ( ! ok ) {
            return;
        }
        let res;
        try {
            res = obj( await api.post( '/seo/links/undo-auto', { confirm: true }, { silent: true } ) );
        } catch ( e ) {
            toast( reason( e, 'The automatic links could not be removed.' ), 'error' );
            return;
        }
        toast( reason( res, 'Done.' ), res.ok === false ? 'warning' : 'success', { timeout: 9000 } );
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
        const open = OPEN_STATUSES.includes( state.linkStatus );
        const pendingItems = open ? items : [];
        const pageInfo = linksPageInfo( data );
        const autoOn = obj( data.auto ).enabled === true;

        const refreshBtn = button( 'Find suggestions', {
            small: true, iconName: 'refresh',
            title: 'Reads published content and lists places where a product or key page could be linked. Nothing is edited.',
            onClick: () => refreshSuggestions(),
        } );
        const onePage = pageInfo.pages <= 1;
        const applyAllBtn = button( onePage ? 'Apply all' : `Apply these ${ fmtNumber( pendingItems.length ) }`, {
            small: true, kind: 'primary', iconName: 'link',
            disabled: pendingItems.length === 0,
            title: pendingItems.length === 0 ? 'No suggestions are listed here.' : ( onePage ? 'Adds every suggested link listed below after you confirm.' : 'Adds the suggested links on this page of the list after you confirm.' ),
            onClick: async () => {
                const sources = new Set( pendingItems.map( ( i ) => i.source_post_id ) ).size;
                const hiddenNote = state.linkStatus === 'not_shown' ? ' These pages don’t display this text to visitors, so the links will not be seen there.' : '';
                const ok = await confirm(
                    `This edits ${ plural( sources, 'published item', 'published items' ) } on your live site: ${ plural( pendingItems.length, 'link is', 'links are' ) } added around the existing words shown below. Nothing else in the content changes, and WordPress saves a revision of each item so you can restore the previous version from the editor.${ hiddenNote }`,
                    { title: onePage ? 'Apply all suggested links?' : 'Apply the suggested links on this page?', confirmText: `Apply ${ plural( pendingItems.length, 'link', 'links' ) }`, danger: true },
                );
                if ( ok ) {
                    await applyIds( pendingItems.map( ( i ) => i.id ) );
                }
            },
        } );

        const statusRow = el( 'div', { class: 'ppclg-row', role: 'group', 'aria-label': 'Show suggestions by status' },
            LINK_STATUSES.filter( ( s ) => ! [ 'refused', 'not_shown' ].includes( s.id ) || Number( counts[ s.id ] ) > 0 || state.linkStatus === s.id ).map( ( s ) => button( `${ s.id === 'pending' && autoOn ? 'Waiting' : s.label } (${ fmtNumber( counts[ s.id ] || 0 ) })`, {
                small: true,
                kind: state.linkStatus === s.id ? 'primary' : 'ghost',
                attrs: { 'aria-pressed': state.linkStatus === s.id ? 'true' : 'false' },
                onClick: () => {
                    state.linkStatus = s.id;
                    state.linkPage = 1;
                    keepHash();
                    return loadLinks();
                },
            } ) ),
        );

        const goTo = ( page ) => {
            state.linkPage = page;
            return loadLinks();
        };
        const pager = pageInfo.pages > 1 || pageInfo.page > 1
            ? el( 'div', { class: 'ppclg-row ppclg-links-pager', role: 'group', 'aria-label': 'Pages of suggestions' },
                el( 'span', { class: 'ppclg-small ppclg-muted', role: 'status' }, `${ pageInfo.text } (page ${ fmtNumber( pageInfo.page ) } of ${ fmtNumber( pageInfo.pages ) })` ),
                button( 'Previous', { small: true, kind: 'ghost', disabled: pageInfo.page <= 1, onClick: () => goTo( pageInfo.page - 1 ) } ),
                button( 'Next', { small: true, kind: 'ghost', disabled: pageInfo.page >= pageInfo.pages, onClick: () => goTo( pageInfo.page + 1 ) } ),
            )
            : ( pageInfo.total > 0 ? el( 'div', { class: 'ppclg-small ppclg-muted' }, pageInfo.text ) : null );

        /** Why a row waits, was left for a person, or is not shown (open rows). */
        const rowState = ( r ) => {
            if ( r.status === 'not_shown' ) {
                return el( 'div', { class: 'ppclg-small' }, badge( r.removed_at ? 'Removed: not shown' : 'Not shown on the page', 'warning' ), ' ', el( 'span', { class: 'ppclg-muted' }, truncate( String( r.note || '' ), 220 ) ) );
            }
            if ( r.auto_skip ) {
                return el( 'div', { class: 'ppclg-small' }, badge( 'Left for you', 'warning' ), ' ', el( 'span', { class: 'ppclg-muted' }, truncate( String( r.auto_skip ), 220 ) ) );
            }
            if ( r.render_note ) {
                return el( 'div', { class: 'ppclg-small ppclg-muted' }, truncate( String( r.render_note ), 220 ) );
            }
            return null;
        };

        const columns = [
            { key: 'source', label: 'In this item', render: ( r ) => el( 'div', {},
                el( 'div', {}, String( r.source_title || `#${ r.source_post_id }` ) ),
                el( 'div', { class: 'ppclg-small ppclg-row' }, r.source_url ? extLink( r.source_url, 'View', {} ) : null, r.edit_url ? extLink( r.edit_url, 'Edit', {} ) : null ),
            ) },
            { key: 'excerpt', label: 'Words to link', render: ( r ) => el( 'div', {},
                el( 'div', { class: 'ppclg-small' }, highlight( r.excerpt, r.anchor ) ),
                el( 'div', { class: 'ppclg-small ppclg-muted' }, 'Links to ', extLink( r.target_url, r.target_title || r.target_url, { maxLength: 60 } ) ),
                open ? rowState( r ) : null,
            ) },
        ];
        if ( open ) {
            columns.push( { key: 'actions', label: 'Actions', align: 'right', render: ( r ) => el( 'div', { class: 'ppclg-row ppclg-row--end' },
                button( 'Apply', { small: true, kind: 'primary', title: r.status === 'not_shown' ? 'Adds this link now, although visitors may not see it on this page (WordPress keeps a revision).' : 'Adds this one link now (WordPress keeps a revision).', onClick: () => applyIds( [ r.id ] ) } ),
                button( 'Dismiss', { small: true, kind: 'ghost', title: 'Hide this suggestion; it will not be suggested again.', onClick: () => dismissIds( [ r.id ] ) } ),
            ) } );
        } else {
            columns.push( { key: 'status', label: 'Status', render: ( r ) => el( 'div', {},
                badge( labelize( r.status ), r.status === 'applied' ? 'success' : 'neutral' ),
                r.auto ? [ ' ', badge( 'Automatic', 'info' ) ] : null,
                r.applied_at ? el( 'div', { class: 'ppclg-small ppclg-muted' }, timeEl( r.applied_at ) ) : null,
                r.note ? el( 'div', { class: 'ppclg-small ppclg-muted' }, truncate( String( r.note ), 160 ) ) : null,
            ) } );
            if ( state.linkStatus === 'stale' ) {
                columns.push( { key: 'actions', label: 'Actions', align: 'right', render: ( r ) => button( 'Dismiss', { small: true, kind: 'ghost', onClick: () => dismissIds( [ r.id ] ) } ) } );
            }
        }

        const auto = autoLinksText( data.auto );
        const autoNode = auto.show ? callout( 'info', auto.title, auto.extra ? `${ auto.text } ${ auto.extra }` : auto.text, {
            actions: button( 'Undo automatic links', {
                small: true, iconName: 'refresh',
                disabled: auto.present === 0,
                title: auto.present === 0 ? 'No automatic link is on the site.' : 'Removes every automatic link still present (asks first).',
                onClick: () => undoAuto( auto.present ),
            } ),
        } ) : null;
        replace( linksHost, card( {
            title: 'Internal link suggestions',
            subtitle: `Places in your published content where a product or key page could be linked (at most ${ fmtNumber( data.max_links_per_post || 3 ) } per item). ${ obj( data.auto ).enabled === true ? 'The best ones are added automatically each day.' : 'Links are added only when you click Apply.' }`,
            actions: [ refreshBtn, applyAllBtn ],
            body: el( 'div', { class: 'ppclg-stack ppclg-links-body' },
                autoNode,
                data.enabled === false ? callout( 'info', 'Automatic suggestions are off', 'The weekly background check is turned off in Settings. “Find suggestions” still runs when you click it.' ) : null,
                progress.running ? callout( 'info', null, `Suggestions are being refreshed: ${ fmtNumber( progress.checked ) } of ${ fmtNumber( progress.total ) } items checked.` ) : null,
                el( 'div', { class: 'ppclg-small ppclg-muted' }, data.generated_at ? el( 'span', {}, 'Last refreshed ', timeEl( data.generated_at ) ) : 'Not refreshed yet.' ),
                statusRow,
                state.linkStatus === 'not_shown' ? callout( 'info', null, 'These suggestions are on pages whose template doesn’t display the text (for example a hard-coded home page, the posts page or the shop), or the words are not on the page visitors see. Automatic links skip them; they are checked again when the page or the theme changes. You can still apply one by hand.' ) : null,
                state.linkStatus === 'refused' ? callout( 'info', null, 'Automation could not add these by itself; each row says why. Apply them by hand from an administrator account, or dismiss them.' ) : null,
                dataTable( {
                    caption: 'Internal link suggestions',
                    rows: items,
                    rowKey: ( r ) => r.id,
                    empty: state.linkStatus === 'pending'
                        ? { title: 'No suggestions waiting', message: 'Click “Find suggestions” to look for linkable words in your published content.', iconName: 'link', compact: true }
                        : { title: 'Nothing here', compact: true },
                    columns,
                } ),
                pager,
            ),
        } ) );
    }

    await Promise.all( [ loadAudit(), loadIndexNow(), loadLinks() ] );
}
