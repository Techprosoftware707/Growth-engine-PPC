<?php
declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Support;

use PPC\LinkGrowth\Support\Http\Response;
use PPC\LinkGrowth\Support\Http\RobotsTxt;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class RobotsTxtTest extends TestCase {

    public function testParseAndEvaluateLongestMatch(): void {
        $parsed = RobotsTxt::parse( "User-agent: *\nDisallow: /private/\nAllow: /private/public\nDisallow: /*.pdf$\nCrawl-delay: 5\n\nUser-agent: PPCLinkGrowthBot\nDisallow: /nobots/\n" );
        $star = $parsed['rules']['*'];
        $this->assertTrue( RobotsTxt::evaluate( $star, '/' ) );
        $this->assertFalse( RobotsTxt::evaluate( $star, '/private/x' ) );
        $this->assertTrue( RobotsTxt::evaluate( $star, '/private/public/x' ) );
        $this->assertFalse( RobotsTxt::evaluate( $star, '/docs/file.pdf' ) );
        $this->assertTrue( RobotsTxt::evaluate( $star, '/docs/file.pdfx' ) );
        $this->assertArrayHasKey( RobotsTxt::BOT_TOKEN, $parsed['rules'] );
        $this->assertFalse( RobotsTxt::evaluate( $parsed['rules'][ RobotsTxt::BOT_TOKEN ], '/nobots/a' ) );
        $this->assertSame( 5.0, $parsed['delay'] );
    }

    public function testIsAllowedUsesSpecificGroupAndTreats5xxAsDisallowed(): void {
        $robots = Container::get( RobotsTxt::class );
        $body   = "User-agent: *\nDisallow: /\n\nUser-agent: PPCLinkGrowthBot\nDisallow: /admin/\n";
        $fetch  = static fn( string $u ) => new Response( 200, [ 'content-type' => 'text/plain' ], $body, $u, $u, 0.01 );
        $this->assertTrue( $robots->isAllowed( 'https://a.test/page', $fetch ) );
        $this->assertFalse( $robots->isAllowed( 'https://a.test/admin/x', $fetch ) );
        $down = static fn( string $u ) => new Response( 503, [], '', $u, $u, 0.01 );
        $this->assertFalse( $robots->isAllowed( 'https://b.test/page', $down ) );
        $missing = static fn( string $u ) => new Response( 404, [], '', $u, $u, 0.01 );
        $this->assertTrue( $robots->isAllowed( 'https://c.test/page', $missing ) );
    }
}
