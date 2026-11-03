<?php
/**
 * Classifies a site/page into a site type and a recommended opportunity type.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Discovery;

use PPC\LinkGrowth\Analysis\PageAnalysis;
use PPC\LinkGrowth\Analysis\Taxonomy;
use PPC\LinkGrowth\Support\Str;
use PPC\LinkGrowth\Support\Url;

final class Classifier {

    private const DEFAULT_OPPORTUNITY = [
        'directory'          => 'directory_listing',
        'supplier_directory' => 'supplier_listing',
        'association'        => 'membership',
        'publication'        => 'editorial',
        'media'              => 'editorial',
        'blog'               => 'editorial',
        'educational'        => 'educational_contribution',
        'resource_page'      => 'resource_page',
        'podcast'            => 'podcast',
        'government'         => 'resource_page',
        'ecommerce'          => 'partnership',
        'unknown'            => 'resource_page',
    ];

    public function __construct( private Taxonomy $taxonomy ) {}

    /**
     * @return array{site_type:string,opportunity_type:string,confidence:int}
     */
    public function classify( string $url, string $title = '', string $snippet = '', ?PageAnalysis $page = null ): array {
        $host  = Url::host( $url );
        $path  = strtolower( Url::path( $url ) );
        $text  = mb_strtolower( $title . ' ' . $snippet );
        $body  = $page ? mb_strtolower( $page->title . ' ' . $page->headings() . ' ' . mb_substr( $page->text, 0, 6000 ) ) : '';
        $hints = $this->taxonomy->siteTypeHints();
        $scores = [];

        foreach ( $hints as $type => $terms ) {
            $s = 0.0;
            foreach ( $terms as $t ) {
                $s += 3 * Str::countPhrase( $text, $t );
                if ( '' !== $body ) {
                    $s += min( 4, Str::countPhrase( $body, $t ) );
                }
                if ( str_contains( $path, str_replace( ' ', '-', $t ) ) || str_contains( $path, str_replace( ' ', '', $t ) ) ) {
                    $s += 4;
                }
            }
            $scores[ $type ] = $s;
        }
        // Host / TLD signals.
        if ( preg_match( '/\.(edu|ac\.[a-z]{2})$/', $host ) || preg_match( '/^(www\.)?(u|uni|univ)[a-z\-]*\.ca$/', $host ) || preg_match( '/\b(university|college|polytechnic|institute of technology)\b/', $text . $body ) ) {
            $scores['educational'] = ( $scores['educational'] ?? 0 ) + 6;
        }
        if ( preg_match( '/\.(gov|gc\.ca|gov\.[a-z]{2})$/', $host ) || preg_match( '/\.(on|qc|bc|ab|mb|sk|ns|nb|nl|pe)\.ca$/', $host ) ) {
            $scores['government'] = ( $scores['government'] ?? 0 ) + 8;
        }
        if ( preg_match( '#/(directory|listings?|business(es)?|companies|suppliers?|vendors?|members?)(/|$)#', $path ) ) {
            $scores['directory'] = ( $scores['directory'] ?? 0 ) + 5;
        }
        if ( preg_match( '#/(resources?|links|useful-links|helpful-links|further-reading)(/|$)#', $path ) ) {
            $scores['resource_page'] = ( $scores['resource_page'] ?? 0 ) + 8;
        }
        if ( preg_match( '#/(blog|news|articles?|insights|magazine|journal)(/|$)#', $path ) ) {
            $scores['publication'] = ( $scores['publication'] ?? 0 ) + 4;
        }
        if ( preg_match( '#/(podcasts?|episodes?)(/|$)#', $path ) ) {
            $scores['podcast'] = ( $scores['podcast'] ?? 0 ) + 8;
        }
        if ( $page ) {
            foreach ( $page->forms as $f ) {
                if ( 'listing_submission' === ( $f['kind'] ?? '' ) ) {
                    $scores['directory'] = ( $scores['directory'] ?? 0 ) + 10;
                }
            }
            if ( $page->hasAuthorByline && '' !== $page->datePublished ) {
                $scores['publication'] = ( $scores['publication'] ?? 0 ) + 3;
            }
            $ext = count( $page->externalLinks() );
            if ( $ext > 40 && preg_match( '/resources?|links/', $body ) ) {
                $scores['resource_page'] = ( $scores['resource_page'] ?? 0 ) + 5;
            }
        }
        // Supplier directories beat generic directories when lab terms co-occur.
        if ( ( $scores['directory'] ?? 0 ) > 0 && preg_match( '/laborator|scientific|biotech|life science|supplier/', $text . $body ) ) {
            $scores['supplier_directory'] = ( $scores['supplier_directory'] ?? 0 ) + 4;
        }
        // Ecommerce must be very explicit to win.
        if ( isset( $scores['ecommerce'] ) ) {
            $scores['ecommerce'] *= 0.5;
        }
        arsort( $scores );
        $type  = (string) array_key_first( $scores );
        $top   = (float) ( $scores[ $type ] ?? 0 );
        if ( $top <= 0 ) {
            $type = 'unknown';
        }
        $second = array_values( $scores )[1] ?? 0.0;
        $conf   = $top <= 0 ? 10 : (int) min( 95, 40 + ( $top - $second ) * 6 + min( 20, $top * 2 ) );
        return [
            'site_type'        => $type,
            'opportunity_type' => self::DEFAULT_OPPORTUNITY[ $type ] ?? 'resource_page',
            'confidence'       => $conf,
        ];
    }
}
