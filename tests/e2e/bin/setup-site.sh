#!/usr/bin/env bash
#
# Builds (or rebuilds) the WordPress site the E2E suite runs against:
#
#   tests/.wp/e2e-site   WordPress core, served by `php -S` (see playwright.config.js)
#   database dfxpray_e2e on the test MySQL (tests/bin/start-db.sh)
#
# Every run starts from an empty database: fresh install, plugin activated,
# three users (admin/admin, leader1/leader1, leader2/leader2).
#
# The site defines DFXPRAY_ALLOW_FROZEN_CLOCK so tests can move the plugin's
# clock, disables WP-Cron (tests run the tick through WP-CLI), and loads a
# must-use plugin that captures outgoing email and HTTP instead of sending it.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
SITE="${ROOT}/tests/.wp/e2e-site"
PORT="${DFXPRAY_E2E_PORT:-8890}"
URL="http://127.0.0.1:${PORT}"
DB_PORT="${DFXPRAY_DB_PORT:-3310}"
WP_LOG="$(mktemp)"
trap 'rm -f "${WP_LOG}"' EXIT

# Every WP-CLI call goes through here, so any PHP notice the plugin causes
# while loading (activation, cron, roles) fails the setup instead of scrolling by.
WP() {
	wp --path="${SITE}" --quiet "$@" 2> >(tee -a "${WP_LOG}" >&2)
}

"${ROOT}/tests/bin/start-db.sh" >/dev/null

if [ ! -f "${SITE}/wp-load.php" ]; then
	mkdir -p "${SITE}"
	if [ -f "${ROOT}/tests/.wp/wordpress/wp-load.php" ]; then
		cp -r "${ROOT}/tests/.wp/wordpress/." "${SITE}/"
	else
		WP core download --locale=en_US
	fi
fi

rm -f "${SITE}/wp-config.php"
WP config create \
	--dbname=dfxpray_e2e --dbuser=root --dbpass= --dbhost="127.0.0.1:${DB_PORT}" \
	--skip-check --force --extra-php <<'PHP'
define( 'DFXPRAY_ALLOW_FROZEN_CLOCK', true );
define( 'DISABLE_WP_CRON', true );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', __DIR__ . '/wp-content/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );
PHP

WP db reset --yes
WP core install --url="${URL}" --title="DFX Prayer E2E" \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.org --skip-email
WP option update timezone_string Europe/Madrid
WP option update permalink_structure '/%postname%/'

mkdir -p "${SITE}/wp-content/plugins" "${SITE}/wp-content/mu-plugins"
ln -sfn "${ROOT}" "${SITE}/wp-content/plugins/dfx-prayer-intentions"
cp "${ROOT}/tests/e2e/mu-plugins/dfxpray-e2e.php" "${SITE}/wp-content/mu-plugins/"
: > "${SITE}/wp-content/debug.log"

# Core Spanish, for the i18n spec (weekday names come from core). Kept between runs.
if [ ! -f "${SITE}/wp-content/languages/es_ES.mo" ]; then
	WP language core install es_ES || echo "Could not download core es_ES; the i18n spec will fail." >&2
fi

WP plugin activate dfx-prayer-intentions
WP user create leader1 leader1@example.org --role=dfxpray_leader --user_pass=leader1 --display_name="Leader One"
WP user create leader2 leader2@example.org --role=dfxpray_leader --user_pass=leader2 --display_name="Leader Two"
WP rewrite flush

wp --path="${SITE}" eval 'DFXPRAY_Scheduler::get_instance()->tick();' 2> >(tee -a "${WP_LOG}" >&2)
sleep 0.2

if grep -q "dfx-prayer-intentions" "${WP_LOG}"; then
	echo "The plugin raised PHP notices while setting up the site:" >&2
	grep "dfx-prayer-intentions" "${WP_LOG}" >&2
	exit 1
fi

echo "E2E site ready at ${URL}"
