<?php
/**
 * WP1 removals and their replacements (DESIGN.md §1.5, §2.4): no AI model ships as a default and the writer stays
 * off until the owner names one; the search provider that resold scraped results is gone from the registry, the
 * settings and the secrets; the language rule reads a translation counterpart meta only under the key the owner
 * names in seo.language_counterpart_meta_key.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Integrations;

use LinkGrowth\Autopilot\Admin\Rest\SettingsController;
use LinkGrowth\Autopilot\Integrations\AiWriter;
use LinkGrowth\Autopilot\Integrations\ProviderRegistry;
use LinkGrowth\Autopilot\Seo\PostLanguage;
use LinkGrowth\Autopilot\Support\Secrets;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Tests\Support\Container;
use LinkGrowth\Autopilot\Tests\Support\HttpFixtures;
use LinkGrowth\Autopilot\Tests\Support\StubsSeo;
use LinkGrowth\Autopilot\Tests\Support\TestCase;
use WP_REST_Request;

require_once dirname( __DIR__, 2 ) . '/Support/StubsSeo.php';

final class ForkRemovalsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        StubsSeo::reset();
        $GLOBALS['lga_test_caps']    = [ 'manage_options' => true ];
        $GLOBALS['lga_test_user_id'] = 1;
    }

    protected function tearDown(): void {
        StubsSeo::reset();
        unset( $GLOBALS['lga_test_caps'] );
        $GLOBALS['lga_test_user_id'] = 0;
        parent::tearDown();
    }

    private function settings(): Settings {
        return Container::get( Settings::class );
    }

    public function testNoAiModelShipsAndTheWriterStaysOffUntilTheOwnerNamesOne(): void {
        $this->assertSame( '', Settings::defaults()['providers']['ai']['model'] );
        Container::get( Secrets::class )->set( 'anthropic_api_key', 'sk-ant-test-0123456789' );
        $this->settings()->set( 'providers.ai.enabled', true );
        $writer = Container::get( AiWriter::class );
        $this->assertTrue( $writer->isConfigured(), 'the key is there' );
        $this->assertSame( '', $writer->model() );
        $this->assertFalse( $writer->isEnabled(), 'no model: the writer is off' );
        $this->assertNull( $writer->generate( 'system', 'user' ) );
        $this->assertSame( [], HttpFixtures::$requests, 'no API call is made without a model' );

        $this->settings()->set( 'providers.ai.model', 'owner-chosen-model' );
        $this->assertSame( 'owner-chosen-model', $writer->model() );
        $this->assertTrue( $writer->isEnabled() );
        $this->settings()->set( 'providers.ai.model', 'Not a model id!' );
        $this->assertSame( '', $writer->model(), 'an id that is not plain is not used' );
        $this->assertFalse( $writer->isEnabled() );

        // The connection test says what is missing instead of calling the API.
        $this->settings()->set( 'providers.ai.model', '' );
        $req = new WP_REST_Request( 'POST', '/lga/v1/settings/test' );
        $req->set_param( 'provider', 'anthropic' );
        $res = ( new SettingsController( Container::plugin()->container() ) )->test( $req );
        $res = is_object( $res ) && method_exists( $res, 'get_data' ) ? $res->get_data() : $res;
        $this->assertFalse( $res['ok'] );
        $this->assertStringContainsString( 'No AI model is set', $res['message'] );
        $this->assertSame( [], HttpFixtures::$requests );

        // The owner can clear the model through the settings form.
        $this->settings()->set( 'providers.ai.model', 'owner-chosen-model' );
        $save = new WP_REST_Request( 'POST', '/lga/v1/settings' );
        $save->set_param( 'settings', [ 'providers' => [ 'ai' => [ 'model' => '' ] ] ] );
        ( new SettingsController( Container::plugin()->container() ) )->update( $save );
        $this->assertSame( '', $this->settings()->get( 'providers.ai.model' ) );
    }

    public function testOnlyTheRemainingSearchProvidersAreRegistered(): void {
        $registry = Container::get( ProviderRegistry::class );
        $ids      = [];
        foreach ( [ 'google_cse', 'brave', 'serper' ] as $id ) {
            if ( null !== $registry->searchProvider( $id ) ) {
                $ids[] = $id;
            }
        }
        $this->assertSame( [ 'google_cse', 'brave' ], $ids );
        $this->assertArrayNotHasKey( 'serper_daily_limit', Settings::defaults()['providers']['search'] );
        $this->assertArrayNotHasKey( 'serper_api_key', Secrets::KEYS );

        // A stored primary of the removed provider is not accepted from the form.
        $save = new WP_REST_Request( 'POST', '/lga/v1/settings' );
        $save->set_param( 'settings', [ 'providers' => [ 'search' => [ 'primary' => 'serper' ] ] ] );
        ( new SettingsController( Container::plugin()->container() ) )->update( $save );
        $this->assertSame( 'auto', $this->settings()->get( 'providers.search.primary' ) );
    }

    public function testTheCounterpartMetaIsReadOnlyUnderTheKeyTheOwnerNames(): void {
        $this->assertSame( '', Settings::defaults()['seo']['language_counterpart_meta_key'] );
        $home = 'https://northwind.test';
        // A translated page at the site root (no language prefix) whose text is too short to tell its language.
        StubsSeo::addPost( 501, [ 'post_type' => 'page', 'post_title' => 'Contact', 'url' => $home . '/kontakt/', 'post_content' => '<p>Kontakt</p>' ], [ 'translation_of' => 77 ] );
        StubsSeo::addPost( 502, [ 'post_type' => 'page', 'post_title' => 'Kontakt', 'url' => $home . '/de/kontakt/', 'post_content' => '<p>Kontakt</p>' ], [ 'translation_of' => 77 ] );

        $none = new PostLanguage();
        $this->assertSame( 'en', $none->of( 501 ), 'no key named: the meta is not read, the site language applies' );
        $this->assertSame( 'de', $none->of( 502 ), 'the address prefix still decides' );

        $owner = new PostLanguage( [], 'translation_of' );
        $this->assertSame( PostLanguage::UNKNOWN, $owner->of( 501 ), 'a counterpart without a prefix or a readable text is in no known language' );
        $this->assertSame( 'de', $owner->of( 502 ) );

        $this->assertSame( '', PostLanguage::validKey( 'bad key; DROP' ) );
        $this->assertSame( 'en', ( new PostLanguage( [], 'bad key' ) )->of( 501 ), 'an invalid key reads nothing' );

        // The settings form accepts a plain key and refuses anything else.
        $save = new WP_REST_Request( 'POST', '/lga/v1/settings' );
        $save->set_param( 'settings', [ 'seo' => [ 'language_counterpart_meta_key' => 'translation_of' ] ] );
        ( new SettingsController( Container::plugin()->container() ) )->update( $save );
        $this->assertSame( 'translation_of', $this->settings()->get( PostLanguage::COUNTERPART_SETTING ) );
        $bad = new WP_REST_Request( 'POST', '/lga/v1/settings' );
        $bad->set_param( 'settings', [ 'seo' => [ 'language_counterpart_meta_key' => 'not a key' ] ] );
        ( new SettingsController( Container::plugin()->container() ) )->update( $bad );
        $this->assertSame( 'translation_of', $this->settings()->get( PostLanguage::COUNTERPART_SETTING ), 'an invalid key is not stored' );
    }
}
