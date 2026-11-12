/**
 * Overview page: the "Grow your site" panel (one-click growth run) under the storefront compliance banner, then
 * hero stats, charts, integration health, automation status and notifications.
 */

import * as api from '../api.js';
import {
    el, replace, statTile, card, badge, button, linkButton, callout, kv, meter, loading, errorState, emptyState, timeEl, fmtNumber, fmtPercent, fmtCompact, labelize, pageHeader, statusBadge, truncate, fmtDate,
    icon, spinner, progressBar, details, fmtDuration, sleep, toast, confirm,
} from '../ui.js';
import { barChart, lineChart, histogram, segmentedBar } from '../charts.js';

// ---------------------------------------------------------------------
// Storefront compliance banner (assets/js/compliance-banner.js)
// ---------------------------------------------------------------------

const asArray = ( v ) => ( Array.isArray( v ) ? v : [] );
const asObject = ( v ) => ( v && typeof v === 'object' && ! Array.isArray( v ) ? v : {} );

function errorMessage( e ) {
    return e && e.message ? String( e.message ) : 'The request failed.';
}

/** Minimal banner used when compliance-banner.js cannot be loaded (same facts, plain text only). */
function fallbackComplianceBanner( host, status ) {
    const st = asObject( status );
    const effective = String( st.effective || '' );
    if ( effective !== 'block' && effective !== 'warn' ) {
        replace( host );
        return;
    }
    const counts = asObject( st.counts );
    const paused = Boolean( st.blocking );
    replace( host, callout(
        paused ? 'danger' : 'warning',
        paused ? 'Automated submissions and outreach sending are paused by the storefront check' : 'Storefront wording to review',
        [
            el( 'div', {}, st.message ? String( st.message ) : `${ fmtNumber( Number( counts.block ) || 0 ) } blocking and ${ fmtNumber( Number( counts.warn ) || 0 ) } to review.` ),
            el( 'div', { class: 'ppclg-small' }, 'How the store presents itself is your legal decision as the owner, to be made with your Canadian regulatory counsel.' ),
        ],
    ) );
}

/** Loads GET /compliance/storefront and renders the banner into `host` (nothing on 404: the check is not installed). */
export async function loadComplianceBanner( host ) {
    let status;
    try {
        status = await api.get( '/compliance/storefront', {}, { silent: true } );
    } catch ( e ) {
        if ( e && e.status === 404 ) {
            replace( host );
            return;
        }
        replace( host, callout( 'warning', 'The storefront compliance status could not be loaded', errorMessage( e ) ) );
        return;
    }
    let renderBanner = null;
    try {
        const mod = await import( '../compliance-banner.js' );
        renderBanner = mod && typeof mod.default === 'function' ? mod.default : null;
    } catch ( e ) {
        renderBanner = null;
    }
    if ( renderBanner ) {
        try {
            renderBanner( host, status, {} );
            return;
        } catch ( e ) {
            // Fall through to the plain banner.
        }
    }
    fallbackComplianceBanner( host, status );
}

// ---------------------------------------------------------------------
// "Grow your site" panel (POST /growth/start, then POST /growth/step until the run is no longer running)
// ---------------------------------------------------------------------

const GROW_EXPLAIN = 'Runs the whole growth routine once, in order: storefront and setup checks, on-site SEO audit, search-engine notification, prospect discovery and evaluation, listing packages, approved submissions and outreach drafts. It never buys or hides links, never emails anyone without your approval and never edits your site content unless you click Apply.';

const STEP_STATUS = {
    pending: { label: 'Waiting', kind: 'neutral', iconName: 'clock' },
    running: { label: 'Running', kind: 'info', iconName: 'play' },
    done: { label: 'Done', kind: 'success', iconName: 'check' },
    skipped: { label: 'Skipped', kind: 'neutral', iconName: 'more' },
    blocked: { label: 'Blocked', kind: 'warning', iconName: 'lock' },
    failed: { label: 'Failed', kind: 'danger', iconName: 'alert' },
};
const FINISHED_STEPS = [ 'done', 'skipped', 'blocked', 'failed' ];
const RUN_STATUS = {
    idle: { label: 'Never run', kind: 'neutral' },
    running: { label: 'Running', kind: 'info' },
    completed: { label: 'Completed', kind: 'success' },
    cancelled: { label: 'Stopped', kind: 'neutral' },
    failed: { label: 'Stopped on an error', kind: 'danger' },
};
/** Failed step requests are retried this many times before the panel offers "Resume". */
const MAX_RETRIES = 2;

