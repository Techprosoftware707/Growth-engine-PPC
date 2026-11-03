<?php
/**
 * Pre-filter that drops platforms, our own site, suppressed and obviously unusable URLs.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Discovery;

use PPC\LinkGrowth\Repositories\Suppression;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Url;

final class DomainFilter {

    /** @var string[]|null */
    private ?array $excluded = null;

    public function __construct( private Settings $settings, private Suppression $suppression ) {}

    /** @return string[] */
    private function excluded(): array {
        if ( null === $this->excluded ) {
            $file = PPCLG_DIR . 'data/excluded-domains.json';
            $list = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : []; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $list = is_array( $list ) ? $list : [];
            $list = array_merge( $list, (array) $this->settings->get( 'discovery.excluded_domains', [] ) );
            /**
             * Filter domains that discovery must ignore.
             *
             * @param string[] $list
             */
            $list           = apply_filters( 'ppclg_excluded_domains', $list );
            $this->excluded = array_values( array_unique( array_map( static fn( $d ) => Url::registrableDomain( strtolower( trim( (string) $d ) ) ), $list ) ) );
        }
        return $this->excluded;
    }

    /** Returns a reason string when the URL should be skipped, null when it is acceptable. */
    public function reason( string $url ): ?string {
        $norm = Url::normalize( $url );
        if ( '' === $norm ) {
            return 'invalid_url';
        }
        $host   = Url::host( $norm );
        $domain = Url::registrableDomain( $host );
        if ( '' === $domain || filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return 'no_domain';
        }
        if ( ! str_contains( $domain, '.' ) ) {
            return 'no_tld';
        }
        $own = $this->settings->siteDomain();
        if ( '' !== $own && ( $domain === $own || Url::belongsTo( $norm, $own ) ) ) {
            return 'own_site';
        }
        foreach ( $this->excluded() as $ex ) {
            if ( $domain === $ex || str_ends_with( $host, '.' . $ex ) ) {
                return 'excluded_platform';
            }
        }
        if ( $this->suppression->isSuppressed( 'domain', $domain ) ) {
            return 'suppressed';
        }
        if ( ! Url::looksLikeHtml( $norm ) ) {
            return 'non_html';
        }
        return null;
    }
}
