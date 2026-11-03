<?php
/**
 * Contract for domain-metrics providers (SE Ranking, Ahrefs, Semrush, Moz…).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations\Contracts;

interface MetricsProvider {

    public function id(): string;

    public function label(): string;

    public function isEnabled(): bool;

    public function isConfigured(): bool;

    /**
     * Normalised metrics for a domain or null when unavailable.
     *
     * Keys (all optional): authority (0-100), authority_label, organic_traffic, organic_keywords,
     * referring_domains, backlinks, dofollow_backlinks, spam_score, raw.
     *
     * @return array<string,mixed>|null
     */
    public function domainMetrics( string $domain ): ?array;

    /** Last error message (for the status UI), empty when none. */
    public function lastError(): string;
}
