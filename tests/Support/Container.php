<?php
/**
 * Test helper that returns a fresh plugin container per test (services share the fake wpdb).
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Support;

final class Container {

    private static ?\PPC\LinkGrowth\Plugin $plugin = null;

    public static function reset(): void {
        self::$plugin = null;
        $ref = new \ReflectionClass( \PPC\LinkGrowth\Plugin::class );
        $prop = $ref->getProperty( 'instance' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );
    }

    public static function plugin(): \PPC\LinkGrowth\Plugin {
        if ( null === self::$plugin ) {
            self::$plugin = \PPC\LinkGrowth\Plugin::instance();
        }
        return self::$plugin;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public static function get( string $id ): object {
        return self::plugin()->get( $id );
    }
}
