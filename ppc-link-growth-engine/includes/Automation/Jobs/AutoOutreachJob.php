<?php
/**
 * Automatically drafts personalised outreach for qualified prospects whose best
 * opportunity is editorial rather than a submission form.
 *
 * One evergreen campaign per approach ("Auto: <approach>") collects the drafts. Drafts always
 * require the configured approval flow before anything is sent; when the administrator has
 * explicitly enabled outreach autosend AND the sender identity (name, address, postal address)
 * is complete, drafts and their campaign are approved automatically so the send stage can
 * deliver them inside the send window and limits.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Database\Schema;
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

        $approaches = Templates::approaches();
        // Eligibility is filtered in SQL before the LIMIT, so form-first, low-score, contact-less and already
        // drafted prospects at the top of the ranking can no longer fill the window and starve the rest.
        $candidateIds = $this->candidateIds( $prospects, $approaches, $minScore );

        foreach ( array_chunk( $candidateIds, self::PAGE_SIZE ) as $chunk ) {
            foreach ( $this->loadOrdered( $prospects, $chunk ) as $prospect ) {
                if ( $result['drafted'] >= self::MAX_DRAFTS_PER_RUN || ! $budget->has( 4 ) ) {
                    break 2;
                }
                $this->draftOne( $prospect, $result, $engine, $campaigns, $contacts, $messages, $logger, $approaches, $minScore, $autosend && $sender['ok'] );
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
     */
    private function draftOne( array $prospect, array &$result, OutreachEngine $engine, Campaigns $campaigns, Contacts $contacts, OutreachMessages $messages, Logger $logger, array $approaches, int $minScore, bool $autoApprove ): void {
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
            $campaignId = $this->evergreenCampaign( $campaigns, $engine, $approach, (string) $approaches[ $approach ], $autoApprove );
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
            if ( $autoApprove ) {
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
            . " AND EXISTS ( SELECT 1 FROM {$ct} c WHERE c.prospect_id = p.id AND c.status = %s )";
        $params[] = Contacts::STATUS_ACTIVE;
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

    /** Find or create the evergreen campaign for an approach; approve it only in the autosend path. */
    private function evergreenCampaign( Campaigns $campaigns, OutreachEngine $engine, string $approach, string $label, bool $autosend ): int {
        $name = 'Auto: ' . $label;
        $row  = $campaigns->findOneBy( [ 'approach' => $approach, 'name' => $name ] );
        $id   = null !== $row ? (int) $row['id'] : 0;
        if ( 0 === $id ) {
            $id = $engine->createCampaign(
                $name,
                $approach,
                [
                    'daily_limit'  => 5,
                    'follow_ups'   => true,
                    'auto_created' => true,
                    'notes'        => 'Created automatically for hands-off drafting. Drafts wait for approval unless outreach autosend is enabled.',
                ],
                0
            );
        }
        if ( $id > 0 && $autosend ) {
            $row = $campaigns->find( $id );
            if ( null !== $row && in_array( (string) $row['status'], [ Campaigns::STATUS_DRAFT, Campaigns::STATUS_PENDING_APPROVAL, Campaigns::STATUS_PAUSED ], true ) ) {
                $engine->approveCampaign( $id, 0 );
            }
        }
        return $id;
    }
}
