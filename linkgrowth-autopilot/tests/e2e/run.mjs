/**
 * Browser E2E suite of every dashboard control (DESIGN.md §12.5; the owner's spec §10: "every control must work; no
 * decorative buttons").
 *
 *   LGA_WP_PATH=<LGA test site>/wordpress flock <suite lock> nice -n 10 node tests/e2e/run.mjs [options]
 *
 * What one run does, in order:
 *  1. loads and validates the control manifests (tests/e2e/controls/*.json, see lib/manifest.mjs) and starts Chromium
 *     (Playwright via createRequire). A browser that cannot start ends the run with "not run (reason)", exit 3,
 *     before the site is touched;
 *  2. copies the site's SQLite database and PHP error log aside (lib/site.mjs), resets the plugin state and creates an
 *     E2E administrator and an E2E submitter (tests/e2e/setup.php), and serves the site on 127.0.0.1:8793 (with
 *     tests/e2e/server-prepend.php: no background runner, no outbound request beyond loopback and the *.test
 *     fixtures, no mail) and the fixture router on 127.0.0.1:8794;
 *  3. logs in as both users and seeds data through REST as the administrator (lib/seed.mjs);
 *  4. responsive pass: every page of lga-config.pages, every page tab and every Settings tab, at 1280 and 390 px in
 *     the light and dark themes of the dashboard's theme switch: a screenshot of each, and at 390 px
 *     document.documentElement.scrollWidth <= innerWidth;
 *  5. permissions pass: the submitter's lga-config lists the owner-task page only; their walk may meet only controls
 *     whose manifest entry lists the submitter role, and those entries are performed as the submitter;
 *  6. control pass as the administrator: every manifest entry is performed (destructive ones last): load its view,
 *     run its "before" steps, act, wait for the expected request and check its status, body and DOM change;
 *  7. coverage: every [data-lga-control] element met anywhere in the walk must have a manifest entry, every
 *     entry's control must have been met, and every interactive element of the dashboard (lib/browser.mjs
 *     INTERACTIVE_SELECTOR) must carry a data-lga-control at all;
 *  8. stops the servers and puts the database and the error log back (checksum compared).
 * A failure is: a control without a manifest entry, an interactive element without a control id, an entry whose
 * control never appears, an action that sends no request and changes nothing, an expected request that is not sent
 * or answers another status, a DOM expectation that does not hold after the action (or held before it already: no
 * change), any console error or page error, any failed request (4xx/5xx or network error) the entry does not
 * declare, a control the submitter meets outside the owner-task page and the shell, a PHP error-log line naming the
 * plugin, a horizontal scroll at 390 px, or a seed step that fails. Every event of every page is checked once, whenever it happens: during a load, an entry's steps,
 * its action, its cleanup steps, or later (a late answer or a timer, reported for the entry or load it follows): a
 * page the walk moves on from stays open for LINGER_MS (8 s, lib/browser.mjs) with its writes held, so a timer the
 * entry started still runs on a live page. A step's deferred effects (a debounced request) are waited for within the
 * step (DEFER_MS), so they are never counted as the effect of the action that follows.
 * Outbound requests to hosts other than the fixtures are refused by the server and listed in the report.
 *
 * Options: --only page,page (control pass and coverage for these pages; shell entries are kept)  --skip
 * responsive,permissions,controls  --controls DIR  --out DIR (report, server logs)  --shots DIR (screenshots;
 * default <LGA_WP_PATH>/../../e2e-shots/e2e, i.e. $S/e2e-shots/e2e of DESIGN.md §12.1)  --discover FILE (writes
 * every control met, with its text and where it was met)  --verbose.
 * A run of a throwaway copy of the plugin (tests/e2e/run.mjs inside the copy) serves the copy's assets/ to the browser
 * instead of the site's plugin folder (the seeded-defect checks of WP3 use this); PHP still comes from the site.
 * Exit 0 all passed, 1 failures, 2 usage error, 3 not run.
 */
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import fs from 'node:fs';
import path from 'node:path';
import { Site, BASE } from './lib/site.mjs';
import { Tracker, classify, mutationInitScript, domState, settle, collectControls, overflowProbe, routeContext, holdWrites, DEFER_MS, LINGER_MS } from './lib/browser.mjs';
import { loadManifests, requestMatches, statusMatches, substitute, unresolved, parseRequestPattern } from './lib/manifest.mjs';
import { runSeed, restUrl } from './lib/seed.mjs';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const PLUGIN_ROOT = path.resolve( HERE, '..', '..' );
const require = createRequire( import.meta.url );

// ------------------------------------------------------------------ options
const argv = process.argv.slice( 2 );
const opt = ( name, fallback = '' ) => {
    const i = argv.indexOf( `--${ name }` );
    return i >= 0 && argv[ i + 1 ] !== undefined && ! argv[ i + 1 ].startsWith( '--' ) ? argv[ i + 1 ] : fallback;
};
const flag = ( name ) => argv.includes( `--${ name }` );
const WP_PATH = process.env.LGA_WP_PATH ? path.resolve( process.env.LGA_WP_PATH ) : '';
const STAMP = new Date().toISOString().replace( /[-:]/g, '' ).replace( /\..*$/, '' );
const SCRATCH = WP_PATH ? path.resolve( WP_PATH, '..', '..' ) : '';
const OUT = path.resolve( opt( 'out', SCRATCH ? path.join( SCRATCH, 'e2e-runs', STAMP ) : '' ) || '.' );
const SHOTS = path.resolve( opt( 'shots', SCRATCH ? path.join( SCRATCH, 'e2e-shots', 'e2e' ) : '' ) || '.' );
const CONTROLS = path.resolve( opt( 'controls', path.join( HERE, 'controls' ) ) );
const ONLY = opt( 'only' ).split( ',' ).filter( Boolean );
const SKIP = opt( 'skip' ).split( ',' ).filter( Boolean );
const DISCOVER = opt( 'discover' );
const VERBOSE = flag( 'verbose' );
const WIDTHS = [ 1280, 390 ];
const THEMES = [ 'light', 'dark' ];
const HEIGHT = { 1280: 900, 390: 844 };

const t0 = Date.now();
const log = ( ...m ) => process.stdout.write( `[${ ( ( Date.now() - t0 ) / 1000 ).toFixed( 0 ).padStart( 4 ) }s] ${ m.join( ' ' ) }\n` );
const vlog = ( ...m ) => ( VERBOSE ? log( ...m ) : null );

if ( flag( 'check-manifests' ) ) {
    // Lint (bin/lint.sh): validate the manifests only, no browser and no site.
    const checked = loadManifests( CONTROLS );
    if ( checked.errors.length ) {
        process.stdout.write( `E2E manifests invalid (${ checked.errors.length } problems):\n${ checked.errors.map( ( e ) => '  - ' + e ).join( '\n' ) }\n` );
        process.exit( 1 );
    }
    process.stdout.write( `E2E manifests OK: ${ checked.entries.length } entries in ${ checked.files.length } files\n` );
    process.exit( 0 );
}
if ( ! WP_PATH || ! fs.existsSync( path.join( WP_PATH, 'wp-load.php' ) ) ) {
    process.stderr.write( 'Set LGA_WP_PATH to the LGA test site (a WordPress root with wp-load.php), DESIGN.md §12.1.\n' );
    process.exit( 2 );
}

