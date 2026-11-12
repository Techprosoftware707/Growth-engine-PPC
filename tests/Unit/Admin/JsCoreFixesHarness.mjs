/**
 * Browser harness for JsCoreFixesTest (js-core slice): loads the real admin modules (app.js, ui.js and
 * friends) in headless Chrome through Playwright, with every request answered from the plugin directory
 * by a route handler (no server, no network), and prints one JSON object with raw observations that
 * the PHP test asserts on. Prints {"skip": "..."} when Playwright or a browser is not available.
 *
 * Usage: node JsCoreFixesHarness.mjs <pluginDir> <playwrightModulePath> [chromeExecutable]
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';

const [ pluginDirArg, playwrightPath, chromePath ] = process.argv.slice( 2 );
const pluginDir = path.resolve( pluginDirArg || '.' ) + path.sep;
const require = createRequire( import.meta.url );
const print = ( value ) => process.stdout.write( JSON.stringify( value ) );

let chromium;
try {
    ( { chromium } = require( playwrightPath ) );
} catch ( e ) {
    print( { skip: `Playwright not loadable: ${ String( e.message ).split( '\n' )[ 0 ] }` } );
    process.exit( 0 );
}
let browser;
try {
    browser = await chromium.launch( chromePath ? { executablePath: chromePath } : {} );
} catch ( e ) {
    print( { skip: `Browser not available: ${ String( e.message ).split( '\n' )[ 0 ] }` } );
    process.exit( 0 );
}

const ORIGIN = 'http://ppclg.test';
const PLUGIN_PATH = '/wp-content/plugins/ppc-link-growth-engine/';
const PLUGIN = ORIGIN + PLUGIN_PATH;
const VERSION = '9.9.9';

/** Every sibling ES module of app.js, as WordPress' import map lists them (unversioned URL => ?ver URL). */
function importMap() {
    const imports = {};
    const add = ( rel ) => {
        imports[ PLUGIN + rel ] = `${ PLUGIN + rel }?ver=${ VERSION }`;
    };
    for ( const f of fs.readdirSync( path.join( pluginDir, 'assets/js' ) ) ) {
        if ( f.endsWith( '.js' ) && f !== 'app.js' ) {
            add( `assets/js/${ f }` );
        }
    }
    for ( const f of fs.readdirSync( path.join( pluginDir, 'assets/js/pages' ) ) ) {
        if ( f.endsWith( '.js' ) ) {
            add( `assets/js/pages/${ f }` );
        }
    }
    return { imports };
}

const WP_SHELL_START = '<div id="wpwrap"><div id="adminmenu"><a href="#m1" id="menu-link">Menu</a></div><div id="wpcontent"><div id="wpbody"><div id="wpbody-content">';
const WP_SHELL_END = '</div></div></div><div id="wpfooter"><p><a href="https://wordpress.org/" id="wpfooter-link">Thank you for creating with WordPress</a></p></div></div>';

function adminHtml( withImportMap ) {
    const config = {
        page: 'overview',
        pageUrls: { overview: `${ ORIGIN }/wp-admin/admin.php?page=ppclg`, logs: `${ ORIGIN }/wp-admin/admin.php?page=ppclg-logs` },
        restRoot: `${ ORIGIN }/wp-json/ppclg/v1/`,
        nonce: 'x',
        version: VERSION,
    };
    const map = withImportMap ? `<script type="importmap" id="wp-importmap">${ JSON.stringify( importMap() ) }</script>` : '';
    return `<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="${ PLUGIN }assets/css/admin.css?ver=${ VERSION }"></head><body>${ WP_SHELL_START }`
        + '<div class="wrap ppclg-wrap"><div id="ppclg-app" data-page="overview"></div></div>'
        + `${ WP_SHELL_END }<script type="application/json" id="ppclg-config">${ JSON.stringify( config ) }</script>${ map }`
        + `<script type="module" src="${ PLUGIN }assets/js/app.js?ver=${ VERSION }"></script></body></html>`;
}

function wpHtml() {
    return `<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="${ PLUGIN }assets/css/admin.css?ver=${ VERSION }"></head><body>${ WP_SHELL_START }`
        + `<div class="wrap ppclg-wrap"><div id="host" style="width:1000px"></div></div>${ WP_SHELL_END }</body></html>`;
}

