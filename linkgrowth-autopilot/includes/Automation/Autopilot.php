<?php
/**
 * Autopilot: the one switch that lets the engine work without per-item approval.
 *
 * This class is the single source of truth for the "who approves what" policy. Every code path that used to read
 * automation.outreach_autosend, outreach.require_approval, automation.auto_outreach_drafts, automation.submissions or
 * automation.submission_mode asks it for the effective value instead:
 *
 * - Off (the default): exactly the behaviour of the individual settings, unchanged.
 * - On: outreach drafts are approved automatically, campaigns (the owner's and the evergreen "Auto:" ones) are
 *   approved automatically, approved email is sent automatically inside the daily/weekly caps and the send window,
 *   automated listing submissions run in "auto" mode, auto-drafting runs, the best internal-link suggestions are
 *   applied automatically (seo.auto_apply_per_day a day, undoable in one click) and, while the owner lists no
 *   competitors, competitors are found through SE Ranking (Discovery\CompetitorBacklinkSource). Follow-ups are sent automatically only
 *   when reply tracking (the inbound webhook secret) is configured; without it a follow-up could reach someone who
 *   already replied, so follow-ups stay drafts.
 *
 * The hard limits never change with the switch (see hardLimits()): no sign-ups or account creation on third-party
 * sites, no CAPTCHA / login / registration bypass, no comment or forum posting, robots.txt, the claims filter,
 * CASL / CAN-SPAM (identification, working reply address, postal address, unsubscribe honoured permanently, only
 * conspicuously published business addresses, relevance, the contact cool-down, the suppression list), the caps and
 * send window, the storefront gate and no paid listings. Those are enforced where they always were (Compliance,
 * Submission\Policy, the adapters); this class only removes the human approval step.
 *
 * Turning it on needs explicit confirmation and records the owner's consent (option + audit log: user, time, policy
 * text version). Turning it off stops automatic sending at once and returns messages Autopilot approved but did not
 * send yet to draft.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Automation;

use LinkGrowth\Autopilot\Database\Schema;
use LinkGrowth\Autopilot\Plugin;
use LinkGrowth\Autopilot\Repositories\AuditLog;
use LinkGrowth\Autopilot\Repositories\OutreachMessages;
use LinkGrowth\Autopilot\Submission\Policy;
use LinkGrowth\Autopilot\Support\Secrets;
use LinkGrowth\Autopilot\Support\Settings;
use Throwable;

final class Autopilot {

    /** Settings path of the switch. */
    public const SETTING = 'automation.autopilot';

    /** Owner consent record (non-autoloaded option; never written through POST /settings). */
    public const OPTION_CONSENT = 'lga_autopilot_consent';

    /**
     * Version of policyText(); stored with the consent so a changed policy can be told apart. 1.2.2-1 added saving a
     * page as the consenting owner's account (markup kses would strip) and taking automatic links back out of pages
     * that do not display them: InternalLinker saves as the owner only on a consent given under this version.
     */
    public const POLICY_VERSION = '1.2.2-1';

    /** Audit actor of everything Autopilot approves on the owner's behalf. */
    public const ACTOR = 'system:autopilot';

    /** Audit action written when Autopilot queues a follow-up for sending. */
    public const ACTION_FOLLOWUP = 'outreach.followup_scheduled';

    /** last_error of a message returned to draft when Autopilot is switched off. */
    public const REVERTED_NOTE = 'Returned to draft: Autopilot was switched off before this message was sent.';

    /** Automation flags whose effective value Autopilot raises to "on". */
    private const RAISED_FLAGS = [ 'submissions', 'outreach_autosend', 'auto_outreach_drafts' ];

    public function __construct(
        private Settings $settings,
        private ?Secrets $secrets = null,
        private ?AuditLog $audit = null
    ) {}

    // ------------------------------------------------------------------
    // Effective policy
    // ------------------------------------------------------------------

    public function isOn(): bool {
        return (bool) $this->settings->get( self::SETTING, false );
    }

    /** Effective automation.outreach_autosend: approved and scheduled email goes out without a click. */
    public function outreachAutosend(): bool {
        return $this->isOn() || (bool) $this->settings->get( 'automation.outreach_autosend', false );
    }

    /** Effective outreach.require_approval: whether automation is refused when it tries to approve a message. */
    public function requireApproval(): bool {
        return ! $this->isOn() && (bool) $this->settings->get( 'outreach.require_approval', true );
    }

    /** Automation may approve first-contact drafts (Autopilot on, or autosend on with individual approval off). */
    public function autoApproveMessages(): bool {
        return $this->outreachAutosend() && ! $this->requireApproval();
    }

    /** Automation may approve the evergreen "Auto:" campaigns (as before: whenever autosend is effectively on). */
    public function autoApproveAutoCampaigns(): bool {
        return $this->outreachAutosend();
    }

    /** Automation may approve campaigns the owner created (only under Autopilot). */
    public function autoApproveOwnerCampaigns(): bool {
        return $this->isOn();
    }

    /** Effective automation.auto_outreach_drafts. */
    public function autoDrafts(): bool {
        return $this->isOn() || (bool) $this->settings->get( 'automation.auto_outreach_drafts', true );
    }

    /** Effective automation.submissions. */
    public function submissionsEnabled(): bool {
        return $this->isOn() || (bool) $this->settings->get( 'automation.submissions', false );
    }

    /** Effective automation.submission_mode ('auto' under Autopilot; otherwise the normalised setting). */
    public function submissionMode(): string {
        if ( $this->isOn() ) {
            return Policy::MODE_AUTO;
        }
        $mode = strtolower( trim( (string) $this->settings->get( 'automation.submission_mode', 'auto' ) ) );
        return in_array( $mode, Policy::SUBMISSION_MODES, true ) ? $mode : Policy::MODE_AUTO;
    }

    /** Effective value of automation.{flag} (Pipeline cadences, job gates, status payloads). */
    public function flag( string $flag ): bool {
        if ( $this->isOn() && in_array( $flag, self::RAISED_FLAGS, true ) ) {
            return true;
        }
        $default = 'auto_outreach_drafts' === $flag;
        return (bool) $this->settings->get( 'automation.' . $flag, $default );
    }

    /** Effective seo.auto_apply_links: pending internal-link suggestions are applied without a click. */
    public function autoApplyLinks(): bool {
        return $this->isOn() || (bool) $this->settings->get( 'seo.auto_apply_links', false );
    }

    /** Most internal links applied automatically per day (seo.auto_apply_per_day, 1–100, default 20). */
    public function linksPerDay(): int {
        $n = (int) $this->settings->get( 'seo.auto_apply_per_day', 20 );
        return max( 1, min( 100, $n > 0 ? $n : 20 ) );
    }

    /** Competitors are found automatically (Autopilot on and the owner's competitor list empty). */
    public function autoDetectCompetitors(): bool {
        return $this->isOn() && [] === array_filter( array_map( 'strval', (array) $this->settings->get( 'discovery.competitor_domains', [] ) ), static fn( string $d ): bool => '' !== trim( $d ) );
    }

    /** Reply tracking is configured: the inbound webhook secret exists, so replies stop follow-ups automatically. */
    public function replyTracking(): bool {
        try {
            $secrets = $this->secrets ?? ( class_exists( Plugin::class ) ? Plugin::instance()->get( Secrets::class ) : null );
            return $secrets instanceof Secrets && '' !== $secrets->get( 'inbound_webhook_secret' );
        } catch ( Throwable $e ) {
            return false;
        }
    }

    /**
     * Whether a follow-up in a campaign with these settings is queued for sending without review.
     * Off: autosend on AND the campaign set to "Auto-approve follow-ups" (unchanged). On: only with reply tracking.
     *
     * @param array<string,mixed> $campaignSettings
     */
    public function autoFollowUp( array $campaignSettings ): bool {
        if ( $this->isOn() ) {
            return $this->replyTracking();
        }
        return (bool) $this->settings->get( 'automation.outreach_autosend', false )
            && ! empty( $campaignSettings['auto_follow_up'] )
            && filter_var( $campaignSettings['auto_follow_up'], FILTER_VALIDATE_BOOLEAN );
    }

    /** Plain explanation of what happens to follow-ups under the current policy. */
    public function followUpNote(): string {
        if ( ! $this->isOn() ) {
            return 'Follow-ups are drafts for your review unless outreach autosend is on and the campaign auto-approves follow-ups.';
        }
        if ( $this->replyTracking() ) {
            return 'Follow-ups are sent automatically: reply tracking is set up, so anyone who answered is never followed up.';
        }
        return 'Follow-ups are not sent automatically: reply tracking (the inbound webhook secret under Settings → Integrations → Email, RDAP & inbound replies) is not set up, so the engine could follow up someone who already replied. They wait as drafts for your approval; once reply tracking is set up, Autopilot approves and sends the waiting ones too.';
    }

    // ------------------------------------------------------------------
    // Switching
    // ------------------------------------------------------------------

    /** @return array{user_id:int,at:string,policy_version:string}|null */
    public function consent(): ?array {
        $c = get_option( self::OPTION_CONSENT, null );
        if ( ! is_array( $c ) || empty( $c['at'] ) ) {
            return null;
        }
        return [
            'user_id'        => (int) ( $c['user_id'] ?? 0 ),
            'at'             => (string) $c['at'],
            'policy_version' => (string) ( $c['policy_version'] ?? '' ),
        ];
    }

    /**
     * Switches Autopilot on (the caller has already obtained explicit confirmation) and records the consent.
     * Also switches the background automation master switch on: Autopilot without a running scheduler does nothing.
     *
     * @return array<string,mixed> status()
     */
    public function enable( int $userId ): array {
        $was = $this->isOn();
        $now = gmdate( 'c' );
        $this->settings->update( [ 'automation' => [ 'autopilot' => true, 'enabled' => true ] ] );
        update_option(
            self::OPTION_CONSENT,
            [
                'user_id'        => $userId,
                'at'             => $now,
                'policy_version' => self::POLICY_VERSION,
                'policy_hash'    => hash( 'sha256', self::policyText() ),
            ],
            false
        );
        $this->record( $userId > 0 ? 'user:' . $userId : AuditLog::currentActor(), 'autopilot.enabled', [ 'was_on' => $was, 'policy_version' => self::POLICY_VERSION, 'consented_at' => $now ] );
        return $this->status();
    }

    /**
     * Switches Autopilot off: automatic sending stops at once and messages Autopilot approved but did not send yet go
     * back to draft (messages a person approved stay approved).
     *
     * @return array<string,mixed> status() + reverted
     */
    public function disable( int $userId ): array {
        $was = $this->isOn();
        $this->settings->set( self::SETTING, false );
        $reverted = $this->revertAutoApproved();
        $this->record( $userId > 0 ? 'user:' . $userId : AuditLog::currentActor(), 'autopilot.disabled', [ 'was_on' => $was, 'reverted_to_draft' => $reverted ] );
        return array_merge( $this->status(), [ 'reverted' => $reverted ] );
    }

    /**
     * Returns to draft every approved / scheduled, not yet sent message whose latest approval was made by Autopilot.
     * The compare on status keeps a message that is being sent right now ("sending") untouched.
     */
    public function revertAutoApproved(): int {
        global $wpdb;
        try {
            $m   = Schema::table( 'outreach_messages' );
            $a   = Schema::table( 'audit_log' );
            $sql = "SELECT m.id FROM {$m} m WHERE m.status IN (%s,%s) AND m.sent_at IS NULL"
                . " AND ( SELECT a.actor FROM {$a} a WHERE a.object_type = %s AND a.object_id = m.id AND a.action IN (%s,%s) ORDER BY a.id DESC LIMIT 1 ) = %s";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $ids = $wpdb->get_col( $wpdb->prepare( $sql, OutreachMessages::STATUS_APPROVED, OutreachMessages::STATUS_SCHEDULED, 'outreach_message', 'outreach.message_approved', self::ACTION_FOLLOWUP, self::ACTOR ) );
            $n   = 0;
            foreach ( array_map( 'intval', (array) $ids ) as $id ) {
                $sql = "UPDATE {$m} SET status = %s, last_error = %s, updated_at = %s WHERE id = %d AND status IN (%s,%s)";
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $n += (int) $wpdb->query( $wpdb->prepare( $sql, OutreachMessages::STATUS_DRAFT, self::REVERTED_NOTE, gmdate( 'Y-m-d H:i:s' ), $id, OutreachMessages::STATUS_APPROVED, OutreachMessages::STATUS_SCHEDULED ) );
            }
            return $n;
        } catch ( Throwable $e ) {
            return 0;
        }
    }

    // ------------------------------------------------------------------
    // Reporting
    // ------------------------------------------------------------------

    /**
     * Everything the dashboard needs to describe the switch truthfully.
     *
     * @return array<string,mixed>
     */
    public function status(): array {
        return [
            'enabled'            => $this->isOn(),
            'automation_enabled' => (bool) $this->settings->get( 'automation.enabled', false ),
            'consent'            => $this->consent(),
            // A consent given under an earlier policy text keeps Autopilot running, but does not cover saving a page
            // as the owner's account (InternalLinker::autoOwner()): switching Autopilot off and on again gives it.
            'consent_current'    => self::POLICY_VERSION === (string) ( $this->consent()['policy_version'] ?? '' ),
            'policy_version'     => self::POLICY_VERSION,
            'policy_text'        => self::policyText(),
            'hard_limits'        => self::hardLimits(),
            'owner_tasks_note'   => self::ownerTasksNote(),
            'will_run'           => self::willRun( $this->numbers() ),
            'numbers'            => $this->numbers(),
            'reply_tracking'     => $this->replyTracking(),
            'follow_ups'         => [ 'automatic' => $this->isOn() && $this->replyTracking(), 'note' => $this->followUpNote() ],
            'effective'          => [
                'outreach_autosend'           => $this->outreachAutosend(),
                'require_approval'            => $this->requireApproval(),
                'auto_approve_messages'       => $this->autoApproveMessages(),
                'auto_approve_owner_campaigns' => $this->autoApproveOwnerCampaigns(),
                'auto_outreach_drafts'        => $this->autoDrafts(),
                'submissions'                 => $this->submissionsEnabled(),
                'submission_mode'             => $this->submissionMode(),
                'auto_apply_links'            => $this->autoApplyLinks(),
                'links_per_day'               => $this->linksPerDay(),
            ],
        ];
    }

    /**
     * The caps quoted to the owner before switching on.
     *
     * @return array{emails_per_day:int,emails_per_week:int,submissions_per_day:int,links_per_day:int}
     */
    public function numbers(): array {
        return [
            'emails_per_day'      => max( 0, (int) $this->settings->get( 'automation.daily_limits.outreach_emails', 10 ) ),
            'emails_per_week'     => max( 0, (int) $this->settings->get( 'automation.weekly_limits.outreach_emails', 40 ) ),
            'submissions_per_day' => max( 0, (int) $this->settings->get( 'automation.daily_limits.submissions', 5 ) ),
            'links_per_day'       => $this->linksPerDay(),
        ];
    }

    /**
     * What runs on its own once Autopilot is on, in plain words with the current caps (the confirm dialog lists it).
     *
     * @param array{emails_per_day:int,emails_per_week:int,submissions_per_day:int,links_per_day:int} $n
     * @return string[]
     */
    public static function willRun( array $n ): array {
        $emails = (int) ( $n['emails_per_day'] ?? 0 );
        $week   = (int) ( $n['emails_per_week'] ?? 0 );
        $subs   = (int) ( $n['submissions_per_day'] ?? 0 );
        $links  = (int) ( $n['links_per_day'] ?? 0 );
        return [
            sprintf( 'Send outreach emails in your name: up to %d a day%s, only inside the send window, to the published business addresses of relevant websites.', $emails, $week > 0 ? sprintf( ' and %d a week', $week ) : '' ),
            sprintf( 'Add up to %d internal %s a day between your own pages (WordPress keeps a revision of each page, and “Undo automatic links” removes them).', $links, 1 === $links ? 'link' : 'links' ),
            sprintf( 'Submit listings to open public forms that allow it and pass every safety check (up to %d a day).', $subs ),
            'Discover new prospects, evaluate them and monitor your backlinks on schedule; while you list no competitors, find them through SE Ranking and mine their backlinks.',
        ];
    }

    /** What the owner agrees to by switching Autopilot on (versioned by POLICY_VERSION). */
    public static function policyText(): string {
        return 'Turn on Autopilot? The engine will then work without asking you first: it approves the outreach emails it drafts and your campaigns, sends them in your name to the published business addresses of relevant websites (inside the send window and the daily and weekly limits), submits listings to sites whose public listing form passes every safety check, adds the best internal links between your own pages (up to the daily number in Settings, each page keeps a revision and one click undoes them), finds your competitors through SE Ranking while you list none, and keeps doing this on its schedule. To add a link to a page holding markup only an administrator may save (such as inline SVG diagrams or structured data), it saves that one change as your account, and it takes its own automatic links back out of pages whose template doesn’t display them. Follow-ups are sent automatically only when reply tracking is set up. The hard limits stay on: no sign-ups or account creation on other sites, no CAPTCHA, login or registration bypass, no comment or forum posting, robots.txt is respected, the claims filter (research use only, no health, medical or dosing claims), CASL and CAN-SPAM rules (sender identification, a working reply address, your postal address, every unsubscribe honoured permanently, only conspicuously published business addresses, relevance, the contact cool-down and the suppression list), the storefront compliance gate and no paid listings. You can switch Autopilot off at any time; messages it approved but did not send yet go back to draft.';
    }

    /** @return string[] The rules Autopilot never relaxes. */
    public static function hardLimits(): array {
        return [
            'No account creation or sign-ups on third-party sites.',
            'No CAPTCHA, login-wall or registration bypass.',
            'No blog comment or forum posting.',
            'robots.txt is respected for every crawl and submission.',
            'Claims filter: research use only, no health, medical or dosing claims.',
            'CASL / CAN-SPAM: sender identification, a working reply address and your postal address in every email.',
            'Every unsubscribe is honoured permanently; suppressed addresses and domains are never contacted.',
            'Only conspicuously published business addresses of relevant websites are emailed.',
            'The contact cool-down (minimum days between contacts) and one open message per website.',
            'Daily and weekly limits and the send window.',
            'The storefront compliance gate (unless you acknowledged its findings).',
            'No paid listings.',
        ];
    }

    /** Honest note about the work that stays with a person. */
    public static function ownerTasksNote(): string {
        return 'Sites that need an account, a login, a CAPTCHA or a paid plan stay owner tasks: Autopilot never signs up anywhere. You can hand them to a staff member with the LinkGrowth Submitter role.';
    }

    /** @param array<string,mixed> $details */
    private function record( string $actor, string $action, array $details ): void {
        try {
            $audit = $this->audit ?? new AuditLog();
            $audit->record( $actor, $action, 'settings', 0, $details );
        } catch ( Throwable $e ) {
            unset( $e );
        }
    }
}
