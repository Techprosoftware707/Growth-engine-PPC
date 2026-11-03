<?php
/**
 * Base REST controller: permissions, rate limiting, input helpers, response helpers.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Admin\Rest;

use PPC\LinkGrowth\Admin\Capabilities;
use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Support\Logger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

abstract class Controller {

    /** Write requests (POST/PUT/PATCH/DELETE) allowed per user per minute. */
    public const RATE_LIMIT_PER_MINUTE = 60;

    /** Maximum length of raw response excerpts returned to the UI. */
    public const RESPONSE_EXCERPT_MAX = 2000;

    /** @var array<int,array{body:?string,file:?string}> raw (non-JSON) responses awaiting serving, keyed by object id */
    private static array $raw = [];

    private static bool $rawHooked = false;

    public function __construct( protected Container $c ) {}

    /** Registers this controller's routes under the given namespace. */
    abstract public function register( string $namespace ): void;

    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    /** Capability check for every admin route. */
    public function permission( WP_REST_Request $request ): bool|WP_Error {
        unset( $request );
        if ( ! Capabilities::userCan() ) {
            return new WP_Error(
                'ppclg_forbidden',
                __( 'You are not allowed to use the Link Growth Engine.', 'ppc-link-growth-engine' ),
                [ 'status' => rest_authorization_required_code() ]
            );
        }
        return true;
    }

    /** Capability check plus per-user rate limit for write routes. */
    public function permissionWrite( WP_REST_Request $request ): bool|WP_Error {
        $allowed = $this->permission( $request );
        if ( true !== $allowed ) {
            return $allowed;
        }
        return $this->rateLimit( $request, self::RATE_LIMIT_PER_MINUTE, 'user' );
    }

    /**
     * Sliding one-minute window rate limit stored in a transient.
     *
     * @param string $scope 'user' (current user id) or 'ip' (client address).
     */
    protected function rateLimit( WP_REST_Request $request, int $perMinute = self::RATE_LIMIT_PER_MINUTE, string $scope = 'user' ): bool|WP_Error {
        unset( $request );
        $who = 'ip' === $scope ? 'ip' . substr( md5( $this->clientIp() ), 0, 16 ) : 'u' . get_current_user_id();
        $key = 'ppclg_rl_' . $who . '_' . (int) floor( time() / 60 );
        $n   = (int) get_transient( $key );
        if ( $n >= $perMinute ) {
            return new WP_Error(
                'ppclg_rate_limited',
                __( 'Too many requests. Please wait a minute and try again.', 'ppc-link-growth-engine' ),
                [ 'status' => 429 ]
            );
        }
        set_transient( $key, $n + 1, 2 * MINUTE_IN_SECONDS );
        return true;
    }

    protected function clientIp(): string {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
        return '' === $ip ? '0.0.0.0' : $ip;
    }

    // ------------------------------------------------------------------
    // Route registration
    // ------------------------------------------------------------------

    /**
     * Registers one route. Write methods get the rate-limited permission callback;
     * every handler is wrapped so an exception becomes a logged 500 instead of a fatal.
     *
     * @param string|string[]     $methods
     * @param array<string,mixed> $args
     */
    protected function route( string $namespace, string $route, string|array $methods, callable $callback, array $args = [], bool $public = false ): void {
        $list  = is_array( $methods ) ? $methods : array_map( 'trim', explode( ',', $methods ) );
        $list  = array_values( array_filter( array_map( 'strtoupper', $list ) ) );
        $write = [] !== array_intersect( $list, [ 'POST', 'PUT', 'PATCH', 'DELETE' ] );
        if ( $public ) {
            $permission = '__return_true';
        } else {
            $permission = $write ? [ $this, 'permissionWrite' ] : [ $this, 'permission' ];
        }
        register_rest_route(
            $namespace,
            $route,
            [
                [
                    'methods'             => implode( ', ', $list ),
                    'callback'            => $this->wrap( $callback ),
                    'permission_callback' => $permission,
                    'args'                => $args,
                ],
            ]
        );
    }

    private function wrap( callable $callback ): \Closure {
        return function ( WP_REST_Request $request ) use ( $callback ) {
            try {
                $result = $callback( $request );
                if ( $result instanceof WP_REST_Response || $result instanceof WP_Error ) {
                    return $result;
                }
                return $this->ok( $result );
            } catch ( \Throwable $e ) {
                try {
                    $this->logger()->error(
                        'rest',
                        'Unhandled error in REST handler: ' . $e->getMessage(),
                        [
                            'route'  => $request->get_route(),
                            'method' => $request->get_method(),
                            'file'   => basename( $e->getFile() ),
                            'line'   => $e->getLine(),
                        ]
                    );
                } catch ( \Throwable $ignored ) {
                    unset( $ignored );
                }
                return $this->fail( 'ppclg_internal_error', __( 'The request could not be completed. Check the plugin logs for details.', 'ppc-link-growth-engine' ), 500 );
            }
        };
    }

    /** Standard argument schema for list endpoints (plus named string filters). @param string[] $filters */
    protected function listArgs( array $filters = [] ): array {
        $args = [
            'page'     => [ 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ],
            'per_page' => [ 'type' => 'integer', 'default' => 25, 'sanitize_callback' => 'absint' ],
            'orderby'  => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
            'order'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
            'q'        => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
        ];
        foreach ( $filters as $name ) {
            $args[ $name ] = [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ];
        }
        return $args;
    }

    /** Argument schema for the numeric `id` URL parameter. */
    protected function idArgs(): array {
        return [
            'id' => [
                'type'              => 'integer',
                'required'          => true,
                'sanitize_callback' => 'absint',
                'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Responses
    // ------------------------------------------------------------------

    protected function ok( mixed $data, int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response( $data, $status );
    }

    /** @param array<string,mixed> $extra Additional error data (merged with status). */
    protected function fail( string $code, string $message, int $status = 400, array $extra = [] ): WP_Error {
        return new WP_Error( $code, $message, array_merge( $extra, [ 'status' => $status ] ) );
    }

    protected function notFound( string $what = 'Record' ): WP_Error {
        return $this->fail(
            'ppclg_not_found',
            sprintf(
                /* translators: %s: object name */
                __( '%s not found.', 'ppc-link-growth-engine' ),
                $what
            ),
            404
        );
    }

    /**
     * A non-JSON response (HTML page, file). The body is echoed by {@see serveRaw()}.
     *
     * @param array<string,string> $headers
     */
    protected function raw( string $body, string $contentType, int $status = 200, array $headers = [] ): WP_REST_Response {
        $response = new WP_REST_Response( null, $status );
        $response->header( 'Content-Type', $contentType );
        foreach ( $headers as $name => $value ) {
            $response->header( $name, $value );
        }
        self::$raw[ spl_object_id( $response ) ] = [ 'body' => $body, 'file' => null ];
        self::hookRaw();
        return $response;
    }

    /** Streams a local file as a download. */
    protected function rawFile( string $path, string $contentType, string $filename ): WP_REST_Response {
        $response = new WP_REST_Response( null, 200 );
        $response->header( 'Content-Type', $contentType );
        $response->header( 'Content-Disposition', 'attachment; filename="' . rawurlencode( $filename ) . '"' );
        $response->header( 'Content-Length', (string) (int) filesize( $path ) );
        $response->header( 'Cache-Control', 'private, no-store' );
        $response->header( 'X-Content-Type-Options', 'nosniff' );
        self::$raw[ spl_object_id( $response ) ] = [ 'body' => null, 'file' => $path ];
        self::hookRaw();
        return $response;
    }

    private static function hookRaw(): void {
        if ( self::$rawHooked ) {
            return;
        }
        self::$rawHooked = true;
        add_filter( 'rest_pre_serve_request', [ self::class, 'serveRaw' ], 10, 4 );
    }

    /**
     * `rest_pre_serve_request` filter: outputs raw bodies registered via raw()/rawFile().
     *
     * @param mixed $result
     * @param mixed $request
     * @param mixed $server
     */
    public static function serveRaw( bool $served, $result, $request, $server ): bool {
        unset( $server );
        if ( $served || ! $result instanceof WP_REST_Response ) {
            return $served;
        }
        $id = spl_object_id( $result );
        if ( ! isset( self::$raw[ $id ] ) ) {
            return $served;
        }
        $raw = self::$raw[ $id ];
        unset( self::$raw[ $id ] );
        if ( $request instanceof WP_REST_Request && 'HEAD' === $request->get_method() ) {
            return true;
        }
        if ( null !== $raw['file'] ) {
            if ( is_readable( $raw['file'] ) ) {
                readfile( $raw['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
            }
            return true;
        }
        echo (string) $raw['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- complete HTML document escaped by its renderer.
        return true;
    }

    // ------------------------------------------------------------------
    // Input helpers
    // ------------------------------------------------------------------

    protected function int( WP_REST_Request $request, string $key, int $default = 0 ): int {
        $value = $request->get_param( $key );
        if ( null === $value || '' === $value || is_array( $value ) ) {
            return $default;
        }
        return is_numeric( $value ) ? (int) $value : $default;
    }

    /** Single-line text (sanitize_text_field, trimmed, length-capped). */
    protected function str( WP_REST_Request $request, string $key, string $default = '', int $max = 1000 ): string {
        $value = $request->get_param( $key );
        if ( null === $value || is_array( $value ) || is_object( $value ) ) {
            return $default;
        }
        $clean = trim( sanitize_text_field( (string) $value ) );
        return '' === $clean ? $default : mb_substr( $clean, 0, $max );
    }

    /** Multi-line text (sanitize_textarea_field, length-capped). */
    protected function text( WP_REST_Request $request, string $key, string $default = '', int $max = 20000 ): string {
        $value = $request->get_param( $key );
        if ( null === $value || is_array( $value ) || is_object( $value ) ) {
            return $default;
        }
        $clean = trim( sanitize_textarea_field( (string) $value ) );
        return '' === $clean ? $default : mb_substr( $clean, 0, $max );
    }

    protected function bool( WP_REST_Request $request, string $key, bool $default = false ): bool {
        $value = $request->get_param( $key );
        if ( null === $value || '' === $value || is_array( $value ) ) {
            return $default;
        }
        if ( is_bool( $value ) ) {
            return $value;
        }
        $parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
        return null === $parsed ? $default : $parsed;
    }

    /** @return array<mixed> */
    protected function arr( WP_REST_Request $request, string $key ): array {
        $value = $request->get_param( $key );
        if ( is_array( $value ) ) {
            return $value;
        }
        if ( is_string( $value ) && '' !== $value ) {
            $decoded = json_decode( $value, true );
            return is_array( $decoded ) ? $decoded : [];
        }
        return [];
    }

    /** @return int[] */
    protected function intList( WP_REST_Request $request, string $key, int $max = 500 ): array {
        $out = [];
        foreach ( array_slice( $this->arr( $request, $key ), 0, $max ) as $v ) {
            if ( is_numeric( $v ) && (int) $v > 0 ) {
                $out[] = (int) $v;
            }
        }
        return array_values( array_unique( $out ) );
    }

    /** Numeric `id` from the URL. */
    protected function id( WP_REST_Request $request ): int {
        return max( 0, (int) $request->get_param( 'id' ) );
    }

    /**
     * Pagination/sorting/search parameters, clamped.
     *
     * @return array{page:int,per_page:int,orderby:string,order:string,q:string}
     */
    protected function paginationArgs( WP_REST_Request $request, string $defaultOrderBy = 'id', string $defaultOrder = 'DESC' ): array {
        $orderBy = strtolower( $this->str( $request, 'orderby', $defaultOrderBy, 40 ) );
        $orderBy = preg_replace( '/[^a-z_]/', '', $orderBy ) ?: $defaultOrderBy;
        return [
            'page'     => max( 1, $this->int( $request, 'page', 1 ) ),
            'per_page' => min( 100, max( 1, $this->int( $request, 'per_page', 25 ) ) ),
            'orderby'  => $orderBy,
            'order'    => 'ASC' === strtoupper( $this->str( $request, 'order', $defaultOrder, 4 ) ) ? 'ASC' : 'DESC',
            'q'        => $this->str( $request, 'q', '', 200 ),
        ];
    }

    /**
     * Comma-separated or array filter value → list of sanitized keys (empty when absent).
     *
     * @return string[]
     */
    protected function keyList( WP_REST_Request $request, string $key ): array {
        $value = $request->get_param( $key );
        if ( is_array( $value ) ) {
            $parts = $value;
        } elseif ( is_string( $value ) && '' !== $value ) {
            $parts = explode( ',', $value );
        } else {
            return [];
        }
        $out = [];
        foreach ( $parts as $p ) {
            if ( ! is_scalar( $p ) ) {
                continue;
            }
            $k = sanitize_key( (string) $p );
            if ( '' !== $k ) {
                $out[] = $k;
            }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Recursively sanitises arbitrary JSON input (strings via sanitize_textarea_field, keys restricted).
     */
    protected function sanitizeDeep( mixed $value, int $depth = 0 ): mixed {
        if ( $depth > 8 ) {
            return null;
        }
        if ( is_array( $value ) ) {
            $out = [];
            $n   = 0;
            foreach ( $value as $k => $v ) {
                if ( ++$n > 500 ) {
                    break;
                }
                if ( is_int( $k ) ) {
                    $key = $k;
                } else {
                    $key = preg_replace( '/[^A-Za-z0-9_\-\.]/', '', (string) $k ) ?? '';
                    if ( '' === $key ) {
                        continue;
                    }
                }
                $clean = $this->sanitizeDeep( $v, $depth + 1 );
                if ( null !== $clean || null === $v ) {
                    $out[ $key ] = $clean;
                }
            }
            return $out;
        }
        if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
            return $value;
        }
        if ( is_string( $value ) ) {
            return mb_substr( sanitize_textarea_field( $value ), 0, 20000 );
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Shared services / helpers
    // ------------------------------------------------------------------

    protected function userId(): int {
        return (int) get_current_user_id();
    }

    protected function logger(): Logger {
        return $this->c->get( Logger::class );
    }

    /** Records an audit entry for the current actor (never throws). @param array<string,mixed> $details */
    protected function audit( string $action, string $objectType = '', int $objectId = 0, array $details = [] ): void {
        try {
            $this->c->get( AuditLog::class )->record( AuditLog::currentActor(), $action, $objectType, $objectId, $details );
        } catch ( \Throwable $e ) {
            unset( $e );
        }
    }

    /** Runs a callable, returning $default (and logging) on any exception. */
    protected function safe( callable $fn, mixed $default = null, string $what = '' ): mixed {
        try {
            return $fn();
        } catch ( \Throwable $e ) {
            try {
                $this->logger()->warning( 'rest', ( '' !== $what ? $what . ': ' : '' ) . $e->getMessage() );
            } catch ( \Throwable $ignored ) {
                unset( $ignored );
            }
            return $default;
        }
    }

    /**
     * Compact prospect summaries for a set of ids (bulk fetch).
     *
     * @param int[] $ids
     * @return array<int,array{id:int,domain:string,name:string,status:string,score:?int}>
     */
    protected function prospectIndex( array $ids ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $i ): bool => $i > 0 ) ) );
        if ( [] === $ids ) {
            return [];
        }
        $out = [];
        try {
            $rows = $this->c->get( Prospects::class )->findBy( [ 'id' => $ids ], 'id', 'ASC', count( $ids ) );
        } catch ( \Throwable $e ) {
            return [];
        }
        foreach ( $rows as $row ) {
            $out[ (int) $row['id'] ] = [
                'id'     => (int) $row['id'],
                'domain' => (string) ( $row['domain'] ?? '' ),
                'name'   => (string) ( $row['name'] ?? '' ),
                'status' => (string) ( $row['status'] ?? '' ),
                'score'  => isset( $row['score'] ) ? (int) $row['score'] : null,
            ];
        }
        return $out;
    }

    /**
     * Adds prospect_domain / prospect_name / prospect_status to each item by its prospect_id.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    protected function attachProspects( array $items, string $idKey = 'prospect_id' ): array {
        $index = $this->prospectIndex( array_map( static fn( array $i ): int => (int) ( $i[ $idKey ] ?? 0 ), $items ) );
        foreach ( $items as &$item ) {
            $p                       = $index[ (int) ( $item[ $idKey ] ?? 0 ) ] ?? null;
            $item['prospect_domain'] = $p['domain'] ?? '';
            $item['prospect_name']   = $p['name'] ?? '';
            $item['prospect_status'] = $p['status'] ?? '';
        }
        unset( $item );
        return $items;
    }

    /**
     * Prospect row without heavy crawl payloads (page text; in list mode also link/form dumps and raw provider data).
     *
     * @param array<string,mixed> $prospect
     * @return array<string,mixed>
     */
    protected function slimProspect( array $prospect, bool $list = false ): array {
        $heavy   = $list ? [ 'text', 'links', 'forms', 'jsonLd', 'internalPaths', 'externalDomains', 'h2', 'h3', 'emails' ] : [ 'text' ];
        $profile = is_array( $prospect['profile'] ?? null ) ? $prospect['profile'] : [];
        if ( is_array( $profile['home'] ?? null ) ) {
            foreach ( $heavy as $k ) {
                unset( $profile['home'][ $k ] );
            }
        }
        if ( is_array( $profile['pages'] ?? null ) ) {
            foreach ( $profile['pages'] as $i => $page ) {
                if ( ! is_array( $page ) ) {
                    continue;
                }
                foreach ( $heavy as $k ) {
                    unset( $profile['pages'][ $i ][ $k ] );
                }
            }
        }
        if ( $list && is_array( $profile['submission_form'] ?? null ) ) {
            $profile['submission_form'] = [
                'kind'   => $profile['submission_form']['kind'] ?? '',
                'action' => $profile['submission_form']['action'] ?? '',
                'fields' => is_array( $profile['submission_form']['fields'] ?? null ) ? count( $profile['submission_form']['fields'] ) : 0,
            ];
        }
        $prospect['profile'] = $profile;
        if ( $list && is_array( $prospect['metrics'] ?? null ) && is_array( $prospect['metrics']['providers'] ?? null ) ) {
            foreach ( $prospect['metrics']['providers'] as $id => $m ) {
                if ( is_array( $m ) ) {
                    unset( $prospect['metrics']['providers'][ $id ]['raw'] );
                }
            }
        }
        foreach ( [ 'score', 'relevance', 'score_confidence', 'automation_allowed', 'prospect_id' ] as $k ) {
            if ( isset( $prospect[ $k ] ) && is_numeric( $prospect[ $k ] ) ) {
                $prospect[ $k ] = (int) $prospect[ $k ];
            }
        }
        return $prospect;
    }

    /**
     * Submission row with the stored response excerpt capped at 2000 characters per string.
     *
     * @param array<string,mixed> $submission
     * @return array<string,mixed>
     */
    protected function slimSubmission( array $submission ): array {
        if ( is_array( $submission['response'] ?? null ) ) {
            $submission['response'] = self::truncateStrings( $submission['response'], self::RESPONSE_EXCERPT_MAX );
        } elseif ( is_string( $submission['response'] ?? null ) ) {
            $submission['response'] = mb_substr( (string) $submission['response'], 0, self::RESPONSE_EXCERPT_MAX );
        }
        foreach ( [ 'prospect_id', 'opportunity_id', 'attempts', 'reviewed_by' ] as $k ) {
            if ( isset( $submission[ $k ] ) && is_numeric( $submission[ $k ] ) ) {
                $submission[ $k ] = (int) $submission[ $k ];
            }
        }
        return $submission;
    }

    /** Recursively caps string lengths (HTML stripped) inside a structure. */
    protected static function truncateStrings( mixed $value, int $max, int $depth = 0 ): mixed {
        if ( $depth > 8 ) {
            return null;
        }
        if ( is_array( $value ) ) {
            $out = [];
            foreach ( $value as $k => $v ) {
                $out[ $k ] = self::truncateStrings( $v, $max, $depth + 1 );
            }
            return $out;
        }
        if ( is_string( $value ) && mb_strlen( $value ) > $max ) {
            $stripped = wp_strip_all_tags( $value, true );
            return mb_substr( $stripped, 0, $max ) . ( mb_strlen( $stripped ) > $max ? '…' : '' );
        }
        return $value;
    }

    /** Casts common numeric columns on a row. @param array<string,mixed> $row @param string[] $keys */
    protected static function castInts( array $row, array $keys ): array {
        foreach ( $keys as $k ) {
            if ( array_key_exists( $k, $row ) && null !== $row[ $k ] && is_numeric( $row[ $k ] ) ) {
                $row[ $k ] = (int) $row[ $k ];
            }
        }
        return $row;
    }
}
