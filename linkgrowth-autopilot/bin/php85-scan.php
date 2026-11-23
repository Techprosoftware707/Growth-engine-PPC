<?php
/**
 * Rule engine of bin/php85-scan.sh (DESIGN.md §11.8). Development tooling only.
 *
 *   php bin/php85-scan.php [--root=DIR] [--min=8.1] [--files-out=FILE] [--verbose] [PATH ...]
 *
 * Two rule sets, both checked on the significant tokens of each file (comments, doc comments, string contents
 * and inline HTML are never matched, so a backtick in a comment or an SQL string is not a finding):
 *
 * 1. PHP 8.1-8.5 deprecations (always, on every scanned file): the §11.8 list (implicitly nullable parameters,
 *    non-canonical casts, the backtick operator, `case X;` / `default;`, null array offsets, __sleep/__wakeup,
 *    the no-op close functions, setAccessible(), E_STRICT, lcg_value(), DATE_RFC7231, CSV calls without an
 *    explicit $escape) plus further deprecations PHP 8.5.13 reports (see RULES).
 * 2. With --min=X.Y: syntax, functions and classes newer than PHP X.Y, on the shipped files only (the main
 *    file, uninstall.php and includes/), or on every PATH given. A function or class use is accepted inside a
 *    function_exists()/class_exists()/method_exists() or PHP_VERSION_ID / version_compare( PHP_VERSION … )
 *    guard (bin/scan-lib.php Guards), or when a floor file declares the function under the name the call
 *    resolves to (a polyfill; declarations in tests/ and bin/ do not count, and a namespaced function never
 *    satisfies a `\name()` call).
 *    New syntax cannot be guarded: the file would not parse on the older PHP.
 *
 * Without PATH arguments it scans the plugin under --root (default: the folder above bin/): the main file,
 * uninstall.php, includes/, tests/ and bin/ for the deprecations, and the main file, uninstall.php and
 * includes/ for the floor. Left out: exactly the folder tests/fixtures/compat-scan (Files::FIXTURES, the seeded
 * violations of tests/Unit/Wiring/CompatScansTest.php). PATH arguments (files of any extension, or folders)
 * replace that list.
 *
 * Output: one block per finding, "<file>:<line>: [<rule>] PHP <version>: <message>" and the source line.
 * Exit code: 0 no finding, 1 findings, 2 usage error.
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tools;

require_once __DIR__ . '/scan-lib.php';

final class Php85Scan
{
    /** @var array<string, array{0:string,1:string}> rule => [PHP version, message] */
    public const RULES = [
        // §11.8 list
        'implicit-nullable'   => ['8.4', 'implicitly nullable parameter; write ?Type (or Type|null) for a null default'],
        'noncanonical-cast'   => ['8.5', 'non-canonical cast; use (bool), (int), (float) or (string)'],
        'backtick'            => ['8.5', 'backtick shell operator; use shell_exec()'],
        'case-semicolon'      => ['8.5', 'case/default label ended by a semicolon; use a colon'],
        'null-offset'         => ['8.5', 'null used as an array offset or array_key_exists() key; use \'\''],
        'sleep-wakeup'        => ['8.5', '__sleep()/__wakeup() are soft-deprecated (no runtime notice in 8.5.13); use __serialize()/__unserialize()'],
        'noop-close'          => ['8.5', 'no-op close function is deprecated; drop the call'],
        'set-accessible'      => ['8.5', 'ReflectionProperty/ReflectionMethod::setAccessible() is deprecated (no effect since 8.1)'],
        'e-strict'            => ['8.4', 'E_STRICT is deprecated (the error level was removed)'],
        'lcg-value'           => ['8.4', 'lcg_value() is deprecated; use random_int() or Random\\Randomizer'],
        'date-rfc7231'        => ['8.5', 'DATE_RFC7231 / DateTimeInterface::RFC7231 are deprecated'],
        'csv-escape'          => ['8.4', 'CSV call without an explicit $escape argument'],
        // further deprecations reported by PHP 8.5.13 (verified with the toolchain of DESIGN.md §12.6)
        'http-response-header' => ['8.5', '$http_response_header is deprecated; use http_get_last_response_headers()'],
        'pdo-driver-constant' => ['8.5', 'driver-specific PDO constant is deprecated; use the Pdo\\<Driver> subclass constant'],
        'pdo-driver-method'   => ['8.5', 'driver-specific PDO method is deprecated; use the Pdo\\<Driver> subclass method'],
        'reflection-method-1' => ['8.4', 'new ReflectionMethod() with one argument is deprecated; use ReflectionMethod::createFromMethodName()'],
        'user-error'          => ['8.4', 'trigger_error() with E_USER_ERROR is deprecated; throw an exception'],
        'dollar-brace'        => ['8.2', '"${var}" string interpolation is deprecated; use "{$var}"'],
        'deprecated-function' => ['8.1', 'deprecated function'],
        'deprecated-constant' => ['8.1', 'deprecated constant'],
        // floor (--min)
        'readonly-class'      => ['8.2', 'readonly class'],
        'dnf-type'            => ['8.2', 'disjunctive normal form type'],
        'true-type'           => ['8.2', 'true type'],
        'standalone-type'     => ['8.2', 'standalone null or false type'],
        'trait-constant'      => ['8.2', 'constant declared in a trait'],
        'typed-constant'      => ['8.3', 'typed class constant'],
        'override-attribute'  => ['8.3', '#[\\Override] attribute'],
        'dynamic-const-fetch' => ['8.3', 'dynamic class constant fetch Foo::{$x}'],
        'property-hooks'      => ['8.4', 'property hooks'],
        'asymmetric-visibility' => ['8.4', 'asymmetric visibility (public private(set))'],
        'new-without-parens'  => ['8.4', 'member access on new Foo() without parentheses'],
        'deprecated-attribute' => ['8.4', '#[\\Deprecated] attribute'],
        'pipe-operator'       => ['8.5', 'pipe operator |>'],
        'clone-with'          => ['8.5', 'clone() with a second argument'],
        'nodiscard-attribute' => ['8.5', '#[\\NoDiscard] / #[\\DelayedTargetValidation] attribute'],
        'void-cast'           => ['8.5', '(void) cast'],
        'new-function'        => ['', 'function newer than the minimum PHP version'],
        'new-class'           => ['', 'class newer than the minimum PHP version'],
        'new-method'          => ['', 'method newer than the minimum PHP version'],
        'php-lint'            => ['', 'php -l failed on the oldest available PHP'],
    ];

    /** Functions added after PHP 8.1, by version (verified against PHP 8.3.6 and 8.5.13 where loaded). */
    public const NEW_FUNCTIONS = [
        '8.2' => ['mysqli_execute_query', 'ini_parse_quantity', 'memory_reset_peak_usage', 'curl_upkeep',
            'openssl_cipher_key_length', 'libxml_get_external_entity_loader', 'sodium_crypto_stream_xchacha20_xor_ic'],
        '8.3' => ['json_validate', 'mb_str_pad', 'str_increment', 'str_decrement', 'stream_context_set_options',
            'posix_sysconf', 'posix_pathconf', 'posix_fpathconf', 'posix_eaccess', 'socket_atmark',
            'ldap_connect_wallet', 'ldap_exop_sync'],
        '8.4' => ['array_find', 'array_find_key', 'array_any', 'array_all', 'mb_trim', 'mb_ltrim', 'mb_rtrim',
            'mb_ucfirst', 'mb_lcfirst', 'request_parse_body', 'bcdivmod', 'bcround', 'bcceil', 'bcfloor',
            'grapheme_str_split', 'http_get_last_response_headers', 'http_clear_last_response_headers', 'fpow',
            'pcntl_getcpu', 'pcntl_getcpuaffinity', 'pcntl_setcpuaffinity', 'pcntl_getqos_class',
            'pcntl_setqos_class', 'pcntl_waitid'],
        '8.5' => ['array_first', 'array_last', 'get_error_handler', 'get_exception_handler', 'locale_is_right_to_left',
            'grapheme_levenshtein', 'curl_multi_get_handles', 'curl_share_init_persistent'],
    ];

    /** Classes added after PHP 8.1 (lower-case FQ name => version). */
    public const NEW_CLASSES = [
        'random\\randomizer' => '8.2', 'random\\engine\\mt19937' => '8.2', 'random\\engine\\pcgoneseq128xslrr64' => '8.2',
        'random\\engine\\xoshiro256starstar' => '8.2', 'random\\engine\\secure' => '8.2', 'random\\engine' => '8.2',
        'random\\cryptosafeengine' => '8.2', 'random\\intervalboundary' => '8.3',
        'dom\\htmldocument' => '8.4', 'dom\\xmldocument' => '8.4', 'bcmath\\number' => '8.4',
        'pdo\\sqlite' => '8.4', 'pdo\\mysql' => '8.4', 'pdo\\pgsql' => '8.4', 'pdo\\firebird' => '8.4',
        'pdo\\odbc' => '8.4', 'pdo\\dblib' => '8.4',
        'uri\\rfc3986\\uri' => '8.5', 'uri\\whatwg\\url' => '8.5', 'intllistformatter' => '8.5',
    ];

    /** Methods added after PHP 8.1 that are distinctive enough to match by name (lower-case name => version). */
    public const NEW_METHODS = ['getbytesfromstring' => '8.3', 'isrighttoleft' => '8.5'];

    /** Deprecated functions (lower-case name => version). */
    public const DEPRECATED_FUNCTIONS = [
        'strftime' => '8.1', 'gmstrftime' => '8.1', 'date_sunrise' => '8.1', 'date_sunset' => '8.1',
        'strptime' => '8.1', 'mhash' => '8.1', 'mhash_count' => '8.1', 'mhash_get_block_size' => '8.1',
        'mhash_get_hash_name' => '8.1', 'mhash_keygen_s2k' => '8.1', 'odbc_result_all' => '8.1',
        'utf8_encode' => '8.2', 'utf8_decode' => '8.2', 'assert_options' => '8.3',
        'xml_set_object' => '8.4', 'mysqli_ping' => '8.4', 'mysqli_kill' => '8.4', 'mysqli_refresh' => '8.4',
    ];

    /** Driver-specific PDO methods deprecated in 8.5 (distinctive names, matched on any receiver). */
    public const PDO_DRIVER_METHODS = ['sqlitecreatefunction', 'sqlitecreateaggregate', 'sqlitecreatecollation',
        'pgsqlcopyfromarray', 'pgsqlcopyfromfile', 'pgsqlcopytoarray', 'pgsqlcopytofile', 'pgsqlgetnotify',
        'pgsqlgetpid', 'pgsqllobcreate', 'pgsqllobopen', 'pgsqllobunlink'];

    public const NOOP_CLOSE = ['curl_close', 'curl_share_close', 'finfo_close', 'xml_parser_free', 'imagedestroy'];

    /** Deprecated global constants (name => version). */
    public const DEPRECATED_CONSTANTS = [
        'FILTER_SANITIZE_STRING' => '8.1', 'FILTER_SANITIZE_STRIPPED' => '8.1', 'FILE_BINARY' => '8.1',
        'FILE_TEXT' => '8.1', 'MT_RAND_PHP' => '8.3', 'SUNFUNCS_RET_STRING' => '8.4', 'SUNFUNCS_RET_DOUBLE' => '8.4',
        'SUNFUNCS_RET_TIMESTAMP' => '8.4',
    ];

    /**
     * Deprecations PHP raises when the code runs (not when it compiles): a use inside a PHP_VERSION_ID < X or
     * version_compare( PHP_VERSION, X, '<' ) branch with X at or below the deprecating version is not reported.
     * Compile-time deprecations (casts, backticks, case;, implicit nullable, "${}", $http_response_header) are
     * raised whatever the branch, so they are always reported.
     */
    private const RUNTIME_RULES = ['null-offset', 'noop-close', 'set-accessible', 'e-strict', 'lcg-value', 'date-rfc7231',
        'csv-escape', 'pdo-driver-constant', 'pdo-driver-method', 'reflection-method-1', 'user-error',
        'deprecated-function', 'deprecated-constant'];

    /** CSV calls: function or method => 1-based position of $escape. */
    private const CSV_FUNCTIONS = ['fgetcsv' => 5, 'fputcsv' => 5, 'str_getcsv' => 4];
    private const CSV_METHODS = ['fgetcsv' => 3, 'fputcsv' => 4, 'setcsvcontrol' => 3];

    /** @var list<array{file:string,line:int,rule:string,php:string,msg:string,src:string}> */
    public array $findings = [];
    /**
     * Functions the floor files declare (lower-case fully qualified names, no leading backslash). Only these count
     * as polyfills: a call is accepted when it resolves to one of them (bin/scan-lib.php Source::resolveFunction:
     * `\name()` only to the global function, an unqualified call in namespace N to N\name or the global one).
     *
     * @var array<string, true>
     */
    private array $declared = [];
    private ?string $min;
    private bool $floor = false;
    private Source $s;
    private Guards $g;

    public function __construct(?string $min)
    {
        $this->min = $min === null ? null : Version::normalize($min);
    }

    /** @param list<array{0:string,1:string,2:bool}> $files [absolute path, display path, floor] */
    public function run(array $files): void
    {
        $sources = [];
        foreach ($files as [$abs, $rel, $floor]) {
            $src = Source::fromFile($abs, $rel);
            $sources[] = [$src, $floor];
            if (!$floor) {
                continue; // a function declared in tests/ or bin/ does not ship, so it is no polyfill
            }
            for ($i = 0; $i < $src->n; $i++) {
                $k = $src->text($i + 1) === '&' || $src->id($i + 1) === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG ? $i + 2 : $i + 1;
                if ($src->id($i) === T_FUNCTION && $src->id($k) === T_STRING && $src->text($k + 1) === '('
                    && !in_array($src->bodyKindOf($i), ['class', 'trait', 'interface', 'enum'], true)) {
                    $this->declared[strtolower(ltrim($src->namespaceAt($i) . '\\' . $src->text($k), '\\'))] = true;
                }
            }
        }
        foreach ($sources as [$src, $floor]) {
            $this->s = $src;
            $this->g = new Guards($src);
            $this->floor = $floor && $this->min !== null;
            $this->scanFile();
        }
        usort($this->findings, static function (array $a, array $b): int {
            return [$a['file'], $a['line'], $a['rule']] <=> [$b['file'], $b['line'], $b['rule']];
        });
    }

    private function hit(int $i, string $rule, string $php = '', string $detail = ''): void
    {
        $meta = self::RULES[$rule];
        $php = $php !== '' ? $php : $meta[0];
        if (in_array($rule, self::RUNTIME_RULES, true) && $this->below($i, $php)) {
            return;
        }
        $line = $this->s->line($i);
        foreach ($this->findings as $f) {
            if ($f['file'] === $this->s->rel && $f['line'] === $line && $f['rule'] === $rule && $f['msg'] === $meta[1] . $detail) {
                return;
            }
        }
        $this->findings[] = ['file' => $this->s->rel, 'line' => $line, 'rule' => $rule, 'php' => $php,
            'msg' => $meta[1] . $detail, 'src' => $this->s->lineText($line)];
    }

    /** Floor rule for a PHP version: active only for floor files when the version is newer than --min. */
    private function floorHit(int $i, string $rule, string $php = '', string $detail = ''): void
    {
        $php = $php !== '' ? $php : self::RULES[$rule][0];
        if ($this->floor && Version::cmp($php, (string) $this->min) > 0) {
            $this->hit($i, $rule, $php, $detail);
        }
    }

    private function scanFile(): void
    {
        $s = $this->s;
        $inBacktick = false;
        for ($i = 0; $i < $s->n; $i++) {
            $id = $s->id($i);
            $text = $s->text($i);
            $lower = strtolower($text);

            if ($id === 96) { // the '`' operator token (string fragments that read "`" have another id)
                if (!$inBacktick) {
                    $this->hit($i, 'backtick');
                }
                $inBacktick = !$inBacktick;
                continue;
            }
            if ($id === T_FUNCTION || $id === T_FN) {
                $this->functionSignature($i);
            }
            if (in_array($id, [T_BOOL_CAST, T_INT_CAST, T_DOUBLE_CAST, T_STRING_CAST], true)) {
                $norm = strtolower((string) preg_replace('/\s+/', '', $text));
                if (in_array($norm, ['(boolean)', '(integer)', '(double)', '(binary)'], true)) {
                    $this->hit($i, 'noncanonical-cast', '', ' ' . $norm);
                }
            }
            if ((defined('T_VOID_CAST') && $id === constant('T_VOID_CAST'))
                || ($text === '(' && $s->lower($i + 1) === 'void' && $s->text($i + 2) === ')'
                    && in_array($s->text($i - 1), [';', '{', '}', ''], true))) {
                $this->floorHit($i, 'void-cast');
            }
            if ($id === T_CASE) {
                $this->caseLabel($i);
            }
            if ($id === T_DEFAULT && $s->text($i + 1) === ';') {
                $this->hit($i, 'case-semicolon');
            }
            if ($text === '[' && ltrim($s->lower($i + 1), '\\') === 'null' && $s->text($i + 2) === ']' && $this->isOffsetBase($i - 1)) {
                $this->hit($i, 'null-offset');
            }
            if (ltrim($lower, '\\') === 'null' && ($id === T_STRING || $id === T_NAME_FULLY_QUALIFIED)
                && $s->id($i + 1) === T_DOUBLE_ARROW && $this->isArrayElementStart($i)) {
                $this->hit($i, 'null-offset'); // [null => …], array(… , NULL => …)
            }
            if ($id === T_VARIABLE && $text === '$http_response_header') {
                $this->hit($i, 'http-response-header');
            }
            if ($id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $this->hit($i, 'dollar-brace');
            }
            if ($id === T_FUNCTION && $s->id($i + 1) === T_STRING && in_array($s->lower($i + 1), ['__sleep', '__wakeup'], true)) {
                $this->hit($i + 1, 'sleep-wakeup');
            }
            if ($s->isFunctionCall($i)) {
                $this->functionCall($i);
            } elseif (($id === T_OBJECT_OPERATOR || $id === T_NULLSAFE_OBJECT_OPERATOR) && $s->text($i + 2) === '(') {
                $this->methodCall($i + 1);
            } elseif ($id === T_DOUBLE_COLON) {
                $this->staticAccess($i);
            } elseif ($id === T_STRING || $id === T_NAME_FULLY_QUALIFIED) {
                $this->constant($i);
            }
            if ($id === T_NEW) {
                $this->newExpression($i);
            }
            if ($id === T_READONLY) {
                $this->readonlyClass($i);
            }
            if ($id === T_ATTRIBUTE) {
                $this->attribute($i);
            }
            if ((defined('T_PUBLIC_SET') && in_array($id, [constant('T_PUBLIC_SET'), constant('T_PROTECTED_SET'), constant('T_PRIVATE_SET')], true))
                || (in_array($id, [T_PUBLIC, T_PROTECTED, T_PRIVATE], true) && $s->text($i + 1) === '('
                    && $s->lower($i + 2) === 'set' && $s->text($i + 3) === ')')) {
                $this->floorHit($i, 'asymmetric-visibility');
            }
            if ((defined('T_PIPE') && $id === constant('T_PIPE'))
                || ($text === '|' && ($next = $s->rawNext($i)) !== null && $next->text === '>')) {
                $this->floorHit($i, 'pipe-operator');
            }
            if ($id === T_CLONE && $s->text($i + 1) === '(' && count($s->args($i + 1)) >= 2) {
                $this->floorHit($i, 'clone-with');
            }
            if ($id === T_CONST) {
                $this->classConstant($i);
            }
            if ($id === T_VARIABLE && $this->isPropertyDeclaration($i)) {
                $this->propertyDeclaration($i);
            }
        }
    }

    private function functionSignature(int $i): void
    {
        $s = $this->s;
        $o = $i + 1;
        for ($k = $i + 1; $k <= $i + 3; $k++) {
            if ($s->text($k) === '(') {
                $o = $k;
                break;
            }
        }
        if ($s->text($o) !== '(' || !isset($s->match[$o])) {
            return;
        }
        $c = $s->match[$o];
        foreach ($s->args($o) as [$a, $b]) {
            $this->parameter($a, $b);
        }
        $k = $c + 1;
        if ($s->id($k) === T_USE && $s->text($k + 1) === '(' && isset($s->match[$k + 1])) {
            $k = $s->match[$k + 1] + 1;
        }
        if ($s->text($k) === ':') {
            $from = $k + 1;
            $to = $from;
            while ($to < $s->n && !in_array($s->text($to), ['{', ';'], true) && $s->id($to) !== T_DOUBLE_ARROW) {
                $to++;
            }
            $this->typeRules($from, $to - 1);
        }
    }

    private function parameter(int $a, int $b): void
    {
        $s = $this->s;
        $k = $a;
        while ($k <= $b) {
            $id = $s->id($k);
            if ($id === T_ATTRIBUTE && isset($s->match[$k])) {
                $k = $s->match[$k] + 1;
                continue;
            }
            if (in_array($id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY, T_FINAL], true)
                || (defined('T_PUBLIC_SET') && in_array($id, [constant('T_PUBLIC_SET'), constant('T_PROTECTED_SET'), constant('T_PRIVATE_SET')], true))) {
                $k++;
                if ($s->text($k) === '(' && $s->lower($k + 1) === 'set' && $s->text($k + 2) === ')') {
                    $k += 3;
                }
                continue;
            }
            break;
        }
        $typeFrom = $k;
        $var = -1;
        for ($j = $k; $j <= $b; $j++) {
            if ($s->id($j) === T_VARIABLE) {
                $var = $j;
                break;
            }
        }
        if ($var < 0) {
            return;
        }
        $typeTo = $var - 1;
        while ($typeTo >= $typeFrom && ($s->id($typeTo) === T_ELLIPSIS
            || $s->id($typeTo) === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG)) {
            $typeTo--;
        }
        if ($typeTo < $typeFrom) {
            return;
        }
        $type = $s->joined($typeFrom, $typeTo);
        $this->typeRules($typeFrom, $typeTo);
        if ($s->text($var + 1) === '=' && $var + 2 === $b && ltrim($s->lower($b), '\\') === 'null'
            && !self::allowsNull($type)) {
            $this->hit($var, 'implicit-nullable');
        }
    }

    private static function allowsNull(string $type): bool
    {
        if ($type === '' || $type[0] === '?') {
            return true;
        }
        foreach (preg_split('/[|&()]/', strtolower($type)) ?: [] as $m) {
            $m = ltrim($m, '\\');
            if ($m === 'null' || $m === 'mixed') {
                return true;
            }
        }
        return false;
    }

    private function typeRules(int $from, int $to): void
    {
        if ($from > $to) {
            return;
        }
        $s = $this->s;
        $type = $s->joined($from, $to);
        if (strpos($type, '(') !== false) {
            $this->floorHit($from, 'dnf-type');
        }
        $members = array_values(array_filter(array_map(static function (string $m): string {
            return ltrim(strtolower($m), '\\?');
        }, preg_split('/[|&()]/', $type) ?: []), 'strlen'));
        if (in_array('true', $members, true)) {
            $this->floorHit($from, 'true-type');
        } elseif (count($members) === 1 && in_array($members[0], ['null', 'false'], true)) {
            $this->floorHit($from, 'standalone-type');
        }
    }

    private function caseLabel(int $i): void
    {
        $s = $this->s;
        if ($s->bodyKindOf($i) === 'enum') {
            return; // enum case declarations end with a semicolon
        }
        $depth = 0;
        for ($j = $i + 1; $j < $s->n; $j++) {
            if ($s->isOpener($j) && isset($s->match[$j])) {
                $j = $s->match[$j];
                continue;
            }
            $t = $s->text($j);
            if ($t === '?') {
                $depth++;
            } elseif ($t === ':') {
                if ($depth === 0) {
                    return;
                }
                $depth--;
            } elseif ($t === ';') {
                $this->hit($i, 'case-semicolon');
                return;
            } elseif ($s->isCloser($j)) {
                return;
            }
        }
    }

    private function isOffsetBase(int $p): bool
    {
        $s = $this->s;
        $id = $s->id($p);
        $t = $s->text($p);
        if ($id === T_VARIABLE || $t === ']' || $t === ')' || $t === '}') {
            return true;
        }
        $pp = $s->id($p - 1);
        return $id === T_STRING && ($pp === T_OBJECT_OPERATOR || $pp === T_NULLSAFE_OBJECT_OPERATOR || $pp === T_DOUBLE_COLON);
    }

    /**
     * True when token $i starts an element of an array literal or list: right after the '[' or `array(`/`list(`
     * that opens it, or after a ',' directly inside one. Match arms (`null => …` inside `match {}`), `yield` keys
     * and call arguments are not array elements.
     */
    private function isArrayElementStart(int $i): bool
    {
        $s = $this->s;
        $open = $s->parent[$i] ?? -1;
        if ($open < 0) {
            return false;
        }
        $isArray = $s->text($open) === '['
            || ($s->text($open) === '(' && in_array($s->id($open - 1), [T_ARRAY, T_LIST], true));
        return $isArray && ($s->text($i - 1) === ',' || $i - 1 === $open);
    }

    private function functionCall(int $i): void
    {
        $s = $this->s;
        [$nsName, $global] = $s->resolveFunction($i);
        $name = strtolower($global);
        $args = $s->args($i + 1);
        if (in_array($name, self::NOOP_CLOSE, true)) {
            $this->hit($i, 'noop-close', '', ': ' . $name . '()');
        }
        if ($name === 'lcg_value') {
            $this->hit($i, 'lcg-value');
        }
        if (isset(self::DEPRECATED_FUNCTIONS[$name])) {
            $this->hit($i, 'deprecated-function', self::DEPRECATED_FUNCTIONS[$name], ': ' . $name . '()');
        }
        if (($name === 'get_class' || $name === 'get_parent_class') && !$args) {
            $this->hit($i, 'deprecated-function', '8.3', ': ' . $name . '() without an argument');
        }
        if ($name === 'mb_check_encoding' && !$args) {
            $this->hit($i, 'deprecated-function', '8.1', ': mb_check_encoding() without an argument');
        }
        if (isset(self::CSV_FUNCTIONS[$name])) {
            $this->csv($i + 1, self::CSV_FUNCTIONS[$name], $name . '()');
        }
        if ($name === 'trigger_error' && isset($args[1]) && ltrim($s->joined($args[1][0], $args[1][1]), '\\') === 'E_USER_ERROR') {
            $this->hit($i, 'user-error');
        }
        if (($name === 'array_key_exists' || $name === 'key_exists') && isset($args[0])
            && $args[0][0] === $args[0][1] && ltrim($s->lower($args[0][0]), '\\') === 'null') {
            $this->hit($i, 'null-offset');
        }
        if (!$this->floor) {
            return;
        }
        foreach (self::NEW_FUNCTIONS as $ver => $list) {
            if (!in_array($name, $list, true)) {
                continue;
            }
            if (isset($this->declared[$name]) || ($nsName !== null && isset($this->declared[strtolower($nsName)]))) {
                return; // a floor file declares the function the call resolves to (a polyfill)
            }
            $atoms = $this->g->at($i);
            if (in_array('fn:' . $name, $atoms, true) || Guards::versionCovers($atoms, 'php', $ver)) {
                return;
            }
            $this->floorHit($i, 'new-function', $ver, ': ' . $name . '()');
        }
    }

    private function methodCall(int $m): void
    {
        $s = $this->s;
        $name = $s->lower($m);
        if ($name === 'setaccessible') {
            $this->hit($m, 'set-accessible');
        }
        if (in_array($name, self::PDO_DRIVER_METHODS, true)) {
            $this->hit($m, 'pdo-driver-method', '', ': ' . $s->text($m) . '()');
        }
        if (isset(self::CSV_METHODS[$name])) {
            $this->csv($m + 1, self::CSV_METHODS[$name], '->' . $s->text($m) . '()');
        }
        if (isset(self::NEW_METHODS[$name]) && $this->floor) {
            $atoms = $this->g->at($m);
            foreach ($atoms as $a) {
                if (substr($a, 0, 7) === 'method:' && substr($a, -strlen('::' . $name)) === '::' . $name) {
                    return;
                }
            }
            if (!Guards::versionCovers($atoms, 'php', self::NEW_METHODS[$name])) {
                $this->floorHit($m, 'new-method', self::NEW_METHODS[$name], ': ' . $s->text($m) . '()');
            }
        }
    }

    private function csv(int $open, int $position, string $label): void
    {
        $s = $this->s;
        $args = $s->args($open);
        foreach ($args as [$a, $b]) {
            if ($s->id($a) === T_ELLIPSIS) {
                $this->hit($open, 'csv-escape', '', ': ' . $label . ' (spread arguments, $escape not visible)');
                return;
            }
            if ($s->id($a) === T_STRING && $s->text($a + 1) === ':' && strtolower($s->text($a)) === 'escape') {
                return;
            }
        }
        if (count($args) < $position) {
            $this->hit($open, 'csv-escape', '', ': ' . $label);
        }
    }

    private function staticAccess(int $i): void
    {
        $s = $this->s;
        $member = $s->text($i + 1);
        if ($member === '{') {
            $this->floorHit($i, 'dynamic-const-fetch');
            return;
        }
        $cls = $s->isName($i - 1) ? $s->resolveClass($i - 1) : null;
        $cl = strtolower((string) $cls);
        if ($member === 'RFC7231' && $s->text($i + 2) !== '(') {
            $this->hit($i + 1, 'date-rfc7231');
        }
        if ($cl === 'pdo' && $s->id($i + 1) === T_STRING && $s->text($i + 2) !== '('
            && preg_match('/^(MYSQL|PGSQL|SQLITE|DBLIB|FB|OCI|ODBC)_/', $member)) {
            $this->hit($i + 1, 'pdo-driver-constant', '', ': PDO::' . $member);
        }
        if (!$this->floor || $cls === null) {
            return;
        }
        if ($s->lower($i + 1) === 'class') {
            return;
        }
        $this->classUse($i - 1, $cl);
        if ($cl === 'pdo' && $s->lower($i + 1) === 'connect' && $s->text($i + 2) === '(') {
            $atoms = $this->g->at($i);
            if (!in_array('method:pdo::connect', $atoms, true) && !Guards::versionCovers($atoms, 'php', '8.4')) {
                $this->floorHit($i + 1, 'new-method', '8.4', ': PDO::connect()');
            }
        }
        if ($cl === 'locale' && $s->lower($i + 1) === 'isrighttoleft') {
            $this->methodCall($i + 1);
        }
    }

    private function classUse(int $i, string $cl): void
    {
        if (!isset(self::NEW_CLASSES[$cl])) {
            return;
        }
        $ver = self::NEW_CLASSES[$cl];
        $atoms = $this->g->at($i);
        if (in_array('class:' . $cl, $atoms, true) || Guards::versionCovers($atoms, 'php', $ver)) {
            return;
        }
        $this->floorHit($i, 'new-class', $ver, ': ' . $cl);
    }

    /** A global constant read: `NAME` (T_STRING) or `\NAME` (T_NAME_FULLY_QUALIFIED, the leading backslash dropped). */
    private function constant(int $i): void
    {
        $s = $this->s;
        $prev = $s->id($i - 1);
        if ($s->text($i + 1) === '(' || $s->id($i + 1) === T_DOUBLE_COLON
            || in_array($prev, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON,
            T_FUNCTION, T_CONST, T_NAMESPACE, T_USE, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_AS, T_GOTO, T_NEW,
            T_INSTANCEOF], true)) {
            return;
        }
        $name = ltrim($s->text($i), '\\');
        if ($name === 'E_STRICT') {
            $this->hit($i, 'e-strict');
        } elseif ($name === 'DATE_RFC7231') {
            $this->hit($i, 'date-rfc7231');
        } elseif (isset(self::DEPRECATED_CONSTANTS[$name])) {
            $this->hit($i, 'deprecated-constant', self::DEPRECATED_CONSTANTS[$name], ': ' . $name);
        }
    }

    private function newExpression(int $i): void
    {
        $s = $this->s;
        $k = $i + 1;
        if ($s->id($k) === T_CLASS || $s->id($k) === T_READONLY || $s->id($k) === T_ATTRIBUTE) {
            return; // anonymous class (readonly handled by readonlyClass)
        }
        $cl = null;
        if ($s->isName($k)) {
            $cl = $s->resolveClass($k);
            if ($cl !== null && $this->floor) {
                $this->classUse($k, strtolower($cl));
            }
        }
        if (!$s->isName($k) && $s->id($k) !== T_VARIABLE && $s->id($k) !== T_STATIC) {
            return;
        }
        $o = $k + 1;
        if ($s->text($o) !== '(' || !isset($s->match[$o])) {
            return;
        }
        if ($cl !== null && strtolower($cl) === 'reflectionmethod' && count($s->args($o)) === 1) {
            $this->hit($i, 'reflection-method-1');
        }
        $after = $s->match[$o] + 1;
        $aid = $s->id($after);
        if ($aid === T_OBJECT_OPERATOR || $aid === T_NULLSAFE_OBJECT_OPERATOR || $aid === T_DOUBLE_COLON || $s->text($after) === '[') {
            $this->floorHit($i, 'new-without-parens');
        }
    }

    private function readonlyClass(int $i): void
    {
        $s = $this->s;
        $k = $i + 1;
        while (in_array($s->id($k), [T_FINAL, T_ABSTRACT], true)) {
            $k++;
        }
        if ($s->id($k) !== T_CLASS) {
            return;
        }
        $j = $i - 1;
        while (in_array($s->id($j), [T_FINAL, T_ABSTRACT], true)) {
            $j--;
        }
        $this->floorHit($i, 'readonly-class', $s->id($j) === T_NEW ? '8.3' : '8.2');
    }

    private function attribute(int $i): void
    {
        $s = $this->s;
        $close = $s->match[$i] ?? -1;
        if ($close < 0) {
            return;
        }
        $start = $i + 1;
        for ($k = $i + 1; $k <= $close; $k++) {
            if ($k < $close && $s->isOpener($k) && isset($s->match[$k])) {
                $k = $s->match[$k];
                continue;
            }
            if ($k === $close || $s->text($k) === ',') {
                if ($s->isName($start)) {
                    $fq = strtolower((string) $s->resolveClass($start));
                    if ($fq === 'override') {
                        $this->floorHit($start, 'override-attribute');
                    } elseif ($fq === 'deprecated') {
                        $this->floorHit($start, 'deprecated-attribute');
                    } elseif ($fq === 'nodiscard' || $fq === 'delayedtargetvalidation') {
                        $this->floorHit($start, 'nodiscard-attribute');
                    }
                }
                $start = $k + 1;
            }
        }
    }

    private function classConstant(int $i): void
    {
        $s = $this->s;
        $kind = $s->bodyKindOf($i);
        if (!in_array($kind, ['class', 'interface', 'trait', 'enum'], true)) {
            return;
        }
        if ($kind === 'trait') {
            $this->floorHit($i, 'trait-constant');
        }
        $names = 0;
        for ($k = $i + 1; $k < $s->n && $s->text($k) !== '='; $k++) {
            if ($s->isName($k) || in_array($s->id($k), [T_ARRAY, T_CALLABLE, T_STATIC], true)) {
                $names++;
            }
            if ($s->text($k) === ';') {
                break;
            }
        }
        if ($names >= 2) {
            $this->floorHit($i, 'typed-constant');
        }
    }

    private function isPropertyDeclaration(int $i): bool
    {
        $s = $this->s;
        if (!in_array($s->bodyKindOf($i), ['class', 'trait', 'interface'], true)) {
            return false;
        }
        for ($k = $i - 1; $k >= 0; $k--) {
            $id = $s->id($k);
            $t = $s->text($k);
            if ($t === ';' || $t === '{' || $t === '}' || $t === ']' || $id === T_ATTRIBUTE) {
                return false;
            }
            if (self::isModifier($id)) {
                // the statement started with modifiers; a function or const in between means it is not a property
                for ($j = $k; $j < $i; $j++) {
                    if (in_array($s->id($j), [T_FUNCTION, T_CONST, T_FN], true)) {
                        return false;
                    }
                }
                return true;
            }
            if ($t === ',' && $s->bodyKindOf($k) !== '') {
                return true; // second property of `public int $a, $b;`
            }
        }
        return false;
    }

    private function propertyDeclaration(int $i): void
    {
        $s = $this->s;
        // type: tokens between the last modifier and the variable. A ')' is the end of a DNF group `(A&B)` (skipped
        // to its '('), unless it closes the `(set)` of asymmetric visibility on a tokenizer older than PHP 8.4, where
        // `private(set)` is four tokens; then the modifiers end there.
        $k = $i - 1;
        while ($k >= 0 && !self::isModifier($s->id($k)) && $s->text($k) !== ',') {
            if ($s->text($k) === ')') {
                if ($s->lower($k - 1) === 'set' && $s->text($k - 2) === '('
                    && in_array($s->id($k - 3), [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
                    break;
                }
                if (!isset($s->match[$k])) {
                    break;
                }
                $k = $s->match[$k];
            }
            $k--;
        }
        if ($s->text($k) !== ',' && $k + 1 <= $i - 1) {
            $this->typeRules($k + 1, $i - 1);
        }
        // hooks: `$x {` or `$x = default {`
        $j = $i + 1;
        if ($s->text($j) === '=') {
            for ($j = $i + 2; $j < $s->n; $j++) {
                if ($s->isOpener($j) && $s->text($j) !== '{' && isset($s->match[$j])) {
                    $j = $s->match[$j];
                    continue;
                }
                if (in_array($s->text($j), [';', ',', '{'], true)) {
                    break;
                }
            }
        }
        if ($s->text($j) === '{') {
            $this->floorHit($i, 'property-hooks');
        }
    }

    /** Property modifier, including the PHP 8.4 tokens of asymmetric visibility (public(set) …). */
    private static function isModifier(int $id): bool
    {
        if (in_array($id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR, T_READONLY, T_STATIC, T_FINAL, T_ABSTRACT], true)) {
            return true;
        }
        return defined('T_PUBLIC_SET')
            && in_array($id, [constant('T_PUBLIC_SET'), constant('T_PROTECTED_SET'), constant('T_PRIVATE_SET')], true);
    }

    /** True when the code at $i only runs on PHP below $version (a PHP_VERSION_ID < … guard). */
    private function below(int $i, string $version): bool
    {
        return Guards::belowCovers($this->g->at($i), 'php', $version);
    }
}

