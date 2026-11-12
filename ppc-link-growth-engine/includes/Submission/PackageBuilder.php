<?php
/**
 * Builds a complete, compliant submission package for a prospect: verified business facts,
 * destination-specific generated copy, a mapping of detected form fields to values, the list of
 * required fields a human must still fill, and step-by-step instructions.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Submission;

use PPC\LinkGrowth\Analysis\FormDetector;
use PPC\LinkGrowth\Content\ClaimsFilter;
use PPC\LinkGrowth\Content\ContentGenerator;
use PPC\LinkGrowth\Content\FactsRegistry;
use PPC\LinkGrowth\Content\LinkableAssets;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Str;
use PPC\LinkGrowth\Support\Url;

final class PackageBuilder {

    /** Category match terms in priority order (first term with any matching option wins). */
    public const CATEGORY_TERMS = [ 'reagent', 'laboratory supplies', 'scientific supplies', 'laboratory', 'scientific', 'biotech', 'life science', 'chemical', 'research', 'science', 'medical supplies', 'industrial supplies', 'business services' ];

    /** Consent boxes that may be ticked (terms / privacy / accuracy) vs. boxes that must never be ticked (newsletters). */
    public const CONSENT_ALLOW = '/\b(terms|conditions|privacy|policy|policies|accura\w*|correct|true|agree\w*|confirm\w*|consent|authori[sz]e\w*|acknowledge\w*|certify|guidelines)\b/i';
    public const CONSENT_DENY  = '/\b(newsletter|subscribe|subscription|marketing|promotion\w*|offers|updates|mailing list|notify me|notifications|sms|text messages|partners?)\b/i';

    /**
     * Boxes that commit the business to money or a paid/sponsored placement (fees, charges, invoices,
     * prices, featured/premium upgrades, orders). They are never ticked, even when they also say
     * "agree"/"confirm": a required one stays unmapped, so the package is held for a person.
     */
    public const CONSENT_PAID = '/(\$|€|£|¥|\b(cad|usd|eur|gbp|aud)\b|\b\d+\s*(dollars?|euros?|pounds?)\b|\bfees?\b|\bpay(s|ing|ment|ments|able)?\b|\bpaid\b|\bcharg(e|es|ed|ing)\b|\binvoic\w*|\bbill(ed|ing)?\b|\bsponsor\w*|\bpremium\b|\bfeatured\b|\bupgrad\w*|\bpric(e|es|ed|ing)\b|\bpurchas\w*|\bcosts?\b|\bcheckout\b|\b(credit|debit) card\b|\brenew\w*|\bdonat\w*|\badvertis\w*|\b(my|this|your|the) order\b|\border (for|of)\b)/i';

    private const SCIENTIFIC_SITE_TYPES  = [ 'supplier_directory', 'publication', 'association', 'media' ];
    private const EDUCATIONAL_SITE_TYPES = [ 'educational', 'resource_page', 'government' ];
    private const RESOURCE_OPPORTUNITIES = [ 'resource_page', 'broken_link', 'educational_contribution', 'unlinked_mention' ];

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

    private const COUNTRIES = [
        'CA' => 'Canada',
        'US' => 'United States',
        'GB' => 'United Kingdom',
        'AU' => 'Australia',
    ];

    private ?ClaimsFilter $claims = null;

    public function __construct(
        private Settings $settings,
        private ContentGenerator $content,
        private FactsRegistry $facts,
        private LinkableAssets $assets
    ) {}

    /**
     * @param array<string,mixed>      $prospect    Prospect row (json columns decoded).
     * @param array<string,mixed>|null $opportunity Opportunity row.
     * @param array<string,mixed>|null $form        Detected form (FormDetector shape, or the compact profile copy).
     *                                              An optional "page_url" key tells where the form lives.
     * @return array<string,mixed>
     */
    public function build( array $prospect, ?array $opportunity, ?array $form ): array {
        $domain   = strtolower( trim( (string) ( $prospect['domain'] ?? '' ) ) );
        $siteType = (string) ( $prospect['site_type'] ?? 'unknown' ) ?: 'unknown';
        $oppType  = (string) ( ( $opportunity['type'] ?? '' ) ?: ( $prospect['opportunity_type'] ?? '' ) );
        $style    = $this->style( $siteType, $oppType );
        $destUrl  = (string) ( ( $opportunity['url'] ?? '' ) ?: ( ( $prospect['submission_url'] ?? '' ) ?: ( $prospect['homepage_url'] ?? '' ) ) );
        if ( '' === $destUrl && '' !== $domain ) {
            $destUrl = 'https://' . $domain . '/';
        }

        $destination = [
            'name'             => (string) ( ( $prospect['name'] ?? '' ) ?: $domain ),
            'domain'           => $domain,
            'url'              => $destUrl,
            'type'             => $siteType,
            'opportunity_type' => $oppType,
        ];

        $business = $this->business();
        $content  = $this->contentFor( $domain, $style, $siteType, $oppType, $destination, $opportunity, $business );

        $fields      = [];
        $unmapped    = [];
        $notes       = [];
        $formSummary = null;
        if ( is_array( $form ) && [] !== $form ) {
            $formSummary = self::summarizeForm( $form );
            $fields      = $this->mapFields( $form, $domain, $style, $business, $content, $unmapped, $notes );
        }

        $texts      = [ $content['tagline'], $content['short_description'], $content['description'], $content['company_background'], $content['request_message'] ];
        $violations = [];
        foreach ( $texts as $text ) {
            foreach ( $this->claims()->check( (string) $text ) as $v ) {
                $violations[] = [ 'rule' => $v['rule'], 'match' => $v['match'], 'message' => $v['message'] ];
            }
        }

        return [
            'destination'       => $destination,
            'content'           => $content,
            'business'          => $business,
            'fields'            => $fields,
            'unmapped_required' => array_values( array_unique( $unmapped ) ),
            'instructions'      => $this->instructions( $destination, $formSummary, $fields, $notes, $business ),
            'compliance'        => [ 'clean' => [] === $violations, 'violations' => $violations ],
            'form'              => $formSummary,
            'generated_at'      => gmdate( 'c' ),
        ];
    }

    /**
     * Pick the option best matching the ordered terms (exact text/value first, then word-prefix match).
     *
     * @param array<int,mixed> $options Each {value,text}.
     * @param string[]         $terms
     * @return array{value:string,text:string}|null
     */
    public static function matchOption( array $options, array $terms ): ?array {
        $norm = [];
        foreach ( $options as $o ) {
            if ( ! is_array( $o ) ) {
                continue;
            }
            $text  = trim( (string) ( $o['text'] ?? '' ) );
            $value = trim( (string) ( $o['value'] ?? '' ) );
            if ( '' === $text && '' === $value ) {
                continue;
            }
            if ( '' === $value && preg_match( '/^(-+|please (select|choose)|select|choose|none|n\/a|all|other)\b/i', $text ) ) {
                continue;
            }
            $norm[] = [
                'value' => '' !== $value ? $value : $text,
                'text'  => '' !== $text ? $text : $value,
                'lt'    => mb_strtolower( $text ),
                'lv'    => mb_strtolower( $value ),
            ];
        }
        if ( [] === $norm ) {
            return null;
        }
        $isCategory = $terms === self::CATEGORY_TERMS;
        // Exact text/value matches (province/country names, codes) win outright, in term order.
        if ( ! $isCategory ) {
            foreach ( $terms as $term ) {
                $t = mb_strtolower( trim( (string) $term ) );
                if ( '' === $t ) {
                    continue;
                }
                foreach ( $norm as $o ) {
                    if ( $o['lt'] === $t || $o['lv'] === $t ) {
                        return [ 'value' => $o['value'], 'text' => $o['text'] ];
                    }
                }
            }
        }
        // Otherwise score every option: earlier terms weigh more and, for category lists, options that
        // describe supplies/reagents beat equipment, devices or services (which a reagent supplier does not sell).
        $count      = count( $terms );
        $best       = null;
        $bestScore  = 0.0;
        foreach ( $norm as $o ) {
            $score = 0.0;
            foreach ( array_values( $terms ) as $i => $term ) {
                $t = mb_strtolower( trim( (string) $term ) );
                if ( '' === $t || mb_strlen( $t ) <= 2 ) {
                    continue; // Short codes ("ca", "on") only match exactly.
                }
                $stem  = str_ends_with( $t, 'y' ) ? preg_quote( mb_substr( $t, 0, -1 ), '/' ) . '(y|ies)' : preg_quote( $t, '/' );
                $regex = '/(?<![\p{L}\p{N}])' . $stem . '/u';
                if ( $o['lt'] === $t || $o['lv'] === $t ) {
                    $score += ( $count - $i ) + 20; // exact name match
                } elseif ( preg_match( $regex, $o['lt'] ) || preg_match( $regex, $o['lv'] ) ) {
                    $score += $count - $i;
                }
            }
            if ( $score <= 0 ) {
                continue;
            }
            if ( $isCategory ) {
                $score += preg_match( '/\breagents?\b/u', $o['lt'] ) ? 6 : 0;
                $score += preg_match( '/\bsuppl(y|ies)\b/u', $o['lt'] ) ? 4 : 0;
                $score += preg_match( '/\bscientific\b/u', $o['lt'] ) ? 3 : 0;
                $score += preg_match( '/\b(biotech\w*|life sciences?)\b/u', $o['lt'] ) ? 2 : 0;
                $score -= preg_match( '/\bequipment\b/u', $o['lt'] ) ? 3 : 0;
                $score -= preg_match( '/\b(devices?|software|services?|consulting|clinics?|pharmac\w+)\b/u', $o['lt'] ) ? 4 : 0;
            }
            if ( $score > $bestScore ) {
                $bestScore = $score;
                $best      = [ 'value' => $o['value'], 'text' => $o['text'] ];
            }
        }
        return $best;
    }

    /** Names a region code can be matched against (code first). @return string[] */
    public static function regionNames( string $code ): array {
        $code = strtoupper( trim( $code ) );
        if ( '' === $code ) {
            return [];
        }
        $name = self::PROVINCES[ $code ] ?? '';
        if ( '' === $name ) {
            $flip = array_change_key_case( array_flip( self::PROVINCES ), CASE_UPPER );
            if ( isset( $flip[ $code ] ) ) {
                return [ $flip[ $code ], ucwords( strtolower( $code ) ) ];
            }
            return [ $code ];
        }
        return [ $name, $code ];
    }

    /** Country names a code can be matched against. @return string[] */
    public static function countryNames( string $code ): array {
        $code = strtoupper( trim( $code ) );
        if ( '' === $code ) {
            return [];
        }
        $name = self::COUNTRIES[ $code ] ?? '';
        return '' !== $name ? [ $name, $code ] : [ $code ];
    }

    /**
     * Compact, storable summary of a detected form.
     *
     * @param array<string,mixed> $form
     * @return array<string,mixed>
     */
    public static function summarizeForm( array $form ): array {
        $fields  = is_array( $form['fields'] ?? null ) ? $form['fields'] : [];
        $hasFile = ! empty( $form['has_file'] );
        if ( ! $hasFile ) {
            foreach ( $fields as $f ) {
                if ( is_array( $f ) && 'file' === strtolower( (string) ( $f['type'] ?? '' ) ) ) {
                    $hasFile = true;
                    break;
                }
            }
        }
        return [
            'page_url'     => (string) ( $form['page_url'] ?? '' ),
            'action'       => (string) ( $form['action'] ?? '' ),
            'method'       => strtoupper( (string) ( $form['method'] ?? 'GET' ) ) ?: 'GET',
            'id'           => (string) ( $form['id'] ?? '' ),
            'kind'         => (string) ( $form['kind'] ?? '' ),
            'enctype'      => (string) ( $form['enctype'] ?? '' ),
            'has_captcha'  => ! empty( $form['has_captcha'] ),
            'has_password' => ! empty( $form['has_password'] ),
            'has_file'     => $hasFile,
            'fields_count' => count( $fields ),
        ];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function claims(): ClaimsFilter {
        if ( null === $this->claims ) {
            $this->claims = new ClaimsFilter( $this->facts );
        }
        return $this->claims;
    }

    /**
     * Fresh claims check of everything a package publishes or posts (content.* texts and field values),
     * in the payload's "compliance" shape. Used right before a package is approved or sent.
     *
     * @param array<string,mixed> $payload
     * @return array{clean:bool,violations:array<int,array{rule:string,match:string,message:string,field:string}>}
     */
    public function complianceOf( array $payload ): array {
        $violations = self::claimsViolations( $this->claims(), $payload );
        return [ 'clean' => [] === $violations, 'violations' => $violations ];
    }

    /**
     * Claims-filter hits in content.* texts (one level of lists) and fields[].value.
     *
     * @param array<string,mixed> $payload
     * @return array<int,array{rule:string,match:string,message:string,field:string}>
     */
    public static function claimsViolations( ClaimsFilter $filter, array $payload ): array {
        $out   = [];
        $check = static function ( string $field, mixed $value ) use ( &$out, $filter ): void {
            $values = is_array( $value ) ? $value : [ $value ];
            foreach ( $values as $k => $v ) {
                if ( ! is_string( $v ) || '' === trim( $v ) ) {
                    continue;
                }
                foreach ( $filter->check( $v ) as $hit ) {
                    $out[] = [
                        'rule'    => (string) $hit['rule'],
                        'match'   => (string) $hit['match'],
                        'message' => (string) $hit['message'],
                        'field'   => is_array( $value ) ? $field . '.' . $k : $field,
                    ];
                }
            }
        };
        foreach ( (array) ( $payload['content'] ?? [] ) as $k => $v ) {
            $check( 'content.' . $k, $v );
        }
        foreach ( (array) ( $payload['fields'] ?? [] ) as $f ) {
            if ( is_array( $f ) && isset( $f['name'] ) ) {
                $check( 'fields.' . (string) $f['name'], $f['value'] ?? '' );
            }
        }
        return $out;
    }

    private function style( string $siteType, string $oppType ): string {
        if ( in_array( $siteType, self::SCIENTIFIC_SITE_TYPES, true ) ) {
            return 'scientific';
        }
        if ( in_array( $siteType, self::EDUCATIONAL_SITE_TYPES, true ) ) {
            return 'educational';
        }
        if ( 'directory' === $siteType ) {
            return 'business';
        }
        if ( in_array( $oppType, [ 'supplier_listing', 'editorial', 'membership', 'expert_commentary' ], true ) ) {
            return 'scientific';
        }
        if ( in_array( $oppType, [ 'educational_contribution', 'resource_page', 'broken_link' ], true ) ) {
            return 'educational';
        }
        return 'business';
    }

    /** @return array<string,mixed> */
    private function business(): array {
        $s    = $this->settings;
        $home = trim( (string) $s->get( 'site.home_url', '' ) );
        if ( '' === $home ) {
            $home = 'https://' . $s->siteDomain();
        }
        $home    = untrailingslashit( Url::normalize( $home ) ?: $home );
        $country = strtoupper( trim( (string) $s->get( 'site.country', 'CA' ) ) ) ?: 'CA';
        $region  = trim( (string) $s->get( 'site.region', '' ) );
        $social  = [];
        foreach ( (array) $s->get( 'site.social', [] ) as $url ) {
            $url = Url::normalize( (string) $url );
            if ( '' !== $url ) {
                $social[] = $url;
            }
        }
        $brand = trim( (string) $s->get( 'site.brand_name', '' ) );
        return [
            'name'         => $brand,
            'legal_name'   => trim( (string) $s->get( 'site.legal_name', '' ) ) ?: $brand,
            'website'      => $home,
            'email'        => trim( (string) $s->get( 'site.email', '' ) ),
            'phone'        => trim( (string) $s->get( 'site.phone', '' ) ),
            'address'      => trim( (string) $s->get( 'site.address', '' ) ),
            'city'         => trim( (string) $s->get( 'site.city', '' ) ),
            'region'       => $region,
            'region_name'  => self::regionNames( $region )[0] ?? $region,
            'postal_code'  => trim( (string) $s->get( 'site.postal_code', '' ) ),
            'country'      => $country,
            'country_name' => self::countryNames( $country )[0] ?? $country,
            'founded_year' => trim( (string) $s->get( 'site.founded_year', '' ) ),
            'logo_url'     => trim( (string) $s->get( 'site.logo_url', '' ) ),
            'social'       => $social,
            'contact_name' => trim( (string) $s->get( 'outreach.sender_name', '' ) ),
        ];
    }

    /**
     * @param array<string,mixed>      $destination
     * @param array<string,mixed>|null $opportunity
     * @param array<string,mixed>      $business
     * @return array<string,mixed>
     */
    private function contentFor( string $domain, string $style, string $siteType, string $oppType, array $destination, ?array $opportunity, array $business ): array {
        $seedDomain = '' !== $domain ? $domain : $destination['name'];
        $categories = [];
        foreach ( (array) $this->settings->get( 'site.categories', [] ) as $c ) {
            $c = trim( (string) $c );
            if ( '' !== $c ) {
                $categories[] = $c;
            }
        }
        $suggestions = array_values( array_unique( array_merge( $categories, array_map( static fn( string $t ): string => Str::titleCase( $t ), self::CATEGORY_TERMS ) ) ) );

        $content = [
            'tagline'              => $this->content->tagline( $seedDomain ),
            'short_description'    => $this->content->shortDescription( $seedDomain, 160 ),
            'description'          => $this->content->description( $seedDomain, 500, $style ),
            'company_background'   => $this->content->companyBackground( $seedDomain ),
            'category_suggestions' => $suggestions,
            'keywords'             => $this->keywords( $categories ),
            'style'                => $style,
        ];

        $asset   = null;
        $details = is_array( $opportunity['details'] ?? null ) ? $opportunity['details'] : [];
        if ( in_array( $oppType, self::RESOURCE_OPPORTUNITIES, true ) ) {
            $asset = $this->assetFor( $opportunity, $details, $destination );
            $content['asset']          = $asset;
            $content['resource_blurb'] = $this->content->resourceContribution( $seedDomain, $asset['title'], $asset['url'], $asset['summary'] );
            if ( 'educational_contribution' === $oppType || 'educational' === $siteType ) {
                $content['educational_intro'] = $this->content->educationalIntro( $seedDomain, $asset['title'], $asset['summary'] );
            }
        }
        if ( in_array( $oppType, [ 'partnership', 'membership' ], true ) || 'association' === $siteType ) {
            $partnerContext = '' !== (string) ( $opportunity['title'] ?? '' )
                ? sprintf( 'Your page "%s" is relevant to laboratory research buyers in Canada.', (string) $opportunity['title'] )
                : sprintf( 'Your site %s is relevant to laboratory research buyers in Canada.', $destination['domain'] );
            $content['partnership_proposal'] = $this->content->partnershipProposal( $seedDomain, $destination['name'], $partnerContext );
        }
        if ( 'supplier_listing' === $oppType || 'supplier_directory' === $siteType ) {
            $content['supplier_profile'] = $this->content->supplierProfile( $seedDomain );
        }

        $content['subject']         = sprintf( 'Listing request: %s', $business['name'] ?: $domain );
        $content['request_message'] = $this->requestMessage( $destination, $business, $content, $oppType, $asset, $details );

        return $content;
    }

    /**
     * @param array<string,mixed>|null $opportunity
     * @param array<string,mixed>      $details
     * @param array<string,mixed>      $destination
     * @return array{url:string,title:string,summary:string,topics:string[],kind:string}
     */
    private function assetFor( ?array $opportunity, array $details, array $destination ): array {
        $suggested = (string) ( $details['suggested_url'] ?? '' );
        if ( '' !== $suggested ) {
            foreach ( $this->assets->all() as $a ) {
                if ( Url::normalize( $a['url'] ) === Url::normalize( $suggested ) ) {
                    return $a;
                }
            }
        }
        $parts = [
            (string) ( $opportunity['title'] ?? '' ),
            (string) ( $details['snippet'] ?? '' ),
            (string) ( $details['anchor'] ?? '' ),
            (string) ( $details['mention_snippet'] ?? '' ),
            (string) ( $details['broken_anchor'] ?? '' ),
            (string) ( $destination['name'] ?? '' ),
        ];
        $context = trim( implode( ' ', array_filter( $parts ) ) );
        return $this->assets->bestFor( '' !== $context ? $context : 'research peptides laboratory', 'guide' );
    }

    /**
     * @param string[] $categories
     * @return string[]
     */
    private function keywords( array $categories ): array {
        $base = [ 'research peptides', 'laboratory reagents', 'peptide supplier', 'laboratory supplies', 'biotechnology', 'research reagents', 'scientific supplies', 'canada' ];
        $out  = [];
        foreach ( array_merge( $categories, $base ) as $k ) {
            $k = mb_strtolower( trim( (string) $k ) );
            if ( '' !== $k && ! in_array( $k, $out, true ) ) {
                $out[] = $k;
            }
        }
        return array_slice( $out, 0, 10 );
    }

    /**
     * What the broken-link probe actually found, worded so the message never overstates it:
     * BrokenLinkFinder records details.failure as not_found|gone|dns|refused. Older opportunities
     * without the key fall back to the most cautious wording.
     *
     * @param array<string,mixed> $details
     */
    public static function brokenLinkProblem( array $details ): string {
        return match ( (string) ( $details['failure'] ?? '' ) ) {
            'dns'       => 'no longer resolves',
            'not_found' => 'returns a 404 (not found) error',
            'gone'      => 'returns a 410 (gone) error',
            default     => 'appears unreachable',
        };
    }

    /**
     * Plain-text request used for "message" fields and contact-form routes. Built from verified facts only.
     *
     * @param array<string,mixed>                                                             $destination
     * @param array<string,mixed>                                                             $business
     * @param array<string,mixed>                                                             $content
     * @param array{url:string,title:string,summary:string,topics:string[],kind:string}|null $asset
     * @param array<string,mixed>                                                             $details
     */
    private function requestMessage( array $destination, array $business, array $content, string $oppType, ?array $asset, array $details ): string {
        $brand    = (string) ( $business['name'] ?? '' );
        $destName = (string) ( $destination['name'] ?? '' ) ?: (string) ( $destination['domain'] ?? '' );
        $site     = (string) ( $business['website'] ?? '' );

        switch ( true ) {
            case in_array( $oppType, [ 'resource_page', 'educational_contribution', 'unlinked_mention' ], true ) && null !== $asset:
                $ask = sprintf( 'We would like to suggest a resource for %s: "%s" (%s). %s', $destName, $asset['title'], $asset['url'], $asset['summary'] );
                break;
            case 'broken_link' === $oppType && null !== $asset:
                $broken = (string) ( $details['broken_url'] ?? '' );
                $ask    = sprintf( 'While reading %s we noticed a link that %s%s. If useful, "%s" (%s) covers the same topic: %s', $destName, self::brokenLinkProblem( $details ), '' !== $broken ? ' (' . $broken . ')' : '', $asset['title'], $asset['url'], $asset['summary'] );
                break;
            case in_array( $oppType, [ 'partnership', 'membership' ], true ):
                $ask = (string) ( $content['partnership_proposal'] ?? sprintf( 'We are interested in a listing or membership with %s for %s (%s).', $destName, $brand, $site ) );
                break;
            case in_array( $oppType, [ 'editorial', 'expert_commentary', 'podcast' ], true ):
                $ask = sprintf( 'We would like to introduce %s (%s) as a Canadian laboratory supplier that can speak to peptide documentation, independent batch testing and research-use-only compliance, should that be useful for your coverage.', $brand, $site );
                break;
            default:
                $ask = sprintf( 'We would like to request a listing for %s (%s) on %s.', $brand, $site, $destName );
        }

        $location = implode( ', ', array_filter( [ (string) ( $business['city'] ?? '' ), (string) ( $business['region_name'] ?? '' ), (string) ( $business['country_name'] ?? '' ) ] ) );
        $lines    = [
            sprintf( 'Hello %s team,', $destName ),
            '',
            $ask,
            '',
            (string) ( $content['short_description'] ?? '' ),
            '',
            'Business details:',
            '- Name: ' . $brand,
            '- Website: ' . $site,
        ];
        if ( '' !== (string) ( $business['email'] ?? '' ) ) {
            $lines[] = '- Email: ' . $business['email'];
        }
        if ( '' !== $location ) {
            $lines[] = '- Location: ' . $location;
        }
        $suggestion = (string) ( $content['category_suggestions'][0] ?? '' );
        if ( '' !== $suggestion && ! in_array( $oppType, self::RESOURCE_OPPORTUNITIES, true ) ) {
            $lines[] = '- Suggested category: ' . $suggestion;
        }
        $lines[] = '';
        $lines[] = 'Thank you for considering this request. We are happy to provide any further details you need.';
        $lines[] = '';
        $lines[] = (string) ( ( $business['contact_name'] ?? '' ) ?: $brand );

        $text = implode( "\n", $lines );
        // Sanitise sentence by sentence without appending the positioning line (the description already carries it).
        return $this->claims()->isClean( $text ) ? $text : $this->claims()->sanitize( $text, false );
    }

    /**
     * @param array<string,mixed> $form
     * @param array<string,mixed> $business
     * @param array<string,mixed> $content
     * @param string[]            $unmapped
     * @param string[]            $notes
     * @return array<int,array<string,mixed>>
     */
    private function mapFields( array $form, string $domain, string $style, array $business, array $content, array &$unmapped, array &$notes ): array {
        $out = [];
        foreach ( (array) ( $form['fields'] ?? [] ) as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $name     = (string) ( $field['name'] ?? '' );
            $type     = strtolower( (string) ( $field['type'] ?? 'text' ) ) ?: 'text';
            $semantic = (string) ( $field['semantic'] ?? 'unknown' ) ?: 'unknown';
            $label    = trim( preg_replace( '/\s+/u', ' ', (string) ( $field['label'] ?? '' ) ) ?? '' );
            $required = ! empty( $field['required'] );
            $options  = is_array( $field['options'] ?? null ) ? $field['options'] : [];
            $optCount = [] !== $options ? count( $options ) : (int) ( is_numeric( $field['options'] ?? null ) ? $field['options'] : 0 );
            $maxLen   = (int) ( $field['maxlength'] ?? 0 );
            $matched  = null;
            $value    = null;
            $display  = '' !== $label ? $label : $name;

            switch ( true ) {
                case 'hidden' === $type:
                    $value = (string) ( $field['value'] ?? '' );
                    break;
                case 'file' === $type:
                    $value = null;
                    if ( $required ) {
                        $notes[] = sprintf( 'Upload the company logo for "%s" manually%s.', $display, '' !== (string) $business['logo_url'] ? ' (file: ' . $business['logo_url'] . ')' : '' );
                    }
                    break;
                case 'password' === $type || 'password' === $semantic:
                    $value = null;
                    if ( $required ) {
                        $notes[] = 'An account/password is required: create it yourself; this tool never stores or shares passwords.';
                    }
                    break;
                case 'captcha' === $semantic:
                    $value = null;
                    $notes[] = 'Complete the CAPTCHA yourself; the engine never bypasses human verification.';
                    break;
                case in_array( $type, [ 'checkbox', 'radio' ], true ):
                    $value = $this->toggleValue( $semantic, $label, $business );
                    break;
                case 'select' === $type:
                    [ $value, $matched ] = $this->selectValue( $semantic, $options, $business );
                    if ( null === $value && [] === $options && $optCount > 0 ) {
                        // Compact profile copy: options unknown here; the adapter re-matches against the live form.
                        $value = $this->textValue( $semantic, $type, $label, $business, $content, $domain, $style, 0 );
                        if ( null !== $value && '' !== $value ) {
                            $notes[] = sprintf( 'Choose the option closest to "%s" for "%s".', $value, $display );
                        }
                    }
                    break;
                default:
                    $value = $this->textValue( $semantic, $type, $label, $business, $content, $domain, $style, $maxLen );
            }

            if ( $required && 'hidden' !== $type && ( null === $value || '' === $value ) ) {
                $unmapped[] = $semantic;
                if ( ! in_array( $type, [ 'file' ], true ) && 'captcha' !== $semantic && 'password' !== $semantic && 'password' !== $type ) {
                    $notes[] = sprintf( 'Fill the required field "%s" (%s) manually.', $display, $semantic );
                }
            }

            $out[] = [
                'name'           => $name,
                'label'          => $label,
                'semantic'       => $semantic,
                'type'           => $type,
                'required'       => $required,
                'value'          => (string) ( $value ?? '' ),
                'matched_option' => $matched,
                'options_count'  => $optCount,
            ];
        }
        return $out;
    }

    /**
     * Value for a checkbox/radio: "1" = tick, "" = leave untouched.
     *
     * @param array<string,mixed> $business
     */
    private function toggleValue( string $semantic, string $label, array $business ): string {
        if ( preg_match( self::CONSENT_DENY, $label ) || preg_match( self::CONSENT_PAID, $label ) ) {
            return '';
        }
        if ( 'consent' === $semantic ) {
            return preg_match( self::CONSENT_ALLOW, $label ) ? '1' : '';
        }
        if ( 'category' === $semantic ) {
            return null !== self::matchOption( [ [ 'value' => '1', 'text' => $label ] ], self::CATEGORY_TERMS ) ? '1' : '';
        }
        if ( 'country' === $semantic ) {
            return null !== self::matchOption( [ [ 'value' => '1', 'text' => $label ] ], self::countryNames( (string) $business['country'] ) ) ? '1' : '';
        }
        return '';
    }

    /**
     * @param array<int,mixed>    $options
     * @param array<string,mixed> $business
     * @return array{0:?string,1:?array{value:string,text:string}}
     */
    private function selectValue( string $semantic, array $options, array $business ): array {
        if ( [] === $options ) {
            return [ null, null ];
        }
        $terms = match ( $semantic ) {
            'category' => self::CATEGORY_TERMS,
            'country'  => self::countryNames( (string) $business['country'] ),
            'region'   => self::regionNames( (string) $business['region'] ),
            'founded'  => [ (string) $business['founded_year'] ],
            default    => [],
        };
        if ( [] === $terms ) {
            return [ null, null ];
        }
        $hit = self::matchOption( $options, $terms );
        return null === $hit ? [ null, null ] : [ $hit['value'], $hit ];
    }

    /**
     * @param array<string,mixed> $business
     * @param array<string,mixed> $content
     */
    private function textValue( string $semantic, string $type, string $label, array $business, array $content, string $domain, string $style, int $maxLen ): ?string {
        $short = $maxLen > 0 && $maxLen <= 3;
        switch ( $semantic ) {
            case 'website':
                $value = (string) $business['website'];
                break;
            case 'email':
                $value = (string) $business['email'];
                break;
            case 'business_name':
                $value = (string) $business['name'];
                break;
            case 'contact_name':
                $value = $this->contactName( $label, $business );
                break;
            case 'phone':
                $value = (string) $business['phone'];
                break;
            case 'description':
                $value = $maxLen > 0 ? ( $maxLen >= 120 ? $this->content->description( $domain, $maxLen, $style ) : $this->content->shortDescription( $domain, $maxLen ) ) : (string) $content['description'];
                break;
            case 'message':
                $value = (string) $content['request_message'];
                if ( $maxLen > 0 && mb_strlen( $value ) > $maxLen ) {
                    $value = $maxLen >= 120 ? $this->content->description( $domain, $maxLen, $style ) : $this->content->shortDescription( $domain, $maxLen );
                }
                break;
            case 'tagline':
                $value = (string) $content['tagline'];
                break;
            case 'category':
                $value = (string) ( $content['category_suggestions'][0] ?? 'Laboratory' );
                break;
            case 'address':
                $value = (string) $business['address'];
                break;
            case 'city':
                $value = (string) $business['city'];
                break;
            case 'region':
                $value = $short ? (string) $business['region'] : (string) $business['region_name'];
                break;
            case 'postal_code':
                $value = (string) $business['postal_code'];
                break;
            case 'country':
                $value = $short ? (string) $business['country'] : (string) $business['country_name'];
                break;
            case 'keywords':
                $value = implode( ', ', (array) $content['keywords'] );
                break;
            case 'logo':
                $value = in_array( $type, [ 'url', 'text' ], true ) ? (string) $business['logo_url'] : '';
                break;
            case 'founded':
                $value = (string) $business['founded_year'];
                break;
            case 'social':
                $value = $this->socialFor( $label, (array) $business['social'] );
                break;
            case 'subject':
                $value = (string) $content['subject'];
                break;
            default:
                return null;
        }
        if ( $maxLen > 0 && mb_strlen( $value ) > $maxLen ) {
            $value = Str::truncate( $value, $maxLen, '' );
        }
        return $value;
    }

    /** @param array<string,mixed> $business */
    private function contactName( string $label, array $business ): ?string {
        $sender = trim( (string) ( $business['contact_name'] ?? '' ) );
        $isFirst = (bool) preg_match( '/\b(first|given)\b/i', $label );
        $isLast  = (bool) preg_match( '/\b(last|surname|family)\b/i', $label );
        if ( '' === $sender ) {
            return ( $isFirst || $isLast ) ? null : (string) $business['name'];
        }
        if ( $isFirst ) {
            return Str::firstName( $sender ) ?: $sender;
        }
        if ( $isLast ) {
            $parts = preg_split( '/\s+/', $sender ) ?: [];
            return count( $parts ) > 1 ? (string) end( $parts ) : null;
        }
        return $sender;
    }

    /** @param string[] $social */
    private function socialFor( string $label, array $social ): string {
        if ( [] === $social ) {
            return '';
        }
        $hay = strtolower( $label );
        $map = [
            'facebook'  => [ 'facebook.com' ],
            'twitter'   => [ 'x.com', 'twitter.com' ],
            'linkedin'  => [ 'linkedin.com' ],
            'instagram' => [ 'instagram.com' ],
            'youtube'   => [ 'youtube.com' ],
            'tiktok'    => [ 'tiktok.com' ],
        ];
        if ( preg_match( '/\bx\b|x\.com/', $hay ) ) {
            $hay .= ' twitter';
        }
        foreach ( $map as $key => $hosts ) {
            if ( str_contains( $hay, $key ) ) {
                foreach ( $social as $url ) {
                    foreach ( $hosts as $h ) {
                        if ( Url::belongsTo( $url, $h ) ) {
                            return $url;
                        }
                    }
                }
                return '';
            }
        }
        return (string) $social[0];
    }

    /**
     * @param array<string,mixed>            $destination
     * @param array<string,mixed>|null       $form
     * @param array<int,array<string,mixed>> $fields
     * @param string[]                       $notes
     * @param array<string,mixed>            $business
     * @return string[]
     */
    private function instructions( array $destination, ?array $form, array $fields, array $notes, array $business ): array {
        $steps = [];
        $page  = (string) ( ( $form['page_url'] ?? '' ) ?: ( $destination['url'] ?? '' ) );
        if ( '' !== $page ) {
            $steps[] = sprintf( 'Open %s', $page );
        }
        if ( null !== $form ) {
            $kind    = (string) ( $form['kind'] ?? '' );
            $steps[] = FormDetector::KIND_LISTING === $kind
                ? 'Complete the listing form with the business details and generated content in this package; mapped fields are pre-filled below.'
                : sprintf( 'The page has a %s form (no dedicated listing form was found): use the request message below and the business details.', '' !== $kind ? str_replace( '_', ' ', $kind ) : 'generic' );
            foreach ( $fields as $f ) {
                if ( is_array( $f['matched_option'] ?? null ) ) {
                    $steps[] = sprintf( 'Select "%s" for "%s".', (string) $f['matched_option']['text'], (string) ( $f['label'] ?: $f['name'] ) );
                }
            }
            if ( ! empty( $form['has_captcha'] ) ) {
                $steps[] = 'Complete the CAPTCHA yourself; the engine never bypasses human verification.';
            }
            if ( ! empty( $form['has_password'] ) ) {
                $steps[] = 'An account is required: register with the business email and keep the password out of this tool.';
            }
        } else {
            $steps[] = 'Locate the "Add listing", "Submit" or "Contact" page on the site; if a contact form is the only route, paste the request message below.';
        }
        foreach ( array_unique( $notes ) as $n ) {
            $steps[] = $n;
        }
        $steps[] = 'Use the destination-specific description from this package as-is; never paste copy that was used on another directory.';
        $steps[] = 'Tick only terms, privacy or accuracy confirmations; leave newsletter and marketing opt-ins unticked.';
        if ( '' !== (string) ( $business['logo_url'] ?? '' ) ) {
            $steps[] = 'Logo file (if the listing accepts one): ' . $business['logo_url'];
        }
        $steps[] = 'After submitting, use "Mark submitted" to record the confirmation reference and listing URL so verification can begin.';
        return array_values( array_unique( $steps ) );
    }
}
