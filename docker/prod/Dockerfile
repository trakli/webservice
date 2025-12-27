# Stage 1: Composer dependencies
FROM composer:2 AS vendor

WORKDIR /app

COPY database/ database/
COPY composer.json composer.json
COPY composer.lock composer.lock

RUN composer install --ignore-platform-reqs --no-interaction --no-plugins --no-scripts --prefer-dist

# Stage 2: Application
FROM ghcr.io/whilesmartphp/frankenphp:8.2

LABEL org.opencontainers.image.source=https://github.com/trakli/webservice
LABEL org.opencontainers.image.description="Trakli API webservice"
LABEL org.opencontainers.image.vendor="WhileSmart LLC"
LABEL org.opencontainers.image.licenses=MIT

COPY . /var/www/html
COPY --from=vendor /app/vendor/ /var/www/html/vendor/

RUN chown -R www-data:www-data /var/www/html /data /config

ENV SERVER_ROOT=/var/www/html/public

USER www-data
