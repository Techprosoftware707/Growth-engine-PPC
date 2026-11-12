<?php
/**
 * Verified opportunity database: imports data/verified-opportunities.json (researched, human-verified listing
 * and outreach opportunities) as prospects plus ONE owner task per item.
 *
 * An owner task is a submissions row with status needs_manual, method manual, adapter manual_package and a
 * payload.package carrying the listing kit, the steps, the storefront gate note and the verification evidence.
 * Nothing is submitted, emailed or fetched here: the import only reads the bundled file and writes local rows
 * (ProspectIntake::fromUrl() makes no HTTP requests). Outreach-class items become owner tasks too; no outreach
 * message or contact is created, the owner decides whether to pitch.
 *
 * The import is version aware (option ppclg_verified_db_version holds the last fully imported data version)
 * and runs in batches of at most 100 items with a cursor (option ppclg_verified_db_state), so a short time
 * budget never times out; the job continues the pass on the next run. Each imported item's fingerprint is kept
 * (option ppclg_verified_db_items), so a new data version re-imports only new or changed items.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Discovery;

use PPC\LinkGrowth\Container;
use PPC\LinkGrowth\Content\ClaimsFilter;
use PPC\LinkGrowth\Content\ContentGenerator;
use PPC\LinkGrowth\Database\Schema;
use PPC\LinkGrowth\Repositories\AuditLog;
use PPC\LinkGrowth\Repositories\Opportunities;
use PPC\LinkGrowth\Repositories\Prospects;
use PPC\LinkGrowth\Repositories\Repository;
use PPC\LinkGrowth\Repositories\Submissions;
use PPC\LinkGrowth\Support\Locks;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\TimeBudget;
use PPC\LinkGrowth\Support\Url;
use Throwable;

final class VerifiedOpportunities {

    /** Value of payload.package.source (and of the prospects' discovered_via) for rows created here. */
    public const SOURCE = 'verified_db';

    /** Data version whose import pass last completed. */
    public const OPTION_VERSION = 'ppclg_verified_db_version';

    /** Cursor and counters of the current (or last) import pass. */
    public const OPTION_STATE = 'ppclg_verified_db_state';

    /** item id => "fingerprint:submission id:prospect id" for every item that has an owner task. */
    public const OPTION_ITEMS = 'ppclg_verified_db_items';

    /** Cached version of the data file (keyed by path, modification time and size). */
    public const OPTION_FILE = 'ppclg_verified_db_file';

    /** Largest batch one import() call processes. */
    public const MAX_BATCH = 100;

    /** Adapter id of manual packages (AdapterRegistry::ID_MANUAL). */
    public const ADAPTER = 'manual_package';

    /** JSON fragment that identifies an owner task's payload (Json::encode does not escape it). */
    public const PAYLOAD_MARKER = '"source":"verified_db"';

    private const LOCK = 'verified_import';

    /** Seconds kept free in the budget for saving the cursor after the last item. */
    private const RESERVE_SECONDS = 1.5;

    /** The cursor is saved after this many items (the import is idempotent, so a crash only repeats work). */
    private const SAVE_EVERY = 10;

    private const MAX_SKIPPED_LIST = 150;

    private const MAX_STEP_LINES = 20;

    /** Words that never appear in generated listing text (the business is a research-use-only reagent supplier). */
    private const BANNED_LISTING_WORDS = '/\b(health|healthcare|medical|medicine|medicinal|hospitals?|clinics?|clinical|pharmacy|pharmacies|pharmaceuticals?|drugs?|supplements?|supplementation|nutraceuticals?|wellness|patients?|therap\w*|cosmetics?|skin\s?care|beauty|anti-?ag(e|ing|eing)|dos(e|es|age|ing))\b/i';

    /** Sentences dropped from a generated description before it is offered for third-party listings. */
    private const DROPPED_SENTENCE = '/\b(crypto\w*|bitcoin)\b/i';

    /** Same research-use-only test the content generator applies. */
    private const RUO_PATTERN = '/research use only|laboratory research (use|purposes) only|not for human/i';

    /** @var array<string,mixed>|null */
    private ?array $data = null;

    private string $loadError = '';

    private bool $locked = false;

    public function __construct(
        private ProspectIntake $intake,
        private Prospects $prospects,
        private Opportunities $opportunities,
        private Submissions $submissions,
        private ClaimsFilter $claims,
        private AuditLog $audit,
        private Logger $logger,
        private ?ContentGenerator $content = null,
        private ?Locks $locks = null,
        private string $file = ''
    ) {
        if ( '' === $this->file ) {
            $this->file = PPCLG_DIR . 'data/verified-opportunities.json';
        }
    }

    /** Builds the service from the container (used until the factory is registered, and by tests). */
    public static function fromContainer( Container $c, string $file = '' ): self {
        $content = null;
        try {
            $content = $c->get( ContentGenerator::class );
        } catch ( Throwable $e ) {
            $content = null; // The kit description is used instead.
        }
        $locks = null;
        try {
            $locks = $c->get( Locks::class );
        } catch ( Throwable $e ) {
            $locks = null;
        }
        return new self(
            $c->get( ProspectIntake::class ),
            $c->get( Prospects::class ),
            $c->get( Opportunities::class ),
            $c->get( Submissions::class ),
            $c->get( ClaimsFilter::class ),
            $c->get( AuditLog::class ),
            $c->get( Logger::class ),
            $content,
            $locks,
            $file
        );
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * @return array{data_version:string,imported_version:string,total:int,imported:int,skipped:int,owner_tasks_open:int,up_to_date:bool,in_progress:bool,cursor:int,available:bool,error:string,skip_reasons:array<string,int>,skipped_items:array<int,array<string,mixed>>,data_counts:array<string,int>,started_at:?string,finished_at:?string,summary:string}
     */
    public function status(): array {
        $data     = $this->data();
        $state    = $this->state();
        $version  = (string) $data['version'];
        $imported = $this->importedVersion();
        $total    = count( $data['items'] );
        $current  = $state['version'] === $version && '' !== $version;
        $running  = $current && null === $state['finished_at'];
        $upToDate = '' !== $version && $imported === $version && ! $running;
        $open     = $this->ownerTasksOpen();
        $out      = [
            'data_version'     => $version,
            'imported_version' => $imported,
            'total'            => $total,
            'imported'         => count( $this->itemMap() ),
            'skipped'          => $current ? (int) $state['counts']['skipped'] : 0,
            'owner_tasks_open' => $open,
            'up_to_date'       => $upToDate,
            'in_progress'      => $running,
            'cursor'           => $current ? (int) $state['cursor'] : 0,
            'available'        => '' === $this->loadError,
            'error'            => $this->loadError,
            'skip_reasons'     => $current ? $state['skip_reasons'] : [],
            'skipped_items'    => $current ? $state['skipped_items'] : [],
            'data_counts'      => $data['counts'],
            'started_at'       => $current ? $state['started_at'] : null,
            'finished_at'      => $current ? $state['finished_at'] : null,
        ];
        $out['summary'] = $this->statusSentence( $out );
        return $out;
    }

    /**
     * True when the bundled data has a version that is not fully imported yet (or a pass is unfinished).
     * Cheap enough for a per-tick due check: the data version is cached per file modification time.
     */
    public function needsImport(): bool {
        $version = $this->dataVersion();
        if ( '' === $version ) {
            return false;
        }
        $state = $this->state();
        if ( $state['version'] === $version && null === $state['finished_at'] ) {
            return true;
        }
        return $this->importedVersion() !== $version;
    }

    /** Version string of the bundled data file ('' when it cannot be read). */
    public function dataVersion(): string {
        if ( null !== $this->data ) {
            return (string) $this->data['version'];
        }
        $mtime = is_readable( $this->file ) ? filemtime( $this->file ) : false;
        if ( false === $mtime ) {
            return (string) $this->data()['version'];
        }
        $key    = md5( $this->file . '|' . $mtime . '|' . (int) filesize( $this->file ) );
        $cached = get_option( self::OPTION_FILE, [] );
        if ( is_array( $cached ) && ( $cached['key'] ?? '' ) === $key && is_string( $cached['version'] ?? null ) ) {
            return $cached['version'];
        }
        $version = (string) $this->data()['version'];
        if ( '' === $this->loadError ) {
            update_option( self::OPTION_FILE, [ 'key' => $key, 'version' => $version ], false );
        }
        return $version;
    }

    /**
     * Imports the next batch of the current pass (starting a pass when the data version changed).
     *
     * @return array{imported:int,skipped:int,existing:int,updated:int,processed:int,done:bool,busy:bool,cursor:int,total:int,owner_tasks_open:int,summary:string,error:string}
     */
    public function import( TimeBudget $budget, int $batch = self::MAX_BATCH ): array {
        $batch = max( 1, min( self::MAX_BATCH, $batch ) );
        $data  = $this->data();
        $total = count( $data['items'] );
        $out   = [ 'imported' => 0, 'skipped' => 0, 'existing' => 0, 'updated' => 0, 'processed' => 0, 'done' => false, 'busy' => false, 'cursor' => 0, 'total' => $total, 'owner_tasks_open' => 0, 'summary' => '', 'error' => '' ];

        if ( '' !== $this->loadError || '' === (string) $data['version'] ) {
            $out['done']    = true;
            $out['error']   = '' !== $this->loadError ? $this->loadError : 'The data file has no version.';
            $out['summary'] = 'The verified opportunity database could not be read: ' . $out['error'];
            $this->logger->error( 'verified', 'Verified opportunity database unreadable', [ 'error' => $out['error'] ] );
            return $out;
        }
        $version = (string) $data['version'];

        if ( ! $this->lock( $budget ) ) {
            $out['busy']    = true;
            $out['summary'] = 'Another import of the verified opportunity database is running; this one was not started.';
            return $out;
        }
        try {
            $state = $this->state();
            if ( $state['version'] === $version && null !== $state['finished_at'] && $this->importedVersion() === $version ) {
                $out['done']             = true;
                $out['cursor']           = $total;
                $out['owner_tasks_open'] = $this->ownerTasksOpen();
                $out['summary']          = sprintf(
                    'The verified opportunity database (version %s) is already imported: %d of %d items have owner tasks, %d skipped; %d owner tasks are open.',
                    $version,
                    count( $this->itemMap() ),
                    $total,
                    (int) $state['counts']['skipped'],
                    $out['owner_tasks_open']
                );
                return $out;
            }
            if ( $state['version'] !== $version || null !== $state['finished_at'] ) {
                $state = self::freshState( $version );
                $this->logger->info( 'verified', 'Verified opportunity import started', [ 'version' => $version, 'items' => $total ] );
            }

            $map     = $this->itemMap();
            $claimed = self::claimedProspects( $map );
            $kit     = $data['kit'];
            $kitHash = substr( sha1( (string) wp_json_encode( [ $kit, $data['gate'] ] ) ), 0, 12 );
            $created = [];

            while ( $state['cursor'] < $total && $out['processed'] < $batch ) {
                if ( ! $budget->has( self::RESERVE_SECONDS ) ) {
                    break;
                }
                $item = $data['items'][ $state['cursor'] ];
                try {
                    $res = $this->importItem( is_array( $item ) ? $item : [], $map, $claimed, $version, $kitHash, $data );
                } catch ( Throwable $e ) {
                    $this->logger->error( 'verified', 'Verified item could not be imported: ' . $e->getMessage(), [ 'item' => is_array( $item ) ? (string) ( $item['id'] ?? '' ) : '', 'at' => basename( $e->getFile() ) . ':' . $e->getLine() ] );
                    $res = [ 'outcome' => 'skipped', 'reason' => 'error', 'submission_id' => 0 ];
                }
                $outcome = $res['outcome'];
                $out[ $outcome ]++;
                $state['counts'][ $outcome ]++;
                if ( 'skipped' === $outcome ) {
                    $reason                           = (string) $res['reason'];
                    $state['skip_reasons'][ $reason ] = (int) ( $state['skip_reasons'][ $reason ] ?? 0 ) + 1;
                    if ( count( $state['skipped_items'] ) < self::MAX_SKIPPED_LIST && is_array( $item ) ) {
                        $state['skipped_items'][] = [
                            'id'       => (string) ( $item['id'] ?? '' ),
                            'platform' => mb_substr( (string) ( $item['platform'] ?? '' ), 0, 120 ),
                            'host'     => mb_substr( (string) ( $item['host'] ?? '' ), 0, 120 ),
                            'tier'     => (int) ( $item['tier'] ?? 0 ),
                            'reason'   => $reason,
                        ];
                    }
                } elseif ( 'imported' === $outcome ) {
                    $created[] = (int) $res['submission_id'];
                }
                $state['cursor']++;
                $out['processed']++;
                if ( 0 === $out['processed'] % self::SAVE_EVERY ) {
                    $this->saveState( $state );
                    $this->saveItemMap( $map );
                }
            }

            $out['done'] = $state['cursor'] >= $total;
            if ( $out['done'] ) {
                $state['finished_at'] = gmdate( 'c' );
                update_option( self::OPTION_VERSION, $version, false );
            }
            $this->saveItemMap( $map );
            $this->saveState( $state );

            $out['cursor']           = (int) $state['cursor'];
            $out['owner_tasks_open'] = $this->ownerTasksOpen();
            $out['summary']          = $this->importSentence( $out, $state, $version );
            if ( $out['processed'] > 0 ) {
                $this->audit->record(
                    AuditLog::currentActor(),
                    'verified_db.imported',
                    'verified_db',
                    0,
                    [
                        'version'        => $version,
                        'imported'       => $out['imported'],
                        'updated'        => $out['updated'],
                        'existing'       => $out['existing'],
                        'skipped'        => $out['skipped'],
                        'cursor'         => $out['cursor'],
                        'done'           => $out['done'],
                        'submission_ids' => array_slice( $created, 0, 100 ),
                    ]
                );
                $this->logger->info( 'verified', $out['summary'], [ 'version' => $version, 'cursor' => $out['cursor'], 'total' => $total ] );
            }
            return $out;
        } finally {
            $this->unlock();
        }
    }

    /** Whether a submissions row is a verified-database owner task. @param array<string,mixed> $submission */
    public static function isOwnerTask( array $submission ): bool {
        $payload = is_array( $submission['payload'] ?? null ) ? $submission['payload'] : [];
        $package = is_array( $payload['package'] ?? null ) ? $payload['package'] : [];
        return self::SOURCE === ( $package['source'] ?? null );
    }

    /**
     * Compact package facts for list views (null when the payload is not an owner task).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    public static function listSummary( array $payload ): ?array {
        $package = is_array( $payload['package'] ?? null ) ? $payload['package'] : [];
        if ( self::SOURCE !== ( $package['source'] ?? null ) ) {
            return null;
        }
        return [
            'source'      => self::SOURCE,
            'item_id'     => (string) ( $package['item_id'] ?? '' ),
            'platform'    => (string) ( $package['platform'] ?? '' ),
            'tier'        => isset( $package['tier'] ) ? (int) $package['tier'] : null,
            'authority'   => isset( $package['authority'] ) && is_numeric( $package['authority'] ) ? (int) $package['authority'] : null,
            'outreach'    => ! empty( $package['outreach'] ),
            'conditional' => ! empty( $package['conditional'] ),
        ];
    }

    /**
     * SQL fragment + params selecting owner tasks with optional tier / kind / conditional filters, for
     * Repository::paginate()'s extra WHERE (the payload column is matched with LIKE: valid on MariaDB and SQLite).
     *
     * @param array{tier?:int,kind?:string,conditional?:string} $filters kind: outreach|listing; conditional: yes|no
     * @return array{0:string,1:array<int,string>}
     */
    public static function payloadFilter( array $filters ): array {
        $sql    = [ 'payload LIKE %s' ];
        $params = [ '%' . self::PAYLOAD_MARKER . '%' ];
        $tier   = (int) ( $filters['tier'] ?? 0 );
        if ( $tier >= 1 && $tier <= 9 ) {
            $sql[]    = 'payload LIKE %s';
            $params[] = '%"tier":' . $tier . ',"%';
        }
        $kind = (string) ( $filters['kind'] ?? '' );
        if ( 'outreach' === $kind || 'listing' === $kind ) {
            $sql[]    = 'payload LIKE %s';
            $params[] = '%"outreach":' . ( 'outreach' === $kind ? 'true' : 'false' ) . ',"%';
        }
        $conditional = (string) ( $filters['conditional'] ?? '' );
        if ( 'yes' === $conditional || 'no' === $conditional ) {
            $sql[]    = 'payload LIKE %s';
            $params[] = '%"conditional":' . ( 'yes' === $conditional ? 'true' : 'false' ) . ',"%';
        }
        return [ implode( ' AND ', $sql ), $params ];
    }

    // ------------------------------------------------------------------
    // One item
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed>             $item
     * @param array<string,array<string,mixed>> $map
     * @param array<int,string>               $claimed prospect id => first item id that set its fields
     * @param array<string,mixed>             $data
     * @return array{outcome:string,reason:string,submission_id:int}
     */
    private function importItem( array $item, array &$map, array &$claimed, string $version, string $kitHash, array $data ): array {
        $id = trim( (string) ( $item['id'] ?? '' ) );
        if ( '' === $id ) {
            return self::skip( 'invalid_item' );
        }
        $hash = substr( sha1( (string) wp_json_encode( $item ) . '|' . $kitHash ), 0, 16 );
        $prev = $map[ $id ] ?? null;
        if ( null !== $prev && $prev['h'] === $hash ) {
            return [ 'outcome' => 'existing', 'reason' => '', 'submission_id' => (int) $prev['s'] ];
        }

        $intakeUrl = self::httpUrl( $item['website'] ?? '' ) ?: self::httpUrl( $item['submission_url'] ?? '' ) ?: self::httpUrl( $item['registration_url'] ?? '' );
        if ( '' === $intakeUrl ) {
            return self::skip( 'invalid_url' );
        }
        $target = self::httpUrl( $item['submission_url'] ?? '' ) ?: self::httpUrl( $item['registration_url'] ?? '' ) ?: $intakeUrl;

        $platform = trim( (string) ( $item['platform'] ?? '' ) );
        $oppType  = (string) ( $item['opportunity_type'] ?? '' );
        if ( ! array_key_exists( $oppType, Opportunities::TYPES ) ) {
            $oppType = 'directory_listing';
        }
        $res = $this->intake->fromUrl(
            $intakeUrl,
            self::SOURCE,
            mb_substr( (string) ( $item['category'] ?? '' ), 0, 255 ),
            [
                'name'             => mb_substr( $platform, 0, 255 ),
                'title'            => mb_substr( $platform, 0, 255 ),
                'snippet'          => mb_substr( (string) ( $item['relevance'] ?? '' ), 0, 500 ),
                'site_type'        => (string) ( $item['site_type'] ?? '' ),
                'opportunity_type' => $oppType,
                'details'          => [ 'source' => self::SOURCE, 'verified_item_id' => $id, 'tier' => (int) ( $item['tier'] ?? 0 ) ],
            ]
        );
        if ( null === $res ) {
            return self::skip( 'invalid_url' );
        }
        if ( null !== $res['skipped'] ) {
            return self::skip( (string) $res['skipped'] );
        }
        $pid      = (int) $res['prospect_id'];
        $prospect = $pid > 0 ? $this->prospects->find( $pid ) : null;
        if ( null === $prospect ) {
            return self::skip( 'prospect_missing' );
        }
        $status = (string) ( $prospect['status'] ?? '' );
        if ( Prospects::STATUS_SUPPRESSED === $status || Prospects::STATUS_REJECTED === $status ) {
            return self::skip( 'prospect_' . $status );
        }

        $this->updateProspect( $prospect, $item, $id, $claimed );

        $oppId = isset( $res['opportunity_id'] ) && null !== $res['opportunity_id'] ? (int) $res['opportunity_id'] : 0;
        if ( $oppId <= 0 ) {
            [ $oppId ] = $this->opportunities->upsert(
                $pid,
                $oppType,
                $target,
                mb_substr( $platform, 0, 255 ),
                [ 'discovered_via' => self::SOURCE, 'source' => self::SOURCE, 'verified_item_id' => $id, 'tier' => (int) ( $item['tier'] ?? 0 ) ]
            );
        }

        $payload  = $this->payload( $item, $id, $target, $version, $data );
        $existing = $this->submissions->existsForTarget( $pid, $target );
        if ( null === $existing && null !== $prev && $this->retarget( (int) $prev['s'], $pid, $id, $target, $oppId, $payload ) ) {
            // A changed item whose submission page moved: its still-open task follows the new page.
            $this->markPackaged( $oppId );
            $map[ $id ] = [ 'h' => $hash, 's' => (int) $prev['s'], 'p' => $pid ];
            return [ 'outcome' => 'updated', 'reason' => '', 'submission_id' => (int) $prev['s'] ];
        }
        if ( null === $existing ) {
            try {
                $sid = $this->submissions->create(
                    $pid,
                    $oppId > 0 ? $oppId : null,
                    $target,
                    [
                        'method'     => 'manual',
                        'adapter'    => self::ADAPTER,
                        'status'     => Submissions::STATUS_NEEDS_MANUAL,
                        'category'   => mb_substr( self::categoryLabel( (array) $data['kit'] ), 0, 190 ),
                        'payload'    => $payload,
                        'attempts'   => 0,
                        'last_error' => '',
                    ]
                );
                $this->markPackaged( $oppId );
                $map[ $id ] = [ 'h' => $hash, 's' => $sid, 'p' => $pid ];
                return [ 'outcome' => 'imported', 'reason' => '', 'submission_id' => $sid ];
            } catch ( Throwable $e ) {
                // A concurrent run created the same (prospect, target) row and the unique key refused this one.
                $existing = $this->submissions->existsForTarget( $pid, $target );
                if ( null === $existing ) {
                    throw $e;
                }
            }
        }

        // The (prospect, target) row exists: never a second task. A changed item refreshes its own open task.
        $sid     = (int) $existing['id'];
        $outcome = 'existing';
        if ( null !== $prev && self::isOwnerTask( $existing ) && in_array( (string) $existing['status'], [ Submissions::STATUS_NEEDS_MANUAL, Submissions::STATUS_PENDING_REVIEW ], true ) ) {
            $current = is_array( $existing['payload'] ?? null ) ? $existing['payload'] : [];
            if ( (string) ( $current['package']['item_id'] ?? '' ) === $id ) {
                $merged = array_merge( $current, $payload );
                $this->submissions->update( $sid, [ 'payload' => $merged, 'updated_at' => Repository::now() ] );
                $outcome = 'updated';
            }
        }
        $this->markPackaged( $oppId );
        $map[ $id ] = [ 'h' => $hash, 's' => $sid, 'p' => $pid ];
        return [ 'outcome' => $outcome, 'reason' => '', 'submission_id' => $sid ];
    }

    /**
     * Sets submission_url (http/https only), recommended_action and the item's notes block on the prospect.
     * When several items share one registrable domain, the first item in file order sets the URL and action.
     *
     * @param array<string,mixed> $prospect
     * @param array<string,mixed> $item
     * @param array<int,string>   $claimed
     */
    private function updateProspect( array $prospect, array $item, string $id, array &$claimed ): void {
        $pid  = (int) $prospect['id'];
        $data = [];
        if ( ! isset( $claimed[ $pid ] ) || $claimed[ $pid ] === $id ) {
            $claimed[ $pid ] = $id;
            $submissionUrl   = self::httpUrl( $item['submission_url'] ?? '' );
            if ( '' !== $submissionUrl ) {
                $data['submission_url'] = mb_substr( $submissionUrl, 0, 700 );
            }
            $data['recommended_action'] = ! empty( $item['outreach'] ) ? 'outreach_pitch' : 'manual_submission';
        }
        $data['notes']      = self::mergeNotes( (string) ( $prospect['notes'] ?? '' ), $id, self::notesBlock( $item, $id ) );
        $data['updated_at'] = Prospects::now();
        $this->prospects->update( $pid, $data );
    }

    /**
     * Moves an item's own, still-open owner task to a new target page (refreshing its package).
     *
     * @param array<string,mixed> $payload
     */
    private function retarget( int $sid, int $pid, string $id, string $target, int $oppId, array $payload ): bool {
        if ( $sid <= 0 ) {
            return false;
        }
        $old = $this->submissions->find( $sid );
        if ( null === $old || (int) $old['prospect_id'] !== $pid || ! self::isOwnerTask( $old ) ) {
            return false;
        }
        $current = is_array( $old['payload'] ?? null ) ? $old['payload'] : [];
        if ( (string) ( $current['package']['item_id'] ?? '' ) !== $id || ! in_array( (string) $old['status'], [ Submissions::STATUS_NEEDS_MANUAL, Submissions::STATUS_PENDING_REVIEW ], true ) ) {
            return false;
        }
        $data = [
            'target_url'  => mb_substr( Url::normalize( $target ) ?: $target, 0, 700 ),
            'target_hash' => Url::hash( $target ),
            'payload'     => array_merge( $current, $payload ),
            'updated_at'  => Repository::now(),
        ];
        if ( $oppId > 0 ) {
            $data['opportunity_id'] = $oppId;
        }
        return $this->submissions->update( $sid, $data );
    }

    private function markPackaged( int $oppId ): void {
        if ( $oppId <= 0 ) {
            return;
        }
        $opp = $this->opportunities->find( $oppId );
        if ( null !== $opp && Opportunities::STATUS_OPEN === (string) ( $opp['status'] ?? '' ) ) {
            $this->opportunities->setStatus( $oppId, Opportunities::STATUS_PACKAGED );
        }
    }

    /**
     * The owner-task payload. The package keys tier, outreach and conditional are each followed by another
     * key, so the list filters can match '"tier":2,"' etc. with LIKE.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function payload( array $item, string $id, string $target, string $version, array $data ): array {
        $kit                     = (array) $data['kit'];
        [ $description, $from ] = $this->description( $item, $kit );
        $steps                   = self::stepLines( '' !== trim( (string) ( $item['steps'] ?? '' ) ) ? (string) $item['steps'] : (string) ( $item['requirements'] ?? '' ) );
        $authority               = $item['authority'] ?? null;
        $violations              = [];
        foreach ( '' !== $description ? $this->claims->check( $description ) : [] as $v ) {
            $violations[] = [ 'rule' => (string) $v['rule'], 'match' => (string) $v['match'], 'message' => (string) $v['message'], 'field' => 'package.description' ];
        }
        $clean = '' !== $description && [] === $violations && self::listingSafe( $description );

        $package = [
            'source'             => self::SOURCE,
            'item_id'            => $id,
            'tier'               => max( 0, (int) ( $item['tier'] ?? 0 ) ),
            'authority'          => is_numeric( $authority ) ? (int) $authority : null,
            'outreach'           => ! empty( $item['outreach'] ),
            'conditional'        => ! empty( $item['conditional'] ),
            'data_version'       => $version,
            'db'                 => (string) ( $item['db'] ?? '' ),
            'platform'           => (string) ( $item['platform'] ?? '' ),
            'category'           => (string) ( $item['category'] ?? '' ),
            'opportunity_type'   => (string) ( $item['opportunity_type'] ?? '' ),
            'target_url'         => $target,
            'submission_url'     => (string) ( $item['submission_url'] ?? '' ),
            'registration_url'   => self::httpUrl( $item['registration_url'] ?? '' ),
            'contact_email'      => self::mailtoAddress( (string) ( $item['submission_url'] ?? '' ) ) ?: self::mailtoAddress( (string) ( $item['registration_url'] ?? '' ) ),
            'business_name'      => (string) ( $kit['business_name'] ?? '' ),
            'website'            => (string) ( $kit['website'] ?? '' ),
            'address'            => (string) ( $kit['address'] ?? '' ),
            'email'              => (string) ( $kit['email'] ?? '' ),
            'founded'            => (string) ( $kit['founded'] ?? '' ),
            'description'        => $description,
            'description_source' => $from,
            'category_rule'      => (string) ( $kit['category_rule'] ?? '' ),
            'positioning_rule'   => (string) ( $kit['positioning_rule'] ?? '' ),
            'budget_rule'        => (string) ( $kit['budget_rule'] ?? '' ),
            'identity_rule'      => (string) ( $kit['identity_rule'] ?? '' ),
            'steps'              => implode( "\n", $steps ),
            'requirements'       => (string) ( $item['requirements'] ?? '' ),
            'gate_note'          => (string) $data['gate'],
            'verification_note'  => (string) ( $item['verification_note'] ?? '' ),
            'link_attribute'     => (string) ( $item['link_attribute'] ?? '' ),
            'free'               => (string) ( $item['free'] ?? '' ),
            'payment'            => (string) ( $item['payment'] ?? '' ),
            'approval_required'  => (string) ( $item['approval_required'] ?? '' ),
            'effort'             => (string) ( $item['effort'] ?? '' ),
            'risk'               => (string) ( $item['risk'] ?? '' ),
            'relevance'          => (string) ( $item['relevance'] ?? '' ),
        ];

        return [
            'package'           => $package,
            'destination'       => [
                'name'             => (string) ( $item['platform'] ?? '' ),
                'domain'           => Url::registrableDomain( $target ),
                'url'              => $target,
                'type'             => (string) ( $item['site_type'] ?? '' ),
                'opportunity_type' => (string) ( $item['opportunity_type'] ?? '' ),
            ],
            'content'           => [ 'description' => $description ],
            'instructions'      => $steps,
            'unmapped_required' => [],
            'compliance'        => [ 'clean' => $clean, 'violations' => $violations, 'checked_at' => gmdate( 'c' ) ],
            'generated_at'      => gmdate( 'c' ),
        ];
    }

    /**
     * Unique, deterministic description variant from the content generator (seeded by the platform's host), with
     * sentences that do not suit a research-use-only listing removed; the kit description when that fails.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $kit
     * @return array{0:string,1:string} [text, 'generated'|'kit'|'none']
     */
    private function description( array $item, array $kit ): array {
        if ( null !== $this->content ) {
            try {
                $seed  = (string) ( ( $item['host'] ?? '' ) ?: ( $item['website'] ?? '' ) ?: ( $item['id'] ?? '' ) );
                $style = match ( (string) ( $item['site_type'] ?? '' ) ) {
                    'publication', 'educational' => 'educational',
                    'resource_page', 'association' => 'scientific',
                    default => 'business',
                };
                // Generated long, then cut to 500 characters after unsuitable sentences are removed, so more of
                // the varied middle sentences survive.
                $text = $this->listingText( $this->content->description( $seed, 1200, $style ), 500 );
                if ( '' !== $text && $this->claims->isClean( $text ) && self::listingSafe( $text ) ) {
                    return [ $text, 'generated' ];
                }
            } catch ( Throwable $e ) {
                $this->logger->warning( 'verified', 'Description variant unavailable; using the kit description.', [ 'error' => $e->getMessage() ] );
            }
        }
        $fallback = trim( (string) ( $kit['description'] ?? '' ) );
        if ( '' !== $fallback && $this->claims->isClean( $fallback ) && self::listingSafe( $fallback ) ) {
            return [ $fallback, 'kit' ];
        }
        return [ '', 'none' ];
    }

    /**
     * Drops sentences with banned or unsuitable wording and fits the rest into $max characters (the opening and
     * the research-use-only statement are kept, middle sentences are added in order while they fit); '' when
     * nothing research-use-only remains.
     */
    private function listingText( string $text, int $max ): string {
        $kept = [];
        foreach ( preg_split( '/(?<=[.!?])\s+/u', trim( $text ) ) ?: [] as $sentence ) {
            $sentence = trim( $sentence );
            if ( '' === $sentence || preg_match( self::BANNED_LISTING_WORDS, $sentence ) || preg_match( self::DROPPED_SENTENCE, $sentence ) ) {
                continue;
            }
            $kept[] = $sentence;
        }
        $ruo = null;
        for ( $i = count( $kept ) - 1; $i >= 0; $i-- ) {
            if ( preg_match( self::RUO_PATTERN, $kept[ $i ] ) ) {
                $ruo = $kept[ $i ];
                array_splice( $kept, $i, 1 );
                break;
            }
        }
        if ( null === $ruo || [] === $kept ) {
            return '';
        }
        $out = [ array_shift( $kept ) ];
        foreach ( $kept as $sentence ) {
            if ( mb_strlen( implode( ' ', array_merge( $out, [ $sentence, $ruo ] ) ) ) <= $max ) {
                $out[] = $sentence;
            }
        }
        $out[] = $ruo;
        $text  = implode( ' ', $out );
        return mb_strlen( $text ) >= 120 && mb_strlen( $text ) <= $max ? $text : '';
    }

    /** Research-use-only listing text: states RUO and uses no health/medical/pharmacy/supplement/wellness wording. */
    public static function listingSafe( string $text ): bool {
        return '' !== trim( $text ) && 1 === preg_match( self::RUO_PATTERN, $text ) && 1 !== preg_match( self::BANNED_LISTING_WORDS, $text );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{outcome:string,reason:string,submission_id:int} */
    private static function skip( string $reason ): array {
        return [ 'outcome' => 'skipped', 'reason' => '' !== $reason ? $reason : 'skipped', 'submission_id' => 0 ];
    }

    /** Normalised http(s) URL, '' for anything else (mailto:, javascript:, relative, empty). */
    private static function httpUrl( mixed $value ): string {
        $v = is_string( $value ) ? trim( $value ) : '';
        if ( '' === $v || ! preg_match( '#^https?://[^/\s]+#i', $v ) ) {
            return '';
        }
        return Url::normalize( $v );
    }

    private static function mailtoAddress( string $value ): string {
        if ( ! preg_match( '/^mailto:([^?\s]+)/i', trim( $value ), $m ) ) {
            return '';
        }
        $email = rawurldecode( $m[1] );
        return is_email( $email ) ? strtolower( $email ) : '';
    }

    /** "Laboratory supplies / biotechnology / scientific research supplier" from the kit's category rule. */
    private static function categoryLabel( array $kit ): string {
        $rule  = trim( (string) ( $kit['category_rule'] ?? '' ) );
        $first = trim( (string) ( preg_split( '/(?<=\.)\s/', $rule )[0] ?? '' ) );
        return rtrim( $first, '.' );
    }

    /**
     * Steps as readable lines: newlines, " || " and numbered "(1) (2)" parts start new lines, and a "- a - b"
     * bullet run is split into its bullets.
     *
     * @return string[]
     */
    public static function stepLines( string $text ): array {
        $text = trim( str_replace( [ "\r\n", "\r" ], "\n", $text ) );
        if ( '' === $text ) {
            return [];
        }
        $lines = [];
        foreach ( preg_split( '/\n+|\s+\|\|\s+/u', $text ) ?: [] as $part ) {
            $part = trim( $part );
            if ( '' === $part ) {
                continue;
            }
            $pieces = str_starts_with( $part, '- ' )
                ? ( preg_split( '/\s+-\s+(?=[A-Z0-9(])/u', substr( $part, 2 ) ) ?: [ $part ] )
                : ( preg_split( '/\s+(?=\(\d{1,2}\)\s)/u', $part ) ?: [ $part ] );
            foreach ( $pieces as $p ) {
                $p = trim( $p );
                if ( '' !== $p ) {
                    $lines[] = mb_substr( $p, 0, 600 );
                }
            }
        }
        return array_slice( $lines, 0, self::MAX_STEP_LINES );
    }

    /** @param array<string,mixed> $item */
    private static function notesBlock( array $item, string $id ): string {
        $tier      = (int) ( $item['tier'] ?? 0 );
        $authority = is_numeric( $item['authority'] ?? null ) ? 'authority ' . (int) $item['authority'] : 'authority unknown';
        $kind      = ! empty( $item['outreach'] ) ? 'outreach (owner decides whether to pitch)' : 'listing';
        $lines     = [
            sprintf( '[Verified database item %s] %s (tier %d, %s, %s%s)', $id, mb_substr( (string) ( $item['platform'] ?? '' ), 0, 200 ), $tier, $authority, $kind, ! empty( $item['conditional'] ) ? ', conditional' : '' ),
            'Requirements: ' . mb_substr( trim( (string) ( $item['requirements'] ?? '' ) ), 0, 1200 ),
            'Verification: ' . mb_substr( trim( (string) ( $item['verification_note'] ?? '' ) ), 0, 500 ),
            sprintf( '[End of verified item %s]', $id ),
        ];
        return implode( "\n", $lines );
    }

    /** Replaces (or appends) the block of one item in the prospect notes, leaving everything else alone. */
    private static function mergeNotes( string $notes, string $id, string $block ): string {
        $q     = preg_quote( $id, '/' );
        $notes = (string) preg_replace( '/\n*\[Verified database item ' . $q . '\].*?\[End of verified item ' . $q . '\]/s', '', $notes );
        $notes = trim( $notes );
        $out   = '' === $notes ? $block : $notes . "\n\n" . $block;
        // The column is TEXT (65,535 bytes): cut on a character boundary well below that.
        return mb_strcut( $out, 0, 60000, 'UTF-8' );
    }

    /**
     * @param array<string,array<string,mixed>> $map
     * @return array<int,string> prospect id => item id (first in file order wins: the map keeps insertion order)
     */
    private static function claimedProspects( array $map ): array {
        $out = [];
        foreach ( $map as $itemId => $row ) {
            $pid = (int) ( $row['p'] ?? 0 );
            if ( $pid > 0 && ! isset( $out[ $pid ] ) ) {
                $out[ $pid ] = (string) $itemId;
            }
        }
        return $out;
    }

    private function ownerTasksOpen(): int {
        try {
            global $wpdb;
            $table = Schema::table( 'submissions' );
            $sql   = "SELECT COUNT(*) FROM {$table} WHERE status = %s AND payload LIKE %s";
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            return (int) $wpdb->get_var( $wpdb->prepare( $sql, Submissions::STATUS_NEEDS_MANUAL, '%' . self::PAYLOAD_MARKER . '%' ) );
        } catch ( Throwable $e ) {
            return 0;
        }
    }

    private function importedVersion(): string {
        $v = get_option( self::OPTION_VERSION, '' );
        return is_string( $v ) ? $v : '';
    }

    /** @param array<string,mixed> $s */
    private function statusSentence( array $s ): string {
        if ( ! $s['available'] ) {
            return 'The verified opportunity database could not be read: ' . $s['error'];
        }
        if ( $s['in_progress'] ) {
            return sprintf( 'Import of version %s in progress: %d of %d items processed; %d owner tasks are open.', $s['data_version'], $s['cursor'], $s['total'], $s['owner_tasks_open'] );
        }
        if ( $s['up_to_date'] ) {
            return sprintf( 'Version %s imported: %d of %d items have owner tasks, %d skipped; %d owner tasks are open.', $s['data_version'], $s['imported'], $s['total'], $s['skipped'], $s['owner_tasks_open'] );
        }
        if ( '' === $s['imported_version'] ) {
            return sprintf( 'Not imported yet: %d verified opportunities (version %s) are ready to import.', $s['total'], $s['data_version'] );
        }
        return sprintf( 'Version %s is available (version %s was imported); new and changed items are imported on the next run.', $s['data_version'], $s['imported_version'] );
    }

    /**
     * @param array<string,mixed> $out
     * @param array<string,mixed> $state
     */
    private function importSentence( array $out, array $state, string $version ): string {
        $parts = [];
        if ( $out['imported'] > 0 ) {
            $parts[] = sprintf( '%d new owner task%s', $out['imported'], 1 === $out['imported'] ? '' : 's' );
        }
        if ( $out['updated'] > 0 ) {
            $parts[] = sprintf( '%d updated', $out['updated'] );
        }
        if ( $out['existing'] > 0 ) {
            $parts[] = sprintf( '%d already present', $out['existing'] );
        }
        if ( $out['skipped'] > 0 ) {
            $parts[] = sprintf( '%d skipped', $out['skipped'] );
        }
        $did = [] !== $parts ? implode( ', ', $parts ) : 'nothing new';
        if ( 0 === $out['processed'] ) {
            return sprintf( 'Not enough time left to import verified opportunities; %d of %d items processed so far, the import continues on the next run.', (int) $state['cursor'], $out['total'] );
        }
        if ( $out['done'] ) {
            $reasons = [];
            foreach ( (array) $state['skip_reasons'] as $reason => $n ) {
                $reasons[] = sprintf( '%s: %d', str_replace( '_', ' ', (string) $reason ), (int) $n );
            }
            return sprintf(
                'Imported verified opportunities version %s (%s); all %d items processed, %d skipped in total%s; %d owner tasks are open.',
                $version,
                $did,
                $out['total'],
                (int) $state['counts']['skipped'],
                [] !== $reasons ? ' (' . implode( ', ', $reasons ) . ')' : '',
                $out['owner_tasks_open']
            );
        }
        return sprintf( 'Imported verified opportunities %d of %d (%s); the rest continues on the next run.', (int) $state['cursor'], $out['total'], $did );
    }

    // ------------------------------------------------------------------
    // Data file and stored state
    // ------------------------------------------------------------------

    /**
     * @return array{version:string,counts:array<string,int>,kit:array<string,mixed>,gate:string,items:array<int,array<string,mixed>>}
     */
    private function data(): array {
        if ( null !== $this->data ) {
            return $this->data;
        }
        $empty = [ 'version' => '', 'counts' => [], 'kit' => [], 'gate' => '', 'items' => [] ];
        $raw   = is_readable( $this->file ) ? file_get_contents( $this->file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $json  = is_string( $raw ) ? json_decode( $raw, true ) : null;
        if ( ! is_array( $json ) || ! is_array( $json['items'] ?? null ) ) {
            $this->loadError = false === $raw ? 'the data file is missing or unreadable.' : 'the data file is not valid JSON.';
            $this->data      = $empty;
            return $this->data;
        }
        $counts = [];
        foreach ( (array) ( $json['counts'] ?? [] ) as $k => $v ) {
            if ( is_numeric( $v ) ) {
                $counts[ (string) $k ] = (int) $v;
            }
        }
        $this->data = [
            'version' => is_scalar( $json['version'] ?? null ) ? (string) $json['version'] : '',
            'counts'  => $counts,
            'kit'     => is_array( $json['kit'] ?? null ) ? $json['kit'] : [],
            'gate'    => is_string( $json['gate'] ?? null ) ? $json['gate'] : '',
            'items'   => array_values( array_filter( $json['items'], 'is_array' ) ),
        ];
        return $this->data;
    }

    /** @return array{version:string,cursor:int,started_at:?string,finished_at:?string,counts:array{imported:int,updated:int,existing:int,skipped:int},skip_reasons:array<string,int>,skipped_items:array<int,array<string,mixed>>} */
    private static function freshState( string $version ): array {
        return [
            'version'       => $version,
            'cursor'        => 0,
            'started_at'    => gmdate( 'c' ),
            'finished_at'   => null,
            'counts'        => [ 'imported' => 0, 'updated' => 0, 'existing' => 0, 'skipped' => 0 ],
            'skip_reasons'  => [],
            'skipped_items' => [],
        ];
    }

    /** @return array{version:string,cursor:int,started_at:?string,finished_at:?string,counts:array{imported:int,updated:int,existing:int,skipped:int},skip_reasons:array<string,int>,skipped_items:array<int,array<string,mixed>>} */
    private function state(): array {
        $raw   = get_option( self::OPTION_STATE, [] );
        $raw   = is_array( $raw ) ? $raw : [];
        $state = self::freshState( is_string( $raw['version'] ?? null ) ? $raw['version'] : '' );
        if ( [] === $raw ) {
            return $state;
        }
        $state['cursor']      = max( 0, (int) ( $raw['cursor'] ?? 0 ) );
        $state['started_at']  = is_string( $raw['started_at'] ?? null ) ? $raw['started_at'] : null;
        $state['finished_at'] = is_string( $raw['finished_at'] ?? null ) && '' !== $raw['finished_at'] ? $raw['finished_at'] : null;
        foreach ( array_keys( $state['counts'] ) as $k ) {
            $state['counts'][ $k ] = max( 0, (int) ( $raw['counts'][ $k ] ?? 0 ) );
        }
        foreach ( (array) ( $raw['skip_reasons'] ?? [] ) as $reason => $n ) {
            $state['skip_reasons'][ (string) $reason ] = (int) $n;
        }
        $state['skipped_items'] = array_values( array_filter( (array) ( $raw['skipped_items'] ?? [] ), 'is_array' ) );
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function saveState( array $state ): void {
        update_option( self::OPTION_STATE, $state, false );
    }

    /** @return array<string,array{h:string,s:int,p:int}> */
    private function itemMap(): array {
        $raw = get_option( self::OPTION_ITEMS, [] );
        $out = [];
        foreach ( is_array( $raw ) ? $raw : [] as $id => $packed ) {
            $parts = explode( ':', (string) $packed );
            if ( count( $parts ) < 3 || '' === $parts[0] ) {
                continue;
            }
            $out[ (string) $id ] = [ 'h' => $parts[0], 's' => (int) $parts[1], 'p' => (int) $parts[2] ];
        }
        return $out;
    }

    /** @param array<string,array<string,mixed>> $map */
    private function saveItemMap( array $map ): void {
        $packed = [];
        foreach ( $map as $id => $row ) {
            $packed[ (string) $id ] = $row['h'] . ':' . (int) $row['s'] . ':' . (int) $row['p'];
        }
        update_option( self::OPTION_ITEMS, $packed, false );
    }

    private function lock( TimeBudget $budget ): bool {
        if ( null === $this->locks ) {
            return true;
        }
        try {
            $this->locked = $this->locks->acquire( self::LOCK, (int) ceil( $budget->remaining() ) + 60 );
            return $this->locked;
        } catch ( Throwable $e ) {
            $this->logger->warning( 'verified', 'Import lock unavailable; continuing without it.', [ 'error' => $e->getMessage() ] );
            return true;
        }
    }

    private function unlock(): void {
        if ( null !== $this->locks && $this->locked ) {
            $this->locks->release( self::LOCK );
            $this->locked = false;
        }
    }
}
