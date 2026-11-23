<?php
/**
 * Describes the plugin's wiring in a form that stays the same when code moves between namespaces (WP2 part 0).
 *
 * Shared by tests/Unit/Wiring/WiringSnapshotTest.php (the parts that need no WordPress) and
 * tests/integration/wiring-snapshot.php (hooks, REST server, cron and the live tables on the LGA test site).
 *
 * A callback is written as ShortClass::method (no namespace: WP2 regroups classes into modules, which must not
 * count as a change). A closure that resolves a service on first use (Plugin::lazy() today) is written as
 * "lazy:ShortClass::method", so a hook that stops being lazy shows up (the 1.2.4 rule: a visitor's request builds no
 * service). A REST handler wrapped by the base controller is written as the handler it wraps.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Support;

final class Wiring {

    /** Root namespace of the plugin's classes. */
    public const ROOT = 'LinkGrowth\\Autopilot\\';

    /** The class name without its namespace. */
    public static function shortClass( string|object $class ): string {
        $name = is_object( $class ) ? get_class( $class ) : ltrim( $class, '\\' );
        if ( str_contains( $name, '@anonymous' ) ) {
            return 'class@anonymous';
        }
        $pos = strrpos( $name, '\\' );
        return false === $pos ? $name : substr( $name, $pos + 1 );
    }

    /** Whether $class (a name or an object) belongs to the plugin (not to its tests). */
    public static function isPluginClass( string|object $class ): bool {
        $name = is_object( $class ) ? get_class( $class ) : ltrim( $class, '\\' );
        return str_starts_with( $name, self::ROOT ) && ! str_starts_with( $name, self::ROOT . 'Tests\\' );
    }

    /** A stable description of a callback (see the class comment). */
    public static function callback( mixed $cb ): string {
        if ( is_string( $cb ) ) {
            return str_contains( $cb, '::' ) ? self::shortClass( (string) strstr( $cb, '::', true ) ) . strstr( $cb, '::' ) : $cb;
        }
        if ( is_array( $cb ) && 2 === count( $cb ) && isset( $cb[0], $cb[1] ) && is_string( $cb[1] ) ) {
            return self::shortClass( is_object( $cb[0] ) ? $cb[0] : (string) $cb[0] ) . '::' . $cb[1];
        }
        if ( $cb instanceof \Closure ) {
            return self::closure( $cb );
        }
        if ( is_object( $cb ) && method_exists( $cb, '__invoke' ) ) {
            return self::shortClass( $cb ) . '::__invoke';
        }
        return get_debug_type( $cb );
    }

    /** The callable a closure wraps, when it captured one (the REST wrapper), else null. */
    public static function wrapped( \Closure $closure ): mixed {
        foreach ( self::used( $closure ) as $value ) {
            if ( $value instanceof \Closure || ( is_array( $value ) && 2 === count( $value ) && isset( $value[0], $value[1] ) && is_string( $value[1] ) && ( is_object( $value[0] ) || is_string( $value[0] ) ) ) ) {
                return $value;
            }
        }
        return null;
    }

    /** @return array<string,mixed> the variables a closure captured with `use` */
    public static function used( \Closure $closure ): array {
        $ref = new \ReflectionFunction( $closure );
        return method_exists( $ref, 'getClosureUsedVariables' ) ? $ref->getClosureUsedVariables() : $ref->getStaticVariables();
    }

    private static function closure( \Closure $closure ): string {
        $used = self::used( $closure );
        // A lazy service callback: a class name and one of its methods.
        foreach ( $used as $id ) {
            if ( ! is_string( $id ) || ! class_exists( $id ) ) {
                continue;
            }
            foreach ( $used as $method ) {
                if ( is_string( $method ) && $method !== $id && method_exists( $id, $method ) ) {
                    return 'lazy:' . self::shortClass( $id ) . '::' . $method;
                }
            }
        }
        $inner = self::wrapped( $closure );
        if ( null !== $inner ) {
            return self::callback( $inner );
        }
        $ref   = new \ReflectionFunction( $closure );
        $scope = $ref->getClosureScopeClass();
        if ( null !== $scope ) {
            return self::shortClass( $scope->getName() ) . '::{closure}';
        }
        return basename( (string) $ref->getFileName() ) . '::{closure}';
    }

    /** The PHP file that defines a callback ('' when unknown, e.g. an internal function). */
    public static function file( mixed $cb ): string {
        try {
            if ( $cb instanceof \Closure ) {
                return (string) ( new \ReflectionFunction( $cb ) )->getFileName();
            }
            if ( is_string( $cb ) && str_contains( $cb, '::' ) ) {
                [ $class, $method ] = explode( '::', $cb, 2 );
                return (string) ( new \ReflectionMethod( $class, $method ) )->getFileName();
            }
            if ( is_string( $cb ) && function_exists( $cb ) ) {
                return (string) ( new \ReflectionFunction( $cb ) )->getFileName();
            }
            if ( is_array( $cb ) && isset( $cb[0], $cb[1] ) && is_string( $cb[1] ) ) {
                return (string) ( new \ReflectionMethod( $cb[0], $cb[1] ) )->getFileName();
            }
            if ( is_object( $cb ) ) {
                return (string) ( new \ReflectionClass( $cb ) )->getFileName();
            }
        } catch ( \ReflectionException $e ) {
            unset( $e );
        }
        return '';
    }

    /**
     * Settings tree flattened to dot paths; lists (and empty arrays) stay values.
     *
     * @param array<string,mixed> $tree
     * @return array<string,mixed>
     */
    public static function flatten( array $tree, string $prefix = '' ): array {
        $out = [];
        foreach ( $tree as $key => $value ) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
            if ( is_array( $value ) && [] !== $value && ! array_is_list( $value ) ) {
                $out += self::flatten( $value, $path );
            } else {
                $out[ $path ] = $value;
            }
        }
        return $out;
    }

    /** A nested array holding $value at the dot path. @return array<string,mixed> */
    public static function nest( string $path, mixed $value ): array {
        foreach ( array_reverse( explode( '.', $path ) ) as $segment ) {
            $value = [ $segment => $value ];
        }
        return $value;
    }

    /**
     * CREATE TABLE statements as table => {columns, keys}: the table prefix written as {prefix}, white space collapsed,
     * the charset clause dropped. Columns keep their order (the order the database shows them in).
     *
     * @param string[] $statements
     * @return array<string,array{columns:string[],keys:string[]}>
     */
    public static function tables( array $statements, string $prefix ): array {
        $out = [];
        foreach ( $statements as $sql ) {
            if ( ! preg_match( '/CREATE TABLE\s+`?([A-Za-z0-9_]+)`?\s*\((.*)\)[^)]*$/s', trim( $sql ), $m ) ) {
                $out[ 'unparsed:' . md5( $sql ) ] = [ 'columns' => [ preg_replace( '/\s+/', ' ', $sql ) ?? $sql ], 'keys' => [] ];
                continue;
            }
            $name = str_starts_with( $m[1], $prefix ) ? '{prefix}' . substr( $m[1], strlen( $prefix ) ) : $m[1];
            $cols = [];
            $keys = [];
            foreach ( preg_split( '/,\s*\n/', trim( $m[2] ) ) ?: [] as $line ) {
                $line = trim( preg_replace( '/\s+/', ' ', $line ) ?? $line, " ,\t\n" );
                if ( '' === $line ) {
                    continue;
                }
                if ( preg_match( '/^(PRIMARY KEY|UNIQUE KEY|KEY|INDEX|UNIQUE|FULLTEXT)\b/i', $line ) ) {
                    $keys[] = $line;
                } else {
                    $cols[] = $line;
                }
            }
            $out[ $name ] = [ 'columns' => $cols, 'keys' => $keys ];
        }
        ksort( $out );
        return $out;
    }

    /**
     * REST route definitions (path => list of register_rest_route() argument arrays, as the unit stub records them or as
     * WP_REST_Server::get_routes() returns them) as one entry per method and path, in registration order.
     *
     * @param array<string,array<int,mixed>> $routes
     * @return array<int,array<string,mixed>>
     */
    public static function restRoutes( array $routes, string $namespace ): array {
        $out = [];
        foreach ( $routes as $path => $registrations ) {
            $path = '/' . ltrim( (string) $path, '/' );
            if ( ! str_starts_with( $path, '/' . $namespace . '/' ) && $path !== '/' . $namespace ) {
                continue;
            }
            foreach ( self::routeDefinitions( (array) $registrations ) as $def ) {
                $methods = $def['methods'] ?? 'GET';
                if ( is_array( $methods ) ) {
                    $methods = array_is_list( $methods ) ? $methods : array_keys( array_filter( $methods ) ); // WordPress: [ 'GET' => true ]
                } else {
                    $methods = preg_split( '/[,\s]+/', strtoupper( (string) $methods ) ) ?: [];
                }
                $callback = $def['callback'] ?? null;
                $entry    = [
                    'callback'   => self::callback( $callback ),
                    'permission' => self::callback( $def['permission_callback'] ?? null ),
                ];
                if ( $callback instanceof \Closure && array_key_exists( 'rateLimitWrites', self::used( $callback ) ) ) {
                    $entry['rate_limited'] = (bool) self::used( $callback )['rateLimitWrites'];
                }
                $args = self::args( is_array( $def['args'] ?? null ) ? $def['args'] : [] );
                if ( [] !== $args ) {
                    $entry['args'] = $args;
                }
                foreach ( array_values( array_filter( array_map( 'strval', $methods ) ) ) as $method ) {
                    $out[] = array_merge( [ 'route' => strtoupper( $method ) . ' ' . $path ], $entry );
                }
            }
        }
        return $out;
    }

    /** @param array<int|string,mixed> $registrations @return array<int,array<string,mixed>> */
    private static function routeDefinitions( array $registrations ): array {
        $defs = [];
        foreach ( $registrations as $reg ) {
            if ( ! is_array( $reg ) ) {
                continue;
            }
            if ( isset( $reg['callback'] ) || isset( $reg['methods'] ) ) {
                $defs[] = $reg; // WP_REST_Server::get_routes(): path => [ handler, … ]
                continue;
            }
            foreach ( $reg as $def ) { // the unit stub: path => [ [ handler, … ], … ]
                if ( is_array( $def ) && ( isset( $def['callback'] ) || isset( $def['methods'] ) ) ) {
                    $defs[] = $def;
                }
            }
        }
        return $defs;
    }

    /** @param array<string,mixed> $args @return array<string,array<string,mixed>> */
    private static function args( array $args ): array {
        $out = [];
        foreach ( $args as $name => $schema ) {
            if ( ! is_array( $schema ) ) {
                continue;
            }
            $row = [];
            foreach ( [ 'type', 'required', 'default', 'enum' ] as $k ) {
                if ( array_key_exists( $k, $schema ) ) {
                    $row[ $k ] = $schema[ $k ];
                }
            }
            foreach ( [ 'sanitize_callback' => 'sanitize', 'validate_callback' => 'validate' ] as $k => $label ) {
                if ( isset( $schema[ $k ] ) ) {
                    $row[ $label ] = $schema[ $k ] instanceof \Closure ? 'closure' : self::callback( $schema[ $k ] );
                }
            }
            $out[ (string) $name ] = $row;
        }
        ksort( $out );
        return $out;
    }
}
