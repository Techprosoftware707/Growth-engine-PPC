<?php
/**
 * Extracts legitimate editorial/business contacts from analysed pages.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Analysis;

use PPC\LinkGrowth\Repositories\Suppression;
use PPC\LinkGrowth\Support\Url;

final class ContactExtractor {

    private const JUNK_DOMAINS = [ 'example.com', 'example.org', 'sentry.io', 'wixpress.com', 'domain.com', 'email.com', 'yoursite.com', 'website.com', 'company.com', 'test.com', 'sentry-next.wixpress.com', 'googlegroups.com', 'w3.org', 'schema.org' ];

    private const ROLE_MAP = [
        'editor'        => [ 'editor', 'editorial', 'editors', 'managing-editor', 'news', 'newsdesk', 'newsroom', 'press', 'media', 'tips', 'stories', 'pitch', 'pitches', 'submissions', 'contribute', 'contributors', 'writers' ],
        'partnerships'  => [ 'partner', 'partners', 'partnerships', 'sponsorship', 'sponsorships', 'advertising', 'advertise', 'ads', 'sales', 'marketing', 'business', 'bizdev', 'bd', 'collab', 'collaborations' ],
        'directory'     => [ 'listings', 'listing', 'directory', 'submit', 'members', 'membership', 'join', 'register', 'admin', 'webmaster', 'web', 'website' ],
        'general'       => [ 'info', 'hello', 'contact', 'contactus', 'enquiries', 'inquiries', 'office', 'mail', 'general', 'hi', 'team', 'secretary', 'communications', 'comms' ],
        'support'       => [ 'support', 'help', 'service', 'customerservice', 'orders', 'billing', 'accounts', 'privacy', 'legal', 'abuse', 'security', 'jobs', 'careers', 'hr', 'recruit' ],
        'noreply'       => [ 'noreply', 'no-reply', 'donotreply', 'do-not-reply', 'mailer-daemon', 'bounce', 'postmaster' ],
    ];

    public function __construct( private Suppression $suppression ) {}

    /**
     * @param PageAnalysis[] $pages
     * @return array<int,array{email:string,name:string,role:string,source_url:string,confidence:int}>
     */
    public function extract( array $pages, string $prospectDomain ): array {
        $out = [];
        foreach ( $pages as $page ) {
            $pageUrl = $page->finalUrl ?: $page->url;
            foreach ( $page->emails as $e ) {
                $email  = strtolower( $e['email'] );
                $domain = substr( $email, (int) strrpos( $email, '@' ) + 1 );
                if ( in_array( $domain, self::JUNK_DOMAINS, true ) || preg_match( '/\.(png|jpg|jpeg|gif|webp|svg|css|js)$/', $email ) ) {
                    continue;
                }
                if ( $this->suppression->isSuppressed( 'email', $email ) ) {
                    continue;
                }
                $local = substr( $email, 0, (int) strrpos( $email, '@' ) );
                $role  = $this->role( $local, $e['context'] ?? '' );
                if ( 'noreply' === $role || 'support' === $role ) {
                    continue; // never contact transactional/support mailboxes for outreach
                }
                $confidence = match ( $e['source'] ?? 'text' ) {
                    'jsonld' => 85,
                    'mailto' => 80,
                    default  => 60,
                };
                // Same-domain addresses are far more trustworthy than third-party ones.
                if ( Url::registrableDomain( $domain ) !== Url::registrableDomain( $prospectDomain ) ) {
                    $confidence -= 25;
                    if ( preg_match( '/gmail|yahoo|hotmail|outlook|icloud|protonmail|proton\.me/', $domain ) ) {
                        $confidence -= 10;
                    }
                }
                if ( preg_match( '#/(contact|about|team|staff|editorial|advertis|submit|partner)#i', $pageUrl ) ) {
                    $confidence += 5;
                }
                $name = $this->nameNear( $e['context'] ?? '', $email );
                $key  = $email;
                if ( ! isset( $out[ $key ] ) || $out[ $key ]['confidence'] < $confidence ) {
                    $out[ $key ] = [
                        'email'      => $email,
                        'name'       => $name,
                        'role'       => $role,
                        'source_url' => $pageUrl,
                        'confidence' => max( 5, min( 100, $confidence ) ),
                    ];
                }
            }
        }
        usort( $out, static fn( $a, $b ) => $b['confidence'] <=> $a['confidence'] );
        return array_values( array_slice( $out, 0, 8 ) );
    }

    public function role( string $local, string $context = '' ): string {
        $l = strtolower( preg_replace( '/[^a-z\-]/i', '', $local ) ?? $local );
        foreach ( self::ROLE_MAP as $role => $needles ) {
            foreach ( $needles as $n ) {
                if ( $l === $n || str_starts_with( $l, $n . '-' ) || str_starts_with( $l, $n ) && strlen( $l ) - strlen( $n ) <= 3 ) {
                    return $role;
                }
            }
        }
        $ctx = strtolower( $context );
        if ( preg_match( '/editor|editorial|press|media/', $ctx ) ) {
            return 'editor';
        }
        if ( preg_match( '/partner|sponsor|advertis/', $ctx ) ) {
            return 'partnerships';
        }
        if ( preg_match( '/listing|directory|submit|member/', $ctx ) ) {
            return 'directory';
        }
        // first.last style addresses are usually people.
        if ( preg_match( '/^[a-z]+[._\-][a-z]+$/', $local ) ) {
            return 'person';
        }
        return 'general';
    }

    private function nameNear( string $context, string $email ): string {
        $ctx = str_ireplace( $email, ' ', $context );
        if ( preg_match( '/\b([A-Z][a-z\'\-]{1,20}\s+[A-Z][a-zA-Z\'\-]{1,24})\b(?!\s+(Street|St\.|Avenue|Ave\.|Road|Rd\.|Blvd|Drive))/u', $ctx, $m ) ) {
            $cand = trim( $m[1] );
            if ( ! preg_match( '/^(Contact Us|Email Us|Send Email|Get In|Mailing Address|Privacy Policy|All Rights|Terms Of|Follow Us|Our Team|Phone Number)$/i', $cand ) ) {
                return $cand;
            }
        }
        return '';
    }
}
