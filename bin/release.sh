#!/usr/bin/env bash
#
# The whole release in one command: nothing is packaged unless everything
# is green.
#
#   bin/release.sh                  lint, PHPUnit, PHPUnit on PHP 8.1, E2E, zip
#   bin/release.sh --skip-e2e       without the browser suite (~10 minutes)
#   bin/release.sh --skip-matrix    without the PHP 8.1 run in Docker
#
# Also available as `composer release`. Publishing (GitHub release, SVN) is
# a separate, deliberate step.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
E2E=1
MATRIX=1

for arg in "$@"; do
	case "${arg}" in
		--skip-e2e) E2E=0 ;;
		--skip-matrix) MATRIX=0 ;;
		*) echo "Unknown option ${arg}" >&2; exit 2 ;;
	esac
done

step() {
	printf '\n━━ %s\n' "$*"
}

cd "${ROOT}"

if [ -n "$(git status --porcelain 2>/dev/null)" ]; then
	echo "! The working tree has uncommitted changes; the zip will include them." >&2
fi

step "Coding standards"
vendor/bin/phpcs -q

step "PHPUnit"
tests/bin/start-db.sh >/dev/null
vendor/bin/phpunit --do-not-cache-result | tail -3

if [ "${MATRIX}" = 1 ]; then
	step "PHPUnit on PHP 8.1 (the declared floor)"
	tests/bin/php-matrix.sh 8.1
fi

if [ "${E2E}" = 1 ]; then
	step "End-to-end"
	npx playwright test --reporter=dot
fi

step "Package"
ZIP="$(bin/build-zip.sh | tee /dev/stderr | tail -1)"

printf '\n✓ Release ready: %s\n  sha256 %s\n' "${ZIP}" "$(sha256sum "${ZIP}" | cut -d' ' -f1)"
