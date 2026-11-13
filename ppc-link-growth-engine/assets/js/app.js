/**
 * Application entry: reads the injected configuration, renders the shell
 * (header, sub-navigation, notification bell, automation pill) and lazy-loads
 * the page module named by #ppclg-app[data-page].
 */

// api.js and ui.js are loaded with dynamic import() from start() rather than static imports: a static
// import that fails to link (a cached copy of ui.js from an older release that lacks an export this
// app.js needs) stops the whole module graph before any code runs and leaves #ppclg-app blank. Loaded
// dynamically, the failure is caught and the operator is told to reload. The specifiers stay plain
// relative paths so the page modules' own '../ui.js' imports resolve to the same module instance, and
// so the versioned import map Assets prints for every sibling module applies to all of them alike.
const loadCore = () => Promise.all( [ import( './api.js' ), import( './ui.js' ) ] );

const PAGES = {
    overview: () => import( './pages/overview.js' ),
    prospects: () => import( './pages/prospects.js' ),
    submissions: () => import( './pages/submissions.js' ),
    outreach: () => import( './pages/outreach.js' ),
    backlinks: () => import( './pages/backlinks.js' ),
    seo: () => import( './pages/seo.js' ),
    automation: () => import( './pages/automation.js' ),
    settings: () => import( './pages/settings.js' ),
    logs: () => import( './pages/logs.js' ),
};

const PAGE_ICONS = {
    overview: 'home',
    prospects: 'target',
    submissions: 'package',
    outreach: 'mail',
    backlinks: 'link',
    seo: 'search',
    automation: 'zap',
    settings: 'settings',
    logs: 'list',
};

const DEFAULT_PAGES = {
    overview: 'Overview',
    prospects: 'Prospects',
    submissions: 'Submissions',
    outreach: 'Outreach',
    backlinks: 'Backlinks',
    seo: 'On-site SEO',
    automation: 'Automation',
    settings: 'Settings',
    logs: 'Logs',
};

const THEME_KEY = 'ppclg_theme';
const THEMES = [ 'auto', 'light', 'dark' ];

