<?php
/**
 * Shared code of the static compatibility scans: bin/php85-scan.php (run through bin/php85-scan.sh) and
 * bin/compat-api.php (DESIGN.md §11.8). Development tooling only: never loaded by the plugin.
 *
 * - Source:  the significant tokens of one PHP file (no whitespace, comments, doc comments or inline HTML),
 *            bracket matching, enclosing-bracket map, body kinds and name resolution (namespace and `use`).
 * - Guards:  which guard atoms hold at a token: `fn:<name>`, `class:<fqcn>`, `method:<fqcn>::<name>`,
 *            `const:<NAME>` and version atoms `ver:<php|wp|wc>>=<x.y.z>` / `ver:<php|wp|wc><<x.y.z>`.
 *            Recognized: if/elseif/else branches, early exits (`if ( ! function_exists( … ) ) { return; }`,
 *            a body that requires a file, or a body that declares the guarded function or class itself), `&&`,
 *            `||` and `?:` operands.
 * - Version: version normalisation and comparison.
 *
 * Runs on PHP 8.1 and later (the tokenizer view does not depend on the PHP version that runs it).
 *
 * @package LinkGrowth\Autopilot
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tools;

final class Version
{
    /** '6.6' → '6.6.0'; 'MU (3.0.0)' → '3.0.0'; '' when no version number is found. */
    public static function normalize(string $v): string
    {
        if (!preg_match('/(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $v, $m)) {
            return '';
        }
        return (int) $m[1] . '.' . (int) ($m[2] ?? 0) . '.' . (int) ($m[3] ?? 0);
    }

    public static function cmp(string $a, string $b): int
    {
        return version_compare(self::normalize($a), self::normalize($b));
    }

    /** 80300 → '8.3.0' */
    public static function fromId(int $id): string
    {
        return intdiv($id, 10000) . '.' . intdiv($id % 10000, 100) . '.' . ($id % 100);
    }

    /** Lowest version found in all `@since` tags of a doc comment, or '' when there is none. */
    public static function sinceOf(string $doc): string
    {
        $best = '';
        if (preg_match_all('/@since\s+(?:MU\s*\(\s*)?v?(\d+(?:\.\d+){0,3})/i', $doc, $m)) {
            foreach ($m[1] as $v) {
                $n = self::normalize($v);
                if ($n !== '' && ($best === '' || version_compare($n, $best) < 0)) {
                    $best = $n;
                }
            }
        }
        return $best;
    }

    /** First version of an `@deprecated` tag, or '' (null when there is no tag at all). */
    public static function deprecatedOf(string $doc): ?string
    {
        if (!preg_match('/@deprecated\b([^\n]*)/i', $doc, $m)) {
            return null;
        }
        return self::normalize($m[1]);
    }
}

final class Source
{
    /** @var list<string> */
    private const OPENERS = ['(', '[', '{'];

    public string $path;
    public string $rel;
    /** @var array<int, \PhpToken> significant tokens */
    public array $t = [];
    /** @var array<int, int> raw token index of each significant token */
    public array $raw = [];
    /** @var array<int, \PhpToken> */
    public array $all = [];
    /** @var array<int, int> opener index => closer index and closer index => opener index */
    public array $match = [];
    /** @var array<int, int> innermost enclosing opener of each token (-1 at file level) */
    public array $parent = [];
    /** @var array<int, string> kind of a '{': class, interface, trait, enum, function, namespace */
    public array $body = [];
    /** @var list<array{0:int,1:string}> [first token index, namespace] */
    private array $namespaces = [];
    /** @var array<string, string> 'class:alias' / 'function:alias' / 'const:alias' (lower-case alias) => FQ name */
    public array $imports = [];
    /** @var list<string> */
    private array $lines = [];
    public int $n = 0;

    public static function fromFile(string $path, string $rel): self
    {
        $code = (string) file_get_contents($path);
        return self::fromCode($code, $path, $rel);
    }

    public static function fromCode(string $code, string $path, string $rel): self
    {
        $s = new self();
        $s->path = $path;
        $s->rel = $rel;
        $s->lines = preg_split('/\R/', $code) ?: [];
        $s->all = \PhpToken::tokenize($code);
        foreach ($s->all as $ri => $tok) {
            // String fragments of interpolated strings are left out as well: a fragment can read "(", ")" or
            // "`" and must never pair with code brackets or look like an operator. They stay in $all.
            if ($tok->id === T_WHITESPACE || $tok->id === T_COMMENT || $tok->id === T_DOC_COMMENT
                || $tok->id === T_OPEN_TAG || $tok->id === T_INLINE_HTML || $tok->id === T_ENCAPSED_AND_WHITESPACE
                || $tok->id === T_BAD_CHARACTER) {
                continue;
            }
            $s->t[] = $tok;
            $s->raw[] = $ri;
        }
        $s->n = count($s->t);
        $s->index();
        return $s;
    }

