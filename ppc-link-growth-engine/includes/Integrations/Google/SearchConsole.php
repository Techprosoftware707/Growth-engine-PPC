<?php
/**
 * Google Search Console API (service account).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations\Google;

use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;

final class SearchConsole {

    private string $lastError = '';

    public function __construct( private ServiceAccount $sa, private Client $client, private Settings $settings, private Logger $logger ) {}

    public function isConfigured(): bool {
        return $this->sa->isConfigured() && '' !== $this->property();
    }

    public function lastError(): string {
        return $this->lastError ?: $this->sa->lastError();
    }

    public function property(): string {
        return (string) $this->settings->get( 'providers.google.gsc_property', '' );
    }

    /** @return array<int,array{siteUrl:string,permissionLevel:string}> */
    public function sites(): array {
        $json = $this->get( 'https://www.googleapis.com/webmasters/v3/sites' );
        return array_values( array_map( static fn( $s ) => [ 'siteUrl' => (string) ( $s['siteUrl'] ?? '' ), 'permissionLevel' => (string) ( $s['permissionLevel'] ?? '' ) ], (array) ( $json['siteEntry'] ?? [] ) ) );
    }

    /**
     * @param string[] $dimensions
     * @return array<int,array{keys:array<int,string>,clicks:float,impressions:float,ctr:float,position:float}>
     */
    public function searchAnalytics( string $startDate, string $endDate, array $dimensions = [], int $rowLimit = 100, array $filters = [] ): array {
        $body = [
            'startDate'  => $startDate,
            'endDate'    => $endDate,
            'dimensions' => array_values( $dimensions ),
            'rowLimit'   => max( 1, min( 25000, $rowLimit ) ),
        ];
        if ( ! empty( $filters ) ) {
            $body['dimensionFilterGroups'] = [ [ 'filters' => $filters ] ];
        }
        $url  = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $this->property() ) . '/searchanalytics/query';
        $json = $this->post( $url, $body );
        $rows = [];
        foreach ( (array) ( $json['rows'] ?? [] ) as $r ) {
            $rows[] = [
                'keys'        => array_map( 'strval', (array) ( $r['keys'] ?? [] ) ),
                'clicks'      => (float) ( $r['clicks'] ?? 0 ),
                'impressions' => (float) ( $r['impressions'] ?? 0 ),
                'ctr'         => (float) ( $r['ctr'] ?? 0 ),
                'position'    => (float) ( $r['position'] ?? 0 ),
            ];
        }
        return $rows;
    }

    /** @return array{clicks:float,impressions:float,ctr:float,position:float}|null */
    public function totals( int $days = 28 ): ?array {
        $rows = $this->searchAnalytics( gmdate( 'Y-m-d', time() - ( $days + 2 ) * DAY_IN_SECONDS ), gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ), [], 1 );
        if ( empty( $rows ) ) {
            return '' === $this->lastError ? [ 'clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0 ] : null;
        }
        $r = $rows[0];
        unset( $r['keys'] );
        return $r;
    }

    /** @return array<string,mixed>|null */
    public function inspectUrl( string $url ): ?array {
        $json = $this->post( 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [ 'inspectionUrl' => $url, 'siteUrl' => $this->property() ] );
        return isset( $json['inspectionResult'] ) ? (array) $json['inspectionResult'] : null;
    }

    /** @return array<mixed> */
    private function get( string $url ): array {
        $token = $this->sa->accessToken();
        if ( '' === $token ) {
            $this->lastError = $this->sa->lastError();
            return [];
        }
        $r = $this->client->api( 'GET', $url, [ 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ] );
        return $this->handle( $r );
    }

    /** @return array<mixed> */
    private function post( string $url, array $body ): array {
        $token = $this->sa->accessToken();
        if ( '' === $token ) {
            $this->lastError = $this->sa->lastError();
            return [];
        }
        $r = $this->client->api( 'POST', $url, [ 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ], $body );
        return $this->handle( $r );
    }

    /** @return array<mixed> */
    private function handle( \PPC\LinkGrowth\Support\Http\Response $r ): array {
        if ( ! $r->ok() ) {
            $this->lastError = 'Search Console API: ' . ( $r->error ?: 'HTTP ' . $r->status . ' ' . mb_substr( (string) ( $r->json()['error']['message'] ?? '' ), 0, 200 ) );
            $this->logger->warning( 'google', $this->lastError );
            return [];
        }
        $this->lastError = '';
        return $r->json();
    }
}
