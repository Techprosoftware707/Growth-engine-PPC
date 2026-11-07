/**
 * Pure-SVG charts: bar, line, histogram, segmented bar and sparkline.
 *
 * Mark rules: bars ≤ 24px thick with a 4px radius on the data end only, 2px lines,
 * ≥ 8px markers ringed in the surface colour, hairline solid gridlines, labels in
 * text tokens, hover tooltips, a legend for ≥ 2 series and a table view toggle.
 */

import { el, svg, replace, fmtNumber, fmtPercent, dataTable, truncate } from './ui.js';

const SERIES = [ 'var(--series-1)', 'var(--series-2)', 'var(--series-3)', 'var(--series-4)' ];
const BAR_MAX = 24;
const BAR_RADIUS = 4;
const GAP = 2;

export function seriesColor( index ) {
    return SERIES[ Math.abs( index ) % SERIES.length ];
}

function num( value ) {
    const n = Number( value );
    return Number.isFinite( n ) ? n : 0;
}

/** Nice tick values from 0 to a rounded maximum. */
export function niceTicks( max, count = 4 ) {
    if ( ! ( max > 0 ) ) {
        return { ticks: [ 0, 1 ], max: 1 };
    }
    const rough = max / count;
    const pow = Math.pow( 10, Math.floor( Math.log10( rough ) ) );
    let step = pow * 10;
    for ( const c of [ 1, 2, 2.5, 5, 10 ] ) {
        if ( rough <= c * pow ) {
            step = c * pow;
            break;
        }
    }
    let top = Math.ceil( max / step ) * step;
    // Counts are whole numbers: never emit fractional ticks that render as duplicate integer labels.
    if ( max >= 1 && step < 1 ) {
        step = 1;
        top = Math.ceil( max );
    }
    const ticks = [];
    for ( let v = 0; v <= top + step / 2; v += step ) {
        ticks.push( Number( v.toFixed( 10 ) ) );
    }
    return { ticks, max: top };
}

/** Rectangle path rounded on the data end only ('top' for vertical bars, 'right' for horizontal). */
function barPath( x, y, w, h, side ) {
    if ( w <= 0 || h <= 0 ) {
        return '';
    }
    const r = Math.max( 0, Math.min( BAR_RADIUS, side === 'top' ? Math.min( w / 2, h ) : Math.min( h / 2, w ) ) );
    if ( side === 'top' ) {
        return `M${ x },${ y + h } V${ y + r } Q${ x },${ y } ${ x + r },${ y } H${ x + w - r } Q${ x + w },${ y } ${ x + w },${ y + r } V${ y + h } Z`;
    }
    return `M${ x },${ y } H${ x + w - r } Q${ x + w },${ y } ${ x + w },${ y + r } V${ y + h - r } Q${ x + w },${ y + h } ${ x + w - r },${ y + h } H${ x } Z`;
}

function textWidth( str, size = 11 ) {
    return String( str ).length * size * 0.58 + 4;
}

function label( x, y, content, { anchor = 'start', muted = false, strong = false, baseline = 'middle', size = 11 } = {} ) {
    return svg( 'text', {
        x,
        y,
        class: [ 'ppclg-chart-label', muted ? 'ppclg-chart-label--muted' : '', strong ? 'ppclg-chart-label--strong' : '' ],
        'text-anchor': anchor,
        'dominant-baseline': baseline,
        'font-size': size,
        text: String( content ),
    } );
}

// ---------------------------------------------------------------------
// Frame: title, chart/table toggle, tooltip layer, resize handling
// ---------------------------------------------------------------------

