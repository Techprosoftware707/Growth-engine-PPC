<?php
/**
 * Automatically drafts personalised outreach for qualified prospects whose best
 * opportunity is editorial rather than a submission form.
 *
 * One evergreen campaign per approach ("Auto: <approach>") collects the drafts. Drafts always
 * require the configured approval flow before anything is sent; when the administrator has
 * explicitly enabled outreach autosend AND the sender identity (name, address, postal address)
 * is complete, drafts and their campaign are approved automatically so the send stage can
 * deliver them inside the send window and limits.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Automation\Jobs;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Outreach\Compliance;
use PPC\LinkGrowth\Outreach\OutreachEngine;
use PPC\LinkGrowth\Outreach\Templates;
use PPC\LinkGrowth\Repositories\Campaigns;
use PPC\LinkGrowth\Repositories\Contacts;
use PPC\LinkGrowth\Repositories\OutreachMessages;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Support\TimeBudget;
use Throwable;

final class AutoOutreachJob extends AbstractJob {

    /** Opportunity type → outreach approach. Form-first types are handled by the submission engine. */
    public const APPROACH_FOR_TYPE = [
        'resource_page'            => 'resource_page',
        'editorial'                => 'editorial_mention',
        'broken_link'              => 'broken_link',
        'unlinked_mention'         => 'unlinked_mention',
        'podcast'                  => 'expert_commentary',
        'educational_contribution' => 'educational_contribution',
        'partnership'              => 'partnership',
        'membership'               => 'partnership',
    ];

    public static function type(): string {
        return 'auto_outreach';
    }

    public static function priority(): int {
        return 6;
    }

    public function handle( array $payload, TimeBudget $budget, Container $c ): array {
        $settings = $c->get( Settings::class );
        $result   = [ 'drafted' => 0, 'auto_approved' => 0, 'skipped' => 0, 'campaigns' => [], 'warnings' => [] ];

        if ( ! (bool) $settings->get( 'automation.auto_outreach_drafts', true ) && empty( $payload['manual'] ) ) {
            $result['warnings'][] = 'Automatic outreach drafting is switched off (Settings → Automation).';
            return $this->result( $result );
        }

        $engine     = $c->get( OutreachEngine::class );
        $campaigns  = $c->get( Campaigns::class );
        $prospects  = $c->get( Prospects::class );
        $contacts   = $c->get( Contacts::class );
        $messages   = $c->get( OutreachMessages::class );
        $compliance = $c->get( Compliance::class );
        $logger     = $c->get( Logger::class );

        $minScore = (int) $settings->get( 'automation.min_score_auto_queue', 70 );
        $autosend = (bool) $settings->get( 'automation.outreach_autosend', false );
        $sender   = $compliance->senderReady();
        if ( $autosend && ! $sender['ok'] ) {
            $result['warnings'][] = 'Outreach autosend is enabled but the sender identity is incomplete: ' . implode( ', ', $sender['missing'] ) . '.';
        }

        $approaches = Templates::approaches();
        $candidates = $prospects->findBy(
            [ 'status' => [ Prospects::STATUS_QUALIFIED, Prospects::STATUS_APPROVED ] ],
            'score',
            'DESC',
            40
        );

        foreach ( $candidates as $prospect ) {
            if ( ! $budget->has( 4 ) ) {
                break;
            }
            try {
                $approach = self::APPROACH_FOR_TYPE[ (string) $prospect['opportunity_type'] ] ?? '';
                if ( '' === $approach || ! isset( $approaches[ $approach ] ) ) {
                    continue; // Form-first prospects belong to the submission engine.
                }
                $score = isset( $prospect['score'] ) && null !== $prospect['score'] ? (int) $prospect['score'] : 0;
                if ( $score < $minScore ) {
                    continue;
                }
                if ( [] === $contacts->forProspect( (int) $prospect['id'], true ) ) {
                    $result['skipped']++;
                    continue;
                }
                $campaignId = $this->evergreenCampaign( $campaigns, $engine, $approach, (string) $approaches[ $approach ], $autosend && $sender['ok'] );
                if ( $campaignId <= 0 ) {
                    continue;
                }
                $result['campaigns'][ $approach ] = $campaignId;
                $messageId = $engine->draftForProspect( $campaignId, (int) $prospect['id'], 0, false );
                if ( null === $messageId ) {
                    $result['skipped']++;
                    continue;
                }
                $result['drafted']++;
                if ( $autosend && $sender['ok'] ) {
                    $approved = $engine->approveMessage( $messageId, 0 );
                    if ( ! empty( $approved['ok'] ) ) {
                        $result['auto_approved']++;
                    } else {
                        $messages->setStatus( $messageId, OutreachMessages::STATUS_DRAFT );
                    }
                }
            } catch ( Throwable $e ) {
                $logger->warning( 'automation', 'Auto outreach failed for one prospect: ' . $e->getMessage(), [ 'prospect_id' => (int) ( $prospect['id'] ?? 0 ) ] );
                $result['skipped']++;
            }
        }
        return $this->result( $result );
    }

    /** Find or create the evergreen campaign for an approach; approve it only in the autosend path. */
    private function evergreenCampaign( Campaigns $campaigns, OutreachEngine $engine, string $approach, string $label, bool $autosend ): int {
        $name = 'Auto: ' . $label;
        $row  = $campaigns->findOneBy( [ 'approach' => $approach, 'name' => $name ] );
        $id   = null !== $row ? (int) $row['id'] : 0;
        if ( 0 === $id ) {
            $id = $engine->createCampaign(
                $name,
                $approach,
                [
                    'daily_limit'  => 5,
                    'follow_ups'   => true,
                    'auto_created' => true,
                    'notes'        => 'Created automatically for hands-off drafting. Drafts wait for approval unless outreach autosend is enabled.',
                ],
                0
            );
        }
        if ( $id > 0 && $autosend ) {
            $row = $campaigns->find( $id );
            if ( null !== $row && in_array( (string) $row['status'], [ Campaigns::STATUS_DRAFT, Campaigns::STATUS_PENDING_APPROVAL, Campaigns::STATUS_PAUSED ], true ) ) {
                $engine->approveCampaign( $id, 0 );
            }
        }
        return $id;
    }
}
