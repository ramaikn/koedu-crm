# Image PHP untuk app, worker queue, dan scheduler.
# Target "dev": kode di-mount dari host. Target "prod": kode dan aset ikut dibangun ke image.

FROM php:8.4-fpm-alpine AS base

# Ekstensi yang dibutuhkan Laravel + PostgreSQL + Redis + upload bukti transfer (gd).
RUN apk add --no-cache icu-libs libzip libpng libjpeg-turbo freetype libpq fcgi \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libpng-dev \
      libjpeg-turbo-dev freetype-dev postgresql-dev linux-headers \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip gd bcmath pcntl opcache \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-koedu.ini

WORKDIR /var/www/html

# UID/GID disamakan dengan user host supaya file buatan container (log, cache) tidak terkunci.
ARG UID=1000
ARG GID=1000
# Folder vendor dibuat milik "app" supaya volume Docker yang di-mount ke sana ikut milik "app".
RUN addgroup -g "${GID}" app && adduser -D -u "${UID}" -G app app \
 && mkdir -p /var/www/html/vendor && chown app:app /var/www/html/vendor \
 && sed -i 's/^user = www-data/user = app/; s/^group = www-data/group = app/' /usr/local/etc/php-fpm.d/www.conf

FROM base AS dev
USER app

# Aset frontend dibangun terpisah dengan bun, lalu disalin ke image produksi.
FROM oven/bun:1.4.4-alpine AS assets
WORKDIR /app
COPY package.json bun.lock ./
RUN bun install --frozen-lockfile
COPY . .
RUN bun run build

FROM base AS prod
COPY --chown=app:app . .
COPY --from=assets --chown=app:app /app/public/build ./public/build
RUN composer install --no-dev --optimize-autoloader --no-interaction \
 && mkdir -p logs storage/framework/cache storage/framework/sessions storage/framework/views \
 && chown -R app:app logs storage bootstrap/cache
USER app
