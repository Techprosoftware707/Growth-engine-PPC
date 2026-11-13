<?php
/**
 * Activation routine.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth;

use PPC\LinkGrowth\Admin\Capabilities;
use PPC\LinkGrowth\Automation\Scheduler;
use PPC\LinkGrowth\Database\Migrator;
use PPC\LinkGrowth\Support\Settings;

final class Activator {

    public static function activate(): void {
        if ( version_compare( PHP_VERSION, PPCLG_MIN_PHP, '<' ) ) {
            deactivate_plugins( PPCLG_BASENAME );
            wp_die(
                esc_html( sprintf( 'PPC Link Growth Engine requires PHP %s or newer.', PPCLG_MIN_PHP ) ),
                esc_html__( 'Plugin activation error', 'ppc-link-growth-engine' ),
                [ 'back_link' => true ]
            );
        }

        $plugin = Plugin::instance();

        $plugin->get( Settings::class )->ensureDefaults();
        $plugin->get( Migrator::class )->migrate( true );
        $plugin->get( Capabilities::class )->ensure( true );
        $plugin->get( Scheduler::class )->schedule();

        update_option( 'ppclg_activated_at', gmdate( 'c' ), false );
        set_transient( 'ppclg_activation_redirect', 1, 60 );
    }
}
