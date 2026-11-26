/**
 * Browser harness for CampaignUiTest (1.2.0 Autopilot / campaign fixes): loads the real admin SPA in headless
 * Chrome through Playwright, with REST answered by per-scenario mocks shaped like the PHP controllers' payloads.
 * Prints one JSON object with raw observations, or {"skip": "..."} when Playwright or a browser is missing.
 *
 * Usage: node CampaignUiHarness.mjs <pluginDir> <playwrightModulePath> [chromeExecutable]
 */
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';

const [ pluginDirArg, playwrightPath, chromePath ] = process.argv.slice( 2 );
const pluginDir = path.resolve( pluginDirArg || '.' ) + path.sep;
// lga-config's pages and Settings tabs as Admin\Menu prints them (WP3: the router and the Settings tabs are built from them).
const SPA = JSON.parse( fs.readFileSync( path.join( pluginDir, 'tests/fixtures/spa-config.json' ), 'utf8' ) );
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

const ORIGIN = 'http://lga.test';
const PLUGIN_PATH = '/wp-content/plugins/linkgrowth-autopilot/';
const PLUGIN = ORIGIN + PLUGIN_PATH;
const REST = '/wp-json/lga/v1';
const VERSION = '9.9.9';
const LIST = { items: [], total: 0, page: 1, per_page: 25, pages: 1, unread: 0 };

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

function adminHtml( page ) {
    const config = {
        pages: SPA.pages,
        settingsTabs: SPA.settingsTabs,
        page,
        restRoot: `${ ORIGIN }${ REST }/`,
        nonce: 'x',
        version: VERSION,
        siteDomain: 'example.ca',
        automationEnabled: true,
    };
    return `<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="${ PLUGIN }assets/css/admin.css?ver=${ VERSION }"></head><body>`
        + `<div class="wrap lga-wrap"><div id="lga-app" data-page="${ page }"></div></div>`
        + `<script type="application/json" id="lga-config">${ JSON.stringify( config ) }</script>`
        + `<script type="importmap">${ JSON.stringify( importMap() ) }</script>`
        + `<script type="module" src="${ PLUGIN }assets/js/app.js?ver=${ VERSION }"></script></body></html>`;
}

/**
 * Opens one admin page. mock(method, path, body, url) answers REST calls: a plain value is sent as a 200 JSON
 * body, { status, json } sets the status, undefined falls back to an empty list payload.
 */
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
    await page.waitForSelector( '.lga-page-head, .lga-load-failure', { timeout: 8000 } ).catch( () => null );
    await page.waitForTimeout( 500 );
    const toasts = () => page.$$eval( '.lga-toast', ( ns ) => ns.map( ( n ) => ( {
        kind: ( Array.from( n.classList ).find( ( c ) => c.startsWith( 'lga-toast--' ) ) || '' ).replace( 'lga-toast--', '' ),
        text: n.textContent.trim(),
    } ) ) );
    const clearToasts = () => page.$$eval( '.lga-toast', ( ns ) => ns.forEach( ( n ) => n.remove() ) );
    const modalText = () => page.$$eval( '.lga-modal', ( ns ) => ns.map( ( n ) => n.textContent.trim() ) );
    const settle = ( ms = 500 ) => page.waitForTimeout( ms );
    const posts = ( p ) => requests.filter( ( r ) => r.method === 'POST' && ( ! p || r.path === p ) );
    return { context, page, requests, errors, toasts, clearToasts, modalText, settle, posts };
}

const results = {};
const scenario = async ( name, fn ) => {
    try {
        results[ name ] = await fn();
    } catch ( e ) {
        results[ name ] = { failed: String( e && e.stack ? e.stack : e ).slice( 0, 1500 ) };
    }
};

