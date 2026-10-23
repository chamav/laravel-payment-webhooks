# PHP 8.5.12-cli-trixie, multi-architecture index.
FROM php:8.5.12-cli-trixie@sha256:595a7cbb1e0547336319f228c4dc991c2a199350ab12b1c8fda05f73abd06e6b

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && git config --system --add safe.directory /app

# Composer 2.10.3, multi-architecture index.
COPY --from=composer:2.10.3@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc /usr/bin/composer /usr/bin/composer

# The container runs as the host user (see compose.yaml), which may have no home directory.
ENV HOME=/tmp \
    COMPOSER_HOME=/tmp/composer \
    PHP_CLI_SERVER_WORKERS=4

WORKDIR /app
EXPOSE 8080

# PHP built-in development server; PHP_CLI_SERVER_WORKERS lets it serve requests concurrently.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080", "--no-reload"]
