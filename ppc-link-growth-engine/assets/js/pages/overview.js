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

const LEGAL_NOTE = 'How the store presents itself is your legal decision as the owner, to be made with your Canadian regulatory counsel.';

/**
 * Minimal banner used when compliance-banner.js cannot be loaded (same facts, plain text only). Exported for tests.
 * A check that could not run (status.error, or blocking while the stored result is not "block") is shown whatever
 * the last stored result was: automation is paused then, and the owner must be able to see why.
 */
export function fallbackComplianceBanner( host, status ) {
    const st = asObject( status );
    const effective = String( st.effective || '' );
    const error = typeof st.error === 'string' ? st.error : '';
    const paused = st.blocking === true;
    if ( effective !== 'block' && ( paused || error !== '' ) ) {
        replace( host, callout(
            paused ? 'danger' : 'warning',
            paused ? 'Storefront check could not run: automated submissions and outreach sending are paused' : 'Storefront check could not run',
            [
                el( 'div', {}, error !== '' ? error : 'The last check could not be refreshed.' ),
                el( 'div', { class: 'ppclg-small' }, LEGAL_NOTE ),
            ],
        ) );
        return;
    }
    if ( effective !== 'block' && effective !== 'warn' ) {
        replace( host );
        return;
    }
    const counts = asObject( st.counts );
    replace( host, callout(
        paused ? 'danger' : 'warning',
        paused ? 'Automated submissions and outreach sending are paused by the storefront check' : 'Storefront wording to review',
        [
            el( 'div', {}, st.message ? String( st.message ) : `${ fmtNumber( Number( counts.block ) || 0 ) } blocking and ${ fmtNumber( Number( counts.warn ) || 0 ) } to review.` ),
            el( 'div', { class: 'ppclg-small' }, LEGAL_NOTE ),
        ],
    ) );
}

/**
 * Loads GET /compliance/storefront and renders the banner into `host` (nothing on 404: the check is not installed).
 * `onStatus( status )` receives the loaded status and every later answer of the banner's Re-check / Acknowledge
 * buttons, so the rest of the page can follow the live result.
 */
