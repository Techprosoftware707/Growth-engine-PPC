<?php
/**
 * Integration section (1.2.1): the live bug on purepeptidescanada.com after installing 1.2.0. Drafts built while
 * outreach.postal_address was "222 Jarvis st Toronto" were refused on every approval once the address was corrected
 * ("Not approved: Body must contain the sender postal address."), never retried, and the card said they needed
 * editing. On real WordPress + SQLite: the stale footer is replaced in place before approval (Autopilot switch-on and
 * the Settings save) and before sending. Required from run.php after the Autopilot sections; leaves Autopilot off and
 * the settings it changed restored. Offline: *.test hosts, mail captured by run.php's pre_wp_mail filter.
 */

declare(strict_types=1);

use PPC\LinkGrowth\Outreach\Compliance;
use PPC\LinkGrowth\Outreach\OutreachEngine;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;

IT::step( '1.2.1: drafts built under an old postal address are refreshed and approved under Autopilot', static function (): void {
    global $wpdb;
    wp_set_current_user( 1 );
    $GLOBALS['itMail'] = [];
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
    $settings   = ppclg()->get( Settings::class );
    $messages   = ppclg()->get( OutreachMessages::class );
    $compliance = ppclg()->get( Compliance::class );
    $saved      = $settings->all();
    $old        = '222 Jarvis st Toronto';
    $new        = '222 Jarvis St, Toronto, ON M5B 2B8, Canada';
    $unsub      = "If you'd rather not hear from us again";
    $audits     = static fn( int $id ): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ppclg_audit_log WHERE action = %s AND object_id = %d", OutreachEngine::ACTION_FOOTER_REFRESHED, $id ) ); // phpcs:ignore

    // The live state: the old address, autosend on, individual approval on, an open window for the test.
    $settings->update( [
        'automation' => [ 'outreach_autosend' => true, 'daily_limits' => [ 'outreach_emails' => 50 ], 'weekly_limits' => [ 'outreach_emails' => 200 ] ],
        'outreach'   => [ 'postal_address' => $old, 'require_approval' => true, 'send_window_start' => 0, 'send_window_end' => 0, 'send_days' => [ 1, 2, 3, 4, 5, 6, 7 ] ],
    ] );
    $now  = gmdate( 'Y-m-d H:i:s' );
    $seed = static function ( string $domain ) use ( $wpdb, $now ): int {
        $wpdb->insert( $wpdb->prefix . 'ppclg_prospects', [ 'domain' => $domain, 'name' => $domain, 'status' => 'qualified', 'opportunity_type' => 'resource_page', 'score' => 82, 'discovered_via' => 'search', 'first_seen' => $now, 'updated_at' => $now ] );
        $id = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'ppclg_contacts', [ 'prospect_id' => $id, 'email' => 'editor@' . $domain, 'name' => 'Editor', 'role' => 'editor', 'confidence' => 80, 'consent_basis' => 'conspicuous_publication', 'status' => 'active', 'source_url' => 'https://' . $domain . '/contact/', 'created_at' => $now, 'updated_at' => $now ] );
        return $id;
    };
    $seed( 'fr-resources-a.test' );
    $seed( 'fr-resources-b.test' );

    // 1. Drafts built under the old address (Autopilot off, so they wait for approval).
    $create = (array) $do( 'POST', '/outreach/campaigns', [ 'name' => 'Footer refresh', 'approach' => 'resource_page', 'settings' => [ 'daily_limit' => 10 ] ] )->get_data();
    $campId = (int) ( $create['campaign_id'] ?? 0 );
    $drafts = $messages->findBy( [ 'campaign_id' => $campId ], 'id', 'ASC', 10 );
    IT::check( 2 === count( $drafts ) && [ 'draft', 'draft' ] === array_column( $drafts, 'status' ), 'two drafts built under the old address', wp_json_encode( [ $create['drafted'] ?? null, $create['reasons'] ?? null ] ) );
    $oldLine = "· {$old}\n{$unsub}";
    IT::check( 2 === count( array_filter( $drafts, static fn( array $m ): bool => 1 === substr_count( (string) $m['body'], $oldLine ) ) ), 'each carries the footer rendered with "222 Jarvis st Toronto"' );

    // 2. The owner corrects the address. With Autopilot off and individual approval on (the live settings), nothing is
    // approved automatically.
    $fix = $do( 'POST', '/settings', [ 'settings' => [ 'outreach' => [ 'postal_address' => $new ] ] ] );
    IT::check( 200 === $fix->get_status() && null === ( $fix->get_data()['approved_now'] ?? null ), 'address saved; no automatic approval while Autopilot is off and individual approval is on', wp_json_encode( $fix->get_data()['approved_now'] ?? null ) );
    IT::check( [ 'draft', 'draft' ] === array_map( static fn( array $m ): string => (string) $messages->find( (int) $m['id'] )['status'], $drafts ), 'the drafts still wait for approval' );
    IT::check( ! $compliance->bodyHasFooter( (string) $drafts[0]['body'], (string) $drafts[0]['unsubscribe_token'] ), 'the stale footer is what 1.2.0 refused' );
    foreach ( $drafts as $d ) {
        $messages->update( (int) $d['id'], [ 'last_error' => 'Not approved: Body must contain the sender postal address.' ] ); // the 1.2.0 note
    }
    $card = (array) ( $do( 'GET', '/outreach/campaigns/' . $campId )->get_data()['campaign']['next_step'] ?? [] );
    IT::check( 0 === ( $card['needs_edit'] ?? -1 ) && 2 === ( $card['waiting'] ?? 0 ), 'the card counts the stuck drafts as waiting, not "need editing"', wp_json_encode( $card ) );

    // 3. Autopilot on: the stuck drafts are retried, their footer is rebuilt in place and they are approved.
    $on = $do( 'POST', '/automation/autopilot', [ 'enabled' => true, 'confirm' => true ] );
    IT::check( 200 === $on->get_status() && ( $on->get_data()['approved_now'] ?? 0 ) >= 2, 'switching Autopilot on approved the stuck drafts', wp_json_encode( $on->get_data()['approved_now'] ?? null ) );
    foreach ( $drafts as $d ) {
        $row  = (array) $messages->find( (int) $d['id'] );
        $body = (string) ( $row['body'] ?? '' );
        IT::check( 'approved' === ( $row['status'] ?? '' ) && '' === (string) $row['last_error'], 'draft #' . $d['id'] . ' approved, note cleared', (string) ( $row['last_error'] ?? '' ) );
        IT::check( 1 === substr_count( $body, $new ) && 0 === substr_count( $body, $old ) && 1 === substr_count( $body, $unsub ), 'draft #' . $d['id'] . ' has the new address once, the old line zero times, one footer' );
        IT::check( str_replace( $oldLine, "· {$new}\n{$unsub}", (string) $d['body'] ) === $body, 'draft #' . $d['id'] . ': only the footer changed' );
        IT::check( (string) $d['unsubscribe_token'] === (string) $row['unsubscribe_token'] && 1 === $audits( (int) $d['id'] ), 'draft #' . $d['id'] . ': same unsubscribe token, one outreach.footer_refreshed audit entry' );
    }

    // 4. Under Autopilot, correcting the address in Settings approves a draft built under the old one at once.
    $seed( 'fr-resources-c.test' );
    $settings->update( [ 'outreach' => [ 'postal_address' => $old ] ] );
    $third = (int) ppclg()->get( OutreachEngine::class )->draftForProspect( $campId, (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}ppclg_prospects WHERE domain = 'fr-resources-c.test'" ), 0 ); // phpcs:ignore
    IT::check( $third > 0 && str_contains( (string) $messages->find( $third )['body'], $oldLine ), 'a third draft built under the old address' );
    $fix2 = $do( 'POST', '/settings', [ 'settings' => [ 'outreach' => [ 'postal_address' => $new ] ] ] );
    $row  = (array) $messages->find( $third );
    IT::check( 200 === $fix2->get_status() && ( $fix2->get_data()['approved_now'] ?? 0 ) >= 1 && 'approved' === ( $row['status'] ?? '' ), 'the Settings save approved it at once', wp_json_encode( [ $fix2->get_data()['approved_now'] ?? null, $row['last_error'] ?? null ] ) );
    IT::check( 1 === substr_count( (string) $row['body'], $new ) && 0 === substr_count( (string) $row['body'], $old ), 'its footer carries the new address only' );

    // 5. A footer that goes stale after approval (the brand here) is refreshed before sending.
    $settings->update( [ 'site' => [ 'brand_name' => 'Pure Peptides Canada Labs' ] ] );
    $sent = ppclg()->get( OutreachEngine::class )->sendDue( TimeBudget::seconds( 20.0 ), 20 );
    IT::check( ( $sent['sent'] ?? 0 ) >= 3, 'the send stage mailed the three messages', wp_json_encode( $sent ) );
    foreach ( array_merge( $drafts, [ [ 'id' => $third ] ] ) as $d ) {
        $row  = (array) $messages->find( (int) $d['id'] );
        $to   = 'editor@' . (string) $wpdb->get_var( $wpdb->prepare( "SELECT domain FROM {$wpdb->prefix}ppclg_prospects WHERE id = %d", (int) $row['prospect_id'] ) ); // phpcs:ignore
        $mail = array_values( array_filter( $GLOBALS['itMail'], static fn( array $m ): bool => ( is_array( $m['to'] ) ? implode( ',', $m['to'] ) : (string) $m['to'] ) === $to ) );
        $text = (string) ( $mail[0]['message'] ?? '' );
        IT::check( 'sent' === ( $row['status'] ?? '' ) && 1 === count( $mail ), $to . ' sent once', (string) ( $row['last_error'] ?? '' ) );
        IT::check( 1 === substr_count( $text, "Pure Peptides Canada Labs · {$new}\n{$unsub}" ) && 0 === substr_count( $text, $old ) && 1 === substr_count( $text, $unsub ), $to . ': the email carries the current footer once and the old address nowhere' );
        IT::check( $text === (string) $row['body'], $to . ': the record keeps what was sent' );
    }

    // Clean up: Autopilot off, settings back.
    $off = $do( 'POST', '/automation/autopilot', [ 'enabled' => false ] );
    IT::check( 200 === $off->get_status() && false === ( $off->get_data()['enabled'] ?? null ), 'Autopilot off again' );
    $settings->update( [
        'automation' => [ 'enabled' => (bool) $saved['automation']['enabled'], 'outreach_autosend' => (bool) $saved['automation']['outreach_autosend'], 'daily_limits' => $saved['automation']['daily_limits'], 'weekly_limits' => $saved['automation']['weekly_limits'] ],
        'outreach'   => [ 'postal_address' => (string) $saved['outreach']['postal_address'], 'require_approval' => (bool) $saved['outreach']['require_approval'], 'send_window_start' => (int) $saved['outreach']['send_window_start'], 'send_window_end' => (int) $saved['outreach']['send_window_end'], 'send_days' => $saved['outreach']['send_days'] ],
        'site'       => [ 'brand_name' => (string) $saved['site']['brand_name'] ],
    ] );
    IT::check( (string) $saved['outreach']['postal_address'] === (string) $settings->get( 'outreach.postal_address' ) && (string) $saved['site']['brand_name'] === (string) $settings->get( 'site.brand_name' ), 'settings restored' );
    $errors = ppclg()->get( \PPC\LinkGrowth\Repositories\Logs::class )->findBy( [ 'level' => 'error', 'channel' => 'outreach' ], 'id', 'DESC', 5 );
    IT::check( [] === $errors, 'no outreach errors logged during the section', wp_json_encode( array_column( $errors, 'message' ) ) );
} );

