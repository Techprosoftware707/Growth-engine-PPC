<?php
/**
 * 1.2.4 Automation\LoadGuard, Support\Opcache and Admin\SiteHealth: every pause reason (OPcache restarting with
 * opcache_get_status stubbed, absent, refused with a warning or throwing; an UpdraftPlus event present, absent, far
 * ahead or long overdue; the update window; both filters), the answer kept per request, the once-per-pause log, a
 * full OPcache that never pauses anything, and the Site Health / Overview warning levels.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Automation;

use PPC\LinkGrowth\Admin\Rest\OverviewController;
use PPC\LinkGrowth\Admin\SiteHealth;
use PPC\LinkGrowth\Automation\LoadGuard;
use PPC\LinkGrowth\Automation\Pipeline;
use PPC\LinkGrowth\Compliance\StorefrontAudit;
use PPC\LinkGrowth\Repositories\Logs;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Opcache;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;
use PPC\LinkGrowth\Tests\Unit\Growth\FakeStorefront;
use PPC\LinkGrowth\Tests\Unit\Growth\GrowthFakeJob;
use RuntimeException;
use WP_REST_Request;

require_once __DIR__ . '/../Growth/GrowthFakes.php';

final class LoadGuardTest extends TestCase {

    private int $reads = 0;

    protected function setUp(): void {
        parent::setUp();
        $this->reads = 0;
        GrowthFakeJob::reset();
        $GLOBALS['ppclg_test_filters']['ppclg_job_types'] = [ static fn() => GrowthFakeJob::registry( [ 'import_verified' ] ) ];
        Container::plugin()->container()->set( StorefrontAudit::class, new FakeStorefront() );
        Container::get( Settings::class )->set( 'automation.enabled', true );
    }

    /**
     * An opcache_get_status(false) answer: $usedPercent of 128 MB in use (wasted included), interned strings
     * $internedPercent of 8 MB.
     *
     * @return array<string,mixed>
     */
    private static function opStatus( float $usedPercent = 40.0, float $internedPercent = 30.0, array $over = [] ): array {
        $total  = 134217728;
        $free   = (int) round( $total * ( 100 - $usedPercent ) / 100 );
        $wasted = (int) round( ( $total - $free ) * 0.1 );
        $buffer = 8388608;
        $sfree  = (int) round( $buffer * ( 100 - $internedPercent ) / 100 );
        return array_replace_recursive(
            [
                'opcache_enabled'        => true,
                'cache_full'             => false,
                'restart_pending'        => false,
                'restart_in_progress'    => false,
                'memory_usage'           => [ 'used_memory' => $total - $free - $wasted, 'free_memory' => $free, 'wasted_memory' => $wasted, 'current_wasted_percentage' => $wasted / $total * 100 ],
                'interned_strings_usage' => [ 'buffer_size' => $buffer, 'used_memory' => $buffer - $sfree, 'free_memory' => $sfree, 'number_of_strings' => 9000 ],
                'opcache_statistics'     => [ 'num_cached_scripts' => 4200, 'num_cached_keys' => 5000, 'max_cached_keys' => 16229, 'opcache_hit_rate' => 97.0 ],
            ],
            $over
        );
    }

    /** The live Site Health numbers: 134217016 of 134217728 bytes, interned strings 100 % of 8 MB, cache_full. */
    private static function liveStatus(): array {
        return [
            'opcache_enabled'        => true,
            'cache_full'             => true,
            'restart_pending'        => false,
            'restart_in_progress'    => false,
            'memory_usage'           => [ 'used_memory' => 120000000, 'free_memory' => 712, 'wasted_memory' => 14217016, 'current_wasted_percentage' => 10.6 ],
            'interned_strings_usage' => [ 'buffer_size' => 8388608, 'used_memory' => 8388608, 'free_memory' => 0, 'number_of_strings' => 61000 ],
            'opcache_statistics'     => [ 'num_cached_scripts' => 9100, 'num_cached_keys' => 16229, 'max_cached_keys' => 16229, 'opcache_hit_rate' => 97.0 ],
        ];
    }

    /** Installs an Opcache with the given reader in the container (and returns a fresh guard over it). */
    private function useOpcache( callable $reader ): LoadGuard {
        $opcache = new Opcache( function () use ( $reader ) {
            $this->reads++;
            return $reader();
        } );
        $c = Container::plugin()->container();
        $c->set( Opcache::class, $opcache );
        $guard = new LoadGuard( $opcache, self::quietServer() );
        $c->set( LoadGuard::class, $guard );
        return $guard;
    }

    private function updraftEvent( int $offset, string $hook = 'updraft_backup_resume', array $args = [ 3, 'feedc0ffee12' ] ): void {
        $cron = get_option( 'cron', [] );
        $cron = is_array( $cron ) ? $cron : [];
        $cron[ time() + $offset ][ $hook ][ md5( serialize( $args ) ) ] = [ 'schedule' => false, 'args' => $args ];
        $cron['version'] = 2;
        update_option( 'cron', $cron );
    }

    private function guard(): LoadGuard {
        return new LoadGuard( new Opcache( static fn() => false ), self::quietServer() );
    }

    /** The Site Health test over $opcache on a server whose memory is not known (the advice for any size). */
    private static function health( Opcache $opcache ): SiteHealth {
        return new SiteHealth( $opcache, self::quietServer() );
    }

    // ------------------------------------------------------------------
    // OPcache restarting
    // ------------------------------------------------------------------

    public function testOpcacheRestartPendingOrInProgressPauses(): void {
        $guard  = $this->useOpcache( static fn() => self::opStatus( 60, 50, [ 'restart_pending' => true ] ) );
        $reason = $guard->pauseReason();
        $this->assertSame( LoadGuard::CODE_OPCACHE_RESTART, $reason['code'] ?? null );
        $this->assertSame( "Paused: PHP's code cache is restarting (resumes automatically).", $reason['text'] );
        $this->assertNull( $reason['until'] );

        $guard = $this->useOpcache( static fn() => self::opStatus( 60, 50, [ 'restart_in_progress' => true ] ) );
        $this->assertSame( LoadGuard::CODE_OPCACHE_RESTART, $guard->pauseReason()['code'] ?? null );

        $guard = $this->useOpcache( static fn() => self::opStatus( 60, 50 ) );
        $this->assertNull( $guard->pauseReason() );
    }

    public function testMissingRefusedOrThrowingOpcacheStatusNeverPauses(): void {
        $readers = [
            'absent (false)'       => static fn() => false,
            'disabled'             => static fn() => [ 'opcache_enabled' => false, 'restart_pending' => true ],
            'restrict_api warning' => static function () {
                trigger_error( 'Zend OPcache API is restricted by "restrict_api" configuration directive', E_USER_WARNING ); // phpcs:ignore
                return false;
            },
            'throws'               => static function () {
                throw new RuntimeException( 'boom' );
            },
            'garbage'              => static fn() => 'yes',
        ];
        foreach ( $readers as $label => $reader ) {
            $guard = $this->useOpcache( $reader );
            $this->assertNull( $guard->pauseReason( true ), $label );
            $opcache = new Opcache( $reader );
            $this->assertNull( $opcache->status(), $label );
            $this->assertFalse( $opcache->restarting(), $label );
            $this->assertSame( Opcache::LEVEL_UNAVAILABLE, $opcache->pressure()['level'], $label );
        }
        // PHP's own function in the CLI (opcache.enable_cli is off here, or the function is missing): no status.
        $real = new Opcache();
        if ( ! function_exists( 'opcache_get_status' ) || ! ini_get( 'opcache.enable_cli' ) ) {
            $this->assertNull( $real->status() );
        }
    }

    public function testAFullOpcacheAloneNeverPausesAndTheTickRuns(): void {
        $guard = $this->useOpcache( static fn() => self::liveStatus() );
        $this->assertNull( $guard->pauseReason(), 'cache_full, no free memory, full interned strings: still no pause' );
        $this->assertSame( Opcache::LEVEL_CRITICAL, Container::get( Opcache::class )->pressure()['level'] );

        $out = Container::get( Pipeline::class )->tick();
        $this->assertSame( '', $out['skipped_reason'] );
        $this->assertNull( $out['paused'] );
        $this->assertNotSame( [], GrowthFakeJob::$runs, 'Autopilot keeps working with a full cache' );
    }

    public function testTheAnswerIsKeptForTheRequestAndTheTickAsksAfresh(): void {
        $restarting = true;
        $guard      = $this->useOpcache( static function () use ( &$restarting ) {
            return self::opStatus( 50, 50, [ 'restart_pending' => $restarting ] );
        } );
        $this->assertNotNull( $guard->pauseReason() );
        $restarting = false;
        $this->assertNotNull( $guard->pauseReason(), 'kept for the rest of the request' );
        $this->assertSame( 1, $this->reads, 'opcache_get_status() was called once' );
        $this->assertNull( $guard->pauseReason( true ), 'fresh: asked again' );
        $this->assertSame( 2, $this->reads );

        // Pipeline::tick() asks afresh each time (a long Action Scheduler batch runs several ticks in one request).
        $restarting = true;
        $this->assertSame( 'paused', Container::get( Pipeline::class )->tick()['skipped_reason'] );
        $restarting = false;
        $this->assertSame( '', Container::get( Pipeline::class )->tick()['skipped_reason'] );

        $guard->forget();
        $restarting = true;
        $this->assertNotNull( $guard->pauseReason() );
    }

    // ------------------------------------------------------------------
    // Backups
    // ------------------------------------------------------------------

    public function testUpdraftResumptionEventWindow(): void {
        $cases = [
            'due in 4 minutes'          => [ 240, true ],
            'due in 29 minutes'         => [ 29 * 60, true ],
            'due in 31 minutes'         => [ 31 * 60, false ],
            'due tomorrow'              => [ DAY_IN_SECONDS, false ],
            'overdue by 5 minutes'      => [ -300, true ],
            'overdue by 59 minutes'     => [ -59 * 60, true ],
            'overdue by 2 hours'        => [ -2 * HOUR_IN_SECONDS, false ],
        ];
        foreach ( $cases as $label => [ $offset, $paused ] ) {
            delete_option( 'cron' );
            $this->updraftEvent( $offset );
            $reason = $this->guard()->pauseReason();
            $this->assertSame( $paused ? LoadGuard::CODE_BACKUP : null, $reason['code'] ?? null, $label );
            if ( $paused ) {
                $this->assertSame( 'Paused: a backup is running (resumes automatically).', $reason['text'], $label );
                $this->assertSame( 'updraftplus', $reason['source'] );
            }
        }
        delete_option( 'cron' );
        $this->assertNull( $this->guard()->pauseReason(), 'no event: no pause' );
    }

    public function testUpdraftJobRecordDecidesALeftoverEvent(): void {
        $this->updraftEvent( 120 );
        update_option( 'updraft_jobdata_feedc0ffee12', [ 'jobstatus' => 'finished' ] );
        $this->assertNull( $this->guard()->pauseReason(), 'finished job: leftover event' );
        update_option( 'updraft_jobdata_feedc0ffee12', [ 'jobstatus' => 'filescreating' ] );
        $this->assertSame( LoadGuard::CODE_BACKUP, $this->guard()->pauseReason()['code'] ?? null );
        delete_option( 'updraft_jobdata_feedc0ffee12' );
        $this->assertSame( LoadGuard::CODE_BACKUP, $this->guard()->pauseReason()['code'] ?? null, 'no record: trust the event' );

        // One finished and one running backup: running wins.
        update_option( 'updraft_jobdata_feedc0ffee12', [ 'jobstatus' => 'finished' ] );
        $this->updraftEvent( 300, 'updraft_backup_resume', [ 1, 'abcdef123456' ] );
        $this->assertSame( LoadGuard::CODE_BACKUP, $this->guard()->pauseReason()['code'] ?? null );
    }

    public function testBackupNowEventsAndMalformedCronEntries(): void {
        foreach ( LoadGuard::UPDRAFT_START_HOOKS as $hook ) {
            delete_option( 'cron' );
            $this->updraftEvent( 3, $hook, [] );
            $this->assertSame( LoadGuard::CODE_BACKUP, $this->guard()->pauseReason()['code'] ?? null, $hook );
        }
        update_option( 'cron', [ 'version' => 2, 'x' => 'y', time() + 60 => 'not-an-array', time() + 61 => [ 'updraft_backup_resume' => 'nope' ], time() + 62 => [ 'other_hook' => [ 'k' => [ 'args' => [] ] ] ] ] );
        $this->assertNull( $this->guard()->pauseReason() );
        update_option( 'cron', 'corrupt' );
        $this->assertNull( $this->guard()->pauseReason() );
    }

    public function testOtherBackupPluginsReportThroughTheFilter(): void {
        add_filter( LoadGuard::FILTER_BACKUP, static fn(): bool => true );
        $reason = $this->guard()->pauseReason();
        $this->assertSame( LoadGuard::CODE_BACKUP, $reason['code'] ?? null );
        $this->assertSame( 'filter', $reason['source'] );

        $GLOBALS['ppclg_test_filters'][ LoadGuard::FILTER_BACKUP ] = [ static fn(): string => '<b>BackWPup</b>' ];
        $this->assertSame( 'BackWPup', $this->guard()->pauseReason()['source'] ?? null );

        $GLOBALS['ppclg_test_filters'][ LoadGuard::FILTER_BACKUP ] = [ static fn(): string => '   ' ];
        $this->assertNull( $this->guard()->pauseReason() );
    }

    // ------------------------------------------------------------------
    // Just updated
    // ------------------------------------------------------------------

    public function testVersionChangeStartsATenMinuteWindow(): void {
        update_option( LoadGuard::OPTION_VERSION, [ 'version' => '1.2.3', 'since' => time() - WEEK_IN_SECONDS, 'from' => '1.2.2' ] );
        $reason = $this->guard()->pauseReason();
        $this->assertSame( LoadGuard::CODE_UPDATED, $reason['code'] ?? null );
        $this->assertSame( 'Paused: the plugin was just updated (resumes automatically in about 10 minutes).', $reason['text'] );
        $stored = get_option( LoadGuard::OPTION_VERSION );
        $this->assertSame( [ 'version' => PPCLG_VERSION, 'from' => '1.2.3' ], [ 'version' => $stored['version'], 'from' => $stored['from'] ] );
        $this->assertEqualsWithDelta( time() + 600, strtotime( (string) $reason['until'] ), 2 );

        $since = time() - 9 * 60 - 30;
        update_option( LoadGuard::OPTION_VERSION, [ 'version' => PPCLG_VERSION, 'since' => $since, 'from' => '1.2.3' ] );
        $reason = $this->guard()->pauseReason();
        $this->assertSame( 'Paused: the plugin was just updated (resumes automatically in about 1 minute).', $reason['text'] );

        update_option( LoadGuard::OPTION_VERSION, [ 'version' => PPCLG_VERSION, 'since' => time() - 600, 'from' => '1.2.3' ] );
        $this->assertNull( $this->guard()->pauseReason(), 'the window is over' );
        update_option( LoadGuard::OPTION_VERSION, [ 'version' => PPCLG_VERSION, 'since' => time() + 3600, 'from' => '' ] );
        $this->assertNull( $this->guard()->pauseReason(), 'a time in the future (clock change) does not pause for an hour' );
        $this->assertSame( time() + 3600, get_option( LoadGuard::OPTION_VERSION )['since'], 'and is not rewritten for the same version' );
    }

    public function testNoteVersionWritesOnlyWhenTheVersionChanges(): void {
        $guard  = $this->guard();
        $before = get_option( LoadGuard::OPTION_VERSION );
        $guard->noteVersion();
        $this->assertSame( $before, get_option( LoadGuard::OPTION_VERSION ), 'same version: nothing written' );

        delete_option( LoadGuard::OPTION_VERSION );
        $guard->noteVersion();
        $stored = get_option( LoadGuard::OPTION_VERSION );
        $this->assertSame( PPCLG_VERSION, $stored['version'] );
        $this->assertSame( '', $stored['from'] );
        $this->assertEqualsWithDelta( time(), $stored['since'], 2 );
    }

    public function testActivationStartsTheWindowEvenForTheSameVersion(): void {
        $guard = $this->guard();
        $this->assertNull( $guard->pauseReason() );
        $guard->recordActivation();
        $this->assertSame( LoadGuard::CODE_UPDATED, $guard->pauseReason()['code'] ?? null, 'forgets the earlier answer' );
        $this->assertSame( PPCLG_VERSION, get_option( LoadGuard::OPTION_VERSION )['version'] );
    }

    // ------------------------------------------------------------------
    // The owner's filter and the order of reasons
    // ------------------------------------------------------------------

    public function testPauseFilterAddsOrOverridesAReason(): void {
        $seen = [];
        add_filter( LoadGuard::FILTER_PAUSE, static function ( $pause, $reason ) use ( &$seen ) {
            $seen[] = [ $pause, $reason['code'] ?? null ];
            return $pause;
        }, 10, 2 );
        $this->assertNull( $this->guard()->pauseReason(), 'returning the value unchanged changes nothing' );
        $this->updraftEvent( 60 );
        $this->assertSame( LoadGuard::CODE_BACKUP, $this->guard()->pauseReason()['code'] ?? null );
        $this->assertSame( [ [ false, null ], [ true, LoadGuard::CODE_BACKUP ] ], $seen, 'the filter sees the built-in reason' );

        $GLOBALS['ppclg_test_filters'][ LoadGuard::FILTER_PAUSE ] = [ static fn(): bool => false ];
        $this->assertNull( $this->guard()->pauseReason(), 'false lets the work run' );

        delete_option( 'cron' );
        $GLOBALS['ppclg_test_filters'][ LoadGuard::FILTER_PAUSE ] = [ static fn(): bool => true ];
        $reason = $this->guard()->pauseReason();
        $this->assertSame( [ LoadGuard::CODE_OWNER, 'Paused: background work was paused for this site.' ], [ $reason['code'], $reason['text'] ] );

        $GLOBALS['ppclg_test_filters'][ LoadGuard::FILTER_PAUSE ] = [ static fn(): string => "  <script>x</script>Host   maintenance.  " ];
        $reason = $this->guard()->pauseReason();
        $this->assertSame( 'Paused: xHost maintenance.', $reason['text'], 'tags stripped, spaces collapsed' );
        $this->assertSame( 'Background work is paused: xHost maintenance.', LoadGuard::warning( $reason ) );

        $GLOBALS['ppclg_test_filters'][ LoadGuard::FILTER_PAUSE ] = [ static fn() => 1 ];
        $this->assertNull( $this->guard()->pauseReason(), 'only true, false or a message count' );
    }

    public function testReasonsAreReportedMostSevereFirst(): void {
        update_option( LoadGuard::OPTION_VERSION, [ 'version' => PPCLG_VERSION, 'since' => time() - 60, 'from' => '1.2.3' ] );
        $this->updraftEvent( 60 );
        $guard = $this->useOpcache( static fn() => self::opStatus( 50, 50, [ 'restart_pending' => true ] ) );
        $this->assertSame( LoadGuard::CODE_OPCACHE_RESTART, $guard->pauseReason()['code'] );
        $guard = $this->useOpcache( static fn() => self::opStatus( 50, 50 ) );
        $this->assertSame( LoadGuard::CODE_BACKUP, $guard->pauseReason()['code'] );
        delete_option( 'cron' );
        $this->assertSame( LoadGuard::CODE_UPDATED, $guard->pauseReason( true )['code'] );
        $this->assertSame( 'Background work is paused: the plugin was just updated. It resumes automatically.', LoadGuard::warning( $guard->pauseReason() ) );
    }

    // ------------------------------------------------------------------
    // Record and log once per pause
    // ------------------------------------------------------------------

    public function testTrackLogsOncePerPauseAndOnceOnResume(): void {
        $guard  = $this->guard();
        $logger = Container::get( Logger::class );
        $logs   = Container::get( Logs::class );
        $backup = [ 'code' => 'backup', 'message' => 'a backup is running', 'text' => 'Paused: a backup is running (resumes automatically).', 'until' => null, 'source' => 'updraftplus' ];

        $first = $guard->track( $backup, $logger );
        $this->assertNotEmpty( $first['since'] );
        $again = $guard->track( $backup, $logger );
        $this->assertSame( $first['since'], $again['since'], 'the same pause keeps its start' );
        $this->assertSame( 1, $logs->count( [ 'channel' => 'automation' ] ) );
        $this->assertSame( 'backup', $guard->recorded()['code'] );

        $update = [ 'code' => 'just_updated', 'message' => 'the plugin was just updated', 'text' => 'Paused: the plugin was just updated (resumes automatically in about 10 minutes).', 'until' => gmdate( 'c', time() + 600 ), 'source' => 'update' ];
        $guard->track( $update, $logger );
        $this->assertSame( 2, $logs->count( [ 'channel' => 'automation' ] ), 'another reason is logged' );

        $this->assertNull( $guard->track( null, $logger ) );
        $this->assertNull( $guard->recorded() );
        $messages = array_column( $logs->findBy( [ 'channel' => 'automation' ], 'id', 'ASC', 10 ), 'message' );
        $this->assertCount( 3, $messages );
        $this->assertStringStartsWith( 'Background work resumed', $messages[2] );
        $this->assertStringContainsString( 'the plugin was just updated', $messages[2] );
        $guard->track( null, $logger );
        $this->assertSame( 3, $logs->count( [ 'channel' => 'automation' ] ), 'nothing more while running' );
    }

    public function testStatusCarriesWhenThePauseBegan(): void {
        $this->updraftEvent( 120 );
        $guard  = $this->guard();
        $status = $guard->status();
        $this->assertSame( 'backup', $status['code'] );
        $this->assertNull( $status['since'], 'no tick recorded it yet' );
        $guard->track( $guard->pauseReason(), null );
        $this->assertNotNull( $guard->status()['since'] );
        delete_option( 'cron' );
        $this->assertNull( ( $this->guard() )->status() );
    }

    // ------------------------------------------------------------------
    // How full the cache is
    // ------------------------------------------------------------------

    public function testClassifyLevels(): void {
        $live = Opcache::classify( self::liveStatus() );
        $this->assertSame( Opcache::LEVEL_CRITICAL, $live['level'], 'the live numbers' );
        $this->assertTrue( $live['cache_full'] );
        $this->assertSame( 134217728, $live['memory']['total'] );
        $this->assertLessThan( 0.01, $live['memory']['free_percent'] );
        $this->assertSame( 100.0, $live['interned']['used_percent'] );
        $this->assertCount( 3, $live['problems'] );

        $this->assertSame( Opcache::LEVEL_CRITICAL, Opcache::classify( self::opStatus( 40, 30, [ 'cache_full' => true ] ) )['level'], 'cache_full alone' );
        $this->assertSame( Opcache::LEVEL_CRITICAL, Opcache::classify( self::opStatus( 96, 30 ) )['level'], 'less than 5 % free' );
        $this->assertSame( Opcache::LEVEL_CRITICAL, Opcache::classify( self::opStatus( 40, 99.5 ) )['level'], 'interned strings full' );
        $this->assertSame( Opcache::LEVEL_RECOMMENDED, Opcache::classify( self::opStatus( 92, 30 ) )['level'], 'memory above 90 %' );
        $this->assertSame( Opcache::LEVEL_RECOMMENDED, Opcache::classify( self::opStatus( 40, 93 ) )['level'], 'interned above 90 %' );
        $this->assertSame( Opcache::LEVEL_GOOD, Opcache::classify( self::opStatus( 90, 90 ) )['level'], 'exactly 90 % is fine' );
        $this->assertSame( Opcache::LEVEL_GOOD, Opcache::classify( self::opStatus( 40, 30 ) )['level'] );
        $this->assertSame( Opcache::LEVEL_UNAVAILABLE, Opcache::classify( null )['level'] );
        $this->assertSame( Opcache::LEVEL_UNAVAILABLE, Opcache::classify( [ 'opcache_enabled' => false ] )['level'] );
        $this->assertSame( Opcache::LEVEL_GOOD, Opcache::classify( [ 'opcache_enabled' => true ] )['level'], 'no numbers: nothing to warn about' );
    }

    // ------------------------------------------------------------------
    // Site Health
    // ------------------------------------------------------------------

    public function testSiteHealthCriticalWithTheExactRemedy(): void {
        $health = self::health( new Opcache( static fn() => self::liveStatus() ) );
        $r      = $health->opcacheTest();
        $this->assertSame( 'critical', $r['status'] );
        $this->assertSame( "PHP's code cache (OPcache) is full", $r['label'] );
        $this->assertSame( 'red', $r['badge']['color'] );
        $this->assertSame( 'ppclg_opcache', $r['test'] );
        $this->assertStringContainsString( 'That memory is full.', $r['description'] );
        $this->assertStringContainsString( 'Memory in use: 128 MB of 128 MB (100%), of which 13.6 MB is held by code replaced in updates.', $r['description'] );
        $this->assertStringContainsString( 'Interned strings: 100% of 8 MB.', $r['description'] );
        foreach ( [ 'opcache.memory_consumption=256', 'opcache.interned_strings_buffer=32', 'opcache.max_accelerated_files=32531' ] as $setting ) {
            $this->assertStringContainsString( '<code>' . $setting . '</code>', $r['actions'] );
        }
        $this->assertStringContainsString( 'restart PHP-FPM', $r['actions'] );
        $this->assertStringContainsString( 'php.ini', $r['actions'] );
        $this->assertStringContainsString( 'conf.d', $r['actions'] );
        $this->assertStringContainsString( 'On managed hosting, ask your host to make this change.', $r['actions'] );
        $this->assertStringContainsString( '(now ' . ini_get( 'opcache.memory_consumption' ) . ')', $r['actions'] );

        foreach ( [ 'free < 5 %' => self::opStatus( 97, 30 ), 'interned full' => self::opStatus( 30, 100 ), 'cache_full' => self::opStatus( 30, 30, [ 'cache_full' => true ] ) ] as $label => $status ) {
            $this->assertSame( 'critical', ( self::health( new Opcache( static fn() => $status ) ) )->opcacheTest()['status'], $label );
        }
    }

    public function testSiteHealthRecommendedAndGood(): void {
        $r = ( self::health( new Opcache( static fn() => self::opStatus( 92, 40 ) ) ) )->opcacheTest();
        $this->assertSame( [ 'recommended', "PHP's code cache (OPcache) is almost full", 'orange' ], [ $r['status'], $r['label'], $r['badge']['color'] ] );
        $this->assertStringContainsString( 'opcache.memory_consumption=256', $r['actions'] );

        $r = ( self::health( new Opcache( static fn() => self::opStatus( 60, 60 ) ) ) )->opcacheTest();
        $this->assertSame( [ 'good', "PHP's code cache (OPcache) has room", '' ], [ $r['status'], $r['label'], $r['actions'] ] );

        foreach ( [ static fn() => false, static function () {
            throw new RuntimeException( 'restricted' );
        } ] as $reader ) {
            $r = ( self::health( new Opcache( $reader ) ) )->opcacheTest();
            $this->assertSame( 'good', $r['status'], 'unavailable: nothing to report' );
            $this->assertSame( "PHP's code cache (OPcache) has nothing to report", $r['label'] );
        }
    }

    /**
     * An opcache_get_status(false) answer for any sizes: $usedPercent of $memoryMb in use (nothing wasted), the
     * interned strings $internedPercent of $stringsMb, $cached of $maxKeys files.
     *
     * @return array<string,mixed>
     */
    private static function sizedStatus( int $memoryMb, float $usedPercent, int $stringsMb, float $internedPercent, int $cached = 9100, int $maxKeys = 65407 ): array {
        $total  = $memoryMb * 1048576;
        $free   = (int) round( $total * ( 100 - $usedPercent ) / 100 );
        $buffer = $stringsMb * 1048576;
        $sfree  = (int) round( $buffer * ( 100 - $internedPercent ) / 100 );
        return [
            'opcache_enabled'        => true,
            'cache_full'             => false,
            'restart_pending'        => false,
            'restart_in_progress'    => false,
            'memory_usage'           => [ 'used_memory' => $total - $free, 'free_memory' => $free, 'wasted_memory' => 0, 'current_wasted_percentage' => 0.0 ],
            'interned_strings_usage' => [ 'buffer_size' => $buffer, 'used_memory' => $buffer - $sfree, 'free_memory' => $sfree, 'number_of_strings' => 9000 ],
            'opcache_statistics'     => [ 'num_cached_scripts' => $cached, 'num_cached_keys' => $cached, 'max_cached_keys' => $maxKeys, 'opcache_hit_rate' => 97.0 ],
        ];
    }

    /** The php.ini values the Site Health test reads (memory_consumption, interned_strings_buffer, max_accelerated_files). */
    private static function iniSizes( string $memory, string $strings, string $files ): array {
        return [ 'opcache.memory_consumption' => $memory, 'opcache.interned_strings_buffer' => $strings, 'opcache.max_accelerated_files' => $files ];
    }

    /** Every remedy line raises its setting: the value it names is above the current one. */
    private function assertEveryLineRaises( array $remedy, string $label ): void {
        $this->assertStringStartsWith( 'Raise these settings', (string) array_shift( $remedy ), $label );
        $this->assertNotEmpty( $remedy, $label . ': at least one setting to change' );
        foreach ( $remedy as $line ) {
            $this->assertMatchesRegularExpression( '/^opcache\.[a-z_]+=(\d+)(?: \(now (\d+)\))?$/', $line, $label );
            preg_match( '/=(\d+)(?: \(now (\d+)\))?$/', $line, $m );
            if ( isset( $m[2] ) ) {
                $this->assertGreaterThan( (int) $m[2], (int) $m[1], $label . ': ' . $line );
            }
        }
    }

    public function testSiteHealthRemedyNeverAdvisesSmallerSettingsThanTheServerHas(): void {
        // Already above the recommendation (512 / 64 / 60000), memory 97 % used, interned strings full, files have room.
        $health = self::health( new Opcache( static fn() => self::sizedStatus( 512, 97, 64, 100, 9100, 65407 ), self::iniSizes( '512', '64', '60000' ) ) );
        $r      = $health->opcacheTest();
        $this->assertSame( 'critical', $r['status'] );
        $this->assertStringContainsString( '<code>opcache.memory_consumption=1024</code> (now 512)', $r['actions'], 'about double the current size' );
        $this->assertStringContainsString( '<code>opcache.interned_strings_buffer=128</code> (now 64)', $r['actions'] );
        $this->assertStringNotContainsString( 'opcache.max_accelerated_files', $r['actions'], '9,100 of 65,407 files: nothing to change' );
        foreach ( [ 'memory_consumption=256', 'interned_strings_buffer=32<', 'max_accelerated_files=32531' ] as $smaller ) {
            $this->assertStringNotContainsString( $smaller, $r['actions'], 'never a smaller value: ' . $smaller );
        }
        $w = $health->warning();
        $this->assertIsArray( $w );
        $this->assertSame( [ 'Raise these settings in the php.ini (or a file in the conf.d folder) that PHP-FPM uses, then restart PHP-FPM:', 'opcache.memory_consumption=1024 (now 512)', 'opcache.interned_strings_buffer=128 (now 64)' ], $w['remedy'] );
        $this->assertSame( [ 'now' => '512', 'recommended' => '1024' ], $w['settings']['opcache.memory_consumption'] );
        $this->assertSame( [ 'now' => '64', 'recommended' => '128' ], $w['settings']['opcache.interned_strings_buffer'] );
        $this->assertSame( [ 'now' => '60000', 'recommended' => '60000' ], $w['settings']['opcache.max_accelerated_files'], 'kept as it is' );

        // Exactly the recommended sizes and full again after updates: never "raise to the value it already has".
        $health = self::health( new Opcache( static fn() => self::sizedStatus( 256, 97, 32, 50, 31000, 32531 ), self::iniSizes( '256', '32', '32531' ) ) );
        $w      = $health->warning();
        $this->assertIsArray( $w );
        $this->assertSame( [ 'opcache.memory_consumption=512 (now 256)', 'opcache.max_accelerated_files=65062 (now 32531)' ], array_slice( $w['remedy'], 1 ), 'the interned strings have room' );
        $this->assertEveryLineRaises( $w['remedy'], 'at the recommendation' );
        $this->assertEveryLineRaises( self::actionLines( $health->opcacheTest()['actions'] ), 'Site Health at the recommendation' );

        // Below the recommendation the recommended sizes stay the advice; a setting above it with room is left out.
        $health = self::health( new Opcache( static fn() => self::sizedStatus( 128, 97, 64, 30, 9100, 65407 ), self::iniSizes( '128', '64', '60000' ) ) );
        $this->assertSame( [ 'opcache.memory_consumption=256 (now 128)' ], array_slice( (array) $health->warning()['remedy'], 1 ) );
        $health = self::health( new Opcache( static fn() => self::sizedStatus( 128, 97, 8, 100, 9100, 16229 ), self::iniSizes( '128', '8', '10000' ) ) );
        $this->assertSame( [ 'opcache.memory_consumption=256 (now 128)', 'opcache.interned_strings_buffer=32 (now 8)', 'opcache.max_accelerated_files=32531 (now 10000)' ], array_slice( (array) $health->warning()['remedy'], 1 ), 'the live sizes: unchanged advice' );

        // OPcache takes at most 1000000 files; recommended (almost full) gets the same check.
        $health = self::health( new Opcache( static fn() => self::sizedStatus( 2048, 92, 256, 40, 500000, 524521 ), self::iniSizes( '2048', '256', '400000' ) ) );
        $r      = $health->opcacheTest();
        $this->assertSame( 'recommended', $r['status'] );
        $this->assertEveryLineRaises( self::actionLines( $r['actions'] ), 'recommended above the recommendation' );
        $this->assertStringContainsString( '<code>opcache.memory_consumption=4096</code> (now 2048)', $r['actions'] );
        $this->assertStringContainsString( '<code>opcache.max_accelerated_files=1000000</code> (now 400000)', $r['actions'] );
        $health = self::health( new Opcache( static fn() => self::sizedStatus( 2048, 97, 256, 40, 1000000, 1048793 ), self::iniSizes( '2048', '256', '1000000' ) ) );
        $this->assertSame( [ 'opcache.memory_consumption=4096 (now 2048)' ], array_slice( (array) $health->warning()['remedy'], 1 ), 'no larger file table exists' );

        // OPcache rounds the file count up to its next table size (max_cached_keys): the advice is larger than that.
        $health = self::health( new Opcache( static fn() => self::sizedStatus( 512, 97, 64, 40, 62000, 65407 ), self::iniSizes( '512', '64', '40000' ) ) );
        $this->assertSame( [ 'opcache.memory_consumption=1024 (now 512)', 'opcache.max_accelerated_files=130814 (now 40000)' ], array_slice( (array) $health->warning()['remedy'], 1 ) );
        $health = self::health( new Opcache( static fn() => self::sizedStatus( 256, 97, 32, 30, 9100, 32531 ), self::iniSizes( '256', '32', '30000' ) ) );
        $this->assertSame( [ 'opcache.memory_consumption=512 (now 256)' ], array_slice( (array) $health->warning()['remedy'], 1 ), '30000 already gives the 32531 table, with room' );
    }

    /** The Site Health actions as remedy lines ("intro", "setting=value (now x)", …). */
    private static function actionLines( string $actions ): array {
        preg_match( '#<p>(.*?)</p>#', $actions, $intro );
        preg_match_all( '#<li><code>(.*?)</code>(.*?)</li>#', $actions, $items, PREG_SET_ORDER );
        return array_merge( [ html_entity_decode( $intro[1] ?? '', ENT_QUOTES ) ], array_map( static fn( array $m ): string => $m[1] . $m[2], $items ) );
    }

    public function testSiteHealthSaysWhichPartOfTheCacheIsFull(): void {
        // Only the interned strings buffer is full; the cache memory is 40 % used: still critical, but no claim
        // that PHP stops caching code or that pages time out.
        $status = self::sizedStatus( 128, 40, 8, 100, 4200, 16229 );
        $health = self::health( new Opcache( static fn() => $status, self::iniSizes( '128', '8', '10000' ) ) );
        $r      = $health->opcacheTest();
        $this->assertSame( 'critical', $r['status'] );
        $this->assertStringContainsString( 'Memory in use: 51.2 MB of 128 MB (40%).', $r['description'] );
        $this->assertStringContainsString( 'Interned strings: 100% of 8 MB.', $r['description'] );
        $this->assertStringContainsString( 'interned strings buffer', $r['description'] );
        $this->assertStringContainsString( 'PHP still caches code', $r['description'] );
        foreach ( [ 'That memory is full', 'PHP stops caching code', 'HTTP 504', 'restarts the cache' ] as $false ) {
            $this->assertStringNotContainsString( $false, $r['description'], $false );
        }
        $this->assertStringContainsString( '<code>opcache.interned_strings_buffer=32</code> (now 8)', $r['actions'] );
        $w = $health->warning();
        $this->assertIsArray( $w );
        $this->assertStringContainsString( 'PHP still caches code', $w['summary'] );
        $this->assertStringNotContainsString( 'That memory is full', $w['summary'] );

        // Almost full: the same distinction.
        $r = ( self::health( new Opcache( static fn() => self::sizedStatus( 128, 40, 8, 93, 4200, 16229 ) ) ) )->opcacheTest();
        $this->assertSame( 'recommended', $r['status'] );
        $this->assertStringContainsString( 'interned strings buffer', $r['description'] );
        $this->assertStringNotContainsString( 'That memory is almost full', $r['description'] );

        // When the cache memory itself is full the 504 explanation stays, also with full interned strings.
        foreach ( [ 'memory' => self::sizedStatus( 128, 97, 8, 30 ), 'both' => self::sizedStatus( 128, 97, 8, 100 ), 'cache_full' => [ 'cache_full' => true ] + self::sizedStatus( 128, 40, 8, 100 ) ] as $label => $full ) {
            $d = ( self::health( new Opcache( static fn() => $full ) ) )->opcacheTest()['description'];
            $this->assertStringContainsString( 'That memory is full.', $d, $label );
            $this->assertStringContainsString( 'HTTP 504', $d, $label );
        }
        $d = ( self::health( new Opcache( static fn() => self::sizedStatus( 128, 92, 8, 30 ) ) ) )->opcacheTest()['description'];
        $this->assertStringContainsString( 'That memory is almost full.', $d );
    }

    public function testSiteHealthKeepsOtherTestsAndToleratesOddInput(): void {
        $health = self::health( new Opcache( static fn() => false ) );
        $tests  = $health->addTests( [ 'direct' => [ 'php_version' => [ 'label' => 'PHP', 'test' => 'php_version' ] ], 'async' => [ 'dotorg' => [] ] ] );
        $this->assertSame( [ 'php_version', 'ppclg_opcache' ], array_keys( $tests['direct'] ) );
        $this->assertArrayHasKey( 'dotorg', $tests['async'] );
        $this->assertArrayHasKey( 'ppclg_opcache', $health->addTests( null )['direct'] );
        $this->assertArrayHasKey( 'ppclg_opcache', $health->addTests( [ 'direct' => 'x' ] )['direct'] );
    }

    public function testOverviewCarriesTheWarningOnlyWhenCritical(): void {
        $c = Container::plugin()->container();
        $c->set( SiteHealth::class, self::health( new Opcache( static fn() => self::liveStatus() ) ) );
        $GLOBALS['ppclg_test_caps'] = [ 'manage_options' => true ];
        $data = ( new OverviewController( $c ) )->overview( new WP_REST_Request( 'GET', '/ppclg/v1/overview' ) );
        $this->assertIsArray( $data['opcache'] );
        $this->assertSame( 'critical', $data['opcache']['level'] );
        $this->assertSame( "PHP's code cache (OPcache) is full", $data['opcache']['title'] );
        $this->assertStringStartsWith( 'Raise these settings', $data['opcache']['remedy'][0] );
        $this->assertStringStartsWith( 'opcache.memory_consumption=256', $data['opcache']['remedy'][1] );
        $this->assertSame( '256', $data['opcache']['settings']['opcache.memory_consumption']['recommended'] );
        $this->assertSame( 'On managed hosting, ask your host to make this change.', $data['opcache']['host'] );
        $this->assertArrayHasKey( 'paused', $data['automation'], 'the automation status says whether work is paused' );

        foreach ( [ self::opStatus( 92, 40 ), self::opStatus( 40, 40 ) ] as $status ) {
            $c->set( SiteHealth::class, self::health( new Opcache( static fn() => $status ) ) );
            $data = ( new OverviewController( $c ) )->overview( new WP_REST_Request( 'GET', '/ppclg/v1/overview' ) );
            $this->assertNull( $data['opcache'], 'recommended or good: Site Health only' );
        }
        unset( $GLOBALS['ppclg_test_caps'] );
    }
}
