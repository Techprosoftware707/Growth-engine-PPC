<?php
/**
 * Mines competitors' backlink profiles (via configured providers) for directories and
 * resource pages that already link to similar Canadian research suppliers.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Discovery;

use PPC\LinkGrowth\Integrations\ProviderRegistry;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Url;

final class CompetitorBacklinkSource {

    public const LAST_RUN_OPTION = 'ppclg_competitor_scan_at';

    /** After a scan in which every provider call failed: do not retry before this time. */
    public const RETRY_OPTION = 'ppclg_competitor_scan_retry_at';

    private const RETRY_SECONDS = 6 * HOUR_IN_SECONDS;

    public function __construct( private ProviderRegistry $registry, private Settings $settings, private Logger $logger ) {}

    /** @return string[] */
    public function competitors(): array {
        $out = [];
        foreach ( (array) $this->settings->get( 'discovery.competitor_domains', [] ) as $d ) {
            $d = Url::registrableDomain( strtolower( trim( (string) $d ) ) );
            if ( '' !== $d && $d !== $this->settings->siteDomain() ) {
                $out[] = $d;
            }
        }
        return array_values( array_unique( $out ) );
    }

    public function isDue( int $intervalDays = 7 ): bool {
        $retry = (string) get_option( self::RETRY_OPTION, '' );
        if ( '' !== $retry && (int) strtotime( $retry ) > time() ) {
            return false;
        }
        $last = (string) get_option( self::LAST_RUN_OPTION, '' );
        return '' === $last || strtotime( $last ) < time() - $intervalDays * DAY_IN_SECONDS;
    }

    /** @return array{competitors:int,backlinks:int,created:int,skipped:int,warning:string} */
    public function discover( ProspectIntake $intake, int $perCompetitor = 150 ): array {
        $stats   = [ 'competitors' => 0, 'backlinks' => 0, 'created' => 0, 'skipped' => 0, 'warning' => '' ];
        $sources = $this->registry->backlinkSources();
        $comps   = $this->competitors();
        if ( empty( $comps ) ) {
            $stats['warning'] = 'No competitor domains configured (Settings → Discovery).';
            return $stats;
        }
        if ( empty( $sources ) ) {
            $stats['warning'] = 'No backlink data provider configured (SE Ranking, Ahrefs or Semrush).';
            return $stats;
        }
        $source = $sources[0]; // one provider is enough; avoid double credit spend.
        $errors = [];
        $anyOk  = false;
        foreach ( $comps as $comp ) {
            $stats['competitors']++;
            $links = $source->backlinks( $comp, $perCompetitor, 1 );
            $error = method_exists( $source, 'lastError' ) ? (string) $source->lastError() : '';
            if ( empty( $links ) && '' !== $error ) {
                $errors[ $comp ] = $error; // the call failed: not "no backlinks"
                continue;
            }
            $anyOk = true;
            $stats['backlinks'] += count( $links );
            foreach ( $links as $bl ) {
                $src  = $bl['source_url'];
                $path = strtolower( Url::path( $src ) );
                $opp  = 'editorial';
                if ( preg_match( '#director|listing|business|suppliers?|vendors?|members?|companies#', $path ) ) {
                    $opp = 'directory_listing';
                } elseif ( preg_match( '#resource|links|useful|reading#', $path ) ) {
                    $opp = 'resource_page';
                }
                $r = $intake->fromUrl( $src, 'competitor_backlinks', $comp, [ 'opportunity_type' => $opp, 'title' => '', 'details' => [ 'competitor' => $comp, 'anchor' => $bl['anchor'], 'nofollow' => $bl['nofollow'], 'source_authority' => $bl['source_authority'] ] ] );
                if ( ! $r || null !== $r['skipped'] ) {
                    $stats['skipped']++;
                } elseif ( $r['created'] ) {
                    $stats['created']++;
                }
            }
        }
        if ( $anyOk ) {
            // Only a scan that actually got data starts the weekly interval.
            update_option( self::LAST_RUN_OPTION, gmdate( 'c' ), false );
            delete_option( self::RETRY_OPTION );
        } else {
            update_option( self::RETRY_OPTION, gmdate( 'c', time() + self::RETRY_SECONDS ), false );
        }
        if ( [] !== $errors ) {
            $stats['warning'] = sprintf( 'Backlink provider "%s" failed for %s: %s', $source->id(), implode( ', ', array_keys( $errors ) ), (string) reset( $errors ) );
        }
        $this->logger->info( 'discovery', 'Competitor backlink scan finished.', $stats );
        return $stats;
    }
}
