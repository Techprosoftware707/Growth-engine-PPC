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
    async click() {
        const event = { preventDefault() {}, stopImmediatePropagation() {}, currentTarget: this };
        for ( const fn of this.listeners.click || [] ) {
            await fn( event );
        }
    }
}

globalThis.Node = FakeNode;
const body = new FakeElement( 'body' );
globalThis.document = {
    body,
    createElement: ( t ) => new FakeElement( t ),
    createElementNS: ( ns, t ) => new FakeElement( t ),
    createTextNode: ( t ) => new FakeText( t ),
    createDocumentFragment: () => Object.assign( new FakeElement( '#fragment' ), { isFragment: true } ),
    querySelector: () => null,
    addEventListener() {},
    removeEventListener() {},
};
globalThis.window = { location: { href: 'https://shop.example/wp-admin/admin.php?page=ppclg', origin: 'https://shop.example' }, addEventListener() {}, removeEventListener() {} };

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

const ctx = {
    config: { pageUrls: { submissions: 'https://shop.example/wp-admin/admin.php?page=ppclg-submissions', settings: 'https://shop.example/wp-admin/admin.php?page=ppclg-settings', outreach: 'https://shop.example/wp-admin/admin.php?page=ppclg-outreach' } },
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
        lostContact: text.includes( 'Lost contact with the server' ) && text.includes( 'Bad gateway' ),
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
        explains: idleText.includes( 'never buys or hides links' ) && idleText.includes( 'never emails anyone without your approval' ) && idleText.includes( 'never edits your site content unless you click Apply' ),
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

process.stdout.write( JSON.stringify( out ) );
process.exit( 0 );