/** @return never */
function usage(string $msg): void
{
    fwrite(STDERR, "php85-scan: $msg\n");
    exit(2);
}

function main(array $argv): int
{
    $root = dirname(__DIR__);
    $min = null;
    $filesOut = null;
    $paths = [];
    foreach (array_slice($argv, 1) as $a) {
        if (strncmp($a, '--root=', 7) === 0) {
            $root = rtrim(substr($a, 7), '/');
        } elseif (strncmp($a, '--min=', 6) === 0) {
            $min = substr($a, 6);
            if (!preg_match('/^8\.\d$/', $min)) {
                usage("--min must look like 8.1, got '$min'");
            }
        } elseif (strncmp($a, '--files-out=', 12) === 0) {
            $filesOut = substr($a, 12);
        } elseif ($a === '--') {
            continue;
        } elseif ($a !== '' && $a[0] === '-') {
            usage("unknown option $a");
        } else {
            $paths[] = $a;
        }
    }
    $files = [];
    if ($paths) {
        foreach ($paths as $p) {
            if (is_dir($p)) {
                foreach (Files::collect($p, ['.']) as $rel) {
                    $files[] = [$p . '/' . substr($rel, 2), rtrim($p, '/') . '/' . substr($rel, 2), true];
                }
            } elseif (is_file($p)) {
                $files[] = [$p, $p, true];
            } else {
                usage("no such file or folder: $p");
            }
        }
    } else {
        if (!is_dir($root)) {
            usage("no such folder: $root");
        }
        $main = Files::mainFile($root);
        $shipped = array_values(array_filter([$main, 'uninstall.php', 'includes']));
        $floor = array_flip(Files::collect($root, $shipped));
        // the seeded violations of tests/fixtures/compat-scan are scanned only when named as PATH (CompatScansTest)
        $skip = ['vendor', 'node_modules', '.phpunit.cache', 'dist', '.git'];
        foreach (Files::collect($root, array_merge($shipped, ['tests', 'bin']), $skip, [Files::FIXTURES]) as $rel) {
            $files[] = [$root . '/' . $rel, $rel, isset($floor[$rel])];
        }
    }
    if ($filesOut !== null) {
        file_put_contents($filesOut, implode("\n", array_map(static function (array $f): string {
            return $f[0];
        }, $files)) . "\n");
    }
    $scan = new Php85Scan($min);
    $scan->run($files);
    foreach ($scan->findings as $f) {
        echo $f['file'], ':', $f['line'], ': [', $f['rule'], '] PHP ', $f['php'], ': ', $f['msg'], "\n    ", $f['src'], "\n";
    }
    $floorCount = count(array_filter($files, static function (array $f): bool {
        return $f[2];
    }));
    printf("php85-scan: %d finding(s); %d file(s) scanned for PHP 8.1-8.5 deprecations%s\n", count($scan->findings),
        count($files), $min === null ? '' : sprintf(', %d for the PHP %s floor', $floorCount, $min));
    return $scan->findings ? 1 : 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(main($argv));
}
