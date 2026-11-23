<?php
/**
 * Wiring snapshot, the parts that need no WordPress (WP2 part 0). WP2 regroups the code into modules
 * (DESIGN.md §3.1, §13.2) and must keep tests/fixtures/golden/wiring.json identical, apart from deliberate
 * differences that its notes document. The hooks, the REST server, the cron schedules and the live tables are
 * compared on the LGA test site by tests/integration/wiring-snapshot.php (tests/fixtures/golden/wiring-wordpress.json).
 *
 * Sections (each test compares one):
 *  - rest_routes: every route of lga/v1 (method, path, handler, permission callback, write rate limit, arguments),
 *    registered the way a REST request does it (the plugin booted, `rest_api_init` fired);
 *  - job_types: JobRunner::types() with each class and its priority;
 *  - tables: the CREATE TABLE statements Schema hands to dbDelta, normalised (Wiring::tables()); the activation cannot
 *    be used for this here, its migration step needs SHOW TABLES, which FakeWpdb lacks (the integration snapshot
 *    reads the tables the real activation created);
 *  - settings_defaults: Settings::defaults() flattened to dot paths;
 *  - settings_validation: for every settings key (and the keys the controller adds to the defaults), POST /settings
 *    with a fixed set of inputs for the key's type, each on a fresh install, and what was stored: the key's value
 *    afterwards, any other key that changed, or the error (a refused change that needs confirmation is sent again
 *    with confirm=true);
 *  - settings_requests: POST /settings requests that are not about one key (unknown keys, a refused request that
 *    also carries another change, secrets, custom seeds);
 *  - admin_menu: the menu and submenu pages for the owner and for the submitter role;
 *  - capabilities: what the activation gives each role.
 *
 * Every section is recorded with the plugin limited to the 13 WP2 modules (Tests\Support\Wp2Modules, through the
 * `lga_modules` filter), so a later package's line in Core\Modules::LIST does not change the snapshot (DESIGN.md
 * §13.1); a change to a WP2 module still does.
 *
 * Classes are written without their namespace (Tests\Support\Wiring). To change the snapshot on purpose:
 * LGA_GOLDEN_UPDATE=1 bash bin/test.sh tests/Unit/Wiring/WiringSnapshotTest.php, then review the diff.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Wiring;

use LinkGrowth\Autopilot\Activator;
use LinkGrowth\Autopilot\Automation\JobRunner;
use LinkGrowth\Autopilot\Database\Schema;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Tests\Support\Container;
use LinkGrowth\Autopilot\Tests\Support\FakeWpdb;
use LinkGrowth\Autopilot\Tests\Support\GoldenJson;
use LinkGrowth\Autopilot\Tests\Support\TestCase;
use LinkGrowth\Autopilot\Tests\Support\Wiring;
use LinkGrowth\Autopilot\Tests\Support\Wp2Modules;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class WiringSnapshotTest extends TestCase {

    private const FILE = 'wiring.json';

    private const NS = 'lga/v1';

    /** Sections in file order. */
    private const SECTIONS = [ 'rest_routes', 'job_types', 'tables', 'settings_defaults', 'settings_validation', 'settings_requests', 'admin_menu', 'capabilities' ];

    /**
     * Keys POST /settings accepts that Settings::defaults() does not list, with the default the services read them
     * with (before WP2 part 2 SettingsController's EXTRA_SCHEMA; now the optional() rules of Support\SettingsSchema).
     * While such a key is not stored, a probe records null.
     */
    private const EXTRA_KEYS = [
        'providers.seranking.fetch_overview' => true,
        'providers.seranking.fetch_summary'  => false,
        'providers.ahrefs.fetch_metrics'     => true,
        'content.linkable_assets'            => [],
    ];

    private const OWNER_CAPS = [ 'lga_manage' => true, 'manage_options' => true ];

    /** The site address the snapshot was recorded with (the bootstrap's default; the defaults derive from it). */
    private const HOME = 'https://northwind.test';

    /** @var array<string,mixed> globals this test pins, as they were */
    private array $previous = [];

    protected function setUp(): void {
        parent::setUp();
        foreach ( [ 'lga_test_caps' => self::OWNER_CAPS, 'lga_test_home' => self::HOME ] as $global => $value ) {
            $this->previous[ $global ] = $GLOBALS[ $global ] ?? null;
            $GLOBALS[ $global ]        = $value;
        }
        $GLOBALS['lga_test_user_id'] = 1;
        Wp2Modules::limit();
    }

    protected function tearDown(): void {
        foreach ( $this->previous as $global => $value ) {
            if ( null === $value ) {
                unset( $GLOBALS[ $global ] );
            } else {
                $GLOBALS[ $global ] = $value;
            }
        }
        $GLOBALS['lga_test_user_id']  = 0;
        $GLOBALS['lga_test_is_admin'] = false;
        unset( $GLOBALS['lga_test_routes'], $GLOBALS['lga_test_menu'] );
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Sections
    // ------------------------------------------------------------------

    public function testRestRoutesMatchTheSnapshot(): void {
        $this->compare( 'rest_routes', $this->restRoutes(), true );
    }

    public function testJobTypesMatchTheSnapshot(): void {
        $out = [];
        foreach ( Container::get( JobRunner::class )->types() as $type => $class ) {
            $out[ $type ] = [ 'class' => Wiring::shortClass( $class ), 'priority' => (int) $class::priority() ];
        }
        ksort( $out );
        $this->compare( 'job_types', $out );
    }

    public function testTablesMatchTheSnapshot(): void {
        global $wpdb;
        $this->compare( 'tables', Wiring::tables( ( new Schema() )->statements(), $wpdb->prefix ) );
    }

    public function testSettingsDefaultsMatchTheSnapshot(): void {
        $flat = Wiring::flatten( Settings::defaults() );
        ksort( $flat );
        $this->compare( 'settings_defaults', $flat );
    }

    public function testSettingsValidationMatchesTheSnapshot(): void {
        $keys = Wiring::flatten( Settings::defaults() ) + self::EXTRA_KEYS;
        ksort( $keys );
        $out = [];
        foreach ( $keys as $key => $default ) {
            $probes = [];
            foreach ( self::inputsFor( $key, $default ) as $input ) {
                $probes[] = [ $input, $this->probeKey( $key, $input ) ];
            }
            $out[ $key ] = [ 'default' => $default, 'probes' => $probes ];
        }
        $this->compare( 'settings_validation', $out );
    }

    public function testSettingsRequestsMatchTheSnapshot(): void {
        $long = str_repeat( 'k', 20001 );
        $cases = [
            'unknown section'                     => [ 'settings' => [ 'no_such_section' => [ 'x' => 1 ] ] ],
            'unknown key in a section'            => [ 'settings' => [ 'automation' => [ 'no_such_key' => true ] ] ],
            'section that is not an object'       => [ 'settings' => [ 'automation' => 'on' ] ],
            'settings that are not an object'     => [ 'settings' => 'on' ],
            'nested object for a scalar key'      => [ 'settings' => [ 'automation' => [ 'tick_interval' => [ 'value' => 120 ] ] ] ],
            'a refused change with a valid one'   => [ 'settings' => [ 'automation' => [ 'outreach_autosend' => true, 'tick_interval' => 120 ] ] ],
            'the same with confirm'               => [ 'settings' => [ 'automation' => [ 'outreach_autosend' => true, 'tick_interval' => 120 ] ], 'confirm' => true ],
            'read-only keys only'                 => [ 'settings' => [ 'compliance' => [ 'acknowledged_hash' => 'abc', 'acknowledged_by' => 3 ], 'seo' => [ 'indexnow_key' => 'k1' ] ] ],
            'autopilot off while it is off'       => [ 'settings' => [ 'automation' => [ 'autopilot' => false ] ] ],
            'secrets'                             => [ 'secrets' => [ 'seranking_api_key' => 'test-key-1', 'brave_api_key' => '__clear__', 'not_a_secret' => 'x', 'google_service_account_json' => '{"client_email":"a@example.test"}', 'semrush_api_key' => $long, 'moz_access_id' => '   ', 'ahrefs_api_key' => 5 ] ],
            'custom seeds'                        => [ 'custom_seeds' => [ [ 'url' => 'https://dir.example.test/add', 'name' => 'Example Directory', 'type' => 'Directory', 'opportunity' => 'no_such_type', 'category' => 'General', 'country' => 'us' ], [ 'url' => 'https://dir.example.test/add', 'name' => 'Duplicate' ], [ 'url' => 'not a url' ], 'not an object' ] ],
        ];
        $out = [];
        foreach ( $cases as $label => $params ) {
            $response = $this->post( $params );
            $row      = [ 'status' => $response['status'] ];
            if ( null !== $response['error'] ) {
                $row['error'] = $response['error'];
            } else {
                $row['changed'] = self::changedSettings();
                $data           = $response['data'];
                if ( isset( $params['secrets'] ) ) {
                    $row['secrets'] = $data['secrets'] ?? null;
                }
                if ( isset( $params['custom_seeds'] ) ) {
                    $row['seeds_updated'] = $data['seeds_updated'] ?? null;
                    $row['custom_seeds']  = get_option( 'lga_custom_seeds', null );
                }
            }
            $out[ $label ] = $row;
        }
        $this->compare( 'settings_requests', $out );
    }

    public function testAdminMenuMatchesTheSnapshot(): void {
        $out = [];
        foreach ( [ 'owner' => self::OWNER_CAPS, 'submitter' => [ 'read' => true, 'lga_submit_listings' => true ] ] as $who => $caps ) {
            $GLOBALS['lga_test_caps']     = $caps;
            $GLOBALS['lga_test_is_admin'] = true;
            $GLOBALS['lga_test_menu']     = [];
            $this->boot();
            do_action( 'admin_menu' );
            $pages = [];
            foreach ( $GLOBALS['lga_test_menu'] as $entry ) {
                $kind = array_shift( $entry );
                if ( 'menu' === $kind ) {
                    [ $pageTitle, $menuTitle, $cap, $slug ] = $entry;
                    $pages[] = [ 'menu' => $slug, 'page_title' => $pageTitle, 'menu_title' => $menuTitle, 'capability' => $cap, 'callback' => Wiring::callback( $entry[4] ?? null ), 'icon' => $entry[5] ?? null, 'position' => $entry[6] ?? null ];
                } else {
                    [ $parent, $pageTitle, $menuTitle, $cap, $slug ] = $entry;
                    $pages[] = [ 'submenu' => $slug, 'parent' => $parent, 'page_title' => $pageTitle, 'menu_title' => $menuTitle, 'capability' => $cap, 'callback' => Wiring::callback( $entry[5] ?? null ) ];
                }
            }
            $out[ $who ] = $pages;
        }
        $this->compare( 'admin_menu', $out );
    }

    public function testCapabilitiesMatchTheSnapshot(): void {
        $GLOBALS['lga_test_roles']      = [];
        $GLOBALS['lga_test_role_names'] = [];
        Activator::activate();
        $roles = [];
        foreach ( $GLOBALS['lga_test_roles'] as $slug => $role ) {
            $caps = array_keys( array_filter( (array) $role->capabilities ) );
            sort( $caps );
            $roles[ $slug ] = [ 'name' => $GLOBALS['lga_test_role_names'][ $slug ] ?? null, 'capabilities' => $caps ];
        }
        ksort( $roles );
        $this->compare( 'capabilities', [ 'roles_after_activation' => $roles ] );
    }

    // ------------------------------------------------------------------
    // Settings probes
    // ------------------------------------------------------------------

    /**
     * The inputs every key of a type is sent with: accepted values, values to normalise or clamp, and values to
     * refuse. String keys all get the same inputs, which include one of each special format the controller knows
     * (e-mail, URL, domain, country, time zone, enumerations), so each key shows which of them it accepts.
     *
     * @return array<int,mixed>
     */
    private static function inputsFor( string $key, mixed $default ): array {
        if ( 'content.linkable_assets' === $key ) {
            return [
                [ [ 'url' => 'https://www.example.test/guide/', 'title' => 'Buying guide', 'summary' => 'How to choose a supplier.', 'topics' => 'Guides, Buying', 'kind' => 'Guide' ] ],
                [ [ 'url' => 'https://www.example.test/guide/', 'title' => '' ], [ 'url' => 'not a url', 'title' => 'No URL' ], 'not an object' ],
                [ [ 'url' => 'https://www.example.test/best/', 'title' => 'World-class guide', 'summary' => 'Guaranteed results.' ] ],
                [],
                'text',
            ];
        }
        if ( is_bool( $default ) ) {
            return [ true, false, '1', 'off', 'maybe', [ 'x' ] ];
        }
        if ( is_int( $default ) ) {
            return [ 7, '12', -5, 1000000, 'abc', 2.7, [ 'x' ] ];
        }
        if ( is_array( $default ) ) {
            return [
                [ 'Alpha', 'beta', 'Alpha' ],
                "one, two\nthree",
                [ 'https://www.Example.test/path', 'not a domain', 'sub.example.test' ],
                [ 3, '4', 9, 'x' ],
                [],
                5,
                [ [ 'nested' ] ],
            ];
        }
        return [ 'Example text', '  <b>Spaced</b>   text  ', '', 'owner@example.test', 'https://www.example.test/page?x=1', 'us', 'Europe/Berlin', 'manual', 'debug', 'lang_meta', 42, [ 'not', 'a', 'string' ] ];
    }

    /** @return array<string,mixed> what one POST /settings {settings: {key: input}} did on a fresh install */
    private function probeKey( string $key, mixed $input ): array {
        $params   = [ 'settings' => Wiring::nest( $key, $input ) ];
        $response = $this->post( $params );
        if ( null === $response['error'] ) {
            return self::outcome( $key, $response['status'] );
        }
        $row = [ 'status' => $response['status'], 'error' => $response['error'] ];
        if ( 'lga_confirmation_required' === $response['error'] ) {
            $confirmed = $this->post( $params + [ 'confirm' => true ] );
            $row['confirmed'] = null === $confirmed['error'] ? self::outcome( $key, $confirmed['status'] ) : [ 'status' => $confirmed['status'], 'error' => $confirmed['error'] ];
        }
        return $row;
    }

    /** @return array<string,mixed> the key's stored value afterwards, and any other setting that changed */
    private static function outcome( string $key, int $status ): array {
        $after = self::storedSettings();
        $row   = [ 'status' => $status, 'value' => array_key_exists( $key, $after ) ? $after[ $key ] : null ];
        $also  = self::changedSettings();
        unset( $also[ $key ] );
        if ( [] !== $also ) {
            $row['also'] = $also;
        }
        return $row;
    }

    /** @return array<string,mixed> the stored settings over the defaults, flattened */
    private static function storedSettings(): array {
        $stored = get_option( Settings::OPTION, [] );
        return Wiring::flatten( Settings::mergeDeep( Settings::defaults(), is_array( $stored ) ? $stored : [] ) );
    }

    /** @return array<string,mixed> dot path => value for every setting that differs from its default */
    private static function changedSettings(): array {
        $defaults = Wiring::flatten( Settings::defaults() );
        $out      = [];
        foreach ( self::storedSettings() as $k => $v ) {
            if ( ! array_key_exists( $k, $defaults ) || $defaults[ $k ] !== $v ) {
                $out[ $k ] = $v;
            }
        }
        ksort( $out );
        return $out;
    }

    /**
     * POST /settings on a fresh install, as the REST server dispatches it: the route's permission callback, then its
     * callback.
     *
     * @param array<string,mixed> $params
     * @return array{status:int,error:?string,data:array<string,mixed>}
     */
    private function post( array $params ): array {
        $GLOBALS['lga_test_options']    = [ 'lga_running_version' => [ 'version' => LGA_VERSION, 'since' => 0, 'from' => '' ] ];
        $GLOBALS['lga_test_transients'] = [];
        $GLOBALS['wpdb']                = new FakeWpdb();
        foreach ( ( new Schema() )->statements() as $sql ) {
            $GLOBALS['wpdb']->createFromMysql( $sql );
        }
        $def     = $this->route( 'POST', '/' . self::NS . '/settings' );
        $request = new WP_REST_Request( 'POST', '/' . self::NS . '/settings' );
        foreach ( $params as $name => $value ) {
            $request->set_param( (string) $name, $value );
        }
        $this->assertTrue( ( $def['permission_callback'] )( $request ), 'the owner may POST /settings' );
        $result = ( $def['callback'] )( $request );
        if ( $result instanceof WP_Error ) {
            $data = $result->get_error_data();
            return [ 'status' => (int) ( is_array( $data ) ? ( $data['status'] ?? 500 ) : 500 ), 'error' => $result->get_error_code(), 'data' => [] ];
        }
        $this->assertInstanceOf( WP_REST_Response::class, $result );
        $data = $result->get_data();
        return [ 'status' => $result->get_status(), 'error' => null, 'data' => is_array( $data ) ? $data : [] ];
    }

    /** @return array<string,mixed> the handler registered for $method $path, the way a REST request registers it */
    private function route( string $method, string $path ): array {
        $GLOBALS['lga_test_routes'] = [];
        $this->boot();
        do_action( 'rest_api_init' );
        foreach ( $GLOBALS['lga_test_routes'][ ltrim( $path, '/' ) ] ?? [] as $registration ) {
            foreach ( (array) $registration as $def ) {
                if ( is_array( $def ) && in_array( $method, preg_split( '/[,\s]+/', strtoupper( (string) ( $def['methods'] ?? '' ) ) ) ?: [], true ) ) {
                    return $def;
                }
            }
        }
        $this->fail( "{$method} {$path} is not registered" );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    private function restRoutes(): array {
        $GLOBALS['lga_test_routes'] = [];
        $this->boot();
        do_action( 'rest_api_init' );
        return Wiring::restRoutes( $GLOBALS['lga_test_routes'], self::NS );
    }

    /** A new request: no hooks left from an earlier one, a fresh container, the plugin (the WP2 modules) booted. */
    private function boot(): void {
        $GLOBALS['lga_test_actions'] = [];
        $GLOBALS['lga_test_filters'] = [];
        add_filter( Wp2Modules::FILTER, [ Wp2Modules::class, 'filter' ] );
        Container::reset();
        Container::plugin()->boot();
    }

    private function compare( string $section, mixed $actual, bool $ordered = false ): void {
        if ( GoldenJson::updating() ) {
            $golden             = GoldenJson::read( self::FILE );
            $golden[ $section ] = $actual;
            $out                = [ '_about' => 'Wiring snapshot without WordPress (tests/Unit/Wiring/WiringSnapshotTest.php), recorded on the WP1 fork state (e160656). WP2 keeps it identical apart from documented, deliberate differences; rewrite only on purpose with LGA_GOLDEN_UPDATE=1.' ];
            foreach ( self::SECTIONS as $name ) {
                if ( array_key_exists( $name, $golden ) ) {
                    $out[ $name ] = $golden[ $name ];
                }
            }
            GoldenJson::write( self::FILE, $out );
            $this->markTestSkipped( 'LGA_GOLDEN_UPDATE=1: section ' . $section . ' of tests/fixtures/golden/' . self::FILE . ' rewritten; review the diff.' );
        }
        $golden = GoldenJson::read( self::FILE );
        $this->assertArrayHasKey( $section, $golden, 'tests/fixtures/golden/' . self::FILE . ' has section ' . $section );
        $expected = $golden[ $section ];
        if ( $ordered && is_array( $expected ) && is_array( $actual ) ) {
            $sort = static function ( array $rows ): array {
                $keys = array_map( static fn( $r ): string => (string) json_encode( $r ), $rows );
                sort( $keys );
                return $keys;
            };
            $this->assertSame( $sort( $expected ), $sort( $actual ), $section . ' (ignoring order): ' . GoldenJson::firstDifference( $sort( $expected ), $sort( $actual ) ) );
            $this->assertSame( $expected, $actual, $section . ': the same entries, in a different order: ' . GoldenJson::firstDifference( $expected, $actual ) );
            return;
        }
        $this->assertSame( $expected, $actual, $section . ': ' . GoldenJson::firstDifference( $expected, $actual ) );
    }
}
