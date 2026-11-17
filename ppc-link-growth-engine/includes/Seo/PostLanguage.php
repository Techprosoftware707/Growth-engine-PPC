<?php
/**
 * The language of a published item (1.2.3), so internal links never cross languages.
 *
 * Decided by the first source that answers, in this order:
 * 1. Polylang: pll_get_post_language( id, 'locale' ) (its slug when the locale is empty);
 * 2. WPML: the wpml_post_language_details filter (language_code, else locale);
 * 3. the filter 'ppclg_post_language' (post id → language code, or '' for "not known here");
 * 4. the post meta ppc_en_counterpart (the live site's own French pages, ppc-french plugin): when present, the item
 *    is the non-English counterpart of that English item, and its language is the one its address starts with
 *    ("/fr/a-propos/" → fr), else the language its text is written in when that is not English (6.), else a
 *    language that is not known (UNKNOWN);
 * 5. a two-letter language prefix at the start of its address below the home address ("/fr/…", "/es/…"), only when
 *    it is an ISO 639-1 code ("/go/" or "/uk-orders/" are not);
 * 6. the language its text is written in (title, excerpt and content; textLanguage()), when the site language is
 *    English or French: the live site (en-CA) also has French pages at the site root (/acheter-peptides-canada/,
 *    /peptides-recherche-quebec/) without a prefix or the meta;
 * 7. the site language (the first two letters of get_locale()).
 *
 * Codes are the primary language subtag in lower case ("fr_CA", "fr-ca" → "fr"). Two items are in the same language
 * only when both codes are known and equal; UNKNOWN matches nothing (not even another UNKNOWN). Each item's language
 * is worked out once per instance (a suggestion run seeds and keeps its map in the run state).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Seo;

use Throwable;

final class PostLanguage {

    /** Filter: post id → language code ('' when it does not know). */
    public const FILTER = 'ppclg_post_language';

    /** Post meta of the live site's French pages: the id of their English counterpart. */
    public const COUNTERPART_META = 'ppc_en_counterpart';

    /** A language that is not known (a counterpart without a language prefix): matches no other item. */
    public const UNKNOWN = 'und';

    /** ISO 639-1 language codes. */
    public const ISO_639_1 = [
        'aa', 'ab', 'ae', 'af', 'ak', 'am', 'an', 'ar', 'as', 'av', 'ay', 'az', 'ba', 'be', 'bg', 'bh', 'bi', 'bm', 'bn', 'bo', 'br', 'bs',
        'ca', 'ce', 'ch', 'co', 'cr', 'cs', 'cu', 'cv', 'cy', 'da', 'de', 'dv', 'dz', 'ee', 'el', 'en', 'eo', 'es', 'et', 'eu', 'fa', 'ff',
        'fi', 'fj', 'fo', 'fr', 'fy', 'ga', 'gd', 'gl', 'gn', 'gu', 'gv', 'ha', 'he', 'hi', 'ho', 'hr', 'ht', 'hu', 'hy', 'hz', 'ia', 'id',
        'ie', 'ig', 'ii', 'ik', 'io', 'is', 'it', 'iu', 'ja', 'jv', 'ka', 'kg', 'ki', 'kj', 'kk', 'kl', 'km', 'kn', 'ko', 'kr', 'ks', 'ku',
        'kv', 'kw', 'ky', 'la', 'lb', 'lg', 'li', 'ln', 'lo', 'lt', 'lu', 'lv', 'mg', 'mh', 'mi', 'mk', 'ml', 'mn', 'mr', 'ms', 'mt', 'my',
        'na', 'nb', 'nd', 'ne', 'ng', 'nl', 'nn', 'no', 'nr', 'nv', 'ny', 'oc', 'oj', 'om', 'or', 'os', 'pa', 'pi', 'pl', 'ps', 'pt', 'qu',
        'rm', 'rn', 'ro', 'ru', 'rw', 'sa', 'sc', 'sd', 'se', 'sg', 'si', 'sk', 'sl', 'sm', 'sn', 'so', 'sq', 'sr', 'ss', 'st', 'su', 'sv',
        'sw', 'ta', 'te', 'tg', 'th', 'ti', 'tk', 'tl', 'tn', 'to', 'tr', 'ts', 'tt', 'tw', 'ty', 'ug', 'uk', 'ur', 'uz', 've', 'vi', 'vo',
        'wa', 'wo', 'xh', 'yi', 'yo', 'za', 'zh', 'zu',
    ];

    /**
     * Function words that tell English from French text (textLanguage()); each is rare in the other language. The
     * French ones leave out the words French shares most with Spanish, Italian and Portuguese ("de", "la", "en",
     * "que", "un"); the shared ones left (Italian "il", "le", "ne"; Spanish "le", "les"; Portuguese "ou", "nos", "à")
     * are fewer than TEXT_MIN_DISTINCT in each of those languages.
     */
    public const TEXT_MARKERS = [
        'en' => [ 'the', 'and', 'of', 'to', 'is', 'are', 'for', 'with', 'this', 'that', 'it', 'be', 'by', 'from', 'you', 'your', 'our', 'we', 'at', 'not',
            'have', 'has', 'was', 'were', 'which', 'will', 'can', 'in', 'its', 'their', 'they', 'these', 'those', 'what', 'how', 'when', 'if', 'than', 'each', 'every' ],
        'fr' => [ 'le', 'les', 'des', 'du', 'et', 'est', 'une', 'au', 'aux', 'à', 'avec', 'dans', 'nous', 'vous', 'notre', 'nos', 'votre', 'vos', 'cette', 'ces',
            'ses', 'sont', 'être', 'été', 'chez', 'qui', 'pas', 'ne', 'il', 'ils', 'elle', 'elles', 'sur', 'leur', 'leurs', 'ou', 'où', 'mais', 'très', 'chaque' ],
    ];

    /** A text is in a language when it holds at least this many of its function words … */
    public const TEXT_MIN_WORDS = 8;

    /** … at least this many different ones … */
    public const TEXT_MIN_DISTINCT = 4;

    /** … and this many times as many as of the other language. */
    public const TEXT_DOMINANCE = 3;

    /** Characters of an item's text that are read. */
    private const TEXT_SAMPLE = 20000;

    /** @var array<int,string> post id => language code */
    private array $cache = [];

    private ?string $site = null;

    /** @param array<int|string,string> $known languages already worked out in this run (post id => code) */
    public function __construct( array $known = [] ) {
        foreach ( $known as $id => $code ) {
            if ( (int) $id > 0 && is_string( $code ) && '' !== $code ) {
                $this->cache[ (int) $id ] = $code;
            }
        }
    }

    /** @return array<int,string> every language worked out so far (post id => code) */
    public function known(): array {
        return $this->cache;
    }

    /** Whether two language codes are the same known language. */
    public static function same( string $a, string $b ): bool {
        return '' !== $a && self::UNKNOWN !== $a && $a === $b;
    }

    /** The item's language code (see the class comment). */
    public function of( int $postId ): string {
        if ( $postId <= 0 ) {
            return $this->site();
        }
        return $this->cache[ $postId ] ??= $this->detect( $postId );
    }

    private function detect( int $postId ): string {
        if ( function_exists( 'pll_get_post_language' ) ) {
            try {
                $code = self::code( \pll_get_post_language( $postId, 'locale' ) );
                if ( '' === $code ) {
                    $code = self::code( \pll_get_post_language( $postId, 'slug' ) );
                }
                if ( '' !== $code ) {
                    return $code;
                }
            } catch ( Throwable $e ) {
                // Polylang not ready: the next source decides.
            }
        }
        $wpml = apply_filters( 'wpml_post_language_details', null, $postId );
        if ( is_array( $wpml ) ) {
            $code = self::code( $wpml['language_code'] ?? '' );
            if ( '' === $code ) {
                $code = self::code( $wpml['locale'] ?? '' );
            }
            if ( '' !== $code ) {
                return $code;
            }
        }
        $filtered = self::code( apply_filters( self::FILTER, '', $postId ) );
        if ( '' !== $filtered ) {
            return $filtered;
        }
        $prefix = self::pathPrefix( (string) get_permalink( $postId ) );
        if ( '' !== $prefix ) {
            return $prefix;
        }
        $text = $this->textOf( $postId );
        if ( ! empty( get_post_meta( $postId, self::COUNTERPART_META, true ) ) ) {
            return '' !== $text && 'en' !== $text ? $text : self::UNKNOWN; // a non-English counterpart without a prefix
        }
        return '' !== $text ? $text : $this->site();
    }

    /** The language of the item's text (textLanguage()) when the site language is one it is read for, else ''. */
    private function textOf( int $postId ): string {
        if ( ! isset( self::TEXT_MARKERS[ $this->site() ] ) ) {
            return '';
        }
        $post = get_post( $postId );
        if ( ! is_object( $post ) ) {
            return '';
        }
        return self::textLanguage( (string) ( $post->post_title ?? '' ) . "\n" . (string) ( $post->post_excerpt ?? '' ) . "\n" . (string) ( $post->post_content ?? '' ) );
    }

    /**
     * The language a text (HTML allowed) is written in, read from its function words (TEXT_MARKERS): the language
     * with at least TEXT_MIN_WORDS of them, TEXT_MIN_DISTINCT different ones and TEXT_DOMINANCE times as many as the
     * other; '' when the text is too short to tell or mixed.
     */
    public static function textLanguage( string $text ): string {
        static $markers = null;
        $markers ??= array_map( 'array_flip', self::TEXT_MARKERS );
        $plain = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        if ( ! preg_match_all( '/\p{L}+/u', mb_strtolower( mb_substr( $plain, 0, self::TEXT_SAMPLE ) ), $m ) ) {
            return '';
        }
        $hits = array_fill_keys( array_keys( $markers ), 0 );
        $seen = array_fill_keys( array_keys( $markers ), [] );
        foreach ( $m[0] as $word ) {
            foreach ( $markers as $code => $words ) {
                if ( isset( $words[ $word ] ) ) {
                    $hits[ $code ]++;
                    $seen[ $code ][ $word ] = true;
                }
            }
        }
        arsort( $hits );
        $best  = (string) array_key_first( $hits );
        $other = array_sum( $hits ) - $hits[ $best ];
        $clear = $hits[ $best ] >= self::TEXT_MIN_WORDS && count( $seen[ $best ] ) >= self::TEXT_MIN_DISTINCT && $hits[ $best ] >= self::TEXT_DOMINANCE * $other;
        return $clear ? $best : '';
    }

    /** The site language: the first two letters of get_locale() ("en" when it says nothing). */
    public function site(): string {
        if ( null === $this->site ) {
            $locale     = function_exists( 'get_locale' ) ? (string) get_locale() : '';
            $code       = self::code( $locale );
            $this->site = '' !== $code ? $code : 'en';
        }
        return $this->site;
    }

    /**
     * The language prefix of an address: its first path segment below the home address when that is an ISO 639-1
     * code ("https://example.com/fr/a-propos/" → "fr"), else ''.
     */
    public static function pathPrefix( string $url ): string {
        if ( '' === $url ) {
            return '';
        }
        $path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
        $home = rtrim( rawurldecode( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ), '/' );
        if ( '' !== $home && str_starts_with( $path, $home . '/' ) ) {
            $path = substr( $path, strlen( $home ) );
        }
        $first = strtolower( explode( '/', ltrim( $path, '/' ) )[0] );
        return 2 === strlen( $first ) && in_array( $first, self::ISO_639_1, true ) ? $first : '';
    }

    /** The primary language subtag of a code or locale, lower case ("fr_CA" → "fr"); '' when it is not one. */
    public static function code( mixed $value ): string {
        if ( ! is_string( $value ) ) {
            return '';
        }
        $primary = strtolower( (string) preg_split( '/[-_]/', trim( $value ) )[0] );
        if ( self::UNKNOWN === $primary ) {
            return self::UNKNOWN;
        }
        return preg_match( '/^[a-z]{2,3}$/', $primary ) ? $primary : '';
    }
}
