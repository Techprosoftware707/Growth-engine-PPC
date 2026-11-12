/**
 * Browser harness for OwnerTaskBrowserTest: loads the real admin SPA (app.js + pages/submissions.js) in headless
 * Chrome through Playwright with mocked REST answers shaped like SubmissionsController / ComplianceController,
 * opens the "Owner tasks" tab and a verified-database owner task, and prints one JSON object with observations
 * (or {"skip": "..."} when Playwright or a browser is not available).
 *
 * Usage: node OwnerTaskBrowserHarness.mjs <pluginDir> <playwrightModulePath> [chromeExecutable]
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

function adminHtml( page ) {
    const config = { page, restRoot: `${ ORIGIN }${ REST }/`, nonce: 'x', version: VERSION, siteDomain: 'example.ca', automationEnabled: true };
    return `<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="${ PLUGIN }assets/css/admin.css?ver=${ VERSION }"></head><body>`
        + `<div class="wrap ppclg-wrap"><div id="ppclg-app" data-page="${ page }"></div></div>`
        + `<script type="application/json" id="ppclg-config">${ JSON.stringify( config ) }</script>`
        + `<script type="importmap">${ JSON.stringify( importMap() ) }</script>`
        + `<script type="module" src="${ PLUGIN }assets/js/app.js?ver=${ VERSION }"></script></body></html>`;
}

async function open( pageName, mock, hash = '' ) {
    const context = await browser.newContext();
    const requests = [];
    const errors = [];
    await context.route( `${ ORIGIN }/**`, async ( route ) => {
        const req = route.request();
        const url = new URL( req.url() );
        if ( url.pathname === '/wp-admin/admin.php' ) {
            return route.fulfill( { contentType: 'text/html', body: adminHtml( pageName ) } );
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
    const clearToasts = () => page.$$eval( '.ppclg-toast', ( ns ) => ns.forEach( ( n ) => n.remove() ) );
    const settle = ( ms = 500 ) => page.waitForTimeout( ms );
    return { context, page, requests, errors, toasts, clearToasts, settle };
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
    source: 'verified_db', item_id: 'c0520cd691098362', tier: 1, authority: 100, outreach: false, conditional: true, data_version: '2026-11-11-029392d3',
    platform: 'Lab Supplier Directory', target_url: 'https://labdirectory.example/add-listing', registration_url: 'https://labdirectory.example/signup', contact_email: '',
    business_name: 'Pure Peptides Canada', website: 'https://purepeptidescanada.com', address: '222 Jarvis St, Toronto ON M5B 2B8, Canada', email: 'hi@purepeptidescanada.com', founded: '2023',
    description: 'Pure Peptides Canada supplies research peptides and laboratory reagents. All research products are sold strictly for in-vitro laboratory research use and are not for human or veterinary use.',
    category_rule: 'Laboratory supplies / biotechnology / scientific research supplier. Never a health, medical, wellness, pharmacy or supplement category.',
    steps: 'Tier 1 | directory | wave 1\nSubmit at: https://labdirectory.example/add-listing\n<img src=x onerror=alert(1)> Use the exact business details',
    requirements: 'Free account.', gate_note: 'Storefront consistency gate: every listing uses the research-use-only description.',
    verification_note: 'HTTP 200 on the form.', link_attribute: 'nofollow', free: 'yes', payment: 'none', approval_required: 'staff-review', effort: '30', risk: 'none',
};
const row = { id: 501, prospect_id: 12, status: 'needs_manual', method: 'manual', adapter: 'manual_package', target_url: pkg.target_url, category: 'Laboratory supplies', attempts: 0, created_at: '2026-11-12 10:00:00', updated_at: '2026-11-12 10:00:00', prospect_name: 'Lab Supplier Directory', prospect_domain: 'labdirectory.example', payload: { package: { source: 'verified_db', item_id: pkg.item_id, platform: pkg.platform, tier: 1, authority: 100, outreach: false, conditional: true } } };
const detail = { ...row, payload: { package: pkg, content: { description: pkg.description }, instructions: pkg.steps.split( '\n' ), compliance: { clean: true, violations: [] } } };
const verifiedStatus = {
    available: true, data_version: '2026-11-11-029392d3', imported_version: '2026-11-11-029392d3', total: 595, imported: 556, skipped: 39, owner_tasks_open: 556, up_to_date: true, in_progress: false,
    skip_reasons: { excluded_platform: 39 }, skipped_items: [ { id: 'x1', platform: 'Google Business Profile (Canada)', host: 'google.com', tier: 1, reason: 'excluded_platform' } ],
    summary: 'Version 2026-11-11-029392d3 imported: 556 of 595 items have owner tasks, 39 skipped; 556 owner tasks are open.', job_registered: true,
};

await scenario( 'ownerTasks', async () => {
    const s = await open( 'submissions', ( m, p ) => {
        if ( p === '/submissions' && m === 'GET' ) {
            return { items: [ row ], total: 1, page: 1, per_page: 25, pages: 1 };
        }
        if ( p === '/submissions/verified' ) {
            return verifiedStatus;
        }
        if ( p === '/submissions/verified/import' ) {
            return { ok: false, message: 'Another import of the verified opportunity database is running; this one was not started.', result: { busy: true } };
        }
        if ( p === '/compliance/storefront' ) {
            return { __status: 404, json: { code: 'rest_no_route', message: 'No route was found matching the URL and request method.', data: { status: 404 } } };
        }
        if ( p === '/submissions/501' && m === 'GET' ) {
            return { submission: detail, prospect: { id: 12, domain: 'labdirectory.example', name: 'Lab Supplier Directory' }, opportunity: null };
        }
        if ( p === '/submissions/501/mark-submitted' ) {
            return { ok: true, action: 'mark-submitted', message: 'Marked as submitted; verification starts on the next run.', submission: { ...detail, status: 'submitted' } };
        }
        return undefined;
    }, '#tab=owner_tasks' );

    const out = {};
    out.listQuery = s.requests.filter( ( r ) => r.path === '/submissions' ).map( ( r ) => r.query )[ 0 ] || null;
    out.verifiedCard = await s.page.locator( '.ppclg-card', { hasText: 'Verified opportunity database' } ).first().textContent();
    out.bannerChildren = await s.page.$$eval( '.ppclg-compliance-host', ( ns ) => ns.map( ( n ) => n.childElementCount ) );
    out.rowBadges = await s.page.locator( 'tbody tr' ).first().textContent();
    out.toastsOnLoad = await s.toasts();

    // Import refused by the server: its message is shown.
    await s.page.click( 'button.ppclg-btn:has-text("Check for changes")' );
    await s.settle( 600 );
    out.importToasts = await s.toasts();
    await s.clearToasts();

    // A tier filter is sent to the server.
    const tierSelect = s.page.locator( '.ppclg-field', { hasText: 'Tier' } ).locator( 'select' ).first();
    await tierSelect.selectOption( '1' );
    await s.settle( 800 );
    const lists = s.requests.filter( ( r ) => r.path === '/submissions' );
    out.tierQuery = lists[ lists.length - 1 ].query;

    // Open the owner task.
    await s.page.locator( 'tbody tr' ).first().click();
    await s.page.waitForSelector( '.ppclg-drawer', { timeout: 5000 } ).catch( () => null );
    await s.settle( 600 );
    const drawer = s.page.locator( '.ppclg-drawer' ).first();
    out.drawerText = await drawer.textContent();
    out.openLinks = await drawer.locator( 'a', { hasText: 'Open submission page' } ).evaluateAll( ( ns ) => ns.map( ( n ) => ( { href: n.getAttribute( 'href' ), target: n.getAttribute( 'target' ), rel: n.getAttribute( 'rel' ) } ) ) );
    out.drawerButtons = await drawer.locator( 'button.ppclg-btn' ).evaluateAll( ( ns ) => ns.map( ( n ) => n.textContent.trim() ) );
    out.injectedImages = await drawer.locator( 'img' ).count();
    out.stepBreaks = await drawer.locator( '.ppclg-owner-steps br' ).count();

    await drawer.locator( 'input[type=url]' ).first().fill( 'https://labdirectory.example/listing/pure-peptides-canada' );
    await drawer.locator( 'button.ppclg-btn:has-text("Mark submitted")' ).first().click();
    await s.settle( 800 );
    out.markRequests = s.requests.filter( ( r ) => r.path === '/submissions/501/mark-submitted' ).map( ( r ) => r.body );
    out.markToasts = await s.toasts();
    out.errors = s.errors;
    await s.context.close();
    return out;
} );

await scenario( 'blockingBanner', async () => {
    const s = await open( 'submissions', ( m, p ) => {
        if ( p === '/compliance/storefront' ) {
            return { status: 'block', effective: 'block', gate: true, blocking: true, checked_at: '2026-11-12T10:00:00+00:00', hash: 'a'.repeat( 40 ), counts: { block: 2, warn: 0 }, findings: [ { severity: 'block', where: 'category', term: 'weight loss', object_id: 11, label: 'Weight Loss', url: '', excerpt: 'Weight Loss' } ], top: [] };
        }
        if ( p === '/submissions/verified' ) {
            return { __status: 404, json: { code: 'rest_no_route', message: 'No route', data: { status: 404 } } };
        }
        return undefined;
    } );
    const out = {
        banner: await s.page.locator( '.ppclg-compliance-host' ).first().textContent(),
        verifiedCards: await s.page.locator( '.ppclg-card', { hasText: 'Verified opportunity database' } ).count(),
        toasts: await s.toasts(),
        errors: s.errors,
    };
    await s.context.close();
    return out;
} );

await browser.close();
print( { results } );
