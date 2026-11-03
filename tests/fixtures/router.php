<?php
/**
 * Router for the PHP built-in server serving test fixture sites.
 *
 * Usage: PPCLG_FIXTURE_LOG=/path/to/log php -S 127.0.0.1:8788 tests/fixtures/router.php
 * URL layout: http://127.0.0.1:8788/{fake-domain}/{path}  →  tests/fixtures/sites/{fake-domain}/{path}
 */
$sites = __DIR__ . '/sites';
$uri   = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
$parts = array_values( array_filter( explode( '/', $uri ) ) );
$host  = $parts[0] ?? '';
$rest  = array_slice( $parts, 1 );
$path  = implode( '/', $rest );
$log   = getenv( 'PPCLG_FIXTURE_LOG' ) ?: sys_get_temp_dir() . '/ppclg-fixture-submissions.log';

if ( '' === $host || ! preg_match( '/^[a-z0-9.\-]+$/', $host ) ) {
    http_response_code( 404 );
    echo 'fixture host missing';
    return true;
}
// Always-broken hosts.
if ( 'deadlab.test' === $host ) {
    http_response_code( 404 );
    header( 'Content-Type: text/html' );
    echo '<html><body><h1>404 Not Found</h1></body></html>';
    return true;
}
if ( 'gone.test' === $host ) {
    http_response_code( 410 );
    header( 'Content-Type: text/html' );
    echo '<html><body><h1>410 Gone</h1></body></html>';
    return true;
}
if ( 'slow.test' === $host ) {
    http_response_code( 503 );
    echo 'temporarily unavailable';
    return true;
}
// Form handlers.
if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
    $entry = [ 'time' => gmdate( 'c' ), 'host' => $host, 'path' => '/' . $path, 'post' => $_POST, 'headers' => [ 'referer' => $_SERVER['HTTP_REFERER'] ?? '', 'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '' ] ];
    file_put_contents( $log, json_encode( $entry ) . "\n", FILE_APPEND );
    header( 'Content-Type: text/html' );
    if ( 'labdirectory.test' === $host && 'add-listing/submit' === $path ) {
        if ( empty( $_POST['business_name'] ) || empty( $_POST['website'] ) || empty( $_POST['email'] ) || empty( $_POST['category'] ) || ( $_POST['form_token'] ?? '' ) !== 'tok-7f3a9c' ) {
            http_response_code( 200 );
            echo '<html><body><h1>Error</h1><p>Please fill in all required fields.</p></body></html>';
            return true;
        }
        echo '<html><body><h1>Thank you!</h1><p>Your listing has been received and is pending review. Reference: LD-' . substr( md5( (string) $_POST['website'] ), 0, 6 ) . '</p></body></html>';
        return true;
    }
    echo '<html><body><p>Thanks, your message was sent.</p></body></html>';
    return true;
}
$dir  = $sites . '/' . $host;
$file = rtrim( $dir . '/' . $path, '/' );
if ( is_dir( $file ) ) {
    $file .= '/index.html';
} elseif ( ! is_file( $file ) && is_file( $file . '.html' ) ) {
    $file .= '.html';
}
$real = realpath( $file );
if ( ! $real || ! str_starts_with( $real, realpath( $sites ) ) || ! is_file( $real ) ) {
    http_response_code( 404 );
    header( 'Content-Type: text/html' );
    echo '<html><body><h1>404 Not Found</h1></body></html>';
    return true;
}
$ext = pathinfo( $real, PATHINFO_EXTENSION );
header( 'Content-Type: ' . ( 'txt' === $ext ? 'text/plain' : 'text/html' ) . '; charset=UTF-8' );
readfile( $real );
return true;
