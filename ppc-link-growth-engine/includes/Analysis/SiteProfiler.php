<?php
/**
 * Builds a SiteProfile by fetching the homepage and a few key pages (politely).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Analysis;

use PPC\LinkGrowth\Integrations\Rdap;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Url;

final class SiteProfiler {

    /**
     * Pages a deferred evaluation already fetched (transient per domain, 1 day): the next run reuses them and fetches
     * only what is still missing, so a slow host or a long Crawl-delay is evaluated over a few runs that each end
     * within the time budget, with the same pages as before.
     */
    public const CARRY_PREFIX = 'ppclg_profile_carry_';

    /** After this many deferred runs the site is judged on what could be fetched. */
    public const MAX_DEFERRALS = 3;

    /** A page larger than this (JSON) is fetched again instead of being kept between runs (keeps the transient small). */
    private const CARRY_MAX_BYTES = 65536;

    public function __construct(
        private Client $client,
        private PageAnalyzer $analyzer,
        private ContactExtractor $contacts,
        private FormDetector $forms,
        private Rdap $rdap,
        private Logger $logger,
        private Settings $settings
    ) {}

    public function profile( string $domain, string $homepageUrl = '', int $maxExtraPages = 2 ): SiteProfile {
        $p         = new SiteProfile();
        $p->domain = Url::registrableDomain( $domain );
        $tryUrls   = array_values( array_unique( array_filter( [ $homepageUrl, 'https://' . $p->domain . '/', 'https://www.' . $p->domain . '/', 'http://' . $p->domain . '/' ] ) ) );
        $carry     = $this->carried( $p->domain );
        $p->deferrals = (int) ( $carry['deferrals'] ?? 0 );
        $p->carried   = [] !== $carry;
        foreach ( (array) ( $carry['tried'] ?? [] ) as $triedUrl => $triedError ) {
            $p->tried[ (string) $triedUrl ] = (string) $triedError;
        }

        $home = null;
        if ( isset( $carry['home']['page'] ) && is_array( $carry['home']['page'] ) ) {
            $home           = self::import( $carry['home']['page'] );
            $p->homepageUrl = (string) ( $carry['home']['final'] ?? '' );
            $p->fetches++;
        }
        foreach ( null === $home ? $tryUrls : [] as $url ) {
            if ( isset( $p->tried[ $url ] ) ) {
                // Failed for real in an earlier run of this deferred evaluation: the next address is tried instead.
                $p->error = $p->tried[ $url ];
                $p->fetches++;
                if ( $p->fetches >= 3 ) {
                    break;
                }
                continue;
            }
            $resp = $this->client->crawl( $url, [ 'cache' => true ] );
            $p->fetches++;
            if ( Client::DEADLINE === $resp->errorCode ) {
                $p->deferred = true; // the run's time is up: EvaluateJob tries the site again later
                $p->error    = (string) $resp->error;
                break;
            }
            if ( 'robots_disallow' === $resp->errorCode ) {
                $p->error = 'robots_disallow';
                break;
            }
            if ( $resp->ok() && $resp->isHtml() ) {
                $home = $this->analyzer->fromResponse( $resp );
                $p->homepageUrl = $resp->finalUrl ?: $url;
                if ( empty( $carry['home_seen'] ) ) {
                    // Runs that stopped before the homepage came in (see waitsForHome()) do not count against the
                    // pages after it: those get their own MAX_DEFERRALS runs, so the site is judged on them too.
                    $p->deferrals = 0;
                }
                break;
            }
            if ( $resp->error ) {
                $p->error = $resp->error;
            } elseif ( $resp->status > 0 ) {
                $p->error = 'HTTP ' . $resp->status;
            }
            $p->tried[ $url ] = $p->error;
            if ( $p->fetches >= 3 ) {
                break;
            }
        }
        if ( ! $home ) {
            $p->reachable = false;
            return $p;
        }
        $p->reachable = true;
        $p->https     = str_starts_with( $p->homepageUrl, 'https://' );
        $p->home      = $home;
        $p->name      = $this->siteName( $home, $p->domain );
        $p->description = $home->metaDescription;
        $p->language  = strtolower( substr( $home->lang, 0, 2 ) );
        if ( $home->isWordPress ) {
            $p->technologies[] = 'WordPress';
        }

        // Extra pages: contact / about (max N fetches). The contact page is fetched first: it is where
        // outreach addresses usually live, so about/team links listed before it must not crowd it out. Under a
        // job's time budget a page that no longer fits (a slow host, a long Crawl-delay) is skipped: the site is
        // judged on the pages fetched, as when the submission-page finder is skipped for lack of time.
        $about = [];
        foreach ( $home->links as $l ) {
            if ( $l['external'] ) {
                continue;
            }
            $path = strtolower( Url::path( $l['url'] ) );
            if ( '' === $p->contactUrl && preg_match( '#/(contact|contact-us|contactus|get-in-touch)/?$#', $path ) ) {
                $p->contactUrl = $l['url'];
            } elseif ( preg_match( '#/(about|about-us|aboutus|who-we-are|our-team|team|editorial-team|staff)/?$#', $path ) ) {
                $about[] = $l['url'];
            }
        }
        $extra = array_values( array_unique( array_merge( '' !== $p->contactUrl ? [ $p->contactUrl ] : [], $about ) ) );
        foreach ( array_slice( $extra, 0, max( 0, $maxExtraPages ) ) as $url ) {
            if ( isset( $carry['pages'][ $url ] ) && is_array( $carry['pages'][ $url ] ) ) {
                $p->pages[] = self::import( $carry['pages'][ $url ] );
                $p->fetches++;
                continue;
            }
            $resp = $this->client->crawl( $url, [ 'cache' => true ] );
            $p->fetches++;
            if ( $resp->ok() && $resp->isHtml() ) {
                $p->pages[] = $this->analyzer->fromResponse( $resp );
            }
        }

        $p->contacts = $this->contacts->extract( array_merge( [ $home ], $p->pages ), $p->domain );

        // Country / geo.
        [ $p->country, $p->countryConfidence ] = $this->guessCountry( $home, $p->pages, $p->domain );

        // Content recency.
        $dates = array_filter( array_merge( [ $home->dateModified, $home->datePublished ], array_map( static fn( PageAnalysis $x ) => $x->dateModified, $p->pages ) ) );
        if ( ! empty( $dates ) ) {
            rsort( $dates );
            $p->lastContentDate = substr( (string) $dates[0], 0, 10 );
        }

        // Domain age via RDAP (free, no key).
        if ( $this->settings->get( 'providers.rdap.enabled', true ) ) {
            $reg = $this->rdap->registrationDate( $p->domain );
            if ( $reg ) {
                $p->registrationDate = $reg;
                $p->domainAgeYears   = round( ( time() - strtotime( $reg ) ) / YEAR_IN_SECONDS, 1 );
            }
        }
        return $p;
    }

    /**
     * Keeps the pages $p fetched for the next run of a deferred evaluation (EvaluateJob, when the run's time ran out
     * before every page, the domain age or the metrics could be read).
     */
    public function carry( SiteProfile $p ): void {
        if ( '' === $p->domain ) {
            return;
        }
        $data = [ 'deferrals' => $p->deferrals + 1, 'pages' => [], 'tried' => null === $p->home ? $p->tried : [], 'home_seen' => null !== $p->home ];
        if ( null !== $p->home ) {
            $page = self::export( $p->home );
            if ( null !== $page ) {
                $data['home'] = [ 'final' => $p->homepageUrl, 'page' => $page ];
            }
        }
        foreach ( $p->pages as $page ) {
            $vars = self::export( $page );
            if ( null !== $vars && '' !== $page->url ) {
                $data['pages'][ $page->url ] = $vars;
            }
        }
        set_transient( self::CARRY_PREFIX . md5( $p->domain ), $data, DAY_IN_SECONDS );
    }

    /**
     * Whether earlier runs of a deferred evaluation of $domain already stopped MAX_DEFERRALS times without its homepage
     * (none is kept): EvaluateJob then asks the site only in a run with time for the homepage's whole timeout.
     */
    public function waitsForHome( string $domain ): bool {
        $carry = $this->carried( Url::registrableDomain( $domain ) );
        return (int) ( $carry['deferrals'] ?? 0 ) >= self::MAX_DEFERRALS && empty( $carry['home_seen'] ) && ! isset( $carry['home']['page'] );
    }

    /** Removes what a deferred run kept for $p's domain, once the evaluation is stored. */
    public function forget( SiteProfile $p ): void {
        if ( $p->carried && '' !== $p->domain ) {
            delete_transient( self::CARRY_PREFIX . md5( $p->domain ) );
        }
    }

    /** @return array<string,mixed> */
    private function carried( string $domain ): array {
        if ( '' === $domain ) {
            return [];
        }
        $data = get_transient( self::CARRY_PREFIX . md5( $domain ) );
        return is_array( $data ) ? $data : [];
    }

    /** @return array<string,mixed>|null the page's fields, null when too large to keep */
    private static function export( PageAnalysis $page ): ?array {
        $vars = get_object_vars( $page );
        return strlen( (string) wp_json_encode( $vars ) ) > self::CARRY_MAX_BYTES ? null : $vars;
    }

    /** @param array<string,mixed> $vars */
    private static function import( array $vars ): PageAnalysis {
        $page = new PageAnalysis();
        foreach ( $vars as $key => $value ) {
            if ( ! property_exists( $page, (string) $key ) ) {
                continue;
            }
            try {
                $page->{$key} = $value;
            } catch ( \TypeError $e ) {
                unset( $e ); // a field whose type changed between versions keeps its default
            }
        }
        return $page;
    }

    private function siteName( PageAnalysis $home, string $domain ): string {
        if ( '' !== $home->siteName ) {
            return mb_substr( $home->siteName, 0, 120 );
        }
        foreach ( $home->jsonLd as $ld ) {
            $nodes = isset( $ld['@graph'] ) && is_array( $ld['@graph'] ) ? $ld['@graph'] : [ $ld ];
            foreach ( $nodes as $n ) {
                if ( is_array( $n ) && isset( $n['@type'], $n['name'] ) && in_array( $n['@type'], [ 'Organization', 'WebSite', 'LocalBusiness', 'NewsMediaOrganization', 'EducationalOrganization', 'GovernmentOrganization', 'NGO' ], true ) && is_string( $n['name'] ) ) {
                    return mb_substr( $n['name'], 0, 120 );
                }
            }
        }
        $title = $home->title;
        if ( '' !== $title ) {
            $parts = preg_split( '/\s+[\|\-–—:·»]\s+/u', $title ) ?: [ $title ];
            usort( $parts, static fn( $a, $b ) => mb_strlen( $a ) <=> mb_strlen( $b ) );
            $short = trim( $parts[0] );
            if ( mb_strlen( $short ) >= 3 && mb_strlen( $short ) <= 60 ) {
                return $short;
            }
            return mb_substr( $title, 0, 120 );
        }
        return $domain;
    }

    /** ISO 3166-1 alpha-2 codes for full country names commonly used in schema.org addressCountry. */
    private const COUNTRY_NAMES = [
        'united states'            => 'US',
        'united states of america' => 'US',
        'usa'                      => 'US',
        'u.s.a.'                   => 'US',
        'u.s.'                     => 'US',
        'united kingdom'           => 'GB',
        'great britain'            => 'GB',
        'britain'                  => 'GB',
        'england'                  => 'GB',
        'scotland'                 => 'GB',
        'wales'                    => 'GB',
        'northern ireland'         => 'GB',
        'uk'                       => 'GB',
        'ireland'                  => 'IE',
        'australia'                => 'AU',
        'new zealand'              => 'NZ',
        'germany'                  => 'DE',
        'france'                   => 'FR',
        'switzerland'              => 'CH',
        'austria'                  => 'AT',
        'netherlands'              => 'NL',
        'the netherlands'          => 'NL',
        'belgium'                  => 'BE',
        'spain'                    => 'ES',
        'portugal'                 => 'PT',
        'italy'                    => 'IT',
        'sweden'                   => 'SE',
        'norway'                   => 'NO',
        'denmark'                  => 'DK',
        'finland'                  => 'FI',
        'poland'                   => 'PL',
        'india'                    => 'IN',
        'china'                    => 'CN',
        'japan'                    => 'JP',
        'south korea'              => 'KR',
        'singapore'                => 'SG',
        'mexico'                   => 'MX',
        'brazil'                   => 'BR',
        'south africa'             => 'ZA',
        'israel'                   => 'IL',
        'united arab emirates'     => 'AE',
    ];

    /** Maps a schema.org addressCountry value (ISO code or English name) to an ISO alpha-2 code, or '' when unknown. */
    private function countryCode( string $value ): string {
        $value = trim( $value );
        if ( preg_match( '/^[A-Za-z]{2}$/', $value ) ) {
            $code = strtoupper( $value );
            return 'UK' === $code ? 'GB' : $code;
        }
        return self::COUNTRY_NAMES[ strtolower( preg_replace( '/\s+/', ' ', $value ) ?? $value ) ] ?? '';
    }

    /**
     * @param PageAnalysis[] $pages
     * @return array{0:string,1:int}
     */
    private function guessCountry( PageAnalysis $home, array $pages, string $domain ): array {
        $score = 0;
        if ( str_ends_with( $domain, '.ca' ) ) {
            $score += 60;
        }
        $text = mb_strtolower( $home->text . ' ' . implode( ' ', array_map( static fn( PageAnalysis $p ) => $p->text, $pages ) ) );
        foreach ( $home->jsonLd as $ld ) {
            $json = wp_json_encode( $ld );
            if ( preg_match( '/"addressCountry"\s*:\s*"(CA|Canada)"/i', (string) $json ) ) {
                $score += 50;
            } elseif ( preg_match( '/"addressCountry"\s*:\s*"([A-Z]{2}|[A-Za-z .]+)"/', (string) $json, $m ) && ! in_array( strtoupper( $m[1] ), [ 'CA', 'CANADA' ], true ) ) {
                $other = $this->countryCode( $m[1] );
                if ( '' !== $other ) {
                    return [ $other, 70 ];
                }
                // Unrecognised country name: ignore it rather than truncating it into a wrong ISO code.
            }
        }
        $hits = preg_match_all( '/\b(canada|canadian|ontario|toronto|vancouver|montreal|calgary|ottawa|edmonton|winnipeg|halifax|quebec|british columbia|alberta|manitoba|saskatchewan|nova scotia)\b/u', $text );
        $score += min( 40, $hits * 4 );
        if ( preg_match( '/\b[ABCEGHJ-NPRSTVXY]\d[ABCEGHJ-NPRSTV-Z][ -]?\d[ABCEGHJ-NPRSTV-Z]\d\b/', mb_strtoupper( $text ) ) ) {
            $score += 25;
        }
        $us = preg_match_all( '/\b(united states|usa|u\.s\.|california|texas|new york|florida|illinois|washington dc)\b/u', $text );
        $uk = preg_match_all( '/\b(united kingdom|england|london|scotland|wales)\b/u', $text );
        $au = preg_match_all( '/\b(australia|sydney|melbourne|brisbane)\b/u', $text );
        if ( $score >= 40 ) {
            return [ 'CA', min( 100, $score ) ];
        }
        if ( str_ends_with( $domain, '.co.uk' ) || str_ends_with( $domain, '.uk' ) || $uk > 4 ) {
            return [ 'GB', 60 ];
        }
        if ( str_ends_with( $domain, '.com.au' ) || str_ends_with( $domain, '.au' ) || $au > 4 ) {
            return [ 'AU', 60 ];
        }
        if ( str_ends_with( $domain, '.us' ) || str_ends_with( $domain, '.gov' ) || $us > 4 ) {
            return [ 'US', 50 ];
        }
        if ( $score > 0 ) {
            return [ 'CA', $score ];
        }
        return [ '', 0 ];
    }
}
