<?php
/**
 * The browser E2E suite's manifest library (tests/e2e/lib/manifest.mjs, DESIGN.md §12.5), run under node by
 * ManifestHarness.mjs: request patterns and status classes match as documented, the validator refuses the entries a
 * typo would otherwise turn into a weaker check, and the shipped manifests (tests/e2e/controls) are valid, with one
 * file per dashboard page, a shell manifest, Settings split per tab, and submitter entries. Skipped when node is not
 * installed. The walk itself needs a browser and the LGA test site: `node tests/e2e/run.mjs` (WP3.md).
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\E2E;

use PHPUnit\Framework\TestCase;

final class ManifestTest extends TestCase {

    /** @var array<string,mixed>|null */
    private static ?array $result = null;

    private static string $skip = '';

    /** @return array<string,mixed> */
    private function harness(): array {
        if ( null === self::$result && '' === self::$skip ) {
            $node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
            if ( '' === $node ) {
                self::$skip = 'node is not installed';
            } else {
                $root = rtrim( dirname( __DIR__, 3 ), '/' );
                $out  = (string) shell_exec( 'timeout 60 ' . escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/ManifestHarness.mjs' ) . ' ' . escapeshellarg( $root ) . ' 2>&1' );
                $data = json_decode( $out, true );
                if ( ! is_array( $data ) ) {
                    self::fail( 'Manifest harness failed: ' . substr( $out, 0, 2000 ) );
                }
                self::$result = $data;
            }
        }
        if ( null === self::$result ) {
            $this->markTestSkipped( self::$skip );
        }
        return self::$result;
    }

    public function testRequestPatternsMatchMethodRouteSegmentsAndQuery(): void {
        $m = $this->harness()['match'];
        foreach ( [ 'exact', 'segment', 'star', 'rest', 'query', 'queryAny' ] as $key ) {
            self::assertTrue( $m[ $key ], $key );
        }
        foreach ( [ 'wrongMethod', 'longerRoute', 'missingQuery', 'otherQueryValue' ] as $key ) {
            self::assertFalse( $m[ $key ], $key );
        }
        self::assertNull( $m['malformed'] );
    }

    public function testANegatedQueryKeyMatchesOnlyARequestWithoutThatFilter(): void {
        $m = $this->harness()['match'];
        self::assertTrue( $m['absentOk'], 'no channel at all' );
        self::assertTrue( $m['absentEmpty'], 'an empty channel' );
        self::assertFalse( $m['absentPresent'], 'a reload that still carries channel=rest is not a Reset' );
        self::assertFalse( $m['absentWithQuery'] );
        self::assertSame( [ null, null ], $m['absentMalformed'] );
    }

    public function testStatusClassesAndTheTwoHundredDefault(): void {
        $s = $this->harness()['status'];
        self::assertTrue( $s['class2'] );
        self::assertTrue( $s['class4'] );
        self::assertTrue( $s['exact'] );
        self::assertTrue( $s['default2xx'] );
        self::assertFalse( $s['defaultRefuses4xx'], 'an entry without a status expects 2xx' );
        self::assertFalse( $s['wrongClass'] );
    }

    public function testTheValidatorRefusesEntriesThatWouldWeakenACheck(): void {
        $v = $this->harness()['validate'];
        self::assertSame( [], $v['ok'] );
        $expected = [
            'noExpect'             => 'expect is required',
            'emptyExpect'          => 'expect needs at least one of',
            'badAction'            => 'action must be one of',
            'otherPage'            => 'does not belong to backlinks.json',
            'unknownKey'           => 'unknown key "exect"',
            'fillWithoutInput'     => 'fill needs a string input',
            'badRequest'           => 'is not "METHOD /route"',
            'statusWithoutRequest' => 'expect.status needs expect.request',
            'badRole'              => 'roles must list admin and/or submitter',
            'badMock'              => 'a mock is',
            'badStep'              => 'is not a control id',
            'navOtherPage'         => 'belongs to prospects.json',
            'shellInPage'          => 'control shell.theme does not belong to backlinks.json',
            'navOnItsOwnPage'      => 'is clicked on the page it opens',
            'navOverviewDefault'   => 'is clicked on the page it opens',
        ];
        foreach ( $expected as $key => $needle ) {
            self::assertNotEmpty( $v[ $key ], $key );
            self::assertStringContainsString( $needle, implode( ' | ', $v[ $key ] ), $key );
        }
    }

    public function testAPageDescribesItsOwnNavigationLink(): void {
        $v = $this->harness()['validate'];
        self::assertSame( [], $v['navOwnPage'], 'shell.nav-backlinks in backlinks.json' );
        self::assertSame( [], $v['navInShell'], 'still accepted in shell.json' );
        $m = $this->harness()['shipped'];
        self::assertNotEmpty( $m['spaPages'] );
        foreach ( $m['spaPages'] as $page ) {
            $id = 'shell.nav-' . $page;
            self::assertArrayHasKey( $id, $m['nav'], 'every page of lga-config.pages has a navigation entry' );
            self::assertSame( $page . '.json', $m['nav'][ $id ]['file'], $id . ' is in its page\'s own manifest' );
            self::assertNotSame( $page, $m['nav'][ $id ]['page'], $id . ' is clicked from another page' );
        }
        self::assertSame( 'overview', $m['nav']['shell.nav-prospects']['page'], 'a navigation entry starts on the Overview by default' );
    }

    public function testEveryFilterResetExpectsAReloadWithoutTheFilter(): void {
        $m = $this->harness()['shipped'];
        self::assertGreaterThanOrEqual( 9, $m['resets'] );
        self::assertSame( [], $m['resetsWithoutAbsent'] );
    }

    public function testSeedPlaceholdersAreFilledAndMissingOnesReported(): void {
        $s = $this->harness()['substitute'];
        self::assertSame( [ 'view' => '#open=12', 'n' => 3 ], $s['value'] );
        self::assertSame( [ '{seed.missing}' ], $s['left'] );
    }

    public function testTheShippedManifestsAreValidAndCoverEveryPage(): void {
        $m = $this->harness()['shipped'];
        self::assertSame( [], $m['errors'] );
        foreach ( [ 'shell', 'overview', 'prospects', 'submissions', 'outreach', 'backlinks', 'seo', 'automation', 'settings', 'logs' ] as $page ) {
            self::assertContains( $page . '.json', $m['files'], $page );
        }
        foreach ( [ 'business', 'automation', 'discovery', 'integrations', 'outreach', 'content', 'monitoring', 'advanced' ] as $tab ) {
            self::assertContains( 'settings.' . $tab . '.json', $m['files'], 'Settings tab ' . $tab );
        }
        self::assertGreaterThan( 800, $m['entries'] );
        self::assertGreaterThan( 0, $m['shell'] );
        self::assertGreaterThan( 0, $m['submitter'], 'the permissions pass has submitter entries' );
        self::assertGreaterThan( 0, $m['destructive'] );
        self::assertGreaterThan( 450, $m['withRequest'] );
    }

    public function testEverySettingsFieldIsCheckedThroughTheRequestItsSaveSends(): void {
        $m = $this->harness()['shipped'];
        self::assertGreaterThan( 100, $m['settingsFields'] );
        self::assertSame( [], $m['settingsFieldsWithoutRequest'], 'a Settings field entry expects the Save request with its value in the body' );
    }
}
