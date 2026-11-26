/**
 * Browser harness for BrowserLibTest: runs the E2E suite's browser library (tests/e2e/lib/browser.mjs) in headless
 * Chrome through Playwright against pages the harness serves itself (no server, no network):
 *  - unnamed:  collectControls() reports every interactive element of the dashboard that has no data-lga-control
 *              (a helper called without `control`, a raw link), and only those;
 *  - defer:    settle( { since, defer } ) does not return before a debounced request the step scheduled has been sent
 *              and answered, so nothing of it is left to be counted as the next action's effect;
 *  - hold:     holdWrites() keeps a lingering page's writes from reaching the server while its reads go through, and
 *              the held request raises no error on the page;
 * and, under node only, scans assets/js: every debounce must be shorter than DEFER_MS by a margin, and every
 * setTimeout delay written in the dashboard must fit in LINGER_MS (the auto-refresh loops excepted, which their
 * manifest entries wait for). Prints one JSON object; {"skip": "..."} for the browser part when Playwright or a
 * browser is not available (the scans still run).
 *
 * Usage: node BrowserLibHarness.mjs <pluginDir> <playwrightModulePath> [chromeExecutable]
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [ pluginDirArg, playwrightPath, chromePath ] = process.argv.slice( 2 );
const pluginDir = path.resolve( pluginDirArg || '.' );
const require = createRequire( import.meta.url );
const print = ( value ) => process.stdout.write( JSON.stringify( value ) );
const lib = await import( pathToFileURL( path.join( pluginDir, 'tests/e2e/lib/browser.mjs' ) ).href );

// ---------------------------------------------------------------- scans of the dashboard's timers (node only)
/** The text after the last top-level comma of the call whose "(" is at `open` (strings and comments skipped). */
function lastArgument( src, open ) {
    let depth = 0;
    let lastComma = -1;
    for ( let i = open; i < src.length; i++ ) {
        const ch = src[ i ];
        if ( ch === '\'' || ch === '"' || ch === '`' ) {
            for ( i++; i < src.length && src[ i ] !== ch; i++ ) {
                if ( src[ i ] === '\\' ) {
                    i++;
                }
            }
            continue;
        }
        if ( ch === '/' && src[ i + 1 ] === '/' ) {
            i = src.indexOf( '\n', i );
            continue;
        }
        if ( ch === '/' && src[ i + 1 ] === '*' ) {
            i = src.indexOf( '*/', i ) + 1;
            continue;
        }
        if ( '([{'.includes( ch ) ) {
            depth++;
        } else if ( ')]}'.includes( ch ) ) {
            depth--;
            if ( depth === 0 ) {
                return lastComma < 0 ? null : src.slice( lastComma + 1, i ).trim();
            }
        } else if ( ch === ',' && depth === 1 ) {
            lastComma = i;
        }
    }
    return null;
}