const LIVE_SENDER = {
    ok: true, missing: [], problems: [], warnings: [ 'Sender email domain differs from the site domain; deliverability and trust are better when they match.' ],
    autosend: true, auto_approve: false, autopilot: false, follow_ups: '',
    send_window: { start: 9, end: 17, days: [ 1, 2, 3, 4, 5 ], timezone: 'America/Toronto', timezone_label: 'Toronto time', timezone_source: 'region', wp_timezone: 'UTC', open_now: false, next_open: 'Monday 09:00 Toronto time' },
};

// The owner's flow: open Messages, create a campaign from the header button. The new campaign must be shown
// (Campaigns tab), with its drafts and a "What happens next" line; the Messages filter must list it afterwards.
await scenario( 'createFromMessages', async () => {
    const campaigns = [ { id: 1, name: 'Auto: Industry partnership', status: 'approved', approach: 'partnership', settings: { auto_created: true }, stats: {}, counts: { draft: 4 }, next_step: { state: 'waiting', text: '4 drafts are waiting for your approval.', waiting: 4, needs_edit: 0 } } ];
    const created = { id: 5, name: 'Run 1', status: 'draft', approach: 'resource_page', settings: { daily_limit: 5, follow_ups: true }, stats: {}, counts: { draft: 2 }, next_step: { state: 'waiting', text: 'Waiting for your approval: approve the campaign and its 2 drafts so the engine can send them.', waiting: 2, needs_edit: 0 } };
    let approveAll = 0;
    const s = await open( 'outreach', ( m, p ) => {
        if ( p === '/outreach/sender' ) {
            return LIVE_SENDER;
        }
        if ( p === '/outreach/approaches' ) {
            return { approaches: { resource_page: 'Resource page inclusion', partnership: 'Industry partnership' }, descriptions: {}, variants: {} };
        }
        if ( p === '/outreach/campaigns' && m === 'GET' ) {
            return { items: campaigns, total: campaigns.length, page: 1, per_page: 100, pages: 1 };
        }
        if ( p === '/outreach/campaigns' && m === 'POST' ) {
            campaigns.unshift( created );
            return { ok: true, campaign_id: 5, campaign: created, drafted: 2, eligible: 2, remaining: 0, approved: 0, reasons: {}, explanation: '', queued: false, autopilot: false };
        }
        if ( p === '/outreach/campaigns/5/approve-drafts' ) {
            approveAll++;
            created.next_step = { state: 'waiting', text: 'Waiting for your approval: approve the campaign so the engine can send them.', waiting: 0, needs_edit: 0 };
            created.counts = { approved: 2 };
            return { ok: true, approved: 2, failed: {}, remaining: 0, campaign: created };
        }
        if ( /^\/outreach\/campaigns\/\d+$/.test( p ) ) {
            const c = campaigns.find( ( x ) => `/outreach/campaigns/${ x.id }` === p );
            return { campaign: c };
        }
        return undefined;
    }, '#tab=messages' );
    const banner = await s.page.locator( '.lga-callout' ).allTextContents();
    const zoneLine = await s.page.locator( 'p.lga-small', { hasText: 'Send window' } ).allTextContents();
    await s.page.click( '.lga-page-head button:has-text("New campaign")' );
    await s.settle( 300 );
    await s.page.fill( '.lga-modal input[name="name"]', 'Run 1' );
    await s.page.click( '.lga-modal-foot >> text=Create campaign' );
    await s.settle( 900 );
    const toasts = await s.toasts();
    const hash = await s.page.evaluate( () => window.location.hash );
    const activeTab = await s.page.locator( '[role="tab"][aria-selected="true"]' ).first().textContent();
    const cards = await s.page.locator( '.lga-card' ).allTextContents();
    await s.clearToasts();
    await s.page.click( 'button.lga-btn:has-text("Approve all drafts (2)")' );
    await s.settle( 300 );
    await s.page.click( '.lga-modal-foot >> text=Approve all' );
    await s.settle( 900 );
    const afterApprove = await s.toasts();
    // The Messages tab is rebuilt: its campaign filter now lists "Run 1".
    await s.page.click( '[role="tab"]:has-text("Messages")' );
    await s.settle( 900 );
    const filterOptions = await s.page.locator( 'select option' ).allTextContents();
    const out = { banner, zoneLine, toasts, hash, activeTab, cards, afterApprove, approveAll, filterOptions, posts: s.posts( '/outreach/campaigns' ).map( ( r ) => r.body ), errors: s.errors };
    await s.context.close();
    return out;
} );

