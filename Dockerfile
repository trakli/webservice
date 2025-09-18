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

# Create user and group with host IDs
RUN set -eux; \
    # It's important to remove users before groups.
    if getent passwd trakli >/dev/null; then userdel trakli; fi; \
    if getent passwd www-data >/dev/null; then userdel www-data; fi; \
    \
    # Delete and recreate www-data group to ensure it has the correct GID.
    if getent group www-data >/dev/null; then groupdel www-data; fi; \
    groupadd -o -g ${HOST_GID} www-data; \
    \
    # Create trakli user with the correct UID and GID.
    useradd -u ${HOST_UID} -g www-data -m -s /bin/bash trakli


# Configure nginx
COPY docker/nginx.conf /etc/nginx/sites-available/default
RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/

# Configure PHP-FPM to run as trakli user
RUN sed -i 's/user = www-data/user = trakli/' /usr/local/etc/php-fpm.d/www.conf

# Configure supervisor
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Set working directory and create Laravel directories
WORKDIR /var/www/html
RUN mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache \
    && chown -R trakli:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

# Expose port
EXPOSE 80

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
