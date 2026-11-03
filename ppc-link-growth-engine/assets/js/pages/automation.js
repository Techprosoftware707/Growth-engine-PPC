/**
 * Automation page: master switch, per-stage flags with confirmation gates,
 * scheduler status, limits, stage cards with "Run now", recent jobs.
 */

import * as api from '../api.js';
import {
    el, replace, badge, statusBadge, button, callout, kv, pre, loading, errorState, emptyState, timeEl, fmtNumber, fmtDate, fmtDuration, labelize, truncate,
    pageHeader, dataTable, confirm, toast, card, switchRow, meter, details, isObject, modal, formField,
} from '../ui.js';

const FLAGS = [
    { key: 'discovery', label: 'Discovery', desc: 'Search-engine queries, the seed catalog, competitor backlink mining and brand-mention scans to find new candidate sites. Bounded by the daily query limit.' },
    { key: 'evaluation', label: 'Evaluation', desc: 'Crawls due prospects (robots.txt aware, throttled), fetches provider metrics and recomputes the 100-point score.' },
    { key: 'opportunities', label: 'Opportunity finding', desc: 'Creates concrete placement opportunities for qualified sites and scans resource pages for broken outbound links.' },
    { key: 'packaging', label: 'Packaging', desc: 'Builds listing packages for open opportunities above the auto-queue score. Packages still wait for your review unless a site is explicitly allowed for automation.' },
    { key: 'submissions', label: 'Automated submissions', desc: 'Submits approved packages to directory forms or partner APIs without a manual click — only for prospects marked “automation allowed”, never through CAPTCHAs, logins or robots.txt-disallowed URLs.', gated: true, confirmText: 'Enable automated submissions? Only prospects you have individually marked “automation allowed” are affected, and every submission is logged in the audit trail.' },
    { key: 'outreach_autosend', label: 'Outreach autosend', desc: 'Sends auto-generated follow-ups that a campaign marked “auto-approve” without individual review. Initial messages always require approval.', gated: true, confirmText: 'Enable outreach autosend? Follow-up emails in campaigns with auto-approval will be sent inside the send window without a manual review.' },
    { key: 'monitoring', label: 'Backlink monitoring', desc: 'Re-checks known backlinks on their schedule, records anchor/rel/placement changes and alerts on losses.' },
    { key: 'imports', label: 'Provider imports', desc: 'Imports backlinks from SE Ranking / Ahrefs / Semrush and referral sources from GA4 on the configured interval.' },
];

const STAGES = {
    discover: { label: 'Discover', desc: 'Run search discovery and seeds.' },
    evaluate: { label: 'Evaluate', desc: 'Profile and score due prospects.' },
    find_opportunities: { label: 'Find opportunities', desc: 'Create opportunities for qualified prospects.' },
    package: { label: 'Package', desc: 'Build listing packages for open opportunities.' },
    submit: { label: 'Submit', desc: 'Process the approved submission queue (policy re-checked per item).' },
    verify_listings: { label: 'Verify listings', desc: 'Look for submitted listings going live.' },
    outreach_send: { label: 'Outreach send', desc: 'Send approved messages inside the send window.' },
    follow_ups: { label: 'Follow-ups', desc: 'Draft follow-ups for unanswered messages.' },
    monitor_backlinks: { label: 'Monitor backlinks', desc: 'Check backlinks that are due.' },
    import_backlinks: { label: 'Import backlinks', desc: 'Pull backlinks from providers and GA4.' },
    mention_scan: { label: 'Mention scan', desc: 'Find unlinked brand mentions.' },
    broken_link_scan: { label: 'Broken-link scan', desc: 'Scan resource pages for dead links to replace.' },
    cleanup: { label: 'Cleanup', desc: 'Prune logs, audit entries, old jobs and stale metrics.' },
    metrics_refresh: { label: 'Metrics refresh', desc: 'Refresh provider metrics for approved/acquired prospects.' },
};

const obj = ( v ) => ( isObject( v ) ? v : {} );
const arr = ( v ) => ( Array.isArray( v ) ? v : [] );

