<?php
/**
 * On-site SEO audit of published pages, posts and products, read straight from the WordPress database.
 *
 * Checks per item: resolved SEO title (Rank Math title / template, else the post title), meta description
 * (Rank Math description, else the excerpt), robots noindex (Rank Math robots meta, post-type default, global
 * default), focus keyword, title/description length, H1 headings inside the content, images without alt text,
 * word count, internal/external link counts and internal links that point at addresses no published item answers.
 * Across items: duplicate titles/descriptions, orphan pages (no inbound internal link), noindex items linked from a
 * navigation menu. An optional sample of at most ten URLs per audit is fetched through the polite HTTP client
 * (robots.txt and crawl delay respected) to confirm the HTTP status, canonical tag and JSON-LD presence.
 *
 * Work is cursor based: run() audits as many items as the time budget allows and continues on the next call.
 * The audit never edits content; every issue carries a concrete fix sentence and the item's edit link.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Seo;

use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Locks;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Support\Url;
use Throwable;

final class SiteAuditor {

    /** Stored result of the last completed audit (non-autoloaded). */
    public const OPTION_RESULT = 'ppclg_site_audit';

    /** Work state of the audit in progress (non-autoloaded). */
    public const OPTION_RUN = 'ppclg_site_audit_run';

    public const LOCK = 'seo_site_audit';

    /** Issues kept in the stored result (highest severity first). */
    public const MAX_ISSUES = 500;

    /** URLs fetched over HTTP per audit at most. */
    public const SAMPLE_MAX = 10;

    /** Audited post types, in the order they fill the page cap. */
    public const POST_TYPES = [ 'page', 'product', 'post' ];

    public const SEVERITIES = [ 'high', 'medium', 'low' ];

    private const DEFAULT_MAX_PAGES = 400;

    private const HARD_MAX_PAGES = 5000;

    /** Issues collected while a run is in progress (the stored list is capped at MAX_ISSUES). */
    private const MAX_RUN_ISSUES = 5000;

    /** Work older than this is abandoned and a fresh audit starts. */
    private const STALE_RUN_SECONDS = 3 * 86400;

    /** Seconds one sampled URL may need (robots.txt, crawl delay, request). */
    private const SAMPLE_SECONDS = 12.0;

    /** Spot-check deferrals less than this many seconds apart (one tick) count as one. */
    private const SAMPLE_DEFERRAL_GAP = 30;

    private const THIN_WORDS = 300;

    private const TITLE_MIN = 30;

    private const TITLE_MAX = 60;

    private const DESC_MIN = 70;

    private const DESC_MAX = 160;

    /** Taxonomies whose archive URLs count as valid internal link targets. */
    private const TAXONOMIES = [ 'category', 'post_tag', 'product_cat', 'product_tag', 'product_brand' ];

    /**
     * Paths that are not posts but are valid on a WordPress/WooCommerce site (never reported as broken). An entry
     * ending in "/" covers everything below it, a file name matches exactly, and any other entry matches itself
     * and what is below it ("/cart" and "/cart/x", never "/cartridge-filters/").
     */
    private const SKIP_PREFIXES = [ '/wp-content/', '/wp-admin', '/wp-json', '/wp-includes/', '/wp-login.php', '/feed', '/comments/feed', '/cart', '/checkout', '/my-account', '/xmlrpc.php', '/sitemap', '/robots.txt' ];

    /** @var array<string,array{kind:string,id:int}> per-request cache of internal link resolutions */
    private array $resolved = [];

    /**
     * @param (\Closure(string,object):string)|null $replaceVars Resolves Rank Math %variables% (default: RankMath\Helper::replace_vars when available).
     */
    public function __construct(
        private Settings $settings,
        private Logger $logger,
        private Locks $locks,
        private ?Client $client = null,
        private ?\Closure $replaceVars = null
    ) {}

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Audits the next batch of items within the budget; finalises the audit when every item is done.
     *
     * @param array{restart?:bool,sample?:bool} $opts
     * @return array{ok:bool,busy:bool,checked:int,total:int,issues:int,high:int,medium:int,low:int,more:bool,done:bool,summary:string}
     */
    public function run( TimeBudget $budget, array $opts = [] ): array {
        if ( ! $this->locks->acquire( self::LOCK, (int) ceil( $budget->remaining() ) + 60 ) ) {
            $progress = $this->progress();
            return [
                'ok'      => false,
                'busy'    => true,
                'checked' => $progress['checked'],
                'total'   => $progress['total'],
                'issues'  => $progress['issues'],
                'high'    => 0,
                'medium'  => 0,
                'low'     => 0,
                'more'    => $progress['running'],
                'done'    => false,
                'summary' => 'Another site audit run is in progress; it continues on its own.',
            ];
        }
        try {
            return $this->runLocked( $budget, $opts );
        } finally {
            $this->locks->release( self::LOCK );
        }
    }

    /**
     * Contract summary for the dashboard and the growth run.
     *
     * @return array{checked_at:?string,pages:int,complete:bool,counts:array{high:int,medium:int,low:int},issues_total:int,running:bool,progress:array{checked:int,total:int}}
     */
    public function summary(): array {
        $stored   = $this->result();
        $progress = $this->progress();
        $out      = [
            'checked_at'   => null,
            'pages'        => 0,
            'complete'     => false,
            'counts'       => [ 'high' => 0, 'medium' => 0, 'low' => 0 ],
            'issues_total' => 0,
            'running'      => $progress['running'],
            'progress'     => [ 'checked' => $progress['checked'], 'total' => $progress['total'] ],
        ];
        if ( [] !== $stored ) {
            $out['checked_at']   = is_string( $stored['checked_at'] ?? null ) ? $stored['checked_at'] : null;
            $out['pages']        = (int) ( $stored['pages'] ?? 0 );
            $out['counts']       = self::countsOf( $stored['counts'] ?? [] );
            $out['issues_total'] = (int) ( $stored['issues_total'] ?? 0 );
            $out['complete']     = ! $progress['running'];
            return $out;
        }
        if ( $progress['running'] ) {
            $out['pages']        = $progress['checked'];
            $out['counts']       = $progress['counts'];
            $out['issues_total'] = $progress['issues'];
        }
        return $out;
    }

    /**
     * The stored result of the last completed audit ([] when none).
     *
     * @return array<string,mixed>
     */
    public function result(): array {
        $stored = get_option( self::OPTION_RESULT, [] );
        return is_array( $stored ) ? $stored : [];
    }

    /**
     * Progress of the audit in progress.
     *
     * @return array{running:bool,checked:int,total:int,issues:int,counts:array{high:int,medium:int,low:int},started_at:?string,phase:string}
     */
    public function progress(): array {
        $work = $this->work();
        if ( [] === $work ) {
            return [ 'running' => false, 'checked' => 0, 'total' => 0, 'issues' => 0, 'counts' => [ 'high' => 0, 'medium' => 0, 'low' => 0 ], 'started_at' => null, 'phase' => '' ];
        }
        $counts = [ 'high' => 0, 'medium' => 0, 'low' => 0 ];
        foreach ( (array) ( $work['issues'] ?? [] ) as $issue ) {
            $sev = (string) ( $issue['severity'] ?? '' );
            if ( isset( $counts[ $sev ] ) ) {
                $counts[ $sev ]++;
            }
        }
        return [
            'running'    => true,
            'checked'    => (int) ( $work['cursor'] ?? 0 ),
            'total'      => count( (array) ( $work['ids'] ?? [] ) ),
            'issues'     => array_sum( $counts ),
            'counts'     => $counts,
            'started_at' => is_string( $work['started_at'] ?? null ) ? $work['started_at'] : null,
            'phase'      => (string) ( $work['phase'] ?? 'pages' ),
        ];
    }

    // ------------------------------------------------------------------
    // Shared helpers (also used by IndexNow and InternalLinker)
    // ------------------------------------------------------------------

    /**
     * Whether Rank Math (or WordPress itself) tells search engines not to index this item: the item's own
     * rank_math_robots meta, else the post type's custom robots default, else Rank Math's global robots default.
     */
    public static function isNoindex( int $postId, string $postType ): bool {
        if ( self::isWooNoindexPage( $postId ) ) {
            return true; // WooCommerce sends noindex for these through the wp_robots filter, whatever Rank Math says
        }
        $robots = self::robotsList( get_post_meta( $postId, 'rank_math_robots', true ) );
        if ( [] !== $robots ) {
            return in_array( 'noindex', $robots, true );
        }
        $titles = get_option( 'rank-math-options-titles', [] );
        if ( ! is_array( $titles ) ) {
            return false;
        }
        $type = sanitize_key( $postType );
        if ( 'on' === ( $titles[ 'pt_' . $type . '_custom_robots' ] ?? '' ) ) {
            return in_array( 'noindex', self::robotsList( $titles[ 'pt_' . $type . '_robots' ] ?? [] ), true );
        }
        return in_array( 'noindex', self::robotsList( $titles['robots_global'] ?? [] ), true );
    }

    /**
     * The WooCommerce cart, checkout and my-account pages: WooCommerce marks them noindex (wc_page_no_robots on the
     * wp_robots filter), so they are never submitted to IndexNow, graded, or sampled.
     */
    public static function isWooNoindexPage( int $postId ): bool {
        if ( $postId <= 0 || ! function_exists( 'wc_get_page_id' ) ) {
            return false;
        }
        foreach ( [ 'cart', 'checkout', 'myaccount' ] as $page ) {
            try {
                $id = (int) \call_user_func( 'wc_get_page_id', $page );
            } catch ( Throwable $e ) {
                continue;
            }
            if ( $id > 0 && $id === $postId ) {
                return true;
            }
        }
        return false;
    }

    /** WordPress "Discourage search engines from indexing this site" (Settings → Reading) is on. */
    public static function siteDiscouraged(): bool {
        $public = get_option( 'blog_public', '1' );
        return '0' === (string) $public;
    }

    /**
     * Robots directives from an array, a serialized array or a comma separated string.
     *
     * @return string[] lower-case directives
     */
    public static function robotsList( mixed $value ): array {
        if ( is_string( $value ) ) {
            $value = self::maybeUnserialize( $value );
        }
        if ( is_string( $value ) ) {
            $value = '' === trim( $value ) ? [] : explode( ',', $value );
        }
        if ( ! is_array( $value ) ) {
            return [];
        }
        $out = [];
        foreach ( $value as $k => $v ) {
            // Rank Math stores a list ['noindex','nofollow']; some imports keep a map ['noindex' => 'noindex'].
            $item = is_string( $v ) && '' !== trim( $v ) ? $v : ( is_string( $k ) ? $k : '' );
            $item = strtolower( trim( (string) $item ) );
            if ( '' !== $item ) {
                $out[] = $item;
            }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Parses an HTML fragment with DOMDocument inside a UTF-8 wrapper.
     *
     * @return array{0:\DOMDocument,1:\DOMElement,2:\DOMXPath}|null document, wrapper element, xpath (null without the DOM extension)
     */
    public static function loadFragment( string $html ): ?array {
        if ( ! class_exists( \DOMDocument::class ) ) {
            return null;
        }
        $html = mb_scrub( $html, 'UTF-8' );
        $doc  = new \DOMDocument( '1.0', 'UTF-8' );
        $prev = libxml_use_internal_errors( true );
        try {
            $loaded = $doc->loadHTML(
                '<?xml encoding="UTF-8"><!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="ppclg-fragment-root">' . $html . '</div></body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } catch ( Throwable $e ) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors( $prev );
        }
        if ( ! $loaded ) {
            return null;
        }
        $xpath = new \DOMXPath( $doc );
        $root  = $xpath->query( '//div[@id="ppclg-fragment-root"]' );
        $node  = false !== $root ? $root->item( 0 ) : null;
        if ( ! $node instanceof \DOMElement ) {
            return null;
        }
        return [ $doc, $node, $xpath ];
    }

    /**
     * Parses a complete HTML document (a fetched page) with DOMDocument.
     *
     * @return array{0:\DOMDocument,1:\DOMElement,2:\DOMXPath}|null document, document element, xpath
     */
    public static function loadDocument( string $html ): ?array {
        if ( ! class_exists( \DOMDocument::class ) || '' === trim( $html ) ) {
            return null;
        }
        $doc  = new \DOMDocument( '1.0', 'UTF-8' );
        $prev = libxml_use_internal_errors( true );
        try {
            $loaded = $doc->loadHTML( '<?xml encoding="UTF-8">' . mb_scrub( $html, 'UTF-8' ), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        } catch ( Throwable $e ) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors( $prev );
        }
        if ( ! $loaded || ! $doc->documentElement instanceof \DOMElement ) {
            return null;
        }
        return [ $doc, $doc->documentElement, new \DOMXPath( $doc ) ];
    }

    /**
     * Byte ranges [start, end) of everything that looks like a shortcode, including the content of enclosing
     * shortcodes ([tag]…[/tag]). Same structure as WordPress's get_shortcode_regex(), for any tag name.
     *
     * @return array<int,array{0:int,1:int}>
     */
    public static function shortcodeRanges( string $content ): array {
        if ( ! str_contains( $content, '[' ) ) {
            return [];
        }
        $regex = '/\[(\[?)([A-Za-z0-9_\-]+)(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)/s';
        if ( ! preg_match_all( $regex, $content, $m, PREG_OFFSET_CAPTURE ) ) {
            return [];
        }
        $out = [];
        foreach ( $m[0] as $match ) {
            $out[] = [ (int) $match[1], (int) $match[1] + strlen( (string) $match[0] ) ];
        }
        return $out;
    }

    /** Content with shortcodes (and enclosed shortcode content) removed. */
    public static function stripShortcodes( string $content ): string {
        $ranges = self::shortcodeRanges( $content );
        if ( [] === $ranges ) {
            return $content;
        }
        $out  = '';
        $from = 0;
        foreach ( $ranges as [ $start, $end ] ) {
            if ( $start < $from ) {
                continue;
            }
            $out .= substr( $content, $from, $start - $from ) . ' ';
            $from = $end;
        }
        return $out . substr( $content, $from );
    }

    /**
     * Content with shortcode tags ([tag …], [/tag]) removed but the text and markup they enclose kept, e.g. the
     * image and caption inside [caption]…[/caption].
     */
    public static function stripShortcodeTags( string $content ): string {
        if ( ! str_contains( $content, '[' ) ) {
            return $content;
        }
        return preg_replace( '/\[\/?[A-Za-z][A-Za-z0-9_\-]*(?:\s[^\[\]]*)?\/?\]/', ' ', $content ) ?? $content;
    }

    /** Visible word count of post content (shortcode tags, markup, scripts and styles removed). */
    public static function wordCount( string $content ): int {
        $text = self::plainText( self::stripShortcodeTags( $content ) );
        if ( '' === $text ) {
            return 0;
        }
        return (int) preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', $text );
    }

    /** Tags removed, entities decoded, whitespace collapsed. */
    public static function plainText( string $html ): string {
        $text = preg_replace( '@<(script|style|noscript|template)[^>]*?>.*?</\1\s*>@si', ' ', $html ) ?? $html;
        $text = preg_replace( '/<!--.*?-->/s', ' ', $text ) ?? $text;
        $text = strip_tags( str_replace( '<', ' <', $text ) );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( '/[\s\x{00A0}]+/u', ' ', mb_scrub( $text, 'UTF-8' ) ) ?? $text;
        return trim( $text );
    }

    /** Item builder in use: Elementor (and other page builders that keep their own layout data). */
    public static function builderOf( int $postId ): string {
        if ( 'builder' === (string) get_post_meta( $postId, '_elementor_edit_mode', true ) ) {
            return 'Elementor';
        }
        if ( 'on' === (string) get_post_meta( $postId, '_et_pb_use_builder', true ) ) {
            return 'Divi';
        }
        if ( in_array( (string) get_post_meta( $postId, '_fl_builder_enabled', true ), [ '1', 'true', 'yes' ], true ) ) {
            return 'Beaver Builder';
        }
        if ( 'bricks' === (string) get_post_meta( $postId, '_bricks_editor_mode', true ) ) {
            return 'Bricks';
        }
        return '';
    }

    /** The first Rank Math focus keyword of an item ('' when none). */
    public static function focusKeyword( int $postId ): string {
        $raw = get_post_meta( $postId, 'rank_math_focus_keyword', true );
        if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
            return '';
        }
        $first = explode( ',', $raw )[0];
        $first = html_entity_decode( wp_strip_all_tags( $first ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/\s+/u', ' ', $first ) ?? $first );
    }

    /** Host of the site's home URL without "www.". */
    public static function siteHost(): string {
        return preg_replace( '/^www\./', '', Url::host( home_url( '/' ) ) ) ?? '';
    }

    /** Whether $url (absolute) belongs to this site. */
    public static function isInternalUrl( string $url ): bool {
        $host = preg_replace( '/^www\./', '', Url::host( $url ) ) ?? '';
        return '' !== $host && $host === self::siteHost();
    }

    // ------------------------------------------------------------------
    // Run
    // ------------------------------------------------------------------

    /**
     * @param array{restart?:bool,sample?:bool} $opts
     * @return array<string,mixed>
     */
    private function runLocked( TimeBudget $budget, array $opts ): array {
        $work = $this->work();
        $age  = [] !== $work ? time() - (int) strtotime( (string) ( $work['started_at'] ?? '' ) ) : 0;
        if ( [] === $work || ! empty( $opts['restart'] ) || $age > self::STALE_RUN_SECONDS ) {
            $work = $this->startWork();
        }
        if ( array_key_exists( 'sample', $opts ) ) {
            $work['sample_enabled'] = (bool) $opts['sample'];
        }

        $ids   = array_map( 'intval', (array) ( $work['ids'] ?? [] ) );
        $total = count( $ids );
        $phase = (string) ( $work['phase'] ?? 'pages' );

        if ( 'pages' === $phase ) {
            $since = 0;
            while ( (int) $work['cursor'] < $total && $budget->has( 1.5 ) ) {
                $id = $ids[ (int) $work['cursor'] ];
                $work['cursor'] = (int) $work['cursor'] + 1;
                try {
                    $this->auditOne( $id, $work );
                } catch ( Throwable $e ) {
                    $this->logger->warning( 'seo', 'Site audit could not analyse an item: ' . $e->getMessage(), [ 'post_id' => $id ] );
                }
                if ( ++$since >= 50 ) {
                    $this->saveWork( $work ); // keep progress if the request dies
                    $since = 0;
                }
            }
            if ( (int) $work['cursor'] >= $total ) {
                $work['phase'] = ! empty( $work['sample_enabled'] ) && null !== $this->client ? 'sample' : 'finalize';
            }
        }

        $sampleDeferred = false;
        if ( 'sample' === ( $work['phase'] ?? '' ) ) {
            if ( $budget->has( self::SAMPLE_SECONDS ) || (int) ( $work['sample_deferrals'] ?? 0 ) >= 2 ) {
                $this->runSample( $work, $budget );
                $work['phase'] = 'finalize';
            } else {
                // Wait for a fresh budget (SiteAuditJob continues on the next tick). Deferrals within one tick
                // count once, so the spot check is never forced to run on a nearly spent budget.
                $sampleDeferred = true;
                if ( time() - (int) ( $work['sample_deferred_at'] ?? 0 ) >= self::SAMPLE_DEFERRAL_GAP ) {
                    $work['sample_deferrals'] = (int) ( $work['sample_deferrals'] ?? 0 ) + 1;
                }
                $work['sample_deferred_at'] = time();
            }
        }

        if ( 'finalize' === ( $work['phase'] ?? '' ) ) {
            $result = $this->finalize( $work );
            delete_option( self::OPTION_RUN );
            $counts = self::countsOf( $result['counts'] );
            $this->logger->info( 'seo', 'Site audit completed', [ 'pages' => $result['pages'], 'issues' => $result['issues_total'] ] + $counts );
            return [
                'ok'      => true,
                'busy'    => false,
                'checked' => (int) $result['pages'],
                'total'   => (int) $result['pages'],
                'issues'  => (int) $result['issues_total'],
                'high'    => $counts['high'],
                'medium'  => $counts['medium'],
                'low'     => $counts['low'],
                'more'    => false,
                'done'    => true,
                'summary' => sprintf(
                    'Audited %d %s; %d %s (%d high, %d medium, %d low).',
                    (int) $result['pages'],
                    1 === (int) $result['pages'] ? 'page' : 'pages',
                    (int) $result['issues_total'],
                    1 === (int) $result['issues_total'] ? 'issue' : 'issues',
                    $counts['high'],
                    $counts['medium'],
                    $counts['low']
                ),
            ];
        }

        $this->saveWork( $work );
        $progress = $this->progress();
        return [
            'ok'      => true,
            'busy'    => false,
            'checked' => $progress['checked'],
            'total'   => $progress['total'],
            'issues'  => $progress['issues'],
            'high'    => $progress['counts']['high'],
            'medium'  => $progress['counts']['medium'],
            'low'     => $progress['counts']['low'],
            'more'    => true,
            'done'    => false,
            // The HTTP spot check waits for a fresh budget: continue on the next tick, not right away.
            'sample_deferred' => $sampleDeferred,
            'summary' => 'sample' === ( $work['phase'] ?? '' )
                ? sprintf( 'Audited all %d pages; the HTTP spot check of up to %d URLs runs next.', $progress['total'], self::SAMPLE_MAX )
                : sprintf( 'Audited %d of %d pages; %d %s so far.', $progress['checked'], $progress['total'], $progress['issues'], 1 === $progress['issues'] ? 'issue' : 'issues' ),
        ];
    }

    /** @return array<string,mixed> */
    private function startWork(): array {
        $max   = (int) $this->settings->get( 'seo.audit_max_pages', self::DEFAULT_MAX_PAGES );
        $max   = max( 1, min( self::HARD_MAX_PAGES, $max > 0 ? $max : self::DEFAULT_MAX_PAGES ) );
        $ids   = [];
        $capped = false;
        foreach ( self::POST_TYPES as $type ) {
            $left = $max - count( $ids );
            if ( $left <= 0 ) {
                $capped = $capped || [] !== $this->publishedIds( $type, 1 );
                continue;
            }
            $found = $this->publishedIds( $type, $left + 1 );
            if ( count( $found ) > $left ) {
                $capped = true;
                $found  = array_slice( $found, 0, $left );
            }
            $ids = array_merge( $ids, $found );
        }
        $work = [
            'started_at'     => gmdate( 'c' ),
            'ids'            => array_values( array_unique( $ids ) ),
            'cursor'         => 0,
            'capped'         => $capped,
            'max_pages'      => $max,
            'phase'          => 'pages',
            'menu_ids'       => $this->menuPostIds(),
            'special_ids'    => $this->specialPageIds(),
            'records'        => [],
            'issues'         => [],
            'issue_overflow' => 0,
            'sample'         => [],
            'sample_enabled' => (bool) $this->settings->get( 'seo.audit_http_sample', true ),
        ];
        $this->saveWork( $work );
        return $work;
    }

    /** @return int[] */
    private function publishedIds( string $type, int $limit ): array {
        $found = get_posts(
            [
                'post_type'        => $type,
                'post_status'      => 'publish',
                'has_password'     => false,
                'fields'           => 'ids',
                'orderby'          => 'ID',
                'order'            => 'ASC',
                'numberposts'      => max( 1, $limit ),
                'no_found_rows'    => true,
                'suppress_filters' => true,
            ]
        );
        return array_values( array_filter( array_map( 'intval', is_array( $found ) ? $found : [] ), static fn( int $id ): bool => $id > 0 ) );
    }

    /**
     * Analyses one item and appends its record and issues to the work state.
     *
     * @param array<string,mixed> $work
     */
    private function auditOne( int $id, array &$work ): void {
        $post = get_post( $id );
        if ( ! is_object( $post ) || 'publish' !== (string) ( $post->post_status ?? '' ) ) {
            return;
        }
        $type      = (string) ( $post->post_type ?? 'post' );
        $url       = (string) get_permalink( $id );
        $name      = $this->plainTitle( $post );
        $edit      = $this->editLink( $id );
        $noindex   = self::isNoindex( $id, $type );
        $inMenu    = in_array( $id, array_map( 'intval', (array) ( $work['menu_ids'] ?? [] ) ), true );
        $title     = $this->resolveTitle( $post );
        $desc      = $this->resolveDescription( $post );
        $content   = (string) ( $post->post_content ?? '' );
        $words     = self::wordCount( $content );
        $parsed    = $this->parseContent( $content, '' !== $url ? $url : home_url( '/' ) );
        $issue     = function ( string $severity, string $code, string $message, string $fix ) use ( &$work, $id, $url, $name, $edit ): void {
            $this->addIssue( $work, [ 'post_id' => $id, 'url' => $url, 'title' => $name, 'severity' => $severity, 'code' => $code, 'message' => $message, 'fix' => $fix, 'edit_url' => $edit ] );
        };

        $work['records'][ (string) $id ] = [
            'u'  => $url,
            'p'  => $name,
            'ty' => $type,
            't'  => $title,
            'd'  => $desc,
            'n'  => $noindex,
            'w'  => $words,
            'li' => $parsed['internal'],
            'le' => $parsed['external'],
            'k'  => '' !== self::focusKeyword( $id ),
            'l'  => $parsed['targets'],
            'e'  => $edit,
        ];

        if ( $noindex && $inMenu ) {
            $issue( 'high', 'noindex_in_menu', 'This item is linked from a navigation menu but is set to noindex, so search engines are told to leave it out.', 'If it should appear in search, open it, go to Rank Math → Advanced → Robots Meta and select "Index"; if it should stay hidden, remove it from the menu under Appearance → Menus.' );
        }
        if ( '' === $title ) {
            $issue( 'high', 'missing_title', 'The item has no title, so search results have nothing to show as its headline.', 'Give the item a descriptive title, or set an SEO title in Rank Math (Edit Snippet → Title).' );
        }

        if ( ! $noindex ) {
            $len = mb_strlen( $title );
            if ( '' !== $title && $len > self::TITLE_MAX ) {
                $issue( 'medium', 'title_too_long', sprintf( 'The SEO title is %d characters long; search results usually cut titles after about %d characters.', $len, self::TITLE_MAX ), sprintf( 'Shorten the SEO title in Rank Math (Edit Snippet → Title) to %d characters or fewer, keeping the main phrase at the start.', self::TITLE_MAX ) );
            } elseif ( '' !== $title && $len < self::TITLE_MIN ) {
                $issue( 'medium', 'title_too_short', sprintf( 'The SEO title is only %d characters long.', $len ), sprintf( 'Lengthen the SEO title in Rank Math (Edit Snippet → Title) to at least %d characters with a descriptive phrase about the item.', self::TITLE_MIN ) );
            }
            $dlen = mb_strlen( $desc );
            if ( '' === $desc ) {
                $issue( 'medium', 'missing_description', 'No meta description is set (the Rank Math description and the excerpt are both empty), so search engines pick a snippet from the page text.', sprintf( 'Write a %d–%d character summary in Rank Math (Edit Snippet → Description).', self::DESC_MIN, self::DESC_MAX ) );
            } elseif ( $dlen > self::DESC_MAX ) {
                $issue( 'medium', 'description_too_long', sprintf( 'The meta description is %d characters long; search results usually cut it after about %d characters.', $dlen, self::DESC_MAX ), sprintf( 'Shorten the description in Rank Math (Edit Snippet → Description) to %d characters or fewer.', self::DESC_MAX ) );
            } elseif ( $dlen < self::DESC_MIN ) {
                $issue( 'medium', 'description_too_short', sprintf( 'The meta description is only %d characters long.', $dlen ), sprintf( 'Expand the description in Rank Math (Edit Snippet → Description) to at least %d characters that summarise the item.', self::DESC_MIN ) );
            }
            if ( in_array( $type, [ 'post', 'page' ], true ) && $words < self::THIN_WORDS && ! in_array( $id, array_map( 'intval', (array) ( $work['special_ids'] ?? [] ) ), true ) ) {
                $issue( 'low', 'thin_content', sprintf( 'The content has %d words.', $words ), sprintf( 'Expand the content to at least %d words of useful detail, or set the item to noindex in Rank Math if it is a utility page.', self::THIN_WORDS ) );
            }
        }

        foreach ( array_slice( $parsed['broken'], 0, 5 ) as $path ) {
            $issue( 'medium', 'broken_internal_link', sprintf( 'The content links to %s, which does not match any published page, post, product or category on this site.', $path ), sprintf( 'Edit the link to %s so it points at the current address of the page you meant, or remove it.', $path ) );
        }
        if ( $parsed['images_no_alt'] > 0 ) {
            $issue( 'low', 'images_missing_alt', sprintf( '%d %s in the content %s no alt text.', $parsed['images_no_alt'], 1 === $parsed['images_no_alt'] ? 'image' : 'images', 1 === $parsed['images_no_alt'] ? 'has' : 'have' ), 'Select each image in the editor and fill in "Alternative text" with a short description of what it shows.' );
        }
        if ( 'product' === $type ) {
            $thumb = (int) get_post_thumbnail_id( $id );
            if ( $thumb > 0 && '' === trim( (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true ) ) ) {
                $issue( 'low', 'product_image_missing_alt', 'The main product image has no alt text.', 'Open Media → Library, select the product image and fill in "Alternative text" with a short description of the item.' );
            }
        }
        if ( $parsed['h1'] >= 2 ) {
            $issue( 'low', 'multiple_h1', sprintf( 'The content contains %d H1 headings; a page should have a single H1 (most themes already print the title as one).', $parsed['h1'] ), 'Change the extra H1 headings in the content to H2.' );
        }
    }

    /**
     * Cross-item checks (duplicates, orphans), stores the result.
     *
     * @param array<string,mixed> $work
     * @return array<string,mixed>
     */
    private function finalize( array &$work ): array {
        $records = (array) ( $work['records'] ?? [] );

        if ( self::siteDiscouraged() ) {
            $this->addIssue( $work, [ 'post_id' => 0, 'url' => home_url( '/' ), 'title' => 'Whole site', 'severity' => 'high', 'code' => 'site_noindex', 'message' => 'WordPress is set to discourage search engines from indexing the whole site.', 'fix' => 'Go to Settings → Reading and untick "Discourage search engines from indexing this site", then save.', 'edit_url' => admin_url( 'options-reading.php' ) ] );
        }

        // Duplicate titles (high) and descriptions (medium) among indexable items.
        foreach ( [ 't' => [ 'high', 'duplicate_title', 'SEO title' ], 'd' => [ 'medium', 'duplicate_description', 'meta description' ] ] as $field => [ $severity, $code, $label ] ) {
            $groups = [];
            foreach ( $records as $id => $r ) {
                $value = mb_strtolower( trim( (string) ( $r[ $field ] ?? '' ) ) );
                if ( '' === $value || ! empty( $r['n'] ) ) {
                    continue;
                }
                $groups[ $value ][] = (int) $id;
            }
            foreach ( $groups as $ids ) {
                if ( count( $ids ) < 2 ) {
                    continue;
                }
                foreach ( $ids as $id ) {
                    $others = array_values( array_diff( $ids, [ $id ] ) );
                    $names  = array_map( static fn( int $o ): string => '“' . (string) ( $records[ (string) $o ]['p'] ?? ( '#' . $o ) ) . '”', array_slice( $others, 0, 3 ) );
                    $more   = count( $others ) > 3 ? sprintf( ' and %d more', count( $others ) - 3 ) : '';
                    $r      = $records[ (string) $id ];
                    $this->addIssue(
                        $work,
                        [
                            'post_id'  => $id,
                            'url'      => (string) $r['u'],
                            'title'    => (string) $r['p'],
                            'severity' => $severity,
                            'code'     => $code,
                            'message'  => sprintf( 'The same %s is used by %s%s.', $label, implode( ', ', $names ), $more ),
                            'fix'      => sprintf( 'Rewrite the %s in Rank Math (Edit Snippet) so it describes this item specifically and differs from the others.', $label ),
                            'edit_url' => (string) $r['e'],
                        ]
                    );
                }
            }
        }

        // Orphans: only meaningful when every published item was read (a capped audit misses inbound links).
        if ( empty( $work['capped'] ) ) {
            $inbound = [];
            foreach ( $records as $id => $r ) {
                foreach ( (array) ( $r['l'] ?? [] ) as $target ) {
                    if ( (int) $target !== (int) $id ) {
                        $inbound[ (int) $target ] = true;
                    }
                }
            }
            $menu    = array_map( 'intval', (array) ( $work['menu_ids'] ?? [] ) );
            $special = array_map( 'intval', (array) ( $work['special_ids'] ?? [] ) );
            foreach ( $records as $id => $r ) {
                $id = (int) $id;
                if ( ! in_array( (string) $r['ty'], [ 'post', 'page' ], true ) || ! empty( $r['n'] ) || isset( $inbound[ $id ] ) || in_array( $id, $menu, true ) || in_array( $id, $special, true ) ) {
                    continue;
                }
                if ( 'post' === (string) $r['ty'] ) {
                    // Posts are listed on the blog page and the category, tag, author and date archives, so they are
                    // reachable; only the lack of links from other content is worth a (low) note.
                    $this->addIssue( $work, [ 'post_id' => $id, 'url' => (string) $r['u'], 'title' => (string) $r['p'], 'severity' => 'low', 'code' => 'no_content_links', 'message' => 'No other page or post links to this post from its content; visitors reach it only through the blog listing and archives.', 'fix' => 'Link to it from one or two related pages or posts.', 'edit_url' => (string) $r['e'] ] );
                    continue;
                }
                $this->addIssue( $work, [ 'post_id' => $id, 'url' => (string) $r['u'], 'title' => (string) $r['p'], 'severity' => 'medium', 'code' => 'orphan', 'message' => 'No other published page, post, product or menu links to this page, so visitors and crawlers have no path to it.', 'fix' => 'Add a link to this page from at least one related published page or post (or from a menu).', 'edit_url' => (string) $r['e'] ] );
            }
        }

        $issues = (array) ( $work['issues'] ?? [] );
        $counts = [ 'high' => 0, 'medium' => 0, 'low' => 0 ];
        foreach ( $issues as $i ) {
            $sev = (string) ( $i['severity'] ?? '' );
            if ( isset( $counts[ $sev ] ) ) {
                $counts[ $sev ]++;
            }
        }
        $rank = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
        usort(
            $issues,
            static fn( array $a, array $b ): int => [ $rank[ $a['severity'] ] ?? 3, (int) $a['post_id'], (string) $a['code'] ] <=> [ $rank[ $b['severity'] ] ?? 3, (int) $b['post_id'], (string) $b['code'] ]
        );

        $stats = [ 'without_focus_keyword' => 0, 'noindex' => 0, 'internal_links' => 0, 'external_links' => 0, 'words' => 0 ];
        foreach ( $records as $r ) {
            $stats['without_focus_keyword'] += empty( $r['k'] ) ? 1 : 0;
            $stats['noindex']               += empty( $r['n'] ) ? 0 : 1;
            $stats['internal_links']        += (int) ( $r['li'] ?? 0 );
            $stats['external_links']        += (int) ( $r['le'] ?? 0 );
            $stats['words']                 += (int) ( $r['w'] ?? 0 );
        }
        $pages = count( $records );
        $stats['average_words'] = $pages > 0 ? (int) round( $stats['words'] / $pages ) : 0;
        unset( $stats['words'] );

        $byType = [];
        foreach ( $records as $r ) {
            $byType[ (string) $r['ty'] ] = ( $byType[ (string) $r['ty'] ] ?? 0 ) + 1;
        }

        $result = [
            'checked_at'   => gmdate( 'c' ),
            'started_at'   => (string) ( $work['started_at'] ?? '' ),
            'complete'     => true,
            'pages'        => $pages,
            'by_type'      => $byType,
            'capped'       => ! empty( $work['capped'] ),
            'max_pages'    => (int) ( $work['max_pages'] ?? self::DEFAULT_MAX_PAGES ),
            'orphans_checked' => empty( $work['capped'] ),
            'counts'       => $counts,
            'issues_total' => array_sum( $counts ) + (int) ( $work['issue_overflow'] ?? 0 ),
            'issues'       => array_slice( $issues, 0, self::MAX_ISSUES ),
            'stats'        => $stats,
            'sample'       => array_values( (array) ( $work['sample'] ?? [] ) ),
        ];
        update_option( self::OPTION_RESULT, $result, false );
        return $result;
    }

    // ------------------------------------------------------------------
    // HTTP sample
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $work */
    private function runSample( array &$work, TimeBudget $budget ): void {
        if ( null === $this->client ) {
            return;
        }
        $records = (array) ( $work['records'] ?? [] );
        $front   = (int) get_option( 'page_on_front', 0 );
        $urls    = [ home_url( '/' ) => $front > 0 ? $front : 0 ];
        $pick    = static function ( callable $filter ) use ( $records, &$urls ): void {
            foreach ( $records as $id => $r ) {
                if ( count( $urls ) >= self::SAMPLE_MAX ) {
                    return;
                }
                if ( '' !== (string) $r['u'] && empty( $r['n'] ) && $filter( (int) $id, $r ) && ! isset( $urls[ (string) $r['u'] ] ) ) {
                    $urls[ (string) $r['u'] ] = (int) $id;
                }
            }
        };
        $menu = array_map( 'intval', (array) ( $work['menu_ids'] ?? [] ) );
        $pick( static fn( int $id, array $r ): bool => in_array( $id, $menu, true ) );
        $pick( static fn( int $id, array $r ): bool => 'product' === $r['ty'] );
        $pick( static fn( int $id, array $r ): bool => true );

        $sample = [];
        foreach ( $urls as $url => $id ) {
            $url = (string) $url;
            if ( ! $budget->has( self::SAMPLE_SECONDS ) ) {
                $sample[] = [ 'url' => $url, 'post_id' => $id, 'status' => null, 'result' => 'unknown', 'note' => 'Not checked: the run ran out of time.' ];
                continue;
            }
            $sample[] = $this->checkUrl( $work, $url, $id, $records );
        }
        $work['sample'] = $sample;
    }

    /**
     * @param array<string,mixed> $work
     * @param array<string,mixed> $records
     * @return array{url:string,post_id:int,status:?int,result:string,note:string}
     */
    private function checkUrl( array &$work, string $url, int $id, array $records ): array {
        try {
            $resp = $this->client->crawl( $url, [ 'timeout' => 8, 'robots_timeout' => 4, 'redirection' => 3 ] );
        } catch ( Throwable $e ) {
            return [ 'url' => $url, 'post_id' => $id, 'status' => null, 'result' => 'unknown', 'note' => 'Not checked: ' . $e->getMessage() ];
        }
        if ( null !== $resp->error ) {
            return [ 'url' => $url, 'post_id' => $id, 'status' => null, 'result' => 'unknown', 'note' => 'Not checked: ' . $resp->error ];
        }
        $server = strtolower( $resp->header( 'server' ) );
        if ( in_array( $resp->status, [ 403, 429, 503 ], true ) && ( '' !== $resp->header( 'cf-mitigated' ) || str_contains( $server, 'cloudflare' ) ) ) {
            return [ 'url' => $url, 'post_id' => $id, 'status' => $resp->status, 'result' => 'unknown', 'note' => 'Cloudflare answered with a challenge, so the page itself could not be checked.' ];
        }
        $r     = $records[ (string) $id ] ?? null;
        $name  = is_array( $r ) ? (string) $r['p'] : 'Home page';
        $edit  = is_array( $r ) ? (string) $r['e'] : admin_url( 'options-reading.php' );
        $add   = function ( string $severity, string $code, string $message, string $fix ) use ( &$work, $id, $url, $name, $edit ): void {
            $this->addIssue( $work, [ 'post_id' => $id, 'url' => $url, 'title' => $name, 'severity' => $severity, 'code' => $code, 'message' => $message, 'fix' => $fix, 'edit_url' => $edit ] );
        };
        if ( $resp->status >= 400 ) {
            $add( 'high', 'http_error', sprintf( 'The published address answered with HTTP %d when fetched.', $resp->status ), 'Open the address in a private browser window; if it fails there too, check the permalink, caching and security rules for this page.' );
            return [ 'url' => $url, 'post_id' => $id, 'status' => $resp->status, 'result' => 'issue', 'note' => sprintf( 'HTTP %d', $resp->status ) ];
        }
        $notes = [];
        if ( $resp->redirected() && rtrim( Url::normalize( $resp->finalUrl ), '/' ) !== rtrim( Url::normalize( $url ), '/' ) ) {
            $add( 'medium', 'redirects', sprintf( 'The address redirects to %s.', $resp->finalUrl ), 'Update internal links and the item\'s permalink so they point straight at the final address.' );
            $notes[] = 'redirects';
        }
        $html  = $resp->body;
        $final = '' !== $resp->finalUrl ? $resp->finalUrl : $url;
        $dom   = '' !== $html ? self::loadDocument( $html ) : null;
        if ( null !== $dom ) {
            [ , , $xp ] = $dom;
            $canonical = '';
            $nodes     = $xp->query( '//link[@rel]' );
            foreach ( false !== $nodes ? $nodes : [] as $link ) {
                if ( $link instanceof \DOMElement && in_array( 'canonical', preg_split( '/\s+/', strtolower( trim( $link->getAttribute( 'rel' ) ) ) ) ?: [], true ) ) {
                    $canonical = trim( $link->getAttribute( 'href' ) );
                    break;
                }
            }
            if ( '' === $canonical ) {
                $add( 'low', 'missing_canonical', 'The page has no canonical tag.', 'Check that Rank Math is active and that "Canonical URL" is not blanked in the item\'s Rank Math → Advanced settings.' );
                $notes[] = 'no canonical';
            } elseif ( rtrim( Url::normalize( Url::absolutize( $canonical, $final ) ), '/' ) !== rtrim( Url::normalize( $final ), '/' ) ) {
                $add( 'medium', 'canonical_mismatch', sprintf( 'The canonical tag points at %s instead of the page itself.', $canonical ), 'Clear or correct the "Canonical URL" in the item\'s Rank Math → Advanced settings unless the other address is intentionally the main version.' );
                $notes[] = 'canonical elsewhere';
            }
            $jsonLd = $xp->query( '//script[@type="application/ld+json"]' );
            if ( false === $jsonLd || 0 === $jsonLd->length ) {
                $add( 'low', 'no_structured_data', 'No JSON-LD structured data was found on the page.', 'Enable Rank Math\'s Schema module and choose a schema type for this item (for example Product or Article).' );
                $notes[] = 'no JSON-LD';
            }
            $robots = $xp->query( '//meta[@name]' );
            foreach ( false !== $robots ? $robots : [] as $meta ) {
                if ( $meta instanceof \DOMElement && 'robots' === strtolower( $meta->getAttribute( 'name' ) ) && str_contains( strtolower( $meta->getAttribute( 'content' ) ), 'noindex' ) && ( ! is_array( $r ) || empty( $r['n'] ) ) ) {
                    $add( 'high', 'noindex_rendered', 'The page HTML carries a robots "noindex" tag although the item is not set to noindex in Rank Math.', 'Check Settings → Reading, other SEO or caching plugins and the theme for a second robots tag, and remove the noindex.' );
                    $notes[] = 'noindex tag';
                    break;
                }
            }
        }
        if ( str_contains( strtolower( $resp->header( 'x-robots-tag' ) ), 'noindex' ) ) {
            $add( 'high', 'noindex_header', 'The server sends an "X-Robots-Tag: noindex" header for this page.', 'Remove the X-Robots-Tag header from the server, CDN or security plugin rules for this address.' );
            $notes[] = 'noindex header';
        }
        return [ 'url' => $url, 'post_id' => $id, 'status' => $resp->status, 'result' => [] === $notes ? 'ok' : 'issue', 'note' => [] === $notes ? 'OK' : implode( ', ', $notes ) ];
    }

    // ------------------------------------------------------------------
    // Content analysis
    // ------------------------------------------------------------------

    /**
     * @return array{h1:int,images:int,images_no_alt:int,internal:int,external:int,targets:int[],broken:string[]}
     */
    private function parseContent( string $content, string $pageUrl ): array {
        $out = [ 'h1' => 0, 'images' => 0, 'images_no_alt' => 0, 'internal' => 0, 'external' => 0, 'targets' => [], 'broken' => [] ];
        if ( '' === trim( $content ) ) {
            return $out;
        }
        $dom = self::loadFragment( self::stripShortcodeTags( $content ) );
        if ( null === $dom ) {
            return $out;
        }
        [ , $root, $xp ] = $dom;
        $h1        = $xp->query( './/h1', $root );
        $out['h1'] = false !== $h1 ? $h1->length : 0;
        $imgs      = $xp->query( './/img', $root );
        foreach ( false !== $imgs ? $imgs : [] as $img ) {
            if ( ! $img instanceof \DOMElement ) {
                continue;
            }
            $out['images']++;
            if ( ! $img->hasAttribute( 'alt' ) || '' === trim( $img->getAttribute( 'alt' ) ) ) {
                $out['images_no_alt']++;
            }
        }
        $links = $xp->query( './/a[@href]', $root );
        foreach ( false !== $links ? $links : [] as $a ) {
            if ( ! $a instanceof \DOMElement ) {
                continue;
            }
            $href = trim( $a->getAttribute( 'href' ) );
            if ( '' === $href || str_starts_with( $href, '#' ) || preg_match( '/^(mailto|tel|javascript|sms|data):/i', $href ) ) {
                continue;
            }
            $abs = Url::absolutize( $href, $pageUrl );
            if ( '' === $abs ) {
                continue;
            }
            if ( ! self::isInternalUrl( $abs ) ) {
                $out['external']++;
                continue;
            }
            $out['internal']++;
            $res = $this->resolveInternal( $abs );
            if ( 'post' === $res['kind'] && $res['id'] > 0 ) {
                $out['targets'][] = $res['id'];
            } elseif ( 'broken' === $res['kind'] ) {
                $path = (string) wp_parse_url( $abs, PHP_URL_PATH );
                $out['broken'][] = '' !== $path ? $path : $abs;
            }
        }
        $out['targets'] = array_values( array_unique( $out['targets'] ) );
        $out['broken']  = array_values( array_unique( $out['broken'] ) );
        return $out;
    }

    /**
     * Resolves an internal URL without HTTP: 'post' (a published item, with id), 'ok' (a valid non-item address such
     * as the home page, an archive or a file), 'broken' (no published item, term or archive answers it).
     *
     * @return array{kind:string,id:int}
     */
    /** Whether a lower-case site-relative path is a WordPress/WooCommerce system address (see SKIP_PREFIXES). */
    public static function isSystemPath( string $lower ): bool {
        foreach ( self::SKIP_PREFIXES as $prefix ) {
            if ( str_ends_with( $prefix, '/' ) ) {
                $hit = str_starts_with( $lower, $prefix );
            } elseif ( 1 === preg_match( '/\.(php|txt)$/', $prefix ) ) {
                $hit = $lower === $prefix;
            } else {
                $hit = $lower === $prefix || str_starts_with( $lower, $prefix . '/' );
            }
            if ( $hit ) {
                return true;
            }
        }
        return false;
    }

    public function resolveInternal( string $url ): array {
        $path  = (string) wp_parse_url( $url, PHP_URL_PATH );
        $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
        $key   = $path . '?' . $query;
        if ( isset( $this->resolved[ $key ] ) ) {
            return $this->resolved[ $key ];
        }
        $homePath = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
        $rel      = '' !== $homePath && str_starts_with( $path, $homePath ) ? (string) substr( $path, strlen( $homePath ) ) : $path;
        $rel      = '/' . ltrim( $rel, '/' );
        $lower    = strtolower( $rel );

        $out = [ 'kind' => 'ok', 'id' => 0 ];
        if ( '/' === $rel && '' === $query ) {
            return $this->resolved[ $key ] = $out;
        }
        if ( self::isSystemPath( $lower ) ) {
            return $this->resolved[ $key ] = $out;
        }
        if ( preg_match( '/\.([a-z0-9]{2,5})$/', $lower, $m ) && ! in_array( $m[1], [ 'html', 'htm', 'php' ], true ) ) {
            return $this->resolved[ $key ] = $out; // a file (image, PDF, …): not checked without HTTP
        }

        $id = (int) url_to_postid( $url );
        if ( $id > 0 ) {
            $status = (string) get_post_status( $id );
            $out    = in_array( $status, [ 'publish', 'inherit' ], true ) ? [ 'kind' => 'publish' === $status ? 'post' : 'ok', 'id' => $id ] : [ 'kind' => 'broken', 'id' => $id ];
            return $this->resolved[ $key ] = $out;
        }
        if ( '/' === $rel ) {
            return $this->resolved[ $key ] = $out; // home page with a query string (?s=, ?add-to-cart=, …)
        }
        $slugPath = trim( $rel, '/' );
        if ( '' !== $slugPath ) {
            $page = get_page_by_path( $slugPath, OBJECT, [ 'page', 'post', 'product' ] );
            if ( is_object( $page ) && isset( $page->ID ) ) {
                $status = (string) ( $page->post_status ?? get_post_status( (int) $page->ID ) );
                $out    = 'publish' === $status ? [ 'kind' => 'post', 'id' => (int) $page->ID ] : [ 'kind' => 'broken', 'id' => (int) $page->ID ];
                return $this->resolved[ $key ] = $out;
            }
        }
        // Archives: pagination, dates, authors, search, taxonomy terms.
        if ( preg_match( '#(^|/)page/\d+$#', $slugPath ) || preg_match( '#^\d{4}(/\d{2}(/\d{2})?)?$#', $slugPath ) || preg_match( '#^(author|search|shop|blog)(/|$)#', $slugPath ) ) {
            return $this->resolved[ $key ] = $out;
        }
        $segments = array_values( array_filter( explode( '/', $slugPath ), static fn( string $s ): bool => '' !== $s ) );
        $last     = (string) end( $segments );
        if ( '' !== $last ) {
            foreach ( self::TAXONOMIES as $tax ) {
                $term = get_term_by( 'slug', sanitize_title( rawurldecode( $last ) ), $tax );
                if ( is_object( $term ) ) {
                    return $this->resolved[ $key ] = $out;
                }
            }
        }
        return $this->resolved[ $key ] = [ 'kind' => 'broken', 'id' => 0 ];
    }

    // ------------------------------------------------------------------
    // Titles and descriptions
    // ------------------------------------------------------------------

    /** The SEO title search engines get for an item (Rank Math title or template; else the post title). */
    public function resolveTitle( object $post ): string {
        $id  = (int) ( $post->ID ?? 0 );
        $raw = get_post_meta( $id, 'rank_math_title', true );
        $raw = is_string( $raw ) ? trim( $raw ) : '';
        if ( '' === $raw && $this->rankMathActive() ) {
            $titles = get_option( 'rank-math-options-titles', [] );
            $type   = sanitize_key( (string) ( $post->post_type ?? 'post' ) );
            $raw    = is_array( $titles ) && is_string( $titles[ 'pt_' . $type . '_title' ] ?? null ) ? trim( (string) $titles[ 'pt_' . $type . '_title' ] ) : '%title% %sep% %sitename%';
            if ( '' === $this->plainTitle( $post ) && str_contains( $raw, '%title%' ) ) {
                return ''; // a template around an empty title is still a missing title
            }
        }
        if ( '' === $raw ) {
            return $this->plainTitle( $post );
        }
        return $this->replaceVars( $raw, $post );
    }

    /** The meta description (Rank Math description, else the excerpt / product short description; '' when none). */
    public function resolveDescription( object $post ): string {
        $id  = (int) ( $post->ID ?? 0 );
        $raw = get_post_meta( $id, 'rank_math_description', true );
        $raw = is_string( $raw ) ? trim( $raw ) : '';
        if ( '' !== $raw ) {
            return $this->replaceVars( $raw, $post );
        }
        return self::clean( (string) ( $post->post_excerpt ?? '' ) );
    }

    private function plainTitle( object $post ): string {
        return self::clean( (string) ( $post->post_title ?? '' ) );
    }

    private function rankMathActive(): bool {
        return null !== $this->replaceVars || class_exists( '\\RankMath\\Helper' ) || defined( 'RANK_MATH_VERSION' );
    }

    private function replaceVars( string $text, object $post ): string {
        if ( ! str_contains( $text, '%' ) ) {
            return self::clean( $text );
        }
        try {
            if ( null !== $this->replaceVars ) {
                $out = ( $this->replaceVars )( $text, $post );
                if ( is_string( $out ) ) {
                    return self::clean( $out );
                }
            } elseif ( class_exists( '\\RankMath\\Helper' ) && method_exists( '\\RankMath\\Helper', 'replace_vars' ) ) {
                $out = \call_user_func( [ '\\RankMath\\Helper', 'replace_vars' ], $text, $post );
                if ( is_string( $out ) ) {
                    return self::clean( $out );
                }
            }
        } catch ( Throwable $e ) {
            $this->logger->debug( 'seo', 'Rank Math variable replacement failed: ' . $e->getMessage(), [ 'post_id' => (int) ( $post->ID ?? 0 ) ] );
        }
        // Fallback for the common variables; anything else is dropped rather than shown as "%var%".
        $titles = get_option( 'rank-math-options-titles', [] );
        $sep    = is_array( $titles ) && is_string( $titles['title_separator'] ?? null ) && '' !== $titles['title_separator'] ? html_entity_decode( (string) $titles['title_separator'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '-';
        $map    = [
            '%title%'    => $this->plainTitle( $post ),
            '%sitename%' => (string) get_bloginfo( 'name' ),
            '%sep%'      => $sep,
            '%excerpt%'  => self::clean( (string) ( $post->post_excerpt ?? '' ) ),
            '%focuskw%'  => self::focusKeyword( (int) ( $post->ID ?? 0 ) ),
        ];
        $out = strtr( $text, $map );
        $out = preg_replace( '/%[a-z0-9_\-()]+%/i', '', $out ) ?? $out;
        $out = self::clean( $out );
        return trim( $out, " \t" . $sep );
    }

    private static function clean( string $text ): string {
        $text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) ?? $text );
    }

    // ------------------------------------------------------------------
    // Site structure
    // ------------------------------------------------------------------

    /** @return int[] ids of items linked from any navigation menu */
    private function menuPostIds(): array {
        $ids = [];
        try {
            foreach ( array_slice( (array) wp_get_nav_menus(), 0, 20 ) as $menu ) {
                $menuId = is_object( $menu ) ? (int) ( $menu->term_id ?? 0 ) : (int) $menu;
                if ( $menuId <= 0 ) {
                    continue;
                }
                foreach ( (array) wp_get_nav_menu_items( $menuId ) as $item ) {
                    if ( ! is_object( $item ) ) {
                        continue;
                    }
                    if ( 'post_type' === (string) ( $item->type ?? '' ) && (int) ( $item->object_id ?? 0 ) > 0 ) {
                        $ids[] = (int) $item->object_id;
                    } elseif ( 'custom' === (string) ( $item->type ?? '' ) && '' !== (string) ( $item->url ?? '' ) ) {
                        $id = (int) url_to_postid( (string) $item->url );
                        if ( $id > 0 ) {
                            $ids[] = $id;
                        }
                    }
                }
            }
        } catch ( Throwable $e ) {
            $this->logger->debug( 'seo', 'Navigation menus could not be read: ' . $e->getMessage() );
        }
        // Block themes keep header/footer links in wp_navigation posts, not in classic menus.
        try {
            if ( wp_is_block_theme() ) {
                foreach ( get_posts( [ 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'numberposts' => 20, 'no_found_rows' => true ] ) as $nav ) {
                    if ( is_object( $nav ) ) {
                        $ids = array_merge( $ids, self::navigationLinkIds( (string) ( $nav->post_content ?? '' ) ) );
                    }
                }
            }
        } catch ( Throwable $e ) {
            $this->logger->debug( 'seo', 'Block navigation could not be read: ' . $e->getMessage() );
        }
        return array_values( array_unique( $ids ) );
    }

    /**
     * Item ids linked from core/navigation-link and core/navigation-submenu blocks (their `id` for post-type
     * links, else their `url`).
     *
     * @return int[]
     */
    public static function navigationLinkIds( string $content ): array {
        $ids = [];
        if ( ! preg_match_all( '/<!--\s+wp:(?:core\/)?navigation-(?:link|submenu)\s+(\{.*?\})\s+\/?-->/s', $content, $m ) ) {
            return $ids;
        }
        foreach ( $m[1] as $json ) {
            $a = json_decode( (string) $json, true );
            if ( ! is_array( $a ) ) {
                continue;
            }
            $id = (int) ( $a['id'] ?? 0 );
            if ( $id > 0 && 'post-type' === (string) ( $a['kind'] ?? '' ) ) {
                $ids[] = $id;
            } elseif ( is_string( $a['url'] ?? null ) && '' !== $a['url'] ) {
                $found = (int) url_to_postid( $a['url'] );
                if ( $found > 0 ) {
                    $ids[] = $found;
                }
            }
        }
        return $ids;
    }

    /** @return int[] front page, posts page, privacy page and WooCommerce system pages */
    private function specialPageIds(): array {
        $ids = [ (int) get_option( 'page_on_front', 0 ), (int) get_option( 'page_for_posts', 0 ), (int) get_option( 'wp_page_for_privacy_policy', 0 ) ];
        if ( function_exists( 'wc_get_page_id' ) ) {
            foreach ( [ 'shop', 'cart', 'checkout', 'myaccount', 'terms' ] as $page ) {
                try {
                    $ids[] = (int) \call_user_func( 'wc_get_page_id', $page );
                } catch ( Throwable $e ) {
                    unset( $e );
                }
            }
        }
        return array_values( array_unique( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) );
    }

    private function editLink( int $id ): string {
        $link = get_edit_post_link( $id, 'raw' );
        if ( is_string( $link ) && '' !== $link ) {
            return $link;
        }
        // No user context (cron): the standard editor address.
        return admin_url( sprintf( 'post.php?post=%d&action=edit', $id ) );
    }

    // ------------------------------------------------------------------
    // State
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $work @param array<string,mixed> $issue */
    private function addIssue( array &$work, array $issue ): void {
        if ( count( (array) ( $work['issues'] ?? [] ) ) >= self::MAX_RUN_ISSUES ) {
            $work['issue_overflow'] = (int) ( $work['issue_overflow'] ?? 0 ) + 1;
            return;
        }
        $work['issues'][] = $issue;
    }

    /** @return array<string,mixed> */
    private function work(): array {
        $work = get_option( self::OPTION_RUN, [] );
        return is_array( $work ) && isset( $work['ids'] ) ? $work : [];
    }

    /** @param array<string,mixed> $work */
    private function saveWork( array $work ): void {
        update_option( self::OPTION_RUN, $work, false );
    }

    /** @return array{high:int,medium:int,low:int} */
    private static function countsOf( mixed $counts ): array {
        $counts = is_array( $counts ) ? $counts : [];
        return [ 'high' => (int) ( $counts['high'] ?? 0 ), 'medium' => (int) ( $counts['medium'] ?? 0 ), 'low' => (int) ( $counts['low'] ?? 0 ) ];
    }

    private static function maybeUnserialize( string $value ): mixed {
        $trim = trim( $value );
        if ( '' === $trim || ! preg_match( '/^(a|s|i|b|d|N):/', $trim ) ) {
            return $value;
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
        $out = @unserialize( $trim, [ 'allowed_classes' => false ] );
        return false === $out && 'b:0;' !== $trim ? $value : $out;
    }
}
