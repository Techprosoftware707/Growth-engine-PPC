<?php
/**
 * Minimal PSR-4 autoloader (no Composer dependency at runtime).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth;

final class Autoloader {

    /** @var array<string,string> prefix => base directory */
    private static array $map = [];

    private static bool $registered = false;

    public static function register( string $baseDir, string $prefix ): void {
        self::$map[ rtrim( $prefix, '\\' ) . '\\' ] = rtrim( $baseDir, '/\\' ) . DIRECTORY_SEPARATOR;
        if ( ! self::$registered ) {
            spl_autoload_register( [ self::class, 'load' ] );
            self::$registered = true;
        }
    }

    public static function load( string $class ): void {
        foreach ( self::$map as $prefix => $dir ) {
            if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
                continue;
            }
            $relative = substr( $class, strlen( $prefix ) );
            $file     = $dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
            if ( is_file( $file ) ) {
                require_once $file;
                return;
            }
        }
    }
}
