# ------------------------------------------------------------------------------
# Stage 1: Base - Install PHP extensions and system dependencies
# ------------------------------------------------------------------------------
FROM php:8.4-cli AS php_base

RUN apt-get update && apt-get install -y \
    libpq-dev \
    git \
    unzip \
    libzip-dev \
    libicu-dev \
    && docker-php-ext-install \
    pdo_pgsql \
    intl \
    zip \
    opcache

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Allow composer to run as superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

# Expose port 8000
EXPOSE 8000

# ------------------------------------------------------------------------------
# Stage 2: Development - Default local development environment
# ------------------------------------------------------------------------------
FROM php_base AS dev

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]

# ------------------------------------------------------------------------------
# Stage 3: Builder - Install production composer dependencies
# ------------------------------------------------------------------------------
FROM php_base AS builder

# Copy only the composer files first to leverage Docker layer caching
COPY composer.json composer.lock symfony.lock ./

# Install NO-DEV dependencies, without scripts and autoloader (since code is missing)
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

# ------------------------------------------------------------------------------
# Stage 4: Production - Combine source code, generate autoloaders, verify health
# ------------------------------------------------------------------------------
FROM builder AS production

# Copy the rest of the application code
COPY . .

# Generate highly optimized production autoloaders and run scripts
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && composer run-script post-install-cmd --no-dev || true

# Ensure permissions are correct for Symfony's var directory
RUN mkdir -p var/cache var/log \
    && chmod -R 777 var

# Clear the cache for prod environment
ENV APP_ENV=prod
RUN php bin/console cache:clear --no-debug

# Start the application
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
