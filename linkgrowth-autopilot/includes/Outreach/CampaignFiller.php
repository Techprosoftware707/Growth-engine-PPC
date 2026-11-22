<?php
/**
 * Fills outreach campaigns with drafts and says what happens to each one next.
 *
 * Creating a campaign used to insert an empty draft row and nothing else ("Campaign created as a draft … No messages
 * yet"). fill() now builds drafts at once for every eligible prospect that matches the campaign's approach, within a
 * time budget; AutoOutreachJob continues the rest in the background for every campaign (the owner's as well as the
 * evergreen "Auto:" ones). Under Autopilot the campaign and its drafts are approved straight away, so the send stage
 * mails them inside the caps and the send window; otherwise the card says how many drafts wait for the owner.
 *
 * Eligibility (shared with AutoOutreachJob through eligibilityClauses()): a qualified / approved / in-progress
 * website whose best opportunity fits the approach, with an active, conspicuously published, non-support contact
 * with a recognised consent basis, no open message in any campaign (one open message per website), not contacted
 * within outreach.min_days_between_contacts, not on the domain suppression list, not yet contacted with this
 * approach, and not an owner task imported from the verified database (as before: those are the owner's call).
 * The draft itself re-checks the contact (suppression, consent) in OutreachEngine::draft().
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Outreach;

use LinkGrowth\Autopilot\Automation\Autopilot;
use LinkGrowth\Autopilot\Automation\Jobs\AutoOutreachJob;
use LinkGrowth\Autopilot\Compliance\PositioningGate;
use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Database\Schema;
use LinkGrowth\Autopilot\Discovery\CatalogImporter;
use LinkGrowth\Autopilot\Repositories\Campaigns;
use LinkGrowth\Autopilot\Repositories\Contacts;
use LinkGrowth\Autopilot\Repositories\Opportunities;
use LinkGrowth\Autopilot\Repositories\OutreachMessages;
use LinkGrowth\Autopilot\Repositories\Prospects;
use LinkGrowth\Autopilot\Support\Limits;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\TimeBudget;
use Throwable;

final class CampaignFiller {

    /** Name prefix of the evergreen campaigns AutoOutreachJob maintains. */
    public const AUTO_PREFIX = 'Auto: ';

    /** Website states a campaign may draft for. */
    public const PROSPECT_STATUSES = [ Prospects::STATUS_QUALIFIED, Prospects::STATUS_APPROVED, Prospects::STATUS_IN_PROGRESS ];

    /** Campaign states that get no new drafts. */
    public const HELD = [ Campaigns::STATUS_PAUSED, Campaigns::STATUS_ARCHIVED, Campaigns::STATUS_COMPLETED ];

    /** Message states that count as an open conversation. */
    private const OPEN = [ OutreachMessages::STATUS_DRAFT, OutreachMessages::STATUS_APPROVED, OutreachMessages::STATUS_SCHEDULED, OutreachMessages::STATUS_SENDING ];

    /** last_error prefixes of drafts a person has to edit first; automation does not retry approving them. */
    public const NEEDS_EDIT = [ 'Needs editing', 'Not approved:' ];

    /**
     * "Not approved:" notes (prefixes) that 1.2.0 builds wrote for problems that clear without editing the draft: the
     * sender identity and the contact cool-down. New notes of that kind use OutreachEngine::WAITING_PREFIX; these
     * older ones are retried too (and counted as waiting) instead of being left for a person forever; a retry that
     * still fails for another reason writes that reason, which then counts as needing an edit.
     */
    public const TRANSIENT_NOT_APPROVED = [ 'Not approved: The sender email domain', 'Not approved: Sender identity incomplete', 'Not approved: This website was contacted on' ];

    /**
     * The whole notes 1.2.0 wrote for a footer rendered before the postal address, brand or site URL changed. Since
     * 1.2.1 OutreachEngine::refreshFooter() rebuilds the footer before every approval, so these drafts are retried
     * (and counted as waiting) like TRANSIENT_NOT_APPROVED. They are matched whole: a note with anything more (another
     * problem, or the OutreachEngine::FOOTER_EDIT_HINT a 1.2.1 approval adds when the rebuilt footer still fails)
     * needs a person, so a draft whose footer cannot be updated is never retried on every run.
     */
    public const STALE_FOOTER_NOTES = [
        'Not approved: Body must contain the sender postal address.',
        'Not approved: Body must contain the unsubscribe link.',
        'Not approved: Body must contain the unsubscribe link. Body must contain the sender postal address.',
    ];

    /** Most candidates looked at per fill. */
    private const MAX_CANDIDATES = 200;

    /** Prospects drafted per batch between time-budget checks. */
    private const BATCH = 10;

    public function __construct( private Container $c ) {}

    public static function isAutoCampaign( array $campaign ): bool {
        $settings = is_array( $campaign['settings'] ?? null ) ? $campaign['settings'] : [];
        return ! empty( $settings['auto_created'] ) || str_starts_with( (string) ( $campaign['name'] ?? '' ), self::AUTO_PREFIX );
    }

    // ------------------------------------------------------------------
    // Filling
    // ------------------------------------------------------------------

    /**
     * Drafts for every eligible prospect matching the campaign's approach (within the budget and $max), adopts the
     * unsent drafts an "Auto:" campaign holds for this approach (so the owner's campaign gets its own prospects
     * instead of "already exists" skips), and under Autopilot approves the campaign and its drafts.
     *
     * @return array{drafted:int,adopted:int,eligible:int,remaining:int,approved:int,campaign_approved:bool,reasons:array<string,int>,explanation:string}
     */
    public function fill( int $campaignId, TimeBudget $budget, int $userId = 0, int $max = 40 ): array {
        $out = [ 'drafted' => 0, 'adopted' => 0, 'eligible' => 0, 'remaining' => 0, 'approved' => 0, 'campaign_approved' => false, 'reasons' => [], 'explanation' => '' ];
        try {
            $campaign = $this->campaigns()->find( $campaignId );
            if ( null === $campaign ) {
                $out['explanation'] = 'The campaign no longer exists.';
                return $out;
            }
            $status = (string) $campaign['status'];
            if ( in_array( $status, self::HELD, true ) ) {
                $out['reasons']['campaign_' . $status] = 1;
                $out['explanation']                     = sprintf( 'The campaign is %s, so no drafts are added.', $status );
                return $out;
            }
            $approach = (string) ( $campaign['approach'] ?? '' );
            if ( ! Templates::isApproach( $approach ) ) {
                $out['explanation'] = 'The campaign has an unknown approach.';
                return $out;
            }
            $auto = self::isAutoCampaign( $campaign );
            if ( ! $auto ) {
                $out['adopted'] = $this->adoptAutoDrafts( $campaignId, $approach );
            }
            // Engine-drafted cold email that nobody reviews goes only to sites at or above the auto-queue score: the
            // "Auto:" campaigns always, the owner's campaigns while Autopilot approves them.
            $minScore = $auto || $this->autopilot()->autoApproveOwnerCampaigns() ? $this->autoQueueScore() : 0;
            $ids      = $this->candidateIds( $approach, $minScore, self::MAX_CANDIDATES );
            $out['eligible'] = count( $ids ) + $out['adopted'];

            $engine = $this->engine();
            $chunks = array_chunk( $ids, self::BATCH );
            foreach ( $chunks as $i => $chunk ) {
                if ( $out['drafted'] >= $max || ( $i > 0 && ! $budget->has( 3.0 ) ) ) {
                    foreach ( array_slice( $chunks, $i ) as $rest ) {
                        $out['remaining'] += count( $rest );
                    }
                    break;
                }
                $chunk  = array_slice( $chunk, 0, max( 1, $max - $out['drafted'] ) );
                $result = $engine->buildDrafts( $campaignId, $chunk, $userId );
                $out['drafted'] += (int) ( $result['created'] ?? 0 );
                foreach ( (array) ( $result['skipped'] ?? [] ) as $reason ) {
                    $key                    = self::reasonKey( (string) $reason );
                    $out['reasons'][ $key ] = ( $out['reasons'][ $key ] ?? 0 ) + 1;
                }
                $out['remaining'] += count( $chunks[ $i ] ) - count( $chunk );
            }
            // Approval needs a sender that is ready (identity complete, a domain that receives mail); without one the
            // campaign and its drafts wait (nextStep() says why) and are approved once the sender is fixed. Drafts
            // are approved only where automation approves them by itself (the "Auto:" campaigns, or the owner's
            // under Autopilot): without Autopilot the owner's drafts wait for the owner, as before 1.2.0.
            $senderOk = $this->compliance()->senderReady()['ok'];
            if ( $senderOk && $this->mayApproveCampaign( $campaign ) ) {
                $out['campaign_approved'] = $this->approveCampaign( $campaignId );
            }
            if ( $senderOk && $this->autoApprovesIn( $campaign ) ) {
                $out['approved'] = $this->approveDrafts( $campaignId, $budget );
            }
            if ( 0 === $out['eligible'] ) {
                $out['explanation'] = $this->emptyExplanation( $approach, $auto ? $minScore : 0 );
            }
        } catch ( Throwable $e ) {
            $this->logger()->error( 'outreach', 'Filling campaign #' . $campaignId . ' failed: ' . $e->getMessage() );
            $out['explanation'] = 'Drafts could not be built: ' . $e->getMessage();
        }
        return $out;
    }

    /**
     * Background pass over the owner's campaigns (not the "Auto:" ones, which AutoOutreachJob fills by itself):
     * fills each active one, oldest first, $firstId first.
     *
     * @return array{campaigns:int,drafted:int,adopted:int,remaining:int,approved:int}
     */
    public function fillOwnerCampaigns( TimeBudget $budget, int $maxDrafts, int $firstId = 0 ): array {
        $out = [ 'campaigns' => 0, 'drafted' => 0, 'adopted' => 0, 'remaining' => 0, 'approved' => 0 ];
        $rows = $this->campaigns()->findBy( [ 'status' => [ Campaigns::STATUS_DRAFT, Campaigns::STATUS_PENDING_APPROVAL, Campaigns::STATUS_APPROVED, Campaigns::STATUS_SENDING ] ], 'id', 'ASC', 100 );
        usort( $rows, static fn( array $a, array $b ): int => ( (int) $b['id'] === $firstId ) <=> ( (int) $a['id'] === $firstId ) );
        foreach ( $rows as $row ) {
            if ( self::isAutoCampaign( $row ) ) {
                continue;
            }
            if ( $out['drafted'] >= $maxDrafts || ! $budget->has( 4.0 ) ) {
                break;
            }
            $r = $this->fill( (int) $row['id'], $budget, 0, $maxDrafts - $out['drafted'] );
            $out['campaigns']++;
            $out['drafted']   += $r['drafted'];
            $out['adopted']   += $r['adopted'];
            $out['remaining'] += $r['remaining'];
            $out['approved']  += $r['approved'];
        }
        return $out;
    }

    /**
     * Approves what may be approved automatically right now (used by AutoOutreachJob on every run, so drafts that
     * were waiting when Autopilot was switched on are not left behind): campaigns in draft / pending approval
     * (the owner's only under Autopilot, the "Auto:" ones whenever autosend is effectively on), then their first-
     * contact drafts when automation may approve messages. A paused campaign is never touched.
     *
     * @return array{campaigns:int,messages:int}
     */
    public function approveWaiting( TimeBudget $budget, int $max = 50 ): array {
        $out = [ 'campaigns' => 0, 'messages' => 0 ];
        // The approval policy as stored now (this run may have started before the owner switched Autopilot off).
        $this->settings()->refresh();
        if ( ! $this->compliance()->senderReady()['ok'] ) {
            return $out;
        }
        foreach ( $this->campaigns()->findBy( [ 'status' => [ Campaigns::STATUS_DRAFT, Campaigns::STATUS_PENDING_APPROVAL ] ], 'id', 'ASC', 100 ) as $row ) {
            if ( $this->mayApproveCampaign( $row ) && $this->approveCampaign( (int) $row['id'] ) ) {
                $out['campaigns']++;
            }
        }
        if ( ! $this->autopilot()->autoApproveMessages() ) {
            return $out;
        }
        foreach ( $this->campaigns()->findBy( [ 'status' => [ Campaigns::STATUS_APPROVED, Campaigns::STATUS_SENDING ] ], 'id', 'ASC', 100 ) as $row ) {
            if ( $out['messages'] >= $max || ! $budget->has( 2.0 ) ) {
                break;
            }
            if ( ! self::isAutoCampaign( $row ) && ! $this->autopilot()->autoApproveOwnerCampaigns() ) {
                continue; // without Autopilot, drafts in the owner's own campaigns keep waiting for the owner
            }
            $out['messages'] += $this->approveDrafts( (int) $row['id'], $budget, $max - $out['messages'] );
        }
        return $out;
    }

    /**
     * Approves (as automation) the drafts of one campaign that do not need editing: first contacts to sites at or
     * above the auto-queue score, and follow-ups only under Autopilot with reply tracking (without it a follow-up
     * could reach someone who already replied).
     */
    public function approveDrafts( int $campaignId, TimeBudget $budget, int $max = 50 ): int {
        $n         = 0;
        $criteria  = [ 'campaign_id' => $campaignId, 'status' => OutreachMessages::STATUS_DRAFT ];
        $followUps = $this->autoApprovesFollowUps();
        if ( ! $followUps ) {
            $criteria['sequence'] = 1;
        }
        $scores = [];
        foreach ( $this->messages()->findBy( $criteria, 'id', 'ASC', 500 ) as $m ) {
            if ( $n >= $max || ! $budget->has( 1.0 ) ) {
                break;
            }
            if ( self::needsEdit( (string) ( $m['last_error'] ?? '' ) ) ) {
                continue;
            }
            if ( (int) ( $m['sequence'] ?? 1 ) <= 1 && $this->belowAutoBar( (int) ( $m['prospect_id'] ?? 0 ), $scores ) ) {
                continue; // waits for the owner (nextStep() says so)
            }
            $r = $this->engine()->approveMessage( (int) $m['id'], 0 );
            if ( ! empty( $r['ok'] ) ) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Prospects eligible for a first message with this approach, best first.
     *
     * @return int[]
     */
    public function candidateIds( string $approach, int $minScore = 0, int $limit = self::MAX_CANDIDATES ): array {
        global $wpdb;
        [ $match, $matchParams ] = $this->approachClause( $approach );
        [ $elig, $eligParams ]   = self::eligibilityClauses( $this->settings(), [ $approach ] );
        $p      = Schema::table( 'prospects' );
        $sql    = "SELECT p.id FROM {$p} p WHERE p.status IN (" . self::placeholders( self::PROSPECT_STATUSES ) . ') AND ' . $match . $elig;
        $params = array_merge( self::PROSPECT_STATUSES, $matchParams, $eligParams );
        if ( $minScore > 0 ) {
            $sql     .= ' AND p.score >= %d';
            $params[] = $minScore;
        }
        $sql     .= ' ORDER BY p.score DESC, p.id ASC LIMIT %d';
        $params[] = max( 1, $limit );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return array_values( array_unique( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) ) ) );
    }

    /**
     * Slim rows of the eligible prospects, for the Build drafts picker.
     *
     * @return array<int,array<string,mixed>>
     */
    public function candidates( string $approach, int $limit = 100 ): array {
        $ids = $this->candidateIds( $approach, 0, $limit );
        if ( [] === $ids ) {
            return [];
        }
        $rows = [];
        foreach ( $this->prospects()->findBy( [ 'id' => $ids ], 'id', 'ASC', count( $ids ) ) as $row ) {
            $rows[ (int) $row['id'] ] = [
                'id'               => (int) $row['id'],
                'domain'           => (string) ( $row['domain'] ?? '' ),
                'name'             => (string) ( $row['name'] ?? '' ),
                'score'            => isset( $row['score'] ) ? (int) $row['score'] : null,
                'status'           => (string) ( $row['status'] ?? '' ),
                'opportunity_type' => (string) ( $row['opportunity_type'] ?? '' ),
            ];
        }
        $out = [];
        foreach ( $ids as $id ) {
            if ( isset( $rows[ $id ] ) ) {
                $out[] = $rows[ $id ];
            }
        }
        return $out;
    }

    /**
     * SQL (" AND …" clauses on prospects alias p) shared with AutoOutreachJob: published active contact, no open
     * message anywhere, contact cool-down, domain suppression, verified-database owner tasks excluded, and no first
     * message yet with one of $approaches.
     *
     * @param string[] $approaches
     * @return array{0:string,1:array<int,mixed>}
     */
    public static function eligibilityClauses( Settings $settings, array $approaches = [] ): array {
        $m      = Schema::table( 'outreach_messages' );
        $ct     = Schema::table( 'contacts' );
        $s      = Schema::table( 'submissions' );
        $sup    = Schema::table( 'suppression' );
        $sql    = " AND EXISTS ( SELECT 1 FROM {$ct} c WHERE c.prospect_id = p.id AND c.status = %s"
            . ' AND c.consent_basis IN (' . self::placeholders( Compliance::CONSENT_BASES ) . ')'
            . ' AND ( c.role IS NULL OR c.role NOT IN (' . self::placeholders( Compliance::BLOCKED_ROLES ) . ') ) )'
            . " AND NOT EXISTS ( SELECT 1 FROM {$m} o WHERE o.prospect_id = p.id AND o.status IN (" . self::placeholders( self::OPEN ) . ') )'
            . " AND NOT EXISTS ( SELECT 1 FROM {$sup} x WHERE x.type = %s AND x.value = p.domain )"
            // Prospects imported from the verified opportunity database are owner tasks (unchanged rule).
            . ' AND p.discovered_via <> %s'
            . " AND NOT EXISTS ( SELECT 1 FROM {$s} st WHERE st.prospect_id = p.id AND st.payload LIKE %s )";
        $params = array_merge( [ Contacts::STATUS_ACTIVE ], Compliance::CONSENT_BASES, Compliance::BLOCKED_ROLES, self::OPEN, [ 'domain', CatalogImporter::SOURCE, '%' . CatalogImporter::PAYLOAD_MARKER . '%' ] );
        $days   = max( 0, (int) $settings->get( 'outreach.min_days_between_contacts', 90 ) );
        if ( $days > 0 ) {
            $sql     .= " AND NOT EXISTS ( SELECT 1 FROM {$m} r WHERE r.prospect_id = p.id AND r.sent_at IS NOT NULL AND r.sent_at >= %s )";
            $params[] = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        }
        if ( [] !== $approaches ) {
            $sql   .= " AND NOT EXISTS ( SELECT 1 FROM {$m} f WHERE f.prospect_id = p.id AND f.sequence = 1 AND f.approach IN (" . self::placeholders( $approaches ) . ') )';
            $params = array_merge( $params, array_values( $approaches ) );
        }
        return [ $sql, $params ];
    }

    // ------------------------------------------------------------------
    // "What happens next"
    // ------------------------------------------------------------------

    /**
     * One plain line about the campaign's next step, plus the number of drafts waiting for the owner.
     *
     * @param array<string,mixed> $campaign Row (with counts when the caller has them).
     * @return array{state:string,text:string,waiting:int,needs_edit:int}
     */
    public function nextStep( array $campaign ): array {
        $id      = (int) ( $campaign['id'] ?? 0 );
        $status  = (string) ( $campaign['status'] ?? '' );
        $counts  = is_array( $campaign['counts'] ?? null ) ? array_map( 'intval', $campaign['counts'] ) : $this->messages()->countBy( 'status', 'campaign_id = %d', [ $id ] );
        $drafts  = (int) ( $counts[ OutreachMessages::STATUS_DRAFT ] ?? 0 );
        $ready   = (int) ( $counts[ OutreachMessages::STATUS_APPROVED ] ?? 0 ) + (int) ( $counts[ OutreachMessages::STATUS_SCHEDULED ] ?? 0 );
        $total   = (int) array_sum( $counts );
        $kinds   = $drafts > 0 ? $this->draftKinds( $id ) : [ 'edit' => 0, 'follow_ups' => 0, 'below_bar' => 0 ];
        $edit    = $kinds['edit'];
        $waiting = max( 0, $drafts - $edit );
        $auto    = $this->autoApprovesIn( $campaign );
        // Under automatic approval some drafts still wait for the owner: follow-ups without reply tracking (under
        // Autopilot) and first contacts to sites below the auto-queue score. They are named, never hidden.
        $heldFollowUps = $auto && ! $this->autoApprovesFollowUps() ? $kinds['follow_ups'] : 0;
        $heldBelowBar  = $auto ? $kinds['below_bar'] : 0;
        $held          = $heldFollowUps + $heldBelowBar;
        $autoWaiting   = max( 0, $waiting - $held );
        $out           = static fn( string $state, string $text ) => [ 'state' => $state, 'text' => $text, 'waiting' => $auto ? $held : $waiting, 'needs_edit' => $edit, 'follow_ups_waiting' => $heldFollowUps, 'below_bar' => $heldBelowBar ];

        if ( Campaigns::STATUS_ARCHIVED === $status ) {
            return $out( 'archived', 'Archived: nothing more is drafted or sent.' );
        }
        if ( Campaigns::STATUS_COMPLETED === $status ) {
            return $out( 'completed', 'Completed: nothing more is drafted or sent.' );
        }
        if ( Campaigns::STATUS_PAUSED === $status ) {
            return $out( 'paused', 'Paused by you: nothing is drafted or sent until you resume it.' );
        }
        $sender = $this->compliance()->senderReady();
        if ( empty( $sender['ok'] ) ) {
            $missing = (array) $sender['missing'];
            $why     = in_array( 'sender_email_domain', $missing, true ) ? 'the sender email domain cannot receive mail'
                : ( in_array( 'reply_to_domain', $missing, true ) ? 'the Reply-To address is on a domain that cannot receive mail'
                : 'the sender identity is incomplete (' . implode( ', ', array_map( static fn( $m ): string => str_replace( '_', ' ', (string) $m ), $missing ) ) . ')' );
            return $out( 'blocked', 'Paused: ' . $why . '. Fix it under Settings → Outreach; nothing is sent until then.' );
        }
        $storefront = $this->storefrontPause();
        if ( '' !== $storefront ) {
            return $out( 'blocked', $storefront );
        }
        if ( 0 === $total ) {
            $explanation = $this->emptyExplanation( (string) ( $campaign['approach'] ?? '' ), self::isAutoCampaign( $campaign ) ? (int) $this->settings()->get( 'automation.min_score_auto_queue', 70 ) : 0 );
            return $out( 'empty', '' !== $explanation ? $explanation : 'No messages yet; the engine adds drafts as it finds matching prospects.' );
        }
        if ( ! $auto && in_array( $status, [ Campaigns::STATUS_DRAFT, Campaigns::STATUS_PENDING_APPROVAL ], true ) ) {
            $text = 'Waiting for your approval: approve the campaign';
            $text .= $waiting > 0 ? sprintf( ' and its %d %s', $waiting, 1 === $waiting ? 'draft' : 'drafts' ) : '';
            return $out( 'waiting', $text . ' so the engine can send them.' );
        }
        if ( $auto && in_array( $status, [ Campaigns::STATUS_DRAFT, Campaigns::STATUS_PENDING_APPROVAL ], true ) ) {
            // Nothing is sent from a campaign that is not approved yet; say so instead of "Sending automatically".
            return $out( 'approving', 'Not approved yet: automation approves this campaign and its drafts on the next outreach run, then sends them inside the send window and the daily limits.' );
        }
        if ( ! $auto && $waiting > 0 ) {
            return $out( 'waiting', sprintf( '%d %s waiting for your approval.', $waiting, 1 === $waiting ? 'draft is' : 'drafts are' ) );
        }
        $heldText = $this->heldText( $heldFollowUps, $heldBelowBar );
        if ( $ready > 0 || ( $auto && $autoWaiting > 0 ) ) {
            return $out( 'sending', $this->sendingText( $campaign ) . ( '' !== $heldText ? ' ' . $heldText : '' ) );
        }
        if ( $held > 0 ) {
            return $out( 'waiting', $heldText );
        }
        if ( $edit > 0 ) {
            return $out( 'needs_edit', self::needsEditText( $edit ) );
        }
        return $out( 'idle', $this->autopilot()->isOn() || self::isAutoCampaign( $campaign )
            ? 'Nothing waiting to send; new drafts are added as the engine finds matching prospects.'
            : 'Nothing waiting to send; new drafts are added automatically as the engine finds matching prospects.' );
    }

    /** "Sending automatically: 2 of 10 today; next send window Monday 09:00 Toronto time". */
    private function sendingText( array $campaign ): string {
        $id    = (int) ( $campaign['id'] ?? 0 );
        $limit = (int) ( $campaign['daily_limit'] ?? 0 );
        $sent  = isset( $campaign['sent_today'] ) ? (int) $campaign['sent_today'] : $this->messages()->sentTodayForCampaign( $id );
        $text  = 'Sending automatically: ' . ( $limit > 0 ? sprintf( '%d of %d today', $sent, $limit ) : sprintf( '%d today', $sent ) );
        $compliance = $this->compliance();
        $limits     = $this->c->get( Limits::class );
        if ( ! $limits->allow( 'outreach_emails' ) ) {
            return $text . '; the daily or weekly outreach email limit is reached, so sending resumes when it resets.';
        }
        if ( $limit > 0 && $sent >= $limit ) {
            return $text . '; this campaign reached its daily limit, so sending resumes tomorrow.';
        }
        if ( ! $compliance->withinSendWindow() ) {
            $next = $compliance->describeNextSendWindow();
            return $text . ( '' !== $next ? '; next send window ' . $next . '.' : '; the send window has no send days configured.' );
        }
        return $text . '; the send window is open now (' . $compliance->sendTimezoneInfo()['label'] . ').';
    }

    /** Plain reason a campaign of this approach has nobody to write to. */
    public function emptyExplanation( string $approach, int $minScore = 0 ): string {
        global $wpdb;
        try {
            $label                   = strtolower( Templates::label( $approach ) );
            [ $match, $matchParams ] = $this->approachClause( $approach );
            $p                       = Schema::table( 'prospects' );
            $ct                      = Schema::table( 'contacts' );
            $base                    = "SELECT COUNT(*) FROM {$p} p WHERE p.status IN (" . self::placeholders( self::PROSPECT_STATUSES ) . ') AND ' . $match;
            $params                  = array_merge( self::PROSPECT_STATUSES, $matchParams );
            if ( $minScore > 0 ) {
                $base    .= ' AND p.score >= %d';
                $params[] = $minScore;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $matching = (int) $wpdb->get_var( $wpdb->prepare( $base, $params ) );
            if ( 0 === $matching ) {
                return sprintf( 'No qualified prospects for %s yet; the engine adds them as it evaluates sites.', $label );
            }
            $withContact = $base . " AND EXISTS ( SELECT 1 FROM {$ct} c WHERE c.prospect_id = p.id AND c.status = %s )";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $contactable = (int) $wpdb->get_var( $wpdb->prepare( $withContact, array_merge( $params, [ Contacts::STATUS_ACTIVE ] ) ) );
            if ( 0 === $contactable ) {
                return sprintf( 'No qualified prospects with a published email address for %s yet; the engine adds them as it evaluates sites.', $label );
            }
            // Name the campaigns that already hold an open message for these websites (one open message per website).
            $m     = Schema::table( 'outreach_messages' );
            $cTbl  = Schema::table( 'campaigns' );
            $where = "SELECT DISTINCT c.name FROM {$m} o INNER JOIN {$cTbl} c ON c.id = o.campaign_id INNER JOIN {$p} p ON p.id = o.prospect_id"
                . ' WHERE o.status IN (' . self::placeholders( self::OPEN ) . ') AND p.status IN (' . self::placeholders( self::PROSPECT_STATUSES ) . ') AND ' . $match . ' LIMIT 3';
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $holders = array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( $where, array_merge( self::OPEN, self::PROSPECT_STATUSES, $matchParams ) ) ) );
            $verified = $base . ' AND p.discovered_via = %s';
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $owner = (int) $wpdb->get_var( $wpdb->prepare( $verified, array_merge( $params, [ CatalogImporter::SOURCE ] ) ) );
            $text  = sprintf(
                'All %d matching %s with a published email address already have a message (in this or another campaign), were contacted in the last %d days, or are owner tasks from the verified database.',
                $contactable,
                1 === $contactable ? 'prospect' : 'prospects',
                max( 0, (int) $this->settings()->get( 'outreach.min_days_between_contacts', 90 ) )
            );
            if ( [] !== $holders ) {
                $text .= ' Already being contacted from: ' . implode( ', ', array_map( static fn( string $n ): string => '“' . $n . '”', $holders ) ) . ' (one open message per website).';
            }
            return $owner > 0 ? $text . sprintf( ' %d of the matching prospects came from the verified database; those stay owner tasks.', $owner ) : $text;
        } catch ( Throwable $e ) {
            return '';
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Prospects whose best opportunity fits the approach: the prospect's opportunity type maps to it
     * (AutoOutreachJob::APPROACH_FOR_TYPE) or an open opportunity of a fitting type is recorded. Broken-link and
     * unlinked-mention pitches always need the recorded opportunity.
     *
     * @return array{0:string,1:array<int,string>}
     */
    private function approachClause( string $approach ): array {
        $types = [];
        foreach ( AutoOutreachJob::APPROACH_FOR_TYPE as $type => $a ) {
            if ( $a === $approach ) {
                $types[] = $type;
            }
        }
        $oppTypes = OutreachEngine::APPROACH_OPPORTUNITIES[ $approach ] ?? [];
        $o        = Schema::table( 'opportunities' );
        $parts    = [];
        $params   = [];
        if ( [] !== $types && ! in_array( $approach, OutreachEngine::REQUIRES_OPPORTUNITY, true ) ) {
            $parts[] = 'p.opportunity_type IN (' . self::placeholders( $types ) . ')';
            $params  = array_merge( $params, $types );
        }
        if ( [] !== $oppTypes ) {
            $parts[] = "EXISTS ( SELECT 1 FROM {$o} op WHERE op.prospect_id = p.id AND op.status IN (%s,%s) AND op.type IN (" . self::placeholders( $oppTypes ) . ') )';
            $params  = array_merge( $params, [ Opportunities::STATUS_OPEN, Opportunities::STATUS_PACKAGED ], $oppTypes );
        }
        if ( [] === $parts ) {
            return [ '1 = 0', [] ];
        }
        return [ '( ' . implode( ' OR ', $parts ) . ' )', $params ];
    }

    /** Moves unsent drafts for this approach out of the "Auto:" campaigns into the owner's campaign. */
    private function adoptAutoDrafts( int $campaignId, string $approach ): int {
        global $wpdb;
        $auto = [];
        foreach ( $this->campaigns()->findBy( [ 'approach' => $approach ], 'id', 'ASC', 50 ) as $row ) {
            if ( (int) $row['id'] !== $campaignId && self::isAutoCampaign( $row ) ) {
                $auto[] = (int) $row['id'];
            }
        }
        if ( [] === $auto ) {
            return 0;
        }
        $m   = $this->messages()->table();
        $sql = "UPDATE {$m} SET campaign_id = %d, updated_at = %s WHERE status = %s AND sequence = 1 AND approach = %s AND campaign_id IN (" . implode( ',', array_fill( 0, count( $auto ), '%d' ) ) . ')';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $n = (int) $wpdb->query( $wpdb->prepare( $sql, array_merge( [ $campaignId, OutreachMessages::now(), OutreachMessages::STATUS_DRAFT, $approach ], $auto ) ) );
        if ( $n > 0 ) {
            $this->logger()->info( 'outreach', sprintf( 'Campaign #%d took over %d unsent %s draft(s) from the Auto campaign.', $campaignId, $n, $approach ) );
        }
        return max( 0, $n );
    }

    /** @param array<string,mixed> $campaign */
    private function mayApproveCampaign( array $campaign ): bool {
        if ( ! in_array( (string) ( $campaign['status'] ?? '' ), [ Campaigns::STATUS_DRAFT, Campaigns::STATUS_PENDING_APPROVAL ], true ) ) {
            return false;
        }
        return self::isAutoCampaign( $campaign ) ? $this->autopilot()->autoApproveAutoCampaigns() : $this->autopilot()->autoApproveOwnerCampaigns();
    }

    /** Whether automation approves this campaign's drafts by itself. @param array<string,mixed> $campaign */
    private function autoApprovesIn( array $campaign ): bool {
        if ( ! $this->autopilot()->autoApproveMessages() ) {
            return false;
        }
        return self::isAutoCampaign( $campaign ) || $this->autopilot()->autoApproveOwnerCampaigns();
    }

    private function approveCampaign( int $id ): bool {
        return $this->engine()->approveCampaign( $id, 0 );
    }

    /** "2 drafts need editing (the claims filter or a compliance check flagged them) before they can be sent." */
    public static function needsEditText( int $edit ): string {
        return sprintf( '%d %s editing (the claims filter or a compliance check flagged %s) before %s can be sent.', $edit, 1 === $edit ? 'draft needs' : 'drafts need', 1 === $edit ? 'it' : 'them', 1 === $edit ? 'it' : 'they' );
    }

    /** What still waits for the owner while automation approves the rest ('' when nothing does). */
    private function heldText( int $followUps, int $belowBar ): string {
        $parts = [];
        if ( $followUps > 0 ) {
            $parts[] = sprintf( '%d follow-up %s for you: Autopilot sends follow-ups only once reply tracking is set up (the inbound webhook secret under Settings → Integrations → Email, RDAP & inbound replies), or approve %s yourself.', $followUps, 1 === $followUps ? 'draft waits' : 'drafts wait', 1 === $followUps ? 'it' : 'them' );
        }
        if ( $belowBar > 0 ) {
            $parts[] = sprintf( '%d %s to sites scoring below the auto-queue score (%d) %s for you: automation only emails sites at or above it without review.', $belowBar, 1 === $belowBar ? 'draft' : 'drafts', $this->autoQueueScore(), 1 === $belowBar ? 'waits' : 'wait' );
        }
        return implode( ' ', $parts );
    }

    /**
     * Drafts of one campaign by kind: needing an edit, follow-ups, and first contacts to sites below the
     * auto-queue score (both of the latter only among the drafts that do not need an edit).
     *
     * @return array{edit:int,follow_ups:int,below_bar:int}
     */
    private function draftKinds( int $campaignId ): array {
        $out    = [ 'edit' => 0, 'follow_ups' => 0, 'below_bar' => 0 ];
        $scores = [];
        foreach ( $this->messages()->findBy( [ 'campaign_id' => $campaignId, 'status' => OutreachMessages::STATUS_DRAFT ], 'id', 'ASC', 500 ) as $m ) {
            if ( self::needsEdit( (string) ( $m['last_error'] ?? '' ) ) ) {
                $out['edit']++;
            } elseif ( (int) ( $m['sequence'] ?? 1 ) > 1 ) {
                $out['follow_ups']++;
            } elseif ( $this->belowAutoBar( (int) ( $m['prospect_id'] ?? 0 ), $scores ) ) {
                $out['below_bar']++;
            }
        }
        return $out;
    }

    /** Whether a draft's last_error means a person has to edit it before it can be approved. */
    public static function needsEdit( string $lastError ): bool {
        if ( in_array( $lastError, self::STALE_FOOTER_NOTES, true ) ) {
            return false;
        }
        foreach ( self::TRANSIENT_NOT_APPROVED as $prefix ) {
            if ( str_starts_with( $lastError, $prefix ) ) {
                return false;
            }
        }
        foreach ( self::NEEDS_EDIT as $prefix ) {
            if ( str_starts_with( $lastError, $prefix ) ) {
                return true;
            }
        }
        return false;
    }

    private function autoQueueScore(): int {
        return max( 0, (int) $this->settings()->get( 'automation.min_score_auto_queue', 70 ) );
    }

    /** Follow-up drafts are approved automatically only under Autopilot with reply tracking. */
    private function autoApprovesFollowUps(): bool {
        return $this->autopilot()->isOn() && $this->autopilot()->replyTracking();
    }

    /** Whether the website of a first-contact draft scores below the auto-queue score. @param array<int,int> $scores */
    private function belowAutoBar( int $prospectId, array &$scores ): bool {
        $bar = $this->autoQueueScore();
        if ( $bar <= 0 || $prospectId <= 0 ) {
            return false;
        }
        if ( ! array_key_exists( $prospectId, $scores ) ) {
            $p                     = $this->prospects()->find( $prospectId );
            $scores[ $prospectId ] = null !== $p && isset( $p['score'] ) ? (int) $p['score'] : 0;
        }
        return $scores[ $prospectId ] < $bar;
    }

    private function storefrontPause(): string {
        try {
            $audit = $this->c->get( PositioningGate::class );
            if ( $audit->isBlocking() ) {
                return 'Paused: the storefront compliance check blocks outreach sending (review it on the Overview page).';
            }
        } catch ( Throwable $e ) {
            return 'Paused: the storefront compliance check could not run, so sending is paused until it does.';
        }
        return '';
    }

    /** Groups skip reasons for the summary ("…already exists (draft #12 in campaign …)" → one key). */
    private static function reasonKey( string $reason ): string {
        $reason = (string) preg_replace( '/\s*\(.*$/', '', $reason );
        return '' !== $reason ? $reason : 'skipped';
    }

    /** @param array<int,mixed> $values */
    private static function placeholders( array $values ): string {
        return implode( ',', array_fill( 0, max( 1, count( $values ) ), '%s' ) );
    }

    private function engine(): OutreachEngine {
        return $this->c->get( OutreachEngine::class );
    }

    private function autopilot(): Autopilot {
        return $this->c->get( Autopilot::class );
    }

    private function compliance(): Compliance {
        return $this->c->get( Compliance::class );
    }

    private function settings(): Settings {
        return $this->c->get( Settings::class );
    }

    private function campaigns(): Campaigns {
        return $this->c->get( Campaigns::class );
    }

    private function messages(): OutreachMessages {
        return $this->c->get( OutreachMessages::class );
    }

    private function prospects(): Prospects {
        return $this->c->get( Prospects::class );
    }

    private function logger(): Logger {
        return $this->c->get( Logger::class );
    }
}
