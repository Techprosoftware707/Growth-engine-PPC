<?php
/**
 * /settings — configuration, secrets (write-only), facts, seeds, provider tests and backups.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Admin\Rest;

use PPC\LinkGrowth\Automation\Scheduler;
use PPC\LinkGrowth\Content\ClaimsFilter;
use PPC\LinkGrowth\Content\FactsRegistry;
use PPC\LinkGrowth\Content\LinkableAssets;
use PPC\LinkGrowth\Database\Backup;
use PPC\LinkGrowth\Discovery\QueryGenerator;
use PPC\LinkGrowth\Discovery\SeedCatalog;
use PPC\LinkGrowth\Integrations\ClaudeWriter;
use PPC\LinkGrowth\Integrations\Google\Analytics4;
use PPC\LinkGrowth\Integrations\Google\SearchConsole;
use PPC\LinkGrowth\Integrations\Google\SiteKitBridge;
use PPC\LinkGrowth\Integrations\ProviderRegistry;
use PPC\LinkGrowth\Repositories\DomainMetrics;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Support\Crypto;
use PPC\LinkGrowth\Support\Redactor;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Url;
use WP_REST_Request;

final class SettingsController extends Controller {

    /**
     * Keys that services read but Settings::defaults() does not list; merged into the sanitising schema.
     * `content.linkable_assets` is the optional asset list documented for LinkableAssets.
     */
    private const EXTRA_SCHEMA = [
        'providers' => [
            'seranking' => [ 'fetch_overview' => true, 'fetch_summary' => false ],
            'ahrefs'    => [ 'fetch_metrics' => true ],
        ],
        'content'   => [ 'linkable_assets' => [] ],
    ];

    /** Clamp ranges for integer settings (by leaf key). */
    private const INT_RANGES = [
        'tick_interval'               => [ 60, 86400 ],
        'max_execution_seconds'       => [ 5, 120 ],
        'send_window_start'           => [ 0, 23 ],
        'send_window_end'             => [ 1, 24 ],
        'request_timeout'             => [ 3, 60 ],
        'crawl_delay_seconds'         => [ 0, 60 ],
        'max_response_kb'             => [ 64, 8192 ],
        'log_retention_days'          => [ 1, 365 ],
        'audit_retention_days'        => [ 7, 3650 ],
        'min_score_auto_queue'        => [ 0, 100 ],
        'min_score_keep'              => [ 0, 100 ],
        'results_per_query'           => [ 1, 50 ],
        'query_cooldown_days'         => [ 0, 365 ],
        'cache_days'                  => [ 1, 365 ],
        'follow_up_days'              => [ 1, 60 ],
        'max_follow_ups'              => [ 0, 3 ],
        'min_days_between_contacts'   => [ 0, 365 ],
        'lost_after_failures'         => [ 1, 10 ],
        'check_interval_days_new'     => [ 1, 30 ],
        'check_interval_days_stable'  => [ 1, 90 ],
        'import_interval_days'        => [ 1, 90 ],
        'import_limit'                => [ 10, 5000 ],
        'max_tokens'                  => [ 200, 8000 ],
        'google_cse_daily_limit'      => [ 0, 10000 ],
        'brave_daily_limit'           => [ 0, 100000 ],
        'serper_daily_limit'          => [ 0, 100000 ],
    ];

    private const EMAIL_KEYS    = [ 'email', 'sender_email', 'reply_to', 'notify_email' ];
    private const TEXTAREA_KEYS = [ 'signature', 'postal_address', 'address' ];
    private const DOMAIN_LISTS  = [ 'competitor_domains', 'excluded_domains' ];

    private const TEST_PROVIDERS = [ 'seranking', 'ahrefs', 'semrush', 'moz', 'google_search_console', 'google_analytics_4', 'google_cse', 'brave', 'serper', 'anthropic', 'sitekit', 'email' ];

    /** Secret key → provider id whose stored error is cleared when the key changes. */
    private const SECRET_PROVIDER = [
        'seranking_api_key'           => 'seranking',
        'ahrefs_api_key'              => 'ahrefs',
        'semrush_api_key'             => 'semrush',
        'moz_access_id'               => 'moz',
        'moz_secret_key'              => 'moz',
        'google_cse_api_key'          => 'google_cse',
        'google_cse_cx'               => 'google_cse',
        'brave_api_key'               => 'brave',
        'serper_api_key'              => 'serper',
        'anthropic_api_key'           => 'ai',
    ];

    public function register( string $namespace ): void {
        $this->route( $namespace, '/settings', 'GET', [ $this, 'show' ] );
        $this->route( $namespace, '/settings', 'POST', [ $this, 'update' ] );
        $this->route( $namespace, '/settings/test', 'POST', [ $this, 'test' ], [ 'provider' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ] ] );
        $this->route( $namespace, '/settings/facts/reset', 'POST', [ $this, 'resetFacts' ] );
        $this->route(
            $namespace,
            '/settings/backups/(?P<file>[\w\-]+\.sql\.gz)',
            'GET',
            [ $this, 'downloadBackup' ],
            [
                'file' => [
                    'type'              => 'string',
                    'required'          => true,
                    // Not sanitize_file_name(): core rewrites "name.sql.gz" to "name.sql_.gz" (sql is not an
                    // allowed upload type), which then fails the strict check below. Validate instead; the
                    // route regex, this check and the realpath containment check in downloadBackup() stop traversal.
                    'validate_callback' => static fn( $value ): bool => is_string( $value ) && self::isBackupFileName( $value ),
                    'sanitize_callback' => static fn( $value ): string => is_scalar( $value ) ? (string) $value : '',
                ],
            ]
        );
        $this->route( $namespace, '/settings/competitors/suggest', 'POST', [ $this, 'suggestCompetitors' ] );
    }

    /** GET /settings @return array<string,mixed> */
    public function show( WP_REST_Request $request ): array {
        unset( $request );
        $settings = $this->c->get( Settings::class );
        return [
            'settings'        => $settings->all(),
            'defaults'        => Settings::defaults(),
            'secrets'         => $this->safe( fn(): array => $this->c->get( Secrets::class )->status(), [], 'secret status' ),
            'providers'       => $this->safe( fn(): array => $this->c->get( ProviderRegistry::class )->status(), [], 'provider status' ),
            'facts'           => $this->safe( fn(): array => $this->c->get( FactsRegistry::class )->all(), [], 'facts' ),
            'facts_defaults'  => FactsRegistry::defaults(),
            'claims_rules'    => ClaimsFilter::rules(),
            'assets'          => $this->safe( fn(): array => $this->c->get( LinkableAssets::class )->all(), [], 'assets' ),
            'seeds'           => $this->safe( fn(): array => $this->c->get( SeedCatalog::class )->all(), [], 'seeds' ),
            'custom_seeds'    => $this->safe( static fn(): array => array_values( (array) get_option( 'ppclg_custom_seeds', [] ) ), [], 'custom seeds' ),
            'seeds_imported_at' => $this->safe( fn(): string => $this->c->get( SeedCatalog::class )->importedAt(), '', 'seeds imported' ),
            'query_pool_size' => (int) $this->safe( fn(): int => count( $this->c->get( QueryGenerator::class )->pool() ), 0, 'query pool' ),
            'crypto_strong'   => (bool) $this->safe( fn(): bool => $this->c->get( Crypto::class )->hasStrongKeys(), false, 'crypto' ),
            'backups'         => $this->safe( fn(): array => $this->c->get( Backup::class )->listBackups(), [], 'backups' ),
            'db_version'      => (string) get_option( 'ppclg_db_version', '' ),
            'version'         => defined( 'PPCLG_VERSION' ) ? PPCLG_VERSION : '',
            'test_providers'  => self::TEST_PROVIDERS,
            'endpoints'       => [
                'inbound_webhook' => esc_url_raw( rest_url( RestApi::NAMESPACE . '/inbound' ) ),
                'unsubscribe'     => esc_url_raw( rest_url( RestApi::NAMESPACE . '/unsubscribe' ) ),
            ],
            'site_timezone'   => wp_timezone_string(),
            'admin_email'     => (string) get_option( 'admin_email', '' ),
        ];
    }

    /**
     * POST /settings {settings, secrets, facts, custom_seeds}
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function update( WP_REST_Request $request ) {
        $settings = $this->c->get( Settings::class );
        $before   = $settings->all();
        $changed  = [];

        $incoming = $request->get_param( 'settings' );
        if ( is_array( $incoming ) && [] !== $incoming ) {
            $clean = $this->sanitizeSettings( $incoming );
            if ( [] !== $clean ) {
                $settings->update( $clean );
                $changed = self::diffKeys( $before, $settings->all() );
            }
        }

        $secretsIn    = $request->get_param( 'secrets' );
        $secretResult = is_array( $secretsIn ) ? $this->applySecrets( $secretsIn ) : [ 'updated' => [], 'cleared' => [], 'rejected' => [], 'locked' => [] ];

        $factsUpdated = false;
        $facts        = $request->get_param( 'facts' );
        if ( is_array( $facts ) ) {
            $this->c->get( FactsRegistry::class )->replace( $facts );
            $factsUpdated = true;
        }

        $seedsUpdated = false;
        $seeds        = $request->get_param( 'custom_seeds' );
        if ( is_array( $seeds ) ) {
            update_option( 'ppclg_custom_seeds', $this->sanitizeSeeds( $seeds ), false );
            $seedsUpdated = true;
        }

        if ( in_array( 'automation.tick_interval', $changed, true ) || ( in_array( 'automation.enabled', $changed, true ) && (bool) $settings->get( 'automation.enabled', false ) ) ) {
            $this->safe(
                function () use ( $changed ): void {
                    if ( ! class_exists( Scheduler::class ) ) {
                        return;
                    }
                    $scheduler = $this->c->get( Scheduler::class );
                    if ( in_array( 'automation.tick_interval', $changed, true ) ) {
                        $scheduler->unschedule();
                    }
                    $scheduler->schedule();
                },
                null,
                'reschedule'
            );
        }

        $secretKeys = array_merge( $secretResult['updated'], $secretResult['cleared'] );
        if ( [] !== $changed || [] !== $secretKeys || $factsUpdated || $seedsUpdated ) {
            $this->audit(
                'settings.updated',
                'settings',
                0,
                [
                    'changed'      => $changed,
                    'secret_keys'  => $secretKeys,
                    'facts'        => $factsUpdated,
                    'custom_seeds' => $seedsUpdated,
                ]
            );
        }

        return [
            'ok'            => true,
            'changed'       => $changed,
            'secrets'       => $secretResult,
            'facts_updated' => $factsUpdated,
            'seeds_updated' => $seedsUpdated,
            'settings'      => $settings->all(),
            'secret_status' => $this->safe( fn(): array => $this->c->get( Secrets::class )->status(), [], 'secret status' ),
            'providers'     => $this->safe( fn(): array => $this->c->get( ProviderRegistry::class )->status(), [], 'provider status' ),
        ];
    }

    /** POST /settings/test {provider} @return array<string,mixed>|\WP_Error */
    public function test( WP_REST_Request $request ) {
        $provider = sanitize_key( $this->str( $request, 'provider', '', 40 ) );
        if ( ! in_array( $provider, self::TEST_PROVIDERS, true ) ) {
            return $this->fail( 'ppclg_unknown_provider', __( 'Unknown provider.', 'ppc-link-growth-engine' ), 400, [ 'providers' => self::TEST_PROVIDERS ] );
        }
        $started = microtime( true );
        try {
            $result = $this->runTest( $provider );
        } catch ( \Throwable $e ) {
            $result = [ 'ok' => false, 'message' => 'Test failed: ' . $e->getMessage(), 'data' => [] ];
        }
        $result['message']    = Redactor::redactString( (string) ( $result['message'] ?? '' ) );
        $result['data']       = Redactor::redact( is_array( $result['data'] ?? null ) ? $result['data'] : [] );
        $result['ok']         = (bool) ( $result['ok'] ?? false );
        $result['provider']   = $provider;
        $result['elapsed_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
        $this->audit( 'settings.provider_tested', 'settings', 0, [ 'provider' => $provider, 'ok' => $result['ok'] ] );
        return $result;
    }

    /** POST /settings/facts/reset @return array<string,mixed> */
    public function resetFacts( WP_REST_Request $request ): array {
        unset( $request );
        $facts = $this->c->get( FactsRegistry::class );
        $facts->reset();
        $this->audit( 'settings.facts_reset', 'settings', 0, [] );
        return [ 'ok' => true, 'facts' => $facts->all() ];
    }

    /** GET /settings/backups/{file} @return \WP_REST_Response|\WP_Error */
    public function downloadBackup( WP_REST_Request $request ) {
        $file = (string) $request->get_param( 'file' );
        if ( ! self::isBackupFileName( $file ) ) {
            return $this->fail( 'ppclg_invalid_file', __( 'Invalid backup file name.', 'ppc-link-growth-engine' ), 400 );
        }
        $dir     = $this->c->get( Backup::class )->directory();
        $dirReal = realpath( $dir );
        $real    = realpath( $dir . '/' . $file );
        if ( false === $dirReal || false === $real || ! str_starts_with( $real, $dirReal . DIRECTORY_SEPARATOR ) || ! is_file( $real ) || ! is_readable( $real ) ) {
            return $this->notFound( __( 'Backup', 'ppc-link-growth-engine' ) );
        }
        $this->audit( 'settings.backup_downloaded', 'backup', 0, [ 'file' => $file ] );
        return $this->rawFile( $real, 'application/gzip', $file );
    }

    /** A bare backup file name as Backup creates it ("20261111-234005-manual-AbCd1234.sql.gz"); no paths. */
    public static function isBackupFileName( string $file ): bool {
        return 1 === preg_match( '/^[\w\-]+\.sql\.gz$/D', $file );
    }

    /** POST /settings/competitors/suggest @return array<string,mixed>|\WP_Error */
    public function suggestCompetitors( WP_REST_Request $request ) {
        unset( $request );
        $se = $this->c->get( ProviderRegistry::class )->seranking();
        if ( ! $se->isConfigured() ) {
            return $this->fail( 'ppclg_not_configured', __( 'SE Ranking is not configured: add the API key first.', 'ppc-link-growth-engine' ), 400 );
        }
        $settings = $this->c->get( Settings::class );
        $domain   = $settings->siteDomain();
        $list     = $se->competitors( $domain );
        if ( [] === $list && '' !== $se->lastError() ) {
            return $this->fail( 'ppclg_provider_error', Redactor::redactString( $se->lastError() ), 502 );
        }
        $list     = array_values( array_filter( array_unique( array_map( static fn( string $d ): string => Url::registrableDomain( $d ), $list ) ), static fn( string $d ): bool => '' !== $d && $d !== $domain ) );
        $existing = array_values( array_map( 'strval', (array) $settings->get( 'discovery.competitor_domains', [] ) ) );
        $this->audit( 'settings.competitors_suggested', 'settings', 0, [ 'count' => count( $list ) ] );
        return [
            'ok'          => true,
            'competitors' => $list,
            'existing'    => $existing,
            'new'         => array_values( array_diff( $list, $existing ) ),
            'note'        => __( 'SE Ranking competitor lookups consume API credits; results are not saved until you add them to the competitor list.', 'ppc-link-growth-engine' ),
        ];
    }

    // ------------------------------------------------------------------
    // Settings sanitisation
    // ------------------------------------------------------------------

    /**
     * Recursively sanitises a partial settings tree against the defaults schema; unknown keys are dropped.
     *
     * @param array<mixed> $input
     * @return array<string,mixed>
     */
    private function sanitizeSettings( array $input ): array {
        $schema = Settings::mergeDeep( Settings::defaults(), self::EXTRA_SCHEMA );
        $out    = [];
        foreach ( $input as $key => $value ) {
            if ( ! is_string( $key ) || ! isset( $schema[ $key ] ) || ! is_array( $schema[ $key ] ) || ! is_array( $value ) ) {
                continue;
            }
            $clean = $this->sanitizeNode( $value, $schema[ $key ], $key );
            if ( [] !== $clean ) {
                $out[ $key ] = $clean;
            }
        }
        return $out;
    }

    /** @param array<mixed> $value @param array<mixed> $schema @return array<mixed> */
    private function sanitizeNode( array $value, array $schema, string $path ): array {
        if ( Settings::isList( $schema ) ) {
            return $this->sanitizeList( $value, $schema, $path );
        }
        $out = [];
        foreach ( $value as $key => $v ) {
            if ( ! is_string( $key ) || ! array_key_exists( $key, $schema ) ) {
                continue;
            }
            $default = $schema[ $key ];
            if ( is_array( $default ) ) {
                if ( is_string( $v ) && Settings::isList( $default ) ) {
                    // Lists may arrive as newline/comma separated text.
                    $v = preg_split( '/[\r\n,]+/', $v ) ?: [];
                }
                if ( ! is_array( $v ) ) {
                    continue;
                }
                $node = $this->sanitizeNode( $v, $default, $path . '.' . $key );
                // An empty object would replace the whole section through mergeDeep; only lists may be cleared.
                if ( [] !== $node || Settings::isList( $default ) ) {
                    $out[ $key ] = $node;
                }
                continue;
            }
            $clean = $this->sanitizeScalar( $v, $default, $path . '.' . $key );
            if ( null !== $clean ) {
                $out[ $key ] = $clean;
            }
        }
        return $out;
    }

    /** @param array<mixed> $value @param array<mixed> $schema @return array<int,mixed> */
    private function sanitizeList( array $value, array $schema, string $path ): array {
        $leaf = (string) substr( strrchr( '.' . $path, '.' ), 1 );
        if ( 'linkable_assets' === $leaf ) {
            return $this->sanitizeAssets( $value );
        }
        $ints = isset( $schema[0] ) && is_int( $schema[0] );
        $out  = [];
        foreach ( array_slice( array_values( $value ), 0, 500 ) as $v ) {
            if ( $ints ) {
                if ( is_numeric( $v ) ) {
                    $i = (int) $v;
if ( 'send_days' === $leaf && ( $i < 0 || $i > 7 ) ) {
                        continue;
                    }
                    $out[] = $i;
                }
                continue;
            }
            if ( ! is_scalar( $v ) ) {
                continue;
            }
            $s = trim( sanitize_text_field( (string) $v ) );
            if ( '' === $s ) {
                continue;
            }
            if ( in_array( $leaf, self::DOMAIN_LISTS, true ) ) {
                $s = self::domainValue( $s );
                if ( '' === $s ) {
                    continue;
                }
            } elseif ( 'social' === $leaf ) {
                $s = self::validUrl( $s );
                if ( '' === $s ) {
                    continue;
                }
            }
            $out[] = mb_substr( $s, 0, 500 );
        }
        return array_values( array_unique( $out, SORT_REGULAR ) );
    }

    private function sanitizeScalar( mixed $value, mixed $default, string $path ): mixed {
        if ( is_array( $value ) || is_object( $value ) ) {
            return null;
        }
        $key      = (string) substr( strrchr( '.' . $path, '.' ), 1 );
        $provider = str_starts_with( $path, 'providers.' );
        if ( 'automation.submission_mode' === $path ) {
            $mode = strtolower( sanitize_key( (string) $value ) );
            return in_array( $mode, \PPC\LinkGrowth\Submission\Policy::SUBMISSION_MODES, true ) ? $mode : 'auto';
        }
        if ( is_bool( $default ) ) {
            if ( is_bool( $value ) ) {
                return $value;
            }
            return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
        }
        if ( is_int( $default ) ) {
            if ( ! is_numeric( $value ) ) {
                return null;
            }
            $i = (int) $value;
            if ( isset( self::INT_RANGES[ $key ] ) ) {
                [ $min, $max ] = self::INT_RANGES[ $key ];
                return max( $min, min( $max, $i ) );
            }
            return max( 0, $i );
        }
        if ( is_float( $default ) ) {
            return is_numeric( $value ) ? (float) $value : null;
        }
        $s = (string) $value;
        if ( in_array( $key, self::EMAIL_KEYS, true ) ) {
            $s = trim( $s );
            if ( '' === $s ) {
                return '';
            }
            $email = sanitize_email( $s );
            return is_email( $email ) ? $email : null;
        }
        if ( str_ends_with( $key, '_url' ) ) {
            $s = trim( $s );
            if ( '' === $s ) {
                return '';
            }
            $url = self::validUrl( $s );
            return '' === $url ? null : $url;
        }
        if ( in_array( $key, self::TEXTAREA_KEYS, true ) ) {
            return mb_substr( sanitize_textarea_field( $s ), 0, 2000 );
        }
        switch ( $key ) {
            case 'log_level':
                $s = sanitize_key( $s );
                return in_array( $s, [ 'debug', 'info', 'warning', 'error' ], true ) ? $s : null;
            case 'primary':
                $s = sanitize_key( $s );
                return in_array( $s, [ 'auto', 'google_cse', 'brave', 'serper' ], true ) ? $s : null;
            case 'model':
                $s = strtolower( trim( $s ) );
                return preg_match( '/^[a-z0-9\-\.]{3,80}$/', $s ) ? $s : null;
            case 'region':
            case 'database':
                if ( ! $provider ) {
                    break;
                }
                $s = strtolower( preg_replace( '/[^a-z0-9]/i', '', $s ) ?? '' );
                return '' === $s ? null : substr( $s, 0, 10 );
            case 'country':
                $s = strtoupper( preg_replace( '/[^a-z]/i', '', $s ) ?? '' );
                return 2 === strlen( $s ) ? $s : null;
            case 'domain':
                $d = self::domainValue( $s );
                return '' === $d ? null : $d;
            case 'founded_year':
                $s = preg_replace( '/\D/', '', $s ) ?? '';
                return substr( $s, 0, 4 );
            case 'ga4_property_id':
                return substr( preg_replace( '/\D/', '', $s ) ?? '', 0, 20 );
            case 'user_agent':
                $s = trim( sanitize_text_field( $s ) );
                return '' === $s ? null : mb_substr( $s, 0, 255 );
        }
        return mb_substr( trim( sanitize_text_field( $s ) ), 0, 1000 );
    }

    /** @param array<mixed> $value @return array<int,array<string,mixed>> */
    private function sanitizeAssets( array $value ): array {
        $out = [];
        foreach ( array_slice( array_values( $value ), 0, 100 ) as $asset ) {
            if ( ! is_array( $asset ) ) {
                continue;
            }
            $url   = self::validUrl( (string) ( is_scalar( $asset['url'] ?? null ) ? $asset['url'] : '' ) );
            $title = trim( sanitize_text_field( (string) ( is_scalar( $asset['title'] ?? null ) ? $asset['title'] : '' ) ) );
            if ( '' === $url || '' === $title ) {
                continue;
            }
            $rawTopics = $asset['topics'] ?? [];
            if ( is_string( $rawTopics ) ) {
                $rawTopics = preg_split( '/[\r\n,]+/', $rawTopics ) ?: [];
            }
            $topics = [];
            foreach ( array_slice( (array) $rawTopics, 0, 30 ) as $t ) {
                if ( is_scalar( $t ) ) {
                    $t = strtolower( trim( sanitize_text_field( (string) $t ) ) );
                    if ( '' !== $t ) {
                        $topics[] = mb_substr( $t, 0, 60 );
                    }
                }
            }
            $kind  = sanitize_key( (string) ( is_scalar( $asset['kind'] ?? null ) ? $asset['kind'] : 'guide' ) );
            $out[] = [
                'url'     => mb_substr( $url, 0, 700 ),
                'title'   => mb_substr( $title, 0, 190 ),
                'summary' => mb_substr( sanitize_textarea_field( (string) ( is_scalar( $asset['summary'] ?? null ) ? $asset['summary'] : '' ) ), 0, 1000 ),
                'topics'  => array_values( array_unique( $topics ) ),
                'kind'    => '' === $kind ? 'guide' : $kind,
            ];
        }
        return $out;
    }

    /** @param array<mixed> $seeds @return array<int,array<string,string>> */
    private function sanitizeSeeds( array $seeds ): array {
        $out  = [];
        $seen = [];
        foreach ( array_slice( array_values( $seeds ), 0, 500 ) as $seed ) {
            if ( ! is_array( $seed ) ) {
                continue;
            }
            $url = self::validUrl( (string) ( is_scalar( $seed['url'] ?? null ) ? $seed['url'] : '' ) );
            if ( '' === $url || isset( $seen[ $url ] ) ) {
                continue;
            }
            $seen[ $url ] = true;
            $opportunity  = sanitize_key( (string) ( is_scalar( $seed['opportunity'] ?? null ) ? $seed['opportunity'] : 'directory_listing' ) );
            $type         = sanitize_key( (string) ( is_scalar( $seed['type'] ?? null ) ? $seed['type'] : 'directory' ) );
            $out[]        = [
                'url'         => mb_substr( $url, 0, 700 ),
                'name'        => mb_substr( sanitize_text_field( (string) ( is_scalar( $seed['name'] ?? null ) ? $seed['name'] : '' ) ), 0, 190 ),
                'type'        => '' === $type ? 'directory' : $type,
                'opportunity' => isset( Opportunities::TYPES[ $opportunity ] ) ? $opportunity : 'directory_listing',
                'category'    => mb_substr( sanitize_text_field( (string) ( is_scalar( $seed['category'] ?? null ) ? $seed['category'] : '' ) ), 0, 100 ),
                'country'     => substr( strtoupper( preg_replace( '/[^a-z]/i', '', (string) ( is_scalar( $seed['country'] ?? null ) ? $seed['country'] : '' ) ) ?? '' ), 0, 2 ),
            ];
        }
        return $out;
    }

    /** Absolute http(s) URL with a dotted host, or '' when unusable. */
    private static function validUrl( string $value ): string {
        $url = esc_url_raw( trim( $value ) );
        if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
            return '';
        }
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        if ( '' === $host || ( ! str_contains( $host, '.' ) && 'localhost' !== strtolower( $host ) ) ) {
            return '';
        }
        return mb_substr( $url, 0, 700 );
    }

    /** Host-only, lower-case domain value ('' when unusable). */
    private static function domainValue( string $value ): string {
        $value = strtolower( trim( $value ) );
        if ( '' === $value ) {
            return '';
        }
        $host = ( str_contains( $value, '/' ) || str_contains( $value, ':' ) ) ? Url::host( $value ) : $value;
        $host = preg_replace( '/^www\./', '', $host ) ?? $host;
        return preg_match( '/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $host ) ? $host : '';
    }

    /**
     * Writes secrets (write-only: values are never returned).
     *
     * @param array<mixed> $input
     * @return array{updated:string[],cleared:string[],rejected:string[],locked:string[]}
     */
    private function applySecrets( array $input ): array {
        $secrets = $this->c->get( Secrets::class );
        $result  = [ 'updated' => [], 'cleared' => [], 'rejected' => [], 'locked' => [] ];
        $n       = 0;
        foreach ( $input as $key => $value ) {
            if ( ++$n > 50 ) {
                break;
            }
            $key = is_string( $key ) ? (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( $key ) ) : '';
            if ( '' === $key ) {
                continue;
            }
            if ( ! Secrets::isValidKey( $key ) ) {
                $result['rejected'][] = $key;
                continue;
            }
            if ( ! is_string( $value ) ) {
                continue;
            }
            $value = trim( $value );
            if ( '' === $value ) {
                continue;
            }
            if ( $secrets->isFromConstant( $key ) ) {
                $result['locked'][] = $key;
                continue;
            }
            if ( '__clear__' === $value ) {
                $secrets->delete( $key );
                $result['cleared'][] = $key;
                $this->clearProviderError( $key );
                continue;
            }
            if ( strlen( $value ) > 20000 ) {
                $result['rejected'][] = $key;
                continue;
            }
            if ( 'google_service_account_json' === $key ) {
                $json = json_decode( $value, true );
                if ( ! is_array( $json ) || empty( $json['client_email'] ) || empty( $json['private_key'] ) ) {
                    $result['rejected'][] = $key;
                    continue;
                }
            }
            $secrets->set( $key, $value );
            $result['updated'][] = $key;
            $this->clearProviderError( $key );
        }
        return $result;
    }

    private function clearProviderError( string $secretKey ): void {
        $provider = self::SECRET_PROVIDER[ $secretKey ] ?? '';
        if ( '' !== $provider ) {
            delete_option( 'ppclg_provider_error_' . $provider );
        }
        if ( 'seranking_api_key' === $secretKey ) {
            delete_transient( OverviewController::CACHE_SERANKING );
        }
        if ( 'google_service_account_json' === $secretKey ) {
            delete_transient( OverviewController::CACHE_GSC );
            delete_transient( OverviewController::CACHE_REFERRAL );
        }
    }

    /**
     * Dot-path keys whose value differs between two settings trees (lists compared whole).
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return string[]
     */
    private static function diffKeys( array $before, array $after ): array {
        $a   = self::flatten( $before );
        $b   = self::flatten( $after );
        $out = [];
        foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $key ) {
            $va = array_key_exists( $key, $a ) ? wp_json_encode( $a[ $key ] ) : null;
            $vb = array_key_exists( $key, $b ) ? wp_json_encode( $b[ $key ] ) : null;
            if ( $va !== $vb ) {
                $out[] = $key;
            }
        }
        sort( $out );
        return $out;
    }

    /** @param array<string,mixed> $tree @return array<string,mixed> */
    private static function flatten( array $tree, string $prefix = '' ): array {
        $out = [];
        foreach ( $tree as $key => $value ) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
            if ( is_array( $value ) && ! Settings::isList( $value ) ) {
                $out += self::flatten( $value, $path );
            } else {
                $out[ $path ] = $value;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Live provider tests
    // ------------------------------------------------------------------

    /** @return array{ok:bool,message:string,data:array<string,mixed>} */
    private function runTest( string $provider ): array {
        $settings = $this->c->get( Settings::class );
        $registry = $this->c->get( ProviderRegistry::class );
        $domain   = $settings->siteDomain();
        $brand    = (string) $settings->get( 'site.brand_name', 'Pure Peptides Canada' );

        switch ( $provider ) {
            case 'seranking':
                $se = $registry->seranking();
                if ( ! $se->isConfigured() ) {
                    return [ 'ok' => false, 'message' => 'SE Ranking API key is not configured.', 'data' => [] ];
                }
                $sub = $se->subscription();
                if ( null === $sub ) {
                    return [ 'ok' => false, 'message' => 'SE Ranking rejected the request: ' . ( $se->lastError() ?: 'unknown error' ), 'data' => [] ];
                }
                return [ 'ok' => true, 'message' => sprintf( 'Connected. %s units left of %s (status: %s).', number_format_i18n( (int) $sub['units_left'] ), number_format_i18n( (int) $sub['units_limit'] ), (string) $sub['status'] ), 'data' => $sub ];

            case 'ahrefs':
            case 'semrush':
            case 'moz':
                $p = $registry->metricsProvider( $provider );
                if ( null === $p ) {
                    return [ 'ok' => false, 'message' => 'Provider not registered.', 'data' => [] ];
                }
                if ( ! $p->isConfigured() ) {
                    return [ 'ok' => false, 'message' => $p->label() . ' credentials are not configured.', 'data' => [] ];
                }
                $m = $p->domainMetrics( $domain );
                if ( ! is_array( $m ) ) {
                    return [ 'ok' => false, 'message' => $p->label() . ' lookup failed: ' . ( $p->lastError() ?: 'no data returned' ), 'data' => [] ];
                }
                $this->safe( fn() => $this->c->get( DomainMetrics::class )->put( $domain, $provider, array_merge( $m, [ 'fetched_at' => gmdate( 'c' ) ] ), (int) $settings->get( 'providers.' . $provider . '.cache_days', 30 ) ), null, 'cache metrics' );
                unset( $m['raw'] );
                return [ 'ok' => true, 'message' => sprintf( '%s connected. %s for %s: %s.', $p->label(), (string) ( $m['authority_label'] ?? 'Authority' ), $domain, isset( $m['authority'] ) ? (string) $m['authority'] : 'n/a' ), 'data' => $m ];

            case 'google_search_console':
                $gsc = $this->c->get( SearchConsole::class );
                if ( ! $gsc->isConfigured() ) {
                    return [ 'ok' => false, 'message' => 'Service account JSON and a Search Console property are required.', 'data' => [ 'property' => $gsc->property() ] ];
                }
                $t = $gsc->totals( 28 );
                if ( null === $t ) {
                    return [ 'ok' => false, 'message' => 'Search Console request failed: ' . ( $gsc->lastError() ?: 'unknown error' ), 'data' => [ 'property' => $gsc->property() ] ];
                }
                delete_transient( OverviewController::CACHE_GSC );
                return [ 'ok' => true, 'message' => sprintf( 'Connected to %s: %s clicks and %s impressions in the last 28 days.', $gsc->property(), number_format_i18n( (int) round( (float) $t['clicks'] ) ), number_format_i18n( (int) round( (float) $t['impressions'] ) ) ), 'data' => array_merge( $t, [ 'property' => $gsc->property() ] ) ];

            case 'google_analytics_4':
                $ga = $this->c->get( Analytics4::class );
                if ( ! $ga->isConfigured() ) {
                    return [ 'ok' => false, 'message' => 'Service account JSON and a GA4 property ID are required.', 'data' => [] ];
                }
                $t = $ga->referralTotals( 28 );
                if ( null === $t ) {
                    return [ 'ok' => false, 'message' => 'GA4 request failed: ' . ( $ga->lastError() ?: 'unknown error' ), 'data' => [ 'property_id' => $ga->propertyId() ] ];
                }
                delete_transient( OverviewController::CACHE_REFERRAL );
                return [ 'ok' => true, 'message' => sprintf( 'Connected to property %s: %s referral sessions in the last 28 days.', $ga->propertyId(), number_format_i18n( (int) $t['sessions'] ) ), 'data' => array_merge( $t, [ 'property_id' => $ga->propertyId() ] ) ];

            case 'google_cse':
            case 'brave':
            case 'serper':
                $p = $registry->searchProvider( $provider );
                if ( null === $p ) {
                    return [ 'ok' => false, 'message' => 'Search provider not registered.', 'data' => [] ];
                }
                if ( ! $p->isConfigured() ) {
                    return [ 'ok' => false, 'message' => $p->label() . ' credentials are not configured.', 'data' => [] ];
                }
                if ( $p->remainingToday() <= 0 ) {
                    return [ 'ok' => false, 'message' => $p->label() . ' daily quota is exhausted; try again tomorrow.', 'data' => [ 'remaining_today' => 0 ] ];
                }
                $results = $p->search( '"' . $brand . '"', [ 'num' => 3 ] );
                if ( '' !== $p->lastError() ) {
                    return [ 'ok' => false, 'message' => $p->label() . ' request failed: ' . $p->lastError(), 'data' => [] ];
                }
                return [
                    'ok'      => true,
                    'message' => sprintf( '%s connected: %d result(s) for "%s" (one query consumed; %d left today).', $p->label(), count( $results ), $brand, $p->remainingToday() ),
                    'data'    => [ 'results' => array_map( static fn( array $r ): array => [ 'url' => $r['url'], 'title' => $r['title'] ], array_slice( $results, 0, 3 ) ), 'remaining_today' => $p->remainingToday() ],
                ];

            case 'anthropic':
                $ai = $this->c->get( ClaudeWriter::class );
                if ( ! $ai->isConfigured() ) {
                    return [ 'ok' => false, 'message' => 'Anthropic API key is not configured.', 'data' => [] ];
                }
                if ( ! $ai->isEnabled() ) {
                    return [ 'ok' => false, 'message' => 'The AI writer is disabled: enable it under Integrations → AI writer before testing.', 'data' => [ 'model' => $ai->model() ] ];
                }
                $text = $ai->generate( 'You are a connectivity check. Reply with exactly the word OK and nothing else.', 'Connectivity check.', 200 );
                if ( null === $text ) {
                    return [ 'ok' => false, 'message' => 'Anthropic request failed: ' . ( $ai->lastError() ?: 'empty response' ), 'data' => [ 'model' => $ai->model() ] ];
                }
                return [ 'ok' => true, 'message' => sprintf( 'Connected to model %s.', $ai->model() ), 'data' => [ 'model' => $ai->model(), 'reply' => mb_substr( $text, 0, 40 ) ] ];

            case 'sitekit':
                $sk = $this->c->get( SiteKitBridge::class );
                if ( ! $sk->isAvailable() ) {
                    return [ 'ok' => false, 'message' => 'Site Kit by Google is not active (or no user context).', 'data' => [] ];
                }
                $t = $sk->searchConsoleTotals( 28 );
                if ( null === $t ) {
                    return [ 'ok' => false, 'message' => 'Site Kit did not return Search Console data: ' . ( $sk->lastError() ?: 'module not connected' ), 'data' => [] ];
                }
                delete_transient( OverviewController::CACHE_GSC );
                return [ 'ok' => true, 'message' => sprintf( 'Site Kit bridge works: %s clicks in the last 28 days.', number_format_i18n( (int) round( (float) $t['clicks'] ) ) ), 'data' => $t ];

            case 'email':
                return $this->testEmail( $brand );
        }
        return [ 'ok' => false, 'message' => 'Unknown provider.', 'data' => [] ];
    }

    /** @return array{ok:bool,message:string,data:array<string,mixed>} */
    private function testEmail( string $brand ): array {
        $user = wp_get_current_user();
        $to   = (string) $user->user_email;
        if ( '' === $to || ! is_email( $to ) ) {
            return [ 'ok' => false, 'message' => 'Your WordPress account has no valid email address.', 'data' => [] ];
        }
        $error = '';
        $hook  = static function ( $e ) use ( &$error ): void {
            $error = ( $e instanceof \WP_Error ) ? $e->get_error_message() : 'unknown error';
        };
        add_action( 'wp_mail_failed', $hook );
        $sent = wp_mail(
            $to,
            sprintf( '[%s] Link Growth Engine test email', $brand ),
            "This is a test message from the PPC Link Growth Engine.\n\nIf you received it, outgoing email from this WordPress site works. No action is needed.\n\nSent " . gmdate( 'c' ) . ' UTC',
            [ 'Content-Type: text/plain; charset=UTF-8' ]
        );
        remove_action( 'wp_mail_failed', $hook );
        $masked = Redactor::redactString( $to );
        if ( ! $sent ) {
            return [ 'ok' => false, 'message' => 'wp_mail() failed' . ( '' !== $error ? ': ' . $error : '.' ), 'data' => [ 'to' => $masked ] ];
        }
        return [ 'ok' => true, 'message' => 'Test email handed to the mail transport for ' . $masked . '. Check the inbox (and spam folder).', 'data' => [ 'to' => $masked ] ];
    }
}
