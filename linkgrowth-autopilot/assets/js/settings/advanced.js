/**
 * Settings tab "Advanced" (advanced): logging and retention, crawler, database backups, system and the danger zone.
 * The Settings page (pages/settings.js) renders it from lga-config.settingsTabs; `tab` carries the page's shared
 * state and helpers (bind() edits the draft the page saves with every other tab's changes).
 */

import * as api from '../api.js';
import {
    el, badge, callout, card, dataTable, fmtBytes, fmtDate, isObject, kv, linkButton,
} from '../ui.js';

export default function render( tab ) {
    const { ctx, state, bind, grid } = tab;
    const obj = ( v ) => ( isObject( v ) ? v : {} );
    const arr = ( v ) => ( Array.isArray( v ) ? v : [] );
    const d = obj( state.data );
    const backups = arr( d.backups );
    return el( 'div', { class: 'lga-stack' },
        card( { title: 'Logging & retention', body: grid( [
            bind( 'advanced.log_level', { type: 'select', label: 'Log level', options: { debug: 'Debug (verbose)', info: 'Info', warning: 'Warning', error: 'Error' } } ),
            bind( 'advanced.log_retention_days', { type: 'number', label: 'Keep logs for (days)', min: 1, default: 30 } ),
            bind( 'advanced.audit_retention_days', { type: 'number', label: 'Keep audit entries for (days)', min: 30, default: 365 } ),
        ], 3 ) } ),
        card( { title: 'Crawler', subtitle: 'The crawler honours robots.txt and never bypasses CAPTCHAs or logins', body: grid( [
            bind( 'advanced.user_agent', { type: 'text', label: 'User agent', full: true, hint: 'Identify the bot honestly and include a contact URL.' } ),
            bind( 'advanced.request_timeout', { type: 'number', label: 'Request timeout (s)', min: 3, max: 25, default: 15 } ),
            bind( 'advanced.crawl_delay_seconds', { type: 'number', label: 'Delay between requests to one host (s)', min: 0, max: 30, default: 2 } ),
            bind( 'advanced.max_response_kb', { type: 'number', label: 'Max response size (KB)', min: 128, max: 8192, default: 1536 } ),
        ] ) } ),
        card( { title: 'Database backups', subtitle: 'Created automatically before migrations', flush: true, body: dataTable( {
            dense: true,
            plain: true,
            rows: backups,
            empty: { title: 'No backups yet', compact: true },
            columns: [
                { key: 'file', label: 'File', render: ( b ) => el( 'span', { class: 'lga-mono' }, b.file ) },
                { key: 'size', label: 'Size', align: 'right', render: ( b ) => fmtBytes( b.size ) },
                { key: 'created', label: 'Created', render: ( b ) => fmtDate( b.created ) },
                { key: 'download', label: 'Download', hideLabel: true, align: 'right', render: ( b ) => ( /^[\w-]+\.sql\.gz$/.test( String( b.file || '' ) ) ? linkButton( 'Download', api.fileUrl( `/settings/backups/${ encodeURIComponent( b.file ) }` ), { small: true, iconName: 'download', control: 'backup-download' } ) : null ) },
            ],
        } ) } ),
        card( { title: 'System', body: kv( [
            [ 'Plugin version', ctx.config.version || '—' ],
            [ 'Database schema', d.db_version || '—' ],
            [ 'Encryption keys', d.crypto_strong ? badge( 'Strong', 'success', { dot: true } ) : badge( 'Weak / default salts', 'danger', { dot: true } ) ],
            [ 'REST root', el( 'code', {}, api.restRoot() ) ],
        ], { compact: true } ) } ),
        card( { title: 'Danger zone', body: [
            bind( 'advanced.delete_on_uninstall', { type: 'checkbox', label: 'Delete all plugin data (tables, options, secrets, backups) when the plugin is uninstalled' } ).el,
            callout( 'danger', 'Irreversible', 'With this enabled, deleting the plugin from the Plugins screen permanently removes every prospect, submission, message, backlink record and setting. Leave it off to keep data for reinstallation.' ),
        ] } ),
    );
}
