<?php
declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Analysis;

use PPC\LinkGrowth\Analysis\FormDetector;
use PPC\LinkGrowth\Analysis\PageAnalyzer;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class PageAnalyzerTest extends TestCase {

    public function testHomepageExtraction(): void {
        $a = ( new PageAnalyzer() )->analyze( $this->fixture( 'sites/labdirectory.test/index.html' ), 'https://labdirectory.test/' );
        $this->assertSame( 'Canadian Laboratory Suppliers Directory | LabDirectory Canada', $a->title );
        $this->assertSame( 'LabDirectory Canada', $a->siteName );
        $this->assertSame( 'en-CA', $a->lang );
        $this->assertStringContainsString( 'laboratory equipment', $a->metaDescription );
        $this->assertSame( 'https://labdirectory.test/', $a->canonical );
        $this->assertFalse( $a->canonicalMismatch() );
        $this->assertTrue( $a->isIndexable() );
        $this->assertGreaterThan( 250, $a->wordCount );
        $this->assertContains( 'Canadian Laboratory Suppliers Directory', $a->h1 );
        $emails = array_column( $a->emails, 'email' );
        $this->assertContains( 'info@labdirectory.test', $emails );
        $this->assertNotEmpty( $a->jsonLd );
        $this->assertSame( '2026-10-20', $a->datePublished );
        $this->assertTrue( $a->hasNewsletter );
        $this->assertCount( 2, $a->socialLinks );
        $this->assertTrue( $a->hasPathLink( '#/about#' ) );
        $ext = $a->externalLinks();
        $hosts = array_column( $ext, 'host' );
        $this->assertContains( 'northernlabsupply.test', $hosts );
        $this->assertTrue( in_array( 'nav', array_column( $a->links, 'region' ), true ) );
        $this->assertTrue( in_array( 'footer', array_column( $a->links, 'region' ), true ) );
    }

    public function testListingFormDetectionAndSemantics(): void {
        $a = ( new PageAnalyzer() )->analyze( $this->fixture( 'sites/labdirectory.test/add-listing/index.html' ), 'https://labdirectory.test/add-listing/' );
        $this->assertCount( 1, $a->forms );
        $form = $a->forms[0];
        $this->assertSame( FormDetector::KIND_LISTING, $form['kind'] );
        $this->assertSame( 'POST', $form['method'] );
        $this->assertSame( 'https://labdirectory.test/add-listing/submit', $form['action'] );
        $this->assertFalse( $form['has_captcha'] );
        $this->assertFalse( $form['has_password'] );
        $sem = array_column( $form['fields'], 'semantic', 'name' );
        $this->assertSame( 'business_name', $sem['business_name'] );
        $this->assertSame( 'website', $sem['website'] );
        $this->assertSame( 'email', $sem['email'] );
        $this->assertSame( 'phone', $sem['phone'] );
        $this->assertSame( 'category', $sem['category'] );
        $this->assertSame( 'description', $sem['description'] );
        $this->assertSame( 'city', $sem['city'] );
        $this->assertSame( 'region', $sem['province'] );
        $this->assertSame( 'postal_code', $sem['postal_code'] );
        $this->assertSame( 'hidden', $sem['form_token'] );
        $this->assertSame( 'consent', $sem['accuracy'] );
        $this->assertSame( 'consent', $sem['newsletter'] );
        $cat = array_values( array_filter( $form['fields'], static fn( $f ) => 'category' === $f['name'] ) )[0];
        $this->assertCount( 6, $cat['options'] );
        $this->assertTrue( $cat['required'] );
    }

    public function testSpamPageSignals(): void {
        $a = ( new PageAnalyzer() )->analyze( $this->fixture( 'sites/spam.test/index.html' ), 'https://spam.test/' );
        $hidden = array_filter( $a->links, static fn( $l ) => $l['hidden'] );
        $this->assertCount( 3, $hidden );
        $this->assertContains( 'casino bonus', $a->mentions( [ 'casino bonus', 'peptide' ] ) );
    }

    public function testMentionSnippet(): void {
        $a = ( new PageAnalyzer() )->analyze( $this->fixture( 'sites/mention.test/blog/canadian-peptide-suppliers/index.html' ), 'https://mention.test/blog/canadian-peptide-suppliers/' );
        $this->assertSame( [ 'pure peptides canada' ], $a->mentions( [ 'Pure Peptides Canada', 'purepeptidescanada.com' ] ) );
        $this->assertEmpty( $a->linksTo( 'purepeptidescanada.test' ) );
        $this->assertStringContainsString( 'Toronto-based supplier', $a->snippetAround( 'Pure Peptides Canada', 60 ) );
        $this->assertTrue( $a->hasAuthorByline );
        $this->assertSame( '2026-08-02T10:00:00Z', $a->datePublished );
        $b = ( new PageAnalyzer() )->analyze( $this->fixture( 'sites/mention.test/blog/linked/index.html' ), 'https://mention.test/blog/linked/' );
        $links = $b->linksTo( 'purepeptidescanada.test' );
        $this->assertCount( 1, $links );
        $this->assertTrue( $links[0]['nofollow'] );
    }
}
