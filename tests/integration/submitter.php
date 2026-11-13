<?php
/**
 * Integration section for the "Link Growth Submitter" role (required from run.php): a real WordPress user with
 * only that role lists and reads owner tasks and marks one submitted through rest_do_request(); every other
 * ppclg/v1 route answers 403 for them; the admin menu shows them one item. Creates its own rows and user and
 * removes them afterwards.
 */

declare(strict_types=1);

use PPC\LinkGrowth\Admin\Capabilities;

IT::step( 'Link Growth Submitter role (owner tasks for a staff member)', static function (): void {
    require_once ABSPATH . 'wp-admin/includes/user.php';

    // Role: created on activation with exactly read + the submitter capability.
    ppclg()->get( Capabilities::class )->ensure( true );
    $role = get_role( Capabilities::SUBMITTER_ROLE );
    IT::check( null !== $role, 'submitter role exists' );
    $caps = $role ? array_keys( array_filter( $role->capabilities ) ) : [];
    sort( $caps );
    IT::check( [ 'ppclg_submit_listings', 'read' ] === $caps, 'submitter role has only read and ppclg_submit_listings', wp_json_encode( $caps ) );
    IT::check( 'Link Growth Submitter' === ( wp_roles()->role_names[ Capabilities::SUBMITTER_ROLE ] ?? '' ), 'role display name' );
    IT::check( get_role( 'administrator' )->has_cap( Capabilities::CAP ), 'administrator keeps ppclg_manage' );

    // After an update that ran no activation hook, the previous version's hourly sync is still fresh and the role
    // does not exist yet: the next init sync creates it anyway.
    remove_role( Capabilities::SUBMITTER_ROLE );
    set_transient( Capabilities::SYNC_TRANSIENT, 1, HOUR_IN_SECONDS );
    ppclg()->get( Capabilities::class )->ensure();
    IT::check( null !== get_role( Capabilities::SUBMITTER_ROLE ) && isset( get_editable_roles()[ Capabilities::SUBMITTER_ROLE ] ), 'a missing role is created even while the sync is throttled (offered on user-new.php)' );

    $login = 'ppclg_it_submitter';
    $old   = get_user_by( 'login', $login );
    if ( $old ) {
        wp_delete_user( (int) $old->ID );
    }
    $uid = wp_insert_user( [ 'user_login' => $login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'submitter@purepeptidescanada.test', 'role' => Capabilities::SUBMITTER_ROLE ] );
    IT::check( is_int( $uid ) && $uid > 0, 'submitter user created', is_wp_error( $uid ) ? $uid->get_error_message() : '' );
    if ( ! is_int( $uid ) ) {
        return;
    }
    IT::check( user_can( $uid, Capabilities::SUBMIT_CAP ) && ! user_can( $uid, Capabilities::CAP ) && ! user_can( $uid, 'manage_options' ) && ! user_can( $uid, 'edit_posts' ), 'user has the submitter capability and nothing more' );

    // Fixture rows: one open owner task, one row awaiting review (outside the submitter's scope).
    $subs      = ppclg()->get( \PPC\LinkGrowth\Repositories\Submissions::class );
    [ $pid ]   = ppclg()->get( \PPC\LinkGrowth\Repositories\Prospects::class )->upsertDiscovered( 'submitter-it.test', [ 'name' => 'Submitter IT Directory' ] );
    $package   = [ 'source' => 'verified_db', 'item_id' => 'it-submitter', 'platform' => 'Submitter IT Directory', 'tier' => 2, 'outreach' => false, 'conditional' => false, 'steps' => 'Create an account. Add the listing.' ];
    $open      = $subs->create( (int) $pid, null, 'https://submitter-it.test/add-listing', [ 'status' => 'needs_manual', 'method' => 'manual', 'last_error' => 'internal adapter note', 'response' => [ 'raw' => 'adapter body' ], 'payload' => [ 'package' => $package, 'instructions' => [ 'Create an account.' ], 'form' => [ 'token' => 'csrf-it' ] ] ] );
    $pending   = $subs->create( (int) $pid, null, 'https://submitter-it.test/other', [ 'status' => 'pending_review' ] );

    // Owner tasks the owner stopped, made through the normal admin routes, plus a site that already has a sent
    // listing. None of them may reach the submitter.
    $prospects   = ppclg()->get( \PPC\LinkGrowth\Repositories\Prospects::class );
    $kit         = [ 'content' => [ 'description' => 'Pure Peptides Canada supplies research peptides for in-vitro research use only.' ], 'instructions' => [ 'Fill the form.' ], 'compliance' => [ 'clean' => true, 'violations' => [] ] ];
    [ $supPid ]  = $prospects->upsertDiscovered( 'submitter-it-suppressed.test', [ 'name' => 'Suppressed IT' ] );
    [ $clmPid ]  = $prospects->upsertDiscovered( 'submitter-it-claims.test', [ 'name' => 'Claims IT' ] );
    [ $dupPid ]  = $prospects->upsertDiscovered( 'submitter-it-dup.test', [ 'name' => 'Duplicate IT' ] );
    $suppressed  = $subs->create( (int) $supPid, null, 'https://submitter-it-suppressed.test/add', [ 'status' => 'needs_manual', 'method' => 'manual', 'payload' => [ 'package' => $package + [ 'item_id' => 'it-sup' ] ] + $kit ] );
    $claims      = $subs->create( (int) $clmPid, null, 'https://submitter-it-claims.test/add', [ 'status' => 'needs_manual', 'method' => 'manual', 'payload' => $kit ] );
    $dupSent     = $subs->create( (int) $dupPid, null, 'https://submitter-it-dup.test/first', [ 'status' => 'submitted', 'method' => 'form', 'submitted_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ] );
    $duplicate   = $subs->create( (int) $dupPid, null, 'https://submitter-it-dup.test/second', [ 'status' => 'needs_manual', 'method' => 'manual', 'payload' => $kit ] );
    $blocked     = [ 'suppressed site' => $suppressed, 'claims-flagged text' => $claims, 'duplicate listing' => $duplicate ];

    $do = static function ( string $method, string $route, array $params = [] ) {
        $req = new WP_REST_Request( $method, '/ppclg/v1' . $route );
        foreach ( $params as $k => $v ) {
            $req->set_param( $k, $v );
        }
        if ( 'POST' === $method ) {
            $req->set_header( 'Content-Type', 'application/json' );
            $req->set_body( wp_json_encode( $params ) );
        }
        return rest_do_request( $req );
    };

    $removed = false;
    try {
        wp_set_current_user( 1 );
        $sup = $do( 'POST', '/prospects/' . (int) $supPid . '/suppress', [ 'reason' => 'Integration test' ] );
        IT::check( 200 === $sup->get_status() && 'suppressed' === ( $prospects->find( (int) $supPid )['status'] ?? '' ), 'owner suppresses a site that has an open owner task', (string) $sup->get_status() );
        $edit = $do( 'POST', '/submissions/' . $claims . '/payload', [ 'payload' => [ 'content' => [ 'description' => 'This peptide cures cancer and treats diabetes.' ] ] ] );
        IT::check( 200 === $edit->get_status() && false === ( $edit->get_data()['clean'] ?? null ) && 'needs_manual' === ( $subs->find( $claims )['status'] ?? '' ), 'owner edit that fails the claims check leaves the task open', wp_json_encode( $edit->get_data()['clean'] ?? null ) );
        $ownerView = $do( 'GET', '/submissions/' . $claims );
        IT::check( [] !== (array) ( $ownerView->get_data()['submitter_blockers'] ?? [] ), 'owner detail says why the task is kept from the submitter' );

        wp_set_current_user( $uid );
        // Allowed: list, one task, mark submitted.
        $list = $do( 'GET', '/submissions', [ 'per_page' => 100 ] );
        $ids  = array_map( static fn( array $i ): int => (int) $i['id'], (array) ( $list->get_data()['items'] ?? [] ) );
        IT::check( 200 === $list->get_status(), 'submitter: GET /submissions 200', (string) $list->get_status() );
        IT::check( in_array( $open, $ids, true ) && ! in_array( $pending, $ids, true ), 'submitter list holds the open task and not the pending_review row' );
        $statuses = array_unique( array_map( static fn( array $i ): string => (string) $i['status'], (array) ( $list->get_data()['items'] ?? [] ) ) );
        IT::check( [] === array_diff( $statuses, [ 'needs_manual', 'submitted', 'confirmed' ] ), 'submitter list holds owner-task statuses only', wp_json_encode( $statuses ) );
        $widened = $do( 'GET', '/submissions', [ 'status' => 'pending_review,approved,failed' ] );
        IT::check( 200 === $widened->get_status() && 0 === (int) ( $widened->get_data()['total'] ?? -1 ), 'submitter: a status filter cannot widen the scope', wp_json_encode( $widened->get_data()['total'] ?? null ) );
        $json = wp_json_encode( $list->get_data() );
        IT::check( ! str_contains( (string) $json, 'internal adapter note' ) && ! str_contains( (string) $json, 'adapter body' ), 'submitter list carries no errors or adapter responses' );

        $one = $do( 'GET', '/submissions/' . $open );
        IT::check( 200 === $one->get_status() && 'it-submitter' === ( $one->get_data()['submission']['payload']['package']['item_id'] ?? '' ), 'submitter: GET /submissions/{id} returns the kit', (string) $one->get_status() );
        $oneJson = (string) wp_json_encode( $one->get_data() );
        IT::check( ! isset( $one->get_data()['submission']['response'] ) && ! str_contains( $oneJson, 'csrf-it' ) && ! str_contains( $oneJson, 'internal adapter note' ), 'submitter detail strips responses, errors and the form dump' );
        IT::check( 404 === $do( 'GET', '/submissions/' . $pending )->get_status(), 'submitter: a row outside the queue is 404' );
        foreach ( $blocked as $label => $bid ) {
            IT::check( ! in_array( $bid, $ids, true ), "submitter list leaves out the {$label} task" );
            IT::check( 404 === $do( 'GET', '/submissions/' . $bid )->get_status(), "submitter: GET of the {$label} task is 404" );
            IT::check( 404 === $do( 'POST', '/submissions/' . $bid . '/mark-submitted', [ 'listing_url' => '' ] )->get_status() && 'needs_manual' === ( $subs->find( $bid )['status'] ?? '' ), "submitter: cannot mark the {$label} task submitted" );
        }

        // Denied: every other route (the permission callback of each registered handler), plus real requests.
        $denied  = 0;
        $leaks   = [];
        $allowed = [ '/ppclg/v1/submissions' => [ 'GET' ], '/ppclg/v1/submissions/(?P<id>\d+)' => [ 'GET' ], '/ppclg/v1/submissions/(?P<id>\d+)/mark-submitted' => [ 'POST' ] ];
        $public  = [ '/ppclg/v1/unsubscribe', '/ppclg/v1/inbound' ];
        foreach ( rest_get_server()->get_routes( 'ppclg/v1' ) as $route => $handlers ) {
            foreach ( $handlers as $handler ) {
                foreach ( array_keys( (array) ( $handler['methods'] ?? [] ) ) as $method ) {
                    $cb = $handler['permission_callback'] ?? null;
                    if ( '/ppclg/v1' === $route || in_array( $route, $public, true ) || in_array( $method, $allowed[ $route ] ?? [], true ) ) {
                        continue;
                    }
                    $r = is_callable( $cb ) ? call_user_func( $cb, new WP_REST_Request( $method, $route ) ) : true;
                    if ( $r instanceof WP_Error && 403 === (int) ( $r->get_error_data()['status'] ?? 0 ) ) {
                        ++$denied;
                    } else {
                        $leaks[] = $method . ' ' . $route;
                    }
                }
            }
        }
        IT::check( [] === $leaks && $denied > 70, 'submitter: every other ppclg/v1 route is refused with 403 (' . $denied . ' checked)', implode( ', ', $leaks ) );
        foreach ( [ [ 'GET', '/prospects' ], [ 'GET', '/settings' ], [ 'GET', '/logs' ], [ 'GET', '/notifications' ], [ 'GET', '/overview' ], [ 'GET', '/outreach/campaigns' ], [ 'GET', '/backlinks' ], [ 'GET', '/automation/status' ], [ 'GET', '/compliance/storefront' ], [ 'GET', '/growth/status' ], [ 'GET', '/submissions/verified' ], [ 'POST', '/submissions/' . $open . '/approve' ], [ 'POST', '/submissions/' . $open . '/reject' ], [ 'POST', '/submissions/' . $open . '/payload' ], [ 'POST', '/settings' ] ] as [ $m, $r ] ) {
            $res = $do( $m, $r );
            IT::check( 403 === $res->get_status(), "submitter: {$m} {$r} is 403", (string) $res->get_status() );
        }
        IT::check( 'needs_manual' === ( $subs->find( $open )['status'] ?? '' ), 'refused calls changed nothing' );

        $bad = $do( 'POST', '/submissions/' . $open . '/mark-submitted', [ 'listing_url' => 'javascript:alert(1)' ] );
        IT::check( 400 === $bad->get_status(), 'submitter: a non-http(s) listing URL is refused', (string) $bad->get_status() );
        $ownSite = ppclg()->get( \PPC\LinkGrowth\Support\Settings::class )->siteDomain();
        if ( '' !== $ownSite ) {
            $own = $do( 'POST', '/submissions/' . $open . '/mark-submitted', [ 'listing_url' => 'https://' . $ownSite . '/' ] );
            IT::check( 400 === $own->get_status(), 'submitter: a listing URL on the own site is refused', (string) $own->get_status() );
        }
        $foreign = $do( 'POST', '/submissions/' . $open . '/mark-submitted', [ 'listing_url' => 'https://unrelated-it.test/post' ] );
        IT::check( 400 === $foreign->get_status() && 'needs_manual' === ( $subs->find( $open )['status'] ?? '' ), 'submitter: a listing URL on an unrelated site is refused', (string) $foreign->get_status() );
        $mark = $do( 'POST', '/submissions/' . $open . '/mark-submitted', [ 'listing_url' => 'https://submitter-it.test/listing/pure-peptides-canada', 'note' => 'Done by the assistant' ] );
        IT::check( 200 === $mark->get_status() && true === ( $mark->get_data()['ok'] ?? null ), 'submitter: POST mark-submitted 200', wp_json_encode( $mark->get_data() ) );
        $row = $subs->find( $open );
        IT::check( 'submitted' === ( $row['status'] ?? '' ) && 'https://submitter-it.test/listing/pure-peptides-canada' === ( $row['listing_url'] ?? '' ) && (int) ( $row['reviewed_by'] ?? 0 ) === $uid, 'owner task marked submitted by the submitter, verification scheduled', wp_json_encode( [ $row['status'] ?? null, $row['next_verify_at'] ?? null ] ) );
        IT::check( '' !== (string) ( $row['next_verify_at'] ?? '' ), 'listing verification scheduled' );
        $audit = ppclg()->get( \PPC\LinkGrowth\Repositories\AuditLog::class )->findOneBy( [ 'action' => 'submission.marked_submitted', 'object_id' => $open ] );
        IT::check( null !== $audit && 'user:' . $uid === $audit['actor'], 'audit log records the submitter user id', wp_json_encode( $audit['actor'] ?? null ) );
        IT::check( 409 === $do( 'POST', '/submissions/' . $open . '/mark-submitted' )->get_status(), 'submitter: marking it twice is refused' );
        IT::check( 404 === $do( 'POST', '/submissions/' . $pending . '/mark-submitted' )->get_status(), 'submitter: cannot mark a pending_review row' );
        IT::check( 'pending_review' === ( $subs->find( $pending )['status'] ?? '' ), 'pending_review row unchanged' );

        // Admin menu: one item for the submitter.
        global $menu, $submenu;
        $savedMenu    = $menu;
        $savedSubmenu = $submenu;
        $menu         = [];
        $submenu      = [];
        ppclg()->get( \PPC\LinkGrowth\Admin\Menu::class )->addMenu();
        $slugs = array_map( static fn( array $item ): string => (string) ( $item[2] ?? '' ), (array) $menu );
        IT::check( [ 'ppclg-submissions' ] === array_values( $slugs ) && empty( $submenu['ppclg'] ), 'submitter admin menu: a single "Link Submissions" item', wp_json_encode( $slugs ) );
        IT::check( false === ppclg()->get( \PPC\LinkGrowth\Admin\Menu::class )->allowSubmitterAdmin( true ), 'WooCommerce wp-admin block is lifted for the submitter' );
        $config = ppclg()->get( \PPC\LinkGrowth\Admin\Menu::class )->config( 'submissions' );
        IT::check( true === $config['capabilities']['submitOnly'] && [ 'submissions' ] === array_keys( $config['pages'] ), 'bootstrap flags the submitter view' );
        $menu    = $savedMenu;
        $submenu = $savedSubmenu;
    } finally {
        wp_set_current_user( 1 );
        $subs->delete( $open );
        $subs->delete( $pending );
        foreach ( [ $suppressed, $claims, $dupSent, $duplicate ] as $rowId ) {
            $subs->delete( $rowId );
        }
        ppclg()->get( \PPC\LinkGrowth\Repositories\Suppression::class )->remove( 'domain', 'submitter-it-suppressed.test' );
        $removed = wp_delete_user( $uid );
    }
    global $wpdb;
    IT::check( true === $removed && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE ID = %d", $uid ) ), 'submitter user removed' ); // phpcs:ignore
} );
