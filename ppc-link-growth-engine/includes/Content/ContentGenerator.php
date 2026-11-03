<?php
/**
 * Generates unique, compliant business descriptions, profiles and pitches per destination.
 *
 * Deterministic variety: the destination domain seeds sentence selection so two directories
 * never receive identical copy, while repeated runs for the same destination stay stable.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Content;

use PPC\LinkGrowth\Integrations\ClaudeWriter;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Str;

final class ContentGenerator {

    public function __construct(
        private FactsRegistry $facts,
        private ClaimsFilter $filter,
        private Settings $settings,
        private ClaudeWriter $ai,
        private Logger $logger
    ) {}

    private function brand(): string {
        return (string) $this->settings->get( 'site.brand_name', 'Pure Peptides Canada' );
    }

    private function city(): string {
        return (string) $this->settings->get( 'site.city', 'Toronto' );
    }

    /** Opening sentence variants (all built from verified facts). @return string[] */
    private function openings(): array {
        $b = $this->brand();
        $c = $this->city();
        $y = (string) $this->settings->get( 'site.founded_year', '2023' );
        return array_values( array_filter( [
            "$b is a Canadian-owned online supplier of research peptides and laboratory reagents based in $c, Ontario.",
            "Based in $c and established in $y, $b supplies research peptides and laboratory reagents to Canadian research buyers.",
            "$b supplies laboratory research peptides and reagents from $c, Ontario, serving laboratories across Canada.",
            "Founded in $y, $b is a $c-based, Canadian-owned supplier of research peptides and laboratory reagents.",
            "$b is an online laboratory supplier of research peptides and reagents, Canadian-owned and headquartered in $c.",
        ] ) );
    }

    /** @return string[] */
    private function qualitySentences(): array {
        $out = [];
        if ( '' !== $this->facts->statement( 'lab_reports' ) ) {
            $out[] = 'Independent Janoshik Analytical test reports are listed on its Lab Testing page by product, vial size and batch, each carrying a verification key that can be checked on the laboratory\'s own website.';
            $out[] = 'Batch-level analytical reports from an independent laboratory (Janoshik Analytical) are published by product and vial size, with verification keys for every current report.';
            $out[] = 'Rather than generic purity claims, the company publishes independent per-batch test reports and makes reports for unlisted batches available on request.';
        }
        if ( '' !== $this->facts->statement( 'testing_methods' ) ) {
            $out[] = 'Documentation covers HPLC purity analysis and mass spectrometry identity confirmation where available for a lot.';
        }
        return $out;
    }

    /** @return string[] */
    private function commerceSentences(): array {
        $out = [];
        if ( '' !== $this->facts->statement( 'shipping' ) ) {
            $out[] = 'Orders are priced in Canadian dollars and ship tracked from inside Canada by Canada Post Xpresspost, so there are no customs holds or brokerage fees.';
            $out[] = 'Everything ships domestically by tracked Canada Post Xpresspost, with prices in CAD and free shipping on orders over $299.';
        }
        if ( '' !== $this->facts->statement( 'payment' ) ) {
            $out[] = 'Checkout accepts credit and debit cards, Interac e-Transfer and cryptocurrency.';
        }
        return $out;
    }

    /** @return string[] */
    private function audienceSentences(): array {
        $out = [];
        if ( '' !== $this->facts->statement( 'customers' ) ) {
            $out[] = 'Its customers include university research groups, biotech and contract research teams, institutional laboratories and independent qualified researchers.';
            $out[] = 'The company serves academic labs, biotech and CRO teams, hospital and institutional laboratories, and independent researchers working in a research capacity.';
        }
        if ( '' !== $this->facts->statement( 'library' ) ) {
            $out[] = 'Its Research Library publishes practical guides on peptide storage and handling, HPLC testing, reading a certificate of analysis and evaluating Canadian suppliers.';
        }
        return $out;
    }

    private function positioning(): string {
        return $this->facts->statement( 'research_use_only' ) ?: 'All research products are sold strictly for in-vitro laboratory research use and are not for human or veterinary use.';
    }

    /**
     * Directory / listing description.
     *
     * @param string $destination domain (seed for variety)
     * @param string $style business|scientific|educational
     */
    public function description( string $destination, int $maxLength = 500, string $style = 'business' ): string {
        $seed  = Str::seed( $destination );
        $parts = [ Str::seededPick( $this->openings(), $seed, 1 ) ];
        $pools = match ( $style ) {
            'scientific'  => [ $this->qualitySentences(), $this->audienceSentences(), $this->commerceSentences() ],
            'educational' => [ $this->audienceSentences(), $this->qualitySentences(), $this->commerceSentences() ],
            default       => [ $this->commerceSentences(), $this->qualitySentences(), $this->audienceSentences() ],
        };
        $salt = 2;
        foreach ( $pools as $pool ) {
            $pick = Str::seededPick( $pool, $seed, $salt++ );
            if ( is_string( $pick ) ) {
                $parts[] = $pick;
            }
        }
        $parts[] = $this->positioning();
        $text    = implode( ' ', array_filter( $parts ) );
        $text    = $this->filter->sanitize( $text );
        return $this->fitLength( $text, $maxLength );
    }

    public function shortDescription( string $destination, int $maxLength = 160 ): string {
        $seed = Str::seed( $destination );
        $b    = $this->brand();
        $opts = [
            "$b: Canadian-owned supplier of research peptides and lab reagents, with independent batch test reports. Research use only.",
            "Research peptides and laboratory reagents from a Canadian-owned, {$this->city()}-based supplier. Independent COAs by batch. Research use only.",
            "$b supplies lab research peptides and reagents across Canada, priced in CAD with per-batch lab reports. Research use only.",
            "Canadian-owned laboratory supplier of research peptides and reagents. Tracked domestic shipping, independent testing. Research use only.",
        ];
        $text = (string) Str::seededPick( $opts, $seed, 7 );
        return $this->fitLength( $this->filter->sanitize( $text, false ), $maxLength );
    }

    public function tagline( string $destination ): string {
        $opts = [
            'Canadian-owned research peptide and laboratory reagent supplier',
            'Research peptides and lab reagents, shipped from inside Canada',
            'Independently tested research peptides for Canadian laboratories',
            'Laboratory research reagents with batch-level test reports',
        ];
        return (string) Str::seededPick( $opts, Str::seed( $destination ), 11 );
    }

    public function companyBackground( string $destination ): string {
        $seed  = Str::seed( $destination );
        $parts = [
            Str::seededPick( $this->openings(), $seed, 21 ),
            $this->facts->statement( 'established' ) ? 'It was established in ' . (string) $this->settings->get( 'site.founded_year', '2023' ) . ' to give Canadian research buyers a domestic source that avoids international shipping delays.' : '',
            Str::seededPick( $this->audienceSentences(), $seed, 22 ),
            Str::seededPick( $this->qualitySentences(), $seed, 23 ),
            Str::seededPick( $this->commerceSentences(), $seed, 24 ),
            $this->facts->statement( 'support' ),
            $this->positioning(),
        ];
        return $this->filter->sanitize( implode( ' ', array_filter( array_map( 'strval', $parts ) ) ) );
    }

    /** Supplier profile for scientific/industrial directories. */
    public function supplierProfile( string $destination ): array {
        $seed = Str::seed( $destination );
        return [
            'tagline'            => $this->tagline( $destination ),
            'short_description'  => $this->shortDescription( $destination ),
            'description'        => $this->description( $destination, 600, 'scientific' ),
            'products_services'  => 'Research peptides (lyophilized, research use only), laboratory reagents, bacteriostatic water, and a Research Library of laboratory guides.',
            'differentiators'    => (string) Str::seededPick( [
                'Independent per-batch test reports with verification keys; domestic Canadian shipping; CAD pricing; card, e-Transfer and crypto payment.',
                'Canadian-owned and Toronto-based; Janoshik Analytical reports listed by batch; tracked Canada Post shipping with no customs holds.',
                'Documentation-first supplier: HPLC purity and mass-spec identity reports where available, reports for unlisted batches on request.',
            ], $seed, 31 ),
            'markets'            => 'Canada (all provinces and territories)',
            'customers'          => 'Universities, biotech and contract research organizations, institutional laboratories, independent qualified researchers',
            'compliance'         => $this->positioning(),
        ];
    }

    /** Short introduction for educational resource pages. */
    public function educationalIntro( string $destination, string $assetTitle, string $assetSummary ): string {
        $seed = Str::seed( $destination . $assetTitle );
        $opts = [
            "$assetTitle — $assetSummary Written for students, lab staff and researchers who need a clear, non-promotional explanation.",
            "$assetTitle: $assetSummary A practical reference for laboratory coursework and research training.",
            "A plain-English guide, $assetTitle, covering: $assetSummary Suitable as a reference for research laboratories and science educators.",
        ];
        return $this->filter->sanitize( (string) Str::seededPick( $opts, $seed, 41 ), false );
    }

    /** Resource-page contribution blurb. */
    public function resourceContribution( string $destination, string $assetTitle, string $assetUrl, string $assetSummary ): string {
        $seed = Str::seed( $destination . $assetUrl );
        $opts = [
            "$assetTitle ($assetUrl) — $assetSummary",
            "$assetTitle — $assetSummary ($assetUrl)",
            "$assetUrl — $assetTitle. $assetSummary",
        ];
        return $this->filter->sanitize( (string) Str::seededPick( $opts, $seed, 51 ), false );
    }

    /** Partnership proposal paragraph. */
    public function partnershipProposal( string $destination, string $partnerName, string $partnerContext ): string {
        $b    = $this->brand();
        $seed = Str::seed( $destination );
        $opts = [
            "$b would welcome a listing or partnership with $partnerName. $partnerContext We can contribute verified supplier information, laboratory documentation examples and practical educational content for your audience, and we are happy to supply membership or listing details in whatever format you need.",
            "As a Canadian-owned laboratory supplier, $b is interested in working with $partnerName. $partnerContext We can provide accurate company details, independent testing documentation and research-education resources, and we respect any editorial or membership requirements you have.",
        ];
        return $this->filter->sanitize( (string) Str::seededPick( $opts, $seed, 61 ) );
    }

    /**
     * Optional AI rewrite for a specific destination; always falls back to the template text.
     */
    public function rewriteForDestination( string $draft, string $destinationName, string $destinationContext, int $maxLength = 500 ): string {
        if ( ! $this->ai->isEnabled() ) {
            return $draft;
        }
        $facts  = implode( "\n- ", $this->facts->verified() );
        $system = "You write concise, factual business descriptions for directory listings and partner pages. Rules: use ONLY the verified facts provided; never add claims; no medical, therapeutic, dosing, benefit, safety or approval language; research products are for in-vitro laboratory research use only and not for human or veterinary use (say so once); no superlatives; plain, professional English; output only the description text.";
        $user   = "Destination: {$destinationName}\nDestination context: {$destinationContext}\nMaximum length: {$maxLength} characters.\n\nVerified facts:\n- {$facts}\n\nDraft to adapt for this destination (keep it accurate, make it read naturally for this audience):\n{$draft}";
        $out    = $this->ai->generate( $system, $user, 800 );
        if ( null === $out ) {
            return $draft;
        }
        $out = $this->filter->sanitize( $out );
        if ( ! $this->filter->isClean( $out ) || mb_strlen( $out ) < 80 ) {
            $this->logger->info( 'ai', 'AI rewrite rejected by claims filter; using template text.' );
            return $draft;
        }
        return $this->fitLength( $out, $maxLength );
    }

    private function fitLength( string $text, int $max ): string {
        if ( mb_strlen( $text ) <= $max ) {
            return $text;
        }
        // Drop whole sentences from the middle first, keeping the opening and the positioning sentence.
        $sentences = preg_split( '/(?<=[.!?])\s+/u', $text ) ?: [ $text ];
        while ( count( $sentences ) > 2 && mb_strlen( implode( ' ', $sentences ) ) > $max ) {
            array_splice( $sentences, count( $sentences ) - 2, 1 );
        }
        $out = implode( ' ', $sentences );
        return mb_strlen( $out ) <= $max ? $out : Str::truncate( $out, $max, '.' );
    }
}
