<?php
/**
 * Integration section: "Grow now" release (1.1.0) on the real WordPress + SQLite + WooCommerce install.
 *
 * Required from tests/integration/run.php after the existing checks. It
 * (a) builds WooCommerce categories, a tag and products that mirror the live store, and checks that the storefront
 *     compliance check blocks, that Policy refuses automated submissions with "storefront_inconsistent", and that a
 *     one-click growth run (POST /growth/start + POST /growth/step) completes with its submit step blocked, the
 *     verified opportunity database imported as owner tasks, IndexNow submitted (mocked endpoint) and its key file
 *     served, the site audit done and an internal-link suggestion that is applied only through the REST "Apply"
 *     call (a revision exists and only the anchor changed);
 * (b) renames the categories to research-only names, deletes the syringe product and the tag, and checks that the
 *     re-check passes and a second run's submit step is no longer blocked.
 * Everything created here is removed at the end (and by run.php before the existing checks, if a run was cut short).
 *
 * The section stays offline: api.indexnow.org is answered by a recorder and every non-fixture host is refused.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

use PPC\LinkGrowth\Automation\Jobs\EvaluateJob;
use PPC\LinkGrowth\Compliance\StorefrontAudit;
use PPC\LinkGrowth\Discovery\VerifiedOpportunities;
use PPC\LinkGrowth\Growth\GrowthRun;
use PPC\LinkGrowth\Outreach\OutreachEngine;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Seo\IndexNow;
use PPC\LinkGrowth\Seo\SiteAuditor;
use PPC\LinkGrowth\Submission\Policy;
use PPC\LinkGrowth\Support\Settings;

require_once __DIR__ . '/growth-fixtures.php';

$GLOBALS['itGrowth'] = [];
register_shutdown_function( static function (): void {
    // A fatal error mid-section must not leave consumer-use categories behind for the next run.
    try {
        ppclg_it_growth_cleanup();
    } catch ( \Throwable $e ) {
        unset( $e );
    }
} );

IT::step( 'Growth (a): storefront mirroring the live store blocks automation', static function (): void {
    $g = &$GLOBALS['itGrowth'];
    ppclg_it_growth_cleanup();
    wp_set_current_user( 1 );
    ppclg_it_offline_guard(); // already active for the whole run; resets the IndexNow recorder
    // The install reports the "local" environment; IndexNow submits only on production. Opt in from code (the
    // endpoint is mocked by the offline guard).
    add_filter( 'ppclg_indexnow_environment', static fn(): string => 'production' );

    $settings = ppclg()->get( Settings::class );
    IT::check( true === $settings->get( 'compliance.storefront_gate' ) && '' === $settings->get( 'compliance.acknowledged_hash' ), 'compliance defaults registered (gate on, nothing acknowledged)' );
    IT::check( 20 === (int) $settings->get( 'growth.step_seconds' ) && 3 === (int) $settings->get( 'seo.max_links_per_post' ), 'growth and seo defaults registered' );

    $audit = ppclg()->get( StorefrontAudit::class );
    $audit->run();
    delete_option( StorefrontAudit::STALE_OPTION );
    IT::check( 'pass' === $audit->current()['status'], 'empty store passes before the fixtures', (string) wp_json_encode( $audit->current()['counts'] ?? null ) );

    $cats = [];
    foreach ( PPCLG_IT_LIVE_CATEGORIES as $name ) {
        $cats[ $name ] = ppclg_it_term( $name, 'product_cat' );
    }
    $tag = ppclg_it_term( 'Buy Semaglutide Canada', 'product_tag', PPCLG_IT_LIVE_TAG );
    IT::check( false !== get_option( StorefrontAudit::STALE_OPTION, false ), 'creating product categories marks the storefront check stale (created_product_cat hook)' );

    delete_option( StorefrontAudit::STALE_OPTION );
    $g['products'] = [
        'sema'     => ppclg_it_product( 'Semaglutide 5mg', [ $cats['Weight Loss & Metabolic'] ], [ $tag ] ),
        'ghk'      => ppclg_it_product( 'GHK-Cu 50mg', [ $cats['Peptide Skincare'] ] ),
        'syringes' => ppclg_it_product( 'Insulin Syringes 30G', [] ),
    ];
    IT::check( false !== get_option( StorefrontAudit::STALE_OPTION, false ), 'saving a product marks the storefront check stale (save_post_product hook)' );
    $g['cats'] = $cats;
    $g['tag']  = $tag;
    $g['post'] = ppclg_it_post(
        'Handling notes for lyophilized research peptides',
        "<!-- wp:paragraph -->\n<p>Before reconstitution, our laboratory stores GHK-Cu 50mg at -20 °C and protects it from light &amp; moisture.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Record every lot number in the lab notebook.</p>\n<!-- /wp:paragraph -->"
    );

    $res = ppclg_it_rest( 'GET', '/compliance/storefront' );
    $s   = (array) $res->get_data();
    IT::check( 200 === $res->get_status(), 'GET /compliance/storefront 200', (string) $res->get_status() );
    IT::check( 'block' === ( $s['status'] ?? '' ) && 'block' === ( $s['effective'] ?? '' ) && ! empty( $s['blocking'] ), 'storefront check blocks the live-store wording', (string) wp_json_encode( [ $s['status'] ?? null, $s['effective'] ?? null, $s['counts'] ?? null ] ) );
    $found = [];
    foreach ( (array) ( $s['findings'] ?? [] ) as $f ) {
        $found[] = $f['severity'] . ':' . $f['where'] . ':' . $f['term'];
    }
    $expected = [ 'block:category:weight loss', 'block:category:anti-aging', 'block:category:tanning', 'block:category:skincare', 'block:category:nasal spray', 'block:category:capsule', 'block:product:syringe', 'warn:category:metabolic', 'warn:category:cognitive', 'warn:category:recovery', 'warn:category:growth' ];
    $missing  = array_values( array_diff( $expected, $found ) );
    IT::check( [] === $missing, 'findings name the consumer-use categories, the syringe product and the review terms', 'missing: ' . implode( ', ', $missing ) . ' | found: ' . implode( ', ', $found ) );
    IT::check( (int) ( $s['counts']['block'] ?? 0 ) >= 7, 'at least 7 blocking findings', (string) wp_json_encode( $s['counts'] ?? null ) );
    IT::check( '' !== (string) ( $s['message'] ?? '' ), 'blocking reason explained', (string) ( $s['message'] ?? '' ) );
    IT::check( $audit->isBlocking(), 'StorefrontAudit::isBlocking() is true' );

    $nack = ppclg_it_rest( 'POST', '/compliance/storefront/acknowledge', [] );
    IT::check( 400 === $nack->get_status(), 'acknowledge without confirm refused (400)', (string) $nack->get_status() );

    $prospect = ppclg()->get( Prospects::class )->findByDomain( 'resources.test' );
    $policy   = ppclg()->get( Policy::class )->evaluate( (array) $prospect, [ 'unmapped_required' => [] ], [ 'kind' => 'listing_submission', 'method' => 'POST', 'fields' => [] ], 'https://resources.test/submit-resource/' );
    IT::check( ! $policy['allowed'] && in_array( Policy::REASON_STOREFRONT, (array) ( $policy['codes'] ?? [] ), true ), 'Policy refuses an automated submission with storefront_inconsistent', (string) wp_json_encode( $policy ) );
    IT::check( ! empty( $policy['hard'] ), 'the storefront reason is a hard blocker' );
} );

IT::step( 'Growth (a): one-click run while the storefront blocks', static function () use ( $fixtureLog ): void {
    $g        = &$GLOBALS['itGrowth'];
    $logBytes = is_file( $fixtureLog ) ? (int) filesize( $fixtureLog ) : 0;
    $settings = ppclg()->get( Settings::class );
    IT::check( (bool) $settings->get( 'automation.submissions' ), 'automated submissions are on (so only the storefront gate can block the submit step)' );

    // The scheduler tick of the existing checks may already have imported the bundled database. Forget the import
    // bookkeeping so the run imports it through its own step: items already present must be recognised (no
    // duplicate owner tasks).
    $tasksBefore = (int) ( ppclg_it_rest( 'GET', '/submissions', [ 'source' => 'verified_db', 'per_page' => 1 ] )->get_data()['total'] ?? 0 );
    foreach ( [ VerifiedOpportunities::OPTION_VERSION, VerifiedOpportunities::OPTION_STATE, VerifiedOpportunities::OPTION_ITEMS ] as $option ) {
        delete_option( $option );
    }
    IT::check( ! empty( ppclg()->get( VerifiedOpportunities::class )->needsImport() ), 'verified database needs an import before the run', 'owner tasks before: ' . $tasksBefore );

    $run = ppclg_it_grow();
    $g['run1'] = $run;
    $state = $run['state'];
    echo sprintf( "    (run 1: %d step requests in %.1f s)\n", $run['steps'], $run['seconds'] );
    foreach ( (array) ( $state['steps'] ?? [] ) as $step ) {
        echo sprintf( "    - %-18s %-8s %s\n", $step['key'], $step['status'], mb_substr( (string) $step['summary'], 0, 150 ) );
    }
    IT::check( ! empty( $run['start']['ok'] ) && 'Growth run started.' === ( $run['start']['message'] ?? '' ), 'POST /growth/start started a run', (string) wp_json_encode( [ $run['start']['ok'] ?? null, $run['start']['message'] ?? null ] ) );
    IT::check( [] === array_values( array_diff( $run['codes'], [ 200 ] ) ), 'every growth request answered 200', implode( ',', $run['codes'] ) );
    IT::check( 'completed' === ( $state['status'] ?? '' ), 'growth run completed', (string) ( $state['status'] ?? '' ) . ' ' . (string) ( $state['error'] ?? '' ) );
    $progress = (array) ( $state['progress'] ?? [] );
    IT::check( isset( $progress['done'], $progress['total'] ) && $progress['done'] === $progress['total'] && 18 === $progress['total'], 'every step finished', (string) wp_json_encode( $progress ) );

    $submit = ppclg_it_step( $state, 'submit' );
    IT::check( 'blocked' === ( $submit['status'] ?? '' ), 'submit step blocked by the storefront gate', (string) wp_json_encode( $submit ) );
    IT::check( str_starts_with( (string) ( $submit['summary'] ?? '' ), 'Paused by the storefront compliance check.' ), 'submit step explains the pause', (string) ( $submit['summary'] ?? '' ) );
    $after = is_file( $fixtureLog ) ? (int) filesize( $fixtureLog ) : 0;
    IT::check( $after === $logBytes, 'nothing was submitted to a fixture site during the blocked run', $logBytes . ' -> ' . $after );
    foreach ( [ 'preflight', 'import_verified', 'site_audit', 'indexnow', 'internal_links', 'summary' ] as $key ) {
        $step = ppclg_it_step( $state, $key );
        IT::check( 'done' === ( $step['status'] ?? '' ), "step {$key} done", (string) wp_json_encode( [ $step['status'] ?? null, $step['summary'] ?? null, $step['error'] ?? null ] ) );
    }
    $outreachStep = ppclg_it_step( $state, 'auto_outreach' );
    IT::check( in_array( $outreachStep['status'] ?? '', [ 'done', 'skipped' ], true ), 'auto_outreach step ran (drafting only)', (string) wp_json_encode( $outreachStep ) );
    IT::check( [] === ppclg_it_step( $state, 'outreach_send' ), 'the growth run has no outreach sending step' );

    $summary = (array) ( $state['summary'] ?? [] );
    IT::check( isset( $summary['prospects']['total'], $summary['submissions']['owner_tasks'] ) && is_int( $summary['outreach_drafts'] ?? null ), 'run summary has prospects, owner tasks and outreach drafts', (string) wp_json_encode( array_keys( $summary ) ) );
    IT::check( ! empty( $summary['compliance']['blocking'] ) && 'block' === ( $summary['compliance']['effective'] ?? '' ), 'run summary reports the blocking storefront', (string) wp_json_encode( $summary['compliance'] ?? null ) );
    IT::check( is_array( $summary['site_audit'] ?? null ) && (int) $summary['site_audit']['pages'] > 0, 'run summary includes the site audit', (string) wp_json_encode( $summary['site_audit'] ?? null ) );
    $actions = [];
    foreach ( (array) ( $state['next_actions'] ?? [] ) as $a ) {
        $actions[ (string) $a['key'] ] = $a;
    }
    IT::check( isset( $actions['storefront'], $actions['owner_tasks'] ), 'next actions include the storefront review and the owner tasks', implode( ',', array_keys( $actions ) ) );
    IT::check( isset( $actions['owner_tasks'] ) && str_starts_with( (string) $actions['owner_tasks']['route'], '#/submissions?' ) && 'submissions' === $actions['owner_tasks']['page'], 'next action carries a dashboard route', (string) wp_json_encode( $actions['owner_tasks'] ?? null ) );

    $status = ppclg_it_rest( 'GET', '/growth/status' );
    IT::check( 200 === $status->get_status() && ( $status->get_data()['id'] ?? '' ) === ( $state['id'] ?? '-' ) && empty( $status->get_data()['active'] ), 'GET /growth/status returns the finished run' );
    $stepAgain = ppclg_it_rest( 'POST', '/growth/step' );
    IT::check( 200 === $stepAgain->get_status() && false === ( $stepAgain->get_data()['ok'] ?? null ), 'POST /growth/step after completion reports ok=false', (string) wp_json_encode( $stepAgain->get_data()['message'] ?? null ) );
    $auditLog = ppclg()->get( AuditLog::class );
    IT::check( $auditLog->count( [ 'action' => 'growth.started' ] ) >= 1 && $auditLog->count( [ 'action' => 'growth.completed' ] ) >= 1, 'growth.started and growth.completed audited' );

    // Outreach sending pauses while the storefront blocks (drafting continues): approve the auto-drafted message
    // from the existing checks and try "Send now".
    $messages = ppclg()->get( OutreachMessages::class );
    $engine   = ppclg()->get( OutreachEngine::class );
    $draft    = $messages->findOneBy( [ 'status' => OutreachMessages::STATUS_DRAFT ] );
    IT::check( null !== $draft, 'an outreach draft exists to exercise the send gate' );
    if ( null !== $draft ) {
        $g['message'] = (int) $draft['id'];
        $engine->approveCampaign( (int) $draft['campaign_id'], 1 );
        $approved = $engine->approveMessage( (int) $draft['id'], 1 );
        IT::check( ! empty( $approved['ok'] ), 'approving a draft still works while the storefront blocks', (string) wp_json_encode( $approved ) );
        $mailBefore = count( $GLOBALS['itMail'] );
        $send       = $engine->sendNow( (int) $draft['id'], 1 );
        $row        = $messages->find( (int) $draft['id'] );
        IT::check( empty( $send['ok'] ) && str_starts_with( (string) ( $send['error'] ?? '' ), 'Paused: the storefront compliance check is blocking' ), 'Send now is refused while the storefront blocks', (string) wp_json_encode( $send ) );
        IT::check( count( $GLOBALS['itMail'] ) === $mailBefore && OutreachMessages::STATUS_APPROVED === ( $row['status'] ?? '' ) && str_starts_with( (string) ( $row['last_error'] ?? '' ), 'Paused:' ), 'no email sent; the message stays approved with a "Paused" note', (string) wp_json_encode( [ $row['status'] ?? null, $row['last_error'] ?? null ] ) );
    }

    // Verified opportunity database: one owner task per imported item.
    $importStep = ppclg_it_step( $state, 'import_verified' );
    IT::check( (int) ( $importStep['rounds'] ?? 0 ) >= 6 && 595 === (int) ( $importStep['counts']['processed'] ?? 0 ), 'the import step processed all 595 items in batches of 100 within one run', (string) wp_json_encode( [ $importStep['rounds'] ?? null, $importStep['counts'] ?? null ] ) );
    $vo = ppclg()->get( VerifiedOpportunities::class )->status();
    IT::check( 595 === (int) $vo['total'] && ! empty( $vo['up_to_date'] ), 'verified database fully imported by the run', (string) wp_json_encode( array_intersect_key( $vo, array_flip( [ 'total', 'imported', 'skipped', 'up_to_date', 'in_progress', 'error' ] ) ) ) );
    IT::check( (int) $vo['imported'] === 595 - (int) $vo['skipped'] && (int) $vo['imported'] > 500, 'imported = 595 minus skipped', $vo['imported'] . ' + ' . $vo['skipped'] );
    $list = ppclg_it_rest( 'GET', '/submissions', [ 'source' => 'verified_db', 'per_page' => 1 ] );
    IT::check( 200 === $list->get_status() && (int) ( $list->get_data()['total'] ?? -1 ) === 595 - (int) $vo['skipped'], 'owner tasks from the verified database exist (595 minus skipped)', (string) wp_json_encode( $list->get_data()['total'] ?? null ) );
    IT::check( 0 === $tasksBefore || $tasksBefore === (int) ( $list->get_data()['total'] ?? -1 ), 're-importing created no duplicate owner tasks', $tasksBefore . ' -> ' . (string) wp_json_encode( $list->get_data()['total'] ?? null ) );
    $item = (array) ( $list->get_data()['items'][0] ?? [] );
    IT::check( 'needs_manual' === ( $item['status'] ?? '' ) && 'verified_db' === ( $item['payload']['package']['source'] ?? '' ), 'owner tasks are needs_manual rows with a verified_db package', (string) wp_json_encode( [ $item['status'] ?? null, $item['payload']['package'] ?? null ] ) );
    global $wpdb;
    $verifiedDrafts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ppclg_outreach_messages m INNER JOIN {$wpdb->prefix}ppclg_prospects p ON p.id = m.prospect_id WHERE p.discovered_via = %s", 'verified_db' ) ); // phpcs:ignore
    IT::check( 0 === $verifiedDrafts, 'no outreach drafts for verified-database prospects (the owner decides whether to pitch)', (string) $verifiedDrafts );
    $paidTasks = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ppclg_submissions WHERE status = %s AND payload LIKE %s", 'needs_manual', '%"item_id":"ec40093b4f8ac1ef"%' ) ); // phpcs:ignore
    IT::check( 0 === $paidTasks && (int) ( $vo['skip_reasons']['paid_only'] ?? 0 ) > 0, 'paid-only items (e.g. EIN Presswire) are skipped, not owner tasks', $paidTasks . ' / ' . (string) wp_json_encode( $vo['skip_reasons'] ?? null ) );

    // Evaluation order: a prospect discovered after the import is evaluated before the imported backlog.
    $repo        = ppclg()->get( Prospects::class );
    [ $orderId ] = $repo->upsertDiscovered( 'it-evaluation-order.test', [ 'discovered_via' => 'search' ] );
    try {
        $fifo    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$repo->table()} WHERE status = %s ORDER BY id ASC LIMIT 1", Prospects::STATUS_DISCOVERED ) ); // phpcs:ignore
        $fifoVia = (string) ( $repo->find( $fifo )['discovered_via'] ?? '' );
        IT::check( VerifiedOpportunities::SOURCE === $fifoVia && $fifo < $orderId, 'imported prospects are still waiting for evaluation, and the oldest discovered row is an imported one', $fifo . ' ' . $fifoVia );
        $next = $repo->dueForEvaluation( 1 )[0] ?? [];
        IT::check( VerifiedOpportunities::SOURCE !== (string) ( $next['discovered_via'] ?? VerifiedOpportunities::SOURCE ), 'the next prospect due for evaluation is not an imported one', (string) wp_json_encode( [ $next['id'] ?? null, $next['discovered_via'] ?? null ] ) );
        $batch = EvaluateJob::dueBatch( $repo, 50 );
        $ids   = array_map( static fn( array $r ): int => (int) $r['id'], $batch );
        $flags = array_map( static fn( array $r ): bool => VerifiedOpportunities::SOURCE === (string) $r['discovered_via'], $batch );
        $first = array_search( true, $flags, true );
        $tail  = false === $first ? [] : array_slice( $flags, (int) $first );
        $pos   = array_search( $orderId, $ids, true );
        IT::check( count( $ids ) <= 50 && count( $ids ) === count( array_unique( $ids ) ) && ! in_array( false, $tail, true ), 'the evaluation batch has no duplicates and lists every non-imported prospect before any imported one', (string) wp_json_encode( [ count( $ids ), $first ] ) );
        IT::check( false !== $pos && ( false === $first || $pos < $first ), 'the newly discovered prospect is evaluated before the imported backlog', (string) wp_json_encode( [ $pos, $first, $orderId ] ) );
    } finally {
        $repo->delete( $orderId );
    }

    // IndexNow (mocked endpoint) and the key file.
    $key = (string) $settings->get( 'seo.indexnow_key', '' );
    IT::check( 1 === preg_match( '/^[0-9a-f]{32}$/', $key ), 'IndexNow key generated (32 hex characters)', $key );
    $calls = (array) $GLOBALS['itIndexNow'];
    IT::check( count( $calls ) >= 1 && 'POST' === $calls[0]['method'] && 'https://api.indexnow.org/indexnow' === $calls[0]['url'], 'IndexNow submission POSTed to api.indexnow.org', (string) wp_json_encode( array_column( $calls, 'url' ) ) );
    $body = json_decode( (string) ( $calls[0]['body'] ?? '' ), true );
    $home = home_url( '/' );
    IT::check( is_array( $body ) && ( $body['host'] ?? '' ) === (string) wp_parse_url( $home, PHP_URL_HOST ) && ( $body['key'] ?? '' ) === $key && ( $body['keyLocation'] ?? '' ) === home_url( '/' . $key . '.txt' ), 'IndexNow JSON body has host, key and keyLocation', (string) ( $calls[0]['body'] ?? '' ) );
    $urls = is_array( $body['urlList'] ?? null ) ? $body['urlList'] : [];
    IT::check( in_array( $home, $urls, true ) && in_array( (string) get_permalink( $g['products']['ghk'] ), $urls, true ) && in_array( (string) get_permalink( $g['post'] ), $urls, true ), 'urlList holds the home page, products and posts', (string) wp_json_encode( $urls ) );
    $in = ppclg()->get( IndexNow::class )->status();
    IT::check( (int) $in['submitted_total'] === count( $urls ) && 202 === $in['last_status'] && '' === $in['last_error'], 'IndexNow status records the accepted submission', (string) wp_json_encode( $in ) );
    IT::check( 0 === has_action( 'parse_request', [ ppclg(), 'maybeServeIndexNowKey' ] ), 'key file handler hooked on parse_request at priority 0' );
    $serve = static function ( string $uri ): string {
        $code = sprintf(
            '$_SERVER["HTTP_HOST"]="localhost:8787"; $_SERVER["REQUEST_URI"]=%s; $_SERVER["REQUEST_METHOD"]="GET"; define("WP_USE_THEMES", true); require %s;',
            var_export( $uri, true ),
            var_export( ABSPATH . 'wp-blog-header.php', true )
        );
        $out = [];
        exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) . ' 2>/dev/null', $out );
        return implode( "\n", $out );
    };
    $served = $serve( '/' . $key . '.txt' );
    IT::check( $key === $served, 'GET /{key}.txt serves exactly the key (front-end request through wp())', mb_substr( $served, 0, 120 ) );
    $other = $serve( '/0123456789abcdef0123456789abcdef.txt' );
    IT::check( $key !== trim( $other ) && ! str_contains( $other, $key ), 'another /{hex}.txt path is not answered with the key', mb_substr( $other, 0, 80 ) );
    if ( [] !== (array) $GLOBALS['itBlocked'] ) {
        echo '    (offline guard refused requests to ' . count( (array) $GLOBALS['itBlocked'] ) . " hosts, e.g. " . implode( ', ', array_slice( array_keys( (array) $GLOBALS['itBlocked'] ), 0, 5 ) ) . ")\n";
    }

    // Site audit (read-only).
    $sa = ppclg()->get( SiteAuditor::class )->summary();
    IT::check( (int) $sa['pages'] > 0 && ! empty( $sa['complete'] ) && null !== $sa['checked_at'], 'site audit summary has pages > 0', (string) wp_json_encode( $sa ) );
    $auditRes = ppclg_it_rest( 'GET', '/seo/audit' );
    IT::check( 200 === $auditRes->get_status() && ! empty( $auditRes->get_data()['available'] ) && is_array( $auditRes->get_data()['issues'] ?? null ), 'GET /seo/audit lists the issues' );

    // Internal-link suggestion for the fixture post that mentions a product title; applied only through REST.
    $links = ppclg_it_rest( 'GET', '/seo/links' );
    $sugg  = null;
    foreach ( (array) ( $links->get_data()['items'] ?? [] ) as $s ) {
        if ( (int) $s['source_post_id'] === $g['post'] && (int) $s['target_id'] === $g['products']['ghk'] ) {
            $sugg = $s;
        }
    }
    IT::check( 200 === $links->get_status() && null !== $sugg && 'GHK-Cu 50mg' === ( $sugg['anchor'] ?? '' ) && 'pending' === ( $sugg['status'] ?? '' ), 'internal-link suggestion computed for the post that mentions "GHK-Cu 50mg"', (string) wp_json_encode( $links->get_data()['items'] ?? null ) );
    $before = (string) get_post( $g['post'] )->post_content;
    IT::check( ! str_contains( $before, '<a ' ), 'the run did not edit the post (suggestions only)' );
    if ( null !== $sugg ) {
        $revsBefore = count( wp_get_post_revisions( $g['post'] ) );
        $noConfirm  = ppclg_it_rest( 'POST', '/seo/links/apply', [ 'ids' => [ (int) $sugg['id'] ] ] );
        IT::check( 400 === $noConfirm->get_status() && (string) get_post( $g['post'] )->post_content === $before, 'apply without confirm refused and nothing edited', (string) $noConfirm->get_status() );
        $apply = ppclg_it_rest( 'POST', '/seo/links/apply', [ 'ids' => [ (int) $sugg['id'] ], 'confirm' => true ] );
        $data  = (array) $apply->get_data();
        IT::check( 200 === $apply->get_status() && ! empty( $data['ok'] ) && 1 === (int) ( $data['applied'] ?? 0 ), 'POST /seo/links/apply {confirm:true} applied the link', (string) wp_json_encode( $data ) );
        clean_post_cache( $g['post'] );
        $afterContent = (string) get_post( $g['post'] )->post_content;
        $url          = (string) get_permalink( $g['products']['ghk'] );
        $expectedHtml = (string) preg_replace( '/GHK-Cu 50mg/', '<a href="' . esc_url( $url ) . '">GHK-Cu 50mg</a>', $before, 1 );
        IT::check( $afterContent === $expectedHtml && $afterContent !== $before, 'only the anchor changed (byte-identical otherwise)', $afterContent );
        $revs = wp_get_post_revisions( $g['post'] );
        $last = reset( $revs );
        IT::check( count( $revs ) > $revsBefore && is_object( $last ) && (string) $last->post_content === $afterContent, 'WordPress saved a revision of the edit', $revsBefore . ' -> ' . count( $revs ) );
        $restorable = array_filter( $revs, static fn( $r ): bool => is_object( $r ) && (string) $r->post_content === $before );
        $appliedRow = (array) ( ppclg_it_rest( 'GET', '/seo/links', [ 'status' => 'applied' ] )->get_data()['items'][0] ?? [] );
        IT::check( [] !== $restorable && isset( $restorable[ (int) ( $appliedRow['restore_revision_id'] ?? 0 ) ] ), 'the content before the edit is kept as a revision to restore', $revsBefore . ' revisions before; restore id ' . (string) wp_json_encode( $appliedRow['restore_revision_id'] ?? null ) );
        IT::check( $auditLog->count( [ 'action' => 'seo.internal_link_applied' ] ) >= 1, 'applied link audited' );
        $again = ppclg_it_rest( 'POST', '/seo/links/apply', [ 'ids' => [ (int) $sugg['id'] ], 'confirm' => true ] );
        IT::check( empty( $again->get_data()['ok'] ) && (string) get_post( $g['post'] )->post_content === $afterContent, 'applying the same suggestion twice changes nothing', (string) wp_json_encode( $again->get_data()['results'] ?? null ) );
    }
} );

IT::step( 'Growth (b): research-only storefront passes and the submit step runs', static function (): void {
    $g = &$GLOBALS['itGrowth'];
    delete_option( StorefrontAudit::STALE_OPTION );
    foreach ( PPCLG_IT_RESEARCH_CATEGORIES as $old => $new ) {
        $r = wp_update_term( (int) $g['cats'][ $old ], 'product_cat', [ 'name' => $new, 'slug' => sanitize_title( $new ), 'description' => '' ] );
        IT::check( ! is_wp_error( $r ), "category renamed: {$old} -> {$new}", is_wp_error( $r ) ? $r->get_error_message() : '' );
    }
    IT::check( false !== get_option( StorefrontAudit::STALE_OPTION, false ), 'renaming a category marks the check stale (edited_product_cat hook)' );
    IT::check( (bool) wp_delete_post( $g['products']['syringes'], true ), 'syringe product deleted' );
    IT::check( true === wp_delete_term( (int) $g['tag'], 'product_tag' ), 'tag buy-semaglutide-canada deleted' );

    $re = ppclg_it_rest( 'POST', '/compliance/storefront/recheck' );
    $s  = (array) $re->get_data();
    IT::check( 200 === $re->get_status() && ! empty( $s['ok'] ), 'POST /compliance/storefront/recheck 200', (string) wp_json_encode( $s['message'] ?? null ) );
    IT::check( 'pass' === ( $s['status'] ?? '' ) && 'pass' === ( $s['effective'] ?? '' ) && empty( $s['blocking'] ) && [] === ( $s['findings'] ?? null ), 're-check passes', (string) wp_json_encode( [ $s['status'] ?? null, $s['findings'] ?? null ] ) );
    IT::check( ! ppclg()->get( StorefrontAudit::class )->isBlocking(), 'the gate no longer blocks' );
    $nothing = ppclg_it_rest( 'POST', '/compliance/storefront/acknowledge', [ 'confirm' => true ] );
    IT::check( 409 === $nothing->get_status(), 'nothing to acknowledge on a pass (409)', (string) $nothing->get_status() );

    $prospect = ppclg()->get( Prospects::class )->findByDomain( 'resources.test' );
    $policy   = ppclg()->get( Policy::class )->evaluate( (array) $prospect, [ 'unmapped_required' => [] ], [ 'kind' => 'listing_submission', 'method' => 'POST', 'fields' => [] ], 'https://resources.test/submit-resource/' );
    IT::check( ! in_array( Policy::REASON_STOREFRONT, (array) ( $policy['codes'] ?? [] ), true ), 'Policy no longer gives storefront_inconsistent', (string) wp_json_encode( $policy['codes'] ?? null ) );

    if ( ! empty( $g['message'] ) ) {
        $mailBefore = count( $GLOBALS['itMail'] );
        $send       = ppclg()->get( OutreachEngine::class )->sendNow( (int) $g['message'], 1 );
        IT::check( ! empty( $send['ok'] ) && count( $GLOBALS['itMail'] ) === $mailBefore + 1, 'the paused message sends once the storefront passes', (string) wp_json_encode( $send ) );
    }

    // POST /settings cannot record an acknowledgement, and turning the gate off needs confirmation.
    $ack = ppclg_it_rest( 'POST', '/settings', [ 'settings' => [ 'compliance' => [ 'acknowledged_hash' => str_repeat( 'b', 40 ), 'acknowledged_by' => 1 ] ] ] );
    IT::check( 200 === $ack->get_status() && '' === ppclg()->get( Settings::class )->get( 'compliance.acknowledged_hash' ), 'POST /settings ignores compliance.acknowledged_*', (string) $ack->get_status() );
    $off = ppclg_it_rest( 'POST', '/settings', [ 'settings' => [ 'compliance' => [ 'storefront_gate' => false ] ] ] );
    IT::check( 400 === $off->get_status() && true === ppclg()->get( Settings::class )->get( 'compliance.storefront_gate' ), 'turning the storefront gate off without confirm is refused (400)', (string) $off->get_status() );

    $run   = ppclg_it_grow();
    $state = $run['state'];
    echo sprintf( "    (run 2: %d step requests in %.1f s)\n", $run['steps'], $run['seconds'] );
    IT::check( 'completed' === ( $state['status'] ?? '' ) && ( $state['id'] ?? '' ) !== ( $g['run1']['state']['id'] ?? '' ), 'second growth run completed', (string) ( $state['status'] ?? '' ) );
    $submit = ppclg_it_step( $state, 'submit' );
    IT::check( in_array( $submit['status'] ?? '', [ 'done', 'skipped' ], true ), 'submit step is no longer blocked', (string) wp_json_encode( $submit ) );
    $pre = ppclg_it_step( $state, 'preflight' );
    IT::check( str_contains( (string) ( $pre['summary'] ?? '' ), 'no consumer-use wording found' ), 'preflight reports the passing storefront', (string) ( $pre['summary'] ?? '' ) );
    $keys = array_column( (array) ( $state['next_actions'] ?? [] ), 'key' );
    IT::check( ! in_array( 'storefront', $keys, true ), 'no storefront next action once the check passes', implode( ',', $keys ) );
    $vo = ppclg()->get( VerifiedOpportunities::class )->status();
    IT::check( ! empty( $vo['up_to_date'] ) && (int) $vo['imported'] === 595 - (int) $vo['skipped'], 'second run re-imports nothing new (still one task per item)', (string) wp_json_encode( [ $vo['imported'], $vo['skipped'] ] ) );
    IT::check( 'completed' === ppclg()->get( GrowthRun::class )->state()['status'], 'GrowthRun::state() reports the completed run' );
} );

IT::step( 'Growth: cleanup', static function (): void {
    $removed = ppclg_it_growth_cleanup();
    IT::check( $removed >= 12, 'fixture products, post and terms removed', (string) $removed );
    $left = get_posts( [ 'post_type' => [ 'product', 'post', 'page' ], 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => PPCLG_IT_MARKER ] ); // phpcs:ignore
    IT::check( [] === $left, 'no fixture posts left', (string) wp_json_encode( $left ) );
    $after = ppclg()->get( StorefrontAudit::class )->run();
    IT::check( 'pass' === $after['status'], 'storefront back to its original state (pass)', (string) wp_json_encode( $after['counts'] ) );
} );
