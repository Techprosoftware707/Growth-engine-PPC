/**
 * Prospects page: filterable table, detail drawer (summary, opportunities, contacts,
 * submissions, outreach, backlinks, actions) and the "Add prospect" modal.
 */

import * as api from '../api.js';
import {
    el, replace, badge, statusBadge, button, callout, kv, pre, loading, errorState, emptyState, timeEl, fmtNumber, fmtCompact, fmtPercent, fmtDate,
    labelize, truncate, pageHeader, scoreRing, scoreClass, kindFor, drawer, tabs, dataTable, formField, formModal, confirm, prompt, toast, extLink,
    listView, progressBar, details, card, linkButton, copyButton, isObject,
} from '../ui.js';
import { reportRun } from './outcome.js';

const STATUSES = [ 'discovered', 'evaluating', 'qualified', 'approved', 'in_progress', 'acquired', 'rejected', 'on_hold', 'suppressed' ];
const CLASSES = { exceptional: 'Exceptional (90+)', high: 'High (80–89)', good: 'Good (70–79)', moderate: 'Moderate (50–69)', reject: 'Reject (< 50)' };
export const OPP_TYPES = {
    directory_listing: 'Business directory inclusion',
    resource_page: 'Resource page inclusion',
    editorial: 'Editorial mention',
    partnership: 'Industry partnership',
    expert_commentary: 'Expert commentary',
    educational_contribution: 'Educational contribution',
    broken_link: 'Broken-link replacement',
    unlinked_mention: 'Unlinked brand mention',
    podcast: 'Podcast / interview',
    membership: 'Association membership listing',
    supplier_listing: 'Supplier directory listing',
};
const SITE_TYPES = [ 'directory', 'supplier_directory', 'association', 'publication', 'media', 'blog', 'educational', 'resource_page', 'podcast', 'government', 'ecommerce', 'unknown' ];
const VIA = { search: 'Search discovery', seed: 'Seed catalog', competitor_backlinks: 'Competitor backlinks', mention: 'Brand mention', manual: 'Added manually' };
const COMPONENT_ORDER = [ 'topical_relevance', 'organic_traffic', 'editorial_quality', 'audience_engagement', 'authority', 'indexability_placement', 'risk' ];
const RISK_HINTS = {
    unreachable: 'The site could not be fetched.',
    parked_domain: 'Looks like a parked or placeholder domain.',
    spam_terms: 'Spam-associated vocabulary found on the page.',
    link_selling: 'Signals of paid link placements.',
    excessive_outbound_links: 'Very high number of outbound links.',
    outbound_density: 'Outbound links dominate the content.',
    pbn_footprint: 'Private blog network footprint detected.',
    hidden_links: 'Hidden links found in the markup.',
    hacked_keywords: 'Injected/hacked keyword patterns.',
    misuse_context: 'Content frames research reagents in a misuse context.',
    thin_content: 'Very little textual content.',
    noindex: 'The page is marked noindex.',
    ad_heavy: 'Heavy advertising density.',
    risky_tld: 'Top-level domain associated with abuse.',
    young_domain: 'Domain registered recently.',
    language_mismatch: 'Primary language differs from ours.',
    no_https: 'No HTTPS.',
};

const obj = ( v ) => ( isObject( v ) ? v : {} );
const arr = ( v ) => ( Array.isArray( v ) ? v : [] );

function oppLabel( type ) {
    return OPP_TYPES[ type ] || labelize( type || 'unknown' );
}

function unwrapIntake( res ) {
    if ( ! res || typeof res !== 'object' ) {
        return null;
    }
    if ( res.prospect_id !== undefined || res.skipped !== undefined ) {
        return res;
    }
    for ( const key of [ 'result', 'intake', 'data', 'prospect' ] ) {
        if ( isObject( res[ key ] ) && ( res[ key ].prospect_id !== undefined || res[ key ].id !== undefined ) ) {
            return res[ key ].prospect_id !== undefined ? res[ key ] : { prospect_id: res[ key ].id, created: true };
        }
    }
    return res;
}

function authorityCell( prospect ) {
    const m = obj( prospect.metrics );
    if ( m.authority === null || m.authority === undefined ) {
        return el( 'span', { class: 'ppclg-muted' }, '—' );
    }
    const labels = obj( m.authority_labels );
    const scores = obj( m.authority_scores );
    const sub = Object.keys( scores ).map( ( p ) => `${ labels[ p ] || labelize( p ) } ${ fmtNumber( scores[ p ] ) }` ).join( ' · ' );
    return el( 'span', {}, el( 'strong', {}, fmtNumber( m.authority ) ), sub ? el( 'span', { class: 'ppclg-cell-sub' }, sub ) : null );
}

const SUBMISSION_MODES = { auto: 'Auto', authorized_only: 'Authorized only', manual: 'Manual' };

/** Per-site automation setting (prospect.automation_allowed): -1 never automate, 0 inherit the mode, 1 always allow. */
export const AUTOMATION_SETTINGS = [
    { value: '0', label: 'Inherit (follow the submission mode)' },
    { value: '1', label: 'Always allow' },
    { value: '-1', label: 'Never automate' },
];

/** Normalised per-site setting, mirroring Prospects::automationSetting(): only 1 allows, any negative value never automates. */
export function automationSetting( p ) {
    const n = Number( isObject( p ) ? p.automation_allowed : 0 );
    if ( ! Number.isFinite( n ) ) {
        return 0;
    }
    return n < 0 ? -1 : ( n === 1 ? 1 : 0 );
}

/**
 * Effective automated-submission status of one prospect under the configured submission mode, mirroring
 * Policy::evaluate()'s authorisation step (the structural gates — form, CAPTCHA, login, robots.txt — still apply).
 */
export function submissionModeNotice( p, automation, hasApi = false ) {
    const a = isObject( automation ) ? automation : {};
    const mode = Object.prototype.hasOwnProperty.call( SUBMISSION_MODES, a.submission_mode ) ? a.submission_mode : 'auto';
    const modeLabel = `Submission mode: ${ SUBMISSION_MODES[ mode ] }`;
    const min = Number.isFinite( Number( a.min_score_auto_queue ) ) && a.min_score_auto_queue !== null && a.min_score_auto_queue !== '' ? Number( a.min_score_auto_queue ) : 70;
    const score = p.score === null || p.score === undefined || p.score === '' || ! Number.isFinite( Number( p.score ) ) ? null : Number( p.score );
    const setting = automationSetting( p );
    if ( a.submissions !== true ) {
        return callout( 'info', modeLabel, 'The global “Automated submissions” stage is off, so nothing is submitted to this site without you.' );
    }
    if ( [ 'on_hold', 'rejected', 'suppressed' ].includes( p.status ) ) {
        return callout( 'info', modeLabel, `This site is ${ labelize( p.status ).toLowerCase() }, so every automated stage skips it.` );
    }
    if ( setting === -1 ) {
        return callout( 'info', modeLabel, 'This site is set to “Never automate”: nothing is submitted to it automatically in any mode (a partner API included). Its packages are prepared for you to submit by hand.' );
    }
    if ( setting === 1 || hasApi ) {
        return callout( 'warning', modeLabel, `This site is authorised for automated submission (${ setting === 1 ? 'its per-site setting below is “Always allow”' : 'a partner API is configured' }).` );
    }
    if ( mode === 'auto' ) {
        if ( score !== null && score >= min ) {
            return callout( 'warning', modeLabel, `This site scores ${ score }, at or above the auto-queue threshold (${ min }), so the engine may submit to it automatically while its per-site setting below is “Inherit”. To keep it manual, choose “Never automate” below, or change the mode to “Authorized only” under Settings → Automation.` );
        }
        return callout( 'info', modeLabel, `Sites scoring ${ min } or more are submitted automatically while their per-site setting is “Inherit”. This site (${ score === null ? 'not scored yet' : `score ${ score }` }) does not qualify at the moment; it will once it reaches ${ min }. Choose “Never automate” below to keep it manual.` );
    }
    return callout( 'info', modeLabel, 'Only sites set to “Always allow” below (or with a partner API) are submitted automatically, so packages for this site wait for your approval.' );
}

