<?php
/**
 * Regression tests for the js-core slice fixes (assets/js/app.js and ui.js): sibling modules loaded so a
 * stale cached copy shows a failure state instead of a blank screen, per-column sorting only, page clamp
 * after rows leave a list, same-document fragment navigation re-rendering the page, busy buttons that keep
 * focus and the disabled state their handler chose, and hidden flags that win over author display rules.
 *
 * Each fix has a static guard on the source, plus a behavioural check in headless Chrome
 * (JsCoreFixesHarness.mjs via Playwright) that runs when node, Playwright and a browser are available
 * and is skipped otherwise. PPCLG_PLAYWRIGHT / PPCLG_CHROME override the module and browser paths.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class JsCoreFixesTest extends TestCase {

    /** @var array<string,mixed>|null Harness observations, shared by the browser tests (one browser run). */
    private static ?array $browser = null;

    private static string $skipReason = '';

    private static function source( string $relative ): string {
        $source = file_get_contents( PPCLG_DIR . 'assets/js/' . $relative );
        self::assertIsString( $source );
        return $source;
    }

    /** Body of a top-level `function name(...) {...}` in $source (brace matched; sources contain no braces in strings there). */
    private static function functionBody( string $source, string $name ): string {
        $start = strpos( $source, 'function ' . $name . '(' );
        self::assertNotFalse( $start, "function $name not found" );
        $open  = strpos( $source, '{', (int) strpos( $source, ')', $start ) );
        $depth = 0;
        $len   = strlen( $source );
        for ( $i = (int) $open; $i < $len; $i++ ) {
            if ( '{' === $source[ $i ] ) {
                $depth++;
            } elseif ( '}' === $source[ $i ] && 0 === --$depth ) {
                return substr( $source, (int) $open, $i - (int) $open + 1 );
            }
        }
        self::fail( "unbalanced body for $name" );
    }

    // ------------------------------------------------------------------ static guards

    public function testAppEntryHasNoStaticSiblingImportsAndAVisibleLoadFailure(): void {
        $app = self::source( 'app.js' );
        // A static import that fails to link (stale cached ui.js) stops the module before any code runs.
        $this->assertDoesNotMatchRegularExpression( '/^\s*import\s+(?!\()/m', $app, 'app.js must not statically import its siblings' );
        $this->assertStringContainsString( "import( './api.js' )", $app );
        $this->assertStringContainsString( "import( './ui.js' )", $app );
        $start = self::functionBody( $app, 'start' );
        $this->assertStringContainsString( 'renderLoadFailure', $start, 'a failed core import must render a failure state' );
        $this->assertStringNotContainsString( 'innerHTML', self::functionBody( $app, 'renderLoadFailure' ) );
    }

    public function testListViewOnlyMakesColumnsMarkedSortableSortable(): void {
        $ui = self::source( 'ui.js' );
        $this->assertMatchesRegularExpression( '/export function listView\(\s*\{[^\n]*\bsortable = false\b/', $ui );
    }

    public function testSetBusyNeitherDisablesNorReEnablesTheButton(): void {
        $body = self::functionBody( self::source( 'ui.js' ), 'setBusy' );
        $this->assertStringNotContainsString( '.disabled', $body, 'busy must not take focus away nor overwrite the handler\'s disabled state' );
        $this->assertStringContainsString( 'aria-disabled', $body );
    }

    public function testListViewClampsAPageThatNoLongerExists(): void {
        $ui = self::source( 'ui.js' );
        $this->assertMatchesRegularExpression( '/state\.page > list\.pages/', $ui );
    }

    public function testAppReRendersOnSameDocumentFragmentNavigation(): void {
        $app = self::source( 'app.js' );
        $this->assertStringContainsString( "addEventListener( 'hashchange'", $app );
        $this->assertStringContainsString( "addEventListener( 'popstate'", $app );
        $this->assertStringContainsString( 'isSameDocument( target )', self::functionBody( $app, 'boot' ) );
    }

    public function testHiddenFlagsOnFlexElementsAreMirroredInline(): void {
        $ui = self::source( 'ui.js' );
        $this->assertStringContainsString( "node.style.display = hidden ? 'none' : ''", self::functionBody( $ui, 'setHidden' ) );
        $this->assertDoesNotMatchRegularExpression( "/class: 'ppclg-count', hidden:/", $ui );
        $this->assertDoesNotMatchRegularExpression( "/class: 'ppclg-drawer-foot', hidden:/", $ui );
        $this->assertDoesNotMatchRegularExpression( '/foot\.hidden\s*=/', $ui );
    }

    // ------------------------------------------------------------------ browser checks

    /** js-core-1: sibling modules reach the import map; stale or missing core modules show a failure state. */
    public function testBrowserStaleOrMissingCoreModulesShowAFailureState(): void {
        $r = $this->browser();

        $this->assertTrue( $r['versioned']['shell'], 'app boots with the import map' );
        $this->assertTrue( $r['versioned']['overview'] );
        $this->assertSame( [], $r['versioned']['errors'] );
        $this->assertGreaterThanOrEqual( 5, count( $r['versioned']['js'] ) );
        foreach ( $r['versioned']['js'] as $url ) {
            $this->assertStringContainsString( '?ver=9.9.9', $url, "$url bypassed the versioned import map" );
        }

        foreach ( [ 'staleUi', 'missingApi' ] as $case ) {
            $this->assertNotNull( $r[ $case ]['failure'], "$case: #ppclg-app must not stay blank" );
            $this->assertStringContainsString( 'could not load', (string) $r[ $case ]['failure'] );
            $this->assertSame( 'alert', $r[ $case ]['failureRole'] );
            $this->assertGreaterThan( 0, $r[ $case ]['mountLength'] );
        }
        $this->assertTrue( $r['staleUi']['patched'], 'the stale ui.js fixture was built' );
    }

    /** js-core-2 */
    public function testBrowserOnlyColumnsMarkedSortableGetSortButtons(): void {
        $r = $this->browser()['sorting'];
        $buttons = array_column( $r['before'], 'button', 'label' );
        $this->assertSame( [ 'Prospect' => true, 'Score' => true, 'Class' => false, 'Authority' => false ], $buttons );
        $this->assertSame( 'domain desc', $r['sentAfterClick'] );
        $this->assertSame( 'descending', $r['afterClick'][0]['ariaSort'] );
        $this->assertSame( [ true, false ], $r['wholeTable'], 'sortable: true for the whole table still opts in, sortable: false still opts out' );
    }

    /** js-core-3 */
    public function testBrowserFocusReturnsToTheOpenerInsideTheDrawer(): void {
        $r = $this->browser()['focus'];
        $this->assertTrue( $r['confirmOpen']['inModal'] );
        $this->assertSame( 'reject', $r['afterEscape']['id'] );
        $this->assertTrue( $r['afterTab']['insideDrawer'], 'Tab after closing the confirm stays in the aria-modal drawer' );
        $this->assertTrue( $r['afterTab']['drawerOpen'] );
        $this->assertSame( 'reject', $r['afterModalButton']['id'] );
        $this->assertTrue( $r['tabFromBody']['insideDrawer'], 'plain Tab from <body> goes back into the drawer' );
    }

    /** js-core-4 */
    public function testBrowserReloadAfterRowsLeaveTheLastPageShowsTheLastExistingPage(): void {
        $r = $this->browser()['pageClamp'];
        $this->assertSame( [ 'page' => 2, 'rows' => 1 ], $r['onLastPage'] );
        $this->assertSame( 1, $r['afterRemoval']['page'] );
        $this->assertSame( 25, $r['afterRemoval']['rows'] );
        $this->assertFalse( $r['afterRemoval']['empty'] );
        $this->assertStringContainsString( 'Page 1 of 1', $r['afterRemoval']['pager'] );
        $this->assertSame( [ 2, 1 ], $r['afterRemoval']['calls'] );
        $this->assertTrue( $r['allRemoved']['empty'] );
        $this->assertSame( [ 1 ], $r['allRemoved']['calls'], 'an empty result on page 1 does not loop' );
    }

    /** js-core-5 */
    public function testBrowserSameDocumentFragmentNavigationReRendersThePage(): void {
        $r = $this->browser()['fragmentNavigation'];
        $this->assertTrue( $r['linkRerendered'], 'a ctx.pageUrl() link to the open page re-renders it' );
        $this->assertSame( 'same-document', $r['afterLink']['marker'], 'without a full reload' );
        $this->assertSame( '#campaign_id=3', $r['afterLink']['hash'] );
        $this->assertTrue( $r['backRerendered'], 'Back re-renders the previous view' );
        $this->assertFalse( $r['anchorRerendered'], 'plain in-page anchors are left alone' );
        $this->assertSame( [], $r['errors'] );
    }

    /** js-core-6 */
    public function testBrowserBusyStateKeepsTheDisabledStateTheHandlerChose(): void {
        $r = $this->browser()['busy'];
        $this->assertSame( 'true', $r['whileBusy']['ariaDisabled'] );
        $this->assertSame( 'true', $r['whileBusy']['ariaBusy'] );
        $this->assertSame( 1, $r['after']['runs'], 'clicks while busy are ignored' );
        $this->assertTrue( $r['after']['disabled'], 'Settings\' Save stays disabled after a successful save' );
        $this->assertNull( $r['after']['ariaDisabled'] );
        $this->assertFalse( $r['plainAfter']['disabled'] );
        $this->assertNull( $r['plainAfter']['ariaDisabled'] );
    }

    /** js-core-7 */
    public function testBrowserHiddenCountPillsAndEmptyFootersAreNotRendered(): void {
        $r = $this->browser()['hidden'];
        foreach ( [ 'noCount', 'setCountNull', 'drawerFootEmpty', 'modalFootEmpty' ] as $case ) {
            $this->assertTrue( $r[ $case ]['hidden'], $case );
            $this->assertSame( 'none', $r[ $case ]['display'], $case );
        }
        $this->assertSame( 0, (int) $r['noCount']['width'] );
        $this->assertNotSame( 'none', $r['withCount']['display'] );
        $this->assertNotSame( 'none', $r['setCountSeven']['display'] );
        $this->assertNotSame( 'none', $r['drawerFootActions']['display'] );
        $this->assertSame( [], $this->browser()['uiErrors'] );
    }

    // ------------------------------------------------------------------ harness runner

    /** @return array<string,mixed> */
    private function browser(): array {
        if ( null === self::$browser && '' === self::$skipReason ) {
            self::runHarness();
        }
        if ( null === self::$browser ) {
            $this->markTestSkipped( self::$skipReason );
        }
        return self::$browser;
    }

    private static function runHarness(): void {
        $node = self::which( 'node' );
        if ( null === $node ) {
            self::$skipReason = 'node is not installed';
            return;
        }
        $playwright = self::playwright( $node );
        if ( null === $playwright ) {
            self::$skipReason = 'Playwright is not installed (set PPCLG_PLAYWRIGHT)';
            return;
        }
        $chrome = (string) getenv( 'PPCLG_CHROME' );
        if ( '' === $chrome && is_executable( '/usr/bin/google-chrome' ) ) {
            $chrome = '/usr/bin/google-chrome';
        }
        $cmd = [ $node, __DIR__ . '/JsCoreFixesHarness.mjs', PPCLG_DIR, $playwright ];
        if ( '' !== $chrome ) {
            $cmd[] = $chrome;
        }
        [ $code, $out, $err ] = self::exec( $cmd, 150 );
        $data = json_decode( $out, true );
        if ( ! is_array( $data ) ) {
            self::fail( "Browser harness failed (exit $code): " . substr( $err . $out, 0, 2000 ) );
        }
        if ( isset( $data['skip'] ) ) {
            self::$skipReason = (string) $data['skip'];
            return;
        }
        self::$browser = (array) ( $data['results'] ?? [] );
    }

    private static function which( string $binary ): ?string {
        foreach ( explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ) as $dir ) {
            $path = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $binary;
            if ( '' !== $dir && is_file( $path ) && is_executable( $path ) ) {
                return $path;
            }
        }
        return null;
    }

    private static function playwright( string $node ): ?string {
        $env = (string) getenv( 'PPCLG_PLAYWRIGHT' );
        if ( '' !== $env ) {
            return $env;
        }
        if ( is_dir( '/opt/node-tools/node_modules/playwright' ) ) {
            return '/opt/node-tools/node_modules/playwright';
        }
        [ $code, $out ] = self::exec( [ $node, '-e', "process.stdout.write(require.resolve('playwright'))" ], 20 );
        return 0 === $code && '' !== trim( $out ) ? trim( $out ) : null;
    }

    /**
     * Runs a command without a shell, with a deadline.
     *
     * @param list<string> $cmd
     * @return array{0:int,1:string,2:string}
     */
    private static function exec( array $cmd, int $timeout ): array {
        $dir  = sys_get_temp_dir();
        $out  = (string) tempnam( $dir, 'ppclg-js-out' );
        $err  = (string) tempnam( $dir, 'ppclg-js-err' );
        $proc = proc_open( $cmd, [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', $out, 'w' ], 2 => [ 'file', $err, 'w' ] ], $pipes );
        if ( ! is_resource( $proc ) ) {
            return [ -1, '', 'proc_open failed' ];
        }
        fclose( $pipes[0] );
        $deadline = microtime( true ) + $timeout;
        $code     = -1;
        while ( true ) {
            $status = proc_get_status( $proc );
            if ( ! $status['running'] ) {
                $code = (int) $status['exitcode'];
                break;
            }
            if ( microtime( true ) > $deadline ) {
                proc_terminate( $proc, 9 );
                break;
            }
            usleep( 50000 );
        }
        proc_close( $proc );
        $stdout = (string) file_get_contents( $out );
        $stderr = (string) file_get_contents( $err );
        @unlink( $out );
        @unlink( $err );
        return [ $code, $stdout, $stderr ];
    }
}
