FROM php:8.4-cli

# Imagemagick solo necesario en scripts/ocr.sh

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
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

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

CMD ["php", "-v"]
