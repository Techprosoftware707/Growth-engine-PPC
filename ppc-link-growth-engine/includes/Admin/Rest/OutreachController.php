<?php
/**
 * /outreach — campaigns, messages, contacts, approaches and sender readiness.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Admin\Rest;

use PPC\LinkGrowth\Automation\JobRunner;
use PPC\LinkGrowth\Outreach\Compliance;
use PPC\LinkGrowth\Outreach\OutreachEngine;
use PPC\LinkGrowth\Outreach\Templates;
use PPC\LinkGrowth\Repositories\Campaigns;
use PPC\LinkGrowth\Repositories\Contacts;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Repository;
use PPC\LinkGrowth\Repositories\Suppression;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use WP_REST_Request;

final class OutreachController extends Controller {

    private const CAMPAIGN_ACTIONS = [
        'submit'  => 'submitCampaignForApproval',
        'approve' => 'approveCampaign',
        'pause'   => 'pauseCampaign',
        'resume'  => 'resumeCampaign',
        'archive' => 'archiveCampaign',
    ];

    private const MESSAGE_ACTIONS = [ 'approve', 'reject', 'send', 'log-reply', 'close' ];

    private const CONTACT_STATUSES = [
        Contacts::STATUS_ACTIVE,
        Contacts::STATUS_OPTED_OUT,
        Contacts::STATUS_BOUNCED,
        Contacts::STATUS_SUPPRESSED,
        Contacts::STATUS_INVALID,
    ];

    /** Suppression reason prefix written when a reviewer changes a contact's status. */
    private const REVIEWER_REASON_PREFIX = 'Set by reviewer (';

    /**
     * Seconds a POST /outreach/campaigns/{id}/drafts request may spend drafting before it returns the rest
     * as `remaining` (well under Cloudflare's 100 s proxy timeout; AI personalisation is one call per prospect).
     */
    private const DRAFT_SECONDS = 20.0;

    /** Prospects drafted per batch between time-budget checks (AI drafting is checked after every prospect). */
    private const DRAFT_BATCH = 25;

    /** Seconds the job-runner lock is held for one "Send now" (released as soon as the send returns). */
    private const SEND_LOCK_SECONDS = 120;

    public function register( string $namespace ): void {
        $this->route( $namespace, '/outreach/campaigns', 'GET', [ $this, 'campaigns' ], $this->listArgs( [ 'status', 'approach' ] ) );
        $this->route( $namespace, '/outreach/campaigns', 'POST', [ $this, 'createCampaign' ] );
        $this->route( $namespace, '/outreach/campaigns/(?P<id>\d+)', 'GET', [ $this, 'campaign' ], $this->idArgs() );
        $this->route(
            $namespace,
            '/outreach/campaigns/(?P<id>\d+)/(?P<action>submit|approve|pause|resume|archive)',
            'POST',
            [ $this, 'campaignAction' ],
            $this->idArgs() + [ 'action' => [ 'type' => 'string', 'required' => true, 'enum' => array_keys( self::CAMPAIGN_ACTIONS ) ] ]
        );
        $this->route( $namespace, '/outreach/campaigns/(?P<id>\d+)/drafts', 'POST', [ $this, 'drafts' ], $this->idArgs() );

        $this->route( $namespace, '/outreach/messages', 'GET', [ $this, 'messages' ], $this->listArgs( [ 'status', 'campaign_id', 'prospect_id', 'approach', 'sequence' ] ) );
        $this->route( $namespace, '/outreach/messages/(?P<id>\d+)', 'GET', [ $this, 'message' ], $this->idArgs() );
        $this->route( $namespace, '/outreach/messages/(?P<id>\d+)', 'POST', [ $this, 'updateMessage' ], $this->idArgs() );
        $this->route(
            $namespace,
            '/outreach/messages/(?P<id>\d+)/(?P<action>approve|reject|send|log-reply|close)',
            'POST',
            [ $this, 'messageAction' ],
            $this->idArgs() + [ 'action' => [ 'type' => 'string', 'required' => true, 'enum' => self::MESSAGE_ACTIONS ] ]
        );

        $this->route( $namespace, '/outreach/contacts', 'GET', [ $this, 'contacts' ], $this->listArgs( [ 'prospect_id', 'status' ] ) );
        $this->route( $namespace, '/outreach/contacts/(?P<id>\d+)/status', 'POST', [ $this, 'contactStatus' ], $this->idArgs() );
        $this->route( $namespace, '/outreach/approaches', 'GET', [ $this, 'approaches' ] );
        $this->route( $namespace, '/outreach/sender', 'GET', [ $this, 'sender' ] );
    }

    // ------------------------------------------------------------------
    // Campaigns
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    public function campaigns( WP_REST_Request $request ): array {
        $pg    = $this->paginationArgs( $request, 'id', 'DESC' );
        $where = [];
        $status = $this->keyList( $request, 'status' );
        if ( [] !== $status ) {
            $where['status'] = count( $status ) > 1 ? $status : $status[0];
        }
        $approach = sanitize_key( $this->str( $request, 'approach', '', 40 ) );
        if ( '' !== $approach ) {
            $where['approach'] = $approach;
        }
        $repo     = $this->c->get( Campaigns::class );
        $messages = $this->c->get( OutreachMessages::class );
        $page     = $repo->paginate( $where, $pg['q'], [ 'name' ], $pg['page'], $pg['per_page'], $pg['orderby'], $pg['order'] );
        $items    = [];
        foreach ( $page['items'] as $c ) {
            $items[] = $this->decorateCampaign( $c, $messages );
        }
        $page['items']  = $items;
        $page['facets'] = $this->safe( static fn(): array => [ 'status' => $repo->countBy( 'status' ) ], [], 'campaign facets' );
        return $page;
    }

    /** POST /outreach/campaigns {name, approach, settings{daily_limit, follow_ups, auto_follow_up, notes}} @return array<string,mixed>|\WP_Error */
    public function createCampaign( WP_REST_Request $request ) {
        $approach = sanitize_key( $this->str( $request, 'approach', '', 40 ) );
        if ( ! Templates::isApproach( $approach ) ) {
            return $this->fail( 'ppclg_invalid_approach', __( 'Unknown outreach approach.', 'ppc-link-growth-engine' ), 400 );
        }
        $raw      = $this->arr( $request, 'settings' );
        $settings = [
            'daily_limit'    => max( 1, min( 200, (int) ( $raw['daily_limit'] ?? 10 ) ) ),
            'follow_ups'     => filter_var( $raw['follow_ups'] ?? true, FILTER_VALIDATE_BOOLEAN ),
            'auto_follow_up' => filter_var( $raw['auto_follow_up'] ?? false, FILTER_VALIDATE_BOOLEAN ),
            'notes'          => mb_substr( sanitize_textarea_field( (string) ( is_scalar( $raw['notes'] ?? null ) ? $raw['notes'] : '' ) ), 0, 2000 ),
        ];
        $id = $this->c->get( OutreachEngine::class )->createCampaign( $this->str( $request, 'name', '', 190 ), $approach, $settings, $this->userId() );
        if ( $id <= 0 ) {
            return $this->fail( 'ppclg_campaign_failed', __( 'The campaign could not be created.', 'ppc-link-growth-engine' ), 422 );
        }
        return [ 'ok' => true, 'campaign_id' => $id, 'campaign' => $this->campaignDetail( $id ) ];
    }

    /** @return array<string,mixed>|\WP_Error */
    public function campaign( WP_REST_Request $request ) {
        $detail = $this->campaignDetail( $this->id( $request ) );
        if ( null === $detail ) {
            return $this->notFound( __( 'Campaign', 'ppc-link-growth-engine' ) );
        }
        return [ 'campaign' => $detail ];
    }

    /** POST /outreach/campaigns/{id}/{action} @return array<string,mixed>|\WP_Error */
    public function campaignAction( WP_REST_Request $request ) {
        $id     = $this->id( $request );
        $action = sanitize_key( (string) $request->get_param( 'action' ) );
        if ( ! isset( self::CAMPAIGN_ACTIONS[ $action ] ) ) {
            return $this->fail( 'ppclg_invalid_action', __( 'Unknown campaign action.', 'ppc-link-growth-engine' ), 400 );
        }
        if ( null === $this->c->get( Campaigns::class )->find( $id ) ) {
            return $this->notFound( __( 'Campaign', 'ppc-link-growth-engine' ) );
        }
        $engine = $this->c->get( OutreachEngine::class );
        $method = self::CAMPAIGN_ACTIONS[ $action ];
        $ok     = (bool) $engine->{$method}( $id, $this->userId() );
        if ( $ok ) {
            return [ 'ok' => true, 'action' => $action, 'campaign' => $this->campaignDetail( $id ) ];
        }
        // A refused action is an error response (409), so the admin UI shows the reason instead of a success toast.
        $data   = [ 'ok' => false, 'action' => $action, 'campaign' => $this->campaignDetail( $id ) ];
        $sender = null;
        if ( in_array( $action, [ 'approve', 'resume' ], true ) ) {
            $sender         = $this->safe( fn(): array => $this->c->get( Compliance::class )->senderReady(), [], 'sender readiness' );
            $data['sender'] = $sender;
        }
        $senderMissing = is_array( $sender ) && false === ( $sender['ok'] ?? true );
        if ( 'approve' === $action ) {
            $message = __( 'The campaign could not be approved. Complete the sender identity (Settings → Outreach) and make sure the campaign is pending approval.', 'ppc-link-growth-engine' );
        } elseif ( 'resume' === $action && $senderMissing ) {
            $message = __( 'The campaign could not be resumed: the sender identity is incomplete. Complete it under Settings → Outreach first.', 'ppc-link-growth-engine' );
        } else {
            $message = __( 'The action is not available for the current campaign status.', 'ppc-link-growth-engine' );
        }
        if ( $senderMissing && is_array( $sender['missing'] ?? null ) && [] !== $sender['missing'] ) {
            $message .= ' ' . sprintf(
                /* translators: %s: comma-separated list of missing sender fields */
                __( 'Missing: %s.', 'ppc-link-growth-engine' ),
                implode( ', ', array_map( 'strval', $sender['missing'] ) )
            );
        }
        $data['message'] = $message;
        return $this->fail( 'ppclg_campaign_action_failed', $message, 409, $data );
    }

    /** POST /outreach/campaigns/{id}/drafts {prospect_ids[], use_ai} @return array<string,mixed>|\WP_Error */
    public function drafts( WP_REST_Request $request ) {
        $id = $this->id( $request );
        if ( null === $this->c->get( Campaigns::class )->find( $id ) ) {
            return $this->notFound( __( 'Campaign', 'ppc-link-growth-engine' ) );
        }
        $ids = $this->intList( $request, 'prospect_ids', 500 );
        if ( [] === $ids ) {
            return $this->fail( 'ppclg_missing_prospects', __( 'Select at least one prospect.', 'ppc-link-growth-engine' ), 400 );
        }
        $useAi = $this->bool( $request, 'use_ai', false );
        /**
         * Filters the seconds one draft-building request may spend before returning the rest as `remaining`.
         *
         * @param float $seconds
         */
        $budget    = TimeBudget::seconds( max( 0.0, (float) apply_filters( 'ppclg_rest_drafts_seconds', self::DRAFT_SECONDS ) ) );
        $engine    = $this->c->get( OutreachEngine::class );
        $created   = 0;
        $skipped   = [];
        $remaining = [];
        $chunks    = array_chunk( $ids, $useAi ? 1 : self::DRAFT_BATCH );
        foreach ( $chunks as $i => $chunk ) {
            // Always make progress on the first batch; afterwards stop before the proxy/PHP time limit.
            if ( $i > 0 && $budget->expired() ) {
                foreach ( array_slice( $chunks, $i ) as $rest ) {
                    foreach ( $rest as $pid ) {
                        $remaining[] = (int) $pid;
                    }
                }
                break;
            }
            // Interactive: AI calls use the shorter ClaudeWriter::INTERACTIVE_TIMEOUT so one hung call cannot push
            // the request past the proxy limit.
            $result   = $engine->buildDrafts( $id, $chunk, $this->userId(), $useAi, true );
            $created += (int) ( $result['created'] ?? 0 );
            foreach ( is_array( $result['skipped'] ?? null ) ? $result['skipped'] : [] as $pid => $reason ) {
                $skipped[ $pid ] = $reason;
            }
        }
        foreach ( $remaining as $pid ) {
            $skipped[ $pid ] = __( 'Not processed yet: the request time limit was reached. Build drafts again for this prospect to continue.', 'ppc-link-growth-engine' );
        }
        return [
            'ok'        => true,
            'created'   => $created,
            'skipped'   => $skipped,
            'remaining' => $remaining,
            'partial'   => [] !== $remaining,
            'campaign'  => $this->campaignDetail( $id ),
        ];
    }

    // ------------------------------------------------------------------
    // Messages
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    public function messages( WP_REST_Request $request ): array {
        $pg    = $this->paginationArgs( $request, 'id', 'DESC' );
        $where = [];
        $status = $this->keyList( $request, 'status' );
        if ( [] !== $status ) {
            $where['status'] = count( $status ) > 1 ? $status : $status[0];
        }
        foreach ( [ 'campaign_id', 'prospect_id', 'sequence' ] as $key ) {
            $v = $this->int( $request, $key, -1 );
            if ( $v >= 0 && ( 'sequence' === $key ? $v > 0 : true ) ) {
                $where[ $key ] = $v;
            }
        }
        $approach = sanitize_key( $this->str( $request, 'approach', '', 40 ) );
        if ( '' !== $approach ) {
            $where['approach'] = $approach;
        }
        $repo  = $this->c->get( OutreachMessages::class );
        $page  = $repo->paginate( $where, $pg['q'], [ 'subject' ], $pg['page'], $pg['per_page'], $pg['orderby'], $pg['order'] );
        $items = $this->attachProspects( array_map( static fn( array $m ): array => self::castInts( $m, [ 'campaign_id', 'prospect_id', 'contact_id', 'opportunity_id', 'sequence' ] ), $page['items'] ) );

        $contactIds = array_values( array_unique( array_filter( array_map( static fn( array $m ): int => (int) ( $m['contact_id'] ?? 0 ), $items ) ) ) );
        $contacts   = [];
        if ( [] !== $contactIds ) {
            foreach ( $this->safe( fn(): array => $this->c->get( Contacts::class )->findBy( [ 'id' => $contactIds ], 'id', 'ASC', count( $contactIds ) ), [], 'message contacts' ) as $c ) {
                $contacts[ (int) $c['id'] ] = $c;
            }
        }
        foreach ( $items as &$m ) {
            $c                    = $contacts[ (int) ( $m['contact_id'] ?? 0 ) ] ?? null;
            $m['contact_email']   = (string) ( $c['email'] ?? '' );
            $m['contact_name']    = (string) ( $c['name'] ?? '' );
            $m['contact_status']  = (string) ( $c['status'] ?? '' );
            $m['approach_label']  = Templates::label( (string) ( $m['approach'] ?? '' ) );
            $m['body_excerpt']    = mb_substr( preg_replace( '/\s+/', ' ', (string) ( $m['body'] ?? '' ) ) ?? '', 0, 160 );
            unset( $m['body'] );
        }
        unset( $m );
        $page['items']  = $items;
        $page['facets'] = $this->safe( static fn(): array => [ 'status' => $repo->statusCounts() ], [], 'message facets' );
        return $page;
    }

    /** @return array<string,mixed>|\WP_Error */
    public function message( WP_REST_Request $request ) {
        $id = $this->id( $request );
        $m  = $this->c->get( OutreachMessages::class )->find( $id );
        if ( null === $m ) {
            return $this->notFound( __( 'Message', 'ppc-link-growth-engine' ) );
        }
        $detail = $this->messageDetail( $m );
        return [
            'message'  => $detail,
            'prospect' => $detail['prospect'],
            'contact'  => $detail['contact'],
            'campaign' => $detail['campaign'],
            'preview'  => $detail['preview'],
        ];
    }

    /** POST /outreach/messages/{id} {subject, body} @return array<string,mixed>|\WP_Error */
    public function updateMessage( WP_REST_Request $request ) {
        $id   = $this->id( $request );
        $repo = $this->c->get( OutreachMessages::class );
        if ( null === $repo->find( $id ) ) {
            return $this->notFound( __( 'Message', 'ppc-link-growth-engine' ) );
        }
        // Read raw: OutreachEngine::updateMessage() sanitises subject and body itself (tags stripped, UTF-8
        // checked). sanitize_text(area)_field() would also delete %XX octets and corrupt quoted URLs.
        $subject = $this->rawText( $request, 'subject', '', 255, false );
        $body    = $this->rawText( $request, 'body', '', 20000 );
        if ( '' === $subject || '' === $body ) {
            return $this->fail( 'ppclg_missing_fields', __( 'Subject and body are required.', 'ppc-link-growth-engine' ), 400 );
        }
        $result = $this->c->get( OutreachEngine::class )->updateMessage( $id, $subject, $body, $this->userId() );
        $fresh  = $repo->find( $id );
        return [
            'ok'         => (bool) ( $result['ok'] ?? false ),
            'violations' => is_array( $result['violations'] ?? null ) ? $result['violations'] : [],
            'message'    => $fresh ? $this->messageDetail( $fresh ) : null,
        ];
    }

    /** POST /outreach/messages/{id}/{action} @return array<string,mixed>|\WP_Error */
    public function messageAction( WP_REST_Request $request ) {
        $id     = $this->id( $request );
        $action = sanitize_text_field( (string) $request->get_param( 'action' ) );
        $repo   = $this->c->get( OutreachMessages::class );
        if ( null === $repo->find( $id ) ) {
            return $this->notFound( __( 'Message', 'ppc-link-growth-engine' ) );
        }
        if ( ! in_array( $action, self::MESSAGE_ACTIONS, true ) ) {
            return $this->fail( 'ppclg_invalid_action', __( 'Unknown message action.', 'ppc-link-growth-engine' ), 400 );
        }
        $engine = $this->c->get( OutreachEngine::class );
        $user   = $this->userId();
        $out    = [ 'ok' => false, 'action' => $action, 'violations' => [], 'error' => '' ];

        switch ( $action ) {
            case 'approve':
                $r                 = $engine->approveMessage( $id, $user );
                $out['ok']         = (bool) ( $r['ok'] ?? false );
                $out['violations'] = is_array( $r['violations'] ?? null ) ? $r['violations'] : [];
                $out['message_text'] = $out['ok'] ? __( 'Message approved.', 'ppc-link-growth-engine' ) : __( 'The message did not pass the compliance checks.', 'ppc-link-growth-engine' );
                break;
            case 'reject':
                $out['ok']           = $engine->rejectMessage( $id, $user, $this->str( $request, 'reason', '', 500 ) );
                $out['message_text'] = $out['ok'] ? __( 'Message rejected.', 'ppc-link-growth-engine' ) : __( 'The message could not be rejected.', 'ppc-link-growth-engine' );
                break;
            case 'send':
                // Same lock as the automation runner (Pipeline::runNow), so "Send now" never races a send batch.
                $runner = $this->c->get( JobRunner::class );
                if ( ! $runner->lock( self::SEND_LOCK_SECONDS ) ) {
                    return $this->fail(
                        'ppclg_runner_busy',
                        __( 'The automation runner is busy (it may be sending outreach right now), so this message was not sent. Try again in a minute.', 'ppc-link-growth-engine' ),
                        409,
                        [ 'ok' => false, 'action' => $action ]
                    );
                }
                try {
                    $r = $engine->sendNow( $id, $user );
                } finally {
                    $runner->unlock();
                }
                $out['ok']           = (bool) ( $r['ok'] ?? false );
                $out['error']        = (string) ( $r['error'] ?? '' );
                $out['message_text'] = $out['ok'] ? __( 'Message sent.', 'ppc-link-growth-engine' ) : ( '' !== $out['error'] ? $out['error'] : __( 'The message could not be sent.', 'ppc-link-growth-engine' ) );
                break;
            case 'log-reply':
                $summary = $this->text( $request, 'summary', '', 1000 );
                if ( '' === $summary ) {
                    return $this->fail( 'ppclg_missing_summary', __( 'A reply summary is required.', 'ppc-link-growth-engine' ), 400 );
                }
                $out['ok']           = $engine->logReply( $id, $summary, $user );
                $out['message_text'] = $out['ok'] ? __( 'Reply logged.', 'ppc-link-growth-engine' ) : __( 'The reply could not be logged.', 'ppc-link-growth-engine' );
                break;
            case 'close':
                $out['ok']           = $engine->closeMessage( $id, $user );
                $out['message_text'] = $out['ok'] ? __( 'Conversation closed.', 'ppc-link-growth-engine' ) : __( 'The message could not be closed.', 'ppc-link-growth-engine' );
                break;
        }

        $fresh          = $repo->find( $id );
        $out['message'] = $fresh ? $this->messageDetail( $fresh ) : null;
        return $out;
    }

    // ------------------------------------------------------------------
    // Contacts, approaches, sender
    // ------------------------------------------------------------------

    /** GET /outreach/contacts?prospect_id= @return array<string,mixed> */
    public function contacts( WP_REST_Request $request ): array {
        $repo       = $this->c->get( Contacts::class );
        $prospectId = $this->int( $request, 'prospect_id', 0 );
        if ( $prospectId > 0 ) {
            $items = array_map( static fn( array $c ): array => self::castInts( $c, [ 'prospect_id', 'confidence' ] ), $repo->forProspect( $prospectId ) );
            return [ 'items' => $items, 'total' => count( $items ), 'page' => 1, 'per_page' => count( $items ), 'pages' => 1 ];
        }
        $pg    = $this->paginationArgs( $request, 'id', 'DESC' );
        $where = [];
        $status = $this->keyList( $request, 'status' );
        if ( [] !== $status ) {
            $where['status'] = count( $status ) > 1 ? $status : $status[0];
        }
        $page          = $repo->paginate( $where, $pg['q'], [ 'email', 'name', 'role' ], $pg['page'], $pg['per_page'], $pg['orderby'], $pg['order'] );
        $page['items'] = $this->attachProspects( array_map( static fn( array $c ): array => self::castInts( $c, [ 'prospect_id', 'confidence' ] ), $page['items'] ) );
        return $page;
    }

    /** POST /outreach/contacts/{id}/status {status} @return array<string,mixed>|\WP_Error */
    public function contactStatus( WP_REST_Request $request ) {
        $id   = $this->id( $request );
        $repo = $this->c->get( Contacts::class );
        $c    = $repo->find( $id );
        if ( null === $c ) {
            return $this->notFound( __( 'Contact', 'ppc-link-growth-engine' ) );
        }
        $status = sanitize_key( $this->str( $request, 'status', '', 20 ) );
        if ( ! in_array( $status, self::CONTACT_STATUSES, true ) ) {
            return $this->fail( 'ppclg_invalid_status', __( 'Unknown contact status.', 'ppc-link-growth-engine' ), 400 );
        }
        $email = (string) ( $c['email'] ?? '' );
        if ( Contacts::STATUS_ACTIVE === $status && '' !== $email ) {
            // Opt-outs are honoured permanently (COMPLIANCE.md): never lift one from the status dropdown.
            $blocker = $this->reactivationBlocker( $c, $email );
            if ( null !== $blocker ) {
                return $this->fail(
                    'ppclg_contact_opted_out',
                    sprintf(
                        /* translators: 1: email address, 2: suppression reason */
                        __( '%1$s opted out or is on the suppression list (%2$s). Opt-outs are permanent, so the contact cannot be reactivated here. Only if the recipient has explicitly asked to be contacted again, remove the entry under Logs → Suppression list first (the removal is audited).', 'ppc-link-growth-engine' ),
                        $email,
                        '' !== (string) ( $blocker['reason'] ?? '' ) ? (string) $blocker['reason'] : __( 'no reason recorded', 'ppc-link-growth-engine' )
                    ),
                    409,
                    [ 'suppression' => [ 'id' => (int) ( $blocker['id'] ?? 0 ), 'reason' => (string) ( $blocker['reason'] ?? '' ) ] ]
                );
            }
        }
        $repo->update( $id, [ 'status' => $status, 'updated_at' => Repository::now() ] );
        if ( '' !== $email ) {
            $suppression = $this->c->get( Suppression::class );
            if ( in_array( $status, [ Contacts::STATUS_OPTED_OUT, Contacts::STATUS_SUPPRESSED ], true ) ) {
                $this->safe( static fn(): bool => $suppression->add( 'email', $email, self::REVIEWER_REASON_PREFIX . $status . ')' ), false, 'suppress email' );
            } elseif ( Contacts::STATUS_ACTIVE === $status ) {
                // Only reached when reactivationBlocker() found nothing or just the reviewer's own "suppressed" marker.
                $this->safe( static function () use ( $suppression, $email ): void { $suppression->remove( 'email', $email ); }, null, 'unsuppress email' );
            }
        }
        $this->audit( 'contact.status_changed', 'contact', $id, [ 'from' => (string) $c['status'], 'to' => $status, 'email' => $email, 'prospect_id' => (int) ( $c['prospect_id'] ?? 0 ) ] );
        $fresh = $repo->find( $id );
        return [ 'ok' => true, 'contact' => $fresh ? self::castInts( $fresh, [ 'prospect_id', 'confidence' ] ) : null ];
    }

    /** GET /outreach/approaches @return array<string,mixed> */
    public function approaches( WP_REST_Request $request ): array {
        unset( $request );
        $variants = [];
        foreach ( array_keys( Templates::approaches() ) as $approach ) {
            $variants[ $approach ] = Templates::variants( $approach );
        }
        return [
            'approaches'   => Templates::approaches(),
            'descriptions' => Templates::descriptions(),
            'variants'     => $variants,
            'placeholders' => Templates::placeholders(),
            'follow_up'    => Templates::followUp(),
        ];
    }

    /** GET /outreach/sender @return array<string,mixed> */
    public function sender( WP_REST_Request $request ): array {
        unset( $request );
        $settings   = $this->c->get( Settings::class );
        $compliance = $this->c->get( Compliance::class );
        $ready      = $this->safe( static fn(): array => $compliance->senderReady(), [ 'ok' => false, 'missing' => [ 'unknown' ], 'warnings' => [] ], 'sender readiness' );
        return array_merge(
            $ready,
            [
                'sender'        => [
                    'name'               => (string) $settings->get( 'outreach.sender_name', '' ),
                    'email'              => (string) $settings->get( 'outreach.sender_email', '' ),
                    'reply_to'           => (string) $settings->get( 'outreach.reply_to', '' ),
                    'postal_address_set' => '' !== trim( (string) $settings->get( 'outreach.postal_address', '' ) ),
                    'require_approval'   => (bool) $settings->get( 'outreach.require_approval', true ),
                ],
                'send_window'   => [
                    'start'    => (int) $settings->get( 'outreach.send_window_start', 9 ),
                    'end'      => (int) $settings->get( 'outreach.send_window_end', 17 ),
                    'days'     => array_values( array_map( 'intval', (array) $settings->get( 'outreach.send_days', [ 1, 2, 3, 4, 5 ] ) ) ),
                    'timezone' => wp_timezone_string(),
                    'open_now' => (bool) $this->safe( static fn(): bool => $compliance->withinSendWindow(), false, 'send window' ),
                ],
                'autosend'      => (bool) $settings->get( 'automation.outreach_autosend', false ),
            ]
        );
    }

    // ------------------------------------------------------------------

    /**
     * The email suppression entry that must survive reactivating this contact, or null when reactivating is allowed.
     *
     * Only the reviewer's own "suppressed" marker (an internal do-not-contact decision) may be lifted from the
     * status dropdown, and only while nothing records an opt-out by the recipient (contact status or an
     * opted-out message for any contact with this address). Every other entry — unsubscribe link, opt-out reply,
     * reviewer-recorded opt-out, manual entry — is removed deliberately via DELETE /suppression/{id} (audited).
     * Fails closed: if the check itself fails, reactivation is refused.
     *
     * @param array<string,mixed> $contact
     * @return array<string,mixed>|null
     */
    private function reactivationBlocker( array $contact, string $email ): ?array {
        $fallback = [ 'id' => 0, 'reason' => __( 'the suppression list could not be checked', 'ppc-link-growth-engine' ) ];
        return $this->safe(
            function () use ( $contact, $email ): ?array {
                $normalized = Suppression::normalizeValue( 'email', $email );
                if ( '' === $normalized ) {
                    return null;
                }
                $entry = $this->c->get( Suppression::class )->findOneBy( [ 'type' => 'email', 'value' => $normalized ] );
                if ( null === $entry ) {
                    return null;
                }
                if ( self::REVIEWER_REASON_PREFIX . Contacts::STATUS_SUPPRESSED . ')' !== (string) ( $entry['reason'] ?? '' ) ) {
                    return $entry;
                }
                if ( Contacts::STATUS_OPTED_OUT === (string) ( $contact['status'] ?? '' ) ) {
                    return $entry;
                }
                $ids = [];
                foreach ( $this->c->get( Contacts::class )->findBy( [ 'email' => $normalized ], 'id', 'ASC', 200 ) as $row ) {
                    if ( Contacts::STATUS_OPTED_OUT === (string) ( $row['status'] ?? '' ) ) {
                        return $entry;
                    }
                    $ids[] = (int) $row['id'];
                }
                $ids[] = (int) ( $contact['id'] ?? 0 );
                $ids   = array_values( array_unique( array_filter( $ids ) ) );
                if ( [] !== $ids && $this->c->get( OutreachMessages::class )->count( [ 'contact_id' => $ids, 'status' => OutreachMessages::STATUS_OPTED_OUT ] ) > 0 ) {
                    return $entry;
                }
                return null;
            },
            $fallback,
            'opt-out check'
        );
    }

    /** @return array<string,mixed>|null */
    private function campaignDetail( int $id ): ?array {
        $c = $this->safe( fn(): ?array => $this->c->get( OutreachEngine::class )->campaign( $id ), null, 'campaign detail' );
        if ( null === $c ) {
            $row = $this->c->get( Campaigns::class )->find( $id );
            if ( null === $row ) {
                return null;
            }
            $c = $this->decorateCampaign( $row, $this->c->get( OutreachMessages::class ) );
        }
        $c = self::castInts( $c, [ 'daily_limit', 'created_by', 'approved_by', 'messages_total', 'sent_today' ] );
        if ( ! isset( $c['message_counts'] ) ) {
            $c['message_counts'] = is_array( $c['counts'] ?? null ) ? $c['counts'] : [];
        }
        return $c;
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function decorateCampaign( array $c, OutreachMessages $messages ): array {
        $id     = (int) $c['id'];
        $counts = $this->safe( static fn(): array => $messages->countBy( 'status', 'campaign_id = %d', [ $id ] ), [], 'campaign counts' );
        $c['counts']         = $counts;
        $c['message_counts'] = $counts;
        $c['messages_total'] = (int) array_sum( $counts );
        $c['approach_label'] = Templates::label( (string) ( $c['approach'] ?? '' ) );
        $c['sent_today']     = (int) $this->safe( static fn(): int => $messages->sentTodayForCampaign( $id ), 0, 'sent today' );
        return self::castInts( $c, [ 'daily_limit', 'created_by', 'approved_by' ] );
    }

    /** @param array<string,mixed> $m @return array<string,mixed> */
    private function messageDetail( array $m ): array {
        $m        = self::castInts( $m, [ 'campaign_id', 'prospect_id', 'contact_id', 'opportunity_id', 'sequence' ] );
        $id       = (int) $m['id'];
        $prospect = $this->safe( fn(): ?array => $this->c->get( Prospects::class )->find( (int) ( $m['prospect_id'] ?? 0 ) ), null, 'message prospect' );
        $contact  = $this->safe( fn(): ?array => $this->c->get( Contacts::class )->find( (int) ( $m['contact_id'] ?? 0 ) ), null, 'message contact' );
        $campaign = (int) ( $m['campaign_id'] ?? 0 ) > 0 ? $this->campaignDetail( (int) $m['campaign_id'] ) : null;
        $m['approach_label'] = Templates::label( (string) ( $m['approach'] ?? '' ) );
        $m['prospect']       = $prospect ? $this->slimProspect( $prospect, true ) : null;
        $m['contact']        = $contact ? self::castInts( $contact, [ 'prospect_id', 'confidence' ] ) : null;
        $m['campaign']       = $campaign;
        $m['preview']        = $this->safe( fn(): array => $this->c->get( OutreachEngine::class )->preview( $id ), [ 'subject' => (string) ( $m['subject'] ?? '' ), 'body' => (string) ( $m['body'] ?? '' ), 'to' => (string) ( $contact['email'] ?? '' ) ], 'message preview' );
        return $m;
    }
}
