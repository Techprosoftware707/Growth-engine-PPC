<?php
/**
 * Follow-ups the review groups reported for files outside their own scope: listing verification falls back
 * to the next search provider when the first one fails (like discovery and mention scans), and the partner
 * API adapter refuses a site set to "Never automate" even when it is called directly.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\CrossSlice;

use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Submission\Adapters\ConfiguredApiAdapter;
use PPC\LinkGrowth\Submission\ListingVerifier;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class IntegratorFollowupsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 0 );
    }

    public function testListingSearchFallsBackToTheNextProviderWhenTheFirstRejectsItsKey(): void {
        $settings = Container::get( Settings::class );
        $settings->set( 'site.brand_name', 'Pure Peptides Canada' );
        $secrets = Container::get( Secrets::class );
        $secrets->set( 'serper_api_key', 'revoked-key' );
        $secrets->set( 'brave_api_key', 'brave-key' );

        $listing = 'https://labdirectory-fallback.test/suppliers/pure-peptides-canada/';
        HttpFixtures::$handler = static function ( string $url ) use ( $listing ) {
            if ( str_contains( $url, 'serper.dev' ) ) {
                return [ 'response' => [ 'code' => 401, 'message' => 'Unauthorized' ], 'headers' => [ 'content-type' => 'application/json' ], 'body' => '{"message":"Unauthorized"}', 'cookies' => [] ];
            }
            if ( str_contains( $url, 'api.search.brave.com' ) ) {
                return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'headers' => [ 'content-type' => 'application/json' ], 'body' => (string) wp_json_encode( [ 'web' => [ 'results' => [ [ 'url' => $listing, 'title' => 'Pure Peptides Canada', 'description' => '' ] ] ] ] ), 'cookies' => [] ];
            }
            return null;
        };
        HttpFixtures::add( 'https://labdirectory-fallback.test/robots.txt', '', 404, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( $listing, '<html><head><title>Pure Peptides Canada</title></head><body><main><h1>Pure Peptides Canada</h1><p>Research reagent supplier.</p></main></body></html>' );

        $prospect = [ 'id' => 0, 'domain' => 'labdirectory-fallback.test', 'homepage_url' => 'https://labdirectory-fallback.test/', 'name' => 'Lab Directory', 'status' => 'qualified' ];
        Container::get( ListingVerifier::class )->verify( [ 'id' => 0, 'target_url' => 'https://labdirectory-fallback.test/add/', 'payload' => [] ], (array) $prospect );

        $braveCalls = array_filter( HttpFixtures::$requests, static fn( array $r ): bool => str_contains( $r['url'], 'api.search.brave.com' ) );
        $this->assertNotEmpty( $braveCalls, 'the second provider ran the site: query after the first refused its key' );
        $pageCalls = array_filter( HttpFixtures::$requests, static fn( array $r ): bool => $r['url'] === $listing );
        $this->assertNotEmpty( $pageCalls, 'the result page from the fallback provider was checked' );
    }

    public function testPartnerApiAdapterRefusesANeverAutomateSite(): void {
        Container::get( Settings::class )->set( 'automation.submissions', true );
        $adapter  = new ConfiguredApiAdapter( Container::get( \PPC\LinkGrowth\Support\Http\Client::class ), Container::get( Secrets::class ), Container::get( Settings::class ), Container::get( \PPC\LinkGrowth\Support\Logger::class ) );
        $prospect = [
            'id'                 => 7,
            'domain'             => 'partner-never.test',
            'automation_allowed' => Prospects::AUTOMATION_NEVER,
            'submission_config'  => [ 'api' => [ 'endpoint' => 'https://partner-never.test/api/listings', 'method' => 'POST' ] ],
        ];
        $out = $adapter->submit( [ 'id' => 1 ], $prospect, [ 'content' => [ 'description' => 'Research reagent supplier.' ] ] );

        $this->assertSame( 'needs_manual', $out['status'] ?? '' );
        $this->assertStringContainsString( 'Never automate', (string) ( $out['message'] ?? $out['note'] ?? wp_json_encode( $out ) ) );
        $sent = array_filter( HttpFixtures::$requests, static fn( array $r ): bool => str_contains( $r['url'], 'partner-never.test' ) );
        $this->assertSame( [], $sent, 'nothing is sent to the partner' );
    }
}
