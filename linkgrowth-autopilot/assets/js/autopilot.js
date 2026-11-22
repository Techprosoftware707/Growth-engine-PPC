/**
 * Autopilot: the one switch that lets the engine approve and send without asking first (GET/POST
 * /automation/autopilot). Shown on the Overview and Automation pages. Turning it on asks for explicit
 * confirmation with the policy text and the hard limits; the server records the consent. Turning it off
 * returns what Autopilot approved but did not send yet to draft.
 */

import * as api from './api.js';
import { el, card, switchRow, badge, callout, confirm, toast, isObject, fmtDate } from './ui.js';

const obj = ( v ) => ( isObject( v ) ? v : {} );
const arr = ( v ) => ( Array.isArray( v ) ? v : [] );

/**
 * Plain lines describing what Autopilot does right now. Exported for tests.
 *
 * @param {object} status GET /automation/autopilot
 * @return {{ on: boolean, headline: string, lines: string[] }}
 */
export function autopilotSummary( status ) {
    const s = obj( status );
    const on = s.enabled === true;
    const lines = [];
    if ( on ) {
        lines.push( 'Outreach drafts and your campaigns are approved automatically and sent in your name inside the send window and the daily and weekly limits.' );
        lines.push( 'Listings are submitted automatically to sites whose public listing form passes every safety check.' );
        const links = Number( obj( s.effective ).links_per_day ) || Number( obj( s.numbers ).links_per_day ) || 0;
        lines.push( `The best internal links between your own pages are added automatically${ links ? ` (up to ${ links } a day)` : '' }; “Undo automatic links” on the On-site SEO page removes them.` );
        if ( s.automation_enabled === false ) {
            lines.push( 'Background automation is switched off (Master switch below), so nothing runs on its own until you switch it back on.' );
        }
    } else {
        lines.push( 'Off: drafts, campaigns and listings wait for the approvals your Automation and Outreach settings ask for.' );
    }
    const follow = obj( s.follow_ups );
    if ( on && follow.note ) {
        lines.push( String( follow.note ) );
    }
    const consent = obj( s.consent );
    if ( on && consent.at ) {
        lines.push( `Switched on ${ fmtDate( consent.at ) }${ consent.user_id ? ` by user #${ consent.user_id }` : '' } (policy ${ consent.policy_version || s.policy_version || '' }).` );
    }
    if ( on && consent.at && s.consent_current === false ) {
        lines.push( 'It was switched on under an earlier version of the Autopilot text, which did not cover saving a page as your account: links on pages with inline SVG or structured data wait for you until you switch Autopilot off and on again.' );
    }
    return { on, headline: on ? 'Autopilot is on' : 'Autopilot is off', lines };
}

/**
 * What runs on its own once Autopilot is on, in plain words with the caps (server `will_run`, else built from
 * `numbers`). Exported for tests.
 *
 * @param {object} status GET /automation/autopilot
 * @return {string[]}
 */
export function willRunLines( status ) {
    const s = obj( status );
    const given = arr( s.will_run ).map( String ).filter( Boolean );
    if ( given.length ) {
        return given;
    }
    const n = obj( s.numbers );
    const num = ( v, d ) => ( Number.isFinite( Number( v ) ) ? Number( v ) : d );
    return [
        `Send outreach emails in your name: up to ${ num( n.emails_per_day, 10 ) } a day, only inside the send window, to the published business addresses of relevant websites.`,
        `Add up to ${ num( n.links_per_day, 20 ) } internal links a day between your own pages (WordPress keeps a revision of each page, and “Undo automatic links” removes them).`,
        `Submit listings to open public forms that allow it and pass every safety check (up to ${ num( n.submissions_per_day, 5 ) } a day).`,
        'Discover new prospects, evaluate them and monitor your backlinks on schedule.',
    ];
}

/**
 * Asks for confirmation and switches Autopilot. Resolves to the new status, or null when cancelled / refused.
 *
 * @param {boolean} next
 * @param {object}  status current status (policy text and hard limits)
 */
export async function setAutopilot( next, status ) {
    const s = obj( status );
    if ( next ) {
        const limits = arr( s.hard_limits );
        const runs = willRunLines( s );
        const ok = await confirm( String( s.policy_text || 'Turn on Autopilot? The engine then approves and sends outreach and submits listings without asking you first.' ), {
            title: 'Turn on Autopilot',
            confirmText: 'Turn on Autopilot',
            danger: true,
            details: el( 'div', { class: 'lga-autopilot-confirm' },
                el( 'p', { class: 'lga-strong' }, 'What runs automatically:' ),
                el( 'ul', { class: 'lga-list--bullets lga-autopilot-runs' }, runs.map( ( l ) => el( 'li', {}, l ) ) ),
                limits.length ? el( 'p', { class: 'lga-strong' }, 'These limits always stay on:' ) : null,
                limits.length ? el( 'ul', { class: 'lga-list--bullets lga-autopilot-limits' }, limits.map( ( l ) => el( 'li', {}, String( l ) ) ) ) : null,
                s.owner_tasks_note ? el( 'p', { class: 'lga-small' }, String( s.owner_tasks_note ) ) : null,
            ),
        } );
        if ( ! ok ) {
            return null;
        }
        let res;
        try {
            res = await api.post( '/automation/autopilot', { enabled: true, confirm: true } );
        } catch ( e ) {
            return null;
        }
        toast( 'Autopilot is on. The engine now approves and sends without asking you first.', 'success' );
        return res;
    }
    if ( ! ( await confirm( 'Turn off Autopilot? Automatic sending stops at once, and messages Autopilot approved but did not send yet go back to draft.', { title: 'Turn off Autopilot', confirmText: 'Turn off' } ) ) ) {
        return null;
    }
    let res;
    try {
        res = await api.post( '/automation/autopilot', { enabled: false } );
    } catch ( e ) {
        return null;
    }
    const reverted = Number( res && res.reverted ) || 0;
    toast( `Autopilot is off.${ reverted ? ` ${ reverted } approved message(s) went back to draft.` : '' }`, 'success' );
    return res;
}

/**
 * The Autopilot card: the switch, what it does now, the hard limits and the owner-task note.
 *
 * @param {object}   status   GET /automation/autopilot
 * @param {Function} onChange called with the new status after a successful switch
 */
export function autopilotCard( status, onChange ) {
    const s = obj( status );
    const sum = autopilotSummary( s );
    const limits = arr( s.hard_limits );
    return card( {
        title: 'Autopilot',
        subtitle: 'One switch for hands-off growth',
        actions: sum.on ? badge( 'On', 'success', { dot: true } ) : badge( 'Off', 'neutral', { dot: true } ),
        body: [
            switchRow( {
                label: 'Let the engine approve and send without asking me',
                description: sum.lines[ 0 ],
                checked: sum.on,
                badgeNode: badge( 'Confirmation required', 'warning' ),
                onChange: async ( next ) => {
                    const res = await setAutopilot( next, s );
                    if ( ! res ) {
                        return false;
                    }
                    if ( typeof onChange === 'function' ) {
                        onChange( res );
                    }
                    return true;
                },
            } ),
            sum.lines.length > 1 ? el( 'ul', { class: 'lga-list--bullets lga-small' }, sum.lines.slice( 1 ).map( ( l ) => el( 'li', {}, l ) ) ) : null,
            limits.length ? el( 'details', { class: 'lga-details' }, el( 'summary', {}, 'Limits that always stay on' ), el( 'ul', { class: 'lga-list--bullets lga-small' }, limits.map( ( l ) => el( 'li', {}, String( l ) ) ) ) ) : null,
            s.owner_tasks_note ? callout( 'info', null, String( s.owner_tasks_note ) ) : null,
        ],
    } );
}
