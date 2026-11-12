<?php
/**
 * Job: submit new and changed public URLs to IndexNow (first run: every public, indexable URL).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Seo\IndexNow;
use PPC\LinkGrowth\Support\TimeBudget;

final class IndexNowJob extends AbstractJob {

    public static function type(): string {
        return 'indexnow';
    }

    public static function priority(): int {
        return 6;
    }

    /**
     * @param array<string,mixed> $payload { full?: bool }
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        if ( ! class_exists( IndexNow::class ) ) {
            return $this->skipped( 'not_available', [ 'summary' => 'IndexNow is not available in this installation.', 'submitted' => 0, 'pending' => 0, 'more' => false ] );
        }
        if ( ! $budget->has( 5.0 ) ) {
            return $this->deferred( $c, $payload, 'time_budget', [ 'summary' => 'Not enough time left in this run; IndexNow submits on the next tick.', 'submitted' => 0, 'pending' => 0, 'more' => true ] );
        }
        $res = $c->get( IndexNow::class )->run( $budget, [ 'full' => ! empty( $payload['full'] ) ] );
        $out = [
            'summary'    => (string) $res['summary'],
            'submitted'  => (int) $res['submitted'],
            'candidates' => (int) $res['candidates'],
            'pending'    => (int) $res['pending'],
            'http_status' => (int) $res['status'],
            'more'       => (bool) $res['more'],
        ];
        if ( ! empty( $res['skipped'] ) ) {
            return $this->skipped( (string) $res['reason'], $out );
        }
        if ( empty( $res['ok'] ) ) {
            // A refusal (400/403/422/429 or a network error) is reported, not thrown: the URLs stay pending and
            // retrying the job right away would only hit the endpoint again.
            return $this->result( array_merge( $out, [ 'ok' => false, 'reason' => (string) $res['reason'], 'more' => false ] ) );
        }
        if ( $out['more'] ) {
            unset( $payload['full'] );
            $out['continued'] = null !== $this->continueLater( $c, $payload, 60 );
        }
        return $this->result( $out );
    }
}
