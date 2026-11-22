<?php
/**
 * Test helper that returns a fresh plugin container per test (services share the fake wpdb).
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Support;

final class Container {

    private static ?\LinkGrowth\Autopilot\Plugin $plugin = null;

    public static function reset(): void {
        self::$plugin = null;
        $ref = new \ReflectionClass( \LinkGrowth\Autopilot\Plugin::class );
        $prop = $ref->getProperty( 'instance' );
        $prop->setValue( null, null );
    }

    public static function plugin(): \LinkGrowth\Autopilot\Plugin {
        if ( null === self::$plugin ) {
            self::$plugin = \LinkGrowth\Autopilot\Plugin::instance();
            // The test machine's own load and memory never pause a test (1.2.4 Automation\LoadGuard); tests of
            // those pauses set a Support\ServerResources with their own readers.
            self::$plugin->container()->set( \LinkGrowth\Autopilot\Support\ServerResources::class, self::quietServer() );
        }
        return self::$plugin;
    }

    /** A Support\ServerResources that knows nothing (no load, no CPU count, no memory), as on a server without /proc. */
    public static function quietServer(): \LinkGrowth\Autopilot\Support\ServerResources {
        return new \LinkGrowth\Autopilot\Support\ServerResources( static fn() => null, static fn() => null );
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
