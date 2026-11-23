<?php
/**
 * Golden scheduler test (DESIGN.md §13.2 WP2: "the scheduler produces the same enqueue sequence as before for a
 * frozen clock"). Written on the WP1 fork state (e160656), before WP2 replaces Pipeline::stages() with the task
 * registry; tests/fixtures/golden/scheduler.json holds what the scheduler enqueued then.
 *
 * Each scenario is a fresh install at T0 (the activation entry point, Activator::activate(), with the default
 * settings), followed by the same 389 ticks over 8 days: at 60, 300 and 599 s (inside the 10-minute quiet window after
 * an activation), 600 s (its end) and 900 s, then every 30 minutes. (In the unit environment the activation's migration
 * step cannot list tables, FakeWpdb has no SHOW TABLES, and logs that; the tables exist already, as they do after the
 * migration of a real install.) A tick is what cron runs: a new request
 * (fresh container, the plugin booted) that fires the `lga_tick` hook. Between ticks a scenario changes settings
 * through the Settings service, pauses background work through the `lga_pause_background` filter, or adds the rows
 * a due-check looks for straight into the plugin's tables (the storage, not a repository class, so the scenario
 * does not depend on where WP2 puts those classes).
 *
 * The recorded sequence is every job row the ticks created, in order: [time of the tick, job type, priority] (plus
 * the payload and the delay when they are not empty). Jobs do not run: the test holds the runner lock (as another
 * process running the queue would) and, after each tick, marks the jobs that tick queued as completed, as the
 * runner of the same tick would. So the sequence shows the cadences, flags, gates and due-checks of the scheduler
 * alone, never what a job did. Nothing here names Pipeline::stages(): the test goes through the activation, the
 * tick hook, the settings and the storage only.
 *
 * Clock: tests/Support/FrozenClock.php. The plugin reads the time through unqualified time()/gmdate() calls (and
 * current_time() in Limits), which the frozen clock covers in every plugin namespace; the test also registers the
 * `lga_test_time` filter. A clock seam that WP2 adds must read one of those for this test to stay meaningful.
 *
 * Modules: every request runs with the plugin limited to the 13 WP2 modules (Tests\Support\Wp2Modules, through the
 * `lga_modules` filter), so the sequence records the engine's tasks only and a later package's line in
 * Core\Modules::LIST does not change it (DESIGN.md §13.1); a change to a WP2 module's tasks still does.
 *
 * To change the expected sequence on purpose: LGA_GOLDEN_UPDATE=1 bash bin/test.sh tests/Unit/Automation/SchedulerGoldenTest.php,
 * review `git diff tests/fixtures/golden/scheduler.json` and document the difference in the package notes.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Automation;

use LinkGrowth\Autopilot\Activator;
use LinkGrowth\Autopilot\Support\Locks;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Tests\Support\Container;
use LinkGrowth\Autopilot\Tests\Support\FrozenClock;
use LinkGrowth\Autopilot\Tests\Support\GoldenJson;
use LinkGrowth\Autopilot\Tests\Support\TestCase;
use LinkGrowth\Autopilot\Tests\Support\Wp2Modules;
use PHPUnit\Framework\Attributes\DataProvider;

final class SchedulerGoldenTest extends TestCase {

    private const FILE = 'scheduler.json';

    /** 2026-01-05 16:00:00 UTC: a Monday, 08:00 in the unit site's zone (America/Los_Angeles, no DST change in the 8 days). */
    private const T0 = 1767628800;

    /** Ticks in the first 15 minutes (seconds after T0): inside the quiet window, its last second and its end. */
    private const FIRST_TICKS = [ 60, 300, 599, 600, 900 ];

    private const EVERY = 1800;

    private const UNTIL = 8 * DAY_IN_SECONDS;

    /** Lock that serialises the queue runner (JobRunner::LOCK), held by "another process" for the whole run. */
    private const RUNNER_LOCK = 'runner';

    private const H = HOUR_IN_SECONDS;
    private const D = DAY_IN_SECONDS;

    /** The site zone and address the fixture was recorded with (the bootstrap's defaults). */
    private const TIMEZONE = 'America/Los_Angeles';
    private const HOME     = 'https://northwind.test';

    /**
     * The environment IndexNow sees (it submits only on "production"), set through its own filter: the bootstrap's
     * wp_get_environment_type() says "local", while the Seo test stubs (tests/Support/StubsSeo.php), loaded with the
     * whole suite, answer "production" for the Seo classes.
     */
    private const INDEXNOW_ENVIRONMENT = 'production';

    /** @var array<string,mixed> globals this test pins, as they were */
    private array $previous = [];

    protected function setUp(): void {
        parent::setUp();
        // Other tests change these (and restore them); pin them so the sequence never depends on the test order.
        foreach ( [ 'lga_test_timezone' => self::TIMEZONE, 'lga_test_home' => self::HOME ] as $global => $value ) {
            $this->previous[ $global ] = $GLOBALS[ $global ] ?? null;
            $GLOBALS[ $global ]        = $value;
        }
    }

    protected function tearDown(): void {
        FrozenClock::set( null );
        foreach ( $this->previous as $global => $value ) {
            if ( null === $value ) {
                unset( $GLOBALS[ $global ] );
            } else {
                $GLOBALS[ $global ] = $value;
            }
        }
        parent::tearDown();
    }

    /** @return array<string,array{0:string}> */
    public static function scenarios(): array {
        $out = [];
        foreach ( array_keys( self::definitions() ) as $name ) {
            $out[ $name ] = [ $name ];
        }
        return $out;
    }

    #[DataProvider( 'scenarios' )]
    public function testTheSchedulerEnqueuesTheRecordedSequence( string $name ): void {
        $definition = self::definitions()[ $name ];
        $actual     = [
            'about'    => $definition['about'],
            'events'   => self::eventLabels( $definition ),
            'enqueued' => $this->replay( $definition ),
        ];

        if ( GoldenJson::updating() ) {
            $golden                      = GoldenJson::read( self::FILE );
            $golden                      = array_merge( [ '_about' => self::ABOUT ], self::header(), [ 'scenarios' => (array) ( $golden['scenarios'] ?? [] ) ] );
            $golden['scenarios'][ $name ] = $actual;
            $order                       = array_flip( array_keys( self::definitions() ) );
            uksort( $golden['scenarios'], static fn( string $a, string $b ): int => ( $order[ $a ] ?? 99 ) <=> ( $order[ $b ] ?? 99 ) );
            GoldenJson::write( self::FILE, $golden );
            $this->markTestSkipped( 'LGA_GOLDEN_UPDATE=1: tests/fixtures/golden/' . self::FILE . ' rewritten for "' . $name . '"; review the diff.' );
        }

        $golden = GoldenJson::read( self::FILE );
        $this->assertNotSame( [], $golden, 'tests/fixtures/golden/' . self::FILE . ' exists' );
        foreach ( self::header() as $key => $value ) {
            $this->assertSame( $value, $golden[ $key ] ?? null, 'the fixture was recorded with the same clock and ticks (' . $key . ')' );
        }
        $expected = $golden['scenarios'][ $name ] ?? null;
        $this->assertIsArray( $expected, 'the fixture has scenario "' . $name . '"' );
        $this->assertSame( $expected, $actual, $name . ': ' . GoldenJson::firstDifference( $expected, $actual ) );
    }

    // ------------------------------------------------------------------
    // Scenarios
    // ------------------------------------------------------------------

    /**
     * Scenario definitions. `events` maps seconds after T0 to [label, change]; a change runs right before the tick at
     * that second (or, for an offset that is not a tick, before the next tick). `pause` lists [from, to) windows in
     * which the `lga_pause_background` filter pauses background work.
     *
     * @return array<string,array{about:string,events:array<int,array{0:string,1:callable():void}>,pause?:array<int,array{0:int,1:int}>}>
     */
    private static function definitions(): array {
        return [
            'defaults'      => [
                'about'  => 'Fresh install with the default settings and no data: the quiet window after the activation, then every cadence (hourly, 6-hourly, daily, weekly) and the digest-driven monitor check.',
                'events' => [],
            ],
            'work_waiting'  => [
                'about'  => 'Defaults with the daily digest off and rows that the continuous stages wait for: a prospect to evaluate, then one without opportunities, an approved submission, an approved outreach message (sent only inside the weekday 9-17 send window of the site zone) and a backlink due for its check.',
                'events' => [
                    120          => [ 'the owner switches the daily digest off', static fn() => self::settings( [ 'monitoring' => [ 'digest_email' => false ] ] ) ],
                    2 * self::H  => [ 'a prospect is discovered', static fn() => self::insertProspect( 'alpha-directory.test', 'discovered' ) ],
                    self::D + 2 * self::H => [ 'the prospect is qualified (no opportunity yet)', static fn() => self::setRow( 'prospects', 'domain', 'alpha-directory.test', [ 'status' => 'qualified', 'score' => 72 ] ) ],
                    2 * self::D + 2 * self::H => [ 'an opportunity is recorded for it', static fn() => self::insertOpportunity( 'alpha-directory.test' ) ],
                    2 * self::D + 4 * self::H => [ 'a submission is approved', static fn() => self::insertSubmission( 'alpha-directory.test', 'approved' ) ],
                    3 * self::D + 4 * self::H => [ 'the submission is sent', static fn() => self::setRow( 'submissions', 'status', 'approved', [ 'status' => 'submitted' ] ) ],
                    3 * self::D + 20 * self::H => [ 'an outreach message is approved (Friday 04:00 site time, before the send window opens)', static fn() => self::insertOutreachMessage( 'alpha-directory.test' ) ],
                    7 * self::D + 6 * self::H => [ 'the message is sent (Monday 14:00 site time)', static fn() => self::setRow( 'outreach_messages', 'status', 'approved', [ 'status' => 'sent', 'sent_at' => self::now() ] ) ],
                    5 * self::D + 6 * self::H => [ 'a backlink is due for its check', static fn() => self::insertBacklink( 'alpha-directory.test' ) ],
                    6 * self::D + 6 * self::H => [ 'the backlink is checked (next check in 7 days)', static fn() => self::setRow( 'backlinks', 'source_domain', 'alpha-directory.test', [ 'status' => 'active', 'next_check_at' => self::now( 7 * self::D ) ] ) ],
                ],
            ],
            'autopilot_on'  => [
                'about'  => 'Defaults with Autopilot switched on by the owner two minutes after the activation (internal links become daily).',
                'events' => [
                    120 => [ 'the owner switches Autopilot on', static fn() => self::settings( [ 'automation' => [ 'autopilot' => true ] ] ) ],
                ],
            ],
            'modules_off'   => [
                'about'  => 'Defaults with the discovery and monitoring stages switched off and backlink imports every 3 days; discovery comes back on day 4 and imports go off on day 5.',
                'events' => [
                    120               => [ 'the owner switches discovery and monitoring off and sets imports to every 3 days', static fn() => self::settings( [ 'automation' => [ 'discovery' => false, 'monitoring' => false ], 'monitoring' => [ 'import_interval_days' => 3 ] ] ) ],
                    4 * self::D       => [ 'discovery is switched back on', static fn() => self::settings( [ 'automation' => [ 'discovery' => true ] ] ) ],
                    5 * self::D       => [ 'backlink imports are switched off', static fn() => self::settings( [ 'automation' => [ 'imports' => false ] ] ) ],
                ],
            ],
            'paused'        => [
                'about'  => 'Defaults with background work paused for 28 hours (the lga_pause_background filter, as while a backup runs), from +18 h to +46 h after the install: nothing is enqueued meanwhile, and what came due runs once afterwards.',
                'events' => [],
                'pause'  => [ [ 18 * self::H, 46 * self::H ] ],
            ],
            'automation_off' => [
                'about'  => 'Defaults with the automation master switch off from day 2 to day 3.',
                'events' => [
                    2 * self::D => [ 'the owner switches automation off', static fn() => self::settings( [ 'automation' => [ 'enabled' => false ] ] ) ],
                    3 * self::D => [ 'and on again', static fn() => self::settings( [ 'automation' => [ 'enabled' => true ] ] ) ],
                ],
            ],
        ];
    }

    private const ABOUT = 'Golden scheduler sequence (tests/Unit/Automation/SchedulerGoldenTest.php): per scenario, every job the ticks enqueued, as [tick time (UTC), job type, priority] (+ payload and delay when not empty). Recorded on the WP1 fork state (e160656); rewrite only on purpose with LGA_GOLDEN_UPDATE=1.';

    /** What the fixture must have been recorded with (the clock, the zone and the ticks). @return array<string,mixed> */
    private static function header(): array {
        return [
            't0'            => gmdate( 'Y-m-d\TH:i:s\Z', self::T0 ),
            'site_timezone' => wp_timezone_string(),
            'site_home'     => home_url(),
            'indexnow_environment' => self::INDEXNOW_ENVIRONMENT,
            'ticks'         => [ 'first' => self::FIRST_TICKS, 'then_every' => self::EVERY, 'until' => self::UNTIL, 'count' => count( self::ticks() ) ],
        ];
    }

    /** @return int[] seconds after T0 */
    private static function ticks(): array {
        $ticks = self::FIRST_TICKS;
        for ( $t = self::EVERY; $t <= self::UNTIL; $t += self::EVERY ) {
            $ticks[] = $t;
        }
        $ticks = array_values( array_unique( $ticks ) );
        sort( $ticks );
        return $ticks;
    }

    /** @return array<int,array{0:string,1:string}> [offset label, event label] */
    private static function eventLabels( array $definition ): array {
        $out    = [];
        $events = $definition['events'];
        ksort( $events );
        foreach ( $events as $offset => [ $label ] ) {
            $out[] = [ self::offsetLabel( (int) $offset ), $label ];
        }
        foreach ( $definition['pause'] ?? [] as [ $from, $to ] ) {
            $out[] = [ self::offsetLabel( $from ) . ' – ' . self::offsetLabel( $to ), 'background work paused (lga_pause_background)' ];
        }
        return $out;
    }

    private static function offsetLabel( int $seconds ): string {
        return '+' . intdiv( $seconds, self::D ) . 'd ' . gmdate( 'H:i:s', $seconds % self::D );
    }

    // ------------------------------------------------------------------
    // The run
    // ------------------------------------------------------------------

    /** @return array<int,array<int,mixed>> */
    private function replay( array $definition ): array {
        global $wpdb;
        $jobs = $wpdb->prefix . 'lga_jobs';

        FrozenClock::set( self::T0 );
        $this->newRequest( $definition );
        Activator::activate();
        $this->assertTrue( Container::get( Locks::class )->acquire( self::RUNNER_LOCK, 30 * self::D ), 'the test holds the runner lock' );

        $events  = $definition['events'];
        ksort( $events );
        $lastId  = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$jobs}" ); // phpcs:ignore
        $out     = [];
        foreach ( self::ticks() as $offset ) {
            $now = self::T0 + $offset;
            FrozenClock::set( $now );
            foreach ( $events as $at => [ , $change ] ) {
                if ( $at > $offset ) {
                    break;
                }
                $change();
                unset( $events[ $at ] );
            }

            $this->newRequest( $definition );
            do_action( 'lga_tick' );

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, type, priority, payload, scheduled_at FROM {$jobs} WHERE id > %d ORDER BY id ASC", $lastId ), ARRAY_A );
            foreach ( $rows as $row ) {
                $lastId  = max( $lastId, (int) $row['id'] );
                $payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
                $delay   = (int) strtotime( (string) $row['scheduled_at'] . ' UTC' ) - $now;
                $entry   = [ gmdate( 'Y-m-d\TH:i:s\Z', $now ), (string) $row['type'], (int) $row['priority'] ];
                if ( is_array( $payload ) && [] !== $payload ) {
                    $entry[] = $payload;
                }
                if ( 0 !== $delay ) {
                    $entry[] = [ 'delay' => $delay ];
                }
                $out[] = $entry;
            }
            // The queue runner of the same tick would have run them: they leave the queue completed.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( $wpdb->prepare( "UPDATE {$jobs} SET status = 'completed', attempts = attempts + 1, started_at = %s, finished_at = %s WHERE status = 'queued' AND scheduled_at <= %s", self::now(), self::now(), self::now() ) );
        }
        $this->assertSame( [], $events, 'every event ran' );
        return $out;
    }

    /** A new request: no hooks from the previous one, a fresh container, the plugin (the WP2 modules) booted, the scenario's filters. */
    private function newRequest( array $definition ): void {
        $GLOBALS['lga_test_actions'] = [];
        $GLOBALS['lga_test_filters'] = [];
        $GLOBALS['lga_test_did']     = [];
        add_filter( Wp2Modules::FILTER, [ Wp2Modules::class, 'filter' ] );
        add_filter( 'lga_test_time', static fn(): int => FrozenClock::now() );
        add_filter( 'lga_indexnow_environment', static fn(): string => self::INDEXNOW_ENVIRONMENT );
        $pauses = $definition['pause'] ?? [];
        if ( [] !== $pauses ) {
            add_filter(
                'lga_pause_background',
                static function ( mixed $pause = false ) use ( $pauses ): mixed {
                    $offset = FrozenClock::now() - self::T0;
                    foreach ( $pauses as [ $from, $to ] ) {
                        if ( $offset >= $from && $offset < $to ) {
                            return true;
                        }
                    }
                    return $pause;
                }
            );
        }
        Container::reset();
        Container::plugin()->boot();
    }

    // ------------------------------------------------------------------
    // Changes a scenario makes between ticks (settings service, plugin tables)
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $partial */
    private static function settings( array $partial ): void {
        Container::get( Settings::class )->update( $partial );
    }

    private static function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . 'lga_' . $name;
    }

    /** The frozen time (+ $plus seconds) as a database datetime. This file's own time() and gmdate() are the real ones. */
    private static function now( int $plus = 0 ): string {
        return gmdate( 'Y-m-d H:i:s', FrozenClock::now() + $plus );
    }

    /** @param array<string,mixed> $data */
    private static function insert( string $table, array $data ): int {
        global $wpdb;
        if ( ! $wpdb->insert( self::table( $table ), $data ) ) {
            throw new \RuntimeException( 'The scenario could not add a row to ' . $table . ': ' . $wpdb->last_error );
        }
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $data */
    private static function setRow( string $table, string $column, string $value, array $data ): void {
        global $wpdb;
        $data['updated_at'] = self::now();
        if ( 1 !== $wpdb->update( self::table( $table ), $data, [ $column => $value ] ) ) {
            throw new \RuntimeException( 'The scenario could not change the row of ' . $table . ' where ' . $column . ' = ' . $value . ': ' . $wpdb->last_error );
        }
    }

    private static function prospectId( string $domain ): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table( 'prospects' ) . ' WHERE domain = %s', $domain ) );
    }

    private static function insertProspect( string $domain, string $status ): void {
        self::insert( 'prospects', [ 'domain' => $domain, 'name' => 'Alpha Directory', 'homepage_url' => 'https://' . $domain . '/', 'status' => $status, 'first_seen' => self::now(), 'updated_at' => self::now() ] );
    }

    private static function insertOpportunity( string $domain ): void {
        self::insert( 'opportunities', [ 'prospect_id' => self::prospectId( $domain ), 'type' => 'directory_listing', 'status' => 'open', 'created_at' => self::now(), 'updated_at' => self::now() ] );
    }

    private static function insertSubmission( string $domain, string $status ): void {
        self::insert( 'submissions', [ 'prospect_id' => self::prospectId( $domain ), 'status' => $status, 'created_at' => self::now(), 'updated_at' => self::now() ] );
    }

    private static function insertOutreachMessage( string $domain ): void {
        $prospect = self::prospectId( $domain );
        $contact  = self::insert( 'contacts', [ 'prospect_id' => $prospect, 'email' => 'editor@' . $domain, 'created_at' => self::now(), 'updated_at' => self::now() ] );
        self::insert( 'outreach_messages', [ 'campaign_id' => 0, 'prospect_id' => $prospect, 'contact_id' => $contact, 'status' => 'approved', 'subject' => 'A resource for your readers', 'body' => 'Hello', 'created_at' => self::now(), 'updated_at' => self::now() ] );
    }

    private static function insertBacklink( string $domain ): void {
        self::insert(
            'backlinks',
            [
                'prospect_id'   => self::prospectId( $domain ),
                'source_domain' => $domain,
                'source_url'    => 'https://' . $domain . '/listing/',
                'source_hash'   => sha1( 'https://' . $domain . '/listing/' ),
                'target_url'    => home_url( '/' ),
                'target_hash'   => sha1( home_url( '/' ) ),
                'status'        => 'pending',
                'first_seen'    => self::now(),
                'next_check_at' => self::now( -60 ),
                'created_at'    => self::now(),
                'updated_at'    => self::now(),
            ]
        );
    }
}
