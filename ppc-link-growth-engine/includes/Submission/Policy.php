<?php
/**
 * Authorization policy for automated submissions.
 *
 * A submission may run automatically only when every gate passes: the automation master switch,
 * per-prospect authorization (or a configured partner API; a site set to "never automate" is refused
 * in every mode), an automatable listing form (POST, no CAPTCHA, no password, no required upload, never
 * a blog comment or forum form), robots.txt permission for the action URL, daily and
 * weekly limits (checked when sending; packaging/approval may skip them so the item waits in the
 * queue), no submission already sent to the same target or site, no required field left unmapped,
 * package text that passes the claims filter and a storefront that does not contradict the
 * research-use-only positioning (Compliance\StorefrontAudit; a hard blocker with the code
 * "storefront_inconsistent" — the item becomes an owner task, manual packaging keeps working).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Submission;

use PPC\LinkGrowth\Analysis\FormDetector;
use PPC\LinkGrowth\Compliance\StorefrontAudit;
use PPC\LinkGrowth\Plugin;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Support\Http\RobotsTxt;
use PPC\LinkGrowth\Support\Json;
use PPC\LinkGrowth\Support\Limits;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Url;
use Throwable;

final class Policy {

    public const MODE_AUTO   = 'auto';
    public const MODE_MANUAL = 'manual';

    public const SUBMISSION_MODES = [ 'auto', 'authorized_only', 'manual' ];

    /** Machine code of the storefront compliance blocker (listed in evaluate()['codes']). */
    public const REASON_STOREFRONT = 'storefront_inconsistent';

    /** Reason used when the storefront check cannot run (fail closed). */
    private const STOREFRONT_UNAVAILABLE = 'The storefront compliance check could not run, so automated submissions are paused until it does (manual packaging and owner tasks keep working; check the plugin logs).';

    private const API_METHODS = [ 'POST', 'PUT', 'PATCH' ];

    private const COMMENT_REFUSAL = 'The detected form is a blog comment / forum reply form (comment and forum posting is never automated).';

    /** Form action paths of comment, reply and forum endpoints. */
    private const COMMENT_ACTION_RE = '/wp-comments-post|\/comments?\/|reply|bbp-|forum/i';

    /** Field names used by comment and forum reply forms. */
    private const COMMENT_FIELD_RE = '/^(comment|comment_post_id|comment_parent)$|wc_comment|bbp_|reply_content/i';

    /** Hidden values that mark a bbPress reply/topic form. */
    private const COMMENT_HIDDEN_RE = '/bbp-new-(reply|topic)/i';

    public function __construct(
        private Settings $settings,
        private RobotsTxt $robots,
        private Limits $limits,
        private Submissions $submissions,
        private ?StorefrontAudit $storefront = null
    ) {}

    /**
     * Evaluate every authorization gate.
     *
     * @param array<string,mixed>      $prospect      Prospect row.
     * @param array<string,mixed>      $payload       Package from PackageBuilder (uses unmapped_required, fields).
     * @param array<string,mixed>|null $form          Detected form (full or compact) or null.
     * @param string                   $targetUrl     Submission target page.
     * @param callable|null            $robotsFetcher fn(string $url): Response — robots check is skipped when null.
     * @param bool                     $enforceLimits False when deciding whether a package may be queued: the queue
     *                                                processor checks the daily/weekly limits before every send, so a
     *                                                package created while the limit is used up waits in the queue
     *                                                instead of being parked in review.
     * @return array{allowed:bool,mode:string,reasons:string[],hard:bool,codes:string[]} "hard" is true when a structural
     *                                                                     blocker (form, CAPTCHA, robots, unmapped field,
     *                                                                     storefront…) exists, false when only
     *                                                                     authorization/limits block it. "codes" lists
     *                                                                     machine codes of coded reasons
     *                                                                     (REASON_STOREFRONT).
     */
    public function evaluate( array $prospect, array $payload, ?array $form, string $targetUrl, ?callable $robotsFetcher = null, bool $enforceLimits = true ): array {
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

        // 2. Authorization. In "auto" mode a high-quality prospect is authorised by itself; the
        // structural gates below (listing form, no CAPTCHA/login, robots.txt, mappable fields)
        // still decide whether the destination actually permits an automated submission.
        // A site set to "never automate" is refused in every mode, partner API included.
        $mode    = self::submissionMode( $this->settings );
        $perSite = Prospects::automationSetting( $prospect );
        if ( Prospects::AUTOMATION_NEVER === $perSite ) {
            $add( 'This site is set to "Never automate": submissions to it are always prepared for a person (Prospects → Automation).', true );
        } elseif ( Prospects::AUTOMATION_ALWAYS !== $perSite && null === $api ) {
            if ( 'manual' === $mode ) {
                $add( 'Submission mode is "manual": every submission is prepared for a person (Settings → Automation).', false );
            } elseif ( 'authorized_only' === $mode ) {
                $add( 'This prospect is not authorised for automated submission (set its per-site automation to "Always allow" or configure a partner API).', false );
            } else {
                $minScore = (int) $this->settings->get( 'automation.min_score_auto_queue', 70 );
                $score    = isset( $prospect['score'] ) && null !== $prospect['score'] ? (int) $prospect['score'] : null;
                if ( null === $score || $score < $minScore ) {
                    $add( sprintf( 'Auto mode only submits to sites scoring at least %d (this one: %s); set its per-site automation to "Always allow" to override.', $minScore, null === $score ? 'not yet scored' : (string) $score ), false );
                }
            }
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
        if ( $enforceLimits && ! $this->limits->allow( 'submissions' ) ) {
            $add( 'The daily or weekly submission limit has been reached.', false );
        }

        // 7. Duplicate: a submission to this target (or another page of the same site) already went out.
        // The row for this target is usually the one being evaluated, so "sent" is judged from submitted_at
        // as well as the status: a sent submission that was later rejected/failed must never be posted again.
        $pid      = (int) ( $prospect['id'] ?? 0 );
        $existing = '' !== $target ? $this->submissions->existsForTarget( $pid, $target ) : null;
        if ( null !== $existing && self::alreadySent( $existing ) ) {
            $add( sprintf( 'A submission to this target was already sent (#%d, %s); sending it again would post a duplicate.', (int) $existing['id'], (string) $existing['status'] ), true );
        }
        if ( $pid > 0 ) {
            foreach ( $this->submissions->findBy( [ 'prospect_id' => $pid ], 'id', 'ASC', 200 ) as $row ) {
                if ( null !== $existing && (int) $row['id'] === (int) $existing['id'] ) {
                    continue;
                }
                if ( self::alreadySent( $row ) ) {
                    $add( sprintf( 'A listing was already submitted to this site (#%d, %s); a second automated submission would be a duplicate.', (int) $row['id'], (string) $row['status'] ), true );
                    break;
                }
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

        // 9. Claims compliance: text the claims filter flags is never approved or posted automatically.
        $compliance = is_array( $payload['compliance'] ?? null ) ? $payload['compliance'] : [];
        if ( array_key_exists( 'clean', $compliance ) && ! $compliance['clean'] ) {
            $count = is_array( $compliance['violations'] ?? null ) ? count( $compliance['violations'] ) : 0;
            $add( sprintf( 'The package text fails the claims compliance check (%d issue(s)); edit the flagged text before it can be submitted.', max( 1, $count ) ), true );
        }

        // 10. Storefront compliance: while the storefront presents the products for consumer use (and the owner
        // has not acknowledged those exact findings), nothing is submitted automatically. evaluate() only decides
        // automation, so packaging continues and the item becomes an owner task.
        $codes      = [];
        $storefront = $this->storefrontBlocker();
        if ( '' !== $storefront ) {
            $add( $storefront, true );
            $codes[] = self::REASON_STOREFRONT;
        }

        $allowed = [] === $reasons;
        return [
            'allowed' => $allowed,
            'mode'    => $allowed ? self::MODE_AUTO : self::MODE_MANUAL,
            'reasons' => $reasons,
            'hard'    => $hard,
            'codes'   => $codes,
        ];
    }

    /**
     * The storefront blocker sentence, or '' when automated submissions may proceed. Fails closed (and logs)
     * when the storefront check cannot be constructed or cannot run.
     */
    private function storefrontBlocker(): string {
        try {
            $audit = $this->storefront ?? Plugin::instance()->get( StorefrontAudit::class );
            if ( ! $audit instanceof StorefrontAudit ) {
                throw new \RuntimeException( 'unexpected service type' );
            }
            $this->storefront = $audit;
            if ( ! $audit->isBlocking() ) {
                return '';
            }
            $why = $audit->blockingReason();
            return '' !== $why ? $why : self::STOREFRONT_UNAVAILABLE;
        } catch ( Throwable $e ) {
            try {
                Plugin::instance()->get( Logger::class )->error( 'compliance', 'Storefront compliance check unavailable; automated submissions are paused (fail closed): ' . $e->getMessage() );
            } catch ( Throwable $ignored ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[ppclg][error][compliance] Storefront compliance check unavailable; automated submissions are paused: ' . $e->getMessage() );
            }
            return self::STOREFRONT_UNAVAILABLE;
        }
    }

    /**
     * True when the submission was already sent to the destination (by an adapter or by hand), whatever
     * its current status: re-sending it would post the same listing twice.
     *
     * @param array<string,mixed> $sub
     */
    public static function alreadySent( array $sub ): bool {
        if ( in_array( (string) ( $sub['status'] ?? '' ), [ Submissions::STATUS_SUBMITTED, Submissions::STATUS_CONFIRMED ], true ) ) {
            return true;
        }
        $at = trim( (string) ( $sub['submitted_at'] ?? '' ) );
        return '' !== $at && ! str_starts_with( $at, '0000-00-00' );
    }

    /** The configured submission mode, normalised to a known value. */
    public static function submissionMode( Settings $settings ): string {
        $mode = strtolower( trim( (string) $settings->get( 'automation.submission_mode', 'auto' ) ) );
        return in_array( $mode, self::SUBMISSION_MODES, true ) ? $mode : 'auto';
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
        if ( FormDetector::KIND_COMMENT === $kind ) {
            $out[] = self::COMMENT_REFUSAL;
        } elseif ( FormDetector::KIND_LISTING !== $kind ) {
            $out[] = sprintf( 'The detected form is a "%s" form, not a listing submission form.', '' !== $kind ? str_replace( '_', ' ', $kind ) : 'unknown' );
        }
        // Comment and forum posting is refused from the form's own markers too, whatever kind
        // FormDetector assigned to it.
        if ( self::isCommentOrForumForm( $form, $payload ) ) {
            $out[] = self::COMMENT_REFUSAL;
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
     * True when the form posts to a comment/reply/forum endpoint, carries comment or forum field names,
     * or a hidden bbPress reply/topic marker.
     *
     * @param array<string,mixed> $form
     * @param array<string,mixed> $payload
     */
    public static function isCommentOrForumForm( array $form, array $payload = [] ): bool {
        $action = trim( (string) ( $form['action'] ?? '' ) );
        if ( '' === $action ) {
            $action = trim( (string) ( $form['page_url'] ?? '' ) ); // An empty action posts to the page itself.
        }
        if ( '' !== $action ) {
            $parts = wp_parse_url( $action );
            $path  = is_array( $parts ) ? (string) ( $parts['path'] ?? '' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) : $action;
            if ( '' !== $path && preg_match( self::COMMENT_ACTION_RE, $path ) ) {
                return true;
            }
        }
        $fields = array_merge(
            is_array( $form['fields'] ?? null ) ? $form['fields'] : [],
            is_array( $payload['fields'] ?? null ) ? $payload['fields'] : []
        );
        foreach ( $fields as $f ) {
            if ( ! is_array( $f ) ) {
                continue;
            }
            $name = trim( (string) ( $f['name'] ?? '' ) );
            if ( '' !== $name && preg_match( self::COMMENT_FIELD_RE, $name ) ) {
                return true;
            }
            if ( 'hidden' === strtolower( (string) ( $f['type'] ?? '' ) ) && is_scalar( $f['value'] ?? null ) && preg_match( self::COMMENT_HIDDEN_RE, (string) $f['value'] ) ) {
                return true;
            }
        }
        return false;
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
