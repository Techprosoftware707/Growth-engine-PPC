<?php
/**
 * RDAP lookup for domain registration dates (free, no API key).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Integrations;

use PPC\LinkGrowth\Support\Http\Client;

final class Rdap {

    public function __construct( private Client $client ) {}

    /** ISO date (Y-m-d) of registration or null. */
    public function registrationDate( string $domain ): ?string {
        $domain = strtolower( trim( $domain ) );
        if ( '' === $domain || filter_var( $domain, FILTER_VALIDATE_IP ) ) {
            return null;
        }
        $key    = 'ppclg_rdap_' . md5( $domain );
        $cached = get_transient( $key );
        if ( is_string( $cached ) ) {
            return '' === $cached ? null : $cached;
        }
        $r    = $this->client->api( 'GET', 'https://rdap.org/domain/' . rawurlencode( $domain ), [ 'Accept' => 'application/rdap+json, application/json' ], null, 12 );
        $date = null;
        if ( $r->ok() ) {
            foreach ( (array) ( $r->json()['events'] ?? [] ) as $ev ) {
                if ( isset( $ev['eventAction'], $ev['eventDate'] ) && 'registration' === $ev['eventAction'] ) {
                    $ts = strtotime( (string) $ev['eventDate'] );
                    if ( $ts ) {
                        $date = gmdate( 'Y-m-d', $ts );
                    }
                    break;
                }
            }
        }
        set_transient( $key, $date ?? '', $date ? 30 * DAY_IN_SECONDS : DAY_IN_SECONDS );
        return $date;
    }
}