function frame( { title, subtitle, columns, rows, draw, minHeight = 60, emptyMessage = 'No data yet' } ) {
    const body = el( 'div', { class: 'ppclg-chart-body', style: { minHeight: `${ minHeight }px` } } );
    const tableWrap = el( 'div', { class: 'ppclg-chart-table', hidden: true } );
    const tip = el( 'div', { class: 'ppclg-chart-tip', hidden: true, role: 'status' } );
    const chartBtn = el( 'button', { type: 'button', 'aria-pressed': 'true' }, 'Chart' );
    const tableBtn = el( 'button', { type: 'button', 'aria-pressed': 'false' }, 'Table' );
    const toggle = el( 'div', { class: 'ppclg-chart-toggle', role: 'group', 'aria-label': 'View as chart or table' }, chartBtn, tableBtn );
    const root = el( 'div', { class: 'ppclg-chart', role: 'figure', 'aria-label': title || 'Chart' },
        el( 'div', { class: 'ppclg-chart-head' },
            el( 'div', {}, title ? el( 'div', { class: 'ppclg-chart-title' }, title ) : null, subtitle ? el( 'div', { class: 'ppclg-chart-sub' }, subtitle ) : null ),
            toggle,
        ),
        body,
        tableWrap,
        tip,
    );

    const hasData = rows && rows.length > 0;
    const showTable = ( on ) => {
        chartBtn.setAttribute( 'aria-pressed', on ? 'false' : 'true' );
        tableBtn.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
        body.hidden = on;
        tableWrap.hidden = ! on;
        if ( on && ! tableWrap.firstChild ) {
            tableWrap.appendChild( dataTable( { columns, rows, dense: true, plain: true, empty: emptyMessage } ) );
        }
    };
    chartBtn.addEventListener( 'click', () => showTable( false ) );
    tableBtn.addEventListener( 'click', () => showTable( true ) );

    if ( ! hasData ) {
        body.appendChild( el( 'div', { class: 'ppclg-chart-empty' }, emptyMessage ) );
        return root;
    }

    const tooltip = {
        show: ( x, y, content ) => {
            replace( tip, content );
            tip.hidden = false;
            const w = body.clientWidth || 300;
            const cx = Math.max( 70, Math.min( w - 70, x ) );
            tip.style.left = `${ cx }px`;
            tip.style.top = `${ y }px`;
            tip.style.transform = y < 70 ? 'translate(-50%, 14px)' : 'translate(-50%, calc(-100% - 10px))';
        },
        hide: () => {
            tip.hidden = true;
        },
    };

    let lastWidth = 0;
    const render = () => {
        const width = Math.max( 240, Math.floor( body.clientWidth || root.clientWidth || 0 ) || 600 );
        if ( width === lastWidth ) {
            return;
        }
        lastWidth = width;
        tooltip.hide();
        try {
            replace( body, draw( width, tooltip, body ) );
        } catch ( e ) {
            replace( body, el( 'div', { class: 'ppclg-chart-empty' }, 'The chart could not be drawn.' ) );
        }
    };

    if ( typeof ResizeObserver !== 'undefined' ) {
        const ro = new ResizeObserver( () => {
            if ( ! body.hidden ) {
                render();
            }
        } );
        ro.observe( root );
    } else {
        window.addEventListener( 'resize', render );
    }
    requestAnimationFrame( render );
    return root;
}

function tipContent( title, rows ) {
    return [
        title !== null && title !== undefined ? el( 'div', { class: 'ppclg-chart-tip-title' }, String( title ) ) : null,
        rows.map( ( r ) => el( 'div', { class: 'ppclg-chart-tip-row' }, r.color ? el( 'span', { class: 'ppclg-chart-swatch', style: { background: r.color } } ) : null, r.label ? el( 'span', {}, `${ r.label }: ` ) : null, el( 'strong', {}, r.value ) ) ),
    ];
}

function foldOther( data, maxBars ) {
    if ( data.length <= maxBars ) {
        return data;
    }
    const head = data.slice( 0, maxBars - 1 );
    const rest = data.slice( maxBars - 1 ).reduce( ( sum, d ) => sum + num( d.value ), 0 );
    return head.concat( [ { label: 'Other', value: rest, other: true } ] );
}

// ---------------------------------------------------------------------
// Bar chart
// ---------------------------------------------------------------------

