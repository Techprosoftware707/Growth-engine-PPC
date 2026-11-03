<?php
/**
 * Static/runtime wiring audit (no WordPress needed):
 *  1. Every service registered in Plugin.php can be constructed (constructor signatures match).
 *  2. Every REST route the plugin registers vs. every path the admin JS calls.
 *  3. Every job type registered with the runner resolves to a class implementing JobInterface.
 *
 * Usage: php tests/integration/wiring-audit.php   (exit code 1 on problems)
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$problems = [];
$GLOBALS['wpdb'] = new \PPC\LinkGrowth\Tests\Support\FakeWpdb();
foreach ( ( new \PPC\LinkGrowth\Database\Schema() )->statements() as $sql ) {
    $GLOBALS['wpdb']->createFromMysql( $sql );
}

// 1. Services.
$plugin    = \PPC\LinkGrowth\Plugin::instance();
$container = $plugin->container();
$ref       = new ReflectionClass( $container );
$prop      = $ref->getProperty( 'factories' );
$prop->setAccessible( true );
$ids = array_keys( $prop->getValue( $container ) );
$ok  = 0;
foreach ( $ids as $id ) {
    try {
        $plugin->get( $id );
        $ok++;
    } catch ( \Throwable $e ) {
        $problems[] = "service {$id}: " . get_class( $e ) . ': ' . $e->getMessage();
    }
}
echo "Services constructed: {$ok}/" . count( $ids ) . "\n";

// 2. REST routes vs JS.
$GLOBALS['ppclg_test_routes'] = [];
$GLOBALS['ppclg_test_caps']   = [ 'ppclg_manage' => true, 'manage_options' => true ];
try {
    $plugin->get( \PPC\LinkGrowth\Admin\Rest\RestApi::class )->register();
} catch ( \Throwable $e ) {
    $problems[] = 'RestApi::register threw: ' . $e->getMessage();
}
$routes = [];
foreach ( $GLOBALS['ppclg_test_routes'] as $full => $args ) {
    $route   = preg_replace( '#^ppclg/v1#', '', $full );
    $methods = [];
    $defs    = isset( $args['callback'] ) ? [ $args ] : $args;
    foreach ( $defs as $def ) {
        if ( is_array( $def ) && isset( $def['methods'] ) ) {
            foreach ( preg_split( '/[,\s]+/', strtoupper( (string) $def['methods'] ) ) as $m ) {
                if ( '' !== $m ) {
                    $methods[] = 'PUT' === $m || 'PATCH' === $m ? 'POST' : $m;
                }
            }
            if ( empty( $def['permission_callback'] ) ) {
                $problems[] = "route {$route} has no permission_callback";
            }
        }
    }
    $routes[ $route ] = array_unique( $methods );
}
echo 'REST routes registered: ' . count( $routes ) . "\n";

$jsDir = PPCLG_DIR . 'assets/js';
$calls = [];
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $jsDir ) ) as $file ) {
    if ( ! str_ends_with( (string) $file, '.js' ) ) {
        continue;
    }
    $src = (string) file_get_contents( (string) $file );
    if ( preg_match_all( '/\b(?:api\.)?(get|post|del|put)\s*\(\s*[`\'"](\/[^`\'"]*)[`\'"]/', $src, $m, PREG_SET_ORDER ) ) {
        foreach ( $m as $hit ) {
            $calls[] = [ 'file' => basename( (string) $file ), 'method' => strtoupper( 'del' === $hit[1] ? 'DELETE' : ( 'put' === $hit[1] ? 'POST' : $hit[1] ) ), 'path' => $hit[2] ];
        }
    }
}
echo 'JS API calls found: ' . count( $calls ) . "\n";
$matchRoute = static function ( string $path, string $method ) use ( $routes ): bool {
    $path = preg_replace( '/\?.*$/', '', $path ) ?? $path;
    $path = preg_replace( '/\$\{[^}]+\}/', '123', $path ) ?? $path;
    foreach ( $routes as $route => $methods ) {
        $regex = '#^' . preg_replace( '/\(\?P<\w+>([^)]*)\)/', '$1', $route ) . '/?$#';
        if ( @preg_match( $regex, $path ) ) {
            return empty( $methods ) || in_array( $method, $methods, true );
        }
    }
    return false;
};
foreach ( $calls as $c ) {
    if ( ! $matchRoute( $c['path'], $c['method'] ) ) {
        $problems[] = "JS {$c['file']} calls {$c['method']} {$c['path']} but no matching REST route/method";
    }
}

// 3. Job types.
try {
    $runner = $plugin->get( \PPC\LinkGrowth\Automation\JobRunner::class );
    $types  = $runner->types();
    foreach ( $types as $type => $class ) {
        if ( ! class_exists( $class ) ) {
            $problems[] = "job type {$type}: class {$class} missing";
        } elseif ( ! in_array( \PPC\LinkGrowth\Automation\Jobs\JobInterface::class, class_implements( $class ) ?: [], true ) ) {
            $problems[] = "job type {$type}: {$class} does not implement JobInterface";
        }
    }
    echo 'Job types: ' . count( $types ) . "\n";
} catch ( \Throwable $e ) {
    $problems[] = 'JobRunner::types threw: ' . $e->getMessage();
}

if ( empty( $problems ) ) {
    echo "Wiring audit OK\n";
    exit( 0 );
}
echo "Problems:\n";
foreach ( $problems as $p ) {
    echo " - {$p}\n";
}
exit( 1 );
