#!/usr/bin/env bash
# Syntax-check every PHP file in the plugin and the test suite, every JS module and every JSON data file,
# then run the neutrality scan (bin/neutrality.sh, DESIGN.md §1.7).
set -euo pipefail
cd "$(dirname "$0")/.."
status=0
while IFS= read -r -d '' f; do
  if ! php -l "$f" >/dev/null 2>&1; then
    echo "PHP syntax error: $f"; php -l "$f" || true; status=1
  fi
done < <(find linkgrowth-autopilot.php uninstall.php includes tests bin -name '*.php' -print0)
if command -v node >/dev/null 2>&1; then
  while IFS= read -r -d '' f; do
    if ! node --input-type=module --check < "$f" >/dev/null 2>&1; then
      echo "JS syntax error: $f"; node --input-type=module --check < "$f" || true; status=1
    fi
  done < <(find assets/js -name '*.js' -print0)
else
  echo "JS syntax check not run: node is not installed."
fi
while IFS= read -r -d '' f; do
  php -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "$f" || { echo "Invalid JSON: $f"; status=1; }
done < <(find data -name '*.json' -print0)
if ! bash bin/neutrality.sh; then
  status=1
fi
[ "$status" -eq 0 ] && echo "Lint OK" || echo "Lint FAILED"
exit $status
