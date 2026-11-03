<?php
declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Integrations;

use PPC\LinkGrowth\Integrations\Google\ServiceAccount;
use PPC\LinkGrowth\Integrations\MetricsAggregator;
use PPC\LinkGrowth\Integrations\ProviderRegistry;
use PPC\LinkGrowth\Integrations\Providers\Semrush;
use PPC\LinkGrowth\Repositories\DomainMetrics;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class ProvidersTest extends TestCase {

    private function json( array $data, int $status = 200 ): array {
        return [ 'response' => [ 'code' => $status, 'message' => 'OK' ], 'headers' => [ 'content-type' => 'application/json' ], 'body' => json_encode( $data ), 'cookies' => [] ];
    }

    public function testSeRankingMapsRealResponseShapes(): void {
        Container::get( Secrets::class )->set( 'seranking_api_key', 'key123' );
        HttpFixtures::$handler = function ( string $url, array $args ) {
            $this->assertSame( 'Token key123', $args['headers']['Authorization'] );
            if ( str_contains( $url, '/backlinks/authority/domain' ) ) {
                return $this->json( [ 'pages' => [ [ 'url' => 'labdirectory.test', 'domain_inlink_rank' => 41 ] ] ] );
            }
            if ( str_contains( $url, '/domain/overview/db' ) ) {
                return $this->json( [ 'organic' => [ 'base_domain' => 'labdirectory.test', 'traffic_sum' => 2350, 'keywords_count' => 640, 'price_sum' => 10 ], 'adv' => [] ] );
            }
            if ( str_contains( $url, '/backlinks/all' ) ) {
                return $this->json( [ 'backlinks' => [ [ 'url_from' => 'https://resources.test/links/', 'url_to' => 'https://labdirectory.test/', 'anchor' => 'LabDirectory', 'nofollow' => false, 'first_seen' => '2026-01-02', 'last_visited' => '2026-10-01', 'domain_inlink_rank' => 33 ] ] ] );
            }
            if ( str_contains( $url, '/account/subscription' ) ) {
                return $this->json( [ 'status' => 'active', 'expiration_date' => '2026-11-11', 'units_limit' => 100000, 'units_left' => 75497 ] );
            }
            return null;
        };
        $se = Container::get( ProviderRegistry::class )->seranking();
        $this->assertTrue( $se->isConfigured() );
        $m = $se->domainMetrics( 'labdirectory.test' );
        $this->assertSame( 41, $m['authority'] );
        $this->assertSame( 2350, $m['organic_traffic'] );
        $this->assertSame( 640, $m['organic_keywords'] );
        $this->assertSame( 'ca', $m['traffic_region'] );
        $bl = $se->backlinks( 'labdirectory.test', 10 );
        $this->assertSame( 'https://resources.test/links/', $bl[0]['source_url'] );
        $this->assertFalse( $bl[0]['nofollow'] );
        $this->assertSame( 33, $bl[0]['source_authority'] );
        $this->assertSame( 75497, $se->subscription()['units_left'] );
    }

    public function testAggregatorCachesAndMerges(): void {
        Container::get( Secrets::class )->set( 'seranking_api_key', 'key123' );
        $calls = 0;
        HttpFixtures::$handler = function ( string $url ) use ( &$calls ) {
            $calls++;
            if ( str_contains( $url, '/backlinks/authority/domain' ) ) {
                return $this->json( [ 'pages' => [ [ 'url' => 'x.test', 'domain_inlink_rank' => 20 ] ] ] );
            }
            if ( str_contains( $url, '/domain/overview/db' ) ) {
                return $this->json( [ 'organic' => [ 'traffic_sum' => 100, 'keywords_count' => 5 ], 'adv' => [] ] );
            }
            return null;
        };
        $agg = Container::get( MetricsAggregator::class );
        $m1  = $agg->metricsFor( 'x.test' );
        $this->assertSame( 20, $m1['authority'] );
        $this->assertSame( [ 'seranking' => 20 ], $m1['authority_scores'] );
        $this->assertSame( 100, $m1['organic_traffic'] );
        $this->assertArrayHasKey( 'ahrefs', $m1['missing'] );
        $before = $calls;
        $m2 = $agg->metricsFor( 'x.test' );
        $this->assertSame( $before, $calls, 'second call served from cache' );
        $this->assertSame( 20, $m2['authority'] );
        $this->assertNotNull( Container::get( DomainMetrics::class )->fresh( 'x.test', 'seranking' ) );
        $merged = MetricsAggregator::merge( [ 'seranking' => [ 'authority' => 10, 'organic_traffic' => 50 ], 'ahrefs' => [ 'authority' => 30, 'organic_traffic' => 500, 'referring_domains' => 12 ] ] );
        $this->assertSame( 20, $merged['authority'] );
        $this->assertSame( 500, $merged['organic_traffic'] );
        $this->assertSame( 'ahrefs', $merged['traffic_source'] );
        $this->assertSame( 12, $merged['referring_domains'] );
        $skipped = $agg->metricsFor( 'y.test', true, static fn() => false );
        $this->assertStringContainsString( 'deferred', $skipped['missing']['seranking'] );
    }

    public function testSemrushCsvParsing(): void {
        $rows = Semrush::parseCsv( "Database;Domain;Rank;Organic Keywords;Organic Traffic;Organic Cost\nca;example.com;1234;567;8900;1200\n" );
        $this->assertSame( '8900', $rows[0]['Organic Traffic'] );
        $this->assertSame( [], Semrush::parseCsv( 'ERROR 120 :: WRONG KEY' ) );
    }

    public function testServiceAccountSignsJwtAndExchangesToken(): void {
        $key = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
        openssl_pkey_export( $key, $pem );
        Container::get( Secrets::class )->set( 'google_service_account_json', json_encode( [ 'client_email' => 'bot@proj.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token' ] ) );
        $captured = null;
        HttpFixtures::$handler = function ( string $url, array $args ) use ( &$captured ) {
            if ( 'https://oauth2.googleapis.com/token' === $url ) {
                parse_str( (string) $args['body'], $captured );
                return $this->json( [ 'access_token' => 'ya29.token', 'expires_in' => 3600 ] );
            }
            return null;
        };
        $sa = Container::get( ServiceAccount::class );
        $this->assertTrue( $sa->isConfigured() );
        $this->assertSame( 'ya29.token', $sa->accessToken() );
        $this->assertSame( 'urn:ietf:params:oauth:grant-type:jwt-bearer', $captured['grant_type'] );
        [ $h, $c, $s ] = explode( '.', $captured['assertion'] );
        $claims = json_decode( base64_decode( strtr( $c, '-_', '+/' ) ), true );
        $this->assertSame( 'bot@proj.iam.gserviceaccount.com', $claims['iss'] );
        $this->assertStringContainsString( 'webmasters.readonly', $claims['scope'] );
        $this->assertSame( 1, openssl_verify( $h . '.' . $c, base64_decode( strtr( $s, '-_', '+/' ) ), openssl_pkey_get_public( openssl_pkey_get_details( $key )['key'] ), OPENSSL_ALGO_SHA256 ) );
        $this->assertSame( 'ya29.token', $sa->accessToken(), 'cached token' );
    }
}
