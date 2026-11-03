<?php
/**
 * In-memory HTTP fixture registry used by the wp_remote_request() stub.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Support;

final class HttpFixtures {

    /** @var array<string,array{status:int,headers:array<string,string>,body:string}> */
    private static array $exact = [];

    /** @var array<int,array{pattern:string,response:array{status:int,headers:array<string,string>,body:string}}> */
    private static array $patterns = [];

    /** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
    public static array $requests = [];

    public static ?\Closure $handler = null;

    /** @var array<string,bool> hosts that have at least one registered fixture */
    private static array $knownHosts = [];

    public static function reset(): void {
        self::$exact      = [];
        self::$patterns   = [];
        self::$requests   = [];
        self::$handler    = null;
        self::$knownHosts = [];
    }

    public static function add( string $url, string $body, int $status = 200, array $headers = [ 'content-type' => 'text/html; charset=utf-8' ] ): void {
        self::$exact[ rtrim( $url, '/' ) ] = [ 'status' => $status, 'headers' => $headers, 'body' => $body ];
        $host = (string) parse_url( $url, PHP_URL_HOST );
        if ( '' !== $host ) {
            self::$knownHosts[ $host ] = true;
        }
    }

    public static function addPattern( string $regex, string $body, int $status = 200, array $headers = [ 'content-type' => 'text/html; charset=utf-8' ] ): void {
        self::$patterns[] = [ 'pattern' => $regex, 'response' => [ 'status' => $status, 'headers' => $headers, 'body' => $body ] ];
    }

    /** Load every file under tests/fixtures/sites as https://{domain}/{path}. */
    public static function loadSites( string $dir ): void {
        $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            $rel    = substr( (string) $file, strlen( rtrim( $dir, '/' ) ) + 1 );
            $parts  = explode( '/', $rel );
            $domain = array_shift( $parts );
            $path   = implode( '/', $parts );
            $path   = preg_replace( '#(^|/)index\.html$#', '$1', $path ) ?? $path;
            $body   = (string) file_get_contents( (string) $file );
            $ct     = str_ends_with( $rel, '.txt' ) ? 'text/plain' : 'text/html; charset=utf-8';
            self::add( 'https://' . $domain . '/' . $path, $body, 200, [ 'content-type' => $ct ] );
            self::add( 'http://' . $domain . '/' . $path, $body, 200, [ 'content-type' => $ct ] );
        }
        self::addPattern( '#^https?://(janoshik|biotechassociation|sciencecouncil|protocols|northernlabsupply|pacificreagents|prairiescientific|maritimebiotech)\.test/#', '<html><head><title>Live external site</title></head><body><p>ok</p></body></html>' );
        self::addPattern( '#^https?://deadlab\.test/#', '<html><body>404</body></html>', 404 );
        self::addPattern( '#^https?://gone\.test/#', '<html><body>410</body></html>', 410 );
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function respond( string $url, array $args ): array|\WP_Error {
        self::$requests[] = [ 'method' => strtoupper( (string) ( $args['method'] ?? 'GET' ) ), 'url' => $url, 'args' => $args ];
        if ( self::$handler ) {
            $r = ( self::$handler )( $url, $args );
            if ( null !== $r ) {
                return $r;
            }
        }
        $key = rtrim( $url, '/' );
        $res = self::$exact[ $key ] ?? null;
        if ( ! $res ) {
            foreach ( self::$patterns as $p ) {
                if ( preg_match( $p['pattern'], $url ) ) {
                    $res = $p['response'];
                    break;
                }
            }
        }
        if ( ! $res ) {
            $host = (string) parse_url( $url, PHP_URL_HOST );
            if ( isset( self::$knownHosts[ $host ] ) ) {
                $res = [ 'status' => 404, 'headers' => [ 'content-type' => 'text/html' ], 'body' => '<html><body>404</body></html>' ];
            } else {
                return new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: ' . $host );
            }
        }
        return [
            'response' => [ 'code' => $res['status'], 'message' => 'OK' ],
            'headers'  => $res['headers'],
            'body'     => $res['body'],
            'cookies'  => [],
        ];
    }
}
