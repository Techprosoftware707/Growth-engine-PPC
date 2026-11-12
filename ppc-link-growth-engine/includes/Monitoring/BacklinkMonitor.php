<?php
/**
 * Backlink monitor: re-crawls known linking pages, confirms the link is still
 * present, records attribute changes and applies two-strike lost detection.
 *
 * Outcomes per check:
 *  - robots.txt disallow      -> status unverified, re-check in 30 days, no strike
 *  - robots.txt unreachable   -> transient (the page is not crawled): note only, re-check tomorrow
 *  - network error / 5xx      -> transient: note only, re-check tomorrow, no strike; a domain that
 *                                no longer resolves (DNS) for 14+ days counts as a strike
 *  - HTTP 404/410             -> strike; "broken" once strikes reach the threshold
 *  - page OK, link missing    -> strike; "lost" once strikes reach the threshold
 *  - redirect to other domain -> status redirected
 *  - link present             -> status active, attributes refreshed, changes recorded
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Monitoring;

use PPC\LinkGrowth\Analysis\PageAnalysis;
use PPC\LinkGrowth\Analysis\PageAnalyzer;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\Backlinks;
use PPC\LinkGrowth\Repositories\Repository;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Http\Response;
use PPC\LinkGrowth\Support\Http\RobotsTxt;
use PPC\LinkGrowth\Support\Limits;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Support\Url;

final class BacklinkMonitor {

    public const LIMIT_KEY = 'backlink_checks';

    private const DAY = 86400;

    /** Change history entries kept per backlink. */
    private const MAX_CHANGES = 50;

    /** Links younger than this use the "new" check interval. */
    private const NEW_LINK_DAYS = 14;

    /** Lost/broken links are re-checked weekly ... */
    private const LOST_RECHECK_DAYS = 7;

    /** ... for this long, then they become unverified. */
    private const LOST_GIVE_UP_DAYS = 60;

    private const ROBOTS_RETRY_DAYS = 30;

    private const TRANSIENT_RETRY_DAYS = 1;

    private const STRIKE_RETRY_DAYS = 1;

    /** A linking domain that does not resolve and was last reached this many days ago counts as a strike. */
    private const DNS_STRIKE_DAYS = 14;

    /** Timeout (seconds) of the robots.txt re-read that tells "unreachable" apart from "disallowed". */
    private const ROBOTS_PROBE_TIMEOUT = 5;

    /** How long (seconds) a robots.txt re-read is reused for other links on the same host. */
    private const ROBOTS_PROBE_TTL = 300;

    /** Origins whose links were created by a bulk import and may never have existed as real links. */
    private const BULK_ORIGINS = [ 'provider:seranking', 'provider:ahrefs', 'provider:semrush', 'provider:moz', 'ga4_referral', 'mention_scan' ];

    /** @var string[] */
    private const PLACEMENTS = [ 'content', 'nav', 'footer', 'sidebar', 'header', 'comments', 'directory', 'unknown' ];

    /** @var array<string,int> */
    private const PLACEMENT_POINTS = [ 'content' => 25, 'directory' => 15, 'sidebar' => 10, 'footer' => 5 ];

    /** @var string[] */
    private const GENERIC_ANCHORS = [ 'click here', 'here', 'link', 'this link', 'website', 'web site', 'site', 'read more', 'more', 'learn more', 'visit', 'visit website', 'visit site', 'go', 'source', 'url', 'homepage', 'home page', 'home', 'this', 'page', 'view', 'open', 'details', 'info', 'www', 'http', 'https', '>>', '->', '→', 'continue', 'continue reading', 'see more', 'check it out' ];

    private Limits $limits;

    /** @var array<string,array{at:int,error:string,status:int,body:string}> robots.txt re-reads by origin */
    private array $robotsProbes = [];

    public function __construct(
        private Backlinks $backlinks,
        private Client $client,
        private PageAnalyzer $analyzer,
        private Settings $settings,
        private Notifier $notifier,
        private AuditLog $audit,
        private Logger $logger
    ) {
        $this->limits = new Limits( $settings );
    }

    /**
     * Checks one backlink row now and persists the outcome.
     *
     * @param array<string,mixed> $backlink Hydrated row from the Backlinks repository.
     * @return array{status:string,changed:bool,lost:bool,recovered:bool,note:string}
     */
    public function check( array $backlink ): array {
        $id     = (int) ( $backlink['id'] ?? 0 );
        $result = $this->result( (string) ( $backlink['status'] ?? Backlinks::STATUS_PENDING ) );
        if ( $id <= 0 ) {
            $result['note'] = 'Invalid backlink record.';
            return $result;
        }
        try {
            return $this->inspect( $backlink );
        } catch ( \Throwable $e ) {
            $this->logger->error( 'monitoring', 'Backlink check failed', [ 'backlink_id' => $id, 'error' => $e->getMessage() ] );
            $this->safeUpdate( $id, [ 'last_checked' => Repository::now(), 'next_check_at' => $this->inDays( self::TRANSIENT_RETRY_DAYS ) ] );
            $result['note'] = 'Check failed: ' . $e->getMessage();
            return $result;
        }
    }

    /**
     * Checks due backlinks within the time budget and the daily "backlink_checks" limit.
     *
     * @return array{checked:int,lost:int,changed:int,recovered:int,more_due:bool,note:string}
     */
    public function checkDue( TimeBudget $budget, int $max ): array {
        $out = [ 'checked' => 0, 'lost' => 0, 'changed' => 0, 'recovered' => 0, 'more_due' => false, 'note' => '' ];
        try {
            $allowed = $this->limits->remaining( self::LIMIT_KEY );
            $n       = min( max( 0, $max ), $allowed );
            if ( $n <= 0 ) {
                $out['note']     = $allowed <= 0 ? 'Daily backlink check limit reached.' : 'No checks requested.';
                $out['more_due'] = ! empty( $this->dueRows( 1 ) );
                return $out;
            }
            $rows = $this->dueRows( $n );
            $need = min( 12.0, $this->timeout() + 2.0 );
            foreach ( $rows as $row ) {
                if ( ! $budget->has( $need ) ) {
                    $out['note'] = 'Time budget exhausted.';
                    break;
                }
                $this->limits->spend( self::LIMIT_KEY );
                $r = $this->check( $row );
                ++$out['checked'];
                if ( $r['lost'] ) {
                    ++$out['lost'];
                }
                if ( $r['changed'] ) {
                    ++$out['changed'];
                }
                if ( $r['recovered'] ) {
                    ++$out['recovered'];
                }
            }
            $out['more_due'] = ! empty( $this->dueRows( 1 ) );
            if ( '' === $out['note'] && empty( $rows ) ) {
                $out['note'] = 'No backlinks due for a check.';
            }
            $this->logger->info( 'monitoring', 'Backlink check run finished', $out );
        } catch ( \Throwable $e ) {
            $out['note'] = 'Run aborted: ' . $e->getMessage();
            $this->logger->error( 'monitoring', 'Backlink check run failed', [ 'error' => $e->getMessage() ] );
        }
        return $out;
    }

    /**
     * Rows due for a check: Backlinks::dueForCheck() plus "unverified" rows whose robots.txt re-check
     * date has passed. dueForCheck() leaves out every unverified row, so without this the 30-day robots
     * re-check would never run. Unverified rows with next_check_at NULL (monitoring stopped) stay out.
     *
     * @return array<int,array<string,mixed>>
     */
    private function dueRows( int $limit ): array {
        $limit = max( 1, $limit );
        $rows  = $this->backlinks->dueForCheck( $limit );
        if ( count( $rows ) >= $limit ) {
            return $rows;
        }
        $seen = [];
        foreach ( $rows as $row ) {
            $seen[ (int) ( $row['id'] ?? 0 ) ] = true;
        }
        $page = $this->backlinks->paginate(
            [ 'status' => Backlinks::STATUS_UNVERIFIED ],
            '',
            [],
            1,
            $limit - count( $rows ),
            'next_check_at',
            'ASC',
            'next_check_at IS NOT NULL AND next_check_at <= %s',
            [ Repository::now() ]
        );
        foreach ( (array) ( $page['items'] ?? [] ) as $row ) {
            if ( is_array( $row ) && ! isset( $seen[ (int) ( $row['id'] ?? 0 ) ] ) ) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Registers a backlink added by a person (origin manual, status pending, checked on the next run).
     *
     * @return int Backlink id, or 0 when the URLs are unusable.
     */
    public function addManual( string $sourceUrl, string $targetUrl, int $userId ): int {
        try {
            $source = Url::normalize( $sourceUrl );
            $target = Url::normalize( $targetUrl );
            if ( '' === $target ) {
                $target = $this->homeUrl();
            }
            if ( '' === $source || ! $this->validHost( Url::host( $source ) ) ) {
                $this->logger->warning( 'monitoring', 'Manual backlink rejected: invalid source URL', [ 'source' => $sourceUrl ] );
                return 0;
            }
            if ( ! $this->validHost( Url::host( $target ) ) ) {
                $this->logger->warning( 'monitoring', 'Manual backlink rejected: invalid target URL', [ 'target' => $targetUrl ] );
                return 0;
            }
            if ( Url::isSameSite( $source, $target ) ) {
                $this->logger->warning( 'monitoring', 'Manual backlink rejected: source and target are the same site', [ 'source' => $source ] );
                return 0;
            }
            $now              = Repository::now();
            [ $id, $created ] = $this->backlinks->upsert( $source, $target, [ 'next_check_at' => $now ] );
            if ( $created ) {
                $this->backlinks->update( $id, [ 'origin' => 'manual', 'status' => Backlinks::STATUS_PENDING, 'placement' => 'unknown', 'updated_at' => $now ] );
            } else {
                $row = $this->backlinks->find( $id );
                if ( $row && Backlinks::STATUS_UNVERIFIED === (string) ( $row['status'] ?? '' ) ) {
                    $this->backlinks->update( $id, [ 'status' => Backlinks::STATUS_PENDING, 'updated_at' => $now ] );
                }
            }
            $this->audit->record(
                $userId > 0 ? 'user:' . $userId : AuditLog::currentActor(),
                $created ? 'backlink.added' : 'backlink.recheck_requested',
                'backlink',
                $id,
                [ 'source_url' => $source, 'target_url' => $target, 'created' => $created ]
            );
            return $id;
        } catch ( \Throwable $e ) {
            $this->logger->error( 'monitoring', 'Manual backlink could not be saved', [ 'error' => $e->getMessage() ] );
            return 0;
        }
    }

    /** Makes the backlink due now (unverified rows go back to pending so the scheduler picks them up). */
    public function scheduleCheck( int $id ): bool {
        try {
            $row = $this->backlinks->find( $id );
            if ( ! $row ) {
                return false;
            }
            $data = [ 'next_check_at' => Repository::now(), 'updated_at' => Repository::now() ];
            if ( Backlinks::STATUS_UNVERIFIED === (string) ( $row['status'] ?? '' ) ) {
                $data['status'] = Backlinks::STATUS_PENDING;
            }
            return $this->backlinks->update( $id, $data );
        } catch ( \Throwable $e ) {
            $this->logger->error( 'monitoring', 'Could not schedule backlink check', [ 'backlink_id' => $id, 'error' => $e->getMessage() ] );
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Check pipeline
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $backlink
     * @return array{status:string,changed:bool,lost:bool,recovered:bool,note:string}
     */
    private function inspect( array $backlink ): array {
        $prev      = (string) ( $backlink['status'] ?? Backlinks::STATUS_PENDING );
        $sourceUrl = Url::normalize( (string) ( $backlink['source_url'] ?? '' ) );
        $targetUrl = (string) ( $backlink['target_url'] ?? '' );
        $changes   = $this->changeList( $backlink );
        $result    = $this->result( $prev );
        $now       = Repository::now();

        if ( '' === $sourceUrl ) {
            if ( $prev !== Backlinks::STATUS_UNVERIFIED ) {
                $changes[] = $this->change( 'status', $prev, Backlinks::STATUS_UNVERIFIED, $now );
            }
            return $this->finish( $backlink, [ 'status' => Backlinks::STATUS_UNVERIFIED, 'last_checked' => $now, 'next_check_at' => null ], $changes, $result, 'Source URL is invalid; monitoring stopped.', $prev !== Backlinks::STATUS_UNVERIFIED ? 'unverified' : '' );
        }

        $resp = $this->client->crawl( $sourceUrl, [ 'timeout' => $this->timeout() ] );

        // 1. The crawler refused the page because of robots.txt. It also refuses when robots.txt itself
        //    cannot be fetched (network error, DNS failure, 5xx): that is an outage, not a Disallow, so
        //    robots.txt is re-read once to tell the two apart. The page itself is never fetched here.
        if ( in_array( (string) $resp->errorCode, [ 'robots_disallow', 'robots_unavailable' ], true ) ) {
            $robots = $this->robotsVerdict( $sourceUrl );
            if ( 'disallow' === $robots['verdict'] ) {
                if ( $prev !== Backlinks::STATUS_UNVERIFIED ) {
                    $changes[] = $this->change( 'status', $prev, Backlinks::STATUS_UNVERIFIED, $now );
                }
                return $this->finish(
                    $backlink,
                    [ 'status' => Backlinks::STATUS_UNVERIFIED, 'last_checked' => $now, 'next_check_at' => $this->inDays( self::ROBOTS_RETRY_DAYS ) ],
                    $changes,
                    $result,
                    'robots.txt disallows crawling this page; verification skipped for 30 days.',
                    $prev !== Backlinks::STATUS_UNVERIFIED ? 'unverified' : ''
                );
            }
            $note = 'unavailable' === $robots['verdict']
                ? sprintf( 'robots.txt of the linking site could not be fetched (%s), so the page was not crawled.', $robots['error'] )
                : 'robots.txt of the linking site was briefly unavailable, so the page was not crawled.';
            return $this->transient( $backlink, $resp, $robots['dns'], $note, $changes, $result );
        }

        // 2. Transient problems (network errors, timeouts, 5xx, rate limiting): note only.
        if ( null !== $resp->error || $this->isTransientStatus( $resp->status ) ) {
            $note = null !== $resp->error
                ? 'Temporary fetch error: ' . $resp->error
                : sprintf( 'Temporary HTTP %d from the linking page.', $resp->status );
            return $this->transient( $backlink, $resp, null !== $resp->error && $this->isDnsFailure( $resp->error ), $note, $changes, $result );
        }

        // 3. Page gone.
        if ( in_array( $resp->status, [ 404, 410 ], true ) ) {
            return $this->strike( $backlink, $resp, Backlinks::STATUS_BROKEN, sprintf( 'The linking page returned HTTP %d.', $resp->status ), $changes, $result );
        }

        // 4. Redirected to another domain.
        if ( $resp->redirected() && '' !== $this->domainOf( $resp->finalUrl ) && $this->domainOf( $resp->finalUrl ) !== $this->domainOf( $sourceUrl ) ) {
            return $this->redirected( $backlink, $resp, $changes, $result );
        }

        // 5. Other non-success codes (401/403/3xx without body): inconclusive, no strike.
        if ( ! $resp->ok() ) {
            return $this->finish(
                $backlink,
                [ 'last_checked' => $now, 'http_status' => $resp->status, 'next_check_at' => $this->activeSchedule( $backlink ) ],
                $changes,
                $result,
                sprintf( 'Could not verify: HTTP %d (possibly bot protection). Not counted as a failure.', $resp->status ),
                ''
            );
        }

        // 6. Not an HTML document (PDF, feed, ...): cannot parse links.
        if ( ! $resp->isHtml() ) {
            return $this->finish(
                $backlink,
                [ 'last_checked' => $now, 'http_status' => $resp->status, 'next_check_at' => $this->activeSchedule( $backlink ) ],
                $changes,
                $result,
                sprintf( 'Could not verify: non-HTML response (%s). Not counted as a failure.', $resp->contentType() ?: 'unknown type' ),
                ''
            );
        }

        // 7. Parse the page and look for our link.
        $page       = $this->analyzer->fromResponse( $resp );
        $domain     = $this->siteDomain( $targetUrl );
        $candidates = $page->linksTo( $domain );
        [ $link, $exact ] = $this->selectLink( $candidates, $targetUrl );
        $extra      = '';

        if ( null !== $link && ! $exact ) {
            $newTarget = Url::normalize( (string) $link['url'] ) ?: (string) $link['url'];
            $other     = $this->backlinks->findOneBy( [ 'source_hash' => (string) ( $backlink['source_hash'] ?? sha1( $sourceUrl ) ), 'target_hash' => sha1( $newTarget ) ] );
            if ( $other && (int) $other['id'] !== (int) $backlink['id'] ) {
                $extra = sprintf( ' The page links to %s instead, which is tracked separately (#%d).', $newTarget, (int) $other['id'] );
                $link  = null;
            }
        }

        if ( null === $link ) {
            return $this->strike( $backlink, $resp, Backlinks::STATUS_LOST, 'The page loaded but no longer links to ' . $targetUrl . '.' . $extra, $changes, $result );
        }

        return $this->confirm( $backlink, $resp, $page, $link, $exact, $changes, $result );
    }

    /**
     * Link present: refresh attributes, record changes, handle recovery.
     *
     * @param array<string,mixed> $backlink
     * @param array<string,mixed> $link
     * @param array<int,array<string,mixed>> $changes
     * @param array{status:string,changed:bool,lost:bool,recovered:bool,note:string} $result
     * @return array{status:string,changed:bool,lost:bool,recovered:bool,note:string}
     */
    private function confirm( array $backlink, Response $resp, PageAnalysis $page, array $link, bool $exact, array $changes, array $result ): array {
        $id        = (int) $backlink['id'];
        $prev      = (string) ( $backlink['status'] ?? Backlinks::STATUS_PENDING );
        $now       = Repository::now();
        $seenLive  = '' !== trim( (string) ( $backlink['last_seen_live'] ?? '' ) );
        $anchor    = mb_substr( trim( preg_replace( '/\s+/u', ' ', (string) ( $link['anchor'] ?? '' ) ) ?? '' ), 0, 500 );
        $rel       = $this->relString( (array) ( $link['rel'] ?? [] ) );
        $placement = $this->placement( (string) ( $link['region'] ?? '' ), (string) ( $backlink['placement'] ?? '' ) );
        $indexable = $page->isIndexable() && ! $page->canonicalMismatch();
        $target    = $exact ? (string) ( $backlink['target_url'] ?? '' ) : ( Url::normalize( (string) $link['url'] ) ?: (string) $link['url'] );
        $quality   = $this->qualityScore( $indexable, $rel, $placement, $anchor, $resp->finalUrl ?: (string) ( $backlink['source_url'] ?? '' ) );

        $update = [
            'anchor_text'          => $anchor,
            'rel'                  => $rel,
            'placement'            => $placement,
            'is_indexable'         => $indexable ? 1 : 0,
            'http_status'          => $resp->status,
            'status'               => Backlinks::STATUS_ACTIVE,
            'consecutive_failures' => 0,
            'quality_score'        => $quality,
            'last_checked'         => $now,
            'last_seen_live'       => $now,
            'lost_at'              => null,
            'next_check_at'        => $this->activeSchedule( $backlink ),
        ];

        $fieldChanges = [];
        if ( ! $exact ) {
            $update['target_url']        = mb_substr( $target, 0, 700 );
            $update['target_hash']       = sha1( $target );
            $fieldChanges['target_url']  = [ (string) ( $backlink['target_url'] ?? '' ), $target ];
        }
        if ( $seenLive ) {
            foreach ( [ 'anchor_text' => $anchor, 'rel' => $rel, 'placement' => $placement ] as $field => $value ) {
                $old = (string) ( $backlink[ $field ] ?? '' );
                if ( $old !== $value ) {
                    $fieldChanges[ $field ] = [ $old, $value ];
                }
            }
        }
        foreach ( $fieldChanges as $field => $pair ) {
            $changes[] = $this->change( $field, $pair[0], $pair[1], $now );
        }
        $statusChanged = Backlinks::STATUS_ACTIVE !== $prev;
        if ( $statusChanged ) {
            $changes[] = $this->change( 'status', $prev, Backlinks::STATUS_ACTIVE, $now );
        }
        $recovered = in_array( $prev, [ Backlinks::STATUS_LOST, Backlinks::STATUS_BROKEN ], true );

        $notes   = [];
        $notes[] = sprintf( 'Link confirmed live (%s, %s, %s).', $rel, $placement, $indexable ? 'indexable' : 'not indexable' );
        if ( ! $exact ) {
            $notes[] = 'Target changed to ' . $target . '.';
        }
        if ( ! empty( $link['hidden'] ) ) {
            $notes[] = 'Warning: the link is hidden with CSS.';
        }
        if ( $resp->redirected() ) {
            $notes[] = 'Page moved to ' . $resp->finalUrl . ' (same site).';
        }
        if ( ! empty( $fieldChanges ) ) {
            $notes[] = 'Changed: ' . implode( ', ', array_keys( $fieldChanges ) ) . '.';
        }

        $event  = $recovered ? 'recovered' : ( $statusChanged ? 'verified' : ( ! empty( $fieldChanges ) ? 'changed' : '' ) );
        $result = $this->finish( $backlink, $update, $changes, $result, implode( ' ', $notes ), $event, [ 'fields' => array_keys( $fieldChanges ), 'quality_score' => $quality ] );

        $result['recovered'] = $recovered;
        $result['changed']   = ! empty( $fieldChanges );

        $data = $this->notificationData( $backlink, [ 'target_url' => $target, 'rel' => $rel, 'placement' => $placement, 'quality_score' => $quality ] );
        if ( $recovered ) {
            $this->notifier->notify( 'backlink', 'success', 'Backlink recovered', sprintf( '%s links to %s again (%s, %s). Found on %s.', $this->sourceDomain( $backlink ), $target, $rel, $placement, (string) ( $backlink['source_url'] ?? '' ) ), $data );
        } elseif ( $statusChanged && Backlinks::STATUS_PENDING === $prev && ! $this->isBulkOrigin( $backlink ) ) {
            $this->notifier->notify( 'backlink', 'info', 'Backlink confirmed', sprintf( '%s links to %s (%s, %s, quality %d/100).', $this->sourceDomain( $backlink ), $target, $rel, $placement, $quality ), $data );
        }
        $notable = array_intersect( array_keys( $fieldChanges ), [ 'anchor_text', 'rel', 'target_url' ] );
        if ( ! empty( $notable ) ) {
            $parts = [];
            foreach ( $notable as $field ) {
                $parts[] = sprintf( '%s "%s" -> "%s"', str_replace( '_', ' ', $field ), mb_substr( $fieldChanges[ $field ][0], 0, 120 ), mb_substr( $fieldChanges[ $field ][1], 0, 120 ) );
            }
            $this->notifier->notify( 'backlink', 'info', 'Backlink changed', sprintf( 'The link from %s changed: %s.', (string) ( $backlink['source_url'] ?? '' ), implode( '; ', $parts ) ), array_merge( $data, [ 'fields' => array_values( $notable ) ] ) );
        }

        return $result;
    }

    /**
     * Link missing or page gone: count a strike; mark lost/broken once the threshold is reached.
     *
     * @param array<string,mixed> $backlink
     * @param array<int,array<string,mixed>> $changes
     * @param array{status:string,changed:bool,lost:bool,recovered:bool,note:string} $result
     * @return array{status:string,changed:bool,lost:bool,recovered:bool,note:string}
     */
    private function strike( array $backlink, Response $resp, string $lostStatus, string $reason, array $changes, array $result ): array {
        $prev        = (string) ( $backlink['status'] ?? Backlinks::STATUS_PENDING );
        $now         = Repository::now();
        $failures    = max( 0, (int) ( $backlink['consecutive_failures'] ?? 0 ) ) + 1;
        $threshold   = max( 1, (int) $this->settings->get( 'monitoring.lost_after_failures', 2 ) );
        $alreadyLost = in_array( $prev, [ Backlinks::STATUS_LOST, Backlinks::STATUS_BROKEN ], true );
        $update      = [ 'consecutive_failures' => min( 255, $failures ), 'last_checked' => $now, 'http_status' => $resp->status > 0 ? $resp->status : null ];

        if ( ! $alreadyLost && $failures < $threshold ) {
            $update['next_check_at'] = $this->inDays( self::STRIKE_RETRY_DAYS );
            return $this->finish( $backlink, $update, $changes, $result, sprintf( '%s Strike %d of %d; status unchanged until confirmed again tomorrow.', $reason, $failures, $threshold ), '' );
        }

        $lostAt = $alreadyLost && '' !== trim( (string) ( $backlink['lost_at'] ?? '' ) ) ? (string) $backlink['lost_at'] : $now;
        $update['lost_at'] = $lostAt;

        // Lost for more than 60 days: stop monitoring.
        if ( $alreadyLost && $this->daysSince( $lostAt ) >= self::LOST_GIVE_UP_DAYS ) {
            $update['status']        = Backlinks::STATUS_UNVERIFIED;
            $update['next_check_at'] = null;
            $changes[]               = $this->change( 'status', $prev, Backlinks::STATUS_UNVERIFIED, $now );
            return $this->finish( $backlink, $update, $changes, $result, $reason . ' Lost for more than 60 days; monitoring stopped (unverified).', 'unverified' );
        }

        $update['status']        = $lostStatus;
        $update['next_check_at'] = $this->inDays( self::LOST_RECHECK_DAYS );

        if ( $alreadyLost ) {
            $event = $prev !== $lostStatus ? 'status_changed' : '';
            if ( $prev !== $lostStatus ) {
                $changes[] = $this->change( 'status', $prev, $lostStatus, $now );
            }
            $until = gmdate( 'Y-m-d', $this->timestamp( $lostAt ) + self::LOST_GIVE_UP_DAYS * self::DAY );
            return $this->finish( $backlink, $update, $changes, $result, sprintf( '%s Still %s after %d consecutive failures; weekly re-checks continue until %s.', $reason, $lostStatus, $failures, $until ), $event );
        }

        // Transition to lost/broken.
        $changes[]      = $this->change( 'status', $prev, $lostStatus, $now );
        $result         = $this->finish( $backlink, $update, $changes, $result, sprintf( '%s %d consecutive failures: marked %s.', $reason, $failures, $lostStatus ), 'lost', [ 'failures' => $failures, 'threshold' => $threshold ] );
        $result['lost'] = true;

        $seenLive  = '' !== trim( (string) ( $backlink['last_seen_live'] ?? '' ) );
        $confirmed = $seenLive || ! $this->isBulkOrigin( $backlink );
        $severity  = $confirmed ? 'critical' : 'warning';
        $message   = sprintf(
            '%s no longer links to %s. %s %d consecutive checks failed, so the link is now marked %s.%s',
            $this->sourceDomain( $backlink ),
            (string) ( $backlink['target_url'] ?? '' ),
            $reason,
            $failures,
            $lostStatus,
            $confirmed ? '' : ' This link came from an import and was never confirmed live, so it may never have existed.'
        );
        $this->notifier->notify( 'backlink', $severity, 'Backlink lost', $message, $this->notificationData( $backlink, [ 'failures' => $failures, 'http_status' => $resp->status, 'new_status' => $lostStatus, 'confirmed_before' => $confirmed ] ) );

        return $result;
    }

    /**
     * Temporary failure (network error, timeout, 5xx, robots.txt unreachable): note only, retry tomorrow.
     * A domain that does not resolve although the link was last reached DNS_STRIKE_DAYS or more ago is
     * treated as gone and counts as a strike, so links on dead or expired domains still reach "broken".
     *
     * @param array<string,mixed> $backlink
     * @param array<int,array<string,mixed>> $changes
     * @param array{status:string,changed:bool,lost:bool,recovered:bool,note:string} $result
     * @return array{status:string,changed:bool,lost:bool,recovered:bool,note:string}
     */
    private function transient( array $backlink, Response $resp, bool $dnsFailure, string $note, array $changes, array $result ): array {
        if ( $dnsFailure ) {
            $days = $this->daysSinceReached( $backlink );
            if ( $days >= self::DNS_STRIKE_DAYS ) {
                $reason = sprintf( 'The domain of the linking site does not resolve, and the link has not been confirmed for %d days.', (int) floor( $days ) );
                return $this->strike( $backlink, $resp, Backlinks::STATUS_BROKEN, $reason, $changes, $result );
            }
        }
        $update = [ 'last_checked' => Repository::now(), 'next_check_at' => $this->inDays( self::TRANSIENT_RETRY_DAYS ) ];
        if ( $resp->status > 0 ) {
            $update['http_status'] = $resp->status;
        }
        return $this->finish( $backlink, $update, $changes, $result, rtrim( $note, '.' ) . '. Not counted as a failure; retrying tomorrow.', '' );
    }

    /**
     * Re-reads robots.txt after the crawler refused a page. Returns "unavailable" when robots.txt cannot
     * be fetched (network error, DNS failure, 5xx), "disallow" when it loads and disallows the page, and
     * "allow" when a fresh copy allows it (4xx = no robots.txt, or 200 without a matching Disallow): the
     * refusal then came from a cached failure. Only "disallow" stops monitoring for 30 days; the page is
     * not fetched in any case.
     *
     * @return array{verdict:string,error:string,dns:bool}
     */
    private function robotsVerdict( string $url ): array {
        $scheme = (string) ( wp_parse_url( $url, PHP_URL_SCHEME ) ?: 'https' );
        $origin = $scheme . '://' . Url::host( $url );
        $probe  = $this->robotsProbes[ $origin ] ?? null;
        if ( null === $probe || $probe['at'] < time() - self::ROBOTS_PROBE_TTL ) {
            $r     = $this->client->rawRequest( 'GET', $origin . '/robots.txt', [ 'timeout' => self::ROBOTS_PROBE_TIMEOUT ] );
            $probe = [ 'at' => time(), 'error' => (string) ( $r->error ?? '' ), 'status' => $r->status, 'body' => 200 === $r->status ? $r->body : '' ];
            $this->robotsProbes[ $origin ] = $probe;
        }
        if ( '' !== $probe['error'] || 0 === $probe['status'] || $probe['status'] >= 500 ) {
            return [
                'verdict' => 'unavailable',
                'error'   => '' !== $probe['error'] ? $probe['error'] : sprintf( 'HTTP %d', $probe['status'] ),
                'dns'     => $this->isDnsFailure( $probe['error'] ),
            ];
        }
        if ( 200 !== $probe['status'] ) {
            return [ 'verdict' => 'allow', 'error' => '', 'dns' => false ];
        }
        $path  = Url::path( $url );
        $query = wp_parse_url( $url, PHP_URL_QUERY );
        if ( is_string( $query ) && '' !== $query ) {
            $path .= '?' . $query;
        }
        $rules   = RobotsTxt::parse( $probe['body'] )['rules'];
        $allowed = RobotsTxt::evaluate( $rules[ RobotsTxt::BOT_TOKEN ] ?? $rules['*'] ?? [], $path );
        return [ 'verdict' => $allowed ? 'allow' : 'disallow', 'error' => '', 'dns' => false ];
    }

    /** Whether a transport error means the host name does not resolve (as opposed to a timeout or refusal). */
    private function isDnsFailure( string $error ): bool {
        return '' !== $error && 1 === preg_match( '/could not resolve host|name or service not known|nodename nor servname|no address associated with hostname|getaddrinfo .*failed|nxdomain/i', $error );
    }

    /**
     * Days since the link was last confirmed live, or since monitoring started when it never was.
     *
     * @param array<string,mixed> $backlink
     */
    private function daysSinceReached( array $backlink ): float {
        $seen    = $this->timestamp( (string) ( $backlink['last_seen_live'] ?? '' ) );
        $created = $this->timestamp( (string) ( $backlink['created_at'] ?? '' ) );
        $ref     = max( $seen, $created );
        return 0 === $ref ? 0.0 : ( time() - $ref ) / self::DAY;
    }

    /**
     * @param array<string,mixed> $backlink
     * @param array<int,array<string,mixed>> $changes
     * @param array{status:string,changed:bool,lost:bool,recovered:bool,note:string} $result
     * @return array{status:string,changed:bool,lost:bool,recovered:bool,note:string}
     */
    private function redirected( array $backlink, Response $resp, array $changes, array $result ): array {
        $prev   = (string) ( $backlink['status'] ?? Backlinks::STATUS_PENDING );
        $now    = Repository::now();
        $update = [
            'status'        => Backlinks::STATUS_REDIRECTED,
            'http_status'   => $resp->status,
            'last_checked'  => $now,
            'next_check_at' => $this->inDays( $this->stableIntervalDays() ),
        ];
        $isNew  = Backlinks::STATUS_REDIRECTED !== $prev;
        if ( $isNew ) {
            $changes[] = $this->change( 'status', $prev, Backlinks::STATUS_REDIRECTED, $now );
            $changes[] = $this->change( 'redirect', (string) ( $backlink['source_url'] ?? '' ), $resp->finalUrl, $now );
        }
        $note   = sprintf( 'The linking page now redirects to %s (different domain). Not counted as a failure.', $resp->finalUrl );
        $result = $this->finish( $backlink, $update, $changes, $result, $note, $isNew ? 'redirected' : '', [ 'redirect_to' => $resp->finalUrl ] );
        if ( $isNew ) {
            $this->notifier->notify( 'backlink', 'warning', 'Backlink redirected', sprintf( '%s now redirects to %s; the link to %s can no longer be verified there.', (string) ( $backlink['source_url'] ?? '' ), $resp->finalUrl, (string) ( $backlink['target_url'] ?? '' ) ), $this->notificationData( $backlink, [ 'redirect_to' => $resp->finalUrl ] ) );
        }
        return $result;
    }

    /**
     * Persists the outcome, audits state changes and fills the result.
     *
     * @param array<string,mixed> $backlink
     * @param array<string,mixed> $update
     * @param array<int,array<string,mixed>> $changes
     * @param array{status:string,changed:bool,lost:bool,recovered:bool,note:string} $result
     * @param array<string,mixed> $details
     * @return array{status:string,changed:bool,lost:bool,recovered:bool,note:string}
     */
    private function finish( array $backlink, array $update, array $changes, array $result, string $note, string $event, array $details = [] ): array {
        $id                   = (int) $backlink['id'];
        $prev                 = (string) ( $backlink['status'] ?? Backlinks::STATUS_PENDING );
        $status               = (string) ( $update['status'] ?? $prev );
        $update['changes']    = array_values( array_slice( $changes, -self::MAX_CHANGES ) );
        $update['updated_at'] = Repository::now();
        $this->backlinks->update( $id, $update );

        $result['status'] = $status;
        $result['note']   = $note;
        $this->logger->debug( 'monitoring', 'Backlink checked', [ 'backlink_id' => $id, 'from' => $prev, 'to' => $status, 'note' => $note ] );

        if ( '' !== $event ) {
            $this->audit->record(
                AuditLog::currentActor(),
                'backlink.' . $event,
                'backlink',
                $id,
                array_merge(
                    [
                        'from'       => $prev,
                        'to'         => $status,
                        'source_url' => (string) ( $backlink['source_url'] ?? '' ),
                        'target_url' => (string) ( $update['target_url'] ?? $backlink['target_url'] ?? '' ),
                        'note'       => $note,
                    ],
                    $details
                )
            );
        }
        return $result;
    }

    // ------------------------------------------------------------------
    // Link selection and attribute helpers
    // ------------------------------------------------------------------

    /**
     * Picks the best link to the monitored target: exact URL matches first, else any link to our domain.
     *
     * @param array<int,array<string,mixed>> $candidates
     * @return array{0:array<string,mixed>|null,1:bool} [link, exactMatch]
     */
    private function selectLink( array $candidates, string $targetUrl ): array {
        if ( empty( $candidates ) ) {
            return [ null, false ];
        }
        $exact = array_values( array_filter( $candidates, fn( array $l ): bool => $this->sameUrl( (string) ( $l['url'] ?? '' ), $targetUrl ) ) );
        if ( ! empty( $exact ) ) {
            return [ $this->bestLink( $exact ), true ];
        }
        return [ $this->bestLink( $candidates ), false ];
    }

    /**
     * @param array<int,array<string,mixed>> $links
     * @return array<string,mixed>
     */
    private function bestLink( array $links ): array {
        usort( $links, fn( array $a, array $b ): int => $this->linkRank( $b ) <=> $this->linkRank( $a ) );
        return $links[0];
    }

    /** @param array<string,mixed> $l */
    private function linkRank( array $l ): int {
        $rank = 0;
        if ( empty( $l['hidden'] ) ) {
            $rank += 100;
        }
        if ( 'dofollow' === $this->relString( (array) ( $l['rel'] ?? [] ) ) ) {
            $rank += 20;
        }
        $rank += [ 'content' => 10, 'directory' => 8, 'sidebar' => 5, 'footer' => 2, 'header' => 1 ][ (string) ( $l['region'] ?? '' ) ] ?? 0;
        if ( '' !== trim( (string) ( $l['anchor'] ?? '' ) ) ) {
            $rank += 3;
        }
        return $rank;
    }

    private function sameUrl( string $a, string $b ): bool {
        $ka = $this->urlKey( $a );
        return '' !== $ka && $ka === $this->urlKey( $b );
    }

    /** Comparison key: scheme, "www." and trailing slash ignored. */
    private function urlKey( string $url ): string {
        $n = Url::normalize( $url );
        if ( '' === $n ) {
            return '';
        }
        $n = preg_replace( '#^https?://(www\.)?#i', '', $n ) ?? $n;
        return rtrim( strtolower( $n ), '/' );
    }

    /** @param array<int,mixed> $rel */
    private function relString( array $rel ): string {
        $rel   = array_map( static fn( $r ): string => strtolower( trim( (string) $r ) ), $rel );
        $flags = array_values( array_intersect( [ 'nofollow', 'sponsored', 'ugc' ], $rel ) );
        return empty( $flags ) ? 'dofollow' : implode( ',', $flags );
    }

    private function placement( string $region, string $previous ): string {
        $region = strtolower( trim( $region ) );
        if ( 'directory' === $previous && in_array( $region, [ 'content', '' ], true ) ) {
            return 'directory';
        }
        return in_array( $region, self::PLACEMENTS, true ) ? $region : 'unknown';
    }

    /** Simple 0-100 quality score: indexable 30, dofollow 25, placement up to 25, descriptive anchor 10, https 10. */
    private function qualityScore( bool $indexable, string $rel, string $placement, string $anchor, string $sourceUrl ): int {
        $score = 0;
        if ( $indexable ) {
            $score += 30;
        }
        if ( 'dofollow' === $rel ) {
            $score += 25;
        }
        $score += self::PLACEMENT_POINTS[ $placement ] ?? 0;
        if ( $this->isDescriptiveAnchor( $anchor ) ) {
            $score += 10;
        }
        if ( str_starts_with( strtolower( $sourceUrl ), 'https://' ) ) {
            $score += 10;
        }
        return max( 0, min( 100, $score ) );
    }

    private function isDescriptiveAnchor( string $anchor ): bool {
        $a = mb_strtolower( trim( $anchor ) );
        if ( mb_strlen( $a ) < 3 ) {
            return false;
        }
        if ( in_array( $a, self::GENERIC_ANCHORS, true ) ) {
            return false;
        }
        if ( preg_match( '#^(https?://|www\.)#', $a ) || preg_match( '#^[a-z0-9.-]+\.[a-z]{2,}(/\S*)?$#', $a ) ) {
            return false;
        }
        return (bool) preg_match( '/\p{L}{3,}/u', $a );
    }

    // ------------------------------------------------------------------
    // Scheduling helpers
    // ------------------------------------------------------------------

    /** Next check for a live link: the "new" interval during the first 14 days, then the stable interval. */
    private function activeSchedule( array $backlink ): string {
        $firstSeen = $this->timestamp( (string) ( $backlink['first_seen'] ?? '' ) );
        $isNew     = 0 === $firstSeen || ( time() - $firstSeen ) < self::NEW_LINK_DAYS * self::DAY;
        $days      = $isNew ? max( 1, (int) $this->settings->get( 'monitoring.check_interval_days_new', 1 ) ) : $this->stableIntervalDays();
        return $this->inDays( $days );
    }

    private function stableIntervalDays(): int {
        return max( 1, (int) $this->settings->get( 'monitoring.check_interval_days_stable', 7 ) );
    }

    private function isTransientStatus( int $status ): bool {
        return 0 === $status || $status >= 500 || in_array( $status, [ 408, 425, 429 ], true );
    }

    private function timeout(): int {
        return max( 5, min( 20, (int) $this->settings->get( 'advanced.request_timeout', 15 ) ) );
    }

    private function inDays( int $days ): string {
        return gmdate( 'Y-m-d H:i:s', time() + max( 0, $days ) * self::DAY );
    }

    private function timestamp( string $utc ): int {
        $utc = trim( $utc );
        if ( '' === $utc ) {
            return 0;
        }
        $ts = strtotime( $utc . ' UTC' );
        return false === $ts ? 0 : $ts;
    }

    private function daysSince( string $utc ): float {
        $ts = $this->timestamp( $utc );
        return 0 === $ts ? 0.0 : ( time() - $ts ) / self::DAY;
    }

    // ------------------------------------------------------------------
    // Misc helpers
    // ------------------------------------------------------------------

    /** Domain our link must point at: the target's registrable domain, else the configured site domain. */
    private function siteDomain( string $targetUrl ): string {
        $d = Url::registrableDomain( $targetUrl );
        return '' !== $d ? $d : $this->settings->siteDomain();
    }

    private function homeUrl(): string {
        $home = (string) $this->settings->get( 'site.home_url', '' );
        if ( '' === $home ) {
            $home = home_url( '/' );
        }
        return Url::normalize( $home ) ?: $home;
    }

    private function domainOf( string $url ): string {
        return Url::registrableDomain( $url );
    }

    /** Hostname sanity check (public DNS name or IP literal); Url::normalize alone accepts garbage like "not a url". */
    private function validHost( string $host ): bool {
        $host = strtolower( trim( $host ) );
        if ( '' === $host ) {
            return false;
        }
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return true;
        }
        return (bool) preg_match( '/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $host );
    }

    /** @param array<string,mixed> $backlink */
    private function sourceDomain( array $backlink ): string {
        $d = (string) ( $backlink['source_domain'] ?? '' );
        return '' !== $d ? $d : Url::registrableDomain( (string) ( $backlink['source_url'] ?? '' ) );
    }

    /** @param array<string,mixed> $backlink */
    private function isBulkOrigin( array $backlink ): bool {
        $origin = strtolower( (string) ( $backlink['origin'] ?? '' ) );
        return in_array( $origin, self::BULK_ORIGINS, true ) || str_starts_with( $origin, 'provider:' );
    }

    /**
     * @param array<string,mixed> $backlink
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function notificationData( array $backlink, array $extra = [] ): array {
        return array_merge(
            [
                'backlink_id'   => (int) $backlink['id'],
                'prospect_id'   => (int) ( $backlink['prospect_id'] ?? 0 ),
                'source_domain' => $this->sourceDomain( $backlink ),
                'source_url'    => (string) ( $backlink['source_url'] ?? '' ),
                'target_url'    => (string) ( $backlink['target_url'] ?? '' ),
                'origin'        => (string) ( $backlink['origin'] ?? '' ),
            ],
            $extra
        );
    }

    /**
     * @param array<string,mixed> $backlink
     * @return array<int,array<string,mixed>>
     */
    private function changeList( array $backlink ): array {
        $changes = $backlink['changes'] ?? [];
        if ( ! is_array( $changes ) ) {
            return [];
        }
        return array_values( array_filter( $changes, 'is_array' ) );
    }

    /** @return array{at:string,field:string,from:string,to:string} */
    private function change( string $field, string $from, string $to, string $at ): array {
        return [ 'at' => $at, 'field' => $field, 'from' => mb_substr( $from, 0, 500 ), 'to' => mb_substr( $to, 0, 500 ) ];
    }

    /** @return array{status:string,changed:bool,lost:bool,recovered:bool,note:string} */
    private function result( string $status ): array {
        return [ 'status' => $status, 'changed' => false, 'lost' => false, 'recovered' => false, 'note' => '' ];
    }

    /** @param array<string,mixed> $data */
    private function safeUpdate( int $id, array $data ): void {
        try {
            $data['updated_at'] = Repository::now();
            $this->backlinks->update( $id, $data );
        } catch ( \Throwable $e ) {
            $this->logger->error( 'monitoring', 'Backlink update failed', [ 'backlink_id' => $id, 'error' => $e->getMessage() ] );
        }
    }
}
