<?php
/**
 * Outreach email templates: eight approaches, at least two variants each, plus one follow-up.
 *
 * Every body is plain text, concise (under 170 words), personalised through placeholders,
 * makes one clear ask, contains no product or health claims, and ends with the signature
 * and the mandatory CASL / CAN-SPAM compliance footer.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Outreach;

final class Templates {

    public const FOLLOW_UP_ID = 'followup-1';

    /** @var array<string,string> approach id => label */
    private const APPROACHES = [
        'resource_page'            => 'Resource page inclusion',
        'directory_inclusion'      => 'Business directory inclusion',
        'partnership'              => 'Industry partnership',
        'expert_commentary'        => 'Expert commentary',
        'educational_contribution' => 'Educational contribution',
        'editorial_mention'        => 'Editorial mention',
        'broken_link'              => 'Broken-link replacement',
        'unlinked_mention'         => 'Unlinked brand mention',
    ];

    /** @var array<string,string> approach id => short description for the campaign form */
    private const DESCRIPTIONS = [
        'resource_page'            => 'Ask a curated resource / links page to add one of our non-commercial laboratory guides.',
        'directory_inclusion'      => 'Ask a business or supplier directory how to be listed, offering verified company details.',
        'partnership'              => 'Open a conversation with an association, network or organisation about membership or collaboration.',
        'expert_commentary'        => 'Offer a supplier-side source for journalists and editors covering laboratory documentation and testing.',
        'educational_contribution' => 'Offer a free educational guide (or an original contributed explainer) to an educational site.',
        'editorial_mention'        => 'Follow up on a relevant article with context and a reference the author may use in future coverage.',
        'broken_link'              => 'Report a dead outbound link on their page and suggest a working replacement on the same subject.',
        'unlinked_mention'         => 'Thank a site that already mentions the brand and ask for the mention to link to the site.',
    ];

    /** @var string[] placeholders every template may use (all supplied by Personalizer::context) */
    private const PLACEHOLDERS = [
        'greeting',
        'first_name',
        'site_name',
        'page_title',
        'page_url',
        'observation',
        'asset_title',
        'asset_url',
        'asset_summary',
        'why_relevant',
        'broken_url',
        'broken_anchor',
        'mention_snippet',
        'brand',
        'sender_name',
        'signature',
        'compliance_footer',
        'unsubscribe_url',
        'postal_address',
        'original_subject',
        // Extras (also supplied by the Personalizer; the engine may override with ContentGenerator output).
        'short_description',
        'partnership_proposal',
        'research_note',
        'home_url',
        'domain',
        'city',
        'sender_email',
    ];

    /** @return array<string,string> approach id => label */
    public static function approaches(): array {
        return self::APPROACHES;
    }

    /** @return array<string,string> approach id => description */
    public static function descriptions(): array {
        return self::DESCRIPTIONS;
    }

    public static function isApproach( string $approach ): bool {
        return array_key_exists( $approach, self::APPROACHES );
    }

    public static function label( string $approach ): string {
        return self::APPROACHES[ $approach ] ?? $approach;
    }

    /** @return string[] */
    public static function placeholders(): array {
        return self::PLACEHOLDERS;
    }

    /**
     * Template variants for an approach (at least two). Unknown approaches return an empty list.
     *
     * @return array<int,array{id:string,subject:string,body:string}>
     */
    public static function variants( string $approach ): array {
        $all = self::library();
        return $all[ $approach ] ?? [];
    }

    /**
     * A specific variant by id, or null.
     *
     * @return array{id:string,subject:string,body:string}|null
     */
    public static function variant( string $approach, string $id ): ?array {
        foreach ( self::variants( $approach ) as $v ) {
            if ( $v['id'] === $id ) {
                return $v;
            }
        }
        return null;
    }

    /** @return array{id:string,subject:string,body:string} */
    public static function followUp(): array {
        return [
            'id'      => self::FOLLOW_UP_ID,
            'subject' => 'Following up: {{original_subject}}',
            'body'    => <<<'TXT'
{{greeting}}

A short follow-up on my earlier note about {{page_title}}. Inboxes fill up quickly, so I wanted to make sure it did not get buried rather than assume a no.

If it is not a fit, a one-word reply is genuinely welcome and I will not write again. If it is, I am happy to send anything else you need.

{{signature}}

{{compliance_footer}}
TXT,
        ];
    }

    /**
     * Word count of a template body (placeholders count as one word each).
     */
    public static function wordCount( string $body ): int {
        $words = preg_split( '/\s+/u', trim( $body ) ) ?: [];
        return count( array_filter( $words, static fn( string $w ): bool => '' !== $w ) );
    }

    /**
     * The full template library.
     *
     * @return array<string,array<int,array{id:string,subject:string,body:string}>>
     */
    private static function library(): array {
        return [
            'resource_page'            => [
                [
                    'id'      => 'rp-a',
                    'subject' => 'A Canadian lab reference for {{page_title}}',
                    'body'    => <<<'TXT'
{{greeting}}

{{observation}}

I work with {{brand}}, a Canadian-owned supplier of research peptides and laboratory reagents in {{city}}. We publish a small set of non-commercial laboratory guides, and one of them may suit your list:

{{asset_title}}
{{asset_url}}
{{asset_summary}}

{{why_relevant}}

If it fits, I would be grateful if you would consider adding it. If not, no reply is needed, and thank you for maintaining the page.

{{signature}}

{{compliance_footer}}
TXT,
                ],
                [
                    'id'      => 'rp-b',
                    'subject' => 'Suggestion for your {{page_title}} page',
                    'body'    => <<<'TXT'
{{greeting}}

{{observation}}

Would you consider one more entry? {{asset_title}} ({{asset_url}}) is a plain-English guide we wrote for laboratory staff and students: {{asset_summary}}

{{why_relevant}}

It is free, has no sign-up wall and makes no product claims, so I hope it is a reasonable fit. Either way, thanks for keeping that page up.

{{signature}}

{{compliance_footer}}
TXT,
                ],
            ],
            'directory_inclusion'      => [
                [
                    'id'      => 'di-a',
                    'subject' => 'Listing request: {{brand}}, Canadian laboratory supplier',
                    'body'    => <<<'TXT'
{{greeting}}

{{observation}}

Could you let me know how to be listed on {{site_name}}, or whether we qualify? Here is the short version:

{{short_description}}

Website: {{home_url}}
Suggested category: laboratory supplies / scientific research supplier

I am happy to complete a submission form or provide any further company details you need. {{research_note}}

Thank you for your time.

{{signature}}

{{compliance_footer}}
TXT,
                ],
                [
                    'id'      => 'di-b',
                    'subject' => 'Would {{brand}} be a fit for {{site_name}}?',
                    'body'    => <<<'TXT'
{{greeting}}

{{observation}}

I would like to ask whether {{brand}} would be a fit for your directory. {{short_description}}

Our website is {{home_url}}. If there is a submission form or a set of listing criteria, please point me to it and I will follow it exactly. {{research_note}}

Thanks for considering it.

{{signature}}

{{compliance_footer}}
TXT,
                ],
            ],
            'partnership'              => [
                [
                    'id'      => 'pa-a',
                    'subject' => 'Working with {{site_name}}: a note from {{brand}}',
                    'body'    => <<<'TXT'
{{greeting}}

{{observation}}

{{partnership_proposal}}

Concretely, we could contribute a supplier profile, examples of batch-level laboratory documentation, or a short educational piece for your members, whichever is useful. {{research_note}}

Would a brief call or an email exchange make sense? If this is not something {{site_name}} does, a one-line reply saying so is completely fine.

{{signature}}

{{compliance_footer}}
TXT,
                ],
                [
                    'id'      => 'pa-b',
                    'subject' => 'Membership or partnership enquiry from {{brand}}',
                    'body'    => <<<'TXT'
{{greeting}}

{{observation}}

I am writing from {{brand}}, a Canadian-owned supplier of research peptides and laboratory reagents based in {{city}}. I would like to understand whether {{site_name}} offers membership, supplier listings or partnerships for companies like ours, and what the requirements are.

{{partnership_proposal}}

If you can share the process or the right person to talk to, I would appreciate it. {{research_note}}

{{signature}}

{{compliance_footer}}
TXT,
                ],
            ],
            'expert_commentary'        => [
                [
                    'id'      => 'ec-a',
                    'subject' => 'A Canadian supplier perspective for {{site_name}}',
                    'body'    => <<<'TXT'
{{greeting}}

{{observation}}

If you ever cover laboratory procurement, certificates of analysis, HPLC purity testing or how Canadian labs source research reagents, I would be glad to answer questions or provide a short quote. I work with {{brand}}, a Canadian-owned supplier of research peptides and laboratory reagents, and we publish independent per-batch test reports, so I can speak to documentation and verification from the supplier side. {{research_note}}

No obligation at all. If it is useful, keep my details on file; if not, thanks for reading.

{{signature}}

{{compliance_footer}}
TXT,
                ],
                [
                    'id'      => 'ec-b',
                    'subject' => 'Available as a source on laboratory documentation and testing',
                    'body'    => <<<'TXT'
{{greeting}}

{{observation}}

I am offering myself as a background source rather than pitching a story. Topics I can speak to with specifics: reading a certificate of analysis, what HPLC and mass-spectrometry reports do and do not show, and the practical side of supplying research peptides to Canadian laboratories. {{research_note}}

One reference you may find useful in the meantime: {{asset_title}}, {{asset_url}}.

Happy to help with a quote or a fact check whenever it is relevant.

{{signature}}

{{compliance_footer}}
TXT,
                ],
            ],
            'educational_contribution' => [
                [
                    'id'      => 'ed-a',
                    'subject' => 'A free laboratory guide for your {{page_title}} readers',
                    'body'    => <<<'TXT'
{{greeting}}

{{observation}}

We maintain a Research Library of non-commercial laboratory guides written for students, lab staff and researchers. One that may suit your audience:

{{asset_title}}
{{asset_url}}
{{asset_summary}}

{{why_relevant}}

If you would prefer an original piece instead, I can write a short, referenced explainer on a related topic for {{site_name}}, with no product promotion. {{research_note}}

Thanks for the work you put into the site.

{{signature}}

{{compliance_footer}}
TXT,
                ],
                [
                    'id'      => 'ed-b',
                    'subject' => 'Educational resource suggestion for {{site_name}}',
                    'body'    => <<<'TXT'
{{greeting}}

{{observation}}

Would {{asset_title}} be useful to the people you teach or support? It is a free, plain-English guide with no sign-up wall that explains the subject without selling anything: {{asset_summary}}

{{asset_url}}

{{why_relevant}}

If there is a better format for your audience, such as a handout or a short contributed article, I am happy to put one together. {{research_note}}

{{signature}}

{{compliance_footer}}
TXT,
                ],
            ],
            'editorial_mention'        => [
                [
                    'id'      => 'em-a',
                    'subject' => 'About your article: {{page_title}}',
                    'body'    => <<<'TXT'
{{greeting}}

{{observation}}

If you ever revisit the topic or write a follow-up, one thing that might add context for readers is how Canadian laboratories verify what they buy: independent per-batch certificates of analysis with verification keys that can be checked on the testing laboratory's own website. We publish ours openly at {{brand}}, and I would be glad to walk you through examples or answer questions.

A reference that may help: {{asset_title}}, {{asset_url}}. {{research_note}}

No pressure either way. Thanks for the piece.

{{signature}}

{{compliance_footer}}
TXT,
                ],
                [
                    'id'      => 'em-b',
                    'subject' => 'A Canadian angle on {{page_title}}',
                    'body'    => <<<'TXT'
{{greeting}}

{{observation}}

I am writing from {{brand}}, a Canadian-owned supplier of research peptides and laboratory reagents. If a Canadian supplier's perspective, or a worked example of reading a certificate of analysis, would be useful for a future piece, I am available for questions or a quote. {{research_note}}

In case it helps now: {{asset_title}}, {{asset_url}}.

Thanks for your time, and for covering the subject carefully.

{{signature}}

{{compliance_footer}}
TXT,
                ],
            ],
            'broken_link'              => [
                [
                    'id'      => 'bl-a',
                    'subject' => 'Broken link on {{page_title}}',
                    'body'    => <<<'TXT'
{{greeting}}

{{observation}}

I thought you would want to know, since it is an easy fix. If you are looking for a replacement on the same subject, one option is {{asset_title}}, {{asset_url}}: {{asset_summary}} It is free and non-commercial, though of course any current source that fits is fine.

Thanks for maintaining the page.

{{signature}}

{{compliance_footer}}
TXT,
                ],
                [
                    'id'      => 'bl-b',
                    'subject' => 'Dead link spotted on {{site_name}}',
                    'body'    => <<<'TXT'
{{greeting}}

{{observation}}

No action is needed on my account; I just did not want it to sit there unnoticed. If a replacement on the same subject would help, I can suggest {{asset_title}} ({{asset_url}}), a plain-English guide we wrote for laboratory staff: {{asset_summary}}

Either way, thanks for the page.

{{signature}}

{{compliance_footer}}
TXT,
                ],
            ],
            'unlinked_mention'         => [
                [
                    'id'      => 'um-a',
                    'subject' => 'Thank you for mentioning {{brand}}',
                    'body'    => <<<'TXT'
{{greeting}}

{{observation}}

Thank you for the mention. One small request: would you consider linking the name to {{asset_url}} so readers can find us directly? It helps people land on the right page, and it asks nothing else of you.

If you would like any detail verified for the piece, such as our location, testing documentation or shipping, I am happy to confirm it. {{research_note}}

{{signature}}

{{compliance_footer}}
TXT,
                ],
                [
                    'id'      => 'um-b',
                    'subject' => 'Small request about your mention of {{brand}}',
                    'body'    => <<<'TXT'
{{greeting}}

{{observation}}

I appreciate you including us. If it is easy, could the mention link to our site at {{asset_url}}? Readers sometimes end up at unrelated results otherwise. If a link is not possible, no problem at all.

I am also glad to fact-check anything about {{brand}} for you. {{research_note}}

{{signature}}

{{compliance_footer}}
TXT,
                ],
            ],
        ];
    }
}