export default async function render( root, ctx ) {
    const params = ctx.params || {};
    const initialFilters = {
        status: params.status || '',
        class: params.class || '',
        type: params.type || '',
        site_type: params.site_type || '',
        country: params.country || '',
        min_score: params.min_score || '',
        via: params.via || '',
        q: params.q || '',
    };

    const addBtn = button( 'Add prospect', { kind: 'primary', iconName: 'plus', onClick: () => addProspect() } );

    const list = listView( {
        fetch: ( p ) => api.get( '/prospects', p ),
        defaultSort: { key: 'score', dir: 'desc' },
        initial: { filters: initialFilters },
        onRowClick: ( row ) => openProspect( Number( row.id ) ),
        rowKey: ( row ) => row.id,
        empty: { title: 'No prospects match', message: 'Adjust the filters, run discovery from the Automation page, or add a prospect manually.', iconName: 'target' },
        filters: [
            { name: 'status', label: 'Status', type: 'select', options: STATUSES.map( ( s ) => ( { value: s, label: labelize( s ) } ) ), allLabel: 'All statuses' },
            { name: 'class', label: 'Class', type: 'select', options: CLASSES, allLabel: 'All classes' },
            { name: 'type', label: 'Opportunity', type: 'select', options: OPP_TYPES, allLabel: 'All types' },
            { name: 'site_type', label: 'Site type', type: 'select', options: SITE_TYPES.map( ( s ) => ( { value: s, label: labelize( s ) } ) ), allLabel: 'All site types' },
            { name: 'via', label: 'Found via', type: 'select', options: VIA, allLabel: 'Any source' },
            { name: 'country', label: 'Country', type: 'text', placeholder: 'CA', width: '90px' },
            { name: 'min_score', label: 'Min score', type: 'number', placeholder: '0', min: 0, max: 100, width: '100px' },
            { name: 'q', label: 'Search', type: 'search', placeholder: 'Domain or name…' },
        ],
        columns: [
            { key: 'domain', label: 'Prospect', sortable: true, render: ( r ) => el( 'span', {}, el( 'span', { class: 'ppclg-cell-title' }, r.name || r.domain ), el( 'span', { class: 'ppclg-cell-sub' }, r.domain ) ) },
            { key: 'score', label: 'Score', sortable: true, align: 'center', render: ( r ) => scoreRing( r.score, { size: 36, stroke: 4 } ) },
            { key: 'score_class', label: 'Class', render: ( r ) => ( r.score_class ? badge( labelize( r.score_class ), kindFor( r.score_class ) ) : null ) },
            { key: 'relevance', label: 'Relevance', sortable: true, align: 'right', render: ( r ) => ( r.relevance === null || r.relevance === undefined ? null : fmtNumber( r.relevance ) ) },
            { key: 'authority', label: 'Authority', render: authorityCell },
            { key: 'traffic', label: 'Est. traffic', align: 'right', render: ( r ) => ( obj( r.metrics ).organic_traffic === null || obj( r.metrics ).organic_traffic === undefined ? null : fmtCompact( obj( r.metrics ).organic_traffic ) ) },
            { key: 'opportunity_type', label: 'Opportunity', render: ( r ) => oppLabel( r.opportunity_type ) },
            { key: 'country', label: 'Country', sortable: true, render: ( r ) => ( r.country ? String( r.country ).toUpperCase() : null ) },
            { key: 'status', label: 'Status', sortable: true, render: ( r ) => statusBadge( r.status ) },
            { key: 'recommended_action', label: 'Recommended action', render: ( r ) => ( r.recommended_action ? el( 'span', { title: r.recommended_action }, truncate( r.recommended_action, 40 ) ) : null ) },
            { key: 'last_evaluated', label: 'Evaluated', sortable: true, render: ( r ) => ( r.last_evaluated ? timeEl( r.last_evaluated ) : el( 'span', { class: 'ppclg-muted' }, 'Pending' ) ) },
        ],
        toolbar: addBtn,
        onLoaded: ( { state } ) => ctx.setHash( { ...state.filters, open: '' } ),
    } );

    replace( root,
        pageHeader( { title: 'Prospects', description: 'Every discovered site, scored 0–100 for relevance, traffic, editorial quality, authority and risk. Open a row for the full profile and actions.' } ),
        list.el,
    );
    list.reload();

    if ( params.open ) {
        openProspect( Number( params.open ) );
    }

    // ------------------------------------------------------------------
    // Add prospect
    // ------------------------------------------------------------------
    async function addProspect() {
        await formModal( {
            title: 'Add a prospect',
            description: 'Paste any page or domain. It is fetched, profiled and scored like a discovered prospect; nothing is submitted automatically.',
            submitText: 'Add prospect',
            fields: [
                { type: 'url', name: 'url', label: 'URL', placeholder: 'https://example.ca/resources', required: true },
                { type: 'textarea', name: 'note', label: 'Note (optional)', placeholder: 'Why this site is interesting…', rows: 3 },
            ],
            onSubmit: async ( values ) => {
                const res = unwrapIntake( await api.post( '/prospects', { url: values.url, note: values.note || '' } ) );
                if ( res && res.skipped ) {
                    toast( `Skipped: ${ res.skipped }`, 'warning' );
                    return true;
                }
                toast( res && res.created === false ? 'Prospect already existed — opened.' : 'Prospect added. Evaluate it from the Actions tab.', 'success' );
                list.reload();
                if ( res && res.prospect_id ) {
                    openProspect( Number( res.prospect_id ) );
                }
                return true;
            },
        } );
    }

    // ------------------------------------------------------------------
    // Drawer
    // ------------------------------------------------------------------
    async function openProspect( id ) {
        if ( ! id ) {
            return;
        }
        const body = el( 'div', {}, loading( 'Loading prospect…', true ) );
        const dr = drawer( `Prospect #${ id }`, body, { width: 'wide', onClose: () => ctx.setHash( { ...list.state.filters, open: '' } ) } );
        ctx.setHash( { ...list.state.filters, open: id } );
        let activeTab = 'summary';

        async function load() {
            let data;
            try {
                data = await api.get( `/prospects/${ id }` );
            } catch ( e ) {
                dr.setContent( errorState( { title: 'Could not load this prospect', message: e && e.message ? e.message : '', retry: load } ) );
                return;
            }
            const prospect = obj( data.prospect ).id ? obj( data.prospect ) : obj( data );
            if ( ! prospect.id ) {
                dr.setContent( errorState( { title: 'Prospect not found' } ) );
                return;
            }
            dr.setTitle( prospect.name || prospect.domain );
            dr.setSubtitle( [
                extLink( prospect.homepage_url || `https://${ prospect.domain }/`, prospect.domain ),
                statusBadge( prospect.status ),
                prospect.score_class ? badge( labelize( prospect.score_class ), kindFor( prospect.score_class ) ) : null,
                prospect.site_type ? badge( labelize( prospect.site_type ), 'neutral' ) : null,
            ] );
            const refresh = async () => {
                list.reload();
                await load();
            };
            const view = tabs( {
                active: activeTab,
                onChange: ( id2 ) => {
                    activeTab = id2;
                },
                items: [
                    { id: 'summary', label: 'Summary', render: ( panel ) => panel.appendChild( summaryTab( prospect, data ) ) },
                    { id: 'opportunities', label: 'Opportunities', count: arr( data.opportunities ).length, render: ( panel ) => panel.appendChild( opportunitiesTab( prospect, arr( data.opportunities ), refresh ) ) },
                    { id: 'contacts', label: 'Contacts', count: arr( data.contacts ).length, render: ( panel ) => panel.appendChild( contactsTab( prospect, arr( data.contacts ), refresh ) ) },
                    { id: 'submissions', label: 'Submissions', count: arr( data.submissions ).length, render: ( panel ) => panel.appendChild( submissionsTab( prospect, arr( data.submissions ), refresh ) ) },
                    { id: 'outreach', label: 'Outreach', count: arr( data.messages ).length, render: ( panel ) => panel.appendChild( outreachTab( prospect, arr( data.messages ) ) ) },
                    { id: 'backlinks', label: 'Backlinks', count: arr( data.backlinks ).length, render: ( panel ) => panel.appendChild( backlinksTab( arr( data.backlinks ) ) ) },
                    { id: 'actions', label: 'Actions', render: ( panel ) => panel.appendChild( actionsTab( prospect, refresh, dr ) ) },
                ],
            } );
            dr.setContent( view.el );
        }
        await load();
    }

    // ------------------------------------------------------------------
    // Tabs
    // ------------------------------------------------------------------
    function summaryTab( p, data ) {
        const profile = obj( p.profile );
        const home = obj( profile.home );
        const breakdown = obj( p.score_breakdown );
        const components = obj( breakdown.components );
        const metrics = obj( p.metrics );
        const missingRaw = data.metrics_missing !== undefined && data.metrics_missing !== null ? data.metrics_missing : metrics.missing;
        const missingMap = Array.isArray( missingRaw ) ? Object.fromEntries( missingRaw.map( ( k ) => [ String( k ), '' ] ) ) : obj( missingRaw );
        const metricsMissing = Object.keys( missingMap ).filter( ( k ) => ! k.startsWith( '_' ) );
        const missingNote = typeof missingMap._providers === 'string' ? missingMap._providers : '';
        const risk = Array.isArray( p.risk_flags ) ? { flags: p.risk_flags, reasons: [] } : { flags: arr( obj( p.risk_flags ).flags ), reasons: arr( obj( p.risk_flags ).reasons ), score: obj( p.risk_flags ).score };
        const form = obj( profile.submission_form );
        const stack = el( 'div', { class: 'ppclg-stack' } );

        stack.appendChild( el( 'div', { class: 'ppclg-row ppclg-row--start', style: { gap: '18px' } },
            scoreRing( p.score, { size: 76, stroke: 7 } ),
            el( 'div', { class: 'ppclg-grow' },
                el( 'div', { class: 'ppclg-strong', style: { fontSize: '15px' } }, breakdown.class_label || ( p.score_class ? labelize( p.score_class ) : 'Not yet evaluated' ) ),
                el( 'p', { style: { margin: '2px 0 6px' } }, p.recommended_action || 'Run an evaluation to get a recommendation.' ),
                el( 'div', { class: 'ppclg-row' },
                    breakdown.confidence !== undefined ? badge( `Confidence ${ fmtNumber( breakdown.confidence ) }%`, 'neutral' ) : null,
                    p.relevance !== null && p.relevance !== undefined ? badge( `Relevance ${ fmtNumber( p.relevance ) }`, 'neutral' ) : null,
                    badge( oppLabel( p.opportunity_type ), 'brand' ),
                    automationSetting( p ) === 1 ? badge( 'Automation allowed', 'warning', { dot: true } ) : null,
                    automationSetting( p ) === -1 ? badge( 'Never automate', 'neutral' ) : null,
                ),
            ),
        ) );

        stack.appendChild( card( { title: 'Profile', body: kv( [
            [ 'Homepage', extLink( p.homepage_url || `https://${ p.domain }/` ) ],
            [ 'Submission page', p.submission_url ? extLink( p.submission_url, truncate( p.submission_url, 60 ) ) : null ],
            [ 'Contact page', p.contact_url ? extLink( p.contact_url, truncate( p.contact_url, 60 ) ) : null ],
            [ 'Site type', p.site_type ? labelize( p.site_type ) : null ],
            [ 'Found via', [ VIA[ p.discovered_via ] || labelize( p.discovered_via || '' ), p.discovery_query ? `“${ truncate( p.discovery_query, 80 ) }”` : '' ].filter( Boolean ).join( ' · ' ) ],
            [ 'Language / country', [ p.language ? String( p.language ).toUpperCase() : '', p.country ? `${ String( p.country ).toUpperCase() }${ profile.country_confidence ? ` (${ fmtNumber( profile.country_confidence ) }% confidence)` : '' }` : '' ].filter( Boolean ).join( ' · ' ) ],
            [ 'Domain age', profile.domain_age_years !== null && profile.domain_age_years !== undefined ? `${ fmtNumber( profile.domain_age_years, { decimals: 1 } ) } years${ profile.registration_date ? ` (registered ${ fmtDate( profile.registration_date, { time: false } ) })` : '' }` : null ],
            [ 'Last content update', profile.last_content_date ? fmtDate( profile.last_content_date, { time: false } ) : null ],
            [ 'HTTPS', profile.https === undefined ? null : ( profile.https ? 'Yes' : 'No' ) ],
            [ 'Technologies', arr( profile.technologies ).join( ', ' ) ],
            [ 'Pages analysed', profile.fetches !== undefined ? `${ fmtNumber( profile.fetches ) } fetched · ${ fmtNumber( arr( profile.pages ).length + ( profile.home ? 1 : 0 ) ) } analysed` : null ],
            [ 'Listing form', Object.keys( form ).length ? el( 'span', { class: 'ppclg-row' }, badge( labelize( form.kind || 'form' ), 'info' ), form.method ? badge( String( form.method ).toUpperCase(), 'neutral' ) : null, form.has_captcha ? badge( 'CAPTCHA', 'warning' ) : null, form.has_password ? badge( 'Login required', 'warning' ) : null, el( 'span', { class: 'ppclg-muted ppclg-small' }, `${ fmtNumber( Array.isArray( form.fields ) ? form.fields.length : ( Number( form.fields ) || 0 ) ) } fields` ) ) : el( 'span', { class: 'ppclg-muted' }, 'None detected' ) ],
            [ 'First seen', p.first_seen ? fmtDate( p.first_seen ) : null ],
            [ 'Last evaluated', p.last_evaluated ? fmtDate( p.last_evaluated ) : 'Not yet' ],
            [ 'Next review', p.next_review ? fmtDate( p.next_review, { time: false } ) : null ],
            [ 'Description', profile.description || home.metaDescription || null ],
            [ 'Home title', home.title || null ],
        ] ) } ) );

        const compRows = COMPONENT_ORDER.filter( ( k ) => components[ k ] ).concat( Object.keys( components ).filter( ( k ) => ! COMPONENT_ORDER.includes( k ) ) ).map( ( key ) => {
            const c = obj( components[ key ] );
            return el( 'div', { class: 'ppclg-score-bar-row' },
                el( 'div', {}, el( 'span', { class: 'ppclg-strong' }, labelize( key ) ), ' ', badge( `${ fmtNumber( c.weight ) }%`, 'neutral' ), el( 'span', { class: 'ppclg-score-source' }, [ c.source ? `Source: ${ c.source }` : '', c.known === false ? 'estimated' : '' ].filter( Boolean ).join( ' · ' ) ) ),
                progressBar( c.score, 100, { tone: c.known === false ? 'muted' : ( Number( c.score ) >= 70 ? 'good' : ( Number( c.score ) >= 40 ? 'warning' : 'critical' ) ) } ),
                el( 'div', { class: 'ppclg-right ppclg-strong' }, `${ fmtNumber( c.score ) }`, el( 'span', { class: 'ppclg-muted ppclg-small' }, ` → ${ fmtNumber( c.weighted, { decimals: 1 } ) }` ) ),
            );
        } );
        stack.appendChild( card( {
            title: 'Score breakdown',
            subtitle: breakdown.total !== undefined ? `${ fmtNumber( breakdown.total ) } / 100 · weights sum to 100 · scored ${ fmtDate( breakdown.scored_at ) }` : 'Weighted components of the 100-point score',
            body: compRows.length ? [ compRows, arr( breakdown.reasons ).length ? el( 'ul', { class: 'ppclg-list--bullets', style: { marginTop: '12px' } }, arr( breakdown.reasons ).map( ( r ) => el( 'li', {}, String( r ) ) ) ) : null ] : emptyState( { title: 'Not evaluated yet', message: 'Use “Evaluate now” under Actions.', compact: true } ),
        } ) );

        stack.appendChild( card( {
            title: 'Risk analysis',
            subtitle: risk.score !== undefined ? `Risk score ${ fmtNumber( risk.score ) } / 100 (lower is better)` : null,
            body: risk.flags.length ? [
                el( 'div', { class: 'ppclg-row' }, risk.flags.map( ( f ) => badge( labelize( f ), [ 'noindex', 'young_domain', 'language_mismatch', 'no_https', 'thin_content' ].includes( f ) ? 'warning' : 'danger', { title: RISK_HINTS[ f ] || '' } ) ) ),
                risk.reasons.length ? el( 'ul', { class: 'ppclg-list--bullets', style: { marginTop: '10px' } }, risk.reasons.map( ( r ) => el( 'li', {}, String( r ) ) ) ) : null,
            ] : el( 'span', { class: 'ppclg-row' }, badge( 'No risk flags', 'success', { dot: true } ) ),
        } ) );

        const providerRows = Object.entries( obj( metrics.providers ) ).map( ( [ pid, m ] ) => [ labelize( pid ), isObject( m ) ? Object.entries( m ).filter( ( [ k, v ] ) => k !== 'raw' && ( typeof v === 'number' || typeof v === 'string' ) ).slice( 0, 6 ).map( ( [ k, v ] ) => `${ labelize( k ) }: ${ typeof v === 'number' ? fmtNumber( v ) : v }` ).join( ' · ' ) : String( m ) ] );
        stack.appendChild( card( {
            title: 'Third-party metrics',
            subtitle: metrics.fetched_at ? `Fetched ${ fmtDate( metrics.fetched_at ) }` : 'No provider data yet',
            body: [
                kv( [
                    [ 'Authority', metrics.authority !== null && metrics.authority !== undefined ? `${ fmtNumber( metrics.authority ) } (${ Object.values( obj( metrics.authority_labels ) ).join( ', ' ) || 'blended' })` : null ],
                    [ 'Organic traffic', metrics.organic_traffic !== null && metrics.organic_traffic !== undefined ? `${ fmtNumber( metrics.organic_traffic ) } / month${ metrics.traffic_source ? ` via ${ metrics.traffic_source }` : '' }` : null ],
                    [ 'Organic keywords', metrics.organic_keywords !== null && metrics.organic_keywords !== undefined ? fmtNumber( metrics.organic_keywords ) : null ],
                    [ 'Referring domains', metrics.referring_domains !== null && metrics.referring_domains !== undefined ? fmtNumber( metrics.referring_domains ) : null ],
                    [ 'Backlinks', metrics.backlinks !== null && metrics.backlinks !== undefined ? `${ fmtNumber( metrics.backlinks ) }${ metrics.dofollow_backlinks !== null && metrics.dofollow_backlinks !== undefined ? ` (${ fmtNumber( metrics.dofollow_backlinks ) } dofollow)` : '' }` : null ],
                    [ 'Spam score', metrics.spam_score !== null && metrics.spam_score !== undefined ? fmtPercent( metrics.spam_score, 0 ) : null ],
                ].concat( providerRows ), { compact: true } ),
                metricsMissing.length || missingNote ? callout( 'info', null, [ metricsMissing.length ? `No data from: ${ metricsMissing.map( ( m ) => labelize( m ) ).join( ', ' ) }.` : '', missingNote, 'Configure providers under Settings → Integrations to improve confidence.' ].filter( Boolean ).join( ' ' ) ) : null,
            ],
        } ) );

        if ( p.notes ) {
            stack.appendChild( card( { title: 'Notes', body: el( 'div', { class: 'ppclg-pre ppclg-pre--plain' }, String( p.notes ) ) } ) );
        }
        if ( p.rejection_reason ) {
            stack.appendChild( callout( 'warning', 'Rejection reason', String( p.rejection_reason ) ) );
        }
        if ( home.h1 && arr( home.h1 ).length ) {
            stack.appendChild( details( 'Headings seen on the homepage', el( 'ul', { class: 'ppclg-list--bullets' }, arr( home.h1 ).concat( arr( home.h2 ) ).slice( 0, 12 ).map( ( h ) => el( 'li', {}, String( h ) ) ) ) ) );
        }
        return stack;
    }

    function opportunitiesTab( p, opportunities, refresh ) {
        const scanBtn = button( 'Scan page for broken links', { iconName: 'search', small: true, onClick: async () => {
            const res = await api.post( `/prospects/${ p.id }/find-opportunities`, {} );
            const r = obj( res && res.result ? res.result : res );
            toast( r.opportunities !== undefined ? `Scan finished: ${ fmtNumber( r.checked ) } links checked, ${ fmtNumber( r.broken ) } broken, ${ fmtNumber( r.opportunities ) } opportunities.` : ( res && res.job_id ? `Queued as job #${ res.job_id } (runner busy).` : 'Scan finished.' ), 'success' );
            refresh();
        } } );
        return el( 'div', { class: 'ppclg-stack' },
            el( 'div', { class: 'ppclg-row ppclg-row--between' }, el( 'p', { style: { margin: 0 } }, 'Concrete placements worth pursuing on this site. Packages and outreach drafts are built from these.' ), scanBtn ),
            dataTable( {
                dense: true,
                rows: opportunities,
                empty: { title: 'No opportunities yet', message: 'They are created by evaluation and the broken-link scan.', compact: true },
                columns: [
                    { key: 'type', label: 'Type', render: ( o ) => badge( oppLabel( o.type ), 'brand' ) },
                    { key: 'title', label: 'Placement', render: ( o ) => el( 'span', {}, el( 'span', { class: 'ppclg-cell-title' }, o.title || o.url ), o.url ? extLink( o.url, truncate( o.url, 60 ), { className: 'ppclg-cell-sub' } ) : null ) },
                    { key: 'status', label: 'Status', render: ( o ) => statusBadge( o.status ) },
                    { key: 'score', label: 'Score', align: 'right', render: ( o ) => ( o.score === null || o.score === undefined ? null : fmtNumber( o.score ) ) },
                    { key: 'details', label: 'Details', render: ( o ) => {
                        const d = obj( o.details );
                        const bits = [];
                        if ( d.broken_url ) {
                            bits.push( `Broken: ${ truncate( d.broken_url, 50 ) }` );
                        }
                        if ( d.anchor ) {
                            bits.push( `Anchor: ${ truncate( d.anchor, 40 ) }` );
                        }
                        if ( d.mention_snippet ) {
                            bits.push( `“${ truncate( d.mention_snippet, 80 ) }”` );
                        }
                        if ( d.suggested_url ) {
                            bits.push( `Suggest: ${ truncate( d.suggested_url, 50 ) }` );
                        }
                        return bits.length ? el( 'span', { class: 'ppclg-small' }, bits.join( ' · ' ) ) : null;
                    } },
                    { key: 'created_at', label: 'Created', render: ( o ) => timeEl( o.created_at ) },
                ],
            } ),
        );
    }

    function contactsTab( p, contacts, refresh ) {
        const addBtnEl = button( 'Add contact', { iconName: 'plus', small: true, onClick: () => formModal( {
            title: 'Add a contact',
            description: 'Only add addresses published for business enquiries on their site (CASL “conspicuous publication”). Personal addresses scraped elsewhere are not permitted.',
            submitText: 'Add contact',
            fields: [
                { type: 'email', name: 'email', label: 'Email', required: true },
                { type: 'text', name: 'name', label: 'Name' },
                { type: 'select', name: 'role', label: 'Role', options: { general: 'General enquiries', editor: 'Editor', partnerships: 'Partnerships', directory: 'Directory listings', person: 'Named person', webmaster: 'Webmaster' }, value: 'general' },
            ],
            onSubmit: async ( values ) => {
                await api.post( `/prospects/${ p.id }/contacts`, values );
                toast( 'Contact added.', 'success' );
                refresh();
                return true;
            },
        } ) } );
        return el( 'div', { class: 'ppclg-stack' },
            el( 'div', { class: 'ppclg-row ppclg-row--between' }, el( 'p', { style: { margin: 0 } }, 'Addresses found on the site with their consent basis. Opted-out or bounced contacts are never emailed.' ), addBtnEl ),
            dataTable( {
                dense: true,
                rows: contacts,
                empty: { title: 'No contacts found', message: 'Add one from their contact page if you have a legitimate business address.', compact: true },
                columns: [
                    { key: 'name', label: 'Name', render: ( c ) => c.name || null },
                    { key: 'email', label: 'Email', render: ( c ) => el( 'span', { class: 'ppclg-mono' }, c.email ) },
                    { key: 'role', label: 'Role', render: ( c ) => ( c.role ? labelize( c.role ) : null ) },
                    { key: 'confidence', label: 'Confidence', align: 'right', render: ( c ) => ( c.confidence === null || c.confidence === undefined ? null : fmtNumber( c.confidence ) ) },
                    { key: 'consent_basis', label: 'Consent basis', render: ( c ) => ( c.consent_basis ? labelize( c.consent_basis ) : null ) },
                    { key: 'source_url', label: 'Source', render: ( c ) => ( c.source_url ? extLink( c.source_url, 'page' ) : null ) },
                    { key: 'status', label: 'Status', render: ( c ) => {
                        const select = el( 'select', { class: 'ppclg-select ppclg-input--small', 'aria-label': `Status for ${ c.email }`, style: { width: 'auto', minWidth: '120px' } }, [ 'active', 'opted_out', 'bounced', 'suppressed', 'invalid' ].map( ( s ) => el( 'option', { value: s, selected: s === c.status }, labelize( s ) ) ) );
                        select.addEventListener( 'change', async () => {
                            const next = select.value;
                            if ( next !== 'active' && ! ( await confirm( `Mark ${ c.email } as ${ labelize( next ).toLowerCase() }? They will no longer receive outreach.`, { confirmText: 'Update status' } ) ) ) {
                                select.value = c.status;
                                return;
                            }
                            try {
                                await api.post( `/outreach/contacts/${ c.id }/status`, { status: next } );
                                toast( 'Contact status updated.', 'success' );
                                refresh();
                            } catch ( e ) {
                                select.value = c.status;
                            }
                        } );
                        return select;
                    } },
                ],
            } ),
        );
    }

    function submissionsTab( p, submissions, refresh ) {
        const packageBtn = button( 'Create submission package', { iconName: 'package', small: true, kind: 'primary', onClick: async () => {
            const res = await api.post( `/prospects/${ p.id }/package`, {} );
            const sid = res && ( res.submission_id || res.id || ( isObject( res.submission ) ? res.submission.id : null ) );
            toast( sid ? `Package #${ sid } prepared. Review it under Submissions.` : 'Package request processed.', 'success', { action: sid ? { label: 'Open', onClick: () => ctx.navigate( 'submissions', { open: sid } ) } : null } );
            refresh();
        } } );
        return el( 'div', { class: 'ppclg-stack' },
            el( 'div', { class: 'ppclg-row ppclg-row--between' }, el( 'p', { style: { margin: 0 } }, 'Listing packages built for this site. A package is submitted without your review only while “Automated submissions” is on and the site qualifies under the submission mode (see the Actions tab); in “auto” mode a site whose per-site automation is “Inherit” qualifies by itself once it scores at or above the auto-queue threshold, and “Never automate” keeps a site manual in every mode. Everything else waits for your approval.' ), packageBtn ),
            dataTable( {
                dense: true,
                rows: submissions,
                onRowClick: ( s ) => ctx.navigate( 'submissions', { open: s.id } ),
                empty: { title: 'No submissions yet', compact: true },
                columns: [
                    { key: 'id', label: '#', render: ( s ) => `#${ s.id }` },
                    { key: 'target_url', label: 'Target', render: ( s ) => extLink( s.target_url, truncate( s.target_url, 50 ) ) },
                    { key: 'method', label: 'Method', render: ( s ) => statusBadge( s.method, { dot: false } ) },
                    { key: 'status', label: 'Status', render: ( s ) => statusBadge( s.status ) },
                    { key: 'attempts', label: 'Attempts', align: 'right', render: ( s ) => fmtNumber( s.attempts ) },
                    { key: 'submitted_at', label: 'Submitted', render: ( s ) => ( s.submitted_at ? timeEl( s.submitted_at ) : null ) },
                    { key: 'listing_url', label: 'Listing', render: ( s ) => ( s.listing_url ? extLink( s.listing_url, 'view' ) : null ) },
                ],
            } ),
        );
    }

    function outreachTab( p, messages ) {
        return el( 'div', { class: 'ppclg-stack' },
            el( 'div', { class: 'ppclg-row ppclg-row--between' }, el( 'p', { style: { margin: 0 } }, 'Outreach messages drafted for this site. Create drafts from a campaign on the Outreach page.' ), linkButton( 'Open outreach', ctx.pageUrl( 'outreach', { tab: 'messages', prospect_id: p.id } ), { small: true, iconName: 'mail' } ) ),
            dataTable( {
                dense: true,
                rows: messages,
                onRowClick: ( m ) => ctx.navigate( 'outreach', { tab: 'messages', open: m.id } ),
                empty: { title: 'No outreach yet', compact: true },
                columns: [
                    { key: 'subject', label: 'Subject', render: ( m ) => el( 'span', { class: 'ppclg-cell-title' }, m.subject || '(no subject)' ) },
                    { key: 'approach', label: 'Approach', render: ( m ) => labelize( m.approach || '' ) },
                    { key: 'sequence', label: 'Seq.', align: 'center', render: ( m ) => fmtNumber( m.sequence ) },
                    { key: 'status', label: 'Status', render: ( m ) => statusBadge( m.status ) },
                    { key: 'sent_at', label: 'Sent', render: ( m ) => ( m.sent_at ? timeEl( m.sent_at ) : null ) },
                    { key: 'replied_at', label: 'Replied', render: ( m ) => ( m.replied_at ? timeEl( m.replied_at ) : null ) },
                ],
            } ),
        );
    }

    function backlinksTab( backlinks ) {
        return dataTable( {
            dense: true,
            rows: backlinks,
            onRowClick: ( b ) => ctx.navigate( 'backlinks', { open: b.id } ),
            empty: { title: 'No backlinks from this site yet', compact: true },
            columns: [
                { key: 'source_url', label: 'Source page', render: ( b ) => extLink( b.source_url, truncate( b.source_url, 60 ) ) },
                { key: 'anchor_text', label: 'Anchor', render: ( b ) => ( b.anchor_text ? truncate( b.anchor_text, 40 ) : el( 'span', { class: 'ppclg-muted' }, '(empty)' ) ) },
                { key: 'rel', label: 'Rel', render: ( b ) => el( 'span', { class: 'ppclg-row' }, String( b.rel || 'dofollow' ).split( ',' ).map( ( r ) => statusBadge( r.trim(), { dot: false } ) ) ) },
                { key: 'status', label: 'Status', render: ( b ) => statusBadge( b.status ) },
                { key: 'last_checked', label: 'Checked', render: ( b ) => ( b.last_checked ? timeEl( b.last_checked ) : null ) },
            ],
        } );
    }

    function actionsTab( p, refresh, dr ) {
        const stack = el( 'div', { class: 'ppclg-stack' } );

        // Evaluate
        stack.appendChild( card( {
            title: 'Evaluate',
            body: [
                el( 'p', {}, 'Fetches the site again, re-profiles it, refreshes provider metrics (within daily limits) and recomputes the score. Takes up to 25 seconds.' ),
                button( 'Evaluate now', { kind: 'primary', iconName: 'refresh', onClick: async () => {
                    const res = await api.post( `/prospects/${ p.id }/evaluate`, {} );
                    // Skipped (daily limit, suppressed), failed and already-queued runs are reported as such, not as "finished".
                    reportRun( res, 'Evaluation' );
                    await refresh();
                } } ),
            ],
        } ) );

        // Status
        const statusField = formField( { type: 'select', label: 'New status', options: [ 'approved', 'qualified', 'on_hold', 'in_progress', 'acquired', 'rejected' ].map( ( s ) => ( { value: s, label: labelize( s ) } ) ), value: p.status === 'approved' ? 'on_hold' : 'approved' } );
        const reasonField = formField( { type: 'text', label: 'Reason (recorded in the audit log)', placeholder: 'Optional for approvals; required for rejections' } );
        stack.appendChild( card( {
            title: 'Status',
            subtitle: `Currently ${ labelize( p.status ) }`,
            body: [
                el( 'div', { class: 'ppclg-form-grid' }, statusField.el, reasonField.el ),
                el( 'p', { class: 'ppclg-small', style: { marginTop: '8px' } }, 'Approved prospects can be packaged and queued; on-hold and rejected prospects are skipped by every automated stage.' ),
            ],
            footer: button( 'Update status', { kind: 'primary', onClick: async () => {
                const status = statusField.get();
                const reason = String( reasonField.get() || '' ).trim();
                if ( status === 'rejected' && reason === '' ) {
                    reasonField.setError( 'Give a reason for rejecting.' );
                    return;
                }
                if ( status === 'rejected' && ! ( await confirm( `Reject ${ p.domain }? It will be excluded from packaging and outreach.`, { danger: true, confirmText: 'Reject' } ) ) ) {
                    return;
                }
                await api.post( `/prospects/${ p.id }/status`, { status, reason } );
                toast( `Status set to ${ labelize( status ).toLowerCase() }.`, 'success' );
                await refresh();
            } } ),
        } ) );

        // Automation
        const cfg = obj( obj( p.submission_config ).api );
        // Per-site setting (Policy::evaluate): Inherit follows the submission mode, Always allow authorises the
        // site in every mode, Never automate refuses automation in every mode, "auto" and partner APIs included.
        let currentSetting = automationSetting( p );
        const settingToasts = {
            '-1': 'This site will never be submitted to automatically.',
            0: 'This site now follows the submission mode.',
            1: 'Automation always allowed for this site.',
        };
        const settingField = formField( {
            type: 'select',
            label: 'Per-site automation',
            options: AUTOMATION_SETTINGS,
            value: String( currentSetting ),
            full: true,
            hint: 'Inherit: the submission mode decides (in “Auto” mode, sites scoring at or above the auto-queue threshold are submitted automatically). Always allow: authorises this site in every mode, so the engine may POST the generated listing to its form without a manual review; only choose it for directories whose terms permit automated listings. Never automate: nothing is submitted to this site automatically in any mode; its packages are prepared for you to submit by hand.',
            onChange: async ( value ) => {
                const next = Number( value );
                if ( ! [ -1, 0, 1 ].includes( next ) || next === currentSetting ) {
                    return;
                }
                const reset = () => settingField.set( String( currentSetting ) );
                if ( next === 1 && ! ( await confirm( `Always allow automated submissions to ${ p.domain }? Confirm that their terms of service permit automated listing submissions.`, { title: 'Always allow automation', confirmText: 'Yes, allow', danger: true } ) ) ) {
                    reset();
                    return;
                }
                if ( next === 0 && currentSetting === -1 && ! ( await confirm( `Let ${ p.domain } follow the submission mode? In “Auto” mode a site scoring at or above the auto-queue threshold is submitted automatically.`, { title: 'Follow the submission mode', confirmText: 'Follow the mode' } ) ) ) {
                    reset();
                    return;
                }
                settingField.input.disabled = true;
                try {
                    await api.post( `/prospects/${ p.id }/automation`, { automation_allowed: next } );
                    currentSetting = next;
                    p.automation_allowed = next;
                    toast( settingToasts[ next ], 'success' );
                    drawMode();
                    list.reload();
                } catch ( e ) {
                    reset(); // api.js has already shown the error.
                } finally {
                    settingField.input.disabled = false;
                }
            },
        } );
        // What the setting actually means under the current submission mode (Policy::evaluate): in "auto"
        // mode a high enough score authorises an "Inherit" site by itself.
        const modeHost = el( 'div' );
        let automationCfg = null;
        const drawMode = () => {
            if ( automationCfg ) {
                replace( modeHost, submissionModeNotice( p, automationCfg, Boolean( cfg.endpoint ) ) );
            }
        };
        api.get( '/settings', {}, { silent: true } )
            .then( ( d ) => {
                automationCfg = obj( obj( obj( d ).settings ).automation );
                drawMode();
            } )
            .catch( () => replace( modeHost ) );
        const apiFields = [
            formField( { type: 'url', name: 'endpoint', label: 'API endpoint', value: cfg.endpoint || '', placeholder: 'https://partner.example/api/listings', full: true } ),
            formField( { type: 'select', name: 'method', label: 'Method', options: [ 'POST', 'PUT', 'PATCH' ], value: ( cfg.method || 'POST' ).toUpperCase() } ),
            formField( { type: 'text', name: 'success_path', label: 'Success path (dot path in JSON response)', value: cfg.success_path || '', placeholder: 'data.id' } ),
            formField( { type: 'text', name: 'listing_url_path', label: 'Listing URL path', value: cfg.listing_url_path || '', placeholder: 'data.url' } ),
            formField( { type: 'password', name: 'partner_api_key', label: 'Partner API key', placeholder: 'Leave blank to keep the stored key', autocomplete: 'new-password' } ),
            formField( { type: 'json', name: 'headers', label: 'Headers (JSON object)', value: isObject( cfg.headers ) ? cfg.headers : {}, rows: 3, full: true, hint: 'Use {{api_key}} inside values to insert the stored key.' } ),
            formField( { type: 'textarea', name: 'body_template', label: 'Body template (JSON with {{placeholders}})', value: cfg.body_template || '', rows: 6, mono: true, full: true, hint: 'Placeholders: {{business.name}}, {{business.website}}, {{business.email}}, {{content.description}}, {{content.short_description}}, {{api_key}} …' } ),
        ];
        const byName = Object.fromEntries( apiFields.map( ( f ) => [ f.name, f ] ) );
        stack.appendChild( card( {
            title: 'Automation & partner API',
            body: [
                callout( 'warning', 'Compliance gate', 'Nothing is submitted while the global “Automated submissions” switch is off. CAPTCHA, login walls and robots.txt disallow rules always stop automation regardless of this setting.' ),
                modeHost,
                settingField.el,
                details( 'Configured API (optional, for partners that offer a listing API)', el( 'div', { class: 'ppclg-stack' },
                    el( 'div', { class: 'ppclg-form-grid' }, apiFields.map( ( f ) => f.el ) ),
                    el( 'div', { class: 'ppclg-row ppclg-row--end' },
                        button( 'Clear API config', { kind: 'ghost', small: true, onClick: async () => {
                            if ( ! ( await confirm( 'Remove the configured API for this prospect?', { danger: true, confirmText: 'Remove' } ) ) ) {
                                return;
                            }
                            // automation_allowed is left out so the per-site setting is kept as it is.
                            await api.post( `/prospects/${ p.id }/automation`, { submission_config: null } );
                            toast( 'API configuration removed.', 'success' );
                            await refresh();
                        } } ),
                        button( 'Save API config', { kind: 'primary', small: true, onClick: async () => {
                            if ( ! byName.headers.valid() ) {
                                return;
                            }
                            const endpoint = String( byName.endpoint.get() || '' ).trim();
                            if ( endpoint === '' ) {
                                byName.endpoint.setError( 'An endpoint URL is required.' );
                                return;
                            }
                            // automation_allowed is left out so the per-site setting is kept as it is.
                            const payload = {
                                submission_config: { api: {
                                    endpoint,
                                    method: byName.method.get(),
                                    headers: byName.headers.get() || {},
                                    body_template: byName.body_template.get(),
                                    success_path: byName.success_path.get(),
                                    listing_url_path: byName.listing_url_path.get(),
                                } },
                            };
                            const key = String( byName.partner_api_key.get() || '' );
                            if ( key !== '' ) {
                                payload.partner_api_key = key;
                            }
                            await api.post( `/prospects/${ p.id }/automation`, payload );
                            toast( 'API configuration saved.', 'success' );
                            byName.partner_api_key.set( '' );
                            await refresh();
                        } } ),
                    ),
                ), { open: Boolean( cfg.endpoint ) } ),
            ],
        } ) );

        // Notes
        const notesField = formField( { type: 'textarea', label: 'Internal notes', value: p.notes || '', rows: 4, placeholder: 'Context for reviewers…' } );
        stack.appendChild( card( {
            title: 'Notes',
            body: notesField.el,
            footer: button( 'Save notes', { onClick: async () => {
                await api.post( `/prospects/${ p.id }/notes`, { notes: notesField.get() } );
                toast( 'Notes saved.', 'success' );
            } } ),
        } ) );

        // Danger zone
        stack.appendChild( card( {
            title: 'Danger zone',
            body: el( 'div', { class: 'ppclg-row' },
                button( 'Suppress domain', { kind: 'danger', iconName: 'shield', onClick: async () => {
                    const reason = await prompt( `Suppressing ${ p.domain } blocks it from discovery, packaging and outreach permanently (until removed from the suppression list).`, { title: 'Suppress domain', label: 'Reason', confirmText: 'Suppress', danger: true } );
                    if ( reason === null ) {
                        return;
                    }
                    await api.post( `/prospects/${ p.id }/suppress`, { reason } );
                    toast( 'Domain suppressed.', 'success' );
                    await refresh();
                } } ),
                button( 'Delete prospect', { kind: 'danger', iconName: 'trash', onClick: async () => {
                    if ( ! ( await confirm( `Delete ${ p.domain } with its opportunities, submissions, contacts and outreach messages? Backlinks and audit history are kept. This cannot be undone. A prospect that was already sent a submission or an email cannot be deleted (those records prevent a duplicate submission or a repeat contact); use “Suppress domain” instead.`, { danger: true, confirmText: 'Delete' } ) ) ) {
                        return;
                    }
                    try {
                        await api.del( `/prospects/${ p.id }` );
                    } catch ( e ) {
                        return; // api.js has already shown the reason (e.g. the prospect has sent history).
                    }
                    toast( 'Prospect deleted.', 'success' );
                    dr.close();
                    list.reload();
                } } ),
                copyButton( () => JSON.stringify( { domain: p.domain, score: p.score, status: p.status, recommended_action: p.recommended_action }, null, 2 ), 'Copy summary' ),
            ),
        } ) );

        return stack;
    }
}
