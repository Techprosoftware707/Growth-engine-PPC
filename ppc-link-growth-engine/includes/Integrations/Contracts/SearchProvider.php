<?php
/**
 * Contract for web search providers used by discovery.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations\Contracts;

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
