<?php
/**
 * How hard the server is working: the 1-minute load average, the number of CPUs and the memory (MemTotal,
 * MemAvailable), read as cheaply as PHP allows and only when someone asks: Automation\LoadGuard while it decides a
 * pause (scheduler tick, kick, Grow now step, the admin status), Admin\SiteHealth for its OPcache advice. Never on an
 * ordinary page view.
 *
 * Linux only, through what the kernel provides: sys_getloadavg() for the load, /proc/meminfo for the memory, and
 * /sys/devices/system/cpu/online (a few bytes, e.g. "0-3") or else the "processor" lines of /proc/cpuinfo for the CPU
 * count. Each file is one small read (a byte limit, no loop) behind an @-guarded is_readable(), with warnings
 * suppressed, so a path outside open_basedir, a missing file, a disabled function or another operating system reads
 * as "unknown" (null), and unknown never pauses anything or changes any advice. Values are read at most once per
 * instance (one per request through the container); forget() reads them again. The CPU count is kept for a day in
 * the non-autoloaded option ppclg_cpu_count, so its files are read about once a day (a resized server is noticed
 * the next day).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Support;

use Closure;
use Throwable;

final class ServerResources {

    /** Non-autoloaded: { count, known, source (sysfs|cpuinfo|unknown), checked (unix time) }. */
    public const OPTION_CPUS = 'ppclg_cpu_count';

    /** How long the stored CPU count is used before the files are read again. */
    public const CPU_CACHE_SECONDS = 86400;

    public const MEMINFO    = '/proc/meminfo';
    public const CPU_ONLINE = '/sys/devices/system/cpu/online';
    public const CPUINFO    = '/proc/cpuinfo';

    /** MemTotal, MemFree and MemAvailable are the first three lines of /proc/meminfo (about 80 bytes). */
    public const MEMINFO_BYTES = 4096;

    /** "0-3", or a list of ranges such as "0-1,4-5". */
    public const CPU_ONLINE_BYTES = 256;

    /** About 1.5 KB per CPU on x86; a longer file is cut there, which can only count fewer CPUs (pauses sooner). */
    public const CPUINFO_BYTES = 65536;

    /** @var Closure(string,int):mixed|null */
    private ?Closure $reader;

    /** @var Closure():mixed|null */
    private ?Closure $loadReader;

    private string $os;

    /** @var array<string,mixed> what this instance has read: load, cpus, memory */
    private array $read = [];

    /**
     * @param callable|null $reader     fn( string $path, int $maxBytes ): ?string, the file contents or null (tests inject one); null = the real files.
     * @param callable|null $loadReader fn(): mixed, what sys_getloadavg() returns (tests inject one); null = sys_getloadavg().
     * @param string|null   $os         PHP_OS_FAMILY (tests inject one).
     */
    public function __construct( ?callable $reader = null, ?callable $loadReader = null, ?string $os = null ) {
        $this->reader     = null === $reader ? null : Closure::fromCallable( $reader );
        $this->loadReader = null === $loadReader ? null : Closure::fromCallable( $loadReader );
        $this->os         = $os ?? PHP_OS_FAMILY;
    }

    /** Reads everything again on the next call (the stored CPU count stays). */
    public function forget(): void {
        $this->read = [];
    }

    /**
     * The 1-minute load average, or null when it is not known. Linux only: elsewhere the CPU count cannot be read
     * the same way, and a load average without it would pause background work on any multi-core machine.
     */
    public function load(): ?float {
        if ( array_key_exists( 'load', $this->read ) ) {
            return $this->read['load'];
        }
        $load = null;
        if ( 'Linux' === $this->os ) {
            $raw = $this->quietly(
                function () {
                    if ( null !== $this->loadReader ) {
                        return ( $this->loadReader )();
                    }
                    return function_exists( 'sys_getloadavg' ) ? @sys_getloadavg() : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                }
            );
            $first = is_array( $raw ) ? ( array_values( $raw )[0] ?? null ) : null;
            if ( ( is_int( $first ) || is_float( $first ) || ( is_string( $first ) && is_numeric( $first ) ) ) && is_finite( (float) $first ) && (float) $first >= 0 ) {
                $load = (float) $first;
            }
        }
        $this->read['load'] = $load;
        return $load;
    }

    /**
     * The number of CPUs: from /sys/devices/system/cpu/online, else the "processor" lines of /proc/cpuinfo, kept for
     * a day in ppclg_cpu_count. When neither can be read (open_basedir, a container without /sys and /proc) the
     * count is 1 with known false: counting a busy server as smaller than it is only pauses background work sooner,
     * while counting a 1-CPU server (the live store) as larger would let the work pile onto it.
     *
     * @return array{count:int,known:bool,source:string}
     */
    public function cpus(): array {
        if ( isset( $this->read['cpus'] ) ) {
            return $this->read['cpus'];
        }
        $now    = time();
        $stored = $this->quietly( static fn() => get_option( self::OPTION_CPUS, null ) );
        if ( is_array( $stored ) ) {
            $checked = (int) ( $stored['checked'] ?? 0 );
            $count   = (int) ( $stored['count'] ?? 0 );
            if ( $count >= 1 && $checked > $now - self::CPU_CACHE_SECONDS && $checked <= $now + 60 ) {
                return $this->read['cpus'] = [ 'count' => $count, 'known' => ! empty( $stored['known'] ), 'source' => (string) ( $stored['source'] ?? '' ) ];
            }
        }
        $source = 'sysfs';
        $count  = self::countOnline( $this->file( self::CPU_ONLINE, self::CPU_ONLINE_BYTES ) );
        if ( null === $count ) {
            $source = 'cpuinfo';
            $count  = self::countProcessors( $this->file( self::CPUINFO, self::CPUINFO_BYTES ) );
        }
        $cpus = null === $count
            ? [ 'count' => 1, 'known' => false, 'source' => 'unknown' ]
            : [ 'count' => $count, 'known' => true, 'source' => $source ];
        $this->quietly( static fn() => update_option( self::OPTION_CPUS, $cpus + [ 'checked' => $now ], false ) );
        return $this->read['cpus'] = $cpus;
    }

    /**
     * MemTotal and MemAvailable from /proc/meminfo in bytes (available null when the kernel does not report it,
     * before Linux 3.14), or null when the memory is not known.
     *
     * @return array{total:int,available:?int}|null
     */
    public function memory(): ?array {
        if ( array_key_exists( 'memory', $this->read ) ) {
            return $this->read['memory'];
        }
        $memory = null;
        $raw    = $this->file( self::MEMINFO, self::MEMINFO_BYTES );
        if ( null !== $raw && 1 === preg_match( '/^MemTotal:\s+(\d{1,15})\s*kB\b/m', $raw, $total ) && (int) $total[1] > 0 ) {
            $bytes     = (int) $total[1] * 1024;
            $available = 1 === preg_match( '/^MemAvailable:\s+(\d{1,15})\s*kB\b/m', $raw, $avail ) ? min( $bytes, (int) $avail[1] * 1024 ) : null;
            $memory    = [ 'total' => $bytes, 'available' => $available ];
        }
        $this->read['memory'] = $memory;
        return $memory;
    }

    /** MemTotal in bytes, or null when it is not known. */
    public function totalMemory(): ?int {
        $memory = $this->memory();
        return null === $memory ? null : $memory['total'];
    }

    // ------------------------------------------------------------------

    /** "0-3" => 4, "0" => 1, "0-1,4-5" => 4; anything else => null. */
    public static function countOnline( ?string $raw ): ?int {
        $raw = null === $raw ? '' : trim( $raw );
        if ( 1 !== preg_match( '/^\d{1,5}(?:-\d{1,5})?(?:,\d{1,5}(?:-\d{1,5})?)*$/', $raw ) ) {
            return null;
        }
        $count = 0;
        foreach ( explode( ',', $raw ) as $range ) {
            $ends   = array_map( 'intval', explode( '-', $range ) );
            $count += count( $ends ) > 1 ? max( 0, $ends[1] - $ends[0] + 1 ) : 1;
        }
        return $count >= 1 && $count <= 65536 ? $count : null;
    }

    /** The number of "processor : N" lines (x86, ARM, RISC-V; "processor 0:" on s390), or null. */
    public static function countProcessors( ?string $raw ): ?int {
        if ( null === $raw ) {
            return null;
        }
        $count = (int) preg_match_all( '/^processor\s*(?:\d+\s*)?:/m', $raw );
        return $count >= 1 ? $count : null;
    }

    /** One read of at most $max bytes, or null (unreadable, outside open_basedir, empty). */
    private function file( string $path, int $max ): ?string {
        $raw = $this->quietly(
            function () use ( $path, $max ) {
                if ( null !== $this->reader ) {
                    return ( $this->reader )( $path, $max );
                }
                // is_readable() warns for a path outside open_basedir; the @ and the handler keep that quiet.
                if ( ! @is_readable( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                    return null;
                }
                return @file_get_contents( $path, false, null, 0, $max ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            }
        );
        return is_string( $raw ) && '' !== $raw ? substr( $raw, 0, $max ) : null;
    }

    /** Runs $fn with every warning swallowed; a throw reads as null. */
    private function quietly( callable $fn ): mixed {
        set_error_handler( static fn(): bool => true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
        try {
            return $fn();
        } catch ( Throwable $e ) {
            return null;
        } finally {
            restore_error_handler();
        }
    }
}
