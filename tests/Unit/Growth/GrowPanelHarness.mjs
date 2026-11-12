/**
 * Node harness for GrowPanelTest: runs the "Grow your site" panel and the compliance banner loader from
 * assets/js/pages/overview.js against a minimal fake DOM and a scripted fake fetch, and prints one JSON object.
 * Any use of innerHTML throws.
 *
 * Usage: node GrowPanelHarness.mjs <pluginDir>
 */
import path from 'node:path';
import { pathToFileURL } from 'node:url';

class FakeNode {
    constructor() {
        this.childNodes = [];
        this.parentNode = null;
    }
    appendChild( child ) {
        if ( child.isFragment ) {
            [ ...child.childNodes ].forEach( ( c ) => this.appendChild( c ) );
            return child;
        }
        if ( child.parentNode ) {
            child.parentNode.removeChild( child );
        }
        child.parentNode = this;
        this.childNodes.push( child );
        return child;
    }
    removeChild( child ) {
        const i = this.childNodes.indexOf( child );
        if ( i >= 0 ) {
            this.childNodes.splice( i, 1 );
        }
        child.parentNode = null;
        return child;
    }
    get firstChild() {
        return this.childNodes[ 0 ] || null;
    }
    get children() {
        return this.childNodes.filter( ( c ) => c instanceof FakeElement );
    }
    get textContent() {
        return this.childNodes.map( ( c ) => c.textContent ).join( '' );
    }
    set textContent( v ) {
        this.childNodes = [ new FakeText( String( v ) ) ];
    }
    get isConnected() {
        return true;
    }
}

class FakeText extends FakeNode {
    constructor( data ) {
        super();
        this.data = data;
    }
    get textContent() {
        return this.data;
    }
}

class FakeElement extends FakeNode {
    constructor( tag ) {
        super();
        this.tagName = String( tag ).toUpperCase();
        this.attributes = {};
        this.dataset = {};
        this.style = {};
        this.listeners = {};
        this.className = '';
        const classes = new Set();
        this.classList = {
            toggle: ( c, on ) => ( on ? classes.add( c ) : classes.delete( c ) ),
            add: ( c ) => classes.add( c ),
            remove: ( c ) => classes.delete( c ),
            contains: ( c ) => classes.has( c ),
        };
    }
    setAttribute( k, v ) {
        this.attributes[ k ] = String( v );
    }
    getAttribute( k ) {
        return k in this.attributes ? this.attributes[ k ] : null;
    }
    hasAttribute( k ) {
        return k in this.attributes;
    }
    removeAttribute( k ) {
        delete this.attributes[ k ];
    }
    addEventListener( type, fn ) {
        ( this.listeners[ type ] ||= [] ).push( fn );
    }
    set innerHTML( v ) {
        throw new Error( 'innerHTML must not be used' );
    }
    focus() {
        document.activeElement = this;
    }
    get isConnected() {
        // Attached (through its parents) to the body or to a panel node the harness keeps.
        let n = this;
        while ( n.parentNode ) {
            n = n.parentNode;
        }
        return n === body || roots.has( n );
    }
    async click() {
        const event = { preventDefault() {}, stopImmediatePropagation() {}, currentTarget: this };
        for ( const fn of this.listeners.click || [] ) {
            await fn( event );
        }
    }
}

globalThis.Node = FakeNode;
/** Panel nodes created by the harness count as connected (they stand for the overview page). */
const roots = new Set();
const body = new FakeElement( 'body' );
globalThis.document = {
    body,
    activeElement: null,
    createElement: ( t ) => new FakeElement( t ),
    createElementNS: ( ns, t ) => new FakeElement( t ),
    createTextNode: ( t ) => new FakeText( t ),
    createDocumentFragment: () => Object.assign( new FakeElement( '#fragment' ), { isFragment: true } ),
    querySelector: () => null,
    addEventListener() {},
    removeEventListener() {},
};
let reloads = 0;
globalThis.window = { location: { href: 'https://shop.example/wp-admin/admin.php?page=ppclg', origin: 'https://shop.example', reload: () => {
    reloads++;
} }, addEventListener() {}, removeEventListener() {} };
// ui.js modal(): the focus callback is not needed here.
globalThis.requestAnimationFrame = () => 0;

