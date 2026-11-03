<?php
/**
 * Imports backlinks discovered by third-party providers (SE Ranking, Ahrefs,
 * Semrush, ...) and referral sources reported by Google Analytics 4, so the
 * monitor can verify them.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Monitoring;

use PPC\LinkGrowth\Integrations\Contracts\BacklinkSource;
use PPC\LinkGrowth\Integrations\Google\Analytics4;
use PPC\LinkGrowth\Integrations\ProviderRegistry;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\Backlinks;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Repository;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Url;

final class BacklinkImporter {

    public const OPTION_LAST_IMPORT = 'ppclg_last_import_at';

    /** Provider source authority (0-100) from which a new link is announced as valuable. */
    public const HIGH_AUTHORITY = 30;

    private const DAY = 86400;

    private const PER_DOMAIN = 5;

    private const MAX_LIMIT = 10000;

    /** GA4 sources that are not referring websites. */
    private const IGNORED_SOURCES = [ '(direct)', '(not set)', '(none)', 'localhost' ];

    public function __construct(
        private Backlinks $backlinks,
        private Prospects $prospects,
        private ProviderRegistry $registry,
        private Analytics4 $analytics,
        private Settings $settings,
        private Notifier $notifier,
        private AuditLog $audit,
        private Logger $logger
    ) {}

    /** True when no import ran within settings monitoring.import_interval_days. */
    public function isDue(): bool {
        $days = max( 1, (int) $this->settings->get( 'monitoring.import_interval_days', 7 ) );
        $last = (string) get_option( self::OPTION_LAST_IMPORT, '' );
        if ( '' === trim( $last ) ) {
            return true;
        }
        $ts = strtotime( trim( $last ) . ' UTC' );
        return false === $ts || $ts <= time() - $days * self::DAY;
    }

    /**
     * Pulls backlinks pointing at the site from every configured backlink provider.
     *
     * New rows are stored as pending (origin provider:{id}) and verified by the monitor;
     * existing rows are left untouched so monitor observations are never overwritten.
     *
     * @return array{providers:string[],records:int,new:int,warning:string}
     */
    public function importFromProviders( int $limit = 500 ): array {
        $limit    = max( 1, min( self::MAX_LIMIT, $limit ) );
        $out      = [ 'providers' => [], 'records' => 0, 'new' => 0, 'warning' => '' ];
        $warnings = [];
        $valuable = 0;
        $domain   = $this->siteDomain();
        $home     = $this->homeUrl();

        if ( '' === $domain ) {
            $out['warning'] = 'Site domain is not configured.';
            return $out;
        }

        $sources = [];
        try {
            $sources = $this->registry->backlinkSources();
        } catch ( \Throwable $e ) {
            $warnings[] = 'Provider registry error: ' . $e->getMessage();
            $this->logger->error( 'monitoring', 'Backlink sources unavailable', [ 'error' => $e->getMessage() ] );
        }
        if ( empty( $sources ) ) {
            $warnings[] = 'No backlink provider is enabled and configured (SE Ranking, Ahrefs or Semrush).';
        }

        foreach ( $sources as $source ) {
            if ( ! $source instanceof BacklinkSource ) {
                continue;
            }
            $pid                = $source->id();
            $out['providers'][] = $pid;
            try {
                $rows = $source->backlinks( $domain, $limit, self::PER_DOMAIN );
            } catch ( \Throwable $e ) {
                $warnings[] = sprintf( '%s: %s', $pid, $e->getMessage() );
                $this->logger->warning( 'monitoring', 'Provider backlink list failed', [ 'provider' => $pid, 'error' => $e->getMessage() ] );
                continue;
            }
            if ( empty( $rows ) ) {
                $this->logger->info( 'monitoring', 'Provider returned no backlinks', [ 'provider' => $pid, 'domain' => $domain ] );
                continue;
            }
            $providerNew = 0;
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                try {
                    $r = $this->importProviderRow( $row, $pid, $domain, $home );
                    if ( null === $r ) {
                        continue;
                    }
                    ++$out['records'];
                    if ( $r['created'] ) {
                        ++$out['new'];
                        ++$providerNew;
                        if ( $r['valuable'] ) {
                            ++$valuable;
                        }
                    }
                } catch ( \Throwable $e ) {
                    $this->logger->warning( 'monitoring', 'Backlink row import failed', [ 'provider' => $pid, 'source' => (string) ( $row['source_url'] ?? '' ), 'error' => $e->getMessage() ] );
                }
            }
            $this->logger->info( 'monitoring', 'Provider backlinks imported', [ 'provider' => $pid, 'records' => count( $rows ), 'new' => $providerNew ] );
        }

        update_option( self::OPTION_LAST_IMPORT, Repository::now(), false );
        $out['warning'] = implode( ' ', $warnings );

        $this->audit->record( AuditLog::currentActor(), 'backlinks.imported', 'backlink', 0, [ 'providers' => $out['providers'], 'records' => $out['records'], 'new' => $out['new'], 'valuable' => $valuable, 'warning' => $out['warning'] ] );
        if ( $out['new'] > 0 ) {
            $this->notifier->notify(
                'backlink',
                'info',
                'Backlink import complete',
                sprintf( '%d new backlink%s imported from %s (%d of them from high-authority domains). They will be verified by the monitor.', $out['new'], 1 === $out['new'] ? '' : 's', implode( ', ', $out['providers'] ), $valuable ),
                [ 'providers' => $out['providers'], 'records' => $out['records'], 'new' => $out['new'], 'valuable' => $valuable ]
            );
        }
        return $out;
    }

    /**
     * Adds GA4 referral sources that are not yet known backlink domains as pending links to verify.
     *
     * @return array{sources:int,new:int,warning:string}
     */
    public function importFromAnalytics( int $days = 28 ): array {
        $out  = [ 'sources' => 0, 'new' => 0, 'warning' => '' ];
        $days = max( 1, min( 365, $days ) );

        try {
            if ( ! $this->analytics->isConfigured() ) {
                $out['warning'] = 'Google Analytics 4 is not configured (service account JSON and GA4 property ID are required).';
                return $out;
            }
            $rows = $this->analytics->referralSources( $days );
            if ( empty( $rows ) ) {
                $err            = $this->analytics->lastError();
                $out['warning'] = '' !== $err ? 'GA4 returned no referral sources: ' . $err : sprintf( 'GA4 reported no referral sources in the last %d days.', $days );
                return $out;
            }
        } catch ( \Throwable $e ) {
            $out['warning'] = 'GA4 request failed: ' . $e->getMessage();
            $this->logger->error( 'monitoring', 'GA4 referral import failed', [ 'error' => $e->getMessage() ] );
            return $out;
        }

        $domain = $this->siteDomain();
        $home   = $this->homeUrl();
        $now    = Repository::now();
        $seen   = [];

        foreach ( $rows as $row ) {
            try {
                $source = strtolower( trim( (string) ( $row['source'] ?? '' ) ) );
                $host   = $this->referralHost( $source );
                if ( '' === $host ) {
                    continue;
                }
                $sourceDomain = Url::registrableDomain( $host );
                if ( '' === $sourceDomain || isset( $seen[ $sourceDomain ] ) || $sourceDomain === $domain ) {
                    continue;
                }
                $seen[ $sourceDomain ] = true;
                ++$out['sources'];

                if ( $this->backlinks->count( [ 'source_domain' => $sourceDomain ] ) > 0 ) {
                    continue;
                }

                $sessions = (int) ( $row['sessions'] ?? 0 );
                $prospect = $this->prospects->findByDomain( $sourceDomain );
                $data     = [
                    'origin'        => 'ga4_referral',
                    'status'        => Backlinks::STATUS_PENDING,
                    'anchor_text'   => '',
                    'placement'     => 'unknown',
                    'first_seen'    => $now,
                    'next_check_at' => $now,
                ];
                if ( $prospect ) {
                    $data['prospect_id'] = (int) $prospect['id'];
                }
                [ $id, $created ] = $this->backlinks->upsert( 'https://' . $host . '/', $home, $data );
                if ( ! $created ) {
                    continue;
                }
                ++$out['new'];
                $this->notifier->notify(
                    'backlink',
                    'info',
                    'New referral source',
                    sprintf( '%s sent %d referral session%s in the last %d days but is not a known backlink source yet. It was added for verification.', $host, $sessions, 1 === $sessions ? '' : 's', $days ),
                    [ 'backlink_id' => $id, 'source_domain' => $sourceDomain, 'sessions' => $sessions, 'users' => (int) ( $row['users'] ?? 0 ), 'prospect_id' => (int) ( $data['prospect_id'] ?? 0 ) ]
                );
            } catch ( \Throwable $e ) {
                $this->logger->warning( 'monitoring', 'GA4 referral row failed', [ 'source' => (string) ( $row['source'] ?? '' ), 'error' => $e->getMessage() ] );
            }
        }

        $this->audit->record( AuditLog::currentActor(), 'backlinks.ga4_imported', 'backlink', 0, [ 'days' => $days, 'sources' => $out['sources'], 'new' => $out['new'] ] );
        $this->logger->info( 'monitoring', 'GA4 referral sources imported', [ 'days' => $days, 'sources' => $out['sources'], 'new' => $out['new'] ] );
        return $out;
    }

    /**
     * @param array<string,mixed> $row Provider row: source_url, target_url, anchor, nofollow, first_seen, last_seen, source_authority.
     * @return array{id:int,created:bool,valuable:bool}|null
     */
    private function importProviderRow( array $row, string $providerId, string $domain, string $home ): ?array {
        $src = Url::normalize( (string) ( $row['source_url'] ?? '' ) );
        if ( '' === $src ) {
            return null;
        }
        $srcDomain = Url::registrableDomain( $src );
        if ( '' === $srcDomain || $srcDomain === $domain ) {
            return null; // Self-links are not backlinks.
        }
        $tgt = Url::normalize( (string) ( $row['target_url'] ?? '' ) );
        if ( '' === $tgt || ! Url::belongsTo( $tgt, $domain ) ) {
            $tgt = $home;
        }

        [ $id, $created ] = $this->backlinks->upsert( $src, $tgt, [] );
        if ( ! $created ) {
            return [ 'id' => $id, 'created' => false, 'valuable' => false ];
        }

        $now       = Repository::now();
        $anchor    = mb_substr( trim( preg_replace( '/\s+/u', ' ', (string) ( $row['anchor'] ?? '' ) ) ?? '' ), 0, 500 );
        $authority = isset( $row['source_authority'] ) && null !== $row['source_authority'] ? (int) $row['source_authority'] : null;
        $prospect  = $this->prospects->findByDomain( $srcDomain );
        $data      = [
            'anchor_text'   => $anchor,
            'rel'           => ! empty( $row['nofollow'] ) ? 'nofollow' : 'dofollow',
            'placement'     => 'unknown',
            'origin'        => mb_substr( 'provider:' . $providerId, 0, 40 ),
            'status'        => Backlinks::STATUS_PENDING,
            'first_seen'    => $this->providerDate( (string) ( $row['first_seen'] ?? '' ), $now ),
            'next_check_at' => $now,
            'updated_at'    => $now,
        ];
        if ( $prospect ) {
            $data['prospect_id'] = (int) $prospect['id'];
        }
        $this->backlinks->update( $id, $data );

        $valuable = null !== $authority && $authority >= self::HIGH_AUTHORITY;
        if ( $valuable ) {
            $this->notifier->notify(
                'backlink',
                'success',
                'New valuable backlink discovered',
                sprintf( '%s (authority %d) links to %s%s. Reported by %s; the monitor will confirm it on the next run.', $srcDomain, $authority, $tgt, '' !== $anchor ? sprintf( ' with anchor "%s"', mb_substr( $anchor, 0, 80 ) ) : '', $providerId ),
                [ 'backlink_id' => $id, 'source_domain' => $srcDomain, 'source_url' => $src, 'target_url' => $tgt, 'authority' => $authority, 'provider' => $providerId, 'prospect_id' => (int) ( $data['prospect_id'] ?? 0 ) ]
            );
        }
        return [ 'id' => $id, 'created' => true, 'valuable' => $valuable ];
    }

    /** Normalises a provider-supplied date to UTC "Y-m-d H:i:s", falling back when missing or implausible. */
    private function providerDate( string $value, string $fallback ): string {
        $value = trim( $value );
        if ( '' === $value ) {
            return $fallback;
        }
        $ts = strtotime( $value );
        if ( false === $ts || $ts > time() || $ts < 946684800 ) {
            return $fallback;
        }
        return gmdate( 'Y-m-d H:i:s', $ts );
    }

    /** Returns a usable hostname for a GA4 session source, or '' when it is not a referring website. */
    private function referralHost( string $source ): string {
        if ( '' === $source || in_array( $source, self::IGNORED_SOURCES, true ) || str_starts_with( $source, '(' ) ) {
            return '';
        }
        $host = preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $source ) ?? $source;
        $host = explode( '/', $host )[0];
        $host = explode( ':', $host )[0];
        $host = rtrim( $host, '.' );
        if ( ! preg_match( '/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $host ) ) {
            return '';
        }
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return '';
        }
        return $host;
    }

    private function siteDomain(): string {
        $d = $this->settings->siteDomain();
        return '' !== $d ? $d : Url::registrableDomain( home_url( '/' ) );
    }

    private function homeUrl(): string {
        $home = (string) $this->settings->get( 'site.home_url', '' );
        if ( '' === trim( $home ) ) {
            $home = home_url( '/' );
        }
        return Url::normalize( $home ) ?: $home;
    }
}
