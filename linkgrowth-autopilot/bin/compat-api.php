<?php
/**
 * WordPress / WooCommerce API floor scan (DESIGN.md §11.8). Development tooling only.
 *
 *   php bin/compat-api.php [--wp=PATH] [--root=DIR] [--wp-floor=6.5] [--wc-floor=8.0] [--as-floor=X.Y.Z]
 *                          [--json] [--verbose] [PATH ...]
 *
 * Tokenizes the plugin (the main file, uninstall.php and includes/ under --root, or the PATHs given) and
 * collects every called function, every class used with `new`, `::`, `extends` or `implements`, every static
 * method, and every hook name passed to add_action/add_filter/do_action/apply_filters (and their has_/remove_/
 * _ref_array/did_ variants) that is not the plugin's own or PHP's. Each one is looked up in the source of the
 * WordPress installation given by --wp or LGA_WP_PATH (read-only): the lowest `@since` of the declaration's doc
 * comment (for a hook: of the doc comment of the do_action/apply_filters call). A symbol newer than the floor
 * fails unless it sits inside a function_exists()/class_exists()/method_exists() guard or a version check
 * (version_compare( get_bloginfo( 'version' ) | $wp_version | WC_VERSION | WC()->version … ),
 * is_wp_version_compatible()). Symbols without `@since`, or not found, are listed as "unknown" and do not fail.
 *
 * Floors default to the plugin header ("Requires at least", "WC requires at least"). Action Scheduler symbols
 * (woocommerce/packages/action-scheduler) use their own version numbers; their floor is --as-floor, by default
 * the Action Scheduler release bundled with the WooCommerce floor version (AS_FLOOR_FOR_WC below).
 *
 * Not checked: instance methods ($obj->method(), the receiver type is unknown), constants, and functions that
 * WordPress polyfills for PHP (wp-includes/compat*.php; PHP floors are bin/php85-scan.sh's job).
 *
 * Exit code: 0 no failure, 1 failures, 2 usage error, 3 not run (no WordPress or WooCommerce source).
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tools;

require_once __DIR__ . '/scan-lib.php';

final class SinceIndex
{
    /**
     * Action Scheduler version bundled with a WooCommerce floor version. 8.0: the WooCommerce 8.0.5 archive reads
     * "Version: 3.6.1" in packages/action-scheduler/action-scheduler.php, and that file has the same sha256 in the
     * wordpress.org checksum lists of WooCommerce 8.0.0 and 8.0.5.
     */
    public const AS_FLOOR_FOR_WC = ['8.0' => '3.6.1'];

    private const HOOK_FUNCTIONS = ['do_action', 'apply_filters', 'do_action_ref_array', 'apply_filters_ref_array',
        'do_action_deprecated', 'apply_filters_deprecated'];
    private const WP_VENDOR = ['wp-includes/ID3/', 'wp-includes/SimplePie/', 'wp-includes/Requests/',
        'wp-includes/PHPMailer/', 'wp-includes/sodium_compat/', 'wp-includes/Text/'];
    private const WP_POLYFILLS = ['wp-includes/compat.php', 'wp-includes/compat-utf8.php'];

    /** @var array<string, array{since:string,deprecated:?string,file:string,line:int,src:string}> */
    public array $functions = [];
    /** @var array<string, array{since:string,deprecated:?string,file:string,line:int,src:string}> */
    public array $classes = [];
    /** @var array<string, array{since:string,deprecated:?string,file:string,line:int,src:string}> */
    public array $methods = [];
    /** @var array<string, array{since:string,deprecated:?string,file:string,line:int,src:string}> */
    public array $hooks = [];
    /** @var list<array{pattern:string,regex:string,prefix:string,since:string,deprecated:?string,file:string,line:int,src:string}> */
    public array $dynamicHooks = [];
    /** @var array<string, true> */
    public array $polyfills = [];
    public string $wpVersion = '';
    public string $wcVersion = '';
    public string $asVersion = '';
    public int $files = 0;

    public static function build(string $wp, ?string $wc): self
    {
        $x = new self();
        $versionFile = $wp . '/wp-includes/version.php';
        $versionCode = is_file($versionFile) && is_readable($versionFile) ? (string) file_get_contents($versionFile) : '';
        if (preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', $versionCode, $m)) {
            $x->wpVersion = $m[1];
        }
        $wpFiles = array_merge(
            array_map(static function (string $f): string {
                return basename($f);
            }, glob($wp . '/*.php') ?: []),
            Files::collect($wp, ['wp-includes', 'wp-admin'])
        );
        foreach ($wpFiles as $rel) {
            $src = 'wp';
            foreach (self::WP_VENDOR as $v) {
                if (strncmp($rel, $v, strlen($v)) === 0) {
                    $src = 'wp-vendor';
                }
            }
            $x->indexFile($wp . '/' . $rel, 'wp:' . $rel, $src, in_array($rel, self::WP_POLYFILLS, true));
        }
        if ($wc !== null) {
            $x->wcVersion = (string) Files::header($wc . '/woocommerce.php', 'Version');
            $as = $wc . '/packages/action-scheduler/action-scheduler.php';
            if (is_file($as)) {
                $x->asVersion = (string) Files::header($as, 'Version');
            }
            foreach (Files::collect($wc, ['.'], ['vendor', 'node_modules', 'tests', '.git']) as $rel) {
                $rel = substr($rel, 2);
                if (strncmp($rel, 'packages/action-scheduler/', 26) === 0) {
                    $src = 'as';
                } elseif (strncmp($rel, 'packages/', 9) === 0 || strncmp($rel, 'lib/', 4) === 0) {
                    $src = 'wc-vendor';
                } elseif (strncmp($rel, 'src/Blocks/', 11) === 0 || strncmp($rel, 'src/StoreApi/', 13) === 0) {
                    $src = 'wc-blocks';
                } else {
                    $src = 'wc';
                }
                $x->indexFile($wc . '/' . $rel, 'woocommerce:' . $rel, $src, false);
            }
        }
        return $x;
    }

    private function put(array &$map, string $key, string $doc, string $file, int $line, string $src): void
    {
        $since = Version::sinceOf($doc);
        $entry = ['since' => $since, 'deprecated' => $doc === '' ? null : Version::deprecatedOf($doc),
            'file' => $file, 'line' => $line, 'src' => $src];
        // several declarations (pluggable functions, version branches, several do_action calls): keep the lowest @since
        if (!isset($map[$key]) || ($since !== '' && ($map[$key]['since'] === '' || Version::cmp($since, $map[$key]['since']) < 0))) {
            $map[$key] = $entry;
        }
    }

    private function indexFile(string $path, string $label, string $src, bool $polyfill): void
    {
        $code = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($code === false || (strpos($code, 'function') === false && strpos($code, 'class') === false
            && strpos($code, 'do_action') === false && strpos($code, 'apply_filters') === false)) {
            return;
        }
        $this->files++;
        $toks = token_get_all($code);
        $n = count($toks);
        $ns = '';
        $depth = 0;
        $classes = []; // stack of [fq, depth of its body]
        $pendingClass = null;
        $doc = '';
        $docValid = false;
        $stmtDoc = '';
        $prev = null; // previous significant token id or char
        $skip = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT, T_READONLY, T_VAR];
        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            $id = is_array($t) ? $t[0] : null;
            $text = is_array($t) ? $t[1] : $t;
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_OPEN_TAG || $id === T_INLINE_HTML) {
                continue;
            }
            if ($id === T_DOC_COMMENT) {
                $doc = $text;
                $docValid = true;
                $stmtDoc = $text;
                continue;
            }
            if ($id !== null && in_array($id, $skip, true)) {
                continue;
            }
            if ($id === T_ATTRIBUTE) {
                $d = 1;
                for ($i++; $i < $n && $d > 0; $i++) {
                    $c = is_array($toks[$i]) ? $toks[$i][1] : $toks[$i];
                    if ($c === '[' || (is_array($toks[$i]) && $toks[$i][0] === T_ATTRIBUTE)) {
                        $d++;
                    } elseif ($c === ']') {
                        $d--;
                    }
                }
                $i--;
                continue;
            }
            $line = is_array($t) ? $t[2] : 0;
            if ($id === T_NAMESPACE) {
                $j = $this->nextSig($toks, $i);
                if ($j !== null && is_array($toks[$j]) && in_array($toks[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                    $ns = $toks[$j][1];
                }
            } elseif (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $prev !== T_DOUBLE_COLON && $prev !== T_NEW) {
                $j = $this->nextSig($toks, $i);
                if ($j !== null && is_array($toks[$j]) && $toks[$j][0] === T_STRING) {
                    $fq = ltrim($ns . '\\' . $toks[$j][1], '\\');
                    if (!$polyfill) {
                        $this->put($this->classes, strtolower($fq), $docValid ? $doc : '', $label, $toks[$j][2], $src);
                    }
                    $pendingClass = $fq;
                }
            } elseif ($id === T_FUNCTION) {
                $j = $this->nextSig($toks, $i);
                if ($j !== null && $toks[$j] === '&') {
                    $j = $this->nextSig($toks, $j);
                }
                if ($j !== null && is_array($toks[$j]) && $toks[$j][0] === T_STRING) {
                    $name = $toks[$j][1];
                    $d = $docValid ? $doc : '';
                    if ($classes && $classes[count($classes) - 1][1] === $depth) {
                        $this->put($this->methods, strtolower($classes[count($classes) - 1][0] . '::' . $name), $d, $label, $toks[$j][2], $src);
                    } elseif ($polyfill && $ns === '') {
                        $this->polyfills[strtolower($name)] = true;
                    } else {
                        $this->put($this->functions, strtolower(ltrim($ns . '\\' . $name, '\\')), $d, $label, $toks[$j][2], $src);
                    }
                }
            } elseif ($id === T_STRING && in_array(strtolower($text), self::HOOK_FUNCTIONS, true)
                && $prev !== T_OBJECT_OPERATOR && $prev !== T_DOUBLE_COLON && $prev !== T_FUNCTION) {
                $j = $this->nextSig($toks, $i);
                if ($j !== null && $toks[$j] === '(') {
                    $name = self::hookName($toks, $j);
                    if ($name !== null) {
                        $this->putHook($name, $stmtDoc, $label, $line, $src, strtolower($text), $toks, $j);
                    }
                }
            }
            if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                if ($text === '{' && $pendingClass !== null) {
                    $classes[] = [$pendingClass, $depth];
                    $pendingClass = null;
                }
            } elseif ($text === '}') {
                if ($classes && $classes[count($classes) - 1][1] === $depth) {
                    array_pop($classes);
                }
                $depth--;
            }
            if ($text === ';' || $text === '{' || $text === '}') {
                $stmtDoc = '';
            }
            $docValid = false;
            $prev = $id ?? $text;
        }
    }

    /** @param array<int, mixed> $toks */
    private function nextSig(array $toks, int $i): ?int
    {
        for ($j = $i + 1, $n = count($toks); $j < $n; $j++) {
            $id = is_array($toks[$j]) ? $toks[$j][0] : null;
            if ($id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT) {
                return $j;
            }
        }
        return null;
    }

    /**
     * The first argument of a hook call whose '(' is at $open, as a list of parts: strings are literal text,
     * null is a dynamic part. Null when the argument is not built from literals.
     *
     * @param array<int, mixed> $toks
     * @return ?list<?string>
     */
    public static function hookName(array $toks, int $open): ?array
    {
        $parts = [];
        $depth = 0;
        $inQuote = false;
        for ($j = $open + 1, $n = count($toks); $j < $n; $j++) {
            $t = $toks[$j];
            $id = is_array($t) ? $t[0] : null;
            $text = is_array($t) ? $t[1] : $t;
            if ($inQuote) {
                if ($text === '"') {
                    $inQuote = false;
                } elseif ($id === T_ENCAPSED_AND_WHITESPACE) {
                    $parts[] = stripcslashes($text);
                } elseif ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $parts[] = null;
                    $d = 1;
                    for ($j++; $j < $n && $d > 0; $j++) {
                        $c = is_array($toks[$j]) ? $toks[$j][1] : $toks[$j];
                        if ($c === '{' || (is_array($toks[$j]) && in_array($toks[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                            $d++;
                        } elseif ($c === '}') {
                            $d--;
                        }
                    }
                    $j--;
                } elseif ($id === T_VARIABLE) {
                    $parts[] = null;
                } elseif ($text === '[' || $text === ']' || $id === T_NUM_STRING || $id === T_STRING || $id === T_OBJECT_OPERATOR) {
                    // "$a[0]" / "$a->b": still part of the dynamic piece
                }
                continue;
            }
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }
            if ($depth === 0 && ($text === ',' || $text === ')')) {
                break;
            }
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;
                $parts[] = null;
                continue;
            }
            if ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
                continue;
            }
            if ($depth > 0) {
                continue;
            }
            if ($text === '"') {
                $inQuote = true;
                continue;
            }
            if ($text === '.') {
                continue;
            }
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $q = $text[0];
                $body = substr($text, 1, -1);
                $parts[] = $q === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $body) : stripcslashes($body);
                continue;
            }
            if ($id === T_START_HEREDOC) {
                return null;
            }
            $parts[] = null; // variable, constant, call
        }
        // merge
        $out = [];
        foreach ($parts as $p) {
            $last = count($out) - 1;
            if ($last >= 0 && is_string($p) && is_string($out[$last])) {
                $out[$last] .= $p;
            } elseif ($last >= 0 && $p === null && $out[$last] === null) {
                continue;
            } else {
                $out[] = $p;
            }
        }
        if (!$out || $out === [null]) {
            return null;
        }
        return $out;
    }

    /** @param list<?string> $parts */
    public static function isLiteral(array $parts): bool
    {
        return count($parts) === 1 && is_string($parts[0]);
    }

    /** @param list<?string> $parts */
    public static function display(array $parts): string
    {
        return implode('', array_map(static function (?string $p): string {
            return $p ?? '{…}';
        }, $parts));
    }

    /** @param list<?string> $parts */
    private function putHook(array $parts, string $doc, string $file, int $line, string $src, string $fn, array $toks, int $open): void
    {
        $deprecated = null;
        if ($fn === 'do_action_deprecated' || $fn === 'apply_filters_deprecated') {
            // third argument is the version
            $args = [];
            $depth = 0;
            $cur = '';
            for ($j = $open + 1, $n = count($toks); $j < $n; $j++) {
                $c = is_array($toks[$j]) ? $toks[$j][1] : $toks[$j];
                if ($depth === 0 && ($c === ',' || $c === ')')) {
                    $args[] = trim($cur);
                    $cur = '';
                    if ($c === ')') {
                        break;
                    }
                    continue;
                }
                if ($c === '(' || $c === '[') {
                    $depth++;
                } elseif ($c === ')' || $c === ']') {
                    $depth--;
                }
                $cur .= $c;
            }
            $deprecated = Version::normalize($args[2] ?? '');
        }
        if (self::isLiteral($parts)) {
            $this->put($this->hooks, (string) $parts[0], $doc, $file, $line, $src);
            if ($deprecated !== null && $deprecated !== '') {
                $this->hooks[(string) $parts[0]]['deprecated'] = $deprecated;
            }
            return;
        }
        $regex = '/^' . implode('', array_map(static function (?string $p): string {
            return $p === null ? '.+' : preg_quote($p, '/');
        }, $parts)) . '$/';
        $this->dynamicHooks[] = ['pattern' => self::display($parts), 'regex' => $regex,
            'prefix' => is_string($parts[0]) ? $parts[0] : '', 'since' => Version::sinceOf($doc),
            'deprecated' => $deprecated, 'file' => $file, 'line' => $line, 'src' => $src];
    }

    /**
     * @param list<?string> $parts
     * @return ?array{since:string,deprecated:?string,file:string,line:int,src:string,name:string}
     */
    public function hook(array $parts): ?array
    {
        if (self::isLiteral($parts)) {
            $name = (string) $parts[0];
            if (isset($this->hooks[$name])) {
                return $this->hooks[$name] + ['name' => $name];
            }
            $best = null;
            foreach ($this->dynamicHooks as $d) {
                // a pattern such as "{$old_status}_{$post_type}" matches nearly any name: it needs real literal text
                if (strlen(str_replace('{…}', '', $d['pattern'])) < 6) {
                    continue;
                }
                if (preg_match($d['regex'], $name) && ($best === null || strlen($d['prefix']) > strlen($best['prefix'])
                    || (strlen($d['prefix']) === strlen($best['prefix']) && $d['since'] !== '' && ($best['since'] === '' || Version::cmp($d['since'], $best['since']) < 0)))) {
                    $best = $d;
                }
            }
            return $best === null ? null : $best + ['name' => $best['pattern']];
        }
        // a dynamic name: match by the literal prefix
        $prefix = is_string($parts[0]) ? $parts[0] : '';
        if ($prefix === '') {
            return null;
        }
        $best = null;
        foreach ($this->dynamicHooks as $d) {
            if ($d['prefix'] === $prefix && ($best === null || ($d['since'] !== '' && ($best['since'] === '' || Version::cmp($d['since'], $best['since']) < 0)))) {
                $best = $d;
            }
        }
        return $best === null ? null : $best + ['name' => $best['pattern']];
    }
}

