<?php
/**
 * Module `analysis` (DESIGN.md §3.2 Scoring (and analysis) row): page and site analysis, topical taxonomy, relevance,
 * risk, form detection, submission-page finding and contact extraction.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Analysis;

use LinkGrowth\Autopilot\Container;
use LinkGrowth\Autopilot\Core\AbstractModule;
use LinkGrowth\Autopilot\Integrations\Rdap;
use LinkGrowth\Autopilot\Repositories\Suppression;
use LinkGrowth\Autopilot\Support\Http\Client;
use LinkGrowth\Autopilot\Support\Logger;
use LinkGrowth\Autopilot\Support\Settings;

final class AnalysisModule extends AbstractModule {

    public const ID = 'analysis';

    public function id(): string {
        return self::ID;
    }

    public function services( Container $c ): void {
        $c->singleton( Taxonomy::class, static fn( Container $c ) => new Taxonomy( $c->get( Settings::class ) ) );
        $c->singleton( PageAnalyzer::class, static fn() => new PageAnalyzer() );
        $c->singleton( FormDetector::class, static fn() => new FormDetector() );
        $c->singleton( ContactExtractor::class, static fn( Container $c ) => new ContactExtractor( $c->get( Suppression::class ) ) );
        $c->singleton( RelevanceScorer::class, static fn( Container $c ) => new RelevanceScorer( $c->get( Taxonomy::class ) ) );
        $c->singleton( RiskAnalyzer::class, static fn( Container $c ) => new RiskAnalyzer( $c->get( Taxonomy::class ) ) );
        $c->singleton( SubmissionPageFinder::class, static fn( Container $c ) => new SubmissionPageFinder( $c->get( Client::class ), $c->get( PageAnalyzer::class ), $c->get( FormDetector::class ), $c->get( Logger::class ) ) );
        $c->singleton( SiteProfiler::class, static fn( Container $c ) => new SiteProfiler( $c->get( Client::class ), $c->get( PageAnalyzer::class ), $c->get( ContactExtractor::class ), $c->get( FormDetector::class ), $c->get( Rdap::class ), $c->get( Logger::class ), $c->get( Settings::class ) ) );
    }
}
