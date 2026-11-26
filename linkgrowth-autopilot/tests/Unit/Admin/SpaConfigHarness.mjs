/**
 * Browser harness for SpaConfigTest (WP3): the admin SPA builds its router, navigation and Settings tabs from
 * lga-config (pages, settingsTabs), not from lists in app.js or settings.js. Loads the real app.js in headless Chrome
 * through Playwright, every request answered by a route handler (no server, no network): a page and a Settings tab
 * that exist only in lga-config (their modules are served by the handler, not files of the plugin) appear and work,
 * pages missing from lga-config (no capability, unknown) are neither listed nor routable, and module paths outside
 * the plugin are not loaded; ui.js's control helpers name what they create, and a button whose handler ends on a
 * refused request is not an uncaught error. Also runs app.js's pure helpers under node. Prints one JSON object with raw
 * observations for the PHP test; {"skip": "..."} when Playwright or a browser is not available.
 *
 * Usage: node SpaConfigHarness.mjs <pluginDir> <playwrightModulePath> [chromeExecutable]
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const [ pluginDirArg, playwrightPath, chromePath ] = process.argv.slice( 2 );
const pluginDir = path.resolve( pluginDirArg || '.' ) + path.sep;
const SPA = JSON.parse( fs.readFileSync( path.join( pluginDir, 'tests/fixtures/spa-config.json' ), 'utf8' ) );
const require = createRequire( import.meta.url );
const print = ( value ) => process.stdout.write( JSON.stringify( value ) );

// ---------------------------------------------------------------- pure helpers (no browser needed)
const helpers = {};
{
    const app = await import( pathToFileURL( path.join( pluginDir, 'assets/js/app.js' ) ).href );
    const base = 'http://lga.test/wp-content/plugins/linkgrowth-autopilot/assets/js/app.js?ver=1';
    helpers.definitions = app.definitionsFrom( { late: { label: 'Late', order: 90 }, bare: 'Bare label', early: { label: 'Early', icon: 'zap', module: 'pages/early.js', order: 5 }, nolabel: { label: '' }, 'Bad Key': { label: 'Bad' }, list: [ 'x' ] } );
    helpers.tabDefinitions = app.definitionsFrom( { probe: { label: 'Probe settings' } }, { modulePrefix: 'settings/' } );
    helpers.notAMap = app.definitionsFrom( [ 'overview' ] );
    helpers.moduleUrls = Object.fromEntries( [ 'pages/overview.js', 'settings/business.js', '../ui.js', 'pages/../x.js', 'pages/x.js?y=1', 'https://evil.test/x.js', 'javascript:alert(1)', '', 'pages/x.mjs' ].map( ( p ) => [ p, app.moduleUrl( p, base ) ] ) );
    helpers.routes = {
        known: app.routeFor( 'logs', { overview: {}, logs: {} } ),
        unknown: app.routeFor( 'nope', { overview: {}, logs: {} } ),
        forbidden: app.routeFor( 'logs', { overview: {}, prospects: {} } ),
        noOverview: app.routeFor( 'logs', { prospects: {}, submissions: {} } ),
        none: app.routeFor( 'overview', {} ),
    };
}

let chromium;
try {
    ( { chromium } = require( playwrightPath ) );
} catch ( e ) {
    print( { skip: `Playwright not loadable: ${ String( e.message ).split( '\n' )[ 0 ] }`, helpers } );
    process.exit( 0 );
}
let browser;
try {
    browser = await chromium.launch( chromePath ? { executablePath: chromePath } : {} );
} catch ( e ) {
    print( { skip: `Browser not available: ${ String( e.message ).split( '\n' )[ 0 ] }`, helpers } );
    process.exit( 0 );
}

const ORIGIN = 'http://lga.test';
const PLUGIN_PATH = '/wp-content/plugins/linkgrowth-autopilot/';
const PLUGIN = ORIGIN + PLUGIN_PATH;
const REST = '/wp-json/lga/v1';
const VERSION = '9.9.9';
const LIST = { items: [], total: 0, page: 1, per_page: 25, pages: 1, unread: 0 };

/** Modules that exist only for this harness (served by the route handler; not files of the plugin). */
const VIRTUAL = {
    'assets/js/pages/probe.js': `import { el, replace, button } from '../ui.js';
export default function render( root, ctx ) {
    replace( root, el( 'div', { class: 'lga-page-head' }, el( 'h2', { class: 'lga-page-title' }, 'Probe page' ) ), el( 'p', { class: 'lga-probe-page' }, 'Rendered from lga-config: ' + ctx.page ), button( 'Probe action', { control: 'probe-action' } ) );
}
`,
    'assets/js/pages/first.js': `import { el, replace } from '../ui.js';
export default function render( root ) {
    replace( root, el( 'div', { class: 'lga-page-head' }, el( 'h2', { class: 'lga-page-title' }, 'First page' ) ) );
}
`,
    'assets/js/settings/probe.js': `export default function render( tab ) {
    return tab.grid( [ tab.bind( 'probe.limit', { type: 'number', label: 'Probe limit', min: 1, default: 3 } ) ] );
}
`,
};

