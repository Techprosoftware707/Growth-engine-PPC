/**
 * Control manifests of the E2E suite (DESIGN.md §12.5): tests/e2e/controls/<page>.json, optionally split into
 * <page>.<part>.json (the Settings page keeps one file per tab, so a package that adds a tab adds a file). Every
 * [data-lga-control] element the walk meets must be described by an entry, and every entry's control must be met.
 * A page's own file also describes its link in the shell's navigation (shell.nav-<page>: app.js gives every page of
 * lga-config.pages one), so a package that adds a page touches no shared manifest; the header and the notifications
 * drawer are in shell.json.
 *
 * File: { "page": "<page key, or shell>", "description": "…", "controls": [ entry, … ] }
 * Entry (only these keys; unknown keys are an error, so a typo cannot silently weaken a check):
 *   control      "<page>.<control-id>" (the file's page; shell.* in shell.json; shell.nav-<page> also in the
 *                page's own file)                                                                         required
 *   action       click | fill | select | toggle                                                             required
 *   input        the value for fill / select (select: an option value or label)
 *   expect       what the action must cause; at least one of:                                              required
 *     request    "METHOD /route" under lga/v1 ({id} or * matches one path segment, ** the rest; "?k=v&k2=v2" in the
 *                pattern must all be in the request's query, k=* with any value, and "!k" must not be there or be
 *                empty: a filter's Reset sends "GET /logs?!channel"); the request must be sent after the action
 *     status     the response status of that request: a number, "2xx" (default), "4xx" or "5xx"
 *     body       text the request's body must contain (fill: defaults to the input when request is set)
 *     dom        after the action: a CSS selector that must match a visible element, "!<selector>" that must match
 *                none, or "text=<text>" that must be shown
 *     navigate   text the URL of the page (same tab) must contain after the action
 *     popup      text the URL of a new tab the action opens must contain
 *     download   true, or text the suggested file name must contain
 *   page         the page to load (default: the file's page; shell.* entries default to overview); a navigation
 *                link (shell.nav-<page>) is clicked from another page, never from the page it opens
 *   view         the URL fragment to load ("#tab=messages", "#open={seed.prospectMain}")
 *   before       steps that reach the control: "<control id>" (a click) or {control, action, input, nth, within}
 *   then         steps after the action and before the checks (fill a form field, then submit it)
 *   after        steps after the checks that put the state back (toggle a switch back)
 *   prepare      REST steps run as the administrator before the view loads ({"rest": "POST /automation/toggle",
 *                "body": {…}, "status": "2xx"}): a state the walk cannot reach from this page (a REST step may also
 *                stand in before/then/after)
 *   mock         [{ "request": "GET /overview", "status": 500, "times": 1, "body": {…} }]: the browser gets this
 *                answer instead of the server's, so an error state (and its retry control) can be reached; mocked
 *                failures count as declared
 *   nth          which visible element when the control repeats (row controls; default 0)
 *   within       a CSS selector the control is looked up in (".lga-modal")
 *   roles        ["admin"] (default), ["submitter"] or ["admin", "submitter"]: who performs it; a control the
 *                submitter meets must have "submitter" in its roles (permissions pass)
 *   destructive  true: runs in the last phase, on seeded data (delete, purge, cancel, uninstall-like)
 *   fresh        true: always load the view again first (default: a clean page of the same view is reused)
 *   timeout      milliseconds to wait for the expected request (default 30000)
 *   allowFailures ["METHOD /route STATUS", …]: other failed requests this action may cause
 *   note         free text (why the entry looks the way it does)
 * {seed.<key>} anywhere in a string is replaced by the value the REST seed produced.
 */
import fs from 'node:fs';
import path from 'node:path';

