ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libonig-dev libzip-dev libsqlite3-dev git unzip \
    && docker-php-ext-install -j2 intl mbstring zip pdo_sqlite pdo_mysql \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /work
ENV COMPOSER_ALLOW_SUPERUSER=1
