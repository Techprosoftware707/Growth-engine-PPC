<?php
/**
 * WordPress post/menu/term stubs for the On-site SEO tests, driven by StubsSeo fixtures.
 *
 * The functions are declared in the PPC\LinkGrowth\Seo namespace: PHP resolves an unqualified call made inside that
 * namespace to the namespaced function first, so these stubs serve only the Seo classes and can neither clash with
 * nor be shadowed by global stubs other test files may declare. Each one is guarded with function_exists().
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Support {

    /** Fixture store behind the namespaced WordPress stubs. */
    final class StubsSeo {

        /** @var array<int,array<string,mixed>> post id => fields */
        public static array $posts = [];

        /** @var array<int,array<string,mixed>> post id => meta key => value */
        public static array $meta = [];

        /** @var array<int,object> nav menu items */
        public static array $menuItems = [];

        /** @var array<string,array<string,object>> taxonomy => slug => term */
        public static array $terms = [];

        /** @var array<int,int[]> post id => revision ids (newest first) */
        public static array $revisions = [];

        /** @var array<int,array<string,mixed>> wp_update_post() calls (unslashed data) */
        public static array $updates = [];

        /** @var array<string,bool> post type => supports revisions */
        public static array $supportsRevisions = [ 'post' => true, 'page' => true, 'product' => false ];

        public static bool $revisionsEnabled = true;

        public static string $environment = 'production';

        /**
         * How the stand-in theme renders an item for the render check (1.2.2): 'content' (default: the post content
         * inside the theme's header and footer), 'hidden' (a hard-coded template that ignores the content, like the
         * live front page), 'partial' (only the first paragraph), or a Closure(int $id): string with the whole page.
         *
         * @var array<int,string|\Closure>
         */
        public static array $render = [];

        /** @var array<int,int|\WP_Error> post id => HTTP status (or a transport error) of its page */
        public static array $renderStatus = [];

        /** @var array<int,array{url:string,args:array<string,mixed>}> page-check requests served */
        public static array $renderRequests = [];

        /** wp_update_post() runs wp_kses_post() on the content when the current user lacks unfiltered_html (as core). */
        public static bool $ksesOnSave = true;

        /** @var int[]|null existing user ids for get_userdata() (null: every id > 0 exists) */
        public static ?array $users = null;

        /** @var int[] wp_set_current_user() calls */
        public static array $userSwitches = [];

        /** get_locale() for the Seo classes (1.2.3 site language). */
        public static string $locale = 'en_CA';

        /** @var array<int,string|false> Polylang stand-in: post id => locale (pll_get_post_language) */
        public static array $pll = [];

        /** @var int Polylang stand-in calls (the language is worked out once per item and call) */
        public static int $pllCalls = 0;

        private static int $nextRevision = 9000;

        public static function reset(): void {
            self::$posts             = [];
            self::$meta              = [];
            self::$menuItems         = [];
            self::$terms             = [];
            self::$revisions         = [];
            self::$updates           = [];
            self::$supportsRevisions = [ 'post' => true, 'page' => true, 'product' => false ];
            self::$revisionsEnabled  = true;
            self::$environment       = 'production';
            self::$nextRevision      = 9000;
            self::$render            = [];
            self::$renderStatus      = [];
            self::$renderRequests    = [];
            self::$ksesOnSave        = true;
            self::$users             = null;
            self::$userSwitches      = [];
            self::$locale            = 'en_CA';
            self::$pll               = [];
            self::$pllCalls          = 0;
            if ( class_exists( StubsSeoReview::class ) ) {
                StubsSeoReview::reset();
            }
            // The render check's page requests are answered by the stand-in theme below, without waiting between them.
            HttpFixtures::$handler = self::renderHandler();
            add_filter( 'ppclg_render_check_gap_seconds', static fn(): float => 0.0 );
        }

        /** Answers the render check's requests (they send Cache-Control: no-cache) for the permalinks of the fixtures. */
        public static function renderHandler(): \Closure {
            return static function ( string $url, array $args ) {
                $headers = array_change_key_case( (array) ( $args['headers'] ?? [] ), CASE_LOWER );
                if ( 'no-cache' !== ( $headers['cache-control'] ?? '' ) ) {
                    return null;
                }
                $id = 0;
                foreach ( array_keys( self::$posts ) as $pid ) {
                    if ( self::permalink( (int) $pid ) === $url ) {
                        $id = (int) $pid;
                        break;
                    }
                }
                if ( 0 === $id ) {
                    return null;
                }
                self::$renderRequests[] = [ 'url' => $url, 'args' => $args ];
                $status = self::$renderStatus[ $id ] ?? 200;
                if ( $status instanceof \WP_Error ) {
                    return $status;
                }
                return [ 'response' => [ 'code' => (int) $status, 'message' => 'OK' ], 'headers' => [ 'content-type' => 'text/html; charset=UTF-8' ], 'body' => 200 === $status ? self::renderPage( $id ) : '<html><body>Error</body></html>', 'cookies' => [] ];
            };
        }

        /** The page a visitor gets for an item under the stand-in classic theme. */
        public static function renderPage( int $id ): string {
            $p    = self::$posts[ $id ];
            $mode = self::$render[ $id ] ?? 'content';
            if ( $mode instanceof \Closure ) {
                return (string) $mode( $id );
            }
            $content = (string) $p['post_content'];
            $main    = match ( $mode ) {
                'hidden'  => '<section class="hero"><h1>Research peptides, tested in Canada</h1><p>Every batch ships with an independent lab report and fast tracked delivery across the country.</p><p>Where can I see the lab reports? On every product page, next to the price.</p></section>',
                'partial' => preg_match( '#^.*?</p>#s', $content, $m ) ? $m[0] : $content,
                default   => $content,
            };
            $class = 'page' === $p['post_type'] ? 'page-template-default page page-id-' . $id : 'single single-' . $p['post_type'] . ' postid-' . $id;
            return '<!DOCTYPE html><html lang="en"><head><title>' . htmlspecialchars( (string) $p['post_title'] ) . '</title><link rel="canonical" href="' . self::permalink( $id ) . '">'
                . '<script type="application/ld+json">{"@type":"WebPage","name":"' . htmlspecialchars( (string) $p['post_title'] ) . '"}</script></head>'
                . '<body class="' . $class . '"><header><nav><a href="/">Home</a> <a href="/shop/">Shop</a> <a href="/product/bacteriostatic-water/">Bacteriostatic Water</a></nav></header>'
                . '<main>' . $main . '</main><footer><p>Pure Peptides Canada · Research use only</p></footer></body></html>';
        }


        /**
         * @param array<string,mixed> $fields
         * @param array<string,mixed> $meta
         */
        public static function addPost( int $id, array $fields, array $meta = [] ): void {
            $title = (string) ( $fields['post_title'] ?? '' );
            self::$posts[ $id ] = array_merge(
                [
                    'ID'                => $id,
                    'post_type'         => 'post',
                    'post_status'       => 'publish',
                    'post_title'        => $title,
                    'post_content'      => '',
                    'post_excerpt'      => '',
                    'post_name'         => '' !== $title ? sanitize_title( $title ) : 'item-' . $id,
                    'post_password'     => '',
                    'post_modified_gmt' => '2026-01-01 00:00:00',
                ],
                $fields,
                [ 'ID' => $id ]
            );
            self::$meta[ $id ] = $meta;
        }

        public static function setMeta( int $id, string $key, mixed $value ): void {
            self::$meta[ $id ][ $key ] = $value;
        }

        public static function addMenuItem( int $objectId ): void {
            self::$menuItems[] = (object) [ 'type' => 'post_type', 'object_id' => $objectId, 'object' => self::$posts[ $objectId ]['post_type'] ?? 'page', 'url' => self::permalink( $objectId ) ];
        }

        public static function addTerm( string $taxonomy, string $slug ): void {
            self::$terms[ $taxonomy ][ $slug ] = (object) [ 'term_id' => count( self::$terms[ $taxonomy ] ?? [] ) + 1, 'slug' => $slug, 'taxonomy' => $taxonomy ];
        }

        public static function post( int $id ): ?object {
            return isset( self::$posts[ $id ] ) ? (object) self::$posts[ $id ] : null;
        }

        public static function permalink( int $id ): string {
            $p = self::$posts[ $id ] ?? null;
            if ( null === $p ) {
                return '';
            }
            if ( isset( $p['url'] ) ) {
                return (string) $p['url'];
            }
            $prefix = 'product' === $p['post_type'] ? 'product/' : '';
            return home_url( '/' . $prefix . $p['post_name'] . '/' );
        }

        public static function addRevision( int $postId ): int {
            $rev = ++self::$nextRevision;
            array_unshift( self::$revisions[ $postId ], $rev );
            return $rev;
        }
    }
}

