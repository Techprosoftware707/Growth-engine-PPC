/**
 * Settings tab "Outreach & compliance" (outreach): sender identity, approval and pacing, send window and the storefront compliance gate.
 * The Settings page (pages/settings.js) renders it from lga-config.settingsTabs; `tab` carries the page's shared
 * state and helpers (bind() edits the draft the page saves with every other tab's changes).
 */

import {
    el, card, callout, checkbox, controlId, getPath, setPath,
} from '../ui.js';

const DAYS = [ [ 1, 'Mon' ], [ 2, 'Tue' ], [ 3, 'Wed' ], [ 4, 'Thu' ], [ 5, 'Fri' ], [ 6, 'Sat' ], [ 7, 'Sun' ] ];

export default function render( tab ) {
    const { state, bind, grid, markDirty, sendZoneText } = tab;
    const arr = ( v ) => ( Array.isArray( v ) ? v : [] );
    const days = arr( getPath( state.draft, 'outreach.send_days', [ 1, 2, 3, 4, 5 ] ) ).map( Number );
    const dayGroup = el( 'div', { class: 'lga-checkgroup' }, DAYS.map( ( [ n, label ] ) => {
        const cb = checkbox( { checked: days.includes( n ), control: controlId( 'send-day', label ) } );
        cb.addEventListener( 'change', () => {
            const current = new Set( arr( getPath( state.draft, 'outreach.send_days', [] ) ).map( Number ) );
            if ( cb.checked ) {
                current.add( n );
            } else {
                current.delete( n );
            }
            setPath( state.draft, 'outreach.send_days', Array.from( current ).sort( ( a, b ) => a - b ) );
            markDirty();
        } );
        return el( 'label', { class: 'lga-checkbox' }, cb, el( 'span', {}, label ) );
    } ) );
    return el( 'div', { class: 'lga-stack' },
        callout( 'info', 'CASL / CAN-SPAM checklist', el( 'ul', {}, [
            'Every message identifies the sender (name, working email, postal address) and carries a one-click unsubscribe.',
            'Only addresses conspicuously published for business enquiries are contacted (implied consent under CASL s.10(9)(b)); opt-outs are honoured within one business day and permanently suppressed.',
            'Messages contain no dosing, therapeutic or unverified claims; the claims filter blocks them before approval.',
            'Each contact receives at most the configured number of follow-ups and is not re-contacted within the cool-down period.',
        ].map( ( t ) => el( 'li', {}, t ) ) ) ),
        card( { title: 'Sender identity', subtitle: 'Required before any email can be sent', body: grid( [
            bind( 'outreach.sender_name', { type: 'text', label: 'Sender name', required: true } ),
            bind( 'outreach.sender_email', { type: 'email', label: 'Sender email', required: true, hint: 'Ideally on the site domain for deliverability.' } ),
            bind( 'outreach.reply_to', { type: 'email', label: 'Reply-To (optional)' } ),
            bind( 'outreach.postal_address', { type: 'textarea', label: 'Postal address (printed in every message)', rows: 2, required: true } ),
            bind( 'outreach.signature', { type: 'textarea', label: 'Signature', rows: 3, full: true, hint: 'Plain text; appears above the compliance footer.' } ),
        ] ) } ),
        card( { title: 'Approval & pacing', body: grid( [
            bind( 'outreach.require_approval', { type: 'toggle', label: 'Require individual approval for every message', hint: 'Recommended unless you use Autopilot. While on, automation never approves a message; only follow-ups in campaigns that opted in to auto-approval can send automatically, and only with Outreach autosend on. Autopilot (Automation page) overrides this setting while it is on.' } ),
            bind( 'outreach.follow_up_days', { type: 'number', label: 'Days before a follow-up', min: 1, default: 7 } ),
            bind( 'outreach.max_follow_ups', { type: 'number', label: 'Maximum follow-ups', min: 0, max: 3, default: 1 } ),
            bind( 'outreach.min_days_between_contacts', { type: 'number', label: 'Cool-down between contacts (days)', min: 1, default: 90 } ),
        ] ) } ),
        card( { title: 'Send window', subtitle: sendZoneText( state.data && state.data.send_timezone ), body: [
            grid( [
                bind( 'outreach.send_window_start', { type: 'number', label: 'Start hour (0–23)', min: 0, max: 23, default: 9 } ),
                bind( 'outreach.send_window_end', { type: 'number', label: 'End hour (1–24)', min: 1, max: 24, default: 17 } ),
                bind( 'outreach.timezone', { type: 'text', label: 'Time zone (IANA, optional)', placeholder: 'America/Toronto', hint: 'Leave empty to use the WordPress time zone; when WordPress is set to UTC for a Canadian business, the zone of the province under Settings → Business is used.' } ),
            ] ),
            el( 'div', { class: 'lga-field', style: { marginTop: '12px' } }, el( 'span', { class: 'lga-field-label' }, 'Send days' ), dayGroup ),
        ] } ),
        card( { title: 'Storefront compliance gate', subtitle: 'Listings and outreach describe a research-use-only supplier', body: [
            bind( 'compliance.storefront_gate', { type: 'toggle', default: true, label: 'Pause automated submissions and outreach sending while the storefront check blocks', hint: 'The check reads product categories, tags, product titles and menus for consumer-use wording. You can acknowledge its current findings on the Overview page instead of turning the gate off; any change in the findings pauses again. Turning the gate off asks for confirmation.' } ).el,
        ] } ),
    );
}
