<?php
/**
 * Outreach message repository.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Repositories;

final class OutreachMessages extends Repository {

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENT      = 'sent';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_REPLIED   = 'replied';
    public const STATUS_BOUNCED   = 'bounced';
    public const STATUS_OPTED_OUT = 'opted_out';
    public const STATUS_CLOSED    = 'closed';
    public const STATUS_REJECTED  = 'rejected';

    /** Transient claim while one run hands the email to wp_mail() (OutreachEngine::deliver()). */
    public const STATUS_SENDING   = 'sending';

    protected string $table = 'outreach_messages';

    protected array $sortable = [ 'id', 'status', 'approach', 'sequence', 'created_at', 'sent_at', 'replied_at', 'scheduled_at', 'campaign_id', 'prospect_id' ];

    public function existsFor( int $contactId, string $approach, int $sequence = 1 ): ?array {
        return $this->findOneBy( [ 'contact_id' => $contactId, 'approach' => $approach, 'sequence' => $sequence ] );
    }

    /** @param array<string,mixed> $data */
    public function create( array $data ): int {
        $now = self::now();
        return $this->insert( array_merge( [ 'status' => self::STATUS_DRAFT, 'created_at' => $now, 'updated_at' => $now ], $data ) );
    }

    public function setStatus( int $id, string $status, array $extra = [] ): void {
        $this->update( $id, array_merge( [ 'status' => $status, 'updated_at' => self::now() ], $extra ) );
    }

    /** @return array<int,array<string,mixed>> */
    public function readyToSend( int $limit ): array {
        $sql = "SELECT * FROM {$this->table()} WHERE status IN (%s,%s) AND (scheduled_at IS NULL OR scheduled_at <= %s) ORDER BY sequence ASC, id ASC LIMIT %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->db()->get_results( $this->db()->prepare( $sql, self::STATUS_APPROVED, self::STATUS_SCHEDULED, self::now(), $limit ), ARRAY_A );
        return array_map( [ $this, 'hydrate' ], $rows ?: [] );
    }

    /** Messages sent N+ days ago with no reply and no follow-up yet. @return array<int,array<string,mixed>> */
    public function awaitingFollowUp( int $days, int $limit ): array {
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $sql    = "SELECT m.* FROM {$this->table()} m LEFT JOIN {$this->table()} f ON f.contact_id = m.contact_id AND f.approach = m.approach AND f.sequence = m.sequence + 1 WHERE m.status = %s AND m.sent_at IS NOT NULL AND m.sent_at <= %s AND f.id IS NULL ORDER BY m.sent_at ASC LIMIT %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->db()->get_results( $this->db()->prepare( $sql, self::STATUS_SENT, $cutoff, $limit ), ARRAY_A );
        return array_map( [ $this, 'hydrate' ], $rows ?: [] );
    }

    public function findByToken( string $token ): ?array {
        return preg_match( '/^[a-f0-9]{32}$/', $token ) ? $this->findOneBy( [ 'unsubscribe_token' => $token ] ) : null;
    }

    public function findByMessageId( string $messageId ): ?array {
        $messageId = trim( $messageId, '<> ' );
        return '' === $messageId ? null : $this->findOneBy( [ 'message_id' => $messageId ] );
    }

    /** Last message sent to any contact of a prospect (for cool-down enforcement). */
    public function lastSentForProspect( int $prospectId ): ?array {
        $sql = "SELECT * FROM {$this->table()} WHERE prospect_id = %d AND sent_at IS NOT NULL ORDER BY sent_at DESC LIMIT 1";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row = $this->db()->get_row( $this->db()->prepare( $sql, $prospectId ), ARRAY_A );
        return $row ? $this->hydrate( $row ) : null;
    }

    public function sentTodayForCampaign( int $campaignId ): int {
        $sql = "SELECT COUNT(*) FROM {$this->table()} WHERE campaign_id = %d AND sent_at >= %s";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $this->db()->get_var( $this->db()->prepare( $sql, $campaignId, gmdate( 'Y-m-d 00:00:00' ) ) );
    }

    /** @return array<string,int> */
    public function statusCounts(): array {
        return $this->countBy( 'status' );
    }

    /** @return array{sent:int,replied:int,rate:float} */
    public function responseRate(): array {
        $sql = "SELECT SUM(sent_at IS NOT NULL) AS sent, SUM(replied_at IS NOT NULL) AS replied FROM {$this->table()}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $row     = $this->db()->get_row( $sql, ARRAY_A );
        $sent    = (int) ( $row['sent'] ?? 0 );
        $replied = (int) ( $row['replied'] ?? 0 );
        return [ 'sent' => $sent, 'replied' => $replied, 'rate' => $sent > 0 ? round( $replied / $sent * 100, 1 ) : 0.0 ];
    }
}