IT::step( '1.2.1 review: the whole postal address is printed and an edited footer never goes out with the old address', static function (): void {
    global $wpdb;
    wp_set_current_user( 1 );
    $GLOBALS['itMail'] = [];
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
    $settings = ppclg()->get( Settings::class );
    $messages = ppclg()->get( OutreachMessages::class );
    $engine   = ppclg()->get( OutreachEngine::class );
    $saved    = $settings->all();
    $old      = '222 Jarvis st Toronto';
    $new      = '222 Jarvis St, Toronto, ON M5B 2B8, Canada';
    $unsub    = "If you'd rather not hear from us again";
    $now      = gmdate( 'Y-m-d H:i:s' );
    $seed     = static function ( string $domain ) use ( $wpdb, $now ): int {
        $wpdb->insert( $wpdb->prefix . 'ppclg_prospects', [ 'domain' => $domain, 'name' => $domain, 'status' => 'qualified', 'opportunity_type' => 'resource_page', 'score' => 82, 'discovered_via' => 'search', 'first_seen' => $now, 'updated_at' => $now ] );
        $id = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'ppclg_contacts', [ 'prospect_id' => $id, 'email' => 'editor@' . $domain, 'name' => 'Editor', 'role' => 'editor', 'confidence' => 80, 'consent_basis' => 'conspicuous_publication', 'status' => 'active', 'source_url' => 'https://' . $domain . '/contact/', 'created_at' => $now, 'updated_at' => $now ] );
        return $id;
    };
    $mailTo = static function ( string $to ): array {
        return array_values( array_filter( $GLOBALS['itMail'], static fn( array $m ): bool => ( is_array( $m['to'] ) ? implode( ',', $m['to'] ) : (string) $m['to'] ) === $to ) );
    };
    $settings->update( [
        'automation' => [ 'outreach_autosend' => true, 'daily_limits' => [ 'outreach_emails' => 50 ], 'weekly_limits' => [ 'outreach_emails' => 200 ] ],
        'outreach'   => [ 'require_approval' => true, 'send_window_start' => 0, 'send_window_end' => 0, 'send_days' => [ 1, 2, 3, 4, 5, 6, 7 ] ],
    ] );

    // 1. A postal address over 200 characters, on two lines, saved through POST /settings (a textarea field).
    $long = "222 Jarvis St, Toronto, ON M5B 2B8, Canada\nc/o " . trim( str_repeat( 'Research Supply Division ', 8 ) );
    $res  = $do( 'POST', '/settings', [ 'settings' => [ 'outreach' => [ 'postal_address' => $long ] ] ] );
    $kept = (string) $settings->get( 'outreach.postal_address' );
    IT::check( 200 === $res->get_status() && $long === $kept, 'the long address is stored as typed, line break included', wp_json_encode( $kept ) );
    $line = str_replace( "\n", ', ', $long );
    $pidL = $seed( 'frr-long.test' );
    $camp = (array) $do( 'POST', '/outreach/campaigns', [ 'name' => 'Footer review', 'approach' => 'resource_page', 'settings' => [ 'daily_limit' => 10 ] ] )->get_data();
    $cid  = (int) ( $camp['campaign_id'] ?? 0 );
    IT::check( $cid > 0 && $engine->approveCampaign( $cid, 1 ), 'campaign created and approved', wp_json_encode( $camp['reasons'] ?? null ) );
    $longMsg = (array) $messages->findOneBy( [ 'campaign_id' => $cid, 'prospect_id' => $pidL ] );
    IT::check( str_contains( (string) ( $longMsg['body'] ?? '' ), "· {$line}\n{$unsub}" ), 'the footer prints the whole address on one line', (string) ( $longMsg['body'] ?? '' ) );
    $ap = (array) $do( 'POST', '/outreach/messages/' . (int) ( $longMsg['id'] ?? 0 ) . '/approve' )->get_data();
    IT::check( true === ( $ap['ok'] ?? null ), 'and the draft is approved', wp_json_encode( $ap['violations'] ?? null ) );
    $sent = $engine->sendDue( TimeBudget::seconds( 20.0 ), 20 );
    $mL   = $mailTo( 'editor@frr-long.test' );
    IT::check( 1 === count( $mL ) && 1 === substr_count( (string) $mL[0]['message'], "· {$line}\n{$unsub}" ), 'the send stage mailed it with the whole address', wp_json_encode( $sent ) );

    // 2. Drafts built under the old address, their footer edited by hand, then the address corrected.
    $settings->update( [ 'outreach' => [ 'postal_address' => $old ] ] );
    $pidA   = $seed( 'frr-blank.test' );
    $pidB   = $seed( 'frr-dash.test' );
    $idA    = (int) $engine->draftForProspect( $cid, $pidA, 0 );
    $idB    = (int) $engine->draftForProspect( $cid, $pidB, 0 );
    $rowA   = (array) $messages->find( $idA );
    $rowB   = (array) $messages->find( $idB );
    $editA  = str_replace( "\n{$unsub}", "\n\n{$unsub}", (string) $rowA['body'] );
    $editB  = str_replace( "Pure Peptides Canada · {$old}", "Pure Peptides Canada - {$old}", (string) $rowB['body'] );
    $saveA  = (array) $do( 'POST', '/outreach/messages/' . $idA, [ 'subject' => (string) $rowA['subject'], 'body' => $editA ] )->get_data();
    $saveB  = (array) $do( 'POST', '/outreach/messages/' . $idB, [ 'subject' => (string) $rowB['subject'], 'body' => $editB ] )->get_data();
    IT::check( $idA > 0 && $idB > 0 && true === ( $saveA['ok'] ?? null ) && true === ( $saveB['ok'] ?? null ), 'two drafts built under the old address, their footers edited (a blank line added; the "·" replaced)', wp_json_encode( [ $saveA['violations'] ?? null, $saveB['violations'] ?? null ] ) );
    $do( 'POST', '/settings', [ 'settings' => [ 'outreach' => [ 'postal_address' => $new ] ] ] );

    $apA  = (array) $do( 'POST', '/outreach/messages/' . $idA . '/approve' )->get_data();
    $body = (string) $messages->find( $idA )['body'];
    IT::check( true === ( $apA['ok'] ?? null ) && 1 === substr_count( $body, $new ) && 0 === substr_count( $body, $old ) && 1 === substr_count( $body, $unsub ), 'the blank-line edit: the whole footer replaced, the old address nowhere', wp_json_encode( $apA['violations'] ?? null ) );
    $apB = (array) $do( 'POST', '/outreach/messages/' . $idB . '/approve' )->get_data();
    $row = (array) $messages->find( $idB );
    IT::check( false === ( $apB['ok'] ?? null ) && in_array( OutreachEngine::FOOTER_EDIT_HINT, (array) ( $apB['violations'] ?? [] ), true ) && 'draft' === $row['status'] && $editB === (string) $row['body'], 'the "·" edit is refused with what to do, the body left as written', wp_json_encode( $apB['violations'] ?? null ) );
    $card = (array) ( $do( 'GET', '/outreach/campaigns/' . $cid )->get_data()['campaign']['next_step'] ?? [] );
    IT::check( \PPC\LinkGrowth\Outreach\CampaignFiller::needsEdit( (string) $row['last_error'] ) && ( $card['needs_edit'] ?? 0 ) >= 1, 'it counts as needing an edit, on the card too', wp_json_encode( $card ) );

    // 3. The send stage mails the approved one with the current footer only; the refused one stays behind.
    $sent = $engine->sendDue( TimeBudget::seconds( 20.0 ), 20 );
    $mA   = $mailTo( 'editor@frr-blank.test' );
    IT::check( 1 === count( $mA ) && 1 === substr_count( (string) $mA[0]['message'], $new ) && 0 === substr_count( (string) $mA[0]['message'], $old ) && 1 === substr_count( (string) $mA[0]['message'], $unsub ), 'the edited footer went out with the current address only', wp_json_encode( $sent ) );
    IT::check( [] === $mailTo( 'editor@frr-dash.test' ) && 'draft' === (string) $messages->find( $idB )['status'], 'nothing went to the site whose footer could not be updated' );

    // Clean up: settings back.
    $settings->update( [
        'automation' => [ 'outreach_autosend' => (bool) $saved['automation']['outreach_autosend'], 'daily_limits' => $saved['automation']['daily_limits'], 'weekly_limits' => $saved['automation']['weekly_limits'] ],
        'outreach'   => [ 'postal_address' => (string) $saved['outreach']['postal_address'], 'require_approval' => (bool) $saved['outreach']['require_approval'], 'send_window_start' => (int) $saved['outreach']['send_window_start'], 'send_window_end' => (int) $saved['outreach']['send_window_end'], 'send_days' => $saved['outreach']['send_days'] ],
    ] );
    IT::check( (string) $saved['outreach']['postal_address'] === (string) $settings->get( 'outreach.postal_address' ), 'settings restored' );
} );
