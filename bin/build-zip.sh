#!/usr/bin/env bash
#
# Builds the installable zip: dfx-prayer-intentions-<version>.zip, holding a
# dfx-prayer-intentions/ folder with everything not listed in .distignore.
#
#   bin/build-zip.sh                       into dist/
#   DFXPRAY_DIST_DIR=/tmp/x bin/build-zip.sh
#
# Before packaging it checks that every place stating the version agrees,
# recompiles the translations from the .po, and lints every PHP file that
# ships. The last line of output is the absolute path of the zip.
#
# For a full release (lint, tests, E2E, then this), use bin/release.sh.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="${DFXPRAY_DIST_DIR:-${ROOT}/dist}"
SLUG="dfx-prayer-intentions"

fail() {
	echo "✗ $*" >&2
	exit 1
}

# 1. One version everywhere.
VERSION="$(sed -n "s/^define( 'DFXPRAY_VERSION', '\(.*\)' );$/\1/p" "${ROOT}/${SLUG}.php")"
[[ "${VERSION}" =~ ^[0-9]{2}\.[0-9]{2}\.[0-9]{2}$ ]] || fail "DFXPRAY_VERSION '${VERSION}' is not YY.MM.DD."

grep -qE "^ \* Version: +${VERSION}$" "${ROOT}/${SLUG}.php" || fail "Plugin header Version is not ${VERSION}."
grep -q "\"version\": \"${VERSION}\"" "${ROOT}/blocks/form/block.json" || fail "blocks/form/block.json version is not ${VERSION}."
grep -q "'version'      => '${VERSION}'" "${ROOT}/blocks/form/editor.asset.php" || fail "blocks/form/editor.asset.php version is not ${VERSION}."
grep -q "^Stable tag: ${VERSION}$" "${ROOT}/readme.txt" || fail "readme.txt Stable tag is not ${VERSION}."
grep -q "^= ${VERSION} =$" "${ROOT}/readme.txt" || fail "readme.txt has no changelog entry for ${VERSION}."
echo "✓ Version ${VERSION} everywhere"

# 2. Compiled translations follow the .po.
if command -v wp >/dev/null 2>&1; then
	wp i18n make-mo "${ROOT}/languages" >/dev/null
	rm -f "${ROOT}"/languages/*.json
	wp i18n make-json "${ROOT}/languages" --no-purge >/dev/null
	echo "✓ Translations compiled"
else
	echo "! WP-CLI not found: shipping the translations as they are" >&2
fi

# 3. Stage what ships.
STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT
mkdir -p "${STAGE}/${SLUG}"
rsync -a --exclude-from="${ROOT}/.distignore" "${ROOT}/" "${STAGE}/${SLUG}/"

# 4. Every shipped PHP file parses.
while IFS= read -r -d '' file; do
	php -l "${file}" >/dev/null 2>&1 || fail "Syntax error in ${file#"${STAGE}/"}: $(php -l "${file}" 2>&1 | head -1)"
done < <(find "${STAGE}/${SLUG}" -name '*.php' -print0)
echo "✓ PHP files parse"

# 5. Zip.
mkdir -p "${DIST}"
DIST="$(cd "${DIST}" && pwd)"
ZIP="${DIST}/${SLUG}-${VERSION}.zip"
rm -f "${ZIP}"
( cd "${STAGE}" && zip -qr "${ZIP}" "${SLUG}" )

echo "✓ $(unzip -Z1 "${ZIP}" | grep -vc '/$') files, $(du -h "${ZIP}" | cut -f1)"
echo "${ZIP}"