function pageHref( ctx, page, params ) {
    const urls = ctx && ctx.config && ctx.config.pageUrls ? ctx.config.pageUrls : {};
    if ( ! page || typeof urls[ page ] !== 'string' || typeof ctx.pageUrl !== 'function' ) {
        return null;
    }
    return ctx.pageUrl( page, asObject( params ) );
}

function stepRow( step ) {
    const st = asObject( step );
    const status = STEP_STATUS[ st.status ] ? String( st.status ) : 'pending';
    const meta = STEP_STATUS[ status ];
    const rounds = Number( st.rounds ) || 0;
    const summary = st.summary ? String( st.summary ) : '';
    const error = st.error ? String( st.error ) : '';
    return el( 'li', { class: [ 'ppclg-grow-step', `ppclg-grow-step--${ status }` ] },
        el( 'span', { class: 'ppclg-grow-step-icon', title: meta.label }, status === 'running' ? spinner( true ) : icon( meta.iconName, 14 ) ),
        el( 'div', { class: 'ppclg-grow' },
            el( 'div', { class: 'ppclg-row' },
                el( 'strong', {}, String( st.label || labelize( st.key ) ) ),
                badge( meta.label, meta.kind ),
                rounds > 1 ? el( 'span', { class: 'ppclg-muted ppclg-small' }, `${ fmtNumber( rounds ) } rounds` ) : null,
            ),
            summary ? el( 'div', { class: 'ppclg-small' }, summary ) : null,
            st.note ? el( 'div', { class: 'ppclg-muted ppclg-small' }, String( st.note ) ) : null,
            status === 'failed' && error && ! summary.includes( error ) ? el( 'div', { class: 'ppclg-small' }, `Error: ${ error }` ) : null,
        ),
    );
}

function needsYouList( actions, ctx ) {
    const items = asArray( actions ).filter( ( a ) => a && typeof a === 'object' );
    if ( items.length === 0 ) {
        return el( 'p', { class: 'ppclg-muted' }, 'Nothing needs you right now.' );
    }
    return el( 'ul', { class: 'ppclg-list ppclg-list--bullets ppclg-grow-needs' }, items.map( ( a ) => {
        const label = String( a.label || labelize( a.key ) );
        const href = pageHref( ctx, String( a.page || '' ), a.params );
        const count = Number( a.count );
        return el( 'li', {},
            href ? el( 'a', { href }, label ) : el( 'span', {}, label ),
            Number.isFinite( count ) && count > 0 ? [ ' ', badge( fmtNumber( count ), 'warning' ) ] : null,
        );
    } ) );
}

function valueOr( value, missing ) {
    return value === null || value === undefined || ! Number.isFinite( Number( value ) ) ? missing : fmtNumber( value );
}

function headline( summary, ctx ) {
    const s = asObject( summary );
    const subs = s.submissions && typeof s.submissions === 'object' ? s.submissions : null;
    const prospects = s.prospects && typeof s.prospects === 'object' ? s.prospects : null;
    const backlinks = s.backlinks && typeof s.backlinks === 'object' ? s.backlinks : null;
    const audit = s.site_audit && typeof s.site_audit === 'object' ? s.site_audit : null;
    return el( 'div', { class: 'ppclg-grid ppclg-grid--tiles' },
        statTile( { label: 'Owner tasks', iconName: 'package', value: subs ? valueOr( subs.owner_tasks, 'unknown' ) : 'unknown', hint: 'Listings you submit yourself', href: pageHref( ctx, 'submissions', { tab: 'needs_manual' } ) } ),
        statTile( { label: 'Drafts to approve', iconName: 'mail', value: valueOr( s.outreach_drafts, 'unknown' ), hint: 'Nothing is sent without approval', href: pageHref( ctx, 'outreach', { tab: 'messages', status: 'draft' } ) } ),
        statTile( { label: 'Qualified prospects', iconName: 'target', value: prospects ? valueOr( prospects.qualified, 'unknown' ) : 'unknown', hint: prospects ? `${ valueOr( prospects.total, 'unknown' ) } prospects in total` : '', href: pageHref( ctx, 'prospects', { status: 'qualified' } ) } ),
        statTile( { label: 'Live backlinks', iconName: 'link', value: backlinks ? valueOr( backlinks.live, 'unknown' ) : 'unknown', hint: backlinks ? `${ valueOr( backlinks.lost, 'unknown' ) } lost` : '' } ),
        statTile( { label: 'High-priority SEO issues', iconName: 'alert', value: audit ? valueOr( audit.high, 'unknown' ) : 'not available', hint: audit ? `${ valueOr( audit.issues_total, 'unknown' ) } issues in total` : 'The site audit is not available', href: audit ? pageHref( ctx, 'seo', { severity: 'high' } ) : null } ),
        statTile( { label: 'Link suggestions', iconName: 'link', value: valueOr( s.link_suggestions_pending, 'not available' ), hint: 'Applied only when you click Apply', href: pageHref( ctx, 'seo', { links: 'pending' } ) } ),
    );
}

