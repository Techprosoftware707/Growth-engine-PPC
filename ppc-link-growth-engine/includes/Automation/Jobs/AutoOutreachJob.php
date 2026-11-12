<?php
/**
 * Automatically drafts personalised outreach for qualified prospects whose best
 * opportunity is editorial rather than a submission form.
 *
 * One evergreen campaign per approach ("Auto: <approach>") collects the drafts. By default every draft waits
 * for a person to approve it. When the administrator has turned on outreach autosend (Automation) and the
 * sender identity (name, address, postal address) is complete, the evergreen campaign is approved
 * automatically; if "Require individual approval" (Settings → Outreach) is also turned off, this job approves
 * each auto-drafted first-contact email itself, and the send stage then emails the qualified prospect inside
 * the send window and limits without per-message review. A campaign a person paused, archived or completed
 * gets no new drafts and is never re-approved by automation.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Database\Schema;
use PPC\LinkGrowth\Discovery\VerifiedOpportunities;
use PPC\LinkGrowth\Outreach\Compliance;
use PPC\LinkGrowth\Outreach\OutreachEngine;
use PPC\LinkGrowth\Outreach\Templates;
use PPC\LinkGrowth\Repositories\Campaigns;
use PPC\LinkGrowth\Repositories\Contacts;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use Throwable;

final class AutoOutreachJob extends AbstractJob {

    /** Opportunity type → outreach approach. Form-first types are handled by the submission engine. */
    public const APPROACH_FOR_TYPE = [
        'resource_page'            => 'resource_page',
        'editorial'                => 'editorial_mention',
        'broken_link'              => 'broken_link',
        'unlinked_mention'         => 'unlinked_mention',
        'podcast'                  => 'expert_commentary',
        'educational_contribution' => 'educational_contribution',
        'partnership'              => 'partnership',
        'membership'               => 'partnership',
    ];

    /** Most drafts created in one run. */
    private const MAX_DRAFTS_PER_RUN = 40;

    /** Most candidates examined in one run (they are loaded in pages of PAGE_SIZE). */
    private const MAX_CANDIDATES = 200;

    private const PAGE_SIZE = 40;

    /** Evergreen campaign states set by a person in which no new drafts are created. */
    private const HELD_CAMPAIGN_STATUSES = [ Campaigns::STATUS_PAUSED, Campaigns::STATUS_ARCHIVED, Campaigns::STATUS_COMPLETED ];

    public static function type(): string {
        return 'auto_outreach';
    }

    public static function priority(): int {
        return 6;
    }

    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        $settings = $c->get( Settings::class );
        $result   = [ 'drafted' => 0, 'auto_approved' => 0, 'skipped' => 0, 'campaigns' => [], 'warnings' => [] ];

        if ( ! (bool) $settings->get( 'automation.auto_outreach_drafts', true ) && empty( $payload['manual'] ) ) {
            $result['warnings'][] = 'Automatic outreach drafting is switched off (Settings → Automation).';
            return $this->result( $result );
        }

        $engine     = $c->get( OutreachEngine::class );
        $campaigns  = $c->get( Campaigns::class );
        $prospects  = $c->get( Prospects::class );
        $contacts   = $c->get( Contacts::class );
        $messages   = $c->get( OutreachMessages::class );
        $compliance = $c->get( Compliance::class );
        $logger     = $c->get( Logger::class );

        $minScore = (int) $settings->get( 'automation.min_score_auto_queue', 70 );
        $autosend = (bool) $settings->get( 'automation.outreach_autosend', false );
        $sender   = $compliance->senderReady();
        if ( $autosend && ! $sender['ok'] ) {
            $result['warnings'][] = 'Outreach autosend is enabled but the sender identity is incomplete: ' . implode( ', ', $sender['missing'] ) . '.';
        }

        // The engine refuses automated message approval while "Require individual approval" is on, so it is not
        // attempted then; the drafts simply wait for review.
        $approveMessages = $autosend && $sender['ok'] && ! (bool) $settings->get( 'outreach.require_approval', true );

        $approaches = Templates::approaches();
        // An approach whose evergreen campaign a person paused, archived or completed is left out of the candidate
        // query, so its prospects (which get no drafts) cannot fill the candidate window and starve the others.
        foreach ( $this->heldApproaches( $campaigns, $approaches ) as $approach => $warning ) {
            unset( $approaches[ $approach ] );
            $result['warnings'][] = $warning;
        }
        // Eligibility is filtered in SQL before the LIMIT, so form-first, low-score, contact-less and already
        // drafted prospects at the top of the ranking can no longer fill the window and starve the rest.
        $candidateIds = $this->candidateIds( $prospects, $approaches, $minScore );

        foreach ( array_chunk( $candidateIds, self::PAGE_SIZE ) as $chunk ) {
            foreach ( $this->loadOrdered( $prospects, $chunk ) as $prospect ) {
                if ( $result['drafted'] >= self::MAX_DRAFTS_PER_RUN || ! $budget->has( 4 ) ) {
                    break 2;
                }
                $this->draftOne( $prospect, $result, $engine, $campaigns, $contacts, $messages, $logger, $approaches, $minScore, $autosend && $sender['ok'], $approveMessages );
            }
        }
        return $this->result( $result );
    }

    /**
     * Drafts (and, in the autosend path, approves) the first message for one candidate.
     *
     * @param array<string,mixed>  $prospect
     * @param array<string,mixed>  $result
     * @param array<string,string> $approaches
     * @param bool                 $autoApprove     Approve the evergreen campaign (autosend on, sender complete).
     * @param bool                 $approveMessages Also approve the draft (and "Require individual approval" is off).
     */
    private function draftOne( array $prospect, array &$result, OutreachEngine $engine, Campaigns $campaigns, Contacts $contacts, OutreachMessages $messages, Logger $logger, array $approaches, int $minScore, bool $autoApprove, bool $approveMessages ): void {
        try {
            $approach = self::APPROACH_FOR_TYPE[ (string) $prospect['opportunity_type'] ] ?? '';
            if ( '' === $approach || ! isset( $approaches[ $approach ] ) ) {
                return; // Form-first prospects belong to the submission engine.
            }
            $score = isset( $prospect['score'] ) && null !== $prospect['score'] ? (int) $prospect['score'] : 0;
            if ( $score < $minScore ) {
                return;
            }
            if ( [] === $contacts->forProspect( (int) $prospect['id'], true ) ) {
                $result['skipped']++;
                return;
            }
            $campaignId = $this->evergreenCampaign( $campaigns, $engine, $approach, (string) $approaches[ $approach ], $autoApprove, $result );
            if ( $campaignId <= 0 ) {
                return;
            }
            $result['campaigns'][ $approach ] = $campaignId;
            $messageId = $engine->draftForProspect( $campaignId, (int) $prospect['id'], 0, false );
            if ( null === $messageId ) {
                $result['skipped']++;
                return;
            }
            $result['drafted']++;
            if ( $approveMessages ) {
                $approved = $engine->approveMessage( $messageId, 0 );
                if ( ! empty( $approved['ok'] ) ) {
                    $result['auto_approved']++;
                } else {
                    $messages->setStatus( $messageId, OutreachMessages::STATUS_DRAFT );
                }
            }
        } catch ( Throwable $e ) {
            $logger->warning( 'automation', 'Auto outreach failed for one prospect: ' . $e->getMessage(), [ 'prospect_id' => (int) ( $prospect['id'] ?? 0 ) ] );
            $result['skipped']++;
        }
    }

    /**
     * Ids of qualified/approved prospects that can be drafted: an outreach-first opportunity type with a known
     * approach, score >= the auto-queue minimum, at least one active contact, and no first message yet for that
     * approach to any of their active contacts. Highest score first, at most MAX_CANDIDATES.
     *
     * @param array<string,string> $approaches approach => label
     * @return int[]
     */
    private function candidateIds( Prospects $prospects, array $approaches, int $minScore ): array {
        global $wpdb;
        $byApproach = [];
        foreach ( self::APPROACH_FOR_TYPE as $type => $approach ) {
            if ( isset( $approaches[ $approach ] ) ) {
                $byApproach[ $approach ][] = $type;
            }
        }
        if ( [] === $byApproach ) {
            return [];
        }
        $p        = $prospects->table();
        $m        = Schema::table( 'outreach_messages' );
        $ct       = Schema::table( 'contacts' );
        $s        = Schema::table( 'submissions' );
        $params   = [ Prospects::STATUS_QUALIFIED, Prospects::STATUS_APPROVED ];
        $branches = [];
        foreach ( $byApproach as $approach => $types ) {
            $branches[] = '( p.opportunity_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')'
                . " AND NOT EXISTS ( SELECT 1 FROM {$m} m INNER JOIN {$ct} mc ON mc.id = m.contact_id"
                . ' WHERE m.prospect_id = p.id AND m.approach = %s AND m.sequence = 1 AND mc.status = %s ) )';
            foreach ( $types as $type ) {
                $params[] = $type;
            }
            $params[] = (string) $approach;
            $params[] = Contacts::STATUS_ACTIVE;
        }
        $sql = "SELECT p.id FROM {$p} p WHERE p.status IN (%s,%s) AND ( " . implode( ' OR ', $branches ) . ' )'
            . " AND EXISTS ( SELECT 1 FROM {$ct} c WHERE c.prospect_id = p.id AND c.status = %s )"
            // Prospects imported from the verified opportunity database are owner tasks: whether to pitch them is the
            // owner's decision, so they are never auto-drafted (the owner can still draft for them by hand). A site
            // that was already a prospect (seed, search, manual) keeps its discovered_via, so the owner task itself
            // is checked too.
            . ' AND p.discovered_via <> %s'
            . " AND NOT EXISTS ( SELECT 1 FROM {$s} s WHERE s.prospect_id = p.id AND s.payload LIKE %s )";
        $params[] = Contacts::STATUS_ACTIVE;
        $params[] = VerifiedOpportunities::SOURCE;
        // Unescaped like the other owner-task queries: SQLite has no default LIKE escape character, and the only
        // wildcard in the marker ("_") can match nothing but itself in a JSON payload.
        $params[] = '%' . VerifiedOpportunities::PAYLOAD_MARKER . '%';
        if ( $minScore > 0 ) {
            $sql     .= ' AND p.score >= %d';
            $params[] = $minScore;
        }
        $sql     .= ' ORDER BY p.score DESC, p.id ASC LIMIT %d';
        $params[] = self::MAX_CANDIDATES;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
        return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
    }

    /**
     * @param int[] $ids
     * @return array<int,array<string,mixed>> rows in the order of $ids
     */
    private function loadOrdered( Prospects $prospects, array $ids ): array {
        if ( [] === $ids ) {
            return [];
        }
        $byId = [];
        foreach ( $prospects->findBy( [ 'id' => $ids ], 'id', 'ASC', count( $ids ) ) as $row ) {
            $byId[ (int) $row['id'] ] = $row;
        }
        $out = [];
        foreach ( $ids as $id ) {
            if ( isset( $byId[ $id ] ) ) {
                $out[] = $byId[ $id ];
            }
        }
        return $out;
    }

    /**
     * Approaches whose evergreen campaign ("Auto: <label>") a person paused, archived or completed.
     *
     * @param array<string,string> $approaches approach => label
     * @return array<string,string> approach => warning
     */
    private function heldApproaches( Campaigns $campaigns, array $approaches ): array {
        $held = [];
        foreach ( array_unique( array_values( self::APPROACH_FOR_TYPE ) ) as $approach ) {
            if ( ! isset( $approaches[ $approach ] ) ) {
                continue;
            }
            $name = 'Auto: ' . $approaches[ $approach ];
            $row  = $campaigns->findOneBy( [ 'approach' => $approach, 'name' => $name ] );
            if ( null !== $row && in_array( (string) $row['status'], self::HELD_CAMPAIGN_STATUSES, true ) ) {
                $held[ $approach ] = self::heldWarning( $name, (string) $row['status'] );
            }
        }
        return $held;
    }

    private static function heldWarning( string $name, string $status ): string {
        return sprintf( 'The "%s" campaign is %s, so no new drafts were created for it.', $name, $status );
    }

    /**
     * Find or create the evergreen campaign for an approach; approve it only in the autosend path. Returns 0 (no
     * drafting) when a person paused, archived or completed it, so drafts do not pile up in a held campaign.
     *
     * @param array<string,mixed> $result
     */
    private function evergreenCampaign( Campaigns $campaigns, OutreachEngine $engine, string $approach, string $label, bool $autosend, array &$result ): int {
        $name = 'Auto: ' . $label;
        $row  = $campaigns->findOneBy( [ 'approach' => $approach, 'name' => $name ] );
        if ( null !== $row && in_array( (string) $row['status'], self::HELD_CAMPAIGN_STATUSES, true ) ) {
            $warning = self::heldWarning( $name, (string) $row['status'] );
            if ( ! in_array( $warning, $result['warnings'], true ) ) {
                $result['warnings'][] = $warning;
            }
            return 0;
        }
        $id = null !== $row ? (int) $row['id'] : 0;
        if ( 0 === $id ) {
            $id = $engine->createCampaign(
                $name,
                $approach,
                [
                    'daily_limit'  => 5,
                    'follow_ups'   => true,
                    'auto_created' => true,
                    'notes'        => 'Created automatically for hands-off drafting. Drafts wait for your approval unless outreach autosend is on and "Require individual approval" is off.',
                ],
                0
            );
        }
        if ( $id > 0 && $autosend ) {
            $row = $campaigns->find( $id );
            if ( null !== $row && in_array( (string) $row['status'], [ Campaigns::STATUS_DRAFT, Campaigns::STATUS_PENDING_APPROVAL ], true ) ) {
                $engine->approveCampaign( $id, 0 );
            }
        }
        return $id;
    }
}
