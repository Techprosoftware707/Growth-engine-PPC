<?php
/**
 * "Link Growth Submitter" role: a least-privilege role for a staff member or assistant who does the owner tasks
 * by hand. Covers the role (creation, idempotency, healing, removal, the capability filter), the REST permission
 * matrix over every registered ppclg/v1 route, the server-side scope of the three submitter routes (list, one
 * submission, mark-submitted), the audit actor, and the menu / bootstrap flag.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Admin;

use PPC\LinkGrowth\Admin\Capabilities;
use PPC\LinkGrowth\Admin\Menu;
use PPC\LinkGrowth\Admin\Rest\RestApi;
use PPC\LinkGrowth\Admin\Rest\SubmissionsController;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SubmitterRoleTest extends TestCase {

    private const SUBMITTER = 7;

    private const ADMIN = 1;

    /** Routes (and methods) a submitter-only user may call. Every other admin route must answer 403. */
    private const SUBMITTER_ALLOWED = [
        'ppclg/v1/submissions'                                 => [ 'GET' ],
        'ppclg/v1/submissions/(?P<id>\d+)'                     => [ 'GET' ],
        'ppclg/v1/submissions/(?P<id>\d+)/mark-submitted'      => [ 'POST' ],
    ];

    /** Public routes (unsubscribe links, inbound reply webhook): open to everyone by design. */
    private const PUBLIC_ROUTES = [
        'ppclg/v1/unsubscribe' => [ 'GET', 'POST' ],
        'ppclg/v1/inbound'     => [ 'POST' ],
    ];

    private mixed $previousCaps = null;

    protected function setUp(): void {
        parent::setUp();
        $this->previousCaps              = $GLOBALS['ppclg_test_caps'] ?? null;
        $GLOBALS['ppclg_test_roles']      = [];
        $GLOBALS['ppclg_test_role_names'] = [];
        $GLOBALS['ppclg_test_routes']     = [];
        $GLOBALS['ppclg_test_menu']       = [];
        $GLOBALS['ppclg_test_user_caps']  = [];
        $this->asSubmitter();
    }

    protected function tearDown(): void {
        if ( null === $this->previousCaps ) {
            unset( $GLOBALS['ppclg_test_caps'] );
        } else {
            $GLOBALS['ppclg_test_caps'] = $this->previousCaps;
        }
        $GLOBALS['ppclg_test_user_id']    = 0;
        $GLOBALS['ppclg_test_routes']     = [];
        $GLOBALS['ppclg_test_menu']       = [];
        $GLOBALS['ppclg_test_roles']      = [];
        $GLOBALS['ppclg_test_role_names'] = [];
        $GLOBALS['ppclg_test_user_caps']  = [];
        unset( $_GET['page'] );
        parent::tearDown();
    }

    private function asSubmitter(): void {
        $GLOBALS['ppclg_test_user_id'] = self::SUBMITTER;
        $GLOBALS['ppclg_test_caps']    = [ 'read' => true, Capabilities::SUBMIT_CAP => true ];
    }

    private function asAdmin(): void {
        $GLOBALS['ppclg_test_user_id'] = self::ADMIN;
        $GLOBALS['ppclg_test_caps']    = [ 'read' => true, 'manage_options' => true, Capabilities::CAP => true ];
    }

    private function asAnonymous(): void {
        $GLOBALS['ppclg_test_user_id'] = 0;
        $GLOBALS['ppclg_test_caps']    = [];
    }

    private function controller(): SubmissionsController {
        return new SubmissionsController( Container::plugin()->container() );
    }

    /** @param array<string,mixed> $params */
    private function request( string $method, string $route, array $params = [] ): WP_REST_Request {
        $r = new WP_REST_Request( $method, '/ppclg/v1' . $route );
        foreach ( $params as $k => $v ) {
            $r->set_param( $k, $v );
        }
        return $r;
    }

    /** Dispatches like WordPress: the registered permission callback, then the wrapped handler. */
    private function dispatch( string $method, string $routeKey, string $path, array $params = [] ): mixed {
        $handlers = $GLOBALS['ppclg_test_routes'][ 'ppclg/v1' . $routeKey ] ?? null;
        $this->assertIsArray( $handlers, 'route registered: ' . $routeKey );
        $handler = $handlers[0][0];
        $this->assertStringContainsString( $method, (string) $handler['methods'] );
        $request = $this->request( $method, $path, $params );
        $allowed = ( $handler['permission_callback'] )( $request );
        if ( true !== $allowed ) {
            return $allowed;
        }
        $response = ( $handler['callback'] )( $request );
        return $response instanceof WP_REST_Response ? $response->get_data() : $response;
    }

    private static function httpStatus( mixed $error ): int {
        return $error instanceof WP_Error ? (int) ( $error->get_error_data()['status'] ?? 0 ) : 0;
    }

    /**
     * One row per state the scope rule distinguishes.
     *
     * @return array<string,int> label => submission id
     */
    private function fixtureRows(): array {
        $prospects = Container::get( Prospects::class );
        $subs      = Container::get( Submissions::class );
        [ $pid ]   = $prospects->upsertDiscovered( 'labdirectory.test', [ 'name' => 'Lab Directory' ] );
        // Rows that were already sent live on another site: a sent listing keeps further tasks on its site with
        // the owner (a second listing would be a duplicate), which the blocker tests cover.
        [ $donePid ] = $prospects->upsertDiscovered( 'donedirectory.test', [ 'name' => 'Done Directory' ] );
        $recent    = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
        $old       = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
        $owner     = [
            'destination' => [ 'name' => 'Lab Directory', 'url' => 'https://labdirectory.test/add' ],
            'business'    => [ 'name' => 'Pure Peptides Canada', 'website' => 'https://purepeptidescanada.test/' ],
            'content'     => [ 'description' => 'Research peptides supplier.' ],
            'instructions' => [ 'Create the listing by hand.' ],
            'compliance'  => [ 'clean' => true, 'violations' => [] ],
            'form'        => [ 'action' => 'https://labdirectory.test/post', 'secret_token' => 'csrf-abc' ],
            'edited_by'   => 1,
            'package'     => [ 'source' => 'verified_db', 'item_id' => 'abc', 'platform' => 'Lab Directory', 'tier' => 1, 'outreach' => false, 'conditional' => false, 'steps' => 'Sign up. Add listing.' ],
        ];
        $internal = [ 'response' => [ 'adapter_log' => 'raw adapter response', 'body' => '<html>secret</html>' ], 'last_error' => 'Internal: adapter exploded', 'attempts' => 3, 'adapter' => 'generic_form' ];
        $rows     = [
            'open_owner'      => [ 'status' => 'needs_manual', 'payload' => $owner ] + $internal,
            'open_policy'     => [ 'status' => 'needs_manual', 'payload' => [ 'content' => [ 'description' => 'Policy manual kit' ], 'instructions' => [ 'Fill the form.' ] ] ],
            'pending'         => [ 'status' => 'pending_review', 'payload' => $owner ],
            'approved'        => [ 'status' => 'approved' ],
            'failed'          => [ 'status' => 'failed', 'last_error' => 'boom' ],
            'mine_recent'     => [ 'status' => 'submitted', 'method' => 'manual', 'reviewed_by' => self::SUBMITTER, 'submitted_at' => $recent, 'payload' => $owner ],
            'mine_confirmed'  => [ 'status' => 'confirmed', 'method' => 'manual', 'reviewed_by' => self::SUBMITTER, 'submitted_at' => $recent ],
            'mine_old'        => [ 'status' => 'submitted', 'method' => 'manual', 'reviewed_by' => self::SUBMITTER, 'submitted_at' => $old ],
            'other_user'      => [ 'status' => 'submitted', 'method' => 'manual', 'reviewed_by' => 99, 'submitted_at' => $recent ],
            'mine_automated'  => [ 'status' => 'submitted', 'method' => 'form', 'reviewed_by' => self::SUBMITTER, 'submitted_at' => $recent ],
        ];
        $ids = [];
        foreach ( $rows as $label => $data ) {
            $sent          = ! empty( $data['submitted_at'] );
            $ids[ $label ] = $subs->create( $sent ? $donePid : $pid, null, 'https://' . ( $sent ? 'donedirectory' : 'labdirectory' ) . '.test/add/' . $label, $data );
        }
        return $ids;
    }

    // ------------------------------------------------------------------
    // Role and capabilities
    // ------------------------------------------------------------------

    public function testEnsureCreatesTheSubmitterRoleWithOnlyReadAndTheSubmitCapability(): void {
        $this->assertSame( 'ppclg_submit_listings', Capabilities::SUBMIT_CAP );
        $this->assertNull( get_role( Capabilities::SUBMITTER_ROLE ), 'no role before ensure()' );

        ( new Capabilities() )->ensure( true );

        $role = get_role( Capabilities::SUBMITTER_ROLE );
        $this->assertNotNull( $role );
        $this->assertSame( [ 'read' => true, Capabilities::SUBMIT_CAP => true ], $role->capabilities );
        $this->assertSame( 'Link Growth Submitter', $GLOBALS['ppclg_test_role_names'][ Capabilities::SUBMITTER_ROLE ] );
        $this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::CAP ), 'administrators keep full access' );
        $this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::SUBMIT_CAP ), 'administrators do not need the submitter capability' );
    }

    public function testEnsureIsIdempotentAndResetsTheRoleToLeastPrivilege(): void {
        $caps = new Capabilities();
        $caps->ensure( true );
        $caps->ensure( true );
        $this->assertSame( [ 'read' => true, Capabilities::SUBMIT_CAP => true ], get_role( Capabilities::SUBMITTER_ROLE )->capabilities );

        // A role editor (or another plugin) widens the role; the next sync takes it back to the two capabilities.
        $role = get_role( Capabilities::SUBMITTER_ROLE );
        $role->add_cap( Capabilities::CAP );
        $role->add_cap( 'manage_options' );
        $role->add_cap( 'edit_posts' );
        $role->remove_cap( 'read' );
        $caps->ensure( true );
        $this->assertEquals( [ 'read' => true, Capabilities::SUBMIT_CAP => true ], $role->capabilities );

        // The hourly throttle still applies to a normal (unforced) sync.
        $role->add_cap( 'edit_posts' );
        $caps->ensure();
        $this->assertTrue( $role->has_cap( 'edit_posts' ), 'throttled: no sync within the hour' );
    }

    public function testCapabilityRolesFilterNeverGivesFullAccessToTheSubmitterRole(): void {
        add_filter( 'ppclg_capability_roles', static fn( array $roles ): array => array_merge( $roles, [ Capabilities::SUBMITTER_ROLE, 'editor' ] ) );
        ( new Capabilities() )->ensure( true );
        $this->assertFalse( get_role( Capabilities::SUBMITTER_ROLE )->has_cap( Capabilities::CAP ) );
        $this->assertTrue( get_role( 'editor' )->has_cap( Capabilities::CAP ), 'other filtered roles still get it' );
        $this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::CAP ) );
    }

    public function testRemoveDeletesTheRoleAndBothCapabilities(): void {
        $caps = new Capabilities();
        $caps->ensure( true );
        get_role( 'editor' )->add_cap( Capabilities::SUBMIT_CAP );
        $this->assertNotFalse( get_transient( Capabilities::SYNC_TRANSIENT ) );

        $caps->remove();

        $this->assertNull( get_role( Capabilities::SUBMITTER_ROLE ) );
        $this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::CAP ) );
        $this->assertFalse( get_role( 'editor' )->has_cap( Capabilities::SUBMIT_CAP ) );
        $this->assertFalse( get_transient( Capabilities::SYNC_TRANSIENT ) );
    }

    public function testUninstallRemovesTheRoleEvenWhenDataIsKept(): void {
        ( new Capabilities() )->ensure( true );
        $this->assertNotNull( get_role( Capabilities::SUBMITTER_ROLE ) );
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', 'ppc-link-growth-engine/ppc-link-growth-engine.php' );
        }
        // No delete_on_uninstall opt-in: data stays, the role and capabilities go.
        ( static function (): void {
            include PPCLG_DIR . 'uninstall.php';
        } )();
        $this->assertNull( get_role( Capabilities::SUBMITTER_ROLE ) );
        $this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::CAP ) );
    }

    public function testActivationForcesTheRoleSync(): void {
        $src = (string) file_get_contents( PPCLG_DIR . 'includes/Activator.php' );
        $this->assertStringContainsString( '$plugin->get( Capabilities::class )->ensure( true );', $src );
    }

    public function testUserCanSubmitAndSubmitOnly(): void {
        $this->asSubmitter();
        $this->assertTrue( Capabilities::userCanSubmit() );
        $this->assertFalse( Capabilities::userCan() );
        $this->assertTrue( Capabilities::submitOnly() );

        $this->asAdmin();
        $this->assertTrue( Capabilities::userCanSubmit(), 'full access includes the owner-task queue' );
        $this->assertFalse( Capabilities::submitOnly() );

        $this->asAnonymous();
        $this->assertFalse( Capabilities::userCanSubmit() );
        $this->assertFalse( Capabilities::submitOnly() );

        // By user id.
        $GLOBALS['ppclg_test_user_caps'][ 42 ] = [ Capabilities::SUBMIT_CAP => true ];
        $GLOBALS['ppclg_test_user_caps'][ 43 ] = [ 'manage_options' => true ];
        $this->assertTrue( Capabilities::userCanSubmit( 42 ) );
        $this->assertTrue( Capabilities::submitOnly( 42 ) );
        $this->assertTrue( Capabilities::userCanSubmit( 43 ) );
        $this->assertFalse( Capabilities::submitOnly( 43 ) );
        $this->assertFalse( Capabilities::userCanSubmit( 44 ) );
    }

    // ------------------------------------------------------------------
    // Permission matrix: every registered route
    // ------------------------------------------------------------------

    public function testEveryRouteIsDeniedToASubmitterExceptTheOwnerTaskRoutes(): void {
        Container::get( RestApi::class )->register();
        $routes = $GLOBALS['ppclg_test_routes'];
        $this->assertGreaterThan( 60, count( $routes ), 'all controllers registered' );
        foreach ( array_keys( self::SUBMITTER_ALLOWED + self::PUBLIC_ROUTES ) as $key ) {
            $this->assertArrayHasKey( $key, $routes, 'allow-listed route still exists: ' . $key );
        }

        $checked = 0;
        foreach ( $routes as $key => $registrations ) {
            foreach ( $registrations as $registration ) {
                foreach ( $registration as $handler ) {
                    $methods = array_filter( array_map( 'trim', explode( ',', (string) $handler['methods'] ) ) );
                    $perm    = $handler['permission_callback'];
                    foreach ( $methods as $method ) {
                        $label = $method . ' ' . $key;
                        if ( '__return_true' === $perm ) {
                            $this->assertContains( $method, self::PUBLIC_ROUTES[ $key ] ?? [], 'only the known public routes skip the capability check: ' . $label );
                            continue;
                        }
                        $this->assertIsCallable( $perm, $label );
                        $request = new WP_REST_Request( $method, '/' . $key );
                        $request->set_param( 'id', 1 );

                        $this->asSubmitter();
                        $result = $perm( $request );
                        if ( in_array( $method, self::SUBMITTER_ALLOWED[ $key ] ?? [], true ) ) {
                            $this->assertTrue( $result, 'submitter allowed: ' . $label );
                        } else {
                            $this->assertInstanceOf( WP_Error::class, $result, 'submitter denied: ' . $label );
                            $this->assertSame( 403, self::httpStatus( $result ), $label );
                        }

                        $this->asAnonymous();
                        $result = $perm( $request );
                        $this->assertInstanceOf( WP_Error::class, $result, 'anonymous denied: ' . $label );
                        $this->assertSame( 401, self::httpStatus( $result ), $label );

                        $this->asAdmin();
                        $this->assertTrue( $perm( $request ), 'administrator allowed: ' . $label );
                        ++$checked;
                    }
                }
            }
        }
        $this->assertGreaterThan( 70, $checked );
    }

    public function testApproveRejectRetryRebuildRouteIsAdminOnlyAndMarkSubmittedHasItsOwnRoute(): void {
        $this->controller()->register( 'ppclg/v1' );
        $routes = $GLOBALS['ppclg_test_routes'];
        $this->assertArrayNotHasKey( 'ppclg/v1/submissions/(?P<id>\d+)/(?P<action>approve|reject|retry|rebuild|mark-submitted)', $routes );
        $admin = $routes['ppclg/v1/submissions/(?P<id>\d+)/(?P<action>approve|reject|retry|rebuild)'][0][0] ?? null;
        $this->assertIsArray( $admin );
        $this->assertSame( [ 'approve', 'reject', 'retry', 'rebuild' ], $admin['args']['action']['enum'] );
        $this->assertSame( 'permissionWrite', $admin['permission_callback'][1] );
        $mark = $routes['ppclg/v1/submissions/(?P<id>\d+)/mark-submitted'][0][0] ?? null;
        $this->assertIsArray( $mark );
        $this->assertSame( 'permissionSubmit', $mark['permission_callback'][1] );
        $this->assertSame( 'POST', $mark['methods'] );

        // Through the dispatcher: a submitter cannot approve, even with an action parameter pointing elsewhere.
        $ids = $this->fixtureRows();
        foreach ( [ 'approve', 'reject', 'retry', 'rebuild' ] as $action ) {
            $res = $this->dispatch( 'POST', '/submissions/(?P<id>\d+)/(?P<action>approve|reject|retry|rebuild)', '/submissions/' . $ids['open_owner'] . '/' . $action, [ 'id' => $ids['open_owner'], 'action' => $action ] );
            $this->assertSame( 403, self::httpStatus( $res ), $action );
        }
        $this->assertSame( 'needs_manual', Container::get( Submissions::class )->find( $ids['open_owner'] )['status'] );
    }

    // ------------------------------------------------------------------
    // Scope: list, show, mark-submitted
    // ------------------------------------------------------------------

    public function testListIsLimitedToOpenTasksAndTheSubmittersRecentWork(): void {
        $ids = $this->fixtureRows();
        $this->controller()->register( 'ppclg/v1' );
        $list = fn( array $params = [] ): array => $this->dispatch( 'GET', '/submissions', '/submissions', $params + [ 'per_page' => 100 ] );
        $idsOf = static fn( array $page ): array => array_map( static fn( array $i ): int => (int) $i['id'], $page['items'] );

        $all = $list();
        $expected = [ $ids['open_owner'], $ids['open_policy'], $ids['mine_recent'], $ids['mine_confirmed'] ];
        sort( $expected );
        $got = $idsOf( $all );
        sort( $got );
        $this->assertSame( $expected, $got );
        $this->assertSame( 4, $all['total'] );
        $this->assertSame( [ 'confirmed' => 1, 'needs_manual' => 2, 'submitted' => 1 ], ( static function ( array $f ): array {
            ksort( $f );
            return $f;
        } )( $all['facets']['status'] ), 'facets count only the scope' );

        // Client filters only narrow the scope.
        $this->assertSame( [], $idsOf( $list( [ 'status' => 'pending_review' ] ) ) );
        $this->assertSame( [], $idsOf( $list( [ 'status' => 'approved,failed,pending_review' ] ) ) );
        $this->assertSame( 0, $list( [ 'status' => 'pending_review' ] )['total'] );
        $narrow = $idsOf( $list( [ 'status' => 'needs_manual,pending_review,failed' ] ) );
        sort( $narrow );
        $this->assertSame( [ $ids['open_owner'], $ids['open_policy'] ], $narrow );
        $this->assertSame( [ $ids['mine_recent'] ], $idsOf( $list( [ 'status' => 'submitted' ] ) ), 'other users and old rows stay out' );
        $this->assertSame( [ $ids['open_owner'], $ids['mine_recent'] ], array_values( array_intersect( $expected, $idsOf( $list( [ 'source' => 'verified_db' ] ) ) ) ) );
        $this->assertNotContains( $ids['pending'], $idsOf( $list( [ 'source' => 'verified_db' ] ) ), 'source filter does not reach pending_review owner tasks' );
        $this->assertNotContains( $ids['pending'], $idsOf( $list( [ 'q' => 'pending' ] ) ) );

        // Rows carry what the queue needs, nothing internal.
        foreach ( $all['items'] as $item ) {
            foreach ( [ 'response', 'last_error', 'attempts', 'adapter', 'reviewed_by', 'prospect_id', 'opportunity_id', 'prospect_status', 'target_hash', 'scheduled_at' ] as $k ) {
                $this->assertArrayNotHasKey( $k, $item, $k );
            }
            $this->assertArrayNotHasKey( 'compliance', $item['payload'] );
            $this->assertContains( $item['prospect_domain'], [ 'labdirectory.test', 'donedirectory.test' ] );
        }
        $owner = array_values( array_filter( $all['items'], static fn( array $i ): bool => (int) $i['id'] === $ids['open_owner'] ) )[0];
        $this->assertSame( 'verified_db', $owner['payload']['package']['source'] );

        // The administrator still sees everything.
        $this->asAdmin();
        $this->assertSame( count( $ids ), $list()['total'] );
    }

    public function testShowRefusesOutOfScopeIdsAndStripsInternals(): void {
        $ids = $this->fixtureRows();
        $this->controller()->register( 'ppclg/v1' );
        $show = fn( int $id ): mixed => $this->dispatch( 'GET', '/submissions/(?P<id>\d+)', '/submissions/' . $id, [ 'id' => $id ] );

        foreach ( [ 'pending', 'approved', 'failed', 'mine_old', 'other_user', 'mine_automated' ] as $label ) {
            $res = $show( $ids[ $label ] );
            $this->assertInstanceOf( WP_Error::class, $res, $label );
            $this->assertSame( 404, self::httpStatus( $res ), $label . ': looks like a missing row' );
        }
        $this->assertSame( 404, self::httpStatus( $show( 999999 ) ) );

        $res = $show( $ids['open_owner'] );
        $this->assertIsArray( $res );
        $sub = $res['submission'];
        $this->assertSame( $ids['open_owner'], $sub['id'] );
        $this->assertSame( 'needs_manual', $sub['status'] );
        foreach ( [ 'response', 'last_error', 'attempts', 'adapter', 'reviewed_by', 'prospect_id', 'opportunity_id', 'target_hash' ] as $k ) {
            $this->assertArrayNotHasKey( $k, $sub, $k );
        }
        $this->assertSame( [ 'destination', 'business', 'content', 'instructions', 'package' ], array_keys( $sub['payload'] ) );
        $json = (string) wp_json_encode( $res );
        $this->assertStringNotContainsString( 'raw adapter response', $json );
        $this->assertStringNotContainsString( 'adapter exploded', $json );
        $this->assertStringNotContainsString( 'csrf-abc', $json );
        $this->assertSame( [ 'domain' => 'labdirectory.test', 'name' => 'Lab Directory' ], $res['prospect'] );
        $this->assertNull( $res['opportunity'] );

        $this->assertIsArray( $show( $ids['mine_recent'] ), 'own recent work stays readable' );

        // The administrator gets the full detail as before.
        $this->asAdmin();
        $full = $show( $ids['open_owner'] );
        $this->assertSame( 'Internal: adapter exploded', $full['submission']['last_error'] );
        $this->assertArrayHasKey( 'response', $full['submission'] );
    }

    public function testMarkSubmittedWorksOnlyOnOpenTasksAndRecordsTheActingUser(): void {
        $ids = $this->fixtureRows();
        $this->controller()->register( 'ppclg/v1' );
        $mark = fn( int $id, array $params = [] ): mixed => $this->dispatch( 'POST', '/submissions/(?P<id>\d+)/mark-submitted', '/submissions/' . $id . '/mark-submitted', [ 'id' => $id ] + $params );
        $repo = Container::get( Submissions::class );

        // Outside the scope: 404; already marked: 409. Nothing changes.
        foreach ( [ 'pending', 'approved', 'failed', 'other_user' ] as $label ) {
            $before = $repo->find( $ids[ $label ] )['status'];
            $this->assertSame( 404, self::httpStatus( $mark( $ids[ $label ] ) ), $label );
            $this->assertSame( $before, $repo->find( $ids[ $label ] )['status'], $label );
        }
        $this->assertSame( 409, self::httpStatus( $mark( $ids['mine_recent'] ) ) );

        // The listing URL must be http(s); a bad one is refused before anything is recorded.
        foreach ( [ 'javascript:alert(1)', 'ftp://files.example/x', 'data:text/html,hi', 'mailto:owner@example.org', ' ' . chr( 1 ) . 'x' ] as $bad ) {
            $res = $mark( $ids['open_owner'], [ 'listing_url' => $bad ] );
            $this->assertSame( 400, self::httpStatus( $res ), $bad );
            $this->assertSame( 'ppclg_invalid_listing_url', $res->get_error_code() );
        }
        $this->assertSame( 'needs_manual', $repo->find( $ids['open_owner'] )['status'] );

        $res = $mark( $ids['open_owner'], [ 'listing_url' => 'labdirectory.test/listing/pure-peptides-canada', 'note' => '<b>Done</b> by VA <script>alert(1)</script>' ] );
        $this->assertIsArray( $res );
        $this->assertTrue( $res['ok'] );
        $this->assertSame( 'submitted', $res['submission']['status'] );
        $this->assertArrayNotHasKey( 'response', $res['submission'], 'the reply is scoped too' );

        $row = $repo->find( $ids['open_owner'] );
        $this->assertSame( 'submitted', $row['status'] );
        $this->assertSame( 'https://labdirectory.test/listing/pure-peptides-canada', $row['listing_url'] );
        $this->assertSame( self::SUBMITTER, (int) $row['reviewed_by'] );
        $this->assertSame( self::SUBMITTER, (int) $row['response']['manual']['by'] );
        $this->assertSame( 'Done by VA', $row['response']['manual']['note'], 'note is sanitized' );
        $this->assertNotEmpty( $row['next_verify_at'], 'listing verification picks it up' );

        $audit = Container::get( AuditLog::class )->findOneBy( [ 'action' => 'submission.marked_submitted', 'object_id' => $ids['open_owner'] ] );
        $this->assertNotNull( $audit );
        $this->assertSame( 'user:' . self::SUBMITTER, $audit['actor'] );

        // The task now shows under the submitter's recent work and cannot be marked twice.
        $this->assertIsArray( $this->dispatch( 'GET', '/submissions/(?P<id>\d+)', '/submissions/' . $ids['open_owner'], [ 'id' => $ids['open_owner'] ] ) );
        $this->assertSame( 409, self::httpStatus( $mark( $ids['open_owner'] ) ) );

        // Calling the shared handler with another action is refused for a submitter.
        $res = $this->controller()->action( $this->request( 'POST', '/submissions/' . $ids['open_policy'] . '/approve', [ 'id' => $ids['open_policy'], 'action' => 'approve' ] ) );
        $this->assertSame( 403, self::httpStatus( $res ) );
        $this->assertSame( 'needs_manual', $repo->find( $ids['open_policy'] )['status'] );

        // Administrators keep the old reach (mark-submitted on a pending_review row).
        $this->asAdmin();
        $res = $mark( $ids['pending'], [ 'listing_url' => 'https://labdirectory.test/l/1' ] );
        $this->assertTrue( $res['ok'] );
        $this->assertArrayHasKey( 'response', $res['submission'] );
    }

    // ------------------------------------------------------------------
    // Blocked owner tasks, listing URL, verifier, role after an update
    // ------------------------------------------------------------------

    /**
     * needs_manual rows the engine stopped on purpose, each with the warning the owner would see.
     *
     * @return array<string,int> label => submission id
     */
    private function blockedRows(): array {
        $prospects = Container::get( Prospects::class );
        $subs      = Container::get( Submissions::class );
        $kit       = [ 'content' => [ 'description' => 'Research peptides supplier.' ], 'instructions' => [ 'Fill the form.' ], 'compliance' => [ 'clean' => true, 'violations' => [] ] ];
        $site      = static function ( string $domain, string $status = 'qualified' ) use ( $prospects ): int {
            [ $id ] = $prospects->upsertDiscovered( $domain, [ 'name' => $domain ] );
            $prospects->update( (int) $id, [ 'status' => $status ] );
            return (int) $id;
        };
        $ids = [];
        $ids['good'] = $subs->create( $site( 'good-site.test' ), null, 'https://good-site.test/add', [ 'status' => 'needs_manual', 'payload' => $kit ] );
        foreach ( [ 'suppressed', 'rejected', 'on_hold' ] as $status ) {
            $ids[ $status ] = $subs->create( $site( $status . '-site.test', $status ), null, 'https://' . $status . '-site.test/add', [ 'status' => 'needs_manual', 'payload' => $kit, 'last_error' => 'The prospect is ' . $status . '; automated submission cancelled.' ] );
        }
        $ids['claims'] = $subs->create(
            $site( 'claims-site.test' ),
            null,
            'https://claims-site.test/add',
            [
                'status'     => 'needs_manual',
                'last_error' => 'The package text fails the claims compliance check (1 issue(s)); edit the flagged text before it can be submitted.',
                'payload'    => [ 'content' => [ 'description' => 'Cures cancer' ], 'compliance' => [ 'clean' => false, 'violations' => [ [ 'rule' => 'disease_claim', 'match' => 'Cures cancer', 'message' => 'Disease claim' ] ] ] ] + $kit,
            ]
        );
        $ids['outcome_unknown'] = $subs->create( $site( 'unknown-site.test' ), null, 'https://unknown-site.test/add', [ 'status' => 'needs_manual', 'payload' => $kit, 'response' => [ 'outcome_unknown' => true ], 'last_error' => 'An earlier automated attempt was interrupted before its result was recorded, so the listing may already have been sent.' ] );
        $dupSite = $site( 'dup-site.test' );
        $subs->create( $dupSite, null, 'https://dup-site.test/first', [ 'status' => 'submitted', 'method' => 'form', 'submitted_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ] );
        $ids['duplicate'] = $subs->create( $dupSite, null, 'https://dup-site.test/second', [ 'status' => 'needs_manual', 'payload' => $kit, 'last_error' => 'A listing was already submitted to this site (#1, submitted); a second automated submission would be a duplicate.' ] );
        $ids['sent_self'] = $subs->create( $site( 'sentself-site.test' ), null, 'https://sentself-site.test/add', [ 'status' => 'needs_manual', 'payload' => $kit, 'submitted_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ] );
        $ids['outreach'] = $subs->create(
            $site( 'outreach-site.test' ),
            null,
            'https://outreach-site.test/contact',
            [ 'status' => 'needs_manual', 'payload' => [ 'package' => [ 'source' => 'verified_db', 'item_id' => 'out1', 'platform' => 'Library guide', 'tier' => 2, 'outreach' => true, 'conditional' => false, 'contact_email' => 'librarian@outreach-site.test' ] ] + $kit ]
        );
        return $ids;
    }

    public function testOwnerTasksTheEngineStoppedStayWithTheOwner(): void {
        $ids = $this->blockedRows();
        $this->controller()->register( 'ppclg/v1' );
        $repo    = Container::get( Submissions::class );
        $blocked = array_diff_key( $ids, [ 'good' => true ] );

        $page = $this->dispatch( 'GET', '/submissions', '/submissions', [ 'per_page' => 100 ] );
        $this->assertSame( [ $ids['good'] ], array_map( static fn( array $i ): int => (int) $i['id'], $page['items'] ), 'only the clear task is in the queue' );
        $this->assertSame( 1, $page['total'] );
        $this->assertSame( [ 'needs_manual' => 1 ], $page['facets']['status'] );
        foreach ( [ [ 'source' => 'verified_db' ], [ 'kind' => 'outreach' ], [ 'status' => 'needs_manual' ], [ 'q' => 'site' ] ] as $filter ) {
            $got = array_map( static fn( array $i ): int => (int) $i['id'], $this->dispatch( 'GET', '/submissions', '/submissions', $filter + [ 'per_page' => 100 ] )['items'] );
            $this->assertSame( [], array_values( array_intersect( $got, $blocked ) ), wp_json_encode( $filter ) );
        }

        foreach ( $blocked as $label => $id ) {
            $this->assertSame( 404, self::httpStatus( $this->dispatch( 'GET', '/submissions/(?P<id>\d+)', '/submissions/' . $id, [ 'id' => $id ] ) ), 'show: ' . $label );
            $this->assertSame( 404, self::httpStatus( $this->dispatch( 'POST', '/submissions/(?P<id>\d+)/mark-submitted', '/submissions/' . $id . '/mark-submitted', [ 'id' => $id ] ) ), 'mark: ' . $label );
            $this->assertSame( 'needs_manual', $repo->find( $id )['status'], 'unchanged: ' . $label );
        }
        $this->assertTrue( $this->dispatch( 'POST', '/submissions/(?P<id>\d+)/mark-submitted', '/submissions/' . $ids['good'] . '/mark-submitted', [ 'id' => $ids['good'] ] )['ok'] );

        // The owner sees every row and, on the blocked ones, why the submitter does not.
        $this->asAdmin();
        $this->assertSame( count( $ids ) + 1, $this->dispatch( 'GET', '/submissions', '/submissions', [ 'per_page' => 100 ] )['total'] );
        $expect = [
            'suppressed'      => 'The site is suppressed',
            'rejected'        => 'The site is rejected',
            'on_hold'         => 'The site is on hold',
            'claims'          => 'fails the claims check',
            'outcome_unknown' => 'may already have sent this listing',
            'duplicate'       => 'a second one would be a duplicate',
            'sent_self'       => 'a second one would be a duplicate',
            'outreach'        => 'pitch to a person',
        ];
        foreach ( $expect as $label => $needle ) {
            $res = $this->dispatch( 'GET', '/submissions/(?P<id>\d+)', '/submissions/' . $ids[ $label ], [ 'id' => $ids[ $label ] ] );
            $this->assertStringContainsString( $needle, implode( ' ', $res['submitter_blockers'] ), $label );
        }
    }

    public function testListingUrlMustBeOnTheTaskSiteAndNeverOnTheOwnSite(): void {
        $ids  = $this->fixtureRows();
        $this->controller()->register( 'ppclg/v1' );
        $mark = fn( int $id, string $url ): mixed => $this->dispatch( 'POST', '/submissions/(?P<id>\d+)/mark-submitted', '/submissions/' . $id . '/mark-submitted', [ 'id' => $id, 'listing_url' => $url ] );
        $repo = Container::get( Submissions::class );

        foreach ( [ 'https://purepeptidescanada.test/', 'https://www.purepeptidescanada.test/shop/', 'https://unrelated-blog.test/post', 'https://labdirectory.test.evil.test/x' ] as $bad ) {
            $res = $mark( $ids['open_owner'], $bad );
            $this->assertSame( 400, self::httpStatus( $res ), $bad );
            $this->assertSame( 'ppclg_invalid_listing_url', $res->get_error_code(), $bad );
        }
        $this->assertStringContainsString( 'labdirectory.test', $mark( $ids['open_owner'], 'https://unrelated-blog.test/post' )->get_error_message() );
        $this->assertSame( 'needs_manual', $repo->find( $ids['open_owner'] )['status'] );
        $this->assertTrue( $mark( $ids['open_owner'], 'https://listings.labdirectory.test/pure-peptides' )['ok'], 'a page on the task site (any subdomain) is accepted' );

        // The owner may record a listing elsewhere, but not on their own site either.
        $this->asAdmin();
        $this->assertSame( 400, self::httpStatus( $mark( $ids['pending'], 'https://purepeptidescanada.test/' ) ) );
        $this->assertTrue( $mark( $ids['pending'], 'https://syndicated-news.test/story' )['ok'] );
    }

    public function testListingVerificationIgnoresAListingUrlOnTheOwnSite(): void {
        \PPC\LinkGrowth\Tests\Support\HttpFixtures::reset();
        Container::get( \PPC\LinkGrowth\Support\Settings::class )->set( 'advanced.crawl_delay_seconds', 0 );
        [ $pid ] = Container::get( Prospects::class )->upsertDiscovered( 'verify-dir.test', [ 'name' => 'Verify Directory' ] );
        $own     = 'https://purepeptidescanada.test/';
        \PPC\LinkGrowth\Tests\Support\HttpFixtures::add( $own, '<html><body><a href="https://purepeptidescanada.test/shop/">Shop</a></body></html>' );
        $subs = Container::get( Submissions::class );
        $id   = $subs->create( (int) $pid, null, 'https://verify-dir.test/add', [ 'status' => 'submitted', 'method' => 'manual', 'listing_url' => $own, 'submitted_at' => gmdate( 'Y-m-d H:i:s' ) ] );

        $out = Container::get( \PPC\LinkGrowth\Submission\ListingVerifier::class )->verify( $subs->find( $id ), Container::get( Prospects::class )->find( (int) $pid ) );

        $this->assertFalse( $out['confirmed'] );
        $this->assertSame( 'submitted', $subs->find( $id )['status'] );
        $fetched = array_filter( \PPC\LinkGrowth\Tests\Support\HttpFixtures::$requests, static fn( array $r ): bool => str_starts_with( $r['url'], $own ) );
        $this->assertSame( [], $fetched, 'the own-site page is not even fetched' );
        $this->assertNull( Container::get( \PPC\LinkGrowth\Repositories\Backlinks::class )->findOneBy( [ 'prospect_id' => (int) $pid ] ), 'no backlink recorded' );
    }

    public function testEnsureCreatesAMissingRoleEvenWhileThrottled(): void {
        // State after an update that ran no activation hook: the previous version's hourly sync is still fresh.
        set_transient( Capabilities::SYNC_TRANSIENT, 1, HOUR_IN_SECONDS );
        $this->assertNull( get_role( Capabilities::SUBMITTER_ROLE ) );
        ( new Capabilities() )->ensure();
        $this->assertNotNull( get_role( Capabilities::SUBMITTER_ROLE ), 'the role is there at once' );
        $this->assertSame( [ 'read' => true, Capabilities::SUBMIT_CAP => true ], get_role( Capabilities::SUBMITTER_ROLE )->capabilities );
    }

    public function testSubmitterDetailCarriesTheOpportunityAndNoHiddenFormTokens(): void {
        [ $pid ] = Container::get( Prospects::class )->upsertDiscovered( 'fields-dir.test', [ 'name' => 'Fields Directory' ] );
        [ $oppId ] = Container::get( \PPC\LinkGrowth\Repositories\Opportunities::class )->upsert( (int) $pid, 'directory', 'https://fields-dir.test/suppliers', 'Supplier directory' );
        $oppId     = (int) $oppId;
        $this->assertGreaterThan( 0, $oppId );
        $fields = [
            [ 'name' => 'company', 'label' => 'Company', 'type' => 'text', 'semantic' => 'business_name', 'required' => true, 'value' => 'Pure Peptides Canada' ],
            [ 'name' => 'cat', 'label' => 'Category', 'type' => 'select', 'semantic' => 'category', 'required' => true, 'value' => '7', 'matched_option' => [ 'value' => '7', 'text' => 'Laboratory supplies' ] ],
            [ 'name' => '_token', 'label' => '', 'type' => 'hidden', 'semantic' => 'unknown', 'required' => false, 'value' => 'third-party-csrf' ],
        ];
        $id = Container::get( Submissions::class )->create( (int) $pid, $oppId, 'https://fields-dir.test/add', [ 'status' => 'needs_manual', 'payload' => [ 'content' => [ 'description' => 'x', 'keywords' => [ 'peptides' ] ], 'fields' => $fields, 'compliance' => [ 'clean' => true, 'violations' => [] ] ] ] );
        $this->controller()->register( 'ppclg/v1' );
        $res = $this->dispatch( 'GET', '/submissions/(?P<id>\d+)', '/submissions/' . $id, [ 'id' => $id ] );

        $this->assertSame( [ 'id' => $oppId, 'type' => 'directory', 'title' => 'Supplier directory', 'url' => 'https://fields-dir.test/suppliers' ], $res['opportunity'] );
        $this->assertSame( [ 'company', 'cat' ], array_column( $res['submission']['payload']['fields'], 'name' ) );
        $this->assertStringNotContainsString( 'third-party-csrf', (string) wp_json_encode( $res ) );
        $this->assertSame( [ 'peptides' ], $res['submission']['payload']['content']['keywords'] );
    }

    // ------------------------------------------------------------------
    // Menu and bootstrap
    // ------------------------------------------------------------------

    public function testSubmitterGetsOneMenuItemAndAScopedBootstrap(): void {
        $menu = Container::get( Menu::class );
        $menu->addMenu();
        $this->assertCount( 1, $GLOBALS['ppclg_test_menu'] );
        [ $type, $pageTitle, $menuTitle, $cap, $slug ] = $GLOBALS['ppclg_test_menu'][0];
        $this->assertSame( 'menu', $type );
        $this->assertSame( 'Link Submissions', $menuTitle );
        $this->assertSame( Capabilities::SUBMIT_CAP, $cap );
        $this->assertSame( 'ppclg-submissions', $slug );

        $config = $menu->config( 'submissions' );
        $this->assertTrue( $config['capabilities']['submitOnly'] );
        $this->assertFalse( $config['capabilities']['manage'] );
        $this->assertSame( [ 'submissions' ], array_keys( $config['pages'] ) );
        $this->assertSame( [ 'submissions' ], array_keys( $config['pageUrls'] ) );
        $this->assertSame( 'submissions', $config['page'] );
        $this->assertSame( 0, $config['unreadNotifications'] );
        $this->assertFalse( $config['automationEnabled'] );
        $this->assertArrayNotHasKey( 'newUserUrl', $config );
        $this->assertArrayNotHasKey( 'ajaxUrl', $config );

        // Another plugin page opened directly is not rendered.
        foreach ( [ 'ppclg', 'ppclg-settings', 'ppclg-prospects', 'ppclg-logs' ] as $page ) {
            $_GET['page'] = $page;
            ob_start();
            try {
                $menu->render();
                $this->fail( 'rendered ' . $page );
            } catch ( \RuntimeException $e ) {
                $this->assertStringContainsString( 'wp_die', $e->getMessage(), $page );
            } finally {
                $out = (string) ob_get_clean();
            }
            $this->assertSame( '', $out, $page );
        }
        $_GET['page'] = 'ppclg-submissions';
        ob_start();
        $menu->render();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString( 'data-page="submissions"', $html );
        $this->assertStringContainsString( '"submitOnly":true', $html );

        // WooCommerce's "customers stay out of wp-admin" rule is lifted for this user only.
        $this->assertFalse( $menu->allowSubmitterAdmin( true ) );
        $this->asAnonymous();
        $this->assertTrue( $menu->allowSubmitterAdmin( true ) );
    }

    public function testAdministratorKeepsTheFullMenuAndGetsTheHandOverLink(): void {
        $this->asAdmin();
        $menu = Container::get( Menu::class );
        $menu->addMenu();
        $this->assertCount( 1 + count( Menu::pages() ), $GLOBALS['ppclg_test_menu'] );
        $this->assertSame( Capabilities::CAP, $GLOBALS['ppclg_test_menu'][0][3] );
        $this->assertSame( 'ppclg', $GLOBALS['ppclg_test_menu'][0][4] );

        $config = $menu->config( 'settings' );
        $this->assertFalse( $config['capabilities']['submitOnly'] );
        $this->assertSame( Menu::PAGE_KEYS, array_keys( $config['pages'] ) );
        $this->assertSame( 'https://purepeptidescanada.test/wp-admin/user-new.php', $config['newUserUrl'] );
        $this->assertSame( [ 'slug' => 'ppclg_link_submitter', 'name' => 'Link Growth Submitter' ], $config['submitterRole'] );
        $this->assertTrue( $menu->allowSubmitterAdmin( true ), 'administrators are not affected' );

        // Someone with neither capability gets no page.
        $this->asAnonymous();
        $GLOBALS['ppclg_test_user_id'] = 5;
        $_GET['page']                  = 'ppclg-submissions';
        $this->expectException( \RuntimeException::class );
        $menu->render();
    }
}
