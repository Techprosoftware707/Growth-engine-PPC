<?php
/**
 * One-click growth run ("Grow now").
 *
 * Runs every growth stage once, in a fixed order, as a resumable state machine: the dashboard calls advance()
 * repeatedly (POST /growth/step) and each call runs as many steps as fit in its time budget. The state is kept in
 * the non-autoloaded option ppclg_growth_run and saved after every step, so a closed browser tab loses nothing:
 * Automation\Pipeline::tick() continues a run nobody advanced for BACKGROUND_AFTER_SECONDS.
 *
 * Every job step runs the existing job through Automation\JobRunner with manual=true, under the runner lock, so
 * all the owner's gates still apply: the hard automation flags (submissions, outreach autosend), the submission
 * Policy (including the storefront compliance gate), robots.txt, the daily limits and the outreach approval rules.
 * The run never changes a setting, never sends outreach (drafting only; sending stays with the approval/autosend
 * gates) and never edits site content (internal links are suggestions applied only by an admin click).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Growth;

use PPC\LinkGrowth\Automation\Audit;
use PPC\LinkGrowth\Automation\JobRunner;
use PPC\LinkGrowth\Automation\Pipeline;
use PPC\LinkGrowth\Automation\Scheduler;
use PPC\LinkGrowth\Compliance\StorefrontAudit;
use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Discovery\VerifiedOpportunities;
use PPC\LinkGrowth\Integrations\Google\SearchConsole;
use PPC\LinkGrowth\Integrations\Google\SiteKitBridge;
use PPC\LinkGrowth\Integrations\ProviderRegistry;
use PPC\LinkGrowth\Outreach\Compliance;
use PPC\LinkGrowth\Plugin;
use PPC\LinkGrowth\Repositories\Backlinks;
use PPC\LinkGrowth\Repositories\Jobs;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Seo\IndexNow;
use PPC\LinkGrowth\Seo\InternalLinker;
use PPC\LinkGrowth\Seo\SiteAuditor;
use PPC\LinkGrowth\Support\Crypto;
use PPC\LinkGrowth\Support\Locks;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use Throwable;

final class GrowthRun {

    /** Run state (non-autoloaded). */
    public const OPTION = 'ppclg_growth_run';

    /** Lock that keeps two advances (dashboard + background tick, or two tabs) from running at once. */
    public const LOCK = 'growth_run';

    /** The scheduler continues a run nobody advanced for longer than this (the dashboard tab was closed). */
    public const BACKGROUND_AFTER_SECONDS = 45;

    /** advance() starts no further step once fewer seconds than this remain. */
    public const MIN_STEP_SECONDS = 6.0;

    public const STATUS_IDLE      = 'idle';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STEP_PENDING = 'pending';
    public const STEP_RUNNING = 'running';
    public const STEP_DONE    = 'done';
    public const STEP_SKIPPED = 'skipped';
    public const STEP_BLOCKED = 'blocked';
    public const STEP_FAILED  = 'failed';

    /** Step statuses that end a step. */
    private const FINISHED = [ self::STEP_DONE, self::STEP_SKIPPED, self::STEP_BLOCKED, self::STEP_FAILED ];

    /**
     * Ordered steps: key => [label, kind]. A "job" step runs the job type named by its key.
     */
    private const STEPS = [
        'preflight'          => [ 'Check the storefront and setup', 'internal' ],
        'import_verified'    => [ 'Import verified opportunities', 'job' ],
        'site_audit'         => [ 'Audit on-site SEO', 'job' ],
        'indexnow'           => [ 'Notify search engines (IndexNow)', 'job' ],
        'internal_links'     => [ 'Suggest internal links (applied only by you)', 'job' ],
        'discover'           => [ 'Discover new prospects', 'job' ],
        'mention_scan'       => [ 'Find unlinked brand mentions', 'job' ],
        'broken_link_scan'   => [ 'Find broken-link opportunities', 'job' ],
        'evaluate'           => [ 'Evaluate prospects', 'job' ],
        'find_opportunities' => [ 'Find link opportunities', 'job' ],
        'package'            => [ 'Prepare listing packages', 'job' ],
        'submit'             => [ 'Submit approved listings', 'job' ],
        'verify_listings'    => [ 'Verify listings', 'job' ],
        'auto_outreach'      => [ 'Draft outreach for your approval', 'job' ],
        'monitor_backlinks'  => [ 'Check existing backlinks', 'job' ],
        'import_backlinks'   => [ 'Import backlinks from providers', 'job' ],
        'metrics_refresh'    => [ 'Refresh domain metrics', 'job' ],
        'summary'            => [ 'Summarise the results', 'internal' ],
    ];

    /**
     * Most job runs per step (first run + continuations); evaluate reads settings growth.evaluate_rounds.
     * import_verified imports batches of 100 local items: 8 rounds cover the 595 bundled items in one run.
     */
    private const CAPS = [ 'site_audit' => 8, 'find_opportunities' => 4, 'import_verified' => 8 ];

    private const DEFAULT_CAP = 3;

    private const DEFAULT_EVALUATE_ROUNDS = 6;

    /** Seconds a job needs to do at least one item (per-item need + bookkeeping, see JobRunner::MIN_TICK_SECONDS). */
    private const NEEDS = [ 'submit' => 14.0, 'evaluate' => 10.0, 'package' => 10.0 ];

    /** Seconds kept back from a job's budget for saving the state afterwards. */
    private const RESERVE_SECONDS = 2.0;

    /** A step whose first run never returned (the request died) this many times is marked failed. */
    private const MAX_STEP_ATTEMPTS = 3;

    /** Top-level integer result keys that are bookkeeping, not work done. */
    private const IGNORED_COUNTS = [ 'elapsed', 'deferrals', 'requeued_job_id', 'job_id', 'attempt', 'attempts', 'prospect_id', 'limit', 'max', 'min_score', 'continued' ];

    /** Most count entries quoted in a composed step summary. */
    private const MAX_SUMMARY_COUNTS = 6;

    private Container $c;

    public function __construct( ?Container $c = null ) {
        $this->c = $c ?? Plugin::instance()->container();
    }

    // ------------------------------------------------------------------
    // Public API (see the cross-builder contract)
    // ------------------------------------------------------------------

    /**
     * Starts a new run, or returns the active one unchanged when a run is already in progress.
     *
     * @return array<string,mixed> state()
     */
    public function start( int $userId ): array {
        if ( $this->isActive() ) {
            return $this->state();
        }
        $locks = $this->locks();
        if ( ! $locks->acquire( self::LOCK, 30 ) ) {
            // Another request is starting or advancing a run right now.
            $state            = $this->state();
            $state['waiting'] = true;
            $state['note']    = 'Another request is working on the growth run; showing its current state.';
            return $state;
        }
        try {
            $current = $this->load();
            if ( null !== $current && self::STATUS_RUNNING === $current['status'] ) {
                return $this->decorate( $current );
            }
            $now   = gmdate( 'c' );
            $state = [
                'id'              => gmdate( 'YmdHis' ) . '-' . Crypto::randomToken( 3 ),
                'status'          => self::STATUS_RUNNING,
                'started_at'      => $now,
                'started_by'      => max( 0, $userId ),
                'updated_at'      => $now,
                'finished_at'     => null,
                'last_advance_at' => null,
                'cursor'          => 0,
                'steps'           => self::freshSteps(),
                'summary'         => [],
                'needs_you'       => [],
                'next_actions'    => [],
            ];
            $this->save( $state );
        } finally {
            $locks->release( self::LOCK );
        }

        $this->audit( 'growth.started', [ 'id' => $state['id'] ], $userId );
        $this->log( 'info', 'Growth run started.', [ 'id' => $state['id'], 'user' => $userId ] );
        $this->kickScheduler();
        return $this->decorate( $state );
    }

    /**
     * Runs as many steps as fit in the budget (no step starts with fewer than MIN_STEP_SECONDS left) and saves the
     * state after every step. Returns the state; `waiting` is true when the runner or another advance was busy.
     *
     * @return array<string,mixed> state()
     */
    public function advance( TimeBudget $budget ): array {
        $state = $this->load();
        if ( null === $state || self::STATUS_RUNNING !== $state['status'] ) {
            return $this->decorate( $state ?? self::blank() );
        }
        $locks = $this->locks();
        if ( ! $locks->acquire( self::LOCK, (int) ceil( $budget->remaining() ) + 60 ) ) {
            return $this->waiting( $state, 'Another request is already working on this run. Checking again shortly.' );
        }

        $waitingNote = '';
        try {
            $state = $this->load() ?? $state; // what other requests wrote before the lock was taken
            if ( self::STATUS_RUNNING !== $state['status'] ) {
                return $this->decorate( $state );
            }
            $state['last_advance_at'] = gmdate( 'c' );
            $this->save( $state );

            $finishedHere = 0;
            while ( true ) {
                $cursor = (int) $state['cursor'];
                if ( $cursor >= count( $state['steps'] ) ) {
                    $this->complete( $state );
                    break;
                }
                if ( ! $budget->has( self::MIN_STEP_SECONDS ) ) {
                    break;
                }
                $outcome = $this->runStep( $state, $cursor, $budget, $finishedHere );
                if ( 'cancelled' === $outcome ) {
                    break;
                }
                if ( 'waiting' === $outcome ) {
                    $waitingNote = 'The background job runner is busy with another job; this step starts as soon as it is free.';
                    $this->saveUnlessCancelled( $state );
                    break;
                }
                if ( 'later' === $outcome ) {
                    $this->saveUnlessCancelled( $state );
                    break;
                }
                $finishedHere++;
                $step = $state['steps'][ $cursor ];
                $this->log(
                    'info',
                    sprintf( 'Growth step "%s" %s: %s', (string) $step['key'], (string) $step['status'], (string) $step['summary'] ),
                    [ 'id' => $state['id'], 'rounds' => (int) $step['rounds'], 'counts' => $step['counts'] ]
                );
                $state['cursor'] = $cursor + 1;
                if ( ! $this->saveUnlessCancelled( $state ) ) {
                    break;
                }
            }
            if ( self::STATUS_CANCELLED === $state['status'] ) {
                // Cancelled while a job was running: a continuation it queued afterwards is cancelled as well.
                $this->cancelTaggedJobs( (string) $state['id'] );
            }
        } catch ( Throwable $e ) {
            // Never leave a half-written state behind: record the failure and end the run.
            $this->log( 'error', 'The growth run stopped on an unexpected error: ' . $e->getMessage(), [ 'at' => basename( $e->getFile() ) . ':' . $e->getLine() ] );
            if ( self::STATUS_RUNNING === ( $state['status'] ?? '' ) ) {
                $state['status']      = self::STATUS_FAILED;
                $state['finished_at'] = gmdate( 'c' );
                $state['error']       = 'The run stopped on an unexpected error: ' . $e->getMessage();
                $this->saveUnlessCancelled( $state );
            }
        } finally {
            $locks->release( self::LOCK );
        }

        if ( '' !== $waitingNote ) {
            return $this->waiting( $state, $waitingNote );
        }
        return $this->decorate( $state );
    }

    /**
     * The current (or last) run with computed fields: active, progress {done,total}, elapsed_seconds, waiting, note.
     *
     * @return array<string,mixed>
     */
    public function state(): array {
        return $this->decorate( $this->load() ?? self::blank() );
    }

    /**
     * Cancels the active run: steps not started stay pending, queued jobs tagged with the run id are cancelled.
     * Work already done is kept.
     *
     * @return array<string,mixed> state() plus cancelled_jobs
     */
    public function cancel( int $userId ): array {
        $state = $this->load();
        if ( null === $state || self::STATUS_RUNNING !== $state['status'] ) {
            $out                   = $this->decorate( $state ?? self::blank() );
            $out['cancelled_jobs'] = 0;
            return $out;
        }
        $state['status']      = self::STATUS_CANCELLED;
        $state['finished_at'] = gmdate( 'c' );
        foreach ( $state['steps'] as $i => $step ) {
            if ( self::STEP_RUNNING === ( $step['status'] ?? '' ) ) {
                $state['steps'][ $i ]['status']      = self::STEP_SKIPPED;
                $state['steps'][ $i ]['finished_at'] = gmdate( 'c' );
                $state['steps'][ $i ]['note']        = 'Stopped when the run was cancelled.';
            }
        }
        $this->save( $state );
        $cancelled = $this->cancelTaggedJobs( (string) $state['id'] );
        $this->audit( 'growth.cancelled', [ 'id' => $state['id'], 'cancelled_jobs' => $cancelled, 'cursor' => (int) $state['cursor'] ], $userId );
        $this->log( 'info', 'Growth run cancelled.', [ 'id' => $state['id'], 'cancelled_jobs' => $cancelled ] );
        $out                   = $this->decorate( $state );
        $out['cancelled_jobs'] = $cancelled;
        return $out;
    }

    public function isActive(): bool {
        $state = $this->load();
        return null !== $state && self::STATUS_RUNNING === $state['status'];
    }

    /** Seconds since the run was last advanced (PHP_INT_MAX when there is no run). */
    public function lastAdvanceAge(): int {
        $state = $this->load();
        if ( null === $state ) {
            return PHP_INT_MAX;
        }
        foreach ( [ 'last_advance_at', 'started_at' ] as $key ) {
            $at = is_string( $state[ $key ] ?? null ) ? strtotime( (string) $state[ $key ] ) : false;
            if ( false !== $at && $at > 0 ) {
                return max( 0, time() - $at );
            }
        }
        return PHP_INT_MAX;
    }

    /**
     * The ordered step plan: [{key, label, kind}].
     *
     * @return array<int,array{key:string,label:string,kind:string}>
     */
    public static function plan(): array {
        $out = [];
        foreach ( self::STEPS as $key => [ $label, $kind ] ) {
            $out[] = [ 'key' => $key, 'label' => $label, 'kind' => $kind ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Step execution
    // ------------------------------------------------------------------

    /**
     * Runs (or continues) step $i. Returns 'finished' (the step ended), 'later' (out of time; continue on the next
     * advance), 'waiting' (the runner lock is busy) or 'cancelled' (the run was cancelled meanwhile).
     *
     * @param array<string,mixed> $state
     */
    private function runStep( array &$state, int $i, TimeBudget $budget, int $finishedHere ): string {
        $key  = (string) $state['steps'][ $i ]['key'];
        $kind = self::STEPS[ $key ][1] ?? 'job';
        try {
            if ( 'internal' === $kind ) {
                $this->markRunning( $state, $i );
                if ( 'preflight' === $key ) {
                    $this->preflight( $state, $i );
                } else {
                    $this->summaryStep( $state, $i );
                }
                return 'finished';
            }
            return $this->jobStep( $state, $i, $budget, $finishedHere );
        } catch ( Throwable $e ) {
            $this->log( 'error', sprintf( 'Growth step "%s" failed: %s', $key, $e->getMessage() ), [ 'at' => basename( $e->getFile() ) . ':' . $e->getLine() ] );
            $this->finishStep( $state, $i, self::STEP_FAILED, 'This step failed: ' . $e->getMessage(), $e->getMessage() );
            return 'finished';
        }
    }

    /**
     * Runs a job step: the job once (runType, manual, tagged with the run id), then its continuations
     * (runQueuedOfType) while rounds < cap and the budget allows.
     *
     * @param array<string,mixed> $state
     */
    private function jobStep( array &$state, int $i, TimeBudget $budget, int $finishedHere ): string {
        $type   = (string) $state['steps'][ $i ]['key'];
        $runner = $this->runner();
        if ( ! isset( $runner->types()[ $type ] ) ) {
            $this->finishStep( $state, $i, self::STEP_SKIPPED, 'Not available in this version.' );
            return 'finished';
        }
        if ( 'submit' === $type ) {
            $blocked = $this->submitBlockedReason();
            if ( '' !== $blocked ) {
                $this->finishStep( $state, $i, self::STEP_BLOCKED, $blocked );
                return 'finished';
            }
        }

        $need = max( self::MIN_STEP_SECONDS, self::NEEDS[ $type ] ?? self::MIN_STEP_SECONDS );
        $cap  = $this->capFor( $type );
        // A step that does not fit waits for the next advance, unless nothing ran yet in this one (a budget smaller
        // than the need must not stall the run: the job then defers itself and the scheduler picks it up).
        if ( $finishedHere > 0 && ! $budget->has( $need ) ) {
            return 'later';
        }
        if ( ! $runner->lock( (int) ceil( $budget->remaining() ) + 60 ) ) {
            return 'waiting';
        }

        $progress = false;
        try {
            if ( 0 === (int) $state['steps'][ $i ]['rounds'] ) {
                $attempts = (int) ( $state['steps'][ $i ]['attempts'] ?? 0 ) + 1;
                if ( $attempts > self::MAX_STEP_ATTEMPTS ) {
                    $this->finishStep( $state, $i, self::STEP_FAILED, sprintf( 'This step did not finish in %d attempts (the request probably ran out of time), so the run moved on.', self::MAX_STEP_ATTEMPTS ), 'Did not finish.' );
                    return 'finished';
                }
                $state['steps'][ $i ]['attempts'] = $attempts;
                $this->markRunning( $state, $i );
                if ( ! $this->saveUnlessCancelled( $state ) ) {
                    return 'cancelled';
                }
                $result = $runner->runType( $type, $this->payload( $state ), $this->childBudget( $budget ) );
                $this->recordStageRun( $type );
                $state['steps'][ $i ]['rounds'] = 1;
                $this->absorb( $state['steps'][ $i ], $result );
                $progress = true;
            }

            while ( (int) $state['steps'][ $i ]['rounds'] < $cap ) {
                $acc = is_array( $state['steps'][ $i ]['_acc'] ?? null ) ? $state['steps'][ $i ]['_acc'] : self::freshAcc();
                if ( ! empty( $acc['last_failed'] ) || ! $this->hasQueued( $type ) ) {
                    break;
                }
                $madeProgress = $progress || $finishedHere > 0;
                if ( $madeProgress && ( ! empty( $acc['last_deferred'] ) || ! $budget->has( $need ) ) ) {
                    return 'later';
                }
                if ( $this->replacedMeanwhile( $state ) ) {
                    return 'cancelled';
                }
                $res = $runner->runQueuedOfType( $type, $budget, true, 1 );
                if ( (int) ( $res['ran'] ?? 0 ) < 1 ) {
                    if ( $madeProgress ) {
                        return 'later'; // too little time left to claim the continuation
                    }
                    break;
                }
                $state['steps'][ $i ]['rounds'] = (int) $state['steps'][ $i ]['rounds'] + 1;
                foreach ( (array) ( $res['results'] ?? [] ) as $r ) {
                    $this->absorb( $state['steps'][ $i ], is_array( $r['result'] ?? null ) ? $r['result'] : [] );
                }
                $progress = true;
            }
        } finally {
            $runner->unlock();
        }

        $step = $state['steps'][ $i ];
        $acc  = is_array( $step['_acc'] ?? null ) ? $step['_acc'] : self::freshAcc();
        $more = $this->hasQueued( $type ) || ! empty( $acc['last_more'] );
        $note = $more ? 'More work remains; the scheduler continues it in the background.' : '';
        if ( (int) $acc['failed'] > 0 && 0 === (int) $acc['ok'] ) {
            $this->finishStep( $state, $i, self::STEP_FAILED, self::composeSummary( $step ), (string) $acc['error'], $note );
        } elseif ( (int) $acc['skipped'] > 0 && (int) $acc['skipped'] === (int) $acc['ok'] && [] === self::nonZero( $step['counts'] ) && empty( $acc['deferred_any'] ) ) {
            $this->finishStep( $state, $i, self::STEP_SKIPPED, self::composeSummary( $step ), '', $note );
        } else {
            $this->finishStep( $state, $i, self::STEP_DONE, self::composeSummary( $step ), (string) $acc['error'], $note );
        }
        return 'finished';
    }

    /**
     * Folds one job result into the step: integer counters are summed, the latest summary/reason/message kept.
     *
     * @param array<string,mixed> $step
     * @param array<string,mixed> $result
     */
    private function absorb( array &$step, array $result ): void {
        $acc = is_array( $step['_acc'] ?? null ) ? $step['_acc'] : self::freshAcc();
        $ok  = ! array_key_exists( 'ok', $result ) || false !== $result['ok'];
        if ( $ok ) {
            $acc['ok']++;
            $acc['last_failed'] = false;
        } else {
            $acc['failed']++;
            $acc['last_failed'] = true;
            $acc['error']       = mb_substr( (string) ( $result['error'] ?? 'The job failed.' ), 0, 500 );
        }
        $skipped               = $ok && ! empty( $result['skipped'] );
        $deferred              = $skipped && 'time_budget' === (string) ( $result['reason'] ?? '' );
        $acc['last_deferred']  = $deferred;
        $acc['deferred_any']   = ! empty( $acc['deferred_any'] ) || $deferred;
        $acc['last_more']      = ! empty( $result['more'] ) || ! empty( $result['continued'] ) || ! empty( $result['requeued'] );
        if ( $skipped ) {
            $acc['skipped']++;
            $acc['reason'] = (string) ( $result['reason'] ?? '' );
        }
        if ( is_string( $result['summary'] ?? null ) && '' !== trim( $result['summary'] ) ) {
            $acc['summary'] = mb_substr( trim( $result['summary'] ), 0, 500 );
        }
        foreach ( [ 'message', 'note', 'hint' ] as $k ) {
            if ( is_string( $result[ $k ] ?? null ) && '' !== trim( $result[ $k ] ) ) {
                $acc['detail'] = mb_substr( trim( $result[ $k ] ), 0, 300 );
                break;
            }
        }
        if ( is_array( $result['warnings'] ?? null ) ) {
            foreach ( $result['warnings'] as $w ) {
                if ( is_string( $w ) && '' !== trim( $w ) ) {
                    $acc['warning'] = mb_substr( trim( $w ), 0, 300 );
                    break;
                }
            }
        }
        $counts = is_array( $step['counts'] ?? null ) ? $step['counts'] : [];
        foreach ( $result as $k => $v ) {
            if ( is_int( $v ) && is_string( $k ) && ! in_array( $k, self::IGNORED_COUNTS, true ) ) {
                $counts[ $k ] = (int) ( $counts[ $k ] ?? 0 ) + $v;
            }
        }
        $step['counts'] = $counts;
        $step['_acc']   = $acc;
    }

    /**
     * One plain sentence for a job step: the job's own summary when it gave one, else one composed from its
     * numeric results plus its reason / message / note.
     *
     * @param array<string,mixed> $step
     */
    private static function composeSummary( array $step ): string {
        $acc = is_array( $step['_acc'] ?? null ) ? $step['_acc'] : self::freshAcc();
        if ( '' !== (string) $acc['summary'] ) {
            return (string) $acc['summary'];
        }
        $parts   = [];
        $nonZero = self::nonZero( is_array( $step['counts'] ?? null ) ? $step['counts'] : [] );
        if ( [] !== $nonZero ) {
            $items = [];
            foreach ( array_slice( $nonZero, 0, self::MAX_SUMMARY_COUNTS, true ) as $k => $v ) {
                $items[] = sprintf( '%d %s', $v, str_replace( '_', ' ', (string) $k ) );
            }
            $parts[] = 'Results: ' . implode( ', ', $items ) . '.';
        } elseif ( (int) $acc['failed'] > 0 && 0 === (int) $acc['ok'] ) {
            $parts[] = 'The job failed: ' . rtrim( (string) $acc['error'], '.' ) . '.';
        } elseif ( '' !== (string) $acc['reason'] ) {
            $parts[] = 'Skipped: ' . str_replace( '_', ' ', (string) $acc['reason'] ) . '.';
        }
        if ( '' !== (string) $acc['detail'] ) {
            $parts[] = rtrim( (string) $acc['detail'] );
        }
        if ( '' !== (string) $acc['warning'] ) {
            $parts[] = rtrim( (string) $acc['warning'] );
        }
        return [] === $parts ? 'Nothing to do this time.' : implode( ' ', $parts );
    }

    /**
     * Submit-step gate: the reason the step is blocked, '' when the submit job may run (Policy then decides per item).
     */
    private function submitBlockedReason(): string {
        $storefront = $this->storefront();
        if ( null !== $storefront ) {
            try {
                if ( $storefront->isBlocking() ) {
                    $reason = method_exists( $storefront, 'blockingReason' ) ? trim( (string) $storefront->blockingReason() ) : '';
                    return 'Paused by the storefront compliance check. ' . ( '' !== $reason ? $reason : 'The storefront wording conflicts with the research-use-only listings; review the findings on the Overview page.' );
                }
            } catch ( Throwable $e ) {
                return 'Paused: the storefront compliance check could not run (' . $e->getMessage() . '). Automated submissions stay paused until it runs.';
            }
        }
        if ( ! (bool) $this->settings()->get( 'automation.submissions', false ) ) {
            return 'Automated submissions are switched off (Automation → Submissions), so nothing was submitted. Prepared packages wait for your review under Submissions.';
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Internal steps
    // ------------------------------------------------------------------

    /**
     * Preflight: re-runs the storefront compliance check and checks the integrations a full run relies on. Every gap
     * becomes a "needs you" item; nothing here fails the run.
     *
     * @param array<string,mixed> $state
     */
    private function preflight( array &$state, int $i ): void {
        $needs     = [];
        $sentences = [];
        $counts    = [ 'storefront_block' => 0, 'storefront_warn' => 0 ];

        $storefront = $this->storefront();
        if ( null === $storefront || ! method_exists( $storefront, 'run' ) ) {
            $sentences[] = 'The storefront compliance check is not available in this version.';
        } else {
            try {
                $result                     = (array) $storefront->run();
                $counts['storefront_block'] = (int) ( $result['counts']['block'] ?? 0 );
                $counts['storefront_warn']  = (int) ( $result['counts']['warn'] ?? 0 );
                $blocking                   = (bool) $storefront->isBlocking();
                $effective                  = method_exists( $storefront, 'effectiveStatus' ) ? (string) $storefront->effectiveStatus() : (string) ( $result['status'] ?? '' );
                $sentences[]                = self::storefrontSentence( $effective, $blocking, $counts['storefront_block'], $counts['storefront_warn'] );
                if ( $blocking ) {
                    $needs[] = self::action( 'storefront', 'Review the storefront compliance findings: automated submissions and outreach sending are paused while they block.', null, 'overview' );
                }
            } catch ( Throwable $e ) {
                $sentences[] = 'The storefront check could not run (' . $e->getMessage() . '); automated submissions and outreach sending stay paused until it runs.';
                $needs[]     = self::action( 'storefront', 'The storefront compliance check could not run; open the Overview page to re-check it.', null, 'overview' );
            }
        }

        foreach ( $this->setupGaps() as $gap ) {
            $needs[] = $gap;
        }
        $counts['needs_you'] = count( $needs );
        $state['needs_you']  = $needs;
        $sentences[]         = [] === $needs
            ? 'Setup checks passed.'
            : sprintf( '%d setup %s need%s you (listed at the end of the run).', count( $needs ), 1 === count( $needs ) ? 'item' : 'items', 1 === count( $needs ) ? 's' : '' );

        $state['steps'][ $i ]['counts'] = $counts;
        $this->finishStep( $state, $i, self::STEP_DONE, implode( ' ', $sentences ) );
    }

    private static function storefrontSentence( string $effective, bool $blocking, int $block, int $warn ): string {
        if ( $blocking ) {
            return sprintf( 'Storefront check: %d blocking and %d to review; automated submissions and outreach sending are paused until you review them.', $block, $warn );
        }
        return match ( $effective ) {
            'pass'         => 'Storefront check: no consumer-use wording found.',
            'acknowledged' => sprintf( 'Storefront check: %d blocking and %d to review, unchanged since you acknowledged them.', $block, $warn ),
            'block'        => sprintf( 'Storefront check: %d blocking and %d to review (the gate is off, so nothing is paused).', $block, $warn ),
            'warn'         => sprintf( 'Storefront check: %d item(s) to review (nothing is paused).', $warn ),
            default        => 'Storefront check: status unknown.',
        };
    }

    /**
     * Configuration the run relies on that is missing.
     *
     * @return array<int,array<string,mixed>>
     */
    private function setupGaps(): array {
        $gaps = [];
        try {
            $registry = $this->c->get( ProviderRegistry::class );
            if ( [] === $registry->searchProviders() ) {
                $configured = false;
                foreach ( $registry->status() as $entry ) {
                    if ( is_array( $entry ) && 'search' === (string) ( $entry['kind'] ?? '' ) && ! empty( $entry['configured'] ) ) {
                        $configured = true;
                        break;
                    }
                }
                if ( ! $configured ) {
                    $gaps[] = self::action( 'search_provider', 'Add a search provider key (Google Programmable Search, Brave Search or Serper) so discovery can run search queries.', null, 'settings', [ 'tab' => 'integrations' ] );
                }
            }
            if ( [] === $registry->metricsProviders( true ) ) {
                $gaps[] = self::action( 'metrics_provider', 'Add your SE Ranking API key (or an Ahrefs, Semrush or Moz key) so prospects get authority and traffic metrics; until then those metrics show as unknown.', null, 'settings', [ 'tab' => 'integrations' ] );
            }
        } catch ( Throwable $e ) {
            $this->log( 'warning', 'Growth preflight could not check the providers: ' . $e->getMessage() );
        }
        try {
            $sender = $this->c->get( Compliance::class )->senderReady();
            if ( empty( $sender['ok'] ) ) {
                $labels  = [ 'sender_name' => 'sender name', 'sender_email' => 'sender email', 'postal_address' => 'postal address' ];
                $missing = [];
                foreach ( (array) ( $sender['missing'] ?? [] ) as $field ) {
                    $missing[] = $labels[ (string) $field ] ?? str_replace( '_', ' ', (string) $field );
                }
                $gaps[] = self::action(
                    'sender_identity',
                    'Complete the outreach sender identity' . ( [] !== $missing ? ' (missing: ' . implode( ', ', $missing ) . ')' : '' ) . '. Outreach emails cannot be sent until it is complete.',
                    null,
                    'settings',
                    [ 'tab' => 'outreach' ]
                );
            }
        } catch ( Throwable $e ) {
            $this->log( 'warning', 'Growth preflight could not check the sender identity: ' . $e->getMessage() );
        }
        try {
            $google = $this->c->get( SearchConsole::class )->isConfigured() || $this->c->get( SiteKitBridge::class )->isAvailable();
            if ( ! $google ) {
                $gaps[] = self::action( 'google', 'Connect Google Search Console (a service account under Settings → Integrations, or the Site Kit plugin) to see search clicks and impressions.', null, 'settings', [ 'tab' => 'integrations' ] );
            }
        } catch ( Throwable $e ) {
            $this->log( 'warning', 'Growth preflight could not check the Google connection: ' . $e->getMessage() );
        }
        return $gaps;
    }

    /**
     * Summary step: headline numbers of the whole system after the run, plus the owner's next actions.
     *
     * @param array<string,mixed> $state
     */
    private function summaryStep( array &$state, int $i ): void {
        $summary               = $this->computeSummary( $state );
        $state['summary']      = $summary;
        $state['next_actions'] = $this->nextActions( $state, $summary );

        $parts = [];
        if ( is_array( $summary['prospects'] ) ) {
            $parts[] = sprintf( '%d prospects (%d qualified).', $summary['prospects']['total'], $summary['prospects']['qualified'] );
        }
        if ( is_array( $summary['submissions'] ) ) {
            $parts[] = sprintf( '%d owner tasks waiting.', $summary['submissions']['owner_tasks'] );
        }
        if ( null !== $summary['outreach_drafts'] ) {
            $parts[] = sprintf( '%d outreach drafts await your approval.', $summary['outreach_drafts'] );
        }
        if ( is_array( $summary['backlinks'] ) ) {
            $parts[] = sprintf( '%d live backlinks.', $summary['backlinks']['live'] );
        }
        $parts[] = sprintf( '%d next %s for you.', count( $state['next_actions'] ), 1 === count( $state['next_actions'] ) ? 'action' : 'actions' );

        $counts = [ 'next_actions' => count( $state['next_actions'] ) ];
        if ( is_array( $summary['submissions'] ) ) {
            $counts['owner_tasks'] = (int) $summary['submissions']['owner_tasks'];
        }
        if ( null !== $summary['outreach_drafts'] ) {
            $counts['outreach_drafts'] = (int) $summary['outreach_drafts'];
        }
        $state['steps'][ $i ]['counts'] = $counts;
        $this->finishStep( $state, $i, self::STEP_DONE, implode( ' ', $parts ) );
    }

    /**
     * Headline numbers. A value that cannot be read is null (shown as "unknown" / "not available"), never 0.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function computeSummary( array $state ): array {
        $prospects = $this->safe( function (): array {
            $ps = $this->c->get( Prospects::class )->statusCounts();
            return [
                'total'     => (int) array_sum( $ps ),
                'qualified' => (int) ( $ps[ Prospects::STATUS_QUALIFIED ] ?? 0 ),
                'approved'  => (int) ( $ps[ Prospects::STATUS_APPROVED ] ?? 0 ),
            ];
        } );
        $opportunities = $this->safe( fn(): int => $this->c->get( Opportunities::class )->count( [ 'status' => Opportunities::STATUS_OPEN ] ) );
        $submissions   = $this->safe( function (): array {
            $ss = array_map( 'intval', $this->c->get( Submissions::class )->statusCounts() );
            return [
                'by_status'      => $ss,
                'owner_tasks'    => (int) ( $ss[ Submissions::STATUS_NEEDS_MANUAL ] ?? 0 ),
                'pending_review' => (int) ( $ss[ Submissions::STATUS_PENDING_REVIEW ] ?? 0 ),
                'submitted'      => (int) ( $ss[ Submissions::STATUS_SUBMITTED ] ?? 0 ),
                'confirmed'      => (int) ( $ss[ Submissions::STATUS_CONFIRMED ] ?? 0 ),
            ];
        } );
        $drafts    = $this->safe( fn(): int => $this->c->get( OutreachMessages::class )->count( [ 'status' => OutreachMessages::STATUS_DRAFT ] ) );
        $backlinks = $this->safe( function (): array {
            $bs = $this->c->get( Backlinks::class )->statusCounts();
            return [
                'live' => (int) ( $bs[ Backlinks::STATUS_ACTIVE ] ?? 0 ),
                'lost' => (int) ( $bs[ Backlinks::STATUS_LOST ] ?? 0 ) + (int) ( $bs[ Backlinks::STATUS_BROKEN ] ?? 0 ),
            ];
        } );
        $audit = $this->optional( SiteAuditor::class, static function ( object $auditor ): array {
            $s      = (array) $auditor->summary();
            $counts = is_array( $s['counts'] ?? null ) ? $s['counts'] : [];
            return [
                'checked_at'   => isset( $s['checked_at'] ) && is_string( $s['checked_at'] ) ? $s['checked_at'] : null,
                'pages'        => (int) ( $s['pages'] ?? 0 ),
                'complete'     => ! empty( $s['complete'] ),
                'high'         => (int) ( $counts['high'] ?? 0 ),
                'medium'       => (int) ( $counts['medium'] ?? 0 ),
                'low'          => (int) ( $counts['low'] ?? 0 ),
                'issues_total' => (int) ( $s['issues_total'] ?? 0 ),
            ];
        } );
        $indexNow = $this->optional( IndexNow::class, static function ( object $svc ): array {
            $s = (array) $svc->status();
            return [
                'enabled'           => ! empty( $s['enabled'] ),
                'submitted_total'   => (int) ( $s['submitted_total'] ?? 0 ),
                'pending'           => (int) ( $s['pending'] ?? 0 ),
                'last_submitted_at' => isset( $s['last_submitted_at'] ) && is_string( $s['last_submitted_at'] ) ? $s['last_submitted_at'] : null,
                'last_error'        => (string) ( $s['last_error'] ?? '' ),
            ];
        } );
        $links      = $this->optional( InternalLinker::class, static fn( object $svc ): int => (int) $svc->pendingCount() );
        $verified   = $this->optional( VerifiedOpportunities::class, static fn( object $svc ): array => (array) $svc->status() );
        $compliance = null;
        $storefront = $this->storefront();
        if ( null !== $storefront && method_exists( $storefront, 'summary' ) ) {
            $compliance = $this->safe( static function () use ( $storefront ): array {
                $s = (array) $storefront->summary();
                return [
                    'status'    => (string) ( $s['status'] ?? 'unknown' ),
                    'effective' => (string) ( $s['effective'] ?? 'unknown' ),
                    'blocking'  => ! empty( $s['blocking'] ),
                    'counts'    => [ 'block' => (int) ( $s['counts']['block'] ?? 0 ), 'warn' => (int) ( $s['counts']['warn'] ?? 0 ) ],
                ];
            } );
        }

        $steps = [ self::STEP_DONE => 0, self::STEP_SKIPPED => 0, self::STEP_BLOCKED => 0, self::STEP_FAILED => 0 ];
        $run   = [];
        foreach ( $state['steps'] as $step ) {
            $status = (string) ( $step['status'] ?? '' );
            if ( isset( $steps[ $status ] ) ) {
                $steps[ $status ]++;
            }
            $run[ (string) $step['key'] ] = is_array( $step['counts'] ?? null ) ? $step['counts'] : [];
        }

        return [
            'computed_at'              => gmdate( 'c' ),
            'prospects'                => $prospects,
            'opportunities_open'       => $opportunities,
            'submissions'              => $submissions,
            'outreach_drafts'          => $drafts,
            'backlinks'                => $backlinks,
            'site_audit'               => $audit,
            'indexnow'                 => $indexNow,
            'indexnow_submitted_run'   => isset( $run['indexnow']['submitted'] ) ? (int) $run['indexnow']['submitted'] : null,
            'link_suggestions_pending' => $links,
            'compliance'               => $compliance,
            'verified'                 => $verified,
            'steps'                    => $steps,
        ];
    }

    /**
     * What the owner should do next, most important first: [{key, label, count, route, page, params}].
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $summary
     * @return array<int,array<string,mixed>>
     */
    private function nextActions( array $state, array $summary ): array {
        $out = [];
        $add = static function ( array $action ) use ( &$out ): void {
            foreach ( $out as $existing ) {
                if ( $existing['key'] === $action['key'] ) {
                    return;
                }
            }
            $out[] = $action;
        };

        foreach ( (array) ( $state['needs_you'] ?? [] ) as $item ) {
            if ( is_array( $item ) && 'storefront' === ( $item['key'] ?? '' ) ) {
                $add( $item );
            }
        }
        if ( is_array( $summary['compliance'] ) && ! empty( $summary['compliance']['blocking'] ) ) {
            $add( self::action( 'storefront', 'Review the storefront compliance findings: automated submissions and outreach sending are paused while they block.', null, 'overview' ) );
        }
        $subs = is_array( $summary['submissions'] ) ? $summary['submissions'] : null;
        if ( null !== $subs && $subs['owner_tasks'] > 0 ) {
            $add( self::action( 'owner_tasks', sprintf( 'Complete %d owner %s (sites where a person has to submit the listing)', $subs['owner_tasks'], 1 === $subs['owner_tasks'] ? 'task' : 'tasks' ), $subs['owner_tasks'], 'submissions', [ 'tab' => 'needs_manual' ] ) );
        }
        if ( null !== $subs && $subs['pending_review'] > 0 ) {
            $add( self::action( 'pending_review', sprintf( 'Review %d listing %s waiting for approval', $subs['pending_review'], 1 === $subs['pending_review'] ? 'package' : 'packages' ), $subs['pending_review'], 'submissions', [ 'tab' => 'pending_review' ] ) );
        }
        $drafts = $summary['outreach_drafts'];
        if ( is_int( $drafts ) && $drafts > 0 ) {
            $add( self::action( 'outreach_drafts', sprintf( 'Review %d outreach %s (nothing is sent without approval)', $drafts, 1 === $drafts ? 'draft' : 'drafts' ), $drafts, 'outreach', [ 'tab' => 'messages', 'status' => 'draft' ] ) );
        }
        $links = $summary['link_suggestions_pending'];
        if ( is_int( $links ) && $links > 0 ) {
            $add( self::action( 'link_suggestions', sprintf( 'Review %d internal-link %s (applied only when you click Apply)', $links, 1 === $links ? 'suggestion' : 'suggestions' ), $links, 'seo', [ 'links' => 'pending' ] ) );
        }
        if ( is_array( $summary['site_audit'] ) && $summary['site_audit']['high'] > 0 ) {
            $high = (int) $summary['site_audit']['high'];
            $add( self::action( 'seo_issues', sprintf( 'Fix %d high-priority on-site SEO %s', $high, 1 === $high ? 'issue' : 'issues' ), $high, 'seo', [ 'severity' => 'high' ] ) );
        }
        foreach ( $state['steps'] as $step ) {
            if ( 'submit' === ( $step['key'] ?? '' ) && self::STEP_BLOCKED === ( $step['status'] ?? '' ) && str_starts_with( (string) $step['summary'], 'Automated submissions are switched off' ) ) {
                $add( self::action( 'submissions_off', 'Automated submissions are switched off: approved listings wait for you (Automation → Submissions)', null, 'automation' ) );
            }
        }
        foreach ( (array) ( $state['needs_you'] ?? [] ) as $item ) {
            if ( is_array( $item ) && isset( $item['key'], $item['label'] ) ) {
                $add( $item );
            }
        }
        return $out;
    }

    /**
     * A next action / needs-you item. `page` + `params` address a dashboard page (ctx.pageUrl( page, params ));
     * `route` is the same as one SPA-style hash string.
     *
     * @param array<string,string> $params
     * @return array{key:string,label:string,count:?int,route:string,page:string,params:array<string,string>}
     */
    private static function action( string $key, string $label, ?int $count, string $page, array $params = [] ): array {
        return [
            'key'    => $key,
            'label'  => $label,
            'count'  => $count,
            'route'  => '#/' . $page . ( [] !== $params ? '?' . http_build_query( $params ) : '' ),
            'page'   => $page,
            'params' => $params,
        ];
    }

    // ------------------------------------------------------------------
    // State helpers
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function blank(): array {
        return [
            'id'              => '',
            'status'          => self::STATUS_IDLE,
            'started_at'      => null,
            'started_by'      => 0,
            'updated_at'      => null,
            'finished_at'     => null,
            'last_advance_at' => null,
            'cursor'          => 0,
            'steps'           => self::freshSteps(),
            'summary'         => [],
            'needs_you'       => [],
            'next_actions'    => [],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function freshSteps(): array {
        $out = [];
        foreach ( self::STEPS as $key => [ $label ] ) {
            $out[] = [
                'key'         => $key,
                'label'       => $label,
                'status'      => self::STEP_PENDING,
                'rounds'      => 0,
                'attempts'    => 0,
                'started_at'  => null,
                'finished_at' => null,
                'summary'     => '',
                'counts'      => [],
                'error'       => '',
                'note'        => '',
                '_acc'        => self::freshAcc(),
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function freshAcc(): array {
        return [ 'ok' => 0, 'failed' => 0, 'skipped' => 0, 'summary' => '', 'detail' => '', 'warning' => '', 'reason' => '', 'error' => '', 'last_failed' => false, 'last_deferred' => false, 'deferred_any' => false, 'last_more' => false ];
    }

    /** @param array<string,mixed> $state */
    private function markRunning( array &$state, int $i ): void {
        $state['steps'][ $i ]['status']     = self::STEP_RUNNING;
        $state['steps'][ $i ]['started_at'] = $state['steps'][ $i ]['started_at'] ?? gmdate( 'c' );
        if ( ! is_array( $state['steps'][ $i ]['_acc'] ?? null ) ) {
            $state['steps'][ $i ]['_acc'] = self::freshAcc();
        }
    }

    /** @param array<string,mixed> $state */
    private function finishStep( array &$state, int $i, string $status, string $summary, string $error = '', string $note = '' ): void {
        $state['steps'][ $i ]['status']      = $status;
        $state['steps'][ $i ]['started_at']  = $state['steps'][ $i ]['started_at'] ?? gmdate( 'c' );
        $state['steps'][ $i ]['finished_at'] = gmdate( 'c' );
        $state['steps'][ $i ]['summary']     = mb_substr( $summary, 0, 1000 );
        $state['steps'][ $i ]['error']       = mb_substr( $error, 0, 500 );
        $state['steps'][ $i ]['note']        = $note;
        if ( ! is_array( $state['steps'][ $i ]['counts'] ?? null ) ) {
            $state['steps'][ $i ]['counts'] = [];
        }
    }

    /** @param array<string,mixed> $state */
    private function complete( array &$state ): void {
        $state['status']      = self::STATUS_COMPLETED;
        $state['finished_at'] = gmdate( 'c' );
        $state['cursor']      = count( $state['steps'] );
        if ( ! $this->saveUnlessCancelled( $state ) ) {
            return;
        }
        $statuses = array_count_values( array_map( static fn( array $s ): string => (string) $s['status'], $state['steps'] ) );
        $this->audit( 'growth.completed', [ 'id' => $state['id'], 'steps' => $statuses, 'next_actions' => count( (array) $state['next_actions'] ) ] );
        $this->log( 'info', 'Growth run completed.', [ 'id' => $state['id'], 'steps' => $statuses ] );
    }

    /**
     * The stored run, read past the object cache (another request may have written it), or null.
     *
     * @return array<string,mixed>|null
     */
    private function load(): ?array {
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( self::OPTION, 'options' );
        }
        $state = get_option( self::OPTION, null );
        if ( ! is_array( $state ) || ! is_string( $state['id'] ?? null ) || '' === $state['id'] || ! is_string( $state['status'] ?? null ) || ! is_array( $state['steps'] ?? null ) ) {
            return null;
        }
        $defaults = [];
        foreach ( self::freshSteps() as $fresh ) {
            $defaults[ $fresh['key'] ] = $fresh;
        }
        $steps = [];
        foreach ( $state['steps'] as $step ) {
            // Steps this version does not know (or malformed entries) are dropped; known ones get missing fields back.
            if ( is_array( $step ) && is_string( $step['key'] ?? null ) && isset( $defaults[ $step['key'] ] ) ) {
                $steps[] = array_merge( $defaults[ $step['key'] ], $step );
            }
        }
        if ( [] === $steps ) {
            return null;
        }
        $state           = array_merge( self::blank(), $state );
        $state['steps']  = $steps;
        $state['cursor'] = min( count( $steps ), max( 0, (int) $state['cursor'] ) );
        foreach ( [ 'summary', 'needs_you', 'next_actions' ] as $key ) {
            if ( ! is_array( $state[ $key ] ) ) {
                $state[ $key ] = [];
            }
        }
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function save( array &$state ): void {
        $state['updated_at'] = gmdate( 'c' );
        $stored              = $state;
        unset( $stored['active'], $stored['progress'], $stored['elapsed_seconds'], $stored['waiting'], $stored['note'] );
        update_option( self::OPTION, $stored, false );
    }

    /**
     * Saves unless the run was cancelled (or replaced) by another request meanwhile; then $state becomes the stored
     * state and false is returned.
     *
     * @param array<string,mixed> $state
     */
    private function saveUnlessCancelled( array &$state ): bool {
        if ( $this->replacedMeanwhile( $state ) ) {
            return false;
        }
        $this->save( $state );
        return true;
    }

    /**
     * True (and $state becomes the stored state) when another request cancelled or replaced this run.
     *
     * @param array<string,mixed> $state
     */
    private function replacedMeanwhile( array &$state ): bool {
        $stored = $this->load();
        if ( null !== $stored && ( $stored['id'] !== $state['id'] || self::STATUS_RUNNING !== $stored['status'] ) ) {
            $state = $stored;
            return true;
        }
        return false;
    }

    /**
     * Public view of a state: internal accumulators removed, computed fields added.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function decorate( array $state ): array {
        $total = count( $state['steps'] );
        $done  = 0;
        foreach ( $state['steps'] as $i => $step ) {
            unset( $state['steps'][ $i ]['_acc'] );
            if ( in_array( (string) ( $step['status'] ?? '' ), self::FINISHED, true ) ) {
                $done++;
            }
        }
        $started = is_string( $state['started_at'] ?? null ) ? strtotime( (string) $state['started_at'] ) : false;
        $ended   = is_string( $state['finished_at'] ?? null ) ? strtotime( (string) $state['finished_at'] ) : false;
        $state['active']          = self::STATUS_RUNNING === $state['status'];
        $state['progress']        = [ 'done' => $done, 'total' => $total ];
        $state['elapsed_seconds'] = false !== $started && $started > 0 ? max( 0, ( false !== $ended && $ended > 0 ? $ended : time() ) - $started ) : 0;
        $state['waiting']         = false;
        $state['note']            = '';
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function waiting( array $state, string $note ): array {
        $out            = $this->decorate( $state );
        $out['waiting'] = true;
        $out['note']    = $note;
        return $out;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function payload( array $state ): array {
        return [ 'manual' => true, 'growth_run' => (string) $state['id'] ];
    }

    private function capFor( string $type ): int {
        if ( 'evaluate' === $type ) {
            return max( 1, min( 20, (int) $this->settings()->get( 'growth.evaluate_rounds', self::DEFAULT_EVALUATE_ROUNDS ) ) );
        }
        return self::CAPS[ $type ] ?? self::DEFAULT_CAP;
    }

    /** Budget for one direct job run: what is left minus the bookkeeping reserve, at most the tick ceiling. */
    private function childBudget( TimeBudget $budget ): TimeBudget {
        return TimeBudget::seconds( max( 1.0, min( $budget->remaining() - self::RESERVE_SECONDS, JobRunner::effectiveMaxSeconds( $this->settings() ) ) ) );
    }

    private function hasQueued( string $type ): bool {
        try {
            return $this->c->get( Jobs::class )->count( [ 'type' => $type, 'status' => Jobs::STATUS_QUEUED ] ) > 0;
        } catch ( Throwable $e ) {
            return false;
        }
    }

    /**
     * Marks the stage as just run (the same record Pipeline::runNow() keeps), so the cadence scheduler does not
     * enqueue the same stage again on the next tick.
     */
    private function recordStageRun( string $type ): void {
        try {
            $runs = get_option( Pipeline::OPTION_STAGE_RUNS, [] );
            if ( ! is_array( $runs ) ) {
                $runs = [];
            }
            $runs[ $type ] = gmdate( 'c' );
            update_option( Pipeline::OPTION_STAGE_RUNS, $runs, false );
        } catch ( Throwable $e ) {
            unset( $e );
        }
    }

    /** Cancels queued jobs whose payload carries this run's id. */
    private function cancelTaggedJobs( string $id ): int {
        $n = 0;
        try {
            $jobs = $this->c->get( Jobs::class );
            foreach ( $jobs->findBy( [ 'status' => Jobs::STATUS_QUEUED ], 'id', 'ASC', 500 ) as $job ) {
                $payload = is_array( $job['payload'] ?? null ) ? $job['payload'] : [];
                if ( $id !== (string) ( $payload['growth_run'] ?? '' ) ) {
                    continue;
                }
                $jobs->update( (int) $job['id'], [ 'status' => Jobs::STATUS_CANCELLED, 'finished_at' => gmdate( 'Y-m-d H:i:s' ), 'error' => 'Cancelled: the growth run was cancelled.' ] );
                $n++;
            }
        } catch ( Throwable $e ) {
            $this->log( 'error', 'Could not cancel the growth run\'s queued jobs: ' . $e->getMessage() );
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // Services (resolved lazily: Pipeline → GrowthRun → JobRunner/Scheduler → Pipeline would otherwise be circular)
    // ------------------------------------------------------------------

    private function runner(): JobRunner {
        return $this->c->get( JobRunner::class );
    }

    private function settings(): Settings {
        return $this->c->get( Settings::class );
    }

    private function locks(): Locks {
        return $this->c->get( Locks::class );
    }

    /**
     * The storefront audit service (duck-typed: isBlocking(), blockingReason(), run(), effectiveStatus(), summary()),
     * or null when it is not available in this installation.
     */
    private function storefront(): ?object {
        if ( ! class_exists( StorefrontAudit::class ) ) {
            return null;
        }
        try {
            $svc = $this->c->get( StorefrontAudit::class );
            return method_exists( $svc, 'isBlocking' ) ? $svc : null;
        } catch ( Throwable $e ) {
            return null;
        }
    }

    /**
     * Runs $fn with another builder's service; null when the class or service is not available.
     *
     * @param class-string $class
     */
    private function optional( string $class, callable $fn ): mixed {
        if ( ! class_exists( $class ) ) {
            return null;
        }
        return $this->safe( fn() => $fn( $this->c->get( $class ) ) );
    }

    private function safe( callable $fn ): mixed {
        try {
            return $fn();
        } catch ( Throwable $e ) {
            $this->log( 'debug', 'Growth summary value unavailable: ' . $e->getMessage() );
            return null;
        }
    }

    private function kickScheduler(): void {
        try {
            if ( class_exists( Scheduler::class ) ) {
                $this->c->get( Scheduler::class )->kick();
            }
        } catch ( Throwable $e ) {
            $this->log( 'warning', 'Could not kick the scheduler for the growth run: ' . $e->getMessage() );
        }
    }

    /** @param array<string,mixed> $details */
    private function audit( string $action, array $details, int $userId = 0 ): void {
        try {
            $this->c->get( Audit::class )->record( $action, 'growth', 0, $details, $userId > 0 ? 'user:' . $userId : null );
        } catch ( Throwable $e ) {
            unset( $e );
        }
    }

    /** @param array<string,mixed> $context */
    private function log( string $level, string $message, array $context = [] ): void {
        try {
            $this->c->get( Logger::class )->log( $level, 'growth', $message, $context );
        } catch ( Throwable $e ) {
            unset( $e );
        }
    }

    /**
     * Non-zero integer counters, in their original order.
     *
     * @param array<string,mixed> $counts
     * @return array<string,int>
     */
    private static function nonZero( array $counts ): array {
        return array_filter( array_map( 'intval', $counts ), static fn( int $v ): bool => 0 !== $v );
    }
}
