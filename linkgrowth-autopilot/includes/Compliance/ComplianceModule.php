<?php
/**
 * Module `compliance` (DESIGN.md §3.2 Compliance row): the positioning gate (storefront preflight), its settings,
 * audit job and controller, and the hooks that mark the preflight stale when the storefront changes.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Compliance;

use LinkGrowth\Autopilot\Admin\Rest\ComplianceController;
use LinkGrowth\Autopilot\Automation\Frequency;
use LinkGrowth\Autopilot\Automation\Jobs\PositioningAuditJob;
use LinkGrowth\Autopilot\Automation\TaskDefinition;
use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Content\ClaimsFilter;
use LinkGrowth\Autopilot\Core\AbstractModule;
use LinkGrowth\Autopilot\Plugin;
use LinkGrowth\Autopilot\Repositories\AuditLog;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\SettingsSchema;

final class ComplianceModule extends AbstractModule {

    public const ID = 'compliance';

    public function id(): string {
        return self::ID;
    }

    public function services( Container $c ): void {
        // Storefront preflight. Submission\Policy and Outreach\OutreachEngine resolve it lazily (and fail closed when
        // it cannot be built), so a broken audit never stops those services from being constructed.
        $c->singleton( PositioningGate::class, static fn( Container $c ) => new PositioningGate( $c->get( Settings::class ), $c->get( Logger::class ), $c->get( ClaimsFilter::class ), $c->get( AuditLog::class ) ) );
    }

    public function boot( Plugin $plugin ): void {
        // Storefront compliance: mark the preflight stale when products, product categories/tags, pages/posts or nav
        // menus change (outside is_admin(): products are also saved through REST and the WooCommerce API).
        foreach ( Plugin::STOREFRONT_HOOKS as $hook => [ $method, $args ] ) {
            add_action( $hook, $plugin->lazy( PositioningGate::class, $method, true ), 20, $args );
        }
    }

    public function settingsDefaults(): array {
        return [
            'compliance' => [
                'storefront_gate'   => true,
                // Written only by Compliance\PositioningGate::acknowledge() (POST /compliance/storefront/acknowledge).
                'acknowledged_hash' => '',
                'acknowledged_by'   => 0,
                'acknowledged_at'   => '',
            ],
        ];
    }

    /**
     * The acknowledgement is recorded only through POST /compliance/storefront/acknowledge (audited, hash-checked),
     * never from the settings form.
     */
    public function settingsRules(): array {
        return [
            'compliance.storefront_gate'   => SettingsSchema::bool(),
            'compliance.acknowledged_hash' => SettingsSchema::readOnly(),
            'compliance.acknowledged_by'   => SettingsSchema::readOnly( SettingsSchema::INT ),
            'compliance.acknowledged_at'   => SettingsSchema::readOnly(),
        ];
    }

    public function jobs(): array {
        return [ 'storefront_audit' => PositioningAuditJob::class ];
    }

    /**
     * The compliance task, with the engine's cadences (DESIGN.md §5.1; Automation\TaskDefinition explains the WP2 values).
     * The job type keeps its engine name (storefront_audit) until the scheduler package renames job types.
     *
     * @return list<TaskDefinition>
     */
    public function tasks(): array {
        return [
            new TaskDefinition( id: 'positioning_audit', jobType: 'storefront_audit', label: __( 'Positioning audit', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::DAILY, module: '', dueCheck: null, priority: PositioningAuditJob::priority(), cadence: 'daily (and when products, product categories/tags or menus change)', order: 50 ),
        ];
    }

    public function restControllers(): array {
        return [ ComplianceController::class ];
    }
}
