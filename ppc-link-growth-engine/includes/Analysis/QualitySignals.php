<?php
/**
 * Editorial-quality and real-audience signals derived from fetched pages.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Analysis;

final class QualitySignals {

    /**
     * @return array{score:int,signals:array<string,mixed>}
     */
    public static function editorial( SiteProfile $profile ): array {
        $home = $profile->home;
        if ( ! $home ) {
            return [ 'score' => 0, 'signals' => [] ];
        }
        $s     = [];
        $score = 0.0;

        // Content depth (homepage + sampled pages).
        $words = $home->wordCount + array_sum( array_map( static fn( PageAnalysis $p ) => $p->wordCount, $profile->pages ) );
        $s['words_sampled'] = $words;
        $score += min( 25, $words / 60 );

        // Structure: title, description, headings.
        $s['has_title']       = '' !== $home->title;
        $s['has_description'] = '' !== $home->metaDescription;
        $s['has_h1']          = ! empty( $home->h1 );
        $score += ( $s['has_title'] ? 5 : 0 ) + ( $s['has_description'] ? 5 : 0 ) + ( $s['has_h1'] ? 5 : 0 );

        // Trust pages.
        $s['has_about']   = $home->hasPathLink( '#/(about|about-us|who-we-are|team)#i' );
        $s['has_contact'] = '' !== $profile->contactUrl || $home->hasPathLink( '#/contact#i' );
        $s['has_privacy'] = $home->hasPathLink( '#/(privacy|privacy-policy|terms)#i' );
        $score += ( $s['has_about'] ? 8 : 0 ) + ( $s['has_contact'] ? 8 : 0 ) + ( $s['has_privacy'] ? 4 : 0 );

        // Editorial markers.
        $s['author_bylines'] = $home->hasAuthorByline || (bool) array_filter( $profile->pages, static fn( PageAnalysis $p ) => $p->hasAuthorByline );
        $s['dated_content']  = '' !== $profile->lastContentDate;
        $score += ( $s['author_bylines'] ? 10 : 0 ) + ( $s['dated_content'] ? 6 : 0 );

        // Outbound link hygiene: external links with descriptive anchors, low ad density.
        $ext = $home->externalLinks();
        $descriptive = count( array_filter( $ext, static fn( $l ) => mb_strlen( $l['anchor'] ) >= 4 && ! preg_match( '/^(click here|here|link|read more|more)$/i', $l['anchor'] ) ) );
        $s['external_links']    = count( $ext );
        $s['descriptive_ratio'] = count( $ext ) > 0 ? round( $descriptive / count( $ext ), 2 ) : null;
        if ( count( $ext ) > 0 ) {
            $score += 8 * ( $descriptive / count( $ext ) );
        } else {
            $score += 4;
        }
        $s['ad_signals'] = $home->adSignals;
        $score -= min( 15, $home->adSignals * 2.5 );

        // Technical hygiene.
        $s['https']       = $profile->https;
        $s['indexable']   = $home->isIndexable();
        $score += ( $profile->https ? 4 : 0 ) + ( $home->isIndexable() ? 4 : -10 );

        // Age as a weak reputation proxy.
        if ( null !== $profile->domainAgeYears ) {
            $s['domain_age_years'] = $profile->domainAgeYears;
            $score += min( 8, $profile->domainAgeYears * 1.2 );
        }

        return [ 'score' => (int) max( 0, min( 100, round( $score ) ) ), 'signals' => $s ];
    }

    /**
     * Real audience & engagement.
     *
     * @param array<string,mixed> $metrics merged provider metrics (may be empty)
     * @return array{score:int,signals:array<string,mixed>}
     */
    public static function audience( SiteProfile $profile, array $metrics = [], bool $sendsReferralTraffic = false ): array {
        $home = $profile->home;
        if ( ! $home ) {
            return [ 'score' => 0, 'signals' => [] ];
        }
        $s     = [];
        $score = 10.0;

        $s['social_profiles'] = count( $home->socialLinks );
        $score += min( 15, count( $home->socialLinks ) * 5 );

        $s['comments']   = $home->hasComments;
        $s['newsletter'] = $home->hasNewsletter;
        $score += ( $home->hasComments ? 10 : 0 ) + ( $home->hasNewsletter ? 8 : 0 );

        // Recency of content.
        if ( '' !== $profile->lastContentDate ) {
            $ageDays = max( 0, ( time() - (int) strtotime( $profile->lastContentDate ) ) / DAY_IN_SECONDS );
            $s['content_age_days'] = (int) $ageDays;
            if ( $ageDays <= 90 ) {
                $score += 20;
            } elseif ( $ageDays <= 365 ) {
                $score += 10;
            } elseif ( $ageDays > 730 ) {
                $score -= 10;
            }
        }

        // Provider corroboration (keywords/refdomains/traffic indicate real visitors).
        $traffic   = (int) ( $metrics['organic_traffic'] ?? 0 );
        $keywords  = (int) ( $metrics['organic_keywords'] ?? 0 );
        $refdomains = (int) ( $metrics['referring_domains'] ?? 0 );
        $s['organic_traffic']   = $metrics['organic_traffic'] ?? null;
        $s['organic_keywords']  = $metrics['organic_keywords'] ?? null;
        $s['referring_domains'] = $metrics['referring_domains'] ?? null;
        if ( $traffic > 0 ) {
            $score += min( 25, log10( $traffic + 1 ) * 6 );
        } elseif ( $keywords > 0 ) {
            $score += min( 15, log10( $keywords + 1 ) * 5 );
        }
        if ( $refdomains > 0 ) {
            $score += min( 10, log10( $refdomains + 1 ) * 4 );
        }
        if ( $sendsReferralTraffic ) {
            $s['sends_referral_traffic'] = true;
            $score += 15;
        }
        return [ 'score' => (int) max( 0, min( 100, round( $score ) ) ), 'signals' => $s ];
    }
}
