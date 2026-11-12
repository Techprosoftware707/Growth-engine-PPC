<?php
/**
 * Brave Search API.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations\Search;

use PPC\LinkGrowth\Support\Http\Response;

final class BraveSearch extends AbstractSearch {

    public function id(): string {
        return 'brave';
    }

    public function label(): string {
        return 'Brave Search';
    }

    public function isConfigured(): bool {
        return $this->secrets->has( 'brave_api_key' );
    }

    protected function dailyLimit(): int {
        return (int) $this->settings->get( 'providers.search.brave_daily_limit', 60 );
    }

    /** Brave also answers an invalid subscription token with 422 SUBSCRIPTION_TOKEN_INVALID. */
    protected function rejectedCredentials( Response $r ): bool {
        return parent::rejectedCredentials( $r ) || ( null === $r->error && str_contains( $r->body, 'SUBSCRIPTION_TOKEN_INVALID' ) );
    }

    public function search( string $query, array $opts = [] ): array {
        if ( ! $this->isConfigured() || $this->remainingToday() <= 0 ) {
            return [];
        }
        $params = [
            'q'           => $query,
            'count'       => max( 1, min( 20, (int) ( $opts['num'] ?? 20 ) ) ),
            'country'     => strtoupper( (string) ( $opts['country'] ?? 'CA' ) ),
            'search_lang' => (string) ( $opts['lang'] ?? 'en' ),
            'offset'      => max( 0, (int) floor( ( (int) ( $opts['start'] ?? 1 ) - 1 ) / 20 ) ),
        ];
        $this->consume();
        $r = $this->client->api( 'GET', 'https://api.search.brave.com/res/v1/web/search?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ), [ 'X-Subscription-Token' => $this->secrets->get( 'brave_api_key' ), 'Accept' => 'application/json' ] );
        if ( ! $r->ok() ) {
            if ( $this->rejectedCredentials( $r ) ) {
                $this->refund();
            }
            return $this->fail( 'Brave Search error: ' . ( $r->error ?: 'HTTP ' . $r->status ) );
        }
        $this->ok();
        $out = [];
        foreach ( (array) ( $r->json()['web']['results'] ?? [] ) as $i => $item ) {
            if ( empty( $item['url'] ) ) {
                continue;
            }
            $out[] = [ 'url' => (string) $item['url'], 'title' => (string) ( $item['title'] ?? '' ), 'snippet' => (string) ( $item['description'] ?? '' ), 'position' => (int) $i + 1 ];
        }
        return $out;
    }
}
