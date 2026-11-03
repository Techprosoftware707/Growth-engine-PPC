<?php
/**
 * Finds HTML forms and classifies them (listing submission, contact, login, search…).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Analysis;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PPC\LinkGrowth\Support\Url;

final class FormDetector {

    public const KIND_LISTING    = 'listing_submission';
    public const KIND_CONTACT    = 'contact';
    public const KIND_NEWSLETTER = 'newsletter';
    public const KIND_SEARCH     = 'search';
    public const KIND_LOGIN      = 'login';
    public const KIND_REGISTER   = 'register';
    public const KIND_COMMENT    = 'comment';
    public const KIND_OTHER      = 'other';

    /** Semantic field detection: semantic key => regex on name|id|label|placeholder. */
    private const SEMANTICS = [
        'website'      => '/\b(website|web_?site|url|site_?url|homepage|web ?address|domain|link)\b/i',
        'email'        => '/\b(e-?mail)\b/i',
        'business_name' => '/\b(business|company|organi[sz]ation|listing|org|store|firm|brand)[ _\-]?(name|title)?\b|\b(name of (your )?(business|company))\b|^(title|name)$/i',
        'contact_name' => '/\b(your|contact|first|last|full)[ _\-]?name\b|\b(first|last)name\b|\bfname\b|\blname\b/i',
        'phone'        => '/\b(phone|tel|telephone|mobile)\b/i',
        'description'  => '/\b(description|about|summary|overview|bio|details|profile)\b/i',
        'message'      => '/\b(message|comments?|enquiry|inquiry|question|body)\b/i',
        'category'     => '/\b(categor(y|ies)|industry|sector|type|classification|listing_?type)\b/i',
        'address'      => '/\b(address|street|address_?1|addr)\b/i',
        'city'         => '/\b(city|town|locality)\b/i',
        'region'       => '/\b(province|state|region)\b/i',
        'postal_code'  => '/\b(postal|postcode|zip)\b/i',
        'country'      => '/\b(country)\b/i',
        'keywords'     => '/\b(keywords?|tags?)\b/i',
        'logo'         => '/\b(logo|image|photo|upload)\b/i',
        'tagline'      => '/\b(tagline|slogan|short[ _]?description|excerpt)\b/i',
        'founded'      => '/\b(founded|established|year)\b/i',
        'social'       => '/\b(facebook|twitter|linkedin|instagram|social)\b/i',
        'password'     => '/\b(password|passwd|pass)\b/i',
        'search'       => '/^(s|q|query|search|keyword)$/i',
        'subject'      => '/\b(subject)\b/i',
        'captcha'      => '/captcha|turnstile/i',
        'consent'      => '/\b(consent|agree|terms|privacy|gdpr|newsletter|subscribe)\b/i',
    ];

    /**
     * @return array<int,array<string,mixed>>
     */
    public function detect( DOMDocument $dom, DOMXPath $xp, string $baseUrl ): array {
        $forms = [];
        foreach ( $xp->query( '//form' ) as $form ) {
            if ( ! $form instanceof DOMElement ) {
                continue;
            }
            $fields      = [];
            $hasPassword = false;
            $hasCaptcha  = false;
            foreach ( $xp->query( './/input|.//select|.//textarea', $form ) as $el ) {
                if ( ! $el instanceof DOMElement ) {
                    continue;
                }
                $tag  = strtolower( $el->tagName );
                $type = strtolower( $el->getAttribute( 'type' ) ?: ( 'textarea' === $tag ? 'textarea' : ( 'select' === $tag ? 'select' : 'text' ) ) );
                if ( in_array( $type, [ 'submit', 'button', 'image', 'reset' ], true ) ) {
                    continue;
                }
                $name = $el->getAttribute( 'name' );
                $id   = $el->getAttribute( 'id' );
                if ( '' === $name && '' === $id ) {
                    continue;
                }
                if ( 'password' === $type ) {
                    $hasPassword = true;
                }
                $label = $this->labelFor( $xp, $el, $id );
                $field = [
                    'name'        => $name,
                    'id'          => $id,
                    'type'        => $type,
                    'label'       => $label,
                    'placeholder' => $el->getAttribute( 'placeholder' ),
                    'required'    => $el->hasAttribute( 'required' ) || 'true' === $el->getAttribute( 'aria-required' ),
                    'maxlength'   => (int) $el->getAttribute( 'maxlength' ),
                    'value'       => 'hidden' === $type ? mb_substr( $el->getAttribute( 'value' ), 0, 500 ) : '',
                    'options'     => [],
                ];
                if ( 'select' === $tag ) {
                    foreach ( $xp->query( './/option', $el ) as $opt ) {
                        if ( $opt instanceof DOMElement && count( $field['options'] ) < 300 ) {
                            $field['options'][] = [ 'value' => $opt->getAttribute( 'value' ), 'text' => trim( $opt->textContent ) ];
                        }
                    }
                }
                $field['semantic'] = $this->semantic( $field );
                if ( 'captcha' === $field['semantic'] ) {
                    $hasCaptcha = true;
                }
                $fields[] = $field;
                if ( count( $fields ) >= 80 ) {
                    break;
                }
            }
            $formHtml = $dom->saveHTML( $form ) ?: '';
            if ( preg_match( '/recaptcha|hcaptcha|turnstile|g-recaptcha|h-captcha|data-sitekey|class="[^"]*captcha/i', $formHtml ) ) {
                $hasCaptcha = true;
            }
            $action = Url::absolutize( $form->getAttribute( 'action' ), $baseUrl ) ?: Url::normalize( $baseUrl );
            $forms[] = [
                'action'       => $action,
                'method'       => strtoupper( $form->getAttribute( 'method' ) ?: 'GET' ),
                'enctype'      => strtolower( $form->getAttribute( 'enctype' ) ),
                'id'           => $form->getAttribute( 'id' ),
                'fields'       => $fields,
                'has_password' => $hasPassword,
                'has_captcha'  => $hasCaptcha,
                'has_file'     => (bool) array_filter( $fields, static fn( $f ) => 'file' === $f['type'] ),
                'kind'         => $this->classify( $fields, $hasPassword, $form ),
            ];
            if ( count( $forms ) >= 15 ) {
                break;
            }
        }
        return $forms;
    }

    /** @param array<string,mixed> $field */
    public function semantic( array $field ): string {
        $hay = strtolower( trim( ( $field['name'] ?? '' ) . ' ' . ( $field['id'] ?? '' ) . ' ' . ( $field['label'] ?? '' ) . ' ' . ( $field['placeholder'] ?? '' ) ) );
        $type = $field['type'] ?? 'text';
        if ( 'password' === $type ) {
            return 'password';
        }
        if ( 'email' === $type ) {
            return 'email';
        }
        if ( 'url' === $type ) {
            return 'website';
        }
        if ( 'tel' === $type ) {
            return 'phone';
        }
        if ( 'file' === $type ) {
            return 'logo';
        }
        if ( 'hidden' === $type ) {
            return 'hidden';
        }
        if ( in_array( $type, [ 'checkbox', 'radio' ], true ) && preg_match( self::SEMANTICS['consent'], $hay ) ) {
            return 'consent';
        }
        $order = [ 'captcha', 'search', 'website', 'email', 'phone', 'postal_code', 'country', 'city', 'region', 'address', 'category', 'tagline', 'description', 'message', 'subject', 'keywords', 'logo', 'founded', 'social', 'contact_name', 'business_name', 'password', 'consent' ];
        foreach ( $order as $key ) {
            if ( preg_match( self::SEMANTICS[ $key ], $hay ) ) {
                if ( 'business_name' === $key && preg_match( '/\b(first|last|your|contact)\b/', $hay ) ) {
                    return 'contact_name';
                }
                return $key;
            }
        }
        if ( 'textarea' === $type ) {
            return 'description';
        }
        return 'unknown';
    }

    private function labelFor( DOMXPath $xp, DOMElement $el, string $id ): string {
        if ( '' !== $id ) {
            $lab = $xp->query( '//label[@for="' . addslashes( $id ) . '"]' )->item( 0 );
            if ( $lab ) {
                return trim( preg_replace( '/\s+/', ' ', $lab->textContent ) ?? '' );
            }
        }
        $p = $el->parentNode;
        $i = 0;
        while ( $p instanceof DOMElement && $i++ < 3 ) {
            if ( 'label' === strtolower( $p->tagName ) ) {
                return trim( preg_replace( '/\s+/', ' ', $p->textContent ) ?? '' );
            }
            $p = $p->parentNode;
        }
        $aria = $el->getAttribute( 'aria-label' );
        return '' !== $aria ? $aria : '';
    }

    /** @param array<int,array<string,mixed>> $fields */
    private function classify( array $fields, bool $hasPassword, DOMElement $form ): string {
        $sem = array_column( $fields, 'semantic' );
        $cnt = array_count_values( $sem );
        $visible = array_filter( $fields, static fn( $f ) => 'hidden' !== $f['type'] );
        if ( $hasPassword ) {
            return ( isset( $cnt['email'] ) && count( $visible ) > 3 ) ? self::KIND_REGISTER : self::KIND_LOGIN;
        }
        if ( isset( $cnt['search'] ) && count( $visible ) <= 2 ) {
            return self::KIND_SEARCH;
        }
        $attrs = strtolower( $form->getAttribute( 'id' ) . ' ' . $form->getAttribute( 'class' ) . ' ' . $form->getAttribute( 'action' ) );
        if ( str_contains( $attrs, 'commentform' ) || str_contains( $attrs, 'wp-comments-post' ) ) {
            return self::KIND_COMMENT;
        }
        if ( isset( $cnt['website'] ) && ( isset( $cnt['business_name'] ) || isset( $cnt['description'] ) || isset( $cnt['category'] ) ) ) {
            return self::KIND_LISTING;
        }
        if ( preg_match( '/listing|submit|add-?business|directory|register-?business/', $attrs ) && isset( $cnt['website'] ) ) {
            return self::KIND_LISTING;
        }
        if ( isset( $cnt['email'] ) && count( $visible ) <= 2 ) {
            return self::KIND_NEWSLETTER;
        }
        if ( isset( $cnt['email'] ) && ( isset( $cnt['message'] ) || isset( $cnt['description'] ) || isset( $cnt['subject'] ) ) ) {
            return self::KIND_CONTACT;
        }
        return self::KIND_OTHER;
    }
}
