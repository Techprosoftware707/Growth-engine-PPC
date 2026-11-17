<?php
/**
 * Integration section (1.2.3): internal links never use the brand name as link text and never cross languages.
 * Required from run.php after render-check.php. Real WordPress + SQLite: real post meta, permalinks, revisions and
 * kses, and the page check over HTTP (ppclg_it_self_server), as on the live site.
 *
 * It reproduces the live case: the English Terms of Service page, whose licensors sentence reads "belong to Pure
 * Peptides Canada or its licensors", got "Pure Peptides Canada" linked to the French About page (served under
 * /fr/a-propos/ by the site's own French plugin, with the meta ppc_en_counterpart and the focus keyword "pure
 * peptides canada, fournisseur canadien de peptides, …") and "Peptides Canada" linked to the home page (focus keyword
 * "peptides canada"). The French plugin is stood in for by a page_link filter that gives the French pages their
 * /fr/ addresses. The two suggestions are stored as 1.2.2 made them (the second one after the first had been
 * applied), next to a link a person applied earlier; then the suggestion run and the automatic run go through.
 * Expected: no link is added at the brand name, both suggestions are closed as no longer valid with the reason, the
 * person's link is left alone, the Terms page still gets its one valid automatic link, and a French page is offered
 * the French About page by its next focus keyword. The site's French pages at the site root
 * (/acheter-peptides-canada/: no /fr/ address, no ppc_en_counterpart) are French by their text: such a page is
 * offered the French About page and not an English product named in it.
 *
 * Everything it creates is removed and the settings are restored at the end.
 */

declare(strict_types=1);

use PPC\LinkGrowth\Seo\InternalLinker;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;

