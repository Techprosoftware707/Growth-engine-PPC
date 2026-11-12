/**
 * Storefront compliance banner (shown above the "Grow your site" card and wherever the status matters).
 *
 * renderComplianceBanner( container, status, { onRecheck, onAcknowledge } ) renders INTO `container`
 * (replacing its children) from the GET /compliance/storefront response. It renders nothing when the
 * effective status is "pass" or "acknowledged".
 *
 * The banner performs the re-check / acknowledge requests itself, re-renders with the server's answer and
 * then calls onRecheck( newStatus ) / onAcknowledge( newStatus ) so the host page can refresh. Refusals are
 * reported with the server's message; nothing is reported as done unless the server said so.
 *
 * All text goes through the ui.js DOM helpers as text nodes; server data is never parsed as markup.
 */

import * as api from './api.js';
import { el, replace, callout, button, badge, details, toast, confirm, timeEl, fmtNumber } from './ui.js';

const WHERE_LABELS = {
    category: 'Product category',
    tag: 'Product tag',
    product: 'Product',
    menu: 'Menu item',
    page: 'Page',
    post: 'Post',
};

const LEGAL_NOTE = 'How the store presents itself is your legal decision as the owner, to be made with your Canadian regulatory counsel. This check only reads the store; it never changes it.';

function num( value ) {
    const n = Number( value );
    return Number.isFinite( n ) && n > 0 ? Math.floor( n ) : 0;
}

function plural( n, one, many ) {
    return `${ fmtNumber( n ) } ${ n === 1 ? one : many }`;
}

/**
 * Pure description of what the banner shows for a status (exported for tests).
 *
 * @param {object|null} status GET /compliance/storefront response.
 * @return {{show:boolean, kind:string, title:string, lines:string[], canAcknowledge:boolean, canRecheck:boolean, recheckLabel:string, paused:boolean}}
 */
export function bannerModel( status ) {
    const s = status && typeof status === 'object' ? status : null;
    const effective = s && typeof s.effective === 'string' ? s.effective : 'unknown';
    const none = { show: false, kind: 'neutral', title: '', lines: [], canAcknowledge: false, canRecheck: false, recheckLabel: '', paused: false };
    if ( effective === 'pass' || effective === 'acknowledged' ) {
        return none;
    }
    const counts = s && s.counts && typeof s.counts === 'object' ? s.counts : {};
    const block = num( counts.block );
    const warn = num( counts.warn );
    const gate = ! s || s.gate !== false;
    const error = s && typeof s.error === 'string' ? s.error : '';

    if ( effective === 'block' ) {
        const paused = gate;
        return {
            show: true,
            kind: paused ? 'danger' : 'warning',
            title: paused
                ? 'Storefront check: automated submissions and outreach sending are paused'
                : 'Storefront check: consumer-use wording found (gate switched off)',
            lines: [
                `${ plural( block, 'storefront item presents', 'storefront items present' ) } the products for consumer use (for example weight-loss, skincare, nasal-spray, capsule, syringe or dosing wording)${ warn > 0 ? `, and ${ plural( warn, 'item needs', 'items need' ) } review` : '' }. Listings and outreach describe the business as a research-use-only supplier of laboratory reagents, so this contradicts them.`,
                LEGAL_NOTE,
                paused
                    ? 'While this blocks, automated submissions and outreach sending are paused. Drafting, packaging and owner tasks continue so you can review them. Change the storefront and re-check, or acknowledge these exact findings; any change in the findings pauses automation again.'
                    : 'The storefront gate is switched off in the settings, so automation is not paused.',
            ],
            canAcknowledge: true,
            canRecheck: true,
            recheckLabel: 'Re-check now',
            paused,
        };
    }
    if ( effective === 'warn' ) {
        return {
            show: true,
            kind: 'warning',
            title: `Storefront check: ${ plural( warn, 'item', 'items' ) } to review`,
            lines: [
                `${ plural( warn, 'storefront item uses', 'storefront items use' ) } wording that can read as consumer use (for example recovery, growth or cognitive). Nothing is paused.`,
                LEGAL_NOTE,
            ],
            canAcknowledge: true,
            canRecheck: true,
            recheckLabel: 'Re-check now',
            paused: false,
        };
    }
    // Unknown: never run, could not run or the status could not be loaded.
    const paused = Boolean( s && s.blocking );
    return {
        show: true,
        kind: paused ? 'danger' : 'info',
        title: paused ? 'Storefront check could not run: automated submissions and outreach sending are paused' : 'Storefront check has not run yet',
        lines: [
            error !== '' ? error : ( s ? 'The storefront has not been checked yet.' : 'The storefront compliance status could not be loaded.' ),
            'The check reads product categories, tags, product titles, short descriptions and menus for consumer-use wording.',
        ],
        canAcknowledge: false,
        canRecheck: true,
        recheckLabel: s && s.checked_at ? 'Re-check now' : 'Run the check now',
        paused,
    };
}

function safeHref( url ) {
    const href = String( url || '' );
    return /^https?:\/\//i.test( href ) ? href : '';
}

