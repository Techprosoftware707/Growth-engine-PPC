<?php
/**
 * Reads Search Console / GA4 numbers through Site Kit by Google's own REST datapoints
 * (admin-request context only; Site Kit owns the Google connection).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations\Google;

use PPC\LinkGrowth\Support\Logger;

final class SiteKitBridge {

    private string $lastError = '';

    public function __construct( private Logger $logger ) {}

    public function isAvailable(): bool {
        return defined( 'GOOGLESITEKIT_VERSION' ) && function_exists( 'rest_do_request' ) && is_user_logged_in();
    }

    public function lastError(): string {
        return $this->lastError;
    }

    /** @return array{clicks:float,impressions:float,ctr:float,position:float}|null */
    public function searchConsoleTotals( int $days = 28 ): ?array {
        $data = $this->request( '/google-site-kit/v1/modules/search-console/data/searchanalytics', [ 'startDate' => gmdate( 'Y-m-d', time() - ( $days + 2 ) * DAY_IN_SECONDS ), 'endDate' => gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ), 'limit' => 1 ] );
        if ( null === $data ) {
            return null;
        }
        $row = $data[0] ?? ( $data['rows'][0] ?? null );
        if ( ! is_array( $row ) ) {
            return [ 'clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0 ];
        }
        return [ 'clicks' => (float) ( $row['clicks'] ?? 0 ), 'impressions' => (float) ( $row['impressions'] ?? 0 ), 'ctr' => (float) ( $row['ctr'] ?? 0 ), 'position' => (float) ( $row['position'] ?? 0 ) ];
    }

    /** @return array<int,array{source:string,sessions:int,users:int}>|null */
    public function referralSources( int $days = 28 ): ?array {
        $data = $this->request(
            '/google-site-kit/v1/modules/analytics-4/data/report',
            [
                'startDate'        => gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ),
                'endDate'          => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
                'metrics'          => [ [ 'name' => 'sessions' ], [ 'name' => 'activeUsers' ] ],
                'dimensions'       => [ [ 'name' => 'sessionSource' ], [ 'name' => 'sessionMedium' ] ],
                'dimensionFilters' => [ 'sessionMedium' => 'referral' ],
                'orderby'          => [ [ 'metric' => [ 'metricName' => 'sessions' ], 'desc' => true ] ],
                'limit'            => 100,
            ]
        );
        if ( null === $data ) {
            return null;
        }
        return Analytics4::parseReferralRows( $data );
    }

    /** @return array<mixed>|null */
    private function request( string $route, array $params ): ?array {
        if ( ! $this->isAvailable() ) {
            $this->lastError = 'Site Kit is not active or no user context.';
            return null;
        }
        try {
            $req = new \WP_REST_Request( 'GET', $route );
            foreach ( $params as $k => $v ) {
                $req->set_param( $k, $v );
            }
            $res = rest_do_request( $req );
            if ( $res->is_error() ) {
                $err = $res->as_error();
                $this->lastError = 'Site Kit: ' . ( $err ? $err->get_error_message() : 'request error' );
                return null;
            }
            $data = $res->get_data();
            $this->lastError = '';
            return is_array( $data ) ? $data : null;
        } catch ( \Throwable $e ) {
            $this->lastError = 'Site Kit: ' . $e->getMessage();
            $this->logger->debug( 'sitekit', $e->getMessage() );
            return null;
        }
    }
}