/**
 * Opens a fresh browser context whose requests are all answered here.
 * strictVersions: unversioned requests for sibling modules get a 404 (proves the import map reached them).
 * overrides: { 'assets/js/x.js': source | null } replaces (null: 404s) the UNVERSIONED copy of a module.
 */
async function open( { withImportMap = true, strictVersions = false, overrides = {} } = {} ) {
    const context = await browser.newContext();
    const requests = [];
    await context.route( `${ ORIGIN }/**`, async ( route ) => {
        const url = new URL( route.request().url() );
        requests.push( url.pathname + url.search );
        if ( url.pathname === '/wp-admin/admin.php' ) {
            return route.fulfill( { contentType: 'text/html', body: adminHtml( withImportMap ) } );
        }
        if ( url.pathname === '/wp.html' ) {
            return route.fulfill( { contentType: 'text/html', body: wpHtml() } );
        }
        if ( url.pathname.startsWith( '/wp-json/' ) ) {
            return route.fulfill( { contentType: 'application/json', body: JSON.stringify( { items: [], total: 0, page: 1, per_page: 25, pages: 1, unread: 0 } ) } );
        }
        if ( url.pathname.startsWith( PLUGIN_PATH ) ) {
            const rel = url.pathname.slice( PLUGIN_PATH.length );
            const versioned = url.searchParams.has( 'ver' );
            if ( ! versioned && Object.prototype.hasOwnProperty.call( overrides, rel ) ) {
                return overrides[ rel ] === null
                    ? route.fulfill( { status: 404, body: 'not found' } )
                    : route.fulfill( { contentType: 'text/javascript', body: overrides[ rel ] } );
            }
            if ( strictVersions && rel.endsWith( '.js' ) && ! versioned ) {
                return route.fulfill( { status: 404, body: 'unversioned module URL' } );
            }
            const file = path.resolve( pluginDir, rel );
            if ( ! file.startsWith( pluginDir ) || ! fs.existsSync( file ) ) {
                return route.fulfill( { status: 404, body: 'not found' } );
            }
            return route.fulfill( { contentType: rel.endsWith( '.css' ) ? 'text/css' : 'text/javascript', body: fs.readFileSync( file ) } );
        }
        return route.fulfill( { status: 404, body: 'not found' } );
    } );
    const page = await context.newPage();
    const errors = [];
    page.on( 'pageerror', ( e ) => errors.push( e.message ) );
    return { context, page, requests, errors };
}

const appState = ( page ) => page.evaluate( () => {
    const mount = document.getElementById( 'ppclg-app' );
    const failure = document.querySelector( '.ppclg-load-failure' );
    return {
        mountLength: mount ? mount.innerHTML.length : 0,
        shell: Boolean( document.querySelector( '.ppclg-shell' ) ),
        overview: Boolean( document.querySelector( '.ppclg-page-overview' ) ),
        failure: failure ? failure.textContent : null,
        failureRole: failure ? failure.getAttribute( 'role' ) : null,
    };
} );

const settled = ( page ) => page.waitForFunction( () => document.querySelector( '.ppclg-page-overview, .ppclg-load-failure' ), null, { timeout: 8000 } ).catch( () => null );

const results = {};

// ---------------------------------------------------------------- js-core-1
{
    // With the import map every sibling module (also the pages' own '../ui.js') is fetched with ?ver=.
    const s = await open( { withImportMap: true, strictVersions: true } );
    await s.page.goto( `${ ORIGIN }/wp-admin/admin.php?page=ppclg` );
    await settled( s.page );
    await s.page.waitForTimeout( 150 );
    results.versioned = { ...( await appState( s.page ) ), errors: s.errors, js: s.requests.filter( ( r ) => r.endsWith( '.js' ) || r.includes( '.js?' ) ) };
    await s.context.close();
}
{
    // Stale cached ui.js from an older release (an export app.js needs is missing): visible failure, not blank.
    const src = fs.readFileSync( path.join( pluginDir, 'assets/js/ui.js' ), 'utf8' );
    const stale = src.replace( 'export function setLocale', 'function setLocale' );
    const s = await open( { withImportMap: false, overrides: { 'assets/js/ui.js': stale } } );
    await s.page.goto( `${ ORIGIN }/wp-admin/admin.php?page=ppclg` );
    await settled( s.page );
    results.staleUi = { patched: stale !== src, ...( await appState( s.page ) ), errors: s.errors };
    await s.context.close();
}
{
    // A sibling module that cannot be fetched at all.
    const s = await open( { withImportMap: false, overrides: { 'assets/js/api.js': null } } );
    await s.page.goto( `${ ORIGIN }/wp-admin/admin.php?page=ppclg` );
    await settled( s.page );
    results.missingApi = { ...( await appState( s.page ) ), errors: s.errors };
    await s.context.close();
}