    public function text(int $i): string
    {
        return isset($this->t[$i]) ? $this->t[$i]->text : '';
    }

    public function lower(int $i): string
    {
        return strtolower($this->text($i));
    }

    public function id(int $i): int
    {
        return isset($this->t[$i]) ? $this->t[$i]->id : -1;
    }

    public function line(int $i): int
    {
        return isset($this->t[$i]) ? $this->t[$i]->line : 0;
    }

    public function lineText(int $line): string
    {
        return trim($this->lines[$line - 1] ?? '');
    }

    public function isOpener(int $i): bool
    {
        $id = $this->id($i);
        return in_array($this->text($i), self::OPENERS, true) || $id === T_CURLY_OPEN
            || $id === T_DOLLAR_OPEN_CURLY_BRACES || $id === T_ATTRIBUTE;
    }

    public function isCloser(int $i): bool
    {
        $t = $this->text($i);
        return $t === ')' || $t === ']' || $t === '}';
    }

    /** The raw token right after significant token $i (whitespace included), or null. */
    public function rawNext(int $i): ?\PhpToken
    {
        return $this->all[$this->raw[$i] + 1] ?? null;
    }

    /** Name-like token: identifier or (qualified) name. */
    public function isName(int $i): bool
    {
        $id = $this->id($i);
        return $id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED
            || $id === T_NAME_RELATIVE;
    }

    /** True when token $i is the name of a called function (not a method, declaration or `new`). */
    public function isFunctionCall(int $i): bool
    {
        if (!$this->isName($i) || $this->text($i + 1) !== '(') {
            return false;
        }
        $p = $this->id($i - 1);
        if ($p === T_OBJECT_OPERATOR || $p === T_NULLSAFE_OBJECT_OPERATOR || $p === T_DOUBLE_COLON
            || $p === T_FUNCTION || $p === T_NEW || $p === T_CONST || $p === T_FN) {
            return false;
        }
        if (($this->text($i - 1) === '&' || $p === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG
            || $p === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG) && $this->id($i - 2) === T_FUNCTION) {
            return false;
        }
        return true;
    }

    /** @return list<array{0:int,1:int}> argument ranges (inclusive) of the call whose '(' is at $open */
    public function args(int $open): array
    {
        $close = $this->match[$open] ?? -1;
        if ($close < 0) {
            return [];
        }
        $out = [];
        $start = $open + 1;
        for ($k = $open + 1; $k < $close; $k++) {
            if ($this->isOpener($k) && isset($this->match[$k])) {
                $k = $this->match[$k];
                continue;
            }
            if ($this->text($k) === ',') {
                $out[] = [$start, $k - 1];
                $start = $k + 1;
            }
        }
        if ($start <= $close - 1) {
            $out[] = [$start, $close - 1];
        }
        return $out;
    }

    /** Text of a token range without whitespace. */
    public function joined(int $from, int $to): string
    {
        $s = '';
        for ($k = $from; $k <= $to; $k++) {
            $s .= $this->text($k);
        }
        return $s;
    }

    /** The value of a range that is exactly one string literal or `Name::class`; null otherwise. */
    public function literal(int $from, int $to): ?string
    {
        if ($from === $to && $this->id($from) === T_CONSTANT_ENCAPSED_STRING) {
            $raw = $this->text($from);
            $q = $raw[0];
            $body = substr($raw, 1, -1);
            if ($q === "'") {
                return str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
            }
            if (strpos($body, '$') !== false) {
                return null;
            }
            return stripcslashes($body);
        }
        if ($to === $from + 2 && $this->isName($from) && $this->id($from + 1) === T_DOUBLE_COLON
            && $this->lower($from + 2) === 'class') {
            return $this->resolveClass($from) ?? null;
        }
        return null;
    }

    public function namespaceAt(int $i): string
    {
        $ns = '';
        foreach ($this->namespaces as [$start, $name]) {
            if ($start <= $i) {
                $ns = $name;
            }
        }
        return $ns;
    }

