<?php
/**
 * Contract for web search providers used by discovery.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Integrations\Contracts;

interface SearchProvider {

    public function id(): string;

    public function label(): string;

    public function isConfigured(): bool;

    public function remainingToday(): int;

    /**
     * @param array{num?:int,country?:string,lang?:string,start?:int} $opts
     * @return array<int,array{url:string,title:string,snippet:string,position:int}>
     */
    public function search( string $query, array $opts = [] ): array;

    public function lastError(): string;
}
