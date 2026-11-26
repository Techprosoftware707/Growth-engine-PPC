<?php
/**
 * Module `content` (DESIGN.md §3.2 Content row): the verified facts registry, the claims filter, linkable assets and
 * the content generator; the Content & facts settings tab.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Content;

use LinkGrowth\Autopilot\Analysis\Taxonomy;
use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Core\AbstractModule;
use LinkGrowth\Autopilot\Integrations\AiWriter;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\SettingsSchema;

final class ContentModule extends AbstractModule {

    public const ID = 'content';

    public function id(): string {
        return self::ID;
    }

    public function services( Container $c ): void {
        $c->singleton( FactsRegistry::class, static fn( Container $c ) => new FactsRegistry( $c->get( Settings::class ) ) );
        $c->singleton( ClaimsFilter::class, static fn( Container $c ) => new ClaimsFilter( $c->get( FactsRegistry::class ) ) );
        $c->singleton( LinkableAssets::class, static fn( Container $c ) => new LinkableAssets( $c->get( Settings::class ), $c->get( Taxonomy::class ) ) );
        $c->singleton( ContentGenerator::class, static fn( Container $c ) => new ContentGenerator( $c->get( FactsRegistry::class ), $c->get( ClaimsFilter::class ), $c->get( Settings::class ), $c->get( AiWriter::class ), $c->get( Logger::class ) ) );
    }

    /**
     * The optional list of linkable assets (Content\LinkableAssets reads it; the defaults do not list it). Each asset
     * needs a URL and a title; POST /settings also runs every asset through the claims filter.
     */
    public function settingsRules(): array {
        return [
            'content.linkable_assets' => SettingsSchema::optional(
                SettingsSchema::records(
                    [
                        'url'     => [ 'required' => true ] + SettingsSchema::url(),
                        'title'   => [ 'required' => true ] + SettingsSchema::text( 190 ),
                        'summary' => SettingsSchema::textarea( 1000 ),
                        'topics'  => SettingsSchema::list( 'string', [ 'max_items' => 30, 'item_max' => 60, 'case' => 'lower', 'unique' => 'string' ] ),
                        'kind'    => [ 'default' => 'guide' ] + SettingsSchema::string( [ 'sanitize' => 'key' ] ),
                    ],
                    100
                ),
                []
            ),
        ];
    }

    public function settingsTabs(): array {
        return [
            'content' => [ 'label' => __( 'Content & facts', 'linkgrowth-autopilot' ), 'module' => 'settings/content.js', 'order' => 60 ],
        ];
    }
}
