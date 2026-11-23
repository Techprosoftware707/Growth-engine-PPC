<?php
/**
 * Converts a WordPress Site Health test result into a health check result (DESIGN.md §5.8 shape): good is ok,
 * recommended is warning, critical is critical; the description becomes plain text.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Health\Checks;

use LinkGrowth\Autopilot\Health\Check;

final class SiteHealthResult {

    private const STATUS = [
        'good'        => Check::OK,
        'recommended' => Check::WARNING,
        'critical'    => Check::CRITICAL,
    ];

    /**
     * @param array<string,mixed> $result   a Site Health test result (label, status, description, …)
     * @param array<string,mixed> $evidence
     * @return array{id:string,status:string,label:string,message:string,evidence:array<string,mixed>,action:array{label:string,url:string}}
     */
    public static function toCheck( string $id, array $result, array $evidence = [] ): array {
        $status = self::STATUS[ (string) ( $result['status'] ?? '' ) ] ?? Check::WARNING;
        return [
            'id'       => $id,
            'status'   => $status,
            'label'    => (string) ( $result['label'] ?? '' ),
            'message'  => self::text( (string) ( $result['description'] ?? '' ) ),
            'evidence' => $evidence,
            'action'   => Check::OK === $status ? [ 'label' => '', 'url' => '' ] : self::siteHealthAction(),
        ];
    }

    /** @return array{label:string,url:string} the Site Health screen, where the full advice is shown */
    public static function siteHealthAction(): array {
        return [
            'label' => __( 'Open Site Health', 'linkgrowth-autopilot' ),
            'url'   => function_exists( 'admin_url' ) ? (string) admin_url( 'site-health.php' ) : '',
        ];
    }

    /** HTML paragraphs and list items as one line of plain text. */
    private static function text( string $html ): string {
        $html = (string) preg_replace( '#</(p|li|ul|ol)>#i', ' ', $html );
        return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
    }
}