    /** Fully qualified class name (no leading backslash) of the name token at $i; null for self/static/parent. */
    public function resolveClass(int $i): ?string
    {
        $name = $this->text($i);
        $id = $this->id($i);
        if ($id === T_NAME_FULLY_QUALIFIED) {
            return ltrim($name, '\\');
        }
        if ($id === T_NAME_RELATIVE) {
            $ns = $this->namespaceAt($i);
            return ltrim($ns . '\\' . substr($name, strlen('namespace\\')), '\\');
        }
        $lower = strtolower($name);
        if (in_array($lower, ['self', 'static', 'parent'], true)) {
            return null;
        }
        $first = strtolower(explode('\\', $name)[0]);
        if (isset($this->imports['class:' . $first])) {
            $rest = substr($name, strlen($first));
            return $this->imports['class:' . $first] . $rest;
        }
        $ns = $this->namespaceAt($i);
        return $ns === '' ? $name : $ns . '\\' . $name;
    }

    /**
     * Candidates for a called function name at $i: [namespaced candidate or null, global name].
     *
     * @return array{0:?string,1:string}
     */
    public function resolveFunction(int $i): array
    {
        $name = $this->text($i);
        $id = $this->id($i);
        if ($id === T_NAME_FULLY_QUALIFIED) {
            $fq = ltrim($name, '\\');
            return [strpos($fq, '\\') !== false ? $fq : null, $fq];
        }
        if ($id === T_NAME_QUALIFIED || $id === T_NAME_RELATIVE) {
            $fq = (string) $this->resolveClass($i);
            return [$fq, $fq];
        }
        $lower = strtolower($name);
        if (isset($this->imports['function:' . $lower])) {
            $fq = $this->imports['function:' . $lower];
            return [strpos($fq, '\\') !== false ? $fq : null, $fq];
        }
        $ns = $this->namespaceAt($i);
        return [$ns === '' ? null : $ns . '\\' . $name, $name];
    }

    /** The innermost '{' enclosing token $i, or -1. */
    public function enclosingBrace(int $i): int
    {
        $p = $this->parent[$i] ?? -1;
        while ($p >= 0 && $this->text($p) !== '{') {
            $p = $this->parent[$p] ?? -1;
        }
        return $p;
    }

    /** Kind of the body that directly contains token $i ('' at file level or in a plain block). */
    public function bodyKindOf(int $i): string
    {
        $p = $this->parent[$i] ?? -1;
        return $p >= 0 ? ($this->body[$p] ?? '') : '';
    }

    private function index(): void
    {
        $stack = [];
        $pendingKind = null;
        $pendingParent = null;
        for ($i = 0; $i < $this->n; $i++) {
            $top = $stack ? $stack[count($stack) - 1] : -1;
            $id = $this->id($i);
            $text = $this->text($i);
            if ($this->isCloser($i)) {
                $open = array_pop($stack);
                if ($open !== null) {
                    $this->match[$open] = $i;
                    $this->match[$i] = $open;
                    $this->parent[$i] = $this->parent[$open];
                } else {
                    $this->parent[$i] = -1;
                }
                continue;
            }
            $this->parent[$i] = $top;
            // Body kinds: the next '{' at the same level after a class-like keyword, function or namespace.
            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $this->id($i - 1) !== T_DOUBLE_COLON) {
                $pendingKind = [T_CLASS => 'class', T_INTERFACE => 'interface', T_TRAIT => 'trait', T_ENUM => 'enum'][$id];
                $pendingParent = $top;
            } elseif ($id === T_FUNCTION) {
                $pendingKind = 'function';
                $pendingParent = $top;
            } elseif ($id === T_NAMESPACE && ($this->isName($i + 1) || $this->text($i + 1) === '{')) {
                $name = $this->isName($i + 1) ? ltrim($this->text($i + 1), '\\') : '';
                $this->namespaces[] = [$i, $name];
                $pendingKind = 'namespace';
                $pendingParent = $top;
            } elseif ($text === ';' && $pendingKind !== null && $pendingParent === $top) {
                $pendingKind = null; // abstract method, `namespace X;`
            }
            if ($text === '{' && $pendingKind !== null && $pendingParent === $top) {
                $this->body[$i] = $pendingKind;
                $pendingKind = null;
            }
            if ($this->isOpener($i)) {
                $stack[] = $i;
            }
            if ($id === T_USE && $this->isImportLevel($top) && $this->text($i - 1) !== ')') {
                $this->readUse($i);
            }
        }
    }

    private function isImportLevel(int $top): bool
    {
        return $top === -1 || ($this->body[$top] ?? '') === 'namespace';
    }

    private function readUse(int $i): void
    {
        $kind = 'class';
        $k = $i + 1;
        if ($this->id($k) === T_FUNCTION) {
            $kind = 'function';
            $k++;
        } elseif ($this->id($k) === T_CONST) {
            $kind = 'const';
            $k++;
        }
        $prefix = '';
        while ($k < $this->n && $this->text($k) !== ';') {
            if ($this->isName($k)) {
                $name = ltrim($this->text($k), '\\');
                if ($this->id($k + 1) === T_NS_SEPARATOR && $this->text($k + 2) === '{') {
                    $prefix = $name . '\\';
                    $k += 3;
                    continue;
                }
                $full = $prefix . $name;
                $alias = substr($full, (int) strrpos('\\' . $full, '\\'));
                $k++;
                if ($this->id($k) === T_AS) {
                    $alias = $this->text($k + 1);
                    $k += 2;
                }
                $this->imports[$kind . ':' . strtolower($alias)] = $full;
                continue;
            }
            if ($this->text($k) === '}') {
                $prefix = '';
            }
            $k++;
        }
    }
}

