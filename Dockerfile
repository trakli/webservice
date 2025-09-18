FROM php:8.2-fpm

# Set build arguments for user and group IDs
ARG HOST_UID
ARG HOST_GID

# Install system dependencies
RUN apt-get update && apt-get install -y \
    nginx \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    supervisor \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create/update user and group with host IDs to avoid permission issues
RUN set -eux; \
    if getent group www-data >/dev/null; then \
        groupmod -o -g ${HOST_GID} www-data; \
    else \
        groupadd -o -g ${HOST_GID} www-data; \
    fi; \
    if getent passwd www-data >/dev/null; then \
        usermod -o -u ${HOST_UID} -g www-data www-data; \
    else \
        useradd -o -u ${HOST_UID} -g www-data -m -s /bin/bash www-data; \
    fi


# Configure nginx
COPY docker/nginx.conf /etc/nginx/sites-available/default
RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/

# Configure PHP-FPM to run as www-data user
# The default user is www-data, so no changes are needed here.

# Configure supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Set working directory and create Laravel directories
WORKDIR /var/www/html
RUN mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

# Expose port
EXPOSE 80

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
