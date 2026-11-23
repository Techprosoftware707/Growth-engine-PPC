<?php
/**
 * The module seam (DESIGN.md §3.1): one class per area declares everything the central wiring used to list.
 *
 * Plugin (services, boot), Database\Schema (tables), Support\Settings (defaults), Automation\JobRunner (jobs),
 * Admin\Rest\RestApi (REST controllers), Admin\Menu (admin pages, settings tabs), Health\HealthMonitor (health checks)
 * and Cli\CommandRegistry (WP-CLI commands) collect from the modules of Core\Modules (filter `lga_modules`).
 *
 * boot() runs on every request, a visitor's page view included (1.2.4 rule, see Plugin::boot()): it only adds hook
 * callbacks, reads and writes nothing in the database and builds no service. A callback that needs a service resolves
 * it when its hook fires (Plugin::lazy()); checks that read or write run or are hooked on maintenance requests only
 * (Plugin::isMaintenanceRequest()). services() runs the first time a service is needed, never during boot().
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Core;

use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Plugin;

interface Module {
    public function id(): string;                                   // 'discovery'
    public function services( Container $c ): void;                 // DI registrations
    public function boot( Plugin $plugin ): void;                   // hooks (cheap; front-end safe)
    /** @return array<string,string> table name (without prefix) => CREATE TABLE statement */
    public function tables(): array;
    /** @return array<string,mixed> settings section => defaults */
    public function settingsDefaults(): array;
    /** @return array<string,array<string,mixed>> dot.path => validation rule */
    public function settingsRules(): array;
    /** @return array<string,class-string> job type => JobInterface class */
    public function jobs(): array;
    /** @return list<\LinkGrowth\Autopilot\Automation\TaskDefinition> */
    public function tasks(): array;
    /** @return list<class-string> REST controllers */
    public function restControllers(): array;
    /** @return array<string,array{label:string,icon:string,module:string,cap:string,order:int}> SPA pages */
    public function adminPages(): array;
    /** @return array<string,array{label:string,module:string,order:int}> Settings tabs (JS module paths) */
    public function settingsTabs(): array;
    /** @return list<class-string> Health\Check implementations */
    public function healthChecks(): array;
    /** @return array<string,class-string> WP-CLI subcommand => class */
    public function cliCommands(): array;
}
