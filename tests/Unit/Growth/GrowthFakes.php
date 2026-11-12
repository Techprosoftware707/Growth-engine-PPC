<?php
/**
 * Fakes for the growth-run tests: deterministic job types (one class per job type, all sharing one scripted
 * behaviour) and a duck-typed storefront audit registered in the test container.
 *
 * Required from the Growth tests (not a test case itself).
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Growth;

use PPC\LinkGrowth\Automation\Jobs\AbstractJob;
use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Support\TimeBudget;

/**
 * Scripted job: records each run, returns `checked` => 5 (plus any scripted result) and, while `more` > 0, queues a
 * continuation through AbstractJob::continueLater() (scheduled 60 s ahead, like the real jobs).
 */
abstract class GrowthFakeJob extends AbstractJob {

    /** @var array<string,array<string,mixed>> type => { more?:int, throw?:bool, result?:array, before?:callable( payload ) } */
    public static array $script = [];

    /** @var array<int,array{type:string,payload:array<string,mixed>}> every run, in order */
    public static array $runs = [];

    public static function reset(): void {
        self::$script = [];
        self::$runs   = [];
    }

    /** @return string[] job types in the order they first ran */
    public static function order(): array {
        $out = [];
        foreach ( self::$runs as $run ) {
            if ( ! in_array( $run['type'], $out, true ) ) {
                $out[] = $run['type'];
            }
        }
        return $out;
    }

    public static function count( string $type ): int {
        return count( array_filter( self::$runs, static fn( array $r ): bool => $r['type'] === $type ) );
    }

    /** @return array<string,mixed> */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        $type          = static::type();
        self::$runs[]  = [ 'type' => $type, 'payload' => $payload ];
        $script        = self::$script[ $type ] ?? [];
        if ( isset( $script['before'] ) && is_callable( $script['before'] ) ) {
            ( $script['before'] )( $payload );
        }
        if ( ! empty( $script['throw'] ) ) {
            throw new \RuntimeException( 'Scripted failure in ' . $type );
        }
        $result = array_merge( [ 'checked' => 5 ], is_array( $script['result'] ?? null ) ? $script['result'] : [] );
        $more   = (int) ( $script['more'] ?? 0 );
        if ( $more > 0 ) {
            self::$script[ $type ]['more'] = $more - 1;
            $result['more']                = true;
            $result['continued']           = null !== $this->continueLater( $c, $payload, 60 );
        } else {
            $result['more'] = false;
        }
        return $this->result( $result );
    }

    /**
     * type => class map for the ppclg_job_types filter.
     *
     * @param string[] $except job types to leave out (unregistered)
     * @return array<string,class-string>
     */
    public static function registry( array $except = [] ): array {
        $all = [
            FakeImportVerifiedJob::class,
            FakeSiteAuditJob::class,
            FakeIndexNowJob::class,
            FakeInternalLinksJob::class,
            FakeDiscoverJob::class,
            FakeMentionScanJob::class,
            FakeBrokenLinkScanJob::class,
            FakeEvaluateJob::class,
            FakeFindOpportunitiesJob::class,
            FakePackageJob::class,
            FakeSubmitJob::class,
            FakeVerifyListingsJob::class,
            FakeAutoOutreachJob::class,
            FakeMonitorBacklinksJob::class,
            FakeImportBacklinksJob::class,
            FakeMetricsRefreshJob::class,
            FakeOtherJob::class,
        ];
        $out = [];
        foreach ( $all as $class ) {
            if ( ! in_array( $class::type(), $except, true ) ) {
                $out[ $class::type() ] = $class;
            }
        }
        return $out;
    }
}

final class FakeImportVerifiedJob extends GrowthFakeJob {
    public static function type(): string {
        return 'import_verified';
    }
}
final class FakeSiteAuditJob extends GrowthFakeJob {
    public static function type(): string {
        return 'site_audit';
    }
}
final class FakeIndexNowJob extends GrowthFakeJob {
    public static function type(): string {
        return 'indexnow';
    }
}
final class FakeInternalLinksJob extends GrowthFakeJob {
    public static function type(): string {
        return 'internal_links';
    }
}
final class FakeDiscoverJob extends GrowthFakeJob {
    public static function type(): string {
        return 'discover';
    }
}
final class FakeMentionScanJob extends GrowthFakeJob {
    public static function type(): string {
        return 'mention_scan';
    }
}
final class FakeBrokenLinkScanJob extends GrowthFakeJob {
    public static function type(): string {
        return 'broken_link_scan';
    }
}
final class FakeEvaluateJob extends GrowthFakeJob {
    public static function type(): string {
        return 'evaluate';
    }
}
final class FakeFindOpportunitiesJob extends GrowthFakeJob {
    public static function type(): string {
        return 'find_opportunities';
    }
}
final class FakePackageJob extends GrowthFakeJob {
    public static function type(): string {
        return 'package';
    }
}
final class FakeSubmitJob extends GrowthFakeJob {
    public static function type(): string {
        return 'submit';
    }
}
final class FakeVerifyListingsJob extends GrowthFakeJob {
    public static function type(): string {
        return 'verify_listings';
    }
}
final class FakeAutoOutreachJob extends GrowthFakeJob {
    public static function type(): string {
        return 'auto_outreach';
    }
}
final class FakeMonitorBacklinksJob extends GrowthFakeJob {
    public static function type(): string {
        return 'monitor_backlinks';
    }
}
final class FakeImportBacklinksJob extends GrowthFakeJob {
    public static function type(): string {
        return 'import_backlinks';
    }
}
final class FakeMetricsRefreshJob extends GrowthFakeJob {
    public static function type(): string {
        return 'metrics_refresh';
    }
}
/** A type that is not a growth step (for the claimNext / runQueuedOfType filters). */
final class FakeOtherJob extends GrowthFakeJob {
    public static function type(): string {
        return 'other_stage';
    }
}

/**
 * Duck-typed stand-in for Compliance\StorefrontAudit (which is final), registered in the test container.
 */
final class FakeStorefront {

    public bool $blocking = false;

    public bool $throwOnRun = false;

    public bool $throwOnGate = false;

    public int $runs = 0;

    /** @return array<string,mixed> */
    public function run(): array {
        $this->runs++;
        if ( $this->throwOnRun ) {
            throw new \RuntimeException( 'storefront unreadable' );
        }
        return [
            'status'     => $this->blocking ? 'block' : 'pass',
            'checked_at' => gmdate( 'c' ),
            'hash'       => 'abc',
            'counts'     => [ 'block' => $this->blocking ? 2 : 0, 'warn' => 1 ],
            'findings'   => [],
        ];
    }

    public function isBlocking(): bool {
        if ( $this->throwOnGate ) {
            throw new \RuntimeException( 'gate unreadable' );
        }
        return $this->blocking;
    }

    public function blockingReason(): string {
        return $this->blocking ? 'Two product categories present the products for consumer use.' : '';
    }

    public function effectiveStatus(): string {
        return $this->blocking ? 'block' : 'warn';
    }

    /** @return array<string,mixed> */
    public function summary(): array {
        return [
            'status'    => $this->blocking ? 'block' : 'warn',
            'effective' => $this->effectiveStatus(),
            'blocking'  => $this->blocking,
            'counts'    => [ 'block' => $this->blocking ? 2 : 0, 'warn' => 1 ],
        ];
    }
}
