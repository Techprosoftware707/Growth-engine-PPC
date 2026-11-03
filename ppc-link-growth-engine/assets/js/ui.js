/**
 * UI primitives and components for the Link Growth Engine admin app.
 *
 * All content is created through DOM APIs; strings coming from the server are
 * only ever assigned to textContent / attributes, never parsed as HTML.
 */

const SVG_NS = 'http://www.w3.org/2000/svg';

const locale = {
    tag: ( typeof navigator !== 'undefined' && navigator.language ) || 'en-CA',
    timeZone: undefined,
};

let uid = 0;

/** Unique DOM id (for aria relationships). */
export function nextId( prefix = 'ppclg' ) {
    uid += 1;
    return `${ prefix }-${ uid }`;
}

/**
 * Configures date/number formatting.
 *
 * @param {string} tag   WordPress locale such as "en_CA".
 * @param {string} timeZone IANA time zone; invalid values are ignored.
 */
export function setLocale( tag, timeZone ) {
    if ( typeof tag === 'string' && tag !== '' ) {
        locale.tag = tag.replace( /_/g, '-' );
    }
    if ( typeof timeZone === 'string' && timeZone !== '' ) {
        try {
            new Intl.DateTimeFormat( locale.tag, { timeZone } );
            locale.timeZone = timeZone;
        } catch ( e ) {
            locale.timeZone = undefined;
        }
    }
}

// ---------------------------------------------------------------------
// DOM helpers
// ---------------------------------------------------------------------

/**
 * Creates an element. Attributes: class/className, style (object or string),
 * dataset, on<Event> handlers, boolean properties, everything else as attributes.
 * Children: nodes, strings, numbers, arrays (nested); null/false/undefined skipped.
 */
export function el( tag, attrs, ...children ) {
    const node = document.createElement( tag );
    if ( attrs instanceof Node || typeof attrs === 'string' || typeof attrs === 'number' || Array.isArray( attrs ) ) {
        children.unshift( attrs );
        attrs = null;
    }
    if ( attrs && typeof attrs === 'object' ) {
        applyAttrs( node, attrs );
    }
    append( node, children );
    return node;
}

/** SVG element factory (namespace aware). */
export function svg( tag, attrs, ...children ) {
    const node = document.createElementNS( SVG_NS, tag );
    if ( attrs && typeof attrs === 'object' ) {
        for ( const [ key, value ] of Object.entries( attrs ) ) {
            if ( value === null || value === undefined || value === false ) {
                continue;
            }
            if ( key === 'class' || key === 'className' ) {
                node.setAttribute( 'class', Array.isArray( value ) ? value.filter( Boolean ).join( ' ' ) : String( value ) );
            } else if ( key === 'style' && typeof value === 'object' ) {
                Object.assign( node.style, value );
            } else if ( key.startsWith( 'on' ) && typeof value === 'function' ) {
                node.addEventListener( key.slice( 2 ).toLowerCase(), value );
            } else if ( key === 'text' ) {
                node.textContent = String( value );
            } else {
                node.setAttribute( key, String( value ) );
            }
        }
    }
    append( node, children );
    return node;
}

function applyAttrs( node, attrs ) {
    for ( const [ key, value ] of Object.entries( attrs ) ) {
        if ( value === null || value === undefined || value === false ) {
            continue;
        }
        if ( key === 'class' || key === 'className' ) {
            node.className = Array.isArray( value ) ? value.filter( Boolean ).join( ' ' ) : String( value );
        } else if ( key === 'style' ) {
            if ( typeof value === 'object' ) {
                Object.assign( node.style, value );
            } else {
                node.setAttribute( 'style', String( value ) );
            }
        } else if ( key === 'dataset' && typeof value === 'object' ) {
            for ( const [ dk, dv ] of Object.entries( value ) ) {
                if ( dv !== undefined && dv !== null ) {
                    node.dataset[ dk ] = String( dv );
                }
            }
        } else if ( key.startsWith( 'on' ) && typeof value === 'function' ) {
            node.addEventListener( key.slice( 2 ).toLowerCase(), value );
        } else if ( key === 'text' ) {
            node.textContent = String( value );
        } else if ( key === 'value' && 'value' in node ) {
            node.value = value === true ? '' : String( value );
        } else if ( [ 'checked', 'disabled', 'selected', 'readOnly', 'required', 'multiple', 'hidden', 'indeterminate' ].includes( key ) ) {
            node[ key ] = Boolean( value );
        } else if ( value === true ) {
            node.setAttribute( key, '' );
        } else {
            node.setAttribute( key, String( value ) );
        }
    }
}

/** Appends children (nested arrays allowed, falsy skipped, strings become text nodes). */
export function append( node, children ) {
    const list = Array.isArray( children ) ? children : [ children ];
    for ( const child of list ) {
        if ( child === null || child === undefined || child === false || child === '' ) {
            continue;
        }
        if ( Array.isArray( child ) ) {
            append( node, child );
        } else if ( child instanceof Node ) {
            node.appendChild( child );
        } else {
            node.appendChild( document.createTextNode( String( child ) ) );
        }
    }
    return node;
}

/** Removes every child of a node and optionally appends new content. */
export function replace( node, ...children ) {
    while ( node.firstChild ) {
        node.removeChild( node.firstChild );
    }
    append( node, children );
    return node;
}

export function clear( node ) {
    return replace( node );
}

export function text( value ) {
    return document.createTextNode( value === null || value === undefined ? '' : String( value ) );
}

export function fragment( ...children ) {
    const frag = document.createDocumentFragment();
    append( frag, children );
    return frag;
}

