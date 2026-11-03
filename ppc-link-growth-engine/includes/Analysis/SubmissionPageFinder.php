<?php
/**
 * Locates the actual listing/submission/contribution page of a prospect site.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Analysis;

use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Url;

final class SubmissionPageFinder {

    /** Ordered (priority desc) patterns tested against path + anchor text. */
    private const PATTERNS = [
        [ 'score' => 100, 'regex' => '/add[\s\-_]?(your[\s\-_]?)?(business|listing|company|site|website|supplier|organi[sz]ation)|submit[\s\-_]?(a[\s\-_]?|your[\s\-_]?)?(business|listing|site|website|company|link|resource|supplier)|list[\s\-_]?your|get[\s\-_]?listed|claim[\s\-_]?(your[\s\-_]?)?listing|suggest[\s\-_]?(a[\s\-_]?)?(link|resource|site)/i' ],
        [ 'score' => 85, 'regex' => '/\/(submit|submission|add-listing|add_listing|addlisting|listing\/add|new-listing|register-business|business-registration)(\/|$|\?)/i' ],
        [ 'score' => 70, 'regex' => '/become[\s\-_]?a[\s\-_]?(member|supplier|partner|vendor|sponsor)|join[\s\-_]?(us|now|the|our)?|membership|member[\s\-_]?(application|signup|sign-up)|apply/i' ],
        [ 'score' => 65, 'regex' => '/write[\s\-_]?for[\s\-_]?us|contribute|contributor|guest[\s\-_]?(post|article|author)|submit[\s\-_]?(an[\s\-_]?)?article|editorial[\s\-_]?guidelines|pitch/i' ],
        [ 'score' => 60, 'regex' => '/advertis|sponsor|partner(ship)?s?(\/|$)|exhibit/i' ],
        [ 'score' => 55, 'regex' => '/(resources?|useful[\s\-_]?links|links)(\/|$|\?)/i' ],
        [ 'score' => 40, 'regex' => '/contact(-us)?(\/|$|\?)|get[\s\-_]?in[\s\-_]?touch/i' ],
    ];

    private const COMMON_PATHS = [ '/submit', '/add-listing', '/add-your-business', '/submit-listing', '/add-business', '/join', '/membership', '/contribute', '/write-for-us', '/advertise', '/contact' ];

    public function __construct(
        private Client $client,
        private PageAnalyzer $analyzer,
        private FormDetector $forms,
        private Logger $logger
    ) {}

    /**
     * @return array{url:string,score:int,kind:string,form:?array<string,mixed>,page:?PageAnalysis,candidates:array<int,array{url:string,score:int,anchor:string}>}
     */
    public function find( PageAnalysis $home, int $maxFetches = 3, bool $probeCommonPaths = false ): array {
        $base       = $home->finalUrl ?: $home->url;
        $candidates = [];
        foreach ( $home->links as $link ) {
            if ( $link['external'] || ! Url::looksLikeHtml( $link['url'] ) ) {
                continue;
            }
            $hay = Url::path( $link['url'] ) . ' ' . $link['anchor'];
            foreach ( self::PATTERNS as $p ) {
                if ( preg_match( $p['regex'], $hay ) ) {
                    $url = $link['url'];
                    if ( ! isset( $candidates[ $url ] ) || $candidates[ $url ]['score'] < $p['score'] ) {
                        $candidates[ $url ] = [ 'url' => $url, 'score' => $p['score'], 'anchor' => $link['anchor'] ];
                    }
                    break;
                }
            }
        }
        if ( $probeCommonPaths && count( $candidates ) < 2 ) {
            foreach ( self::COMMON_PATHS as $path ) {
                $url = Url::absolutize( $path, $base );
                if ( '' !== $url && ! isset( $candidates[ $url ] ) ) {
                    $candidates[ $url ] = [ 'url' => $url, 'score' => 30, 'anchor' => '' ];
                }
            }
        }
        uasort( $candidates, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
        $candidates = array_slice( array_values( $candidates ), 0, 8 );

        $best = [ 'url' => '', 'score' => 0, 'kind' => '', 'form' => null, 'page' => null, 'candidates' => $candidates ];

        // The homepage itself may carry the form.
        foreach ( $home->forms as $f ) {
            if ( FormDetector::KIND_LISTING === $f['kind'] ) {
                return array_merge( $best, [ 'url' => $base, 'score' => 95, 'kind' => $f['kind'], 'form' => $f, 'page' => $home ] );
            }
        }

        $fetched = 0;
        foreach ( $candidates as $cand ) {
            if ( $fetched >= $maxFetches ) {
                break;
            }
            $resp = $this->client->crawl( $cand['url'], [ 'cache' => true ] );
            $fetched++;
            if ( $resp->failed() || ! $resp->isHtml() ) {
                continue;
            }
            $page = $this->analyzer->fromResponse( $resp );
            $kind = '';
            $form = null;
            foreach ( $page->forms as $f ) {
                if ( FormDetector::KIND_LISTING === $f['kind'] ) {
                    $form = $f;
                    $kind = $f['kind'];
                    break;
                }
                if ( in_array( $f['kind'], [ FormDetector::KIND_CONTACT, FormDetector::KIND_REGISTER ], true ) && null === $form ) {
                    $form = $f;
                    $kind = $f['kind'];
                }
            }
            $score = $cand['score'] + ( FormDetector::KIND_LISTING === $kind ? 20 : 0 ) + ( '' === $kind ? -15 : 0 );
            if ( $score > $best['score'] ) {
                $best = array_merge( $best, [ 'url' => $page->finalUrl ?: $cand['url'], 'score' => $score, 'kind' => $kind ?: 'page', 'form' => $form, 'page' => $page ] );
            }
            if ( FormDetector::KIND_LISTING === $kind ) {
                break;
            }
        }
        return $best;
    }
}
