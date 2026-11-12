<?php
/**
 * Regression tests for the analysis-scoring slice fixes (analysis-scoring-1 … analysis-scoring-11).
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Analysis;

use PPC\LinkGrowth\Analysis\ContactExtractor;
use PPC\LinkGrowth\Analysis\FormDetector;
use PPC\LinkGrowth\Analysis\PageAnalysis;
use PPC\LinkGrowth\Analysis\PageAnalyzer;
use PPC\LinkGrowth\Analysis\RelevanceScorer;
use PPC\LinkGrowth\Analysis\RiskAnalyzer;
use PPC\LinkGrowth\Analysis\SiteProfile;
use PPC\LinkGrowth\Analysis\SiteProfiler;
use PPC\LinkGrowth\Analysis\SubmissionPageFinder;
use PPC\LinkGrowth\Submission\Policy;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class AnalysisScoringFixesTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 0 );
        Container::get( Settings::class )->set( 'providers.rdap.enabled', false );
    }

    private function analyze( string $html, string $url = 'https://site.test/', array $headers = [] ): PageAnalysis {
        return ( new PageAnalyzer() )->analyze( $html, $url, $headers );
    }

    private function profileOf( PageAnalysis $home, string $domain = 'site.test' ): SiteProfile {
        $p              = new SiteProfile();
        $p->domain      = $domain;
        $p->homepageUrl = 'https://' . $domain . '/';
        $p->reachable   = true;
        $p->https       = true;
        $p->language    = 'en';
        $p->home        = $home;
        return $p;
    }

    private function longText(): string {
        return str_repeat( 'Our laboratory publishes research notes on analytical chemistry, sample preparation and quality control for Canadian institutions. ', 12 );
    }

    // ------------------------------------------------------------------ analysis-scoring-1

    public function testGeoScoreIsAnIntegerForOddCanadianConfidence(): void {
        $scorer = Container::get( RelevanceScorer::class );
        $home   = $this->analyze( '<html><head><title>Lab supplies</title></head><body><p>Laboratory equipment in Toronto.</p></body></html>' );
        foreach ( [ 25 => 62, 41 => 70, 89 => 94, 93 => 96, 99 => 99, 80 => 90 ] as $confidence => $geo ) {
            $r = $scorer->score( [ $home ], 'CA', $confidence );
            $this->assertSame( $geo, $r['geo'], "confidence {$confidence}" );
            $this->assertIsInt( $r['score'] );
        }
    }

    public function testCanadianSiteWithPostalCodeProfilesAndScores(): void {
        HttpFixtures::add( 'https://northlab.ca/robots.txt', "User-agent: *\nAllow: /\n", 200, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://northlab.ca/', '<html lang="en-CA"><head><title>NorthLab | Laboratory supplies</title></head><body><main><h1>Laboratory equipment</h1><p>Visit us at 100 King St W, Toronto, ON M5X 1A9.</p></main></body></html>' );
        $p = Container::get( SiteProfiler::class )->profile( 'northlab.ca' );
        $this->assertSame( 'CA', $p->country );
        $this->assertSame( 1, $p->countryConfidence % 2, 'the postal-code bonus makes the confidence odd' );
        $r = Container::get( RelevanceScorer::class )->score( [ $p->home ], $p->country, $p->countryConfidence );
        $this->assertIsInt( $r['geo'] );
        $this->assertGreaterThanOrEqual( 60, $r['geo'] );
    }

    // ------------------------------------------------------------------ analysis-scoring-2

    private function bbPressReplyForm(): string {
        return '<html><body><div id="new-reply-123" class="bbp-reply-form"><form id="new-post" name="new-post" method="post" action="">'
            . '<label for="bbp_anonymous_author">Name (required):</label><input type="text" id="bbp_anonymous_author" name="bbp_anonymous_name">'
            . '<label for="bbp_anonymous_email">Mail (will not be published) (required):</label><input type="text" id="bbp_anonymous_email" name="bbp_anonymous_email">'
            . '<label for="bbp_anonymous_website">Website:</label><input type="text" id="bbp_anonymous_website" name="bbp_anonymous_website">'
            . '<textarea id="bbp_reply_content" class="bbp-the-content" name="bbp_reply_content"></textarea>'
            . '<input type="hidden" name="bbp_topic_id" value="123"><input type="hidden" name="action" value="bbp-new-reply"><input type="hidden" name="_wpnonce" value="n">'
            . '<button type="submit" name="bbp_reply_submit">Submit</button></form></div></body></html>';
    }

    /** @return array<string,string> */
    private function commentForms(): array {
        return [
            'bbpress'   => $this->bbPressReplyForm(),
            'wpdiscuz'  => '<html><body><div class="wpd-form wpd-form-wrapper" id="wpd-main-form-wrapper-0_0"><form method="post" class="wpd_comm_form wpd_main_comm_form">'
                . '<textarea id="wc-textarea-0_0" name="wc_comment"></textarea><input id="wc_name-0_0" type="text" name="wc_name" placeholder="Name*">'
                . '<input id="wc_email-0_0" type="email" name="wc_email" placeholder="Email*"><input id="wc_website-0_0" type="text" name="wc_website" placeholder="Website">'
                . '<input type="submit" name="submit" value="Post Comment"></form></div></body></html>',
            'reply'     => '<html><body><h3>Leave a reply</h3><form id="respond-form" class="reply-form" method="post" action="/post/reply"><input name="author" placeholder="Your name"><input type="email" name="email" placeholder="Email"><input name="url" placeholder="Website"><textarea name="text"></textarea><button type="submit" name="post">Post</button></form></body></html>',
            'guestbook' => '<html><body><form id="gwolle_gb_new_entry" method="post" action=""><label for="gwolle_gb_author_name">Name</label><input id="gwolle_gb_author_name" name="gwolle_gb_author_name"><label for="gwolle_gb_author_email">Email</label><input id="gwolle_gb_author_email" name="gwolle_gb_author_email" type="email"><label for="gwolle_gb_author_website">Website</label><input id="gwolle_gb_author_website" name="gwolle_gb_author_website"><label for="gwolle_gb_content">Guestbook entry</label><textarea id="gwolle_gb_content" name="gwolle_gb_content"></textarea></form></body></html>',
            'wp-core'   => '<html><body><div id="respond" class="comment-respond"><form action="https://site.test/wp-comments-post.php" method="post" id="commentform" class="comment-form"><textarea id="comment" name="comment"></textarea><input id="author" name="author"><input id="email" name="email" type="email"><input id="url" name="url" type="url"><input type="hidden" name="comment_post_ID" value="9"></form></div></body></html>',
            'container' => '<html><body><div id="comments" class="comments-area"><form method="post" action="/x"><label for="n">Name</label><input id="n" name="n"><label for="w">Website</label><input id="w" name="w" type="url"><label for="d">About you</label><textarea id="d" name="d"></textarea></form></div></body></html>',
        ];
    }

    public function testCommentForumAndGuestbookFormsAreNeverListingForms(): void {
        $policy = Container::get( Policy::class );
        foreach ( $this->commentForms() as $name => $html ) {
            $a = $this->analyze( $html, 'https://site.test/forums/topic/lab-suppliers/' );
            $this->assertCount( 1, $a->forms, $name );
            $form = $a->forms[0];
            $this->assertSame( FormDetector::KIND_COMMENT, $form['kind'], $name );
            $this->assertNotSame( [], $policy->formBlockers( $form ), "{$name}: the policy refuses to auto-submit it" );
        }
    }

    public function testSubmissionFinderIgnoresAForumReplyFormOnTheHomepage(): void {
        $home = $this->analyze( $this->bbPressReplyForm(), 'https://forum.test/' );
        $best = Container::get( SubmissionPageFinder::class )->find( $home, 0 );
        $this->assertNotSame( FormDetector::KIND_LISTING, $best['kind'] );
        $this->assertNull( $best['form'] );
    }

    public function testUnlabeledTextareaAloneDoesNotMakeAListingForm(): void {
        $html = '<html><body><form method="post" action="/send"><input name="author"><input type="email" name="email"><input type="url" name="site"><textarea name="t"></textarea></form></body></html>';
        $form = $this->analyze( $html )->forms[0];
        $this->assertSame( 'description', array_column( $form['fields'], 'semantic', 'name' )['t'], 'the semantic fallback is unchanged' );
        $this->assertNotSame( FormDetector::KIND_LISTING, $form['kind'] );
    }

    public function testRealListingFormsAreStillListingForms(): void {
        $listing = $this->analyze( $this->fixture( 'sites/labdirectory.test/add-listing/index.html' ), 'https://labdirectory.test/add-listing/' );
        $this->assertSame( FormDetector::KIND_LISTING, $listing->forms[0]['kind'] );

        // Website + an explicitly labelled description is a listing even without a business-name field.
        $described = $this->analyze( '<html><body><form method="post" action="/add"><label for="u">Website URL</label><input id="u" name="u" type="url"><label for="d">Business description</label><textarea id="d" name="d"></textarea></form></body></html>' );
        $this->assertSame( FormDetector::KIND_LISTING, $described->forms[0]['kind'] );

        // An explicit listing attribute still qualifies a form with an unlabeled textarea.
        $explicit = $this->analyze( '<html><body><form id="add-business" method="post" action="/directory/submit"><input type="url" name="site"><textarea name="t"></textarea></form></body></html>' );
        $this->assertSame( FormDetector::KIND_LISTING, $explicit->forms[0]['kind'] );
    }

    // ------------------------------------------------------------------ analysis-scoring-3

    public function testSmallFontSizeIsNotHiddenButZeroIs(): void {
        $links = '<a href="https://www.nserc-crsng.gc.ca/">NSERC</a> <a href="https://cihr-irsc.gc.ca/">CIHR</a> <a href="https://nrc-cnrc.gc.ca/">NRC</a>';
        $page  = $this->analyze( '<html><body><main><p>' . $this->longText() . '</p><p style="font-size:0.875rem">Funding partners: ' . $links . '</p><p style="font-size:0.9em;opacity:0.5">' . $links . ' again</p></main></body></html>' );
        foreach ( $page->externalLinks() as $l ) {
            $this->assertFalse( $l['hidden'], $l['url'] );
        }
        $risk = Container::get( RiskAnalyzer::class )->analyze( $this->profileOf( $page ) );
        $this->assertNotContains( 'hidden_links', $risk['flags'] );
        $this->assertFalse( $risk['hard_reject'] );

        foreach ( [ 'font-size:0', 'font-size: 0px;', 'opacity:0 !important', 'display:none', 'opacity:0.0' ] as $style ) {
            $hidden = $this->analyze( '<html><body><main><p>' . $this->longText() . '</p><div style="' . $style . '">' . $links . '</div></main></body></html>' );
            $this->assertSame( [ true, true, true ], array_column( $hidden->externalLinks(), 'hidden' ), $style );
            $this->assertContains( 'hidden_links', Container::get( RiskAnalyzer::class )->analyze( $this->profileOf( $hidden ) )['flags'], $style );
        }
    }

    // ------------------------------------------------------------------ analysis-scoring-4

    public function testPbnFootprintIgnoresInternalAnchorsAndSubstrings(): void {
        $nav  = '<nav><a href="/a/">Validating antibody assays</a><a href="/b/">Hosting a seminar</a><a href="/c/">Quantum cryptography group</a><a href="/d/">Seoul conference</a><a href="/e/">Undergraduate essay prize</a><a href="/f/">Updating protocols</a></nav>';
        $page = $this->analyze( '<html><head><title>Chemistry Department</title></head><body>' . $nav . '<main><p>' . $this->longText() . '</p></main></body></html>', 'https://chem.site.test/' );
        $this->assertSame( [], $page->externalLinks() );
        $risk = Container::get( RiskAnalyzer::class )->analyze( $this->profileOf( $page ) );
        $this->assertNotContains( 'pbn_footprint', $risk['flags'] );
        $this->assertFalse( $risk['hard_reject'] );

        // The same anchors pointing off-site only match whole words (essay, hosting), which is below the threshold.
        $ext  = str_replace( [ 'href="/a/"', 'href="/b/"', 'href="/c/"', 'href="/d/"', 'href="/e/"', 'href="/f/"' ], [ 'href="https://a.test/"', 'href="https://b.test/"', 'href="https://c.test/"', 'href="https://d.test/"', 'href="https://e.test/"', 'href="https://f.test/"' ], $nav );
        $page = $this->analyze( '<html><head><title>Chemistry Department</title></head><body>' . $ext . '<main><p>' . $this->longText() . '</p></main></body></html>', 'https://chem.site.test/' );
        $this->assertCount( 6, $page->externalLinks() );
        $this->assertNotContains( 'pbn_footprint', Container::get( RiskAnalyzer::class )->analyze( $this->profileOf( $page ) )['flags'] );
    }

    public function testPbnFootprintStillFlagsOutboundLinkFarms(): void {
        $farm = '<ul><li><a href="https://casino1.test/">best casinos</a></li><li><a href="https://loans.test/">payday loans</a></li><li><a href="https://vape.test/">vape wholesale</a></li><li><a href="https://forex.test/">forex signals</a></li><li><a href="https://lawyer.test/">divorce lawyers</a></li></ul>';
        $page = $this->analyze( '<html><head><title>Blog</title></head><body><main><p>' . $this->longText() . '</p>' . $farm . '</main></body></html>' );
        $risk = Container::get( RiskAnalyzer::class )->analyze( $this->profileOf( $page ) );
        $this->assertContains( 'pbn_footprint', $risk['flags'] );
        $this->assertTrue( $risk['hard_reject'] );

        // Several niche anchors that all point at one domain are not a network footprint.
        $one  = preg_replace( '#https://[a-z0-9]+\.test/#', 'https://partner.test/', $farm );
        $page = $this->analyze( '<html><head><title>Blog</title></head><body><main><p>' . $this->longText() . '</p>' . $one . '</main></body></html>' );
        $this->assertNotContains( 'pbn_footprint', Container::get( RiskAnalyzer::class )->analyze( $this->profileOf( $page ) )['flags'] );
    }

    // ------------------------------------------------------------------ analysis-scoring-5

    private function cp1252Page( string $meta ): string {
        $html = '<html><head>' . $meta . '<title>Répertoire des laboratoires du Québec</title></head><body><main><p>Contactez Hélène Côté</p>'
            . '<form method="post" action="/ajout"><label for="cat">Catégorie</label><select id="cat" name="cat"><option value="Équipement">Équipement</option></select></form></main></body></html>';
        return (string) mb_convert_encoding( $html, 'Windows-1252', 'UTF-8' );
    }

    public function testLegacyMetaCharsetIsNotDoubleDecoded(): void {
        $cases = [
            'meta-only'      => [ $this->cp1252Page( '<meta charset="windows-1252">' ), [] ],
            'header+meta'    => [ $this->cp1252Page( '<meta charset="windows-1252">' ), [ 'content-type' => 'text/html; charset=windows-1252' ] ],
            'http-equiv'     => [ $this->cp1252Page( '<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">' ), [] ],
            'utf8+stalemeta' => [ (string) mb_convert_encoding( $this->cp1252Page( '<meta charset="iso-8859-1">' ), 'UTF-8', 'Windows-1252' ), [ 'content-type' => 'text/html; charset=utf-8' ] ],
        ];
        foreach ( $cases as $name => [ $html, $headers ] ) {
            $a = $this->analyze( $html, 'https://repertoire.test/', $headers );
            $this->assertSame( 'Répertoire des laboratoires du Québec', $a->title, $name );
            $this->assertStringContainsString( 'Contactez Hélène Côté', $a->text, $name );
            $field = $a->forms[0]['fields'][0];
            $this->assertSame( 'Catégorie', $field['label'], $name );
            $this->assertSame( 'Équipement', $field['options'][0]['value'], $name );
        }
        $this->assertStringContainsString( 'charset="utf-8"', ( new PageAnalyzer() )->utf8Html( $this->cp1252Page( '<meta charset="windows-1252">' ) ) );
    }

    // ------------------------------------------------------------------ analysis-scoring-6

    public function testContactPageIsFetchedBeforeAboutAndTeamPages(): void {
        HttpFixtures::add( 'https://acme.test/robots.txt', "User-agent: *\nAllow: /\n", 200, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://acme.test/', '<html lang="en"><head><title>Acme Labs</title></head><body><nav><a href="/about/">About</a><a href="/team/">Our team</a><a href="/contact/">Contact</a></nav><main><p>Lab equipment.</p></main></body></html>' );
        HttpFixtures::add( 'https://acme.test/about/', '<html><body><main><p>About us</p></main></body></html>' );
        HttpFixtures::add( 'https://acme.test/team/', '<html><body><main><p>Team</p></main></body></html>' );
        HttpFixtures::add( 'https://acme.test/contact/', '<html><body><main><p>Write to editor@acme.test</p></main></body></html>' );
        $p = Container::get( SiteProfiler::class )->profile( 'acme.test', '', 2 );
        $this->assertSame( 'https://acme.test/contact/', $p->contactUrl );
        $this->assertSame( [ 'https://acme.test/contact/', 'https://acme.test/about/' ], array_map( static fn( PageAnalysis $x ) => $x->url, $p->pages ) );
        $this->assertContains( 'editor@acme.test', array_column( $p->contacts, 'email' ) );
        $this->assertNotContains( 'https://acme.test/team/', array_column( HttpFixtures::$requests, 'url' ), 'the fetch budget is unchanged' );
    }

    // ------------------------------------------------------------------ analysis-scoring-7

    public function testUnsupportedCharsetLabelsDoNotThrow(): void {
        foreach ( [ 'windows-1250', 'windows-1257', 'iso-8859-8-i', 'tis-620', 'ks_c_5601-1987', 'x-user-defined' ] as $charset ) {
            $meta = $this->analyze( '<html><head><meta charset="' . $charset . '"><title>Title ' . $charset . '</title></head><body><p>x</p></body></html>' );
            $this->assertSame( 'Title ' . $charset, $meta->title, 'meta ' . $charset );
            $hdr = $this->analyze( '<html><head><title>T</title></head><body><p>x</p></body></html>', 'https://site.test/', [ 'content-type' => 'text/html; charset=' . $charset ] );
            $this->assertSame( 'T', $hdr->title, 'header ' . $charset );
        }
        if ( function_exists( 'iconv' ) ) {
            $polish = $this->analyze( "<html><head><meta charset=\"windows-1250\"><title>\xA3\xF3d\x9F</title></head><body></body></html>" );
            $this->assertSame( 'Łódź', $polish->title, 'windows-1250 is converted through iconv' );
        }
    }

    // ------------------------------------------------------------------ analysis-scoring-8

    public function testFieldIdsWithQuotesOrBackslashesKeepTheirLabels(): void {
        $html = '<html><body><form method="post" action="/x">'
            . '<label for=\'a"b\'>Website</label><input id=\'a"b\' name="x">'
            . '<label for="c\\d">Email</label><input id="c\\d" name="y">'
            . '<label for="e\'f">Phone</label><input id="e\'f" name="z">'
            . '</form></body></html>';
        $a   = $this->analyze( $html );
        $sem = array_column( $a->forms[0]['fields'], 'label', 'name' );
        $this->assertSame( [ 'x' => 'Website', 'y' => 'Email', 'z' => 'Phone' ], $sem );
        $this->assertSame( 'website', $a->forms[0]['fields'][0]['semantic'] );
    }

    // ------------------------------------------------------------------ analysis-scoring-9

    public function testPlainTextEmailsInBoilerplateRegionsAreExtracted(): void {
        $html = '<html><body><header><p>Questions? press@acme.ca</p></header><main><p>Main</p></main>'
            . '<aside><p>Write to Jane Okafor at jane.okafor@acme.ca</p></aside><footer><p>Email: info@acme.ca | Tel 416-555-0100</p></footer></body></html>';
        $a = $this->analyze( $html, 'https://acme.ca/' );
        $this->assertSame( 'Main', $a->text, 'boilerplate stays out of the main text' );
        $emails = array_column( $a->emails, 'context', 'email' );
        $this->assertArrayHasKey( 'info@acme.ca', $emails );
        $this->assertArrayHasKey( 'press@acme.ca', $emails );
        $this->assertStringContainsString( 'Email: info@acme.ca', $emails['info@acme.ca'] );
        $contacts = Container::get( ContactExtractor::class )->extract( [ $a ], 'acme.ca' );
        $byEmail  = array_column( $contacts, null, 'email' );
        $this->assertArrayHasKey( 'info@acme.ca', $byEmail );
        $this->assertSame( 'Jane Okafor', $byEmail['jane.okafor@acme.ca']['name'] );

        // A mailto link keeps its higher-confidence source when the same address is also plain text.
        $both = $this->analyze( '<html><body><main><p>Main</p></main><footer><a href="mailto:info@acme.ca">Mail</a> info@acme.ca</footer></body></html>', 'https://acme.ca/' );
        $this->assertSame( [ 'info@acme.ca' => 'mailto' ], array_column( $both->emails, 'source', 'email' ) );
    }

    // ------------------------------------------------------------------ analysis-scoring-10

    public function testJsonLdCountryNamesMapToIsoCodes(): void {
        $profiler = Container::get( SiteProfiler::class );
        $guess    = new \ReflectionMethod( SiteProfiler::class, 'guessCountry' );
        $cases    = [ 'United States' => 'US', 'United Kingdom' => 'GB', 'Ireland' => 'IE', 'Germany' => 'DE', 'Switzerland' => 'CH', 'US' => 'US', 'DE' => 'DE', 'USA' => 'US' ];
        foreach ( $cases as $value => $code ) {
            $home = $this->analyze( '<html><head><script type="application/ld+json">{"@type":"Organization","address":{"@type":"PostalAddress","addressCountry":"' . $value . '"}}</script></head><body><p>x</p></body></html>' );
            $this->assertSame( [ $code, 70 ], $guess->invoke( $profiler, $home, [], 'site.com' ), $value );
        }
        // Unknown names are ignored instead of being truncated into a bogus code ("Narnia" is not "NA").
        $home = $this->analyze( '<html><head><script type="application/ld+json">{"@type":"Organization","address":{"addressCountry":"Narnia"}}</script></head><body><p>x</p></body></html>' );
        $this->assertSame( [ '', 0 ], $guess->invoke( $profiler, $home, [], 'site.com' ) );
    }

    // ------------------------------------------------------------------ analysis-scoring-11

    public function testPageBuilderWidgetsAreContentAndWordPressWidgetsAreSidebar(): void {
        $cases = [
            'elementor'      => [ '<main class="site-main"><div class="elementor-element elementor-widget elementor-widget-text-editor"><div class="elementor-widget-container"><p><a href="https://partner.test/">x</a></p></div></div></main>', 'content' ],
            'elementor-nomain' => [ '<div class="elementor elementor-12"><div class="elementor-widget elementor-widget-text-editor"><div class="elementor-widget-container"><a href="https://partner.test/">x</a></div></div></div>', 'content' ],
            'siteorigin'     => [ '<article><div class="panel-grid"><div class="so-panel widget widget_sow-editor"><div class="so-widget-sow-editor"><a href="https://partner.test/">x</a></div></div></div></article>', 'content' ],
            'wp-aside'       => [ '<main><p>Body</p></main><aside class="widget-area"><section class="widget widget_text"><a href="https://partner.test/">x</a></section></aside>', 'sidebar' ],
            'wp-secondary'   => [ '<main><p>Body</p></main><div id="secondary" class="widget-area" role="complementary"><div class="widget"><a href="https://partner.test/">x</a></div></div>', 'sidebar' ],
            'wp-bare-widget' => [ '<div class="widget widget_recent_entries"><a href="https://partner.test/">x</a></div>', 'sidebar' ],
            'footer-widget'  => [ '<footer><div class="widget widget_text"><a href="https://partner.test/">x</a></div></footer>', 'footer' ],
        ];
        foreach ( $cases as $name => [ $body, $region ] ) {
            $a = $this->analyze( '<html><body>' . $body . '</body></html>' );
            $this->assertSame( $region, $a->externalLinks()[0]['region'], $name );
        }
    }
}
