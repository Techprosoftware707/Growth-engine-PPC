<?php
/**
 * 100-point opportunity scoring with weighted components, confidence and classification.
 *
 * Weights (documented): topical relevance 30, organic traffic 20, editorial quality 15,
 * real audience 10, authority metrics 10, indexability & placement 10, risk 5.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Scoring;

use PPC\LinkGrowth\Analysis\PageAnalysis;
use PPC\LinkGrowth\Analysis\QualitySignals;
use PPC\LinkGrowth\Analysis\SiteProfile;
use PPC\LinkGrowth\Support\Settings;

final class ScoringEngine {

    public const WEIGHTS = [
        'topical_relevance'      => 30,
        'organic_traffic'        => 20,
        'editorial_quality'      => 15,
        'audience_engagement'    => 10,
        'authority'              => 10,
        'indexability_placement' => 10,
        'risk'                   => 5,
    ];

    public const CLASS_EXCEPTIONAL = 'exceptional';
    public const CLASS_HIGH        = 'high';
    public const CLASS_GOOD        = 'good';
    public const CLASS_MODERATE    = 'moderate';
    public const CLASS_REJECT      = 'reject';

    public function __construct( private Settings $settings ) {}

    public static function classify( int $score ): string {
        if ( $score >= 90 ) {
            return self::CLASS_EXCEPTIONAL;
        }
        if ( $score >= 80 ) {
            return self::CLASS_HIGH;
        }
        if ( $score >= 70 ) {
            return self::CLASS_GOOD;
        }
        if ( $score >= 50 ) {
            return self::CLASS_MODERATE;
        }
        return self::CLASS_REJECT;
    }

    public static function classLabel( string $class ): string {
        return match ( $class ) {
            self::CLASS_EXCEPTIONAL => 'Exceptional opportunity',
            self::CLASS_HIGH        => 'High-value opportunity',
            self::CLASS_GOOD        => 'Good opportunity',
            self::CLASS_MODERATE    => 'Moderate opportunity',
            default                 => 'Do not pursue automatically',
        };
    }

    /**
     * @param array{score:int,topical:int,geo:int,matched:array<string,int>,negative:array<string,int>,misuse:array<string,int>} $relevance
     * @param array{score:int,flags:string[],hard_reject:bool,reasons:string[]}                                                $risk
     * @param array<string,mixed>                                                                                               $metrics merged provider metrics
     * @param PageAnalysis|null                                                                                                 $opportunityPage page where the link would live
     * @return array<string,mixed>
     */
    public function score( SiteProfile $profile, array $relevance, array $risk, array $metrics, ?PageAnalysis $opportunityPage = null, bool $sendsReferral = false ): array {
        $components = [];

        // 1. Topical relevance (30).
        $components['topical_relevance'] = [
            'score'  => $relevance['score'],
            'source' => 'content analysis',
            'detail' => [ 'topical' => $relevance['topical'], 'geo' => $relevance['geo'], 'matched_terms' => array_slice( array_keys( $relevance['matched'] ), 0, 10 ) ],
            'known'  => true,
        ];

        // 2. Organic traffic (20).
        $traffic = $metrics['organic_traffic'] ?? null;
        if ( null !== $traffic ) {
            $t = (float) $traffic;
            // log scale: 0->0, 100->30, 1k->50, 10k->70, 100k->90, 1M->100
            $ts = $t <= 0 ? 0 : min( 100, (int) round( 20 * log10( $t ) - 10 ) );
            $components['organic_traffic'] = [ 'score' => max( 0, $ts ), 'source' => (string) ( $metrics['traffic_source'] ?? 'provider' ), 'detail' => [ 'organic_traffic' => (int) $t, 'organic_keywords' => $metrics['organic_keywords'] ?? null ], 'known' => true ];
        } elseif ( isset( $metrics['organic_keywords'] ) ) {
            $k  = (float) $metrics['organic_keywords'];
            $ks = $k <= 0 ? 0 : min( 85, (int) round( 18 * log10( $k + 1 ) ) );
            $components['organic_traffic'] = [ 'score' => $ks, 'source' => 'keyword visibility (no traffic estimate)', 'detail' => [ 'organic_keywords' => (int) $k ], 'known' => true ];
        } else {
            $components['organic_traffic'] = [ 'score' => 35, 'source' => 'unknown (no traffic provider configured)', 'detail' => [], 'known' => false ];
        }

        // 3. Editorial quality (15).
        $editorial = QualitySignals::editorial( $profile );
        $components['editorial_quality'] = [ 'score' => $editorial['score'], 'source' => 'page analysis', 'detail' => $editorial['signals'], 'known' => true ];

        // 4. Real audience & engagement (10).
        $audience = QualitySignals::audience( $profile, $metrics, $sendsReferral );
        $components['audience_engagement'] = [ 'score' => $audience['score'], 'source' => 'page analysis + provider data', 'detail' => $audience['signals'], 'known' => true ];

        // 5. Authority metrics (10).
        $authorityScores = (array) ( $metrics['authority_scores'] ?? [] );
        if ( ! empty( $authorityScores ) ) {
            $avg = array_sum( $authorityScores ) / count( $authorityScores );
            $as  = (int) round( min( 100, $avg * 1.15 ) ); // mild lift: 60 DR ~ 69
            $rd  = (int) ( $metrics['referring_domains'] ?? 0 );
            if ( $rd > 0 ) {
                $as = (int) round( 0.7 * $as + 0.3 * min( 100, log10( $rd + 1 ) * 25 ) );
            }
            $components['authority'] = [ 'score' => $as, 'source' => implode( ', ', array_keys( $authorityScores ) ), 'detail' => array_merge( $authorityScores, [ 'referring_domains' => $metrics['referring_domains'] ?? null ] ), 'known' => true ];
        } else {
            $components['authority'] = [ 'score' => 30, 'source' => 'unknown (no authority provider configured)', 'detail' => [], 'known' => false ];
        }

        // 6. Indexability & link placement (10).
        $components['indexability_placement'] = $this->indexability( $profile, $opportunityPage );

        // 7. Risk (5) — inverted.
        $components['risk'] = [ 'score' => max( 0, 100 - $risk['score'] ), 'source' => 'risk analysis', 'detail' => [ 'flags' => $risk['flags'], 'risk_score' => $risk['score'] ], 'known' => true ];

        $total      = 0.0;
        $knownW     = 0;
        foreach ( self::WEIGHTS as $key => $weight ) {
            $components[ $key ]['weight']   = $weight;
            $components[ $key ]['weighted'] = round( $components[ $key ]['score'] * $weight / 100, 2 );
            $total += $components[ $key ]['weighted'];
            if ( ! empty( $components[ $key ]['known'] ) ) {
                $knownW += $weight;
            }
        }
        $total = (int) round( $total );

        // Hard gates.
        $reasons    = [];
        $autoReject = false;
        if ( $risk['hard_reject'] ) {
            $autoReject = true;
            $reasons[]  = 'Hard risk flags: ' . implode( ', ', $risk['flags'] );
        }
        if ( $relevance['topical'] < 12 ) {
            $autoReject = true;
            $reasons[]  = 'No topical relevance to laboratory research supply.';
        }
        if ( ! $profile->reachable ) {
            $autoReject = true;
            $reasons[]  = 'Site unreachable.';
        }
        if ( $autoReject ) {
            $total = min( $total, 40 );
        }

        $class = self::classify( $total );
        return [
            'total'       => $total,
            'class'       => $class,
            'class_label' => self::classLabel( $class ),
            'confidence'  => $knownW,
            'components'  => $components,
            'auto_reject' => $autoReject,
            'reasons'     => array_merge( $reasons, $risk['reasons'] ),
            'scored_at'   => gmdate( 'c' ),
            'weights'     => self::WEIGHTS,
        ];
    }

    /** @return array<string,mixed> */
    private function indexability( SiteProfile $profile, ?PageAnalysis $page ): array {
        $page = $page ?: $profile->home;
        if ( ! $page ) {
            return [ 'score' => 0, 'source' => 'n/a', 'detail' => [], 'known' => false ];
        }
        $score  = 0;
        $detail = [];
        $detail['indexable'] = $page->isIndexable();
        $score += $page->isIndexable() ? 45 : 0;
        $detail['canonical_mismatch'] = $page->canonicalMismatch();
        $score -= $page->canonicalMismatch() ? 15 : 0;
        $ext    = $page->externalLinks();
        $follow = count( array_filter( $ext, static fn( $l ) => ! $l['nofollow'] && ! in_array( 'sponsored', $l['rel'], true ) ) );
        $detail['external_links']   = count( $ext );
        $detail['followed_ratio']   = count( $ext ) > 0 ? round( $follow / count( $ext ), 2 ) : null;
        if ( count( $ext ) > 0 ) {
            // nofollow-only sites still have referral value: floor at 55% of the placement credit.
            $score += (int) round( 30 * max( 0.55, $follow / count( $ext ) ) );
        } else {
            $score += 20;
        }
        $content = count( array_filter( $ext, static fn( $l ) => 'content' === $l['region'] ) );
        $detail['content_region_ratio'] = count( $ext ) > 0 ? round( $content / count( $ext ), 2 ) : null;
        $score += count( $ext ) > 0 ? (int) round( 15 * ( $content / count( $ext ) ) ) : 10;
        $detail['https'] = $profile->https;
        $score += $profile->https ? 10 : 0;
        return [ 'score' => max( 0, min( 100, $score ) ), 'source' => 'page analysis', 'detail' => $detail, 'known' => true ];
    }
}
