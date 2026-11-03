/**
 * REST client for the ppclg/v1 namespace.
 *
 * Every request carries the REST nonce and same-origin credentials. Errors are
 * normalised into ApiError instances and surfaced through a toast unless the
 * caller passes { silent: true }.
 */

import { toast } from './ui.js';

const state = {
    restRoot: '',
    nonce: '',
    onError: null,
};

export class ApiError extends Error {
    constructor( message, { code = 'error', status = 0, data = null } = {} ) {
        super( message );
        this.name = 'ApiError';
        this.code = code;
        this.status = status;
        this.data = data;
    }
}

/**
 * @param {{restRoot?: string, nonce?: string}} config
 */
export function configure( config = {} ) {
    if ( typeof config.restRoot === 'string' && config.restRoot !== '' ) {
        state.restRoot = config.restRoot;
    }
    if ( typeof config.nonce === 'string' ) {
        state.nonce = config.nonce;
    }
    if ( state.restRoot === '' ) {
        state.restRoot = window.location.origin + '/wp-json/ppclg/v1';
    }
}

/** Optional hook invoked for every surfaced error (in addition to the toast). */
export function setErrorHandler( fn ) {
    state.onError = typeof fn === 'function' ? fn : null;
}

export function nonce() {
    return state.nonce;
}

export function restRoot() {
    return state.restRoot;
}

/**
 * Builds an absolute URL for a route path such as "/prospects/12", tolerating
 * both pretty permalinks and "?rest_route=" style roots.
 */
export function buildUrl( path, params ) {
    let url;
    try {
        url = new URL( state.restRoot, window.location.href );
    } catch ( e ) {
        url = new URL( window.location.origin + '/wp-json/ppclg/v1' );
    }
    const clean = '/' + String( path || '' ).replace( /^\/+/, '' );
    if ( url.searchParams.has( 'rest_route' ) ) {
        const base = url.searchParams.get( 'rest_route' ).replace( /\/+$/, '' );
        url.searchParams.set( 'rest_route', base + clean );
    } else {
        url.pathname = url.pathname.replace( /\/+$/, '' ) + clean;
    }
    if ( params && typeof params === 'object' ) {
        for ( const [ key, value ] of Object.entries( params ) ) {
            if ( value === undefined || value === null || value === '' || value === false ) {
                continue;
            }
            if ( Array.isArray( value ) ) {
                value.forEach( ( v ) => url.searchParams.append( key + '[]', String( v ) ) );
            } else {
                url.searchParams.set( key, String( value ) );
            }
        }
    }
    return url.toString();
}

/** URL for a file download route (nonce passed as a query argument). */
export function fileUrl( path, params = {} ) {
    return buildUrl( path, { ...params, _wpnonce: state.nonce } );
}

function friendlyMessage( status, code, message ) {
    if ( status === 403 && ( code === 'rest_cookie_invalid_nonce' || /nonce/i.test( message || '' ) ) ) {
        return 'Your session has expired. Reload the page and try again.';
    }
    if ( status === 401 || status === 403 ) {
        return message || 'You do not have permission to do that.';
    }
    if ( status === 404 && ( ! message || code === 'rest_no_route' ) ) {
        return 'That endpoint is not available. The plugin may need to be reactivated.';
    }
    if ( status === 429 ) {
        return message || 'Too many requests. Please wait a minute and try again.';
    }
    if ( status >= 500 ) {
        return message || 'The server could not complete the request. Check the plugin logs.';
    }
    return message || `Request failed (${ status })`;
}

function surface( error, silent ) {
    if ( ! silent ) {
        try {
            toast( error.message, 'error' );
        } catch ( e ) {
            // Toast layer unavailable (very early failure); nothing else to do.
        }
    }
    if ( state.onError ) {
        try {
            state.onError( error );
        } catch ( e ) {
            // Never let a handler break the request path.
        }
    }
    return error;
}

/**
 * @param {string} method
 * @param {string} path
 * @param {{params?: object, body?: any, silent?: boolean, signal?: AbortSignal}} options
 */
export async function request( method, path, options = {} ) {
    const { params, body, silent = false, signal } = options;
    const url = buildUrl( path, method === 'GET' ? params : undefined );
    const headers = {
        Accept: 'application/json',
        'X-WP-Nonce': state.nonce,
    };
    const init = { method, credentials: 'same-origin', headers, signal };
    if ( method !== 'GET' ) {
        headers[ 'Content-Type' ] = 'application/json; charset=utf-8';
        init.body = JSON.stringify( body === undefined ? {} : body );
    }

    let response;
    try {
        response = await fetch( url, init );
    } catch ( e ) {
        if ( e && e.name === 'AbortError' ) {
            throw e;
        }
        throw surface( new ApiError( 'Network error: the site could not be reached.', { code: 'network', status: 0 } ), silent );
    }

    const text = await response.text();
    let data = null;
    if ( text !== '' ) {
        try {
            data = JSON.parse( text );
        } catch ( e ) {
            data = null;
        }
    }

    if ( ! response.ok ) {
        const code = data && typeof data.code === 'string' ? data.code : 'http_' + response.status;
        const message = data && typeof data.message === 'string' ? data.message : '';
        throw surface( new ApiError( friendlyMessage( response.status, code, message ), {
            code,
            status: response.status,
            data: data && data.data !== undefined ? data.data : data,
        } ), silent );
    }

    if ( data === null && text !== '' ) {
        throw surface( new ApiError( 'The server returned an unreadable response.', { code: 'invalid_json', status: response.status } ), silent );
    }
    return data;
}

export function get( path, params = {}, options = {} ) {
    return request( 'GET', path, { ...options, params } );
}

export function post( path, body = {}, options = {} ) {
    return request( 'POST', path, { ...options, body } );
}

export function del( path, options = {} ) {
    return request( 'DELETE', path, options );
}

/**
 * Normalises a list response into { items, total, page, per_page, pages }.
 * Accepts either the paginated envelope or a bare array.
 */
export function listResult( data ) {
    if ( Array.isArray( data ) ) {
        return { items: data, total: data.length, page: 1, per_page: data.length, pages: 1 };
    }
    if ( data && typeof data === 'object' ) {
        const items = Array.isArray( data.items ) ? data.items : ( Array.isArray( data.data ) ? data.data : [] );
        const total = Number.isFinite( Number( data.total ) ) ? Number( data.total ) : items.length;
        const perPage = Number( data.per_page ) > 0 ? Number( data.per_page ) : Math.max( 1, items.length );
        return {
            items,
            total,
            page: Number( data.page ) > 0 ? Number( data.page ) : 1,
            per_page: perPage,
            pages: Number( data.pages ) > 0 ? Number( data.pages ) : Math.max( 1, Math.ceil( total / perPage ) ),
        };
    }
    return { items: [], total: 0, page: 1, per_page: 25, pages: 1 };
}

export default { configure, get, post, del, request, buildUrl, fileUrl, listResult, setErrorHandler, ApiError };
