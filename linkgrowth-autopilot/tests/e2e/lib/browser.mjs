/**
 * Browser-side instrumentation of the E2E suite: request, console, dialog, download and popup tracking per page, a
 * DOM mutation counter (injected before any page script runs), "settled" detection, the [data-lga-control] inventory
 * (with the interactive elements that lack an id), holding a page's writes while it lingers, and the narrow-width
 * overflow probe.
 */
import fs from 'node:fs';
import path from 'node:path';

export const SITE_HOSTS = [ 'localhost:8793', '127.0.0.1:8793' ];

/**
 * How long after a step's action the page is not taken as settled, in ms: longer than the longest debounce the
 * dashboard starts from an input (ui.js filterBar 350 ms, the outreach claims check 400 ms) plus the time its request
 * needs to show up, so the request a step schedules is answered within the step and never counted as the effect of
 * the action that follows it. BrowserLibTest checks every debounce in assets/js against it.
 */
export const DEFER_MS = 650;

/**
 * How long a page stays open after the walk has moved on, in ms (run.mjs retires it instead of closing it): a timer
 * the entry started (the Automation page's status reload 3 s after "Kick scheduler", a toast's 4.5-8 s dismissal)
 * still runs on a live page, and what it raises is reported for that entry. Its writes are held meanwhile
 * (holdWrites). BrowserLibTest checks every one-shot timer in assets/js against it.
 */
export const LINGER_MS = 8000;

/**
 * Interactive elements (DESIGN.md §10.1: each one carries data-lga-control; spec §10: no decorative controls). Inside
 * the dashboard (.lga-wrap: the page, the shell, and the drawers, dialogs and toasts mounted in it) every match that
 * is not itself, or inside, a named control is reported as a control without an id, except a focusable tab panel
 * (role=tabpanel, tabindex 0: a region, not a control) and a <details> whose <summary> is the control.
 */
export const INTERACTIVE_SELECTOR = [
    'a[href]', 'button', 'input:not([type="hidden"])', 'select', 'textarea', 'summary', 'details', '[onclick]',
    '[role="button"]', '[role="link"]', '[role="tab"]', '[role="switch"]', '[role="checkbox"]', '[role="radio"]',
    '[role="menuitem"]', '[role="menuitemcheckbox"]', '[role="menuitemradio"]', '[role="option"]', '[role="combobox"]',
    '[role="slider"]', '[role="spinbutton"]', '[role="searchbox"]', '[role="textbox"]', '[contenteditable=""]',
    '[contenteditable="true"]', '[tabindex]:not([tabindex="-1"])',
].join( ', ' );

/** lga/v1 route, query and kind of a URL ('lga', 'rest', 'ajax', 'document', 'asset', 'external', 'other'). */
export function classify( url ) {
    let u;
    try {
        u = new URL( url );
    } catch ( e ) {
        return { kind: 'other', path: String( url ) };
    }
    if ( u.protocol === 'blob:' || u.protocol === 'data:' ) {
        return { kind: 'other', path: u.protocol };
    }
    const local = SITE_HOSTS.includes( u.host );
    if ( ! local ) {
        return { kind: 'external', host: u.host, path: u.href };
    }
    let route = '';
    if ( u.pathname.startsWith( '/wp-json/' ) ) {
        route = '/' + u.pathname.slice( '/wp-json/'.length );
    } else if ( u.searchParams.has( 'rest_route' ) ) {
        route = String( u.searchParams.get( 'rest_route' ) );
    }
    const query = {};
    for ( const [ k, v ] of u.searchParams.entries() ) {
        if ( ! [ 'rest_route', '_wpnonce', '_locale' ].includes( k ) ) {
            query[ k ] = v;
        }
    }
    if ( route.startsWith( '/lga/v1' ) ) {
        return { kind: 'lga', route: route.slice( '/lga/v1'.length ) || '/', query, path: u.pathname + u.search };
    }
    if ( route ) {
        return { kind: 'rest', route, query, path: u.pathname + u.search };
    }
    if ( u.pathname.endsWith( '/admin-ajax.php' ) ) {
        return { kind: 'ajax', query, path: u.pathname + u.search };
    }
    return { kind: 'asset', query, path: u.pathname + u.search };
}

