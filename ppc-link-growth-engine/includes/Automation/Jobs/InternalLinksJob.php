<?php
/**
 * Job: refresh internal-link suggestions. Suggestions only: nothing is ever applied by this job; links are added
 * solely when an admin clicks "Apply" on the On-site SEO page.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Seo\InternalLinker;
use PPC\LinkGrowth\Support\TimeBudget;

final class InternalLinksJob extends AbstractJob {

    public static function type(): string {
        return 'internal_links';
    }

    public static function priority(): int {
        return 7;
    }

    /**
     * @param array<string,mixed> $payload { restart?: bool }
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        if ( ! class_exists( InternalLinker::class ) ) {
            return $this->skipped( 'not_available', [ 'summary' => 'Internal-link suggestions are not available in this installation.', 'checked' => 0, 'suggested' => 0, 'pending' => 0, 'more' => false ] );
        }
        if ( ! $budget->has( 3.0 ) ) {
            return $this->deferred( $c, $payload, 'time_budget', [ 'summary' => 'Not enough time left in this run; link suggestions continue on the next tick.', 'checked' => 0, 'suggested' => 0, 'pending' => 0, 'more' => true ] );
        }
        $res = $c->get( InternalLinker::class )->run( $budget, [ 'restart' => ! empty( $payload['restart'] ), 'manual' => $this->isManual( $payload ) ] );
        $out = [
            'summary'   => (string) $res['summary'],
            'checked'   => (int) $res['checked'],
            'total'     => (int) $res['total'],
            'targets'   => (int) $res['targets'],
            'suggested' => (int) $res['suggested'],
            'pending'   => (int) $res['pending'],
            'applied'   => 0,
            'more'      => (bool) $res['more'],
        ];
        if ( ! empty( $res['skipped'] ) ) {
            return $this->skipped( (string) $res['reason'], array_merge( $out, [ 'more' => false ] ) );
        }
        if ( $out['more'] ) {
            unset( $payload['restart'] );
            $out['continued'] = null !== $this->continueLater( $c, $payload, 0 );
        }
        return $this->result( $out );
    }
}
