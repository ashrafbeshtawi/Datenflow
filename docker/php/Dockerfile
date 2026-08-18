FROM php:8.5-fpm-alpine

RUN apk add --no-cache icu-libs libzip libpq \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev \
 && docker-php-ext-install intl pdo_pgsql zip \
 && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
