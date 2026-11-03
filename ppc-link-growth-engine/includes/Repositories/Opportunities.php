<?php
/**
 * Opportunity (specific page / action) repository.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Repositories;

use PPC\LinkGrowth\Support\Url;

final class Opportunities extends Repository {

    public const TYPES = [
        'directory_listing'        => 'Business directory inclusion',
        'resource_page'            => 'Resource page inclusion',
        'editorial'                => 'Editorial mention',
        'partnership'              => 'Industry partnership',
        'expert_commentary'        => 'Expert commentary',
        'educational_contribution' => 'Educational contribution',
        'broken_link'              => 'Broken-link replacement',
        'unlinked_mention'         => 'Unlinked brand mention',
        'podcast'                  => 'Podcast / interview',
        'membership'               => 'Association membership listing',
        'supplier_listing'         => 'Supplier directory listing',
    ];

    public const STATUS_OPEN      = 'open';
    public const STATUS_PACKAGED  = 'packaged';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_ACQUIRED  = 'acquired';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_EXPIRED   = 'expired';

    protected string $table = 'opportunities';

    protected array $jsonColumns = [ 'details' ];

    protected array $sortable = [ 'id', 'type', 'status', 'score', 'created_at', 'updated_at' ];

    /**
     * @param array<string,mixed> $details
     * @param string              $uniqueKey Optional discriminator when the same page hosts several opportunities of one type.
     * @return array{0:int,1:bool}
     */
    public function upsert( int $prospectId, string $type, string $url, string $title, array $details = [], ?int $score = null, string $uniqueKey = '' ): array {
        $url  = Url::normalize( $url );
        // Several opportunities of one type can live on the same page (e.g. two broken links); $uniqueKey separates them.
        $hash = sha1( $url . ( '' !== $uniqueKey ? '|' . $uniqueKey : '' ) );
        $row  = $this->findOneBy( [ 'prospect_id' => $prospectId, 'type' => $type, 'url_hash' => $hash ] );
        $now  = self::now();
        if ( $row ) {
            $merged = array_merge( (array) $row['details'], $details );
            $this->update( (int) $row['id'], [ 'title' => mb_substr( $title, 0, 255 ), 'details' => $merged, 'score' => $score ?? $row['score'], 'updated_at' => $now ] );
            return [ (int) $row['id'], false ];
        }
        $id = $this->insert(
            [
                'prospect_id' => $prospectId,
                'type'        => $type,
                'url'         => mb_substr( $url, 0, 700 ),
                'url_hash'    => $hash,
                'title'       => mb_substr( $title, 0, 255 ),
                'details'     => $details,
                'status'      => self::STATUS_OPEN,
                'score'       => $score,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        );
        return [ $id, true ];
    }

    /** @return array<int,array<string,mixed>> */
    public function forProspect( int $prospectId ): array {
        return $this->findBy( [ 'prospect_id' => $prospectId ], 'score', 'DESC', 50 );
    }

    /** @return array<int,array<string,mixed>> */
    public function openForPackaging( int $limit, int $minScore ): array {
        $p   = \PPC\LinkGrowth\Database\Schema::table( 'prospects' );
        $sql = "SELECT o.* FROM {$this->table()} o INNER JOIN {$p} p ON p.id = o.prospect_id WHERE o.status = %s AND p.status IN (%s,%s) AND p.score >= %d ORDER BY p.score DESC, o.id ASC LIMIT %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->db()->get_results( $this->db()->prepare( $sql, self::STATUS_OPEN, Prospects::STATUS_QUALIFIED, Prospects::STATUS_APPROVED, $minScore, $limit ), ARRAY_A );
        return array_map( [ $this, 'hydrate' ], $rows ?: [] );
    }

    public function setStatus( int $id, string $status ): void {
        $this->update( $id, [ 'status' => $status, 'updated_at' => self::now() ] );
    }

    /** @return array<string,int> */
    public function typeCounts(): array {
        return $this->countBy( 'type', 'status NOT IN (%s,%s)', [ self::STATUS_DISMISSED, self::STATUS_EXPIRED ] );
    }
}
