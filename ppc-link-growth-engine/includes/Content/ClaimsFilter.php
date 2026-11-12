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

    /** Words that can negate the claim right after them ("not for human use", "no dosing guidance"). */
    private const NEGATION = '(?:not|no|never|without|nor|neither|rather\h+than|instead\h+of)';

    /**
     * Words that end a negation's scope. A claim after one of them is a new assertion, not part of
     * the negated phrase: "no known risks and heals…", "no joke, it cures…", "never fails to heal…".
     */
    private const SCOPE_BREAKERS = 'and|but|so|yet|then|also|plus|it|its|they|their|them|we|our|us|you|your|he|she|this|these|those|that|which|who|while|because|just|only|even|merely|simply|fail|fails|failed|doubt|joke|kidding|problem|problems|wonder|surprise|longer|more|less|matter|worries|worry|need|needs|needed|prescription|pressure|question|questions|risk|risks|side|hassle|wait|waiting|other|else';

    /** Bytes of text before a hit that are searched for a governing negation. */
    private const NEGATION_WINDOW = 60;

    public function __construct( private FactsRegistry $facts ) {}

    /**
     * @return array<int,array{rule:string,match:string,message:string,offset:int}>
     */
    public function check( string $text ): array {
        $violations = [];
        foreach ( self::RULES as $rule => $def ) {
            if ( preg_match_all( $def['regex'], $text, $m, PREG_OFFSET_CAPTURE ) ) {
                foreach ( $m[0] as $hit ) {
                    if ( self::isNegated( $text, (int) $hit[1] ) ) {
                        continue;
                    }
                    $violations[] = [ 'rule' => $rule, 'match' => $hit[0], 'message' => $def['message'], 'offset' => (int) $hit[1] ];
                }
            }
        }
        return $violations;
    }

    /**
     * True when the phrase at $offset is directly governed by a negation, as in compliance
     * statements like "not for human use", "does not provide dosing, administration or protocol
     * guidance", "not a supplement" or "neither a drug nor a supplement".
     *
     * Only text strictly before the match counts, so a negation inside the match ("no side
     * effects") or later in the sentence ("cure disease, not just symptoms") never hides it.
     * The negation must sit at most two words before the match within the same clause, and none
     * of those words may start a new assertion (see SCOPE_BREAKERS). Punctuation other than a
     * list comma, and line breaks, end the scope.
     */
    private static function isNegated( string $text, int $offset ): bool {
        if ( $offset <= 0 ) {
            return false;
        }
        $start  = max( 0, $offset - self::NEGATION_WINDOW );
        $before = strtolower( substr( $text, $start, $offset - $start ) );
        if ( $start > 0 && 1 === preg_match( '/[A-Za-z0-9\'\-]/', $text[ $start - 1 ] ) ) {
            // The window starts mid-word ("cannot" → "not"); drop that partial word.
            $before = (string) preg_replace( '/^[a-z0-9\'\-]*/', '', $before );
        }
        $word    = '(?!(?:' . self::SCOPE_BREAKERS . ')\b)[a-z][a-z0-9\'\-]*(?:,?\h+(?:(?:or|nor)\h+)?|\/)';
        $pattern = '/(?<![a-z0-9\-])' . self::NEGATION . '\h+(?:' . $word . '){0,2}$/';
        return 1 === preg_match( $pattern, $before );
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
