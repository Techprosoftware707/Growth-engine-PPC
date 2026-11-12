/**
 * Browser harness for JsPagesFixesTest (js-pages slice): loads the real admin SPA (app.js + the page modules)
 * in headless Chrome through Playwright. Every request is answered by a route handler: plugin files from the
 * plugin directory, REST calls from per-scenario mocks shaped like the PHP controllers' payloads. Prints one
 * JSON object with raw observations that the PHP test asserts on, or {"skip": "..."} when Playwright or a
 * browser is not available.
 *
 * Usage: node JsPagesFixesHarness.mjs <pluginDir> <playwrightModulePath> [chromeExecutable]
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
    const config = {
        page,
        restRoot: `${ ORIGIN }${ REST }/`,
        nonce: 'x',
        version: VERSION,
        siteDomain: 'example.ca',
        automationEnabled: true,
    };
    return `<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="${ PLUGIN }assets/css/admin.css?ver=${ VERSION }"></head><body>`
        + `<div class="wrap ppclg-wrap"><div id="ppclg-app" data-page="${ page }"></div></div>`
        + `<script type="application/json" id="ppclg-config">${ JSON.stringify( config ) }</script>`
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
    await page.waitForSelector( '.ppclg-page-head, .ppclg-load-failure', { timeout: 8000 } ).catch( () => null );
    await page.waitForTimeout( 500 );
    const toasts = () => page.$$eval( '.ppclg-toast', ( ns ) => ns.map( ( n ) => ( {
        kind: ( Array.from( n.classList ).find( ( c ) => c.startsWith( 'ppclg-toast--' ) ) || '' ).replace( 'ppclg-toast--', '' ),
        text: n.textContent.trim(),
    } ) ) );
    const clearToasts = () => page.$$eval( '.ppclg-toast', ( ns ) => ns.forEach( ( n ) => n.remove() ) );
    const modalText = () => page.$$eval( '.ppclg-modal', ( ns ) => ns.map( ( n ) => n.textContent.trim() ) );
    const settle = ( ms = 500 ) => page.waitForTimeout( ms );
    const posts = ( p ) => requests.filter( ( r ) => r.method === 'POST' && ( ! p || r.path === p ) );
    return { context, page, requests, errors, toasts, clearToasts, modalText, settle, posts };
}

const clone = ( v ) => JSON.parse( JSON.stringify( v ) );

function settingsPayload( automation = {} ) {
    const settings = {
        site: { brand_name: 'Example', domain: 'example.ca' },
        automation: {
            enabled: true, discovery: true, evaluation: true, opportunities: true, packaging: true,
            submissions: true, submission_mode: 'auto', auto_outreach_drafts: true, outreach_autosend: true, monitoring: true, imports: true,
            daily_limits: { discovery_queries: 15, evaluations: 40, submissions: 5, outreach_emails: 10, backlink_checks: 150, provider_lookups: 60 },
            weekly_limits: { discovery_queries: 80, submissions: 20, outreach_emails: 40 },
            min_score_auto_queue: 70, min_score_keep: 50, max_execution_seconds: 20, tick_interval: 300, international: true,
            ...automation,
        },
        discovery: {}, providers: { google: { enabled: true, gsc_property: '', ga4_property_id: '', use_sitekit: false } }, outreach: {}, content: {}, monitoring: {}, advanced: {},
    };
    return {
        settings,
        defaults: clone( settings ),
        secrets: { google_service_account_json: { configured: false, label: 'Google service account JSON' } },
        providers: {}, facts: {}, custom_seeds: [], seeds: [], crypto_strong: true, backups: [],
    };
}

const daily = ( page, label ) => page.locator( '.ppclg-card', { has: page.locator( '.ppclg-card-head', { hasText: 'Daily limits' } ) } )
    .locator( '.ppclg-field', { hasText: label } ).locator( 'input[type=number]' ).first();
const switchIn = ( page, label ) => page.locator( '.ppclg-switch-row, .ppclg-field', { hasText: label } ).locator( 'button.ppclg-toggle' ).first();

const results = {};
const scenario = async ( name, fn ) => {
    try {
        results[ name ] = await fn();
    } catch ( e ) {
        results[ name ] = { failed: String( e && e.stack ? e.stack : e ).slice( 0, 1500 ) };
    }
};

// js-pages-1: a stale Settings tab that only edits one limit must not re-send the automation flags.
await scenario( 'settingsStale', async () => {
    const s = await open( 'settings', ( m, p ) => {
        if ( p === '/settings' && m === 'GET' ) {
            return settingsPayload();
        }
        if ( p === '/settings' && m === 'POST' ) {
            return { ok: true, changed: [], secrets: { updated: [], cleared: [], rejected: [], locked: [] } };
        }
        return undefined;
    }, '#tab=automation' );
    await daily( s.page, 'Evaluations' ).fill( '25' );
    await s.page.click( 'text=Save changes' );
    await s.settle( 700 );
    const out = {
        settingsBodies: s.posts( '/settings' ).map( ( r ) => r.body ),
        toggles: s.posts( '/automation/toggle' ).map( ( r ) => r.body ),
        toasts: await s.toasts(),
        errors: s.errors,
    };
    await s.context.close();
    return out;
} );

// js-pages-1: enabling a gated flag from the Settings form goes through /automation/toggle after a confirmation.
await scenario( 'settingsGate', async () => {
    const s = await open( 'settings', ( m, p ) => {
        if ( p === '/settings' && m === 'GET' ) {
            return settingsPayload( { outreach_autosend: false } );
        }
        if ( p === '/settings' && m === 'POST' ) {
            return { ok: true, changed: [], secrets: { updated: [], cleared: [], rejected: [], locked: [] } };
        }
        if ( p === '/automation/toggle' ) {
            return { ok: true };
        }
        return undefined;
    }, '#tab=automation' );
    await switchIn( s.page, 'Outreach autosend' ).click();
    await s.page.click( 'text=Save changes' );
    await s.settle( 400 );
    const declinedModal = await s.modalText();
    await s.page.click( '.ppclg-modal-foot >> text=Cancel' );
    await s.settle( 400 );
    const afterDecline = { settings: s.posts( '/settings' ).length, toggles: s.posts( '/automation/toggle' ).length, toasts: await s.toasts() };
    await s.clearToasts();
    await s.page.click( 'text=Save changes' );
    await s.settle( 400 );
    await s.page.click( '.ppclg-modal-foot >> text=Enable' );
    await s.settle( 800 );
    const out = {
        declinedModal,
        afterDecline,
        settingsBodies: s.posts( '/settings' ).map( ( r ) => r.body ),
        toggles: s.posts( '/automation/toggle' ).map( ( r ) => r.body ),
        toasts: await s.toasts(),
        errors: s.errors,
    };
    await s.context.close();
    return out;
} );

// js-pages-8: an emptied limit field is "unchanged"; an explicit 0 asks before removing the cap.
await scenario( 'settingsLimits', async () => {
    const s = await open( 'settings', ( m, p ) => ( p === '/settings' && m === 'GET' ? settingsPayload() : ( p === '/settings' ? { ok: true, secrets: {} } : undefined ) ), '#tab=automation' );
    const field = daily( s.page, 'Submissions' );
    await field.fill( '' );
    await s.settle( 200 );
    const cleared = {
        dirtyLabel: await s.page.locator( '.ppclg-savebar' ).textContent(),
        saveDisabled: await s.page.locator( 'button:has-text("Save changes")' ).isDisabled(),
    };
    await field.fill( '0' );
    await s.page.click( 'text=Save changes' );
    await s.settle( 400 );
    const zeroModal = await s.modalText();
    await s.page.click( '.ppclg-modal-foot >> text=Cancel' );
    await s.settle( 300 );
    const out = { cleared, zeroModal, posts: s.posts( '/settings' ).length, toasts: await s.toasts(), errors: s.errors };
    await s.context.close();
    return out;
} );

// js-pages-9: a secret the server rejects is reported and kept, not announced as saved.
await scenario( 'settingsSecrets', async () => {
    const s = await open( 'settings', ( m, p, body ) => {
        if ( p === '/settings' && m === 'GET' ) {
            return settingsPayload();
        }
        if ( p === '/settings' && m === 'POST' ) {
            return { ok: true, changed: [], secrets: { updated: [], cleared: [], rejected: Object.keys( ( body && body.secrets ) || {} ), locked: [] } };
        }
        return undefined;
    }, '#tab=integrations' );
    const gsc = s.page.locator( '.ppclg-card', { hasText: 'Google Search Console & Analytics 4' } ).first();
    await gsc.locator( 'button:has-text("Set")' ).first().click();
    await gsc.locator( 'textarea' ).first().fill( '{"type":"service_account"}' );
    await s.page.click( 'text=Save changes' );
    await s.settle( 800 );
    const textarea = s.page.locator( '.ppclg-card', { hasText: 'Google Search Console & Analytics 4' } ).first().locator( 'textarea' );
    const out = {
        sentSecrets: s.posts( '/settings' ).map( ( r ) => Object.keys( ( r.body && r.body.secrets ) || {} ) ),
        toasts: await s.toasts(),
        keptValue: ( await textarea.count() ) ? await textarea.first().inputValue() : null,
        dirtyLabel: await s.page.locator( '.ppclg-savebar' ).textContent(),
        errors: s.errors,
    };
    await s.context.close();
    return out;
} );

// js-pages-2: autosend consent text on the Automation page, and the Outreach page banner.
await scenario( 'automationConsent', async () => {
    const s = await open( 'automation', ( m, p ) => {
        if ( p === '/automation/status' ) {
            return { automation_enabled: true, flags: { enabled: true, submissions: true, outreach_autosend: false }, limits: {}, stages: {}, queue: {}, recent_jobs: [], scheduler: { backend: 'wp_cron' } };
        }
        return undefined;
    } );
    const row = s.page.locator( '.ppclg-switch-row', { hasText: 'Outreach autosend' } ).first();
    const desc = await row.textContent();
    await row.locator( 'button.ppclg-toggle' ).click();
    await s.settle( 400 );
    const modal = await s.modalText();
    await s.page.click( '.ppclg-modal-foot >> text=Cancel' );
    const header = await s.page.locator( '.ppclg-page-desc' ).first().textContent();
    const out = { desc, modal, header, toggles: s.posts( '/automation/toggle' ).length, errors: s.errors };
    await s.context.close();
    return out;
} );

// js-pages-3 + js-pages-6a: prospect drawer — effective submission mode and honest "Evaluate now" results.
await scenario( 'prospects', async () => {
    const prospect = { id: 12, domain: 'labdirectory.ca', name: 'Lab Directory', status: 'qualified', score: 74, automation_allowed: 0, profile: {}, metrics: {} };
    let evaluations = 0;
    const s = await open( 'prospects', ( m, p ) => {
        if ( p === '/prospects' && m === 'GET' ) {
            return { items: [ prospect ], total: 1, page: 1, per_page: 25, pages: 1 };
        }
        if ( p === '/prospects/12' && m === 'GET' ) {
            return { prospect, opportunities: [], contacts: [], submissions: [], messages: [], backlinks: [] };
        }
        if ( p === '/settings' && m === 'GET' ) {
            return settingsPayload( { submissions: true, submission_mode: 'auto', min_score_auto_queue: 70 } );
        }
        if ( p === '/prospects/12/evaluate' ) {
            evaluations++;
            if ( evaluations === 1 ) {
                // EvaluateJob::skipped('evaluation_limit_reached') through Pipeline::runNow (runType adds ok=true).
                return { ok: true, result: { job: 'evaluate', skipped: true, reason: 'evaluation_limit_reached', ok: true }, job_id: null, prospect };
            }
            if ( evaluations === 2 ) {
                // Pipeline::runNow while the runner is busy and the job is already queued.
                return { ok: true, result: { ok: true, queued: true, duplicate: true, message: 'The job runner is busy and this job is already queued; it will run on the next tick.' }, job_id: null, prospect };
            }
            return { ok: true, result: { job: 'evaluate', evaluated: 1, errors: 0, items: [ { prospect_id: 12, outcome: 'qualified' } ], ok: true }, job_id: null, prospect };
        }
        return undefined;
    }, '#open=12' );
    await s.page.click( 'button.ppclg-tab:has-text("Actions")' );
    await s.settle( 600 );
    const automationCard = s.page.locator( '.ppclg-card', { hasText: 'Automation & partner API' } ).first();
    const modeNotice = await automationCard.textContent();
    const evaluate = [];
    for ( let i = 0; i < 3; i++ ) {
        await s.clearToasts();
        await s.page.click( 'button.ppclg-btn:has-text("Evaluate now")' );
        await s.settle( 600 );
        evaluate.push( await s.toasts() );
    }
    const out = { modeNotice, evaluate, errors: s.errors };
    await s.context.close();
    return out;
} );

// js-pages-5 + js-pages-2: refused campaign actions and the autosend banner on the Outreach page.
await scenario( 'outreach', async () => {
    const campaign = { id: 3, name: 'Editorial', status: 'pending_approval', approach: 'editorial_mention', settings: {}, stats: {} };
    let approvals = 0;
    const s = await open( 'outreach', ( m, p ) => {
        if ( p === '/outreach/sender' ) {
            return { ok: false, missing: [ 'sender_name', 'postal_address' ], warnings: [], autosend: true };
        }
        if ( p === '/outreach/campaigns' && m === 'GET' ) {
            return { items: [ campaign ], total: 1, page: 1, per_page: 100, pages: 1 };
        }
        if ( p === '/outreach/campaigns/3/approve' ) {
            approvals++;
            const data = { ok: false, action: 'approve', message: 'The campaign could not be approved. Complete the sender identity (Settings → Outreach) and make sure the campaign is pending approval. Missing: sender_name.' };
            // First an older server (HTTP 200 + ok:false), then the current one (409 error).
            return approvals === 1 ? data : { __status: 409, json: { code: 'ppclg_campaign_action_failed', message: data.message, data: { status: 409, ...data } } };
        }
        return undefined;
    }, '#tab=campaigns' );
    const banner = await s.page.locator( '.ppclg-callout' ).allTextContents();
    const header = await s.page.locator( '.ppclg-page-desc' ).first().textContent();
    const approve = [];
    for ( let i = 0; i < 2; i++ ) {
        await s.clearToasts();
        await s.page.click( 'button.ppclg-btn:has-text("Approve campaign")' );
        await s.settle( 300 );
        await s.page.click( '.ppclg-modal-foot >> text=Approve' );
        await s.settle( 700 );
        approve.push( await s.toasts() );
    }
    const out = { banner, header, approve, approvals, errors: s.errors };
    await s.context.close();
    return out;
} );

// js-pages-6b: a refused package rebuild.
await scenario( 'submissions', async () => {
    const sub = { id: 31, prospect_id: 12, status: 'pending_review', method: 'manual', target_url: 'https://labdirectory.ca/submit', payload: {}, attempts: 0, created_at: '2026-11-01 10:00:00' };
    const s = await open( 'submissions', ( m, p ) => {
        if ( p === '/submissions' && m === 'GET' ) {
            return { items: [ sub ], total: 1, page: 1, per_page: 25, pages: 1 };
        }
        if ( p === '/submissions/31' && m === 'GET' ) {
            return { submission: sub, prospect: { id: 12, domain: 'labdirectory.ca' }, opportunity: null };
        }
        if ( p === '/submissions/31/rebuild' ) {
            return { ok: false, action: 'rebuild', message: 'The package could not be rebuilt (submitted or confirmed submissions are locked).', submission: sub };
        }
        return undefined;
    }, '#open=31' );
    await s.page.click( 'button.ppclg-btn:has-text("Rebuild package")' );
    await s.settle( 300 );
    await s.page.click( '.ppclg-modal-foot >> text=Rebuild' );
    await s.settle( 700 );
    const out = { toasts: await s.toasts(), errors: s.errors };
    await s.context.close();
    return out;
} );

// js-pages-6c: provider import errors are shown.
await scenario( 'backlinks', async () => {
    const s = await open( 'backlinks', ( m, p ) => {
        if ( p === '/backlinks/summary' ) {
            return { status_counts: {}, trend: [] };
        }
        if ( p === '/backlinks/import' ) {
            return { ok: true, job_id: null, result: { job: 'import_backlinks', providers: null, analytics: { sources: 0, new: 0, warning: '' }, errors: [ 'Daily provider lookup limit reached; provider import deferred.' ], ok: true } };
        }
        return undefined;
    } );
    await s.page.click( 'button.ppclg-btn:has-text("Import from providers")' );
    await s.settle( 300 );
    await s.page.click( '.ppclg-modal-foot >> text=Import' );
    await s.settle( 700 );
    const out = { toasts: await s.toasts(), errors: s.errors };
    await s.context.close();
    return out;
} );

// js-pages-10: the audit "object type" filter sends the type the writers record.
await scenario( 'logs', async () => {
    const s = await open( 'logs', () => undefined, '#tab=audit' );
    const select = s.page.locator( 'select', { has: s.page.locator( 'option', { hasText: 'Outreach message' } ) } ).first();
    const options = await select.locator( 'option' ).evaluateAll( ( ns ) => ns.map( ( n ) => n.value ) );
    await select.selectOption( 'outreach_message' );
    await s.settle( 900 );
    const audit = s.requests.filter( ( r ) => r.path === '/audit' ).map( ( r ) => r.query.object_type || '' );
    const out = { options, audit, errors: s.errors };
    await s.context.close();
    return out;
} );

await browser.close();
print( { results } );
