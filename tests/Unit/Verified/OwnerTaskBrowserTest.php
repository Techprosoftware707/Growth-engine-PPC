<?php
/**
 * Submissions page in a real browser (OwnerTaskBrowserHarness.mjs, Playwright + Chrome): the "Owner tasks" tab,
 * the verified-database summary card, the owner-task drawer and the storefront banner. Skipped when node,
 * Playwright or a browser is not available.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Verified;

use PHPUnit\Framework\TestCase;

final class OwnerTaskBrowserTest extends TestCase {

    /** @var array<string,mixed>|null */
    private static ?array $results = null;

    private static string $skip = '';

    /** @return array<string,mixed> */
    private function scenario( string $name ): array {
        if ( null === self::$results && '' === self::$skip ) {
            self::runHarness();
        }
        if ( null === self::$results ) {
            $this->markTestSkipped( self::$skip );
        }
        $this->assertArrayHasKey( $name, self::$results );
        $r = self::$results[ $name ];
        $this->assertArrayNotHasKey( 'failed', $r, (string) ( $r['failed'] ?? '' ) );
        return $r;
    }

    private static function runHarness(): void {
        $node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
        if ( '' === $node ) {
            self::$skip = 'node is not installed';
            return;
        }
        $playwright = (string) getenv( 'PPCLG_PLAYWRIGHT' );
        if ( '' === $playwright && is_dir( '/opt/node-tools/node_modules/playwright' ) ) {
            $playwright = '/opt/node-tools/node_modules/playwright';
        }
        if ( '' === $playwright ) {
            self::$skip = 'Playwright is not installed (set PPCLG_PLAYWRIGHT)';
            return;
        }
        $chrome = (string) getenv( 'PPCLG_CHROME' );
        if ( '' === $chrome && is_executable( '/usr/bin/google-chrome' ) ) {
            $chrome = '/usr/bin/google-chrome';
        }
        $cmd = escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/OwnerTaskBrowserHarness.mjs' ) . ' ' . escapeshellarg( rtrim( PPCLG_DIR, '/' ) ) . ' ' . escapeshellarg( $playwright );
        if ( '' !== $chrome ) {
            $cmd .= ' ' . escapeshellarg( $chrome );
        }
        $out  = (string) shell_exec( 'timeout 240 ' . $cmd . ' 2>/dev/null' );
        $data = json_decode( $out, true );
        if ( ! is_array( $data ) ) {
            self::fail( 'Browser harness failed: ' . substr( $out, 0, 2000 ) );
        }
        if ( isset( $data['skip'] ) ) {
            self::$skip = (string) $data['skip'];
            return;
        }
        self::$results = (array) ( $data['results'] ?? [] );
    }

    public function testOwnerTasksTabListsAndFiltersTheVerifiedTasks(): void {
        $r = $this->scenario( 'ownerTasks' );
        $this->assertSame( [], $r['errors'] );
        $this->assertSame( 'needs_manual', $r['listQuery']['status'] );
        $this->assertSame( 'verified_db', $r['listQuery']['source'] );
        $this->assertSame( '1', $r['tierQuery']['tier'] );
        $this->assertSame( 'verified_db', $r['tierQuery']['source'] );
        $this->assertStringContainsString( 'Tier 1', $r['rowBadges'] );
        $this->assertStringContainsString( 'Authority 100', $r['rowBadges'] );
        $this->assertStringContainsString( 'Conditional', $r['rowBadges'] );
        $this->assertSame( [], $r['toastsOnLoad'], 'a 404 from /compliance/storefront is not reported' );
        $this->assertSame( [ 0 ], $r['bannerChildren'] );
        $this->assertStringContainsString( '556 owner tasks are open', $r['verifiedCard'] );
        $this->assertStringContainsString( 'platform exclusion list', $r['verifiedCard'] );
        $this->assertSame( [ [ 'kind' => 'error', 'text' => 'Another import of the verified opportunity database is running; this one was not started.' ] ], $r['importToasts'] );
    }

    public function testOwnerTaskDrawerShowsTheKitAndMarksSubmittedThroughTheExistingAction(): void {
        $r = $this->scenario( 'ownerTasks' );
        foreach ( [ 'Owner task: Lab Supplier Directory', 'Storefront consistency', 'Listing kit', 'Category rule', 'Verification and terms', 'Done? Mark it submitted' ] as $needle ) {
            $this->assertStringContainsString( $needle, $r['drawerText'] );
        }
        $this->assertStringContainsString( '<img src=x onerror=alert(1)> Use the exact business details', $r['drawerText'], 'steps are shown as text' );
        $this->assertSame( 0, $r['injectedImages'] );
        $this->assertSame( 2, $r['stepBreaks'] );
        $this->assertNotEmpty( $r['openLinks'] );
        foreach ( $r['openLinks'] as $a ) {
            $this->assertSame( 'https://labdirectory.example/add-listing', $a['href'] );
            $this->assertSame( '_blank', $a['target'] );
            $this->assertSame( 'noopener noreferrer', $a['rel'] );
        }
        $this->assertNotContains( 'Approve', $r['drawerButtons'], 'owner tasks are done by hand, not approved for automation' );
        $this->assertNotContains( 'Rebuild package', $r['drawerButtons'], 'a crawl rebuild would replace the verified kit' );
        $this->assertContains( 'Copy listing kit', $r['drawerButtons'] );
        $this->assertContains( 'Reject', $r['drawerButtons'] );
        $this->assertSame( [ [ 'listing_url' => 'https://labdirectory.example/listing/pure-peptides-canada', 'note' => '' ] ], $r['markRequests'] );
        $this->assertSame( [ [ 'kind' => 'success', 'text' => 'Marked as submitted; verification starts on the next run.' ] ], $r['markToasts'] );
    }

    public function testBlockingStorefrontBannerIsRenderedAboveTheList(): void {
        $r = $this->scenario( 'blockingBanner' );
        $this->assertSame( [], $r['errors'] );
        $this->assertStringContainsString( 'your legal decision as the owner', $r['banner'] );
        $this->assertStringContainsString( 'paused', $r['banner'] );
        $this->assertSame( 0, $r['verifiedCards'], 'no summary card while /submissions/verified is not registered' );
        $this->assertSame( [], $r['toasts'] );
    }
}
