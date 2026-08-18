# Datenflow

> IT-Lösungen, die kleine und mittlere Unternehmen wirklich nutzen können — Automatisierung, KI und individuelle Software für Logistik, Gastronomie und Dienstleister.

![CI](https://github.com/ashrafbeshtawi/kiwelt/actions/workflows/ci.yaml/badge.svg)
![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)
![Symfony](https://img.shields.io/badge/Symfony-7.4%20LTS-000000?logo=symfony)
![License](https://img.shields.io/badge/license-proprietary-lightgrey)

## What is this?

The website of **Datenflow** ([datenflow.de](https://datenflow.de)) — an IT agency for small and mid-size businesses. Visitors can read about services in plain, non-technical language and book a free consultation call directly on the site.

## Tech stack

| Layer | Choice |
|---|---|
| Framework | Symfony 7.4 LTS (PHP 8.5) |
| Templating | Twig |
| Database | PostgreSQL 18 |
| Mail | Symfony Mailer (Mailpit in dev) |
| Containers | Docker + docker-compose (single Apache+PHP web container) |
| Tests | PHPUnit (unit/functional) + Panther/Chromium (E2E) |
| CI | GitHub Actions |

## Quickstart

```bash
git clone https://github.com/ashrafbeshtawi/kiwelt.git
cd kiwelt
docker compose up -d --build
```

Then open:

- **Website:** http://localhost:8080
- **Mailpit** (catches all dev mail): http://localhost:8025

Composer dependencies are installed automatically inside the `php` container on first boot.
Committed dev defaults live in `.env.dist`; for local overrides or secrets create `.env` / `.env.local` (both gitignored).

## Running tests

Requires local PHP 8.5 + Composer (`composer install`) and the Docker stack running (Postgres).

```bash
vendor/bin/phpunit --testsuite Unit          # unit tests
vendor/bin/phpunit --testsuite Functional    # HTTP/kernel tests
vendor/bin/phpunit --testsuite E2E           # Chromium end-to-end (needs Chrome/Chromium)
```

First E2E run on a fresh machine: `vendor/bin/bdi detect drivers` to download a matching chromedriver.

## Project structure

```
.
├── config/            # Symfony bundle + service configuration
├── docker/            # Apache+PHP image and vhost config
├── public/            # web root (index.php)
├── src/
│   └── Controller/    # page + form controllers
├── templates/         # Twig templates
├── tests/
│   ├── Unit/
│   ├── Functional/
│   └── E2E/           # Panther/Chromium scenarios
└── docker-compose.yaml
```

## Conventions

- Every feature ships with tests in the same change.
- Work happens on feature branches with pull requests — no direct pushes to `main`.
- All developer commands: see [CHEATSHEET.md](CHEATSHEET.md).

## License

Proprietary — © Datenflow, Berlin.
