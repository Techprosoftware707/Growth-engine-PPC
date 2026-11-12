<?php
/**
 * Regression tests for the data-support-core slice (schema, migrator, backups, locks, HTTP client, robots.txt, URLs).
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Support;

use PPC\LinkGrowth\Database\Backup;
use PPC\LinkGrowth\Database\Migrator;
use PPC\LinkGrowth\Database\Schema;
use PPC\LinkGrowth\Repositories\Suppression;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Http\Response;
use PPC\LinkGrowth\Support\Http\RobotsTxt;
use PPC\LinkGrowth\Support\Locks;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Url;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\FakeWpdb;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\TestCase;

/**
 * wpdb decorator over the SQLite FakeWpdb: records statements, answers the MySQL-only SHOW statements the
 * schema and backup code use, resets last_error per statement like the real wpdb, and can run a callback
 * right before a statement (to interleave a second "process").
 */
final class DscWpdb extends \wpdb {

    /** @var string[] */
    public array $log = [];

    /** @var string[] plugin tables SHOW TABLES pretends are missing */
    public array $hidden = [];

    public int $placeholderCalls = 0;

    /** @var (\Closure(string):void)|null */
    public ?\Closure $beforeQuery = null;

    public function __construct( public FakeWpdb $inner ) {}

    public function __get( string $name ): mixed {
        return $this->inner->$name;
    }

    public function __set( string $name, mixed $value ): void {
        $this->inner->$name = $value;
    }

    public function __isset( string $name ): bool {
        return isset( $this->inner->$name );
    }

    /** @param array<int,mixed> $args */
    public function __call( string $method, array $args ): mixed {
        return $this->inner->$method( ...$args );
    }

    public function prepare( string $query, ...$args ): string {
        return $this->inner->prepare( $query, ...$args );
    }

    public function esc_like( string $text ): string {
        return $this->inner->esc_like( $text );
    }

    public function remove_placeholder_escape( string $s ): string {
        $this->placeholderCalls++;
        return $s;
    }

    public function query( string $sql ): int|false {
        $this->log[]             = $sql;
        $this->inner->last_error = '';
        if ( null !== $this->beforeQuery ) {
            ( $this->beforeQuery )( $sql );
        }
        return $this->inner->query( $sql );
    }

    public function get_var( string $sql ): mixed {
        $this->log[]             = $sql;
        $this->inner->last_error = '';
        if ( preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $sql, $m ) ) {
            $name = str_replace( [ '\\_', '\\%', '\\\\' ], [ '_', '%', '\\' ], $m[1] );
            if ( in_array( $name, $this->hidden, true ) ) {
                return null;
            }
            return $this->inner->get_var( $this->inner->prepare( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = %s", $name ) );
        }
        return $this->inner->get_var( $sql );
    }

    public function get_row( string $sql, string $output = 'OBJECT' ): array|object|null {
        $this->log[] = $sql;
        if ( preg_match( '/^SHOW CREATE TABLE `(.+)`$/', $sql, $m ) ) {
            return [ $m[1], 'CREATE TABLE `' . $m[1] . '` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`))' ];
        }
        return $this->inner->get_row( $sql, $output );
    }

    public function get_results( string $sql, string $output = 'OBJECT' ): array {
        $this->log[] = $sql;
        return $this->inner->get_results( $sql, $output );
    }

    public function get_col( string $sql ): array {
        $this->log[] = $sql;
        return $this->inner->get_col( $sql );
    }

    /** @return string[] */
    public function showTablesQueries(): array {
        return array_values( array_filter( $this->log, static fn( string $q ): bool => str_starts_with( $q, 'SHOW TABLES' ) ) );
    }
}

final class DataSupportCoreFixesTest extends TestCase {

    private DscWpdb $db;

    /** @var float[] seconds the client was asked to wait */
    private array $slept = [];

    protected function setUp(): void {
        parent::setUp();
        $inner = $GLOBALS['wpdb'];
        $inner->query( "CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT NOT NULL UNIQUE, option_value TEXT NOT NULL, autoload TEXT NOT NULL DEFAULT 'yes')" );
        $this->db        = new DscWpdb( $inner );
        $GLOBALS['wpdb'] = $this->db;
        $this->slept     = [];
    }

