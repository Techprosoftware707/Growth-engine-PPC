<?php
/**
 * Authorization policy for automated submissions.
 *
 * A submission may run automatically only when every gate passes: the automation master switch,
 * per-prospect authorization (or a configured partner API), an automatable listing form (POST, no
 * CAPTCHA, no password, no required upload), robots.txt permission for the action URL, daily and
 * weekly limits, no duplicate submission to the same target and no required field left unmapped.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Submission;

use PPC\LinkGrowth\Analysis\FormDetector;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Support\Http\RobotsTxt;
use PPC\LinkGrowth\Support\Json;
use PPC\LinkGrowth\Support\Limits;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Url;
use Throwable;

final class Policy {

    public const MODE_AUTO   = 'auto';
    public const MODE_MANUAL = 'manual';

    private const API_METHODS = [ 'POST', 'PUT', 'PATCH' ];

    public function __construct(
        private Settings $settings,
        private RobotsTxt $robots,
        private Limits $limits,
        private Submissions $submissions
    ) {}

    /**
     * Evaluate every authorization gate.
     *
     * @param array<string,mixed>      $prospect      Prospect row.
     * @param array<string,mixed>      $payload       Package from PackageBuilder (uses unmapped_required, fields).
     * @param array<string,mixed>|null $form          Detected form (full or compact) or null.
     * @param string                   $targetUrl     Submission target page.
     * @param callable|null            $robotsFetcher fn(string $url): Response — robots check is skipped when null.
     * @return array{allowed:bool,mode:string,reasons:string[],hard:bool} "hard" is true when a structural blocker
     *                                                                     (form, CAPTCHA, robots, unmapped field…) exists,
     *                                                                     false when only authorization/limits block it.
     */
    public function evaluate( array $prospect, array $payload, ?array $form, string $targetUrl, ?callable $robotsFetcher = null ): array {
        $reasons = [];
        $hard    = false;
        $add     = static function ( string $reason, bool $isHard ) use ( &$reasons, &$hard ): void {
            if ( ! in_array( $reason, $reasons, true ) ) {
                $reasons[] = $reason;
            }
            if ( $isHard ) {
                $hard = true;
            }
        };

        $api = self::apiConfig( $prospect );

        // 1. Master switch.
        if ( ! (bool) $this->settings->get( 'automation.submissions', false ) ) {
            $add( 'Automated submissions are switched off (Automation → Submissions).', false );
        }

        // 2. Per-prospect authorization, or a configured partner API.
        if ( 1 !== (int) ( $prospect['automation_allowed'] ?? 0 ) && null === $api ) {
            $add( 'This prospect is not authorised for automated submission (enable "automation allowed" or configure a partner API).', false );
        }

        // 3. Target.
        $target = Url::normalize( $targetUrl );
        if ( '' === $target ) {
            $add( 'The target URL is missing or invalid.', true );
        }

        // 4. Channel: partner API or an automatable listing form.
        if ( null !== $api ) {
            if ( ! self::validEndpoint( $api['endpoint'] ) ) {
                $add( 'The partner API endpoint must be a valid public https:// URL.', true );
            }
        } else {
            foreach ( $this->formBlockers( $form, $payload ) as $blocker ) {
                $add( $blocker, true );
            }
            // 5. robots.txt for the action URL (only when a fetcher is supplied).
            if ( ! $hard && null !== $robotsFetcher ) {
                $action = Url::normalize( (string) ( $form['action'] ?? '' ) ) ?: $target;
                if ( '' !== $action && ! $this->robotsAllow( $action, $robotsFetcher ) ) {
                    $add( sprintf( 'robots.txt disallows automated requests to %s.', $action ), true );
                }
            }
        }

        // 6. Limits.
        if ( ! $this->limits->allow( 'submissions' ) ) {
            $add( 'The daily or weekly submission limit has been reached.', false );
        }

        // 7. Duplicate: a submission to this target already went out.
        if ( '' !== $target ) {
            $existing = $this->submissions->existsForTarget( (int) ( $prospect['id'] ?? 0 ), $target );
            if ( $existing && in_array( (string) ( $existing['status'] ?? '' ), [ Submissions::STATUS_SUBMITTED, Submissions::STATUS_CONFIRMED ], true ) ) {
                $add( sprintf( 'A submission to this target already exists (#%d, %s).', (int) $existing['id'], (string) $existing['status'] ), true );
            }
        }

        // 8. Required fields the package could not fill.
        $unmapped = [];
        foreach ( (array) ( $payload['unmapped_required'] ?? [] ) as $u ) {
            $u = trim( (string) $u );
            if ( '' !== $u && ! in_array( $u, $unmapped, true ) ) {
                $unmapped[] = $u;
            }
        }
        if ( [] !== $unmapped ) {
            $add( 'Required fields cannot be filled automatically: ' . implode( ', ', $unmapped ) . '.', true );
        }

        $allowed = [] === $reasons;
        return [
            'allowed' => $allowed,
            'mode'    => $allowed ? self::MODE_AUTO : self::MODE_MANUAL,
            'reasons' => $reasons,
            'hard'    => $hard,
        ];
    }

    /**
     * Structural reasons a form cannot be submitted automatically (empty list = automatable).
     *
     * @param array<string,mixed>|null $form
     * @param array<string,mixed>      $payload
     * @return string[]
     */
    public function formBlockers( ?array $form, array $payload = [] ): array {
        if ( null === $form || [] === $form ) {
            return [ 'No submission form was detected on the target page.' ];
        }
        $out  = [];
        $kind = (string) ( $form['kind'] ?? '' );
        if ( FormDetector::KIND_LISTING !== $kind ) {
            $out[] = sprintf( 'The detected form is a "%s" form, not a listing submission form.', '' !== $kind ? str_replace( '_', ' ', $kind ) : 'unknown' );
        }
        if ( 'POST' !== strtoupper( (string) ( $form['method'] ?? 'GET' ) ) ) {
            $out[] = 'The form does not submit via POST.';
        }
        if ( ! empty( $form['has_captcha'] ) ) {
            $out[] = 'The form is protected by a CAPTCHA (never bypassed).';
        }
        if ( ! empty( $form['has_password'] ) ) {
            $out[] = 'The form requires a password or account login.';
        }
        $fields = is_array( $form['fields'] ?? null ) && [] !== $form['fields'] ? $form['fields'] : (array) ( $payload['fields'] ?? [] );
        foreach ( $fields as $f ) {
            if ( ! is_array( $f ) ) {
                continue;
            }
            $type     = strtolower( (string) ( $f['type'] ?? '' ) );
            $semantic = (string) ( $f['semantic'] ?? '' );
            if ( 'file' === $type && ! empty( $f['required'] ) ) {
                $out[] = 'The form requires a file upload.';
            }
            if ( 'password' === $type && empty( $form['has_password'] ) ) {
                $out[] = 'The form requires a password or account login.';
            }
            if ( 'captcha' === $semantic && empty( $form['has_captcha'] ) ) {
                $out[] = 'The form is protected by a CAPTCHA (never bypassed).';
            }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Normalised partner API configuration from prospect.submission_config.api, or null when none.
     *
     * @param array<string,mixed> $prospect
     * @return array{endpoint:string,method:string,headers:array<string,string>,body_template:string,success_path:string,listing_url_path:string,confirmation_path:string}|null
     */
    public static function apiConfig( array $prospect ): ?array {
        $cfg = $prospect['submission_config'] ?? null;
        if ( is_string( $cfg ) ) {
            $cfg = Json::decode( $cfg );
        }
        if ( ! is_array( $cfg ) || ! is_array( $cfg['api'] ?? null ) ) {
            return null;
        }
        $api      = $cfg['api'];
        $endpoint = trim( (string) ( $api['endpoint'] ?? '' ) );
        if ( '' === $endpoint ) {
            return null;
        }
        $headers = [];
        foreach ( (array) ( $api['headers'] ?? [] ) as $k => $v ) {
            $k = trim( (string) $k );
            if ( '' !== $k && is_scalar( $v ) && preg_match( '/^[A-Za-z0-9\-_]+$/', $k ) ) {
                $headers[ $k ] = (string) $v;
            }
        }
        $method = strtoupper( trim( (string) ( $api['method'] ?? 'POST' ) ) );
        if ( ! in_array( $method, self::API_METHODS, true ) ) {
            $method = 'POST';
        }
        $template = $api['body_template'] ?? '';
        if ( is_array( $template ) ) {
            $template = Json::encode( $template );
        }
        return [
            'endpoint'          => $endpoint,
            'method'            => $method,
            'headers'           => $headers,
            'body_template'     => is_string( $template ) ? $template : '',
            'success_path'      => trim( (string) ( $api['success_path'] ?? '' ) ),
            'listing_url_path'  => trim( (string) ( $api['listing_url_path'] ?? '' ) ),
            'confirmation_path' => trim( (string) ( $api['confirmation_path'] ?? '' ) ),
        ];
    }

    /** Public https endpoint (private/loopback hosts are refused via wp_http_validate_url). */
    public static function validEndpoint( string $url ): bool {
        $url = trim( $url );
        if ( ! preg_match( '#^https://[^\s/?\#]+#i', $url ) ) {
            return false;
        }
        if ( function_exists( 'wp_http_validate_url' ) ) {
            return false !== wp_http_validate_url( $url );
        }
        return false !== filter_var( $url, FILTER_VALIDATE_URL );
    }

    private function robotsAllow( string $url, callable $fetcher ): bool {
        try {
            return $this->robots->isAllowed( $url, $fetcher );
        } catch ( Throwable $e ) {
            return false; // Cannot verify => do not act automatically.
        }
    }
}
