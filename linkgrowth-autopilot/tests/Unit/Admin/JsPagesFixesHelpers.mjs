/**
 * Node-only checks for JsPagesFixesTest (no browser needed): imports the page modules' pure helpers
 * (settings.js leaf diff / toggle-flag routing / removed limits, pages/outcome.js run interpretation; the
 * DOM-building parts are covered by the browser harness) and prints their results as one JSON object.
 *
 * Usage: node JsPagesFixesHelpers.mjs <pluginDir>
 */
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const pluginDir = path.resolve( process.argv[ 2 ] || '.' );
const load = ( rel ) => import( pathToFileURL( path.join( pluginDir, rel ) ).href );

const settings = await load( 'assets/js/pages/settings.js' );
const outcome = await load( 'assets/js/pages/outcome.js' );

const out = {};

// js-pages-1: a stale tab (automation flags true in its copy) that only edits one daily limit.
const original = {
    automation: { enabled: true, submissions: true, outreach_autosend: true, submission_mode: 'auto', daily_limits: { evaluations: 40, submissions: 5 }, weekly_limits: { submissions: 20 } },
    site: { brand_name: 'Example', social: [ 'https://a.example' ] },
};
const draft = JSON.parse( JSON.stringify( original ) );
draft.automation.daily_limits.evaluations = 25;
out.staleDiff = settings.changedLeaves( draft, original );

// Explicit flag edits are moved out of the /settings partial into /automation/toggle calls.
const draft2 = JSON.parse( JSON.stringify( original ) );
draft2.automation.outreach_autosend = false;
draft2.automation.enabled = false;
draft2.site.social = [];
const changed2 = settings.changedLeaves( draft2, original );
out.flagDiffBefore = JSON.parse( JSON.stringify( changed2 ) );
out.flags = settings.takeToggleFlags( changed2 );
out.flagDiffAfter = changed2;

// js-pages-8: setting a cap to 0 is detected for confirmation.
const draft3 = JSON.parse( JSON.stringify( original ) );
draft3.automation.daily_limits.submissions = 0;
draft3.automation.weekly_limits.submissions = 0;
out.removed = settings.limitsRemoved( settings.changedLeaves( draft3, original ) );
out.removedNone = settings.limitsRemoved( out.staleDiff );

// js-pages-6: run responses as the controllers return them.
const pick = ( o ) => ( { state: o.state, kind: o.kind, message: o.message } );
out.runs = {
    skipped: pick( outcome.runOutcome( { ok: true, result: { job: 'evaluate', skipped: true, reason: 'evaluation_limit_reached', ok: true }, job_id: null }, 'Evaluation' ) ),
    duplicate: pick( outcome.runOutcome( { ok: true, result: { ok: true, queued: true, duplicate: true }, job_id: null }, 'Evaluation' ) ),
    queued: pick( outcome.runOutcome( { ok: true, result: { ok: true, queued: true, duplicate: false }, job_id: 7 }, 'Evaluation' ) ),
    failed: pick( outcome.runOutcome( { ok: false, result: { ok: false, error: 'Boom' }, job_id: null }, 'Evaluation' ) ),
    importErrors: pick( outcome.runOutcome( { ok: true, result: { providers: null, analytics: { sources: 0, new: 0 }, errors: [ 'Daily provider lookup limit reached; provider import deferred.' ], ok: true }, job_id: null }, 'Import' ) ),
    itemError: pick( outcome.runOutcome( { ok: true, result: { evaluated: 0, errors: 1, items: [ { prospect_id: 3, domain: 'a.example', outcome: 'error', error: 'Timeout' } ], ok: true } }, 'Evaluation' ) ),
    countSkipped: pick( outcome.runOutcome( { ok: true, result: { drafted: 2, skipped: 4, auto_approved: 0, warnings: [], ok: true } }, 'Auto outreach drafts' ) ),
    finished: pick( outcome.runOutcome( { ok: true, result: { evaluated: 1, errors: 0, items: [ { prospect_id: 3, outcome: 'qualified' } ], ok: true } }, 'Evaluation' ) ),
};

process.stdout.write( JSON.stringify( out ) );
