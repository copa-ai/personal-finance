#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

if [ ! -f .env ] && [ -f .env.example ]; then
  cp .env.example .env
fi

if [ ! -d vendor ]; then
  composer install --no-interaction --prefer-dist
fi

if [ -f .env ] && ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force || true
fi

until php artisan migrate --force; do
  echo "Waiting for database..."
  sleep 3
done

exec php artisan serve --host=0.0.0.0 --port=8080
