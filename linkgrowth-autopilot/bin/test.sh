#!/usr/bin/env bash
# Runs the unit test suite. Uses vendor/bin/phpunit when installed, otherwise a phpunit.phar
# placed at bin/phpunit.phar or given via PHPUNIT_PHAR.
set -euo pipefail
cd "$(dirname "$0")/.."
if [ -x vendor/bin/phpunit ]; then
  exec vendor/bin/phpunit "$@"
fi
PHAR="${PHPUNIT_PHAR:-bin/phpunit.phar}"
if [ -f "$PHAR" ]; then
  exec php "$PHAR" "$@"
fi
echo "PHPUnit not found. Run 'composer install' or download phpunit-11.phar to bin/phpunit.phar (https://phar.phpunit.de/)." >&2
exit 1
