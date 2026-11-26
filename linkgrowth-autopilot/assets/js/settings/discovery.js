/**
 * Settings tab "Discovery" (discovery): query inputs, competitors, cadence and custom seeds.
 * The Settings page (pages/settings.js) renders it from lga-config.settingsTabs; `tab` carries the page's shared
 * state and helpers (bind() edits the draft the page saves with every other tab's changes).
 */

import * as api from '../api.js';
import {
    el, replace, button, card, checkbox, details, dataTable, extLink, fmtNumber, labelize, truncate, modal, toast, isObject, setPath,
} from '../ui.js';

const SEED_TYPES = [ 'directory', 'supplier_directory', 'association', 'publication', 'media', 'blog', 'educational', 'resource_page', 'podcast', 'government', 'ecommerce' ];
const OPPORTUNITIES = [ 'directory_listing', 'resource_page', 'editorial', 'partnership', 'expert_commentary', 'educational_contribution', 'broken_link', 'unlinked_mention', 'podcast', 'membership', 'supplier_listing' ];

export default function render( tab ) {
    const { state, bind, grid, markDirty, rowEditor } = tab;
    const obj = ( v ) => ( isObject( v ) ? v : {} );
    const arr = ( v ) => ( Array.isArray( v ) ? v : [] );
    const competitorsField = bind( 'discovery.competitor_domains', { type: 'list', label: 'Competitor domains (one per line)', rows: 5, hint: 'Their backlink profiles are mined for prospects (requires SE Ranking).' } );
    const suggestBtn = button( 'Suggest from SE Ranking', { small: true, iconName: 'search', control: 'competitors-suggest', onClick: async () => {
        const res = await api.post( '/settings/competitors/suggest', {} );
        const items = arr( Array.isArray( res ) ? res : ( res && ( res.competitors || res.items || res.data ) ) ).map( ( c ) => ( typeof c === 'string' ? { domain: c } : obj( c ) ) ).filter( ( c ) => c.domain );
        if ( ! items.length ) {
            toast( 'No suggestions returned. Check that SE Ranking is configured and the domain has ranking data.', 'warning' );
            return;
        }
        const selected = new Set();
        modal( {
            title: 'Suggested competitors',
            control: 'competitors-suggest-dialog',
            content: el( 'div', { class: 'lga-prospect-picker' }, items.slice( 0, 40 ).map( ( c ) => {
                const cb = checkbox( { 'aria-label': c.domain, control: 'competitors-suggest-dialog-pick' } );
                cb.addEventListener( 'change', () => ( cb.checked ? selected.add( c.domain ) : selected.delete( c.domain ) ) );
                const extra = Object.entries( c ).filter( ( [ k, v ] ) => k !== 'domain' && ( typeof v === 'number' || typeof v === 'string' ) ).slice( 0, 3 ).map( ( [ k, v ] ) => `${ labelize( k ) }: ${ typeof v === 'number' ? fmtNumber( v ) : v }` ).join( ' · ' );
                return el( 'label', {}, cb, el( 'span', { class: 'lga-grow' }, el( 'span', { class: 'lga-strong' }, c.domain ), extra ? el( 'span', { class: 'lga-cell-sub' }, extra ) : null ) );
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
        control: 'seeds',
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

    return el( 'div', { class: 'lga-stack' },
        card( { title: 'Query inputs', subtitle: `${ fmtNumber( obj( state.data ).query_pool_size ) } queries in the generated pool`, body: grid( [
            bind( 'discovery.geo_modifiers', { type: 'list', label: 'Geographic modifiers (one per line)', rows: 5 } ),
            bind( 'discovery.brand_terms', { type: 'list', label: 'Brand terms for mention scans (one per line)', rows: 5 } ),
            bind( 'discovery.custom_queries', { type: 'list', label: 'Custom search queries (one per line)', rows: 5, full: true, hint: 'Added to the generated pool verbatim.' } ),
            bind( 'discovery.excluded_domains', { type: 'list', label: 'Excluded domains (one per line)', rows: 4, full: true, hint: 'Never discovered or evaluated (big platforms are excluded by default).' } ),
        ] ) } ),
        card( { title: 'Competitors', actions: suggestBtn, body: el( 'div', { class: 'lga-stack' },
            competitorsField.el,
            bind( 'discovery.competitor_domains_auto', { type: 'list', label: 'Found automatically by Autopilot (one per line)', rows: 4, hint: 'While your own list above is empty, Autopilot asks SE Ranking for your competitors at most once every 30 days and leaves out marketplaces, social networks, news, government and health or pharmacy sites. Remove any you do not want: a removed domain is never added back. Both lists are mined for prospects.' } ).el,
        ) } ),
        card( { title: 'Cadence', body: grid( [
            bind( 'discovery.query_cooldown_days', { type: 'number', label: 'Query cooldown (days)', min: 1, default: 30, hint: 'A query is not repeated within this window.' } ),
            bind( 'discovery.results_per_query', { type: 'number', label: 'Results per query', min: 1, max: 50, default: 10 } ),
            bind( 'discovery.seed_catalog', { type: 'toggle', label: 'Use the seed catalog', hint: `${ fmtNumber( seedCatalog.length ) } curated starting points (directories, associations, publications).` } ),
        ] ) } ),
        card( {
            title: 'Custom seeds',
            subtitle: state.seedsKnown ? 'Appended to the built-in catalog' : 'Appended to the built-in catalog — saving replaces custom seeds added earlier',
            body: [ seedsHost, seedCatalog.length ? details( `Built-in catalog (${ seedCatalog.length } entries)`, dataTable( { dense: true, plain: true, control: 'seed-catalog', rows: seedCatalog.slice( 0, 200 ), columns: [
                { key: 'name', label: 'Name', render: ( s ) => s.name || s.url },
                { key: 'url', label: 'URL', render: ( s ) => extLink( s.url, truncate( s.url, 50 ), { control: 'seed-catalog-url' } ) },
                { key: 'type', label: 'Type', render: ( s ) => labelize( s.type ) },
                { key: 'opportunity', label: 'Opportunity', render: ( s ) => labelize( s.opportunity ) },
                { key: 'country', label: 'Country' },
            ] } ), { control: 'seed-catalog-toggle' } ) : null ],
        } ),
    );
}