function normalizeStatus( res ) {
    const st = obj( res );
    const pipeline = isObject( st.pipeline ) ? st.pipeline : st;
    const scheduler = isObject( st.scheduler ) ? st.scheduler : { backend: st.backend, next_run: st.next_run, last_tick: st.last_tick, interval: st.interval, cron_disabled: st.cron_disabled };
    return {
        enabled: Boolean( pipeline.automation_enabled !== undefined ? pipeline.automation_enabled : st.automation_enabled ),
        flags: obj( pipeline.flags || st.flags ),
        limits: obj( pipeline.limits || st.limits ),
        stages: obj( pipeline.stages || st.stages ),
        stageRuns: obj( pipeline.stage_runs || st.stage_runs ),
        stageResults: obj( pipeline.stage_results || st.stage_results ),
        queue: obj( pipeline.queue || st.queue ),
        recent: arr( pipeline.recent_jobs || st.recent_jobs ),
        locked: Boolean( pipeline.locked ),
        lastTick: pipeline.last_tick || scheduler.last_tick || null,
        maxSeconds: pipeline.max_execution_seconds,
        tickInterval: pipeline.tick_interval || scheduler.interval,
        scheduler,
    };
}

function runTime( value ) {
    if ( value === null || value === undefined || value === '' || value === 0 ) {
        return null;
    }
    return value;
}

