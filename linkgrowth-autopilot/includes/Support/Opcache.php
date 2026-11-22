<?php
/**
 * PHP's code cache (OPcache): a safe read of opcache_get_status(false) and how full the cache is.
 *
 * opcache_get_status() may be missing (OPcache not loaded, or the function disabled), may refuse plugins
 * (opcache.restrict_api answers with a warning and false) or report OPcache as off; every such case reads as
 * "unavailable" and never as a problem. The status is read at most once per instance (one per request through the
 * container); forget() reads it again.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Support;

use Closure;
use Throwable;

final class Opcache {

    public const LEVEL_GOOD        = 'good';
    public const LEVEL_RECOMMENDED = 'recommended';
    public const LEVEL_CRITICAL    = 'critical';
    public const LEVEL_UNAVAILABLE = 'unavailable';

    /** Critical when less than this share of the cache memory is free. */
    public const CRITICAL_FREE_PERCENT = 5.0;

    /** The interned strings buffer counts as full from this share used. */
    public const INTERNED_FULL_PERCENT = 99.0;

    /** Recommended (almost full) above this share used, for the memory or the interned strings buffer. */
    public const RECOMMENDED_USED_PERCENT = 90.0;

    /** The sizes the warning recommends (php.ini directive => value). */
    public const RECOMMENDED_SETTINGS = [
        'opcache.memory_consumption'      => '256',
        'opcache.interned_strings_buffer' => '32',
        'opcache.max_accelerated_files'   => '32531',
    ];

    /**
     * The sizes for a server with less than 2 GB of memory (see SMALL_SERVER_BYTES): there a bigger code cache takes
     * memory that PHP-FPM and the database need, so the advice stops here (the live store has 960 MB).
     */
    public const SMALL_SERVER_SETTINGS = [
        'opcache.memory_consumption'      => '160',
        'opcache.interned_strings_buffer' => '16',
        'opcache.max_accelerated_files'   => '20000',
    ];

    /**
     * "Less than 2 GB" is MemTotal below 1.75 GiB (1792 MiB): the kernel keeps part of the memory for itself, so a
     * 2 GB server reports about 1.9 GiB and a 1 GB server about 960 MB.
     */
    public const SMALL_SERVER_BYTES = 1879048192;

    /**
     * On such a server opcache.memory_consumption is never advised above this share of MemTotal (rounded down to
     * 16 MB), and opcache.interned_strings_buffer, which is part of that memory, never above a quarter of it.
     */
    public const SMALL_SERVER_CACHE_SHARE = 0.25;

    /** OPcache's largest opcache.max_accelerated_files (bigger values are clamped to it). */
    public const MAX_ACCELERATED_FILES = 1000000;

    /** @var Closure():mixed|null */
    private ?Closure $reader;

    /** @var array<string,string>|null */
    private ?array $settings;

    private bool $read = false;

    /** @var array<string,mixed>|null */
    private ?array $status = null;

    /**
     * @param callable|null             $reader   Returns what opcache_get_status(false) returns (tests inject one); null = PHP's own.
     * @param array<string,string>|null $settings The current values of the RECOMMENDED_SETTINGS directives (tests inject them); null = ini_get.
     */
    public function __construct( ?callable $reader = null, ?array $settings = null ) {
        $this->reader   = null === $reader ? null : Closure::fromCallable( $reader );
        $this->settings = $settings;
    }

    /**
     * opcache_get_status(false) when OPcache is on and lets plugins read it, else null. Never throws, never
     * lets a warning through.
     *
     * @return array<string,mixed>|null
     */
    public function status(): ?array {
        if ( $this->read ) {
            return $this->status;
        }
        $this->read   = true;
        $this->status = null;
        $raw          = null;
        // opcache.restrict_api answers with an E_WARNING; never let it reach the page or the error log.
        set_error_handler( static fn(): bool => true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
        try {
            if ( null !== $this->reader ) {
                $raw = ( $this->reader )();
            } elseif ( function_exists( 'opcache_get_status' ) ) {
                $raw = opcache_get_status( false );
            }
        } catch ( Throwable $e ) {
            $raw = null;
        } finally {
            restore_error_handler();
        }
        if ( is_array( $raw ) && ! empty( $raw['opcache_enabled'] ) ) {
            $this->status = $raw;
        }
        return $this->status;
    }

    /** Reads the status again on the next call. */
    public function forget(): void {
        $this->read   = false;
        $this->status = null;
    }

    /** OPcache has scheduled a restart or is restarting (every request then waits for the running ones). */
    public function restarting(): bool {
        $status = $this->status();
        return null !== $status && ( ! empty( $status['restart_pending'] ) || ! empty( $status['restart_in_progress'] ) );
    }

    /**
     * How full the cache is now (see classify()).
     *
     * @return array<string,mixed>
     */
    public function pressure(): array {
        return self::classify( $this->status() );
    }

    /**
     * Level and numbers for a status array: critical when OPcache reports cache_full, less than 5 % of its memory is
     * free or the interned strings buffer is full; recommended above 90 % used (memory or interned strings); good
     * otherwise; unavailable when there is no status.
     *
     * @param array<string,mixed>|null $status opcache_get_status(false)
     * @return array{level:string,cache_full:bool,restarting:bool,memory:?array{used:int,free:int,wasted:int,total:int,used_percent:float,free_percent:float},interned:?array{size:int,used:int,free:int,used_percent:float},scripts:?array{cached:int,max:int},problems:string[]}
     */
    public static function classify( ?array $status ): array {
        $out = [
            'level'      => self::LEVEL_UNAVAILABLE,
            'cache_full' => false,
            'restarting' => false,
            'memory'     => null,
            'interned'   => null,
            'scripts'    => null,
            'problems'   => [],
        ];
        if ( null === $status || empty( $status['opcache_enabled'] ) ) {
            return $out;
        }
        $out['cache_full'] = ! empty( $status['cache_full'] );
        $out['restarting'] = ! empty( $status['restart_pending'] ) || ! empty( $status['restart_in_progress'] );

        $mem = is_array( $status['memory_usage'] ?? null ) ? $status['memory_usage'] : [];
        $used   = max( 0, (int) ( $mem['used_memory'] ?? 0 ) );
        $free   = max( 0, (int) ( $mem['free_memory'] ?? 0 ) );
        $wasted = max( 0, (int) ( $mem['wasted_memory'] ?? 0 ) );
        $total  = $used + $free + $wasted;
        if ( $total > 0 ) {
            $freePercent   = round( $free / $total * 100, 2 );
            $out['memory'] = [ 'used' => $used, 'free' => $free, 'wasted' => $wasted, 'total' => $total, 'used_percent' => round( 100 - $freePercent, 2 ), 'free_percent' => $freePercent ];
        }

        $str  = is_array( $status['interned_strings_usage'] ?? null ) ? $status['interned_strings_usage'] : [];
        $size = max( 0, (int) ( $str['buffer_size'] ?? 0 ) );
        if ( $size > 0 ) {
            $strFree         = min( $size, max( 0, (int) ( $str['free_memory'] ?? 0 ) ) );
            $out['interned'] = [ 'size' => $size, 'used' => $size - $strFree, 'free' => $strFree, 'used_percent' => round( ( $size - $strFree ) / $size * 100, 2 ) ];
        }

        $stats = is_array( $status['opcache_statistics'] ?? null ) ? $status['opcache_statistics'] : [];
        if ( isset( $stats['max_cached_keys'] ) && (int) $stats['max_cached_keys'] > 0 ) {
            $out['scripts'] = [ 'cached' => max( 0, (int) ( $stats['num_cached_scripts'] ?? 0 ) ), 'max' => (int) $stats['max_cached_keys'] ];
        }

        $critical = $out['cache_full'];
        $almost   = false;
        if ( $out['cache_full'] ) {
            $out['problems'][] = 'OPcache reports that it is full.';
        }
        if ( null !== $out['memory'] ) {
            if ( $out['memory']['free_percent'] < self::CRITICAL_FREE_PERCENT ) {
                $critical          = true;
                $out['problems'][] = sprintf( 'Less than %d%% of its memory is free.', (int) self::CRITICAL_FREE_PERCENT );
            } elseif ( $out['memory']['used_percent'] > self::RECOMMENDED_USED_PERCENT ) {
                $almost            = true;
                $out['problems'][] = sprintf( 'More than %d%% of its memory is in use.', (int) self::RECOMMENDED_USED_PERCENT );
            }
        }
        if ( null !== $out['interned'] ) {
            if ( $out['interned']['used_percent'] >= self::INTERNED_FULL_PERCENT ) {
                $critical          = true;
                $out['problems'][] = 'The interned strings buffer is full.';
            } elseif ( $out['interned']['used_percent'] > self::RECOMMENDED_USED_PERCENT ) {
                $almost            = true;
                $out['problems'][] = sprintf( 'More than %d%% of the interned strings buffer is in use.', (int) self::RECOMMENDED_USED_PERCENT );
            }
        }
        $out['level'] = $critical ? self::LEVEL_CRITICAL : ( $almost ? self::LEVEL_RECOMMENDED : self::LEVEL_GOOD );
        return $out;
    }

    /**
     * The current values of the three settings the warning names (ini_get; '' when PHP does not report one).
     *
     * @return array<string,string>
     */
    public static function currentSettings(): array {
        $out = [];
        foreach ( array_keys( self::RECOMMENDED_SETTINGS ) as $directive ) {
            $value             = ini_get( $directive );
            $out[ $directive ] = false === $value ? '' : (string) $value;
        }
        return $out;
    }

    /**
     * The current values of the three settings the warning names for this server (see currentSettings()).
     *
     * @return array<string,string>
     */
    public function settings(): array {
        return $this->settings ?? self::currentSettings();
    }

    /** Megabytes with one decimal, for the messages. */
    public static function mb( int $bytes ): string {
        $mb = $bytes / 1048576;
        return ( abs( $mb - round( $mb ) ) < 0.05 ? (string) (int) round( $mb ) : number_format( $mb, 1, '.', '' ) ) . ' MB';
    }
}
