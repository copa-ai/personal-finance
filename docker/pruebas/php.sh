#!/bin/bash
# Run PHP in a container on the repository root (no PHP needed on the host).
#   docker/pruebas/php.sh -d memory_limit=-1 vendor/bin/phpunit --no-progress --colors=never [--filter X]
#   DB_DATABASE=pf_mine docker/pruebas/php.sh artisan migrate --force
# Variables: IMAGEN_PHP (default pf-php: PHP 8.4 with pdo_pgsql, intl, zip, gd...),
# PHP_RED (default host: reaches the database of postgres.sh).
# The script passes the DB_*, APP_*, CHAT_*, FINANCE_*, AGENTE_*, SESSION_*, CACHE_* and QUEUE_* variables to the container.
set -eu
RAIZ=$(cd "$(dirname "$0")/../.." && pwd)
ENTORNO=()
for v in $(env | grep -E '^(DB_|APP_|CHAT_|FINANCE_|AGENTE_|SESSION_|CACHE_|QUEUE_)' | cut -d= -f1); do ENTORNO+=(-e "$v"); done
exec docker run --rm --security-opt label=disable --user "$(id -u):$(id -g)" --network "${PHP_RED:-host}" -e HOME=/tmp -e COMPOSER_HOME=/tmp/cmp \
    "${ENTORNO[@]}" -v "$RAIZ:/repo" -w /repo "${IMAGEN_PHP:-pf-php}" php "$@"
