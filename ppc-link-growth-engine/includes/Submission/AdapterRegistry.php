<?php
/**
 * Registry of submission adapters (extensible through the "ppclg_submission_adapters" filter).
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Submission;

use PPC\LinkGrowth\Analysis\FormDetector;
use PPC\LinkGrowth\Analysis\PageAnalyzer;
use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Content\ClaimsFilter;
use PPC\LinkGrowth\Submission\Adapters\AdapterInterface;
use PPC\LinkGrowth\Submission\Adapters\ConfiguredApiAdapter;
use PPC\LinkGrowth\Submission\Adapters\GenericFormAdapter;
use PPC\LinkGrowth\Submission\Adapters\ManualPackageAdapter;
use PPC\LinkGrowth\Support\Http\Client;
use PPC\LinkGrowth\Support\Http\Response;
use PPC\LinkGrowth\Support\Http\RobotsTxt;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Support\Settings;
use Throwable;

final class AdapterRegistry {

    public const FILTER = 'ppclg_submission_adapters';

    public const ID_MANUAL = 'manual_package';
    public const ID_FORM   = 'generic_form';
    public const ID_API    = 'configured_api';

    /** @var array<string,AdapterInterface>|null */
    private ?array $adapters = null;

    public function __construct( private Container $c ) {}

    /** @return array<string,AdapterInterface> id => adapter */
    public function all(): array {
        if ( null !== $this->adapters ) {
            return $this->adapters;
        }
        $list = [ new ManualPackageAdapter() ];
        try {
            // The claims filter is the adapters' last check before anything is sent.
            $claims = $this->c->has( ClaimsFilter::class ) ? $this->c->get( ClaimsFilter::class ) : null;
            $list[] = new GenericFormAdapter(
                $this->c->get( Client::class ),
                $this->c->get( PageAnalyzer::class ),
                $this->c->get( RobotsTxt::class ),
                $this->c->get( Settings::class ),
                $this->c->get( Logger::class ),
                $claims instanceof ClaimsFilter ? $claims : null
            );
            $list[] = new ConfiguredApiAdapter(
                $this->c->get( Client::class ),
                $this->c->get( Secrets::class ),
                $this->c->get( Settings::class ),
                $this->c->get( Logger::class ),
                $claims instanceof ClaimsFilter ? $claims : null
            );
        } catch ( Throwable $e ) {
            // A missing dependency must never take the module down: manual packaging keeps working.
            try {
                $this->c->get( Logger::class )->error( 'submission', 'Could not build submission adapters', [ 'error' => $e->getMessage() ] );
            } catch ( Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
                // Nothing else to do.
            }
        }

        /**
         * Filter the submission adapters.
         *
         * @param AdapterInterface[] $list
         * @param Container          $container
         */
        $filtered = apply_filters( self::FILTER, $list, $this->c );

        $out = [];
        foreach ( ( is_array( $filtered ) ? $filtered : $list ) as $adapter ) {
            if ( $adapter instanceof AdapterInterface ) {
                $out[ $adapter->id() ] = $adapter;
            }
        }
        if ( ! isset( $out[ self::ID_MANUAL ] ) ) {
            $out[ self::ID_MANUAL ] = new ManualPackageAdapter();
        }
        $this->adapters = $out;
        return $out;
    }

    public function get( string $id ): ?AdapterInterface {
        return $this->all()[ $id ] ?? null;
    }

    /**
     * Pick the adapter for a prospect/package: configured_api when a partner API endpoint is set,
     * generic_form when a POST listing form is known, otherwise manual_package.
     *
     * @param array<string,mixed>      $prospect
     * @param array<string,mixed>      $payload
     * @param array<string,mixed>|null $form
     */
    public function resolve( array $prospect, array $payload, ?array $form ): AdapterInterface {
        $all = $this->all();

        if ( null !== Policy::apiConfig( $prospect ) && isset( $all[ self::ID_API ] ) && $all[ self::ID_API ]->supports( $prospect, $payload ) ) {
            return $all[ self::ID_API ];
        }

        $summary = is_array( $payload['form'] ?? null ) ? $payload['form'] : [];
        $kind    = (string) ( $form['kind'] ?? ( $summary['kind'] ?? '' ) );
        $method  = strtoupper( (string) ( $form['method'] ?? ( $summary['method'] ?? '' ) ) );
        if ( FormDetector::KIND_LISTING === $kind && 'POST' === $method && isset( $all[ self::ID_FORM ] ) && $all[ self::ID_FORM ]->supports( $prospect, $payload ) ) {
            return $all[ self::ID_FORM ];
        }

        return $all[ self::ID_MANUAL ];
    }

    /** Submission "method" column value for an adapter: api | form | manual. */
    public function methodFor( AdapterInterface|string $adapter ): string {
        $id = $adapter instanceof AdapterInterface ? $adapter->id() : $adapter;
        return match ( $id ) {
            self::ID_API  => 'api',
            self::ID_FORM => 'form',
            default       => 'manual',
        };
    }

    /**
     * robots.txt fetcher for Policy::evaluate (the engine has no HTTP client of its own).
     *
     * @return callable(string):Response
     */
    public function robotsFetcher(): callable {
        $client = $this->c->get( Client::class );
        return static fn( string $url ): Response => $client->rawRequest( 'GET', $url, [ 'timeout' => 8 ] );
    }

    /** Listing verifier used by SubmissionEngine::verifyDue(). */
    public function verifier(): ListingVerifier {
        return $this->c->get( ListingVerifier::class );
    }
}
