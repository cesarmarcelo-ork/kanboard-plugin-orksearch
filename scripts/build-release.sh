#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
VERSION="$(sed -n "/function getPluginVersion()/,/}/s/.*return '\([^']*\)'.*/\1/p" Plugin.php | head -1)"
[[ -n "$VERSION" ]] || { echo 'Cannot determine plugin version' >&2; exit 1; }
for program in zip unzip php node python3; do command -v "$program" >/dev/null || { echo "Missing $program" >&2; exit 1; }; done
bash Test/package.test.sh >/dev/null
node Test/pinned-navigation.test.js >/dev/null
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/OrkSearch" "$ROOT/dist"
cp -R Assets Controller Service Template Tools Test docs Plugin.php README.md CHANGELOG.md RELEASE_NOTES.md LICENSE "$TMP/OrkSearch/"
find "$TMP/OrkSearch" -type d -name __pycache__ -prune -exec rm -rf '{}' +
find "$TMP/OrkSearch" -type f -name '*.pyc' -delete
rm -f "$ROOT/dist/OrkSearch-$VERSION.zip"
(cd "$TMP" && zip -q -r -X "$ROOT/dist/OrkSearch-$VERSION.zip" OrkSearch)
unzip -tq "$ROOT/dist/OrkSearch-$VERSION.zip"
unzip -Z1 "$ROOT/dist/OrkSearch-$VERSION.zip" | grep -Fxq 'OrkSearch/Plugin.php'
echo "Package: $ROOT/dist/OrkSearch-$VERSION.zip"
sha256sum "$ROOT/dist/OrkSearch-$VERSION.zip"