final class Guards
{
    /** @var list<array{0:int,1:int,2:list<string>}> */
    private array $ranges = [];
    private Source $s;

    /** First tokens of a branch that leaves the enclosing block or loads a file (which may declare anything). */
    private const EXITS = [T_RETURN, T_THROW, T_EXIT, T_CONTINUE, T_BREAK, T_REQUIRE, T_REQUIRE_ONCE,
        T_INCLUDE, T_INCLUDE_ONCE];

    public function __construct(Source $s)
    {
        $this->s = $s;
        for ($i = 0; $i < $s->n; $i++) {
            $id = $s->id($i);
            if ($id === T_IF || $id === T_ELSEIF) {
                $this->ifStatement($i);
            } elseif ($id === T_BOOLEAN_AND || $id === T_BOOLEAN_OR || $id === T_LOGICAL_AND
                || $id === T_LOGICAL_OR || $s->text($i) === '?') {
                $this->inline($i);
            }
        }
    }

    /** @return list<string> the guard atoms that hold at token $i */
    public function at(int $i): array
    {
        $out = [];
        foreach ($this->ranges as [$a, $b, $atoms]) {
            if ($i >= $a && $i <= $b) {
                foreach ($atoms as $x) {
                    $out[$x] = true;
                }
            }
        }
        return array_keys($out);
    }

    /** True when an atom `ver:<kind>>=V` with V >= $since holds. */
    public static function versionCovers(array $atoms, string $kind, string $since): bool
    {
        foreach ($atoms as $a) {
            if (preg_match('/^ver:([a-z]+)>=(.+)$/', $a, $m) && $m[1] === $kind && Version::cmp($since, $m[2]) <= 0) {
                return true;
            }
        }
        return false;
    }

    /** True when an atom `ver:<kind><V` with V <= $version holds (the code only runs below $version). */
    public static function belowCovers(array $atoms, string $kind, string $version): bool
    {
        foreach ($atoms as $a) {
            if (preg_match('/^ver:([a-z]+)<(.+)$/', $a, $m) && $m[1] === $kind && Version::cmp($m[2], $version) <= 0) {
                return true;
            }
        }
        return false;
    }

    private function add(int $from, int $to, array $atoms): void
    {
        if ($atoms && $from <= $to) {
            $this->ranges[] = [$from, $to, array_values(array_unique($atoms))];
        }
    }

    private function ifStatement(int $i): void
    {
        $s = $this->s;
        $o = $i + 1;
        if ($s->text($o) !== '(' || !isset($s->match[$o])) {
            return;
        }
        $c = $s->match[$o];
        [$then, $else] = $this->expr($o + 1, $c - 1);
        $b = $c + 1;
        if ($s->text($b) === ':') {
            return; // alternative syntax is not tracked (not used by the plugin)
        }
        [$bs, $be] = $this->branch($b);
        $this->add($bs, $be, $then);
        $a = $be + 1;
        if ($s->id($a) === T_ELSE) {
            [$es, $ee] = $this->branch($a + 1);
            $this->add($es, $ee, $else);
        } elseif ($s->id($a) === T_ELSEIF) {
            $this->add($a, $this->statementEnd($a), $else);
        } elseif ($else && ($established = $this->established($bs, $be, $else))) {
            $p = $s->enclosingBrace($i);
            $end = $p >= 0 ? $s->match[$p] - 1 : $s->n - 1;
            $this->add($be + 1, $end, $established);
        }
    }

