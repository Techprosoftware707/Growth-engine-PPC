<?php
/**
 * Admin SPA for the "Link Growth Submitter" role (capabilities.submitOnly): only the owner-task queue in the
 * navigation, no bell / automation pill, no admin-only buttons or requests, and the owner's hand-over card on
 * Settings. Static guards always run; the browser scenarios (SubmitterUiHarness.mjs, Playwright + Chrome) are
 * skipped when node, Playwright or a browser is not available.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class SubmitterUiTest extends TestCase {

    /** @var array<string,mixed>|null */
    private static ?array $results = null;

    private static string $skip = '';

    private const ADMIN_ONLY_BUTTONS = [ 'Approve', 'Reject', 'Retry', 'Rebuild package', 'Save & check claims', 'Save field values' ];

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
        $cmd = escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/SubmitterUiHarness.mjs' ) . ' ' . escapeshellarg( rtrim( PPCLG_DIR, '/' ) ) . ' ' . escapeshellarg( $playwright );
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

    private static function source( string $relative ): string {
        $src = file_get_contents( PPCLG_DIR . $relative );
        self::assertIsString( $src );
        return $src;
    }

    public function testSourcesGateTheAdminOnlyPartsOnTheSubmitterFlag(): void {
        $app = self::source( 'assets/js/app.js' );
        $this->assertStringContainsString( 'const submitOnly = capabilities.submitOnly === true;', $app );
        $this->assertStringContainsString( 'config.submitOnly ? null : pill', $app );
        $this->assertStringContainsString( 'config.submitOnly ? null : bellBtn', $app );
        $this->assertMatchesRegularExpression( '/refreshUnread: async \(\) => \{\s*if \( config\.submitOnly \) \{\s*return;/', $app, 'no notification polling for a submitter' );

        $page = self::source( 'assets/js/pages/submissions.js' );
        $this->assertStringContainsString( 'const submitOnly = Boolean( ctx.config && ctx.config.submitOnly );', $page );
        $this->assertMatchesRegularExpression( '/if \( ! submitOnly \) \{\s*\/\/[^\n]*\n\s*loadBanner\(\);\s*loadVerified\(\);/', $page, 'the storefront banner and verified card load for the owner only' );
        $this->assertStringNotContainsString( 'innerHTML', $page );

        $settings = self::source( 'assets/js/pages/settings.js' );
        $this->assertStringContainsString( 'Hand owner tasks to a staff member or assistant: create a WordPress user with the Link Growth Submitter role. They see only the owner-task queue and can mark listings submitted; they cannot change settings, send email or see your data.', $settings );
        $this->assertStringContainsString( 'submitterRoleCard( ctx.config )', $settings );
    }

    public function testSubmitterSeesOnlyTheOwnerTaskQueue(): void {
        $r = $this->scenario( 'submitter' );
        $this->assertSame( [], $r['errors'] );
        $this->assertSame( [ 'Submissions' ], $r['navLinks'], 'every other tab is hidden, even when the bootstrap lists more pages' );
        $this->assertSame( 1, $r['pageClass'], 'a direct link to another page opens the owner-task queue' );
        $this->assertSame( 0, $r['bell'], 'no notifications bell' );
        $this->assertSame( 0, $r['pill'], 'no automation pill' );
        $this->assertSame( 'Owner tasks', $r['title'] );
        $this->assertCount( 2, $r['tabs'] );
        $this->assertStringStartsWith( 'To do', $r['tabs'][0] );
        $this->assertSame( 'Marked submitted', $r['tabs'][1] );
        $this->assertSame( 0, $r['verifiedCards'] );
        $this->assertSame( 0, $r['bannerHosts'] );
        $this->assertNotContains( 'Method', $r['filterLabels'] );
        $this->assertNotContains( 'Source', $r['filterLabels'] );
        $this->assertNotContains( 'Kind', $r['filterLabels'], 'outreach is the owner\'s, so there is no listing/outreach filter' );
        $this->assertSame( 'needs_manual', $r['listQuery']['status'] );
        $this->assertArrayNotHasKey( 'source', $r['listQuery'] );
        $this->assertSame( [], $r['forbidden'], 'the UI calls only the three submitter routes' );
        $this->assertStringContainsString( 'owner-task queue only', $r['footer'] );
    }

    public function testSubmitterDrawersHaveNoAdminOnlyButtons(): void {
        $r = $this->scenario( 'submitter' );
        foreach ( [ 'ownerButtons', 'policyButtons' ] as $key ) {
            foreach ( self::ADMIN_ONLY_BUTTONS as $label ) {
                $this->assertNotContains( $label, $r[ $key ], $key . ': ' . $label );
            }
            $this->assertContains( 'Mark submitted', $r[ $key ], $key );
            $this->assertContains( 'Open submission page', $r[ $key ], $key );
        }
        $this->assertContains( 'Copy listing kit', $r['ownerButtons'] );
        $this->assertStringContainsString( 'Personal-profile platforms need a real staff member with disclosed affiliation.', $r['ownerText'], 'the listing kit keeps the identity rule' );
        $this->assertStringContainsString( 'leave this task open and tell the site owner', $r['ownerText'] );
        $this->assertStringNotContainsString( 'reject this task', $r['ownerText'] );
        $this->assertSame( [ [ 'listing_url' => 'https://labdirectory.example/listing/ppc', 'note' => '' ] ], $r['ownerMark'] );

        $this->assertSame( 0, $r['policyTextareas'], 'the kit is read-only for a submitter' );
        foreach ( [ 'Business details', 'Listing text', 'Submission steps', 'Paste the details.' ] as $needle ) {
            $this->assertStringContainsString( $needle, $r['policyText'] );
        }
        $this->assertSame( [ [ 'listing_url' => 'https://resources.example/listing/ppc', 'confirmation_ref' => '', 'note' => '' ] ], $r['policyMark'] );
    }

    public function testSubmitterKitShowsFormFieldValuesCategoriesKeywordsAndTheOpportunity(): void {
        $r = $this->scenario( 'submitter' );
        $this->assertContains( 'Form fields', $r['policyCards'] );
        foreach ( [ 'Company name', 'Directory category', 'Select: Laboratory supplies', 'Short blurb', 'Research reagents, cut to the form limit.', 'Category suggestions', 'Peptide suppliers', 'Keywords', 'research peptides, lab reagents' ] as $needle ) {
            $this->assertStringContainsString( $needle, $r['policyText'], $needle );
        }
        $this->assertStringNotContainsString( 'third-party-csrf-token', $r['policyText'], 'hidden form tokens are not shown' );
        $this->assertStringContainsString( 'Peptide research resources', $r['policyText'], 'the opportunity row is filled without an id' );
    }

    public function testSubmitterIsNotInvitedToPitchOutreachTasks(): void {
        $r = $this->scenario( 'submitter' );
        $this->assertStringContainsString( 'leave this for the site owner', $r['outreachText'] );
        $this->assertStringNotContainsString( 'your decision', $r['outreachText'] );
        $this->assertStringNotContainsString( 'if you decide to pitch', $r['outreachText'] );
        $this->assertStringNotContainsString( 'librarian@library.example', $r['outreachText'] );
        $this->assertNotContains( 'Mark submitted', $r['outreachButtons'] );
        $this->assertSame( [], $r['forbidden'] );
    }

    public function testOwnerKeepsTheFullInterface(): void {
        $r = $this->scenario( 'admin' );
        $this->assertSame( [], $r['errors'] );
        $this->assertCount( 9, $r['navLinks'] );
        $this->assertSame( 1, $r['bell'] );
        $this->assertSame( 1, $r['pill'] );
        $this->assertCount( 9, $r['tabs'] );
        foreach ( [ 'Approve', 'Reject', 'Rebuild package', 'Save & check claims', 'Mark submitted' ] as $label ) {
            $this->assertContains( $label, $r['drawerButtons'] );
        }
        $this->assertStringContainsString( 'Kept out of the Link Growth Submitter queue', $r['drawerText'] );
        $this->assertStringContainsString( 'The listing text fails the claims check. The owner must fix the text first.', $r['drawerText'] );
    }

    public function testSettingsShowsTheHandOverCardWithTheAddUserLink(): void {
        $r = $this->scenario( 'settingsCard' );
        $this->assertSame( [], $r['errors'] );
        $this->assertSame( 1, $r['count'] );
        $this->assertStringContainsString( 'They see only the owner-task queue and can mark listings submitted; they cannot change settings, send email or see your data.', $r['text'] );
        $this->assertSame( [ [ 'href' => 'http://ppclg.test/wp-admin/user-new.php', 'text' => 'Add a user', 'target' => null ] ], $r['links'] );
        $this->assertSame( [], $r['mail'], 'nothing is sent or created from the card' );
        $this->assertSame( 0, $r['unsafeCount'], 'a non-http(s) URL is not linked' );
    }
}
