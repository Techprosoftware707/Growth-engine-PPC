<?php
/**
 * Health check `opcache` (DESIGN.md §5.8, kept from the engine): the Site Health OPcache test of
 * Health\SiteHealthTests in the health monitor's shape (good is ok, recommended is warning, critical is critical).
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Health\Checks;

use LinkGrowth\Autopilot\Health\Check;
use LinkGrowth\Autopilot\Health\SiteHealthTests;

final class OpcacheCheck implements Check {

    public const ID = 'opcache';

    public function __construct( private SiteHealthTests $tests ) {}

    public function id(): string {
        return self::ID;
    }

    public function run(): array {
        return SiteHealthResult::toCheck( self::ID, $this->tests->opcacheTest(), [ 'site_health_test' => SiteHealthTests::TEST ] );
    }
}
