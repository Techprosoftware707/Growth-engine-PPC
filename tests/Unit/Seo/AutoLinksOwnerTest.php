<?php
/**
 * 1.2.2 — automatic links on items an administrator wrote with inline SVG and JSON-LD (the 8 live refusals):
 * background saves run without a user, so kses would strip that markup and the link was refused. Now the save is
 * made as the owner who gave the standing consent, for that one save, only while that user exists, may edit the
 * item and has unfiltered_html; the previous user comes back in a finally block, the audit row keeps the automation
 * as the actor with saved_as, and nothing but the plugin's <a href> is added. Otherwise the refusal stays. Refusals
 * stored by 1.2.1 are tried again once an owner qualifies.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Seo;

require_once dirname( __DIR__, 2 ) . '/Support/StubsSeo.php';

use PPC\LinkGrowth\Admin\Rest\SettingsController;
use PPC\LinkGrowth\Automation\Audit;
use PPC\LinkGrowth\Automation\Autopilot;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Seo\InternalLinker;
use PPC\LinkGrowth\Support\Locks;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\StubsSeo;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_REST_Request;

final class AutoLinksOwnerTest extends TestCase {

    private const WATER_URL = 'https://purepeptidescanada.test/product/bacteriostatic-water/';

    /** Like the live long-form articles: a Custom HTML layout with an inline SVG diagram and a FAQPage JSON-LD script. */
    private const ARTICLE = '<div class="ppl"><p>Lipidation and PEGylation extend the half-life of a peptide in the study model by binding albumin.</p>'
        . "\n<figure><svg viewBox=\"0 0 10 10\" role=\"img\" aria-labelledby=\"t1\"><title id=\"t1\">Half-life</title><text x=\"1\" y=\"5\">Albumin</text></svg></figure>"
        . "\n<p>A second paragraph explains how bacteriostatic water is used for reconstitution in the lab protocol today.</p>"
        . "\n<script type=\"application/ld+json\">{\"@context\":\"https://schema.org\",\"@type\":\"FAQPage\",\"mainEntity\":[]}</script></div>";

    protected function setUp(): void {
        parent::setUp();
        StubsSeo::reset();
        $GLOBALS['ppclg_test_caps']      = [];
        $GLOBALS['ppclg_test_user_id']   = 0;
        $GLOBALS['ppclg_test_user_caps'] = [ 1 => [ 'edit_post' => true, 'unfiltered_html' => true, 'manage_options' => true ] ];
        unset( $GLOBALS['ppclg_seo_update_fails'], $GLOBALS['ppclg_seo_update_throws'] );
        StubsSeo::addPost( 100, [ 'post_type' => 'product', 'post_title' => 'Bacteriostatic Water' ] );
        StubsSeo::addPost( 9731, [ 'post_type' => 'post', 'post_title' => 'How Peptides Are Made to Last', 'post_name' => 'half-life', 'post_content' => self::ARTICLE ] );
        $this->settings()->update( [ 'automation' => [ 'autopilot' => true ] ] );
        $this->consent( 1 );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['ppclg_test_caps'], $GLOBALS['ppclg_test_user_caps'], $GLOBALS['ppclg_seo_update_throws'] );
        $GLOBALS['ppclg_test_user_id'] = 0;
        parent::tearDown();
    }

    private function settings(): Settings {
        return Container::get( Settings::class );
    }

    private function consent( ?int $userId ): void {
        if ( null === $userId ) {
            delete_option( Autopilot::OPTION_CONSENT );
            return;
        }
        update_option( Autopilot::OPTION_CONSENT, [ 'user_id' => $userId, 'at' => '2026-11-15T20:00:00+00:00', 'policy_version' => Autopilot::POLICY_VERSION ] );
    }

    private function linker(): InternalLinker {
        $linker = new InternalLinker( $this->settings(), Container::get( Logger::class ), Container::get( Locks::class ), Container::get( Audit::class ) );
        Container::plugin()->container()->set( InternalLinker::class, $linker );
        $linker->run( TimeBudget::seconds( 30 ) );
        return $linker;
    }

    /** @return array<string,mixed> */
    private function item( InternalLinker $linker ): array {
        $all = array_values( array_filter( $linker->suggestions( '' ), static fn( array $s ): bool => 9731 === (int) $s['source_post_id'] ) );
        $this->assertCount( 1, $all );
        return $all[0];
    }

    public function testAnItemKsesWouldRewriteIsSavedUnchangedAsTheConsentingOwner(): void {
        $this->assertNotSame( self::ARTICLE, wp_kses_post( self::ARTICLE ), 'precondition: kses strips the SVG and the script tags' );
        $linker = $this->linker();
        $res    = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 1, 0 ], [ $res['applied'], $res['refused'] ], $res['message'] );

        $now = (string) StubsSeo::$posts[9731]['post_content'];
        $this->assertSame( self::ARTICLE, str_replace( '<a href="' . self::WATER_URL . '">bacteriostatic water</a>', 'bacteriostatic water', $now ), 'only the link was added' );
        $this->assertStringContainsString( '<svg viewBox="0 0 10 10" role="img"', $now );
        $this->assertStringContainsString( '<script type="application/ld+json">', $now );
        $last = end( StubsSeo::$updates );
        $this->assertSame( 1, $last['_user'], 'saved as the owner, so kses did not run' );
        $this->assertSame( [ 1, 0 ], StubsSeo::$userSwitches, 'switched for the one save and back' );
        $this->assertSame( 0, get_current_user_id() );
        $this->assertSame( [], $GLOBALS['ppclg_test_caps'], 'the background user has no capabilities again' );

        $item = $this->item( $linker );
        $this->assertSame( [ 'applied', true, 1, true ], [ $item['status'], $item['auto'], $item['saved_as'], $item['byte_identical'] ] );
        $audit = Container::get( AuditLog::class )->findBy( [ 'action' => 'seo.internal_link_applied' ] );
        $this->assertSame( Autopilot::ACTOR, $audit[0]['actor'], 'the automation stays the actor' );
        $this->assertSame( 1, $audit[0]['details']['saved_as'], 'on behalf of the consenting owner' );

        // Taking it back out (the page stops showing its content) is saved as the owner too, byte for byte.
        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', 9731 );
        StubsSeo::$render[9731] = 'hidden';
        $off = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 1, $off['removed'], $off['message'] );
        $this->assertSame( self::ARTICLE, StubsSeo::$posts[9731]['post_content'] );
        $this->assertSame( 1, end( StubsSeo::$updates )['_user'] );
        $this->assertSame( 0, get_current_user_id() );
        $removed = Container::get( AuditLog::class )->findBy( [ 'action' => 'seo.internal_link_auto_removed' ] );
        $this->assertSame( 1, $removed[0]['details']['saved_as'] );
    }

    /** @return array<string,array{0:\Closure}> */
    public static function ineligibleOwners(): array {
        return [
            'no consent recorded'         => [ static function (): void {
                delete_option( Autopilot::OPTION_CONSENT );
            } ],
            'the consent user is deleted' => [ static function (): void {
                StubsSeo::$users = [ 2 ];
            } ],
            'no unfiltered_html'          => [ static function (): void {
                $GLOBALS['ppclg_test_user_caps'][1] = [ 'edit_post' => true ]; // e.g. DISALLOW_UNFILTERED_HTML, or an editor
            } ],
            'may not edit the item'       => [ static function (): void {
                $GLOBALS['ppclg_test_user_caps'][1] = [ 'unfiltered_html' => true ];
            } ],
        ];
    }

    /** @dataProvider ineligibleOwners */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'ineligibleOwners' )]
    public function testWithoutAnEligibleOwnerTheRefusalStays( \Closure $make ): void {
        $make();
        $linker = $this->linker();
        $res    = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 0, 1 ], [ $res['applied'], $res['refused'] ], $res['message'] );
        $this->assertStringContainsString( '1 left for you to review', $res['message'] );
        $item = $this->item( $linker );
        $this->assertSame( 'pending', $item['status'] );
        $this->assertStringStartsWith( InternalLinker::KSES_REFUSAL, (string) $item['auto_skip'] );
        $this->assertSame( 'kses', $item['auto_skip_code'] );
        $this->assertSame( self::ARTICLE, StubsSeo::$posts[9731]['post_content'] );
        $this->assertSame( [], StubsSeo::$updates );
        $this->assertSame( [], StubsSeo::$userSwitches, 'nobody was impersonated' );
        $this->assertSame( 1, $linker->counts()['refused'] );
        $this->assertSame( 1, $linker->autoInfo()['left_for_you'] );
    }

    public function testThePreviousUserComesBackWhenTheSaveThrows(): void {
        $GLOBALS['ppclg_seo_update_throws'] = true;
        $linker = $this->linker();
        $res    = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 0, $res['applied'] );
        $this->assertSame( [ 1, 0 ], StubsSeo::$userSwitches );
        $this->assertSame( 0, get_current_user_id() );
        $this->assertSame( [], $GLOBALS['ppclg_test_caps'] );
        $this->assertStringContainsString( 'unexpected error', (string) $this->item( $linker )['auto_skip'] );
    }

    public function testRefusalsStoredBy121AreTriedAgainOnceAnOwnerQualifies(): void {
        $this->consent( null );
        $linker = $this->linker();
        $this->assertSame( 0, $linker->autoApply( TimeBudget::seconds( 30 ) )['applied'] );
        // As 1.2.1 stored it: the message only, no code.
        $store = get_option( InternalLinker::OPTION );
        foreach ( $store['items'] as &$i ) {
            $i['auto_skip'] = 'Not applied automatically: WordPress would re-filter other markup in this item when saving it. Apply it by hand from an administrator account, or add the link in the editor.';
            unset( $i['auto_skip_code'] );
        }
        unset( $i );
        update_option( InternalLinker::OPTION, $store );
        $this->assertSame( [ 0, 0 ], [ $linker->autoApply( TimeBudget::seconds( 30 ) )['applied'], count( StubsSeo::$updates ) ], 'still no owner: not attempted' );

        // Autopilot switched on again by an administrator (consent recorded): the old refusal is tried again.
        $this->consent( 1 );
        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 1, $res['applied'], $res['message'] );
        $item = $this->item( $linker );
        $this->assertArrayNotHasKey( 'auto_skip', $item );
        $this->assertNotEmpty( $item['auto_skip_cleared_at'] );
        // Other refusals (not kses) are never cleared this way.
        $this->assertSame( 0, $linker->counts()['refused'] );
    }

    public function testWithoutAutopilotTheUserWhoSwitchedAutomaticLinksOnIsTheOwner(): void {
        $this->settings()->set( 'automation.autopilot', false );
        $this->consent( null );
        $GLOBALS['ppclg_test_caps']      = [ 'manage_options' => true ];
        $GLOBALS['ppclg_test_user_id']   = 7;
        $GLOBALS['ppclg_test_user_caps'] = [ 7 => [ 'edit_post' => true, 'unfiltered_html' => true ] ];
        $req = new WP_REST_Request( 'POST', '/ppclg/v1/settings' );
        $req->set_param( 'settings', [ 'seo' => [ 'auto_apply_links' => true ] ] );
        $req->set_param( 'confirm', true );
        $saved = ( new SettingsController( Container::plugin()->container() ) )->update( $req );
        $this->assertIsArray( $saved );
        $this->assertSame( 7, get_option( InternalLinker::OPTION_LINKS_CONSENT )['user_id'] );

        $GLOBALS['ppclg_test_caps']    = [];
        $GLOBALS['ppclg_test_user_id'] = 0;
        $linker = $this->linker();
        $this->assertSame( 7, $linker->autoOwner( 9731 ) );
        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 1, $res['applied'], $res['message'] );
        $this->assertSame( 7, end( StubsSeo::$updates )['_user'] );
        $this->assertSame( 'system', Container::get( AuditLog::class )->findBy( [ 'action' => 'seo.internal_link_applied' ] )[0]['actor'] );

        // Switched off: nobody's consent applies any more.
        $this->settings()->set( 'seo.auto_apply_links', false );
        $this->assertSame( 0, $linker->autoOwner( 9731 ) );
    }

    public function testSwitchingAutomaticLinksOffRevokesTheConsentAndWhoeverSwitchesThemOnIsRecorded(): void {
        $this->settings()->set( 'automation.autopilot', false );
        $this->consent( null );
        $admin                           = [ 'edit_post' => true, 'unfiltered_html' => true, 'manage_options' => true ];
        $GLOBALS['ppclg_test_user_caps'] = [ 1 => $admin, 2 => $admin ];
        $save = function ( int $user, array $settings, bool $confirm = false ): void {
            $GLOBALS['ppclg_test_user_id'] = $user;
            $GLOBALS['ppclg_test_caps']    = $GLOBALS['ppclg_test_user_caps'][ $user ];
            $req = new WP_REST_Request( 'POST', '/ppclg/v1/settings' );
            $req->set_param( 'settings', $settings );
            if ( $confirm ) {
                $req->set_param( 'confirm', true );
            }
            $res = ( new SettingsController( Container::plugin()->container() ) )->update( $req );
            $this->assertIsArray( $res, (string) wp_json_encode( $res ) );
        };
        $consent = static fn(): int => (int) ( ( (array) get_option( InternalLinker::OPTION_LINKS_CONSENT, [] ) )['user_id'] ?? 0 );

        // User 2 switches automatic links on (with the confirmation), then off again.
        $save( 2, [ 'seo' => [ 'auto_apply_links' => true ] ], true );
        $this->assertSame( 2, $consent() );
        $save( 2, [ 'seo' => [ 'auto_apply_links' => false ] ] );
        $this->assertFalse( get_option( InternalLinker::OPTION_LINKS_CONSENT, false ), 'switching them off revokes the consent' );

        // User 1 switches Autopilot on, then automatic links (confirming their text, also while Autopilot is on), then
        // Autopilot off: automatic links keep running on seo.auto_apply_links, which user 1 switched on.
        $save( 1, [ 'automation' => [ 'autopilot' => true ] ], true );
        $save( 1, [ 'seo' => [ 'auto_apply_links' => true ] ], true );
        $this->assertSame( 1, $consent(), 'recorded for the user who confirmed them, also under Autopilot' );
        $save( 1, [ 'automation' => [ 'autopilot' => false ] ] );

        $GLOBALS['ppclg_test_user_id'] = 0; // the cron
        $GLOBALS['ppclg_test_caps']    = [];
        $linker = $this->linker();
        $this->assertTrue( $linker->autoApplyEnabled() );
        $this->assertSame( 1, $linker->autoOwner( 9731 ), 'never the user who switched them off' );
        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 1, $res['applied'], $res['message'] );
        $this->assertSame( 1, end( StubsSeo::$updates )['_user'] );
        // A consenting user who no longer exists is simply not used.
        StubsSeo::$users = [ 2 ];
        $this->assertSame( 0, $linker->autoOwner( 9731 ) );
    }

    public function testUnderAutopilotSwitchingAutomaticLinksOnStillNeedsTheConfirmationBeforeAnyoneIsRecorded(): void {
        // The live state: Autopilot on, consent by user 1 under policy 1.2.0-2 (which says nothing about saving as
        // the owner's account). Users 1 and 2 are administrators.
        update_option( Autopilot::OPTION_CONSENT, [ 'user_id' => 1, 'at' => '2026-11-15T20:00:00+00:00', 'policy_version' => '1.2.0-2' ] );
        $admin                           = [ 'edit_post' => true, 'unfiltered_html' => true, 'manage_options' => true ];
        $GLOBALS['ppclg_test_user_caps'] = [ 1 => $admin, 2 => $admin ];
        $save = function ( int $user, array $settings, bool $confirm = false ) {
            $GLOBALS['ppclg_test_user_id'] = $user;
            $GLOBALS['ppclg_test_caps']    = $GLOBALS['ppclg_test_user_caps'][ $user ];
            $req = new WP_REST_Request( 'POST', '/ppclg/v1/settings' );
            $req->set_param( 'settings', $settings );
            if ( $confirm ) {
                $req->set_param( 'confirm', true );
            }
            return ( new SettingsController( Container::plugin()->container() ) )->update( $req );
        };

        // Flipping "Add the best internal links automatically" on without the confirmation is refused, also while
        // Autopilot is on, and nobody is recorded.
        foreach ( [ 1, 2 ] as $user ) {
            $no = $save( $user, [ 'seo' => [ 'auto_apply_links' => true ] ] );
            $this->assertInstanceOf( \WP_Error::class, $no, 'user ' . $user );
            $this->assertSame( 'ppclg_confirmation_required', $no->get_error_code() );
            $this->assertSame( [ 'auto_apply_links' ], $no->get_error_data()['keys'] );
            $this->assertStringContainsString( 'saves that one change as your account', $no->get_error_message() );
            $this->assertFalse( (bool) $this->settings()->get( 'seo.auto_apply_links', false ) );
            $this->assertFalse( get_option( InternalLinker::OPTION_LINKS_CONSENT, false ) );
        }
        // Autopilot goes off: automatic links stop, and nobody's account is used.
        $this->assertIsArray( $save( 1, [ 'automation' => [ 'autopilot' => false ] ] ) );
        $GLOBALS['ppclg_test_user_id'] = 0; // the cron
        $GLOBALS['ppclg_test_caps']    = [];
        $linker = $this->linker();
        $this->assertFalse( $linker->autoApplyEnabled() );
        $this->assertSame( 0, $linker->autoOwner( 9731 ) );
        $this->assertFalse( $linker->autoApply( TimeBudget::seconds( 30 ) )['ran'] );
        $this->assertSame( self::ARTICLE, StubsSeo::$posts[9731]['post_content'] );
        $this->assertSame( [], StubsSeo::$userSwitches, 'nobody was impersonated' );

        // A second administrator who confirms the text while another's Autopilot is on is recorded, and is the one
        // the save is made as once Autopilot is off.
        $this->assertIsArray( $save( 1, [ 'automation' => [ 'autopilot' => true ] ], true ) );
        $this->assertIsArray( $save( 2, [ 'seo' => [ 'auto_apply_links' => true ] ], true ) );
        $this->assertSame( 2, get_option( InternalLinker::OPTION_LINKS_CONSENT )['user_id'] );
        $this->assertIsArray( $save( 1, [ 'automation' => [ 'autopilot' => false ] ] ) );
        $GLOBALS['ppclg_test_user_id'] = 0;
        $GLOBALS['ppclg_test_caps']    = [];
        $this->assertSame( 2, $linker->autoOwner( 9731 ) );
        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 1, $res['applied'], $res['message'] );
        $this->assertSame( 2, end( StubsSeo::$updates )['_user'] );

        // Switching Autopilot and automatic links on in one request needs both texts confirmed.
        $this->assertIsArray( $save( 2, [ 'seo' => [ 'auto_apply_links' => false ] ] ) );
        $both = $save( 1, [ 'automation' => [ 'autopilot' => true ], 'seo' => [ 'auto_apply_links' => true ] ] );
        $this->assertInstanceOf( \WP_Error::class, $both );
        $this->assertSame( [ 'autopilot', 'auto_apply_links' ], $both->get_error_data()['keys'] );
        $this->assertFalse( get_option( InternalLinker::OPTION_LINKS_CONSENT, false ) );
    }

    public function testAnAutopilotConsentFromAnEarlierPolicyDoesNotCoverSavingAsTheOwner(): void {
        // The live consent was given under policy 1.2.0-2, before Autopilot could save as the owner's account.
        update_option( Autopilot::OPTION_CONSENT, [ 'user_id' => 1, 'at' => '2026-11-15T20:00:00+00:00', 'policy_version' => '1.2.0-2' ] );
        StubsSeo::addPost( 9732, [ 'post_type' => 'post', 'post_title' => 'A plain article', 'post_name' => 'plain', 'post_content' => '<p>A plain paragraph explains how bacteriostatic water is used for reconstitution in the lab protocol today.</p>' ] );
        $linker = $this->linker();
        $this->assertSame( 0, $linker->autoOwner( 9731 ) );
        $res = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( [ 1, 1 ], [ $res['applied'], $res['refused'] ], 'the rest of Autopilot keeps working: ' . $res['message'] );
        $this->assertStringContainsString( 'bacteriostatic water</a>', (string) StubsSeo::$posts[9732]['post_content'] );
        $this->assertSame( self::ARTICLE, StubsSeo::$posts[9731]['post_content'] );
        $this->assertSame( 'kses', $this->item( $linker )['auto_skip_code'] );
        $this->assertSame( [], StubsSeo::$userSwitches, 'nobody was impersonated' );

        // The Autopilot text now says it, under a new policy version; switching Autopilot on again records that.
        $this->assertSame( '1.2.2-1', Autopilot::POLICY_VERSION );
        $text = Autopilot::policyText();
        $this->assertStringContainsString( 'saves that one change as your account', $text );
        $this->assertStringContainsString( 'takes its own automatic links back out of pages whose template doesn’t display them', $text );
        $this->assertSame( $text, ( new Autopilot( $this->settings() ) )->status()['policy_text'] );
        ( new Autopilot( $this->settings() ) )->enable( 1 );
        $this->assertSame( 1, $linker->autoOwner( 9731 ) );
        $again = $linker->autoApply( TimeBudget::seconds( 30 ) );
        $this->assertSame( 1, $again['applied'], $again['message'] );
        $this->assertSame( 1, end( StubsSeo::$updates )['_user'] );
    }
}
