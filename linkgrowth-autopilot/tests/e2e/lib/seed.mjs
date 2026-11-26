/**
 * REST seed of the E2E suite: after tests/e2e/setup.php reset the plugin, the administrator creates through lga/v1
 * (the same routes the dashboard uses) the demo business profile and the records every list, drawer and tab of the
 * dashboard needs, against the neutral fixture sites (*.test, served by tests/fixtures/router.php). Each step's
 * answer must be 2xx (or the listed status); the values the manifests refer to as {seed.<key>} are collected here.
 *
 * The demo business is Northwind Supply Co (DESIGN.md §12.2, tests/Support/Profiles.php).
 */

const NORTHWIND = {
    brand_name: 'Northwind Supply Co',
    legal_name: 'NORTHWIND SUPPLY CO',
    domain: 'northwind.test',
    home_url: 'https://northwind.test',
    tagline: 'Industrial supplies and packaging for workshops and warehouses',
    email: 'hello@northwind.test',
    address: '1200 NW Front Ave',
    city: 'Portland',
    region: 'OR',
    postal_code: '97209',
    country: 'US',
    founded_year: '2019',
    social: [ 'https://social.example/northwindsupply' ],
    categories: [ 'Industrial Supplies', 'Packaging' ],
};

/**
 * The seed steps, in order: [ name, method, route, body, { key: 'path.in.answer' }, expectedStatus ].
 * Routes may use {key} of an earlier step's values.
 */