function findingRow( f ) {
    if ( ! f || typeof f !== 'object' ) {
        return null;
    }
    const href = safeHref( f.url );
    const where = WHERE_LABELS[ f.where ] || String( f.where || '' );
    return el( 'li', { class: 'ppclg-compliance-finding' },
        badge( f.severity === 'block' ? 'Blocks' : 'Review', f.severity === 'block' ? 'danger' : 'warning' ),
        ' ',
        el( 'strong', {}, `“${ String( f.term || '' ) }”` ),
        ` in ${ where.toLowerCase() } `,
        href !== ''
            ? el( 'a', { href, target: '_blank', rel: 'noopener noreferrer', title: 'Edit in WordPress (opens a new tab)' }, String( f.label || '(no title)' ) )
            : el( 'span', {}, String( f.label || '(no title)' ) ),
        f.excerpt && String( f.excerpt ) !== String( f.label || '' ) ? el( 'div', { class: 'ppclg-muted ppclg-compliance-excerpt' }, String( f.excerpt ) ) : null,
    );
}

function findingsList( status ) {
    const all = Array.isArray( status.findings ) ? status.findings : ( Array.isArray( status.top ) ? status.top : [] );
    if ( all.length === 0 ) {
        return null;
    }
    const top = all.slice( 0, 5 );
    const rest = all.slice( 5 );
    const total = num( status.total ) || all.length;
    return el( 'div', { class: 'ppclg-compliance-findings' },
        el( 'ul', { class: 'ppclg-compliance-list' }, top.map( findingRow ) ),
        rest.length > 0
            ? details( `Show all ${ fmtNumber( all.length ) } findings`, el( 'ul', { class: 'ppclg-compliance-list' }, rest.map( findingRow ) ) )
            : null,
        total > all.length ? el( 'div', { class: 'ppclg-muted' }, `Showing the first ${ fmtNumber( all.length ) } of ${ fmtNumber( total ) } findings.` ) : null,
    );
}

/**
 * @param {HTMLElement} container
 * @param {object|null} status GET /compliance/storefront response.
 * @param {{onRecheck?: function, onAcknowledge?: function}} options
 */
export default function renderComplianceBanner( container, status, { onRecheck, onAcknowledge } = {} ) {
    if ( ! container ) {
        return;
    }
    const model = bannerModel( status );
    if ( ! model.show ) {
        replace( container );
        return;
    }
    const s = status && typeof status === 'object' ? status : {};
    const rerender = ( next ) => renderComplianceBanner( container, next, { onRecheck, onAcknowledge } );

    const recheck = async () => {
        let res;
        try {
            res = await api.post( '/compliance/storefront/recheck', {}, { silent: true } );
        } catch ( e ) {
            toast( e && e.message ? e.message : 'The storefront check could not run.', 'error' );
            return;
        }
        if ( ! res || res.ok !== true ) {
            toast( res && res.message ? res.message : 'The storefront check did not report a result.', 'error' );
            return;
        }
        toast( res.message || 'Storefront checked.', res.effective === 'block' ? 'warning' : 'success', { timeout: 7000 } );
        rerender( res );
        if ( typeof onRecheck === 'function' ) {
            onRecheck( res );
        }
    };

    const acknowledge = async () => {
        const counts = s.counts || {};
        const n = num( counts.block ) + num( counts.warn );
        const ok = await confirm(
            `You are recording that you reviewed these ${ plural( n, 'finding', 'findings' ) } and decided, as the owner and with your regulatory counsel, how the store presents itself. Automated submissions and outreach sending resume for exactly these findings; any change in the findings pauses them again. The store itself is not changed.`,
            { title: 'Acknowledge storefront findings', confirmText: 'Acknowledge', danger: true },
        );
        if ( ! ok ) {
            return;
        }
        let res;
        try {
            res = await api.post( '/compliance/storefront/acknowledge', { confirm: true, hash: typeof s.hash === 'string' ? s.hash : '' }, { silent: true } );
        } catch ( e ) {
            toast( e && e.message ? e.message : 'The acknowledgement could not be saved.', 'error' );
            return;
        }
        if ( ! res || res.ok !== true ) {
            toast( res && res.message ? res.message : 'The acknowledgement was not recorded.', 'error' );
            if ( res && typeof res === 'object' && res.effective ) {
                rerender( res );
            }
            return;
        }
        toast( res.message || 'Findings acknowledged.', 'success', { timeout: 7000 } );
        rerender( res );
        if ( typeof onAcknowledge === 'function' ) {
            onAcknowledge( res );
        }
    };

    const actions = [];
    if ( model.canRecheck ) {
        actions.push( button( model.recheckLabel, { kind: model.kind === 'danger' ? 'primary' : 'secondary', small: true, iconName: 'refresh', onClick: recheck } ) );
    }
    if ( model.canAcknowledge ) {
        actions.push( button( 'Acknowledge these findings', { small: true, iconName: 'shield', onClick: acknowledge } ) );
    }

    const meta = s.checked_at
        ? el( 'div', { class: 'ppclg-muted' }, 'Last checked ', timeEl( s.checked_at ), s.stale ? ' (the store changed since; it is re-checked before automation relies on it)' : '' )
        : null;

    replace( container, callout(
        model.kind,
        model.title,
        [
            model.lines.map( ( line ) => el( 'p', {}, line ) ),
            findingsList( s ),
            meta,
        ],
        { actions, iconName: 'shield' },
    ) );
}