/**
 * @param {{data: Array<{label: string, value: number}>, horizontal?: boolean, title?: string, subtitle?: string, valueFormat?: Function, color?: string, maxBars?: number, height?: number, emptyMessage?: string}} options
 */
export function barChart( { data = [], horizontal = true, title = '', subtitle = '', valueFormat = ( v ) => fmtNumber( v ), color = seriesColor( 0 ), maxBars = 12, height = 0, emptyMessage = 'No data yet' } ) {
    const clean = foldOther( ( data || [] ).map( ( d ) => ( { label: String( d.label ?? '' ), value: num( d.value ), other: Boolean( d.other ) } ) ), maxBars );
    const total = clean.reduce( ( s, d ) => s + d.value, 0 );
    const columns = [
        { key: 'label', label: 'Label' },
        { key: 'value', label: 'Value', align: 'right', render: ( r ) => valueFormat( r.value ) },
        { key: 'share', label: 'Share', align: 'right', render: ( r ) => ( total > 0 ? fmtPercent( ( r.value / total ) * 100 ) : '—' ) },
    ];

    const draw = ( width, tooltip ) => {
        const maxValue = Math.max( 0, ...clean.map( ( d ) => d.value ) );
        const { ticks, max } = niceTicks( maxValue, horizontal ? 4 : 4 );
        if ( horizontal ) {
            const rowH = 28;
            const barH = 18;
            const padTop = 6;
            const padBottom = 22;
            const labelW = Math.min( Math.max( 60, Math.max( ...clean.map( ( d ) => textWidth( d.label ) ) ) ), Math.floor( width * 0.38 ) );
            const valueW = Math.max( 36, Math.max( ...clean.map( ( d ) => textWidth( valueFormat( d.value ) ) ) ) );
            const plotX = labelW + 10;
            const plotW = Math.max( 40, width - plotX - valueW - 8 );
            const h = height || padTop + clean.length * rowH + padBottom;
            const node = svg( 'svg', { width, height: h, viewBox: `0 0 ${ width } ${ h }`, role: 'img', 'aria-label': title || 'Bar chart' } );
            const gridY2 = padTop + clean.length * rowH;
            for ( const t of ticks ) {
                const x = plotX + ( t / max ) * plotW;
                node.appendChild( svg( 'line', { class: 'ppclg-chart-grid', x1: x, x2: x, y1: padTop, y2: gridY2 } ) );
                node.appendChild( label( x, gridY2 + 12, valueFormat( t ), { anchor: 'middle', muted: true, size: 10 } ) );
            }
            clean.forEach( ( d, i ) => {
                const y = padTop + i * rowH + ( rowH - barH ) / 2;
                const w = max > 0 ? ( d.value / max ) * plotW : 0;
                node.appendChild( label( plotX - 10, y + barH / 2, truncate( d.label, Math.floor( labelW / 6.4 ) ), { anchor: 'end', muted: d.other } ) );
                node.appendChild( svg( 'path', { class: 'ppclg-chart-bar', d: barPath( plotX, y, Math.max( w, d.value > 0 ? 2 : 0 ), barH, 'right' ), style: { fill: d.other ? 'var(--ppclg-ink-3)' : color } } ) );
                node.appendChild( label( plotX + w + 6, y + barH / 2, valueFormat( d.value ), { strong: true } ) );
                const hit = svg( 'rect', { class: 'ppclg-chart-hit', x: 0, y: padTop + i * rowH, width, height: rowH } );
                hit.addEventListener( 'mouseenter', () => tooltip.show( plotX + Math.max( w, 10 ), y, tipContent( d.label, [ { value: valueFormat( d.value ), color }, total > 0 ? { label: 'Share', value: fmtPercent( ( d.value / total ) * 100 ) } : null ].filter( Boolean ) ) ) );
                hit.addEventListener( 'mouseleave', tooltip.hide );
                node.appendChild( svg( 'title', { text: `${ d.label }: ${ valueFormat( d.value ) }` } ) );
                node.appendChild( hit );
            } );
            return node;
        }

        const h = height || 220;
        const padLeft = Math.max( 32, Math.max( ...ticks.map( ( t ) => textWidth( valueFormat( t ), 10 ) ) ) + 6 );
        const padRight = 8;
        const padTop = 10;
        const padBottom = 30;
        const plotW = Math.max( 40, width - padLeft - padRight );
        const plotH = Math.max( 40, h - padTop - padBottom );
        const slot = plotW / Math.max( 1, clean.length );
        const barW = Math.max( 4, Math.min( BAR_MAX, slot - GAP * 2 ) );
        const node = svg( 'svg', { width, height: h, viewBox: `0 0 ${ width } ${ h }`, role: 'img', 'aria-label': title || 'Bar chart' } );
        for ( const t of ticks ) {
            const y = padTop + plotH - ( t / max ) * plotH;
            node.appendChild( svg( 'line', { class: 'ppclg-chart-grid', x1: padLeft, x2: padLeft + plotW, y1: y, y2: y } ) );
            node.appendChild( label( padLeft - 6, y, valueFormat( t ), { anchor: 'end', muted: true, size: 10 } ) );
        }
        const every = Math.max( 1, Math.ceil( clean.length / Math.max( 1, Math.floor( plotW / 56 ) ) ) );
        clean.forEach( ( d, i ) => {
            const x = padLeft + i * slot + ( slot - barW ) / 2;
            const bh = max > 0 ? ( d.value / max ) * plotH : 0;
            const y = padTop + plotH - bh;
            node.appendChild( svg( 'path', { class: 'ppclg-chart-bar', d: barPath( x, y, barW, Math.max( bh, d.value > 0 ? 2 : 0 ), 'top' ), style: { fill: d.other ? 'var(--ppclg-ink-3)' : color } } ) );
            if ( i % every === 0 || clean.length <= 8 ) {
                node.appendChild( label( x + barW / 2, padTop + plotH + 14, truncate( d.label, Math.max( 4, Math.floor( slot / 6.2 ) ) ), { anchor: 'middle', muted: true, size: 10 } ) );
            }
            const hit = svg( 'rect', { class: 'ppclg-chart-hit', x: padLeft + i * slot, y: padTop, width: slot, height: plotH } );
            hit.addEventListener( 'mouseenter', () => tooltip.show( x + barW / 2, y, tipContent( d.label, [ { value: valueFormat( d.value ), color } ] ) ) );
            hit.addEventListener( 'mouseleave', tooltip.hide );
            node.appendChild( hit );
        } );
        return node;
    };

    return frame( { title, subtitle, columns, rows: clean, draw, emptyMessage, minHeight: horizontal ? 40 : 120 } );
}