// ---------------------------------------------------------------- scripted fetch
const requests = [];
let routes = {};
globalThis.fetch = async ( url, init ) => {
    const u = new URL( String( url ) );
    const route = u.pathname.replace( /^.*\/ppclg\/v1/, '' );
    const key = `${ init.method } ${ route }`;
    requests.push( key );
    const queue = routes[ key ] || [];
    const reply = queue.length > 1 ? queue.shift() : ( queue[ 0 ] || { status: 404, body: { code: 'rest_no_route', message: '' } } );
    if ( reply.networkError ) {
        throw new TypeError( 'Failed to fetch' );
    }
    const text = JSON.stringify( reply.body );
    return { ok: reply.status >= 200 && reply.status < 300, status: reply.status, text: async () => text };
};

const pluginDir = path.resolve( process.argv[ 2 ] || '.' );
const api = await import( pathToFileURL( path.join( pluginDir, 'assets/js/api.js' ) ).href );
api.configure( { restRoot: 'https://shop.example/wp-json/ppclg/v1', nonce: 'n' } );
const mod = await import( pathToFileURL( path.join( pluginDir, 'assets/js/pages/overview.js' ) ).href );

const walk = ( node, fn ) => {
    fn( node );
    ( node.childNodes || [] ).forEach( ( c ) => walk( c, fn ) );
};
const find = ( node, pred ) => {
    const out = [];
    walk( node, ( n ) => {
        if ( pred( n ) ) {
            out.push( n );
        }
    } );
    return out;
};
const buttonLabels = ( node ) => find( node, ( n ) => n.tagName === 'BUTTON' ).map( ( b ) => b.textContent.trim() );
const buttonByLabel = ( node, label ) => find( node, ( n ) => n.tagName === 'BUTTON' && n.textContent.trim() === label )[ 0 ] || null;
const links = ( node ) => find( node, ( n ) => n.tagName === 'A' ).map( ( a ) => ( { href: a.attributes.href || '', text: a.textContent } ) );
const until = async ( pred, ms = 3000 ) => {
    const start = Date.now();
    while ( Date.now() - start < ms ) {
        if ( pred() ) {
            return true;
        }
        await new Promise( ( r ) => setTimeout( r, 5 ) );
    }
    return false;
};

const panelOf = ( panel ) => {
    roots.add( panel.node );
    return panel;
};
const attrsOf = ( node, pred ) => find( node, pred ).map( ( n ) => ( { ...n.attributes } ) );

const ctx = {
    config: { pageUrls: { submissions: 'https://shop.example/wp-admin/admin.php?page=ppclg-submissions', settings: 'https://shop.example/wp-admin/admin.php?page=ppclg-settings', outreach: 'https://shop.example/wp-admin/admin.php?page=ppclg-outreach', logs: 'https://shop.example/wp-admin/admin.php?page=ppclg-logs' } },
    pageUrl( page, params ) {
        const hash = Object.entries( params || {} ).map( ( [ k, v ] ) => `${ k }=${ encodeURIComponent( v ) }` ).join( '&' );
        return ( this.config.pageUrls[ page ] || '#' ) + ( hash ? `#${ hash }` : '' );
    },
};

