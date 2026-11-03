<?php
/**
 * Plugin settings (non-secret) with defaults and dot-path access.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Support;

final class Settings {

    public const OPTION = 'ppclg_settings';

    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    /** @return array<string,mixed> */
    public static function defaults(): array {
        $home   = function_exists( 'home_url' ) ? home_url( '/' ) : 'https://purepeptidescanada.com/';
        $host   = (string) wp_parse_url( $home, PHP_URL_HOST );
        $domain = preg_replace( '/^www\./i', '', $host ) ?: 'purepeptidescanada.com';

        return [
            'site'       => [
                'brand_name'   => 'Pure Peptides Canada',
                'legal_name'   => 'PURE PEPTIDES CANADA',
                'domain'       => $domain,
                'home_url'     => untrailingslashit( $home ),
                'tagline'      => 'Canadian-owned supplier of research peptides and laboratory reagents',
                'email'        => 'hi@purepeptidescanada.com',
                'phone'        => '',
                'address'      => '222 Jarvis St',
                'city'         => 'Toronto',
                'region'       => 'ON',
                'postal_code'  => 'M5B 2B8',
                'country'      => 'CA',
                'founded_year' => '2023',
                'logo_url'     => '',
                'social'       => [
                    'https://x.com/PurePeptidesCan',
                    'https://www.facebook.com/profile.php?id=61592401685940',
                ],
                'categories'   => [
                    'Laboratory Supplies',
                    'Scientific Research Supplier',
                    'Biotechnology',
                    'Research Reagents',
                ],
            ],
            'automation' => [
                'enabled'               => false,
                'discovery'             => true,
                'evaluation'            => true,
                'opportunities'         => true,
                'packaging'             => true,
                'submissions'           => false,
                'outreach_autosend'     => false,
                'monitoring'            => true,
                'imports'               => true,
                'daily_limits'          => [
                    'discovery_queries' => 15,
                    'evaluations'       => 40,
                    'submissions'       => 5,
                    'outreach_emails'   => 10,
                    'backlink_checks'   => 150,
                    'provider_lookups'  => 60,
                ],
                'weekly_limits'         => [
                    'discovery_queries' => 80,
                    'submissions'       => 20,
                    'outreach_emails'   => 40,
                ],
                'min_score_auto_queue'  => 70,
                'min_score_keep'        => 50,
                'max_execution_seconds' => 20,
                'tick_interval'         => 300,
                'international'         => true,
            ],
            'discovery'  => [
                'geo_modifiers'       => [ 'Canada', 'Canadian', 'Ontario', 'Toronto', 'British Columbia', 'Alberta', 'Quebec', 'Vancouver', 'Montreal', 'Ottawa' ],
                'competitor_domains'  => [],
                'brand_terms'         => [ 'Pure Peptides Canada', 'purepeptidescanada.com', 'purepeptidescanada' ],
                'query_cooldown_days' => 30,
                'results_per_query'   => 10,
                'seed_catalog'        => true,
                'custom_queries'      => [],
                'excluded_domains'    => [],
            ],
            'providers'  => [
                'seranking' => [ 'enabled' => true, 'region' => 'ca', 'cache_days' => 30 ],
                'ahrefs'    => [ 'enabled' => false, 'cache_days' => 30 ],
                'semrush'   => [ 'enabled' => false, 'database' => 'ca', 'cache_days' => 30 ],
                'moz'       => [ 'enabled' => false, 'cache_days' => 30 ],
                'google'    => [ 'enabled' => true, 'gsc_property' => 'sc-domain:' . $domain, 'ga4_property_id' => '', 'use_sitekit' => true ],
                'search'    => [ 'primary' => 'auto', 'google_cse_daily_limit' => 90, 'brave_daily_limit' => 60, 'serper_daily_limit' => 100 ],
                'ai'        => [ 'enabled' => false, 'model' => 'claude-opus-5-5', 'max_tokens' => 1200 ],
                'rdap'      => [ 'enabled' => true ],
            ],
            'outreach'   => [
                'sender_name'               => '',
                'sender_email'              => '',
                'reply_to'                  => '',
                'postal_address'            => '',
                'signature'                 => '',
                'require_approval'          => true,
                'follow_up_days'            => 7,
                'max_follow_ups'            => 1,
                'min_days_between_contacts' => 90,
                'send_window_start'         => 9,
                'send_window_end'           => 17,
                'send_days'                 => [ 1, 2, 3, 4, 5 ],
            ],
            'monitoring' => [
                'check_interval_days_new'     => 1,
                'check_interval_days_stable'  => 7,
                'lost_after_failures'         => 2,
                'digest_email'                => true,
                'notify_email'                => '',
                'notify_critical_immediately' => true,
                'import_interval_days'        => 7,
                'import_limit'                => 500,
            ],
            'advanced'   => [
                'delete_on_uninstall'  => false,
                'log_level'            => 'info',
                'log_retention_days'   => 30,
                'audit_retention_days' => 365,
                'user_agent'           => 'PPCLinkGrowthBot/1.0 (+' . untrailingslashit( $home ) . '/; link research)',
                'request_timeout'      => 15,
                'crawl_delay_seconds'  => 2,
                'max_response_kb'      => 1536,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function all(): array {
        if ( null === $this->cache ) {
            $stored      = get_option( self::OPTION, [] );
            $this->cache = self::mergeDeep( self::defaults(), is_array( $stored ) ? $stored : [] );
        }
        return $this->cache;
    }

    /**
     * @param string $path Dot path, e.g. "automation.daily_limits.submissions".
     */
    public function get( string $path, mixed $default = null ): mixed {
        $value = $this->all();
        foreach ( explode( '.', $path ) as $segment ) {
            if ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
                $value = $value[ $segment ];
            } else {
                return $default;
            }
        }
        return $value;
    }

    public function set( string $path, mixed $value ): void {
        $all  = $this->all();
        $ref  = &$all;
        $keys = explode( '.', $path );
        $last = array_pop( $keys );
        foreach ( $keys as $k ) {
            if ( ! isset( $ref[ $k ] ) || ! is_array( $ref[ $k ] ) ) {
                $ref[ $k ] = [];
            }
            $ref = &$ref[ $k ];
        }
        $ref[ $last ] = $value;
        $this->replace( $all );
    }

    /** @param array<string,mixed> $values */
    public function replace( array $values ): void {
        $this->cache = self::mergeDeep( self::defaults(), $values );
        update_option( self::OPTION, $this->cache, false );
    }

    /** @param array<string,mixed> $partial */
    public function update( array $partial ): void {
        $this->replace( self::mergeDeep( $this->all(), $partial ) );
    }

    public function ensureDefaults(): void {
        $stored = get_option( self::OPTION, null );
        if ( ! is_array( $stored ) ) {
            update_option( self::OPTION, self::defaults(), false );
            $this->cache = null;
        }
    }

    public function siteDomain(): string {
        return strtolower( (string) $this->get( 'site.domain', '' ) );
    }

    public function userAgent(): string {
        return (string) $this->get( 'advanced.user_agent', 'PPCLinkGrowthBot/1.0' );
    }

    /**
     * Recursive merge where arrays with numeric keys (lists) are replaced, not merged.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    public static function mergeDeep( array $base, array $over ): array {
        foreach ( $over as $k => $v ) {
            if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) && ! self::isList( $v ) && ! self::isList( $base[ $k ] ) ) {
                $base[ $k ] = self::mergeDeep( $base[ $k ], $v );
            } else {
                $base[ $k ] = $v;
            }
        }
        return $base;
    }

    /** @param array<mixed> $a */
    public static function isList( array $a ): bool {
        if ( [] === $a ) {
            return true;
        }
        return array_keys( $a ) === range( 0, count( $a ) - 1 );
    }
}
