<?php
/**
 * Tiny service container with lazy singletons.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth;

use RuntimeException;

final class Container {

    /** @var array<string,callable> */
    private array $factories = [];

    /** @var array<string,object> */
    private array $instances = [];

    public function singleton( string $id, callable $factory ): void {
        $this->factories[ $id ] = $factory;
    }

    public function has( string $id ): bool {
        return isset( $this->factories[ $id ] ) || isset( $this->instances[ $id ] );
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get( string $id ): object {
        if ( isset( $this->instances[ $id ] ) ) {
            return $this->instances[ $id ];
        }
        if ( ! isset( $this->factories[ $id ] ) ) {
            if ( class_exists( $id ) ) {
                // Zero-argument fallback for simple services.
                $this->instances[ $id ] = new $id();
                return $this->instances[ $id ];
            }
            throw new RuntimeException( sprintf( 'Service "%s" is not registered.', $id ) );
        }
        $this->instances[ $id ] = ( $this->factories[ $id ] )( $this );
        return $this->instances[ $id ];
    }

    public function set( string $id, object $instance ): void {
        $this->instances[ $id ] = $instance;
    }
}
