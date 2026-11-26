#!/usr/bin/env bash
# Syntax-check every PHP file in the plugin and the test suite, every JS module (and the E2E suite with its control
# manifests) and every JSON data file,
# then run the neutrality scan (bin/neutrality.sh, DESIGN.md §1.7) and the static compatibility scans of
# DESIGN.md §11.8: bin/php85-scan.sh (PHP 8.1-8.5 rules and the PHP 8.1 floor) and bin/compat-api.php
# (WordPress 6.5 / WooCommerce 8.0 API floors). Both scans need only the php on PATH (8.3 here); they do not need
# the PHP 8.5 toolchain of §12.6.
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
  # The browser E2E suite (DESIGN.md §12.5): its modules and its control manifests (schema checked without a browser).
  while IFS= read -r -d '' f; do
    if ! node --check "$f" >/dev/null 2>&1; then
      echo "JS syntax error: $f"; node --check "$f" || true; status=1
    fi
  done < <(find tests/e2e -name '*.mjs' -print0)
  if ! node tests/e2e/run.mjs --check-manifests; then
    status=1
  fi
else
  echo "JS syntax check not run: node is not installed."
fi
while IFS= read -r -d '' f; do
  php -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "$f" || { echo "Invalid JSON: $f"; status=1; }
done < <(find data -name '*.json' -print0)
if ! bash bin/neutrality.sh; then
  status=1
fi
# PHP 8.1-8.5 deprecation rules on the plugin, its tests and bin/, the PHP 8.1 floor on the shipped files, then
# php -l with the oldest PHP available. The seeded violations in tests/fixtures/compat-scan are not a default target.
if ! bash bin/php85-scan.sh --min=8.1; then
  status=1
fi
# WordPress 6.5 / WooCommerce 8.0 API floors, looked up in the source of the LGA test site (LGA_WP_PATH,
# DESIGN.md §12.1). Exit 3 means the source is missing: reported as not run, never as passed.
compat_status=0
php bin/compat-api.php || compat_status=$?
case "$compat_status" in
  0) ;;
  3) echo "compat-api: not run (set LGA_WP_PATH to the LGA test site to check the WordPress/WooCommerce floors)" ;;
  *) status=1 ;;
esac
[ "$status" -eq 0 ] && echo "Lint OK" || echo "Lint FAILED"
exit $status