// ---------------------------------------------------------------- js-core-5
{
    const s = await open( { withImportMap: true } );
    await s.page.goto( `${ ORIGIN }/wp-admin/admin.php?page=ppclg` );
    await settled( s.page );
    await s.page.waitForTimeout( 150 );
    await s.page.evaluate( ( origin ) => {
        window.__marker = 'same-document';
        window.__root = document.querySelector( '.ppclg-page-overview' );
        const a = document.createElement( 'a' );
        a.id = 'same-doc-link';
        a.textContent = 'Campaign #3';
        a.href = `${ origin }/wp-admin/admin.php?page=ppclg#campaign_id=3`;
        document.getElementById( 'wpbody-content' ).prepend( a );
    }, ORIGIN );
    await s.page.click( '#same-doc-link' );
    const linkRerendered = await s.page.waitForFunction( () => {
        const root = document.querySelector( '.ppclg-page-overview' );
        return root && root !== window.__root;
    }, null, { timeout: 4000 } ).then( () => true, () => false );
    const afterLink = await s.page.evaluate( () => ( { marker: window.__marker || null, hash: location.hash } ) );
    await s.page.evaluate( () => {
        window.__root = document.querySelector( '.ppclg-page-overview' );
    } );
    await s.page.goBack();
    const backRerendered = await s.page.waitForFunction( () => {
        const root = document.querySelector( '.ppclg-page-overview' );
        return root && root !== window.__root;
    }, null, { timeout: 4000 } ).then( () => true, () => false );
    const afterBack = await s.page.evaluate( () => ( { marker: window.__marker || null, hash: location.hash } ) );
    await s.page.waitForTimeout( 100 );
    await s.page.evaluate( () => {
        window.__root = document.querySelector( '.ppclg-page-overview' );
        window.location.hash = 'wpbody-content';
    } );
    await s.page.waitForTimeout( 400 );
    const anchorRerendered = await s.page.evaluate( () => document.querySelector( '.ppclg-page-overview' ) !== window.__root );
    results.fragmentNavigation = { linkRerendered, afterLink, backRerendered, afterBack, anchorRerendered, errors: s.errors };
    await s.context.close();
}

