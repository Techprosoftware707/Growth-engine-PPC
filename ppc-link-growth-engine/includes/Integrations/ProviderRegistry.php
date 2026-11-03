<?php
/**
 * Registry of metrics, backlink and search providers (extensible via filters).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Integrations\Contracts\BacklinkSource;
use PPC\LinkGrowth\Integrations\Contracts\MetricsProvider;
use PPC\LinkGrowth\Integrations\Contracts\SearchProvider;
use PPC\LinkGrowth\Integrations\Google\Analytics4;
use PPC\LinkGrowth\Integrations\Google\SearchConsole;
use PPC\LinkGrowth\Integrations\Google\SiteKitBridge;
use PPC\LinkGrowth\Integrations\Providers\Ahrefs;
use PPC\LinkGrowth\Integrations\Providers\Moz;
use PPC\LinkGrowth\Integrations\Providers\Semrush;
use PPC\LinkGrowth\Integrations\Providers\SeRanking;
use PPC\LinkGrowth\Integrations\Search\BraveSearch;
use PPC\LinkGrowth\Integrations\Search\GoogleCustomSearch;
use PPC\LinkGrowth\Integrations\Search\Serper;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Support\Settings;

final class ProviderRegistry {

    /** @var array<string,MetricsProvider>|null */
    private ?array $metrics = null;

    /** @var array<string,SearchProvider>|null */
    private ?array $search = null;

    public function __construct( private Container $c ) {}

    private function deps(): array {
        return [ $this->c->get( Secrets::class ), $this->c->get( Settings::class ), $this->c->get( Client::class ), $this->c->get( Logger::class ) ];
    }

    /** @return array<string,MetricsProvider> */
    private function allMetrics(): array {
        if ( null === $this->metrics ) {
            [ $sec, $set, $cli, $log ] = $this->deps();
            $list = [
                new SeRanking( $sec, $set, $cli, $log ),
                new Ahrefs( $sec, $set, $cli, $log ),
                new Semrush( $sec, $set, $cli, $log ),
                new Moz( $sec, $set, $cli, $log ),
            ];
            /**
             * Register additional metrics providers (must implement MetricsProvider).
             *
             * @param MetricsProvider[] $list
             */
            $list = apply_filters( 'ppclg_metrics_providers', $list );
            $this->metrics = [];
            foreach ( $list as $p ) {
                if ( $p instanceof MetricsProvider ) {
                    $this->metrics[ $p->id() ] = $p;
                }
            }
        }
        return $this->metrics;
    }

    /** @return MetricsProvider[] */
    public function metricsProviders( bool $onlyReady = true ): array {
        $out = [];
        foreach ( $this->allMetrics() as $p ) {
            if ( ! $onlyReady || ( $p->isEnabled() && $p->isConfigured() ) ) {
                $out[] = $p;
            }
        }
        return $out;
    }

    public function metricsProvider( string $id ): ?MetricsProvider {
        return $this->allMetrics()[ $id ] ?? null;
    }

    public function seranking(): SeRanking {
        /** @var SeRanking $p */
        $p = $this->allMetrics()['seranking'];
        return $p;
    }

    /** @return BacklinkSource[] enabled + configured */
    public function backlinkSources(): array {
        $out = [];
        foreach ( $this->allMetrics() as $p ) {
            if ( $p instanceof BacklinkSource && $p->isEnabled() && $p->isConfigured() ) {
                $out[] = $p;
            }
        }
        /**
         * Register additional backlink sources.
         *
         * @param BacklinkSource[] $out
         */
        return array_values( array_filter( (array) apply_filters( 'ppclg_backlink_sources', $out ), static fn( $s ) => $s instanceof BacklinkSource ) );
    }

    /** @return array<string,SearchProvider> */
    private function allSearch(): array {
        if ( null === $this->search ) {
            [ $sec, $set, $cli, $log ] = $this->deps();
            $list = [ new GoogleCustomSearch( $sec, $set, $cli, $log ), new BraveSearch( $sec, $set, $cli, $log ), new Serper( $sec, $set, $cli, $log ) ];
            /**
             * Register additional search providers (must implement SearchProvider).
             *
             * @param SearchProvider[] $list
             */
            $list = apply_filters( 'ppclg_search_providers', $list );
            $this->search = [];
            foreach ( $list as $p ) {
                if ( $p instanceof SearchProvider ) {
                    $this->search[ $p->id() ] = $p;
                }
            }
        }
        return $this->search;
    }

    /** Configured search providers with quota left, preferred one first. @return SearchProvider[] */
    public function searchProviders(): array {
        $primary = (string) $this->c->get( Settings::class )->get( 'providers.search.primary', 'auto' );
        $ready   = array_filter( $this->allSearch(), static fn( SearchProvider $p ) => $p->isConfigured() && $p->remainingToday() > 0 );
        uasort(
            $ready,
            static function ( SearchProvider $a, SearchProvider $b ) use ( $primary ) {
                if ( $a->id() === $primary ) {
                    return -1;
                }
                if ( $b->id() === $primary ) {
                    return 1;
                }
                return $b->remainingToday() <=> $a->remainingToday();
            }
        );
        return array_values( $ready );
    }

    public function primarySearch(): ?SearchProvider {
        $list = $this->searchProviders();
        return $list[0] ?? null;
    }

    public function searchProvider( string $id ): ?SearchProvider {
        return $this->allSearch()[ $id ] ?? null;
    }

    /** Status summary for the dashboard. @return array<string,array<string,mixed>> */
    public function status(): array {
        $out = [];
        foreach ( $this->allMetrics() as $id => $p ) {
            $err = get_option( 'ppclg_provider_error_' . $id, [] );
            $out[ $id ] = [
                'label'      => $p->label(),
                'kind'       => 'metrics' . ( $p instanceof BacklinkSource ? '+backlinks' : '' ),
                'enabled'    => $p->isEnabled(),
                'configured' => $p->isConfigured(),
                'error'      => is_array( $err ) ? ( $err['message'] ?? '' ) : '',
                'error_at'   => is_array( $err ) ? ( $err['at'] ?? '' ) : '',
            ];
        }
        foreach ( $this->allSearch() as $id => $p ) {
            $err = get_option( 'ppclg_provider_error_' . $id, [] );
            $out[ $id ] = [
                'label'      => $p->label(),
                'kind'       => 'search',
                'enabled'    => $p->isConfigured(),
                'configured' => $p->isConfigured(),
                'remaining_today' => $p->remainingToday(),
                'error'      => is_array( $err ) ? ( $err['message'] ?? '' ) : '',
                'error_at'   => is_array( $err ) ? ( $err['at'] ?? '' ) : '',
            ];
        }
        $gsc = $this->c->get( SearchConsole::class );
        $ga  = $this->c->get( Analytics4::class );
        $sk  = $this->c->get( SiteKitBridge::class );
        $ai  = $this->c->get( ClaudeWriter::class );
        $aiErr = get_option( 'ppclg_provider_error_ai', [] );
        $out['google_search_console'] = [ 'label' => 'Google Search Console', 'kind' => 'analytics', 'enabled' => (bool) $this->c->get( Settings::class )->get( 'providers.google.enabled', true ), 'configured' => $gsc->isConfigured(), 'error' => $gsc->isConfigured() ? '' : 'Service account JSON and property required for background use', 'error_at' => '' ];
        $out['google_analytics_4']    = [ 'label' => 'Google Analytics 4', 'kind' => 'analytics', 'enabled' => (bool) $this->c->get( Settings::class )->get( 'providers.google.enabled', true ), 'configured' => $ga->isConfigured(), 'error' => $ga->isConfigured() ? '' : 'Service account JSON and GA4 property ID required for background use', 'error_at' => '' ];
        $out['site_kit']              = [ 'label' => 'Site Kit by Google (dashboard bridge)', 'kind' => 'analytics', 'enabled' => defined( 'GOOGLESITEKIT_VERSION' ), 'configured' => defined( 'GOOGLESITEKIT_VERSION' ), 'error' => defined( 'GOOGLESITEKIT_VERSION' ) ? '' : 'Site Kit plugin not active', 'error_at' => '' ];
        $out['anthropic']             = [ 'label' => 'AI writer (Anthropic Claude)', 'kind' => 'ai', 'enabled' => $ai->isEnabled(), 'configured' => $ai->isConfigured(), 'error' => is_array( $aiErr ) ? ( $aiErr['message'] ?? '' ) : '', 'error_at' => is_array( $aiErr ) ? ( $aiErr['at'] ?? '' ) : '' ];
        return $out;
    }
}
