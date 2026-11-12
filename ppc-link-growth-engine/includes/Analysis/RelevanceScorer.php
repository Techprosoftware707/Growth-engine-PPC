<?php
/**
 * Topical + geographic relevance scoring against the keyword taxonomy.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Analysis;

use PPC\LinkGrowth\Support\Str;
use PPC\LinkGrowth\Support\Url;

final class RelevanceScorer {

    public function __construct( private Taxonomy $taxonomy ) {}

    /**
     * @param PageAnalysis[] $pages homepage first; optional opportunity page(s)
     * @return array{score:int,topical:int,geo:int,matched:array<string,int>,negative:array<string,int>,misuse:array<string,int>}
     */
    public function score( array $pages, string $country = '', int $countryConfidence = 0 ): array {
        $weighted  = 0.0;
        $matched   = [];
        $negative  = [];
        $misuse    = [];
        $groups    = $this->taxonomy->groups();
        $n         = 0;
        foreach ( $pages as $page ) {
            $n++;
            $pageWeight = 1 === $n ? 1.0 : 0.7;
            $fields     = [
                'url'      => [ 'text' => str_replace( [ '-', '_', '/' ], ' ', Url::path( $page->finalUrl ?: $page->url ) ), 'w' => 2.0 ],
                'title'    => [ 'text' => $page->title, 'w' => 3.0 ],
                'meta'     => [ 'text' => $page->metaDescription, 'w' => 2.0 ],
                'headings' => [ 'text' => $page->headings(), 'w' => 2.0 ],
                'body'     => [ 'text' => mb_substr( $page->text, 0, 20000 ), 'w' => 1.0 ],
            ];
            foreach ( $groups as $gname => $g ) {
                if ( 'canada' === $gname ) {
                    continue; // handled by geo
                }
                foreach ( $g['terms'] as $term ) {
                    $hits = 0.0;
                    foreach ( $fields as $f ) {
                        $c = Str::countPhrase( $f['text'], $term );
                        if ( $c > 0 ) {
                            $hits += $f['w'] * min( 5, $c ); // diminishing returns per field
                        }
                    }
                    if ( $hits > 0 ) {
                        $term_w   = $g['weight'] * ( str_contains( $term, ' ' ) ? 1.4 : 1.0 ); // phrases are more specific
                        $weighted += $hits * $term_w * $pageWeight;
                        $matched[ $term ] = ( $matched[ $term ] ?? 0 ) + (int) $hits;
                    }
                }
            }
            $hay = mb_strtolower( $page->title . ' ' . $page->headings() . ' ' . mb_substr( $page->text, 0, 20000 ) );
            foreach ( $this->taxonomy->negative( 'spam' ) as $term ) {
                $c = Str::countPhrase( $hay, $term );
                if ( $c > 0 ) {
                    $negative[ $term ] = ( $negative[ $term ] ?? 0 ) + $c;
                }
            }
            foreach ( $this->taxonomy->negative( 'misuse' ) as $term ) {
                $c = Str::countPhrase( $hay, $term );
                if ( $c > 0 ) {
                    $misuse[ $term ] = ( $misuse[ $term ] ?? 0 ) + $c;
                }
            }
            foreach ( $this->taxonomy->negative( 'offtopic' ) as $term ) {
                $c = Str::countPhrase( $hay, $term );
                if ( $c > 2 ) {
                    $negative[ $term ] = ( $negative[ $term ] ?? 0 ) + $c;
                }
            }
        }
        // Saturating curve: ~12 weighted hits => 50, ~40 => 80, 100+ => 95.
        $topical = (int) round( 100 * ( 1 - exp( -$weighted / 28 ) ) );
        // Distinct-term bonus: breadth matters more than repetition of one word.
        $distinct = count( $matched );
        $topical  = min( 100, $topical + min( 15, $distinct * 2 ) );
        // Penalties.
        $negCount    = array_sum( $negative );
        $misuseCount = array_sum( $misuse );
        if ( $negCount > 0 ) {
            $topical = (int) round( $topical * max( 0.1, 1 - 0.15 * $negCount ) );
        }
        if ( $misuseCount > 0 ) {
            $topical = (int) round( $topical * max( 0.2, 1 - 0.12 * $misuseCount ) );
        }
        $geo = $this->geoScore( $country, $countryConfidence, $pages );
        // Composite: topical dominates; geo lifts Canadian sites, international relevance keeps a floor.
        $score = (int) round( 0.75 * $topical + 0.25 * $geo );
        if ( $topical >= 70 && $geo < 40 ) {
            $score = max( $score, (int) round( 0.75 * $topical + 0.25 * 40 ) );
        }
        return [
            'score'    => max( 0, min( 100, $score ) ),
            'topical'  => max( 0, min( 100, $topical ) ),
            'geo'      => $geo,
            'matched'  => $this->top( $matched, 20 ),
            'negative' => $this->top( $negative, 10 ),
            'misuse'   => $this->top( $misuse, 10 ),
        ];
    }

    /** @param PageAnalysis[] $pages */
    private function geoScore( string $country, int $confidence, array $pages ): int {
        if ( 'CA' === $country ) {
            // intdiv keeps the result an int for odd confidences (strict_types return type).
            return max( 60, min( 100, 50 + intdiv( max( 0, $confidence ), 2 ) ) );
        }
        if ( '' === $country ) {
            $hits = 0;
            foreach ( $pages as $p ) {
                $hits += count( $p->mentions( $this->taxonomy->groups()['canada']['terms'] ?? [] ) );
            }
            return min( 55, 20 + $hits * 6 );
        }
        return match ( $country ) {
            'US' => 35,
            'GB', 'AU', 'NZ', 'IE' => 30,
            default => 20,
        };
    }

    /** @param array<string,int> $counts @return array<string,int> */
    private function top( array $counts, int $n ): array {
        arsort( $counts );
        return array_slice( $counts, 0, $n, true );
    }
}
