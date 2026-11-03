/**
 * Application entry: reads the injected configuration, renders the shell
 * (header, sub-navigation, notification bell, automation pill) and lazy-loads
 * the page module named by #ppclg-app[data-page].
 */

import * as api from './api.js';
import { el, replace, icon, iconButton, toast, drawer, button, badge, timeEl, errorState, loading, emptyState, setLocale, fmtNumber } from './ui.js';

const PAGES = {
    overview: () => import( './pages/overview.js' ),
    prospects: () => import( './pages/prospects.js' ),
    submissions: () => import( './pages/submissions.js' ),
    outreach: () => import( './pages/outreach.js' ),
    backlinks: () => import( './pages/backlinks.js' ),
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

function normalizeConfig( raw, mount ) {
    const adminUrl = typeof raw.adminUrl === 'string' && raw.adminUrl !== '' ? raw.adminUrl : `${ window.location.origin }/wp-admin/`;
    const pages = raw.pages && typeof raw.pages === 'object' && Object.keys( raw.pages ).length > 0 ? raw.pages : DEFAULT_PAGES;
    const pageUrls = {};
    for ( const key of Object.keys( pages ) ) {
        if ( raw.pageUrls && typeof raw.pageUrls[ key ] === 'string' ) {
            pageUrls[ key ] = raw.pageUrls[ key ];
        } else {
            pageUrls[ key ] = `${ adminUrl.replace( /\/+$/, '' ) }/admin.php?page=${ key === 'overview' ? 'ppclg' : `ppclg-${ key }` }`;
        }
    }
    const requested = ( mount && mount.dataset.page ) || raw.page || 'overview';
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
        capabilities: raw.capabilities && typeof raw.capabilities === 'object' ? raw.capabilities : {},
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

function boot() {
    const mount = document.getElementById( 'ppclg-app' );
    if ( ! mount ) {
        return;
    }
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
        el( 'div', { class: 'ppclg-header-actions' }, pill, themeBtn, bellBtn ),
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
        el( 'span', {}, 'Compliance-first: nothing is submitted or emailed without the gates configured under Automation and Outreach.' ),
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
            window.location.href = ctx.pageUrl( page, params );
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

    // ----- Notifications drawer --------------------------------------
    let notificationsDrawer = null;
    async function openNotifications() {
        if ( notificationsDrawer && notificationsDrawer.isOpen() ) {
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
    loadPage( config.page );

    async function loadPage( page ) {
        replace( main, loading( `Loading ${ config.pages[ page ] || page }…` ) );
        document.title = `${ config.pages[ page ] || page } – Link Growth`;
        let module;
        try {
            module = await PAGES[ page ]();
        } catch ( e ) {
            replace( main, errorState( { title: 'This page could not be loaded', message: `The script for "${ page }" failed to load. ${ e && e.message ? e.message : '' }`, retry: () => loadPage( page ) } ) );
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
    document.addEventListener( 'DOMContentLoaded', boot, { once: true } );
} else {
    boot();
}
