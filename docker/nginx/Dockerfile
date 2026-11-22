ARG APP_IMAGE=ghcr.io/johnson407/trading-bot:latest
FROM ${APP_IMAGE} AS app
FROM nginx:1.27-alpine

COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
