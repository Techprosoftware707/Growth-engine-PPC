<?php
/**
 * Topical keyword taxonomy loaded from data/keywords.json (admin-extendable via settings).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Analysis;

use PPC\LinkGrowth\Support\Settings;

final class Taxonomy {

    /** @var array<string,mixed>|null */
    private ?array $data = null;

    public function __construct( private Settings $settings ) {}

    /** @return array<string,mixed> */
    public function data(): array {
        if ( null === $this->data ) {
            $file = PPCLG_DIR . 'data/keywords.json';
            $raw  = is_readable( $file ) ? (string) file_get_contents( $file ) : '{}'; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $json = json_decode( $raw, true );
            $this->data = is_array( $json ) ? $json : [ 'groups' => [], 'negative' => [], 'site_type_hints' => [], 'canadian_signals' => [] ];

            // Admin-added topics (settings.discovery.extra_terms) join the "core" group.
            $extra = (array) $this->settings->get( 'discovery.extra_terms', [] );
            if ( ! empty( $extra ) ) {
                $this->data['groups']['core']['terms'] = array_values( array_unique( array_merge( $this->data['groups']['core']['terms'] ?? [], array_map( 'strval', $extra ) ) ) );
            }
            /**
             * Filter the keyword taxonomy used for relevance scoring and discovery queries.
             *
             * @param array<string,mixed> $data
             */
            $this->data = apply_filters( 'ppclg_taxonomy', $this->data );
        }
        return $this->data;
    }

    /** @return array<string,array{weight:float,terms:string[]}> */
    public function groups(): array {
        $groups = (array) ( $this->data()['groups'] ?? [] );
        $out    = [];
        foreach ( $groups as $name => $g ) {
            $out[ (string) $name ] = [
                'weight' => (float) ( $g['weight'] ?? 0.5 ),
                'terms'  => array_values( array_filter( array_map( 'strval', (array) ( $g['terms'] ?? [] ) ) ) ),
            ];
        }
        return $out;
    }

    /** @return string[] */
    public function negative( string $kind ): array {
        return array_values( array_map( 'strval', (array) ( $this->data()['negative'][ $kind ] ?? [] ) ) );
    }

    /** @return array<string,string[]> */
    public function siteTypeHints(): array {
        $out = [];
        foreach ( (array) ( $this->data()['site_type_hints'] ?? [] ) as $type => $terms ) {
            $out[ (string) $type ] = array_values( array_map( 'strval', (array) $terms ) );
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function canadianSignals(): array {
        return (array) ( $this->data()['canadian_signals'] ?? [] );
    }

    /** Core terms suitable for search queries. @return string[] */
    public function coreTerms(): array {
        return $this->groups()['core']['terms'] ?? [];
    }
}
