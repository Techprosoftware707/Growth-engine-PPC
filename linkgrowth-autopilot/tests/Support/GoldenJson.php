<?php
/**
 * Golden fixtures under tests/fixtures/golden/ (WP2 part 0): read, compare and, on request, rewrite them.
 *
 * A golden test compares what the code does now with a JSON file written from the code at the WP1 fork state
 * (commit e160656). A deliberate change of behaviour is made by rewriting the file with LGA_GOLDEN_UPDATE=1, reviewing
 * the diff and documenting it in the work package's notes; the test then skips with a message instead of passing, so
 * an update run can never be mistaken for a green run.
 *
 * The layout keeps short records on one line (see encode()), so that a diff of a fixture shows each changed record
 * as one changed line.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Support;

final class GoldenJson {

    public const DIR = __DIR__ . '/../fixtures/golden/';

    /** Longest one-line form a nested value keeps (longer ones are spread over several lines). */
    private const LINE = 150;

    /** Whether this run rewrites the golden files (LGA_GOLDEN_UPDATE=1) instead of comparing with them. */
    public static function updating(): bool {
        return '1' === (string) getenv( 'LGA_GOLDEN_UPDATE' );
    }

    /** @return array<string,mixed> the decoded fixture ([] when the file does not exist) */
    public static function read( string $file ): array {
        $path = self::DIR . $file;
        if ( ! is_file( $path ) ) {
            return [];
        }
        $data = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
        return is_array( $data ) ? $data : [];
    }

    /** @param array<string,mixed> $data */
    public static function write( string $file, array $data ): void {
        if ( ! is_dir( self::DIR ) ) {
            mkdir( self::DIR, 0777, true );
        }
        file_put_contents( self::DIR . $file, self::encode( $data ) . "\n" );
    }

    /**
     * The first difference between two decoded JSON values, as "path: expected X, got Y" ('' when equal). Lists are
     * compared index by index, objects key by key (missing and extra keys included).
     */
    public static function firstDifference( mixed $expected, mixed $actual, string $path = '$' ): string {
        if ( is_array( $expected ) && is_array( $actual ) ) {
            $keys = array_keys( $expected + $actual );
            foreach ( $keys as $key ) {
                $sub = $path . ( is_int( $key ) ? '[' . $key . ']' : '.' . $key );
                if ( ! array_key_exists( $key, $actual ) ) {
                    return $sub . ': missing now (expected ' . self::short( $expected[ $key ] ) . ')';
                }
                if ( ! array_key_exists( $key, $expected ) ) {
                    return $sub . ': new (got ' . self::short( $actual[ $key ] ) . ')';
                }
                $diff = self::firstDifference( $expected[ $key ], $actual[ $key ], $sub );
                if ( '' !== $diff ) {
                    return $diff;
                }
            }
            if ( array_keys( $expected ) !== array_keys( $actual ) ) {
                return $path . ': same keys in a different order';
            }
            return '';
        }
        return $expected === $actual ? '' : $path . ': expected ' . self::short( $expected ) . ', got ' . self::short( $actual );
    }

    /** Pretty JSON where every value whose one-line form fits in LINE characters stays on one line. */
    public static function encode( mixed $value, int $depth = 0 ): string {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
        $one   = json_encode( $value, $flags );
        if ( ! is_array( $value ) || [] === $value || ( $depth > 0 && strlen( $one ) <= self::LINE ) ) {
            return $one;
        }
        $pad   = str_repeat( '  ', $depth + 1 );
        $end   = str_repeat( '  ', $depth );
        $lines = [];
        $list  = array_is_list( $value );
        foreach ( $value as $k => $v ) {
            $lines[] = $pad . ( $list ? '' : json_encode( (string) $k, $flags ) . ': ' ) . self::encode( $v, $depth + 1 );
        }
        return ( $list ? '[' : '{' ) . "\n" . implode( ",\n", $lines ) . "\n" . $end . ( $list ? ']' : '}' );
    }

    private static function short( mixed $value ): string {
        $json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
        $json = false === $json ? var_export( $value, true ) : $json;
        return strlen( $json ) > 300 ? substr( $json, 0, 300 ) . '…' : $json;
    }
}
