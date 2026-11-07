<?php
declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Support;

use PPC\LinkGrowth\Support\Limits;
use PPC\LinkGrowth\Support\Locks;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class SettingsLimitsLocksTest extends TestCase {

    public function testSettingsDefaultsAndDotPaths(): void {
        $s = new Settings();
        $this->assertSame( 'purepeptidescanada.test', $s->siteDomain() );
        $this->assertTrue( $s->get( 'automation.enabled' ), 'automation is on by default (auto-grow)' );
        $this->assertSame( 'auto', $s->get( 'automation.submission_mode' ) );
        $this->assertTrue( $s->get( 'automation.auto_outreach_drafts' ) );
        $this->assertFalse( $s->get( 'automation.outreach_autosend' ), 'email autosend stays an explicit opt-in' );
        $this->assertSame( 5, $s->get( 'automation.daily_limits.submissions' ) );
        $s->set( 'automation.daily_limits.submissions', 9 );
        $this->assertSame( 9, $s->get( 'automation.daily_limits.submissions' ) );
        $s->update( [ 'discovery' => [ 'geo_modifiers' => [ 'Canada' ] ] ] );
        $this->assertSame( [ 'Canada' ], $s->get( 'discovery.geo_modifiers' ) );
        $this->assertSame( 30, $s->get( 'discovery.query_cooldown_days' ), 'sibling keys survive a partial update' );
        $this->assertSame( 'fallback', $s->get( 'does.not.exist', 'fallback' ) );
    }

    public function testLimitsCountDailyAndWeekly(): void {
        $s = new Settings();
        $s->set( 'automation.daily_limits.submissions', 2 );
        $s->set( 'automation.weekly_limits.submissions', 3 );
        $l = new Limits( $s );
        $this->assertTrue( $l->allow( 'submissions' ) );
        $l->spend( 'submissions', 2 );
        $this->assertFalse( $l->allow( 'submissions' ) );
        $this->assertSame( 0, $l->remaining( 'submissions' ) );
        $this->assertSame( 2, $l->used( 'submissions', 'weekly' ) );
        $this->assertSame( PHP_INT_MAX, $l->remaining( 'unlimited_key' ) );
        $snap = $l->snapshot();
        $this->assertSame( 2, $snap['submissions']['used_today'] );
    }

    public function testLocksAcquireReleaseAndStaleTakeover(): void {
        $locks = new Locks();
        $this->assertTrue( $locks->acquire( 'runner', 60 ) );
        $this->assertFalse( ( new Locks() )->acquire( 'runner', 60 ) );
        $this->assertTrue( $locks->isLocked( 'runner' ) );
        $locks->release( 'runner' );
        $this->assertFalse( $locks->isLocked( 'runner' ) );
        update_option( 'ppclg_lock_stale', [ 'token' => 'old', 'expires' => time() - 10 ] );
        $this->assertTrue( $locks->acquire( 'stale', 60 ) );
    }

    public function testTimeBudget(): void {
        $b = TimeBudget::seconds( 5 );
        $this->assertTrue( $b->has( 1 ) );
        $this->assertFalse( $b->expired() );
        $this->assertGreaterThan( 4.0, $b->remaining() );
    }
}
