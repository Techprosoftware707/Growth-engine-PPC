<?php
/**
 * Submits a listing through a site's own HTML form by an authorised HTTP POST.
 *
 * Runs only for prospects explicitly marked automation_allowed while the automation master switch
 * is on. It re-fetches the page, re-detects the form, stops (needs_manual) when a CAPTCHA, password
 * or required upload appears or a required field cannot be mapped, never ticks newsletter boxes,
 * honours robots.txt for the action URL and stores only a redacted, tag-stripped response excerpt.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Submission\Adapters;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PPC\LinkGrowth\Analysis\FormDetector;
use PPC\LinkGrowth\Analysis\PageAnalysis;
use PPC\LinkGrowth\Analysis\PageAnalyzer;
use PPC\LinkGrowth\Submission\PackageBuilder;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Http\Response;
use PPC\LinkGrowth\Support\Http\RobotsTxt;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Redactor;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\Str;
use PPC\LinkGrowth\Support\Url;
use Throwable;

final class GenericFormAdapter implements AdapterInterface {

    private const SUCCESS_MARKERS = [
        'thank you',
        'thanks for',
        'been received',
        'we have received',
        'we received',
        'received your',
        'successfully submitted',
        'submitted successfully',
        'successfully received',
        'successfully added',
        'submission received',
        'submission successful',
        'been submitted',
        'was submitted',
        'listing submitted',
        'request submitted',
        'pending review',
        'pending approval',
        'will be reviewed',
        'under review',
        'we will review',
        "we'll review",
        'awaiting approval',
        'awaiting moderation',
        'has been sent',
        'been added',
    ];

    private const ERROR_MARKERS = [
        'is required',
        'are required',
        'required field',
        'please fill',
        'please enter',
        'please complete',
        'please select',
        'invalid',
        'not valid',
        'try again',
        'captcha',
        'verification failed',
        'already exists',
        'already listed',
        'already been submitted',
        'could not be',
        'unable to',
        'failed to',
        'not allowed',
        'access denied',
        'forbidden',
        'flagged as spam',
        'too many requests',
    ];

    private const SUCCESS_URL  = '/thank|success|confirm|submitted|complete|received/i';
    private const CAPTCHA_HTML = '/g-recaptcha|grecaptcha|hcaptcha|h-captcha|turnstile|data-sitekey|cf-challenge/i';
    private const MAX_EXCERPT  = 2000;

    public function __construct(
        private Client $client,
        private PageAnalyzer $analyzer,
        private RobotsTxt $robots,
        private Settings $settings,
        private Logger $logger
    ) {}

    public function id(): string {
        return 'generic_form';
    }

    public function label(): string {
        return 'Listing form (authorised HTTP POST)';
    }

    /** @inheritDoc */
    public function supports( array $prospect, array $payload ): bool {
        $form = $payload['form'] ?? null;
        if ( ! is_array( $form ) ) {
            return false;
        }
        return FormDetector::KIND_LISTING === (string) ( $form['kind'] ?? '' )
            && 'POST' === strtoupper( (string) ( $form['method'] ?? '' ) )
            && ! empty( $payload['fields'] );
    }

    /** @inheritDoc */
    public function submit( array $submission, array $prospect, array $payload ): array {
        $sid = (int) ( $submission['id'] ?? 0 );
        try {
            return $this->run( $sid, $submission, $prospect, $payload );
        } catch ( Throwable $e ) {
            $this->logger->error( 'submission', 'Generic form adapter error', [ 'submission_id' => $sid, 'error' => $e->getMessage() ] );
            return $this->failed( 'Adapter error: ' . $e->getMessage(), false );
        }
    }

    // ------------------------------------------------------------------
    // Flow
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $submission
     * @param array<string,mixed> $prospect
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function run( int $sid, array $submission, array $prospect, array $payload ): array {
        // Authorization gates (defence in depth; the engine evaluates Policy first).
        if ( ! (bool) $this->settings->get( 'automation.submissions', false ) ) {
            return $this->manual( 'Automated submissions are switched off.' );
        }
        if ( 1 !== (int) ( $prospect['automation_allowed'] ?? 0 ) ) {
            return $this->manual( 'This prospect is not authorised for automated form submission.' );
        }

        $summary = is_array( $payload['form'] ?? null ) ? $payload['form'] : [];
        $pageUrl = Url::normalize( (string) ( $summary['page_url'] ?? '' ) ) ?: Url::normalize( (string) ( $submission['target_url'] ?? '' ) );
        if ( '' === $pageUrl ) {
            return $this->failed( 'No form page URL is available for this submission.', false );
        }

        // 1. Re-fetch the page (robots-aware, throttled).
        $resp = $this->client->crawl( $pageUrl, [ 'cache' => false, 'timeout' => 20 ] );
        if ( $resp->failed() ) {
            if ( 'robots_disallow' === $resp->errorCode ) {
                return $this->failed( 'robots.txt disallows fetching the form page.', false, [ 'http_status' => $resp->status ] );
            }
            return $this->failed( sprintf( 'Could not load the form page (%s).', $resp->error ?: 'HTTP ' . $resp->status ), $this->isTransient( $resp ), [ 'http_status' => $resp->status ] );
        }
        if ( ! $resp->isHtml() ) {
            return $this->manual( 'The form page is no longer an HTML document.' );
        }

        // 2. Re-detect the same form.
        $page = $this->analyzer->fromResponse( $resp );
        [ $form, $index ] = $this->locateForm( $page, $summary );
        if ( null === $form ) {
            return $this->manual( 'The submission form could not be found on the page any more.' );
        }
        if ( ! empty( $form['has_captcha'] ) || preg_match( self::CAPTCHA_HTML, $resp->body ) ) {
            return $this->manual( 'A CAPTCHA protects this form; it is never bypassed.' );
        }
        if ( ! empty( $form['has_password'] ) ) {
            return $this->manual( 'The form now requires a password or account.' );
        }
        if ( 'POST' !== strtoupper( (string) ( $form['method'] ?? '' ) ) ) {
            return $this->manual( 'The form does not submit via POST.' );
        }
        $action = Url::normalize( (string) ( $form['action'] ?? '' ) ) ?: ( $page->finalUrl ?: $pageUrl );
        if ( ! Url::isSameSite( $action, $pageUrl ) ) {
            return $this->manual( sprintf( 'The form posts to another site (%s); manual review required.', Url::host( $action ) ) );
        }
        if ( ! $this->robotsAllow( $action ) ) {
            return $this->failed( 'robots.txt disallows posting to the form action URL.', false );
        }

        // 3. Build the POST body from the live form + package.
        $dom      = $this->inspectForm( $resp->body, $index );
        $problems = [];
        $body     = $this->buildBody( $form, $payload, $dom, $problems );
        if ( [] !== $problems ) {
            return $this->manual( 'The live form needs a person: ' . implode( '; ', array_unique( $problems ) ) . '.' );
        }
        if ( [] === $body ) {
            return $this->manual( 'No package field could be mapped onto the live form.' );
        }

        // 4. Post.
        $referer = $page->finalUrl ?: $pageUrl;
        $origin  = (string) ( wp_parse_url( $referer, PHP_URL_SCHEME ) ?: 'https' ) . '://' . Url::host( $referer );
        $post    = $this->client->postForm( $action, $body, [ 'Referer' => $referer, 'Origin' => $origin ], $resp->cookies );
        $this->logger->info( 'submission', 'Listing form posted', [ 'submission_id' => $sid, 'host' => Url::host( $action ), 'http_status' => $post->status, 'fields' => count( $body ) ] );

        return $this->interpret( $post, $form, $body );
    }

    /**
     * @param array<string,mixed> $summary Stored form summary (action/id) from the package.
     * @return array{0:?array<string,mixed>,1:int}
     */
    private function locateForm( PageAnalysis $page, array $summary ): array {
        $action     = Url::normalize( (string) ( $summary['action'] ?? '' ) );
        $id         = (string) ( $summary['id'] ?? '' );
        $listing    = null;
        $listingIdx = -1;
        $listings   = 0;
        foreach ( $page->forms as $i => $f ) {
            if ( ! is_array( $f ) ) {
                continue;
            }
            if ( '' !== $action && Url::normalize( (string) ( $f['action'] ?? '' ) ) === $action ) {
                return [ $f, (int) $i ];
            }
            if ( '' !== $id && $id === (string) ( $f['id'] ?? '' ) ) {
                return [ $f, (int) $i ];
            }
            if ( FormDetector::KIND_LISTING === ( $f['kind'] ?? '' ) ) {
                $listings++;
                if ( null === $listing ) {
                    $listing    = $f;
                    $listingIdx = (int) $i;
                }
            }
        }
        // Fallback: the only listing form on the page (action URLs may carry rotating tokens).
        if ( null !== $listing && 1 === $listings ) {
            return [ $listing, $listingIdx ];
        }
        return [ null, -1 ];
    }

    /**
     * Reads checkbox/radio values and the submit control of the n-th form straight from the DOM
     * (FormDetector does not keep them).
     *
     * @return array{checkbox:array<string,string>,radio:array<string,string[]>,submit:array<string,string>}
     */
    private function inspectForm( string $html, int $index ): array {
        $out = [ 'checkbox' => [], 'radio' => [], 'submit' => [] ];
        if ( $index < 0 || '' === trim( $html ) ) {
            return $out;
        }
        try {
            $dom  = new DOMDocument();
            $prev = libxml_use_internal_errors( true );
            $dom->loadHTML( '<?xml encoding="UTF-8">' . ( preg_replace( '/<\?xml[^>]*\?>/i', '', $html ) ?? $html ), LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET | LIBXML_COMPACT );
            libxml_clear_errors();
            libxml_use_internal_errors( $prev );
            $xp   = new DOMXPath( $dom );
            $node = $xp->query( '//form' )->item( $index );
            if ( ! $node instanceof DOMElement ) {
                return $out;
            }
            foreach ( $xp->query( './/input', $node ) as $el ) {
                if ( ! $el instanceof DOMElement ) {
                    continue;
                }
                $type = strtolower( $el->getAttribute( 'type' ) ?: 'text' );
                $name = $el->getAttribute( 'name' );
                if ( '' === $name ) {
                    continue;
                }
                if ( 'checkbox' === $type ) {
                    if ( ! isset( $out['checkbox'][ $name ] ) ) {
                        $out['checkbox'][ $name ] = $el->hasAttribute( 'value' ) ? $el->getAttribute( 'value' ) : 'on';
                    }
                } elseif ( 'radio' === $type ) {
                    $out['radio'][ $name ][] = $el->getAttribute( 'value' );
                } elseif ( in_array( $type, [ 'submit', 'image' ], true ) && [] === $out['submit'] ) {
                    $out['submit'][ $name ] = $el->getAttribute( 'value' );
                }
            }
            if ( [] === $out['submit'] ) {
                foreach ( $xp->query( './/button', $node ) as $el ) {
                    if ( ! $el instanceof DOMElement ) {
                        continue;
                    }
                    $type = strtolower( $el->getAttribute( 'type' ) ?: 'submit' );
                    $name = $el->getAttribute( 'name' );
                    if ( 'submit' === $type && '' !== $name ) {
                        $out['submit'][ $name ] = $el->getAttribute( 'value' );
                        break;
                    }
                }
            }
        } catch ( Throwable $e ) {
            // Best effort only.
        }
        return $out;
    }

    /**
     * @param array<string,mixed>                                                                  $form     Live form.
     * @param array<string,mixed>                                                                  $payload  Package.
     * @param array{checkbox:array<string,string>,radio:array<string,string[]>,submit:array<string,string>} $dom
     * @param string[]                                                                             $problems
     * @return array<string,string>
     */
    private function buildBody( array $form, array $payload, array $dom, array &$problems ): array {
        $mapped = [];
        foreach ( (array) ( $payload['fields'] ?? [] ) as $f ) {
            if ( is_array( $f ) && '' !== (string) ( $f['name'] ?? '' ) ) {
                $mapped[ (string) $f['name'] ] = $f;
            }
        }
        $business = (array) ( $payload['business'] ?? [] );
        $content  = (array) ( $payload['content'] ?? [] );
        $body     = [];

        foreach ( (array) ( $form['fields'] ?? [] ) as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $name     = (string) ( $field['name'] ?? '' );
            $type     = strtolower( (string) ( $field['type'] ?? 'text' ) ) ?: 'text';
            $semantic = (string) ( $field['semantic'] ?? 'unknown' );
            $required = ! empty( $field['required'] );
            $label    = trim( (string) ( $field['label'] ?? '' ) );
            $display  = '' !== $label ? $label : $name;
            if ( '' === $name ) {
                if ( $required && 'hidden' !== $type ) {
                    $problems[] = sprintf( 'required field "%s" has no name attribute', $display );
                }
                continue;
            }
            $m  = $mapped[ $name ] ?? null;
            $mv = null !== $m ? (string) ( $m['value'] ?? '' ) : null;

            switch ( true ) {
                case 'hidden' === $type:
                    $body[ $name ] = (string) ( $field['value'] ?? '' );
                    break;

                case 'file' === $type:
                    if ( $required ) {
                        $problems[] = sprintf( 'required file upload "%s"', $display );
                    }
                    break;

                case 'password' === $type || 'password' === $semantic:
                    $problems[] = sprintf( 'password field "%s"', $display );
                    break;

                case 'captcha' === $semantic:
                    $problems[] = 'CAPTCHA field';
                    break;

                case 'checkbox' === $type:
                    if ( $this->shouldTick( $semantic, $label, $mv ) ) {
                        $body[ $name ] = $dom['checkbox'][ $name ] ?? 'on';
                    } elseif ( $required ) {
                        $problems[] = sprintf( 'required checkbox "%s" cannot be ticked automatically', $display );
                    }
                    break;

                case 'radio' === $type:
                    $choice = $this->radioChoice( $semantic, $dom['radio'][ $name ] ?? [], $mv, $business );
                    if ( null !== $choice ) {
                        $body[ $name ] = $choice;
                    } elseif ( $required ) {
                        $problems[] = sprintf( 'required choice "%s" has no safe default', $display );
                    }
                    break;

                case 'select' === $type:
                    $options = is_array( $field['options'] ?? null ) ? $field['options'] : [];
                    $choice  = $this->selectChoice( $semantic, $options, $m, $mv, $business );
                    if ( null !== $choice ) {
                        $body[ $name ] = $choice;
                    } elseif ( $required ) {
                        $problems[] = sprintf( 'required selection "%s" has no matching option', $display );
                    }
                    break;

                default:
                    $value = $mv ?? $this->fallbackValue( $semantic, $business, $content );
                    $value = trim( (string) $value );
                    if ( '' === $value ) {
                        if ( $required ) {
                            $problems[] = sprintf( 'required field "%s" has no value in the package', $display );
                        }
                        break;
                    }
                    $max = (int) ( $field['maxlength'] ?? 0 );
                    if ( $max > 0 && mb_strlen( $value ) > $max ) {
                        $value = Str::truncate( $value, $max, '' );
                    }
                    $body[ $name ] = $value;
            }
        }

        // Many PHP back-ends check isset($_POST['submit']); send the first submit control's name/value.
        foreach ( $dom['submit'] as $n => $v ) {
            if ( '' !== $n && ! isset( $body[ $n ] ) ) {
                $body[ $n ] = (string) $v;
                break;
            }
        }
        return $body;
    }

    /** Consent boxes are decided from the live label: terms/privacy/accuracy yes, newsletters never. */
    private function shouldTick( string $semantic, string $label, ?string $mapped ): bool {
        if ( preg_match( PackageBuilder::CONSENT_DENY, $label ) ) {
            return false;
        }
        if ( 'consent' === $semantic ) {
            return (bool) preg_match( PackageBuilder::CONSENT_ALLOW, $label );
        }
        if ( 'category' === $semantic ) {
            return null !== PackageBuilder::matchOption( [ [ 'value' => '1', 'text' => $label ] ], PackageBuilder::CATEGORY_TERMS ) || '1' === $mapped;
        }
        return null !== $mapped && in_array( strtolower( $mapped ), [ '1', 'on', 'yes', 'true' ], true );
    }

    /**
     * @param string[]            $values
     * @param array<string,mixed> $business
     */
    private function radioChoice( string $semantic, array $values, ?string $mapped, array $business ): ?string {
        if ( [] === $values ) {
            return null;
        }
        if ( null !== $mapped && '' !== $mapped && in_array( $mapped, $values, true ) ) {
            return $mapped;
        }
        $terms = match ( $semantic ) {
            'country' => PackageBuilder::countryNames( (string) ( $business['country'] ?? '' ) ),
            'region'  => PackageBuilder::regionNames( (string) ( $business['region'] ?? '' ) ),
            default   => [],
        };
        if ( [] === $terms ) {
            return null;
        }
        $options = array_map( static fn( string $v ): array => [ 'value' => $v, 'text' => $v ], $values );
        $hit     = PackageBuilder::matchOption( $options, $terms );
        return null === $hit ? null : $hit['value'];
    }

    /**
     * @param array<int,mixed>         $options
     * @param array<string,mixed>|null $mappedField
     * @param array<string,mixed>      $business
     */
    private function selectChoice( string $semantic, array $options, ?array $mappedField, ?string $mapped, array $business ): ?string {
        $values = [];
        $byText = [];
        foreach ( $options as $o ) {
            if ( ! is_array( $o ) ) {
                continue;
            }
            $text  = trim( (string) ( $o['text'] ?? '' ) );
            $value = trim( (string) ( $o['value'] ?? '' ) );
            $v     = '' !== $value ? $value : $text;
            if ( '' === $v ) {
                continue;
            }
            $values[] = $v;
            if ( '' !== $text ) {
                $byText[ mb_strtolower( $text ) ] = $v;
            }
        }
        if ( [] === $values ) {
            return null;
        }
        if ( null !== $mappedField && is_array( $mappedField['matched_option'] ?? null ) ) {
            $v = (string) ( $mappedField['matched_option']['value'] ?? '' );
            if ( '' !== $v && in_array( $v, $values, true ) ) {
                return $v;
            }
        }
        if ( null !== $mapped && '' !== $mapped ) {
            if ( in_array( $mapped, $values, true ) ) {
                return $mapped;
            }
            if ( isset( $byText[ mb_strtolower( $mapped ) ] ) ) {
                return $byText[ mb_strtolower( $mapped ) ];
            }
        }
        $terms = match ( $semantic ) {
            'category' => PackageBuilder::CATEGORY_TERMS,
            'country'  => PackageBuilder::countryNames( (string) ( $business['country'] ?? '' ) ),
            'region'   => PackageBuilder::regionNames( (string) ( $business['region'] ?? '' ) ),
            'founded'  => [ (string) ( $business['founded_year'] ?? '' ) ],
            default    => [],
        };
        if ( [] === $terms ) {
            return null;
        }
        $hit = PackageBuilder::matchOption( $options, $terms );
        return null === $hit ? null : $hit['value'];
    }

    /**
     * Value for a live field that was not part of the package (new field since packaging).
     *
     * @param array<string,mixed> $business
     * @param array<string,mixed> $content
     */
    private function fallbackValue( string $semantic, array $business, array $content ): string {
        $value = match ( $semantic ) {
            'website'       => $business['website'] ?? '',
            'email'         => $business['email'] ?? '',
            'business_name' => $business['name'] ?? '',
            'phone'         => $business['phone'] ?? '',
            'address'       => $business['address'] ?? '',
            'city'          => $business['city'] ?? '',
            'region'        => $business['region_name'] ?? ( $business['region'] ?? '' ),
            'postal_code'   => $business['postal_code'] ?? '',
            'country'       => $business['country_name'] ?? ( $business['country'] ?? '' ),
            'founded'       => $business['founded_year'] ?? '',
            'description'   => $content['description'] ?? '',
            'message'       => $content['request_message'] ?? '',
            'tagline'       => $content['tagline'] ?? '',
            'subject'       => $content['subject'] ?? '',
            'keywords'      => implode( ', ', array_map( 'strval', (array) ( $content['keywords'] ?? [] ) ) ),
            'category'      => (string) ( ( (array) ( $content['category_suggestions'] ?? [] ) )[0] ?? '' ),
            default         => '',
        };
        return is_scalar( $value ) ? (string) $value : '';
    }

    /**
     * @param array<string,mixed>  $form
     * @param array<string,string> $body
     * @return array<string,mixed>
     */
    private function interpret( Response $post, array $form, array $body ): array {
        $clean   = trim( preg_replace( '/\s+/u', ' ', Str::clean( $post->body ) ) ?? '' );
        $lower   = mb_strtolower( $clean );
        $excerpt = Redactor::redactString( mb_substr( $clean, 0, self::MAX_EXCERPT ) );

        $page = null;
        if ( $post->isHtml() && '' !== $post->body ) {
            try {
                $page = $this->analyzer->fromResponse( $post );
            } catch ( Throwable $e ) {
                $page = null;
            }
        }
        $successHits = $this->hits( $lower, self::SUCCESS_MARKERS );
        $errorHits   = $this->hits( $lower, self::ERROR_MARKERS );
        $toSuccess   = $post->redirected() && (bool) preg_match( self::SUCCESS_URL, $post->finalUrl );
        $formAgain   = null !== $page && $this->formRedisplayed( $page, $form );

        $response = [
            'adapter'          => $this->id(),
            'http_status'      => $post->status,
            'final_url'        => $post->finalUrl,
            'elapsed'          => round( $post->elapsed, 2 ),
            'posted_fields'    => array_keys( $body ),
            'success_markers'  => $successHits,
            'error_markers'    => $errorHits,
            'form_redisplayed' => $formAgain,
            'excerpt'          => $excerpt,
            'at'               => gmdate( 'c' ),
        ];

        if ( null !== $post->error ) {
            return $this->failed( 'Posting the form failed: ' . $post->error, $this->isTransient( $post ), $response );
        }
        if ( $post->status >= 500 || 429 === $post->status ) {
            return $this->failed( sprintf( 'The destination returned HTTP %d.', $post->status ), true, $response );
        }
        if ( $post->status >= 400 ) {
            return $this->failed( sprintf( 'The destination rejected the request (HTTP %d).', $post->status ), false, $response );
        }

        $ok = ( [] !== $successHits && ( [] === $errorHits || ! $formAgain ) ) || ( $toSuccess && [] === $errorHits );
        if ( ! $ok ) {
            if ( [] !== $errorHits ) {
                $why = 'The destination reported a problem (' . implode( ', ', array_slice( $errorHits, 0, 4 ) ) . ').';
            } elseif ( $formAgain ) {
                $why = 'The form was displayed again without a confirmation message.';
            } else {
                $why = 'No confirmation message was found in the response; check the destination before retrying.';
            }
            return $this->failed( $why, false, $response );
        }

        return [
            'status'           => self::STATUS_SUBMITTED,
            'response'         => $response,
            'confirmation_ref' => $this->confirmationRef( $clean ),
            'listing_url'      => null !== $page ? $this->listingUrl( $page, $post ) : '',
            'error'            => '',
            'retryable'        => false,
        ];
    }

    /**
     * @param string[] $markers
     * @return string[]
     */
    private function hits( string $haystack, array $markers ): array {
        $out = [];
        foreach ( $markers as $m ) {
            if ( str_contains( $haystack, $m ) ) {
                $out[] = $m;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $form */
    private function formRedisplayed( PageAnalysis $page, array $form ): bool {
        $action = Url::normalize( (string) ( $form['action'] ?? '' ) );
        $id     = (string) ( $form['id'] ?? '' );
        foreach ( $page->forms as $f ) {
            if ( ! is_array( $f ) ) {
                continue;
            }
            if ( '' !== $id && $id === (string) ( $f['id'] ?? '' ) ) {
                return true;
            }
            if ( '' !== $action && Url::normalize( (string) ( $f['action'] ?? '' ) ) === $action && FormDetector::KIND_LISTING === ( $f['kind'] ?? '' ) ) {
                return true;
            }
        }
        return false;
    }

    private function confirmationRef( string $text ): string {
        if ( preg_match( '/\b(?:reference|confirmation|ticket|listing|submission|request|tracking)\s*(?:number|no\.?|id|code|#)?\s*[:#]\s*([A-Za-z0-9][A-Za-z0-9\-_]{3,40})\b/i', $text, $m ) && preg_match( '/\d/', $m[1] ) ) {
            return mb_substr( $m[1], 0, 190 );
        }
        return '';
    }

    /** A link on the response page that already points at our new listing (URL containing the brand slug). */
    private function listingUrl( PageAnalysis $page, Response $post ): string {
        $brand = (string) $this->settings->get( 'site.brand_name', '' );
        $slug  = function_exists( 'sanitize_title' ) ? sanitize_title( $brand ) : strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $brand ) ?? '' );
        $slug  = trim( $slug, '-' );
        if ( '' === $slug ) {
            return '';
        }
        $compact = str_replace( '-', '', $slug );
        $base    = $post->finalUrl ?: $post->url;
        foreach ( $page->links as $l ) {
            if ( ! Url::isSameSite( $l['url'], $base ) ) {
                continue;
            }
            $u = mb_strtolower( $l['url'] );
            if ( str_contains( $u, $slug ) || ( '' !== $compact && str_contains( $u, $compact ) ) ) {
                return $l['url'];
            }
        }
        return '';
    }

    private function robotsAllow( string $url ): bool {
        try {
            return $this->robots->isAllowed( $url, fn( string $u ): Response => $this->client->rawRequest( 'GET', $u, [ 'timeout' => 8 ] ) );
        } catch ( Throwable $e ) {
            return false;
        }
    }

    private function isTransient( Response $r ): bool {
        if ( null !== $r->error ) {
            return ! in_array( (string) $r->errorCode, [ 'invalid_url', 'robots_disallow' ], true );
        }
        return 0 === $r->status || $r->status >= 500 || 429 === $r->status;
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function manual( string $why, array $response = [] ): array {
        return [
            'status'           => self::STATUS_NEEDS_MANUAL,
            'response'         => array_merge( [ 'adapter' => $this->id(), 'at' => gmdate( 'c' ) ], $response ),
            'confirmation_ref' => '',
            'listing_url'      => '',
            'error'            => $why,
            'retryable'        => false,
        ];
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function failed( string $why, bool $retryable, array $response = [] ): array {
        return [
            'status'           => self::STATUS_FAILED,
            'response'         => array_merge( [ 'adapter' => $this->id(), 'at' => gmdate( 'c' ) ], $response ),
            'confirmation_ref' => '',
            'listing_url'      => '',
            'error'            => $why,
            'retryable'        => $retryable,
        ];
    }
}
