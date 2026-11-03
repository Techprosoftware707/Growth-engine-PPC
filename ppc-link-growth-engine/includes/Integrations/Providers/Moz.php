<?php
/**
 * Moz Links API v2 (Domain Authority, Spam Score). Optional.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations\Providers;

use PPC\LinkGrowth\Integrations\Contracts\MetricsProvider;

final class Moz extends AbstractProvider implements MetricsProvider {

    public function id(): string {
        return 'moz';
    }

    public function label(): string {
        return 'Moz';
    }

    public function isConfigured(): bool {
        return $this->secrets->has( 'moz_access_id' ) && $this->secrets->has( 'moz_secret_key' );
    }

    public function domainMetrics( string $domain ): ?array {
        if ( ! $this->isConfigured() ) {
            return null;
        }
        $auth = base64_encode( $this->secrets->get( 'moz_access_id' ) . ':' . $this->secrets->get( 'moz_secret_key' ) );
        $r    = $this->client->api( 'POST', 'https://lsapi.seomoz.com/v2/url_metrics', [ 'Authorization' => 'Basic ' . $auth, 'Accept' => 'application/json' ], [ 'targets' => [ $domain ] ] );
        if ( ! $r->ok() ) {
            $this->fail( 'URL metrics failed: ' . $this->describe( $r ) );
            return null;
        }
        $row = $r->json()['results'][0] ?? null;
        if ( ! is_array( $row ) ) {
            $this->fail( 'Unexpected Moz response.' );
            return null;
        }
        $this->succeed();
        $da  = self::pick( $row, [ 'domain_authority' ] );
        $out = [ 'authority_label' => 'Domain Authority', 'raw' => array_intersect_key( $row, array_flip( [ 'domain_authority', 'page_authority', 'spam_score', 'root_domains_to_root_domain', 'external_pages_to_root_domain' ] ) ) ];
        if ( null !== $da ) {
            $out['authority'] = (int) round( $da );
        }
        $rd = self::pick( $row, [ 'root_domains_to_root_domain' ] );
        if ( null !== $rd ) {
            $out['referring_domains'] = (int) $rd;
        }
        $ss = self::pick( $row, [ 'spam_score' ] );
        if ( null !== $ss ) {
            $out['spam_score'] = (int) round( $ss );
        }
        return isset( $out['authority'] ) ? $out : null;
    }
}
