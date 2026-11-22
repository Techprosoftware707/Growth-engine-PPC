<?php
/**
 * Verified business facts used by every generated description, pitch and submission.
 *
 * Only facts marked "verified" are ever used. The plugin ships no business facts of its own: until the owner saves
 * a fact list (Settings → Content), the facts are the ones the owner stated in the business profile (site.* settings:
 * what the business offers, where it is based, when it was founded, its e-mail and public profiles), each with the
 * profile as its source. Facts the profile cannot state (testing, shipping, payment, audience, …) exist only when
 * the owner adds them.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Content;

use LinkGrowth\Autopilot\Support\Settings;

final class FactsRegistry {

    public const OPTION = 'lga_facts';

    public function __construct( private Settings $settings ) {}

    /**
     * Facts shipped with the plugin: none (no business data is bundled, DESIGN.md §1.7 B and E).
     *
     * @return array<string,array{statement:string,verified:bool,source:string,group:string}>
     */
    public static function defaults(): array {
        return [];
    }

    /**
     * Facts the owner stated in the business profile, worded as plain statements. Empty profile fields give no fact.
     *
     * @return array<string,array{statement:string,verified:bool,source:string,group:string}>
     */
    public function profileFacts(): array {
        $brand   = $this->settings->brandName();
        $offer   = self::listPhrase( array_map( 'strval', (array) $this->settings->get( 'site.categories', [] ) ) );
        $tagline = rtrim( trim( (string) $this->settings->get( 'site.tagline', '' ) ), '.' );
        $city    = trim( (string) $this->settings->get( 'site.city', '' ) );
        $region  = trim( (string) $this->settings->get( 'site.region', '' ) );
        $year    = trim( (string) $this->settings->get( 'site.founded_year', '' ) );
        $email   = trim( (string) $this->settings->get( 'site.email', '' ) );
        $social  = array_values( array_filter( array_map( 'strval', (array) $this->settings->get( 'site.social', [] ) ) ) );
        $source  = (string) $this->settings->get( 'site.home_url', '' );
        $fact    = static fn( string $statement, string $group ): array => [ 'statement' => $statement, 'verified' => true, 'source' => $source, 'group' => $group ];
        $out     = [];
        if ( '' !== $brand && '' !== $offer ) {
            $out['identity'] = $fact( $brand . ' offers ' . mb_strtolower( $offer ) . '.', 'identity' );
        } elseif ( '' !== $brand && '' !== $tagline ) {
            $out['identity'] = $fact( $brand . ': ' . $tagline . '.', 'identity' );
        }
        if ( '' !== $year ) {
            $out['established'] = $fact( 'The company was established in ' . $year . '.', 'identity' );
        }
        if ( '' !== $city ) {
            $out['location'] = $fact( 'It is based in ' . $city . ( '' !== $region ? ', ' . $region : '' ) . '.', 'identity' );
        }
        if ( '' !== $email ) {
            $out['contact_email'] = $fact( 'Contact: ' . $email, 'contact' );
        }
        if ( [] !== $social ) {
            $out['social'] = $fact( 'Public profiles: ' . implode( ', ', $social ) . '.', 'contact' );
        }
        return $out;
    }

    /** "a", "a and b", "a, b and c". @param string[] $items */
    public static function listPhrase( array $items ): string {
        $items = array_values( array_filter( array_map( 'trim', $items ), static fn( string $x ): bool => '' !== $x ) );
        if ( count( $items ) <= 1 ) {
            return $items[0] ?? '';
        }
        $last = array_pop( $items );
        return implode( ', ', $items ) . ' and ' . $last;
    }

    /** @return array<string,array{statement:string,verified:bool,source:string,group:string}> */
    public function all(): array {
        $stored = get_option( self::OPTION, null );
        if ( ! is_array( $stored ) || empty( $stored ) ) {
            return self::defaults() + $this->profileFacts();
        }
        $out = [];
        foreach ( $stored as $key => $f ) {
            if ( ! is_array( $f ) || empty( $f['statement'] ) ) {
                continue;
            }
            $out[ sanitize_key( (string) $key ) ] = [
                'statement' => (string) $f['statement'],
                'verified'  => ! empty( $f['verified'] ),
                'source'    => (string) ( $f['source'] ?? '' ),
                'group'     => (string) ( $f['group'] ?? 'custom' ),
            ];
        }
        return $out;
    }

    /** Verified statements only. @return array<string,string> key => statement */
    public function verified( ?string $group = null ): array {
        $out = [];
        foreach ( $this->all() as $key => $f ) {
            if ( $f['verified'] && ( null === $group || $f['group'] === $group ) ) {
                $out[ $key ] = $f['statement'];
            }
        }
        return $out;
    }

    public function statement( string $key ): string {
        $all = $this->all();
        return isset( $all[ $key ] ) && $all[ $key ]['verified'] ? $all[ $key ]['statement'] : '';
    }

    /**
     * True when generated copy may assert this fact in a fixed (template) wording.
     *
     * The fact must be verified. With $phrases, the current statement must still contain every
     * phrase (case-insensitive), so an owner edit that drops a detail (a payment method, a price, the name of a
     * testing partner) also retires the sentences that state it. Without phrases, any verified statement counts
     * (the plugin ships no default wording to compare it with).
     */
    public function supports( string $key, string ...$phrases ): bool {
        $all = $this->all();
        if ( ! isset( $all[ $key ] ) || ! $all[ $key ]['verified'] ) {
            return false;
        }
        $statement = self::normalize( $all[ $key ]['statement'] );
        if ( [] === $phrases ) {
            $default = self::defaults()[ $key ]['statement'] ?? null;
            return null === $default || self::normalize( $default ) === $statement;
        }
        foreach ( $phrases as $phrase ) {
            $phrase = self::normalize( $phrase );
            if ( '' === $phrase || ! str_contains( $statement, $phrase ) ) {
                return false;
            }
        }
        return true;
    }

    private static function normalize( string $text ): string {
        return trim( (string) preg_replace( '/\s+/u', ' ', mb_strtolower( $text ) ) );
    }

    /** @param array<string,array<string,mixed>> $facts */
    public function replace( array $facts ): void {
        $clean = [];
        foreach ( $facts as $key => $f ) {
            $key = sanitize_key( (string) ( $f['key'] ?? $key ) );
            if ( '' === $key || empty( $f['statement'] ) ) {
                continue;
            }
            $clean[ $key ] = [
                'statement' => sanitize_text_field( (string) $f['statement'] ),
                'verified'  => ! empty( $f['verified'] ),
                'source'    => esc_url_raw( (string) ( $f['source'] ?? '' ) ),
                'group'     => sanitize_key( (string) ( $f['group'] ?? 'custom' ) ),
            ];
        }
        update_option( self::OPTION, $clean, false );
    }

    public function reset(): void {
        delete_option( self::OPTION );
    }
}
