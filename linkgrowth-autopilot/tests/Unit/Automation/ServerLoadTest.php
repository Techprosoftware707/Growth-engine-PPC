<?php
/**
 * 1.2.4 Automation\LoadGuard's server reasons and Admin\SiteHealth's RAM-aware advice, over Support\ServerResources
 * with injected readers (no real /proc): the load per CPU above or at 2.0, the CPU count from
 * /sys/devices/system/cpu/online, /proc/cpuinfo or neither (counted as 1, kept for a day), the two threshold filters,
 * unreadable or unsupported systems, MemAvailable below or at 10 %, the order after the existing reasons, the owner
 * filter, one read per request, a tick that runs no job while busy and the dashboard text; then the OPcache advice
 * on a 960 MB server (160 / 16 / 20000, at most a quarter of the memory, never less than the server has, the RAM line),
 * at 2 GB and more (unchanged) and with unknown memory (unchanged). One test runs the real readers under open_basedir
 * in a child PHP process to show that nothing is read and nothing warns.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Automation;

use LinkGrowth\Autopilot\Admin\Rest\OverviewController;
use LinkGrowth\Autopilot\Admin\SiteHealth;
use LinkGrowth\Autopilot\Automation\LoadGuard;
use LinkGrowth\Autopilot\Automation\Pipeline;
use LinkGrowth\Autopilot\Automation\Scheduler;
use LinkGrowth\Autopilot\Compliance\PositioningGate;
use LinkGrowth\Autopilot\Repositories\Jobs;
use LinkGrowth\Autopilot\Repositories\Logs;
use LinkGrowth\Autopilot\Support\Opcache;
use LinkGrowth\Autopilot\Support\ServerResources;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Tests\Support\Container;
use LinkGrowth\Autopilot\Tests\Support\TestCase;
use LinkGrowth\Autopilot\Tests\Unit\Growth\FakeStorefront;
use LinkGrowth\Autopilot\Tests\Unit\Growth\GrowthFakeJob;
use RuntimeException;
use WP_REST_Request;

require_once __DIR__ . '/../Growth/GrowthFakes.php';

final class ServerLoadTest extends TestCase {

    /** The live store: 960 MB (983040 kB), 207 MB available during the outage. */
    private const LIVE_TOTAL_KB     = 983040;
    private const LIVE_AVAILABLE_KB = 211968;

    private const BUSY_TEXT   = 'Paused: the server is busy (load 6.53 on 1 CPU) — resumes when it calms down.';
    private const MEMORY_TEXT = 'Paused: the server is low on free memory — resumes when memory frees up.';
    private const RAM_LINE    = 'This server has 960 MB of memory; a bigger code cache takes memory from PHP and the database, so more RAM is the lasting fix.';

    /** What the injected sys_getloadavg() answers (a callable is called). */
    private mixed $load = [ 0.3, 0.2, 0.1 ];

    /** @var array<string,string> path => contents; a missing path is unreadable */
    private array $files = [];

    /** @var array<int,array{0:string,1:int}> every file read: path, byte limit */
    private array $fileReads = [];

    private int $loadReads = 0;

    protected function setUp(): void {
        parent::setUp();
        GrowthFakeJob::reset();
        $GLOBALS['lga_test_filters']['lga_job_types'] = [ static fn() => GrowthFakeJob::registry( [ 'import_verified' ] ) ];
        Container::plugin()->container()->set( PositioningGate::class, new FakeStorefront() );
        Container::get( Settings::class )->set( 'automation.enabled', true );
        $this->load      = [ 0.3, 0.2, 0.1 ];
        $this->files     = [ ServerResources::CPU_ONLINE => "0\n", ServerResources::MEMINFO => self::meminfo( self::LIVE_TOTAL_KB, self::LIVE_AVAILABLE_KB ) ];
        $this->fileReads = [];
        $this->loadReads = 0;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** A ServerResources over $this->load and $this->files (read when asked, so a test can change them). */
    private function server( string $os = 'Linux' ): ServerResources {
        return new ServerResources(
            function ( string $path, int $max ) {
                $this->fileReads[] = [ $path, $max ];
                return $this->files[ $path ] ?? null;
            },
            function () {
                $this->loadReads++;
                return is_callable( $this->load ) ? ( $this->load )() : $this->load;
            },
            $os
        );
    }

    /** A guard over the fake server (OPcache not readable), installed in the container for the tick and REST. */
    private function guard( ?ServerResources $server = null, ?Opcache $opcache = null ): LoadGuard {
        $server ??= $this->server();
        $guard    = new LoadGuard( $opcache ?? new Opcache( static fn() => false ), $server );
        $c        = Container::plugin()->container();
        $c->set( ServerResources::class, $server );
        $c->set( LoadGuard::class, $guard );
        return $guard;
    }

    private static function meminfo( int $totalKb, ?int $availableKb ): string {
        return sprintf( "MemTotal:       %8d kB\nMemFree:           12345 kB\n", $totalKb )
            . ( null === $availableKb ? '' : sprintf( "MemAvailable:   %8d kB\n", $availableKb ) )
            . "Buffers:            1024 kB\nCached:           200000 kB\nSwapCached:        51000 kB\nSwapTotal:        998396 kB\nSwapFree:         309000 kB\n";
    }

    /** /proc/cpuinfo with $n x86 processor blocks. */
    private static function cpuinfo( int $n ): string {
        $out = '';
        for ( $i = 0; $i < $n; $i++ ) {
            $out .= "processor\t: {$i}\nvendor_id\t: GenuineIntel\nmodel name\t: Intel(R) Xeon(R)\nflags\t\t: fpu vme de pse\n\n";
        }
        return $out;
    }

    /** The paths read so far (CPU files and meminfo). */
    private function readPaths(): array {
        return array_column( $this->fileReads, 0 );
    }

    /** Runs $fn and returns the warnings that reached PHP's error handler. */
    private static function warningsOf( callable $fn ): array {
        $seen = [];
        set_error_handler( static function ( int $no, string $msg ) use ( &$seen ): bool { // phpcs:ignore
            $seen[] = $msg;
            return true;
        } );
        try {
            $fn();
        } finally {
            restore_error_handler();
        }
        return $seen;
    }

    /** @return string[] automation log messages, oldest first */
    private function automationLog(): array {
        return array_map( static fn( array $r ): string => (string) $r['message'], Container::get( Logs::class )->findBy( [ 'channel' => 'automation' ], 'id', 'ASC', 200 ) );
    }

    // ------------------------------------------------------------------
    // The load per CPU
    // ------------------------------------------------------------------

    public function testTheLiveLoadPausesWithTheNumbers(): void {
        $this->load = [ 6.53, 5.9, 4.1 ];
        $reason     = $this->guard()->pauseReason();
        $this->assertSame( LoadGuard::CODE_SERVER_BUSY, $reason['code'] ?? null );
        $this->assertSame( self::BUSY_TEXT, $reason['text'] );
        $this->assertSame( 'the server is busy', $reason['message'] );
        $this->assertNull( $reason['until'] );
        $this->assertSame( 'load', $reason['source'] );
        $this->assertSame( [ 'load' => 6.53, 'cpus' => 1, 'cpus_known' => true, 'load_per_cpu' => 6.53, 'max_load_per_cpu' => 2.0 ], $reason['measured'] );
        $this->assertSame( 'Background work is paused: the server is busy. It resumes automatically.', LoadGuard::warning( $reason ) );

        $this->load = [ 6.5, 6.0, 5.0 ];
        $this->assertSame( 'Paused: the server is busy (load 6.5 on 1 CPU) — resumes when it calms down.', $this->guard()->pauseReason()['text'] );
        $this->load = [ 7, 6, 5 ];
        $this->assertSame( 'Paused: the server is busy (load 7 on 1 CPU) — resumes when it calms down.', $this->guard()->pauseReason()['text'], 'an int load from a stub' );
    }

    public function testLoadAtOrBelowTwoPerCpuRunsAndAboveItPauses(): void {
        $cases = [
            'exactly 2.0 on 1 CPU'  => [ "0\n", 2.0, null ],
            '2.01 on 1 CPU'         => [ "0\n", 2.01, 'load 2.01 on 1 CPU' ],
            'idle'                  => [ "0\n", 0.0, null ],
            '7.9 on 4 CPUs (1.975)' => [ "0-3\n", 7.9, null ],
            '8.4 on 4 CPUs (2.1)'   => [ "0-3\n", 8.4, 'load 8.4 on 4 CPUs' ],
            '8.4 on 0-1,4-5'        => [ "0-1,4-5\n", 8.4, 'load 8.4 on 4 CPUs' ],
            '30 on 16 CPUs'         => [ "0-15\n", 30.0, null ],
        ];
        foreach ( $cases as $label => [ $online, $load, $expected ] ) {
            delete_option( ServerResources::OPTION_CPUS );
            $this->files[ ServerResources::CPU_ONLINE ] = $online;
            $this->load                                 = [ $load, 1.0, 1.0 ];
            $reason                                     = $this->guard()->pauseReason();
            if ( null === $expected ) {
                $this->assertNull( $reason, $label );
                continue;
            }
            $this->assertSame( LoadGuard::CODE_SERVER_BUSY, $reason['code'] ?? null, $label );
            $this->assertSame( 'Paused: the server is busy (' . $expected . ') — resumes when it calms down.', $reason['text'], $label );
        }
    }

    public function testTheCpuCountComesFromSysfsThenCpuinfoAndIsKeptForADay(): void {
        $this->load = [ 6.0, 5.0, 4.0 ];
        $files      = [
            'sysfs'             => [ [ ServerResources::CPU_ONLINE => "0-1,4-5\n", ServerResources::CPUINFO => self::cpuinfo( 8 ) ], [ 'count' => 4, 'known' => true, 'source' => 'sysfs' ] ],
            'cpuinfo'           => [ [ ServerResources::CPUINFO => self::cpuinfo( 2 ) ], [ 'count' => 2, 'known' => true, 'source' => 'cpuinfo' ] ],
            'garbled sysfs'     => [ [ ServerResources::CPU_ONLINE => 'online', ServerResources::CPUINFO => self::cpuinfo( 3 ) ], [ 'count' => 3, 'known' => true, 'source' => 'cpuinfo' ] ],
            's390 cpuinfo'      => [ [ ServerResources::CPUINFO => "vendor_id       : IBM/S390\nprocessor 0: version = FF\nprocessor 1: version = FF\n" ], [ 'count' => 2, 'known' => true, 'source' => 'cpuinfo' ] ],
            'neither'           => [ [], [ 'count' => 1, 'known' => false, 'source' => 'unknown' ] ],
            'no processor line' => [ [ ServerResources::CPUINFO => "Processor : ARMv7 rev 4\n" ], [ 'count' => 1, 'known' => false, 'source' => 'unknown' ] ],
        ];
        foreach ( $files as $label => [ $present, $expected ] ) {
            delete_option( ServerResources::OPTION_CPUS );
            $this->files = $present;
            $this->assertSame( $expected, $this->server()->cpus(), $label );
            $stored = get_option( ServerResources::OPTION_CPUS );
            $this->assertSame( $expected['count'], $stored['count'] ?? null, $label . ': stored' );
            $this->assertEqualsWithDelta( time(), (int) ( $stored['checked'] ?? 0 ), 5, $label );
        }

        // Unknown counts as 1, and the text says so.
        delete_option( ServerResources::OPTION_CPUS );
        $this->files = [];
        $this->load  = [ 2.5, 2.0, 2.0 ];
        $reason      = $this->guard()->pauseReason();
        $this->assertSame( 'Paused: the server is busy (load 2.5; CPU count unknown, counted as 1) — resumes when it calms down.', $reason['text'] ?? null );
        $this->assertFalse( $reason['measured']['cpus_known'] );
        $this->assertSame( 1, $reason['measured']['cpus'] );

        // Kept for a day: a new request reads no CPU file; after a day (or with a time in the future) it reads again.
        delete_option( ServerResources::OPTION_CPUS );
        $this->files = [ ServerResources::CPU_ONLINE => "0-3\n" ];
        $this->assertSame( 4, $this->server()->cpus()['count'] );
        $this->files     = [ ServerResources::CPU_ONLINE => "0-7\n" ];
        $this->fileReads = [];
        $this->assertSame( 4, $this->server()->cpus()['count'], 'the stored count' );
        $this->assertSame( [], $this->readPaths(), 'no CPU file read within the day' );
        update_option( ServerResources::OPTION_CPUS, [ 'count' => 4, 'known' => true, 'source' => 'sysfs', 'checked' => time() - ServerResources::CPU_CACHE_SECONDS - 1 ] );
        $this->assertSame( 8, $this->server()->cpus()['count'], 'read again after a day (a resized server)' );
        update_option( ServerResources::OPTION_CPUS, [ 'count' => 4, 'known' => true, 'source' => 'sysfs', 'checked' => time() + 3600 ] );
        $this->assertSame( 8, $this->server()->cpus()['count'], 'a time in the future is not trusted' );
        update_option( ServerResources::OPTION_CPUS, 'garbage' );
        $this->assertSame( 8, $this->server()->cpus()['count'] );

        // Each read is one small read.
        $this->fileReads = [];
        delete_option( ServerResources::OPTION_CPUS );
        $this->files = [ ServerResources::CPUINFO => self::cpuinfo( 1 ), ServerResources::MEMINFO => self::meminfo( 983040, 90000 ) ];
        $s           = $this->server();
        $s->cpus();
        $s->memory();
        $this->assertSame( [ [ ServerResources::CPU_ONLINE, 256 ], [ ServerResources::CPUINFO, 65536 ], [ ServerResources::MEMINFO, 4096 ] ], $this->fileReads );
    }

    public function testCountOnlineAndCountProcessors(): void {
        $this->assertSame( 1, ServerResources::countOnline( "0\n" ) );
        $this->assertSame( 4, ServerResources::countOnline( ' 0-3 ' ) );
        $this->assertSame( 3, ServerResources::countOnline( '0,2-3' ) );
        $this->assertSame( 64, ServerResources::countOnline( '0-63' ) );
        foreach ( [ null, '', 'abc', '0-', '-3', '3-1', '0;1' ] as $bad ) {
            $this->assertNull( ServerResources::countOnline( $bad ), var_export( $bad, true ) );
        }
        $this->assertSame( 4, ServerResources::countProcessors( self::cpuinfo( 4 ) ) );
        $this->assertNull( ServerResources::countProcessors( null ) );
        $this->assertNull( ServerResources::countProcessors( "model name : x\n" ) );
    }

    public function testTheLoadFilterMovesTheThreshold(): void {
        $seen = [];
        add_filter( LoadGuard::FILTER_MAX_LOAD, static function ( $max, $load, $cpus ) use ( &$seen ) {
            $seen[] = [ $max, $load, $cpus ];
            return 8.0;
        }, 10, 3 );
        $this->load = [ 6.53, 5.0, 4.0 ];
        $this->assertNull( $this->guard()->pauseReason(), 'a higher threshold lets the work run' );
        $this->assertSame( [ [ 2.0, 6.53, 1 ] ], $seen, 'the filter sees the default, the load and the CPU count' );

        $cases = [
            'int 1'          => [ 1, 1.5, true ],
            'string "3.5"'   => [ '3.5', 3.4, false ],
            'string " 3.5 "' => [ ' 3.5 ', 3.6, true ],
            'INF switches it off' => [ INF, 500.0, false ],
            'below zero: any load' => [ -1, 0.0, true ],
            'abc: default'   => [ 'abc', 2.5, true ],
            'null: default'  => [ null, 1.9, false ],
            'NAN: default'   => [ NAN, 2.5, true ],
            'array: default' => [ [ 9 ], 2.5, true ],
            'true: default'  => [ true, 2.5, true ],
        ];
        foreach ( $cases as $label => [ $threshold, $load, $paused ] ) {
            $GLOBALS['lga_test_filters'][ LoadGuard::FILTER_MAX_LOAD ] = [ static fn() => $threshold ];
            $this->load = [ $load, 1.0, 1.0 ];
            $reason     = $this->guard()->pauseReason();
            $this->assertSame( $paused, null !== $reason, $label );
        }
        $GLOBALS['lga_test_filters'][ LoadGuard::FILTER_MAX_LOAD ] = [ static fn() => 1 ];
        $this->load = [ 1.5, 1.0, 1.0 ];
        $this->assertSame( 1.0, $this->guard()->pauseReason()['measured']['max_load_per_cpu'], 'the threshold used is reported' );
    }

    public function testAnUnreadableOrUnsupportedSystemNeverPauses(): void {
        $this->load = [ 50.0, 50.0, 50.0 ];
        foreach ( [ 'Darwin', 'Windows', 'BSD' ] as $os ) {
            $this->loadReads = 0;
            $this->assertNull( $this->guard( $this->server( $os ) )->pauseReason(), $os );
            $this->assertSame( 0, $this->loadReads, $os . ': the load is not even asked for' );
        }

        $answers = [
            'false (no getloadavg)' => false,
            'null'                  => null,
            'empty'                 => [],
            'text'                  => [ 'x' ],
            'negative'              => [ -1.0 ],
            'NAN'                   => [ NAN ],
            'INF'                   => [ INF ],
            'a string'              => 'busy',
            'throws'                => static function () {
                throw new RuntimeException( 'disabled' );
            },
            'warns'                 => static function () {
                trigger_error( 'sys_getloadavg(): failed', E_USER_WARNING ); // phpcs:ignore
                return false;
            },
        ];
        $this->files[ ServerResources::MEMINFO ] = self::meminfo( self::LIVE_TOTAL_KB, 1000 ); // memory known: the load alone is unknown
        $GLOBALS['lga_test_filters'][ LoadGuard::FILTER_MIN_MEMORY ] = [ static fn() => 0 ];
        foreach ( $answers as $label => $answer ) {
            $this->load = $answer;
            $warnings   = self::warningsOf( function () use ( $label ) {
                $this->assertNull( $this->guard()->pauseReason(), $label );
            } );
            $this->assertSame( [], $warnings, $label . ': no warning reaches PHP' );
        }
        unset( $GLOBALS['lga_test_filters'][ LoadGuard::FILTER_MIN_MEMORY ] );

        // Memory: no /proc/meminfo, garbage, no MemTotal, MemTotal 0, no MemAvailable (before Linux 3.14), a reader that throws.
        $this->load = [ 0.1, 0.1, 0.1 ];
        $meminfos   = [
            'unreadable'      => null,
            'garbage'         => 'hello',
            'no MemTotal'     => "MemFree: 100 kB\nMemAvailable: 100 kB\n",
            'MemTotal 0'      => self::meminfo( 0, 0 ),
            'no MemAvailable' => self::meminfo( self::LIVE_TOTAL_KB, null ),
        ];
        foreach ( $meminfos as $label => $raw ) {
            if ( null === $raw ) {
                unset( $this->files[ ServerResources::MEMINFO ] );
            } else {
                $this->files[ ServerResources::MEMINFO ] = $raw;
            }
            $this->assertNull( $this->guard()->pauseReason(), $label );
        }
        delete_option( ServerResources::OPTION_CPUS );
        $throws = new ServerResources(
            static function () {
                trigger_error( 'file_get_contents(): open_basedir restriction in effect', E_USER_WARNING ); // phpcs:ignore
                throw new RuntimeException( 'unreadable' );
            },
            static fn() => [ 0.1, 0.1, 0.1 ],
            'Linux'
        );
        $warnings = self::warningsOf( function () use ( $throws ) {
            $this->assertNull( $this->guard( $throws )->pauseReason(), 'a reader that warns and throws' );
            $this->assertNull( $throws->memory() );
            $this->assertSame( [ 'count' => 1, 'known' => false, 'source' => 'unknown' ], $throws->cpus() );
        } );
        $this->assertSame( [], $warnings );
    }

    public function testTheRealReadersStayQuietUnderOpenBasedir(): void {
        // In this process: whatever the machine has, the answers are well formed and nothing warns.
        $warnings = self::warningsOf( function () {
            $real = new ServerResources();
            $load = $real->load();
            $this->assertTrue( null === $load || $load >= 0.0 );
            $this->assertGreaterThanOrEqual( 1, $real->cpus()['count'] );
            $memory = $real->memory();
            $this->assertTrue( null === $memory || ( $memory['total'] > 0 && ( null === $memory['available'] || $memory['available'] <= $memory['total'] ) ) );
        } );
        $this->assertSame( [], $warnings );

        // In a child PHP whose open_basedir allows only the class's own folder: /proc and /sys are off limits.
        $php = PHP_BINARY;
        if ( '' === $php || ! is_executable( $php ) || ! function_exists( 'shell_exec' ) ) {
            $this->markTestSkipped( 'no PHP binary to run a child process' );
        }
        $dir    = LGA_DIR . 'includes/Support';
        $script = 'namespace { function get_option( $n, $d = false ) { return $GLOBALS["o"][ $n ] ?? $d; } function update_option( $n, $v, $a = null ) { $GLOBALS["o"][ $n ] = $v; return true; } }'
            . ' namespace { require ' . var_export( $dir . '/ServerResources.php', true ) . '; $s = new LinkGrowth\Autopilot\Support\ServerResources();'
            . ' echo json_encode( [ "load" => $s->load(), "cpus" => $s->cpus(), "memory" => $s->memory(), "basedir" => ini_get( "open_basedir" ) ] ); }';
        $cmd = escapeshellarg( $php ) . ' -n -d open_basedir=' . escapeshellarg( $dir ) . ' -d display_errors=1 -d display_startup_errors=1 -d error_reporting=-1 -d log_errors=0 -r ' . escapeshellarg( $script ) . ' 2>&1';
        $out = (string) shell_exec( $cmd );
        $this->assertStringNotContainsString( 'Warning', $out );
        $this->assertStringNotContainsString( 'open_basedir restriction', $out );
        $data = json_decode( $out, true );
        $this->assertIsArray( $data, $out );
        $this->assertSame( $dir, $data['basedir'] );
        $this->assertSame( [ 'count' => 1, 'known' => false, 'source' => 'unknown' ], $data['cpus'], 'no CPU file is readable: counted as 1' );
        $this->assertNull( $data['memory'], 'no /proc/meminfo: memory unknown' );
        $this->assertTrue( null === $data['load'] || is_float( $data['load'] ) || is_int( $data['load'] ), 'sys_getloadavg() is a system call, not a file read' );
    }

    // ------------------------------------------------------------------
    // Memory
    // ------------------------------------------------------------------

    public function testLessThanTenPercentAvailableMemoryPauses(): void {
        $this->files[ ServerResources::MEMINFO ] = self::meminfo( self::LIVE_TOTAL_KB, 90000 );
        $reason                                  = $this->guard()->pauseReason();
        $this->assertSame( LoadGuard::CODE_LOW_MEMORY, $reason['code'] ?? null );
        $this->assertSame( self::MEMORY_TEXT, $reason['text'] );
        $this->assertSame( 'the server is low on free memory', $reason['message'] );
        $this->assertSame( 'memory', $reason['source'] );
        $this->assertNull( $reason['until'] );
        $this->assertSame( [ 'available_mb' => 88, 'total_mb' => 960, 'available_percent' => 9.2, 'min_percent' => 10.0 ], $reason['measured'] );
        $this->assertSame( 'Background work is paused: the server is low on free memory. It resumes automatically.', LoadGuard::warning( $reason ) );

        $cases = [
            'the live outage: 207 of 960 MB (21.6 %)' => [ self::LIVE_AVAILABLE_KB, false ],
            'exactly 10 %'                            => [ 98304, false ],
            'just under 10 %'                         => [ 98303, true ],
            'nothing available'                       => [ 0, true ],
            'more than the total (clamped)'           => [ 2000000, false ],
        ];
        foreach ( $cases as $label => [ $available, $paused ] ) {
            $this->files[ ServerResources::MEMINFO ] = self::meminfo( self::LIVE_TOTAL_KB, $available );
            $this->assertSame( $paused, null !== $this->guard()->pauseReason(), $label );
        }
    }

    public function testTheMemoryFilterMovesTheShare(): void {
        $seen = [];
        add_filter( LoadGuard::FILTER_MIN_MEMORY, static function ( $min, $available, $total ) use ( &$seen ) {
            $seen[] = [ $min, $available, $total ];
            return 0.25;
        }, 10, 3 );
        $reason = $this->guard()->pauseReason();
        $this->assertSame( LoadGuard::CODE_LOW_MEMORY, $reason['code'] ?? null, 'at 25 % the live 21.6 % pauses' );
        $this->assertSame( 25.0, $reason['measured']['min_percent'] );
        $this->assertSame( [ [ 0.10, self::LIVE_AVAILABLE_KB * 1024, self::LIVE_TOTAL_KB * 1024 ] ], $seen, 'the filter sees the default and the bytes' );

        $this->files[ ServerResources::MEMINFO ] = self::meminfo( self::LIVE_TOTAL_KB, 9000 ); // 0.9 %
        $cases = [
            '0 switches it off' => [ 0, false ],
            '"0.02"'            => [ '0.02', true ],
            '0.005'             => [ 0.005, false ],
            '1.5: default'      => [ 1.5, true ],
            '-0.1: default'     => [ -0.1, true ],
            'x: default'        => [ 'x', true ],
            'NAN: default'      => [ NAN, true ],
        ];
        foreach ( $cases as $label => [ $share, $paused ] ) {
            $GLOBALS['lga_test_filters'][ LoadGuard::FILTER_MIN_MEMORY ] = [ static fn() => $share ];
            $this->assertSame( $paused, null !== $this->guard()->pauseReason(), $label );
        }
        $this->files[ ServerResources::MEMINFO ] = self::meminfo( self::LIVE_TOTAL_KB, self::LIVE_TOTAL_KB - 1 );
        $GLOBALS['lga_test_filters'][ LoadGuard::FILTER_MIN_MEMORY ] = [ static fn() => 1.0 ];
        $this->assertSame( LoadGuard::CODE_LOW_MEMORY, $this->guard()->pauseReason()['code'] ?? null, '1.0 pauses while any memory is in use' );
    }

    // ------------------------------------------------------------------
    // Order, the owner's filter, one read per request
    // ------------------------------------------------------------------

    public function testTheServerIsAskedOnlyAfterTheExistingReasons(): void {
        $this->load                              = [ 6.53, 6.0, 5.0 ];
        $this->files[ ServerResources::MEMINFO ] = self::meminfo( self::LIVE_TOTAL_KB, 9000 );

        $restart = new Opcache( static fn() => [ 'opcache_enabled' => true, 'restart_pending' => true ] );
        $this->assertSame( LoadGuard::CODE_OPCACHE_RESTART, $this->guard( null, $restart )->pauseReason()['code'] ?? null );
        $this->assertSame( [ 0, [] ], [ $this->loadReads, $this->readPaths() ], 'OPcache restarting: the server is not read' );

        $cron        = [ time() + 60 => [ 'updraft_backup_resume' => [ 'k' => [ 'schedule' => false, 'args' => [ 1, 'abcdef123456' ] ] ] ], 'version' => 2 ];
        update_option( 'cron', $cron );
        $this->assertSame( LoadGuard::CODE_BACKUP, $this->guard()->pauseReason()['code'] ?? null );
        $this->assertSame( [ 0, [] ], [ $this->loadReads, $this->readPaths() ], 'a backup: the server is not read' );
        delete_option( 'cron' );

        update_option( LoadGuard::OPTION_VERSION, [ 'version' => LGA_VERSION, 'since' => time() - 60, 'from' => '1.2.3' ] );
        $this->assertSame( LoadGuard::CODE_UPDATED, $this->guard()->pauseReason()['code'] ?? null );
        $this->assertSame( [ 0, [] ], [ $this->loadReads, $this->readPaths() ], 'just updated: the server is not read' );
        update_option( LoadGuard::OPTION_VERSION, [ 'version' => LGA_VERSION, 'since' => 0, 'from' => '' ] );

        $this->assertSame( LoadGuard::CODE_SERVER_BUSY, $this->guard()->pauseReason()['code'] ?? null, 'busy and low on memory: busy first' );
        $this->assertNotContains( ServerResources::MEMINFO, $this->readPaths(), 'the memory is not read once the load pauses' );

        $this->load = [ 0.4, 0.4, 0.4 ];
        $this->assertSame( LoadGuard::CODE_LOW_MEMORY, $this->guard()->pauseReason()['code'] ?? null );
        $this->files[ ServerResources::MEMINFO ] = self::meminfo( self::LIVE_TOTAL_KB, self::LIVE_AVAILABLE_KB );
        $this->assertNull( $this->guard()->pauseReason() );
    }

    public function testTheOwnerFilterStillDecides(): void {
        $this->load = [ 6.53, 6.0, 5.0 ];
        $seen       = [];
        $GLOBALS['lga_test_filters'][ LoadGuard::FILTER_PAUSE ] = [ static function ( $pause, $reason ) use ( &$seen ) {
            $seen[] = [ $pause, $reason['code'] ?? null, $reason['measured']['load'] ?? null ];
            return false;
        } ];
        $this->assertNull( $this->guard()->pauseReason(), 'false lets the work run on a busy server' );
        $this->assertSame( [ [ true, LoadGuard::CODE_SERVER_BUSY, 6.53 ] ], $seen, 'the filter sees the reason with its numbers' );

        $this->load = [ 0.1, 0.1, 0.1 ];
        $this->files[ ServerResources::MEMINFO ] = self::meminfo( self::LIVE_TOTAL_KB, 9000 );
        $GLOBALS['lga_test_filters'][ LoadGuard::FILTER_PAUSE ] = [ static fn() => false ];
        $this->assertNull( $this->guard()->pauseReason(), 'and on a server low on memory' );
        $GLOBALS['lga_test_filters'][ LoadGuard::FILTER_PAUSE ] = [ static fn() => 'Host maintenance' ];
        $this->assertSame( [ LoadGuard::CODE_OWNER, 'Paused: Host maintenance.' ], array_values( array_intersect_key( (array) $this->guard()->pauseReason(), [ 'code' => 1, 'text' => 1 ] ) ) );
    }

    public function testTheServerIsReadOncePerRequestAndAfreshOnEveryTick(): void {
        $guard = $this->guard();
        $guard->pauseReason();
        $guard->pauseReason();
        $guard->status();
        $this->assertSame( 1, $this->loadReads, 'one read for the request' );
        $this->assertSame( 1, count( array_keys( $this->readPaths(), ServerResources::MEMINFO, true ) ) );
        $guard->pauseReason( true );
        $this->assertSame( 2, $this->loadReads, 'asked afresh' );
        $this->assertSame( 2, count( array_keys( $this->readPaths(), ServerResources::MEMINFO, true ) ) );
        $this->assertSame( 1, count( array_keys( $this->readPaths(), ServerResources::CPU_ONLINE, true ) ), 'the CPU count is read once, then stored' );

        $pipeline = Container::get( Pipeline::class );
        $ran      = [ (int) $pipeline->tick()['run']['ran'], (int) $pipeline->tick()['run']['ran'] ];
        // Every tick asks again when it starts and, since 1.2.4's performance pass, before each further claim (after
        // each job it ran, including the last claim that finds the queue empty).
        $this->assertGreaterThan( 1, $ran[0], 'the first tick ran several jobs' );
        $this->assertSame( 2 + 2 + $ran[0] + $ran[1], $this->loadReads, 'every tick asks again, and between jobs' );
        $this->assertSame( 1, count( array_keys( $this->readPaths(), ServerResources::CPU_ONLINE, true ) ) );
        $reads = $this->loadReads;
        $guard->forget();
        $guard->pauseReason();
        $this->assertSame( $reads + 1, $this->loadReads );
    }

    // ------------------------------------------------------------------
    // The tick, the kick, the log, the dashboard
    // ------------------------------------------------------------------

    public function testATickOnABusyOrShortOfMemoryServerRunsNoJobAndSaysWhy(): void {
        $this->guard();
        $jobs     = Container::get( Jobs::class );
        $pipeline = Container::get( Pipeline::class );
        $id       = $jobs->enqueue( 'discover', [ 'manual' => true ], 1 );

        $this->load = [ 6.53, 6.0, 5.0 ];
        $out        = $pipeline->tick();
        $this->assertSame( 'paused', $out['skipped_reason'] );
        $this->assertSame( LoadGuard::CODE_SERVER_BUSY, $out['paused']['code'] ?? null );
        $this->assertSame( 0, $out['run']['ran'] );
        $this->assertSame( [], $out['enqueued'] );
        $this->assertSame( [], GrowthFakeJob::$runs, 'no job ran' );
        $this->assertSame( Jobs::STATUS_QUEUED, $jobs->find( $id )['status'] );
        $this->assertSame( 0, (int) $jobs->find( $id )['attempts'] );
        $this->assertSame( self::BUSY_TEXT, $pipeline->status()['paused']['text'] ?? null, 'the dashboard shows the reason' );
        $this->assertSame( 6.53, $pipeline->status()['paused']['measured']['load'] ?? null, 'with its numbers' );
        $this->assertFalse( Container::get( Scheduler::class )->kick(), 'a kick starts nothing' );
        $this->assertSame( [], $GLOBALS['lga_test_as_async'] );
        $pipeline->tick();
        $this->assertSame( [ self::BUSY_TEXT ], $this->automationLog(), 'logged once over two ticks' );
        $row = Container::get( Logs::class )->findBy( [ 'channel' => 'automation' ], 'id', 'ASC', 1 )[0] ?? [];
        $context = is_array( $row['context'] ?? null ) ? $row['context'] : (array) json_decode( (string) ( $row['context'] ?? '' ), true );
        $this->assertSame( 6.53, $context['measured']['load_per_cpu'] ?? null, 'the log keeps the numbers' );
        $this->assertSame( LoadGuard::CODE_SERVER_BUSY, $context['code'] ?? null );

        // The load falls, the memory runs short: another reason, logged once.
        $this->load                              = [ 0.4, 1.0, 3.0 ];
        $this->files[ ServerResources::MEMINFO ] = self::meminfo( self::LIVE_TOTAL_KB, 50000 );
        $out                                     = $pipeline->tick();
        $this->assertSame( LoadGuard::CODE_LOW_MEMORY, $out['paused']['code'] ?? null );
        $this->assertSame( Jobs::STATUS_QUEUED, $jobs->find( $id )['status'] );
        $this->assertSame( self::MEMORY_TEXT, $pipeline->status()['paused']['text'] ?? null );
        $this->assertSame( [ self::BUSY_TEXT, self::MEMORY_TEXT ], $this->automationLog() );

        // Memory frees up: the next tick runs the waiting job and logs the resume.
        $this->files[ ServerResources::MEMINFO ] = self::meminfo( self::LIVE_TOTAL_KB, self::LIVE_AVAILABLE_KB );
        $out                                     = $pipeline->tick();
        $this->assertNull( $out['paused'] );
        $this->assertNotSame( 'paused', $out['skipped_reason'] );
        $this->assertSame( Jobs::STATUS_COMPLETED, $jobs->find( $id )['status'], 'nothing was lost' );
        $resumed = array_values( array_filter( $this->automationLog(), static fn( string $m ): bool => str_starts_with( $m, 'Background work resumed' ) ) );
        $this->assertCount( 1, $resumed, 'the resume is logged once' );
        $this->assertStringContainsString( '(the server is low on free memory)', $resumed[0] );
        $this->assertNull( $pipeline->status()['paused'] );
    }

    // ------------------------------------------------------------------
    // Site Health: the advice follows the server's memory
    // ------------------------------------------------------------------

    /** opcache_get_status(false) for any sizes (see LoadGuardTest::sizedStatus()). */
    private static function sizedStatus( int $memoryMb, float $usedPercent, int $stringsMb, float $internedPercent, int $cached = 9100, int $maxKeys = 65407, bool $full = false ): array {
        $total  = $memoryMb * 1048576;
        $free   = (int) round( $total * ( 100 - $usedPercent ) / 100 );
        $buffer = $stringsMb * 1048576;
        $sfree  = (int) round( $buffer * ( 100 - $internedPercent ) / 100 );
        return [
            'opcache_enabled'        => true,
            'cache_full'             => $full,
            'restart_pending'        => false,
            'restart_in_progress'    => false,
            'memory_usage'           => [ 'used_memory' => $total - $free, 'free_memory' => $free, 'wasted_memory' => 0, 'current_wasted_percentage' => 0.0 ],
            'interned_strings_usage' => [ 'buffer_size' => $buffer, 'used_memory' => $buffer - $sfree, 'free_memory' => $sfree, 'number_of_strings' => 9000 ],
            'opcache_statistics'     => [ 'num_cached_scripts' => $cached, 'num_cached_keys' => $cached, 'max_cached_keys' => $maxKeys, 'opcache_hit_rate' => 97.0 ],
        ];
    }

    private static function ini( string $memory, string $strings, string $files ): array {
        return [ 'opcache.memory_consumption' => $memory, 'opcache.interned_strings_buffer' => $strings, 'opcache.max_accelerated_files' => $files ];
    }

    /** The Site Health test on a server with $totalKb of memory (null: not known). */
    private function health( array $status, array $ini, ?int $totalKb ): SiteHealth {
        if ( null === $totalKb ) {
            unset( $this->files[ ServerResources::MEMINFO ] );
        } else {
            $this->files[ ServerResources::MEMINFO ] = self::meminfo( $totalKb, (int) ( $totalKb / 2 ) );
        }
        return new SiteHealth( new Opcache( static fn() => $status, $ini ), $this->server() );
    }

    private const INTRO = 'Raise these settings in the php.ini (or a file in the conf.d folder) that PHP-FPM uses, then restart PHP-FPM:';

    public function testA960MbServerIsAdvisedTheSmallSizesAndTheRamLine(): void {
        // The live sizes and state: 128 MB full, interned strings 8 MB full, 10000 files (table 16229).
        $health = $this->health( self::sizedStatus( 128, 99.99, 8, 100, 9100, 16229, true ), self::ini( '128', '8', '10000' ), self::LIVE_TOTAL_KB );
        $w      = $health->warning();
        $this->assertIsArray( $w );
        $this->assertSame( [ self::INTRO, 'opcache.memory_consumption=160 (now 128)', 'opcache.interned_strings_buffer=16 (now 8)', 'opcache.max_accelerated_files=20000 (now 10000)' ], $w['remedy'] );
        $this->assertSame( self::RAM_LINE, $w['memory'] );
        $this->assertSame( 'On managed hosting, ask your host to make this change.', $w['host'] );
        $this->assertSame( [ 'now' => '128', 'recommended' => '160' ], $w['settings']['opcache.memory_consumption'] );
        $r = $health->opcacheTest();
        $this->assertSame( 'critical', $r['status'] );
        foreach ( [ '<code>opcache.memory_consumption=160</code> (now 128)', '<code>opcache.interned_strings_buffer=16</code> (now 8)', '<code>opcache.max_accelerated_files=20000</code> (now 10000)', '<p>' . esc_html( self::RAM_LINE ) . '</p>' ] as $needle ) {
            $this->assertStringContainsString( $needle, $r['actions'] );
        }
        foreach ( [ '=256', '=32<', '=32531' ] as $large ) {
            $this->assertStringNotContainsString( $large, $r['actions'], 'not the large-server sizes: ' . $large );
        }

        // Recommended (almost full) on the same server: the same sizes and the same line.
        $r = $this->health( self::sizedStatus( 128, 92, 8, 40, 9100, 16229 ), self::ini( '128', '8', '10000' ), self::LIVE_TOTAL_KB )->opcacheTest();
        $this->assertSame( 'recommended', $r['status'] );
        $this->assertStringContainsString( '<code>opcache.memory_consumption=160</code> (now 128)', $r['actions'] );
        $this->assertStringContainsString( self::RAM_LINE, $r['actions'] );

        // The Overview carries the line.
        $c = Container::plugin()->container();
        $c->set( SiteHealth::class, $this->health( self::sizedStatus( 128, 99.99, 8, 100, 9100, 16229, true ), self::ini( '128', '8', '10000' ), self::LIVE_TOTAL_KB ) );
        $GLOBALS['lga_test_caps'] = [ 'manage_options' => true ];
        $data                       = ( new OverviewController( $c ) )->overview( new WP_REST_Request( 'GET', '/lga/v1/overview' ) );
        unset( $GLOBALS['lga_test_caps'] );
        $this->assertSame( self::RAM_LINE, $data['opcache']['memory'] ?? null );
        $this->assertSame( 'opcache.memory_consumption=160 (now 128)', $data['opcache']['remedy'][1] ?? null );
    }

    public function testAtTheSmallSizesAFullCacheIsAdvisedAboutDoubleUpToAQuarterOfTheMemory(): void {
        // 160 / 16 / 20000 (table 32531), memory full, interned strings full, 30000 of 32531 files.
        $w = $this->health( self::sizedStatus( 160, 99, 16, 100, 30000, 32531, true ), self::ini( '160', '16', '20000' ), self::LIVE_TOTAL_KB )->warning();
        $this->assertIsArray( $w );
        $this->assertSame(
            [ self::INTRO, 'opcache.memory_consumption=240 (now 160)', 'opcache.interned_strings_buffer=32 (now 16)', 'opcache.max_accelerated_files=65062 (now 20000)' ],
            $w['remedy'],
            'double is 320 MB; a quarter of 960 MB is 240 MB; the strings double inside it; the file table doubles'
        );
        $this->assertSame( self::RAM_LINE, $w['memory'] );

        // Between the small size and the limit: up to the limit, never past it, never below what the server has.
        $w = $this->health( self::sizedStatus( 200, 99, 24, 40, 9100, 32531, true ), self::ini( '200', '24', '30000' ), self::LIVE_TOTAL_KB )->warning();
        $this->assertSame( [ self::INTRO, 'opcache.memory_consumption=240 (now 200)' ], $w['remedy'] );

        // Already at the limit: nothing to raise; the advice says why and points at more memory.
        $health = $this->health( self::sizedStatus( 240, 99, 60, 40, 9100, 32531, true ), self::ini( '240', '60', '20000' ), self::LIVE_TOTAL_KB );
        $w      = $health->warning();
        $this->assertSame( [ "The code cache already has as much of this server's memory as it can spare (a quarter), so these settings stay as they are." ], $w['remedy'] );
        $this->assertSame( [ 'now' => '240', 'recommended' => '240' ], $w['settings']['opcache.memory_consumption'] );
        $this->assertSame( self::RAM_LINE, $w['memory'] );
        $this->assertSame( 'On managed hosting, ask your host for a plan with more memory.', $w['host'] );
        $actions = $health->opcacheTest()['actions'];
        $this->assertStringNotContainsString( '<ul>', $actions, 'no empty list of settings' );
        $this->assertStringContainsString( self::RAM_LINE, $actions );

        // Above the limit (256 MB on 960 MB): kept as it is, never lowered to 160 or 240.
        $w = $this->health( self::sizedStatus( 256, 99, 32, 100, 9100, 32531, true ), self::ini( '256', '32', '32531' ), self::LIVE_TOTAL_KB )->warning();
        $this->assertSame( [ self::INTRO, 'opcache.interned_strings_buffer=64 (now 32)' ], $w['remedy'], 'only the full strings buffer, at most a quarter of the 256 MB it lives in' );
        $this->assertSame( [ 'now' => '256', 'recommended' => '256' ], $w['settings']['opcache.memory_consumption'] );

        // 512 MB: a quarter is 128 MB, below the small size.
        $w = $this->health( self::sizedStatus( 64, 99, 8, 100, 3000, 7963, true ), self::ini( '64', '8', '4000' ), 524288 )->warning();
        $this->assertSame( [ self::INTRO, 'opcache.memory_consumption=128 (now 64)', 'opcache.interned_strings_buffer=16 (now 8)', 'opcache.max_accelerated_files=20000 (now 4000)' ], $w['remedy'] );
        $this->assertSame( 'This server has 512 MB of memory; a bigger code cache takes memory from PHP and the database, so more RAM is the lasting fix.', $w['memory'] );
    }

    public function testTwoGigabytesOrMoreAndUnknownMemoryKeepTheAdvice(): void {
        $servers = [
            'exactly 2 GiB'              => 2097152,
            'a 2 GB plan (1983 MiB)'     => 2030940,
            'the 1.75 GiB line'          => 1835008,
            '4 GB'                       => 4030000,
            'not known'                  => null,
        ];
        foreach ( $servers as $label => $kb ) {
            $health = $this->health( self::sizedStatus( 128, 99.99, 8, 100, 9100, 16229, true ), self::ini( '128', '8', '10000' ), $kb );
            $w      = $health->warning();
            $this->assertSame( [ self::INTRO, 'opcache.memory_consumption=256 (now 128)', 'opcache.interned_strings_buffer=32 (now 8)', 'opcache.max_accelerated_files=32531 (now 10000)' ], $w['remedy'], $label );
            $this->assertSame( '', $w['memory'], $label . ': no RAM line' );
            $this->assertStringNotContainsString( 'This server has', $health->opcacheTest()['actions'], $label );

            $w = $this->health( self::sizedStatus( 256, 99, 32, 100, 31000, 32531, true ), self::ini( '256', '32', '32531' ), $kb )->warning();
            $this->assertSame( [ self::INTRO, 'opcache.memory_consumption=512 (now 256)', 'opcache.interned_strings_buffer=64 (now 32)', 'opcache.max_accelerated_files=65062 (now 32531)' ], $w['remedy'], $label . ': doubled, no cap' );
        }
        $w = $this->health( self::sizedStatus( 128, 99.99, 8, 100, 9100, 16229, true ), self::ini( '128', '8', '10000' ), 1835007 )->warning();
        $this->assertSame( 'opcache.memory_consumption=160 (now 128)', $w['remedy'][1], 'just under 1.75 GiB is a small server' );
    }
}
