# Use PHP 8.5 FPM on Alpine
# Pinned to avoid unreviewed PHP patch-version changes during rebuilds.
FROM php:8.5.8-fpm-alpine AS dev

# ---- Runtime packages (no -dev here) ----
# Keep this lean; add dev libs only in the build step below.
RUN apk add --no-cache \
    bash curl nano git openssh \
    openssl libpq

RUN set -eux; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        openssl-dev postgresql-dev \
    ; \
    # Database and process-control extensions. PDO and OPcache are bundled with
    # the PHP 8.5 image, so only install extensions not included by the base image.
    docker-php-ext-install -j"$(nproc)" \
        pdo_mysql pdo_pgsql \
        pcntl \
    ; \
    # Redis extension
    pecl install redis; \
    docker-php-ext-enable redis; \
    # Clean up build deps and caches
    apk del .build-deps; \
    rm -rf /tmp/pear /tmp/* /var/tmp/* /var/cache/apk/*

# ---- PHP/FPM config ----
COPY docker/php/cookie.ini /usr/local/etc/php/conf.d/cookie.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

# Make Composer available in the development container as well as production.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# FPM process management tweaks
RUN sed -i "s/;process_control_timeout = 0/process_control_timeout = 10s/" /usr/local/etc/php-fpm.conf \
 && sed -i "s/;pid/pid/" /usr/local/etc/php-fpm.conf

WORKDIR /var/www/app

EXPOSE 9000

CMD ["php-fpm"]

# ---- Frontend build stage ----
FROM node:22-alpine AS frontend

WORKDIR /var/www/app

COPY package.json package-lock.json* ./
RUN npm ci

COPY vite.config.js ./
COPY resources ./resources

RUN npm run build

# ---- Production stage ----
FROM dev AS prod

# Copy application code (build context must be project root)
COPY . /var/www/app

# Copy built frontend assets from the frontend stage
COPY --from=frontend /var/www/app/public/build /var/www/app/public/build

RUN cp .env.example .env

# Install production dependencies
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts \
    && php artisan package:discover --ansi \
    && php artisan config:clear \
    && php artisan route:clear

# Ensure storage and cache directories exist and are writable
RUN mkdir -p /var/www/app/storage/app/public \
             /var/www/app/storage/framework/cache/data \
             /var/www/app/storage/framework/sessions \
             /var/www/app/storage/framework/views \
             /var/www/app/storage/logs \
             /var/www/app/bootstrap/cache \
 && chown -R www-data:www-data /var/www/app/storage /var/www/app/bootstrap/cache
