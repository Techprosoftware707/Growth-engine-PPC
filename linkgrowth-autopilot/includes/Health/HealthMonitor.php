<?php
/**
 * Health monitor skeleton (DESIGN.md §5.8, WP2): collects the health checks the modules declare
 * (Module::healthChecks(), in Core\Modules order) and runs them. Scheduling (task `health_check`), caching, the state
 * transitions and their notifications, Site Health mapping and `GET /automation/health` are built on top later; this
 * class has no hook.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Health;

use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Core\Module;

final class HealthMonitor {

    private const STATUSES = [ Check::OK, Check::WARNING, Check::CRITICAL ];

    /** @param list<Module> $modules the loaded modules (Plugin::modules()) */
    public function __construct( private Container $c, private array $modules ) {}

    /**
     * The check classes of every module, each once, in module order (the first declaration wins).
     *
     * @return list<class-string<Check>>
     */
    public function checks(): array {
        $out = [];
        foreach ( $this->modules as $module ) {
            foreach ( (array) $module->healthChecks() as $class ) {
                if ( is_string( $class ) && ! in_array( $class, $out, true ) && class_exists( $class ) && is_subclass_of( $class, Check::class ) ) {
                    $out[] = $class;
                }
            }
        }
        return $out;
    }

    /**
     * Runs every check. A check that cannot be built or throws is reported as critical with the reason (it never stops
     * the others); a result that does not follow the Check shape is normalised.
     *
     * @return array<string,array{id:string,status:string,label:string,message:string,evidence:array<string,mixed>,action:array{label:string,url:string}}> id => result
     */
    public function run(): array {
        $out = [];
        foreach ( $this->checks() as $class ) {
            try {
                $check  = $this->c->get( $class );
                $id     = $check instanceof Check ? $check->id() : '';
                $result = $check instanceof Check ? $check->run() : [];
            } catch ( \Throwable $e ) {
                $id     = '';
                $result = [ 'status' => Check::CRITICAL, 'label' => $class, 'message' => $e->getMessage() ];
            }
            $id = '' !== $id ? $id : (string) ( $result['id'] ?? $class );
            if ( isset( $out[ $id ] ) ) {
                continue; // ids are unique; the first check with an id wins
            }
            $out[ $id ] = self::normalise( $id, is_array( $result ) ? $result : [] );
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $result
     * @return array{id:string,status:string,label:string,message:string,evidence:array<string,mixed>,action:array{label:string,url:string}}
     */
    private static function normalise( string $id, array $result ): array {
        $status = (string) ( $result['status'] ?? '' );
        $action = is_array( $result['action'] ?? null ) ? $result['action'] : [];
        return [
            'id'       => $id,
            'status'   => in_array( $status, self::STATUSES, true ) ? $status : Check::WARNING,
            'label'    => (string) ( $result['label'] ?? $id ),
            'message'  => (string) ( $result['message'] ?? '' ),
            'evidence' => is_array( $result['evidence'] ?? null ) ? $result['evidence'] : [],
            'action'   => [ 'label' => (string) ( $action['label'] ?? '' ), 'url' => (string) ( $action['url'] ?? '' ) ],
        ];
    }
}
