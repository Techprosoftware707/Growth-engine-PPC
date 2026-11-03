<?php
/**
 * PHPUnit bootstrap: minimal WordPress function stubs so the plugin's pure logic can be unit-tested
 * without a WordPress install. Integration tests run against a real WordPress (see tests/integration).
 */

declare(strict_types=1);

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
date_default_timezone_set( 'UTC' );

define( 'ABSPATH', __DIR__ . '/stubs-abspath/' );
define( 'WPINC', 'wp-includes' );
define( 'PPCLG_VERSION', '1.0.0' );
define( 'PPCLG_DB_VERSION', '1.0.0' );
define( 'PPCLG_FILE', dirname( __DIR__ ) . '/ppc-link-growth-engine/ppc-link-growth-engine.php' );
define( 'PPCLG_DIR', dirname( __DIR__ ) . '/ppc-link-growth-engine/' );
define( 'PPCLG_URL', 'http://localhost/wp-content/plugins/ppc-link-growth-engine/' );
define( 'PPCLG_BASENAME', 'ppc-link-growth-engine/ppc-link-growth-engine.php' );
define( 'PPCLG_MIN_PHP', '8.1' );
define( 'PPCLG_MIN_WP', '6.5' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MONTH_IN_SECONDS', 2592000 );
define( 'YEAR_IN_SECONDS', 31536000 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );
define( 'AUTH_KEY', 'unit-test-auth-key-0123456789abcdefghijklmnopqrstuvwxyz' );
define( 'SECURE_AUTH_KEY', 'unit-test-secure-auth-key-0123456789abcdefghijklmnopqrstuvwxyz' );
define( 'WP_DEBUG', false );

require_once PPCLG_DIR . 'includes/Autoloader.php';
\PPC\LinkGrowth\Autoloader::register( PPCLG_DIR . 'includes', 'PPC\\LinkGrowth\\' );
\PPC\LinkGrowth\Autoloader::register( __DIR__, 'PPC\\LinkGrowth\\Tests\\' );

$GLOBALS['ppclg_test_options']    = [];
$GLOBALS['ppclg_test_transients'] = [];
$GLOBALS['ppclg_test_mail']       = [];
$GLOBALS['ppclg_test_actions']    = [];
$GLOBALS['ppclg_test_filters']    = [];
$GLOBALS['ppclg_test_user_id']    = 0;
$GLOBALS['ppclg_test_home']       = 'https://purepeptidescanada.test';
$GLOBALS['ppclg_test_time']       = null;

// ---------------------------------------------------------------- classes
class wpdb {}
class WP_Error {
    private array $errors = [];
    private array $data   = [];
    public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
        if ( '' !== $code ) {
            $this->errors[ $code ][] = $message;
            if ( '' !== $data ) {
                $this->data[ $code ] = $data;
            }
        }
    }
    public function get_error_code(): string {
        return (string) ( array_key_first( $this->errors ) ?? '' );
    }
    public function get_error_message( string $code = '' ): string {
        $code = '' === $code ? $this->get_error_code() : $code;
        return (string) ( $this->errors[ $code ][0] ?? '' );
    }
    public function get_error_data( string $code = '' ): mixed {
        $code = '' === $code ? $this->get_error_code() : $code;
        return $this->data[ $code ] ?? null;
    }
    public function has_errors(): bool {
        return ! empty( $this->errors );
    }
    public function add( string $code, string $message ): void {
        $this->errors[ $code ][] = $message;
    }
}
class WP_REST_Request implements ArrayAccess {
    private array $params = [];
    private array $headers = [];
    private string $method;
    private string $route;
    private string $body = '';
    public function __construct( string $method = 'GET', string $route = '', array $attributes = [] ) {
        $this->method = strtoupper( $method );
        $this->route  = $route;
    }
    public function set_param( string $key, mixed $value ): void { $this->params[ $key ] = $value; }
    public function get_param( string $key ): mixed { return $this->params[ $key ] ?? null; }
    public function get_params(): array { return $this->params; }
    public function set_body( string $body ): void { $this->body = $body; }
    public function get_body(): string { return $this->body; }
    public function get_json_params(): array { $j = json_decode( $this->body, true ); return is_array( $j ) ? $j : []; }
    public function get_body_params(): array { return $this->params; }
    public function get_query_params(): array { return $this->params; }
    public function set_header( string $key, string $value ): void { $this->headers[ strtolower( $key ) ] = $value; }
    public function get_header( string $key ): ?string { return $this->headers[ strtolower( $key ) ] ?? null; }
    public function get_headers(): array { return $this->headers; }
    public function get_method(): string { return $this->method; }
    public function get_route(): string { return $this->route; }
    public function offsetExists( mixed $o ): bool { return isset( $this->params[ $o ] ); }
    public function offsetGet( mixed $o ): mixed { return $this->params[ $o ] ?? null; }
    public function offsetSet( mixed $o, mixed $v ): void { $this->params[ $o ] = $v; }
    public function offsetUnset( mixed $o ): void { unset( $this->params[ $o ] ); }
}
class WP_REST_Response {
    public mixed $data;
    public int $status;
    public array $headers = [];
    public function __construct( mixed $data = null, int $status = 200, array $headers = [] ) {
        $this->data = $data; $this->status = $status; $this->headers = $headers;
    }
    public function get_data(): mixed { return $this->data; }
    public function set_data( mixed $d ): void { $this->data = $d; }
    public function get_status(): int { return $this->status; }
    public function set_status( int $s ): void { $this->status = $s; }
    public function header( string $k, string $v ): void { $this->headers[ $k ] = $v; }
    public function get_headers(): array { return $this->headers; }
    public function is_error(): bool { return $this->status >= 400; }
    public function as_error(): ?WP_Error { return $this->is_error() ? new WP_Error( 'rest_error', is_array( $this->data ) ? (string) ( $this->data['message'] ?? 'error' ) : 'error' ) : null; }
}
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const EDITABLE = 'POST, PUT, PATCH'; const DELETABLE = 'DELETE'; const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE'; }
class WP_Http_Cookie { public string $name; public string $value; public function __construct( array $data ) { $this->name = (string) ( $data['name'] ?? '' ); $this->value = (string) ( $data['value'] ?? '' ); } }
class WP_Role { public array $capabilities = []; public function add_cap( string $cap ): void { $this->capabilities[ $cap ] = true; } public function remove_cap( string $cap ): void { unset( $this->capabilities[ $cap ] ); } public function has_cap( string $cap ): bool { return ! empty( $this->capabilities[ $cap ] ); } }

