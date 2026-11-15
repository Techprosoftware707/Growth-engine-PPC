<?php
/**
 * Job: refresh internal-link suggestions. Without automatic links (the default) nothing is applied by this job; links
 * are added solely when an admin clicks "Apply" on the On-site SEO page. With seo.auto_apply_links or Autopilot on,
 * the job then applies the best pending suggestions (InternalLinker::autoApply(): highest relevance first, at most
 * seo.auto_apply_per_day a day, same safeguards as a click, a revision before each change).
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
        $linker = $c->get( InternalLinker::class );
        $res    = $linker->run( $budget, [ 'restart' => ! empty( $payload['restart'] ), 'manual' => $this->isManual( $payload ) ] );
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
        if ( $linker->autoApplyEnabled() && $budget->has( 3.0 ) ) {
            $auto             = $linker->autoApply( $budget );
            $out['applied']   = (int) $auto['applied'];
            $out['pending']   = $linker->pendingCount();
            $out['auto_today'] = (int) $auto['today'];
            if ( '' !== $auto['message'] ) {
                $out['summary'] = trim( $out['summary'] . ' ' . $auto['message'] );
            }
        }
        if ( $out['more'] ) {
            unset( $payload['restart'] );
            $out['continued'] = null !== $this->continueLater( $c, $payload, 0 );
        }
        return $this->result( $out );
    }
}
