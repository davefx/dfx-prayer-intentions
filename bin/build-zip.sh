#!/usr/bin/env bash
#
# Builds dist/dfx-prayer-intentions-<version>.zip with only what ships
# (everything not listed in .distignore).

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(sed -n "s/^define( 'DFXPRAY_VERSION', '\(.*\)' );$/\1/p" "${ROOT}/dfx-prayer-intentions.php")"
STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT

mkdir -p "${STAGE}/dfx-prayer-intentions" "${ROOT}/dist"
rsync -a --exclude-from="${ROOT}/.distignore" "${ROOT}/" "${STAGE}/dfx-prayer-intentions/"

rm -f "${ROOT}/dist/dfx-prayer-intentions-${VERSION}.zip"
( cd "${STAGE}" && zip -qr "${ROOT}/dist/dfx-prayer-intentions-${VERSION}.zip" dfx-prayer-intentions )

echo "dist/dfx-prayer-intentions-${VERSION}.zip"