// Autopilot card on the Automation page: turning it on asks first (policy text + hard limits) and posts confirm.
await scenario( 'autopilotSwitch', async () => {
    let on = false;
    const status = () => ( {
        enabled: on, automation_enabled: true, consent: on ? { user_id: 1, at: '2026-11-15T10:00:00+00:00', policy_version: '1.2.0-1' } : null,
        policy_version: '1.2.0-1', policy_text: 'Turn on Autopilot? The engine will then work without asking you first.',
        hard_limits: [ 'No account creation or sign-ups on third-party sites.', 'No blog comment or forum posting.' ],
        owner_tasks_note: 'Sites that need an account stay owner tasks.',
        reply_tracking: false, follow_ups: { automatic: false, note: 'Follow-ups are not sent automatically: reply tracking is not set up.' },
        effective: {},
    } );
    const s = await open( 'automation', ( m, p, body ) => {
        if ( p === '/automation/status' ) {
            return { automation_enabled: true, flags: { enabled: true, submissions: false, outreach_autosend: true }, effective_flags: { submissions: on, outreach_autosend: true }, limits: {}, stages: {}, queue: {}, recent_jobs: [], scheduler: { backend: 'wp_cron' }, autopilot: status() };
        }
        if ( p === '/automation/autopilot' && m === 'POST' ) {
            on = Boolean( body && body.enabled );
            return { ok: true, ...status(), reverted: on ? undefined : 3 };
        }
        return undefined;
    } );
    const card = s.page.locator( '.lga-card', { has: s.page.locator( '.lga-card-head', { hasText: 'Autopilot' } ) } ).first();
    const before = await card.textContent();
    await card.locator( 'button.lga-toggle' ).first().click();
    await s.settle( 300 );
    const dialog = await s.modalText();
    await s.page.click( '.lga-modal-foot >> text=Turn on Autopilot' );
    await s.settle( 900 );
    const toastsOn = await s.toasts();
    const after = await s.page.locator( '.lga-card', { has: s.page.locator( '.lga-card-head', { hasText: 'Autopilot' } ) } ).first().textContent();
    const raised = await s.page.locator( '.lga-switch-row', { hasText: 'Automated submissions' } ).first().textContent();
    const out = { before, dialog, toastsOn, after, raised, posts: s.posts( '/automation/autopilot' ).map( ( r ) => r.body ), errors: s.errors };
    await s.context.close();
    return out;
} );

// "Use <site email>" in the paused-sender banner: the campaign cards are reloaded too, not only the banner.
await scenario( 'useSuggestion', async () => {
    let fixed = false;
    const card = () => ( { id: 7, name: 'Run 3', status: fixed ? 'approved' : 'draft', approach: 'resource_page', settings: { daily_limit: 5 }, stats: {}, counts: fixed ? { approved: 2 } : { draft: 2 },
        next_step: fixed ? { state: 'sending', text: 'Sending automatically: 0 of 5 today; next send window Monday 09:00 Toronto time.', waiting: 0, needs_edit: 0 } : { state: 'blocked', text: 'Paused: the sender email domain cannot receive mail. Fix it under Settings → Outreach; nothing is sent until then.', waiting: 0, needs_edit: 0 } } );
    const s = await open( 'outreach', ( m, p ) => {
        if ( p === '/outreach/sender' ) {
            return fixed ? { ...LIVE_SENDER, autopilot: true, auto_approve: true } : { ...LIVE_SENDER, ok: false, missing: [ 'sender_email_domain' ], problems: [ 'The sender email domain northwind.example.net cannot receive mail.' ], suggestion: { sender_email: 'hi@northwind.example.com', reason: 'Use hi@northwind.example.com (your site email): its domain has a mail server (MX record).' }, autopilot: true, auto_approve: true };
        }
        if ( p === '/outreach/approaches' ) {
            return { approaches: { resource_page: 'Resource page inclusion' }, descriptions: {}, variants: {} };
        }
        if ( p === '/outreach/campaigns' && m === 'GET' ) {
            return { items: [ card() ], total: 1, page: 1, per_page: 100, pages: 1 };
        }
        if ( p === '/settings' && m === 'POST' ) {
            fixed = true;
            return { ok: true, changed: [ 'outreach.sender_email' ], approved_now: 2, settings: {} };
        }
        return undefined;
    } );
    const before = await s.page.locator( '.lga-card', { hasText: 'Run 3' } ).first().textContent();
    await s.page.click( 'button.lga-btn:has-text("Use hi@northwind.example.com")' );
    await s.settle( 900 );
    const after = await s.page.locator( '.lga-card', { hasText: 'Run 3' } ).first().textContent();
    const out = { before, after, toasts: await s.toasts(), errors: s.errors };
    await s.context.close();
    return out;
} );

