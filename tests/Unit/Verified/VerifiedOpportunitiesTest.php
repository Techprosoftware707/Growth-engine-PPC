<?php
/**
 * Verified opportunity database import (Discovery\VerifiedOpportunities + Automation\Jobs\ImportVerifiedJob)
 * against the real bundled data/verified-opportunities.json (595 items).
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Verified;

use PPC\LinkGrowth\Admin\Rest\SubmissionsController;
use PPC\LinkGrowth\Automation\Jobs\ImportVerifiedJob;
use PPC\LinkGrowth\Content\ClaimsFilter;
use PPC\LinkGrowth\Database\Schema;
use PPC\LinkGrowth\Discovery\VerifiedOpportunities;
use PPC\LinkGrowth\Repositories\Contacts;
use PPC\LinkGrowth\Repositories\Jobs;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Repositories\Suppression;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Support\Url;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_REST_Request;

final class VerifiedOpportunitiesTest extends TestCase {

    private const DATA = PPCLG_DIR . 'data/verified-opportunities.json';

    /** @var string[] temporary data files written by a test */
    private array $tmp = [];

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

    /** @return array<string,mixed> */
    private static function data(): array {
        $d = json_decode( (string) file_get_contents( self::DATA ), true );
        self::assertIsArray( $d );
        return $d;
    }

    private function service( string $file = '' ): VerifiedOpportunities {
        return VerifiedOpportunities::fromContainer( Container::plugin()->container(), $file );
    }

    /**
     * Runs import() until the pass is done.
     *
     * @return array{calls:int,imported:int,skipped:int,existing:int,updated:int,results:array<int,array<string,mixed>>}
     */
    private function importAll( VerifiedOpportunities $vo, int $batch = 100 ): array {
        $sum = [ 'calls' => 0, 'imported' => 0, 'skipped' => 0, 'existing' => 0, 'updated' => 0, 'results' => [] ];
        for ( $i = 0; $i < 50; $i++ ) {
            $r = $vo->import( TimeBudget::seconds( 120 ), $batch );
            $sum['calls']++;
            $sum['results'][] = $r;
            foreach ( [ 'imported', 'skipped', 'existing', 'updated' ] as $k ) {
                $sum[ $k ] += (int) $r[ $k ];
            }
            if ( $r['done'] ) {
                return $sum;
            }
        }
        $this->fail( 'The import never finished.' );
    }

    /** @return array<int,array<string,mixed>> owner-task rows (hydrated) */
    private function ownerTasks(): array {
        global $wpdb;
        $t    = Schema::table( 'submissions' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE payload LIKE %s ORDER BY id ASC", '%' . VerifiedOpportunities::PAYLOAD_MARKER . '%' ), ARRAY_A );
        $repo = Container::get( Submissions::class );
        return array_map( [ $repo, 'hydrate' ], $rows ?: [] );
    }

    /** @param array<string,mixed> $data */
    private function writeData( array $data ): string {
        $file = tempnam( sys_get_temp_dir(), 'ppclg-verified-' );
        $this->assertIsString( $file );
        file_put_contents( $file, (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        $this->tmp[] = $file;
        return $file;
    }

    /** @return array<string,mixed> state option */
    private static function state(): array {
        $s = get_option( VerifiedOpportunities::OPTION_STATE, [] );
        return is_array( $s ) ? $s : [];
    }

    // ------------------------------------------------------------------ tests

    public function testImportCreatesOneOwnerTaskPerItemWithoutHttpAndCountsMatchTheFile(): void {
        $data = self::data();
        $this->assertCount( 595, $data['items'] );
        $this->assertSame( 595, (int) $data['counts']['total'] );

        $vo  = $this->service();
        $sum = $this->importAll( $vo );

        $this->assertSame( [], HttpFixtures::$requests, 'the import makes no HTTP requests' );
        $this->assertSame( 6, $sum['calls'], '595 items in batches of 100' );
        foreach ( $sum['results'] as $r ) {
            $this->assertLessThanOrEqual( 100, $r['processed'] );
        }
        $this->assertSame( 595, $sum['imported'] + $sum['skipped'] + $sum['existing'] );
        $this->assertSame( 0, $sum['updated'] );

        $state   = self::state();
        $skipped = [];
        foreach ( $state['skipped_items'] as $s ) {
            $skipped[ $s['id'] ] = $s['reason'];
        }
        $this->assertCount( $sum['skipped'], $skipped );
        $this->assertGreaterThan( 0, $sum['skipped'], 'platform domains on the exclusion list are skipped' );
        $this->assertSame( $sum['skipped'], array_sum( $state['skip_reasons'] ) );
        $this->assertSame( [ 'excluded_platform' ], array_values( array_unique( $skipped ) ) );

        $tasks = $this->ownerTasks();
        $this->assertCount( $sum['imported'], $tasks, 'exactly one owner task per imported item' );

        // Per-class counts match the file's counts minus the skipped items.
        $kept = array_values( array_filter( $data['items'], static fn( array $i ): bool => ! isset( $skipped[ $i['id'] ] ) ) );
        $this->assertCount( 595 - count( $skipped ), $kept );
        $byId = [];
        foreach ( $tasks as $t ) {
            $byId[ $t['payload']['package']['item_id'] ] = $t;
        }
        $this->assertCount( count( $tasks ), $byId, 'no item has two tasks' );
        $this->assertCount( count( $kept ) - $sum['existing'], $byId );
        $count = static fn( array $list, string $key ): int => count( array_filter( $list, static fn( array $x ): bool => ! empty( $x[ $key ] ) ) );
        $skippedItems = array_values( array_filter( $data['items'], static fn( array $i ): bool => isset( $skipped[ $i['id'] ] ) ) );
        $this->assertSame( (int) $data['counts']['outreach'] - $count( $skippedItems, 'outreach' ), $count( array_map( static fn( array $t ): array => $t['payload']['package'], $tasks ), 'outreach' ) );
        $this->assertSame( (int) $data['counts']['conditional'] - $count( $skippedItems, 'conditional' ), $count( array_map( static fn( array $t ): array => $t['payload']['package'], $tasks ), 'conditional' ) );
        $masterKept = count( array_filter( $kept, static fn( array $i ): bool => 'master' === $i['db'] ) );
        $this->assertSame( $masterKept, count( array_filter( $tasks, static fn( array $t ): bool => 'master' === $t['payload']['package']['db'] ) ) );

        foreach ( $tasks as $t ) {
            $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $t['status'] );
            $this->assertSame( 'manual', $t['method'] );
            $this->assertSame( 'manual_package', $t['adapter'] );
            $this->assertMatchesRegularExpression( '#^https?://#', (string) $t['target_url'] );
            $this->assertSame( (string) $t['target_url'], $t['payload']['package']['target_url'] );
            $item = null;
            foreach ( $kept as $i ) {
                if ( $i['id'] === $t['payload']['package']['item_id'] ) {
                    $item = $i;
                    break;
                }
            }
            $this->assertNotNull( $item );
            if ( preg_match( '#^https?://#i', (string) $item['submission_url'] ) ) {
                $this->assertSame( Url::normalize( (string) $item['submission_url'] ), $t['target_url'], 'target_url = submission_url' );
            }
            $this->assertNotEmpty( $t['opportunity_id'], 'the task is linked to an opportunity' );
        }

        // Outreach-class items never create outreach messages or contacts.
        $this->assertSame( 0, Container::get( Contacts::class )->count() );
        $this->assertSame( 0, Container::get( OutreachMessages::class )->count() );
        // Every linked opportunity is packaged, so the packaging stage does not build a second package.
        $this->assertSame( 0, Container::get( Opportunities::class )->count( [ 'status' => Opportunities::STATUS_OPEN ] ) );

        $status = $vo->status();
        $this->assertSame( (string) $data['version'], $status['data_version'] );
        $this->assertSame( (string) $data['version'], $status['imported_version'] );
        $this->assertTrue( $status['up_to_date'] );
        $this->assertFalse( $status['in_progress'] );
        $this->assertSame( 595, $status['total'] );
        $this->assertSame( $sum['skipped'], $status['skipped'] );
        $this->assertSame( count( $tasks ), $status['owner_tasks_open'] );
        $this->assertSame( 595 - $sum['skipped'], $status['imported'] );
        $this->assertFalse( $vo->needsImport() );
        $this->assertStringContainsString( 'owner tasks are open', $status['summary'] );
    }

    public function testProspectsGetSubmissionUrlNotesAndRecommendedAction(): void {
        $this->importAll( $this->service() );
        $data      = self::data();
        $prospects = Container::get( Prospects::class );

        $checked = 0;
        foreach ( $data['items'] as $item ) {
            $p = $prospects->findByDomain( Url::registrableDomain( (string) $item['website'] ) );
            if ( null === $p ) {
                continue; // skipped item
            }
            $checked++;
            $this->assertStringContainsString( '[Verified database item ' . $item['id'] . ']', (string) $p['notes'] );
            $this->assertContains( $p['recommended_action'], [ 'manual_submission', 'outreach_pitch' ] );
            $this->assertTrue( '' === $p['submission_url'] || 1 === preg_match( '#^https?://#', (string) $p['submission_url'] ), 'only http(s) submission URLs' );
        }
        $this->assertGreaterThan( 500, $checked );

        // An outreach item whose submission URL is an email address: no mailto on the prospect, outreach_pitch action,
        // and the task targets a web page while the package keeps the address for the owner.
        $mailto = null;
        foreach ( $data['items'] as $item ) {
            if ( str_starts_with( (string) $item['submission_url'], 'mailto:' ) ) {
                $mailto = $item;
                break;
            }
        }
        $this->assertNotNull( $mailto );
        $p = $prospects->findByDomain( Url::registrableDomain( (string) $mailto['website'] ) );
        $this->assertNotNull( $p );
        $this->assertStringNotContainsString( 'mailto:', (string) $p['submission_url'] );
        $task = null;
        foreach ( $this->ownerTasks() as $t ) {
            if ( $t['payload']['package']['item_id'] === $mailto['id'] ) {
                $task = $t;
            }
        }
        $this->assertNotNull( $task );
        $this->assertMatchesRegularExpression( '#^https?://#', (string) $task['target_url'] );
        $this->assertSame( strtolower( substr( (string) $mailto['submission_url'], 7 ) ), $task['payload']['package']['contact_email'] );
        if ( ! empty( $mailto['outreach'] ) ) {
            $this->assertSame( 'outreach_pitch', $p['recommended_action'] );
        }
    }

    public function testPackageFieldsArePresentAndTheDescriptionIsResearchUseOnly(): void {
        $this->importAll( $this->service() );
        $data   = self::data();
        $claims = Container::get( ClaimsFilter::class );
        $tasks  = $this->ownerTasks();
        $this->assertNotEmpty( $tasks );

        $required = [ 'source', 'item_id', 'tier', 'authority', 'outreach', 'conditional', 'business_name', 'website', 'address', 'email', 'description', 'category_rule', 'steps', 'gate_note', 'verification_note', 'link_attribute', 'registration_url', 'target_url' ];
        $descriptions = [];
        foreach ( $tasks as $t ) {
            $pkg = $t['payload']['package'];
            foreach ( $required as $key ) {
                $this->assertArrayHasKey( $key, $pkg, $key );
            }
            $this->assertSame( 'verified_db', $pkg['source'] );
            $this->assertSame( $data['kit']['business_name'], $pkg['business_name'] );
            $this->assertSame( $data['kit']['website'], $pkg['website'] );
            $this->assertSame( $data['kit']['address'], $pkg['address'] );
            $this->assertSame( $data['kit']['email'], $pkg['email'] );
            $this->assertSame( $data['kit']['category_rule'], $pkg['category_rule'] );
            $this->assertSame( $data['gate'], $pkg['gate_note'] );
            $this->assertIsInt( $pkg['tier'] );
            $this->assertTrue( null === $pkg['authority'] || is_int( $pkg['authority'] ) );
            $this->assertIsBool( $pkg['outreach'] );
            $this->assertIsBool( $pkg['conditional'] );
            $this->assertTrue( '' === $pkg['registration_url'] || 1 === preg_match( '#^https?://#', $pkg['registration_url'] ) );
            $this->assertNotSame( '', trim( (string) $pkg['steps'] ) );

            $desc = (string) $pkg['description'];
            $this->assertNotSame( '', $desc );
            $this->assertSame( [], $claims->check( $desc ), 'ClaimsFilter finds nothing in: ' . $desc );
            $this->assertMatchesRegularExpression( '/research use only|laboratory research (use|purposes) only|not for human/i', $desc );
            $this->assertDoesNotMatchRegularExpression( '/\b(health|medical|hospital|clinic\w*|pharmac\w*|drugs?|supplements?|wellness|patients?|therap\w*|cosmetic\w*)\b/i', $desc );
            $this->assertLessThanOrEqual( 500, mb_strlen( $desc ) );
            $this->assertTrue( VerifiedOpportunities::listingSafe( $desc ) );
            $this->assertTrue( $t['payload']['compliance']['clean'] );
            $this->assertSame( $desc, $t['payload']['content']['description'] );
            $descriptions[ $desc ] = true;
        }
        $this->assertGreaterThan( 10, count( $descriptions ), 'descriptions vary by destination' );
    }

    public function testSecondRunIsIdempotentEvenWhenTheStoredProgressIsLost(): void {
        $vo    = $this->service();
        $first = $this->importAll( $vo );
        $rows  = Container::get( Submissions::class )->count();
        $opps  = Container::get( Opportunities::class )->count();
        $pros  = Container::get( Prospects::class )->count();

        // Same version again: nothing to do.
        $again = $vo->import( TimeBudget::seconds( 60 ) );
        $this->assertTrue( $again['done'] );
        $this->assertSame( 0, $again['imported'] + $again['processed'] );
        $this->assertStringContainsString( 'already imported', $again['summary'] );

        // A fresh pass (version marker and cursor lost): every item is recognised by its fingerprint.
        delete_option( VerifiedOpportunities::OPTION_VERSION );
        delete_option( VerifiedOpportunities::OPTION_STATE );
        $this->assertTrue( $vo->needsImport() );
        $second = $this->importAll( $vo );
        $this->assertSame( 0, $second['imported'] );
        $this->assertSame( $first['imported'] + $first['existing'], $second['existing'] );

        // Fingerprints lost too: the (prospect, target) rows are found, never duplicated.
        delete_option( VerifiedOpportunities::OPTION_VERSION );
        delete_option( VerifiedOpportunities::OPTION_STATE );
        delete_option( VerifiedOpportunities::OPTION_ITEMS );
        $third = $this->importAll( $this->service() );
        $this->assertSame( 0, $third['imported'] );
        $this->assertSame( $first['imported'] + $first['existing'], $third['existing'] );
        $this->assertSame( $first['skipped'], $third['skipped'] );

        $this->assertSame( $rows, Container::get( Submissions::class )->count() );
        $this->assertSame( $opps, Container::get( Opportunities::class )->count() );
        $this->assertSame( $pros, Container::get( Prospects::class )->count() );
        $this->assertCount( $first['imported'], $this->ownerTasks() );
        // Notes blocks are replaced, not repeated.
        foreach ( Container::get( Prospects::class )->findBy( [ 'discovered_via' => 'verified_db' ], 'id', 'ASC', 1000 ) as $p ) {
            preg_match_all( '/\[Verified database item ([0-9a-f]+)\]/', (string) $p['notes'], $m );
            $this->assertSame( array_values( array_unique( $m[1] ) ), $m[1], 'each item block appears once' );
        }
    }

    public function testVersionBumpReimportsOnlyNewAndChangedItems(): void {
        $data  = self::data();
        $vo    = $this->service( $this->writeData( $data ) );
        $first = $this->importAll( $vo );
        $rows  = Container::get( Submissions::class )->count();

        // Pick two imported items (not skipped): one with new requirements, one whose submission page moved.
        $skipped = array_column( self::state()['skipped_items'], 'reason', 'id' );
        $idx     = [];
        foreach ( $data['items'] as $i => $item ) {
            if ( ! isset( $skipped[ $item['id'] ] ) && preg_match( '#^https://#', (string) $item['submission_url'] ) ) {
                $idx[] = $i;
            }
            if ( count( $idx ) >= 2 ) {
                break;
            }
        }
        [ $changed, $moved ] = $idx;
        $tasksBefore = [];
        foreach ( $this->ownerTasks() as $t ) {
            $tasksBefore[ $t['payload']['package']['item_id'] ] = $t;
        }
        $movedTaskId = (int) $tasksBefore[ $data['items'][ $moved ]['id'] ]['id'];

        $next                                       = $data;
        $next['version']                           = $data['version'] . '-next';
        $next['items'][ $changed ]['requirements'] = 'Updated requirement: confirm the free tier before submitting.';
        $next['items'][ $changed ]['steps']        = '';
        $next['items'][ $moved ]['submission_url'] = rtrim( (string) $data['items'][ $moved ]['website'], '/' ) . '/new-listing-form';
        $next['items'][]                           = [
            'id' => 'feedfacecafe0001', 'db' => 'expansion', 'platform' => 'Northern Lab Directory', 'website' => 'https://northern-lab-directory.example.org/',
            'host' => 'northern-lab-directory.example.org', 'submission_url' => 'https://northern-lab-directory.example.org/add', 'registration_url' => '',
            'category' => 'directory', 'site_type' => 'directory', 'opportunity_type' => 'directory_listing', 'tier' => 2, 'authority' => 40,
            'free' => 'yes', 'payment' => 'none', 'requirements' => 'Free listing form.', 'link_clickable' => 'yes', 'link_attribute' => 'nofollow',
            'approval_required' => 'staff-review', 'effort' => '10', 'seo_value' => 'low', 'relevance' => 'Lab directory.', 'risk' => 'none',
            'verification_note' => 'HTTP 200.', 'conditional' => false, 'outreach' => false, 'source' => 'test', 'steps' => '',
        ];
        $file = $this->writeData( $next );
        $vo2  = $this->service( $file );
        $this->assertTrue( $vo2->needsImport() );
        $this->assertFalse( $vo2->status()['up_to_date'] );

        $sum = $this->importAll( $vo2 );
        $this->assertSame( 1, $sum['imported'], 'only the new item gets a new task' );
        $this->assertSame( 2, $sum['updated'], 'the changed and the moved item refresh their own tasks' );
        $this->assertSame( $first['skipped'], $sum['skipped'] );
        $this->assertSame( 596 - $first['skipped'] - 3, $sum['existing'] );
        $this->assertSame( $rows + 1, Container::get( Submissions::class )->count() );

        $after = [];
        foreach ( $this->ownerTasks() as $t ) {
            $after[ $t['payload']['package']['item_id'] ] = $t;
        }
        $this->assertSame( 'Updated requirement: confirm the free tier before submitting.', $after[ $data['items'][ $changed ]['id'] ]['payload']['package']['requirements'] );
        $this->assertSame( $next['version'], $after[ $data['items'][ $changed ]['id'] ]['payload']['package']['data_version'] );
        $this->assertSame( $movedTaskId, (int) $after[ $data['items'][ $moved ]['id'] ]['id'], 'the moved item keeps its task' );
        $this->assertSame( Url::normalize( $next['items'][ $moved ]['submission_url'] ), $after[ $data['items'][ $moved ]['id'] ]['target_url'] );
        $this->assertArrayHasKey( 'feedfacecafe0001', $after );
        $this->assertSame( $next['version'], get_option( VerifiedOpportunities::OPTION_VERSION ) );
        $this->assertTrue( $vo2->status()['up_to_date'] );
    }

    public function testBatchCursorResumesAndAShortBudgetNeverStartsWork(): void {
        $vo = $this->service();

        $none = $vo->import( TimeBudget::seconds( 0 ) );
        $this->assertSame( 0, $none['processed'] );
        $this->assertFalse( $none['done'] );
        $this->assertStringContainsString( 'continues on the next run', $none['summary'] );

        $a = $vo->import( TimeBudget::seconds( 60 ), 40 );
        $this->assertSame( 40, $a['processed'] );
        $this->assertSame( 40, $a['cursor'] );
        $this->assertFalse( $a['done'] );
        $status = $vo->status();
        $this->assertTrue( $status['in_progress'] );
        $this->assertSame( 40, $status['cursor'] );
        $this->assertTrue( $vo->needsImport() );

        // A new service instance (next request) resumes at the stored cursor.
        $b = $this->service()->import( TimeBudget::seconds( 60 ), 40 );
        $this->assertSame( 80, $b['cursor'] );
        $this->assertSame( 40, $b['processed'] );
        $this->assertSame( $a['imported'] + $b['imported'], count( $this->ownerTasks() ) );

        // Batches are capped at 100.
        $c = $vo->import( TimeBudget::seconds( 60 ), 1000 );
        $this->assertSame( 100, $c['processed'] );
        $this->assertSame( 180, $c['cursor'] );
    }

    public function testSuppressedDomainIsSkippedAndCounted(): void {
        $data = self::data();
        $item = null;
        foreach ( $data['items'] as $i ) {
            if ( 'master' === $i['db'] && str_contains( (string) $i['website'], 'biotechgate.com' ) ) {
                $item = $i;
            }
        }
        $this->assertNotNull( $item );
        Container::get( Suppression::class )->add( 'domain', 'biotechgate.com', 'owner request' );

        $sum = $this->importAll( $this->service() );
        $state = self::state();
        $this->assertSame( 1, $state['skip_reasons']['suppressed'] ?? 0 );
        $this->assertContains( [ 'id' => $item['id'], 'reason' => 'suppressed' ], array_map( static fn( array $s ): array => [ 'id' => $s['id'], 'reason' => $s['reason'] ], $state['skipped_items'] ) );
        $this->assertNull( Container::get( Prospects::class )->findByDomain( 'biotechgate.com' ) );
        foreach ( $this->ownerTasks() as $t ) {
            $this->assertNotSame( $item['id'], $t['payload']['package']['item_id'] );
        }
        $this->assertSame( 595, $sum['imported'] + $sum['skipped'] + $sum['existing'] );
    }

    public function testRejectedProspectGetsNoNewTask(): void {
        $prospects = Container::get( Prospects::class );
        [ $pid ]   = $prospects->upsertDiscovered( 'biotechgate.com', [ 'name' => 'Biotechgate' ] );
        $prospects->setStatus( $pid, Prospects::STATUS_REJECTED, 'Owner rejected' );

        $this->importAll( $this->service() );
        $this->assertSame( 1, self::state()['skip_reasons']['prospect_rejected'] ?? 0 );
        $this->assertSame( 0, Container::get( Submissions::class )->count( [ 'prospect_id' => $pid ] ) );
    }

    public function testJobImportsInBatchesAndContinuesUntilDone(): void {
        $c   = Container::plugin()->container();
        $job = new ImportVerifiedJob();
        $this->assertSame( 'import_verified', ImportVerifiedJob::type() );

        $r = $job->handle( [ 'manual' => true ], TimeBudget::seconds( 60 ), $c );
        $this->assertSame( 'import_verified', $r['job'] );
        $this->assertIsString( $r['summary'] );
        $this->assertNotSame( '', $r['summary'] );
        foreach ( [ 'imported', 'items_skipped', 'existing', 'updated', 'processed', 'owner_tasks_open' ] as $k ) {
            $this->assertIsInt( $r[ $k ], $k );
        }
        // "skipped" is AbstractJob's "did not run" flag (read by GrowthRun and the dashboard): a run that did work
        // must not carry it, even when items were skipped.
        $this->assertArrayNotHasKey( 'skipped', $r );
        $this->assertSame( 100, $r['processed'] );
        $this->assertTrue( $r['more'] );
        $this->assertTrue( $r['continued'] );
        $jobs = Container::get( Jobs::class );
        $this->assertSame( 1, $jobs->count( [ 'type' => 'import_verified', 'status' => Jobs::STATUS_QUEUED ] ) );

        // The continuation runs (here directly) until the pass is complete.
        $last = $r;
        for ( $i = 0; $i < 10 && $last['more']; $i++ ) {
            $jobs->cancelQueued( 'import_verified' );
            $last = $job->handle( [ 'manual' => true, 'continued' => $i + 1 ], TimeBudget::seconds( 60 ), $c );
        }
        $this->assertFalse( $last['more'] );
        $this->assertArrayNotHasKey( 'continued', $last );
        $this->assertStringContainsString( 'all 595 items processed', $last['summary'] );
        $this->assertSame( (int) $last['owner_tasks_open'], count( $this->ownerTasks() ) );

        // Too little time: deferred (re-queued), nothing imported.
        $jobs->cancelQueued( 'import_verified' );
        $d = $job->handle( [], TimeBudget::seconds( 1 ), $c );
        $this->assertTrue( $d['skipped'] );
        $this->assertSame( 'time_budget', $d['reason'] );
        $this->assertTrue( $d['more'] );
    }

    public function testControllerListsOwnerTasksWithFiltersAndRunsTheImport(): void {
        $c          = Container::plugin()->container();
        $controller = new SubmissionsController( $c );

        $status = $controller->verifiedStatus( new WP_REST_Request( 'GET', '/ppclg/v1/submissions/verified' ) );
        $this->assertTrue( $status['available'] );
        $this->assertSame( '', $status['imported_version'] );
        $this->assertFalse( $status['up_to_date'] );

        $res = $controller->verifiedImport( new WP_REST_Request( 'POST', '/ppclg/v1/submissions/verified/import' ) );
        $this->assertTrue( $res['ok'] );
        $this->assertSame( 100, $res['result']['processed'] );
        // ImportVerifiedJob is registered (JobRunner::BUILTIN): the rest is queued for the background runner.
        $this->assertStringContainsString( 'queued', $res['message'], 'with the job registered the remaining items are queued' );
        $this->assertSame( 1, Container::get( Jobs::class )->count( [ 'type' => 'import_verified', 'status' => Jobs::STATUS_QUEUED ] ) );
        Container::get( Jobs::class )->cancelQueued( 'import_verified' );

        // Without the job type registered the owner is told to continue by hand.
        add_filter( 'ppclg_job_types', static function ( array $types ): array {
            unset( $types['import_verified'] );
            return $types;
        } );
        $res = $controller->verifiedImport( new WP_REST_Request( 'POST', '/ppclg/v1/submissions/verified/import' ) );
        $this->assertTrue( $res['ok'] );
        $this->assertStringContainsString( 'Import now', $res['message'], 'without the job registered the owner is told to continue by hand' );
        $this->assertSame( 0, Container::get( Jobs::class )->count( [ 'type' => 'import_verified', 'status' => Jobs::STATUS_QUEUED ] ) );
        $GLOBALS['ppclg_test_filters']['ppclg_job_types'] = [];
        $this->importAll( $this->service() );

        $list = function ( array $params ) use ( $controller ): array {
            $req = new WP_REST_Request( 'GET', '/ppclg/v1/submissions' );
            foreach ( $params + [ 'per_page' => 100 ] as $k => $v ) {
                $req->set_param( $k, $v );
            }
            return $controller->index( $req );
        };

        $all = $list( [ 'source' => 'verified_db' ] );
        $this->assertSame( count( $this->ownerTasks() ), $all['total'] );
        $this->assertSame( 'verified_db', $all['items'][0]['payload']['package']['source'] );
        $this->assertArrayHasKey( 'tier', $all['items'][0]['payload']['package'] );
        $this->assertArrayNotHasKey( 'steps', $all['items'][0]['payload']['package'], 'the list carries a compact summary only' );

        $tier1 = $list( [ 'source' => 'verified_db', 'tier' => 1 ] );
        $this->assertGreaterThan( 0, $tier1['total'] );
        $this->assertLessThan( $all['total'], $tier1['total'] );
        foreach ( $tier1['items'] as $item ) {
            $this->assertSame( 1, $item['payload']['package']['tier'] );
        }
        $outreach = $list( [ 'kind' => 'outreach' ] );
        $this->assertGreaterThan( 0, $outreach['total'] );
        foreach ( $outreach['items'] as $item ) {
            $this->assertTrue( $item['payload']['package']['outreach'] );
        }
        $listing = $list( [ 'kind' => 'listing' ] );
        $this->assertSame( $all['total'], $listing['total'] + $outreach['total'] );
        $conditional = $list( [ 'conditional' => 'yes' ] );
        foreach ( $conditional['items'] as $item ) {
            $this->assertTrue( $item['payload']['package']['conditional'] );
        }
        $this->assertSame( $all['total'], $conditional['total'] + $list( [ 'conditional' => 'no' ] )['total'] );

        // Unfiltered lists are unchanged for other submissions (no package key on non-owner rows).
        $prospects = Container::get( Prospects::class );
        [ $pid ]   = $prospects->upsertDiscovered( 'labdirectory.test', [ 'name' => 'Lab Directory' ] );
        Container::get( Submissions::class )->create( $pid, null, 'https://labdirectory.test/add', [ 'status' => 'pending_review', 'payload' => [ 'content' => [ 'description' => 'x' ] ] ] );
        $plain = $list( [ 'prospect_id' => $pid ] );
        $this->assertSame( 1, $plain['total'] );
        $this->assertArrayNotHasKey( 'package', $plain['items'][0]['payload'] );
    }

    public function testMarkSubmittedUsesTheExistingActionAndSchedulesVerification(): void {
        $this->importAll( $this->service() );
        $task       = $this->ownerTasks()[0];
        $controller = new SubmissionsController( Container::plugin()->container() );
        $req        = new WP_REST_Request( 'POST', '/ppclg/v1/submissions/' . $task['id'] . '/mark-submitted' );
        $req->set_param( 'id', $task['id'] );
        $req->set_param( 'action', 'mark-submitted' );
        $req->set_param( 'listing_url', 'https://example.org/listing/pure-peptides-canada' );
        $res = $controller->action( $req );
        $this->assertTrue( $res['ok'] );

        $row = Container::get( Submissions::class )->find( (int) $task['id'] );
        $this->assertSame( Submissions::STATUS_SUBMITTED, $row['status'] );
        $this->assertSame( 'https://example.org/listing/pure-peptides-canada', $row['listing_url'] );
        $this->assertNotEmpty( $row['next_verify_at'], 'the verify_listings stage picks it up' );
        $this->assertSame( 'verified_db', $row['payload']['package']['source'], 'the package survives' );
        $this->assertSame( [ (int) $task['id'] ], array_map( static fn( array $s ): int => (int) $s['id'], Container::get( Submissions::class )->dueForVerification( 10 ) ) );

        // The open-task count drops; a re-import does not reopen or duplicate it.
        delete_option( VerifiedOpportunities::OPTION_VERSION );
        delete_option( VerifiedOpportunities::OPTION_STATE );
        delete_option( VerifiedOpportunities::OPTION_ITEMS );
        $this->importAll( $this->service() );
        $row = Container::get( Submissions::class )->find( (int) $task['id'] );
        $this->assertSame( Submissions::STATUS_SUBMITTED, $row['status'] );
        $this->assertSame( count( $this->ownerTasks() ) - 1, $this->service()->status()['owner_tasks_open'] );
    }

    public function testStepLinesSplitBulletRunsAndNumberedConditions(): void {
        $this->assertSame(
            [ 'Tier 1 | maps/data aggregator | wave 1', 'Submit at: https://business.apple.com/', 'Sign in with an Apple ID on desktop.', 'Complete verification.' ],
            VerifiedOpportunities::stepLines( '- Tier 1 | maps/data aggregator | wave 1 - Submit at: https://business.apple.com/ - Sign in with an Apple ID on desktop. - Complete verification.' )
        );
        $this->assertSame(
            [ 'CONDITIONAL (audit 2026-11-11):', '(1) A real, named author.', '(2) Vendor-neutral piece.', 'Email an outline to the editor.' ],
            VerifiedOpportunities::stepLines( 'CONDITIONAL (audit 2026-11-11): (1) A real, named author. (2) Vendor-neutral piece. || Email an outline to the editor.' )
        );
        $this->assertSame( [], VerifiedOpportunities::stepLines( "  \n " ) );
    }

    public function testUnreadableDataFileIsReportedNotFatal(): void {
        $file = $this->writeData( [] );
        file_put_contents( $file, '{not json' );
        $vo = $this->service( $file );
        $r  = $vo->import( TimeBudget::seconds( 10 ) );
        $this->assertTrue( $r['done'] );
        $this->assertNotSame( '', $r['error'] );
        $this->assertStringContainsString( 'could not be read', $r['summary'] );
        $this->assertFalse( $vo->status()['available'] );
        $this->assertFalse( $vo->needsImport() );
        $this->assertSame( 0, Container::get( Submissions::class )->count() );
    }
}
