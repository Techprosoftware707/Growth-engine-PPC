<?php
/**
 * /growth — the one-click growth run ("Grow now"): start, advance one step batch, status and cancel.
 *
 * The dashboard calls POST /growth/step in a loop until the run is no longer running; each call runs as many steps
 * as fit in settings growth.step_seconds (20 s, capped to 25 s so a request stays well inside PHP and proxy time
 * limits). Every response is the run state plus `ok` and a plain `message`.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Admin\Rest;

use PPC\LinkGrowth\Automation\Autopilot;
use PPC\LinkGrowth\Automation\LoadGuard;
use PPC\LinkGrowth\Growth\GrowthRun;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use Throwable;
use WP_Error;
use WP_REST_Request;

final class GrowthController extends Controller {

    /** Default seconds per POST /growth/step (settings growth.step_seconds). */
    public const DEFAULT_STEP_SECONDS = 20;

    /** Hard ceiling per request. */
    public const MAX_STEP_SECONDS = 25;

    /** Floor per request (enough for one step to start). */
    public const MIN_STEP_SECONDS = 8;

    public function register( string $namespace ): void {
        $this->route( $namespace, '/growth/start', 'POST', [ $this, 'start' ] );
        $this->route( $namespace, '/growth/step', 'POST', [ $this, 'step' ] );
        $this->route( $namespace, '/growth/status', 'GET', [ $this, 'status' ] );
        $this->route( $namespace, '/growth/cancel', 'POST', [ $this, 'cancel' ] );
    }

    /** POST /growth/start — starts a run, or returns the one already in progress. @return array<string,mixed>|WP_Error */
    public function start( WP_REST_Request $request ): array|WP_Error {
        unset( $request );
        $run = $this->growth();
        if ( $run instanceof WP_Error ) {
            return $run;
        }
        $wasActive = $run->isActive();
        $state     = $run->start( $this->userId() );
        $running   = GrowthRun::STATUS_RUNNING === ( $state['status'] ?? '' );

        $state['ok']              = $running;
        $state['already_running'] = $wasActive && $running;
        if ( $wasActive && $running ) {
            $state['message'] = 'A growth run is already in progress; showing its progress.';
        } elseif ( $running ) {
            $state['message'] = 'Growth run started.';
            // While the server is under pressure the steps wait; say so at once instead of after the first step.
            $paused = $this->safe( fn(): ?array => $this->c->get( LoadGuard::class )->pauseReason(), null, 'load guard' );
            if ( is_array( $paused ) ) {
                $state['message'] .= ' ' . (string) $paused['text'];
                $state['paused']   = $paused;
            }
        } else {
            $state['message'] = '' !== (string) ( $state['note'] ?? '' ) ? (string) $state['note'] : 'The growth run could not be started. Try again in a moment.';
        }
        return $state;
    }

    /** POST /growth/step — advances the active run by one time budget. @return array<string,mixed>|WP_Error */
    public function step( WP_REST_Request $request ): array|WP_Error {
        unset( $request );
        $run = $this->growth();
        if ( $run instanceof WP_Error ) {
            return $run;
        }
        if ( ! $run->isActive() ) {
            $state            = $run->state();
            $state['ok']      = false;
            $state['message'] = GrowthRun::STATUS_IDLE === ( $state['status'] ?? '' ) ? 'No growth run has been started yet.' : 'This growth run is no longer running.';
            return $state;
        }
        $state            = $run->advance( TimeBudget::seconds( (float) $this->stepSeconds() ) );
        $state['ok']      = true;
        $state['message'] = ! empty( $state['waiting'] ) ? (string) $state['note'] : '';
        return $state;
    }

    /** GET /growth/status — the active or last run. @return array<string,mixed>|WP_Error */
    public function status( WP_REST_Request $request ): array|WP_Error {
        unset( $request );
        $run = $this->growth();
        if ( $run instanceof WP_Error ) {
            return $run;
        }
        $state                 = $run->state();
        $state['step_seconds'] = $this->stepSeconds();
        $state['policy']       = $this->policy();
        return $state;
    }

    /** POST /growth/cancel — stops the active run; work already done is kept. @return array<string,mixed>|WP_Error */
    public function cancel( WP_REST_Request $request ): array|WP_Error {
        unset( $request );
        $run = $this->growth();
        if ( $run instanceof WP_Error ) {
            return $run;
        }
        if ( ! $run->isActive() ) {
            $state            = $run->state();
            $state['ok']      = false;
            $state['message'] = 'No growth run is in progress.';
            return $state;
        }
        $state            = $run->cancel( $this->userId() );
        $cancelled        = GrowthRun::STATUS_CANCELLED === ( $state['status'] ?? '' );
        $jobs             = (int) ( $state['cancelled_jobs'] ?? 0 );
        $state['ok']      = $cancelled;
        $state['message'] = $cancelled
            ? 'Growth run stopped. Work already done is kept' . ( $jobs > 0 ? sprintf( '; %d queued follow-up %s of this run %s cancelled.', $jobs, 1 === $jobs ? 'job' : 'jobs', 1 === $jobs ? 'was' : 'were' ) : '.' )
            : 'The growth run could not be stopped. Try again in a moment.';
        return $state;
    }

    /**
     * The settings that decide what a run may send on its own, so the panel can say so accurately: the effective
     * values from Automation\Autopilot (Autopilot on raises them; otherwise automation.submissions + submission_mode
     * for listings and automation.outreach_autosend + outreach.require_approval for emails). Values that cannot be
     * read are null (the panel then makes no promise either way).
     *
     * @return array{autopilot:?bool,submissions:?bool,submission_mode:?string,outreach_autosend:?bool,require_approval:?bool,auto_links:?bool,links_per_day:?int}
     */
    private function policy(): array {
        $out = [ 'autopilot' => null, 'submissions' => null, 'submission_mode' => null, 'outreach_autosend' => null, 'require_approval' => null, 'auto_links' => null, 'links_per_day' => null ];
        try {
            $autopilot                = $this->c->get( Autopilot::class );
            $out['autopilot']         = $autopilot->isOn();
            $out['submissions']       = $autopilot->submissionsEnabled();
            $out['submission_mode']   = $autopilot->submissionMode();
            $out['outreach_autosend'] = $autopilot->outreachAutosend();
            $out['require_approval']  = $autopilot->requireApproval();
            $out['auto_links']        = (bool) $this->c->get( Settings::class )->get( 'seo.internal_links', true ) && $autopilot->autoApplyLinks();
            $out['links_per_day']     = $autopilot->linksPerDay();
        } catch ( Throwable $e ) {
            unset( $e );
        }
        return $out;
    }

    /** settings growth.step_seconds, clamped to [MIN_STEP_SECONDS, MAX_STEP_SECONDS]. */
    private function stepSeconds(): int {
        $seconds = self::DEFAULT_STEP_SECONDS;
        try {
            $seconds = (int) $this->c->get( Settings::class )->get( 'growth.step_seconds', self::DEFAULT_STEP_SECONDS );
        } catch ( Throwable $e ) {
            unset( $e );
        }
        if ( $seconds <= 0 ) {
            $seconds = self::DEFAULT_STEP_SECONDS;
        }
        return max( self::MIN_STEP_SECONDS, min( self::MAX_STEP_SECONDS, $seconds ) );
    }

    private function growth(): GrowthRun|WP_Error {
        try {
            $run = $this->c->get( GrowthRun::class );
            if ( $run instanceof GrowthRun ) {
                return $run;
            }
            throw new \RuntimeException( 'unexpected service type' );
        } catch ( Throwable $e ) {
            $message = $e->getMessage();
            $this->safe( fn() => $this->logger()->error( 'growth', 'Growth run unavailable: ' . $message ), null, 'growth log' );
            return $this->fail( 'ppclg_growth_unavailable', 'The growth run is not available. Check the plugin logs.', 503 );
        }
    }
}
