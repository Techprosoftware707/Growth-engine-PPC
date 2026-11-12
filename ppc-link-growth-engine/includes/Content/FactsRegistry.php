<?php
/**
 * Verified business facts used by every generated description, pitch and submission.
 *
 * Only facts marked "verified" are ever used. Defaults were taken from the live site's
 * About, Lab Testing and Contact pages and its Organization structured data.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Content;

use PPC\LinkGrowth\Support\Settings;

final class FactsRegistry {

    public const OPTION = 'ppclg_facts';

    public function __construct( private Settings $settings ) {}

    /** @return array<string,array{statement:string,verified:bool,source:string,group:string}> */
    public static function defaults(): array {
        $home = 'https://purepeptidescanada.com';
        return [
            'identity'          => [ 'statement' => 'Pure Peptides Canada is a Canadian-owned online supplier of research peptides and laboratory reagents.', 'verified' => true, 'source' => $home . '/about/', 'group' => 'identity' ],
            'established'       => [ 'statement' => 'The company was established in 2023.', 'verified' => true, 'source' => $home . '/about/', 'group' => 'identity' ],
            'location'          => [ 'statement' => 'It is based in Toronto, Ontario (222 Jarvis St, Toronto, ON M5B 2B8) and operates as an online store.', 'verified' => true, 'source' => $home . '/contact/', 'group' => 'identity' ],
            'research_use_only' => [ 'statement' => 'All research products are sold strictly for in-vitro laboratory research use and are not for human or veterinary use.', 'verified' => true, 'source' => $home . '/about/', 'group' => 'compliance' ],
            'no_medical_advice' => [ 'statement' => 'The company does not provide dosing, administration or protocol guidance of any kind.', 'verified' => true, 'source' => $home . '/contact/', 'group' => 'compliance' ],
            'not_approved'      => [ 'statement' => 'Products are not approved by Health Canada for human or animal use and are not drugs, supplements or cosmetics.', 'verified' => true, 'source' => $home . '/about/', 'group' => 'compliance' ],
            'lab_reports'       => [ 'statement' => 'Independent Janoshik Analytical test reports for most products are listed on the Lab Testing page by product, vial size and batch, each with a verification key checkable on the laboratory\'s own website.', 'verified' => true, 'source' => $home . '/lab-testing/', 'group' => 'quality' ],
            'reports_on_request' => [ 'statement' => 'Reports for products or batches not listed are available on request.', 'verified' => true, 'source' => $home . '/lab-testing/', 'group' => 'quality' ],
            'testing_methods'   => [ 'statement' => 'Documentation covers HPLC purity analysis and mass spectrometry identity confirmation where available.', 'verified' => true, 'source' => $home . '/about/', 'group' => 'quality' ],
            'cad_pricing'       => [ 'statement' => 'Prices are in Canadian dollars.', 'verified' => true, 'source' => $home . '/about/', 'group' => 'commerce' ],
            'shipping'          => [ 'statement' => 'Orders ship tracked from inside Canada by Canada Post Xpresspost to every province and territory, avoiding customs holds and brokerage fees.', 'verified' => true, 'source' => $home . '/shipping-across-canada/', 'group' => 'commerce' ],
            'shipping_terms'    => [ 'statement' => 'Xpresspost shipping is a flat $25 CAD (3–7 days), or free in 4–12 days on orders over $299.', 'verified' => true, 'source' => $home . '/about/', 'group' => 'commerce' ],
            'payment'           => [ 'statement' => 'Checkout accepts credit or debit card, Interac e-Transfer and cryptocurrency.', 'verified' => true, 'source' => $home . '/about/', 'group' => 'commerce' ],
            'customers'         => [ 'statement' => 'Customers include university lab groups, biotech and contract research teams, hospital and institutional laboratories, and independent qualified researchers.', 'verified' => true, 'source' => $home . '/about/', 'group' => 'audience' ],
            'library'           => [ 'statement' => 'The site publishes a Research Library with guides on peptide storage and handling, HPLC testing, reading a certificate of analysis, and choosing a Canadian supplier.', 'verified' => true, 'source' => $home . '/education/', 'group' => 'content' ],
            'support'           => [ 'statement' => 'The Canadian team typically replies within one business day.', 'verified' => true, 'source' => $home . '/contact/', 'group' => 'support' ],
            'skincare_line'     => [ 'statement' => 'A separate GHK-Cu cosmetic skincare line is sold for external use only.', 'verified' => true, 'source' => $home . '/about/', 'group' => 'identity' ],
            'contact_email'     => [ 'statement' => 'Contact: hi@purepeptidescanada.com', 'verified' => true, 'source' => $home . '/contact/', 'group' => 'contact' ],
            'social'            => [ 'statement' => 'Public profiles: @PurePeptidesCan on X and a Facebook page.', 'verified' => true, 'source' => $home . '/about/', 'group' => 'contact' ],
        ];
    }

    /** @return array<string,array{statement:string,verified:bool,source:string,group:string}> */
    public function all(): array {
        $stored = get_option( self::OPTION, null );
        if ( ! is_array( $stored ) || empty( $stored ) ) {
            return self::defaults();
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
     * phrase (case-insensitive), so an owner edit that drops a detail ("cryptocurrency",
     * "$299", "Janoshik") also retires the template sentences that state it. Without phrases,
     * the statement must still read as the shipped default the template was written from.
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
