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
            if ( class_exists( StubsSeoReview::class ) ) {
                StubsSeoReview::reset();
            }
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
            StubsSeo::$updates[] = $data;
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
