<?php
/**
 * auto_prepend_file of the PHP server tests/e2e/run.mjs starts for the browser E2E suite (php -d auto_prepend_file=…
 * -S 127.0.0.1:8793). It is never installed in the site. It pre-registers hooks through WordPress's preinitialised
 * $wp_filter, so the dashboard runs offline and on data only the walk changes:
 *  - action_scheduler_allow_async_request_runner => false: an admin page view does not start the Action Scheduler
 *    loopback runner, so no background job changes the data between two page loads (the walk's own "Run now" and
 *    "Run tick now" buttons still run their work in their REST request);
 *  - pre_http_request (priority 1): a request to this site or to a *.test fixture host passes (the test mu-plugin
 *    routes *.test hosts to the fixture server); every other host is refused with a WP_Error and logged as one JSON
 *    line to LGA_E2E_OUTBOUND_LOG, so no test reaches a real third-party service (DESIGN.md §12.4);
 *  - pre_wp_mail: mail is not sent; each message is logged to LGA_E2E_MAIL_LOG and reported as sent;
 *  - pre_option for the REST write counter (transients lga_rl_*): the walk performs several hundred writes as one user
 *    within minutes, far above the 60 writes per minute a person is allowed (Admin\Rest\Controller::rateLimit,
 *    covered by the unit suite), so the counter always reads 0 on this server.
 */

$GLOBALS['wp_filter'] = isset( $GLOBALS['wp_filter'] ) && is_array( $GLOBALS['wp_filter'] ) ? $GLOBALS['wp_filter'] : [];

$GLOBALS['wp_filter']['action_scheduler_allow_async_request_runner'][10][] = [
    'function'      => static function () {
        return false;
    },
    'accepted_args' => 1,
];

$GLOBALS['wp_filter']['pre_http_request'][1][] = [
    'function'      => static function ( $pre, $args = [], $url = '' ) {
        if ( false !== $pre || ! empty( $args['_lga_fixture'] ) ) {
            return $pre;
        }
        $host = strtolower( (string) parse_url( (string) $url, PHP_URL_HOST ) );
        if ( in_array( $host, [ 'localhost', '127.0.0.1' ], true ) || str_ends_with( $host, '.test' ) ) {
            return $pre;
        }
        $log = (string) getenv( 'LGA_E2E_OUTBOUND_LOG' );
        if ( '' !== $log ) {
            @file_put_contents(
                $log,
                json_encode( [ 'time' => gmdate( 'c' ), 'request' => (string) ( $_SERVER['REQUEST_URI'] ?? '' ), 'method' => strtoupper( (string) ( $args['method'] ?? 'GET' ) ), 'host' => $host ] ) . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
        return new WP_Error( 'lga_e2e_offline', 'E2E run: outbound request refused (' . $host . ')' );
    },
    'accepted_args' => 3,
];

$GLOBALS['wp_filter']['pre_wp_mail'][10][] = [
    'function'      => static function ( $null, $atts = [] ) {
        $log = (string) getenv( 'LGA_E2E_MAIL_LOG' );
        if ( '' !== $log ) {
            $to = is_array( $atts ) ? ( $atts['to'] ?? '' ) : '';
            @file_put_contents(
                $log,
                json_encode( [ 'time' => gmdate( 'c' ), 'to' => is_array( $to ) ? implode( ',', $to ) : (string) $to, 'subject' => is_array( $atts ) ? (string) ( $atts['subject'] ?? '' ) : '' ] ) . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
        return true;
    },
    'accepted_args' => 2,
];

$GLOBALS['wp_filter']['pre_option'][10][] = [
    'function'      => static function ( $pre, $option = '' ) {
        if ( is_string( $option ) && str_starts_with( $option, '_transient_lga_rl_' ) ) {
            return '0';
        }
        return $pre;
    },
    'accepted_args' => 2,
];
