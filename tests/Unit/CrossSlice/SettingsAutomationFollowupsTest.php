<?php
/**
 * Cross-slice follow-ups for the settings-automation group: a pending kick no longer hides a lost recurring
 * tick ([5]), the overview names its link-quality count quality_domains ([7]), the tick ceiling cannot be set
 * below the runner's minimum and the automation status reports the effective value ([19]), the outreach
 * autosend wording matches what runs ([27], [28], [50]), the inbound secret URL forms are shown ([32]),
 * linkable assets must pass the claims filter ([38]), the suppression types have one source ([44]),
 * POST /settings cannot switch a gated flag on, or turn "Require individual approval" off while autosend is on,
 * without confirmation ([52]) and a rejected secret comes with its reason ([53]).
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation {

    /*
     * Action Scheduler test double, reached only from Scheduler (the only code in this namespace that calls
     * these functions). It is inert, and defers to the bootstrap stubs, unless a test sets
     * $GLOBALS['ppclg_fake_as'] to an array; it then keeps the actions there and filters them the way Action
     * Scheduler does: an args filter matches only actions whose stored args are exactly equal, and
     * as_unschedule_all_actions() with a hook alone cancels that hook's actions whatever their args.
     */

    function ppclg_fake_as_active(): bool {
        return is_array( $GLOBALS['ppclg_fake_as'] ?? null );
    }

    /** @return array<int,array{hook:string,args:array<mixed>,group:string,recurring:bool,ts:?int}> */
    function ppclg_fake_as_find( mixed $hook, mixed $args, mixed $group ): array {
        $out = [];
        foreach ( $GLOBALS['ppclg_fake_as'] as $id => $a ) {
            if ( $a['hook'] === $hook && ( '' === $group || $a['group'] === $group ) && ( null === $args || $a['args'] === $args ) ) {
                $out[ $id ] = $a;
            }
        }
        return $out;
    }

    if ( ! function_exists( __NAMESPACE__ . '\\did_action' ) ) {
        function did_action( string $hook ): int {
            return ppclg_fake_as_active() ? (int) ( 'init' === $hook ) : \did_action( $hook );
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\as_schedule_recurring_action' ) ) {
        function as_schedule_recurring_action( mixed $ts, mixed $interval, mixed $hook, mixed $args = [], mixed $group = '' ): int {
            if ( ! ppclg_fake_as_active() ) {
                return \as_schedule_recurring_action( $ts, $interval, $hook, $args, $group );
            }
            $GLOBALS['ppclg_fake_as'][] = [ 'hook' => $hook, 'args' => (array) $args, 'group' => (string) $group, 'recurring' => true, 'ts' => (int) $ts ];
            return count( $GLOBALS['ppclg_fake_as'] );
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\as_enqueue_async_action' ) ) {
        function as_enqueue_async_action( mixed $hook, mixed $args = [], mixed $group = '' ): int {
            if ( ! ppclg_fake_as_active() ) {
                return \as_enqueue_async_action( $hook, $args, $group );
            }
            $GLOBALS['ppclg_fake_as'][] = [ 'hook' => $hook, 'args' => (array) $args, 'group' => (string) $group, 'recurring' => false, 'ts' => null ];
            return count( $GLOBALS['ppclg_fake_as'] );
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\as_has_scheduled_action' ) ) {
        function as_has_scheduled_action( mixed $hook, mixed $args = null, mixed $group = '' ): bool {
            if ( ! ppclg_fake_as_active() ) {
                return \as_has_scheduled_action( $hook, $args, $group );
            }
            return [] !== ppclg_fake_as_find( $hook, $args, $group );
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\as_next_scheduled_action' ) ) {
        function as_next_scheduled_action( mixed $hook, mixed $args = null, mixed $group = '' ): int|bool {
            if ( ! ppclg_fake_as_active() ) {
                return \as_next_scheduled_action( $hook, $args, $group );
            }
            $found = ppclg_fake_as_find( $hook, $args, $group );
            if ( [] === $found ) {
                return false;
            }
            $first = reset( $found );
            return null === $first['ts'] ? true : $first['ts'];
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\as_unschedule_all_actions' ) ) {
        function as_unschedule_all_actions( mixed $hook, mixed $args = [], mixed $group = '' ): void {
            if ( ! ppclg_fake_as_active() ) {
                \as_unschedule_all_actions( $hook, $args, $group );
                return;
            }
            $hookOnly = [] === $args && '' === $group;
            foreach ( ppclg_fake_as_find( $hook, $hookOnly ? null : $args, $group ) as $id => $a ) {
                unset( $GLOBALS['ppclg_fake_as'][ $id ] );
            }
        }
    }
}

namespace PPC\LinkGrowth\Tests\Unit\CrossSlice {

    use PPC\LinkGrowth\Admin\Rest\AutomationController;
    use PPC\LinkGrowth\Admin\Rest\LogsController;
    use PPC\LinkGrowth\Admin\Rest\OverviewController;
    use PPC\LinkGrowth\Admin\Rest\SettingsController;
    use PPC\LinkGrowth\Automation\JobRunner;
    use PPC\LinkGrowth\Automation\Pipeline;
    use PPC\LinkGrowth\Automation\Scheduler;
    use PPC\LinkGrowth\Repositories\AuditLog;
    use PPC\LinkGrowth\Repositories\Backlinks;
    use PPC\LinkGrowth\Repositories\Suppression;
    use PPC\LinkGrowth\Support\Secrets;
    use PPC\LinkGrowth\Support\Settings;
    use PPC\LinkGrowth\Tests\Support\Container;
    use PPC\LinkGrowth\Tests\Support\TestCase;
    use WP_Error;
    use WP_REST_Request;

    final class SettingsAutomationFollowupsTest extends TestCase {

        private mixed $previousCaps = null;

        protected function setUp(): void {
            parent::setUp();
            $this->previousCaps            = $GLOBALS['ppclg_test_caps'] ?? null;
            $GLOBALS['ppclg_test_caps']    = [ 'manage_options' => true ];
            $GLOBALS['ppclg_test_user_id'] = 1;
            unset( $GLOBALS['ppclg_fake_as'] );
        }

        protected function tearDown(): void {
            if ( null === $this->previousCaps ) {
                unset( $GLOBALS['ppclg_test_caps'] );
            } else {
                $GLOBALS['ppclg_test_caps'] = $this->previousCaps;
            }
            $GLOBALS['ppclg_test_user_id'] = 0;
            unset( $GLOBALS['ppclg_fake_as'] );
            parent::tearDown();
        }

        // ------------------------------------------------------------------ helpers

        private function c(): \PPC\LinkGrowth\Container {
            return Container::plugin()->container();
        }

        private function settings(): Settings {
            return Container::get( Settings::class );
        }

        /** @param array<string,mixed> $params */
        private function request( string $method, string $route, array $params = [] ): WP_REST_Request {
            $r = new WP_REST_Request( $method, $route );
            foreach ( $params as $k => $v ) {
                $r->set_param( $k, $v );
            }
            return $r;
        }

        /** @param array<string,mixed> $params @return array<string,mixed>|WP_Error */
        private function saveSettings( array $params ): array|WP_Error {
            return ( new SettingsController( $this->c() ) )->update( $this->request( 'POST', '/ppclg/v1/settings', $params ) );
        }

        /** @return array<int,array<string,mixed>> */
        private function fakeActions(): array {
            return array_values( (array) ( $GLOBALS['ppclg_fake_as'] ?? [] ) );
        }

        /** @return array<int,array<string,mixed>> */
        private function recurringTicks(): array {
            return array_values( array_filter( $this->fakeActions(), static fn( array $a ): bool => $a['recurring'] && Scheduler::HOOK === $a['hook'] ) );
        }

        private const CLEAN_ASSET = [ 'url' => 'https://purepeptidescanada.test/hplc-testing-guide/', 'title' => 'HPLC Testing Guide', 'summary' => 'How reverse-phase HPLC purity analysis works and how to read a chromatogram.', 'topics' => [ 'hplc' ], 'kind' => 'guide' ];

        // ------------------------------------------------------------------ [5] kicks vs. the recurring tick

        public function testPendingKickDoesNotHideALostRecurringTick(): void {
            $GLOBALS['ppclg_fake_as'] = [];
            $scheduler                = Container::get( Scheduler::class );

            $scheduler->schedule();
            $this->assertCount( 1, $this->recurringTicks(), 'the recurring tick is registered' );
            $this->assertSame( [], $this->recurringTicks()[0]['args'] );

            $this->assertTrue( $scheduler->kick() );
            $kicks = array_values( array_filter( $this->fakeActions(), static fn( array $a ): bool => ! $a['recurring'] ) );
            $this->assertCount( 1, $kicks );
            $this->assertNotSame( [], $kicks[0]['args'], 'a kick carries its own argument' );
            $this->assertSame( Scheduler::GROUP, $kicks[0]['group'] );

            // The recurring action is lost (e.g. deleted by a cleanup plugin) while the kick is still pending.
            $GLOBALS['ppclg_fake_as'] = array_filter( $GLOBALS['ppclg_fake_as'], static fn( array $a ): bool => ! $a['recurring'] );
            $scheduler->schedule();
            $this->assertCount( 1, $this->recurringTicks(), 'the self-heal re-creates the recurring tick despite the pending kick' );
            $this->assertCount( 2, $this->fakeActions(), 'the kick is still pending' );

            $scheduler->schedule();
            $this->assertCount( 1, $this->recurringTicks(), 'never more than one recurring tick' );

            // Status reports the recurring action, not the kick.
            $this->assertSame( 'action_scheduler', $scheduler->status()['backend'] );

            // unschedule() cancels the kick too, not only the argument-less recurring action.
            $scheduler->unschedule();
            $this->assertSame( [], $this->fakeActions() );
        }

        public function testTickRunsForEveryWayTheHookIsDispatched(): void {
            $scheduler = Container::get( Scheduler::class );
            $now       = time();
            // Recurring tick (no args), an Action Scheduler kick (args passed positionally, as AS does with
            // array_values()), a WP-Cron kick (positional) and a legacy associative kick via call_user_func_array().
            foreach ( [ [], [ $now ], [ (string) $now ], [ 'kick' => (string) $now ] ] as $args ) {
                delete_option( Pipeline::OPTION_LAST_TICK );
                call_user_func_array( [ $scheduler, 'onTick' ], $args );
                $this->assertNotFalse( get_option( Pipeline::OPTION_LAST_TICK, false ), 'tick ran for args ' . wp_json_encode( $args ) );
            }
            // Without Action Scheduler at init the WP-Cron fallback is used, with a positional argument.
            $this->assertTrue( $scheduler->kick() );
        }

        // ------------------------------------------------------------------ [7] overview quality_domains

        public function testOverviewReportsLinkQualityAsQualityDomainsWithDeprecatedAlias(): void {
            $backlinks = $this->c()->get( Backlinks::class );
            $target    = 'https://purepeptidescanada.test/';
            $backlinks->upsert( 'https://a-lab.org/links', $target, [ 'status' => 'active', 'quality_score' => 80 ] );
            $backlinks->upsert( 'https://www.a-lab.org/more', $target, [ 'status' => 'active', 'quality_score' => 70 ] );
            $backlinks->upsert( 'https://b-lab.org/links', $target, [ 'status' => 'active', 'quality_score' => 61 ] );
            $backlinks->upsert( 'https://c-lab.org/links', $target, [ 'status' => 'active', 'quality_score' => 40 ] );
            $backlinks->upsert( 'https://d-lab.org/gone', $target, [ 'status' => 'lost', 'quality_score' => 95 ] );

            $stats = ( new OverviewController( $this->c() ) )->overview( $this->request( 'GET', '/ppclg/v1/overview' ) )['stats'];
            $this->assertSame( 2, $stats['quality_domains'], 'active referring domains with quality score >= 60' );
            $this->assertSame( $stats['quality_domains'], $stats['high_authority_domains'], 'old name kept as an alias for one release' );
        }

        public function testOverviewAndBacklinksPagesReadTheCorrectlyNamedFields(): void {
            $root     = dirname( __DIR__, 3 ) . '/ppc-link-growth-engine/assets/js/pages/';
            $overview = (string) file_get_contents( $root . 'overview.js' );
            $this->assertStringContainsString( 'stats.quality_domains', $overview );
            $this->assertMatchesRegularExpression( '/quality_domains[^\n]*with quality score ≥ 60/u', $overview );
            $backlinks = (string) file_get_contents( $root . 'backlinks.js' );
            $this->assertStringContainsString( 's.quality_domains', $backlinks );
            $this->assertStringContainsString( 's.high_authority_min', $backlinks );
        }

        // ------------------------------------------------------------------ [19] tick ceiling

        public function testMaxExecutionSecondsIsClampedToTheRunnerMinimum(): void {
            $r = $this->saveSettings( [ 'settings' => [ 'automation' => [ 'max_execution_seconds' => 5 ] ] ] );
            $this->assertIsArray( $r );
            $this->assertSame( (int) JobRunner::MIN_TICK_SECONDS, $this->settings()->get( 'automation.max_execution_seconds' ) );

            $this->saveSettings( [ 'settings' => [ 'automation' => [ 'max_execution_seconds' => 500 ] ] ] );
            $this->assertSame( 120, $this->settings()->get( 'automation.max_execution_seconds' ) );

            $this->saveSettings( [ 'settings' => [ 'automation' => [ 'max_execution_seconds' => 22 ] ] ] );
            $this->assertSame( 22, $this->settings()->get( 'automation.max_execution_seconds' ) );

            $js = (string) file_get_contents( dirname( __DIR__, 3 ) . '/ppc-link-growth-engine/assets/js/pages/settings.js' );
            $this->assertStringContainsString( "label: 'Max seconds per tick', min: 15,", $js );
        }

        public function testAutomationStatusReportsTheEffectiveTickCeiling(): void {
            $ctl = new AutomationController( $this->c() );
            // A value stored before the clamp existed (or written directly) is reported as what the runner uses.
            $this->settings()->set( 'automation.max_execution_seconds', 5 );
            $this->assertSame( 15, $ctl->status( $this->request( 'GET', '/ppclg/v1/automation/status' ) )['settings']['max_execution_seconds'] );
            $this->settings()->set( 'automation.max_execution_seconds', 24 );
            $this->assertSame( 24, $ctl->status( $this->request( 'GET', '/ppclg/v1/automation/status' ) )['settings']['max_execution_seconds'] );
        }

        // ------------------------------------------------------------------ [27] [28] [50] autosend wording

        public function testAutosendConfirmationDescribesWhatActuallyRuns(): void {
            // The bootstrap request stub has no has_param(), which toggle() calls.
            $req = new class( 'POST', '/ppclg/v1/automation/toggle' ) extends WP_REST_Request {
                public function has_param( string $key ): bool {
                    return null !== $this->get_param( $key );
                }
            };
            $req->set_param( 'key', 'outreach_autosend' );
            $req->set_param( 'value', true );
            $err = ( new AutomationController( $this->c() ) )->toggle( $req );
            $this->assertInstanceOf( WP_Error::class, $err );
            $this->assertSame( 'ppclg_confirmation_required', $err->get_error_code() );
            $msg = $err->get_error_message();
            $this->assertStringNotContainsString( 'individually approved', $msg );
            $this->assertStringContainsString( 'first-contact emails it drafts for qualified prospects', $msg );
            $this->assertStringContainsString( 'Require individual approval', $msg );
            $this->assertStringContainsString( 'auto-approve follow-ups', $msg );
            $this->assertFalse( (bool) $this->settings()->get( 'automation.outreach_autosend' ) );

            $root       = dirname( __DIR__, 3 ) . '/ppc-link-growth-engine/assets/js/pages/';
            $automation = (string) file_get_contents( $root . 'automation.js' );
            $settings   = (string) file_get_contents( $root . 'settings.js' );
            // The Automation description and both consent texts name the "Require individual approval" condition.
            $this->assertSame( 2, substr_count( $automation, '“Require individual approval” (Settings → Outreach) is off' ), 'description and confirmation' );
            $this->assertStringContainsString( 'are also approved and sent without your review if “Require individual approval” (Settings → Outreach) is off; while it is on, they wait for your approval.', $automation );
            $this->assertStringContainsString( 'are also approved and sent without your review if “Require individual approval” (Settings → Outreach) is off; while it is on, they wait for your approval.', $settings );
            $this->assertStringContainsString( 'Follow-ups in campaigns set to “Auto-approve follow-ups” will be sent', $automation );
            $this->assertStringContainsString( 'auto-drafted first messages also need “Require individual approval” (Outreach tab) turned off', $settings );
            $this->assertStringContainsString( 'While on, automation never approves a message; only follow-ups in campaigns that opted in to auto-approval can send automatically, and only with Outreach autosend on.', $settings );
        }

        // ------------------------------------------------------------------ [32] inbound secret URL forms

        public function testSettingsPageShowsTheInboundSecretUrlForms(): void {
            $js = (string) file_get_contents( dirname( __DIR__, 3 ) . '/ppc-link-growth-engine/assets/js/pages/settings.js' );
            $this->assertStringContainsString( "api.buildUrl( '/inbound', { secret: 'SECRET' } )", $js );
            $this->assertStringContainsString( "u.username = 'ppclg';", $js );
            $this->assertStringContainsString( "u.password = 'SECRET';", $js );
            $this->assertStringContainsString( "'X-PPCLG-Secret: SECRET'", $js );
            // The secret is free text and never sent back to the page, so the hint says how to make it URL-safe.
            $this->assertStringContainsString( 'use a secret of only letters and digits, or percent-encode any other character (such as + & # % @ : / ?)', $js );
        }

        // ------------------------------------------------------------------ [38] linkable assets and the claims filter

        public function testUncleanLinkableAssetRejectsTheWholeSave(): void {
            $before = $this->settings()->get( 'automation.tick_interval' );
            $r      = $this->saveSettings(
                [
                    'settings' => [
                        'automation' => [ 'tick_interval' => 900 ],
                        'content'    => [
                            'linkable_assets' => [
                                self::CLEAN_ASSET,
                                [ 'url' => 'https://purepeptidescanada.test/recovery/', 'title' => 'Peptides That Cure Injuries', 'summary' => 'The best supplier in Canada.', 'topics' => 'recovery', 'kind' => 'guide' ],
                            ],
                        ],
                    ],
                    'secrets'  => [ 'brave_api_key' => 'brave-should-not-be-stored' ],
                ]
            );
            $this->assertInstanceOf( WP_Error::class, $r );
            $this->assertSame( 'ppclg_asset_claims', $r->get_error_code() );
            $data = $r->get_error_data();
            $this->assertSame( 422, $data['status'] );
            $this->assertCount( 1, $data['assets'], 'only the unclean asset is listed' );
            $this->assertSame( 1, $data['assets'][0]['index'] );
            $this->assertSame( 'Peptides That Cure Injuries', $data['assets'][0]['title'] );
            $problems = implode( ' | ', $data['assets'][0]['problems'] );
            $this->assertStringContainsString( 'title contains “Cure”', $problems );
            $this->assertStringContainsString( 'summary contains “The best”', $problems );

            $msg = $r->get_error_message();
            $this->assertStringContainsString( 'Nothing was saved', $msg );
            $this->assertStringContainsString( '“Peptides That Cure Injuries”', $msg );
            $this->assertStringNotContainsString( 'HPLC Testing Guide', $msg );

            $this->assertSame( $before, $this->settings()->get( 'automation.tick_interval' ), 'other settings in the request are not saved' );
            $this->assertSame( [], (array) $this->settings()->get( 'content.linkable_assets', [] ) );
            $this->assertFalse( Container::get( Secrets::class )->has( 'brave_api_key' ), 'secrets in the request are not saved' );
        }

        public function testCleanLinkableAssetsAreSaved(): void {
            $r = $this->saveSettings( [ 'settings' => [ 'content' => [ 'linkable_assets' => [ self::CLEAN_ASSET ] ] ] ] );
            $this->assertIsArray( $r );
            $this->assertTrue( $r['ok'] );
            $this->assertContains( 'content.linkable_assets', $r['changed'] );
            $saved = (array) $this->settings()->get( 'content.linkable_assets' );
            $this->assertCount( 1, $saved );
            $this->assertSame( 'HPLC Testing Guide', $saved[0]['title'] );
        }

        public function testNegatedComplianceStatementInAnAssetIsAccepted(): void {
            $asset = [ 'url' => 'https://purepeptidescanada.test/about/', 'title' => 'About Us', 'summary' => 'Research reagents, not for human use and not a supplement.', 'topics' => [ 'about' ], 'kind' => 'info' ];
            $r     = $this->saveSettings( [ 'settings' => [ 'content' => [ 'linkable_assets' => [ $asset ] ] ] ] );
            $this->assertIsArray( $r, $r instanceof WP_Error ? $r->get_error_message() : '' );
        }

        // ------------------------------------------------------------------ [44] one list of suppression types

        public function testLogsControllerUsesTheRepositorySuppressionTypes(): void {
            $types = ( new \ReflectionClass( LogsController::class ) )->getConstant( 'SUPPRESSION_TYPES' );
            $this->assertSame( Suppression::TYPES, $types );
        }

        // ------------------------------------------------------------------ [52] gated flags through POST /settings

        public function testGatedFlagCannotBeSwitchedOnThroughSettingsWithoutConfirmation(): void {
            $this->settings()->set( 'automation.submissions', false );
            $this->settings()->set( 'automation.outreach_autosend', false );
            $before = $this->settings()->get( 'automation.tick_interval' );

            $r = $this->saveSettings( [ 'settings' => [ 'automation' => [ 'submissions' => true, 'outreach_autosend' => 'true', 'tick_interval' => 900 ] ] ] );
            $this->assertInstanceOf( WP_Error::class, $r );
            $this->assertSame( 'ppclg_confirmation_required', $r->get_error_code() );
            $data = $r->get_error_data();
            $this->assertSame( 400, $data['status'] );
            $this->assertTrue( $data['confirm_required'] );
            $this->assertSame( [ 'submissions', 'outreach_autosend' ], $data['keys'] );
            $this->assertStringContainsString( AutomationController::confirmationMessage( 'submissions' ), $r->get_error_message() );
            $this->assertStringContainsString( AutomationController::confirmationMessage( 'outreach_autosend' ), $r->get_error_message() );
            $this->assertFalse( (bool) $this->settings()->get( 'automation.submissions' ) );
            $this->assertFalse( (bool) $this->settings()->get( 'automation.outreach_autosend' ) );
            $this->assertSame( $before, $this->settings()->get( 'automation.tick_interval' ), 'nothing else in the request is saved' );

            $r = $this->saveSettings( [ 'settings' => [ 'automation' => [ 'outreach_autosend' => true ] ], 'confirm' => true ] );
            $this->assertIsArray( $r );
            $this->assertTrue( (bool) $this->settings()->get( 'automation.outreach_autosend' ) );
            $this->assertFalse( (bool) $this->settings()->get( 'automation.submissions' ) );
            $audit = $this->c()->get( AuditLog::class )->findBy( [ 'action' => 'settings.updated' ], 'id', 'DESC', 1 );
            $this->assertCount( 1, $audit );
            $this->assertSame( [ 'outreach_autosend' ], $audit[0]['details']['confirmed'], 'the confirmed switch-on is audited' );
        }

        public function testGatedFlagGateOnlyAppliesWhenSwitchingOn(): void {
            $this->settings()->set( 'automation.submissions', true );
            $this->settings()->set( 'automation.outreach_autosend', true );
            // Re-sending a flag that is already on, or switching one off, needs no confirmation.
            $r = $this->saveSettings( [ 'settings' => [ 'automation' => [ 'submissions' => true, 'outreach_autosend' => false ] ] ] );
            $this->assertIsArray( $r );
            $this->assertTrue( (bool) $this->settings()->get( 'automation.submissions' ) );
            $this->assertFalse( (bool) $this->settings()->get( 'automation.outreach_autosend' ) );
            // Ungated flags never need it.
            $r = $this->saveSettings( [ 'settings' => [ 'automation' => [ 'monitoring' => true, 'enabled' => true ] ] ] );
            $this->assertIsArray( $r );
            $this->assertSame( AutomationController::CONFIRM_FLAGS, [ 'submissions', 'outreach_autosend' ] );
        }

        public function testTurningApprovalOffWhileAutosendIsOnNeedsConfirmation(): void {
            $this->settings()->set( 'automation.outreach_autosend', true );
            $this->settings()->set( 'outreach.require_approval', true );
            $before = $this->settings()->get( 'outreach.follow_up_days' );

            $r = $this->saveSettings( [ 'settings' => [ 'outreach' => [ 'require_approval' => false, 'follow_up_days' => 12 ] ] ] );
            $this->assertInstanceOf( WP_Error::class, $r );
            $this->assertSame( 'ppclg_confirmation_required', $r->get_error_code() );
            $data = $r->get_error_data();
            $this->assertSame( 400, $data['status'] );
            $this->assertTrue( $data['confirm_required'] );
            $this->assertSame( [ 'require_approval' ], $data['keys'] );
            $this->assertSame( AutomationController::confirmationMessage( 'require_approval' ), $r->get_error_message() );
            $this->assertStringContainsString( 'first-contact emails it drafts for qualified prospects', $r->get_error_message() );
            $this->assertTrue( (bool) $this->settings()->get( 'outreach.require_approval' ), 'nothing is saved without confirmation' );
            $this->assertSame( $before, $this->settings()->get( 'outreach.follow_up_days' ) );

            $r = $this->saveSettings( [ 'settings' => [ 'outreach' => [ 'require_approval' => 'false' ] ], 'confirm' => true ] );
            $this->assertIsArray( $r );
            $this->assertFalse( (bool) $this->settings()->get( 'outreach.require_approval' ) );
            $audit = $this->c()->get( AuditLog::class )->findBy( [ 'action' => 'settings.updated' ], 'id', 'DESC', 1 );
            $this->assertSame( [ 'require_approval' ], $audit[0]['details']['confirmed'] );

            // Already off: re-sending it needs no confirmation.
            $this->assertIsArray( $this->saveSettings( [ 'settings' => [ 'outreach' => [ 'require_approval' => false ] ] ] ) );
        }

        public function testApprovalGateFollowsTheAutosendStateAfterTheSave(): void {
            // Autosend off: turning approval off is not gated (switching autosend on later asks for it).
            $this->settings()->set( 'automation.outreach_autosend', false );
            $this->settings()->set( 'outreach.require_approval', true );
            $this->assertIsArray( $this->saveSettings( [ 'settings' => [ 'outreach' => [ 'require_approval' => false ] ] ] ) );
            $this->assertFalse( (bool) $this->settings()->get( 'outreach.require_approval' ) );

            // Autosend switched off in the same request: not gated.
            $this->settings()->set( 'automation.outreach_autosend', true );
            $this->settings()->set( 'outreach.require_approval', true );
            $r = $this->saveSettings( [ 'settings' => [ 'automation' => [ 'outreach_autosend' => false ], 'outreach' => [ 'require_approval' => false ] ] ] );
            $this->assertIsArray( $r );
            $this->assertFalse( (bool) $this->settings()->get( 'automation.outreach_autosend' ) );
            $this->assertFalse( (bool) $this->settings()->get( 'outreach.require_approval' ) );

            // Autosend switched on in the same request: one confirmation (the autosend one, which names this case).
            $this->settings()->set( 'outreach.require_approval', true );
            $r = $this->saveSettings( [ 'settings' => [ 'automation' => [ 'outreach_autosend' => true ], 'outreach' => [ 'require_approval' => false ] ] ] );
            $this->assertInstanceOf( WP_Error::class, $r );
            $this->assertSame( [ 'outreach_autosend' ], $r->get_error_data()['keys'] );
            $this->assertStringContainsString( 'Require individual approval', $r->get_error_message() );
            $this->assertFalse( (bool) $this->settings()->get( 'automation.outreach_autosend' ) );
            $this->assertTrue( (bool) $this->settings()->get( 'outreach.require_approval' ) );

            // Turning approval back on is never gated.
            $this->settings()->set( 'automation.outreach_autosend', true );
            $this->settings()->set( 'outreach.require_approval', false );
            $this->assertIsArray( $this->saveSettings( [ 'settings' => [ 'outreach' => [ 'require_approval' => true ] ] ] ) );
            $this->assertTrue( (bool) $this->settings()->get( 'outreach.require_approval' ) );
        }

        public function testSettingsPageAsksBeforeTurningApprovalOffWithAutosendOn(): void {
            $js = (string) file_get_contents( dirname( __DIR__, 3 ) . '/ppc-link-growth-engine/assets/js/pages/settings.js' );
            $this->assertStringContainsString( "obj( changed.outreach ).require_approval === false && getPath( state.original, 'automation.outreach_autosend', false )", $js );
            $this->assertStringContainsString( 'await confirm( APPROVAL_OFF_GATE.text,', $js );
            $this->assertStringContainsString( 'body.confirm = true;', $js );
            $this->assertStringContainsString( "setPath( changed, 'automation.outreach_autosend', false );", $js, 'autosend switched off in the same save goes with the settings' );
            $this->assertStringContainsString( 'will be approved and sent to third parties in your name without your review', $js );
        }

        // ------------------------------------------------------------------ [53] reason per rejected secret

        public function testRejectedSecretsComeWithTheirReason(): void {
            $r = $this->saveSettings(
                [
                    'secrets' => [
                        'not_a_secret'                => 'x',
                        'brave_api_key'               => str_repeat( 'a', 20001 ),
                        'google_service_account_json' => '{"type":"service_account"}',
                        'serper_api_key'              => 'serper-0123456789',
                    ],
                ]
            );
            $this->assertIsArray( $r );
            $this->assertSame( [ 'not_a_secret', 'brave_api_key', 'google_service_account_json' ], $r['secrets']['rejected'] );
            $this->assertSame(
                [ 'not_a_secret' => 'invalid_key', 'brave_api_key' => 'too_long', 'google_service_account_json' => 'invalid_service_account_json' ],
                $r['secrets']['reasons']
            );
            $this->assertSame( [ 'serper_api_key' ], $r['secrets']['updated'] );

            $empty = $this->saveSettings( [ 'settings' => [ 'automation' => [ 'tick_interval' => 600 ] ] ] );
            $this->assertIsArray( $empty );
            $this->assertSame( [], $empty['secrets']['reasons'] );

            $js = (string) file_get_contents( dirname( __DIR__, 3 ) . '/ppc-link-growth-engine/assets/js/pages/settings.js' );
            foreach ( [ 'invalid_key', 'too_long', 'invalid_service_account_json' ] as $reason ) {
                $this->assertStringContainsString( "    {$reason}: '", $js, "settings.js explains {$reason}" );
            }
        }
    }
}
