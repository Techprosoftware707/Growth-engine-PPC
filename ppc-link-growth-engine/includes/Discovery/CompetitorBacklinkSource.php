<?php
/**
 * Mines competitors' backlink profiles (via configured providers) for directories and
 * resource pages that already link to similar Canadian research suppliers.
 *
 * Competitors: the owner's list (discovery.competitor_domains) plus the ones found automatically
 * (discovery.competitor_domains_auto). While Autopilot is on and the owner lists none, autoDetect() asks a
 * provider with competitors() (SE Ranking) at most once every 30 days, within the provider_lookups limit, and keeps
 * only real competitors: marketplaces, social networks, encyclopedias, news, government, the site itself and any
 * domain whose name reads as health, medical or pharmacy are dropped. A domain the owner removed from the automatic
 * list is never added back.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Discovery;

use PPC\LinkGrowth\Analysis\Taxonomy;
use PPC\LinkGrowth\Automation\Autopilot;
use PPC\LinkGrowth\Integrations\ProviderRegistry;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Support\Limits;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Url;

final class CompetitorBacklinkSource {

    public const LAST_RUN_OPTION = 'ppclg_competitor_scan_at';

    /** After a scan in which every provider call failed: do not retry before this time. */
    public const RETRY_OPTION = 'ppclg_competitor_scan_retry_at';

    /** Competitors already scanned in the current weekly scan (it pauses at the provider lookup limit). */
    public const SCAN_PROGRESS_OPTION = 'ppclg_competitor_scan_progress';

    private const RETRY_SECONDS = 6 * HOUR_IN_SECONDS;

    /** Last automatic competitor detection that got an answer (ISO time). */
    public const DETECT_OPTION = 'ppclg_competitor_detect_at';

    /** After a failed detection: do not ask again before this time. */
    public const DETECT_RETRY_OPTION = 'ppclg_competitor_detect_retry_at';

    /** Every domain ever added automatically (one the owner removed is not added back). */
    public const DETECT_SEEN_OPTION = 'ppclg_competitor_detect_seen';

    /** Days between automatic detections. */
    public const DETECT_INTERVAL_DAYS = 30;

    /** Most automatically found competitors kept. */
    public const AUTO_MAX = 10;

    /** Big platforms, news, reference and government sites that rank for everything (never "competitors"). */
    private const GIANTS = [
        'amazon', 'ebay', 'walmart', 'etsy', 'alibaba', 'aliexpress', 'temu', 'shein', 'costco', 'bestbuy', 'target', 'canadiantire', 'homedepot', 'staples', 'indiamart', 'made-in-china', 'globalsources', 'thomasnet', 'kijiji', 'craigslist',
        'facebook', 'instagram', 'twitter', 'x', 'linkedin', 'youtube', 'tiktok', 'pinterest', 'reddit', 'quora', 'medium', 'tumblr', 'snapchat', 'threads', 'discord', 'telegram', 'whatsapp',
        'wikipedia', 'wikimedia', 'wiktionary', 'wikihow', 'britannica', 'dictionary', 'merriam-webster', 'cambridge', 'investopedia', 'scribd', 'archive', 'researchgate', 'academia', 'sciencedirect', 'springer', 'nature', 'wiley', 'nih', 'ncbi',
        'google', 'bing', 'yahoo', 'duckduckgo', 'apple', 'microsoft', 'github', 'stackoverflow', 'yelp', 'tripadvisor', 'glassdoor', 'indeed', 'crunchbase', 'zoominfo', 'bloomberg', 'forbes', 'businessinsider',
        'cbc', 'ctvnews', 'globalnews', 'theglobeandmail', 'thestar', 'nationalpost', 'cp24', 'cnn', 'bbc', 'nytimes', 'washingtonpost', 'theguardian', 'reuters', 'apnews', 'foxnews', 'nbcnews', 'cbsnews', 'usatoday', 'huffpost', 'news',
        'canada', 'gc', 'ontario', 'gov',
    ];

    /** Words that make a domain read as health, medical or pharmacy (the claims filter and risk rules forbid that niche). */
    private const HEALTH_TERMS = [
        'pharm', 'pharmacy', 'drug', 'meds', 'medic', 'clinic', 'health', 'doctor', 'hospital', 'wellness', 'therapy', 'therap', 'telehealth', 'pill', 'nutrition', 'supplement', 'vitamin',
        'steroid', 'sarms', 'anabolic', 'longevity', 'antiaging', 'anti-aging', 'weightloss', 'weight-loss', 'semaglutide', 'tirzepatide', 'testosterone', 'hormone', 'medspa', 'ivtherapy', 'dental', 'derma', 'cosmetic', 'fitness', 'bodybuild', 'muscle',
    ];

    /** Whole labels that read as medical (too short to match inside other words). */
    private const HEALTH_LABELS = [ 'rx', 'md', 'trt', 'hrt', 'iv', 'med', 'care', 'cure', 'dr' ];

    public function __construct(
        private ProviderRegistry $registry,
        private Settings $settings,
        private Logger $logger,
        private ?Limits $limits = null,
        private ?Taxonomy $taxonomy = null,
        private ?AuditLog $audit = null
    ) {}

    /** @return string[] the owner's competitors plus the ones found automatically */
    public function competitors(): array {
        return array_values( array_unique( array_merge( $this->ownerCompetitors(), $this->autoCompetitors() ) ) );
    }

    /** @return string[] discovery.competitor_domains_auto */
    public function autoCompetitors(): array {
        return $this->clean( (array) $this->settings->get( 'discovery.competitor_domains_auto', [] ) );
    }

    /** @return string[] discovery.competitor_domains */
    public function ownerCompetitors(): array {
        return $this->clean( (array) $this->settings->get( 'discovery.competitor_domains', [] ) );
    }

    /** A configured provider that can list competitors (SE Ranking), or null. */
    public function detectionProvider(): ?object {
        try {
            foreach ( $this->registry->backlinkSources() as $p ) {
                if ( method_exists( $p, 'competitors' ) ) {
                    return $p;
                }
            }
        } catch ( \Throwable $e ) {
            unset( $e );
        }
        return null;
    }

    /**
     * Finds competitors automatically (Autopilot on, the owner's list empty, a provider with competitors()), at most
     * once every DETECT_INTERVAL_DAYS and within the provider_lookups limit. Returns null when nothing was due (not
     * applicable, or checked recently), else a plain report.
     *
     * @return array{ran:bool,reason:string,provider:string,found:int,added:string[],filtered:array<string,string>,competitors:string[],message:string}|null
     */
    public function autoDetect( bool $force = false ): ?array {
        $autopilot = new Autopilot( $this->settings );
        if ( ! $autopilot->isOn() || [] !== $this->ownerCompetitors() ) {
            return null;
        }
        $provider = $this->detectionProvider();
        $base     = [ 'ran' => false, 'reason' => '', 'provider' => '', 'found' => 0, 'added' => [], 'filtered' => [], 'competitors' => $this->autoCompetitors(), 'message' => '' ];
        if ( null === $provider ) {
            return null; // GrowthRun's needs-you list says which key unlocks discovery
        }
        $label            = method_exists( $provider, 'label' ) ? (string) $provider->label() : 'SE Ranking';
        $base['provider'] = $label;
        if ( ! $force ) {
            $retry = (string) get_option( self::DETECT_RETRY_OPTION, '' );
            if ( '' !== $retry && (int) strtotime( $retry ) > time() ) {
                return null;
            }
            $last = (string) get_option( self::DETECT_OPTION, '' );
            if ( '' !== $last && (int) strtotime( $last ) > time() - self::DETECT_INTERVAL_DAYS * DAY_IN_SECONDS ) {
                return null;
            }
        }
        $limits = $this->limits ?? new Limits( $this->settings );
        if ( ! $limits->allow( 'provider_lookups' ) ) {
            return array_merge( $base, [ 'reason' => 'provider_lookup_limit', 'message' => sprintf( 'Finding competitors through %s waits until tomorrow: today’s provider lookup limit is used up.', $label ) ] );
        }
        $own = $this->settings->siteDomain();
        $limits->spend( 'provider_lookups' );
        $list  = (array) $provider->competitors( $own );
        $error = method_exists( $provider, 'lastError' ) ? (string) $provider->lastError() : '';
        if ( [] === $list && '' !== $error ) {
            update_option( self::DETECT_RETRY_OPTION, gmdate( 'c', time() + self::RETRY_SECONDS ), false );
            $this->logger->warning( 'discovery', 'Automatic competitor detection failed: ' . $error );
            return array_merge( $base, [ 'reason' => 'provider_error', 'message' => sprintf( 'Could not find competitors through %s: %s It is tried again in a few hours.', $label, rtrim( $error, '.' ) . '.' ) ] );
        }
        update_option( self::DETECT_OPTION, gmdate( 'c' ), false );
        delete_option( self::DETECT_RETRY_OPTION );

        $seen     = array_map( 'strval', (array) get_option( self::DETECT_SEEN_OPTION, [] ) );
        $current  = $this->autoCompetitors();
        $filtered = [];
        $added    = [];
        foreach ( $list as $raw ) {
            $d = Url::registrableDomain( strtolower( trim( (string) $raw ) ) );
            if ( '' === $d || in_array( $d, $current, true ) || in_array( $d, $added, true ) ) {
                continue;
            }
            $why = $this->notACompetitor( $d, $own );
            if ( '' === $why && in_array( $d, $seen, true ) ) {
                $why = 'removed by you earlier';
            }
            if ( '' !== $why ) {
                $filtered[ $d ] = $why;
                continue;
            }
            if ( count( $current ) + count( $added ) >= self::AUTO_MAX ) {
                break;
            }
            $added[] = $d;
        }
        $next = array_values( array_unique( array_merge( $current, $added ) ) );
        if ( [] !== $added ) {
            $this->settings->set( 'discovery.competitor_domains_auto', $next );
            update_option( self::DETECT_SEEN_OPTION, array_values( array_unique( array_merge( $seen, $added ) ) ), false );
        }
        $this->recordAudit( 'discovery.competitors_detected', [ 'provider' => $label, 'found' => count( $list ), 'added' => $added, 'filtered' => count( $filtered ) ] );
        $this->logger->info( 'discovery', 'Automatic competitor detection finished.', [ 'found' => count( $list ), 'added' => $added, 'filtered' => $filtered ] );
        if ( [] === $next ) {
            $message = sprintf( '%s suggested %d %s, but none is a real competitor (%s), so competitor backlinks were not mined. Add competitor domains under Settings → Discovery.', $label, count( $list ), 1 === count( $list ) ? 'domain' : 'domains', [] === $filtered ? 'no ranking data for your domain' : 'marketplaces, media, government or health sites were left out' );
        } else {
            $message = sprintf(
                'Found %d %s automatically through %s: %s%s.',
                count( $added ),
                1 === count( $added ) ? 'competitor' : 'competitors',
                $label,
                [] === $added ? 'none new' : implode( ', ', array_slice( $added, 0, 6 ) ) . ( count( $added ) > 6 ? ', …' : '' ),
                [] !== $filtered ? sprintf( ' (%d left out: marketplaces, media, government or health sites)', count( $filtered ) ) : ''
            );
        }
        return [ 'ran' => true, 'reason' => 'detected', 'provider' => $label, 'found' => count( $list ), 'added' => $added, 'filtered' => $filtered, 'competitors' => $next, 'message' => $message ];
    }

    /** Why a domain is not used as a competitor ('' = it is). */
    public function notACompetitor( string $domain, string $own = '' ): string {
        $domain = Url::registrableDomain( strtolower( trim( $domain ) ) );
        if ( '' === $domain || ! str_contains( $domain, '.' ) ) {
            return 'not a domain';
        }
        $own = '' !== $own ? $own : $this->settings->siteDomain();
        if ( $domain === $own ) {
            return 'your own site';
        }
        if ( preg_match( '/(^|\.)(gov|gouv|gc\.ca|mil|edu)(\.[a-z]{2})?$/', $domain ) || preg_match( '/\.(on|qc|bc|ab|mb|sk|ns|nb|nl|pe|nt|yk|nu)\.ca$/', $domain ) ) {
            return 'government or education';
        }
        $labels = explode( '.', $domain );
        $name   = (string) $labels[0];
        if ( in_array( $name, self::GIANTS, true ) || in_array( $domain, $this->excludedPlatforms(), true ) ) {
            return 'marketplace, social network, reference, news or government site';
        }
        $parts = preg_split( '/[-.]/', $name ) ?: [];
        foreach ( $parts as $part ) {
            if ( in_array( $part, self::HEALTH_LABELS, true ) ) {
                return 'health, medical or pharmacy site';
            }
        }
        $flat = str_replace( '-', '', $name );
        foreach ( array_merge( self::HEALTH_TERMS, $this->riskTerms() ) as $term ) {
            $t = str_replace( [ ' ', '-' ], '', strtolower( (string) $term ) );
            if ( strlen( $t ) >= 4 && str_contains( $flat, $t ) ) {
                return 'health, medical or pharmacy site';
            }
        }
        return '';
    }

    /** @return string[] the risk analyser's spam / misuse terms (one-word forms) */
    private function riskTerms(): array {
        $taxonomy = $this->taxonomy;
        if ( null === $taxonomy ) {
            return [];
        }
        try {
            return array_merge( $taxonomy->negative( 'misuse' ), $taxonomy->negative( 'spam' ) );
        } catch ( \Throwable $e ) {
            return [];
        }
    }

    /** @return string[] data/excluded-domains.json */
    private function excludedPlatforms(): array {
        static $list = null;
        if ( null === $list ) {
            $file = defined( 'PPCLG_DIR' ) ? PPCLG_DIR . 'data/excluded-domains.json' : '';
            $data = '' !== $file && is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : []; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $list = array_values( array_map( static fn( $d ): string => strtolower( trim( (string) $d ) ), is_array( $data ) ? $data : [] ) );
        }
        return $list;
    }

    /** @param array<string,mixed> $details */
    private function recordAudit( string $action, array $details ): void {
        try {
            ( $this->audit ?? new AuditLog() )->record( Autopilot::ACTOR, $action, 'discovery', 0, $details );
        } catch ( \Throwable $e ) {
            unset( $e );
        }
    }

    /**
     * @param array<mixed> $raw
     * @return string[]
     */
    private function clean( array $raw ): array {
        $out = [];
        foreach ( $raw as $d ) {
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
        $source   = $sources[0]; // one provider is enough; avoid double credit spend.
        $errors   = [];
        $limits   = $this->limits ?? new Limits( $this->settings );
        $progress = get_option( self::SCAN_PROGRESS_OPTION, [] );
        $done     = is_array( $progress ) ? array_map( 'strval', (array) ( $progress['done'] ?? [] ) ) : [];
        // A scan paused at the lookup limit resumes where it stopped; one with data from earlier competitors counts.
        $anyOk    = is_array( $progress ) && ! empty( $progress['ok'] );
        $paused   = false;
        foreach ( $comps as $comp ) {
            if ( in_array( $comp, $done, true ) ) {
                continue;
            }
            // Every competitor is one billable provider request: it counts against the daily provider lookup limit
            // like every other provider call, and the scan continues on a later run when the limit is reached.
            if ( ! $limits->allow( 'provider_lookups' ) ) {
                $paused = true;
                break;
            }
            $limits->spend( 'provider_lookups' );
            $stats['competitors']++;
            $done[] = $comp;
            $links  = $source->backlinks( $comp, $perCompetitor, 1 );
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
        if ( $paused ) {
            update_option( self::SCAN_PROGRESS_OPTION, [ 'done' => $done, 'ok' => $anyOk ], false );
            $stats['warning'] = trim( $stats['warning'] . ' The competitor backlink scan paused at today’s provider lookup limit; it continues on the next run.' );
        } elseif ( $anyOk ) {
            // Only a scan that actually got data starts the weekly interval.
            update_option( self::LAST_RUN_OPTION, gmdate( 'c' ), false );
            delete_option( self::RETRY_OPTION );
            delete_option( self::SCAN_PROGRESS_OPTION );
        } else {
            delete_option( self::SCAN_PROGRESS_OPTION );
            update_option( self::RETRY_OPTION, gmdate( 'c', time() + self::RETRY_SECONDS ), false );
        }
        if ( [] !== $errors ) {
            $stats['warning'] = trim( sprintf( 'Backlink provider "%s" failed for %s: %s', $source->id(), implode( ', ', array_keys( $errors ) ), (string) reset( $errors ) ) . ' ' . $stats['warning'] );
        }
        $this->logger->info( 'discovery', 'Competitor backlink scan finished.', $stats );
        return $stats;
    }
}
