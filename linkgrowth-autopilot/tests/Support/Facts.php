<?php
/**
 * Neutral business facts and linkable assets of the two demo businesses (tests/Support/Profiles.php, DESIGN.md
 * §12.2), as an owner would enter them in Settings → Content. The plugin ships no facts and assumes no page but the
 * home page (Content\FactsRegistry::defaults() is empty, Content\LinkableAssets::defaults() is the home page only),
 * so tests of fact gating, copy variety and asset matching install these first.
 *
 * Every statement is fictional. The quality, commerce and audience facts name details a template must not repeat
 * once the owner unverifies or edits them: a testing partner ("Kestrel Analytical"), a price ("$299"/"£299") and a
 * payment method ("cryptocurrency").
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Support;

use LinkGrowth\Autopilot\Content\FactsRegistry;
use LinkGrowth\Autopilot\Support\Settings;

final class Facts {

    /** A neutral positioning statement the claims filter recognises (sanitize() appends it when it is missing). */
    public const POSITIONING = 'Sold for laboratory research purposes only.';

    /** @return array<string,array{statement:string,verified:bool,source:string,group:string}> */
    public static function northwind(): array {
        $src = 'https://northwind.test/about/';
        return [
            'identity'           => self::fact( 'Northwind Supply Co is an independent supplier of industrial supplies and packaging for workshops and warehouses.', 'identity', $src ),
            'established'        => self::fact( 'The company was established in 2019.', 'identity', $src ),
            'location'           => self::fact( 'It is based in Portland, OR and ships across the United States.', 'identity', $src ),
            'lab_reports'        => self::fact( 'Independent load test reports from Kestrel Analytical are listed by product and batch, each with a verification key.', 'quality', $src ),
            'reports_on_request' => self::fact( 'Reports for batches not listed are available on request.', 'quality', $src ),
            'testing_methods'    => self::fact( 'Documentation covers tensile and load testing where available.', 'quality', $src ),
            'pricing'            => self::fact( 'Prices are in US dollars.', 'commerce', $src ),
            'shipping'           => self::fact( 'Orders ship tracked from Portland to every US state.', 'commerce', $src ),
            'shipping_terms'     => self::fact( 'Ground shipping is a flat $12, or free on orders over $299.', 'commerce', $src ),
            'payment'            => self::fact( 'Checkout accepts credit or debit card, bank transfer and cryptocurrency.', 'commerce', $src ),
            'customers'          => self::fact( 'Customers include workshops, warehouses, schools and facilities teams.', 'audience', $src ),
            'library'            => self::fact( 'The site publishes a guide library on packing, storage and handling.', 'content', $src ),
            'support'            => self::fact( 'The team typically replies within one business day.', 'support', $src ),
            'contact_email'      => self::fact( 'Contact: hello@northwind.test', 'contact', 'https://northwind.test/contact/' ),
        ];
    }

    /** @return array<string,array{statement:string,verified:bool,source:string,group:string}> */
    public static function fjordline(): array {
        $src = 'https://fjordline.test/about/';
        return [
            'identity'           => self::fact( 'Fjordline Lab Supplies is an independent supplier of laboratory consumables for teaching and research labs.', 'identity', $src ),
            'established'        => self::fact( 'The company was established in 2017.', 'identity', $src ),
            'location'           => self::fact( 'It is based in Leith, SCT and ships across the United Kingdom.', 'identity', $src ),
            'research_use_only'  => self::fact( self::POSITIONING, 'compliance', $src ),
            'lab_reports'        => self::fact( 'Independent quality reports from Kestrel Analytical are listed by product and batch, each with a verification key.', 'quality', $src ),
            'reports_on_request' => self::fact( 'Reports for batches not listed are available on request.', 'quality', $src ),
            'pricing'            => self::fact( 'Prices are in pounds sterling.', 'commerce', $src ),
            'shipping'           => self::fact( 'Orders ship tracked from Leith to every UK region.', 'commerce', $src ),
            'shipping_terms'     => self::fact( 'Shipping is a flat £6, or free on orders over £299.', 'commerce', $src ),
            'payment'            => self::fact( 'Checkout accepts credit or debit card, bank transfer and cryptocurrency.', 'commerce', $src ),
            'customers'          => self::fact( 'Customers include school science departments, university teaching labs and independent researchers.', 'audience', $src ),
            'support'            => self::fact( 'The team typically replies within one business day.', 'support', $src ),
        ];
    }

    /** Stores the demo business's facts (FactsRegistry::replace(), as the Settings page saves them). */
    public static function install( FactsRegistry $registry, string $profile = Profiles::NORTHWIND ): void {
        $registry->replace( Profiles::FJORDLINE === $profile ? self::fjordline() : self::northwind() );
    }

    /**
     * Pages of the Northwind demo site an owner lists as linkable assets (content.linkable_assets).
     *
     * @return array<int,array{url:string,title:string,summary:string,topics:string[],kind:string}>
     */
    public static function northwindAssets(): array {
        $home = 'https://northwind.test';
        return [
            [ 'url' => $home . '/', 'title' => 'Northwind Supply Co', 'summary' => 'Industrial supplies and packaging for workshops and warehouses.', 'topics' => [ 'industrial supplies', 'packaging', 'supplier' ], 'kind' => 'home' ],
            [ 'url' => $home . '/test-reports/', 'title' => 'Load Test Reports by Batch', 'summary' => 'Kestrel Analytical reports listed by product and batch.', 'topics' => [ 'test reports', 'load testing', 'quality', 'batch' ], 'kind' => 'resource' ],
            [ 'url' => $home . '/coa-guide/', 'title' => 'How to Read a Certificate of Conformance (COA)', 'summary' => 'A walkthrough of every field on a supplier conformance document.', 'topics' => [ 'certificate', 'coa', 'documentation', 'quality control' ], 'kind' => 'guide' ],
            [ 'url' => $home . '/storage-guide/', 'title' => 'Storage and Handling Guide for Fasteners', 'summary' => 'Keeping fasteners and packaging materials dry, sorted and rust-free.', 'topics' => [ 'storage', 'handling', 'fastener', 'humidity', 'shelf life' ], 'kind' => 'guide' ],
            [ 'url' => $home . '/packaging-guide/', 'title' => 'Choosing Packaging Materials', 'summary' => 'Corrugated boxes, void fill and tape compared for shipping.', 'topics' => [ 'packaging', 'packaging materials', 'corrugated boxes', 'shipping supplies' ], 'kind' => 'guide' ],
            [ 'url' => $home . '/supplier-checklist/', 'title' => 'Supplier Evaluation Checklist', 'summary' => 'Verifiable criteria for choosing an industrial supplier: documentation, shipping and payment.', 'topics' => [ 'supplier', 'procurement', 'checklist', 'vendor evaluation' ], 'kind' => 'guide' ],
            [ 'url' => $home . '/guides/', 'title' => 'Guide Library', 'summary' => 'Articles on packing, storage and workshop handling.', 'topics' => [ 'education', 'library', 'articles', 'guides', 'students' ], 'kind' => 'resource' ],
        ];
    }

    /** Lists the Northwind assets in the owner's setting. */
    public static function installAssets( Settings $settings ): void {
        $settings->set( 'content.linkable_assets', self::northwindAssets() );
    }

    /** @return array{statement:string,verified:bool,source:string,group:string} */
    private static function fact( string $statement, string $group, string $source ): array {
        return [ 'statement' => $statement, 'verified' => true, 'source' => $source, 'group' => $group ];
    }
}
