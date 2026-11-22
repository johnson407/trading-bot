FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache \
        bash \
        git \
        gmp \
        gmp-dev \
        icu-dev \
        libpq-dev \
        libzip-dev \
        nodejs \
        npm \
        oniguruma-dev \
        postgresql-client \
        redis \
        su-exec \
        supervisor \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" bcmath gmp intl opcache pcntl pdo_pgsql pgsql zip \
    && pecl install redis pcov \
    && docker-php-ext-enable redis pcov \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-trading.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html

FROM base AS app

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts

COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi

COPY . .
RUN composer dump-autoload --optimize \
    && npm run build \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist \
    --optimize-autoloader --no-scripts

FROM base AS assets

COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi

COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
RUN npm run build

FROM base AS production

COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
COPY --from=assets /var/www/html/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
