FROM php:8.2-fpm

# Install dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev libfreetype6-dev \
    libonig-dev libxml2-dev zip unzip curl git netcat-openbsd \
    supervisor default-mysql-client \
    && apt-get install -y libjpeg62-turbo-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-install pdo pdo_mysql mbstring exif pcntl bcmath sockets

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# The base image has no active php.ini, so memory_limit otherwise falls back to PHP's compiled
# 128M default -- too low to run the full Pest suite without --parallel.
COPY docker/php/conf.d/memory-limit.ini /usr/local/etc/php/conf.d/memory-limit.ini

# Set working directory
WORKDIR /var/www/html

# Set permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 9000

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

CMD ["php-fpm"]
