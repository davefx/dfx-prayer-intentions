#!/usr/bin/env bash
#
# Runs the PHPUnit suite on other PHP versions, in Docker, against the same
# test database (tests/bin/start-db.sh). The repository is mounted at its own
# absolute path so the wp-tests-config.php the installer wrote stays valid.
#
#   ./tests/bin/php-matrix.sh            8.1 (the declared floor) and 8.3
#   ./tests/bin/php-matrix.sh 8.2 8.4    any versions

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
VERSIONS=( "${@:-8.1 8.3}" )
read -r -a VERSIONS <<< "${VERSIONS[*]}"

"${ROOT}/tests/bin/start-db.sh" >/dev/null

status=0
for version in "${VERSIONS[@]}"; do
	image="dfxpray-test:${version}"
	if ! docker image inspect "${image}" >/dev/null 2>&1; then
		echo "Building ${image}…"
		docker build -q -t "${image}" - >/dev/null <<DOCKERFILE
FROM php:${version}-cli
RUN docker-php-ext-install mysqli >/dev/null
DOCKERFILE
	fi

	echo "PHP ${version}:"
	if ! docker run --rm --network host \
		-u "$(id -u):$(id -g)" \
		-v "${ROOT}:${ROOT}" -w "${ROOT}" \
		"${image}" php vendor/bin/phpunit --do-not-cache-result | tail -3; then
		status=1
	fi
done

exit ${status}
