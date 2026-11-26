/**
 * Node side of ManifestTest.php: runs the E2E suite's manifest library (tests/e2e/lib/manifest.mjs) and the
 * shipped manifests' validation, and prints one JSON object with the results. No browser, no site.
 * Usage: node ManifestHarness.mjs <plugin dir>
 */
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const root = process.argv[ 2 ];
const lib = await import( pathToFileURL( path.join( root, 'tests/e2e/lib/manifest.mjs' ) ).href );
const { requestMatches, statusMatches, validateEntry, parseRequestPattern, substitute, unresolved, loadManifests } = lib;
const req = ( method, route, query = {} ) => ( { method, route, query } );
const shipped = loadManifests( path.join( root, 'tests/e2e/controls' ) );
const ids = shipped.entries.map( ( e ) => e.control );
const spaPages = Object.keys( JSON.parse( fs.readFileSync( path.join( root, 'tests/fixtures/spa-config.json' ), 'utf8' ) ).pages || {} );
const navEntries = shipped.entries.filter( ( e ) => e.control.startsWith( 'shell.nav-' ) );

process.stdout.write( JSON.stringify( {
    match: {
        exact: requestMatches( 'GET /prospects', req( 'GET', '/prospects' ) ),
        segment: requestMatches( 'POST /prospects/{id}/evaluate', req( 'POST', '/prospects/12/evaluate' ) ),
        star: requestMatches( 'POST /outreach/campaigns/*/drafts', req( 'POST', '/outreach/campaigns/3/drafts' ) ),
        rest: requestMatches( 'GET /seo/**', req( 'GET', '/seo/links/run' ) ),
        query: requestMatches( 'GET /prospects?status=qualified', req( 'GET', '/prospects', { status: 'qualified', page: '1' } ) ),
        queryAny: requestMatches( 'GET /outreach/messages?campaign_id=*', req( 'GET', '/outreach/messages', { campaign_id: '4' } ) ),
        wrongMethod: requestMatches( 'POST /prospects', req( 'GET', '/prospects' ) ),
        longerRoute: requestMatches( 'GET /prospects', req( 'GET', '/prospects/3' ) ),
        missingQuery: requestMatches( 'GET /prospects?status=qualified', req( 'GET', '/prospects', { page: '1' } ) ),
        otherQueryValue: requestMatches( 'GET /prospects?status=qualified', req( 'GET', '/prospects', { status: 'rejected' } ) ),
        malformed: parseRequestPattern( 'prospects' ),
        // "!k": the request must not carry k (a filter's Reset reloads without the filter its before step typed).
        absentOk: requestMatches( 'GET /logs?!channel', req( 'GET', '/logs', { page: '1' } ) ),
        absentEmpty: requestMatches( 'GET /logs?!channel', req( 'GET', '/logs', { channel: '', page: '1' } ) ),
        absentPresent: requestMatches( 'GET /logs?!channel', req( 'GET', '/logs', { channel: 'rest', page: '1' } ) ),
        absentWithQuery: requestMatches( 'GET /submissions?status=needs_manual&!q', req( 'GET', '/submissions', { status: 'needs_manual', q: 'owner' } ) ),
        absentMalformed: [ parseRequestPattern( 'GET /logs?!' ), parseRequestPattern( 'GET /logs?!a=b' ) ],
    },
    status: {
        class2: statusMatches( '2xx', 201 ),
        class4: statusMatches( '4xx', 422 ),
        exact: statusMatches( '409', 409 ),
        default2xx: statusMatches( undefined, 200 ),
        defaultRefuses4xx: statusMatches( undefined, 404 ),
        wrongClass: statusMatches( '2xx', 500 ),
    },
    validate: {
        ok: validateEntry( { control: 'backlinks.add', action: 'click', expect: { dom: '.lga-modal' } }, 'backlinks' ),
        noExpect: validateEntry( { control: 'backlinks.add', action: 'click' }, 'backlinks' ),
        emptyExpect: validateEntry( { control: 'backlinks.add', action: 'click', expect: {} }, 'backlinks' ),
        badAction: validateEntry( { control: 'backlinks.add', action: 'hover', expect: { dom: 'x' } }, 'backlinks' ),
        otherPage: validateEntry( { control: 'prospects.add', action: 'click', expect: { dom: 'x' } }, 'backlinks' ),
        unknownKey: validateEntry( { control: 'backlinks.add', action: 'click', expect: { dom: 'x' }, exect: {} }, 'backlinks' ),
        fillWithoutInput: validateEntry( { control: 'backlinks.list-filter-q', action: 'fill', expect: { request: 'GET /backlinks' } }, 'backlinks' ),
        badRequest: validateEntry( { control: 'backlinks.add', action: 'click', expect: { request: 'backlinks' } }, 'backlinks' ),
        statusWithoutRequest: validateEntry( { control: 'backlinks.add', action: 'click', expect: { dom: 'x', status: '4xx' } }, 'backlinks' ),
        badRole: validateEntry( { control: 'backlinks.add', action: 'click', expect: { dom: 'x' }, roles: [ 'editor' ] }, 'backlinks' ),
        badMock: validateEntry( { control: 'backlinks.add', action: 'click', expect: { dom: 'x' }, mock: [ { request: 'GET /x', status: 'bad' } ] }, 'backlinks' ),
        badStep: validateEntry( { control: 'backlinks.add', action: 'click', expect: { dom: 'x' }, before: [ 'not a control' ] }, 'backlinks' ),
        // A page's own file describes its navigation link (a package that adds a page touches no shared manifest),
        // clicked from another page; other shell controls stay in shell.json.
        navOwnPage: validateEntry( { control: 'shell.nav-backlinks', action: 'click', expect: { navigate: '/page=lga-backlinks(&|#|$)/' } }, 'backlinks' ),
        navInShell: validateEntry( { control: 'shell.nav-backlinks', action: 'click', expect: { navigate: '/page=lga-backlinks(&|#|$)/' } }, 'shell' ),
        navOtherPage: validateEntry( { control: 'shell.nav-prospects', action: 'click', expect: { navigate: '/page=lga-prospects(&|#|$)/' } }, 'backlinks' ),
        shellInPage: validateEntry( { control: 'shell.theme', action: 'click', expect: { dom: 'x' } }, 'backlinks' ),
        navOnItsOwnPage: validateEntry( { control: 'shell.nav-backlinks', action: 'click', expect: { navigate: '/page=lga-backlinks/' }, page: 'backlinks' }, 'backlinks' ),
        navOverviewDefault: validateEntry( { control: 'shell.nav-overview', action: 'click', expect: { navigate: '/page=lga(&|#|$)/' } }, 'overview' ),
    },
    substitute: {
        value: substitute( { view: '#open={seed.prospect}', n: 3 }, { prospect: 12 } ),
        left: unresolved( substitute( { view: '#open={seed.missing}' }, {} ) ),
    },
    shipped: {
        errors: shipped.errors,
        files: shipped.files.map( ( f ) => f.name ),
        entries: shipped.entries.length,
        shell: ids.filter( ( id ) => id.startsWith( 'shell.' ) ).length,
        submitter: shipped.entries.filter( ( e ) => e.roles.includes( 'submitter' ) ).length,
        destructive: shipped.entries.filter( ( e ) => e.destructive ).length,
        withRequest: shipped.entries.filter( ( e ) => e.expect.request ).length,
        // A Settings field is checked through the request its Save sends (with the value in the body), not only
        // through the "unsaved" save bar.
        settingsFields: shipped.entries.filter( ( e ) => /^settings\.field-/.test( e.control ) ).length,
        settingsFieldsWithoutRequest: shipped.entries.filter( ( e ) => /^settings\.field-/.test( e.control ) && ! ( e.expect.request && e.expect.body ) ).map( ( e ) => e.label ),
        // Each page's navigation link: the file that describes it and the page it is clicked from.
        nav: Object.fromEntries( navEntries.map( ( e ) => [ e.control, { file: e.file, page: e.page } ] ) ),
        spaPages,
        // A filter's Reset is checked through a reload without the filter its before step typed.
        resetsWithoutAbsent: shipped.entries.filter( ( e ) => /-filter-reset$/.test( e.control ) && ! /[?&]!/.test( String( e.expect.request || '' ) ) ).map( ( e ) => e.label ),
        resets: shipped.entries.filter( ( e ) => /-filter-reset$/.test( e.control ) ).length,
    },
} ) );