/**
 * Creates the panel (exported for tests; the page uses the per-page instance from growPanel()).
 *
 * @param {object} ctx Page context from app.js.
 * @param {{onFinished?: Function, pollDelay?: number, waitDelay?: number, retryDelay?: number}} options
 * @return {{node: Element, refresh: Function, setFinished: Function, isBusy: Function, ready: Promise}}
 */
export function createGrowPanel( ctx, options = {} ) {
    const node = el( 'div', { class: 'ppclg-section ppclg-grow-panel' } );
    const pollDelay = Number.isFinite( options.pollDelay ) ? options.pollDelay : 400;
    const waitDelay = Number.isFinite( options.waitDelay ) ? options.waitDelay : 3000;
    const retryDelay = Number.isFinite( options.retryDelay ) ? options.retryDelay : 1500;
    const s = { mode: 'loading', run: null, busy: false, failures: 0, error: '', notice: '', elapsedBase: 0, elapsedAt: Date.now(), ticker: null, stoppedByUser: false, onFinished: typeof options.onFinished === 'function' ? options.onFinished : null };
    let elapsedNode = null;

    const isRunning = () => s.busy || ( s.run && s.run.status === 'running' );

    function setRun( res ) {
        if ( res && typeof res === 'object' && Array.isArray( res.steps ) ) {
            s.run = res;
            s.elapsedBase = Number( res.elapsed_seconds ) || 0;
            s.elapsedAt = Date.now();
        }
    }

    function elapsedText() {
        const live = s.run && s.run.status === 'running' ? ( Date.now() - s.elapsedAt ) / 1000 : 0;
        return fmtDuration( s.elapsedBase + live );
    }

    function startTicker() {
        if ( s.ticker ) {
            return;
        }
        s.ticker = setInterval( () => {
            if ( elapsedNode ) {
                elapsedNode.textContent = elapsedText();
            }
            if ( ! s.busy ) {
                stopTicker();
            }
        }, 1000 );
        if ( s.ticker && typeof s.ticker.unref === 'function' ) {
            s.ticker.unref();
        }
    }

    function stopTicker() {
        if ( s.ticker ) {
            clearInterval( s.ticker );
            s.ticker = null;
        }
    }

    function progressView( run ) {
        const steps = asArray( run && run.steps );
        const total = steps.length;
        const done = steps.filter( ( st ) => st && FINISHED_STEPS.includes( st.status ) ).length;
        const current = steps.find( ( st ) => st && st.status === 'running' ) || steps.find( ( st ) => st && st.status === 'pending' );
        elapsedNode = el( 'span', { class: 'ppclg-grow-elapsed' }, elapsedText() );
        return el( 'div', { class: 'ppclg-stack' },
            el( 'div', { class: 'ppclg-row' },
                el( 'strong', {}, total > 0 ? `${ fmtNumber( done ) } of ${ fmtNumber( total ) } steps finished` : 'Starting…' ),
                current && isRunning() ? el( 'span', { class: 'ppclg-muted' }, `Now: ${ String( current.label || labelize( current.key ) ) }` ) : null,
                el( 'span', { class: 'ppclg-muted' }, 'Elapsed: ', elapsedNode ),
            ),
            progressBar( done, total > 0 ? total : 1, { tone: 'good' } ),
            run && run.waiting && run.note ? el( 'div', { class: 'ppclg-muted ppclg-small', role: 'status' }, String( run.note ) ) : null,
            el( 'ol', { class: 'ppclg-list ppclg-grow-steps', 'aria-live': 'polite' }, steps.map( stepRow ) ),
        );
    }

    function lastRunView( run ) {
        if ( ! run || ! run.id ) {
            return el( 'p', {}, 'No growth run yet. Click "Grow now" to run the whole routine once. It takes a few minutes; keep this page open to watch it, or close it and the scheduler finishes the run in the background.' );
        }
        const meta = RUN_STATUS[ run.status ] || { label: labelize( run.status ), kind: 'neutral' };
        const when = run.finished_at || run.updated_at || run.started_at;
        const summary = asObject( run.summary );
        return el( 'div', { class: 'ppclg-stack' },
            el( 'div', { class: 'ppclg-row' },
                el( 'span', {}, 'Last run: ', when ? timeEl( when ) : 'unknown' ),
                badge( meta.label, meta.kind ),
                el( 'span', { class: 'ppclg-muted' }, `took ${ fmtDuration( run.elapsed_seconds ) }` ),
            ),
            summary.computed_at ? headline( summary, ctx ) : el( 'p', { class: 'ppclg-muted' }, 'Headline numbers appear once a run reaches its summary step.' ),
        );
    }

    function resultsView( run ) {
        const finished = run.finished_at ? Date.parse( run.finished_at ) : NaN;
        const recent = Number.isFinite( finished ) && Date.now() - finished < 30 * 60 * 1000;
        const actions = asArray( run.next_actions ).length ? run.next_actions : run.needs_you;
        return el( 'div', { class: 'ppclg-grid ppclg-grid--2 ppclg-grow-results' },
            el( 'div', {}, el( 'h4', {}, 'Needs you' ), needsYouList( actions, ctx ) ),
            el( 'div', {}, el( 'h4', {}, 'What happened' ), details( recent ? 'Step by step' : 'Step by step (last run)', el( 'ol', { class: 'ppclg-list ppclg-grow-steps' }, asArray( run.steps ).map( stepRow ) ), { open: recent } ) ),
        );
    }

    function draw() {
        const run = s.run;
        const status = run ? String( run.status || 'idle' ) : 'idle';
        const running = isRunning();
        const actions = [];
        if ( s.mode === 'error' ) {
            actions.push( button( 'Resume', { kind: 'primary', iconName: 'play', onClick: () => {
                loop();
            } } ) );
        } else if ( running ) {
            actions.push( button( 'Stop', { small: true, iconName: 'stop', onClick: stopRun, disabled: status !== 'running' } ) );
        } else {
            actions.push( button( 'Grow now', { kind: 'primary', iconName: 'zap', onClick: startRun, disabled: s.mode === 'loading' } ) );
        }
        const body = [ el( 'p', { class: 'ppclg-muted ppclg-grow-explain' }, GROW_EXPLAIN ) ];
        if ( s.notice ) {
            body.push( callout( 'warning', 'The last growth run could not be loaded', s.notice ) );
        }
        if ( s.mode === 'loading' ) {
            body.push( loading( 'Checking the last growth run…', true ) );
        } else if ( running || s.mode === 'error' ) {
            body.push( progressView( run ) );
        } else {
            body.push( lastRunView( run ) );
        }
        if ( s.mode === 'error' ) {
            body.push( callout( 'danger', 'Lost contact with the server', `${ s.error } Work done so far is saved; click Resume to continue where it stopped.` ) );
        }
        if ( ! running && s.mode !== 'error' && s.mode !== 'loading' && run && [ 'completed', 'cancelled', 'failed' ].includes( status ) ) {
            if ( status === 'failed' && run.error ) {
                body.push( callout( 'danger', 'The run stopped on an error', String( run.error ) ) );
            }
            body.push( resultsView( run ) );
        }
        replace( node, card( {
            title: 'Grow your site',
            subtitle: 'One click runs the whole routine; anything that leaves your site still needs your approval.',
            actions,
            body,
            className: 'ppclg-grow-card',
        } ) );
    }

    async function loop() {
        if ( s.busy ) {
            return;
        }
        s.busy = true;
        s.mode = 'running';
        s.failures = 0;
        s.error = '';
        s.stoppedByUser = false;
        draw();
        startTicker();
        try {
            for ( ;; ) {
                let res;
                try {
                    res = await api.post( '/growth/step', {}, { silent: true } );
                } catch ( e ) {
                    s.failures += 1;
                    if ( s.failures > MAX_RETRIES ) {
                        s.mode = 'error';
                        s.error = errorMessage( e );
                        break;
                    }
                    await sleep( retryDelay * s.failures );
                    continue;
                }
                s.failures = 0;
                setRun( res );
                if ( ! res || typeof res !== 'object' || res.status !== 'running' ) {
                    s.mode = 'idle';
                    break;
                }
                draw();
                await sleep( res.waiting ? waitDelay : pollDelay );
            }
        } catch ( e ) {
            s.mode = 'error';
            s.error = errorMessage( e );
        } finally {
            s.busy = false;
            stopTicker();
            draw();
        }
        if ( s.mode !== 'idle' || ! s.run ) {
            return;
        }
        const status = s.run.status;
        if ( status === 'completed' ) {
            toast( 'Growth run finished. "Needs you" lists what to do next.', 'success' );
        } else if ( status === 'cancelled' && ! s.stoppedByUser ) {
            toast( 'The growth run was stopped.', 'info' );
        } else if ( status === 'failed' ) {
            toast( s.run.error ? String( s.run.error ) : 'The growth run stopped on an error.', 'error' );
        }
        if ( s.onFinished ) {
            try {
                s.onFinished( s.run );
            } catch ( e ) {
                // The panel already shows the result.
            }
        }
    }

    async function startRun() {
        let res;
        try {
            res = await api.post( '/growth/start', {} );
        } catch ( e ) {
            return; // api.js already reported the error.
        }
        if ( ! res || typeof res !== 'object' ) {
            toast( 'The growth run could not be started: the server sent no answer.', 'error' );
            return;
        }
        setRun( res );
        if ( res.ok === false || res.status !== 'running' ) {
            toast( res.message ? String( res.message ) : 'The growth run could not be started.', 'warning' );
            draw();
            return;
        }
        if ( res.message ) {
            toast( String( res.message ), res.already_running ? 'info' : 'success' );
        }
        s.notice = '';
        loop();
    }

    async function stopRun() {
        const yes = await confirm( 'Stop the growth run? Steps already finished are kept; queued follow-up jobs of this run are cancelled.', { title: 'Stop the growth run', confirmText: 'Stop run', danger: true } );
        if ( ! yes ) {
            return;
        }
        let res;
        try {
            res = await api.post( '/growth/cancel', {} );
        } catch ( e ) {
            return;
        }
        if ( res && res.ok ) {
            s.stoppedByUser = true;
            toast( String( res.message || 'Growth run stopped.' ), 'success' );
        } else {
            toast( res && res.message ? String( res.message ) : 'The growth run could not be stopped.', 'warning' );
        }
        setRun( res );
        draw();
    }

    async function refresh() {
        if ( s.busy ) {
            return;
        }
        let res;
        try {
            res = await api.get( '/growth/status', {}, { silent: true } );
        } catch ( e ) {
            s.mode = 'idle';
            s.notice = e && e.status === 404 ? 'The growth run endpoints are not available. Reload the page after updating the plugin.' : errorMessage( e );
            draw();
            return;
        }
        s.notice = '';
        setRun( res );
        if ( res && res.status === 'running' ) {
            loop(); // an active run (this tab was closed or reloaded) resumes automatically
            return;
        }
        s.mode = 'idle';
        draw();
    }

    draw();
    const ready = refresh();
    return {
        node,
        refresh,
        ready,
        isBusy: () => s.busy,
        setFinished: ( fn ) => {
            s.onFinished = typeof fn === 'function' ? fn : null;
        },
    };
}

