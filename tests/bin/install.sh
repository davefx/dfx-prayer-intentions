#!/usr/bin/env bash
#
# One-time setup for the PHPUnit suite.
#
#   ./tests/bin/install.sh [db-name] [db-user] [db-pass] [db-host] [wp-version]
#
# Downloads WordPress core and the WordPress test library into tests/.wp/ and
# configures them against the database you name. Run tests/bin/start-db.sh
# first if you have no database handy.

set -euo pipefail

DB_NAME="${1:-wordpress_test}"
DB_USER="${2:-root}"
DB_PASS="${3:-}"
DB_HOST="${4:-127.0.0.1:3310}"
WP_VERSION="${5:-latest}"

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WP_DIR="${ROOT}/tests/.wp/wordpress"
LIB_DIR="${ROOT}/tests/.wp/wordpress-tests-lib"
INSTALLER="${ROOT}/tests/.wp/install-wp-tests.sh"

mkdir -p "${ROOT}/tests/.wp"

if [ ! -f "${INSTALLER}" ]; then
	curl -fsSL \
		-o "${INSTALLER}" \
		https://raw.githubusercontent.com/wp-cli/scaffold-command/main/templates/install-wp-tests.sh
	chmod +x "${INSTALLER}"
fi

WP_CORE_DIR="${WP_DIR}/" WP_TESTS_DIR="${LIB_DIR}/" \
	"${INSTALLER}" "${DB_NAME}" "${DB_USER}" "${DB_PASS}" "${DB_HOST}" "${WP_VERSION}" true

echo "Done. Run: composer test"
