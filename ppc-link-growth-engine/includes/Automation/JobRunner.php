<?php
/**
 * Claims queued jobs under a lock and executes them with child time budgets.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation;

use PPC\LinkGrowth\Automation\Jobs\BrokenLinkScanJob;
use PPC\LinkGrowth\Automation\Jobs\CleanupJob;
use PPC\LinkGrowth\Automation\Jobs\DiscoverJob;
use PPC\LinkGrowth\Automation\Jobs\EvaluateJob;
use PPC\LinkGrowth\Automation\Jobs\FindOpportunitiesJob;
use PPC\LinkGrowth\Automation\Jobs\FollowUpJob;
use PPC\LinkGrowth\Automation\Jobs\ImportBacklinksJob;
use PPC\LinkGrowth\Automation\Jobs\JobInterface;
use PPC\LinkGrowth\Automation\Jobs\MentionScanJob;
use PPC\LinkGrowth\Automation\Jobs\MetricsRefreshJob;
use PPC\LinkGrowth\Automation\Jobs\MonitorBacklinksJob;
use PPC\LinkGrowth\Automation\Jobs\OutreachSendJob;
use PPC\LinkGrowth\Automation\Jobs\PackageJob;
use PPC\LinkGrowth\Automation\Jobs\SubmitJob;
use PPC\LinkGrowth\Automation\Jobs\VerifyListingsJob;
use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\Jobs;
use PPC\LinkGrowth\Support\Crypto;
use PPC\LinkGrowth\Support\Locks;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use Throwable;

final class JobRunner {

    /** Name of the lock that serialises job execution across requests. */
    public const LOCK = 'runner';

    /** Option holding the latest result per job type (for the automation dashboard). */
    public const OPTION_STAGE_RESULTS = 'ppclg_stage_results';

    /** Minimum seconds that must remain in the parent budget before another job is claimed (child budget ≥ 4s). */
    private const MIN_CLAIM_SECONDS = 6.0;

    /** Seconds reserved for bookkeeping after a job returns. */
    private const RESERVE_SECONDS = 2.0;

    private const FALLBACK_MAX_SECONDS = 20.0;

    /** Built-in job classes. @var array<int,class-string<JobInterface>> */
    private const BUILTIN = [
        DiscoverJob::class,
        EvaluateJob::class,
        FindOpportunitiesJob::class,
        PackageJob::class,
        SubmitJob::class,
        VerifyListingsJob::class,
        OutreachSendJob::class,
        FollowUpJob::class,
        MonitorBacklinksJob::class,
        ImportBacklinksJob::class,
        MentionScanJob::class,
        BrokenLinkScanJob::class,
        CleanupJob::class,
        MetricsRefreshJob::class,
    ];

    public function __construct(
        private Container $c,
        private Jobs $jobs,
        private Locks $locks,
        private Settings $settings,
        private Logger $logger,
        private AuditLog $audit
    ) {}

    /** The service container the jobs run against. */
    public function container(): Container {
        return $this->c;
    }

    /**
     * Registered job types (filterable via `ppclg_job_types`: type => class-string).
     *
     * @return array<string,class-string<JobInterface>>
     */
    public function types(): array {
        $types = [];
        foreach ( self::BUILTIN as $class ) {
            $types[ $class::type() ] = $class;
        }

        /**
         * Filters the job type registry.
         *
         * @param array<string,class-string<JobInterface>> $types type => class implementing JobInterface
         */
        $filtered = apply_filters( 'ppclg_job_types', $types );

        $out = [];
        foreach ( (array) $filtered as $type => $class ) {
            $type = sanitize_key( (string) $type );
            if ( '' === $type || ! is_string( $class ) || ! class_exists( $class ) || ! is_subclass_of( $class, JobInterface::class ) ) {
                continue;
            }
            $out[ $type ] = $class;
        }
        return $out;
    }

    /**
     * Runs queued jobs until the budget is spent or the queue is empty.
     *
     * @return array{ran:int,completed:int,failed:int,skipped:bool}
     */
    public function run( TimeBudget $budget ): array {
        $out = [ 'ran' => 0, 'completed' => 0, 'failed' => 0, 'skipped' => false ];
        $ttl = (int) ceil( $budget->remaining() ) + 60;
        if ( ! $this->lock( $ttl ) ) {
            $out['skipped'] = true;
            $this->logger->debug( 'jobs', 'Runner lock busy; skipping this run.' );
            return $out;
        }
        $token = Crypto::randomToken( 8 );
        try {
            while ( $budget->has( self::MIN_CLAIM_SECONDS ) ) {
                $job = $this->jobs->claimNext( $token );
                if ( null === $job ) {
                    break;
                }
                $out['ran']++;
                if ( $this->execute( $job, $budget ) ) {
                    $out['completed']++;
                } else {
                    $out['failed']++;
                }
            }
        } finally {
            $this->unlock();
        }
        return $out;
    }

    /**
     * Runs a job type immediately, bypassing the queue (caller is responsible for locking).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed> The job result (with ok=false and error on failure).
     */
    public function runType( string $type, array $payload, TimeBudget $budget ): array {
        $class = $this->types()[ $type ] ?? null;
        if ( null === $class ) {
            return [ 'ok' => false, 'error' => sprintf( 'Unknown job type "%s".', $type ), 'job' => $type ];
        }
        $started = microtime( true );
        try {
            $result            = ( new $class() )->handle( $payload, $budget, $this->c );
            $result['ok']      = array_key_exists( 'ok', $result ) ? (bool) $result['ok'] : true;
            $result['elapsed'] = round( microtime( true ) - $started, 2 );
            $this->rememberResult( $type, 'completed', $result, null );
            return $result;
        } catch ( Throwable $e ) {
            $this->logger->error( 'jobs', 'Direct job run failed', [ 'type' => $type, 'error' => $e->getMessage(), 'at' => basename( $e->getFile() ) . ':' . $e->getLine() ] );
            $this->audit->record( AuditLog::currentActor(), 'job.failed', 'job', 0, [ 'type' => $type, 'error' => $e->getMessage(), 'direct' => true ] );
            $result = [ 'ok' => false, 'error' => $e->getMessage(), 'job' => $type, 'elapsed' => round( microtime( true ) - $started, 2 ) ];
            $this->rememberResult( $type, 'failed', $result, null );
            return $result;
        }
    }

    /** Acquires the runner lock for $ttl seconds. */
    public function lock( int $ttl ): bool {
        return $this->locks->acquire( self::LOCK, max( 30, $ttl ) );
    }

    public function unlock(): void {
        $this->locks->release( self::LOCK );
    }

    public function isLocked(): bool {
        return $this->locks->isLocked( self::LOCK );
    }

    /**
     * @param array<string,mixed> $job Claimed job row.
     */
    private function execute( array $job, TimeBudget $budget ): bool {
        $id          = (int) $job['id'];
        $type        = (string) ( $job['type'] ?? '' );
        $payload     = is_array( $job['payload'] ?? null ) ? $job['payload'] : [];
        $attempts    = max( 1, (int) ( $job['attempts'] ?? 1 ) );
        $maxAttempts = max( 1, (int) ( $job['max_attempts'] ?? 3 ) );
        $class       = $this->types()[ $type ] ?? null;

        if ( null === $class ) {
            $this->jobs->fail( $id, sprintf( 'Unknown job type "%s".', $type ), false );
            $this->logger->error( 'jobs', 'Unknown job type claimed', [ 'id' => $id, 'type' => $type ] );
            return false;
        }

        $child   = TimeBudget::seconds( $this->childSeconds( $budget ) );
        $started = microtime( true );
        try {
            $result            = ( new $class() )->handle( $payload, $child, $this->c );
            $result['elapsed'] = round( microtime( true ) - $started, 2 );
            $this->jobs->complete( $id, $result );
            $this->rememberResult( $type, 'completed', $result, $id );
            $this->logger->debug( 'jobs', 'Job completed', [ 'id' => $id, 'type' => $type, 'elapsed' => $result['elapsed'] ] );
            return true;
        } catch ( Throwable $e ) {
            $retry = $attempts < $maxAttempts;
            $this->jobs->fail( $id, $e->getMessage(), $retry, 300 * $attempts );
            $this->logger->error(
                'jobs',
                'Job failed',
                [ 'id' => $id, 'type' => $type, 'attempt' => $attempts, 'retry' => $retry, 'error' => $e->getMessage(), 'at' => basename( $e->getFile() ) . ':' . $e->getLine() ]
            );
            $this->audit->record( 'system', 'job.failed', 'job', $id, [ 'type' => $type, 'attempt' => $attempts, 'retry' => $retry, 'error' => $e->getMessage() ] );
            $this->rememberResult( $type, $retry ? 'retrying' : 'failed', [ 'ok' => false, 'error' => $e->getMessage(), 'attempt' => $attempts ], $id );
            return false;
        }
    }

    /** Child budget: min(remaining - reserve, settings automation.max_execution_seconds), at least 1s. */
    private function childSeconds( TimeBudget $budget ): float {
        $max = (float) $this->settings->get( 'automation.max_execution_seconds', self::FALLBACK_MAX_SECONDS );
        if ( $max <= 0 ) {
            $max = self::FALLBACK_MAX_SECONDS;
        }
        return max( 1.0, min( $budget->remaining() - self::RESERVE_SECONDS, $max ) );
    }

    /**
     * Keeps the last result per job type in a small (non-autoloaded) option for the dashboard.
     *
     * @param array<string,mixed> $result
     */
    private function rememberResult( string $type, string $status, array $result, ?int $jobId ): void {
        try {
            $all = get_option( self::OPTION_STAGE_RESULTS, [] );
            if ( ! is_array( $all ) ) {
                $all = [];
            }
            $json = wp_json_encode( $result );
            if ( ! is_string( $json ) || strlen( $json ) > 6000 ) {
                $result = self::summarise( $result );
            }
            $all[ $type ] = [
                'status'      => $status,
                'job_id'      => $jobId,
                'finished_at' => gmdate( 'c' ),
                'result'      => $result,
            ];
            update_option( self::OPTION_STAGE_RESULTS, $all, false );
        } catch ( Throwable $e ) {
            // Purely informational; never let bookkeeping break a run.
            unset( $e );
        }
    }

    /**
     * Scalar top-level values only (used when a result is too large to keep in an option).
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function summarise( array $result ): array {
        $out = [ 'truncated' => true ];
        foreach ( $result as $k => $v ) {
            if ( is_scalar( $v ) || null === $v ) {
                $out[ $k ] = is_string( $v ) ? mb_substr( $v, 0, 200 ) : $v;
            } elseif ( is_array( $v ) ) {
                $out[ $k ] = sprintf( '[%d items]', count( $v ) );
            }
        }
        return $out;
    }
}
