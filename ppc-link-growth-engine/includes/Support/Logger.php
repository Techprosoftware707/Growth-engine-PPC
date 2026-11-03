<?php
/**
 * Leveled logger writing to the plugin log table (with redaction) and PHP error_log fallback.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Support;

use PPC\LinkGrowth\Repositories\Logs;

final class Logger {

    private const LEVELS = [ 'debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3 ];

    public function __construct( private Logs $logs, private Settings $settings ) {}

    /** @param array<string,mixed> $context */
    public function debug( string $channel, string $message, array $context = [] ): void {
        $this->log( 'debug', $channel, $message, $context );
    }

    /** @param array<string,mixed> $context */
    public function info( string $channel, string $message, array $context = [] ): void {
        $this->log( 'info', $channel, $message, $context );
    }

    /** @param array<string,mixed> $context */
    public function warning( string $channel, string $message, array $context = [] ): void {
        $this->log( 'warning', $channel, $message, $context );
    }

    /** @param array<string,mixed> $context */
    public function error( string $channel, string $message, array $context = [] ): void {
        $this->log( 'error', $channel, $message, $context );
    }

    /** @param array<string,mixed> $context */
    public function log( string $level, string $channel, string $message, array $context = [] ): void {
        $min = (string) $this->settings->get( 'advanced.log_level', 'info' );
        if ( ( self::LEVELS[ $level ] ?? 1 ) < ( self::LEVELS[ $min ] ?? 1 ) ) {
            return;
        }
        $message = Redactor::redactString( $message );
        $context = Redactor::redact( $context );
        try {
            $this->logs->insert(
                [
                    'level'      => $level,
                    'channel'    => substr( $channel, 0, 40 ),
                    'message'    => substr( $message, 0, 60000 ),
                    'context'    => wp_json_encode( $context ),
                    'created_at' => gmdate( 'Y-m-d H:i:s' ),
                ]
            );
        } catch ( \Throwable $e ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( sprintf( '[ppclg][%s][%s] %s %s', $level, $channel, $message, wp_json_encode( $context ) ) );
        }
        if ( 'error' === $level && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( sprintf( '[ppclg][error][%s] %s', $channel, $message ) );
        }
    }
}