// ---------------------------------------------------------------- ui.js components
{
    const s = await open();
    const page = s.page;
    await page.goto( `${ ORIGIN }/wp.html` );
    await page.evaluate( async ( url ) => {
        window.ui = await import( url );
        const SORTABLE = [ 'id', 'domain', 'name', 'score', 'relevance', 'status', 'first_seen', 'last_evaluated', 'updated_at', 'country' ];
        window.makeDb = ( n ) => Array.from( { length: n }, ( _, i ) => ( { id: i + 1, domain: `site${ i + 1 }.ca`, score: ( i * 37 ) % 100, authority: ( i * 53 ) % 100 } ) );
        // Mirrors Repository::paginate: whitelisted orderby (else id), page not clamped to pages.
        window.paginate = ( db, p ) => {
            const orderBy = SORTABLE.includes( p.orderby ) ? p.orderby : 'id';
            const dir = String( p.order ).toUpperCase() === 'ASC' ? 1 : -1;
            const pg = Math.max( 1, Number( p.page ) || 1 );
            const per = Math.min( 200, Math.max( 1, Number( p.per_page ) || 25 ) );
            const sorted = [ ...db ].sort( ( a, b ) => ( a[ orderBy ] > b[ orderBy ] ? 1 : a[ orderBy ] < b[ orderBy ] ? -1 : 0 ) * dir || b.id - a.id );
            return { items: sorted.slice( ( pg - 1 ) * per, pg * per ), total: db.length, page: pg, per_page: per, pages: Math.ceil( db.length / per ) };
        };
        window.tick = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );
    }, `${ PLUGIN }assets/js/ui.js?ver=${ VERSION }` );

    // js-core-2
    results.sorting = await page.evaluate( async () => {
        const db = makeDb( 30 );
        let sent = null;
        const lv = ui.listView( {
            fetch: async ( p ) => {
                sent = p;
                return paginate( db, p );
            },
            defaultSort: { key: 'score', dir: 'desc' },
            columns: [ { key: 'domain', label: 'Prospect', sortable: true }, { key: 'score', label: 'Score', sortable: true }, { key: 'score_class', label: 'Class' }, { key: 'authority', label: 'Authority' } ],
        } );
        document.getElementById( 'host' ).appendChild( lv.el );
        await lv.reload();
        const heads = () => [ ...lv.el.querySelectorAll( 'thead th' ) ].map( ( th ) => ( { label: th.textContent, button: Boolean( th.querySelector( 'button.ppclg-th-sort' ) ), ariaSort: th.getAttribute( 'aria-sort' ) } ) );
        const before = heads();
        lv.el.querySelector( 'thead th button.ppclg-th-sort' ).click();
        await tick( 30 );
        const afterClick = heads();
        const sentAfterClick = `${ sent.orderby } ${ sent.order }`;
        lv.el.remove();
        const whole = ui.listView( { sortable: true, fetch: async ( p ) => paginate( db, p ), columns: [ { key: 'a', label: 'A' }, { key: 'b', label: 'B', sortable: false } ] } );
        document.getElementById( 'host' ).appendChild( whole.el );
        await whole.reload();
        const wholeTable = [ ...whole.el.querySelectorAll( 'thead th' ) ].map( ( th ) => Boolean( th.querySelector( 'button.ppclg-th-sort' ) ) );
        whole.el.remove();
        return { before, afterClick, sentAfterClick, wholeTable };
    } );

    // js-core-4
    results.pageClamp = await page.evaluate( async () => {
        const db = makeDb( 26 );
        const calls = [];
        const lv = ui.listView( {
            fetch: async ( p ) => {
                calls.push( p.page );
                return paginate( db, p );
            },
            columns: [ { key: 'id', label: '#', sortable: true }, { key: 'domain', label: 'Prospect' } ],
        } );
        document.getElementById( 'host' ).appendChild( lv.el );
        await lv.reload();
        lv.el.querySelector( 'button[aria-label="Next page"]' ).click();
        await tick( 40 );
        const onLastPage = { page: lv.state.page, rows: lv.items.length };
        db.splice( db.findIndex( ( x ) => x.id === lv.items[ 0 ].id ), 1 );
        calls.length = 0;
        await lv.reload();
        const afterRemoval = { page: lv.state.page, rows: lv.items.length, pager: lv.el.querySelector( '.ppclg-pagination' ).textContent, empty: lv.el.querySelector( 'tbody' ).textContent.includes( 'No records found' ), calls: [ ...calls ] };
        db.length = 0;
        calls.length = 0;
        await lv.reload();
        const allRemoved = { empty: lv.el.querySelector( 'tbody' ).textContent.includes( 'No records found' ), calls: [ ...calls ] };
        lv.el.remove();
        return { onLastPage, afterRemoval, allRemoved };
    } );

    // js-core-6
    results.busy = await page.evaluate( async () => {
        let saveBtn = null;
        let runs = 0;
        saveBtn = ui.button( 'Save changes', { kind: 'primary', onClick: async () => {
            runs++;
            await tick( 15 );
            saveBtn.disabled = true; // Settings' markDirty() after the reload: nothing left to save.
        } } );
        document.getElementById( 'host' ).appendChild( saveBtn );
        saveBtn.click();
        const whileBusy = { ariaDisabled: saveBtn.getAttribute( 'aria-disabled' ), ariaBusy: saveBtn.getAttribute( 'aria-busy' ), disabled: saveBtn.disabled };
        saveBtn.click();
        saveBtn.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
        await tick( 60 );
        const after = { runs, disabled: saveBtn.disabled, ariaDisabled: saveBtn.getAttribute( 'aria-disabled' ), ariaBusy: saveBtn.getAttribute( 'aria-busy' ) };
        const plain = ui.button( 'Run', { onClick: async () => tick( 10 ) } );
        document.getElementById( 'host' ).appendChild( plain );
        plain.click();
        await tick( 40 );
        const plainAfter = { disabled: plain.disabled, ariaDisabled: plain.getAttribute( 'aria-disabled' ) };
        saveBtn.remove();
        plain.remove();
        return { whileBusy, after, plainAfter };
    } );

    // js-core-7
    results.hidden = await page.evaluate( async () => {
        const t = ui.tabs( { items: [ { id: 'business', label: 'Business', render: ( p ) => p.append( 'x' ) }, { id: 'limits', label: 'Limits', count: 2, render: ( p ) => p.append( 'y' ) } ] } );
        document.getElementById( 'host' ).appendChild( t.el );
        const [ none, two ] = t.el.querySelectorAll( '.ppclg-count' );
        const box = ( n ) => ( { hidden: n.hidden, display: getComputedStyle( n ).display, width: n.getBoundingClientRect().width } );
        const out = { noCount: box( none ), withCount: box( two ) };
        t.setCount( 'limits', null );
        out.setCountNull = box( two );
        t.setCount( 'business', 7 );
        out.setCountSeven = box( none );
        t.el.remove();
        const d = ui.drawer( 'Backlink', ui.el( 'p', {}, 'body' ), {} );
        const df = d.el.querySelector( '.ppclg-drawer-foot' );
        out.drawerFootEmpty = { hidden: df.hidden, display: getComputedStyle( df ).display, height: df.getBoundingClientRect().height };
        d.setActions( [ ui.button( 'Close' ) ] );
        out.drawerFootActions = { hidden: df.hidden, display: getComputedStyle( df ).display };
        d.close();
        const m = ui.modal( { title: 'M', content: 'c', actions: [] } );
        const mf = m.el.querySelector( '.ppclg-modal-foot' );
        out.modalFootEmpty = { hidden: mf.hidden, display: getComputedStyle( mf ).display, height: mf.getBoundingClientRect().height };
        m.close();
        return out;
    } );

    // js-core-3: keyboard user, confirm() opened from a button in a drawer.
    await page.evaluate( () => {
        const reject = ui.button( 'Reject', { attrs: { id: 'reject' }, onClick: async () => {
            window.__confirmed = await ui.confirm( 'Reject site1.ca?', { danger: true, confirmText: 'Reject' } );
        } } );
        const approve = ui.button( 'Approve', { attrs: { id: 'approve' } } );
        window.__drawer = ui.drawer( 'Prospect #1', ui.el( 'div', {}, reject, approve ), { actions: [ ui.button( 'Close drawer', { attrs: { id: 'close-drawer' } } ) ] } );
    } );
    await page.waitForTimeout( 80 );
    const active = () => page.evaluate( () => ( {
        id: document.activeElement === document.body ? 'BODY' : ( document.activeElement.id || document.activeElement.textContent ),
        insideDrawer: Boolean( document.activeElement.closest( '.ppclg-drawer' ) ),
        inModal: Boolean( document.activeElement.closest( '.ppclg-modal' ) ),
        drawerOpen: window.__drawer.isOpen(),
    } ) );
    await page.focus( '#reject' );
    await page.keyboard.press( 'Enter' );
    await page.waitForTimeout( 80 );
    const confirmOpen = await active();
    await page.keyboard.press( 'Escape' );
    await page.waitForTimeout( 80 );
    const afterEscape = await active();
    await page.keyboard.press( 'Tab' );
    await page.waitForTimeout( 40 );
    const afterTab = await active();
    await page.focus( '#reject' );
    await page.keyboard.press( 'Enter' );
    await page.waitForTimeout( 80 );
    await page.keyboard.press( 'Enter' ); // Cancel has focus (danger confirm).
    await page.waitForTimeout( 80 );
    const afterModalButton = await active();
    await page.evaluate( () => document.activeElement.blur() );
    await page.keyboard.press( 'Tab' );
    await page.waitForTimeout( 40 );
    const tabFromBody = await active();
    results.focus = { confirmOpen, afterEscape, afterTab, afterModalButton, tabFromBody };
    results.uiErrors = s.errors;
    await s.context.close();
}

await browser.close();
print( { results } );
