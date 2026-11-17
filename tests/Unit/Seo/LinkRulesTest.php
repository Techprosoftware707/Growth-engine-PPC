<?php
/**
 * 1.2.3 — the two rules behind internal links, unit by unit:
 * - Seo\BrandTerms: brand terms from site.brand_name, site.legal_name, discovery.brand_terms, the blogname and the
 *   domain with and without its TLD, compared with case, spaces, punctuation and accents ignored; brand mentions in
 *   a text and whether a match lies inside one or cuts through one; InternalLinker::locate() skips such matches;
 *   InternalLinker::targetKeyword() falls back to the next usable focus keyword when the first is the brand.
 * - Seo\PostLanguage: Polylang, then WPML, then the ppclg_post_language filter, then ppc_en_counterpart with the
 *   address prefix (or the text), then an ISO 639-1 address prefix, then the language of the item's text (French
 *   pages at the site root), then the site language; worked out once per item.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Seo;

require_once dirname( __DIR__, 2 ) . '/Support/StubsSeo.php';

use PPC\LinkGrowth\Seo\BrandTerms;
use PPC\LinkGrowth\Seo\InternalLinker;
use PPC\LinkGrowth\Seo\PostLanguage;
use PPC\LinkGrowth\Seo\RenderCheck;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\StubsSeo;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class LinkRulesTest extends TestCase {

    private string $home = '';

    protected function setUp(): void {
        parent::setUp();
        StubsSeo::reset();
        $this->home = (string) $GLOBALS['ppclg_test_home'];
    }

    protected function tearDown(): void {
        $GLOBALS['ppclg_test_home'] = $this->home;
        parent::tearDown();
    }

    private function settings(): Settings {
        return Container::get( Settings::class );
    }

    public function testNormalisationFoldsCaseSpacesPunctuationAndAccents(): void {
        $this->assertSame( 'pure peptides canada', BrandTerms::normalize( 'PURE PEPTIDES CANADA' ) );
        $this->assertSame( 'pure peptides canada', BrandTerms::normalize( "  Pùre\u{00A0}Peptidés \u{2014} Canadá! " ) );
        $this->assertSame( 'pure peptides canada', BrandTerms::normalize( 'Pure&nbsp;Peptides-Canada' ) );
        $this->assertSame( 'purepeptidescanada com', BrandTerms::normalize( 'PurePeptidesCanada.com' ) );
        $this->assertSame( 'laboratoires reunis strasse oeuvre', BrandTerms::normalize( 'LABORATOIRES RÉUNIS — Straße Œuvre' ) );
        $this->assertSame( [ 'purepeptidescanada.com', 'purepeptidescanada' ], BrandTerms::domainForms( 'www.PurePeptidesCanada.com' ) );
        $this->assertSame( [ 'purepeptidescanada.com', 'purepeptidescanada' ], BrandTerms::domainForms( 'https://purepeptidescanada.com/shop/' ) );
        $this->assertSame( [ 'example.co.uk', 'example' ], BrandTerms::domainForms( 'example.co.uk' ) );
        $this->assertSame( [ 'localhost' ], BrandTerms::domainForms( 'localhost' ) );
        $this->assertSame( [], BrandTerms::domainForms( '' ) );
    }

    public function testTheLiveBrandTermsCoverEveryWrittenFormAndOnlyThose(): void {
        $this->settings()->update( [ 'site' => [ 'brand_name' => 'Pure Peptides Canada', 'legal_name' => 'PURE PEPTIDES CANADA', 'domain' => 'purepeptidescanada.com' ], 'discovery' => [ 'brand_terms' => [ 'Pure Peptides Canada', 'purepeptidescanada.com', 'purepeptidescanada' ] ] ] );
        $brand = BrandTerms::fromSettings( $this->settings() );
        $this->assertSame( [ 'pure peptides canada', 'pure peptides canada com' ], $brand->terms(), 'the domain without spaces is read as the brand name’s words' );
        foreach ( [ 'Pure Peptides Canada', 'PURE PEPTIDES CANADA', 'pure-peptides canadá', 'Peptides Canada', 'peptides  canada', 'Pure Peptides', 'PurePeptides', 'purepeptidescanada', 'PurePeptidesCanada.com', 'Pure', 'Canada' ] as $anchor ) {
            $this->assertTrue( $brand->isBrand( $anchor ), $anchor );
        }
        foreach ( [ 'Bacteriostatic Water', 'scan', 'tides', 'Canada Post', 'Peptides Canada Express', 'Pure Peptides Canada reviews', 'fournisseur canadien de peptides', '', '   ' ] as $anchor ) {
            $this->assertFalse( $brand->isBrand( $anchor ), $anchor );
        }
        $this->assertTrue( $brand->sharesWord( 'Peptides Canada Express' ) );
        $this->assertFalse( $brand->sharesWord( 'Bacteriostatic Water' ) );
    }

    public function testLegalNameInCapitalsBlognameAndTheDomainWithoutItsTldAreBrandTerms(): void {
        $this->settings()->update( [ 'site' => [ 'brand_name' => 'Acme Labs', 'legal_name' => 'LABORATOIRES ACME RÉACTIFS INC.', 'domain' => 'www.acme-labs.ca' ], 'discovery' => [ 'brand_terms' => [ 'Acme' ] ] ] );
        update_option( 'blogname', 'Acmé Lab Store' );
        $brand = BrandTerms::fromSettings( $this->settings() );
        foreach ( [ 'Laboratoires Acme Reactifs', 'acme réactifs', 'Réactifs Inc', 'ACME-LABS', 'acme-labs.ca', 'acmelabs', 'Acme Lab Store', 'lab store', 'ACME' ] as $anchor ) {
            $this->assertTrue( $brand->isBrand( $anchor ), $anchor );
        }
        foreach ( [ 'Pure Peptides Canada', 'acme reactifs inc catalogue', 'lab supplies' ] as $anchor ) {
            $this->assertFalse( $brand->isBrand( $anchor ), $anchor );
        }

        // With no site.domain, the home address's host is the domain.
        $this->settings()->update( [ 'site' => [ 'domain' => '' ] ] );
        $GLOBALS['ppclg_test_home'] = 'https://www.labo-quebec.ca';
        $this->assertTrue( BrandTerms::fromSettings( $this->settings() )->isBrand( 'Labo Québec' ) );
    }

    public function testBrandMentionsAndWhetherAMatchCutsThem(): void {
        $brand = new BrandTerms( [ 'Pure Peptides Canada', 'PURE PEPTIDES CANADA', 'purepeptidescanada.com', 'purepeptidescanada' ] );
        $text  = 'All content belongs to Pure Peptides Canada or its licensors. Visit PurePeptidesCanada.com or PURE PEPTIDES CANADÁ today. Peptides Canada wide.';
        $m     = BrandTerms::mentions( $text, $brand->patterns() );
        $first = strpos( $text, 'Pure Peptides Canada' );
        $this->assertContains( [ $first, $first + 20 ], $m );
        $site = strpos( $text, 'PurePeptidesCanada.com' );
        $this->assertContains( [ $site, $site + 22 ], $m, 'the domain written without spaces' );
        $caps = strpos( $text, 'PURE PEPTIDES CANAD' );
        $this->assertContains( [ $caps, $caps + strlen( 'PURE PEPTIDES CANADÁ' ) ], $m, 'capitals and an accent' );
        $tail = strrpos( $text, 'Peptides Canada' );
        foreach ( $m as [ $s, $e ] ) {
            $this->assertFalse( $s <= $tail && $tail < $e, 'the last words are not a brand mention (no "Pure")' );
        }

        $this->assertTrue( BrandTerms::cuts( $m, $first + 5, $first + 20 ), '"Peptides Canada" inside "Pure Peptides Canada"' );
        $this->assertTrue( BrandTerms::cuts( $m, $first + 14, $first + 27 ), '"Canada or its" cuts through the name' );
        $this->assertFalse( BrandTerms::cuts( $m, $first, $first + 23 ), '"Pure Peptides Canada or" holds the whole name' );
        $this->assertFalse( BrandTerms::cuts( $m, $tail, $tail + 15 ), 'words outside every mention' );
    }

    public function testLocateSkipsAnOccurrenceInsideTheBrandName(): void {
        $patterns = ( new BrandTerms( [ 'Pure Peptides Canada' ] ) )->patterns();
        $html     = '<p>Orders from Pure Peptides Canada Express arrive fast.</p><p>Choose <strong>Peptides Canada Express</strong> at checkout.</p>';
        $old      = InternalLinker::locate( InternalLinker::analyse( $html ), 'Peptides Canada Express' );
        $this->assertSame( strpos( $html, 'Peptides Canada Express' ), $old['start'] ?? null, 'without the brand rule (1.2.2): the words inside the name' );
        $new = InternalLinker::locate( InternalLinker::analyse( $html, $patterns ), 'Peptides Canada Express' );
        $this->assertSame( strrpos( $html, 'Peptides Canada Express' ), $new['start'] ?? null, 'the next occurrence, outside the name' );
        $this->assertNull( InternalLinker::locate( InternalLinker::analyse( '<p>Only from Pure Peptides Canada Express.</p>', $patterns ), 'Peptides Canada Express' ) );
        $inline = '<p>belong to <em>Pure</em> Peptides Canada or its licensors</p>';
        $this->assertNotNull( InternalLinker::locate( InternalLinker::analyse( $inline ), 'Peptides Canada' ) );
        $this->assertNull( InternalLinker::locate( InternalLinker::analyse( $inline, $patterns ), 'Peptides Canada' ), 'also when part of the name is in an inline tag' );
        // The page check reads the words around the same spot the suggestion uses.
        $ctx = RenderCheck::context( $html, 'Peptides Canada Express', $patterns );
        $this->assertStringContainsString( ' choose peptides canada express at checkout ', (string) ( $ctx['full'] ?? '' ) );
        $this->assertSame( 1, RenderCheck::pageOf( '<p>Pure Peptides Canada Express.</p><!--nextpage--><p>Choose Peptides Canada Express.</p>', 'Peptides Canada Express' ) );
        $this->assertSame( 2, RenderCheck::pageOf( '<p>Pure Peptides Canada Express.</p><!--nextpage--><p>Choose Peptides Canada Express.</p>', 'Peptides Canada Express', $patterns ) );
        $whole = '<p>Read the Pure Peptides Canada reviews first.</p>';
        $this->assertSame( strpos( $whole, 'Pure' ), InternalLinker::locate( InternalLinker::analyse( $whole, $patterns ), 'pure peptides canada reviews' )['start'] ?? null, 'a match that holds the whole name does not cut it' );
    }

    public function testFocusKeywordFallsBackOnlyWhenTheFirstKeywordIsTheBrand(): void {
        $brand = new BrandTerms( [ 'Pure Peptides Canada', 'purepeptidescanada.com', 'purepeptidescanada' ] );
        $cases = [
            'French About page: next usable keyword'      => [ [ 'pure peptides canada', 'fournisseur canadien de peptides', 'peptides de recherche' ], 'fournisseur canadien de peptides' ],
            'generic and brand-only keywords are skipped' => [ [ 'Pure Peptides Canada', 'home', 'canada', 'Peptides Canada', 'peptide storage' ], 'peptide storage' ],
            'domain forms are the brand'                  => [ [ 'purepeptidescanada.com', 'purepeptidescanada', 'lab reports online' ], 'lab reports online' ],
            'the home page: brand only'                   => [ [ 'peptides canada' ], '' ],
            'brand, then nothing usable'                  => [ [ 'pure peptides canada', 'faq', 'pure peptides' ], '' ],
            'first keyword generic: no fallback (1.2.2)'  => [ [ 'home', 'peptide storage' ], '' ],
            'first keyword empty: no fallback (1.2.2)'    => [ [ '', 'peptide storage' ], '' ],
            'first keyword usable'                        => [ [ 'peptide storage', 'cold chain' ], 'peptide storage' ],
            'no keyword'                                  => [ [], '' ],
        ];
        foreach ( $cases as $label => [ $keywords, $expected ] ) {
            $this->assertSame( $expected, InternalLinker::targetKeyword( $keywords, $brand ), $label );
        }
    }

    public function testLanguageSourcesAreReadInOrder(): void {
        $home = 'https://purepeptidescanada.test';
        StubsSeo::addPost( 300, [ 'post_type' => 'page', 'post_title' => 'À propos', 'url' => $home . '/fr/a-propos/' ], [ PostLanguage::COUNTERPART_META => 138 ] );
        StubsSeo::$pll[300] = 'de_DE';
        $wpml     = [ 'language_code' => 'es', 'locale' => 'es_ES' ];
        $filtered = 'it';
        $asked    = [];
        add_filter( 'wpml_post_language_details', static function ( $value, $id ) use ( &$wpml ) {
            return 300 === (int) $id ? $wpml : $value;
        } );
        add_filter( PostLanguage::FILTER, static function ( $value, $id ) use ( &$filtered, &$asked ) {
            $asked[] = (int) $id;
            return 300 === (int) $id ? $filtered : $value;
        } );

        $this->assertSame( 'de', ( new PostLanguage() )->of( 300 ), '1. Polylang' );
        unset( StubsSeo::$pll[300] );
        $this->assertSame( 'es', ( new PostLanguage() )->of( 300 ), '2. WPML' );
        $wpml = null;
        $this->assertSame( 'it', ( new PostLanguage() )->of( 300 ), '3. the ppclg_post_language filter' );
        $filtered = '';
        $this->assertSame( 'fr', ( new PostLanguage() )->of( 300 ), '4. ppc_en_counterpart: the language of its address prefix' );

        // A counterpart whose address has no language prefix: not English, language not known (matches nothing).
        StubsSeo::addPost( 301, [ 'post_type' => 'page', 'post_title' => 'Contact FR', 'url' => $home . '/nous-joindre/' ], [ PostLanguage::COUNTERPART_META => 139 ] );
        $lang = new PostLanguage();
        $this->assertSame( PostLanguage::UNKNOWN, $lang->of( 301 ) );
        $this->assertFalse( PostLanguage::same( $lang->of( 301 ), $lang->of( 301 ) ) );
        $this->assertFalse( PostLanguage::same( $lang->of( 301 ), 'en' ) );

        // 5. An ISO 639-1 address prefix (not any two letters), 6. else the site language.
        StubsSeo::addPost( 302, [ 'post_title' => 'Contacto', 'url' => $home . '/es/contacto/' ] );
        StubsSeo::addPost( 303, [ 'post_title' => 'Deal', 'url' => $home . '/go/deal/' ] );
        StubsSeo::addPost( 304, [ 'post_title' => 'Account', 'url' => $home . '/my-account/' ] );
        StubsSeo::addPost( 305, [ 'post_title' => 'Accueil FR', 'url' => $home . '/FR/' ] );
        StubsSeo::addPost( 306, [ 'post_title' => 'Plain', 'url' => $home . '/?page_id=306' ] );
        $lang = new PostLanguage();
        $this->assertSame( [ 'es', 'en', 'en', 'fr', 'en' ], [ $lang->of( 302 ), $lang->of( 303 ), $lang->of( 304 ), $lang->of( 305 ), $lang->of( 306 ) ] );
        StubsSeo::$locale = 'fr_CA';
        $this->assertSame( 'fr', ( new PostLanguage() )->of( 306 ), 'the site language is the first two letters of get_locale()' );
        StubsSeo::$locale = '';
        $this->assertSame( 'en', ( new PostLanguage() )->site() );

        // The prefix is read below the home address of a site installed in a folder.
        $GLOBALS['ppclg_test_home'] = 'https://example.test/shop';
        $this->assertSame( 'fr', PostLanguage::pathPrefix( 'https://example.test/shop/fr/a-propos/' ) );
        $this->assertSame( '', PostLanguage::pathPrefix( 'https://example.test/shop/a-propos/' ) );
        $this->assertSame( '', PostLanguage::pathPrefix( 'https://example.test/shop/' ) );

        // Codes are primary subtags.
        $this->assertSame( [ 'fr', 'pt', 'en', '', '', 'und' ], [ PostLanguage::code( 'fr_CA' ), PostLanguage::code( 'pt-BR' ), PostLanguage::code( 'EN' ), PostLanguage::code( 'français' ), PostLanguage::code( 12 ), PostLanguage::code( 'und' ) ] );
        $this->assertTrue( PostLanguage::same( 'fr', 'fr' ) );
        $this->assertFalse( PostLanguage::same( 'fr', 'en' ) );
        $this->assertFalse( PostLanguage::same( '', '' ) );
    }

    /**
     * The live site (en-CA) has French landing pages at the site root (/acheter-peptides-canada/, page 11783, and
     * /peptides-recherche-quebec/) with no language prefix and, as far as anyone knows, no ppc_en_counterpart: their
     * text decides, not the site language.
     */
    public function testAnItemWithNoOtherSignalIsInTheLanguageItsTextIsWrittenIn(): void {
        $home   = 'https://purepeptidescanada.test';
        $achat  = '<!-- wp:paragraph --><p>Fournisseur canadien de peptides de recherche : prix en dollars canadiens, expédition suivie depuis le Canada et certificats d&rsquo;analyse pour chaque lot.</p><!-- /wp:paragraph -->'
            . '<p>Notre BPC-157 Peptide est expédié partout au pays. Nous préparons les commandes dans notre laboratoire et vous recevez un numéro de suivi dès le départ du colis.</p>';
        $english = '<p>Every order ships from our Toronto lab with a tracking number. We pack each vial in a cold pack, and the parcel is insured for its full value.</p>'
            . '<p>French readers can see peptides de recherche au Québec. Stability data follow Smith et al. and Tremblay et al. for each of the compounds.</p>';
        StubsSeo::addPost( 11783, [ 'post_type' => 'page', 'post_title' => 'Acheter des peptides de recherche au Canada', 'url' => $home . '/acheter-peptides-canada/', 'post_content' => $achat ] );
        StubsSeo::addPost( 700, [ 'post_type' => 'page', 'post_title' => 'Shipping across Canada', 'url' => $home . '/shipping-across-canada/', 'post_content' => $english ] );
        StubsSeo::addPost( 701, [ 'post_type' => 'page', 'post_title' => 'Écrivez-nous', 'url' => $home . '/ecrivez-nous/', 'post_content' => '<p>Une question sur une commande ?</p>' ] );
        StubsSeo::addPost( 702, [ 'post_type' => 'page', 'post_title' => 'Cart', 'url' => $home . '/cart/', 'post_content' => '<!-- wp:shortcode -->[woocommerce_cart]<!-- /wp:shortcode -->' ] );
        StubsSeo::addPost( 500, [ 'post_type' => 'product', 'post_title' => 'BPC-157 Peptide', 'post_excerpt' => '<p>Lyophilized powder for laboratory research. Each vial is tested by a third-party lab and ships with its certificate.</p>' ] );
        // A counterpart without a language prefix: the language of its text when that is not English, else not known.
        StubsSeo::addPost( 703, [ 'post_type' => 'page', 'post_title' => 'Livraison', 'url' => $home . '/livraison/', 'post_content' => $achat ], [ PostLanguage::COUNTERPART_META => 700 ] );
        StubsSeo::addPost( 704, [ 'post_type' => 'page', 'post_title' => 'Shipping copy', 'url' => $home . '/shipping-copy/', 'post_content' => $english ], [ PostLanguage::COUNTERPART_META => 700 ] );

        $lang = new PostLanguage();
        $this->assertSame( 'fr', $lang->of( 11783 ), 'a French page at the site root' );
        $this->assertSame( 'en', $lang->of( 700 ), 'an English page quoting French words (and "et al.") stays English' );
        $this->assertSame( 'en', $lang->of( 701 ), 'too little text to tell: the site language' );
        $this->assertSame( 'en', $lang->of( 702 ), 'no text: the site language' );
        $this->assertSame( 'en', $lang->of( 500 ), 'a product by its title and short description' );
        $this->assertSame( 'fr', $lang->of( 703 ) );
        $this->assertSame( PostLanguage::UNKNOWN, $lang->of( 704 ) );

        // The address prefix and the explicit sources still come first.
        StubsSeo::addPost( 705, [ 'post_type' => 'page', 'post_title' => 'Achat', 'url' => $home . '/es/achat/', 'post_content' => $achat ] );
        StubsSeo::$pll[706] = 'en_CA';
        StubsSeo::addPost( 706, [ 'post_type' => 'page', 'post_title' => 'Achat', 'url' => $home . '/achat/', 'post_content' => $achat ] );
        $this->assertSame( [ 'es', 'en' ], [ ( new PostLanguage() )->of( 705 ), ( new PostLanguage() )->of( 706 ) ] );

        // A French site: an English page at its root is English.
        StubsSeo::$locale = 'fr_CA';
        $this->assertSame( [ 'en', 'fr' ], [ ( new PostLanguage() )->of( 700 ), ( new PostLanguage() )->of( 11783 ) ] );
        // A site in a language the text is not read for: the site language, as before.
        StubsSeo::$locale = 'de_DE';
        $this->assertSame( 'de', ( new PostLanguage() )->of( 11783 ) );

        // The text reader alone.
        $this->assertSame( 'fr', PostLanguage::textLanguage( $achat ) );
        $this->assertSame( 'en', PostLanguage::textLanguage( $english ) );
        $this->assertSame( '', PostLanguage::textLanguage( 'Le BPC-157 et le TB-500.' ), 'too few function words' );
        $this->assertSame( '', PostLanguage::textLanguage( '<p>Il ne le sono il ne le dice il ne le fa il ne le vede.</p>' ), 'too few different ones (Italian)' );
        $this->assertSame( '', PostLanguage::textLanguage( str_repeat( 'the and of to le et des du ', 3 ) ), 'neither language clearly' );
    }

    public function testEachItemsLanguageIsWorkedOutOncePerRun(): void {
        StubsSeo::addPost( 310, [ 'post_title' => 'Contacto', 'url' => 'https://purepeptidescanada.test/es/contacto/' ] );
        $asked = 0;
        add_filter( PostLanguage::FILTER, static function ( $value ) use ( &$asked ) {
            $asked++;
            return $value;
        } );
        $lang = new PostLanguage();
        $this->assertSame( 'es', $lang->of( 310 ) );
        $this->assertSame( 'es', $lang->of( 310 ) );
        $this->assertSame( 1, $asked, 'cached per post id' );
        $this->assertSame( 2, StubsSeo::$pllCalls, 'Polylang asked once for the locale and once for the slug' );
        $this->assertSame( [ 310 => 'es' ], $lang->known() );

        // A run's later batches start from the languages already worked out (kept in the run state).
        $seeded = new PostLanguage( [ 310 => 'es', '311' => 'fr', 312 => '' ] );
        $this->assertSame( 'fr', $seeded->of( 311 ) );
        $this->assertSame( 'es', $seeded->of( 310 ) );
        $this->assertSame( 1, $asked );
    }
}