    /**
     * The atoms of $else that hold after a branch without an else: all of them when the branch's first statement
     * leaves the block (return, throw, exit, continue, break, wp_die()) or requires/includes a file; otherwise
     * only the `fn:`/`class:` atoms of the functions and classes the branch itself declares at its top level
     * (`if ( ! function_exists( 'x' ) ) { function x() … }`). A branch that declares something else establishes
     * nothing.
     *
     * @param list<string> $else
     * @return list<string>
     */
    private function established(int $bs, int $be, array $else): array
    {
        $s = $this->s;
        $braced = $s->text($bs) === '{';
        $f = $braced ? $bs + 1 : $bs;
        if (in_array($s->id($f), self::EXITS, true) || ($s->lower($f) === 'wp_die' && $s->text($f + 1) === '(')) {
            return $else;
        }
        $level = $braced ? $bs : ($s->parent[$bs] ?? -1);
        $declared = [];
        for ($k = $f; $k <= ($braced ? $be - 1 : $be); $k++) {
            if (($s->parent[$k] ?? -2) !== $level) {
                continue;
            }
            $id = $s->id($k);
            if ($id === T_FUNCTION) {
                $n = $s->text($k + 1) === '&' || $s->id($k + 1) === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG ? $k + 2 : $k + 1;
                if ($s->id($n) === T_STRING && $s->text($n + 1) === '(') {
                    $declared[] = 'fn:' . strtolower(ltrim($s->namespaceAt($k) . '\\' . $s->text($n), '\\'));
                }
            } elseif (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && $s->id($k + 1) === T_STRING
                && $s->id($k - 1) !== T_DOUBLE_COLON && $s->id($k - 1) !== T_NEW) {
                $declared[] = 'class:' . strtolower(ltrim($s->namespaceAt($k) . '\\' . $s->text($k + 1), '\\'));
            }
        }
        return array_values(array_intersect($else, $declared));
    }

    /** @return array{0:int,1:int} */
    private function branch(int $b): array
    {
        $s = $this->s;
        if ($s->text($b) === '{' && isset($s->match[$b])) {
            return [$b, $s->match[$b]];
        }
        return [$b, $this->statementEnd($b)];
    }

    private function statementEnd(int $j): int
    {
        $s = $this->s;
        $id = $s->id($j);
        if (in_array($id, [T_IF, T_ELSEIF, T_FOREACH, T_FOR, T_WHILE, T_SWITCH, T_DECLARE], true)
            && $s->text($j + 1) === '(' && isset($s->match[$j + 1])) {
            $k = $s->match[$j + 1] + 1;
            $end = ($s->text($k) === '{' && isset($s->match[$k])) ? $s->match[$k] : $this->statementEnd($k);
            if ($id === T_IF || $id === T_ELSEIF) {
                $n = $end + 1;
                if ($s->id($n) === T_ELSEIF) {
                    return $this->statementEnd($n);
                }
                if ($s->id($n) === T_ELSE) {
                    return $this->branch($n + 1)[1];
                }
            }
            return $end;
        }
        if ($s->text($j) === '{' && isset($s->match[$j])) {
            return $s->match[$j];
        }
        for ($k = $j; $k < $s->n; $k++) {
            if ($s->isOpener($k) && isset($s->match[$k])) {
                $k = $s->match[$k];
                continue;
            }
            if ($s->text($k) === ';' || $s->id($k) === T_CLOSE_TAG) {
                return $k;
            }
            if ($s->isCloser($k)) {
                return $k - 1;
            }
        }
        return $s->n - 1;
    }

    /**
     * Atoms that hold when the expression is true and when it is false.
     *
     * @return array{0:list<string>,1:list<string>}
     */
    private function expr(int $from, int $to): array
    {
        $s = $this->s;
        if ($from > $to) {
            return [[], []];
        }
        while ($s->text($from) === '(' && ($s->match[$from] ?? -1) === $to) {
            $from++;
            $to--;
        }
        $parts = $this->split($from, $to, ['||', 'or']);
        if (count($parts) > 1) {
            $then = null;
            $else = [];
            foreach ($parts as [$a, $b]) {
                [$t, $e] = $this->expr($a, $b);
                $then = $then === null ? $t : array_values(array_intersect($then, $t));
                $else = array_merge($else, $e);
            }
            return [$then ?? [], $else];
        }
        $parts = $this->split($from, $to, ['&&', 'and']);
        if (count($parts) > 1) {
            $then = [];
            $else = null;
            foreach ($parts as [$a, $b]) {
                [$t, $e] = $this->expr($a, $b);
                $then = array_merge($then, $t);
                $else = $else === null ? $e : array_values(array_intersect($else, $e));
            }
            return [$then, $else ?? []];
        }
        if ($s->text($from) === '!') {
            [$t, $e] = $this->expr($from + 1, $to);
            return [$e, $t];
        }
        return $this->atom($from, $to);
    }

