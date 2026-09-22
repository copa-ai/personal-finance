#!/bin/sh
set -eu

cd /app

if [ -z "${APP_KEY:-}" ]; then
    echo "[start.sh] APP_KEY no fue proporcionada por el ejecutor (nodo execute -e APP_KEY ...)." >&2
    echo "[start.sh] Generando una clave solo para este arranque; las sesiones no sobrevivirán a un reinicio." >&2
    export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
fi

php artisan config:clear >/dev/null 2>&1 || true
php artisan package:discover --ansi

until php artisan migrate --force; do
    echo "[start.sh] Esperando a la base de datos (DB_HOST=${DB_HOST:-unset})..." >&2
    sleep 3
done

exec php artisan serve --host=0.0.0.0 --port=8080
