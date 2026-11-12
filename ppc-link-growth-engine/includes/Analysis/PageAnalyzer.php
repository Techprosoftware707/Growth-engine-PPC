<?php
/**
 * Parses HTML into a PageAnalysis using DOMDocument.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Analysis;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use PPC\LinkGrowth\Support\Http\Response;
use PPC\LinkGrowth\Support\Url;

final class PageAnalyzer {

    private const SOCIAL_HOSTS = [ 'facebook.com', 'twitter.com', 'x.com', 'linkedin.com', 'instagram.com', 'youtube.com', 'tiktok.com', 'threads.net', 'mastodon.social', 'bsky.app', 'pinterest.com' ];

    /**
     * Inline styles that hide an element. font-size and opacity only count when the value is exactly zero
     * (0, 0px, 0.0em, 0 !important…), never 0.875rem or 0.5.
     */
    private const HIDDEN_STYLE_RE = '/(?<![\w-])(?:display\s*:\s*none|visibility\s*:\s*hidden|font-size\s*:\s*(?:0+(?:\.0*)?|\.0+)(?:px|em|rem|pt|pc|ex|ch|vw|vh|vmin|vmax|%)?\s*(?:;|!|$)|opacity\s*:\s*(?:0+(?:\.0*)?|\.0+)%?\s*(?:;|!|$))/';

    /** WHATWG / legacy charset labels mbstring does not know, mapped to a name mbstring or iconv accepts. */
    private const CHARSET_ALIASES = [
        'iso-8859-8-i'    => 'ISO-8859-8',
        'iso-8859-6-i'    => 'ISO-8859-6',
        'iso-8859-6-e'    => 'ISO-8859-6',
        'iso-8859-8-e'    => 'ISO-8859-8',
        'ks_c_5601-1987'  => 'UHC',
        'ks_c_5601-1989'  => 'UHC',
        'x-sjis'          => 'SJIS',
        'x-euc-jp'        => 'EUC-JP',
        'tis-620'         => 'TIS-620',
        'windows-874'     => 'TIS-620',
        'latin1'          => 'ISO-8859-1',
        'us-ascii'        => 'ASCII',
        'x-user-defined'  => '',
    ];

    public function fromResponse( Response $resp ): PageAnalysis {
        $a = $this->analyze( $resp->body, $resp->finalUrl ?: $resp->url, $resp->headers, $resp->status );
        $a->url = $resp->url;
        if ( $resp->error ) {
            $a->error = $resp->error;
        }
        return $a;
    }

    /**
     * @param array<string,string> $headers lower-cased
     */
    public function analyze( string $html, string $url, array $headers = [], int $status = 200 ): PageAnalysis {
        $a           = new PageAnalysis();
        $a->url      = $url;
        $a->finalUrl = $url;
        $a->status   = $status;
        $a->xRobots  = $headers['x-robots-tag'] ?? '';
        $a->htmlLength = strlen( $html );

        if ( '' === trim( $html ) ) {
            return $a;
        }
        $html = $this->toUtf8( $html, $headers['content-type'] ?? '' );
        $a->isWordPress = (bool) preg_match( '#/wp-content/|/wp-includes/|wp-json#', $html );

        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="UTF-8">' . preg_replace( '/<\?xml[^>]*\?>/i', '', $html ), LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET | LIBXML_COMPACT );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        $xp = new DOMXPath( $dom );

        $a->lang = $this->attr( $xp->query( '//html' )->item( 0 ), 'lang' );
        $a->title = $this->text( $xp->query( '//title' )->item( 0 ) );
        foreach ( $xp->query( '//meta' ) as $meta ) {
            if ( ! $meta instanceof DOMElement ) {
                continue;
            }
            $name    = strtolower( $meta->getAttribute( 'name' ) ?: $meta->getAttribute( 'property' ) );
            $content = trim( $meta->getAttribute( 'content' ) );
            switch ( $name ) {
                case 'description':
                case 'og:description':
                    if ( '' === $a->metaDescription ) {
                        $a->metaDescription = $content;
                    }
                    break;
                case 'robots':
                case 'googlebot':
                    $a->metaRobots = trim( $a->metaRobots . ' ' . $content );
                    break;
                case 'og:site_name':
                    $a->siteName = $content;
                    break;
                case 'article:published_time':
                    $a->datePublished = $content;
                    break;
                case 'article:modified_time':
                case 'og:updated_time':
                    $a->dateModified = $content;
                    break;
                case 'generator':
                    if ( stripos( $content, 'wordpress' ) !== false ) {
                        $a->isWordPress = true;
                    }
                    break;
            }
        }
        $canon = $xp->query( '//link[translate(@rel,"CANONICAL","canonical")="canonical"]' )->item( 0 );
        if ( $canon instanceof DOMElement ) {
            $a->canonical = Url::absolutize( $canon->getAttribute( 'href' ), $url );
        }
        foreach ( [ 'h1', 'h2', 'h3' ] as $h ) {
            $list = [];
            foreach ( $xp->query( '//' . $h ) as $node ) {
                $t = $this->text( $node );
                if ( '' !== $t ) {
                    $list[] = mb_substr( $t, 0, 200 );
                }
                if ( count( $list ) >= 25 ) {
                    break;
                }
            }
            $a->{$h} = $list;
        }

        // JSON-LD.
        foreach ( $xp->query( '//script[contains(translate(@type,"JSON","json"),"ld+json")]' ) as $node ) {
            $decoded = json_decode( trim( $node->textContent ), true );
            if ( is_array( $decoded ) ) {
                $a->jsonLd[] = $decoded;
            }
            if ( count( $a->jsonLd ) >= 12 ) {
                break;
            }
        }

        // Signals.
        $a->scriptCount = $xp->query( '//script' )->length;
        $a->imageCount  = $xp->query( '//img' )->length;
        $htmlLower      = strtolower( $html );
        $a->hasCaptcha  = (bool) preg_match( '/recaptcha|hcaptcha|turnstile|cf-challenge|g-recaptcha|h-captcha|captcha/i', $html );
        $a->adSignals   = preg_match_all( '/adsbygoogle|doubleclick\.net|googlesyndication|adnxs\.com|taboola|outbrain|mgid\.com|propellerads|adsterra/i', $html );
        $a->hasComments = (bool) preg_match( '/id=["\']comments["\']|class=["\'][^"\']*comment-list|disqus|wpDiscuz|commento/i', $html );
        $a->hasNewsletter = (bool) preg_match( '/newsletter|subscribe to our|mailchimp|convertkit|substack/i', $htmlLower );
        $a->hasAuthorByline = (bool) preg_match( '/rel=["\']author["\']|class=["\'][^"\']*(byline|author-name|entry-author|post-author)|itemprop=["\']author["\']/i', $html ) || ! empty( array_filter( $a->jsonLd, static fn( $j ) => isset( $j['author'] ) || ( isset( $j['@graph'] ) && is_array( $j['@graph'] ) && array_filter( $j['@graph'], static fn( $g ) => is_array( $g ) && isset( $g['author'] ) ) ) ) );
        foreach ( $xp->query( '//time[@datetime]' ) as $node ) {
            $dt = $this->attr( $node, 'datetime' );
            if ( '' === $a->datePublished ) {
                $a->datePublished = $dt;
            }
            $a->dateModified = $dt > $a->dateModified ? $dt : $a->dateModified;
        }

        // Links.
        $pageHost = Url::registrableDomain( $url );
        $seen     = [];
        foreach ( $xp->query( '//a[@href]' ) as $node ) {
            if ( ! $node instanceof DOMElement ) {
                continue;
            }
            $href = Url::absolutize( $node->getAttribute( 'href' ), $url );
            if ( '' === $href ) {
                $raw = $node->getAttribute( 'href' );
                if ( stripos( $raw, 'mailto:' ) === 0 ) {
                    $email = strtolower( trim( explode( '?', substr( $raw, 7 ) )[0] ) );
                    if ( is_email( $email ) ) {
                        $a->emails[] = [ 'email' => $email, 'context' => mb_substr( $this->text( $node->parentNode ), 0, 200 ), 'source' => 'mailto' ];
                    }
                }
                continue;
            }
            $host     = Url::registrableDomain( $href );
            $external = '' !== $host && $host !== $pageHost;
            $rel      = array_values( array_filter( preg_split( '/\s+/', strtolower( $node->getAttribute( 'rel' ) ) ) ?: [] ) );
            $anchor   = mb_substr( $this->text( $node ), 0, 300 );
            if ( '' === $anchor ) {
                $img = $xp->query( './/img[@alt]', $node )->item( 0 );
                $anchor = $img instanceof DOMElement ? mb_substr( trim( $img->getAttribute( 'alt' ) ), 0, 300 ) : '';
            }
            $key = $href . '|' . $anchor;
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $link = [
                'url'      => $href,
                'host'     => $host,
                'anchor'   => $anchor,
                'rel'      => $rel,
                'nofollow' => in_array( 'nofollow', $rel, true ),
                'external' => $external,
                'region'   => $this->region( $node ),
                'hidden'   => $this->isHidden( $node ),
            ];
            $a->links[] = $link;
            if ( $external ) {
                $a->externalDomains[ $host ] = ( $a->externalDomains[ $host ] ?? 0 ) + 1;
                foreach ( self::SOCIAL_HOSTS as $sh ) {
                    if ( $host === $sh || str_ends_with( $host, '.' . $sh ) ) {
                        $a->socialLinks[] = $href;
                        break;
                    }
                }
            } else {
                $a->internalPaths[] = Url::path( $href );
            }
            if ( count( $a->links ) >= 1500 ) {
                break;
            }
        }
        $a->socialLinks   = array_values( array_unique( $a->socialLinks ) );
        $a->internalPaths = array_values( array_unique( $a->internalPaths ) );

        // Main text (remove boilerplate elements).
        $body        = $xp->query( '//body' )->item( 0 );
        $boilerplate = []; // text of removed nav/header/footer/aside/form regions (searched for emails only)
        $boilerLen   = 0;
        if ( $body instanceof DOMNode ) {
            $clone = $body->cloneNode( true );
            $cxp   = new DOMXPath( $clone->ownerDocument );
            foreach ( [ 'script', 'style', 'noscript', 'template', 'svg', 'iframe', 'nav', 'header', 'footer', 'aside', 'form' ] as $tag ) {
                $nodes = [];
                foreach ( $cxp->query( './/' . $tag, $clone ) as $n ) {
                    $nodes[] = $n;
                }
                $keep = in_array( $tag, [ 'nav', 'header', 'footer', 'aside', 'form' ], true );
                foreach ( $nodes as $n ) {
                    if ( $n->parentNode ) {
                        if ( $keep && $boilerLen < 60000 ) {
                            $seg = html_entity_decode( $n->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                            $seg = trim( preg_replace( '/\s+/u', ' ', $seg ) ?? $seg );
                            if ( '' !== $seg ) {
                                $boilerplate[] = $seg;
                                $boilerLen    += strlen( $seg );
                            }
                        }
                        $n->parentNode->removeChild( $n );
                    }
                }
            }
            $text = $clone->textContent;
        } else {
            $text = $dom->textContent;
        }
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text ) ?? $text;
        $text = preg_replace( '/\s*\n\s*/u', "\n", $text ) ?? $text;
        $a->text      = trim( mb_substr( $text, 0, 60000 ) );
        $a->wordCount = count( preg_split( '/\s+/u', $a->text, -1, PREG_SPLIT_NO_EMPTY ) ?: [] );

        // Emails in text, including plain-text addresses in the footer/header/sidebar/nav that the
        // boilerplate-stripped main text no longer contains.
        if ( preg_match_all( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $a->text . "\n" . implode( "\n", $boilerplate ), $m ) ) {
            foreach ( array_unique( $m[0] ) as $email ) {
                $email = strtolower( $email );
                if ( is_email( $email ) && ! preg_match( '/\.(png|jpe?g|gif|webp|svg)$/i', $email ) ) {
                    $context = $a->snippetAround( $email, 80 );
                    foreach ( $boilerplate as $seg ) {
                        if ( '' !== $context ) {
                            break;
                        }
                        $context = $this->snippetIn( $seg, $email, 80 );
                    }
                    $a->emails[] = [ 'email' => $email, 'context' => $context, 'source' => 'text' ];
                }
            }
        }
        // Emails from JSON-LD.
        foreach ( $a->jsonLd as $ld ) {
            foreach ( $this->jsonLdEmails( $ld ) as $email ) {
                $a->emails[] = [ 'email' => $email, 'context' => 'structured data', 'source' => 'jsonld' ];
            }
        }
        $uniq = [];
        foreach ( $a->emails as $e ) {
            if ( ! isset( $uniq[ $e['email'] ] ) || 'text' === $uniq[ $e['email'] ]['source'] ) {
                $uniq[ $e['email'] ] = $e;
            }
        }
        $a->emails = array_values( $uniq );

        // Forms.
        $a->forms = ( new FormDetector() )->detect( $dom, $xp, $url );

        return $a;
    }

    /**
     * Returns $html as UTF-8 with every in-document charset declaration pointing at UTF-8, ready for
     * DOMDocument::loadHTML( '<?xml encoding="UTF-8">' . $html ). For callers that parse a response body
     * themselves (e.g. re-reading a form's controls before posting it).
     */
    public function utf8Html( string $html, string $contentType = '' ): string {
        return '' === trim( $html ) ? $html : $this->toUtf8( $html, $contentType );
    }

    private function toUtf8( string $html, string $contentType ): string {
        $charset = '';
        if ( preg_match( '/charset=["\']?([\w\-:.]+)/i', $contentType, $m ) ) {
            $charset = $m[1];
        } elseif ( preg_match( '/<meta[^>]+charset=["\']?([\w\-:.]+)/i', $html, $m ) ) {
            $charset = $m[1];
        }
        $charset = strtolower( $charset );
        if ( '' !== $charset && ! in_array( $charset, [ 'utf-8', 'utf8' ], true ) ) {
            $converted = $this->convertCharset( $html, $charset );
            if ( is_string( $converted ) && '' !== $converted ) {
                $html = $converted;
            }
        }
        if ( ! mb_check_encoding( $html, 'UTF-8' ) ) {
            $html = mb_convert_encoding( $html, 'UTF-8', 'ISO-8859-1' );
        }
        // The bytes are UTF-8 now. libxml honours an in-document <meta charset> over the
        // <?xml encoding="UTF-8"> hint, so a leftover legacy declaration would make it decode the
        // UTF-8 bytes a second time (mojibake). Point every declaration at UTF-8.
        return preg_replace( '/(<meta\b[^>]*?\bcharset\s*=\s*["\']?)[\w\-:.]+/i', '${1}utf-8', $html ) ?? $html;
    }

    /**
     * Converts $html from $charset to UTF-8. mbstring throws ValueError for labels it does not know
     * (windows-1250, iso-8859-8-i, x-user-defined…), so aliases and iconv are tried before giving up.
     */
    private function convertCharset( string $html, string $charset ): ?string {
        $candidates = [];
        if ( array_key_exists( $charset, self::CHARSET_ALIASES ) ) {
            if ( '' !== self::CHARSET_ALIASES[ $charset ] ) {
                $candidates[] = self::CHARSET_ALIASES[ $charset ];
            }
        } else {
            $candidates[] = $charset;
        }
        foreach ( $candidates as $from ) {
            try {
                $converted = @mb_convert_encoding( $html, 'UTF-8', $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                if ( is_string( $converted ) && '' !== $converted ) {
                    return $converted;
                }
            } catch ( \ValueError $e ) {
                // Unknown to mbstring; fall through to iconv.
            }
            if ( function_exists( 'iconv' ) ) {
                $converted = @iconv( $from, 'UTF-8//IGNORE', $html ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                if ( is_string( $converted ) && '' !== $converted ) {
                    return $converted;
                }
            }
        }
        return null;
    }

    private function snippetIn( string $haystack, string $needle, int $radius ): string {
        $pos = mb_stripos( $haystack, $needle );
        if ( false === $pos ) {
            return '';
        }
        $start = max( 0, $pos - $radius );
        return trim( mb_substr( $haystack, $start, $radius * 2 + mb_strlen( $needle ) ) );
    }

    private function text( ?DOMNode $node ): string {
        if ( ! $node ) {
            return '';
        }
        $t = html_entity_decode( $node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/\s+/u', ' ', $t ) ?? $t );
    }

    private function attr( ?DOMNode $node, string $name ): string {
        return $node instanceof DOMElement ? trim( $node->getAttribute( $name ) ) : '';
    }

    private function region( DOMNode $node ): string {
        $n      = $node;
        $i      = 0;
        $widget = false; // inside a WordPress widget wrapper; sidebar unless a content container comes first
        while ( $n && $i++ < 25 ) {
            if ( $n instanceof DOMElement ) {
                $tag    = strtolower( $n->tagName );
                $cls    = strtolower( $n->getAttribute( 'class' ) . ' ' . $n->getAttribute( 'id' ) . ' ' . $n->getAttribute( 'role' ) );
                $tokens = preg_split( '/\s+/', $cls, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
                if ( 'nav' === $tag || str_contains( $cls, 'navigation' ) || preg_match( '/\b(nav|menu)\b/', $cls ) ) {
                    return 'nav';
                }
                if ( 'footer' === $tag || str_contains( $cls, 'footer' ) || str_contains( $cls, 'contentinfo' ) ) {
                    return 'footer';
                }
                if ( 'aside' === $tag || str_contains( $cls, 'sidebar' ) || in_array( 'complementary', $tokens, true ) || (bool) array_intersect( [ 'widget-area', 'widgets-area', 'widget-column' ], $tokens ) ) {
                    return 'sidebar';
                }
                // WordPress widget classes ("widget", "widget_text"…). Page-builder blocks (elementor-widget*,
                // so-widget*, SiteOrigin "so-panel widget") are content and never start a token with "widget".
                if ( ! in_array( 'so-panel', $tokens, true ) && preg_grep( '/^widgets?(?:$|[_-])/', $tokens ) ) {
                    $widget = true;
                }
                if ( 'header' === $tag || str_contains( $cls, 'site-header' ) || str_contains( $cls, 'masthead' ) ) {
                    return 'header';
                }
                if ( str_contains( $cls, 'comment' ) ) {
                    return 'comments';
                }
                if ( in_array( $tag, [ 'main', 'article' ], true ) ) {
                    return 'content';
                }
            }
            $n = $n->parentNode;
        }
        return $widget ? 'sidebar' : 'content';
    }

    private function isHidden( DOMNode $node ): bool {
        $n = $node;
        $i = 0;
        while ( $n && $i++ < 8 ) {
            if ( $n instanceof DOMElement ) {
                $style = strtolower( $n->getAttribute( 'style' ) );
                if ( preg_match( self::HIDDEN_STYLE_RE, $style ) || 'true' === strtolower( $n->getAttribute( 'hidden' ) ) || $n->hasAttribute( 'hidden' ) ) {
                    return true;
                }
            }
            $n = $n->parentNode;
        }
        return false;
    }

    /** @return string[] */
    private function jsonLdEmails( array $ld, int $depth = 0 ): array {
        $out = [];
        if ( $depth > 5 ) {
            return $out;
        }
        foreach ( $ld as $k => $v ) {
            if ( 'email' === $k && is_string( $v ) ) {
                $email = strtolower( trim( str_ireplace( 'mailto:', '', $v ) ) );
                if ( is_email( $email ) ) {
                    $out[] = $email;
                }
            } elseif ( is_array( $v ) ) {
                $out = array_merge( $out, $this->jsonLdEmails( $v, $depth + 1 ) );
            }
        }
        return array_values( array_unique( $out ) );
    }
}