/** Per-page event log: requests (with status and failure), console errors, page errors, dialogs, downloads, popups. */
export class Tracker {
    constructor( page, { log } = {} ) {
        this.page = page;
        this.log = log || ( () => {} );
        this.requests = [];
        this.console = [];
        this.pageErrors = [];
        this.dialogs = [];
        this.downloads = [];
        this.popups = [];
        this.navigations = [];
        this.inflight = new Set();
        const byReq = new Map();
        page.on( 'request', ( req ) => {
            const c = classify( req.url() );
            const entry = { seq: this.requests.length, method: req.method(), url: req.url(), ...c, type: req.resourceType(), status: null, failure: null, postData: null, done: false, at: Date.now() };
            if ( c.kind === 'lga' || c.kind === 'rest' || c.kind === 'ajax' ) {
                try {
                    entry.postData = req.postData();
                } catch ( e ) {
                    entry.postData = null;
                }
            }
            byReq.set( req, entry );
            this.requests.push( entry );
            if ( ! [ 'image', 'font', 'media' ].includes( entry.type ) ) {
                this.inflight.add( entry );
            }
        } );
        page.on( 'response', ( res ) => {
            const entry = byReq.get( res.request() );
            if ( entry ) {
                entry.status = res.status();
            }
        } );
        const finish = ( req, failure ) => {
            const entry = byReq.get( req );
            if ( ! entry ) {
                return;
            }
            entry.done = true;
            if ( failure ) {
                entry.failure = failure;
            }
            this.inflight.delete( entry );
        };
        page.on( 'requestfinished', ( req ) => finish( req, null ) );
        page.on( 'requestfailed', ( req ) => finish( req, ( req.failure() && req.failure().errorText ) || 'failed' ) );
        page.on( 'console', ( m ) => {
            if ( m.type() === 'error' ) {
                const loc = m.location() || {};
                this.console.push( { seq: this.requests.length, text: m.text().slice( 0, 2000 ), url: loc.url || '', line: loc.lineNumber, at: Date.now() } );
            }
        } );
        page.on( 'pageerror', ( e ) => this.pageErrors.push( { text: String( e && e.message ? e.message : e ).slice( 0, 2000 ), at: Date.now() } ) );
        page.on( 'dialog', ( d ) => {
            this.dialogs.push( { type: d.type(), message: d.message().slice( 0, 500 ), at: Date.now() } );
            d.accept().catch( () => {} );
        } );
        page.on( 'download', ( d ) => {
            this.downloads.push( { name: d.suggestedFilename(), at: Date.now() } );
            d.cancel().catch( () => {} );
        } );
        page.on( 'popup', ( p ) => {
            const rec = { url: p.url(), at: Date.now(), page: p };
            this.popups.push( rec );
            p.waitForLoadState( 'domcontentloaded', { timeout: 10000 } ).then( () => {
                rec.url = p.url();
            } ).catch( () => {} ).finally( () => {
                rec.url = p.url() || rec.url;
            } );
        } );
        page.on( 'framenavigated', ( frame ) => {
            if ( frame === page.mainFrame() ) {
                this.navigations.push( { url: frame.url(), at: Date.now() } );
            }
        } );
    }

    mark() {
        return { requests: this.requests.length, console: this.console.length, pageErrors: this.pageErrors.length, dialogs: this.dialogs.length, downloads: this.downloads.length, popups: this.popups.length, navigations: this.navigations.length, at: Date.now() };
    }

    since( mark ) {
        return this.between( mark, null );
    }