// ---------------------------------------------------------------- hooks
function add_action( string $hook, callable $cb, int $priority = 10, int $args = 1 ): bool { $GLOBALS['ppclg_test_actions'][ $hook ][] = $cb; return true; }
function add_filter( string $hook, callable $cb, int $priority = 10, int $args = 1 ): bool { $GLOBALS['ppclg_test_filters'][ $hook ][] = $cb; return true; }
function remove_action( string $hook, callable $cb, int $priority = 10 ): bool { return true; }
function remove_filter( string $hook, callable $cb, int $priority = 10 ): bool { return true; }
function has_action( string $hook, mixed $cb = false ): bool { return ! empty( $GLOBALS['ppclg_test_actions'][ $hook ] ); }
function do_action( string $hook, mixed ...$args ): void { foreach ( $GLOBALS['ppclg_test_actions'][ $hook ] ?? [] as $cb ) { $cb( ...$args ); } }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { foreach ( $GLOBALS['ppclg_test_filters'][ $hook ] ?? [] as $cb ) { $value = $cb( $value, ...$args ); } return $value; }
function did_action( string $hook ): int { return 0; }
function register_activation_hook( string $file, callable $cb ): void {}
function register_deactivation_hook( string $file, callable $cb ): void {}

// ---------------------------------------------------------------- options / transients / cache
function get_option( string $name, mixed $default = false ): mixed { return array_key_exists( $name, $GLOBALS['ppclg_test_options'] ) ? $GLOBALS['ppclg_test_options'][ $name ] : $default; }
function update_option( string $name, mixed $value, mixed $autoload = null ): bool { $GLOBALS['ppclg_test_options'][ $name ] = $value; return true; }
function add_option( string $name, mixed $value = '', string $deprecated = '', mixed $autoload = 'yes' ): bool { if ( array_key_exists( $name, $GLOBALS['ppclg_test_options'] ) ) { return false; } $GLOBALS['ppclg_test_options'][ $name ] = $value; return true; }
function delete_option( string $name ): bool { unset( $GLOBALS['ppclg_test_options'][ $name ] ); return true; }
function get_transient( string $key ): mixed { $t = $GLOBALS['ppclg_test_transients'][ $key ] ?? null; if ( ! $t ) { return false; } if ( $t['expires'] && $t['expires'] < time() ) { unset( $GLOBALS['ppclg_test_transients'][ $key ] ); return false; } return $t['value']; }
function set_transient( string $key, mixed $value, int $ttl = 0 ): bool { $GLOBALS['ppclg_test_transients'][ $key ] = [ 'value' => $value, 'expires' => $ttl ? time() + $ttl : 0 ]; return true; }
function delete_transient( string $key ): bool { unset( $GLOBALS['ppclg_test_transients'][ $key ] ); return true; }
function wp_cache_flush(): bool { return true; }
function wp_cache_get( string $key, string $group = '' ): mixed { return false; }
function wp_cache_set( string $key, mixed $data, string $group = '', int $expire = 0 ): bool { return true; }
function wp_cache_delete( string $key, string $group = '' ): bool { return true; }

