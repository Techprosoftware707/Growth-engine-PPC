/**
 * Browser harness for SubmitterUiTest: loads the real admin SPA (app.js + pages/*.js) in headless Chrome through
 * Playwright with mocked REST answers, once as a submitter-only user ("Link Growth Submitter" role,
 * capabilities.submitOnly) and once as the owner, and prints one JSON object with observations (or
 * {"skip": "..."} when Playwright or a browser is not available).
 *
 * The submitter mock answers only the three routes the server opens to that role (GET /submissions,
 * GET /submissions/{id}, POST /submissions/{id}/mark-submitted) and records every other request as forbidden
 * with a 403, so the test sees whether the UI calls anything it is not allowed to.
 *
 * Usage: node SubmitterUiHarness.mjs <pluginDir> <playwrightModulePath> [chromeExecutable]
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
const REST = '/wp-json/ppclg/v1';
const VERSION = '9.9.9';
const LIST = { items: [], total: 0, page: 1, per_page: 25, pages: 1, unread: 0 };
const ALL_PAGES = { overview: 'Overview', prospects: 'Prospects', submissions: 'Submissions', outreach: 'Outreach', backlinks: 'Backlinks', seo: 'On-site SEO', automation: 'Automation', settings: 'Settings', logs: 'Logs' };

function importMap() {
    const imports = {};
    for ( const dir of [ 'assets/js', 'assets/js/pages' ] ) {
        for ( const f of fs.readdirSync( path.join( pluginDir, dir ) ) ) {
            if ( f.endsWith( '.js' ) && f !== 'app.js' ) {
                imports[ `${ PLUGIN }${ dir }/${ f }` ] = `${ PLUGIN }${ dir }/${ f }?ver=${ VERSION }`;
            }
        }
    }
    return { imports };
}

function adminHtml( page, extra ) {
    const config = { page, restRoot: `${ ORIGIN }${ REST }/`, nonce: 'x', version: VERSION, siteDomain: 'example.ca', automationEnabled: true, ...extra };
    return `<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="${ PLUGIN }assets/css/admin.css?ver=${ VERSION }"></head><body>`
        + `<div class="wrap ppclg-wrap"><div id="ppclg-app" data-page="${ page }"></div></div>`
        + `<script type="application/json" id="ppclg-config">${ JSON.stringify( config ) }</script>`
        + `<script type="importmap">${ JSON.stringify( importMap() ) }</script>`
        + `<script type="module" src="${ PLUGIN }assets/js/app.js?ver=${ VERSION }"></script></body></html>`;
}

async function open( pageName, config, mock, hash = '' ) {
    const context = await browser.newContext();
    const requests = [];
    const errors = [];
    await context.route( `${ ORIGIN }/**`, async ( route ) => {
        const req = route.request();
        const url = new URL( req.url() );
        if ( url.pathname === '/wp-admin/admin.php' ) {
            return route.fulfill( { contentType: 'text/html', body: adminHtml( pageName, config ) } );
        }
        if ( url.pathname.startsWith( REST ) ) {
            const p = url.pathname.slice( REST.length );
            let body = null;
            try {
                body = req.postData() ? JSON.parse( req.postData() ) : null;
            } catch ( e ) {
                body = null;
            }
            requests.push( { method: req.method(), path: p, query: Object.fromEntries( url.searchParams ), body } );
            const r = mock( req.method(), p, body, url );
            if ( r && typeof r === 'object' && r.__status ) {
                return route.fulfill( { status: r.__status, contentType: 'application/json', body: JSON.stringify( r.json ) } );
            }
            return route.fulfill( { contentType: 'application/json', body: JSON.stringify( r === undefined ? LIST : r ) } );
        }
        if ( url.pathname.startsWith( PLUGIN_PATH ) ) {
            const rel = url.pathname.slice( PLUGIN_PATH.length );
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
    await page.waitForSelector( '.ppclg-page-head, .ppclg-load-failure', { timeout: 8000 } ).catch( () => null );
    await page.waitForTimeout( 600 );
    const toasts = () => page.$$eval( '.ppclg-toast', ( ns ) => ns.map( ( n ) => ( {
        kind: ( Array.from( n.classList ).find( ( c ) => c.startsWith( 'ppclg-toast--' ) ) || '' ).replace( 'ppclg-toast--', '' ),
        text: n.textContent.trim(),
    } ) ) );
    const settle = ( ms = 500 ) => page.waitForTimeout( ms );
    return { context, page, requests, errors, toasts, settle };
}

const results = {};
const scenario = async ( name, fn ) => {
    try {
        results[ name ] = await fn();
    } catch ( e ) {
        results[ name ] = { failed: String( e && e.stack ? e.stack : e ).slice( 0, 1500 ) };
    }
};

const pkg = {
    source: 'verified_db', item_id: 'c0520cd691098362', tier: 1, authority: 100, outreach: false, conditional: true,
    platform: 'Lab Supplier Directory', target_url: 'https://labdirectory.example/add-listing', business_name: 'Pure Peptides Canada', website: 'https://purepeptidescanada.com',
    description: 'Pure Peptides Canada supplies research peptides and laboratory reagents for in-vitro research use only.', category_rule: 'Laboratory supplies.',
    identity_rule: 'Personal-profile platforms need a real staff member with disclosed affiliation.', steps: 'Create an account.\nAdd the listing.',
};
const ownerRow = { id: 501, status: 'needs_manual', method: 'manual', target_url: pkg.target_url, created_at: '2026-11-12 10:00:00', updated_at: '2026-11-12 10:00:00', prospect_name: 'Lab Supplier Directory', prospect_domain: 'labdirectory.example', payload: { package: { source: 'verified_db', item_id: pkg.item_id, platform: pkg.platform, tier: 1, authority: 100, outreach: false, conditional: true } } };
const policyRow = { id: 502, status: 'needs_manual', method: 'manual', target_url: 'https://resources.example/submit', created_at: '2026-11-12 10:00:00', updated_at: '2026-11-12 10:00:00', prospect_name: 'Resources', prospect_domain: 'resources.example', payload: { instructions_count: 1 } };
const ownerDetail = { ...ownerRow, payload: { package: pkg, instructions: [ 'Create an account.' ] } };
const policyFields = [
    { name: 'company', label: 'Company name', type: 'text', semantic: 'business_name', required: true, value: 'Pure Peptides Canada' },
    { name: 'cat', label: 'Directory category', type: 'select', semantic: 'category', required: true, value: '7', matched_option: { value: '7', text: 'Laboratory supplies' } },
    { name: 'blurb', label: 'Short blurb', type: 'textarea', semantic: 'short_description', required: false, value: 'Research reagents, cut to the form limit.' },
    { name: '_token', label: 'Token', type: 'hidden', semantic: 'unknown', required: false, value: 'third-party-csrf-token' },
];
const policyDetail = { ...policyRow, payload: { business: { name: 'Pure Peptides Canada', website: 'https://purepeptidescanada.com' }, content: { description: 'Research supplier.', category_suggestions: [ 'Peptide suppliers' ], keywords: [ 'research peptides', 'lab reagents' ] }, fields: policyFields, instructions: [ 'Open the form.', 'Paste the details.' ] } };
// An outreach owner task (the server keeps these with the owner; the UI must not invite the submitter to pitch).
const outreachPkg = { ...pkg, item_id: 'out503', platform: 'University library guide', outreach: true, conditional: false, contact_email: 'librarian@library.example', target_url: 'https://library.example/guide' };
const outreachRow = { ...ownerRow, id: 503, target_url: outreachPkg.target_url, prospect_name: 'Library', prospect_domain: 'library.example', payload: { package: { source: 'verified_db', item_id: 'out503', platform: outreachPkg.platform, tier: 2, authority: 80, outreach: true, conditional: false } } };
const outreachDetail = { ...outreachRow, payload: { package: outreachPkg } };
const SUBMITTER_ROUTES = [ 'GET /submissions', 'GET /submissions/501', 'GET /submissions/502', 'GET /submissions/503', 'POST /submissions/501/mark-submitted', 'POST /submissions/502/mark-submitted' ];

function submitterMock( forbidden ) {
    return ( m, p, body ) => {
        const key = `${ m } ${ p }`;
        if ( ! SUBMITTER_ROUTES.includes( key ) ) {
            forbidden.push( key );
            return { __status: 403, json: { code: 'ppclg_forbidden', message: 'You are not allowed to use the Link Growth Engine.', data: { status: 403 } } };
        }
        if ( key === 'GET /submissions' ) {
            return { items: [ ownerRow, policyRow, outreachRow ], total: 3, page: 1, per_page: 25, pages: 1, facets: { status: { needs_manual: 3 } } };
        }
        if ( key === 'GET /submissions/501' ) {
            return { submission: ownerDetail, prospect: { domain: 'labdirectory.example', name: 'Lab Supplier Directory' }, opportunity: null };
        }
        if ( key === 'GET /submissions/502' ) {
            // The submitter's opportunity object as an older server sent it: type, title and page, no id.
            return { submission: policyDetail, prospect: { domain: 'resources.example', name: 'Resources' }, opportunity: { type: 'resource_page', title: 'Peptide research resources', url: 'https://resources.example/links' } };
        }
        if ( key === 'GET /submissions/503' ) {
            return { submission: outreachDetail, prospect: { domain: 'library.example', name: 'Library' }, opportunity: null };
        }
        return { ok: true, action: 'mark-submitted', message: 'Marked as submitted; verification starts on the next run.', submission: { ...( p.includes( '501' ) ? ownerDetail : policyDetail ), status: 'submitted', payload: body } };
    };
}

const drawerInfo = async ( s ) => {
    await s.page.waitForSelector( '.ppclg-drawer', { timeout: 5000 } ).catch( () => null );
    await s.settle( 600 );
    const drawer = s.page.locator( '.ppclg-drawer' ).last();
    return {
        drawer,
        text: await drawer.textContent(),
        buttons: await drawer.locator( 'button.ppclg-btn, a.ppclg-btn' ).evaluateAll( ( ns ) => ns.map( ( n ) => n.textContent.trim() ) ),
        textareas: await drawer.locator( 'textarea' ).count(),
    };
};

await scenario( 'submitter', async () => {
    const forbidden = [];
    // The server sends only the owner-task page; the client must not show other tabs even when given more.
    const config = { capabilities: { manage: false, manage_options: false, submit: true, submitOnly: true }, pages: ALL_PAGES, automationEnabled: false };
    const s = await open( 'overview', config, submitterMock( forbidden ) );
    const out = {};
    out.navLinks = await s.page.$$eval( '.ppclg-subnav-link', ( ns ) => ns.map( ( n ) => n.textContent.trim() ) );
    out.bell = await s.page.locator( 'button[aria-label^="Notifications"]' ).count();
    out.pill = await s.page.locator( '.ppclg-pill' ).count();
    out.pageClass = await s.page.locator( '.ppclg-page-submissions' ).count();
    out.title = await s.page.locator( '.ppclg-page-title' ).first().textContent();
    out.tabs = await s.page.$$eval( '.ppclg-tabs [role=tab], .ppclg-tab', ( ns ) => ns.map( ( n ) => n.textContent.trim() ) );
    out.verifiedCards = await s.page.locator( '.ppclg-card', { hasText: 'Verified opportunity database' } ).count();
    out.bannerHosts = await s.page.locator( '.ppclg-compliance-host' ).count();
    out.filterLabels = await s.page.$$eval( '.ppclg-field-label, .ppclg-field label', ( ns ) => ns.map( ( n ) => n.textContent.trim() ) );
    out.listQuery = s.requests.filter( ( r ) => r.path === '/submissions' ).map( ( r ) => r.query )[ 0 ] || null;
    out.headerText = await s.page.locator( '.ppclg-header' ).textContent();
    out.footer = await s.page.locator( '.ppclg-footer' ).textContent();

    // Owner task (verified database).
    await s.page.locator( 'tbody tr' ).first().click();
    const owner = await drawerInfo( s );
    out.ownerText = owner.text;
    out.ownerButtons = owner.buttons;
    await owner.drawer.locator( 'input[type=url]' ).first().fill( 'https://labdirectory.example/listing/ppc' );
    await owner.drawer.locator( 'button.ppclg-btn:has-text("Mark submitted")' ).first().click();
    await s.settle( 800 );
    out.ownerMark = s.requests.filter( ( r ) => r.path === '/submissions/501/mark-submitted' ).map( ( r ) => r.body );
    await s.page.keyboard.press( 'Escape' );
    await s.settle( 400 );

    // Needs-manual kit from the policy (not a verified-database task): read-only cards and the modal form.
    await s.page.locator( 'tbody tr' ).nth( 1 ).click();
    const policy = await drawerInfo( s );
    out.policyText = policy.text;
    out.policyButtons = policy.buttons;
    out.policyTextareas = policy.textareas;
    await policy.drawer.locator( 'button.ppclg-btn:has-text("Mark submitted")' ).first().click();
    await s.settle( 500 );
    const modal = s.page.locator( '.ppclg-modal' ).last();
    await modal.locator( 'input[type=url]' ).first().fill( 'https://resources.example/listing/ppc' );
    await modal.locator( 'button.ppclg-btn:has-text("Mark submitted")' ).last().click();
    await s.settle( 800 );
    out.policyMark = s.requests.filter( ( r ) => r.path === '/submissions/502/mark-submitted' ).map( ( r ) => r.body );
    out.policyCards = await policy.drawer.locator( '.ppclg-card-title' ).evaluateAll( ( ns ) => ns.map( ( n ) => n.textContent.trim() ) ).catch( () => [] );
    await s.page.keyboard.press( 'Escape' );
    await s.settle( 400 );

    // Outreach owner task: no invitation to pitch, no contact address, no "Mark submitted".
    await s.page.locator( 'tbody tr' ).nth( 2 ).click();
    const outreach = await drawerInfo( s );
    out.outreachText = outreach.text;
    out.outreachButtons = outreach.buttons;

    out.forbidden = forbidden;
    out.toasts = await s.toasts();
    out.errors = s.errors;
    await s.context.close();
    return out;
} );

await scenario( 'admin', async () => {
    const config = { capabilities: { manage: true, manage_options: true, submit: true, submitOnly: false }, pages: ALL_PAGES };
    const s = await open( 'submissions', config, ( m, p ) => {
        if ( p === '/submissions' && m === 'GET' ) {
            return { items: [ { ...policyRow, status: 'pending_review' } ], total: 1, page: 1, per_page: 25, pages: 1 };
        }
        if ( p === '/submissions/502' ) {
            return { submission: { ...policyDetail, status: 'pending_review' }, prospect: { id: 9, domain: 'resources.example', name: 'Resources' }, opportunity: null, submitter_blockers: [ 'The listing text fails the claims check. The owner must fix the text first.' ] };
        }
        if ( p === '/compliance/storefront' || p === '/submissions/verified' ) {
            return { __status: 404, json: { code: 'rest_no_route', message: 'No route', data: { status: 404 } } };
        }
        return undefined;
    } );
    const out = {};
    out.navLinks = await s.page.$$eval( '.ppclg-subnav-link', ( ns ) => ns.map( ( n ) => n.textContent.trim() ) );
    out.bell = await s.page.locator( 'button[aria-label^="Notifications"]' ).count();
    out.pill = await s.page.locator( '.ppclg-pill' ).count();
    out.tabs = await s.page.$$eval( '.ppclg-tabs [role=tab], .ppclg-tab', ( ns ) => ns.map( ( n ) => n.textContent.trim() ) );
    await s.page.locator( 'tbody tr' ).first().click();
    const d = await drawerInfo( s );
    out.drawerButtons = d.buttons;
    out.drawerText = d.text;
    out.errors = s.errors;
    await s.context.close();
    return out;
} );

await scenario( 'settingsCard', async () => {
    const config = { capabilities: { manage: true, manage_options: true, submit: true, submitOnly: false }, pages: ALL_PAGES, newUserUrl: `${ ORIGIN }/wp-admin/user-new.php` };
    const s = await open( 'settings', config, ( m, p ) => ( p === '/settings' ? { __status: 500, json: { code: 'x', message: 'not under test', data: { status: 500 } } } : undefined ) );
    const card = s.page.locator( '.ppclg-submitter-role-card' );
    const out = {
        count: await card.count(),
        text: await card.first().textContent().catch( () => '' ),
        links: await card.locator( 'a' ).evaluateAll( ( ns ) => ns.map( ( n ) => ( { href: n.getAttribute( 'href' ), text: n.textContent.trim(), target: n.getAttribute( 'target' ) } ) ) ).catch( () => [] ),
        mail: s.requests.filter( ( r ) => r.method !== 'GET' ).map( ( r ) => r.path ),
        errors: s.errors,
    };
    await s.context.close();
    // Without the URL (e.g. an older bootstrap) the card is not shown.
    const s2 = await open( 'settings', { ...config, newUserUrl: 'javascript:alert(1)' }, ( m, p ) => ( p === '/settings' ? { __status: 500, json: { code: 'x', message: 'x', data: { status: 500 } } } : undefined ) );
    out.unsafeCount = await s2.page.locator( '.ppclg-submitter-role-card' ).count();
    await s2.context.close();
    return out;
} );

await browser.close();
print( { results } );