    /** The events between two marks (`to` null: up to now). */
    between( from, to ) {
        const cut = ( list, key ) => list.slice( from[ key ], to ? to[ key ] : undefined );
        return {
            requests: cut( this.requests, 'requests' ),
            console: cut( this.console, 'console' ),
            pageErrors: cut( this.pageErrors, 'pageErrors' ),
            dialogs: cut( this.dialogs, 'dialogs' ),
            downloads: cut( this.downloads, 'downloads' ),
            popups: cut( this.popups, 'popups' ),
            navigations: cut( this.navigations, 'navigations' ),
        };
    }

    async closePopups() {
        for ( const p of this.popups ) {
            if ( p.page && ! p.page.isClosed() ) {
                await p.page.close().catch( () => {} );
            }
        }
    }
}

/** Injected into every document: counts DOM mutations under <html> and remembers the time of the last one. */
export function mutationInitScript() {
    const state = { mutations: 0, last: 0 };
    Object.defineProperty( window, '__lgaE2E', { value: state, configurable: false, enumerable: false, writable: false } );
    const start = () => {
        const obs = new MutationObserver( ( list ) => {
            state.mutations += list.length;
            state.last = performance.now();
        } );
        obs.observe( document.documentElement, { subtree: true, childList: true, attributes: true, characterData: true } );
    };
    if ( document.documentElement ) {
        start();
    } else {
        document.addEventListener( 'readystatechange', start, { once: true } );
    }
}

/** Mutation count, ms since the last mutation and the page clock (null without a counter, e.g. after a navigation). */
export async function domState( page ) {
    return page.evaluate( () => {
        const s = window.__lgaE2E;
        return s ? { mutations: s.mutations, quietMs: s.last ? performance.now() - s.last : performance.now(), now: performance.now() } : null;
    } ).catch( () => null );
}

/**
 * Waits until no tracked request is open, no loading indicator shows and the DOM has been quiet for `quiet` ms (at
 * most `max` ms). With `needPage` it also waits for the page module to have rendered (or failed). `since` is the page
 * clock (domState().now) of the action: quiet is not counted from before it, and with `defer` the page is not settled
 * until `defer` ms after it either, so a debounced effect the action scheduled (an input's delayed request) has been
 * sent, answered and rendered when this returns.
 */
export async function settle( page, tracker, { quiet = 400, max = 20000, needPage = false, since = 0, defer = 0 } = {} ) {
    const start = Date.now();
    while ( Date.now() - start < max ) {
        await page.waitForTimeout( 100 );
        if ( page.isClosed() ) {
            return false;
        }
        if ( tracker.inflight.size > 0 ) {
            continue;
        }
        const st = await page.evaluate( ( [ need, from, hold ] ) => {
            const s = window.__lgaE2E;
            if ( need && ! document.querySelector( '#lga-main > .lga-page, #lga-main .lga-error, .lga-load-failure, #lga-main .lga-empty' ) ) {
                return { busy: true };
            }
            const vis = ( n ) => n && n.getClientRects().length > 0;
            const loading = [ ...document.querySelectorAll( '.lga-wrap .lga-loading, .lga-wrap .lga-skeleton' ) ].some( vis );
            // Quiet is counted from the last mutation, and never from before `from` (the moment of the action), so a
            // debounced or delayed effect is waited for; `hold` keeps the page unsettled that long after `from`.
            // (After a navigation the new document's clock starts again: `from` then no longer applies.)
            const now = performance.now();
            const fromOk = from && from <= now;
            const last = Math.max( s && s.last ? s.last : 0, fromOk ? from : 0 );
            return { busy: loading || Boolean( fromOk && hold && now - from < hold ), quietMs: last ? now - last : 1e9 };
        }, [ needPage, since, defer ] ).catch( () => ( { busy: true } ) );
        if ( ! st.busy && st.quietMs >= quiet ) {
            return true;
        }
    }
    return false;
}