// ---------------------------------------------------------------------
// Line chart
// ---------------------------------------------------------------------

/**
 * @param {{series: Array<{name: string, points: Array<{x: any, y: number}>}>, title?: string, subtitle?: string, height?: number, xFormat?: Function, yFormat?: Function, emptyMessage?: string}} options
 */
export function lineChart( { series = [], title = '', subtitle = '', height = 240, xFormat = ( x ) => String( x ), yFormat = ( v ) => fmtNumber( v ), emptyMessage = 'No data yet' } ) {
    const clean = ( series || [] ).filter( ( s ) => s && Array.isArray( s.points ) ).map( ( s, i ) => ( {
        name: String( s.name ?? `Series ${ i + 1 }` ),
        color: s.color || seriesColor( i ),
        points: s.points.map( ( p ) => ( { x: p && typeof p === 'object' ? p.x : p, y: num( p && typeof p === 'object' ? p.y : 0 ) } ) ),
    } ) );
    const xs = [];
    const seen = new Set();
    for ( const s of clean ) {
        for ( const p of s.points ) {
            const key = String( p.x );
            if ( ! seen.has( key ) ) {
                seen.add( key );
                xs.push( p.x );
            }
        }
    }
    const rows = xs.map( ( x ) => {
        const row = { x: xFormat( x ) };
        clean.forEach( ( s, i ) => {
            const p = s.points.find( ( q ) => String( q.x ) === String( x ) );
            row[ `s${ i }` ] = p ? p.y : null;
        } );
        return row;
    } );
    const columns = [ { key: 'x', label: 'Period' } ].concat( clean.map( ( s, i ) => ( { key: `s${ i }`, label: s.name, align: 'right', render: ( r ) => ( r[ `s${ i }` ] === null ? '—' : yFormat( r[ `s${ i }` ] ) ) } ) ) );

    const draw = ( width, tooltip ) => {
        const maxY = Math.max( 0, ...clean.flatMap( ( s ) => s.points.map( ( p ) => p.y ) ) );
        const { ticks, max } = niceTicks( maxY, 4 );
        const directLabels = clean.length >= 2 && clean.length <= 4;
        const padLeft = Math.max( 30, Math.max( ...ticks.map( ( t ) => textWidth( yFormat( t ), 10 ) ) ) + 6 );
        const padRight = directLabels ? Math.min( 110, Math.max( ...clean.map( ( s ) => textWidth( s.name ) ) ) + 10 ) : 14;
        const padTop = 12;
        const padBottom = 28;
        const plotW = Math.max( 40, width - padLeft - padRight );
        const plotH = Math.max( 40, height - padTop - padBottom );
        const n = xs.length;
        const xPos = ( i ) => ( n <= 1 ? padLeft + plotW / 2 : padLeft + ( i / ( n - 1 ) ) * plotW );
        const yPos = ( v ) => padTop + plotH - ( max > 0 ? ( v / max ) * plotH : 0 );
        const node = svg( 'svg', { width, height, viewBox: `0 0 ${ width } ${ height }`, role: 'img', 'aria-label': title || 'Line chart' } );

        for ( const t of ticks ) {
            const y = yPos( t );
            node.appendChild( svg( 'line', { class: 'ppclg-chart-grid', x1: padLeft, x2: padLeft + plotW, y1: y, y2: y } ) );
            node.appendChild( label( padLeft - 6, y, yFormat( t ), { anchor: 'end', muted: true, size: 10 } ) );
        }
        const maxLabels = Math.max( 2, Math.floor( plotW / 70 ) );
        const every = Math.max( 1, Math.ceil( n / maxLabels ) );
        xs.forEach( ( x, i ) => {
            const isLast = i === n - 1;
            // Drop the regular label that would collide with the always-shown last label.
            const nearLast = ! isLast && n - 1 - i < Math.max( 1, Math.ceil( every / 2 ) );
            if ( ( i % every === 0 && ! nearLast ) || isLast ) {
                const anchor = i === 0 ? 'start' : ( isLast ? 'end' : 'middle' );
                node.appendChild( label( xPos( i ), padTop + plotH + 16, xFormat( x ), { anchor, muted: true, size: 10 } ) );
            }
        } );

        const markers = [];
        clean.forEach( ( s, si ) => {
            const pts = xs.map( ( x, i ) => {
                const p = s.points.find( ( q ) => String( q.x ) === String( x ) );
                return p ? { i, x: xPos( i ), y: yPos( p.y ), v: p.y } : null;
            } ).filter( Boolean );
            if ( pts.length > 1 ) {
                node.appendChild( svg( 'path', { class: 'ppclg-chart-line', d: pts.map( ( p, k ) => `${ k === 0 ? 'M' : 'L' }${ p.x.toFixed( 1 ) },${ p.y.toFixed( 1 ) }` ).join( ' ' ), style: { stroke: s.color } } ) );
            }
            for ( const p of pts ) {
                const m = svg( 'circle', { class: 'ppclg-chart-marker', cx: p.x, cy: p.y, r: 4, style: { fill: s.color } } );
                markers.push( { si, i: p.i, node: m } );
                node.appendChild( m );
            }
            if ( directLabels && pts.length > 0 ) {
                const last = pts[ pts.length - 1 ];
                node.appendChild( label( last.x + 8, last.y, truncate( s.name, 16 ), { size: 11, strong: true } ) );
            }
        } );

        const crosshair = svg( 'line', { class: 'ppclg-chart-crosshair', x1: 0, x2: 0, y1: padTop, y2: padTop + plotH, style: { visibility: 'hidden' } } );
        node.appendChild( crosshair );
        const overlay = svg( 'rect', { class: 'ppclg-chart-hit', x: padLeft - 10, y: padTop, width: plotW + 20, height: plotH } );
        overlay.addEventListener( 'mousemove', ( event ) => {
            const rect = node.getBoundingClientRect();
            const mx = event.clientX - rect.left;
            let best = 0;
            let bestDist = Infinity;
            for ( let i = 0; i < n; i++ ) {
                const d = Math.abs( xPos( i ) - mx );
                if ( d < bestDist ) {
                    bestDist = d;
                    best = i;
                }
            }
            const cx = xPos( best );
            crosshair.setAttribute( 'x1', cx );
            crosshair.setAttribute( 'x2', cx );
            crosshair.style.visibility = 'visible';
            for ( const m of markers ) {
                m.node.setAttribute( 'r', m.i === best ? 5.5 : 4 );
            }
            const tipRows = clean.map( ( s ) => {
                const p = s.points.find( ( q ) => String( q.x ) === String( xs[ best ] ) );
                return { label: s.name, value: p ? yFormat( p.y ) : '—', color: s.color };
            } );
            const topY = Math.min( ...clean.map( ( s ) => {
                const p = s.points.find( ( q ) => String( q.x ) === String( xs[ best ] ) );
                return p ? yPos( p.y ) : padTop + plotH;
            } ) );
            tooltip.show( cx, topY, tipContent( xFormat( xs[ best ] ), tipRows ) );
        } );
        overlay.addEventListener( 'mouseleave', () => {
            crosshair.style.visibility = 'hidden';
            for ( const m of markers ) {
                m.node.setAttribute( 'r', 4 );
            }
            tooltip.hide();
        } );
        node.appendChild( overlay );
        return node;
    };

    const root = frame( { title, subtitle, columns, rows, draw, emptyMessage, minHeight: 120 } );
    if ( clean.length >= 2 && rows.length > 0 ) {
        root.appendChild( el( 'div', { class: 'ppclg-chart-legend', 'aria-label': 'Legend' }, clean.map( ( s ) => el( 'span', { class: 'ppclg-chart-legend-item' }, el( 'span', { class: 'ppclg-chart-swatch ppclg-chart-swatch--line', style: { background: s.color } } ), s.name ) ) ) );
    }
    return root;
}

