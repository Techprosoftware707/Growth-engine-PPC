<?php
/**
 * WP-CLI command registry (DESIGN.md §3.1, §5.6; WP2 skeleton): collects the subcommands the modules declare
 * (Module::cliCommands(): subcommand => class) and registers each as `wp linkgrowth <subcommand>` on WP-CLI's
 * `cli_init`. No module declares a command yet; the commands themselves (`wp linkgrowth tick`, `tasks`, `health`, …)
 * come with the server-cron work.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Cli;

use LinkGrowth\Autopilot\Core\Module;

final class CommandRegistry {

    /** The root command every subcommand hangs under. */
    public const ROOT = 'linkgrowth';

    /** @param list<Module> $modules the loaded modules (Plugin::modules()) */
    public function __construct( private array $modules ) {}

    /**
     * Subcommands of every module, in module order; a subcommand already taken by an earlier module is skipped.
     *
     * @return array<string,class-string> subcommand => class
     */
    public function commands(): array {
        $out = [];
        foreach ( $this->modules as $module ) {
            foreach ( (array) $module->cliCommands() as $name => $class ) {
                $name = trim( (string) $name );
                if ( '' === $name || isset( $out[ $name ] ) || ! is_string( $class ) || ! class_exists( $class ) ) {
                    continue;
                }
                $out[ $name ] = $class;
            }
        }
        return $out;
    }

    /**
     * `cli_init` (WP-CLI only): registers the commands through WP_CLI::add_command(), or through $add( name, class )
     * when one is given.
     *
     * @return array<string,class-string> full command name ("linkgrowth <subcommand>") => class, as registered
     */
    public function register( mixed $add = null ): array {
        if ( ! is_callable( $add ) ) {
            if ( ! class_exists( '\WP_CLI' ) ) {
                return [];
            }
            $add = [ '\WP_CLI', 'add_command' ];
        }
        $out = [];
        foreach ( $this->commands() as $name => $class ) {
            $add( self::ROOT . ' ' . $name, $class );
            $out[ self::ROOT . ' ' . $name ] = $class;
        }
        return $out;
    }
}
