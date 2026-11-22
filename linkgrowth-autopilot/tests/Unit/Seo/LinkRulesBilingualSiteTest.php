<?php
/**
 * The brand and language rules of internal links on a bilingual site (English pages plus French pages under /fr/
 * and at the site root), through InternalLinker's public API:
 * - the brand name and its tail ("Supply Co" in "belong to Northwind Supply Co or its licensors") are never link
 *   text, even when a French page's first focus keyword is the brand ("northwind supply co, fournisseur de fixations
 *   industrielles, …"; translated items carry the owner's counterpart meta, seo.language_counterpart_meta_key);
 * - no suggestion uses the brand as link text, none crosses languages, words inside the brand name are never linked;
 * - suggestions made before (pending or not shown) are closed as no longer valid with the reason on the next
 *   suggestion run, by the automatic run and by a person's Apply, and are never applied; applied, dismissed and
 *   undone ones are left as they are;
 * - a page whose first focus keyword is the brand is a target by its next usable keyword, for its own language.
 *
 * The expected notes are written out (not read from the class constants).
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Seo;

require_once dirname( __DIR__, 2 ) . '/Support/StubsSeo.php';

use LinkGrowth\Autopilot\Automation\Audit;
use LinkGrowth\Autopilot\Seo\InternalLinker;
use LinkGrowth\Autopilot\Support\Locks;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\TimeBudget;
use LinkGrowth\Autopilot\Tests\Support\Container;
use LinkGrowth\Autopilot\Tests\Support\StubsSeo;
use LinkGrowth\Autopilot\Tests\Support\TestCase;

final class LinkRulesBilingualSiteTest extends TestCase {

    private const BRAND_NOTE    = 'Not suggested: the link text is your brand name';
    private const LANGUAGE_NOTE = 'Not suggested: the target page is in another language';

    private const HOME = 'https://northwind.test';

    private const TERMS = '<p>This website is operated by Northwind Supply Co, Portland, Oregon.</p>'
        . '<p>All text, graphics, logos and images on this site belong to Northwind Supply Co or its licensors and may not be copied.</p>'
        . '<p>Questions about an order? Write to your industrial fastener supplier at any time of the week.</p>';

    private const CONDITIONS = '<p>Le contenu de ce site appartient à Northwind Supply Co, fournisseur de fixations industrielles, ou à ses concédants de licence.</p>';

    protected function setUp(): void {
        parent::setUp();
        StubsSeo::reset();
        // The internal_links job runs without a logged-in user (WP-Cron).
        $GLOBALS['lga_test_caps']    = [];
        $GLOBALS['lga_test_user_id'] = 0;
        unset( $GLOBALS['lga_seo_update_fails'] );
        $this->settings()->update( [ 'site' => [ 'brand_name' => 'Northwind Supply Co', 'legal_name' => 'NORTHWIND SUPPLY CO' ], 'discovery' => [ 'brand_terms' => [ 'Northwind Supply Co', 'northwindsupplyco.example', 'northwindsupplyco' ] ], 'seo' => [ 'language_counterpart_meta_key' => 'translation_of' ] ] );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['lga_test_caps'] );
        $GLOBALS['lga_test_user_id'] = 0;
        parent::tearDown();
    }

    private function settings(): Settings {
        return Container::get( Settings::class );
    }

    private function linker(): InternalLinker {
        $linker = new InternalLinker( $this->settings(), Container::get( Logger::class ), Container::get( Locks::class ), Container::get( Audit::class ) );
        Container::plugin()->container()->set( InternalLinker::class, $linker );
        return $linker;
    }

    /** @return array<int,array<string,mixed>> */
    private function bySource( InternalLinker $linker, int $source, string $status = '' ): array {
        return array_values( array_filter( $linker->suggestions( $status ), static fn( array $s ): bool => $source === (int) $s['source_post_id'] ) );
    }

    /** @return array<string,mixed> one stored suggestion */
    private function item( string $id ): array {
        return (array) ( get_option( InternalLinker::OPTION, [] )['items'][ $id ] ?? [] );
    }

    /** The site's pages: home, English About, Terms, the French About page and a French Conditions page. */
    private function seedBilingualSite(): void {
        StubsSeo::addPost( 134, [ 'post_type' => 'page', 'post_title' => 'Home', 'url' => self::HOME . '/' ], [ 'rank_math_focus_keyword' => 'supply co' ] );
        StubsSeo::addPost( 138, [ 'post_type' => 'page', 'post_title' => 'About Us', 'post_name' => 'about-us' ], [ 'rank_math_focus_keyword' => 'industrial fastener supplier' ] );
        StubsSeo::addPost( 158, [ 'post_type' => 'page', 'post_title' => 'Terms of Service', 'post_name' => 'terms-of-service', 'post_content' => self::TERMS ] );
        StubsSeo::addPost( 12840, [ 'post_type' => 'page', 'post_title' => 'À propos', 'url' => self::HOME . '/fr/a-propos/', 'post_content' => '<p>Northwind Supply Co est un fournisseur de fixations industrielles de recherche.</p>' ], [ 'rank_math_focus_keyword' => 'northwind supply co, fournisseur de fixations industrielles, fixations de laboratoire', 'translation_of' => 138 ] );
        StubsSeo::addPost( 12841, [ 'post_type' => 'page', 'post_title' => 'Conditions', 'url' => self::HOME . '/fr/conditions/', 'post_content' => self::CONDITIONS ], [ 'translation_of' => 158 ] );
    }

    /**
     * A suggestion as an earlier version stored it: the first occurrence of $term in $locateIn (the source content
     * unless given).
     *
     * @param array<string,mixed> $extra
     */
    private function seedItem( string $id, int $source, int $target, string $term, string $status, array $extra = [], ?string $locateIn = null ): void {
        $occ = InternalLinker::locate( InternalLinker::analyse( $locateIn ?? (string) StubsSeo::$posts[ $source ]['post_content'] ), $term );
        $this->assertNotNull( $occ, 'fixture: ' . $term );
        $store = get_option( InternalLinker::OPTION, [] );
        $store = is_array( $store ) && isset( $store['items'] ) ? $store : [ 'next_id' => 1, 'generated_at' => '2026-11-10T00:00:00+00:00', 'items' => [] ];
        $store['items'][ $id ] = array_merge(
            [
                'id'             => (int) $id,
                'source_post_id' => $source,
                'source_title'   => (string) StubsSeo::$posts[ $source ]['post_title'],
                'source_url'     => StubsSeo::permalink( $source ),
                'anchor'         => $occ['text'],
                'term'           => $term,
                'target_id'      => $target,
                'target_url'     => StubsSeo::permalink( $target ),
                'target_title'   => (string) StubsSeo::$posts[ $target ]['post_title'],
                'excerpt'        => $occ['excerpt'],
                'context_hash'   => $occ['context_hash'],
                'relevance'      => 50,
                'status'         => $status,
                'created_at'     => '2026-11-10T00:00:00+00:00',
                'updated_at'     => '2026-11-10T00:00:00+00:00',
            ],
            $extra
        );
        $store['next_id'] = max( (int) $store['next_id'], (int) $id + 1 );
        update_option( InternalLinker::OPTION, $store );
    }

    /** Two suggestions as an earlier version made them (the second after the first had been applied) and a third. */
    private function seedEarlierSuggestions(): void {
        $this->seedItem( '1', 158, 12840, 'northwind supply co', 'pending', [ 'relevance' => 90 ] );
        $firstLinked = preg_replace( '/Northwind Supply Co/', '<a href="' . self::HOME . '/fr/a-propos/">Northwind Supply Co</a>', self::TERMS, 1 );
        $this->seedItem( '2', 158, 134, 'supply co', 'pending', [ 'relevance' => 73 ], $firstLinked );
        StubsSeo::addPost( 160, [ 'post_title' => 'Lab notes', 'post_content' => '<p>Our team keeps notes on fixations de laboratoire for every lot we receive.</p>' ] );
        $this->seedItem( '3', 160, 12840, 'fixations de laboratoire', 'pending', [ 'relevance' => 60 ] );
        $this->assertStringContainsString( 'belong to Northwind Supply Co', (string) $this->item( '2' )['excerpt'], 'fixture: the second suggestion is the tail of the brand name in the licensors sentence' );
    }

    public function testTheBilingualPagesGetNoBrandOrCrossLanguageSuggestions(): void {
        $this->seedBilingualSite();
        $linker = $this->linker();
        $res    = $linker->run( TimeBudget::seconds( 30 ) );
        $this->assertTrue( $res['ok'] );
        $this->assertSame( 2, $res['targets'], 'the English About page and the French About page by its next keyword; the home page ("supply co") is no target' );

        $terms = $this->bySource( $linker, 158 );
        $this->assertCount( 1, $terms, wp_json_encode( $terms ) );
        $this->assertSame( [ 138, 'industrial fastener supplier' ], [ $terms[0]['target_id'], $terms[0]['anchor'] ], 'only the English page, by words that are not the brand' );

        $conditions = $this->bySource( $linker, 12841 );
        $this->assertCount( 1, $conditions, wp_json_encode( $conditions ) );
        $this->assertSame( [ 12840, 'fournisseur de fixations industrielles' ], [ $conditions[0]['target_id'], $conditions[0]['term'] ], 'a French page links to the French About page by its next focus keyword' );

        foreach ( $linker->suggestions( '' ) as $s ) {
            $this->assertNotContains( mb_strtolower( (string) $s['term'] ), [ 'northwind supply co', 'supply co' ], 'no brand anchor' );
            $this->assertNotSame( 134, (int) $s['target_id'] );
            $this->assertSame( str_contains( (string) $s['source_url'], '/fr/' ), str_contains( (string) $s['target_url'], '/fr/' ), 'never across languages: ' . $s['source_url'] . ' → ' . $s['target_url'] );
        }
    }

    public function testEarlierSuggestionsBreakingARuleAreClosedWithTheReasonAndOthersAreLeftAlone(): void {
        $this->seedBilingualSite();
        $this->settings()->set( 'automation.autopilot', true );
        $this->seedEarlierSuggestions();
        $privacy = '<p>Northwind Supply Co never sells your data.</p>';
        StubsSeo::addPost( 159, [ 'post_type' => 'page', 'post_title' => 'Privacy Policy', 'post_content' => '<p><a href="' . self::HOME . '/fr/a-propos/">Northwind Supply Co</a> never sells your data.</p>' ] );
        $this->seedItem( '4', 159, 12840, 'northwind supply co', 'applied', [ 'auto' => false, 'applied_by' => 1, 'applied_at' => '2026-11-11T00:00:00+00:00', 'note' => 'Linked by a person.' ], $privacy );
        $this->seedItem( '5', 158, 134, 'supply co', 'dismissed', [ 'dismissed_at' => '2026-11-12T00:00:00+00:00', 'dismissed_by' => 1 ] );
        $this->seedItem( '6', 160, 12840, 'fixations de laboratoire', 'dismissed', [ 'auto' => true, 'undone_at' => '2026-11-12T00:00:00+00:00', 'note' => 'Removed by “Undo automatic links”; it will not be added again.' ] );
        StubsSeo::addPost( 161, [ 'post_type' => 'page', 'post_title' => 'Shipping', 'post_content' => '<p>Northwind Supply Co ships across the country every weekday.</p>' ] );
        $this->seedItem( '7', 161, 12840, 'northwind supply co', 'not_shown', [ 'note' => 'Not added: this page’s template doesn’t display its content.', 'render_code' => 'template', 'render_scope' => 'page' ] );
        $untouched = [ '4' => $this->item( '4' ), '5' => $this->item( '5' ), '6' => $this->item( '6' ) ];
        $contents  = [ 159 => StubsSeo::$posts[159]['post_content'], 160 => StubsSeo::$posts[160]['post_content'], 161 => StubsSeo::$posts[161]['post_content'] ];

        $linker = $this->linker();
        $run    = $linker->run( TimeBudget::seconds( 30 ), [ 'restart' => true ] );
        $this->assertStringContainsString( '4 earlier suggestions were closed', (string) $run['summary'] );
        $auto = $linker->autoApply( TimeBudget::seconds( 30 ) );

        // The French About page by the brand name, and an English page to it by French words: another language.
        foreach ( [ '1', '3', '7' ] as $id ) {
            $this->assertSame( 'stale', $this->item( $id )['status'] ?? null, 'suggestion ' . $id );
            $this->assertStringStartsWith( self::LANGUAGE_NOTE, (string) ( $this->item( $id )['note'] ?? '' ), 'suggestion ' . $id );
        }
        $this->assertSame( [ 'en', 'fr' ], [ $this->item( '1' )['source_language'] ?? null, $this->item( '1' )['target_language'] ?? null ] );
        // "Supply Co" (the tail of the brand name) to the home page: the brand name.
        $this->assertSame( 'stale', $this->item( '2' )['status'] ?? null );
        $this->assertStringStartsWith( self::BRAND_NOTE, (string) ( $this->item( '2' )['note'] ?? '' ) );

        // Applied (a person may have chosen it), dismissed and undone suggestions are left exactly as they were.
        foreach ( $untouched as $id => $before ) {
            $this->assertSame( $before, $this->item( (string) $id ), 'suggestion ' . $id );
        }

        // No brand link and no link across languages was added; the two valid suggestions were applied.
        $this->assertSame( 2, $auto['applied'], (string) $auto['message'] );
        $this->assertSame( str_replace( 'industrial fastener supplier', '<a href="' . self::HOME . '/about-us/">industrial fastener supplier</a>', self::TERMS ), StubsSeo::$posts[158]['post_content'] );
        $this->assertSame( str_replace( 'fournisseur de fixations industrielles', '<a href="' . self::HOME . '/fr/a-propos/">fournisseur de fixations industrielles</a>', self::CONDITIONS ), StubsSeo::$posts[12841]['post_content'] );
        foreach ( $contents as $id => $content ) {
            $this->assertSame( $content, StubsSeo::$posts[ $id ]['post_content'], 'item ' . $id );
        }
        $this->assertEqualsCanonicalizing( [ 158, 12841 ], array_map( static fn( array $u ): int => (int) $u['ID'], StubsSeo::$updates ) );

        // They stay closed: the next runs neither suggest nor apply them again.
        $linker->run( TimeBudget::seconds( 30 ), [ 'restart' => true ] );
        $again = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 0, $again['applied'] );
        foreach ( [ '1', '2', '3', '7' ] as $id ) {
            $this->assertSame( 'stale', $this->item( $id )['status'] ?? null );
        }
        $stale = array_column( $linker->suggestions( 'stale' ), 'note', 'id' );
        $this->assertStringStartsWith( self::BRAND_NOTE, (string) ( $stale[2] ?? '' ), 'the On-site SEO list shows the reason under "No longer valid"' );
    }

    public function testTheAutomaticRunNeverAppliesThemAlsoBeforeTheSuggestionRunReachesThem(): void {
        $this->seedBilingualSite();
        $this->settings()->set( 'automation.autopilot', true );
        $this->seedEarlierSuggestions();
        $linker = $this->linker();
        $this->assertTrue( $linker->autoApplyEnabled() );

        $auto = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 0, $auto['applied'], (string) $auto['message'] );
        $this->assertSame( [], StubsSeo::$updates, 'the earlier rules added both links here: "Northwind Supply Co" first, then "Supply Co" in the licensors sentence' );
        $this->assertSame( self::TERMS, StubsSeo::$posts[158]['post_content'] );
        $this->assertSame( 3, $auto['closed'] );
        $this->assertStringContainsString( '3 no longer valid (closed)', (string) $auto['message'] );
        $this->assertStringStartsWith( self::LANGUAGE_NOTE, (string) ( $this->item( '1' )['note'] ?? '' ) );
        $this->assertStringStartsWith( self::BRAND_NOTE, (string) ( $this->item( '2' )['note'] ?? '' ) );
        $this->assertStringStartsWith( self::LANGUAGE_NOTE, (string) ( $this->item( '3' )['note'] ?? '' ) );
    }

    public function testAPersonsApplyClosesThemWithTheReasonInsteadOfLinking(): void {
        $this->seedBilingualSite();
        $this->seedEarlierSuggestions();
        $GLOBALS['lga_test_caps']    = [ 'manage_options' => true, 'edit_post' => true, 'unfiltered_html' => true ];
        $GLOBALS['lga_test_user_id'] = 1;
        $res = $this->linker()->apply( [ 1, 2 ], 1 );
        $this->assertSame( 0, $res['applied'] );
        $this->assertSame( [], StubsSeo::$updates );
        $messages = array_column( $res['results'], 'message', 'id' );
        $this->assertStringStartsWith( self::LANGUAGE_NOTE, (string) ( $messages[1] ?? '' ) );
        $this->assertStringStartsWith( self::BRAND_NOTE, (string) ( $messages[2] ?? '' ) );
        $this->assertSame( [ 'stale', 'stale' ], [ $this->item( '1' )['status'] ?? null, $this->item( '2' )['status'] ?? null ] );
    }

    public function testWordsInsideTheBrandNameAreNeverLinkedButTheNextOccurrenceIs(): void {
        StubsSeo::addPost( 110, [ 'post_type' => 'product', 'post_title' => 'Supply Co Express' ] );
        $both = '<p>Orders from Northwind Supply Co Express arrive fast.</p><p>Choose Supply Co Express at checkout.</p>';
        $only = '<p>Every order from Northwind Supply Co Express arrives within two days.</p>';
        StubsSeo::addPost( 210, [ 'post_title' => 'Delivery', 'post_content' => $both ] );
        StubsSeo::addPost( 211, [ 'post_title' => 'Delivery times', 'post_content' => $only ] );
        // Made under the earlier rules at the words inside the brand name.
        $this->seedItem( '1', 210, 110, 'Supply Co Express', 'pending' );
        $this->seedItem( '2', 211, 110, 'Supply Co Express', 'pending' );

        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ), [ 'restart' => true ] );
        $s210 = $this->bySource( $linker, 210 );
        $this->assertCount( 1, $s210 );
        $this->assertSame( 1, (int) $s210[0]['id'], 'the same suggestion, moved to the next occurrence' );
        $this->assertSame( 'pending', $s210[0]['status'] );
        $this->assertStringContainsString( 'Choose Supply Co Express at checkout', (string) $s210[0]['excerpt'] );
        $this->assertSame( 'stale', $this->item( '2' )['status'] ?? null, 'no other occurrence: closed with the reason, not dropped' );
        $this->assertStringStartsWith( self::BRAND_NOTE, (string) ( $this->item( '2' )['note'] ?? '' ) );

        $GLOBALS['lga_test_caps']    = [ 'manage_options' => true, 'edit_post' => true, 'unfiltered_html' => true ];
        $GLOBALS['lga_test_user_id'] = 1;
        $res = $linker->apply( [ 1 ], 1 );
        $this->assertSame( 1, $res['applied'], wp_json_encode( $res['results'] ) );
        $this->assertSame( '<p>Orders from Northwind Supply Co Express arrive fast.</p><p>Choose <a href="' . self::HOME . '/product/supply-co-express/">Supply Co Express</a> at checkout.</p>', StubsSeo::$posts[210]['post_content'] );
    }

    public function testTheAutomaticRunClosesASuggestionInsideTheBrandNameBeforeItsSourceIsRefreshed(): void {
        $this->settings()->set( 'automation.autopilot', true );
        StubsSeo::addPost( 110, [ 'post_type' => 'product', 'post_title' => 'Supply Co Express' ] );
        $only = '<p>Every order from Northwind Supply Co Express arrives within two days, tracked from our Portland warehouse to your door.</p>';
        StubsSeo::addPost( 211, [ 'post_title' => 'Delivery times', 'post_content' => $only ] );
        $this->seedItem( '2', 211, 110, 'Supply Co Express', 'pending', [ 'relevance' => 95 ] );
        $auto = $this->linker()->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 0, $auto['applied'], (string) $auto['message'] );
        $this->assertSame( $only, StubsSeo::$posts[211]['post_content'] );
        $this->assertSame( 'stale', $this->item( '2' )['status'] ?? null );
        $this->assertStringStartsWith( self::BRAND_NOTE, (string) ( $this->item( '2' )['note'] ?? '' ) );
    }

    /**
     * French landing pages at the site root (/acheter-fournitures/, page 11783, and /fixations-laboratoire-quebec/)
     * have no /fr/ address and no counterpart meta. Before,
     * they were the site language (English): an English page was offered the French Québec page by its French focus
     * keyword, the French buy page was offered the English product named in its text, and a French page under /fr/
     * was never offered the French Québec page.
     */
    public function testFrenchPagesAtTheSiteRootLinkOnlyToFrenchPages(): void {
        $achat = '<p>Fournisseur de fournitures industrielles : prix en dollars, expédition suivie depuis notre entrepôt et fiches techniques pour chaque lot.</p>'
            . '<p>Notre PTFE Thread Tape est expédié partout au pays. Nous préparons les commandes dans notre entrepôt et vous recevez un numéro de suivi dès le départ du colis.</p>'
            . '<p>Voir aussi fixations de laboratoire au Québec pour la livraison à Montréal et à Québec.</p>';
        $quebec = '<p>Les fixations de laboratoire sont livrées à Montréal, Laval, Gatineau et Québec. Nous expédions chaque commande avec un numéro de suivi et les fiches techniques du lot.</p>';
        $livraison = '<p>Nous livrons partout dans la région. Pour le Québec, voyez la page fixations de laboratoire au Québec : les délais y sont indiqués.</p>';
        $shipping = '<p>Every order ships from our Portland warehouse with a tracking number, and the PTFE Thread Tape is packed in a carton.</p>'
            . '<p>French readers: fixations de laboratoire au Québec.</p>';
        StubsSeo::addPost( 500, [ 'post_type' => 'product', 'post_title' => 'PTFE Thread Tape' ] );
        StubsSeo::addPost( 11783, [ 'post_type' => 'page', 'post_title' => 'Acheter des fournitures industrielles au Québec', 'url' => self::HOME . '/acheter-fournitures/', 'post_content' => $achat ] );
        StubsSeo::addPost( 11800, [ 'post_type' => 'page', 'post_title' => 'Fixations de laboratoire au Québec', 'url' => self::HOME . '/fixations-laboratoire-quebec/', 'post_content' => $quebec ], [ 'rank_math_focus_keyword' => 'fixations de laboratoire au québec' ] );
        StubsSeo::addPost( 12900, [ 'post_type' => 'page', 'post_title' => 'Livraison', 'url' => self::HOME . '/fr/livraison/', 'post_content' => $livraison ], [ 'translation_of' => 700 ] );
        StubsSeo::addPost( 700, [ 'post_type' => 'page', 'post_title' => 'Shipping across the region', 'url' => self::HOME . '/shipping/', 'post_content' => $shipping ] );
        // As the earlier rules made it: the French buy page to the English product.
        $this->seedItem( '1', 11783, 500, 'PTFE Thread Tape', 'pending' );

        $linker = $this->linker();
        $linker->run( TimeBudget::seconds( 30 ), [ 'restart' => true ] );
        $targets = static fn( array $rows ): array => array_map( static fn( array $s ): int => (int) $s['target_id'], $rows );
        $this->assertSame( [ 500 ], $targets( $this->bySource( $linker, 700, 'pending' ) ), 'the English page: the English product, not the French Québec page' );
        $this->assertSame( [ 11800 ], $targets( $this->bySource( $linker, 11783, 'pending' ) ), 'the French buy page: the French Québec page, not the English product' );
        $this->assertSame( [ 11800 ], $targets( $this->bySource( $linker, 12900, 'pending' ) ), 'a French page under /fr/ is offered the French Québec page' );
        $this->assertSame( 'stale', $this->item( '1' )['status'] ?? null );
        $this->assertStringStartsWith( self::LANGUAGE_NOTE, (string) ( $this->item( '1' )['note'] ?? '' ) );
        $this->assertSame( [ 'fr', 'en' ], [ $this->item( '1' )['source_language'] ?? null, $this->item( '1' )['target_language'] ?? null ] );
    }

    public function testTargetsInAnotherLanguageAreNeverSuggestedAndSharedAnchorsAreKeptPerLanguage(): void {
        StubsSeo::addPost( 100, [ 'post_type' => 'product', 'post_title' => 'Corrugated Boxes' ] );
        StubsSeo::addPost( 101, [ 'post_type' => 'product', 'post_title' => 'Corrugated Boxes', 'url' => self::HOME . '/fr/produit/boites-en-carton/' ], [ 'translation_of' => 100 ] );
        StubsSeo::addPost( 140, [ 'post_type' => 'page', 'post_title' => 'Stockage', 'url' => self::HOME . '/fr/stockage/' ], [ 'rank_math_focus_keyword' => 'stockage des fixations' ] );
        StubsSeo::addPost( 230, [ 'post_title' => 'Reconstitution', 'post_content' => '<p>Use corrugated boxes. A French guide covers stockage des fixations.</p>' ] );
        StubsSeo::addPost( 231, [ 'post_title' => 'Notes', 'url' => self::HOME . '/fr/notes/', 'post_content' => '<p>Utilisez la corrugated boxes. Le stockage des fixations compte.</p>' ] );
        StubsSeo::addPost( 232, [ 'post_title' => 'Notes deux', 'url' => self::HOME . '/notes-deux/', 'post_content' => '<p>Gardez la corrugated boxes au froid.</p>' ] );
        StubsSeo::addPost( 233, [ 'post_title' => 'Notizie', 'url' => self::HOME . '/notizie/', 'post_content' => '<p>Usa la corrugated boxes.</p>' ] );
        StubsSeo::$pll[232] = 'fr_FR';
        add_filter( 'lga_post_language', static fn( $code, $id ) => 233 === (int) $id ? 'it' : $code );

        $linker = $this->linker();
        $res    = $linker->run( TimeBudget::seconds( 30 ) );
        $this->assertSame( 3, $res['targets'], 'the English and the French product share their title but not their language' );
        $pairs = static fn( array $rows ): array => array_map( static fn( array $s ): int => (int) $s['target_id'], $rows );
        $this->assertSame( [ 100 ], $pairs( $this->bySource( $linker, 230 ) ), 'English: the English product only' );
        $this->assertEqualsCanonicalizing( [ 101, 140 ], $pairs( $this->bySource( $linker, 231 ) ), 'French (address prefix): the French product and page' );
        $this->assertSame( [ 101 ], $pairs( $this->bySource( $linker, 232 ) ), 'French (Polylang)' );
        $this->assertSame( [], $this->bySource( $linker, 233 ), 'Italian (lga_post_language): no Italian target' );
    }
}