// Pure helpers of outreach.js, settings.js and overview.js.
await scenario( 'helpers', async () => {
    const s = await open( 'outreach', ( m, p ) => ( p === '/outreach/sender' ? LIVE_SENDER : undefined ) );
    const out = await s.page.evaluate( async ( base ) => {
        const o = await import( `${ base }assets/js/pages/outreach.js` );
        const st = await import( `${ base }assets/js/pages/settings.js` );
        const ov = await import( `${ base }assets/js/pages/overview.js` );
        const au = await import( `${ base }assets/js/pages/automation.js` );
        return {
            createdDrafts: o.campaignCreatedText( { drafted: 3, eligible: 5, remaining: 2, approved: 0, autopilot: false } ),
            createdAutopilot: o.campaignCreatedText( { drafted: 2, eligible: 2, remaining: 0, approved: 2, autopilot: true } ),
            createdEmpty: o.campaignCreatedText( { drafted: 0, eligible: 0, remaining: 0, explanation: 'No qualified prospects with a published email address for resource page inclusion yet; the engine adds them as it evaluates sites.' } ),
            bannerConflict: o.autosendBanner( { autosend: true, auto_approve: false } ),
            bannerAutosend: o.autosendBanner( { autosend: true, auto_approve: true } ),
            bannerAutopilot: o.autosendBanner( { autopilot: true, autosend: true, auto_approve: true, follow_ups: 'Follow-ups are not sent automatically.', send_window: { timezone: 'America/Toronto', timezone_label: 'Toronto time', open_now: false, next_open: 'Monday 09:00 Toronto time' } } ),
            bannerOff: o.autosendBanner( { autosend: false } ),
            zone: st.sendZoneText( { timezone: 'America/Toronto', label: 'Toronto time', source: 'region', wordpress: 'UTC' } ),
            growAutopilot: ov.growPolicyText( { autopilot: true, submissions: true, submission_mode: 'auto', outreach_autosend: true, require_approval: false } ),
            createdBlocked: o.campaignCreatedText( { drafted: 2, eligible: 2, remaining: 0, approved: 0, autopilot: true, campaign: { next_step: { state: 'blocked', text: 'Paused: the sender email domain cannot receive mail. Fix it under Settings → Outreach; nothing is sent until then.' } } } ),
            modesAfterOff: ov.headlineModes( { autopilot: true, links_auto: { enabled: true, present: 3, per_day: 20 } }, { autopilot: false, auto_links: false } ),
            modesNoPolicy: ov.headlineModes( { autopilot: true, links_auto: { enabled: true } }, null ),
            stageOn: au.stageDescription( 'outreach_autosend', true ),
            stageOff: au.stageDescription( 'outreach_autosend', false ),
        };
    }, PLUGIN );
    out.errors = s.errors;
    await s.context.close();
    return out;
} );

await browser.close();
print( { results } );
