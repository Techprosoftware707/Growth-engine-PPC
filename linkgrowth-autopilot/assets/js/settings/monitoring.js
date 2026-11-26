/**
 * Settings tab "Monitoring" (monitoring): check cadence and notifications.
 * The Settings page (pages/settings.js) renders it from lga-config.settingsTabs; `tab` carries the page's shared
 * state and helpers (bind() edits the draft the page saves with every other tab's changes).
 */

import {
    el, card,
} from '../ui.js';

export default function render( tab ) {
    const { bind, grid } = tab;
    return el( 'div', { class: 'lga-stack' },
        card( { title: 'Check cadence', body: grid( [
            bind( 'monitoring.check_interval_days_new', { type: 'number', label: 'Re-check new links every (days)', min: 1, default: 1, hint: 'Applies for the first 14 days after a link is found.' } ),
            bind( 'monitoring.check_interval_days_stable', { type: 'number', label: 'Re-check stable links every (days)', min: 1, default: 7 } ),
            bind( 'monitoring.lost_after_failures', { type: 'number', label: 'Mark lost after consecutive failures', min: 1, max: 10, default: 2 } ),
            bind( 'monitoring.import_interval_days', { type: 'number', label: 'Provider import every (days)', min: 1, default: 7 } ),
            bind( 'monitoring.import_limit', { type: 'number', label: 'Max records per import', min: 50, max: 5000, default: 500 } ),
        ] ) } ),
        card( { title: 'Notifications', body: grid( [
            bind( 'monitoring.notify_email', { type: 'email', label: 'Notification email', hint: 'Defaults to the site admin email when empty.' } ),
            bind( 'monitoring.digest_email', { type: 'toggle', label: 'Daily digest email', hint: 'One summary of unread alerts per day.' } ),
            bind( 'monitoring.notify_critical_immediately', { type: 'toggle', label: 'Email critical alerts immediately', hint: 'Lost high-value backlinks, failed submissions and bounces (throttled to one per title per 6 hours).' } ),
        ] ) } ),
    );
}
