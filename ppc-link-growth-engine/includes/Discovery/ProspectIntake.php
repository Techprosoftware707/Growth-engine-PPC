<?php
/**
 * Turns discovered URLs into prospects (one per registrable domain) and opportunities (specific pages).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Discovery;

use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Url;

final class ProspectIntake {

    public function __construct(
        private Prospects $prospects,
        private Opportunities $opportunities,
        private DomainFilter $filter,
        private Classifier $classifier,
        private AuditLog $audit,
        private Logger $logger
    ) {}

    /**
     * @param array<string,mixed> $hints title, snippet, name, site_type, opportunity_type, country, details
     * @return array{prospect_id:int,created:bool,opportunity_id:?int,skipped:?string}|null
     */
    public function fromUrl( string $url, string $via, string $query = '', array $hints = [] ): ?array {
        $norm = Url::normalize( $url );
        $skip = $this->filter->reason( $norm );
        if ( null !== $skip ) {
            return [ 'prospect_id' => 0, 'created' => false, 'opportunity_id' => null, 'skipped' => $skip ];
        }
        $domain = Url::registrableDomain( $norm );
        $class  = $this->classifier->classify( $norm, (string) ( $hints['title'] ?? '' ), (string) ( $hints['snippet'] ?? '' ) );
        $siteType = (string) ( $hints['site_type'] ?? $class['site_type'] );
        $oppType  = (string) ( $hints['opportunity_type'] ?? $class['opportunity_type'] );
        if ( ! array_key_exists( $oppType, Opportunities::TYPES ) ) {
            $oppType = $class['opportunity_type'];
        }
        $country = strtoupper( (string) ( $hints['country'] ?? '' ) );
        if ( '' === $country && Url::isCanadianTld( $domain ) ) {
            $country = 'CA';
        }
        $scheme = (string) ( wp_parse_url( $norm, PHP_URL_SCHEME ) ?: 'https' );
        $host   = Url::host( $norm );

        [ $id, $created ] = $this->prospects->upsertDiscovered(
            $domain,
            [
                'name'             => mb_substr( ( (string) ( $hints['name'] ?? '' ) ?: (string) ( $hints['title'] ?? '' ) ) ?: $domain, 0, 255 ),
                'homepage_url'     => $scheme . '://' . $host . '/',
                'site_type'        => $siteType,
                'opportunity_type' => $oppType,
                'country'          => $country,
                'discovered_via'   => mb_substr( $via, 0, 60 ),
                'discovery_query'  => mb_substr( $query, 0, 255 ),
            ]
        );

        $oppId = null;
        $path  = Url::path( $norm );
        if ( '/' !== $path && '' !== $path || in_array( $oppType, [ 'unlinked_mention', 'broken_link' ], true ) ) {
            [ $oppId ] = $this->opportunities->upsert(
                $id,
                $oppType,
                $norm,
                (string) ( $hints['title'] ?? '' ),
                array_merge( [ 'discovered_via' => $via, 'query' => $query, 'snippet' => mb_substr( (string) ( $hints['snippet'] ?? '' ), 0, 500 ) ], (array) ( $hints['details'] ?? [] ) )
            );
        }
        if ( $created ) {
            $this->audit->record( 'system:discovery', 'prospect.discovered', 'prospect', $id, [ 'domain' => $domain, 'via' => $via, 'query' => $query, 'site_type' => $siteType, 'opportunity_type' => $oppType ] );
        }
        return [ 'prospect_id' => $id, 'created' => $created, 'opportunity_id' => $oppId, 'skipped' => null ];
    }

    /** Manual addition by an administrator. */
    public function manual( string $url, string $note = '', int $userId = 0 ): ?array {
        $r = $this->fromUrl( $url, 'manual', '', [] );
        if ( $r && $r['prospect_id'] > 0 ) {
            if ( '' !== $note ) {
                $this->prospects->update( $r['prospect_id'], [ 'notes' => mb_substr( $note, 0, 5000 ), 'updated_at' => Prospects::now() ] );
            }
            $this->audit->record( 'user:' . $userId, 'prospect.added_manually', 'prospect', $r['prospect_id'], [ 'url' => $url ] );
        }
        return $r;
    }
}
