FROM node:24-bookworm-slim AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY public ./public
COPY vite.config.js ./
RUN npm run build

FROM php:8.4-cli-bookworm AS application

RUN apt-get update \
    && apt-get install --no-install-recommends -y \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libsqlite3-dev \
        libxml2-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        dom \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        simplexml \
        xml \
        xmlreader \
        xmlwriter \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader \
    --prefer-dist

COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN mkdir -p \
        bootstrap/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && composer dump-autoload --no-dev --no-interaction --no-scripts --optimize \
    && php artisan package:discover --ansi \
    && php artisan filament:assets \
    && chmod +x scripts/vercel-entrypoint

ENV APP_ENV=production \
    APP_DEBUG=false \
    AUTHORITATIVE_PROJECT_SYNC_ENABLED=false \
    BROADCAST_CONNECTION=log \
    CACHE_STORE=array \
    DB_CONNECTION=sqlite \
    EVIDENCE_DISK=local \
    FILESYSTEM_DISK=local \
    LARAVEL_STORAGE_PATH=/tmp/aasraa-preview/storage \
    LOG_CHANNEL=stderr \
    MAIL_MAILER=log \
    QUEUE_CONNECTION=sync \
    SESSION_DRIVER=database

EXPOSE 3000

ENTRYPOINT ["/var/www/html/scripts/vercel-entrypoint"]
