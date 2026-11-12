/**
 * Overview page: hero stats, charts, integration health, automation status and notifications.
 */

import * as api from '../api.js';
import { el, replace, statTile, card, badge, button, linkButton, callout, kv, meter, loading, errorState, emptyState, timeEl, fmtNumber, fmtPercent, fmtCompact, labelize, pageHeader, statusBadge, truncate, fmtDate } from '../ui.js';
import { barChart, lineChart, histogram, segmentedBar } from '../charts.js';

function providerBadge( p ) {
    if ( ! p ) {
        return badge( 'Unknown', 'neutral' );
    }
    if ( p.error ) {
        return badge( 'Error', 'danger', { dot: true, title: String( p.error ) } );
    }
    if ( p.configured && p.enabled !== false ) {
        return badge( 'Ready', 'success', { dot: true } );
    }
    if ( p.configured ) {
        return badge( 'Disabled', 'neutral', { dot: true } );
    }
    return badge( 'Not configured', 'warning', { dot: true } );
}

function balanceRows( balance ) {
    if ( ! balance || typeof balance !== 'object' ) {
        return [];
    }
    const rows = [];
    for ( const [ key, value ] of Object.entries( balance ) ) {
        if ( value === null || typeof value === 'object' ) {
            continue;
        }
        rows.push( [ labelize( key ), typeof value === 'number' ? fmtNumber( value, { decimals: 2 } ) : String( value ) ] );
        if ( rows.length >= 8 ) {
            break;
        }
    }
    return rows;
}