final class CompatApi
{
    private const HOOK_USERS = ['add_action', 'add_filter', 'do_action', 'apply_filters', 'do_action_ref_array',
        'apply_filters_ref_array', 'has_action', 'has_filter', 'remove_action', 'remove_filter',
        'remove_all_actions', 'remove_all_filters', 'did_action', 'did_filter', 'doing_action', 'doing_filter'];
    private const OWN_HOOK_PREFIXES = ['lga_', 'lga/', 'lga-', 'linkgrowth'];

    private SinceIndex $idx;
    /** @var array<string, string> source => floor */
    private array $floors;
    /** @var array<string, true> */
    private array $ownFunctions = [];
    /** @var array<string, true> */
    private array $ownClasses = [];
    /** @var list<string> lower-case namespace prefixes of the scanned code */
    private array $ownNamespaces = [];
    /** @var array<string, array<string, mixed>> keyed by kind|symbol */
    public array $symbols = [];
    public int $fileCount = 0;

    public function __construct(SinceIndex $idx, array $floors)
    {
        $this->idx = $idx;
        $this->floors = $floors;
    }

    /** @param list<array{0:string,1:string}> $files [absolute, display] */
    public function run(array $files): void
    {
        $sources = [];
        foreach ($files as [$abs, $rel]) {
            $s = Source::fromFile($abs, $rel);
            $sources[] = $s;
            for ($i = 0; $i < $s->n; $i++) {
                $id = $s->id($i);
                if ($id === T_FUNCTION && $s->id($i + 1) === T_STRING && $s->text($i + 2) === '('
                    && !in_array($s->bodyKindOf($i), ['class', 'interface', 'trait', 'enum'], true)) {
                    $ns = $s->namespaceAt($i);
                    $this->ownFunctions[strtolower(ltrim($ns . '\\' . $s->text($i + 1), '\\'))] = true;
                }
                if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $s->id($i + 1) === T_STRING
                    && $s->id($i - 1) !== T_DOUBLE_COLON && $s->id($i - 1) !== T_NEW) {
                    $ns = $s->namespaceAt($i);
                    $this->ownClasses[strtolower(ltrim($ns . '\\' . $s->text($i + 1), '\\'))] = true;
                    if ($ns !== '') {
                        $seg = explode('\\', strtolower($ns));
                        $this->ownNamespaces[] = implode('\\', array_slice($seg, 0, 2)) . '\\';
                    }
                }
            }
        }
        $this->ownNamespaces = array_values(array_unique($this->ownNamespaces));
        $this->fileCount = count($sources);
        foreach ($sources as $s) {
            $this->scan($s);
        }
        ksort($this->symbols);
    }

    private function isOwnClass(string $fq): bool
    {
        $l = strtolower($fq);
        if (isset($this->ownClasses[$l])) {
            return true;
        }
        foreach ($this->ownNamespaces as $p) {
            if (strncmp($l, $p, strlen($p)) === 0) {
                return true;
            }
        }
        return false;
    }

    private static function isInternalClass(string $fq): bool
    {
        if (!class_exists($fq, false) && !interface_exists($fq, false) && !trait_exists($fq, false)
            && !(function_exists('enum_exists') && enum_exists($fq, false))) {
            return false;
        }
        return (new \ReflectionClass($fq))->isInternal();
    }

    private function scan(Source $s): void
    {
        $g = new Guards($s);
        for ($i = 0; $i < $s->n; $i++) {
            $id = $s->id($i);
            if ($s->isFunctionCall($i)) {
                [$nsName, $global] = $s->resolveFunction($i);
                if ($nsName !== null && isset($this->ownFunctions[strtolower($nsName)])) {
                    continue;
                }
                $name = strtolower(ltrim($global, '\\'));
                if (isset($this->ownFunctions[$name]) || (function_exists($name) && (new \ReflectionFunction($name))->isInternal())) {
                    // PHP or plugin function; hook names and string callbacks below
                } elseif (isset($this->idx->polyfills[$name])) {
                    $this->record('php-polyfill', $name, $s, $i, null, [], 'function');
                } else {
                    $this->checkFunction($s, $g, $i, $name);
                }
                if (in_array($name, self::HOOK_USERS, true)) {
                    $this->hookUse($s, $g, $i, $name);
                }
                continue;
            }
            if ($id === T_NEW && $s->isName($i + 1)) {
                $this->classUse($s, $g, $i + 1, 'new');
            } elseif ($id === T_DOUBLE_COLON && $s->isName($i - 1) && $s->lower($i + 1) !== 'class') {
                $cl = $this->classUse($s, $g, $i - 1, 'static');
                if ($cl !== null && $s->id($i + 1) === T_STRING && $s->text($i + 2) === '(') {
                    $this->methodUse($s, $g, $i + 1, $cl);
                }
            } elseif ($id === T_EXTENDS || $id === T_IMPLEMENTS) {
                for ($k = $i + 1; $k < $s->n && ($s->isName($k) || $s->text($k) === ','); $k++) {
                    if ($s->isName($k)) {
                        $this->classUse($s, $g, $k, $id === T_EXTENDS ? 'extends' : 'implements');
                    }
                }
            }
        }
    }

    private function floorFor(string $src): ?string
    {
        return $this->floors[$src] ?? null;
    }

    /**
     * @param ?array{since:string,deprecated:?string,file:string,line:int,src:string} $entry
     * @param list<string> $atoms
     */
    private function record(string $kind, string $symbol, Source $s, int $i, ?array $entry, array $atoms, string $what, string $verdict = ''): void
    {
        $key = $kind . '|' . $symbol;
        if (!isset($this->symbols[$key])) {
            $this->symbols[$key] = ['kind' => $kind, 'symbol' => $symbol, 'what' => $what, 'entry' => $entry,
                'uses' => [], 'verdict' => 'ok'];
        }
        $this->symbols[$key]['uses'][] = ['file' => $s->rel, 'line' => $s->line($i), 'verdict' => $verdict,
            'src' => $s->lineText($s->line($i))];
        $order = ['ok' => 0, 'guarded' => 1, 'fail' => 2];
        if ($verdict !== '' && $order[$verdict] > $order[$this->symbols[$key]['verdict']]) {
            $this->symbols[$key]['verdict'] = $verdict;
        }
    }

    /**
     * Verdict for one use: '' when not newer than the floor, 'guarded' or 'fail'.
     *
     * @param array{since:string,src:string} $entry
     * @param list<string> $atoms
     * @param list<string> $accept atoms that guard this symbol
     */
    private function verdict(array $entry, array $atoms, array $accept): string
    {
        $floor = $this->floorFor($entry['src']);
        if ($floor === null || $entry['since'] === '' || Version::cmp($entry['since'], $floor) <= 0) {
            return '';
        }
        foreach ($accept as $a) {
            if (in_array($a, $atoms, true)) {
                return 'guarded';
            }
        }
        $kind = in_array($entry['src'], ['wc', 'wc-blocks'], true) ? 'wc' : ($entry['src'] === 'as' ? 'as' : 'wp');
        if (Guards::versionCovers($atoms, $kind, $entry['since'])) {
            return 'guarded';
        }
        return 'fail';
    }

    private function checkFunction(Source $s, Guards $g, int $i, string $name): void
    {
        $entry = $this->idx->functions[$name] ?? null;
        $atoms = $g->at($i);
        if ($entry === null) {
            $this->record('unknown', $name . '()', $s, $i, null, $atoms, 'function');
            return;
        }
        $this->record($entry['since'] === '' ? 'unknown' : 'function', $name . '()', $s, $i, $entry, $atoms, 'function',
            $this->verdict($entry, $atoms, ['fn:' . $name]));
    }

    private function classUse(Source $s, Guards $g, int $i, string $how): ?string
    {
        $fq = $s->resolveClass($i);
        if ($fq === null || $this->isOwnClass($fq) || self::isInternalClass($fq)) {
            return null;
        }
        $l = strtolower($fq);
        $entry = $this->idx->classes[$l] ?? null;
        $atoms = $g->at($i);
        if ($entry === null) {
            $this->record('unknown', $fq, $s, $i, null, $atoms, 'class (' . $how . ')');
            return $l;
        }
        $this->record($entry['since'] === '' ? 'unknown' : 'class', $fq, $s, $i, $entry, $atoms, 'class',
            $this->verdict($entry, $atoms, ['class:' . $l]));
        return $l;
    }

    private function methodUse(Source $s, Guards $g, int $i, string $cl): void
    {
        $m = $s->lower($i);
        $key = $cl . '::' . $m;
        $entry = $this->idx->methods[$key] ?? null;
        if ($entry === null || $entry['since'] === '') {
            return; // the class itself was checked; inherited or undocumented methods are not followed
        }
        $atoms = $g->at($i);
        $this->record('method', $key . '()', $s, $i, $entry, $atoms, 'static method',
            $this->verdict($entry, $atoms, ['class:' . $cl, 'method:' . $key, 'method:*::' . $m]));
    }

    private function hookUse(Source $s, Guards $g, int $i, string $fn): void
    {
        $open = $i + 1;
        $args = $s->args($open);
        if (!$args) {
            return;
        }
        // the hook name from the raw tokens of the first argument (fragments of interpolated strings included)
        $raw = [];
        for ($r = $s->raw[$args[0][0]]; $r <= $s->raw[$args[0][1]]; $r++) {
            $t = $s->all[$r];
            $raw[] = $t->id < 256 && strlen($t->text) === 1 && $t->id === ord($t->text) ? $t->text : [$t->id, $t->text, $t->line];
        }
        $raw[] = ')';
        array_unshift($raw, '(');
        $parts = SinceIndex::hookName($raw, 0);
        if ($parts === null) {
            return;
        }
        $first = is_string($parts[0]) ? strtolower($parts[0]) : '';
        foreach (self::OWN_HOOK_PREFIXES as $p) {
            if ($first !== '' && strncmp($first, $p, strlen($p)) === 0) {
                return;
            }
        }
        $display = SinceIndex::display($parts);
        $entry = $this->idx->hook($parts);
        $atoms = $g->at($i);
        if ($entry === null || $entry['since'] === '') {
            $this->record('unknown', $display, $s, $i, $entry, $atoms, 'hook');
            return;
        }
        $what = $entry['name'] === $display ? 'hook' : 'hook (matches ' . $entry['name'] . ')';
        $this->record('hook', $display, $s, $i, $entry, $atoms, $what, $this->verdict($entry, $atoms, []));
        // string callbacks: add_action( 'hook', 'function_name' )
        if (in_array($fn, ['add_action', 'add_filter'], true) && isset($args[1])) {
            $cb = $s->literal($args[1][0], $args[1][1]);
            if ($cb !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $cb)) {
                $name = strtolower($cb);
                if (!isset($this->ownFunctions[$name]) && !(function_exists($name) && (new \ReflectionFunction($name))->isInternal())) {
                    $this->checkFunction($s, $g, $args[1][0], $name);
                }
            }
        }
    }
}

