<?php
/**
 * Pages on the PPC site worth linking to, with topic keywords for matching.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Content;

use PPC\LinkGrowth\Analysis\Taxonomy;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Str;

final class LinkableAssets {

    public function __construct( private Settings $settings, private Taxonomy $taxonomy ) {}

    /** @return array<int,array{url:string,title:string,summary:string,topics:string[],kind:string}> */
    public static function defaults( string $home ): array {
        $home = untrailingslashit( $home );
        return [
            [ 'url' => $home . '/', 'title' => 'Pure Peptides Canada', 'summary' => 'Canadian-owned supplier of research peptides and laboratory reagents, with independent batch test reports.', 'topics' => [ 'peptide', 'research peptides', 'laboratory reagents', 'supplier', 'canada' ], 'kind' => 'home' ],
            [ 'url' => $home . '/lab-testing/', 'title' => 'Lab Testing and Certificates of Analysis', 'summary' => 'Janoshik Analytical reports listed by product, vial size and batch, each with a verification key.', 'topics' => [ 'certificate of analysis', 'coa', 'lab testing', 'hplc', 'mass spectrometry', 'purity', 'quality', 'testing' ], 'kind' => 'resource' ],
            [ 'url' => $home . '/peptide-coa-guide/', 'title' => 'Peptide COA Guide: How to Read a Certificate of Analysis', 'summary' => 'Plain-English walkthrough of every field on a peptide certificate of analysis.', 'topics' => [ 'certificate of analysis', 'coa', 'quality control', 'documentation', 'purity', 'identity' ], 'kind' => 'guide' ],
            [ 'url' => $home . '/hplc-testing-guide/', 'title' => 'HPLC Testing for Research Peptides: A Plain-English Guide', 'summary' => 'How reverse-phase HPLC purity analysis works and how to read a chromatogram.', 'topics' => [ 'hplc', 'chromatography', 'analytical chemistry', 'purity', 'testing', 'method' ], 'kind' => 'guide' ],
            [ 'url' => $home . '/peptide-storage-guide/', 'title' => 'Peptide Storage Guide: Handling Research Peptides', 'summary' => 'Storage, handling and reconstitution principles for lyophilized research peptides in the lab.', 'topics' => [ 'storage', 'handling', 'lyophilized', 'stability', 'lab practice', 'freeze-thaw' ], 'kind' => 'guide' ],
            [ 'url' => $home . '/research-peptides-canada/', 'title' => 'Research Peptides in Canada: What They Are and How to Verify Them', 'summary' => 'Overview of research peptides, Canadian supply, and how to verify documentation.', 'topics' => [ 'research peptides', 'canada', 'verification', 'overview', 'education' ], 'kind' => 'guide' ],
            [ 'url' => $home . '/best-canadian-peptide-suppliers/', 'title' => 'How to Choose the Best Canadian Peptide Supplier', 'summary' => 'A checklist of verifiable supplier criteria: testing, documentation, shipping and payment.', 'topics' => [ 'supplier', 'canada', 'procurement', 'checklist', 'vendor evaluation' ], 'kind' => 'guide' ],
            [ 'url' => $home . '/education/', 'title' => 'Peptide Research Library', 'summary' => 'Educational articles on peptide research, analytical testing and laboratory handling.', 'topics' => [ 'education', 'research', 'library', 'articles', 'learning', 'students' ], 'kind' => 'resource' ],
            [ 'url' => $home . '/shipping-across-canada/', 'title' => 'Shipping Across Canada', 'summary' => 'Tracked domestic shipping to every province and territory from inside Canada.', 'topics' => [ 'shipping', 'canada', 'logistics', 'procurement' ], 'kind' => 'info' ],
            [ 'url' => $home . '/about/', 'title' => 'About Pure Peptides Canada', 'summary' => 'Who we are, how we approach quality and documentation, and what research-use-only means in practice.', 'topics' => [ 'about', 'company', 'canada', 'toronto', 'supplier' ], 'kind' => 'info' ],
        ];
    }

    /** @return array<int,array{url:string,title:string,summary:string,topics:string[],kind:string}> */
    public function all(): array {
        $custom = (array) $this->settings->get( 'content.linkable_assets', [] );
        $list   = [];
        foreach ( $custom as $a ) {
            if ( is_array( $a ) && ! empty( $a['url'] ) && ! empty( $a['title'] ) ) {
                $list[] = [
                    'url'     => esc_url_raw( (string) $a['url'] ),
                    'title'   => (string) $a['title'],
                    'summary' => (string) ( $a['summary'] ?? '' ),
                    'topics'  => array_values( array_map( 'strval', (array) ( $a['topics'] ?? [] ) ) ),
                    'kind'    => (string) ( $a['kind'] ?? 'guide' ),
                ];
            }
        }
        return ! empty( $list ) ? $list : self::defaults( (string) $this->settings->get( 'site.home_url', home_url() ) );
    }

    /**
     * Best asset for a given context text (page title/headings/snippet).
     *
     * @return array{url:string,title:string,summary:string,topics:string[],kind:string}
     */
    public function bestFor( string $context, string $preferKind = '' ): array {
        $context = mb_strtolower( $context );
        $best    = null;
        $bestS   = -1.0;
        foreach ( $this->all() as $asset ) {
            $s = 0.0;
            foreach ( $asset['topics'] as $t ) {
                $s += Str::countPhrase( $context, $t ) * ( str_contains( $t, ' ' ) ? 2.0 : 1.0 );
            }
            if ( '' !== $preferKind && $asset['kind'] === $preferKind ) {
                $s += 0.5;
            }
            if ( 'home' === $asset['kind'] ) {
                $s -= 0.25; // prefer deep, useful resources over the homepage
            }
            if ( $s > $bestS ) {
                $bestS = $s;
                $best  = $asset;
            }
        }
        return $best ?? $this->all()[0];
    }

    public function home(): array {
        foreach ( $this->all() as $a ) {
            if ( 'home' === $a['kind'] ) {
                return $a;
            }
        }
        return $this->all()[0];
    }
}
