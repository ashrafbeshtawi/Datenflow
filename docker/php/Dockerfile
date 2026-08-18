# base: dev image used by docker-compose (app is volume-mounted)
FROM php:8.5-apache AS base

RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev libzip-dev libpq-dev \
 && docker-php-ext-install intl pdo_pgsql zip \
 && rm -rf /var/lib/apt/lists/*

COPY docker/php/vhost.conf /etc/apache2/sites-available/000-default.conf

# CV uploads on /karriere go up to 8 MB
RUN printf 'upload_max_filesize=10M\npost_max_size=12M\n' > /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# prod: self-contained image published to ghcr.io — app baked in, no dev deps.
# Runtime env (APP_SECRET, DATABASE_URL, MAILER_DSN, MAIL_*) is provided at deploy time.
FROM base AS prod

ENV APP_ENV=prod

COPY . /app

# Empty .env satisfies Symfony's Dotenv; all real values MUST come from
# runtime env vars — a missing one fails loudly instead of using dev defaults.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts \
 && touch .env \
 && mkdir -p var \
 && chown -R www-data:www-data var
