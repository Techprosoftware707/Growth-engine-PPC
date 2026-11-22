<?php
/**
 * Integration section (1.2.0): "we create a campaign and it says saved in draft but we don't see anything" and the
 * Autopilot switch, on real WordPress + SQLite. Required from run.php after the other sections; leaves Autopilot
 * off and the settings it changed restored. Offline: prospects use *.test hosts, DNS answers come from the
 * lga_mail_domain_dns filter, mail is captured by run.php's pre_wp_mail filter.
 */

declare(strict_types=1);

IT::step( 'Autopilot: campaigns fill at once, one switch approves and sends', static function (): void {
    global $wpdb;
    wp_set_current_user( 1 );
    $GLOBALS['itMail'] = [];
    $do = static function ( string $method, string $route, array $params = [] ) {
        $req = new WP_REST_Request( $method, '/lga/v1' . $route );
        foreach ( $params as $k => $v ) {
            $req->set_param( $k, $v );
        }
        if ( 'POST' === $method ) {
            $req->set_header( 'Content-Type', 'application/json' );
            $req->set_body( wp_json_encode( $params ) );
        }
        return rest_do_request( $req );
    };
    $settings = lga()->get( \LinkGrowth\Autopilot\Support\Settings::class );
    $saved    = $settings->all();
    $tzSaved  = [ get_option( 'timezone_string' ), get_option( 'gmt_offset' ) ];

    // The live conflict: autosend on, individual approval on, window open for the test, generous limits.
    $settings->update( [
        'automation' => [ 'outreach_autosend' => true, 'daily_limits' => [ 'outreach_emails' => 50 ], 'weekly_limits' => [ 'outreach_emails' => 200 ] ],
        'outreach'   => [ 'require_approval' => true, 'send_window_start' => 0, 'send_window_end' => 0, 'send_days' => [ 1, 2, 3, 4, 5, 6, 7 ], 'min_days_between_contacts' => 90 ],
    ] );
    $now  = gmdate( 'Y-m-d H:i:s' );
    $seed = static function ( string $domain, string $type ) use ( $wpdb, $now ): int {
        $wpdb->insert( $wpdb->prefix . 'lga_prospects', [ 'domain' => $domain, 'name' => $domain, 'status' => 'qualified', 'opportunity_type' => $type, 'score' => 82, 'discovered_via' => 'search', 'first_seen' => $now, 'updated_at' => $now ] );
        $id = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'lga_contacts', [ 'prospect_id' => $id, 'email' => 'editor@' . $domain, 'name' => 'Editor', 'role' => 'editor', 'confidence' => 80, 'consent_basis' => 'conspicuous_publication', 'status' => 'active', 'source_url' => 'https://' . $domain . '/contact/', 'created_at' => $now, 'updated_at' => $now ] );
        return $id;
    };
    $rpA = $seed( 'ap-resources-a.test', 'resource_page' );
    $rpB = $seed( 'ap-resources-b.test', 'resource_page' );

    // 1. Creating a campaign now shows real drafts and what happens next (Autopilot off).
    $create = $do( 'POST', '/outreach/campaigns', [ 'name' => 'Run 1', 'approach' => 'resource_page', 'settings' => [ 'daily_limit' => 5 ] ] );
    $data   = (array) $create->get_data();
    IT::check( 200 === $create->get_status(), 'POST /outreach/campaigns 200', wp_json_encode( $data ) );
    IT::check( 2 === ( $data['drafted'] ?? null ) && 2 === ( $data['eligible'] ?? null ), 'new campaign drafted for both matching resource-page prospects at once', wp_json_encode( [ $data['drafted'] ?? null, $data['eligible'] ?? null, $data['reasons'] ?? null ] ) );
    $messages = lga()->get( \LinkGrowth\Autopilot\Repositories\OutreachMessages::class );
    $mine     = $messages->findBy( [ 'campaign_id' => (int) $data['campaign_id'] ], 'id', 'ASC', 50 );
    IT::check( [ $rpA, $rpB ] === array_map( static fn( array $m ): int => (int) $m['prospect_id'], $mine ), 'only resource-page prospects were pitched (no partnership contact)' );
    IT::check( 'waiting' === ( $data['campaign']['next_step']['state'] ?? '' ) && 2 === ( $data['campaign']['next_step']['waiting'] ?? 0 ), 'card says the drafts wait for approval', wp_json_encode( $data['campaign']['next_step'] ?? null ) );
    $sender = (array) $do( 'GET', '/outreach/sender' )->get_data();
    IT::check( true === $sender['autosend'] && false === $sender['auto_approve'], 'sender endpoint reports that autosend + approval required approves nothing' );

    // 2. Autopilot needs confirmation, then approves everything that waits.
    $refused = $do( 'POST', '/automation/autopilot', [ 'enabled' => true ] );
    IT::check( 400 === $refused->get_status(), 'POST /automation/autopilot without confirm is refused (400)', (string) $refused->get_status() );
    $viaSettings = $do( 'POST', '/settings', [ 'settings' => [ 'automation' => [ 'autopilot' => true ] ] ] );
    IT::check( 400 === $viaSettings->get_status() && ! lga()->get( \LinkGrowth\Autopilot\Automation\Autopilot::class )->isOn(), 'POST /settings cannot switch Autopilot on without confirm' );
    $on = $do( 'POST', '/automation/autopilot', [ 'enabled' => true, 'confirm' => true ] );
    IT::check( 200 === $on->get_status() && true === ( $on->get_data()['enabled'] ?? null ), 'Autopilot switched on with confirm', wp_json_encode( $on->get_data()['consent'] ?? null ) );
    $consent = get_option( \LinkGrowth\Autopilot\Automation\Autopilot::OPTION_CONSENT );
    IT::check( is_array( $consent ) && 1 === (int) $consent['user_id'] && \LinkGrowth\Autopilot\Automation\Autopilot::POLICY_VERSION === $consent['policy_version'], 'consent recorded (user, time, policy version)' );
    $auditOn = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lga_audit_log WHERE action = 'autopilot.enabled' AND actor = 'user:1'" ); // phpcs:ignore
    IT::check( 1 <= $auditOn, 'consent written to the audit log' );

    IT::check( ( $on->get_data()['approved_now'] ?? 0 ) >= 2, 'switching Autopilot on approved the drafts that were waiting', wp_json_encode( $on->get_data()['approved_now'] ?? null ) );
    $run = lga()->get( \LinkGrowth\Autopilot\Automation\Pipeline::class )->runNow( 'auto_outreach', [], 25 );
    IT::check( ! empty( $run['result']['ok'] ), 'auto outreach stage runs under Autopilot', wp_json_encode( $run['result'] ?? null ) );
    $camp = lga()->get( \LinkGrowth\Autopilot\Repositories\Campaigns::class )->find( (int) $data['campaign_id'] );
    IT::check( 'approved' === $camp['status'], 'the owner campaign was approved by Autopilot', (string) $camp['status'] );

    // A partnership site found after that run goes straight into the owner's new campaign.
    $seed( 'ap-partner.test', 'partnership' );
    $second = (array) $do( 'POST', '/outreach/campaigns', [ 'name' => 'Partners', 'approach' => 'partnership', 'settings' => [ 'daily_limit' => 5 ] ] )->get_data();
    IT::check( 1 === ( $second['drafted'] ?? 0 ) && 1 === ( $second['approved'] ?? 0 ), 'a campaign created under Autopilot is approved with its draft at once', wp_json_encode( $second ) );

    $sent = lga()->get( \LinkGrowth\Autopilot\Outreach\OutreachEngine::class )->sendDue( \LinkGrowth\Autopilot\Support\TimeBudget::seconds( 20.0 ), 20 );
    IT::check( ( $sent['sent'] ?? 0 ) >= 3, 'the send stage mailed the approved first contacts', wp_json_encode( $sent ) );
    $to = array_map( static fn( array $m ): string => is_array( $m['to'] ) ? implode( ',', $m['to'] ) : (string) $m['to'], $GLOBALS['itMail'] );
    IT::check( [] === array_diff( [ 'editor@ap-resources-a.test', 'editor@ap-resources-b.test', 'editor@ap-partner.test' ], $to ), 'mail captured for each contact', wp_json_encode( $to ) );

    // 3. Sender on a parked domain pauses sending and suggests the site email.
    $dns = static fn( $r, string $domain ) => [ 'parked-sender.ca' => [ 'mx' => false, 'a' => false, 'spf' => false ], 'working-site.ca' => [ 'mx' => true, 'a' => true, 'spf' => true ] ][ $domain ] ?? $r;
    add_filter( 'lga_mail_domain_dns', $dns, 10, 2 );
    $settings->update( [ 'outreach' => [ 'sender_email' => 'hi@parked-sender.ca' ], 'site' => [ 'email' => 'hi@working-site.ca' ] ] );
    $parked = (array) $do( 'GET', '/outreach/sender' )->get_data();
    IT::check( false === $parked['ok'] && in_array( 'sender_email_domain', (array) $parked['missing'], true ), 'parked sender domain blocks sending', wp_json_encode( $parked['problems'] ?? null ) );
    IT::check( 'hi@working-site.ca' === ( $parked['suggestion']['sender_email'] ?? '' ), 'the site email is offered instead' );
    remove_filter( 'lga_mail_domain_dns', $dns, 10 );
    // A parked domain usually keeps a parking A record: that is not a mail server either.
    $parking = static fn( $r, string $domain ) => [ 'parking-a.ca' => [ 'mx' => false, 'a' => true, 'spf' => false ], 'working-site.ca' => [ 'mx' => true, 'a' => true, 'spf' => true ] ][ $domain ] ?? $r;
    add_filter( 'lga_mail_domain_dns', $parking, 10, 2 );
    $settings->update( [ 'outreach' => [ 'sender_email' => 'hi@parking-a.ca' ] ] );
    $parkingA = (array) $do( 'GET', '/outreach/sender' )->get_data();
    IT::check( false === $parkingA['ok'] && in_array( 'sender_email_domain', (array) $parkingA['missing'], true ) && 'hi@working-site.ca' === ( $parkingA['suggestion']['sender_email'] ?? '' ), 'a domain with only a parking A record (no MX) blocks sending too', wp_json_encode( $parkingA['problems'] ?? null ) );
    remove_filter( 'lga_mail_domain_dns', $parking, 10 );
    $settings->update( [ 'outreach' => [ 'sender_email' => (string) $saved['outreach']['sender_email'] ], 'site' => [ 'email' => (string) $saved['site']['email'] ] ] );

    // 4. WordPress left on UTC for a business in British Columbia: the window runs on Vancouver time (the region's zone).
    update_option( 'timezone_string', 'UTC' );
    update_option( 'gmt_offset', 0 );
    $settings->update( [ 'site' => [ 'country' => 'CA', 'region' => 'BC' ] ] );
    $zone = (array) ( $do( 'GET', '/outreach/sender' )->get_data()['send_window'] ?? [] );
    IT::check( 'America/Vancouver' === ( $zone['timezone'] ?? '' ) && 'region' === ( $zone['timezone_source'] ?? '' ), 'send window evaluated in America/Vancouver', wp_json_encode( $zone ) );
    update_option( 'timezone_string', (string) $tzSaved[0] );
    update_option( 'gmt_offset', $tzSaved[1] );

    // 5. Off again: what Autopilot approved but did not send returns to draft.
    $wpdb->insert( $wpdb->prefix . 'lga_prospects', [ 'domain' => 'ap-late.test', 'name' => 'late', 'status' => 'qualified', 'opportunity_type' => 'resource_page', 'score' => 80, 'discovered_via' => 'search', 'first_seen' => $now, 'updated_at' => $now ] );
    $late = (int) $wpdb->insert_id;
    $wpdb->insert( $wpdb->prefix . 'lga_contacts', [ 'prospect_id' => $late, 'email' => 'editor@ap-late.test', 'name' => 'E', 'role' => 'editor', 'confidence' => 80, 'consent_basis' => 'conspicuous_publication', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ] );
    $third = (array) $do( 'POST', '/outreach/campaigns', [ 'name' => 'Late', 'approach' => 'resource_page' ] )->get_data();
    IT::check( 1 === ( $third['approved'] ?? 0 ), 'one more draft approved by Autopilot', wp_json_encode( $third ) );
    $off = $do( 'POST', '/automation/autopilot', [ 'enabled' => false ] );
    IT::check( 200 === $off->get_status() && false === ( $off->get_data()['enabled'] ?? null ) && ( $off->get_data()['reverted'] ?? 0 ) >= 1, 'Autopilot off returned its unsent approvals to draft', wp_json_encode( $off->get_data()['reverted'] ?? null ) );
    $lateMsg = $messages->findOneBy( [ 'prospect_id' => $late ] );
    IT::check( null !== $lateMsg && 'draft' === $lateMsg['status'], 'the unsent message is a draft again' );
    $after = lga()->get( \LinkGrowth\Autopilot\Outreach\OutreachEngine::class )->sendDue( \LinkGrowth\Autopilot\Support\TimeBudget::seconds( 10.0 ), 20 );
    IT::check( 0 === ( $after['sent'] ?? -1 ), 'nothing more is sent once Autopilot is off', wp_json_encode( $after ) );

    $settings->update( [ 'automation' => [ 'outreach_autosend' => (bool) $saved['automation']['outreach_autosend'], 'daily_limits' => $saved['automation']['daily_limits'], 'weekly_limits' => $saved['automation']['weekly_limits'] ], 'outreach' => [ 'require_approval' => (bool) $saved['outreach']['require_approval'], 'send_window_start' => (int) $saved['outreach']['send_window_start'], 'send_window_end' => (int) $saved['outreach']['send_window_end'], 'send_days' => $saved['outreach']['send_days'] ], 'site' => [ 'country' => (string) $saved['site']['country'], 'region' => (string) $saved['site']['region'] ] ] );
    $errors = lga()->get( \LinkGrowth\Autopilot\Repositories\Logs::class )->findBy( [ 'level' => 'error', 'channel' => 'outreach' ], 'id', 'DESC', 5 );
    IT::check( [] === $errors, 'no outreach errors logged during the Autopilot section', wp_json_encode( array_column( $errors, 'message' ) ) );
} );