const scans = { debounces: [], timers: [], unreadable: [] };
const jsFiles = [];
const walk = ( dir ) => {
    for ( const f of fs.readdirSync( dir, { withFileTypes: true } ) ) {
        const p = path.join( dir, f.name );
        if ( f.isDirectory() ) {
            walk( p );
        } else if ( f.name.endsWith( '.js' ) ) {
            jsFiles.push( p );
        }
    }
};
walk( path.join( pluginDir, 'assets/js' ) );
for ( const file of jsFiles.sort() ) {
    const src = fs.readFileSync( file, 'utf8' );
    const rel = path.relative( pluginDir, file );
    const line = ( i ) => src.slice( 0, i ).split( '\n' ).length;
    // debounce( fn, N ) calls (a call without a delay uses ui.js's default), the default itself, filterBar's debounceMs.
    for ( const m of src.matchAll( /\bdebounce\(/g ) ) {
        if ( /function\s+$/.test( src.slice( Math.max( 0, m.index - 20 ), m.index ) ) ) {
            continue;
        }
        const arg = lastArgument( src, m.index + m[ 0 ].length - 1 );
        if ( arg === null ) {
            continue;
        }
        ( /^\d+$/.test( arg ) ? scans.debounces : scans.unreadable ).push( { file: rel, line: line( m.index ), kind: 'debounce', delay: /^\d+$/.test( arg ) ? Number( arg ) : arg } );
    }
    for ( const m of src.matchAll( /\b(?:debounceMs|wait)\s*=\s*(\d+)/g ) ) {
        if ( /debounce|filterBar/.test( src.slice( Math.max( 0, m.index - 200 ), m.index ) ) ) {
            scans.debounces.push( { file: rel, line: line( m.index ), kind: 'default', delay: Number( m[ 1 ] ) } );
        }
    }
    for ( const m of src.matchAll( /\bsetTimeout\(/g ) ) {
        const arg = lastArgument( src, m.index + m[ 0 ].length - 1 );
        if ( arg !== null && /^\d+$/.test( arg ) ) {
            scans.timers.push( { file: rel, line: line( m.index ), delay: Number( arg ) } );
        }
    }
}
const constants = { DEFER_MS: lib.DEFER_MS ?? null, LINGER_MS: lib.LINGER_MS ?? null, INTERACTIVE_SELECTOR: typeof lib.INTERACTIVE_SELECTOR === 'string' };

// ---------------------------------------------------------------- browser scenarios
let chromium;
try {
    ( { chromium } = require( playwrightPath ) );
} catch ( e ) {
    print( { skip: `Playwright not loadable: ${ String( e.message ).split( '\n' )[ 0 ] }`, scans, constants } );
    process.exit( 0 );
}
let browser;
try {
    browser = await chromium.launch( chromePath ? { executablePath: chromePath } : {} );
} catch ( e ) {
    print( { skip: `Browser not available: ${ String( e.message ).split( '\n' )[ 0 ] }`, scans, constants } );
    process.exit( 0 );
}

// One of the site hosts the tracker classifies (lib/browser.mjs SITE_HOSTS); every request to it is answered by the
// route below, so nothing reaches the network or a server on that port.
const ORIGIN = 'http://127.0.0.1:8793';
const PAGES = {
    '/unnamed': `<!doctype html><html><body>
<div id="adminmenu"><a href="/wp-admin/">A WordPress menu link outside the dashboard</a><button>WordPress button</button></div>
<div class="wrap lga-wrap"><div id="lga-app" data-page="backlinks"><div class="lga-shell">
  <header class="lga-header"><button data-lga-control="shell.theme">Theme</button><button type="button">Header button without an id</button></header>
  <nav class="lga-subnav"><a href="?page=lga" data-lga-control="shell.nav-overview">Overview</a></nav>
  <main id="lga-main" class="lga-main" tabindex="-1"><div class="lga-page lga-page-backlinks">
    <button data-lga-control="backlinks.add">Add backlink</button>
    <button type="button">Seeded button without an id</button>
    <a href="#" class="lga-btn">Seeded raw link</a>
    <a class="lga-muted">An anchor without href is text</a>
    <input type="hidden" name="nonce" value="x">
    <input type="search" placeholder="Search without an id">
    <label data-lga-control="backlinks.flag"><input type="checkbox"> A labelled switch named on its label</label>
    <details><summary data-lga-control="backlinks.more">More</summary><p>Inside</p></details>
    <details><p>A disclosure without a summary</p></details>
    <div role="tablist"><button role="tab" data-lga-control="backlinks.tab-a" aria-selected="true">Tab A</button></div>
    <div role="tabpanel" tabindex="0">A focusable tab panel is a region</div>
    <div role="switch" aria-checked="false">A raw switch</div>
    <span tabindex="0">A focusable span</span>
    <button hidden>Hidden button without an id</button>
  </div></main>
  <div class="lga-toasts"><div class="lga-toast"><button type="button" aria-label="Toast close without an id">x</button></div></div>
</div></div></div>
</body></html>`,
    '/defer': `<!doctype html><html><body><div class="wrap lga-wrap"><input id="q"><div id="out">none</div></div>
<script>
let timer;
document.getElementById( 'q' ).addEventListener( 'input', () => {
    clearTimeout( timer );
    timer = setTimeout( async () => {
        const res = await fetch( '/wp-json/lga/v1/logs?channel=' + encodeURIComponent( document.getElementById( 'q' ).value ) );
        document.getElementById( 'out' ).textContent = await res.text();
    }, ${ Math.max( 50, ( Number( lib.DEFER_MS ) || 650 ) - 200 ) } );
} );
</script></body></html>`,
    '/hold': `<!doctype html><html><body><div class="wrap lga-wrap"><div id="out">none</div></div>
<script>
window.lgaWrite = () => fetch( '/wp-json/lga/v1/automation/kick', { method: 'POST', body: '{}' } ).then( () => { document.getElementById( 'out' ).textContent = 'write answered'; } ).catch( ( e ) => console.error( 'write failed: ' + e.message ) );
window.lgaRead = () => fetch( '/wp-json/lga/v1/automation/status' ).then( ( r ) => r.text() ).then( ( t ) => { document.getElementById( 'out' ).textContent = t; } );
</script></body></html>`,
};

const results = {};
const context = await browser.newContext();
const server = [];
await context.route( ( u ) => u.origin === ORIGIN, async ( route ) => {
    const req = route.request();
    const u = new URL( req.url() );
    server.push( `${ req.method() } ${ u.pathname }` );
    if ( PAGES[ u.pathname ] ) {
        await route.fulfill( { status: 200, contentType: 'text/html', body: PAGES[ u.pathname ] } );
        return;
    }
    await route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { ok: true, path: u.pathname, query: u.search } ) } );
} );
await context.addInitScript( lib.mutationInitScript );