// ------------------------------------------------------------------ results
const failures = [];
const fail = ( where, message ) => {
    failures.push( { where, message } );
    log( `  ✘ ${ where }: ${ message }` );
};
const stats = { pages: 0, settingsTabs: 0, pageTabs: 0, views: 0, screenshots: 0, overflowChecks: 0, entries: 0, exercised: 0, passed: 0, submitterEntries: 0, requestsChecked: 0, domChecks: 0 };
/** control id → { roles:Set, where:Set, sample } for everything the walk met. */
const met = new Map();
/** Interactive elements of the dashboard without data-lga-control, keyed by scope and description: { roles, where, sample }. */
const unnamedMet = new Map();
const report = { startedAt: new Date().toISOString(), wpPath: WP_PATH, pluginRoot: PLUGIN_ROOT, controlsDir: CONTROLS, shots: SHOTS, out: OUT, entries: [], views: [], seed: null };

function noteControls( list, role, where ) {
    for ( const c of list ) {
        if ( ! c.id ) {
            if ( c.unnamed ) {
                const key = [ c.scope, c.region, c.tag, c.type, c.role, c.text ].join( '|' );
                let u = unnamedMet.get( key );
                if ( ! u ) {
                    u = { roles: new Set(), where: new Set(), sample: c };
                    unnamedMet.set( key, u );
                }
                u.roles.add( role );
                if ( u.where.size < 6 ) {
                    u.where.add( where );
                }
            }
            continue;
        }
        let m = met.get( c.id );
        if ( ! m ) {
            m = { roles: new Set(), where: new Set(), sample: c };
            met.set( c.id, m );
        }
        m.roles.add( role );
        if ( m.where.size < 6 ) {
            m.where.add( where );
        }
    }
}

// ------------------------------------------------------------------ manifests (before anything else)
const manifests = loadManifests( CONTROLS );
if ( manifests.errors.length ) {
    process.stdout.write( `E2E suite: the control manifests are invalid (${ manifests.errors.length } problems):\n${ manifests.errors.map( ( e ) => '  - ' + e ).join( '\n' ) }\n` );
    process.exit( 1 );
}
stats.entries = manifests.entries.length;

// ------------------------------------------------------------------ browser (before touching the site)
let chromium;
let browser;
try {
    ( { chromium } = require( 'playwright' ) );
    browser = await chromium.launch();
} catch ( e ) {
    process.stdout.write( `E2E suite: not run (the browser could not start: ${ String( e && e.message ? e.message : e ).split( '\n' )[ 0 ] })\n` );
    process.exit( 3 );
}
fs.mkdirSync( OUT, { recursive: true } );
fs.mkdirSync( SHOTS, { recursive: true } );

const site = new Site( { wpPath: WP_PATH, pluginRoot: PLUGIN_ROOT, outDir: OUT, log } );
const external = [];
let assetsFrom = '';
try {
    const sitePlugin = fs.realpathSync( path.join( WP_PATH, 'wp-content', 'plugins', 'linkgrowth-autopilot' ) );
    if ( sitePlugin !== fs.realpathSync( PLUGIN_ROOT ) ) {
        assetsFrom = PLUGIN_ROOT;
    }
} catch ( e ) {
    assetsFrom = '';
}

// One cleanup, however many callers: a signal and the main flow (whose browser the signal just closed) both wait for
// the same restore before either may exit.
let cleanupDone = null;
function cleanup() {
    if ( ! cleanupDone ) {
        cleanupDone = ( async () => {
            try {
                await browser.close();
            } catch ( e ) {
                // Already closed.
            }
            await site.stopServers();
            await new Promise( ( r ) => setTimeout( r, 300 ) );
            return site.restore();
        } )();
    }
    return cleanupDone;
}
let interrupted = '';
for ( const sig of [ 'SIGINT', 'SIGTERM' ] ) {
    process.on( sig, async () => {
        if ( interrupted ) {
            return;
        }
        interrupted = sig;
        log( `${ sig }: stopping and restoring the site` );
        const ok = await cleanup();
        log( `${ sig }: run interrupted; the site database was ${ ok ? 'restored' : 'NOT restored (checksum differs)' }` );
        process.exit( 130 );
    } );
}
process.on( 'exit', () => site.killServersNow() );

// ------------------------------------------------------------------ helpers
/** The administrator's REST access for prepare/after steps (set once the seed has run). */
let adminApi = null;

async function restCall( step, seed ) {
    if ( ! adminApi ) {
        throw new Error( 'no REST access yet' );
    }
    const st = substitute( step, seed );
    const p = parseRequestPattern( st.rest );
    const route = '/' + p.segments.join( '/' ) + ( Object.keys( p.query ).length ? '?' + new URLSearchParams( p.query ).toString() : '' );
    const res = await adminApi.request.fetch( restUrl( adminApi.restRoot, route ), {
        method: p.method,
        headers: { 'X-WP-Nonce': adminApi.nonce, 'Content-Type': 'application/json', Accept: 'application/json' },
        data: st.body !== undefined ? JSON.stringify( st.body ) : undefined,
        timeout: 120000,
    } );
    if ( ! statusMatches( st.status, res.status() ) ) {
        const text = await res.text().catch( () => '' );
        throw new Error( `${ st.rest } answered ${ res.status() } (expected ${ st.status || '2xx' }): ${ text.slice( 0, 200 ) }` );
    }
    return res;
}

const slugFor = ( page ) => ( page === 'overview' ? 'lga' : `lga-${ page }` );
const viewUrl = ( page, hash = '' ) => `${ BASE }/wp-admin/admin.php?page=${ slugFor( page ) }${ hash || '' }`;

