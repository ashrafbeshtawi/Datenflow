# Cheatsheet

## Docker

`docker compose up -d --build` — build and start the full stack (php, nginx, postgres, mailpit)
`docker compose down` — stop the stack
`docker compose down -v` — stop and wipe the database volume
`docker compose logs -f php` — tail PHP logs
`docker compose exec php sh` — shell into the PHP container
`docker compose exec php composer install` — reinstall dependencies inside the container

## Symfony console

`php bin/console cache:clear` — clear the app cache
`php bin/console debug:router` — list all routes
`php bin/console debug:container` — list services
`php bin/console debug:twig` — list Twig functions/filters/globals

## Doctrine

`php bin/console doctrine:database:create --if-not-exists` — create the database
`php bin/console doctrine:migrations:diff` — generate a migration from entity changes
`php bin/console doctrine:migrations:migrate -n` — run pending migrations
`php bin/console doctrine:migrations:migrate prev -n` — roll back one migration
`php bin/console doctrine:schema:validate` — check mapping/schema sync

## Testing

`vendor/bin/phpunit` — full suite
`vendor/bin/phpunit --testsuite Unit` — unit only
`vendor/bin/phpunit --testsuite Functional` — functional only
`vendor/bin/phpunit --testsuite E2E` — Chromium E2E only
`vendor/bin/bdi detect drivers` — download chromedriver for local E2E runs
`php bin/console doctrine:database:create --if-not-exists --env=test` — create the test DB

## Mail (dev)

`open http://localhost:8025` — Mailpit UI, shows every mail the app sends in dev

## CI locally

```bash
docker compose up -d database
php bin/console doctrine:database:create --if-not-exists --env=test
php bin/console doctrine:migrations:migrate -n --allow-no-migration --env=test
vendor/bin/phpunit --testsuite Unit,Functional
vendor/bin/phpunit --testsuite E2E
```
