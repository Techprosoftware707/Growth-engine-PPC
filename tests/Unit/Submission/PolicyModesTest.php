<?php
declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Submission;

use PPC\LinkGrowth\Submission\Policy;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class PolicyModesTest extends TestCase {

    /** @return array<string,mixed> */
    private function form(): array {
        return [
            'kind'         => 'listing_submission',
            'method'       => 'POST',
            'action'       => 'https://labdirectory.test/add-listing/submit',
            'has_captcha'  => false,
            'has_password' => false,
            'fields'       => [ [ 'name' => 'website', 'semantic' => 'website', 'type' => 'url', 'required' => true ] ],
        ];
    }

    /** @return array<string,mixed> */
    private function prospect( ?int $score, int $allowed = 0 ): array {
        return [ 'id' => 11, 'domain' => 'labdirectory.test', 'score' => $score, 'automation_allowed' => $allowed, 'submission_config' => [] ];
    }

    public function testAutoModeAuthorisesHighScoringSitesOnly(): void {
        $policy = Container::get( Policy::class );
        $high = $policy->evaluate( $this->prospect( 85 ), [ 'unmapped_required' => [] ], $this->form(), 'https://labdirectory.test/add-listing/' );
        $this->assertTrue( $high['allowed'], implode( ' | ', $high['reasons'] ) );
        $low = $policy->evaluate( $this->prospect( 55 ), [ 'unmapped_required' => [] ], $this->form(), 'https://labdirectory.test/add-listing/' );
        $this->assertFalse( $low['allowed'] );
        $this->assertFalse( $low['hard'], 'quality gate is a soft blocker' );
        $unscored = $policy->evaluate( $this->prospect( null ), [ 'unmapped_required' => [] ], $this->form(), 'https://labdirectory.test/add-listing/' );
        $this->assertFalse( $unscored['allowed'] );
        $override = $policy->evaluate( $this->prospect( 55, 1 ), [ 'unmapped_required' => [] ], $this->form(), 'https://labdirectory.test/add-listing/' );
        $this->assertTrue( $override['allowed'], 'explicit authorization overrides the score gate' );
    }

    public function testStricterModesStillRequireExplicitAuthorization(): void {
        $settings = Container::get( Settings::class );
        $policy   = Container::get( Policy::class );
        $settings->set( 'automation.submission_mode', 'authorized_only' );
        $r = $policy->evaluate( $this->prospect( 95 ), [ 'unmapped_required' => [] ], $this->form(), 'https://labdirectory.test/add-listing/' );
        $this->assertFalse( $r['allowed'] );
        $this->assertTrue( $policy->evaluate( $this->prospect( 95, 1 ), [ 'unmapped_required' => [] ], $this->form(), 'https://labdirectory.test/add-listing/' )['allowed'] );
        $settings->set( 'automation.submission_mode', 'manual' );
        $m = $policy->evaluate( $this->prospect( 95, 1 ), [ 'unmapped_required' => [] ], $this->form(), 'https://labdirectory.test/add-listing/' );
        $this->assertTrue( $m['allowed'], 'explicit per-prospect authorization still works in manual mode' );
        $none = $policy->evaluate( $this->prospect( 95 ), [ 'unmapped_required' => [] ], $this->form(), 'https://labdirectory.test/add-listing/' );
        $this->assertFalse( $none['allowed'] );
    }

    public function testHardGatesBeatEveryMode(): void {
        $policy  = Container::get( Policy::class );
        $captcha = $this->form();
        $captcha['has_captcha'] = true;
        $r = $policy->evaluate( $this->prospect( 95, 1 ), [ 'unmapped_required' => [] ], $captcha, 'https://labdirectory.test/add-listing/' );
        $this->assertFalse( $r['allowed'] );
        $this->assertTrue( $r['hard'] );
        $this->assertStringContainsString( 'CAPTCHA', implode( ' ', $r['reasons'] ) );
        $unmapped = $policy->evaluate( $this->prospect( 95, 1 ), [ 'unmapped_required' => [ 'category' ] ], $this->form(), 'https://labdirectory.test/add-listing/' );
        $this->assertTrue( $unmapped['hard'] );
        $get = $this->form();
        $get['method'] = 'GET';
        $this->assertTrue( $policy->evaluate( $this->prospect( 95, 1 ), [ 'unmapped_required' => [] ], $get, 'https://labdirectory.test/add-listing/' )['hard'] );
    }

    public function testModeNormalisation(): void {
        $settings = Container::get( Settings::class );
        $this->assertSame( 'auto', Policy::submissionMode( $settings ) );
        $settings->set( 'automation.submission_mode', 'AUTHORIZED_ONLY' );
        $this->assertSame( 'authorized_only', Policy::submissionMode( $settings ) );
        $settings->set( 'automation.submission_mode', 'nonsense' );
        $this->assertSame( 'auto', Policy::submissionMode( $settings ) );
    }
}