/**
 * Every [data-lga-control] element of the document (id, visibility and a short description), followed by every
 * interactive element of the dashboard that has no id (INTERACTIVE_SELECTOR): those come with `id: ''`,
 * `unnamed: true` and `scope` (`shell` for the header, navigation and footer, else the page shown).
 */
export async function collectControls( page ) {
    return page.evaluate( ( interactive ) => {
        const describe = ( n ) => {
            const style = getComputedStyle( n );
            const visible = n.getClientRects().length > 0 && style.visibility !== 'hidden' && ! n.closest( '[hidden]' );
            const region = n.closest( '.lga-modal' ) ? 'modal' : n.closest( '.lga-drawer' ) ? 'drawer' : n.closest( '.lga-toast' ) ? 'toast' : n.closest( '.lga-header' ) ? 'header' : n.closest( '.lga-subnav' ) ? 'nav' : n.closest( '#lga-main' ) ? 'main' : 'other';
            return {
                id: n.getAttribute( 'data-lga-control' ),
                tag: n.tagName.toLowerCase(),
                type: n.getAttribute( 'type' ) || '',
                role: n.getAttribute( 'role' ) || '',
                text: ( n.getAttribute( 'aria-label' ) || n.innerText || n.value || n.getAttribute( 'placeholder' ) || '' ).replace( /\s+/g, ' ' ).trim().slice( 0, 80 ),
                visible,
                enabled: ! n.disabled && n.getAttribute( 'aria-disabled' ) !== 'true',
                region,
                // For the --discover listing: what an entry needs to change the field.
                value: [ 'INPUT', 'TEXTAREA', 'SELECT' ].includes( n.tagName ) && n.type !== 'password' ? String( n.value ).slice( 0, 120 ) : undefined,
                checked: n.type === 'checkbox' ? n.checked : ( n.getAttribute( 'role' ) === 'switch' ? n.getAttribute( 'aria-checked' ) === 'true' : undefined ),
                options: n.tagName === 'SELECT' ? [ ...n.options ].map( ( o ) => o.value ).slice( 0, 12 ) : undefined,
                inputType: n.tagName === 'INPUT' ? n.type : undefined,
                min: n.getAttribute( 'min' ) || undefined,
                max: n.getAttribute( 'max' ) || undefined,
                inTab: ( () => {
                    const panel = n.closest( '[role="tabpanel"]' );
                    const tab = panel ? document.getElementById( panel.getAttribute( 'aria-labelledby' ) || '' ) : null;
                    return tab ? tab.getAttribute( 'data-lga-control' ) || undefined : undefined;
                } )(),
            };
        };
        const named = [ ...document.querySelectorAll( '[data-lga-control]' ) ].map( describe );
        const pageRoot = document.querySelector( '#lga-main > .lga-page' );
        const shown = ( pageRoot && ( /(?:^|\s)lga-page-([a-z0-9_-]+)/.exec( pageRoot.className ) || [] )[ 1 ] ) || ( document.getElementById( 'lga-app' ) || { dataset: {} } ).dataset.page || '';
        const unnamed = [];
        for ( const wrap of document.querySelectorAll( '.lga-wrap' ) ) {
            for ( const n of wrap.querySelectorAll( interactive ) ) {
                // A named control, or part of one (the input inside a labelled switch), is covered by its entry.
                if ( n.closest( '[data-lga-control]' ) ) {
                    continue;
                }
                if ( n.getAttribute( 'role' ) === 'tabpanel' || ( n.tagName === 'DETAILS' && n.querySelector( ':scope > summary' ) ) ) {
                    continue;
                }
                unnamed.push( { ...describe( n ), id: '', unnamed: true, scope: n.closest( '.lga-header, .lga-subnav, .lga-footer' ) ? 'shell' : shown, html: n.outerHTML.slice( 0, 160 ) } );
            }
        }
        return [ ...named, ...unnamed ];
    }, INTERACTIVE_SELECTOR ).catch( () => [] );
}