    /** @return list<array{0:int,1:int}> */
    private function split(int $from, int $to, array $ops): array
    {
        $s = $this->s;
        $parts = [];
        $start = $from;
        for ($k = $from; $k <= $to; $k++) {
            if ($s->isOpener($k) && isset($s->match[$k])) {
                $k = $s->match[$k];
                continue;
            }
            if (in_array($s->lower($k), $ops, true)) {
                $parts[] = [$start, $k - 1];
                $start = $k + 1;
            }
        }
        $parts[] = [$start, $to];
        return $parts;
    }

    /** @return array{0:list<string>,1:list<string>} */
    private function atom(int $from, int $to): array
    {
        $s = $this->s;
        // name( args ) spanning the whole range
        if ($s->isName($from) && $s->text($from + 1) === '(' && ($s->match[$from + 1] ?? -1) === $to) {
            return $this->callAtom($from);
        }
        // comparisons: PHP_VERSION_ID op N, version_compare( a, b ) op 0
        for ($k = $from; $k <= $to; $k++) {
            if ($s->isOpener($k) && isset($s->match[$k])) {
                $k = $s->match[$k];
                continue;
            }
            $op = $s->text($k);
            if (!in_array($op, ['>=', '<=', '>', '<'], true)) {
                continue;
            }
            $l = [$from, $k - 1];
            $r = [$k + 1, $to];
            $flip = ['>=' => '<=', '<=' => '>=', '>' => '<', '<' => '>'];
            if ($this->isVersionId($r) && !$this->isVersionId($l)) {
                [$l, $r] = [$r, $l];
                $op = $flip[$op];
            }
            if ($this->isVersionId($l) && $r[0] === $r[1] && $s->id($r[0]) === T_LNUMBER) {
                $n = (int) $s->text($r[0]);
                if ($op === '>') {
                    $n++;
                    $op = '>=';
                } elseif ($op === '<=') {
                    $n++;
                    $op = '<';
                }
                $v = Version::fromId($n);
                return $op === '>=' ? [['ver:php>=' . $v], ['ver:php<' . $v]] : [['ver:php<' . $v], ['ver:php>=' . $v]];
            }
            // version_compare( a, b ) >= 0
            if ($s->lower($l[0]) === 'version_compare' || ltrim($s->lower($l[0]), '\\') === 'version_compare') {
                if ($r[0] === $r[1] && $s->text($r[0]) === '0' && ($s->match[$l[0] + 1] ?? -1) === $l[1]) {
                    $args = $s->args($l[0] + 1);
                    if (count($args) === 2) {
                        return $this->versionCompare($args[0], $args[1], $op);
                    }
                }
            }
            return [[], []];
        }
        return [[], []];
    }

    /** @param array{0:int,1:int} $r */
    private function isVersionId(array $r): bool
    {
        return $r[0] === $r[1] && ltrim($this->s->text($r[0]), '\\') === 'PHP_VERSION_ID';
    }

