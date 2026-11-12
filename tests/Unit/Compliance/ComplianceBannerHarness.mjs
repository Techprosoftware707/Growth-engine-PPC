/**
 * Node harness for ComplianceBannerTest: runs assets/js/compliance-banner.js against a minimal fake DOM
 * (enough for ui.js el()/replace()/callout()/button()/toast()) and a fake fetch, and prints one JSON object.
 * Any use of innerHTML throws.
 *
 * Usage: node ComplianceBannerHarness.mjs <pluginDir>
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
    // Enough for ui.js confirm()/modal(): focus handling is skipped (no requestAnimationFrame callbacks run).
    activeElement: null,
    addEventListener() {},
    removeEventListener() {},
};
globalThis.window = { location: { href: 'https://shop.example/wp-admin/admin.php?page=ppclg', origin: 'https://shop.example' } };
globalThis.requestAnimationFrame = () => 0;

const requests = [];
let reply = { status: 200, body: {} };
/** Replies used in order before `reply` (one per request). */
let replyQueue = [];
globalThis.fetch = async ( url, init ) => {
    requests.push( { url: String( url ), method: init.method, body: init.body ? JSON.parse( init.body ) : null } );
    const r = replyQueue.length ? replyQueue.shift() : reply;
    const text = JSON.stringify( r.body );
    return { ok: r.status >= 200 && r.status < 300, status: r.status, text: async () => text };
};

const pluginDir = path.resolve( process.argv[ 2 ] || '.' );
const mod = await import( pathToFileURL( path.join( pluginDir, 'assets/js/compliance-banner.js' ) ).href );
const render = mod.default;

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
const buttons = ( node ) => find( node, ( n ) => n.tagName === 'BUTTON' );
const toasts = () => find( body, ( n ) => n instanceof FakeElement && /ppclg-toast--/.test( String( n.className ) ) ).map( ( n ) => ( { kind: String( n.className ).replace( /.*ppclg-toast--(\w+).*/, '$1' ), text: n.textContent } ) );

const finding = ( over = {} ) => ( { severity: 'block', where: 'category', term: 'weight loss', object_id: 11, label: 'Weight Loss & Metabolic', url: 'https://shop.example/wp-admin/term.php?taxonomy=product_cat&tag_ID=11', excerpt: 'Weight Loss & Metabolic', ...over } );
const blocking = {
    status: 'block', effective: 'block', gate: true, blocking: true, checked_at: '2026-11-12T10:00:00+00:00', hash: 'a'.repeat( 40 ),
    counts: { block: 7, warn: 2 }, total: 9,
    findings: [
        finding(),
        finding( { object_id: 12, term: 'anti-aging', label: 'Anti-Aging & Cellular' } ),
        finding( { where: 'product', object_id: 101, term: 'syringe', label: 'Insulin Syringes 30G', url: 'javascript:alert(1)' } ),
        finding( { where: 'menu', object_id: 502, label: '<img src=x onerror=alert(1)>' } ),
        finding( { where: 'tag', object_id: 32, term: 'weight loss', label: 'peptides' } ),
        finding( { object_id: 17, term: 'skincare', label: 'Peptide Skincare' } ),
        finding( { object_id: 18, term: 'nasal spray', label: 'Nasal Sprays' } ),
        finding( { severity: 'warn', object_id: 15, term: 'recovery', label: 'Recovery' } ),
        finding( { severity: 'warn', object_id: 16, term: 'growth', label: 'Growth' } ),
    ],
};

const out = {};
out.models = {
    pass: mod.bannerModel( { effective: 'pass' } ),
    acknowledged: mod.bannerModel( { effective: 'acknowledged', counts: { block: 3, warn: 0 } } ),
    block: mod.bannerModel( blocking ),
    blockGateOff: mod.bannerModel( { ...blocking, gate: false } ),
    warn: mod.bannerModel( { effective: 'warn', counts: { block: 0, warn: 1 }, gate: true } ),
    unknown: mod.bannerModel( { effective: 'unknown', counts: { block: 0, warn: 0 } } ),
    failed: mod.bannerModel( { effective: 'unknown', blocking: true, error: 'The storefront check could not run: boom' } ),
    missing: mod.bannerModel( null ),
    // An older stored pass whose refresh failed: the gate fails closed, so the banner must say automation is paused.
    stalePassFailed: mod.bannerModel( { effective: 'pass', status: 'pass', blocking: true, error: 'x' } ),
    acknowledgedFailed: mod.bannerModel( { effective: 'acknowledged', status: 'block', blocking: true, error: '' } ),
    passErrorGateOff: mod.bannerModel( { effective: 'pass', status: 'pass', blocking: false, gate: false, error: 'The storefront check could not run: boom' } ),
};

