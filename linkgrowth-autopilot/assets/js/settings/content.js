/**
 * Settings tab "Content & facts" (content): verified facts, claims filter rules and linkable assets.
 * The Settings page (pages/settings.js) renders it from lga-config.settingsTabs; `tab` carries the page's shared
 * state and helpers (bind() edits the draft the page saves with every other tab's changes).
 */

import * as api from '../api.js';
import {
    el, button, card, confirm, emptyState, getPath, isObject, setPath, toast,
} from '../ui.js';

const ASSET_KINDS = [ 'guide', 'resource', 'info', 'home', 'tool', 'page' ];

export default function render( tab ) {
    const { ctx, state, markDirty, load, rowEditor } = tab;
    const obj = ( v ) => ( isObject( v ) ? v : {} );
    const arr = ( v ) => ( Array.isArray( v ) ? v : [] );
    const claims = obj( obj( state.data ).claims_rules );
    const assets = arr( getPath( state.draft, 'content.linkable_assets', [] ) ).length ? arr( getPath( state.draft, 'content.linkable_assets', [] ) ) : arr( obj( state.data ).assets );
    const assetRows = assets.map( ( a ) => ( { url: a.url || '', title: a.title || '', summary: a.summary || '', topics: arr( a.topics ).join( ', ' ), kind: a.kind || 'guide' } ) );
    const syncAssets = () => {
        setPath( state.draft, 'content.linkable_assets', assetRows.filter( ( r ) => r.url && r.title ).map( ( r ) => ( { url: r.url, title: r.title, summary: r.summary, topics: String( r.topics || '' ).split( ',' ).map( ( t ) => t.trim() ).filter( Boolean ), kind: r.kind } ) ) );
        markDirty();
    };
    return el( 'div', { class: 'lga-stack' },
        card( {
            title: 'Verified facts',
            subtitle: 'The only statements generated content may make about the company. Unverified facts are never used.',
            actions: button( 'Reset to defaults', { small: true, kind: 'danger', iconName: 'refresh', control: 'facts-reset', onClick: async () => {
                if ( ! ( await confirm( 'Replace all facts with the built-in defaults? Custom facts are lost.', { danger: true, confirmText: 'Reset', control: 'facts-reset-confirm' } ) ) ) {
                    return;
                }
                await api.post( '/settings/facts/reset', {} );
                toast( 'Facts reset to defaults.', 'success' );
                await load();
            } } ),
            body: rowEditor( {
                control: 'facts',
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
        card( { title: 'Claims filter rules', subtitle: 'Applied to every generated description and outreach message (negated compliance statements are allowed)', body: Object.keys( claims ).length ? el( 'ul', { class: 'lga-list--bullets' }, Object.entries( claims ).map( ( [ rule, message ] ) => el( 'li', {}, el( 'span', { class: 'lga-mono' }, rule ), ' — ', String( message ) ) ) ) : emptyState( { title: 'No rules reported', compact: true } ) } ),
        card( {
            title: 'Linkable assets',
            subtitle: 'Non-commercial pages offered in resource-page and educational outreach; topics drive matching',
            body: rowEditor( {
                control: 'assets',
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
