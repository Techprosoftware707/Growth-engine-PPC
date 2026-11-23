<?php
/**
 * Registers every REST controller the modules declare under the lga/v1 namespace.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Admin\Rest;

use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Plugin;
use LinkGrowth\Autopilot\Support\Logger;

final class RestApi {

    public const NAMESPACE = 'lga/v1';

    private bool $registered = false;

    public function __construct( private Container $c ) {}

    /**
     * Controller classes: the REST controllers every module declares (Module::restControllers(), in Core\Modules
     * order, each once), then the `lga_rest_controllers` filter.
     *
     * @return array<int,class-string<Controller>>
     */
    public function controllers(): array {
        $list = [];
        foreach ( Plugin::instance()->modules() as $module ) {
            foreach ( (array) $module->restControllers() as $class ) {
                if ( is_string( $class ) && ! in_array( $class, $list, true ) ) {
                    $list[] = $class;
                }
            }
        }
        /**
         * Filters the REST controllers registered by the plugin.
         *
         * @param array<int,class-string<Controller>> $list
         */
        $filtered = apply_filters( 'lga_rest_controllers', $list );
        $out      = [];
        foreach ( (array) $filtered as $class ) {
            if ( is_string( $class ) && class_exists( $class ) && is_subclass_of( $class, Controller::class ) ) {
                $out[] = $class;
            }
        }
        return array_values( array_unique( $out ) );
    }

    /** `rest_api_init` callback. */
    public function register(): void {
        if ( $this->registered ) {
            return;
        }
        $this->registered = true;
        foreach ( $this->controllers() as $class ) {
            try {
                $controller = new $class( $this->c );
                $controller->register( self::NAMESPACE );
            } catch ( \Throwable $e ) {
                try {
                    $this->c->get( Logger::class )->error( 'rest', 'Could not register REST controller: ' . $e->getMessage(), [ 'controller' => $class ] );
                } catch ( \Throwable $ignored ) {
                    unset( $ignored );
                }
            }
        }
    }
}
