#!/usr/bin/env bash
#
# Brings up a throwaway MySQL in Docker for the test suites (PHPUnit and E2E).
#
#   ./tests/bin/start-db.sh          start it (idempotent)
#   ./tests/bin/start-db.sh stop     stop and remove it
#   ./tests/bin/start-db.sh reset    throw the data away and start again
#
# It listens on 127.0.0.1:3310, so it cannot collide with whatever database
# server already runs on 3306. The data lives in the container only: removing
# the container throws it away, which is the point.

set -euo pipefail

PORT="${DFXPRAY_DB_PORT:-3310}"
NAME="dfxpray-test-db"
IMAGE="${DFXPRAY_DB_IMAGE:-mysql:8.0}"
DATABASES="wordpress_test dfxpray_e2e"

is_up() {
	mysql -h 127.0.0.1 -P "${PORT}" -u root -e "SELECT 1" >/dev/null 2>&1
}

create_databases() {
	for db in ${DATABASES}; do
		mysql -h 127.0.0.1 -P "${PORT}" -u root -e "CREATE DATABASE IF NOT EXISTS ${db};" 2>/dev/null
	done
}

stop_db() {
	docker rm -f "${NAME}" >/dev/null 2>&1 || true
	echo "Stopped."
}

case "${1:-start}" in
	stop)
		stop_db
		exit 0
		;;
	reset)
		stop_db
		;;
esac

if is_up; then
	create_databases
	echo "Already up on 127.0.0.1:${PORT}."
	exit 0
fi

if docker ps -a --format '{{.Names}}' | grep -qx "${NAME}"; then
	docker start "${NAME}" >/dev/null
else
	echo "Starting ${IMAGE} on 127.0.0.1:${PORT}…"
	docker run -d --name "${NAME}" \
		-p "127.0.0.1:${PORT}:3306" \
		-e MYSQL_ALLOW_EMPTY_PASSWORD=1 \
		--tmpfs /var/lib/mysql:rw \
		"${IMAGE}" >/dev/null
fi

for _ in $(seq 1 60); do
	sleep 1
	if is_up; then
		break
	fi
done

if ! is_up; then
	echo "Failed to start. Last lines of the container log:" >&2
	docker logs --tail 20 "${NAME}" >&2
	exit 1
fi

create_databases
echo "Up on 127.0.0.1:${PORT}."
