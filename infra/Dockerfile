# NOT executed in the authoring environment (no Docker daemon) — verify on first build.
# One PHP image, used by: app (php-fpm), queue, scheduler, reverb.
FROM php:8.3-fpm-alpine
RUN apk add --no-cache icu-dev libzip-dev libpng-dev oniguruma-dev $PHPIZE_DEPS \
 && docker-php-ext-install pdo_mysql intl zip pcntl opcache bcmath mbstring gd \
 && pecl install redis && docker-php-ext-enable redis \
 && apk del $PHPIZE_DEPS
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY backend/ .
RUN composer dump-autoload --optimize \
 && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache
