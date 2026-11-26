/**
 * Browser harness for AutopilotUiTest (1.2.0 Build B: Autopilot in the Grow panel, automatic internal links and
 * their undo, automatically found competitors in Settings): loads the real admin SPA in headless Chrome through
 * Playwright, with REST answered by per-scenario mocks shaped like the PHP controllers' payloads. Prints one JSON
 * object with raw observations, or {"skip": "..."} when Playwright or a browser is missing.
 *
 * Usage: node AutopilotUiHarness.mjs <pluginDir> <playwrightModulePath> [chromeExecutable]
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

const HARD_LIMITS = [ 'No account creation or sign-ups on third-party sites.', 'No CAPTCHA, login-wall or registration bypass.', 'No blog comment or forum posting.' ];
const WILL_RUN = [
    'Send outreach emails in your name: up to 10 a day and 40 a week, only inside the send window, to the published business addresses of relevant websites.',
    'Add up to 20 internal links a day between your own pages (WordPress keeps a revision of each page, and “Undo automatic links” removes them).',
    'Submit listings to open public forms that allow it and pass every safety check (up to 5 a day).',
    'Discover new prospects, evaluate them and monitor your backlinks on schedule; while you list no competitors, find them through SE Ranking and mine their backlinks.',
];
// The real Autopilot text (Autopilot::policyText(), passed by AutopilotUiTest), so the dialog is checked against it.
const POLICY_TEXT = process.env.LGA_POLICY_TEXT || 'Turn on Autopilot? The engine will then work without asking you first.';
const autopilotStatus = ( on ) => ( {
    enabled: on, automation_enabled: true, consent: on ? { user_id: 1, at: '2026-11-15T10:00:00+00:00', policy_version: '1.2.2-1' } : null, consent_current: on,
    policy_version: '1.2.2-1', policy_text: POLICY_TEXT,
    hard_limits: HARD_LIMITS, will_run: WILL_RUN, numbers: { emails_per_day: 10, emails_per_week: 40, submissions_per_day: 5, links_per_day: 20 },
    owner_tasks_note: 'Sites that need an account stay owner tasks: Autopilot never signs up anywhere. You can hand them to a staff member with the LinkGrowth Submitter role.',
    reply_tracking: false, follow_ups: { automatic: false, note: 'Follow-ups are not sent automatically: reply tracking is not set up.' },
    effective: { auto_apply_links: on, links_per_day: 20 },
} );
const policy = ( on ) => ( { autopilot: on, submissions: true, submission_mode: 'auto', outreach_autosend: on, require_approval: ! on, auto_links: on, links_per_day: 20 } );
const steps = ( on ) => [
    { key: 'internal_links', label: on ? 'Add the best internal links automatically' : 'Suggest internal links (applied only by you)', status: 'done', summary: on ? 'Checked 40 items; Applied 3 internal links automatically (3 of 20 today).' : 'Checked 40 items.', counts: {} },
    { key: 'auto_outreach', label: on ? 'Draft and approve outreach (Autopilot sends it)' : 'Draft outreach for your approval', status: 'done', summary: 'Drafted 2.', counts: {} },
    { key: 'summary', label: 'Summarise the results', status: 'done', summary: on ? 'Autopilot approves outreach drafts and sends them inside the send window and daily limits. Autopilot is on: nothing needs your approval.' : '2 outreach drafts await your approval.', counts: {} },
];
const completedRun = ( on ) => ( {
    id: 'r1', status: 'completed', ok: true, started_at: '2026-11-15T10:00:00+00:00', finished_at: new Date().toISOString(), elapsed_seconds: 40,
    steps: steps( on ), progress: { done: 3, total: 3 }, policy: policy( on ),
    summary: { computed_at: '2026-11-15T10:01:00+00:00', autopilot: on, submissions: { owner_tasks: 2 }, outreach_drafts: 2, prospects: { total: 10, qualified: 3 }, backlinks: { live: 1, lost: 0 }, site_audit: null, link_suggestions_pending: 5, links_auto: on ? { enabled: true, autopilot: true, per_day: 20, today: 3, present: 3, undone: 0 } : null },
    next_actions: on ? [] : [ { key: 'outreach_drafts', label: 'Review 2 outreach drafts (nothing is sent without approval)', count: 2, page: 'outreach', params: {} } ],
    needs_you: [],
} );

// The Grow panel carries the Autopilot switch: the confirm dialog lists what runs and the hard limits; once on,
// the panel says nothing needs approval, the step labels and tiles follow, and no second Autopilot card is shown.
await scenario( 'growPanel', async () => {
    let on = false;
    const s = await open( 'overview', ( m, p, body ) => {
        if ( p === '/growth/status' ) {
            return completedRun( on );
        }
        if ( p === '/automation/autopilot' && m === 'GET' ) {
            return autopilotStatus( on );
        }
        if ( p === '/automation/autopilot' && m === 'POST' ) {
            on = Boolean( body && body.enabled );
            return { ok: true, ...autopilotStatus( on ) };
        }
        if ( p === '/compliance/storefront' ) {
            return { status: 'pass', effective: 'pass', blocking: false, counts: { block: 0, warn: 0 }, findings: [] };
        }
        if ( p === '/overview' ) {
            return { stats: {}, charts: {}, notifications: [], providers: {}, automation: { automation_enabled: true }, limits: {}, missing_integrations: [] };
        }
        return undefined;
    } );
    await s.settle( 600 );
    const panel = s.page.locator( '.lga-grow-panel' );
    const before = await panel.textContent();
    const separateCards = await s.page.locator( '.lga-card-head', { hasText: /^Autopilot/ } ).count();
    await panel.locator( '.lga-grow-autopilot button.lga-toggle' ).first().click();
    await s.settle( 300 );
    const dialog = await s.modalText();
    await s.page.click( '.lga-modal-foot >> text=Turn on Autopilot' );
    await s.settle( 1200 );
    const after = await panel.textContent();
    const tiles = await panel.locator( '.lga-stat' ).allTextContents().catch( () => [] );
    const out = { before, separateCards, dialog, after, tiles, posts: s.posts( '/automation/autopilot' ).map( ( r ) => r.body ), errors: s.errors };
    await s.context.close();
    return out;
} );

// On-site SEO: "Applied automatically: N" and "Undo automatic links" (asks first, posts confirm=true).
await scenario( 'seoUndo', async () => {
    let present = 3;
    const s = await open( 'seo', ( m, p ) => {
        if ( p === '/seo/links' ) {
            return { available: true, enabled: true, max_links_per_post: 3, generated_at: '2026-11-15T09:00:00+00:00', progress: { running: false }, counts: { pending: 4, applied: present, dismissed: 3 - present, stale: 0 }, auto: { enabled: true, autopilot: true, per_day: 20, today: 3, present, undone: 3 - present }, status: 'pending', items: [] };
        }
        if ( p === '/seo/links/undo-auto' && m === 'POST' ) {
            present = 0;
            return { ok: true, restored: 2, unlinked: 1, already_gone: 0, refused: 0, left: 0, items: 3, message: 'Removed 3 automatic links (2 restored to the version before the change, 1 unlinked in items edited since). WordPress kept a revision of each edited item.' };
        }
        if ( p === '/seo/audit' ) {
            return { available: true, summary: {}, progress: {}, result: null, issues: [] };
        }
        if ( p === '/seo/indexnow' ) {
            return { available: true, status: { enabled: true }, history: [] };
        }
        return undefined;
    } );
    await s.settle( 600 );
    const card = s.page.locator( '.lga-card', { has: s.page.locator( '.lga-card-head', { hasText: 'Internal link suggestions' } ) } ).first();
    const before = await card.textContent();
    await card.locator( 'button', { hasText: 'Undo automatic links' } ).click();
    await s.settle( 300 );
    const dialog = await s.modalText();
    await s.page.click( '.lga-modal-foot >> text=Undo automatic links' );
    await s.settle( 900 );
    const toasts = await s.toasts();
    const after = await s.page.locator( '.lga-card', { has: s.page.locator( '.lga-card-head', { hasText: 'Internal link suggestions' } ) } ).first().textContent();
    const undoDisabled = await s.page.locator( 'button', { hasText: 'Undo automatic links' } ).first().isDisabled();
    const out = { before, dialog, toasts, after, undoDisabled, posts: s.posts( '/seo/links/undo-auto' ).map( ( r ) => r.body ), errors: s.errors };
    await s.context.close();
    return out;
} );

// 1.2.2 On-site SEO: the list comes a page at a time, "Left for you" and "Not shown on the page" say why, and applying
// a not-shown suggestion by hand warns that visitors will not see it.
await scenario( 'seoLinksPaging', async () => {
    const row = ( id, status, extra = {} ) => ( {
        id, source_post_id: 9000 + id, source_title: `Article ${ id }`, source_url: `https://example.ca/a-${ id }/`, edit_url: `https://example.ca/wp-admin/post.php?post=${ 9000 + id }&action=edit`,
        anchor: 'tapered pipe thread', term: 'tapered pipe thread', target_id: 11839, target_url: 'https://example.ca/pipe-threads/', target_title: 'Tapered pipe threads',
        excerpt: '… PTFE tape seals a tapered pipe thread that tightens as the fitting turns …', relevance: 90, status, auto: false, auto_skip: '', note: '', render_note: '', ...extra,
    } );
    const counts = { pending: 120, applied: 1, dismissed: 0, stale: 2, not_shown: 2, refused: 1 };
    const s = await open( 'seo', ( m, p, body, url ) => {
        if ( p === '/seo/links' && m === 'GET' ) {
            const status = url.searchParams.get( 'status' ) || 'pending';
            const page = Number( url.searchParams.get( 'page' ) || 1 );
            const per = Number( url.searchParams.get( 'per_page' ) || 100 );
            let items = [];
            let total = 0;
            if ( status === 'pending' ) {
                total = 120;
                for ( let i = ( page - 1 ) * per + 1; i <= Math.min( total, page * per ); i++ ) {
                    items.push( row( i, 'pending', i === 2 ? { auto_skip: 'Not applied automatically: WordPress would re-filter other markup in this item when saving it.' } : ( i === 3 ? { render_note: 'Not checked yet: the page answered HTTP 503, so it could not be confirmed that the page shows this text. It is checked again later.' } : {} ) ) );
                }
            } else if ( status === 'refused' ) {
                total = 1;
                items = [ row( 2, 'pending', { auto_skip: 'Not applied automatically: WordPress would re-filter other markup in this item when saving it.' } ) ];
            } else if ( status === 'not_shown' ) {
                total = 2;
                items = [ row( 201, 'not_shown', { note: 'Not added: this page’s template doesn’t display its content, so visitors and Google wouldn’t see the link.' } ), row( 202, 'not_shown', { note: 'Removed automatically: this page’s template doesn’t display its content, so visitors and Google never saw this link.', removed_at: '2026-11-16T09:00:00+00:00', auto: true } ) ];
            } else if ( status === 'stale' ) {
                // 1.2.3: suggestions closed because the link text is the brand name or the target is in another language.
                total = 2;
                items = [
                    row( 301, 'stale', { anchor: 'Northwind Supply Co', term: 'northwind supply co', target_url: 'https://example.ca/fr/a-propos/', target_title: 'À propos', excerpt: '… belong to Northwind Supply Co or its licensors …', note: 'Not suggested: the target page is in another language.', closed_rule: 'language' } ),
                    row( 302, 'stale', { anchor: 'Supply Co', term: 'supply co', target_url: 'https://example.ca/', target_title: 'Home', excerpt: '… belong to Northwind Supply Co or its licensors …', note: 'Not suggested: the link text is your brand name.', closed_rule: 'brand' } ),
                ];
            }
            return { available: true, enabled: true, max_links_per_post: 3, generated_at: '2026-11-16T09:00:00+00:00', progress: { running: false }, counts, auto: { enabled: true, autopilot: true, per_day: 20, today: 1, present: 1, undone: 0, not_shown: 2, left_for_you: 1, removed: 1 }, status, total, page, per_page: per, pages: Math.max( 1, Math.ceil( total / per ) ), items };
        }
        if ( p === '/seo/links/apply' && m === 'POST' ) {
            return { ok: true, applied: 1, refused: 0, results: [ { id: body.ids[ 0 ], ok: true, message: 'Linked “tapered pipe thread” to https://example.ca/pipe-threads/. Note: this page doesn’t display its content (its template shows other content in its place), so visitors and Google won’t see this link.', warning: 'Note: this page doesn’t display its content (its template shows other content in its place), so visitors and Google won’t see this link.' } ], counts };
        }
        if ( p === '/seo/audit' ) {
            return { available: true, summary: {}, progress: {}, result: null, issues: [] };
        }
        if ( p === '/seo/indexnow' ) {
            return { available: true, status: { enabled: true }, history: [] };
        }
        return undefined;
    } );
    await s.settle( 600 );
    const card = () => s.page.locator( '.lga-card', { has: s.page.locator( '.lga-card-head', { hasText: 'Internal link suggestions' } ) } ).first();
    const first = await card().textContent();
    const firstRows = await card().locator( 'tbody tr' ).count();
    await card().locator( 'button', { hasText: 'Next' } ).click();
    await s.settle( 500 );
    const second = await card().textContent();
    await card().locator( 'button', { hasText: 'Left for you' } ).click();
    await s.settle( 500 );
    const refused = await card().textContent();
    await card().locator( 'button', { hasText: 'Not shown on the page' } ).click();
    await s.settle( 500 );
    const notShown = await card().textContent();
    await card().locator( 'tbody tr' ).first().locator( 'button', { hasText: 'Apply' } ).click();
    await s.settle( 700 );
    const toasts = await s.toasts();
    await card().locator( 'button', { hasText: 'No longer valid' } ).click();
    await s.settle( 500 );
    const stale = await card().textContent();
    const staleRows = await card().locator( 'tbody tr' ).count();
    const gets = s.requests.filter( ( r ) => r.method === 'GET' && r.path === '/seo/links' ).map( ( r ) => r.query );
    const out = { first, firstRows, second, refused, notShown, stale, staleRows, toasts, gets, posts: s.posts( '/seo/links/apply' ).map( ( r ) => r.body ), errors: s.errors };
    await s.context.close();
    return out;
} );

// Settings → Discovery: the automatically found competitors are listed apart from the owner's and can be removed.
await scenario( 'settingsDiscovery', async () => {
    const settings = {
        discovery: { competitor_domains: [], competitor_domains_auto: [ 'canadalabsupply.com', 'northernbioreagents.ca' ], geo_modifiers: [], brand_terms: [], custom_queries: [], excluded_domains: [], query_cooldown_days: 30, results_per_query: 10, seed_catalog: true },
        seo: { indexnow: true, internal_links: true, max_links_per_post: 3, auto_apply_links: false, auto_apply_per_day: 20, audit_max_pages: 400, audit_http_sample: true },
        automation: { enabled: true, autopilot: true },
    };
    const s = await open( 'settings', ( m, p ) => {
        if ( p === '/settings' && m === 'GET' ) {
            return { settings, defaults: settings, secrets: {}, providers: {}, seeds: [], custom_seeds: [], facts: {}, query_pool_size: 0 };
        }
        if ( p === '/settings' && m === 'POST' ) {
            return { ok: true, settings };
        }
        return undefined;
    }, '#tab=discovery' );
    await s.settle( 600 );
    const field = s.page.locator( '.lga-field', { hasText: 'Found automatically by Autopilot' } ).first();
    const label = await field.textContent();
    const area = field.locator( 'textarea' );
    const value = await area.inputValue();
    await area.fill( 'canadalabsupply.com' );
    await area.dispatchEvent( 'input' );
    await area.dispatchEvent( 'change' );
    await s.page.locator( 'button', { hasText: 'Save changes' } ).first().click();
    await s.settle( 900 );
    const saved = s.posts( '/settings' ).map( ( r ) => r.body );
    await s.page.evaluate( () => {
        window.location.hash = 'tab=automation';
    } );
    await s.settle( 500 );
    const automationText = await s.page.locator( '#lga-app' ).textContent();
    const out = { label, value, saved, automationText, errors: s.errors };
    await s.context.close();
    return out;
} );

// 1.2.2 Settings → Automation under Autopilot: switching "Add the best internal links automatically" on still shows
// its confirmation (the text says a page with administrator-only markup is saved as your account) and posts
// confirm=true; cancelling saves nothing.
await scenario( 'settingsAutoLinks', async () => {
    const settings = {
        seo: { indexnow: true, internal_links: true, max_links_per_post: 3, auto_apply_links: false, auto_apply_per_day: 20, audit_max_pages: 400, audit_http_sample: true },
        automation: { enabled: true, autopilot: true },
    };
    const s = await open( 'settings', ( m, p ) => {
        if ( p === '/settings' && m === 'GET' ) {
            return { settings, defaults: settings, secrets: {}, providers: {}, seeds: [], custom_seeds: [], facts: {}, query_pool_size: 0, autopilot: autopilotStatus( true ) };
        }
        if ( p === '/settings' && m === 'POST' ) {
            return { ok: true, settings };
        }
        return undefined;
    }, '#tab=automation' );
    await s.settle( 600 );
    const toggle = s.page.locator( 'button.lga-toggle[aria-label="Add the best internal links automatically"]' ).first();
    const save = () => s.page.locator( 'button', { hasText: 'Save changes' } ).first().click();
    await toggle.click();
    await save();
    await s.settle( 300 );
    const cancelDialog = await s.modalText();
    await s.page.click( '.lga-modal-foot >> text=Cancel' );
    await s.settle( 300 );
    const afterCancel = s.posts( '/settings' ).length;
    await save();
    await s.settle( 300 );
    const dialog = await s.modalText();
    await s.page.click( '.lga-modal-foot >> text=Turn on' );
    await s.settle( 900 );
    const out = { cancelDialog, afterCancel, dialog, saved: s.posts( '/settings' ).map( ( r ) => r.body ), errors: s.errors };
    await s.context.close();
    return out;
} );

// Pure helpers.
await scenario( 'helpers', async () => {
    const s = await open( 'seo', () => undefined );
    const out = await s.page.evaluate( async ( base ) => {
        const ov = await import( `${ base }assets/js/pages/overview.js` );
        const seo = await import( `${ base }assets/js/pages/seo.js` );
        const ap = await import( `${ base }assets/js/autopilot.js` );
        return {
            explainOff: ov.growExplainText( { autopilot: false } ),
            explainOn: ov.growExplainText( { autopilot: true, links_per_day: 20 } ),
            policyOn: ov.growPolicyText( { autopilot: true, links_per_day: 20 } ),
            onText: ov.AUTOPILOT_ON_TEXT,
            autoOff: seo.autoLinksText( { enabled: false, present: 0 } ),
            autoOn: seo.autoLinksText( { enabled: true, autopilot: true, per_day: 20, today: 3, present: 7 } ),
            autoLeft: seo.autoLinksText( { enabled: false, present: 2 } ),
            willRunFallback: ap.willRunLines( { numbers: { emails_per_day: 8, links_per_day: 12, submissions_per_day: 4 } } ),
            summaryOn: ap.autopilotSummary( { enabled: true, automation_enabled: true, effective: { links_per_day: 20 } } ).lines,
            summaryOldConsent: ap.autopilotSummary( { enabled: true, automation_enabled: true, consent: { user_id: 1, at: '2026-11-15T10:00:00+00:00', policy_version: '1.2.0-2' }, consent_current: false, policy_version: '1.2.2-1' } ).lines,
            summaryCurrentConsent: ap.autopilotSummary( { enabled: true, automation_enabled: true, consent: { user_id: 1, at: '2026-11-16T10:00:00+00:00', policy_version: '1.2.2-1' }, consent_current: true, policy_version: '1.2.2-1' } ).lines,
        };
    }, PLUGIN );
    out.errors = s.errors;
    await s.context.close();
    return out;
} );

await browser.close();
print( { results } );
