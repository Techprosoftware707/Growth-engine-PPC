<?php
/**
 * Encrypted credential store with wp-config constant overrides.
 *
 * Constants take precedence: define( 'PPCLG_SECRET_SERANKING_API_KEY', '...' ).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Support;

final class Secrets {

    public const OPTION = 'ppclg_secrets';

    public const KEYS = [
        'seranking_api_key'           => 'SE Ranking Data API key',
        'ahrefs_api_key'              => 'Ahrefs API v3 token',
        'semrush_api_key'             => 'Semrush API key',
        'moz_access_id'               => 'Moz Links API access ID',
        'moz_secret_key'              => 'Moz Links API secret key',
        'google_service_account_json' => 'Google service account JSON (Search Console + GA4)',
        'google_cse_api_key'          => 'Google Custom Search API key',
        'google_cse_cx'               => 'Google Programmable Search Engine ID (cx)',
        'brave_api_key'               => 'Brave Search API key',
        'serper_api_key'              => 'Serper.dev API key',
        'anthropic_api_key'           => 'Anthropic API key (optional AI writer)',
        'inbound_webhook_secret'      => 'Inbound email webhook secret',
    ];

    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct( private Crypto $crypto ) {}

    public function get( string $key ): string {
        $const = 'PPCLG_SECRET_' . strtoupper( $key );
        if ( defined( $const ) ) {
            return (string) constant( $const );
        }
        $all = $this->load();
        if ( ! isset( $all[ $key ] ) || '' === $all[ $key ] ) {
            return '';
        }
        try {
            return $this->crypto->decrypt( $all[ $key ] );
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    public function has( string $key ): bool {
        return '' !== $this->get( $key );
    }

    public function isFromConstant( string $key ): bool {
        return defined( 'PPCLG_SECRET_' . strtoupper( $key ) );
    }

    public static function isValidKey( string $key ): bool {
        return array_key_exists( $key, self::KEYS ) || (bool) preg_match( '/^partner_api_\d{1,12}$/', $key );
    }

    public function set( string $key, string $value ): void {
        if ( ! self::isValidKey( $key ) ) {
            return;
        }
        $all = $this->load();
        if ( '' === $value ) {
            unset( $all[ $key ] );
        } else {
            $all[ $key ] = $this->crypto->encrypt( $value );
        }
        $this->cache = $all;
        update_option( self::OPTION, $all, false );
    }

    public function delete( string $key ): void {
        $this->set( $key, '' );
    }

    /** Masked value for display: never returns the secret. */
    public function masked( string $key ): string {
        $value = $this->get( $key );
        if ( '' === $value ) {
            return '';
        }
        if ( 'google_service_account_json' === $key ) {
            $json = json_decode( $value, true );
            $mail = is_array( $json ) && isset( $json['client_email'] ) ? (string) $json['client_email'] : 'service account';
            return 'configured (' . $mail . ')';
        }
        $len = strlen( $value );
        if ( $len <= 6 ) {
            return str_repeat( '•', $len );
        }
        return str_repeat( '•', max( 4, $len - 4 ) ) . substr( $value, -4 );
    }

    /** @return array<string,array{label:string,configured:bool,masked:string,locked:bool}> */
    public function status(): array {
        $out = [];
        foreach ( self::KEYS as $key => $label ) {
            $out[ $key ] = [
                'label'      => $label,
                'configured' => $this->has( $key ),
                'masked'     => $this->masked( $key ),
                'locked'     => $this->isFromConstant( $key ),
            ];
        }
        return $out;
    }

    /** @return array<string,string> */
    private function load(): array {
        if ( null === $this->cache ) {
            $stored      = get_option( self::OPTION, [] );
            $this->cache = is_array( $stored ) ? $stored : [];
        }
        return $this->cache;
    }
}
