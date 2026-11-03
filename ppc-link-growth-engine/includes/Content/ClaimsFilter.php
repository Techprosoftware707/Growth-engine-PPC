<?php
/**
 * Blocks medical/therapeutic claims, human-use language and unsupported product claims.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Content;

final class ClaimsFilter {

    /** @var array<string,array{regex:string,message:string}> */
    private const RULES = [
        'therapeutic'   => [ 'regex' => '/\b(treat(s|ed|ing|ment)?|cure(s|d)?|heal(s|ed|ing)?|therap(y|ies|eutic)|remedy|relie(f|ve)|prevent(s|ion)?|regenerat(e|ive|ion)|rejuvenat|recover(y|ies) from)\b/i', 'message' => 'Therapeutic or medical claim' ],
        'human_use'     => [ 'regex' => '/\b(for (human|personal|your) (use|health|body)|take (it|daily|before|after)|dos(e|es|age|ing)|inject(ion|ions|able)?|administer|administration|cycle|stack|protocol for (use|users))\b/i', 'message' => 'Human-use, dosing or administration language' ],
        'benefit'       => [ 'regex' => '/\b(weight[\s\-]?loss|fat[\s\-]?loss|muscle (growth|gain|building)|anti[\s\-]?ag(e|ing)|longevity|performance[\s\-]?enhanc|boost(s|ing)? (your|energy|immune|metabolism)|burn(s|ing)? fat|wrinkle|skin (repair|tightening)|hair (growth|regrowth)|libido|sleep better|tanning)\b/i', 'message' => 'Health/cosmetic benefit claim' ],
        'approval'      => [ 'regex' => '/\b((fda|health canada|ema|who)[\s\-]?(approved|registered|certified|cleared|compliant)|clinically (proven|tested)|doctor[\s\-]?recommended|medical[\s\-]?grade|pharmaceutical[\s\-]?grade|gmp[\s\-]?certified)\b/i', 'message' => 'Regulatory approval or grade claim that is not verified' ],
        'purity_number' => [ 'regex' => '/\b(9\d(\.\d+)?|100)\s?%\s?(pur(e|ity)|purity guaranteed)\b/i', 'message' => 'Specific purity percentage claim (batch-specific; cite the COA instead)' ],
        'superlative'   => [ 'regex' => '/\b(#\s?1|number one|the best|best (peptide|supplier|quality|prices?)|cheapest|lowest price|guaranteed results|world[\s\-]?class|industry[\s\-]?leading|top[\s\-]?rated|highest quality)\b/i', 'message' => 'Unsupported superlative' ],
        'safety'        => [ 'regex' => '/\b(safe (to use|for (humans|people|you))|no side effects|risk[\s\-]?free|100% safe)\b/i', 'message' => 'Safety claim' ],
        'supplement'    => [ 'regex' => '/\b(supplement(s|ation)?|nutraceutical|wellness product|health product)\b/i', 'message' => 'Positions research reagents as supplements/health products' ],
        'patient'       => [ 'regex' => '/\b(patients?|clients? taking|users? report)\b/i', 'message' => 'Implies clinical or consumer use' ],
    ];

    public function __construct( private FactsRegistry $facts ) {}

    /**
     * @return array<int,array{rule:string,match:string,message:string,offset:int}>
     */
    public function check( string $text ): array {
        $violations = [];
        foreach ( self::RULES as $rule => $def ) {
            if ( preg_match_all( $def['regex'], $text, $m, PREG_OFFSET_CAPTURE ) ) {
                foreach ( $m[0] as $hit ) {
                    // Allow negated compliance statements ("not for human use", "does not provide dosing guidance", "not a supplement").
                    $before = strtolower( substr( $text, max( 0, $hit[1] - 40 ), 40 ) );
                    if ( preg_match( '/\b(not|no|never|without|nor|neither|rather than|instead of)\b[^.]*$/', $before ) ) {
                        continue;
                    }
                    $violations[] = [ 'rule' => $rule, 'match' => $hit[0], 'message' => $def['message'], 'offset' => (int) $hit[1] ];
                }
            }
        }
        return $violations;
    }

    public function isClean( string $text ): bool {
        return [] === $this->check( $text );
    }

    /**
     * Removes sentences containing violations; guarantees the research-use positioning sentence is present.
     */
    public function sanitize( string $text, bool $ensurePositioning = true ): string {
        $sentences = preg_split( '/(?<=[.!?])\s+/u', trim( $text ) ) ?: [ $text ];
        $kept      = [];
        foreach ( $sentences as $s ) {
            if ( '' === trim( $s ) ) {
                continue;
            }
            if ( $this->isClean( $s ) ) {
                $kept[] = trim( $s );
            }
        }
        $out = implode( ' ', $kept );
        if ( $ensurePositioning && ! preg_match( '/research use only|laboratory research (use|purposes) only|not for human/i', $out ) ) {
            $pos = $this->facts->statement( 'research_use_only' );
            if ( '' !== $pos ) {
                $out = trim( $out . ' ' . $pos );
            }
        }
        return $out;
    }

    /** @return array<string,string> rule => description, for the settings UI */
    public static function rules(): array {
        $out = [];
        foreach ( self::RULES as $k => $d ) {
            $out[ $k ] = $d['message'];
        }
        return $out;
    }
}
