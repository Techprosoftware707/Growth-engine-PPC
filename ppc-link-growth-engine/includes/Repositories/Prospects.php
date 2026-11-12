<?php
/**
 * Prospect (website) repository.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Repositories;

use PPC\LinkGrowth\Support\Url;

final class Prospects extends Repository {

    public const STATUS_DISCOVERED  = 'discovered';
    public const STATUS_EVALUATING  = 'evaluating';
    public const STATUS_QUALIFIED   = 'qualified';
    public const STATUS_APPROVED    = 'approved';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_ACQUIRED    = 'acquired';
    public const STATUS_REJECTED    = 'rejected';
    public const STATUS_ON_HOLD     = 'on_hold';
    public const STATUS_SUPPRESSED  = 'suppressed';

    public const STATUSES = [
        self::STATUS_DISCOVERED,
        self::STATUS_EVALUATING,
        self::STATUS_QUALIFIED,
        self::STATUS_APPROVED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_ACQUIRED,
        self::STATUS_REJECTED,
        self::STATUS_ON_HOLD,
        self::STATUS_SUPPRESSED,
    ];

    /**
     * Per-site automation setting (automation_allowed, a signed tinyint): never automate, follow the
     * global submission mode, or always allowed (still subject to the master switch and the form,
     * CAPTCHA, login, robots.txt, duplicate and claims gates).
     */
    public const AUTOMATION_NEVER   = -1;
    public const AUTOMATION_INHERIT = 0;
    public const AUTOMATION_ALWAYS  = 1;

    public const AUTOMATION_SETTINGS = [ self::AUTOMATION_NEVER, self::AUTOMATION_INHERIT, self::AUTOMATION_ALWAYS ];

    protected string $table = 'prospects';

    protected array $jsonColumns = [ 'score_breakdown', 'metrics', 'profile', 'risk_flags', 'submission_config' ];

    protected array $sortable = [ 'id', 'domain', 'name', 'score', 'relevance', 'status', 'first_seen', 'last_evaluated', 'updated_at', 'country', 'site_type', 'opportunity_type' ];

    /**
     * The prospect's per-site automation setting. Only 1 means "always allowed"; any negative value
     * means "never automate"; everything else (0, null, unknown values) follows the submission mode.
     *
     * @param array<string,mixed> $prospect
     */
    public static function automationSetting( array $prospect ): int {
        $raw = $prospect['automation_allowed'] ?? 0;
        $v   = is_bool( $raw ) ? (int) $raw : ( is_numeric( $raw ) ? (int) $raw : 0 );
        if ( $v < 0 ) {
            return self::AUTOMATION_NEVER;
        }
        return self::AUTOMATION_ALWAYS === $v ? self::AUTOMATION_ALWAYS : self::AUTOMATION_INHERIT;
    }

    /** @return array<string,mixed>|null */
    public function findByDomain( string $domain ): ?array {
        $domain = Url::registrableDomain( $domain );
        if ( '' === $domain ) {
            return null;
        }
        return $this->findOneBy( [ 'domain' => $domain ] );
    }

    /**
     * Inserts a prospect if the domain is unknown; returns [id, created].
     *
     * @param array<string,mixed> $data
     * @return array{0:int,1:bool}
     */
    public function upsertDiscovered( string $domain, array $data ): array {
        $domain = Url::registrableDomain( $domain );
        $row    = $this->findByDomain( $domain );
        if ( $row ) {
            return [ (int) $row['id'], false ];
        }
        $now = self::now();
        $id  = $this->insert(
            array_merge(
                [
                    'domain'       => $domain,
                    'name'         => $domain,
                    'homepage_url' => 'https://' . $domain . '/',
                    'status'       => self::STATUS_DISCOVERED,
                    'first_seen'   => $now,
                    'updated_at'   => $now,
                ],
                $data
            )
        );
        return [ $id, true ];
    }

    /**
     * Prospects waiting for (re-)evaluation.
     *
     * @return array<int,array<string,mixed>>
     */
    public function dueForEvaluation( int $limit ): array {
        $sql = "SELECT * FROM {$this->table()} WHERE status = %s OR (status IN (%s,%s,%s) AND next_review IS NOT NULL AND next_review <= %s) ORDER BY (status = %s) DESC, id ASC LIMIT %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->db()->get_results( $this->db()->prepare( $sql, self::STATUS_DISCOVERED, self::STATUS_QUALIFIED, self::STATUS_APPROVED, self::STATUS_ACQUIRED, self::now(), self::STATUS_DISCOVERED, $limit ), ARRAY_A );
        return array_map( [ $this, 'hydrate' ], $rows ?: [] );
    }

    /** @return array<int,array<string,mixed>> */
    public function qualifiedWithoutOpportunities( int $limit ): array {
        $opps = \PPC\LinkGrowth\Database\Schema::table( 'opportunities' );
        $sql  = "SELECT p.* FROM {$this->table()} p LEFT JOIN {$opps} o ON o.prospect_id = p.id WHERE p.status IN (%s,%s) AND o.id IS NULL ORDER BY p.score DESC, p.id ASC LIMIT %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->db()->get_results( $this->db()->prepare( $sql, self::STATUS_QUALIFIED, self::STATUS_APPROVED, $limit ), ARRAY_A );
        return array_map( [ $this, 'hydrate' ], $rows ?: [] );
    }

    public function setStatus( int $id, string $status, string $reason = '' ): void {
        $data = [ 'status' => $status, 'updated_at' => self::now() ];
        if ( '' !== $reason ) {
            $data['rejection_reason'] = mb_substr( $reason, 0, 255 );
        }
        $this->update( $id, $data );
    }

    /** @return array<string,int> */
    public function statusCounts(): array {
        return $this->countBy( 'status' );
    }

    /** @return array<string,int> */
    public function classCounts(): array {
        return $this->countBy( 'score_class', 'score IS NOT NULL' );
    }

    /** @return array<string,int> */
    public function typeCounts(): array {
        return $this->countBy( 'opportunity_type', 'status NOT IN (%s,%s)', [ self::STATUS_REJECTED, self::STATUS_SUPPRESSED ] );
    }

    /** Score histogram in 10-point buckets. @return array<int,int> */
    public function scoreHistogram(): array {
        $sql = "SELECT FLOOR(score/10)*10 AS bucket, COUNT(*) AS c FROM {$this->table()} WHERE score IS NOT NULL GROUP BY bucket ORDER BY bucket";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->db()->get_results( $sql, ARRAY_A );
        $out  = array_fill_keys( [ 0, 10, 20, 30, 40, 50, 60, 70, 80, 90 ], 0 );
        foreach ( $rows ?: [] as $r ) {
            $b = min( 90, (int) $r['bucket'] );
            $out[ $b ] = ( $out[ $b ] ?? 0 ) + (int) $r['c'];
        }
        return $out;
    }

    /** @return array<int,string> */
    public function allDomains(): array {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return array_map( 'strval', $this->db()->get_col( "SELECT domain FROM {$this->table()}" ) ?: [] );
    }
}