export const SEED_STEPS = [
    [ 'profile and fixture settings', 'POST', '/settings', { settings: {
        site: NORTHWIND,
        advanced: { crawl_delay_seconds: 0, log_level: 'debug' },
        providers: { rdap: { enabled: false }, seranking: { enabled: false }, google: { enabled: false } },
        discovery: { seed_catalog: false, brand_terms: [ 'Northwind Supply Co', 'northwind.test' ], extra_terms: [ 'fastener', 'fasteners', 'packaging', 'industrial supplies', 'shipping supplies', 'storage', 'handling' ] },
        automation: { submission_mode: 'manual' },
        outreach: { sender_name: 'Test Sender', sender_email: 'outreach@northwind.test', postal_address: '1200 NW Front Ave, Portland, OR 97209, United States', signature: 'Test Sender, Northwind Supply Co' },
        monitoring: { notify_email: 'admin@example.com' },
    } }, {} ],
    [ 'prospect: directory with a listing form', 'POST', '/prospects', { url: 'https://labdirectory.test/add-listing/', note: 'E2E: directory with a listing form' }, { prospectDirectory: 'prospect_id' } ],
    [ 'prospect: resource page', 'POST', '/prospects', { url: 'https://resources.test/links/', note: 'E2E: resource page' }, { prospectResource: 'prospect_id' } ],
    [ 'prospect: blog with a mention', 'POST', '/prospects', { url: 'https://mention.test/blog/regional-fastener-suppliers/' }, { prospectMention: 'prospect_id' } ],
    [ 'prospect: spam site', 'POST', '/prospects', { url: 'https://spam.test/' }, { prospectSpam: 'prospect_id' } ],
    [ 'prospect: one to delete', 'POST', '/prospects', { url: 'https://remove-me.test/', note: 'E2E: deleted by the walk' }, { prospectDelete: 'prospect_id' } ],
    [ 'evaluate the directory', 'POST', '/prospects/{prospectDirectory}/evaluate', null, {} ],
    [ 'evaluate the resource page', 'POST', '/prospects/{prospectResource}/evaluate', null, {} ],
    [ 'evaluate the mention blog', 'POST', '/prospects/{prospectMention}/evaluate', null, {} ],
    [ 'evaluate the spam site', 'POST', '/prospects/{prospectSpam}/evaluate', null, {} ],
    [ 'contact on the resource page', 'POST', '/prospects/{prospectResource}/contacts', { email: 'librarian@resources.test', name: 'Resource Librarian', role: 'Editor' }, { contactResource: 'contact_id' } ],
    [ 'package: directory listing', 'POST', '/prospects/{prospectDirectory}/package', null, { submissionDirectory: 'submission_id' } ],
    [ 'package: resource page (owner task)', 'POST', '/prospects/{prospectResource}/package', null, { submissionOwnerTask: 'submission_id' } ],
    [ 'backlink: blog page that links to the site', 'POST', '/backlinks', { source_url: 'https://mention.test/blog/linked/', target_url: 'https://northwind.test/' }, { backlinkMain: 'backlink_id' } ],
    [ 'backlink: one to delete', 'POST', '/backlinks', { source_url: 'https://resources.test/old-links/', target_url: 'https://northwind.test/' }, { backlinkDelete: 'backlink_id' } ],
    [ 'check the main backlink', 'POST', '/backlinks/{backlinkMain}/check', null, {} ],
    [ 'suppression: a domain', 'POST', '/suppression', { type: 'domain', value: 'blocked-e2e.test', reason: 'E2E: removed by the walk' }, { suppressionDelete: 'entry.id' } ],
    [ 'campaign: resource pages (drafts the first message)', 'POST', '/outreach/campaigns', { name: 'E2E resource pages', approach: 'resource_page', settings: { daily_limit: 5 } }, { campaignMain: 'campaign_id' } ],
    // Lists with more than one page (25 rows per page), so the pagers' Previous and Next buttons are enabled.
    ...Array.from( { length: 26 }, ( _, i ) => [ `bulk prospect ${ i + 1 }`, 'POST', '/prospects', { url: `https://e2e-bulk-${ String( i + 1 ).padStart( 2, '0' ) }.test/` }, i < 3 ? { [ `prospectBulk${ i + 1 }` ]: 'prospect_id' } : {} ] ),
    ...Array.from( { length: 26 }, ( _, i ) => [ `bulk backlink ${ i + 1 }`, 'POST', '/backlinks', { source_url: `https://e2e-ref-${ String( i + 1 ).padStart( 2, '0' ) }.test/links/`, target_url: 'https://northwind.test/' }, {} ] ),
    // Suppression entries are stored by registrable domain: one domain each.
    ...Array.from( { length: 26 }, ( _, i ) => [ `bulk suppression ${ i + 1 }`, 'POST', '/suppression', { type: 'domain', value: `e2e-blocked-${ String( i + 1 ).padStart( 2, '0' ) }.test`, reason: 'E2E: pager rows' }, {} ] ),
    // One finished growth run, so the Overview shows its results (needs-you links, headline tiles, step list).
    [ 'growth run: start', 'POST', '/growth/start', null, {} ],
    [ 'growth run: step', 'POST', '/growth/step', null, {} ],
    [ 'growth run: finished', 'GET', '/growth/status', null, { growthRunStatus: 'status' } ],
    // Content for internal-link suggestions: a product (a link target, by its title) and a post that mentions it.
    [ 'store product (link target)', 'POST', '/wc/v3/products', { name: 'Corrugated Shipping Boxes', type: 'simple', status: 'publish', regular_price: '12.00', description: 'Strong corrugated shipping boxes for warehouses.' }, { productBoxes: 'id' } ],
    ...[ [ 'Packing parts for shipping', 'workshops pack spare parts' ], [ 'Storing stock in a small warehouse', 'small warehouses stack stock' ], [ 'Moving fragile fasteners', 'fragile fasteners travel' ] ].map( ( [ title, phrase ], i ) => [ `post ${ i + 1 } that mentions it`, 'POST', '/wp/v2/posts', { title, status: 'publish', content: `<p>Most ${ phrase } in corrugated shipping boxes. Choosing corrugated shipping boxes of the right strength keeps everything safe in transit.</p><p>We also explain how corrugated shipping boxes are rated.</p>` }, {} ] ),
    [ 'internal-link suggestions', 'POST', '/seo/links/run', null, {} ],
    [ 'internal-link suggestions (continued)', 'POST', '/seo/links/run', null, {} ],
    [ 'internal-link suggestions waiting', 'GET', '/seo/links?status=pending', null, { linkSuggestions: 'counts.pending' } ],
    // After the growth run (its packaging stage would package them): directories the walk packages itself.
    [ 'prospect: directory to package from the drawer', 'POST', '/prospects', { url: 'https://e2e-package-a.test/add-listing/' }, { prospectPackage: 'prospect_id' } ],
    [ 'prospect: directory to package for the toast link', 'POST', '/prospects', { url: 'https://e2e-package-b.test/add-listing/' }, { prospectPackageToast: 'prospect_id' } ],
    // Manual kits (directories that cannot be reached, so the package is a kit to submit by hand): the walk marks them
    // submitted (1-3 as the administrator, 4-7 as the submitter).
    ...[ 1, 2, 3, 4, 5, 6, 7 ].flatMap( ( n ) => [
        [ `kit prospect ${ n }`, 'POST', '/prospects', { url: `https://e2e-kit-${ n }.test/add-listing/` }, { [ `prospectKit${ n }` ]: 'prospect_id' } ],
        [ `kit ${ n }: package`, 'POST', `/prospects/{prospectKit${ n }}/package`, null, { [ `submissionKit${ n }` ]: 'submission_id' } ],
    ] ),
    // Outreach: five more resource pages with a published address, drafted into the campaign; the campaign is
    // approved and the messages are left in the states the message drawer's buttons need.
    ...[ 1, 2, 3, 4, 5 ].flatMap( ( n ) => [
        [ `outreach prospect ${ n }`, 'POST', '/prospects', { url: `https://e2e-outreach-${ n }.test/resources/` }, { [ `prospectOutreach${ n }` ]: 'prospect_id' } ],
        [ `outreach prospect ${ n }: qualified`, 'POST', `/prospects/{prospectOutreach${ n }}/status`, { status: 'qualified', reason: 'E2E outreach target' }, {} ],
        [ `outreach prospect ${ n }: contact`, 'POST', `/prospects/{prospectOutreach${ n }}/contacts`, { email: `editor@e2e-outreach-${ n }.test`, name: `Editor ${ n }`, role: 'editor' }, {} ],
    ] ),
    [ 'outreach: drafts for the five', 'POST', '/outreach/campaigns/{campaignMain}/drafts', { prospect_ids: '{prospectOutreach1},{prospectOutreach2},{prospectOutreach3},{prospectOutreach4},{prospectOutreach5}' }, { outreachDrafted: 'created' } ],
    [ 'outreach message of the resource page', 'GET', '/outreach/messages?prospect_id={prospectResource}', null, { messageResource: 'items.0.id' } ],
    ...[ 1, 2, 3, 4, 5 ].map( ( n ) => [ `outreach message ${ n }`, 'GET', `/outreach/messages?prospect_id={prospectOutreach${ n }}`, null, { [ `message${ n }` ]: 'items.0.id' } ] ),
    [ 'campaign: submit for approval', 'POST', '/outreach/campaigns/{campaignMain}/submit', null, {} ],
    [ 'campaign: approve', 'POST', '/outreach/campaigns/{campaignMain}/approve', null, {} ],
    [ 'message 2: approved (to send)', 'POST', '/outreach/messages/{message2}/approve', null, {} ],
    [ 'message 3: approved', 'POST', '/outreach/messages/{message3}/approve', null, {} ],
    [ 'message 3: sent (to log a reply)', 'POST', '/outreach/messages/{message3}/send', null, {} ],
    [ 'message 4: approved', 'POST', '/outreach/messages/{message4}/approve', null, {} ],
    [ 'message 4: sent', 'POST', '/outreach/messages/{message4}/send', null, {} ],
    [ 'message 4: replied', 'POST', '/outreach/messages/{message4}/log-reply', { summary: 'E2E: thanks, we will look at it.' }, {} ],
    [ 'message 5: rejected', 'POST', '/outreach/messages/{message5}/reject', { reason: 'E2E' }, {} ],
];

