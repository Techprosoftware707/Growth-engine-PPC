<?php
/**
 * Generates the single polite follow-up for sent messages that received no reply.
 *
 * Follow-ups are drafts by default; they are only queued automatically ("scheduled") when the
 * campaign opted in (settings.auto_follow_up) AND the automation master switch for outreach
 * auto-send is on. Nothing here sends email.
 *
 * Candidates are filtered in SQL (follow-up cap, active contact, website not rejected/suppressed, campaign
 * present / active / follow-ups enabled, nobody in the conversation answered), so rows that can never get a
 * follow-up do not fill the oldest-first batch on every run and starve newer conversations.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Outreach;

use PPC\LinkGrowth\Repositories\Campaigns;
use PPC\LinkGrowth\Repositories\Contacts;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Support\Crypto;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;

final class FollowUps {

    private ?Contacts $contacts = null;

    private ?Prospects $prospects = null;

    private ?Opportunities $opportunities = null;

    public function __construct(
        private OutreachMessages $messages,
        private Campaigns $campaigns,
        private Settings $settings,
        private Templates $templates,
        private Personalizer $personalizer
    ) {}

    /**
     * @return array{created:int,skipped:int,errors:int}
     */
    public function generateDue( TimeBudget $budget, int $max = 20 ): array {
        $out          = [ 'created' => 0, 'skipped' => 0, 'errors' => 0 ];
        $maxFollowUps = (int) $this->settings->get( 'outreach.max_follow_ups', 1 );
        if ( $maxFollowUps < 1 ) {
            return $out;
        }
        $days     = max( 1, (int) $this->settings->get( 'outreach.follow_up_days', 7 ) );
        $autosend = (bool) $this->settings->get( 'automation.outreach_autosend', false );
        $max      = max( 1, min( 200, $max ) );

        try {
            $due = $this->due( $days, 1 + $maxFollowUps, $max );
        } catch ( \Throwable $e ) {
            $out['errors']++;
            return $out;
        }

        $campaignCache = [];
        foreach ( $due as $original ) {
            if ( ! $budget->has( 1.0 ) ) {
                break;
            }
            try {
                $sequence = max( 1, (int) ( $original['sequence'] ?? 1 ) );
                if ( $sequence >= 1 + $maxFollowUps ) {
                    $out['skipped']++;
                    continue;
                }
                $campaignId = (int) ( $original['campaign_id'] ?? 0 );
                $campaign   = null;
                if ( $campaignId > 0 ) {
                    if ( ! array_key_exists( $campaignId, $campaignCache ) ) {
                        $campaignCache[ $campaignId ] = $this->campaigns->find( $campaignId );
                    }
                    $campaign = $campaignCache[ $campaignId ];
                    if ( ! $campaign ) {
                        $out['skipped']++;
                        continue;
                    }
                }
                $cs = is_array( $campaign['settings'] ?? null ) ? $campaign['settings'] : [];
                if ( isset( $cs['follow_ups'] ) && ! filter_var( $cs['follow_ups'], FILTER_VALIDATE_BOOLEAN ) ) {
                    $out['skipped']++;
                    continue;
                }
                if ( $campaign && in_array( (string) $campaign['status'], [ Campaigns::STATUS_ARCHIVED, Campaigns::STATUS_COMPLETED ], true ) ) {
                    $out['skipped']++;
                    continue;
                }
                $contactId = (int) ( $original['contact_id'] ?? 0 );
                $contact   = $contactId > 0 ? $this->contacts()->find( $contactId ) : null;
                if ( ! $contact || Contacts::STATUS_ACTIVE !== (string) ( $contact['status'] ?? '' ) ) {
                    $out['skipped']++;
                    continue;
                }
                $approach = (string) ( $original['approach'] ?? '' );
                if ( $this->messages->existsFor( $contactId, $approach, $sequence + 1 ) ) {
                    $out['skipped']++;
                    continue;
                }
                $prospect = $this->prospects()->find( (int) ( $original['prospect_id'] ?? 0 ) );
                if ( ! $prospect || in_array( (string) ( $prospect['status'] ?? '' ), [ Prospects::STATUS_REJECTED, Prospects::STATUS_SUPPRESSED ], true ) ) {
                    $out['skipped']++;
                    continue;
                }
                $opportunity = ! empty( $original['opportunity_id'] ) ? $this->opportunities()->find( (int) $original['opportunity_id'] ) : null;

                $token = Crypto::randomToken( 16 );
                $url   = Unsubscribe::urlFor( $token );
                $ctx   = $this->personalizer->context( $prospect, $opportunity, $contact, $approach, $url );

                $ctx['original_subject'] = $this->originalSubject( (string) ( $original['subject'] ?? '' ) );
                $template                = $this->templates->followUp();
                $subject                 = $this->oneLine( $this->personalizer->render( $template['subject'], $ctx ), 255 );
                $body                    = $this->personalizer->render( $template['body'], $ctx );

                $auto   = $autosend && ! empty( $cs['auto_follow_up'] ) && filter_var( $cs['auto_follow_up'], FILTER_VALIDATE_BOOLEAN );
                $status = $auto ? OutreachMessages::STATUS_SCHEDULED : OutreachMessages::STATUS_DRAFT;

                $this->messages->create(
                    [
                        'campaign_id'       => $campaignId,
                        'prospect_id'       => (int) $prospect['id'],
                        'contact_id'        => $contactId,
                        'opportunity_id'    => $opportunity ? (int) $opportunity['id'] : null,
                        'approach'          => $approach,
                        'sequence'          => $sequence + 1,
                        'subject'           => $subject,
                        'body'              => $body,
                        'status'            => $status,
                        'variant'           => $template['id'],
                        'scheduled_at'      => $auto ? OutreachMessages::now() : null,
                        'unsubscribe_token' => $token,
                    ]
                );
                $out['created']++;
            } catch ( \Throwable $e ) {
                $out['errors']++;
                continue;
            }
        }
        return $out;
    }

    /**
     * Sent messages that can get a follow-up now: sent $days+ days ago, below the follow-up cap, no next message
     * in the conversation yet, nobody in it answered (or opted out / bounced / closed it), active contact, website
     * not rejected or suppressed, and a campaign that still exists, is not archived/completed and has follow-ups
     * enabled. Oldest first.
     *
     * @return array<int,array<string,mixed>>
     */
    private function due( int $days, int $maxSequence, int $limit ): array {
        global $wpdb;
        $m      = $this->messages->table();
        $sql    = "SELECT m.* FROM {$m} m"
            . " INNER JOIN {$this->contacts()->table()} ct ON ct.id = m.contact_id AND ct.status = %s"
            . " INNER JOIN {$this->prospects()->table()} p ON p.id = m.prospect_id AND p.status NOT IN (%s,%s)"
            . " LEFT JOIN {$this->campaigns->table()} c ON c.id = m.campaign_id"
            . " LEFT JOIN {$m} f ON f.contact_id = m.contact_id AND f.approach = m.approach AND f.sequence = m.sequence + 1"
            . ' WHERE m.status = %s AND m.sent_at IS NOT NULL AND m.sent_at <= %s AND m.sequence < %d AND f.id IS NULL'
            . ' AND ( m.campaign_id = 0 OR ( c.id IS NOT NULL AND c.status NOT IN (%s,%s) ) )'
            . " AND NOT EXISTS ( SELECT 1 FROM {$m} r WHERE r.contact_id = m.contact_id AND r.approach = m.approach AND r.id <> m.id"
            . ' AND ( r.replied_at IS NOT NULL OR r.status IN (%s,%s,%s,%s) ) )';
        $params = [
            Contacts::STATUS_ACTIVE,
            Prospects::STATUS_REJECTED,
            Prospects::STATUS_SUPPRESSED,
            OutreachMessages::STATUS_SENT,
            gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
            $maxSequence,
            Campaigns::STATUS_ARCHIVED,
            Campaigns::STATUS_COMPLETED,
            OutreachMessages::STATUS_REPLIED,
            OutreachMessages::STATUS_OPTED_OUT,
            OutreachMessages::STATUS_BOUNCED,
            OutreachMessages::STATUS_CLOSED,
        ];
        $off = $this->campaignsWithoutFollowUps();
        if ( [] !== $off ) {
            $sql   .= ' AND m.campaign_id NOT IN (' . implode( ',', array_fill( 0, count( $off ), '%d' ) ) . ')';
            $params = array_merge( $params, $off );
        }
        $sql     .= ' ORDER BY m.sent_at ASC, m.id ASC LIMIT %d';
        $params[] = max( 1, $limit );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        return array_map( [ $this->messages, 'hydrate' ], is_array( $rows ) ? $rows : [] );
    }

    /**
     * Ids of campaigns whose settings switch follow-ups off (same rule as the per-row check in generateDue()).
     *
     * @return int[]
     */
    private function campaignsWithoutFollowUps(): array {
        $ids = [];
        foreach ( $this->campaigns->findBy( [], 'id', 'ASC', 5000 ) as $c ) {
            $cs = is_array( $c['settings'] ?? null ) ? $c['settings'] : [];
            if ( isset( $cs['follow_ups'] ) && ! filter_var( $cs['follow_ups'], FILTER_VALIDATE_BOOLEAN ) ) {
                $ids[] = (int) $c['id'];
            }
        }
        return $ids;
    }

    /** Strips any existing "Following up:" / "Re:" prefixes so the follow-up subject does not stack them. */
    private function originalSubject( string $subject ): string {
        $s = trim( $subject );
        $s = preg_replace( '/^((following up|follow[\s\-]?up|re|fw|fwd)\s*:\s*)+/i', '', $s ) ?? $s;
        return '' !== trim( $s ) ? trim( $s ) : $subject;
    }

    private function oneLine( string $s, int $max ): string {
        $s = trim( preg_replace( '/\s+/u', ' ', $s ) ?? $s );
        return mb_strlen( $s ) > $max ? mb_substr( $s, 0, $max ) : $s;
    }

    // Repositories not present in the fixed constructor; they have no dependencies of their own.

    private function contacts(): Contacts {
        return $this->contacts ??= new Contacts();
    }

    private function prospects(): Prospects {
        return $this->prospects ??= new Prospects();
    }

    private function opportunities(): Opportunities {
        return $this->opportunities ??= new Opportunities();
    }
}
