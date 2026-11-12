<?php
/**
 * Job: re-run the storefront compliance preflight (database only, no HTTP, never edits the store).
 *
 * A failure is thrown so the runner records the job as failed and retries it; meanwhile the gate in
 * Compliance\StorefrontAudit::isBlocking() fails closed.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Compliance\StorefrontAudit;
use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Support\TimeBudget;

final class StorefrontAuditJob extends AbstractJob {

    public static function type(): string {
        return 'storefront_audit';
    }

    /** Runs before packaging/submission/outreach stages so the gate works from a current result. */
    public static function priority(): int {
        return 2;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        if ( ! $budget->has( 3.0 ) ) {
            return $this->deferred( $c, $payload );
        }
        /** @var StorefrontAudit $audit */
        $audit   = $c->get( StorefrontAudit::class );
        $result  = $audit->run();
        $summary = $audit->summary();

        $counts    = is_array( $result['counts'] ?? null ) ? $result['counts'] : [];
        $scannedBy = is_array( $result['scanned'] ?? null ) ? $result['scanned'] : [];
        $scanned   = 0;
        foreach ( $scannedBy as $n ) {
            $scanned += (int) $n;
        }
        $block = (int) ( $counts['block'] ?? 0 );
        $warn  = (int) ( $counts['warn'] ?? 0 );

        return $this->result(
            [
                'summary'   => self::sentence( (string) $result['status'], (string) $summary['effective'], (bool) $summary['gate'], $scanned, $block, $warn ),
                'status'    => (string) $result['status'],
                'effective' => (string) $summary['effective'],
                'blocking'  => (bool) $summary['blocking'],
                'checked'   => $scanned,
                'block'     => $block,
                'warn'      => $warn,
                'findings'  => $block + $warn,
                'more'      => false,
            ]
        );
    }

    private static function sentence( string $status, string $effective, bool $gate, int $scanned, int $block, int $warn ): string {
        $items = sprintf( '%d storefront %s', $scanned, 1 === $scanned ? 'item' : 'items' );
        if ( StorefrontAudit::STATUS_PASS === $status ) {
            return sprintf( 'Checked %s; no consumer-use wording found.', $items );
        }
        $found = sprintf( '%d blocking and %d to review', $block, $warn );
        if ( StorefrontAudit::EFFECTIVE_ACKNOWLEDGED === $effective ) {
            return sprintf( 'Checked %s; %s, already acknowledged by the owner.', $items, $found );
        }
        if ( StorefrontAudit::STATUS_BLOCK === $status ) {
            return $gate
                ? sprintf( 'Checked %s; %s. Automated submissions and outreach sending are paused until the storefront changes or the owner acknowledges the findings.', $items, $found )
                : sprintf( 'Checked %s; %s. The storefront gate is switched off, so automation is not paused.', $items, $found );
        }
        return sprintf( 'Checked %s; %s (nothing is paused).', $items, $found );
    }
}
