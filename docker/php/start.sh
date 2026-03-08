#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

upsert_env() {
  local key="$1"
  local value="$2"

  if grep -q "^${key}=" .env; then
    sed -i "s|^${key}=.*|${key}=${value}|" .env
  else
    echo "${key}=${value}" >> .env
  fi
}

if [ ! -f .env ] && [ -f .env.example ]; then
  cp .env.example .env
fi

# Force DB config for Docker network (app -> db service)
if [ -f .env ]; then
  upsert_env DB_CONNECTION "${DB_CONNECTION:-pgsql}"
  upsert_env DB_HOST "${DB_HOST:-db}"
  upsert_env DB_PORT "${DB_PORT:-5432}"
  upsert_env DB_DATABASE "${DB_DATABASE:-personal_finance}"
  upsert_env DB_USERNAME "${DB_USERNAME:-laravel}"
  upsert_env DB_PASSWORD "${DB_PASSWORD:-secret}"
fi

if [ ! -d vendor ]; then
  composer install --no-interaction --prefer-dist
fi

if [ -f .env ] && ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force || true
fi

# Prevent stale cached config (e.g. old sqlite settings)
php artisan config:clear || true

until php artisan migrate --force; do
  echo "Waiting for database..."
  sleep 3
done

exec php artisan serve --host=0.0.0.0 --port=8080