function readConfig() {
    const node = document.getElementById( 'ppclg-config' );
    if ( ! node ) {
        return {};
    }
    try {
        const parsed = JSON.parse( node.textContent || '{}' );
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch ( e ) {
        return {};
    }
}

/** Pages a submitter-only user (the "Link Growth Submitter" role) sees: the owner-task queue only. */
const SUBMITTER_PAGES = [ 'submissions' ];

function normalizeConfig( raw, mount ) {
    const adminUrl = typeof raw.adminUrl === 'string' && raw.adminUrl !== '' ? raw.adminUrl : `${ window.location.origin }/wp-admin/`;
    const capabilities = raw.capabilities && typeof raw.capabilities === 'object' ? raw.capabilities : {};
    const submitOnly = capabilities.submitOnly === true;
    let pages = raw.pages && typeof raw.pages === 'object' && Object.keys( raw.pages ).length > 0 ? raw.pages : DEFAULT_PAGES;
    if ( submitOnly ) {
        // The server already sends only the owner-task page; never show another tab even if it did not.
        pages = { submissions: typeof pages.submissions === 'string' && pages.submissions !== '' ? pages.submissions : 'Owner tasks' };
    }
    const pageUrls = {};
    for ( const key of Object.keys( pages ) ) {
        if ( raw.pageUrls && typeof raw.pageUrls[ key ] === 'string' ) {
            pageUrls[ key ] = raw.pageUrls[ key ];
        } else {
            pageUrls[ key ] = `${ adminUrl.replace( /\/+$/, '' ) }/admin.php?page=${ key === 'overview' ? 'ppclg' : `ppclg-${ key }` }`;
        }
    }
    const requested = submitOnly ? SUBMITTER_PAGES[ 0 ] : ( ( mount && mount.dataset.page ) || raw.page || 'overview' );
    return {
        restRoot: typeof raw.restRoot === 'string' ? raw.restRoot : '',
        nonce: typeof raw.nonce === 'string' ? raw.nonce : '',
        page: Object.prototype.hasOwnProperty.call( PAGES, requested ) ? requested : 'overview',
        siteDomain: typeof raw.siteDomain === 'string' ? raw.siteDomain : '',
        brand: typeof raw.brand === 'string' && raw.brand !== '' ? raw.brand : 'Pure Peptides Canada',
        pages,
        pageUrls,
        version: typeof raw.version === 'string' ? raw.version : '',
        user: raw.user && typeof raw.user === 'object' ? raw.user : { name: '' },
        adminUrl,
        pluginUrl: typeof raw.pluginUrl === 'string' ? raw.pluginUrl : '',
        homeUrl: typeof raw.homeUrl === 'string' ? raw.homeUrl : '',
        automationEnabled: Boolean( raw.automationEnabled ),
        capabilities,
        submitOnly,
        newUserUrl: typeof raw.newUserUrl === 'string' ? raw.newUserUrl : '',
        unreadNotifications: Number( raw.unreadNotifications ) || 0,
        locale: typeof raw.locale === 'string' ? raw.locale : '',
        timezone: typeof raw.timezone === 'string' ? raw.timezone : '',
    };
}

function parseHash() {
    const out = {};
    const hash = window.location.hash.replace( /^#/, '' );
    if ( hash === '' ) {
        return out;
    }
    for ( const part of hash.split( '&' ) ) {
        const [ k, v = '' ] = part.split( '=' );
        if ( k ) {
            try {
                out[ decodeURIComponent( k ) ] = decodeURIComponent( v );
            } catch ( e ) {
                out[ k ] = v;
            }
        }
    }
    return out;
}

function buildHash( params ) {
    const parts = [];
    for ( const [ k, v ] of Object.entries( params || {} ) ) {
        if ( v === undefined || v === null || v === '' ) {
            continue;
        }
        parts.push( `${ encodeURIComponent( k ) }=${ encodeURIComponent( String( v ) ) }` );
    }
    return parts.length ? `#${ parts.join( '&' ) }` : '';
}

/**
 * True for fragments in the k=v&k=v form buildHash() writes, and for "no fragment at all" (Back to the
 * first view). False for plain in-page anchors (#wpbody-content) and for a bare '#' from an href="#" link.
 */
function isParamHash( hash, href ) {
    const raw = String( hash || '' ).replace( /^#/, '' );
    if ( raw === '' ) {
        return ! String( href || '' ).endsWith( '#' );
    }
    return raw.includes( '=' );
}

/** True when `url` points at the current document (same origin, path and query; the fragment may differ). */
function isSameDocument( url ) {
    try {
        const target = new URL( url, window.location.href );
        const here = new URL( window.location.href );
        return target.origin === here.origin && target.pathname === here.pathname && target.search === here.search;
    } catch ( e ) {
        return false;
    }
}

function readTheme() {
    try {
        const stored = window.localStorage.getItem( THEME_KEY );
        return THEMES.includes( stored ) ? stored : 'auto';
    } catch ( e ) {
        return 'auto';
    }
}

function storeTheme( theme ) {
    try {
        window.localStorage.setItem( THEME_KEY, theme );
    } catch ( e ) {
        // Storage unavailable; the choice lasts for this page view only.
    }
}

function logoSvg() {
    const node = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
    node.setAttribute( 'viewBox', '0 0 24 24' );
    node.setAttribute( 'fill', 'none' );
    node.setAttribute( 'stroke', 'currentColor' );
    node.setAttribute( 'stroke-width', '2' );
    node.setAttribute( 'stroke-linecap', 'round' );
    node.setAttribute( 'stroke-linejoin', 'round' );
    node.setAttribute( 'aria-hidden', 'true' );
    const path = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
    path.setAttribute( 'd', 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7' );
    node.appendChild( path );
    return node;
}

/**
 * Shown when api.js / ui.js (or the bootstrap itself) cannot be loaded. Plain DOM only: ui.js is
 * exactly what may have failed, and nothing here may use innerHTML.
 */
function renderLoadFailure( mount, error ) {
    const box = document.createElement( 'div' );
    box.className = 'notice notice-error ppclg-load-failure';
    box.setAttribute( 'role', 'alert' );
    const title = document.createElement( 'p' );
    const strong = document.createElement( 'strong' );
    strong.textContent = 'The Link Growth dashboard could not load.';
    title.appendChild( strong );
    const hint = document.createElement( 'p' );
    hint.textContent = 'This usually means the browser or a CDN cache (e.g. Cloudflare) is still serving a script from an older plugin version. Reload the page; if the problem persists, do a hard refresh (Ctrl+Shift+R / Cmd+Shift+R) or purge the CDN cache.';
    const actions = document.createElement( 'p' );
    const reload = document.createElement( 'button' );
    reload.type = 'button';
    reload.className = 'button button-primary';
    reload.textContent = 'Reload the page';
    reload.addEventListener( 'click', () => window.location.reload() );
    actions.appendChild( reload );
    box.append( title, hint, actions );
    const detail = error && error.message ? String( error.message ) : '';
    if ( detail ) {
        const code = document.createElement( 'p' );
        const small = document.createElement( 'code' );
        small.textContent = detail;
        code.appendChild( small );
        box.appendChild( code );
    }
    while ( mount.firstChild ) {
        mount.removeChild( mount.firstChild );
    }
    mount.appendChild( box );
}

async function start() {
    const mount = document.getElementById( 'ppclg-app' );
    if ( ! mount ) {
        return;
    }
    let core;
    try {
        core = await loadCore();
    } catch ( e ) {
        renderLoadFailure( mount, e );
        return;
    }
    try {
        boot( mount, core[ 0 ], core[ 1 ] );
    } catch ( e ) {
        // A shell that throws half-way (e.g. ui.js from another release with a changed signature)
        // must not leave a blank or half-drawn screen either.
        renderLoadFailure( mount, e );
    }
}

function boot( mount, api, ui ) {
    const { el, replace, icon, iconButton, toast, drawer, button, badge, timeEl, errorState, loading, emptyState, setLocale, fmtNumber, closeOverlays } = ui;
    const wrap = mount.closest( '.ppclg-wrap' ) || mount;
    const config = normalizeConfig( readConfig(), mount );
    api.configure( config );
    setLocale( config.locale, config.timezone );

    let theme = readTheme();
    wrap.dataset.theme = theme;

    // ----- Header -----------------------------------------------------
    const themeBtn = iconButton( 'monitor', 'Theme: auto', { onClick: () => {
        theme = THEMES[ ( THEMES.indexOf( theme ) + 1 ) % THEMES.length ];
        wrap.dataset.theme = theme;
        storeTheme( theme );
        syncThemeButton();
        toast( `Theme: ${ theme }`, 'info', { timeout: 1500 } );
    } } );
    const syncThemeButton = () => {
        const names = { auto: 'monitor', light: 'sun', dark: 'moon' };
        replace( themeBtn, icon( names[ theme ] || 'monitor', 18 ) );
        themeBtn.setAttribute( 'aria-label', `Theme: ${ theme } (click to change)` );
        themeBtn.title = `Theme: ${ theme } (click to change)`;
    };
    syncThemeButton();

    const bellBadge = el( 'span', { class: 'ppclg-bell-badge', hidden: true, 'aria-hidden': 'true' } );
    const bellBtn = el( 'button', { type: 'button', class: 'ppclg-iconbtn', 'aria-label': 'Notifications', title: 'Notifications' }, icon( 'bell', 18 ), bellBadge );
    bellBtn.addEventListener( 'click', () => openNotifications() );

    const pillDot = el( 'span', { class: 'ppclg-pill-dot' } );
    const pillText = el( 'span' );
    const pill = el( 'a', { class: 'ppclg-pill', href: config.pageUrls.automation || '#', title: 'Open automation settings' }, pillDot, pillText );

    const header = el( 'header', { class: 'ppclg-header' },
        el( 'div', { class: 'ppclg-brand' },
            el( 'span', { class: 'ppclg-logo' }, logoSvg() ),
            el( 'div', {},
                el( 'div', { class: 'ppclg-brand-title' }, 'PPC Link Growth Engine' ),
                el( 'span', { class: 'ppclg-brand-sub' }, config.siteDomain ? `${ config.brand } · ${ config.siteDomain }` : config.brand ),
            ),
        ),
        // A submitter-only user has no automation settings or notifications to open (both are admin-only routes).
        el( 'div', { class: 'ppclg-header-actions' }, config.submitOnly ? null : pill, themeBtn, config.submitOnly ? null : bellBtn ),
    );

    // ----- Sub navigation ---------------------------------------------
    const nav = el( 'nav', { class: 'ppclg-subnav', 'aria-label': 'Link Growth sections' } );
    for ( const [ key, labelText ] of Object.entries( config.pages ) ) {
        nav.appendChild( el( 'a', {
            class: 'ppclg-subnav-link',
            href: config.pageUrls[ key ] || '#',
            'aria-current': key === config.page ? 'page' : null,
        }, icon( PAGE_ICONS[ key ] || 'chevronRight', 15 ), String( labelText ) ) );
    }

    const main = el( 'main', { class: 'ppclg-main', id: 'ppclg-main', tabindex: '-1' } );
    const footer = el( 'div', { class: 'ppclg-footer' },
        el( 'span', {}, `PPC Link Growth Engine${ config.version ? ' v' + config.version : '' }` ),
        el( 'span', {}, config.submitOnly
            ? 'You see the owner-task queue only. Submit each listing by hand, then mark it submitted.'
            : 'Compliance-first: nothing is submitted or emailed without the gates configured under Automation and Outreach.' ),
    );
    replace( mount, el( 'div', { class: 'ppclg-shell' }, header, nav, main, footer ) );

    // ----- Shell API --------------------------------------------------
    const shell = {
        setUnread( n ) {
            const count = Math.max( 0, Number( n ) || 0 );
            bellBadge.textContent = count > 99 ? '99+' : String( count );
            bellBadge.hidden = count === 0;
            bellBtn.setAttribute( 'aria-label', count > 0 ? `Notifications (${ fmtNumber( count ) } unread)` : 'Notifications' );
        },
        setAutomation( enabled ) {
            const on = Boolean( enabled );
            pill.classList.toggle( 'ppclg-pill--on', on );
            pill.classList.toggle( 'ppclg-pill--off', ! on );
            pillText.textContent = on ? 'Automation on' : 'Automation off';
        },
        openNotifications,
        refreshUnread: async () => {
            if ( config.submitOnly ) {
                return;
            }
            try {
                const res = await api.get( '/notifications', { per_page: 1 }, { silent: true } );
                if ( res && res.unread !== undefined ) {
                    shell.setUnread( res.unread );
                }
            } catch ( e ) {
                // Badge keeps its last value.
            }
        },
    };
    shell.setUnread( config.unreadNotifications );
    shell.setAutomation( config.automationEnabled );

    const ctx = {
        config,
        shell,
        api,
        params: parseHash(),
        pageUrl( page, params ) {
            return ( config.pageUrls[ page ] || '#' ) + buildHash( params );
        },
        navigate( page, params ) {
            const target = ctx.pageUrl( page, params );
            if ( page === config.page && isSameDocument( target ) ) {
                // Only the fragment would change: the browser would neither reload nor tell the page,
                // so re-render the current page with the new parameters ourselves (a history entry is
                // pushed so Back returns to the previous view).
                if ( window.history && window.history.pushState ) {
                    window.history.pushState( null, '', `${ window.location.pathname }${ window.location.search }${ buildHash( params ) }` );
                }
                rerender();
                return;
            }
            window.location.href = target;
        },
        setHash( params ) {
            const next = buildHash( params );
            if ( window.history && window.history.replaceState ) {
                window.history.replaceState( null, '', `${ window.location.pathname }${ window.location.search }${ next }` );
            } else {
                window.location.hash = next;
            }
            ctx.params = parseHash();
        },
    };

    // Same-document links built with ctx.pageUrl() (e.g. "Campaign #3" inside an Outreach drawer) and
    // Back/Forward between them only change the fragment, which neither reloads the page nor re-reads
    // ctx.params. Re-render the current page when the parameters really changed. setHash() keeps
    // ctx.params in step (replaceState fires no event anyway), and plain anchors such as WordPress's
    // "Skip to main content" (#wpbody-content) are not parameter fragments and are left alone.
    const onLocationChange = () => {
        if ( ! isParamHash( window.location.hash, window.location.href ) ) {
            return;
        }
        if ( buildHash( parseHash() ) === buildHash( ctx.params ) ) {
            return;
        }
        rerender();
    };
    window.addEventListener( 'hashchange', onLocationChange );
    window.addEventListener( 'popstate', onLocationChange );

    function rerender() {
        const target = window.location.hash;
        if ( typeof closeOverlays === 'function' ) {
            // Drawers of the old view close first; their onClose handlers may call setHash() with the
            // old view's state, so the fragment being navigated to is put back afterwards.
            closeOverlays();
        }
        if ( window.location.hash !== target && window.history && window.history.replaceState ) {
            window.history.replaceState( null, '', `${ window.location.pathname }${ window.location.search }${ target }` );
        }
        ctx.params = parseHash();
        if ( typeof main.focus === 'function' ) {
            main.focus( { preventScroll: true } );
        }
        loadPage( config.page );
    }

    // ----- Notifications drawer --------------------------------------
    let notificationsDrawer = null;
    async function openNotifications() {
        if ( config.submitOnly || ( notificationsDrawer && notificationsDrawer.isOpen() ) ) {
            return;
        }
        const body = el( 'div', {}, loading( 'Loading notifications…', true ) );
        const markAll = button( 'Mark all as read', { small: true, iconName: 'check', onClick: async () => {
            await api.post( '/notifications/read', {} );
            shell.setUnread( 0 );
            body.querySelectorAll( '.ppclg-feed-item--unread' ).forEach( ( n ) => n.classList.remove( 'ppclg-feed-item--unread' ) );
            toast( 'All notifications marked as read.', 'success' );
        } } );
        notificationsDrawer = drawer( 'Notifications', body, {
            width: 'narrow',
            subtitle: 'Alerts from monitoring, discovery, submissions and outreach.',
            actions: [ button( 'View logs', { small: true, kind: 'ghost', onClick: () => ctx.navigate( 'logs' ) } ), markAll ],
            onClose: () => {
                notificationsDrawer = null;
            },
        } );
        try {
            const res = await api.get( '/notifications', { per_page: 40 } );
            const { items } = api.listResult( res );
            if ( res && res.unread !== undefined ) {
                shell.setUnread( res.unread );
            }
            if ( items.length === 0 ) {
                replace( body, emptyState( { title: 'No notifications yet', message: 'Monitoring and discovery events will appear here.', iconName: 'bell' } ) );
                return;
            }
            replace( body, el( 'div', { class: 'ppclg-list' }, items.map( ( n ) => el( 'div', { class: [ 'ppclg-feed-item', Number( n.is_read ) === 0 ? 'ppclg-feed-item--unread' : '' ] },
                el( 'span', { class: [ 'ppclg-feed-dot', `ppclg-feed-dot--${ n.severity || 'info' }` ], title: String( n.severity || 'info' ) } ),
                el( 'div', { class: 'ppclg-grow' },
                    el( 'div', { class: 'ppclg-feed-title' }, String( n.title || n.type || 'Notification' ) ),
                    n.message ? el( 'div', { class: 'ppclg-feed-msg' }, String( n.message ) ) : null,
                    el( 'div', { class: 'ppclg-feed-time' }, timeEl( n.created_at ), ' · ', badge( String( n.type || 'info' ), 'neutral' ) ),
                ),
            ) ) ) );
        } catch ( e ) {
            replace( body, errorState( { title: 'Could not load notifications', message: e && e.message ? e.message : '' } ) );
        }
    }

    // ----- Page module ------------------------------------------------
    let loadSeq = 0;
    loadPage( config.page );

    async function loadPage( page ) {
        // A newer loadPage() (re-render after a fragment navigation) supersedes one still importing.
        loadSeq += 1;
        const seq = loadSeq;
        replace( main, loading( `Loading ${ config.pages[ page ] || page }…` ) );
        document.title = `${ config.pages[ page ] || page } – Link Growth`;
        let module;
        try {
            module = await PAGES[ page ]();
        } catch ( e ) {
            if ( seq !== loadSeq ) {
                return;
            }
            const detail = e && e.message ? String( e.message ).replace( /([^.!?])$/, '$1.' ) + ' ' : '';
            replace( main, errorState( { title: 'This page could not be loaded', message: `The script for "${ page }" failed to load. ${ detail }If this keeps happening after a plugin update, reload the page (hard refresh: Ctrl+Shift+R / Cmd+Shift+R).`, retry: () => loadPage( page ) } ) );
            return;
        }
        if ( seq !== loadSeq ) {
            return;
        }
        const render = module && ( module.default || module.render );
        if ( typeof render !== 'function' ) {
            replace( main, errorState( { title: 'This page is unavailable', message: 'The page module does not export a renderer.' } ) );
            return;
        }
        const root = el( 'div', { class: `ppclg-page ppclg-page-${ page }` } );
        replace( main, root );
        try {
            await render( root, ctx );
        } catch ( e ) {
            replace( root, errorState( { title: 'Something went wrong while rendering this page', message: e && e.message ? e.message : '', retry: () => loadPage( page ) } ) );
        }
    }
}

if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', start, { once: true } );
} else {
    start();
}
