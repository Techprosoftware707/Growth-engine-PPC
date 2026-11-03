#!/usr/bin/env bash
# Builds a distributable plugin zip (dist/ppc-link-growth-engine-<version>.zip) ready for
# Plugins → Add New → Upload Plugin.
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(grep -E '^\s*\*\s*Version:' ppc-link-growth-engine/ppc-link-growth-engine.php | head -1 | sed -E 's/.*Version:\s*//')
mkdir -p dist
OUT="dist/ppc-link-growth-engine-${VERSION}.zip"
rm -f "$OUT"
bash bin/lint.sh
( cd . && zip -qr "$OUT" ppc-link-growth-engine -x '*.DS_Store' -x '*/.git*' -x '*/node_modules/*' )
echo "Built $OUT ($(du -h "$OUT" | cut -f1))"
