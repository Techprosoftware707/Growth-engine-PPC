<?php
/**
 * Render check: does an item's public page show its post_content, and the words around a suggested anchor, to
 * visitors and search engines? Asked before Autopilot adds an internal link to the item (InternalLinker::autoApply()).
 *
 * A page can be published and still never print its own text: the static front page of a theme whose front-page
 * template is hard-coded, the posts page (WordPress lists posts there), the WooCommerce shop (a product archive),
 * a page template that ignores the editor content. A link added there is invisible to visitors and Google.
 *
 * - Certain cases need no request (cheap()): the posts page while the front page is a static page, and the
 *   WooCommerce shop, cart, checkout and my-account pages.
 * - Everything else is decided from the page itself (check()): one anonymous GET of the permalink through the
 *   plugin's HTTP client (no cookies, no redirects, Accept: text/html, a short timeout, at most MAX_FETCHES per run,
 *   at least GAP_SECONDS apart, inside the caller's TimeBudget). The answer counts only when it is HTTP 200 HTML whose
 *   <body> names this item (body class page-id-N / postid-N, or its shortlink / canonical address), so a login wall,
 *   maintenance page or challenge is never mistaken for the page. Text from the item (a few sentences spread through
 *   it, and for each suggestion the anchor with the words around it) is looked for, as one contiguous run of words,
 *   in the visible text of <body> only (scripts, styles, SVG and comments removed; the same normalisation on both
 *   sides). Shown: at least one of them is there. Hidden: none of at least two is there. A network error, a timeout,
 *   a non-200 answer, a page that does not identify itself or too little text to compare is "unknown": nothing is
 *   decided, the item is skipped this run and checked again later (UNKNOWN_RETRY), never a permanent verdict.
 *   A timeout, HTTP 429 / 5xx or a slow answer (SLOW_SECONDS) stops further requests for the rest of the run.
 * - Verdicts are kept per item in OPTION with a key of what could change them (the item's post_modified_gmt and
 *   page template, the theme and its version, and whether the item is the front page, the posts page or a
 *   WooCommerce page):
 *   a different key or VERDICT_TTL days make the item be checked again. After the plugin's own edit (an inserted or
 *   removed <a>, which does not change the visible words) rekey() carries the verdict over.
 *
 * Never sends Accept: text/markdown (some themes answer that with the raw content) and never edits anything.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Seo;

use PPC\LinkGrowth\Plugin;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use Throwable;

final class RenderCheck {

    /** Verdicts per item: {post_id: entry} (non-autoloaded). */
    public const OPTION = 'ppclg_render_checks';

    public const SHOWN   = 'shown';
    public const HIDDEN  = 'hidden';
    public const UNKNOWN = 'unknown';

    /** Per-suggestion outcomes in an entry's `found` map. */
    public const WORDS_FOUND   = 'yes';
    public const WORDS_MISSING = 'no';
    public const WORDS_SHORT   = 'short';
    public const WORDS_GONE    = 'gone';

    /** Page requests per run at most (filter ppclg_render_check_max_fetches, 0–10). */
    public const MAX_FETCHES = 3;

    /** A response slower than this stops further requests in the run (the host is under load). */
    public const SLOW_SECONDS = 5.0;

    /** Request timeout (seconds) unless the budget leaves less. */
    private const TIMEOUT = 8;

    /** Seconds between two requests (filter ppclg_render_check_gap_seconds). */
    private const GAP_SECONDS = 2.0;

    /** Response size cap; pages are 100–250 KB. */
    private const MAX_KB = 2048;

    /** Shown and hidden verdicts are re-checked after this, also when nothing in their key changed. */
    public const VERDICT_TTL = 7 * 86400;

    /** An unknown verdict is retried after this. */
    public const UNKNOWN_RETRY = 6 * 3600;

    private const MAX_ENTRIES = 600;

    /** Sentences sampled from the item's own text. */
    private const SAMPLES = 5;

    private const SAMPLE_WORDS = 10;

    private const MIN_SAMPLE_WORDS = 8;

    /** Words taken on each side of an anchor. */
    private const CONTEXT_WORDS = 6;

    /** Fewest neighbouring words for a one-sided match. */
    private const MIN_SIDE_WORDS = 4;

    /** Elements whose content is never visible text of the page. */
    private const INVISIBLE = [ 'script', 'style', 'noscript', 'template', 'svg', 'math', 'iframe', 'textarea', 'title', 'xmp', 'noembed', 'noframes', 'object' ];

    /** Inline elements: no word break at their tags (as InternalLinker::analyse() reads the content). */
    private const INLINE = [ 'a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'font', 'i', 'ins', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var' ];

    /** Plain words for the notes shown on the On-site SEO page. */
    public const NOTE_TEMPLATE  = 'Not added: this page’s template doesn’t display its content, so visitors and Google wouldn’t see the link.';
    public const NOTE_POSTS     = 'Not added: this is your posts page — WordPress lists your latest posts there and never shows the page’s own text, so visitors and Google wouldn’t see the link.';
    public const NOTE_SHOP      = 'Not added: this is the WooCommerce shop page — it shows the product catalogue, not the page’s own text, so visitors and Google wouldn’t see the link.';
    public const NOTE_WOO       = 'Not added: this is a WooCommerce cart, checkout or account page — it shows the visitor’s cart or account, not article text, and search engines are told not to index it.';
    public const NOTE_WORDS     = 'Not added: these words aren’t on the page visitors see (the theme or a plugin shows only part of this item), so the link wouldn’t be seen.';

    private int $fetches = 0;

    private int $maxFetches = self::MAX_FETCHES;

    private string $abort = '';

    private float $lastFetchEnd = 0.0;

    /**
     * @param (\Closure(float):void)|null $sleeper waits the given seconds between requests (default usleep; tests pass a recorder)
     * @param (\Closure():float)|null     $clock   current time in seconds (default microtime; tests pass a fake one)
     */
    public function __construct(
        private Settings $settings,
        private Logger $logger,
        private ?Client $client = null,
        private ?\Closure $sleeper = null,
        private ?\Closure $clock = null
    ) {}

    // ------------------------------------------------------------------
    // Run state
    // ------------------------------------------------------------------

    /** Starts a run: the request counter and the stop flag are reset. */
    public function beginRun(): void {
        $max              = (int) apply_filters( 'ppclg_render_check_max_fetches', self::MAX_FETCHES );
        $this->maxFetches = max( 0, min( 10, $max ) );
        $this->fetches    = 0;
        $this->abort      = '';
    }

    public function fetchesUsed(): int {
        return $this->fetches;
    }

    /** Why requests stopped for the rest of this run ('' while they may continue). */
    public function stopped(): string {
        return $this->abort;
    }

    /** Whether another page may be requested in this run (budget aside). */
    public function canFetch(): bool {
        return '' === $this->abort && $this->fetches < $this->maxFetches;
    }

    // ------------------------------------------------------------------
    // Verdicts
    // ------------------------------------------------------------------

    /**
     * Certain verdicts that need no request: WordPress or WooCommerce never shows this page's text.
     *
     * @return array{verdict:string,code:string,note:string,cheap:bool}|null
     */
    public function cheap( int $postId ): ?array {
        if ( $postId <= 0 ) {
            return null;
        }
        if ( 'page' === (string) get_option( 'show_on_front', 'posts' ) && $postId === (int) get_option( 'page_for_posts', 0 ) ) {
            return [ 'verdict' => self::HIDDEN, 'code' => 'posts_page', 'note' => self::NOTE_POSTS, 'cheap' => true ];
        }
        foreach ( self::wooPages() as $page => $id ) {
            if ( $id === $postId ) {
                return 'shop' === $page
                    ? [ 'verdict' => self::HIDDEN, 'code' => 'shop', 'note' => self::NOTE_SHOP, 'cheap' => true ]
                    : [ 'verdict' => self::HIDDEN, 'code' => 'woo_' . $page, 'note' => self::NOTE_WOO, 'cheap' => true ];
            }
        }
        return null;
    }

    /**
     * The stored verdict while it is still valid (same key, not expired), else null.
     *
     * @return array<string,mixed>|null
     */
    public function cached( int $postId ): ?array {
        $entry = $this->entries()[ $postId ] ?? null;
        if ( ! is_array( $entry ) || (string) ( $entry['key'] ?? '' ) !== $this->key( $postId ) || (int) ( $entry['expires'] ?? 0 ) <= time() ) {
            return null;
        }
        return $entry;
    }

    /**
     * What is known without a request: a certain verdict, else a valid stored one.
     *
     * @return array<string,mixed>|null
     */
    public function known( int $postId ): ?array {
        return $this->cheap( $postId ) ?? $this->cached( $postId );
    }

    /**
     * Requests the item's page and decides whether it shows the item's text and, for each suggestion ($terms:
     * suggestion id => anchor term), the words around the anchor. Null when no request may be made now (the run's
     * request limit, a stop after host trouble, or the budget); otherwise the stored entry (verdict shown / hidden /
     * unknown).
     *
     * @param array<int,string> $terms
     * @return array<string,mixed>|null
     */
    public function check( int $postId, array $terms, ?TimeBudget $budget = null ): ?array {
        $cheap = $this->cheap( $postId );
        if ( null !== $cheap ) {
            return $cheap;
        }
        $pre = apply_filters( 'ppclg_render_check_pre', null, $postId, $terms );
        if ( is_array( $pre ) && isset( $pre['verdict'] ) ) {
            return $this->store( $postId, $pre );
        }
        if ( ! $this->canFetch() || ( null !== $budget && ! $budget->has( 6.0 ) ) ) {
            return null;
        }
        $post = get_post( $postId );
        $url  = (string) get_permalink( $postId );
        if ( ! is_object( $post ) || '' === $url ) {
            return null;
        }

        $this->wait();
        $timeout = self::TIMEOUT;
        if ( null !== $budget ) {
            $timeout = (int) max( 3, min( self::TIMEOUT, floor( $budget->remaining() - 3.0 ) ) );
        }
        $this->fetches++;
        $resp    = null;
        $started = $this->now();
        try {
            $resp = $this->http()->rawRequest(
                'GET',
                $url,
                [
                    'timeout'     => $timeout,
                    'redirection' => 0,
                    'limit_kb'    => self::MAX_KB,
                    // Anonymous, as a visitor: no cookies. Never text/markdown (a theme may answer with raw content).
                    'headers'     => [ 'Accept' => 'text/html,application/xhtml+xml;q=0.9', 'Cache-Control' => 'no-cache' ],
                    'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
                ]
            );
        } catch ( Throwable $e ) {
            $this->logger->warning( 'seo', 'Page check request failed: ' . $e->getMessage(), [ 'post_id' => $postId ] );
        } finally {
            $this->lastFetchEnd = $this->now();
        }

        $seconds = round( max( 0.0, $this->lastFetchEnd - $started ), 2 );
        $status  = null !== $resp ? (int) $resp->status : 0;
        if ( null === $resp || null !== $resp->error ) {
            $error       = null !== $resp ? (string) $resp->error : 'the request failed';
            $timedOut    = (bool) preg_match( '/timed? ?out|cURL error 28/i', $error );
            $this->abort = $timedOut ? 'timeout' : 'error';
            return $this->store( $postId, self::unknown( $timedOut ? 'timeout' : 'error', $timedOut ? 'the page did not answer in time' : 'the page could not be requested (' . self::short( $error ) . ')', $status, $seconds ) );
        }
        if ( $seconds > self::SLOW_SECONDS ) {
            $this->abort = 'slow';
        }
        $challenge = '' !== $resp->header( 'cf-mitigated' ) || ( 200 !== $status && str_contains( $resp->body, 'Just a moment' ) );
        if ( 429 === $status || $status >= 500 || $challenge ) {
            $this->abort = $challenge ? 'challenge' : 'http';
            return $this->store( $postId, self::unknown( $challenge ? 'challenge' : 'http', $challenge ? 'a security challenge answered instead of the page' : sprintf( 'the page answered HTTP %d', $status ), $status, $seconds ) );
        }
        if ( 200 !== $status ) {
            return $this->store( $postId, self::unknown( 'http', sprintf( 'the page answered HTTP %d', $status ), $status, $seconds ) );
        }
        $type = $resp->contentType();
        if ( '' !== $type && ! str_contains( $type, 'html' ) ) {
            return $this->store( $postId, self::unknown( 'not_html', 'the page did not answer with HTML', $status, $seconds ) );
        }
        $entry = self::evaluate( $resp->body, $postId, $url, (string) ( $post->post_content ?? '' ), $terms );
        return $this->store( $postId, $entry + [ 'status' => $status, 'seconds' => $seconds ] );
    }

    /**
     * After the plugin's own edit of the item (an inserted or removed <a>: the visible words are unchanged), the stored
     * verdict is carried over to the item's new modification time.
     */
    public function rekey( int $postId ): void {
        $all = $this->entries();
        if ( ! isset( $all[ $postId ] ) || ! is_array( $all[ $postId ] ) ) {
            return;
        }
        $all[ $postId ]['key']      = $this->key( $postId );
        $all[ $postId ]['modified'] = $this->modified( $postId );
        $this->save( $all );
    }

    /** Forgets the stored verdict of an item. */
    public function forget( int $postId ): void {
        $all = $this->entries();
        if ( isset( $all[ $postId ] ) ) {
            unset( $all[ $postId ] );
            $this->save( $all );
        }
    }

    /**
     * What could change whether the item's page shows its text: its modification time and page template, the theme
     * and its version, and whether it is the static front page, the posts page or a WooCommerce page.
     */
    public function key( int $postId ): string {
        $theme   = '';
        $version = '';
        try {
            if ( function_exists( 'get_stylesheet' ) ) {
                $theme = (string) get_stylesheet() . '/' . ( function_exists( 'get_template' ) ? (string) get_template() : '' );
            }
            if ( function_exists( 'wp_get_theme' ) ) {
                $version = (string) wp_get_theme()->get( 'Version' );
            }
        } catch ( Throwable $e ) {
            unset( $e );
        }
        // Only this item's role counts (front page, posts page, a WooCommerce page): moving the front page elsewhere
        // re-checks the pages concerned, not every item.
        $static = 'page' === (string) get_option( 'show_on_front', 'posts' );
        return md5(
            (string) wp_json_encode(
                [
                    $this->modified( $postId ),
                    (string) get_post_meta( $postId, '_wp_page_template', true ),
                    $theme,
                    $version,
                    $static && (int) get_option( 'page_on_front', 0 ) === $postId,
                    $static && (int) get_option( 'page_for_posts', 0 ) === $postId,
                    (string) array_search( $postId, self::wooPages(), true ),
                ]
            )
        );
    }

    // ------------------------------------------------------------------
    // Pure helpers (public for tests)
    // ------------------------------------------------------------------

    /**
     * Decides from a fetched page. $terms: suggestion id => anchor term.
     *
     * @param array<int,string> $terms
     * @return array{verdict:string,code:string,note:string,samples:int,matched:int,found:array<int,string>}
     */
    public static function evaluate( string $html, int $postId, string $permalink, string $content, array $terms ): array {
        if ( ! self::identifies( $html, $postId, $permalink ) ) {
            return self::unknown( 'identity', 'the page that answered does not name this item (a login, maintenance or challenge page may be in the way)' ) + [ 'samples' => 0, 'matched' => 0, 'found' => [] ];
        }
        $visible = self::visibleWords( $html );
        if ( null === $visible ) {
            return self::unknown( 'identity', 'the page that answered has no body' ) + [ 'samples' => 0, 'matched' => 0, 'found' => [] ];
        }
        $samples  = self::contentSamples( $content );
        $matched  = 0;
        $evidence = count( $samples );
        foreach ( $samples as $sample ) {
            $matched += str_contains( $visible, $sample ) ? 1 : 0;
        }
        $found = [];
        foreach ( $terms as $id => $term ) {
            $ctx = self::context( $content, (string) $term );
            if ( null === $ctx ) {
                $found[ (int) $id ] = self::WORDS_GONE; // the words are no longer there: applying will close it as stale
                continue;
            }
            if ( 0 === $ctx['left_n'] + $ctx['right_n'] ) {
                $found[ (int) $id ] = self::WORDS_SHORT; // the anchor alone also appears in menus and listings
                continue;
            }
            $hit = str_contains( $visible, $ctx['full'] )
                || ( $ctx['left_n'] >= self::MIN_SIDE_WORDS && str_contains( $visible, $ctx['left'] ) )
                || ( $ctx['right_n'] >= self::MIN_SIDE_WORDS && str_contains( $visible, $ctx['right'] ) );
            $found[ (int) $id ] = $hit ? self::WORDS_FOUND : self::WORDS_MISSING;
            $matched           += $hit ? 1 : 0;
            $evidence          += ! $hit && $ctx['total'] >= self::MIN_SAMPLE_WORDS ? 1 : 0;
        }
        if ( $matched > 0 ) {
            return [ 'verdict' => self::SHOWN, 'code' => 'shown', 'note' => '', 'samples' => count( $samples ), 'matched' => $matched, 'found' => $found ];
        }
        if ( $evidence >= 2 ) {
            return [ 'verdict' => self::HIDDEN, 'code' => 'template', 'note' => self::NOTE_TEMPLATE, 'samples' => count( $samples ), 'matched' => 0, 'found' => $found ];
        }
        return self::unknown( 'too_little_text', 'there is too little text in this item to compare with the page' ) + [ 'samples' => count( $samples ), 'matched' => 0, 'found' => $found ];
    }

    /**
     * Whether a fetched document is WordPress serving this item: its body class names the item, or its shortlink or
     * canonical address is the item's.
     */
    public static function identifies( string $html, int $postId, string $permalink ): bool {
        if ( $postId <= 0 ) {
            return false;
        }
        if ( preg_match( '/<body\b[^>]*\bclass\s*=\s*(["\'])(.*?)\1/is', $html, $m ) ) {
            $classes = preg_split( '/\s+/', strtolower( trim( (string) $m[2] ) ) ) ?: [];
            if ( in_array( 'page-id-' . $postId, $classes, true ) || in_array( 'postid-' . $postId, $classes, true ) ) {
                return true;
            }
        }
        $head = (string) preg_replace( '/<body\b.*$/is', '', $html );
        if ( preg_match_all( '/<link\b[^>]*>/i', $head, $links ) ) {
            foreach ( $links[0] as $tag ) {
                if ( ! preg_match( '/\brel\s*=\s*(["\']?)([^"\'\s>]+)\1/i', $tag, $rel ) || ! preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/i', $tag, $href ) ) {
                    continue;
                }
                $relName = strtolower( (string) $rel[2] );
                $url     = html_entity_decode( (string) $href[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                if ( 'shortlink' === $relName ) {
                    parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
                    if ( (int) ( $q['p'] ?? $q['page_id'] ?? 0 ) === $postId ) {
                        return true;
                    }
                }
                if ( 'canonical' === $relName && '' !== $permalink && self::sameUrl( $url, $permalink ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Visible words of <body> as " w1 w2 … " (lower case), null when the document has no body.
     */
    public static function visibleWords( string $html ): ?string {
        $start = stripos( $html, '<body' );
        if ( false === $start ) {
            return null;
        }
        $body = substr( $html, $start );
        $body = (string) preg_replace( '/<!--.*?(?:-->|$)/s', ' ', $body );
        $body = (string) preg_replace( '#<(' . implode( '|', self::INVISIBLE ) . ')\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?(?:</\1\s*>|$)#is', ' ', $body );
        $body = (string) preg_replace_callback(
            '#</?([a-zA-Z][a-zA-Z0-9:\-]*)(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#',
            static fn( array $m ): string => in_array( strtolower( (string) $m[1] ), self::INLINE, true ) ? '' : ' ',
            $body
        );
        $text = html_entity_decode( $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return self::joinWords( self::words( $text ) );
    }

    /**
     * Up to SAMPLES runs of SAMPLE_WORDS words from the item's own prose (outside links, headings, shortcodes and
     * blocks that render from their settings; one block each), spread from start to end, each as " w1 w2 … ".
     *
     * @return string[]
     */
    public static function contentSamples( string $content ): array {
        if ( '' === trim( $content ) ) {
            return [];
        }
        $analysis = InternalLinker::analyse( $content );
        $runs     = [];
        $cur      = null;
        foreach ( $analysis['segments'] as $seg ) {
            $inShortcode = false;
            foreach ( $analysis['shortcodes'] as [ $s, $e ] ) {
                if ( $seg['start'] < $e && $s < $seg['end'] ) {
                    $inShortcode = true;
                    break;
                }
            }
            if ( empty( $seg['eligible'] ) || $inShortcode ) {
                if ( null !== $cur ) {
                    $runs[] = $cur;
                }
                $cur = null;
                continue;
            }
            // A block boundary (paragraph, list item, cell…) ends a run: a sample never spans two blocks.
            if ( null !== $cur && empty( $seg['join'] ) ) {
                $runs[] = $cur;
                $cur    = null;
            }
            $cur = null === $cur ? (string) $seg['decoded'] : $cur . (string) $seg['decoded'];
        }
        if ( null !== $cur ) {
            $runs[] = $cur;
        }
        $usable = [];
        foreach ( $runs as $run ) {
            $w = self::words( $run );
            if ( count( $w ) >= self::MIN_SAMPLE_WORDS ) {
                $usable[] = self::joinWords( array_slice( $w, 0, self::SAMPLE_WORDS ) );
            }
        }
        $n = count( $usable );
        if ( $n <= self::SAMPLES ) {
            return array_values( array_unique( $usable ) );
        }
        $out = [];
        for ( $i = 0; $i < self::SAMPLES; $i++ ) {
            $out[] = $usable[ (int) round( $i * ( $n - 1 ) / ( self::SAMPLES - 1 ) ) ];
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * The anchor's words with up to CONTEXT_WORDS visible words on each side, from the item's current content:
     * full, left (neighbours + anchor) and right (anchor + neighbours) as " w1 w2 … "; null when the anchor is no
     * longer found.
     *
     * @return array{full:string,left:string,right:string,left_n:int,right_n:int,total:int}|null
     */
    public static function context( string $content, string $term ): ?array {
        $analysis = InternalLinker::analyse( $content );
        $occ      = InternalLinker::locate( $analysis, $term );
        if ( null === $occ || ! isset( $occ['spaced_start'], $occ['spaced_length'] ) ) {
            return null;
        }
        $spaced = (string) $analysis['spaced'];
        $s      = (int) $occ['spaced_start'];
        $len    = (int) $occ['spaced_length'];
        $from   = max( 0, $s - 400 );
        $left   = self::words( mb_strcut( $spaced, $from, $s - $from, 'UTF-8' ) );
        $anchor = self::words( substr( $spaced, $s, $len ) );
        $right  = self::words( mb_strcut( $spaced, $s + $len, 400, 'UTF-8' ) );
        // A cut through a word leaves a fragment at the outer edge: drop it unless the cut is at the text's edge.
        if ( $from > 0 && count( $left ) > self::CONTEXT_WORDS ) {
            array_shift( $left );
        }
        if ( $s + $len + 400 < strlen( $spaced ) && count( $right ) > self::CONTEXT_WORDS ) {
            array_pop( $right );
        }
        $left  = array_slice( $left, -self::CONTEXT_WORDS );
        $right = array_slice( $right, 0, self::CONTEXT_WORDS );
        if ( [] === $anchor ) {
            return null;
        }
        return [
            'full'    => self::joinWords( array_merge( $left, $anchor, $right ) ),
            'left'    => self::joinWords( array_merge( $left, $anchor ) ),
            'right'   => self::joinWords( array_merge( $anchor, $right ) ),
            'left_n'  => count( $left ),
            'right_n' => count( $right ),
            'total'   => count( $left ) + count( $anchor ) + count( $right ),
        ];
    }

    /**
     * Lower-case words (letters, marks and digits) of decoded text. Punctuation, quotes and dashes are word breaks,
     * so wptexturize's curly quotes and dashes compare equal; its "3×3" is read as "3x3".
     *
     * @return string[]
     */
    public static function words( string $text ): array {
        $text = str_replace( "\u{00D7}", 'x', mb_scrub( $text, 'UTF-8' ) );
        if ( ! preg_match_all( '/[\p{L}\p{M}\p{N}]+/u', mb_strtolower( $text, 'UTF-8' ), $m ) ) {
            return [];
        }
        return $m[0];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** @param string[] $words */
    private static function joinWords( array $words ): string {
        return ' ' . implode( ' ', $words ) . ' ';
    }

    /** @return array{verdict:string,code:string,note:string,status?:int,seconds?:float} */
    private static function unknown( string $code, string $why, int $status = 0, float $seconds = 0.0 ): array {
        $out = [ 'verdict' => self::UNKNOWN, 'code' => $code, 'note' => 'Not checked yet: ' . $why . ', so it could not be confirmed that the page shows this text. It is checked again later.' ];
        if ( $status > 0 || $seconds > 0 ) {
            $out['status']  = $status;
            $out['seconds'] = $seconds;
        }
        return $out;
    }

    private static function short( string $text ): string {
        $text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
        return mb_strlen( $text ) > 120 ? mb_substr( $text, 0, 117 ) . '…' : $text;
    }

    private static function sameUrl( string $a, string $b ): bool {
        $norm = static function ( string $u ): string {
            $p    = wp_parse_url( $u );
            $host = strtolower( (string) ( $p['host'] ?? '' ) );
            $path = rtrim( (string) ( $p['path'] ?? '' ), '/' );
            return $host . $path . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
        };
        return '' !== $norm( $a ) && $norm( $a ) === $norm( $b );
    }

    /** @return array<string,int> WooCommerce page => id (only those that exist) */
    private static function wooPages(): array {
        $out = [];
        if ( ! function_exists( 'wc_get_page_id' ) ) {
            return $out;
        }
        foreach ( [ 'shop', 'cart', 'checkout', 'myaccount' ] as $page ) {
            try {
                $id = (int) \call_user_func( 'wc_get_page_id', $page );
            } catch ( Throwable $e ) {
                continue;
            }
            if ( $id > 0 ) {
                $out[ $page ] = $id;
            }
        }
        return $out;
    }

    private function modified( int $postId ): string {
        $post = get_post( $postId );
        return is_object( $post ) ? (string) ( $post->post_modified_gmt ?? '' ) : '';
    }

    /**
     * Stores a verdict with its key and expiry and returns the stored entry.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function store( int $postId, array $entry ): array {
        $ttl = self::UNKNOWN === ( $entry['verdict'] ?? '' ) ? self::UNKNOWN_RETRY : self::VERDICT_TTL;
        if ( 'too_little_text' === ( $entry['code'] ?? '' ) ) {
            $ttl = self::VERDICT_TTL; // nothing will change until the item does (the key)
        }
        $entry = array_merge(
            [ 'found' => [], 'samples' => 0, 'matched' => 0 ],
            $entry,
            [
                'post_id'    => $postId,
                'key'        => $this->key( $postId ),
                'modified'   => $this->modified( $postId ),
                'checked_at' => gmdate( 'c' ),
                'expires'    => time() + $ttl,
                'cheap'      => false,
            ]
        );
        $all            = $this->entries();
        $all[ $postId ] = $entry;
        if ( count( $all ) > self::MAX_ENTRIES ) {
            uasort( $all, static fn( array $a, array $b ): int => strcmp( (string) ( $a['checked_at'] ?? '' ), (string) ( $b['checked_at'] ?? '' ) ) );
            $all = array_slice( $all, count( $all ) - self::MAX_ENTRIES, null, true );
        }
        $this->save( $all );
        $this->logger->info( 'seo', 'Checked whether a page shows its content', [ 'post_id' => $postId, 'verdict' => $entry['verdict'], 'code' => $entry['code'], 'status' => $entry['status'] ?? null, 'seconds' => $entry['seconds'] ?? null ] );
        return $entry;
    }

    /** @return array<int,array<string,mixed>> */
    private function entries(): array {
        $raw = get_option( self::OPTION, [] );
        $out = [];
        foreach ( is_array( $raw ) ? $raw : [] as $id => $entry ) {
            if ( (int) $id > 0 && is_array( $entry ) ) {
                $out[ (int) $id ] = $entry;
            }
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $all */
    private function save( array $all ): void {
        update_option( self::OPTION, $all, false );
    }

    private function wait(): void {
        $gap = (float) apply_filters( 'ppclg_render_check_gap_seconds', self::GAP_SECONDS );
        if ( $this->lastFetchEnd <= 0.0 || $gap <= 0.0 ) {
            return;
        }
        $wait = $gap - ( $this->now() - $this->lastFetchEnd );
        if ( $wait <= 0.0 ) {
            return;
        }
        if ( null !== $this->sleeper ) {
            ( $this->sleeper )( $wait );
            return;
        }
        usleep( (int) round( min( 10.0, $wait ) * 1_000_000 ) );
    }

    private function now(): float {
        return null !== $this->clock ? (float) ( $this->clock )() : microtime( true );
    }

    private function http(): Client {
        if ( null === $this->client ) {
            $this->client = Plugin::instance()->get( Client::class );
        }
        return $this->client;
    }
}
