<?php
declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Support;

use PPC\LinkGrowth\Support\Url;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class UrlTest extends TestCase {

    public function testNormalizeStripsTrackingAndFragments(): void {
        $this->assertSame( 'https://example.com/path?a=1&b=2', Url::normalize( 'HTTPS://Example.com:443/path?utm_source=x&b=2&a=1#frag' ), 'query keys are sorted, tracking params and fragments dropped' );
        $this->assertSame( 'https://example.com/', Url::normalize( 'example.com' ) );
        $this->assertSame( '', Url::normalize( 'javascript:alert(1)' ) );
        $this->assertSame( 'http://example.com:8080/', Url::normalize( 'http://example.com:8080' ) );
    }

    public function testRegistrableDomainHandlesMultiLabelSuffixes(): void {
        $this->assertSame( 'example.co.uk', Url::registrableDomain( 'https://www.sub.example.co.uk/x' ) );
        $this->assertSame( 'city.on.ca', Url::registrableDomain( 'portal.city.on.ca' ) );
        $this->assertSame( 'example.com', Url::registrableDomain( 'www.example.com' ) );
        $this->assertSame( 'labdirectory.test', Url::registrableDomain( 'https://labdirectory.test/add-listing/' ) );
        $this->assertSame( '127.0.0.1', Url::registrableDomain( '127.0.0.1' ) );
    }

    public function testAbsolutizeResolvesRelativePaths(): void {
        $base = 'https://example.com/dir/page.html';
        $this->assertSame( 'https://example.com/dir/other.html', Url::absolutize( 'other.html', $base ) );
        $this->assertSame( 'https://example.com/other', Url::absolutize( '../other', $base ) );
        $this->assertSame( 'https://example.com/root', Url::absolutize( '/root', $base ) );
        $this->assertSame( 'https://cdn.example.org/a', Url::absolutize( '//cdn.example.org/a', $base ) );
        $this->assertSame( '', Url::absolutize( 'mailto:a@b.co', $base ) );
        $this->assertSame( '', Url::absolutize( '#top', $base ) );
    }

    public function testBelongsToAndHtmlDetection(): void {
        $this->assertTrue( Url::belongsTo( 'https://www.purepeptidescanada.com/lab-testing/', 'purepeptidescanada.com' ) );
        $this->assertFalse( Url::belongsTo( 'https://notpurepeptidescanada.com/', 'purepeptidescanada.com' ) );
        $this->assertTrue( Url::looksLikeHtml( 'https://a.test/page' ) );
        $this->assertFalse( Url::looksLikeHtml( 'https://a.test/file.pdf' ) );
        $this->assertTrue( Url::isCanadianTld( 'https://biotech.ca/' ) );
        $this->assertSame( sha1( 'https://a.test/' ), Url::hash( 'a.test' ) );
    }
}
