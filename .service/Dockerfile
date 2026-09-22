# Build stage — instala dependencias de Composer y genera el autoload optimizado.
# El resultado de este build nunca se ejecuta: el packer de Celaut solo exporta el
# filesystem final (ver docs/PACKING.md del nodo). CMD/ENTRYPOINT se ignoran.
FROM composer:2 AS composer

WORKDIR /app

COPY ./composer.json ./composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

COPY ./app ./app
COPY ./bootstrap ./bootstrap
COPY ./config ./config
COPY ./database ./database
COPY ./public ./public
COPY ./resources ./resources
COPY ./routes ./routes
COPY ./scripts ./scripts
COPY ./artisan ./artisan

# --no-scripts: no arranca Laravel (package:discover, filament:upgrade) durante el
# build, que no tiene .env ni base de datos; se ejecuta en el primer arranque real
# (ver start.sh).
RUN composer dump-autoload --optimize --no-scripts

# Runtime stage — únicamente esta capa se exporta como filesystem del servicio.
FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        postgresql-client \
        imagemagick \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=composer /app ./

COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY start.sh /usr/local/bin/start.sh

RUN chmod +x /usr/local/bin/start.sh scripts/ocr.sh \
    && mkdir -p \
        storage/app/private \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache
