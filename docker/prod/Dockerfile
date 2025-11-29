# Stage 1: Composer dependencies
FROM composer:2 AS vendor

WORKDIR /app

COPY database/ database/
COPY composer.json composer.json
COPY composer.lock composer.lock

RUN composer install --ignore-platform-reqs --no-interaction --no-plugins --no-scripts --prefer-dist

# Stage 2: Application
FROM dunglas/frankenphp:1-php8.2-alpine

LABEL org.opencontainers.image.source=https://github.com/trakli/webservice
LABEL org.opencontainers.image.description="Trakli API webservice"
LABEL org.opencontainers.image.vendor="WhileSmart LLC"
LABEL org.opencontainers.image.licenses=MIT

RUN apk add --no-cache \
    curl \
    libzip-dev \
    libpng-dev \
    libxml2-dev \
    oniguruma-dev \
    freetype-dev \
    libjpeg-turbo-dev

RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

WORKDIR /var/www/html

COPY . /var/www/html
COPY --from=vendor /app/vendor/ /var/www/html/vendor/

RUN mkdir -p /data/caddy /config/caddy \
    && chown -R www-data:www-data /var/www/html /data /config

ENV SERVER_ROOT=/var/www/html/public
ENV SERVER_NAME=:80

USER www-data

EXPOSE 80

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
