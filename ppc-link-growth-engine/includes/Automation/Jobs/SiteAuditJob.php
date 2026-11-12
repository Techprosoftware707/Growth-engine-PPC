<?php
/**
 * Job: on-site SEO audit of published pages, posts and products (cursor based; never edits content).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Seo\SiteAuditor;
use PPC\LinkGrowth\Support\TimeBudget;

final class SiteAuditJob extends AbstractJob {

    public static function type(): string {
        return 'site_audit';
    }

    public static function priority(): int {
        return 6;
    }

    /**
     * @param array<string,mixed> $payload { restart?: bool }
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        if ( ! class_exists( SiteAuditor::class ) ) {
            return $this->skipped( 'not_available', [ 'summary' => 'The site audit is not available in this installation.', 'checked' => 0, 'issues' => 0, 'more' => false ] );
        }
        if ( ! $budget->has( 3.0 ) ) {
            return $this->deferred( $c, $payload, 'time_budget', [ 'summary' => 'Not enough time left in this run; the site audit continues on the next tick.', 'checked' => 0, 'issues' => 0, 'more' => true ] );
        }
        $res = $c->get( SiteAuditor::class )->run( $budget, [ 'restart' => ! empty( $payload['restart'] ) ] );

        $out = [
            'summary' => (string) $res['summary'],
            'checked' => (int) $res['checked'],
            'total'   => (int) $res['total'],
            'issues'  => (int) $res['issues'],
            'high'    => (int) $res['high'],
            'medium'  => (int) $res['medium'],
            'low'     => (int) $res['low'],
            'more'    => (bool) $res['more'],
        ];
        if ( ! empty( $res['busy'] ) ) {
            return $this->skipped( 'audit_running', $out );
        }
        if ( $out['more'] ) {
            // Local database work only: the next batch may start right away.
            unset( $payload['restart'] );
            $out['continued'] = null !== $this->continueLater( $c, $payload, 0 );
        }
        return $this->result( $out );
    }
}
