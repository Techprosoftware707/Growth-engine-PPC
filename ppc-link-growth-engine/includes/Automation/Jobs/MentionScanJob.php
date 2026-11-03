<?php
/**
 * Job: search for brand mentions; unlinked ones become prospects, linked ones become pending backlinks.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Discovery\MentionFinder;
use PPC\LinkGrowth\Discovery\QueryGenerator;
use PPC\LinkGrowth\Repositories\Backlinks;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Support\Url;
use Throwable;

final class MentionScanJob extends AbstractJob {

    private const DEFAULT_MAX_PAGES = 15;

    public static function type(): string {
        return 'mention_scan';
    }

    public static function priority(): int {
        return 6;
    }

    /**
     * @param array<string,mixed> $payload { max_pages?: int }
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        if ( ! $this->flag( $c, $payload, 'discovery' ) ) {
            return $this->skipped( 'discovery_disabled' );
        }
        $limits = $this->limits( $c );
        if ( ! $limits->allow( 'discovery_queries' ) ) {
            return $this->skipped( 'discovery_query_limit_reached', [ 'limits' => $limits->snapshot()['discovery_queries'] ?? null ] );
        }
        if ( ! $budget->has( 6.0 ) ) {
            return $this->deferred( $c, $payload );
        }

        $queries = $c->get( QueryGenerator::class )->brandQueries();
        if ( [] === $queries ) {
            return $this->skipped( 'no_brand_queries' );
        }
        // Never run more queries than the daily/weekly limit allows.
        $queries  = array_slice( $queries, 0, max( 1, min( count( $queries ), $limits->remaining( 'discovery_queries' ) ) ) );
        $maxPages = isset( $payload['max_pages'] ) ? max( 1, min( 50, (int) $payload['max_pages'] ) ) : self::DEFAULT_MAX_PAGES;

        $scan = $c->get( MentionFinder::class )->scan( $queries, $budget, $maxPages );
        $ran  = (int) ( $scan['queries'] ?? 0 );
        if ( $ran > 0 ) {
            $limits->spend( 'discovery_queries', $ran );
        }

        $stats = [
            'queries'        => $ran,
            'pages_checked'  => (int) ( $scan['pages_checked'] ?? 0 ),
            'unlinked'       => (int) ( $scan['unlinked'] ?? 0 ),
            'linked'         => count( (array) ( $scan['linked'] ?? [] ) ),
            'backlinks_new'  => 0,
            'backlinks_seen' => 0,
            'errors'         => 0,
            'warning'        => (string) ( $scan['warning'] ?? '' ),
        ];

        $backlinks = $c->get( Backlinks::class );
        foreach ( (array) ( $scan['linked'] ?? [] ) as $link ) {
            try {
                $source = Url::normalize( (string) ( $link['url'] ?? '' ) );
                $target = (string) ( $link['target'] ?? '' );
                if ( '' === $source || '' === $target ) {
                    continue;
                }
                $targetN = Url::normalize( $target ) ?: $target;
                // Do not touch known links (keeps their monitoring status/origin intact).
                if ( null !== $backlinks->findOneBy( [ 'source_hash' => sha1( $source ), 'target_hash' => sha1( $targetN ) ] ) ) {
                    $stats['backlinks_seen']++;
                    continue;
                }
                [ , $created ] = $backlinks->upsert(
                    $source,
                    $targetN,
                    [
                        'anchor_text'   => mb_substr( (string) ( $link['anchor'] ?? '' ), 0, 500 ),
                        'rel'           => ! empty( $link['nofollow'] ) ? 'nofollow' : 'dofollow',
                        'placement'     => 'content',
                        'origin'        => 'mention_scan',
                        'status'        => Backlinks::STATUS_PENDING,
                        'next_check_at' => $this->now(),
                    ]
                );
                if ( $created ) {
                    $stats['backlinks_new']++;
                }
            } catch ( Throwable $e ) {
                $stats['errors']++;
                $this->logger( $c )->warning( 'mentions', 'Could not record linked mention', $this->errorContext( $e ) );
            }
        }

        if ( $stats['backlinks_new'] > 0 ) {
            $this->notify(
                $c,
                'backlink',
                'info',
                'Brand mentions with links found',
                sprintf( '%d new linking page(s) were found through the brand mention scan and queued for verification.', $stats['backlinks_new'] ),
                [ 'count' => $stats['backlinks_new'] ]
            );
        }
        $this->audit( $c )->record( 'mentions.scanned', 'discovery', 0, array_diff_key( $stats, [ 'warning' => 1 ] ) );
        return $this->result( $stats );
    }
}
