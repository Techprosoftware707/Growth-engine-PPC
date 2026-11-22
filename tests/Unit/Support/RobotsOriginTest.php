<?php
/**
 * 1.2.4: robots.txt is asked from the page's own origin, port included (before, the site audit's spot check of
 * http://localhost:8791/page asked http://localhost/robots.txt, got "cURL error 7" and could not run).
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Support;

use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Url;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class RobotsOriginTest extends TestCase {

    public function testTheOriginKeepsANonDefaultPortOnly(): void {
        $this->assertSame( 'http://localhost:8791', Url::origin( 'http://localhost:8791/shop/?a=1' ) );
        $this->assertSame( 'https://example.test', Url::origin( 'https://Example.test:443/x' ) );
        $this->assertSame( 'http://example.test', Url::origin( 'http://example.test:80/' ) );
        $this->assertSame( 'https://example.test:8443', Url::origin( 'https://example.test:8443/' ) );
        $this->assertSame( '', Url::origin( '' ) );
    }

    public function testRobotsTxtIsFetchedFromThePagesPort(): void {
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 0 );
        HttpFixtures::add( 'http://localhost:8791/robots.txt', "User-agent: *\nDisallow: /private/\n", 200, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'http://localhost:8791/page/', '<html><body><p>Own page</p></body></html>' );
        HttpFixtures::add( 'http://localhost:8791/private/x/', '<html><body><p>Private</p></body></html>' );
        $client = Container::get( Client::class );
        $this->assertTrue( $client->crawl( 'http://localhost:8791/page/' )->ok() );
        $this->assertSame( 'http://localhost:8791/robots.txt', HttpFixtures::$requests[0]['url'] );
        $this->assertSame( 'robots_disallow', $client->crawl( 'http://localhost:8791/private/x/' )->errorCode, 'its rules apply' );
        $urls = array_map( static fn( array $r ): string => $r['url'], HttpFixtures::$requests );
        $this->assertNotContains( 'http://localhost/robots.txt', $urls );
    }
}
