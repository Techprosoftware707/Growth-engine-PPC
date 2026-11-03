<?php
/**
 * String helpers.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Support;

final class Str {

    public static function truncate( string $text, int $max, string $suffix = '…' ): string {
        $text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? $text );
        if ( mb_strlen( $text ) <= $max ) {
            return $text;
        }
        $cut = mb_substr( $text, 0, $max - mb_strlen( $suffix ) );
        $sp  = mb_strrpos( $cut, ' ' );
        if ( false !== $sp && $sp > $max * 0.6 ) {
            $cut = mb_substr( $cut, 0, $sp );
        }
        return rtrim( $cut, ' ,;:-' ) . $suffix;
    }

    /** Lower-case word tokens (letters, digits, hyphens). @return string[] */
    public static function tokens( string $text ): array {
        $text = mb_strtolower( $text );
        preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\-]{1,}/u', $text, $m );
        return $m[0];
    }

    public static function containsAny( string $haystack, array $needles ): bool {
        $h = mb_strtolower( $haystack );
        foreach ( $needles as $n ) {
            if ( '' !== $n && str_contains( $h, mb_strtolower( (string) $n ) ) ) {
                return true;
            }
        }
        return false;
    }

    /** Count whole-phrase occurrences (case-insensitive, word boundaries). */
    public static function countPhrase( string $haystack, string $phrase ): int {
        $phrase = trim( $phrase );
        if ( '' === $phrase ) {
            return 0;
        }
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote( mb_strtolower( $phrase ), '/' ) . '(?![\p{L}\p{N}])/u';
        return (int) preg_match_all( $pattern, mb_strtolower( $haystack ) );
    }

    public static function titleCase( string $s ): string {
        return mb_convert_case( $s, MB_CASE_TITLE, 'UTF-8' );
    }

    public static function firstName( string $name ): string {
        $name = trim( $name );
        if ( '' === $name ) {
            return '';
        }
        $parts = preg_split( '/\s+/', $name ) ?: [];
        $first = $parts[0] ?? '';
        return preg_match( '/^[\p{L}\'\-]{2,}$/u', $first ) ? ucfirst( $first ) : '';
    }

    public static function seededPick( array $options, int $seed, int $salt = 0 ): mixed {
        if ( empty( $options ) ) {
            return null;
        }
        $index = abs( ( $seed * 31 + $salt * 17 ) ) % count( $options );
        $values = array_values( $options );
        return $values[ $index ];
    }

    public static function seed( string $s ): int {
        return (int) ( crc32( strtolower( $s ) ) & 0x7fffffff );
    }

    public static function clean( string $html ): string {
        $text = wp_strip_all_tags( $html, true );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/[ \t]+/u', ' ', preg_replace( '/\s*\n\s*/u', "\n", $text ) ?? $text ) ?? $text );
    }
}
