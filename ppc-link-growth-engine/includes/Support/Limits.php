<?php
/**
 * Daily / weekly action limits with persistent counters.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Support;

final class Limits {

    public const OPTION = 'ppclg_counters';

    public function __construct( private Settings $settings ) {}

    /** @return array{day:string,week:string,daily:array<string,int>,weekly:array<string,int>} */
    private function counters(): array {
        $now   = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
        $day   = gmdate( 'Y-m-d', $now );
        $week  = gmdate( 'o-W', $now );
        $saved = get_option( self::OPTION, [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }
        $out = [
            'day'    => $day,
            'week'   => $week,
            'daily'  => ( ( $saved['day'] ?? '' ) === $day && is_array( $saved['daily'] ?? null ) ) ? $saved['daily'] : [],
            'weekly' => ( ( $saved['week'] ?? '' ) === $week && is_array( $saved['weekly'] ?? null ) ) ? $saved['weekly'] : [],
        ];
        return $out;
    }

    public function dailyLimit( string $key ): int {
        return (int) $this->settings->get( 'automation.daily_limits.' . $key, 0 );
    }

    public function weeklyLimit( string $key ): int {
        return (int) $this->settings->get( 'automation.weekly_limits.' . $key, 0 );
    }

    public function used( string $key, string $period = 'daily' ): int {
        $c = $this->counters();
        return (int) ( $c[ $period ][ $key ] ?? 0 );
    }

    /** Remaining actions for today (and this week when a weekly limit exists). */
    public function remaining( string $key ): int {
        $daily = $this->dailyLimit( $key );
        $rem   = $daily > 0 ? max( 0, $daily - $this->used( $key, 'daily' ) ) : PHP_INT_MAX;
        $wk    = $this->weeklyLimit( $key );
        if ( $wk > 0 ) {
            $rem = min( $rem, max( 0, $wk - $this->used( $key, 'weekly' ) ) );
        }
        return $rem;
    }

    public function allow( string $key, int $n = 1 ): bool {
        return $this->remaining( $key ) >= $n;
    }

    public function spend( string $key, int $n = 1 ): void {
        $c = $this->counters();
        $c['daily'][ $key ]  = (int) ( $c['daily'][ $key ] ?? 0 ) + $n;
        $c['weekly'][ $key ] = (int) ( $c['weekly'][ $key ] ?? 0 ) + $n;
        update_option( self::OPTION, $c, false );
    }

    /** @return array<string,array{used_today:int,limit_today:int,used_week:int,limit_week:int,remaining:int}> */
    public function snapshot(): array {
        $keys = array_unique( array_merge( array_keys( (array) $this->settings->get( 'automation.daily_limits', [] ) ), array_keys( (array) $this->settings->get( 'automation.weekly_limits', [] ) ) ) );
        $out  = [];
        foreach ( $keys as $key ) {
            $out[ $key ] = [
                'used_today'  => $this->used( $key, 'daily' ),
                'limit_today' => $this->dailyLimit( $key ),
                'used_week'   => $this->used( $key, 'weekly' ),
                'limit_week'  => $this->weeklyLimit( $key ),
                'remaining'   => $this->remaining( $key ) === PHP_INT_MAX ? -1 : $this->remaining( $key ),
            ];
        }
        return $out;
    }
}
