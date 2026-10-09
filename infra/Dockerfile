# NOT executed in the authoring environment (no Docker daemon) — verify on first build.
FROM php:8.3-fpm-alpine
RUN apk add --no-cache icu-dev libzip-dev $PHPIZE_DEPS \
 && docker-php-ext-install pdo_mysql intl zip pcntl opcache \
 && pecl install redis && docker-php-ext-enable redis
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY backend/ .
RUN composer dump-autoload --optimize && chown -R www-data:www-data storage bootstrap/cache
