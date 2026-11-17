/**
 * Server pressure (1.2.4): plain wording for the dashboard when background work is paused (Automation\LoadGuard)
 * and when PHP's code cache (OPcache) is critically full (Admin\SiteHealth::warning()). Pure functions without DOM
 * access, so they can be tested under node; the pages render the result with ui.js helpers.
 */

const isObj = ( v ) => v !== null && typeof v === 'object' && ! Array.isArray( v );
const str = ( v ) => ( typeof v === 'string' ? v.trim() : '' );

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
        return 'A filter on this site (ppclg_pause_background) holds the scheduler tick, kicks and Grow now steps. Queued work waits; nothing is lost.';
    }
    return 'The scheduler tick, kicks and Grow now steps wait so the store gets the server first. Queued work, outreach (inside its send window) and limits are untouched; nothing is lost, only delayed. A stage you start with “Run now” still runs.';
}

/**
 * The Overview notice for a critically full OPcache (GET /overview `opcache`), or null when there is none.
 *
 * @param {object|null} warning {level, title, summary, details, remedy: string[], host}
 * @return {{title: string, summary: string, details: string, intro: string, settings: string[], host: string}|null}
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
        host: str( warning.host ) || 'On managed hosting, ask your host to make this change.',
    };
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
