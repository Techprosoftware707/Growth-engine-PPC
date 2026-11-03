/**
 * Outreach page: campaigns (create, approve, pause, build drafts), messages
 * (review, edit with live claims check, approve, send, log replies) and contacts.
 */

import * as api from '../api.js';
import {
    el, replace, badge, statusBadge, button, callout, kv, loading, errorState, emptyState, timeEl, fmtNumber, fmtDate, labelize, truncate,
    pageHeader, drawer, tabs, dataTable, formField, formModal, modal, confirm, prompt, toast, extLink, listView, normalizeList, card, linkButton, isObject, icon, debounce, switchRow,
} from '../ui.js';

const MESSAGE_STATUSES = [ 'draft', 'approved', 'scheduled', 'sent', 'failed', 'replied', 'bounced', 'opted_out', 'closed', 'rejected' ];
const CONTACT_STATUSES = [ 'active', 'opted_out', 'bounced', 'suppressed', 'invalid' ];
const EDITABLE_STATUSES = [ 'draft', 'approved', 'rejected', 'failed' ];

/** Client-side mirror of Content\ClaimsFilter rules (the server check is authoritative). */
const CLAIM_RULES = [
    { rule: 'therapeutic', re: /\b(treat(s|ed|ing|ment)?|cure(s|d)?|heal(s|ed|ing)?|therap(y|ies|eutic)|remedy|relie(f|ve)|prevent(s|ion)?|regenerat(e|ive|ion)|rejuvenat|recover(y|ies) from)\b/gi, message: 'Therapeutic or medical claim' },
    { rule: 'human_use', re: /\b(for (human|personal|your) (use|health|body)|take (it|daily|before|after)|dos(e|es|age|ing)|inject(ion|ions|able)?|administer|administration|cycle|stack|protocol for (use|users))\b/gi, message: 'Human-use, dosing or administration language' },
    { rule: 'benefit', re: /\b(weight[\s-]?loss|fat[\s-]?loss|muscle (growth|gain|building)|anti[\s-]?ag(e|ing)|longevity|performance[\s-]?enhanc|boost(s|ing)? (your|energy|immune|metabolism)|burn(s|ing)? fat|wrinkle|skin (repair|tightening)|hair (growth|regrowth)|libido|sleep better|tanning)\b/gi, message: 'Health or cosmetic benefit claim' },
    { rule: 'approval', re: /\b((fda|health canada|ema|who)[\s-]?(approved|registered|certified|cleared|compliant)|clinically (proven|tested)|doctor[\s-]?recommended|medical[\s-]?grade|pharmaceutical[\s-]?grade|gmp[\s-]?certified)\b/gi, message: 'Regulatory approval or grade claim' },
    { rule: 'purity_number', re: /\b(9\d(\.\d+)?|100)\s?%\s?(pur(e|ity)|purity guaranteed)\b/gi, message: 'Specific purity percentage claim' },
    { rule: 'superlative', re: /\b(#\s?1|number one|the best|best (peptide|supplier|quality|prices?)|cheapest|lowest price|guaranteed results|world[\s-]?class|industry[\s-]?leading|top[\s-]?rated|highest quality)\b/gi, message: 'Unsupported superlative' },
    { rule: 'safety', re: /\b(safe (to use|for (humans|people|you))|no side effects|risk[\s-]?free|100% safe)\b/gi, message: 'Safety claim' },
    { rule: 'supplement', re: /\b(supplement(s|ation)?|nutraceutical|wellness product|health product)\b/gi, message: 'Positions research reagents as supplements or health products' },
    { rule: 'patient', re: /\b(patients?|clients? taking|users? report)\b/gi, message: 'Implies clinical or consumer use' },
];
const NEGATION = /\b(not|no|never|without|nor|neither|rather than|instead of)\b[^.]*$/i;

export function localClaimsCheck( text ) {
    const out = [];
    const str = String( text || '' );
    for ( const def of CLAIM_RULES ) {
        def.re.lastIndex = 0;
        let m;
        while ( ( m = def.re.exec( str ) ) !== null ) {
            const before = str.slice( Math.max( 0, m.index - 40 ), m.index );
            if ( ! NEGATION.test( before ) ) {
                out.push( { rule: def.rule, match: m[ 0 ], message: def.message } );
            }
            if ( m[ 0 ] === '' ) {
                def.re.lastIndex += 1;
            }
        }
    }
    return out;
}

const obj = ( v ) => ( isObject( v ) ? v : {} );
const arr = ( v ) => ( Array.isArray( v ) ? v : [] );

function normalizeApproaches( res ) {
    const out = [];
    if ( Array.isArray( res ) ) {
        for ( const a of res ) {
            if ( isObject( a ) && a.id ) {
                out.push( { id: String( a.id ), label: a.label || labelize( a.id ), description: a.description || '', variants: arr( a.variants ) } );
            }
        }
        return out;
    }
    if ( ! isObject( res ) ) {
        return out;
    }
    const map = isObject( res.approaches ) ? res.approaches : res;
    const descriptions = obj( res.descriptions );
    const variants = obj( res.variants );
    for ( const [ id, v ] of Object.entries( map ) ) {
        if ( [ 'descriptions', 'variants', 'placeholders', 'follow_up' ].includes( id ) ) {
            continue;
        }
        if ( typeof v === 'string' ) {
            out.push( { id, label: v, description: descriptions[ id ] || '', variants: arr( variants[ id ] ) } );
        } else if ( isObject( v ) && ( v.label || v.variants || v.description ) ) {
            out.push( { id, label: v.label || labelize( id ), description: v.description || descriptions[ id ] || '', variants: arr( v.variants || variants[ id ] ) } );
        }
    }
    return out;
}

function violationList( violations ) {
    return el( 'ul', { class: 'ppclg-violations' }, violations.map( ( v ) => el( 'li', {}, icon( 'alert', 14 ), `${ v.message || labelize( v.rule || 'claim' ) }: “${ v.match || '' }”` ) ) );
}

export default async function render( root, ctx ) {
    const params = ctx.params || {};
    const cache = { approaches: null, campaigns: null };
    const settingsUrl = ctx.pageUrl( 'settings', { tab: 'outreach' } );

    const bannerHost = el( 'div' );
    const messageFilters = { status: params.status || '', campaign_id: params.campaign_id || '', prospect_id: params.prospect_id || '', approach: params.approach || '', q: params.q || '' };
    let messagesList = null;
    const view = tabs( {
        pills: true,
        active: [ 'campaigns', 'messages', 'contacts' ].includes( params.tab ) ? params.tab : 'campaigns',
        onChange: ( id ) => ctx.setHash( { tab: id } ),
        items: [
            { id: 'campaigns', label: 'Campaigns', iconName: 'send', render: ( panel ) => campaignsTab( panel ) },
            { id: 'messages', label: 'Messages', iconName: 'mail', render: ( panel ) => messagesTab( panel ) },
            { id: 'contacts', label: 'Contacts', iconName: 'users', render: ( panel ) => contactsTab( panel ) },
        ],
    } );

    replace( root,
        pageHeader( {
            title: 'Outreach',
            description: 'Personalised, CASL/CAN-SPAM-compliant email outreach. Every message is reviewed and approved before it is sent; recipients can opt out with one click.',
            actions: button( 'New campaign', { kind: 'primary', iconName: 'plus', onClick: () => createCampaign() } ),
        } ),
        bannerHost,
        view.el,
    );
    loadSenderBanner();

    if ( params.open ) {
        openMessage( Number( params.open ) );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------
    async function loadSenderBanner() {
        try {
            const sender = await api.get( '/outreach/sender', {}, { silent: true } );
            const missing = arr( sender && sender.missing ).map( ( m ) => labelize( m ) );
            const warnings = arr( sender && sender.warnings );
            replace( bannerHost,
                sender && sender.ok === false ? callout( 'danger', 'Sender identity incomplete — sending is blocked', `Missing: ${ missing.join( ', ' ) }. Anti-spam law requires the sender name, a working address and a postal address in every message.`, { actions: linkButton( 'Complete sender settings', settingsUrl, { small: true, kind: 'primary' } ) } ) : null,
                warnings.length ? callout( 'info', 'Sender identity notes', el( 'ul', {}, warnings.map( ( w ) => el( 'li', {}, String( w ) ) ) ) ) : null,
            );
        } catch ( e ) {
            replace( bannerHost );
        }
    }

    async function approaches() {
        if ( ! cache.approaches ) {
            try {
                cache.approaches = normalizeApproaches( await api.get( '/outreach/approaches', {}, { silent: true } ) );
            } catch ( e ) {
                cache.approaches = [];
            }
        }
        return cache.approaches;
    }

    async function campaigns( force = false ) {
        if ( ! cache.campaigns || force ) {
            cache.campaigns = normalizeList( await api.get( '/outreach/campaigns', { per_page: 100 } ) ).items;
        }
        return cache.campaigns;
    }

    function approachLabel( id ) {
        const a = ( cache.approaches || [] ).find( ( x ) => x.id === id );
        return a ? a.label : labelize( id || '' );
    }

    // ------------------------------------------------------------------
    // Campaigns
    // ------------------------------------------------------------------
    async function createCampaign() {
        const list = await approaches();
        const approachField = formField( { type: 'select', name: 'approach', label: 'Approach', options: list.length ? list.map( ( a ) => ( { value: a.id, label: a.label } ) ) : { resource_page: 'Resource page inclusion' }, required: true } );
        const desc = el( 'div', { class: 'ppclg-field-hint' } );
        const variantsBox = el( 'div' );
        const syncDesc = () => {
            const a = list.find( ( x ) => x.id === approachField.get() );
            replace( desc, a && a.description ? a.description : '' );
            replace( variantsBox, a && a.variants.length ? el( 'details', { class: 'ppclg-details' }, el( 'summary', {}, `${ a.variants.length } template variants (preview)` ), el( 'div', { class: 'ppclg-stack' }, a.variants.map( ( v ) => el( 'div', { class: 'ppclg-message-preview' }, el( 'div', { class: 'ppclg-message-preview-head' }, `Subject: ${ v.subject || '' }` ), String( v.body || '' ) ) ) ) ) : null );
        };
        approachField.input.addEventListener( 'change', syncDesc );
        syncDesc();
        const nameField = formField( { type: 'text', name: 'name', label: 'Campaign name', placeholder: 'Canadian lab resource pages — Q1', required: true } );
        const limitField = formField( { type: 'number', name: 'daily_limit', label: 'Daily send limit', value: 5, min: 1, max: 100, hint: 'Also capped by the global outreach limits.' } );
        const followField = formField( { type: 'toggle', name: 'follow_ups', label: 'Allow one polite follow-up', value: true, hint: 'A single follow-up draft is created after the configured number of days without a reply.' } );
        const autoField = formField( { type: 'toggle', name: 'auto_follow_up', label: 'Auto-approve follow-ups', value: false, hint: 'Only takes effect when the global “Outreach autosend” stage is on; otherwise follow-ups wait for approval like any message.' } );
        const notesField = formField( { type: 'textarea', name: 'notes', label: 'Notes', rows: 2 } );

        const dialog = modal( {
            title: 'New campaign',
            width: 'wide',
            content: el( 'div', { class: 'ppclg-stack' },
                el( 'div', { class: 'ppclg-form-grid' }, nameField.el, el( 'div', { class: 'ppclg-field' }, approachField.el, desc ), limitField.el ),
                variantsBox,
                followField.el,
                autoField.el,
                notesField.el,
            ),
            actions: [
                { label: 'Cancel', onClick: ( close ) => close() },
                { label: 'Create campaign', kind: 'primary', onClick: async ( close ) => {
                    const name = String( nameField.get() || '' ).trim();
                    if ( name === '' ) {
                        nameField.setError( 'Give the campaign a name.' );
                        return;
                    }
                    await api.post( '/outreach/campaigns', {
                        name,
                        approach: approachField.get(),
                        settings: { daily_limit: limitField.get() || 5, follow_ups: followField.get(), auto_follow_up: autoField.get(), notes: notesField.get() },
                    } );
                    toast( 'Campaign created as a draft.', 'success' );
                    close();
                    await campaigns( true );
                    view.refresh( 'campaigns' );
                } },
            ],
        } );
        return dialog;
    }

    async function campaignsTab( panel ) {
        replace( panel, loading( 'Loading campaigns…', true ) );
        let items;
        try {
            await approaches();
            items = await campaigns( true );
        } catch ( e ) {
            replace( panel, errorState( { title: 'Could not load campaigns', message: e && e.message ? e.message : '', retry: () => view.refresh( 'campaigns' ) } ) );
            return;
        }
        if ( ! items.length ) {
            replace( panel, emptyState( { title: 'No campaigns yet', message: 'Create a campaign, choose an approach and build drafts for qualified prospects.', iconName: 'send', action: button( 'New campaign', { kind: 'primary', iconName: 'plus', onClick: () => createCampaign() } ) } ) );
            return;
        }
        replace( panel, el( 'div', { class: 'ppclg-grid ppclg-grid--cards' }, items.map( ( c ) => campaignCard( c ) ) ) );
    }

    function campaignCard( c ) {
        const settings = obj( c.settings );
        const stats = obj( c.stats );
        const statRows = Object.entries( stats ).filter( ( [ , v ] ) => typeof v === 'number' || typeof v === 'string' ).slice( 0, 6 );
        const act = async ( action, confirmText ) => {
            if ( confirmText && ! ( await confirm( confirmText, { confirmText: labelize( action ) } ) ) ) {
                return;
            }
            await api.post( `/outreach/campaigns/${ c.id }/${ action }`, {} );
            toast( `Campaign ${ action === 'submit' ? 'submitted for approval' : action + 'd' }.`, 'success' );
            await campaigns( true );
            view.refresh( 'campaigns' );
        };
        const actions = [];
        if ( c.status === 'draft' ) {
            actions.push( button( 'Submit for approval', { small: true, iconName: 'check', onClick: () => act( 'submit' ) } ) );
        }
        if ( c.status === 'pending_approval' ) {
            actions.push( button( 'Approve campaign', { small: true, kind: 'success', iconName: 'check', onClick: () => act( 'approve', 'Approve this campaign? Approved messages inside it become eligible for sending within the send window.' ) } ) );
        }
        if ( [ 'approved', 'sending' ].includes( c.status ) ) {
            actions.push( button( 'Pause', { small: true, iconName: 'pause', onClick: () => act( 'pause' ) } ) );
        }
        if ( c.status === 'paused' ) {
            actions.push( button( 'Resume', { small: true, iconName: 'play', onClick: () => act( 'resume' ) } ) );
        }
        if ( ! [ 'archived' ].includes( c.status ) ) {
            actions.push( button( 'Build drafts', { small: true, kind: 'primary', iconName: 'edit', onClick: () => buildDrafts( c ) } ) );
            actions.push( button( 'Archive', { small: true, kind: 'ghost', iconName: 'inbox', onClick: () => act( 'archive', 'Archive this campaign? Unsent messages will no longer be sent.' ) } ) );
        }
        actions.push( button( 'Messages', { small: true, kind: 'ghost', iconName: 'mail', onClick: () => {
            messageFilters.campaign_id = String( c.id );
            view.select( 'messages' );
            if ( messagesList ) {
                messagesList.setFilters( { campaign_id: String( c.id ) } );
            }
        } } ) );

        const countsHost = el( 'div', { class: 'ppclg-row', style: { marginTop: '8px' } } );
        api.get( `/outreach/campaigns/${ c.id }`, {}, { silent: true } ).then( ( res ) => {
            const detail = isObject( res && res.campaign ) ? res.campaign : obj( res );
            const counts = obj( detail.counts || detail.message_counts || detail.messages );
            const entries = Object.entries( counts ).filter( ( [ , v ] ) => typeof v === 'number' );
            replace( countsHost, entries.length ? entries.map( ( [ k, v ] ) => badge( `${ labelize( k ) }: ${ fmtNumber( v ) }`, k === 'sent' || k === 'replied' ? 'success' : ( k === 'failed' ? 'danger' : 'neutral' ) ) ) : el( 'span', { class: 'ppclg-muted ppclg-small' }, 'No messages yet' ) );
        } ).catch( () => replace( countsHost ) );

        return card( {
            title: c.name,
            subtitle: approachLabel( c.approach ),
            actions: statusBadge( c.status ),
            body: [
                kv( [
                    [ 'Daily limit', fmtNumber( c.daily_limit || settings.daily_limit ) ],
                    [ 'Follow-ups', settings.follow_ups === false ? 'Off' : `On${ settings.auto_follow_up ? ' (auto-approve)' : '' }` ],
                    [ 'Created', fmtDate( c.created_at ) ],
                    [ 'Approved', c.approved_at ? fmtDate( c.approved_at ) : null ],
                ].concat( statRows.map( ( [ k, v ] ) => [ labelize( k ), typeof v === 'number' ? fmtNumber( v ) : String( v ) ] ) ), { compact: true } ),
                countsHost,
                settings.notes ? el( 'p', { class: 'ppclg-small', style: { marginTop: '8px' } }, String( settings.notes ) ) : null,
            ],
            footer: actions,
        } );
    }

    async function buildDrafts( c ) {
        const picker = el( 'div', { class: 'ppclg-prospect-picker' }, loading( 'Loading qualified prospects…', true ) );
        const search = el( 'input', { type: 'search', class: 'ppclg-input', placeholder: 'Filter by domain or name…', 'aria-label': 'Filter prospects' } );
        const aiField = formField( { type: 'toggle', name: 'use_ai', label: 'AI personalisation', value: false, hint: 'Rewrites the opening observation with the configured AI writer (if enabled). The compliance footer and unsubscribe link are always preserved.' } );
        const selected = new Set();
        const countEl = el( 'span', { class: 'ppclg-muted ppclg-small' }, '0 selected' );
        let rows = [];

        const renderRows = () => {
            const q = search.value.trim().toLowerCase();
            const visible = rows.filter( ( p ) => q === '' || String( p.domain || '' ).toLowerCase().includes( q ) || String( p.name || '' ).toLowerCase().includes( q ) );
            replace( picker, visible.length ? visible.map( ( p ) => {
                const cb = el( 'input', { type: 'checkbox', checked: selected.has( p.id ), 'aria-label': p.domain } );
                cb.addEventListener( 'change', () => {
                    if ( cb.checked ) {
                        selected.add( p.id );
                    } else {
                        selected.delete( p.id );
                    }
                    countEl.textContent = `${ selected.size } selected`;
                } );
                return el( 'label', {}, cb, el( 'span', { class: 'ppclg-grow' }, el( 'span', { class: 'ppclg-strong' }, p.name || p.domain ), ' ', el( 'span', { class: 'ppclg-muted ppclg-small' }, p.domain ) ), badge( `${ fmtNumber( p.score ) }`, 'neutral' ), statusBadge( p.status ) );
            } ) : emptyState( { title: 'No matching prospects', compact: true } ) );
        };
        search.addEventListener( 'input', debounce( renderRows, 200 ) );

        ( async () => {
            try {
                const results = await Promise.all( [ 'approved', 'qualified', 'in_progress' ].map( ( status ) => api.get( '/prospects', { status, per_page: 100, orderby: 'score', order: 'desc' }, { silent: true } ).then( normalizeList ).catch( () => ( { items: [] } ) ) ) );
                const seen = new Set();
                rows = results.flatMap( ( r ) => r.items ).filter( ( p ) => {
                    if ( seen.has( p.id ) ) {
                        return false;
                    }
                    seen.add( p.id );
                    return true;
                } );
                renderRows();
            } catch ( e ) {
                replace( picker, errorState( { title: 'Could not load prospects', message: e && e.message ? e.message : '' } ) );
            }
        } )();

        modal( {
            title: `Build drafts — ${ c.name }`,
            width: 'wide',
            content: el( 'div', { class: 'ppclg-stack' },
                el( 'p', {}, 'Drafts are created for prospects with an active, legitimately published contact and a matching opportunity. Nothing is sent until each message is approved.' ),
                el( 'div', { class: 'ppclg-row ppclg-row--between' }, el( 'div', { class: 'ppclg-grow' }, search ), countEl, button( 'Select all visible', { small: true, kind: 'ghost', onClick: () => {
                    picker.querySelectorAll( 'input[type="checkbox"]' ).forEach( ( cb ) => {
                        if ( ! cb.checked ) {
                            cb.click();
                        }
                    } );
                } } ) ),
                picker,
                aiField.el,
            ),
            actions: [
                { label: 'Cancel', onClick: ( close ) => close() },
                { label: 'Build drafts', kind: 'primary', onClick: async ( close ) => {
                    if ( selected.size === 0 ) {
                        toast( 'Select at least one prospect.', 'warning' );
                        return;
                    }
                    const res = await api.post( `/outreach/campaigns/${ c.id }/drafts`, { prospect_ids: Array.from( selected ), use_ai: aiField.get() } );
                    const created = Number( res && res.created ) || 0;
                    const skipped = obj( res && res.skipped );
                    const skippedEntries = Object.entries( skipped );
                    toast( `${ fmtNumber( created ) } draft(s) created${ skippedEntries.length ? `, ${ skippedEntries.length } skipped` : '' }.`, created > 0 ? 'success' : 'warning' );
                    close();
                    if ( skippedEntries.length ) {
                        modal( {
                            title: 'Skipped prospects',
                            content: el( 'ul', { class: 'ppclg-list--bullets' }, skippedEntries.map( ( [ pid, reason ] ) => el( 'li', {}, `${ rows.find( ( p ) => String( p.id ) === String( pid ) )?.domain || `#${ pid }` }: ${ reason }` ) ) ),
                            actions: [ { label: 'Close', kind: 'primary', onClick: ( close2 ) => close2() } ],
                        } );
                    }
                    view.refresh( 'campaigns' );
                    if ( messagesList ) {
                        messagesList.reload();
                    }
                } },
            ],
        } );
    }

    // ------------------------------------------------------------------
    // Messages
    // ------------------------------------------------------------------
    async function messagesTab( panel ) {
        replace( panel, loading( 'Loading messages…', true ) );
        const [ list, appr ] = await Promise.all( [ campaigns().catch( () => [] ), approaches() ] );
        messagesList = listView( {
            fetch: ( p ) => api.get( '/outreach/messages', p ),
            defaultSort: { key: 'id', dir: 'desc' },
            initial: { filters: messageFilters },
            onRowClick: ( row ) => openMessage( Number( row.id ) ),
            rowKey: ( row ) => row.id,
            empty: { title: 'No messages', message: 'Build drafts from a campaign to get started.', iconName: 'mail' },
            filters: [
                { name: 'status', label: 'Status', type: 'select', options: MESSAGE_STATUSES.map( ( s ) => ( { value: s, label: labelize( s ) } ) ), allLabel: 'All statuses' },
                { name: 'campaign_id', label: 'Campaign', type: 'select', options: list.map( ( c ) => ( { value: String( c.id ), label: c.name } ) ), allLabel: 'All campaigns' },
                { name: 'approach', label: 'Approach', type: 'select', options: appr.map( ( a ) => ( { value: a.id, label: a.label } ) ), allLabel: 'All approaches' },
                { name: 'prospect_id', label: 'Prospect #', type: 'number', placeholder: 'ID', width: '90px', min: 1 },
                { name: 'q', label: 'Search', type: 'search', placeholder: 'Subject…' },
            ],
            columns: [
                { key: 'prospect', label: 'Prospect', render: ( m ) => el( 'span', {}, el( 'span', { class: 'ppclg-cell-title' }, m.prospect_name || m.prospect_domain || `#${ m.prospect_id }` ), el( 'span', { class: 'ppclg-cell-sub' }, m.contact_email || '' ) ) },
                { key: 'subject', label: 'Subject', sortable: true, render: ( m ) => el( 'span', { class: 'ppclg-cell-title', title: m.subject || '' }, m.subject || '(no subject)' ) },
                { key: 'approach', label: 'Approach', render: ( m ) => approachLabel( m.approach ) },
                { key: 'sequence', label: 'Seq.', align: 'center', render: ( m ) => ( Number( m.sequence ) > 1 ? badge( `Follow-up ${ Number( m.sequence ) - 1 }`, 'info' ) : 'Initial' ) },
                { key: 'status', label: 'Status', sortable: true, render: ( m ) => statusBadge( m.status ) },
                { key: 'scheduled_at', label: 'Scheduled', sortable: true, render: ( m ) => ( m.scheduled_at ? timeEl( m.scheduled_at ) : null ) },
                { key: 'sent_at', label: 'Sent', sortable: true, render: ( m ) => ( m.sent_at ? timeEl( m.sent_at ) : null ) },
                { key: 'replied_at', label: 'Replied', render: ( m ) => ( m.replied_at ? timeEl( m.replied_at ) : null ) },
            ],
            onLoaded: ( { state } ) => {
                Object.assign( messageFilters, state.filters );
                ctx.setHash( { tab: 'messages', ...state.filters } );
            },
        } );
        replace( panel, messagesList.el );
        messagesList.reload();
    }

    async function openMessage( id ) {
        if ( ! id ) {
            return;
        }
        const body = el( 'div', {}, loading( 'Loading message…', true ) );
        const dr = drawer( `Message #${ id }`, body, { width: 'wide' } );
        await approaches();

        async function load() {
            let data;
            try {
                data = await api.get( `/outreach/messages/${ id }` );
            } catch ( e ) {
                dr.setContent( errorState( { title: 'Could not load this message', message: e && e.message ? e.message : '', retry: load } ) );
                return;
            }
            const m = obj( data.message ).id ? obj( data.message ) : obj( data );
            const prospect = obj( isObject( data.prospect ) ? data.prospect : m.prospect );
            const contact = obj( isObject( data.contact ) ? data.contact : m.contact );
            const preview = obj( isObject( data.preview ) ? data.preview : m.preview );
            if ( ! m.id ) {
                dr.setContent( errorState( { title: 'Message not found' } ) );
                return;
            }
            const refresh = async () => {
                if ( messagesList ) {
                    messagesList.reload();
                }
                await load();
            };
            const editable = EDITABLE_STATUSES.includes( m.status );

            dr.setTitle( m.subject || '(no subject)' );
            dr.setSubtitle( [
                statusBadge( m.status ),
                badge( approachLabel( m.approach ), 'brand' ),
                Number( m.sequence ) > 1 ? badge( 'Follow-up', 'info' ) : null,
                m.variant ? badge( `Variant ${ m.variant }`, 'neutral' ) : null,
                prospect.id ? el( 'a', { href: ctx.pageUrl( 'prospects', { open: prospect.id } ) }, prospect.name || prospect.domain ) : null,
            ] );

            const stack = el( 'div', { class: 'ppclg-stack' } );
            if ( m.last_error ) {
                stack.appendChild( callout( 'danger', 'Last error', String( m.last_error ) ) );
            }
            if ( m.reply_summary ) {
                stack.appendChild( callout( 'success', `Reply received ${ m.replied_at ? fmtDate( m.replied_at ) : '' }`, String( m.reply_summary ) ) );
            }
            stack.appendChild( card( { title: 'Recipient', body: kv( [
                [ 'To', contact.email ? el( 'span', {}, contact.name ? `${ contact.name } ` : '', el( 'span', { class: 'ppclg-mono' }, `<${ contact.email }>` ), ' ', statusBadge( contact.status, { dot: false } ) ) : ( preview.to || null ) ],
                [ 'Role / consent', [ contact.role ? labelize( contact.role ) : '', contact.consent_basis ? labelize( contact.consent_basis ) : '' ].filter( Boolean ).join( ' · ' ) ],
                [ 'Prospect', prospect.domain ? extLink( prospect.homepage_url || `https://${ prospect.domain }/`, prospect.domain ) : null ],
                [ 'Campaign', m.campaign_id ? el( 'a', { href: ctx.pageUrl( 'outreach', { tab: 'messages', campaign_id: m.campaign_id } ) }, `Campaign #${ m.campaign_id }` ) : null ],
                [ 'Scheduled / sent', [ m.scheduled_at ? `scheduled ${ fmtDate( m.scheduled_at ) }` : '', m.sent_at ? `sent ${ fmtDate( m.sent_at ) }` : '' ].filter( Boolean ).join( ' · ' ) ],
                [ 'Message-ID', m.message_id || null ],
            ], { compact: true } ) } ) );

            // Editor with live claims check
            const subjectField = formField( { type: 'text', name: 'subject', label: 'Subject', value: m.subject || '', disabled: ! editable, full: true } );
            const bodyField = formField( { type: 'textarea', name: 'body', label: 'Body (plain text)', value: m.body || '', rows: 14, disabled: ! editable, full: true, hint: 'Keep the compliance footer and unsubscribe link intact; the server rejects messages without them.' } );
            const liveBox = el( 'div', { 'aria-live': 'polite' } );
            const runLive = () => {
                const v = localClaimsCheck( `${ subjectField.get() }\n${ bodyField.get() }` );
                replace( liveBox, v.length ? callout( 'warning', `${ v.length } possible claim issue(s)`, violationList( v ) ) : el( 'div', { class: 'ppclg-row ppclg-small ppclg-muted' }, icon( 'check', 14 ), 'No claim patterns detected (local check; the server re-checks on save).' ) );
            };
            const liveDebounced = debounce( runLive, 400 );
            subjectField.input.addEventListener( 'input', liveDebounced );
            bodyField.input.addEventListener( 'input', liveDebounced );
            runLive();

            stack.appendChild( card( {
                title: 'Message',
                subtitle: editable ? 'Edit the copy; the claims filter runs as you type and again on save' : 'Read-only in this status',
                body: [ subjectField.el, bodyField.el, liveBox ],
                footer: editable ? [ button( 'Save', { kind: 'primary', onClick: async () => {
                    const res = await api.post( `/outreach/messages/${ m.id }`, { subject: subjectField.get(), body: bodyField.get() } );
                    const violations = arr( res && res.violations );
                    if ( violations.length ) {
                        replace( liveBox, callout( 'danger', 'The claims filter rejected this copy', violationList( violations ) ) );
                        toast( 'Saved with claim issues — fix them before approving.', 'warning' );
                    } else {
                        toast( 'Message saved.', 'success' );
                    }
                    await refresh();
                } } ) ] : null,
            } ) );

            stack.appendChild( card( {
                title: 'Preview',
                subtitle: 'Exactly what the recipient will receive',
                body: el( 'div', { class: 'ppclg-message-preview' },
                    el( 'div', { class: 'ppclg-message-preview-head' }, `To: ${ preview.to || contact.email || '' }`, el( 'br' ), `Subject: ${ preview.subject || m.subject || '' }` ),
                    String( preview.body || m.body || '' ),
                ),
            } ) );

            const actions = [];
            if ( [ 'draft', 'rejected', 'failed' ].includes( m.status ) ) {
                actions.push( button( 'Approve', { kind: 'success', iconName: 'check', onClick: async () => {
                    if ( ! ( await confirm( 'Approve this message? It becomes eligible for sending in the next send window once its campaign is approved.', { confirmText: 'Approve' } ) ) ) {
                        return;
                    }
                    const res = await api.post( `/outreach/messages/${ m.id }/approve`, {} );
                    const violations = arr( res && res.violations );
                    if ( res && res.ok === false ) {
                        modal( { title: 'Not approved', content: [ el( 'p', {}, 'Compliance checks failed:' ), violationList( violations.map( ( v ) => ( typeof v === 'string' ? { message: v, match: '' } : v ) ) ) ], actions: [ { label: 'Close', kind: 'primary', onClick: ( close ) => close() } ] } );
                        return;
                    }
                    toast( 'Message approved.', 'success' );
                    await refresh();
                } } ) );
            }
            if ( [ 'draft', 'approved', 'scheduled' ].includes( m.status ) ) {
                actions.push( button( 'Reject', { kind: 'danger', iconName: 'x', onClick: async () => {
                    const reason = await prompt( 'Reason for rejecting this draft (optional):', { title: 'Reject message', label: 'Reason', required: false, confirmText: 'Reject', danger: true } );
                    if ( reason === null ) {
                        return;
                    }
                    await api.post( `/outreach/messages/${ m.id }/reject`, { reason } );
                    toast( 'Message rejected.', 'success' );
                    await refresh();
                } } ) );
            }
            if ( [ 'approved', 'scheduled' ].includes( m.status ) ) {
                actions.push( button( 'Send now', { kind: 'primary', iconName: 'send', onClick: async () => {
                    if ( ! ( await confirm( `Send this email to ${ contact.email || 'the contact' } right now? Compliance checks and limits still apply; the send window is bypassed.`, { title: 'Send email', confirmText: 'Send now' } ) ) ) {
                        return;
                    }
                    const res = await api.post( `/outreach/messages/${ m.id }/send`, {} );
                    if ( res && res.ok === false ) {
                        toast( `Not sent: ${ res.error || 'blocked by compliance or limits' }`, 'error' );
                    } else {
                        toast( 'Email sent.', 'success' );
                    }
                    await refresh();
                } } ) );
            }
            if ( [ 'sent', 'replied' ].includes( m.status ) ) {
                actions.push( button( 'Log reply', { iconName: 'mail', onClick: async () => {
                    const summary = await prompt( 'Summarise the reply (stored for reporting; keep it short and free of personal data).', { title: 'Log a reply', label: 'Summary', multiline: true, confirmText: 'Log reply' } );
                    if ( summary === null ) {
                        return;
                    }
                    await api.post( `/outreach/messages/${ m.id }/log-reply`, { summary } );
                    toast( 'Reply logged.', 'success' );
                    await refresh();
                } } ) );
            }
            if ( ! [ 'closed' ].includes( m.status ) ) {
                actions.push( button( 'Close thread', { kind: 'ghost', iconName: 'stop', onClick: async () => {
                    if ( ! ( await confirm( 'Close this thread? No follow-ups will be generated.', { confirmText: 'Close thread' } ) ) ) {
                        return;
                    }
                    await api.post( `/outreach/messages/${ m.id }/close`, {} );
                    toast( 'Thread closed.', 'success' );
                    await refresh();
                } } ) );
            }
            dr.setActions( actions );
            dr.setContent( stack );
        }
        await load();
    }

    // ------------------------------------------------------------------
    // Contacts
    // ------------------------------------------------------------------
    async function contactsTab( panel ) {
        const contactsList = listView( {
            fetch: ( p ) => api.get( '/outreach/contacts', p ),
            defaultSort: { key: 'id', dir: 'desc' },
            initial: { filters: { prospect_id: params.prospect_id || '', status: '', q: '' } },
            rowKey: ( row ) => row.id,
            empty: { title: 'No contacts', message: 'Contacts are extracted during evaluation or added from a prospect’s Contacts tab.', iconName: 'users' },
            filters: [
                { name: 'status', label: 'Status', type: 'select', options: CONTACT_STATUSES.map( ( s ) => ( { value: s, label: labelize( s ) } ) ), allLabel: 'All statuses' },
                { name: 'prospect_id', label: 'Prospect #', type: 'number', placeholder: 'ID', width: '90px', min: 1 },
                { name: 'q', label: 'Search', type: 'search', placeholder: 'Email or name…' },
            ],
            columns: [
                { key: 'prospect', label: 'Prospect', render: ( c ) => ( c.prospect_domain ? el( 'a', { href: ctx.pageUrl( 'prospects', { open: c.prospect_id } ) }, c.prospect_domain ) : `#${ c.prospect_id }` ) },
                { key: 'name', label: 'Name', render: ( c ) => c.name || null },
                { key: 'email', label: 'Email', sortable: true, render: ( c ) => el( 'span', { class: 'ppclg-mono' }, c.email ) },
                { key: 'role', label: 'Role', render: ( c ) => ( c.role ? labelize( c.role ) : null ) },
                { key: 'confidence', label: 'Confidence', align: 'right', render: ( c ) => ( c.confidence === null || c.confidence === undefined ? null : fmtNumber( c.confidence ) ) },
                { key: 'consent_basis', label: 'Consent basis', render: ( c ) => ( c.consent_basis ? labelize( c.consent_basis ) : null ) },
                { key: 'source_url', label: 'Source', render: ( c ) => ( c.source_url ? extLink( c.source_url, 'page' ) : null ) },
                { key: 'status', label: 'Status', sortable: true, render: ( c ) => {
                    const select = el( 'select', { class: 'ppclg-select ppclg-input--small', 'aria-label': `Status for ${ c.email }`, style: { width: 'auto', minWidth: '120px' } }, CONTACT_STATUSES.map( ( s ) => el( 'option', { value: s, selected: s === c.status }, labelize( s ) ) ) );
                    select.addEventListener( 'change', async () => {
                        const next = select.value;
                        if ( next !== 'active' && ! ( await confirm( `Mark ${ c.email } as ${ labelize( next ).toLowerCase() }? They will not be contacted again.`, { confirmText: 'Update' } ) ) ) {
                            select.value = c.status;
                            return;
                        }
                        try {
                            await api.post( `/outreach/contacts/${ c.id }/status`, { status: next } );
                            toast( 'Contact status updated.', 'success' );
                            contactsList.reload();
                        } catch ( e ) {
                            select.value = c.status;
                        }
                    } );
                    return select;
                } },
                { key: 'created_at', label: 'Added', render: ( c ) => timeEl( c.created_at ) },
            ],
        } );
        replace( panel, contactsList.el );
        contactsList.reload();
    }
}
