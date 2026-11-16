<?php
/**
 * Integration section (1.2.0 Build B): automatic internal links and their undo, and competitors found
 * automatically, on real WordPress + SQLite (real kses, revisions and block markup). Required from run.php after
 * autopilot.php; leaves Autopilot off and restores the settings it changed. Offline: the competitor provider is a
 * stand-in registered through the ppclg_backlink_sources filter.
 */

declare(strict_types=1);

use PPC\LinkGrowth\Automation\Autopilot;
use PPC\LinkGrowth\Automation\Jobs\InternalLinksJob;
use PPC\LinkGrowth\Discovery\CompetitorBacklinkSource;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Seo\InternalLinker;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;

IT::step( 'Autopilot: internal links applied automatically and undone; competitors found without a search key', static function (): void {
    wp_set_current_user( 1 );
    // 1.2.2: an automatic link needs the item's public page to show the words, so this WordPress is served over HTTP.
    // This whole integration run is one PHP request, so its page-check limit per request is lifted for the section.
    $stopServer = ppclg_it_self_server();
    $perRequest = static fn(): int => 30;
    add_filter( 'ppclg_render_check_max_fetches_per_request', $perRequest );
    delete_transient( 'ppclg_render_check_pause' );
    $do = static function ( string $method, string $route, array $params = [] ) {
        $req = new WP_REST_Request( $method, '/ppclg/v1' . $route );
        foreach ( $params as $k => $v ) {
            $req->set_param( $k, $v );
        }
        if ( 'POST' === $method ) {
            $req->set_header( 'Content-Type', 'application/json' );
            $req->set_body( wp_json_encode( $params ) );
        }
        return rest_do_request( $req );
    };
    $settings = ppclg()->get( Settings::class );
    $saved    = $settings->all();

    // A target product and a post that mentions it, with block markup, an entity and UTF-8 around the words.
    $product  = wp_insert_post( [ 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Sterile Saline Ampoule Pack' ] );
    $original = "<!-- wp:paragraph {\"className\":\"lab-note\"} -->\n<p class=\"lab-note\">Before use, open the sterile saline ampoule pack &amp; log it \u{2014} caf\u{00E9} notes.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Store at room temperature.</p>\n<!-- /wp:paragraph -->";
    $postId   = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Autopilot link source', 'post_content' => wp_slash( $original ) ] );
    IT::check( $product > 0 && $postId > 0 && get_post( $postId )->post_content === $original, 'fixture product and post created' );
    $settings->update( [ 'seo' => [ 'internal_links' => true, 'auto_apply_per_day' => 5, 'max_links_per_post' => 3 ] ] );

    // Off: the job only suggests.
    wp_set_current_user( 0 ); // WP-Cron: no user, so kses filters are active on save
    $job = ( new InternalLinksJob() )->handle( [ 'restart' => true ], TimeBudget::seconds( 20.0 ), ppclg()->container() );
    IT::check( 0 === ( $job['applied'] ?? -1 ) && get_post( $postId )->post_content === $original, 'Autopilot off: nothing applied, content unchanged', wp_json_encode( $job ) );

    // On (with consent through REST): the job applies the suggestion by itself.
    wp_set_current_user( 1 );
    $on = $do( 'POST', '/automation/autopilot', [ 'enabled' => true, 'confirm' => true ] );
    IT::check( 200 === $on->get_status() && true === ( $on->get_data()['enabled'] ?? null ), 'Autopilot on with confirmation' );
    $willRun = implode( ' ', (array) ( $on->get_data()['will_run'] ?? [] ) );
    IT::check( str_contains( $willRun, 'Add up to 5 internal links a day' ) && str_contains( $willRun, 'outreach emails' ), 'the status lists what runs automatically with the caps', $willRun );
    $revisionsBefore = count( wp_get_post_revisions( $postId ) );
    wp_set_current_user( 0 );
    $job = ( new InternalLinksJob() )->handle( [ 'restart' => true ], TimeBudget::seconds( 20.0 ), ppclg()->container() );
    $url = (string) get_permalink( $product );
    $now = (string) get_post( $postId )->post_content;
    IT::check( 1 === ( $job['applied'] ?? 0 ), 'Autopilot on: the job applied the link by itself', wp_json_encode( [ $job['applied'] ?? null, $job['summary'] ?? null ] ) );
    IT::check( str_replace( '<a href="' . esc_url( $url ) . '">sterile saline ampoule pack</a>', 'sterile saline ampoule pack', $now ) === $original, 'only the anchor was added; block comments, entity and UTF-8 bytes are identical', $now );
    IT::check( count( wp_get_post_revisions( $postId ) ) > $revisionsBefore, 'a revision was kept for the change' );
    $mineApplied = array_values( array_filter( ppclg()->get( InternalLinker::class )->suggestions( 'applied' ), static fn( array $i ): bool => $postId === (int) $i['source_post_id'] ) );
    $restoreId   = (int) ( $mineApplied[0]['restore_revision_id'] ?? 0 );
    IT::check( $restoreId > 0 && (string) get_post( $restoreId )->post_content === $original, 'the revision holds the content before the change' );
    $audit = ppclg()->get( AuditLog::class )->findBy( [ 'action' => 'seo.internal_link_applied', 'object_id' => $postId ] );
    IT::check( isset( $audit[0] ) && Autopilot::ACTOR === $audit[0]['actor'], 'audit-logged as Autopilot', wp_json_encode( $audit[0]['actor'] ?? null ) );

    // Growth status and the SEO page say it plainly.
    wp_set_current_user( 1 );
    $policy = (array) ( $do( 'GET', '/growth/status' )->get_data()['policy'] ?? [] );
    IT::check( true === ( $policy['auto_links'] ?? null ) && 5 === ( $policy['links_per_day'] ?? null ), 'growth policy reports automatic links', wp_json_encode( $policy ) );
    $links = (array) $do( 'GET', '/seo/links', [ 'status' => 'applied' ] )->get_data();
    $row   = array_values( array_filter( (array) ( $links['items'] ?? [] ), static fn( array $i ): bool => $postId === (int) $i['source_post_id'] ) );
    IT::check( 1 === ( $links['auto']['present'] ?? 0 ) && true === ( $row[0]['auto'] ?? null ), 'GET /seo/links reports 1 automatic link', wp_json_encode( [ $links['auto'] ?? null, $row[0] ?? null ] ) );

    // Undo needs confirmation, then restores the content exactly.
    $refused = $do( 'POST', '/seo/links/undo-auto', [] );
    IT::check( 400 === $refused->get_status(), 'undo without confirm is refused (400)' );
    $undo = $do( 'POST', '/seo/links/undo-auto', [ 'confirm' => true ] );
    IT::check( 200 === $undo->get_status() && 1 === ( $undo->get_data()['restored'] ?? 0 ), 'undo restored the item', wp_json_encode( $undo->get_data() ) );
    IT::check( get_post( $postId )->post_content === $original, 'content is byte-for-byte what it was before Autopilot' );
    wp_set_current_user( 0 );
    $again = ( new InternalLinksJob() )->handle( [ 'restart' => true ], TimeBudget::seconds( 20.0 ), ppclg()->container() );
    IT::check( 0 === ( $again['applied'] ?? -1 ) && get_post( $postId )->post_content === $original, 'an undone link is not added again', wp_json_encode( $again ) );
    wp_set_current_user( 1 );

    // Competitors without a search key: a provider that lists competitors (stand-in for SE Ranking).
    $provider = new class() implements \PPC\LinkGrowth\Integrations\Contracts\BacklinkSource {
        public function id(): string {
            return 'it_rank';
        }
        public function label(): string {
            return 'SE Ranking';
        }
        public function isConfigured(): bool {
            return true;
        }
        public function lastError(): string {
            return '';
        }
        /** @return string[] */
        public function competitors( string $domain ): array {
            return [ 'amazon.ca', 'en.wikipedia.org', 'canada.ca', 'cbc.ca', 'rxpharmacy-online.com', 'it-labsupply.test', 'it-bioreagents.test', $domain ];
        }
        public function backlinks( string $domain, int $limit = 200, int $perDomain = 3 ): array {
            return [];
        }
    };
    $filter = static fn( array $list ): array => array_merge( $list, [ $provider ] );
    add_filter( 'ppclg_backlink_sources', $filter );
    $settings->update( [ 'discovery' => [ 'competitor_domains' => [], 'competitor_domains_auto' => [] ] ] );
    delete_option( CompetitorBacklinkSource::DETECT_OPTION );
    delete_option( CompetitorBacklinkSource::DETECT_SEEN_OPTION );
    $detect = ppclg()->get( CompetitorBacklinkSource::class )->autoDetect();
    IT::check( is_array( $detect ) && [ 'it-labsupply.test', 'it-bioreagents.test' ] === $detect['added'], 'Autopilot found the two real competitors', wp_json_encode( $detect ) );
    IT::check( isset( $detect['filtered']['amazon.ca'], $detect['filtered']['wikipedia.org'], $detect['filtered']['canada.ca'], $detect['filtered']['cbc.ca'], $detect['filtered']['rxpharmacy-online.com'] ), 'marketplaces, reference, government, news and pharmacy sites were left out' );
    $view = (array) ( $do( 'GET', '/settings' )->get_data()['settings']['discovery'] ?? [] );
    IT::check( [] === ( $view['competitor_domains'] ?? null ) && [ 'it-labsupply.test', 'it-bioreagents.test' ] === ( $view['competitor_domains_auto'] ?? null ), 'Settings shows them apart from the owner list' );
    $remove = $do( 'POST', '/settings', [ 'settings' => [ 'discovery' => [ 'competitor_domains_auto' => [ 'it-labsupply.test' ] ] ] ] );
    IT::check( 200 === $remove->get_status() && [ 'it-labsupply.test' ] === ppclg()->get( Settings::class )->get( 'discovery.competitor_domains_auto' ), 'one can be removed in Settings', wp_json_encode( $remove->get_data() ) );
    $redo = ppclg()->get( CompetitorBacklinkSource::class )->autoDetect( true );
    IT::check( [] === ( $redo['added'] ?? null ) && 'removed by you earlier' === ( $redo['filtered']['it-bioreagents.test'] ?? '' ), 'a removed competitor is not added back', wp_json_encode( $redo ) );
    IT::check( null === ppclg()->get( CompetitorBacklinkSource::class )->autoDetect(), 'detection runs at most once every 30 days' );
    remove_filter( 'ppclg_backlink_sources', $filter );

    // Clean up: Autopilot off, settings and options back.
    $off = $do( 'POST', '/automation/autopilot', [ 'enabled' => false ] );
    IT::check( 200 === $off->get_status() && false === ( $off->get_data()['enabled'] ?? null ), 'Autopilot off again' );
    $settings->update( [ 'seo' => $saved['seo'], 'discovery' => [ 'competitor_domains' => $saved['discovery']['competitor_domains'], 'competitor_domains_auto' => $saved['discovery']['competitor_domains_auto'] ?? [] ], 'automation' => [ 'enabled' => (bool) $saved['automation']['enabled'] ] ] );
    foreach ( [ CompetitorBacklinkSource::DETECT_OPTION, CompetitorBacklinkSource::DETECT_SEEN_OPTION, CompetitorBacklinkSource::DETECT_RETRY_OPTION ] as $opt ) {
        delete_option( $opt );
    }
    wp_delete_post( $postId, true );
    wp_delete_post( $product, true );
    $stopServer();
    remove_filter( 'ppclg_render_check_max_fetches_per_request', $perRequest );
    delete_transient( 'ppclg_render_check_pause' );
    delete_option( 'ppclg_render_checks' ); // the 1.2.2 page-check verdicts of this section's items
    $errors = ppclg()->get( \PPC\LinkGrowth\Repositories\Logs::class )->findBy( [ 'level' => 'error', 'channel' => 'seo' ], 'id', 'DESC', 5 );
    IT::check( [] === $errors, 'no SEO errors logged during the section', wp_json_encode( array_column( $errors, 'message' ) ) );
} );