// ---------------------------------------------------------------------
// Histogram
// ---------------------------------------------------------------------

/**
 * @param {{data: Array<{bucket: string, count: number}>, title?: string, subtitle?: string, height?: number, color?: string, emptyMessage?: string}} options
 */
export function histogram( { data = [], title = '', subtitle = '', height = 200, color = seriesColor( 0 ), emptyMessage = 'No data yet' } ) {
    const clean = ( data || [] ).map( ( d ) => ( { bucket: String( d.bucket ?? d.label ?? '' ), count: num( d.count ?? d.value ) } ) );
    const total = clean.reduce( ( s, d ) => s + d.count, 0 );
    const columns = [
        { key: 'bucket', label: 'Bucket' },
        { key: 'count', label: 'Count', align: 'right', render: ( r ) => fmtNumber( r.count ) },
        { key: 'share', label: 'Share', align: 'right', render: ( r ) => ( total > 0 ? fmtPercent( ( r.count / total ) * 100 ) : '—' ) },
    ];
    const draw = ( width, tooltip ) => {
        const maxV = Math.max( 0, ...clean.map( ( d ) => d.count ) );
        const { ticks, max } = niceTicks( maxV, 4 );
        const padLeft = Math.max( 28, Math.max( ...ticks.map( ( t ) => textWidth( fmtNumber( t ), 10 ) ) ) + 6 );
        const padRight = 8;
        const padTop = 10;
        const padBottom = 28;
        const plotW = Math.max( 40, width - padLeft - padRight );
        const plotH = Math.max( 40, height - padTop - padBottom );
        const slot = plotW / Math.max( 1, clean.length );
        const barW = Math.max( 3, Math.min( BAR_MAX, slot - GAP ) );
        const node = svg( 'svg', { width, height, viewBox: `0 0 ${ width } ${ height }`, role: 'img', 'aria-label': title || 'Histogram' } );
        for ( const t of ticks ) {
            const y = padTop + plotH - ( t / max ) * plotH;
            node.appendChild( svg( 'line', { class: 'ppclg-chart-grid', x1: padLeft, x2: padLeft + plotW, y1: y, y2: y } ) );
            node.appendChild( label( padLeft - 6, y, fmtNumber( t ), { anchor: 'end', muted: true, size: 10 } ) );
        }
        const every = Math.max( 1, Math.ceil( clean.length / Math.max( 1, Math.floor( plotW / 44 ) ) ) );
        clean.forEach( ( d, i ) => {
            const x = padLeft + i * slot + ( slot - barW ) / 2;
            const bh = max > 0 ? ( d.count / max ) * plotH : 0;
            const y = padTop + plotH - bh;
            node.appendChild( svg( 'path', { class: 'ppclg-chart-bar', d: barPath( x, y, barW, Math.max( bh, d.count > 0 ? 2 : 0 ), 'top' ), style: { fill: color } } ) );
            if ( i % every === 0 || i === clean.length - 1 ) {
                node.appendChild( label( x + barW / 2, padTop + plotH + 14, d.bucket, { anchor: 'middle', muted: true, size: 10 } ) );
            }
            const hit = svg( 'rect', { class: 'ppclg-chart-hit', x: padLeft + i * slot, y: padTop, width: slot, height: plotH } );
            hit.addEventListener( 'mouseenter', () => tooltip.show( x + barW / 2, y, tipContent( d.bucket, [ { label: 'Count', value: fmtNumber( d.count ), color } ] ) ) );
            hit.addEventListener( 'mouseleave', tooltip.hide );
            node.appendChild( hit );
        } );
        return node;
    };
    return frame( { title, subtitle, columns, rows: clean, draw, emptyMessage, minHeight: 120 } );
}

