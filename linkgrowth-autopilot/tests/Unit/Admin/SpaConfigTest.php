<?php
/**
 * WP3 (DESIGN.md §3.1, §10.1): the SPA's router, navigation and Settings tabs come from lga-config, which Admin\Menu
 * fills from the modules' adminPages() and settingsTabs(): each page with its label, icon, module path, capability and
 * order, only the pages the user may open. Server side: what Menu prints for the owner, for a page whose capability the
 * user lacks and for the submitter role, and that tests/fixtures/spa-config.json (the lga-config the browser harnesses
 * load) is what Menu prints. Browser side (SpaConfigHarness.mjs, Playwright + Chrome, skipped when node, Playwright or a
 * browser is missing): a page and a tab added only to lga-config appear and work, a page or tab missing from it is
 * neither listed nor routable, a module path outside the plugin is not loaded; app.js's pure helpers run under node.
 * WP3 part 2 (problems the E2E suite found): a value that is not a web address is not rendered as a link to "#", and
 * a button whose handler ends on a refused request api.js already showed is not an uncaught error of the page.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Admin;

use LinkGrowth\Autopilot\Admin\Capabilities;
use LinkGrowth\Autopilot\Admin\Menu;
use LinkGrowth\Autopilot\Tests\Support\Container;
use LinkGrowth\Autopilot\Tests\Support\TestCase;
use LinkGrowth\Autopilot\Tests\Support\Wp2Modules;

final class SpaConfigTest extends TestCase {

    private const OWNER = [ 'read' => true, 'manage_options' => true, Capabilities::CAP => true ];

    /** @var array<string,mixed>|null */
    private static ?array $harness = null;

    private static string $skip = '';

    private mixed $previousCaps = null;

    protected function setUp(): void {
        parent::setUp();
        $this->previousCaps        = $GLOBALS['lga_test_caps'] ?? null;
        $GLOBALS['lga_test_caps'] = self::OWNER;
    }

    protected function tearDown(): void {
        if ( null === $this->previousCaps ) {
            unset( $GLOBALS['lga_test_caps'] );
        } else {
            $GLOBALS['lga_test_caps'] = $this->previousCaps;
        }
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private static function spaFixture(): array {
        $data = json_decode( (string) file_get_contents( LGA_DIR . 'tests/fixtures/spa-config.json' ), true );
        self::assertIsArray( $data );
        return $data;
    }

    // ------------------------------------------------------------------ server side

    public function testTheHarnessFixtureIsWhatMenuPrintsForTheOwner(): void {
        $fixture = self::spaFixture();
        $config  = Container::get( Menu::class )->config( 'overview' );
        $pages   = array_intersect_key( $config['pages'], array_flip( Wp2Modules::PAGES ) );
        $this->assertSame( $fixture['pages'], $pages, 'the pages of the WP2 modules, as lga-config carries them' );
        $tabs = array_intersect_key( $config['settingsTabs'], $fixture['settingsTabs'] );
        $this->assertSame( $fixture['settingsTabs'], $tabs, 'the Settings tabs of the WP2 modules' );
        $this->assertSame( array_keys( $fixture['settingsTabs'] ), Wp2Modules::only( array_keys( $config['settingsTabs'] ), array_keys( $fixture['settingsTabs'] ) ), 'in tab order' );
    }

    public function testLgaConfigCarriesEachPageDefinitionAndOnlyThePagesTheUserMayOpen(): void {
        add_filter(
            Menu::FILTER_PAGES,
            static function ( array $pages ): array {
                $pages['spa_secret'] = [ 'label' => 'Secret page', 'icon' => 'lock', 'module' => 'pages/spa-secret.js', 'cap' => 'lga_spa_secret', 'order' => 55 ];
                $pages['spa_open']   = [ 'label' => 'Open page', 'icon' => 'zap', 'module' => 'pages/spa-open.js', 'order' => 56 ];
                return $pages;
            }
        );
        $menu   = Container::get( Menu::class );
        $config = $menu->config( 'overview' );
        foreach ( $config['pages'] as $key => $def ) {
            $this->assertSame( [ 'label', 'icon', 'module', 'cap', 'order' ], array_keys( $def ), $key );
            $this->assertSame( Menu::pageDefinitions()[ $key ], $def, $key . ': the module\'s definition as it is' );
        }
        $this->assertSame( array_keys( $config['pages'] ), array_keys( $config['pageUrls'] ) );
        $this->assertSame( array_keys( $config['pages'] ), array_keys( $config['menuSlugs'] ) );
        $this->assertSame( [ 'label' => 'Open page', 'icon' => 'zap', 'module' => 'pages/spa-open.js', 'cap' => Capabilities::CAP, 'order' => 56 ], $config['pages']['spa_open'] ?? null, 'a page without a capability needs the plugin\'s' );
        $this->assertArrayNotHasKey( 'spa_secret', $config['pages'], 'a page whose capability the user lacks is not in lga-config' );
        $this->assertArrayNotHasKey( 'spa_secret', $config['pageUrls'] );

        $GLOBALS['lga_test_caps']['lga_spa_secret'] = true;
        $config = $menu->config( 'overview' );
        $this->assertSame( [ 'label' => 'Secret page', 'icon' => 'lock', 'module' => 'pages/spa-secret.js', 'cap' => 'lga_spa_secret', 'order' => 55 ], $config['pages']['spa_secret'] ?? null );
        $keys = array_keys( $config['pages'] );
        $this->assertLessThan( array_search( 'spa_open', $keys, true ), array_search( 'spa_secret', $keys, true ), 'in navigation order' );
    }

    public function testTheSettingsTabsPointAtTheirOwnModules(): void {
        foreach ( Menu::settingsTabs() as $key => $def ) {
            $this->assertSame( [ 'label', 'module', 'order' ], array_keys( $def ), $key );
            if ( in_array( $key, array_keys( self::spaFixture()['settingsTabs'] ), true ) ) {
                $this->assertSame( 'settings/' . $key . '.js', $def['module'], $key );
                $this->assertFileExists( LGA_DIR . 'assets/js/' . $def['module'] );
            }
        }
        $this->assertFileDoesNotExist( LGA_DIR . 'assets/js/settings/index.js', 'no list of tabs in the Settings folder' );
        $settings = (string) file_get_contents( LGA_DIR . 'assets/js/pages/settings.js' );
        $this->assertStringNotContainsString( 'TAB_IDS', $settings, 'the Settings page has no tab list of its own' );
        $this->assertStringContainsString( 'ctx.config.settingsTabs', $settings );
    }

    public function testTheSubmitterGetsTheOwnerTaskPageOnly(): void {
        $GLOBALS['lga_test_caps'] = [ 'read' => true, Capabilities::SUBMIT_CAP => true ];
        $config                   = Container::get( Menu::class )->config( 'submissions' );
        $this->assertSame(
            [ 'submissions' => [ 'label' => 'Owner tasks', 'icon' => 'package', 'module' => 'pages/submissions.js', 'cap' => Capabilities::SUBMIT_CAP, 'order' => 30 ] ],
            $config['pages']
        );
        $this->assertArrayNotHasKey( 'settingsTabs', $config, 'no Settings for the submitter' );
    }

    // ------------------------------------------------------------------ pure helpers of app.js (node)

    public function testAppHelpersReadDefinitionsRoutesAndModulePaths(): void {
        $h = $this->harness()['helpers'];
        $this->assertSame( [ 'early', 'late', 'bare' ], array_keys( $h['definitions'] ), 'sorted by order; entries without a label or with a bad key are left out' );
        $this->assertSame( [ 'key' => 'bare', 'label' => 'Bare label', 'icon' => '', 'module' => 'pages/bare.js', 'cap' => '', 'order' => 100 ], $h['definitions']['bare'] );
        $this->assertSame( 'settings/probe.js', $h['tabDefinitions']['probe']['module'] );
        $this->assertSame( [], $h['notAMap'] );
        $plugin = 'http://lga.test/wp-content/plugins/linkgrowth-autopilot/assets/js/';
        $this->assertSame(
            [
                'pages/overview.js'      => $plugin . 'pages/overview.js',
                'settings/business.js'   => $plugin . 'settings/business.js',
                '../ui.js'               => '',
                'pages/../x.js'          => '',
                'pages/x.js?y=1'         => '',
                'https://evil.test/x.js' => '',
                'javascript:alert(1)'    => '',
                ''                       => '',
                'pages/x.mjs'            => '',
            ],
            $h['moduleUrls']
        );
        $this->assertSame( [ 'known' => 'logs', 'unknown' => 'overview', 'forbidden' => 'overview', 'noOverview' => 'prospects', 'none' => '' ], $h['routes'] );
    }

    // ------------------------------------------------------------------ browser

    public function testAPageAddedOnlyToLgaConfigIsListedAndRouted(): void {
        $r = $this->scenario( 'configPage' );
        $this->assertSame( [], $r['errors'] );
        $this->assertSame( [ 'First', 'Overview', 'Prospects', 'Submissions', 'Outreach', 'Backlinks', 'Probe', 'On-site SEO', 'Automation', 'Settings' ], array_column( $r['nav'], 'label' ), 'every page of lga-config in its order; Logs is not in it' );
        $this->assertSame( 'shell.nav-probe', $r['nav'][6]['control'] );
        $this->assertSame( 'page', $r['nav'][6]['current'] );
        $this->assertStringEndsWith( 'admin.php?page=lga-probe', (string) $r['nav'][6]['href'] );
        $this->assertSame( 'lga-page lga-page-probe', $r['pageClass'] );
        $this->assertSame( 'Probe – LinkGrowth', $r['title'] );
        $this->assertSame( 'Rendered from lga-config: probe', $r['probeText'] );
        $this->assertSame( 'probe.probe-action', $r['probeAction'], 'the page\'s controls are scoped to it' );
        $this->assertSame( [ 'assets/js/pages/probe.js' ], $r['modules'], 'the module of lga-config is loaded' );
    }

    public function testAPageMissingFromLgaConfigIsNeitherListedNorRouted(): void {
        foreach ( [ 'forbiddenPage', 'unknownPage' ] as $name ) {
            $r = $this->scenario( $name );
            $this->assertSame( [], $r['errors'], $name );
            $this->assertNotContains( 'Logs', array_column( $r['nav'], 'label' ), $name );
            $this->assertSame( 'lga-page lga-page-overview', $r['pageClass'], $name . ': the Overview opens instead' );
            $this->assertSame( [ 'assets/js/pages/overview.js' ], $r['modules'], $name . ': the page\'s own module is never loaded' );
        }
        $r = $this->scenario( 'noOverview' );
        $this->assertSame( [], $r['errors'] );
        $this->assertSame( [ 'Prospects', 'Submissions' ], array_column( $r['nav'], 'label' ), 'in lga-config\'s order' );
        $this->assertSame( 'lga-page lga-page-prospects', $r['pageClass'], 'without the Overview the first page opens' );
        $this->assertNotContains( 'assets/js/pages/logs.js', $r['modules'] );
    }

    public function testAModulePathOutsideThePluginIsNotLoaded(): void {
        $r = $this->scenario( 'refusedModules' );
        foreach ( [ 'evil' => 'https://evil.test/x.js', 'up' => '../../../x.js' ] as $key => $path ) {
            $this->assertSame( [], $r[ $key ]['errors'], $key );
            $this->assertSame( [], $r[ $key ]['external'], $key . ': no request leaves the site' );
            $this->assertSame( [], $r[ $key ]['modules'], $key . ': no page module is requested' );
            $this->assertStringContainsString( 'This page could not be loaded', $r[ $key ]['error'], $key );
            $this->assertStringContainsString( 'The module path "' . $path . '" is not allowed.', $r[ $key ]['error'], $key );
        }
    }

    public function testATabAddedOnlyToLgaConfigIsShownAndSavedThroughPostSettings(): void {
        $r = $this->scenario( 'configTab' );
        $this->assertSame( [], $r['errors'] );
        $this->assertSame( [ 'Business profile', 'Automation & limits', 'Discovery', 'Integrations', 'Outreach & compliance', 'Content & facts', 'Probe settings', 'Advanced', 'Broken tab' ], array_column( $r['tabs'], 'label' ), 'the tabs of lga-config in their order; Monitoring is not in it' );
        $this->assertSame( 'settings.tab-probe', $r['tabs'][6]['control'] );
        $this->assertSame( 'true', $r['tabs'][6]['selected'], '#tab=probe opens it' );
        $this->assertStringContainsString( 'Probe limit', $r['panel'] );
        $this->assertSame( 'settings.field-probe-limit', $r['fieldControl'] );
        $this->assertSame( 'settings.save', $r['saveControl'] );
        $this->assertSame( [ [ 'path' => '/settings', 'body' => [ 'settings' => [ 'probe' => [ 'limit' => 7 ] ] ] ] ], $r['posts'], 'the same POST /settings as every other tab, with the changed leaf only' );
        $this->assertContains( 'assets/js/settings/probe.js', $r['modules'] );
        $this->assertNotContains( 'assets/js/settings/monitoring.js', $r['modules'], 'a tab missing from lga-config is not loaded' );
    }

    public function testATabMissingFromLgaConfigIsNotShownAndABrokenTabStaysContained(): void {
        $r = $this->scenario( 'missingTab' );
        $this->assertSame( [], $r['errors'] );
        $this->assertNotContains( 'Monitoring', array_column( $r['tabs'], 'label' ) );
        $this->assertSame( 'true', $r['tabs'][0]['selected'], '#tab=monitoring opens the first tab' );
        $this->assertStringContainsString( 'Brand name', $r['panel'] );

        $r = $this->scenario( 'brokenTab' );
        $this->assertSame( [], $r['errors'] );
        $this->assertStringContainsString( 'This tab could not be loaded', $r['broken']['panel'] );
        $this->assertStringContainsString( 'The script of the "Broken tab" tab failed to load.', $r['broken']['panel'] );
        $this->assertSame( 'settings.tab-broken-reload', $r['retryControl'] );
        $this->assertTrue( $r['reloaded'], '"Try again" reloads the page' );
        $this->assertStringContainsString( 'Brand name', $r['business']['panel'], 'the other tabs still work' );
    }

    public function testTheUiHelpersNameEveryControlTheyCreate(): void {
        $r = $this->scenario( 'controlHelpers' );
        $this->assertSame( [], $r['errors'] );
        unset( $r['errors'] );
        $this->assertSame(
            [
                'scope'           => 'probe',
                'button'          => 'probe.save',
                'buttonWithoutId' => null,
                'iconButton'      => 'probe.close-it',
                'linkButton'      => 'probe.open-page',
                'link'            => 'probe.logs-link',
                'extLink'         => 'probe.site',
                'extLinkEmpty'    => null,
                'extLinkNotWeb'   => [ null, 'span', 'Manual' ],
                'input'           => 'probe.listing-url',
                'checkbox'        => 'probe.pick',
                'textarea'        => 'probe.note',
                'select'          => 'probe.status',
                'toggle'          => 'probe.flag',
                'fieldByName'     => 'probe.field-site-name',
                'fieldById'       => 'probe.field-x',
                'switchRow'       => 'probe.autopilot',
                'details'         => 'probe.more',
                'staticTile'      => null,
                'linkTile'        => 'probe.tile',
                'tabs'            => [ 'probe.tab-pending-review', 'probe.tab-all' ],
                'table'           => [ 'probe.list-sort-id', 'probe.list-row' ],
                'pager'           => [ 'probe.list-pager-per-page', 'probe.list-pager-prev', 'probe.list-pager-next' ],
                'filterBar'       => [ 'probe.list-filter-status', 'probe.list-filter-q', 'probe.list-filter-reset' ],
                'shellId'         => 'shell.theme',
                'kebab'           => [ 'save-changes', 'pending-review', 'ee-co', 'drawer-tab-contacts', 'a-b', 'shell.notifications-drawer-close' ],
                'scopedDrawer'    => 'shell.panel-close',
                'confirm'         => [ 'probe.delete-confirm-close', 'probe.delete-confirm-cancel', 'probe.delete-confirm-confirm' ],
            ],
            $r,
            'data-lga-control="<page>.<control-id>" on every interactive element a helper creates; none on a static tile or an empty link'
        );
    }

    /**
     * WP3 part 2, found by the E2E suite (e.g. "Suggest from SE Ranking" without a key): a button whose handler ends
     * on a refused request was shown as an error toast and then also became an uncaught error of the page. Now the
     * toast is the end of it; a refusal the handler asked to keep silent, and any other error, still surface as
     * uncaught errors, and the button is usable again either way.
     */
    public function testARefusedRequestInAButtonHandlerEndsAtItsToast(): void {
        $r = $this->scenario( 'surfacedErrors' );
        $this->assertSame( 2, $r['requests'], 'both refusal buttons sent their request' );
        $this->assertSame( [], $r['refused']['errors'], 'no uncaught error for a refusal api.js already showed' );
        $this->assertContains( 'Probe refused: add the API key first.', $r['refused']['toasts'] );
        $this->assertSame( [ 'disabled' => false, 'busy' => 'false' ], $r['refused']['button'] );
        $this->assertSame( [ 'Probe refused: add the API key first.' ], $r['silent']['errors'], 'a silent refusal (no toast) is not swallowed' );
        $this->assertSame( [ 'disabled' => false, 'busy' => 'false' ], $r['silent']['button'] );
        $this->assertSame( [ 'Probe refused: add the API key first.', 'Probe: a bug in a handler' ], $r['broken']['errors'], 'any other error still surfaces' );
        $this->assertSame( [ 'disabled' => false, 'busy' => 'false' ], $r['broken']['button'] );
    }

    // ------------------------------------------------------------------ harness

    /** @return array<string,mixed> */
    private function scenario( string $name ): array {
        $data = $this->harness();
        if ( isset( $data['skip'] ) ) {
            $this->markTestSkipped( (string) $data['skip'] );
        }
        $this->assertArrayHasKey( $name, $data['results'] );
        $r = $data['results'][ $name ];
        $this->assertArrayNotHasKey( 'failed', $r, (string) ( $r['failed'] ?? '' ) );
        return $r;
    }

    /** @return array<string,mixed> */
    private function harness(): array {
        if ( null === self::$harness && '' === self::$skip ) {
            self::runHarness();
        }
        if ( null === self::$harness ) {
            $this->markTestSkipped( self::$skip );
        }
        return self::$harness;
    }

    private static function runHarness(): void {
        $node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
        if ( '' === $node ) {
            self::$skip = 'node is not installed';
            return;
        }
        $playwright = (string) getenv( 'LGA_PLAYWRIGHT' );
        if ( '' === $playwright && is_dir( '/opt/node-tools/node_modules/playwright' ) ) {
            $playwright = '/opt/node-tools/node_modules/playwright';
        }
        if ( '' === $playwright ) {
            $playwright = trim( (string) shell_exec( escapeshellarg( $node ) . ' -e ' . escapeshellarg( "process.stdout.write(require.resolve('playwright'))" ) . ' 2>/dev/null' ) );
        }
        $chrome = (string) getenv( 'LGA_CHROME' );
        if ( '' === $chrome && is_executable( '/usr/bin/google-chrome' ) ) {
            $chrome = '/usr/bin/google-chrome';
        }
        $cmd = escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/SpaConfigHarness.mjs' ) . ' ' . escapeshellarg( rtrim( LGA_DIR, '/' ) ) . ' ' . escapeshellarg( '' !== $playwright ? $playwright : 'playwright' );
        if ( '' !== $chrome ) {
            $cmd .= ' ' . escapeshellarg( $chrome );
        }
        $out  = (string) shell_exec( 'timeout 240 ' . $cmd . ' 2>/dev/null' );
        $data = json_decode( $out, true );
        if ( ! is_array( $data ) || ! isset( $data['helpers'] ) ) {
            self::fail( 'SPA config harness failed: ' . substr( $out, 0, 2000 ) );
        }
        if ( isset( $data['skip'] ) ) {
            // The node helpers ran; only the browser scenarios are skipped.
            self::$harness = [ 'helpers' => $data['helpers'], 'skip' => (string) $data['skip'], 'results' => [] ];
            return;
        }
        self::$harness = $data;
    }
}
