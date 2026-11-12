<?php
/**
 * Google Analytics 4 Data API (service account).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations\Google;

use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;

final class Analytics4 {

    /**
     * Search engines and social networks, matched as whole hostname labels (or a leading "search."
     * label) so referring sites that merely contain these letters (trustpilot.com, dropbox.com,
     * research.ca, bingoforum.com, ...) are kept. "google*" also covers Google's ad/app hosts.
     */
    private const SEARCH_ENGINE_SOURCES = '/(?<![a-z0-9-])(google[a-z0-9-]*|bing|yahoo|duckduckgo|baidu|yandex|ecosia|qwant|kagi|startpage|brave)(?![a-z0-9-])|(?<![a-z0-9-])search\./i';
    private const SOCIAL_SOURCES = '/(?<![a-z0-9-])(facebook|instagram|twitter|linkedin|reddit|pinterest|tiktok|youtube|threads|snapchat)(?![a-z0-9-])|(?<![a-z0-9-])(t\.co|x\.com)(?![a-z0-9.-])/i';

    private string $lastError = '';

    public function __construct( private ServiceAccount $sa, private Client $client, private Settings $settings, private Logger $logger ) {}

    public function isConfigured(): bool {
        return $this->sa->isConfigured() && '' !== $this->propertyId();
    }

    public function lastError(): string {
        return $this->lastError ?: $this->sa->lastError();
    }

    public function propertyId(): string {
        return preg_replace( '/\D/', '', (string) $this->settings->get( 'providers.google.ga4_property_id', '' ) ) ?? '';
    }

    /** @return array<mixed> raw RunReportResponse */
    public function runReport( array $body ): array {
        $token = $this->sa->accessToken();
        if ( '' === $token ) {
            $this->lastError = $this->sa->lastError();
            return [];
        }
        $r = $this->client->api( 'POST', 'https://analyticsdata.googleapis.com/v1beta/properties/' . $this->propertyId() . ':runReport', [ 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ], $body );
        if ( ! $r->ok() ) {
            $this->lastError = 'GA4 Data API: ' . ( $r->error ?: 'HTTP ' . $r->status . ' ' . mb_substr( (string) ( $r->json()['error']['message'] ?? '' ), 0, 200 ) );
            $this->logger->warning( 'google', $this->lastError );
            return [];
        }
        $this->lastError = '';
        return $r->json();
    }

    /**
     * Referral traffic by source domain (search engines and social excluded unless $includeAll).
     *
     * @return array<int,array{source:string,sessions:int,users:int}>
     */
    public function referralSources( int $days = 28, bool $includeAll = false ): array {
        $json = $this->runReport(
            [
                'dateRanges'      => [ [ 'startDate' => $days . 'daysAgo', 'endDate' => 'yesterday' ] ],
                'dimensions'      => [ [ 'name' => 'sessionSource' ], [ 'name' => 'sessionMedium' ] ],
                'metrics'         => [ [ 'name' => 'sessions' ], [ 'name' => 'activeUsers' ] ],
                'dimensionFilter' => [ 'filter' => [ 'fieldName' => 'sessionMedium', 'stringFilter' => [ 'matchType' => 'EXACT', 'value' => 'referral' ] ] ],
                'orderBys'        => [ [ 'metric' => [ 'metricName' => 'sessions' ], 'desc' => true ] ],
                'limit'           => 200,
            ]
        );
        return self::parseReferralRows( $json, $includeAll );
    }

    /** @return array<int,array{source:string,sessions:int,users:int}> */
    public static function parseReferralRows( array $json, bool $includeAll = false ): array {
        $out = [];
        foreach ( (array) ( $json['rows'] ?? [] ) as $row ) {
            $source = (string) ( $row['dimensionValues'][0]['value'] ?? '' );
            if ( '' === $source ) {
                continue;
            }
            if ( ! $includeAll && ( preg_match( self::SEARCH_ENGINE_SOURCES, $source ) || preg_match( self::SOCIAL_SOURCES, $source ) || '(direct)' === $source ) ) {
                continue;
            }
            $out[] = [
                'source'   => strtolower( $source ),
                'sessions' => (int) ( $row['metricValues'][0]['value'] ?? 0 ),
                'users'    => (int) ( $row['metricValues'][1]['value'] ?? 0 ),
            ];
        }
        return $out;
    }

    /** @return array{sessions:int,users:int}|null totals of referral medium */
    public function referralTotals( int $days = 28 ): ?array {
        $json = $this->runReport(
            [
                'dateRanges'      => [ [ 'startDate' => $days . 'daysAgo', 'endDate' => 'yesterday' ] ],
                'metrics'         => [ [ 'name' => 'sessions' ], [ 'name' => 'activeUsers' ] ],
                'dimensionFilter' => [ 'filter' => [ 'fieldName' => 'sessionMedium', 'stringFilter' => [ 'matchType' => 'EXACT', 'value' => 'referral' ] ] ],
            ]
        );
        if ( empty( $json ) ) {
            return null;
        }
        $row = $json['rows'][0]['metricValues'] ?? [];
        return [ 'sessions' => (int) ( $row[0]['value'] ?? 0 ), 'users' => (int) ( $row[1]['value'] ?? 0 ) ];
    }
}
