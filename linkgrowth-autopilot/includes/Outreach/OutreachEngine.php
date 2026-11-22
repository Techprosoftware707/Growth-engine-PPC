<?php
/**
 * Outreach orchestration: campaigns, personalised drafts, human approval, compliant sending.
 *
 * Nothing is ever sent without (1) an approved campaign, (2) an individually approved message
 * (or an auto follow-up explicitly enabled by both the campaign and the automation switch),
 * (3) a passing Compliance::validate() and (4) a free slot in the daily/weekly limits.
 *
 * Automation (user id 0) may approve an initial message only when Autopilot is on, or outreach autosend is on AND
 * the owner turned off "Require individual approval" (Automation\Autopilot decides), and it never approves anything
 * in a campaign a person paused. Approvals made under Autopilot are recorded with the actor "system:autopilot", so
 * switching Autopilot off can return them to draft.
 * Every send first claims its row (approved/scheduled -> "sending" with a compare-and-set on status and
 * updated_at), so a message that was sent, edited or rejected meanwhile is never sent from a stale copy.
 *
 * While the storefront compliance check (Compliance\PositioningGate) blocks, nothing is sent: approved messages
 * stay approved with a "Paused: …" last_error and drafting/approval continue. When the check cannot run, sending
 * fails closed.
 *
 * The compliance footer (brand, postal address, unsubscribe link) is rendered into a draft when it is built. When the
 * postal address, the brand or the site URL changes before the message is sent, refreshFooter() rebuilds that footer
 * from the current settings and replaces it in place, before every approval, before every send and on every manual
 * save, so a draft is never refused (or sent) with an address the owner no longer uses. A footer edited so that it
 * cannot be recognised is not patched by guesswork: the message is refused with FOOTER_EDIT_HINT and waits for an edit.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Outreach;

use LinkGrowth\Autopilot\Automation\Autopilot;
use LinkGrowth\Autopilot\Compliance\PositioningGate;
use LinkGrowth\Autopilot\Content\ClaimsFilter;
use LinkGrowth\Autopilot\Content\ContentGenerator;
use LinkGrowth\Autopilot\Integrations\AiWriter;
use LinkGrowth\Autopilot\Monitoring\Notifier;
use LinkGrowth\Autopilot\Plugin;
use LinkGrowth\Autopilot\Repositories\AuditLog;
use LinkGrowth\Autopilot\Repositories\Campaigns;
use LinkGrowth\Autopilot\Repositories\Contacts;
use LinkGrowth\Autopilot\Repositories\Opportunities;
use LinkGrowth\Autopilot\Repositories\OutreachMessages;
use LinkGrowth\Autopilot\Repositories\Prospects;
use LinkGrowth\Autopilot\Support\Crypto;
use LinkGrowth\Autopilot\Support\Limits;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\Str;
use LinkGrowth\Autopilot\Support\TimeBudget;

final class OutreachEngine {

    /** Contact role preference per approach (first = best); support/noreply are never used. */
    private const APPROACH_ROLES = [
        'resource_page'            => [ 'editor', 'directory', 'person', 'general', 'partnerships' ],
        'directory_inclusion'      => [ 'directory', 'general', 'partnerships', 'editor', 'person' ],
        'partnership'              => [ 'partnerships', 'general', 'directory', 'person', 'editor' ],
        'expert_commentary'        => [ 'editor', 'person', 'general', 'partnerships', 'directory' ],
        'educational_contribution' => [ 'editor', 'person', 'general', 'partnerships', 'directory' ],
        'editorial_mention'        => [ 'editor', 'person', 'general', 'partnerships', 'directory' ],
        'broken_link'              => [ 'editor', 'directory', 'person', 'general', 'partnerships' ],
        'unlinked_mention'         => [ 'editor', 'person', 'general', 'partnerships', 'directory' ],
    ];

    private const DEFAULT_ROLES = [ 'editor', 'partnerships', 'directory', 'person', 'general' ];

    /** Opportunity types that fit each approach (first = best). */
    public const APPROACH_OPPORTUNITIES = [
        'resource_page'            => [ 'resource_page' ],
        'directory_inclusion'      => [ 'directory_listing', 'supplier_listing', 'membership' ],
        'partnership'              => [ 'partnership', 'membership' ],
        'expert_commentary'        => [ 'expert_commentary', 'editorial', 'podcast' ],
        'educational_contribution' => [ 'educational_contribution', 'resource_page' ],
        'editorial_mention'        => [ 'editorial', 'podcast', 'unlinked_mention' ],
        'broken_link'              => [ 'broken_link' ],
        'unlinked_mention'         => [ 'unlinked_mention' ],
    ];

    public const REQUIRES_OPPORTUNITY = [ 'broken_link', 'unlinked_mention' ];

    private const EDITABLE_STATUSES = [ OutreachMessages::STATUS_DRAFT, OutreachMessages::STATUS_APPROVED, OutreachMessages::STATUS_SCHEDULED, OutreachMessages::STATUS_REJECTED, OutreachMessages::STATUS_FAILED ];

    /** Transient message status while one run owns the row and the email is being handed to wp_mail(). */
    public const STATUS_SENDING = OutreachMessages::STATUS_SENDING;

    /** A claim older than this (seconds) belongs to a run that died mid-send. */
    private const CLAIM_STALE_AFTER = 900;

    /** States of an earlier message in the same conversation after which no follow-up may go out. */
    private const THREAD_ENDED = [ OutreachMessages::STATUS_REPLIED, OutreachMessages::STATUS_OPTED_OUT, OutreachMessages::STATUS_BOUNCED, OutreachMessages::STATUS_CLOSED ];

    /** Not-yet-sent states that a reply or a closed conversation cancels. */
    private const PENDING = [ OutreachMessages::STATUS_DRAFT, OutreachMessages::STATUS_APPROVED, OutreachMessages::STATUS_SCHEDULED ];

    /** Skip reason recorded while the storefront compliance check blocks sending. */
    public const SKIP_STOREFRONT = 'storefront_blocking';

    /** Ready messages that receive the "Paused" note per run while the storefront check blocks. */
    private const PAUSE_SCAN = 200;

    /** last_error when the storefront check cannot run (fail closed). */
    private const STOREFRONT_UNAVAILABLE = 'Paused: the storefront compliance check could not run, so outreach sending is paused until it does (check the plugin logs).';

    /** last_error prefix of a draft whose approval waits for a problem that clears without editing it. */
    public const WAITING_PREFIX = 'Waiting to approve: ';

    /** Audit action of refreshFooter() (one entry each time it rewrites a message's footer). */
    public const ACTION_FOOTER_REFRESHED = 'outreach.footer_refreshed';

    /**
     * Added after "Body must contain the sender postal address." (or "… the unsubscribe link.") when the footer of a
     * message could not be brought up to date (withCurrentFooter() does not guess which edited line held the old
     * address). It also keeps such a note apart from the ones 1.2.0 wrote for a stale footer, which automation retries
     * (CampaignFiller::STALE_FOOTER_NOTES): this one needs a person.
     */
    public const FOOTER_EDIT_HINT = 'The footer was edited, so it could not be updated to the current postal address (Settings → Outreach): delete the edited footer lines (the current footer is then added) or type the current address into them, then save.';

    /** The start of the footer's unsubscribe line (Personalizer::FOOTER_TEMPLATE), as a pattern. */
    private const UNSUBSCRIBE_SENTENCE = 'If you(?:\'|\x{2019})d rather not hear from us again\b';

    /**
     * The compliance footer block (Personalizer::FOOTER_TEMPLATE) as it sits in a body: a "<brand> · <postal address>"
     * line ("brand"), up to four lines its address continues on when it was written on several lines or wrapped
     * ("more": none blank, none with a "·"), blank lines an edit put above the unsubscribe line ("gap"), then the "If
     * you'd rather not hear from us again" line, whose link withCurrentFooter() checks against the message's token.
     * Several "·" lines in a row: the block starts at the last one.
     */
    private const FOOTER_BLOCK_RE = '/^(?<brand>[^\r\n]*\x{00B7}[^\r\n]*+)(?<more>(?:\r?\n(?!' . self::UNSUBSCRIBE_SENTENCE . ')(?=[^\r\n]*\S)[^\r\n\x{00B7}]++(?=\r?\n)){0,4})(?<gap>(?:\r?\n[ \t]*+(?=\r?\n))*)\r?\n(?<link>' . self::UNSUBSCRIBE_SENTENCE . '[^\r\n]*+)(?=\r?\n|\z)/miu';

    /** The footer's unsubscribe line on its own (the "<brand> · <address>" line above it was deleted). */
    private const UNSUBSCRIBE_LINE_RE = '/^(?<link>' . self::UNSUBSCRIBE_SENTENCE . '[^\r\n]*+)(?=\r?\n|\z)/miu';

    /** A "<brand> · <postal address>" line (and up to four lines its address continues on) that ends the body. */
    private const TRAILING_BRAND_RE = '/^(?<brand>[^\r\n]*\x{00B7}[^\r\n]*+)(?:\r?\n(?=[^\r\n]*\S)[^\r\n\x{00B7}]++){0,4}\s*\z/mu';

    /** @var array<int,array<string,mixed>|null> */
    private array $campaignCache = [];

    public function __construct(
        private Campaigns $campaigns,
        private OutreachMessages $messages,
        private Contacts $contacts,
        private Prospects $prospects,
        private Opportunities $opportunities,
        private Templates $templates,
        private Personalizer $personalizer,
        private Compliance $compliance,
        private Mailer $mailer,
        private ContentGenerator $content,
        private Limits $limits,
        private AuditLog $audit,
        private Notifier $notifier,
        private Settings $settings,
        private Logger $logger,
        private ?PositioningGate $storefront = null,
        private ?Autopilot $autopilot = null
    ) {}

    /** The approval policy (Automation\Autopilot), built from the same settings when it was not injected. */
    public function autopilot(): Autopilot {
        return $this->autopilot ??= new Autopilot( $this->settings );
    }

    // --- Campaigns ----------------------------------------------------------

    /**
     * Creates a draft campaign. Returns 0 when the approach is unknown or the insert fails.
     *
     * @param array<string,mixed> $settings daily_limit, follow_ups, auto_follow_up, notes
     */
    public function createCampaign( string $name, string $approach, array $settings, int $userId ): int {
        $approach = sanitize_key( $approach );
        if ( ! Templates::isApproach( $approach ) ) {
            $this->logger->warning( 'outreach', 'Campaign not created: unknown approach "' . $approach . '".' );
            return 0;
        }
        $name = trim( sanitize_text_field( $name ) );
        if ( '' === $name ) {
            $name = Templates::label( $approach ) . ' campaign';
        }
        $clean = $this->cleanCampaignSettings( $settings );
        try {
            $id = $this->campaigns->create( $name, $approach, $clean, $userId > 0 ? $userId : null );
        } catch ( \Throwable $e ) {
            $this->logger->error( 'outreach', 'Campaign insert failed: ' . $e->getMessage() );
            return 0;
        }
        $this->audit->record( $this->actor( $userId ), 'campaign.created', 'campaign', $id, [ 'name' => $name, 'approach' => $approach, 'settings' => $clean ] );
        return $id;
    }

    /**
     * Campaign row enriched with message counts and approach label.
     *
     * @return array<string,mixed>|null
     */
    public function campaign( int $id ): ?array {
        $c = $this->campaigns->find( $id );
        if ( ! $c ) {
            return null;
        }
        $counts = [];
        try {
            $counts = $this->messages->countBy( 'status', 'campaign_id = %d', [ $id ] );
        } catch ( \Throwable $e ) {
            $counts = [];
        }
        $c['counts']         = $counts;
        $c['messages_total'] = array_sum( $counts );
        $c['approach_label'] = Templates::label( (string) ( $c['approach'] ?? '' ) );
        $c['sent_today']     = $this->messages->sentTodayForCampaign( $id );
        return $c;
    }

    public function submitCampaignForApproval( int $id, int $userId ): bool {
        $c = $this->campaigns->find( $id );
        if ( ! $c ) {
            return false;
        }
        $status = (string) $c['status'];
        if ( Campaigns::STATUS_PENDING_APPROVAL === $status ) {
            return true;
        }
        if ( ! in_array( $status, [ Campaigns::STATUS_DRAFT, Campaigns::STATUS_PAUSED ], true ) ) {
            return false;
        }
        $this->campaigns->setStatus( $id, Campaigns::STATUS_PENDING_APPROVAL );
        $this->audit->record( $this->actor( $userId ), 'campaign.submitted', 'campaign', $id );
        return true;
    }

    /** Approves a campaign for sending; requires a complete sender identity (CASL/CAN-SPAM). */
    public function approveCampaign( int $id, int $userId ): bool {
        $c = $this->campaigns->find( $id );
        if ( ! $c ) {
            return false;
        }
        if ( $userId <= 0 && Campaigns::STATUS_PAUSED === (string) $c['status'] ) {
            // A pause is the owner's decision; only a person (resume / approve) may lift it.
            $this->logger->info( 'outreach', 'Campaign #' . $id . ' stays paused: automation never re-approves a paused campaign.' );
            return false;
        }
        $sender = $this->compliance->senderReady();
        if ( ! $sender['ok'] ) {
            $this->logger->warning( 'outreach', 'Campaign #' . $id . ' not approved: sender identity incomplete (' . implode( ', ', $sender['missing'] ) . ').' );
            $why = is_array( $sender['problems'] ?? null ) && [] !== $sender['problems'] ? implode( ' ', $sender['problems'] ) . ' ' : '';
            $this->notify( 'outreach_sender', 'warning', 'Outreach sender identity incomplete', $why . 'Complete the sender name, a sender email on a domain that receives mail and the postal address under Settings → Outreach before campaigns can be approved.', [ 'missing' => $sender['missing'] ] );
            return false;
        }
        $status = (string) $c['status'];
        if ( in_array( $status, [ Campaigns::STATUS_APPROVED, Campaigns::STATUS_SENDING ], true ) ) {
            return true;
        }
        if ( ! in_array( $status, [ Campaigns::STATUS_DRAFT, Campaigns::STATUS_PENDING_APPROVAL, Campaigns::STATUS_PAUSED ], true ) ) {
            return false;
        }
        $this->campaigns->setStatus( $id, Campaigns::STATUS_APPROVED, [ 'approved_by' => $userId > 0 ? $userId : null, 'approved_at' => Campaigns::now() ] );
        unset( $this->campaignCache[ $id ] );
        $this->audit->record( $this->approver( $userId ), 'campaign.approved', 'campaign', $id, [ 'name' => $c['name'] ?? '', 'autopilot' => $userId <= 0 && $this->autopilot()->isOn() ] );
        return true;
    }

    public function pauseCampaign( int $id, int $userId ): bool {
        $c = $this->campaigns->find( $id );
        if ( ! $c ) {
            return false;
        }
        if ( Campaigns::STATUS_PAUSED === (string) $c['status'] ) {
            return true;
        }
        if ( ! in_array( (string) $c['status'], [ Campaigns::STATUS_APPROVED, Campaigns::STATUS_SENDING, Campaigns::STATUS_PENDING_APPROVAL ], true ) ) {
            return false;
        }
        $this->campaigns->setStatus( $id, Campaigns::STATUS_PAUSED );
        unset( $this->campaignCache[ $id ] );
        $this->audit->record( $this->actor( $userId ), 'campaign.paused', 'campaign', $id );
        return true;
    }

    public function resumeCampaign( int $id, int $userId ): bool {
        $c = $this->campaigns->find( $id );
        if ( ! $c ) {
            return false;
        }
        $status = (string) $c['status'];
        if ( in_array( $status, [ Campaigns::STATUS_APPROVED, Campaigns::STATUS_SENDING ], true ) ) {
            return true; // already active
        }
        if ( Campaigns::STATUS_PAUSED !== $status || ! $this->compliance->senderReady()['ok'] ) {
            return false;
        }
        $this->campaigns->setStatus( $id, Campaigns::STATUS_APPROVED );
        unset( $this->campaignCache[ $id ] );
        $this->audit->record( $this->actor( $userId ), 'campaign.resumed', 'campaign', $id );
        return true;
    }

    public function archiveCampaign( int $id, int $userId ): bool {
        $c = $this->campaigns->find( $id );
        if ( ! $c ) {
            return false;
        }
        $this->campaigns->setStatus( $id, Campaigns::STATUS_ARCHIVED );
        unset( $this->campaignCache[ $id ] );
        $this->audit->record( $this->actor( $userId ), 'campaign.archived', 'campaign', $id );
        return true;
    }

    // --- Drafting -----------------------------------------------------------

    /**
     * @param array<int,int|string> $prospectIds
     * @param bool                  $interactive An administrator is waiting on this request: AI calls use the
     *                                           shorter AiWriter::INTERACTIVE_TIMEOUT.
     * @return array{created:int,skipped:array<int,string>}
     */
    public function buildDrafts( int $campaignId, array $prospectIds, int $userId, bool $useAi = false, bool $interactive = false ): array {
        $out = [ 'created' => 0, 'skipped' => [] ];
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $prospectIds ), static fn( int $i ): bool => $i > 0 ) ) );
        foreach ( array_slice( $ids, 0, 500 ) as $prospectId ) {
            try {
                [ $id, $reason ] = $this->draft( $campaignId, $prospectId, $userId, $useAi, $interactive );
            } catch ( \Throwable $e ) {
                $id     = null;
                $reason = 'Error: ' . $e->getMessage();
                $this->logger->error( 'outreach', 'Draft failed for prospect #' . $prospectId . ': ' . $e->getMessage() );
            }
            if ( null !== $id ) {
                $out['created']++;
            } else {
                $out['skipped'][ $prospectId ] = $reason;
            }
        }
        return $out;
    }

    public function draftForProspect( int $campaignId, int $prospectId, int $userId, bool $useAi = false ): ?int {
        try {
            [ $id, $reason ] = $this->draft( $campaignId, $prospectId, $userId, $useAi );
            if ( null === $id ) {
                $this->logger->info( 'outreach', 'Draft skipped for prospect #' . $prospectId . ': ' . $reason );
            }
            return $id;
        } catch ( \Throwable $e ) {
            $this->logger->error( 'outreach', 'Draft failed for prospect #' . $prospectId . ': ' . $e->getMessage() );
            return null;
        }
    }

    /**
     * @return array{0:int|null,1:string} [message id, skip reason]
     */
    private function draft( int $campaignId, int $prospectId, int $userId, bool $useAi, bool $interactive = false ): array {
        $campaign = $this->campaignCached( $campaignId );
        if ( ! $campaign ) {
            return [ null, 'Campaign not found' ];
        }
        if ( in_array( (string) $campaign['status'], [ Campaigns::STATUS_ARCHIVED, Campaigns::STATUS_COMPLETED ], true ) ) {
            return [ null, 'Campaign is ' . $campaign['status'] ];
        }
        $approach = (string) ( $campaign['approach'] ?? '' );
        if ( ! Templates::isApproach( $approach ) ) {
            return [ null, 'Unknown approach "' . $approach . '"' ];
        }
        $prospect = $this->prospects->find( $prospectId );
        if ( ! $prospect ) {
            return [ null, 'Prospect not found' ];
        }
        if ( in_array( (string) ( $prospect['status'] ?? '' ), [ Prospects::STATUS_REJECTED, Prospects::STATUS_SUPPRESSED ], true ) ) {
            return [ null, 'Prospect is ' . $prospect['status'] ];
        }
        $contact = $this->bestContact( $prospectId, $approach );
        if ( ! $contact ) {
            return [ null, 'No eligible active contact (add one on the prospect)' ];
        }
        $contactId = (int) $contact['id'];
        $existing  = $this->messages->existsFor( $contactId, $approach, 1 );
        if ( $existing ) {
            return [ null, 'A first message to this contact for this approach already exists' . $this->whereMessage( $existing ) ];
        }
        $open = $this->openMessageFor( $prospectId );
        if ( null !== $open ) {
            // One open conversation per website: a second pitch to the same site waits until the first is answered,
            // closed or sent and the contact cool-down has passed.
            return [ null, 'This website already has an open message' . $this->whereMessage( $open ) . '; one open message per website' ];
        }
        $opportunity = $this->bestOpportunity( $prospectId, $approach );
        if ( ! $opportunity && in_array( $approach, self::REQUIRES_OPPORTUNITY, true ) ) {
            return [ null, 'No ' . strtolower( Templates::label( $approach ) ) . ' opportunity recorded for this prospect' ];
        }
        $variants = Templates::variants( $approach );
        if ( [] === $variants ) {
            return [ null, 'No templates for this approach' ];
        }
        $variant = Str::seededPick( $variants, Str::seed( (string) ( $prospect['domain'] ?? (string) $prospectId ) ), $campaignId );
        if ( ! is_array( $variant ) ) {
            $variant = $variants[0];
        }

        $token = Crypto::randomToken( 16 );
        $url   = Unsubscribe::urlFor( $token );
        $ctx   = $this->personalizer->context( $prospect, $opportunity, $contact, $approach, $url );
        $ctx   = array_merge( $ctx, $this->generatedContent( $prospect, $approach, $ctx, (string) $variant['body'] ) );

        $subject = $this->cleanSubject( $this->personalizer->render( $variant['subject'], $ctx ) );
        $body    = $this->personalizer->render( $variant['body'], $ctx );
        if ( $useAi ) {
            $body = $this->aiPersonalize( $body, $ctx, $interactive );
        }
        $violations = $this->compliance->checkClaims( $subject, $body );

        $data = [
            'campaign_id'       => $campaignId,
            'prospect_id'       => $prospectId,
            'contact_id'        => $contactId,
            'opportunity_id'    => $opportunity ? (int) $opportunity['id'] : null,
            'approach'          => $approach,
            'sequence'          => 1,
            'subject'           => $subject,
            'body'              => $body,
            'status'            => OutreachMessages::STATUS_DRAFT,
            'variant'           => (string) $variant['id'],
            'unsubscribe_token' => $token,
        ];
        if ( [] !== $violations ) {
            $data['last_error'] = 'Needs editing before approval: ' . implode( ' ', $violations );
        }
        $id = $this->messages->create( $data );
        $this->audit->record( $this->actor( $userId ), 'outreach.drafted', 'outreach_message', $id, [ 'campaign_id' => $campaignId, 'prospect_id' => $prospectId, 'contact_id' => $contactId, 'approach' => $approach, 'variant' => $variant['id'], 'ai' => $useAi, 'claims_violations' => count( $violations ) ] );
        return [ $id, '' ];
    }

    // --- Message review -----------------------------------------------------

    /**
     * Saves an edited subject/body (re-attaching the compliance footer if it was removed, and replacing a footer
     * rendered under an earlier postal address, brand or site URL in place) and reports claims violations. An edited
     * message always returns to "draft" for re-approval.
     *
     * @return array{ok:bool,violations:string[]}
     */
    public function updateMessage( int $id, string $subject, string $body, int $userId ): array {
        $m = $this->messages->find( $id );
        if ( ! $m ) {
            return [ 'ok' => false, 'violations' => [ 'Message not found.' ] ];
        }
        if ( ! in_array( (string) $m['status'], self::EDITABLE_STATUSES, true ) ) {
            return [ 'ok' => false, 'violations' => [ 'Message can no longer be edited (status: ' . $m['status'] . ').' ] ];
        }
        $violations = [];
        $subject    = $this->cleanSubject( $subject );
        $body       = $this->cleanBody( $body );
        if ( '' === $subject ) {
            $violations[] = 'Subject is empty.';
        }
        if ( '' === trim( $body ) ) {
            $violations[] = 'Body is empty.';
        }
        $token = strtolower( (string) ( $m['unsubscribe_token'] ?? '' ) );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
            $token = Crypto::randomToken( 16 );
        }
        // A stale footer is replaced, not kept above a second, current one. One that cannot be updated without
        // guessing which edited line holds the old address is left as written and reported.
        $edited = Unsubscribe::refreshLinks( $body, $token );
        $body   = $this->withCurrentFooter( $edited, $token );
        foreach ( $this->footerProblems( $body, $token ) as $v ) {
            $violations[] = $v;
        }
        foreach ( $this->compliance->checkClaims( $subject, $body ) as $v ) {
            $violations[] = $v;
        }
        $this->messages->update(
            $id,
            [
                'subject'           => $subject,
                'body'              => $body,
                'status'            => OutreachMessages::STATUS_DRAFT,
                'unsubscribe_token' => $token,
                'last_error'        => [] !== $violations ? 'Needs editing before approval: ' . implode( ' ', $violations ) : '',
                'updated_at'        => OutreachMessages::now(),
            ]
        );
        $this->audit->record( $this->actor( $userId ), 'outreach.message_updated', 'outreach_message', $id, [ 'violations' => count( $violations ), 'previous_status' => $m['status'], 'footer_refreshed' => $body !== $edited ] );
        return [ 'ok' => [] === $violations, 'violations' => $violations ];
    }

    /**
     * Brings the compliance footer of a message that is not sent yet (draft, approved, scheduled, failed, rejected)
     * up to date with the current settings: a footer rendered before the postal address (outreach.postal_address, or
     * the business address under Settings → Site it falls back to), the brand or the site URL changed is rebuilt and
     * replaced in place, a missing one is appended (withCurrentFooter()). Nothing else in the body changes, the
     * unsubscribe token stays the same, and sent, replied, closed or opted-out messages are never touched.
     *
     * The new body is stored only when it differs, with a compare-and-set on status and updated_at (an edit, approval
     * or send that happened meanwhile wins and the stored row is looked at again), and each rewrite is recorded once
     * in the audit log (ACTION_FOOTER_REFRESHED). It approves nothing, so it runs whatever the approval policy says.
     *
     * @param int|array<string,mixed> $message Message id, or the row as read.
     * @param int                     $userId  The person whose action led here (0 = automation).
     * @return array<string,mixed> The row to continue with (refreshed, or as stored now); [] when it does not exist.
     */
    public function refreshFooter( int|array $message, int $userId = 0 ): array {
        $m = is_array( $message ) ? $message : $this->messages->find( $message );
        for ( $attempt = 0; is_array( $m ) && ! empty( $m['id'] ) && $attempt < 2; $attempt++ ) {
            if ( ! in_array( (string) ( $m['status'] ?? '' ), self::EDITABLE_STATUSES, true ) ) {
                return $m;
            }
            $body = (string) ( $m['body'] ?? '' );
            $new  = $this->withCurrentFooter( $body, (string) ( $m['unsubscribe_token'] ?? '' ) );
            if ( $new === $body ) {
                return $m;
            }
            $id = (int) $m['id'];
            if ( $this->transition( $m, [ 'body' => $new ] ) ) {
                $this->audit->record(
                    $this->actor( $userId, 'system:outreach' ),
                    self::ACTION_FOOTER_REFRESHED,
                    'outreach_message',
                    $id,
                    [ 'status' => (string) $m['status'], 'campaign_id' => (int) ( $m['campaign_id'] ?? 0 ), 'sequence' => (int) ( $m['sequence'] ?? 1 ), 'postal_address' => $this->compliance->postalAddress() ]
                );
                return $this->messages->find( $id ) ?? [];
            }
            // Changed meanwhile (edited, approved, sent): look again at what is stored now.
            $m = $this->messages->find( $id );
        }
        return is_array( $m ) ? $m : [];
    }

    /**
     * Approves a message after a full compliance validation (as if it were about to be sent).
     *
     * @return array{ok:bool,violations:string[]}
     */
    public function approveMessage( int $id, int $userId ): array {
        $m = $this->messages->find( $id );
        if ( ! $m ) {
            return [ 'ok' => false, 'violations' => [ 'Message not found.' ] ];
        }
        if ( ! in_array( (string) $m['status'], [ OutreachMessages::STATUS_DRAFT, OutreachMessages::STATUS_REJECTED, OutreachMessages::STATUS_APPROVED, OutreachMessages::STATUS_SCHEDULED, OutreachMessages::STATUS_FAILED ], true ) ) {
            return [ 'ok' => false, 'violations' => [ 'Message cannot be approved (status: ' . $m['status'] . ').' ] ];
        }
        $contact = $this->contacts->find( (int) ( $m['contact_id'] ?? 0 ) );
        if ( ! $contact ) {
            return [ 'ok' => false, 'violations' => [ 'Contact no longer exists.' ] ];
        }
        $campaignId = (int) ( $m['campaign_id'] ?? 0 );
        $campaign   = $campaignId > 0 ? $this->campaignCached( $campaignId ) : null;
        if ( $campaignId > 0 && ! $campaign ) {
            return [ 'ok' => false, 'violations' => [ 'Campaign no longer exists.' ] ];
        }
        if ( $campaign && in_array( (string) $campaign['status'], [ Campaigns::STATUS_ARCHIVED, Campaigns::STATUS_COMPLETED ], true ) ) {
            return [ 'ok' => false, 'violations' => [ 'Campaign is ' . $campaign['status'] . '.' ] ];
        }
        if ( $userId <= 0 ) {
            // Automation approving on the owner's behalf (Autopilot / outreach autosend): honour the owner's settings
            // as stored now, not as this (possibly long-running) request read them: Autopilot may have been switched
            // off by another request meanwhile.
            $this->settings->refresh();
            if ( $this->autopilot()->requireApproval() ) {
                return [ 'ok' => false, 'violations' => [ 'Individual approval is required (Settings → Outreach → Require individual approval), so automation does not approve messages.' ] ];
            }
            if ( $campaign && Campaigns::STATUS_PAUSED === (string) $campaign['status'] ) {
                return [ 'ok' => false, 'violations' => [ 'Campaign is paused; automation does not approve messages in it.' ] ];
            }
        }
        // A draft built before the postal address, brand or site URL changed carries the footer rendered with the old
        // values: it is brought up to date first (only the footer block changes), so the check below sees the footer
        // the email will actually carry instead of refusing the draft as one to edit.
        $m = $this->refreshFooter( $m, $userId );
        if ( [] === $m ) {
            return [ 'ok' => false, 'violations' => [ 'Message not found.' ] ];
        }
        if ( ! in_array( (string) $m['status'], self::EDITABLE_STATUSES, true ) ) {
            return [ 'ok' => false, 'violations' => [ 'Message cannot be approved (status: ' . $m['status'] . ').' ] ];
        }
        $prospectId = (int) ( $m['prospect_id'] ?? 0 );
        $prospect   = $prospectId > 0 ? $this->prospects->find( $prospectId ) : null;
        // The campaign's own approval is enforced at send time, so messages may be reviewed in any order.
        $result = $this->compliance->assess( array_merge( $m, [ 'status' => OutreachMessages::STATUS_APPROVED ] ), $contact, null, $prospect );
        if ( ! $result['ok'] ) {
            // A problem that clears by itself or with a settings fix (sender identity, the contact cool-down) is not
            // something to edit in the draft: it is noted as "Waiting", so automation approves it once it clears.
            // Anything else ("Not approved:") needs a person to edit the draft, a footer that is still missing the
            // address or link after the refresh above included (it says what to do, and is not retried).
            $violations = self::withFooterHint( $result['violations'] );
            $prefix     = $result['transient'] ? self::WAITING_PREFIX : 'Not approved: ';
            $this->messages->update( $id, [ 'last_error' => $prefix . implode( ' ', $violations ), 'updated_at' => OutreachMessages::now() ] );
            return [ 'ok' => false, 'violations' => $violations ];
        }
        $this->messages->setStatus( $id, OutreachMessages::STATUS_APPROVED, [ 'last_error' => '' ] );
        $this->audit->record( $this->approver( $userId ), 'outreach.message_approved', 'outreach_message', $id, [ 'campaign_id' => $campaignId, 'prospect_id' => (int) ( $m['prospect_id'] ?? 0 ), 'autopilot' => $userId <= 0 && $this->autopilot()->isOn() ] );
        return [ 'ok' => true, 'violations' => [] ];
    }

    public function rejectMessage( int $id, int $userId, string $reason = '' ): bool {
        $m = $this->messages->find( $id );
        if ( ! $m || ! in_array( (string) $m['status'], self::EDITABLE_STATUSES, true ) ) {
            return false;
        }
        $reason = trim( sanitize_text_field( $reason ) );
        $this->messages->setStatus( $id, OutreachMessages::STATUS_REJECTED, [ 'last_error' => '' !== $reason ? 'Rejected: ' . mb_substr( $reason, 0, 400 ) : 'Rejected' ] );
        $this->audit->record( $this->actor( $userId ), 'outreach.message_rejected', 'outreach_message', $id, [ 'reason' => $reason ] );
        return true;
    }

    /** Closes a conversation (after a reply was handled or when no answer is expected) and cancels its pending follow-ups. */
    public function closeMessage( int $id, int $userId ): bool {
        $m = $this->messages->find( $id );
        if ( ! $m || OutreachMessages::STATUS_CLOSED === (string) $m['status'] ) {
            return (bool) $m;
        }
        $this->messages->setStatus( $id, OutreachMessages::STATUS_CLOSED );
        $cancelled = $this->cancelLaterInThread( $m, 'Cancelled: the conversation was closed.' );
        $this->audit->record( $this->actor( $userId ), 'outreach.message_closed', 'outreach_message', $id, [ 'previous_status' => $m['status'], 'follow_ups_cancelled' => $cancelled ] );
        return true;
    }

    public function logReply( int $messageId, string $summary, int $userId ): bool {
        $m = $this->messages->find( $messageId );
        if ( ! $m ) {
            return false;
        }
        $summary = trim( sanitize_textarea_field( $summary ) );
        $summary = mb_strlen( $summary ) > 1000 ? mb_substr( $summary, 0, 999 ) . '…' : $summary;
        $this->messages->setStatus(
            $messageId,
            OutreachMessages::STATUS_REPLIED,
            [ 'replied_at' => ! empty( $m['replied_at'] ) ? $m['replied_at'] : OutreachMessages::now(), 'reply_summary' => $summary ]
        );
        // Someone who answered must not get the "following up" email afterwards.
        $cancelled = $this->cancelLaterInThread( $m, 'Cancelled: the recipient replied.' );
        $this->audit->record( $this->actor( $userId ), 'outreach.reply_logged', 'outreach_message', $messageId, [ 'prospect_id' => (int) ( $m['prospect_id'] ?? 0 ), 'follow_ups_cancelled' => $cancelled ] );
        return true;
    }

    /**
     * Closes the not-yet-sent later messages (follow-ups) of the conversation $m belongs to: same contact, same
     * approach, higher sequence. Returns how many were cancelled.
     *
     * @param array<string,mixed> $m
     */
    private function cancelLaterInThread( array $m, string $why ): int {
        $contactId = (int) ( $m['contact_id'] ?? 0 );
        if ( $contactId <= 0 ) {
            return 0;
        }
        $sequence = (int) ( $m['sequence'] ?? 1 );
        $n        = 0;
        foreach ( $this->messages->findBy( [ 'contact_id' => $contactId, 'approach' => (string) ( $m['approach'] ?? '' ), 'status' => self::PENDING ], 'sequence', 'ASC', 50 ) as $p ) {
            if ( (int) $p['id'] === (int) $m['id'] || (int) ( $p['sequence'] ?? 1 ) <= $sequence ) {
                continue;
            }
            $this->messages->setStatus( (int) $p['id'], OutreachMessages::STATUS_CLOSED, [ 'last_error' => $why ] );
            $n++;
        }
        return $n;
    }

    public function addContact( int $prospectId, string $email, string $name, string $role, int $userId ): ?int {
        $email = strtolower( trim( sanitize_email( $email ) ) );
        if ( '' === $email || ! is_email( $email ) ) {
            return null;
        }
        if ( ! $this->prospects->find( $prospectId ) ) {
            return null;
        }
        $role = strtolower( trim( sanitize_text_field( $role ) ) );
        $role = preg_replace( '/[^a-z0-9_\-]/', '', $role ) ?? '';
        $name = trim( sanitize_text_field( $name ) );
        try {
            [ $id, $created ] = $this->contacts->upsert(
                $prospectId,
                $email,
                [
                    'name'          => mb_substr( $name, 0, 190 ),
                    'role'          => mb_substr( $role, 0, 100 ),
                    'source_url'    => 'manual',
                    'confidence'    => 90,
                    'consent_basis' => 'conspicuous_publication',
                ]
            );
        } catch ( \Throwable $e ) {
            $this->logger->error( 'outreach', 'Contact insert failed: ' . $e->getMessage() );
            return null;
        }
        if ( $id <= 0 ) {
            return null;
        }
        $this->audit->record( $this->actor( $userId ), $created ? 'contact.added' : 'contact.updated', 'contact', $id, [ 'prospect_id' => $prospectId, 'email' => $email, 'role' => $role ] );
        return $id;
    }

    /** @return array{subject:string,body:string,to:string,to_name:string,from:string} */
    public function preview( int $messageId ): array {
        $m = $this->messages->find( $messageId );
        if ( ! $m ) {
            return [ 'subject' => '', 'body' => '', 'to' => '', 'to_name' => '', 'from' => '' ];
        }
        $contact = $this->contacts->find( (int) ( $m['contact_id'] ?? 0 ) );
        $name    = trim( (string) $this->settings->get( 'outreach.sender_name', '' ) );
        $email   = trim( (string) $this->settings->get( 'outreach.sender_email', '' ) );
        return [
            'subject' => (string) ( $m['subject'] ?? '' ),
            'body'    => (string) ( $m['body'] ?? '' ),
            'to'      => (string) ( $contact['email'] ?? '' ),
            'to_name' => (string) ( $contact['name'] ?? '' ),
            'from'    => '' !== $name ? $name . ' <' . $email . '>' : $email,
        ];
    }

    // --- Sending ------------------------------------------------------------

    /**
     * Sends due messages inside the send window, within limits, after full validation.
     *
     * @return array{sent:int,failed:int,skipped:int,reasons:array<string,int>}
     */
    public function sendDue( TimeBudget $budget, int $max = 10 ): array {
        $out  = [ 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'reasons' => [] ];
        $max  = max( 1, min( 100, $max ) );
        $skip = static function ( string $reason, int $n = 1 ) use ( &$out ): void {
            $out['skipped']               += $n;
            $out['reasons'][ $reason ] = ( $out['reasons'][ $reason ] ?? 0 ) + $n;
        };
        try {
            $this->releaseStaleClaims();
            // Read the switch as stored now: a tick that started before Autopilot was switched off may have approved
            // messages after disable() returned the others to draft. Those go back to draft too, before anything is
            // sent, so "automatic sending stops at once" holds.
            $this->settings->refresh();
            if ( ! $this->autopilot()->isOn() ) {
                $reverted = $this->autopilot()->revertAutoApproved();
                if ( $reverted > 0 ) {
                    $out['reasons']['autopilot_off'] = $reverted;
                }
            }
            $autosend = $this->autopilot()->outreachAutosend();
            $ready    = $this->sendCandidates( $max, $autosend );
            if ( [] === $ready ) {
                // Say why nothing goes out when first-contact drafts wait for a person (e.g. autosend on while
                // "Require individual approval" is on): reported as a reason only, nothing was skipped.
                $waiting = $this->autopilot()->autoApproveMessages() ? 0 : $this->messages->count( [ 'status' => OutreachMessages::STATUS_DRAFT, 'sequence' => 1 ] );
                if ( $waiting > 0 ) {
                    $out['reasons']['awaiting_approval'] = $waiting;
                }
                return $out;
            }
            $pause = $this->storefrontPause();
            if ( '' !== $pause ) {
                // Nothing goes out; every ready message shows why (written once, not on every run).
                foreach ( $this->sendCandidates( self::PAUSE_SCAN, $autosend ) as $m ) {
                    $this->markPaused( $m, $pause );
                }
                $skip( self::SKIP_STOREFRONT, count( $ready ) );
                $this->logger->info( 'outreach', 'Outreach sending is paused by the storefront compliance check.', [ 'ready' => count( $ready ) ] );
                return $out;
            }
            if ( ! $this->compliance->withinSendWindow() ) {
                $skip( 'outside_send_window', count( $ready ) );
                return $out;
            }
            if ( ! $this->limits->allow( 'outreach_emails' ) ) {
                $skip( 'daily_limit_reached', count( $ready ) );
                return $out;
            }
            if ( ! $this->compliance->senderReady()['ok'] ) {
                $skip( 'sender_incomplete', count( $ready ) );
                return $out;
            }
            foreach ( $ready as $m ) {
                if ( ! $budget->has( 2.0 ) ) {
                    $skip( 'time_budget' );
                    continue;
                }
                try {
                    [ $status, $reason ] = $this->deliver( $m, $autosend, 0 );
                } catch ( \Throwable $e ) {
                    $status = 'skipped';
                    $reason = 'error';
                    $this->logger->error( 'outreach', 'Send failed for message #' . (int) $m['id'] . ': ' . $e->getMessage() );
                }
                if ( 'sent' === $status ) {
                    $out['sent']++;
                } elseif ( 'failed' === $status ) {
                    $out['failed']++;
                } else {
                    $skip( $reason );
                }
            }
        } catch ( \Throwable $e ) {
            $this->logger->error( 'outreach', 'sendDue failed: ' . $e->getMessage() );
        }
        return $out;
    }

    /**
     * Whether sendDue() would find a message it may send now (for deciding whether to queue the send job).
     * While the storefront check blocks, only when a ready message does not show the pause note yet (so the
     * send stage records it once instead of running on every tick).
     */
    public function hasSendable(): bool {
        try {
            $autosend = $this->autopilot()->outreachAutosend();
            if ( [] === $this->sendCandidates( 1, $autosend ) ) {
                return false;
            }
            $pause = $this->storefrontPause();
            if ( '' === $pause ) {
                return true;
            }
            foreach ( $this->sendCandidates( self::PAUSE_SCAN, $autosend ) as $m ) {
                if ( (string) ( $m['last_error'] ?? '' ) !== $pause ) {
                    return true;
                }
            }
            return false;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * '' when outreach may be sent; otherwise the "Paused: …" note. Fails closed (and logs) when the storefront
     * compliance check cannot be constructed or cannot run.
     */
    private function storefrontPause(): string {
        try {
            $audit = $this->storefront;
            if ( null === $audit ) {
                $audit = class_exists( Plugin::class ) ? Plugin::instance()->get( PositioningGate::class ) : null;
                if ( ! $audit instanceof PositioningGate ) {
                    throw new \RuntimeException( 'the storefront check service is not available' );
                }
                $this->storefront = $audit;
            }
            if ( ! $audit->isBlocking() ) {
                return '';
            }
            $why = $audit->blockingReason();
            return '' !== $why ? 'Paused: ' . lcfirst( $why ) : self::STOREFRONT_UNAVAILABLE;
        } catch ( \Throwable $e ) {
            $this->logger->error( 'outreach', 'Storefront compliance check unavailable; outreach sending is paused (fail closed): ' . $e->getMessage() );
            return self::STOREFRONT_UNAVAILABLE;
        }
    }

    /**
     * Records the pause note on a ready message (compare-and-set; status unchanged; skipped when already shown).
     *
     * @param array<string,mixed> $m
     */
    private function markPaused( array $m, string $pause ): void {
        if ( (string) ( $m['last_error'] ?? '' ) === $pause ) {
            return;
        }
        $this->transition( $m, [ 'last_error' => mb_substr( $pause, 0, 2000 ) ] );
    }

    /**
     * Whether a run that died mid-send left a row "sending" for longer than CLAIM_STALE_AFTER. sendDue() marks
     * such rows failed, so the send stage is due for them even when nothing else can be sent.
     */
    public function hasStaleClaims(): bool {
        global $wpdb;
        try {
            $sql = "SELECT id FROM {$this->messages->table()} WHERE status = %s AND updated_at < %s LIMIT 1";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            return null !== $wpdb->get_var( $wpdb->prepare( $sql, self::STATUS_SENDING, self::staleClaimCutoff() ) );
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * Due messages that sendDue() may actually send: approved (and, with autosend on, scheduled) rows whose
     * campaign is approved/sending and still under its own daily limit. Rows held back by a paused, archived or
     * capped campaign are filtered out here instead of filling the batch on every run and starving the
     * campaigns that could send (rows blocked by compliance are moved out of the ready set by deliver()).
     *
     * @return array<int,array<string,mixed>>
     */
    private function sendCandidates( int $limit, bool $includeScheduled ): array {
        global $wpdb;
        $m        = $this->messages->table();
        $c        = $this->campaigns->table();
        $statuses = $includeScheduled ? [ OutreachMessages::STATUS_APPROVED, OutreachMessages::STATUS_SCHEDULED ] : [ OutreachMessages::STATUS_APPROVED ];
        $sql      = "SELECT m.* FROM {$m} m LEFT JOIN {$c} c ON c.id = m.campaign_id"
            . ' WHERE m.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')'
            . ' AND ( m.scheduled_at IS NULL OR m.scheduled_at <= %s )'
            . ' AND ( m.campaign_id = 0 OR ( c.status IN (%s,%s)'
            . " AND ( c.daily_limit = 0 OR ( SELECT COUNT(*) FROM {$m} s WHERE s.campaign_id = m.campaign_id AND s.sent_at >= %s ) < c.daily_limit ) ) )"
            . ' ORDER BY m.sequence ASC, m.id ASC LIMIT %d';
        $params   = array_merge( $statuses, [ OutreachMessages::now(), Campaigns::STATUS_APPROVED, Campaigns::STATUS_SENDING, gmdate( 'Y-m-d 00:00:00' ), max( 1, $limit ) ] );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        return array_map( [ $this->messages, 'hydrate' ], is_array( $rows ) ? $rows : [] );
    }

    /**
     * A run that died between claiming a row and recording the result leaves it "sending". After
     * CLAIM_STALE_AFTER it is marked failed (never re-queued: the email may already have gone out).
     */
    private function releaseStaleClaims(): void {
        global $wpdb;
        $sql = "UPDATE {$this->messages->table()} SET status = %s, last_error = %s, updated_at = %s WHERE status = %s AND updated_at < %s";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( $wpdb->prepare( $sql, OutreachMessages::STATUS_FAILED, 'Sending was interrupted; the email may or may not have gone out. Check the mail log before approving it again.', OutreachMessages::now(), self::STATUS_SENDING, self::staleClaimCutoff() ) );
    }

    /** Rows still "sending" with an updated_at before this belong to a run that died mid-send. */
    private static function staleClaimCutoff(): string {
        return gmdate( 'Y-m-d H:i:s', time() - self::CLAIM_STALE_AFTER );
    }

    /**
     * Compare-and-set update of a message row: applies only while the row still has the status and updated_at
     * of the copy in $m (so a send, edit, rejection or approval that happened meanwhile is never overwritten).
     *
     * @param array<string,mixed>            $m      Row as read.
     * @param array<string,string|int|null>  $fields Columns to set (fixed names from this class only).
     */
    private function transition( array $m, array $fields ): bool {
        global $wpdb;
        $fields['updated_at'] = OutreachMessages::now();
        $set                  = [];
        $params               = [];
        foreach ( $fields as $col => $val ) {
            if ( null === $val ) {
                $set[] = $col . ' = NULL';
                continue;
            }
            $set[]    = $col . ' = %s';
            $params[] = (string) $val;
        }
        $where    = 'id = %d AND status = %s';
        $params[] = (int) $m['id'];
        $params[] = (string) ( $m['status'] ?? '' );
        $updated  = trim( (string) ( $m['updated_at'] ?? '' ) );
        if ( '' === $updated ) {
            $where .= ' AND updated_at IS NULL';
        } else {
            $where   .= ' AND updated_at = %s';
            $params[] = $updated;
        }
        $sql = "UPDATE {$this->messages->table()} SET " . implode( ', ', $set ) . ' WHERE ' . $where;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $n = $wpdb->query( $wpdb->prepare( $sql, $params ) );
        return 1 === (int) $n;
    }

    /**
     * Whether an earlier message of the same conversation (contact + approach) was answered or ended, in which
     * case a follow-up must not go out.
     *
     * @param array<string,mixed> $m
     */
    private function threadEnded( array $m ): bool {
        $sequence = (int) ( $m['sequence'] ?? 1 );
        if ( $sequence <= 1 ) {
            return false;
        }
        foreach ( $this->messages->findBy( [ 'contact_id' => (int) ( $m['contact_id'] ?? 0 ), 'approach' => (string) ( $m['approach'] ?? '' ) ], 'sequence', 'ASC', 20 ) as $row ) {
            if ( (int) $row['id'] === (int) $m['id'] || (int) ( $row['sequence'] ?? 1 ) >= $sequence ) {
                continue;
            }
            if ( ! empty( $row['replied_at'] ) || in_array( (string) ( $row['status'] ?? '' ), self::THREAD_ENDED, true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Manual send by an administrator: still validates compliance and limits; ignores the send window.
     *
     * @return array{ok:bool,error:string}
     */
    public function sendNow( int $messageId, int $userId ): array {
        $m = $this->messages->find( $messageId );
        if ( ! $m ) {
            return [ 'ok' => false, 'error' => 'Message not found.' ];
        }
        $status = (string) $m['status'];
        if ( OutreachMessages::STATUS_DRAFT === $status || OutreachMessages::STATUS_REJECTED === $status ) {
            return [ 'ok' => false, 'error' => 'Approve the message before sending it.' ];
        }
        if ( ! in_array( $status, [ OutreachMessages::STATUS_APPROVED, OutreachMessages::STATUS_SCHEDULED ], true ) ) {
            return [ 'ok' => false, 'error' => 'Message cannot be sent (status: ' . $status . ').' ];
        }
        $pause = $this->storefrontPause();
        if ( '' !== $pause ) {
            $this->markPaused( $m, $pause );
            return [ 'ok' => false, 'error' => $pause ];
        }
        try {
            [ $result, $reason ] = $this->deliver( $m, true, $userId );
        } catch ( \Throwable $e ) {
            $this->logger->error( 'outreach', 'sendNow failed for message #' . $messageId . ': ' . $e->getMessage() );
            return [ 'ok' => false, 'error' => 'Sending failed: ' . $e->getMessage() ];
        }
        if ( 'sent' === $result ) {
            return [ 'ok' => true, 'error' => '' ];
        }
        return [ 'ok' => false, 'error' => $reason ];
    }

    /**
     * Shared delivery path. Returns [sent|failed|skipped, reason].
     *
     * @param array<string,mixed> $m
     * @return array{0:string,1:string}
     */
    private function deliver( array $m, bool $allowScheduled, int $userId ): array {
        $id     = (int) $m['id'];
        $status = (string) ( $m['status'] ?? '' );
        if ( OutreachMessages::STATUS_SCHEDULED === $status && ! $allowScheduled ) {
            return [ 'skipped', 'autosend_disabled' ];
        }
        if ( ! in_array( $status, [ OutreachMessages::STATUS_APPROVED, OutreachMessages::STATUS_SCHEDULED ], true ) ) {
            return [ 'skipped', 'not_approved' ];
        }
        // Storefront compliance gate (sendDue()/sendNow() check it first; repeated here for every caller).
        $pause = $this->storefrontPause();
        if ( '' !== $pause ) {
            $this->markPaused( $m, $pause );
            return [ 'skipped', self::SKIP_STOREFRONT ];
        }
        $campaignId = (int) ( $m['campaign_id'] ?? 0 );
        $campaign   = $campaignId > 0 ? $this->campaignCached( $campaignId ) : null;
        if ( $campaignId > 0 && ! $campaign ) {
            return [ 'skipped', 'campaign_missing' ];
        }
        if ( $campaign ) {
            if ( ! in_array( (string) $campaign['status'], [ Campaigns::STATUS_APPROVED, Campaigns::STATUS_SENDING ], true ) ) {
                return [ 'skipped', 'campaign_not_active' ];
            }
            $dailyLimit = (int) ( $campaign['daily_limit'] ?? 0 );
            if ( $dailyLimit > 0 && $this->messages->sentTodayForCampaign( $campaignId ) >= $dailyLimit ) {
                return [ 'skipped', 'campaign_daily_limit' ];
            }
        }
        if ( ! $this->limits->allow( 'outreach_emails' ) ) {
            return [ 'skipped', 'daily_limit_reached' ];
        }
        $contact = $this->contacts->find( (int) ( $m['contact_id'] ?? 0 ) );
        if ( ! $contact ) {
            $this->messages->setStatus( $id, OutreachMessages::STATUS_FAILED, [ 'last_error' => 'Contact no longer exists' ] );
            return [ 'failed', 'Contact no longer exists.' ];
        }
        if ( $this->threadEnded( $m ) ) {
            $this->transition( $m, [ 'status' => OutreachMessages::STATUS_CLOSED, 'last_error' => 'Not sent: the recipient already answered (or the conversation ended) before this follow-up went out.' ] );
            return [ 'skipped', 'thread_answered' ];
        }
        $prospectId = (int) ( $m['prospect_id'] ?? 0 );
        $prospect   = $prospectId > 0 ? $this->prospects->find( $prospectId ) : null;
        if ( $prospect && Prospects::STATUS_ON_HOLD === (string) ( $prospect['status'] ?? '' ) ) {
            $this->transition( $m, [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'last_error' => 'Deferred: the website is on hold.' ] );
            return [ 'skipped', 'prospect_on_hold' ];
        }
        // An approved message whose footer was rendered under an earlier postal address, brand or site URL goes out
        // with the current one (only the footer block changes); the rest of this send works on the refreshed row.
        $m = $this->refreshFooter( $m, $userId );
        if ( (string) ( $m['status'] ?? '' ) !== $status ) {
            return [ 'skipped', 'changed' ];
        }
        $validation = $this->compliance->assess( $m, $contact, $campaign, $prospect );
        if ( ! $validation['ok'] ) {
            // Take the row out of the ready set so it cannot hold up every later message: a cool-down defers it
            // until the cool-down ends, an incomplete sender identity (a settings fix) leaves it queued, anything
            // else (contact, website or copy) fails it with the reason so the owner can edit / re-approve it.
            $note = implode( ' ', self::withFooterHint( $validation['violations'] ) );
            if ( $validation['transient'] && null !== $validation['retry_at'] ) {
                $this->transition( $m, [ 'scheduled_at' => gmdate( 'Y-m-d H:i:s', max( time() + MINUTE_IN_SECONDS, (int) $validation['retry_at'] ) ), 'last_error' => 'Deferred: ' . $note ] );
            } elseif ( $validation['transient'] ) {
                $this->transition( $m, [ 'last_error' => 'Blocked: ' . $note ] );
            } else {
                $this->transition( $m, [ 'status' => OutreachMessages::STATUS_FAILED, 'last_error' => 'Blocked: ' . $note ] );
            }
            $this->logger->info( 'outreach', 'Message #' . $id . ' blocked by compliance.', [ 'violations' => $validation['violations'] ] );
            return [ 'skipped', 'compliance' ];
        }
        // Claim the row: only one run (cron batch or "Send now") gets past this, and only while the row is
        // unchanged since it was read (not sent, edited or rejected meanwhile).
        if ( ! $this->transition( $m, [ 'status' => self::STATUS_SENDING ] ) ) {
            return [ 'skipped', 'changed' ];
        }
        $token = (string) $m['unsubscribe_token'];
        $url   = Unsubscribe::urlFor( $token );
        $body  = Unsubscribe::refreshLinks( (string) ( $m['body'] ?? '' ), $token );
        try {
            $res = $this->mailer->send( array_merge( $m, [ 'body' => $body ] ), $contact, $url );
        } catch ( \Throwable $e ) {
            $res = [ 'ok' => false, 'message_id' => '', 'error' => 'Sending failed: ' . $e->getMessage() ];
        }
        if ( ! $res['ok'] ) {
            $this->messages->setStatus( $id, OutreachMessages::STATUS_FAILED, [ 'last_error' => $res['error'] ] );
            $this->audit->record( $this->actor( $userId ), 'outreach.send_failed', 'outreach_message', $id, [ 'error' => $res['error'], 'campaign_id' => $campaignId ] );
            $this->notify( 'outreach_failed', 'warning', 'Outreach email failed to send', sprintf( 'Message #%d could not be sent: %s', $id, $res['error'] ), [ 'message_id' => $id ] );
            return [ 'failed', $res['error'] ];
        }
        $now  = OutreachMessages::now();
        $sent = [ 'sent_at' => $now, 'message_id' => $res['message_id'], 'last_error' => '' ];
        if ( $body !== (string) ( $m['body'] ?? '' ) ) {
            $sent['body'] = $body; // the record keeps what was actually sent (refreshed unsubscribe link)
        }
        $this->messages->setStatus( $id, OutreachMessages::STATUS_SENT, $sent );
        $this->limits->spend( 'outreach_emails' );
        if ( $campaign && Campaigns::STATUS_APPROVED === (string) $campaign['status'] ) {
            $this->campaigns->setStatus( $campaignId, Campaigns::STATUS_SENDING );
            unset( $this->campaignCache[ $campaignId ] );
        }
        if ( $prospect && in_array( (string) $prospect['status'], [ Prospects::STATUS_QUALIFIED, Prospects::STATUS_APPROVED ], true ) ) {
            $this->prospects->setStatus( $prospectId, Prospects::STATUS_IN_PROGRESS );
        }
        $this->audit->record( $this->actor( $userId, 'system:outreach' ), 'outreach.sent', 'outreach_message', $id, [ 'campaign_id' => $campaignId, 'prospect_id' => $prospectId, 'contact_id' => (int) $contact['id'], 'approach' => $m['approach'] ?? '', 'sequence' => (int) ( $m['sequence'] ?? 1 ), 'message_id' => $res['message_id'] ] );
        return [ 'sent', '' ];
    }

    // --- Selection helpers --------------------------------------------------

    /**
     * Best active contact for a prospect: role fit for the approach, then confidence.
     *
     * @return array<string,mixed>|null
     */
    private function bestContact( int $prospectId, string $approach ): ?array {
        $roles = self::APPROACH_ROLES[ $approach ] ?? self::DEFAULT_ROLES;
        $best  = null;
        $bestS = -1;
        foreach ( $this->contacts->forProspect( $prospectId, true ) as $c ) {
            $role = strtolower( (string) ( $c['role'] ?? '' ) );
            if ( in_array( $role, Compliance::BLOCKED_ROLES, true ) ) {
                continue;
            }
            if ( ! $this->compliance->contactAllowed( $c )['ok'] ) {
                continue;
            }
            $rank  = array_search( $role, $roles, true );
            $bonus = false !== $rank ? ( count( $roles ) - (int) $rank ) * 10 : 0;
            $score = (int) ( $c['confidence'] ?? 0 ) + $bonus;
            if ( $score > $bestS ) {
                $bestS = $score;
                $best  = $c;
            }
        }
        return $best;
    }

    /**
     * Best open opportunity matching the approach (type preference order, then score).
     *
     * @return array<string,mixed>|null
     */
    private function bestOpportunity( int $prospectId, string $approach ): ?array {
        $types = self::APPROACH_OPPORTUNITIES[ $approach ] ?? [];
        if ( [] === $types ) {
            return null;
        }
        $best  = null;
        $bestS = PHP_INT_MIN;
        foreach ( $this->opportunities->forProspect( $prospectId ) as $o ) {
            if ( ! in_array( (string) ( $o['status'] ?? '' ), [ Opportunities::STATUS_OPEN, Opportunities::STATUS_PACKAGED ], true ) ) {
                continue;
            }
            $rank = array_search( (string) ( $o['type'] ?? '' ), $types, true );
            if ( false === $rank ) {
                continue;
            }
            $score = ( count( $types ) - (int) $rank ) * 1000 + (int) ( $o['score'] ?? 0 );
            if ( $score > $bestS ) {
                $bestS = $score;
                $best  = $o;
            }
        }
        return $best;
    }

    /**
     * Destination-specific generated copy (deterministic variety per prospect domain).
     *
     * @param array<string,mixed>  $prospect
     * @param array<string,string> $ctx
     * @return array<string,string>
     */
    private function generatedContent( array $prospect, string $approach, array $ctx, string $variantBody ): array {
        $out    = [];
        $domain = (string) ( $prospect['domain'] ?? '' );
        try {
            if ( 'directory_inclusion' === $approach ) {
                $out['short_description'] = $this->content->shortDescription( $domain, 200 );
            } elseif ( 'partnership' === $approach ) {
                // The template's {{observation}} already describes the partner, so no extra partner context is injected.
                $out['partnership_proposal'] = $this->content->partnershipProposal( $domain, (string) ( $ctx['site_name'] ?? 'your organisation' ), '' );
            }
            foreach ( $out as $key => $text ) {
                // When the chosen variant embeds generated copy that already carries the research-use positioning
                // sentence, drop the separate {{research_note}} so the email does not say it twice.
                if ( str_contains( $variantBody, '{{' . $key . '}}' ) && preg_match( '/research use only|research use and|not for human/i', $text ) ) {
                    $out['research_note'] = '';
                    break;
                }
            }
        } catch ( \Throwable $e ) {
            $this->logger->warning( 'outreach', 'Content generation failed; using template fallbacks: ' . $e->getMessage() );
            return [];
        }
        return $out;
    }

    /** @param array<string,string> $ctx */
    private function aiPersonalize( string $body, array $ctx, bool $interactive = false ): string {
        try {
            if ( ! class_exists( Plugin::class ) ) {
                return $body;
            }
            $container = Plugin::instance()->container();
            if ( ! $container->has( AiWriter::class ) || ! $container->has( ClaimsFilter::class ) ) {
                return $body;
            }
            /** @var AiWriter $ai */
            $ai = $container->get( AiWriter::class );
            if ( $interactive ) {
                $ai = $ai->interactive(); // a copy: background jobs keep the longer timeout
            }
            /** @var ClaimsFilter $filter */
            $filter = $container->get( ClaimsFilter::class );
            return $this->personalizer->aiPersonalize( $body, $ctx, $ai, $filter );
        } catch ( \Throwable $e ) {
            $this->logger->warning( 'outreach', 'AI personalisation skipped: ' . $e->getMessage() );
            return $body;
        }
    }

    /** The compliance footer for $token under the current settings (the same text a new draft gets). */
    private function footer( string $token ): string {
        return $this->personalizer->footer( Unsubscribe::urlFor( $token ) );
    }

    /**
     * $body with its compliance footer for $token brought up to date (refreshFooter(), updateMessage()).
     *
     * The footer block (FOOTER_BLOCK_RE) runs from the last "<brand> · <postal address>" line, through the lines its
     * address continues on and any blank lines an edit added, to the "If you'd rather not hear from us again" line
     * that carries this token's unsubscribe link (the current URL, or one built under an earlier site URL / permalink
     * setting, see Unsubscribe::refreshLinks()). It is replaced whole by footer(), so no line of the old address is
     * left, and older copies of the block for the same token above it (an edit saved by 1.2.0 appended a second footer
     * under the stale one) are removed. A body without such a block that lacks the current address or link
     * (Compliance::bodyHasFooter()) gets the footer where bareFooterSpan() says, or appended; when that cannot be done
     * without guessing which edited line held the old address, the body is left as it is, so approval and sending
     * refuse it (with FOOTER_EDIT_HINT) instead of mailing the old address above the current one. The rest of the body
     * stays byte for byte as it was, and a body whose own wording already carries the current address and link is
     * left alone. While no postal address is configured an existing block is not rewritten (nothing is sent without
     * one, and the old address is not stripped); a missing footer is still appended.
     */
    private function withCurrentFooter( string $body, string $token ): string {
        $token = strtolower( trim( $token ) );
        $url   = preg_match( '/^[a-f0-9]{32}$/', $token ) ? Unsubscribe::urlFor( $token ) : '';
        if ( '' === $url ) {
            return $body;
        }
        $footer   = $this->footer( $token );
        $complete = $this->compliance->bodyHasFooter( $body, $token );
        if ( '' === $this->compliance->postalAddress() ) {
            return $complete ? $body : rtrim( $body ) . "\n\n" . $footer;
        }
        $spans = $this->footerSpans( $body, $token, $url );
        if ( [] === $spans ) {
            if ( $complete ) {
                return $body;
            }
            $span = $this->bareFooterSpan( $body, $token, $url );
            if ( null === $span ) {
                return $body;
            }
            if ( [] === $span ) {
                return rtrim( $body ) . "\n\n" . $footer;
            }
            $spans = [ $span ];
        }
        [ $offset, $length ] = array_pop( $spans );
        $out                 = substr( $body, 0, $offset ) . $footer . substr( $body, $offset + $length );
        // Earlier copies, last first so the offsets before them stay valid, each with the line breaks after it.
        foreach ( array_reverse( $spans ) as [ $offset, $length ] ) {
            $end = $offset + $length;
            $out = substr( $out, 0, $offset ) . substr( $out, $end + strspn( $out, "\r\n", $end ) );
        }
        return $out;
    }

    /**
     * Byte offset and length of each footer block (FOOTER_BLOCK_RE) in $body whose unsubscribe line carries this
     * token's link. A signature line that has a "·" is not taken for the footer's first line when it is not directly
     * above the unsubscribe line (the owner deleted the footer's own first line; the signature stays).
     *
     * @return array<int,array{0:int,1:int}>
     */
    private function footerSpans( string $body, string $token, string $url ): array {
        if ( ! preg_match_all( self::FOOTER_BLOCK_RE, $body, $hits, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            return [];
        }
        $out = [];
        foreach ( $hits as $hit ) {
            if ( ! self::carriesLink( (string) $hit['link'][0], $token, $url ) ) {
                continue;
            }
            if ( '' !== (string) $hit['more'][0] . (string) $hit['gap'][0] && $this->isSignatureLine( (string) $hit['brand'][0] ) ) {
                continue;
            }
            $out[] = [ (int) $hit[0][1], strlen( (string) $hit[0][0] ) ];
        }
        return $out;
    }

    /**
     * Where the current footer goes in a body that has no footer block for this token and lacks the current address
     * or link: the last unsubscribe line for this token when it starts a paragraph (the "<brand> · <address>" line
     * above it was deleted), or, with no such line, a "<brand> · <address>" line that ends the body (the unsubscribe
     * line under it was deleted). [] means the footer is appended (there is none). null means it cannot be done
     * without guessing: the line directly above the unsubscribe line is not a "<brand> · <address>" line (it was
     * edited, and may still hold the old address), or the link sits in the owner's own wording without the address.
     *
     * @return array{0:int,1:int}|array{}|null
     */
    private function bareFooterSpan( string $body, string $token, string $url ): ?array {
        $lines = [];
        if ( preg_match_all( self::UNSUBSCRIBE_LINE_RE, $body, $hits, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            foreach ( $hits as $hit ) {
                if ( self::carriesLink( (string) $hit['link'][0], $token, $url ) ) {
                    $lines[] = [ (int) $hit[0][1], strlen( (string) $hit[0][0] ) ];
                }
            }
        }
        if ( [] !== $lines ) {
            $line  = end( $lines );
            $above = substr( $body, 0, $line[0] );
            return '' === trim( $above ) || 1 === preg_match( '/\n[ \t]*\r?\n\z/', $above ) ? $line : null;
        }
        if ( self::carriesLink( $body, $token, $url ) ) {
            return null;
        }
        if ( preg_match( self::TRAILING_BRAND_RE, $body, $hit, PREG_OFFSET_CAPTURE ) && ! $this->isSignatureLine( (string) $hit['brand'][0] ) ) {
            return [ (int) $hit[0][1], strlen( $body ) - (int) $hit[0][1] ];
        }
        return [];
    }

    /** Whether $text carries this token's unsubscribe link (the current URL, or one Unsubscribe::refreshLinks() updates). */
    private static function carriesLink( string $text, string $token, string $url ): bool {
        return str_contains( Unsubscribe::refreshLinks( $text, $token ), $url );
    }

    /** Whether $line is a line of the signature drafts get now. */
    private function isSignatureLine( string $line ): bool {
        $line = trim( $line );
        return '' !== $line && in_array( $line, array_map( 'trim', explode( "\n", $this->personalizer->currentSignature() ) ), true );
    }

    /**
     * What updateMessage() reports when the saved body still lacks the current postal address or the unsubscribe
     * link (withCurrentFooter() left an edited footer as it was): the violation approval would give, and what to do.
     *
     * @return string[]
     */
    private function footerProblems( string $body, string $token ): array {
        $url = Unsubscribe::urlFor( $token );
        if ( '' === $url || $this->compliance->bodyHasFooter( $body, $token ) ) {
            return [];
        }
        return [ self::carriesLink( $body, $token, $url ) ? 'Body must contain the sender postal address.' : 'Body must contain the unsubscribe link.', self::FOOTER_EDIT_HINT ];
    }

    /**
     * $violations followed by FOOTER_EDIT_HINT when they include a missing postal address or unsubscribe link: the
     * footer was refreshed just before the check, so what is left is an edit only a person can sort out.
     *
     * @param string[] $violations
     * @return string[]
     */
    private static function withFooterHint( array $violations ): array {
        $footer = array_intersect( [ 'Body must contain the sender postal address.', 'Body must contain the unsubscribe link.' ], $violations );
        return [] !== $footer && ! in_array( self::FOOTER_EDIT_HINT, $violations, true ) ? array_merge( $violations, [ self::FOOTER_EDIT_HINT ] ) : $violations;
    }

    /** @param array<string,mixed> $settings */
    private function cleanCampaignSettings( array $settings ): array {
        $daily = isset( $settings['daily_limit'] ) ? (int) $settings['daily_limit'] : 10;
        return [
            'daily_limit'    => max( 1, min( 200, $daily ) ),
            'follow_ups'     => array_key_exists( 'follow_ups', $settings ) ? (bool) filter_var( $settings['follow_ups'], FILTER_VALIDATE_BOOLEAN ) : true,
            'auto_follow_up' => array_key_exists( 'auto_follow_up', $settings ) ? (bool) filter_var( $settings['auto_follow_up'], FILTER_VALIDATE_BOOLEAN ) : false,
            'notes'          => mb_substr( trim( sanitize_textarea_field( (string) ( $settings['notes'] ?? '' ) ) ), 0, 2000 ),
        ];
    }

    /** @return array<string,mixed>|null */
    private function campaignCached( int $id ): ?array {
        if ( ! array_key_exists( $id, $this->campaignCache ) ) {
            $this->campaignCache[ $id ] = $this->campaigns->find( $id );
        }
        return $this->campaignCache[ $id ];
    }

    private function cleanSubject( string $subject ): string {
        $subject = wp_strip_all_tags( $subject );
        $subject = trim( preg_replace( '/[\r\n\t]+|\s{2,}/', ' ', $subject ) ?? $subject );
        return mb_substr( $subject, 0, 255 );
    }

    private function cleanBody( string $body ): string {
        $body = wp_check_invalid_utf8( $body );
        $body = str_replace( [ "\r\n", "\r" ], "\n", $body );
        if ( preg_match( '/<[a-z\/!][^>]*>/i', $body ) ) {
            $body = wp_strip_all_tags( $body );
        }
        $body = preg_replace( '/[ \t]+$/m', '', $body ) ?? $body;
        $body = preg_replace( "/\n{3,}/", "\n\n", $body ) ?? $body;
        return trim( $body );
    }

    /**
     * Audit actor of an approval: the person, or "system:autopilot" when automation approves under Autopilot (so
     * switching Autopilot off finds what it approved), or the plain automation actor otherwise.
     */
    private function approver( int $userId ): string {
        if ( $userId > 0 ) {
            return 'user:' . $userId;
        }
        return $this->autopilot()->isOn() ? Autopilot::ACTOR : 'system';
    }

    /**
     * The open (not yet sent) first message of a website in any campaign, or null.
     *
     * @return array<string,mixed>|null
     */
    private function openMessageFor( int $prospectId ): ?array {
        $rows = $this->messages->findBy( [ 'prospect_id' => $prospectId, 'status' => [ OutreachMessages::STATUS_DRAFT, OutreachMessages::STATUS_APPROVED, OutreachMessages::STATUS_SCHEDULED, self::STATUS_SENDING ] ], 'id', 'ASC', 1 );
        return $rows[0] ?? null;
    }

    /** ' (draft #12 in campaign "Auto: Resource page inclusion")' for a skip reason. @param array<string,mixed> $m */
    private function whereMessage( array $m ): string {
        $campaign = (int) ( $m['campaign_id'] ?? 0 ) > 0 ? $this->campaignCached( (int) $m['campaign_id'] ) : null;
        $what     = sprintf( '%s #%d', (string) ( $m['status'] ?? 'message' ), (int) ( $m['id'] ?? 0 ) );
        return null !== $campaign ? sprintf( ' (%s in campaign "%s")', $what, (string) ( $campaign['name'] ?? '' ) ) : ' (' . $what . ')';
    }

    private function actor( int $userId, string $fallback = '' ): string {
        if ( $userId > 0 ) {
            return 'user:' . $userId;
        }
        return '' !== $fallback ? $fallback : AuditLog::currentActor();
    }

    /** @param array<string,mixed> $data */
    private function notify( string $type, string $severity, string $title, string $message, array $data = [] ): void {
        try {
            $this->notifier->notify( $type, $severity, $title, $message, $data );
        } catch ( \Throwable $e ) {
            $this->logger->warning( 'outreach', 'Notification failed: ' . $e->getMessage() );
        }
    }
}
