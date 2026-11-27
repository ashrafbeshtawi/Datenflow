# base: dev image used by docker-compose (app is volume-mounted)
FROM php:8.5.12-apache AS base

RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev libzip-dev libpq-dev \
 && docker-php-ext-install intl pdo_pgsql zip \
 && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY docker/php/vhost.conf /etc/apache2/sites-available/000-default.conf

# CV uploads on /karriere go up to 8 MB
RUN printf 'upload_max_filesize=10M\npost_max_size=12M\n' > /usr/local/etc/php/conf.d/uploads.ini

# App-wide convention: all times are naive Europe/Berlin wall-clock (see SlotFinder).
# PHP must hydrate and create DateTimes in that zone, or every rendered entity
# time drifts by the UTC offset (admin panel +2h in summer).
RUN printf 'date.timezone=Europe/Berlin\n' > /usr/local/etc/php/conf.d/timezone.ini

# Unused here; also a confirmed RCE consumer in CVE-2026-109619.
RUN printf 'session.upload_progress.enabled=0\n' > /usr/local/etc/php/conf.d/security.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# prod: self-contained image published to ghcr.io — app baked in, no dev deps.
# Runtime env (APP_SECRET, DATABASE_URL, MAILER_DSN, MAIL_*) is provided at deploy time.
FROM base AS prod

ENV APP_ENV=prod

# cron runs app:purge-inquiries nightly (retention promised in the privacy policy).
# pam_loginuid can't write the audit uid in an unprivileged container and would
# make every job fail, so it is switched off for cron.
RUN apt-get update \
 && apt-get install -y --no-install-recommends cron \
 && rm -rf /var/lib/apt/lists/* \
 && sed -i '/pam_loginuid.so/s/^/#/' /etc/pam.d/cron
COPY --chmod=0644 docker/php/purge-inquiries.cron /etc/cron.d/purge-inquiries

COPY . /app

# Empty .env satisfies Symfony's Dotenv; all real values MUST come from
# runtime env vars — a missing one fails loudly instead of using dev defaults.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts \
 && touch .env \
 && mkdir -p var \
 && chown -R www-data:www-data var

# Apply pending DB migrations before serving — keeps schema in sync on every deploy.
# The console runs as root and writes cache into var/, so hand var/ back to
# www-data afterwards or Apache workers can't write the prod cache (500s).
# Then hand the runtime env to cron (root-only file, see purge-inquiries.cron)
# and start the cron daemon, which forks into the background.
CMD ["sh", "-c", "bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration && chown -R www-data:www-data var && (umask 077 && export -p > /run/cron.env) && cron && apache2-foreground"]
