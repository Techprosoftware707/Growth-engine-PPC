<?php
/**
 * Serper.dev Google SERP API.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations\Search;

final class Serper extends AbstractSearch {

    public function id(): string {
        return 'serper';
    }

    public function label(): string {
        return 'Serper.dev (Google SERP)';
    }

    public function isConfigured(): bool {
        return $this->secrets->has( 'serper_api_key' );
    }

    protected function dailyLimit(): int {
        return (int) $this->settings->get( 'providers.search.serper_daily_limit', 100 );
    }

    public function search( string $query, array $opts = [] ): array {
        if ( ! $this->isConfigured() || $this->remainingToday() <= 0 ) {
            return [];
        }
        $body = [
            'q'    => $query,
            'gl'   => strtolower( (string) ( $opts['country'] ?? 'ca' ) ),
            'hl'   => (string) ( $opts['lang'] ?? 'en' ),
            'num'  => max( 1, min( 20, (int) ( $opts['num'] ?? 10 ) ) ),
            'page' => max( 1, (int) ceil( (int) ( $opts['start'] ?? 1 ) / 10 ) ),
        ];
        $this->consume();
        $r = $this->client->api( 'POST', 'https://google.serper.dev/search', [ 'X-API-KEY' => $this->secrets->get( 'serper_api_key' ), 'Accept' => 'application/json' ], $body );
        if ( ! $r->ok() ) {
            if ( $this->rejectedCredentials( $r ) ) {
                $this->refund();
            }
            return $this->fail( 'Serper error: ' . ( $r->error ?: 'HTTP ' . $r->status ) );
        }
        $this->ok();
        $out = [];
        foreach ( (array) ( $r->json()['organic'] ?? [] ) as $i => $item ) {
            if ( empty( $item['link'] ) ) {
                continue;
            }
            $out[] = [ 'url' => (string) $item['link'], 'title' => (string) ( $item['title'] ?? '' ), 'snippet' => (string) ( $item['snippet'] ?? '' ), 'position' => (int) ( $item['position'] ?? $i + 1 ) ];
        }
        return $out;
    }
}
