#!/bin/sh
set -e

cd /var/www/html

if [ "${APP_ENV:-local}" = "production" ]; then
    if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
        echo "ERROR: APP_KEY is required in production; set it in the runtime environment." >&2
        exit 1
    fi
else
    if [ -z "${APP_KEY:-}" ] && [ ! -f .env ]; then
        cp .env.example .env
    fi

    if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
        php artisan key:generate --force
    fi
fi

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data storage bootstrap/cache public
    chmod -R ug+rwX storage bootstrap/cache public
fi

# Wait for PostgreSQL.
if [ "${DB_CONNECTION:-pgsql}" = "pgsql" ]; then
    until php -r '
        $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST") ?: "postgres", getenv("DB_PORT") ?: "5432", getenv("DB_DATABASE") ?: "trading_bot");
        try { new PDO($dsn, getenv("DB_USERNAME") ?: "trading_bot", getenv("DB_PASSWORD") ?: "secret"); } catch (Throwable $e) { exit(1); }
    '; do
        echo "Waiting for PostgreSQL..."
        sleep 2
    done
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
    php artisan storage:link --force 2>/dev/null || true
fi

if [ "$(id -u)" = "0" ]; then
    if [ "$1" = "php-fpm" ]; then
        exec "$@"
    fi

    exec su-exec www-data "$@"
fi

exec "$@"
