FROM php:8.5.12-cli-trixie

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && git config --system --add safe.directory /app

COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

# The container runs as the host user (see compose.yaml), which may have no home directory.
ENV HOME=/tmp \
    COMPOSER_HOME=/tmp/composer \
    PHP_CLI_SERVER_WORKERS=4

WORKDIR /app
EXPOSE 8080

# PHP built-in development server; PHP_CLI_SERVER_WORKERS lets it serve requests concurrently.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080", "--no-reload"]