const steps = ( statuses ) => [ 'preflight', 'site_audit', 'submit', 'summary' ].map( ( key, i ) => ( {
    key,
    label: `Step ${ key }`,
    status: statuses[ i ] || 'pending',
    rounds: statuses[ i ] === 'done' ? 1 : 0,
    summary: statuses[ i ] === 'done' ? `Summary of ${ key }.` : ( statuses[ i ] === 'blocked' ? 'Paused by the storefront compliance check.' : '' ),
    note: '',
    error: '',
    counts: {},
} ) );
const running = ( statuses, extra = {} ) => ( { id: 'r1', status: 'running', started_at: '2026-11-12T10:00:00+00:00', elapsed_seconds: 12, steps: steps( statuses ), summary: {}, next_actions: [], needs_you: [], progress: { done: 0, total: 4 }, ...extra } );
const completed = {
    id: 'r1',
    status: 'completed',
    ok: true,
    started_at: '2026-11-12T10:00:00+00:00',
    finished_at: new Date().toISOString(),
    elapsed_seconds: 95,
    steps: steps( [ 'done', 'done', 'blocked', 'done' ] ),
    summary: { computed_at: '2026-11-12T10:01:35+00:00', submissions: { owner_tasks: 37 }, outreach_drafts: 5, prospects: { total: 120, qualified: 18 }, backlinks: { live: 4, lost: 1 }, site_audit: null, link_suggestions_pending: null },
    next_actions: [
        { key: 'owner_tasks', label: 'Complete 37 owner tasks', count: 37, page: 'submissions', params: { tab: 'needs_manual' }, route: '#/submissions?tab=needs_manual' },
        { key: 'link_suggestions', label: 'Review 4 internal-link suggestions', count: 4, page: 'seo', params: { links: 'pending' }, route: '#/seo?links=pending' },
    ],
    needs_you: [],
};

const out = {};
const fast = { pollDelay: 1, waitDelay: 1, retryDelay: 1 };

// 1. An active run resumes on load and loops POST /growth/step until it is no longer running.
{
    requests.length = 0;
    routes = {
        'GET /growth/status': [ { status: 200, body: running( [ 'done', 'running' ] ) } ],
        'POST /growth/step': [
            { status: 200, body: { ...running( [ 'done', 'done', 'running' ] ), ok: true, waiting: true, note: 'The background job runner is busy.' } },
            { status: 200, body: completed },
        ],
    };
    let finished = null;
    const panel = mod.createGrowPanel( ctx, { ...fast, onFinished: ( run ) => {
        finished = run;
    } } );
    await panel.ready;
    await until( () => finished !== null );
    const text = panel.node.textContent;
    out.resume = {
        requests: [ ...requests ],
        finishedStatus: finished ? finished.status : null,
        hasNeedsYou: text.includes( 'Needs you' ),
        hasWhatHappened: text.includes( 'What happened' ),
        hasStepSummary: text.includes( 'Paused by the storefront compliance check.' ),
        hasHeadline: text.includes( 'Owner tasks' ) && text.includes( '37' ),
        unknownForMissing: text.includes( 'not available' ),
        links: links( panel.node ),
        buttons: buttonLabels( panel.node ),
        busy: panel.isBusy(),
    };
}

// 2. Failed step requests are retried twice, then the panel offers "Resume"; Resume continues the run.
{
    requests.length = 0;
    routes = {
        'GET /growth/status': [ { status: 200, body: running( [ 'done' ] ) } ],
        'POST /growth/step': [
            { status: 502, body: { code: 'http_502', message: 'Bad gateway' } },
            { status: 502, body: { code: 'http_502', message: 'Bad gateway' } },
            { status: 502, body: { code: 'http_502', message: 'Bad gateway' } },
            { status: 200, body: completed },
        ],
    };
    const panel = mod.createGrowPanel( ctx, fast );
    await panel.ready;
    await until( () => ! panel.isBusy() && buttonLabels( panel.node ).includes( 'Resume' ) );
    const stepCalls = requests.filter( ( r ) => r === 'POST /growth/step' ).length;
    const text = panel.node.textContent;
    out.retry = {
        stepCallsBeforeResume: stepCalls,
        buttons: buttonLabels( panel.node ),
        lostContact: text.includes( 'Lost contact with the server' ),
        serverError: text.includes( 'The server reported an error' ) && text.includes( 'Bad gateway' ) && text.includes( 'Details are in Logs' ),
        logsLink: links( panel.node ).some( ( a ) => a.href === 'https://shop.example/wp-admin/admin.php?page=ppclg-logs' ),
    };
    await buttonByLabel( panel.node, 'Resume' ).click();
    await until( () => ! panel.isBusy() && panel.node.textContent.includes( 'What happened' ) );
    out.retry.afterResume = { buttons: buttonLabels( panel.node ), stepCalls: requests.filter( ( r ) => r === 'POST /growth/step' ).length, done: panel.node.textContent.includes( 'What happened' ) };
}

