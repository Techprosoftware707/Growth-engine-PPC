<?php
/**
 * Orchestrates listing submissions: packaging opportunities, the review/approval workflow,
 * processing the authorised queue through adapters, and verifying live listings.
 *
 * Status flow: pending_review (automatable form, authorization pending) / needs_manual (a person
 * must submit) / queued (Policy allowed) → approved (human) → submitted → confirmed | failed.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Submission;

use PPC\LinkGrowth\Analysis\PageAnalysis;
use PPC\LinkGrowth\Analysis\SubmissionPageFinder;
use PPC\LinkGrowth\Monitoring\Notifier;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Repository;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Submission\Adapters\AdapterInterface;
use PPC\LinkGrowth\Support\Limits;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Support\Url;
use Throwable;

final class SubmissionEngine {

    /** Opportunity types whose own URL is the submission target. */
    private const LISTING_TYPES = [ 'directory_listing', 'supplier_listing', 'membership' ];

    private const MAX_ATTEMPTS = 3;
    private const RETRY_DELAY  = 6 * HOUR_IN_SECONDS;
    private const VERIFY_DELAY = 2 * DAY_IN_SECONDS;

    private ?ListingVerifier $verifier = null;

    public function __construct(
        private Submissions $submissions,
        private Prospects $prospects,
        private Opportunities $opportunities,
        private PackageBuilder $builder,
        private Policy $policy,
        private AdapterRegistry $adapters,
        private SubmissionPageFinder $finder,
        private Limits $limits,
        private AuditLog $audit,
        private Notifier $notifier,
        private Logger $logger
    ) {}

    /** Inject a verifier (tests); otherwise it is resolved through the adapter registry's container. */
    public function setVerifier( ListingVerifier $verifier ): void {
        $this->verifier = $verifier;
    }

    // ------------------------------------------------------------------
    // Packaging
    // ------------------------------------------------------------------

    /**
     * Create (or return the existing) submission package for an opportunity.
     */
    public function packageOpportunity( int $opportunityId, int $userId = 0 ): ?int {
        try {
            [ $id ] = $this->package( $opportunityId, $userId );
            return $id > 0 ? $id : null;
        } catch ( Throwable $e ) {
            $this->logger->error( 'submission', 'Packaging failed', [ 'opportunity_id' => $opportunityId, 'error' => $e->getMessage() ] );
            return null;
        }
    }

    /**
     * Package the prospect's best open opportunity (creating one from its submission URL when none exists).
     */
    public function packageProspect( int $prospectId, int $userId = 0 ): ?int {
        try {
            $prospect = $this->prospects->find( $prospectId );
            if ( null === $prospect || in_array( (string) $prospect['status'], [ Prospects::STATUS_REJECTED, Prospects::STATUS_SUPPRESSED ], true ) ) {
                return null;
            }
            $wanted = (string) ( $prospect['opportunity_type'] ?? '' );
            $open   = null;
            $any    = null;
            foreach ( $this->opportunities->forProspect( $prospectId ) as $opp ) {
                $status = (string) ( $opp['status'] ?? '' );
                if ( in_array( $status, [ Opportunities::STATUS_DISMISSED, Opportunities::STATUS_EXPIRED ], true ) ) {
                    continue;
                }
                if ( null === $any ) {
                    $any = $opp;
                }
                if ( Opportunities::STATUS_OPEN !== $status ) {
                    continue;
                }
                if ( null === $open || ( $opp['type'] === $wanted && $open['type'] !== $wanted ) ) {
                    $open = $opp;
                }
            }
            $opp = $open ?? $any;
            if ( null === $opp ) {
                $type = array_key_exists( $wanted, Opportunities::TYPES ) ? $wanted : 'directory_listing';
                $url  = Url::normalize( (string) ( $prospect['submission_url'] ?? '' ) ) ?: Url::normalize( (string) ( $prospect['homepage_url'] ?? '' ) ) ?: 'https://' . $prospect['domain'] . '/';
                [ $oppId ] = $this->opportunities->upsert(
                    $prospectId,
                    $type,
                    $url,
                    (string) ( ( $prospect['name'] ?? '' ) ?: $prospect['domain'] ),
                    [ 'discovered_via' => 'package' ],
                    null !== ( $prospect['score'] ?? null ) ? (int) $prospect['score'] : null
                );
                $opp = $this->opportunities->find( $oppId );
            }
            return null !== $opp ? $this->packageOpportunity( (int) $opp['id'], $userId ) : null;
        } catch ( Throwable $e ) {
            $this->logger->error( 'submission', 'Packaging prospect failed', [ 'prospect_id' => $prospectId, 'error' => $e->getMessage() ] );
            return null;
        }
    }

    /**
     * @return array{packaged:int,skipped:int}
     */
    public function packageOpenOpportunities( TimeBudget $budget, int $max, int $minScore ): array {
        $stats = [ 'packaged' => 0, 'skipped' => 0 ];
        foreach ( $this->opportunities->openForPackaging( max( 1, $max ), $minScore ) as $opp ) {
            if ( ! $budget->has( 8 ) ) {
                break;
            }
            try {
                [ $id, $created ] = $this->package( (int) $opp['id'], 0 );
                if ( $id > 0 && $created ) {
                    $stats['packaged']++;
                } else {
                    $stats['skipped']++;
                }
            } catch ( Throwable $e ) {
                $stats['skipped']++;
                $this->logger->error( 'submission', 'Packaging failed', [ 'opportunity_id' => (int) $opp['id'], 'error' => $e->getMessage() ] );
            }
        }
        return $stats;
    }

    // ------------------------------------------------------------------
    // Queue processing
    // ------------------------------------------------------------------

    /**
     * @return array{processed:int,submitted:int,failed:int,manual:int}
     */
    public function processQueue( TimeBudget $budget, int $max = 5 ): array {
        $stats = [ 'processed' => 0, 'submitted' => 0, 'failed' => 0, 'manual' => 0 ];
        foreach ( $this->submissions->readyToProcess( max( 1, $max ) ) as $sub ) {
            if ( ! $budget->has( 12 ) ) {
                break;
            }
            if ( ! $this->limits->allow( 'submissions' ) ) {
                $this->logger->info( 'submission', 'Submission limit reached; queue paused until the limit resets.' );
                break;
            }
            $stats['processed']++;
            try {
                $this->processOne( $sub, $stats );
            } catch ( Throwable $e ) {
                $id = (int) $sub['id'];
                $this->logger->error( 'submission', 'Queue item crashed', [ 'submission_id' => $id, 'error' => $e->getMessage() ] );
                $attempts = (int) ( $sub['attempts'] ?? 0 ) + 1;
                $this->submissions->setStatus(
                    $id,
                    $attempts < self::MAX_ATTEMPTS ? Submissions::STATUS_QUEUED : Submissions::STATUS_FAILED,
                    [
                        'attempts'     => $attempts,
                        'last_error'   => 'Internal error: ' . $e->getMessage(),
                        'scheduled_at' => $attempts < self::MAX_ATTEMPTS ? gmdate( 'Y-m-d H:i:s', time() + self::RETRY_DELAY ) : null,
                    ]
                );
                $stats['failed']++;
            }
        }
        return $stats;
    }

    /**
     * @param array<string,mixed>                                        $sub
     * @param array{processed:int,submitted:int,failed:int,manual:int} $stats
     */
    private function processOne( array $sub, array &$stats ): void {
        $id       = (int) $sub['id'];
        $prospect = $this->prospects->find( (int) $sub['prospect_id'] );
        if ( null === $prospect ) {
            $this->submissions->setStatus( $id, Submissions::STATUS_FAILED, [ 'last_error' => 'The prospect no longer exists.' ] );
            $stats['failed']++;
            return;
        }
        $pStatus = (string) $prospect['status'];
        if ( in_array( $pStatus, [ Prospects::STATUS_REJECTED, Prospects::STATUS_SUPPRESSED ], true ) ) {
            $this->toManual( $sub, 'The prospect is ' . $pStatus . '; automated submission cancelled.', [], 0 );
            $stats['manual']++;
            return;
        }
        if ( Prospects::STATUS_ON_HOLD === $pStatus ) {
            $this->submissions->update( $id, [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'updated_at' => Repository::now() ] );
            $stats['processed']--;
            return;
        }

        $payload = is_array( $sub['payload'] ?? null ) ? $sub['payload'] : [];
        $form    = $this->formFromPayload( $payload );

        // Re-evaluate the policy right before acting.
        $policy = $this->policy->evaluate( $prospect, $payload, $form, (string) $sub['target_url'], $this->robotsFetcher() );
        if ( ! $policy['allowed'] ) {
            $this->toManual( $sub, implode( ' ', $policy['reasons'] ), [ 'policy' => $this->policyRecord( $policy ) ], 0 );
            $this->notifier->notify( 'submission', 'warning', 'Submission moved to manual', sprintf( 'Submission #%d to %s can no longer be automated: %s', $id, (string) $prospect['domain'], implode( ' ', $policy['reasons'] ) ), [ 'submission_id' => $id, 'prospect_id' => (int) $prospect['id'] ] );
            $stats['manual']++;
            return;
        }

        $adapter = $this->adapters->get( (string) ( $sub['adapter'] ?? '' ) );
        if ( null === $adapter || AdapterRegistry::ID_MANUAL === $adapter->id() ) {
            $adapter = $this->adapters->resolve( $prospect, $payload, $form );
        }
        if ( AdapterRegistry::ID_MANUAL === $adapter->id() ) {
            $this->toManual( $sub, 'No automated channel is available for this target.', [], 0 );
            $stats['manual']++;
            return;
        }

        $result   = $adapter->submit( $sub, $prospect, $payload );
        $status   = (string) ( $result['status'] ?? AdapterInterface::STATUS_FAILED );
        $response = is_array( $result['response'] ?? null ) ? $result['response'] : [];
        $error    = (string) ( $result['error'] ?? '' );
        $attempts = (int) ( $sub['attempts'] ?? 0 ) + 1;
        $now      = Repository::now();

        if ( AdapterInterface::STATUS_NEEDS_MANUAL === $status ) {
            // The adapter stopped before acting (CAPTCHA, changed form…): no limit spent.
            $this->toManual( $sub, $error, $response, $attempts );
            $this->notifier->notify( 'submission', 'info', 'Submission needs a person', sprintf( 'Submission #%d to %s: %s', $id, (string) $prospect['domain'], $error ), [ 'submission_id' => $id, 'prospect_id' => (int) $prospect['id'] ] );
            $stats['manual']++;
            return;
        }

        $this->limits->spend( 'submissions' );

        if ( AdapterInterface::STATUS_SUBMITTED === $status ) {
            $this->submissions->setStatus(
                $id,
                Submissions::STATUS_SUBMITTED,
                [
                    'method'           => $this->adapters->methodFor( $adapter ),
                    'adapter'          => $adapter->id(),
                    'submitted_at'     => $now,
                    'next_verify_at'   => gmdate( 'Y-m-d H:i:s', time() + self::VERIFY_DELAY ),
                    'attempts'         => $attempts,
                    'response'         => $response,
                    'confirmation_ref' => mb_substr( (string) ( $result['confirmation_ref'] ?? '' ), 0, 190 ),
                    'listing_url'      => mb_substr( Url::normalize( (string) ( $result['listing_url'] ?? '' ) ), 0, 700 ),
                    'last_error'       => '',
                    'scheduled_at'     => null,
                ]
            );
            $this->afterSubmitted( $sub, $prospect );
            $this->notifier->notify( 'submission', 'success', 'Listing submitted', sprintf( 'Submission #%d to %s was sent via %s; verification starts in 2 days.', $id, (string) $prospect['domain'], $adapter->label() ), [ 'submission_id' => $id, 'prospect_id' => (int) $prospect['id'] ] );
            $this->audit->record( AuditLog::currentActor(), 'submission.submitted', 'submission', $id, [ 'prospect_id' => (int) $prospect['id'], 'adapter' => $adapter->id(), 'attempt' => $attempts, 'http_status' => $response['http_status'] ?? null ] );
            $stats['submitted']++;
            return;
        }

        // Failed.
        $retry = ! empty( $result['retryable'] ) && $attempts < self::MAX_ATTEMPTS;
        $this->submissions->setStatus(
            $id,
            $retry ? Submissions::STATUS_QUEUED : Submissions::STATUS_FAILED,
            [
                'attempts'     => $attempts,
                'response'     => $response,
                'last_error'   => $error,
                'scheduled_at' => $retry ? gmdate( 'Y-m-d H:i:s', time() + self::RETRY_DELAY ) : null,
            ]
        );
        $this->audit->record( AuditLog::currentActor(), 'submission.failed', 'submission', $id, [ 'prospect_id' => (int) $prospect['id'], 'adapter' => $adapter->id(), 'attempt' => $attempts, 'retry_scheduled' => $retry, 'error' => $error ] );
        if ( ! $retry ) {
            $this->notifier->notify( 'submission', 'warning', 'Submission failed', sprintf( 'Submission #%d to %s failed: %s', $id, (string) $prospect['domain'], $error ), [ 'submission_id' => $id, 'prospect_id' => (int) $prospect['id'] ] );
        }
        $stats['failed']++;
    }

    // ------------------------------------------------------------------
    // Verification
    // ------------------------------------------------------------------

    /**
     * @return array{checked:int,confirmed:int}
     */
    public function verifyDue( TimeBudget $budget, int $max = 10 ): array {
        $stats = [ 'checked' => 0, 'confirmed' => 0 ];
        foreach ( $this->submissions->dueForVerification( max( 1, $max ) ) as $sub ) {
            if ( ! $budget->has( 8 ) ) {
                break;
            }
            $stats['checked']++;
            try {
                $prospect = $this->prospects->find( (int) $sub['prospect_id'] );
                if ( null === $prospect ) {
                    $this->submissions->update( (int) $sub['id'], [ 'next_verify_at' => null, 'last_error' => 'The prospect no longer exists.', 'updated_at' => Repository::now() ] );
                    continue;
                }
                $result = $this->verifier()->verify( $sub, $prospect );
                if ( ! empty( $result['confirmed'] ) ) {
                    $stats['confirmed']++;
                    if ( ! empty( $sub['opportunity_id'] ) ) {
                        $this->opportunities->setStatus( (int) $sub['opportunity_id'], Opportunities::STATUS_ACQUIRED );
                    }
                }
            } catch ( Throwable $e ) {
                $this->logger->error( 'submission', 'Verification crashed', [ 'submission_id' => (int) $sub['id'], 'error' => $e->getMessage() ] );
            }
        }
        return $stats;
    }

    // ------------------------------------------------------------------
    // Review workflow
    // ------------------------------------------------------------------

    /**
     * Approve a pending/manual submission for automated processing. Returns false (and notifies)
     * when the policy does not allow automation; the submission then stays needs_manual.
     */
    public function approve( int $id, int $userId ): bool {
        $sub = $this->submissions->find( $id );
        if ( null === $sub ) {
            return false;
        }
        if ( in_array( (string) $sub['status'], [ Submissions::STATUS_APPROVED, Submissions::STATUS_QUEUED ], true ) ) {
            return true; // already approved for automated processing (idempotent)
        }
        if ( ! in_array( (string) $sub['status'], [ Submissions::STATUS_PENDING_REVIEW, Submissions::STATUS_NEEDS_MANUAL ], true ) ) {
            return false;
        }
        $prospect = $this->prospects->find( (int) $sub['prospect_id'] );
        if ( null === $prospect ) {
            return false;
        }
        $payload = is_array( $sub['payload'] ?? null ) ? $sub['payload'] : [];
        $form    = $this->formFromPayload( $payload );
        $policy  = $this->policy->evaluate( $prospect, $payload, $form, (string) $sub['target_url'], $this->robotsFetcher() );

        if ( $policy['allowed'] ) {
            $adapter = $this->adapters->resolve( $prospect, $payload, $form );
            if ( AdapterRegistry::ID_MANUAL !== $adapter->id() ) {
                $this->submissions->setStatus(
                    $id,
                    Submissions::STATUS_APPROVED,
                    [
                        'method'       => $this->adapters->methodFor( $adapter ),
                        'adapter'      => $adapter->id(),
                        'reviewed_by'  => $userId > 0 ? $userId : null,
                        'scheduled_at' => Repository::now(),
                        'last_error'   => '',
                        'response'     => array_merge( is_array( $sub['response'] ?? null ) ? $sub['response'] : [], [ 'policy' => $this->policyRecord( $policy ) ] ),
                    ]
                );
                $this->audit->record( $this->actor( $userId ), 'submission.approved', 'submission', $id, [ 'prospect_id' => (int) $prospect['id'], 'adapter' => $adapter->id() ] );
                return true;
            }
            $policy['reasons'][] = 'No automated channel (listing form or partner API) is available for this target.';
        }

        $this->submissions->setStatus(
            $id,
            Submissions::STATUS_NEEDS_MANUAL,
            [
                'method'      => 'manual',
                'adapter'     => AdapterRegistry::ID_MANUAL,
                'reviewed_by' => $userId > 0 ? $userId : null,
                'last_error'  => implode( ' ', $policy['reasons'] ),
                'response'    => array_merge( is_array( $sub['response'] ?? null ) ? $sub['response'] : [], [ 'policy' => $this->policyRecord( $policy ) ] ),
            ]
        );
        $this->notifier->notify( 'submission', 'warning', 'Submission cannot be automated', sprintf( 'Submission #%d to %s stays manual: %s', $id, (string) $prospect['domain'], implode( ' ', $policy['reasons'] ) ), [ 'submission_id' => $id, 'prospect_id' => (int) $prospect['id'] ] );
        $this->audit->record( $this->actor( $userId ), 'submission.approve_refused', 'submission', $id, [ 'prospect_id' => (int) $prospect['id'], 'reasons' => $policy['reasons'] ] );
        return false;
    }

    public function reject( int $id, int $userId, string $reason ): bool {
        $sub = $this->submissions->find( $id );
        if ( null === $sub || Submissions::STATUS_CONFIRMED === (string) $sub['status'] ) {
            return false;
        }
        $reason = trim( $reason );
        $this->submissions->setStatus(
            $id,
            Submissions::STATUS_REJECTED,
            [
                'reviewed_by'  => $userId > 0 ? $userId : null,
                'last_error'   => '' !== $reason ? 'Rejected: ' . mb_substr( $reason, 0, 1000 ) : 'Rejected by reviewer.',
                'scheduled_at' => null,
            ]
        );
        if ( ! empty( $sub['opportunity_id'] ) ) {
            $this->opportunities->setStatus( (int) $sub['opportunity_id'], Opportunities::STATUS_DISMISSED );
        }
        $this->audit->record( $this->actor( $userId ), 'submission.rejected', 'submission', $id, [ 'prospect_id' => (int) $sub['prospect_id'], 'reason' => $reason ] );
        return true;
    }

    /** Re-queue a failed (or rejected) submission when the policy allows automation again. */
    public function retry( int $id, int $userId ): bool {
        $sub = $this->submissions->find( $id );
        if ( null === $sub || ! in_array( (string) $sub['status'], [ Submissions::STATUS_FAILED, Submissions::STATUS_REJECTED ], true ) ) {
            return false;
        }
        $prospect = $this->prospects->find( (int) $sub['prospect_id'] );
        if ( null === $prospect ) {
            return false;
        }
        $payload = is_array( $sub['payload'] ?? null ) ? $sub['payload'] : [];
        $form    = $this->formFromPayload( $payload );
        $policy  = $this->policy->evaluate( $prospect, $payload, $form, (string) $sub['target_url'], $this->robotsFetcher() );
        $adapter = $this->adapters->resolve( $prospect, $payload, $form );

        if ( $policy['allowed'] && AdapterRegistry::ID_MANUAL !== $adapter->id() ) {
            $this->submissions->setStatus(
                $id,
                Submissions::STATUS_QUEUED,
                [
                    'method'       => $this->adapters->methodFor( $adapter ),
                    'adapter'      => $adapter->id(),
                    'reviewed_by'  => $userId > 0 ? $userId : null,
                    'attempts'     => 0,
                    'scheduled_at' => Repository::now(),
                    'last_error'   => '',
                ]
            );
            if ( ! empty( $sub['opportunity_id'] ) ) {
                $this->opportunities->setStatus( (int) $sub['opportunity_id'], Opportunities::STATUS_PACKAGED );
            }
            $this->audit->record( $this->actor( $userId ), 'submission.retried', 'submission', $id, [ 'prospect_id' => (int) $prospect['id'], 'adapter' => $adapter->id() ] );
            return true;
        }

        $reasons = $policy['reasons'];
        if ( $policy['allowed'] ) {
            $reasons[] = 'No automated channel (listing form or partner API) is available for this target.';
        }
        $this->submissions->setStatus(
            $id,
            Submissions::STATUS_NEEDS_MANUAL,
            [
                'method'      => 'manual',
                'adapter'     => AdapterRegistry::ID_MANUAL,
                'reviewed_by' => $userId > 0 ? $userId : null,
                'last_error'  => implode( ' ', $reasons ),
            ]
        );
        $this->notifier->notify( 'submission', 'warning', 'Submission cannot be automated', sprintf( 'Submission #%d to %s needs a person: %s', $id, (string) $prospect['domain'], implode( ' ', $reasons ) ), [ 'submission_id' => $id, 'prospect_id' => (int) $prospect['id'] ] );
        $this->audit->record( $this->actor( $userId ), 'submission.retry_refused', 'submission', $id, [ 'prospect_id' => (int) $prospect['id'], 'reasons' => $reasons ] );
        return false;
    }

    /** Rebuild the package (fresh form detection and copy); re-derives the status for unsent submissions. */
    public function rebuildPackage( int $id, int $userId ): bool {
        $sub = $this->submissions->find( $id );
        if ( null === $sub || in_array( (string) $sub['status'], [ Submissions::STATUS_SUBMITTED, Submissions::STATUS_CONFIRMED ], true ) ) {
            return false;
        }
        $prospect = $this->prospects->find( (int) $sub['prospect_id'] );
        if ( null === $prospect ) {
            return false;
        }
        try {
            $opp     = ! empty( $sub['opportunity_id'] ) ? $this->opportunities->find( (int) $sub['opportunity_id'] ) : null;
            $target  = (string) $sub['target_url'];
            $located = $this->locateForm( $prospect, $target );
            $form    = $located['form'];
            $payload = $this->builder->build( $prospect, $opp, $form );
            $policy  = $this->policy->evaluate( $prospect, $payload, $form, $target, $this->robotsFetcher() );
            $adapter = $this->adapters->resolve( $prospect, $payload, $form );

            $data = [
                'payload'    => $payload,
                'category'   => mb_substr( $this->categoryOf( $payload ), 0, 190 ),
                'response'   => array_merge( is_array( $sub['response'] ?? null ) ? $sub['response'] : [], [ 'policy' => $this->policyRecord( $policy ) ] ),
                'updated_at' => Repository::now(),
            ];
            $status = (string) $sub['status'];
            if ( in_array( $status, [ Submissions::STATUS_PENDING_REVIEW, Submissions::STATUS_NEEDS_MANUAL, Submissions::STATUS_FAILED, Submissions::STATUS_REJECTED ], true ) ) {
                $newStatus = $this->statusFor( $policy, $adapter );
                $auto      = Submissions::STATUS_QUEUED === $newStatus;
                $manual    = Submissions::STATUS_NEEDS_MANUAL === $newStatus;
                $data['status']       = $newStatus;
                $data['method']       = $manual ? 'manual' : $this->adapters->methodFor( $adapter );
                $data['adapter']      = $manual ? AdapterRegistry::ID_MANUAL : $adapter->id();
                $data['scheduled_at'] = $auto ? Repository::now() : null;
                $data['attempts']     = 0;
                $data['last_error']   = $manual ? implode( ' ', $policy['reasons'] ) : '';
                if ( ! empty( $sub['opportunity_id'] ) ) {
                    $this->opportunities->setStatus( (int) $sub['opportunity_id'], Opportunities::STATUS_PACKAGED );
                }
            }
            if ( $userId > 0 ) {
                $data['reviewed_by'] = $userId;
            }
            $this->submissions->update( $id, $data );
            $this->audit->record( $this->actor( $userId ), 'submission.rebuilt', 'submission', $id, [ 'prospect_id' => (int) $prospect['id'], 'status' => $data['status'] ?? $status, 'unmapped_required' => $payload['unmapped_required'], 'compliance_clean' => $payload['compliance']['clean'] ] );
            return true;
        } catch ( Throwable $e ) {
            $this->logger->error( 'submission', 'Rebuild failed', [ 'submission_id' => $id, 'error' => $e->getMessage() ] );
            return false;
        }
    }

    /** Record that a person submitted the listing by hand; verification starts immediately. */
    public function markSubmittedManually( int $id, int $userId, string $listingUrl, string $confirmationRef, string $note = '' ): bool {
        $sub = $this->submissions->find( $id );
        if ( null === $sub || Submissions::STATUS_CONFIRMED === (string) $sub['status'] ) {
            return false;
        }
        $now      = Repository::now();
        $listing  = Url::normalize( trim( $listingUrl ) );
        $response = is_array( $sub['response'] ?? null ) ? $sub['response'] : [];
        $response['manual'] = [
            'by'   => $userId,
            'at'   => gmdate( 'c' ),
            'note' => mb_substr( trim( $note ), 0, 2000 ),
        ];
        $this->submissions->setStatus(
            $id,
            Submissions::STATUS_SUBMITTED,
            [
                'method'           => 'manual',
                'adapter'          => AdapterRegistry::ID_MANUAL,
                'submitted_at'     => $now,
                'next_verify_at'   => $now,
                'listing_url'      => mb_substr( $listing, 0, 700 ),
                'confirmation_ref' => mb_substr( trim( $confirmationRef ), 0, 190 ),
                'reviewed_by'      => $userId > 0 ? $userId : null,
                'last_error'       => '',
                'scheduled_at'     => null,
                'response'         => $response,
            ]
        );
        $prospect = $this->prospects->find( (int) $sub['prospect_id'] );
        if ( null !== $prospect ) {
            $this->afterSubmitted( $sub, $prospect );
        }
        $this->audit->record( $this->actor( $userId ), 'submission.marked_submitted', 'submission', $id, [ 'prospect_id' => (int) $sub['prospect_id'], 'listing_url' => $listing, 'confirmation_ref' => trim( $confirmationRef ), 'note' => mb_substr( trim( $note ), 0, 500 ) ] );
        return true;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Core packaging. Returns [submission id, created].
     *
     * @return array{0:int,1:bool}
     */
    private function package( int $opportunityId, int $userId ): array {
        $opp = $this->opportunities->find( $opportunityId );
        if ( null === $opp || in_array( (string) $opp['status'], [ Opportunities::STATUS_DISMISSED, Opportunities::STATUS_EXPIRED ], true ) ) {
            return [ 0, false ];
        }
        $prospect = $this->prospects->find( (int) $opp['prospect_id'] );
        if ( null === $prospect ) {
            return [ 0, false ];
        }
        if ( in_array( (string) $prospect['status'], [ Prospects::STATUS_REJECTED, Prospects::STATUS_SUPPRESSED ], true ) ) {
            $this->logger->debug( 'submission', 'Skipping package for rejected/suppressed prospect', [ 'prospect_id' => (int) $prospect['id'] ] );
            return [ 0, false ];
        }
        $pid    = (int) $prospect['id'];
        $target = $this->targetFor( $prospect, $opp );
        if ( '' === $target ) {
            return [ 0, false ];
        }

        $existing = $this->submissions->existsForTarget( $pid, $target );
        if ( null !== $existing ) {
            if ( Opportunities::STATUS_OPEN === (string) $opp['status'] ) {
                $this->opportunities->setStatus( $opportunityId, Opportunities::STATUS_PACKAGED );
            }
            return [ (int) $existing['id'], false ];
        }

        $located = $this->locateForm( $prospect, $target );
        if ( '' !== $located['page_url'] && Url::normalize( $located['page_url'] ) !== Url::normalize( $target ) ) {
            // The finder discovered the real submission page (e.g. the target was only the homepage).
            $target   = Url::normalize( $located['page_url'] ) ?: $target;
            $existing = $this->submissions->existsForTarget( $pid, $target );
            if ( null !== $existing ) {
                if ( Opportunities::STATUS_OPEN === (string) $opp['status'] ) {
                    $this->opportunities->setStatus( $opportunityId, Opportunities::STATUS_PACKAGED );
                }
                return [ (int) $existing['id'], false ];
            }
            if ( '' === (string) ( $prospect['submission_url'] ?? '' ) ) {
                $this->prospects->update( $pid, [ 'submission_url' => mb_substr( $target, 0, 700 ), 'updated_at' => Repository::now() ] );
                $prospect['submission_url'] = $target;
            }
        }

        $form    = $located['form'];
        $payload = $this->builder->build( $prospect, $opp, $form );
        $policy  = $this->policy->evaluate( $prospect, $payload, $form, $target, $this->robotsFetcher() );
        $adapter = $this->adapters->resolve( $prospect, $payload, $form );
        $status  = $this->statusFor( $policy, $adapter );
        $auto    = Submissions::STATUS_QUEUED === $status;
        $manual  = Submissions::STATUS_NEEDS_MANUAL === $status;

        $id = $this->submissions->create(
            $pid,
            $opportunityId,
            $target,
            [
                'method'       => $manual ? 'manual' : $this->adapters->methodFor( $adapter ),
                'adapter'      => $manual ? AdapterRegistry::ID_MANUAL : $adapter->id(),
                'status'       => $status,
                'category'     => mb_substr( $this->categoryOf( $payload ), 0, 190 ),
                'payload'      => $payload,
                'response'     => [ 'policy' => $this->policyRecord( $policy ) ],
                'attempts'     => 0,
                'scheduled_at' => $auto ? Repository::now() : null,
                'last_error'   => $manual ? implode( ' ', $policy['reasons'] ) : '',
                'reviewed_by'  => $userId > 0 ? $userId : null,
            ]
        );
        $this->opportunities->setStatus( $opportunityId, Opportunities::STATUS_PACKAGED );
        $this->audit->record(
            $this->actor( $userId ),
            'submission.packaged',
            'submission',
            $id,
            [
                'prospect_id'       => $pid,
                'opportunity_id'    => $opportunityId,
                'target'            => $target,
                'status'            => $status,
                'adapter'           => $manual ? AdapterRegistry::ID_MANUAL : $adapter->id(),
                'form_kind'         => $located['kind'],
                'reasons'           => $policy['reasons'],
                'unmapped_required' => $payload['unmapped_required'],
                'compliance_clean'  => $payload['compliance']['clean'],
            ]
        );
        $this->logger->info( 'submission', 'Package created', [ 'submission_id' => $id, 'domain' => (string) $prospect['domain'], 'status' => $status ] );
        return [ $id, true ];
    }

    /**
     * @param array<string,mixed> $prospect
     * @param array<string,mixed> $opp
     */
    private function targetFor( array $prospect, array $opp ): string {
        if ( in_array( (string) ( $opp['type'] ?? '' ), self::LISTING_TYPES, true ) ) {
            $u = Url::normalize( (string) ( $opp['url'] ?? '' ) );
            if ( '' !== $u ) {
                return $u;
            }
        }
        $profile = is_array( $prospect['profile'] ?? null ) ? $prospect['profile'] : [];
        foreach ( [ (string) ( $prospect['submission_url'] ?? '' ), (string) ( $profile['submission_url'] ?? '' ), (string) ( $prospect['homepage_url'] ?? '' ) ] as $candidate ) {
            $u = Url::normalize( $candidate );
            if ( '' !== $u ) {
                return $u;
            }
        }
        $domain = (string) ( $prospect['domain'] ?? '' );
        return '' !== $domain ? 'https://' . $domain . '/' : '';
    }

    /**
     * Find the form for the target page: the stored (complete) profile form, else a fresh crawl via
     * SubmissionPageFinder (which also probes common submission paths when only the homepage is known).
     *
     * @param array<string,mixed> $prospect
     * @return array{form:?array<string,mixed>,page_url:string,kind:string}
     */
    private function locateForm( array $prospect, string $target ): array {
        $profile    = is_array( $prospect['profile'] ?? null ) ? $prospect['profile'] : [];
        $stored     = is_array( $profile['submission_form'] ?? null ) ? $profile['submission_form'] : null;
        $profileUrl = Url::normalize( (string) ( ( $profile['submission_url'] ?? '' ) ?: ( $prospect['submission_url'] ?? '' ) ) );
        $targetN    = Url::normalize( $target );
        $domain     = (string) ( $prospect['domain'] ?? '' );
        $homepage   = Url::normalize( (string) ( $prospect['homepage_url'] ?? '' ) ) ?: ( '' !== $domain ? 'https://' . $domain . '/' : '' );
        $isHome     = '' !== $homepage && rtrim( $targetN, '/' ) === rtrim( $homepage, '/' );

        // 1. Stored form for this exact page with full option lists: no fetch needed.
        if ( null !== $stored && '' !== $profileUrl && $profileUrl === $targetN && self::formIsComplete( $stored ) ) {
            $stored['page_url'] = $profileUrl;
            return [ 'form' => $stored, 'page_url' => $profileUrl, 'kind' => (string) ( $stored['kind'] ?? '' ) ];
        }

        // 2. Fresh crawl through the finder.
        try {
            $home           = new PageAnalysis();
            $home->url      = $homepage ?: $targetN;
            $home->finalUrl = $home->url;
            $home->status   = 200;
            if ( ! $isHome ) {
                $home->links[] = [
                    'url'      => $targetN,
                    'host'     => Url::registrableDomain( $targetN ),
                    'anchor'   => 'Submit your listing',
                    'rel'      => [],
                    'nofollow' => false,
                    'external' => false,
                    'region'   => 'content',
                    'hidden'   => false,
                ];
            }
            $found = $this->finder->find( $home, $isHome ? 3 : 1, $isHome );
            $url   = (string) ( $found['url'] ?? '' );
            if ( '' !== $url && is_array( $found['form'] ?? null ) ) {
                $form             = $found['form'];
                $form['page_url'] = $url;
                return [ 'form' => $form, 'page_url' => $url, 'kind' => (string) ( $found['kind'] ?? '' ) ];
            }
            if ( '' !== $url && ! $isHome && ( $found['page'] ?? null ) instanceof PageAnalysis ) {
                return [ 'form' => null, 'page_url' => $url, 'kind' => 'page' ];
            }
        } catch ( Throwable $e ) {
            $this->logger->warning( 'submission', 'Form detection failed', [ 'domain' => $domain, 'target' => $targetN, 'error' => $e->getMessage() ] );
        }

        // 3. Fallback: the compact stored form (the adapter re-matches select options live).
        if ( null !== $stored && '' !== $profileUrl && $profileUrl === $targetN ) {
            $stored['page_url'] = $profileUrl;
            return [ 'form' => $stored, 'page_url' => $profileUrl, 'kind' => (string) ( $stored['kind'] ?? '' ) ];
        }
        return [ 'form' => null, 'page_url' => '', 'kind' => '' ];
    }

    /** True when every select field carries its option list (not just a count). @param array<string,mixed> $form */
    private static function formIsComplete( array $form ): bool {
        $fields = $form['fields'] ?? null;
        if ( ! is_array( $fields ) || [] === $fields ) {
            return false;
        }
        foreach ( $fields as $f ) {
            if ( is_array( $f ) && 'select' === strtolower( (string) ( $f['type'] ?? '' ) ) && ! is_array( $f['options'] ?? null ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Rebuild a Policy-compatible form array from the stored package.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function formFromPayload( array $payload ): ?array {
        $f = $payload['form'] ?? null;
        if ( ! is_array( $f ) || [] === $f ) {
            return null;
        }
        $fields = [];
        foreach ( (array) ( $payload['fields'] ?? [] ) as $field ) {
            if ( is_array( $field ) ) {
                $fields[] = $field;
            }
        }
        return [
            'page_url'     => (string) ( $f['page_url'] ?? '' ),
            'action'       => (string) ( $f['action'] ?? '' ),
            'method'       => (string) ( $f['method'] ?? '' ),
            'id'           => (string) ( $f['id'] ?? '' ),
            'kind'         => (string) ( $f['kind'] ?? '' ),
            'enctype'      => (string) ( $f['enctype'] ?? '' ),
            'has_captcha'  => ! empty( $f['has_captcha'] ),
            'has_password' => ! empty( $f['has_password'] ),
            'has_file'     => ! empty( $f['has_file'] ),
            'fields'       => $fields,
        ];
    }

    /**
     * queued when the policy allows automation, pending_review when only authorization/limits block an
     * otherwise automatable form, needs_manual for structural blockers.
     *
     * @param array{allowed:bool,mode:string,reasons:string[],hard:bool} $policy
     */
    private function statusFor( array $policy, AdapterInterface $adapter ): string {
        if ( AdapterRegistry::ID_MANUAL === $adapter->id() ) {
            return Submissions::STATUS_NEEDS_MANUAL;
        }
        if ( $policy['allowed'] ) {
            return Submissions::STATUS_QUEUED;
        }
        return empty( $policy['hard'] ) ? Submissions::STATUS_PENDING_REVIEW : Submissions::STATUS_NEEDS_MANUAL;
    }

    /**
     * @param array<string,mixed> $sub
     * @param array<string,mixed> $response
     */
    private function toManual( array $sub, string $why, array $response, int $attempts ): void {
        $merged = is_array( $sub['response'] ?? null ) ? $sub['response'] : [];
        $merged = array_merge( $merged, $response );
        $data   = [
            'method'       => 'manual',
            'adapter'      => AdapterRegistry::ID_MANUAL,
            'last_error'   => mb_substr( $why, 0, 2000 ),
            'response'     => $merged,
            'scheduled_at' => null,
        ];
        if ( $attempts > 0 ) {
            $data['attempts'] = $attempts;
        }
        $this->submissions->setStatus( (int) $sub['id'], Submissions::STATUS_NEEDS_MANUAL, $data );
        $this->audit->record( AuditLog::currentActor(), 'submission.needs_manual', 'submission', (int) $sub['id'], [ 'prospect_id' => (int) $sub['prospect_id'], 'reason' => $why ] );
    }

    /**
     * @param array<string,mixed> $sub
     * @param array<string,mixed> $prospect
     */
    private function afterSubmitted( array $sub, array $prospect ): void {
        if ( ! empty( $sub['opportunity_id'] ) ) {
            $this->opportunities->setStatus( (int) $sub['opportunity_id'], Opportunities::STATUS_SUBMITTED );
        }
        if ( in_array( (string) $prospect['status'], [ Prospects::STATUS_QUALIFIED, Prospects::STATUS_APPROVED ], true ) ) {
            $this->prospects->setStatus( (int) $prospect['id'], Prospects::STATUS_IN_PROGRESS );
        }
    }

    /** @param array<string,mixed> $payload */
    private function categoryOf( array $payload ): string {
        foreach ( (array) ( $payload['fields'] ?? [] ) as $f ) {
            if ( is_array( $f ) && 'category' === ( $f['semantic'] ?? '' ) ) {
                if ( is_array( $f['matched_option'] ?? null ) && '' !== (string) ( $f['matched_option']['text'] ?? '' ) ) {
                    return (string) $f['matched_option']['text'];
                }
                if ( '' !== (string) ( $f['value'] ?? '' ) ) {
                    return (string) $f['value'];
                }
            }
        }
        return (string) ( $payload['content']['category_suggestions'][0] ?? '' );
    }

    /**
     * @param array{allowed:bool,mode:string,reasons:string[],hard:bool} $policy
     * @return array<string,mixed>
     */
    private function policyRecord( array $policy ): array {
        return [
            'allowed'      => (bool) $policy['allowed'],
            'mode'         => (string) $policy['mode'],
            'reasons'      => array_values( $policy['reasons'] ),
            'hard'         => ! empty( $policy['hard'] ),
            'evaluated_at' => gmdate( 'c' ),
        ];
    }

    private function actor( int $userId ): string {
        return $userId > 0 ? 'user:' . $userId : AuditLog::currentActor();
    }

    private function robotsFetcher(): ?callable {
        try {
            return $this->adapters->robotsFetcher();
        } catch ( Throwable $e ) {
            $this->logger->warning( 'submission', 'robots.txt fetcher unavailable; robots check skipped', [ 'error' => $e->getMessage() ] );
            return null;
        }
    }

    private function verifier(): ListingVerifier {
        if ( null === $this->verifier ) {
            $this->verifier = $this->adapters->verifier();
        }
        return $this->verifier;
    }
}
