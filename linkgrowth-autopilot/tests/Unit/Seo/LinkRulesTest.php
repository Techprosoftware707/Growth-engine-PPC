<?php
/**
 * 1.2.3 — the two rules behind internal links, unit by unit:
 * - Seo\BrandTerms: brand terms from site.brand_name, site.legal_name, discovery.brand_terms, the blogname and the
 *   domain with and without its TLD, compared with case, spaces, punctuation and accents ignored; brand mentions in
 *   a text and whether a match lies inside one or cuts through one; InternalLinker::locate() skips such matches;
 *   InternalLinker::targetKeyword() falls back to the next usable focus keyword when the first is the brand.
 * - Seo\PostLanguage: Polylang, then WPML, then the lga_post_language filter, then the owner's counterpart meta key
 *   (seo.language_counterpart_meta_key) with the address prefix (or the text), then an ISO 639-1 address prefix,
 *   then the language of the item's text (pages in another language at the site root), then the site language;
 *   worked out once per item.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Seo;

require_once dirname( __DIR__, 2 ) . '/Support/StubsSeo.php';

use LinkGrowth\Autopilot\Seo\BrandTerms;
use LinkGrowth\Autopilot\Seo\InternalLinker;
use LinkGrowth\Autopilot\Seo\PostLanguage;
use LinkGrowth\Autopilot\Seo\RenderCheck;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Tests\Support\Container;
use LinkGrowth\Autopilot\Tests\Support\StubsSeo;
use LinkGrowth\Autopilot\Tests\Support\TestCase;

final class LinkRulesTest extends TestCase {

    private string $home = '';

    protected function setUp(): void {
        parent::setUp();
        StubsSeo::reset();
        $this->home = (string) $GLOBALS['lga_test_home'];
    }

    protected function tearDown(): void {
        $GLOBALS['lga_test_home'] = $this->home;
        parent::tearDown();
    }

    private function settings(): Settings {
        return Container::get( Settings::class );
    }

    public function testNormalisationFoldsCaseSpacesPunctuationAndAccents(): void {
        $this->assertSame( 'northwind supply co', BrandTerms::normalize( 'NORTHWIND SUPPLY CO' ) );
        $this->assertSame( 'northwind supply co', BrandTerms::normalize( "  Nòrthwind\u{00A0}Süpply \u{2014} Có! " ) );
        $this->assertSame( 'northwind supply co', BrandTerms::normalize( 'Northwind&nbsp;Supply-Co' ) );
        $this->assertSame( 'northwindsupplyco example', BrandTerms::normalize( 'NorthwindSupplyCo.example' ) );
        $this->assertSame( 'laboratoires reunis strasse oeuvre', BrandTerms::normalize( 'LABORATOIRES RÉUNIS — Straße Œuvre' ) );
        $this->assertSame( [ 'northwindsupplyco.example', 'northwindsupplyco' ], BrandTerms::domainForms( 'www.NorthwindSupplyCo.example' ) );
        $this->assertSame( [ 'northwindsupplyco.example', 'northwindsupplyco' ], BrandTerms::domainForms( 'https://northwindsupplyco.example/shop/' ) );
        $this->assertSame( [ 'example.co.uk', 'example' ], BrandTerms::domainForms( 'example.co.uk' ) );
        $this->assertSame( [ 'localhost' ], BrandTerms::domainForms( 'localhost' ) );
        $this->assertSame( [], BrandTerms::domainForms( '' ) );
    }

    public function testTheLiveBrandTermsCoverEveryWrittenFormAndOnlyThose(): void {
        $this->settings()->update( [ 'site' => [ 'brand_name' => 'Northwind Supply Co', 'legal_name' => 'NORTHWIND SUPPLY CO', 'domain' => 'northwindsupplyco.example' ], 'discovery' => [ 'brand_terms' => [ 'Northwind Supply Co', 'northwindsupplyco.example', 'northwindsupplyco' ] ] ] );
        $brand = BrandTerms::fromSettings( $this->settings() );
        $this->assertSame( [ 'northwind supply co', 'northwind supply co example' ], $brand->terms(), 'the domain without spaces is read as the brand name’s words' );
        foreach ( [ 'Northwind Supply Co', 'NORTHWIND SUPPLY CO', 'northwind-supply có', 'Supply Co', 'supply  co', 'Northwind Supply', 'NorthwindSupply', 'northwindsupplyco', 'NorthwindSupplyCo.example', 'Northwind', 'Co' ] as $anchor ) {
            $this->assertTrue( $brand->isBrand( $anchor ), $anchor );
        }
        foreach ( [ 'Corrugated Boxes', 'wind', 'pply', 'Supply Chain', 'Supply Co Express', 'Northwind Supply Co reviews', 'fournisseur de fournitures industrielles', '', '   ' ] as $anchor ) {
            $this->assertFalse( $brand->isBrand( $anchor ), $anchor );
        }
        $this->assertTrue( $brand->sharesWord( 'Supply Co Express' ) );
        $this->assertFalse( $brand->sharesWord( 'Corrugated Boxes' ) );
    }

    public function testLegalNameInCapitalsBlognameAndTheDomainWithoutItsTldAreBrandTerms(): void {
        $this->settings()->update( [ 'site' => [ 'brand_name' => 'Acme Labs', 'legal_name' => 'LABORATOIRES ACME RÉACTIFS INC.', 'domain' => 'www.acme-labs.ca' ], 'discovery' => [ 'brand_terms' => [ 'Acme' ] ] ] );
        update_option( 'blogname', 'Acmé Lab Store' );
        $brand = BrandTerms::fromSettings( $this->settings() );
        foreach ( [ 'Laboratoires Acme Reactifs', 'acme réactifs', 'Réactifs Inc', 'ACME-LABS', 'acme-labs.ca', 'acmelabs', 'Acme Lab Store', 'lab store', 'ACME' ] as $anchor ) {
            $this->assertTrue( $brand->isBrand( $anchor ), $anchor );
        }
        foreach ( [ 'Northwind Supply Co', 'acme reactifs inc catalogue', 'lab supplies' ] as $anchor ) {
            $this->assertFalse( $brand->isBrand( $anchor ), $anchor );
        }

        // With no site.domain, the home address's host is the domain.
        $this->settings()->update( [ 'site' => [ 'domain' => '' ] ] );
        $GLOBALS['lga_test_home'] = 'https://www.labo-quebec.ca';
        $this->assertTrue( BrandTerms::fromSettings( $this->settings() )->isBrand( 'Labo Québec' ) );
    }

    public function testBrandMentionsAndWhetherAMatchCutsThem(): void {
        $brand = new BrandTerms( [ 'Northwind Supply Co', 'NORTHWIND SUPPLY CO', 'northwindsupplyco.example', 'northwindsupplyco' ] );
        $text  = 'All content belongs to Northwind Supply Co or its licensors. Visit NorthwindSupplyCo.example or NORTHWIND SUPPLY CÓ today. Supply Co wide.';
        $m     = BrandTerms::mentions( $text, $brand->patterns() );
        $first = strpos( $text, 'Northwind Supply Co' );
        $this->assertContains( [ $first, $first + strlen( 'Northwind Supply Co' ) ], $m );
        $site = strpos( $text, 'NorthwindSupplyCo.example' );
        $this->assertContains( [ $site, $site + strlen( 'NorthwindSupplyCo.example' ) ], $m, 'the domain written without spaces' );
        $caps = strpos( $text, 'NORTHWIND SUPPLY C' );
        $this->assertContains( [ $caps, $caps + strlen( 'NORTHWIND SUPPLY CÓ' ) ], $m, 'capitals and an accent' );
        $tail = strrpos( $text, 'Supply Co' );
        foreach ( $m as [ $s, $e ] ) {
            $this->assertFalse( $s <= $tail && $tail < $e, 'the last words are not a brand mention (no "Northwind")' );
        }

        $this->assertTrue( BrandTerms::cuts( $m, $first + 10, $first + 19 ), '"Supply Co" inside "Northwind Supply Co"' );
        $this->assertTrue( BrandTerms::cuts( $m, $first + 17, $first + 26 ), '"Co or its" cuts through the name' );
        $this->assertFalse( BrandTerms::cuts( $m, $first, $first + 22 ), '"Northwind Supply Co or" holds the whole name' );
        $this->assertFalse( BrandTerms::cuts( $m, $tail, $tail + 9 ), 'words outside every mention' );
    }

    public function testLocateSkipsAnOccurrenceInsideTheBrandName(): void {
        $patterns = ( new BrandTerms( [ 'Northwind Supply Co' ] ) )->patterns();
        $html     = '<p>Orders from Northwind Supply Co Express arrive fast.</p><p>Choose <strong>Supply Co Express</strong> at checkout.</p>';
        $old      = InternalLinker::locate( InternalLinker::analyse( $html ), 'Supply Co Express' );
        $this->assertSame( strpos( $html, 'Supply Co Express' ), $old['start'] ?? null, 'without the brand rule (1.2.2): the words inside the name' );
        $new = InternalLinker::locate( InternalLinker::analyse( $html, $patterns ), 'Supply Co Express' );
        $this->assertSame( strrpos( $html, 'Supply Co Express' ), $new['start'] ?? null, 'the next occurrence, outside the name' );
        $this->assertNull( InternalLinker::locate( InternalLinker::analyse( '<p>Only from Northwind Supply Co Express.</p>', $patterns ), 'Supply Co Express' ) );
        $inline = '<p>belong to <em>Northwind</em> Supply Co or its licensors</p>';
        $this->assertNotNull( InternalLinker::locate( InternalLinker::analyse( $inline ), 'Supply Co' ) );
        $this->assertNull( InternalLinker::locate( InternalLinker::analyse( $inline, $patterns ), 'Supply Co' ), 'also when part of the name is in an inline tag' );
        // The page check reads the words around the same spot the suggestion uses.
        $ctx = RenderCheck::context( $html, 'Supply Co Express', $patterns );
        $this->assertStringContainsString( ' choose supply co express at checkout ', (string) ( $ctx['full'] ?? '' ) );
        $this->assertSame( 1, RenderCheck::pageOf( '<p>Northwind Supply Co Express.</p><!--nextpage--><p>Choose Supply Co Express.</p>', 'Supply Co Express' ) );
        $this->assertSame( 2, RenderCheck::pageOf( '<p>Northwind Supply Co Express.</p><!--nextpage--><p>Choose Supply Co Express.</p>', 'Supply Co Express', $patterns ) );
        $whole = '<p>Read the Northwind Supply Co reviews first.</p>';
        $this->assertSame( strpos( $whole, 'Northwind' ), InternalLinker::locate( InternalLinker::analyse( $whole, $patterns ), 'northwind supply co reviews' )['start'] ?? null, 'a match that holds the whole name does not cut it' );
    }

    public function testFocusKeywordFallsBackOnlyWhenTheFirstKeywordIsTheBrand(): void {
        $brand = new BrandTerms( [ 'Northwind Supply Co', 'northwindsupplyco.example', 'northwindsupplyco' ] );
        $cases = [
            'French About page: next usable keyword'      => [ [ 'northwind supply co', 'fournisseur de fournitures industrielles', 'fournitures de laboratoire' ], 'fournisseur de fournitures industrielles' ],
            'generic and brand-only keywords are skipped' => [ [ 'Northwind Supply Co', 'home', 'co', 'Supply Co', 'fastener storage' ], 'fastener storage' ],
            'domain forms are the brand'                  => [ [ 'northwindsupplyco.example', 'northwindsupplyco', 'lab reports online' ], 'lab reports online' ],
            'the home page: brand only'                   => [ [ 'supply co' ], '' ],
            'brand, then nothing usable'                  => [ [ 'northwind supply co', 'faq', 'northwind supply' ], '' ],
            'first keyword generic: no fallback (1.2.2)'  => [ [ 'home', 'fastener storage' ], '' ],
            'first keyword empty: no fallback (1.2.2)'    => [ [ '', 'fastener storage' ], '' ],
            'first keyword usable'                        => [ [ 'fastener storage', 'cold chain' ], 'fastener storage' ],
            'no keyword'                                  => [ [], '' ],
        ];
        foreach ( $cases as $label => [ $keywords, $expected ] ) {
            $this->assertSame( $expected, InternalLinker::targetKeyword( $keywords, $brand ), $label );
        }
    }

    public function testLanguageSourcesAreReadInOrder(): void {
        $home = 'https://northwind.test';
        $key  = 'translation_of'; // the owner's counterpart meta key (seo.language_counterpart_meta_key)
        StubsSeo::addPost( 300, [ 'post_type' => 'page', 'post_title' => 'À propos', 'url' => $home . '/fr/a-propos/' ], [ $key => 138 ] );
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
        $this->assertSame( 'it', ( new PostLanguage() )->of( 300 ), '3. the lga_post_language filter' );
        $filtered = '';
        $this->assertSame( 'fr', ( new PostLanguage( [], $key ) )->of( 300 ), '4. a counterpart (owner key): the language of its address prefix' );

        // A counterpart whose address has no language prefix: not the site language, language not known (matches nothing).
        StubsSeo::addPost( 301, [ 'post_type' => 'page', 'post_title' => 'Contact FR', 'url' => $home . '/nous-joindre/' ], [ $key => 139 ] );
        $this->assertSame( 'en', ( new PostLanguage() )->of( 301 ), 'no counterpart key named: the meta is not read' );
        $lang = new PostLanguage( [], $key );
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
        $GLOBALS['lga_test_home'] = 'https://example.test/shop';
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
     * A site can have landing pages in another language at the site root (/acheter-fournitures/, page 11783) with no
     * language prefix and no counterpart meta: their text decides, not the site language.
     */
    public function testAnItemWithNoOtherSignalIsInTheLanguageItsTextIsWrittenIn(): void {
        $home   = 'https://northwind.test';
        $achat  = '<!-- wp:paragraph --><p>Fournisseur de fournitures industrielles : prix en dollars, expédition suivie depuis notre entrepôt et fiches techniques pour chaque lot.</p><!-- /wp:paragraph -->'
            . '<p>Notre ruban PTFE est expédié partout au pays. Nous préparons les commandes dans notre entrepôt et vous recevez un numéro de suivi dès le départ du colis.</p>';
        $english = '<p>Every order ships from our Portland warehouse with a tracking number. We pack each carton on a pallet, and the parcel is insured for its full value.</p>'
            . '<p>French readers can see fournitures industrielles au Québec. Load ratings follow Smith et al. and Tremblay et al. for each of the products.</p>';
        StubsSeo::addPost( 11783, [ 'post_type' => 'page', 'post_title' => 'Acheter des fournitures industrielles au Québec', 'url' => $home . '/acheter-fournitures/', 'post_content' => $achat ] );
        StubsSeo::addPost( 700, [ 'post_type' => 'page', 'post_title' => 'Shipping across the region', 'url' => $home . '/shipping/', 'post_content' => $english ] );
        StubsSeo::addPost( 701, [ 'post_type' => 'page', 'post_title' => 'Écrivez-nous', 'url' => $home . '/ecrivez-nous/', 'post_content' => '<p>Une question sur une commande ?</p>' ] );
        StubsSeo::addPost( 702, [ 'post_type' => 'page', 'post_title' => 'Cart', 'url' => $home . '/cart/', 'post_content' => '<!-- wp:shortcode -->[woocommerce_cart]<!-- /wp:shortcode -->' ] );
        StubsSeo::addPost( 500, [ 'post_type' => 'product', 'post_title' => 'PTFE Thread Tape', 'post_excerpt' => '<p>Thread seal tape for pipe fittings. Each roll is tested by a third-party lab and ships with its data sheet.</p>' ] );
        // A counterpart (owner key) without a language prefix: the language of its text when that is not the site
        // language, else not known.
        StubsSeo::addPost( 703, [ 'post_type' => 'page', 'post_title' => 'Livraison', 'url' => $home . '/livraison/', 'post_content' => $achat ], [ 'translation_of' => 700 ] );
        StubsSeo::addPost( 704, [ 'post_type' => 'page', 'post_title' => 'Shipping copy', 'url' => $home . '/shipping-copy/', 'post_content' => $english ], [ 'translation_of' => 700 ] );

        $lang = new PostLanguage( [], 'translation_of' );
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
        $this->assertSame( '', PostLanguage::textLanguage( 'Le ruban PTFE et le film étirable.' ), 'too few function words' );
        $this->assertSame( '', PostLanguage::textLanguage( '<p>Il ne le sono il ne le dice il ne le fa il ne le vede.</p>' ), 'too few different ones (Italian)' );
        $this->assertSame( '', PostLanguage::textLanguage( str_repeat( 'the and of to le et des du ', 3 ) ), 'neither language clearly' );
    }

    public function testEachItemsLanguageIsWorkedOutOncePerRun(): void {
        StubsSeo::addPost( 310, [ 'post_title' => 'Contacto', 'url' => 'https://northwind.test/es/contacto/' ] );
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
