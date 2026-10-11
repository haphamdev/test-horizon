# Shared PHP image for the app-* and horizon-* services.
# Backend-only (no Node/Vite); code is bind-mounted at runtime, so this image
# only provides the PHP runtime, extensions, and Composer.
FROM php:8.5-cli

# System libs + PHP extensions:
#   pdo_pgsql -> Postgres (DB_CONNECTION=pgsql)
#   pcntl     -> required by Horizon's master/supervisor processes
#   redis     -> phpredis (REDIS_CLIENT=phpredis); posix ships enabled by default
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev git unzip \
    && docker-php-ext-install pdo_pgsql pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint
ENTRYPOINT ["entrypoint"]

# Overridden per service in compose (app-* migrate+serve, horizon-* runs horizon).
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
