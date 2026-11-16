<?php
/**
 * Job: refresh internal-link suggestions. Without automatic links (the default) nothing is applied by this job; links
 * are added solely when an admin clicks "Apply" on the On-site SEO page. With seo.auto_apply_links or Autopilot on,
 * the job then applies the best pending suggestions (InternalLinker::autoApply(): highest relevance first, at most
 * seo.auto_apply_per_day a day, same safeguards as a click, a revision before each change, and only on items whose
 * public page shows the words, see Seo\RenderCheck). When that stops for time or for the page-check limit with
 * links left for today, the job continues AUTO_CONTINUE_SECONDS later with payload auto_only, which skips the
 * suggestion refresh while the list is fresh (refreshed within the last day) and only applies.
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

    /** Pause before the next automatic-links round of the same day (gentle on small hosts). */
    public const AUTO_CONTINUE_SECONDS = 900;

    /** A list refreshed within this many seconds is fresh enough for an auto_only round. */
    private const FRESH_SECONDS = 86400;

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
        $linker   = $c->get( InternalLinker::class );
        $at       = (string) ( $linker->generatedAt() ?? '' );
        $autoOnly = ! empty( $payload['auto_only'] ) && empty( $payload['restart'] ) && $linker->autoApplyEnabled() && empty( $linker->progress()['running'] )
            && '' !== $at && time() - (int) strtotime( $at ) < self::FRESH_SECONDS;
        $res      = $autoOnly
            ? [ 'ok' => true, 'skipped' => false, 'reason' => 'auto_only', 'checked' => 0, 'total' => 0, 'targets' => 0, 'suggested' => 0, 'pending' => $linker->pendingCount(), 'more' => false, 'summary' => 'Suggestions are up to date.' ]
            : $linker->run( $budget, [ 'restart' => ! empty( $payload['restart'] ), 'manual' => $this->isManual( $payload ) ] );
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
        $autoMore = false;
        if ( $linker->autoApplyEnabled() && $budget->has( 3.0 ) ) {
            $auto               = $linker->autoApply( $budget );
            $out['applied']     = (int) $auto['applied'];
            $out['pending']     = $linker->pendingCount();
            $out['auto_today']  = (int) $auto['today'];
            $out['not_shown']   = (int) ( $auto['not_shown'] ?? 0 );
            $out['removed']     = (int) ( $auto['removed'] ?? 0 );
            $out['page_checks'] = (int) ( $auto['fetches'] ?? 0 );
            $autoMore           = ! empty( $auto['more'] );
            if ( '' !== $auto['message'] ) {
                $out['summary'] = trim( $out['summary'] . ' ' . $auto['message'] );
            }
        } elseif ( $linker->autoApplyEnabled() ) {
            $autoMore = true; // no time left for the automatic links after the refresh
        }
        if ( $out['more'] ) {
            unset( $payload['restart'] );
            $out['continued'] = null !== $this->continueLater( $c, $payload, 0 );
        } elseif ( $autoMore && '' === (string) ( $payload['growth_run'] ?? '' ) ) {
            // Links are left for today and the run stopped for time or the page-check limit: another round later.
            // (Not from a Grow now step, which would run it at once: the scheduled job keeps the pause.)
            unset( $payload['restart'] );
            $out['continued'] = null !== $this->continueLater( $c, array_merge( $payload, [ 'auto_only' => true ] ), self::AUTO_CONTINUE_SECONDS );
        }
        return $this->result( $out );
    }
}
