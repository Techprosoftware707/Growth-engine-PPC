<?php
/**
 * /settings — configuration, secrets (write-only), facts, seeds, provider tests and backups.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Admin\Rest;

use LinkGrowth\Autopilot\Automation\Autopilot;
use LinkGrowth\Autopilot\Automation\Scheduler;
use LinkGrowth\Autopilot\Content\ClaimsFilter;
use LinkGrowth\Autopilot\Content\FactsRegistry;
use LinkGrowth\Autopilot\Content\LinkableAssets;
use LinkGrowth\Autopilot\Database\Backup;
use LinkGrowth\Autopilot\Discovery\QueryGenerator;
use LinkGrowth\Autopilot\Discovery\PackSeeds;
use LinkGrowth\Autopilot\Integrations\AiWriter;
use LinkGrowth\Autopilot\Integrations\Google\Analytics4;
use LinkGrowth\Autopilot\Integrations\Google\SearchConsole;
use LinkGrowth\Autopilot\Integrations\Google\SiteKitBridge;
use LinkGrowth\Autopilot\Integrations\ProviderRegistry;
use LinkGrowth\Autopilot\Outreach\Compliance;
use LinkGrowth\Autopilot\Plugin;
use LinkGrowth\Autopilot\Repositories\DomainMetrics;
use LinkGrowth\Autopilot\Repositories\Opportunities;
use LinkGrowth\Autopilot\Seo\InternalLinker;
use LinkGrowth\Autopilot\Support\Crypto;
use LinkGrowth\Autopilot\Support\Redactor;
use LinkGrowth\Autopilot\Support\Secrets;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\SettingsSchema;
use LinkGrowth\Autopilot\Support\Url;
use WP_REST_Request;

final class SettingsController extends Controller {

    private const TEST_PROVIDERS = [ 'seranking', 'ahrefs', 'semrush', 'moz', 'google_search_console', 'google_analytics_4', 'google_cse', 'brave', 'anthropic', 'sitekit', 'email' ];

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
            'seeds'           => $this->safe( fn(): array => $this->c->get( PackSeeds::class )->all(), [], 'seeds' ),
            'custom_seeds'    => $this->safe( static fn(): array => array_values( (array) get_option( 'lga_custom_seeds', [] ) ), [], 'custom seeds' ),
            'seeds_imported_at' => $this->safe( fn(): string => $this->c->get( PackSeeds::class )->importedAt(), '', 'seeds imported' ),
            'query_pool_size' => (int) $this->safe( fn(): int => count( $this->c->get( QueryGenerator::class )->pool() ), 0, 'query pool' ),
            'crypto_strong'   => (bool) $this->safe( fn(): bool => $this->c->get( Crypto::class )->hasStrongKeys(), false, 'crypto' ),
            'backups'         => $this->safe( fn(): array => $this->c->get( Backup::class )->listBackups(), [], 'backups' ),
            'db_version'      => (string) get_option( 'lga_db_version', '' ),
            'version'         => defined( 'LGA_VERSION' ) ? LGA_VERSION : '',
            'test_providers'  => self::TEST_PROVIDERS,
            'endpoints'       => [
                'inbound_webhook' => esc_url_raw( rest_url( RestApi::NAMESPACE . '/inbound' ) ),
                'unsubscribe'     => esc_url_raw( rest_url( RestApi::NAMESPACE . '/unsubscribe' ) ),
            ],
            'site_timezone'   => wp_timezone_string(),
            // The zone the outreach send window is evaluated in (outreach.timezone, or derived; see Compliance).
            'send_timezone'   => $this->safe( fn(): array => $this->c->get( Compliance::class )->sendTimezoneInfo(), [], 'send timezone' ),
            'autopilot'       => $this->safe( fn(): array => $this->c->get( Autopilot::class )->status(), [], 'autopilot status' ),
            'admin_email'     => (string) get_option( 'admin_email', '' ),
        ];
    }

    /**
     * POST /settings {settings, secrets, facts, custom_seeds, confirm}
     *
     * Switching automation.submissions or automation.outreach_autosend on needs confirm=true, as on
     * POST /automation/toggle, and so does turning outreach.require_approval off while outreach autosend is on
     * (that is what lets automation approve and send its own first-contact drafts), switching seo.auto_apply_links on
     * (also while Autopilot is on; only then is lga_auto_links_consent recorded) and turning the storefront
     * compliance gate off. automation.autopilot is never stored from the form directly: switching it on needs
     * confirm=true and goes through Automation\Autopilot, which
     * records the consent (the consent itself lives in its own option and cannot be written here); switching it off
     * returns what Autopilot approved but did not send to draft. Linkable assets must pass the claims filter.
     * Otherwise nothing is saved.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public function update( WP_REST_Request $request ) {
        $settings = $this->c->get( Settings::class );
        $before   = $settings->all();
        $changed  = [];

        // Every value goes through the rule its module declares (Support\SettingsSchema): unknown keys, refused
        // values and read-only keys (the storefront acknowledgement, the IndexNow key) are left out.
        $incoming = $request->get_param( 'settings' );
        $clean    = is_array( $incoming ) && [] !== $incoming ? $this->c->get( SettingsSchema::class )->sanitize( $incoming ) : [];

        // Autopilot is switched only through Automation\Autopilot (consent record, revert on off), never stored as-is.
        $autopilot       = $this->c->get( Autopilot::class );
        $autopilotWanted = null;
        if ( isset( $clean['automation'] ) && is_array( $clean['automation'] ) && array_key_exists( 'autopilot', $clean['automation'] ) ) {
            $autopilotWanted = (bool) $clean['automation']['autopilot'];
            unset( $clean['automation']['autopilot'] );
            if ( [] === $clean['automation'] ) {
                unset( $clean['automation'] );
            }
            if ( $autopilotWanted === $autopilot->isOn() ) {
                $autopilotWanted = null; // no change
            }
        }

        $enabling = [];
        if ( true === $autopilotWanted ) {
            $enabling[] = 'autopilot';
        }
        foreach ( AutomationController::CONFIRM_FLAGS as $flag ) {
            if ( true === ( $clean['automation'][ $flag ] ?? null ) && ! (bool) $settings->get( 'automation.' . $flag, false ) ) {
                $enabling[] = $flag;
            }
        }
        // Autosend approves auto-drafted first messages only while "Require individual approval" is off, so turning it
        // off with autosend on (after this request) is gated too; switching autosend on here already asks for it.
        if (
            false === ( $clean['outreach']['require_approval'] ?? null )
            && (bool) $settings->get( 'outreach.require_approval', true )
            && (bool) ( $clean['automation']['outreach_autosend'] ?? $settings->get( 'automation.outreach_autosend', false ) )
            && ! in_array( 'outreach_autosend', $enabling, true )
        ) {
            $enabling[] = 'require_approval';
        }
        // Switching the storefront compliance gate off lets automated submissions and outreach sending continue while
        // the storefront contradicts the research-use-only listings, so it needs the same explicit confirmation.
        if ( false === ( $clean['compliance']['storefront_gate'] ?? null ) && (bool) $settings->get( 'compliance.storefront_gate', true ) ) {
            $enabling[] = 'storefront_gate_off';
        }
        // Automatic internal links edit published pages without review and, once Autopilot is off, save a page with
        // administrator-only markup as the account of whoever switched them on, so switching them on needs the same
        // explicit confirmation, also while Autopilot is on (its own consent covers only the time it is on).
        if ( true === ( $clean['seo']['auto_apply_links'] ?? null ) && ! (bool) $settings->get( 'seo.auto_apply_links', false ) ) {
            $enabling[] = 'auto_apply_links';
        }
        if ( [] !== $enabling && ! $this->bool( $request, 'confirm', false ) ) {
            return $this->fail(
                'lga_confirmation_required',
                implode( ' ', array_map( [ self::class, 'confirmationText' ], $enabling ) ),
                400,
                [ 'confirm_required' => true, 'key' => $enabling[0], 'keys' => $enabling ]
            );
        }

        $unclean = $this->uncleanAssets( $clean );
        if ( [] !== $unclean ) {
            $lines = array_map( static fn( array $a ): string => sprintf( '“%s” (%s)', $a['title'], implode( '; ', $a['problems'] ) ), $unclean );
            return $this->fail(
                'lga_asset_claims',
                sprintf(
                    /* translators: %s: the linkable assets and the claims found in each */
                    __( 'Nothing was saved. Linkable assets are quoted in listing packages and outreach, so their title and summary must pass the claims filter. Edit these and save again: %s.', 'linkgrowth-autopilot' ),
                    implode( '; ', $lines )
                ),
                422,
                [ 'assets' => $unclean ]
            );
        }

        if ( [] !== $clean ) {
            $settings->update( $clean );
            $changed = self::diffKeys( $before, $settings->all() );
        }
        // Automatic links outside Autopilot act on the consent of whoever switched seo.auto_apply_links on: that user is
        // what InternalLinker::autoOwner() checks before saving an item as them. It is recorded only when the setting
        // goes on with its confirmation (asked also while Autopilot is on, so it is in place once Autopilot goes off)
        // and removed when it goes off, so a consent that was taken back never comes back.
        $linksWere = (bool) ( $before['seo']['auto_apply_links'] ?? false );
        $linksNow  = (bool) $settings->get( 'seo.auto_apply_links', false );
        if ( ! $linksWere && $linksNow && in_array( 'auto_apply_links', $enabling, true ) ) {
            update_option( InternalLinker::OPTION_LINKS_CONSENT, [ 'user_id' => $this->userId(), 'at' => gmdate( 'c' ) ], false );
        } elseif ( $linksWere && ! $linksNow ) {
            delete_option( InternalLinker::OPTION_LINKS_CONSENT );
        }
        $approvedNow = null;
        if ( null !== $autopilotWanted ) {
            if ( $autopilotWanted ) {
                $autopilot->enable( $this->userId() );
                $approvedNow = AutomationController::approveWaitingNow( $this->c );
            } else {
                $autopilot->disable( $this->userId() );
            }
            $changed = array_values( array_unique( array_merge( $changed, self::diffKeys( $before, $settings->all() ) ) ) );
        }
        // A sender fix (outreach.* or the business address under site.*) can unblock approval: approve what waited
        // for it now instead of at the next daily outreach run.
        if ( null === $approvedNow && $autopilot->autoApproveMessages() && [] !== array_filter( $changed, static fn( string $k ): bool => str_starts_with( $k, 'outreach.' ) || str_starts_with( $k, 'site.' ) ) ) {
            $approvedNow = AutomationController::approveWaitingNow( $this->c );
        }

        $secretsIn    = $request->get_param( 'secrets' );
        $secretResult = is_array( $secretsIn ) ? $this->applySecrets( $secretsIn ) : [ 'updated' => [], 'cleared' => [], 'rejected' => [], 'locked' => [], 'reasons' => [] ];

        $factsUpdated = false;
        $facts        = $request->get_param( 'facts' );
        if ( is_array( $facts ) ) {
            $this->c->get( FactsRegistry::class )->replace( $facts );
            $factsUpdated = true;
        }

        $seedsUpdated = false;
        $seeds        = $request->get_param( 'custom_seeds' );
        if ( is_array( $seeds ) ) {
            update_option( 'lga_custom_seeds', $this->sanitizeSeeds( $seeds ), false );
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
                    'confirmed'    => $enabling,
                ]
            );
        }

        return [
            'ok'            => true,
            'changed'       => $changed,
            'secrets'       => $secretResult,
            'facts_updated' => $factsUpdated,
            'seeds_updated' => $seedsUpdated,
            'approved_now'  => $approvedNow,
            'settings'      => $settings->all(),
            'secret_status' => $this->safe( fn(): array => $this->c->get( Secrets::class )->status(), [], 'secret status' ),
            'providers'     => $this->safe( fn(): array => $this->c->get( ProviderRegistry::class )->status(), [], 'provider status' ),
        ];
    }

    /** Why a settings change needs confirm=true (see update()). */
    public static function confirmationText( string $key ): string {
        if ( 'autopilot' === $key ) {
            return Autopilot::policyText();
        }
        if ( 'auto_apply_links' === $key ) {
            return self::autoLinksConfirmationText();
        }
        if ( 'storefront_gate_off' === $key ) {
            return __( 'Turning off the storefront compliance gate lets automated submissions and outreach sending continue while the storefront check finds consumer-use wording that contradicts the research-use-only listings. How the store presents itself is your legal decision. Confirm to proceed.', 'linkgrowth-autopilot' );
        }
        return AutomationController::confirmationMessage( $key );
    }

    /** What switching automatic internal links on does (the settings page shows the same text before saving). */
    public static function autoLinksConfirmationText(): string {
        return __( 'Turn on automatic internal links? Every day the engine will edit your published pages and posts without asking you first, adding the best internal-link suggestions (highest relevance first, up to the daily number under Settings → Automation → On-site SEO). WordPress keeps a revision of every page it changes, and “Undo automatic links” on the On-site SEO page removes them. To add a link to a page holding markup only an administrator may save (such as inline SVG diagrams or structured data), it saves that one change as your account, and it takes its own automatic links back out of pages whose template doesn’t display them. Confirm to proceed.', 'linkgrowth-autopilot' );
    }

    /** POST /settings/test {provider} @return array<string,mixed>|\WP_Error */
    public function test( WP_REST_Request $request ) {
        $provider = sanitize_key( $this->str( $request, 'provider', '', 40 ) );
        if ( ! in_array( $provider, self::TEST_PROVIDERS, true ) ) {
            return $this->fail( 'lga_unknown_provider', __( 'Unknown provider.', 'linkgrowth-autopilot' ), 400, [ 'providers' => self::TEST_PROVIDERS ] );
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
            return $this->fail( 'lga_invalid_file', __( 'Invalid backup file name.', 'linkgrowth-autopilot' ), 400 );
        }
        $dir     = $this->c->get( Backup::class )->directory();
        $dirReal = realpath( $dir );
        $real    = realpath( $dir . '/' . $file );
        if ( false === $dirReal || false === $real || ! str_starts_with( $real, $dirReal . DIRECTORY_SEPARATOR ) || ! is_file( $real ) || ! is_readable( $real ) ) {
            return $this->notFound( __( 'Backup', 'linkgrowth-autopilot' ) );
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
            return $this->fail( 'lga_not_configured', __( 'SE Ranking is not configured: add the API key first.', 'linkgrowth-autopilot' ), 400 );
        }
        $settings = $this->c->get( Settings::class );
        $domain   = $settings->siteDomain();
        $list     = $se->competitors( $domain );
        if ( [] === $list && '' !== $se->lastError() ) {
            return $this->fail( 'lga_provider_error', Redactor::redactString( $se->lastError() ), 502 );
        }
        $list     = array_values( array_filter( array_unique( array_map( static fn( string $d ): string => Url::registrableDomain( $d ), $list ) ), static fn( string $d ): bool => '' !== $d && $d !== $domain ) );
        $existing = array_values( array_map( 'strval', (array) $settings->get( 'discovery.competitor_domains', [] ) ) );
        $this->audit( 'settings.competitors_suggested', 'settings', 0, [ 'count' => count( $list ) ] );
        return [
            'ok'          => true,
            'competitors' => $list,
            'existing'    => $existing,
            'new'         => array_values( array_diff( $list, $existing ) ),
            'note'        => __( 'SE Ranking competitor lookups consume API credits; results are not saved until you add them to the competitor list.', 'linkgrowth-autopilot' ),
        ];
    }

    // ------------------------------------------------------------------
    // Settings sanitisation (the rules: Support\SettingsSchema, from the modules' settingsRules())
    // ------------------------------------------------------------------

    /**
     * Sanitised linkable assets whose title or summary fails the claims filter (LinkableAssets::isClean), with
     * what was found in each; [] when the save carries no assets or all of them are clean.
     *
     * @param array<string,mixed> $clean Output of SettingsSchema::sanitize().
     * @return array<int,array{index:int,title:string,url:string,problems:string[]}>
     */
    private function uncleanAssets( array $clean ): array {
        $assets = $clean['content']['linkable_assets'] ?? null;
        if ( ! is_array( $assets ) || [] === $assets ) {
            return [];
        }
        $checker = $this->c->get( LinkableAssets::class );
        $claims  = $this->c->get( ClaimsFilter::class );
        $fields  = [ 'title' => __( 'title', 'linkgrowth-autopilot' ), 'summary' => __( 'summary', 'linkgrowth-autopilot' ) ];
        $out     = [];
        foreach ( $assets as $i => $asset ) {
            if ( ! is_array( $asset ) || $checker->isClean( $asset ) ) {
                continue;
            }
            $problems = [];
            foreach ( $fields as $field => $label ) {
                foreach ( $claims->check( (string) ( $asset[ $field ] ?? '' ) ) as $v ) {
                    /* translators: 1: "title" or "summary", 2: the matched phrase, 3: the claims rule that matched */
                    $problems[] = sprintf( __( '%1$s contains “%2$s”: %3$s', 'linkgrowth-autopilot' ), $label, $v['match'], $v['message'] );
                }
            }
            if ( [] === $problems ) {
                $problems[] = __( 'the title and summary together make a blocked claim', 'linkgrowth-autopilot' );
            }
            $out[] = [ 'index' => (int) $i, 'title' => (string) ( $asset['title'] ?? '' ), 'url' => (string) ( $asset['url'] ?? '' ), 'problems' => array_values( array_unique( $problems ) ) ];
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
            $url = SettingsSchema::validUrl( (string) ( is_scalar( $seed['url'] ?? null ) ? $seed['url'] : '' ) );
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

    /**
     * Writes secrets (write-only: values are never returned). `reasons` gives the cause for each rejected key, from
     * the secret rules of Support\SettingsSchema: invalid_key (not a secret the plugin stores), too_long (over 20,000
     * characters) or invalid_service_account_json (not a Google service-account JSON key with client_email and
     * private_key).
     *
     * @param array<mixed> $input
     * @return array{updated:string[],cleared:string[],rejected:string[],locked:string[],reasons:array<string,string>}
     */
    private function applySecrets( array $input ): array {
        $secrets = $this->c->get( Secrets::class );
        $result  = [ 'updated' => [], 'cleared' => [], 'rejected' => [], 'locked' => [], 'reasons' => [] ];
        $reject  = static function ( string $key, string $reason ) use ( &$result ): void {
            $result['rejected'][]      = $key;
            $result['reasons'][ $key ] = $reason;
        };
        $n       = 0;
        foreach ( $input as $key => $value ) {
            if ( ++$n > 50 ) {
                break;
            }
            $key = is_string( $key ) ? (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( $key ) ) : '';
            if ( '' === $key ) {
                continue;
            }
            if ( null === SettingsSchema::secretRule( $key ) ) {
                $reject( $key, 'invalid_key' );
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
            $problem = SettingsSchema::secretProblem( $key, $value );
            if ( '' !== $problem ) {
                $reject( $key, $problem );
                continue;
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
            delete_option( 'lga_provider_error_' . $provider );
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
        $brand    = $settings->brandName();

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
                $ai = $this->c->get( AiWriter::class );
                if ( ! $ai->isConfigured() ) {
                    return [ 'ok' => false, 'message' => 'Anthropic API key is not configured.', 'data' => [] ];
                }
                if ( '' === $ai->model() ) {
                    return [ 'ok' => false, 'message' => 'No AI model is set: enter the model id from your Anthropic account under Integrations → AI writer before testing.', 'data' => [ 'model' => '' ] ];
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
            sprintf( '[%1$s] %2$s test email', $brand, Plugin::NAME ),
            'This is a test message from ' . Plugin::NAME . ".\n\nIf you received it, outgoing email from this WordPress site works. No action is needed.\n\nSent " . gmdate( 'c' ) . ' UTC',
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