export default async function render( root, ctx ) {
    const { config, shell } = ctx;
    replace( root, pageHeader( { title: 'Overview', description: `Link acquisition health for ${ config.siteDomain || config.brand }.` } ), loading( 'Loading overview…' ) );

    let data;
    try {
        data = await api.get( '/overview' );
    } catch ( e ) {
        replace( root, pageHeader( { title: 'Overview' } ), errorState( { title: 'The overview could not be loaded', message: e && e.message ? e.message : '', retry: () => render( root, ctx ) } ) );
        return;
    }

    const stats = ( data && data.stats ) || {};
    const charts = ( data && data.charts ) || {};
    const notifications = Array.isArray( data && data.notifications ) ? data.notifications : [];
    const providers = ( data && data.providers ) || {};
    const automation = ( data && data.automation ) || {};
    const scheduler = ( data && data.scheduler ) || {};
    const limits = ( data && data.limits ) || automation.limits || {};
    const missing = Array.isArray( data && data.missing_integrations ) ? data.missing_integrations : [];
    const balance = data ? data.seranking_balance : null;

    if ( stats.unread_notifications !== undefined ) {
        shell.setUnread( stats.unread_notifications );
    }
    if ( automation.automation_enabled !== undefined ) {
        shell.setAutomation( automation.automation_enabled );
    }

    const settingsUrl = ctx.pageUrl( 'settings', { tab: 'integrations' } );
    const responseRate = stats.outreach_response_rate;

    // ----- Tiles ------------------------------------------------------
    const tiles = el( 'div', { class: 'ppclg-grid ppclg-grid--tiles ppclg-section' },
        statTile( { label: 'Prospects', iconName: 'target', value: fmtNumber( stats.prospects_total ), hint: `${ fmtNumber( stats.prospects_qualified ) } qualified · ${ fmtNumber( stats.prospects_approved ) } approved`, href: ctx.pageUrl( 'prospects' ) } ),
        statTile( { label: 'Active backlinks', iconName: 'link', value: fmtNumber( stats.backlinks_active ), delta: Number( stats.backlinks_new_30d ) > 0 ? { value: stats.backlinks_new_30d, label: `+${ fmtNumber( stats.backlinks_new_30d ) } in 30d`, good: true, direction: 'up' } : null, hint: `${ fmtNumber( stats.backlinks_lost ) } lost`, href: ctx.pageUrl( 'backlinks', { status: 'active' } ) } ),
        statTile( { label: 'Referring domains', iconName: 'globe', value: fmtNumber( stats.referring_domains ), hint: `${ fmtNumber( stats.quality_domains !== undefined ? stats.quality_domains : stats.high_authority_domains ) } with quality score ≥ 60`, href: ctx.pageUrl( 'backlinks' ) } ),
        statTile( { label: 'Submissions', iconName: 'package', value: fmtNumber( stats.submissions_submitted ), hint: `${ fmtNumber( stats.submissions_confirmed ) } confirmed live`, href: ctx.pageUrl( 'submissions' ) } ),
        statTile( { label: 'Outreach sent', iconName: 'mail', value: fmtNumber( stats.outreach_sent ), hint: `${ fmtNumber( stats.outreach_replied ) } replies · ${ responseRate === null || responseRate === undefined ? '—' : fmtPercent( responseRate ) } response rate`, href: ctx.pageUrl( 'outreach', { tab: 'messages' } ) } ),
        statTile( { label: 'Search clicks (28d)', iconName: 'search', value: stats.gsc_clicks_28d === null || stats.gsc_clicks_28d === undefined ? '—' : fmtCompact( stats.gsc_clicks_28d ), hint: stats.gsc_clicks_28d === null || stats.gsc_clicks_28d === undefined ? 'Search Console not connected' : `${ fmtCompact( stats.gsc_impressions_28d ) } impressions · via ${ stats.gsc_source || 'service account' }` } ),
        statTile( { label: 'Referral sessions (28d)', iconName: 'activity', value: stats.referral_sessions_28d === null || stats.referral_sessions_28d === undefined ? '—' : fmtCompact( stats.referral_sessions_28d ), hint: stats.referral_source ? `Source: ${ stats.referral_source === 'ga4' ? 'Google Analytics 4' : 'Site Kit' }` : 'Analytics not connected' } ),
        statTile( { label: 'Unread alerts', iconName: 'bell', value: fmtNumber( stats.unread_notifications ), hint: 'Open the bell to review', onClick: () => shell.openNotifications() } ),
    );

    // ----- Charts -----------------------------------------------------
    const trend = Array.isArray( charts.backlink_trend ) ? charts.backlink_trend : [];
    const trendChart = lineChart( {
        title: 'Backlinks: new vs. lost',
        subtitle: 'Weekly, last 12 weeks',
        series: [
            { name: 'New', points: trend.map( ( r ) => ( { x: r.week, y: Number( r.new ) || 0 } ) ) },
            { name: 'Lost', points: trend.map( ( r ) => ( { x: r.week, y: Number( r.lost ) || 0 } ) ) },
        ],
        xFormat: ( x ) => String( x ),
        emptyMessage: 'No backlink history yet — run monitoring or an import.',
    } );
    const histChart = histogram( {
        title: 'Prospect score distribution',
        subtitle: '0–100 opportunity score',
        data: Array.isArray( charts.score_histogram ) ? charts.score_histogram : [],
        emptyMessage: 'Scores appear once prospects have been evaluated.',
    } );
    const oppChart = barChart( {
        title: 'Opportunity types',
        data: ( Array.isArray( charts.opportunity_types ) ? charts.opportunity_types : [] ).map( ( r ) => ( { label: r.label || labelize( r.type ), value: r.count } ) ).sort( ( a, b ) => b.value - a.value ),
        emptyMessage: 'No opportunities recorded yet.',
    } );
    const statusChart = barChart( {
        title: 'Prospects by status',
        data: ( Array.isArray( charts.status_counts ) ? charts.status_counts : [] ).map( ( r ) => ( { label: labelize( r.status ), value: r.count } ) ),
        emptyMessage: 'No prospects yet — run discovery.',
    } );
    const anchorChart = barChart( {
        title: 'Anchor text distribution',
        subtitle: 'Top anchors across active backlinks',
        data: ( Array.isArray( charts.anchor_distribution ) ? charts.anchor_distribution : [] ).map( ( r ) => ( { label: r.anchor === '' || r.anchor === null ? '(empty / image)' : truncate( r.anchor, 40 ), value: r.count } ) ),
        maxBars: 10,
        emptyMessage: 'Anchor texts appear once backlinks are verified.',
    } );
    const relChart = segmentedBar( {
        title: 'Link attributes',
        subtitle: 'dofollow vs. nofollow / sponsored / ugc',
        parts: ( Array.isArray( charts.rel_distribution ) ? charts.rel_distribution : [] ).map( ( r ) => ( { label: labelize( r.rel || 'unknown' ), value: r.count } ) ),
        emptyMessage: 'No verified backlinks yet.',
    } );

    const chartGrid = el( 'div', { class: 'ppclg-grid ppclg-grid--2' },
        card( { body: trendChart } ),
        card( { body: histChart } ),
        card( { body: oppChart } ),
        card( { body: statusChart } ),
        card( { body: anchorChart } ),
        card( { body: relChart } ),
    );

    // ----- Side column ------------------------------------------------
    const providerRows = Object.entries( providers ).map( ( [ id, p ] ) => el( 'div', { class: 'ppclg-health-row' },
        el( 'div', { class: 'ppclg-health-name' }, String( ( p && p.label ) || labelize( id ) ), el( 'span', { class: 'ppclg-health-kind' }, [ labelize( ( p && p.kind ) || '' ), p && p.remaining_today !== undefined ? `${ fmtNumber( p.remaining_today ) } lookups left today` : '', p && p.error ? truncate( String( p.error ), 70 ) : '' ].filter( Boolean ).join( ' · ' ) ) ),
        providerBadge( p ),
        el( 'a', { href: settingsUrl, class: 'ppclg-small' }, 'Configure' ),
    ) );
    const healthCard = card( {
        title: 'Integration health',
        actions: linkButton( 'Settings', settingsUrl, { small: true, iconName: 'settings' } ),
        body: providerRows.length ? providerRows : emptyState( { title: 'No integrations reported', compact: true } ),
    } );

    const queue = automation.queue && typeof automation.queue === 'object' ? automation.queue : {};
    const automationCard = card( {
        title: 'Automation',
        actions: linkButton( 'Manage', ctx.pageUrl( 'automation' ), { small: true, iconName: 'zap' } ),
        body: [
            el( 'div', { class: 'ppclg-row', style: { marginBottom: '10px' } },
                automation.automation_enabled ? badge( 'Master switch on', 'success', { dot: true } ) : badge( 'Master switch off', 'warning', { dot: true } ),
                automation.locked ? badge( 'Runner busy', 'info', { dot: true } ) : null,
                scheduler.cron_disabled && scheduler.backend === 'wp_cron' ? badge( 'WP-Cron disabled', 'danger', { dot: true } ) : null,
            ),
            kv( [
                [ 'Scheduler', scheduler.backend === 'action_scheduler' ? 'Action Scheduler' : ( scheduler.backend === 'wp_cron' ? 'WP-Cron' : 'Not scheduled' ) ],
                [ 'Next run', scheduler.next_run ? timeEl( scheduler.next_run ) : '—' ],
                [ 'Last tick', automation.last_tick || scheduler.last_tick ? timeEl( automation.last_tick || scheduler.last_tick ) : 'Never' ],
                [ 'Queue', Object.keys( queue ).length ? el( 'span', { class: 'ppclg-row' }, Object.entries( queue ).map( ( [ s, n ] ) => badge( `${ labelize( s ) } ${ fmtNumber( n ) }`, s === 'failed' ? 'danger' : ( s === 'running' ? 'info' : 'neutral' ) ) ) ) : 'Empty' ],
            ], { compact: true } ),
        ],
    } );

    const limitRows = Object.entries( limits ).map( ( [ key, l ] ) => meter( { label: labelize( key ), used: l && l.used_today, limit: l && l.limit_today } ) );
    const limitsCard = card( { title: 'Daily limits', subtitle: 'Used today vs. configured caps', body: limitRows.length ? limitRows : emptyState( { title: 'No limits configured', compact: true } ) } );

    const balanceCard = balance ? card( { title: 'SE Ranking balance', subtitle: 'Cached hourly', body: balanceRows( balance ).length ? kv( balanceRows( balance ), { compact: true } ) : el( 'span', { class: 'ppclg-muted' }, 'Subscription details unavailable.' ) } ) : null;

    // ----- Notifications feed ----------------------------------------
    const feed = card( {
        title: 'Recent notifications',
        actions: [ button( 'Open all', { small: true, kind: 'ghost', iconName: 'bell', onClick: () => shell.openNotifications() } ) ],
        body: notifications.length ? el( 'div', { class: 'ppclg-list' }, notifications.slice( 0, 10 ).map( ( n ) => el( 'div', { class: [ 'ppclg-feed-item', Number( n.is_read ) === 0 ? 'ppclg-feed-item--unread' : '' ] },
            el( 'span', { class: [ 'ppclg-feed-dot', `ppclg-feed-dot--${ n.severity || 'info' }` ] } ),
            el( 'div', { class: 'ppclg-grow' },
                el( 'div', { class: 'ppclg-feed-title' }, String( n.title || n.type || 'Notification' ) ),
                n.message ? el( 'div', { class: 'ppclg-feed-msg' }, truncate( String( n.message ), 240 ) ) : null,
                el( 'div', { class: 'ppclg-feed-time' }, timeEl( n.created_at ), n.type ? [ ' · ', statusBadge( n.type, { dot: false } ) ] : null ),
            ),
        ) ) ) : emptyState( { title: 'No notifications yet', message: 'Alerts will appear here as the engine works.', iconName: 'bell', compact: true } ),
    } );

    // ----- Compose ----------------------------------------------------
    replace( root,
        pageHeader( {
            title: 'Overview',
            description: `Link acquisition health for ${ config.siteDomain || config.brand }. Data refreshed ${ fmtDate( new Date() ) }.`,
            actions: [ button( 'Refresh', { iconName: 'refresh', onClick: () => render( root, ctx ) } ) ],
        } ),
        missing.length ? callout( 'warning', 'Some integrations are not configured', el( 'ul', {}, missing.map( ( m ) => el( 'li', {}, String( m ) ) ) ), { actions: linkButton( 'Configure integrations', settingsUrl, { small: true, kind: 'primary' } ) } ) : null,
        ! automation.automation_enabled ? callout( 'info', 'Automation is switched off', 'Discovery, evaluation, monitoring and the submission/outreach queues only run while the master switch is on. Manual actions still work.', { actions: linkButton( 'Open automation', ctx.pageUrl( 'automation' ), { small: true } ) } ) : null,
        tiles,
        el( 'div', { class: 'ppclg-grid ppclg-grid--main-side' },
            el( 'div', { class: 'ppclg-stack' }, chartGrid, feed ),
            el( 'div', { class: 'ppclg-stack' }, healthCard, automationCard, limitsCard, balanceCard ),
        ),
    );
}
