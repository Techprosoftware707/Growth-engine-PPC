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
                if ( spec.type === 'number' ) {
                    next = v === null ? ( spec.default !== undefined ? spec.default : 0 ) : v;
                }
                setPath( state.draft, path, next );
                markDirty();
            },
        } );
    }

    async function load() {
        try {
            state.data = await api.get( '/settings' );
        } catch ( e ) {
            replace( content, errorState( { title: 'Settings could not be loaded', message: e && e.message ? e.message : '', retry: load } ) );
            return;
        }
        const d = state.data || {};
        state.original = deepClone( obj( d.settings ) );
        state.draft = deepClone( state.original );
        state.pendingSecrets = {};
        state.factsRows = Object.entries( obj( d.facts ) ).map( ( [ key, f ] ) => ( { key, statement: String( obj( f ).statement || '' ), verified: Boolean( obj( f ).verified ), source: String( obj( f ).source || '' ), group: String( obj( f ).group || 'custom' ) } ) );
        state.factsDirty = false;
        state.seedsKnown = Array.isArray( d.custom_seeds );
        state.customSeeds = arr( d.custom_seeds ).map( ( s ) => ( { url: s.url || '', name: s.name || '', type: s.type || 'directory', opportunity: s.opportunity || 'directory_listing', category: s.category || '', country: s.country || 'CA' } ) );
        state.seedsDirty = false;
        markDirty();
        drawTabs();
    }

    async function save() {
        const body = {};
        const changed = {};
        for ( const key of Object.keys( state.draft ) ) {
            if ( ! deepEqual( state.draft[ key ], state.original[ key ] ) ) {
                changed[ key ] = state.draft[ key ];
            }
        }
        if ( Object.keys( changed ).length ) {
            body.settings = changed;
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
        if ( ! Object.keys( body ).length ) {
            toast( 'Nothing to save.', 'info' );
            return;
        }
        await api.post( '/settings', body );
        toast( 'Settings saved.', 'success' );
        await load();
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
            [ 'submissions', 'Automated submissions', 'Submit approved packages on explicitly allowed sites. Off by default.' ],
            [ 'outreach_autosend', 'Outreach autosend', 'Send auto-approved follow-ups without review. Off by default.' ],
            [ 'monitoring', 'Backlink monitoring', 'Re-check backlinks and alert on changes.' ],
            [ 'imports', 'Provider imports', 'Import backlinks and referral sources periodically.' ],
        ];
        const limitKeys = [ 'discovery_queries', 'evaluations', 'submissions', 'outreach_emails', 'backlink_checks', 'provider_lookups' ];
        return el( 'div', { class: 'ppclg-stack' },
            callout( 'info', 'Live switches', 'These are the same flags as on the Automation page. Enabling “Automated submissions” or “Outreach autosend” there asks for confirmation; here they are saved with the rest of the form.' ),
            card( { title: 'Stage flags', body: flags.map( ( [ key, label, hint ] ) => bind( `automation.${ key }`, { type: 'toggle', label, hint } ).el ) } ),
            card( { title: 'Daily limits', subtitle: '0 = unlimited', body: grid( limitKeys.map( ( k ) => bind( `automation.daily_limits.${ k }`, { type: 'number', label: labelize( k ), min: 0, default: 0 } ) ), 3 ) } ),
            card( { title: 'Weekly limits', subtitle: '0 = unlimited', body: grid( [ 'discovery_queries', 'submissions', 'outreach_emails' ].map( ( k ) => bind( `automation.weekly_limits.${ k }`, { type: 'number', label: labelize( k ), min: 0, default: 0 } ) ), 3 ) } ),
            card( { title: 'Thresholds & timing', body: grid( [
                bind( 'automation.min_score_auto_queue', { type: 'number', label: 'Minimum score to auto-package', min: 0, max: 100, default: 70, hint: 'Opportunities on prospects at or above this score are packaged automatically (still reviewed).' } ),
                bind( 'automation.min_score_keep', { type: 'number', label: 'Minimum score to keep', min: 0, max: 100, default: 50, hint: 'Prospects below this are rejected after evaluation.' } ),
                bind( 'automation.max_execution_seconds', { type: 'number', label: 'Max seconds per tick', min: 5, max: 25, default: 20, hint: 'Keep below the PHP time limit (30 s on this host).' } ),
                bind( 'automation.tick_interval', { type: 'number', label: 'Tick interval (seconds)', min: 60, step: 60, default: 300 } ),
                bind( 'automation.international', { type: 'toggle', label: 'Include non-Canadian sites', hint: 'When off, only sites judged Canadian are kept.' } ),
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
                    secretField( 'inbound_webhook_secret', { hint: 'Shared secret your inbound-email provider sends in the X-PPCLG-Secret header (or “secret” body field).' } ),
                    el( 'div', {}, el( 'div', { class: 'ppclg-field-label' }, 'Inbound webhook URL' ), el( 'div', { class: 'ppclg-row' }, el( 'code', { class: 'ppclg-secret-masked', style: { flex: '1 1 auto' } }, inboundUrl ), copyButton( inboundUrl ) ), el( 'div', { class: 'ppclg-field-hint' }, 'POST JSON with from, subject, text, in_reply_to / message_id. Replies are matched to sent messages and logged.' ) ),
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
                bind( 'outreach.require_approval', { type: 'toggle', label: 'Require individual approval for every message', hint: 'Recommended. Drafts are never sent until approved.' } ),
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
