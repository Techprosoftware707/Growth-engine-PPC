<?php
/**
 * Structured representation of an analysed HTML page.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Analysis;

use PPC\LinkGrowth\Support\Url;

final class PageAnalysis {

    public string $url = '';
    public string $finalUrl = '';
    public int $status = 0;
    public string $title = '';
    public string $metaDescription = '';
    public string $metaRobots = '';
    public string $xRobots = '';
    public string $canonical = '';
    public string $lang = '';
    public string $siteName = '';
    /** @var string[] */
    public array $h1 = [];
    /** @var string[] */
    public array $h2 = [];
    /** @var string[] */
    public array $h3 = [];
    public string $text = '';
    public int $wordCount = 0;
    public int $htmlLength = 0;
    /** @var array<int,array{url:string,host:string,anchor:string,rel:string[],nofollow:bool,external:bool,region:string,hidden:bool}> */
    public array $links = [];
    /** @var array<int,array{email:string,context:string,source:string}> */
    public array $emails = [];
    /** @var array<int,array<string,mixed>> */
    public array $forms = [];
    /** @var array<int,array<string,mixed>> */
    public array $jsonLd = [];
    /** @var string[] */
    public array $socialLinks = [];
    public string $datePublished = '';
    public string $dateModified = '';
    public bool $hasComments = false;
    public int $adSignals = 0;
    public int $imageCount = 0;
    public int $scriptCount = 0;
    public bool $hasCaptcha = false;
    public bool $hasAuthorByline = false;
    public bool $hasNewsletter = false;
    public bool $isWordPress = false;
    /** @var string[] */
    public array $internalPaths = [];
    /** @var array<string,int> */
    public array $externalDomains = [];
    public string $error = '';

    public function isIndexable(): bool {
        $robots = strtolower( $this->metaRobots . ' ' . $this->xRobots );
        if ( str_contains( $robots, 'noindex' ) ) {
            return false;
        }
        return $this->status >= 200 && $this->status < 300;
    }

    public function canonicalMismatch(): bool {
        if ( '' === $this->canonical ) {
            return false;
        }
        $a = rtrim( Url::normalize( $this->canonical ), '/' );
        $b = rtrim( Url::normalize( $this->finalUrl ?: $this->url ), '/' );
        return '' !== $a && $a !== $b;
    }

    /** @return array<int,array{url:string,host:string,anchor:string,rel:string[],nofollow:bool,external:bool,region:string,hidden:bool}> */
    public function linksTo( string $domain ): array {
        return array_values( array_filter( $this->links, static fn( array $l ) => Url::belongsTo( $l['url'], $domain ) ) );
    }

    /** @return array<int,array{url:string,host:string,anchor:string,rel:string[],nofollow:bool,external:bool,region:string,hidden:bool}> */
    public function externalLinks(): array {
        return array_values( array_filter( $this->links, static fn( array $l ) => $l['external'] ) );
    }

    public function mentions( array $terms ): array {
        $hay   = mb_strtolower( $this->title . "\n" . $this->text );
        $found = [];
        foreach ( $terms as $t ) {
            $t = mb_strtolower( trim( (string) $t ) );
            if ( '' !== $t && str_contains( $hay, $t ) ) {
                $found[] = $t;
            }
        }
        return $found;
    }

    public function snippetAround( string $needle, int $radius = 140 ): string {
        $pos = mb_stripos( $this->text, $needle );
        if ( false === $pos ) {
            return '';
        }
        $start = max( 0, $pos - $radius );
        $snip  = mb_substr( $this->text, $start, $radius * 2 + mb_strlen( $needle ) );
        return trim( preg_replace( '/\s+/u', ' ', $snip ) ?? $snip );
    }

    public function hasPathLink( string $pattern ): bool {
        foreach ( $this->internalPaths as $p ) {
            if ( preg_match( $pattern, $p ) ) {
                return true;
            }
        }
        return false;
    }

    public function headings(): string {
        return implode( ' | ', array_merge( $this->h1, $this->h2, $this->h3 ) );
    }

    /** Compact array for storage in the prospect profile. @return array<string,mixed> */
    public function summary(): array {
        return [
            'url'              => $this->finalUrl ?: $this->url,
            'status'           => $this->status,
            'title'            => $this->title,
            'description'      => $this->metaDescription,
            'lang'             => $this->lang,
            'site_name'        => $this->siteName,
            'word_count'       => $this->wordCount,
            'links_total'      => count( $this->links ),
            'links_external'   => count( $this->externalLinks() ),
            'indexable'        => $this->isIndexable(),
            'canonical_mismatch' => $this->canonicalMismatch(),
            'has_comments'     => $this->hasComments,
            'ad_signals'       => $this->adSignals,
            'has_captcha'      => $this->hasCaptcha,
            'has_author'       => $this->hasAuthorByline,
            'has_newsletter'   => $this->hasNewsletter,
            'date_modified'    => $this->dateModified ?: $this->datePublished,
            'social'           => array_slice( $this->socialLinks, 0, 8 ),
            'forms'            => array_map( static fn( array $f ) => [ 'kind' => $f['kind'] ?? 'other', 'fields' => count( $f['fields'] ?? [] ), 'captcha' => ! empty( $f['has_captcha'] ) ], $this->forms ),
            'h1'               => array_slice( $this->h1, 0, 3 ),
            'is_wordpress'     => $this->isWordPress,
        ];
    }
}