/**
 * The URL of an lga/v1 route ("/outreach/messages?prospect_id=3") under the REST root lga-config gives, which may be
 * a pretty root (/wp-json/lga/v1) or a query root (?rest_route=/lga/v1).
 */
export function restUrl( restRoot, route ) {
    const [ path, query = '' ] = String( route ).split( '?' );
    if ( /^\/(wp|wc)\/v\d/.test( path ) ) {
        // A WordPress or WooCommerce route: the same REST root without the lga/v1 namespace.
        restRoot = restRoot.replace( /\/?lga\/v1\/?$/, '' ).replace( /(rest_route=)%2Flga%2Fv1/i, '$1' );
    }
    const u = new URL( restRoot.replace( /\/+$/, '' ) + ( restRoot.includes( '?' ) ? '' : path ) );
    if ( restRoot.includes( '?' ) ) {
        u.searchParams.set( 'rest_route', ( u.searchParams.get( 'rest_route' ) || '' ).replace( /\/+$/, '' ) + path );
    }
    for ( const [ k, v ] of new URLSearchParams( query ) ) {
        u.searchParams.set( k, v );
    }
    return u.href;
}

function pick( obj, dotted ) {
    return String( dotted ).split( '.' ).reduce( ( o, k ) => ( o === null || o === undefined ? undefined : o[ k ] ), obj );
}

