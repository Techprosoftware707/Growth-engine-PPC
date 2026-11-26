/**
 * Settings tab "Integrations" (integrations): SEO data providers, Google, search providers, AI writer, email, RDAP and inbound replies.
 * The Settings page (pages/settings.js) renders it from lga-config.settingsTabs; `tab` carries the page's shared
 * state and helpers (bind() edits the draft the page saves with every other tab's changes).
 */

import * as api from '../api.js';
import {
    el, replace, badge, button, callout, card, controlId, copyButton, confirm, details, getPath, input, isObject, kv, labelize, textarea,
} from '../ui.js';

export default function render( tab ) {
    const { state, bind, grid, markDirty, providerBadge } = tab;
    const obj = ( v ) => ( isObject( v ) ? v : {} );
    /** One write-only secret (Support\Secrets): its controls are `secret-<key>-edit|clear|undo|cancel|input`. */
    function secretField( key, { label, multiline = false, hint = '' } = {} ) {
        const info = obj( obj( obj( state.data ).secrets )[ key ] );
        const ctl = ( part ) => controlId( 'secret', key, part );
        const title = label || info.label || labelize( key );
        const host = el( 'div', { class: 'lga-field' } );
        const render = () => {
            const pending = state.pendingSecrets[ key ];
            const rows = [ el( 'span', { class: 'lga-field-label' }, title ) ];
            if ( info.locked ) {
                rows.push( el( 'div', { class: 'lga-secret' }, el( 'span', { class: 'lga-secret-masked' }, info.masked || 'configured' ), badge( 'Defined in wp-config.php', 'info', { iconName: 'lock' } ) ) );
            } else if ( pending === '__clear__' ) {
                rows.push( el( 'div', { class: 'lga-secret' }, badge( 'Will be removed on save', 'danger' ), button( 'Undo', { small: true, kind: 'ghost', control: ctl( 'undo' ), onClick: () => {
                    delete state.pendingSecrets[ key ];
                    markDirty();
                    render();
                } } ) ) );
            } else if ( pending !== undefined ) {
                const field = ( multiline ? textarea : input )( { type: multiline ? null : 'password', class: multiline ? 'lga-textarea lga-textarea--mono' : 'lga-input', rows: 6, value: pending, autocomplete: 'new-password', 'aria-label': title, placeholder: multiline ? '{ "type": "service_account", … }' : 'Paste the new value', control: ctl( 'input' ) } );
                field.addEventListener( 'input', () => {
                    if ( field.value === '' ) {
                        delete state.pendingSecrets[ key ];
                    } else {
                        state.pendingSecrets[ key ] = field.value;
                    }
                    markDirty();
                } );
                rows.push( el( 'div', { class: 'lga-secret' }, el( 'div', { class: 'lga-grow' }, field ), button( 'Cancel', { small: true, kind: 'ghost', control: ctl( 'cancel' ), onClick: () => {
                    delete state.pendingSecrets[ key ];
                    markDirty();
                    render();
                } } ) ) );
                requestAnimationFrame( () => field.focus() );
            } else {
                rows.push( el( 'div', { class: 'lga-secret' },
                    el( 'span', { class: 'lga-secret-masked' }, info.configured ? ( info.masked || '••••••••' ) : 'Not set' ),
                    info.configured ? badge( 'Configured', 'success', { dot: true } ) : null,
                    button( info.configured ? 'Replace' : 'Set', { small: true, iconName: 'edit', control: ctl( 'edit' ), onClick: () => {
                        state.pendingSecrets[ key ] = '';
                        render();
                        delete state.pendingSecrets[ key ];
                    } } ),
                    info.configured ? button( 'Clear', { small: true, kind: 'danger', control: ctl( 'clear' ), onClick: async () => {
                        if ( await confirm( `Remove the stored ${ title }? The integration stops working until a new value is saved.`, { danger: true, confirmText: 'Remove on save', control: ctl( 'clear-confirm' ) } ) ) {
                            state.pendingSecrets[ key ] = '__clear__';
                            markDirty();
                            render();
                        }
                    } } ) : null,
                ) );
            }
            if ( hint ) {
                rows.push( el( 'div', { class: 'lga-field-hint' }, hint ) );
            }
            replace( host, rows );
        };
        render();
        return host;
    }

    /** "Test connection" of one provider (control `test-<provider>`; its result's details `test-<provider>-details`). */
    function testButton( provider, label = 'Test connection' ) {
        const result = el( 'span', { class: 'lga-row' } );
        const btn = button( label, { small: true, iconName: 'activity', control: controlId( 'test', provider ), onClick: async () => {
            replace( result );
            let res;
            try {
                res = await api.post( '/settings/test', { provider }, { silent: true } );
            } catch ( e ) {
                replace( result, badge( 'Failed', 'danger', { dot: true } ), el( 'span', { class: 'lga-small' }, e && e.message ? e.message : '' ) );
                return;
            }
            const ok = Boolean( res && res.ok );
            replace( result, badge( ok ? 'OK' : 'Failed', ok ? 'success' : 'danger', { dot: true } ), el( 'span', { class: 'lga-small' }, String( ( res && res.message ) || '' ) ), res && isObject( res.data ) && Object.keys( res.data ).length ? details( 'Details', kv( Object.entries( res.data ).filter( ( [ , v ] ) => typeof v !== 'object' ).map( ( [ k, v ] ) => [ labelize( k ), String( v ) ] ), { compact: true } ), { control: controlId( 'test', provider, 'details' ) } ) : null );
        } } );
        return el( 'div', { class: 'lga-row' }, btn, result, el( 'span', { class: 'lga-notice-inline' }, 'Tests use saved values.' ) );
    }

    const providers = obj( obj( state.data ).providers );
    const s = state.draft;
    const has = ( path ) => getPath( s, path ) !== undefined;
    const inboundUrl = api.buildUrl( '/inbound' );
    // URL forms for providers that can only be given a URL (the server also reads ?secret= and the Basic auth password).
    const inboundQueryUrl = api.buildUrl( '/inbound', { secret: 'SECRET' } );
    let inboundBasicUrl;
    try {
        const u = new URL( inboundUrl );
        u.username = 'lga';
        u.password = 'SECRET';
        inboundBasicUrl = u.toString();
    } catch ( e ) {
        inboundBasicUrl = inboundUrl.replace( /^(https?:\/\/)/i, '$1lga:SECRET@' );
    }
    const urlRow = ( url, control ) => el( 'div', { class: 'lga-row' }, el( 'code', { class: 'lga-secret-masked', style: { flex: '1 1 auto' } }, url ), copyButton( url, 'Copy', { control } ) );
    return el( 'div', { class: 'lga-stack' },
        ! obj( state.data ).crypto_strong ? callout( 'warning', 'Weak encryption keys', 'AUTH_KEY / AUTH_SALT in wp-config.php look like defaults. Secrets are encrypted with them; generate strong salts before storing API keys.' ) : null,
        el( 'div', { class: 'lga-grid lga-grid--2' },
            card( { title: 'SE Ranking', subtitle: 'Domain authority, traffic, keywords, competitors and backlinks (primary provider)', actions: providerBadge( providers.seranking ), body: el( 'div', { class: 'lga-stack' },
                bind( 'providers.seranking.enabled', { type: 'toggle', label: 'Enabled' } ).el,
                secretField( 'seranking_api_key' ),
                grid( [
                    bind( 'providers.seranking.region', { type: 'text', label: 'Region / search engine', hint: 'e.g. ca, us, uk' } ),
                    bind( 'providers.seranking.cache_days', { type: 'number', label: 'Cache days', min: 1, default: 30 } ),
                    has( 'providers.seranking.fetch_overview' ) ? bind( 'providers.seranking.fetch_overview', { type: 'toggle', label: 'Fetch domain overview' } ) : null,
                    has( 'providers.seranking.fetch_summary' ) ? bind( 'providers.seranking.fetch_summary', { type: 'toggle', label: 'Fetch backlink summary' } ) : null,
                ].filter( Boolean ) ),
                testButton( 'seranking' ),
            ) } ),
            card( { title: 'Ahrefs', subtitle: 'Domain rating and backlink export', actions: providerBadge( providers.ahrefs ), body: el( 'div', { class: 'lga-stack' },
                bind( 'providers.ahrefs.enabled', { type: 'toggle', label: 'Enabled' } ).el,
                secretField( 'ahrefs_api_key' ),
                grid( [
                    bind( 'providers.ahrefs.cache_days', { type: 'number', label: 'Cache days', min: 1, default: 30 } ),
                    has( 'providers.ahrefs.fetch_metrics' ) ? bind( 'providers.ahrefs.fetch_metrics', { type: 'toggle', label: 'Fetch domain metrics' } ) : null,
                ].filter( Boolean ) ),
                testButton( 'ahrefs' ),
            ) } ),
            card( { title: 'Semrush', subtitle: 'Authority score and organic traffic', actions: providerBadge( providers.semrush ), body: el( 'div', { class: 'lga-stack' },
                bind( 'providers.semrush.enabled', { type: 'toggle', label: 'Enabled' } ).el,
                secretField( 'semrush_api_key' ),
                grid( [
                    bind( 'providers.semrush.database', { type: 'text', label: 'Database', hint: 'e.g. ca, us' } ),
                    bind( 'providers.semrush.cache_days', { type: 'number', label: 'Cache days', min: 1, default: 30 } ),
                ] ),
                testButton( 'semrush' ),
            ) } ),
            card( { title: 'Moz', subtitle: 'Domain authority and spam score', actions: providerBadge( providers.moz ), body: el( 'div', { class: 'lga-stack' },
                bind( 'providers.moz.enabled', { type: 'toggle', label: 'Enabled' } ).el,
                secretField( 'moz_access_id' ),
                secretField( 'moz_secret_key' ),
                grid( [ bind( 'providers.moz.cache_days', { type: 'number', label: 'Cache days', min: 1, default: 30 } ) ] ),
                testButton( 'moz' ),
            ) } ),
        ),
        card( { title: 'Google Search Console & Analytics 4', subtitle: 'Service account for background use; Site Kit is used as a dashboard fallback when active', actions: el( 'span', { class: 'lga-row' }, providerBadge( providers.google_search_console ), providerBadge( providers.google_analytics_4 ) ), body: el( 'div', { class: 'lga-stack' },
            bind( 'providers.google.enabled', { type: 'toggle', label: 'Enabled' } ).el,
            secretField( 'google_service_account_json', { multiline: true, hint: 'Paste the JSON key of a service account that has been added as a user on the Search Console property and the GA4 property.' } ),
            grid( [
                bind( 'providers.google.gsc_property', { type: 'text', label: 'Search Console property', hint: 'sc-domain:example.com or https://example.com/' } ),
                bind( 'providers.google.ga4_property_id', { type: 'text', label: 'GA4 property ID', hint: 'Numeric, e.g. 123456789' } ),
                bind( 'providers.google.use_sitekit', { type: 'toggle', label: 'Use Site Kit data when available', hint: providers.site_kit && providers.site_kit.configured ? 'Site Kit is active on this site.' : 'Site Kit is not active.' } ),
            ] ),
            el( 'div', { class: 'lga-row' }, testButton( 'google_search_console', 'Test Search Console' ), testButton( 'google_analytics_4', 'Test GA4' ), testButton( 'sitekit', 'Test Site Kit bridge' ) ),
        ) } ),
        card( { title: 'Search providers', subtitle: 'Used for discovery queries and listing verification', body: el( 'div', { class: 'lga-stack' },
            grid( [ bind( 'providers.search.primary', { type: 'select', label: 'Primary provider', options: { auto: 'Automatic (most remaining quota)', google_cse: 'Google Custom Search', brave: 'Brave Search' } } ) ] ),
            el( 'div', { class: 'lga-grid lga-grid--2' },
                card( { flat: true, title: 'Google Custom Search', actions: providerBadge( providers.google_cse ), body: el( 'div', { class: 'lga-stack' }, secretField( 'google_cse_api_key' ), secretField( 'google_cse_cx' ), bind( 'providers.search.google_cse_daily_limit', { type: 'number', label: 'Daily query cap', min: 0, default: 90 } ).el, testButton( 'google_cse' ) ) } ),
                card( { flat: true, title: 'Brave Search', actions: providerBadge( providers.brave ), body: el( 'div', { class: 'lga-stack' }, secretField( 'brave_api_key' ), bind( 'providers.search.brave_daily_limit', { type: 'number', label: 'Daily query cap', min: 0, default: 60 } ).el, testButton( 'brave' ) ) } ),
            ),
        ) } ),
        el( 'div', { class: 'lga-grid lga-grid--2' },
            card( { title: 'AI writer (optional)', subtitle: 'Rewrites descriptions and outreach openers; every output still passes the claims filter', actions: providerBadge( providers.anthropic ), body: el( 'div', { class: 'lga-stack' },
                bind( 'providers.ai.enabled', { type: 'toggle', label: 'Enabled' } ).el,
                secretField( 'anthropic_api_key' ),
                grid( [
                    bind( 'providers.ai.model', { type: 'text', label: 'Model', hint: 'Required: the model id from your Anthropic account. No model is set by default.' } ),
                    bind( 'providers.ai.max_tokens', { type: 'number', label: 'Max tokens', min: 100, max: 8000, default: 1200 } ),
                ] ),
                testButton( 'anthropic' ),
            ) } ),
            card( { title: 'Email, RDAP & inbound replies', body: el( 'div', { class: 'lga-stack' },
                bind( 'providers.rdap.enabled', { type: 'toggle', label: 'RDAP domain age lookups', hint: 'Free registry lookups used for the young-domain risk flag.' } ).el,
                el( 'div', {}, el( 'div', { class: 'lga-field-label' }, 'Outgoing email' ), el( 'div', { class: 'lga-field-hint', style: { marginBottom: '6px' } }, 'Sends a test message to your account address through wp_mail() (WP Mail SMTP).' ), testButton( 'email', 'Send test email' ) ),
                secretField( 'inbound_webhook_secret', { hint: 'Shared secret your inbound-email provider sends with every request: in the X-LGA-Secret header, a “secret” body field, or the webhook URL (see below).' } ),
                el( 'div', {}, el( 'div', { class: 'lga-field-label' }, 'Inbound webhook URL' ), urlRow( inboundUrl, 'copy-inbound-url' ), el( 'div', { class: 'lga-field-hint' }, 'POST JSON with from, subject, text, in_reply_to / message_id. Replies are matched to sent messages and logged.' ) ),
                el( 'div', {},
                    el( 'div', { class: 'lga-field-label' }, 'Sending the secret' ),
                    el( 'div', { class: 'lga-field-hint' }, 'Preferred: the header ', el( 'code', {}, 'X-LGA-Secret: SECRET' ), ' (or a “secret” field in the body). If your provider can only be given a URL, use one of these forms instead, with SECRET replaced by your secret. For these URL forms, use a secret of only letters and digits, or percent-encode any other character (such as + & # % @ : / ?); otherwise the request fails. A secret in a URL can end up in server and proxy logs, so use the header when you can.' ),
                    urlRow( inboundQueryUrl, 'copy-inbound-query-url' ),
                    urlRow( inboundBasicUrl, 'copy-inbound-basic-url' ),
                ),
            ) } ),
        ),
    );
}
