/**
 * Backlinks page: summary tiles and charts, filterable table, detail drawer
 * with change history and "check now", add-backlink modal and provider import.
 */

import * as api from '../api.js';
import {
    el, replace, badge, statusBadge, button, callout, kv, pre, loading, errorState, emptyState, timeEl, fmtNumber, fmtDate, labelize, truncate,
    pageHeader, drawer, dataTable, formModal, confirm, toast, extLink, listView, card, statTile, isObject, copyButton,
} from '../ui.js';
import { barChart, lineChart, segmentedBar } from '../charts.js';
import { reportRun } from './outcome.js';

const STATUSES = [ 'pending', 'active', 'lost', 'broken', 'redirected', 'unverified' ];
const RELS = [ 'dofollow', 'nofollow', 'sponsored', 'ugc' ];
const ORIGINS = { monitor: 'Monitor', submission: 'Submission', outreach: 'Outreach', 'provider:seranking': 'SE Ranking', 'provider:ahrefs': 'Ahrefs', 'provider:semrush': 'Semrush', ga4_referral: 'GA4 referral', mention_scan: 'Mention scan', manual: 'Manual' };

const obj = ( v ) => ( isObject( v ) ? v : {} );
const arr = ( v ) => ( Array.isArray( v ) ? v : [] );

function relBadges( rel ) {
    const parts = String( rel || 'dofollow' ).split( ',' ).map( ( r ) => r.trim() ).filter( Boolean );
    return el( 'span', { class: 'ppclg-row', style: { gap: '4px' } }, ( parts.length ? parts : [ 'dofollow' ] ).map( ( r ) => statusBadge( r, { dot: false } ) ) );
}

function countsToList( value, keyName ) {
    if ( Array.isArray( value ) ) {
        return value.map( ( r ) => ( { label: String( r[ keyName ] ?? r.label ?? '' ), value: Number( r.count ?? r.value ) || 0 } ) );
    }
    if ( isObject( value ) ) {
        return Object.entries( value ).map( ( [ k, v ] ) => ( { label: k, value: Number( v ) || 0 } ) );
    }
    return [];
}

