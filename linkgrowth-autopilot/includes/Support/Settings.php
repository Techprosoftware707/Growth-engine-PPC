<?php
/**
 * Plugin settings (non-secret) with defaults and dot-path access.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Support;

final class Settings {

    public const OPTION = 'lga_settings';

    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    /** @return array<string,mixed> */
    public static function defaults(): array {
        $home   = function_exists( 'home_url' ) ? home_url( '/' ) : '';
        $host   = (string) wp_parse_url( $home, PHP_URL_HOST );
        $domain = (string) preg_replace( '/^www\./i', '', $host );

        return [
            // The business profile ships empty: every site enters its own (no brand, address or market is assumed).
            'site'       => [
                'brand_name'   => '',
                'legal_name'   => '',
                'domain'       => $domain,
                'home_url'     => untrailingslashit( $home ),
                'tagline'      => '',
                'email'        => '',
                'phone'        => '',
                'address'      => '',
                'city'         => '',
                'region'       => '',
                'postal_code'  => '',
                'country'      => '',
                'founded_year' => '',
                'logo_url'     => '',
                'social'       => [],
                'categories'   => [],
            ],
            'automation' => [
                'enabled'               => true,
                // One switch for hands-off growth (Automation\Autopilot): written only through
                // POST /automation/autopilot or POST /settings with confirm=true, which records the owner's consent.
                'autopilot'             => false,
                'discovery'             => true,
                'evaluation'            => true,
                'opportunities'         => true,
                'packaging'             => true,
                'submissions'           => true,
                'submission_mode'       => 'auto',
                'auto_outreach_drafts'  => true,
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
                // Competitors Autopilot found through SE Ranking while competitor_domains was empty (kept apart from
                // the owner's list; removable in Settings → Discovery; see Discovery\CompetitorBacklinkSource).
                'competitor_domains_auto' => [],
                'brand_terms'         => [], // the brand name, legal name, site title and domain are always brand terms (Seo\BrandTerms)
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
                'search'    => [ 'primary' => 'auto', 'google_cse_daily_limit' => 90, 'brave_daily_limit' => 60 ],
                'ai'        => [ 'enabled' => false, 'model' => '', 'max_tokens' => 1200 ],
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
                // IANA zone the send window is evaluated in; '' = the WordPress timezone, or the business region's
                // zone when WordPress is left on UTC (see Outreach\Compliance::sendTimezone()).
                'timezone'                  => '',
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
            'seo'        => [
                'indexnow'           => true,
                'indexnow_key'       => '', // generated once by Seo\IndexNow::key(); never edited through the settings form
                'audit_max_pages'    => 400,
                'audit_http_sample'  => true, // optional HTTP spot check of at most 10 URLs at the end of each audit
                'internal_links'     => true,
                'max_links_per_post' => 3,
                // Apply the best pending internal-link suggestions without a click (always on under Autopilot).
                'auto_apply_links'   => false,
                'auto_apply_per_day' => 20,
                // Post meta key (holding the id of the source-language item) that marks translated items; '' = none.
                'language_counterpart_meta_key' => '',
            ],
            'compliance' => [
                'storefront_gate'   => true,
                // Written only by Compliance\PositioningGate::acknowledge() (POST /compliance/storefront/acknowledge).
                'acknowledged_hash' => '',
                'acknowledged_by'   => 0,
                'acknowledged_at'   => '',
            ],
            'growth'     => [
                'evaluate_rounds' => 6,  // most evaluate job runs per growth step (1–20)
                'step_seconds'    => 20, // seconds per POST /growth/step (8–25)
            ],
            'advanced'   => [
                'delete_on_uninstall'  => false,
                'log_level'            => 'info',
                'log_retention_days'   => 30,
                'audit_retention_days' => 365,
                'user_agent'           => 'LinkGrowthAutopilotBot/1.0 (+' . untrailingslashit( $home ) . '/; link research)',
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

    /**
     * Drops this request's copy so the next read comes from the database. Settings is a container singleton that
     * caches the option for the whole request (a cron tick can run for tens of seconds), so code that must see a
     * change another request made meanwhile (Autopilot switched off, say) refreshes first.
     */
    public function refresh(): void {
        $this->cache = null;
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( self::OPTION, 'options' );
            $all = function_exists( 'wp_cache_get' ) ? wp_cache_get( 'alloptions', 'options' ) : false;
            if ( is_array( $all ) && array_key_exists( self::OPTION, $all ) ) {
                wp_cache_delete( 'alloptions', 'options' );
            }
        }
    }

    /**
     * Changes one path. The change is merged into a fresh read of the stored option, never into this request's
     * cached copy: a background job that writes one value must not put back settings the owner changed while it
     * ran (for example Autopilot switched off).
     */
    public function set( string $path, mixed $value ): void {
        $this->refresh();
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

    /** Merges $partial into a fresh read of the stored option (see set()). @param array<string,mixed> $partial */
    public function update( array $partial ): void {
        $this->refresh();
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

    /**
     * The business name to use in copy: site.brand_name, else the WordPress site title, else ''. No brand ships as
     * a default (the profile of each site names its own).
     */
    public function brandName(): string {
        $brand = trim( (string) $this->get( 'site.brand_name', '' ) );
        if ( '' === $brand && function_exists( 'get_bloginfo' ) ) {
            $brand = trim( (string) get_bloginfo( 'name' ) );
        }
        return $brand;
    }

    public function userAgent(): string {
        return (string) $this->get( 'advanced.user_agent', 'LinkGrowthAutopilotBot/1.0' );
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