IT::step( '1.2.3: internal links never use the brand name and never cross languages (the live Terms of Service case)', static function (): void {
    // Observable values are written out here (not read from the 1.2.3 classes), so the section also runs on 1.2.2.
    $brandNote = 'Not suggested: the link text is your brand name';
    $langNote  = 'Not suggested: the target page is in another language';
    $option    = 'ppclg_internal_links';
    wp_set_current_user( 1 );
    $do = static function ( string $method, string $route, array $params = [] ) {
        $req = new WP_REST_Request( $method, '/ppclg/v1' . $route );
        foreach ( $params as $k => $v ) {
            $req->set_param( $k, $v );
        }
        return rest_do_request( $req );
    };
    $settings   = ppclg()->get( Settings::class );
    $saved      = $settings->all();
    $stopServer = ppclg_it_self_server();
    $maxFetches = static fn(): int => 10;
    $perRequest = static fn(): int => 30; // this whole integration run is one PHP request
    add_filter( 'ppclg_render_check_max_fetches', $maxFetches );
    add_filter( 'ppclg_render_check_max_fetches_per_request', $perRequest );
    delete_transient( 'ppclg_render_check_pause' );
    delete_option( 'ppclg_internal_links_run' );
    // Stand-in for the live French plugin (ppc-french): the French pages are served under /fr/.
    $frUrls   = [];
    $pageLink = static function ( string $link, int $id ) use ( &$frUrls ): string {
        return $frUrls[ $id ] ?? $link;
    };
    add_filter( 'page_link', $pageLink, 10, 2 );
    $created = [];
    $mine    = [];

    try {
        $settings->update(
            [
                'site'      => [ 'brand_name' => 'Pure Peptides Canada', 'legal_name' => 'PURE PEPTIDES CANADA' ],
                'discovery' => [ 'brand_terms' => [ 'Pure Peptides Canada', 'purepeptidescanada.com', 'purepeptidescanada' ] ],
                'seo'       => [ 'internal_links' => true, 'auto_apply_links' => false, 'auto_apply_per_day' => 20, 'max_links_per_post' => 3 ],
            ]
        );
        $para      = static fn( string $html ): string => "<!-- wp:paragraph -->\n" . $html . "\n<!-- /wp:paragraph -->";
        $termsText = $para( '<p>This website is operated by Pure Peptides Canada, Toronto, Ontario, for research customers across the country.</p>' ) . "\n\n"
            . $para( '<p>All text, graphics, logos and images on this site belong to Pure Peptides Canada or its licensors and may not be copied without written permission.</p>' ) . "\n\n"
            . $para( '<p>Questions about an order? Write to your Canadian peptide supplier at any time of the week and we answer within one business day.</p>' );
        $condText  = $para( '<p>Le contenu de ce site appartient à Pure Peptides Canada, fournisseur canadien de peptides, ou à ses concédants de licence.</p>' );
        $privText  = $para( '<p>Pure Peptides Canada never sells or shares the personal information of its customers with anyone.</p>' );
        $page      = static function ( string $title, string $content = '', array $meta = [] ) use ( &$created ): int {
            $id = (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => wp_slash( $content ) ] );
            foreach ( $meta as $k => $v ) {
                update_post_meta( $id, $k, $v );
            }
            $created[] = $id;
            return $id;
        };
        $home    = $page( 'IT Home', $para( '<p>Research compounds with lab reports for every batch.</p>' ), [ 'rank_math_focus_keyword' => 'peptides canada' ] );
        $aboutEn = $page( 'IT About Us', '', [ 'rank_math_focus_keyword' => 'canadian peptide supplier' ] );
        $terms   = $page( 'IT Terms of Service', $termsText );
        $aboutFr = $page( 'IT À propos', $para( '<p>Pure Peptides Canada est un fournisseur canadien de peptides de recherche.</p>' ), [ 'rank_math_focus_keyword' => 'pure peptides canada, fournisseur canadien de peptides, peptides de recherche', 'ppc_en_counterpart' => $aboutEn ] );
        $cond    = $page( 'IT Conditions', $condText, [ 'ppc_en_counterpart' => $terms ] );
        $frUrls  = [ $aboutFr => home_url( '/fr/a-propos/' ), $cond => home_url( '/fr/conditions/' ) ];
        // A French page at the site root (as /acheter-peptides-canada/ on the live site) naming an English product.
        $product   = (int) wp_insert_post( [ 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Ipamorelin Research Vial' ] );
        $created[] = $product;
        $achat     = $page(
            'IT Acheter des peptides de recherche au Canada',
            $para( '<p>Prix en dollars canadiens, expédition suivie depuis le Canada et certificats d’analyse pour chaque lot.</p>' ) . "\n\n"
                . $para( '<p>Notre Ipamorelin Research Vial est expédié partout au pays. Nous préparons les commandes dans notre laboratoire et vous recevez un numéro de suivi dès le départ du colis, chez votre fournisseur canadien de peptides.</p>' )
        );
        $frAbout = (string) get_permalink( $aboutFr );
        $privacy = $page( 'IT Privacy Policy', str_replace( '<p>Pure Peptides Canada', '<p><a href="' . esc_url( $frAbout ) . '">Pure Peptides Canada</a>', $privText ) );
        IT::check( $frAbout === home_url( '/fr/a-propos/' ) && get_post( $terms )->post_content === $termsText && (int) get_post_meta( $aboutFr, 'ppc_en_counterpart', true ) === $aboutEn, 'fixtures: English Terms page, French About page at /fr/a-propos/ with ppc_en_counterpart, home page with focus keyword "peptides canada"', $frAbout );

        // The suggestions as 1.2.2 stored them, and a link a person applied earlier (left as it is).
        $seed = static function ( int $source, int $target, string $term, string $status, array $extra = [], ?string $in = null ) use ( $option, &$mine ): int {
            $store = get_option( $option, [] );
            $store = is_array( $store ) && isset( $store['items'] ) ? $store : [ 'next_id' => 1, 'generated_at' => null, 'items' => [] ];
            $occ   = InternalLinker::locate( InternalLinker::analyse( $in ?? (string) get_post( $source )->post_content ), $term );
            $id    = max( 1, (int) ( $store['next_id'] ?? 1 ), [] === $store['items'] ? 1 : max( array_map( 'intval', array_keys( $store['items'] ) ) ) + 1 );
            $store['items'][ (string) $id ] = array_merge(
                [
                    'id' => $id, 'source_post_id' => $source, 'source_title' => get_the_title( $source ), 'source_url' => (string) get_permalink( $source ),
                    'anchor' => (string) ( $occ['text'] ?? '' ), 'term' => $term, 'target_id' => $target, 'target_url' => (string) get_permalink( $target ),
                    'target_title' => get_the_title( $target ), 'excerpt' => (string) ( $occ['excerpt'] ?? '' ), 'context_hash' => (string) ( $occ['context_hash'] ?? '' ),
                    'relevance' => 50, 'status' => $status, 'created_at' => gmdate( 'c', time() - 86400 ), 'updated_at' => gmdate( 'c', time() - 86400 ),
                ],
                $extra
            );
            $store['next_id'] = $id + 1;
            update_option( $option, $store, false );
            $mine[] = $id;
            return $id;
        };
        $first   = preg_replace( '/Pure Peptides Canada/', '<a href="' . esc_url( $frAbout ) . '">Pure Peptides Canada</a>', $termsText, 1 );
        $idBrand = $seed( $terms, $aboutFr, 'pure peptides canada', 'pending', [ 'relevance' => 90 ] );
        $idTail  = $seed( $terms, $home, 'peptides canada', 'pending', [ 'relevance' => 73 ], $first );
        $idKept  = $seed( $privacy, $aboutFr, 'pure peptides canada', 'applied', [ 'auto' => false, 'applied_by' => 1, 'applied_at' => gmdate( 'c', time() - 3600 ) ], $privText );
        $items   = static fn(): array => (array) ( get_option( $option, [] )['items'] ?? [] );
        $kept    = $items()[ (string) $idKept ];
        IT::check( str_contains( (string) $items()[ (string) $idTail ]['excerpt'], 'belong to Pure Peptides Canada or its licensors' ), 'the second live suggestion is the tail of the brand name in the licensors sentence', (string) $items()[ (string) $idTail ]['excerpt'] );

        // The suggestion run, then the automatic run (automatic links on; WP-Cron: no user, kses on).
        wp_set_current_user( 0 );
        $linker = ppclg()->get( InternalLinker::class );
        $run    = $linker->run( TimeBudget::seconds( 25.0 ), [ 'restart' => true ] );
        $ofItem = static fn( int $src ): array => array_values( array_filter( $items(), static fn( array $i ): bool => $src === (int) $i['source_post_id'] && in_array( $i['status'], [ 'pending', 'not_shown' ], true ) ) );
        $frOpen = $ofItem( $cond );
        IT::check( 1 === count( $frOpen ) && $aboutFr === (int) $frOpen[0]['target_id'] && 'fournisseur canadien de peptides' === $frOpen[0]['term'], 'a French page is offered the French About page by its next focus keyword', wp_json_encode( array_map( static fn( array $i ): array => [ $i['target_id'], $i['term'] ], $frOpen ) ) );
        $achatOpen = array_map( static fn( array $i ): int => (int) $i['target_id'], $ofItem( $achat ) );
        IT::check( [ $aboutFr ] === $achatOpen, 'a French page at the site root (no /fr/, no ppc_en_counterpart) is French by its text: offered the French About page, not the English product named in it', wp_json_encode( [ $achatOpen, $aboutFr, $product ] ) );
        $termsOpen = $ofItem( $terms );
        IT::check( 1 === count( $termsOpen ) && $aboutEn === (int) $termsOpen[0]['target_id'], 'the Terms page is offered only the English About page, by words that are not the brand name', wp_json_encode( array_map( static fn( array $i ): array => [ $i['target_id'], $i['anchor'] ], $termsOpen ) ) );
        $settings->update( [ 'seo' => [ 'auto_apply_links' => true ] ] );
        $auto = $linker->autoApply( TimeBudget::seconds( 25.0 ) );
        wp_set_current_user( 1 );

        $now = (string) get_post( $terms )->post_content;
        IT::check( ! str_contains( $now, '>Pure Peptides Canada</a>' ) && ! str_contains( $now, '>Peptides Canada</a>' ) && ! str_contains( $now, '/fr/a-propos/' ), 'no link was added at the brand name on the Terms page', $now );
        $aboutTag = '<a href="' . esc_url( (string) get_permalink( $aboutEn ) ) . '">Canadian peptide supplier</a>';
        IT::check( str_replace( 'Canadian peptide supplier', $aboutTag, $termsText ) === $now, 'the Terms page got only its one valid automatic link (to the English About page), every other byte identical', wp_json_encode( [ $auto['message'] ?? null ] ) );
        $after = $items();
        IT::check( 'stale' === ( $after[ (string) $idBrand ]['status'] ?? '' ) && str_starts_with( (string) ( $after[ (string) $idBrand ]['note'] ?? '' ), $langNote ), '"Pure Peptides Canada" → the French About page: closed, "the target page is in another language"', wp_json_encode( $after[ (string) $idBrand ] ?? null ) );
        IT::check( 'stale' === ( $after[ (string) $idTail ]['status'] ?? '' ) && str_starts_with( (string) ( $after[ (string) $idTail ]['note'] ?? '' ), $brandNote ), '"Peptides Canada" → the home page: closed, "the link text is your brand name"', wp_json_encode( $after[ (string) $idTail ] ?? null ) );
        IT::check( $kept === ( $after[ (string) $idKept ] ?? null ) && str_contains( (string) get_post( $privacy )->post_content, '>Pure Peptides Canada</a>' ), 'the link a person applied earlier is left as it is' );
        IT::check( str_contains( (string) ( $run['summary'] ?? '' ), 'earlier suggestions were closed' ), 'the run summary says earlier suggestions were closed', (string) ( $run['summary'] ?? '' ) );
        $list = (array) $do( 'GET', '/seo/links', [ 'status' => 'stale', 'per_page' => 500 ] )->get_data();
        $rows = array_column( array_filter( (array) ( $list['items'] ?? [] ), static fn( array $i ): bool => in_array( (int) $i['id'], [ $idBrand, $idTail ], true ) ), 'note', 'id' );
        IT::check( 2 === count( $rows ) && str_starts_with( (string) ( $rows[ $idTail ] ?? '' ), $brandNote ), 'GET /seo/links?status=stale lists both with their note (the "No longer valid" tab)', wp_json_encode( $rows ) );

        // Nothing comes back on the next runs.
        wp_set_current_user( 0 );
        $linker->run( TimeBudget::seconds( 25.0 ), [ 'restart' => true ] );
        $again = $linker->autoApply( TimeBudget::seconds( 25.0 ) );
        wp_set_current_user( 1 );
        $open = array_filter( $items(), static fn( array $i ): bool => in_array( (int) $i['target_id'], [ $home, $aboutFr ], true ) && in_array( (int) $i['source_post_id'], [ $terms, $privacy, $home, $aboutEn ], true ) && 'stale' !== $i['status'] && 'applied' !== $i['status'] );
        IT::check( [] === $open && (string) get_post( $terms )->post_content === str_replace( 'Canadian peptide supplier', $aboutTag, $termsText ), 'the next runs suggest and add nothing at the brand name or across languages', wp_json_encode( [ $again['message'] ?? null, array_values( $open ) ] ) );
    } finally {
        wp_set_current_user( 1 );
        remove_filter( 'page_link', $pageLink, 10 );
        remove_filter( 'ppclg_render_check_max_fetches', $maxFetches );
        remove_filter( 'ppclg_render_check_max_fetches_per_request', $perRequest );
        delete_transient( 'ppclg_render_check_pause' );
        foreach ( $created as $id ) {
            wp_delete_post( $id, true );
        }
        $store = get_option( $option, [] );
        if ( is_array( $store ) && isset( $store['items'] ) ) {
            $store['items'] = array_filter( (array) $store['items'], static fn( $i ): bool => is_array( $i ) && ! in_array( (int) ( $i['source_post_id'] ?? 0 ), $created, true ) );
            update_option( $option, $store, false );
        }
        delete_option( 'ppclg_internal_links_run' );
        delete_option( 'ppclg_render_checks' );
        $settings->update( [ 'site' => [ 'brand_name' => $saved['site']['brand_name'], 'legal_name' => $saved['site']['legal_name'] ], 'discovery' => [ 'brand_terms' => $saved['discovery']['brand_terms'] ], 'seo' => $saved['seo'] ] );
        $stopServer();
    }
    $errors = ppclg()->get( \PPC\LinkGrowth\Repositories\Logs::class )->findBy( [ 'level' => 'error', 'channel' => 'seo' ], 'id', 'DESC', 5 );
    IT::check( [] === $errors, 'no SEO errors logged during the section', wp_json_encode( array_column( $errors, 'message' ) ) );
} );
