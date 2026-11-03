#!/usr/bin/env bash
# Syntax-check every PHP file in the plugin and the test suite, and every JS module.
set -euo pipefail
cd "$(dirname "$0")/.."
status=0
while IFS= read -r -d '' f; do
  if ! php -l "$f" >/dev/null 2>&1; then
    echo "PHP syntax error: $f"; php -l "$f" || true; status=1
  fi
done < <(find ppc-link-growth-engine tests -name '*.php' -print0)
if command -v node >/dev/null 2>&1; then
  while IFS= read -r -d '' f; do
    if ! node --input-type=module --check < "$f" >/dev/null 2>&1; then
      echo "JS syntax error: $f"; node --input-type=module --check < "$f" || true; status=1
    fi
  done < <(find ppc-link-growth-engine/assets/js -name '*.js' -print0)
fi
for f in ppc-link-growth-engine/data/*.json; do
  php -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "$f" || { echo "Invalid JSON: $f"; status=1; }
done
[ "$status" -eq 0 ] && echo "Lint OK" || echo "Lint FAILED"
exit $status