// Pass / acknowledged empty the container.
const host = new FakeElement( 'div' );
host.appendChild( new FakeText( 'old content' ) );
render( host, { effective: 'pass' } );
out.passChildren = host.childNodes.length;
render( host, { effective: 'acknowledged' } );
out.acknowledgedChildren = host.childNodes.length;

// Blocking status renders the legal wording, findings and both buttons; unsafe URLs are not linked.
render( host, blocking );
out.blockText = host.textContent;
out.blockButtons = buttons( host ).map( ( b ) => b.textContent );
out.links = find( host, ( n ) => n.tagName === 'A' ).map( ( a ) => a.getAttribute( 'href' ) );
out.linkRel = find( host, ( n ) => n.tagName === 'A' ).map( ( a ) => a.getAttribute( 'rel' ) );
out.hasDetails = find( host, ( n ) => n.tagName === 'DETAILS' ).length;

// Re-check that the server refuses: honest error toast, no callback, banner unchanged.
let rechecked = null;
reply = { status: 500, body: { code: 'ppclg_storefront_check_failed', message: 'The storefront check could not run: boom.', data: { status: 500 } } };
await buttons( host ).find( ( b ) => b.textContent.includes( 'Re-check' ) ).click();
out.refusedToasts = toasts();
out.refusedCallback = rechecked;
out.stillBlocking = host.textContent.includes( 'paused' );

// Re-check that passes: the banner disappears and the host is told.
reply = { status: 200, body: { ok: true, message: 'Storefront checked: no consumer-use wording found.', effective: 'pass', status: 'pass', counts: { block: 0, warn: 0 }, findings: [] } };
render( host, blocking, { onRecheck: ( s ) => { rechecked = s; } } );
await buttons( host ).find( ( b ) => b.textContent.includes( 'Re-check' ) ).click();
out.recheckRequest = requests[ requests.length - 1 ];
out.recheckCallback = rechecked ? rechecked.effective : null;
out.afterRecheckChildren = host.childNodes.length;
out.toasts = toasts();

// Acknowledge refused with 409 (the findings changed meanwhile): the banner reloads the current findings, so the
// next acknowledgement sends the current hash instead of the stale one.
{
    const changed = { ...blocking, hash: 'b'.repeat( 40 ), counts: { block: 1, warn: 0 }, total: 1, findings: [ finding( { object_id: 99, term: 'capsules', label: 'Renamed Product Capsules' } ) ] };
    const ackHost = new FakeElement( 'div' );
    render( ackHost, blocking );
    const before = requests.length;
    replyQueue = [
        { status: 409, body: { code: 'ppclg_findings_changed', message: 'The storefront findings changed since you loaded them. Review the current findings and acknowledge again.', data: { status: 409 } } },
        { status: 200, body: changed },
    ];
    const pending = buttons( ackHost ).find( ( b ) => b.textContent.includes( 'Acknowledge these findings' ) ).click();
    await new Promise( ( r ) => setTimeout( r, 5 ) );
    const confirmButton = buttons( body ).find( ( b ) => b.textContent === 'Acknowledge' );
    out.ackConfirmFound = Boolean( confirmButton );
    if ( confirmButton ) {
        await confirmButton.click();
    }
    await pending;
    out.ackRequests = requests.slice( before ).map( ( r ) => `${ r.method } ${ r.url.replace( /^.*?(\/compliance\/)/, '$1' ).replace( /\?.*$/, '' ) }` );
    out.ackSentHash = requests[ before ] && requests[ before ].body ? requests[ before ].body.hash : null;
    out.ackShowsCurrentFindings = ackHost.textContent.includes( 'Renamed Product Capsules' ) && ! ackHost.textContent.includes( 'Anti-Aging & Cellular' );
    out.ackToast = toasts().filter( ( t ) => t.text.includes( 'findings changed' ) ).pop() || null;

    // The next acknowledgement covers the reloaded findings (their hash).
    replyQueue = [ { status: 200, body: { ...changed, effective: 'acknowledged', ok: true, message: 'Findings acknowledged.' } } ];
    const second = requests.length;
    const pending2 = buttons( ackHost ).find( ( b ) => b.textContent.includes( 'Acknowledge these findings' ) ).click();
    await new Promise( ( r ) => setTimeout( r, 5 ) );
    const confirm2 = buttons( body ).find( ( b ) => b.textContent === 'Acknowledge' );
    if ( confirm2 ) {
        await confirm2.click();
    }
    await pending2;
    out.secondAckHash = requests[ second ] && requests[ second ].body ? requests[ second ].body.hash : null;
}

// Toast timers would keep node alive for seconds: exit once the output is flushed.
process.stdout.write( JSON.stringify( out ), () => process.exit( 0 ) );