/** HTML-escapes a string (only needed when building attribute strings manually). */
export function escapeHtml( value ) {
    return String( value === null || value === undefined ? '' : value )
        .replace( /&/g, '&amp;' )
        .replace( /</g, '&lt;' )
        .replace( />/g, '&gt;' )
        .replace( /"/g, '&quot;' )
        .replace( /'/g, '&#039;' );
}

// ---------------------------------------------------------------------
// Icons (inline SVG, 24px grid, stroke based)
// ---------------------------------------------------------------------

const ICONS = {
    bell: 'M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0',
    sun: 'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10zM12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4',
    moon: 'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z',
    monitor: 'M3 4h18v12H3zM8 20h8M12 16v4',
    check: 'M20 6L9 17l-5-5',
    x: 'M18 6L6 18M6 6l12 12',
    search: 'M21 21l-4.3-4.3M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14z',
    external: 'M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3',
    refresh: 'M23 4v6h-6M1 20v-6h6M3.5 9a9 9 0 0 1 14.9-3.4L23 10M1 14l4.6 4.4A9 9 0 0 0 20.5 15',
    plus: 'M12 5v14M5 12h14',
    alert: 'M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01',
    info: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 16v-4M12 8h.01',
    chevronDown: 'M6 9l6 6 6-6',
    chevronLeft: 'M15 18l-6-6 6-6',
    chevronRight: 'M9 18l6-6-6-6',
    arrowDown: 'M12 5v14M19 12l-7 7-7-7',
    trash: 'M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6',
    copy: 'M20 9h-9a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2zM5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1',
    link: 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7',
    play: 'M5 3l14 9-14 9V3z',
    pause: 'M6 4h4v16H6zM14 4h4v16h-4z',
    edit: 'M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z',
    download: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3',
    inbox: 'M22 12h-6l-2 3h-4l-2-3H2M5.5 5.1L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1z',
    zap: 'M13 2L3 14h9l-1 8 10-12h-9l1-8z',
    settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
    list: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
    mail: 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM22 6l-10 7L2 6',
    shield: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
    eye: 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
    send: 'M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z',
    clock: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 6v6l4 2',
    globe: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z',
    users: 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8',
    target: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 18a6 6 0 1 0 0-12 6 6 0 0 0 0 12zM12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
    file: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M16 13H8M16 17H8M10 9H8',
    chart: 'M18 20V10M12 20V4M6 20v-6',
    activity: 'M22 12h-4l-3 9L9 3l-3 9H2',
    package: 'M16.5 9.4L7.5 4.2M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.7zM3.3 7L12 12l8.7-5M12 22V12',
    home: 'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM9 22V12h6v10',
    more: 'M12 13a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM19 13a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM5 13a1 1 0 1 0 0-2 1 1 0 0 0 0 2z',
    table: 'M3 3h18v18H3zM3 9h18M3 15h18M9 3v18',
    lock: 'M19 11H5a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2zM7 11V7a5 5 0 0 1 10 0v4',
    stop: 'M4 4h16v16H4z',
};

/** Inline stroke icon. Unknown names render an empty 16px box. */
export function icon( name, size = 16 ) {
    const node = svg( 'svg', {
        width: size,
        height: size,
        viewBox: '0 0 24 24',
        fill: 'none',
        stroke: 'currentColor',
        'stroke-width': 2,
        'stroke-linecap': 'round',
        'stroke-linejoin': 'round',
        'aria-hidden': 'true',
        focusable: 'false',
        class: 'ppclg-icon',
    } );
    const d = ICONS[ name ];
    if ( d ) {
        node.appendChild( svg( 'path', { d } ) );
    }
    return node;
}

// ---------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------

export function fmtNumber( value, options = {} ) {
    if ( value === null || value === undefined || value === '' || Number.isNaN( Number( value ) ) ) {
        return options.empty !== undefined ? options.empty : '—';
    }
    const n = Number( value );
    try {
        return new Intl.NumberFormat( locale.tag, { maximumFractionDigits: options.decimals !== undefined ? options.decimals : 0, minimumFractionDigits: options.minDecimals || 0 } ).format( n );
    } catch ( e ) {
        return String( Math.round( n ) );
    }
}

/** Compact number such as 1.2k / 3.4M. */
export function fmtCompact( value ) {
    if ( value === null || value === undefined || value === '' || Number.isNaN( Number( value ) ) ) {
        return '—';
    }
    const n = Number( value );
    try {
        return new Intl.NumberFormat( locale.tag, { notation: 'compact', maximumFractionDigits: 1 } ).format( n );
    } catch ( e ) {
        return fmtNumber( n );
    }
}

export function fmtPercent( value, decimals = 1 ) {
    if ( value === null || value === undefined || value === '' || Number.isNaN( Number( value ) ) ) {
        return '—';
    }
    const n = Number( value );
    return `${ fmtNumber( n, { decimals } ) }%`;
}

export function fmtBytes( bytes ) {
    const n = Number( bytes );
    if ( ! Number.isFinite( n ) || n < 0 ) {
        return '—';
    }
    const units = [ 'B', 'KB', 'MB', 'GB' ];
    let i = 0;
    let v = n;
    while ( v >= 1024 && i < units.length - 1 ) {
        v /= 1024;
        i += 1;
    }
    return `${ fmtNumber( v, { decimals: i === 0 ? 0 : 1 } ) } ${ units[ i ] }`;
}

/** Parses MySQL ("Y-m-d H:i:s", UTC), ISO-8601 or epoch values into a Date (null when invalid). */
export function parseDate( value ) {
    if ( value === null || value === undefined || value === '' || value === '0000-00-00 00:00:00' ) {
        return null;
    }
    if ( value instanceof Date ) {
        return Number.isNaN( value.getTime() ) ? null : value;
    }
    if ( typeof value === 'number' ) {
        const d = new Date( value < 1e12 ? value * 1000 : value );
        return Number.isNaN( d.getTime() ) ? null : d;
    }
    const str = String( value ).trim();
    let iso = str;
    if ( /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test( str ) ) {
        iso = str.replace( ' ', 'T' ) + 'Z';
    } else if ( /^\d{4}-\d{2}-\d{2}$/.test( str ) ) {
        iso = str + 'T00:00:00Z';
    } else if ( /^\d{10}$/.test( str ) ) {
        iso = Number( str ) * 1000;
    }
    const d = new Date( iso );
    return Number.isNaN( d.getTime() ) ? null : d;
}

/** Formats a date value; options: { time: bool, relative: bool, empty: string }. */
export function fmtDate( value, options = {} ) {
    const d = parseDate( value );
    if ( ! d ) {
        return options.empty !== undefined ? options.empty : '—';
    }
    if ( options.relative ) {
        return timeAgo( d );
    }
    const fmt = { year: 'numeric', month: 'short', day: 'numeric' };
    if ( options.time !== false ) {
        fmt.hour = '2-digit';
        fmt.minute = '2-digit';
    }
    if ( locale.timeZone ) {
        fmt.timeZone = locale.timeZone;
    }
    try {
        return new Intl.DateTimeFormat( locale.tag, fmt ).format( d );
    } catch ( e ) {
        return d.toISOString().slice( 0, 16 ).replace( 'T', ' ' );
    }
}

export function timeAgo( value ) {
    const d = parseDate( value );
    if ( ! d ) {
        return '—';
    }
    const diff = ( d.getTime() - Date.now() ) / 1000;
    const abs = Math.abs( diff );
    const units = [ [ 'year', 31536000 ], [ 'month', 2592000 ], [ 'week', 604800 ], [ 'day', 86400 ], [ 'hour', 3600 ], [ 'minute', 60 ], [ 'second', 1 ] ];
    for ( const [ unit, secs ] of units ) {
        if ( abs >= secs || unit === 'second' ) {
            const n = Math.round( diff / secs );
            if ( unit === 'second' && Math.abs( n ) < 10 ) {
                return 'just now';
            }
            try {
                return new Intl.RelativeTimeFormat( locale.tag, { numeric: 'auto' } ).format( n, unit );
            } catch ( e ) {
                return `${ Math.abs( n ) } ${ unit }${ Math.abs( n ) === 1 ? '' : 's' } ${ n < 0 ? 'ago' : 'from now' }`;
            }
        }
    }
    return '';
}

/** Both absolute and relative time as a <time> element. */
export function timeEl( value, { relative = true } = {} ) {
    const d = parseDate( value );
    if ( ! d ) {
        return el( 'span', { class: 'ppclg-muted' }, '—' );
    }
    return el( 'time', { datetime: d.toISOString(), title: fmtDate( d ) }, relative ? timeAgo( d ) : fmtDate( d ) );
}

export function fmtDuration( seconds ) {
    const s = Number( seconds );
    if ( ! Number.isFinite( s ) ) {
        return '—';
    }
    if ( s < 60 ) {
        return `${ Math.round( s ) }s`;
    }
    if ( s < 3600 ) {
        return `${ Math.round( s / 60 ) } min`;
    }
    if ( s < 86400 ) {
        return `${ fmtNumber( s / 3600, { decimals: 1 } ) } h`;
    }
    return `${ fmtNumber( s / 86400, { decimals: 1 } ) } d`;
}

/** snake_case / kebab-case → "Title case". */
export function labelize( value ) {
    const s = String( value === null || value === undefined ? '' : value ).replace( /[_\-]+/g, ' ' ).trim();
    if ( s === '' ) {
        return '';
    }
    return s.charAt( 0 ).toUpperCase() + s.slice( 1 );
}

export function truncate( value, max = 60 ) {
    const s = String( value === null || value === undefined ? '' : value );
    return s.length > max ? s.slice( 0, Math.max( 0, max - 1 ) ) + '…' : s;
}

export function debounce( fn, wait = 300 ) {
    let timer = null;
    const debounced = ( ...args ) => {
        clearTimeout( timer );
        timer = setTimeout( () => fn( ...args ), wait );
    };
    debounced.cancel = () => clearTimeout( timer );
    debounced.flush = ( ...args ) => {
        clearTimeout( timer );
        fn( ...args );
    };
    return debounced;
}

export function sleep( ms ) {
    return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}

export function isObject( value ) {
    return value !== null && typeof value === 'object' && ! Array.isArray( value );
}

export function deepClone( value ) {
    if ( value === undefined ) {
        return undefined;
    }
    try {
        return JSON.parse( JSON.stringify( value ) );
    } catch ( e ) {
        return value;
    }
}

export function deepEqual( a, b ) {
    try {
        return JSON.stringify( a ) === JSON.stringify( b );
    } catch ( e ) {
        return a === b;
    }
}

/** Reads a dot path from a nested object. */
export function getPath( obj, path, fallback ) {
    let cur = obj;
    for ( const part of String( path ).split( '.' ) ) {
        if ( cur === null || cur === undefined || typeof cur !== 'object' || ! ( part in cur ) ) {
            return fallback;
        }
        cur = cur[ part ];
    }
    return cur === undefined ? fallback : cur;
}

/** Writes a dot path into a nested object (creating intermediate objects). */
export function setPath( obj, path, value ) {
    const parts = String( path ).split( '.' );
    let cur = obj;
    for ( let i = 0; i < parts.length - 1; i++ ) {
        if ( ! isObject( cur[ parts[ i ] ] ) ) {
            cur[ parts[ i ] ] = {};
        }
        cur = cur[ parts[ i ] ];
    }
    cur[ parts[ parts.length - 1 ] ] = value;
    return obj;
}

// ---------------------------------------------------------------------
// Status vocabulary
// ---------------------------------------------------------------------

export const STATUS_KIND = {
    discovered: 'neutral', evaluating: 'info', qualified: 'brand', approved: 'success', in_progress: 'info', acquired: 'success', rejected: 'danger', on_hold: 'warning', suppressed: 'danger',
    open: 'info', packaged: 'brand', submitted: 'info', confirmed: 'success', dismissed: 'neutral', expired: 'neutral',
    pending_review: 'warning', needs_manual: 'warning', queued: 'info', failed: 'danger', duplicate: 'neutral',
    draft: 'neutral', scheduled: 'info', sent: 'success', replied: 'success', bounced: 'danger', opted_out: 'danger', closed: 'neutral',
    pending_approval: 'warning', sending: 'info', paused: 'warning', completed: 'success', archived: 'neutral',
    pending: 'neutral', active: 'success', lost: 'danger', broken: 'danger', redirected: 'warning', unverified: 'neutral',
    running: 'info', cancelled: 'neutral', invalid: 'danger',
    debug: 'neutral', info: 'info', warning: 'warning', error: 'danger', critical: 'danger', success: 'success', notice: 'info',
    exceptional: 'brand', high: 'success', good: 'success', moderate: 'warning', reject: 'danger',
    dofollow: 'success', nofollow: 'neutral', sponsored: 'warning', ugc: 'warning',
    manual: 'neutral', form: 'info', api: 'brand',
    yes: 'success', no: 'neutral', on: 'success', off: 'neutral', ok: 'success',
};

export function kindFor( status ) {
    return STATUS_KIND[ String( status || '' ).toLowerCase() ] || 'neutral';
}

export function scoreClass( score ) {
    const n = Number( score );
    if ( ! Number.isFinite( n ) ) {
        return 'unknown';
    }
    if ( n >= 90 ) {
        return 'exceptional';
    }
    if ( n >= 80 ) {
        return 'high';
    }
    if ( n >= 70 ) {
        return 'good';
    }
    if ( n >= 50 ) {
        return 'moderate';
    }
    return 'reject';
}

// ---------------------------------------------------------------------
// Basic components
// ---------------------------------------------------------------------

export function badge( label, kind = 'neutral', { dot = false, outline = false, title, iconName } = {} ) {
    return el( 'span', {
        class: [ 'ppclg-badge', kind && kind !== 'neutral' ? `ppclg-badge--${ kind }` : '', dot ? 'ppclg-badge--dot' : '', outline ? 'ppclg-badge--outline' : '' ],
        title: title || null,
    }, iconName ? icon( iconName, 12 ) : null, label );
}

export function statusBadge( status, { dot = true } = {} ) {
    const s = String( status === null || status === undefined ? '' : status );
    if ( s === '' ) {
        return el( 'span', { class: 'ppclg-muted' }, '—' );
    }
    return badge( labelize( s ), kindFor( s ), { dot } );
}

export function button( label, { kind = 'secondary', onClick, iconName, small = false, type = 'button', disabled = false, title, block = false, attrs = {} } = {} ) {
    const btn = el( 'button', {
        type,
        class: [ 'ppclg-btn', kind !== 'secondary' ? `ppclg-btn--${ kind }` : '', small ? 'ppclg-btn--small' : '', block ? 'ppclg-btn--block' : '' ],
        disabled,
        title: title || null,
        ...attrs,
    }, iconName ? icon( iconName, small ? 13 : 15 ) : null, label );
    if ( onClick ) {
        btn.addEventListener( 'click', async ( event ) => {
            if ( btn.disabled ) {
                return;
            }
            const result = onClick( event, btn );
            if ( result && typeof result.then === 'function' ) {
                setBusy( btn, true );
                try {
                    await result;
                } finally {
                    setBusy( btn, false );
                }
            }
        } );
    }
    return btn;
}

export function iconButton( iconName, label, { onClick, small = false, attrs = {} } = {} ) {
    const btn = el( 'button', { type: 'button', class: [ 'ppclg-iconbtn', small ? 'ppclg-iconbtn--small' : '' ], 'aria-label': label, title: label, ...attrs }, icon( iconName, small ? 14 : 18 ) );
    if ( onClick ) {
        btn.addEventListener( 'click', onClick );
    }
    return btn;
}

export function setBusy( btn, busy ) {
    if ( ! btn ) {
        return;
    }
    btn.classList.toggle( 'ppclg-btn--busy', Boolean( busy ) );
    btn.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
    btn.disabled = Boolean( busy );
}

export function linkButton( label, href, { kind = 'secondary', iconName, small = false, external = false } = {} ) {
    return el( 'a', {
        href,
        class: [ 'ppclg-btn', kind !== 'secondary' ? `ppclg-btn--${ kind }` : '', small ? 'ppclg-btn--small' : '' ],
        target: external ? '_blank' : null,
        rel: external ? 'noopener noreferrer' : null,
    }, iconName ? icon( iconName, 14 ) : null, label );
}

export function extLink( url, label, { className = '', maxLength = 0 } = {} ) {
    const href = String( url || '' );
    if ( href === '' ) {
        return el( 'span', { class: 'ppclg-muted' }, label || '—' );
    }
    const safeHref = /^https?:\/\//i.test( href ) ? href : '#';
    const display = label !== undefined && label !== null && label !== '' ? String( label ) : href.replace( /^https?:\/\//i, '' );
    return el( 'a', { href: safeHref, target: '_blank', rel: 'noopener noreferrer', class: [ 'ppclg-link-ext', className ], title: href }, maxLength > 0 ? truncate( display, maxLength ) : display, icon( 'external', 12 ) );
}

export function copyButton( value, label = 'Copy' ) {
    return el( 'button', {
        type: 'button',
        class: 'ppclg-copybtn',
        onClick: async () => {
            const str = typeof value === 'function' ? value() : String( value === null || value === undefined ? '' : value );
            try {
                await navigator.clipboard.writeText( str );
                toast( 'Copied to clipboard.', 'success', { timeout: 1800 } );
            } catch ( e ) {
                toast( 'Could not copy automatically; select the text and copy it manually.', 'warning' );
            }
        },
    }, icon( 'copy', 12 ), label );
}

export function spinner( small = false ) {
    return el( 'span', { class: [ 'ppclg-spinner', small ? 'ppclg-spinner--small' : '' ], role: 'status', 'aria-label': 'Loading' } );
}

export function loading( message = 'Loading…', compact = false ) {
    return el( 'div', { class: [ 'ppclg-loading', compact ? 'ppclg-loading--compact' : '' ], role: 'status', 'aria-live': 'polite' }, spinner(), el( 'span', {}, message ) );
}

export function skeletonRows( count = 5, widths = [ '60%', '40%', '80%', '30%' ] ) {
    const rows = [];
    for ( let i = 0; i < count; i++ ) {
        rows.push( el( 'div', { class: 'ppclg-row', style: { padding: '10px 14px', gap: '16px' } }, widths.map( ( w ) => el( 'span', { class: 'ppclg-skeleton', style: { width: w, flex: '0 1 auto' } } ) ) ) );
    }
    return el( 'div', { 'aria-hidden': 'true' }, rows );
}

export function emptyState( { title = 'Nothing here yet', message = '', action = null, iconName = 'inbox', compact = false } = {} ) {
    return el( 'div', { class: [ 'ppclg-empty', compact ? 'ppclg-empty--compact' : '' ] },
        icon( iconName, 36 ),
        el( 'div', { class: 'ppclg-empty-title' }, title ),
        message ? el( 'div', {}, message ) : null,
        action ? el( 'div', { style: { marginTop: '8px' } }, action ) : null,
    );
}

export function errorState( { title = 'Something went wrong', message = '', retry = null } = {} ) {
    return el( 'div', { class: 'ppclg-error', role: 'alert' },
        icon( 'alert', 36 ),
        el( 'div', { class: 'ppclg-error-title' }, title ),
        message ? el( 'div', {}, message ) : null,
        retry ? el( 'div', { style: { marginTop: '8px' } }, button( 'Try again', { onClick: retry, iconName: 'refresh' } ) ) : null,
    );
}

export function callout( kind, title, content, { actions = null, iconName } = {} ) {
    const icons = { info: 'info', warning: 'alert', danger: 'alert', success: 'check', neutral: 'info' };
    return el( 'div', { class: [ 'ppclg-callout', `ppclg-callout--${ kind }` ], role: kind === 'danger' || kind === 'warning' ? 'alert' : null },
        icon( iconName || icons[ kind ] || 'info', 18 ),
        el( 'div', { class: 'ppclg-grow' },
            title ? el( 'div', { class: 'ppclg-callout-title' }, title ) : null,
            content instanceof Node || Array.isArray( content ) ? content : ( content ? el( 'div', {}, content ) : null ),
            actions ? el( 'div', { class: 'ppclg-row', style: { marginTop: '8px' } }, actions ) : null,
        ),
    );
}

export function card( { title, subtitle, actions = null, body = null, footer = null, flat = false, flush = false, className = '' } = {} ) {
    const node = el( 'section', { class: [ 'ppclg-card', flat ? 'ppclg-card--flat' : '', className ] } );
    if ( title || actions ) {
        node.appendChild( el( 'header', { class: 'ppclg-card-head' },
            el( 'div', {}, el( 'h3', { class: 'ppclg-card-title' }, title ), subtitle ? el( 'div', { class: 'ppclg-card-sub' }, subtitle ) : null ),
            actions ? el( 'div', { class: 'ppclg-row' }, actions ) : null,
        ) );
    }
    node.appendChild( el( 'div', { class: [ 'ppclg-card-body', flush ? 'ppclg-card-body--flush' : '' ] }, body ) );
    if ( footer ) {
        node.appendChild( el( 'footer', { class: 'ppclg-card-foot' }, footer ) );
    }
    return node;
}

export function cardBody( node ) {
    return node.querySelector( '.ppclg-card-body' );
}

export function pageHeader( { title, description, actions = null } ) {
    return el( 'div', { class: 'ppclg-page-head' },
        el( 'div', {}, el( 'h2', { class: 'ppclg-page-title' }, title ), description ? el( 'p', { class: 'ppclg-page-desc' }, description ) : null ),
        actions ? el( 'div', { class: 'ppclg-page-actions' }, actions ) : null,
    );
}

/** Definition list from [[label, value], …] or an object. Empty values render as "—". */
export function kv( pairs, { compact = false } = {} ) {
    const list = Array.isArray( pairs ) ? pairs : Object.entries( pairs || {} );
    const dl = el( 'dl', { class: [ 'ppclg-kv', compact ? 'ppclg-kv--compact' : '' ] } );
    for ( const [ label, value ] of list ) {
        if ( value === undefined ) {
            continue;
        }
        const isEmpty = value === null || value === '' || ( Array.isArray( value ) && value.length === 0 );
        dl.appendChild( el( 'dt', {}, label ) );
        dl.appendChild( el( 'dd', {}, isEmpty ? el( 'span', { class: 'ppclg-muted' }, '—' ) : ( Array.isArray( value ) && ! ( value[ 0 ] instanceof Node ) ? value.join( ', ' ) : value ) ) );
    }
    return dl;
}

export function pre( value, { plain = false, maxLength = 20000 } = {} ) {
    let str;
    if ( typeof value === 'string' ) {
        str = value;
    } else {
        try {
            str = JSON.stringify( value, null, 2 );
        } catch ( e ) {
            str = String( value );
        }
    }
    if ( str === undefined ) {
        str = '';
    }
    return el( 'pre', { class: [ 'ppclg-pre', plain ? 'ppclg-pre--plain' : '' ] }, truncate( str, maxLength ) );
}

export function details( summary, content, { open = false } = {} ) {
    return el( 'details', { class: 'ppclg-details', open }, el( 'summary', {}, summary ), el( 'div', {}, content ) );
}

export function progressBar( value, max, { tone = '' } = {} ) {
    const m = Number( max );
    const v = Number( value );
    const pct = m > 0 && Number.isFinite( v ) ? Math.max( 0, Math.min( 100, ( v / m ) * 100 ) ) : 0;
    let cls = tone;
    if ( ! cls && m > 0 ) {
        cls = pct >= 100 ? 'critical' : ( pct >= 80 ? 'warning' : '' );
    }
    return el( 'div', { class: [ 'ppclg-progress', cls ? `ppclg-progress--${ cls }` : '' ], role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': m > 0 ? m : 100, 'aria-valuenow': Number.isFinite( v ) ? v : 0 },
        el( 'div', { class: 'ppclg-progress-bar', style: { width: `${ pct }%` } } ),
    );
}

/** Used/limit meter row (limit <= 0 means unlimited). */
export function meter( { label, used, limit, suffix = '' } ) {
    const unlimited = ! ( Number( limit ) > 0 );
    return el( 'div', { class: 'ppclg-meter' },
        el( 'span', { class: 'ppclg-meter-label' }, label ),
        unlimited ? progressBar( 0, 0, { tone: 'muted' } ) : progressBar( used, limit ),
        el( 'span', { class: 'ppclg-meter-value' }, unlimited ? `${ fmtNumber( used ) } / ∞${ suffix }` : `${ fmtNumber( used ) } / ${ fmtNumber( limit ) }${ suffix }` ),
    );
}

export function scoreRing( score, { size = 40, stroke = 4, showValue = true, label } = {} ) {
    const n = Number( score );
    const valid = Number.isFinite( n );
    const pct = valid ? Math.max( 0, Math.min( 100, n ) ) : 0;
    const r = ( size - stroke ) / 2;
    const c = 2 * Math.PI * r;
    const cls = scoreClass( valid ? n : null );
    const node = svg( 'svg', {
        class: [ 'ppclg-ring', `ppclg-ring--${ cls }` ],
        width: size,
        height: size,
        viewBox: `0 0 ${ size } ${ size }`,
        role: 'img',
        'aria-label': label || ( valid ? `Score ${ Math.round( n ) } of 100` : 'Not scored' ),
    },
        svg( 'circle', { class: 'ppclg-ring-track', cx: size / 2, cy: size / 2, r, fill: 'none', 'stroke-width': stroke } ),
        svg( 'circle', {
            class: 'ppclg-ring-bar',
            cx: size / 2,
            cy: size / 2,
            r,
            fill: 'none',
            'stroke-width': stroke,
            'stroke-linecap': 'round',
            'stroke-dasharray': c.toFixed( 2 ),
            'stroke-dashoffset': ( c * ( 1 - pct / 100 ) ).toFixed( 2 ),
            transform: `rotate(-90 ${ size / 2 } ${ size / 2 })`,
        } ),
    );
    if ( showValue ) {
        node.appendChild( svg( 'text', { class: 'ppclg-ring-text', x: '50%', y: '50%', 'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-size': Math.max( 9, Math.round( size * 0.32 ) ), text: valid ? String( Math.round( n ) ) : '–' } ) );
    }
    return node;
}

export function statTile( { label, value, delta, hint, iconName, tone, href, onClick, spark } = {} ) {
    const tag = href ? 'a' : 'div';
    const node = el( tag, { class: [ 'ppclg-tile', href || onClick ? 'ppclg-tile--link' : '' ], href: href || null, role: onClick ? 'button' : null, tabindex: onClick ? 0 : null },
        el( 'div', { class: 'ppclg-tile-label' }, iconName ? icon( iconName, 14 ) : null, label ),
        el( 'div', { class: 'ppclg-tile-row' },
            el( 'div', { class: [ 'ppclg-tile-value', String( value ?? '' ).length > 9 ? 'ppclg-tile-value--small' : '' ], style: tone ? { color: `var(--ppclg-${ tone })` } : null }, value === null || value === undefined || value === '' ? '—' : value ),
            deltaEl( delta ),
        ),
        hint ? el( 'div', { class: 'ppclg-tile-hint' }, hint ) : null,
        spark ? el( 'div', { class: 'ppclg-tile-spark' }, spark ) : null,
    );
    if ( onClick ) {
        node.addEventListener( 'click', onClick );
        node.addEventListener( 'keydown', ( e ) => {
            if ( e.key === 'Enter' || e.key === ' ' ) {
                e.preventDefault();
                onClick( e );
            }
        } );
    }
    return node;
}

function deltaEl( delta ) {
    if ( delta === null || delta === undefined || delta === '' ) {
        return null;
    }
    if ( typeof delta === 'string' || typeof delta === 'number' ) {
        return el( 'span', { class: 'ppclg-tile-delta' }, String( delta ) );
    }
    const dir = delta.direction || ( Number( delta.value ) > 0 ? 'up' : ( Number( delta.value ) < 0 ? 'down' : 'flat' ) );
    const good = delta.good === undefined ? dir === 'up' : delta.good;
    const cls = dir === 'flat' ? '' : ( good ? 'ppclg-tile-delta--good' : 'ppclg-tile-delta--bad' );
    const arrow = dir === 'up' ? '▲' : ( dir === 'down' ? '▼' : '' );
    return el( 'span', { class: [ 'ppclg-tile-delta', cls ], title: delta.title || null }, arrow ? el( 'span', { 'aria-hidden': 'true' }, arrow ) : null, delta.label || String( delta.value ) );
}

export function chipList( items, { onRemove, kind } = {} ) {
    const list = el( 'div', { class: 'ppclg-chip-list' } );
    ( items || [] ).forEach( ( item, index ) => {
        const label = typeof item === 'object' && item !== null ? ( item.label ?? item.value ?? '' ) : item;
        list.appendChild( el( 'span', { class: 'ppclg-chip' },
            kind ? badge( '', kind, { dot: true } ) : null,
            String( label ),
            onRemove ? el( 'button', { type: 'button', 'aria-label': `Remove ${ label }`, onClick: () => onRemove( item, index ) }, icon( 'x', 12 ) ) : null,
        ) );
    } );
    return list;
}

export function checklist( items, { storageKey = '' } = {} ) {
    const ul = el( 'ul', { class: 'ppclg-checklist' } );
    let done = {};
    if ( storageKey ) {
        try {
            done = JSON.parse( window.localStorage.getItem( storageKey ) || '{}' ) || {};
        } catch ( e ) {
            done = {};
        }
    }
    ( items || [] ).forEach( ( step, index ) => {
        const id = nextId( 'chk' );
        const li = el( 'li', { class: done[ index ] ? 'ppclg-done' : '' } );
        const input = el( 'input', { type: 'checkbox', id, checked: Boolean( done[ index ] ) } );
        input.addEventListener( 'change', () => {
            li.classList.toggle( 'ppclg-done', input.checked );
            done[ index ] = input.checked;
            if ( storageKey ) {
                try {
                    window.localStorage.setItem( storageKey, JSON.stringify( done ) );
                } catch ( e ) {
                    // Storage unavailable; the checklist still works for this view.
                }
            }
        } );
        li.appendChild( el( 'label', { class: 'ppclg-checkbox', for: id }, input, el( 'span', {}, String( step ) ) ) );
        ul.appendChild( li );
    } );
    return ul;
}

// ---------------------------------------------------------------------
// Toasts
// ---------------------------------------------------------------------

export function overlayHost() {
    return document.querySelector( '.ppclg-wrap' ) || document.body;
}

let toastHost = null;

export function toast( message, kind = 'info', { timeout = 4500, action = null } = {} ) {
    if ( ! toastHost || ! toastHost.isConnected ) {
        toastHost = el( 'div', { class: 'ppclg-toasts', 'aria-live': 'polite', 'aria-relevant': 'additions' } );
        overlayHost().appendChild( toastHost );
    }
    const icons = { info: 'info', success: 'check', warning: 'alert', error: 'alert' };
    const node = el( 'div', { class: [ 'ppclg-toast', `ppclg-toast--${ kind }` ], role: kind === 'error' ? 'alert' : 'status' },
        icon( icons[ kind ] || 'info', 16 ),
        el( 'div', { class: 'ppclg-grow' }, String( message ), action ? el( 'div', { style: { marginTop: '6px' } }, button( action.label, { small: true, kind: 'ghost', onClick: () => { action.onClick(); remove(); } } ) ) : null ),
        el( 'button', { type: 'button', class: 'ppclg-toast-close', 'aria-label': 'Dismiss', onClick: () => remove() }, icon( 'x', 14 ) ),
    );
    let timer = null;
    const remove = () => {
        clearTimeout( timer );
        if ( node.parentNode ) {
            node.parentNode.removeChild( node );
        }
    };
    toastHost.appendChild( node );
    while ( toastHost.children.length > 5 ) {
        toastHost.removeChild( toastHost.firstChild );
    }
    if ( timeout > 0 ) {
        timer = setTimeout( remove, kind === 'error' ? Math.max( timeout, 7000 ) : timeout );
    }
    return remove;
}

// ---------------------------------------------------------------------
// Overlays (drawer, modal) with focus management
// ---------------------------------------------------------------------

const overlayStack = [];

function focusable( container ) {
    return Array.from( container.querySelectorAll( 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])' ) )
        .filter( ( n ) => n.offsetParent !== null || n === document.activeElement );
}

function pushOverlay( entry ) {
    overlayStack.push( entry );
    if ( overlayStack.length === 1 ) {
        document.addEventListener( 'keydown', overlayKeydown, true );
        document.body.dataset.ppclgOverflow = document.body.style.overflow || '';
        document.body.style.overflow = 'hidden';
    }
}

function popOverlay( entry ) {
    const i = overlayStack.indexOf( entry );
    if ( i >= 0 ) {
        overlayStack.splice( i, 1 );
    }
    if ( overlayStack.length === 0 ) {
        document.removeEventListener( 'keydown', overlayKeydown, true );
        document.body.style.overflow = document.body.dataset.ppclgOverflow || '';
        delete document.body.dataset.ppclgOverflow;
    }
}

function overlayKeydown( event ) {
    const top = overlayStack[ overlayStack.length - 1 ];
    if ( ! top ) {
        return;
    }
    if ( event.key === 'Escape' ) {
        if ( top.dismissible ) {
            event.preventDefault();
            event.stopPropagation();
            top.close();
        }
        return;
    }
    if ( event.key === 'Tab' ) {
        const nodes = focusable( top.panel );
        if ( nodes.length === 0 ) {
            event.preventDefault();
            top.panel.focus();
            return;
        }
        const first = nodes[ 0 ];
        const last = nodes[ nodes.length - 1 ];
        if ( event.shiftKey && ( document.activeElement === first || ! top.panel.contains( document.activeElement ) ) ) {
            event.preventDefault();
            last.focus();
        } else if ( ! event.shiftKey && document.activeElement === last ) {
            event.preventDefault();
            first.focus();
        }
    }
}

/**
 * Right-side slide-over.
 *
 * @returns {{el: HTMLElement, body: HTMLElement, close: Function, setTitle: Function, setSubtitle: Function, setContent: Function, setActions: Function, isOpen: Function}}
 */
export function drawer( title, content, { subtitle = null, width = '', onClose = null, actions = null, dismissible = true } = {} ) {
    const titleId = nextId( 'drawer-title' );
    const previouslyFocused = document.activeElement;
    const titleEl = el( 'h2', { class: 'ppclg-drawer-title', id: titleId }, title );
    const subEl = el( 'div', { class: 'ppclg-drawer-sub' }, subtitle );
    const body = el( 'div', { class: 'ppclg-drawer-body' }, content );
    const foot = el( 'div', { class: 'ppclg-drawer-foot', hidden: ! actions }, actions );
    const panel = el( 'div', { class: [ 'ppclg-drawer', width ? `ppclg-drawer--${ width }` : '' ], role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId, tabindex: '-1' },
        el( 'div', { class: 'ppclg-drawer-head' },
            el( 'div', { class: 'ppclg-grow' }, titleEl, subEl ),
            iconButton( 'x', 'Close', { onClick: () => close() } ),
        ),
        body,
        foot,
    );
    const backdrop = el( 'div', { class: 'ppclg-backdrop', onClick: () => dismissible && close() } );
    const root = el( 'div', { class: 'ppclg-drawer-root' }, backdrop, panel );
    let open = true;
    const entry = { panel, dismissible, close: () => close() };

    function close() {
        if ( ! open ) {
            return;
        }
        open = false;
        popOverlay( entry );
        if ( root.parentNode ) {
            root.parentNode.removeChild( root );
        }
        if ( onClose ) {
            try {
                onClose();
            } catch ( e ) {
                // Ignore listener failures on close.
            }
        }
        if ( previouslyFocused && typeof previouslyFocused.focus === 'function' && previouslyFocused.isConnected ) {
            previouslyFocused.focus();
        }
    }

    overlayHost().appendChild( root );
    pushOverlay( entry );
    requestAnimationFrame( () => {
        const first = focusable( body )[ 0 ];
        ( first || panel ).focus();
    } );

    return {
        el: root,
        body,
        close,
        isOpen: () => open,
        setTitle: ( t ) => replace( titleEl, t ),
        setSubtitle: ( s ) => replace( subEl, s ),
        setContent: ( c ) => {
            replace( body, c );
            body.scrollTop = 0;
        },
        setActions: ( a ) => {
            replace( foot, a );
            foot.hidden = ! a || ( Array.isArray( a ) && a.filter( Boolean ).length === 0 );
        },
    };
}

/**
 * Centered modal dialog. `actions` entries: { label, kind, onClick(close) } — async handlers show a busy state.
 */
export function modal( { title, content, actions = [], width = '', onClose = null, dismissible = true } = {} ) {
    const titleId = nextId( 'modal-title' );
    const previouslyFocused = document.activeElement;
    const body = el( 'div', { class: 'ppclg-modal-body' }, content );
    const foot = el( 'div', { class: 'ppclg-modal-foot' } );
    const panel = el( 'div', { class: [ 'ppclg-modal', width ? `ppclg-modal--${ width }` : '' ], role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId, tabindex: '-1' },
        el( 'div', { class: 'ppclg-modal-head' },
            el( 'h2', { class: 'ppclg-modal-title', id: titleId }, title ),
            dismissible ? iconButton( 'x', 'Close', { small: true, onClick: () => close() } ) : null,
        ),
        body,
        foot,
    );
    const backdrop = el( 'div', { class: 'ppclg-backdrop', onClick: () => dismissible && close() } );
    const root = el( 'div', { class: 'ppclg-modal-root' }, backdrop, panel );
    let open = true;
    const entry = { panel, dismissible, close: () => close() };

    function close( result ) {
        if ( ! open ) {
            return;
        }
        open = false;
        popOverlay( entry );
        if ( root.parentNode ) {
            root.parentNode.removeChild( root );
        }
        if ( onClose ) {
            try {
                onClose( result );
            } catch ( e ) {
                // Ignore listener failures on close.
            }
        }
        if ( previouslyFocused && typeof previouslyFocused.focus === 'function' && previouslyFocused.isConnected ) {
            previouslyFocused.focus();
        }
    }

    const setActions = ( list ) => {
        replace( foot, ( list || [] ).map( ( a ) => button( a.label, {
            kind: a.kind || 'secondary',
            iconName: a.iconName,
            attrs: a.autofocus ? { 'data-autofocus': '1' } : {},
            onClick: () => a.onClick ? a.onClick( close, panel ) : close(),
        } ) ) );
        foot.hidden = ! list || list.length === 0;
    };
    setActions( actions );

    overlayHost().appendChild( root );
    pushOverlay( entry );
    requestAnimationFrame( () => {
        const preferred = panel.querySelector( '[data-autofocus]' ) || focusable( body )[ 0 ] || focusable( foot )[ 0 ];
        ( preferred || panel ).focus();
    } );

    return { el: root, body, panel, close, setActions, isOpen: () => open };
}

export function confirm( message, { title = 'Please confirm', confirmText = 'Confirm', cancelText = 'Cancel', danger = false, details = null } = {} ) {
    return new Promise( ( resolve ) => {
        let settled = false;
        const done = ( value ) => {
            if ( ! settled ) {
                settled = true;
                resolve( value );
            }
        };
        modal( {
            title,
            content: [ el( 'p', {}, message ), details ],
            onClose: () => done( false ),
            actions: [
                { label: cancelText, onClick: ( close ) => { done( false ); close(); } },
                { label: confirmText, kind: danger ? 'danger' : 'primary', autofocus: ! danger, onClick: ( close ) => { done( true ); close(); } },
            ],
        } );
    } );
}

export function prompt( message, { title = 'Input required', label = '', placeholder = '', multiline = false, required = true, defaultValue = '', confirmText = 'Continue', cancelText = 'Cancel', danger = false } = {} ) {
    return new Promise( ( resolve ) => {
        let settled = false;
        const done = ( value ) => {
            if ( ! settled ) {
                settled = true;
                resolve( value );
            }
        };
        const field = formField( { type: multiline ? 'textarea' : 'text', label, placeholder, value: defaultValue, rows: 4, required } );
        const dialog = modal( {
            title,
            content: [ message ? el( 'p', {}, message ) : null, field.el ],
            onClose: () => done( null ),
            actions: [
                { label: cancelText, onClick: ( close ) => { done( null ); close(); } },
                { label: confirmText, kind: danger ? 'danger' : 'primary', onClick: ( close ) => {
                    const value = String( field.get() || '' ).trim();
                    if ( required && value === '' ) {
                        field.setError( 'This field is required.' );
                        field.input.focus();
                        return;
                    }
                    done( value );
                    close();
                } },
            ],
        } );
        field.input.addEventListener( 'keydown', ( e ) => {
            if ( e.key === 'Enter' && ! multiline ) {
                e.preventDefault();
                const value = String( field.get() || '' ).trim();
                if ( required && value === '' ) {
                    field.setError( 'This field is required.' );
                    return;
                }
                done( value );
                dialog.close();
            }
        } );
    } );
}

/**
 * Modal built from formField specs. Resolves with onSubmit's return value, or null when cancelled.
 * onSubmit(values, helpers{ setError(message), close }) may throw to keep the dialog open with the error shown.
 */
export function formModal( { title, description = null, fields = [], submitText = 'Save', cancelText = 'Cancel', danger = false, width = '', onSubmit } ) {
    return new Promise( ( resolve ) => {
        let settled = false;
        const done = ( value ) => {
            if ( ! settled ) {
                settled = true;
                resolve( value );
            }
        };
        const built = fields.map( ( spec ) => formField( spec ) );
        const errorBox = el( 'div', { hidden: true } );
        const grid = el( 'div', { class: 'ppclg-stack' }, built.map( ( f ) => f.el ) );
        const dialog = modal( {
            title,
            width,
            content: [ description ? ( description instanceof Node ? description : el( 'p', {}, description ) ) : null, errorBox, grid ],
            onClose: () => done( null ),
            actions: [
                { label: cancelText, onClick: ( close ) => { done( null ); close(); } },
                { label: submitText, kind: danger ? 'danger' : 'primary', onClick: async ( close ) => {
                    errorBox.hidden = true;
                    let valid = true;
                    const values = {};
                    for ( const f of built ) {
                        f.setError( '' );
                        const value = f.get();
                        if ( f.spec.required && ( value === '' || value === null || value === undefined || ( Array.isArray( value ) && value.length === 0 ) ) ) {
                            f.setError( 'This field is required.' );
                            valid = false;
                        }
                        if ( f.spec.type === 'json' && ! f.valid() ) {
                            valid = false;
                        }
                        if ( f.spec.name ) {
                            values[ f.spec.name ] = value;
                        }
                    }
                    if ( ! valid ) {
                        return;
                    }
                    try {
                        const result = onSubmit ? await onSubmit( values, { setError: ( m ) => showError( m ), close } ) : values;
                        if ( result === false ) {
                            return;
                        }
                        done( result === undefined ? values : result );
                        close();
                    } catch ( e ) {
                        showError( e && e.message ? e.message : 'The request failed.' );
                    }
                } },
            ],
        } );
        function showError( message ) {
            replace( errorBox, callout( 'danger', null, message ) );
            errorBox.hidden = false;
        }
        const firstInput = built[ 0 ] && built[ 0 ].input;
        if ( firstInput && typeof firstInput.focus === 'function' ) {
            requestAnimationFrame( () => firstInput.focus() );
        }
        return dialog;
    } );
}

// ---------------------------------------------------------------------
// Forms
// ---------------------------------------------------------------------

function normalizeOptions( options ) {
    if ( ! options ) {
        return [];
    }
    if ( Array.isArray( options ) ) {
        return options.map( ( o ) => ( typeof o === 'object' && o !== null ? { value: String( o.value ), label: String( o.label ?? o.value ), description: o.description || '' } : { value: String( o ), label: labelize( o ) } ) );
    }
    return Object.entries( options ).map( ( [ value, label ] ) => ( { value: String( value ), label: String( label ) } ) );
}

/**
 * Form field factory.
 * Types: text, email, url, password, number, textarea, select, checkbox, toggle, list (one item per line), json, hidden.
 *
 * @returns {{el: HTMLElement, input: HTMLElement, get: Function, set: Function, setError: Function, valid: Function, spec: object, name: string}}
 */
export function formField( spec = {} ) {
    const {
        type = 'text', name = '', label = '', value = '', options = null, hint = '', placeholder = '', required = false,
        rows = 4, min, max, step, disabled = false, mono = false, onChange = null, inline = false, autocomplete, maxlength, full = false, small = false,
    } = spec;
    const id = spec.id || nextId( 'field' );
    const hintId = hint ? `${ id }-hint` : null;
    const errorEl = el( 'div', { class: 'ppclg-field-error', id: `${ id }-error`, hidden: true, role: 'alert' } );
    const hintEl = hint ? el( 'div', { class: 'ppclg-field-hint', id: hintId }, hint ) : null;
    let input;
    let wrapper;
    let get;
    let set;

    const labelNode = label ? el( 'label', { class: 'ppclg-field-label', for: id }, label, required ? el( 'span', { class: 'ppclg-field-required', 'aria-hidden': 'true' }, '*' ) : null ) : null;
    const base = { id, name: name || null, disabled, 'aria-describedby': hintId, placeholder: placeholder || null, autocomplete: autocomplete || null, maxlength: maxlength || null };

    switch ( type ) {
        case 'textarea':
        case 'list':
        case 'json': {
            input = el( 'textarea', { ...base, class: [ 'ppclg-textarea', ( mono || type === 'json' ) ? 'ppclg-textarea--mono' : '' ], rows } );
            if ( type === 'list' ) {
                set = ( v ) => { input.value = Array.isArray( v ) ? v.map( String ).join( '\n' ) : String( v ?? '' ); };
                get = () => input.value.split( /\r?\n/ ).map( ( s ) => s.trim() ).filter( ( s ) => s !== '' );
            } else if ( type === 'json' ) {
                set = ( v ) => {
                    if ( typeof v === 'string' ) {
                        input.value = v;
                    } else if ( v === null || v === undefined ) {
                        input.value = '';
                    } else {
                        try {
                            input.value = JSON.stringify( v, null, 2 );
                        } catch ( e ) {
                            input.value = '';
                        }
                    }
                };
                get = () => {
                    const raw = input.value.trim();
                    if ( raw === '' ) {
                        return null;
                    }
                    try {
                        return JSON.parse( raw );
                    } catch ( e ) {
                        return undefined;
                    }
                };
            } else {
                set = ( v ) => { input.value = v === null || v === undefined ? '' : String( v ); };
                get = () => input.value;
            }
            break;
        }
        case 'select': {
            input = el( 'select', { ...base, class: 'ppclg-select' } );
            for ( const opt of normalizeOptions( options ) ) {
                input.appendChild( el( 'option', { value: opt.value }, opt.label ) );
            }
            set = ( v ) => { input.value = v === null || v === undefined ? '' : String( v ); };
            get = () => input.value;
            break;
        }
        case 'checkbox': {
            input = el( 'input', { ...base, type: 'checkbox' } );
            set = ( v ) => { input.checked = Boolean( v ); };
            get = () => input.checked;
            break;
        }
        case 'toggle': {
            input = el( 'button', { id, type: 'button', class: 'ppclg-toggle', role: 'switch', 'aria-checked': 'false', 'aria-describedby': hintId, disabled, 'aria-label': label || name || 'Toggle' } );
            set = ( v ) => { input.setAttribute( 'aria-checked', v ? 'true' : 'false' ); };
            get = () => input.getAttribute( 'aria-checked' ) === 'true';
            input.addEventListener( 'click', () => {
                if ( input.disabled ) {
                    return;
                }
                set( ! get() );
                input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
            } );
            break;
        }
        case 'number': {
            input = el( 'input', { ...base, type: 'number', class: [ 'ppclg-input', small ? 'ppclg-input--small' : '' ], min: min ?? null, max: max ?? null, step: step ?? null, inputmode: 'numeric' } );
            set = ( v ) => { input.value = v === null || v === undefined || v === '' ? '' : String( v ); };
            get = () => {
                const raw = input.value.trim();
                if ( raw === '' ) {
                    return null;
                }
                const n = Number( raw );
                return Number.isFinite( n ) ? n : null;
            };
            break;
        }
        case 'hidden': {
            input = el( 'input', { ...base, type: 'hidden' } );
            set = ( v ) => { input.value = v === null || v === undefined ? '' : String( v ); };
            get = () => input.value;
            break;
        }
        default: {
            input = el( 'input', { ...base, type: [ 'email', 'url', 'password', 'search', 'tel', 'date' ].includes( type ) ? type : 'text', class: [ 'ppclg-input', mono ? 'ppclg-textarea--mono' : '', small ? 'ppclg-input--small' : '' ] } );
            set = ( v ) => { input.value = v === null || v === undefined ? '' : String( v ); };
            get = () => input.value;
        }
    }

    set( value );

    if ( type === 'checkbox' ) {
        wrapper = el( 'div', { class: [ 'ppclg-field', full ? 'ppclg-field--full' : '' ] },
            el( 'label', { class: 'ppclg-checkbox', for: id }, input, el( 'span', {}, label ) ),
            hintEl, errorEl,
        );
    } else if ( type === 'toggle' ) {
        wrapper = el( 'div', { class: [ 'ppclg-switch-row', full ? 'ppclg-field--full' : '' ] },
            el( 'div', { class: 'ppclg-grow' }, el( 'div', { class: 'ppclg-switch-row-title' }, el( 'label', { for: id }, label ) ), hintEl ? el( 'div', { class: 'ppclg-switch-row-desc' }, hintEl ) : null, errorEl ),
            input,
        );
    } else if ( type === 'hidden' ) {
        wrapper = input;
    } else {
        wrapper = el( 'div', { class: [ 'ppclg-field', inline ? 'ppclg-field--inline' : '', full ? 'ppclg-field--full' : '' ] }, labelNode, input, hintEl, errorEl );
    }

    const setError = ( message ) => {
        if ( message ) {
            replace( errorEl, message );
            errorEl.hidden = false;
            if ( input.setAttribute ) {
                input.setAttribute( 'aria-invalid', 'true' );
            }
        } else {
            errorEl.hidden = true;
            if ( input.removeAttribute ) {
                input.removeAttribute( 'aria-invalid' );
            }
        }
    };

    const valid = () => {
        if ( type === 'json' ) {
            const ok = get() !== undefined;
            setError( ok ? '' : 'Invalid JSON.' );
            return ok;
        }
        if ( type === 'email' && input.value.trim() !== '' && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( input.value.trim() ) ) {
            setError( 'Enter a valid email address.' );
            return false;
        }
        if ( type === 'url' && input.value.trim() !== '' && ! /^https?:\/\/\S+/i.test( input.value.trim() ) ) {
            setError( 'Enter a full URL starting with http:// or https://.' );
            return false;
        }
        setError( '' );
        return true;
    };

    if ( onChange ) {
        input.addEventListener( 'change', () => onChange( get(), input ) );
        if ( ! [ 'select', 'checkbox', 'toggle' ].includes( type ) ) {
            input.addEventListener( 'input', () => onChange( get(), input ) );
        }
    }

    return { el: wrapper, input, get, set, setError, valid, spec, name };
}

/** Collects { name: value } from an array of formField results. */
export function collect( fields ) {
    const out = {};
    for ( const f of fields ) {
        if ( f && f.name ) {
            out[ f.name ] = f.get();
        }
    }
    return out;
}

/** Row with label/description on the left and a toggle on the right (bound to a getter/setter). */
export function switchRow( { label, description, checked = false, onChange, disabled = false, badgeNode = null } ) {
    const id = nextId( 'switch' );
    const toggle = el( 'button', { id, type: 'button', class: 'ppclg-toggle', role: 'switch', 'aria-checked': checked ? 'true' : 'false', disabled, 'aria-label': label } );
    toggle.addEventListener( 'click', async () => {
        if ( toggle.disabled ) {
            return;
        }
        const next = toggle.getAttribute( 'aria-checked' ) !== 'true';
        if ( onChange ) {
            toggle.disabled = true;
            try {
                const result = await onChange( next, toggle );
                if ( result !== false ) {
                    toggle.setAttribute( 'aria-checked', next ? 'true' : 'false' );
                }
            } finally {
                toggle.disabled = disabled;
            }
        } else {
            toggle.setAttribute( 'aria-checked', next ? 'true' : 'false' );
        }
    } );
    const row = el( 'div', { class: 'ppclg-switch-row' },
        el( 'div', { class: 'ppclg-grow' },
            el( 'div', { class: 'ppclg-switch-row-title' }, el( 'label', { for: id }, label ), badgeNode ),
            description ? el( 'div', { class: 'ppclg-switch-row-desc' }, description ) : null,
        ),
        toggle,
    );
    row.toggle = toggle;
    return row;
}

// ---------------------------------------------------------------------
// Filter bar
// ---------------------------------------------------------------------

/**
 * @param {{fields: Array, values?: object, onChange: Function, onReset?: Function, actions?: Array, debounceMs?: number}} spec
 * fields: { name, label, type: 'select'|'search'|'text'|'number', options, placeholder, width, min, max }
 */
export function filterBar( { fields = [], values = {}, onChange, onReset = null, actions = null, debounceMs = 350 } ) {
    const state = { ...values };
    const inputs = {};
    const emit = ( name ) => onChange && onChange( { ...state }, name );
    const emitDebounced = debounce( emit, debounceMs );
    const bar = el( 'div', { class: 'ppclg-filterbar', role: 'search' } );

    for ( const f of fields ) {
        const id = nextId( 'filter' );
        let input;
        const current = state[ f.name ] ?? '';
        if ( f.type === 'select' ) {
            input = el( 'select', { id, class: 'ppclg-select', 'aria-label': f.label } );
            const opts = normalizeOptions( f.options );
            if ( ! opts.some( ( o ) => o.value === '' ) ) {
                input.appendChild( el( 'option', { value: '' }, f.allLabel || `All ${ String( f.label || '' ).toLowerCase() }` ) );
            }
            for ( const opt of opts ) {
                input.appendChild( el( 'option', { value: opt.value }, opt.label ) );
            }
            input.value = String( current );
            if ( input.value !== String( current ) ) {
                input.value = '';
            }
            input.addEventListener( 'change', () => {
                state[ f.name ] = input.value;
                emit( f.name );
            } );
        } else {
            input = el( 'input', { id, type: f.type === 'number' ? 'number' : 'search', class: 'ppclg-input', placeholder: f.placeholder || '', value: current, min: f.min ?? null, max: f.max ?? null, 'aria-label': f.label } );
            input.addEventListener( 'input', () => {
                state[ f.name ] = input.value;
                emitDebounced( f.name );
            } );
            input.addEventListener( 'keydown', ( e ) => {
                if ( e.key === 'Enter' ) {
                    e.preventDefault();
                    state[ f.name ] = input.value;
                    emitDebounced.flush( f.name );
                }
            } );
        }
        inputs[ f.name ] = input;
        const control = f.type === 'search' ? el( 'div', { class: 'ppclg-search' }, icon( 'search', 14 ), input ) : input;
        bar.appendChild( el( 'div', { class: [ 'ppclg-field', f.type === 'search' ? 'ppclg-field--search' : '' ], style: f.width ? { minWidth: f.width, flexBasis: f.width } : null },
            el( 'label', { class: 'ppclg-field-label', for: id }, f.label ),
            control,
        ) );
    }

    const resetBtn = button( 'Reset', { kind: 'ghost', small: true, iconName: 'x', onClick: () => {
        for ( const f of fields ) {
            state[ f.name ] = '';
            inputs[ f.name ].value = '';
        }
        emitDebounced.cancel();
        if ( onReset ) {
            onReset( { ...state } );
        } else {
            emit( null );
        }
    } } );
    bar.appendChild( el( 'div', { class: 'ppclg-filterbar-actions' }, resetBtn, actions ) );

    return {
        el: bar,
        get: () => ( { ...state } ),
        set: ( next ) => {
            for ( const [ k, v ] of Object.entries( next || {} ) ) {
                state[ k ] = v;
                if ( inputs[ k ] ) {
                    inputs[ k ].value = v ?? '';
                }
            }
        },
    };
}

// ---------------------------------------------------------------------
// Tables & pagination
// ---------------------------------------------------------------------

/**
 * @param {{columns: Array, rows: Array, onRowClick?: Function, sortable?: boolean, sort?: {key: string, dir: string}, onSort?: Function,
 *          empty?: any, loading?: boolean, rowKey?: Function, rowAttrs?: Function, dense?: boolean, plain?: boolean, expandable?: Function, caption?: string}} spec
 * Column: { key, label, render(row, index), sortable, align: 'right'|'center', width, class, hideLabel }
 */
export function dataTable( { columns = [], rows = [], onRowClick = null, sortable = false, sort = null, onSort = null, empty = null, loading = false, rowKey = null, rowAttrs = null, dense = false, plain = false, expandable = null, caption = '' } ) {
    const wrap = el( 'div', { class: [ 'ppclg-table-wrap', plain ? 'ppclg-table-wrap--plain' : '' ] } );
    const table = el( 'table', { class: [ 'ppclg-table', dense ? 'ppclg-table--dense' : '' ] } );
    if ( caption ) {
        table.appendChild( el( 'caption', { class: 'ppclg-sr-only' }, caption ) );
    }
    const thead = el( 'thead' );
    const headRow = el( 'tr' );
    for ( const col of columns ) {
        const th = el( 'th', { scope: 'col', class: [ col.align === 'right' ? 'ppclg-right' : '', col.align === 'center' ? 'ppclg-center' : '', col.class || '' ], style: col.width ? { width: col.width } : null } );
        const canSort = ( sortable || col.sortable ) && col.sortable !== false && onSort;
        if ( canSort ) {
            const active = sort && sort.key === col.key;
            const dir = active ? ( String( sort.dir ).toLowerCase() === 'asc' ? 'ascending' : 'descending' ) : 'none';
            th.setAttribute( 'aria-sort', dir );
            th.appendChild( el( 'button', { type: 'button', class: 'ppclg-th-sort', 'aria-sort': dir, onClick: () => {
                const nextDir = active && String( sort.dir ).toLowerCase() === 'desc' ? 'asc' : 'desc';
                onSort( col.key, nextDir );
            } }, col.hideLabel ? el( 'span', { class: 'ppclg-sr-only' }, col.label ) : col.label, icon( 'arrowDown', 12 ) ) );
        } else {
            th.appendChild( col.hideLabel ? el( 'span', { class: 'ppclg-sr-only' }, col.label ) : text( col.label ) );
        }
        headRow.appendChild( th );
    }
    thead.appendChild( headRow );
    table.appendChild( thead );

    const tbody = el( 'tbody' );
    if ( rows.length === 0 ) {
        const cell = el( 'td', { colspan: Math.max( 1, columns.length ) } );
        if ( loading ) {
            cell.appendChild( skeletonRows( 5 ) );
        } else if ( empty instanceof Node ) {
            cell.appendChild( empty );
        } else if ( empty && typeof empty === 'object' ) {
            cell.appendChild( emptyState( empty ) );
        } else {
            cell.appendChild( emptyState( { title: typeof empty === 'string' ? empty : 'No records found', compact: true } ) );
        }
        tbody.appendChild( el( 'tr', {}, cell ) );
    }
    rows.forEach( ( row, index ) => {
        const tr = el( 'tr', { 'data-key': rowKey ? rowKey( row ) : null } );
        if ( rowAttrs ) {
            applyAttrs( tr, rowAttrs( row, index ) || {} );
        }
        for ( const col of columns ) {
            const td = el( 'td', { class: [ col.align === 'right' ? 'ppclg-right' : '', col.align === 'center' ? 'ppclg-center' : '', col.class || '' ] } );
            let content;
            try {
                content = col.render ? col.render( row, index ) : row[ col.key ];
            } catch ( e ) {
                content = el( 'span', { class: 'ppclg-muted' }, '—' );
            }
            if ( content === null || content === undefined || content === '' ) {
                td.appendChild( el( 'span', { class: 'ppclg-muted' }, '—' ) );
            } else {
                append( td, content );
            }
            tr.appendChild( td );
        }
        const interactive = onRowClick || expandable;
        if ( interactive ) {
            tr.classList.add( 'ppclg-row--clickable' );
            tr.setAttribute( 'tabindex', '0' );
            tr.setAttribute( 'role', 'button' );
            const activate = ( event ) => {
                if ( event.target.closest( 'a, button, input, select, textarea, label, summary, .ppclg-copybtn' ) ) {
                    return;
                }
                if ( onRowClick ) {
                    onRowClick( row, index, tr );
                } else {
                    toggleExpand( tr, row, columns.length, expandable );
                }
            };
            tr.addEventListener( 'click', activate );
            tr.addEventListener( 'keydown', ( e ) => {
                if ( e.key === 'Enter' || e.key === ' ' ) {
                    if ( e.target !== tr ) {
                        return;
                    }
                    e.preventDefault();
                    activate( e );
                }
            } );
        }
        tbody.appendChild( tr );
    } );
    table.appendChild( tbody );
    wrap.appendChild( table );
    if ( loading && rows.length > 0 ) {
        wrap.appendChild( el( 'div', { class: 'ppclg-table-overlay' }, spinner() ) );
    }
    return wrap;
}

function toggleExpand( tr, row, colspan, render ) {
    const next = tr.nextElementSibling;
    if ( next && next.classList.contains( 'ppclg-expand-row' ) ) {
        next.remove();
        tr.setAttribute( 'aria-expanded', 'false' );
        return;
    }
    let content;
    try {
        content = render( row );
    } catch ( e ) {
        content = el( 'span', { class: 'ppclg-muted' }, 'Nothing to show.' );
    }
    if ( ! content ) {
        return;
    }
    tr.setAttribute( 'aria-expanded', 'true' );
    tr.insertAdjacentElement( 'afterend', el( 'tr', { class: 'ppclg-expand-row' }, el( 'td', { colspan }, content ) ) );
}

export function pagination( { page = 1, pages = 1, total = 0, perPage = 25, onChange, onPerPage = null, perPageOptions = [ 25, 50, 100 ] } ) {
    const from = total === 0 ? 0 : ( page - 1 ) * perPage + 1;
    const to = Math.min( total, page * perPage );
    const info = el( 'div', {}, total === 0 ? 'No results' : `Showing ${ fmtNumber( from ) }–${ fmtNumber( to ) } of ${ fmtNumber( total ) }` );
    const controls = el( 'div', { class: 'ppclg-pagination-controls' } );
    if ( onPerPage ) {
        const select = el( 'select', { class: 'ppclg-select', 'aria-label': 'Rows per page' }, perPageOptions.map( ( n ) => el( 'option', { value: n, selected: n === perPage }, `${ n } / page` ) ) );
        select.addEventListener( 'change', () => onPerPage( Number( select.value ) ) );
        controls.appendChild( select );
    }
    controls.appendChild( iconButton( 'chevronLeft', 'Previous page', { small: true, onClick: () => page > 1 && onChange( page - 1 ), attrs: { disabled: page <= 1 } } ) );
    controls.appendChild( el( 'span', { 'aria-live': 'polite' }, `Page ${ fmtNumber( page ) } of ${ fmtNumber( Math.max( 1, pages ) ) }` ) );
    controls.appendChild( iconButton( 'chevronRight', 'Next page', { small: true, onClick: () => page < pages && onChange( page + 1 ), attrs: { disabled: page >= pages } } ) );
    return el( 'div', { class: 'ppclg-pagination' }, info, controls );
}

// ---------------------------------------------------------------------
// Tabs
// ---------------------------------------------------------------------

/**
 * @param {{items: Array<{id: string, label: string, count?: number, render?: Function, iconName?: string}>, active?: string, onChange?: Function, pills?: boolean, lazy?: boolean}} spec
 * When items have render(panel, api) the component owns the panels (rendered once, unless api.refresh(id) is called).
 */
export function tabs( { items = [], active = null, onChange = null, pills = false, rerender = false } ) {
    const listId = nextId( 'tabs' );
    const list = el( 'div', { class: [ 'ppclg-tabs', pills ? 'ppclg-tabs--pills' : '' ], role: 'tablist' } );
    const panels = el( 'div', { class: 'ppclg-tabpanels' } );
    const root = el( 'div', { class: 'ppclg-tabs-root' }, list, panels );
    const buttons = {};
    const counts = {};
    const panelEls = {};
    const rendered = {};
    let current = active && items.some( ( i ) => i.id === active ) ? active : ( items[ 0 ] ? items[ 0 ].id : null );

    const api = {
        el: root,
        list,
        panels,
        get active() {
            return current;
        },
        select: ( id, { silent = false } = {} ) => select( id, silent ),
        setCount: ( id, n ) => {
            if ( counts[ id ] ) {
                counts[ id ].textContent = n === null || n === undefined ? '' : fmtNumber( n );
                counts[ id ].hidden = n === null || n === undefined;
            }
        },
        refresh: ( id ) => {
            const target = id || current;
            rendered[ target ] = false;
            if ( target === current ) {
                renderPanel( target );
            }
        },
        panel: ( id ) => panelEls[ id ],
    };

    function renderPanel( id ) {
        const item = items.find( ( i ) => i.id === id );
        const panel = panelEls[ id ];
        if ( ! item || ! panel || ! item.render ) {
            return;
        }
        if ( rendered[ id ] && ! rerender ) {
            return;
        }
        rendered[ id ] = true;
        replace( panel );
        try {
            const result = item.render( panel, api );
            if ( result && typeof result.then === 'function' ) {
                result.catch( ( e ) => {
                    replace( panel, errorState( { message: e && e.message ? e.message : '', retry: () => api.refresh( id ) } ) );
                } );
            }
        } catch ( e ) {
            replace( panel, errorState( { message: e && e.message ? e.message : '', retry: () => api.refresh( id ) } ) );
        }
    }

    function select( id, silent ) {
        if ( ! buttons[ id ] ) {
            return;
        }
        current = id;
        for ( const item of items ) {
            const on = item.id === id;
            buttons[ item.id ].setAttribute( 'aria-selected', on ? 'true' : 'false' );
            buttons[ item.id ].setAttribute( 'tabindex', on ? '0' : '-1' );
            if ( panelEls[ item.id ] ) {
                panelEls[ item.id ].hidden = ! on;
            }
        }
        renderPanel( id );
        if ( ! silent && onChange ) {
            onChange( id );
        }
    }

    items.forEach( ( item ) => {
        const tabId = `${ listId }-tab-${ item.id }`;
        const panelId = `${ listId }-panel-${ item.id }`;
        const count = el( 'span', { class: 'ppclg-count', hidden: item.count === undefined || item.count === null }, item.count === undefined || item.count === null ? '' : fmtNumber( item.count ) );
        counts[ item.id ] = count;
        const btn = el( 'button', { type: 'button', class: 'ppclg-tab', role: 'tab', id: tabId, 'aria-selected': 'false', 'aria-controls': panelId, tabindex: '-1' }, item.iconName ? icon( item.iconName, 14 ) : null, item.label, count );
        btn.addEventListener( 'click', () => select( item.id, false ) );
        btn.addEventListener( 'keydown', ( e ) => {
            const idx = items.findIndex( ( i ) => i.id === item.id );
            let nextIdx = -1;
            if ( e.key === 'ArrowRight' ) {
                nextIdx = ( idx + 1 ) % items.length;
            } else if ( e.key === 'ArrowLeft' ) {
                nextIdx = ( idx - 1 + items.length ) % items.length;
            } else if ( e.key === 'Home' ) {
                nextIdx = 0;
            } else if ( e.key === 'End' ) {
                nextIdx = items.length - 1;
            }
            if ( nextIdx >= 0 ) {
                e.preventDefault();
                select( items[ nextIdx ].id, false );
                buttons[ items[ nextIdx ].id ].focus();
            }
        } );
        buttons[ item.id ] = btn;
        list.appendChild( btn );
        if ( item.render ) {
            const panel = el( 'div', { class: 'ppclg-tabpanel', role: 'tabpanel', id: panelId, 'aria-labelledby': tabId, hidden: true, tabindex: '0' } );
            panelEls[ item.id ] = panel;
            panels.appendChild( panel );
        }
    } );

    if ( current ) {
        select( current, true );
    }
    return api;
}

export default {
    el, svg, append, replace, clear, text, fragment, escapeHtml, icon, nextId, setLocale,
    fmtNumber, fmtCompact, fmtPercent, fmtBytes, fmtDate, fmtDuration, parseDate, timeAgo, timeEl, labelize, truncate, debounce, sleep,
    isObject, deepClone, deepEqual, getPath, setPath, STATUS_KIND, kindFor, scoreClass,
    badge, statusBadge, button, iconButton, setBusy, linkButton, extLink, copyButton, spinner, loading, skeletonRows, emptyState, errorState, callout,
    card, cardBody, pageHeader, kv, pre, details, progressBar, meter, scoreRing, statTile, chipList, checklist,
    toast, overlayHost, drawer, modal, confirm, prompt, formModal, formField, collect, switchRow, filterBar, dataTable, pagination, tabs,
};
