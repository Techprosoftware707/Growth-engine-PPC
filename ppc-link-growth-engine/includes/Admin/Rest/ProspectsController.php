<?php
/**
 * /prospects — listing, detail, manual intake and per-prospect actions.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Admin\Rest;

use PPC\LinkGrowth\Automation\Pipeline;
use PPC\LinkGrowth\Discovery\ProspectIntake;
use PPC\LinkGrowth\Integrations\MetricsAggregator;
use PPC\LinkGrowth\Outreach\OutreachEngine;
use PPC\LinkGrowth\Repositories\Backlinks;
use PPC\LinkGrowth\Repositories\Contacts;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Repository;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Repositories\Suppression;
use PPC\LinkGrowth\Submission\SubmissionEngine;
use PPC\LinkGrowth\Support\Secrets;
use WP_REST_Request;

final class ProspectsController extends Controller {

    /** Seconds granted to on-demand pipeline runs triggered from the UI. */
    private const RUN_SECONDS = 25.0;

    public function register( string $namespace ): void {
        $this->route( $namespace, '/prospects', 'GET', [ $this, 'index' ], $this->listArgs( [ 'status', 'class', 'type', 'site_type', 'country', 'via', 'min_score', 'automation_allowed' ] ) );
        $this->route( $namespace, '/prospects', 'POST', [ $this, 'create' ] );
        $this->route( $namespace, '/prospects/(?P<id>\d+)', 'GET', [ $this, 'show' ], $this->idArgs() );
        $this->route( $namespace, '/prospects/(?P<id>\d+)', 'DELETE', [ $this, 'destroy' ], $this->idArgs() );
        $this->route( $namespace, '/prospects/(?P<id>\d+)/status', 'POST', [ $this, 'setStatus' ], $this->idArgs() );
        $this->route( $namespace, '/prospects/(?P<id>\d+)/evaluate', 'POST', [ $this, 'evaluate' ], $this->idArgs() );
        $this->route( $namespace, '/prospects/(?P<id>\d+)/automation', 'POST', [ $this, 'automation' ], $this->idArgs() );
        $this->route( $namespace, '/prospects/(?P<id>\d+)/notes', 'POST', [ $this, 'notes' ], $this->idArgs() );
        $this->route( $namespace, '/prospects/(?P<id>\d+)/suppress', 'POST', [ $this, 'suppress' ], $this->idArgs() );
        $this->route( $namespace, '/prospects/(?P<id>\d+)/package', 'POST', [ $this, 'package' ], $this->idArgs() );
        $this->route( $namespace, '/prospects/(?P<id>\d+)/contacts', 'POST', [ $this, 'addContact' ], $this->idArgs() );
        $this->route( $namespace, '/prospects/(?P<id>\d+)/find-opportunities', 'POST', [ $this, 'findOpportunities' ], $this->idArgs() );
    }

    /** @return array<string,mixed> */
    public function index( WP_REST_Request $request ): array {
        $pg    = $this->paginationArgs( $request, 'score', 'DESC' );
        $where = [];
        foreach ( [ 'status' => 'status', 'class' => 'score_class', 'type' => 'opportunity_type', 'site_type' => 'site_type' ] as $param => $column ) {
            $values = $this->keyList( $request, $param );
            if ( [] !== $values ) {
                $where[ $column ] = count( $values ) > 1 ? $values : $values[0];
            }
        }
        $via = preg_replace( '/[^a-z0-9_:\.\-]/', '', strtolower( $this->str( $request, 'via', '', 60 ) ) ) ?? '';
        if ( '' !== $via ) {
            $where['discovered_via'] = $via;
        }
        $country = strtoupper( preg_replace( '/[^a-z]/i', '', $this->str( $request, 'country', '', 2 ) ) ?? '' );
        if ( '' !== $country ) {
            $where['country'] = $country;
        }
        $auto = $request->get_param( 'automation_allowed' );
        if ( null !== $auto && '' !== $auto ) {
            $where['automation_allowed'] = $this->bool( $request, 'automation_allowed' ) ? 1 : 0;
        }
        $extra  = '';
        $params = [];
        $min    = $this->int( $request, 'min_score', -1 );
        if ( $min >= 0 ) {
            $extra    = 'score >= %d';
            $params[] = min( 100, $min );
        }

        $repo = $this->c->get( Prospects::class );
        $page = $repo->paginate( $where, $pg['q'], [ 'domain', 'name' ], $pg['page'], $pg['per_page'], $pg['orderby'], $pg['order'], $extra, $params );

        $page['items']  = array_map( fn( array $p ): array => $this->slimProspect( $p, true ), $page['items'] );
        $page['facets'] = $this->safe(
            static fn(): array => [
                'status' => $repo->statusCounts(),
                'class'  => $repo->classCounts(),
                'type'   => $repo->typeCounts(),
            ],
            [],
            'prospect facets'
        );
        return $page;
    }

    /** @return array<string,mixed>|\WP_Error */
    public function show( WP_REST_Request $request ) {
        $id       = $this->id( $request );
        $prospect = $this->c->get( Prospects::class )->find( $id );
        if ( null === $prospect ) {
            return $this->notFound( __( 'Prospect', 'ppc-link-growth-engine' ) );
        }
        $domain    = (string) ( $prospect['domain'] ?? '' );
        $backlinks = $this->c->get( Backlinks::class );
        $links     = [];
        $this->safe(
            function () use ( &$links, $backlinks, $id, $domain ): void {
                foreach ( array_merge( $backlinks->findBy( [ 'prospect_id' => $id ], 'id', 'DESC', 50 ), '' !== $domain ? $backlinks->forDomain( $domain ) : [] ) as $row ) {
                    $links[ (int) $row['id'] ] = self::castInts( $row, [ 'prospect_id', 'http_status', 'is_indexable', 'quality_score', 'consecutive_failures' ] );
                }
            },
            null,
            'prospect backlinks'
        );

        $metrics        = is_array( $prospect['metrics'] ?? null ) ? $prospect['metrics'] : [];
        $metricsMissing = is_array( $metrics['missing'] ?? null ) ? $metrics['missing'] : [];
        if ( ! $this->safe( fn(): bool => $this->c->get( MetricsAggregator::class )->hasAnyProvider(), false, 'metrics providers' ) ) {
            $metricsMissing['_providers'] = __( 'No domain-metrics provider is configured.', 'ppc-link-growth-engine' );
        }

        $secrets = $this->c->get( Secrets::class );
        $keyName = 'partner_api_' . $id;

        return [
            'prospect'        => $this->slimProspect( $prospect ),
            'opportunities'   => $this->safe( fn(): array => array_map( static fn( array $o ): array => self::castInts( $o, [ 'prospect_id', 'score' ] ), $this->c->get( Opportunities::class )->forProspect( $id ) ), [], 'prospect opportunities' ),
            'contacts'        => $this->safe( fn(): array => array_map( static fn( array $c ): array => self::castInts( $c, [ 'prospect_id', 'confidence' ] ), $this->c->get( Contacts::class )->forProspect( $id ) ), [], 'prospect contacts' ),
            'submissions'     => $this->safe( fn(): array => array_map( fn( array $s ): array => $this->slimSubmission( $s ), $this->c->get( Submissions::class )->findBy( [ 'prospect_id' => $id ], 'id', 'DESC', 50 ) ), [], 'prospect submissions' ),
            'messages'        => $this->safe( fn(): array => array_map( static fn( array $m ): array => self::castInts( $m, [ 'campaign_id', 'prospect_id', 'contact_id', 'opportunity_id', 'sequence' ] ), $this->c->get( OutreachMessages::class )->findBy( [ 'prospect_id' => $id ], 'id', 'DESC', 50 ) ), [], 'prospect messages' ),
            'backlinks'       => array_values( $links ),
            'metrics_missing' => $metricsMissing,
            'partner_api_key' => [
                'configured' => $secrets->has( $keyName ),
                'masked'     => $secrets->masked( $keyName ),
            ],
        ];
    }

    /** POST /prospects {url, note} @return array<string,mixed>|\WP_Error */
    public function create( WP_REST_Request $request ) {
        $url  = $this->str( $request, 'url', '', 700 );
        $note = $this->text( $request, 'note', '', 5000 );
        if ( '' === $url ) {
            return $this->fail( 'ppclg_missing_url', __( 'A URL or domain is required.', 'ppc-link-growth-engine' ), 400 );
        }
        $result = $this->c->get( ProspectIntake::class )->manual( $url, $note, $this->userId() );
        if ( null === $result || (int) ( $result['prospect_id'] ?? 0 ) <= 0 ) {
            $reason = is_array( $result ) && ! empty( $result['skipped'] ) ? (string) $result['skipped'] : __( 'The URL could not be added (invalid, excluded, suppressed or already rejected).', 'ppc-link-growth-engine' );
            return $this->fail( 'ppclg_intake_rejected', $reason, 422 );
        }
        $prospectId = (int) $result['prospect_id'];
        $prospect   = $this->c->get( Prospects::class )->find( $prospectId );
        return [
            'ok'             => true,
            'created'        => ! empty( $result['created'] ),
            'prospect_id'    => $prospectId,
            'opportunity_id' => isset( $result['opportunity_id'] ) ? (int) $result['opportunity_id'] : null,
            'skipped'        => $result['skipped'] ?? null,
            'prospect'       => $prospect ? $this->slimProspect( $prospect ) : null,
        ];
    }

    /** POST /prospects/{id}/status {status, reason} @return array<string,mixed>|\WP_Error */
    public function setStatus( WP_REST_Request $request ) {
        $id   = $this->id( $request );
        $repo = $this->c->get( Prospects::class );
        $p    = $repo->find( $id );
        if ( null === $p ) {
            return $this->notFound( __( 'Prospect', 'ppc-link-growth-engine' ) );
        }
        $status = sanitize_key( $this->str( $request, 'status', '', 30 ) );
        if ( ! in_array( $status, Prospects::STATUSES, true ) ) {
            return $this->fail( 'ppclg_invalid_status', __( 'Unknown prospect status.', 'ppc-link-growth-engine' ), 400 );
        }
        $reason = $this->str( $request, 'reason', '', 255 );
        $repo->setStatus( $id, $status, $reason );
        if ( Prospects::STATUS_SUPPRESSED === $status ) {
            $this->safe( fn(): bool => $this->c->get( Suppression::class )->add( 'domain', (string) $p['domain'], '' !== $reason ? $reason : 'Suppressed from the prospect list' ), false, 'suppress domain' );
        }
        $this->audit( 'prospect.status_changed', 'prospect', $id, [ 'from' => (string) $p['status'], 'to' => $status, 'reason' => $reason, 'domain' => (string) $p['domain'] ] );
        return [ 'ok' => true, 'prospect' => $this->fresh( $id ) ];
    }

    /** POST /prospects/{id}/evaluate @return array<string,mixed>|\WP_Error */
    public function evaluate( WP_REST_Request $request ) {
        $id = $this->id( $request );
        if ( null === $this->c->get( Prospects::class )->find( $id ) ) {
            return $this->notFound( __( 'Prospect', 'ppc-link-growth-engine' ) );
        }
        $run = $this->c->get( Pipeline::class )->runNow( 'evaluate', [ 'prospect_id' => $id ], self::RUN_SECONDS );
        return [
            'ok'       => (bool) ( $run['result']['ok'] ?? true ),
            'result'   => $run['result'] ?? [],
            'job_id'   => $run['job_id'] ?? null,
            'prospect' => $this->fresh( $id ),
        ];
    }

    /** POST /prospects/{id}/automation {automation_allowed, submission_config, partner_api_key} @return array<string,mixed>|\WP_Error */
    public function automation( WP_REST_Request $request ) {
        $id   = $this->id( $request );
        $repo = $this->c->get( Prospects::class );
        $p    = $repo->find( $id );
        if ( null === $p ) {
            return $this->notFound( __( 'Prospect', 'ppc-link-growth-engine' ) );
        }
        $data    = [ 'updated_at' => Repository::now() ];
        $details = [];
        if ( $request->has_param( 'automation_allowed' ) ) {
            $allowed                    = $this->bool( $request, 'automation_allowed', (bool) ( $p['automation_allowed'] ?? false ) );
            $data['automation_allowed'] = $allowed ? 1 : 0;
            $details['automation_allowed'] = $allowed;
        }
        if ( $request->has_param( 'submission_config' ) ) {
            $raw = $request->get_param( 'submission_config' );
            if ( is_string( $raw ) && '' !== trim( $raw ) ) {
                $decoded = json_decode( $raw, true );
                $raw     = is_array( $decoded ) ? $decoded : null;
            }
            $config                    = is_array( $raw ) ? $this->sanitizeSubmissionConfig( $raw ) : null;
            $data['submission_config'] = $config;
            $details['has_api_config'] = null !== $config && ! empty( $config['api']['endpoint'] );
        }
        $repo->update( $id, $data );

        $secrets = $this->c->get( Secrets::class );
        $keyName = 'partner_api_' . $id;
        $key     = $request->get_param( 'partner_api_key' );
        if ( is_string( $key ) && '' !== trim( $key ) ) {
            $key = trim( $key );
            if ( '__clear__' === $key ) {
                $secrets->delete( $keyName );
                $details['partner_api_key'] = 'cleared';
            } elseif ( strlen( $key ) <= 4000 ) {
                $secrets->set( $keyName, $key );
                $details['partner_api_key'] = 'updated';
            }
        }
        $this->audit( 'prospect.automation_updated', 'prospect', $id, $details );
        return [
            'ok'              => true,
            'prospect'        => $this->fresh( $id ),
            'partner_api_key' => [ 'configured' => $secrets->has( $keyName ), 'masked' => $secrets->masked( $keyName ) ],
        ];
    }

    /** POST /prospects/{id}/notes {notes} @return array<string,mixed>|\WP_Error */
    public function notes( WP_REST_Request $request ) {
        $id   = $this->id( $request );
        $repo = $this->c->get( Prospects::class );
        if ( null === $repo->find( $id ) ) {
            return $this->notFound( __( 'Prospect', 'ppc-link-growth-engine' ) );
        }
        $notes = $this->text( $request, 'notes', '', 5000 );
        $repo->update( $id, [ 'notes' => $notes, 'updated_at' => Repository::now() ] );
        $this->audit( 'prospect.notes_updated', 'prospect', $id, [ 'length' => mb_strlen( $notes ) ] );
        return [ 'ok' => true, 'prospect' => $this->fresh( $id ) ];
    }

    /** POST /prospects/{id}/suppress {reason} @return array<string,mixed>|\WP_Error */
    public function suppress( WP_REST_Request $request ) {
        $id   = $this->id( $request );
        $repo = $this->c->get( Prospects::class );
        $p    = $repo->find( $id );
        if ( null === $p ) {
            return $this->notFound( __( 'Prospect', 'ppc-link-growth-engine' ) );
        }
        $reason = $this->str( $request, 'reason', '', 190 );
        $repo->setStatus( $id, Prospects::STATUS_SUPPRESSED, '' !== $reason ? $reason : 'Suppressed by reviewer' );
        $added = $this->safe( fn(): bool => $this->c->get( Suppression::class )->add( 'domain', (string) $p['domain'], '' !== $reason ? $reason : 'Suppressed from the prospect list' ), false, 'suppress domain' );
        $this->safe(
            function () use ( $id ): void {
                $opps = $this->c->get( Opportunities::class );
                foreach ( $opps->findBy( [ 'prospect_id' => $id, 'status' => [ Opportunities::STATUS_OPEN, Opportunities::STATUS_PACKAGED ] ], 'id', 'ASC', 100 ) as $o ) {
                    $opps->setStatus( (int) $o['id'], Opportunities::STATUS_DISMISSED );
                }
            },
            null,
            'dismiss opportunities'
        );
        $this->audit( 'prospect.suppressed', 'prospect', $id, [ 'domain' => (string) $p['domain'], 'reason' => $reason, 'suppression_added' => (bool) $added ] );
        return [ 'ok' => true, 'prospect' => $this->fresh( $id ), 'suppression_added' => (bool) $added ];
    }

    /** POST /prospects/{id}/package @return array<string,mixed>|\WP_Error */
    public function package( WP_REST_Request $request ) {
        $id = $this->id( $request );
        if ( null === $this->c->get( Prospects::class )->find( $id ) ) {
            return $this->notFound( __( 'Prospect', 'ppc-link-growth-engine' ) );
        }
        $submissionId = $this->c->get( SubmissionEngine::class )->packageProspect( $id, $this->userId() );
        if ( null === $submissionId || $submissionId <= 0 ) {
            return $this->fail( 'ppclg_package_failed', __( 'No submission package could be created for this prospect (no open opportunity or usable target page).', 'ppc-link-growth-engine' ), 422 );
        }
        $submission = $this->c->get( Submissions::class )->find( $submissionId );
        return [
            'ok'            => true,
            'submission_id' => $submissionId,
            'submission'    => $submission ? $this->slimSubmission( $submission ) : null,
            'prospect'      => $this->fresh( $id ),
        ];
    }

    /** POST /prospects/{id}/contacts {email, name, role} @return array<string,mixed>|\WP_Error */
    public function addContact( WP_REST_Request $request ) {
        $id = $this->id( $request );
        if ( null === $this->c->get( Prospects::class )->find( $id ) ) {
            return $this->notFound( __( 'Prospect', 'ppc-link-growth-engine' ) );
        }
        $email = sanitize_email( $this->str( $request, 'email', '', 190 ) );
        if ( '' === $email || ! is_email( $email ) ) {
            return $this->fail( 'ppclg_invalid_email', __( 'A valid email address is required.', 'ppc-link-growth-engine' ), 400 );
        }
        $contactId = $this->c->get( OutreachEngine::class )->addContact( $id, $email, $this->str( $request, 'name', '', 190 ), $this->str( $request, 'role', '', 100 ), $this->userId() );
        if ( null === $contactId ) {
            return $this->fail( 'ppclg_contact_rejected', __( 'The contact could not be added.', 'ppc-link-growth-engine' ), 422 );
        }
        $contact = $this->c->get( Contacts::class )->find( $contactId );
        return [
            'ok'         => true,
            'contact_id' => $contactId,
            'contact'    => $contact ? self::castInts( $contact, [ 'prospect_id', 'confidence' ] ) : null,
            'contacts'   => $this->safe( fn(): array => $this->c->get( Contacts::class )->forProspect( $id ), [], 'contacts' ),
        ];
    }

    /** POST /prospects/{id}/find-opportunities @return array<string,mixed>|\WP_Error */
    public function findOpportunities( WP_REST_Request $request ) {
        $id = $this->id( $request );
        if ( null === $this->c->get( Prospects::class )->find( $id ) ) {
            return $this->notFound( __( 'Prospect', 'ppc-link-growth-engine' ) );
        }
        $run = $this->c->get( Pipeline::class )->runNow( 'broken_link_scan', [ 'prospect_id' => $id ], self::RUN_SECONDS );
        return [
            'ok'            => (bool) ( $run['result']['ok'] ?? true ),
            'result'        => $run['result'] ?? [],
            'job_id'        => $run['job_id'] ?? null,
            'opportunities' => $this->safe( fn(): array => $this->c->get( Opportunities::class )->forProspect( $id ), [], 'opportunities' ),
        ];
    }

    /** DELETE /prospects/{id} — removes the prospect and its dependent records (backlinks are kept, detached). @return array<string,mixed>|\WP_Error */
    public function destroy( WP_REST_Request $request ) {
        $id   = $this->id( $request );
        $repo = $this->c->get( Prospects::class );
        $p    = $repo->find( $id );
        if ( null === $p ) {
            return $this->notFound( __( 'Prospect', 'ppc-link-growth-engine' ) );
        }
        global $wpdb;
        $removed = [];
        foreach ( [ Opportunities::class, Submissions::class, Contacts::class, OutreachMessages::class ] as $class ) {
            $removed[ $class ] = $this->safe(
                function () use ( $wpdb, $class, $id ): int {
                    $n = $wpdb->delete( $this->c->get( $class )->table(), [ 'prospect_id' => $id ], [ '%d' ] );
                    return false === $n ? 0 : (int) $n;
                },
                0,
                'cascade delete'
            );
        }
        $this->safe(
            fn() => $wpdb->update( $this->c->get( Backlinks::class )->table(), [ 'prospect_id' => null ], [ 'prospect_id' => $id ], null, [ '%d' ] ),
            null,
            'detach backlinks'
        );
        $repo->delete( $id );
        $this->audit( 'prospect.deleted', 'prospect', $id, [ 'domain' => (string) $p['domain'], 'removed' => $removed ] );
        return [ 'ok' => true, 'deleted' => $id ];
    }

    // ------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function fresh( int $id ): ?array {
        $p = $this->c->get( Prospects::class )->find( $id );
        return $p ? $this->slimProspect( $p ) : null;
    }

    /**
     * Only the documented partner-API keys are kept; the API key itself lives in Secrets.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>|null
     */
    private function sanitizeSubmissionConfig( array $config ): ?array {
        $api      = is_array( $config['api'] ?? null ) ? $config['api'] : $config;
        $endpoint = esc_url_raw( trim( (string) ( $api['endpoint'] ?? '' ) ) );
        if ( '' === $endpoint || ! preg_match( '#^https?://#i', $endpoint ) ) {
            return null;
        }
        $method = strtoupper( sanitize_key( (string) ( $api['method'] ?? 'POST' ) ) );
        if ( ! in_array( $method, [ 'GET', 'POST', 'PUT', 'PATCH' ], true ) ) {
            $method = 'POST';
        }
        $headers = [];
        $rawHeaders = $api['headers'] ?? [];
        if ( is_string( $rawHeaders ) ) {
            $decoded    = json_decode( $rawHeaders, true );
            $rawHeaders = is_array( $decoded ) ? $decoded : [];
        }
        $n = 0;
        foreach ( (array) $rawHeaders as $name => $value ) {
            if ( ++$n > 20 || ! is_scalar( $value ) ) {
                continue;
            }
            $name = preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) $name ) ?? '';
            if ( '' === $name ) {
                continue;
            }
            $headers[ $name ] = mb_substr( sanitize_text_field( (string) $value ), 0, 500 );
        }
        $template = is_string( $api['body_template'] ?? null ) ? (string) $api['body_template'] : '';
        $template = str_replace( "\0", '', wp_check_invalid_utf8( $template ) );
        $pathRule = static fn( mixed $v ): string => mb_substr( preg_replace( '/[^A-Za-z0-9_\.\[\]\-]/', '', (string) ( is_scalar( $v ) ? $v : '' ) ) ?? '', 0, 120 );
        return [
            'api' => [
                'endpoint'         => mb_substr( $endpoint, 0, 700 ),
                'method'           => $method,
                'headers'          => $headers,
                'body_template'    => mb_substr( trim( $template ), 0, 10000 ),
                'success_path'     => $pathRule( $api['success_path'] ?? '' ),
                'listing_url_path' => $pathRule( $api['listing_url_path'] ?? '' ),
            ],
        ];
    }
}
