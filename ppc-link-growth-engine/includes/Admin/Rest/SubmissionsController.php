<?php
/**
 * /submissions — review queue, package editing and workflow actions.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Admin\Rest;

use PPC\LinkGrowth\Content\ClaimsFilter;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Repository;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Submission\SubmissionEngine;
use WP_REST_Request;

final class SubmissionsController extends Controller {

    private const ACTIONS = [ 'approve', 'reject', 'retry', 'rebuild', 'mark-submitted' ];

    /** Actions that can (re-)queue a submission for automated posting. */
    private const REQUEUE_ACTIONS = [ 'approve', 'retry', 'rebuild' ];

    /** Statuses the queue processor picks up and posts without a further review step. */
    private const AUTO_STATUSES = [ Submissions::STATUS_APPROVED, Submissions::STATUS_QUEUED ];

    public function register( string $namespace ): void {
        $this->route( $namespace, '/submissions', 'GET', [ $this, 'index' ], $this->listArgs( [ 'status', 'method', 'prospect_id', 'adapter' ] ) );
        $this->route( $namespace, '/submissions/(?P<id>\d+)', 'GET', [ $this, 'show' ], $this->idArgs() );
        $this->route( $namespace, '/submissions/(?P<id>\d+)/payload', 'POST', [ $this, 'payload' ], $this->idArgs() );
        $this->route(
            $namespace,
            '/submissions/(?P<id>\d+)/(?P<action>approve|reject|retry|rebuild|mark-submitted)',
            'POST',
            [ $this, 'action' ],
            $this->idArgs() + [ 'action' => [ 'type' => 'string', 'required' => true, 'enum' => self::ACTIONS ] ]
        );
    }

    /** @return array<string,mixed> */
    public function index( WP_REST_Request $request ): array {
        $pg    = $this->paginationArgs( $request, 'id', 'DESC' );
        $where = [];
        $status = $this->keyList( $request, 'status' );
        if ( [] !== $status ) {
            $where['status'] = count( $status ) > 1 ? $status : $status[0];
        }
        foreach ( [ 'method', 'adapter' ] as $key ) {
            $v = sanitize_key( $this->str( $request, $key, '', 60 ) );
            if ( '' !== $v ) {
                $where[ $key ] = $v;
            }
        }
        $prospectId = $this->int( $request, 'prospect_id', 0 );
        if ( $prospectId > 0 ) {
            $where['prospect_id'] = $prospectId;
        }
        $repo = $this->c->get( Submissions::class );
        $page = $repo->paginate( $where, $pg['q'], [ 'target_url', 'listing_url', 'category' ], $pg['page'], $pg['per_page'], $pg['orderby'], $pg['order'] );

        $items = array_map( fn( array $s ): array => $this->slimSubmission( $s ), $page['items'] );
        foreach ( $items as &$item ) {
            // Lists carry a compact payload: the full package is loaded from the detail endpoint.
            $payload         = is_array( $item['payload'] ?? null ) ? $item['payload'] : [];
            $item['payload'] = [
                'destination'      => $payload['destination'] ?? null,
                'compliance'       => $payload['compliance'] ?? null,
                'unmapped_required' => $payload['unmapped_required'] ?? [],
                'fields_count'     => is_array( $payload['fields'] ?? null ) ? count( $payload['fields'] ) : 0,
                'instructions_count' => is_array( $payload['instructions'] ?? null ) ? count( $payload['instructions'] ) : 0,
            ];
            unset( $item['response'] );
        }
        unset( $item );
        $page['items']  = $this->attachProspects( $items );
        $page['facets'] = $this->safe( static fn(): array => [ 'status' => $repo->statusCounts() ], [], 'submission facets' );
        return $page;
    }

    /** @return array<string,mixed>|\WP_Error */
    public function show( WP_REST_Request $request ) {
        $id  = $this->id( $request );
        $sub = $this->c->get( Submissions::class )->find( $id );
        if ( null === $sub ) {
            return $this->notFound( __( 'Submission', 'ppc-link-growth-engine' ) );
        }
        $prospect    = $this->safe( fn(): ?array => $this->c->get( Prospects::class )->find( (int) $sub['prospect_id'] ), null, 'submission prospect' );
        $opportunity = ! empty( $sub['opportunity_id'] ) ? $this->safe( fn(): ?array => $this->c->get( Opportunities::class )->find( (int) $sub['opportunity_id'] ), null, 'submission opportunity' ) : null;
        return [
            'submission'  => $this->slimSubmission( $sub ),
            'prospect'    => $prospect ? $this->slimProspect( $prospect, true ) : null,
            'opportunity' => $opportunity ? self::castInts( $opportunity, [ 'prospect_id', 'score' ] ) : null,
        ];
    }

    /** POST /submissions/{id}/{action} @return array<string,mixed>|\WP_Error */
    public function action( WP_REST_Request $request ) {
        $id     = $this->id( $request );
        $action = sanitize_text_field( (string) $request->get_param( 'action' ) );
        $repo   = $this->c->get( Submissions::class );
        $sub    = $repo->find( $id );
        if ( null === $sub ) {
            return $this->notFound( __( 'Submission', 'ppc-link-growth-engine' ) );
        }
        if ( ! in_array( $action, self::ACTIONS, true ) ) {
            return $this->fail( 'ppclg_invalid_action', __( 'Unknown submission action.', 'ppc-link-growth-engine' ), 400 );
        }
        $engine = $this->c->get( SubmissionEngine::class );
        $user   = $this->userId();
        $ok     = false;
        $message = '';

        // Gates that apply before any (re-)queueing for automated posting.
        if ( in_array( $action, self::REQUEUE_ACTIONS, true ) ) {
            $refusal = $this->requeueRefusal( $id, $sub, $action );
            if ( null !== $refusal ) {
                $fresh = $repo->find( $id );
                return [
                    'ok'         => false,
                    'action'     => $action,
                    'message'    => $refusal,
                    'submission' => $fresh ? $this->slimSubmission( $fresh ) : null,
                ];
            }
        }

        switch ( $action ) {
            case 'approve':
                $ok      = $engine->approve( $id, $user );
                $message = $ok
                    ? __( 'Submission approved for automated processing.', 'ppc-link-growth-engine' )
                    : __( 'The submission could not be approved for automation and stays in manual mode. Review the reasons and submit it by hand, or mark it as submitted once done.', 'ppc-link-growth-engine' );
                break;
            case 'reject':
                $ok      = $engine->reject( $id, $user, $this->str( $request, 'reason', '', 1000 ) );
                $message = $ok ? __( 'Submission rejected.', 'ppc-link-growth-engine' ) : __( 'This submission cannot be rejected (already confirmed).', 'ppc-link-growth-engine' );
                break;
            case 'retry':
                $ok      = $engine->retry( $id, $user );
                $message = $ok ? __( 'Submission queued again.', 'ppc-link-growth-engine' ) : __( 'The submission could not be re-queued (only failed or rejected submissions that pass the automation policy can be retried).', 'ppc-link-growth-engine' );
                break;
            case 'rebuild':
                $ok      = $engine->rebuildPackage( $id, $user );
                $message = $ok ? __( 'Package rebuilt from a fresh crawl.', 'ppc-link-growth-engine' ) : __( 'The package could not be rebuilt (submitted or confirmed submissions are locked).', 'ppc-link-growth-engine' );
                if ( $ok ) {
                    // A freshly generated package must pass the claims check before it can stay queued.
                    $rebuilt = $repo->find( $id );
                    if ( null !== $rebuilt && $this->holdForClaims( $id, $rebuilt, $this->claimsViolations( is_array( $rebuilt['payload'] ?? null ) ? $rebuilt['payload'] : [] ) ) ) {
                        $message .= ' ' . __( 'The new text fails the claims check, so it is held for review: edit the flagged text, save it, then approve it.', 'ppc-link-growth-engine' );
                    }
                }
                break;
            case 'mark-submitted':
                $listing = $this->str( $request, 'listing_url', '', 700 );
                if ( '' !== $listing && ! preg_match( '#^https?://#i', $listing ) ) {
                    $listing = 'https://' . ltrim( $listing, '/' );
                }
                $ok      = $engine->markSubmittedManually( $id, $user, $listing, $this->str( $request, 'confirmation_ref', '', 190 ), $this->text( $request, 'note', '', 2000 ) );
                $message = $ok ? __( 'Marked as submitted; verification starts on the next run.', 'ppc-link-growth-engine' ) : __( 'This submission is already confirmed.', 'ppc-link-growth-engine' );
                break;
        }

        $fresh = $repo->find( $id );
        return [
            'ok'         => $ok,
            'action'     => $action,
            'message'    => $message,
            'submission' => $fresh ? $this->slimSubmission( $fresh ) : null,
        ];
    }

    /**
     * POST /submissions/{id}/payload {payload} — reviewer edits to generated content / field values.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function payload( WP_REST_Request $request ) {
        $id   = $this->id( $request );
        $repo = $this->c->get( Submissions::class );
        $sub  = $repo->find( $id );
        if ( null === $sub ) {
            return $this->notFound( __( 'Submission', 'ppc-link-growth-engine' ) );
        }
        if ( in_array( (string) $sub['status'], [ Submissions::STATUS_SUBMITTED, Submissions::STATUS_CONFIRMED ], true ) ) {
            return $this->fail( 'ppclg_submission_locked', __( 'Submitted and confirmed submissions cannot be edited.', 'ppc-link-growth-engine' ), 409 );
        }
        $incoming = $request->get_param( 'payload' );
        if ( is_string( $incoming ) ) {
            $decoded  = json_decode( $incoming, true );
            $incoming = is_array( $decoded ) ? $decoded : null;
        }
        if ( ! is_array( $incoming ) ) {
            return $this->fail( 'ppclg_invalid_payload', __( 'A payload object is required.', 'ppc-link-growth-engine' ), 400 );
        }
        $clean   = $this->sanitizeDeep( $incoming );
        $payload = is_array( $sub['payload'] ?? null ) ? $sub['payload'] : [];
        $changed = [];

        foreach ( [ 'content', 'business' ] as $section ) {
            if ( isset( $clean[ $section ] ) && is_array( $clean[ $section ] ) ) {
                $current = is_array( $payload[ $section ] ?? null ) ? $payload[ $section ] : [];
                foreach ( $clean[ $section ] as $k => $v ) {
                    if ( ! is_string( $k ) ) {
                        continue;
                    }
                    if ( ( $current[ $k ] ?? null ) !== $v ) {
                        $changed[] = $section . '.' . $k;
                    }
                    $current[ $k ] = $v;
                }
                $payload[ $section ] = $current;
            }
        }
        if ( isset( $clean['fields'] ) && is_array( $clean['fields'] ) && is_array( $payload['fields'] ?? null ) ) {
            $index = [];
            foreach ( $payload['fields'] as $i => $f ) {
                if ( is_array( $f ) && isset( $f['name'] ) ) {
                    $index[ (string) $f['name'] ] = $i;
                }
            }
            foreach ( $clean['fields'] as $f ) {
                if ( ! is_array( $f ) || ! isset( $f['name'] ) || ! isset( $index[ (string) $f['name'] ] ) ) {
                    continue;
                }
                $i     = $index[ (string) $f['name'] ];
                $value = is_scalar( $f['value'] ?? null ) ? (string) $f['value'] : '';
                if ( (string) ( $payload['fields'][ $i ]['value'] ?? '' ) !== $value ) {
                    $changed[] = 'fields.' . (string) $f['name'];
                }
                $payload['fields'][ $i ]['value'] = $value;
            }
            $unmapped = [];
            foreach ( $payload['fields'] as $f ) {
                if ( is_array( $f ) && ! empty( $f['required'] ) && '' === trim( (string) ( $f['value'] ?? '' ) ) ) {
                    $unmapped[] = (string) ( $f['semantic'] ?? $f['name'] ?? '' );
                }
            }
            $payload['unmapped_required'] = array_values( array_unique( array_filter( $unmapped ) ) );
        }
        if ( isset( $clean['instructions'] ) && is_array( $clean['instructions'] ) ) {
            $payload['instructions'] = array_values( array_map( static fn( $i ): string => is_scalar( $i ) ? (string) $i : '', $clean['instructions'] ) );
            $changed[]               = 'instructions';
        }

        // Claims compliance check on every editable text.
        $violations            = $this->claimsViolations( $payload );
        $payload['compliance'] = [ 'clean' => [] === $violations, 'violations' => $violations, 'checked_at' => gmdate( 'c' ) ];
        $payload['edited_by']  = $this->userId();
        $payload['edited_at']  = gmdate( 'c' );

        $repo->update( $id, [ 'payload' => $payload, 'updated_at' => Repository::now() ] );
        $this->audit( 'submission.payload_edited', 'submission', $id, [ 'changed' => array_values( array_unique( $changed ) ), 'violations' => count( $violations ) ] );

        // An approved/queued submission whose text now fails the claims check must not be posted:
        // take it out of the automated queue until the text is fixed and it is approved again.
        $held = $this->holdForClaims( $id, $sub, $violations );

        $fresh = $repo->find( $id );
        return [
            'ok'         => true,
            'clean'      => [] === $violations,
            'violations' => $violations,
            'held'       => $held,
            'changed'    => array_values( array_unique( $changed ) ),
            'submission' => $fresh ? $this->slimSubmission( $fresh ) : null,
        ];
    }

    // ------------------------------------------------------------------

    /**
     * Why an approve/retry/rebuild must not (re-)queue this submission, or null when it may proceed.
     *
     * @param array<string,mixed> $sub
     */
    private function requeueRefusal( int $id, array $sub, string $action ): ?string {
        // Already sent: a second automated post to the same form would be a duplicate submission.
        $sentAt = trim( (string) ( $sub['submitted_at'] ?? '' ) );
        if ( '' !== $sentAt && ! in_array( (string) $sub['status'], [ Submissions::STATUS_SUBMITTED, Submissions::STATUS_CONFIRMED ], true ) ) {
            $this->audit( 'submission.requeue_refused', 'submission', $id, [ 'action' => $action, 'reason' => 'already_sent', 'submitted_at' => $sentAt ] );
            return sprintf(
                /* translators: %s: date/time the submission was sent */
                __( 'This submission was already sent to the site on %s, so it cannot be queued again (that would post a duplicate). If the listing never appeared, follow up with the site by hand and use "Mark as submitted" to record the result.', 'ppc-link-growth-engine' ),
                $sentAt
            );
        }
        if ( 'rebuild' === $action ) {
            return null; // The rebuilt package is checked after it is generated.
        }
        // Text that fails the claims check is never approved or re-queued for automated posting.
        $violations = $this->claimsViolations( is_array( $sub['payload'] ?? null ) ? $sub['payload'] : [] );
        if ( [] === $violations ) {
            return null;
        }
        $this->holdForClaims( $id, $sub, $violations );
        $this->audit( 'submission.requeue_refused', 'submission', $id, [ 'action' => $action, 'reason' => 'claims', 'violations' => count( $violations ) ] );
        return sprintf(
            /* translators: 1: number of issues, 2: list of flagged fields */
            __( 'The package text fails the claims check (%1$d issue(s) in: %2$s). Edit the flagged text, save it, then try again.', 'ppc-link-growth-engine' ),
            count( $violations ),
            implode( ', ', array_values( array_unique( array_map( static fn( array $v ): string => (string) ( $v['field'] ?? '' ), $violations ) ) ) )
        );
    }

    /**
     * Takes an approved/queued submission out of the automated queue when its text fails the claims check.
     *
     * @param array<string,mixed>            $sub
     * @param array<int,array<string,mixed>> $violations
     * @return bool True when the submission was moved back to review.
     */
    private function holdForClaims( int $id, array $sub, array $violations ): bool {
        if ( [] === $violations || ! in_array( (string) ( $sub['status'] ?? '' ), self::AUTO_STATUSES, true ) ) {
            return false;
        }
        $fields = array_values( array_unique( array_map( static fn( array $v ): string => (string) ( $v['field'] ?? '' ), $violations ) ) );
        $this->c->get( Submissions::class )->setStatus(
            $id,
            Submissions::STATUS_PENDING_REVIEW,
            [
                'scheduled_at' => null,
                'last_error'   => mb_substr( 'Held for review: the package text fails the claims check (' . implode( ', ', array_filter( $fields ) ) . '). Edit the flagged text, then approve it again.', 0, 2000 ),
            ]
        );
        $this->audit( 'submission.held_for_claims', 'submission', $id, [ 'previous_status' => (string) $sub['status'], 'violations' => count( $violations ), 'fields' => $fields ] );
        return true;
    }

    /**
     * Claims-filter hits in the texts that are published or posted: content.* and fields[].value.
     *
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>> Each hit carries rule, match, message, offset and field.
     */
    private function claimsViolations( array $payload ): array {
        $filter     = $this->c->get( ClaimsFilter::class );
        $violations = [];
        $check      = static function ( string $field, mixed $value ) use ( &$violations, $filter ): void {
            if ( is_array( $value ) ) {
                foreach ( $value as $k => $v ) {
                    if ( is_string( $v ) ) {
                        foreach ( $filter->check( $v ) as $hit ) {
                            $violations[] = $hit + [ 'field' => $field . '.' . $k ];
                        }
                    }
                }
                return;
            }
            if ( is_string( $value ) && '' !== $value ) {
                foreach ( $filter->check( $value ) as $hit ) {
                    $violations[] = $hit + [ 'field' => $field ];
                }
            }
        };
        foreach ( (array) ( $payload['content'] ?? [] ) as $k => $v ) {
            $check( 'content.' . $k, $v );
        }
        foreach ( (array) ( $payload['fields'] ?? [] ) as $f ) {
            if ( is_array( $f ) && isset( $f['name'] ) ) {
                $check( 'fields.' . (string) $f['name'], $f['value'] ?? '' );
            }
        }
        return $violations;
    }
}
