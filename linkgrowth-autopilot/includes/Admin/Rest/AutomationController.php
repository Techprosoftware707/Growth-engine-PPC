<?php
/**
 * /automation — pipeline status, manual stage runs, flag toggles, scheduler controls and the job list.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Admin\Rest;

use LinkGrowth\Autopilot\Automation\Autopilot;
use LinkGrowth\Autopilot\Automation\JobRunner;
use LinkGrowth\Autopilot\Automation\LoadGuard;
use LinkGrowth\Autopilot\Automation\Pipeline;
use LinkGrowth\Autopilot\Automation\Scheduler;
use LinkGrowth\Autopilot\Outreach\CampaignFiller;
use LinkGrowth\Autopilot\Repositories\Jobs;
use LinkGrowth\Autopilot\Support\Limits;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\TimeBudget;
use WP_REST_Request;

final class AutomationController extends Controller {

    /** Flags that may be toggled; `enabled` is the master switch. */
    private const FLAGS = [ 'enabled', 'discovery', 'evaluation', 'opportunities', 'packaging', 'submissions', 'outreach_autosend', 'monitoring', 'imports' ];

    /**
     * Flags whose activation performs real-world side effects and therefore needs explicit confirmation.
     * POST /settings enforces the same gate (SettingsController::update), so no client can skip it.
     */
    public const CONFIRM_FLAGS = [ 'submissions', 'outreach_autosend' ];

    private const RUN_SECONDS = 25.0;

    private const STAGE_LABELS = [
        'discover'           => 'Discover prospects',
        'evaluate'           => 'Evaluate prospects',
        'find_opportunities' => 'Find opportunities',
        'package'            => 'Package submissions',
        'submit'             => 'Process submission queue',
        'verify_listings'    => 'Verify listings',
        'outreach_send'      => 'Send approved outreach',
        'follow_ups'         => 'Generate follow-ups',
        'monitor_backlinks'  => 'Monitor backlinks',
        'import_backlinks'   => 'Import backlinks',
        'mention_scan'       => 'Scan brand mentions',
        'broken_link_scan'   => 'Scan for broken links',
        'cleanup'            => 'Housekeeping',
        'metrics_refresh'    => 'Refresh metrics',
    ];

    public function register( string $namespace ): void {
        $this->route( $namespace, '/automation/status', 'GET', [ $this, 'status' ] );
        $this->route( $namespace, '/automation/run', 'POST', [ $this, 'run' ] );
        $this->route( $namespace, '/automation/toggle', 'POST', [ $this, 'toggle' ] );
        $this->route( $namespace, '/automation/tick', 'POST', [ $this, 'tick' ] );
        $this->route( $namespace, '/automation/kick', 'POST', [ $this, 'kick' ] );
        $this->route( $namespace, '/automation/cancel', 'POST', [ $this, 'cancel' ] );
        $this->route( $namespace, '/automation/jobs', 'GET', [ $this, 'jobs' ], $this->listArgs( [ 'status', 'type' ] ) );
        $this->route( $namespace, '/automation/autopilot', 'GET', [ $this, 'autopilot' ] );
        $this->route( $namespace, '/automation/autopilot', 'POST', [ $this, 'setAutopilot' ] );
    }

    /** GET /automation/status @return array<string,mixed> */
    public function status( WP_REST_Request $request ): array {
        unset( $request );
        return $this->statusPayload();
    }

    /** POST /automation/run {stage, options{}} @return array<string,mixed>|\WP_Error */
    public function run( WP_REST_Request $request ) {
        $stage = sanitize_key( $this->str( $request, 'stage', '', 40 ) );
        $types = $this->types();
        if ( '' === $stage || ! isset( $types[ $stage ] ) ) {
            return $this->fail( 'lga_unknown_stage', __( 'Unknown automation stage.', 'linkgrowth-autopilot' ), 400, [ 'stages' => array_keys( $types ) ] );
        }
        $options = $this->sanitizeOptions( $this->arr( $request, 'options' ) );
        $run     = $this->c->get( Pipeline::class )->runNow( $stage, $options, self::RUN_SECONDS );
        $out     = [
            'ok'      => (bool) ( $run['result']['ok'] ?? true ),
            'stage'   => $stage,
            'label'   => self::STAGE_LABELS[ $stage ] ?? $stage,
            'result'  => $run['result'] ?? [],
            'job_id'  => $run['job_id'] ?? null,
            'status'  => $this->statusPayload(),
        ];
        // A stage you start yourself still runs while background work is paused; the answer says so.
        $paused = $this->pausedReason();
        if ( null !== $paused ) {
            $out['paused']  = $paused;
            $out['warning'] = LoadGuard::warning( $paused ) . ' This stage ran because you started it.';
        }
        return $out;
    }

    /** @return array<string,mixed>|null the server-pressure pause (Automation\LoadGuard), or null */
    private function pausedReason(): ?array {
        return $this->safe( fn(): ?array => $this->c->get( LoadGuard::class )->pauseReason(), null, 'load guard' );
    }

    /** POST /automation/toggle {key, value, confirm} @return array<string,mixed>|\WP_Error */
    public function toggle( WP_REST_Request $request ) {
        $key = sanitize_key( $this->str( $request, 'key', '', 40 ) );
        if ( ! in_array( $key, self::FLAGS, true ) ) {
            return $this->fail( 'lga_unknown_flag', __( 'Unknown automation flag.', 'linkgrowth-autopilot' ), 400, [ 'flags' => self::FLAGS ] );
        }
        if ( ! $request->has_param( 'value' ) ) {
            return $this->fail( 'lga_missing_value', __( 'A boolean value is required.', 'linkgrowth-autopilot' ), 400 );
        }
        $value = $this->bool( $request, 'value', false );
        if ( $value && in_array( $key, self::CONFIRM_FLAGS, true ) && ! $this->bool( $request, 'confirm', false ) ) {
            return $this->fail( 'lga_confirmation_required', self::confirmationMessage( $key ), 400, [ 'confirm_required' => true, 'key' => $key ] );
        }
        $settings = $this->c->get( Settings::class );
        $previous = (bool) $settings->get( 'automation.' . $key, false );
        $settings->set( 'automation.' . $key, $value );
        $this->audit( 'automation.toggled', 'settings', 0, [ 'key' => $key, 'from' => $previous, 'to' => $value, 'confirmed' => $this->bool( $request, 'confirm', false ) ] );

        if ( 'enabled' === $key && $value ) {
            $this->safe(
                function (): void {
                    if ( class_exists( Scheduler::class ) ) {
                        $this->c->get( Scheduler::class )->schedule();
                    }
                },
                null,
                'schedule after enable'
            );
        }
        $flags = [];
        foreach ( self::FLAGS as $flag ) {
            $flags[ $flag ] = (bool) $settings->get( 'automation.' . $flag, false );
        }
        return [ 'ok' => true, 'key' => $key, 'value' => $value, 'flags' => $flags ];
    }

    /**
     * Why enabling a CONFIRM_FLAGS flag needs confirmation (shared with POST /settings), or, for the key
     * 'require_approval', why POST /settings needs it to turn "Require individual approval" off while autosend is on.
     */
    public static function confirmationMessage( string $key ): string {
        if ( 'submissions' === $key ) {
            return __( 'Enabling automated submissions lets the engine submit approved listing packages to third-party sites. Confirm to proceed.', 'linkgrowth-autopilot' );
        }
        if ( 'require_approval' === $key ) {
            return __( 'Turning off “Require individual approval” while outreach auto-send is on lets the engine approve and send, in your name and without review, the first-contact emails it drafts for qualified prospects. Confirm to proceed.', 'linkgrowth-autopilot' );
        }
        return __( 'Enabling outreach auto-send lets the engine approve and send, in your name and without review, the first-contact emails it drafts for qualified prospects (while “Require individual approval” in Settings → Outreach is off) plus follow-ups in campaigns that auto-approve follow-ups. Confirm to proceed.', 'linkgrowth-autopilot' );
    }

    /** GET /automation/autopilot @return array<string,mixed> */
    public function autopilot( WP_REST_Request $request ): array {
        unset( $request );
        return $this->c->get( Autopilot::class )->status();
    }

    /**
     * POST /automation/autopilot {enabled, confirm}. Turning Autopilot on needs confirm=true (400 otherwise, with the
     * policy text to show) and records the consent; turning it off needs no confirmation and returns the messages
     * Autopilot approved but did not send yet to draft.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function setAutopilot( WP_REST_Request $request ) {
        if ( ! $request->has_param( 'enabled' ) ) {
            return $this->fail( 'lga_missing_value', __( 'A boolean "enabled" value is required.', 'linkgrowth-autopilot' ), 400 );
        }
        $autopilot = $this->c->get( Autopilot::class );
        $enable    = $this->bool( $request, 'enabled', false );
        if ( $enable && ! $this->bool( $request, 'confirm', false ) ) {
            return $this->fail( 'lga_confirmation_required', Autopilot::policyText(), 400, [ 'confirm_required' => true, 'key' => 'autopilot', 'policy_version' => Autopilot::POLICY_VERSION, 'hard_limits' => Autopilot::hardLimits() ] );
        }
        $status = $enable ? $autopilot->enable( $this->userId() ) : $autopilot->disable( $this->userId() );
        if ( $enable ) {
            // Drafts and campaigns that were already waiting are approved now, not at the next daily outreach run.
            $status['approved_now'] = self::approveWaitingNow( $this->c );
            $this->safe(
                function (): void {
                    if ( class_exists( Scheduler::class ) ) {
                        $this->c->get( Scheduler::class )->schedule();
                    }
                },
                null,
                'schedule after autopilot'
            );
        }
        return array_merge( [ 'ok' => true ], $status );
    }

    /**
     * Approves (as Autopilot) the campaigns and drafts that may be approved automatically, within a short budget;
     * the auto outreach stage approves the rest on its next run. Returns the number of messages approved.
     */
    public static function approveWaitingNow( \LinkGrowth\Autopilot\Container $c ): int {
        try {
            $r = $c->get( CampaignFiller::class )->approveWaiting( TimeBudget::seconds( 10.0 ), 200 );
            return (int) ( $r['messages'] ?? 0 );
        } catch ( \Throwable $e ) {
            return 0;
        }
    }

    /** POST /automation/tick @return array<string,mixed> */
    public function tick( WP_REST_Request $request ): array {
        unset( $request );
        $result = $this->c->get( Pipeline::class )->tick();
        $this->audit( 'automation.tick_manual', 'job', 0, [ 'enqueued' => $result['enqueued'] ?? [], 'run' => $result['run'] ?? [] ] );
        return [ 'ok' => true, 'tick' => $result, 'status' => $this->statusPayload() ];
    }

    /** POST /automation/kick @return array<string,mixed>|\WP_Error */
    public function kick( WP_REST_Request $request ) {
        unset( $request );
        if ( ! class_exists( Scheduler::class ) ) {
            return $this->fail( 'lga_scheduler_unavailable', __( 'The scheduler is not available.', 'linkgrowth-autopilot' ), 503 );
        }
        $scheduler = $this->c->get( Scheduler::class );
        // Re-register the recurring tick first (as the dashboard promises), so a lost schedule is repaired now
        // instead of after the hourly self-heal; the async kick queued afterwards cannot mask a missing recurrence.
        $this->safe( static function () use ( $scheduler ): void { $scheduler->schedule(); }, null, 'schedule on kick' );
        $paused = $this->pausedReason();
        if ( null !== $paused ) {
            // Kicks add no background load while the server is under pressure; the recurring tick resumes the work.
            $this->audit( 'automation.kicked', 'job', 0, [ 'ok' => false, 'paused' => (string) $paused['code'] ] );
            return [
                'ok'        => true,
                'kicked'    => false,
                'paused'    => $paused,
                'message'   => LoadGuard::warning( $paused ) . ' No extra tick was started.',
                'scheduler' => $this->safe( fn(): array => $this->c->get( Scheduler::class )->status(), null, 'scheduler status' ),
            ];
        }
        $ok = (bool) $scheduler->kick();
        $this->audit( 'automation.kicked', 'job', 0, [ 'ok' => $ok ] );
        return [
            'ok'        => $ok,
            'kicked'    => $ok,
            'message'   => $ok ? __( 'A background tick has been requested.', 'linkgrowth-autopilot' ) : __( 'The background tick could not be triggered; run a tick manually instead.', 'linkgrowth-autopilot' ),
            'scheduler' => $this->safe( fn(): array => $this->c->get( Scheduler::class )->status(), null, 'scheduler status' ),
        ];
    }

    /** POST /automation/cancel {type?} @return array<string,mixed> */
    public function cancel( WP_REST_Request $request ): array {
        $type = sanitize_key( $this->str( $request, 'type', '', 40 ) );
        $n    = $this->c->get( Pipeline::class )->cancelQueued( '' === $type ? null : $type );
        return [ 'ok' => true, 'cancelled' => $n, 'type' => '' === $type ? null : $type, 'status' => $this->statusPayload() ];
    }

    /** GET /automation/jobs @return array<string,mixed> */
    public function jobs( WP_REST_Request $request ): array {
        $pg    = $this->paginationArgs( $request, 'id', 'DESC' );
        $where = [];
        $status = $this->keyList( $request, 'status' );
        if ( [] !== $status ) {
            $where['status'] = count( $status ) > 1 ? $status : $status[0];
        }
        $type = sanitize_key( $this->str( $request, 'type', '', 40 ) );
        if ( '' !== $type ) {
            $where['type'] = $type;
        }
        $repo = $this->c->get( Jobs::class );
        $page = $repo->paginate( $where, $pg['q'], [ 'type', 'error' ], $pg['page'], $pg['per_page'], $pg['orderby'], $pg['order'] );
        $page['items'] = array_map(
            static function ( array $job ): array {
                $job          = self::castInts( $job, [ 'priority', 'attempts', 'max_attempts' ] );
                $job['label'] = self::STAGE_LABELS[ (string) ( $job['type'] ?? '' ) ] ?? (string) ( $job['type'] ?? '' );
                unset( $job['lock_token'] );
                return $job;
            },
            $page['items']
        );
        $page['facets'] = $this->safe( static fn(): array => [ 'status' => $repo->statusCounts(), 'type' => $repo->countBy( 'type' ) ], [], 'job facets' );
        return $page;
    }

    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function statusPayload(): array {
        $settings = $this->c->get( Settings::class );
        $pipeline = $this->safe( fn(): array => $this->c->get( Pipeline::class )->status(), [], 'pipeline status' );
        $flags    = [];
        foreach ( self::FLAGS as $flag ) {
            $flags[ $flag ] = (bool) $settings->get( 'automation.' . $flag, false );
        }
        $types  = $this->types();
        $stages = [];
        foreach ( array_keys( $types ) as $type ) {
            $stages[ $type ] = [
                'type'  => $type,
                'label' => self::STAGE_LABELS[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) ),
            ];
        }
        return array_merge(
            is_array( $pipeline ) ? $pipeline : [],
            [
                'automation_enabled' => (bool) $settings->get( 'automation.enabled', false ),
                'flags'              => $flags,
                'limits'             => $pipeline['limits'] ?? $this->safe( fn(): array => $this->c->get( Limits::class )->snapshot(), [], 'limits' ),
                'scheduler'          => $this->safe( fn(): ?array => class_exists( Scheduler::class ) ? $this->c->get( Scheduler::class )->status() : null, null, 'scheduler status' ),
                'job_types'          => array_keys( $types ),
                'stage_labels'       => $stages,
                'confirm_flags'      => self::CONFIRM_FLAGS,
                'autopilot'          => $this->safe( fn(): array => $this->c->get( Autopilot::class )->status(), null, 'autopilot status' ),
                'settings'           => [
                    // What the runner actually uses: the setting, raised to JobRunner::MIN_TICK_SECONDS.
                    'max_execution_seconds' => (int) ceil( JobRunner::effectiveMaxSeconds( $settings ) ),
                    'tick_interval'         => (int) $settings->get( 'automation.tick_interval', 300 ),
                    'min_score_auto_queue'  => (int) $settings->get( 'automation.min_score_auto_queue', 70 ),
                    'min_score_keep'        => (int) $settings->get( 'automation.min_score_keep', 50 ),
                ],
            ]
        );
    }

    /** @return array<string,string> type => class */
    private function types(): array {
        return $this->safe( fn(): array => $this->c->get( JobRunner::class )->types(), [], 'job types' );
    }

    /**
     * Options for a manual stage run: scalar values only, known-safe keys, shallow.
     *
     * @param array<mixed> $options
     * @return array<string,mixed>
     */
    private function sanitizeOptions( array $options ): array {
        $out = [];
        $n   = 0;
        foreach ( $options as $key => $value ) {
            if ( ++$n > 20 || ! is_string( $key ) ) {
                continue;
            }
            $key = sanitize_key( $key );
            if ( '' === $key || 'manual' === $key ) {
                continue;
            }
            if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
                $out[ $key ] = $value;
            } elseif ( is_string( $value ) ) {
                $out[ $key ] = mb_substr( sanitize_text_field( $value ), 0, 500 );
            } elseif ( is_array( $value ) ) {
                $list = [];
                foreach ( array_slice( $value, 0, 100 ) as $v ) {
                    if ( is_scalar( $v ) ) {
                        $list[] = is_string( $v ) ? mb_substr( sanitize_text_field( $v ), 0, 500 ) : $v;
                    }
                }
                $out[ $key ] = $list;
            }
        }
        return $out;
    }
}