    private function client( ?RobotsTxt $robots = null ): Client {
        return new Client(
            Container::get( Settings::class ),
            Container::get( Logger::class ),
            $robots ?? new RobotsTxt( Container::get( Logger::class ) ),
            function ( float $seconds ): void {
                $this->slept[] = $seconds;
            }
        );
    }

    /** @return string[] "METHOD url" of every outbound request */
    private static function requests(): array {
        return array_map( static fn( array $r ): string => $r['method'] . ' ' . $r['url'], HttpFixtures::$requests );
    }

    private static function ttl( string $transient ): int {
        return (int) ( $GLOBALS['ppclg_test_transients'][ $transient ]['expires'] ?? 0 ) - time();
    }

    // ------------------------------------------------------------------
    // data-support-core-1: suppression.type fits every stored type
    // ------------------------------------------------------------------

    public function testSuppressionTypeColumnFitsEveryTypeAndSchemaVersionWasBumped(): void {
        $create = '';
        foreach ( ( new Schema() )->statements() as $sql ) {
            if ( str_contains( $sql, 'ppclg_suppression' ) ) {
                $create = $sql;
            }
        }
        $this->assertSame( 1, preg_match( '/^\s*type varchar\((\d+)\)/m', $create, $m ), 'suppression.type is a varchar' );
        $length = (int) $m[1];
        $types  = array_merge( Suppression::TYPES, (array) ( new \ReflectionClass( \PPC\LinkGrowth\Admin\Rest\LogsController::class ) )->getConstant( 'SUPPRESSION_TYPES' ) );
        $this->assertContains( 'email_domain', $types );
        foreach ( $types as $type ) {
            $this->assertLessThanOrEqual( $length, strlen( $type ), "suppression type '{$type}' fits varchar({$length}) (wpdb::insert rejects longer values)" );
        }
        // dbDelta only widens the column on existing installs when the schema version changes.
        $main = (string) file_get_contents( PPCLG_DIR . 'ppc-link-growth-engine.php' );
        $this->assertSame( 1, preg_match( "/define\(\s*'PPCLG_DB_VERSION',\s*'([0-9.]+)'\s*\)/", $main, $v ) );
        $this->assertTrue( version_compare( $v[1], '1.0.0', '>' ), 'PPCLG_DB_VERSION was bumped past 1.0.0 (varchar(10))' );

        $sup = Container::get( Suppression::class );
        $this->assertTrue( $sup->add( 'email_domain', 'Blocked.example', 'owner request' ) );
        $this->assertTrue( $sup->isSuppressed( 'email', 'anyone@blocked.example' ) );
        $this->assertFalse( $sup->isSuppressed( 'email', 'anyone@other.example' ) );
    }

    // ------------------------------------------------------------------
    // data-support-core-2 / -3: throttle waits the full delay, robots Crawl-delay applies
    // ------------------------------------------------------------------

    public function testThrottleWaitsTheWholeDelayAboveFiveSeconds(): void {
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 10 );
        HttpFixtures::add( 'https://polite.test/a', '<html><body>a</body></html>' );
        HttpFixtures::add( 'https://polite.test/b', '<html><body>b</body></html>' );
        $client = $this->client();
        $this->assertTrue( $client->crawl( 'https://polite.test/a' )->ok() );
        $this->assertSame( [], $this->slept, 'first request to a host does not wait' );
        $this->assertTrue( $client->crawl( 'https://polite.test/b' )->ok() );
        $this->assertCount( 1, $this->slept, 'a 10 s delay is waited, not skipped' );
        $this->assertGreaterThan( 9.5, $this->slept[0] );
        $this->assertLessThanOrEqual( 10.0, $this->slept[0] );
        $this->assertGreaterThanOrEqual( 59, self::ttl( 'ppclg_hit_' . md5( 'polite.test' ) ) );

