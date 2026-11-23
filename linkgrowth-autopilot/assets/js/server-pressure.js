/**
 * Server pressure (1.2.4): plain wording for the dashboard when background work is paused (Automation\LoadGuard)
 * and when PHP's code cache (OPcache) is critically full (Health\SiteHealthTests::warning()). Pure functions without DOM
 * access, so they can be tested under node; the pages render the result with ui.js helpers.
 */

const isObj = ( v ) => v !== null && typeof v === 'object' && ! Array.isArray( v );
const str = ( v ) => ( typeof v === 'string' ? v.trim() : '' );
const num = ( v ) => ( typeof v === 'number' && Number.isFinite( v ) ? v : null );
const fmt = ( v ) => String( Math.round( v * 100 ) / 100 );

/**
 * The measured numbers behind a load or memory pause (`paused.measured`), as one sentence, or ''.
 *
 * @param {object} paused
 * @return {string}
 */
function measuredLine( paused ) {
    const m = isObj( paused.measured ) ? paused.measured : null;
    if ( ! m ) {
        return '';
    }
    if ( paused.code === 'server_busy' ) {
        const load = num( m.load );
        const cpus = num( m.cpus );
        const perCpu = num( m.load_per_cpu );
        const max = num( m.max_load_per_cpu );
        if ( load === null || cpus === null || perCpu === null || max === null ) {
            return '';
        }
        const on = m.cpus_known === false ? '1 CPU (the CPU count could not be read, so it counts as 1)' : `${ cpus } ${ cpus === 1 ? 'CPU' : 'CPUs' }`;
        return `Load over the last minute: ${ fmt( load ) } on ${ on }, ${ fmt( perCpu ) } per CPU; background work waits while it is above ${ fmt( max ) } per CPU.`;
    }
    if ( paused.code === 'low_memory' ) {
        const available = num( m.available_mb );
        const total = num( m.total_mb );
        const percent = num( m.available_percent );
        const min = num( m.min_percent );
        if ( available === null || total === null || percent === null || min === null ) {
            return '';
        }
        return `Free memory: ${ fmt( available ) } MB of ${ fmt( total ) } MB (${ fmt( percent ) }%); background work waits while less than ${ fmt( min ) }% is free.`;
    }
    return '';
}

/**
 * The one-line pause text, e.g. "Paused: a backup is running (resumes automatically).", or '' when work runs.
 *
 * @param {object|null} paused Pipeline::status() `paused` ({code, message, text, until, since})
 * @return {string}
 */
export function pausedText( paused ) {
    if ( ! isObj( paused ) ) {
        return '';
    }
    const text = str( paused.text );
    if ( text !== '' ) {
        return text;
    }
    const message = str( paused.message );
    return message !== '' ? `Paused: ${ message.replace( /\.$/, '' ) } (resumes automatically).` : '';
}

/**
 * What the Automation page and the Overview card add under the pause line.
 *
 * @param {object|null} paused
 * @return {string}
 */
export function pausedDetail( paused ) {
    if ( ! isObj( paused ) ) {
        return '';
    }
    if ( paused.code === 'owner' ) {
        return 'A filter on this site (lga_pause_background) holds the scheduler tick, kicks and Grow now steps. Queued work waits; nothing is lost.';
    }
    const measured = measuredLine( paused );
    return `${ measured ? `${ measured } ` : '' }The scheduler tick, kicks and Grow now steps wait so the store gets the server first. Queued work, outreach (inside its send window) and limits are untouched; nothing is lost, only delayed. A stage you start with “Run now” still runs.`;
}

/**
 * The Overview notice for a critically full OPcache (GET /overview `opcache`), or null when there is none.
 *
 * @param {object|null} warning {level, title, summary, details, remedy: string[], memory, host}
 * @return {{title: string, summary: string, details: string, intro: string, settings: string[], memory: string, host: string}|null}
 */
export function opcacheNotice( warning ) {
    if ( ! isObj( warning ) || warning.level !== 'critical' ) {
        return null;
    }
    const remedy = Array.isArray( warning.remedy ) ? warning.remedy.map( str ).filter( Boolean ) : [];
    return {
        title: str( warning.title ) || 'PHP’s code cache (OPcache) is full',
        summary: str( warning.summary ),
        details: str( warning.details ),
        intro: remedy.length ? remedy[ 0 ] : '',
        settings: remedy.slice( 1 ),
        // Servers with less than 2 GB: "This server has 960 MB of memory; … more RAM is the lasting fix."
        memory: str( warning.memory ),
        host: str( warning.host ) || 'On managed hosting, ask your host to make this change.',
    };
}

/**
 * The wait before the next Grow now step after one that ran: the server's `pace_ms` (1.2.4: on a server with 1 or 2
 * CPUs a step is followed by a rest as long as the step, so a run does not hold the CPU and a PHP worker almost all
 * the time), at most 30 s, never shorter than the panel's own delay.
 *
 * @param {object|null} res      The /growth/step answer.
 * @param {number}      fallback The panel's delay in ms.
 * @return {number}
 */
export function paceDelay( res, fallback ) {
    const ms = isObj( res ) ? Number( res.pace_ms ) : NaN;
    if ( ! Number.isFinite( ms ) || ms <= 0 ) {
        return fallback;
    }
    return Math.max( fallback, Math.min( 30000, ms ) );
}

/**
 * How long the Grow now panel waits before it asks again while the run is paused (ms): the server's retry_after
 * (at most 5 minutes), else the normal waiting delay.
 *
 * @param {object} res      POST /growth/step answer
 * @param {number} fallback normal waiting delay (ms)
 * @return {number}
 */
export function pausedDelay( res, fallback ) {
    const seconds = isObj( res ) && isObj( res.paused ) ? Number( res.retry_after ) : NaN;
    if ( ! Number.isFinite( seconds ) || seconds <= 0 ) {
        return fallback;
    }
    return Math.max( fallback, Math.min( 300, seconds ) * 1000 );
}
