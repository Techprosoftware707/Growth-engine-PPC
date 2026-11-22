<?php
/**
 * A synthetic, neutral opportunity catalog in the data format Discovery\CatalogImporter reads (version, counts, kit,
 * gate, items), for the import mechanics tests. The fork ships no opportunity database (DESIGN.md §1.3 step 1,
 * §2.5): the importer's batching, cursor, fingerprints, skip rules and owner tasks are tested against this file.
 *
 * Every site is fictional (.test hosts), the kit is the demo business Northwind Supply Co (tests/Support/Profiles),
 * and the named items below stand for the cases the tests need: a paid-only item, an item on a site that already has
 * a sent listing (on another page of that site), two separate sites on one registrable domain, an outreach item
 * and an e-mail submission address.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Support;

use LinkGrowth\Autopilot\Discovery\CatalogImporter;

final class NeutralCatalog {

    /** Items in the default catalog: three import batches of at most 100. */
    public const TOTAL = 250;

    /** A newswire whose only tier is paid. */
    public const PAID_ID = 'aa00000000000001';

    public const PAID_HOST = 'newswire-paid.test';

    /** A business directory where a listing was already sent (testSiteWithAListingAlreadySubmitted…). */
    public const LISTED_ID = 'aa00000000000002';

    public const LISTED_HOST = 'industry-hub.test';

    /** Two separate Q&A sites on one registrable domain. */
    public const BIOLOGY_ID = 'aa00000000000003';

    public const CHEMISTRY_ID = 'aa00000000000004';

    public const QA_DOMAIN = 'qa-network.test';

    /** A trade journal that takes contributions (an outreach-class item). */
    public const OUTREACH_ID = 'aa00000000000005';

    public const OUTREACH_HOST = 'trade-journal.test';

    /** An outreach item whose submission address is an e-mail address. */
    public const MAILTO_ID = 'aa00000000000006';

    /** Items without a free tier (PAID_ID among them). */
    public const PAID_COUNT = 5;

    /** Items on platforms of data/universal/excluded-domains.json. */
    public const EXCLUDED_HOSTS = [ 'www.linkedin.com', 'www.youtube.com', 'medium.com' ];

    public const VERSION = 'neutral-catalog-1';

    /** @var string[] files written by write() */
    private static array $files = [];

    /** @return array<string,mixed> the whole catalog */
    public static function data( int $total = self::TOTAL ): array {
        $items = self::namedItems();
        $paid  = 1; // PAID_ID
        for ( $i = count( $items ) + 1; $i <= $total; $i++ ) {
            $excluded = self::EXCLUDED_HOSTS[ $i - 7 ] ?? null; // items 7, 8 and 9
            $host     = $excluded ?? sprintf( 'catalog-%03d.test', $i );
            $paidOnly = null === $excluded && $paid < self::PAID_COUNT && 0 === $i % 47;
            $paid    += $paidOnly ? 1 : 0;
            $outreach = 0 === $i % 12;
            $items[]  = self::item(
                sprintf( 'bb%014x', $i ),
                $i <= 60 ? 'master' : 'expansion',
                sprintf( '%s %03d', $outreach ? 'Trade Blog' : 'Supplier Directory', $i ),
                $host,
                [
                    'tier'             => 1 + $i % 3,
                    'authority'        => 0 === $i % 10 ? null : 20 + $i % 60,
                    'free'             => $paidOnly ? 'no - paid packages only' : ( 0 === $i % 4 ? 'freemium' : 'yes' ),
                    'payment'          => $paidOnly ? 'paid listing only' : 'none',
                    'outreach'         => $outreach,
                    'conditional'      => 0 === $i % 9,
                    'opportunity_type' => $outreach ? 'educational_contribution' : 'directory_listing',
                    'site_type'        => $outreach ? 'blog' : 'directory',
                    'category'         => $outreach ? 'publishing' : 'directory',
                    'steps'            => 0 === $i % 5 ? sprintf( '- Tier %d | directory | wave 1 - Submit at: https://%s/add - Confirm the e-mail.', 1 + $i % 3, $host ) : '',
                ]
            );
        }
        $count = static fn( string $key ): int => count( array_filter( $items, static fn( array $x ): bool => ! empty( $x[ $key ] ) ) );
        return [
            'version'   => self::VERSION,
            'generated' => '2026-11-01T00:00:00+00:00',
            'counts'    => [
                'total'       => count( $items ),
                'master'      => count( array_filter( $items, static fn( array $x ): bool => 'master' === $x['db'] ) ),
                'expansion'   => count( array_filter( $items, static fn( array $x ): bool => 'expansion' === $x['db'] ) ),
                'with_steps'  => count( array_filter( $items, static fn( array $x ): bool => '' !== $x['steps'] ) ),
                'outreach'    => $count( 'outreach' ),
                'conditional' => $count( 'conditional' ),
            ],
            'kit'       => [
                'business_name'    => 'Northwind Supply Co',
                'website'          => 'https://northwind.test/',
                'address'          => '1200 NW Front Ave, Portland, OR 97209, United States',
                'email'            => 'hello@northwind.test',
                'founded'          => '2019',
                'category_rule'    => 'Choose the closest industrial supplies or packaging category.',
                'description'      => 'Northwind Supply Co sells industrial supplies and packaging materials to workshops and warehouses.',
                'positioning_rule' => 'Describe the business as it is; no claims beyond the profile.',
                'budget_rule'      => 'Free tiers only.',
                'identity_rule'    => 'Use the business name exactly as written.',
            ],
            'gate'      => 'Check the listing text against the business profile before you submit.',
            'items'     => $items,
        ];
    }

    /** Writes the catalog (or $data) to a temporary file and returns its path. */
    public static function write( ?array $data = null ): string {
        $file = (string) tempnam( sys_get_temp_dir(), 'lga-catalog-' );
        file_put_contents( $file, (string) json_encode( $data ?? self::data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        self::$files[] = $file;
        return $file;
    }

    /** Registers an importer reading the catalog file in the plugin container (jobs and controllers use it). */
    public static function install( ?string $file = null ): string {
        $file ??= self::write();
        $c      = Container::plugin()->container();
        $c->set( CatalogImporter::class, CatalogImporter::fromContainer( $c, $file ) );
        return $file;
    }

    /** Removes the files written by write(). */
    public static function cleanup(): void {
        foreach ( self::$files as $f ) {
            if ( is_file( $f ) ) {
                unlink( $f );
            }
        }
        self::$files = [];
    }

    /** @return array<int,array<string,mixed>> */
    private static function namedItems(): array {
        return [
            self::item( self::PAID_ID, 'master', 'Paid Newswire', self::PAID_HOST, [ 'free' => 'no - paid packages only', 'payment' => 'paid release only', 'category' => 'press_release', 'site_type' => 'media', 'opportunity_type' => 'editorial', 'submission_url' => 'https://' . self::PAID_HOST . '/pricing' ] ),
            self::item( self::LISTED_ID, 'master', 'Industry Hub', self::LISTED_HOST, [ 'submission_url' => 'https://www.' . self::LISTED_HOST . '/register', 'website' => 'https://www.' . self::LISTED_HOST . '/' ] ),
            self::item( self::BIOLOGY_ID, 'expansion', 'Biology Q&A', 'biology.' . self::QA_DOMAIN, [ 'submission_url' => 'https://biology.' . self::QA_DOMAIN . '/questions/ask', 'category' => 'community', 'site_type' => 'community', 'opportunity_type' => 'educational_contribution' ] ),
            self::item( self::CHEMISTRY_ID, 'expansion', 'Chemistry Q&A', 'chemistry.' . self::QA_DOMAIN, [ 'submission_url' => 'https://chemistry.' . self::QA_DOMAIN . '/questions/ask', 'category' => 'community', 'site_type' => 'community', 'opportunity_type' => 'educational_contribution' ] ),
            self::item( self::OUTREACH_ID, 'master', 'Trade Journal', self::OUTREACH_HOST, [ 'outreach' => true, 'category' => 'publishing', 'site_type' => 'publication', 'opportunity_type' => 'educational_contribution', 'submission_url' => 'https://' . self::OUTREACH_HOST . '/contribute' ] ),
            self::item( self::MAILTO_ID, 'expansion', 'Warehouse Weekly', 'warehouse-weekly.test', [ 'outreach' => true, 'category' => 'publishing', 'site_type' => 'blog', 'opportunity_type' => 'editorial', 'submission_url' => 'mailto:Editor@Warehouse-Weekly.test' ] ),
        ];
    }

    /**
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private static function item( string $id, string $db, string $platform, string $host, array $over = [] ): array {
        return array_merge(
            [
                'id'                => $id,
                'db'                => $db,
                'platform'          => $platform,
                'website'           => 'https://' . $host . '/',
                'host'              => $host,
                'submission_url'    => 'https://' . $host . '/add-listing',
                'registration_url'  => '',
                'category'          => 'directory',
                'site_type'         => 'directory',
                'opportunity_type'  => 'directory_listing',
                'tier'              => 2,
                'authority'         => 35,
                'free'              => 'yes',
                'payment'           => 'none',
                'requirements'      => 'Free listing form; staff review.',
                'link_clickable'    => 'yes',
                'link_attribute'    => 'nofollow',
                'approval_required' => 'staff-review',
                'effort'            => '10',
                'seo_value'         => 'low',
                'relevance'         => 'Buyers of industrial supplies look for suppliers here.',
                'risk'              => 'none',
                'verification_note' => 'Form page answered HTTP 200 on 2026-11-01.',
                'conditional'       => false,
                'outreach'          => false,
                'source'            => 'test',
                'steps'             => '',
            ],
            $over
        );
    }
}
