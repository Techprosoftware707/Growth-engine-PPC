<?php
/**
 * The settings schema (DESIGN.md §2.2, §3.1, §13.2 WP2): Support\SettingsSchema collects one validation rule per
 * settings key from the modules (Module::settingsRules(), next to their settingsDefaults()), and
 * Admin\Rest\SettingsController validates POST /settings with it. Here: every rule type on its own (what it accepts,
 * refuses, clamps or normalises), the schema against Settings::defaults() (exactly one rule per key and no rule
 * without a key, apart from the optional keys the services read with their own default), the engine's ranges, the
 * sanitising of a whole tree, and a module added through `lga_modules`. The outcome of POST /settings for every key
 * is pinned by WiringSnapshotTest (settings_validation, 975 probes) and stayed identical.
 *
 * The counts, ranges and key lists written here are those of the 13 WP2 modules (Tests\Support\Wp2Modules); the
 * invariants (one rule per key, a known type that fits the default) hold for every module, so a later package's
 * module is checked by them without editing this file (DESIGN.md §13.1).
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Support;

use LinkGrowth\Autopilot\Admin\Rest\SettingsController;
use LinkGrowth\Autopilot\Core\AbstractModule;
use LinkGrowth\Autopilot\Core\Modules;
use LinkGrowth\Autopilot\Repositories\AuditLog;
use LinkGrowth\Autopilot\Support\Settings;
use LinkGrowth\Autopilot\Support\SettingsSchema;
use LinkGrowth\Autopilot\Tests\Support\Container;
use LinkGrowth\Autopilot\Tests\Support\TestCase;
use LinkGrowth\Autopilot\Tests\Support\Wiring;
use LinkGrowth\Autopilot\Tests\Support\Wp2Modules;
use WP_REST_Request;

final class SettingsSchemaTest extends TestCase {

    /**
     * The clamp ranges of the engine's SettingsController (its INT_RANGES table, by leaf key); every other integer
     * setting was "a whole number from 0".
     */
    private const ENGINE_INT_RANGES = [
        'tick_interval'              => [ 60, 86400 ],
        'max_execution_seconds'      => [ 15, 120 ],
        'send_window_start'          => [ 0, 23 ],
        'send_window_end'            => [ 1, 24 ],
        'request_timeout'            => [ 3, 60 ],
        'crawl_delay_seconds'        => [ 0, 60 ],
        'max_response_kb'            => [ 64, 8192 ],
        'log_retention_days'         => [ 1, 365 ],
        'audit_retention_days'       => [ 7, 3650 ],
        'min_score_auto_queue'       => [ 0, 100 ],
        'min_score_keep'             => [ 0, 100 ],
        'results_per_query'          => [ 1, 50 ],
        'query_cooldown_days'        => [ 0, 365 ],
        'cache_days'                 => [ 1, 365 ],
        'follow_up_days'             => [ 1, 60 ],
        'max_follow_ups'             => [ 0, 3 ],
        'min_days_between_contacts'  => [ 0, 365 ],
        'lost_after_failures'        => [ 1, 10 ],
        'check_interval_days_new'    => [ 1, 30 ],
        'check_interval_days_stable' => [ 1, 90 ],
        'import_interval_days'       => [ 1, 90 ],
        'import_limit'               => [ 10, 5000 ],
        'max_tokens'                 => [ 200, 8000 ],
        'google_cse_daily_limit'     => [ 0, 10000 ],
        'brave_daily_limit'          => [ 0, 100000 ],
        'audit_max_pages'            => [ 20, 5000 ],
        'max_links_per_post'         => [ 1, 10 ],
        'auto_apply_per_day'         => [ 1, 100 ],
        'evaluate_rounds'            => [ 1, 20 ],
        'step_seconds'               => [ 8, 25 ],
    ];

    /** The keys POST /settings accepts that Settings::defaults() does not list, with the default the services use. */
    private const OPTIONAL_KEYS = [
        'providers.seranking.fetch_overview' => true,
        'providers.seranking.fetch_summary'  => false,
        'providers.ahrefs.fetch_metrics'     => true,
        'content.linkable_assets'            => [],
    ];

    /** Keys POST /settings never writes. */
    private const READ_ONLY_KEYS = [ 'compliance.acknowledged_hash', 'compliance.acknowledged_by', 'compliance.acknowledged_at', 'seo.indexnow_key' ];

    protected function tearDown(): void {
        Container::reset(); // a probe module must not reach the next test through the memoised module list
        $GLOBALS['lga_test_caps'] = [];
        parent::tearDown();
    }

    private static function schema(): SettingsSchema {
        return Container::get( SettingsSchema::class );
    }

    /** @return array<string,array<string,mixed>> the rules the WP2 modules declare, by dot path */
    private static function wp2Rules(): array {
        return array_merge( ...array_values( Wp2Modules::declared( 'settingsRules' ) ) );
    }

    /** @return array<string,mixed> the defaults the WP2 modules declare, flattened to dot paths */
    private static function wp2Defaults(): array {
        return Wiring::flatten( array_merge( ...array_values( Wp2Modules::declared( 'settingsDefaults' ) ) ) );
    }

    /** @param array<string,mixed> $rule */
    private static function value( array $rule, mixed $input ): mixed {
        return SettingsSchema::sanitizeValue( $rule, $input );
    }

    // ------------------------------------------------------------------
    // The schema and the defaults
    // ------------------------------------------------------------------

    public function testEveryDefaultKeyHasExactlyOneRuleAndEveryRuleAKey(): void {
        $schema   = self::schema();
        $defaults = Wiring::flatten( Settings::defaults() );
        $rules    = $schema->rules();

        $missing = array_diff( array_keys( $defaults ), array_keys( $rules ) );
        $this->assertSame( [], array_values( $missing ), 'every key of Settings::defaults() has a rule' );

        $extra = [];
        foreach ( $rules as $path => $rule ) {
            if ( ! array_key_exists( $path, $defaults ) ) {
                $extra[ $path ] = $rule;
            }
        }
        foreach ( $extra as $path => $rule ) {
            $this->assertFalse( $rule['in_defaults'] ?? true, $path . ': a rule without a default key is declared optional()' );
        }
        foreach ( array_keys( $defaults ) as $path ) {
            $this->assertTrue( $rules[ $path ]['in_defaults'] ?? true, $path . ' is not declared optional' );
        }
        $this->assertSame( [], $schema->duplicates(), 'no key is declared by two modules' );
        $this->assertSame( $defaults + array_map( static fn( array $rule ): mixed => $rule['default'] ?? null, $extra ), $schema->defaults() );

        // The WP2 modules: the engine's keys and the four optional keys, all collected.
        $wp2Defaults = self::wp2Defaults();
        $wp2Rules    = self::wp2Rules();
        $this->assertCount( 116, $wp2Defaults, 'the 116 engine keys' );
        $this->assertCount( 120, $wp2Rules );
        $this->assertSame( $wp2Defaults, array_intersect_key( $defaults, $wp2Defaults ), 'among the defaults' );
        $this->assertSame( $wp2Rules, array_intersect_key( $rules, $wp2Rules ), 'among the rules' );
        $wp2Extra = array_diff_key( $wp2Rules, $wp2Defaults );
        $this->assertSame( array_keys( self::OPTIONAL_KEYS ), array_keys( $wp2Extra ), 'a WP2 rule without a default key is one of the optional keys' );
        foreach ( $wp2Extra as $path => $rule ) {
            $this->assertSame( self::OPTIONAL_KEYS[ $path ], $rule['default'] ?? null, $path . ': the default the services read' );
            $this->assertSame( self::OPTIONAL_KEYS[ $path ], $schema->defaults()[ $path ] ?? null, $path . ' in the schema defaults' );
        }
    }

    public function testEachModuleDeclaresTheRulesOfItsOwnSections(): void {
        foreach ( Container::plugin()->modules() as $module ) {
            $sections = array_keys( $module->settingsDefaults() );
            foreach ( array_keys( $module->settingsRules() ) as $path ) {
                $section = explode( '.', $path )[0];
                $own     = in_array( $section, $sections, true ) || array_key_exists( $path, self::OPTIONAL_KEYS );
                $this->assertTrue( $own, $module->id() . ' declares ' . $path . ' of its own section (or an optional key of its area)' );
            }
            foreach ( Wiring::flatten( $module->settingsDefaults() ) as $path => $default ) {
                $this->assertArrayHasKey( $path, $module->settingsRules(), $module->id() . ' declares the rule of its key ' . $path );
            }
        }
        $owners = [];
        foreach ( Container::plugin()->modules() as $module ) {
            foreach ( array_keys( $module->settingsRules() ) as $path ) {
                $owners[ $path ] = $module->id();
            }
        }
        $this->assertSame( 'integrations', $owners['providers.seranking.fetch_overview'] );
        $this->assertSame( 'content', $owners['content.linkable_assets'] );
    }

    public function testEveryRuleHasAKnownTypeThatFitsItsDefault(): void {
        $types = [];
        $wp2   = self::wp2Rules();
        foreach ( self::schema()->rules() as $path => $rule ) {
            $this->assertContains( $rule['type'], SettingsSchema::TYPES, $path );
            if ( isset( $wp2[ $path ] ) ) {
                $types[ $rule['type'] ] = true;
            }
            $default = self::schema()->defaults()[ $path ];
            switch ( $rule['type'] ) {
                case SettingsSchema::BOOL:
                    $this->assertIsBool( $default, $path );
                    break;
                case SettingsSchema::INT:
                    $this->assertIsInt( $default, $path );
                    break;
                case SettingsSchema::LIST:
                case SettingsSchema::RECORDS:
                    $this->assertIsArray( $default, $path );
                    $this->assertTrue( Settings::isList( $default ), $path . ' defaults to a list' );
                    break;
                case SettingsSchema::READ_ONLY:
                    if ( isset( $wp2[ $path ] ) ) {
                        $this->assertContains( $path, self::READ_ONLY_KEYS, 'the read-only keys of the WP2 modules' );
                    }
                    break;
                default:
                    $this->assertIsString( $default, $path );
            }
            if ( SettingsSchema::READ_ONLY !== $rule['type'] && ! is_array( $default ) && 'site.country' !== $path ) {
                // A default that the form sends back unchanged is accepted as it is.
                $this->assertSame( $default, self::value( $rule, $default ), $path . ': the default passes its own rule' );
            }
        }
        // The one exception, kept from the engine: the country is two letters or it is not changed, so an empty value
        // (the default) is refused (WP4a's iso_country rule decides about clearing it).
        $this->assertNull( self::value( self::schema()->rule( 'site.country' ), '' ) );
        ksort( $types );
        $this->assertSame( [ 'bool', 'domain', 'email', 'enum', 'int', 'list', 'read_only', 'records', 'string', 'timezone', 'url' ], array_keys( $types ), 'the rule types the engine keys (the WP2 modules) use (float and secret are used outside the keys)' );
    }

    public function testTheIntegerRulesHaveTheEnginesRanges(): void {
        $n = 0;
        foreach ( array_intersect_key( self::schema()->rules(), self::wp2Rules() ) as $path => $rule ) {
            if ( SettingsSchema::INT !== $rule['type'] ) {
                continue;
            }
            $leaf = (string) substr( strrchr( '.' . $path, '.' ), 1 );
            [ $min, $max ] = self::ENGINE_INT_RANGES[ $leaf ] ?? [ 0, null ];
            $this->assertSame( [ $min, $max, SettingsSchema::CLAMP ], [ $rule['min'], $rule['max'], $rule['out_of_range'] ], $path );
            $n++;
        }
        $this->assertSame( 42, $n, 'every integer setting of the WP2 modules (a later module\'s ranges are its own)' );
        foreach ( self::READ_ONLY_KEYS as $path ) {
            $this->assertSame( SettingsSchema::READ_ONLY, self::schema()->rule( $path )['type'] ?? null, $path );
        }
    }

    // ------------------------------------------------------------------
    // Rule types
    // ------------------------------------------------------------------

    public function testBoolAcceptsBooleansAndTheirTextFormsAndRefusesTheRest(): void {
        $rule = SettingsSchema::bool();
        $this->assertTrue( self::value( $rule, true ) );
        $this->assertFalse( self::value( $rule, false ) );
        foreach ( [ '1' => true, 'on' => true, 'yes' => true, 'TRUE' => true, '0' => false, 'off' => false, 'no' => false, '' => false ] as $text => $expected ) {
            $this->assertSame( $expected, self::value( $rule, (string) $text ), 'normalised: ' . $text );
        }
        $this->assertSame( true, self::value( $rule, 1 ) );
        foreach ( [ 'maybe', 42, 2.5, [ 'x' ], [ true ] ] as $refused ) {
            $this->assertNull( self::value( $rule, $refused ), 'refused: ' . json_encode( $refused ) );
        }
    }

    public function testIntCutsNumbersToIntegersClampsOrRefusesOutOfRangeAndRefusesTheRest(): void {
        $clamp = SettingsSchema::int( 60, 86400 );
        $this->assertSame( 300, self::value( $clamp, 300 ) );
        $this->assertSame( 120, self::value( $clamp, '120' ), 'a numeric string' );
        $this->assertSame( 120, self::value( $clamp, 120.9 ), 'cut to an integer' );
        $this->assertSame( 60, self::value( $clamp, 7 ), 'clamped to the minimum' );
        $this->assertSame( 60, self::value( $clamp, -5 ) );
        $this->assertSame( 86400, self::value( $clamp, 1000000 ), 'clamped to the maximum' );
        $this->assertSame( 1000, self::value( SettingsSchema::int( 0, 5000 ), '1e3' ) );
        foreach ( [ 'abc', '', '12 days', true, null, [ 'x' ] ] as $refused ) {
            $this->assertNull( self::value( $clamp, $refused ), 'refused: ' . json_encode( $refused ) );
        }

        $floor = SettingsSchema::int( 0 );
        $this->assertSame( 0, self::value( $floor, -5 ), 'no upper bound: only negative numbers are raised to 0' );
        $this->assertSame( 1000000, self::value( $floor, 1000000 ) );

        $refuse = SettingsSchema::int( 60, 900, SettingsSchema::REFUSE );
        $this->assertSame( 600, self::value( $refuse, 600 ) );
        $this->assertSame( 60, self::value( $refuse, 60 ), 'the bounds are inside the range' );
        $this->assertSame( 900, self::value( $refuse, '900' ) );
        $this->assertNull( self::value( $refuse, 59 ), 'refused below the range' );
        $this->assertNull( self::value( $refuse, 901 ), 'refused above the range' );
    }

    public function testFloatAcceptsNumbersAndClampsOrRefusesOutOfRange(): void {
        $any = SettingsSchema::float();
        $this->assertSame( 2.5, self::value( $any, '2.5' ) );
        $this->assertSame( 7.0, self::value( $any, 7 ) );
        $this->assertSame( -0.5, self::value( $any, -0.5 ) );
        $this->assertNull( self::value( $any, 'abc' ) );
        $this->assertNull( self::value( $any, [ 1.5 ] ) );

        $share = SettingsSchema::float( 0.0, 1.0 );
        $this->assertSame( 0.25, self::value( $share, 0.25 ) );
        $this->assertSame( 1.0, self::value( $share, 1.7 ), 'clamped' );
        $this->assertSame( 0.0, self::value( $share, -3 ) );
        $this->assertNull( self::value( SettingsSchema::float( 0.0, 1.0, SettingsSchema::REFUSE ), 1.7 ), 'refused' );
    }

    public function testEnumAcceptsItsValuesInAnyCaseAndRefusesOrFallsBack(): void {
        $level = SettingsSchema::enum( [ 'debug', 'info', 'warning', 'error' ] );
        $this->assertSame( 'debug', self::value( $level, 'debug' ) );
        $this->assertSame( 'warning', self::value( $level, 'Warning' ), 'normalised to the key form' );
        $this->assertSame( 'error', self::value( $level, ' ERROR ' ) );
        foreach ( [ 'verbose', '', 42, true, [ 'debug' ] ] as $refused ) {
            $this->assertNull( self::value( $level, $refused ), 'refused: ' . json_encode( $refused ) );
        }

        $mode = self::schema()->rule( 'automation.submission_mode' );
        $this->assertSame( 'manual', self::value( $mode, 'Manual' ) );
        $this->assertSame( 'authorized_only', self::value( $mode, 'authorized_only' ) );
        $this->assertSame( 'auto', self::value( $mode, 'everything' ), 'an unknown mode falls back to the engine default' );
        $this->assertNull( self::value( $mode, [ 'manual' ] ), 'a list is refused, not converted' );
    }

    public function testStringSanitisesTrimsStripsCasesChecksAndCuts(): void {
        $text = SettingsSchema::text( 20 );
        $this->assertSame( 'Spaced text', self::value( $text, '  <b>Spaced</b>   text  ' ), 'tags and runs of white space removed' );
        $this->assertSame( 'one two', self::value( $text, "one\ntwo" ), 'one line' );
        $this->assertSame( str_repeat( 'é', 20 ), self::value( $text, str_repeat( 'é', 30 ) ), 'cut to 20 characters, not bytes' );
        $this->assertSame( '42', self::value( $text, 42 ) );
        $this->assertSame( '', self::value( $text, '' ), 'may be cleared' );
        $this->assertNull( self::value( $text, [ 'not', 'a', 'string' ] ) );

        $textarea = SettingsSchema::textarea( 2000 );
        $this->assertSame( "500 Main St\nSuite 4", self::value( $textarea, "500 Main St\nSuite 4" ), 'line breaks kept' );

        $key = SettingsSchema::string( [ 'sanitize' => 'key' ] );
        $this->assertSame( 'my-kind', self::value( $key, 'my-kind' ) );
        $this->assertSame( 'mykind', self::value( $key, 'My Kind!' ), 'the key form: lower case, other characters dropped' );

        $country = self::schema()->rule( 'site.country' );
        $this->assertSame( 'US', self::value( $country, 'us' ), 'upper case' );
        $this->assertSame( 'US', self::value( $country, 'U.S.' ), 'other characters dropped' );
        $this->assertNull( self::value( $country, 'usa' ), 'refused: not two letters' );
        $this->assertNull( self::value( $country, '' ), 'refused: empty' );

        $market = self::schema()->rule( 'providers.semrush.database' );
        $this->assertSame( 'us', self::value( $market, 'U-S' ) );
        $this->assertSame( 'abcdefghij', self::value( $market, 'ABCDEFGHIJKL' ), 'cut to 10' );
        $this->assertNull( self::value( $market, '--' ), 'refused: nothing left' );

        $year = self::schema()->rule( 'site.founded_year' );
        $this->assertSame( '1999', self::value( $year, 'founded 1999' ) );
        $this->assertSame( '2019', self::value( $year, '201999' ), 'at most four digits' );
        $this->assertSame( '', self::value( $year, 'n/a' ), 'no digits: cleared' );

        $agent = self::schema()->rule( 'advanced.user_agent' );
        $this->assertSame( 'ExampleBot/2.0', self::value( $agent, '  ExampleBot/2.0 ' ) );
        $this->assertNull( self::value( $agent, '   ' ), 'refused: empty' );
        $this->assertSame( 255, mb_strlen( (string) self::value( $agent, str_repeat( 'a', 300 ) ) ) );

        $writer = self::schema()->rule( 'providers.ai.model' );
        $this->assertSame( 'writer-x1.2', self::value( $writer, ' Writer-X1.2 ' ), 'trimmed and lower case' );
        $this->assertSame( '', self::value( $writer, '' ), 'no model: the writer stays off' );
        $this->assertNull( self::value( $writer, 'ab' ), 'refused: too short for the pattern' );
        $this->assertNull( self::value( $writer, 'bad id!' ), 'refused: outside the pattern' );

        $meta = self::schema()->rule( 'seo.language_counterpart_meta_key' );
        $this->assertSame( 'lang_meta', self::value( $meta, ' lang_meta ' ) );
        $this->assertSame( '', self::value( $meta, '' ) );
        $this->assertNull( self::value( $meta, 'bad key!' ) );
    }

    public function testEmailKeepsAValidAddressOrNothingAndRefusesTheRest(): void {
        $rule = SettingsSchema::email();
        $this->assertSame( 'owner@example.test', self::value( $rule, ' owner@example.test ' ), 'trimmed' );
        $this->assertSame( '', self::value( $rule, '  ' ), 'cleared' );
        foreach ( [ 'not-an-email', 'a@', 42, [ 'owner@example.test' ] ] as $refused ) {
            $this->assertNull( self::value( $rule, $refused ), 'refused: ' . json_encode( $refused ) );
        }
    }

    public function testUrlKeepsAnAbsoluteWebAddressOrNothingAndRefusesTheRest(): void {
        $rule = SettingsSchema::url();
        $this->assertSame( 'https://www.example.test/page?x=1', self::value( $rule, ' https://www.example.test/page?x=1 ' ) );
        $this->assertSame( 'http://localhost/x', self::value( $rule, 'http://localhost/x' ), 'localhost is allowed' );
        $this->assertSame( '', self::value( $rule, '' ) );
        $this->assertSame( 700, mb_strlen( (string) self::value( $rule, 'https://example.test/' . str_repeat( 'p', 800 ) ) ), 'cut to 700 characters' );
        foreach ( [ 'ftp://example.test/f', 'https://intranet/x', 'not a url', [ 'https://example.test/' ] ] as $refused ) {
            $this->assertNull( self::value( $rule, $refused ), 'refused: ' . json_encode( $refused ) );
        }
    }

    public function testDomainReducesToALowerCaseHostWithoutWwwAndRefusesTheRest(): void {
        $rule = SettingsSchema::domain();
        $this->assertSame( 'example.test', self::value( $rule, 'www.Example.TEST' ) );
        $this->assertSame( 'sub.example.test', self::value( $rule, 'https://sub.example.test:8080/path' ), 'a URL is reduced to its host' );
        foreach ( [ '', 'localhost', 'not a domain', 'example', [ 'example.test' ] ] as $refused ) {
            $this->assertNull( self::value( $rule, $refused ), 'refused: ' . json_encode( $refused ) );
        }
    }

    public function testTimezoneKeepsAZoneOrNothingAndRefusesTheRest(): void {
        $rule = SettingsSchema::timezone();
        $this->assertSame( 'Europe/Berlin', self::value( $rule, ' Europe/Berlin ' ) );
        $this->assertSame( '', self::value( $rule, '' ), "'' = derived from the site" );
        $this->assertNull( self::value( $rule, 'Not/AZone' ) );
        $this->assertNull( self::value( $rule, [ 'UTC' ] ) );
    }

    public function testListSplitsTextDropsUnusableItemsDuplicatesAndExtrasAndMayBeEmptied(): void {
        $strings = SettingsSchema::list();
        $this->assertSame( [ 'one', 'two', 'three' ], self::value( $strings, "one, two\nthree" ), 'text split at commas and line ends' );
        $this->assertSame( [ 'Alpha', 'beta' ], self::value( $strings, [ 'Alpha', 'beta', 'Alpha', '', '  ', [ 'nested' ] ] ), 'duplicates, empty and non-scalar items dropped' );
        $this->assertSame( [], self::value( $strings, [] ), 'a list may be emptied' );
        $this->assertCount( 500, self::value( $strings, array_map( 'strval', range( 1, 530 ) ) ), 'at most 500 items' );
        $this->assertSame( 500, mb_strlen( self::value( $strings, [ str_repeat( 'y', 700 ) ] )[0] ), 'items cut to 500 characters' );
        $this->assertNull( self::value( $strings, 5 ), 'a number is not a list' );

        $hosts = SettingsSchema::list( 'domain' );
        $this->assertSame( [ 'example.test', 'sub.example.test' ], self::value( $hosts, [ 'https://www.Example.test/path', 'not a domain', 'sub.example.test', 'www.example.test' ] ), 'host list: reduced, lower case, deduplicated' );

        $urls = SettingsSchema::list( 'url' );
        $this->assertSame( [ 'https://www.example.test/a' ], self::value( $urls, [ 'https://www.example.test/a', 'example', 'ftp://example.test/' ] ) );

        $days = self::schema()->rule( 'outreach.send_days' );
        $this->assertSame( [ 3, 4, 0, 7 ], self::value( $days, [ 3, '4', 9, 'x', 0, -1, 7, '7' ] ), 'integers 0-7, others dropped' );

        $topics = SettingsSchema::list( 'string', [ 'max_items' => 2, 'item_max' => 3, 'case' => 'lower', 'unique' => 'string' ] );
        $this->assertSame( [ 'abc', 'xy' ], self::value( $topics, [ 'ABCD', 'xy', 'zz' ] ), 'own limits and case' );
    }

    public function testRecordsKeepObjectsWithTheirRequiredFieldsAndApplyEachFieldsRule(): void {
        $rule = self::schema()->rule( 'content.linkable_assets' );
        $this->assertSame( SettingsSchema::RECORDS, $rule['type'] );
        $clean = self::value(
            $rule,
            [
                [ 'url' => 'https://www.example.test/guide/', 'title' => '  Buying <b>guide</b> ', 'summary' => "How to choose\na supplier.", 'topics' => 'Guides, buying, GUIDES', 'kind' => 'Guide', 'extra' => 'ignored' ],
                [ 'url' => 'https://www.example.test/guide/', 'title' => '' ],
                [ 'url' => 'not a url', 'title' => 'No URL' ],
                'not an object',
                [ 'url' => 'https://www.example.test/tool/', 'title' => 'Tool', 'kind' => [ 'x' ] ],
            ]
        );
        $this->assertSame(
            [
                [ 'url' => 'https://www.example.test/guide/', 'title' => 'Buying guide', 'summary' => "How to choose\na supplier.", 'topics' => [ 'guides', 'buying' ], 'kind' => 'guide' ],
                [ 'url' => 'https://www.example.test/tool/', 'title' => 'Tool', 'summary' => '', 'topics' => [], 'kind' => 'guide' ],
            ],
            $clean,
            'objects without a URL or a title are dropped; fields in the declared order; missing fields get their default'
        );
        $this->assertSame( [], self::value( $rule, 'text' ), 'text is split into items, none of them an object' );
        $this->assertCount( 100, self::value( $rule, array_fill( 0, 120, [ 'url' => 'https://www.example.test/a/', 'title' => 'A' ] ) ), 'at most 100' );
        $this->assertNull( self::value( $rule, 5 ) );
    }

    public function testSecretRulesCheckTheKeyTheLengthAndTheJsonShape(): void {
        $this->assertSame( '', SettingsSchema::secretProblem( 'seranking_api_key', 'test-key-1' ) );
        $this->assertSame( 'invalid_key', SettingsSchema::secretProblem( 'not_a_secret', 'x' ) );
        $this->assertNull( SettingsSchema::secretRule( 'not_a_secret' ) );
        $this->assertSame( '', SettingsSchema::secretProblem( 'brave_api_key', str_repeat( 'k', 20000 ) ) );
        $this->assertSame( 'too_long', SettingsSchema::secretProblem( 'brave_api_key', str_repeat( 'k', 20001 ) ) );
        $this->assertSame( 'invalid_service_account_json', SettingsSchema::secretProblem( 'google_service_account_json', '{"client_email":"a@example.test"}' ), 'json: a key is missing' );
        $this->assertSame( 'invalid_service_account_json', SettingsSchema::secretProblem( 'google_service_account_json', 'not json' ) );
        $this->assertSame( '', SettingsSchema::secretProblem( 'google_service_account_json', '{"client_email":"a@example.test","private_key":"k"}' ) );
        $rule = SettingsSchema::secretRule( 'google_service_account_json' );
        $this->assertSame( [ 'type' => SettingsSchema::SECRET, 'max' => 20000, 'json_keys' => [ 'client_email', 'private_key' ] ], $rule );
        $this->assertSame( 'k1', self::value( SettingsSchema::secret( 5 ), 'k1' ) );
        $this->assertNull( self::value( SettingsSchema::secret( 5 ), 'too-long' ) );
    }

    public function testReadOnlyKeysAreNeverWritten(): void {
        $this->assertNull( self::value( SettingsSchema::readOnly(), 'abc' ) );
        $this->assertSame(
            [ 'compliance' => [ 'storefront_gate' => false ] ],
            self::schema()->sanitize( [ 'compliance' => [ 'acknowledged_hash' => 'abc', 'acknowledged_by' => 3, 'acknowledged_at' => '2026-01-01', 'storefront_gate' => false ], 'seo' => [ 'indexnow_key' => 'k1' ] ] ),
            'read-only keys are left out, and a section left empty with them'
        );
    }

    // ------------------------------------------------------------------
    // A whole tree
    // ------------------------------------------------------------------

    public function testSanitiseKeepsKnownKeysOnlyAndNeverAnEmptyObject(): void {
        $schema = self::schema();
        $this->assertSame(
            [ 'automation' => [ 'tick_interval' => 120, 'daily_limits' => [ 'submissions' => 0 ] ], 'outreach' => [ 'send_days' => [] ] ],
            $schema->sanitize(
                [
                    'no_such_section' => [ 'x' => 1 ],
                    'automation'      => [ 'no_such_key' => true, 'tick_interval' => '120', 'enabled' => 'maybe', 'daily_limits' => [ 'submissions' => -4, 'no_such_counter' => 3 ], 'weekly_limits' => [ 'no_such_counter' => 3 ] ],
                    'discovery'       => 'on',
                    'outreach'        => [ 'send_days' => [] ],
                    'site'            => [ 'email' => [ 'nested' ] ],
                    7                 => [ 'x' => 1 ],
                ]
            ),
            'unknown sections and keys, refused values and groups left empty are dropped; a list may be emptied'
        );
        $this->assertSame( [], $schema->sanitize( [ 'automation' => [ 'tick_interval' => [ 'value' => 120 ] ] ] ), 'an object for a scalar key is refused' );
        $this->assertSame( [], $schema->sanitize( [ 'automation' => [ 'daily_limits' => [ 1, 2 ] ] ] ), 'a list for a group is refused' );
        $this->assertSame( [ 'providers' => [ 'seranking' => [ 'fetch_summary' => true ] ] ], $schema->sanitize( [ 'providers' => [ 'seranking' => [ 'fetch_summary' => 'yes' ] ] ] ), 'an optional key is accepted' );
    }

    /**
     * A key is one segment of a dot path. A key that holds a dot ('automation.daily_limits' at the top, or
     * 'daily_limits.submissions' inside automation) names no node of the tree: the engine (e160656) walked the
     * defaults one segment at a time and left such keys out, and so does the schema.
     */
    public function testSanitiseLeavesOutKeysThatHoldADotAtEveryLevel(): void {
        $schema = self::schema();
        foreach (
            [
                [ 'automation.daily_limits' => [ 'submissions' => 999 ] ],
                [ 'providers.ai' => [ 'max_tokens' => 900, 'enabled' => true ] ],
                [ 'automation' => [ 'daily_limits.submissions' => 999 ] ],
                [ 'providers' => [ 'seranking.enabled' => false, 'ai.enabled' => true ] ],
                [ 'automation' => [ 'daily_limits' => [ 'submissions.x' => 1 ] ] ],
                [ 'automation.enabled' => false ],
            ] as $input
        ) {
            $this->assertSame( [], $schema->sanitize( $input ), (string) json_encode( $input ) );
        }
        $this->assertSame(
            [ 'automation' => [ 'daily_limits' => [ 'submissions' => 7 ] ], 'providers' => [ 'seranking' => [ 'enabled' => false ] ] ],
            $schema->sanitize(
                [
                    'automation.daily_limits' => [ 'submissions' => 999 ],
                    'automation'              => [ 'daily_limits.submissions' => 999, 'daily_limits' => [ 'submissions' => 7 ] ],
                    'providers'               => [ 'seranking.enabled' => true, 'seranking' => [ 'enabled' => false ] ],
                ]
            ),
            'the keys of the tree are kept, the dotted ones beside them are left out'
        );
    }

    /**
     * Through POST /settings: a dotted key is never stored, so it can neither be reported as a change that did not
     * happen nor hide a real change of the same setting from the response and the audit log.
     */
    public function testPostSettingsStoresNoDottedKeyAndReportsAndAuditsTheRealChange(): void {
        $GLOBALS['lga_test_caps'] = [ 'manage_options' => true ];
        $post = static function ( array $settings ): array {
            $request = new WP_REST_Request( 'POST', '/lga/v1/settings' );
            $request->set_param( 'settings', $settings );
            return ( new SettingsController( Container::plugin()->container() ) )->update( $request );
        };
        $audited = static fn(): array => array_map( static fn( array $row ): mixed => $row['details']['changed'] ?? null, Container::get( AuditLog::class )->findBy( [ 'action' => 'settings.updated' ] ) );
        $path    = 'automation.daily_limits.submissions';
        $default = Container::get( Settings::class )->get( $path );
        $this->assertNotSame( 50, $default );

        $first = $post( [ 'automation' => [ 'daily_limits.submissions' => 999 ], 'automation.daily_limits' => [ 'submissions' => 999 ], 'providers' => [ 'seranking.enabled' => false, 'ai.enabled' => true ] ] );
        $this->assertSame( [], $first['changed'], 'nothing changed' );
        $stored = get_option( Settings::OPTION, [] );
        $this->assertSame( [], self::dottedKeys( is_array( $stored ) ? $stored : [] ), 'no dotted key is stored' );
        $this->assertSame( $default, Container::get( Settings::class )->get( $path ) );
        $this->assertTrue( Container::get( Settings::class )->get( 'providers.seranking.enabled' ) );
        $this->assertSame( [], $audited(), 'no settings.updated audit row for a change that did not happen' );

        $second = $post( [ 'automation' => [ 'daily_limits' => [ 'submissions' => 50 ] ] ] );
        $this->assertSame( [ $path ], $second['changed'], 'the real change is reported' );
        $this->assertSame( 50, Container::get( Settings::class )->get( $path ) );
        $this->assertSame( [ [ $path ] ], $audited(), 'and audited' );
    }

    /**
     * @param array<mixed> $tree
     * @return list<string> the keys of $tree, at any depth, that hold a dot
     */
    private static function dottedKeys( array $tree ): array {
        $out = [];
        foreach ( $tree as $key => $value ) {
            if ( is_string( $key ) && str_contains( $key, '.' ) ) {
                $out[] = $key;
            }
            if ( is_array( $value ) ) {
                $out = array_merge( $out, self::dottedKeys( $value ) );
            }
        }
        return $out;
    }

    public function testAModuleAddedThroughTheFilterContributesItsRules(): void {
        add_filter( Modules::FILTER, static fn( array $list ): array => array_merge( $list, [ SettingsSchemaTestProbeModule::class ] ) );
        Container::reset();
        $schema = self::schema();
        $this->assertSame( SettingsSchema::int( 1, 9 ), $schema->rule( 'probe.limit' ) );
        $this->assertSame( [ 'probe' => [ 'limit' => 9, 'enabled' => false ] ], $schema->sanitize( [ 'probe' => [ 'limit' => 50, 'enabled' => 'off', 'other' => 1 ] ] ) );
        $this->assertSame( SettingsSchema::int( 60, 86400 ), $schema->rule( 'automation.tick_interval' ), 'a key another module declared keeps the first rule' );
        $this->assertSame( [ 'automation.tick_interval' => [ 'automation', 'probe' ] ], $schema->duplicates() );
        $this->assertNull( $schema->rule( 'probe' ), 'an entry without a section and key is left out' );
        $this->assertNull( $schema->rule( 'probe.bad' ), 'an entry with an unknown type is left out' );
        $this->assertSame( 3, Container::get( Settings::class )->get( 'probe.limit' ) );
    }
}

/** A module from an extension with its own settings section and rules (and one key it may not take over). */
final class SettingsSchemaTestProbeModule extends AbstractModule {

    public function id(): string {
        return 'probe';
    }

    public function settingsDefaults(): array {
        return [ 'probe' => [ 'enabled' => true, 'limit' => 3 ] ];
    }

    public function settingsRules(): array {
        return [
            'probe.enabled'           => SettingsSchema::bool(),
            'probe.limit'             => SettingsSchema::int( 1, 9 ),
            'automation.tick_interval' => SettingsSchema::int( 1, 10 ),
            'probe'                   => SettingsSchema::bool(),
            'probe.bad'               => [ 'type' => 'no_such_type' ],
        ];
    }
}
