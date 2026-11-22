<?php
/**
 * Generates unique, compliant business descriptions, profiles and pitches per destination.
 *
 * Deterministic variety: the destination domain seeds sentence selection so two directories
 * never receive identical copy, while repeated runs for the same destination stay stable.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Content;

use LinkGrowth\Autopilot\Integrations\AiWriter;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\Str;

final class ContentGenerator {

    public function __construct(
        private FactsRegistry $facts,
        private ClaimsFilter $filter,
        private Settings $settings,
        private AiWriter $ai,
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
        return $this->settings->brandName();
    }

    private function city(): string {
        return trim( (string) $this->settings->get( 'site.city', '' ) );
    }

    private function foundedYear(): string {
        return trim( (string) $this->settings->get( 'site.founded_year', '' ) );
    }

    /** Province/territory name from site.region (code or name); '' when unset. */
    private function region(): string {
        $region = trim( (string) $this->settings->get( 'site.region', '' ) );
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

    /** What the business offers, from the profile: "industrial supplies and packaging" ('' when unset). */
    private function offer(): string {
        $offer = FactsRegistry::listPhrase( array_map( 'strval', (array) $this->settings->get( 'site.categories', [] ) ) );
        if ( '' === $offer ) {
            $offer = rtrim( trim( (string) $this->settings->get( 'site.tagline', '' ) ), '.' );
        }
        return mb_strtolower( $offer );
    }

    /**
     * Opening sentence variants from the business profile. Each one is used only when every fact it states is
     * verified (location, established); otherwise a neutral opening is used.
     *
     * @return string[]
     */
    private function openings(): array {
        $b       = $this->brand();
        $o       = $this->offer();
        $c       = $this->city();
        $p       = $this->place();
        $y       = $this->foundedYear();
        $located = $this->located();
        $founded = $this->founded();
        $out     = [];
        if ( '' !== $o && $located ) {
            $out[] = "$b supplies $o from $p.";
            $out[] = "$b is a supplier of $o based in $p.";
        }
        if ( '' !== $o && $located && $founded ) {
            $out[] = "Based in $c and established in $y, $b supplies $o.";
            $out[] = "Founded in $y, $b is a $c-based supplier of $o.";
        }
        return [] !== $out ? $out : [ $this->compactOpening() ];
    }

    /** Opening that states no fact beyond what the business offers. */
    private function compactOpening(): string {
        $o = $this->offer();
        return '' !== $o ? $this->brand() . ' supplies ' . $o . '.' : $this->brand() . '.';
    }

    /**
     * Verified fact statements of the given groups, as written by the owner (one candidate sentence each).
     *
     * @return string[]
     */
    private function groupSentences( string ...$groups ): array {
        $out = [];
        foreach ( $groups as $group ) {
            foreach ( $this->facts->verified( $group ) as $statement ) {
                $statement = trim( $statement );
                if ( '' !== $statement ) {
                    $out[] = $statement;
                }
            }
        }
        return $out;
    }

    /** @return string[] */
    private function qualitySentences(): array {
        return $this->groupSentences( 'quality' );
    }

    /** @return string[] */
    private function commerceSentences(): array {
        return $this->groupSentences( 'commerce' );
    }

    /** @return string[] */
    private function audienceSentences(): array {
        return $this->groupSentences( 'audience', 'content' );
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
        $o    = $this->offer();
        $opts = [];
        if ( '' !== $o ) {
            $opts[] = "$b: " . $o . '.';
            if ( $this->located() ) {
                $opts[] = self::upperFirst( $o ) . " from $b, based in {$this->city()}.";
            }
        }
        if ( [] === $opts ) {
            $opts[] = $this->compactOpening();
        }
        $text = (string) Str::seededPick( $opts, $seed, 7 ) . ' Research use only.';
        return $this->fitLength( $this->filter->sanitize( $text, false ), $maxLength );
    }

    public function tagline( string $destination ): string {
        $own = rtrim( trim( (string) $this->settings->get( 'site.tagline', '' ) ), '.' );
        if ( '' !== $own ) {
            return $own;
        }
        $o    = $this->offer();
        $opts = '' !== $o ? [ self::upperFirst( $o ), self::upperFirst( $o ) . ' supplier' ] : [ $this->brand() ];
        if ( '' !== $o && $this->located() ) {
            $opts[] = self::upperFirst( $o ) . ' from ' . $this->city();
        }
        return (string) Str::seededPick( $opts, Str::seed( $destination ), 11 );
    }

    public function companyBackground( string $destination ): string {
        $seed    = Str::seed( $destination );
        $opening = (string) Str::seededPick( $this->openings(), $seed, 21 );
        $parts   = [
            $opening,
            $this->founded() && ! str_contains( $opening, $this->foundedYear() ) ? 'It was established in ' . $this->foundedYear() . '.' : '',
            Str::seededPick( $this->audienceSentences(), $seed, 22 ),
            Str::seededPick( $this->qualitySentences(), $seed, 23 ),
            Str::seededPick( $this->commerceSentences(), $seed, 24 ),
            $this->facts->statement( 'support' ),
            $this->positioning(),
        ];
        return $this->filter->sanitize( implode( ' ', array_filter( array_map( 'strval', $parts ) ) ) );
    }

    /** Supplier profile for business and industry directories. */
    public function supplierProfile( string $destination ): array {
        $seed      = Str::seed( $destination );
        $o         = $this->offer();
        $audience  = $this->groupSentences( 'audience' );
        return [
            'tagline'            => $this->tagline( $destination ),
            'short_description'  => $this->shortDescription( $destination ),
            'description'        => $this->description( $destination, 600, 'scientific' ),
            'products_services'  => '' !== $o ? self::upperFirst( $o ) . '.' : $this->compactOpening(),
            'differentiators'    => (string) Str::seededPick( $this->differentiators(), $seed, 31 ),
            'markets'            => $this->market(),
            'customers'          => (string) ( $audience[0] ?? '' ),
            'compliance'         => $this->positioning(),
        ];
    }

    /** Country the business names in its profile, as a name ("United States"), or '' when unset. */
    private function market(): string {
        $code = strtoupper( trim( (string) $this->settings->get( 'site.country', '' ) ) );
        if ( '' === $code ) {
            return '';
        }
        $names = \LinkGrowth\Autopilot\Submission\PackageBuilder::countryNames( $code );
        return (string) ( $names[0] ?? $code );
    }

    /**
     * Differentiator variants for supplier profiles: the owner's verified quality and commerce facts, else what the
     * business offers.
     *
     * @return string[]
     */
    private function differentiators(): array {
        $out = array_merge( $this->qualitySentences(), $this->commerceSentences() );
        return [] !== $out ? $out : [ $this->compactOpening() ];
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
            "$b would welcome a listing or partnership with $partnerName. $partnerContext We can contribute accurate business information and practical educational content for your audience, and we are happy to supply membership or listing details in whatever format you need.",
        ];
        if ( $this->located() ) {
            $opts[] = "$b, based in {$this->city()}, is interested in working with $partnerName. $partnerContext We can provide accurate company details and practical resources, and we respect any editorial or membership requirements you have.";
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

    private static function upperFirst( string $text ): string {
        return '' === $text ? '' : mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
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
