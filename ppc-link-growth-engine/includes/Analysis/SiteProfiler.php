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

        $home = null;
        foreach ( $tryUrls as $url ) {
            $resp = $this->client->crawl( $url, [ 'cache' => true ] );
            $p->fetches++;
            if ( 'robots_disallow' === $resp->errorCode ) {
                $p->error = 'robots_disallow';
                break;
            }
            if ( $resp->ok() && $resp->isHtml() ) {
                $home = $this->analyzer->fromResponse( $resp );
                $p->homepageUrl = $resp->finalUrl ?: $url;
                break;
            }
            if ( $resp->error ) {
                $p->error = $resp->error;
            } elseif ( $resp->status > 0 ) {
                $p->error = 'HTTP ' . $resp->status;
            }
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

        // Extra pages: contact / about (max N fetches).
        $extra = [];
        foreach ( $home->links as $l ) {
            if ( $l['external'] ) {
                continue;
            }
            $path = strtolower( Url::path( $l['url'] ) );
            if ( '' === $p->contactUrl && preg_match( '#/(contact|contact-us|contactus|get-in-touch)/?$#', $path ) ) {
                $p->contactUrl = $l['url'];
                $extra[]       = $l['url'];
            } elseif ( preg_match( '#/(about|about-us|aboutus|who-we-are|our-team|team|editorial-team|staff)/?$#', $path ) && count( $extra ) < $maxExtraPages ) {
                $extra[] = $l['url'];
            }
        }
        foreach ( array_slice( array_unique( $extra ), 0, $maxExtraPages ) as $url ) {
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
            } elseif ( preg_match( '/"addressCountry"\s*:\s*"([A-Z]{2}|[A-Za-z ]+)"/', (string) $json, $m ) && ! in_array( strtoupper( $m[1] ), [ 'CA', 'CANADA' ], true ) ) {
                $other = strtoupper( substr( $m[1], 0, 2 ) );
                return [ $other, 70 ];
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