// 3. "Grow now" starts a run and loops; a refused start is reported and no step is requested.
{
    requests.length = 0;
    routes = {
        'GET /growth/status': [ { status: 200, body: { id: '', status: 'idle', steps: steps( [] ), summary: {}, next_actions: [] } } ],
        'POST /growth/start': [ { status: 200, body: { ...running( [] ), ok: true, message: 'Growth run started.' } } ],
        'POST /growth/step': [ { status: 200, body: completed } ],
    };
    const panel = mod.createGrowPanel( ctx, fast );
    await panel.ready;
    const idleText = panel.node.textContent;
    const idleButtons = buttonLabels( panel.node );
    await buttonByLabel( panel.node, 'Grow now' ).click();
    await until( () => ! panel.isBusy() && panel.node.textContent.includes( 'What happened' ) );
    out.start = {
        idleButtons,
        explains: idleText.includes( 'never buys or hides links' ) && idleText.includes( 'never edits your site content unless you click Apply' ),
        promisesApproval: /needs your approval|never emails anyone without your approval|approved submissions/.test( idleText ),
        neutralPolicy: idleText.includes( 'follows your Automation and Outreach settings' ),
        requests: [ ...requests ],
    };

    requests.length = 0;
    routes = {
        'GET /growth/status': [ { status: 200, body: { id: '', status: 'idle', steps: steps( [] ), summary: {}, next_actions: [] } } ],
        'POST /growth/start': [ { status: 200, body: { id: '', status: 'idle', steps: steps( [] ), ok: false, message: 'Another request is working on the growth run.' } } ],
    };
    const refused = mod.createGrowPanel( ctx, fast );
    await refused.ready;
    await buttonByLabel( refused.node, 'Grow now' ).click();
    await new Promise( ( r ) => setTimeout( r, 20 ) );
    out.refused = {
        requests: [ ...requests ],
        toast: body.textContent.includes( 'Another request is working on the growth run.' ),
        buttons: buttonLabels( refused.node ),
    };
}

// 4. Compliance banner: nothing on 404, the banner module for a blocking status.
{
    routes = { 'GET /compliance/storefront': [ { status: 404, body: { code: 'rest_no_route', message: '' } } ] };
    const host404 = document.createElement( 'div' );
    host404.appendChild( document.createTextNode( 'stale' ) );
    await mod.loadComplianceBanner( host404 );
    routes = { 'GET /compliance/storefront': [ { status: 200, body: { status: 'block', effective: 'block', gate: true, blocking: true, counts: { block: 2, warn: 1 }, top: [], findings: [], message: 'Two categories use consumer-use wording.', acknowledged: {} } } ] };
    const hostBlock = document.createElement( 'div' );
    await mod.loadComplianceBanner( hostBlock );
    out.banner = {
        emptyOn404: host404.childNodes.length === 0,
        blockShown: hostBlock.textContent.length > 0,
        legal: /legal decision/i.test( hostBlock.textContent ),
    };
}