async function login( role ) {
    const ctx = await browser.newContext( { viewport: { width: 1280, height: 900 } } );
    const page = await ctx.newPage();
    await page.goto( `${ BASE }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
    await page.fill( '#user_login', role === 'admin' ? 'lga-e2e-admin' : 'lga-e2e-submitter' );
    await page.fill( '#user_pass', site.passwords[ role ] );
    await Promise.all( [ page.waitForNavigation( { waitUntil: 'domcontentloaded', timeout: 60000 } ), page.click( '#wp-submit' ) ] );
    if ( ! page.url().includes( '/wp-admin' ) ) {
        throw new Error( `login as the ${ role } failed (${ page.url() })` );
    }
    const state = await ctx.storageState();
    await ctx.close();
    return state;
}

async function newContext( state, { width = 1280, theme = 'light' } = {} ) {
    const ctx = await browser.newContext( { storageState: state, viewport: { width, height: HEIGHT[ width ] || 900 }, colorScheme: theme === 'dark' ? 'dark' : 'light', deviceScaleFactor: 1 } );
    await ctx.grantPermissions( [ 'clipboard-read', 'clipboard-write' ], { origin: BASE } );
    await ctx.addInitScript( mutationInitScript );
    await ctx.addInitScript( ( t ) => {
        try {
            window.localStorage.setItem( 'lga_theme', t );
        } catch ( e ) {
            // Storage unavailable.
        }
    }, theme );
    await routeContext( ctx, { assetsFrom, external } );
    return ctx;
}

/** lga-config of a page as the browser sees it. */
async function readConfig( page ) {
    return page.evaluate( () => {
        try {
            return JSON.parse( document.getElementById( 'lga-config' ).textContent || '{}' );
        } catch ( e ) {
            return null;
        }
    } );
}

/** Problems in a slice of tracker events: console errors, page errors, undeclared failed requests. */
function eventProblems( ev, { allowed = [], navigated = false, mocked = [] } = {} ) {
    const out = [];
    const isAllowed = ( r ) => allowed.some( ( a ) => a.method === r.method && requestMatches( a.pattern, r ) && statusMatches( a.status, r.status ) );
    const allowedStatuses = new Set();
    for ( const r of ev.requests ) {
        if ( r.kind === 'external' ) {
            continue;
        }
        if ( r.failure ) {
            if ( /ERR_ABORTED/.test( r.failure ) && ( navigated || r.type === 'document' ) ) {
                continue;
            }
            out.push( `request failed: ${ r.method } ${ r.route || r.path } (${ r.failure })` );
        } else if ( r.status >= 400 ) {
            if ( r.kind === 'lga' && ( isAllowed( r ) || mocked.some( ( m ) => requestMatches( m.pattern, r ) && Number( m.status ) === r.status ) ) ) {
                allowedStatuses.add( r.status );
                continue;
            }
            out.push( `request answered ${ r.status }: ${ r.method } ${ r.route || r.path }` );
        }
    }
    for ( const c of ev.console ) {
        const m = /Failed to load resource: the server responded with a status of (\d+)/.exec( c.text );
        if ( m && allowedStatuses.has( Number( m[ 1 ] ) ) ) {
            continue;
        }
        if ( m ) {
            // The failed request itself is reported above (or allowed); the console line adds nothing.
            continue;
        }
        out.push( `console error: ${ c.text.split( '\n' )[ 0 ].slice( 0, 300 ) }` );
    }
    for ( const p of ev.pageErrors ) {
        out.push( `page error: ${ p.text.split( '\n' )[ 0 ].slice( 0, 300 ) }` );
    }
    return out;
}

/** Visible elements of a control (optionally inside `within`); the nth one, or null. */
async function findControl( page, control, { nth = 0, within = '', timeout = 8000 } = {} ) {
    const sel = `[data-lga-control="${ control }"]`;
    const base = within ? page.locator( within ).locator( sel ) : page.locator( sel );
    const until = Date.now() + timeout;
    do {
        const count = await base.count().catch( () => 0 );
        const visible = [];
        for ( let i = 0; i < count; i++ ) {
            if ( await base.nth( i ).isVisible().catch( () => false ) ) {
                visible.push( base.nth( i ) );
            }
        }
        if ( visible.length > nth ) {
            return visible[ nth ];
        }
        await page.waitForTimeout( 150 );
    } while ( Date.now() < until );
    return null;
}

async function act( loc, action, input ) {
    if ( action === 'fill' ) {
        await loc.fill( String( input ) );
        // Fields that commit on change (not on input) get it when focus leaves them.
        await loc.dispatchEvent( 'change' ).catch( () => {} );
    } else if ( action === 'select' ) {
        try {
            await loc.selectOption( String( input ) );
        } catch ( e ) {
            await loc.selectOption( { label: String( input ) } );
        }
    } else {
        await loc.click();
    }
}

// ------------------------------------------------------------------ page session (a loaded view that later entries may reuse)
/**
 * Checks the events of a page since its last check (a console error, page error or failed request that came after
 * the checks of the load or entry they belong to: a late answer, a timer) and reports them as a failure of that
 * owner; its record in the report is marked failed too. `h` is a session (its current page) or a retired page.
 */
function checkLate( h ) {
    if ( ! h.tracker || ! h.checked || ! h.page || h.page.isClosed() ) {
        return;
    }
    const ev = h.tracker.since( h.checked );
    // A navigation in that time (a timer that opens another page) aborts the old document's requests, as in an entry.
    const problems = eventProblems( ev, { allowed: h.allowed, mocked: h.mocks || [], navigated: ev.navigations.length > 0 } );
    h.checked = h.tracker.mark();
    if ( problems.length ) {
        fail( `${ h.owner } (after its checks)`, problems.join( '; ' ) );
        if ( h.rec ) {
            if ( h.rec.ok ) {
                h.rec.ok = false;
                stats.passed--;
            }
            h.rec.problems.push( ...problems.map( ( p ) => `after its checks: ${ p }` ) );
        }
    }
}

class Session {
    constructor( ctx, role ) {
        this.ctx = ctx;
        this.role = role;
        this.page = null;
        this.tracker = null;
        this.view = '';
        this.signature = '';
        this.clean = false;
        // Every event of the page is checked once: `checked` is the tracker mark up to which it was, `owner` the load
        // or entry the events after it belong to, `allowed` the failed requests that owner declared, `rec` its record
        // in the report (an entry's, once its checks are done).
        this.checked = null;
        this.owner = '';
        this.allowed = [];
        this.rec = null;
        // Pages the walk has moved on from: open until `until` so the timers they run are still checked (retire()).
        this.retired = [];
    }

    /** Checks the current page's events since the last check, for their owner (see checkLate). */
    flush() {
        checkLate( this );
    }

    /** Takes over the page's unchecked events for `owner` (an entry that reuses the page). */
    claim( owner, allowed ) {
        this.flush();
        this.owner = owner;
        this.allowed = allowed;
        this.rec = null;
    }

    /**
     * Moves the current page aside instead of closing it: it stays open for LINGER_MS with its writes held
     * (holdWrites), so a timer its load or entry started (a delayed reload, a toast's dismissal) still runs on a live
     * page, and what that raises is reported for the owner. Retired pages whose time is up are checked and closed.
     */
    async retire() {
        if ( this.page && ! this.page.isClosed() ) {
            this.flush();
            await this.tracker.closePopups();
            await holdWrites( this.page ).catch( () => {} );
            this.retired.push( { page: this.page, tracker: this.tracker, checked: this.checked, owner: this.owner, allowed: this.allowed, mocks: this.mocks, rec: this.rec, until: Date.now() + LINGER_MS } );
        }
        this.page = null;
        this.tracker = null;
        this.checked = null;
        await this.reap( false );
    }

    /** Checks and closes the retired pages whose time is up; with `all`, every one (waiting until its time is up). */
    async reap( all ) {
        const keep = [];
        for ( const r of this.retired ) {
            const wait = r.until - Date.now();
            if ( wait > 0 && ! all ) {
                keep.push( r );
                continue;
            }
            if ( wait > 0 ) {
                await new Promise( ( resolve ) => setTimeout( resolve, wait ) );
            }
            checkLate( r );
            await r.tracker.closePopups();
            await r.page.close().catch( () => {} );
        }
        this.retired = keep;
    }

    /** Retires the current page and waits out every retired page; the context stays open. */
    async drain() {
        await this.retire();
        await this.reap( true );
    }

    async close() {
        await this.drain();
        await this.ctx.close();
    }

    async signatureOf() {
        if ( ! this.page || this.page.isClosed() ) {
            return '';
        }
        const overlays = await this.page.evaluate( () => [ ...document.querySelectorAll( '.lga-drawer, .lga-modal' ) ].filter( ( n ) => n.getClientRects().length > 0 ).length ).catch( () => -1 );
        return `${ this.page.url() }|${ overlays }`;
    }

    /**
     * Opens `url` in a fresh page; returns the problems of the load (console errors, failed requests). `mocks` answer
     * matching lga/v1 requests in the browser instead of the server ({pattern, status, times, body}).
     */
    async load( url, where, mocks = [] ) {
        await this.retire();
        this.page = await this.ctx.newPage();
        this.tracker = new Tracker( this.page );
        this.mocks = mocks;
        if ( mocks.length ) {
            await this.page.route( ( u ) => u.pathname.includes( '/wp-json/lga/v1/' ) || u.searchParams.has( 'rest_route' ), async ( route ) => {
                const req = route.request();
                const c = classify( req.url() );
                const m = mocks.find( ( x ) => x.left > 0 && requestMatches( x.pattern, { method: req.method(), route: c.route, query: c.query } ) );
                if ( ! m ) {
                    await route.fallback();
                    return;
                }
                m.left -= 1;
                await route.fulfill( { status: Number( m.status ), contentType: 'application/json', body: JSON.stringify( m.body || { code: 'lga_e2e_mock', message: `E2E: a mocked ${ m.status } answer`, data: { status: Number( m.status ) } } ) } );
            } );
        }
        const mark = this.tracker.mark();
        let navError = '';
        try {
            await this.page.goto( url, { waitUntil: 'domcontentloaded', timeout: 60000 } );
        } catch ( e ) {
            navError = String( e.message ).split( '\n' )[ 0 ];
        }
        const settled = navError ? false : await settle( this.page, this.tracker, { needPage: true } );
        this.view = url;
        const problems = eventProblems( this.tracker.since( mark ), { mocked: mocks } );
        this.checked = this.tracker.mark();
        this.owner = where;
        this.allowed = [];
        this.rec = null;
        if ( navError ) {
            problems.push( `the page did not load: ${ navError }` );
        } else if ( ! settled ) {
            problems.push( 'the page did not settle within 20 s' );
        }
        const doc = await this.page.evaluate( () => ( { title: document.title, denied: /not allowed to access this page/i.test( document.body ? document.body.innerText : '' ) } ) ).catch( () => ( {} ) );
        if ( doc.denied ) {
            problems.push( 'WordPress denied access to the page' );
        }
        noteControls( await collectControls( this.page ), this.role, where );
        this.signature = await this.signatureOf();
        this.clean = problems.length === 0;
        return problems;
    }
}

// ------------------------------------------------------------------ steps and entries
async function runStep( s, step, seed, where ) {
    const st = typeof step === 'string' ? { control: step, action: 'click' } : { action: 'click', ...step };
    const resolved = substitute( st, seed );
    if ( resolved.rest ) {
        await restCall( resolved, seed );
        return;
    }
    if ( resolved.control ) {
        const loc = await findControl( s.page, resolved.control, { nth: resolved.nth || 0, within: resolved.within || '' } );
        if ( ! loc ) {
            throw new Error( `step ${ resolved.control } not found (visible)` );
        }
        await act( loc, resolved.action, resolved.input );
    }
    if ( resolved.wait ) {
        // A state that never settles (a running loop): wait for what the step must show instead.
        await s.page.waitForSelector( resolved.wait, { state: 'visible', timeout: 20000 } );
        await settle( s.page, s.tracker, { quiet: 300, max: 2000 } );
    } else {
        // What the step scheduled (the debounced request of a filter it typed into) is the step's: it is sent,
        // answered and rendered before the entry's action, never counted as that action's effect.
        const acted = resolved.control ? await domState( s.page ) : null;
        await settle( s.page, s.tracker, { quiet: 350, max: 30000, since: acted ? acted.now : 0, defer: acted ? DEFER_MS : 0 } );
    }
    noteControls( await collectControls( s.page ), s.role, where );
}

/** "text" (contained) or "/regex/" against a URL. */
function urlMatches( url, want ) {
    const w = String( want );
    if ( w.length > 2 && w.startsWith( '/' ) && w.endsWith( '/' ) ) {
        return new RegExp( w.slice( 1, -1 ) ).test( String( url ) );
    }
    return String( url ).includes( w );
}

/**
 * An expect.dom against the page: true when it holds, false when not, a message for a bad selector. "text=<text>"
 * must be shown in the dashboard, "!<selector>" must match no visible element, any other selector a visible one.
 */
async function domHolds( page, q ) {
    return page.evaluate( ( query ) => {
        const vis = ( n ) => n.getClientRects().length > 0 && getComputedStyle( n ).visibility !== 'hidden';
        if ( query.startsWith( 'text=' ) ) {
            return ( document.querySelector( '.lga-wrap' ) || document.body ).innerText.includes( query.slice( 5 ) );
        }
        if ( query.startsWith( '!' ) ) {
            return ! [ ...document.querySelectorAll( query.slice( 1 ) ) ].some( vis );
        }
        return [ ...document.querySelectorAll( query ) ].some( vis );
    }, q ).catch( ( err ) => `bad selector ${ q }: ${ String( err.message ).split( '\n' )[ 0 ] }` );
}

function describeEvents( ev ) {
    const lga = ev.requests.filter( ( r ) => r.kind === 'lga' ).map( ( r ) => `${ r.method } ${ r.route }${ Object.keys( r.query || {} ).length ? '?' + new URLSearchParams( r.query ).toString() : '' } ${ r.status === null ? '…' : r.status }` );
    return lga.length ? lga.join( ', ' ) : 'no lga/v1 request';
}

async function runEntry( s, entry, seed ) {
    const e = substitute( entry, seed );
    const where = `${ e.file } ${ e.label }`;
    const left = unresolved( e );
    if ( left.length ) {
        fail( where, `seed values missing: ${ left.join( ', ' ) }` );
        return;
    }
    stats.exercised++;
    const url = viewUrl( e.page, e.view || '' );
    try {
        for ( const st of e.prepare || [] ) {
            await restCall( st, seed );
        }
    } catch ( err ) {
        fail( where, `prepare: ${ String( err.message ).split( '\n' )[ 0 ] }` );
        s.clean = false;
        return;
    }
    const mocks = ( e.mock || [] ).map( ( m ) => ( { ...m, pattern: parseRequestPattern( m.request ), left: m.times || 1 } ) );
    const pattern = e.expect.request ? parseRequestPattern( e.expect.request ) : null;
    // The failed requests this entry declares: its expected request with the expected status, and allowFailures.
    const allowed = [];
    if ( pattern ) {
        allowed.push( { method: pattern.method, pattern, status: e.expect.status || '2xx' } );
    }
    for ( const a of e.allowFailures || [] ) {
        const [ m, r, st ] = a.split( ' ' );
        allowed.push( { method: m, pattern: parseRequestPattern( `${ m } ${ r }` ), status: st } );
    }
    const reuse = ! e.fresh && ! mocks.length && ! ( e.prepare || [] ).length && s.clean && s.view === url && s.page && ! s.page.isClosed() && ( await s.signatureOf() ) === s.signature;
    if ( ! reuse ) {
        const problems = await s.load( url, `${ e.page }${ e.view || '' }`, mocks );
        if ( problems.length ) {
            fail( where, `loading ${ url.replace( BASE, '' ) }: ${ problems.join( '; ' ) }` );
            s.clean = false;
            return;
        }
    }
    // From here on the page's events are this entry's (a reused page's earlier ones go to the entry before it).
    s.claim( where, allowed );
    s.clean = false;
    const page = s.page;
    const pre = s.tracker.mark();
    try {
        for ( const step of e.before || [] ) {
            await runStep( s, step, seed, `${ where } (before)` );
        }
    } catch ( err ) {
        fail( where, `before: ${ String( err.message ).split( '\n' )[ 0 ] }` );
        return;
    }
    const loc = await findControl( page, e.control, { nth: e.nth || 0, within: e.within || '' } );
    if ( ! loc ) {
        const present = await page.locator( `[data-lga-control="${ e.control }"]` ).count().catch( () => 0 );
        fail( where, present ? `${ present } element(s) but none visible${ e.nth ? ` at nth ${ e.nth }` : '' }` : 'the control is not on the page' );
        return;
    }
    const enabled = await loc.isEnabled().catch( () => true );
    if ( ! enabled ) {
        fail( where, 'the control is disabled' );
        return;
    }
    // The steps that reached the control must have been clean too.
    const stepProblems = eventProblems( s.tracker.between( pre, s.tracker.mark() ), { allowed, mocked: s.mocks || [] } );
    // A DOM expectation must be a change the action makes: it may not hold already.
    const domBefore = e.expect.dom ? await domHolds( page, String( e.expect.dom ) ) : null;
    const before = await domState( page );
    const mark = s.tracker.mark();
    try {
        await act( loc, e.action, e.input );
        for ( const step of e.then || [] ) {
            await runStep( s, step, seed, `${ where } (then)` );
        }
    } catch ( err ) {
        fail( where, `${ e.action }: ${ String( err.message ).split( '\n' )[ 0 ] }` );
        return;
    }
    // Wait for the expected request (and its answer), a navigation, a popup or a download.
    const timeout = e.timeout || 30000;
    const until = Date.now() + timeout;
    let matched = null;
    while ( Date.now() < until ) {
        const ev = s.tracker.since( mark );
        if ( pattern ) {
            matched = ev.requests.find( ( r ) => r.kind === 'lga' && requestMatches( pattern, r ) && ( r.done || r.failure ) ) || null;
        }
        const navOk = ! e.expect.navigate || ( ! page.isClosed() && urlMatches( page.url(), e.expect.navigate ) );
        const popOk = ! e.expect.popup || ev.popups.some( ( p ) => urlMatches( p.url, e.expect.popup ) );
        const dlOk = ! e.expect.download || ev.downloads.length > 0;
        if ( ( ! pattern || matched ) && navOk && popOk && dlOk ) {
            break;
        }
        await page.waitForTimeout( 150 ).catch( () => {} );
    }
    if ( ! page.isClosed() ) {
        await page.waitForLoadState( 'domcontentloaded', { timeout: 15000 } ).catch( () => {} );
        await settle( page, s.tracker, { quiet: 400, max: 30000, since: before ? before.now : 0 } );
    }
    const ev = s.tracker.since( mark );
    const evEnd = s.tracker.mark();
    const after = await domState( page );
    const navigated = ev.navigations.length > 0 && ev.navigations.some( ( n ) => n.url.split( '#' )[ 0 ] !== url.split( '#' )[ 0 ] );
    const problems = [];
    // Effect: a request, a navigation, a new tab, a download, a native dialog, or a DOM change.
    const sent = ev.requests.filter( ( r ) => r.kind === 'lga' || r.kind === 'rest' || r.kind === 'ajax' || r.type === 'document' );
    const mutated = before && after ? after.mutations > before.mutations : navigated;
    if ( ! sent.length && ! mutated && ! navigated && ! ev.popups.length && ! ev.downloads.length && ! ev.dialogs.length ) {
        problems.push( 'decorative: the action sent no request and changed nothing' );
    }
    if ( pattern ) {
        stats.requestsChecked++;
        if ( ! matched ) {
            problems.push( `expected ${ e.expect.request } but saw ${ describeEvents( ev ) }` );
        } else {
            if ( ! statusMatches( e.expect.status, matched.status ) ) {
                problems.push( `${ e.expect.request } answered ${ matched.status === null ? matched.failure : matched.status }, expected ${ e.expect.status || '2xx' }` );
            }
            const body = e.expect.body !== undefined ? e.expect.body : ( e.action === 'fill' ? e.input : undefined );
            if ( body !== undefined && matched.method !== 'GET' ) {
                if ( ! String( matched.postData || '' ).includes( String( body ) ) && ! String( matched.postData || '' ).includes( JSON.stringify( String( body ) ).slice( 1, -1 ) ) ) {
                    problems.push( `the ${ e.expect.request } body does not contain "${ body }"` );
                }
            } else if ( body !== undefined && matched.method === 'GET' && e.expect.body !== undefined ) {
                if ( ! decodeURIComponent( matched.url ).includes( String( body ) ) ) {
                    problems.push( `the ${ e.expect.request } URL does not contain "${ body }"` );
                }
            }
        }
    }
    if ( e.expect.navigate && ( page.isClosed() || ! urlMatches( page.url(), e.expect.navigate ) ) ) {
        problems.push( `expected to navigate to …${ e.expect.navigate } but the page is at ${ page.isClosed() ? '(closed)' : page.url().replace( BASE, '' ) }` );
    }
    if ( e.expect.popup && ! ev.popups.some( ( p ) => urlMatches( p.url, e.expect.popup ) ) ) {
        problems.push( `expected a new tab at …${ e.expect.popup } but saw ${ ev.popups.length ? ev.popups.map( ( p ) => p.url ).join( ', ' ) : 'none' }` );
    }
    if ( e.expect.download && ! ev.downloads.some( ( d ) => e.expect.download === true || String( d.name ).includes( String( e.expect.download ) ) ) ) {
        problems.push( `expected a download${ e.expect.download === true ? '' : ' named …' + e.expect.download } but saw ${ ev.downloads.map( ( d ) => d.name ).join( ', ' ) || 'none' }` );
    }
    if ( e.expect.dom && ! page.isClosed() ) {
        stats.domChecks++;
        const d = String( e.expect.dom );
        const ok = await domHolds( page, d );
        if ( ok !== true ) {
            problems.push( typeof ok === 'string' ? ok : `the DOM does not show ${ d }` );
        } else if ( domBefore === true && ! navigated ) {
            problems.push( `no DOM change: the page showed ${ d } before the action already` );
        }
    }
    problems.push( ...stepProblems.map( ( p ) => `before: ${ p }` ) );
    problems.push( ...eventProblems( ev, { allowed, navigated, mocked: s.mocks || [] } ) );
    if ( ! page.isClosed() ) {
        noteControls( await collectControls( page ), s.role, `${ where } (after action)` );
    }
    // Cleanup steps: put the state back for the entries after this one (their events are checked like the rest).
    try {
        for ( const step of e.after || [] ) {
            await runStep( s, step, seed, `${ where } (after)` );
        }
    } catch ( err ) {
        problems.push( `after: ${ String( err.message ).split( '\n' )[ 0 ] }` );
    }
    if ( ! page.isClosed() ) {
        problems.push( ...eventProblems( s.tracker.since( evEnd ), { allowed, mocked: s.mocks || [] } ).map( ( p ) => `after: ${ p }` ) );
        s.checked = s.tracker.mark();
    }
    const rec = { entry: e.label, file: e.file, role: s.role, ok: problems.length === 0, requests: describeEvents( ev ), mutations: before && after ? after.mutations - before.mutations : null, navigated, problems };
    report.entries.push( rec );
    // Events after this point (a timer, also once the page is retired) are reported for this entry and its record.
    s.rec = rec;
    if ( problems.length ) {
        fail( where, problems.join( '; ' ) );
    } else {
        stats.passed++;
        vlog( `  ✔ ${ where } — ${ rec.requests }${ rec.mutations !== null ? `, ${ rec.mutations } DOM changes` : '' }` );
    }
    // Only a page the action left exactly as loaded (no request, no DOM change, no navigation, same overlays: a link
    // that opened a new tab) is reused for the next entry of the same view; any other is loaded again.
    // A DOM change (a disclosure opened, a table shown instead of a chart) also stays on the page.
    const requested = ev.requests.some( ( r ) => r.kind === 'lga' || r.type === 'document' );
    const changed = before && after ? after.mutations !== before.mutations : true;
    const stepped = ( e.before || [] ).length > 0 || ( e.then || [] ).length > 0;
    s.clean = ! requested && ! changed && ! stepped && ! navigated && ! ( e.after || [] ).length && ! page.isClosed() && ( await s.signatureOf() ) === s.signature;
    if ( ev.popups.length ) {
        await s.tracker.closePopups();
    }
}

// ------------------------------------------------------------------ views (pages, page tabs, Settings tabs)
async function discoverViews( state, role, config ) {
    const ctx = await newContext( state );
    const s = new Session( ctx, role );
    const views = [];
    const pages = Object.keys( config.pages || {} );
    for ( const key of pages ) {
        const problems = await s.load( viewUrl( key ), `${ key } (view discovery)` );
        if ( problems.length ) {
            fail( `${ role } ${ key }`, `loading the page: ${ problems.join( '; ' ) }` );
            continue;
        }
        views.push( { role, page: key, hash: '', label: key, kind: 'page' } );
        const tabs = await s.page.evaluate( () => [ ...document.querySelectorAll( '#lga-main [role="tablist"]' ) ]
            .filter( ( l ) => ! l.closest( '.lga-drawer, .lga-modal' ) )
            .flatMap( ( l ) => [ ...l.querySelectorAll( ':scope > [role="tab"]' ) ].map( ( b ) => ( { control: b.getAttribute( 'data-lga-control' ) || '', label: b.textContent.replace( /\s+/g, ' ' ).trim(), selected: b.getAttribute( 'aria-selected' ) === 'true' } ) ) ) );
        for ( const tab of tabs ) {
            if ( tab.selected || ! tab.control ) {
                continue;
            }
            const loc = await findControl( s.page, tab.control, { timeout: 3000 } );
            if ( ! loc ) {
                continue;
            }
            await loc.click();
            await settle( s.page, s.tracker );
            noteControls( await collectControls( s.page ), role, `${ key } tab ${ tab.control }` );
            const hash = await s.page.evaluate( () => window.location.hash );
            views.push( { role, page: key, hash, label: `${ key } › ${ tab.label.replace( /\s*\d[\d,]*$/, '' ) }`, kind: key === 'settings' ? 'settings-tab' : 'page-tab', tab: tab.control } );
        }
    }
    await s.close();
    return views;
}

const fileSafe = ( v ) => v.replace( /[^a-z0-9_.-]+/gi, '_' ).replace( /^_+|_+$/g, '' );

async function responsivePass( state, role, views ) {
    for ( const width of WIDTHS ) {
        for ( const theme of THEMES ) {
            const ctx = await newContext( state, { width, theme } );
            const s = new Session( ctx, role );
            for ( const v of views ) {
                const where = `responsive ${ role } ${ width }px ${ theme } ${ v.label }`;
                const problems = await s.load( viewUrl( v.page, v.hash ), where );
                const shot = path.join( SHOTS, role, `${ fileSafe( v.page + ( v.hash || '' ) ) }--${ width }-${ theme }.png` );
                fs.mkdirSync( path.dirname( shot ), { recursive: true } );
                try {
                    await s.page.screenshot( { path: shot, fullPage: true, animations: 'disabled' } );
                    stats.screenshots++;
                } catch ( err ) {
                    problems.push( `screenshot failed: ${ String( err.message ).split( '\n' )[ 0 ] }` );
                }
                const theming = await s.page.evaluate( () => ( document.querySelector( '.lga-wrap' ) || {} ).dataset?.theme || '' ).catch( () => '' );
                if ( theming !== theme ) {
                    problems.push( `the dashboard shows theme "${ theming }", expected "${ theme }"` );
                }
                let overflow = null;
                if ( width < 600 ) {
                    stats.overflowChecks++;
                    overflow = await overflowProbe( s.page );
                    if ( ! overflow.ok ) {
                        problems.push( `horizontal scroll: scrollWidth ${ overflow.scrollWidth } > innerWidth ${ overflow.innerWidth } (${ overflow.offenders.join( ', ' ) })` );
                    }
                }
                report.views.push( { role, view: v.label, url: viewUrl( v.page, v.hash ).replace( BASE, '' ), width, theme, screenshot: shot, overflow, problems } );
                if ( problems.length ) {
                    fail( where, problems.join( '; ' ) );
                }
            }
            await s.close();
        }
    }
}

// ------------------------------------------------------------------ main
let exitCode = 0;
let restoredOk = true;
try {
    log( `E2E suite: ${ manifests.entries.length } manifest entries in ${ manifests.files.length } files (${ CONTROLS })` );
    if ( assetsFrom ) {
        log( `serving the plugin assets from ${ assetsFrom } (a copy of the plugin; PHP comes from the site)` );
    }
    await site.takeSnapshot();
    const prepared = site.prepare();
    log( `site prepared: plugin ${ prepared.version } reset, ${ prepared.tables } tables, users ${ prepared.users.admin.login } and ${ prepared.users.submitter.login }` );
    await site.startServers();
    const states = { admin: await login( 'admin' ), submitter: await login( 'submitter' ) };
    log( 'logged in as the administrator and as the submitter' );

    // ----- seed through REST, as the administrator
    const adminCtx = await newContext( states.admin );
    const s0 = new Session( adminCtx, 'admin' );
    const loadProblems = await s0.load( viewUrl( 'overview' ), 'overview (first load)' );
    const config = await readConfig( s0.page );
    if ( ! config || ! config.restRoot || ! config.nonce ) {
        throw new Error( 'lga-config (REST root and nonce) is missing on the Overview' );
    }
    if ( loadProblems.length ) {
        fail( 'first load of the Overview', loadProblems.join( '; ' ) );
    }
    const seeded = await runSeed( adminCtx.request, { restRoot: config.restRoot, nonce: config.nonce, log: vlog } );
    report.seed = seeded;
    const seedFailed = seeded.steps.filter( ( st ) => ! st.ok );
    for ( const st of seedFailed ) {
        fail( `seed ${ st.name }`, `${ st.method } ${ st.route }: ${ st.error }` );
    }
    log( `seeded through REST: ${ seeded.steps.length - seedFailed.length } of ${ seeded.steps.length } calls ok; values ${ JSON.stringify( seeded.values ) }` );
    // The administrator's REST access stays open for the entries' prepare and after steps.
    adminApi = { request: adminCtx.request, restRoot: config.restRoot, nonce: config.nonce };
    if ( flag( 'seed-only' ) ) {
        // Debugging aid: stop after the seed (the site is restored as always).
        throw new Error( `--seed-only: ${ JSON.stringify( { ...( prepared.seed || {} ), ...seeded.values } ) }` );
    }
    await s0.drain();
    const seed = { ...( prepared.seed || {} ), ...seeded.values };
    report.seed.values = seed;

    // ----- views
    const adminConfig = config;
    const views = { admin: await discoverViews( states.admin, 'admin', adminConfig ) };
    stats.pages = Object.keys( adminConfig.pages || {} ).length;
    stats.settingsTabs = Object.keys( adminConfig.settingsTabs || {} ).length;
    stats.pageTabs = views.admin.filter( ( v ) => v.kind === 'page-tab' ).length;
    const settingsViews = views.admin.filter( ( v ) => v.kind === 'settings-tab' ).length;
    if ( stats.settingsTabs && settingsViews + 1 !== stats.settingsTabs ) {
        fail( 'settings tabs', `lga-config lists ${ stats.settingsTabs } tabs but the Settings page shows ${ settingsViews + 1 }` );
    }
    log( `admin: ${ stats.pages } pages, ${ stats.settingsTabs } Settings tabs, ${ stats.pageTabs } page tabs (${ views.admin.length } views)` );

    // Submitter: the owner-task page only.
    const subCtx = await newContext( states.submitter );
    const sub = new Session( subCtx, 'submitter' );
    const subProblems = await sub.load( viewUrl( 'submissions' ), 'submitter submissions (first load)' );
    if ( subProblems.length ) {
        fail( 'submitter first load', subProblems.join( '; ' ) );
    }
    const subConfig = await readConfig( sub.page );
    const subPages = Object.keys( ( subConfig && subConfig.pages ) || {} );
    if ( subPages.join( ',' ) !== 'submissions' ) {
        fail( 'permissions', `the submitter's lga-config lists ${ subPages.join( ', ' ) || 'no page' }, expected the owner-task page only` );
    }
    if ( subConfig && subConfig.settingsTabs && Object.keys( subConfig.settingsTabs ).length ) {
        fail( 'permissions', 'the submitter\'s lga-config lists Settings tabs' );
    }
    const menu = await sub.page.evaluate( () => [ ...document.querySelectorAll( '#adminmenu a[href*="page=lga"]' ) ].map( ( a ) => a.getAttribute( 'href' ) ) );
    if ( menu.some( ( h ) => ! /page=lga-submissions/.test( h ) ) ) {
        fail( 'permissions', `the submitter's admin menu links to ${ menu.join( ', ' ) }` );
    }
    for ( const other of Object.keys( adminConfig.pages || {} ).filter( ( k ) => k !== 'submissions' ) ) {
        const res = await subCtx.request.get( viewUrl( other ), { maxRedirects: 0 } ).catch( () => null );
        const text = res ? await res.text() : '';
        if ( res && res.status() < 400 && text.includes( 'id="lga-app"' ) ) {
            fail( 'permissions', `the submitter can open the ${ other } page` );
        }
    }
    await sub.close();
    views.submitter = await discoverViews( states.submitter, 'submitter', { pages: { submissions: {} } } );
    stats.views = views.admin.length + views.submitter.length;

    // ----- responsive pass
    if ( ! SKIP.includes( 'responsive' ) ) {
        const pick = ( list ) => list.filter( ( v ) => ! ONLY.length || ONLY.includes( v.page ) );
        log( `responsive pass: ${ pick( views.admin ).length + pick( views.submitter ).length } views × ${ WIDTHS.length } widths × ${ THEMES.length } themes` );
        await responsivePass( states.admin, 'admin', pick( views.admin ) );
        await responsivePass( states.submitter, 'submitter', pick( views.submitter ) );
        log( `responsive pass done: ${ stats.screenshots } screenshots under ${ SHOTS }, ${ stats.overflowChecks } overflow checks at 390 px` );
    }

    const inScope = ( e ) => ! ONLY.length || ONLY.includes( e.scope ) || e.scope === 'shell' || ONLY.includes( e.page );
    const pageOrder = [ 'shell', ...Object.keys( adminConfig.pages || {} ) ];
    const ordered = manifests.entries.filter( inScope ).sort( ( a, b ) => ( pageOrder.indexOf( a.scope ) - pageOrder.indexOf( b.scope ) ) || ( a.file < b.file ? -1 : a.file > b.file ? 1 : a.index - b.index ) );

    // ----- permissions pass: the submitter's own entries
    if ( ! SKIP.includes( 'permissions' ) ) {
        const subEntries = ordered.filter( ( e ) => e.roles.includes( 'submitter' ) );
        stats.submitterEntries = subEntries.length;
        log( `permissions pass: ${ subEntries.length } entries as the submitter` );
        const ctx = await newContext( states.submitter );
        const s = new Session( ctx, 'submitter' );
        for ( const e of subEntries.filter( ( x ) => ! x.destructive ) ) {
            await runEntry( s, { ...e, page: 'submissions' }, seed );
        }
        for ( const e of subEntries.filter( ( x ) => x.destructive ) ) {
            await runEntry( s, { ...e, page: 'submissions' }, seed );
        }
        await s.close();
    }

    // ----- control pass as the administrator
    if ( ! SKIP.includes( 'controls' ) ) {
        const adminEntries = ordered.filter( ( e ) => e.roles.includes( 'admin' ) );
        log( `control pass: ${ adminEntries.length } entries as the administrator (${ adminEntries.filter( ( e ) => e.destructive ).length } destructive, run last)` );
        const ctx = await newContext( states.admin );
        const s = new Session( ctx, 'admin' );
        let n = 0;
        for ( const e of [ ...adminEntries.filter( ( x ) => ! x.destructive ), ...adminEntries.filter( ( x ) => x.destructive ) ] ) {
            n++;
            if ( n % 25 === 0 ) {
                log( `  … ${ n } of ${ adminEntries.length } (${ failures.length } failures so far)` );
            }
            await runEntry( s, e, seed );
        }
        await s.close();
    }

    // ----- coverage
    if ( ! SKIP.includes( 'controls' ) ) {
        const described = new Map();
        for ( const e of manifests.entries ) {
            const d = described.get( e.control ) || new Set();
            e.roles.forEach( ( r ) => d.add( r ) );
            described.set( e.control, d );
        }
        const scopeOk = ( id ) => ! ONLY.length || ONLY.includes( id.split( '.' )[ 0 ] ) || id.startsWith( 'shell.' );
        for ( const [ id, m ] of [ ...met.entries() ].sort() ) {
            if ( ! scopeOk( id ) ) {
                continue;
            }
            const d = described.get( id );
            if ( ! d ) {
                fail( `coverage ${ id }`, `a control with no manifest entry ("${ m.sample.text }", ${ m.sample.tag }${ m.sample.role ? ' role=' + m.sample.role : '' } in ${ m.sample.region }; met in ${ [ ...m.where ].join( ' | ' ) })` );
                continue;
            }
            if ( m.roles.has( 'submitter' ) && ! /^(submissions|shell)\./.test( id ) ) {
                fail( `permissions ${ id }`, `the submitter met a control outside the owner-task page and the shell (met in ${ [ ...m.where ].join( ' | ' ) })` );
            }
            if ( m.roles.has( 'submitter' ) && ! d.has( 'submitter' ) ) {
                fail( `permissions ${ id }`, `the submitter met a control whose manifest entry is not for the submitter (met in ${ [ ...m.where ].join( ' | ' ) })` );
            }
        }
        // An interactive element without data-lga-control (a ui.js helper called without `control`, a raw element)
        // fails whatever it does: no entry can describe it, so nothing would show that it works.
        for ( const [ , u ] of [ ...unnamedMet.entries() ].sort() ) {
            const c = u.sample;
            if ( ONLY.length && c.scope && c.scope !== 'shell' && ! ONLY.includes( c.scope ) ) {
                continue;
            }
            fail( `coverage ${ c.scope || '?' } (no control id)`, `an interactive element without data-lga-control ("${ c.text }", ${ c.tag }${ c.type ? ' type=' + c.type : '' }${ c.role ? ' role=' + c.role : '' } in ${ c.region }${ c.visible ? '' : ', hidden' }: ${ c.html }; met by the ${ [ ...u.roles ].join( ' and the ' ) } in ${ [ ...u.where ].join( ' | ' ) })` );
        }
        for ( const e of manifests.entries.filter( inScope ) ) {
            const m = met.get( e.control );
            for ( const r of e.roles ) {
                if ( r === 'submitter' && SKIP.includes( 'permissions' ) ) {
                    continue;
                }
                if ( ! m || ! m.roles.has( r ) ) {
                    fail( `coverage ${ e.label }`, `a manifest entry whose control the ${ r } walk never met (${ e.file })` );
                }
            }
        }
    }

    if ( DISCOVER ) {
        const out = [ ...met.entries() ].sort().map( ( [ id, m ] ) => ( { id, roles: [ ...m.roles ], where: [ ...m.where ], ...m.sample } ) )
            .concat( [ ...unnamedMet.values() ].map( ( u ) => ( { roles: [ ...u.roles ], where: [ ...u.where ], ...u.sample, id: null } ) ) );
        fs.writeFileSync( path.resolve( DISCOVER ), JSON.stringify( out, null, 2 ) + '\n' );
        log( `discover: ${ out.length } controls written to ${ path.resolve( DISCOVER ) }` );
    }
} catch ( e ) {
    fail( 'run', String( e && e.stack ? e.stack : e ).split( '\n' ).slice( 0, 4 ).join( ' | ' ) );
} finally {
    const outbound = site.readJsonLines( site.outboundLog );
    if ( outbound.length ) {
        const hosts = [ ...new Set( outbound.map( ( o ) => o.host ) ) ];
        // WordPress core's and WooCommerce's own update, news and connection checks are refused like everything else.
        const platform = [ 'api.wordpress.org', 'wordpress.org', 'planet.wordpress.org', 'downloads.wordpress.org', 's.w.org', 'public-api.wordpress.com', 'woocommerce.com', 'api.woocommerce.com', 'tracking.woocommerce.com' ];
        const others = outbound.filter( ( o ) => ! platform.includes( o.host ) );
        report.outbound = { refused: outbound.length, hosts, nonPlatform: others };
        // Not a failure: a control may legitimately call a third-party service (a provider test, IndexNow); the run
        // is offline by design, so those requests are refused and the control shows the provider's error.
        log( `outbound requests refused (the run stays offline): ${ outbound.length } (${ hosts.join( ', ' ) }); ${ others.length } to hosts other than WordPress.org and WooCommerce` );
    }
    const debugLines = site.newDebugLines();
    report.debugLog = debugLines;
    const pluginLines = debugLines.filter( ( l ) => /linkgrowth-autopilot/i.test( l ) && /PHP (Fatal|Warning|Notice|Deprecated|Parse)|Stack trace/i.test( l ) );
    if ( pluginLines.length ) {
        fail( 'PHP error log', `${ pluginLines.length } lines name the plugin: ${ pluginLines.slice( 0, 3 ).join( ' | ' ).slice( 0, 600 ) }` );
    }
    restoredOk = await cleanup();
    if ( ! restoredOk ) {
        fail( 'restore', 'the site database differs from the snapshot after the restore' );
    }
}

