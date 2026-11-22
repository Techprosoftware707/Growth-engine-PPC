<?php
/**
 * Shared quota tracking for search providers.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Integrations\Search;

use LinkGrowth\Autopilot\Integrations\Contracts\SearchProvider;
use LinkGrowth\Autopilot\Support\Http\Client;
use LinkGrowth\Autopilot\Support\Http\Response;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Secrets;
use LinkGrowth\Autopilot\Support\Settings;

abstract class AbstractSearch implements SearchProvider {

    public const OPTION = 'lga_search_quota';

    protected string $lastError = '';

    public function __construct(
        protected Secrets $secrets,
        protected Settings $settings,
        protected Client $client,
        protected Logger $logger
    ) {}

    public function lastError(): string {
        return $this->lastError;
    }

    abstract protected function dailyLimit(): int;

    public function usedToday(): int {
        $q = get_option( self::OPTION, [] );
        if ( ! is_array( $q ) || ( $q['date'] ?? '' ) !== gmdate( 'Y-m-d' ) ) {
            return 0;
        }
        return (int) ( $q[ $this->id() ] ?? 0 );
    }

    public function remainingToday(): int {
        return max( 0, $this->dailyLimit() - $this->usedToday() );
    }

    protected function consume(): void {
        $q = get_option( self::OPTION, [] );
        if ( ! is_array( $q ) || ( $q['date'] ?? '' ) !== gmdate( 'Y-m-d' ) ) {
            $q = [ 'date' => gmdate( 'Y-m-d' ) ];
        }
        $q[ $this->id() ] = (int) ( $q[ $this->id() ] ?? 0 ) + 1;
        update_option( self::OPTION, $q, false );
    }

    /**
     * Gives back the query consume() reserved, for a request the provider refused before running it (see
     * rejectedCredentials()). Nothing is given back once the day has rolled over.
     */
    protected function refund(): void {
        $q = get_option( self::OPTION, [] );
        if ( ! is_array( $q ) || ( $q['date'] ?? '' ) !== gmdate( 'Y-m-d' ) ) {
            return;
        }
        $q[ $this->id() ] = max( 0, (int) ( $q[ $this->id() ] ?? 0 ) - 1 );
        update_option( self::OPTION, $q, false );
    }

    /**
     * Whether the provider refused the request because of the API key (401, or 403 that is not about a quota
     * or rate limit). Such a request ran no search, so it does not count against the daily limit.
     */
    protected function rejectedCredentials( Response $r ): bool {
        if ( null !== $r->error ) {
            return false;
        }
        if ( 401 === $r->status ) {
            return true;
        }
        return 403 === $r->status && ! preg_match( '/quota|limit|exceeded|too many|credits/i', $r->body );
    }

    protected function fail( string $message ): array {
        $this->lastError = $message;
        if ( $this->client->lastWasDeadline() ) {
            // The job's time ran out (the request was not sent, or our shortened timeout ended it): the query gets its
            // quota back, no provider failure is recorded, and lastError stays set so the caller retries the query.
            $this->refund();
            $this->logger->debug( 'search:' . $this->id(), 'Not asked: the time budget of this run is used up.' );
            return [];
        }
        $this->logger->warning( 'search:' . $this->id(), $message );
        update_option( 'lga_provider_error_' . $this->id(), [ 'message' => $message, 'at' => gmdate( 'c' ) ], false );
        return [];
    }

    protected function ok(): void {
        $this->lastError = '';
        delete_option( 'lga_provider_error_' . $this->id() );
    }
}