// ---------------------------------------------------------------- i18n / escaping / sanitising
function __( string $text, string $domain = 'default' ): string { return $text; }
function _e( string $text, string $domain = 'default' ): void { echo $text; }
function _x( string $text, string $context, string $domain = 'default' ): string { return $text; }
function _n( string $single, string $plural, int $number, string $domain = 'default' ): string { return 1 === $number ? $single : $plural; }
function esc_html__( string $text, string $domain = 'default' ): string { return esc_html( $text ); }
function esc_attr__( string $text, string $domain = 'default' ): string { return esc_attr( $text ); }
function esc_html_e( string $text, string $domain = 'default' ): void { echo esc_html( $text ); }
function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ); }
function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false ); }
function esc_js( mixed $text ): string { return addslashes( (string) $text ); }
function esc_textarea( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( string $url, mixed $protocols = null, string $context = 'display' ): string { return esc_url_raw( $url ); }
function esc_url_raw( string $url, mixed $protocols = null ): string { $url = trim( $url ); if ( '' === $url ) { return ''; } if ( ! preg_match( '#^(https?|mailto|tel):#i', $url ) && ! str_starts_with( $url, '/' ) ) { $url = 'http://' . $url; } return str_replace( [ ' ', '"', "'", '<', '>' ], [ '%20', '%22', '%27', '%3C', '%3E' ], $url ); }
function esc_sql( mixed $data ): mixed { return is_array( $data ) ? array_map( 'esc_sql', $data ) : addslashes( (string) $data ); }
function wp_kses_post( string $data ): string { return strip_tags( $data, '<a><p><br><strong><em><ul><ol><li><h1><h2><h3><h4><table><tr><td><th><span><div><img><code><pre><blockquote>' ); }
function wp_kses( string $data, array $allowed ): string { return strip_tags( $data ); }
function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string { $text = preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', $text ) ?? $text; $text = strip_tags( $text ); if ( $remove_breaks ) { $text = preg_replace( '/[\r\n\t ]+/', ' ', $text ) ?? $text; } return trim( $text ); }
function sanitize_text_field( mixed $str ): string { $str = (string) $str; $str = wp_strip_all_tags( $str, false ); $str = preg_replace( '/[\r\n\t ]+/', ' ', $str ) ?? $str; return trim( $str ); }
function sanitize_textarea_field( mixed $str ): string { return trim( wp_strip_all_tags( (string) $str, false ) ); }
function sanitize_key( mixed $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ?? ''; }
function sanitize_title( string $title ): string { $t = strtolower( trim( wp_strip_all_tags( $title ) ) ); $t = preg_replace( '/[^a-z0-9\s\-]/', '', $t ) ?? $t; return trim( preg_replace( '/[\s\-]+/', '-', $t ) ?? $t, '-' ); }
function sanitize_email( string $email ): string { $email = trim( $email ); return is_email( $email ) ? strtolower( $email ) : ''; }
function sanitize_file_name( string $name ): string { return preg_replace( '/[^A-Za-z0-9\.\-_]/', '', $name ) ?? ''; }
function is_email( mixed $email ): mixed { $email = (string) $email; return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false; }
function absint( mixed $v ): int { return abs( (int) $v ); }
function wp_unslash( mixed $v ): mixed { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function wp_slash( mixed $v ): mixed { return $v; }
function wp_parse_args( mixed $args, array $defaults = [] ): array { if ( is_object( $args ) ) { $args = get_object_vars( $args ); } elseif ( ! is_array( $args ) ) { parse_str( (string) $args, $args ); } return array_merge( $defaults, (array) $args ); }
function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false { return json_encode( $data, $options, $depth ); }
function wp_parse_url( string $url, int $component = -1 ): mixed { return -1 === $component ? parse_url( $url ) : parse_url( $url, $component ); }
function wp_http_validate_url( string $url ): string|false { return preg_match( '#^https?://[^/]+#i', $url ) ? $url : false; }
function untrailingslashit( string $s ): string { return rtrim( $s, '/\\' ); }
function trailingslashit( string $s ): string { return untrailingslashit( $s ) . '/'; }
function wp_normalize_path( string $p ): string { return str_replace( '\\', '/', $p ); }
function wp_generate_password( int $length = 12, bool $special = true, bool $extra = false ): string { $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'; $out = ''; for ( $i = 0; $i < $length; $i++ ) { $out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ]; } return $out; }
function wp_rand( int $min = 0, int $max = 0 ): int { return random_int( $min, $max ?: PHP_INT_MAX ); }
function wp_hash( string $data, string $scheme = 'auth' ): string { return hash_hmac( 'md5', $data, AUTH_KEY ); }
function wp_salt( string $scheme = 'auth' ): string { return AUTH_KEY; }
function wp_create_nonce( string $action = '' ): string { return substr( wp_hash( $action . '|nonce' ), 0, 10 ); }
function wp_verify_nonce( string $nonce, string $action = '' ): int|false { return $nonce === wp_create_nonce( $action ) ? 1 : false; }
function number_format_i18n( float $n, int $d = 0 ): string { return number_format( $n, $d ); }
function size_format( int $bytes ): string { return $bytes . ' B'; }
function wp_trim_words( string $text, int $num = 55, string $more = '…' ): string { $words = preg_split( '/\s+/', trim( $text ) ) ?: []; return count( $words ) <= $num ? $text : implode( ' ', array_slice( $words, 0, $num ) ) . $more; }
function wp_html_excerpt( string $s, int $n, string $more = '' ): string { return mb_substr( $s, 0, $n ) . $more; }
function shortcode_atts( array $pairs, array $atts ): array { return array_merge( $pairs, array_intersect_key( $atts, $pairs ) ); }

// ---------------------------------------------------------------- time
function current_time( string $type, int|bool $gmt = 0 ): mixed { $ts = $GLOBALS['ppclg_test_time'] ?? time(); return 'timestamp' === $type ? $ts : ( 'mysql' === $type ? gmdate( 'Y-m-d H:i:s', $ts ) : gmdate( $type, $ts ) ); }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'America/Toronto' ); }
function wp_timezone_string(): string { return 'America/Toronto'; }
function wp_date( string $format, ?int $ts = null, ?DateTimeZone $tz = null ): string { $d = new DateTime( '@' . ( $ts ?? time() ) ); $d->setTimezone( $tz ?? wp_timezone() ); return $d->format( $format ); }
function date_i18n( string $format, ?int $ts = null ): string { return gmdate( $format, $ts ?? time() ); }
function get_date_from_gmt( string $s, string $format = 'Y-m-d H:i:s' ): string { return gmdate( $format, strtotime( $s ) ?: 0 ); }
function get_gmt_from_date( string $s, string $format = 'Y-m-d H:i:s' ): string { return gmdate( $format, strtotime( $s ) ?: 0 ); }
function human_time_diff( int $from, int $to = 0 ): string { return abs( ( $to ?: time() ) - $from ) . ' seconds'; }

// ---------------------------------------------------------------- urls / site
function home_url( string $path = '', ?string $scheme = null ): string { return rtrim( $GLOBALS['ppclg_test_home'], '/' ) . ( '' !== $path ? '/' . ltrim( $path, '/' ) : '' ); }
function site_url( string $path = '', ?string $scheme = null ): string { return home_url( $path ); }
function admin_url( string $path = '', string $scheme = 'admin' ): string { return home_url( 'wp-admin/' . ltrim( $path, '/' ) ); }
function rest_url( string $path = '', string $scheme = 'rest' ): string { return home_url( 'wp-json/' . ltrim( $path, '/' ) ); }
function get_rest_url( ?int $blog_id = null, string $path = '/' ): string { return rest_url( $path ); }
function plugin_dir_path( string $file ): string { return trailingslashit( dirname( $file ) ); }
function plugin_dir_url( string $file ): string { return PPCLG_URL; }
function plugin_basename( string $file ): string { return PPCLG_BASENAME; }
function plugins_url( string $path = '', string $plugin = '' ): string { return PPCLG_URL . ltrim( $path, '/' ); }
function get_bloginfo( string $show = '', string $filter = 'raw' ): string { return match ( $show ) { 'name' => 'Pure Peptides Canada', 'url' => home_url(), 'admin_email' => 'admin@purepeptidescanada.test', 'version' => '7.1.4', 'charset' => 'UTF-8', 'language' => 'en-CA', default => '' }; }
function get_site_url( ?int $b = null, string $path = '' ): string { return home_url( $path ); }
function wp_upload_dir( mixed $time = null, bool $create = true ): array { $base = sys_get_temp_dir() . '/ppclg-test-uploads'; if ( ! is_dir( $base ) ) { mkdir( $base, 0777, true ); } return [ 'basedir' => $base, 'baseurl' => home_url( 'wp-content/uploads' ), 'path' => $base, 'url' => home_url( 'wp-content/uploads' ), 'error' => false ]; }
function wp_mkdir_p( string $dir ): bool { return is_dir( $dir ) || mkdir( $dir, 0777, true ); }
function wp_delete_file( string $file ): void { if ( file_exists( $file ) ) { unlink( $file ); } }
function is_admin(): bool { return false; }
function is_multisite(): bool { return false; }
function is_user_logged_in(): bool { return $GLOBALS['ppclg_test_user_id'] > 0; }
function get_current_user_id(): int { return (int) $GLOBALS['ppclg_test_user_id']; }
function current_user_can( string $cap, mixed ...$args ): bool { return ! empty( $GLOBALS['ppclg_test_caps'][ $cap ] ); }
function wp_get_current_user(): object { return (object) [ 'ID' => get_current_user_id(), 'display_name' => 'Test Admin', 'user_email' => 'admin@purepeptidescanada.test', 'user_login' => 'admin' ]; }
function get_userdata( int $id ): object|false { return $id > 0 ? (object) [ 'ID' => $id, 'display_name' => 'User ' . $id, 'user_email' => 'user' . $id . '@purepeptidescanada.test' ] : false; }
function get_role( string $role ): ?WP_Role { $GLOBALS['ppclg_test_roles'][ $role ] ??= new WP_Role(); return $GLOBALS['ppclg_test_roles'][ $role ]; }
function wp_roles(): object { return (object) [ 'roles' => [] ]; }
function load_plugin_textdomain( string $d, mixed $a = false, mixed $p = false ): bool { return true; }
function deactivate_plugins( mixed $p ): void {}
function wp_die( mixed $m = '', mixed $t = '', mixed $a = [] ): void { throw new RuntimeException( 'wp_die: ' . ( is_string( $m ) ? $m : 'died' ) ); }
function wp_safe_redirect( string $l, int $s = 302 ): bool { return true; }
function wp_redirect( string $l, int $s = 302 ): bool { return true; }
function wp_remote_retrieve_response_code( mixed $r ): int|string { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : ''; }
function wp_remote_retrieve_response_message( mixed $r ): string { return is_array( $r ) ? (string) ( $r['response']['message'] ?? '' ) : ''; }
function wp_remote_retrieve_body( mixed $r ): string { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }
function wp_remote_retrieve_headers( mixed $r ): array { return is_array( $r ) ? (array) ( $r['headers'] ?? [] ) : []; }
function wp_remote_retrieve_header( mixed $r, string $h ): string { $hs = wp_remote_retrieve_headers( $r ); return (string) ( $hs[ strtolower( $h ) ] ?? '' ); }
function wp_remote_retrieve_cookies( mixed $r ): array { return is_array( $r ) ? (array) ( $r['cookies'] ?? [] ) : []; }
function is_wp_error( mixed $t ): bool { return $t instanceof WP_Error; }
function wp_remote_request( string $url, array $args = [] ): array|WP_Error { return \PPC\LinkGrowth\Tests\Support\HttpFixtures::respond( $url, $args ); }
function wp_remote_get( string $url, array $args = [] ): array|WP_Error { return wp_remote_request( $url, array_merge( $args, [ 'method' => 'GET' ] ) ); }
function wp_remote_post( string $url, array $args = [] ): array|WP_Error { return wp_remote_request( $url, array_merge( $args, [ 'method' => 'POST' ] ) ); }
function wp_remote_head( string $url, array $args = [] ): array|WP_Error { return wp_remote_request( $url, array_merge( $args, [ 'method' => 'HEAD' ] ) ); }
function wp_safe_remote_get( string $url, array $args = [] ): array|WP_Error { return wp_remote_get( $url, $args ); }
function wp_safe_remote_post( string $url, array $args = [] ): array|WP_Error { return wp_remote_post( $url, $args ); }
function wp_mail( mixed $to, string $subject, string $message, mixed $headers = '', mixed $attachments = [] ): bool { $GLOBALS['ppclg_test_mail'][] = [ 'to' => $to, 'subject' => $subject, 'message' => $message, 'headers' => $headers ]; return empty( $GLOBALS['ppclg_test_mail_fail'] ); }
function wp_next_scheduled( string $hook, array $args = [] ): int|false { return false; }
function wp_schedule_event( int $ts, string $recurrence, string $hook, array $args = [] ): bool { return true; }
function wp_schedule_single_event( int $ts, string $hook, array $args = [] ): bool { return true; }
function wp_clear_scheduled_hook( string $hook, array $args = [] ): int { return 0; }
function wp_unschedule_event( int $ts, string $hook, array $args = [] ): bool { return true; }
function spawn_cron( int $gmt = 0 ): bool { return true; }
function wp_get_schedules(): array { return [ 'hourly' => [ 'interval' => 3600, 'display' => 'Hourly' ] ]; }
function rest_do_request( WP_REST_Request $r ): WP_REST_Response { return new WP_REST_Response( [], 404 ); }
function rest_ensure_response( mixed $r ): WP_REST_Response { return $r instanceof WP_REST_Response ? $r : new WP_REST_Response( $r ); }
function register_rest_route( string $ns, string $route, array $args = [], bool $override = false ): bool { $GLOBALS['ppclg_test_routes'][ $ns . $route ] = $args; return true; }
function rest_get_server(): object { return (object) []; }
function add_menu_page( mixed ...$a ): string { return 'ppclg'; }
function add_submenu_page( mixed ...$a ): string|false { return 'ppclg'; }
function wp_enqueue_style( mixed ...$a ): void {}
function wp_enqueue_script( mixed ...$a ): void {}
function wp_register_script( mixed ...$a ): bool { return true; }
function wp_enqueue_script_module( mixed ...$a ): void {}
function wp_add_inline_script( mixed ...$a ): bool { return true; }
function wp_localize_script( mixed ...$a ): bool { return true; }
function get_current_screen(): ?object { return null; }
function selected( mixed $a, mixed $b = true, bool $echo = true ): string { return $a == $b ? ' selected="selected"' : ''; }
function checked( mixed $a, mixed $b = true, bool $echo = true ): string { return $a == $b ? ' checked="checked"' : ''; }
function wp_nonce_field( mixed ...$a ): string { return ''; }
function check_ajax_referer( mixed ...$a ): bool { return true; }
function wp_kses_allowed_html( string $context = 'basic' ): array { return []; }
function get_plugin_data( string $file ): array { return [ 'Version' => PPCLG_VERSION, 'Name' => 'PPC Link Growth Engine' ]; }
function dbDelta( mixed $queries, bool $execute = true ): array { foreach ( (array) $queries as $q ) { $GLOBALS['wpdb']->createFromMysql( $q ); } return []; }
function as_schedule_recurring_action( mixed ...$a ): int { return 1; }
function as_has_scheduled_action( mixed ...$a ): bool { return false; }
function as_unschedule_all_actions( mixed ...$a ): void {}
function as_enqueue_async_action( mixed ...$a ): int { return 1; }
function as_next_scheduled_action( mixed ...$a ): int|false { return false; }
function get_admin_url( mixed ...$a ): string { return admin_url(); }
function wp_doing_cron(): bool { return false; }
function wp_doing_ajax(): bool { return false; }
function wp_get_environment_type(): string { return 'local'; }

if ( ! is_dir( ABSPATH . 'wp-admin/includes' ) ) {
    mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
}
if ( ! file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
    file_put_contents( ABSPATH . 'wp-admin/includes/upgrade.php', "<?php\n// stub for tests\n" );
}
