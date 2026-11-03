<?php
/**
 * /automation — pipeline status, manual stage runs, flag toggles, scheduler controls and the job list.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Admin\Rest;

use PPC\LinkGrowth\Automation\JobRunner;
use PPC\LinkGrowth\Automation\Pipeline;
use PPC\LinkGrowth\Automation\Scheduler;
use PPC\LinkGrowth\Repositories\Jobs;
use PPC\LinkGrowth\Support\Limits;
use PPC\LinkGrowth\Support\Settings;
use WP_REST_Request;

final class AutomationController extends Controller {

    /** Flags that may be toggled; `enabled` is the master switch. */
    private const FLAGS = [ 'enabled', 'discovery', 'evaluation', 'opportunities', 'packaging', 'submissions', 'outreach_autosend', 'monitoring', 'imports' ];

    /** Flags whose activation performs real-world side effects and therefore needs explicit confirmation. */
    private const CONFIRM_FLAGS = [ 'submissions', 'outreach_autosend' ];

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
            return $this->fail( 'ppclg_unknown_stage', __( 'Unknown automation stage.', 'ppc-link-growth-engine' ), 400, [ 'stages' => array_keys( $types ) ] );
        }
        $options = $this->sanitizeOptions( $this->arr( $request, 'options' ) );
        $run     = $this->c->get( Pipeline::class )->runNow( $stage, $options, self::RUN_SECONDS );
        return [
            'ok'      => (bool) ( $run['result']['ok'] ?? true ),
            'stage'   => $stage,
            'label'   => self::STAGE_LABELS[ $stage ] ?? $stage,
            'result'  => $run['result'] ?? [],
            'job_id'  => $run['job_id'] ?? null,
            'status'  => $this->statusPayload(),
        ];
    }

    /** POST /automation/toggle {key, value, confirm} @return array<string,mixed>|\WP_Error */
    public function toggle( WP_REST_Request $request ) {
        $key = sanitize_key( $this->str( $request, 'key', '', 40 ) );
        if ( ! in_array( $key, self::FLAGS, true ) ) {
            return $this->fail( 'ppclg_unknown_flag', __( 'Unknown automation flag.', 'ppc-link-growth-engine' ), 400, [ 'flags' => self::FLAGS ] );
        }
        if ( ! $request->has_param( 'value' ) ) {
            return $this->fail( 'ppclg_missing_value', __( 'A boolean value is required.', 'ppc-link-growth-engine' ), 400 );
        }
        $value = $this->bool( $request, 'value', false );
        if ( $value && in_array( $key, self::CONFIRM_FLAGS, true ) && ! $this->bool( $request, 'confirm', false ) ) {
            return $this->fail(
                'ppclg_confirmation_required',
                'submissions' === $key
                    ? __( 'Enabling automated submissions lets the engine submit approved listing packages to third-party sites. Confirm to proceed.', 'ppc-link-growth-engine' )
                    : __( 'Enabling outreach auto-send lets the engine send individually approved emails and scheduled follow-ups without further review. Confirm to proceed.', 'ppc-link-growth-engine' ),
                400,
                [ 'confirm_required' => true, 'key' => $key ]
            );
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
            return $this->fail( 'ppclg_scheduler_unavailable', __( 'The scheduler is not available.', 'ppc-link-growth-engine' ), 503 );
        }
        $ok = (bool) $this->c->get( Scheduler::class )->kick();
        $this->audit( 'automation.kicked', 'job', 0, [ 'ok' => $ok ] );
        return [
            'ok'        => $ok,
            'message'   => $ok ? __( 'A background tick has been requested.', 'ppc-link-growth-engine' ) : __( 'The background tick could not be triggered; run a tick manually instead.', 'ppc-link-growth-engine' ),
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
                'settings'           => [
                    'max_execution_seconds' => (int) $settings->get( 'automation.max_execution_seconds', 20 ),
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
