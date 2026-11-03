<?php
/**
 * Builds the placeholder values for one prospect / contact / opportunity and renders templates.
 *
 * Every value is derived from data the plugin actually observed (the prospect profile, the
 * opportunity details, the verified facts registry and the configured sender identity); nothing
 * is invented. The optional AI pass may only rephrase the editable part of a draft and must leave
 * the signature and compliance footer untouched.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Outreach;

use PPC\LinkGrowth\Content\ClaimsFilter;
use PPC\LinkGrowth\Content\FactsRegistry;
use PPC\LinkGrowth\Content\LinkableAssets;
use PPC\LinkGrowth\Integrations\ClaudeWriter;
use PPC\LinkGrowth\Support\Json;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Str;
use PPC\LinkGrowth\Support\Url;

final class Personalizer {

    /** Mandatory CASL / CAN-SPAM footer (rendered with brand, postal address and unsubscribe link). */
    public const FOOTER_TEMPLATE = "{{brand}} · {{postal_address}}\nIf you'd rather not hear from us again, reply with \"unsubscribe\" or use this link: {{unsubscribe_url}}";

    private const MAX_TITLE   = 110;
    private const MAX_SNIPPET = 160;

    public function __construct(
        private LinkableAssets $assets,
        private FactsRegistry $facts,
        private Settings $settings
    ) {}

    /**
     * Placeholder values for a message.
     *
     * @param array<string,mixed>      $prospect    Prospect row (profile JSON decoded or raw).
     * @param array<string,mixed>|null $opportunity Opportunity row (details JSON decoded or raw).
     * @param array<string,mixed>|null $contact     Contact row.
     * @return array<string,string>
     */
    public function context( array $prospect, ?array $opportunity, ?array $contact, string $approach, string $unsubscribeUrl ): array {
        $profile = Json::decode( $prospect['profile'] ?? [] );
        $home    = is_array( $profile['home'] ?? null ) ? $profile['home'] : [];
        $details = Json::decode( $opportunity['details'] ?? [] );
        $domain  = strtolower( trim( (string) ( $prospect['domain'] ?? '' ) ) );

        $brand       = $this->brand();
        $homeUrl     = $this->homeUrl();
        $city        = trim( (string) $this->settings->get( 'site.city', '' ) );
        $senderName  = trim( (string) $this->settings->get( 'outreach.sender_name', '' ) );
        $senderEmail = trim( (string) $this->settings->get( 'outreach.sender_email', '' ) );
        $postal      = $this->postalAddress();

        $siteName  = $this->siteName( $prospect, $profile, $home, $domain );
        $pageUrl   = $this->pageUrl( $prospect, $opportunity );
        $pageTitle = $this->pageTitle( $opportunity, $home, $siteName );
        $pageInfo  = $this->pageSummary( $profile, $pageUrl, $home );

        $firstName = Str::firstName( (string) ( $contact['name'] ?? '' ) );
        $greeting  = '' !== $firstName ? 'Hi ' . $firstName . ',' : 'Hello,';

        $brokenUrl    = $this->cleanLine( (string) ( $details['broken_url'] ?? '' ), 300 );
        $brokenAnchor = $this->cleanLine( (string) ( $details['anchor'] ?? $details['broken_anchor'] ?? '' ), 120 );
        $snippet      = $this->snippet( (string) ( $details['mention_snippet'] ?? $details['snippet'] ?? '' ), $brand );

        $matchContext = implode( ' ', array_filter( [
            $pageTitle,
            (string) ( $opportunity['title'] ?? '' ),
            implode( ' ', (array) ( $home['h1'] ?? [] ) ),
            (string) ( $home['description'] ?? '' ),
            (string) ( $profile['description'] ?? '' ),
            $brokenAnchor,
            $snippet,
            str_replace( [ '-', '_', '/' ], ' ', Url::path( $brokenUrl ) ),
        ] ) );
        $asset        = $this->asset( $approach, $details, $matchContext );
        $assetTitle   = $this->safeTitle( (string) $asset['title'] );
        $assetSummary = $this->cleanLine( (string) $asset['summary'], 220 );
        $assetUrl     = (string) $asset['url'];
        $whyRelevant  = $this->whyRelevant( $asset, $matchContext, $approach );

        $observation = $this->observation( $approach, [
            'site_name'     => $siteName,
            'page_title'    => $pageTitle,
            'page_url'      => $pageUrl,
            'domain'        => $domain,
            'brand'         => $brand,
            'broken_url'    => $brokenUrl,
            'broken_anchor' => $brokenAnchor,
            'broken_status' => (string) ( $details['status'] ?? '' ),
            'snippet'       => $snippet,
            'date'          => $this->monthYear( (string) ( $pageInfo['date_modified'] ?? $profile['last_content_date'] ?? '' ) ),
            'links'         => (int) ( $pageInfo['links_external'] ?? 0 ),
            'description'   => $this->cleanLine( (string) ( $profile['description'] ?? $home['description'] ?? '' ), 140 ),
            'has_submission' => ! empty( $prospect['submission_url'] ) || in_array( (string) ( $opportunity['type'] ?? '' ), [ 'directory_listing', 'supplier_listing', 'membership' ], true ),
            'has_opportunity' => null !== $opportunity,
        ] );

        $signature = $this->signature( $senderName, $brand, $homeUrl );
        $footer    = $this->render( self::FOOTER_TEMPLATE, [
            'brand'           => $brand,
            'postal_address'  => $postal,
            'unsubscribe_url' => $unsubscribeUrl,
        ] );

        return [
            'greeting'             => $greeting,
            'first_name'           => $firstName,
            'site_name'            => $siteName,
            'page_title'           => $pageTitle,
            'page_url'             => $pageUrl,
            'observation'          => $observation,
            'asset_title'          => $assetTitle,
            'asset_url'            => $assetUrl,
            'asset_summary'        => $assetSummary,
            'asset_kind'           => $this->assetKind( (string) ( $asset['kind'] ?? '' ) ),
            'why_relevant'         => $whyRelevant,
            'broken_url'           => $brokenUrl,
            'broken_anchor'        => $brokenAnchor,
            'mention_snippet'      => $snippet,
            'brand'                => $brand,
            'sender_name'          => '' !== $senderName ? $senderName : $brand,
            'signature'            => $signature,
            'compliance_footer'    => $footer,
            'unsubscribe_url'      => $unsubscribeUrl,
            'postal_address'       => $postal,
            'original_subject'     => '',
            'short_description'    => $this->shortDescriptionFallback( $brand ),
            'partnership_proposal' => $this->partnershipFallback( $brand, $siteName ),
            'research_note'        => $this->researchNote(),
            'home_url'             => $homeUrl,
            'domain'               => $domain,
            'city'                 => $city,
            'sender_email'         => $senderEmail,
        ];
    }

    /**
     * Replaces {{placeholders}}, strips unknown ones and tidies whitespace.
     *
     * @param array<string,mixed> $context
     */
    public function render( string $template, array $context ): string {
        $out = preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            static function ( array $m ) use ( $context ): string {
                $key = strtolower( $m[1] );
                return isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ? (string) $context[ $key ] : '';
            },
            $template
        ) ?? $template;
        $out = str_replace( [ "\r\n", "\r" ], "\n", $out );
        $out = preg_replace( '/[ \t]+$/m', '', $out ) ?? $out;          // trailing spaces per line
        $out = preg_replace( '/^[ \t]+(?=\S)/m', '', $out ) ?? $out;     // leading spaces left by empty placeholders
        $out = preg_replace( '/(?<=\S) {2,}(?=\S)/', ' ', $out ) ?? $out; // double spaces inside lines
        $out = preg_replace( '/ +([.,;:!?])(?=\s|$)/', '$1', $out ) ?? $out; // " ." artefacts
        $out = preg_replace( "/\n{3,}/", "\n\n", $out ) ?? $out;         // collapse blank lines
        return trim( $out );
    }

    /**
     * Optional AI rephrasing of the editable part of a draft. The signature and the compliance
     * footer are never sent to the model and are re-attached unchanged. Any doubt → original draft.
     *
     * @param array<string,string> $context
     */
    public function aiPersonalize( string $draft, array $context, ClaudeWriter $ai, ClaimsFilter $filter ): string {
        try {
            if ( ! $ai->isEnabled() ) {
                return $draft;
            }
            [ $editable, $tail ] = $this->splitTail( $draft, $context );
            if ( '' === $tail || '' === trim( $editable ) ) {
                return $draft;
            }
            $facts  = implode( "\n- ", array_slice( array_values( $this->facts->verified() ), 0, 12 ) );
            $system = 'You edit short, plain-text outreach emails sent by a small Canadian laboratory supplier. Rules: keep the same single request and the same meaning; keep the first line (the greeting) exactly as it is; keep every URL exactly as written and add no new URLs; add no facts, numbers, names, offers, discounts or claims; no medical, therapeutic, dosing, benefit, safety, approval or superlative language; research products are for in-vitro laboratory research use only and never for human or veterinary use; no flattery, no urgency, no pressure; at most 150 words; plain text only, no markdown, no subject line, no signature, no footer. Output only the edited email body.';
            $user   = 'Recipient website: ' . ( $context['site_name'] ?? '' ) . "\nPage: " . ( $context['page_title'] ?? '' ) . ' (' . ( $context['page_url'] ?? '' ) . ")\nObservation about their page (keep it factual): " . ( $context['observation'] ?? '' ) . "\n\nVerified facts you may rely on (do not add others):\n- " . $facts . "\n\nEmail body to make read more naturally for this specific recipient:\n\n" . trim( $editable );
            $out    = $ai->generate( $system, $user, 700 );
            if ( null === $out ) {
                return $draft;
            }
            $out = $this->cleanAiOutput( $out );
            if ( '' === $out || str_contains( $out, '{{' ) || str_contains( $out, '}}' ) ) {
                return $draft;
            }
            if ( ! $filter->isClean( $out ) ) {
                return $draft;
            }
            $words = Templates::wordCount( $out );
            if ( $words < 25 || $words > 170 ) {
                return $draft;
            }
            $firstDraft = trim( strtok( trim( $editable ), "\n" ) ?: '' );
            $firstOut   = trim( strtok( $out, "\n" ) ?: '' );
            if ( '' !== $firstDraft && $firstDraft !== $firstOut ) {
                return $draft;
            }
            $urlsIn  = $this->urls( $editable );
            $urlsOut = $this->urls( $out );
            foreach ( $urlsIn as $u ) {
                if ( ! in_array( $u, $urlsOut, true ) ) {
                    return $draft;
                }
            }
            foreach ( $urlsOut as $u ) {
                if ( ! in_array( $u, $urlsIn, true ) ) {
                    return $draft;
                }
            }
            return rtrim( $out ) . "\n\n" . $tail;
        } catch ( \Throwable $e ) {
            return $draft;
        }
    }

    // --- Context helpers ----------------------------------------------------

    private function brand(): string {
        $b = trim( (string) $this->settings->get( 'site.brand_name', '' ) );
        return '' !== $b ? $b : 'Pure Peptides Canada';
    }

    private function homeUrl(): string {
        $u = trim( (string) $this->settings->get( 'site.home_url', '' ) );
        if ( '' === $u && function_exists( 'home_url' ) ) {
            $u = (string) home_url( '/' );
        }
        return rtrim( $u, '/' );
    }

    /** Postal address from settings, else composed from the business profile. */
    public function postalAddress(): string {
        $configured = trim( (string) $this->settings->get( 'outreach.postal_address', '' ) );
        if ( '' !== $configured ) {
            return $this->cleanLine( $configured, 200 );
        }
        $parts = array_filter( array_map( 'trim', [
            (string) $this->settings->get( 'site.address', '' ),
            (string) $this->settings->get( 'site.city', '' ),
            trim( (string) $this->settings->get( 'site.region', '' ) . ' ' . (string) $this->settings->get( 'site.postal_code', '' ) ),
            (string) $this->settings->get( 'site.country', '' ),
        ] ), static fn( string $p ): bool => '' !== $p );
        return implode( ', ', $parts );
    }

    private function signature( string $senderName, string $brand, string $homeUrl ): string {
        $configured = trim( str_replace( [ "\r\n", "\r" ], "\n", (string) $this->settings->get( 'outreach.signature', '' ) ) );
        if ( '' !== $configured ) {
            return $configured;
        }
        return implode( "\n", array_filter( [ $senderName, $brand, $homeUrl ], static fn( string $p ): bool => '' !== $p ) );
    }

    private function researchNote(): string {
        $s = trim( $this->facts->statement( 'research_use_only' ) );
        if ( '' === $s ) {
            $s = 'All research products are sold strictly for in-vitro laboratory research use and are not for human or veterinary use.';
        }
        return preg_replace( '/^All research products/i', 'Our research products', $s ) ?? $s;
    }

    private function shortDescriptionFallback( string $brand ): string {
        $identity = trim( $this->facts->statement( 'identity' ) );
        if ( '' === $identity ) {
            $identity = $brand . ' is a Canadian-owned online supplier of research peptides and laboratory reagents.';
        }
        $reports = '' !== $this->facts->statement( 'lab_reports' ) ? ' Independent laboratory test reports are published by product and batch.' : '';
        return $identity . $reports;
    }

    private function partnershipFallback( string $brand, string $siteName ): string {
        return $brand . ' would welcome the chance to work with ' . $siteName . '. We can provide accurate company details, independent testing documentation and research-education resources, and we respect any editorial or membership requirements you have.';
    }

    /**
     * @param array<string,mixed> $prospect
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $home
     */
    private function siteName( array $prospect, array $profile, array $home, string $domain ): string {
        $candidates = [
            (string) ( $profile['name'] ?? '' ),
            (string) ( $home['site_name'] ?? '' ),
            (string) ( $prospect['name'] ?? '' ),
        ];
        foreach ( $candidates as $c ) {
            $c = $this->firstSegment( $this->cleanLine( $c, 90 ) );
            if ( '' !== $c && strtolower( $c ) !== $domain && ! filter_var( $c, FILTER_VALIDATE_URL ) ) {
                return $c;
            }
        }
        return '' !== $domain ? $domain : 'your site';
    }

    /**
     * @param array<string,mixed>      $prospect
     * @param array<string,mixed>|null $opportunity
     */
    private function pageUrl( array $prospect, ?array $opportunity ): string {
        $url = trim( (string) ( $opportunity['url'] ?? '' ) );
        if ( '' === $url ) {
            $url = trim( (string) ( $prospect['homepage_url'] ?? '' ) );
        }
        if ( '' === $url && ! empty( $prospect['domain'] ) ) {
            $url = 'https://' . $prospect['domain'] . '/';
        }
        return $url;
    }

    /**
     * @param array<string,mixed>|null $opportunity
     * @param array<string,mixed>      $home
     */
    private function pageTitle( ?array $opportunity, array $home, string $siteName ): string {
        foreach ( [ (string) ( $opportunity['title'] ?? '' ), (string) ( $home['title'] ?? '' ), (string) ( ( $home['h1'] ?? [] )[0] ?? '' ) ] as $t ) {
            $t = $this->cleanLine( $t, self::MAX_TITLE );
            if ( '' !== $t && ! filter_var( $t, FILTER_VALIDATE_URL ) ) {
                return $t;
            }
        }
        return $siteName;
    }

    /**
     * Summary of the page the message is about (from the stored profile), when known.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $home
     * @return array<string,mixed>
     */
    private function pageSummary( array $profile, string $pageUrl, array $home ): array {
        $norm = Url::normalize( $pageUrl );
        if ( '' !== $norm && ! empty( $home['url'] ) && Url::normalize( (string) $home['url'] ) === $norm ) {
            return $home;
        }
        foreach ( (array) ( $profile['pages'] ?? [] ) as $p ) {
            if ( is_array( $p ) && ! empty( $p['url'] ) && Url::normalize( (string) $p['url'] ) === $norm ) {
                return $p;
            }
        }
        return [];
    }

    /**
     * Picks the linkable asset for the approach / opportunity.
     *
     * @param array<string,mixed> $details
     * @return array{url:string,title:string,summary:string,topics:string[],kind:string}
     */
    private function asset( string $approach, array $details, string $matchContext ): array {
        if ( 'broken_link' === $approach && ! empty( $details['suggested_url'] ) ) {
            $assetFromDetails = [
                'url'     => (string) $details['suggested_url'],
                'title'   => (string) ( $details['suggested_title'] ?? '' ),
                'summary' => (string) ( $details['suggested_summary'] ?? '' ),
                'topics'  => [],
                'kind'    => 'guide',
            ];
            // Prefer the registry entry (it carries topics and a maintained summary).
            foreach ( $this->assets->all() as $a ) {
                if ( Url::normalize( $a['url'] ) === Url::normalize( $assetFromDetails['url'] ) ) {
                    return $a;
                }
            }
            if ( '' !== $assetFromDetails['title'] ) {
                return $assetFromDetails;
            }
        }
        return match ( $approach ) {
            'directory_inclusion', 'unlinked_mention', 'partnership' => $this->assets->home(),
            'editorial_mention' => $this->assets->bestFor( $matchContext, 'resource' ),
            default => $this->assets->bestFor( $matchContext, 'guide' ),
        };
    }

    /**
     * @param array{url:string,title:string,summary:string,topics:string[],kind:string} $asset
     */
    private function whyRelevant( array $asset, string $matchContext, string $approach ): string {
        if ( in_array( $approach, [ 'broken_link', 'unlinked_mention', 'directory_inclusion', 'partnership' ], true ) ) {
            return '';
        }
        $matched = [];
        $ctx     = mb_strtolower( $matchContext );
        foreach ( (array) ( $asset['topics'] ?? [] ) as $topic ) {
            $topic = trim( (string) $topic );
            if ( '' !== $topic && Str::countPhrase( $ctx, $topic ) > 0 && ! in_array( $topic, [ 'canada', 'about', 'company', 'info', 'supplier' ], true ) ) {
                $display = $this->displayTopic( $topic );
                if ( ! in_array( $display, array_map( [ $this, 'displayTopic' ], $matched ), true ) ) {
                    $matched[] = $topic;
                }
            }
            if ( count( $matched ) >= 2 ) {
                break;
            }
        }
        if ( ! empty( $matched ) ) {
            return 'It is a non-commercial explainer on ' . $this->joinNatural( array_map( [ $this, 'displayTopic' ], $matched ) ) . ', which seemed close to what your page covers.';
        }
        return 'It is written as a reference for laboratory staff and students rather than as a sales page, which I hope fits the kind of material your page collects.';
    }

    /** Topic keyword → readable phrase (acronyms upper-cased). */
    private function displayTopic( string $topic ): string {
        $map = [
            'coa'                     => 'certificates of analysis',
            'certificate of analysis' => 'certificates of analysis',
            'hplc'                    => 'HPLC testing',
            'lab practice'            => 'laboratory practice',
            'freeze-thaw'             => 'freeze-thaw handling',
        ];
        $t = mb_strtolower( trim( $topic ) );
        return $map[ $t ] ?? $t;
    }

    /** Plain description of what kind of page an asset is, for use inside a sentence. */
    private function assetKind( string $kind ): string {
        return match ( $kind ) {
            'guide'    => 'plain-English guide',
            'resource' => 'reference page',
            'home'     => 'website',
            default    => 'page',
        };
    }

    /**
     * One factual sentence (or two) about the recipient's page, per approach.
     *
     * @param array<string,mixed> $d
     */
    private function observation( string $approach, array $d ): string {
        $site   = (string) $d['site_name'];
        $title  = (string) $d['page_title'];
        $url    = (string) $d['page_url'];
        $date   = (string) $d['date'];
        $quoted = '' !== $title && $title !== $site ? '"' . $title . '"' : '';

        switch ( $approach ) {
            case 'resource_page':
                $s = '' !== $quoted
                    ? 'I came across ' . $quoted . ' on ' . $site . ' while looking for reference lists used by laboratory staff and students in Canada'
                    : 'I came across ' . $site . ' while looking for reference lists used by laboratory staff and students in Canada';
                $s .= '' !== $date ? ', and I see it was last updated in ' . $date . '.' : '.';
                if ( (int) $d['links'] >= 8 ) {
                    $s .= ' It currently links out to ' . (int) $d['links'] . ' external resources, which is a substantial collection.';
                }
                return $s;

            case 'directory_inclusion':
                $s = 'I found ' . $site . ' while looking for directories that list laboratory and scientific suppliers serving Canada.';
                if ( ! empty( $d['has_submission'] ) && '' !== $quoted ) {
                    $s .= ' Your ' . $quoted . ' page suggests you accept new listings.';
                }
                return $s;

            case 'partnership':
                $s = $site . ' came up while I was looking at organisations that work with research laboratories and suppliers in Canada.';
                if ( '' !== $quoted && ! empty( $d['has_opportunity'] ) ) {
                    $s .= ' Your ' . $quoted . ' page in particular caught my attention.';
                }
                return $s;

            case 'expert_commentary':
            case 'editorial_mention':
                if ( '' !== $quoted ) {
                    return 'I read ' . $quoted . ' on ' . $site . ( '' !== $date ? ', published ' . $date . ',' : '' ) . ' and thought it handled the subject clearly.';
                }
                return 'I have been reading ' . $site . ' and wanted to introduce myself briefly.';

            case 'educational_contribution':
                $s = '' !== $quoted ? 'I was reading ' . $quoted . ' on ' . $site . '.' : 'I was reading ' . $site . '.';
                if ( '' !== (string) $d['description'] ) {
                    $s .= ' Your site describes itself as "' . rtrim( (string) $d['description'], '.' ) . '", which is close to the audience our guides were written for.';
                } else {
                    $s .= ' It looks like it serves students and laboratory staff, which is the audience our guides were written for.';
                }
                return $s;

            case 'broken_link':
                $where  = '' !== $quoted ? $quoted . ( '' !== $url ? ' (' . $url . ')' : '' ) : ( '' !== $url ? $url : 'your page' );
                $link   = '' !== (string) $d['broken_anchor'] ? 'the link "' . $d['broken_anchor'] . '" pointing to ' . $d['broken_url'] : 'the link to ' . $d['broken_url'];
                $status = (int) $d['broken_status'];
                $why    = $status >= 400 ? ' (HTTP ' . $status . ')' : '';
                if ( '' === (string) $d['broken_url'] ) {
                    return 'On ' . $where . ', one of the outbound links currently returns an error.';
                }
                return 'On ' . $where . ', ' . $link . ' currently returns an error' . $why . '.';

            case 'unlinked_mention':
                $s = 'In ' . ( '' !== $quoted ? $quoted : 'a recent page' ) . ( '' !== $url ? ' (' . $url . ')' : '' ) . ' you mention ' . $d['brand'];
                return '' !== (string) $d['snippet'] ? $s . ': "' . $d['snippet'] . '".' : $s . '.';
        }
        return '' !== $quoted ? 'I came across ' . $quoted . ' on ' . $site . '.' : 'I came across ' . $site . '.';
    }

    // --- Text helpers -------------------------------------------------------

    /** Neutralises superlatives in a page title so it can be quoted in a claim-free email. */
    private function safeTitle( string $title ): string {
        $t = $this->cleanLine( $title, self::MAX_TITLE );
        $t = preg_replace( '/\bthe best\b/i', 'a', $t ) ?? $t;
        $t = preg_replace( '/\b(best|top[\s\-]?rated|world[\s\-]?class|industry[\s\-]?leading|highest[\s\-]quality|#\s?1|number one)\s*/i', '', $t ) ?? $t;
        return trim( preg_replace( '/\s{2,}/', ' ', $t ) ?? $t );
    }

    private function snippet( string $text, string $brand ): string {
        $text = $this->cleanLine( $text, 1000 );
        if ( '' === $text ) {
            return '';
        }
        if ( mb_strlen( $text ) > self::MAX_SNIPPET ) {
            $pos   = mb_stripos( $text, $brand );
            $start = false !== $pos ? max( 0, $pos - (int) ( self::MAX_SNIPPET / 2 ) ) : 0;
            $text  = ( $start > 0 ? '…' : '' ) . trim( mb_substr( $text, $start, self::MAX_SNIPPET ) ) . '…';
        }
        return rtrim( str_replace( '"', "'", $text ), '. ' );
    }

    private function cleanLine( string $text, int $max ): string {
        $text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $text, true ) : strip_tags( $text );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? $text );
        return mb_strlen( $text ) > $max ? Str::truncate( $text, $max ) : $text;
    }

    /** "Site Name | Tagline" → "Site Name". */
    private function firstSegment( string $text ): string {
        $parts = preg_split( '/\s+[|–—\-»]\s+/u', $text ) ?: [ $text ];
        $first = trim( (string) ( $parts[0] ?? '' ) );
        return mb_strlen( $first ) >= 3 ? $first : trim( $text );
    }

    private function monthYear( string $date ): string {
        $date = trim( $date );
        if ( '' === $date ) {
            return '';
        }
        $ts = strtotime( $date );
        if ( false === $ts || (int) gmdate( 'Y', $ts ) < 2000 || $ts > time() + DAY_IN_SECONDS ) {
            return '';
        }
        return gmdate( 'F Y', $ts );
    }

    /** @param string[] $items */
    private function joinNatural( array $items ): string {
        $items = array_values( array_unique( array_map( 'strval', $items ) ) );
        $n     = count( $items );
        if ( 0 === $n ) {
            return '';
        }
        if ( 1 === $n ) {
            return $items[0];
        }
        $last = array_pop( $items );
        return implode( ', ', $items ) . ' and ' . $last;
    }

    /**
     * Splits a rendered draft into [editable, tail] where tail = signature + compliance footer.
     *
     * @param array<string,string> $context
     * @return array{0:string,1:string}
     */
    private function splitTail( string $draft, array $context ): array {
        $footer = trim( (string) ( $context['compliance_footer'] ?? '' ) );
        if ( '' === $footer ) {
            return [ $draft, '' ];
        }
        $pos = strrpos( $draft, $footer );
        if ( false === $pos ) {
            return [ $draft, '' ];
        }
        $cut       = $pos;
        $signature = trim( (string) ( $context['signature'] ?? '' ) );
        if ( '' !== $signature ) {
            $sigPos = strrpos( substr( $draft, 0, $pos ), $signature );
            if ( false !== $sigPos ) {
                $cut = $sigPos;
            }
        }
        return [ rtrim( substr( $draft, 0, $cut ) ), trim( substr( $draft, $cut ) ) ];
    }

    private function cleanAiOutput( string $out ): string {
        $out = str_replace( [ "\r\n", "\r" ], "\n", trim( $out ) );
        $out = preg_replace( '/^```[a-z]*\s*|\s*```$/i', '', $out ) ?? $out;
        $out = preg_replace( '/^(subject|body)\s*:.*$/im', '', $out ) ?? $out;
        $out = preg_replace( "/\n{3,}/", "\n\n", $out ) ?? $out;
        return trim( $out );
    }

    /** @return string[] */
    private function urls( string $text ): array {
        preg_match_all( '#https?://[^\s<>"\')\]]+#i', $text, $m );
        return array_values( array_unique( array_map( static fn( string $u ): string => rtrim( $u, '.,;:' ), $m[0] ) ) );
    }
}