// ---------------------------------------------------------------------
// Segmented (stacked) bar
// ---------------------------------------------------------------------

/**
 * @param {{parts: Array<{label: string, value: number, color?: string}>, title?: string, subtitle?: string, valueFormat?: Function, showLegend?: boolean, emptyMessage?: string}} options
 */
export function segmentedBar( { parts = [], title = '', subtitle = '', valueFormat = ( v ) => fmtNumber( v ), showLegend = true, emptyMessage = 'No data yet' } ) {
    const clean = ( parts || [] ).map( ( p, i ) => ( { label: String( p.label ?? '' ), value: Math.max( 0, num( p.value ) ), color: p.color || seriesColor( i ) } ) ).filter( ( p ) => p.value > 0 );
    const total = clean.reduce( ( s, p ) => s + p.value, 0 );
    const columns = [
        { key: 'label', label: 'Segment' },
        { key: 'value', label: 'Value', align: 'right', render: ( r ) => valueFormat( r.value ) },
        { key: 'share', label: 'Share', align: 'right', render: ( r ) => ( total > 0 ? fmtPercent( ( r.value / total ) * 100 ) : '—' ) },
    ];
    const barH = 18;
    const draw = ( width, tooltip ) => {
        const node = svg( 'svg', { width, height: barH, viewBox: `0 0 ${ width } ${ barH }`, role: 'img', 'aria-label': title || 'Segmented bar' } );
        const clipId = `ppclg-clip-${ Math.random().toString( 36 ).slice( 2, 10 ) }`;
        node.appendChild( svg( 'defs', {}, svg( 'clipPath', { id: clipId }, svg( 'rect', { x: 0, y: 0, width, height: barH, rx: barH / 2, ry: barH / 2 } ) ) ) );
        const group = svg( 'g', { 'clip-path': `url(#${ clipId })` } );
        group.appendChild( svg( 'rect', { x: 0, y: 0, width, height: barH, style: { fill: 'var(--ppclg-surface-3)' } } ) );
        const gaps = Math.max( 0, clean.length - 1 ) * GAP;
        const usable = Math.max( 0, width - gaps );
        let x = 0;
        clean.forEach( ( p ) => {
            const w = total > 0 ? ( p.value / total ) * usable : 0;
            const rect = svg( 'rect', { class: 'ppclg-chart-bar', x, y: 0, width: Math.max( w, 1 ), height: barH, style: { fill: p.color } } );
            const startX = x;
            rect.addEventListener( 'mouseenter', () => tooltip.show( startX + w / 2, 0, tipContent( p.label, [ { value: valueFormat( p.value ), color: p.color }, { label: 'Share', value: fmtPercent( ( p.value / total ) * 100 ) } ] ) ) );
            rect.addEventListener( 'mouseleave', tooltip.hide );
            group.appendChild( rect );
            x += w + GAP;
        } );
        node.appendChild( group );
        return node;
    };
    const root = frame( { title, subtitle, columns, rows: clean, draw, emptyMessage, minHeight: barH } );
    if ( showLegend && clean.length > 0 ) {
        root.appendChild( el( 'div', { class: 'ppclg-segbar-legend', 'aria-label': 'Legend' }, clean.map( ( p ) => el( 'span', { class: 'ppclg-segbar-legend-item' },
            el( 'span', { class: 'ppclg-chart-swatch', style: { background: p.color } } ),
            el( 'span', { class: 'ppclg-truncate' }, p.label ),
            el( 'span', { class: 'ppclg-strong' }, `${ valueFormat( p.value ) } · ${ fmtPercent( ( p.value / total ) * 100, 0 ) }` ),
        ) ) ) );
    }
    return root;
}