        // The ceiling is the settings maximum (60 s); the cross-request marker outlives the delay.
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 60 );
        $client->probe( 'https://polite.test/a' );
        $this->assertGreaterThan( 59.0, end( $this->slept ) );
        $this->assertGreaterThanOrEqual( 64, self::ttl( 'ppclg_hit_' . md5( 'polite.test' ) ) );
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 500 );
        $client->crawl( 'https://polite.test/b' );
        $this->assertLessThanOrEqual( 60.0, end( $this->slept ) );
    }

    public function testRobotsCrawlDelayIsHonoured(): void {
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 0 );
        HttpFixtures::add( 'https://slowsite.test/robots.txt', "User-agent: *\nCrawl-delay: 20\nAllow: /\n", 200, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://slowsite.test/one', '<html><body>1</body></html>' );
        HttpFixtures::add( 'https://slowsite.test/two', '<html><body>2</body></html>' );
        $client = $this->client();
        $client->crawl( 'https://slowsite.test/one' );
        $client->crawl( 'https://slowsite.test/two' );
        $client->probe( 'https://slowsite.test/one' );
        $this->assertCount( 2, $this->slept );
        foreach ( $this->slept as $s ) {
            $this->assertGreaterThan( 19.5, $s, 'robots.txt Crawl-delay: 20 is waited even with the setting at 0' );
        }
        // The setting wins when it is longer.
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 25 );
        $client->crawl( 'https://slowsite.test/two' );
        $this->assertGreaterThan( 24.5, end( $this->slept ) );
    }

    // ------------------------------------------------------------------
    // data-support-core-4: 429 / 408 robots.txt = temporarily disallowed, Retry-After honoured
    // ------------------------------------------------------------------

    public function testRobotsRateLimitedIsDisallowedWithShortRetryAfterAwareTtl(): void {
        $robots = new RobotsTxt( Container::get( Logger::class ) );
        $answer = static fn( int $status, array $headers = [], string $body = "User-agent: *\nDisallow: /\n" ) => static fn( string $u ): Response => new Response( $status, $headers, $body, $u, $u, 0.01 );

        $this->assertFalse( $robots->isAllowed( 'https://rl.test/private', $answer( 429, [ 'retry-after' => '7200' ] ) ), '429 is not allow-all' );
        $this->assertEqualsWithDelta( 7200, self::ttl( 'ppclg_robots_' . md5( 'https://rl.test' ) ), 2 );

        $this->assertFalse( $robots->isAllowed( 'https://rl2.test/x', $answer( 429 ) ) );
        $this->assertEqualsWithDelta( HOUR_IN_SECONDS, self::ttl( 'ppclg_robots_' . md5( 'https://rl2.test' ) ), 2 );

        $date = gmdate( 'D, d M Y H:i:s', time() + 5400 ) . ' GMT';
        $this->assertFalse( $robots->isAllowed( 'https://rl3.test/x', $answer( 503, [ 'retry-after' => $date ] ) ) );
        $this->assertEqualsWithDelta( 5400, self::ttl( 'ppclg_robots_' . md5( 'https://rl3.test' ) ), 3 );

        $this->assertFalse( $robots->isAllowed( 'https://rl4.test/x', $answer( 429, [ 'retry-after' => '9999999' ] ) ) );
        $this->assertEqualsWithDelta( DAY_IN_SECONDS, self::ttl( 'ppclg_robots_' . md5( 'https://rl4.test' ) ), 2, 'capped at a day' );

        $this->assertFalse( $robots->isAllowed( 'https://rl5.test/x', $answer( 408 ) ) );
        $this->assertTrue( $robots->isAllowed( 'https://ok404.test/x', $answer( 404, [], '' ) ), '404 still means no robots.txt' );
        $this->assertEqualsWithDelta( DAY_IN_SECONDS, self::ttl( 'ppclg_robots_' . md5( 'https://ok404.test' ) ), 2 );

        $dead = static fn( string $u ): Response => Response::error( $u, 'cURL error 6: Could not resolve host: dead.test', 'http_request_failed' );
        $this->assertFalse( $robots->isAllowed( 'https://dead.test/x', $dead ) );
        $this->assertStringContainsString( 'Could not resolve', (string) $robots->fetchError( 'https://dead.test/x', $dead ) );
        $this->assertNull( $robots->fetchError( 'https://rl.test/private', $answer( 200 ) ) );

        // Through the crawler: a rate-limited robots.txt blocks the page for now.
        HttpFixtures::add( 'https://cf.test/robots.txt', "User-agent: *\nDisallow: /\n", 429, [ 'content-type' => 'text/plain', 'retry-after' => '60' ] );
        HttpFixtures::add( 'https://cf.test/private', '<html><body>secret</body></html>' );
        $resp = $this->client()->crawl( 'https://cf.test/private' );
        $this->assertSame( 'robots_disallow', $resp->errorCode );
        $this->assertNotContains( 'GET https://cf.test/private', self::requests() );
    }

    // ------------------------------------------------------------------
    // data-support-core-10: probe() and redirect hops respect robots.txt
    // ------------------------------------------------------------------

    public function testProbeRespectsRobotsButStillDetectsDeadHosts(): void {
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 0 );
        HttpFixtures::add( 'https://nobot.test/robots.txt', "User-agent: PPCLinkGrowthBot\nDisallow: /\n", 200, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://nobot.test/page', '<html>x</html>', 405 );
        $client = $this->client();

        $r = $client->probe( 'https://nobot.test/page' );
        $this->assertSame( 'robots_disallow', $r->errorCode );
        $this->assertSame( [ 'GET https://nobot.test/robots.txt' ], self::requests(), 'the disallowed page is never requested' );

        // A host that does not resolve: no web server answers, so the probe still records the DNS failure.
        HttpFixtures::$requests = [];
        $dead = $client->probe( 'https://nxdomain-dsc.test/old-guide/' );
        $this->assertSame( 'http_request_failed', $dead->errorCode );
        $this->assertStringContainsString( 'Could not resolve', (string) $dead->error );
        $this->assertContains( 'HEAD https://nxdomain-dsc.test/old-guide/', self::requests() );

        // robots.txt timing out is not proof of anything: nothing else is requested and the link stays unknown.
        HttpFixtures::$requests = [];
        HttpFixtures::$handler  = static fn( string $url ) => str_contains( $url, 'slowrobots.test' ) ? new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) : null;
        $slow = $client->probe( 'https://slowrobots.test/page', [ 'timeout' => 3 ] );
        $this->assertSame( 'robots_unavailable', $slow->errorCode );
        $this->assertSame( [ 'GET https://slowrobots.test/robots.txt' ], self::requests() );
        $this->assertLessThanOrEqual( 3, (int) HttpFixtures::$requests[0]['args']['timeout'], 'robots.txt fetch fits the probe timeout' );

        // 404 targets are still reported as such.
        HttpFixtures::$requests = [];
        HttpFixtures::addPattern( '#^https://deadpage\.test/#', '<html>404</html>', 404 );
        $this->assertSame( 404, $client->probe( 'https://deadpage.test/gone' )->status );
    }

    public function testRedirectTargetsAreCheckedAgainstRobotsBeforeTheyAreFetched(): void {
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 0 );
        HttpFixtures::add( 'https://hop.test/start', '', 301, [ 'location' => 'https://walled.test/private/page' ] );
        HttpFixtures::add( 'https://walled.test/robots.txt', "User-agent: *\nDisallow: /private/\n", 200, [ 'content-type' => 'text/plain' ] );
        HttpFixtures::add( 'https://walled.test/private/page', '<html><body>private</body></html>' );
        $client = $this->client();

        $r = $client->crawl( 'https://hop.test/start' );
        $this->assertSame( 'robots_disallow', $r->errorCode );
        $this->assertSame( 'https://hop.test/start', $r->url );
        $this->assertNotContains( 'GET https://walled.test/private/page', self::requests(), 'the disallowed redirect target is never requested' );
        foreach ( HttpFixtures::$requests as $req ) {
            if ( 'https://hop.test/start' === $req['url'] ) {
                $this->assertSame( 0, $req['args']['redirection'], 'redirects are followed by the client, not by WP HTTP' );
            }
        }

        HttpFixtures::$requests = [];
        $p = $client->probe( 'https://hop.test/start' );
        $this->assertSame( 'robots_disallow', $p->errorCode );
        $this->assertNotContains( 'HEAD https://walled.test/private/page', self::requests() );
        $this->assertNotContains( 'GET https://walled.test/private/page', self::requests() );

        // An allowed redirect is followed (relative Location, trailing slash kept).
        HttpFixtures::add( 'https://hop.test/old', '', 301, [ 'location' => '/new/' ] );
        HttpFixtures::add( 'https://hop.test/new/', '<html><body>moved here</body></html>' );
        $moved = $client->crawl( 'https://hop.test/old' );
        $this->assertSame( 200, $moved->status );
        $this->assertSame( 'https://hop.test/old', $moved->url );
        $this->assertSame( 'https://hop.test/new/', $moved->finalUrl );
        $this->assertTrue( $moved->redirected() );
        $this->assertStringContainsString( 'moved here', $moved->body );

        // A redirect loop stops after the redirect limit.
        HttpFixtures::add( 'https://loop.test/a', '', 302, [ 'location' => 'https://loop.test/b' ] );
        HttpFixtures::add( 'https://loop.test/b', '', 302, [ 'location' => 'https://loop.test/a' ] );
        HttpFixtures::$requests = [];
        $loop = $client->crawl( 'https://loop.test/a' );
        $this->assertSame( 302, $loop->status );
        $this->assertFalse( $loop->ok() );
        $pages = array_filter( self::requests(), static fn( string $r ): bool => ! str_ends_with( $r, '/robots.txt' ) );
        $this->assertCount( 5, $pages, 'first request plus four redirects' );
    }

    public function testRedirectCookiesStayWithTheirHost(): void {
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 0 );
        foreach ( [ 'jar.test', 'elsewhere.test' ] as $h ) {
            HttpFixtures::add( 'https://' . $h . '/robots.txt', "User-agent: *\nAllow: /\n", 200, [ 'content-type' => 'text/plain' ] );
        }
        HttpFixtures::$handler = static function ( string $url ) {
            $redirect = [ 'https://jar.test/a' => 'https://jar.test/b', 'https://jar.test/c' => 'https://elsewhere.test/land' ][ $url ] ?? null;
            if ( null !== $redirect ) {
                return [ 'response' => [ 'code' => 302, 'message' => 'Found' ], 'headers' => [ 'location' => $redirect ], 'body' => '', 'cookies' => [ new \WP_Http_Cookie( [ 'name' => 'sid', 'value' => 'secret' ] ) ] ];
            }
            if ( in_array( $url, [ 'https://jar.test/b', 'https://elsewhere.test/land' ], true ) ) {
                return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'headers' => [ 'content-type' => 'text/html' ], 'body' => '<html>ok</html>', 'cookies' => [] ];
            }
            return null;
        };
        $client = $this->client();
        $same   = $client->crawl( 'https://jar.test/a' );
        $this->assertSame( 200, $same->status );
        $this->assertSame( [ 'sid' => 'secret' ], $same->cookies, 'cookies set during a same-host redirect are kept' );
        $hopB = array_values( array_filter( HttpFixtures::$requests, static fn( array $r ): bool => 'https://jar.test/b' === $r['url'] ) );
        $this->assertSame( [ 'sid' => 'secret' ], $hopB[0]['args']['cookies'] ?? null );

        $other = $client->crawl( 'https://jar.test/c' );
        $this->assertSame( 200, $other->status );
        $land = array_values( array_filter( HttpFixtures::$requests, static fn( array $r ): bool => 'https://elsewhere.test/land' === $r['url'] ) );
        $this->assertArrayNotHasKey( 'cookies', $land[0]['args'], 'a cookie from jar.test is never sent to another host' );
        $this->assertSame( [], $other->cookies );
    }

    // ------------------------------------------------------------------
    // data-support-core-5: locks are atomic compare-and-set in the options table
    // ------------------------------------------------------------------

    /** @return string[] name => token held by $locks (via reflection) */
    private static function heldToken( Locks $locks, string $name ): ?string {
        $prop = new \ReflectionProperty( Locks::class, 'held' );
        return $prop->getValue( $locks )[ $name ]['token'] ?? null;
    }

    private function lockRowToken( string $name ): ?string {
        $raw = $this->db->inner->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'ppclg_lock_" . $name . "'" );
        if ( null === $raw ) {
            return null;
        }
        $value = unserialize( (string) $raw, [ 'allowed_classes' => false ] );
        return is_array( $value ) ? (string) $value['token'] : null;
    }

    public function testFirstTimeAcquireRaceHasExactlyOneWinner(): void {
        $a     = new Locks();
        $b     = new Locks();
        $fired = false;
        $bGot  = null;
        // B runs completely between A's start and A's INSERT.
        $this->db->beforeQuery = function ( string $sql ) use ( &$fired, &$bGot, $b ): void {
            if ( $fired || ! str_starts_with( $sql, 'INSERT INTO wp_options' ) ) {
                return;
            }
            $fired = true;
            $bGot  = $b->acquire( 'runner', 120 );
        };
        $aGot = $a->acquire( 'runner', 120 );
        $this->assertTrue( $fired );
        $this->assertTrue( $bGot );
        $this->assertFalse( $aGot, 'the second INSERT loses; ON DUPLICATE KEY UPDATE would have let both win' );
        $this->assertSame( self::heldToken( $b, 'runner' ), $this->lockRowToken( 'runner' ) );
        foreach ( $this->db->log as $q ) {
            $this->assertStringNotContainsStringIgnoringCase( 'ON DUPLICATE KEY', $q );
        }
    }

    public function testStaleTakeoverRaceHasExactlyOneWinner(): void {
        $this->db->inner->query( $this->db->inner->prepare( "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('ppclg_lock_runner', %s, 'off')", serialize( [ 'token' => 'dead', 'expires' => time() - 5 ] ) ) );
        $a     = new Locks();
        $b     = new Locks();
        $fired = false;
        $bGot  = null;
        // B takes the expired lock over while A is about to write its own takeover.
        $this->db->beforeQuery = function ( string $sql ) use ( &$fired, &$bGot, $b ): void {
            if ( $fired || ! str_starts_with( $sql, 'UPDATE wp_options' ) ) {
                return;
            }
            $fired = true;
            $bGot  = $b->acquire( 'runner', 120 );
        };
        $aGot = $a->acquire( 'runner', 120 );
        $this->assertTrue( $fired );
        $this->assertTrue( $bGot );
        $this->assertFalse( $aGot, 'the takeover is a compare-and-set on the old value, not a blind UPDATE' );
        $this->assertSame( self::heldToken( $b, 'runner' ), $this->lockRowToken( 'runner' ) );
    }

    public function testReleaseAfterTakeoverLeavesTheNewHolderAlone(): void {
        $a = new Locks();
        $this->assertTrue( $a->acquire( 'runner', 30 ) );
        // A overran its TTL.
        $this->db->inner->query( $this->db->inner->prepare( "UPDATE wp_options SET option_value = %s WHERE option_name = 'ppclg_lock_runner'", serialize( [ 'token' => (string) self::heldToken( $a, 'runner' ), 'expires' => time() - 1 ] ) ) );
        $b = new Locks();
        $this->assertTrue( $b->acquire( 'runner', 120 ), 'stale lock is taken over' );
        $a->release( 'runner' );
        $this->assertSame( self::heldToken( $b, 'runner' ), $this->lockRowToken( 'runner' ), "A's late release does not delete B's lock" );
        $this->assertTrue( ( new Locks() )->isLocked( 'runner' ) );
        $this->assertFalse( ( new Locks() )->acquire( 'runner', 120 ), 'a third runner cannot start' );
        $b->release( 'runner' );
        $this->assertNull( $this->lockRowToken( 'runner' ) );
        $this->assertFalse( ( new Locks() )->isLocked( 'runner' ) );
        $this->assertTrue( ( new Locks() )->acquire( 'runner', 120 ) );
    }

    // ------------------------------------------------------------------
    // data-support-core-6: backups keep "%" literally
    // ------------------------------------------------------------------

    public function testBackupDumpRemovesWpdbPlaceholderEscape(): void {
        $sup = Container::get( Suppression::class );
        $sup->add( 'domain', 'example.com', '100% Canadian, see https://x.ca/?q=a%20b' );
        $backup = Container::get( Backup::class );
        $file   = (string) $backup->dump( [ Schema::table( 'suppression' ) ], 'pre-migration-test' );
        try {
            $this->assertFileExists( $file );
            $dump = implode( '', (array) gzfile( $file ) );
            $this->assertStringContainsString( "'100% Canadian, see https://x.ca/?q=a%20b'", $dump );
            $this->assertSame( 5, $this->db->placeholderCalls, 'every escaped value goes through remove_placeholder_escape()' );
        } finally {
            $dir = dirname( $file );
            foreach ( glob( $dir . '/{,.}*', GLOB_BRACE ) ?: [] as $f ) {
                if ( is_file( $f ) ) {
                    unlink( $f );
                }
            }
            @rmdir( $dir );
        }
    }

    // ------------------------------------------------------------------
    // data-support-core-8: no SHOW TABLES on ordinary requests
    // ------------------------------------------------------------------

    public function testMaybeMigrateRunsNoTableChecksOnFrontEndRequests(): void {
        update_option( 'ppclg_db_version', PPCLG_DB_VERSION );
        $migrator = Container::get( Migrator::class );
        $migrator->maybeMigrate();
        $this->assertSame( [], $this->db->showTablesQueries(), 'front-end/REST/AJAX requests only compare the stored version' );

        // Maintenance checks are cached once every table was seen.
        $this->assertFalse( $migrator->needsMigration() );
        $this->assertCount( count( Schema::TABLES ), $this->db->showTablesQueries() );
        $this->db->log = [];
        $this->assertFalse( $migrator->needsMigration() );
        $this->assertSame( [], $this->db->showTablesQueries(), 'cached for 12 hours' );
    }

    public function testFailedMigrationBacksOff(): void {
        update_option( 'ppclg_db_version', PPCLG_DB_VERSION );
        $this->db->hidden = [ Schema::table( 'search_log' ) ]; // a table that cannot be created
        $migrator         = Container::get( Migrator::class );
        $this->assertTrue( $migrator->needsMigration() );
        $migrator->migrate( false );
        $this->assertNotFalse( get_transient( Migrator::BACKOFF ), 'the next automatic attempt waits' );
        $this->assertFalse( get_transient( Migrator::TABLES_OK ) );
        $this->assertFalse( get_option( 'ppclg_lock_migrate', false ), 'migration lock released' );
    }

    // ------------------------------------------------------------------
    // data-support-core-9: uninstall removes backup directories
    // ------------------------------------------------------------------

    public function testRemoveAllDeletesBackupDirectoriesOnly(): void {
        $base = sys_get_temp_dir() . '/ppclg-dsc-' . bin2hex( random_bytes( 6 ) );
        $out  = $base . '-outside';
        mkdir( $base . '/ppclg-backups-AAAA1111', 0777, true );
        mkdir( $base . '/ppclg-backups-BBBB2222', 0777, true );
        mkdir( $base . '/unrelated', 0777, true );
        mkdir( $out, 0777, true );
        file_put_contents( $base . '/ppclg-backups-AAAA1111/20260101-000000-pre-migration-1-0-0-x.sql.gz', 'contacts' );
        file_put_contents( $base . '/ppclg-backups-AAAA1111/.htaccess', 'Require all denied' );
        file_put_contents( $base . '/ppclg-backups-AAAA1111/index.php', '<?php' );
        file_put_contents( $base . '/ppclg-backups-BBBB2222/dump.sql.gz', 'outreach' );
        file_put_contents( $base . '/unrelated/keep.txt', 'keep' );
        file_put_contents( $out . '/keep.sql.gz', 'keep' );
        symlink( $out, $base . '/ppclg-backups-LINK' );
        try {
            $this->assertSame( 2, Backup::removeAll( $base, 'ppclg-backups-BBBB2222' ) );
            $this->assertDirectoryDoesNotExist( $base . '/ppclg-backups-AAAA1111' );
            $this->assertDirectoryDoesNotExist( $base . '/ppclg-backups-BBBB2222' );
            $this->assertFileExists( $base . '/unrelated/keep.txt' );
            $this->assertFileExists( $out . '/keep.sql.gz', 'a symlinked directory is not followed out of uploads' );
            $this->assertSame( 0, Backup::removeAll( $base, '../unrelated' ), 'the named directory must look like a backup directory' );
            $this->assertFileExists( $base . '/unrelated/keep.txt' );
        } finally {
            @unlink( $base . '/ppclg-backups-LINK' );
            @unlink( $base . '/unrelated/keep.txt' );
            @rmdir( $base . '/unrelated' );
            @rmdir( $base );
            @unlink( $out . '/keep.sql.gz' );
            @rmdir( $out );
        }

        // uninstall.php removes them before ppclg_backup_dir (which names the directory) is deleted.
        $uninstall = (string) file_get_contents( PPCLG_DIR . 'uninstall.php' );
        $remove    = strpos( $uninstall, 'Backup::removeAll(' );
        $options   = strpos( $uninstall, 'delete_option(' );
        $this->assertNotFalse( $remove );
        $this->assertNotFalse( $options );
        $this->assertLessThan( $options, $remove );
        $this->assertLessThan( $remove, (int) strpos( $uninstall, '$ppclg_delete' ), 'only when the owner opted in to deleting data' );
    }

    // ------------------------------------------------------------------
    // data-support-core-11: URLs are fetched as published
    // ------------------------------------------------------------------

    public function testNormalizeKeepsTheServersQuerySemantics(): void {
        $this->assertSame( 'https://dir.example/index.php?cat.id=12&page.no=2', Url::normalize( 'https://dir.example/index.php?cat.id=12&page.no=2' ), 'dots in keys are not turned into underscores' );
        $this->assertSame( 'https://ex.com/listing?amp', Url::normalize( 'https://ex.com/listing?amp' ), 'bare keys stay bare' );
        $this->assertSame( 'https://ex.com/go?id=5&ref=main', Url::normalize( 'https://ex.com/go?ref=main&id=5' ), "functional 'ref' is kept" );
        $this->assertSame( 'https://ex.com/list?tags%5B%5D=a&tags%5B%5D=b', Url::normalize( 'https://ex.com/list?tags[]=a&tags[]=b' ), 'arrays are not re-indexed' );
        $this->assertSame( 'https://ex.com/p?a=1&a=2', Url::normalize( 'https://ex.com/p?a=1&a=2' ), 'repeated keys are all kept, in order' );
        // Unchanged canonical form for ordinary queries (stored hashes stay valid).
        $this->assertSame( 'https://example.com/path?a=1&b=2', Url::normalize( 'HTTPS://Example.com:443/path?utm_source=x&b=2&a=1&gclid=z#frag' ) );
        $this->assertSame( 'https://ex.com/s?q=peptides%20canada&x=100%25', Url::normalize( 'https://ex.com/s?x=100%25&q=peptides+canada' ) );
        $this->assertSame( 'https://ex.com/s?q=caf%C3%A9%20au%20lait', Url::normalize( 'https://ex.com/s?q=café au lait' ) );

        HttpFixtures::add( 'https://dir.example/robots.txt', "User-agent: *\nAllow: /\n", 200, [ 'content-type' => 'text/plain' ] );
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 0 );
        $this->client()->crawl( 'https://dir.example/index.php?cat.id=12&page.no=2' );
        $this->assertContains( 'GET https://dir.example/index.php?cat.id=12&page.no=2', self::requests() );
    }

    public function testAbsolutizeKeepsDirectoryTrailingSlash(): void {
        $this->assertSame( 'https://ex.com/blog/submit/', Url::absolutize( 'submit/', 'https://ex.com/blog/' ) );
        $this->assertSame( 'https://ex.com/blog/', Url::absolutize( './', 'https://ex.com/blog/post' ) );
        $this->assertSame( 'https://ex.com/blog/', Url::absolutize( '.', 'https://ex.com/blog/post' ) );
        $this->assertSame( 'https://ex.com/a/', Url::absolutize( '..', 'https://ex.com/a/b/c' ) );
        $this->assertSame( 'https://ex.com/dir/add-listing/', Url::absolutize( '../add-listing/', 'https://ex.com/dir/x/' ) );
        $this->assertSame( 'https://dir.ex.ca/add-listing/', Url::absolutize( 'add-listing/', 'https://dir.ex.ca/' ) );
        $this->assertSame( 'https://ex.com/', Url::absolutize( '../../..', 'https://ex.com/a/b' ) );
        // Files and queries are unchanged; a "/" inside the query is not a path segment.
        $this->assertSame( 'https://ex.com/dir/other.html', Url::absolutize( 'other.html', 'https://ex.com/dir/page.html' ) );
        $this->assertSame( 'https://ex.com/dir/list.php?next=a%2Fb', Url::absolutize( 'list.php?next=a/b#top', 'https://ex.com/dir/page' ) );
        $this->assertSame( 'https://ex.com/dir/sub/?p=2', Url::absolutize( 'sub/?p=2', 'https://ex.com/dir/page' ) );
        $this->assertSame( 'https://ex.com/abs/', Url::absolutize( '/abs/', 'https://ex.com/' ) );
    }
}
