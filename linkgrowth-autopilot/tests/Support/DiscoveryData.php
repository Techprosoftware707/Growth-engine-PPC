<?php
/**
 * Neutral discovery data for tests: query templates and a seed catalog in the format Discovery\QueryGenerator and
 * Discovery\PackSeeds read, for the demo business Northwind Supply Co (tests/Support/Profiles.php).
 *
 * The fork ships no query templates and no seed list (DESIGN.md §1.3 step 1 leaves out the engine's store data; the
 * industry and country packs bring them in WP4a and WP6), so a test that exercises query rotation, brand-mention
 * scans or seed imports installs this data through the plugin's own filters (lga_discovery_queries,
 * lga_seed_catalog) instead of relying on a bundled file.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Support;

final class DiscoveryData {

    /** Installs the query templates and the seed catalog for the current test. */
    public static function install(): void {
        add_filter( 'lga_discovery_queries', static fn(): array => self::queries() );
        add_filter( 'lga_seed_catalog', static fn( array $list ): array => array_merge( self::seeds(), $list ) );
    }

    /** @return array{categories:array<string,array{opportunity:string,templates:string[]}>,topics:string[],brand_mention_templates:string[]} */
    public static function queries(): array {
        return [
            'categories'              => [
                'business_directories'   => [ 'opportunity' => 'directory_listing', 'templates' => [ 'business directory add listing {topic}', '{geo} business directory submit company', 'industrial suppliers directory {geo}', 'add your business {topic} {geo}', 'supplier directory free listing {topic}' ] ],
                'supplier_directories'   => [ 'opportunity' => 'supplier_listing', 'templates' => [ 'industrial supplier directory {geo}', '{topic} suppliers list {geo}', 'wholesale {topic} vendors directory', 'packaging suppliers directory {geo}', 'manufacturing suppliers list {topic}' ] ],
                'industry_associations'  => [ 'opportunity' => 'membership', 'templates' => [ '{geo} manufacturers association member directory', '{topic} trade association members', '{geo} distribution association directory', 'packaging association {geo} members', 'industrial distributors association {geo}' ] ],
                'trade_publications'     => [ 'opportunity' => 'editorial', 'templates' => [ '{geo} industrial magazine suppliers', '{topic} trade publication contribute', 'warehouse operations magazine write for us', 'manufacturing news {geo} submit' ] ],
                'educational_resources'  => [ 'opportunity' => 'educational_contribution', 'templates' => [ '{geo} community college industrial technology resources', '{topic} training resources links', 'workshop safety resources {geo}', 'logistics course resources links' ] ],
                'resource_pages'         => [ 'opportunity' => 'resource_page', 'templates' => [ '{topic} resources links', '{topic} useful links', 'helpful {topic} websites', 'recommended {topic} resources {geo}', '{topic} guides links page' ] ],
                'industry_blogs'         => [ 'opportunity' => 'editorial', 'templates' => [ '{topic} blog {geo}', '{topic} blog write for us', 'warehouse blog guest contributor' ] ],
                'business_networks'      => [ 'opportunity' => 'membership', 'templates' => [ '{geo} business network members manufacturing', '{geo} chamber of commerce member directory', 'small business network {geo} suppliers' ] ],
                'podcasts'               => [ 'opportunity' => 'podcast', 'templates' => [ '{geo} manufacturing podcast guests', '{topic} podcast be a guest', 'supply chain podcast guest' ] ],
            ],
            'topics'                  => [ 'industrial supplies', 'packaging', 'shipping supplies', 'fasteners', 'safety equipment', 'warehouse supplies', 'workshop tools', 'packaging materials', 'material handling', 'shop supplies' ],
            'brand_mention_templates' => [ '"{brand}" -site:{domain}', '"{domain}" -site:{domain}', '{brand} review -site:{domain}', '{brand} supplier -site:{domain}' ],
        ];
    }

    /** @return array<int,array{url:string,name:string,type:string,opportunity:string,category:string,country:string}> */
    public static function seeds(): array {
        $out   = [];
        $kinds = [
            [ 'directory', 'directory_listing', 'Business directory' ],
            [ 'supplier_directory', 'supplier_listing', 'Supplier directory' ],
            [ 'association', 'membership', 'Industry association' ],
            [ 'resource_page', 'resource_page', 'Resource page' ],
            [ 'publication', 'editorial', 'Trade publication' ],
        ];
        for ( $i = 1; $i <= 35; $i++ ) {
            [ $type, $opp, $label ] = $kinds[ $i % count( $kinds ) ];
            $out[] = [
                'url'         => sprintf( 'https://seed-%02d.test/', $i ),
                'name'        => sprintf( '%s %02d', $label, $i ),
                'type'        => $type,
                'opportunity' => $opp,
                'category'    => $label,
                'country'     => 0 === $i % 3 ? 'GB' : 'US',
            ];
        }
        // One named seed for assertions: an association in the United States.
        $out[] = [ 'url' => 'https://industrial-association.test/membership/', 'name' => 'Industrial Distributors Association', 'type' => 'association', 'opportunity' => 'membership', 'category' => 'Industry association', 'country' => 'US' ];
        return $out;
    }
}
