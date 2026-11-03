<?php
/**
 * JSON helpers tolerant of bad input.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Support;

final class Json {

    /** @return array<mixed> */
    public static function decode( mixed $value, array $default = [] ): array {
        if ( is_array( $value ) ) {
            return $value;
        }
        if ( ! is_string( $value ) || '' === $value ) {
            return $default;
        }
        $decoded = json_decode( $value, true );
        return is_array( $decoded ) ? $decoded : $default;
    }

    public static function encode( mixed $value ): string {
        $json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return is_string( $json ) ? $json : '{}';
    }
}
