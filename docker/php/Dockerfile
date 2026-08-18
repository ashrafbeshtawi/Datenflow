FROM php:8.5-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev libzip-dev libpq-dev \
 && docker-php-ext-install intl pdo_pgsql zip \
 && rm -rf /var/lib/apt/lists/*

COPY vhost.conf /etc/apache2/sites-available/000-default.conf

# CV uploads on /karriere go up to 8 MB
RUN printf 'upload_max_filesize=10M\npost_max_size=12M\n' > /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