    /** @return array{0:list<string>,1:list<string>} */
    private function callAtom(int $from): array
    {
        $s = $this->s;
        $fn = ltrim($s->lower($from), '\\');
        $args = $s->args($from + 1);
        $lit = static function (?array $r) use ($s): ?string {
            return $r === null ? null : $s->literal($r[0], $r[1]);
        };
        switch ($fn) {
            case 'function_exists':
                $v = $lit($args[0] ?? null);
                return $v === null ? [[], []] : [['fn:' . strtolower(ltrim($v, '\\'))], []];
            case 'is_callable':
                $v = $lit($args[0] ?? null);
                if ($v !== null) {
                    if (strpos($v, '::') !== false) {
                        [$c, $m] = explode('::', $v, 2);
                        return [['method:' . strtolower(ltrim($c, '\\')) . '::' . strtolower($m)], []];
                    }
                    return [['fn:' . strtolower(ltrim($v, '\\'))], []];
                }
                if (isset($args[0]) && in_array($s->text($args[0][0]), ['[', 'array'], true)) {
                    $open = $s->text($args[0][0]) === '[' ? $args[0][0] : $args[0][0] + 1;
                    $inner = $s->args($open);
                    $c = $lit($inner[0] ?? null);
                    $m = $lit($inner[1] ?? null);
                    if ($c !== null && $m !== null) {
                        return [['method:' . strtolower(ltrim($c, '\\')) . '::' . strtolower($m)], []];
                    }
                }
                return [[], []];
            case 'class_exists':
            case 'interface_exists':
            case 'trait_exists':
            case 'enum_exists':
                $v = $lit($args[0] ?? null);
                return $v === null ? [[], []] : [['class:' . strtolower(ltrim($v, '\\'))], []];
            case 'method_exists':
                $m = $lit($args[1] ?? null);
                if ($m === null || !isset($args[0])) {
                    return [[], []];
                }
                $c = $lit($args[0]);
                return [['method:' . ($c === null ? '*' : strtolower(ltrim($c, '\\'))) . '::' . strtolower($m)], []];
            case 'defined':
                $v = $lit($args[0] ?? null);
                return $v === null ? [[], []] : [['const:' . ltrim($v, '\\')], []];
            case 'version_compare':
                if (count($args) !== 3) {
                    return [[], []];
                }
                $op = $lit($args[2]);
                return $op === null ? [[], []] : $this->versionCompare($args[0], $args[1], $op);
            case 'is_wp_version_compatible':
            case 'is_php_version_compatible':
                $v = $lit($args[0] ?? null);
                $kind = $fn === 'is_wp_version_compatible' ? 'wp' : 'php';
                return $v === null ? [[], []] : [['ver:' . $kind . '>=' . Version::normalize($v)], ['ver:' . $kind . '<' . Version::normalize($v)]];
        }
        return [[], []];
    }

    /**
     * @param array{0:int,1:int} $a
     * @param array{0:int,1:int} $b
     * @return array{0:list<string>,1:list<string>}
     */
    private function versionCompare(array $a, array $b, string $op): array
    {
        $s = $this->s;
        $map = ['>=' => '>=', 'ge' => '>=', '>' => '>', 'gt' => '>', '<' => '<', 'lt' => '<', '<=' => '<=', 'le' => '<='];
        $op = $map[strtolower($op)] ?? '';
        if ($op === '') {
            return [[], []];
        }
        $kind = $this->subject($a);
        $ver = $s->literal($b[0], $b[1]);
        if ($kind === null) {
            $kind = $this->subject($b);
            $ver = $s->literal($a[0], $a[1]);
            $op = ['>=' => '<=', '<=' => '>=', '>' => '<', '<' => '>'][$op];
        }
        if ($kind === null || $ver === null || Version::normalize($ver) === '') {
            return [[], []];
        }
        $v = Version::normalize($ver);
        if ($op === '>=' || $op === '>') {
            return [['ver:' . $kind . '>=' . $v], ['ver:' . $kind . '<' . $v]];
        }
        if ($op === '<') {
            return [['ver:' . $kind . '<' . $v], ['ver:' . $kind . '>=' . $v]];
        }
        return [[], ['ver:' . $kind . '>=' . $v]]; // '<='
    }

    /** php, wp or wc when the range reads the running PHP, WordPress or WooCommerce version. */
    private function subject(array $r): ?string
    {
        $x = strtolower(ltrim(str_replace('"', "'", $this->s->joined($r[0], $r[1])), '\\'));
        $x = str_replace('(\\', '(', $x);
        $php = ['php_version', 'phpversion()'];
        $wp = ["get_bloginfo('version')", '$wp_version', "\$globals['wp_version']", 'wp_get_wp_version()'];
        $wc = ['wc_version', 'wc()->version', 'woocommerce::instance()->version', '$woocommerce->version',
            "get_option('woocommerce_version')", "constant('wc_version')"];
        if (in_array($x, $php, true)) {
            return 'php';
        }
        if (in_array($x, $wp, true)) {
            return 'wp';
        }
        if (in_array($x, $wc, true)) {
            return 'wc';
        }
        return null;
    }