/**
 * Runs the seed with an authenticated APIRequestContext. Returns { values, steps } where steps records each call
 * (name, method, route, status, ms, ok, error).
 */
export async function runSeed( request, { restRoot, nonce, log, steps = SEED_STEPS } ) {
    const values = {};
    const out = [];
    for ( const [ name, method, routeTpl, body, keys = {}, expected = '2xx' ] of steps ) {
        const fillIn = ( str ) => String( str ).replace( /\{([A-Za-z0-9_]+)\}/g, ( m, k ) => ( values[ k ] !== undefined ? String( values[ k ] ) : m ) );
        const route = fillIn( routeTpl );
        // A body value "{a},{b}" under prospect_ids becomes a list of ids.
        const data = body && typeof body === 'object' ? JSON.parse( JSON.stringify( body ), ( k, v ) => ( typeof v === 'string' ? ( k === 'prospect_ids' ? fillIn( v ).split( ',' ).map( Number ) : fillIn( v ) ) : v ) ) : body;
        const t = Date.now();
        let status = 0;
        let json = null;
        let error = '';
        try {
            const res = await request.fetch( restUrl( restRoot, route ), {
                method,
                headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json', Accept: 'application/json' },
                data: data ? JSON.stringify( data ) : undefined,
                timeout: 120000,
            } );
            status = res.status();
            const text = await res.text();
            try {
                json = JSON.parse( text );
            } catch ( e ) {
                json = null;
                error = `not JSON: ${ text.slice( 0, 200 ) }`;
            }
        } catch ( e ) {
            error = String( e.message || e ).split( '\n' )[ 0 ];
        }
        const ok = ! error && ( expected === '2xx' ? status >= 200 && status < 300 : String( status ) === String( expected ) );
        for ( const [ key, dotted ] of Object.entries( keys ) ) {
            const v = json ? pick( json, dotted ) : undefined;
            if ( v === undefined || v === null || v === '' ) {
                error = error || `no ${ dotted } in the answer`;
            } else {
                values[ key ] = v;
            }
        }
        const rec = { name, method, route, status, ms: Date.now() - t, ok: ok && ! error, error: error || ( ok ? '' : `status ${ status }${ json && json.message ? ': ' + json.message : '' }` ) };
        out.push( rec );
        log( `seed ${ rec.ok ? 'ok ' : 'FAIL' } ${ method } ${ route } → ${ status } (${ rec.ms } ms) ${ name }${ rec.error ? ' — ' + rec.error : '' }` );
    }
    return { values, steps: out };
}