/**
 * Holds every write the page sends from now on (any REST or admin-ajax request other than GET/HEAD, and any form
 * post): it is never sent to the server and never answered, so a page that lingers after its entry (LINGER_MS) cannot
 * change the site the later entries see. Reads go through, so a timer's reload still runs and is checked.
 */
export async function holdWrites( page ) {
    await page.route( () => true, async ( route ) => {
        const req = route.request();
        const method = req.method();
        if ( method === 'GET' || method === 'HEAD' || method === 'OPTIONS' ) {
            await route.fallback();
            return;
        }
        // Not answered: the request stays pending until the page is closed.
    } );
}

/** document.documentElement.scrollWidth against innerWidth, and the outermost elements that stick out. */
export async function overflowProbe( page ) {
    return page.evaluate( () => {
        const iw = window.innerWidth;
        const sw = document.documentElement.scrollWidth;
        const out = [];
        if ( sw > iw ) {
            const clipped = ( n ) => {
                for ( let cur = n.parentElement; cur && cur !== document.body; cur = cur.parentElement ) {
                    if ( getComputedStyle( cur ).overflowX !== 'visible' ) {
                        return true;
                    }
                }
                return false;
            };
            for ( const n of document.body.querySelectorAll( '*' ) ) {
                if ( n.getClientRects().length === 0 ) {
                    continue;
                }
                const r = n.getBoundingClientRect();
                if ( r.right > iw + 0.5 && ! clipped( n ) && getComputedStyle( n ).position !== 'fixed' ) {
                    const cls = [ ...n.classList ].slice( 0, 2 ).join( '.' );
                    out.push( `${ n.tagName.toLowerCase() }${ cls ? '.' + cls : '' } (right ${ Math.round( r.right ) })` );
                }
            }
        }
        return { innerWidth: iw, scrollWidth: sw, ok: sw <= iw, offenders: out.slice( 0, 8 ) };
    } );
}

/**
 * Routes a context: requests to hosts other than the site are answered locally (an empty response; a small HTML
 * page for a document), so the browser never reaches the internet; with `assetsFrom`, the plugin's assets are served
 * from that folder (a throwaway copy of the plugin) instead of the site's plugin folder.
 */
export async function routeContext( context, { assetsFrom = '', external = [] } = {} ) {
    await context.route( ( url ) => ! SITE_HOSTS.includes( url.host ) && ( url.protocol === 'http:' || url.protocol === 'https:' ), async ( route ) => {
        const req = route.request();
        external.push( { url: req.url(), type: req.resourceType() } );
        if ( req.resourceType() === 'document' ) {
            await route.fulfill( { status: 200, contentType: 'text/html', body: '<!doctype html><title>External page (E2E stub)</title><p>The E2E suite does not leave this machine.</p>' } );
        } else {
            await route.fulfill( { status: 204, body: '' } );
        }
    } );
    if ( assetsFrom ) {
        await context.route( /\/wp-content\/plugins\/linkgrowth-autopilot\/assets\//, async ( route ) => {
            const u = new URL( route.request().url() );
            const rel = decodeURIComponent( u.pathname.replace( /^.*\/wp-content\/plugins\/linkgrowth-autopilot\//, '' ) );
            const file = path.join( assetsFrom, rel );
            if ( ! file.startsWith( path.join( assetsFrom, 'assets' ) ) || ! fs.existsSync( file ) ) {
                await route.fulfill( { status: 404, body: 'not found' } );
                return;
            }
            const type = file.endsWith( '.js' ) ? 'text/javascript' : file.endsWith( '.css' ) ? 'text/css' : file.endsWith( '.svg' ) ? 'image/svg+xml' : 'application/octet-stream';
            await route.fulfill( { status: 200, contentType: type, body: fs.readFileSync( file ) } );
        } );
    }
}
