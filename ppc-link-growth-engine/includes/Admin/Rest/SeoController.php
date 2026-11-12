<?php
/**
 * /seo — on-site audit, IndexNow and internal-link suggestions.
 *
 * Link suggestions change published content only through POST /seo/links/apply, which requires confirm=true and is
 * sent by the admin's explicit "Apply" / "Apply all" click on the On-site SEO page.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Admin\Rest;

use PPC\LinkGrowth\Seo\IndexNow;
use PPC\LinkGrowth\Seo\InternalLinker;
use PPC\LinkGrowth\Seo\SiteAuditor;
use PPC\LinkGrowth\Support\TimeBudget;
use WP_REST_Request;

final class SeoController extends Controller {

    /** Seconds one admin request may work (the page repeats the call while more work remains). */
    private const RUN_SECONDS = 20.0;

    /** Suggestions applied per request at most. */
    private const APPLY_MAX = 100;

    public function register( string $namespace ): void {
        $this->route( $namespace, '/seo/audit', 'GET', [ $this, 'auditStatus' ] );
        $this->route( $namespace, '/seo/audit/run', 'POST', [ $this, 'auditRun' ] );
        $this->route( $namespace, '/seo/indexnow', 'GET', [ $this, 'indexnow' ] );
        $this->route( $namespace, '/seo/indexnow/run', 'POST', [ $this, 'indexnowRun' ] );
        $this->route( $namespace, '/seo/links', 'GET', [ $this, 'links' ] );
        $this->route( $namespace, '/seo/links/run', 'POST', [ $this, 'linksRun' ] );
        $this->route( $namespace, '/seo/links/apply', 'POST', [ $this, 'linksApply' ] );
        $this->route( $namespace, '/seo/links/dismiss', 'POST', [ $this, 'linksDismiss' ] );
    }

    // ------------------------------------------------------------------
    // Audit
    // ------------------------------------------------------------------

    /** GET /seo/audit @return array<string,mixed> */
    public function auditStatus( WP_REST_Request $request ): array {
        unset( $request );
        $auditor = $this->auditor();
        if ( null === $auditor ) {
            return [ 'available' => false, 'message' => 'The site audit is not available.' ];
        }
        $result = $auditor->result();
        return [
            'available' => true,
            'summary'   => $auditor->summary(),
            'progress'  => $auditor->progress(),
            'result'    => [] === $result ? null : [
                'checked_at'      => $result['checked_at'] ?? null,
                'pages'           => (int) ( $result['pages'] ?? 0 ),
                'by_type'         => is_array( $result['by_type'] ?? null ) ? $result['by_type'] : [],
                'capped'          => ! empty( $result['capped'] ),
                'max_pages'       => (int) ( $result['max_pages'] ?? 0 ),
                'orphans_checked' => ! empty( $result['orphans_checked'] ),
                'counts'          => is_array( $result['counts'] ?? null ) ? $result['counts'] : [],
                'issues_total'    => (int) ( $result['issues_total'] ?? 0 ),
                'stats'           => is_array( $result['stats'] ?? null ) ? $result['stats'] : [],
                'sample'          => is_array( $result['sample'] ?? null ) ? $result['sample'] : [],
            ],
            'issues'    => array_values( is_array( $result['issues'] ?? null ) ? $result['issues'] : [] ),
        ];
    }

    /** POST /seo/audit/run {restart?: bool} @return array<string,mixed> */
    public function auditRun( WP_REST_Request $request ): array {
        $auditor = $this->auditor();
        if ( null === $auditor ) {
            return [ 'ok' => false, 'message' => 'The site audit is not available.' ];
        }
        $restart = $this->bool( $request, 'restart', false );
        $res     = $auditor->run( TimeBudget::seconds( self::RUN_SECONDS ), [ 'restart' => $restart ] );
        if ( ! empty( $res['done'] ) || $restart ) {
            $this->audit( 'seo.audit_run', 'seo', 0, [ 'checked' => $res['checked'], 'issues' => $res['issues'], 'done' => ! empty( $res['done'] ), 'restart' => $restart ] );
        }
        return array_merge( $res, [ 'message' => (string) $res['summary'], 'summary_data' => $auditor->summary() ] );
    }

    // ------------------------------------------------------------------
    // IndexNow
    // ------------------------------------------------------------------

    /** GET /seo/indexnow @return array<string,mixed> */
    public function indexnow( WP_REST_Request $request ): array {
        unset( $request );
        $indexnow = $this->indexNowService();
        if ( null === $indexnow ) {
            return [ 'available' => false, 'message' => 'IndexNow is not available.' ];
        }
        if ( $indexnow->enabled() ) {
            // The admin opened the On-site SEO page: create the key now (once) so its file can be checked before the
            // first submission. IndexNow::status() itself never writes settings.
            $this->safe( static fn(): string => $indexnow->key( true ), '', 'IndexNow key' );
        }
        return [
            'available' => true,
            'status'    => $indexnow->status(),
            'history'   => $indexnow->history(),
            'endpoint'  => IndexNow::ENDPOINT,
        ];
    }

    /** POST /seo/indexnow/run ("Submit now") @return array<string,mixed> */
    public function indexnowRun( WP_REST_Request $request ): array {
        $indexnow = $this->indexNowService();
        if ( null === $indexnow ) {
            return [ 'ok' => false, 'message' => 'IndexNow is not available.' ];
        }
        // An explicit click compares every public URL now and may retry before the hourly back-off ends.
        $res = $indexnow->run( TimeBudget::seconds( self::RUN_SECONDS ), [ 'full' => true, 'force' => true ] );
        if ( empty( $res['skipped'] ) ) {
            $this->audit( 'seo.indexnow_run', 'seo', 0, [ 'submitted' => $res['submitted'], 'status' => $res['status'], 'ok' => (bool) $res['ok'] ] );
        }
        return array_merge( $res, [ 'message' => (string) $res['summary'], 'status_data' => $indexnow->status() ] );
    }

    // ------------------------------------------------------------------
    // Internal links
    // ------------------------------------------------------------------

    /** GET /seo/links?status=pending|applied|dismissed|stale|all @return array<string,mixed> */
    public function links( WP_REST_Request $request ): array {
        $linker = $this->linker();
        if ( null === $linker ) {
            return [ 'available' => false, 'message' => 'Internal-link suggestions are not available.', 'items' => [] ];
        }
        $status = sanitize_key( $this->str( $request, 'status', InternalLinker::STATUS_PENDING, 20 ) );
        if ( ! in_array( $status, [ 'pending', 'applied', 'dismissed', 'stale', 'all' ], true ) ) {
            $status = InternalLinker::STATUS_PENDING;
        }
        $items = $linker->suggestions( 'all' === $status ? '' : $status, 500 );
        foreach ( $items as &$item ) {
            $item = self::castInts( $item, [ 'id', 'source_post_id', 'target_id', 'applied_by', 'dismissed_by', 'revision_id' ] );
            unset( $item['context_hash'] );
            $item['edit_url'] = $this->editLink( (int) $item['source_post_id'] );
        }
        unset( $item );
        return [
            'available'          => true,
            'enabled'            => $linker->enabled(),
            'max_links_per_post' => $linker->maxPerSource(),
            'generated_at'       => $linker->generatedAt(),
            'progress'           => $linker->progress(),
            'counts'             => $linker->counts(),
            'status'             => $status,
            'items'              => $items,
        ];
    }

    /** POST /seo/links/run {restart?: bool} — refreshes suggestions (never edits content). @return array<string,mixed> */
    public function linksRun( WP_REST_Request $request ): array {
        $linker = $this->linker();
        if ( null === $linker ) {
            return [ 'ok' => false, 'message' => 'Internal-link suggestions are not available.' ];
        }
        $res = $linker->run( TimeBudget::seconds( self::RUN_SECONDS ), [ 'restart' => $this->bool( $request, 'restart', false ), 'manual' => true ] );
        return array_merge( $res, [ 'message' => (string) $res['summary'] ] );
    }

    /** POST /seo/links/apply {ids: int[], confirm: true} @return array<string,mixed>|\WP_Error */
    public function linksApply( WP_REST_Request $request ) {
        $linker = $this->linker();
        if ( null === $linker ) {
            return [ 'ok' => false, 'message' => 'Internal-link suggestions are not available.' ];
        }
        $ids = $this->intList( $request, 'ids', self::APPLY_MAX );
        if ( [] === $ids ) {
            return $this->fail( 'ppclg_no_ids', __( 'Select at least one suggestion to apply.', 'ppc-link-growth-engine' ), 400 );
        }
        if ( true !== $this->bool( $request, 'confirm', false ) ) {
            return $this->fail(
                'ppclg_confirmation_required',
                __( 'Applying a suggestion edits published content (WordPress keeps a revision). Confirm to continue.', 'ppc-link-growth-engine' ),
                400,
                [ 'confirm_required' => true ]
            );
        }
        $res = $linker->apply( $ids, $this->userId(), TimeBudget::seconds( self::RUN_SECONDS + 5 ) );
        $this->audit( 'seo.internal_links_apply', 'seo', 0, [ 'requested' => count( $ids ), 'applied' => $res['applied'], 'refused' => $res['refused'] ] );
        return array_merge( $res, [ 'counts' => $linker->counts() ] );
    }

    /** POST /seo/links/dismiss {ids: int[]} @return array<string,mixed>|\WP_Error */
    public function linksDismiss( WP_REST_Request $request ) {
        $linker = $this->linker();
        if ( null === $linker ) {
            return [ 'ok' => false, 'message' => 'Internal-link suggestions are not available.' ];
        }
        $ids = $this->intList( $request, 'ids', 500 );
        if ( [] === $ids ) {
            return $this->fail( 'ppclg_no_ids', __( 'Select at least one suggestion to dismiss.', 'ppc-link-growth-engine' ), 400 );
        }
        return array_merge( $linker->dismiss( $ids, $this->userId() ), [ 'counts' => $linker->counts() ] );
    }

    // ------------------------------------------------------------------
    // Services (degrade to "not available" when unregistered)
    // ------------------------------------------------------------------

    private function auditor(): ?SiteAuditor {
        return $this->safe( fn(): ?SiteAuditor => class_exists( SiteAuditor::class ) ? $this->c->get( SiteAuditor::class ) : null, null, 'site auditor' );
    }

    private function indexNowService(): ?IndexNow {
        return $this->safe( fn(): ?IndexNow => class_exists( IndexNow::class ) ? $this->c->get( IndexNow::class ) : null, null, 'IndexNow' );
    }

    private function linker(): ?InternalLinker {
        return $this->safe( fn(): ?InternalLinker => class_exists( InternalLinker::class ) ? $this->c->get( InternalLinker::class ) : null, null, 'internal linker' );
    }

    private function editLink( int $postId ): string {
        $link = function_exists( 'get_edit_post_link' ) ? get_edit_post_link( $postId, 'raw' ) : '';
        return is_string( $link ) && '' !== $link ? $link : admin_url( sprintf( 'post.php?post=%d&action=edit', $postId ) );
    }
}