export const ACTIONS = [ 'click', 'fill', 'select', 'toggle' ];
const ENTRY_KEYS = [ 'control', 'action', 'input', 'expect', 'page', 'view', 'before', 'then', 'after', 'prepare', 'mock', 'nth', 'within', 'roles', 'destructive', 'fresh', 'timeout', 'allowFailures', 'note', 'id' ];
const EXPECT_KEYS = [ 'request', 'status', 'body', 'dom', 'navigate', 'popup', 'download' ];
const STEP_KEYS = [ 'control', 'action', 'input', 'nth', 'within', 'wait', 'rest', 'body', 'status' ];
const MOCK_KEYS = [ 'request', 'status', 'times', 'body' ];
const CONTROL_RE = /^[a-z0-9_-]+\.[a-z0-9]+(?:-[a-z0-9]+)*$/;
const isShell = ( control ) => String( control ).startsWith( 'shell.' );

/** Loads and validates every manifest of `dir`. Returns { files, entries, errors }. */
export function loadManifests( dir ) {
    const errors = [];
    const files = [];
    const entries = [];
    if ( ! fs.existsSync( dir ) ) {
        return { files, entries, errors: [ `no manifest folder ${ dir }` ] };
    }
    const names = fs.readdirSync( dir ).filter( ( f ) => f.endsWith( '.json' ) ).sort();
    for ( const name of names ) {
        const file = path.join( dir, name );
        let data;
        try {
            data = JSON.parse( fs.readFileSync( file, 'utf8' ) );
        } catch ( e ) {
            errors.push( `${ name }: invalid JSON (${ e.message })` );
            continue;
        }
        const filePage = name.replace( /\.json$/, '' ).split( '.' )[ 0 ];
        if ( ! data || typeof data !== 'object' || data.page !== filePage || ! Array.isArray( data.controls ) ) {
            errors.push( `${ name }: must be {"page": "${ filePage }", "controls": [...]}` );
            continue;
        }
        files.push( { name, page: filePage, count: data.controls.length } );
        data.controls.forEach( ( e, i ) => {
            const where = `${ name } #${ i + 1 }${ e && e.control ? ` (${ e.control }${ e.id ? ' ' + e.id : '' })` : '' }`;
            const problems = validateEntry( e, filePage );
            if ( problems.length ) {
                errors.push( ...problems.map( ( p ) => `${ where }: ${ p }` ) );
                return;
            }
            entries.push( {
                ...e,
                file: name,
                index: i,
                scope: filePage,
                page: e.page || ( isShell( e.control ) ? 'overview' : filePage ),
                roles: e.roles || [ 'admin' ],
                label: `${ e.control }${ e.id ? ` [${ e.id }]` : '' }`,
            } );
        } );
    }
    const seen = new Map();
    for ( const e of entries ) {
        const key = `${ e.control }|${ e.id || '' }`;
        if ( seen.has( key ) ) {
            errors.push( `${ e.file } #${ e.index + 1 }: ${ e.control } is described twice (${ seen.get( key ) }); give one an "id"` );
        }
        seen.set( key, `${ e.file } #${ e.index + 1 }` );
    }
    return { files, entries, errors };
}

function validateStep( s ) {
    if ( typeof s === 'string' ) {
        return CONTROL_RE.test( s ) ? [] : [ `step "${ s }" is not a control id` ];
    }
    if ( ! s || typeof s !== 'object' ) {
        return [ 'a step must be a control id or an object' ];
    }
    const out = Object.keys( s ).filter( ( k ) => ! STEP_KEYS.includes( k ) ).map( ( k ) => `unknown step key "${ k }"` );
    if ( s.control !== undefined && ! CONTROL_RE.test( String( s.control ) ) ) {
        out.push( `step control "${ s.control }" is not a control id` );
    }
    if ( s.control === undefined && s.wait === undefined && s.rest === undefined ) {
        out.push( 'a step needs a control (or a wait selector, or a rest call)' );
    }
    if ( s.rest !== undefined && ( s.control !== undefined || parseRequestPattern( s.rest ) === null ) ) {
        out.push( `a rest step is "METHOD /route" without a control (${ s.rest })` );
    }
    if ( s.action !== undefined && ! ACTIONS.includes( s.action ) ) {
        out.push( `step action "${ s.action }" is not one of ${ ACTIONS.join( ', ' ) }` );
    }
    return out;
}

