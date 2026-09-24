#!/usr/bin/env bash
# Builds the distributable plugin directory: bin/build.sh <output-dir>
# Used by the GitHub release workflow and for manual deploys, so both ship the same files.
set -euo pipefail
SRC="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:?usage: bin/build.sh <output-dir>}"
rm -rf "$OUT" && mkdir -p "$OUT"
rsync -a --exclude='.git' --exclude='.github' --exclude='tests' --exclude='bin' --exclude='*.zip' --exclude='node_modules' --exclude='.DS_Store' "$SRC/" "$OUT/"
# Minified copies next to the sources; the plugin loads *.min.* when present.
npx --yes terser@5.36.0 "$OUT/assets/js/main.js" --compress --mangle --comments false -o "$OUT/assets/js/main.min.js"
npx --yes csso-cli@4.0.2 "$OUT/assets/css/style.css" -o "$OUT/assets/css/style.min.css"
node --check "$OUT/assets/js/main.min.js"
echo "built: $OUT ($(wc -c < "$OUT/assets/js/main.js") -> $(wc -c < "$OUT/assets/js/main.min.js") B js, $(wc -c < "$OUT/assets/css/style.css") -> $(wc -c < "$OUT/assets/css/style.min.css") B css)"
