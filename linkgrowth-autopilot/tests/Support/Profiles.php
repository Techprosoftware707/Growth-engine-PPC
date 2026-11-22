<?php
/**
 * The two fictional demo businesses of the test suites (DESIGN.md §12.2). Every test that needs a business profile
 * takes it from here, so no test depends on one brand, country or industry, and no store data is shipped as a
 * default (Support\Settings::defaults() has an empty profile).
 *
 * - Northwind Supply Co.: northwind.test, United States (US-OR), English, USD, pack "general", categories
 *   "Industrial Supplies" and "Packaging"; no positioning statement.
 * - Fjordline Lab Supplies: fjordline.test, United Kingdom (GB-SCT), English, GBP, pack "research-supplies",
 *   positioning on.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Support;

use LinkGrowth\Autopilot\Support\Settings;

final class Profiles {

    public const NORTHWIND = 'northwind';

    public const FJORDLINE = 'fjordline';

    /** @var array<string,array<string,mixed>> profile => site.* settings */
    public const SITES = [
        self::NORTHWIND => [
            'brand_name'   => 'Northwind Supply Co',
            'legal_name'   => 'NORTHWIND SUPPLY CO',
            'domain'       => 'northwind.test',
            'home_url'     => 'https://northwind.test',
            'tagline'      => 'Industrial supplies and packaging for workshops and warehouses',
            'email'        => 'hello@northwind.test',
            'address'      => '1200 NW Front Ave',
            'city'         => 'Portland',
            'region'       => 'OR',
            'postal_code'  => '97209',
            'country'      => 'US',
            'founded_year' => '2019',
            'social'       => [ 'https://social.example/northwindsupply' ],
            'categories'   => [ 'Industrial Supplies', 'Packaging' ],
        ],
        self::FJORDLINE => [
            'brand_name'   => 'Fjordline Lab Supplies',
            'legal_name'   => 'FJORDLINE LAB SUPPLIES LTD',
            'domain'       => 'fjordline.test',
            'home_url'     => 'https://fjordline.test',
            'tagline'      => 'Laboratory consumables for teaching and research labs',
            'email'        => 'hello@fjordline.test',
            'address'      => '12 Harbour Road',
            'city'         => 'Leith',
            'region'       => 'SCT',
            'postal_code'  => 'EH6 6QN',
            'country'      => 'GB',
            'founded_year' => '2017',
            'social'       => [ 'https://social.example/fjordlinelab' ],
            'categories'   => [ 'Laboratory Supplies', 'Teaching Labs' ],
        ],
    ];

    /** Market data of each demo business that the settings do not hold yet (packs arrive in WP4a). */
    public const MARKETS = [
        self::NORTHWIND => [ 'language' => 'en', 'currency' => 'USD', 'pack' => 'general', 'positioning' => false ],
        self::FJORDLINE => [ 'language' => 'en', 'currency' => 'GBP', 'pack' => 'research-supplies', 'positioning' => true ],
    ];

    /**
     * The topics each business works in. They join the taxonomy's core group through the owner's own setting
     * (discovery.extra_terms), as an owner would enter them; the industry packs replace this in WP6.
     *
     * @var array<string,string[]>
     */
    public const TOPICS = [
        self::NORTHWIND => [ 'fastener', 'fasteners', 'packaging', 'packaging materials', 'industrial supplies', 'shipping supplies', 'corrugated boxes', 'storage', 'handling' ],
        self::FJORDLINE => [ 'laboratory consumables', 'lab glassware', 'pipette tips', 'teaching labs', 'storage', 'handling' ],
    ];

    /** Adds the profile's topics to the taxonomy (discovery.extra_terms). */
    public static function applyTopics( Settings $settings, string $profile = self::NORTHWIND ): void {
        $settings->set( 'discovery.extra_terms', self::TOPICS[ $profile ] );
    }

    /** The postal address line a profile gives the outreach footer ("street, city, region postal code, country"). */
    public static function postalAddress( string $profile = self::NORTHWIND ): string {
        $s = self::SITES[ $profile ];
        return sprintf( '%s, %s, %s %s, %s', $s['address'], $s['city'], $s['region'], $s['postal_code'], 'US' === $s['country'] ? 'United States' : 'United Kingdom' );
    }

    /** Stores the profile's site.* settings (and nothing else). */
    public static function apply( Settings $settings, string $profile = self::NORTHWIND ): void {
        $settings->update( [ 'site' => self::SITES[ $profile ] ] );
    }

    /** @return array<string,array{0:string}> PHPUnit data provider over both demo businesses */
    public static function both(): array {
        return [ 'Northwind Supply Co' => [ self::NORTHWIND ], 'Fjordline Lab Supplies' => [ self::FJORDLINE ] ];
    }
}
