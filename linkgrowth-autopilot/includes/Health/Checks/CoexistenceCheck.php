<?php
/**
 * Health check `coexistence` (DESIGN.md §5.8, §1.4): another plugin that does the same work on this site (an
 * owner-listed conflicting plugin, another callback on the tick hook, foreign routes in the REST namespace or foreign
 * tables with the plugin's prefix), from Core\Environment's full check, the one its Site Health test runs. A warning
 * only: nothing is blocked.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Health\Checks;

use LinkGrowth\Autopilot\Core\Environment;
use LinkGrowth\Autopilot\Health\Check;

final class CoexistenceCheck implements Check {

    public const ID = 'coexistence';

    public function __construct( private Environment $environment ) {}

    public function id(): string {
        return self::ID;
    }

    public function run(): array {
        $conflicts = $this->environment->conflicts( true );
        $none      = [] === $conflicts;
        return [
            'id'       => self::ID,
            'status'   => $none ? Check::OK : Check::WARNING,
            'label'    => $none
                ? __( 'No other plugin does the same backlink work on this site', 'linkgrowth-autopilot' )
                : __( 'Another plugin may do the same backlink work on this site', 'linkgrowth-autopilot' ),
            'message'  => implode( ' ', array_map( static fn( array $c ): string => $c['message'], $conflicts ) ),
            'evidence' => [
                'site_health_test' => Environment::TEST,
                'conflicts'        => array_map( static fn( array $c ): string => $c['id'], $conflicts ),
            ],
            'action'   => $none ? [ 'label' => '', 'url' => '' ] : SiteHealthResult::siteHealthAction(),
        ];
    }
}