/** @return never */
function usage(string $msg, int $code = 2): void
{
    fwrite(STDERR, "compat-api: $msg\n");
    exit($code);
}

function main(array $argv): int
{
    $root = dirname(__DIR__);
    $wp = getenv('LGA_WP_PATH') ?: '';
    $floors = [];
    $json = false;
    $verbose = false;
    $paths = [];
    foreach (array_slice($argv, 1) as $a) {
        if (strncmp($a, '--wp=', 5) === 0) {
            $wp = substr($a, 5);
        } elseif (strncmp($a, '--root=', 7) === 0) {
            $root = rtrim(substr($a, 7), '/');
        } elseif (preg_match('/^--(wp|wc|as)-floor=(.+)$/', $a, $m)) {
            $floors[$m[1]] = Version::normalize($m[2]);
        } elseif ($a === '--json') {
            $json = true;
        } elseif ($a === '--verbose' || $a === '-v') {
            $verbose = true;
        } elseif ($a !== '' && $a[0] === '-') {
            usage("unknown option $a");
        } else {
            $paths[] = $a;
        }
    }
    $wp = rtrim($wp, '/');
    if ($wp === '' || !is_file($wp . '/wp-includes/version.php')) {
        echo "compat-api not run: no WordPress source (set LGA_WP_PATH or pass --wp=<WordPress folder>)\n";
        return 3;
    }
    $wc = $wp . '/wp-content/plugins/woocommerce';
    if (!is_file($wc . '/woocommerce.php')) {
        echo "compat-api not run: no WooCommerce source in $wc\n";
        return 3;
    }
    // files and floors
    $files = [];
    if ($paths) {
        foreach ($paths as $p) {
            if (is_dir($p)) {
                foreach (Files::collect($p, ['.']) as $rel) {
                    $files[] = [$p . '/' . substr($rel, 2), rtrim($p, '/') . '/' . substr($rel, 2)];
                }
            } elseif (is_file($p)) {
                $files[] = [$p, $p];
            } else {
                usage("no such file or folder: $p");
            }
        }
    } else {
        $main = Files::mainFile($root);
        if ($main === null) {
            usage("no plugin main file in $root");
        }
        // shipped files only; tests/ and with it the seeded violations of Files::FIXTURES are never a default target
        foreach (Files::collect($root, [$main, 'uninstall.php', 'includes'], ['vendor', 'node_modules', '.phpunit.cache', 'dist', '.git'], [Files::FIXTURES]) as $rel) {
            $files[] = [$root . '/' . $rel, $rel];
        }
        $floors += [
            'wp' => Version::normalize((string) Files::header($root . '/' . $main, 'Requires at least')),
            'wc' => Version::normalize((string) Files::header($root . '/' . $main, 'WC requires at least')),
        ];
    }
    $floors += ['wp' => '6.5.0', 'wc' => '8.0.0'];
    if (!isset($floors['as'])) {
        $wcMinor = implode('.', array_slice(explode('.', $floors['wc']), 0, 2));
        $floors['as'] = SinceIndex::AS_FLOOR_FOR_WC[$wcMinor] ?? '';
    }
    $t0 = microtime(true);
    $idx = SinceIndex::build($wp, $wc);
    $scan = new CompatApi($idx, ['wp' => $floors['wp'], 'wc' => $floors['wc'], 'wc-blocks' => $floors['wc'],
        'as' => $floors['as'] !== '' ? $floors['as'] : null]);
    $scan->run($files);

    $fails = $guarded = $unknown = $deprecated = [];
    $failUses = $guardedUses = 0;
    $counts = ['function' => 0, 'class' => 0, 'method' => 0, 'hook' => 0, 'unknown' => 0, 'php-polyfill' => 0];
    foreach ($scan->symbols as $sym) {
        $counts[$sym['kind']]++;
        if ($sym['kind'] === 'unknown') {
            $unknown[] = $sym;
            continue;
        }
        if ($sym['entry'] !== null && $sym['entry']['deprecated'] !== null) {
            $deprecated[] = $sym;
        }
        foreach ($sym['uses'] as $u) {
            if ($u['verdict'] === 'fail') {
                $fails[] = $sym + ['use' => $u];
                $failUses++;
            } elseif ($u['verdict'] === 'guarded') {
                $guarded[] = $sym + ['use' => $u];
                $guardedUses++;
            }
        }
    }
    $labels = ['wp' => 'WordPress', 'wp-vendor' => 'WordPress (bundled library)', 'wc' => 'WooCommerce',
        'wc-blocks' => 'WooCommerce (src/Blocks or src/StoreApi: may use the former Blocks plugin numbering)',
        'as' => 'Action Scheduler', 'wc-vendor' => 'WooCommerce (bundled package)'];
    $where = static function (array $e) use ($labels): string {
        return sprintf('@since %s %s (%s:%d)', $labels[$e['src']] ?? $e['src'], $e['since'], $e['file'], $e['line']);
    };
    if ($json) {
        echo json_encode(['wordpress' => $idx->wpVersion, 'woocommerce' => $idx->wcVersion,
            'action_scheduler' => $idx->asVersion, 'floors' => $floors, 'files' => $scan->fileCount,
            'counts' => $counts, 'fail' => $fails, 'guarded' => $guarded, 'unknown' => $unknown,
            'deprecated' => $deprecated], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        return $fails ? 1 : 0;
    }
    printf("compat-api: source WordPress %s, WooCommerce %s, Action Scheduler %s (%d files indexed in %.1f s)\n",
        $idx->wpVersion, $idx->wcVersion, $idx->asVersion ?: '-', $idx->files, microtime(true) - $t0);
    printf("compat-api: floors WordPress %s, WooCommerce %s, Action Scheduler %s\n", $floors['wp'], $floors['wc'],
        $floors['as'] !== '' ? $floors['as'] . ' (bundled with WooCommerce ' . $floors['wc'] . ')' : 'unknown (not checked)');
    printf("compat-api: %d file(s), %d WordPress/WooCommerce symbol(s): %d functions, %d classes, %d static methods, %d hooks; %d unknown, %d PHP polyfill(s)\n",
        $scan->fileCount, $counts['function'] + $counts['class'] + $counts['method'] + $counts['hook'],
        $counts['function'], $counts['class'], $counts['method'], $counts['hook'], $counts['unknown'], $counts['php-polyfill']);
    $byLine = static function (array $a, array $b): int {
        return [$a['use']['file'], $a['use']['line'], $a['symbol']] <=> [$b['use']['file'], $b['use']['line'], $b['symbol']];
    };
    usort($fails, $byLine);
    usort($guarded, $byLine);
    foreach ($fails as $sym) {
        $u = $sym['use'];
        printf("%s:%d: [fail] %s %s %s, not guarded\n    %s\n", $u['file'], $u['line'], $sym['what'], $sym['symbol'], $where($sym['entry']), $u['src']);
    }
    foreach ($guarded as $sym) {
        $u = $sym['use'];
        printf("%s:%d: [guarded] %s %s %s\n", $u['file'], $u['line'], $sym['what'], $sym['symbol'], $where($sym['entry']));
    }
    foreach ($deprecated as $sym) {
        $u = $sym['uses'][0];
        printf("%s:%d: [deprecated] %s %s deprecated in %s %s (%d use(s))\n", $u['file'], $u['line'], $sym['what'], $sym['symbol'],
            $labels[$sym['entry']['src']] ?? $sym['entry']['src'], $sym['entry']['deprecated'] ?: '(no version)', count($sym['uses']));
    }
    foreach ($unknown as $sym) {
        $u = $sym['uses'][0];
        printf("%s:%d: [unknown] %s %s: %s (%d use(s))\n", $u['file'], $u['line'], $sym['what'], $sym['symbol'],
            $sym['entry'] === null ? 'not found in the WordPress or WooCommerce source' : 'no @since in ' . $sym['entry']['file'] . ':' . $sym['entry']['line'],
            count($sym['uses']));
    }
    if ($verbose) {
        foreach ($scan->symbols as $sym) {
            if ($sym['kind'] !== 'unknown' && $sym['entry'] !== null && $sym['verdict'] === 'ok') {
                printf("    ok %s %s %s (%d use(s))\n", $sym['what'], $sym['symbol'], $where($sym['entry']), count($sym['uses']));
            }
        }
    }
    printf("compat-api %s: %d failing use(s), %d guarded use(s), %d deprecated symbol(s), %d unknown symbol(s)\n",
        $fails ? 'FAILED' : 'OK', $failUses, $guardedUses, count($deprecated), count($unknown));
    return $fails ? 1 : 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(main($argv));
}
