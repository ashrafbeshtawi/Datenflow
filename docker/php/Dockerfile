FROM php:8.5-fpm-alpine

RUN apk add --no-cache icu-libs libzip libpq \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev \
 && docker-php-ext-install intl pdo_pgsql zip \
 && apk del .build-deps

# CV uploads on /karriere go up to 8 MB
RUN printf 'upload_max_filesize=10M\npost_max_size=12M\n' > /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
