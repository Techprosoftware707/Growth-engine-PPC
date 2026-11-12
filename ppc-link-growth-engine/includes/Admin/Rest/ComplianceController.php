<?php
/**
 * /compliance/storefront — storefront compliance preflight status, re-check and owner acknowledgement.
 *
 * How the store presents itself is the owner's legal decision; this endpoint never edits the store. Acknowledging
 * records that the owner reviewed exactly the current findings (by hash): automated submissions and outreach
 * sending then resume until the findings change.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Admin\Rest;

use PPC\LinkGrowth\Compliance\StorefrontAudit;
use Throwable;
use WP_Error;
use WP_REST_Request;

final class ComplianceController extends Controller {

    public function register( string $namespace ): void {
        $this->route( $namespace, '/compliance/storefront', 'GET', [ $this, 'status' ] );
        $this->route( $namespace, '/compliance/storefront/recheck', 'POST', [ $this, 'recheck' ] );
        $this->route(
            $namespace,
            '/compliance/storefront/acknowledge',
            'POST',
            [ $this, 'acknowledge' ],
            [
                'confirm' => [ 'type' => 'boolean' ],
                'hash'    => [ 'type' => 'string' ],
            ]
        );
    }

    /**
     * GET /compliance/storefront — summary plus every stored finding. A missing, day-old or stale result is
     * refreshed first (database only); when that fails the stored result is returned with `error` set.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function status( WP_REST_Request $request ): array|WP_Error {
        unset( $request );
        $audit = $this->storefront();
        if ( $audit instanceof WP_Error ) {
            return $audit;
        }
        $error = '';
        try {
            $audit->fresh();
        } catch ( Throwable $e ) {
            $error = 'The storefront check could not run: ' . $e->getMessage();
            $audit->isBlocking(); // records the failure, so the summary reports the fail-closed pause truthfully
        }
        $out = $this->payload( $audit );
        if ( '' !== $error ) {
            $out['error'] = $error;
        }
        return $out;
    }

    /** POST /compliance/storefront/recheck — scans now. @return array<string,mixed>|WP_Error */
    public function recheck( WP_REST_Request $request ): array|WP_Error {
        unset( $request );
        $audit = $this->storefront();
        if ( $audit instanceof WP_Error ) {
            return $audit;
        }
        try {
            $result = $audit->run();
        } catch ( Throwable $e ) {
            $this->logger()->error( 'compliance', 'Storefront re-check failed: ' . $e->getMessage() );
            return $this->fail( 'ppclg_storefront_check_failed', 'The storefront check could not run: ' . $e->getMessage() . ' Automated submissions and outreach sending stay paused until it runs.', 500 );
        }
        $this->audit( 'compliance.storefront_rechecked', 'storefront', 0, [ 'status' => $result['status'], 'counts' => $result['counts'], 'hash' => $result['hash'] ] );
        $out            = $this->payload( $audit );
        $out['ok']      = true;
        $out['message'] = self::checkedMessage( $out );
        return $out;
    }

    /**
     * POST /compliance/storefront/acknowledge {confirm:true, hash?} — records the owner's decision for the
     * current findings. With `hash`, refuses when the findings changed since the owner loaded them.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function acknowledge( WP_REST_Request $request ): array|WP_Error {
        if ( ! $this->bool( $request, 'confirm', false ) ) {
            return $this->fail( 'ppclg_confirm_required', 'Confirm that you reviewed the storefront findings and that how the store presents itself is your decision (confirm: true).', 400 );
        }
        $audit = $this->storefront();
        if ( $audit instanceof WP_Error ) {
            return $audit;
        }
        $current = $audit->current();
        if ( [] === $current ) {
            return $this->fail( 'ppclg_storefront_not_checked', 'The storefront has not been checked yet. Run the check and review the findings before acknowledging them.', 409 );
        }
        if ( ! in_array( (string) $current['status'], [ StorefrontAudit::STATUS_BLOCK, StorefrontAudit::STATUS_WARN ], true ) ) {
            return $this->fail( 'ppclg_nothing_to_acknowledge', 'Nothing to acknowledge: the storefront check found no consumer-use wording.', 409 );
        }
        $expected = preg_replace( '/[^a-f0-9]/', '', strtolower( $this->str( $request, 'hash', '', 64 ) ) ) ?? '';
        if ( '' !== $expected && ! hash_equals( (string) $current['hash'], $expected ) ) {
            return $this->fail( 'ppclg_findings_changed', 'The storefront findings changed since you loaded them. Review the current findings and acknowledge again.', 409 );
        }
        try {
            $audit->acknowledge( $this->userId() );
        } catch ( Throwable $e ) {
            $this->logger()->error( 'compliance', 'Storefront acknowledgement failed: ' . $e->getMessage() );
            return $this->fail( 'ppclg_acknowledge_failed', 'The acknowledgement could not be saved: ' . $e->getMessage(), 500 );
        }
        $out            = $this->payload( $audit );
        $out['ok']      = StorefrontAudit::EFFECTIVE_ACKNOWLEDGED === $out['effective'];
        $out['message'] = $out['ok']
            ? sprintf( 'Acknowledged %d finding(s). Automated submissions and outreach sending may resume; any change in the storefront findings pauses them again.', (int) $out['counts']['block'] + (int) $out['counts']['warn'] )
            : 'The acknowledgement was not recorded; check the plugin logs.';
        return $out;
    }

    /** @return StorefrontAudit|WP_Error */
    private function storefront(): StorefrontAudit|WP_Error {
        try {
            $audit = $this->c->get( StorefrontAudit::class );
            if ( $audit instanceof StorefrontAudit ) {
                return $audit;
            }
            throw new \RuntimeException( 'unexpected service type' );
        } catch ( Throwable $e ) {
            $message = $e->getMessage();
            $this->safe( fn() => $this->logger()->error( 'compliance', 'Storefront compliance check unavailable: ' . $message ), null, 'compliance log' );
            return $this->fail( 'ppclg_storefront_unavailable', 'The storefront compliance check is not available; automated submissions and outreach sending stay paused. Check the plugin logs.', 503 );
        }
    }

    /**
     * summary() plus the stored findings.
     *
     * @return array<string,mixed>
     */
    private function payload( StorefrontAudit $audit ): array {
        $summary             = $audit->summary();
        $current             = $audit->current();
        $summary['findings'] = is_array( $current['findings'] ?? null ) ? array_values( $current['findings'] ) : [];
        return $summary;
    }

    /** @param array<string,mixed> $out */
    private static function checkedMessage( array $out ): string {
        $block = (int) ( $out['counts']['block'] ?? 0 );
        $warn  = (int) ( $out['counts']['warn'] ?? 0 );
        return match ( (string) $out['effective'] ) {
            StorefrontAudit::STATUS_PASS          => 'Storefront checked: no consumer-use wording found.',
            StorefrontAudit::EFFECTIVE_ACKNOWLEDGED => sprintf( 'Storefront checked: %d blocking and %d to review, unchanged since the owner acknowledged them.', $block, $warn ),
            StorefrontAudit::STATUS_BLOCK         => ! empty( $out['gate'] )
                ? sprintf( 'Storefront checked: %d blocking and %d to review. Automated submissions and outreach sending are paused.', $block, $warn )
                : sprintf( 'Storefront checked: %d blocking and %d to review. The gate is off, so nothing is paused.', $block, $warn ),
            default                               => sprintf( 'Storefront checked: %d item(s) to review (nothing is paused).', $warn ),
        };
    }
}
