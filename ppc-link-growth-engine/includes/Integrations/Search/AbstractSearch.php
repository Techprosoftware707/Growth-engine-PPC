<?php
/**
 * Shared quota tracking for search providers.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations\Search;

use PPC\LinkGrowth\Integrations\Contracts\SearchProvider;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Support\Settings;

abstract class AbstractSearch implements SearchProvider {

    public const OPTION = 'ppclg_search_quota';

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

    protected function fail( string $message ): array {
        $this->lastError = $message;
        $this->logger->warning( 'search:' . $this->id(), $message );
        update_option( 'ppclg_provider_error_' . $this->id(), [ 'message' => $message, 'at' => gmdate( 'c' ) ], false );
        return [];
    }

    protected function ok(): void {
        $this->lastError = '';
        delete_option( 'ppclg_provider_error_' . $this->id() );
    }
}