export async function loadComplianceBanner( host, { onStatus = null } = {} ) {
    const report = ( st ) => {
        if ( typeof onStatus === 'function' && st && typeof st === 'object' ) {
            try {
                onStatus( st );
            } catch ( e ) {
                // The banner itself already shows the status.
            }
        }
    };
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
    report( status );
    let renderBanner = null;
    try {
        const mod = await import( '../compliance-banner.js' );
        renderBanner = mod && typeof mod.default === 'function' ? mod.default : null;
    } catch ( e ) {
        renderBanner = null;
    }
    if ( renderBanner ) {
        try {
            renderBanner( host, status, { onRecheck: report, onAcknowledge: report } );
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

const GROW_EXPLAIN = 'Runs the whole growth routine once, in order: storefront and setup checks, on-site SEO audit, search-engine notification (IndexNow), prospect discovery and evaluation, listing packages, automatic submissions where the safety checks allow them, and outreach drafts. It never buys or hides links and never edits your site content unless you click Apply.';

/**
 * The panel subtitle: what one click may submit or send on its own, built from the current settings
 * (GET /growth/status `policy`). Without them it makes no promise either way. Exported for tests.
 *
 * @param {object|null} policy { submissions, submission_mode, outreach_autosend, require_approval }
 * @return {string}
 */
export function growPolicyText( policy ) {
    const p = asObject( policy );
    if ( typeof p.submissions !== 'boolean' ) {
        return 'One click runs the whole routine. What it submits or sends on its own follows your Automation and Outreach settings.';
    }
    let listings;
    if ( ! p.submissions ) {
        listings = 'Automated submissions are off (Automation → Submissions), so no listing is submitted';
    } else if ( p.submission_mode === 'auto' ) {
        listings = 'Listings to sites that pass every safety check are submitted automatically (Automation → Submissions is on, mode: auto)';
    } else {
        listings = `Listings are submitted automatically only to sites you set to “Always allow” (or with a partner API) that pass every safety check (Automation → Submissions, mode: ${ labelize( String( p.submission_mode || '' ) ).toLowerCase() || 'unknown' })`;
    }
    let emails;
    if ( typeof p.outreach_autosend !== 'boolean' ) {
        emails = 'outreach sending follows your Outreach settings';
    } else if ( p.outreach_autosend && p.require_approval === false ) {
        emails = 'outreach emails to qualified prospects are sent automatically, because outreach autosend is on and individual approval is off';
    } else {
        emails = 'outreach emails are sent only after your approval';
    }
    return `One click runs the whole routine. ${ listings }; ${ emails }.`;
}

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

/** Steps of a run that ended before reaching them (stopped by you or on an error): they never run. */
const NOT_RUN = { label: 'Not run', kind: 'neutral', iconName: 'more' };

/**
 * Status and summary a step shows. While a run is running, a step that has not started says "Waiting"; once the
 * run was stopped (by you or on an error) it never starts, so it says "Not run" with the reason instead.
 * Exported for tests.
 *
 * @param {object} step      A step of GET /growth/status.
 * @param {string} runStatus The run's status (running, completed, cancelled, failed).
 * @return {{status:string, meta:{label:string, kind:string, iconName:string}, summary:string}}
 */
export function stepDisplay( step, runStatus ) {
    const st = asObject( step );
    const status = STEP_STATUS[ st.status ] ? String( st.status ) : 'pending';
    const summary = st.summary ? String( st.summary ) : '';
    const stopped = runStatus === 'cancelled' || runStatus === 'failed';
    if ( stopped && ( status === 'pending' || status === 'running' ) ) {
        const why = runStatus === 'cancelled' ? 'the run was stopped' : 'the run stopped on an error';
        return {
            status: 'notrun',
            meta: NOT_RUN,
            summary: summary || ( status === 'running' ? `Not finished: ${ why } during this step.` : `Not run: ${ why } before this step.` ),
        };
    }
    return { status, meta: STEP_STATUS[ status ], summary };
}

function stepRow( step, runStatus ) {
    const st = asObject( step );
    const { status, meta, summary } = stepDisplay( st, runStatus );
    const rounds = Number( st.rounds ) || 0;
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

/** Storefront check results that no longer pause anything. */
const STOREFRONT_RESOLVED = [ 'pass', 'acknowledged' ];

/**
 * Whether a GET /compliance/storefront answer says nothing is paused any more: the check passes (or its findings
 * were acknowledged), it ran without an error and the gate does not block. Exported for tests.
 */
export function storefrontResolved( status ) {
    const st = asObject( status );
    return STOREFRONT_RESOLVED.includes( String( st.effective || '' ) ) && st.blocking !== true && ! st.error;
}

/**
 * The run's "Needs you" items that still apply: the storefront item is dropped once the live storefront check on
 * this page no longer pauses anything (see storefrontResolved) after the run. Exported for tests.
 *
 * @param {Array}   actions  run.next_actions (or run.needs_you).
 * @param {boolean} resolved Live storefront check result (false while unknown).
 * @return {Array}
 */
export function currentNeeds( actions, resolved ) {
    return asArray( actions ).filter( ( a ) => a && typeof a === 'object' && ! ( resolved === true && a.key === 'storefront' ) );
}

function needsYouList( actions, ctx, storefrontOk ) {
    const items = currentNeeds( actions, storefrontOk );
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

/** Value and hint of the SEO-issues tile: "not run yet" without a finished audit, "(partial)" while incomplete. */
function auditTile( audit ) {
    if ( ! audit ) {
        return { value: 'not available', hint: 'The site audit is not available' };
    }
    const noResult = audit.checked_at === null || audit.checked_at === undefined || audit.checked_at === '';
    if ( noResult && ! audit.running ) {
        return { value: 'not run yet', hint: 'The site audit has not finished a pass yet' };
    }
    const partial = audit.complete === false || ( noResult && audit.running );
    return { value: valueOr( audit.high, 'unknown' ), hint: `${ valueOr( audit.issues_total, 'unknown' ) } issues in total${ partial ? ' (partial)' : '' }` };
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
        statTile( { label: 'High-priority SEO issues', iconName: 'alert', ...auditTile( audit ), href: audit ? pageHref( ctx, 'seo', { severity: 'high' } ) : null } ),
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
    // The card is redrawn on every step answer; the live region stays in place so screen readers announce
    // progress (a freshly inserted live region is not announced), and keyboard focus is carried over.
    const cardHost = el( 'div', { class: 'ppclg-grow-card-host' } );
    const live = el( 'div', { class: 'ppclg-sr-only', role: 'status', 'aria-live': 'polite' } );
    const node = el( 'div', { class: 'ppclg-section ppclg-grow-panel' }, cardHost, live );
    const pollDelay = Number.isFinite( options.pollDelay ) ? options.pollDelay : 400;
    const waitDelay = Number.isFinite( options.waitDelay ) ? options.waitDelay : 3000;
    const retryDelay = Number.isFinite( options.retryDelay ) ? options.retryDelay : 1500;
    const s = { mode: 'loading', run: null, policy: null, busy: false, failures: 0, error: '', errorStatus: 0, errorCode: '', notice: '', elapsedBase: 0, elapsedAt: Date.now(), ticker: null, stoppedByUser: false, storefrontOk: false, onFinished: typeof options.onFinished === 'function' ? options.onFinished : null };
    let elapsedNode = null;

    const isRunning = () => s.busy || ( s.run && s.run.status === 'running' );

    function setRun( res ) {
        if ( res && typeof res === 'object' && res.policy && typeof res.policy === 'object' ) {
            s.policy = res.policy;
        }
        if ( res && typeof res === 'object' && Array.isArray( res.steps ) ) {
            s.run = res;
            s.elapsedBase = Number( res.elapsed_seconds ) || 0;
            s.elapsedAt = Date.now();
        }
    }

    function setError( e ) {
        s.mode = 'error';
        s.error = errorMessage( e );
        s.errorStatus = e && Number.isFinite( Number( e.status ) ) ? Number( e.status ) : 0;
        s.errorCode = e && e.code ? String( e.code ) : '';
    }

    /** 'session' (expired nonce: Resume and Stop cannot work), 'network' (no answer) or 'server' (an HTTP error). */
    function errorKind() {
        if ( s.errorStatus === 403 && ( s.errorCode === 'rest_cookie_invalid_nonce' || /nonce/i.test( s.errorCode ) ) ) {
            return 'session';
        }
        return s.errorStatus === 0 || s.errorCode === 'network' ? 'network' : 'server';
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
            namedProgressBar( done, total ),
            run && run.waiting && run.note ? el( 'div', { class: 'ppclg-muted ppclg-small' }, String( run.note ) ) : null,
            el( 'ol', { class: 'ppclg-list ppclg-grow-steps' }, steps.map( ( st ) => stepRow( st, run ? String( run.status || '' ) : '' ) ) ),
        );
    }

    function namedProgressBar( done, total ) {
        const bar = progressBar( done, total > 0 ? total : 1, { tone: 'good' } );
        bar.setAttribute( 'aria-label', 'Growth run progress' );
        bar.setAttribute( 'aria-valuetext', `${ done } of ${ total } steps finished` );
        return bar;
    }

    /** One sentence for the persistent live region (only set when it changes, so it is announced once). */
    function announce() {
        const run = s.run;
        const steps = asArray( run && run.steps );
        let message = '';
        if ( s.mode === 'error' ) {
            message = { session: 'Your session expired; reload the page.', network: 'Lost contact with the server.', server: 'The server reported an error.' }[ errorKind() ];
        } else if ( isRunning() && steps.length > 0 ) {
            const done = steps.filter( ( st ) => st && FINISHED_STEPS.includes( st.status ) ).length;
            const current = steps.find( ( st ) => st && st.status === 'running' ) || steps.find( ( st ) => st && st.status === 'pending' );
            message = `${ done } of ${ steps.length } steps finished${ current ? `, now: ${ String( current.label || labelize( current.key ) ) }` : '' }.`;
            if ( run.waiting && run.note ) {
                message += ` ${ String( run.note ) }`;
            }
        } else if ( run && run.status && run.status !== 'idle' && s.mode === 'idle' ) {
            message = `Growth run ${ ( RUN_STATUS[ run.status ] || { label: labelize( run.status ) } ).label.toLowerCase() }.`;
        }
        if ( live.textContent !== message ) {
            live.textContent = message;
        }
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
            el( 'div', {}, el( 'h4', {}, 'Needs you' ), needsYouList( actions, ctx, s.storefrontOk ) ),
            el( 'div', {}, el( 'h4', {}, 'What happened' ), details( recent ? 'Step by step' : 'Step by step (last run)', el( 'ol', { class: 'ppclg-list ppclg-grow-steps' }, asArray( run.steps ).map( ( st ) => stepRow( st, String( run.status || '' ) ) ) ), { open: recent } ) ),
        );
    }

    function draw() {
        const run = s.run;
        const status = run ? String( run.status || 'idle' ) : 'idle';
        const running = isRunning();
        const kind = s.mode === 'error' ? errorKind() : '';
        const actions = [];
        if ( kind === 'session' ) {
            // A dead nonce makes Resume and Stop fail the same way: only a reload helps.
            actions.push( button( 'Reload the page', { kind: 'primary', iconName: 'refresh', onClick: () => {
                window.location.reload();
            } } ) );
        } else if ( s.mode === 'error' ) {
            actions.push( button( 'Resume', { kind: 'primary', iconName: 'play', onClick: () => {
                loop();
            } } ) );
            if ( run && run.status === 'running' ) {
                // The run is still running on the server (the scheduler continues it): it must stay stoppable.
                actions.push( button( 'Stop', { small: true, iconName: 'stop', onClick: stopRun } ) );
            }
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
        if ( kind === 'session' ) {
            body.push( callout( 'danger', 'Your session expired', `${ s.error } Work done so far is saved, and the scheduler continues the run in the background. Reload the page to follow it again.` ) );
        } else if ( kind === 'network' ) {
            body.push( callout( 'danger', 'Lost contact with the server', `${ s.error } Work done so far is saved; click Resume to continue where it stopped.` ) );
        } else if ( kind === 'server' ) {
            const logs = pageHref( ctx, 'logs', {} );
            body.push( callout( 'danger', 'The server reported an error', [
                el( 'div', {}, `${ s.error } Work done so far is saved; click Resume to try again.` ),
                el( 'div', { class: 'ppclg-small' }, 'Details are in ', logs ? el( 'a', { href: logs }, 'Logs' ) : 'Logs', '.' ),
            ] ) );
        }
        if ( ! running && s.mode !== 'error' && s.mode !== 'loading' && run && [ 'completed', 'cancelled', 'failed' ].includes( status ) ) {
            if ( status === 'failed' && run.error ) {
                body.push( callout( 'danger', 'The run stopped on an error', String( run.error ) ) );
            }
            body.push( resultsView( run ) );
        }
        const focused = focusedLabel();
        replace( cardHost, card( {
            title: 'Grow your site',
            subtitle: growPolicyText( s.policy ),
            actions,
            body,
            className: 'ppclg-grow-card',
        } ) );
        refocus( focused );
        announce();
    }

    /** Label of the panel button that has keyboard focus (null when focus is elsewhere). */
    function focusedLabel() {
        const active = typeof document !== 'undefined' ? document.activeElement : null;
        if ( ! active || active.tagName !== 'BUTTON' || ! isInside( cardHost, active ) ) {
            return null;
        }
        return String( active.textContent || '' ).trim();
    }

    /** Puts focus back on the redrawn button with the same label, else on the panel's first button. */
    function refocus( label ) {
        if ( label === null ) {
            return;
        }
        const buttons = [];
        const walk = ( n ) => {
            if ( n.tagName === 'BUTTON' ) {
                buttons.push( n );
            }
            Array.from( n.childNodes || [] ).forEach( walk );
        };
        walk( cardHost );
        const usable = buttons.filter( ( b ) => ! b.disabled );
        const target = usable.find( ( b ) => String( b.textContent || '' ).trim() === label ) || usable[ 0 ];
        if ( target && typeof target.focus === 'function' ) {
            target.focus();
        }
    }

    async function loop() {
        if ( s.busy ) {
            return;
        }
        s.busy = true;
        s.mode = 'running';
        s.failures = 0;
        s.error = '';
        s.errorStatus = 0;
        s.errorCode = '';
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
                    const expired = e && e.status === 403 && /nonce/i.test( String( e.code || '' ) );
                    if ( s.failures > MAX_RETRIES || expired ) {
                        setError( e );
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
            setError( e );
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

    /** After a dialog closes, focus goes back to the panel when the button that opened it was redrawn meanwhile. */
    function refocusIfLost( label ) {
        const active = typeof document !== 'undefined' ? document.activeElement : null;
        if ( ! active || active === document.body || active.isConnected === false ) {
            refocus( label );
        }
    }

    async function stopRun() {
        const yes = await confirm( 'Stop the growth run? Steps already finished are kept; queued follow-up jobs of this run are cancelled, and a job that is already running finishes its current batch without continuing.', { title: 'Stop the growth run', confirmText: 'Stop run', danger: true } );
        if ( ! yes ) {
            refocusIfLost( 'Stop' );
            return;
        }
        let res;
        try {
            res = await api.post( '/growth/cancel', {} );
        } catch ( e ) {
            refocusIfLost( 'Stop' );
            return;
        }
        if ( res && res.ok ) {
            s.stoppedByUser = true;
            toast( String( res.message || 'Growth run stopped.' ), 'success' );
            if ( ! s.busy ) {
                // Stopped from the error view: the "lost contact" message no longer applies.
                s.mode = 'idle';
                s.error = '';
                s.errorStatus = 0;
                s.errorCode = '';
            }
        } else {
            toast( res && res.message ? String( res.message ) : 'The growth run could not be stopped.', 'warning' );
        }
        setRun( res );
        draw();
        refocusIfLost( 'Stop' );
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
        /** Live storefront check answer from the banner (a resolved check drops the storefront item from "Needs you"). */
        setStorefront: ( status ) => {
            const next = storefrontResolved( status );
            if ( next === s.storefrontOk ) {
                return;
            }
            s.storefrontOk = next;
            if ( ! s.busy && s.mode !== 'loading' ) {
                draw();
            }
        },
        setFinished: ( fn ) => {
            s.onFinished = typeof fn === 'function' ? fn : null;
        },
    };
}

/** Whether `n` is `container` or inside it (parentNode walk: also works where Node.contains() is missing). */
function isInside( container, n ) {
    for ( let cur = n; cur; cur = cur.parentNode ) {
        if ( cur === container ) {
            return true;
        }
    }
    return false;
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

/**
 * Label, tone and tooltip of an integration's health badge (exported for tests). An integration that is not set
 * up says "Not configured" (the server's note on what is missing becomes the tooltip); "Error" is kept for a
 * configured integration that reported a failure.
 *
 * @param {object|null} p Provider status from GET /overview `providers`.
 * @return {{label:string, kind:string, title:string}}
 */
export function providerBadgeState( p ) {
    if ( ! p || typeof p !== 'object' ) {
        return { label: 'Unknown', kind: 'neutral', title: '' };
    }
    const note = p.error ? String( p.error ) : '';
    if ( p.configured === false ) {
        return { label: 'Not configured', kind: 'warning', title: note };
    }
    if ( note !== '' ) {
        return { label: 'Error', kind: 'danger', title: note };
    }
    if ( p.configured && p.enabled !== false ) {
        return { label: 'Ready', kind: 'success', title: '' };
    }
    if ( p.configured ) {
        return { label: 'Disabled', kind: 'neutral', title: '' };
    }
    return { label: 'Not configured', kind: 'warning', title: '' };
}

function providerBadge( p ) {
    const st = providerBadgeState( p );
    return badge( st.label, st.kind, { dot: Boolean( p ), title: st.title } );
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
    loadComplianceBanner( bannerHost, { onStatus: ( st ) => {
        if ( growPanelInstance ) {
            growPanelInstance.setStorefront( st );
        }
    } } );
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
