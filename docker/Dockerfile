FROM php:8.2-apache

LABEL maintainer="FideX Protocol Working Group"
LABEL description="FideX PHP Reference Implementation — Development Container"

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libssl-dev \
    libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_sqlite \
    opcache

# Install GMP extension (required by web-token/jwt-framework)
RUN apt-get update && apt-get install -y libgmp-dev \
    && docker-php-ext-install gmp \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite (required for .htaccess routing)
RUN a2enmod rewrite

# Configure Apache document root to public/
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Create required directories with correct permissions
RUN mkdir -p storage/logs storage/coverage keys \
    && chown -R www-data:www-data storage \
    && chmod -R 755 storage

# Copy composer files first for layer caching
COPY composer.json composer.lock* ./

# Install dependencies
RUN composer install --no-interaction --no-scripts --prefer-dist 2>/dev/null || true

EXPOSE 80
