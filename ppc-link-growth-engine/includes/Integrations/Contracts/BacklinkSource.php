<?php
/**
 * Contract for providers that can list backlinks pointing at a domain.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations\Contracts;

interface BacklinkSource {

    public function id(): string;

    public function isConfigured(): bool;

    /**
     * @return array<int,array{source_url:string,target_url:string,anchor:string,nofollow:bool,first_seen:string,last_seen:string,source_authority:?int}>
     */
    public function backlinks( string $domain, int $limit = 200, int $perDomain = 3 ): array;
}
