<?php
/**
 * Google Custom Search JSON API (Programmable Search Engine). 100 free queries/day.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations\Search;

use PPC\LinkGrowth\Support\Http\Response;

final class GoogleCustomSearch extends AbstractSearch {

    public function id(): string {
        return 'google_cse';
    }

    public function label(): string {
        return 'Google Programmable Search';
    }

    public function isConfigured(): bool {
        return $this->secrets->has( 'google_cse_api_key' ) && $this->secrets->has( 'google_cse_cx' );
    }

    protected function dailyLimit(): int {
        return (int) $this->settings->get( 'providers.search.google_cse_daily_limit', 90 );
    }

    /** Google APIs answer an invalid key with 400 "API key not valid" (API_KEY_INVALID). */
    protected function rejectedCredentials( Response $r ): bool {
        return parent::rejectedCredentials( $r ) || ( null === $r->error && 400 === $r->status && 1 === preg_match( '/API_KEY_INVALID|API key not valid|keyInvalid/i', $r->body ) );
    }

    public function search( string $query, array $opts = [] ): array {
        if ( ! $this->isConfigured() || $this->remainingToday() <= 0 ) {
            return [];
        }
        $params = [
            'key'   => $this->secrets->get( 'google_cse_api_key' ),
            'cx'    => $this->secrets->get( 'google_cse_cx' ),
            'q'     => $query,
            'num'   => max( 1, min( 10, (int) ( $opts['num'] ?? 10 ) ) ),
            'gl'    => strtolower( (string) ( $opts['country'] ?? 'ca' ) ),
            'hl'    => (string) ( $opts['lang'] ?? 'en' ),
            'start' => max( 1, (int) ( $opts['start'] ?? 1 ) ),
        ];
        $this->consume();
        $r = $this->client->api( 'GET', 'https://www.googleapis.com/customsearch/v1?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) );
        if ( ! $r->ok() ) {
            if ( $this->rejectedCredentials( $r ) ) {
                $this->refund();
            }
            return $this->fail( 'Google CSE error: ' . ( $r->error ?: 'HTTP ' . $r->status . ' ' . mb_substr( (string) ( $r->json()['error']['message'] ?? '' ), 0, 160 ) ) );
        }
        $this->ok();
        $out = [];
        foreach ( (array) ( $r->json()['items'] ?? [] ) as $i => $item ) {
            if ( empty( $item['link'] ) ) {
                continue;
            }
            $out[] = [ 'url' => (string) $item['link'], 'title' => (string) ( $item['title'] ?? '' ), 'snippet' => (string) ( $item['snippet'] ?? '' ), 'position' => $params['start'] + (int) $i ];
        }
        return $out;
    }
}
