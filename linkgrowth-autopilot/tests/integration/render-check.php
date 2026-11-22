<?php
/**
 * Integration section (1.2.2): automatic internal links only where visitors see them, and items with administrator
 * markup linked without kses rewriting them. Required from run.php after footer-refresh.php. Real WordPress +
 * SQLite + WooCommerce, real kses and revisions, real templates: this WordPress is served over HTTP on its own
 * address (lga_it_self_server) and a temporary classic theme reproduces the live one, whose front-page.php is
 * hard-coded and never prints the front page's content.
 *
 * 1. A page gets an automatic link while it is an ordinary page (index.php prints the_content()).
 * 2. It becomes the static front page: the next run finds that its public page does not show its text, takes the
 *    automatic link back out (byte for byte, with a revision, audited) and sets its suggestions aside as not shown;
 *    GET /seo/links lists them under status=not_shown with the note, in small pages.
 * 3. A post an administrator wrote with an inline SVG and a FAQPage JSON-LD script (kses would strip both when the
 *    background job saves it without a user) is refused while the consenting owner may not save unfiltered HTML, and
 *    linked once it may: saved as that owner, only the <a> added, the previous user and the kses filters back after.
 *
 * Everything it creates is removed and the theme, front page settings and Autopilot are restored at the end.
 */

declare(strict_types=1);

use LinkGrowth\Autopilot\Automation\Autopilot;
use LinkGrowth\Autopilot\Automation\Jobs\InternalLinksJob;
use LinkGrowth\Autopilot\Repositories\AuditLog;
use LinkGrowth\Autopilot\Seo\InternalLinker;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\TimeBudget;

