<?php
/**
 * Review fixes for the verified opportunity import: paid-only items never become owner tasks (and an earlier
 * task for one is closed), a site that already has a sent listing gets no second listing task, and prospects
 * with a verified owner task are never auto-drafted for outreach, whatever their discovered_via.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Verified;

use PPC\LinkGrowth\Automation\JobRunner;
use PPC\LinkGrowth\Database\Schema;
use PPC\LinkGrowth\Discovery\VerifiedOpportunities;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class VerifiedSafetyFixesTest extends TestCase {

    private const DATA = PPCLG_DIR . 'data/verified-opportunities.json';

    private const EIN_PRESSWIRE = 'ec40093b4f8ac1ef';

    /** @var string[] */
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

    /** @return array<string,mixed> */
    private static function data(): array {
        $d = json_decode( (string) file_get_contents( self::DATA ), true );
        self::assertIsArray( $d );
        return $d;
    }

    /** @param array<string,mixed> $data */
    private function writeData( array $data ): string {
        $file = tempnam( sys_get_temp_dir(), 'ppclg-verified-' );
        $this->assertIsString( $file );
        file_put_contents( $file, (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        $this->tmp[] = $file;
        return $file;
    }

    private function service( string $file = '' ): VerifiedOpportunities {
        return VerifiedOpportunities::fromContainer( Container::plugin()->container(), $file );
    }

    private function importAll( VerifiedOpportunities $vo ): void {
        for ( $i = 0; $i < 50; $i++ ) {
            if ( $vo->import( TimeBudget::seconds( 120 ), 100 )['done'] ) {
                return;
            }
        }
        $this->fail( 'The import never finished.' );
    }

    /** @return array<string,mixed> */
    private static function state(): array {
        $s = get_option( VerifiedOpportunities::OPTION_STATE, [] );
        return is_array( $s ) ? $s : [];
    }

    /** @return array<int,array<string,mixed>> owner tasks of one prospect */
    private function tasksFor( int $pid ): array {
        $rows = Container::get( Submissions::class )->findBy( [ 'prospect_id' => $pid ], 'id', 'ASC', 50 );
        return array_values( array_filter( $rows, static fn( array $s ): bool => VerifiedOpportunities::isOwnerTask( $s ) ) );
    }

    /** @return array<string,mixed>|null the owner task of one item */
    private function taskForItem( string $itemId ): ?array {
        global $wpdb;
        $t    = Schema::table( 'submissions' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE payload LIKE %s", '%' . VerifiedOpportunities::PAYLOAD_MARKER . '%' ), ARRAY_A );
        $repo = Container::get( Submissions::class );
        foreach ( $rows ?: [] as $row ) {
            $row = $repo->hydrate( $row );
            if ( ( $row['payload']['package']['item_id'] ?? '' ) === $itemId ) {
                return $row;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private static function item( array $data, string $id ): array {
        foreach ( $data['items'] as $item ) {
            if ( $item['id'] === $id ) {
                return $item;
            }
        }
        self::fail( 'Item ' . $id . ' is not in the data file.' );
    }

    // ------------------------------------------------------------------ paid-only items

    public function testPaidOnlyClassification(): void {
        foreach ( [ 'false', 'False', ' no ', 'no - paid packages only', 'no (paid membership)', false ] as $free ) {
            $this->assertTrue( VerifiedOpportunities::isPaidOnly( [ 'free' => $free ] ), var_export( $free, true ) );
        }
        foreach ( [ 'true', 'yes', 'yes - free listing', 'freemium', 'partial', 'none stated', 'not stated', '', true ] as $free ) {
            $this->assertFalse( VerifiedOpportunities::isPaidOnly( [ 'free' => $free ] ), var_export( $free, true ) );
        }
        $this->assertFalse( VerifiedOpportunities::isPaidOnly( [] ) );

        $paid = array_values( array_filter( self::data()['items'], [ VerifiedOpportunities::class, 'isPaidOnly' ] ) );
        $this->assertCount( 7, $paid, 'the bundled data has 7 items without a free tier' );
        $this->assertContains( self::EIN_PRESSWIRE, array_column( $paid, 'id' ) );
    }

    public function testPaidOnlyItemsAreSkippedAndGetNoOwnerTask(): void {
        $data = self::data();
        $this->importAll( $this->service() );

        $state = self::state();
        $this->assertSame( 7, $state['skip_reasons']['paid_only'] ?? 0 );
        $skipped = array_column( $state['skipped_items'], 'reason', 'id' );
        $this->assertSame( 'paid_only', $skipped[ self::EIN_PRESSWIRE ] ?? null );

        $this->assertNull( $this->taskForItem( self::EIN_PRESSWIRE ), 'no owner task opens the newswire pricing page' );
        $this->assertNull( Container::get( Prospects::class )->findByDomain( 'einpresswire.com' ) );
        foreach ( $data['items'] as $item ) {
            if ( VerifiedOpportunities::isPaidOnly( $item ) ) {
                $this->assertNull( $this->taskForItem( (string) $item['id'] ), (string) $item['platform'] );
            }
        }
    }

    public function testAnEarlierOpenTaskForAPaidOnlyItemIsClosedEvenWhenTheItemIsUnchanged(): void {
        $data = self::data();
        $ein  = self::item( $data, self::EIN_PRESSWIRE );
        $free = $ein;
        $free['free'] = 'yes';
        $small = [ 'version' => 'paid-test-1', 'counts' => [], 'kit' => $data['kit'], 'gate' => $data['gate'], 'items' => [ $free ] ];

        // An earlier import (simulated with the item marked free) created an open task for it.
        $this->importAll( $this->service( $this->writeData( $small ) ) );
        $task = $this->taskForItem( self::EIN_PRESSWIRE );
        $this->assertNotNull( $task );
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $task['status'] );

        // The stored fingerprint already matches the paid-only item (as after an import by an older release):
        // the unchanged-item shortcut must not keep the task open.
        $kitHash = substr( sha1( (string) wp_json_encode( [ $data['kit'], $data['gate'] ] ) ), 0, 12 );
        $map     = get_option( VerifiedOpportunities::OPTION_ITEMS, [] );
        $this->assertIsArray( $map );
        $this->assertArrayHasKey( self::EIN_PRESSWIRE, $map );
        $parts                         = explode( ':', (string) $map[ self::EIN_PRESSWIRE ] ); // "hash:submission id:prospect id"
        $parts[0]                      = substr( sha1( (string) wp_json_encode( $ein ) . '|' . $kitHash ), 0, 16 );
        $map[ self::EIN_PRESSWIRE ]    = implode( ':', $parts );
        update_option( VerifiedOpportunities::OPTION_ITEMS, $map, false );

        $small['version'] = 'paid-test-2';
        $small['items']   = [ $ein ];
        $vo               = $this->service( $this->writeData( $small ) );
        $this->importAll( $vo );

        $task = $this->taskForItem( self::EIN_PRESSWIRE );
        $this->assertNotNull( $task );
        $this->assertSame( Submissions::STATUS_REJECTED, $task['status'] );
        $this->assertStringContainsString( 'Paid-only', (string) $task['last_error'] );
        $this->assertSame( 1, self::state()['skip_reasons']['paid_only'] ?? 0 );
        $this->assertArrayNotHasKey( self::EIN_PRESSWIRE, (array) get_option( VerifiedOpportunities::OPTION_ITEMS, [] ) );
        $this->assertSame( 0, $vo->status()['owner_tasks_open'], 'the closed task no longer counts as an open owner task' );
    }

    // ------------------------------------------------------------------ already listed

    public function testSiteWithAListingAlreadySubmittedGetsNoSecondListingTask(): void {
        $prospects = Container::get( Prospects::class );
        [ $pid ]   = $prospects->upsertDiscovered( 'biotechgate.com', [ 'name' => 'Biotechgate', 'discovered_via' => 'seed' ] );
        $subs      = Container::get( Submissions::class );
        $sent      = $subs->create( $pid, null, 'https://www.biotechgate.com/add-company', [ 'method' => 'form', 'adapter' => 'generic_form', 'status' => Submissions::STATUS_SUBMITTED, 'submitted_at' => gmdate( 'Y-m-d H:i:s' ) ] );

        $this->importAll( $this->service() );

        $this->assertSame( [], $this->tasksFor( $pid ), 'no owner task asks for a second listing on biotechgate.com' );
        $this->assertSame( 1, Container::get( Submissions::class )->count( [ 'prospect_id' => $pid ] ) );
        $this->assertSame( 1, self::state()['skip_reasons']['already_listed'] ?? 0 );
        $this->assertSame( 'already_listed', array_column( self::state()['skipped_items'], 'reason', 'id' )['8611acfcc5fe07b2'] ?? null );
        $this->assertSame( 0, Container::get( Opportunities::class )->count( [ 'prospect_id' => $pid, 'status' => Opportunities::STATUS_OPEN ] ), 'no open opportunity is left for the packaging stage' );
        $this->assertSame( Submissions::STATUS_SUBMITTED, Container::get( Submissions::class )->find( $sent )['status'] );
    }

    public function testSeparateSitesOnOneRegistrableDomainStillGetTheirTasks(): void {
        $prospects = Container::get( Prospects::class );
        [ $pid ]   = $prospects->upsertDiscovered( 'stackexchange.com', [ 'name' => 'Stack Exchange', 'discovered_via' => 'seed' ] );
        // A contribution on Chemistry Stack Exchange was already made; Biology Stack Exchange is a separate site.
        Container::get( Submissions::class )->create( $pid, null, 'https://chemistry.stackexchange.com/users/signup', [ 'method' => 'manual', 'adapter' => 'manual_package', 'status' => Submissions::STATUS_CONFIRMED, 'submitted_at' => gmdate( 'Y-m-d H:i:s' ) ] );

        $this->importAll( $this->service() );

        $biology   = $this->taskForItem( '9e4c617ef279870e' );
        $chemistry = $this->taskForItem( 'a4088b137ae5a63b' );
        $this->assertNotNull( $biology, 'Biology Stack Exchange still gets its owner task' );
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $biology['status'] );
        $this->assertNull( $chemistry, 'Chemistry Stack Exchange, where a listing was already sent, gets none' );
        $this->assertSame( 1, self::state()['skip_reasons']['already_listed'] ?? 0 );
    }

    // ------------------------------------------------------------------ auto outreach

    public function testPreExistingSeedProspectWithAVerifiedOutreachTaskIsNotAutoDrafted(): void {
        Container::get( Settings::class )->update( [ 'outreach' => [ 'sender_name' => 'Jane Doe', 'sender_email' => 'jane@purepeptidescanada.test', 'postal_address' => '222 Jarvis St, Toronto, ON M5B 2B8, Canada' ] ] );
        $db  = $GLOBALS['wpdb'];
        $now = gmdate( 'Y-m-d H:i:s' );
        $db->insert( Schema::table( 'prospects' ), [ 'domain' => 'cdnsciencepub.com', 'name' => 'CSP', 'status' => 'qualified', 'score' => 90, 'opportunity_type' => 'educational_contribution', 'discovered_via' => 'seed', 'first_seen' => $now, 'updated_at' => $now ] );
        $pid = (int) $db->insert_id;
        $db->insert( Schema::table( 'contacts' ), [ 'prospect_id' => $pid, 'email' => 'editor@cdnsciencepub.com', 'name' => 'Ed', 'role' => 'editor', 'confidence' => 80, 'consent_basis' => 'conspicuous_publication', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] );
        // A second seed prospect without an owner task: auto outreach still drafts for it (control).
        $db->insert( Schema::table( 'prospects' ), [ 'domain' => 'lab-control-blog.example.org', 'name' => 'Control', 'status' => 'qualified', 'score' => 90, 'opportunity_type' => 'educational_contribution', 'discovered_via' => 'seed', 'first_seen' => $now, 'updated_at' => $now ] );
        $control = (int) $db->insert_id;
        $db->insert( Schema::table( 'contacts' ), [ 'prospect_id' => $control, 'email' => 'editor@lab-control-blog.example.org', 'name' => 'Cy', 'role' => 'editor', 'confidence' => 80, 'consent_basis' => 'conspicuous_publication', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] );

        $this->importAll( $this->service() );
        $tasks = $this->tasksFor( $pid );
        $this->assertCount( 1, $tasks );
        $this->assertTrue( (bool) $tasks[0]['payload']['package']['outreach'] );
        $this->assertSame( 'seed', Container::get( Prospects::class )->find( $pid )['discovered_via'], 'the prospect keeps its original source' );

        Container::reset();
        Container::get( JobRunner::class )->runType( 'auto_outreach', [ 'manual' => true ], TimeBudget::seconds( 60.0 ) );
        $messages = Container::get( OutreachMessages::class );
        $this->assertSame( 0, $messages->count( [ 'prospect_id' => $pid ] ), 'a verified outreach owner task is the owner\'s decision: never auto-drafted' );
        $this->assertGreaterThan( 0, $messages->count( [ 'prospect_id' => $control ] ), 'other qualified prospects are still drafted' );
    }
}
