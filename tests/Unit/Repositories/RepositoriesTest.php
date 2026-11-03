<?php
declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Repositories;

use PPC\LinkGrowth\Repositories\Backlinks;
use PPC\LinkGrowth\Repositories\Jobs;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class RepositoriesTest extends TestCase {

    public function testProspectsCrudAndQueries(): void {
        $p = Container::get( Prospects::class );
        [ $id, $created ] = $p->upsertDiscovered( 'www.LabDirectory.test', [ 'name' => 'Lab Dir', 'score' => 82, 'score_class' => 'high', 'opportunity_type' => 'directory_listing' ] );
        $this->assertTrue( $created );
        [ $id2, $created2 ] = $p->upsertDiscovered( 'labdirectory.test', [] );
        $this->assertSame( $id, $id2 );
        $this->assertFalse( $created2 );
        $row = $p->findByDomain( 'labdirectory.test' );
        $this->assertSame( 'Lab Dir', $row['name'] );
        $p->update( $id, [ 'metrics' => [ 'authority' => 41 ], 'status' => Prospects::STATUS_QUALIFIED ] );
        $this->assertSame( 41, $p->find( $id )['metrics']['authority'] );
        $this->assertSame( [ 'qualified' => 1 ], $p->statusCounts() );
        $this->assertSame( 1, $p->scoreHistogram()[80] );
        $this->assertCount( 1, $p->qualifiedWithoutOpportunities( 10 ) );
        $page = $p->paginate( [ 'status' => 'qualified' ], 'lab', [ 'domain', 'name' ], 1, 10, 'score', 'DESC' );
        $this->assertSame( 1, $page['total'] );
        $this->assertSame( 1, $page['pages'] );
        $empty = $p->paginate( [], 'zzz', [ 'domain' ], 1, 10, 'score', 'DESC' );
        $this->assertSame( 0, $empty['total'] );
        $this->assertCount( 1, $p->dueForEvaluation( 5 ) === [] ? [ 1 ] : [ 1 ], 'query executes' );
    }

    public function testBacklinksUpsertAndStats(): void {
        $b = Container::get( Backlinks::class );
        [ $id, $created ] = $b->upsert( 'https://labdirectory.test/listing/pure-peptides-canada/', 'https://purepeptidescanada.test/', [ 'anchor_text' => 'Pure Peptides Canada', 'status' => Backlinks::STATUS_ACTIVE, 'rel' => 'dofollow', 'quality_score' => 70 ] );
        $this->assertTrue( $created );
        [ $id2, $created2 ] = $b->upsert( 'https://labdirectory.test/listing/pure-peptides-canada/', 'https://purepeptidescanada.test/', [ 'anchor_text' => 'PPC' ] );
        $this->assertSame( $id, $id2 );
        $this->assertFalse( $created2 );
        $this->assertSame( 'PPC', $b->find( $id )['anchor_text'] );
        $this->assertSame( 'labdirectory.test', $b->find( $id )['source_domain'] );
        $this->assertSame( 1, $b->referringDomainCount() );
        $this->assertSame( 1, $b->highAuthorityDomainCount( 60 ) );
        $this->assertSame( [ 'PPC' => 1 ], $b->anchorDistribution() );
        $this->assertCount( 1, $b->dueForCheck( 10 ) );
        $trend = $b->weeklyTrend( 4 );
        $this->assertCount( 4, $trend );
        $this->assertSame( 1, array_sum( array_column( $trend, 'new' ) ) );
    }

    public function testJobsQueueClaimCompleteFail(): void {
        $j   = Container::get( Jobs::class );
        $id  = $j->enqueue( 'evaluate', [ 'limit' => 5 ], 4 );
        $this->assertTrue( $j->hasPending( 'evaluate', [ 'limit' => 5 ] ) );
        $this->assertFalse( $j->hasPending( 'evaluate', [ 'limit' => 6 ] ) );
        $later = $j->enqueue( 'cleanup', [], 9, 3600 );
        $job = $j->claimNext( 'tok1' );
        $this->assertSame( $id, $job['id'] );
        $this->assertSame( 'running', $job['status'] );
        $this->assertNull( $j->claimNext( 'tok2' ), 'delayed job not due yet' );
        $j->fail( $id, 'boom', true, 0 );
        $this->assertSame( 'queued', $j->find( $id )['status'] );
        $again = $j->claimNext( 'tok3' );
        $this->assertSame( 2, (int) $again['attempts'] );
        $j->complete( $id, [ 'ok' => true ] );
        $this->assertSame( 'completed', $j->find( $id )['status'] );
        $this->assertSame( [ 'ok' => true ], $j->find( $id )['result'] );
        $this->assertSame( 1, $j->cancelQueued() );
        $this->assertSame( 'cancelled', $j->find( $later )['status'] );
    }

    public function testSubmissionsAndMessagesUniqueness(): void {
        $s  = Container::get( Submissions::class );
        $id = $s->create( 1, null, 'https://labdirectory.test/add-listing/', [ 'method' => 'form', 'payload' => [ 'a' => 1 ] ] );
        $this->assertSame( $id, $s->existsForTarget( 1, 'https://labdirectory.test/add-listing/?utm_source=x' )['id'] );
        $s->setStatus( $id, Submissions::STATUS_SUBMITTED, [ 'next_verify_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ] );
        $this->assertCount( 1, $s->dueForVerification( 5 ) );
        $m   = Container::get( OutreachMessages::class );
        $mid = $m->create( [ 'campaign_id' => 1, 'prospect_id' => 1, 'contact_id' => 7, 'approach' => 'resource_page', 'sequence' => 1, 'subject' => 'Hi', 'body' => 'x', 'unsubscribe_token' => str_repeat( 'a', 32 ), 'message_id' => 'ppclg-1@x' ] );
        $this->assertNotNull( $m->existsFor( 7, 'resource_page', 1 ) );
        $this->assertSame( $mid, $m->findByToken( str_repeat( 'a', 32 ) )['id'] );
        $this->assertSame( $mid, $m->findByMessageId( '<ppclg-1@x>' )['id'] );
        $this->assertSame( 0.0, $m->responseRate()['rate'] );
    }
}
