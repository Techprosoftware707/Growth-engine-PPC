<?php
/**
 * Evaluation order: prospects that were not created by the verified opportunity import (new discoveries, due
 * re-reviews and machine retries) are evaluated before any imported prospect; imported prospects are still
 * evaluated once nothing else fills the batch.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Automation;

use PPC\LinkGrowth\Automation\JobRunner;
use PPC\LinkGrowth\Automation\Jobs\EvaluateJob;
use PPC\LinkGrowth\Discovery\DomainFilter;
use PPC\LinkGrowth\Discovery\VerifiedOpportunities;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Support\Url;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_Error;

final class EvaluationOrderTest extends TestCase {

    private const DATA = PPCLG_DIR . 'data/verified-opportunities.json';

    /** @var string[] */
    private array $tmp = [];

    protected function setUp(): void {
        parent::setUp();
        Container::get( Settings::class )->set( 'advanced.crawl_delay_seconds', 0 );
        // Every site is down: the outcome does not matter here, only which prospects are evaluated and in what order.
        HttpFixtures::$handler = static function ( string $url ) {
            if ( str_ends_with( $url, '/robots.txt' ) ) {
                return [ 'response' => [ 'code' => 404, 'message' => 'Not Found' ], 'headers' => [ 'content-type' => 'text/plain' ], 'body' => '', 'cookies' => [] ];
            }
            return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
        };
    }

    protected function tearDown(): void {
        foreach ( $this->tmp as $f ) {
            if ( is_file( $f ) ) {
                unlink( $f );
            }
        }
        $this->tmp = [];
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    private function prospects(): Prospects {
        return Container::get( Prospects::class );
    }

    /**
     * Imports $count items of the real verified database through the real importer; $existing of their sites
     * were already prospects.
     *
     * @return int[] ids of the prospects created by the import, ascending
     */
    private function importItems( int $count, int $existing = 0 ): array {
        $data          = self::data();
        $data['items'] = self::distinctItems( $data['items'], $count );
        $file          = tempnam( sys_get_temp_dir(), 'ppclg-order-' );
        $this->assertIsString( $file );
        file_put_contents( $file, (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        $this->tmp[] = $file;
        $vo          = VerifiedOpportunities::fromContainer( Container::plugin()->container(), $file );
        $r           = $vo->import( TimeBudget::seconds( 60 ), 100 );
        $this->assertTrue( $r['done'] );
        $ids = array_map( static fn( array $row ): int => (int) $row['id'], $this->prospects()->findBy( [ 'discovered_via' => VerifiedOpportunities::SOURCE ], 'id', 'ASC', 1000 ) );
        $this->assertCount( $count - $existing, $ids, 'one new prospect per imported item' );
        return $ids;
    }

    /** @return array<string,mixed> */
    private static function data(): array {
        $data = json_decode( (string) file_get_contents( self::DATA ), true );
        self::assertIsArray( $data );
        return $data;
    }

    /**
     * The first $count free items of the verified database whose sites are all different and accepted by the intake.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private static function distinctItems( array $items, int $count ): array {
        $out     = [];
        $domains = [];
        foreach ( $items as $item ) {
            $url    = Url::normalize( (string) ( $item['website'] ?? '' ) );
            $domain = Url::registrableDomain( $url );
            if ( '' === $domain || isset( $domains[ $domain ] ) || VerifiedOpportunities::isPaidOnly( $item ) || null !== Container::get( DomainFilter::class )->reason( $url ) ) {
                continue;
            }
            $domains[ $domain ] = true;
            $out[]              = $item;
            if ( count( $out ) === $count ) {
                break;
            }
        }
        self::assertCount( $count, $out );
        return $out;
    }

    /** @return int[] prospect ids evaluated by one automatic run, in order */
    private function evaluatedIds( int $limit ): array {
        $r = Container::get( JobRunner::class )->runType( 'evaluate', [ 'limit' => $limit ], TimeBudget::seconds( 60.0 ) );
        $this->assertSame( 0, (int) ( $r['deferred'] ?? 0 ), 'no prospect deferred by the time budget' );
        return array_map( static fn( array $i ): int => (int) $i['prospect_id'], (array) ( $r['items'] ?? [] ) );
    }

    private function past( int $seconds = 60 ): string {
        return gmdate( 'Y-m-d H:i:s', time() - $seconds );
    }

    private function future(): string {
        return gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
    }

    private function newDiscovery( string $domain, string $via = 'search' ): int {
        [ $id ] = $this->prospects()->upsertDiscovered( $domain, [ 'discovered_via' => $via ] );
        return $id;
    }

    private function dueReReview( string $domain ): int {
        $id = $this->newDiscovery( $domain );
        $this->prospects()->update( $id, [ 'status' => Prospects::STATUS_QUALIFIED, 'score' => 75, 'recommended_action' => 'Submit directory listing', 'next_review' => $this->past() ] );
        return $id;
    }

    private function parkUnreachable( int $id, int $agoSeconds = 60 ): void {
        $this->prospects()->update( $id, [ 'status' => Prospects::STATUS_REJECTED, 'rejection_reason' => 'Site unreachable (cURL error 28: Operation timed out)', 'recommended_action' => 'Do not pursue', 'next_review' => $this->past( $agoSeconds ) ] );
    }

    // ------------------------------------------------------------------ tests

    public function testNewDiscoveryIsEvaluatedBeforeImportedProspectsWithLowerIds(): void {
        $imported = $this->importItems( 4 );
        $new      = $this->newDiscovery( 'new-lab-directory.test' );
        $this->assertGreaterThan( max( $imported ), $new, 'the import came first' );

        $this->assertSame( $new, (int) $this->prospects()->dueForEvaluation( 1 )[0]['id'], 'the due queue puts the discovered prospect first' );
        $this->assertSame( [ $new ], $this->evaluatedIds( 1 ), 'the discovered prospect is evaluated first' );
        $this->assertSame( [ $imported[0], $imported[1] ], $this->evaluatedIds( 2 ), 'then the imported ones, oldest first' );
    }

    public function testDueReReviewAndMachineRetryOutrankImportedDiscoveredProspects(): void {
        $imported = $this->importItems( 4 );
        $review   = $this->dueReReview( 'review-due.test' );
        $retry    = $this->newDiscovery( 'retry-due.test' );
        $this->parkUnreachable( $retry );
        foreach ( $imported as $id ) {
            $this->assertSame( Prospects::STATUS_DISCOVERED, (string) $this->prospects()->find( $id )['status'] );
        }

        $this->assertSame( [ $review, $retry, $imported[0] ], $this->evaluatedIds( 3 ), 'non-imported re-review, then non-imported retry, then imported' );
    }

    public function testImportedProspectsAreStillEvaluatedAfterTheOthers(): void {
        $imported = $this->importItems( 3 );
        // One imported prospect is a machine retry too.
        $this->parkUnreachable( $imported[0] );
        $retry = $this->newDiscovery( 'retry-only.test' );
        $this->parkUnreachable( $retry );

        // The non-imported retry first, then imported new rows, then the imported retry.
        $this->assertSame( [ $retry, $imported[1], $imported[2] ], $this->evaluatedIds( 3 ) );
        // Only imported rows remain due: they are evaluated, nothing is skipped.
        $this->assertSame( [], $this->prospects()->dueForEvaluation( 10, false ) );
        $this->assertSame( [], EvaluateJob::retryDue( $this->prospects(), 10, false ) );
        $this->assertSame( [ $imported[0] ], $this->evaluatedIds( 10 ) );
        $this->assertSame( [], EvaluateJob::dueBatch( $this->prospects(), 10 ), 'nothing left due' );
    }

    public function testSiteThatWasAProspectBeforeTheImportRanksAsDiscovered(): void {
        $host = Url::registrableDomain( (string) self::distinctItems( self::data()['items'], 4 )[2]['website'] );
        // A seed prospect, qualified before the import; its review date is in the future.
        $seed = $this->newDiscovery( $host, 'seed:catalog' );
        $this->prospects()->update( $seed, [ 'status' => Prospects::STATUS_QUALIFIED, 'score' => 70, 'next_review' => $this->future() ] );

        $imported = $this->importItems( 4, 1 );
        $row      = $this->prospects()->find( $seed );
        $this->assertSame( 'seed:catalog', (string) $row['discovered_via'], 'the import keeps the existing discovered_via' );
        $this->assertNotContains( $seed, $imported );
        $tasks = Container::get( Submissions::class )->findBy( [ 'prospect_id' => $seed ] );
        $this->assertCount( 1, $tasks, 'the import gave the existing prospect an owner task' );
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, (string) $tasks[0]['status'] );
        $this->assertTrue( VerifiedOpportunities::isOwnerTask( $tasks[0] ) );

        // Its re-review comes due while the imported prospects are still waiting.
        $this->prospects()->update( $seed, [ 'next_review' => $this->past() ] );
        $this->assertSame( $seed, (int) $this->prospects()->dueForEvaluation( 1 )[0]['id'] );
        $this->assertSame( [ $seed ], $this->evaluatedIds( 1 ) );
    }

    public function testBatchHasNoDuplicatesRespectsTheLimitAndKeepsTheOrderInsideEachGroup(): void {
        $imported = $this->importItems( 5 );
        $this->parkUnreachable( $imported[1], 120 );
        $d1     = $this->newDiscovery( 'first-new.test' );
        $review = $this->dueReReview( 'review.test' );
        $d2     = $this->newDiscovery( 'second-new.test' );
        $retryA = $this->newDiscovery( 'retry-a.test' );
        $this->parkUnreachable( $retryA, 60 );
        $retryB = $this->newDiscovery( 'retry-b.test' );
        $this->parkUnreachable( $retryB, 600 ); // due longer ago: retried first
        $error = $this->newDiscovery( 'error-parked.test' );
        $this->prospects()->update( $error, [ 'status' => Prospects::STATUS_ON_HOLD, 'rejection_reason' => 'Evaluation error: boom', 'recommended_action' => '', 'next_review' => $this->past( 300 ) ] );

        $expected = [ $d1, $d2, $review, $retryB, $error, $retryA, $imported[0], $imported[2], $imported[3], $imported[4], $imported[1] ];
        $first    = $this->evaluatedIds( 7 );
        $this->assertCount( 7, $first, 'the limit is respected' );
        $this->assertSame( array_slice( $expected, 0, 7 ), $first );
        $due = EvaluateJob::dueBatch( $this->prospects(), 50 );
        $this->assertSame( array_slice( $expected, 7 ), array_map( static fn( array $r ): int => (int) $r['id'], $due ), 'only the imported prospects are still due' );
        $rest = $this->evaluatedIds( 50 );
        $this->assertSame( array_slice( $expected, 7 ), $rest, 'the rest follows, each once' );
        $both = array_merge( $first, $rest );
        $this->assertSame( count( $both ), count( array_unique( $both ) ), 'no prospect evaluated twice' );
    }
}
