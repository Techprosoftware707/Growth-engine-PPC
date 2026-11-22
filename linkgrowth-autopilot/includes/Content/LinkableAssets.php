<?php
/**
 * Pages on the own site worth linking to, with topic keywords for matching.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Content;

use LinkGrowth\Autopilot\Analysis\Taxonomy;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\Str;

final class LinkableAssets {

    private ?ClaimsFilter $claims = null;

    public function __construct( private Settings $settings, private Taxonomy $taxonomy ) {}

    private function claims(): ClaimsFilter {
        return $this->claims ??= new ClaimsFilter( new FactsRegistry( $this->settings ) );
    }

    /** Title and summary pass the claims filter, so the asset can be quoted in packages and outreach. */
    public function isClean( array $asset ): bool {
        return $this->claims()->isClean( trim( (string) ( $asset['title'] ?? '' ) . '. ' . (string) ( $asset['summary'] ?? '' ) ) );
    }

    /**
     * Assets the plugin assumes without an owner list: only the home page, described from the business profile. The
     * plugin cannot know which other pages a site has; the owner lists them in `content.linkable_assets`.
     *
     * @param string[] $topics
     * @return array<int,array{url:string,title:string,summary:string,topics:string[],kind:string}>
     */
    public static function defaults( string $home, string $title = '', string $summary = '', array $topics = [] ): array {
        $home  = untrailingslashit( $home );
        $title = '' !== trim( $title ) ? trim( $title ) : ( (string) wp_parse_url( $home, PHP_URL_HOST ) ?: $home );
        return [
            [
                'url'     => $home . '/',
                'title'   => $title,
                'summary' => trim( $summary ),
                'topics'  => array_values( array_filter( array_map( static fn( $t ): string => mb_strtolower( trim( (string) $t ) ), $topics ) ) ),
                'kind'    => 'home',
            ],
        ];
    }

    /** @return array<int,array{url:string,title:string,summary:string,topics:string[],kind:string}> */
    public function all(): array {
        $custom = (array) $this->settings->get( 'content.linkable_assets', [] );
        $list   = [];
        foreach ( $custom as $a ) {
            if ( is_array( $a ) && ! empty( $a['url'] ) && ! empty( $a['title'] ) ) {
                $list[] = [
                    'url'     => esc_url_raw( (string) $a['url'] ),
                    'title'   => (string) $a['title'],
                    'summary' => (string) ( $a['summary'] ?? '' ),
                    'topics'  => array_values( array_map( 'strval', (array) ( $a['topics'] ?? [] ) ) ),
                    'kind'    => (string) ( $a['kind'] ?? 'guide' ),
                ];
            }
        }
        if ( ! empty( $list ) ) {
            return $list;
        }
        $categories = array_map( 'strval', (array) $this->settings->get( 'site.categories', [] ) );
        $summary    = rtrim( trim( (string) $this->settings->get( 'site.tagline', '' ) ), '.' );
        if ( '' === $summary && [] !== $categories ) {
            $summary = FactsRegistry::listPhrase( $categories );
        }
        return self::defaults(
            (string) $this->settings->get( 'site.home_url', home_url() ),
            $this->settings->brandName(),
            '' !== $summary ? $summary . '.' : '',
            array_merge( $categories, [ 'supplier', 'about', 'company' ] )
        );
    }

    /**
     * Best asset for a given context text (page title/headings/snippet).
     *
     * @return array{url:string,title:string,summary:string,topics:string[],kind:string}
     */
    public function bestFor( string $context, string $preferKind = '' ): array {
        $context = mb_strtolower( $context );
        $best    = null;
        $bestS   = -1.0;
        $all     = $this->all();
        // Never pick an asset whose own title or summary would trip the claims filter (it would be
        // dropped from blurbs and block outreach); fall back to the full list only if none is clean.
        $clean   = array_values( array_filter( $all, fn( array $a ): bool => $this->isClean( $a ) ) );
        foreach ( [] !== $clean ? $clean : $all as $asset ) {
            $s = 0.0;
            foreach ( $asset['topics'] as $t ) {
                $s += Str::countPhrase( $context, $t ) * ( str_contains( $t, ' ' ) ? 2.0 : 1.0 );
            }
            if ( '' !== $preferKind && $asset['kind'] === $preferKind ) {
                $s += 0.5;
            }
            if ( 'home' === $asset['kind'] ) {
                $s -= 0.25; // prefer deep, useful resources over the homepage
            }
            if ( $s > $bestS ) {
                $bestS = $s;
                $best  = $asset;
            }
        }
        return $best ?? $all[0];
    }

    public function home(): array {
        foreach ( $this->all() as $a ) {
            if ( 'home' === $a['kind'] ) {
                return $a;
            }
        }
        return $this->all()[0];
    }
}
