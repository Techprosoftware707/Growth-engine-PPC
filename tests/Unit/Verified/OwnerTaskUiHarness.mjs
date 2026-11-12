/**
 * Node harness for OwnerTaskUiTest: imports assets/js/pages/submissions.js against a minimal fake DOM (enough
 * for ui.js el()/replace()/card()/callout()/button()/toast()) with a fake fetch and clipboard, renders the
 * verified-database owner-task card and prints one JSON object. Any use of innerHTML throws.
 *
 * Usage: node OwnerTaskUiHarness.mjs <pluginDir>
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
        this.value = '';
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
    insertAdjacentHTML() {
        throw new Error( 'insertAdjacentHTML must not be used' );
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
};
globalThis.window = { location: { href: 'https://shop.example/wp-admin/admin.php?page=ppclg-submissions', origin: 'https://shop.example' }, localStorage: { getItem: () => null, setItem: () => {} } };

const clipboard = [];
Object.defineProperty( globalThis, 'navigator', { value: { clipboard: { writeText: async ( t ) => { clipboard.push( t ); } } }, configurable: true } );

const requests = [];
let reply = { status: 200, body: {} };
globalThis.fetch = async ( url, init ) => {
    requests.push( { url: String( url ), method: init.method, body: init.body ? JSON.parse( init.body ) : null } );
    const text = JSON.stringify( reply.body );
    return { ok: reply.status >= 200 && reply.status < 300, status: reply.status, text: async () => text };
};

const pluginDir = path.resolve( process.argv[ 2 ] || '.' );
const api = await import( pathToFileURL( path.join( pluginDir, 'assets/js/api.js' ) ).href );
api.configure( { restRoot: 'https://shop.example/wp-json/ppclg/v1', nonce: 'n' } );
const mod = await import( pathToFileURL( path.join( pluginDir, 'assets/js/pages/submissions.js' ) ).href );

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
// ui.js caches its toast region (always "connected" in this fake DOM), so it is emptied rather than replaced.
const clearToasts = () => {
    find( body, ( n ) => n instanceof FakeElement && String( n.className ).includes( 'ppclg-toasts' ) ).forEach( ( host ) => {
        host.childNodes = [];
    } );
};

const pkg = {
    source: 'verified_db', item_id: 'abc123', tier: 1, authority: 86, outreach: true, conditional: true, data_version: '2026-11-11-x',
    platform: 'R&D World <contributed articles>', target_url: 'https://www.rdworldonline.com/content-submission-guidelines-for-rd-world/',
    registration_url: 'https://www.rdworldonline.com/content-submission-guidelines-for-rd-world/', contact_email: 'editor@example.org',
    business_name: 'Pure Peptides Canada', website: 'https://purepeptidescanada.com', address: '222 Jarvis St, Toronto ON M5B 2B8, Canada',
    email: 'hi@purepeptidescanada.com', founded: '2023',
    description: 'Pure Peptides Canada supplies research peptides and laboratory reagents. All research products are sold strictly for in-vitro laboratory research use and are not for human or veterinary use.',
    category_rule: 'Laboratory supplies / biotechnology / scientific research supplier.',
    steps: 'CONDITIONAL (audit): proceed only with a real, named author\n<script>alert(1)</script> Pitch once by email\nConfirm the bio link renders',
    requirements: 'Real named author.', gate_note: 'Storefront consistency gate: every listing uses the research-use-only description.',
    verification_note: 'HTTP 200 on the guidelines page.', link_attribute: 'dofollow (bio link)', effort: '60',
};

const out = {};
out.safe = {
    https: mod.safeHttpUrl( 'https://example.org/a' ),
    http: mod.safeHttpUrl( 'http://example.org' ),
    js: mod.safeHttpUrl( 'javascript:alert(1)' ),
    mailto: mod.safeHttpUrl( 'mailto:a@b.c' ),
    relative: mod.safeHttpUrl( '/wp-admin' ),
    protocolRelative: mod.safeHttpUrl( '//evil.example' ),
};
out.isOwner = mod.ownerTaskPackage( { package: pkg } ) !== null;
out.notOwner = mod.ownerTaskPackage( { package: { source: 'crawl' } } ) === null && mod.ownerTaskPackage( null ) === null;
out.kit = mod.listingKitText( pkg );
out.steps = mod.stepLines( pkg.steps );

// Owner-task card for an open task.
let marked = 0;
const sub = { id: 77, status: 'needs_manual', target_url: pkg.target_url, payload: { package: pkg } };
const cardEl = mod.ownerTaskCard( sub, pkg, { onMarked: async () => { marked++; } } );
out.text = cardEl.textContent;
out.anchors = find( cardEl, ( n ) => n.tagName === 'A' ).map( ( a ) => ( { href: a.getAttribute( 'href' ), target: a.getAttribute( 'target' ), rel: a.getAttribute( 'rel' ), text: a.textContent } ) );
const stepsP = find( cardEl, ( n ) => n.tagName === 'P' && String( n.className ).includes( 'ppclg-owner-steps' ) )[ 0 ];
out.stepsBr = stepsP ? stepsP.childNodes.filter( ( c ) => c.tagName === 'BR' ).length : -1;
out.stepsTextNodes = stepsP ? stepsP.childNodes.filter( ( c ) => c instanceof FakeText ).map( ( c ) => c.data ) : [];
out.scriptElements = find( cardEl, ( n ) => n.tagName === 'SCRIPT' ).length;
out.buttons = buttons( cardEl ).map( ( b ) => b.textContent );

// Copy listing kit.
await buttons( cardEl ).find( ( b ) => b.textContent === 'Copy listing kit' ).click();
out.clipboard = clipboard.slice();
out.copyToasts = toasts();
clearToasts();

// Mark submitted with a non-http listing URL: refused locally, no request.
const urlInput = find( cardEl, ( n ) => n.tagName === 'INPUT' && n.getAttribute( 'type' ) === 'url' )[ 0 ];
const markBtn = buttons( cardEl ).find( ( b ) => b.textContent === 'Mark submitted' );
urlInput.value = 'javascript:alert(1)';
const before = requests.length;
await markBtn.click();
out.badUrlRequests = requests.length - before;
out.badUrlToasts = toasts();
clearToasts();

// Server refuses (ok: false): its message is shown, onMarked is not called.
urlInput.value = 'https://www.rdworldonline.com/author/pure-peptides-canada/';
reply = { status: 200, body: { ok: false, action: 'mark-submitted', message: 'This submission is already confirmed.' } };
await markBtn.click();
out.refusedRequest = requests[ requests.length - 1 ];
out.refusedToasts = toasts();
out.markedAfterRefusal = marked;
clearToasts();

// Server accepts.
reply = { status: 200, body: { ok: true, action: 'mark-submitted', message: 'Marked as submitted; verification starts on the next run.' } };
await markBtn.click();
out.acceptedToasts = toasts();
out.markedAfterAccept = marked;
clearToasts();

// Unsafe target URL and a listing task: no "Open submission page" link, no outreach callout.
const unsafe = { ...pkg, outreach: false, conditional: false, target_url: 'javascript:alert(1)', registration_url: '', contact_email: '' };
const unsafeCard = mod.ownerTaskCard( { id: 78, status: 'needs_manual', target_url: 'javascript:alert(1)' }, unsafe );
out.unsafeAnchors = find( unsafeCard, ( n ) => n.tagName === 'A' ).map( ( a ) => a.getAttribute( 'href' ) );
out.unsafeText = unsafeCard.textContent;

// A submitted task shows no "Mark submitted" form.
const doneCard = mod.ownerTaskCard( { id: 79, status: 'submitted', target_url: pkg.target_url }, pkg );
out.doneButtons = buttons( doneCard ).map( ( b ) => b.textContent );

// Badges for list rows.
const badgeHost = document.createElement( 'div' );
mod.ownerTaskBadges( { tier: 2, authority: null, outreach: false, conditional: true } ).forEach( ( b ) => badgeHost.appendChild( b ) );
out.badges = badgeHost.childNodes.map( ( b ) => b.textContent );

process.stdout.write( JSON.stringify( out ), () => process.exit( 0 ) );