IT::step( '1.2.2: automatic links only on pages that show them; administrator markup saved as the consenting owner', static function (): void {
    // Observable values are written out here (not read from the 1.2.2 classes), so the section also runs on 1.2.1.
    $renderOption = 'lga_render_checks';
    $ksesRefusal  = 'Not applied automatically: WordPress would re-filter other markup in this item when saving it.';
    $removedNote  = 'Removed automatically: this page’s template doesn’t display its content';
    wp_set_current_user( 1 );
    $do = static function ( string $method, string $route, array $params = [] ) {
        $req = new WP_REST_Request( $method, '/lga/v1' . $route );
        foreach ( $params as $k => $v ) {
            $req->set_param( $k, $v );
        }
        if ( 'POST' === $method ) {
            $req->set_header( 'Content-Type', 'application/json' );
            $req->set_body( wp_json_encode( $params ) );
        }
        return rest_do_request( $req );
    };
    $job = static function (): array {
        wp_set_current_user( 0 ); // WP-Cron: no user, so the kses filters are on for every save
        $r = ( new InternalLinksJob() )->handle( [ 'restart' => true ], TimeBudget::seconds( 25.0 ), lga()->container() );
        return $r;
    };
    $settings    = lga()->get( Settings::class );
    $saved       = $settings->all();
    $saveFront   = [ get_option( 'show_on_front' ), (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ];
    $stylesheet  = get_stylesheet();
    $stopServer  = lga_it_self_server();
    $themeDir    = get_theme_root() . '/lga-it-classic';
    $created     = [];
    $maxFetches  = static fn(): int => 10; // the run's own items are compared, whatever else the install holds
    $perRequest  = static fn(): int => 30; // this whole integration run is one PHP request
    add_filter( 'lga_render_check_max_fetches', $maxFetches );
    add_filter( 'lga_render_check_max_fetches_per_request', $perRequest );
    delete_transient( 'lga_render_check_pause' ); // no pause left by an earlier section

    try {
        // A classic theme like the live one: index.php prints the content, front-page.php is hard-coded.
        wp_mkdir_p( $themeDir );
        file_put_contents( $themeDir . '/style.css', "/*\nTheme Name: LGA IT Classic\nVersion: 1.0\n*/\n" );
        file_put_contents( $themeDir . '/index.php', "<!DOCTYPE html>\n<html <?php language_attributes(); ?>><head><meta charset=\"utf-8\"><?php wp_head(); ?></head>\n<body <?php body_class(); ?>><header><nav><a href=\"/\">Home</a> <a href=\"/shop/\">Shop</a></nav></header><main>\n<?php while ( have_posts() ) : the_post(); ?><article><h1><?php the_title(); ?></h1><?php the_content(); ?></article><?php endwhile; ?>\n</main><footer>Workshop supplies since 2019</footer><?php wp_footer(); ?></body></html>\n" );
        file_put_contents( $themeDir . '/front-page.php', "<!DOCTYPE html>\n<html <?php language_attributes(); ?>><head><meta charset=\"utf-8\"><?php wp_head(); ?></head>\n<body <?php body_class(); ?>><header><nav><a href=\"/\">Home</a></nav></header><main><section class=\"hero\"><h1>Research fasteners, tested in Portland</h1><p>Every batch ships with an independent lab report.</p><p>Where can I see the lab reports? On every product page.</p></section></main><?php wp_footer(); ?></body></html>\n" );
        wp_clean_themes_cache();
        switch_theme( 'lga-it-classic' );
        IT::check( 'lga-it-classic' === get_stylesheet(), 'temporary classic theme active (front-page.php ignores the page content)' );

        // Link targets and the two items.
        $product  = wp_insert_post( [ 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Sterile Saline Ampoule Pack' ] );
        $standard = wp_insert_post( [ 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Lyophilised Fastener Standard' ] );
        $homeText = "<!-- wp:paragraph -->\n<p>Our laboratory team opens a sterile saline ampoule pack before every reconstitution and logs the batch number.</p>\n<!-- /wp:paragraph -->\n\n"
            . "<!-- wp:paragraph -->\n<p>Each shipment is checked against its certificate of conformance on arrival and stored away from light.</p>\n<!-- /wp:paragraph -->\n\n"
            . "<!-- wp:paragraph -->\n<p>Questions about an order are answered by the same team within one business day by email.</p>\n<!-- /wp:paragraph -->";
        $home     = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'IT Home', 'post_content' => wp_slash( $homeText ) ] );
        // Written by an administrator: an inline SVG diagram and FAQPage JSON-LD in a Custom HTML block (kses strips both).
        $article = "<!-- wp:html -->\n<div class=\"ppl\"><p>Half-life extension keeps a fastener in circulation longer; a lyophilised fastener standard is the usual reference in these assays.</p>\n"
            . "<figure><svg viewBox=\"0 0 120 40\" role=\"img\" aria-labelledby=\"it-t\" xmlns=\"http://www.w3.org/2000/svg\"><title id=\"it-t\">Half-life</title><rect x=\"1\" y=\"1\" width=\"50\" height=\"20\"/><text x=\"5\" y=\"15\">Albumin</text></svg></figure>\n"
            . "<p>Results are compared with the reference run on the same plate, and outliers are repeated before reporting.</p>\n"
            . "<script type=\"application/ld+json\">{\"@context\":\"https://schema.org\",\"@type\":\"FAQPage\",\"mainEntity\":[{\"@type\":\"Question\",\"name\":\"What is half-life?\"}]}</script></div>\n<!-- /wp:html -->";
        $post     = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'IT Half-life tricks', 'post_content' => wp_slash( $article ), 'post_author' => 1 ] );
        $created  = [ $product, $standard, $home, $post ];
        IT::check( $product > 0 && $standard > 0 && $home > 0 && $post > 0 && get_post( $post )->post_content === $article, 'fixtures created (the article keeps its SVG and script: saved by an administrator)' );
        IT::check( wp_kses_post( $article ) !== $article, 'kses would strip the article’s SVG and script (precondition of the 8 live refusals)' );

        $settings->update( [ 'seo' => [ 'internal_links' => true, 'auto_apply_per_day' => 20, 'max_links_per_post' => 3 ] ] );
        wp_set_current_user( 1 );
        $on = $do( 'POST', '/automation/autopilot', [ 'enabled' => true, 'confirm' => true ] );
        IT::check( 200 === $on->get_status() && 1 === (int) ( lga()->get( Autopilot::class )->consent()['user_id'] ?? 0 ), 'Autopilot on; the consent names user 1 (an administrator)' );

        // The consenting owner may not save unfiltered HTML (e.g. DISALLOW_UNFILTERED_HTML): the refusal stays.
        $deny = static fn( array $caps, string $cap ): array => 'unfiltered_html' === $cap ? [ 'do_not_allow' ] : $caps;
        add_filter( 'map_meta_cap', $deny, 10, 2 );
        $r1      = $job();
        $linker  = lga()->get( InternalLinker::class );
        $mine    = static fn( int $src, string $status = '' ): array => array_values( array_filter( $linker->suggestions( $status ), static fn( array $i ): bool => $src === (int) $i['source_post_id'] ) );
        $homeNow = (string) get_post( $home )->post_content;
        $artRows = $mine( $post );
        IT::check( 1 === count( $mine( $home, 'applied' ) ) && str_contains( $homeNow, '<a href="' . esc_url( (string) get_permalink( $product ) ) . '">sterile saline ampoule pack</a>' ), 'an ordinary page that shows its content gets the automatic link', wp_json_encode( [ $r1['summary'] ?? null ] ) );
        IT::check( isset( $artRows[0] ) && 'pending' === $artRows[0]['status'] && str_starts_with( (string) ( $artRows[0]['auto_skip'] ?? '' ), $ksesRefusal ) && get_post( $post )->post_content === $article, 'owner without unfiltered_html: the article is left for a person, unchanged', wp_json_encode( $artRows[0] ?? null ) );
        remove_filter( 'map_meta_cap', $deny, 10 );
        $revBefore = count( wp_get_post_revisions( $post ) );

        // The owner may save it unchanged: the next run tries it again and saves it as user 1.
        $r2      = $job();
        $artNow  = (string) get_post( $post )->post_content;
        $linkTag = '<a href="' . esc_url( (string) get_permalink( $standard ) ) . '">lyophilised fastener standard</a>';
        IT::check( str_replace( $linkTag, 'lyophilised fastener standard', $artNow ) === $article && str_contains( $artNow, $linkTag ), 'the article got the link with every other byte identical (SVG and JSON-LD kept)', wp_json_encode( $r2['summary'] ?? null ) );
        IT::check( 0 === get_current_user_id() && false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'the background user is back and the kses filters are on again' );
        $revs = wp_get_post_revisions( $post );
        IT::check( count( $revs ) > $revBefore && 1 === (int) reset( $revs )->post_author, 'a revision was kept, made by the owner' );
        $audit = lga()->get( AuditLog::class )->findBy( [ 'action' => 'seo.internal_link_applied', 'object_id' => $post ] );
        IT::check( isset( $audit[0] ) && Autopilot::ACTOR === $audit[0]['actor'] && 1 === (int) ( $audit[0]['details']['saved_as'] ?? 0 ) && true === ( $audit[0]['details']['byte_identical'] ?? null ), 'audited as Autopilot, saved_as user 1, byte identical', wp_json_encode( $audit[0] ?? null ) );

        // The page becomes the static front page of this theme: front-page.php never prints its content.
        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', $home );
        $front = wp_remote_get( home_url( '/' ), [ 'timeout' => 10 ] );
        IT::check( ! is_wp_error( $front ) && str_contains( (string) wp_remote_retrieve_body( $front ), 'page-id-' . $home ) && ! str_contains( (string) wp_remote_retrieve_body( $front ), 'reconstitution' ), 'precondition: the home page is served without its content (like the live front page)' );
        $revHome = count( wp_get_post_revisions( $home ) );
        $r3      = $job();
        IT::check( get_post( $home )->post_content === $homeText, 'the automatic link on the front page was taken back out: content byte for byte as before', wp_json_encode( $r3['summary'] ?? null ) );
        IT::check( count( wp_get_post_revisions( $home ) ) > $revHome, 'a revision was kept for the removal' );
        $removed = lga()->get( AuditLog::class )->findBy( [ 'action' => 'seo.internal_link_auto_removed', 'object_id' => $home ] );
        IT::check( isset( $removed[0] ) && Autopilot::ACTOR === $removed[0]['actor'] && [ 'restored' ] === ( $removed[0]['details']['modes'] ?? null ), 'the removal is audited as Autopilot (restored)', wp_json_encode( $removed[0] ?? null ) );
        $homeRows = $mine( $home );
        IT::check( [] !== $homeRows && [] === array_filter( $homeRows, static fn( array $i ): bool => 'not_shown' !== $i['status'] ), 'every suggestion on the front page is set aside as not shown', wp_json_encode( array_column( $homeRows, 'status' ) ) );
        IT::check( 'hidden' === ( ( (array) get_option( $renderOption, [] ) )[ $home ]['verdict'] ?? '' ), 'the verdict is kept for the page' );
        IT::check( str_contains( (string) ( $r3['summary'] ?? '' ), 'automatic link removed from pages that don’t display their content' ), 'the job summary says so', (string) ( $r3['summary'] ?? '' ) );

        // Nothing is added there on the next run, and the list explains it in small pages.
        $r4 = $job();
        IT::check( get_post( $home )->post_content === $homeText, 'no automatic link is added to the front page again', wp_json_encode( $r4['summary'] ?? null ) );
        wp_set_current_user( 1 );
        $list = (array) $do( 'GET', '/seo/links', [ 'status' => 'not_shown', 'per_page' => 50 ] )->get_data();
        $row  = array_values( array_filter( (array) ( $list['items'] ?? [] ), static fn( array $i ): bool => $home === (int) $i['source_post_id'] ) );
        IT::check( isset( $row[0] ) && str_starts_with( (string) $row[0]['note'], $removedNote ) && 50 === ( $list['per_page'] ?? 0 ) && isset( $list['total'], $list['pages'] ) && ( $list['counts']['not_shown'] ?? 0 ) >= 1, 'GET /seo/links?status=not_shown lists it with the note, paged', wp_json_encode( [ $row[0]['note'] ?? null, $list['total'] ?? null ] ) );
        $pending = (array) $do( 'GET', '/seo/links', [ 'status' => 'pending', 'per_page' => 50 ] )->get_data();
        IT::check( count( (array) ( $pending['items'] ?? [] ) ) <= 50 && strlen( (string) wp_json_encode( $pending ) ) < 100 * 1024, 'a page of 50 pending suggestions is small', (string) strlen( (string) wp_json_encode( $pending ) ) );
    } finally {
        remove_filter( 'lga_render_check_max_fetches', $maxFetches );
        remove_filter( 'lga_render_check_max_fetches_per_request', $perRequest );
        delete_transient( 'lga_render_check_pause' );
        wp_set_current_user( 1 );
        $do( 'POST', '/automation/autopilot', [ 'enabled' => false ] );
        update_option( 'show_on_front', $saveFront[0] );
        update_option( 'page_on_front', $saveFront[1] );
        update_option( 'page_for_posts', $saveFront[2] );
        switch_theme( $stylesheet );
        foreach ( [ 'style.css', 'index.php', 'front-page.php' ] as $f ) {
            @unlink( $themeDir . '/' . $f );
        }
        @rmdir( $themeDir );
        wp_clean_themes_cache();
        foreach ( $created as $id ) {
            wp_delete_post( $id, true );
        }
        $settings->update( [ 'seo' => $saved['seo'], 'automation' => [ 'enabled' => (bool) $saved['automation']['enabled'] ] ] );
        delete_option( $renderOption );
        $stopServer();
    }
    IT::check( $stylesheet === get_stylesheet() && ! is_dir( $themeDir ), 'theme and front page settings restored, temporary theme removed' );
    $errors = lga()->get( \LinkGrowth\Autopilot\Repositories\Logs::class )->findBy( [ 'level' => 'error', 'channel' => 'seo' ], 'id', 'DESC', 5 );
    IT::check( [] === $errors, 'no SEO errors logged during the section', wp_json_encode( array_column( $errors, 'message' ) ) );
} );
