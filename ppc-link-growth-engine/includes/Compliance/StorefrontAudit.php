<?php
/**
 * Storefront compliance preflight.
 *
 * Listings and outreach describe the business as a research-use-only supplier of laboratory reagents. When the
 * storefront itself presents the products for consumer use (weight loss, skincare, nasal sprays, capsules,
 * syringes, dosing…), every automated listing and email would misrepresent the business. This audit reads the
 * storefront from the WordPress database only (no HTTP, never edits anything):
 *
 * - WooCommerce active: product categories and tags (name, slug, description), published product titles and
 *   short descriptions, and the items of every assigned nav menu location;
 * - otherwise: published page and post titles.
 *
 * Text is matched case-insensitively on whole words/phrases (hyphens, underscores and slashes count as spaces;
 * HTML entities such as &amp; are decoded; listed plurals are matched) against a BLOCK list (consumer-use
 * framing) and a WARN list (ambiguous wording). Phrases governed by a negation ("Not for human use") are not
 * findings. Product titles that are consumer dosage forms block, and short descriptions also go through
 * Content\ClaimsFilter (therapeutic / human-use violations block, the rest warn).
 *
 * The result is stored in the non-autoloaded option ppclg_storefront_audit. How the store presents itself is the
 * owner's legal decision: the owner may acknowledge the current findings (by hash); any change in the findings
 * re-blocks. While the effective status is "block" and the gate (settings compliance.storefront_gate) is on,
 * automated submissions (Submission\Policy) and outreach sending (Outreach\OutreachEngine) are paused. When the
 * check cannot run, isBlocking() fails closed.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Compliance;

use PPC\LinkGrowth\Content\ClaimsFilter;
use PPC\LinkGrowth\Plugin;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use RuntimeException;
use Throwable;

final class StorefrontAudit {

    /** Stored result (non-autoloaded). */
    public const OPTION = 'ppclg_storefront_audit';

    /** Present (any value) when a product, product term or menu changed after the last scan. */
    public const STALE_OPTION = 'ppclg_storefront_audit_stale';

    public const STATUS_PASS  = 'pass';
    public const STATUS_WARN  = 'warn';
    public const STATUS_BLOCK = 'block';

    public const EFFECTIVE_ACKNOWLEDGED = 'acknowledged';
    public const EFFECTIVE_UNKNOWN      = 'unknown';

    public const SEVERITY_BLOCK = 'block';
    public const SEVERITY_WARN  = 'warn';

    /** Findings kept in the stored result (counts and hash cover all of them). */
    public const MAX_FINDINGS = 200;

    /** A stored result older than this is refreshed before the gate relies on it. */
    public const MAX_AGE_SECONDS = 86400;

    /** How long one isBlocking() answer is reused inside a request / long-running job. */
    private const MEMO_SECONDS = 60;

    /** Posts read per get_posts() batch (keeps memory low on large catalogues). */
    private const BATCH = 100;

    /** Upper bounds per source so one scan stays cheap. */
    private const MAX_POSTS = 5000;
    private const MAX_TERMS = 2000;

    /** Characters of one text field that are scanned. */
    private const MAX_TEXT = 5000;

    /** Characters of context kept per finding. */
    private const EXCERPT_LENGTH = 160;

    /** Where a finding was made: label for sentences. */
    private const WHERE_LABELS = [
        'category' => 'product category',
        'tag'      => 'product tag',
        'product'  => 'product',
        'menu'     => 'menu item',
        'page'     => 'page',
        'post'     => 'post',
    ];

    /** Sort order of the "where" values in the findings list. */
    private const WHERE_ORDER = [ 'category' => 0, 'tag' => 1, 'menu' => 2, 'product' => 3, 'page' => 4, 'post' => 5 ];

    /**
     * Consumer-use framing that contradicts the research-use-only positioning (canonical term => pattern).
     * In patterns a space is a required separator and "~" an optional one (space, hyphen, underscore, slash).
     */
    private const BLOCK_TERMS = [
        'weight loss'   => 'weight~loss(?:es)?',
        'fat loss'      => 'fat~loss',
        'fat burn'      => 'fat~burn(?:s|er|ers|ing)?|burn(?:s|ing)? fat',
        'slimming'      => 'slimming',
        'anti-aging'    => 'anti~ag(?:e|es|ing|eing)',
        'tanning'       => 'tanning',
        'skincare'      => 'skin~care',
        'beauty'        => 'beauty',
        'cosmetic'      => 'cosmetics?',
        'nasal spray'   => 'nasal sprays?',
        'capsule'       => 'capsules?',
        'injectable'    => 'injectables?',
        'injection'     => 'injections?',
        'syringe'       => 'syringes?',
        'needle'        => 'needles?',
        'pen injector'  => 'pen injectors?|injector pens?',
        'dosage'        => 'dosages?',
        'dosing'        => 'dosing',
        'dose'          => 'doses?',
        'libido'        => 'libido',
        'sexual'        => 'sexual(?:ly)?',
        'muscle growth' => 'muscle growth',
        'muscle gain'   => 'muscle gains?',
        'bodybuilding'  => 'body~build(?:ing|ers?)',
        'nootropic'     => 'nootropics?',
        'supplement'    => 'supplements?|supplementation',
        'wellness'      => 'wellness',
        'for human'     => 'for humans?',
        'human use'     => 'human use',
    ];

    /** Ambiguous wording that the owner should review (does not block). */
    private const WARN_TERMS = [
        'recovery'  => 'recover(?:y|ies)',
        'growth'    => 'growth',
        'cognitive' => 'cognitive',
        'metabolic' => 'metabolic',
        'longevity' => 'longevity',
        'healing'   => 'healing',
    ];

    /** Product titles that are consumer dosage forms (block; product titles only). */
    private const DOSAGE_FORMS = [
        'facial toner'    => '(?:facial|face) toners?',
        'facial cleanser' => '(?:facial|face) cleansers?',
        'facial serum'    => '(?:facial|face|eye|skin) serums?',
        'facial cream'    => '(?:facial|face|eye|skin|night|day) creams?',
        'toner'           => 'toners?',
        'cleanser'        => 'cleansers?',
        'moisturizer'     => 'moisturi[sz]ers?',
        'softgel'         => 'soft~gels?',
        'gummies'         => 'gumm(?:y|ies)',
        'lozenge'         => 'lozenges?',
        'oral spray'      => 'oral sprays?',
        'sublingual'      => 'sublingual',
    ];

    /** ClaimsFilter rules whose violations block (all other rules warn). */
    private const CLAIMS_BLOCK_RULES = [ 'therapeutic', 'human_use' ];

    /** Negations that govern a phrase right after them ("not for human use", "no dosing guidance"). */
    private const NEGATION = '(?:not|no|never|without|nor|neither)';

    /** Words that end a negation's scope (same list as Content\ClaimsFilter). */
    private const SCOPE_BREAKERS = 'and|but|so|yet|then|also|plus|it|its|they|their|them|we|our|us|you|your|he|she|this|these|those|that|which|who|while|because|just|only|even|merely|simply|fail|fails|failed|doubt|joke|kidding|problem|problems|wonder|surprise|longer|more|less|matter|worries|worry|need|needs|needed|prescription|pressure|question|questions|risk|risks|side|hassle|wait|waiting|other|else';

    /** @var array<string,array<string,string>>|null compiled regexes per list */
    private static ?array $compiled = null;

    /** @var array{at:int,blocking:bool,error:string}|null last isBlocking() answer */
    private ?array $memo = null;

    public function __construct(
        private ?Settings $settings = null,
        private ?Logger $logger = null,
        private ?ClaimsFilter $claims = null,
        private ?AuditLog $auditLog = null
    ) {}

    // ------------------------------------------------------------------
    // Public API (see the cross-builder contract)
    // ------------------------------------------------------------------

    /**
     * Scans the storefront now (database only), stores and returns the result.
     *
     * @return array{status:string,checked_at:string,hash:string,counts:array{block:int,warn:int},findings:array<int,array<string,mixed>>}&array<string,mixed>
     * @throws Throwable When the storefront cannot be read (the gate then fails closed).
     */
    public function run(): array {
        // Cleared before reading, so a product saved while this scan runs marks the result stale again.
        delete_option( self::STALE_OPTION );
        $this->memo = null;
        try {
            $scan = $this->scan();
        } catch ( Throwable $e ) {
            add_option( self::STALE_OPTION, gmdate( 'c' ), '', false );
            $this->log( 'error', 'The storefront compliance check could not read the storefront: ' . $e->getMessage() );
            throw $e;
        }

        $all    = self::sortFindings( $scan['findings'] );
        $counts = [ 'block' => 0, 'warn' => 0 ];
        foreach ( $all as $f ) {
            $counts[ self::SEVERITY_BLOCK === $f['severity'] ? 'block' : 'warn' ]++;
        }
        $status = $counts['block'] > 0 ? self::STATUS_BLOCK : ( $counts['warn'] > 0 ? self::STATUS_WARN : self::STATUS_PASS );

        $result = [
            'status'      => $status,
            'checked_at'  => gmdate( 'c' ),
            'hash'        => self::hashFindings( $all ),
            'counts'      => $counts,
            'findings'    => array_slice( $all, 0, self::MAX_FINDINGS ),
            'total'       => count( $all ),
            'truncated'   => count( $all ) > self::MAX_FINDINGS,
            'woocommerce' => $scan['woocommerce'],
            'scanned'     => $scan['scanned'],
        ];
        update_option( self::OPTION, $result, false );

        if ( self::STATUS_PASS === $status ) {
            $this->log( 'debug', 'Storefront compliance check passed.', [ 'scanned' => $scan['scanned'] ] );
        } else {
            $this->log( 'info', sprintf( 'Storefront compliance check: %s (%d blocking, %d to review).', $status, $counts['block'], $counts['warn'] ), [ 'hash' => $result['hash'], 'scanned' => $scan['scanned'] ] );
        }
        return $result;
    }

    /**
     * The stored result, or [] when the check never ran.
     *
     * @return array<string,mixed>
     */
    public function current(): array {
        $stored = get_option( self::OPTION, [] );
        if ( ! is_array( $stored ) || ! in_array( $stored['status'] ?? null, [ self::STATUS_PASS, self::STATUS_WARN, self::STATUS_BLOCK ], true ) ) {
            return [];
        }
        return $stored;
    }

    /**
     * The stored result, refreshed first when it is missing, older than 24 h or marked stale.
     *
     * @return array<string,mixed>
     */
    public function fresh(): array {
        $current = $this->current();
        return $this->needsRefresh( $current ) ? $this->run() : $current;
    }

    /** 'pass' | 'warn' | 'block' | 'acknowledged' | 'unknown' (never run). Never scans. */
    public function effectiveStatus(): string {
        return $this->effectiveFor( $this->current() );
    }

    /**
     * True when the gate is on and the effective status is "block". Refreshes a missing/old/stale result first.
     * When the check cannot run it fails closed (true) and logs.
     */
    public function isBlocking(): bool {
        try {
            if ( ! $this->gateOn() ) {
                return false;
            }
            // A recent answer is reused unless the store changed since (a recent failure is reused as well, so a
            // broken scan is not retried and logged for every submission or message).
            if ( null !== $this->memo && ( time() - $this->memo['at'] ) < self::MEMO_SECONDS && ( '' !== $this->memo['error'] || ! $this->staleMarked() ) ) {
                return $this->memo['blocking'];
            }
            $current    = $this->fresh();
            $blocking   = self::STATUS_BLOCK === $this->effectiveFor( $current );
            $this->memo = [ 'at' => time(), 'blocking' => $blocking, 'error' => '' ];
            return $blocking;
        } catch ( Throwable $e ) {
            $this->memo = [ 'at' => time(), 'blocking' => true, 'error' => $e->getMessage() ];
            $this->log( 'error', 'The storefront compliance check could not run; automated submissions and outreach sending stay paused until it does (fail closed): ' . $e->getMessage() );
            return true;
        }
    }

    /** Plain sentence for the UI and logs; '' when not blocking. */
    public function blockingReason(): string {
        if ( ! $this->isBlocking() ) {
            return '';
        }
        return $this->reasonText( $this->current() );
    }

    /**
     * Compact status for dashboards and the banner.
     *
     * @return array<string,mixed> {status, effective, checked_at, counts, top, gate, acknowledged{hash,by,at}, blocking, stale, hash, scanned, total, message}
     */
    public function summary(): array {
        $current   = $this->current();
        $effective = $this->effectiveFor( $current );
        $gate      = $this->gateOn();
        $counts    = is_array( $current['counts'] ?? null ) ? $current['counts'] : [];
        $findings  = is_array( $current['findings'] ?? null ) ? array_values( $current['findings'] ) : [];
        $failed    = null !== $this->memo && '' !== $this->memo['error'];
        $blocking  = $gate && ( self::STATUS_BLOCK === $effective || $failed );
        return [
            'status'       => [] === $current ? self::EFFECTIVE_UNKNOWN : (string) $current['status'],
            'effective'    => $effective,
            'checked_at'   => [] === $current ? null : (string) ( $current['checked_at'] ?? '' ),
            'counts'       => [ 'block' => (int) ( $counts['block'] ?? 0 ), 'warn' => (int) ( $counts['warn'] ?? 0 ) ],
            'top'          => array_slice( $findings, 0, 5 ),
            'gate'         => $gate,
            'blocking'     => $blocking,
            'stale'        => [] !== $current && $this->needsRefresh( $current ),
            'hash'         => (string) ( $current['hash'] ?? '' ),
            'total'        => (int) ( $current['total'] ?? count( $findings ) ),
            'truncated'    => ! empty( $current['truncated'] ),
            'woocommerce'  => ! empty( $current['woocommerce'] ),
            'scanned'      => is_array( $current['scanned'] ?? null ) ? $current['scanned'] : [],
            'error'        => $failed ? (string) $this->memo['error'] : '',
            'message'      => $blocking ? $this->reasonText( $current ) : '',
            'acknowledged' => [
                'hash' => (string) $this->settings()->get( 'compliance.acknowledged_hash', '' ),
                'by'   => (int) $this->settings()->get( 'compliance.acknowledged_by', 0 ),
                'at'   => (string) $this->settings()->get( 'compliance.acknowledged_at', '' ),
            ],
        ];
    }

    /** Marks the stored result stale (product, product term or menu saved); the next gate check re-scans. */
    public function markStale(): void {
        try {
            if ( false === get_option( self::STALE_OPTION, false ) ) {
                add_option( self::STALE_OPTION, gmdate( 'c' ), '', false );
            }
            $this->memo = null;
        } catch ( Throwable $e ) {
            unset( $e ); // Called from save hooks: never break a save.
        }
    }

    /** `save_post` / `trashed_post` / `deleted_post` / `untrashed_post` callback: stale when a scanned post type changed. */
    public function markStaleForPost( mixed $postId = 0 ): void {
        $id = is_numeric( $postId ) ? (int) $postId : 0;
        if ( $id > 0 && function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $id ) ) {
            return;
        }
        $type = $id > 0 && function_exists( 'get_post_type' ) ? (string) get_post_type( $id ) : '';
        if ( '' === $type || in_array( $type, [ 'product', 'page', 'post', 'nav_menu_item' ], true ) ) {
            $this->markStale();
        }
    }

    /**
     * Records the owner's acknowledgement of the current findings (by hash) and returns summary().
     * Nothing is recorded when the check passes (there is nothing to acknowledge).
     *
     * @return array<string,mixed>
     */
    public function acknowledge( int $userId ): array {
        $current = $this->current();
        if ( [] === $current ) {
            $current = $this->run();
        }
        $status = (string) ( $current['status'] ?? '' );
        if ( in_array( $status, [ self::STATUS_BLOCK, self::STATUS_WARN ], true ) ) {
            $hash = (string) ( $current['hash'] ?? '' );
            $at   = gmdate( 'c' );
            $this->settings()->update(
                [
                    'compliance' => [
                        'acknowledged_hash' => $hash,
                        'acknowledged_by'   => max( 0, $userId ),
                        'acknowledged_at'   => $at,
                    ],
                ]
            );
            $this->memo = null;
            try {
                $this->auditLog()->record(
                    $userId > 0 ? 'user:' . $userId : AuditLog::currentActor(),
                    'compliance.storefront_acknowledged',
                    'storefront',
                    0,
                    [ 'hash' => $hash, 'status' => $status, 'counts' => $current['counts'] ?? [], 'checked_at' => $current['checked_at'] ?? '' ]
                );
            } catch ( Throwable $e ) {
                unset( $e );
            }
            $this->log( 'warning', sprintf( 'The owner acknowledged the storefront compliance findings (%s, %d blocking, %d to review); automated submissions and outreach sending may resume until the findings change.', $status, (int) ( $current['counts']['block'] ?? 0 ), (int) ( $current['counts']['warn'] ?? 0 ) ), [ 'user_id' => $userId, 'hash' => $hash ] );
        }
        return $this->summary();
    }

    /** Adds the hooks that mark the result stale when products, product terms or menus change. */
    public function register(): void {
        foreach ( [ 'save_post_product', 'save_post_page', 'save_post_post', 'trashed_post', 'untrashed_post', 'deleted_post' ] as $hook ) {
            add_action( $hook, [ $this, 'markStaleForPost' ], 20, 1 );
        }
        foreach ( [ 'product_cat', 'product_tag' ] as $taxonomy ) {
            foreach ( [ 'created_', 'edited_', 'delete_' ] as $prefix ) {
                add_action( $prefix . $taxonomy, [ $this, 'markStale' ], 20, 0 );
            }
        }
        foreach ( [ 'wp_update_nav_menu', 'wp_delete_nav_menu', 'wp_update_nav_menu_item' ] as $hook ) {
            add_action( $hook, [ $this, 'markStale' ], 20, 0 );
        }
    }

    // ------------------------------------------------------------------
    // Matching (pure)
    // ------------------------------------------------------------------

    /**
     * Term matches in one text: list of [severity, term, offset (bytes), length (bytes), rule].
     * Blocking matches win over overlapping warnings; negated phrases are skipped.
     *
     * @return array<int,array{severity:string,term:string,offset:int,length:int,rule:string}>
     */
    public static function matchTerms( string $text, bool $dosageForms = false ): array {
        $lists = self::compiled();
        $out   = [];
        $sets  = [ [ self::SEVERITY_BLOCK, 'term', $lists['block'] ] ];
        if ( $dosageForms ) {
            $sets[] = [ self::SEVERITY_BLOCK, 'dosage_form', $lists['dosage'] ];
        }
        $sets[] = [ self::SEVERITY_WARN, 'term', $lists['warn'] ];
        foreach ( $sets as [ $severity, $rule, $patterns ] ) {
            foreach ( $patterns as $term => $regex ) {
                if ( ! preg_match_all( $regex, $text, $m, PREG_OFFSET_CAPTURE ) ) {
                    continue;
                }
                foreach ( $m[0] as [ $hit, $offset ] ) {
                    $offset = (int) $offset;
                    $length = strlen( (string) $hit );
                    if ( self::overlaps( $out, $offset, $length ) || self::isNegated( $text, $offset ) ) {
                        continue;
                    }
                    $out[] = [ 'severity' => $severity, 'term' => (string) $term, 'offset' => $offset, 'length' => $length, 'rule' => $rule ];
                }
            }
        }
        return $out;
    }

    /**
     * Plain text of a field: tags stripped, entities decoded, whitespace collapsed, length-capped.
     */
    public static function plain( string $text ): string {
        $text = preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', ' ', $text ) ?? $text;
        $text = strip_tags( str_replace( [ '<br', '</p>', '</li>' ], [ ' <br', ' </p>', ' </li>' ], $text ) );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) ?? $text;
        $text = trim( $text );
        return mb_substr( $text, 0, self::MAX_TEXT );
    }

    /**
     * sha1 of the sorted (severity, where, term, object_id) tuples of every finding.
     *
     * @param array<int,array<string,mixed>> $findings
     */
    public static function hashFindings( array $findings ): string {
        $keys = [];
        foreach ( $findings as $f ) {
            $keys[] = [ (string) ( $f['severity'] ?? '' ), (string) ( $f['where'] ?? '' ), (string) ( $f['term'] ?? '' ), (int) ( $f['object_id'] ?? 0 ) ];
        }
        usort( $keys, static fn( array $a, array $b ): int => $a <=> $b );
        return sha1( (string) wp_json_encode( $keys ) );
    }

    // ------------------------------------------------------------------
    // Scanning
    // ------------------------------------------------------------------

    /**
     * @return array{findings:array<int,array<string,mixed>>,woocommerce:bool,scanned:array<string,int>}
     */
    private function scan(): array {
        $woo      = self::wooActive();
        $findings = [];
        $scanned  = [];
        if ( $woo ) {
            $scanned['categories'] = $this->scanTerms( 'product_cat', 'category', $findings );
            $scanned['tags']       = $this->scanTerms( 'product_tag', 'tag', $findings );
            $scanned['products']   = $this->scanPosts( [ 'product' ], $findings, true );
            $scanned['menu_items'] = $this->scanMenus( $findings );
        } else {
            $scanned['pages'] = $this->scanPosts( [ 'page', 'post' ], $findings, false );
        }
        return [ 'findings' => array_values( $findings ), 'woocommerce' => $woo, 'scanned' => $scanned ];
    }

    private static function wooActive(): bool {
        if ( class_exists( 'WooCommerce' ) ) {
            return true;
        }
        return function_exists( 'taxonomy_exists' ) && taxonomy_exists( 'product_cat' );
    }

    /**
     * @param array<string,array<string,mixed>> $findings
     */
    private function scanTerms( string $taxonomy, string $where, array &$findings ): int {
        if ( ! function_exists( 'get_terms' ) || ( function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( $taxonomy ) ) ) {
            return 0;
        }
        $terms = get_terms(
            [
                'taxonomy'               => $taxonomy,
                'hide_empty'             => false,
                'number'                 => self::MAX_TERMS,
                'orderby'                => 'term_id',
                'order'                  => 'ASC',
                'update_term_meta_cache' => false,
            ]
        );
        if ( is_wp_error( $terms ) ) {
            throw new RuntimeException( sprintf( 'Could not read the %s terms: %s', $taxonomy, $terms->get_error_message() ) );
        }
        if ( ! is_array( $terms ) ) {
            return 0;
        }
        $n = 0;
        foreach ( $terms as $term ) {
            $id = (int) self::prop( $term, 'term_id' );
            if ( $id <= 0 ) {
                continue;
            }
            $n++;
            $name = self::plain( (string) self::prop( $term, 'name' ) );
            $url  = function_exists( 'admin_url' ) ? admin_url( 'term.php?taxonomy=' . rawurlencode( $taxonomy ) . '&tag_ID=' . $id . '&post_type=product' ) : '';
            $base = [ 'where' => $where, 'object_id' => $id, 'label' => $name, 'url' => $url ];
            $this->matchField( $findings, $base, 'name', $name );
            $slug = (string) self::prop( $term, 'slug' );
            $this->matchField( $findings, $base, 'slug', self::plain( rawurldecode( $slug ) ), 'Slug: ' . $slug );
            $this->matchField( $findings, $base, 'description', self::plain( (string) self::prop( $term, 'description' ) ) );
        }
        return $n;
    }

    /**
     * @param string[]                          $postTypes
     * @param array<string,array<string,mixed>> $findings
     */
    private function scanPosts( array $postTypes, array &$findings, bool $products ): int {
        if ( ! function_exists( 'get_posts' ) ) {
            return 0;
        }
        $n = 0;
        for ( $offset = 0; $offset < self::MAX_POSTS; $offset += self::BATCH ) {
            $batch = get_posts(
                [
                    'post_type'              => $postTypes,
                    'post_status'            => 'publish',
                    'posts_per_page'         => self::BATCH,
                    'offset'                 => $offset,
                    'orderby'                => 'ID',
                    'order'                  => 'ASC',
                    'no_found_rows'          => true,
                    'ignore_sticky_posts'    => true,
                    'suppress_filters'       => true,
                    'cache_results'          => false,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                ]
            );
            if ( ! is_array( $batch ) || [] === $batch ) {
                break;
            }
            foreach ( $batch as $post ) {
                $id = (int) self::prop( $post, 'ID' );
                if ( $id <= 0 ) {
                    continue;
                }
                $n++;
                $type  = (string) self::prop( $post, 'post_type' );
                $where = $products ? 'product' : ( 'post' === $type ? 'post' : 'page' );
                $title = self::plain( (string) self::prop( $post, 'post_title' ) );
                $url   = function_exists( 'admin_url' ) ? admin_url( 'post.php?post=' . $id . '&action=edit' ) : '';
                $base  = [ 'where' => $where, 'object_id' => $id, 'label' => '' !== $title ? $title : '(no title)', 'url' => $url ];
                $this->matchField( $findings, $base, 'title', $title, '', $products );
                if ( $products ) {
                    $this->matchField( $findings, $base, 'short_description', self::plain( (string) self::prop( $post, 'post_excerpt' ) ), '', false, true );
                }
            }
            $count = count( $batch );
            unset( $batch );
            if ( $count < self::BATCH ) {
                break;
            }
        }
        return $n;
    }

    /**
     * @param array<string,array<string,mixed>> $findings
     */
    private function scanMenus( array &$findings ): int {
        if ( ! function_exists( 'get_nav_menu_locations' ) || ! function_exists( 'wp_get_nav_menu_items' ) ) {
            return 0;
        }
        $locations = get_nav_menu_locations();
        if ( ! is_array( $locations ) ) {
            return 0;
        }
        $menus = array_values( array_unique( array_filter( array_map( 'intval', $locations ), static fn( int $id ): bool => $id > 0 ) ) );
        $n     = 0;
        foreach ( $menus as $menuId ) {
            $items = wp_get_nav_menu_items( $menuId, [ 'update_post_term_cache' => false ] );
            if ( ! is_array( $items ) ) {
                continue;
            }
            $url = function_exists( 'admin_url' ) ? admin_url( 'nav-menus.php?action=edit&menu=' . $menuId ) : '';
            foreach ( $items as $item ) {
                $id = (int) ( self::prop( $item, 'db_id' ) ?: self::prop( $item, 'ID' ) );
                if ( $id <= 0 ) {
                    continue;
                }
                $n++;
                $title = self::plain( (string) ( self::prop( $item, 'title' ) ?: self::prop( $item, 'post_title' ) ) );
                $this->matchField( $findings, [ 'where' => 'menu', 'object_id' => $id, 'label' => '' !== $title ? $title : '(no title)', 'url' => $url ], 'title', $title );
            }
        }
        return $n;
    }

    /**
     * Adds the findings of one text field (deduplicated by severity, where, term and object id).
     *
     * @param array<string,array<string,mixed>> $findings
     * @param array{where:string,object_id:int,label:string,url:string} $base
     */
    private function matchField( array &$findings, array $base, string $field, string $text, string $excerptOverride = '', bool $dosageForms = false, bool $claims = false ): void {
        if ( '' === $text ) {
            return;
        }
        $matches = self::matchTerms( $text, $dosageForms );
        if ( $claims ) {
            foreach ( $this->claimsFilter()->check( $text ) as $v ) {
                $offset = (int) ( $v['offset'] ?? 0 );
                $length = strlen( (string) ( $v['match'] ?? '' ) );
                if ( $length <= 0 || self::overlaps( $matches, $offset, $length ) ) {
                    continue;
                }
                $rule      = (string) ( $v['rule'] ?? '' );
                $matches[] = [
                    'severity' => in_array( $rule, self::CLAIMS_BLOCK_RULES, true ) ? self::SEVERITY_BLOCK : self::SEVERITY_WARN,
                    'term'     => mb_strtolower( trim( (string) $v['match'] ) ),
                    'offset'   => $offset,
                    'length'   => $length,
                    'rule'     => 'claims:' . $rule,
                ];
            }
        }
        foreach ( $matches as $m ) {
            $key = implode( '|', [ $m['severity'], $base['where'], $m['term'], (string) $base['object_id'] ] );
            if ( isset( $findings[ $key ] ) ) {
                continue;
            }
            $findings[ $key ] = [
                'severity'  => $m['severity'],
                'where'     => $base['where'],
                'term'      => $m['term'],
                'object_id' => $base['object_id'],
                'label'     => self::shorten( $base['label'], 200 ),
                'url'       => $base['url'],
                'excerpt'   => '' !== $excerptOverride ? self::shorten( $excerptOverride, self::EXCERPT_LENGTH ) : self::excerpt( $text, $m['offset'], $m['length'] ),
                'field'     => $field,
                'rule'      => $m['rule'],
            ];
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The blocking sentence for a stored result (or for a check that could not run).
     *
     * @param array<string,mixed> $current
     */
    private function reasonText( array $current ): string {
        if ( ( null !== $this->memo && '' !== $this->memo['error'] ) || [] === $current ) {
            return 'The storefront compliance check could not run, so automated submissions and outreach sending are paused until it runs successfully (check the plugin logs).';
        }
        $counts  = is_array( $current['counts'] ?? null ) ? $current['counts'] : [];
        $n       = (int) ( $counts['block'] ?? 0 );
        $example = '';
        foreach ( (array) ( $current['findings'] ?? [] ) as $f ) {
            if ( is_array( $f ) && self::SEVERITY_BLOCK === ( $f['severity'] ?? '' ) ) {
                $example = sprintf( ' (for example "%s" in the %s "%s")', (string) ( $f['term'] ?? '' ), self::WHERE_LABELS[ (string) ( $f['where'] ?? '' ) ] ?? (string) ( $f['where'] ?? '' ), self::shorten( (string) ( $f['label'] ?? '' ), 60 ) );
                break;
            }
        }
        return sprintf(
            'The storefront compliance check is blocking automated submissions and outreach sending: %d storefront %s consumer-use wording that contradicts the research-use-only positioning%s. Review the findings on the Overview page, then change the storefront or acknowledge them.',
            $n,
            1 === $n ? 'item uses' : 'items use',
            $example
        );
    }

    /** @param array<string,mixed> $current */
    private function needsRefresh( array $current ): bool {
        if ( [] === $current || $this->staleMarked() ) {
            return true;
        }
        $at = strtotime( (string) ( $current['checked_at'] ?? '' ) );
        return false === $at || ( time() - $at ) > self::MAX_AGE_SECONDS;
    }

    private function staleMarked(): bool {
        return false !== get_option( self::STALE_OPTION, false );
    }

    /** @param array<string,mixed> $result */
    private function effectiveFor( array $result ): string {
        $status = (string) ( $result['status'] ?? '' );
        if ( ! in_array( $status, [ self::STATUS_PASS, self::STATUS_WARN, self::STATUS_BLOCK ], true ) ) {
            return self::EFFECTIVE_UNKNOWN;
        }
        if ( self::STATUS_PASS === $status ) {
            return self::STATUS_PASS;
        }
        $ack  = (string) $this->settings()->get( 'compliance.acknowledged_hash', '' );
        $hash = (string) ( $result['hash'] ?? '' );
        if ( '' !== $ack && '' !== $hash && hash_equals( $ack, $hash ) ) {
            return self::EFFECTIVE_ACKNOWLEDGED;
        }
        return $status;
    }

    private function gateOn(): bool {
        $value = $this->settings()->get( 'compliance.storefront_gate', true );
        if ( is_string( $value ) ) {
            return ! in_array( strtolower( trim( $value ) ), [ '', '0', 'false', 'off', 'no' ], true );
        }
        return (bool) $value;
    }

    /**
     * @param array<int,array{offset:int,length:int}> $matches
     */
    private static function overlaps( array $matches, int $offset, int $length ): bool {
        foreach ( $matches as $m ) {
            if ( $offset < $m['offset'] + $m['length'] && $m['offset'] < $offset + $length ) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when the phrase at $offset is governed by a negation at most two words before it in the same clause
     * ("Not for human use", "not intended for human use", "no dosing guidance", "not a supplement").
     */
    private static function isNegated( string $text, int $offset ): bool {
        if ( $offset <= 0 ) {
            return false;
        }
        $start  = max( 0, $offset - 60 );
        $before = mb_strtolower( substr( $text, $start, $offset - $start ) );
        if ( $start > 0 ) {
            $before = (string) preg_replace( '/^\S*/u', '', $before ); // drop a partial first word
        }
        $word = '(?!(?:' . self::SCOPE_BREAKERS . ')\b)[\p{L}\p{N}][\p{L}\p{N}\'’\-]*(?:,?[\s\-_\/]+(?:(?:or|nor)\s+)?)';
        return 1 === preg_match( '/(?<![\p{L}\p{N}\-])' . self::NEGATION . '[\s\-_\/]+(?:' . $word . '){0,2}$/u', $before );
    }

    /**
     * Context around a match (byte offsets from preg) as at most EXCERPT_LENGTH characters.
     */
    private static function excerpt( string $text, int $offset, int $length ): string {
        if ( mb_strlen( $text ) <= self::EXCERPT_LENGTH ) {
            return $text;
        }
        $charOffset = mb_strlen( substr( $text, 0, $offset ) );
        $charLength = max( 1, mb_strlen( substr( $text, $offset, $length ) ) );
        $start      = max( 0, $charOffset - (int) floor( ( self::EXCERPT_LENGTH - $charLength ) / 2 ) );
        $start      = min( $start, max( 0, mb_strlen( $text ) - self::EXCERPT_LENGTH ) );
        $out        = mb_substr( $text, $start, self::EXCERPT_LENGTH );
        return ( $start > 0 ? '…' : '' ) . trim( $out ) . ( $start + self::EXCERPT_LENGTH < mb_strlen( $text ) ? '…' : '' );
    }

    private static function shorten( string $text, int $max ): string {
        return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…' : $text;
    }

    /**
     * Block first, then by where, object id and term (stable for the UI and the hash).
     *
     * @param array<int,array<string,mixed>> $findings
     * @return array<int,array<string,mixed>>
     */
    private static function sortFindings( array $findings ): array {
        usort(
            $findings,
            static function ( array $a, array $b ): int {
                return [ self::SEVERITY_BLOCK === $a['severity'] ? 0 : 1, self::WHERE_ORDER[ $a['where'] ] ?? 9, (int) $a['object_id'], (string) $a['term'] ]
                    <=> [ self::SEVERITY_BLOCK === $b['severity'] ? 0 : 1, self::WHERE_ORDER[ $b['where'] ] ?? 9, (int) $b['object_id'], (string) $b['term'] ];
            }
        );
        return array_values( $findings );
    }

    /**
     * Compiled whole-word, case-insensitive regexes per list.
     *
     * @return array{block:array<string,string>,warn:array<string,string>,dosage:array<string,string>}
     */
    private static function compiled(): array {
        if ( null === self::$compiled ) {
            $compile = static function ( array $list ): array {
                $out = [];
                foreach ( $list as $term => $body ) {
                    $body         = str_replace( [ ' ', '~' ], [ '[\s\-_\/]+', '[\s\-_\/]*' ], (string) $body );
                    $out[ $term ] = '/(?<![\p{L}\p{N}])(?:' . $body . ')(?![\p{L}\p{N}])/iu';
                }
                return $out;
            };
            self::$compiled = [
                'block'  => $compile( self::BLOCK_TERMS ),
                'warn'   => $compile( self::WARN_TERMS ),
                'dosage' => $compile( self::DOSAGE_FORMS ),
            ];
        }
        return self::$compiled;
    }

    private static function prop( mixed $item, string $key ): mixed {
        if ( is_array( $item ) ) {
            return $item[ $key ] ?? null;
        }
        if ( is_object( $item ) ) {
            return $item->{$key} ?? null;
        }
        return null;
    }

    private function settings(): Settings {
        return $this->settings ??= self::service( Settings::class );
    }

    private function claimsFilter(): ClaimsFilter {
        return $this->claims ??= self::service( ClaimsFilter::class );
    }

    private function auditLog(): AuditLog {
        return $this->auditLog ??= self::service( AuditLog::class );
    }

    /** @param array<string,mixed> $context */
    private function log( string $level, string $message, array $context = [] ): void {
        try {
            $this->logger ??= self::service( Logger::class );
            $this->logger->log( $level, 'compliance', $message, $context );
        } catch ( Throwable $e ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[ppclg][' . $level . '][compliance] ' . $message );
        }
    }

    /**
     * Resolves a dependency from the plugin container when the factory did not inject it.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private static function service( string $id ): object {
        $service = Plugin::instance()->get( $id );
        if ( ! $service instanceof $id ) {
            throw new RuntimeException( sprintf( 'Service %s is not available.', $id ) );
        }
        return $service;
    }
}
