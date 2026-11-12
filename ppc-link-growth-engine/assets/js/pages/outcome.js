/**
 * Shared reading of "run" and action responses for the page modules (not a page itself).
 *
 * Pipeline::runNow() wrappers (/prospects/{id}/evaluate, /backlinks/import, /automation/run) and the
 * submission / campaign action routes answer HTTP 200 even when the work was refused, skipped,
 * queued behind a busy runner or finished with errors. A page must look at ok, queued, skipped and
 * errors before it tells the owner that something ran.
 */

import { isObject, toast } from '../ui.js';

/** Human wording for the job skip reasons (AbstractJob::skipped / deferred). */
const SKIP_REASONS = {
    evaluation_limit_reached: 'the daily evaluation limit is used up',
    evaluation_disabled: 'the Evaluation stage is switched off',
    prospect_not_found: 'the prospect no longer exists',
    prospect_suppressed: 'the domain is suppressed',
    imports_disabled: 'provider imports are switched off',
    not_due: 'it is not due yet',
    time_budget: 'there was not enough time left in this run; it was queued again for the next tick',
};

const firstText = ( ...values ) => {
    for ( const v of values ) {
        if ( typeof v === 'string' && v.trim() !== '' ) {
            return v.trim();
        }
    }
    return '';
};

const plural = ( n, word ) => `${ n } ${ word }${ n === 1 ? '' : 's' }`;

/** At most three problems in a toast; the rest are counted. */
const listProblems = ( problems ) => ( problems.length > 3 ? `${ problems.slice( 0, 3 ).join( ' · ' ) } · and ${ problems.length - 3 } more` : problems.join( ' · ' ) );

/** Problems reported inside a finished job result: errors[] strings, failed items, error counts, warnings[]. */
export function resultProblems( r ) {
    const out = [];
    const add = ( s ) => {
        const t = firstText( s );
        if ( t !== '' && ! out.includes( t ) ) {
            out.push( t );
        }
    };
    if ( ! isObject( r ) ) {
        return out;
    }
    if ( Array.isArray( r.errors ) ) {
        r.errors.forEach( ( e ) => add( typeof e === 'string' ? e : ( isObject( e ) ? firstText( e.message, e.error ) : '' ) ) );
    }
    const failedItems = Array.isArray( r.items ) ? r.items.filter( ( i ) => isObject( i ) && ( i.outcome === 'error' || ( typeof i.error === 'string' && i.error !== '' ) ) ) : [];
    failedItems.forEach( ( i ) => add( i.domain ? `${ i.domain }: ${ firstText( i.error, 'error' ) }` : firstText( i.error, 'error' ) ) );
    if ( typeof r.errors === 'number' && r.errors > failedItems.length ) {
        add( `${ plural( r.errors, 'error' ) } (see Logs)` );
    }
    if ( Number( r.limited ) > 0 ) {
        add( `${ plural( Number( r.limited ), 'item' ) } not processed: the daily limit is used up` );
    }
    if ( Array.isArray( r.warnings ) ) {
        r.warnings.forEach( ( w ) => add( typeof w === 'string' ? w : '' ) );
    }
    return out;
}

/**
 * Classifies a run response.
 *
 * @param {*} res    Response body ({ok, result, job_id, message} envelope or a bare job result).
 * @param {string} label  What ran, e.g. "Evaluation".
 * @return {{state: 'failed'|'queued'|'skipped'|'finished', kind: string, message: string, problems: string[], result: object, jobId: *}}
 */
export function runOutcome( res, label ) {
    const top = isObject( res ) ? res : {};
    const r = isObject( top.result ) ? top.result : top;
    const jobId = top.job_id || r.job_id || null;
    if ( res === false || top.ok === false || r.ok === false ) {
        const why = firstText( top.message, r.error, r.message, top.error );
        return { state: 'failed', kind: 'error', message: `${ label } failed${ why ? `: ${ why }` : '.' }`, problems: why ? [ why ] : [], result: r, jobId };
    }
    if ( r.queued === true ) {
        return {
            state: 'queued',
            kind: 'info',
            message: jobId ? `${ label } queued as job #${ jobId } (runner busy).` : `${ label } is already queued and runs on the next tick (runner busy).`,
            problems: [],
            result: r,
            jobId,
        };
    }
    if ( r.skipped === true ) {
        const reason = SKIP_REASONS[ r.reason ] || firstText( r.message, String( r.reason || '' ).replace( /_/g, ' ' ), 'nothing to do' );
        return { state: 'skipped', kind: 'warning', message: `${ label } did not run: ${ reason }.`, problems: [ reason ], result: r, jobId };
    }
    const problems = resultProblems( r );
    return {
        state: 'finished',
        kind: problems.length ? 'warning' : 'success',
        message: problems.length ? `${ label } finished with problems: ${ listProblems( problems ) }` : `${ label } finished.`,
        problems,
        result: r,
        jobId,
    };
}

/**
 * Toasts a run response truthfully and returns the outcome.
 * summary(result) may return a short text appended to the "finished" message.
 */
export function reportRun( res, label, { summary = null, timeout = 7000 } = {} ) {
    const outcome = runOutcome( res, label );
    if ( outcome.state === 'finished' && typeof summary === 'function' ) {
        const text = firstText( summary( outcome.result ) );
        if ( text !== '' ) {
            outcome.message = outcome.problems.length
                ? `${ label } finished: ${ text }. Problems: ${ listProblems( outcome.problems ) }`
                : `${ label } finished: ${ text }.`;
        }
    }
    toast( outcome.message, outcome.kind, { timeout } );
    return outcome;
}

/**
 * Toasts an action response ({ok, message}) and returns whether the server accepted it.
 * A refused action (ok: false) shows the server's reason instead of the success text.
 */
export function reportAction( res, successText, { refusedText = 'The action was not carried out.', timeout = 7000 } = {} ) {
    const body = isObject( res ) ? res : {};
    if ( res === false || body.ok === false ) {
        toast( firstText( body.message, body.error, refusedText ), 'error', { timeout } );
        return false;
    }
    toast( firstText( body.message, successText ), 'success', { timeout } );
    return true;
}
