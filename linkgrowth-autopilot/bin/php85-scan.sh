#!/usr/bin/env bash
# PHP 8.1-8.5 static scan (DESIGN.md §11.8), run by bin/lint.sh as `bash bin/php85-scan.sh --min=8.1`.
#
#   bash bin/php85-scan.sh [--min=8.1] [--root=DIR] [--no-lint] [PATH ...]
#
# 1. bin/php85-scan.php: the PHP 8.1-8.5 deprecation rules on every PHP file of the plugin (main file,
#    uninstall.php, includes/, tests/, bin/) and, with --min, the floor rules (syntax, functions and classes
#    newer than that PHP version) on the shipped files (main file, uninstall.php, includes/). The checks look at
#    PHP tokens, so comments and string contents never match; function and class uses are accepted inside
#    function_exists()/class_exists() or PHP_VERSION_ID guards. PATH arguments (files of any extension or
#    folders) replace the default file list and get both rule sets.
# 2. `php -l` of the same files with the oldest PHP found (php8.1 … php8.5 and php on PATH, /usr/bin/php8.*,
#    or LGA_PHP_LINT), unless --no-lint.
#
# The scan itself runs with $PHP (default: php on PATH). The PHP 8.5 wrapper of DESIGN.md §12.6 exports PHPRC, which
# child processes inherit; another PHP build cannot load that php.ini, so a caller running under the real php8.5
# binary passes PHP=<that binary>. The php -l step always runs with -n (no php.ini).
# Exit 0 when clean, 1 on findings, 2 on usage errors.
# No PHP 8.1 runtime is available here, so the 8.1 floor is a static check ("static only" in TEST-RESULTS.md).
set -uo pipefail
BIN="$(cd "$(dirname "$0")" && pwd)"
PHP="${PHP:-php}"
lint=1
args=()
for a in "$@"; do
  case "$a" in
    --no-lint) lint=0 ;;
    -h|--help) sed -n '2,19p' "$0"; exit 0 ;;
    *) args+=("$a") ;;
  esac
done
if ! command -v "$PHP" >/dev/null 2>&1; then
  echo "php85-scan not run: no PHP binary ($PHP)" >&2
  exit 2
fi
list="$(mktemp "${TMPDIR:-/tmp}/lga-php85-scan.XXXXXX")"
trap 'rm -f "$list"' EXIT
"$PHP" "$BIN/php85-scan.php" --files-out="$list" "${args[@]}"
status=$?
[ "$status" -gt 1 ] && exit "$status"

if [ "$lint" -eq 1 ]; then
  # The oldest PHP available for the syntax check.
  best=""; bestid=999999
  cands=("${LGA_PHP_LINT:-}" php php8.1 php8.2 php8.3 php8.4 php8.5 /usr/bin/php8.*)
  for c in "${cands[@]}"; do
    [ -n "$c" ] || continue
    p="$(command -v "$c" 2>/dev/null)" || continue
    v="$("$p" -n -r 'echo PHP_VERSION_ID;' 2>/dev/null)" || continue
    case "$v" in ''|*[!0-9]*) continue ;; esac
    if [ "$v" -lt "$bestid" ]; then best="$p"; bestid="$v"; fi
  done
  if [ -z "$best" ]; then
    echo "php85-scan: php -l not run: no PHP binary found"
    status=1
  else
    ver="$("$best" -n -r 'echo PHP_VERSION;')"
    bad=0; count=0
    while IFS= read -r f; do
      [ -n "$f" ] || continue
      count=$((count + 1))
      if ! out="$("$best" -n -d error_reporting=-1 -l "$f" 2>&1)"; then
        echo "$f: [php-lint] PHP $ver: php -l failed"
        printf '%s\n' "$out" | grep -v '^No syntax errors' | sed 's/^/    /'
        bad=$((bad + 1))
      fi
    done < "$list"
    echo "php85-scan: php -l with PHP $ver ($best): $count file(s), $bad failed"
    [ "$bad" -gt 0 ] && status=1
  fi
fi
if [ "$status" -eq 0 ]; then echo "php85-scan OK"; else echo "php85-scan FAILED"; fi
exit "$status"
