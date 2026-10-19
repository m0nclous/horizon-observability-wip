# PHP for the containerised workbench (docker compose --profile app): testbench serve, Horizon, the load simulation
# and the live stream command. APP_PHP_VERSION picks another PHP (8.2 to 8.5).
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

# A process environment variable (REDIS_HOST=redis in docker-compose.yml) overrides the testbench.yaml env entry for
# artisan commands, but not for web requests: testbench disables getenv() and the built-in web server does not copy the
# environment into $_SERVER. With E in variables_order, $_ENV carries it to both.
RUN echo 'variables_order = "EGPCS"' > "$PHP_INI_DIR/conf.d/zz-workbench.ini"

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app
