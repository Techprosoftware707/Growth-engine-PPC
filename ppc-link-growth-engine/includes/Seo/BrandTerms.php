<?php
/**
 * The site's own name in its written forms (1.2.3), so internal links never use it as link text.
 *
 * Brand terms: site.brand_name, site.legal_name, every discovery.brand_terms entry, the WordPress blogname, and the
 * site domain (site.domain, or the home address's host when that is empty) with and without its TLD
 * ("purepeptidescanada.com", "purepeptidescanada"). Text is compared normalised: lower case, accents folded,
 * punctuation as a space, runs of spaces as one ("PURE PEPTIDES CANADA" = "Pure Peptides Canada" = "pure-peptides
 * canadá"). A one-word term that is another term written without spaces (the domain "purepeptidescanada") is read as
 * that term's words.
 *
 * - isBrand(): an anchor is a brand term when its words are the words of a brand term or a run of consecutive words
 *   of one ("Pure Peptides Canada", "Peptides Canada", "Pure Peptides", "PurePeptides", "purepeptidescanada.com"),
 *   never a piece of a word ("scan" is not in "purepeptidescanada").
 * - patterns() / mentions() / cuts(): where a brand term is written in a text (whole words, case and accents
 *   ignored, up to 3 spaces or punctuation marks between its words, also none), and whether a match lies inside such
 *   a mention or cuts through it ("Peptides Canada" inside "Pure Peptides Canada"). A match that holds a whole
 *   mention ("Pure Peptides Canada reviews") does not cut it.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Seo;

use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Url;

final class BrandTerms {

    /** Second-level labels dropped together with the TLD ("example.co.uk" → "example"). */
    private const SECOND_LEVEL = [ 'co', 'com', 'net', 'org', 'gov', 'edu', 'ac' ];

    /** Letters matched with their accented forms in mention patterns. */
    private const ACCENTS = [
        'a' => 'aàáâãäåāăą',
        'c' => 'cçćĉċč',
        'e' => 'eèéêëēĕėęě',
        'i' => 'iìíîïĩīĭįı',
        'n' => 'nñńņňŉ',
        'o' => 'oòóôõöøōŏő',
        'u' => 'uùúûüũūŭůűų',
        'y' => 'yýÿŷ',
        's' => 'sśŝşš',
        'z' => 'zźżž',
        'l' => 'lĺļľł',
        'r' => 'rŕřŗ',
        'd' => 'dďđ',
        'g' => 'gğĝġģ',
        't' => 'tţťŧ',
    ];

    /** @var array<int,string[]> words of each brand term (normalised, segmented) */
    private array $words = [];

    /** @var array<string,bool> compact form (no spaces) of every run of consecutive words of a brand term */
    private array $compacts = [];

    /** @var string[]|null */
    private ?array $patterns = null;

    /** @param string[] $terms brand terms as written */
    public function __construct( array $terms ) {
        $lists = [];
        foreach ( $terms as $term ) {
            $norm = self::normalize( (string) $term );
            if ( '' !== $norm ) {
                $lists[ $norm ] = explode( ' ', $norm );
            }
        }
        // A one-word term that is another term without its spaces ("purepeptidescanada") takes that term's words.
        $split = [];
        foreach ( $lists as $words ) {
            $n = count( $words );
            for ( $i = 0; $i < $n; $i++ ) {
                for ( $j = $i + 2; $j <= $n; $j++ ) {
                    $run                           = array_slice( $words, $i, $j - $i );
                    $split[ implode( '', $run ) ] ??= $run;
                }
            }
        }
        $seen = [];
        foreach ( $lists as $words ) {
            $out = [];
            foreach ( $words as $w ) {
                array_push( $out, ...( $split[ $w ] ?? [ $w ] ) );
            }
            $key = implode( ' ', $out );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ]  = true;
            $this->words[] = $out;
            $n             = count( $out );
            for ( $i = 0; $i < $n; $i++ ) {
                for ( $j = $i + 1; $j <= $n; $j++ ) {
                    $this->compacts[ implode( '', array_slice( $out, $i, $j - $i ) ) ] = true;
                }
            }
        }
    }

    /** The brand terms of this site (see the class comment). */
    public static function fromSettings( Settings $settings ): self {
        $terms = [ (string) $settings->get( 'site.brand_name', '' ), (string) $settings->get( 'site.legal_name', '' ) ];
        foreach ( (array) $settings->get( 'discovery.brand_terms', [] ) as $t ) {
            if ( is_string( $t ) ) {
                $terms[] = $t;
            }
        }
        $blog = get_option( 'blogname', '' );
        if ( is_string( $blog ) ) {
            $terms[] = html_entity_decode( $blog, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        }
        $domain = trim( $settings->siteDomain() );
        if ( '' === $domain ) {
            $domain = SiteAuditor::siteHost();
        }
        return new self( array_merge( $terms, self::domainForms( $domain ) ) );
    }

    /**
     * A host with and without its TLD: "www.purepeptidescanada.com" → ["purepeptidescanada.com", "purepeptidescanada"].
     *
     * @return string[]
     */
    public static function domainForms( string $domain ): array {
        $host = strtolower( trim( $domain ) );
        if ( str_contains( $host, '/' ) ) {
            $host = strtolower( Url::host( str_contains( $host, '://' ) ? $host : 'https://' . $host ) );
        }
        $host = (string) preg_replace( '/^www\./', '', rtrim( $host, '.' ) );
        if ( '' === $host ) {
            return [];
        }
        $labels = explode( '.', $host );
        if ( count( $labels ) > 1 ) {
            array_pop( $labels );
            if ( count( $labels ) > 1 && in_array( end( $labels ), self::SECOND_LEVEL, true ) ) {
                array_pop( $labels );
            }
        }
        return array_values( array_unique( [ $host, implode( '.', $labels ) ] ) );
    }

    /** Lower case, accents folded, every run of characters other than letters and digits as one space, trimmed. */
    public static function normalize( string $text ): string {
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = self::fold( mb_strtolower( $text, 'UTF-8' ) );
        return trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text ) );
    }

    /** Accents removed (é → e, ç → c), and letters without a combining accent spelled out (ß → ss, œ → oe). */
    public static function fold( string $text ): string {
        if ( class_exists( \Normalizer::class ) ) {
            $d = \Normalizer::normalize( $text, \Normalizer::FORM_D );
            if ( is_string( $d ) ) {
                $text = (string) preg_replace( '/\p{Mn}+/u', '', $d );
            }
        } elseif ( function_exists( 'remove_accents' ) ) {
            $text = (string) remove_accents( $text );
        }
        return strtr( $text, [ 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o', 'đ' => 'd', 'ł' => 'l', 'þ' => 'th', 'ð' => 'd', 'ı' => 'i' ] );
    }

    /** Whether $anchor, normalised, is a brand term or consecutive words of one. */
    public function isBrand( string $anchor ): bool {
        $compact = str_replace( ' ', '', self::normalize( $anchor ) );
        return '' !== $compact && isset( $this->compacts[ $compact ] );
    }

    /** Whether $text shares a whole word with a brand term (only then can a match in it cut a brand mention). */
    public function sharesWord( string $text ): bool {
        $mine = array_flip( explode( ' ', self::normalize( $text ) ) );
        foreach ( $this->words as $words ) {
            foreach ( $words as $w ) {
                if ( isset( $mine[ $w ] ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return string[] the brand terms, normalised */
    public function terms(): array {
        return array_map( static fn( array $w ): string => implode( ' ', $w ), $this->words );
    }

    /**
     * Regular expressions that find a brand term written in a text: whole words, case and accents ignored, up to 3
     * spaces or punctuation marks between its words (also none: "PurePeptidesCanada").
     *
     * @return string[]
     */
    public function patterns(): array {
        if ( null !== $this->patterns ) {
            return $this->patterns;
        }
        $out = [];
        foreach ( $this->words as $words ) {
            $parts = [];
            foreach ( $words as $w ) {
                $p = '';
                foreach ( mb_str_split( $w, 1, 'UTF-8' ) as $ch ) {
                    $p .= isset( self::ACCENTS[ $ch ] ) ? '[' . self::ACCENTS[ $ch ] . ']' : preg_quote( $ch, '/' );
                }
                $parts[] = $p;
            }
            $out[] = '/(?<![\p{L}\p{N}])' . implode( '[^\p{L}\p{N}]{0,3}', $parts ) . '(?![\p{L}\p{N}])/iu';
        }
        return $this->patterns = $out;
    }

    /**
     * Byte ranges [start, end) of the brand mentions in $text.
     *
     * @param string[] $patterns from patterns()
     * @return array<int,array{0:int,1:int}>
     */
    public static function mentions( string $text, array $patterns ): array {
        $out = [];
        if ( '' === $text ) {
            return $out;
        }
        foreach ( $patterns as $pattern ) {
            if ( @preg_match_all( (string) $pattern, $text, $m, PREG_OFFSET_CAPTURE ) ) {
                foreach ( $m[0] as [ $str, $off ] ) {
                    $out[] = [ (int) $off, (int) $off + strlen( (string) $str ) ];
                }
            }
        }
        return $out;
    }

    /**
     * Whether a match at [start, end) lies inside a brand mention or cuts through one (it overlaps a mention without
     * holding all of it).
     *
     * @param array<int,array{0:int,1:int}> $mentions
     */
    public static function cuts( array $mentions, int $start, int $end ): bool {
        foreach ( $mentions as [ $ms, $me ] ) {
            if ( $start < $me && $ms < $end && ! ( $start <= $ms && $me <= $end ) ) {
                return true;
            }
        }
        return false;
    }
}
