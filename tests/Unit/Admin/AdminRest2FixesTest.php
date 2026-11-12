<?php
/**
 * Regression tests for the admin-rest-2 slice fixes: claims gate on reviewer edits and approvals,
 * no re-queue of already-sent submissions, prospect delete keeping dedupe history, backup download
 * file names, versioned ES-module imports and the scanner-safe unsubscribe GET.
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Admin;

use PPC\LinkGrowth\Admin\Assets;
use PPC\LinkGrowth\Admin\Rest\Controller;
use PPC\LinkGrowth\Admin\Rest\ProspectsController;
use PPC\LinkGrowth\Admin\Rest\PublicController;
use PPC\LinkGrowth\Admin\Rest\SettingsController;
use PPC\LinkGrowth\Admin\Rest\SubmissionsController;
use PPC\LinkGrowth\Database\Backup;
use PPC\LinkGrowth\Repositories\Contacts;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Repositories\Suppression;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Tests\Support\Container;
use PPC\LinkGrowth\Tests\Support\HttpFixtures;
use PPC\LinkGrowth\Tests\Support\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminRest2FixesTest extends TestCase {

    private const TARGET    = 'https://labdirectory.test/add-listing/';
    private const VIOLATING = 'FDA approved peptides that cure injuries, safe for humans. Dosage guide included.';
    private const CLEAN     = 'Canadian supplier of research peptides and laboratory reagents.';

    protected function setUp(): void {
        parent::setUp();
        HttpFixtures::add( 'https://labdirectory.test/robots.txt', "User-agent: *\nAllow: /\n", 200, [ 'content-type' => 'text/plain' ] );
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    }

    // ------------------------------------------------------------------ helpers

    private function prospect( string $domain = 'labdirectory.test' ): int {
        $prospects = Container::get( Prospects::class );
        [ $pid ]   = $prospects->upsertDiscovered( $domain, [ 'name' => 'Lab Directory' ] );
        $prospects->update( $pid, [ 'score' => 90 ] );
        return $pid;
    }

    /** @param array<string,mixed> $data */
    private function submission( int $pid, string $status, string $text = self::CLEAN, array $data = [] ): int {
        $form    = [ 'kind' => 'listing_submission', 'method' => 'POST', 'action' => self::TARGET, 'has_captcha' => false, 'has_password' => false,
                     'fields' => [ [ 'name' => 'description', 'semantic' => 'description', 'type' => 'textarea', 'required' => true ] ] ];
        $payload = [
            'content'           => [ 'description' => $text ],
            'fields'            => [ [ 'name' => 'description', 'semantic' => 'description', 'type' => 'textarea', 'required' => true, 'value' => $text ] ],
            'unmapped_required' => [],
            'form'              => $form,
            'compliance'        => [ 'clean' => true, 'violations' => [] ],
        ];
        return Container::get( Submissions::class )->create(
            $pid,
            null,
            self::TARGET,
            array_merge( [ 'status' => $status, 'adapter' => 'generic_form', 'method' => 'form', 'payload' => $payload, 'scheduled_at' => gmdate( 'Y-m-d H:i:s' ) ], $data )
        );
    }

    private function submissionsController(): SubmissionsController {
        return new SubmissionsController( Container::plugin()->container() );
    }

    /** @return array<string,mixed> */
    private function editPayload( int $sid, string $text ): array {
        $req = new WP_REST_Request( 'POST', '/ppclg/v1/submissions/' . $sid . '/payload' );
        $req->set_param( 'id', $sid );
        $req->set_param( 'payload', [ 'fields' => [ [ 'name' => 'description', 'value' => $text ] ] ] );
        $res = $this->submissionsController()->payload( $req );
        $this->assertIsArray( $res );
        return $res;
    }

    /** @return array<string,mixed> */
    private function act( int $sid, string $action ): array {
        $req = new WP_REST_Request( 'POST', "/ppclg/v1/submissions/$sid/$action" );
        $req->set_param( 'id', $sid );
        $req->set_param( 'action', $action );
        $req->set_param( 'reason', 'reviewer decision' );
        $res = $this->submissionsController()->action( $req );
        $this->assertIsArray( $res );
        return $res;
    }

    /** @return int[] */
    private function readyIds(): array {
        return array_map( static fn( array $r ): int => (int) $r['id'], Container::get( Submissions::class )->readyToProcess( 20 ) );
    }

    private function rawBody( WP_REST_Response $response ): string {
        $prop = new \ReflectionProperty( Controller::class, 'raw' );
        $raw  = $prop->getValue();
        return (string) ( $raw[ spl_object_id( $response ) ]['body'] ?? '' );
    }

    // ------------------------------------------------------------------ admin-rest-2-1

    public function testViolatingEditTakesQueuedSubmissionOutOfTheAutomatedQueue(): void {
        $sid = $this->submission( $this->prospect(), Submissions::STATUS_QUEUED );
        $this->assertSame( [ $sid ], $this->readyIds() );

        $res = $this->editPayload( $sid, self::VIOLATING );
        $this->assertTrue( $res['ok'] );
        $this->assertFalse( $res['clean'] );
        $this->assertNotEmpty( $res['violations'] );
        $this->assertTrue( $res['held'] );

        $row = Container::get( Submissions::class )->find( $sid );
        $this->assertSame( Submissions::STATUS_PENDING_REVIEW, $row['status'] );
        $this->assertNull( $row['scheduled_at'] );
        $this->assertStringContainsString( 'claims check', (string) $row['last_error'] );
        $this->assertSame( [], $this->readyIds(), 'a submission whose text fails the claims check must not be picked up for posting' );
    }

    public function testApproveAndRetryRefuseTextThatFailsTheClaimsCheck(): void {
        $pid = $this->prospect();
        $sid = $this->submission( $pid, Submissions::STATUS_QUEUED );
        $this->editPayload( $sid, self::VIOLATING );

        $res = $this->act( $sid, 'approve' );
        $this->assertFalse( $res['ok'] );
        $this->assertStringContainsString( 'claims check', $res['message'] );
        $this->assertSame( Submissions::STATUS_PENDING_REVIEW, $res['submission']['status'] );
        $this->assertSame( [], $this->readyIds() );

        // Once the text is fixed the reviewer can approve it again.
        $clean = $this->editPayload( $sid, self::CLEAN );
        $this->assertTrue( $clean['clean'] );
        $this->assertFalse( $clean['held'] );
        $ok = $this->act( $sid, 'approve' );
        $this->assertTrue( $ok['ok'], (string) $ok['message'] );
        $this->assertSame( Submissions::STATUS_APPROVED, $ok['submission']['status'] );

        // Retry of a failed submission with violating text is refused as well.
        $failed = $this->submission( $this->prospect( 'otherdirectory.test' ), Submissions::STATUS_FAILED, self::VIOLATING, [ 'scheduled_at' => null ] );
        $retry  = $this->act( $failed, 'retry' );
        $this->assertFalse( $retry['ok'] );
        $this->assertSame( Submissions::STATUS_FAILED, $retry['submission']['status'] );
    }

    public function testViolatingEditOnApprovedSubmissionIsHeldToo(): void {
        $sid = $this->submission( $this->prospect(), Submissions::STATUS_APPROVED );
        $res = $this->editPayload( $sid, self::VIOLATING );
        $this->assertTrue( $res['held'] );
        $this->assertSame( Submissions::STATUS_PENDING_REVIEW, Container::get( Submissions::class )->find( $sid )['status'] );
    }

    // ------------------------------------------------------------------ admin-rest-2-2

    public function testRejectThenRetryDoesNotRequeueAnAlreadySentSubmission(): void {
        $sid = $this->submission( $this->prospect(), Submissions::STATUS_SUBMITTED, self::CLEAN, [ 'submitted_at' => gmdate( 'Y-m-d H:i:s' ), 'scheduled_at' => null ] );

        $reject = $this->act( $sid, 'reject' );
        $this->assertTrue( $reject['ok'] );
        $this->assertSame( Submissions::STATUS_REJECTED, $reject['submission']['status'] );

        foreach ( [ 'retry', 'rebuild', 'approve' ] as $action ) {
            $res = $this->act( $sid, $action );
            $this->assertFalse( $res['ok'], $action . ' must be refused for a submission that was already sent' );
            $this->assertStringContainsString( 'already sent', $res['message'] );
            $this->assertSame( Submissions::STATUS_REJECTED, $res['submission']['status'] );
        }
        $this->assertSame( [], $this->readyIds() );
    }

    public function testRetryOfANeverSentRejectedSubmissionStillWorks(): void {
        $sid = $this->submission( $this->prospect(), Submissions::STATUS_REJECTED, self::CLEAN, [ 'scheduled_at' => null ] );
        $res = $this->act( $sid, 'retry' );
        $this->assertTrue( $res['ok'], (string) $res['message'] );
        $this->assertSame( Submissions::STATUS_QUEUED, $res['submission']['status'] );
    }

    // ------------------------------------------------------------------ admin-rest-2-3

    private function destroy( int $pid ): mixed {
        $req = new WP_REST_Request( 'DELETE', '/ppclg/v1/prospects/' . $pid );
        $req->set_param( 'id', $pid );
        return ( new ProspectsController( Container::plugin()->container() ) )->destroy( $req );
    }

    public function testDeleteIsRefusedOnceASubmissionWentOut(): void {
        $pid = $this->prospect();
        $sid = $this->submission( $pid, Submissions::STATUS_CONFIRMED, self::CLEAN, [ 'submitted_at' => gmdate( 'Y-m-d H:i:s' ), 'scheduled_at' => null ] );

        $res = $this->destroy( $pid );
        $this->assertInstanceOf( WP_Error::class, $res );
        $this->assertSame( 'ppclg_prospect_has_history', $res->get_error_code() );
        $this->assertSame( 409, $res->get_error_data()['status'] );
        $this->assertNotNull( Container::get( Prospects::class )->find( $pid ) );
        $this->assertNotNull( Container::get( Submissions::class )->find( $sid ), 'the record behind the duplicate-submission gate must survive' );
    }

    public function testDeleteIsRefusedOnceAnOutreachEmailWentOut(): void {
        $pid      = $this->prospect();
        [ $cid ]  = Container::get( Contacts::class )->upsert( $pid, 'editor@labdirectory.test' );
        $mid      = Container::get( OutreachMessages::class )->create( [ 'prospect_id' => $pid, 'contact_id' => $cid, 'status' => OutreachMessages::STATUS_SENT, 'sent_at' => gmdate( 'Y-m-d H:i:s' ) ] );

        $res = $this->destroy( $pid );
        $this->assertInstanceOf( WP_Error::class, $res );
        $this->assertSame( 1, $res->get_error_data()['sent_messages'] );
        $this->assertNotNull( Container::get( OutreachMessages::class )->find( $mid ), 'the record behind the contact-interval gate must survive' );
    }

    public function testDeleteOfAProspectWithNothingSentStillCascades(): void {
        $pid = $this->prospect();
        $sid = $this->submission( $pid, Submissions::STATUS_PENDING_REVIEW );

        $res = $this->destroy( $pid );
        $this->assertSame( [ 'ok' => true, 'deleted' => $pid ], $res );
        $this->assertNull( Container::get( Prospects::class )->find( $pid ) );
        $this->assertNull( Container::get( Submissions::class )->find( $sid ) );
    }

    // ------------------------------------------------------------------ admin-rest-2-4

    public function testBackupDownloadRouteKeepsTheGeneratedFileName(): void {
        ( new SettingsController( Container::plugin()->container() ) )->register( 'ppclg/v1' );
        $route = $GLOBALS['ppclg_test_routes']['ppclg/v1/settings/backups/(?P<file>[\w\-]+\.sql\.gz)'] ?? null;
        $this->assertIsArray( $route );
        $arg = end( $route )[0]['args']['file'];

        $name = '20261111-234005-manual-AbCd1234.sql.gz';
        $this->assertNotSame( 'sanitize_file_name', $arg['sanitize_callback'] ?? null, 'core sanitize_file_name() rewrites .sql.gz to .sql_.gz' );
        $this->assertSame( $name, ( $arg['sanitize_callback'] )( $name ) );
        $this->assertTrue( ( $arg['validate_callback'] )( $name ) );
        foreach ( [ '../wp-config.sql.gz', 'a/b.sql.gz', 'name.sql_.gz', "name.sql.gz\n", 'name.php' ] as $bad ) {
            $this->assertFalse( ( $arg['validate_callback'] )( $bad ), $bad );
        }

        // The handler serves the file under the name WordPress passes through.
        $dir = Container::get( Backup::class )->directory();
        file_put_contents( $dir . '/' . $name, gzencode( 'SELECT 1;' ) );
        try {
            $req = new WP_REST_Request( 'GET', '/ppclg/v1/settings/backups/' . $name );
            $req->set_param( 'file', ( $arg['sanitize_callback'] )( $name ) );
            $res = ( new SettingsController( Container::plugin()->container() ) )->downloadBackup( $req );
            $this->assertInstanceOf( WP_REST_Response::class, $res );
            $this->assertSame( 200, $res->get_status() );
            $this->assertStringContainsString( $name, $res->get_headers()['Content-Disposition'] ?? '' );
        } finally {
            @unlink( $dir . '/' . $name );
        }
    }

    // ------------------------------------------------------------------ admin-rest-2-5

    public function testEveryRelativeModuleImportIsVersionedThroughTheImportMap(): void {
        $assets  = new Assets( Container::get( Settings::class ) );
        $modules = $assets->moduleVersions( '9.9.9' );
        $this->assertArrayNotHasKey( PPCLG_URL . 'assets/js/app.js', $modules, 'the entry is enqueued itself, not mapped' );

        $files = array_merge( glob( PPCLG_DIR . 'assets/js/*.js' ) ?: [], glob( PPCLG_DIR . 'assets/js/pages/*.js' ) ?: [] );
        $this->assertNotEmpty( $files );
        $checked = 0;
        foreach ( $files as $file ) {
            $relative = substr( $file, strlen( PPCLG_DIR ) );
            $source   = (string) file_get_contents( $file );
            preg_match_all( '/(?:\bfrom\s*|\bimport\s*\(\s*|\bimport\s+)([\'"])(\.{1,2}\/[^\'"]+)\1/', $source, $m );
            foreach ( $m[2] as $specifier ) {
                $resolved = self::resolve( PPCLG_URL . $relative, $specifier );
                $this->assertArrayHasKey( $resolved, $modules, "$relative imports $specifier, which the import map does not version" );
                $this->assertStringStartsWith( '9.9.9', $modules[ $resolved ] );
                $checked++;
            }
        }
        $this->assertGreaterThan( 10, $checked );
        foreach ( array_keys( $modules ) as $url ) {
            $this->assertFileExists( PPCLG_DIR . substr( $url, strlen( PPCLG_URL ) ) );
        }
    }

    /** Browser-style resolution of a relative specifier against the importing module's URL. */
    private static function resolve( string $base, string $specifier ): string {
        $parts = explode( '/', substr( $base, 0, (int) strrpos( $base, '/' ) ) );
        foreach ( explode( '/', $specifier ) as $seg ) {
            if ( '..' === $seg ) {
                array_pop( $parts );
            } elseif ( '.' !== $seg && '' !== $seg ) {
                $parts[] = $seg;
            }
        }
        return implode( '/', $parts );
    }

    // ------------------------------------------------------------------ admin-rest-2-6

    public function testUnsubscribeGetOnlyRendersAConfirmationAndPostOptsOut(): void {
        $pid     = $this->prospect();
        [ $cid ] = Container::get( Contacts::class )->upsert( $pid, 'editor@labdirectory.test' );
        $token   = str_repeat( 'ab', 16 );
        $msgs    = Container::get( OutreachMessages::class );
        $mid     = $msgs->create( [ 'prospect_id' => $pid, 'contact_id' => $cid, 'status' => OutreachMessages::STATUS_SENT, 'sent_at' => gmdate( 'Y-m-d H:i:s' ), 'unsubscribe_token' => $token ] );
        $followUp = $msgs->create( [ 'prospect_id' => $pid, 'contact_id' => $cid, 'status' => OutreachMessages::STATUS_SCHEDULED, 'sequence' => 2 ] );
        $ctl     = new PublicController( Container::plugin()->container() );

        // A link scanner (or the recipient) opening the URL changes nothing.
        $get = new WP_REST_Request( 'GET', '/ppclg/v1/unsubscribe' );
        $get->set_param( 't', $token );
        $get->set_header( 'user-agent', 'Mozilla/5.0 (compatible; SafeLinks scanner)' );
        $page = $ctl->unsubscribePage( $get );
        $this->assertInstanceOf( WP_REST_Response::class, $page );
        $this->assertSame( 200, $page->get_status() );
        $html = $this->rawBody( $page );
        $this->assertMatchesRegularExpression( '/<form method="post" action="[^"]*unsubscribe[^"]*">/', $html );
        $this->assertStringContainsString( 'name="t" value="' . $token . '"', $html );
        $this->assertSame( OutreachMessages::STATUS_SENT, $msgs->find( $mid )['status'] );
        $this->assertSame( OutreachMessages::STATUS_SCHEDULED, $msgs->find( $followUp )['status'] );
        $this->assertSame( Contacts::STATUS_ACTIVE, Container::get( Contacts::class )->find( $cid )['status'] );
        $this->assertFalse( Container::get( Suppression::class )->isSuppressed( 'email', 'editor@labdirectory.test' ) );

        // The confirmation button (form POST) opts out.
        $post = new WP_REST_Request( 'POST', '/ppclg/v1/unsubscribe' );
        $post->set_param( 't', $token );
        $post->set_header( 'accept', 'text/html,application/xhtml+xml' );
        $done = $ctl->unsubscribePost( $post );
        $this->assertSame( 200, $done->get_status() );
        $this->assertStringContainsString( 'You have been unsubscribed', $this->rawBody( $done ) );
        $this->assertSame( OutreachMessages::STATUS_OPTED_OUT, $msgs->find( $mid )['status'] );
        $this->assertSame( OutreachMessages::STATUS_OPTED_OUT, $msgs->find( $followUp )['status'] );
        $this->assertTrue( Container::get( Suppression::class )->isSuppressed( 'email', 'editor@labdirectory.test' ) );
    }

    public function testUnsubscribeGetWithUnknownOrMalformedTokenIsNotFound(): void {
        $ctl = new PublicController( Container::plugin()->container() );
        foreach ( [ 'not-a-token', str_repeat( 'cd', 16 ) ] as $token ) {
            $get = new WP_REST_Request( 'GET', '/ppclg/v1/unsubscribe' );
            $get->set_param( 't', $token );
            $res = $ctl->unsubscribePage( $get );
            $this->assertSame( 404, $res->get_status(), $token );
            $this->assertStringNotContainsString( '<form', $this->rawBody( $res ) );
        }
    }
}
