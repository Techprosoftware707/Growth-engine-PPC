<?php
/**
 * Base module: declares nothing (DESIGN.md §3.1). A module overrides only what it has.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Core;

use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Plugin;

abstract class AbstractModule implements Module {

    abstract public function id(): string;

    public function services( Container $c ): void {
        unset( $c );
    }

    public function boot( Plugin $plugin ): void {
        unset( $plugin );
    }

    public function tables(): array {
        return [];
    }

    public function settingsDefaults(): array {
        return [];
    }

    public function settingsRules(): array {
        return [];
    }

    public function jobs(): array {
        return [];
    }

    public function tasks(): array {
        return [];
    }

    public function restControllers(): array {
        return [];
    }

    public function adminPages(): array {
        return [];
    }

    public function settingsTabs(): array {
        return [];
    }

    public function healthChecks(): array {
        return [];
    }

    public function cliCommands(): array {
        return [];
    }
}
