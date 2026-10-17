# PHP for the containerised workbench (docker compose --profile app): testbench serve, Horizon, the load simulation
# and the live stream command. Arch 8.3; APP_PHP_VERSION picks another PHP (8.2 to 8.5).
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pcntl zip \
    && php -m | grep -qi pdo_sqlite \
    && curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app
