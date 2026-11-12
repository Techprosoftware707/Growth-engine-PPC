/**
 * Logs page: application logs, audit trail and the suppression list.
 */

import * as api from '../api.js';
import {
    el, replace, badge, statusBadge, button, callout, pre, timeEl, fmtDate, labelize, truncate, pageHeader, tabs, formModal, formField, confirm, toast, listView, isObject,
} from '../ui.js';

const LEVELS = [ 'debug', 'info', 'warning', 'error' ];
const SUPPRESSION_TYPES = { domain: 'Domain', email: 'Email address', email_domain: 'Email domain' };

const obj = ( v ) => ( isObject( v ) ? v : {} );

function contextBlock( value ) {
    if ( value === null || value === undefined || value === '' ) {
        return el( 'span', { class: 'ppclg-muted' }, 'No context recorded.' );
    }
    if ( typeof value === 'string' ) {
        try {
            return pre( JSON.parse( value ) );
        } catch ( e ) {
            return pre( value );
        }
    }
    return pre( value );
}

export default async function render( root, ctx ) {
    const params = ctx.params || {};
    let logsList = null;
    let autoTimer = null;

    const view = tabs( {
        pills: true,
        active: [ 'logs', 'audit', 'suppression' ].includes( params.tab ) ? params.tab : 'logs',
        onChange: ( id ) => ctx.setHash( { tab: id } ),
        items: [
            { id: 'logs', label: 'Logs', iconName: 'list', render: ( panel ) => logsTab( panel ) },
            { id: 'audit', label: 'Audit log', iconName: 'shield', render: ( panel ) => auditTab( panel ) },
            { id: 'suppression', label: 'Suppression list', iconName: 'stop', render: ( panel ) => suppressionTab( panel ) },
        ],
    } );

    replace( root,
        pageHeader( { title: 'Logs', description: 'Application logs (secrets and contact emails are redacted), the audit trail of every decision and action, and the do-not-contact list.' } ),
        view.el,
    );

    // ------------------------------------------------------------------
    function logsTab( panel ) {
        const autoField = formField( { type: 'checkbox', label: 'Auto-refresh every 15s', value: false, onChange: ( v ) => {
            clearTimeout( autoTimer );
            if ( v ) {
                const tick = () => {
                    autoTimer = setTimeout( async () => {
                        if ( root.isConnected && logsList && document.visibilityState === 'visible' ) {
                            await logsList.reload();
                            tick();
                        }
                    }, 15000 );
                };
                tick();
            }
        } } );
        logsList = listView( {
            fetch: ( p ) => api.get( '/logs', p ),
            defaultSort: { key: 'id', dir: 'desc' },
            initial: { filters: { level: params.level || '', channel: params.channel || '', q: params.q || '' } },
            rowKey: ( r ) => r.id,
            empty: { title: 'No log entries', message: 'Nothing matches the current filters. Raise the log level under Settings → Advanced for more detail.', iconName: 'list' },
            filters: [
                { name: 'level', label: 'Level', type: 'select', options: LEVELS.map( ( l ) => ( { value: l, label: labelize( l ) } ) ), allLabel: 'All levels' },
                { name: 'channel', label: 'Channel', type: 'text', placeholder: 'e.g. discovery, http, rest', width: '180px' },
                { name: 'q', label: 'Search', type: 'search', placeholder: 'Message text…' },
            ],
            toolbar: [ autoField.el, button( 'Refresh', { small: true, iconName: 'refresh', onClick: () => logsList.reload() } ) ],
            expandable: ( r ) => el( 'div', {}, el( 'div', { class: 'ppclg-field-label', style: { marginBottom: '6px' } }, 'Context' ), contextBlock( r.context ) ),
            columns: [
                { key: 'created_at', label: 'Time', sortable: true, render: ( r ) => el( 'span', { title: fmtDate( r.created_at ) }, timeEl( r.created_at ) ) },
                { key: 'level', label: 'Level', sortable: true, render: ( r ) => statusBadge( r.level, { dot: true } ) },
                { key: 'channel', label: 'Channel', sortable: true, render: ( r ) => el( 'span', { class: 'ppclg-mono' }, r.channel || 'general' ) },
                { key: 'message', label: 'Message', render: ( r ) => el( 'span', { title: r.message || '' }, truncate( r.message || '', 160 ) ) },
            ],
            onLoaded: ( { state } ) => ctx.setHash( { tab: 'logs', ...state.filters } ),
        } );
        replace( panel, logsList.el );
        logsList.reload();
    }

    // ------------------------------------------------------------------
    function auditTab( panel ) {
        const auditList = listView( {
            fetch: ( p ) => api.get( '/audit', p ),
            defaultSort: { key: 'id', dir: 'desc' },
            // Outreach messages are audited as 'outreach_message'; an old #object_type=message link maps onto it.
            initial: { filters: { action: params.action || '', object_type: params.object_type === 'message' ? 'outreach_message' : ( params.object_type || '' ), q: params.q || '' } },
            rowKey: ( r ) => r.id,
            empty: { title: 'No audit entries', message: 'Every approval, status change, submission and send is recorded here.', iconName: 'shield' },
            filters: [
                { name: 'action', label: 'Action', type: 'text', placeholder: 'e.g. prospect.evaluated', width: '200px' },
                { name: 'object_type', label: 'Object type', type: 'select', options: { prospect: 'Prospect', opportunity: 'Opportunity', submission: 'Submission', campaign: 'Campaign', outreach_message: 'Outreach message', contact: 'Contact', backlink: 'Backlink', settings: 'Settings', job: 'Job', suppression: 'Suppression' }, allLabel: 'All objects' },
                { name: 'q', label: 'Search', type: 'search', placeholder: 'Details…' },
            ],
            expandable: ( r ) => el( 'div', {}, el( 'div', { class: 'ppclg-field-label', style: { marginBottom: '6px' } }, 'Details' ), contextBlock( r.details ) ),
            columns: [
                { key: 'created_at', label: 'Time', sortable: true, render: ( r ) => el( 'span', { title: fmtDate( r.created_at ) }, timeEl( r.created_at ) ) },
                { key: 'actor', label: 'Actor', sortable: true, render: ( r ) => badge( r.actor || 'system', r.actor && r.actor !== 'system' ? 'brand' : 'neutral' ) },
                { key: 'action', label: 'Action', sortable: true, render: ( r ) => el( 'span', { class: 'ppclg-mono' }, r.action || '' ) },
                { key: 'object', label: 'Object', render: ( r ) => ( r.object_type ? el( 'span', {}, labelize( r.object_type ), Number( r.object_id ) > 0 ? ` #${ r.object_id }` : '' ) : null ) },
                { key: 'summary', label: 'Summary', render: ( r ) => {
                    const d = obj( r.details );
                    const bits = Object.entries( d ).filter( ( [ , v ] ) => typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean' ).slice( 0, 3 ).map( ( [ k, v ] ) => `${ k }: ${ truncate( String( v ), 40 ) }` );
                    return bits.length ? el( 'span', { class: 'ppclg-small ppclg-muted' }, bits.join( ' · ' ) ) : null;
                } },
            ],
            onLoaded: ( { state } ) => ctx.setHash( { tab: 'audit', ...state.filters } ),
        } );
        replace( panel, auditList.el );
        auditList.reload();
    }

    // ------------------------------------------------------------------
    function suppressionTab( panel ) {
        const list = listView( {
            fetch: ( p ) => api.get( '/suppression', p ),
            defaultSort: { key: 'id', dir: 'desc' },
            initial: { filters: { type: '', q: '' } },
            rowKey: ( r ) => r.id,
            empty: { title: 'Suppression list is empty', message: 'Domains and addresses listed here are never contacted or re-discovered.', iconName: 'stop' },
            filters: [
                { name: 'type', label: 'Type', type: 'select', options: SUPPRESSION_TYPES, allLabel: 'All types' },
                { name: 'q', label: 'Search', type: 'search', placeholder: 'Value…' },
            ],
            toolbar: button( 'Add entry', { kind: 'primary', small: true, iconName: 'plus', onClick: () => formModal( {
                title: 'Add to the suppression list',
                description: 'Use this for unsubscribe requests received outside the system, competitors, or domains you never want to pursue.',
                submitText: 'Add',
                fields: [
                    { type: 'select', name: 'type', label: 'Type', options: SUPPRESSION_TYPES, value: 'domain' },
                    { type: 'text', name: 'value', label: 'Value', placeholder: 'example.com or person@example.com', required: true },
                    { type: 'text', name: 'reason', label: 'Reason', placeholder: 'Requested by phone on …' },
                ],
                onSubmit: async ( values ) => {
                    await api.post( '/suppression', values );
                    toast( 'Suppression entry added.', 'success' );
                    list.reload();
                    return true;
                },
            } ) } ),
            columns: [
                { key: 'type', label: 'Type', sortable: true, render: ( r ) => badge( SUPPRESSION_TYPES[ r.type ] || labelize( r.type ), 'neutral' ) },
                { key: 'value', label: 'Value', sortable: true, render: ( r ) => el( 'span', { class: 'ppclg-mono' }, r.value ) },
                { key: 'reason', label: 'Reason', render: ( r ) => r.reason || null },
                { key: 'created_at', label: 'Added', sortable: true, render: ( r ) => timeEl( r.created_at ) },
                { key: 'actions', label: 'Actions', hideLabel: true, align: 'right', render: ( r ) => button( 'Remove', { small: true, kind: 'danger', iconName: 'trash', onClick: async () => {
                    if ( ! ( await confirm( `Remove ${ r.value } from the suppression list? It becomes eligible for discovery and outreach again.`, { danger: true, confirmText: 'Remove' } ) ) ) {
                        return;
                    }
                    await api.del( `/suppression/${ r.id }` );
                    toast( 'Entry removed.', 'success' );
                    list.reload();
                } } ) },
            ],
        } );
        replace( panel, callout( 'info', null, 'Entries added by unsubscribe links and by “Suppress domain” on a prospect appear here automatically.' ), list.el );
        list.reload();
    }
}