export default async function render( root, ctx ) {
    const params = ctx.params || {};
    const summaryHost = el( 'div', { class: 'ppclg-section' }, loading( 'Loading summary…', true ) );

    const list = listView( {
        fetch: ( p ) => api.get( '/backlinks', p ),
        defaultSort: { key: 'id', dir: 'desc' },
        initial: { filters: { status: params.status || '', rel: params.rel || '', origin: params.origin || '', q: params.q || '' } },
        onRowClick: ( row ) => openBacklink( Number( row.id ) ),
        rowKey: ( row ) => row.id,
        empty: { title: 'No backlinks recorded', message: 'Import from your SEO providers or add a known link manually.', iconName: 'link' },
        filters: [
            { name: 'status', label: 'Status', type: 'select', options: STATUSES.map( ( s ) => ( { value: s, label: labelize( s ) } ) ), allLabel: 'All statuses' },
            { name: 'rel', label: 'Rel', type: 'select', options: RELS.map( ( r ) => ( { value: r, label: r } ) ), allLabel: 'Any rel' },
            { name: 'origin', label: 'Origin', type: 'select', options: ORIGINS, allLabel: 'Any origin' },
            { name: 'q', label: 'Search', type: 'search', placeholder: 'Source domain or anchor…' },
        ],
        columns: [
            { key: 'source_domain', label: 'Source', sortable: true, render: ( b ) => el( 'span', {}, el( 'span', { class: 'ppclg-cell-title' }, b.source_domain || '' ), extLink( b.source_url, truncate( String( b.source_url || '' ).replace( /^https?:\/\//, '' ), 52 ), { className: 'ppclg-cell-sub' } ) ) },
            { key: 'anchor_text', label: 'Anchor', render: ( b ) => ( b.anchor_text ? el( 'span', { title: b.anchor_text }, truncate( b.anchor_text, 36 ) ) : el( 'span', { class: 'ppclg-muted' }, '(empty / image)' ) ) },
            { key: 'rel', label: 'Rel', render: ( b ) => relBadges( b.rel ) },
            { key: 'placement', label: 'Placement', render: ( b ) => ( b.placement ? labelize( b.placement ) : null ) },
            { key: 'status', label: 'Status', sortable: true, render: ( b ) => statusBadge( b.status ) },
            { key: 'http_status', label: 'HTTP', align: 'center', render: ( b ) => ( b.http_status ? String( b.http_status ) : null ) },
            { key: 'quality_score', label: 'Quality', sortable: true, align: 'right', render: ( b ) => ( b.quality_score === null || b.quality_score === undefined ? null : fmtNumber( b.quality_score ) ) },
            { key: 'origin', label: 'Origin', render: ( b ) => ( b.origin ? badge( ORIGINS[ b.origin ] || labelize( String( b.origin ).replace( 'provider:', '' ) ), 'neutral' ) : null ) },
            { key: 'first_seen', label: 'First seen', sortable: true, render: ( b ) => ( b.first_seen ? timeEl( b.first_seen ) : null ) },
            { key: 'last_checked', label: 'Checked', sortable: true, render: ( b ) => ( b.last_checked ? timeEl( b.last_checked ) : el( 'span', { class: 'ppclg-muted' }, 'Never' ) ) },
            { key: 'next_check_at', label: 'Next check', sortable: true, render: ( b ) => ( b.next_check_at ? timeEl( b.next_check_at ) : null ) },
        ],
        toolbar: [
            button( 'Import from providers', { iconName: 'download', onClick: async () => {
                if ( ! ( await confirm( 'Import backlinks from every configured provider (SE Ranking, Ahrefs, Semrush) and GA4 referral sources now? This uses provider API credits.', { confirmText: 'Import' } ) ) ) {
                    return;
                }
                const res = await api.post( '/backlinks/import', {} );
                // Queued, skipped and failed imports, and the job's errors[] (provider limit reached, provider or
                // analytics import failed), are reported as such instead of as a successful import.
                const outcome = reportRun( res, 'Import', { summary: ( r ) => {
                    const prov = obj( r.providers );
                    const ga = obj( r.analytics );
                    return [ prov.records !== undefined ? `${ fmtNumber( prov.records ) } provider records (${ fmtNumber( prov.new ) } new)` : '', ga.sources !== undefined ? `${ fmtNumber( ga.sources ) } referral sources (${ fmtNumber( ga.new ) } new)` : '', r.records !== undefined && prov.records === undefined ? `${ fmtNumber( r.records ) } records (${ fmtNumber( r.new ) } new)` : '' ].filter( Boolean ).join( ', ' );
                } } );
                if ( outcome.state === 'finished' ) {
                    const r = outcome.result;
                    const warning = obj( r.providers ).warning || obj( r.analytics ).warning || r.warning;
                    if ( warning ) {
                        toast( String( warning ), 'warning', { timeout: 8000 } );
                    }
                }
                list.reload();
                loadSummary();
            } } ),
            button( 'Add backlink', { kind: 'primary', iconName: 'plus', onClick: () => formModal( {
                title: 'Add a known backlink',
                description: 'The link is verified on the next monitoring pass (or immediately with “Check now”).',
                submitText: 'Add backlink',
                fields: [
                    { type: 'url', name: 'source_url', label: 'Page that links to us', placeholder: 'https://example.ca/resources/', required: true },
                    { type: 'url', name: 'target_url', label: 'Our page being linked', value: ctx.config.homeUrl || '', required: true },
                ],
                onSubmit: async ( values ) => {
                    const res = await api.post( '/backlinks', values );
                    toast( 'Backlink added and scheduled for verification.', 'success' );
                    list.reload();
                    loadSummary();
                    const id = res && ( res.id || res.backlink_id || obj( res.backlink ).id );
                    if ( id ) {
                        openBacklink( Number( id ) );
                    }
                    return true;
                },
            } ) } ),
        ],
        onLoaded: ( { state } ) => ctx.setHash( { ...state.filters } ),
    } );

    replace( root,
        pageHeader( { title: 'Backlinks', description: 'Every link pointing at the site, where it came from, whether it is still live and how it changed over time.' } ),
        summaryHost,
        list.el,
    );
    list.reload();
    loadSummary();

    if ( params.open ) {
        openBacklink( Number( params.open ) );
    }

    // ------------------------------------------------------------------
    async function loadSummary() {
        let s;
        try {
            s = await api.get( '/backlinks/summary', {}, { silent: true } );
        } catch ( e ) {
            replace( summaryHost, callout( 'warning', null, 'The backlink summary could not be loaded.' ) );
            return;
        }
        s = obj( s );
        const statusCounts = countsToList( s.status_counts || s.statuses, 'status' );
        const count = ( name ) => ( statusCounts.find( ( r ) => r.label === name ) || { value: 0 } ).value;
        const rel = countsToList( s.rel_distribution || s.rel, 'rel' ).map( ( r ) => ( { label: labelize( r.label || 'unknown' ), value: r.value } ) );
        const anchors = countsToList( s.anchor_distribution || s.anchors, 'anchor' ).map( ( r ) => ( { label: r.label === '' ? '(empty / image)' : truncate( r.label, 40 ), value: r.value } ) );
        const trend = arr( s.weekly_trend || s.trend );
        const referring = s.referring_domains !== undefined ? s.referring_domains : ( isObject( s.domains ) ? s.domains.referring : null );
        const highAuth = s.high_authority_domains !== undefined ? s.high_authority_domains : ( isObject( s.domains ) ? s.domains.high_authority : null );

        replace( summaryHost,
            el( 'div', { class: 'ppclg-grid ppclg-grid--tiles', style: { marginBottom: '16px' } },
                statTile( { label: 'Active', iconName: 'check', value: fmtNumber( count( 'active' ) ), hint: `${ fmtNumber( count( 'pending' ) ) } pending verification`, onClick: () => list.setFilters( { status: 'active' } ) } ),
                statTile( { label: 'Lost or broken', iconName: 'alert', value: fmtNumber( count( 'lost' ) + count( 'broken' ) ), hint: `${ fmtNumber( count( 'redirected' ) ) } redirected · ${ fmtNumber( count( 'unverified' ) ) } unverified`, tone: count( 'lost' ) + count( 'broken' ) > 0 ? 'critical' : null, onClick: () => list.setFilters( { status: 'lost' } ) } ),
                statTile( { label: 'Referring domains', iconName: 'globe', value: fmtNumber( referring ), hint: 'Unique source domains with a live link' } ),
                statTile( { label: 'High-authority domains', iconName: 'shield', value: fmtNumber( highAuth ), hint: 'Authority ≥ 30 on a configured provider' } ),
            ),
            el( 'div', { class: 'ppclg-grid ppclg-grid--3' },
                card( { body: lineChart( { title: 'New vs. lost per week', series: [ { name: 'New', points: trend.map( ( r ) => ( { x: r.week, y: Number( r.new ) || 0 } ) ) }, { name: 'Lost', points: trend.map( ( r ) => ( { x: r.week, y: Number( r.lost ) || 0 } ) ) } ], height: 200, emptyMessage: 'No weekly history yet.' } ) } ),
                card( { body: segmentedBar( { title: 'Link attributes', subtitle: 'Share of verified links', parts: rel, emptyMessage: 'No verified links yet.' } ) } ),
                card( { body: barChart( { title: 'Top anchor texts', data: anchors, maxBars: 8, emptyMessage: 'Anchors appear after verification.' } ) } ),
            ),
        );
    }

    // ------------------------------------------------------------------
    async function openBacklink( id ) {
        if ( ! id ) {
            return;
        }
        const body = el( 'div', {}, loading( 'Loading backlink…', true ) );
        const dr = drawer( `Backlink #${ id }`, body, {} );

        async function load() {
            let data;
            try {
                data = await api.get( `/backlinks/${ id }` );
            } catch ( e ) {
                dr.setContent( errorState( { title: 'Could not load this backlink', message: e && e.message ? e.message : '', retry: load } ) );
                return;
            }
            const b = obj( data.backlink ).id ? obj( data.backlink ) : obj( data );
            if ( ! b.id ) {
                dr.setContent( errorState( { title: 'Backlink not found' } ) );
                return;
            }
            const refresh = async () => {
                list.reload();
                loadSummary();
                await load();
            };
            dr.setTitle( b.source_domain || truncate( b.source_url, 40 ) );
            dr.setSubtitle( [ statusBadge( b.status ), relBadges( b.rel ), b.placement ? badge( labelize( b.placement ), 'neutral' ) : null, b.origin ? badge( ORIGINS[ b.origin ] || labelize( b.origin ), 'neutral' ) : null ] );

            const changes = arr( b.changes ).slice().reverse();
            const stack = el( 'div', { class: 'ppclg-stack' },
                b.status === 'lost' || b.status === 'broken' ? callout( 'danger', `Link ${ b.status }${ b.lost_at ? ` since ${ fmtDate( b.lost_at ) }` : '' }`, `${ fmtNumber( b.consecutive_failures ) } consecutive failed checks. Lost links are re-checked weekly for 60 days.` ) : null,
                b.status === 'unverified' ? callout( 'info', 'Unverified', 'The source page could not be checked (robots.txt disallows crawling or the page was unreachable). The link may still exist.' ) : null,
                card( { title: 'Details', actions: copyButton( () => `${ b.source_url } -> ${ b.target_url }`, 'Copy URLs' ), body: kv( [
                    [ 'Source page', extLink( b.source_url ) ],
                    [ 'Links to', extLink( b.target_url ) ],
                    [ 'Anchor text', b.anchor_text || el( 'span', { class: 'ppclg-muted' }, '(empty / image)' ) ],
                    [ 'Rel', b.rel || 'dofollow' ],
                    [ 'Placement', b.placement ? labelize( b.placement ) : null ],
                    [ 'Indexable', b.is_indexable === null || b.is_indexable === undefined ? null : ( Number( b.is_indexable ) ? 'Yes' : 'No' ) ],
                    [ 'HTTP status', b.http_status || null ],
                    [ 'Quality score', b.quality_score === null || b.quality_score === undefined ? null : `${ fmtNumber( b.quality_score ) } / 100` ],
                    [ 'Prospect', b.prospect_id ? el( 'a', { href: ctx.pageUrl( 'prospects', { open: b.prospect_id } ) }, `Prospect #${ b.prospect_id }` ) : null ],
                    [ 'First seen', b.first_seen ? fmtDate( b.first_seen ) : null ],
                    [ 'Last checked', b.last_checked ? fmtDate( b.last_checked ) : 'Never' ],
                    [ 'Last seen live', b.last_seen_live ? fmtDate( b.last_seen_live ) : null ],
                    [ 'Next check', b.next_check_at ? fmtDate( b.next_check_at ) : null ],
                    [ 'Consecutive failures', fmtNumber( b.consecutive_failures ) ],
                ], { compact: true } ) } ),
                card( { title: 'Change history', subtitle: changes.length ? `${ changes.length } recorded change(s)` : 'No changes recorded', body: changes.length ? el( 'ul', { class: 'ppclg-list ppclg-change-list' }, changes.map( ( c ) => el( 'li', {},
                    el( 'span', { class: 'ppclg-muted' }, fmtDate( c.at ), el( 'br' ), labelize( c.field || 'change' ) ),
                    el( 'span', {}, el( 'span', { class: 'ppclg-change-from' }, c.from === null || c.from === undefined || c.from === '' ? '(empty)' : String( c.from ) ), ' → ', el( 'span', { class: 'ppclg-change-to' }, c.to === null || c.to === undefined || c.to === '' ? '(empty)' : String( c.to ) ) ),
                ) ) ) : emptyState( { title: 'Nothing has changed yet', compact: true } ) } ),
            );

            dr.setActions( [
                button( 'Delete', { kind: 'danger', iconName: 'trash', onClick: async () => {
                    if ( ! ( await confirm( 'Delete this backlink record? It may be re-imported from providers later.', { danger: true, confirmText: 'Delete' } ) ) ) {
                        return;
                    }
                    await api.del( `/backlinks/${ b.id }` );
                    toast( 'Backlink deleted.', 'success' );
                    dr.close();
                    list.reload();
                    loadSummary();
                } } ),
                button( 'Check now', { kind: 'primary', iconName: 'refresh', onClick: async () => {
                    const res = await api.post( `/backlinks/${ b.id }/check`, {} );
                    const r = obj( res && res.result ? res.result : res );
                    const bits = [ r.status ? `status ${ labelize( r.status ) }` : '', r.lost ? 'link lost' : '', r.recovered ? 'link recovered' : '', r.changed ? 'changes recorded' : '', r.note ? r.note : '' ].filter( Boolean );
                    toast( bits.length ? `Check finished: ${ bits.join( ', ' ) }.` : 'Check finished.', r.lost ? 'warning' : 'success', { timeout: 7000 } );
                    await refresh();
                } } ),
            ] );
            dr.setContent( stack );
        }
        await load();
    }
}
