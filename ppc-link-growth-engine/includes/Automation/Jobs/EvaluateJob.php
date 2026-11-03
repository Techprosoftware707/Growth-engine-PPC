<?php
/**
 * Job: qualify prospects — profile the site, score relevance/risk/metrics, detect the submission page,
 * refine classification, store everything on the prospect and upsert its contacts.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Analysis\FormDetector;
use PPC\LinkGrowth\Analysis\PageAnalysis;
use PPC\LinkGrowth\Analysis\RelevanceScorer;
use PPC\LinkGrowth\Analysis\RiskAnalyzer;
use PPC\LinkGrowth\Analysis\SiteProfile;
use PPC\LinkGrowth\Analysis\SiteProfiler;
use PPC\LinkGrowth\Analysis\SubmissionPageFinder;
use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Discovery\Classifier;
use PPC\LinkGrowth\Integrations\MetricsAggregator;
use PPC\LinkGrowth\Repositories\Backlinks;
use PPC\LinkGrowth\Repositories\Contacts;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Scoring\ScoringEngine;
use PPC\LinkGrowth\Support\TimeBudget;
use Throwable;

final class EvaluateJob extends AbstractJob {

    private const DEFAULT_LIMIT          = 10;
    private const MAX_LIMIT              = 50;
    private const PER_PROSPECT_SECONDS   = 8.0;
    private const FINDER_SECONDS         = 6.0;
    private const FINDER_FETCHES         = 2;
    private const REVIEW_DAYS            = 90;
    private const UNREACHABLE_REVIEW_DAYS = 30;
    private const ERROR_REVIEW_DAYS      = 1;
    private const MAX_CONTACTS           = 10;
    private const MAX_OPTIONS_PER_FIELD  = 150;
    private const CLASSIFIER_MIN_CONF    = 40;
    private const ERROR_REASON_PREFIX    = 'Evaluation error:';

    /** Statuses set by a person (or by acquisition) that automatic evaluation must not override. */
    private const PROTECTED_STATUSES = [ Prospects::STATUS_APPROVED, Prospects::STATUS_IN_PROGRESS, Prospects::STATUS_ACQUIRED, Prospects::STATUS_ON_HOLD ];

    /** Opportunity types that come from a specific finding and must survive re-classification. */
    private const SPECIFIC_OPPORTUNITIES = [ 'unlinked_mention', 'broken_link' ];

    private const HIGH_CLASSES = [ ScoringEngine::CLASS_EXCEPTIONAL, ScoringEngine::CLASS_HIGH ];

    public static function type(): string {
        return 'evaluate';
    }

    public static function priority(): int {
        return 4;
    }

    /**
     * @param array<string,mixed> $payload { prospect_id?: int (single prospect), limit?: int }
     * @return array<string,mixed>
     */
    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        $prospects = $c->get( Prospects::class );
        $limits    = $this->limits( $c );
        $logger    = $this->logger( $c );
        $stats     = [ 'evaluated' => 0, 'qualified' => 0, 'rejected' => 0, 'unreachable' => 0, 'kept' => 0, 'errors' => 0, 'deferred' => 0, 'limited' => 0, 'continued' => false, 'items' => [] ];

        $single = (int) ( $payload['prospect_id'] ?? 0 );
        if ( $single > 0 ) {
            $row = $prospects->find( $single );
            if ( null === $row ) {
                return $this->skipped( 'prospect_not_found', [ 'prospect_id' => $single ] );
            }
            if ( Prospects::STATUS_SUPPRESSED === (string) $row['status'] ) {
                return $this->skipped( 'prospect_suppressed', [ 'prospect_id' => $single ] );
            }
            if ( ! $limits->allow( 'evaluations' ) ) {
                return $this->skipped( 'evaluation_limit_reached', [ 'limits' => $limits->snapshot()['evaluations'] ?? null ] );
            }
            $batch = [ $row ];
        } else {
            if ( ! $this->flag( $c, $payload, 'evaluation' ) ) {
                return $this->skipped( 'evaluation_disabled' );
            }
            $remaining = $limits->remaining( 'evaluations' );
            if ( $remaining <= 0 ) {
                return $this->skipped( 'evaluation_limit_reached', [ 'limits' => $limits->snapshot()['evaluations'] ?? null ] );
            }
            $limit = max( 1, min( (int) ( $payload['limit'] ?? self::DEFAULT_LIMIT ), self::MAX_LIMIT, $remaining ) );
            $batch = $prospects->dueForEvaluation( $limit );
            if ( [] === $batch ) {
                return $this->result( array_merge( $stats, [ 'message' => 'No prospects are due for evaluation.' ] ) );
            }
        }

        foreach ( $batch as $prospect ) {
            if ( ! $budget->has( self::PER_PROSPECT_SECONDS ) ) {
                $stats['deferred']++;
                continue;
            }
            if ( ! $limits->allow( 'evaluations' ) ) {
                $stats['limited']++;
                continue;
            }
            $id = (int) $prospect['id'];
            try {
                $item = $this->evaluateOne( $prospect, $c, $budget );
                $stats['evaluated']++;
                $stats[ $item['outcome'] ] = (int) ( $stats[ $item['outcome'] ] ?? 0 ) + 1;
                $stats['items'][]          = $item;
            } catch ( Throwable $e ) {
                $stats['errors']++;
                $logger->error( 'evaluate', 'Evaluation failed', array_merge( [ 'prospect_id' => $id, 'domain' => (string) $prospect['domain'] ], $this->errorContext( $e ) ) );
                $this->parkAfterError( $prospects, $prospect, $e );
                $stats['items'][] = [ 'prospect_id' => $id, 'domain' => (string) $prospect['domain'], 'outcome' => 'error', 'error' => $e->getMessage() ];
            }
        }

        if ( 0 === $stats['evaluated'] && 0 === $stats['errors'] && $stats['deferred'] > 0 ) {
            return $this->deferred( $c, $payload, 'time_budget', $stats );
        }
        if ( $single <= 0 && $stats['evaluated'] > 0 && $limits->allow( 'evaluations' ) && [] !== $prospects->dueForEvaluation( 1 ) ) {
            $stats['continued'] = null !== $this->continueLater( $c, $payload, 30 );
        }
        return $this->result( $stats );
    }

    // ------------------------------------------------------------------
    // One prospect
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $prospect
     * @return array<string,mixed> item summary with key "outcome" (qualified|rejected|unreachable|kept)
     */
    private function evaluateOne( array $prospect, Container $c, TimeBudget $budget ): array {
        $prospects = $c->get( Prospects::class );
        $settings  = $this->settings( $c );
        $limits    = $this->limits( $c );
        $id        = (int) $prospect['id'];
        $domain    = (string) $prospect['domain'];
        $previous  = (string) ( $prospect['status'] ?? Prospects::STATUS_DISCOVERED );
        // Rows parked on hold by the error handler are not a human decision: a re-run may change their status again.
        $errorParked = Prospects::STATUS_ON_HOLD === $previous && str_starts_with( (string) ( $prospect['rejection_reason'] ?? '' ), self::ERROR_REASON_PREFIX );
        $protected   = in_array( $previous, self::PROTECTED_STATUSES, true ) && ! $errorParked;

        $prospects->update( $id, [ 'status' => Prospects::STATUS_EVALUATING, 'updated_at' => $this->now() ] );

        $profile    = $c->get( SiteProfiler::class )->profile( $domain, (string) ( $prospect['homepage_url'] ?? '' ), 2 );
        $profileArr = $profile->toArray();

        if ( ! $profile->reachable || null === $profile->home ) {
            return $this->storeUnreachable( $c, $prospect, $profile, $profileArr, $protected );
        }

        $home      = $profile->home;
        $pages     = array_merge( [ $home ], $profile->pages );
        $relevance = $c->get( RelevanceScorer::class )->score( $pages, $profile->country, $profile->countryConfidence );
        $risk      = $c->get( RiskAnalyzer::class )->analyze( $profile, $relevance );

        // Provider metrics: cached per domain; each live lookup counts against provider_lookups.
        $lookups = 0;
        $metrics = $c->get( MetricsAggregator::class )->metricsFor(
            $domain,
            true,
            static function () use ( $limits, &$lookups ): bool {
                if ( ! $limits->allow( 'provider_lookups', $lookups + 1 ) ) {
                    return false;
                }
                $lookups++;
                return true;
            }
        );
        if ( $lookups > 0 ) {
            $limits->spend( 'provider_lookups', $lookups );
        }

        $sendsReferral = $this->sendsReferral( $c, $domain );
        $scoring       = $c->get( ScoringEngine::class );
        $score         = $scoring->score( $profile, $relevance, $risk, $metrics, null, $sendsReferral );
        $minKeep       = max( 0, min( 100, (int) $settings->get( 'automation.min_score_keep', 50 ) ) );

        // Submission page / form, only for prospects worth keeping (costs up to two fetches).
        $submissionUrl  = $profile->submissionUrl;
        $submissionForm = $profile->submissionForm;
        $finder         = null;
        if ( empty( $score['auto_reject'] ) && (int) $score['total'] >= $minKeep && $budget->has( self::FINDER_SECONDS ) ) {
            try {
                $found = $c->get( SubmissionPageFinder::class )->find( $home, self::FINDER_FETCHES );
                if ( '' !== (string) ( $found['url'] ?? '' ) ) {
                    $submissionUrl = (string) $found['url'];
                    if ( is_array( $found['form'] ?? null ) ) {
                        $submissionForm = $found['form'];
                    }
                    $finder = [
                        'url'        => $submissionUrl,
                        'score'      => (int) ( $found['score'] ?? 0 ),
                        'kind'       => (string) ( $found['kind'] ?? '' ),
                        'candidates' => count( (array) ( $found['candidates'] ?? [] ) ),
                        'checked_at' => gmdate( 'c' ),
                    ];
                    if ( ( $found['page'] ?? null ) instanceof PageAnalysis ) {
                        // Re-score against the page where the link would actually live (indexability / placement).
                        $score = $scoring->score( $profile, $relevance, $risk, $metrics, $found['page'], $sendsReferral );
                    }
                }
            } catch ( Throwable $e ) {
                $this->logger( $c )->warning( 'evaluate', 'Submission page detection failed', array_merge( [ 'domain' => $domain ], $this->errorContext( $e ) ) );
            }
        }
        $hasListingForm = is_array( $submissionForm ) && FormDetector::KIND_LISTING === (string) ( $submissionForm['kind'] ?? '' );

        // Classification refined with the real homepage analysis.
        $classification = $c->get( Classifier::class )->classify( $profile->homepageUrl ?: 'https://' . $domain . '/', $home->title, $profile->description, $home );
        [ $siteType, $oppType ] = $this->refineTypes( $prospect, $classification );

        $total    = max( 0, min( 100, (int) $score['total'] ) );
        $rejected = ! empty( $score['auto_reject'] ) || $total < $minKeep;
        if ( $protected ) {
            $status  = $previous;
            $outcome = 'kept';
        } elseif ( $rejected ) {
            $status  = Prospects::STATUS_REJECTED;
            $outcome = 'rejected';
        } else {
            $status  = Prospects::STATUS_QUALIFIED;
            $outcome = 'qualified';
        }
        $reason = $rejected ? $this->rejectionReason( $score, $total, $minKeep ) : '';
        $action = ( $rejected && ! $protected )
            ? 'Do not pursue'
            : $this->recommendedAction( $oppType, $hasListingForm, $submissionUrl, $profile->contactUrl, count( $profile->contacts ) );

        $profileArr['submission_url']    = $submissionUrl;
        $profileArr['submission_form']   = is_array( $submissionForm ) ? $this->compactForm( $submissionForm, $submissionUrl ) : null;
        $profileArr['submission_finder'] = $finder;
        $profileArr['relevance']         = [
            'score'    => (int) $relevance['score'],
            'topical'  => (int) $relevance['topical'],
            'geo'      => (int) $relevance['geo'],
            'matched'  => array_slice( (array) $relevance['matched'], 0, 15, true ),
            'negative' => array_slice( (array) $relevance['negative'], 0, 10, true ),
            'misuse'   => array_slice( (array) $relevance['misuse'], 0, 10, true ),
        ];
        $profileArr['risk']              = $risk;
        $profileArr['classification']    = $classification;
        $profileArr['sends_referral']    = $sendsReferral;

        $now    = $this->now();
        $name   = '' !== $profile->name ? $profile->name : ( (string) ( $prospect['name'] ?? '' ) ?: $domain );
        $update = [
            'name'               => mb_substr( $name, 0, 255 ),
            'homepage_url'       => mb_substr( $profile->homepageUrl ?: (string) ( $prospect['homepage_url'] ?? '' ), 0, 500 ),
            'site_type'          => $siteType,
            'opportunity_type'   => $oppType,
            'status'             => $status,
            'score'              => $total,
            'score_class'        => mb_substr( (string) $score['class'], 0, 20 ),
            'score_confidence'   => max( 0, min( 100, (int) $score['confidence'] ) ),
            'score_breakdown'    => $score,
            'relevance'          => max( 0, min( 100, (int) $relevance['score'] ) ),
            'metrics'            => $metrics,
            'profile'            => $profileArr,
            'risk_flags'         => array_values( array_map( 'strval', (array) ( $risk['flags'] ?? [] ) ) ),
            'contact_url'        => mb_substr( $profile->contactUrl, 0, 700 ),
            'submission_url'     => mb_substr( $submissionUrl, 0, 700 ),
            'recommended_action' => mb_substr( $action, 0, 60 ),
            'rejection_reason'   => mb_substr( $reason, 0, 255 ),
            'last_evaluated'     => $now,
            'next_review'        => $this->inDays( self::REVIEW_DAYS ),
            'updated_at'         => $now,
        ];
        if ( '' !== $profile->language ) {
            $update['language'] = substr( $profile->language, 0, 10 );
        }
        if ( '' !== $profile->country ) {
            $update['country'] = strtoupper( substr( $profile->country, 0, 2 ) );
        }
        $prospects->update( $id, $update );

        $contactsNew = $this->upsertContacts( $c, $id, $profile->contacts );
        $limits->spend( 'evaluations' );

        $this->audit( $c )->record(
            'prospect.evaluated',
            'prospect',
            $id,
            [
                'domain'             => $domain,
                'outcome'            => $outcome,
                'status'             => $status,
                'previous_status'    => $previous,
                'score'              => $total,
                'class'              => (string) $score['class'],
                'confidence'         => (int) $score['confidence'],
                'relevance'          => (int) $relevance['score'],
                'risk_flags'         => (array) ( $risk['flags'] ?? [] ),
                'auto_reject'        => ! empty( $score['auto_reject'] ),
                'reasons'            => array_slice( (array) ( $score['reasons'] ?? [] ), 0, 5 ),
                'site_type'          => $siteType,
                'opportunity_type'   => $oppType,
                'submission_url'     => $submissionUrl,
                'listing_form'       => $hasListingForm,
                'contacts_found'     => count( $profile->contacts ),
                'contacts_new'       => $contactsNew,
                'provider_lookups'   => $lookups,
                'fetches'            => $profile->fetches,
                'recommended_action' => $action,
            ]
        );

        $prevClass = (string) ( $prospect['score_class'] ?? '' );
        if ( ! $rejected && in_array( (string) $score['class'], self::HIGH_CLASSES, true ) && ! in_array( $prevClass, self::HIGH_CLASSES, true ) ) {
            $this->notify(
                $c,
                'prospect',
                'success',
                'High-value prospect found',
                sprintf( '%s (%s) scored %d/100 — %s. Recommended: %s.', $name, $domain, $total, (string) ( $score['class_label'] ?? '' ), $action ),
                [ 'prospect_id' => $id, 'domain' => $domain, 'score' => $total, 'class' => (string) $score['class'] ]
            );
        } elseif ( $protected && $rejected ) {
            $this->notify(
                $c,
                'prospect',
                'warning',
                'Re-evaluation flagged a prospect you manage',
                sprintf( '%s (%s) now scores %d/100 (%s) but keeps its "%s" status because it was set manually. Reason: %s', $name, $domain, $total, (string) ( $score['class_label'] ?? '' ), $previous, $reason ),
                [ 'prospect_id' => $id, 'domain' => $domain, 'score' => $total ]
            );
        }

        return [
            'prospect_id'        => $id,
            'domain'             => $domain,
            'outcome'            => $outcome,
            'status'             => $status,
            'score'              => $total,
            'class'              => (string) $score['class'],
            'relevance'          => (int) $relevance['score'],
            'risk_flags'         => (array) ( $risk['flags'] ?? [] ),
            'submission_url'     => $submissionUrl,
            'listing_form'       => $hasListingForm,
            'contacts_new'       => $contactsNew,
            'recommended_action' => $action,
            'reason'             => $reason,
        ];
    }

    /**
     * @param array<string,mixed> $prospect
     * @param array<string,mixed> $profileArr
     * @return array<string,mixed>
     */
    private function storeUnreachable( Container $c, array $prospect, SiteProfile $profile, array $profileArr, bool $protected ): array {
        $prospects = $c->get( Prospects::class );
        $id        = (int) $prospect['id'];
        $domain    = (string) $prospect['domain'];
        $previous  = (string) ( $prospect['status'] ?? Prospects::STATUS_DISCOVERED );
        $reason    = 'Site unreachable' . ( '' !== $profile->error ? ' (' . $profile->error . ')' : '' );
        $status    = $protected ? $previous : Prospects::STATUS_REJECTED;
        $now       = $this->now();

        $prospects->update(
            $id,
            [
                'status'             => $status,
                'rejection_reason'   => mb_substr( $reason, 0, 255 ),
                'profile'            => $profileArr,
                'recommended_action' => $protected ? (string) ( $prospect['recommended_action'] ?? '' ) : 'Do not pursue',
                'last_evaluated'     => $now,
                'next_review'        => $this->inDays( self::UNREACHABLE_REVIEW_DAYS ),
                'updated_at'         => $now,
            ]
        );
        $this->limits( $c )->spend( 'evaluations' );
        $this->audit( $c )->record(
            'prospect.evaluated',
            'prospect',
            $id,
            [ 'domain' => $domain, 'outcome' => 'unreachable', 'error' => $profile->error, 'status' => $status, 'previous_status' => $previous, 'fetches' => $profile->fetches ]
        );
        if ( $protected ) {
            $this->notify(
                $c,
                'prospect',
                'warning',
                'Prospect site unreachable',
                sprintf( '%s could not be fetched during re-evaluation (%s). Its "%s" status was kept; it will be re-checked in %d days.', $domain, $profile->error ?: 'no response', $previous, self::UNREACHABLE_REVIEW_DAYS ),
                [ 'prospect_id' => $id, 'domain' => $domain ]
            );
        }
        return [ 'prospect_id' => $id, 'domain' => $domain, 'outcome' => 'unreachable', 'status' => $status, 'error' => $profile->error ];
    }

    /**
     * After an exception the prospect must not stall the queue: "discovered" rows are always due, so they are
     * parked on hold with the error; other rows keep their status and get a short review delay.
     *
     * @param array<string,mixed> $prospect
     */
    private function parkAfterError( Prospects $prospects, array $prospect, Throwable $e ): void {
        try {
            $previous = (string) ( $prospect['status'] ?? Prospects::STATUS_DISCOVERED );
            $park     = in_array( $previous, [ Prospects::STATUS_DISCOVERED, Prospects::STATUS_EVALUATING ], true );
            $prospects->update(
                (int) $prospect['id'],
                [
                    'status'           => $park ? Prospects::STATUS_ON_HOLD : $previous,
                    'rejection_reason' => mb_substr( self::ERROR_REASON_PREFIX . ' ' . $e->getMessage(), 0, 255 ),
                    'next_review'      => $this->inDays( self::ERROR_REVIEW_DAYS ),
                    'updated_at'       => $this->now(),
                ]
            );
        } catch ( Throwable $inner ) {
            unset( $inner );
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function sendsReferral( Container $c, string $domain ): bool {
        try {
            foreach ( $c->get( Backlinks::class )->forDomain( $domain ) as $backlink ) {
                if ( 'ga4_referral' === (string) ( $backlink['origin'] ?? '' ) ) {
                    return true;
                }
            }
        } catch ( Throwable $e ) {
            unset( $e );
        }
        return false;
    }

    /**
     * Keeps curated (seed) and finding-specific types; otherwise trusts a confident classification.
     *
     * @param array<string,mixed> $prospect
     * @param array{site_type:string,opportunity_type:string,confidence:int} $classification
     * @return array{0:string,1:string}
     */
    private function refineTypes( array $prospect, array $classification ): array {
        $siteType = (string) ( $prospect['site_type'] ?? '' ) ?: 'unknown';
        $oppType  = (string) ( $prospect['opportunity_type'] ?? '' ) ?: 'unknown';
        $isSeed   = str_starts_with( (string) ( $prospect['discovered_via'] ?? '' ), 'seed' );
        $clsSite  = (string) ( $classification['site_type'] ?? 'unknown' );
        $clsOpp   = (string) ( $classification['opportunity_type'] ?? '' );
        $conf     = (int) ( $classification['confidence'] ?? 0 );
        $confident = $conf >= self::CLASSIFIER_MIN_CONF && ! $isSeed;

        if ( 'unknown' !== $clsSite && ( 'unknown' === $siteType || $confident ) ) {
            $siteType = $clsSite;
        }
        $oppKnown = array_key_exists( $oppType, Opportunities::TYPES );
        if ( '' !== $clsOpp && array_key_exists( $clsOpp, Opportunities::TYPES ) && ! in_array( $oppType, self::SPECIFIC_OPPORTUNITIES, true ) && ( ! $oppKnown || $confident ) ) {
            $oppType = $clsOpp;
        }
        return [ mb_substr( $siteType, 0, 40 ), mb_substr( $oppType, 0, 40 ) ];
    }

    private function recommendedAction( string $oppType, bool $hasListingForm, string $submissionUrl, string $contactUrl, int $contacts ): string {
        $canContact = '' !== $contactUrl || $contacts > 0;
        switch ( $oppType ) {
            case 'directory_listing':
            case 'supplier_listing':
            case 'membership':
                if ( $hasListingForm ) {
                    return 'membership' === $oppType ? 'Apply for membership listing' : 'Submit directory listing';
                }
                if ( '' !== $submissionUrl ) {
                    return 'Submit listing on submission page';
                }
                return $canContact ? 'Request listing via contact form' : 'Research listing process manually';
            case 'resource_page':
                return 'Pitch resource page inclusion';
            case 'editorial':
                return 'Pitch editorial mention';
            case 'partnership':
                return 'Propose industry partnership';
            case 'expert_commentary':
                return 'Offer expert commentary';
            case 'educational_contribution':
                return 'Offer educational contribution';
            case 'broken_link':
                return 'Suggest replacement for broken link';
            case 'unlinked_mention':
                return 'Request link for brand mention';
            case 'podcast':
                return 'Pitch podcast interview';
            default:
                if ( $hasListingForm ) {
                    return 'Submit directory listing';
                }
                return $canContact ? 'Contact site for inclusion' : 'Review manually';
        }
    }

    /** @param array<string,mixed> $score */
    private function rejectionReason( array $score, int $total, int $minKeep ): string {
        $reasons = array_values( array_filter( array_map( 'strval', (array) ( $score['reasons'] ?? [] ) ) ) );
        if ( ! empty( $score['auto_reject'] ) && [] !== $reasons ) {
            return implode( ' ', array_slice( $reasons, 0, 3 ) );
        }
        $msg = sprintf( 'Score %d is below the keep threshold of %d.', $total, $minKeep );
        return [] !== $reasons ? $msg . ' ' . implode( ' ', array_slice( $reasons, 0, 2 ) ) : $msg;
    }

    /**
     * Stores the detected form with complete select options so packaging can map categories without re-fetching.
     *
     * @param array<string,mixed> $form
     * @return array<string,mixed>
     */
    private function compactForm( array $form, string $pageUrl ): array {
        $fields = [];
        foreach ( (array) ( $form['fields'] ?? [] ) as $f ) {
            if ( ! is_array( $f ) ) {
                continue;
            }
            $options = [];
            foreach ( array_slice( (array) ( $f['options'] ?? [] ), 0, self::MAX_OPTIONS_PER_FIELD ) as $opt ) {
                if ( is_array( $opt ) ) {
                    $options[] = [ 'value' => mb_substr( (string) ( $opt['value'] ?? '' ), 0, 120 ), 'text' => mb_substr( (string) ( $opt['text'] ?? '' ), 0, 120 ) ];
                }
            }
            $fields[] = [
                'name'        => (string) ( $f['name'] ?? '' ),
                'id'          => (string) ( $f['id'] ?? '' ),
                'type'        => (string) ( $f['type'] ?? '' ),
                'label'       => mb_substr( (string) ( $f['label'] ?? '' ), 0, 160 ),
                'placeholder' => mb_substr( (string) ( $f['placeholder'] ?? '' ), 0, 160 ),
                'semantic'    => (string) ( $f['semantic'] ?? 'unknown' ),
                'required'    => ! empty( $f['required'] ),
                'maxlength'   => (int) ( $f['maxlength'] ?? 0 ),
                'value'       => is_scalar( $f['value'] ?? null ) ? mb_substr( (string) $f['value'], 0, 200 ) : '',
                'options'     => $options,
            ];
        }
        return [
            'kind'         => (string) ( $form['kind'] ?? '' ),
            'action'       => (string) ( $form['action'] ?? '' ),
            'method'       => (string) ( $form['method'] ?? '' ),
            'enctype'      => (string) ( $form['enctype'] ?? '' ),
            'id'           => (string) ( $form['id'] ?? '' ),
            'has_captcha'  => ! empty( $form['has_captcha'] ),
            'has_password' => ! empty( $form['has_password'] ),
            'has_file'     => ! empty( $form['has_file'] ),
            'page_url'     => $pageUrl,
            'fields'       => $fields,
            'detected_at'  => gmdate( 'c' ),
        ];
    }

    /**
     * @param array<int,array{email:string,name:string,role:string,source_url:string,confidence:int}> $contacts
     * @return int newly created contacts
     */
    private function upsertContacts( Container $c, int $prospectId, array $contacts ): int {
        if ( [] === $contacts ) {
            return 0;
        }
        $repo = $c->get( Contacts::class );
        $new  = 0;
        foreach ( array_slice( $contacts, 0, self::MAX_CONTACTS ) as $contact ) {
            try {
                $email = strtolower( trim( (string) ( $contact['email'] ?? '' ) ) );
                if ( '' === $email ) {
                    continue;
                }
                [ $cid, $created ] = $repo->upsert(
                    $prospectId,
                    $email,
                    [
                        'name'          => mb_substr( (string) ( $contact['name'] ?? '' ), 0, 190 ),
                        'role'          => mb_substr( (string) ( $contact['role'] ?? '' ), 0, 100 ),
                        'source_url'    => mb_substr( (string) ( $contact['source_url'] ?? '' ), 0, 700 ),
                        'confidence'    => max( 0, min( 100, (int) ( $contact['confidence'] ?? 50 ) ) ),
                        'consent_basis' => 'conspicuous_publication',
                    ]
                );
                if ( $cid > 0 && $created ) {
                    $new++;
                }
            } catch ( Throwable $e ) {
                $this->logger( $c )->warning( 'evaluate', 'Contact could not be stored', array_merge( [ 'prospect_id' => $prospectId ], $this->errorContext( $e ) ) );
            }
        }
        return $new;
    }
}
