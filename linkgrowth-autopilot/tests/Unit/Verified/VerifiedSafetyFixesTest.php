<?php
/**
 * Review fixes for the verified opportunity import: paid-only items never become owner tasks (and an earlier
 * task for one is closed), a site that already has a sent listing gets no second listing task, and prospects
 * with a verified owner task are never auto-drafted for outreach, whatever their discovered_via.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Verified;

use LinkGrowth\Autopilot\Automation\JobRunner;
use LinkGrowth\Autopilot\Database\Schema;
use LinkGrowth\Autopilot\Discovery\CatalogImporter;
use LinkGrowth\Autopilot\Repositories\Opportunities;
use LinkGrowth\Autopilot\Repositories\OutreachMessages;
use LinkGrowth\Autopilot\Repositories\Prospects;
use LinkGrowth\Autopilot\Repositories\Submissions;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\TimeBudget;
use LinkGrowth\Autopilot\Tests\Support\Container;
use LinkGrowth\Autopilot\Tests\Support\NeutralCatalog;
use LinkGrowth\Autopilot\Tests\Support\TestCase;

final class VerifiedSafetyFixesTest extends TestCase {

    /** The paid-only newswire of the neutral catalog. */
    private const PAID_NEWSWIRE = NeutralCatalog::PAID_ID;

    /** The catalog file of this test (NeutralCatalog::write()). */
    private string $file = '';

    /** @var string[] */
    private array $tmp = [];

    protected function setUp(): void {
        parent::setUp();
        $this->file = NeutralCatalog::write();
    }

    protected function tearDown(): void {
        NeutralCatalog::cleanup();
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
        return NeutralCatalog::data();
    }

    /** @param array<string,mixed> $data */
    private function writeData( array $data ): string {
        $file = tempnam( sys_get_temp_dir(), 'lga-verified-' );
        $this->assertIsString( $file );
        file_put_contents( $file, (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        $this->tmp[] = $file;
        return $file;
    }

    private function service( string $file = '' ): CatalogImporter {
        return CatalogImporter::fromContainer( Container::plugin()->container(), '' !== $file ? $file : $this->file );
    }

    private function importAll( CatalogImporter $vo ): void {
        for ( $i = 0; $i < 50; $i++ ) {
            if ( $vo->import( TimeBudget::seconds( 120 ), 100 )['done'] ) {
                return;
            }
        }
        $this->fail( 'The import never finished.' );
    }

    /** @return array<string,mixed> */
    private static function state(): array {
        $s = get_option( CatalogImporter::OPTION_STATE, [] );
        return is_array( $s ) ? $s : [];
    }

    /** @return array<int,array<string,mixed>> owner tasks of one prospect */
    private function tasksFor( int $pid ): array {
        $rows = Container::get( Submissions::class )->findBy( [ 'prospect_id' => $pid ], 'id', 'ASC', 50 );
        return array_values( array_filter( $rows, static fn( array $s ): bool => CatalogImporter::isOwnerTask( $s ) ) );
    }

    /** @return array<string,mixed>|null the owner task of one item */
    private function taskForItem( string $itemId ): ?array {
        global $wpdb;
        $t    = Schema::table( 'submissions' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE payload LIKE %s", '%' . CatalogImporter::PAYLOAD_MARKER . '%' ), ARRAY_A );
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
            $this->assertTrue( CatalogImporter::isPaidOnly( [ 'free' => $free ] ), var_export( $free, true ) );
        }
        foreach ( [ 'true', 'yes', 'yes - free listing', 'freemium', 'partial', 'none stated', 'not stated', '', true ] as $free ) {
            $this->assertFalse( CatalogImporter::isPaidOnly( [ 'free' => $free ] ), var_export( $free, true ) );
        }
        $this->assertFalse( CatalogImporter::isPaidOnly( [] ) );

        $paid = array_values( array_filter( self::data()['items'], [ CatalogImporter::class, 'isPaidOnly' ] ) );
        $this->assertCount( NeutralCatalog::PAID_COUNT, $paid, 'the catalog has 5 items without a free tier' );
        $this->assertContains( self::PAID_NEWSWIRE, array_column( $paid, 'id' ) );
    }

    public function testPaidOnlyItemsAreSkippedAndGetNoOwnerTask(): void {
        $data = self::data();
        $this->importAll( $this->service() );

        $state = self::state();
        $this->assertSame( NeutralCatalog::PAID_COUNT, $state['skip_reasons']['paid_only'] ?? 0 );
        $skipped = array_column( $state['skipped_items'], 'reason', 'id' );
        $this->assertSame( 'paid_only', $skipped[ self::PAID_NEWSWIRE ] ?? null );

        $this->assertNull( $this->taskForItem( self::PAID_NEWSWIRE ), 'no owner task opens the newswire pricing page' );
        $this->assertNull( Container::get( Prospects::class )->findByDomain( NeutralCatalog::PAID_HOST ) );
        foreach ( $data['items'] as $item ) {
            if ( CatalogImporter::isPaidOnly( $item ) ) {
                $this->assertNull( $this->taskForItem( (string) $item['id'] ), (string) $item['platform'] );
            }
        }
    }

    public function testAnEarlierOpenTaskForAPaidOnlyItemIsClosedEvenWhenTheItemIsUnchanged(): void {
        $data = self::data();
        $paidItem  = self::item( $data, self::PAID_NEWSWIRE );
        $free = $paidItem;
        $free['free'] = 'yes';
        $small = [ 'version' => 'paid-test-1', 'counts' => [], 'kit' => $data['kit'], 'gate' => $data['gate'], 'items' => [ $free ] ];

        // An earlier import (simulated with the item marked free) created an open task for it.
        $this->importAll( $this->service( $this->writeData( $small ) ) );
        $task = $this->taskForItem( self::PAID_NEWSWIRE );
        $this->assertNotNull( $task );
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $task['status'] );

        // The stored fingerprint already matches the paid-only item (as after an import by an older release):
        // the unchanged-item shortcut must not keep the task open.
        $kitHash = substr( sha1( (string) wp_json_encode( [ $data['kit'], $data['gate'] ] ) ), 0, 12 );
        $map     = get_option( CatalogImporter::OPTION_ITEMS, [] );
        $this->assertIsArray( $map );
        $this->assertArrayHasKey( self::PAID_NEWSWIRE, $map );
        $parts                         = explode( ':', (string) $map[ self::PAID_NEWSWIRE ] ); // "hash:submission id:prospect id"
        $parts[0]                      = substr( sha1( (string) wp_json_encode( $paidItem ) . '|' . $kitHash ), 0, 16 );
        $map[ self::PAID_NEWSWIRE ]    = implode( ':', $parts );
        update_option( CatalogImporter::OPTION_ITEMS, $map, false );

        $small['version'] = 'paid-test-2';
        $small['items']   = [ $paidItem ];
        $vo               = $this->service( $this->writeData( $small ) );
        $this->importAll( $vo );

        $task = $this->taskForItem( self::PAID_NEWSWIRE );
        $this->assertNotNull( $task );
        $this->assertSame( Submissions::STATUS_REJECTED, $task['status'] );
        $this->assertStringContainsString( 'Paid-only', (string) $task['last_error'] );
        $this->assertSame( 1, self::state()['skip_reasons']['paid_only'] ?? 0 );
        $this->assertArrayNotHasKey( self::PAID_NEWSWIRE, (array) get_option( CatalogImporter::OPTION_ITEMS, [] ) );
        $this->assertSame( 0, $vo->status()['owner_tasks_open'], 'the closed task no longer counts as an open owner task' );
    }

    // ------------------------------------------------------------------ already listed

    public function testSiteWithAListingAlreadySubmittedGetsNoSecondListingTask(): void {
        $prospects = Container::get( Prospects::class );
        [ $pid ]   = $prospects->upsertDiscovered( NeutralCatalog::LISTED_HOST, [ 'name' => 'Industry Hub', 'discovered_via' => 'seed' ] );
        $subs      = Container::get( Submissions::class );
        $sent      = $subs->create( $pid, null, 'https://www.' . NeutralCatalog::LISTED_HOST . '/add-company', [ 'method' => 'form', 'adapter' => 'generic_form', 'status' => Submissions::STATUS_SUBMITTED, 'submitted_at' => gmdate( 'Y-m-d H:i:s' ) ] );

        $this->importAll( $this->service() );

        $this->assertSame( [], $this->tasksFor( $pid ), 'no owner task asks for a second listing on the directory' );
        $this->assertSame( 1, Container::get( Submissions::class )->count( [ 'prospect_id' => $pid ] ) );
        $this->assertSame( 1, self::state()['skip_reasons']['already_listed'] ?? 0 );
        $this->assertSame( 'already_listed', array_column( self::state()['skipped_items'], 'reason', 'id' )[ NeutralCatalog::LISTED_ID ] ?? null );
        $this->assertSame( 0, Container::get( Opportunities::class )->count( [ 'prospect_id' => $pid, 'status' => Opportunities::STATUS_OPEN ] ), 'no open opportunity is left for the packaging stage' );
        $this->assertSame( Submissions::STATUS_SUBMITTED, Container::get( Submissions::class )->find( $sent )['status'] );
    }

    public function testSeparateSitesOnOneRegistrableDomainStillGetTheirTasks(): void {
        $prospects = Container::get( Prospects::class );
        [ $pid ]   = $prospects->upsertDiscovered( NeutralCatalog::QA_DOMAIN, [ 'name' => 'Q&A network', 'discovered_via' => 'seed' ] );
        // A contribution on the chemistry Q&A site was already made; the biology Q&A site is a separate site.
        Container::get( Submissions::class )->create( $pid, null, 'https://chemistry.' . NeutralCatalog::QA_DOMAIN . '/users/signup', [ 'method' => 'manual', 'adapter' => 'manual_package', 'status' => Submissions::STATUS_CONFIRMED, 'submitted_at' => gmdate( 'Y-m-d H:i:s' ) ] );

        $this->importAll( $this->service() );

        $biology   = $this->taskForItem( NeutralCatalog::BIOLOGY_ID );
        $chemistry = $this->taskForItem( NeutralCatalog::CHEMISTRY_ID );
        $this->assertNotNull( $biology, 'the biology Q&A site still gets its owner task' );
        $this->assertSame( Submissions::STATUS_NEEDS_MANUAL, $biology['status'] );
        $this->assertNull( $chemistry, 'the chemistry Q&A site, where a listing was already sent, gets none' );
        $this->assertSame( 1, self::state()['skip_reasons']['already_listed'] ?? 0 );
    }

    // ------------------------------------------------------------------ auto outreach

    public function testPreExistingSeedProspectWithAVerifiedOutreachTaskIsNotAutoDrafted(): void {
        Container::get( Settings::class )->update( [ 'outreach' => [ 'sender_name' => 'Jane Doe', 'sender_email' => 'jane@northwind.test', 'postal_address' => '1200 NW Front Ave, Portland, OR 97209, United States' ] ] );
        $db  = $GLOBALS['wpdb'];
        $now = gmdate( 'Y-m-d H:i:s' );
        $db->insert( Schema::table( 'prospects' ), [ 'domain' => NeutralCatalog::OUTREACH_HOST, 'name' => 'Trade Journal', 'status' => 'qualified', 'score' => 90, 'opportunity_type' => 'educational_contribution', 'discovered_via' => 'seed', 'first_seen' => $now, 'updated_at' => $now ] );
        $pid = (int) $db->insert_id;
        $db->insert( Schema::table( 'contacts' ), [ 'prospect_id' => $pid, 'email' => 'editor@' . NeutralCatalog::OUTREACH_HOST, 'name' => 'Ed', 'role' => 'editor', 'confidence' => 80, 'consent_basis' => 'conspicuous_publication', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] );
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
