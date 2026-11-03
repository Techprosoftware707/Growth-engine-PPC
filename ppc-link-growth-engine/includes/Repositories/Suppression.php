<?php
/**
 * Suppression list (domains and emails never to contact / pursue).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Repositories;

use PPC\LinkGrowth\Support\Url;

final class Suppression extends Repository {

    protected string $table = 'suppression';

    protected array $sortable = [ 'id', 'type', 'value', 'created_at' ];

    public function isSuppressed( string $type, string $value ): bool {
        $value = self::normalizeValue( $type, $value );
        if ( '' === $value ) {
            return false;
        }
        if ( 'email' === $type ) {
            $domain = substr( $value, (int) strrpos( $value, '@' ) + 1 );
            if ( $this->count( [ 'type' => 'email_domain', 'value' => $domain ] ) > 0 ) {
                return true;
            }
        }
        return $this->count( [ 'type' => $type, 'value' => $value ] ) > 0;
    }

    public function add( string $type, string $value, string $reason = '' ): bool {
        $value = self::normalizeValue( $type, $value );
        if ( '' === $value || $this->count( [ 'type' => $type, 'value' => $value ] ) > 0 ) {
            return false;
        }
        $this->insert( [ 'type' => $type, 'value' => $value, 'reason' => mb_substr( $reason, 0, 190 ), 'created_at' => self::now() ] );
        return true;
    }

    public function remove( string $type, string $value ): void {
        $this->db()->delete( $this->table(), [ 'type' => $type, 'value' => self::normalizeValue( $type, $value ) ] );
    }

    public static function normalizeValue( string $type, string $value ): string {
        $value = strtolower( trim( $value ) );
        if ( 'domain' === $type ) {
            return Url::registrableDomain( $value );
        }
        if ( 'email' === $type ) {
            return is_email( $value ) ? $value : '';
        }
        return $value;
    }
}
