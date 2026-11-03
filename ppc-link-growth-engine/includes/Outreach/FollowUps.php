<?php
/**
 * Generates the single polite follow-up for sent messages that received no reply.
 *
 * Follow-ups are drafts by default; they are only queued automatically ("scheduled") when the
 * campaign opted in (settings.auto_follow_up) AND the automation master switch for outreach
 * auto-send is on. Nothing here sends email.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Outreach;

use PPC\LinkGrowth\Repositories\Campaigns;
use PPC\LinkGrowth\Repositories\Contacts;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Support\Crypto;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;

final class FollowUps {

    private ?Contacts $contacts = null;

    private ?Prospects $prospects = null;

    private ?Opportunities $opportunities = null;

    public function __construct(
        private OutreachMessages $messages,
        private Campaigns $campaigns,
        private Settings $settings,
        private Templates $templates,
        private Personalizer $personalizer
    ) {}

    /**
     * @return array{created:int,skipped:int,errors:int}
     */
    public function generateDue( TimeBudget $budget, int $max = 20 ): array {
        $out          = [ 'created' => 0, 'skipped' => 0, 'errors' => 0 ];
        $maxFollowUps = (int) $this->settings->get( 'outreach.max_follow_ups', 1 );
        if ( $maxFollowUps < 1 ) {
            return $out;
        }
        $days     = max( 1, (int) $this->settings->get( 'outreach.follow_up_days', 7 ) );
        $autosend = (bool) $this->settings->get( 'automation.outreach_autosend', false );
        $max      = max( 1, min( 200, $max ) );

        try {
            $due = $this->messages->awaitingFollowUp( $days, $max );
        } catch ( \Throwable $e ) {
            $out['errors']++;
            return $out;
        }

        $campaignCache = [];
        foreach ( $due as $original ) {
            if ( ! $budget->has( 1.0 ) ) {
                break;
            }
            try {
                $sequence = max( 1, (int) ( $original['sequence'] ?? 1 ) );
                if ( $sequence >= 1 + $maxFollowUps ) {
                    $out['skipped']++;
                    continue;
                }
                $campaignId = (int) ( $original['campaign_id'] ?? 0 );
                $campaign   = null;
                if ( $campaignId > 0 ) {
                    if ( ! array_key_exists( $campaignId, $campaignCache ) ) {
                        $campaignCache[ $campaignId ] = $this->campaigns->find( $campaignId );
                    }
                    $campaign = $campaignCache[ $campaignId ];
                    if ( ! $campaign ) {
                        $out['skipped']++;
                        continue;
                    }
                }
                $cs = is_array( $campaign['settings'] ?? null ) ? $campaign['settings'] : [];
                if ( isset( $cs['follow_ups'] ) && ! filter_var( $cs['follow_ups'], FILTER_VALIDATE_BOOLEAN ) ) {
                    $out['skipped']++;
                    continue;
                }
                if ( $campaign && in_array( (string) $campaign['status'], [ Campaigns::STATUS_ARCHIVED, Campaigns::STATUS_COMPLETED ], true ) ) {
                    $out['skipped']++;
                    continue;
                }
                $contactId = (int) ( $original['contact_id'] ?? 0 );
                $contact   = $contactId > 0 ? $this->contacts()->find( $contactId ) : null;
                if ( ! $contact || Contacts::STATUS_ACTIVE !== (string) ( $contact['status'] ?? '' ) ) {
                    $out['skipped']++;
                    continue;
                }
                $approach = (string) ( $original['approach'] ?? '' );
                if ( $this->messages->existsFor( $contactId, $approach, $sequence + 1 ) ) {
                    $out['skipped']++;
                    continue;
                }
                $prospect = $this->prospects()->find( (int) ( $original['prospect_id'] ?? 0 ) );
                if ( ! $prospect || in_array( (string) ( $prospect['status'] ?? '' ), [ Prospects::STATUS_REJECTED, Prospects::STATUS_SUPPRESSED ], true ) ) {
                    $out['skipped']++;
                    continue;
                }
                $opportunity = ! empty( $original['opportunity_id'] ) ? $this->opportunities()->find( (int) $original['opportunity_id'] ) : null;

                $token = Crypto::randomToken( 16 );
                $url   = Unsubscribe::urlFor( $token );
                $ctx   = $this->personalizer->context( $prospect, $opportunity, $contact, $approach, $url );

                $ctx['original_subject'] = $this->originalSubject( (string) ( $original['subject'] ?? '' ) );
                $template                = $this->templates->followUp();
                $subject                 = $this->oneLine( $this->personalizer->render( $template['subject'], $ctx ), 255 );
                $body                    = $this->personalizer->render( $template['body'], $ctx );

                $auto   = $autosend && ! empty( $cs['auto_follow_up'] ) && filter_var( $cs['auto_follow_up'], FILTER_VALIDATE_BOOLEAN );
                $status = $auto ? OutreachMessages::STATUS_SCHEDULED : OutreachMessages::STATUS_DRAFT;

                $this->messages->create(
                    [
                        'campaign_id'       => $campaignId,
                        'prospect_id'       => (int) $prospect['id'],
                        'contact_id'        => $contactId,
                        'opportunity_id'    => $opportunity ? (int) $opportunity['id'] : null,
                        'approach'          => $approach,
                        'sequence'          => $sequence + 1,
                        'subject'           => $subject,
                        'body'              => $body,
                        'status'            => $status,
                        'variant'           => $template['id'],
                        'scheduled_at'      => $auto ? OutreachMessages::now() : null,
                        'unsubscribe_token' => $token,
                    ]
                );
                $out['created']++;
            } catch ( \Throwable $e ) {
                $out['errors']++;
                continue;
            }
        }
        return $out;
    }

    /** Strips any existing "Following up:" / "Re:" prefixes so the follow-up subject does not stack them. */
    private function originalSubject( string $subject ): string {
        $s = trim( $subject );
        $s = preg_replace( '/^((following up|follow[\s\-]?up|re|fw|fwd)\s*:\s*)+/i', '', $s ) ?? $s;
        return '' !== trim( $s ) ? trim( $s ) : $subject;
    }

    private function oneLine( string $s, int $max ): string {
        $s = trim( preg_replace( '/\s+/u', ' ', $s ) ?? $s );
        return mb_strlen( $s ) > $max ? mb_substr( $s, 0, $max ) : $s;
    }

    // Repositories not present in the fixed constructor; they have no dependencies of their own.

    private function contacts(): Contacts {
        return $this->contacts ??= new Contacts();
    }

    private function prospects(): Prospects {
        return $this->prospects ??= new Prospects();
    }

    private function opportunities(): Opportunities {
        return $this->opportunities ??= new Opportunities();
    }
}
