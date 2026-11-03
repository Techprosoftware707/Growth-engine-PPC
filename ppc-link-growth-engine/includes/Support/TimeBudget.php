<?php
/**
 * Wall-clock budget for batch jobs (keeps under PHP max_execution_time).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Support;

final class TimeBudget {

    private float $start;

    public function __construct( private float $seconds ) {
        $this->start = microtime( true );
    }

    public static function seconds( float $seconds ): self {
        return new self( $seconds );
    }

    public function elapsed(): float {
        return microtime( true ) - $this->start;
    }

    public function remaining(): float {
        return max( 0.0, $this->seconds - $this->elapsed() );
    }

    public function expired(): bool {
        return $this->remaining() <= 0.0;
    }

    /** True when at least $seconds remain. */
    public function has( float $seconds ): bool {
        return $this->remaining() >= $seconds;
    }
}
