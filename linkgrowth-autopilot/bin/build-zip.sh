#!/usr/bin/env bash
# Builds the two release archives of DESIGN.md §1.3 steps 6 and 7 into dist/ (OUT_DIR overrides the folder):
#
#   linkgrowth-autopilot-<version>.zip         the installable plugin (Plugins → Add New Plugin → Upload Plugin):
#                                              one top folder linkgrowth-autopilot/ with only the shipped allowlist
#                                              linkgrowth-autopilot.php uninstall.php readme.txt includes/ assets/
#                                              data/ languages/; checked with bin/neutrality.sh --zip
#   linkgrowth-autopilot-<version>-source.zip  the source deliverable: one top folder linkgrowth-autopilot-source/ with
#                                              the plugin allowlist plus tests/ docs/ bin/ composer.json
#                                              phpunit.xml.dist; checked with bin/neutrality.sh --source (patterns
#                                              A, B and D with no exception and no pending list)
#
# Left out of both: *.DS_Store, .git*, node_modules/, .phpunit.cache/, *.map and dist/. Left out of the source ZIP
# as well: tests/e2e/shots/, bin/phpunit.phar (a downloaded tool, see bin/test.sh) and the files that must name the
# engine this plugin was forked from: docs/DESIGN.md, docs/notes/, tests/PORTING.md and
# tests/integration/coexistence.php. Files are staged in a temporary folder (TMPDIR), lint runs first, and the
# build fails when either neutrality check finds anything. Both archives are written and checked in the staging
# folder and moved into the output folder only after both checks pass, so a failed build leaves no archive under a
# release name (the previous build's archives are removed when the build starts).
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(grep -E '^\s*\*\s*Version:' linkgrowth-autopilot.php | head -1 | sed -E 's/.*Version:\s*//')
[ -n "$VERSION" ] || { echo "build-zip: no Version in the plugin header" >&2; exit 1; }
OUT_DIR="${OUT_DIR:-dist}"
mkdir -p "$OUT_DIR"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"
PLUGIN_ZIP="$OUT_DIR/linkgrowth-autopilot-${VERSION}.zip"
SOURCE_ZIP="$OUT_DIR/linkgrowth-autopilot-${VERSION}-source.zip"
rm -f "$PLUGIN_ZIP" "$SOURCE_ZIP"

bash bin/lint.sh

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/lga-build.XXXXXX")"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/out"
STAGED_PLUGIN="$STAGE/out/$(basename "$PLUGIN_ZIP")"
STAGED_SOURCE="$STAGE/out/$(basename "$SOURCE_ZIP")"
SHIPPED=(linkgrowth-autopilot.php uninstall.php readme.txt includes assets data languages)
DEV=(tests docs bin composer.json phpunit.xml.dist)
EXCLUDES=(-x '*.DS_Store' -x '*/.git*' -x '*/node_modules/*' -x '*/.phpunit.cache/*' -x '*.map')

# ------------------------------------------------------------------ plugin ZIP (step 6)
mkdir -p "$STAGE/plugin/linkgrowth-autopilot"
for path in "${SHIPPED[@]}"; do
  [ -e "$path" ] && cp -R "$path" "$STAGE/plugin/linkgrowth-autopilot/"
done
( cd "$STAGE/plugin" && zip -qr "$STAGED_PLUGIN" linkgrowth-autopilot "${EXCLUDES[@]}" )
bash bin/neutrality.sh --zip "$STAGED_PLUGIN"

# ------------------------------------------------------------------ source ZIP (step 7)
SRC="$STAGE/source/linkgrowth-autopilot-source"
mkdir -p "$SRC"
for path in "${SHIPPED[@]}" "${DEV[@]}"; do
  [ -e "$path" ] && cp -R "$path" "$SRC/"
done
rm -rf "$SRC/docs/DESIGN.md" "$SRC/docs/notes" "$SRC/tests/PORTING.md" "$SRC/tests/integration/coexistence.php" \
  "$SRC/tests/e2e/shots" "$SRC/bin/phpunit.phar" "$SRC/dist" "$SRC/.phpunit.cache"
find "$SRC" \( -name node_modules -o -name .phpunit.cache \) -prune -exec rm -rf {} +
rmdir "$SRC/docs" 2>/dev/null || true # empty until WP29 adds the deliverable docs
# A file that records a path of the temporary build or test environment does not belong in the source.
if grep -rIlE '/t[m]p/|/scratch[p]ad' "$SRC" >/dev/null 2>&1; then
  echo "build-zip: these staged files contain a temporary path:" >&2
  grep -rIlE '/t[m]p/|/scratch[p]ad' "$SRC" | sed "s#^$SRC/#  #" >&2
  exit 1
fi
( cd "$STAGE/source" && zip -qr "$STAGED_SOURCE" linkgrowth-autopilot-source "${EXCLUDES[@]}" )
bash bin/neutrality.sh --source "$STAGED_SOURCE"

# ------------------------------------------------------------------ publish (both checks passed)
mv "$STAGED_PLUGIN" "$PLUGIN_ZIP"
mv "$STAGED_SOURCE" "$SOURCE_ZIP"
echo "Built $PLUGIN_ZIP ($(du -h "$PLUGIN_ZIP" | cut -f1))"
echo "Built $SOURCE_ZIP ($(du -h "$SOURCE_ZIP" | cut -f1))"
