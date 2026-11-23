<?php
/**
 * Site Health test "PHP's code cache (OPcache) is full", and the same warning for the plugin's Overview.
 *
 * A full OPcache made the live store answer 504 after a plugin update: every update leaves the replaced code in the
 * cache as wasted memory, a full cache stops caching new code and schedules a restart that waits for every running
 * request. The fix is on the server (bigger OPcache sizes), so the owner is told exactly what to change.
 *
 * Status: critical when OPcache reports cache_full, less than 5 % of its memory is free or the interned strings
 * buffer is full; recommended above 90 % used; good otherwise, and also when PHP does not let plugins read the
 * OPcache status (nothing to report then). Registered on every request (the weekly Site Health check runs in cron).
 * The text says which part is full (the cache memory, or only the interned strings buffer), and the remedy never
 * names a smaller value than the server has (see advice()). On a server with less than 2 GB of memory (MemTotal from
 * Support\ServerResources) the advice stays within what that memory can spare and says that more RAM is the
 * lasting fix; when the memory is not known the advice is the same as on a large server.
 *
 * Moved from Admin\SiteHealth in WP2 (DESIGN.md §3.2, Health row): the Site Health tests of the Health area. The
 * same result in the health monitor's shape is Health\Checks\OpcacheCheck.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Health;

use LinkGrowth\Autopilot\Support\Opcache;
use LinkGrowth\Autopilot\Support\ServerResources;
use Throwable;

final class SiteHealthTests {

    /** Key of the test in site_status_tests['direct']. */
    public const TEST = 'lga_opcache';

    public const LABEL_CRITICAL    = "PHP's code cache (OPcache) is full";
    public const LABEL_RECOMMENDED = "PHP's code cache (OPcache) is almost full";
    public const LABEL_GOOD        = "PHP's code cache (OPcache) has room";
    public const LABEL_UNAVAILABLE = "PHP's code cache (OPcache) has nothing to report";

    public function __construct( private Opcache $opcache, private ?ServerResources $server = null ) {}

    public function register(): void {
        add_filter( 'site_status_tests', [ $this, 'addTests' ] );
    }

    /**
     * `site_status_tests`: adds the direct OPcache test.
     *
     * @param mixed $tests
     * @return array<string,mixed>
     */
    public function addTests( mixed $tests ): array {
        $tests = is_array( $tests ) ? $tests : [];
        if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
            $tests['direct'] = [];
        }
        $tests['direct'][ self::TEST ] = [
            'label' => __( "PHP's code cache (OPcache)", 'linkgrowth-autopilot' ),
            'test'  => [ $this, 'opcacheTest' ],
        ];
        return $tests;
    }

    /**
     * The Site Health result.
     *
     * @return array{label:string,status:string,badge:array{label:string,color:string},description:string,actions:string,test:string}
     */
    public function opcacheTest(): array {
        try {
            $pressure = $this->opcache->pressure();
        } catch ( Throwable $e ) {
            $pressure = Opcache::classify( null );
        }
        $level = (string) $pressure['level'];
        $badge = [ 'label' => __( 'Performance', 'linkgrowth-autopilot' ), 'color' => 'blue' ];

        if ( Opcache::LEVEL_CRITICAL === $level || Opcache::LEVEL_RECOMMENDED === $level ) {
            $critical       = Opcache::LEVEL_CRITICAL === $level;
            $badge['color'] = $critical ? 'red' : 'orange';
            $description    = '<p>' . esc_html( self::explanation( $pressure, $critical ) ) . '</p>';
            $numbers        = self::numbers( $pressure );
            if ( '' !== $numbers ) {
                $description .= '<p>' . esc_html( $numbers ) . '</p>';
            }
            $ram = $this->ram();
            return [
                'label'       => $critical ? self::LABEL_CRITICAL : self::LABEL_RECOMMENDED,
                'status'      => $critical ? 'critical' : 'recommended',
                'badge'       => $badge,
                'description' => $description,
                'actions'     => self::actionsHtml( self::advice( $pressure, $this->opcache->settings(), $ram ), self::memoryLine( $ram ) ),
                'test'        => self::TEST,
            ];
        }
        $good = Opcache::LEVEL_GOOD === $level;
        return [
            'label'       => $good ? self::LABEL_GOOD : self::LABEL_UNAVAILABLE,
            'status'      => 'good',
            'badge'       => $badge,
            'description' => '<p>' . esc_html(
                $good
                    ? 'PHP keeps compiled WordPress and plugin code in memory (OPcache), and that memory has room.'
                    : 'PHP does not let plugins read the OPcache status on this server, so there is nothing to report.'
            ) . '</p>',
            'actions'     => '',
            'test'        => self::TEST,
        ];
    }

    /**
     * The warning for the plugin's Overview, only while the cache is critically full (null otherwise).
     *
     * @return array{level:string,title:string,summary:string,details:string,remedy:string[],memory:string,host:string,settings:array<string,array{now:string,recommended:string}>}|null
     */
    public function warning(): ?array {
        try {
            $pressure = $this->opcache->pressure();
        } catch ( Throwable $e ) {
            return null;
        }
        if ( Opcache::LEVEL_CRITICAL !== $pressure['level'] ) {
            return null;
        }
        $ram      = $this->ram();
        $advice   = self::advice( $pressure, $this->opcache->settings(), $ram );
        $settings = [];
        foreach ( $advice as $directive => $a ) {
            $settings[ $directive ] = [ 'now' => $a['now'], 'recommended' => $a['value'] ];
        }
        return [
            'level'    => Opcache::LEVEL_CRITICAL,
            'title'    => self::LABEL_CRITICAL,
            'summary'  => self::explanation( $pressure, true ),
            'details'  => self::numbers( $pressure ),
            'remedy'   => self::remedyLines( $advice ),
            // On a server with less than 2 GB: "This server has 960 MB of memory; … more RAM is the lasting fix." ('' otherwise).
            'memory'   => self::memoryLine( $ram ),
            'host'     => self::hostLine( $advice ),
            'settings' => $settings,
        ];
    }

    // ------------------------------------------------------------------

    private static function explanation( array $pressure, bool $critical ): string {
        $intro = 'PHP keeps compiled WordPress, WooCommerce and plugin code in a fixed amount of memory (OPcache) so it does not have to compile it again for every page view.';
        // Which limit was hit: the cache memory (PHP then stops caching code and restarts the cache after updates), or
        // only the interned strings buffer (PHP keeps caching code; each file then stores its own copy of the strings).
        $mem    = is_array( $pressure['memory'] ?? null ) ? $pressure['memory'] : null;
        $memory = ! empty( $pressure['cache_full'] ) || ( null !== $mem && ( $critical
            ? (float) $mem['free_percent'] < Opcache::CRITICAL_FREE_PERCENT
            : (float) $mem['used_percent'] > Opcache::RECOMMENDED_USED_PERCENT ) );
        if ( ! $memory && is_array( $pressure['interned'] ?? null ) ) {
            $strings = ' The part of it that holds the names and texts all that code shares (the interned strings buffer)';
            return $intro . ( $critical
                ? $strings . ' is full. PHP still caches code, but every file it caches from now on keeps its own copy of those strings, so the rest of the cache fills up sooner; once that is full too, pages get slow or time out right after plugin updates.'
                : $strings . ' is almost full. Once it is full, every file PHP caches keeps its own copy of those strings, so the rest of the cache fills up sooner.' );
        }
        if ( $critical ) {
            return $intro . ' That memory is full. While it is full, PHP stops caching code and, after updates, restarts the cache, which makes every page wait until the requests already running finish. Pages then get slow or time out (HTTP 504), most of all right after plugin updates, because every update leaves the replaced code in the cache.';
        }
        return $intro . ' That memory is almost full. Every plugin update leaves the replaced code in the cache, so after a few more updates it will be full, and pages then get slow or time out right after updates.';
    }

    /** "In use: 128 MB of 128 MB (12 MB held by code replaced in updates). Interned strings: 100% of 8 MB." */
    private static function numbers( array $pressure ): string {
        $parts = [];
        $mem   = is_array( $pressure['memory'] ?? null ) ? $pressure['memory'] : null;
        if ( null !== $mem ) {
            $line = sprintf( 'Memory in use: %s of %s (%s%%)', Opcache::mb( (int) $mem['used'] + (int) $mem['wasted'] ), Opcache::mb( (int) $mem['total'] ), self::percent( (float) $mem['used_percent'] ) );
            if ( (int) $mem['wasted'] > 0 ) {
                $line .= sprintf( ', of which %s is held by code replaced in updates', Opcache::mb( (int) $mem['wasted'] ) );
            }
            $parts[] = $line . '.';
        }
        $str = is_array( $pressure['interned'] ?? null ) ? $pressure['interned'] : null;
        if ( null !== $str ) {
            $parts[] = sprintf( 'Interned strings: %s%% of %s.', self::percent( (float) $str['used_percent'] ), Opcache::mb( (int) $str['size'] ) );
        }
        $scripts = is_array( $pressure['scripts'] ?? null ) ? $pressure['scripts'] : null;
        if ( null !== $scripts ) {
            $parts[] = sprintf( 'Cached files: %s of at most %s.', number_format( (int) $scripts['cached'] ), number_format( (int) $scripts['max'] ) );
        }
        if ( ! empty( $pressure['cache_full'] ) ) {
            $parts[] = 'OPcache reports itself full.';
        }
        return implode( ' ', $parts );
    }

    /**
     * What each setting should be set to, never less than the server has: the recommended size while the server has
     * less (or does not say); about double the current size when it already has at least that and this limit is
     * the one running out (more than 90 % used, or OPcache reports cache_full for the memory); otherwise the current
     * value, unchanged (raise false). max_accelerated_files counts from the table size OPcache really uses
     * (max_cached_keys, the configured value rounded up) and stops at OPcache's maximum.
     *
     * On a server with less than 2 GB of memory ($ram below Opcache::SMALL_SERVER_BYTES) the recommended sizes are
     * Opcache::SMALL_SERVER_SETTINGS (160 / 16 / 20000) and nothing is advised above what the memory can spare: the
     * cache memory at most a quarter of MemTotal (240 MB of 960 MB), the interned strings buffer, which lives inside
     * that memory, at most a quarter of the cache memory. A setting already at or above that limit stays as it is.
     * Unknown memory ($ram null) or 2 GB and more: as before.
     *
     * @param array<string,mixed>  $pressure Opcache::classify()
     * @param array<string,string> $current  Opcache::settings()
     * @param int|null             $ram      MemTotal in bytes, or null when it is not known.
     * @return array<string,array{now:string,value:string,raise:bool}>
     */
    private static function advice( array $pressure, array $current, ?int $ram = null ): array {
        $mem     = is_array( $pressure['memory'] ?? null ) ? $pressure['memory'] : null;
        $str     = is_array( $pressure['interned'] ?? null ) ? $pressure['interned'] : null;
        $scripts = is_array( $pressure['scripts'] ?? null ) ? $pressure['scripts'] : null;
        $keys    = null !== $scripts ? max( 0, (int) $scripts['max'] ) : 0;
        $tight   = [
            'opcache.memory_consumption'      => ! empty( $pressure['cache_full'] ) || ( null !== $mem && (float) $mem['used_percent'] > Opcache::RECOMMENDED_USED_PERCENT ),
            'opcache.interned_strings_buffer' => null !== $str && (float) $str['used_percent'] > Opcache::RECOMMENDED_USED_PERCENT,
            'opcache.max_accelerated_files'   => $keys > 0 && (int) $scripts['cached'] / $keys * 100 > Opcache::RECOMMENDED_USED_PERCENT,
        ];
        $small   = self::smallServer( $ram );
        $out     = [];
        $cacheMb = null; // the opcache.memory_consumption advised or kept, for the interned strings limit
        foreach ( $small ? Opcache::SMALL_SERVER_SETTINGS : Opcache::RECOMMENDED_SETTINGS as $directive => $recommended ) {
            $now   = trim( (string) ( $current[ $directive ] ?? '' ) );
            $files = 'opcache.max_accelerated_files' === $directive;
            $size  = 1 === preg_match( '/^\d+/', $now, $m ) ? (int) $m[0] : null;
            if ( null !== $size && $files ) {
                $size = max( $size, $keys );
            }
            $cap = null;
            if ( $small && 'opcache.memory_consumption' === $directive ) {
                $cap = self::cacheCapMb( (int) $ram );
            } elseif ( $small && 'opcache.interned_strings_buffer' === $directive && null !== $cacheMb ) {
                $cap = max( 8, intdiv( $cacheMb, 4 ) );
            }
            $target = null === $cap ? (int) $recommended : min( (int) $recommended, $cap );
            if ( null === $size || $size < $target ) {
                $out[ $directive ] = [ 'now' => $now, 'value' => (string) $target, 'raise' => true ];
            } else {
                $value = $files ? min( $size * 2, Opcache::MAX_ACCELERATED_FILES ) : $size * 2;
                if ( null !== $cap ) {
                    $value = min( $value, $cap );
                }
                $raise             = ! empty( $tight[ $directive ] ) && $value > $size;
                $out[ $directive ] = [ 'now' => $now, 'value' => $raise ? (string) $value : $now, 'raise' => $raise ];
            }
            if ( 'opcache.memory_consumption' === $directive ) {
                $cacheMb = $out[ $directive ]['raise'] ? (int) $out[ $directive ]['value'] : ( $size ?? $target );
            }
        }
        return $out;
    }

    /** Less than 2 GB of memory (MemTotal below Opcache::SMALL_SERVER_BYTES); false when it is not known. */
    private static function smallServer( ?int $ram ): bool {
        return null !== $ram && $ram > 0 && $ram < Opcache::SMALL_SERVER_BYTES;
    }

    /** The largest cache memory advised on a small server: a quarter of MemTotal, rounded down to 16 MB. */
    private static function cacheCapMb( int $ram ): int {
        return max( 16, (int) ( floor( $ram / 1048576 * Opcache::SMALL_SERVER_CACHE_SHARE / 16 ) * 16 ) );
    }

    /** The plain line for a server with less than 2 GB of memory, '' otherwise. */
    private static function memoryLine( ?int $ram ): string {
        if ( ! self::smallServer( $ram ) ) {
            return '';
        }
        return sprintf( 'This server has %d MB of memory; a bigger code cache takes memory from PHP and the database, so more RAM is the lasting fix.', (int) round( (int) $ram / 1048576 ) );
    }

    /** MemTotal in bytes, or null when it is not known (never throws). */
    private function ram(): ?int {
        try {
            $this->server ??= new ServerResources();
            return $this->server->totalMemory();
        } catch ( Throwable $e ) {
            return null;
        }
    }

    /**
     * @param array<string,array{now:string,value:string,raise:bool}> $advice
     * @return string[]
     */
    private static function remedyLines( array $advice ): array {
        $settings = [];
        foreach ( $advice as $directive => $a ) {
            if ( $a['raise'] ) {
                $settings[] = sprintf( '%s=%s', $directive, $a['value'] ) . ( '' !== $a['now'] ? sprintf( ' (now %s)', $a['now'] ) : '' );
            }
        }
        if ( [] === $settings ) {
            // Only on a server with less than 2 GB, where the sizes already reach what its memory can spare.
            return [ "The code cache already has as much of this server's memory as it can spare (a quarter), so these settings stay as they are." ];
        }
        return array_merge( [ 'Raise these settings in the php.ini (or a file in the conf.d folder) that PHP-FPM uses, then restart PHP-FPM:' ], $settings );
    }

    /** @param array<string,array{now:string,value:string,raise:bool}> $advice */
    private static function actionsHtml( array $advice, string $memory = '' ): string {
        $lines = self::remedyLines( $advice );
        $intro = array_shift( $lines );
        $html  = '<p>' . esc_html( (string) $intro ) . '</p>';
        if ( [] !== $lines ) {
            $html .= '<ul>';
            foreach ( $lines as $line ) {
                [ $setting, $rest ] = array_pad( explode( ' ', $line, 2 ), 2, '' );
                $html .= '<li><code>' . esc_html( $setting ) . '</code>' . ( '' !== $rest ? ' ' . esc_html( $rest ) : '' ) . '</li>';
            }
            $html .= '</ul>';
        }
        if ( '' !== $memory ) {
            $html .= '<p>' . esc_html( $memory ) . '</p>';
        }
        $html .= '<p>' . esc_html( self::hostLine( $advice ) ) . '</p>';
        return $html;
    }

    /** @param array<string,array{now:string,value:string,raise:bool}> $advice */
    private static function hostLine( array $advice ): string {
        foreach ( $advice as $a ) {
            if ( $a['raise'] ) {
                return 'On managed hosting, ask your host to make this change.';
            }
        }
        return 'On managed hosting, ask your host for a plan with more memory.';
    }

    private static function percent( float $value ): string {
        return $value >= 99.95 || abs( $value - round( $value ) ) < 0.05 ? (string) (int) round( $value ) : number_format( $value, 1, '.', '' );
    }
}
