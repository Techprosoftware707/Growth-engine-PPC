<?php
/**
 * Contact repository (editorial / partnership contacts per prospect).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Repositories;

final class Contacts extends Repository {

    public const STATUS_ACTIVE     = 'active';
    public const STATUS_OPTED_OUT  = 'opted_out';
    public const STATUS_BOUNCED    = 'bounced';
    public const STATUS_SUPPRESSED = 'suppressed';
    public const STATUS_INVALID    = 'invalid';

    protected string $table = 'contacts';

    protected array $sortable = [ 'id', 'email', 'name', 'status', 'confidence', 'created_at' ];

    /**
     * @param array<string,mixed> $data
     * @return array{0:int,1:bool}
     */
    public function upsert( int $prospectId, string $email, array $data = [] ): array {
        $email = strtolower( trim( $email ) );
        if ( ! is_email( $email ) ) {
            return [ 0, false ];
        }
        $row = $this->findOneBy( [ 'prospect_id' => $prospectId, 'email' => $email ] );
        $now = self::now();
        if ( $row ) {
            $update = [ 'updated_at' => $now ];
            foreach ( [ 'name', 'role', 'source_url' ] as $k ) {
                if ( ! empty( $data[ $k ] ) && empty( $row[ $k ] ) ) {
                    $update[ $k ] = $data[ $k ];
                }
            }
            if ( isset( $data['confidence'] ) && (int) $data['confidence'] > (int) $row['confidence'] ) {
                $update['confidence'] = (int) $data['confidence'];
            }
            $this->update( (int) $row['id'], $update );
            return [ (int) $row['id'], false ];
        }
        $id = $this->insert(
            array_merge(
                [
                    'prospect_id'   => $prospectId,
                    'email'         => $email,
                    'name'          => '',
                    'role'          => '',
                    'source_url'    => '',
                    'confidence'    => 50,
                    'consent_basis' => 'conspicuous_publication',
                    'status'        => self::STATUS_ACTIVE,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ],
                $data
            )
        );
        return [ $id, true ];
    }

    /** @return array<int,array<string,mixed>> */
    public function forProspect( int $prospectId, bool $activeOnly = false ): array {
        $where = [ 'prospect_id' => $prospectId ];
        if ( $activeOnly ) {
            $where['status'] = self::STATUS_ACTIVE;
        }
        return $this->findBy( $where, 'confidence', 'DESC', 20 );
    }

    public function setStatusByEmail( string $email, string $status ): int {
        $email = strtolower( trim( $email ) );
        $n     = $this->db()->update( $this->table(), [ 'status' => $status, 'updated_at' => self::now() ], [ 'email' => $email ] );
        return false === $n ? 0 : (int) $n;
    }
}
