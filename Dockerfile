# ---------------------------------------------------------------
# PDF Viewer Platform — Dockerfile
# PHP 8.2 + Apache on Debian Bookworm (slim)
# ---------------------------------------------------------------

FROM php:8.2-apache

# --- System packages ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype-dev \
        libzip-dev \
        unzip \
    && rm -rf /var/lib/apt/lists/*

# --- PHP extensions ---
# curl is already enabled in the php:8.2-apache base image
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_mysql \
        gd \
        zip \
        fileinfo \
        opcache

# --- Apache modules ---
RUN a2enmod rewrite headers deflate expires

# --- PHP production settings ---
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php/php.ini  "$PHP_INI_DIR/conf.d/99-pdfviewer.ini"
COPY docker/php/opcache.ini "$PHP_INI_DIR/conf.d/98-opcache.ini"

# --- Apache virtual host ---
COPY docker/nginx/apache.conf /etc/apache2/sites-available/000-default.conf

# --- Application files ---
WORKDIR /var/www/html
COPY . .

# Remove installer from production image (copy it back manually if needed)
# RUN rm -f install.php

# --- File permissions ---
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/uploads \
    && chmod -R 775 /var/www/html/config

# --- Expose port ---
EXPOSE 80

# --- Entrypoint: writes config from ENV vars, then starts Apache ---
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/ || exit 1
