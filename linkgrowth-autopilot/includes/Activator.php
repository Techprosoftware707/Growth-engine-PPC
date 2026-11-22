<?php
/**
 * Activation routine.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot;

use LinkGrowth\Autopilot\Admin\Capabilities;
use LinkGrowth\Autopilot\Automation\LoadGuard;
use LinkGrowth\Autopilot\Automation\Scheduler;
use LinkGrowth\Autopilot\Database\Migrator;
use LinkGrowth\Autopilot\Support\Settings;

final class Activator {

    public static function activate(): void {
        if ( version_compare( PHP_VERSION, LGA_MIN_PHP, '<' ) ) {
            deactivate_plugins( LGA_BASENAME );
            wp_die(
                esc_html( sprintf( '%1$s requires PHP %2$s or newer.', Plugin::NAME, LGA_MIN_PHP ) ),
                esc_html__( 'Plugin activation error', 'linkgrowth-autopilot' ),
                [ 'back_link' => true ]
            );
        }

        $plugin = Plugin::instance();

        $plugin->get( Settings::class )->ensureDefaults();
        $plugin->get( Migrator::class )->migrate( true );
        $plugin->get( Capabilities::class )->ensure( true );
        $plugin->get( Scheduler::class )->schedule();

        update_option( 'lga_activated_at', gmdate( 'c' ), false );
        // Background work waits 10 minutes after an activation (LoadGuard::UPDATE_QUIET_SECONDS), like after an update.
        $plugin->get( LoadGuard::class )->recordActivation();
        set_transient( 'lga_activation_redirect', 1, 60 );
    }
}