namespace PPC\LinkGrowth\Seo {

    use PPC\LinkGrowth\Tests\Support\StubsSeo;

    if ( ! function_exists( __NAMESPACE__ . '\\get_posts' ) ) {
        /** @param array<string,mixed> $args @return array<int,mixed> */
        function get_posts( array $args = [] ): array {
            $types   = (array) ( $args['post_type'] ?? 'post' );
            $status  = (array) ( $args['post_status'] ?? 'publish' );
            $limit   = (int) ( $args['numberposts'] ?? $args['posts_per_page'] ?? 5 );
            $paged   = max( 1, (int) ( $args['paged'] ?? 1 ) );
            $noPass  = array_key_exists( 'has_password', $args ) && false === $args['has_password'];
            $matches = [];
            foreach ( StubsSeo::$posts as $id => $p ) {
                if ( ! in_array( $p['post_type'], $types, true ) || ! in_array( $p['post_status'], $status, true ) ) {
                    continue;
                }
                if ( $noPass && '' !== (string) $p['post_password'] ) {
                    continue;
                }
                $matches[] = (int) $id;
            }
            sort( $matches );
            if ( $limit > 0 ) {
                $matches = array_slice( $matches, ( $paged - 1 ) * $limit, $limit );
            }
            if ( 'ids' === ( $args['fields'] ?? '' ) ) {
                return $matches;
            }
            return array_map( static fn( int $id ): object => StubsSeo::post( $id ), $matches );
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\get_post' ) ) {
        function get_post( mixed $post = null ): ?object {
            if ( is_object( $post ) ) {
                return StubsSeo::post( (int) ( $post->ID ?? 0 ) );
            }
            return StubsSeo::post( (int) $post );
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\get_post_meta' ) ) {
        function get_post_meta( int $id, string $key = '', bool $single = false ): mixed {
            $value = StubsSeo::$meta[ $id ][ $key ] ?? '';
            return $single ? $value : ( '' === $value ? [] : [ $value ] );
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\get_post_status' ) ) {
        function get_post_status( mixed $post = null ): string|false {
            $id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;
            return isset( StubsSeo::$posts[ $id ] ) ? (string) StubsSeo::$posts[ $id ]['post_status'] : false;
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\get_permalink' ) ) {
        function get_permalink( mixed $post = 0 ): string|false {
            $id  = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;
            $url = StubsSeo::permalink( $id );
            return '' === $url ? false : $url;
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\get_edit_post_link' ) ) {
        function get_edit_post_link( int $id = 0, string $context = 'display' ): ?string {
            return isset( StubsSeo::$posts[ $id ] ) ? admin_url( 'post.php?post=' . $id . '&action=edit' ) : null;
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\url_to_postid' ) ) {
        function url_to_postid( string $url ): int {
            parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
            if ( isset( $q['p'] ) && isset( StubsSeo::$posts[ (int) $q['p'] ] ) ) {
                return (int) $q['p'];
            }
            $path = rtrim( (string) parse_url( $url, PHP_URL_PATH ), '/' );
            foreach ( array_keys( StubsSeo::$posts ) as $id ) {
                if ( '' !== $path && rtrim( (string) parse_url( StubsSeo::permalink( (int) $id ), PHP_URL_PATH ), '/' ) === $path ) {
                    return (int) $id;
                }
            }
            return 0;
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\get_page_by_path' ) ) {
        function get_page_by_path( string $path, string $output = 'OBJECT', string|array $types = 'page' ): ?object {
            $path = trim( $path, '/' );
            foreach ( StubsSeo::$posts as $id => $p ) {
                if ( in_array( $p['post_type'], (array) $types, true ) && 'product' !== $p['post_type'] && trim( (string) parse_url( StubsSeo::permalink( (int) $id ), PHP_URL_PATH ), '/' ) === $path ) {
                    return StubsSeo::post( (int) $id );
                }
            }
            return null;
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\get_term_by' ) ) {
        function get_term_by( string $field, mixed $value, string $taxonomy = '' ): object|false {
            return StubsSeo::$terms[ $taxonomy ][ (string) $value ] ?? false;
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\wp_get_nav_menus' ) ) {
        /** @return array<int,object> */
        function wp_get_nav_menus( array $args = [] ): array {
            return [] === StubsSeo::$menuItems ? [] : [ (object) [ 'term_id' => 7, 'name' => 'Main' ] ];
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\wp_get_nav_menu_items' ) ) {
        /** @return array<int,object> */
        function wp_get_nav_menu_items( mixed $menu, array $args = [] ): array {
            return 7 === (int) $menu ? StubsSeo::$menuItems : [];
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\get_post_thumbnail_id' ) ) {
        function get_post_thumbnail_id( mixed $post = null ): int {
            $id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;
            return (int) ( StubsSeo::$meta[ $id ]['_thumbnail_id'] ?? 0 );
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\get_post_types' ) ) {
        /** @return array<string,string> */
        function get_post_types( array $args = [], string $output = 'names' ): array {
            return [ 'post' => 'post', 'page' => 'page', 'product' => 'product', 'attachment' => 'attachment' ];
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\post_type_supports' ) ) {
        function post_type_supports( string $type, string $feature ): bool {
            return 'revisions' === $feature ? ( StubsSeo::$supportsRevisions[ $type ] ?? false ) : true;
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\wp_revisions_enabled' ) ) {
        function wp_revisions_enabled( mixed $post ): bool {
            return StubsSeo::$revisionsEnabled;
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\wp_slash' ) ) {
        /** Like core: addslashes() on every string (wp_insert_post() unslashes its input). */
        function wp_slash( mixed $value ): mixed {
            if ( is_array( $value ) ) {
                return array_map( __NAMESPACE__ . '\\wp_slash', $value );
            }
            return is_string( $value ) ? addslashes( $value ) : $value;
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\wp_update_post' ) ) {
        /** Like core: expects slashed data, stores unslashed content, saves a revision when the type supports it. */
        function wp_update_post( array $postarr = [], bool $wp_error = false ): int|\WP_Error {
            $data = array_map( static fn( mixed $v ): mixed => is_string( $v ) ? stripslashes( $v ) : $v, $postarr );
            $id   = (int) ( $data['ID'] ?? 0 );
            if ( ! isset( StubsSeo::$posts[ $id ] ) ) {
                return $wp_error ? new \WP_Error( 'invalid_post', 'Invalid post ID.' ) : 0;
            }
            if ( ! empty( $GLOBALS['ppclg_seo_update_fails'] ) ) {
                return $wp_error ? new \WP_Error( 'db_update_error', 'Could not update post in the database.' ) : 0;
            }
            if ( ! empty( $GLOBALS['ppclg_seo_update_throws'] ) ) {
                throw new \RuntimeException( 'A save_post hook threw.' );
            }
            if ( StubsSeo::$ksesOnSave && isset( $data['post_content'] ) && ! current_user_can( 'unfiltered_html' ) ) {
                $data['post_content'] = wp_kses_post( (string) $data['post_content'] ); // content_save_pre for users without unfiltered_html
            }
            $data['_user'] = (int) get_current_user_id();
            StubsSeo::$updates[] = $data;
            unset( $data['_user'] );
            foreach ( $data as $k => $v ) {
                if ( 'ID' !== $k ) {
                    StubsSeo::$posts[ $id ][ $k ] = $v;
                }
            }
            StubsSeo::$posts[ $id ]['post_modified_gmt'] = gmdate( 'Y-m-d H:i:s' );
            if ( StubsSeo::$revisionsEnabled && ( StubsSeo::$supportsRevisions[ StubsSeo::$posts[ $id ]['post_type'] ] ?? false ) ) {
                StubsSeo::$revisions[ $id ] ??= [];
                StubsSeo::addRevision( $id );
            }
            return $id;
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\get_userdata' ) ) {
        /** Users exist unless StubsSeo::$users lists the ones that do (a deleted consent user). */
        function get_userdata( int $id ): object|false {
            if ( $id <= 0 || ( null !== StubsSeo::$users && ! in_array( $id, StubsSeo::$users, true ) ) ) {
                return false;
            }
            return (object) [ 'ID' => $id, 'display_name' => 'User ' . $id ];
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\wp_get_post_revisions' ) ) {
        /** @return array<int,int> */
        function wp_get_post_revisions( int $postId = 0, array $args = [] ): array {
            $revs = StubsSeo::$revisions[ $postId ] ?? [];
            $n    = (int) ( $args['numberposts'] ?? -1 );
            return $n > 0 ? array_slice( $revs, 0, $n ) : $revs;
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\wp_get_environment_type' ) ) {
        function wp_get_environment_type(): string {
            return StubsSeo::$environment;
        }
    }

    // Revisions kept before an edit, WooCommerce page ids and the block registry (review fixes).
    require_once __DIR__ . '/StubsSeoReview.php';
}

namespace {

    use PPC\LinkGrowth\Tests\Support\StubsSeo;

    if ( ! function_exists( 'get_locale' ) ) {
        /** Like core: the site locale (StubsSeo::$locale). */
        function get_locale(): string {
            return StubsSeo::$locale;
        }
    }

    if ( ! function_exists( 'pll_get_post_language' ) ) {
        /** Polylang stand-in: the language of the items in StubsSeo::$pll, false for the others (as Polylang). */
        function pll_get_post_language( int $postId, string $field = 'slug' ): string|false {
            StubsSeo::$pllCalls++;
            $locale = StubsSeo::$pll[ $postId ] ?? false;
            if ( ! is_string( $locale ) || '' === $locale ) {
                return false;
            }
            return 'locale' === $field ? $locale : strtolower( substr( $locale, 0, 2 ) );
        }
    }

    if ( ! function_exists( 'wp_set_current_user' ) ) {
        /** Like core: the current user changes; capabilities follow $GLOBALS['ppclg_test_user_caps'][id] (none for 0). */
        function wp_set_current_user( int $id, string $name = '' ): object {
            StubsSeo::$userSwitches[]      = $id;
            $GLOBALS['ppclg_test_user_id'] = $id;
            $GLOBALS['ppclg_test_caps']    = $id > 0 ? (array) ( $GLOBALS['ppclg_test_user_caps'][ $id ] ?? [] ) : [];
            return (object) [ 'ID' => $id ];
        }
    }
}