function importMap() {
    const imports = {};
    for ( const dir of [ 'assets/js', 'assets/js/pages', 'assets/js/settings' ] ) {
        for ( const f of fs.readdirSync( path.join( pluginDir, dir ) ) ) {
            if ( f.endsWith( '.js' ) && f !== 'app.js' ) {
                imports[ `${ PLUGIN }${ dir }/${ f }` ] = `${ PLUGIN }${ dir }/${ f }?ver=${ VERSION }`;
            }
        }
    }
    return { imports };
}

function adminHtml( dataPage, config ) {
    const full = { restRoot: `${ ORIGIN }${ REST }/`, nonce: 'x', version: VERSION, siteDomain: 'example.ca', automationEnabled: true, page: dataPage, ...config };
    return `<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="${ PLUGIN }assets/css/admin.css?ver=${ VERSION }"></head><body>`
        + `<div class="wrap lga-wrap"><div id="lga-app" data-page="${ dataPage }"></div></div>`
        + `<script type="application/json" id="lga-config">${ JSON.stringify( full ) }</script>`
        + `<script type="importmap">${ JSON.stringify( importMap() ) }</script>`
        + `<script type="module" src="${ PLUGIN }assets/js/app.js?ver=${ VERSION }"></script></body></html>`;
}

/** Opens one admin page; mock(method, path, body) answers REST calls (undefined: an empty list). */
async function open( dataPage, config, mock = () => undefined, hash = '' ) {
    const context = await browser.newContext();
    const requests = [];
    const external = [];
    const errors = [];
    await context.route( '**/*', async ( route ) => {
        const req = route.request();
        const url = new URL( req.url() );
        if ( url.origin !== ORIGIN ) {
            external.push( req.url() );
            return route.fulfill( { status: 404, body: 'not here' } );
        }
        if ( url.pathname === '/wp-admin/admin.php' ) {
            return route.fulfill( { contentType: 'text/html', body: adminHtml( dataPage, config ) } );
        }
        if ( url.pathname.startsWith( REST ) ) {
            const p = url.pathname.slice( REST.length );
            let body = null;
            try {
                body = req.postData() ? JSON.parse( req.postData() ) : null;
            } catch ( e ) {
                body = null;
            }
            requests.push( { method: req.method(), path: p, body } );
            const r = mock( req.method(), p, body );
            // { __status, body }: an error answer (e.g. a refused request) instead of a 200.
            if ( r && typeof r === 'object' && r.__status ) {
                return route.fulfill( { status: r.__status, contentType: 'application/json', body: JSON.stringify( r.body || {} ) } );
            }
            return route.fulfill( { contentType: 'application/json', body: JSON.stringify( r === undefined ? LIST : r ) } );
        }
        if ( url.pathname.startsWith( PLUGIN_PATH ) ) {
            const rel = url.pathname.slice( PLUGIN_PATH.length );
            requests.push( { method: 'GET', path: rel } );
            if ( Object.prototype.hasOwnProperty.call( VIRTUAL, rel ) ) {
                return route.fulfill( { contentType: 'text/javascript', body: VIRTUAL[ rel ] } );
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
    page.on( 'pageerror', ( e ) => errors.push( e.message ) );
    await page.goto( `${ ORIGIN }/wp-admin/admin.php${ hash }` );
    await page.waitForSelector( '#lga-main > .lga-page .lga-page-head, #lga-main .lga-error, .lga-load-failure', { timeout: 8000 } ).catch( () => null );
    await page.waitForTimeout( 400 );
    const shell = () => page.evaluate( () => ( {
        nav: [ ...document.querySelectorAll( '.lga-subnav-link' ) ].map( ( a ) => ( { label: a.textContent.trim(), control: a.getAttribute( 'data-lga-control' ), current: a.getAttribute( 'aria-current' ), href: a.getAttribute( 'href' ) } ) ),
        pageClass: ( document.querySelector( '#lga-main > .lga-page' ) || { className: '' } ).className,
        title: document.title,
        error: ( document.querySelector( '#lga-main .lga-error' ) || { textContent: '' } ).textContent.trim(),
    } ) );
    const moduleRequests = () => requests.filter( ( r ) => r.method === 'GET' && /^assets\/js\/(pages|settings)\//.test( r.path ) ).map( ( r ) => r.path );
    return { context, page, requests, external, errors, shell, moduleRequests };
}

const results = {};
const scenario = async ( name, fn ) => {
    try {
        results[ name ] = await fn();
    } catch ( e ) {
        results[ name ] = { failed: String( e && e.stack ? e.stack : e ).slice( 0, 1500 ) };
    }
};

// Pages as Admin\Menu prints them, without "logs" (as for a user without its capability), plus a page that exists
// only in lga-config ("probe", between Backlinks 50 and On-site SEO 60) and one ordered first.
const withoutLogs = Object.fromEntries( Object.entries( SPA.pages ).filter( ( [ k ] ) => k !== 'logs' ) );
const PAGES = {
    ...withoutLogs,
    probe: { label: 'Probe', icon: 'zap', module: 'pages/probe.js', cap: 'lga_manage', order: 55 },
    first: { label: 'First', icon: 'home', module: 'pages/first.js', cap: 'lga_manage', order: 5 },
};

await scenario( 'configPage', async () => {
    const s = await open( 'probe', { pages: PAGES, settingsTabs: SPA.settingsTabs } );
    const out = {
        ...( await s.shell() ),
        probeText: await s.page.locator( '.lga-probe-page' ).textContent().catch( () => null ),
        probeAction: await s.page.locator( 'button:has-text("Probe action")' ).getAttribute( 'data-lga-control' ).catch( () => null ),
        modules: s.moduleRequests(),
        errors: s.errors,
    };
    await s.context.close();
    return out;
} );

await scenario( 'forbiddenPage', async () => {
    // "logs" is not in lga-config (the user lacks its capability): it is not listed and its URL opens the Overview.
    const s = await open( 'logs', { pages: PAGES, settingsTabs: SPA.settingsTabs } );
    const out = { ...( await s.shell() ), modules: s.moduleRequests(), errors: s.errors };
    await s.context.close();
    return out;
} );

await scenario( 'unknownPage', async () => {
    const s = await open( 'nope', { pages: PAGES, settingsTabs: SPA.settingsTabs } );
    const out = { ...( await s.shell() ), modules: s.moduleRequests(), errors: s.errors };
    await s.context.close();
    return out;
} );

await scenario( 'noOverview', async () => {
    // Without the Overview in lga-config an unknown page opens the user's first page.
    const pages = { submissions: SPA.pages.submissions, prospects: SPA.pages.prospects };
    const s = await open( 'logs', { pages } );
    const out = { ...( await s.shell() ), modules: s.moduleRequests(), errors: s.errors };
    await s.context.close();
    return out;
} );

await scenario( 'refusedModules', async () => {
    const pages = { ...SPA.pages, evil: { label: 'Evil', icon: 'zap', module: 'https://evil.test/x.js', cap: 'lga_manage', order: 95 }, up: { label: 'Up', icon: 'zap', module: '../../../x.js', cap: 'lga_manage', order: 96 } };
    const evil = await open( 'evil', { pages } );
    const up = await open( 'up', { pages } );
    const out = {
        evil: { ...( await evil.shell() ), external: evil.external, modules: evil.moduleRequests(), errors: evil.errors },
        up: { ...( await up.shell() ), external: up.external, modules: up.moduleRequests(), errors: up.errors },
    };
    await evil.context.close();
    await up.context.close();
    return out;
} );

const settingsPayload = () => ( {
    settings: { site: { brand_name: 'Example', domain: 'example.ca' }, automation: {}, discovery: {}, providers: {}, outreach: {}, content: {}, monitoring: {}, advanced: {}, probe: { limit: 3 } },
    defaults: {},
    secrets: {},
    providers: {}, facts: {}, custom_seeds: [], seeds: [], crypto_strong: true, backups: [],
} );
const settingsMock = ( m, p ) => {
    if ( p === '/settings' && m === 'GET' ) {
        return settingsPayload();
    }
    if ( p === '/settings' && m === 'POST' ) {
        return { ok: true, changed: [ 'probe.limit' ], secrets: { updated: [], cleared: [], rejected: [], locked: [] } };
    }
    return undefined;
};
// The tabs as Admin\Menu prints them, without "monitoring", plus a tab that exists only in lga-config ("probe", after
// Content & facts 60) and one whose module cannot be loaded.
const TABS = {
    ...Object.fromEntries( Object.entries( SPA.settingsTabs ).filter( ( [ k ] ) => k !== 'monitoring' ) ),
    probe: { label: 'Probe settings', module: 'settings/probe.js', order: 65 },
    broken: { label: 'Broken tab', module: 'settings/missing-tab.js', order: 85 },
};
const tabState = ( page ) => page.evaluate( () => ( {
    tabs: [ ...document.querySelectorAll( '.lga-tab' ) ].map( ( b ) => ( { label: b.textContent.trim(), control: b.getAttribute( 'data-lga-control' ), selected: b.getAttribute( 'aria-selected' ) } ) ),
    panel: ( [ ...document.querySelectorAll( '.lga-tabpanel' ) ].find( ( p ) => ! p.hidden ) || { textContent: '' } ).textContent.trim().slice( 0, 400 ),
} ) );

await scenario( 'configTab', async () => {
    const s = await open( 'settings', { pages: SPA.pages, settingsTabs: TABS }, settingsMock, '#tab=probe' );
    await s.page.waitForSelector( '.lga-tab', { timeout: 8000 } );
    const before = await tabState( s.page );
    const field = s.page.locator( '.lga-field', { hasText: 'Probe limit' } ).locator( 'input' ).first();
    const control = await field.getAttribute( 'data-lga-control' );
    await field.fill( '7' );
    await s.page.click( 'text=Save changes' );
    await s.page.waitForTimeout( 700 );
    const out = {
        ...before,
        fieldControl: control,
        saveControl: await s.page.locator( 'button:has-text("Save changes")' ).getAttribute( 'data-lga-control' ),
        posts: s.requests.filter( ( r ) => r.method === 'POST' ).map( ( r ) => ( { path: r.path, body: r.body } ) ),
        modules: s.moduleRequests(),
        errors: s.errors,
    };
    await s.context.close();
    return out;
} );

await scenario( 'missingTab', async () => {
    // "monitoring" is not in lga-config: not listed, and its hash opens the first tab.
    const s = await open( 'settings', { pages: SPA.pages, settingsTabs: TABS }, settingsMock, '#tab=monitoring' );
    await s.page.waitForSelector( '.lga-tab', { timeout: 8000 } );
    const out = { ...( await tabState( s.page ) ), errors: s.errors };
    await s.context.close();
    return out;
} );

await scenario( 'brokenTab', async () => {
    // A tab whose module cannot be loaded shows the error in its own panel; the others still work.
    const s = await open( 'settings', { pages: SPA.pages, settingsTabs: TABS }, settingsMock, '#tab=broken' );
    await s.page.waitForSelector( '.lga-tab', { timeout: 8000 } );
    const broken = await tabState( s.page );
    const retryControl = await s.page.locator( '.lga-tabpanel:not([hidden]) button:has-text("Try again")' ).getAttribute( 'data-lga-control' ).catch( () => null );
    await s.page.click( '.lga-tab:has-text("Business profile")' );
    await s.page.waitForTimeout( 200 );
    const business = await tabState( s.page );
    // "Try again" reloads the page (a module that failed stays failed for the page view).
    await s.page.click( '.lga-tab:has-text("Broken tab")' );
    await s.page.waitForTimeout( 200 );
    const navigations = [];
    s.page.on( 'framenavigated', ( f ) => navigations.push( f.url() ) );
    await s.page.click( '.lga-tabpanel:not([hidden]) button:has-text("Try again")' );
    await s.page.waitForSelector( '.lga-tab', { timeout: 8000 } ).catch( () => null );
    const out = { broken, retryControl, business, reloaded: navigations.length > 0, errors: s.errors };
    await s.context.close();
    return out;
} );

await scenario( 'controlHelpers', async () => {
    // The ui.js helpers name every control "<page>.<id>" (the page app.js scoped them to); ids are kebab-case.
    const s = await open( 'probe', { pages: PAGES, settingsTabs: SPA.settingsTabs } );
    const out = await s.page.evaluate( async ( url ) => {
        const ui = await import( url );
        const name = ( n ) => ( n ? n.getAttribute( 'data-lga-control' ) : null );
        const all = ( n ) => [ ...n.querySelectorAll( '[data-lga-control]' ) ].map( name );
        const r = { scope: ui.getControlScope() };
        r.button = name( ui.button( 'Save changes', { control: 'save' } ) );
        r.buttonWithoutId = name( ui.button( 'No id' ) );
        r.iconButton = name( ui.iconButton( 'x', 'Close', { control: 'close-it' } ) );
        r.linkButton = name( ui.linkButton( 'Open', '#', { control: 'open-page' } ) );
        r.link = name( ui.link( 'Logs', '#', { control: 'logs-link' } ) );
        r.extLink = name( ui.extLink( 'https://example.test/', 'Example', { control: 'site' } ) );
        r.extLinkEmpty = name( ui.extLink( '', 'none', { control: 'site' } ) );
        // A value that is not a web address (a contact's source "manual") is text, not a link to "#" (WP3 part 2).
        const notWeb = ui.extLink( 'manual', 'Manual', { control: 'site' } );
        r.extLinkNotWeb = [ name( notWeb ), notWeb.tagName.toLowerCase(), notWeb.textContent ];
        r.input = name( ui.input( { type: 'url', control: 'listing-url' } ) );
        r.checkbox = name( ui.checkbox( { control: 'pick' } ) );
        r.textarea = name( ui.textarea( { control: 'note' } ) );
        r.select = name( ui.select( { options: [ 'a', 'b' ], value: 'b', control: 'status' } ) );
        r.toggle = name( ui.toggleSwitch( { label: 'On', control: 'flag' } ) );
        r.fieldByName = name( ui.formField( { type: 'text', name: 'site_name', label: 'Site' } ).input );
        r.fieldById = name( ui.formField( { type: 'toggle', label: 'On', control: 'field-x' } ).input );
        r.switchRow = name( ui.switchRow( { label: 'Auto', control: 'autopilot' } ).toggle );
        r.details = name( ui.details( 'More', 'x', { control: 'more' } ).querySelector( 'summary' ) );
        r.staticTile = name( ui.statTile( { label: 'Static', value: 1, control: 'tile' } ) );
        r.linkTile = name( ui.statTile( { label: 'Linked', value: 1, href: '#', control: 'tile' } ) );
        r.tabs = all( ui.tabs( { items: [ { id: 'pending_review', label: 'Pending' }, { id: 'all', label: 'All' } ], control: 'tab' } ).el );
        r.table = all( ui.dataTable( { rows: [ { id: 1 } ], columns: [ { key: 'id', label: 'ID', sortable: true } ], onSort: () => {}, sort: { key: 'id', dir: 'desc' }, onRowClick: () => {}, control: 'list' } ) );
        r.pager = all( ui.pagination( { page: 1, pages: 2, total: 30, perPage: 25, onChange: () => {}, onPerPage: () => {}, control: 'list-pager' } ) );
        r.filterBar = all( ui.filterBar( { fields: [ { name: 'status', label: 'Status', type: 'select', options: [ 'a' ] }, { name: 'q', label: 'Search', type: 'search' } ], control: 'list-filter' } ).el );
        r.shellId = ui.controlName( 'shell.theme' );
        r.kebab = [ ui.kebab( 'Save changes' ), ui.kebab( 'pending_review' ), ui.kebab( '  Éé & Co.  ' ), ui.controlId( 'drawer', 'tab', 'contacts' ), ui.controlId( 'a', '', 'b' ), ui.controlId( 'shell.notifications-drawer', 'close' ) ];
        r.scopedDrawer = name( ui.drawer( 'Scoped', 'x', { control: 'shell.panel' } ).el.querySelector( '.lga-drawer-head button' ) );
        ui.closeOverlays();
        ui.confirm( 'Sure?', { control: 'delete-confirm' } );
        await new Promise( ( res ) => setTimeout( res, 50 ) );
        r.confirm = all( document.querySelector( '.lga-modal' ) );
        document.querySelector( '.lga-modal [data-lga-control="probe.delete-confirm-cancel"]' ).click();
        return r;
    }, `${ PLUGIN }assets/js/ui.js` );
    out.errors = s.errors;
    await s.context.close();
    return out;
} );

await scenario( 'surfacedErrors', async () => {
    // WP3 part 2 (found by the E2E suite): a button whose handler ends on a refused request, which api.js has already
    // shown as an error toast, is not an uncaught error on the page; any other error in a handler still is.
    const refusal = { code: 'lga_probe_refused', message: 'Probe refused: add the API key first.', data: { status: 400 } };
    const s = await open( 'probe', { pages: PAGES, settingsTabs: SPA.settingsTabs }, ( method, p ) => ( p === '/probe/refused' ? { __status: 400, body: refusal } : undefined ) );
    await s.page.evaluate( async ( base ) => {
        const ui = await import( base + 'ui.js' );
        const api = await import( base + 'api.js' );
        document.querySelector( '#lga-main' ).append(
            ui.button( 'Refused', { control: 'refused', onClick: async () => {
                await api.post( '/probe/refused', {} );
            } } ),
            ui.button( 'Silent refusal', { control: 'silent', onClick: async () => {
                await api.post( '/probe/refused', {}, { silent: true } );
            } } ),
            ui.button( 'Broken', { control: 'broken', onClick: async () => {
                throw new Error( 'Probe: a bug in a handler' );
            } } ),
        );
    }, `${ PLUGIN }assets/js/` );
    const state = ( id ) => s.page.evaluate( ( sel ) => {
        const b = document.querySelector( sel );
        return { disabled: b.disabled, busy: b.getAttribute( 'aria-busy' ) };
    }, `[data-lga-control="probe.${ id }"]` );
    const toasts = () => s.page.evaluate( () => [ ...document.querySelectorAll( '.lga-toast' ) ].map( ( t ) => t.textContent.trim() ) );
    const out = {};
    await s.page.click( '[data-lga-control="probe.refused"]' );
    await s.page.waitForSelector( '.lga-toast', { timeout: 5000 } ).catch( () => null );
    await s.page.waitForTimeout( 300 );
    out.refused = { errors: [ ...s.errors ], toasts: await toasts(), button: await state( 'refused' ) };
    await s.page.click( '[data-lga-control="probe.silent"]' );
    await s.page.waitForTimeout( 500 );
    out.silent = { errors: [ ...s.errors ], toasts: await toasts(), button: await state( 'silent' ) };
    await s.page.click( '[data-lga-control="probe.broken"]' );
    await s.page.waitForTimeout( 500 );
    out.broken = { errors: [ ...s.errors ], button: await state( 'broken' ) };
    out.requests = s.requests.filter( ( r ) => r.path === '/probe/refused' ).length;
    await s.context.close();
    return out;
} );

await browser.close();
print( { helpers, results } );