/** One panel per page view: re-renders of the overview keep the same panel (and its running loop). */
let growPanelInstance = null;

function growPanel( ctx, onFinished ) {
    if ( growPanelInstance ) {
        growPanelInstance.setFinished( onFinished );
        growPanelInstance.refresh();
        return growPanelInstance.node;
    }
    growPanelInstance = createGrowPanel( ctx, { onFinished } );
    return growPanelInstance.node;
}

function providerBadge( p ) {
    if ( ! p ) {
        return badge( 'Unknown', 'neutral' );
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

function balanceRows( balance ) {
    if ( ! balance || typeof balance !== 'object' ) {
        return [];
    }
    const rows = [];
    for ( const [ key, value ] of Object.entries( balance ) ) {
        if ( value === null || typeof value === 'object' ) {
            continue;
        }
        rows.push( [ labelize( key ), typeof value === 'number' ? fmtNumber( value, { decimals: 2 } ) : String( value ) ] );
        if ( rows.length >= 8 ) {
            break;
        }
    }
    return rows;
}

export default async function render( root, ctx ) {
    const { config, shell } = ctx;
    // Compliance banner first, then the "Grow your site" panel; both stay in place while the rest reloads.
    const bannerHost = el( 'div', { class: 'ppclg-compliance-host' } );
    loadComplianceBanner( bannerHost );
    const grow = growPanel( ctx, () => {
        if ( root.isConnected ) {
            render( root, ctx ); // fresh numbers below once a run finishes
        }
    } );
    replace( root, pageHeader( { title: 'Overview', description: `Link acquisition health for ${ config.siteDomain || config.brand }.` } ), bannerHost, grow, loading( 'Loading overview…' ) );

    let data;
    try {
        data = await api.get( '/overview' );
    } catch ( e ) {
        replace( root, pageHeader( { title: 'Overview' } ), bannerHost, grow, errorState( { title: 'The overview could not be loaded', message: e && e.message ? e.message : '', retry: () => render( root, ctx ) } ) );
        return;
    }

    const stats = ( data && data.stats ) || {};
    const charts = ( data && data.charts ) || {};
    const notifications = Array.isArray( data && data.notifications ) ? data.notifications : [];
    const providers = ( data && data.providers ) || {};
    const automation = ( data && data.automation ) || {};
    const scheduler = ( data && data.scheduler ) || {};
    const limits = ( data && data.limits ) || automation.limits || {};
    const missing = Array.isArray( data && data.missing_integrations ) ? data.missing_integrations : [];
    const balance = data ? data.seranking_balance : null;

    if ( stats.unread_notifications !== undefined ) {
        shell.setUnread( stats.unread_notifications );
    }
    if ( automation.automation_enabled !== undefined ) {
        shell.setAutomation( automation.automation_enabled );
    }

    const settingsUrl = ctx.pageUrl( 'settings', { tab: 'integrations' } );
    const responseRate = stats.outreach_response_rate;

    // ----- Tiles ------------------------------------------------------
    const tiles = el( 'div', { class: 'ppclg-grid ppclg-grid--tiles ppclg-section' },
        statTile( { label: 'Prospects', iconName: 'target', value: fmtNumber( stats.prospects_total ), hint: `${ fmtNumber( stats.prospects_qualified ) } qualified · ${ fmtNumber( stats.prospects_approved ) } approved`, href: ctx.pageUrl( 'prospects' ) } ),
        statTile( { label: 'Active backlinks', iconName: 'link', value: fmtNumber( stats.backlinks_active ), delta: Number( stats.backlinks_new_30d ) > 0 ? { value: stats.backlinks_new_30d, label: `+${ fmtNumber( stats.backlinks_new_30d ) } in 30d`, good: true, direction: 'up' } : null, hint: `${ fmtNumber( stats.backlinks_lost ) } lost`, href: ctx.pageUrl( 'backlinks', { status: 'active' } ) } ),
        statTile( { label: 'Referring domains', iconName: 'globe', value: fmtNumber( stats.referring_domains ), hint: `${ fmtNumber( stats.quality_domains !== undefined ? stats.quality_domains : stats.high_authority_domains ) } with quality score ≥ 60`, href: ctx.pageUrl( 'backlinks' ) } ),
        statTile( { label: 'Submissions', iconName: 'package', value: fmtNumber( stats.submissions_submitted ), hint: `${ fmtNumber( stats.submissions_confirmed ) } confirmed live`, href: ctx.pageUrl( 'submissions' ) } ),
        statTile( { label: 'Outreach sent', iconName: 'mail', value: fmtNumber( stats.outreach_sent ), hint: `${ fmtNumber( stats.outreach_replied ) } replies · ${ responseRate === null || responseRate === undefined ? '—' : fmtPercent( responseRate ) } response rate`, href: ctx.pageUrl( 'outreach', { tab: 'messages' } ) } ),
        statTile( { label: 'Search clicks (28d)', iconName: 'search', value: stats.gsc_clicks_28d === null || stats.gsc_clicks_28d === undefined ? '—' : fmtCompact( stats.gsc_clicks_28d ), hint: stats.gsc_clicks_28d === null || stats.gsc_clicks_28d === undefined ? 'Search Console not connected' : `${ fmtCompact( stats.gsc_impressions_28d ) } impressions · via ${ stats.gsc_source || 'service account' }` } ),
        statTile( { label: 'Referral sessions (28d)', iconName: 'activity', value: stats.referral_sessions_28d === null || stats.referral_sessions_28d === undefined ? '—' : fmtCompact( stats.referral_sessions_28d ), hint: stats.referral_source ? `Source: ${ stats.referral_source === 'ga4' ? 'Google Analytics 4' : 'Site Kit' }` : 'Analytics not connected' } ),
        statTile( { label: 'Unread alerts', iconName: 'bell', value: fmtNumber( stats.unread_notifications ), hint: 'Open the bell to review', onClick: () => shell.openNotifications() } ),
    );

    // ----- Charts -----------------------------------------------------
    const trend = Array.isArray( charts.backlink_trend ) ? charts.backlink_trend : [];
    const trendChart = lineChart( {
        title: 'Backlinks: new vs. lost',
        subtitle: 'Weekly, last 12 weeks',
        series: [
            { name: 'New', points: trend.map( ( r ) => ( { x: r.week, y: Number( r.new ) || 0 } ) ) },
            { name: 'Lost', points: trend.map( ( r ) => ( { x: r.week, y: Number( r.lost ) || 0 } ) ) },
        ],
        xFormat: ( x ) => String( x ),
        emptyMessage: 'No backlink history yet — run monitoring or an import.',
    } );
    const histChart = histogram( {
        title: 'Prospect score distribution',
        subtitle: '0–100 opportunity score',
        data: Array.isArray( charts.score_histogram ) ? charts.score_histogram : [],
        emptyMessage: 'Scores appear once prospects have been evaluated.',
    } );
    const oppChart = barChart( {
        title: 'Opportunity types',
        data: ( Array.isArray( charts.opportunity_types ) ? charts.opportunity_types : [] ).map( ( r ) => ( { label: r.label || labelize( r.type ), value: r.count } ) ).sort( ( a, b ) => b.value - a.value ),
        emptyMessage: 'No opportunities recorded yet.',
    } );
    const statusChart = barChart( {
        title: 'Prospects by status',
        data: ( Array.isArray( charts.status_counts ) ? charts.status_counts : [] ).map( ( r ) => ( { label: labelize( r.status ), value: r.count } ) ),
        emptyMessage: 'No prospects yet — run discovery.',
    } );
    const anchorChart = barChart( {
        title: 'Anchor text distribution',
        subtitle: 'Top anchors across active backlinks',
        data: ( Array.isArray( charts.anchor_distribution ) ? charts.anchor_distribution : [] ).map( ( r ) => ( { label: r.anchor === '' || r.anchor === null ? '(empty / image)' : truncate( r.anchor, 40 ), value: r.count } ) ),
        maxBars: 10,
        emptyMessage: 'Anchor texts appear once backlinks are verified.',
    } );
    const relChart = segmentedBar( {
        title: 'Link attributes',
        subtitle: 'dofollow vs. nofollow / sponsored / ugc',
        parts: ( Array.isArray( charts.rel_distribution ) ? charts.rel_distribution : [] ).map( ( r ) => ( { label: labelize( r.rel || 'unknown' ), value: r.count } ) ),
        emptyMessage: 'No verified backlinks yet.',
    } );

    const chartGrid = el( 'div', { class: 'ppclg-grid ppclg-grid--2' },
        card( { body: trendChart } ),
        card( { body: histChart } ),
        card( { body: oppChart } ),
        card( { body: statusChart } ),
        card( { body: anchorChart } ),
        card( { body: relChart } ),
    );

    // ----- Side column ------------------------------------------------
    const providerRows = Object.entries( providers ).map( ( [ id, p ] ) => el( 'div', { class: 'ppclg-health-row' },
        el( 'div', { class: 'ppclg-health-name' }, String( ( p && p.label ) || labelize( id ) ), el( 'span', { class: 'ppclg-health-kind' }, [ labelize( ( p && p.kind ) || '' ), p && p.remaining_today !== undefined ? `${ fmtNumber( p.remaining_today ) } lookups left today` : '', p && p.error ? truncate( String( p.error ), 70 ) : '' ].filter( Boolean ).join( ' · ' ) ) ),
        providerBadge( p ),
        el( 'a', { href: settingsUrl, class: 'ppclg-small' }, 'Configure' ),
    ) );
    const healthCard = card( {
        title: 'Integration health',
        actions: linkButton( 'Settings', settingsUrl, { small: true, iconName: 'settings' } ),
        body: providerRows.length ? providerRows : emptyState( { title: 'No integrations reported', compact: true } ),
    } );

    const queue = automation.queue && typeof automation.queue === 'object' ? automation.queue : {};
    const automationCard = card( {
        title: 'Automation',
        actions: linkButton( 'Manage', ctx.pageUrl( 'automation' ), { small: true, iconName: 'zap' } ),
        body: [
            el( 'div', { class: 'ppclg-row', style: { marginBottom: '10px' } },
                automation.automation_enabled ? badge( 'Master switch on', 'success', { dot: true } ) : badge( 'Master switch off', 'warning', { dot: true } ),
                automation.locked ? badge( 'Runner busy', 'info', { dot: true } ) : null,
                scheduler.cron_disabled && scheduler.backend === 'wp_cron' ? badge( 'WP-Cron disabled', 'danger', { dot: true } ) : null,
            ),
            kv( [
                [ 'Scheduler', scheduler.backend === 'action_scheduler' ? 'Action Scheduler' : ( scheduler.backend === 'wp_cron' ? 'WP-Cron' : 'Not scheduled' ) ],
                [ 'Next run', scheduler.next_run ? timeEl( scheduler.next_run ) : '—' ],
                [ 'Last tick', automation.last_tick || scheduler.last_tick ? timeEl( automation.last_tick || scheduler.last_tick ) : 'Never' ],
                [ 'Queue', Object.keys( queue ).length ? el( 'span', { class: 'ppclg-row' }, Object.entries( queue ).map( ( [ s, n ] ) => badge( `${ labelize( s ) } ${ fmtNumber( n ) }`, s === 'failed' ? 'danger' : ( s === 'running' ? 'info' : 'neutral' ) ) ) ) : 'Empty' ],
            ], { compact: true } ),
        ],
    } );

    const limitRows = Object.entries( limits ).map( ( [ key, l ] ) => meter( { label: labelize( key ), used: l && l.used_today, limit: l && l.limit_today } ) );
    const limitsCard = card( { title: 'Daily limits', subtitle: 'Used today vs. configured caps', body: limitRows.length ? limitRows : emptyState( { title: 'No limits configured', compact: true } ) } );

    const balanceCard = balance ? card( { title: 'SE Ranking balance', subtitle: 'Cached hourly', body: balanceRows( balance ).length ? kv( balanceRows( balance ), { compact: true } ) : el( 'span', { class: 'ppclg-muted' }, 'Subscription details unavailable.' ) } ) : null;

    // ----- Notifications feed ----------------------------------------
    const feed = card( {
        title: 'Recent notifications',
        actions: [ button( 'Open all', { small: true, kind: 'ghost', iconName: 'bell', onClick: () => shell.openNotifications() } ) ],
        body: notifications.length ? el( 'div', { class: 'ppclg-list' }, notifications.slice( 0, 10 ).map( ( n ) => el( 'div', { class: [ 'ppclg-feed-item', Number( n.is_read ) === 0 ? 'ppclg-feed-item--unread' : '' ] },
            el( 'span', { class: [ 'ppclg-feed-dot', `ppclg-feed-dot--${ n.severity || 'info' }` ] } ),
            el( 'div', { class: 'ppclg-grow' },
                el( 'div', { class: 'ppclg-feed-title' }, String( n.title || n.type || 'Notification' ) ),
                n.message ? el( 'div', { class: 'ppclg-feed-msg' }, truncate( String( n.message ), 240 ) ) : null,
                el( 'div', { class: 'ppclg-feed-time' }, timeEl( n.created_at ), n.type ? [ ' · ', statusBadge( n.type, { dot: false } ) ] : null ),
            ),
        ) ) ) : emptyState( { title: 'No notifications yet', message: 'Alerts will appear here as the engine works.', iconName: 'bell', compact: true } ),
    } );

    // ----- Compose ----------------------------------------------------
    replace( root,
        pageHeader( {
            title: 'Overview',
            description: `Link acquisition health for ${ config.siteDomain || config.brand }. Data refreshed ${ fmtDate( new Date() ) }.`,
            actions: [ button( 'Refresh', { iconName: 'refresh', onClick: () => render( root, ctx ) } ) ],
        } ),
        bannerHost,
        grow,
        missing.length ? callout( 'warning', 'Some integrations are not configured', el( 'ul', {}, missing.map( ( m ) => el( 'li', {}, String( m ) ) ) ), { actions: linkButton( 'Configure integrations', settingsUrl, { small: true, kind: 'primary' } ) } ) : null,
        ! automation.automation_enabled ? callout( 'info', 'Automation is switched off', 'Discovery, evaluation, monitoring and the submission/outreach queues only run while the master switch is on. Manual actions still work.', { actions: linkButton( 'Open automation', ctx.pageUrl( 'automation' ), { small: true } ) } ) : null,
        tiles,
        el( 'div', { class: 'ppclg-grid ppclg-grid--main-side' },
            el( 'div', { class: 'ppclg-stack' }, chartGrid, feed ),
            el( 'div', { class: 'ppclg-stack' }, healthCard, automationCard, limitsCard, balanceCard ),
        ),
    );
}