export function validateEntry( e, filePage ) {
    const out = [];
    if ( ! e || typeof e !== 'object' ) {
        return [ 'an entry must be an object' ];
    }
    for ( const k of Object.keys( e ) ) {
        if ( ! ENTRY_KEYS.includes( k ) ) {
            out.push( `unknown key "${ k }"` );
        }
    }
    if ( typeof e.control !== 'string' || ! CONTROL_RE.test( e.control ) ) {
        out.push( 'control must be "<page>.<kebab-id>"' );
    } else if ( e.control.split( '.' )[ 0 ] !== filePage && e.control !== `shell.nav-${ filePage }` ) {
        out.push( `control ${ e.control } does not belong to ${ filePage }.json${ e.control.startsWith( 'shell.nav-' ) ? ` (a page's navigation link belongs to ${ e.control.slice( 'shell.nav-'.length ) }.json)` : '' }` );
    } else if ( e.control.startsWith( 'shell.nav-' ) && e.control === `shell.nav-${ e.page || 'overview' }` ) {
        out.push( `${ e.control } is clicked on the page it opens; give it another "page"` );
    }
    if ( ! ACTIONS.includes( e.action ) ) {
        out.push( `action must be one of ${ ACTIONS.join( ', ' ) }` );
    }
    if ( ( e.action === 'fill' || e.action === 'select' ) && typeof e.input !== 'string' ) {
        out.push( `${ e.action } needs a string input` );
    }
    const x = e.expect;
    if ( ! x || typeof x !== 'object' ) {
        out.push( 'expect is required' );
    } else {
        for ( const k of Object.keys( x ) ) {
            if ( ! EXPECT_KEYS.includes( k ) ) {
                out.push( `unknown expect key "${ k }"` );
            }
        }
        if ( ! x.request && ! x.dom && ! x.navigate && ! x.popup && ! x.download ) {
            out.push( 'expect needs at least one of request, dom, navigate, popup, download' );
        }
        if ( x.request !== undefined && parseRequestPattern( x.request ) === null ) {
            out.push( `expect.request "${ x.request }" is not "METHOD /route"` );
        }
        if ( x.status !== undefined && ! /^([1-5]xx|[1-5][0-9][0-9])$/.test( String( x.status ) ) ) {
            out.push( `expect.status "${ x.status }" is not a status or a class like 4xx` );
        }
        if ( x.status !== undefined && ! x.request ) {
            out.push( 'expect.status needs expect.request' );
        }
    }
    if ( e.prepare !== undefined && ( ! Array.isArray( e.prepare ) || e.prepare.some( ( st ) => ! st || typeof st !== 'object' || st.rest === undefined ) ) ) {
        out.push( 'prepare must list rest steps' );
    }
    if ( e.mock !== undefined ) {
        if ( ! Array.isArray( e.mock ) ) {
            out.push( 'mock must be a list' );
        } else {
            for ( const m of e.mock ) {
                if ( ! m || typeof m !== 'object' || parseRequestPattern( m.request ) === null || ! /^[1-5][0-9][0-9]$/.test( String( m.status ) ) || Object.keys( m ).some( ( k ) => ! MOCK_KEYS.includes( k ) ) ) {
                    out.push( 'a mock is {"request": "METHOD /route", "status": 500, "times"?: 1, "body"?: {}}' );
                }
            }
        }
    }
    for ( const k of [ 'before', 'then', 'after', 'prepare' ] ) {
        if ( e[ k ] !== undefined ) {
            if ( ! Array.isArray( e[ k ] ) ) {
                out.push( `${ k } must be a list` );
            } else {
                e[ k ].forEach( ( s ) => out.push( ...validateStep( s ).map( ( p ) => `${ k }: ${ p }` ) ) );
            }
        }
    }
    if ( e.roles !== undefined && ( ! Array.isArray( e.roles ) || e.roles.length === 0 || e.roles.some( ( r ) => ! [ 'admin', 'submitter' ].includes( r ) ) ) ) {
        out.push( 'roles must list admin and/or submitter' );
    }
    if ( e.view !== undefined && ( typeof e.view !== 'string' || ( e.view !== '' && ! e.view.startsWith( '#' ) ) ) ) {
        out.push( 'view must be a URL fragment starting with #' );
    }
    if ( e.allowFailures !== undefined && ( ! Array.isArray( e.allowFailures ) || e.allowFailures.some( ( f ) => ! /^[A-Z]+ \S+ [1-5][0-9x][0-9x]$/.test( String( f ) ) ) ) ) {
        out.push( 'allowFailures must list "METHOD /route STATUS" strings' );
    }
    return out;
}

/** "POST /prospects/{id}/evaluate?x=1&!y" → { method, segments, query: {x: '1'}, absent: ['y'] }; null when malformed. */
export function parseRequestPattern( pattern ) {
    const m = /^(GET|POST|PUT|PATCH|DELETE)\s+(\/\S*)$/.exec( String( pattern || '' ).trim() );
    if ( ! m ) {
        return null;
    }
    const [ routePart, queryPart = '' ] = m[ 2 ].split( '?' );
    const query = {};
    const absent = [];
    for ( const kv of queryPart.split( '&' ).filter( Boolean ) ) {
        if ( kv.startsWith( '!' ) ) {
            if ( kv.length === 1 || kv.includes( '=' ) ) {
                return null;
            }
            absent.push( decodeURIComponent( kv.slice( 1 ) ) );
            continue;
        }
        const [ k, v = '' ] = kv.split( '=' );
        query[ decodeURIComponent( k ) ] = decodeURIComponent( v );
    }
    return { method: m[ 1 ], segments: routePart.replace( /\/+$/, '' ).split( '/' ).filter( Boolean ), query, absent };
}

/** True when a tracked lga/v1 request (method, route without the namespace, query object) matches a pattern. */
export function requestMatches( pattern, req ) {
    const p = typeof pattern === 'string' ? parseRequestPattern( pattern ) : pattern;
    if ( ! p || ! req || req.method !== p.method || ! req.route ) {
        return false;
    }
    const segs = req.route.replace( /\/+$/, '' ).split( '/' ).filter( Boolean );
    for ( let i = 0; i < p.segments.length; i++ ) {
        const want = p.segments[ i ];
        if ( want === '**' ) {
            return queryMatches( p, req.query );
        }
        if ( i >= segs.length ) {
            return false;
        }
        if ( want === '*' || /^\{[^}]+\}$/.test( want ) ) {
            continue;
        }
        if ( want !== segs[ i ] ) {
            return false;
        }
    }
    return segs.length === p.segments.length && queryMatches( p, req.query );
}

function queryMatches( p, have ) {
    const has = ( k ) => Boolean( have ) && Object.prototype.hasOwnProperty.call( have, k );
    for ( const [ k, v ] of Object.entries( p.query || {} ) ) {
        if ( ! has( k ) ) {
            return false;
        }
        if ( v !== '*' && String( have[ k ] ) !== v ) {
            return false;
        }
    }
    return ( p.absent || [] ).every( ( k ) => ! has( k ) || String( have[ k ] ) === '' );
}

/** True when a status matches "2xx" / "404" (default "2xx"). */
export function statusMatches( want, status ) {
    const w = String( want === undefined ? '2xx' : want );
    if ( /^[1-5]xx$/.test( w ) ) {
        return Math.floor( Number( status ) / 100 ) === Number( w[ 0 ] );
    }
    return Number( w ) === Number( status );
}

/** Replaces {seed.key} in every string of a value. Unknown keys are left as they are (and reported by the caller). */
export function substitute( value, seed ) {
    if ( typeof value === 'string' ) {
        return value.replace( /\{seed\.([A-Za-z0-9_]+)\}/g, ( m, k ) => ( seed && seed[ k ] !== undefined && seed[ k ] !== null ? String( seed[ k ] ) : m ) );
    }
    if ( Array.isArray( value ) ) {
        return value.map( ( v ) => substitute( v, seed ) );
    }
    if ( value && typeof value === 'object' ) {
        return Object.fromEntries( Object.entries( value ).map( ( [ k, v ] ) => [ k, substitute( v, seed ) ] ) );
    }
    return value;
}

/** The {seed.key} placeholders a value still holds after substitution. */
export function unresolved( value ) {
    const s = JSON.stringify( value );
    return [ ...new Set( ( s.match( /\{seed\.[A-Za-z0-9_]+\}/g ) || [] ) ) ];
}
