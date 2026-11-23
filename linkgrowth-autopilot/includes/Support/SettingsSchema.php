<?php
/**
 * The settings schema (DESIGN.md §2.2, §3.1): the validation rule of every settings key, collected from the modules
 * (Module::settingsRules(), next to their Module::settingsDefaults()). Admin\Rest\SettingsController validates
 * POST /settings with it; nothing else is accepted.
 *
 * A rule is a plain array with a `type` and the options of that type (built with the static helpers below, so the
 * modules read like the §2.2 key list). Types, with what each does to a submitted value (null = refused, the key is
 * left unchanged):
 *
 *  - bool       true/false, or what FILTER_VALIDATE_BOOLEAN reads as one ("1", "off"); anything else is refused.
 *  - int        a number, cut to an integer; outside {min,max} it is clamped (`out_of_range` clamp) or refused (refuse).
 *  - float      a number; {min,max} as for int.
 *  - enum       sanitize_key() of the value; one of `values`, else `fallback` (when the rule has one) or refused.
 *  - string     the value as text, through the steps `sanitize` (text | textarea | key | none), `trim`, `strip` (a
 *               pattern of characters to remove), `case` (lower | upper); then '' is kept or refused (`empty`), the
 *               text must match `pattern` and have `length` characters (when set) and is cut to `max` characters.
 *  - email      trimmed; '' is kept; otherwise a valid address (sanitize_email()) or refused.
 *  - url        trimmed; '' is kept; otherwise an absolute http(s) URL with a dotted host (or localhost), at most 700
 *               characters, or refused.
 *  - domain     a host name without "www." (a URL is reduced to its host), lower case; anything else, '' included,
 *               is refused.
 *  - timezone   trimmed; '' (derive) or an IANA zone / UTC offset (Outreach\Compliance::validTimezone()).
 *  - list       a list (text is split at commas and line ends); each item is a string, an int (optionally within
 *               {min,max}, items outside are dropped), a domain or a URL; unusable items are dropped, at most
 *               `max_items`, no duplicates. A list may be emptied.
 *  - records    a list of objects with the declared `fields` (each a rule of the types above); an object whose
 *               `required` field is empty is dropped. Used for structured lists (linkable assets).
 *  - secret     a write-only credential (not a settings key: Support\Secrets stores it): at most `max` bytes and,
 *               with `json_keys`, a JSON object holding those keys. See secretProblem().
 *  - read_only  never written through POST /settings (`of` names the stored type, for the reader).
 *
 * A key accepted by POST /settings that is not part of Settings::defaults() (the services read it with their own
 * default) is declared with optional(): `in_defaults` false and the `default` the services use.
 *
 * The engine's SettingsController applied the same rules through a range table and lists of key names (by leaf key);
 * WP2 moved them here unchanged, one rule per key in the module that owns the key's section (the wiring snapshot
 * records the outcome of 975 probes per key and type and stayed identical). As in the engine, a refused value is left
 * out silently and the rest of the request is saved; the 400 `lga_invalid_setting` answer of DESIGN.md §2.2 and the
 * new rule types it lists (iso_country, bcp47, weights, ...) come with the complete schema in WP4a.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Support;

use LinkGrowth\Autopilot\Core\Module;
use LinkGrowth\Autopilot\Outreach\Compliance;
use LinkGrowth\Autopilot\Plugin;

final class SettingsSchema {

    public const BOOL      = 'bool';
    public const INT       = 'int';
    public const FLOAT     = 'float';
    public const ENUM      = 'enum';
    public const STRING    = 'string';
    public const EMAIL     = 'email';
    public const URL       = 'url';
    public const DOMAIN    = 'domain';
    public const TIMEZONE  = 'timezone';
    public const LIST      = 'list';
    public const RECORDS   = 'records';
    public const SECRET    = 'secret';
    public const READ_ONLY = 'read_only';

    public const TYPES = [ self::BOOL, self::INT, self::FLOAT, self::ENUM, self::STRING, self::EMAIL, self::URL, self::DOMAIN, self::TIMEZONE, self::LIST, self::RECORDS, self::SECRET, self::READ_ONLY ];

    /** Out-of-range numbers: cut to the nearest bound, or refused. */
    public const CLAMP  = 'clamp';
    public const REFUSE = 'refuse';

    /** A list or records value sent as text is split here (the settings form sends lists as lines or commas). */
    private const LIST_SEPARATORS = '/[\r\n,]+/';

    /** Longest URL kept (also the bound of validUrl()). */
    private const URL_MAX = 700;

    /** Rules of the write-only credentials (Support\Secrets::KEYS); every other valid secret key gets secret(). */
    private const SECRET_RULES = [
        'google_service_account_json' => [ 'type' => self::SECRET, 'max' => 20000, 'json_keys' => [ 'client_email', 'private_key' ] ],
    ];

    /** @var array<string,array<string,mixed>>|null dot path => rule (first module wins) */
    private ?array $rules = null;

    /** @var array<string,list<string>> dot path => ids of the modules that declared it, when more than one did */
    private array $duplicates = [];

    /** @var array<string,true>|null object paths (every prefix of a rule path) */
    private ?array $nodes = null;

    /** @param list<Module>|null $modules the loaded modules (null = Plugin::modules()) */
    public function __construct( private ?array $modules = null ) {}

    // ------------------------------------------------------------------
    // Rule helpers (used by the modules' settingsRules())
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    public static function bool(): array {
        return [ 'type' => self::BOOL ];
    }

    /** @return array<string,mixed> an integer of at least $min (and at most $max) */
    public static function int( int $min = 0, ?int $max = null, string $outOfRange = self::CLAMP ): array {
        return [ 'type' => self::INT, 'min' => $min, 'max' => $max, 'out_of_range' => $outOfRange ];
    }

    /** @return array<string,mixed> */
    public static function float( ?float $min = null, ?float $max = null, string $outOfRange = self::CLAMP ): array {
        return [ 'type' => self::FLOAT, 'min' => $min, 'max' => $max, 'out_of_range' => $outOfRange ];
    }

    /**
     * @param list<string> $values
     * @return array<string,mixed> one of $values; anything else becomes $fallback, or is refused when there is none
     */
    public static function enum( array $values, ?string $fallback = null ): array {
        return [ 'type' => self::ENUM, 'values' => array_values( $values ), 'fallback' => $fallback ];
    }

    /** @return array<string,mixed> one line of plain text (tags and line breaks removed), trimmed, at most $max characters */
    public static function text( int $max = 1000 ): array {
        return self::string( [ 'sanitize' => 'text', 'trim' => true, 'max' => $max ] );
    }

    /** @return array<string,mixed> plain text that keeps its line breaks, at most $max characters */
    public static function textarea( int $max = 2000 ): array {
        return self::string( [ 'sanitize' => 'textarea', 'max' => $max ] );
    }

    /**
     * @param array{sanitize?:string,trim?:bool,strip?:string,case?:string,empty?:string,pattern?:string,length?:int,max?:int} $options
     * @return array<string,mixed>
     */
    public static function string( array $options = [] ): array {
        return array_merge(
            [ 'type' => self::STRING, 'sanitize' => 'none', 'trim' => false, 'strip' => '', 'case' => '', 'empty' => 'keep', 'pattern' => '', 'length' => 0, 'max' => 0 ],
            $options
        );
    }

    /** @return array<string,mixed> */
    public static function email(): array {
        return [ 'type' => self::EMAIL ];
    }

    /** @return array<string,mixed> */
    public static function url(): array {
        return [ 'type' => self::URL ];
    }

    /** @return array<string,mixed> */
    public static function domain(): array {
        return [ 'type' => self::DOMAIN ];
    }

    /** @return array<string,mixed> */
    public static function timezone(): array {
        return [ 'type' => self::TIMEZONE ];
    }

    /**
     * @param string               $of      string | int | domain | url
     * @param array<string,mixed>  $options max_items (500), item_max (500 characters), min/max (int items), case
     *                                      (lower | upper, string items), unique (regular | string: how duplicates are
     *                                      compared, array_unique()'s SORT_REGULAR or SORT_STRING)
     * @return array<string,mixed>
     */
    public static function list( string $of = 'string', array $options = [] ): array {
        return array_merge( [ 'type' => self::LIST, 'of' => $of, 'max_items' => 500, 'item_max' => 500, 'min' => null, 'max' => null, 'case' => '', 'unique' => 'regular' ], $options );
    }

    /**
     * @param array<string,array<string,mixed>> $fields field => rule (a field rule may add `required` and `default`)
     * @return array<string,mixed>
     */
    public static function records( array $fields, int $maxItems = 100 ): array {
        return [ 'type' => self::RECORDS, 'fields' => $fields, 'max_items' => $maxItems ];
    }

    /**
     * @param list<string> $jsonKeys keys a JSON object value must have (non-empty)
     * @return array<string,mixed>
     */
    public static function secret( int $max = 20000, array $jsonKeys = [] ): array {
        return [ 'type' => self::SECRET, 'max' => $max, 'json_keys' => $jsonKeys ];
    }

    /** @return array<string,mixed> a key POST /settings never writes ($of: the type of the stored value) */
    public static function readOnly( string $of = self::STRING ): array {
        return [ 'type' => self::READ_ONLY, 'of' => $of ];
    }

    /**
     * $rule for a key that POST /settings accepts but Settings::defaults() does not list; the services read it with
     * $default.
     *
     * @param array<string,mixed> $rule
     * @return array<string,mixed>
     */
    public static function optional( array $rule, mixed $default ): array {
        return $rule + [ 'in_defaults' => false, 'default' => $default ];
    }

    // ------------------------------------------------------------------
    // The schema
    // ------------------------------------------------------------------

    /**
     * Every rule, by dot path, in module order. A path two modules declare keeps the first module's rule (see
     * duplicates()); an entry that is not an array with a known type is left out.
     *
     * @return array<string,array<string,mixed>>
     */
    public function rules(): array {
        if ( null !== $this->rules ) {
            return $this->rules;
        }
        $rules = [];
        $by    = [];
        foreach ( $this->modules() as $module ) {
            try {
                $declared = (array) $module->settingsRules();
                $id       = $module->id();
            } catch ( \Throwable $e ) {
                unset( $e );
                continue; // a module whose rules cannot be read loses its own keys only
            }
            foreach ( $declared as $path => $rule ) {
                if ( ! is_string( $path ) || 1 !== preg_match( '/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $path ) || ! is_array( $rule ) || ! in_array( $rule['type'] ?? null, self::TYPES, true ) ) {
                    continue;
                }
                $by[ $path ][] = $id;
                if ( ! isset( $rules[ $path ] ) ) {
                    $rules[ $path ] = $rule;
                }
            }
        }
        $this->duplicates = array_filter( $by, static fn( array $ids ): bool => count( $ids ) > 1 );
        $this->rules      = $rules;
        return $rules;
    }

    /** @return array<string,mixed>|null the rule of one dot path */
    public function rule( string $path ): ?array {
        return $this->rules()[ $path ] ?? null;
    }

    /** @return array<string,list<string>> dot path => module ids, for paths more than one module declared */
    public function duplicates(): array {
        $this->rules();
        return $this->duplicates;
    }

    /**
     * The defaults the rules belong to: Settings::defaults() flattened to dot paths (a list is one value), plus the
     * `default` of every optional() rule.
     *
     * @return array<string,mixed>
     */
    public function defaults(): array {
        $out = self::flatten( Settings::defaults() );
        foreach ( $this->rules() as $path => $rule ) {
            if ( false === ( $rule['in_defaults'] ?? true ) && ! array_key_exists( $path, $out ) ) {
                $out[ $path ] = $rule['default'] ?? null;
            }
        }
        return $out;
    }

    /**
     * Sanitises a partial settings tree (POST /settings `settings`): every value goes through the rule of its dot
     * path; unknown keys, refused values and read-only keys are left out, and so is an object left empty (it would
     * replace the whole section on merge). Lists may be emptied. A key is one segment of a path: a key that holds a
     * dot ('automation.daily_limits', or 'daily_limits.submissions' inside automation) is unknown, as it was for the
     * engine, which walked the defaults one segment at a time (such a key would be stored as it is, never read by
     * Settings::get(), and would hide changes of the real key from the changed list and the audit log).
     *
     * @param array<mixed> $input
     * @return array<string,mixed>
     */
    public function sanitize( array $input ): array {
        $out = [];
        foreach ( $input as $section => $value ) {
            if ( ! self::isSegment( $section ) || ! $this->isNode( $section ) || ! is_array( $value ) ) {
                continue;
            }
            $clean = $this->sanitizeNode( $value, $section );
            if ( [] !== $clean ) {
                $out[ $section ] = $clean;
            }
        }
        return $out;
    }

    /**
     * One value through one rule.
     *
     * @param array<string,mixed> $rule
     * @return mixed the value to store, or null when it is refused
     */
    public static function sanitizeValue( array $rule, mixed $value ): mixed {
        $type = (string) ( $rule['type'] ?? '' );
        if ( self::LIST === $type || self::RECORDS === $type ) {
            if ( is_string( $value ) ) {
                $value = preg_split( self::LIST_SEPARATORS, $value ) ?: [];
            }
            if ( ! is_array( $value ) ) {
                return null;
            }
            return self::RECORDS === $type ? self::sanitizeRecords( $rule, $value ) : self::sanitizeList( $rule, $value );
        }
        if ( is_array( $value ) || is_object( $value ) ) {
            return null;
        }
        switch ( $type ) {
            case self::BOOL:
                return is_bool( $value ) ? $value : filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
            case self::INT:
                return is_numeric( $value ) ? self::inRange( (int) $value, $rule ) : null;
            case self::FLOAT:
                return is_numeric( $value ) ? self::inRange( (float) $value, $rule ) : null;
            case self::ENUM:
                $v = strtolower( sanitize_key( (string) $value ) );
                if ( in_array( $v, (array) ( $rule['values'] ?? [] ), true ) ) {
                    return $v;
                }
                return isset( $rule['fallback'] ) ? (string) $rule['fallback'] : null;
            case self::STRING:
                return self::sanitizeString( $rule, (string) $value );
            case self::EMAIL:
                $s = trim( (string) $value );
                if ( '' === $s ) {
                    return '';
                }
                $email = sanitize_email( $s );
                return is_email( $email ) ? $email : null;
            case self::URL:
                $s = trim( (string) $value );
                if ( '' === $s ) {
                    return '';
                }
                $url = self::validUrl( $s );
                return '' === $url ? null : $url;
            case self::DOMAIN:
                $d = self::domainValue( (string) $value );
                return '' === $d ? null : $d;
            case self::TIMEZONE:
                // '' = derive (the WordPress timezone, or the business region's zone when WordPress is on UTC).
                $s = trim( (string) $value );
                return '' === $s || Compliance::validTimezone( $s ) ? $s : null;
            case self::SECRET:
                return '' === self::secretProblemFor( $rule, (string) $value ) ? (string) $value : null;
        }
        return null; // read_only, or an unknown type
    }

    /** The rule of a write-only credential (Support\Secrets), or null when $key is not one. @return array<string,mixed>|null */
    public static function secretRule( string $key ): ?array {
        if ( ! Secrets::isValidKey( $key ) ) {
            return null;
        }
        return self::SECRET_RULES[ $key ] ?? self::secret();
    }

    /**
     * Why a credential value cannot be stored: '' (it can), invalid_key (not a credential the plugin stores),
     * too_long (over the rule's `max` bytes) or invalid_service_account_json (a JSON key without the rule's
     * `json_keys`).
     */
    public static function secretProblem( string $key, string $value ): string {
        $rule = self::secretRule( $key );
        return null === $rule ? 'invalid_key' : self::secretProblemFor( $rule, $value );
    }

    /** Absolute http(s) URL with a dotted host (or localhost), at most 700 characters; '' when unusable. */
    public static function validUrl( string $value ): string {
        $url = esc_url_raw( trim( $value ) );
        if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
            return '';
        }
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        if ( '' === $host || ( ! str_contains( $host, '.' ) && 'localhost' !== strtolower( $host ) ) ) {
            return '';
        }
        return mb_substr( $url, 0, self::URL_MAX );
    }

    /** Host-only, lower-case domain value without "www." ('' when unusable). */
    public static function domainValue( string $value ): string {
        $value = strtolower( trim( $value ) );
        if ( '' === $value ) {
            return '';
        }
        $host = ( str_contains( $value, '/' ) || str_contains( $value, ':' ) ) ? Url::host( $value ) : $value;
        $host = preg_replace( '/^www\./', '', $host ) ?? $host;
        return preg_match( '/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $host ) ? $host : '';
    }

    /**
     * A settings tree flattened to dot paths; a list (or an empty array) is one value.
     *
     * @param array<mixed> $tree
     * @return array<string,mixed>
     */
    public static function flatten( array $tree, string $prefix = '' ): array {
        $out = [];
        foreach ( $tree as $key => $value ) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
            if ( is_array( $value ) && ! Settings::isList( $value ) ) {
                $out += self::flatten( $value, $path );
            } else {
                $out[ $path ] = $value;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** @return list<Module> */
    private function modules(): array {
        if ( null === $this->modules ) {
            $this->modules = Plugin::instance()->modules();
        }
        return $this->modules;
    }

    /** Whether $path is an object of the tree (a section or a group such as automation.daily_limits). */
    private function isNode( string $path ): bool {
        if ( null === $this->nodes ) {
            $this->nodes = [];
            foreach ( array_keys( $this->rules() ) as $rulePath ) {
                $parts = explode( '.', $rulePath );
                array_pop( $parts );
                $node = '';
                foreach ( $parts as $part ) {
                    $node                 = '' === $node ? $part : $node . '.' . $part;
                    $this->nodes[ $node ] = true;
                }
            }
        }
        return isset( $this->nodes[ $path ] );
    }

    /**
     * @param array<mixed> $value
     * @return array<string,mixed>
     */
    private function sanitizeNode( array $value, string $path ): array {
        $rules = $this->rules();
        $out   = [];
        foreach ( $value as $key => $v ) {
            if ( ! self::isSegment( $key ) ) {
                continue;
            }
            $child = $path . '.' . $key;
            if ( isset( $rules[ $child ] ) ) {
                $clean = self::sanitizeValue( $rules[ $child ], $v );
                if ( null !== $clean ) {
                    $out[ $key ] = $clean;
                }
                continue;
            }
            if ( $this->isNode( $child ) && is_array( $v ) ) {
                $node = $this->sanitizeNode( $v, $child );
                if ( [] !== $node ) {
                    $out[ $key ] = $node;
                }
            }
        }
        return $out;
    }

    /** Whether a key of a settings tree is one segment of a dot path (the segments rules() accepts). */
    private static function isSegment( mixed $key ): bool {
        return is_string( $key ) && 1 === preg_match( '/^[a-z0-9_]+$/D', $key );
    }

    /** @param array<string,mixed> $rule */
    private static function inRange( int|float $n, array $rule ): int|float|null {
        $min = $rule['min'] ?? null;
        $max = $rule['max'] ?? null;
        if ( ( null !== $min && $n < $min ) || ( null !== $max && $n > $max ) ) {
            if ( self::REFUSE === ( $rule['out_of_range'] ?? self::CLAMP ) ) {
                return null;
            }
            $n = null !== $min ? max( $min, $n ) : $n;
            $n = null !== $max ? min( $max, $n ) : $n;
        }
        return $n;
    }

    /** @param array<string,mixed> $rule */
    private static function sanitizeString( array $rule, string $s ): ?string {
        switch ( (string) ( $rule['sanitize'] ?? 'none' ) ) {
            case 'text':
                $s = sanitize_text_field( $s );
                break;
            case 'textarea':
                $s = sanitize_textarea_field( $s );
                break;
            case 'key':
                $s = sanitize_key( $s );
                break;
        }
        if ( ! empty( $rule['trim'] ) ) {
            $s = trim( $s );
        }
        if ( '' !== (string) ( $rule['strip'] ?? '' ) ) {
            $s = preg_replace( (string) $rule['strip'], '', $s ) ?? '';
        }
        $case = (string) ( $rule['case'] ?? '' );
        if ( 'lower' === $case ) {
            $s = strtolower( $s );
        } elseif ( 'upper' === $case ) {
            $s = strtoupper( $s );
        }
        if ( '' === $s ) {
            return self::REFUSE === ( $rule['empty'] ?? 'keep' ) ? null : '';
        }
        if ( '' !== (string) ( $rule['pattern'] ?? '' ) && 1 !== preg_match( (string) $rule['pattern'], $s ) ) {
            return null;
        }
        $length = (int) ( $rule['length'] ?? 0 );
        if ( $length > 0 && mb_strlen( $s ) !== $length ) {
            return null;
        }
        $max = (int) ( $rule['max'] ?? 0 );
        return $max > 0 ? mb_substr( $s, 0, $max ) : $s;
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<mixed>        $value
     * @return list<mixed>
     */
    private static function sanitizeList( array $rule, array $value ): array {
        $of   = (string) ( $rule['of'] ?? 'string' );
        $case = (string) ( $rule['case'] ?? '' );
        $out  = [];
        foreach ( array_slice( array_values( $value ), 0, max( 0, (int) ( $rule['max_items'] ?? 500 ) ) ) as $v ) {
            if ( 'int' === $of ) {
                if ( is_numeric( $v ) ) {
                    $i = (int) $v;
                    if ( ( null !== ( $rule['min'] ?? null ) && $i < $rule['min'] ) || ( null !== ( $rule['max'] ?? null ) && $i > $rule['max'] ) ) {
                        continue;
                    }
                    $out[] = $i;
                }
                continue;
            }
            if ( ! is_scalar( $v ) ) {
                continue;
            }
            $s = trim( sanitize_text_field( (string) $v ) );
            if ( 'lower' === $case ) {
                $s = strtolower( $s );
            } elseif ( 'upper' === $case ) {
                $s = strtoupper( $s );
            }
            if ( '' === $s ) {
                continue;
            }
            if ( 'domain' === $of ) {
                $s = self::domainValue( $s );
            } elseif ( 'url' === $of ) {
                $s = self::validUrl( $s );
            }
            if ( '' === $s ) {
                continue;
            }
            $out[] = mb_substr( $s, 0, max( 1, (int) ( $rule['item_max'] ?? 500 ) ) );
        }
        return array_values( array_unique( $out, 'string' === ( $rule['unique'] ?? 'regular' ) ? SORT_STRING : SORT_REGULAR ) );
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<mixed>        $value
     * @return list<array<string,mixed>>
     */
    private static function sanitizeRecords( array $rule, array $value ): array {
        $fields = is_array( $rule['fields'] ?? null ) ? $rule['fields'] : [];
        $out    = [];
        foreach ( array_slice( array_values( $value ), 0, max( 0, (int) ( $rule['max_items'] ?? 100 ) ) ) as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $record = [];
            foreach ( $fields as $field => $fieldRule ) {
                $clean = self::sanitizeField( (array) $fieldRule, $item[ $field ] ?? null );
                if ( ! empty( $fieldRule['required'] ) && ( null === $clean || '' === $clean || [] === $clean ) ) {
                    continue 2;
                }
                $record[ (string) $field ] = $clean ?? ( $fieldRule['default'] ?? '' );
            }
            $out[] = $record;
        }
        return $out;
    }

    /**
     * One field of a record. A missing or non-scalar value counts as the field's `default` ('' without one); a list
     * field also takes one scalar, or text split at commas and line ends. An empty result becomes the `default`.
     *
     * @param array<string,mixed> $rule
     */
    private static function sanitizeField( array $rule, mixed $value ): mixed {
        $default = $rule['default'] ?? null;
        if ( self::LIST === ( $rule['type'] ?? '' ) ) {
            if ( is_string( $value ) ) {
                $value = preg_split( self::LIST_SEPARATORS, $value ) ?: [];
            }
            return self::sanitizeList( $rule, (array) ( $value ?? [] ) );
        }
        if ( ! is_scalar( $value ) ) {
            $value = is_scalar( $default ) ? $default : '';
        }
        $clean = self::sanitizeValue( $rule, $value );
        if ( '' === $clean && is_scalar( $default ) ) {
            return $default;
        }
        return $clean;
    }

    /** @param array<string,mixed> $rule */
    private static function secretProblemFor( array $rule, string $value ): string {
        if ( strlen( $value ) > (int) ( $rule['max'] ?? 20000 ) ) {
            return 'too_long';
        }
        $keys = (array) ( $rule['json_keys'] ?? [] );
        if ( [] !== $keys ) {
            $json = json_decode( $value, true );
            if ( ! is_array( $json ) ) {
                return 'invalid_service_account_json';
            }
            foreach ( $keys as $key ) {
                if ( empty( $json[ $key ] ) ) {
                    return 'invalid_service_account_json';
                }
            }
        }
        return '';
    }
}
