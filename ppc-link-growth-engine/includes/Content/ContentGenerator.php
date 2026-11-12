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

    /** Same research-use-only test the ClaimsFilter uses when it ensures positioning. */
    private const RUO_PATTERN = '/research use only|laboratory research (use|purposes) only|not for human/i';

    /** Shorter research-use-only statements for fields too small for the full positioning sentence. */
    private const RUO_SHORT = [ 'Research use only; not for human or veterinary use.', 'Research use only; not for human use.', 'Research use only.' ];

    /** Canadian province/territory codes (site.region) → names used in copy. */
    private const PROVINCES = [
        'AB' => 'Alberta',
        'BC' => 'British Columbia',
        'MB' => 'Manitoba',
        'NB' => 'New Brunswick',
        'NL' => 'Newfoundland and Labrador',
        'NS' => 'Nova Scotia',
        'NT' => 'Northwest Territories',
        'NU' => 'Nunavut',
        'ON' => 'Ontario',
        'PE' => 'Prince Edward Island',
        'QC' => 'Quebec',
        'SK' => 'Saskatchewan',
        'YT' => 'Yukon',
    ];

    private function brand(): string {
        return (string) $this->settings->get( 'site.brand_name', 'Pure Peptides Canada' );
    }

    private function city(): string {
        return trim( (string) $this->settings->get( 'site.city', 'Toronto' ) );
    }

    private function foundedYear(): string {
        return trim( (string) $this->settings->get( 'site.founded_year', '2023' ) );
    }

    /** Province/territory name from site.region (code or name); '' when unset. */
    private function region(): string {
        $region = trim( (string) $this->settings->get( 'site.region', 'ON' ) );
        return self::PROVINCES[ strtoupper( $region ) ] ?? $region;
    }

    /** The configured city is backed by the verified location fact. */
    private function located(): bool {
        return '' !== $this->city() && $this->facts->supports( 'location', $this->city() );
    }

    /** "Toronto, Ontario", or just the city when the verified location does not name the region. */
    private function place(): string {
        $region = $this->region();
        return '' !== $region && $this->facts->supports( 'location', $region ) ? $this->city() . ', ' . $region : $this->city();
    }

    /** The configured founding year is backed by the verified "established" fact. */
    private function founded(): bool {
        return '' !== $this->foundedYear() && $this->facts->supports( 'established', $this->foundedYear() );
    }

    private function canadianOwned(): bool {
        return $this->facts->supports( 'identity', 'canadian-owned' );
    }

    /**
     * Opening sentence variants. Each one is used only when every fact it states is verified
     * (identity, location, established, shipping); otherwise a neutral opening is used.
     *
     * @return string[]
     */
    private function openings(): array {
        $b        = $this->brand();
        $c        = $this->city();
        $p        = $this->place();
        $y        = $this->foundedYear();
        $owned    = $this->canadianOwned();
        $online   = $owned && $this->facts->supports( 'identity', 'online' );
        $located  = $this->located();
        $founded  = $this->founded();
        $national = $this->facts->supports( 'shipping', 'canada' );
        $out      = [];
        if ( $online && $located ) {
            $out[] = "$b is a Canadian-owned online supplier of research peptides and laboratory reagents based in $p.";
        }
        if ( $located && $founded ) {
            $out[] = "Based in $c and established in $y, $b supplies research peptides and laboratory reagents to Canadian research buyers.";
        }
        if ( $located && $national ) {
            $out[] = "$b supplies laboratory research peptides and reagents from $p, serving laboratories across Canada.";
        }
        if ( $founded && $located && $owned ) {
            $out[] = "Founded in $y, $b is a $c-based, Canadian-owned supplier of research peptides and laboratory reagents.";
        }
        if ( $online && $located ) {
            $out[] = "$b is an online laboratory supplier of research peptides and reagents, Canadian-owned and headquartered in $c.";
        }
        return [] !== $out ? $out : [ $this->compactOpening() ];
    }

    /** Opening that states no fact beyond what the business sells. */
    private function compactOpening(): string {
        return $this->brand() . ' supplies research peptides and laboratory reagents.';
    }

    /** @return string[] */
    private function qualitySentences(): array {
        $out      = [];
        $janoshik = $this->facts->supports( 'lab_reports', 'janoshik', 'independent', 'verification key' );
        if ( $janoshik && $this->facts->supports( 'lab_reports', 'lab testing', 'vial size', 'batch' ) ) {
            $out[] = 'Independent Janoshik Analytical test reports are listed on its Lab Testing page by product, vial size and batch, each carrying a verification key that can be checked on the laboratory\'s own website.';
        }
        if ( $janoshik && $this->facts->supports( 'lab_reports', 'vial size', 'batch' ) ) {
            $out[] = 'Batch-level analytical reports from an independent laboratory (Janoshik Analytical) are published by product and vial size, with verification keys for every current report.';
        }
        if ( $this->facts->supports( 'lab_reports', 'independent', 'batch' ) && $this->facts->supports( 'reports_on_request', 'on request' ) ) {
            $out[] = 'Rather than generic purity claims, the company publishes independent per-batch test reports and makes reports for unlisted batches available on request.';
        }
        if ( [] === $out && '' !== $this->facts->statement( 'lab_reports' ) ) {
            $out[] = $this->facts->statement( 'lab_reports' );
        }
        if ( $this->facts->supports( 'testing_methods', 'hplc', 'mass spectrometry' ) ) {
            $out[] = 'Documentation covers HPLC purity analysis and mass spectrometry identity confirmation where available for a lot.';
        } elseif ( '' !== $this->facts->statement( 'testing_methods' ) ) {
            $out[] = $this->facts->statement( 'testing_methods' );
        }
        return $out;
    }

    /** @return string[] */
    private function commerceSentences(): array {
        $out  = [];
        $cad  = $this->facts->supports( 'cad_pricing', 'canadian dollar' );
        $ship = $this->facts->supports( 'shipping', 'xpresspost', 'inside canada' );
        if ( $cad && $ship && $this->facts->supports( 'shipping', 'customs' ) ) {
            $out[] = 'Orders are priced in Canadian dollars and ship tracked from inside Canada by Canada Post Xpresspost, so there are no customs holds or brokerage fees.';
        }
        if ( $cad && $ship && $this->facts->supports( 'shipping_terms', 'free', '$299' ) ) {
            $out[] = 'Everything ships domestically by tracked Canada Post Xpresspost, with prices in CAD and free shipping on orders over $299.';
        }
        if ( [] === $out && '' !== $this->facts->statement( 'shipping' ) ) {
            $out[] = $this->facts->statement( 'shipping' );
        }
        if ( $this->facts->supports( 'payment', 'card', 'e-transfer', 'crypto' ) ) {
            $out[] = 'Checkout accepts credit and debit cards, Interac e-Transfer and cryptocurrency.';
        } elseif ( '' !== $this->facts->statement( 'payment' ) ) {
            $out[] = $this->facts->statement( 'payment' );
        }
        return $out;
    }

    /** @return string[] */
    private function audienceSentences(): array {
        $out = [];
        if ( $this->facts->supports( 'customers', 'universit', 'biotech', 'contract research', 'institutional', 'independent' ) ) {
            $out[] = 'Its customers include university research groups, biotech and contract research teams, institutional laboratories and independent qualified researchers.';
            if ( $this->facts->supports( 'customers', 'hospital' ) ) {
                $out[] = 'The company serves academic labs, biotech and CRO teams, hospital and institutional laboratories, and independent researchers working in a research capacity.';
            }
        } elseif ( '' !== $this->facts->statement( 'customers' ) ) {
            $out[] = $this->facts->statement( 'customers' );
        }
        if ( $this->facts->supports( 'library', 'research library', 'storage', 'hplc', 'certificate of analysis', 'canadian supplier' ) ) {
            $out[] = 'Its Research Library publishes practical guides on peptide storage and handling, HPLC testing, reading a certificate of analysis and evaluating Canadian suppliers.';
        } elseif ( '' !== $this->facts->statement( 'library' ) ) {
            $out[] = $this->facts->statement( 'library' );
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
        $seed    = Str::seed( $destination );
        $b       = $this->brand();
        $owned   = $this->canadianOwned();
        $reports = $this->facts->supports( 'lab_reports', 'independent', 'batch' );
        $opts    = [];
        if ( $owned && $reports ) {
            $opts[] = "$b: Canadian-owned supplier of research peptides and lab reagents, with independent batch test reports. Research use only.";
        }
        if ( $owned && $reports && $this->located() ) {
            $opts[] = "Research peptides and laboratory reagents from a Canadian-owned, {$this->city()}-based supplier. Independent COAs by batch. Research use only.";
        }
        if ( $reports && $this->facts->supports( 'shipping', 'canada' ) && $this->facts->supports( 'cad_pricing', 'canadian dollar' ) ) {
            $opts[] = "$b supplies lab research peptides and reagents across Canada, priced in CAD with per-batch lab reports. Research use only.";
        }
        if ( $owned && $reports && $this->facts->supports( 'shipping', 'tracked', 'inside canada' ) ) {
            $opts[] = 'Canadian-owned laboratory supplier of research peptides and reagents. Tracked domestic shipping, independent testing. Research use only.';
        }
        if ( [] === $opts ) {
            $opts[] = $this->compactOpening() . ' Research use only.';
        }
        $text = (string) Str::seededPick( $opts, $seed, 7 );
        return $this->fitLength( $this->filter->sanitize( $text, false ), $maxLength );
    }

    public function tagline( string $destination ): string {
        $opts = [];
        if ( $this->canadianOwned() ) {
            $opts[] = 'Canadian-owned research peptide and laboratory reagent supplier';
        }
        if ( $this->facts->supports( 'shipping', 'inside canada' ) ) {
            $opts[] = 'Research peptides and lab reagents, shipped from inside Canada';
        }
        if ( $this->facts->supports( 'lab_reports', 'independent' ) ) {
            $opts[] = 'Independently tested research peptides for Canadian laboratories';
        }
        if ( $this->facts->supports( 'lab_reports', 'batch' ) ) {
            $opts[] = 'Laboratory research reagents with batch-level test reports';
        }
        if ( [] === $opts ) {
            $opts[] = 'Research peptides and laboratory reagents for in-vitro research';
        }
        return (string) Str::seededPick( $opts, Str::seed( $destination ), 11 );
    }

    public function companyBackground( string $destination ): string {
        $seed        = Str::seed( $destination );
        $established = '';
        if ( $this->founded() ) {
            $established = $this->facts->supports( 'shipping', 'inside canada' )
                ? 'It was established in ' . $this->foundedYear() . ' to give Canadian research buyers a domestic source that avoids international shipping delays.'
                : 'It was established in ' . $this->foundedYear() . '.';
        }
        $parts = [
            Str::seededPick( $this->openings(), $seed, 21 ),
            $established,
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
        $seed      = Str::seed( $destination );
        $f         = $this->facts;
        $customers = $f->supports( 'customers', 'universit', 'biotech', 'contract research', 'institutional', 'independent' )
            ? 'Universities, biotech and contract research organizations, institutional laboratories, independent qualified researchers'
            : $f->statement( 'customers' );
        return [
            'tagline'            => $this->tagline( $destination ),
            'short_description'  => $this->shortDescription( $destination ),
            'description'        => $this->description( $destination, 600, 'scientific' ),
            'products_services'  => $f->supports( 'library', 'research library' )
                ? 'Research peptides (lyophilized, research use only), laboratory reagents, bacteriostatic water, and a Research Library of laboratory guides.'
                : 'Research peptides (lyophilized, research use only), laboratory reagents and bacteriostatic water.',
            'differentiators'    => (string) Str::seededPick( $this->differentiators(), $seed, 31 ),
            'markets'            => $f->supports( 'shipping', 'every province' ) ? 'Canada (all provinces and territories)' : 'Canada',
            'customers'          => $customers,
            'compliance'         => $this->positioning(),
        ];
    }

    /**
     * Differentiator variants for supplier profiles, each clause kept only when its fact is verified.
     *
     * @return string[]
     */
    private function differentiators(): array {
        $f        = $this->facts;
        $variants = [
            [
                $f->supports( 'lab_reports', 'independent', 'batch', 'verification key' ) ? 'Independent per-batch test reports with verification keys' : '',
                $f->supports( 'shipping', 'inside canada' ) ? 'domestic Canadian shipping' : '',
                $f->supports( 'cad_pricing', 'canadian dollar' ) ? 'CAD pricing' : '',
                $f->supports( 'payment', 'card', 'e-transfer', 'crypto' ) ? 'card, e-Transfer and crypto payment' : '',
            ],
            [
                $this->canadianOwned() && $this->located() ? 'Canadian-owned and ' . $this->city() . '-based' : '',
                $f->supports( 'lab_reports', 'janoshik', 'batch' ) ? 'Janoshik Analytical reports listed by batch' : '',
                $f->supports( 'shipping', 'canada post', 'customs' ) ? 'tracked Canada Post shipping with no customs holds' : '',
            ],
        ];
        $out = [];
        foreach ( $variants as $clauses ) {
            $clauses = array_values( array_filter( $clauses ) );
            if ( [] !== $clauses ) {
                $out[] = ucfirst( implode( '; ', $clauses ) ) . '.';
            }
        }
        $docs = array_values( array_filter( [
            $f->supports( 'testing_methods', 'hplc', 'mass spectrometry' ) ? 'HPLC purity and mass-spec identity reports where available' : '',
            $f->supports( 'reports_on_request', 'on request' ) ? 'reports for unlisted batches on request' : '',
        ] ) );
        if ( [] !== $docs ) {
            $out[] = 'Documentation-first supplier: ' . implode( ', ', $docs ) . '.';
        }
        return [] !== $out ? $out : [ 'Laboratory supplier of research peptides and reagents for research use only.' ];
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
        ];
        if ( $this->canadianOwned() && $this->facts->supports( 'lab_reports', 'independent' ) ) {
            $opts[] = "As a Canadian-owned laboratory supplier, $b is interested in working with $partnerName. $partnerContext We can provide accurate company details, independent testing documentation and research-education resources, and we respect any editorial or membership requirements you have.";
        }
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

    /**
     * Fits copy into a field limit without ever losing the research-use-only statement.
     *
     * Middle sentences are dropped first (keeping the opening). When the opening and the full
     * positioning sentence still do not fit, a shorter "not for human or veterinary use"
     * statement or a compact opening is used, then the bare "Research use only.", and only as a
     * last resort is the opening cut at a word boundary. The statement itself is never cut.
     */
    private function fitLength( string $text, int $max ): string {
        $text = trim( $text );
        if ( mb_strlen( $text ) <= $max ) {
            return $text;
        }
        $sentences = [];
        foreach ( preg_split( '/(?<=[.!?])\s+/u', $text ) ?: [ $text ] as $s ) {
            $s = trim( $s );
            if ( '' !== $s ) {
                $sentences[] = $s;
            }
        }
        $ruo = '';
        for ( $i = count( $sentences ) - 1; $i >= 0; $i-- ) {
            if ( preg_match( self::RUO_PATTERN, $sentences[ $i ] ) ) {
                $ruo = $sentences[ $i ];
                array_splice( $sentences, $i, 1 );
                break;
            }
        }
        // Candidate statements, longest first, never longer than the one the copy already had.
        $statements = [];
        foreach ( array_merge( [ $ruo ], self::RUO_SHORT ) as $st ) {
            if ( '' !== $st && ! in_array( $st, $statements, true ) && ( '' === $ruo || mb_strlen( $st ) <= mb_strlen( $ruo ) ) ) {
                $statements[] = $st;
            }
        }
        usort( $statements, static fn( string $a, string $b ): int => mb_strlen( $b ) <=> mb_strlen( $a ) );

        $opening = $sentences[0] ?? '';
        $middle  = array_slice( $sentences, 1 );
        $leads   = '' === $opening ? [ '' ] : array_values( array_unique( [ $opening, $this->compactOpening() ] ) );
        $chosen  = null;
        $lead    = '';
        // A statement that still says "not for human … use" is preferred, with the real opening if
        // possible, over the bare "Research use only." with a longer opening.
        $tiers = [
            array_values( array_filter( $statements, static fn( string $st ): bool => 1 === preg_match( '/not for human/i', $st ) ) ),
            array_values( array_filter( $statements, static fn( string $st ): bool => 1 !== preg_match( '/not for human/i', $st ) ) ),
        ];
        foreach ( $tiers as $tier ) {
            foreach ( $leads as $candidate ) {
                foreach ( $tier as $st ) {
                    if ( mb_strlen( '' === $candidate ? $st : $candidate . ' ' . $st ) <= $max ) {
                        [ $chosen, $lead ] = [ $st, $candidate ];
                        break 3;
                    }
                }
            }
        }
        if ( $lead !== $opening ) {
            $middle = []; // the full opening did not fit, so the longer middle sentences will not either
        }
        if ( null === $chosen ) {
            $chosen = (string) end( $statements );
            if ( mb_strlen( $chosen ) > $max ) {
                return Str::truncate( $chosen, $max, '' ); // field too small for any full statement
            }
            $lead = self::clip( $this->compactOpening(), $max - mb_strlen( $chosen ) - 1 );
        }

        $kept = '' !== $lead ? [ $lead ] : [];
        foreach ( $middle as $s ) {
            if ( mb_strlen( implode( ' ', array_merge( $kept, [ $s, $chosen ] ) ) ) > $max ) {
                break;
            }
            $kept[] = $s;
        }
        $kept[] = $chosen;
        $out    = implode( ' ', $kept );
        return $this->filter->isClean( $out ) ? $out : $chosen;
    }

    /** Cuts a sentence at a word boundary to fit $room characters, without a dangling connector. */
    private static function clip( string $sentence, int $room ): string {
        if ( mb_strlen( $sentence ) <= $room ) {
            return $sentence;
        }
        if ( $room < 12 ) {
            return '';
        }
        $cut = mb_substr( $sentence, 0, $room - 1 );
        $sp  = mb_strrpos( $cut, ' ' );
        if ( false !== $sp ) {
            $cut = mb_substr( $cut, 0, $sp );
        }
        do {
            $before = $cut;
            $cut    = (string) preg_replace( '/[\s,;:\-\x{2013}\x{2014}]+$/u', '', $cut );
            $cut    = (string) preg_replace( '/\s+(?:and|or|of|in|with|from|for|to|by|the|a|an|is|are|at|on|based|serving)$/iu', '', $cut );
        } while ( $cut !== $before );
        return '' !== $cut ? $cut . "\u{2026}" : '';
    }
}
