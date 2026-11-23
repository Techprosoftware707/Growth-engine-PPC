<?php
/**
 * One health check (DESIGN.md §5.8). Modules list their checks in Module::healthChecks(); Health\HealthMonitor runs
 * them. A check builds through the service container (a factory in its module's services(), or no constructor
 * arguments) and must not throw for an ordinary failure: it reports it.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Health;

interface Check {

    public const OK       = 'ok';
    public const WARNING  = 'warning';
    public const CRITICAL = 'critical';

    /** The check id ('opcache'); unique across modules. */
    public function id(): string;

    /**
     * @return array{id:string,status:string,label:string,message:string,evidence:array<string,mixed>,action:array{label:string,url:string}}
     *         status is one of OK, WARNING or CRITICAL
     */
    public function run(): array;
}