// ---------------------------------------------------------------------
// Sparkline (no frame)
// ---------------------------------------------------------------------

/**
 * @param {{points: Array<number|{y: number}>, width?: number, height?: number, color?: string, label?: string}} options
 */
export function sparkline( { points = [], width = 120, height = 32, color = seriesColor( 0 ), label: ariaLabel = '' } ) {
    const ys = ( points || [] ).map( ( p ) => num( p && typeof p === 'object' ? p.y : p ) );
    const node = svg( 'svg', { width, height, viewBox: `0 0 ${ width } ${ height }`, role: 'img', 'aria-label': ariaLabel || `Trend of ${ ys.length } values`, class: 'ppclg-sparkline' } );
    if ( ys.length === 0 ) {
        return node;
    }
    const min = Math.min( ...ys );
    const max = Math.max( ...ys );
    const span = max - min || 1;
    const pad = 4;
    const xPos = ( i ) => ( ys.length <= 1 ? width / 2 : pad + ( i / ( ys.length - 1 ) ) * ( width - pad * 2 ) );
    const yPos = ( v ) => height - pad - ( ( v - min ) / span ) * ( height - pad * 2 );
    if ( ys.length > 1 ) {
        node.appendChild( svg( 'path', { class: 'ppclg-chart-line', d: ys.map( ( v, i ) => `${ i === 0 ? 'M' : 'L' }${ xPos( i ).toFixed( 1 ) },${ yPos( v ).toFixed( 1 ) }` ).join( ' ' ), style: { stroke: color } } ) );
    }
    const lastI = ys.length - 1;
    node.appendChild( svg( 'circle', { class: 'ppclg-chart-marker', cx: xPos( lastI ), cy: yPos( ys[ lastI ] ), r: 3.5, style: { fill: color } } ) );
    node.appendChild( svg( 'title', { text: `Latest: ${ fmtNumber( ys[ lastI ] ) }` } ) );
    return node;
}

export default { barChart, lineChart, histogram, segmentedBar, sparkline, seriesColor, niceTicks };
