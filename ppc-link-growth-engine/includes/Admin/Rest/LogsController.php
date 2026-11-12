<?php
/**
 * /logs, /audit, /notifications, /suppression — diagnostics and compliance lists.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Admin\Rest;

use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\Logs;
use PPC\LinkGrowth\Repositories\Notifications;
use PPC\LinkGrowth\Repositories\Suppression;
use WP_REST_Request;

final class LogsController extends Controller {

    private const LEVELS = [ 'debug', 'info', 'warning', 'error' ];

    private const SUPPRESSION_TYPES = [ 'domain', 'email', 'email_domain' ];

    /**
     * Audit object-type filter values offered by the UI that are recorded under another name.
     * Every outreach send/approval/rejection/reply/opt-out is audited as 'outreach_message'.
     */
    private const OBJECT_TYPE_ALIASES = [ 'message' => [ 'outreach_message', 'message' ] ];

    public function register( string $namespace ): void {
        $this->route( $namespace, '/logs', 'GET', [ $this, 'logs' ], $this->listArgs( [ 'level', 'channel', 'since' ] ) );
        $this->route( $namespace, '/audit', 'GET', [ $this, 'auditLog' ], $this->listArgs( [ 'action', 'object_type', 'object_id', 'actor' ] ) );
        $this->route( $namespace, '/notifications', 'GET', [ $this, 'notifications' ], $this->listArgs( [ 'severity', 'unread' ] ) );
        $this->route( $namespace, '/notifications/read', 'POST', [ $this, 'markRead' ] );
        $this->route( $namespace, '/suppression', 'GET', [ $this, 'suppression' ], $this->listArgs( [ 'type' ] ) );
        $this->route( $namespace, '/suppression', 'POST', [ $this, 'addSuppression' ] );
        $this->route( $namespace, '/suppression/(?P<id>\d+)', 'DELETE', [ $this, 'deleteSuppression' ], $this->idArgs() );
    }

    /** GET /logs @return array<string,mixed> */
    public function logs( WP_REST_Request $request ): array {
        $pg    = $this->paginationArgs( $request, 'id', 'DESC' );
        $where = [];
        $levels = array_values( array_intersect( $this->keyList( $request, 'level' ), self::LEVELS ) );
        if ( [] !== $levels ) {
            $where['level'] = count( $levels ) > 1 ? $levels : $levels[0];
        }
        $channel = $this->str( $request, 'channel', '', 40 );
        $channel = preg_replace( '/[^a-z0-9_:\-]/', '', strtolower( $channel ) ) ?? '';
        if ( '' !== $channel ) {
            $where['channel'] = $channel;
        }
        $extra  = '';
        $params = [];
        $since  = $this->since( $request );
        if ( '' !== $since ) {
            $extra    = 'created_at >= %s';
            $params[] = $since;
        }
        $repo = $this->c->get( Logs::class );
        $page = $repo->paginate( $where, $pg['q'], [ 'message', 'channel' ], $pg['page'], $pg['per_page'], $pg['orderby'], $pg['order'], $extra, $params );
        $page['facets'] = $this->safe(
            static fn(): array => [
                'level_24h' => $repo->levelCountsSince( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ),
                'channel'   => $repo->countBy( 'channel' ),
            ],
            [],
            'log facets'
        );
        return $page;
    }

    /** GET /audit @return array<string,mixed> */
    public function auditLog( WP_REST_Request $request ): array {
        $pg    = $this->paginationArgs( $request, 'id', 'DESC' );
        $where = [];
        $action = $this->str( $request, 'action', '', 60 );
        $action = preg_replace( '/[^a-z0-9_\.\-]/', '', strtolower( $action ) ) ?? '';
        if ( '' !== $action ) {
            $where['action'] = $action;
        }
        $objectType = sanitize_key( $this->str( $request, 'object_type', '', 40 ) );
        if ( '' !== $objectType ) {
            $where['object_type'] = self::OBJECT_TYPE_ALIASES[ $objectType ] ?? $objectType;
        }
        $objectId = $this->int( $request, 'object_id', 0 );
        if ( $objectId > 0 ) {
            $where['object_id'] = $objectId;
        }
        $actor = $this->str( $request, 'actor', '', 60 );
        if ( '' !== $actor ) {
            $where['actor'] = $actor;
        }
        $repo = $this->c->get( AuditLog::class );
        $page = $repo->paginate( $where, $pg['q'], [ 'action', 'actor', 'object_type' ], $pg['page'], $pg['per_page'], $pg['orderby'], $pg['order'] );
        $page['items']  = array_map( static fn( array $row ): array => self::castInts( $row, [ 'object_id' ] ), $page['items'] );
        $page['facets'] = $this->safe( static fn(): array => [ 'action' => $repo->countBy( 'action' ), 'object_type' => $repo->countBy( 'object_type' ) ], [], 'audit facets' );
        return $page;
    }

    /** GET /notifications @return array<string,mixed> */
    public function notifications( WP_REST_Request $request ): array {
        $pg    = $this->paginationArgs( $request, 'id', 'DESC' );
        $where = [];
        $severity = array_values( array_intersect( $this->keyList( $request, 'severity' ), [ 'info', 'success', 'warning', 'critical' ] ) );
        if ( [] !== $severity ) {
            $where['severity'] = count( $severity ) > 1 ? $severity : $severity[0];
        }
        $unread = $request->get_param( 'unread' );
        if ( null !== $unread && '' !== $unread && $this->bool( $request, 'unread', false ) ) {
            $where['is_read'] = 0;
        }
        $repo = $this->c->get( Notifications::class );
        $page = $repo->paginate( $where, $pg['q'], [ 'title', 'message' ], $pg['page'], $pg['per_page'], $pg['orderby'], $pg['order'] );
        $page['items']  = array_map( static fn( array $n ): array => self::castInts( $n, [ 'is_read' ] ), $page['items'] );
        $page['unread'] = (int) $this->safe( static fn(): int => $repo->unreadCount(), 0, 'unread count' );
        return $page;
    }

    /** POST /notifications/read @return array<string,mixed> */
    public function markRead( WP_REST_Request $request ): array {
        unset( $request );
        $repo = $this->c->get( Notifications::class );
        $repo->markAllRead();
        return [ 'ok' => true, 'unread' => (int) $this->safe( static fn(): int => $repo->unreadCount(), 0, 'unread count' ) ];
    }

    /** GET /suppression @return array<string,mixed> */
    public function suppression( WP_REST_Request $request ): array {
        $pg    = $this->paginationArgs( $request, 'id', 'DESC' );
        $where = [];
        $type  = sanitize_key( $this->str( $request, 'type', '', 20 ) );
        if ( in_array( $type, self::SUPPRESSION_TYPES, true ) ) {
            $where['type'] = $type;
        }
        $repo = $this->c->get( Suppression::class );
        $page = $repo->paginate( $where, $pg['q'], [ 'value', 'reason' ], $pg['page'], $pg['per_page'], $pg['orderby'], $pg['order'] );
        $page['facets'] = $this->safe( static fn(): array => [ 'type' => $repo->countBy( 'type' ) ], [], 'suppression facets' );
        $page['types']  = self::SUPPRESSION_TYPES;
        return $page;
    }

    /** POST /suppression {type, value, reason} @return array<string,mixed>|\WP_Error */
    public function addSuppression( WP_REST_Request $request ) {
        $type = sanitize_key( $this->str( $request, 'type', 'domain', 20 ) );
        if ( ! in_array( $type, self::SUPPRESSION_TYPES, true ) ) {
            return $this->fail( 'ppclg_invalid_type', __( 'Suppression type must be domain, email or email_domain.', 'ppc-link-growth-engine' ), 400 );
        }
        $value  = strtolower( $this->str( $request, 'value', '', 190 ) );
        $reason = $this->str( $request, 'reason', '', 190 );
        $value  = Suppression::normalizeValue( $type, $value );
        if ( 'email_domain' === $type ) {
            $value = preg_replace( '/^www\./', '', $value ) ?? $value;
            if ( ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $value ) ) {
                $value = '';
            }
        }
        if ( '' === $value ) {
            return $this->fail( 'ppclg_invalid_value', __( 'The value is not a valid domain or email address.', 'ppc-link-growth-engine' ), 400 );
        }
        $repo = $this->c->get( Suppression::class );
        try {
            $added = $repo->add( $type, $value, '' !== $reason ? $reason : 'Added by reviewer' );
        } catch ( \RuntimeException $e ) {
            $this->logger()->error( 'rest', 'Suppression entry could not be stored: ' . $e->getMessage(), [ 'type' => $type ] );
            return $this->fail(
                'ppclg_suppression_not_stored',
                'email_domain' === $type
                    ? __( 'The email-domain entry could not be saved because the suppression table on this site does not accept that entry type yet (database update pending). Add it as a "domain" entry instead: that also blocks every email address at the domain.', 'ppc-link-growth-engine' )
                    : __( 'The suppression entry could not be saved. Check the plugin logs for details.', 'ppc-link-growth-engine' ),
                422,
                [ 'type' => $type ]
            );
        }
        $row = $repo->findOneBy( [ 'type' => $type, 'value' => $value ] );
        if ( $added ) {
            $this->audit( 'suppression.added', 'suppression', (int) ( $row['id'] ?? 0 ), [ 'type' => $type, 'value' => $value, 'reason' => $reason ] );
        }
        return [ 'ok' => true, 'added' => $added, 'entry' => $row ];
    }

    /** DELETE /suppression/{id} @return array<string,mixed>|\WP_Error */
    public function deleteSuppression( WP_REST_Request $request ) {
        $id   = $this->id( $request );
        $repo = $this->c->get( Suppression::class );
        $row  = $repo->find( $id );
        if ( null === $row ) {
            return $this->notFound( __( 'Suppression entry', 'ppc-link-growth-engine' ) );
        }
        $repo->delete( $id );
        $this->audit( 'suppression.removed', 'suppression', $id, [ 'type' => (string) $row['type'], 'value' => (string) $row['value'] ] );
        return [ 'ok' => true, 'deleted' => $id ];
    }

    /** ISO date/time or relative "24h"/"7d" → UTC datetime string ('' when absent). */
    private function since( WP_REST_Request $request ): string {
        $raw = $this->str( $request, 'since', '', 40 );
        if ( '' === $raw ) {
            return '';
        }
        if ( preg_match( '/^(\d{1,4})([hd])$/', $raw, $m ) ) {
            $seconds = (int) $m[1] * ( 'h' === $m[2] ? HOUR_IN_SECONDS : DAY_IN_SECONDS );
            return gmdate( 'Y-m-d H:i:s', time() - $seconds );
        }
        $ts = strtotime( $raw );
        return false === $ts ? '' : gmdate( 'Y-m-d H:i:s', $ts );
    }
}