    private function inline(int $k): void
    {
        $s = $this->s;
        $op = $s->lower($k);
        $p = $s->parent[$k];
        $boundary = [',', ';', '{', '}', '=', '=>', '&&', '||', 'and', 'or', '?', ':', '??', 'return', 'echo',
            'print', 'yield', 'case', '.=', '+=', '-=', '??=', '|=', '&='];
        $start = $k;
        for ($l = $k - 1; $l >= 0; $l--) {
            if ($s->isCloser($l) && isset($s->match[$l])) {
                $l = $s->match[$l];
                $start = $l;
                continue;
            }
            if (($s->parent[$l] ?? -2) !== $p || in_array($s->lower($l), $boundary, true) || $s->id($l) === T_OPEN_TAG) {
                break;
            }
            $start = $l;
        }
        if ($start >= $k) {
            return;
        }
        [$then, $else] = $this->expr($start, $k - 1);
        if (!$then && !$else) {
            return;
        }
        if ($op === '?') {
            $q = $this->ternaryColon($k);
            if ($q < 0) {
                return;
            }
            $this->add($k + 1, $q - 1, $then);
            $this->add($q + 1, $this->rightEnd($q + 1, $p, [',', ';', '?', ':', '??', '=>']), $else);
            return;
        }
        if ($op === '&&' || $op === 'and') {
            $this->add($k + 1, $this->rightEnd($k + 1, $p, [',', ';', '||', 'or', '?', ':', '??', '=>']), $then);
            return;
        }
        $this->add($k + 1, $this->rightEnd($k + 1, $p, [',', ';', '?', ':', '??', '=>']), $else);
    }

    private function ternaryColon(int $k): int
    {
        $s = $this->s;
        $depth = 0;
        for ($j = $k + 1; $j < $s->n; $j++) {
            if ($s->isOpener($j) && isset($s->match[$j])) {
                $j = $s->match[$j];
                continue;
            }
            if ($s->isCloser($j) || $s->text($j) === ';') {
                return -1;
            }
            if ($s->text($j) === '?') {
                $depth++;
            } elseif ($s->text($j) === ':') {
                if ($depth === 0) {
                    return $j;
                }
                $depth--;
            }
        }
        return -1;
    }

    private function rightEnd(int $j, int $p, array $stops): int
    {
        $s = $this->s;
        for ($k = $j; $k < $s->n; $k++) {
            if ($s->isOpener($k) && isset($s->match[$k])) {
                $k = $s->match[$k];
                continue;
            }
            if ($s->isCloser($k) || in_array($s->lower($k), $stops, true) || $s->id($k) === T_CLOSE_TAG) {
                return $k - 1;
            }
        }
        return $s->n - 1;
    }
}

final class Files
{
    /**
     * The seeded-violation fixtures of tests/Unit/Wiring/CompatScansTest.php (relative to the plugin root). The
     * default targets of both scans leave out exactly this folder: the path must be equal, so a sibling such as
     * tests/fixtures/compat-scan-other/ is still scanned.
     */
    public const FIXTURES = 'tests/fixtures/compat-scan';

    /**
     * PHP files under $root for the given relative entries (files or folders, recursive *.php).
     *
     * @param list<string> $entries
     * @param list<string> $skipDirs folder names left out wherever they are
     * @param list<string> $skipPaths folders left out by their exact path relative to $root (no prefix match)
     * @return list<string> relative paths, sorted
     */
    public static function collect(string $root, array $entries, array $skipDirs = ['vendor', 'node_modules', '.phpunit.cache', 'dist', '.git'], array $skipPaths = []): array
    {
        $out = [];
        $skip = array_flip(array_map(static function (string $p): string {
            return trim($p, '/');
        }, $skipPaths));
        $base = strlen($root) + 1;
        foreach ($entries as $e) {
            $abs = $root . '/' . $e;
            if (is_file($abs)) {
                $out[] = $e;
                continue;
            }
            if (!is_dir($abs) || isset($skip[trim($e, '/')])) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
                    static function (\SplFileInfo $f) use ($skipDirs, $skip, $base): bool {
                        if (!$f->isDir()) {
                            return true;
                        }
                        $rel = substr($f->getPathname(), $base);
                        if (strncmp($rel, './', 2) === 0) {
                            $rel = substr($rel, 2);
                        }
                        return !in_array($f->getFilename(), $skipDirs, true) && !isset($skip[$rel]);
                    }
                )
            );
            foreach ($it as $f) {
                if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
                    $out[] = substr($f->getPathname(), strlen($root) + 1);
                }
            }
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /** The plugin's main file: the root *.php file with a "Plugin Name:" header. */
    public static function mainFile(string $root): ?string
    {
        foreach (glob($root . '/*.php') ?: [] as $f) {
            $head = (string) file_get_contents($f, false, null, 0, 4096);
            if (preg_match('/^[ \t\/*#@]*Plugin Name:/mi', $head)) {
                return basename($f);
            }
        }
        return null;
    }

    /** A header value of the plugin's main file ('Requires at least', 'WC requires at least', …). */
    public static function header(string $file, string $name): ?string
    {
        $head = (string) file_get_contents($file, false, null, 0, 8192);
        if (preg_match('/^[ \t\/*#@]*' . preg_quote($name, '/') . ':\s*(.+)$/mi', $head, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
