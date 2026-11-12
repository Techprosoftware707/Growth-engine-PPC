<?php
/**
 * Extra WordPress / WooCommerce / block-registry stubs for the On-site SEO review fixes, driven by StubsSeoReview.
 *
 * - wp_revisions_to_keep() and wp_save_post_revision() in the PPC\LinkGrowth\Seo namespace (like StubsSeo.php):
 *   revisions live in StubsSeo::$revisions; the content of revisions saved here is remembered, and a revision saved
 *   by the wp_update_post() stub holds the post's content as it was right after that update.
 * - wc_get_page_id() (global, as WooCommerce declares it): -1 unless StubsSeoReview::$wcPages names the page.
 * - WP_Block_Type_Registry (global): only blocks listed in StubsSeoReview::$dynamicBlocks are registered (dynamic).
 * - wp_is_block_theme() in the PPC\LinkGrowth\Seo namespace: StubsSeoReview::$blockTheme.
 * Every stub is guarded with function_exists() / class_exists(). Required from StubsSeo.php.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Support {

    final class StubsSeoReview {

        /** wp_revisions_to_keep() result (-1 = keep all, as WordPress by default). */
        public static int $keep = -1;

        /** @var array<int,string> revision id => content, for revisions saved by wp_save_post_revision() */
        public static array $contents = [];

        /** wp_save_post_revision() saves nothing (returns null without a revision). */
        public static bool $saveFails = false;

        /** @var array<string,int> WooCommerce page => post id (cart, checkout, myaccount, shop, terms) */
        public static array $wcPages = [];

        /** @var string[] registered dynamic block names */
        public static array $dynamicBlocks = [];

        /** wp_is_block_theme() result. */
        public static bool $blockTheme = false;

        public static function reset(): void {
            self::$keep          = -1;
            self::$contents      = [];
            self::$saveFails     = false;
            self::$wcPages       = [];
            self::$dynamicBlocks = [];
            self::$blockTheme    = false;
        }
    }
}

namespace PPC\LinkGrowth\Seo {

    use PPC\LinkGrowth\Tests\Support\StubsSeo;
    use PPC\LinkGrowth\Tests\Support\StubsSeoReview;

    if ( ! function_exists( __NAMESPACE__ . '\\wp_revisions_to_keep' ) ) {
        function wp_revisions_to_keep( mixed $post ): int {
            return StubsSeo::$revisionsEnabled ? StubsSeoReview::$keep : 0;
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\wp_is_block_theme' ) ) {
        function wp_is_block_theme(): bool {
            return StubsSeoReview::$blockTheme;
        }
    }

    if ( ! function_exists( __NAMESPACE__ . '\\wp_save_post_revision' ) ) {
        /** Like core: saves the current content unless the newest revision already holds it (then null). */
        function wp_save_post_revision( int $post_id ): ?int {
            $post = StubsSeo::$posts[ $post_id ] ?? null;
            if ( null === $post || StubsSeoReview::$saveFails || ! StubsSeo::$revisionsEnabled ) {
                return null;
            }
            $content = (string) ( $post['post_content'] ?? '' );
            $revs    = StubsSeo::$revisions[ $post_id ] ?? [];
            if ( [] !== $revs ) {
                $latest = StubsSeoReview::$contents[ $revs[0] ] ?? null;
                // A revision the wp_update_post() stub saved holds the content of that update, i.e. the current one.
                if ( null === $latest || $latest === $content ) {
                    return null;
                }
            }
            StubsSeo::$revisions[ $post_id ] ??= [];
            $id                               = StubsSeo::addRevision( $post_id );
            StubsSeoReview::$contents[ $id ] = $content;
            return $id;
        }
    }
}

namespace {

    use PPC\LinkGrowth\Tests\Support\StubsSeoReview;

    if ( ! function_exists( 'wc_get_page_id' ) ) {
        function wc_get_page_id( string $page ): int {
            return StubsSeoReview::$wcPages[ $page ] ?? -1;
        }
    }

    if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
        final class WP_Block_Type_Registry {

            private static ?self $instance = null;

            public static function get_instance(): self {
                return self::$instance ??= new self();
            }

            public function get_registered( string $name ): ?object {
                if ( ! in_array( $name, StubsSeoReview::$dynamicBlocks, true ) ) {
                    return null;
                }
                return new class() {
                    public function is_dynamic(): bool {
                        return true;
                    }
                };
            }
        }
    }
}