// 5. Error kinds: no answer at all, an expired session (no retries, only "Reload the page") and Stop from the
//    error view (the run still runs on the server).
{
    requests.length = 0;
    routes = {
        'GET /growth/status': [ { status: 200, body: running( [ 'done' ] ) } ],
        'POST /growth/step': [ { networkError: true } ],
    };
    const panel = panelOf( mod.createGrowPanel( ctx, fast ) );
    await panel.ready;
    await until( () => ! panel.isBusy() && buttonLabels( panel.node ).includes( 'Resume' ) );
    out.network = { buttons: buttonLabels( panel.node ), lostContact: panel.node.textContent.includes( 'Lost contact with the server' ) };
}
{
    requests.length = 0;
    routes = {
        'GET /growth/status': [ { status: 200, body: running( [ 'done' ] ) } ],
        'POST /growth/step': [ { status: 403, body: { code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed', data: { status: 403 } } } ],
    };
    const panel = panelOf( mod.createGrowPanel( ctx, fast ) );
    await panel.ready;
    await until( () => ! panel.isBusy() && buttonLabels( panel.node ).length > 0 && ! buttonLabels( panel.node ).includes( 'Stop' ) );
    const text = panel.node.textContent;
    const before = reloads;
    const reload = buttonByLabel( panel.node, 'Reload the page' );
    if ( reload ) {
        await reload.click();
    }
    out.session = {
        stepCalls: requests.filter( ( r ) => r === 'POST /growth/step' ).length,
        buttons: buttonLabels( panel.node ),
        expired: text.includes( 'Your session expired' ),
        lostContact: text.includes( 'Lost contact with the server' ),
        reloaded: reloads - before,
    };
}
{
    requests.length = 0;
    routes = {
        'GET /growth/status': [ { status: 200, body: running( [ 'done' ] ) } ],
        'POST /growth/step': [ { status: 500, body: { code: 'internal_server_error', message: 'Critical error', data: { status: 500 } } } ],
        'POST /growth/cancel': [ { status: 200, body: { ...running( [ 'done', 'skipped' ] ), status: 'cancelled', finished_at: new Date().toISOString(), ok: true, message: 'Growth run stopped. Work already done is kept.', cancelled_jobs: 0 } } ],
    };
    const panel = panelOf( mod.createGrowPanel( ctx, fast ) );
    await panel.ready;
    await until( () => ! panel.isBusy() && buttonLabels( panel.node ).includes( 'Resume' ) );
    const errorButtons = buttonLabels( panel.node );
    const pending = buttonByLabel( panel.node, 'Stop' ).click();
    await new Promise( ( r ) => setTimeout( r, 5 ) );
    const confirmStop = find( body, ( n ) => n.tagName === 'BUTTON' && n.textContent.trim() === 'Stop run' )[ 0 ];
    if ( confirmStop ) {
        await confirmStop.click();
    }
    await pending;
    const text = panel.node.textContent;
    out.stopFromError = {
        errorButtons,
        confirmShown: Boolean( confirmStop ),
        cancelled: requests.includes( 'POST /growth/cancel' ),
        buttons: buttonLabels( panel.node ),
        errorGone: ! text.includes( 'The server reported an error' ) && ! text.includes( 'Lost contact with the server' ),
        stopped: text.includes( 'Stopped' ),
    };
}

// 6. Accessibility while polling: named progress bar, one persistent live region, focus kept on Stop.
{
    requests.length = 0;
    const gates = {};
    const held = ( n ) => {
        gates[ n ] = {};
        gates[ n ].promise = new Promise( ( r ) => {
            gates[ n ].release = r;
        } );
    };
    held( 2 );
    held( 3 );
    let calls = 0;
    routes = {
        'GET /growth/status': [ { status: 200, body: running( [ 'done', 'running' ] ) } ],
        'POST /growth/step': [
            { status: 200, body: { ...running( [ 'done', 'running' ] ), ok: true } },
            { status: 200, body: { ...running( [ 'done', 'done', 'running' ] ), ok: true } },
            { status: 200, body: completed },
        ],
    };
    const realFetch = globalThis.fetch;
    globalThis.fetch = async ( url, init ) => {
        if ( String( url ).includes( '/growth/step' ) ) {
            calls++;
            if ( gates[ calls ] ) {
                await gates[ calls ].promise; // hold this step answer until the harness has looked
            }
        }
        return realFetch( url, init );
    };
    const isLive = ( n ) => n.attributes && n.attributes.role === 'status' && n.attributes[ 'aria-live' ] === 'polite' && String( n.className ).includes( 'ppclg-sr-only' );
    const panel = panelOf( mod.createGrowPanel( ctx, { ...fast, pollDelay: 1 } ) );
    await panel.ready;
    await until( () => calls >= 2 );
    const liveBefore = find( panel.node, isLive );
    const liveTextBefore = liveBefore.length ? liveBefore[ 0 ].textContent : null;
    const bars = attrsOf( panel.node, ( n ) => n.attributes && n.attributes.role === 'progressbar' );
    const stop = buttonByLabel( panel.node, 'Stop' );
    stop.focus();
    gates[ 2 ].release();
    await until( () => calls >= 3 ); // the second answer was drawn
    const active = document.activeElement;
    const liveMid = find( panel.node, isLive );
    out.a11y = {
        progressbar: bars[ 0 ] || null,
        liveCount: liveMid.length,
        liveSameNode: liveBefore.length === 1 && liveMid[ 0 ] === liveBefore[ 0 ],
        liveTextBefore,
        liveTextAfter: liveMid.length ? liveMid[ 0 ].textContent : null,
        stepsListLive: find( panel.node, ( n ) => n.tagName === 'OL' && n.attributes[ 'aria-live' ] ).length,
        stopRedrawn: active !== stop && ! stop.isConnected,
        focusOnStop: Boolean( active && active.tagName === 'BUTTON' && active.textContent.trim() === 'Stop' && active.isConnected ),
    };
    gates[ 3 ].release();
    await until( () => ! panel.isBusy() );
    const done = document.activeElement;
    out.a11y.focusAfterFinish = done && done.tagName === 'BUTTON' && done.isConnected ? done.textContent.trim() : null;
    out.a11y.liveTextFinished = find( panel.node, isLive ).map( ( n ) => n.textContent )[ 0 ] || null;
    globalThis.fetch = realFetch;
}

// 7. The subtitle states what the run may send on its own, from the current settings.
{
    const policy = ( over ) => ( { submissions: true, submission_mode: 'auto', outreach_autosend: false, require_approval: true, ...over } );
    out.policy = {
        auto: mod.growPolicyText( policy( {} ) ),
        autosend: mod.growPolicyText( policy( { outreach_autosend: true, require_approval: false } ) ),
        autosendWithApproval: mod.growPolicyText( policy( { outreach_autosend: true, require_approval: true } ) ),
        off: mod.growPolicyText( policy( { submissions: false } ) ),
        authorizedOnly: mod.growPolicyText( policy( { submission_mode: 'authorized_only' } ) ),
        unknown: mod.growPolicyText( null ),
    };
    requests.length = 0;
    routes = {
        'GET /growth/status': [ { status: 200, body: { id: '', status: 'idle', steps: steps( [] ), summary: {}, next_actions: [], policy: policy( {} ) } } ],
    };
    const panel = mod.createGrowPanel( ctx, fast );
    await panel.ready;
    out.policy.rendered = panel.node.textContent.includes( 'Listings to sites that pass every safety check are submitted automatically' );
}

// 8. Headline numbers never show 0 for a site audit that did not run; a partial audit says so.
{
    const withAudit = ( audit ) => ( { ...completed, summary: { ...completed.summary, site_audit: audit } } );
    const render = async ( audit ) => {
        routes = { 'GET /growth/status': [ { status: 200, body: withAudit( audit ) } ] };
        const panel = mod.createGrowPanel( ctx, fast );
        await panel.ready;
        return panel.node.textContent;
    };
    const none = await render( { checked_at: null, pages: 0, complete: false, running: false, high: null, medium: null, low: null, issues_total: null } );
    const partial = await render( { checked_at: '2026-11-12T10:00:00+00:00', pages: 40, complete: false, running: true, high: 2, medium: 1, low: 0, issues_total: 3 } );
    out.audit = {
        notRunYet: none.includes( 'not run yet' ),
        noZero: ! /High-priority SEO issues0/.test( none ),
        partial: partial.includes( '3 issues in total (partial)' ),
    };
}

// 9. The fallback banner shows a check that could not run even when the last stored result passed.
{
    const host = document.createElement( 'div' );
    mod.fallbackComplianceBanner( host, { effective: 'pass', status: 'pass', blocking: true, error: 'x' } );
    const hostPass = document.createElement( 'div' );
    mod.fallbackComplianceBanner( hostPass, { effective: 'pass', status: 'pass', blocking: false, error: '' } );
    out.fallback = { paused: host.textContent.includes( 'paused' ) && host.textContent.includes( 'x' ), passEmpty: hostPass.childNodes.length === 0 };
}

process.stdout.write( JSON.stringify( out ) );
process.exit( 0 );
