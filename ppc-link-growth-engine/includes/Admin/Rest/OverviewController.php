<?php
/**
 * GET /overview — dashboard statistics, charts, notifications and integration health.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Admin\Rest;

use PPC\LinkGrowth\Automation\Pipeline;
use PPC\LinkGrowth\Automation\Scheduler;
use PPC\LinkGrowth\Integrations\Google\Analytics4;
use PPC\LinkGrowth\Integrations\Google\SearchConsole;
use PPC\LinkGrowth\Integrations\Google\SiteKitBridge;
use PPC\LinkGrowth\Integrations\MetricsAggregator;
use PPC\LinkGrowth\Integrations\ProviderRegistry;
use PPC\LinkGrowth\Outreach\Compliance;
use PPC\LinkGrowth\Repositories\Backlinks;
use PPC\LinkGrowth\Repositories\Notifications;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Support\Crypto;
use PPC\LinkGrowth\Support\Limits;
use PPC\LinkGrowth\Support\Secrets;
use WP_REST_Request;

final class OverviewController extends Controller {

    public const CACHE_GSC       = 'ppclg_overview_gsc';
    public const CACHE_REFERRAL  = 'ppclg_overview_referral';
    public const CACHE_SERANKING = 'ppclg_seranking_balance';

    /** Cache lifetime for successful external lookups. */
    private const CACHE_TTL = HOUR_IN_SECONDS;

    /** Cache lifetime for failed/unavailable lookups (avoids hammering failing APIs). */
    private const CACHE_TTL_MISS = 10 * MINUTE_IN_SECONDS;

    public function register( string $namespace ): void {
        $this->route( $namespace, '/overview', 'GET', [ $this, 'overview' ], [ 'refresh' => [ 'type' => 'boolean', 'default' => false ] ] );
        $this->route( $namespace, '/ping', 'GET', [ $this, 'ping' ] );
    }

    /** Lightweight health check for the front end. @return array<string,mixed> */
    public function ping( WP_REST_Request $request ): array {
        unset( $request );
        return [
            'ok'      => true,
            'version' => defined( 'PPCLG_VERSION' ) ? PPCLG_VERSION : '',
            'time'    => gmdate( 'c' ),
            'user'    => $this->userId(),
        ];
    }

    /** @return array<string,mixed> */
    public function overview( WP_REST_Request $request ): array {
        if ( $this->bool( $request, 'refresh' ) ) {
            delete_transient( self::CACHE_GSC );
            delete_transient( self::CACHE_REFERRAL );
            delete_transient( self::CACHE_SERANKING );
        }
        return [
            'stats'                => $this->stats(),
            'charts'               => $this->charts(),
            'notifications'        => $this->safe(
                fn(): array => array_map( static fn( array $n ): array => self::castInts( $n, [ 'is_read' ] ), $this->c->get( Notifications::class )->recent( 10 ) ),
                [],
                'overview notifications'
            ),
            'providers'            => $this->safe( fn(): array => $this->c->get( ProviderRegistry::class )->status(), [], 'overview providers' ),
            'automation'           => $this->safe( fn(): array => $this->c->get( Pipeline::class )->status(), null, 'overview automation' ),
            'scheduler'            => $this->safe( fn(): ?array => class_exists( Scheduler::class ) ? $this->c->get( Scheduler::class )->status() : null, null, 'overview scheduler' ),
            'seranking_balance'    => $this->serankingBalance(),
            'limits'               => $this->safe( fn(): array => $this->c->get( Limits::class )->snapshot(), [], 'overview limits' ),
            'missing_integrations' => $this->missingIntegrations(),
            'generated_at'         => gmdate( 'c' ),
        ];
    }

    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function stats(): array {
        $prospects   = $this->c->get( Prospects::class );
        $backlinks   = $this->c->get( Backlinks::class );
        $ps          = $this->safe( fn(): array => $prospects->statusCounts(), [], 'prospect counts' );
        $ss          = $this->safe( fn(): array => $this->c->get( Submissions::class )->statusCounts(), [], 'submission counts' );
        $bs          = $this->safe( fn(): array => $backlinks->statusCounts(), [], 'backlink counts' );
        $rr          = $this->safe( fn(): array => $this->c->get( OutreachMessages::class )->responseRate(), [ 'sent' => 0, 'replied' => 0, 'rate' => 0.0 ], 'response rate' );
        $since30     = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
        $gsc         = $this->searchConsole();
        $referral    = $this->referral();
        $quality     = (int) $this->safe( fn(): int => $backlinks->highAuthorityDomainCount( 60 ), 0, 'quality domains' );

        return [
            'prospects_total'        => (int) array_sum( $ps ),
            'prospects_qualified'    => (int) ( $ps[ Prospects::STATUS_QUALIFIED ] ?? 0 ),
            'prospects_approved'     => (int) ( $ps[ Prospects::STATUS_APPROVED ] ?? 0 ),
            'prospects_acquired'     => (int) ( $ps[ Prospects::STATUS_ACQUIRED ] ?? 0 ),
            'prospects_discovered'   => (int) ( $ps[ Prospects::STATUS_DISCOVERED ] ?? 0 ),
            'submissions_submitted'  => (int) ( $ss[ Submissions::STATUS_SUBMITTED ] ?? 0 ) + (int) ( $ss[ Submissions::STATUS_CONFIRMED ] ?? 0 ),
            'submissions_confirmed'  => (int) ( $ss[ Submissions::STATUS_CONFIRMED ] ?? 0 ),
            'submissions_pending'    => (int) ( $ss[ Submissions::STATUS_PENDING_REVIEW ] ?? 0 ) + (int) ( $ss[ Submissions::STATUS_NEEDS_MANUAL ] ?? 0 ),
            'backlinks_active'       => (int) ( $bs[ Backlinks::STATUS_ACTIVE ] ?? 0 ),
            'backlinks_lost'         => (int) ( $bs[ Backlinks::STATUS_LOST ] ?? 0 ) + (int) ( $bs[ Backlinks::STATUS_BROKEN ] ?? 0 ),
            'backlinks_pending'      => (int) ( $bs[ Backlinks::STATUS_PENDING ] ?? 0 ),
            'backlinks_new_30d'      => (int) $this->safe( fn(): int => $backlinks->countSince( 'first_seen', $since30 ), 0, 'new backlinks' ),
            'backlinks_lost_30d'     => (int) $this->safe( fn(): int => $backlinks->countSince( 'lost_at', $since30 ), 0, 'lost backlinks' ),
            'referring_domains'      => (int) $this->safe( fn(): int => $backlinks->referringDomainCount( Backlinks::STATUS_ACTIVE ), 0, 'referring domains' ),
            // Active referring domains with a link quality score >= 60 (the monitor's score, not provider authority;
            // /backlinks/summary reports provider authority as high_authority_domains).
            'quality_domains'        => $quality,
            // Deprecated: the same link-quality count under its old, misleading name. Kept for one release for
            // readers of the old field; remove in the next minor version.
            'high_authority_domains' => $quality,
            'referral_sessions_28d'  => $referral['sessions'],
            'referral_source'        => $referral['source'],
            'outreach_sent'          => (int) ( $rr['sent'] ?? 0 ),
            'outreach_replied'       => (int) ( $rr['replied'] ?? 0 ),
            'outreach_response_rate' => (float) ( $rr['rate'] ?? 0 ),
            'gsc_clicks_28d'         => $gsc['clicks'],
            'gsc_impressions_28d'    => $gsc['impressions'],
            'gsc_source'             => $gsc['source'],
            'unread_notifications'   => (int) $this->safe( fn(): int => $this->c->get( Notifications::class )->unreadCount(), 0, 'unread notifications' ),
        ];
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private function charts(): array {
        $prospects = $this->c->get( Prospects::class );
        $backlinks = $this->c->get( Backlinks::class );

        $histogram = [];
        foreach ( $this->safe( fn(): array => $prospects->scoreHistogram(), [], 'score histogram' ) as $bucket => $count ) {
            $histogram[] = [ 'bucket' => (int) $bucket, 'label' => $bucket . '–' . ( (int) $bucket + 9 ), 'count' => (int) $count ];
        }
        $anchors = [];
        foreach ( $this->safe( fn(): array => $backlinks->anchorDistribution( 12 ), [], 'anchor distribution' ) as $anchor => $count ) {
            $anchors[] = [ 'anchor' => (string) $anchor, 'count' => (int) $count ];
        }
        $rel = [];
        foreach ( $this->safe( fn(): array => $backlinks->relDistribution(), [], 'rel distribution' ) as $value => $count ) {
            $rel[] = [ 'rel' => (string) $value, 'count' => (int) $count ];
        }
        $types = [];
        foreach ( $this->safe( fn(): array => $this->c->get( Opportunities::class )->typeCounts(), [], 'opportunity types' ) as $type => $count ) {
            $types[] = [
                'type'  => (string) $type,
                'label' => Opportunities::TYPES[ $type ] ?? ucwords( str_replace( '_', ' ', (string) $type ) ),
                'count' => (int) $count,
            ];
        }
        $status = [];
        foreach ( $this->safe( fn(): array => $prospects->statusCounts(), [], 'status counts' ) as $value => $count ) {
            $status[] = [ 'status' => (string) $value, 'count' => (int) $count ];
        }
        $trend = [];
        foreach ( $this->safe( fn(): array => $backlinks->weeklyTrend( 12 ), [], 'backlink trend' ) as $week ) {
            $trend[] = [ 'week' => (string) ( $week['week'] ?? '' ), 'new' => (int) ( $week['new'] ?? 0 ), 'lost' => (int) ( $week['lost'] ?? 0 ) ];
        }

        return [
            'score_histogram'     => $histogram,
            'backlink_trend'      => $trend,
            'anchor_distribution' => $anchors,
            'rel_distribution'    => $rel,
            'opportunity_types'   => $types,
            'status_counts'       => $status,
        ];
    }

    /** @return array{clicks:?int,impressions:?int,source:?string,error:string} */
    private function searchConsole(): array {
        $cached = get_transient( self::CACHE_GSC );
        if ( is_array( $cached ) && is_array( $cached['v'] ?? null ) ) {
            return $cached['v'];
        }
        $out = [ 'clicks' => null, 'impressions' => null, 'source' => null, 'error' => '' ];
        $ttl = self::CACHE_TTL_MISS;
        try {
            $gsc = $this->c->get( SearchConsole::class );
            if ( $gsc->isConfigured() ) {
                $t = $gsc->totals( 28 );
                if ( is_array( $t ) ) {
                    $out['clicks']      = (int) round( (float) ( $t['clicks'] ?? 0 ) );
                    $out['impressions'] = (int) round( (float) ( $t['impressions'] ?? 0 ) );
                    $out['source']      = 'gsc';
                    $ttl                = self::CACHE_TTL;
                } else {
                    $out['error'] = $gsc->lastError();
                }
            }
            if ( null === $out['source'] ) {
                $sk = $this->c->get( SiteKitBridge::class );
                if ( $sk->isAvailable() ) {
                    $t = $sk->searchConsoleTotals( 28 );
                    if ( is_array( $t ) ) {
                        $out['clicks']      = (int) round( (float) ( $t['clicks'] ?? 0 ) );
                        $out['impressions'] = (int) round( (float) ( $t['impressions'] ?? 0 ) );
                        $out['source']      = 'sitekit';
                        $out['error']       = '';
                        $ttl                = self::CACHE_TTL;
                    } elseif ( '' === $out['error'] ) {
                        $out['error'] = $sk->lastError();
                    }
                }
            }
        } catch ( \Throwable $e ) {
            $out['error'] = $e->getMessage();
        }
        set_transient( self::CACHE_GSC, [ 'v' => $out, 'at' => time() ], $ttl );
        return $out;
    }

    /** @return array{sessions:?int,source:?string,error:string} */
    private function referral(): array {
        $cached = get_transient( self::CACHE_REFERRAL );
        if ( is_array( $cached ) && is_array( $cached['v'] ?? null ) ) {
            return $cached['v'];
        }
        $out = [ 'sessions' => null, 'source' => null, 'error' => '' ];
        $ttl = self::CACHE_TTL_MISS;
        try {
            $ga = $this->c->get( Analytics4::class );
            if ( $ga->isConfigured() ) {
                $t = $ga->referralTotals( 28 );
                if ( is_array( $t ) ) {
                    $out['sessions'] = (int) ( $t['sessions'] ?? 0 );
                    $out['source']   = 'ga4';
                    $ttl             = self::CACHE_TTL;
                } else {
                    $out['error'] = $ga->lastError();
                }
            }
            if ( null === $out['source'] ) {
                $sk = $this->c->get( SiteKitBridge::class );
                if ( $sk->isAvailable() ) {
                    $rows = $sk->referralSources( 28 );
                    if ( is_array( $rows ) ) {
                        $sum = 0;
                        foreach ( $rows as $row ) {
                            $sum += (int) ( $row['sessions'] ?? 0 );
                        }
                        $out['sessions'] = $sum;
                        $out['source']   = 'sitekit';
                        $out['error']    = '';
                        $ttl             = self::CACHE_TTL;
                    } elseif ( '' === $out['error'] ) {
                        $out['error'] = $sk->lastError();
                    }
                }
            }
        } catch ( \Throwable $e ) {
            $out['error'] = $e->getMessage();
        }
        set_transient( self::CACHE_REFERRAL, [ 'v' => $out, 'at' => time() ], $ttl );
        return $out;
    }

    /** @return array<string,mixed>|null */
    private function serankingBalance(): ?array {
        $cached = get_transient( self::CACHE_SERANKING );
        if ( is_array( $cached ) && array_key_exists( 'v', $cached ) ) {
            return is_array( $cached['v'] ) ? $cached['v'] : null;
        }
        $value = null;
        $ttl   = self::CACHE_TTL_MISS;
        try {
            $se = $this->c->get( ProviderRegistry::class )->seranking();
            if ( $se->isEnabled() && $se->isConfigured() ) {
                $value = $se->subscription();
                if ( is_array( $value ) ) {
                    $value['fetched_at'] = gmdate( 'c' );
                    $ttl                 = self::CACHE_TTL;
                }
            }
        } catch ( \Throwable $e ) {
            $value = null;
        }
        set_transient( self::CACHE_SERANKING, [ 'v' => $value, 'at' => time() ], $ttl );
        return $value;
    }

    /** @return string[] */
    private function missingIntegrations(): array {
        $out = [];
        $this->safe(
            function () use ( &$out ): void {
                $registry = $this->c->get( ProviderRegistry::class );
                $search   = false;
                foreach ( $registry->status() as $entry ) {
                    if ( is_array( $entry ) && 'search' === (string) ( $entry['kind'] ?? '' ) && ! empty( $entry['configured'] ) ) {
                        $search = true;
                        break;
                    }
                }
                if ( ! $search ) {
                    $out[] = __( 'No search provider configured: discovery cannot run search queries. Add a Google Programmable Search, Brave Search or Serper API key.', 'ppc-link-growth-engine' );
                }
                if ( ! $this->c->get( MetricsAggregator::class )->hasAnyProvider() ) {
                    $out[] = __( 'No domain-metrics provider configured (SE Ranking, Ahrefs, Semrush or Moz): authority and traffic signals are unavailable and scores rely on on-page analysis only.', 'ppc-link-growth-engine' );
                }
            },
            null,
            'missing providers'
        );
        $this->safe(
            function () use ( &$out ): void {
                $sitekit = $this->c->get( SiteKitBridge::class )->isAvailable();
                if ( ! $this->c->get( SearchConsole::class )->isConfigured() && ! $sitekit ) {
                    $out[] = __( 'Google Search Console is not connected: organic clicks and impressions are unavailable.', 'ppc-link-growth-engine' );
                }
                if ( ! $this->c->get( Analytics4::class )->isConfigured() && ! $sitekit ) {
                    $out[] = __( 'Google Analytics 4 is not connected: referral traffic cannot be imported as backlink evidence.', 'ppc-link-growth-engine' );
                }
            },
            null,
            'missing google'
        );
        $this->safe(
            function () use ( &$out ): void {
                $sender = $this->c->get( Compliance::class )->senderReady();
                if ( empty( $sender['ok'] ) ) {
                    $out[] = __( 'Outreach sender identity is incomplete (sender name, sender email, postal address): outreach emails cannot be sent.', 'ppc-link-growth-engine' );
                }
            },
            null,
            'missing sender'
        );
        $this->safe(
            function () use ( &$out ): void {
                if ( ! $this->c->get( Secrets::class )->has( 'inbound_webhook_secret' ) ) {
                    $out[] = __( 'Inbound email webhook secret is not set: replies to outreach will not be tracked automatically.', 'ppc-link-growth-engine' );
                }
                if ( ! $this->c->get( Crypto::class )->hasStrongKeys() ) {
                    $out[] = __( 'WordPress salts (AUTH_KEY / SECURE_AUTH_KEY) are weak or missing: stored API keys cannot be encrypted securely.', 'ppc-link-growth-engine' );
                }
            },
            null,
            'missing secrets'
        );
        return $out;
    }
}