async function scenario( name, fn ) {
    try {
        results[ name ] = await fn();
    } catch ( e ) {
        results[ name ] = { failed: String( e && e.stack ? e.stack : e ).split( '\n' ).slice( 0, 3 ).join( ' | ' ) };
    }
}

await scenario( 'unnamed', async () => {
    const page = await context.newPage();
    await page.goto( ORIGIN + '/unnamed' );
    const list = await lib.collectControls( page );
    await page.close();
    return {
        named: list.filter( ( c ) => c.id ).map( ( c ) => c.id ),
        unnamed: list.filter( ( c ) => c.unnamed ).map( ( c ) => ( { text: c.text, tag: c.tag, role: c.role, scope: c.scope, region: c.region, visible: c.visible } ) ),
    };
} );

await scenario( 'defer', async () => {
    const page = await context.newPage();
    const tracker = new lib.Tracker( page );
    await page.goto( ORIGIN + '/defer' );
    await page.waitForTimeout( 600 );
    // As run.mjs runStep does: act, take the page clock, settle with the step's defer.
    await page.fill( '#q', 'rest' );
    const acted = await lib.domState( page );
    const t0 = Date.now();
    await lib.settle( page, tracker, { quiet: 100, max: 10000, since: acted.now, defer: lib.DEFER_MS } );
    const settledMs = Date.now() - t0;
    const lga = ( list ) => list.filter( ( r ) => r.kind === 'lga' );
    const inStep = lga( tracker.requests ).map( ( r ) => ( { route: r.route, query: r.query, status: r.status, done: r.done } ) );
    const mark = tracker.mark();
    await page.waitForTimeout( 1200 );
    const afterStep = lga( tracker.since( mark ).requests ).map( ( r ) => `${ r.method } ${ r.route }` );
    const shown = await page.textContent( '#out' );
    await page.close();
    return { inStep, afterStep, shown, settledMs, debounceMs: Math.max( 50, ( Number( lib.DEFER_MS ) || 650 ) - 200 ) };
} );

await scenario( 'hold', async () => {
    if ( typeof lib.holdWrites !== 'function' ) {
        return { failed: 'holdWrites is not exported by tests/e2e/lib/browser.mjs' };
    }
    const page = await context.newPage();
    const tracker = new lib.Tracker( page );
    const errors = [];
    page.on( 'pageerror', ( e ) => errors.push( String( e.message ) ) );
    page.on( 'console', ( m ) => ( m.type() === 'error' ? errors.push( m.text() ) : null ) );
    await page.goto( ORIGIN + '/hold' );
    const before = server.length;
    await lib.holdWrites( page );
    await page.evaluate( () => {
        window.lgaWrite();
        return window.lgaRead();
    } );
    await page.waitForTimeout( 800 );
    const reached = server.slice( before );
    const shown = await page.textContent( '#out' );
    const write = tracker.requests.find( ( r ) => r.method === 'POST' );
    const writeState = write ? { status: write.status, failure: write.failure, done: write.done } : null;
    await page.close();
    await new Promise( ( r ) => setTimeout( r, 200 ) );
    return { reached, shown, writeState, errors };
} );

await context.close();
await browser.close();
print( { scans, constants, results } );
