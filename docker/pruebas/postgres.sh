#!/bin/bash
# Disposable PostgreSQL for the tests (same settings as phpunit.xml and the CI).
#   docker/pruebas/postgres.sh up          # 127.0.0.1:5432, user/password/database: laravel/secret/personal_finance_test
#   docker/pruebas/postgres.sh db <name>   # create an extra database (idempotent), for private test runs
#   docker/pruebas/postgres.sh down
# Then:  DB_DATABASE=<name> docker/pruebas/php.sh -d memory_limit=-1 vendor/bin/phpunit --no-progress --colors=never
set -eu
NOMBRE=finance-pg-prueba
case "${1:-up}" in
    up)
        docker rm -f "$NOMBRE" >/dev/null 2>&1 || true
        docker run -d --name "$NOMBRE" -p 127.0.0.1:5432:5432 -e POSTGRES_DB=personal_finance_test -e POSTGRES_USER=laravel -e POSTGRES_PASSWORD=secret \
            "${IMAGEN_PG:-postgres:18-alpine}" >/dev/null
        for _ in $(seq 1 40); do docker exec "$NOMBRE" pg_isready -U laravel -d personal_finance_test >/dev/null 2>&1 && exit 0; sleep 1; done
        echo "postgres did not start" >&2; exit 1 ;;
    db)
        BD=${2:?usage: $0 db <name>}
        case "$BD" in *[!a-zA-Z0-9_]*) echo "invalid name" >&2; exit 2 ;; esac
        if [ "$(docker exec "$NOMBRE" psql -U laravel -d personal_finance_test -tAc "SELECT 1 FROM pg_database WHERE datname='$BD'")" != 1 ]; then
            docker exec "$NOMBRE" psql -U laravel -d personal_finance_test -c "CREATE DATABASE \"$BD\"" >/dev/null
        fi ;;
    down) docker rm -f "$NOMBRE" >/dev/null 2>&1 || true ;;
    *) echo "usage: $0 up|down|db <name>" >&2; exit 2 ;;
esac