export default async function render( root, ctx ) {
    let autoRefresh = false;
    let timer = null;
    let status = null;

    const content = el( 'div' );
    const refreshToggle = formField( { type: 'checkbox', label: 'Auto-refresh every 30s', value: false, onChange: ( v ) => {
        autoRefresh = Boolean( v );
        schedule();
    } } );

    replace( root,
        pageHeader( {
            title: 'Automation',
            description: 'Background stages run on a schedule within the configured limits. Risky stages (automated submissions, outreach autosend) are off by default and ask for confirmation.',
            actions: [ refreshToggle.el, button( 'Refresh', { iconName: 'refresh', onClick: () => load() } ) ],
        } ),
        content,
    );
    replace( content, loading( 'Loading automation status…' ) );
    await load();

    function schedule() {
        clearTimeout( timer );
        if ( autoRefresh && root.isConnected ) {
            timer = setTimeout( async () => {
                if ( document.visibilityState === 'visible' ) {
                    await load( { quiet: true } );
                }
                schedule();
            }, 30000 );
        }
    }

    async function load( { quiet = false } = {} ) {
        try {
            status = normalizeStatus( await api.get( '/automation/status', {}, { silent: quiet } ) );
        } catch ( e ) {
            if ( ! quiet ) {
                replace( content, errorState( { title: 'Could not load automation status', message: e && e.message ? e.message : '', retry: () => load() } ) );
            }
            return;
        }
        ctx.shell.setAutomation( status.enabled );
        if ( quiet && document.querySelector( '.ppclg-modal-root' ) ) {
            return;
        }
        draw();
    }

    async function toggleFlag( key, value ) {
        const def = FLAGS.find( ( f ) => f.key === key );
        const body = { key, value: Boolean( value ) };
        if ( value && def && def.gated ) {
            if ( ! ( await confirm( def.confirmText, { title: `Enable ${ def.label.toLowerCase() }`, confirmText: 'Enable', danger: true } ) ) ) {
                return false;
            }
            body.confirm = true;
        }
        await api.post( '/automation/toggle', body );
        toast( `${ key === 'enabled' ? 'Automation' : ( def ? def.label : labelize( key ) ) } ${ value ? 'enabled' : 'disabled' }.`, 'success' );
        await load( { quiet: true } );
        return true;
    }

    function draw() {
        const s = status;
        const sched = s.scheduler || {};

        // Master + flags
        const masterCard = card( {
            title: 'Master switch',
            body: [
                switchRow( {
                    label: 'Run background automation',
                    description: 'When off, the scheduler still ticks but only runs jobs you start manually. Turn it on to let the stages below run on their cadence.',
                    checked: s.enabled,
                    badgeNode: s.enabled ? badge( 'On', 'success', { dot: true } ) : badge( 'Off', 'warning', { dot: true } ),
                    onChange: ( next ) => toggleFlag( 'enabled', next ),
                } ),
                ! s.enabled ? callout( 'info', null, 'Automation is off. Discovery, evaluation, monitoring and the queues will not advance on their own.' ) : null,
            ],
        } );

        const flagsCard = card( {
            title: 'Stages',
            subtitle: 'Each flag gates the matching scheduled stage',
            body: FLAGS.map( ( f ) => switchRow( {
                label: f.label,
                description: f.desc,
                checked: Boolean( s.flags[ f.key ] ),
                badgeNode: f.gated ? badge( 'Confirmation required', 'warning' ) : null,
                onChange: ( next ) => toggleFlag( f.key, next ),
            } ) ),
        } );

        // Scheduler
        const backendLabel = sched.backend === 'action_scheduler' ? 'Action Scheduler' : ( sched.backend === 'wp_cron' ? 'WP-Cron' : 'Not scheduled' );
        const schedulerCard = card( {
            title: 'Scheduler',
            actions: s.locked ? badge( 'Runner busy', 'info', { dot: true } ) : badge( 'Idle', 'neutral', { dot: true } ),
            body: [
                sched.backend === 'none' || ! sched.backend ? callout( 'danger', 'No tick is scheduled', 'The recurring tick could not be registered. Click “Kick” to re-schedule it; if the problem persists check that WP-Cron or Action Scheduler is working.' ) : null,
                sched.cron_disabled && sched.backend === 'wp_cron' ? callout( 'warning', 'DISABLE_WP_CRON is set', 'WP-Cron only runs if a system cron calls wp-cron.php. Action Scheduler (bundled with WooCommerce) is preferred when available.' ) : null,
                kv( [
                    [ 'Backend', backendLabel ],
                    [ 'Next run', sched.next_run ? el( 'span', {}, timeEl( sched.next_run ), ` (${ fmtDate( sched.next_run ) })` ) : '—' ],
                    [ 'Last tick', s.lastTick ? el( 'span', {}, timeEl( s.lastTick ), ` (${ fmtDate( s.lastTick ) })` ) : 'Never' ],
                    [ 'Tick interval', s.tickInterval ? fmtDuration( s.tickInterval ) : null ],
                    [ 'Max run per tick', s.maxSeconds ? `${ fmtNumber( s.maxSeconds ) } s (PHP limit is 30 s)` : null ],
                    [ 'Queue', Object.keys( s.queue ).length ? el( 'span', { class: 'ppclg-row' }, Object.entries( s.queue ).map( ( [ st, n ] ) => badge( `${ labelize( st ) } ${ fmtNumber( n ) }`, st === 'failed' ? 'danger' : ( st === 'running' ? 'info' : 'neutral' ) ) ) ) : 'Empty' ],
                ], { compact: true } ),
            ],
            footer: [
                button( 'Kick scheduler', { iconName: 'zap', title: 'Re-register the schedule and trigger an asynchronous tick', onClick: async () => {
                    const res = await api.post( '/automation/kick', {} );
                    toast( res === false || ( isObject( res ) && res.ok === false ) ? 'The scheduler could not be kicked — check the logs.' : 'Tick triggered in the background.', res === false ? 'warning' : 'success' );
                    setTimeout( () => load( { quiet: true } ), 3000 );
                } } ),
                button( 'Run tick now', { kind: 'primary', iconName: 'play', title: 'Enqueue due stages and run the job queue for one time slice', onClick: async () => {
                    const res = await api.post( '/automation/tick', {} );
                    const r = obj( res && ( res.tick || res.result ) ? ( res.tick || res.result ) : res );
                    const run = obj( r.run );
                    toast( `Tick finished: ${ arr( r.enqueued ).length } stage(s) enqueued, ${ fmtNumber( run.ran ) } job(s) ran (${ fmtNumber( run.completed ) } completed, ${ fmtNumber( run.failed ) } failed)${ r.skipped_reason ? ` — ${ r.skipped_reason }` : '' }.`, 'success', { timeout: 8000 } );
                    await load( { quiet: true } );
                } } ),
            ],
        } );

        // Limits
        const limitRows = Object.entries( s.limits ).map( ( [ key, l ] ) => ( { key, ...obj( l ) } ) );
        const limitsCard = card( {
            title: 'Rate limits',
            subtitle: 'Used vs. configured caps (edit under Settings → Automation & limits)',
            flush: true,
            body: dataTable( {
                dense: true,
                plain: true,
                rows: limitRows,
                empty: { title: 'No limits configured', compact: true },
                columns: [
                    { key: 'key', label: 'Limit', render: ( r ) => labelize( r.key ) },
                    { key: 'today', label: 'Today', render: ( r ) => meter( { label: '', used: r.used_today, limit: r.limit_today } ) },
                    { key: 'week', label: 'This week', render: ( r ) => meter( { label: '', used: r.used_week, limit: r.limit_week } ) },
                    { key: 'remaining', label: 'Remaining', align: 'right', render: ( r ) => ( Number( r.remaining ) < 0 ? '∞' : fmtNumber( r.remaining ) ) },
                ],
            } ),
        } );

        // Stage cards
        const stageIds = Object.keys( s.stages ).length ? Object.keys( s.stages ) : Object.keys( STAGES );
        const stageCards = stageIds.map( ( type ) => {
            const def = STAGES[ type ] || { label: labelize( type ), desc: '' };
            const meta = obj( s.stages[ type ] );
            const last = obj( s.stageResults[ type ] );
            const lastRun = runTime( s.stageRuns[ type ] );
            const flagKey = meta.flag || '';
            const flagOn = flagKey ? Boolean( s.flags[ flagKey ] ) : true;
            const resultHost = el( 'div' );
            if ( Object.keys( last ).length ) {
                resultHost.appendChild( details( el( 'span', { class: 'ppclg-row' }, 'Last result', statusBadge( last.status || 'completed', { dot: false } ), el( 'span', { class: 'ppclg-muted' }, last.finished_at ? timeEl( last.finished_at ) : '' ) ), pre( last.result !== undefined ? last.result : last ) ) );
            }
            return card( {
                className: 'ppclg-stage-card',
                title: def.label,
                actions: badge( `P${ meta.priority || '' }`.replace( /^P$/, 'Manual' ), 'neutral', { title: 'Priority (1 = highest)' } ),
                body: [
                    el( 'p', {}, def.desc ),
                    el( 'div', { class: 'ppclg-stage-meta' },
                        el( 'span', {}, `Cadence: ${ meta.cadence || 'manual' }` ),
                        flagKey ? el( 'span', {}, 'Flag: ', badge( `${ labelize( flagKey ) } ${ flagOn ? 'on' : 'off' }`, flagOn ? 'success' : 'warning' ) ) : null,
                        el( 'span', {}, 'Last run: ', lastRun ? timeEl( lastRun ) : 'never' ),
                    ),
                    resultHost,
                ],
                footer: [ button( 'Run now', { small: true, kind: 'primary', iconName: 'play', onClick: async () => {
                    if ( [ 'submit', 'outreach_send' ].includes( type ) && ! ( await confirm( `Run “${ def.label }” now? Approved items in the queue will be processed immediately (policy and compliance checks still apply).`, { confirmText: 'Run now' } ) ) ) {
                        return;
                    }
                    const res = await api.post( '/automation/run', { stage: type, options: {} } );
                    const r = obj( res );
                    if ( r.job_id && ( r.result === undefined || r.result === null || obj( r.result ).queued ) ) {
                        toast( `${ def.label } queued as job #${ r.job_id } (runner busy).`, 'info' );
                    } else {
                        toast( `${ def.label } finished.`, 'success' );
                        replace( resultHost, details( el( 'span', { class: 'ppclg-row' }, 'Result', badge( 'just now', 'success' ) ), pre( r.result !== undefined ? r.result : r ), { open: true } ) );
                    }
                    await load( { quiet: true } );
                } } ) ],
            } );
        } );

        // Recent jobs
        const cancelBtn = button( 'Cancel queued jobs', { kind: 'danger', small: true, iconName: 'stop', onClick: async () => {
            const typeField = formField( { type: 'select', label: 'Job type', options: [ { value: '', label: 'All queued jobs' } ].concat( stageIds.map( ( t ) => ( { value: t, label: ( STAGES[ t ] || { label: labelize( t ) } ).label } ) ) ) } );
            modal( {
                title: 'Cancel queued jobs',
                content: [ el( 'p', {}, 'Queued jobs are marked cancelled; running jobs finish their current slice.' ), typeField.el ],
                actions: [
                    { label: 'Keep jobs', onClick: ( close ) => close() },
                    { label: 'Cancel jobs', kind: 'danger', onClick: async ( close ) => {
                        const res = await api.post( '/automation/cancel', typeField.get() ? { type: typeField.get() } : {} );
                        const n = isObject( res ) ? ( res.cancelled ?? res.count ?? res.result ) : res;
                        toast( `${ fmtNumber( Number( n ) || 0 ) } job(s) cancelled.`, 'success' );
                        close();
                        await load( { quiet: true } );
                    } },
                ],
            } );
        } } );
        const jobsCard = card( {
            title: 'Recent jobs',
            subtitle: 'Last 20 queue entries',
            actions: cancelBtn,
            flush: true,
            body: dataTable( {
                dense: true,
                plain: true,
                rows: s.recent,
                empty: { title: 'No jobs yet', message: 'Jobs appear once a tick enqueues stages or you run a stage manually.', compact: true },
                expandable: ( j ) => el( 'div', { class: 'ppclg-stack' },
                    j.error ? callout( 'danger', 'Error', String( j.error ) ) : null,
                    el( 'div', { class: 'ppclg-grid ppclg-grid--2' },
                        el( 'div', {}, el( 'div', { class: 'ppclg-field-label' }, 'Payload' ), pre( j.payload || {} ) ),
                        el( 'div', {}, el( 'div', { class: 'ppclg-field-label' }, 'Result' ), pre( j.result || {} ) ),
                    ),
                ),
                columns: [
                    { key: 'id', label: '#', render: ( j ) => `#${ j.id }` },
                    { key: 'type', label: 'Type', render: ( j ) => ( STAGES[ j.type ] || { label: labelize( j.type ) } ).label },
                    { key: 'status', label: 'Status', render: ( j ) => statusBadge( j.status ) },
                    { key: 'priority', label: 'Prio', align: 'center', render: ( j ) => fmtNumber( j.priority ) },
                    { key: 'attempts', label: 'Attempts', align: 'center', render: ( j ) => `${ fmtNumber( j.attempts ) } / ${ fmtNumber( j.max_attempts ) }` },
                    { key: 'scheduled_at', label: 'Scheduled', render: ( j ) => timeEl( j.scheduled_at ) },
                    { key: 'started_at', label: 'Started', render: ( j ) => ( j.started_at ? timeEl( j.started_at ) : null ) },
                    { key: 'finished_at', label: 'Finished', render: ( j ) => ( j.finished_at ? timeEl( j.finished_at ) : null ) },
                    { key: 'error', label: 'Error', render: ( j ) => ( j.error ? el( 'span', { class: 'ppclg-small', style: { color: 'var(--ppclg-critical)' }, title: j.error }, truncate( j.error, 40 ) ) : null ) },
                ],
            } ),
        } );

        replace( content,
            el( 'div', { class: 'ppclg-grid ppclg-grid--main-side ppclg-section' },
                el( 'div', { class: 'ppclg-stack' }, masterCard, flagsCard ),
                el( 'div', { class: 'ppclg-stack' }, schedulerCard, limitsCard ),
            ),
            el( 'h3', { style: { margin: '0 0 12px', fontSize: '15px' } }, 'Stages' ),
            el( 'div', { class: 'ppclg-grid ppclg-grid--cards ppclg-section' }, stageCards ),
            jobsCard,
        );
    }
}
