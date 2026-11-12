<?php
/**
 * Internal-link suggestions between published items, applied only on an explicit admin click.
 *
 * Targets: published products (their title is the anchor) and published posts/pages with a Rank Math focus keyword
 * (the first keyword, at least 4 characters, not a generic word). For every published source the first whole-word,
 * case-insensitive occurrence of a target's anchor is looked for in text outside links, headings, script, style,
 * code, pre, figcaption (and other non-prose elements), outside shortcodes (including enclosed shortcode content)
 * and outside comments (block delimiters). No self-links, a target already linked from the source is skipped, each
 * target at most once per source and at most seo.max_links_per_post suggestions per source. Page-builder posts
 * (Elementor and similar) are skipped because their layout data, not post_content, is what visitors see.
 *
 * Nothing is ever changed automatically. apply() runs only from the admin's "Apply" click: it re-locates the
 * occurrence under the same rules, refuses when the surrounding text changed, wraps exactly those bytes in
 * <a href="…">…</a> (every other byte of post_content stays identical), checks the result with DOMDocument, saves
 * through wp_update_post() (which stores a revision) and writes an audit-log entry.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Seo;

use PPC\LinkGrowth\Automation\Audit;
use PPC\LinkGrowth\Support\Locks;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Support\Url;
use Throwable;

final class InternalLinker {

    /** Suggestions: {next_id, generated_at, items: {id: suggestion}} (non-autoloaded). */
    public const OPTION = 'ppclg_internal_links';

    /** Work state of the generation in progress (non-autoloaded). */
    public const OPTION_RUN = 'ppclg_internal_links_run';

    public const LOCK = 'seo_internal_links';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPLIED   = 'applied';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_STALE     = 'stale';

    /** Text inside these elements is never linked. */
    public const NO_LINK_TAGS = [ 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'script', 'style', 'code', 'pre', 'figcaption', 'textarea', 'button', 'select', 'option', 'label', 'noscript', 'iframe', 'svg', 'math', 'title', 'template', 'kbd', 'samp', 'object', 'video', 'audio', 'canvas', 'map' ];

    /** Inline elements: text on both sides of their tags reads as one word ("abc<b>def</b>"). */
    private const INLINE_TAGS = [ 'a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'font', 'i', 'ins', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var' ];

    /** Elements whose content is raw text (never parsed as markup, never visible prose). */
    private const RAW_TEXT_TAGS = [ 'script', 'style', 'textarea', 'title', 'xmp', 'noscript', 'iframe', 'noembed', 'noframes', 'plaintext' ];

    /**
     * Foreign (SVG / MathML) roots: the only NO_LINK_TAGS elements HTML5 lets "/>" close. On every other element
     * the parser ignores a trailing slash, so `<a href=/shop/>` or `<h2 class="x" />` stay open.
     */
    private const FOREIGN_TAGS = [ 'svg', 'math' ];

    /**
     * Blocks whose visible output is built from their comment attributes, not from the saved inner HTML (an edit
     * there would never show, and could make the editor flag the block as invalid). Used whether or not the block
     * registry is loaded; other non-core blocks are checked with WP_Block_Type_Registry (dynamic = opaque).
     */
    private const OPAQUE_BLOCKS = [ 'rank-math/faq-block', 'rank-math/howto-block', 'rank-math/toc-block' ];

    /** Block comment delimiter (the pattern WordPress' own block parser uses). */
    private const BLOCK_COMMENT = '/\G<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

    /** Most characters of block attribute text kept per segment for the attribute check in locate(). */
    private const MAX_ATTR_TEXT = 20000;

    /** Anchors too generic to link. */
    private const GENERIC = [
        'home', 'shop', 'store', 'about', 'about us', 'contact', 'contact us', 'blog', 'news', 'product', 'products', 'service', 'services',
        'research', 'peptide', 'peptides', 'cart', 'checkout', 'account', 'my account', 'login', 'privacy', 'privacy policy', 'terms',
        'terms and conditions', 'faq', 'faqs', 'page', 'click here', 'read more', 'learn more', 'more', 'here', 'this', 'that', 'sale',
        'new', 'best', 'quality', 'canada', 'order', 'orders', 'shipping', 'returns', 'refund policy', 'sample', 'samples', 'kit', 'vial', 'vials',
    ];

    private const MIN_ANCHOR = 4;

    private const MAX_ANCHOR = 80;

    private const MAX_ANCHOR_WORDS = 8;

    private const MAX_TARGETS = 1500;

    private const MAX_ITEMS = 2000;

    private const DEFAULT_MAX_SOURCES = 400;

    private const STALE_RUN_SECONDS = 3 * 86400;

    /** Bytes of surrounding visible text hashed to detect "the text changed". */
    private const CONTEXT_BYTES = 120;

    /** Bytes of surrounding visible text shown as the excerpt. */
    private const EXCERPT_BYTES = 150;

    public function __construct(
        private Settings $settings,
        private Logger $logger,
        private Locks $locks,
        private ?Audit $audit = null
    ) {}

    // ------------------------------------------------------------------
    // Suggestions (generation)
    // ------------------------------------------------------------------

    public function enabled(): bool {
        return (bool) $this->settings->get( 'seo.internal_links', true );
    }

    public function maxPerSource(): int {
        return max( 1, min( 20, (int) $this->settings->get( 'seo.max_links_per_post', 3 ) ) );
    }

    /**
     * Builds suggestions for the next batch of sources within the budget (cursor based). Never edits content.
     *
     * @param array{restart?:bool,manual?:bool} $opts
     * @return array{ok:bool,skipped:bool,reason:string,checked:int,total:int,targets:int,suggested:int,pending:int,more:bool,summary:string}
     */
    public function run( TimeBudget $budget, array $opts = [] ): array {
        if ( ! $this->enabled() && empty( $opts['manual'] ) ) {
            return $this->runOutcome( false, true, 'disabled', 0, 0, 0, 0, false, 'Internal-link suggestions are turned off in Settings.' );
        }
        if ( ! $this->locks->acquire( self::LOCK, (int) ceil( $budget->remaining() ) + 60 ) ) {
            return $this->runOutcome( false, true, 'busy', 0, 0, 0, 0, true, 'Internal-link suggestions are already being updated.' );
        }
        try {
            return $this->runLocked( $budget, $opts );
        } finally {
            $this->locks->release( self::LOCK );
        }
    }

    /**
     * @param array{restart?:bool} $opts
     * @return array<string,mixed>
     */
    private function runLocked( TimeBudget $budget, array $opts ): array {
        $work = get_option( self::OPTION_RUN, [] );
        $work = is_array( $work ) && isset( $work['sources'] ) ? $work : [];
        $age  = [] !== $work ? time() - (int) strtotime( (string) ( $work['started_at'] ?? '' ) ) : 0;
        if ( [] === $work || ! empty( $opts['restart'] ) || $age > self::STALE_RUN_SECONDS ) {
            $work = [
                'started_at' => gmdate( 'c' ),
                'sources'    => $this->sourceIds(),
                'cursor'     => 0,
                'targets'    => $this->targets(),
                'suggested'  => 0,
            ];
        }
        $store   = $this->store();
        $sources = array_map( 'intval', (array) $work['sources'] );
        $total   = count( $sources );
        $max     = $this->maxPerSource();

        while ( (int) $work['cursor'] < $total && $budget->has( 1.5 ) ) {
            $sourceId = $sources[ (int) $work['cursor'] ];
            $work['cursor'] = (int) $work['cursor'] + 1;
            try {
                $work['suggested'] = (int) $work['suggested'] + $this->suggestFor( $sourceId, (array) $work['targets'], $max, $store );
            } catch ( Throwable $e ) {
                $this->logger->warning( 'seo', 'Internal-link suggestions failed for an item: ' . $e->getMessage(), [ 'post_id' => $sourceId ] );
            }
        }

        $done = (int) $work['cursor'] >= $total;
        if ( $done ) {
            // Pending suggestions whose source is no longer a published, eligible item are dropped.
            foreach ( $store['items'] as $id => $item ) {
                if ( self::STATUS_PENDING === $item['status'] && ! in_array( (int) $item['source_post_id'], $sources, true ) ) {
                    unset( $store['items'][ $id ] );
                }
            }
            $store['generated_at'] = gmdate( 'c' );
        }
        $this->saveStore( $store );
        if ( $done ) {
            delete_option( self::OPTION_RUN );
        } else {
            update_option( self::OPTION_RUN, $work, false );
        }

        $pending = $this->pendingCount();
        $checked = (int) $work['cursor'];
        return $this->runOutcome(
            true,
            false,
            $done ? 'done' : 'continuing',
            $checked,
            $total,
            count( (array) $work['targets'] ),
            (int) $work['suggested'],
            ! $done,
            $done
                ? sprintf( 'Checked %d %s against %d link %s; %d %s waiting for your review.', $checked, 1 === $checked ? 'item' : 'items', count( (array) $work['targets'] ), 1 === count( (array) $work['targets'] ) ? 'target' : 'targets', $pending, 1 === $pending ? 'suggestion is' : 'suggestions are' )
                : sprintf( 'Checked %d of %d items for internal-link opportunities; %d %s waiting so far.', $checked, $total, $pending, 1 === $pending ? 'suggestion is' : 'suggestions are' )
        );
    }

    /**
     * Suggestions for one source; replaces its earlier pending suggestions (keeping their ids).
     *
     * @param array<int,array{id:int,anchor:string,url:string,title:string}> $targets
     * @param array<string,mixed> $store
     * @return int new suggestions created
     */
    private function suggestFor( int $sourceId, array $targets, int $max, array &$store ): int {
        $existing = [];
        $blocked  = [];
        $applied  = 0;
        foreach ( $store['items'] as $id => $item ) {
            if ( (int) $item['source_post_id'] !== $sourceId ) {
                continue;
            }
            if ( self::STATUS_PENDING === $item['status'] ) {
                $existing[ (int) $item['target_id'] ] = (int) $id;
            } else {
                $blocked[ (int) $item['target_id'] ] = true; // applied or dismissed pairs are never suggested again
                $applied += self::STATUS_APPLIED === $item['status'] ? 1 : 0;
            }
        }
        $dropPending = function () use ( &$store, $existing ): void {
            foreach ( $existing as $id ) {
                unset( $store['items'][ (string) $id ] );
            }
        };

        $post = get_post( $sourceId );
        if ( ! is_object( $post ) || '' !== $this->sourceProblem( $post ) ) {
            $dropPending();
            return 0;
        }
        $content = (string) ( $post->post_content ?? '' );
        $room    = $max - $applied;
        if ( '' === trim( $content ) || $room <= 0 ) {
            $dropPending();
            return 0;
        }

        $analysis = self::analyse( $content );
        $lower    = mb_strtolower( $analysis['joined'] );
        $linked   = $this->linkedTargets( $content, (string) get_permalink( $sourceId ) );
        $found    = [];
        foreach ( $targets as $t ) {
            $tid = (int) $t['id'];
            if ( $tid === $sourceId || isset( $blocked[ $tid ] ) || isset( $linked['ids'][ $tid ] ) || isset( $linked['paths'][ self::pathKey( (string) $t['url'] ) ] ) ) {
                continue;
            }
            if ( false === mb_strpos( $lower, mb_strtolower( (string) $t['anchor'] ) ) ) {
                continue;
            }
            $occ = self::locate( $analysis, (string) $t['anchor'] );
            if ( null !== $occ ) {
                $found[] = [ 't' => $t, 'o' => $occ ];
            }
        }
        // Reading order; on the same spot the longer anchor wins; no overlaps; at most $room.
        usort( $found, static fn( array $a, array $b ): int => [ $a['o']['start'], $b['o']['end'] ] <=> [ $b['o']['start'], $a['o']['end'] ] );
        $picked = [];
        foreach ( $found as $f ) {
            foreach ( $picked as $p ) {
                if ( $f['o']['start'] < $p['o']['end'] && $p['o']['start'] < $f['o']['end'] ) {
                    continue 2;
                }
            }
            $picked[] = $f;
            if ( count( $picked ) >= $room ) {
                break;
            }
        }
        // Second opinion from DOMDocument: every insertion must land outside the excluded elements.
        $valid  = self::validateInsertions( $content, array_map( static fn( array $p ): array => $p['o'], $picked ) );
        $now    = gmdate( 'c' );
        $title  = self::clean( (string) ( $post->post_title ?? '' ) );
        $url    = (string) get_permalink( $sourceId );
        $keep   = [];
        $new    = 0;
        foreach ( $picked as $i => $p ) {
            if ( empty( $valid[ $i ] ) ) {
                continue;
            }
            $t   = $p['t'];
            $tid = (int) $t['id'];
            $id  = $existing[ $tid ] ?? 0;
            if ( 0 === $id ) {
                if ( count( $store['items'] ) >= self::MAX_ITEMS && ! $this->pruneStore( $store ) ) {
                    break;
                }
                $id = (int) $store['next_id'];
                $store['next_id'] = $id + 1;
                $new++;
            }
            $prev = $store['items'][ (string) $id ] ?? [];
            $store['items'][ (string) $id ] = [
                'id'             => $id,
                'source_post_id' => $sourceId,
                'source_title'   => $title,
                'source_url'     => $url,
                'anchor'         => $p['o']['text'],
                'term'           => (string) $t['anchor'],
                'target_id'      => $tid,
                'target_url'     => (string) $t['url'],
                'target_title'   => (string) $t['title'],
                'excerpt'        => $p['o']['excerpt'],
                'context_hash'   => $p['o']['context_hash'],
                'status'         => self::STATUS_PENDING,
                'created_at'     => (string) ( $prev['created_at'] ?? $now ),
                'updated_at'     => $now,
            ];
            $keep[ $id ] = true;
        }
        foreach ( $existing as $id ) {
            if ( ! isset( $keep[ $id ] ) ) {
                unset( $store['items'][ (string) $id ] );
            }
        }
        return $new;
    }

    /** Why a post cannot be a link source ('' when it can). */
    private function sourceProblem( object $post ): string {
        $id = (int) ( $post->ID ?? 0 );
        if ( 'publish' !== (string) ( $post->post_status ?? '' ) ) {
            return 'The item is no longer published.';
        }
        if ( '' !== (string) ( $post->post_password ?? '' ) ) {
            return 'The item is password protected.';
        }
        $builder = SiteAuditor::builderOf( $id );
        if ( '' !== $builder ) {
            return sprintf( 'The item is built with %s, which keeps its own copy of the layout; add links in the %s editor instead.', $builder, $builder );
        }
        $type = (string) ( $post->post_type ?? '' );
        if ( ! post_type_supports( $type, 'revisions' ) ) {
            return 'WordPress keeps no revisions for this content type, so the change could not be undone from the editor; add the link manually instead.';
        }
        if ( ! wp_revisions_enabled( $post ) ) {
            return 'Revisions are turned off on this site, so the change could not be undone from the editor; add the link manually instead.';
        }
        // The version before the edit and the edited one must both be kept for "restore the previous version".
        $keep = (int) wp_revisions_to_keep( $post );
        if ( $keep >= 0 && $keep < 2 ) {
            return 'WordPress keeps fewer than 2 revisions for this item, so the previous version could not be restored; add the link in the editor instead.';
        }
        return '';
    }

    /**
     * Makes sure the content as it is now is kept as a revision before an edit, and returns that revision's id
     * (0 when none could be made). WordPress itself only saves a revision of the content AFTER an update, so a
     * post that never had one (created through REST, WP-CLI or an importer) would otherwise lose its old content.
     */
    private function restorePoint( int $postId ): int {
        $saved = wp_save_post_revision( $postId );
        if ( is_int( $saved ) && $saved > 0 ) {
            return $saved;
        }
        if ( is_wp_error( $saved ) ) {
            return 0;
        }
        // null: the newest revision already holds exactly the current content.
        $revs = wp_get_post_revisions( $postId, [ 'numberposts' => 1, 'fields' => 'ids' ] );
        if ( is_array( $revs ) && [] !== $revs ) {
            $first = reset( $revs );
            return is_object( $first ) ? (int) ( $first->ID ?? 0 ) : (int) $first;
        }
        return 0;
    }

    /** @return int[] published source ids (pages, posts, products), capped */
    private function sourceIds(): array {
        $max = (int) $this->settings->get( 'seo.audit_max_pages', self::DEFAULT_MAX_SOURCES );
        $max = max( 1, min( 5000, $max > 0 ? $max : self::DEFAULT_MAX_SOURCES ) );
        $ids = [];
        foreach ( [ 'page', 'post', 'product' ] as $type ) {
            if ( count( $ids ) >= $max ) {
                break;
            }
            if ( ! post_type_supports( $type, 'revisions' ) ) {
                continue; // an edit there could not be undone from the editor (WooCommerce products keep no revisions)
            }
            $ids = array_merge( $ids, $this->publishedIds( $type, $max - count( $ids ) ) );
        }
        return array_values( array_unique( $ids ) );
    }

    /**
     * Link targets: products by title, posts/pages by their first Rank Math focus keyword. Anchors shared by more
     * than one target are ambiguous and dropped.
     *
     * @return array<int,array{id:int,anchor:string,url:string,title:string}>
     */
    private function targets(): array {
        $out = [];
        foreach ( $this->publishedIds( 'product', self::MAX_TARGETS ) as $id ) {
            $post = get_post( $id );
            if ( ! is_object( $post ) || '' !== (string) ( $post->post_password ?? '' ) || SiteAuditor::isNoindex( $id, 'product' ) ) {
                continue;
            }
            $title = self::clean( (string) ( $post->post_title ?? '' ) );
            if ( self::usableAnchor( $title ) ) {
                $out[] = [ 'id' => $id, 'anchor' => $title, 'url' => (string) get_permalink( $id ), 'title' => $title ];
            }
        }
        foreach ( [ 'page', 'post' ] as $type ) {
            foreach ( $this->publishedIds( $type, self::MAX_TARGETS ) as $id ) {
                $keyword = SiteAuditor::focusKeyword( $id );
                if ( ! self::usableAnchor( $keyword ) ) {
                    continue;
                }
                $post = get_post( $id );
                if ( ! is_object( $post ) || '' !== (string) ( $post->post_password ?? '' ) || SiteAuditor::isNoindex( $id, $type ) ) {
                    continue;
                }
                $out[] = [ 'id' => $id, 'anchor' => $keyword, 'url' => (string) get_permalink( $id ), 'title' => self::clean( (string) ( $post->post_title ?? '' ) ) ];
            }
        }
        $byAnchor = [];
        foreach ( $out as $t ) {
            $byAnchor[ mb_strtolower( $t['anchor'] ) ][] = $t;
        }
        $targets = [];
        foreach ( $byAnchor as $group ) {
            if ( 1 === count( $group ) && '' !== $group[0]['url'] ) {
                $targets[] = $group[0];
            }
        }
        return array_slice( $targets, 0, self::MAX_TARGETS );
    }

    /** At least 4 characters, a letter, not generic, not a sentence. */
    public static function usableAnchor( string $anchor ): bool {
        $anchor = trim( $anchor );
        if ( mb_strlen( $anchor ) < self::MIN_ANCHOR || mb_strlen( $anchor ) > self::MAX_ANCHOR || ! preg_match( '/\p{L}/u', $anchor ) ) {
            return false;
        }
        if ( count( preg_split( '/\s+/u', $anchor ) ?: [] ) > self::MAX_ANCHOR_WORDS ) {
            return false;
        }
        return ! in_array( mb_strtolower( $anchor ), self::GENERIC, true );
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
     * Post ids and paths the content already links to.
     *
     * @return array{ids:array<int,bool>,paths:array<string,bool>}
     */
    private function linkedTargets( string $content, string $pageUrl ): array {
        $out = [ 'ids' => [], 'paths' => [] ];
        if ( ! preg_match_all( '/<a\s[^>]*?href\s*=\s*(["\']?)([^"\'\s>]+)\1/i', $content, $m ) ) {
            return $out;
        }
        foreach ( $m[2] as $href ) {
            $href = html_entity_decode( (string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $abs  = Url::absolutize( $href, '' !== $pageUrl ? $pageUrl : home_url( '/' ) );
            if ( '' === $abs || ! SiteAuditor::isInternalUrl( $abs ) ) {
                continue;
            }
            $out['paths'][ self::pathKey( $abs ) ] = true;
            if ( str_contains( $abs, '?' ) ) {
                $id = (int) url_to_postid( $abs );
                if ( $id > 0 ) {
                    $out['ids'][ $id ] = true;
                }
            }
        }
        return $out;
    }

    private static function pathKey( string $url ): string {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        return strtolower( rtrim( rawurldecode( $path ), '/' ) );
    }

    // ------------------------------------------------------------------
    // Reading suggestions
    // ------------------------------------------------------------------

    /**
     * @return array<int,array<string,mixed>> suggestions with the given status ('' = all), newest first
     */
    public function suggestions( string $status = self::STATUS_PENDING, int $limit = 500 ): array {
        $items = array_values( $this->store()['items'] );
        if ( '' !== $status ) {
            $items = array_values( array_filter( $items, static fn( array $i ): bool => $status === $i['status'] ) );
        }
        usort( $items, static fn( array $a, array $b ): int => [ (int) $a['source_post_id'], (int) $a['id'] ] <=> [ (int) $b['source_post_id'], (int) $b['id'] ] );
        return array_slice( $items, 0, max( 1, $limit ) );
    }

    /** @return array{pending:int,applied:int,dismissed:int,stale:int} */
    public function counts(): array {
        $out = [ 'pending' => 0, 'applied' => 0, 'dismissed' => 0, 'stale' => 0 ];
        foreach ( $this->store()['items'] as $item ) {
            $s = (string) $item['status'];
            if ( isset( $out[ $s ] ) ) {
                $out[ $s ]++;
            }
        }
        return $out;
    }

    public function pendingCount(): int {
        return $this->counts()['pending'];
    }

    public function generatedAt(): ?string {
        $at = $this->store()['generated_at'] ?? null;
        return is_string( $at ) && '' !== $at ? $at : null;
    }

    /** @return array{running:bool,checked:int,total:int} */
    public function progress(): array {
        $work = get_option( self::OPTION_RUN, [] );
        if ( ! is_array( $work ) || ! isset( $work['sources'] ) ) {
            return [ 'running' => false, 'checked' => 0, 'total' => 0 ];
        }
        return [ 'running' => true, 'checked' => (int) ( $work['cursor'] ?? 0 ), 'total' => count( (array) $work['sources'] ) ];
    }

    // ------------------------------------------------------------------
    // Apply / dismiss (admin click only)
    // ------------------------------------------------------------------

    /**
     * Applies suggestions chosen by an admin. Each one is re-located under the generation rules; nothing is
     * changed when the text changed, the target is already linked, or the source cannot be edited safely.
     *
     * @param int[] $ids
     * @return array{ok:bool,applied:int,refused:int,results:array<int,array{id:int,ok:bool,message:string}>,message:string}
     */
    public function apply( array $ids, int $userId, ?TimeBudget $budget = null ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $i ): bool => $i > 0 ) ) );
        if ( [] === $ids ) {
            return [ 'ok' => false, 'applied' => 0, 'refused' => 0, 'results' => [], 'message' => 'No suggestions were selected.' ];
        }
        if ( ! $this->locks->acquire( self::LOCK, 120 ) ) {
            return [ 'ok' => false, 'applied' => 0, 'refused' => count( $ids ), 'results' => [], 'message' => 'Suggestions are being refreshed right now; nothing was changed. Try again in a minute.' ];
        }
        $results = [];
        $applied = 0;
        try {
            $store = $this->store();
            foreach ( $ids as $id ) {
                if ( null !== $budget && ! $budget->has( 3.0 ) ) {
                    $results[] = [ 'id' => $id, 'ok' => false, 'message' => 'Not attempted: the request ran out of time. Apply it again.' ];
                    continue;
                }
                $item = $store['items'][ (string) $id ] ?? null;
                if ( ! is_array( $item ) ) {
                    $results[] = [ 'id' => $id, 'ok' => false, 'message' => 'Suggestion not found; refresh the list.' ];
                    continue;
                }
                if ( self::STATUS_PENDING !== $item['status'] ) {
                    $results[] = [ 'id' => $id, 'ok' => false, 'message' => sprintf( 'This suggestion is already %s.', $item['status'] ) ];
                    continue;
                }
                try {
                    [ $ok, $message, $status, $extra ] = $this->applyOne( $item, $store, $userId );
                } catch ( Throwable $e ) {
                    $this->logger->error( 'seo', 'Applying an internal link failed: ' . $e->getMessage(), [ 'suggestion' => $id ] );
                    [ $ok, $message, $status, $extra ] = [ false, 'Nothing was changed: an unexpected error occurred (see Logs).', '', [] ];
                }
                if ( '' !== $status ) {
                    $store['items'][ (string) $id ] = array_merge( $item, $extra, [ 'status' => $status, 'updated_at' => gmdate( 'c' ), 'note' => $message ] );
                }
                $applied  += $ok ? 1 : 0;
                $results[] = [ 'id' => $id, 'ok' => $ok, 'message' => $message ];
            }
            $this->saveStore( $store );
        } finally {
            $this->locks->release( self::LOCK );
        }
        $refused = count( $results ) - $applied;
        return [
            'ok'      => $applied > 0,
            'applied' => $applied,
            'refused' => $refused,
            'results' => $results,
            'message' => 0 === $refused
                ? sprintf( 'Added %d internal %s. The previous version of each edited item is kept as a revision you can restore from the editor.', $applied, 1 === $applied ? 'link' : 'links' )
                : sprintf( 'Added %d internal %s; %d %s not applied (see the reason on each row).', $applied, 1 === $applied ? 'link' : 'links', $refused, 1 === $refused ? 'suggestion was' : 'suggestions were' ),
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $store
     * @return array{0:bool,1:string,2:string,3:array<string,mixed>} ok, message, new status ('' = unchanged), extra fields
     */
    private function applyOne( array $item, array $store, int $userId ): array {
        $sourceId = (int) $item['source_post_id'];
        $targetId = (int) $item['target_id'];
        if ( $sourceId === $targetId ) {
            return [ false, 'Refused: an item cannot link to itself.', self::STATUS_STALE, [] ];
        }
        if ( ! current_user_can( 'edit_post', $sourceId ) ) {
            return [ false, 'Refused: your account may not edit this item.', '', [] ];
        }
        $post = get_post( $sourceId );
        if ( ! is_object( $post ) ) {
            return [ false, 'Refused: the item no longer exists.', self::STATUS_STALE, [] ];
        }
        $problem = $this->sourceProblem( $post );
        if ( '' !== $problem ) {
            return [ false, 'Refused: ' . $problem, self::STATUS_STALE, [] ];
        }
        $target = get_post( $targetId );
        if ( ! is_object( $target ) || 'publish' !== (string) ( $target->post_status ?? '' ) ) {
            return [ false, 'Refused: the link target is no longer published.', self::STATUS_STALE, [] ];
        }
        $targetUrl = (string) get_permalink( $targetId );
        if ( '' === $targetUrl ) {
            return [ false, 'Refused: the link target has no address.', self::STATUS_STALE, [] ];
        }
        $appliedHere = 0;
        foreach ( $store['items'] as $other ) {
            $appliedHere += ( self::STATUS_APPLIED === $other['status'] && (int) $other['source_post_id'] === $sourceId ) ? 1 : 0;
        }
        if ( $appliedHere >= $this->maxPerSource() ) {
            return [ false, sprintf( 'Refused: this item already received %d suggested links (the limit per item).', $appliedHere ), '', [] ];
        }

        $content = (string) ( $post->post_content ?? '' );
        $linked  = $this->linkedTargets( $content, (string) get_permalink( $sourceId ) );
        if ( isset( $linked['ids'][ $targetId ] ) || isset( $linked['paths'][ self::pathKey( $targetUrl ) ] ) ) {
            return [ false, 'Refused: the item already links to this target.', self::STATUS_STALE, [] ];
        }
        $occ = self::locate( self::analyse( $content ), (string) ( $item['term'] ?? $item['anchor'] ) );
        if ( null === $occ || $occ['context_hash'] !== (string) $item['context_hash'] ) {
            return [ false, 'Refused: the text changed since this suggestion was made, so nothing was edited. Refresh the suggestions.', self::STATUS_STALE, [] ];
        }

        $new = self::wrap( $content, $occ['start'], $occ['end'], $targetUrl );
        $ok  = self::validateInsertions( $content, [ $occ ] );
        if ( empty( $ok[0] ) ) {
            return [ false, 'Refused: the link would land inside markup that must not contain links, so nothing was edited.', self::STATUS_STALE, [] ];
        }
        if ( ! current_user_can( 'unfiltered_html' ) && wp_kses_post( $new ) !== $new ) {
            return [ false, 'Refused: your account cannot save unfiltered HTML, and WordPress would re-filter other markup in this item. Add the link in the editor instead.', '', [] ];
        }

        $restore = $this->restorePoint( $sourceId );
        if ( $restore <= 0 ) {
            return [ false, 'Refused: WordPress could not keep a revision of the current content, so the previous version could not be restored; nothing was edited.', '', [] ];
        }

        $result = wp_update_post( wp_slash( [ 'ID' => $sourceId, 'post_content' => $new ] ), true );
        if ( is_wp_error( $result ) || (int) $result <= 0 ) {
            $why = is_wp_error( $result ) ? $result->get_error_message() : 'WordPress did not save the item';
            return [ false, 'Nothing was changed: ' . $why . '.', '', [] ];
        }

        $saved    = get_post( $sourceId );
        $stored   = is_object( $saved ) ? (string) ( $saved->post_content ?? '' ) : '';
        $revision = 0;
        $revs = wp_get_post_revisions( $sourceId, [ 'numberposts' => 1, 'fields' => 'ids' ] );
        if ( is_array( $revs ) && [] !== $revs ) {
            $first    = reset( $revs );
            $revision = is_object( $first ) ? (int) ( $first->ID ?? 0 ) : (int) $first;
        }
        $message = $stored === $new
            ? sprintf( 'Linked “%s” to %s.', $occ['text'], $targetUrl )
            : sprintf( 'Linked “%s” to %s, but WordPress or another plugin also changed other markup while saving; review the latest revision.', $occ['text'], $targetUrl );
        $this->audit?->record(
            'seo.internal_link_applied',
            'post',
            $sourceId,
            [ 'suggestion_id' => (int) $item['id'], 'target_id' => $targetId, 'target_url' => $targetUrl, 'anchor' => $occ['text'], 'restore_revision_id' => $restore, 'revision_id' => $revision, 'byte_identical' => $stored === $new ]
        );
        $this->logger->info( 'seo', 'Internal link applied', [ 'post_id' => $sourceId, 'target_id' => $targetId, 'anchor' => $occ['text'], 'restore_revision_id' => $restore ] );
        return [ true, $message, self::STATUS_APPLIED, [ 'applied_at' => gmdate( 'c' ), 'applied_by' => $userId, 'restore_revision_id' => $restore, 'revision_id' => $revision, 'target_url' => $targetUrl ] ];
    }

    /**
     * @param int[] $ids
     * @return array{ok:bool,dismissed:int,message:string}
     */
    public function dismiss( array $ids, int $userId ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $i ): bool => $i > 0 ) ) );
        if ( [] === $ids ) {
            return [ 'ok' => false, 'dismissed' => 0, 'message' => 'No suggestions were selected.' ];
        }
        if ( ! $this->locks->acquire( self::LOCK, 60 ) ) {
            return [ 'ok' => false, 'dismissed' => 0, 'message' => 'Suggestions are being refreshed right now; try again in a minute.' ];
        }
        $n = 0;
        try {
            $store = $this->store();
            foreach ( $ids as $id ) {
                $item = $store['items'][ (string) $id ] ?? null;
                if ( is_array( $item ) && in_array( $item['status'], [ self::STATUS_PENDING, self::STATUS_STALE ], true ) ) {
                    $store['items'][ (string) $id ] = array_merge( $item, [ 'status' => self::STATUS_DISMISSED, 'dismissed_at' => gmdate( 'c' ), 'dismissed_by' => $userId, 'updated_at' => gmdate( 'c' ) ] );
                    $n++;
                }
            }
            $this->saveStore( $store );
        } finally {
            $this->locks->release( self::LOCK );
        }
        if ( $n > 0 ) {
            $this->audit?->record( 'seo.internal_link_dismissed', 'seo', 0, [ 'ids' => $ids, 'count' => $n ] );
        }
        return [ 'ok' => $n > 0, 'dismissed' => $n, 'message' => $n > 0 ? sprintf( 'Dismissed %d %s; %s will not be suggested again.', $n, 1 === $n ? 'suggestion' : 'suggestions', 1 === $n ? 'it' : 'they' ) : 'Nothing was dismissed (the suggestions were already handled).' ];
    }

    // ------------------------------------------------------------------
    // Text engine
    // ------------------------------------------------------------------

    /**
     * Splits raw HTML into text segments with byte offsets. A segment is "eligible" when it is outside every
     * NO_LINK_TAGS element and every opaque block (see opaqueBlock()), "visible" when it is not raw text
     * (script/style/…). `attrs` is the lower-cased text of the enclosing blocks' comment attributes (locate()
     * refuses a match that also appears there). Comments, tags, doctype and processing instructions are not segments.
     *
     * @return array<int,array{start:int,end:int,eligible:bool,visible:bool,attrs:string}>
     */
    public static function segments( string $html ): array {
        $out    = [];
        $len    = strlen( $html );
        $stack  = [];
        $blocks = []; // open blocks: [name, opaque, attribute text]
        $text   = 0;
        $i      = 0;
        $free   = static function () use ( &$stack, &$blocks ): bool {
            if ( [] !== $stack ) {
                return false;
            }
            foreach ( $blocks as $b ) {
                if ( $b[1] ) {
                    return false;
                }
            }
            return true;
        };
        $attrs  = static function () use ( &$blocks ): string {
            return self::attrText( $blocks );
        };
        while ( $i < $len ) {
            $lt = strpos( $html, '<', $i );
            if ( false === $lt ) {
                break;
            }
            $next = $html[ $lt + 1 ] ?? '';
            if ( '!' === $next || '?' === $next ) {
                if ( 0 === substr_compare( $html, '<!--', $lt, 4 ) ) {
                    $close = strpos( $html, '-->', $lt + 4 );
                    $after = false === $close ? $len : $close + 3;
                } else {
                    $close = strpos( $html, '>', $lt + 2 );
                    $after = false === $close ? $len : $close + 1;
                }
                self::emit( $out, $text, $lt, $free(), true, $attrs() );
                if ( '!' === $next && preg_match( self::BLOCK_COMMENT, $html, $bm, 0, $lt ) ) {
                    self::trackBlock( $blocks, $bm );
                }
                $i = $text = $after;
                continue;
            }
            if ( ! preg_match( '/\G<(\/?)([A-Za-z][A-Za-z0-9:\-]*)/', $html, $m, 0, $lt ) ) {
                $i = $lt + 1; // a literal "<" in text
                continue;
            }
            // End of the tag: the first ">" outside quoted attribute values.
            $j     = $lt + strlen( $m[0] );
            $quote = '';
            while ( $j < $len ) {
                $c = $html[ $j ];
                if ( '' !== $quote ) {
                    if ( $c === $quote ) {
                        $quote = '';
                    }
                } elseif ( '"' === $c || "'" === $c ) {
                    $quote = $c;
                } elseif ( '>' === $c ) {
                    break;
                }
                $j++;
            }
            $tagEnd  = min( $len, $j + 1 );
            $name    = strtolower( $m[2] );
            $closing = '/' === $m[1];
            self::emit( $out, $text, $lt, $free(), true, $attrs() );
            if ( ! $closing && in_array( $name, self::RAW_TEXT_TAGS, true ) ) {
                if ( preg_match( '#</' . preg_quote( $name, '#' ) . '\s*>#i', $html, $cm, PREG_OFFSET_CAPTURE, $tagEnd ) ) {
                    $closeAt  = (int) $cm[0][1];
                    $closeEnd = $closeAt + strlen( (string) $cm[0][0] );
                } else {
                    $closeAt = $closeEnd = $len;
                }
                self::emit( $out, $tagEnd, $closeAt, false, false, '' );
                $i = $text = $closeEnd;
                continue;
            }
            if ( in_array( $name, self::NO_LINK_TAGS, true ) ) {
                if ( $closing ) {
                    for ( $k = count( $stack ) - 1; $k >= 0; $k-- ) {
                        if ( $stack[ $k ] === $name ) {
                            array_splice( $stack, $k );
                            break;
                        }
                    }
                } elseif ( ! ( in_array( $name, self::FOREIGN_TAGS, true ) && self::selfClosingFlag( $html, $lt + strlen( $m[0] ), $j ) ) ) {
                    // HTML5 ignores "/>" on <a>, <h2>, <code>…: the element stays open (only SVG/MathML self-close).
                    $stack[] = $name;
                }
            }
            $i = $text = $tagEnd;
        }
        self::emit( $out, $text, $len, $free(), true, $attrs() );
        return $out;
    }

    /**
     * Whether the "/" before the ">" at $gt is a real self-closing flag: right after the tag name, or after
     * whitespace or a closing quote. `<svg viewBox=0/>` ends an unquoted attribute value instead.
     */
    private static function selfClosingFlag( string $html, int $nameEnd, int $gt ): bool {
        if ( $gt <= $nameEnd || '/' !== ( $html[ $gt - 1 ] ?? '' ) ) {
            return false;
        }
        if ( $gt - 1 === $nameEnd ) {
            return true;
        }
        $before = $html[ $gt - 2 ] ?? '';
        return '"' === $before || "'" === $before || ctype_space( $before );
    }

    /**
     * Opens or closes a block from a matched block comment (BLOCK_COMMENT).
     *
     * @param array<int,array{0:string,1:bool,2:string}> $blocks
     * @param array<string,string>                       $m
     */
    private static function trackBlock( array &$blocks, array $m ): void {
        $name = ( '' !== ( $m['namespace'] ?? '' ) ? $m['namespace'] : 'core/' ) . $m['name'];
        if ( '' !== ( $m['closer'] ?? '' ) ) {
            for ( $k = count( $blocks ) - 1; $k >= 0; $k-- ) {
                if ( $blocks[ $k ][0] === $name ) {
                    array_splice( $blocks, $k );
                    break;
                }
            }
            return;
        }
        if ( '' !== ( $m['void'] ?? '' ) ) {
            return; // self-closing block: no inner HTML
        }
        $blocks[] = [ $name, self::opaqueBlock( $name ), self::flattenAttrs( (string) ( $m['attrs'] ?? '' ) ) ];
    }

    /**
     * Whether a block's saved inner HTML is not what visitors see: Rank Math's FAQ / HowTo / TOC blocks (built
     * from their attributes), and any other non-core block registered with a render callback. Core blocks render
     * their saved content (their callbacks only decorate it), so they stay eligible.
     */
    public static function opaqueBlock( string $name ): bool {
        if ( in_array( $name, self::OPAQUE_BLOCKS, true ) ) {
            return true;
        }
        if ( str_starts_with( $name, 'core/' ) || ! class_exists( 'WP_Block_Type_Registry' ) ) {
            return false;
        }
        try {
            $type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
            return is_object( $type ) && method_exists( $type, 'is_dynamic' ) && (bool) $type->is_dynamic();
        } catch ( Throwable $e ) {
            return false;
        }
    }

    /** Lower-cased text of every string value in a block's comment attributes ('' when none). */
    private static function flattenAttrs( string $json ): string {
        $json = trim( $json );
        if ( '' === $json ) {
            return '';
        }
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            return '';
        }
        $parts = [];
        array_walk_recursive(
            $data,
            static function ( mixed $v ) use ( &$parts ): void {
                if ( is_string( $v ) && '' !== $v ) {
                    $parts[] = $v;
                }
            }
        );
        $text = html_entity_decode( strip_tags( implode( ' ', $parts ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return mb_substr( mb_strtolower( self::normalizeSpace( $text ) ), 0, self::MAX_ATTR_TEXT );
    }

    /** @param array<int,array{0:string,1:bool,2:string}> $blocks */
    private static function attrText( array $blocks ): string {
        $parts = [];
        foreach ( $blocks as $b ) {
            if ( '' !== $b[2] ) {
                $parts[] = $b[2];
            }
        }
        return implode( ' | ', $parts );
    }

    /** @param array<int,array{start:int,end:int,eligible:bool,visible:bool,attrs:string}> $out */
    private static function emit( array &$out, int $start, int $end, bool $eligible, bool $visible, string $attrs ): void {
        if ( $end > $start ) {
            $out[] = [ 'start' => $start, 'end' => $end, 'eligible' => $eligible, 'visible' => $visible, 'attrs' => $attrs ];
        }
    }

    /**
     * Decoded visible text of the content with per-segment offset maps (used by locate()).
     *
     * @return array{html:string,segments:array<int,array<string,mixed>>,joined:string,spaced:string,shortcodes:array<int,array{0:int,1:int}>}
     */
    public static function analyse( string $html ): array {
        $segments = [];
        $joined   = '';
        $spaced   = '';
        $prevEnd  = -1;
        foreach ( self::segments( $html ) as $seg ) {
            if ( ! $seg['visible'] ) {
                continue;
            }
            $raw = substr( $html, $seg['start'], $seg['end'] - $seg['start'] );
            [ $decoded, $map ] = self::decodeWithMap( $raw );
            $seg['join'] = false; // reads as one word with the previous visible segment
            if ( $prevEnd >= 0 && $seg['start'] !== $prevEnd ) {
                $seg['join'] = self::inlineGap( substr( $html, $prevEnd, $seg['start'] - $prevEnd ) );
                if ( ! $seg['join'] ) {
                    $spaced .= ' ';
                }
            }
            $seg['decoded'] = $decoded;
            $seg['map']     = $map;
            $seg['joined']  = strlen( $joined );
            $seg['spaced']  = strlen( $spaced );
            $joined        .= $decoded;
            $spaced        .= $decoded;
            $prevEnd        = $seg['end'];
            $segments[]     = $seg;
        }
        return [ 'html' => $html, 'segments' => $segments, 'joined' => $joined, 'spaced' => $spaced, 'shortcodes' => SiteAuditor::shortcodeRanges( $html ) ];
    }

    /**
     * The first eligible whole-word, case-insensitive occurrence of $anchor.
     *
     * @param array<string,mixed> $analysis from analyse()
     * @return array{start:int,end:int,text:string,context_hash:string,excerpt:string}|null byte offsets into the raw HTML
     */
    public static function locate( array $analysis, string $anchor ): ?array {
        $anchor = trim( $anchor );
        if ( '' === $anchor ) {
            return null;
        }
        $words   = preg_split( '/\s+/u', $anchor ) ?: [];
        // Letters, digits, "_" and "-" around the match mean it is part of a longer word ("non-sterile water-like").
        $pattern = '/(?<![\p{L}\p{N}_\-])' . implode( '[\s\x{00A0}]+', array_map( static fn( string $w ): string => preg_quote( $w, '/' ), $words ) ) . '(?![\p{L}\p{N}_\-])/iu';
        $segments = array_values( $analysis['segments'] );
        $needle   = mb_strtolower( self::normalizeSpace( $anchor ) );
        foreach ( $segments as $n => $seg ) {
            if ( ! $seg['eligible'] || '' === $seg['decoded'] ) {
                continue;
            }
            // The same words in the enclosing block's attributes: the block may render from them, not this HTML.
            if ( '' !== (string) ( $seg['attrs'] ?? '' ) && str_contains( (string) $seg['attrs'], $needle ) ) {
                continue;
            }
            $offset = 0;
            while ( 1 === @preg_match( $pattern, $seg['decoded'], $m, PREG_OFFSET_CAPTURE, $offset ) ) {
                $dStart = (int) $m[0][1];
                $dEnd   = $dStart + strlen( (string) $m[0][0] );
                $offset = $dEnd;
                $raw    = self::mapRange( $seg['map'], $dStart, $dEnd );
                if ( null === $raw ) {
                    continue;
                }
                // Whole word across inline tags: "abc<b>Word</b>" or "<b>Word</b>s" is part of a longer word.
                if ( 0 === $dStart && ! empty( $seg['join'] ) && $n > 0 && preg_match( '/[\p{L}\p{N}_\-]$/u', (string) $segments[ $n - 1 ]['decoded'] ) ) {
                    continue;
                }
                if ( strlen( (string) $seg['decoded'] ) === $dEnd && isset( $segments[ $n + 1 ] ) && ! empty( $segments[ $n + 1 ]['join'] ) && preg_match( '/^[\p{L}\p{N}_\-]/u', (string) $segments[ $n + 1 ]['decoded'] ) ) {
                    continue;
                }
                $start = $seg['start'] + $raw[0];
                $end   = $seg['start'] + $raw[1];
                foreach ( $analysis['shortcodes'] as [ $s, $e ] ) {
                    if ( $start < $e && $s < $end ) {
                        continue 2;
                    }
                }
                $jStart = (int) $seg['joined'] + $dStart;
                $sStart = (int) $seg['spaced'] + $dStart;
                $mLen   = $dEnd - $dStart;
                return [
                    'start'        => $start,
                    'end'          => $end,
                    'text'         => self::normalizeSpace( (string) $m[0][0] ),
                    'context_hash' => self::contextHash( (string) $analysis['joined'], $jStart, $mLen ),
                    'excerpt'      => self::excerpt( (string) $analysis['spaced'], $sStart, $mLen ),
                ];
            }
        }
        return null;
    }

    /** Whether the markup between two text segments consists only of inline tags (and comments). */
    private static function inlineGap( string $gap ): bool {
        $rest = preg_replace( '/<!--.*?-->/s', '', $gap ) ?? $gap;
        if ( ! preg_match_all( '/<\/?([A-Za-z][A-Za-z0-9:\-]*)[^>]*>/', $rest, $m ) ) {
            return '' === trim( $rest );
        }
        if ( '' !== trim( (string) preg_replace( '/<\/?[A-Za-z][A-Za-z0-9:\-]*[^>]*>/', '', $rest ) ) ) {
            return false;
        }
        foreach ( $m[1] as $name ) {
            if ( ! in_array( strtolower( (string) $name ), self::INLINE_TAGS, true ) ) {
                return false;
            }
        }
        return true;
    }

    /** Wraps bytes [start, end) of $html in a link; every other byte is unchanged. */
    public static function wrap( string $html, int $start, int $end, string $url ): string {
        return substr( $html, 0, $start ) . '<a href="' . esc_url( $url ) . '">' . substr( $html, $start, $end - $start ) . '</a>' . substr( $html, $end );
    }

    /**
     * Checks planned insertions with DOMDocument: each probe link must exist, contain exactly the matched text and
     * have no ancestor among NO_LINK_TAGS.
     *
     * @param array<int,array{start:int,end:int,text:string}> $occurrences
     * @return array<int,bool> index => valid
     */
    public static function validateInsertions( string $html, array $occurrences ): array {
        $valid = [];
        if ( [] === $occurrences ) {
            return $valid;
        }
        $order = array_keys( $occurrences );
        usort( $order, static fn( int $a, int $b ): int => $occurrences[ $b ]['start'] <=> $occurrences[ $a ]['start'] );
        $probe = $html;
        foreach ( $order as $i ) {
            $o     = $occurrences[ $i ];
            $probe = substr( $probe, 0, $o['start'] ) . '<a href="#" data-ppclg-probe="' . (int) $i . '">' . substr( $probe, $o['start'], $o['end'] - $o['start'] ) . '</a>' . substr( $probe, $o['end'] );
            $valid[ $i ] = false;
        }
        // libxml2 honours "/>" on any element (HTML5 does not), so the DOM alone can miss an open <a> or heading:
        // the occurrence must also lie inside one eligible segment of the HTML5-style tokenizer.
        $inEligible = [];
        $segments   = self::segments( $html );
        foreach ( $occurrences as $i => $o ) {
            $inEligible[ $i ] = false;
            foreach ( $segments as $seg ) {
                if ( $seg['eligible'] && $seg['visible'] && $seg['start'] <= $o['start'] && $o['end'] <= $seg['end'] ) {
                    $inEligible[ $i ] = true;
                    break;
                }
            }
        }
        $dom = SiteAuditor::loadFragment( $probe );
        if ( null === $dom ) {
            return $valid; // no DOM extension: refuse rather than guess
        }
        [ , $root, $xp ] = $dom;
        $nodes = $xp->query( './/a[@data-ppclg-probe]', $root );
        foreach ( false !== $nodes ? $nodes : [] as $a ) {
            if ( ! $a instanceof \DOMElement ) {
                continue;
            }
            $i = (int) $a->getAttribute( 'data-ppclg-probe' );
            if ( ! isset( $occurrences[ $i ] ) ) {
                continue;
            }
            $ok = ! empty( $inEligible[ $i ] ) && self::normalizeSpace( $a->textContent ) === self::normalizeSpace( $occurrences[ $i ]['text'] ) && 0 === $a->getElementsByTagName( 'a' )->length;
            for ( $p = $a->parentNode; $ok && $p instanceof \DOMElement && ! $p->isSameNode( $root ); $p = $p->parentNode ) {
                if ( in_array( strtolower( $p->nodeName ), self::NO_LINK_TAGS, true ) ) {
                    $ok = false;
                }
            }
            $valid[ $i ] = $ok;
        }
        return $valid;
    }

    /**
     * Decodes HTML entities in a raw text segment, remembering where each decoded piece came from.
     *
     * @return array{0:string,1:array<int,array{0:int,1:int,2:int,3:int,4:bool}>} decoded, pieces [decStart, decEnd, rawStart, rawEnd, isEntity]
     */
    private static function decodeWithMap( string $raw ): array {
        $parts = preg_split( '/(&(?:#[0-9]{1,7}|#[xX][0-9A-Fa-f]{1,6}|[A-Za-z][A-Za-z0-9]{1,31});)/', $raw, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE );
        $decoded = '';
        $map     = [];
        foreach ( is_array( $parts ) ? $parts : [ [ $raw, 0 ] ] as [ $str, $off ] ) {
            $str = (string) $str;
            if ( '' === $str ) {
                continue;
            }
            $entity = '&' === $str[0] && str_ends_with( $str, ';' );
            $dec    = $entity ? html_entity_decode( $str, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $str;
            if ( $entity && $dec === $str ) {
                $entity = false; // unknown entity: literal text
            }
            $map[]    = [ strlen( $decoded ), strlen( $decoded ) + strlen( $dec ), (int) $off, (int) $off + strlen( $str ), $entity ];
            $decoded .= $dec;
        }
        return [ $decoded, $map ];
    }

    /**
     * Raw byte range for a decoded range; null when a boundary falls inside a decoded entity.
     *
     * @param array<int,array{0:int,1:int,2:int,3:int,4:bool}> $map
     * @return array{0:int,1:int}|null
     */
    private static function mapRange( array $map, int $dStart, int $dEnd ): ?array {
        $rStart = null;
        $rEnd   = null;
        foreach ( $map as [ $ds, $de, $rs, $re, $entity ] ) {
            if ( null === $rStart && $dStart >= $ds && $dStart < $de ) {
                if ( $entity && $dStart !== $ds ) {
                    return null;
                }
                $rStart = $entity ? $rs : $rs + ( $dStart - $ds );
            }
            if ( $dEnd > $ds && $dEnd <= $de ) {
                if ( $entity && $dEnd !== $de ) {
                    return null;
                }
                $rEnd = $entity ? $re : $rs + ( $dEnd - $ds );
                break;
            }
        }
        return null === $rStart || null === $rEnd ? null : [ $rStart, $rEnd ];
    }

    private static function contextHash( string $joined, int $start, int $length ): string {
        $from   = max( 0, $start - self::CONTEXT_BYTES );
        $window = mb_strcut( $joined, $from, ( $start - $from ) + $length + self::CONTEXT_BYTES, 'UTF-8' );
        return md5( self::normalizeSpace( $window ) );
    }

    private static function excerpt( string $spaced, int $start, int $length ): string {
        $from   = max( 0, $start - self::EXCERPT_BYTES );
        $total  = strlen( $spaced );
        $window = mb_strcut( $spaced, $from, ( $start - $from ) + $length + self::EXCERPT_BYTES, 'UTF-8' );
        $window = self::normalizeSpace( $window );
        $lead   = $from > 0;
        $trail  = $start + $length + self::EXCERPT_BYTES < $total;
        if ( $lead ) {
            $window = preg_replace( '/^\S*\s/u', '', $window, 1 ) ?? $window;
        }
        if ( $trail ) {
            $window = preg_replace( '/\s\S*$/u', '', $window, 1 ) ?? $window;
        }
        return ( $lead ? '… ' : '' ) . $window . ( $trail ? ' …' : '' );
    }

    private static function normalizeSpace( string $text ): string {
        return trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) ?? $text );
    }

    private static function clean( string $text ): string {
        $text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return self::normalizeSpace( $text );
    }

    // ------------------------------------------------------------------
    // Store
    // ------------------------------------------------------------------

    /** @return array{next_id:int,generated_at:?string,items:array<string,array<string,mixed>>} */
    private function store(): array {
        $raw   = get_option( self::OPTION, [] );
        $raw   = is_array( $raw ) ? $raw : [];
        $items = [];
        foreach ( (array) ( $raw['items'] ?? [] ) as $id => $item ) {
            if ( is_array( $item ) && (int) $id > 0 && isset( $item['status'], $item['source_post_id'], $item['target_id'] ) ) {
                $items[ (string) (int) $id ] = $item;
            }
        }
        $next = max( 1, (int) ( $raw['next_id'] ?? 1 ) );
        foreach ( array_keys( $items ) as $id ) {
            $next = max( $next, (int) $id + 1 );
        }
        return [ 'next_id' => $next, 'generated_at' => isset( $raw['generated_at'] ) && is_string( $raw['generated_at'] ) ? $raw['generated_at'] : null, 'items' => $items ];
    }

    /** @param array<string,mixed> $store */
    private function saveStore( array $store ): void {
        update_option( self::OPTION, $store, false );
    }

    /**
     * Frees room by dropping the oldest stale entries, then the oldest dismissed ones.
     *
     * @param array<string,mixed> $store
     */
    private function pruneStore( array &$store ): bool {
        foreach ( [ self::STATUS_STALE, self::STATUS_DISMISSED ] as $status ) {
            foreach ( $store['items'] as $id => $item ) {
                if ( $status === $item['status'] ) {
                    unset( $store['items'][ $id ] );
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function runOutcome( bool $ok, bool $skipped, string $reason, int $checked, int $total, int $targets, int $suggested, bool $more, string $summary ): array {
        return [
            'ok'        => $ok,
            'skipped'   => $skipped,
            'reason'    => $reason,
            'checked'   => $checked,
            'total'     => $total,
            'targets'   => $targets,
            'suggested' => $suggested,
            'pending'   => $this->pendingCount(),
            'more'      => $more,
            'summary'   => $summary,
        ];
    }
}
