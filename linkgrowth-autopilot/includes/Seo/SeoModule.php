<?php
/**
 * Module `seo` (DESIGN.md §3.2 On-site SEO row): the site audit, IndexNow, the render check and internal links; the
 * seo settings and jobs, the On-site SEO page and its controller, and the IndexNow hooks (the key file on front-end
 * requests and the queue of changed URLs).
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Seo;

use LinkGrowth\Autopilot\Admin\Capabilities;
use LinkGrowth\Autopilot\Admin\Rest\SeoController;
use LinkGrowth\Autopilot\Automation\Audit;
use LinkGrowth\Autopilot\Automation\Frequency;
use LinkGrowth\Autopilot\Automation\Jobs\IndexNowJob;
use LinkGrowth\Autopilot\Automation\Jobs\InternalLinksJob;
use LinkGrowth\Autopilot\Automation\Jobs\SiteAuditJob;
use LinkGrowth\Autopilot\Automation\TaskDefinition;
use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Core\AbstractModule;
use LinkGrowth\Autopilot\Plugin;
use LinkGrowth\Autopilot\Support\Http\Client;
use LinkGrowth\Autopilot\Support\Locks;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\SettingsSchema;

final class SeoModule extends AbstractModule {

    public const ID = 'seo';

    public function id(): string {
        return self::ID;
    }

    public function services( Container $c ): void {
        // The due-checks of this module's tasks (Automation\TaskRegistry resolves them by name).
        $c->singleton( DueChecks::class, static fn( Container $c ) => new DueChecks( $c ) );
        // The audit and the link suggestions only read content; links are applied by an admin's Apply click, or under
        // Autopilot / seo.auto_apply_links after RenderCheck confirmed the page shows the words.
        $c->singleton( SiteAuditor::class, static fn( Container $c ) => new SiteAuditor( $c->get( Settings::class ), $c->get( Logger::class ), $c->get( Locks::class ), $c->get( Client::class ) ) );
        $c->singleton( IndexNow::class, static fn( Container $c ) => new IndexNow( $c->get( Settings::class ), $c->get( Logger::class ), $c->get( Client::class ), $c->get( Locks::class ), $c->get( Audit::class ) ) );
        $c->singleton( RenderCheck::class, static fn( Container $c ) => new RenderCheck( $c->get( Settings::class ), $c->get( Logger::class ), $c->get( Client::class ) ) );
        $c->singleton( InternalLinker::class, static fn( Container $c ) => new InternalLinker( $c->get( Settings::class ), $c->get( Logger::class ), $c->get( Locks::class ), $c->get( Audit::class ), $c->get( RenderCheck::class ) ) );
    }

    public function boot( Plugin $plugin ): void {
        // IndexNow: serve the key file on front-end requests (as early as possible) and track changed URLs. Each
        // callback resolves the service only when needed and never throws.
        add_action( 'parse_request', [ $plugin, 'maybeServeIndexNowKey' ], 0 );
        add_action( 'transition_post_status', [ $plugin, 'onPostTransition' ], 10, 3 );
        add_action( 'save_post', [ $plugin, 'onSavePost' ], 20, 3 );
    }

    public function settingsDefaults(): array {
        return [
            'seo' => [
                'indexnow'           => true,
                'indexnow_key'       => '', // generated once by Seo\IndexNow::key(); never edited through the settings form
                'audit_max_pages'    => 400,
                'audit_http_sample'  => true, // optional HTTP spot check of at most 10 URLs at the end of each audit
                'internal_links'     => true,
                'max_links_per_post' => 3,
                // Apply the best pending internal-link suggestions without a click (always on under Autopilot).
                'auto_apply_links'   => false,
                'auto_apply_per_day' => 20,
                // Post meta key (holding the id of the source-language item) that marks translated items; '' = none.
                'language_counterpart_meta_key' => '',
            ],
        ];
    }

    public function settingsRules(): array {
        return [
            'seo.indexnow'                      => SettingsSchema::bool(),
            // Generated once by Seo\IndexNow: search engines would have to verify a replaced key.
            'seo.indexnow_key'                  => SettingsSchema::readOnly(),
            'seo.audit_max_pages'               => SettingsSchema::int( 20, 5000 ),
            'seo.audit_http_sample'             => SettingsSchema::bool(),
            'seo.internal_links'                => SettingsSchema::bool(),
            'seo.max_links_per_post'            => SettingsSchema::int( 1, 10 ),
            'seo.auto_apply_links'              => SettingsSchema::bool(),
            'seo.auto_apply_per_day'            => SettingsSchema::int( 1, 100 ),
            // '' = no counterpart meta is read (Seo\PostLanguage); otherwise letters, digits and underscores only.
            'seo.language_counterpart_meta_key' => SettingsSchema::string( [ 'trim' => true, 'pattern' => PostLanguage::COUNTERPART_KEY_PATTERN ] ),
        ];
    }

    public function jobs(): array {
        return [
            'site_audit'     => SiteAuditJob::class,
            'indexnow'       => IndexNowJob::class,
            'internal_links' => InternalLinksJob::class,
        ];
    }

    /**
     * The on-site SEO tasks, with the engine's cadences (DESIGN.md §5.1; Automation\TaskDefinition explains the WP2 values).
     *
     * @return list<TaskDefinition>
     */
    public function tasks(): array {
        return [
            new TaskDefinition( id: 'site_audit', jobType: 'site_audit', label: __( 'Audit the site', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::DAILY, module: '', dueCheck: null, priority: SiteAuditJob::priority(), requiresProfile: false, order: 170 ),
            new TaskDefinition( id: 'indexnow', jobType: 'indexnow', label: __( 'Submit changed URLs (IndexNow)', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::HOURLY, module: '', dueCheck: 'indexnowDue', priority: IndexNowJob::priority(), outbound: true, cadence: 'hourly (when URLs are waiting)', order: 180 ),
            new TaskDefinition( id: 'internal_links', jobType: 'internal_links', label: __( 'Suggest internal links', 'linkgrowth-autopilot' ), defaultFrequency: Frequency::WEEKLY, module: '', dueCheck: null, priority: InternalLinksJob::priority(), order: 190, schedule: 'internalLinksSchedule' ),
        ];
    }

    public function restControllers(): array {
        return [ SeoController::class ];
    }

    public function adminPages(): array {
        return [
            'seo' => [ 'label' => __( 'On-site SEO', 'linkgrowth-autopilot' ), 'icon' => 'search', 'module' => 'pages/seo.js', 'cap' => Capabilities::CAP, 'order' => 60 ],
        ];
    }
}