if ( interrupted ) {
    // The signal handler exits with 130 once its restore (the same one awaited above) is done.
    fail( 'run', `interrupted by ${ interrupted }` );
}
report.finishedAt = new Date().toISOString();
report.seconds = Math.round( ( Date.now() - t0 ) / 1000 );
report.stats = { ...stats, controlsMet: met.size, controlsWithoutId: unnamedMet.size, failures: failures.length, externalStubbed: external.length };
report.failures = failures;
fs.writeFileSync( path.join( OUT, 'report.json' ), JSON.stringify( report, null, 2 ) + '\n' );
const line = `pages ${ stats.pages }, Settings tabs ${ stats.settingsTabs }, page tabs ${ stats.pageTabs }, views ${ stats.views }; controls met ${ met.size } (without a control id ${ unnamedMet.size }); manifest entries ${ stats.entries } (exercised ${ stats.exercised }, passed ${ stats.passed }, as the submitter ${ stats.submitterEntries }); requests checked ${ stats.requestsChecked }; screenshots ${ stats.screenshots }; 390 px overflow checks ${ stats.overflowChecks }`;
if ( failures.length ) {
    exitCode = interrupted ? 130 : 1;
    log( `E2E suite FAILED: ${ failures.length } failures. ${ line }. Report: ${ path.join( OUT, 'report.json' ) }` );
} else {
    log( `E2E suite passed. ${ line }. Report: ${ path.join( OUT, 'report.json' ) }` );
}
process.exit( exitCode );
