<?php
/**
 * IndexNow: tells participating search engines (Bing, Yandex, Seznam, Naver, …) which public URLs are new or changed.
 *
 *  - The key (32 lowercase hex characters) is generated once with random_bytes() and kept in settings
 *    seo.indexnow_key. serveKey() answers GET /{key}.txt with the key as text/plain and touches no other request.
 *  - The first run submits every public, indexable URL (published, not password protected, not noindex, public
 *    post type). Later runs submit only new or changed URLs (url => post_modified_gmt is tracked in the
 *    non-autoloaded option ppclg_indexnow_state); an unchanged URL is re-submitted at most every 30 days.
 *  - Saving/publishing an item adds it to a pending set (onTransition / onSavePost hooks); the hourly stage submits it.
 *  - Submission goes through the plugin's HTTP client (JSON POST, ≤ 10,000 URLs per request). 200/202 count as
 *    accepted; 400/403/422/429 and transport errors are logged, the URLs stay pending and last_error is stored.
 *    After any refusal the scheduled stage waits at least an hour before it tries again ("Submit now" may retry).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Seo;

use PPC\LinkGrowth\Automation\Audit;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Locks;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Support\Url;
use Throwable;

final class IndexNow {

    public const ENDPOINT = 'https://api.indexnow.org/indexnow';

    /** Tracked submissions and status (non-autoloaded). */
    public const OPTION_STATE = 'ppclg_indexnow_state';

    /** Item ids saved since the last submission: post_id => queued unix time (non-autoloaded). */
    public const OPTION_PENDING = 'ppclg_indexnow_pending';

    public const LOCK = 'seo_indexnow';

    /** IndexNow accepts at most 10,000 URLs per request. */
    public const MAX_URLS = 10000;

    /** Unchanged URLs are re-submitted at most this often. */
    public const RESUBMIT_SECONDS = 30 * 86400;

    /** A full comparison of every public URL runs at most this often (pending items go out hourly). */
    private const SCAN_SECONDS = 86400;

    /** Wait after a refused submission (429 and other errors) before the scheduled stage tries again. */
    private const BACKOFF_SECONDS = 3600;

    private const TIMEOUT = 15;

    private const HISTORY_MAX = 20;

    private const PENDING_MAX = 20000;

    /** Post types never submitted even when public. */
    private const EXCLUDED_TYPES = [ 'attachment', 'revision', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'elementor_library' ];

    /**
     * @param (\Closure():void)|null $terminate Ends the request after the key file was sent (default: exit). Tests pass a recorder.
     */
    public function __construct(
        private Settings $settings,
        private Logger $logger,
        private Client $client,
        private Locks $locks,
        private ?Audit $audit = null,
        private ?\Closure $terminate = null
    ) {}

    // ------------------------------------------------------------------
    // Key
    // ------------------------------------------------------------------

    public function enabled(): bool {
        return (bool) $this->settings->get( 'seo.indexnow', true );
    }

    /**
     * The verification key. A missing or malformed key is replaced by a new 32-character lowercase hex key when
     * $generate is true (stored in settings seo.indexnow_key); otherwise '' is returned.
     */
    public function key( bool $generate = true ): string {
        $key = (string) $this->settings->get( 'seo.indexnow_key', '' );
        if ( self::validKey( $key ) ) {
            return $key;
        }
        if ( ! $generate ) {
            return '';
        }
        $key = bin2hex( random_bytes( 16 ) );
        $this->settings->set( 'seo.indexnow_key', $key );
        $this->logger->info( 'seo', 'IndexNow key generated.' );
        return $key;
    }

    /** IndexNow keys are 8–128 characters of a-z, A-Z, 0-9 and "-". */
    public static function validKey( string $key ): bool {
        return 1 === preg_match( '/^[A-Za-z0-9\-]{8,128}$/', $key );
    }

    /** Public address of the key file ('' when no key exists yet). */
    public function keyUrl( string $key = '' ): string {
        $key = '' !== $key ? $key : $this->key( false );
        return '' === $key ? '' : home_url( '/' . $key . '.txt' );
    }

    /** Cheap pre-check for the parse_request hook: could this request path be a key file request? */
    public static function looksLikeKeyRequest( string $requestUri ): bool {
        $path = (string) wp_parse_url( $requestUri, PHP_URL_PATH );
        return 1 === preg_match( '#/[A-Za-z0-9\-]{8,128}\.txt$#', $path );
    }

    /**
     * Body to serve for a request, or null when the request is not exactly GET/HEAD /{key}.txt (under the home path).
     */
    public function keyFileBody( string $requestUri, string $method = 'GET' ): ?string {
        if ( ! in_array( strtoupper( $method ), [ 'GET', 'HEAD' ], true ) || ! $this->enabled() ) {
            return null;
        }
        $key = $this->key( false );
        if ( '' === $key ) {
            return null;
        }
        $path     = (string) wp_parse_url( $requestUri, PHP_URL_PATH );
        $expected = (string) wp_parse_url( $this->keyUrl( $key ), PHP_URL_PATH );
        if ( '' === $path || $path !== $expected ) {
            return null;
        }
        return $key;
    }

    /**
     * parse_request (priority 0) callback: answers /{key}.txt with the key as text/plain and ends the request.
     * Every other request is left alone.
     */
    public function serveKey( mixed $wp = null ): void {
        unset( $wp );
        $uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
        if ( ! self::looksLikeKeyRequest( $uri ) ) {
            return;
        }
        $body = $this->keyFileBody( $uri, $method );
        if ( null === $body ) {
            return;
        }
        if ( ! headers_sent() ) {
            if ( function_exists( 'status_header' ) ) {
                status_header( 200 );
            } else {
                http_response_code( 200 );
            }
            header( 'Content-Type: text/plain; charset=utf-8' );
            header( 'X-Robots-Tag: noindex' );
            header( 'Cache-Control: public, max-age=3600' );
            header( 'Content-Length: ' . strlen( $body ) );
        }
        if ( 'HEAD' !== strtoupper( $method ) ) {
            echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- validated [A-Za-z0-9-] key served as text/plain.
        }
        if ( null !== $this->terminate ) {
            ( $this->terminate )();
            return;
        }
        exit;
    }

    // ------------------------------------------------------------------
    // Pending set (hooks)
    // ------------------------------------------------------------------

    /** transition_post_status callback (fires on every save, also publish → publish). */
    public function onTransition( mixed $newStatus, mixed $oldStatus = '', mixed $post = null ): void {
        unset( $oldStatus );
        if ( 'publish' !== (string) $newStatus || ! is_object( $post ) ) {
            return;
        }
        $this->queue( $post );
    }

    /** save_post callback. */
    public function onSavePost( mixed $postId, mixed $post = null, mixed $update = null ): void {
        unset( $update );
        $post = is_object( $post ) ? $post : get_post( (int) $postId );
        if ( is_object( $post ) && 'publish' === (string) ( $post->post_status ?? '' ) ) {
            $this->queue( $post );
        }
    }

    /** Adds an item to the pending set when it is published, public and indexable. */
    private function queue( object $post ): void {
        try {
            if ( ! $this->enabled() || ! $this->isIndexable( $post ) ) {
                return;
            }
            $pending = $this->pending();
            $id      = (int) $post->ID;
            if ( isset( $pending[ $id ] ) ) {
                return;
            }
            if ( count( $pending ) >= self::PENDING_MAX ) {
                return; // the daily full comparison picks it up
            }
            $pending[ $id ] = time();
            update_option( self::OPTION_PENDING, $pending, false );
        } catch ( Throwable $e ) {
            $this->logger->debug( 'seo', 'IndexNow could not queue an item: ' . $e->getMessage() );
        }
    }

    /** @return array<int,int> post_id => queued unix time */
    public function pending(): array {
        $raw = get_option( self::OPTION_PENDING, [] );
        $out = [];
        foreach ( is_array( $raw ) ? $raw : [] as $id => $ts ) {
            if ( (int) $id > 0 ) {
                $out[ (int) $id ] = (int) $ts;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Status
    // ------------------------------------------------------------------

    /**
     * Contract status for the dashboard and the growth run.
     *
     * @return array{enabled:bool,key:string,key_url:string,last_submitted_at:?string,last_status:?int,last_error:string,submitted_total:int,pending:int,tracked:int,initialized:bool,retry_after:?string,blocked_reason:string}
     */
    public function status(): array {
        $state   = $this->state();
        $enabled = $this->enabled();
        // Read-only: reading the status never writes settings. The key is generated by the first submission
        // (run()), which stores it before the request goes out, so the key file is served when IndexNow checks it.
        $key = $this->key( false );
        $retry   = (int) ( $state['retry_after'] ?? 0 );
        return [
            'enabled'           => $enabled,
            'key'               => $key,
            'key_url'           => $this->keyUrl( $key ),
            'last_submitted_at' => is_string( $state['last_submitted_at'] ?? null ) && '' !== $state['last_submitted_at'] ? (string) $state['last_submitted_at'] : null,
            'last_status'       => isset( $state['last_status'] ) && is_numeric( $state['last_status'] ) ? (int) $state['last_status'] : null,
            'last_error'        => (string) ( $state['last_error'] ?? '' ),
            'submitted_total'   => (int) ( $state['submitted_total'] ?? 0 ),
            'pending'           => count( $this->pending() ),
            'tracked'           => count( (array) ( $state['urls'] ?? [] ) ),
            'initialized'       => ! empty( $state['initialized'] ),
            'retry_after'       => $retry > time() ? gmdate( 'c', $retry ) : null,
            'blocked_reason'    => $this->blockedReason(),
        ];
    }

    /** @return array<int,array<string,mixed>> recent submission attempts, newest first */
    public function history(): array {
        $state = $this->state();
        return array_values( array_reverse( (array) ( $state['history'] ?? [] ) ) );
    }

    /** Whether the hourly stage has something to do (pending items, first run, daily comparison) and may submit. */
    public function isDue(): bool {
        if ( '' !== $this->blockedReason() ) {
            return false;
        }
        $state = $this->state();
        if ( (int) ( $state['retry_after'] ?? 0 ) > time() ) {
            return false;
        }
        return [] !== $this->pending() || empty( $state['initialized'] ) || $this->scanDue( $state );
    }

    /** Why submissions cannot run at all ('' when they can). */
    public function blockedReason(): string {
        if ( ! $this->enabled() ) {
            return 'IndexNow is turned off in Settings.';
        }
        $env = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'production';
        /**
         * Filters the environment type IndexNow checks before it submits anything (only "production" submits).
         * Lets a live site whose host reports another environment type, or an end-to-end test with a mocked
         * endpoint, opt in from code. Never return "production" on a staging or local copy.
         *
         * @param string $env wp_get_environment_type()
         */
        $env = (string) apply_filters( 'ppclg_indexnow_environment', $env );
        if ( 'production' !== $env ) {
            return sprintf( 'IndexNow only runs on the production site (this site reports the "%s" environment).', $env );
        }
        if ( SiteAuditor::siteDiscouraged() ) {
            return 'WordPress is set to discourage search engines (Settings → Reading), so no URLs are submitted.';
        }
        if ( '' === Url::host( home_url( '/' ) ) ) {
            return 'The site address could not be read.';
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Submission
    // ------------------------------------------------------------------

    /**
     * Submits new/changed URLs (all public URLs on the first run).
     *
     * @param array{full?:bool,force?:bool} $opts full: compare every public URL now; force: ignore the 429 back-off
     * @return array{ok:bool,skipped:bool,reason:string,candidates:int,submitted:int,pending:int,status:int,more:bool,summary:string}
     */
    public function run( TimeBudget $budget, array $opts = [] ): array {
        $blocked = $this->blockedReason();
        if ( '' !== $blocked ) {
            return $this->outcome( false, true, 'blocked', 0, 0, 0, false, $blocked );
        }
        $state = $this->state();
        if ( empty( $opts['force'] ) && (int) ( $state['retry_after'] ?? 0 ) > time() ) {
            return $this->outcome( false, true, 'rate_limited', 0, 0, 0, false, sprintf( 'IndexNow asked us to slow down; the next submission waits until %s UTC.', gmdate( 'Y-m-d H:i', (int) $state['retry_after'] ) ) );
        }
        if ( ! $this->locks->acquire( self::LOCK, (int) ceil( $budget->remaining() ) + 60 ) ) {
            return $this->outcome( false, true, 'busy', 0, 0, 0, false, 'Another IndexNow submission is running.' );
        }
        try {
            return $this->runLocked( $budget, $opts );
        } finally {
            $this->locks->release( self::LOCK );
        }
    }

    /**
     * @param array{full?:bool,force?:bool} $opts
     * @return array<string,mixed>
     */
    private function runLocked( TimeBudget $budget, array $opts ): array {
        $key     = $this->key( true );
        $state   = $this->state();
        $pending = $this->pending();
        $full    = ! empty( $opts['full'] ) || empty( $state['initialized'] ) || $this->scanDue( $state );
        $first   = empty( $state['initialized'] );

        // Candidate URLs: url => [post id, modified (unix)].
        $complete   = true;
        $candidates = [];
        if ( $full ) {
            $complete   = $this->scanAll( $candidates, $budget );
            $candidates = [ home_url( '/' ) => [ 0, 0 ] ] + $candidates;
        }
        foreach ( array_keys( $pending ) as $id ) {
            $post = get_post( $id );
            if ( is_object( $post ) && $this->isIndexable( $post ) ) {
                $url = (string) get_permalink( $id );
                if ( '' !== $url ) {
                    $candidates[ $url ] = [ $id, self::modified( $post ) ];
                }
            }
        }

        $tracked = (array) ( $state['urls'] ?? [] );
        $now     = time();
        $submit  = [];
        foreach ( $candidates as $url => [ $id, $modified ] ) {
            $url  = (string) $url;
            $prev = $tracked[ $url ] ?? null;
            $due  = ! is_array( $prev )
                || (int) ( $prev['m'] ?? 0 ) !== (int) $modified
                || ( $now - (int) ( $prev['at'] ?? 0 ) ) >= self::RESUBMIT_SECONDS;
            if ( $due ) {
                $submit[ $url ] = [ (int) $id, (int) $modified ];
            }
        }
        $processedIds = array_keys( $pending );

        if ( [] === $submit ) {
            $this->finishScan( $state, $full, $complete );
            $this->removePending( $processedIds );
            $this->saveState( $state );
            return $this->outcome( true, false, 'nothing_to_submit', count( $candidates ), 0, count( $this->pending() ), ! $complete, $complete ? 'No new or changed URLs to submit to IndexNow.' : 'No new or changed URLs found so far; the comparison continues on the next run.' );
        }

        $batch = array_slice( $submit, 0, self::MAX_URLS, true );
        $more  = count( $submit ) > count( $batch ) || ! $complete;
        $host  = Url::host( home_url( '/' ) );
        $body  = [
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => $this->keyUrl( $key ),
            'urlList'     => array_keys( $batch ),
        ];

        $err = '';
        try {
            $resp = $this->client->api( 'POST', self::ENDPOINT, [ 'Content-Type' => 'application/json; charset=utf-8', 'Accept' => '*/*' ], $body, self::TIMEOUT );
        } catch ( Throwable $e ) {
            $resp = null;
            $err  = $e->getMessage();
        }
        $status = null !== $resp ? $resp->status : 0;
        $state['last_attempt_at'] = gmdate( 'c' );
        $state['last_status']     = $status;

        if ( null !== $resp && null === $resp->error && in_array( $status, [ 200, 202 ], true ) ) {
            foreach ( $batch as $url => [ , $modified ] ) {
                $tracked[ $url ] = [ 'm' => $modified, 'at' => $now ];
            }
            $state['urls']              = $tracked;
            $state['last_submitted_at'] = gmdate( 'c' );
            $state['last_error']        = '';
            $state['submitted_total']   = (int) ( $state['submitted_total'] ?? 0 ) + count( $batch );
            $state['retry_after']       = 0;
            $this->finishScan( $state, $full, $complete && count( $submit ) === count( $batch ) );
            $this->pushHistory( $state, count( $batch ), $status, '' );
            $this->saveState( $state );
            // Pending ids whose URL went out (or needed nothing) are done; ids in a later batch stay pending.
            $left = [];
            foreach ( array_slice( $submit, count( $batch ), null, true ) as [ $id ] ) {
                if ( $id > 0 ) {
                    $left[] = (int) $id;
                }
            }
            $this->removePending( array_diff( $processedIds, $left ) );
            if ( [] !== $left ) {
                $this->addPending( $left );
            }
            $this->logger->info( 'seo', 'IndexNow submission accepted', [ 'urls' => count( $batch ), 'status' => $status ] );
            $this->audit?->record( 'seo.indexnow_submitted', 'seo', 0, [ 'urls' => count( $batch ), 'status' => $status, 'first_run' => $first ] );
            $sent = count( $batch );
            return $this->outcome(
                true,
                false,
                202 === $status ? 'accepted_pending_key_check' : 'accepted',
                count( $candidates ),
                $sent,
                count( $this->pending() ),
                $more,
                sprintf( 'Submitted %d %s to IndexNow (HTTP %d%s).', $sent, 1 === $sent ? 'URL' : 'URLs', $status, 202 === $status ? ', accepted while the key is being verified' : '' )
            );
        }

        // Failure: keep everything pending, remember why.
        $message = null === $resp
            ? 'The IndexNow request failed: ' . ( '' !== $err ? $err : 'unknown error' ) . '.'
            : ( null !== $resp->error && 0 === $status ? 'The IndexNow request failed: ' . $resp->error . '.' : $this->errorMessage( $status, $host, $this->keyUrl( $key ) ) );
        // Never hammer the endpoint: the scheduled stage waits an hour after any refusal ("Submit now" may retry sooner).
        $state['retry_after'] = time() + self::BACKOFF_SECONDS;
        $state['last_error'] = $message;
        $this->pushHistory( $state, count( $batch ), $status, $message );
        $this->saveState( $state );
        $ids = [];
        foreach ( $batch as [ $id ] ) {
            if ( $id > 0 ) {
                $ids[] = (int) $id;
            }
        }
        $this->addPending( $ids );
        $this->logger->warning( 'seo', 'IndexNow submission refused', [ 'status' => $status, 'urls' => count( $batch ), 'error' => $message ] );
        return $this->outcome( false, false, 'http_' . $status, count( $candidates ), 0, count( $this->pending() ), false, $message );
    }

    private function errorMessage( int $status, string $host, string $keyUrl ): string {
        return match ( $status ) {
            400 => 'IndexNow rejected the request as invalid (HTTP 400). The URLs stay queued.',
            403 => sprintf( 'IndexNow could not verify the key (HTTP 403). Check that %s opens and shows only the key. The URLs stay queued.', $keyUrl ),
            422 => sprintf( 'IndexNow refused the URLs (HTTP 422): every URL must belong to %s and match the key location. The URLs stay queued.', $host ),
            429 => 'IndexNow asked us to slow down (HTTP 429). The URLs stay queued and the next submission waits at least an hour.',
            default => sprintf( 'IndexNow answered HTTP %d. The URLs stay queued for the next run.', $status ),
        };
    }

    /**
     * Every public, indexable URL (published items of public post types).
     *
     * @param array<string,array{0:int,1:int}> $out url => [post id, modified]
     * @return bool false when the budget ran out before every item was read
     */
    private function scanAll( array &$out, TimeBudget $budget ): bool {
        foreach ( $this->postTypes() as $type ) {
            $page = 1;
            do {
                if ( ! $budget->has( 3.0 ) ) {
                    return false;
                }
                $ids = get_posts(
                    [
                        'post_type'        => $type,
                        'post_status'      => 'publish',
                        'has_password'     => false,
                        'fields'           => 'ids',
                        'orderby'          => 'ID',
                        'order'            => 'ASC',
                        'posts_per_page'   => 500,
                        'paged'            => $page,
                        'no_found_rows'    => true,
                        'suppress_filters' => true,
                    ]
                );
                $ids = is_array( $ids ) ? array_map( 'intval', $ids ) : [];
                foreach ( $ids as $id ) {
                    $post = get_post( $id );
                    if ( ! is_object( $post ) || ! $this->isIndexable( $post ) ) {
                        continue;
                    }
                    $url = (string) get_permalink( $id );
                    if ( '' !== $url && SiteAuditor::isInternalUrl( $url ) ) {
                        $out[ $url ] = [ $id, self::modified( $post ) ];
                    }
                }
                $page++;
            } while ( count( $ids ) >= 500 && $page <= 200 );
        }
        return true;
    }

    /** Published, public post type, no password, not noindex. */
    public function isIndexable( object $post ): bool {
        $id   = (int) ( $post->ID ?? 0 );
        $type = (string) ( $post->post_type ?? '' );
        if ( $id <= 0 || 'publish' !== (string) ( $post->post_status ?? '' ) || '' !== (string) ( $post->post_password ?? '' ) ) {
            return false;
        }
        if ( ! in_array( $type, $this->postTypes(), true ) ) {
            return false;
        }
        return ! SiteAuditor::isNoindex( $id, $type );
    }

    /** @return string[] public post types that can be submitted */
    private function postTypes(): array {
        $types = get_post_types( [ 'public' => true ], 'names' );
        $types = array_values( array_diff( array_map( 'strval', is_array( $types ) ? $types : [] ), self::EXCLUDED_TYPES ) );
        return [] === $types ? [ 'post', 'page' ] : $types;
    }

    private static function modified( object $post ): int {
        $gmt = (string) ( $post->post_modified_gmt ?? '' );
        $ts  = '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ? strtotime( $gmt . ' UTC' ) : false;
        return false === $ts ? 0 : (int) $ts;
    }

    // ------------------------------------------------------------------
    // State
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function state(): array {
        $state = get_option( self::OPTION_STATE, [] );
        return is_array( $state ) ? $state : [];
    }

    /** @param array<string,mixed> $state */
    private function saveState( array $state ): void {
        update_option( self::OPTION_STATE, $state, false );
    }

    /** @param array<string,mixed> $state */
    private function scanDue( array $state ): bool {
        $last = (int) ( $state['last_scan_at'] ?? 0 );
        return $last <= 0 || ( time() - $last ) >= self::SCAN_SECONDS;
    }

    /** @param array<string,mixed> $state */
    private function finishScan( array &$state, bool $full, bool $complete ): void {
        if ( $full && $complete ) {
            $state['initialized']  = true;
            $state['last_scan_at'] = time();
        }
    }

    /** @param array<string,mixed> $state */
    private function pushHistory( array &$state, int $count, int $status, string $error ): void {
        $history   = array_values( (array) ( $state['history'] ?? [] ) );
        $history[] = [ 'at' => gmdate( 'c' ), 'urls' => $count, 'status' => $status, 'error' => $error ];
        $state['history'] = array_slice( $history, -self::HISTORY_MAX );
    }

    /** @param int[] $ids */
    private function removePending( array $ids ): void {
        if ( [] === $ids ) {
            return;
        }
        // Re-read right before writing: a save hook may have queued something during the request.
        $pending = $this->pending();
        foreach ( $ids as $id ) {
            unset( $pending[ (int) $id ] );
        }
        update_option( self::OPTION_PENDING, $pending, false );
    }

    /** @param int[] $ids */
    private function addPending( array $ids ): void {
        if ( [] === $ids ) {
            return;
        }
        $pending = $this->pending();
        foreach ( $ids as $id ) {
            if ( (int) $id > 0 && ! isset( $pending[ (int) $id ] ) && count( $pending ) < self::PENDING_MAX ) {
                $pending[ (int) $id ] = time();
            }
        }
        update_option( self::OPTION_PENDING, $pending, false );
    }

    /** @return array<string,mixed> */
    private function outcome( bool $ok, bool $skipped, string $reason, int $candidates, int $submitted, int $pending, bool $more, string $summary ): array {
        return [
            'ok'         => $ok,
            'skipped'    => $skipped,
            'reason'     => $reason,
            'candidates' => $candidates,
            'submitted'  => $submitted,
            'pending'    => $pending,
            'status'     => (int) ( $this->state()['last_status'] ?? 0 ),
            'more'       => $more,
            'summary'    => $summary,
        ];
    }
}
