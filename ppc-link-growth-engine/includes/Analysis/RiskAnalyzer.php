<?php
/**
 * Spam / link-farm / PBN / hacked-site / misuse risk detection.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Analysis;

use PPC\LinkGrowth\Support\Str;

final class RiskAnalyzer {

    private const RISKY_TLDS = [ '.xyz', '.top', '.icu', '.cf', '.tk', '.ml', '.ga', '.gq', '.work', '.click', '.link', '.buzz', '.cyou', '.rest', '.monster' ];

    public function __construct( private Taxonomy $taxonomy ) {}

    /**
     * @param array{negative?:array<string,int>,misuse?:array<string,int>} $relevance
     * @return array{score:int,flags:string[],hard_reject:bool,reasons:string[]}
     */
    public function analyze( SiteProfile $profile, array $relevance = [] ): array {
        $flags   = [];
        $reasons = [];
        $risk    = 0;
        $home    = $profile->home;

        if ( ! $profile->reachable || ! $home ) {
            return [ 'score' => 100, 'flags' => [ 'unreachable' ], 'hard_reject' => false, 'reasons' => [ 'Site could not be fetched: ' . ( $profile->error ?: 'unknown error' ) ] ];
        }

        $text    = mb_strtolower( $home->title . ' ' . $home->headings() . ' ' . mb_substr( $home->text, 0, 30000 ) );
        $anchors = mb_strtolower( implode( ' ', array_map( static fn( $l ) => $l['anchor'], $home->links ) ) );

        // Parked / for-sale domains.
        if ( preg_match( '/domain (is )?for sale|buy this domain|this domain may be for sale|parked (domain|free)|domain parking|hugedomains|sedo\.com|dan\.com\/buy/i', $text . ' ' . $anchors ) ) {
            $flags[]   = 'parked_domain';
            $reasons[] = 'Domain appears parked or for sale.';
            $risk     += 100;
        }

        // Spam / adult / gambling signals in text and anchors.
        $spamHits = 0;
        foreach ( $this->taxonomy->negative( 'spam' ) as $term ) {
            $spamHits += Str::countPhrase( $text, $term ) + 2 * Str::countPhrase( $anchors, $term );
        }
        if ( $spamHits >= 1 ) {
            $flags[] = 'spam_terms';
            $risk   += min( 60, 12 * $spamHits );
            $reasons[] = sprintf( '%d spam/off-niche term hit(s) (casino, adult, pharma, SEO link sales…).', $spamHits );
        }

        // Link selling footprints.
        if ( preg_match( '/sponsored post(s)? (price|pricing|rates)|buy (a )?(guest post|backlink)|guest post (price|pricing|packages)|do-?follow (link|backlink)s? for sale|link insertion (price|pricing)|niche edits? (price|pricing)|write for us.*(fee|paid|price)/i', $text ) ) {
            $flags[]   = 'link_selling';
            $risk     += 45;
            $reasons[] = 'Site advertises paid links / sponsored posts (manipulative link pattern).';
        }

        // Excessive outbound linking.
        $external = $home->externalLinks();
        $extCount = count( $external );
        $words    = max( 1, $home->wordCount );
        $isDirectoryLike = (bool) preg_match( '/directory|listings?|links?|resources?/i', $home->title . ' ' . implode( ' ', $home->h1 ) );
        if ( $extCount > 200 && ! $isDirectoryLike ) {
            $flags[]   = 'excessive_outbound_links';
            $risk     += 25;
            $reasons[] = sprintf( '%d external links on the homepage.', $extCount );
        } elseif ( $extCount / $words > 0.12 && $extCount > 40 && ! $isDirectoryLike ) {
            $flags[]   = 'outbound_density';
            $risk     += 15;
            $reasons[] = 'Very high ratio of external links to content.';
        }

        // Many unrelated external niches (PBN / link farm footprint).
        $nicheHits = 0;
        foreach ( [ 'casino', 'loan', 'crypto', 'essay', 'vape', 'cbd', 'dating', 'plumber', 'roofing', 'lawyer', 'real estate', 'seo', 'hosting', 'vpn', 'forex', 'betting', 'escort', 'pharmacy' ] as $niche ) {
            if ( str_contains( $anchors, $niche ) ) {
                $nicheHits++;
            }
        }
        if ( $nicheHits >= 4 ) {
            $flags[]   = 'pbn_footprint';
            $risk     += 50;
            $reasons[] = 'Outbound anchors span many unrelated commercial niches (link farm / PBN pattern).';
        }

        // Hidden links.
        $hidden = count( array_filter( $home->links, static fn( $l ) => $l['hidden'] && $l['external'] ) );
        if ( $hidden >= 3 ) {
            $flags[]   = 'hidden_links';
            $risk     += 40;
            $reasons[] = sprintf( '%d hidden external links detected.', $hidden );
        }

        // Hacked / injected content signals.
        if ( preg_match( '/[\x{3040}-\x{30ff}\x{4e00}-\x{9faf}]{6,}/u', $home->title ) && ! preg_match( '/\.(jp|cn|tw|hk|kr)$/', $profile->domain ) && 'ja' !== $profile->language && 'zh' !== $profile->language ) {
            $flags[]   = 'hacked_keywords';
            $risk     += 60;
            $reasons[] = 'Title contains injected foreign keyword spam.';
        }

        // Misuse / wrong-audience signals (bodybuilding, clinics, dosing).
        $misuse = array_sum( $relevance['misuse'] ?? [] );
        if ( $misuse >= 3 ) {
            $flags[]   = 'misuse_context';
            $risk     += 30;
            $reasons[] = 'Content targets non-research use (bodybuilding / clinics / dosing), which conflicts with research-use-only positioning.';
        }

        // Thin site.
        if ( $home->wordCount < 80 && count( $home->links ) < 8 ) {
            $flags[]   = 'thin_content';
            $risk     += 20;
            $reasons[] = 'Homepage has almost no content.';
        }

        // Sitewide noindex / unindexable.
        if ( ! $home->isIndexable() ) {
            $flags[]   = 'noindex';
            $risk     += 20;
            $reasons[] = 'Homepage is not indexable (noindex / X-Robots-Tag).';
        }

        // Ad-heavy.
        if ( $home->adSignals >= 6 ) {
            $flags[]   = 'ad_heavy';
            $risk     += 10;
            $reasons[] = 'Heavy third-party advertising footprint.';
        }

        // Risky TLDs & very young domains.
        foreach ( self::RISKY_TLDS as $tld ) {
            if ( str_ends_with( $profile->domain, $tld ) ) {
                $flags[]   = 'risky_tld';
                $risk     += 15;
                $reasons[] = 'Low-trust top-level domain.';
                break;
            }
        }
        if ( null !== $profile->domainAgeYears && $profile->domainAgeYears < 1 ) {
            $flags[]   = 'young_domain';
            $risk     += 10;
            $reasons[] = 'Domain registered less than a year ago.';
        }

        // Language.
        if ( '' !== $profile->language && ! in_array( $profile->language, [ 'en', 'fr' ], true ) ) {
            $flags[]   = 'language_mismatch';
            $risk     += 20;
            $reasons[] = sprintf( 'Primary language "%s" is neither English nor French.', $profile->language );
        }

        if ( ! $profile->https ) {
            $flags[]   = 'no_https';
            $risk     += 5;
        }

        $hard = (bool) array_intersect( $flags, [ 'parked_domain', 'pbn_footprint', 'hacked_keywords', 'hidden_links' ] ) || ( in_array( 'spam_terms', $flags, true ) && $spamHits >= 4 ) || in_array( 'link_selling', $flags, true ) && $spamHits >= 1;

        return [
            'score'       => max( 0, min( 100, $risk ) ),
            'flags'       => array_values( array_unique( $flags ) ),
            'hard_reject' => $hard,
            'reasons'     => $reasons,
        ];
    }
}
